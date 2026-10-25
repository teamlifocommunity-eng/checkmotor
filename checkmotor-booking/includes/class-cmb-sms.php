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

	/** کلید پترنِ پیامکی که همین حالا فرستاده می‌شود (برای برچسب لاگ). */
	protected static $key = '';

	/** شناسه‌ی آخرین ارسال موفق در سامانه‌ی پیامک (recId). */
	protected static $rec = '';

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
		$raw     = $phone;
		$phone   = cmb_normalize_phone( $phone );
		$body_id = trim( (string) $body_id );
		// مقادیر پترن با «;» جدا می‌شوند؛ خود مقادیر نباید حاوی ; باشند.
		$text = implode( ';', array_map( array( __CLASS__, 'clean_param' ), $args ) );

		if ( ! $phone ) {
			return self::logged( $raw, 'pattern', $body_id, $text, new WP_Error( 'cmb_sms_phone', 'شماره‌ی گیرنده معتبر نیست.' ) );
		}

		if ( ! self::is_enabled() ) {
			cmb_log( 'SMS disabled; skipping pattern send', compact( 'phone', 'body_id', 'args' ) );
			return self::logged( $phone, 'pattern', $body_id, $text, new WP_Error( 'cmb_sms_off', 'پیامک در تنظیمات خاموش است.' ), true );
		}

		if ( '' === $body_id ) {
			return self::logged( $phone, 'pattern', $body_id, $text, new WP_Error( 'cmb_sms_pattern', 'شناسه‌ی پترن تنظیم نشده است.' ) );
		}

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

		return self::logged( $phone, 'pattern', $body_id, $text, self::handle_response( $response, 'pattern' ) );
	}

	/**
	 * ارسال پیامک متنی ساده (نیازمند خط اختصاصی).
	 *
	 * @return true|WP_Error
	 */
	public static function send_text( $phone, $message ) {
		$raw   = $phone;
		$phone = cmb_normalize_phone( $phone );

		if ( ! $phone ) {
			return self::logged( $raw, 'simple', '', $message, new WP_Error( 'cmb_sms_phone', 'شماره‌ی گیرنده معتبر نیست.' ) );
		}

		if ( ! self::is_enabled() ) {
			cmb_log( 'SMS disabled; skipping text send', compact( 'phone', 'message' ) );
			return self::logged( $phone, 'simple', '', $message, new WP_Error( 'cmb_sms_off', 'پیامک در تنظیمات خاموش است.' ), true );
		}

		$from = (string) CMB_Settings::get( 'sms_from', '' );

		if ( '' === $from ) {
			return self::logged( $phone, 'simple', '', $message, new WP_Error( 'cmb_sms_from', 'شماره‌ی فرستنده (خط اختصاصی) تنظیم نشده است.' ) );
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

		return self::logged( $phone, 'simple', '', $message, self::handle_response( $response, 'simple' ) );
	}

	/**
	 * ثبت ارسال در لاگ پیامک و برگرداندن همان نتیجه.
	 *
	 * @param bool $off پیامک در تنظیمات خاموش است: در لاگ «ارسال نشد» ولی برای
	 *                  صدازننده موفق (رفتار قبلی؛ سیستم بی‌پیامک هم کار می‌کند).
	 */
	protected static function logged( $phone, $channel, $body_id, $text, $result, $off = false ) {
		if ( class_exists( 'CMB_SMS_Log' ) ) {
			CMB_SMS_Log::sms( $phone, self::$key, $channel, $body_id, $text, $result, true === $result ? self::$rec : '', $off ? 'info' : '' );
		}

		self::$rec = '';

		return $off ? true : $result;
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
		$body_id   = trim( (string) CMB_Settings::get( $pattern_key, '' ) );
		self::$key = $pattern_key;

		if ( '' !== $body_id ) {
			$result = self::send_pattern( $phone, $body_id, $args );
		} elseif ( '' !== $fallback && 'simple' === CMB_Settings::get( 'sms_api_mode', 'pattern' ) ) {
			$result = self::send_text( $phone, $fallback );
		} else {
			// پیامکی که به‌خاطر نبودِ پترن اصلاً نرفت هم در لاگ دیده شود
			$result = self::logged( $phone, '-', '', implode( ';', array_map( array( __CLASS__, 'clean_param' ), $args ) ), new WP_Error( 'cmb_sms_no_pattern', sprintf( 'پترن «%s» در تنظیمات پیامک وارد نشده است.', class_exists( 'CMB_SMS_Log' ) ? CMB_SMS_Log::label( $pattern_key ) : $pattern_key ) ) );
		}

		self::$key = '';

		return $result;
	}

	/**
	 * اولین پترنِ تنظیم‌شده از یک فهرست؛ وگرنه متن ساده (فقط در حالت ساده).
	 *
	 * برای پیامک‌های نمایندگی: تا پترن تازه (با نام و آدرس مرکز) در پنل
	 * پیامک تأیید شود، همان پترن قبلی می‌رود — پیامک قطع نمی‌شود.
	 *
	 * @param array  $attempts [ [ کلید پترن, مقادیر ], ... ] به ترتیب ترجیح.
	 * @param string $fallback متن حالت ساده.
	 *
	 * @return true|WP_Error
	 */
	public static function send_first( $phone, array $attempts, $fallback = '' ) {
		foreach ( $attempts as $try ) {
			$body_id = trim( (string) CMB_Settings::get( $try[0], '' ) );

			if ( '' !== $body_id ) {
				self::$key = $try[0];
				$result    = self::send_pattern( $phone, $body_id, (array) $try[1] );
				self::$key = '';
				return $result;
			}
		}

		self::$key = $attempts ? (string) $attempts[0][0] : '';

		if ( '' !== $fallback && 'simple' === CMB_Settings::get( 'sms_api_mode', 'pattern' ) ) {
			$result = self::send_text( $phone, $fallback );
		} else {
			$result = self::logged( $phone, '-', '', $fallback, new WP_Error( 'cmb_sms_no_pattern', 'هیچ‌کدام از پترن‌های این پیامک تنظیم نشده است.' ) );
		}

		self::$key = '';

		return $result;
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
			self::$rec = $value;
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

	/**
	 * فهرست همه‌ی پیامک‌های سیستم برای راهنمای تنظیمات: کلید پترن، گیرنده،
	 * زمان ارسال، متغیرها به ترتیب، متن پیشنهادی پنل ملی‌پیامک و یک نمونه.
	 * ترتیب متغیرها همان ترتیبی است که کد می‌فرستد؛ تغییرش پیامک‌ها را بهم می‌ریزد.
	 *
	 * کل سیستم همین ۸ پترن است؛ مرکز و نمایندگی‌ها مشترک. پترن‌های قدیمی
	 * (اطلاع به مدیر، پترن‌های جدای نمایندگی) این‌جا نیستند: فقط اگر از قبل پر
	 * باشند، به‌عنوان پشتیبان یا جایگزین ارسال می‌شوند.
	 *
	 * @return array[]
	 */
	public static function catalog() {
		$code  = 'CM482913';
		$svc   = 'تقویت موتور';
		$date  = '1405/08/12';
		$shift = 'شیفت صبح';
		$name  = 'رضا احمدی';
		$title = 'چک موتور - نمایندگی اهواز';

		return array(
			array(
				'key'    => 'pattern_otp',
				'label'  => 'کد ورود',
				'to'     => 'مشتری و کاربران پنل',
				'when'   => 'ورود به اپ رزرو یا پنل با کد',
				'vars'   => array( 'کد تایید' ),
				'text'   => "کد ورود چک موتور: {0}",
				'sample' => array( '48213' ),
			),
			array(
				'key'    => 'pattern_booking',
				'label'  => 'تأیید نوبت',
				'to'     => 'مشتری',
				'when'   => 'ثبت نوبت در مرکز یا هر نمایندگی (و پرداخت بیعانه، اگر دارد)',
				'vars'   => array( 'نام مشتری', 'خدمت', 'تاریخ', 'شیفت', 'ساعت', 'کد پیگیری' ),
				'text'   => "{0} عزیز، نوبت شما ثبت شد\n{1}\n{2} {3} ساعت {4}\nکد: {5}\nچک موتور",
				'sample' => array( $name, $svc, $date, $shift, '08:00', $code ),
			),
			array(
				'key'    => 'pattern_reminder',
				'label'  => 'یادآوری روز قبل',
				'to'     => 'مشتری',
				'when'   => 'یک روز پیش از نوبت (مرکز و نمایندگی‌ها)، ساعت تنظیم‌شده',
				'vars'   => array( 'نام مشتری', 'خدمت', 'تاریخ', 'شیفت', 'ساعت' ),
				'text'   => "یادآوری: {0} عزیز، نوبت فردا {2} {3} ساعت {4}\n{1}\nچک موتور",
				'sample' => array( $name, $svc, $date, $shift, '08:00' ),
			),
			array(
				'key'    => 'pattern_cancel',
				'label'  => 'لغو نوبت',
				'to'     => 'مشتری',
				'when'   => 'لغو نوبت توسط خود مشتری یا توسط مرکز/نمایندگی',
				'vars'   => array( 'نام مشتری', 'خدمت', 'تاریخ', 'شیفت' ),
				'text'   => "{0} عزیز، نوبت {1} در {2} {3} لغو شد.\nچک موتور",
				'sample' => array( $name, $svc, $date, $shift ),
			),
			array(
				'key'    => 'pattern_new_booking',
				'label'  => 'نوبت جدید',
				'to'     => 'مدیر مرکز و نمایندگی‌ها',
				'when'   => 'ثبت هر نوبت؛ نوبت مرکز به شماره‌های مدیر، نوبت نمایندگی به شماره‌های همان نمایندگی',
				'vars'   => array( 'نام مشتری', 'موبایل', 'شهر', 'خودرو', 'موتور', 'خدمت', 'تاریخ', 'شیفت' ),
				'text'   => CMB_Bookings::new_booking_hint(),
				'sample' => array( $name, '09161234567', 'اهواز', '206', 'TU5', $svc, $date, $shift ),
			),
			array(
				'key'    => 'pattern_admin_cancel',
				'label'  => 'لغو توسط مشتری',
				'to'     => 'مدیر مرکز و نمایندگی‌ها',
				'when'   => 'مشتری خودش نوبت را لغو کند',
				'vars'   => array( 'خدمت', 'تاریخ', 'شیفت', 'نام مشتری', 'موبایل' ),
				'text'   => "لغو توسط مشتری\n{3} {4}\n{0}\n{1} {2}",
				'sample' => array( $svc, $date, $shift, $name, '09161234567' ),
			),
			array(
				'key'    => 'pattern_wallet',
				'label'  => 'کیف پول مشتری',
				'to'     => 'مشتری',
				'when'   => 'برگشت بیعانه به کیف پول، شارژ، یا تغییر دستی',
				'vars'   => array( 'نام مشتری', 'مبلغ', 'موجودی بعد از تغییر', 'شرح' ),
				'text'   => CMB_Wallet::pattern_hint(),
				'sample' => array( $name, '200,000', '350,000', 'بابت لغو نوبت ' . $code . ' به کیف پول شما برگشت' ),
			),
			array(
				'key'    => 'pattern_agency_wallet',
				'label'  => 'کیف پول نمایندگی',
				'to'     => 'نمایندگی',
				'when'   => 'شارژ موفق، موجودی کم یا تمام‌شده، و فعال شدن پنل نمایندگی',
				'vars'   => array( 'شرح', 'موجودی کیف پول' ),
				'text'   => "{0}\nموجودی کیف پول: {1} تومان\nچک موتور",
				'sample' => array( 'شارژ کیف پول: 2,000,000 تومان', '2,400,000' ),
			),
		);
	}

	/** متن پترن با مقادیر نمونه، برای نمایش «پیامک چه شکلی می‌شود». */
	public static function fill( $text, array $values ) {
		foreach ( $values as $i => $v ) {
			$text = str_replace( '{' . $i . '}', (string) $v, $text );
		}

		return $text;
	}

	protected static function get_username() {
		return trim( (string) CMB_Settings::get( 'sms_username', '' ) );
	}

	protected static function get_password() {
		return (string) CMB_Settings::get( 'sms_password', '' );
	}
}
