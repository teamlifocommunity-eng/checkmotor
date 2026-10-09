<?php
/**
 * مدیریت تنظیمات افزونه (یک آپشن آرایه‌ای واحد).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Settings {

	const OPTION = 'cmb_settings';

	/**
	 * مقادیر پیش‌فرض تنظیمات.
	 */
	public static function defaults() {
		return array(
			// --- ظرفیت و زمان‌بندی ---
			'morning_start'         => '10:00',
			'morning_capacity'      => 2,
			'afternoon_start'       => '17:00',
			'afternoon_capacity'    => 3,
			'min_days_ahead'        => 1,   // رزرو برای همان روز مجاز نیست
			'min_hours_ahead'       => 24,  // شروع شیفت دست‌کم این‌قدر بعد از لحظه‌ی رزرو
			'window_days'           => 7,   // پنجره‌ی چرخشی ۷ روزه
			'max_active_per_user'   => 1,   // حداکثر نوبت فعال هم‌زمان برای هر کاربر
			'one_per_service'       => 1,   // از هر خدمت هم‌زمان فقط یک نوبت

			// --- لغو نوبت توسط مشتری ---
			'cancel_enabled'        => 1,   // مشتری خودش می‌تواند لغو کند
			'cancel_deadline_hours' => 24,  // تا چند ساعت پیش از شروع شیفت، لغو مجاز است

			// --- ملی‌پیامک ---
			'sms_enabled'           => 1,
			'sms_username'          => '',
			'sms_password'          => '',
			'sms_from'              => '',            // شماره‌ی اختصاصی برای ارسال ساده
			'sms_api_mode'          => 'pattern',     // pattern | simple
			'pattern_otp'           => '',
			'pattern_booking'       => '',
			'pattern_reminder'      => '',
			'pattern_admin'         => '',
			'pattern_cancel'        => '',
			'pattern_admin_cancel'  => '',

			// --- OTP ---
			'otp_ttl_seconds'       => 180,
			'otp_resend_seconds'    => 120,
			'otp_max_attempts'      => 5,
			'otp_hourly_limit_ip'   => 10,
			'otp_hourly_limit_phone'=> 5,
			'otp_dev_mode'          => 0,   // در حالت توسعه، کد در پاسخ برگردانده می‌شود

			// --- اطلاع‌رسانی ---
			'admin_sms_enabled'     => 1,
			'admin_phones'          => '',
			'auto_complete'          => 1,
			'reminder_enabled'      => 1,
			'reminder_hour'         => 18,  // ساعت ارسال یادآوری روز قبل

			// --- پرداخت بیعانه (زرین‌پال) ---
			'pay_enabled'               => 0,       // کلید اصلی؛ خاموش یعنی رزرو مثل قبل رایگان است
			'zp_merchant_id'            => '',      // خالی: مرچنت کد افزونه‌ی زرین‌پال ووکامرس
			'zp_sandbox'                => 0,       // درگاه آزمایشی زرین‌پال (پول واقعی جابه‌جا نمی‌شود)
			'pay_deposit_default'       => 100000,  // تومان؛ برای خدمت‌هایی که «پیش‌فرض» دارند
			'pay_cancel_refund_default' => 30000,   // تومان؛ بازگشتی به مشتری در لغوِ به‌موقع
			'pay_shop_refund_percent'   => 100,     // لغو از طرف مجموعه: پیش‌فرض بازگشت (درصد بیعانه)
			'pay_hold_minutes'          => 20,      // جا تا این مدت برای پرداخت نگه داشته می‌شود
			'pay_terms_text'            => '',      // خالی: متن پیش‌فرض (CMB_Payments::default_terms)
			'pay_refund_operators'      => 0,       // مسئول رزرو هم بتواند کیف پول مشتری را دستی تغییر دهد و برگشت‌های مانده را ببندد
			'pay_tick_key'              => '',      // کلید نشانی کرون پرداخت؛ خودکار ساخته می‌شود

			// --- کیف پول (برگشت‌ها به کیف پول مشتری می‌رود؛ برداشت به کارت ندارد) ---
			'wallet_topup'              => 1,       // مشتری کیف پول را با درگاه شارژ کند
			'wallet_topup_min'          => 10000,   // تومان
			'wallet_topup_max'          => 5000000, // تومان
			'pattern_wallet'            => '',      // پترن پیامک «تغییر کیف پول»: {0} نام، {1} مبلغ، {2} موجودی، {3} شرح

			// --- محل سکونت (استان و شهر) ---
			'location_mode'         => 0,       // 0 = شهر به‌صورت تایپ آزاد؛ 1 = انتخاب استان و شهر از فهرست

			// --- متن‌ها ---
			'confirm_note'          => 'از این صفحه اسکرین‌شات بگیرید و هنگام مراجعه نشان دهید.',
			'ecu_note'              => 'برای برخی ECUها ممکن است خدمات قابل انجام نباشد؛ در صورت تردید با شعبه تماس بگیرید.',
			'out_of_window_note'    => 'برای تاریخ‌های بیش از ۷ روز آینده، لطفاً مستقیماً با شعبه تماس بگیرید.',
			'late_rule_note'        => 'تا حدود یک ساعت پس از شروع شیفت پذیرش انجام می‌شود؛ دیرتر از آن نیاز به رزرو نوبت جدید است.',
			'branch_phone'          => '',
		);
	}

	/**
	 * همه‌ی تنظیمات.
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * یک تنظیم.
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) && '' !== $all[ $key ] ) {
			return $all[ $key ];
		}

		return null === $fallback ? ( isset( $all[ $key ] ) ? $all[ $key ] : '' ) : $fallback;
	}

	/**
	 * ذخیره‌ی تنظیمات (فقط کلیدهای شناخته‌شده).
	 */
	public static function update( array $values ) {
		$current = self::all();
		$allowed = array_keys( self::defaults() );

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$current[ $key ] = $value;
			}
		}

		update_option( self::OPTION, $current );

		// متن‌ها و ظرفیت‌ها داخل پاسخ کش‌شده‌ی خدمات هستند.
		if ( class_exists( 'CMB_Services' ) ) {
			CMB_Services::flush_cache();
		}

		return $current;
	}

	/**
	 * نوشتن پیش‌فرض‌ها هنگام نصب، بدون بازنویسی مقادیر موجود.
	 */
	/**
	 * قواعد تنظیمات برنامه‌ی کاری.
	 *
	 * هم پنل و هم پیشخوان از همین استفاده می‌کنند. پیش از این پیشخوان
	 * هر عددی را می‌پذیرفت — مثلاً «تعداد روزهای قابل رزرو = ۰» تقویم
	 * مشتری را کاملاً خالی می‌کرد و «ساعت شروع = abc» محاسبه‌ی مهلت
	 * لغو را به هم می‌ریخت.
	 */
	public static function schedule_rules() {
		return array(
			'morning_start'         => array( 'time' ),
			'afternoon_start'       => array( 'time' ),
			'morning_capacity'      => array( 'int', 0, 50 ),
			'afternoon_capacity'    => array( 'int', 0, 50 ),
			'min_days_ahead'        => array( 'int', 0, 30 ),
			'min_hours_ahead'       => array( 'int', 0, 336 ),
			'window_days'           => array( 'int', 1, 60 ),
			'max_active_per_user'   => array( 'int', 0, 10 ),
			'one_per_service'       => array( 'bool' ),
			'cancel_enabled'        => array( 'bool' ),
			'cancel_deadline_hours' => array( 'int', 0, 336 ),
		);
	}

	/**
	 * مقادیر برنامه‌ی کاری را بررسی و پاک‌سازی می‌کند.
	 *
	 * فقط کلیدهایی که در ورودی هستند برگردانده می‌شوند. اعداد به بازه‌ی
	 * مجاز محدود می‌شوند؛ ساعت نامعتبر خطا می‌دهد، چون حدس زدنش خطرناک
	 * است.
	 *
	 * @return array|WP_Error
	 */
	public static function sanitize_schedule( array $input ) {
		$clean = array();

		foreach ( self::schedule_rules() as $key => $rule ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			switch ( $rule[0] ) {
				case 'time':
					$value = cmb_en_num( trim( (string) $value ) );

					if ( ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
						return new WP_Error( 'cmb_bad_time', 'ساعت شروع شیفت باید به شکل ۱۰:۰۰ باشد.', array( 'status' => 400 ) );
					}

					// «9:00» و «09:00» یکی‌اند؛ همیشه دورقمی ذخیره می‌شود
					list( $h, $m ) = explode( ':', $value );
					$value         = sprintf( '%02d:%02d', (int) $h, (int) $m );
					break;

				case 'int':
					$value = (int) cmb_en_num( (string) $value );
					$value = max( $rule[1], min( $rule[2], $value ) );
					break;

				case 'bool':
					$value = $value ? 1 : 0;
					break;
			}

			$clean[ $key ] = $value;
		}

		/* شیفت عصر باید بعد از صبح شروع شود؛ وگرنه ترتیب نمایش و
		   محاسبه‌ی مهلت لغو به هم می‌ریزد. */
		$morning   = isset( $clean['morning_start'] ) ? $clean['morning_start'] : self::get( 'morning_start', '10:00' );
		$afternoon = isset( $clean['afternoon_start'] ) ? $clean['afternoon_start'] : self::get( 'afternoon_start', '17:00' );

		if ( strcmp( $afternoon, $morning ) <= 0 ) {
			return new WP_Error( 'cmb_bad_order', 'ساعت شروع شیفت بعدازظهر باید بعد از شیفت صبح باشد.', array( 'status' => 400 ) );
		}

		return $clean;
	}

	public static function install_defaults() {
		$saved = get_option( self::OPTION, null );

		if ( null === $saved || ! is_array( $saved ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}

		update_option( self::OPTION, wp_parse_args( $saved, self::defaults() ) );
	}

	/**
	 * شماره‌های مدیر برای پیامک اطلاع‌رسانی.
	 *
	 * @return string[]
	 */
	public static function admin_phones() {
		$raw = (string) self::get( 'admin_phones', '' );

		if ( '' === $raw ) {
			return array();
		}

		$parts  = preg_split( '/[\s,،;]+/u', $raw );
		$phones = array();

		foreach ( (array) $parts as $part ) {
			$phone = cmb_normalize_phone( $part );
			if ( $phone ) {
				$phones[] = $phone;
			}
		}

		return array_unique( $phones );
	}
}
