<?php
/**
 * پرداخت بیعانه‌ی نوبت با زرین‌پال.
 *
 * جریان:
 *
 *   ۱. مشتری قوانین را می‌پذیرد؛ CMB_Bookings::create نوبت را با وضعیت
 *      «در انتظار پرداخت» (pending) ثبت می‌کند و جا تا hold_until_gmt
 *      نگه داشته می‌شود. پیامکی نمی‌رود.
 *   ۲. start() از زرین‌پال authority می‌گیرد و مشتری به درگاه می‌رود.
 *   ۳. زرین‌پال مشتری را به /{slug}/pay/return برمی‌گرداند. همیشه verify
 *      زده می‌شود (Status داخل نشانی را هر کسی می‌تواند بنویسد) و بعد
 *      مشتری به /{slug}/pay/result می‌رود که رسید یا خطا را نشان می‌دهد.
 *   ۴. اگر مشتری پول داد ولی برنگشت (مرورگر بسته شد، اینترنت قطع شد)،
 *      reconcile() پرداخت‌های بی‌جواب را با فاصله دوباره verify می‌کند.
 *
 * پولی که آمده ولی نوبتی برایش نمانده (پرداخت دیر، پرداخت دوم برای یک
 * نوبت، انصراف قبلی) گم نمی‌شود: در صف «بازگشت وجه» می‌نشیند.
 *
 * مبلغ‌ها: روی نوبت و در تنظیمات تومان؛ در جدول پرداخت‌ها و درگاه ریال.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Payments {

	/** نسخه‌ی اسکیمایی که ستون‌ها و جدول پرداخت را دارد. */
	const SCHEMA = '1.4.0';

	/** فاصله‌ی بررسی دوباره‌ی پرداخت بی‌جواب، دقیقه پس از ساخت. */
	const CHECKS = array( 3, 6, 15, 60, 360, 1440 );

	/** حداقل مبلغ برگشت در زرین‌پال: ۲۰٬۰۰۰ ریال. */
	const MIN_REFUND_RIAL = 20000;

	/** حداکثر تلاش پرداخت برای یک نوبت. */
	const MAX_ATTEMPTS = 5;

	/* ------------------------------------------------------------------
	 * پیکربندی
	 * --------------------------------------------------------------- */

	public static function schema_ready() {
		return version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
	}

	/**
	 * مرچنت کد: از تنظیمات افزونه، وگرنه از افزونه‌ی زرین‌پال ووکامرس.
	 */
	public static function merchant() {
		$own = trim( (string) CMB_Settings::get( 'zp_merchant_id', '' ) );

		if ( '' !== $own ) {
			return $own;
		}

		$woo = get_option( 'woocommerce_WC_ZPal_settings' );

		return ( is_array( $woo ) && ! empty( $woo['merchantcode'] ) ) ? trim( (string) $woo['merchantcode'] ) : '';
	}

	/**
	 * @return string settings | woocommerce | ''
	 */
	public static function merchant_source() {
		if ( '' !== trim( (string) CMB_Settings::get( 'zp_merchant_id', '' ) ) ) {
			return 'settings';
		}

		return '' !== self::merchant() ? 'woocommerce' : '';
	}

	public static function sandbox() {
		return (bool) CMB_Settings::get( 'zp_sandbox', 0 );
	}

	/**
	 * آیا رزرو با بیعانه فعال است؟
	 */
	public static function enabled() {
		return (bool) CMB_Settings::get( 'pay_enabled', 0 ) && self::schema_ready() && '' !== self::merchant();
	}

	/** لحظه‌ی جاری به وقت سایت، برای ستون‌های DATETIME (از ساعت مرجع افزونه). */
	public static function now_local() {
		return cmb_now()->format( 'Y-m-d H:i:s' );
	}

	public static function hold_minutes() {
		return max( 10, min( 60, (int) CMB_Settings::get( 'pay_hold_minutes', 20 ) ) );
	}

	/* ------------------------------------------------------------------
	 * مبلغ‌ها و قوانین
	 * --------------------------------------------------------------- */

	/**
	 * بیعانه‌ی یک خدمت، تومان. ۰ یعنی این خدمت بیعانه ندارد.
	 *
	 * NULL روی خدمت یعنی «پیش‌فرض تنظیمات».
	 */
	public static function service_deposit( $service ) {
		if ( ! $service || ! self::enabled() ) {
			return 0;
		}

		$own = isset( $service->deposit_amount ) ? $service->deposit_amount : null;

		if ( null === $own || '' === $own ) {
			return max( 0, (int) CMB_Settings::get( 'pay_deposit_default', 100000 ) );
		}

		return max( 0, (int) $own );
	}

	/**
	 * مبلغ بازگشتی در لغوِ به‌موقع توسط مشتری، تومان (حداکثر خود بیعانه).
	 */
	public static function service_refund( $service, $deposit = null ) {
		$deposit = null === $deposit ? self::service_deposit( $service ) : (int) $deposit;

		if ( $deposit < 1 ) {
			return 0;
		}

		$own    = isset( $service->cancel_refund_amount ) ? $service->cancel_refund_amount : null;
		$refund = ( null === $own || '' === $own )
			? (int) CMB_Settings::get( 'pay_cancel_refund_default', 30000 )
			: (int) $own;

		return max( 0, min( $deposit, $refund ) );
	}

	public static function default_terms() {
		return implode(
			"\n",
			array(
				'۱. برای ثبت نوبت، پرداخت {deposit} بیعانه الزامی است. نوبت فقط پس از پرداخت موفق ثبت و قطعی می‌شود.',
				'۲. هنگام مراجعه، بیعانه از کل هزینه‌ی خدمات کسر می‌شود.',
				'۳. تا {hours} ساعت پیش از زمان نوبت می‌توانید نوبت را از «نوبت‌های من» لغو کنید. در این صورت {refund} به همان کارتی که با آن پرداخت کرده‌اید بازگردانده می‌شود و {kept} به‌عنوان هزینه‌ی لغو نزد مجموعه می‌ماند.',
				'۴. کمتر از {hours} ساعت مانده به نوبت، لغو آنلاین ممکن نیست.',
				'۵. اگر نوبت را لغو نکنید و در زمان نوبت مراجعه نکنید، کل بیعانه ({deposit}) نزد مجموعه می‌ماند و بازگردانده نمی‌شود.',
				'۶. اگر مجموعه نوبت شما را لغو کند، {shop_refund} به شما بازگردانده می‌شود.',
				'۷. بازگشت وجه معمولاً ظرف ۳ روز کاری انجام می‌شود.',
			)
		);
	}

	/**
	 * متن قوانین با مبلغ‌های همین نوبت.
	 */
	public static function terms_text( $deposit, $refund, $hours ) {
		$tpl = trim( (string) CMB_Settings::get( 'pay_terms_text', '' ) );

		if ( '' === $tpl ) {
			$tpl = self::default_terms();
		}

		$pct  = max( 0, min( 100, (int) CMB_Settings::get( 'pay_shop_refund_percent', 100 ) ) );
		$shop = $pct >= 100
			? 'کل بیعانه (' . cmb_toman( $deposit ) . ')'
			: cmb_toman( self::round_toman( $deposit * $pct / 100 ) );

		return strtr(
			$tpl,
			array(
				'{deposit}'     => cmb_toman( $deposit ),
				'{refund}'      => cmb_toman( $refund ),
				'{kept}'        => cmb_toman( max( 0, $deposit - $refund ) ),
				'{hours}'       => cmb_fa_num( (int) $hours ),
				'{shop_refund}' => $shop,
			)
		);
	}

	public static function terms_hash( $text ) {
		return hash( 'sha256', (string) $text );
	}

	/** گرد کردن به هزار تومان پایین‌تر. */
	public static function round_toman( $amount ) {
		return (int) ( floor( (float) $amount / 1000 ) * 1000 );
	}

	/**
	 * مهلت لغو مشتری برای یک شیفت.
	 *
	 * @return DateTime|null
	 */
	public static function cancel_until( $date, $block ) {
		$slot = cmb_slot_start( $date, $block );

		if ( ! $slot ) {
			return null;
		}

		$hours = max( 0, (int) CMB_Settings::get( 'cancel_deadline_hours', 24 ) );

		if ( $hours > 0 ) {
			$slot->modify( '-' . $hours . ' hour' );
		}

		return $slot;
	}

	/**
	 * همه‌ی چیزی که مشتری پیش از پرداخت باید ببیند.
	 *
	 * @return array|null null یعنی این خدمت بیعانه ندارد.
	 */
	public static function quote( $service, $date, $block ) {
		$deposit = self::service_deposit( $service );

		if ( $deposit < 1 ) {
			return null;
		}

		$refund = self::service_refund( $service, $deposit );
		$hours  = max( 0, (int) CMB_Settings::get( 'cancel_deadline_hours', 24 ) );
		$until  = self::cancel_until( $date, $block );
		$price  = (int) $service->price;
		$text   = self::terms_text( $deposit, $refund, $hours );

		return array(
			'deposit'       => $deposit,
			'depositFa'     => cmb_toman( $deposit ),
			'refund'        => $refund,
			'refundFa'      => cmb_toman( $refund ),
			'kept'          => $deposit - $refund,
			'keptFa'        => cmb_toman( $deposit - $refund ),
			'price'         => $price,
			'priceFa'       => $price ? cmb_toman( $price ) : '',
			'remaining'     => max( 0, $price - $deposit ),
			'remainingFa'   => $price ? cmb_toman( max( 0, $price - $deposit ) ) : '',
			'hours'         => $hours,
			'cancelUntil'   => $until ? $until->format( 'Y-m-d H:i:s' ) : '',
			'cancelUntilFa' => $until ? self::moment_fa( $until ) : '',
			'terms'         => $text,
			'hash'          => self::terms_hash( $text ),
			'holdMinutes'   => self::hold_minutes(),
			'sandbox'       => self::sandbox(),
		);
	}

	/** «سه‌شنبه ۱۵ مهر ۱۴۰۵، ساعت ۱۰:۰۰» */
	public static function moment_fa( DateTime $dt ) {
		return sprintf( '%s، ساعت %s', cmb_jalali_date( $dt->format( 'Y-m-d' ), 'full' ), cmb_fa_num( $dt->format( 'H:i' ) ) );
	}

	/* ------------------------------------------------------------------
	 * داده
	 * --------------------------------------------------------------- */

	public static function get_payment( $id ) {
		global $wpdb;

		$table = cmb_table( 'payments' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	public static function get_by_authority( $authority ) {
		global $wpdb;

		$table = cmb_table( 'payments' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE authority = %s", (string) $authority ) ); // phpcs:ignore
	}

	/**
	 * پرداخت‌های یک نوبت، از قدیمی به جدید.
	 */
	public static function for_booking( $booking_id ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return array();
		}

		$table = cmb_table( 'payments' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC", (int) $booking_id ) ); // phpcs:ignore
	}

	/**
	 * پرداختِ اصلی نوبت: اولین پرداخت موفقی که «پرداخت تکراری» نیست.
	 */
	public static function main_payment( $booking_id ) {
		foreach ( self::for_booking( $booking_id ) as $p ) {
			if ( 'paid' === $p->status && 'duplicate' !== $p->refund_reason ) {
				return $p;
			}
		}

		return null;
	}

	protected static function update_payment( $id, array $fields ) {
		global $wpdb;

		$fields['updated_at'] = self::now_local();

		return $wpdb->update( cmb_table( 'payments' ), $fields, array( 'id' => (int) $id ) );
	}

	protected static function update_booking( $id, array $fields ) {
		global $wpdb;

		$fields['updated_at'] = self::now_local();

		$done = $wpdb->update( cmb_table( 'bookings' ), $fields, array( 'id' => (int) $id ) );

		CMB_Availability::flush_cache();

		return $done;
	}

	/**
	 * لاگ کوتاه پاسخ‌های درگاه روی ردیف پرداخت (بدون مرچنت و توکن).
	 */
	protected static function append_raw( $payment, $event, $data ) {
		$log = json_decode( (string) $payment->raw, true );
		$log = is_array( $log ) ? $log : array();

		$log[] = array(
			't' => cmb_now_gmt(),
			'e' => $event,
			'd' => $data,
		);

		// بیست رویداد آخر بس است
		$log = array_slice( $log, -20 );

		return wp_json_encode( $log );
	}

	/* ------------------------------------------------------------------
	 * شروع پرداخت
	 * --------------------------------------------------------------- */

	public static function callback_url( $booking ) {
		return add_query_arg(
			array(
				'cmb_b' => (int) $booking->id,
				'cmb_t' => (string) $booking->pay_token,
			),
			cmb_app_url( 'pay/return' )
		);
	}

	public static function result_url( $booking ) {
		return add_query_arg(
			array(
				'cmb_b' => (int) $booking->id,
				'cmb_t' => (string) $booking->pay_token,
			),
			cmb_app_url( 'pay/result' )
		);
	}

	/**
	 * یک تلاش پرداخت برای نوبت: ردیف پرداخت + درخواست به زرین‌پال.
	 *
	 * @return array|WP_Error { url, paymentId }
	 */
	public static function start( $booking ) {
		global $wpdb;

		$merchant = self::merchant();

		if ( '' === $merchant ) {
			return new WP_Error( 'cmb_pay_off', 'درگاه پرداخت تنظیم نشده است. با شعبه تماس بگیرید.', array( 'status' => 503 ) );
		}

		$amount  = (int) $booking->deposit_amount * 10;
		$sandbox = self::sandbox();
		$now     = self::now_local();

		$wpdb->insert(
			cmb_table( 'payments' ),
			array(
				'booking_id'  => (int) $booking->id,
				'user_id'     => (int) $booking->user_id,
				'sandbox'     => $sandbox ? 1 : 0,
				'amount_rial' => $amount,
				'status'      => 'created',
				'ip'          => cmb_get_ip(),
				'created_at'  => $now,
				'created_gmt' => cmb_now_gmt(),
				'updated_at'  => $now,
			)
		);

		$pid = (int) $wpdb->insert_id;

		if ( ! $pid ) {
			return new WP_Error( 'cmb_db_error', 'ثبت پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		update_option( 'cmb_pay_used', 1, false );

		$service = CMB_Services::get_service( $booking->service_id );

		$res = CMB_Zarinpal::request(
			$merchant,
			$sandbox,
			$amount,
			self::callback_url( $booking ),
			sprintf( 'بیعانه نوبت %s — %s', $booking->tracking_code, $service ? $service->title : 'چک موتور' ),
			array(
				'mobile'   => $booking->phone,
				'order_id' => $booking->tracking_code,
			)
		);

		$payment = self::get_payment( $pid );

		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();

			self::update_payment(
				$pid,
				array(
					'status'     => 'error',
					'gw_code'    => ( is_array( $data ) && isset( $data['zp_code'] ) ) ? (int) $data['zp_code'] : 0,
					'gw_message' => cmb_substr( $res->get_error_message(), 0, 250 ),
					'raw'        => self::append_raw( $payment, 'request', array( 'error' => $res->get_error_message() ) ),
				)
			);

			cmb_log( 'Zarinpal request failed: ' . $res->get_error_message(), array( 'booking' => (int) $booking->id ) );

			return $res;
		}

		self::update_payment(
			$pid,
			array(
				'authority'      => $res['authority'],
				'status'         => 'requested',
				'gw_code'        => (int) $res['code'],
				'fee_type'       => $res['fee_type'],
				'fee'            => (int) $res['fee'],
				'checks'         => 0,
				'next_check_gmt' => gmdate( 'Y-m-d H:i:s', cmb_now()->getTimestamp() + self::CHECKS[0] * MINUTE_IN_SECONDS ),
				'raw'            => self::append_raw( $payment, 'request', array( 'code' => (int) $res['code'] ) ),
			)
		);

		return array(
			'url'       => CMB_Zarinpal::start_url( $res['authority'], $sandbox ),
			'paymentId' => $pid,
		);
	}

	/* ------------------------------------------------------------------
	 * تأیید
	 * --------------------------------------------------------------- */

	/**
	 * verify یک پرداخت. امن برای فراخوانی چندباره و هم‌زمان: فقط برنده‌ی
	 * به‌روزرسانی شرطی (status <> paid) نوبت را تأیید می‌کند و پیامک
	 * می‌فرستد.
	 *
	 * @param string $source return | reconcile | retry | result
	 *
	 * @return string paid | failed | pending | busy | ...
	 */
	public static function verify_payment( $payment, $source = 'return' ) {
		global $wpdb;

		$lock_name = 'cmb_pay_' . (int) $payment->id;
		$lock      = cmb_lock( $lock_name, 10 );

		if ( 'busy' === $lock ) {
			return 'busy';
		}

		$payment = self::get_payment( $payment->id );
		$result  = self::verify_locked( $payment, $source );

		cmb_unlock( $lock_name, $lock );

		return $result;
	}

	protected static function verify_locked( $payment, $source ) {
		global $wpdb;

		if ( ! $payment ) {
			return 'missing';
		}

		if ( 'paid' === $payment->status ) {
			return 'paid';
		}

		if ( ! in_array( $payment->status, array( 'requested', 'failed', 'expired' ), true ) || '' === (string) $payment->authority ) {
			return $payment->status;
		}

		$res = CMB_Zarinpal::verify( self::merchant(), (bool) $payment->sandbox, $payment->authority, (int) $payment->amount_rial );

		if ( is_wp_error( $res ) ) {
			// معلوم نیست؛ بعداً دوباره
			self::schedule_check( $payment, array( 'error' => $res->get_error_message() ) );
			cmb_log( 'Zarinpal verify failed: ' . $res->get_error_message(), array( 'payment' => (int) $payment->id ) );

			return 'pending';
		}

		$code = (int) $res['code'];

		if ( in_array( $code, CMB_Zarinpal::OK_CODES, true ) ) {
			$now   = self::now_local();
			$table = cmb_table( 'payments' );

			$won = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'paid', gw_code = %d, gw_message = %s, ref_id = %s, card_pan = %s, card_hash = %s,
					 fee_type = %s, fee = %d, paid_at = %s, next_check_gmt = NULL, raw = %s, updated_at = %s
					 WHERE id = %d AND status <> 'paid'", // phpcs:ignore
					$code,
					cmb_substr( $res['message'], 0, 250 ),
					cmb_substr( $res['ref_id'], 0, 40 ),
					cmb_substr( $res['card_pan'], 0, 32 ),
					cmb_substr( $res['card_hash'], 0, 128 ),
					cmb_substr( $res['fee_type'], 0, 20 ),
					(int) $res['fee'],
					$now,
					self::append_raw( $payment, 'verify:' . $source, array( 'code' => $code, 'ref_id' => $res['ref_id'] ) ),
					$now,
					(int) $payment->id
				)
			);

			if ( $won ) {
				self::settle( self::get_payment( $payment->id ) );
			}

			return 'paid';
		}

		/* -51 یعنی «پرداخت انجام نشد». ولی پیش از برگشت مشتری (بررسی
		   دوره‌ای) ممکن است فقط یعنی «هنوز در صفحه‌ی درگاه است»؛ پس
		   تا نیم ساعت پس از ساخت فقط دوباره زمان‌بندی می‌شود. */
		$age = cmb_now()->getTimestamp() - strtotime( $payment->created_gmt . ' UTC' );

		if ( -51 === $code && 'return' !== $source && $age < 30 * MINUTE_IN_SECONDS ) {
			self::schedule_check( $payment, array( 'code' => $code ) );

			return 'pending';
		}

		if ( in_array( $code, array( -50, -51, -53, -54, -55 ), true ) ) {
			self::update_payment(
				$payment->id,
				array(
					'status'         => 'failed',
					'gw_code'        => $code,
					'gw_message'     => cmb_substr( $res['message'], 0, 250 ),
					'next_check_gmt' => null,
					'raw'            => self::append_raw( $payment, 'verify:' . $source, array( 'code' => $code ) ),
				)
			);

			if ( -50 === $code || -53 === $code ) {
				cmb_log( 'Zarinpal verify rejected: ' . $res['message'], array( 'payment' => (int) $payment->id, 'code' => $code ) );
			}

			return 'failed';
		}

		// کدهای دیگر (مرچنت، محدودیت تعداد درخواست، …): بعداً دوباره
		self::schedule_check( $payment, array( 'code' => $code ) );

		return 'pending';
	}

	/**
	 * بررسی بعدی یک پرداخت بی‌جواب؛ بعد از آخرین نوبت، «منقضی».
	 */
	protected static function schedule_check( $payment, array $log ) {
		$checks = (int) $payment->checks + 1;
		$fields = array(
			'checks' => $checks,
			'raw'    => self::append_raw( $payment, 'check', $log ),
		);

		if ( isset( $log['code'] ) ) {
			$fields['gw_code'] = (int) $log['code'];
		}

		if ( 'requested' !== $payment->status ) {
			return self::update_payment( $payment->id, $fields );
		}

		if ( $checks >= count( self::CHECKS ) ) {
			$fields['status']         = 'expired';
			$fields['next_check_gmt'] = null;
		} else {
			$fields['next_check_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $payment->created_gmt . ' UTC' ) + self::CHECKS[ $checks ] * MINUTE_IN_SECONDS );
		}

		return self::update_payment( $payment->id, $fields );
	}

	/**
	 * پول آمد: نوبت تأیید شود، یا اگر دیگر ممکن نیست، پول در صف برگشت.
	 */
	protected static function settle( $payment ) {
		if ( ! $payment ) {
			return;
		}

		$lock_name = 'cmb_payb_' . (int) $payment->booking_id;
		$lock      = cmb_lock( $lock_name, 10 );
		$booking   = CMB_Bookings::get( $payment->booking_id );
		$full      = (int) $payment->amount_rial;

		if ( ! $booking ) {
			self::refund_due( $payment, $full, 'no_booking', 0, false );
			cmb_unlock( $lock_name, $lock );
			return;
		}

		/* پول این نوبت قبلاً با پرداخت دیگری آمده (pay_status فقط در
		   confirm یا برگشتِ پرداختِ دیر عوض می‌شود): این یکی تکراری است و
		   کامل برمی‌گردد، بی‌آنکه به وضعیت پرداختِ خود نوبت دست بزند. */
		if ( ! in_array( (string) $booking->pay_status, array( '', 'unpaid' ), true ) ) {
			self::refund_due( $payment, $full, 'duplicate', 0, false );
			cmb_unlock( $lock_name, $lock );
			return;
		}

		$outcome = self::confirm_or_reason( $booking );

		if ( true === $outcome ) {
			self::confirm( $booking, $payment );
		} else {
			// نوبتی برای این پول نمانده: کل مبلغ برگشت داده می‌شود
			if ( 'pending' === $booking->status ) {
				self::update_booking(
					$booking->id,
					array(
						'status'         => 'expired',
						'expire_reason'  => 'timeout',
						'hold_until_gmt' => null,
					)
				);
			}

			self::refund_due( $payment, $full, $outcome, 0, true );
			self::notify_late( CMB_Bookings::get( $booking->id ), $outcome );
		}

		cmb_unlock( $lock_name, $lock );
	}

	/**
	 * آیا این نوبت هنوز می‌تواند با این پرداخت تأیید شود؟
	 *
	 * @return true|string دلیل برگشت پول.
	 */
	protected static function confirm_or_reason( $booking ) {
		if ( 'pending' === $booking->status && $booking->hold_until_gmt && strtotime( $booking->hold_until_gmt . ' UTC' ) > cmb_now()->getTimestamp() ) {
			return true;
		}

		$late = ( 'pending' === $booking->status )
			|| ( 'expired' === $booking->status && in_array( (string) $booking->expire_reason, array( '', 'timeout' ), true ) );

		if ( ! $late ) {
			return 'cancelled' === $booking->status ? 'cancelled' : 'abandoned';
		}

		// پرداخت دیر رسید: اگر هنوز جا هست و زمان نوبت نگذشته، تأیید
		$slot = CMB_Bookings::slot_datetime( $booking );

		if ( ! $slot || $slot <= cmb_now() ) {
			return 'slot_gone';
		}

		$service = CMB_Services::get_service( $booking->service_id );

		CMB_Availability::flush_cache();

		// نوبتِ pendingِ منقضی‌شده دیگر در شمارش نیست، پس یک جای خالی کافی است
		if ( ! $service || CMB_Availability::remaining( (int) $booking->branch_id, $booking->booking_date, $booking->block_key, $service ) < 1 ) {
			return 'slot_gone';
		}

		if ( is_wp_error( CMB_Bookings::check_active_limits( (int) $booking->user_id, $service, (int) $booking->id ) ) ) {
			return 'limit';
		}

		return true;
	}

	protected static function confirm( $booking, $payment ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );
		$now   = self::now_local();

		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'confirmed', pay_status = 'paid', paid_at = %s, hold_until_gmt = NULL,
				 expire_reason = '', updated_at = %s
				 WHERE id = %d AND status IN ('pending','expired')", // phpcs:ignore
				$now,
				$now,
				(int) $booking->id
			)
		);

		CMB_Availability::flush_cache();

		if ( ! $done ) {
			// هم‌زمان چیز دیگری عوض شد؛ پول گم نشود
			self::refund_due( $payment, (int) $payment->amount_rial, 'duplicate', 0, false );
			return;
		}

		$booking = CMB_Bookings::get( $booking->id );

		cmb_log( 'Booking paid and confirmed', array( 'booking' => (int) $booking->id, 'payment' => (int) $payment->id ) );

		do_action( 'cmb_booking_created', (int) $booking->id, $booking );
		do_action( 'cmb_booking_paid', (int) $booking->id, $booking, $payment );

		cmb_after_response(
			function () use ( $booking ) {
				CMB_Bookings::notify_customer( $booking );
				CMB_Bookings::notify_admin( $booking );
			}
		);
	}

	/**
	 * پیامک به مشتری وقتی پولش آمد ولی نوبت تأیید نشد.
	 */
	protected static function notify_late( $booking, $reason ) {
		if ( ! $booking ) {
			return;
		}

		$text = sprintf(
			"چک موتور\n%s عزیز، پرداخت شما برای نوبت %s رسید ولی %s کل مبلغ (%s) به کارت شما بازگردانده می‌شود.",
			$booking->customer_name,
			$booking->tracking_code,
			'slot_gone' === $reason ? 'ظرفیت آن زمان دیگر خالی نبود؛' : 'نوبت دیگر فعال نبود؛',
			number_format( (int) $booking->deposit_amount ) . ' تومان'
		);

		cmb_after_response(
			function () use ( $booking, $text ) {
				CMB_SMS::send_event( $booking->phone, 'pattern_refund_late', array(), $text );
			}
		);
	}

	/* ------------------------------------------------------------------
	 * برگشت وجه
	 * --------------------------------------------------------------- */

	/**
	 * گذاشتن پرداخت در صف برگشت وجه.
	 *
	 * @param int  $amount_rial  ۰ یعنی برگشتی نیست (کل مبلغ نزد مجموعه).
	 * @param bool $touch_booking وضعیت پرداختِ خود نوبت هم عوض شود؟
	 *                            برای پرداخت تکراری نه: نوبت با پرداخت
	 *                            دیگری پرداخت‌شده است.
	 *
	 * @return true|WP_Error
	 */
	public static function refund_due( $payment, $amount_rial, $reason, $by = 0, $touch_booking = true ) {
		if ( ! $payment || 'paid' !== $payment->status ) {
			return new WP_Error( 'cmb_refund_no_payment', 'پرداخت موفقی برای برگشت پیدا نشد.', array( 'status' => 409 ) );
		}

		if ( in_array( $payment->refund_status, array( 'processing', 'done' ), true ) ) {
			return new WP_Error( 'cmb_refund_locked', 'برگشت وجه این پرداخت قبلاً انجام شده یا در حال انجام است و قابل تغییر نیست.', array( 'status' => 409 ) );
		}

		$amount_rial = max( 0, min( (int) $payment->amount_rial, (int) $amount_rial ) );

		if ( $amount_rial > 0 ) {
			self::update_payment(
				$payment->id,
				array(
					'refund_status'      => 'due',
					'refund_reason'      => $reason,
					'refund_amount_rial' => $amount_rial,
					'refund_by'          => (int) $by,
					'refund_due_at'      => self::now_local(),
					'refund_error'       => '',
				)
			);
		} else {
			self::update_payment(
				$payment->id,
				array(
					'refund_status'      => '',
					'refund_reason'      => $reason,
					'refund_amount_rial' => 0,
				)
			);
		}

		if ( $touch_booking ) {
			self::update_booking(
				$payment->booking_id,
				array(
					'pay_status'    => $amount_rial > 0 ? 'refund_due' : 'kept',
					'refund_amount' => (int) ( $amount_rial / 10 ),
				)
			);
		}

		return true;
	}

	/**
	 * برداشتن برگشتِ معوق (مثلاً با بازگرداندن نوبت لغوشده).
	 *
	 * @return true|WP_Error
	 */
	public static function clear_refund( $payment ) {
		if ( ! $payment ) {
			return true;
		}

		if ( in_array( $payment->refund_status, array( 'processing', 'done' ), true ) ) {
			return new WP_Error( 'cmb_refund_locked', 'بیعانه‌ی این نوبت به مشتری برگشت داده شده است؛ بازگرداندن نوبت ممکن نیست. نوبت تازه ثبت شود.', array( 'status' => 409 ) );
		}

		self::update_payment(
			$payment->id,
			array(
				'refund_status'      => '',
				'refund_reason'      => '',
				'refund_amount_rial' => 0,
			)
		);

		self::update_booking(
			$payment->booking_id,
			array(
				'pay_status'    => 'paid',
				'refund_amount' => 0,
			)
		);

		return true;
	}

	/**
	 * ثبت «برگشت انجام شد» توسط مدیر (از پنل زرین‌پال یا کارت‌به‌کارت).
	 *
	 * @return true|WP_Error
	 */
	public static function mark_refunded( $payment_id, $ref, $by, $send_sms = true ) {
		$payment = self::get_payment( $payment_id );

		if ( ! $payment || ! in_array( $payment->refund_status, array( 'due', 'failed' ), true ) ) {
			return new WP_Error( 'cmb_refund_state', 'این برگشت در صف نیست؛ صفحه را تازه کنید.', array( 'status' => 409 ) );
		}

		self::update_payment(
			$payment->id,
			array(
				'refund_status'  => 'done',
				'refund_method'  => 'manual',
				'refund_ref'     => cmb_substr( sanitize_text_field( (string) $ref ), 0, 80 ),
				'refund_by'      => (int) $by,
				'refund_done_at' => self::now_local(),
				'refund_error'   => '',
			)
		);

		$booking = CMB_Bookings::get( $payment->booking_id );

		if ( $booking && 'duplicate' !== $payment->refund_reason ) {
			self::update_booking( $booking->id, array( 'pay_status' => 'refunded' ) );
		}

		cmb_log( 'Refund marked done', array( 'payment' => (int) $payment->id, 'by' => (int) $by ) );

		if ( $send_sms && $booking ) {
			$amount = (int) ( $payment->refund_amount_rial / 10 );

			cmb_after_response(
				function () use ( $booking, $amount ) {
					self::notify_refund( $booking, $amount );
				}
			);
		}

		return true;
	}

	/**
	 * تغییر مبلغ برگشتی که هنوز انجام نشده.
	 *
	 * @param int $amount تومان.
	 *
	 * @return true|WP_Error
	 */
	public static function edit_refund( $payment_id, $amount, $by ) {
		$payment = self::get_payment( $payment_id );

		if ( ! $payment || 'due' !== $payment->refund_status ) {
			return new WP_Error( 'cmb_refund_state', 'مبلغ فقط پیش از انجام برگشت قابل تغییر است.', array( 'status' => 409 ) );
		}

		$valid = self::validate_refund_amount( $amount, (int) $payment->amount_rial / 10 );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::refund_due( $payment, (int) $amount * 10, $payment->refund_reason, $by, 'duplicate' !== $payment->refund_reason );
	}

	/**
	 * مبلغ برگشت: بین ۰ و مبلغ پرداخت؛ اگر بیشتر از صفر، حداقل
	 * ۲٬۰۰۰ تومان (حداقل برگشت در زرین‌پال).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_refund_amount( $amount, $paid ) {
		$amount = (int) $amount;

		if ( $amount < 0 || $amount > (int) $paid ) {
			return new WP_Error( 'cmb_refund_amount', sprintf( 'مبلغ برگشت باید بین ۰ و %s باشد.', cmb_toman( $paid ) ), array( 'status' => 400 ) );
		}

		if ( $amount > 0 && $amount * 10 < self::MIN_REFUND_RIAL ) {
			return new WP_Error( 'cmb_refund_amount', 'کمترین مبلغ برگشت در زرین‌پال ۲,۰۰۰ تومان است. ۰ بگذارید یا مبلغ را بیشتر کنید.', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * پیش‌فرض برگشت در لغو از طرف مجموعه، تومان.
	 */
	public static function shop_refund_default( $paid ) {
		$pct = max( 0, min( 100, (int) CMB_Settings::get( 'pay_shop_refund_percent', 100 ) ) );

		return $pct >= 100 ? (int) $paid : self::round_toman( (int) $paid * $pct / 100 );
	}

	/**
	 * پیامک «برگشت وجه انجام شد».
	 *
	 * متغیرهای پترن: {0} نام مشتری، {1} مبلغ (تومان)، {2} کد پیگیری نوبت
	 */
	public static function notify_refund( $booking, $amount ) {
		$args = array(
			$booking->customer_name,
			number_format( (int) $amount ),
			$booking->tracking_code,
		);

		$fallback = sprintf(
			"چک موتور\n%s عزیز، %s تومان بابت نوبت %s به کارت شما بازگردانده شد.",
			$args[0],
			$args[1],
			$args[2]
		);

		CMB_SMS::send_event( $booking->phone, 'pattern_refund', $args, $fallback );
	}

	/**
	 * صف برگشت وجه برای پنل.
	 *
	 * @param string $which open (در صف و ناموفق) | done
	 */
	public static function refund_queue( $which = 'open', $limit = 100 ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return array();
		}

		$pt = cmb_table( 'payments' );
		$bt = cmb_table( 'bookings' );

		$where = 'done' === $which ? "p.refund_status = 'done'" : "p.refund_status IN ('due','failed','processing')";
		$order = 'done' === $which ? 'p.refund_done_at DESC' : 'p.refund_due_at ASC';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, b.tracking_code, b.customer_name, b.phone, b.booking_date, b.block_key, b.status AS booking_status, b.service_id
				 FROM {$pt} p LEFT JOIN {$bt} b ON b.id = p.booking_id
				 WHERE {$where} ORDER BY {$order} LIMIT %d", // phpcs:ignore
				(int) $limit
			)
		);

		$out = array();

		foreach ( (array) $rows as $r ) {
			$out[] = self::refund_row( $r );
		}

		return $out;
	}

	public static function count_open_refunds() {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return 0;
		}

		$pt = cmb_table( 'payments' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pt} WHERE refund_status IN ('due','failed','processing')" ); // phpcs:ignore
	}

	public static function refund_reasons() {
		return array(
			'customer'   => 'لغو توسط مشتری',
			'shop'       => 'لغو توسط مجموعه',
			'duplicate'  => 'پرداخت تکراری',
			'slot_gone'  => 'پرداخت دیر رسید؛ جا نبود',
			'limit'      => 'پرداخت دیر رسید؛ سقف نوبت‌های مشتری',
			'abandoned'  => 'پرداخت بعد از انصراف',
			'cancelled'  => 'پرداخت برای نوبت لغوشده',
			'no_booking' => 'نوبت حذف شده بود',
		);
	}

	protected static function refund_row( $r ) {
		$reasons  = self::refund_reasons();
		$paid_at  = $r->paid_at ? (string) $r->paid_at : (string) $r->created_at;
		$deadline = strtotime( substr( $paid_at, 0, 10 ) . ' +60 days' );
		$left     = (int) floor( ( $deadline - strtotime( cmb_today() ) ) / DAY_IN_SECONDS );
		$service  = CMB_Services::get_service( $r->service_id );

		return array(
			'id'          => (int) $r->id,
			'bookingId'   => (int) $r->booking_id,
			'code'        => (string) $r->tracking_code,
			'name'        => (string) $r->customer_name,
			'phone'       => (string) $r->phone,
			'service'     => $service ? $service->title : '',
			'dateFa'      => $r->booking_date ? cmb_jalali_date( $r->booking_date, 'numeric' ) : '',
			'paid'        => (int) ( $r->amount_rial / 10 ),
			'paidFa'      => cmb_toman( $r->amount_rial / 10 ),
			'amount'      => (int) ( $r->refund_amount_rial / 10 ),
			'amountFa'    => cmb_toman( $r->refund_amount_rial / 10 ),
			'refId'       => (string) $r->ref_id,
			'card'        => self::card_tail( $r->card_pan ),
			'paidAtFa'    => cmb_jalali_date( substr( $paid_at, 0, 10 ), 'numeric' ) . ' ' . cmb_fa_num( substr( $paid_at, 11, 5 ) ),
			'daysLeft'    => $left,
			'status'      => (string) $r->refund_status,
			'reason'      => (string) $r->refund_reason,
			'reasonLabel' => isset( $reasons[ $r->refund_reason ] ) ? $reasons[ $r->refund_reason ] : (string) $r->refund_reason,
			'error'       => (string) $r->refund_error,
			'ref'         => (string) $r->refund_ref,
			'doneAtFa'    => $r->refund_done_at ? cmb_jalali_date( substr( $r->refund_done_at, 0, 10 ), 'numeric' ) : '',
			'sandbox'     => (bool) $r->sandbox,
		);
	}

	/** «۶۰۳۷۹۹******۱۲۳۴» → «۱۲۳۴» */
	public static function card_tail( $pan ) {
		$digits = preg_replace( '/\D/', '', (string) $pan );

		return strlen( $digits ) >= 4 ? substr( $digits, -4 ) : '';
	}

	/* ------------------------------------------------------------------
	 * منقضی کردن، بررسی دوره‌ای
	 * --------------------------------------------------------------- */

	/**
	 * نوبت‌های «در انتظار پرداخت» که مهلتشان گذشته → «پرداخت نشد».
	 *
	 * فقط برای تمیزی فهرست‌هاست؛ شمارش ظرفیت خودش مهلت را می‌بیند.
	 */
	public static function expire_stale( $force = false ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return 0;
		}

		if ( ! $force && get_transient( 'cmb_pay_exp' ) ) {
			return 0;
		}

		set_transient( 'cmb_pay_exp', 1, MINUTE_IN_SECONDS );

		$table = cmb_table( 'bookings' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired', expire_reason = 'timeout', pay_status = 'unpaid', updated_at = %s
				 WHERE status = 'pending' AND hold_until_gmt IS NOT NULL AND hold_until_gmt <= %s", // phpcs:ignore
				self::now_local(),
				cmb_now_gmt()
			)
		);
	}

	/**
	 * آیا اصلاً پرداختی در کار بوده؟ (تا وقتی نه، بررسی دوره‌ای هیچ
	 * کوئری‌ای نمی‌زند.)
	 */
	public static function in_use() {
		return self::schema_ready() && ( self::enabled() || get_option( 'cmb_pay_used' ) );
	}

	/**
	 * بررسی پرداخت‌های بی‌جواب و منقضی کردن نوبت‌های مانده.
	 *
	 * @return array خلاصه‌ی کار، برای نشانی کرون و تست.
	 */
	public static function reconcile( $force = false ) {
		global $wpdb;

		$out = array(
			'expired'  => 0,
			'checked'  => 0,
			'recovered' => 0,
		);

		if ( ! self::in_use() ) {
			return $out;
		}

		if ( ! $force && get_transient( 'cmb_pay_rc' ) ) {
			return $out;
		}

		set_transient( 'cmb_pay_rc', 1, 2 * MINUTE_IN_SECONDS );

		$out['expired'] = self::expire_stale( true );

		if ( '' === self::merchant() ) {
			return $out;
		}

		$table = cmb_table( 'payments' );

		$due = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'requested' AND next_check_gmt IS NOT NULL AND next_check_gmt <= %s ORDER BY id ASC LIMIT 20", // phpcs:ignore
				cmb_now_gmt()
			)
		);

		foreach ( (array) $due as $payment ) {
			self::verify_payment( $payment, 'reconcile' );
			$out['checked']++;
		}

		/* پرداخت‌های موفقی که زرین‌پال هنوز منتظر تأییدشان است. فقط
		   authorityهای خودمان: مرچنت ممکن است با فروشگاه مشترک باشد و
		   تأیید سفارش ووکامرس کار ما نیست. */
		$since  = gmdate( 'Y-m-d H:i:s', cmb_now()->getTimestamp() - DAY_IN_SECONDS );
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE authority IS NOT NULL AND status IN ('requested','failed','expired') AND created_gmt >= %s", // phpcs:ignore
				$since
			)
		);

		if ( $recent ) {
			$list = CMB_Zarinpal::unverified( self::merchant(), self::sandbox() );

			if ( ! is_wp_error( $list ) && $list ) {
				foreach ( $recent as $payment ) {
					if ( in_array( (string) $payment->authority, $list, true ) && 'paid' === self::verify_payment( $payment, 'unverified' ) ) {
						$out['recovered']++;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * بررسی دوره‌ای بعد از پاسخ، روی بازدید اپ و پنل.
	 */
	public static function maybe_reconcile() {
		if ( get_transient( 'cmb_pay_rc' ) || ! self::in_use() ) {
			return;
		}

		cmb_after_response(
			function () {
				CMB_Payments::reconcile();
			}
		);
	}

	/**
	 * کلید نشانی کرون پرداخت (یک بار ساخته می‌شود).
	 */
	public static function tick_key() {
		$key = (string) CMB_Settings::get( 'pay_tick_key', '' );

		if ( strlen( $key ) < 16 ) {
			$key = wp_generate_password( 24, false, false );
			CMB_Settings::update( array( 'pay_tick_key' => $key ) );
		}

		return $key;
	}

	public static function tick_url() {
		return add_query_arg( 'k', self::tick_key(), cmb_app_url( 'pay/tick' ) );
	}

	/* ------------------------------------------------------------------
	 * مسیرهای صفحه‌ای: /{slug}/pay/…
	 * --------------------------------------------------------------- */

	/**
	 * @return bool آیا این مسیر مال پرداخت بود (و پاسخ داده شد)؟
	 */
	public static function route( $route ) {
		$route = trim( (string) $route, '/' );

		if ( 'pay/return' === $route ) {
			self::handle_return();
			return true;
		}

		if ( 'pay/tick' === $route ) {
			self::handle_tick();
			return true;
		}

		return false;
	}

	protected static function read_link() {
		// phpcs:disable WordPress.Security.NonceVerification
		$bid = isset( $_GET['cmb_b'] ) ? absint( $_GET['cmb_b'] ) : 0;
		$tok = isset( $_GET['cmb_t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['cmb_t'] ) ) : '';
		// phpcs:enable

		return array( $bid, $tok );
	}

	/**
	 * نوبتی که شناسه و توکنش با هم می‌خوانند، وگرنه null.
	 */
	public static function booking_by_token( $bid, $tok ) {
		if ( ! $bid || strlen( (string) $tok ) < 20 || ! self::schema_ready() ) {
			return null;
		}

		$booking = CMB_Bookings::get( $bid );

		if ( ! $booking || '' === (string) $booking->pay_token || ! hash_equals( (string) $booking->pay_token, (string) $tok ) ) {
			return null;
		}

		return $booking;
	}

	protected static function handle_return() {
		nocache_headers();

		list( $bid, $tok ) = self::read_link();

		$booking = self::booking_by_token( $bid, $tok );

		if ( ! $booking ) {
			wp_safe_redirect( add_query_arg( 'cmb_e', 'link', cmb_app_url( 'pay/result' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$authority = isset( $_GET['Authority'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['Authority'] ) ) : '';
		$payment   = '' !== $authority ? self::get_by_authority( $authority ) : null;

		/* authority باید مال همین نوبت باشد. نشانی بازگشت را هر کسی
		   می‌تواند بسازد؛ وضعیت فقط از verify با مبلغ دیتابیس می‌آید. */
		if ( $payment && (int) $payment->booking_id === (int) $booking->id ) {
			self::verify_payment( $payment, 'return' );
		}

		wp_safe_redirect( self::result_url( $booking ) );
		exit;
	}

	protected static function handle_tick() {
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification
		$key = isset( $_GET['k'] ) ? (string) wp_unslash( $_GET['k'] ) : '';

		if ( '' === $key || ! hash_equals( self::tick_key(), $key ) ) {
			status_header( 403 );
			wp_send_json( array( 'ok' => false ), 403 );
		}

		wp_send_json( array( 'ok' => true ) + self::reconcile( true ) );
	}

	/**
	 * داده‌ی صفحه‌ی نتیجه‌ی پرداخت (بدون نیاز به ورود).
	 */
	public static function result_payload() {
		list( $bid, $tok ) = self::read_link();

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['cmb_e'] ) ) {
			return array(
				'state'   => 'invalid',
				'message' => 'نشانی بازگشت از درگاه معتبر نبود. وضعیت نوبتتان را در «نوبت‌های من» ببینید.',
			);
		}

		$booking = self::booking_by_token( $bid, $tok );

		if ( ! $booking ) {
			return array(
				'state'   => 'invalid',
				'message' => 'این نشانی معتبر نیست. وضعیت نوبتتان را در «نوبت‌های من» ببینید.',
			);
		}

		// پرداختی که هنوز جوابش معلوم نیست، همین حالا یک بار دیگر
		if ( 'pending' === $booking->status ) {
			foreach ( self::for_booking( $booking->id ) as $p ) {
				if ( 'requested' === $p->status ) {
					self::verify_payment( $p, 'result' );
				}
			}

			$booking = CMB_Bookings::get( $booking->id );
		}

		return self::state_of( $booking, $tok );
	}

	/**
	 * وضعیت پرداختِ یک نوبت برای نمایش به مشتری.
	 */
	public static function state_of( $booking, $tok = '' ) {
		$payments = self::for_booking( $booking->id );
		$last     = $payments ? end( $payments ) : null;
		$out      = array(
			'id'      => (int) $booking->id,
			'token'   => (string) $tok,
			'booking' => CMB_Bookings::to_array( $booking ),
		);

		if ( in_array( $booking->status, array( 'confirmed', 'done', 'no_show' ), true ) && 'paid' === $booking->pay_status ) {
			$main = self::main_payment( $booking->id );

			return $out + array(
				'state'   => 'paid',
				'message' => 'پرداخت موفق بود و نوبت شما ثبت شد.',
				'refId'   => $main ? (string) $main->ref_id : '',
			);
		}

		if ( 'pending' === $booking->status ) {
			$checking = $last && 'requested' === $last->status;
			$attempts = count( $payments );

			return $out + array(
				'state'    => $checking ? 'checking' : 'failed',
				'message'  => $checking
					? 'نتیجه‌ی پرداخت هنوز از درگاه نرسیده. اگر پول از حسابتان کم شده، چند دقیقه‌ی دیگر خودکار بررسی می‌شود و نوبت ثبت می‌شود؛ وگرنه کل مبلغ بازگردانده می‌شود.'
					: ( $last && $last->gw_message ? $last->gw_message . ' ' : 'پرداخت انجام نشد. ' ) . 'نوبت هنوز برایتان نگه داشته شده است.',
				'canRetry' => $attempts < self::MAX_ATTEMPTS,
				'holdLeft' => max( 0, strtotime( $booking->hold_until_gmt . ' UTC' ) - cmb_now()->getTimestamp() ),
			);
		}

		if ( in_array( $booking->pay_status, array( 'refund_due', 'refunding', 'refunded' ), true ) && 'expired' === $booking->status ) {
			return $out + array(
				'state'   => 'refund',
				'message' => sprintf( 'پرداخت شما رسید ولی نوبت دیگر قابل ثبت نبود؛ %s به کارت شما بازگردانده می‌شود.', cmb_toman( $booking->refund_amount ) ),
			);
		}

		if ( 'expired' === $booking->status ) {
			return $out + array(
				'state'   => 'expired',
				'message' => 'abandoned' === $booking->expire_reason
					? 'از پرداخت انصراف دادید و نوبت ثبت نشد.'
					: 'مهلت پرداخت تمام شد و نوبت ثبت نشد. اگر هنوز می‌خواهید، دوباره نوبت بگیرید.',
			);
		}

		return $out + array(
			'state'   => 'closed',
			'message' => 'این نوبت ' . cmb_status_label( $booking->status ) . ' است.',
		);
	}

	/* ------------------------------------------------------------------
	 * ادامه‌ی پرداخت / انصراف
	 * --------------------------------------------------------------- */

	/**
	 * نوبت را از روی شناسه و توکن، یا مالکیت کاربرِ واردشده پیدا می‌کند.
	 */
	public static function resolve( $id, $tok ) {
		$booking = self::booking_by_token( $id, $tok );

		if ( $booking ) {
			return $booking;
		}

		$booking = $id ? CMB_Bookings::get( $id ) : null;

		if ( $booking && get_current_user_id() && (int) $booking->user_id === get_current_user_id() ) {
			return $booking;
		}

		return null;
	}

	/**
	 * پرداخت دوباره برای نوبتی که هنوز در انتظار است.
	 *
	 * @return array|WP_Error { url } یا { state: paid }
	 */
	public static function retry( $booking ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		// اول پرداخت‌های بی‌جواب: شاید همین حالا پول آمده باشد
		foreach ( self::for_booking( $booking->id ) as $p ) {
			if ( 'requested' === $p->status ) {
				self::verify_payment( $p, 'retry' );
			}
		}

		$booking = CMB_Bookings::get( $booking->id );

		if ( 'pending' !== $booking->status ) {
			if ( 'paid' === $booking->pay_status ) {
				return array( 'state' => 'paid' );
			}

			return new WP_Error( 'cmb_pay_closed', 'مهلت پرداخت این نوبت تمام شده است. لطفاً دوباره نوبت بگیرید.', array( 'status' => 409 ) );
		}

		$now  = cmb_now()->getTimestamp();
		$hold = strtotime( $booking->hold_until_gmt . ' UTC' );

		if ( $hold <= $now ) {
			self::expire_stale( true );

			return new WP_Error( 'cmb_pay_closed', 'مهلت پرداخت این نوبت تمام شده است. لطفاً دوباره نوبت بگیرید.', array( 'status' => 409 ) );
		}

		if ( count( self::for_booking( $booking->id ) ) >= self::MAX_ATTEMPTS ) {
			return new WP_Error( 'cmb_pay_attempts', 'تعداد تلاش‌های پرداخت برای این نوبت تمام شد. انصراف دهید و دوباره نوبت بگیرید.', array( 'status' => 429 ) );
		}

		/* اگر کمتر از ده دقیقه مانده، مهلت تمدید می‌شود تا مشتری وسط
		   درگاه جا را از دست ندهد؛ ولی هرگز بیش از یک ساعت از ثبت. */
		if ( $hold - $now < 10 * MINUTE_IN_SECONDS ) {
			$cap  = strtotime( get_gmt_from_date( $booking->created_at ) . ' UTC' ) + HOUR_IN_SECONDS;
			$hold = max( $hold, min( $cap, $now + 10 * MINUTE_IN_SECONDS ) );

			self::update_booking( $booking->id, array( 'hold_until_gmt' => gmdate( 'Y-m-d H:i:s', $hold ) ) );
		}

		return self::start( CMB_Bookings::get( $booking->id ) );
	}

	/**
	 * انصراف مشتری از پرداخت: جا همان لحظه آزاد می‌شود.
	 *
	 * @return object|WP_Error نوبت به‌روزشده.
	 */
	public static function abandon( $booking ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		foreach ( self::for_booking( $booking->id ) as $p ) {
			if ( 'requested' === $p->status ) {
				self::verify_payment( $p, 'abandon' );
			}
		}

		$booking = CMB_Bookings::get( $booking->id );

		if ( 'pending' === $booking->status ) {
			self::update_booking(
				$booking->id,
				array(
					'status'         => 'expired',
					'expire_reason'  => 'abandoned',
					'pay_status'     => 'unpaid',
					'hold_until_gmt' => null,
				)
			);
		}

		return CMB_Bookings::get( $booking->id );
	}

	/**
	 * خلاصه‌ی پرداخت یک نوبت برای خروجی‌ها.
	 */
	public static function summary( $booking ) {
		if ( ! isset( $booking->pay_status ) || ( '' === (string) $booking->pay_status && ! (int) $booking->deposit_amount ) ) {
			return null;
		}

		$labels = array(
			'unpaid'     => 'پرداخت نشده',
			'paid'       => 'پرداخت شده',
			'refund_due' => 'در صف بازگشت وجه',
			'refunding'  => 'در حال بازگشت وجه',
			'refunded'   => 'وجه بازگردانده شد',
			'kept'       => 'نزد مجموعه ماند',
		);

		$deposit = (int) $booking->deposit_amount;
		$refund  = (int) $booking->refund_amount;

		return array(
			'deposit'      => $deposit,
			'depositFa'    => cmb_toman( $deposit ),
			'status'       => (string) $booking->pay_status,
			'statusLabel'  => isset( $labels[ $booking->pay_status ] ) ? $labels[ $booking->pay_status ] : '',
			'refund'       => $refund,
			'refundFa'     => cmb_toman( $refund ),
			'cancelRefund' => (int) $booking->cancel_refund_amount,
			'cancelRefundFa' => cmb_toman( $booking->cancel_refund_amount ),
			'price'        => (int) $booking->price_at_booking,
			'remainingFa'  => (int) $booking->price_at_booking ? cmb_toman( max( 0, (int) $booking->price_at_booking - $deposit ) ) : '',
		);
	}
}
