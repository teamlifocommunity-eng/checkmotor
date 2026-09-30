<?php
/**
 * ورود مسئول رزرو به پنل با کد تایید پیامکی.
 *
 * همان کد و همان پیامک اپ مشتری (CMB_OTP)، با سه فرق:
 *   ۱. فقط حسابی وارد می‌شود که به پنل دسترسی دارد؛ کاربر تازه ساخته
 *      نمی‌شود.
 *   ۲. کد با purpose=panel ذخیره می‌شود؛ کد اپ مشتری این‌جا پذیرفته
 *      نمی‌شود و برعکس.
 *   ۳. محدودیت حدس سخت‌تر است، چون این حساب به همه‌ی نوبت‌ها و
 *      شماره‌های مشتری‌ها دسترسی دارد: سه تلاش برای هر کد، و بعد از ده
 *      کد اشتباه در یک روز، ورود با کد برای آن شماره تا ۲۴ ساعت بسته
 *      می‌شود (ورود با رمز باز می‌ماند).
 *
 * مسیرها:  POST /wp-json/cmb/v1/panel/otp/send    { phone }
 *          POST /wp-json/cmb/v1/panel/otp/verify  { phone, code }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Panel_Otp {

	/** سقف تلاش برای هر کد (اپ مشتری پیش‌فرض ۵ دارد). */
	const MAX_ATTEMPTS = 3;

	/** سقف کد اشتباه برای یک شماره در ۲۴ ساعت. */
	const DAILY_FAILS = 10;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * روشن بودن ورود با کد.
	 *
	 * بدون حساب پیامک و پترن کد ورود، کدی به دست کسی نمی‌رسد؛ آن‌وقت
	 * نشان دادن این روش فقط مسئول رزرو را معطل می‌کند.
	 */
	public static function enabled() {
		if ( ! get_option( 'cmb_panel_otp', 1 ) || ! CMB_SMS::is_enabled() ) {
			return false;
		}

		if ( '' === trim( (string) CMB_Settings::get( 'sms_username', '' ) ) ) {
			return false;
		}

		return '' !== trim( (string) CMB_Settings::get( 'pattern_otp', '' ) )
			|| 'simple' === CMB_Settings::get( 'sms_api_mode', 'pattern' );
	}

	public function register_routes() {
		// عمداً بدون guard: کاربر هنوز وارد نشده است.
		register_rest_route(
			CMB_Panel_Api::NS,
			'/panel/otp/send',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			CMB_Panel_Api::NS,
			'/panel/otp/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * حسابِ دارای دسترسی پنل که این شماره مال اوست.
	 *
	 * CMB_OTP::find_user() فقط اولین حساب هم‌شماره را می‌دهد. اگر همان
	 * شماره یک حساب مشتری هم داشته باشد، ممکن است آن را برگرداند و
	 * مسئول رزرو بی‌دلیل رد شود. پس همه‌ی حساب‌های هم‌شماره را می‌بینیم
	 * و اولی را که به پنل دسترسی دارد برمی‌داریم.
	 *
	 * @return WP_User|false
	 */
	public static function find_manager( $phone ) {
		foreach ( CMB_OTP::users_with_phone( $phone ) as $user ) {
			if ( CMB_Panel_Api::user_can_manage( $user ) ) {
				return $user;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------ */

	public function send( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'cmb_potp_off', 'ورود با کد تایید فعال نیست. با نام کاربری و رمز وارد شوید.', array( 'status' => 400 ) );
		}

		$phone = cmb_normalize_phone( (string) $request->get_param( 'phone' ) );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_invalid_phone', 'شماره موبایل معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹', array( 'status' => 400 ) );
		}

		$locked = self::locked( $phone );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		if ( ! self::find_manager( $phone ) ) {
			return new WP_Error(
				'cmb_potp_unknown',
				'این شماره به هیچ حساب پنل رزرو وصل نیست. از مدیر سایت بخواهید شماره‌تان را در «رزرو نوبت ← کاربران پنل» ثبت کند، یا با نام کاربری و رمز وارد شوید.',
				array( 'status' => 404 )
			);
		}

		$result = CMB_OTP::request_code( $phone, 'panel' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'masked'       => $result['masked_phone'],
				'expiresIn'    => (int) $result['expires_in'],
				'resendAfter'  => (int) $result['resend_after'],
				'length'       => CMB_OTP::CODE_LENGTH,
			)
		);
	}

	public function verify( WP_REST_Request $request ) {
		$phone = cmb_normalize_phone( (string) $request->get_param( 'phone' ) );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_invalid_phone', 'شماره موبایل معتبر نیست.', array( 'status' => 400 ) );
		}

		$locked = self::locked( $phone );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$user = self::find_manager( $phone );

		if ( ! $user ) {
			return new WP_Error( 'cmb_potp_unknown', 'این شماره به هیچ حساب پنل رزرو وصل نیست.', array( 'status' => 404 ) );
		}

		$max = min( self::MAX_ATTEMPTS, max( 1, (int) CMB_Settings::get( 'otp_max_attempts', 5 ) ) );
		$ok  = CMB_OTP::consume_code( $phone, (string) $request->get_param( 'code' ), 'panel', $max );

		if ( is_wp_error( $ok ) ) {
			if ( 'cmb_otp_wrong' === $ok->get_error_code() ) {
				self::note_fail( $phone );
			}

			return $ok;
		}

		delete_transient( self::fail_key( $phone ) );

		CMB_Panel_Api::sync_login_cookie();

		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		do_action( 'wp_login', $user->user_login, $user );

		return rest_ensure_response(
			array(
				'success' => true,
				'name'    => $user->display_name,
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	protected static function fail_key( $phone ) {
		return 'cmb_potp_fail_' . md5( (string) $phone );
	}

	/** همان کلید محدودیت ورود با رمز: تلاش ناموفق از یک IP، با هر روشی. */
	protected static function ip_key() {
		return 'cmb_login_fail_' . md5( cmb_get_ip() );
	}

	/**
	 * @return true|WP_Error
	 */
	protected static function locked( $phone ) {
		if ( (int) get_transient( self::fail_key( $phone ) ) >= self::DAILY_FAILS ) {
			return new WP_Error(
				'cmb_potp_locked',
				'به‌خاطر کدهای اشتباه پشت‌سرهم، ورود با کد برای این شماره تا ۲۴ ساعت بسته شد. با نام کاربری و رمز وارد شوید.',
				array( 'status' => 429 )
			);
		}

		if ( (int) get_transient( self::ip_key() ) >= 8 ) {
			return new WP_Error(
				'cmb_login_throttled',
				'تلاش‌های ناموفق زیاد بود. چند دقیقه بعد دوباره امتحان کنید.',
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * هر کد اشتباه پنجره‌ی ۲۴ ساعته را از نو شروع می‌کند و ورود موفق
	 * شمارنده را صفر می‌کند. پس مسئول رزروی که گاهی اشتباه تایپ می‌کند
	 * قفل نمی‌شود، ولی کسی که پشت‌سرهم حدس می‌زند بعد از ده بار می‌ایستد.
	 */
	protected static function note_fail( $phone ) {
		$key = self::fail_key( $phone );
		set_transient( $key, (int) get_transient( $key ) + 1, DAY_IN_SECONDS );

		$ip = self::ip_key();
		set_transient( $ip, (int) get_transient( $ip ) + 1, 15 * MINUTE_IN_SECONDS );
	}
}
