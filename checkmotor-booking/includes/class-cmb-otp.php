<?php
/**
 * ورود/ثبت‌نام با کد تایید پیامکی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_OTP {

	const CODE_LENGTH = 5;

	/**
	 * ساخت و ارسال کد تایید.
	 *
	 * @param string $phone   شماره‌ی موبایل خام.
	 * @param string $purpose login (اپ مشتری) یا panel (ورود مسئول رزرو). کدِ
	 *                        یکی در دیگری پذیرفته نمی‌شود.
	 *
	 * @return array|WP_Error آرایه‌ی نتیجه شامل expires_in و resend_after.
	 */
	public static function request_code( $phone, $purpose = 'login' ) {
		global $wpdb;

		$phone = cmb_normalize_phone( $phone );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_invalid_phone', 'شماره موبایل معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹', array( 'status' => 400 ) );
		}

		$table = cmb_table( 'otp' );
		$now   = current_time( 'mysql' );

		// جلوگیری از ارسال پشت‌سرهم.
		$resend_after = (int) CMB_Settings::get( 'otp_resend_seconds', 120 );
		$last         = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE phone = %s ORDER BY id DESC LIMIT 1", $phone ) ); // phpcs:ignore

		if ( $last ) {
			$elapsed = strtotime( $now ) - strtotime( $last );
			if ( $elapsed < $resend_after ) {
				return new WP_Error(
					'cmb_otp_throttled',
					sprintf( 'برای ارسال مجدد کد، %s ثانیه دیگر صبر کنید.', cmb_fa_num( $resend_after - $elapsed ) ),
					array(
						'status'       => 429,
						'retry_after'  => $resend_after - $elapsed,
					)
				);
			}
		}

		$limit_error = self::check_rate_limits( $phone );

		if ( is_wp_error( $limit_error ) ) {
			return $limit_error;
		}

		self::cleanup();

		$code = self::generate_code();
		$ttl  = (int) CMB_Settings::get( 'otp_ttl_seconds', 180 );

		// کدهای قبلیِ همین شماره باطل می‌شوند.
		$wpdb->update( $table, array( 'is_used' => 1 ), array( 'phone' => $phone, 'is_used' => 0 ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'phone'      => $phone,
				'code_hash'  => wp_hash_password( $code ),
				'purpose'    => $purpose,
				'attempts'   => 0,
				'is_used'    => 0,
				'ip'         => cmb_get_ip(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $ttl ),
				'created_at' => $now,
			)
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cmb_otp_db', 'ثبت کد تایید ناموفق بود. دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		$otp_id = (int) $wpdb->insert_id;

		$sent = CMB_SMS::send_event(
			$phone,
			'pattern_otp',
			array( $code ),
			sprintf( 'panel' === $purpose ? 'کد ورود به پنل مدیریت چک موتور: %s' : 'کد ورود شما به چک موتور: %s', $code )
		);

		if ( is_wp_error( $sent ) ) {
			// اگر ارسال ناموفق بود، کد را باطل می‌کنیم تا کاربر بتواند بلافاصله دوباره تلاش کند.
			$wpdb->update( $table, array( 'is_used' => 1 ), array( 'id' => $otp_id ) );

			if ( self::dev_mode() ) {
				cmb_log( 'OTP dev-mode fallback', array( 'phone' => $phone, 'code' => $code ) );
			} else {
				return new WP_Error( 'cmb_otp_sms', 'ارسال پیامک ناموفق بود: ' . $sent->get_error_message(), array( 'status' => 502 ) );
			}
		}

		$result = array(
			'phone'        => $phone,
			'masked_phone' => self::mask_phone( $phone ),
			'expires_in'   => $ttl,
			'resend_after' => $resend_after,
			'is_new_user'  => ! self::find_user( $phone ),
		);

		/* کد ورود پنل هرگز در پاسخ برنمی‌گردد، حتی در حالت توسعه. */
		if ( 'login' === $purpose && self::dev_mode() && ! self::is_privileged_phone( $phone ) ) {
			$result['dev_code'] = $code;
		}

		return $result;
	}

	/**
	 * بررسی کد و ورود/ثبت‌نام کاربر.
	 *
	 * @return array|WP_Error
	 */
	public static function verify_code( $phone, $code, $name = '' ) {
		$phone = cmb_normalize_phone( $phone );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_invalid_phone', 'شماره موبایل معتبر نیست.', array( 'status' => 400 ) );
		}

		$ok = self::consume_code( $phone, $code, 'login' );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$user_id = self::login_or_register( $phone, $name );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_userdata( $user_id );

		return array(
			'user_id'      => (int) $user_id,
			'display_name' => $user ? $user->display_name : '',
			'phone'        => $phone,
		);
	}

	/**
	 * بررسی و مصرف کد، بدون ورود کاربر.
	 *
	 * ورود پنل هم از همین استفاده می‌کند ولی نباید مثل اپ مشتری کاربر
	 * تازه بسازد؛ پس بررسی کد از ورود/ثبت‌نام جدا شده است.
	 *
	 * @param string $phone        شماره‌ی نرمال‌شده.
	 * @param string $code         کد واردشده (ارقام فارسی هم پذیرفته می‌شود).
	 * @param string $purpose      همان purpose هنگام ساخت کد.
	 * @param int    $max_attempts سقف تلاش برای یک کد؛ صفر یعنی تنظیمات.
	 *
	 * @return true|WP_Error
	 */
	public static function consume_code( $phone, $code, $purpose = 'login', $max_attempts = 0 ) {
		global $wpdb;

		$code = preg_replace( '/\D/', '', cmb_en_num( $code ) );

		if ( strlen( $code ) !== self::CODE_LENGTH ) {
			return new WP_Error( 'cmb_invalid_code', 'کد تایید را کامل وارد کنید.', array( 'status' => 400 ) );
		}

		$table = cmb_table( 'otp' );
		$now   = current_time( 'mysql' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE phone = %s AND purpose = %s AND is_used = 0 ORDER BY id DESC LIMIT 1", // phpcs:ignore
				$phone,
				$purpose
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'cmb_otp_missing', 'کد تاییدی برای این شماره ثبت نشده است. دوباره درخواست کد بدهید.', array( 'status' => 400 ) );
		}

		if ( strtotime( $row->expires_at ) < strtotime( $now ) ) {
			$wpdb->update( $table, array( 'is_used' => 1 ), array( 'id' => $row->id ) );
			return new WP_Error( 'cmb_otp_expired', 'کد تایید منقضی شده است. کد جدید درخواست کنید.', array( 'status' => 400 ) );
		}

		if ( $max_attempts < 1 ) {
			$max_attempts = (int) CMB_Settings::get( 'otp_max_attempts', 5 );
		}

		if ( (int) $row->attempts >= $max_attempts ) {
			$wpdb->update( $table, array( 'is_used' => 1 ), array( 'id' => $row->id ) );
			return new WP_Error( 'cmb_otp_attempts', 'تعداد تلاش‌های ناموفق زیاد بود. کد جدید درخواست کنید.', array( 'status' => 429 ) );
		}

		if ( ! wp_check_password( $code, $row->code_hash ) ) {
			$wpdb->update( $table, array( 'attempts' => (int) $row->attempts + 1 ), array( 'id' => $row->id ) );

			$left = $max_attempts - ( (int) $row->attempts + 1 );

			return new WP_Error(
				'cmb_otp_wrong',
				$left > 0
					? sprintf( 'کد وارد شده صحیح نیست. %s تلاش دیگر باقی مانده است.', cmb_fa_num( $left ) )
					: 'کد وارد شده صحیح نیست. کد جدید درخواست کنید.',
				array( 'status' => 400 )
			);
		}

		$wpdb->update( $table, array( 'is_used' => 1 ), array( 'id' => $row->id ) );

		return true;
	}

	/**
	 * ورود کاربر موجود یا ساخت کاربر جدید بر اساس شماره‌ی موبایل.
	 *
	 * @return int|WP_Error شناسه‌ی کاربر.
	 */
	protected static function login_or_register( $phone, $name = '' ) {
		$user    = self::find_user( $phone );
		$current = get_current_user_id();

		/* اگر کاربر از قبل با حساب وردپرسی خودش وارد شده (مثلاً از فرم
		   ورود سایت) و آن حساب هنوز شماره‌ای ندارد، شماره را به همان
		   حساب وصل می‌کنیم — نه اینکه او را به حساب دیگری پرتاب کنیم.
		   بدون این، کاربرِ واردشده تا آخرِ ویزارد می‌رفت و آن‌جا خطای
		   «شماره‌ی موبایل حساب کاربری شما ثبت نشده است» می‌گرفت. */
		if ( $current && ( ! $user || (int) $user->ID === $current ) ) {
			if ( '' === cmb_get_user_phone( $current ) ) {
				update_user_meta( $current, 'cmb_phone', $phone );
				update_user_meta( $current, 'cmb_registered_via', 'linked' );

				unset( self::$user_cache[ (string) $phone ] );
			}

			$me = get_userdata( $current );

			if ( '' !== $name && $me && ( '' === $me->display_name || $me->display_name === $phone || $me->user_login === $me->display_name ) ) {
				wp_update_user(
					array(
						'ID'           => $current,
						'display_name' => $name,
						'first_name'   => $name,
					)
				);
			}

			return $current;
		}

		if ( ! $user ) {
			/* نام کاربری را هم‌شکل بقیه‌ی سایت می‌گذاریم (خودِ شماره).
			   اگر به هر دلیل گرفته شده بود، به الگوی خودمان برمی‌گردیم. */
			$username = $phone;

			if ( username_exists( $username ) ) {
				$username = 'cm_' . $phone;
			}

			$email = $phone . '@sms.checkmotor.local';

			/* نقش کاربران تازه را از تنظیمات خود وردپرس می‌گیریم.
			   این سایت نقش پیش‌فرضش customer ووکامرس است؛ اگر نقش
			   اختصاصی خودمان را تحمیل کنیم، کاربر از پنل ووکامرس و
			   خریدهایش جا می‌ماند. */
			$role = (string) apply_filters( 'cmb_new_user_role', get_option( 'default_role', 'subscriber' ) );

			if ( ! get_role( $role ) ) {
				$role = 'subscriber';
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'user_email'   => $email,
					'display_name' => '' !== $name ? $name : $phone,
					'first_name'   => $name,
					'role'         => $role,
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			update_user_meta( $user_id, 'cmb_phone', $phone );
			update_user_meta( $user_id, 'cmb_registered_via', 'otp' );

			// کش جست‌وجو دیگر معتبر نیست: این شماره الان صاحب دارد.
			unset( self::$user_cache[ (string) $phone ] );
		} else {
			$user_id = (int) $user->ID;

			if ( '' !== $name && $user->display_name === $phone ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $name,
						'first_name'   => $name,
					)
				);
			}

			if ( '' === cmb_get_user_phone( $user_id ) ) {
				update_user_meta( $user_id, 'cmb_phone', $phone );
			}
		}

		// در بستر REST، کوکی تازه باید بلافاصله در $_COOKIE هم قرار بگیرد تا
		// نانسی که در همین درخواست تولید می‌کنیم با نشست جدید هم‌خوان باشد.
		add_action(
			'set_logged_in_cookie',
			function ( $logged_in_cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
			}
		);

		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		do_action( 'wp_login', get_userdata( $user_id )->user_login, get_userdata( $user_id ) );
		do_action( 'cmb_user_logged_in', $user_id, $phone );

		return (int) $user_id;
	}

	/**
	 * یافتن کاربر بر اساس شماره موبایل.
	 *
	 * @return WP_User|false
	 */
	/**
	 * کلیدهای متایی که ممکن است شماره‌ی موبایل کاربر در آن‌ها باشد.
	 *
	 * سایت‌های ایرانی معمولاً قبلاً یک سیستم ورود پیامکی دارند
	 * (ورودک، دیجیتس، ووکامرس و…) و شماره را جای خودشان ذخیره
	 * کرده‌اند. اگر فقط cmb_phone را نگاه کنیم، برای هر کاربر
	 * موجود یک حساب تکراری می‌سازیم.
	 *
	 * @return string[]
	 */
	public static function phone_meta_keys() {
		return (array) apply_filters(
			'cmb_phone_meta_keys',
			array(
				'cmb_phone',
				'digits_phone',
				'digt_countrycode',
				'billing_phone',
				'mobile',
				'user_mobile',
				'user_phone',
				'voorodak_mobile',
				'vd_mobile',
			)
		);
	}

	/**
	 * پیدا کردن کاربر بر اساس شماره‌ی موبایل.
	 *
	 * سه مسیر بررسی می‌شود، به همین ترتیب:
	 *   ۱. متاهای شناخته‌شده‌ی شماره
	 *   ۲. نام کاربری که خودش شماره است (رفتار ورودک و اکثر
	 *      افزونه‌های ورود پیامکی ایرانی)
	 *   ۳. حساب‌هایی که خود این افزونه ساخته (cm_09…)
	 */
	/**
	 * کش درون‌درخواستی جست‌وجوی شماره.
	 *
	 * find_user() تا هفت کوئری می‌زند (یکی برای هر کلید متا، بعد
	 * نام‌های کاربری). در یک درخواست چند بار صدا زده می‌شود — برای
	 * is_new_user، برای بررسی دسترسی، و در مسیر ورود — و هر بار همان
	 * پاسخ را می‌دهد.
	 */
	protected static $user_cache = array();

	public static function find_user( $phone ) {
		$phone = (string) $phone;

		if ( array_key_exists( $phone, self::$user_cache ) ) {
			return self::$user_cache[ $phone ];
		}

		self::$user_cache[ $phone ] = self::lookup_user( $phone );

		return self::$user_cache[ $phone ];
	}

	protected static function lookup_user( $phone ) {
		$phone = (string) $phone;

		foreach ( self::phone_meta_keys() as $key ) {
			$users = get_users(
				array(
					'meta_key'   => $key,   // phpcs:ignore
					'meta_value' => $phone, // phpcs:ignore
					'number'     => 1,
					'fields'     => 'all',
				)
			);

			if ( ! empty( $users ) ) {
				return $users[0];
			}
		}

		// نام کاربری برابر خودِ شماره — با و بدون صفر ابتدایی.
		$logins = array( $phone, ltrim( $phone, '0' ), '98' . ltrim( $phone, '0' ), '+98' . ltrim( $phone, '0' ) );

		foreach ( array_unique( $logins ) as $login ) {
			$user = get_user_by( 'login', $login );

			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', 'cm_' . $phone );

		return $user ? $user : false;
	}

	/**
	 * همه‌ی حساب‌هایی که این شماره را دارند، نه فقط اولی.
	 *
	 * find_user() برای ورود مشتری اولین حساب را کافی می‌داند. ورود پنل
	 * و ثبت شماره‌ی مسئول رزرو باید همه را ببینند: اولی ممکن است حساب
	 * مشتری همان شخص باشد و حساب پنلش دومی.
	 *
	 * @return WP_User[]
	 */
	public static function users_with_phone( $phone ) {
		global $wpdb;

		$phone = (string) $phone;
		$keys  = self::phone_meta_keys();
		$found = array();

		/* یک کوئری مستقیم، نه meta_query با OR: وردپرس برای هر کلید یک
		   join جدا روی usermeta می‌سازد و روی سایتی با کاربران زیاد کند
		   می‌شود. */
		$ids = $keys ? $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_value = %s AND meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') LIMIT 20', // phpcs:ignore
				array_merge( array( $phone ), array_values( $keys ) )
			)
		) : array();

		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );

			if ( $user ) {
				$found[ $user->ID ] = $user;
			}
		}

		$bare = ltrim( $phone, '0' );

		foreach ( array_unique( array( $phone, $bare, '98' . $bare, '+98' . $bare, 'cm_' . $phone ) ) as $login ) {
			$user = get_user_by( 'login', $login );

			if ( $user ) {
				$found[ $user->ID ] = $user;
			}
		}

		return array_values( $found );
	}

	/**
	 * محدودسازی نرخ درخواست کد.
	 *
	 * @return true|WP_Error
	 */
	protected static function check_rate_limits( $phone ) {
		global $wpdb;

		$table = cmb_table( 'otp' );
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS );

		$by_phone = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE phone = %s AND created_at > %s", $phone, $since ) ); // phpcs:ignore

		if ( $by_phone >= (int) CMB_Settings::get( 'otp_hourly_limit_phone', 5 ) ) {
			return new WP_Error( 'cmb_otp_limit_phone', 'تعداد درخواست کد برای این شماره در یک ساعت گذشته زیاد بوده است. بعداً تلاش کنید.', array( 'status' => 429 ) );
		}

		$ip = cmb_get_ip();

		if ( $ip ) {
			$by_ip = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip = %s AND created_at > %s", $ip, $since ) ); // phpcs:ignore

			if ( $by_ip >= (int) CMB_Settings::get( 'otp_hourly_limit_ip', 10 ) ) {
				return new WP_Error( 'cmb_otp_limit_ip', 'تعداد درخواست‌ها زیاد بوده است. کمی بعد تلاش کنید.', array( 'status' => 429 ) );
			}
		}

		return true;
	}

	/**
	 * حذف کدهای منقضی‌شده‌ی قدیمی.
	 */
	public static function cleanup() {
		global $wpdb;

		$table  = cmb_table( 'otp' );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
	}

	protected static function generate_code() {
		$min = (int) str_pad( '1', self::CODE_LENGTH, '0' );
		$max = (int) str_repeat( '9', self::CODE_LENGTH );

		return (string) wp_rand( $min, $max );
	}

	public static function mask_phone( $phone ) {
		return substr( $phone, 0, 4 ) . '***' . substr( $phone, -3 );
	}

	/**
	 * آیا این شماره به حسابی با دسترسی مدیریتی وصل است؟
	 *
	 * «حالت توسعه» کد تایید را داخل پاسخ API برمی‌گرداند تا موقع تست
	 * لازم نباشد پیامک واقعی فرستاده شود. خطرش این است که اگر روشن
	 * بماند، هر کسی که شماره‌ی مدیر را بداند می‌تواند کد را بگیرد و
	 * با دسترسی کامل وارد شود. پس برای شماره‌های مدیریتی هرگز کد
	 * برنمی‌گردد — تست با شماره‌ی معمولی همچنان کار می‌کند.
	 */
	protected static function is_privileged_phone( $phone ) {
		$user = self::find_user( $phone );

		if ( ! $user ) {
			return false;
		}

		foreach ( array( 'manage_options', 'edit_posts', 'edit_others_posts', 'upload_files' ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}

		/* مسئول رزرو هیچ‌کدام از دسترسی‌های بالا را ندارد ولی به پنل
		   دسترسی دارد؛ بدون این، حالت توسعه کد او را لو می‌داد. */
		return class_exists( 'CMB_Panel_Api' ) && CMB_Panel_Api::user_can_manage( $user );
	}

	protected static function dev_mode() {
		return (bool) CMB_Settings::get( 'otp_dev_mode', 0 );
	}
}
