<?php
/**
 * لایه‌ی ارسال پیامک — ملی‌پیامک (Melipayamak).
 *
 * دو حالت پشتیبانی می‌شود:
 *  1) pattern : ارسال با «خدمات پیشرفته / پترن» از طریق BaseServiceNumber (bodyId)
 *  2) simple  : ارسال متن آزاد با خط اختصاصی از طریق SendSMS
 *
 * برای ارسال کد تایید (OTP) حتماً باید از پترن استفاده شود، چون خطوط خدماتی
 * ملی‌پیامک متن آزاد OTP را ارسال نمی‌کنند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_SMS {

	const ENDPOINT_PATTERN = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
	const ENDPOINT_SIMPLE  = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
	const ENDPOINT_CREDIT  = 'https://rest.payamak-panel.com/api/SendSMS/GetCredit';

	/**
	 * ارسال پیامک با پترن.
	 *
	 * @param string   $phone    شماره‌ی گیرنده.
	 * @param string   $body_id  شناسه‌ی پترن (bodyId) در پنل ملی‌پیامک.
	 * @param string[] $args     مقادیر متغیرهای پترن، به ترتیب تعریف‌شده در پنل.
	 *
	 * @return true|WP_Error
	 */
	public static function send_pattern( $phone, $body_id, array $args ) {
		$phone = cmb_normalize_phone( $phone );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_sms_phone', 'شماره‌ی گیرنده معتبر نیست.' );
		}

		if ( ! self::is_enabled() ) {
			cmb_log( 'SMS disabled; skipping pattern send', compact( 'phone', 'body_id', 'args' ) );
			return true;
		}

		$body_id = trim( (string) $body_id );

		if ( '' === $body_id ) {
			return new WP_Error( 'cmb_sms_pattern', 'شناسه‌ی پترن تنظیم نشده است.' );
		}

		// مقادیر پترن با «;» جدا می‌شوند؛ خود مقادیر نباید حاوی ; باشند.
		$text = implode( ';', array_map( array( __CLASS__, 'clean_param' ), $args ) );

		$response = self::request(
			self::ENDPOINT_PATTERN,
			array(
				'username' => self::get_username(),
				'password' => self::get_password(),
				'text'     => $text,
				'to'       => $phone,
				'bodyId'   => $body_id,
			)
		);

		return self::handle_response( $response, 'pattern' );
	}

	/**
	 * ارسال پیامک متنی ساده (نیازمند خط اختصاصی).
	 *
	 * @return true|WP_Error
	 */
	public static function send_text( $phone, $message ) {
		$phone = cmb_normalize_phone( $phone );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_sms_phone', 'شماره‌ی گیرنده معتبر نیست.' );
		}

		if ( ! self::is_enabled() ) {
			cmb_log( 'SMS disabled; skipping text send', compact( 'phone', 'message' ) );
			return true;
		}

		$from = (string) CMB_Settings::get( 'sms_from', '' );

		if ( '' === $from ) {
			return new WP_Error( 'cmb_sms_from', 'شماره‌ی فرستنده (خط اختصاصی) تنظیم نشده است.' );
		}

		$response = self::request(
			self::ENDPOINT_SIMPLE,
			array(
				'username' => self::get_username(),
				'password' => self::get_password(),
				'to'       => $phone,
				'from'     => $from,
				'text'     => $message,
				'isflash'  => 'false',
			)
		);

		return self::handle_response( $response, 'simple' );
	}

	/**
	 * ارسال بر اساس نوع رویداد؛ اگر پترن تنظیم شده باشد از پترن، وگرنه متن ساده.
	 *
	 * @param string $phone    گیرنده.
	 * @param string $pattern_key کلید تنظیمات پترن، مثل pattern_booking.
	 * @param array  $args     مقادیر پترن.
	 * @param string $fallback  متن جایگزین برای حالت ارسال ساده.
	 *
	 * @return true|WP_Error
	 */
	public static function send_event( $phone, $pattern_key, array $args, $fallback = '' ) {
		$body_id = trim( (string) CMB_Settings::get( $pattern_key, '' ) );

		if ( '' !== $body_id ) {
			return self::send_pattern( $phone, $body_id, $args );
		}

		if ( '' !== $fallback && 'simple' === CMB_Settings::get( 'sms_api_mode', 'pattern' ) ) {
			return self::send_text( $phone, $fallback );
		}

		return new WP_Error( 'cmb_sms_no_pattern', sprintf( 'پترن «%s» تنظیم نشده است.', $pattern_key ) );
	}

	/**
	 * دریافت اعتبار پنل (برای تست اتصال در پنل مدیریت).
	 *
	 * @return float|WP_Error
	 */
	public static function get_credit() {
		$response = self::request(
			self::ENDPOINT_CREDIT,
			array(
				'username' => self::get_username(),
				'password' => self::get_password(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['RetStatus'] ) && 1 === (int) $response['RetStatus'] ) {
			return (float) $response['Value'];
		}

		return new WP_Error( 'cmb_sms_credit', self::status_message( isset( $response['RetStatus'] ) ? (int) $response['RetStatus'] : 0 ) );
	}

	/**
	 * درخواست HTTP به ملی‌پیامک.
	 *
	 * @return array|WP_Error پاسخ دیکد‌شده.
	 */
	protected static function request( $url, array $body ) {
		if ( '' === self::get_username() || '' === self::get_password() ) {
			return new WP_Error( 'cmb_sms_credentials', 'نام کاربری یا رمز عبور ملی‌پیامک تنظیم نشده است.' );
		}

		$args = array(
			'timeout'   => 20,
			'body'      => $body,
			'headers'   => array(
				'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
			),
			'sslverify' => true,
		);

		$response = wp_remote_post( $url, apply_filters( 'cmb_sms_request_args', $args, $url, $body ) );

		if ( is_wp_error( $response ) ) {
			cmb_log( 'SMS HTTP error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			cmb_log( 'SMS HTTP status ' . $code, array( 'body' => $raw ) );
			return new WP_Error( 'cmb_sms_http', sprintf( 'خطای ارتباط با سرویس پیامک (کد %d).', $code ) );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			cmb_log( 'SMS unexpected body', array( 'body' => $raw ) );
			return new WP_Error( 'cmb_sms_parse', 'پاسخ سرویس پیامک قابل خواندن نبود.' );
		}

		return $data;
	}

	/**
	 * بررسی نتیجه‌ی ارسال.
	 *
	 * @return true|WP_Error
	 */
	protected static function handle_response( $response, $context ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['RetStatus'] ) ? (int) $response['RetStatus'] : 0;
		$value  = isset( $response['Value'] ) ? (string) $response['Value'] : '';

		// در ارسال موفق، Value شناسه‌ی رکورد ارسال است و بزرگ‌تر از ۱۵ رقم/۰ است.
		if ( 1 === $status && '' !== $value && '0' !== $value ) {
			cmb_log( 'SMS sent (' . $context . ')', array( 'recId' => $value ) );
			return true;
		}

		$message = self::status_message( $status );
		cmb_log( 'SMS failed (' . $context . '): ' . $message, $response );

		return new WP_Error( 'cmb_sms_failed', $message, $response );
	}

	/**
	 * ترجمه‌ی کدهای وضعیت ملی‌پیامک.
	 */
	public static function status_message( $status ) {
		$map = array(
			0  => 'نام کاربری یا رمز عبور اشتباه است.',
			2  => 'اعتبار کافی نیست.',
			3  => 'محدودیت در ارسال روزانه.',
			4  => 'محدودیت در حجم ارسال.',
			5  => 'شماره‌ی فرستنده معتبر نیست.',
			6  => 'سامانه در حال به‌روزرسانی است.',
			7  => 'متن پیام حاوی کلمه‌ی فیلترشده است.',
			9  => 'ارسال از خطوط عمومی از طریق وب‌سرویس امکان‌پذیر نیست.',
			10 => 'کاربر مورد نظر فعال نیست.',
			11 => 'ارسال نشد.',
			12 => 'مدارک کاربر کامل نیست.',
			14 => 'متن پیامک با الگوی تعریف‌شده مطابقت ندارد.',
			15 => 'ارسال از خطوط عمومی مجاز نیست.',
			16 => 'شماره‌ی گیرنده یافت نشد.',
			17 => 'متن پیامک خالی است.',
			35 => 'شماره در لیست سیاه مخابرات است.',
		);

		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}

		return sprintf( 'ارسال پیامک ناموفق بود (کد %s).', $status );
	}

	/**
	 * پاک‌سازی مقادیر پترن؛ «;» و شکست خط در مقادیر مجاز نیست.
	 */
	public static function clean_param( $value ) {
		$value = (string) $value;
		$value = str_replace( array( ';', "\r", "\n" ), array( '،', ' ', ' ' ), $value );

		return trim( $value );
	}

	public static function is_enabled() {
		return (bool) CMB_Settings::get( 'sms_enabled', 1 );
	}

	protected static function get_username() {
		return trim( (string) CMB_Settings::get( 'sms_username', '' ) );
	}

	protected static function get_password() {
		return (string) CMB_Settings::get( 'sms_password', '' );
	}
}
