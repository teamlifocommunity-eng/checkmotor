<?php
/**
 * نظرسنجی بعد از خدمت (۱.۵۲.۰) — طبق سند «نظرسنجی بعد از خدمت».
 *
 * نوبت «انجام شد» ← یک ردیف در cmb_reviews با زمان ارسال (۲ ساعت بعد از
 * «انجام شد» در پنل، یا ساعت ۱۰ برای تکمیل خودکار؛ هرگز بین ۲۱ تا ۹) ←
 * یک پیامک با لینک ثابت /nazar و کد ۵ رقمی ← مشتری با کوکی ورود یا
 * «شماره + کد» به فرم ستاره می‌رسد.
 *
 * خود کد هیچ‌جا ذخیره نمی‌شود: فقط hash_hmac(شماره|کد). در لاگ پیامک هم
 * پوشانده می‌شود. نمایندگی نه کد را می‌بیند، نه می‌تواند نظر را حذف یا
 * ویرایش کند.
 *
 * status: queued | sent | opened | answered | expired | cancelled | skipped | failed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Reviews {

	const SCHEMA = '1.12.0';

	/** گزینه‌ی «زودترین ارسال در صف» تا تیک بی‌کار حتی یک کوئری نزند. */
	const NEXT_OPTION = 'cmb_review_next';

	const LOCK = 'cmb_review_lock';

	public static function init() {
		add_action( 'cmb_booking_status_changed', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'cmb_booking_deleted', array( __CLASS__, 'on_deleted' ), 10, 1 );
		add_action( 'shutdown', array( __CLASS__, 'tick' ), 100 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------------
	 * پایه
	 * --------------------------------------------------------------- */

	public static function ready() {
		return version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
	}

	public static function enabled() {
		return self::ready() && (int) CMB_Settings::get( 'review_enabled', 1 );
	}

	public static function table() {
		return cmb_table( 'reviews' );
	}

	protected static function now() {
		return cmb_now()->format( 'Y-m-d H:i:s' );
	}

	/** برچسب زمانی یک ستون DATETIME محلی (همه‌ی زمان‌های این جدول به وقت سایت‌اند). */
	public static function ts( $local ) {
		if ( ! $local ) {
			return 0;
		}

		try {
			return ( new DateTime( (string) $local, cmb_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	protected static function setting( $key, $default ) {
		return (int) CMB_Settings::get( $key, $default );
	}

	/** نشانی صفحه‌ی نظرسنجی (همان که در متن ثابت پترن است). */
	public static function path() {
		$p = trim( sanitize_title( (string) CMB_Settings::get( 'review_path', 'nazar' ) ), '/' );

		return '' !== $p ? $p : 'nazar';
	}

	public static function url() {
		return home_url( '/' . self::path() );
	}

	/** برچسب‌های منفی (۱ تا ۳) و مثبت (۴ و ۵) از تنظیمات. */
	public static function tags() {
		$split = function ( $raw, $default ) {
			$raw  = '' !== trim( (string) $raw ) ? (string) $raw : $default;
			$list = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n·،,]+/u', $raw ) ) ) );

			return array_slice( array_unique( $list ), 0, 12 );
		};

		return array(
			'bad'  => $split( CMB_Settings::get( 'review_tags_bad', '' ), 'تأخیر در شروع کار·کیفیت کار·برخورد پرسنل·هزینه بیشتر از انتظار·توضیح ندادن کار انجام‌شده·مشکل حل نشد·تمیزی و نظم' ),
			'good' => $split( CMB_Settings::get( 'review_tags_good', '' ), 'کار تمیز و دقیق·سر وقت·برخورد خوب·توضیح کامل·قیمت منصفانه·مشکل حل شد' ),
		);
	}

	public static function rating_label( $r ) {
		$l = array( 1 => 'خیلی بد', 2 => 'بد', 3 => 'معمولی', 4 => 'خوب', 5 => 'عالی' );

		return isset( $l[ (int) $r ] ) ? $l[ (int) $r ] : '';
	}

	protected static function code_hash( $phone, $code ) {
		return hash_hmac( 'sha256', cmb_normalize_phone( $phone ) . '|' . preg_replace( '/\D/', '', (string) $code ), wp_salt( 'auth' ) . 'cmb-review' );
	}

	public static function row( $booking_id ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d', (int) $booking_id ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------
	 * زمان‌بندی
	 * --------------------------------------------------------------- */

	/**
	 * لحظه‌ی ارسال: manual ← اکنون + فاصله؛ auto ← ساعت تنظیم‌شده‌ی امروز.
	 * بیرون از بازه‌ی مجاز (پیش‌فرض ۹ تا ۲۱) به ۹ صبح بعدی می‌افتد.
	 */
	public static function send_time( $mode = 'manual', $from = null ) {
		$t = $from ? clone $from : cmb_now();

		if ( 'auto' === $mode ) {
			$at = ( clone $t )->setTime( max( 0, min( 23, self::setting( 'review_auto_hour', 10 ) ) ), 0, 0 );
			$t  = $at > $t ? $at : $t;
		} else {
			$t->modify( '+' . max( 0, self::setting( 'review_delay_hours', 2 ) ) . ' hours' );
		}

		return self::outside_quiet( $t )->format( 'Y-m-d H:i:s' );
	}

	/** ساعت سکوت: [شروع، پایان)؛ پیش‌فرض ۲۱ تا ۹. */
	protected static function outside_quiet( DateTime $t ) {
		$start = self::setting( 'review_quiet_start', 21 );
		$end   = self::setting( 'review_quiet_end', 9 );
		$h     = (int) $t->format( 'G' );
		$quiet = $start > $end ? ( $h >= $start || $h < $end ) : ( $h >= $start && $h < $end );

		if ( ! $quiet ) {
			return $t;
		}

		$next = ( clone $t )->setTime( $end, 0, 0 );

		if ( $next <= $t ) {
			$next->modify( '+1 day' );
		}

		return $next;
	}

	public static function in_quiet( DateTime $t = null ) {
		$t = $t ? $t : cmb_now();

		return self::outside_quiet( clone $t ) != $t; // phpcs:ignore -- مقایسه‌ی شیء DateTime
	}

	/** نوبت «انجام شد» را در صف می‌گذارد (یا صف قبلی‌اش را به‌روز می‌کند). */
	public static function queue( $booking, $mode = 'manual' ) {
		global $wpdb;

		$booking = is_object( $booking ) ? $booking : CMB_Bookings::get( (int) $booking );

		if ( ! $booking || ! self::enabled() || 'done' !== $booking->status || ! cmb_normalize_phone( $booking->phone ) ) {
			return false;
		}

		$row   = self::row( $booking->id );
		$phone = cmb_normalize_phone( $booking->phone );

		// پیامکش رفته یا جواب داده: دوباره فرستاده نمی‌شود
		if ( $row && ( $row->sent_at || 'answered' === $row->status ) ) {
			if ( 'cancelled' === $row->status ) {
				$wpdb->update( self::table(), array( 'status' => $row->answered_at ? 'answered' : ( $row->opened_at ? 'opened' : 'sent' ), 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) );
			}
			return false;
		}

		$send = self::send_time( $mode );
		$data = array(
			'booking_id' => (int) $booking->id,
			'branch_id'  => (int) $booking->branch_id,
			'service_id' => (int) $booking->service_id,
			'block_key'  => (string) $booking->block_key,
			'booking_date' => (string) $booking->booking_date,
			'user_id'    => (int) $booking->user_id,
			'phone'      => $phone,
			'status'     => 'queued',
			'send_at'    => $send,
			'updated_at' => self::now(),
		);

		if ( $row ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $row->id ) );
		} else {
			$data['created_at'] = self::now();
			$wpdb->insert( self::table(), $data );
		}

		/* دو نوبت پشت هم برای یک شماره: فقط آخری پیامک می‌گیرد (سند بخش ۶). */
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'skipped', note = 'superseded', updated_at = %s WHERE phone = %s AND status = 'queued' AND booking_id <> %d", self::now(), $phone, (int) $booking->id ) ); // phpcs:ignore

		self::bump_next( $send );

		return true;
	}

	/** چند نوبت با هم (تکمیل خودکار شبانه). */
	public static function queue_ids( array $ids, $mode = 'auto' ) {
		$n = 0;

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id && self::queue( CMB_Bookings::get( $id ), $mode ) ) {
				$n++;
			}
		}

		return $n;
	}

	protected static function bump_next( $send_at ) {
		$ts   = self::ts( $send_at );
		$next = (int) get_option( self::NEXT_OPTION, 0 );

		if ( ! $next || $ts < $next ) {
			update_option( self::NEXT_OPTION, $ts, false );
		}
	}

	public static function on_status( $booking_id, $status, $old ) {
		if ( ! self::ready() ) {
			return;
		}

		if ( 'done' === $status && 'done' !== $old ) {
			self::queue( CMB_Bookings::get( $booking_id ), 'manual' );
			return;
		}

		if ( 'done' === $old && 'done' !== $status ) {
			self::cancel( $booking_id, 'status:' . $status );
		}
	}

	public static function on_deleted( $booking_id ) {
		self::cancel( $booking_id, 'deleted' );
	}

	/** «این نظرسنجی دیگر فعال نیست»؛ نظرِ ثبت‌شده پاک نمی‌شود ولی از آمار بیرون می‌رود. */
	public static function cancel( $booking_id, $why = '' ) {
		global $wpdb;

		$row = self::row( $booking_id );

		if ( ! $row || 'cancelled' === $row->status ) {
			return;
		}

		$wpdb->update( self::table(), array( 'status' => 'cancelled', 'note' => substr( (string) $why, 0, 60 ), 'updated_at' => self::now() ), array( 'id' => (int) $row->id ) );
	}

	/* ------------------------------------------------------------------
	 * ارسال
	 * --------------------------------------------------------------- */

	/** روی shutdown هر بازدید عادی؛ فقط وقتی چیزی در صف رسیده باشد. */
	public static function tick() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( is_admin() && ! wp_doing_cron() ) ) {
			return;
		}

		$next = (int) get_option( self::NEXT_OPTION, 0 );

		if ( ! $next || $next > cmb_now()->getTimestamp() || ! self::ready() || get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		ignore_user_abort( true );
		cmb_finish_request();

		self::send_due();

		delete_transient( self::LOCK );
	}

	/**
	 * ارسال ردیف‌های رسیده.
	 *
	 * @return array شمارش هر نتیجه
	 */
	public static function send_due( $limit = 40 ) {
		global $wpdb;

		$out = array( 'sent' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0, 'later' => 0 );

		if ( ! self::ready() ) {
			return $out;
		}

		$t   = self::table();
		$now = cmb_now();

		// کلید خاموش: صف می‌ماند ولی چیزی نمی‌رود
		if ( ! self::enabled() ) {
			update_option( self::NEXT_OPTION, 0, false );
			return $out;
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'queued' AND send_at <= %s ORDER BY send_at ASC LIMIT %d", $now->format( 'Y-m-d H:i:s' ), (int) $limit ) ); // phpcs:ignore

		foreach ( $rows as $row ) {
			$res = self::send_one( $row, $now );
			$out[ $res ] = isset( $out[ $res ] ) ? $out[ $res ] + 1 : 1;
		}

		$next = $wpdb->get_var( "SELECT MIN(send_at) FROM {$t} WHERE status = 'queued'" ); // phpcs:ignore
		update_option( self::NEXT_OPTION, $next ? self::ts( $next ) : 0, false );

		return $out;
	}

	protected static function send_one( $row, DateTime $now ) {
		global $wpdb;

		$t       = self::table();
		$booking = CMB_Bookings::get( (int) $row->booking_id );
		$set     = function ( array $data ) use ( $wpdb, $t, $row ) {
			$data['updated_at'] = cmb_now()->format( 'Y-m-d H:i:s' );
			$wpdb->update( $t, $data, array( 'id' => (int) $row->id ) );
		};

		if ( ! $booking || 'done' !== $booking->status ) {
			$set( array( 'status' => 'cancelled', 'note' => 'not-done' ) );
			return 'cancelled';
		}

		if ( self::in_quiet( $now ) ) {
			$set( array( 'send_at' => self::outside_quiet( clone $now )->format( 'Y-m-d H:i:s' ) ) );
			return 'later';
		}

		// هر شماره حداکثر یک پیامک نظرسنجی در N روز
		$gap  = max( 0, self::setting( 'review_min_gap_days', 3 ) );
		$from = ( clone $now )->modify( '-' . $gap . ' days' )->format( 'Y-m-d H:i:s' );

		if ( $gap && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE phone = %s AND id <> %d AND sent_at IS NOT NULL AND sent_at >= %s LIMIT 1", $row->phone, (int) $row->id, $from ) ) ) { // phpcs:ignore
			$set( array( 'status' => 'skipped', 'note' => 'gap' ) );
			return 'skipped';
		}

		/* تا پترن «نظرسنجی» در ملی‌پیامک تأیید و در تنظیمات وارد نشده، بی‌سروصدا رد
		   می‌شود (نه یک «ارسال ناموفق» در لاگ پیامک برای هر نوبت). در حالت متن ساده می‌رود. */
		if ( '' === trim( (string) CMB_Settings::get( 'pattern_review', '' ) ) && 'simple' !== CMB_Settings::get( 'sms_api_mode', 'pattern' ) ) {
			$set( array( 'status' => 'skipped', 'note' => 'no-pattern' ) );
			return 'skipped';
		}

		$code    = (string) wp_rand( 10000, 99999 );
		$service = CMB_Services::get_service( (int) $booking->service_id );
		$branch  = class_exists( 'CMB_Branches' ) ? CMB_Branches::display_name( (int) $booking->branch_id ) : '';
		$args    = array(
			$booking->customer_name,
			$service ? $service->title : '',
			$branch,
			$code,
		);
		$fallback = sprintf( "%s عزیز، %s در %s انجام شد.\nبه خدمت امروز از ۱ تا ۵ امتیاز دهید:\n%s\nکد نظرسنجی: %s\nچک موتور", $args[0], $args[1], $args[2], preg_replace( '~^https?://~', '', self::url() ), $code );

		// کد در لاگ پیامک پوشانده می‌شود
		if ( class_exists( 'CMB_SMS_Log' ) ) {
			CMB_SMS_Log::next( array( 'label' => 'نظرسنجی', 'secret' => $code ) );
		}

		$result = CMB_SMS::send_event( $row->phone, 'pattern_review', $args, $fallback );

		if ( is_wp_error( $result ) ) {
			$set( array( 'status' => 'failed', 'note' => substr( $result->get_error_code(), 0, 60 ) ) );
			cmb_log( 'Review SMS failed: ' . $result->get_error_message(), array( 'booking' => (int) $booking->id ) );
			return 'failed';
		}

		$set(
			array(
				'status'        => 'sent',
				'sent_at'       => $now->format( 'Y-m-d H:i:s' ),
				'code_hash'     => self::code_hash( $row->phone, $code ),
				'expires_at'    => ( clone $now )->modify( '+' . max( 1, self::setting( 'review_code_days', 14 ) ) . ' days' )->format( 'Y-m-d H:i:s' ),
				'code_attempts' => 0,
			)
		);

		return 'sent';
	}

	/* ------------------------------------------------------------------
	 * دسترسی مشتری
	 * --------------------------------------------------------------- */

	/** آیا کاربر واردشده صاحب این نوبت است؟ */
	protected static function owns( $booking ) {
		$uid = get_current_user_id();

		if ( ! $uid || ! $booking ) {
			return false;
		}

		if ( (int) $booking->user_id === $uid ) {
			return true;
		}

		$mine = cmb_get_user_phone( $uid );

		return '' !== $mine && cmb_normalize_phone( $mine ) === cmb_normalize_phone( $booking->phone );
	}

	/** توکن کوتاه‌مدت بعد از «شماره + کد» (وقتی کوکی مال شماره‌ی دیگری است). */
	protected static function token( $booking_id ) {
		$exp = cmb_now()->getTimestamp() + 2 * HOUR_IN_SECONDS;
		$sig = hash_hmac( 'sha256', (int) $booking_id . '|' . $exp, wp_salt( 'nonce' ) . 'cmb-review' );

		return (int) $booking_id . '.' . $exp . '.' . substr( $sig, 0, 32 );
	}

	protected static function token_ok( $token, $booking_id ) {
		$p = explode( '.', (string) $token );

		if ( 3 !== count( $p ) || (int) $p[0] !== (int) $booking_id || (int) $p[1] < cmb_now()->getTimestamp() ) {
			return false;
		}

		$sig = hash_hmac( 'sha256', (int) $p[0] . '|' . (int) $p[1], wp_salt( 'nonce' ) . 'cmb-review' );

		return hash_equals( substr( $sig, 0, 32 ), (string) $p[2] );
	}

	/**
	 * آیا این نوبت (با کوکی صاحبش یا توکن) نظرسنجی باز دارد؟
	 *
	 * @return true|WP_Error
	 */
	protected static function can_rate( $booking, $row, $token = '' ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_review_gone', 'این نظرسنجی دیگر فعال نیست.', array( 'status' => 410 ) );
		}

		$by_token = '' !== (string) $token && self::token_ok( $token, (int) $booking->id );

		if ( ! $by_token && ! self::owns( $booking ) ) {
			return new WP_Error( 'cmb_review_auth', 'برای این نظرسنجی شماره و کد پیامک‌شده را وارد کنید.', array( 'status' => 403 ) );
		}

		if ( 'done' !== $booking->status || ( $row && 'cancelled' === $row->status ) ) {
			return new WP_Error( 'cmb_review_gone', 'این نظرسنجی دیگر فعال نیست.', array( 'status' => 410 ) );
		}

		// کد معتبر: تا ۱۴ روز؛ از «نوبت‌های من» (کوکی صاحب نوبت): تا ۳۰ روز بعد از نوبت
		$days  = max( 1, self::setting( 'review_window_days', 30 ) );
		$limit = ( new DateTime( $booking->booking_date, cmb_timezone() ) )->modify( '+' . $days . ' days' )->setTime( 23, 59, 59 );
		$open  = ( $row && $row->expires_at && self::ts( $row->expires_at ) >= cmb_now()->getTimestamp() ) || cmb_now() <= $limit;

		if ( ! $open && ! ( $row && 'answered' === $row->status ) ) {
			return new WP_Error( 'cmb_review_expired', 'مهلت نظر دادن برای این نوبت تمام شده است.', array( 'status' => 410 ) );
		}

		return true;
	}

	protected static function editable( $row ) {
		if ( ! $row || 'answered' !== $row->status || ! $row->answered_at ) {
			return true;
		}

		return self::ts( $row->answered_at ) + DAY_IN_SECONDS >= cmb_now()->getTimestamp();
	}

	/** داده‌ی فرم برای اپ. */
	public static function form_array( $booking, $row ) {
		$service = CMB_Services::get_service( (int) $booking->service_id );
		$tags    = $row && $row->tags ? json_decode( (string) $row->tags, true ) : array();

		return array(
			'booking'    => (int) $booking->id,
			'customer'   => (string) $booking->customer_name,
			'service'    => $service ? $service->title : '',
			'branch'     => class_exists( 'CMB_Branches' ) ? CMB_Branches::display_name( (int) $booking->branch_id ) : '',
			'dateFa'     => cmb_jalali_date( $booking->booking_date, 'full' ),
			'blockLabel' => cmb_block_label( $booking->block_key ),
			'answered'   => $row && 'answered' === $row->status,
			'editable'   => self::editable( $row ),
			'rating'     => $row && $row->rating ? (int) $row->rating : 0,
			'tags'       => is_array( $tags ) ? array_values( $tags ) : array(),
			'comment'    => $row ? (string) $row->comment : '',
			'wantsCall'  => $row ? (bool) $row->wants_call : false,
			'editUntil'  => $row && $row->answered_at ? self::local_fa( $row->answered_at, '+1 day' ) : '',
		);
	}

	/** تاریخ و ساعت شمسی یک زمان محلی، با جابه‌جایی اختیاری. */
	public static function local_fa( $local, $modify = '' ) {
		try {
			$d = new DateTime( (string) $local, cmb_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		if ( $modify ) {
			$d->modify( $modify );
		}

		return cmb_jalali_date( $d->format( 'Y-m-d' ), 'numeric' ) . ' ' . $d->format( 'H:i' );
	}

	/** ردیف را (برای نظر از «نوبت‌های من» بدون پیامک) می‌سازد و اولین نمایش را ثبت می‌کند. */
	protected static function touch_open( $booking, $row ) {
		global $wpdb;

		$now = self::now();

		if ( ! $row ) {
			$wpdb->insert(
				self::table(),
				array(
					'booking_id'   => (int) $booking->id,
					'branch_id'    => (int) $booking->branch_id,
					'service_id'   => (int) $booking->service_id,
					'block_key'    => (string) $booking->block_key,
					'booking_date' => (string) $booking->booking_date,
					'user_id'      => (int) $booking->user_id,
					'phone'        => cmb_normalize_phone( $booking->phone ),
					'status'       => 'opened',
					'opened_at'    => $now,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			return self::row( $booking->id );
		}

		if ( ! $row->opened_at ) {
			$data = array( 'opened_at' => $now, 'updated_at' => $now );

			// صف‌شده‌ای که مشتری زودتر خودش از «نوبت‌های من» باز کرد، دیگر پیامک نمی‌گیرد
			if ( in_array( $row->status, array( 'queued', 'sent', 'skipped', 'failed', 'expired' ), true ) ) {
				$data['status'] = 'opened';
			}

			$wpdb->update( self::table(), $data, array( 'id' => (int) $row->id ) );
			return self::row( $booking->id );
		}

		return $row;
	}

	/**
	 * نظرسنجی‌های باز کاربر واردشده (کوکی): نوبت‌های «انجام شد» در مهلت.
	 *
	 * @return array[]
	 */
	public static function open_for_user( $uid = 0 ) {
		global $wpdb;

		$uid = $uid ? (int) $uid : get_current_user_id();

		if ( ! $uid || ! self::ready() ) {
			return array();
		}

		$phone = cmb_normalize_phone( cmb_get_user_phone( $uid ) );
		$days  = max( 1, self::setting( 'review_window_days', 30 ) );
		$since = ( clone cmb_now() )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
		$bt    = cmb_table( 'bookings' );
		$rt    = self::table();

		$where = $phone ? $wpdb->prepare( '( b.user_id = %d OR b.phone = %s )', $uid, $phone ) : $wpdb->prepare( 'b.user_id = %d', $uid );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.*, r.status AS r_status, r.answered_at AS r_answered, r.expires_at AS r_expires FROM {$bt} b LEFT JOIN {$rt} r ON r.booking_id = b.id WHERE {$where} AND b.status = 'done' AND ( b.booking_date >= %s OR ( r.expires_at IS NOT NULL AND r.expires_at >= %s ) ) ORDER BY b.booking_date DESC, b.id DESC LIMIT 10", $since, self::now() ) ); // phpcs:ignore

		$out = array();

		foreach ( $rows as $b ) {
			if ( 'cancelled' === $b->r_status ) {
				continue;
			}

			$answered = 'answered' === $b->r_status;
			$row      = $answered ? self::row( $b->id ) : null;
			$item     = self::form_array( $b, $row );

			// نظر قفل‌شده (بیش از ۲۴ ساعت) دیگر «باز» نیست
			if ( $answered && ! $item['editable'] ) {
				continue;
			}

			$out[] = $item;
		}

		return $out;
	}

	/** وضعیت کوتاه برای کارت «نوبت‌های من». */
	public static function booking_state( $booking ) {
		if ( ! self::enabled() || ! $booking || 'done' !== $booking->status ) {
			return null;
		}

		$row = self::row( $booking->id );

		if ( $row && 'cancelled' === $row->status ) {
			return null;
		}

		if ( $row && 'answered' === $row->status ) {
			return array( 'state' => self::editable( $row ) ? 'editable' : 'done', 'rating' => (int) $row->rating );
		}

		$days  = max( 1, self::setting( 'review_window_days', 30 ) );
		$limit = ( new DateTime( $booking->booking_date, cmb_timezone() ) )->modify( '+' . $days . ' days' )->setTime( 23, 59, 59 );
		$open  = cmb_now() <= $limit || ( $row && $row->expires_at && self::ts( $row->expires_at ) >= cmb_now()->getTimestamp() );

		return $open ? array( 'state' => 'open', 'rating' => 0 ) : null;
	}

	/* ------------------------------------------------------------------
	 * REST مشتری
	 * --------------------------------------------------------------- */

	public static function routes() {
		$ns     = 'cmb/v1';
		$public = '__return_true';

		register_rest_route( $ns, '/review/mine', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_mine' ), 'permission_callback' => $public ) );
		register_rest_route( $ns, '/review/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_verify' ), 'permission_callback' => $public ) );
		register_rest_route( $ns, '/review/form', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_form' ), 'permission_callback' => $public ) );
		register_rest_route( $ns, '/review/submit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_submit' ), 'permission_callback' => $public ) );
	}

	protected static function no_store( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $res;
	}

	protected static function off() {
		return new WP_Error( 'cmb_review_off', 'نظرسنجی فعلاً فعال نیست.', array( 'status' => 404 ) );
	}

	/** کوکی ورود: نظرسنجی‌های باز همان شماره (حالت‌های ۱، ۳ و ۴ جدول سند). */
	public static function rest_mine( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return self::off();
		}

		$uid = get_current_user_id();

		return self::no_store(
			array(
				'loggedIn' => (bool) $uid,
				'phone'    => $uid ? cmb_get_user_phone( $uid ) : '',
				'items'    => $uid ? self::open_for_user( $uid ) : array(),
				'tags'     => self::tags(),
			)
		);
	}

	/**
	 * «شماره + کد نظرسنجی». قفل: ۵ اشتباه برای یک شماره یا ۱۰ اشتباه از یک IP
	 * در ساعت ← یک ساعت.
	 */
	public static function rest_verify( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return self::off();
		}

		$phone = cmb_normalize_phone( (string) $request->get_param( 'phone' ) );
		$code  = preg_replace( '/\D/', '', cmb_en_num( (string) $request->get_param( 'code' ) ) );
		$ipkey = 'cmb_rv_ip_' . md5( (string) cmb_get_ip() );
		$phkey = 'cmb_rv_ph_' . md5( (string) $phone );
		$maxp  = max( 1, self::setting( 'review_max_attempts', 5 ) );
		$maxi  = max( 1, self::setting( 'review_max_attempts_ip', 10 ) );

		if ( (int) get_transient( $ipkey ) >= $maxi || ( $phone && (int) get_transient( $phkey ) >= $maxp ) ) {
			return new WP_Error( 'cmb_review_locked', 'تلاش‌های نادرست زیاد بود؛ یک ساعت دیگر دوباره امتحان کنید.', array( 'status' => 429 ) );
		}

		if ( ! $phone || 5 !== strlen( $code ) ) {
			return new WP_Error( 'cmb_review_input', 'شماره موبایل و کد ۵ رقمی نظرسنجی را کامل وارد کنید.', array( 'status' => 400 ) );
		}

		$t     = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s AND code_hash <> '' ORDER BY id DESC LIMIT 20", $phone ) ); // phpcs:ignore
		$hash  = self::code_hash( $phone, $code );
		$match = null;

		foreach ( $rows as $r ) {
			if ( hash_equals( (string) $r->code_hash, $hash ) ) {
				$match = $r;
				break;
			}
		}

		if ( ! $match ) {
			set_transient( $ipkey, (int) get_transient( $ipkey ) + 1, HOUR_IN_SECONDS );
			set_transient( $phkey, (int) get_transient( $phkey ) + 1, HOUR_IN_SECONDS );

			if ( $rows ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET code_attempts = code_attempts + 1 WHERE phone = %s AND code_hash <> ''", $phone ) ); // phpcs:ignore

				if ( (int) get_transient( $phkey ) >= $maxp ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET locked_until = %s WHERE phone = %s AND code_hash <> ''", ( clone cmb_now() )->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ), $phone ) ); // phpcs:ignore
				}
			}

			if ( class_exists( 'CMB_SMS_Log' ) ) {
				CMB_SMS_Log::otp( $phone, 'review', 'wrong', 'fail', 'کد نظرسنجی نادرست' );
			}

			return new WP_Error( 'cmb_review_wrong', 'کد یا شماره درست نیست.', array( 'status' => 400 ) );
		}

		delete_transient( $phkey );

		$booking = CMB_Bookings::get( (int) $match->booking_id );

		if ( ! $booking || 'cancelled' === $match->status || 'done' !== $booking->status ) {
			return new WP_Error( 'cmb_review_gone', 'این نظرسنجی دیگر فعال نیست.', array( 'status' => 410 ) );
		}

		if ( $match->expires_at && self::ts( $match->expires_at ) < cmb_now()->getTimestamp() && 'answered' !== $match->status ) {
			return new WP_Error( 'cmb_review_expired', 'این کد منقضی شده است. با کد پیامکی وارد شوید و از «نوبت‌های من» نظر دهید.', array( 'status' => 410, 'login' => true ) );
		}

		// ورود خودکار (سند تصمیم ۶) فقط وقتی کسی وارد نیست؛ کوکی شماره‌ی دیگر عوض نمی‌شود
		$logged = false;

		if ( ! get_current_user_id() && (int) CMB_Settings::get( 'review_autologin', 1 ) && method_exists( 'CMB_OTP', 'login_phone' ) ) {
			$uid    = CMB_OTP::login_phone( $phone, (string) $booking->customer_name );
			$logged = ! is_wp_error( $uid ) && $uid > 0;
		}

		$row = self::touch_open( $booking, $match );

		return self::no_store(
			array(
				'success'  => true,
				'token'    => self::token( (int) $booking->id ),
				'loggedIn' => $logged || (bool) get_current_user_id(),
				'nonce'    => $logged ? wp_create_nonce( 'wp_rest' ) : '',
				'item'     => self::form_array( $booking, $row ),
				'tags'     => self::tags(),
			)
		);
	}

	public static function rest_form( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return self::off();
		}

		$booking = CMB_Bookings::get( (int) $request->get_param( 'booking' ) );
		$row     = $booking ? self::row( $booking->id ) : null;
		$ok      = self::can_rate( $booking, $row, (string) $request->get_param( 'token' ) );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$row = self::touch_open( $booking, $row );

		return self::no_store( array( 'success' => true, 'item' => self::form_array( $booking, $row ), 'tags' => self::tags() ) );
	}

	public static function rest_submit( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return self::off();
		}

		$booking = CMB_Bookings::get( (int) $request->get_param( 'booking' ) );
		$row     = $booking ? self::row( $booking->id ) : null;
		$ok      = self::can_rate( $booking, $row, (string) $request->get_param( 'token' ) );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		if ( ! self::editable( $row ) ) {
			return new WP_Error( 'cmb_review_locked_edit', 'نظر شما ثبت شده و بعد از ۲۴ ساعت دیگر قابل ویرایش نیست.', array( 'status' => 409 ) );
		}

		$rating = (int) $request->get_param( 'rating' );

		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'cmb_review_rating', 'امتیاز را با ستاره‌ها مشخص کنید.', array( 'status' => 400 ) );
		}

		$all     = self::tags();
		$allowed = $rating <= 3 ? $all['bad'] : $all['good'];
		$tags    = array_values( array_intersect( $allowed, array_map( 'sanitize_text_field', (array) $request->get_param( 'tags' ) ) ) );
		$comment = trim( sanitize_textarea_field( (string) $request->get_param( 'comment' ) ) );
		$comment = function_exists( 'mb_substr' ) ? mb_substr( $comment, 0, 500 ) : substr( $comment, 0, 500 );
		$call    = $rating <= 2 && (bool) $request->get_param( 'wantsCall' );
		$row     = self::touch_open( $booking, $row );
		$now     = self::now();
		$follow  = $rating <= max( 1, self::setting( 'review_follow_threshold', 2 ) ) || $call;

		$data = array(
			'status'     => 'answered',
			'rating'     => $rating,
			'tags'       => wp_json_encode( $tags, JSON_UNESCAPED_UNICODE ),
			'comment'    => $comment,
			'wants_call' => $call ? 1 : 0,
			'updated_at' => $now,
		);

		if ( ! $row->answered_at ) {
			$data['answered_at'] = $now;
		}

		// پیگیری: امتیاز پایین یا درخواست تماس؛ ویرایش به امتیاز بالا صف بازِ دست‌نخورده را می‌بندد
		if ( $follow && ! $row->follow_status ) {
			$data['follow_status'] = 'open';
		} elseif ( ! $follow && 'open' === $row->follow_status && '' === (string) $row->follow_note ) {
			$data['follow_status'] = '';
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $row->id ) );

		return self::no_store(
			array(
				'success' => true,
				'message' => $row->answered_at ? 'نظر شما به‌روز شد. ممنون!' : 'ممنون! نظر شما ثبت شد.',
				'item'    => self::form_array( $booking, self::row( $booking->id ) ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * گزارش پنل
	 * --------------------------------------------------------------- */

	/** شرط WHERE گزارش (بدون ردیف‌های لغوشده). */
	public static function report_where( array $f ) {
		global $wpdb;

		$w = array( "r.status <> 'cancelled'" );

		// دامنه‌ی شعبه‌ی جاری پنل (نمایندگی فقط نوبت‌های خودش)
		if ( ! empty( $f['scope_sql'] ) ) {
			$w[] = (string) $f['scope_sql'];
		}

		if ( ! empty( $f['branch'] ) ) {
			$w[] = $wpdb->prepare( 'r.branch_id = %d', (int) $f['branch'] );
		}
		if ( ! empty( $f['from'] ) ) {
			$w[] = $wpdb->prepare( 'r.booking_date >= %s', $f['from'] );
		}
		if ( ! empty( $f['to'] ) ) {
			$w[] = $wpdb->prepare( 'r.booking_date <= %s', $f['to'] );
		}
		if ( ! empty( $f['block'] ) ) {
			$w[] = $wpdb->prepare( 'r.block_key = %s', $f['block'] );
		}
		if ( ! empty( $f['service'] ) ) {
			$w[] = $wpdb->prepare( 'r.service_id = %d', (int) $f['service'] );
		}
		// یک خدمت در همه‌ی شعبه‌ها (هر نمایندگی ردیف کاتالوگ خودش را دارد): با عنوان
		if ( ! empty( $f['svc_title'] ) ) {
			$w[] = $wpdb->prepare( 'r.service_id IN (SELECT id FROM ' . cmb_table( 'services' ) . ' WHERE title = %s)', (string) $f['svc_title'] );
		}

		return implode( ' AND ', $w );
	}

	/**
	 * آمار، توزیع، تفکیک‌ها و فهرست.
	 *
	 * @param array $f branch, from, to, block, service, rating, follow, page
	 */
	public static function report( array $f, $compare = false ) {
		global $wpdb;

		$t     = self::table();
		$where = self::report_where( $f );

		$sum = $wpdb->get_row( "SELECT COUNT(CASE WHEN r.sent_at IS NOT NULL THEN 1 END) AS sent, COUNT(CASE WHEN r.status = 'answered' THEN 1 END) AS answered, AVG(CASE WHEN r.status = 'answered' THEN r.rating END) AS avg_rating, COUNT(CASE WHEN r.status = 'answered' AND r.rating <= 2 THEN 1 END) AS low, COUNT(CASE WHEN r.follow_status IN ('open','called') THEN 1 END) AS follow, COUNT(CASE WHEN r.status = 'answered' AND r.sent_at IS NOT NULL THEN 1 END) AS answered_sent FROM {$t} r WHERE {$where}" ); // phpcs:ignore

		$dist = array_fill( 1, 5, 0 );
		foreach ( (array) $wpdb->get_results( "SELECT r.rating, COUNT(*) AS n FROM {$t} r WHERE {$where} AND r.status = 'answered' GROUP BY r.rating" ) as $d ) { // phpcs:ignore
			if ( isset( $dist[ (int) $d->rating ] ) ) { $dist[ (int) $d->rating ] = (int) $d->n; }
		}

		$group = function ( $col ) use ( $wpdb, $t, $where ) {
			return (array) $wpdb->get_results( "SELECT r.{$col} AS k, COUNT(CASE WHEN r.status = 'answered' THEN 1 END) AS n, AVG(CASE WHEN r.status = 'answered' THEN r.rating END) AS avg_rating, COUNT(CASE WHEN r.sent_at IS NOT NULL THEN 1 END) AS sent, COUNT(CASE WHEN r.status = 'answered' AND r.sent_at IS NOT NULL THEN 1 END) AS answered_sent, COUNT(CASE WHEN r.status = 'answered' AND r.rating <= 2 THEN 1 END) AS low FROM {$t} r WHERE {$where} GROUP BY r.{$col}" ); // phpcs:ignore
		};
		$shape = function ( $g, $label ) {
			$n = (int) $g->n;
			return array(
				'key'      => $g->k,
				'label'    => $label,
				'count'    => $n,
				'avg'      => $n ? round( (float) $g->avg_rating, 2 ) : null,
				'response' => (int) $g->sent ? (int) round( 100 * (int) $g->answered_sent / (int) $g->sent ) : null,
				'lowPct'   => $n ? (int) round( 100 * (int) $g->low / $n ) : null,
			);
		};

		// به تفکیک خدمت، با یکی کردن ردیف‌های کاتالوگِ هم‌نام شعبه‌های مختلف
		$merged = array();
		foreach ( $group( 'service_id' ) as $g ) {
			$s     = CMB_Services::get_service( (int) $g->k );
			$title = $s ? $s->title : 'خدمت حذف‌شده';
			if ( ! isset( $merged[ $title ] ) ) {
				$merged[ $title ] = (object) array( 'k' => $title, 'n' => 0, 'sum' => 0.0, 'sent' => 0, 'answered_sent' => 0, 'low' => 0 );
			}
			$m = $merged[ $title ];
			$m->n += (int) $g->n; $m->sum += (float) $g->avg_rating * (int) $g->n; $m->sent += (int) $g->sent; $m->answered_sent += (int) $g->answered_sent; $m->low += (int) $g->low;
		}
		$by_service = array();
		foreach ( $merged as $title => $m ) {
			$m->avg_rating = $m->n ? $m->sum / $m->n : null;
			$by_service[]  = $shape( $m, $title );
		}
		usort( $by_service, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

		$by_block = array();
		foreach ( $group( 'block_key' ) as $g ) {
			$by_block[] = $shape( $g, cmb_block_label( $g->k ) );
		}

		$by_branch = array();
		if ( $compare ) {
			foreach ( $group( 'branch_id' ) as $g ) {
				$by_branch[] = $shape( $g, class_exists( 'CMB_Branches' ) ? CMB_Branches::display_name( (int) $g->k ) : '#' . $g->k );
			}
			usort( $by_branch, function ( $a, $b ) { return ( $b['avg'] ?? 0 ) <=> ( $a['avg'] ?? 0 ); } );
		}

		// فهرست: فقط پاسخ‌داده‌ها، با فیلتر امتیاز و «فقط منتظر پیگیری»
		$lw = $where . " AND r.status = 'answered'";
		if ( ! empty( $f['rating'] ) ) {
			$lw .= $wpdb->prepare( ' AND r.rating = %d', (int) $f['rating'] );
		}
		if ( ! empty( $f['follow'] ) ) {
			$lw .= " AND r.follow_status IN ('open','called')";
		}

		$page  = max( 1, (int) ( $f['page'] ?? 1 ) );
		$per   = 20;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} r WHERE {$lw}" ); // phpcs:ignore
		$bt    = cmb_table( 'bookings' );
		$list  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*, b.customer_name, b.tracking_code, b.phone AS b_phone FROM {$t} r LEFT JOIN {$bt} b ON b.id = r.booking_id WHERE {$lw} ORDER BY " . ( ! empty( $f['follow'] ) ? 'r.answered_at ASC' : 'r.answered_at DESC' ) . ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per ) ); // phpcs:ignore

		$items = array();
		foreach ( $list as $r ) {
			$items[] = self::panel_item( $r );
		}

		$answered = (int) $sum->answered;

		return array(
			'stats'     => array(
				'avg'      => $answered ? round( (float) $sum->avg_rating, 1 ) : null,
				'count'    => $answered,
				'sent'     => (int) $sum->sent,
				'response' => (int) $sum->sent ? (int) round( 100 * (int) $sum->answered_sent / (int) $sum->sent ) : null,
				'lowPct'   => $answered ? (int) round( 100 * (int) $sum->low / $answered ) : null,
				'follow'   => (int) $sum->follow,
			),
			'dist'      => array_values( $dist ),
			'byService' => $by_service,
			'byBlock'   => $by_block,
			'byBranch'  => $by_branch,
			'items'     => $items,
			'total'     => $total,
			'page'      => $page,
			'pages'     => max( 1, (int) ceil( $total / $per ) ),
		);
	}

	public static function panel_item( $r ) {
		$tags = $r->tags ? json_decode( (string) $r->tags, true ) : array();
		$u    = $r->follow_by ? get_userdata( (int) $r->follow_by ) : null;
		$svc  = CMB_Services::get_service( (int) $r->service_id );

		return array(
			'id'           => (int) $r->id,
			'booking'      => (int) $r->booking_id,
			'code'         => isset( $r->tracking_code ) ? (string) $r->tracking_code : '',
			'customer'     => isset( $r->customer_name ) ? (string) $r->customer_name : '',
			'phone'        => isset( $r->b_phone ) ? (string) $r->b_phone : (string) $r->phone,
			'branchId'     => (int) $r->branch_id,
			'branch'       => class_exists( 'CMB_Branches' ) ? CMB_Branches::display_name( (int) $r->branch_id ) : '',
			'service'      => $svc ? $svc->title : '',
			'dateFa'       => cmb_jalali_date( $r->booking_date, 'numeric' ),
			'blockLabel'   => cmb_block_label( $r->block_key ),
			'rating'       => (int) $r->rating,
			'ratingLabel'  => self::rating_label( (int) $r->rating ),
			'tags'         => is_array( $tags ) ? $tags : array(),
			'comment'      => (string) $r->comment,
			'wantsCall'    => (bool) $r->wants_call,
			'answeredFa'   => $r->answered_at ? self::local_fa( $r->answered_at ) : '',
			'follow'       => (string) $r->follow_status,
			'followNote'   => (string) $r->follow_note,
			'followBy'     => $u ? $u->display_name : '',
			'followAtFa'   => $r->follow_at ? self::local_fa( $r->follow_at ) : '',
		);
	}

	/** نظرِ چند نوبت با یک کوئری (برای جزئیات نوبت در پنل). */
	public static function for_bookings( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( ! $ids || ! self::ready() ) {
			return array();
		}

		$out = array();

		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE status = 'answered' AND booking_id IN (" . implode( ',', $ids ) . ')' ) as $r ) { // phpcs:ignore
			$out[ (int) $r->booking_id ] = self::panel_item( $r );
		}

		return $out;
	}
}
