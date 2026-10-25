<?php
/**
 * لاگ پیامک‌ها و کد تایید (۱.۴۵.۰).
 *
 * هر پیامکی که سیستم می‌فرستد (یا نمی‌تواند بفرستد) و هر رویداد کد تایید
 * (درخواست، ارسال، ورود موفق، کد اشتباه، منقضی، محدودیت) یک ردیف دارد تا
 * مدیر کل در پنل ببیند به چه شماره‌ای، چه پیامکی، کی رفت و اگر نرفت چرا.
 *
 *   · شماره‌ی کامل ذخیره می‌شود (برای جستجوی یک مشتری)؛ فقط مدیر کل می‌بیند.
 *   · خودِ کد تایید هرگز ذخیره نمی‌شود؛ جایش «••••» نوشته می‌شود.
 *   · ۷ روز نگه داشته و بعد خودکار پاک می‌شود (کرون روزانه، هنگام باز کردن
 *     صفحه، و گاه‌به‌گاه هنگام ثبت؛ اگر کرون سایت اجرا نشود هم پاک می‌شود).
 *
 * @package CheckMotor_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_SMS_Log {

	const SCHEMA = '1.11.0';
	const KEEP   = 7; // روز

	/** @var bool|null */
	protected static $ready = null;

	/**
	 * زمینه‌ی پیامک بعدی: نوع (otp)، برچسب و کدی که باید پوشیده شود.
	 *
	 * @var array
	 */
	protected static $next = array();

	/** برچسب فارسی کلیدهای پترن پیامک. */
	public static function labels() {
		return array(
			'pattern_otp'             => 'کد تایید',
			'pattern_booking'         => 'ثبت نوبت',
			'pattern_booking_branch'  => 'ثبت نوبت (نمایندگی)',
			'pattern_reminder'        => 'یادآوری نوبت',
			'pattern_reminder_branch' => 'یادآوری نوبت (نمایندگی)',
			'pattern_cancel'          => 'لغو نوبت',
			'pattern_cancel_branch'   => 'لغو نوبت (نمایندگی)',
			'pattern_admin'           => 'نوبت جدید به مدیر (پترن قدیمی)',
			'pattern_new_booking'     => 'نوبت جدید (به مدیر)',
			'pattern_admin_cancel'    => 'لغو توسط مشتری (به مدیر)',
			'pattern_wallet'          => 'کیف پول مشتری',
			'pattern_refund'          => 'بازگشت وجه',
			'pattern_agency_new'      => 'نوبت جدید به نمایندگی (پترن قدیمی)',
			'pattern_agency_cancel'   => 'لغو به نمایندگی (پترن قدیمی)',
			'pattern_agency_wallet'   => 'کیف پول نمایندگی',
			'pattern_agency_low'      => 'هشدار موجودی نمایندگی',
			'pattern_agency_topup'    => 'شارژ کیف پول نمایندگی',
			'pattern_agency_active'   => 'فعال شدن نمایندگی',
		);
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** جدول ساخته شده است؟ (مهاجرت 1.11.0) */
	public static function ready() {
		if ( null === self::$ready ) {
			self::$ready = version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
		}

		return self::$ready;
	}

	/** برای آزمون‌ها بعد از مهاجرت. */
	public static function reset_ready() {
		self::$ready = null;
	}

	/* ------------------------------------------------------------------
	 * ثبت
	 * --------------------------------------------------------------- */

	/**
	 * زمینه‌ی پیامکِ بعدی را تعیین می‌کند (کد تایید: نوع، برچسب و کدِ پوشاندنی).
	 *
	 * @param array $ctx kind, label, secret
	 */
	public static function next( array $ctx ) {
		self::$next = $ctx;
	}

	/** زمینه‌ی تعیین‌شده بی‌مصرف ماند (مثلاً ارسال پیش از رسیدن به پیامک رد شد). */
	public static function forget() {
		self::$next = array();
	}

	/**
	 * یک پیامک.
	 *
	 * @param string          $phone   گیرنده.
	 * @param string          $key     کلید پترن (pattern_booking…)، اگر معلوم است.
	 * @param string          $channel pattern | simple | -
	 * @param string          $body_id شناسه‌ی پترن.
	 * @param string          $text    متغیرهای پترن یا متن کامل.
	 * @param true|WP_Error   $result  نتیجه‌ی ارسال.
	 * @param string          $rec_id  شناسه‌ی ارسال در سامانه‌ی پیامک.
	 * @param string          $status  ok | fail | info (اگر خالی، از نتیجه)
	 */
	public static function sms( $phone, $key, $channel, $body_id, $text, $result, $rec_id = '', $status = '' ) {
		$ctx  = self::$next;
		self::$next = array();

		$kind  = isset( $ctx['kind'] ) ? $ctx['kind'] : ( 'pattern_otp' === $key ? 'otp' : 'sms' );
		$label = isset( $ctx['label'] ) ? $ctx['label'] : self::label( $key, $body_id );

		if ( '' === $status ) {
			$status = is_wp_error( $result ) ? 'fail' : 'ok';
		}

		self::write(
			array(
				'kind'    => $kind,
				'event'   => 'ok' === $status ? 'sent' : ( 'info' === $status ? 'skipped' : ( 'otp' === $kind ? 'send_failed' : 'failed' ) ),
				'label'   => $label,
				'phone'   => $phone,
				'channel' => $channel,
				'body_id' => $body_id,
				'text'    => self::mask( (string) $text, isset( $ctx['secret'] ) ? (string) $ctx['secret'] : '' ),
				'status'  => $status,
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
				'rec_id'  => $rec_id,
			)
		);
	}

	/**
	 * رویداد کد تایید (بدون پیامک): ورود موفق، کد اشتباه، منقضی، محدودیت…
	 *
	 * @param string $purpose login | panel
	 * @param string $event   verified | wrong | expired | attempts | missing | throttled | limit_phone | limit_ip
	 * @param string $status  ok | fail | info
	 */
	public static function otp( $phone, $purpose, $event, $status, $note = '' ) {
		self::write(
			array(
				'kind'   => 'otp',
				'event'  => $event,
				'label'  => self::otp_label( $purpose ),
				'phone'  => $phone,
				'text'   => $note,
				'status' => $status,
			)
		);
	}

	public static function otp_label( $purpose ) {
		return 'panel' === $purpose ? 'کد ورود پنل' : 'کد ورود مشتری';
	}

	/** برچسب پیامک از کلید پترن، یا از روی شناسه‌ی پترن در تنظیمات. */
	public static function label( $key, $body_id = '' ) {
		$labels = self::labels();

		if ( $key && isset( $labels[ $key ] ) ) {
			return $labels[ $key ];
		}

		$body_id = trim( (string) $body_id );

		if ( '' !== $body_id ) {
			foreach ( $labels as $k => $l ) {
				if ( $body_id === trim( (string) CMB_Settings::get( $k, '' ) ) ) {
					return $l;
				}
			}
		}

		return 'پیامک';
	}

	/** کد تایید در متن پیامک ذخیره نمی‌شود. */
	public static function mask( $text, $secret ) {
		$secret = trim( (string) $secret );

		if ( '' !== $secret ) {
			$text = str_replace( $secret, str_repeat( '•', strlen( $secret ) ), $text );
		}

		return cmb_substr( $text, 0, 480 );
	}

	protected static function write( array $row ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return false;
		}

		$phone = cmb_normalize_phone( (string) $row['phone'] );

		$data = array(
			'kind'       => substr( sanitize_key( $row['kind'] ), 0, 10 ),
			'event'      => substr( sanitize_key( $row['event'] ), 0, 20 ),
			'label'      => cmb_substr( (string) $row['label'], 0, 60 ),
			'phone'      => $phone ? $phone : cmb_substr( preg_replace( '/[^0-9+]/', '', cmb_en_num( (string) $row['phone'] ) ), 0, 20 ),
			'channel'    => isset( $row['channel'] ) ? substr( sanitize_key( $row['channel'] ), 0, 10 ) : '',
			'body_id'    => isset( $row['body_id'] ) ? cmb_substr( (string) $row['body_id'], 0, 20 ) : '',
			'text'       => isset( $row['text'] ) ? (string) $row['text'] : '',
			'status'     => in_array( $row['status'], array( 'ok', 'fail', 'info' ), true ) ? $row['status'] : 'info',
			'error'      => isset( $row['error'] ) ? cmb_substr( (string) $row['error'], 0, 250 ) : '',
			'rec_id'     => isset( $row['rec_id'] ) ? cmb_substr( (string) $row['rec_id'], 0, 40 ) : '',
			'ip'         => function_exists( 'cmb_get_ip' ) ? substr( (string) cmb_get_ip(), 0, 45 ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		// خطای پایگاه داده‌ی این ثبت، کارِ اصلی (ارسال کد، ثبت نوبت) را خراب نکند
		$saved = $wpdb->last_error;
		$ok    = $wpdb->insert( cmb_table( 'sms_log' ), $data );
		$wpdb->last_error = $saved;

		if ( $ok && 0 === wp_rand( 0, 49 ) ) {
			self::prune();
		}

		return (bool) $ok;
	}

	/** نگه‌داری: فقط ۷ روز. */
	public static function prune() {
		global $wpdb;

		if ( ! self::ready() ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::KEEP * DAY_IN_SECONDS ); // phpcs:ignore

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cmb_table( 'sms_log' ) . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------
	 * پنل
	 * --------------------------------------------------------------- */

	public static function routes() {
		register_rest_route(
			'cmb/v1',
			'/panel/smslog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( CMB_Panel_Api::instance(), 'guard_center' ),
			)
		);
	}

	public static function rest_list( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return rest_ensure_response( array( 'ready' => false, 'items' => array(), 'total' => 0, 'pages' => 0, 'page' => 1, 'keep' => self::KEEP ) );
		}

		// صفحه‌ی لاگ باز شد: حتی اگر کرون سایت اجرا نشده، قدیمی‌ها همین حالا پاک می‌شوند
		self::prune();

		$t      = cmb_table( 'sms_log' );
		$where  = array( '1=1' );
		$args   = array();
		$kind   = sanitize_key( (string) $request->get_param( 'kind' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$q      = preg_replace( '/\D/', '', cmb_en_num( (string) $request->get_param( 'q' ) ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 50;

		if ( in_array( $kind, array( 'sms', 'otp' ), true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}

		if ( in_array( $status, array( 'ok', 'fail' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		if ( '' !== $q ) {
			// ۰۹۱۲… و ۹۱۲… هر دو پیدا شوند
			$where[] = 'phone LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( ltrim( $q, '0' ) ) . '%';
		}

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore

		$items = array();

		foreach ( (array) $rows as $r ) {
			$items[] = self::to_array( $r );
		}

		return rest_ensure_response(
			array(
				'ready'   => true,
				'items'   => $items,
				'total'   => $total,
				'pages'   => (int) ceil( $total / $per ),
				'page'    => $page,
				'keep'    => self::KEEP,
				'summary' => self::summary(),
				'smsOn'   => CMB_SMS::is_enabled(),
			)
		);
	}

	/** آمار ۷ روز: پیامک موفق/ناموفق، کد درخواست‌شده، ورود موفق و ناموفق با کد. */
	public static function summary() {
		global $wpdb;

		$t   = cmb_table( 'sms_log' );
		$out = array( 'smsOk' => 0, 'smsFail' => 0, 'otpSent' => 0, 'otpVerified' => 0, 'otpFailed' => 0, 'today' => 0 );

		$rows = $wpdb->get_results( "SELECT kind, event, status, COUNT(*) AS c FROM {$t} GROUP BY kind, event, status" ); // phpcs:ignore

		foreach ( (array) $rows as $r ) {
			$c = (int) $r->c;

			if ( 'sms' === $r->kind ) {
				if ( 'ok' === $r->status ) {
					$out['smsOk'] += $c;
				} elseif ( 'fail' === $r->status ) {
					$out['smsFail'] += $c;
				}
				continue;
			}

			if ( 'sent' === $r->event ) {
				$out['otpSent'] += $c;
			} elseif ( 'verified' === $r->event ) {
				$out['otpVerified'] += $c;
			} elseif ( 'fail' === $r->status ) {
				$out['otpFailed'] += $c;
			}
		}

		$out['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", current_time( 'Y-m-d' ) . ' 00:00:00' ) ); // phpcs:ignore

		return $out;
	}

	/** برچسب رویداد برای نمایش. */
	public static function event_label( $event ) {
		$map = array(
			'sent'        => 'ارسال شد',
			'failed'      => 'ارسال نشد',
			'skipped'     => 'ارسال نشد (پیامک خاموش)',
			'send_failed' => 'کد ارسال نشد',
			'verified'    => 'ورود با کد موفق',
			'wrong'       => 'کد اشتباه وارد شد',
			'expired'     => 'کد منقضی شده بود',
			'attempts'    => 'تلاش‌های ناموفق زیاد',
			'missing'     => 'کدی برای این شماره نبود',
			'throttled'   => 'درخواست دوباره زودتر از موعد',
			'limit_phone' => 'سقف درخواست ساعتی شماره',
			'limit_ip'    => 'سقف درخواست ساعتی دستگاه',
		);

		return isset( $map[ $event ] ) ? $map[ $event ] : $event;
	}

	public static function to_array( $r ) {
		$at = (string) $r->created_at;

		return array(
			'id'      => (int) $r->id,
			'kind'    => (string) $r->kind,
			'event'   => (string) $r->event,
			'eventFa' => self::event_label( (string) $r->event ),
			'label'   => (string) $r->label,
			'phone'   => (string) $r->phone,
			'phoneFa' => cmb_fa_num( (string) $r->phone ),
			'channel' => (string) $r->channel,
			'bodyId'  => (string) $r->body_id,
			'text'    => (string) $r->text,
			'status'  => (string) $r->status,
			'error'   => (string) $r->error,
			'recId'   => (string) $r->rec_id,
			'ip'      => (string) $r->ip,
			'at'      => $at,
			'atFa'    => cmb_fa_num( cmb_jalali_date( substr( $at, 0, 10 ), 'short' ) . '، ' . substr( $at, 11, 5 ) ),
		);
	}
}
