<?php
/**
 * نقاط پایانی REST داشبورد مدیریت (فرانت).
 *
 * مسیر پایه: /wp-json/cmb/v1/panel/
 * دسترسی: هر کاربری که بتواند نوبت‌ها را مدیریت کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Panel_Api {

	const NS = 'cmb/v1';

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
	 * آیا کاربر جاری اجازه‌ی مدیریت نوبت‌ها را دارد؟
	 */
	public static function can() {
		$allowed = current_user_can( 'manage_options' ) || current_user_can( self::cap() );

		// نقش‌هایی که مدیر در تنظیمات اجازه داده است.
		if ( ! $allowed && is_user_logged_in() ) {
			$roles = (array) get_option( 'cmb_panel_roles', array() );

			if ( $roles ) {
				$user = wp_get_current_user();

				if ( array_intersect( (array) $user->roles, $roles ) ) {
					$allowed = true;
				}
			}
		}

		return (bool) apply_filters( 'cmb_can_manage', $allowed, get_current_user_id() )
			&& ( current_user_can( 'manage_options' ) || CMB_Branches::user_branch_id( get_current_user_id() ) > 0 );
	}

	/**
	 * همان can() برای هر کاربر دلخواه، نه فقط کاربر جاری.
	 *
	 * ورود با کد پیش از ورود کاربر باید بداند صاحب این شماره اجازه‌ی
	 * پنل دارد یا نه.
	 *
	 * @param WP_User|int $user
	 */
	public static function user_can_manage( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$allowed = user_can( $user, 'manage_options' ) || user_can( $user, self::cap() );

		if ( ! $allowed ) {
			$roles = (array) get_option( 'cmb_panel_roles', array() );

			if ( $roles && array_intersect( (array) $user->roles, $roles ) ) {
				$allowed = true;
			}
		}

		return (bool) apply_filters( 'cmb_can_manage', $allowed, (int) $user->ID )
			&& ( user_can( $user, 'manage_options' ) || CMB_Branches::user_branch_id( $user->ID ) > 0 );
	}

	/**
	 * کوکی ورودی که همین حالا ساخته می‌شود، در همین درخواست هم دیده شود.
	 *
	 * nonce به توکن نشست داخل کوکی ورود بسته است. بدون این، nonce ای که
	 * همراه پاسخ ورود برمی‌گشت با توکن خالی ساخته می‌شد و اولین درخواست
	 * پنل بعد از ورود با ۴۰۳ رد و دوباره فرستاده می‌شد.
	 */
	public static function sync_login_cookie() {
		add_action(
			'set_logged_in_cookie',
			function ( $logged_in_cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
			}
		);
	}

	/**
	 * نام دسترسی — برای اینکه یک جا تعریف شده باشد.
	 */
	public static function cap() {
		return 'cmb_manage_bookings';
	}

	public function guard() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cmb_panel_auth', 'برای ورود به پنل ابتدا وارد حساب خود شوید.', array( 'status' => 401 ) );
		}

		if ( ! self::can() ) {
			return new WP_Error( 'cmb_panel_forbidden', 'شما به پنل مدیریت دسترسی ندارید.', array( 'status' => 403 ) );
		}

		// کاربری که شعبه‌اش دیگر وجود ندارد، به هیچ داده‌ای دسترسی ندارد
		if ( ! CMB_Branches::panel_branch_id() ) {
			return new WP_Error( 'cmb_panel_forbidden', 'حساب شما به هیچ شعبه‌ای وصل نیست. با مدیر سایت تماس بگیرید.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * گاردِ کیف پول مشتری‌ها، بازگشت وجه، سلامت سیستم و بررسی بیعانه: طبق
	 * بند ۶.۳ سند فقط ادمین (CMB_Branches::can( 'center' )).
	 */
	public function guard_center() {
		$base = $this->guard();

		if ( true !== $base ) {
			return $base;
		}

		return CMB_Branches::can( 'center' )
			? true
			: new WP_Error( 'cmb_panel_forbidden', 'این بخش فقط برای مدیر کل است.', array( 'status' => 403 ) );
	}

	/** گاردِ مسیرهای فقط مدیر سایت (مدیریت نمایندگی‌ها، گزارش، برگشت کسر). */
	public function guard_admin() {
		$base = $this->guard();

		if ( true !== $base ) {
			return $base;
		}

		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'cmb_panel_forbidden', 'این بخش فقط برای مدیر سایت است.', array( 'status' => 403 ) );
	}

	/** خطای ۴۰۳ برای کاری که نقش کاربر اجازه نمی‌دهد. */
	protected static function role_denied( $what = '' ) {
		return new WP_Error( 'cmb_role_forbidden', '' !== $what ? $what : 'این کار با نقش شما مجاز نیست.', array( 'status' => 403 ) );
	}

	/* ------------------------------------------------------------------
	 * ثبت مسیرها
	 * --------------------------------------------------------------- */

	public function register_routes() {
		$guard = array( $this, 'guard' );

		// آپلود پوستر جدا ثبت می‌شود چون بدنه‌اش multipart است نه JSON.
		register_rest_route(
			self::NS,
			'/panel/poster',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_poster' ),
				'permission_callback' => $guard,
			)
		);

		// ورود به پنل: عمداً بدون guard، چون کاربر هنوز وارد نشده است.
		register_rest_route(
			self::NS,
			'/panel/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
			)
		);

		$center = array( $this, 'guard_center' );
		$admin  = array( $this, 'guard_admin' );

		$routes = array(
			'panel/summary'   => array( 'GET', 'summary', $guard ),
			'panel/board'     => array( 'GET', 'board', $guard ),
			'panel/bookings'  => array( 'GET', 'bookings', $guard ),
			'panel/booking'   => array( 'POST', 'update_booking', $guard ),
			'panel/services'  => array( 'GET', 'services', $guard ),
			'panel/service'   => array( 'POST', 'save_service', $guard ),
			'panel/service/delete' => array( 'POST', 'delete_service', $guard ),
			'panel/service/reset' => array( 'POST', 'reset_service', $guard ),
			'panel/customers' => array( 'GET', 'customers', $guard ),
			'panel/settings'  => array( 'GET', 'get_schedule', $guard ),
			'panel/settings/save' => array( 'POST', 'save_schedule', $guard ),
			'panel/health'    => array( 'GET', 'health', $center ),
			'panel/health/sweep' => array( 'POST', 'health_sweep', $center ),
			'panel/closures'  => array( 'GET', 'closures', $guard ),
			'panel/closure'   => array( 'POST', 'save_closure', $guard ),
			'panel/refunds'   => array( 'GET', 'refunds', $center ),
			'panel/refund'    => array( 'POST', 'update_refund', $center ),
			'panel/pay-review' => array( 'GET', 'pay_review', $center ),
			'panel/pay-report' => array( 'GET', 'pay_report', $center ),
			'panel/wallets'   => array( 'GET', 'wallets', $center ),
			'panel/wallet'    => array( 'GET', 'wallet', $center ),
			'panel/wallet/adjust' => array( 'POST', 'wallet_adjust', $center ),

			/* نمایندگی‌ها (بخش ۶.۵ سند). مسیرها هم‌سبک بقیه‌ی پنل‌اند: شناسه
			   در بدنه یا پارامتر، نه در خود نشانی. */
			'panel/branch'            => array( 'GET', 'branch_get', $guard ),
			'panel/branch/save'       => array( 'POST', 'branch_save', $guard ),
			'panel/branch/switch'     => array( 'POST', 'branch_switch', $admin ),
			'panel/branch-wallet'     => array( 'GET', 'branch_wallet', $guard ),
			'panel/branch-wallet/topup' => array( 'POST', 'branch_topup', $guard ),
			'panel/branch-wallet/receipt' => array( 'GET', 'branch_receipt', $guard ),
			'panel/admin/branches'    => array( 'GET', 'admin_branches', $admin ),
			'panel/admin/branch'      => array( 'POST', 'admin_branch_save', $admin ),
			'panel/admin/branch/status' => array( 'POST', 'admin_branch_status', $admin ),
			'panel/admin/branch/delete' => array( 'POST', 'admin_branch_delete', $admin ),
			'panel/admin/branch/users' => array( 'POST', 'admin_branch_users', $admin ),
			'panel/admin/branch/adjust' => array( 'POST', 'admin_branch_adjust', $admin ),
			'panel/admin/fee-refund'  => array( 'POST', 'admin_fee_refund', $admin ),
			'panel/admin/report'      => array( 'GET', 'admin_report', $admin ),
			'panel/admin/fees'        => array( 'POST', 'admin_fee_settings', $admin ),
		);

		foreach ( $routes as $path => $conf ) {
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					'methods'             => 'GET' === $conf[0] ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $conf[1] ),
					'permission_callback' => $conf[2],
				)
			);
		}

		/* همان مسیرها با نشانی و متدی که در جدول بخش ۶.۵ سند آمده (شناسه در
		   نشانی، PUT برای ویرایش). پنل خودش از مسیرهای بالا استفاده می‌کند؛
		   این‌ها برای هم‌خوانی با سند و هر ابزار بیرونی‌اند و همان گارد را دارند. */
		$spec = array(
			array( 'panel/branch', array( 'PUT' ), 'branch_save', $guard ),
			array( 'panel/schedule', array( 'GET' ), 'get_schedule', $guard ),
			array( 'panel/schedule', array( 'PUT' ), 'save_schedule', $guard ),
			array( 'panel/branch-wallet/receipt/(?P<id>\d+)', array( 'GET' ), 'branch_receipt', $guard ),
			array( 'panel/admin/branches', array( 'POST', 'PUT' ), 'admin_branch_save', $admin ),
			array( 'panel/admin/branches/(?P<id>\d+)', array( 'PUT' ), 'admin_branch_save', $admin ),
			array( 'panel/admin/branches/(?P<id>\d+)', array( 'DELETE' ), 'admin_branch_delete', $admin ),
			array( 'panel/admin/branches/(?P<id>\d+)/status', array( 'POST' ), 'admin_branch_status', $admin ),
			array( 'panel/admin/branches/(?P<id>\d+)/users', array( 'POST' ), 'admin_branch_users', $admin ),
			array( 'panel/admin/branches/(?P<id>\d+)/adjust', array( 'POST' ), 'admin_branch_adjust', $admin ),
			array( 'panel/admin/bookings/(?P<id>\d+)/fee-refund', array( 'POST' ), 'admin_fee_refund', $admin ),
		);

		foreach ( $spec as $conf ) {
			register_rest_route(
				self::NS,
				'/' . $conf[0],
				array(
					'methods'             => $conf[1],
					'callback'            => array( $this, $conf[2] ),
					'permission_callback' => $conf[3],
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * خلاصه‌ی وضعیت
	 * --------------------------------------------------------------- */

	/**
	 * ورود به پنل با نام کاربری و رمز.
	 *
	 * جدا از فرم ورود سایت است تا مسئول رزرو مجبور نباشد از
	 * wp-login.php رد شود (که با WPS Hide Login جابه‌جا شده).
	 */
	public function login( WP_REST_Request $request ) {
		$user_login = trim( (string) $request->get_param( 'user' ) );
		$password   = (string) $request->get_param( 'pass' );

		if ( '' === $user_login || '' === $password ) {
			return new WP_Error( 'cmb_login_empty', 'نام کاربری و رمز را وارد کنید.', array( 'status' => 400 ) );
		}

		/* محدودسازی تلاش ناموفق تا حدس‌زدن رمز ممکن نباشد. */
		$key   = 'cmb_login_fail_' . md5( cmb_get_ip() );
		$fails = (int) get_transient( $key );

		if ( $fails >= 8 ) {
			return new WP_Error(
				'cmb_login_throttled',
				'تلاش‌های ناموفق زیاد بود. چند دقیقه بعد دوباره امتحان کنید.',
				array( 'status' => 429 )
			);
		}

		self::sync_login_cookie();

		$user = wp_signon(
			array(
				'user_login'    => $user_login,
				'user_password' => $password,
				'remember'      => (bool) $request->get_param( 'remember' ),
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );

			return new WP_Error( 'cmb_login_bad', 'نام کاربری یا رمز درست نیست.', array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		if ( ! self::can() ) {
			// ورود درست بود ولی این حساب اجازه‌ی پنل ندارد.
			wp_logout();

			return new WP_Error(
				'cmb_login_forbidden',
				'این حساب به پنل رزرو دسترسی ندارد.',
				array( 'status' => 403 )
			);
		}

		delete_transient( $key );

		return rest_ensure_response(
			array(
				'success' => true,
				'name'    => $user->display_name,
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function summary( WP_REST_Request $request ) {
		return rest_ensure_response( self::summary_payload() );
	}

	/**
	 * داده‌ی پرکاربردترین نماها، داخل خود صفحه‌ی پنل.
	 *
	 * گران‌ترین بخش هر درخواست، بالا آمدن وردپرس با همه‌ی افزونه‌ها و
	 * قالب است، نه کوئری‌ها (هرکدام چند میلی‌ثانیه). پس به‌جای اینکه
	 * باز کردن «تخته‌ی روزها» و «نوبت‌ها» هرکدام یک رفت‌وبرگشت کامل
	 * بخواهد، همراه همان صفحه فرستاده می‌شوند. همان کد نقاط پایانی REST
	 * اجرا می‌شود تا شکل داده دقیقاً یکی باشد.
	 *
	 * @return array
	 */
	public static function preload_payload() {
		$api = self::instance();
		$out = array();

		$jobs = array(
			'board'    => array( 'board', array( 'days' => 7 ) ),
			'bookings' => array( 'bookings', array( 'scope' => 'upcoming', 'page' => 1 ) ),
		);

		foreach ( $jobs as $key => $job ) {
			$request = new WP_REST_Request( 'GET', '/' . self::NS . '/panel/' . $job[0] );
			$request->set_query_params( $job[1] );

			$response = rest_ensure_response( call_user_func( array( $api, $job[0] ), $request ) );

			if ( ! is_wp_error( $response ) && 200 === $response->get_status() ) {
				$out[ $key ] = $response->get_data();
			}
		}

		return $out;
	}

	/**
	 * داده‌ی خلاصه — هم برای REST هم برای تزریق در صفحه.
	 */
	public static function summary_payload() {
		global $wpdb;

		/* پنل همیشه تازه‌ترین وضعیت را نشان بدهد. بدون force صدا زده
		   می‌شود: گاردِ «روزی یک بار» خودش کار را انجام می‌دهد و اولین
		   بار باز کردن پنل در هر روز، جاروی همان روز را می‌زند. با
		   force، هر بار تازه‌سازی پنل یک UPDATE بی‌حاصل روی کل جدول
		   می‌فرستاد — شرط کوئری «booking_date < امروز» است و در طول
		   روز عوض نمی‌شود، پس اجرای دوم هیچ‌وقت ردیفی پیدا نمی‌کند. */
		if ( class_exists( 'CMB_Cron' ) ) {
			CMB_Cron::auto_complete_past();
		}

		$table  = cmb_table( 'bookings' );
		$today  = cmb_today();
		$branch = CMB_Branches::panel_branch_id();
		$scope  = CMB_Branches::scope_sql( $branch );

		/* شش شمارش با یک کوئری، نه شش کوئری جدا. فقط نوبت‌های شعبه‌ی جاری
		   پنل (D4). */
		$tomorrow = self::day_after( $today );
		$week_ago = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 7 * DAY_IN_SECONDS );
		$month_ago = gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( booking_date = %s AND status IN ('confirmed','done') ) AS today_c,
					SUM( booking_date = %s AND status IN ('confirmed','done') ) AS tomorrow_c,
					SUM( booking_date >= %s AND status = 'confirmed' )          AS upcoming_c,
					SUM( created_at >= %s AND status NOT IN ('pending','expired') ) AS week_c,
					SUM( status = 'no_show'   AND booking_date >= %s )          AS no_show_c,
					SUM( status = 'cancelled' AND booking_date >= %s )          AS cancelled_c
				 FROM {$table} WHERE {$scope}", // phpcs:ignore
				$today,
				$tomorrow,
				$today,
				$week_ago,
				$month_ago,
				$month_ago
			)
		);

		if ( class_exists( 'CMB_Payments' ) ) {
			CMB_Payments::expire_stale();
		}

		$counts = array(
			'refunds'   => CMB_Branches::can( 'center' ) ? CMB_Payments::count_open_refunds() : 0,
			'today'     => $row ? (int) $row->today_c : 0,
			'tomorrow'  => $row ? (int) $row->tomorrow_c : 0,
			'upcoming'  => $row ? (int) $row->upcoming_c : 0,
			'week'      => $row ? (int) $row->week_c : 0,
			'no_show'   => $row ? (int) $row->no_show_c : 0,
			'cancelled' => $row ? (int) $row->cancelled_c : 0,
		);

		// آمار خدمات در ۳۰ روز گذشته.
		$since    = gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) AS total FROM {$table} WHERE {$scope} AND booking_date >= %s AND status IN ('confirmed','done') GROUP BY service_id ORDER BY total DESC", $since ) ); // phpcs:ignore
		$by_service = array();

		foreach ( (array) $rows as $row ) {
			$service = CMB_Services::get_service( $row->service_id );

			$by_service[] = array(
				'id'    => (int) $row->service_id,
				'title' => $service ? $service->title : 'حذف‌شده',
				'total' => (int) $row->total,
			);
		}

		$branch_row = CMB_Services::get_branch( $branch );

		return array(
			'counts'    => $counts,
			'byService' => $by_service,
			'branch'    => $branch_row ? array(
				'id'    => (int) $branch_row->id,
				'title' => $branch_row->title,
				'phone' => $branch_row->phone,
				'type'  => CMB_Branches::is_agency( $branch_row ) ? 'agency' : 'center',
				'status' => CMB_Branches::status( $branch_row ),
			) : null,
			'ctx'       => self::context(),
			// هشدارهای پیکربندی پیامک و درگاه مال مرکز است
			'setup'     => CMB_Branches::can( 'center' ) ? self::setup_state() : array( 'missing' => array(), 'ready' => true, 'slugClash' => '', 'slug' => '' ),
			'today'     => array(
				'date'   => $today,
				'jalali' => cmb_jalali_date( $today, 'full' ),
			),
		);
	}

	/**
	 * شعبه و نقش کاربر جاری: کدام بخش‌های پنل دیده شود و کدام دکمه فعال
	 * باشد. سرور هر کار را دوباره خودش بررسی می‌کند؛ این فقط برای نمایش
	 * است.
	 */
	public static function context() {
		$branch = CMB_Branches::panel_branch_id();
		$b      = CMB_Branches::get( $branch );
		$admin  = current_user_can( 'manage_options' );
		$agency = CMB_Branches::is_agency( $b );

		$out = array(
			'role'      => CMB_Branches::user_role( get_current_user_id() ),
			'roleLabel' => CMB_Branches::role_label(),
			'isAdmin'   => $admin,
			'branchId' => (int) $branch,
			'agency'   => $agency,
			'branch'   => $b ? array(
				'id'          => (int) $b->id,
				'title'       => (string) $b->title,
				'type'        => $agency ? 'agency' : 'center',
				'status'      => CMB_Branches::status( $b ),
				'statusLabel' => CMB_Branches::status_label( CMB_Branches::status( $b ) ),
				'city'        => isset( $b->city ) ? (string) $b->city : '',
			) : null,
			'can'      => array(
				'schedule'      => CMB_Branches::can( 'schedule' ),
				'services'      => CMB_Branches::can( 'services' ),
				'catalog'       => CMB_Branches::can( 'catalog' ),
				'rules'         => CMB_Branches::can( 'rules' ),
				'createService' => $agency ? CMB_Branches::can( 'own_services' ) : CMB_Branches::can( 'catalog' ),
				'closures'      => CMB_Branches::can( 'closures' ),
				'delete'        => CMB_Branches::can( 'delete' ),
				'overbook'      => CMB_Branches::can( 'overbook' ),
				'export'        => CMB_Branches::can( 'export' ),
				'money'         => CMB_Branches::can( 'money' ),
				'profile'       => CMB_Branches::can( 'profile' ) && $agency,
				'wallet'        => CMB_Branches::can( 'wallet' ) && $agency,
				'topup'         => CMB_Branches::can( 'topup' ),
				'center'        => CMB_Branches::can( 'center' ),
			),
			'fee'      => CMB_Branches::fee(),
			'feeFa'    => cmb_toman( CMB_Branches::fee() ),
			'multi'    => CMB_Branches::ready() && count( CMB_Branches::all() ) > 1,
			// بخش «نمایندگی‌ها» فقط بعد از مهاجرت ۱.۹.۰ معنا دارد
			'ready'    => CMB_Branches::ready(),
			'branches' => array(),
		);

		if ( $admin && CMB_Branches::ready() ) {
			foreach ( CMB_Branches::all() as $row ) {
				$out['branches'][] = array(
					'id'     => (int) $row->id,
					'title'  => (string) $row->title,
					'type'   => CMB_Branches::is_agency( $row ) ? 'agency' : 'center',
					'status' => CMB_Branches::status( $row ),
				);
			}
		}

		if ( $agency && $out['can']['wallet'] ) {
			$out['wallet'] = CMB_Branch_Wallet::summary( (int) $branch );
		}

		return $out;
	}

	/**
	 * وضعیت پیکربندی — برای نمایش هشدارهای راه‌اندازی.
	 */
	protected static function setup_state() {
		$missing = array();

		if ( '' === trim( (string) CMB_Settings::get( 'sms_username', '' ) ) ) {
			$missing[] = 'حساب ملی‌پیامک';
		}

		if ( '' === trim( (string) CMB_Settings::get( 'pattern_otp', '' ) ) ) {
			$missing[] = 'پترن کد ورود';
		}

		if ( '' === trim( (string) CMB_Settings::get( 'pattern_booking', '' ) ) ) {
			$missing[] = 'پترن تاییدیه رزرو';
		}

		if ( empty( CMB_Settings::admin_phones() ) ) {
			$missing[] = 'شماره مدیر';
		}

		/* اگر برگه‌ای دقیقاً هم‌نام مسیر اپ باشد، هر دو یک نشانی را
		   می‌خواهند. قاعده‌ی بازنویسی برنده می‌شود و برگه هیچ‌وقت
		   دیده نمی‌شود — که گیج‌کننده است.

		   استثنا: برگه‌ی نگه‌دارنده‌ی شورت‌کد که خودِ افزونه ساخته.
		   نسخه‌های قدیمی آن را با اسلاگ «reserve» می‌ساختند، یعنی
		   افزونه درباره‌ی کار خودش هشدار می‌داد. آن برگه محتوایش
		   همان اپ است، پس برنده شدن اپ روی آن نشانی مشکلی نیست. */
		$clash = '';

		if ( function_exists( 'cmb_app_slug' ) ) {
			$page = get_page_by_path( cmb_app_slug() );
			$ours = (int) get_option( 'cmb_page_booking' );

			if ( $page && (int) $page->ID !== $ours ) {
				$clash = $page->post_title;
			}
		}

		return array(
			'missing'    => $missing,
			'ready'      => empty( $missing ),
			'slugClash'  => $clash,
			'slug'       => function_exists( 'cmb_app_slug' ) ? cmb_app_slug() : '',
		);
	}

	/* ------------------------------------------------------------------
	 * تخته‌ی روزها — ظرفیت و نوبت‌های هر شیفت
	 * --------------------------------------------------------------- */

	public function board( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();
		$days   = max( 1, min( 21, (int) $request->get_param( 'days' ) ?: 7 ) );
		$from   = sanitize_text_field( (string) $request->get_param( 'from' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = cmb_today();
		}

		$blocks = cmb_blocks( $branch );
		$out    = array();

		// خدمت‌هایی که سهمیه‌ی جداگانه دارند، کنار ظرفیت اصلی نشان داده می‌شوند.
		$own_services = array();

		foreach ( CMB_Services::get_services( $branch, false ) as $svc ) {
			$own = CMB_Services::own_capacity( $svc );

			if ( null !== $own ) {
				$own_services[] = array( 'service' => $svc, 'cap' => $own );
			}
		}

		/* نوبت‌های کل بازه با یک کوئری، نه یکی برای هر شیفت هر روز.
		   قبلاً برای ۷ روز حدود ۴۲ کوئری زده می‌شد. */
		$until   = gmdate( 'Y-m-d', strtotime( $from ) + ( $days - 1 ) * DAY_IN_SECONDS );
		$grouped = self::bookings_in_range( $branch, $from, $until );

		for ( $i = 0; $i < $days; $i++ ) {
			$date   = gmdate( 'Y-m-d', strtotime( $from ) + $i * DAY_IN_SECONDS );
			$counts = CMB_Availability::get_booked_counts( $branch, $date );
			$whole  = CMB_Availability::is_closed( $branch, $date, '' );

			$day_blocks = array();

			foreach ( $blocks as $key => $block ) {
				$booked = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
				$closed = $whole || CMB_Availability::is_closed( $branch, $date, $key );

				$quick = array();

				foreach ( $own_services as $os ) {
					$cap = isset( $os['cap'][ $key ] ) ? (int) $os['cap'][ $key ] : 0;

					if ( $cap < 1 ) {
						continue;
					}

					$sc = CMB_Availability::get_service_counts( (int) $os['service']->id, $branch, $date );

					$quick[] = array(
						'title'    => $os['service']->title,
						'capacity' => $cap,
						'booked'   => isset( $sc[ $key ] ) ? (int) $sc[ $key ] : 0,
					);
				}

				$day_blocks[] = array(
					'key'      => $key,
					'label'    => $block['label'],
					'start'    => $block['start'],
					'capacity' => (int) $block['capacity'],
					'booked'   => $booked,
					'closed'   => $closed,
					'quick'    => $quick,
					'items'    => isset( $grouped[ $date ][ $key ] ) ? $grouped[ $date ][ $key ] : array(),
				);
			}

			$out[] = array(
				'date'    => $date,
				'jalali'  => cmb_jalali_date( $date, 'short' ),
				'weekday' => cmb_weekday_name( $date ),
				'closed'  => $whole,
				'isToday' => $date === cmb_today(),
				'blocks'  => $day_blocks,
			);
		}

		return rest_ensure_response( array( 'days' => $out, 'from' => $from ) );
	}

	/**
	 * نوبت‌های یک بازه، گروه‌بندی‌شده بر اساس تاریخ و شیفت.
	 *
	 * @return array<string,array<string,array>>
	 */
	protected static function bookings_in_range( $branch_id, $from, $until ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE branch_id = %d AND booking_date BETWEEN %s AND %s AND status NOT IN ('cancelled','expired')
				 ORDER BY booking_date ASC, block_key ASC, id ASC", // phpcs:ignore
				(int) $branch_id,
				$from,
				$until
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row->booking_date ][ $row->block_key ][] = self::row_to_array( $row );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * فهرست نوبت‌ها
	 * --------------------------------------------------------------- */

	public function bookings( WP_REST_Request $request ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );
		$today = cmb_today();

		$scope  = sanitize_key( (string) $request->get_param( 'scope' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$date   = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$q      = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 25;

		// فقط نوبت‌های شعبه‌ی جاری پنل (D4)
		$where  = array( CMB_Branches::scope_sql( CMB_Branches::panel_branch_id() ) );
		$params = array();

		$has_date = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );

		/* تاریخ مشخص از تب دامنه مهم‌تر است.

		   پیش از این هر دو با AND کنار هم می‌نشستند، پس «تب پیش‌رو +
		   تاریخِ گذشته» یا «تب فردا + هر تاریخ دیگری» همیشه فهرست خالی
		   می‌داد — بدون اینکه معلوم باشد چرا. وقتی کاربر یک روز مشخص
		   را انتخاب می‌کند منظورش همان روز است، در هر بازه‌ای که
		   باشد. */
		if ( $has_date ) {
			$where[]  = 'booking_date = %s';
			$params[] = $date;
		} else {
			switch ( $scope ) {
				case 'today':
					$where[]  = 'booking_date = %s';
					$params[] = $today;
					break;
				case 'tomorrow':
					$where[]  = 'booking_date = %s';
					$params[] = self::day_after( $today );
					break;
				case 'past':
					$where[]  = 'booking_date < %s';
					$params[] = $today;
					break;
				case 'all':
					break;
				default:
					$where[]  = 'booking_date >= %s';
					$params[] = $today;
					break;
			}
		}

		if ( $status && array_key_exists( $status, cmb_statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		} else {
			// «پرداخت نشد» فقط با فیلتر خودش؛ وگرنه فهرست پر از تلاش‌های نیمه‌کاره می‌شد
			$where[] = "status <> 'expired'";
		}

		if ( '' !== $q ) {
			$like     = '%' . $wpdb->esc_like( cmb_en_num( $q ) ) . '%';
			$cols = array( 'customer_name', 'phone', 'tracking_code', 'car_brand', 'car_model' );

			// ستون شهر پیش از مهاجرت نیست؛ اشاره به آن کل جستجو را خراب می‌کرد
			if ( cmb_has_column( 'bookings', 'city' ) ) {
				$cols[] = 'city';
			}

			if ( cmb_has_column( 'bookings', 'province' ) ) {
				$cols[] = 'province';
			}

			$where[] = '(' . implode( ' OR ', array_map( function ( $c ) { return $c . ' LIKE %s'; }, $cols ) ) . ')';
			$params  = array_merge( $params, array_fill( 0, count( $cols ), $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		/* روی یک روزِ مشخص، همه‌ی ردیف‌ها یک تاریخ دارند و ترتیب
		   معکوس معنایی ندارد — شیفت صبح باید بالاتر از بعدازظهر
		   بماند. پس فقط تب «گذشته» معکوس می‌شود. */
		$order = ( 'past' === $scope && ! $has_date ) ? 'DESC' : 'ASC';

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY booking_date {$order}, block_key ASC, id ASC LIMIT %d OFFSET %d";
		$args = array_merge( $params, array( $per, ( $page - 1 ) * $per ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = self::row_to_array( $row );
		}

		return rest_ensure_response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'pages'    => (int) ceil( $total / $per ),
				'statuses' => cmb_statuses(),
			)
		);
	}

	/**
	 * تبدیل ردیف دیتابیس به آرایه‌ی خروجی پنل.
	 */
	protected static function row_to_array( $row ) {
		$service = CMB_Services::get_service( $row->service_id );

		return array(
			'id'          => (int) $row->id,
			'code'        => $row->tracking_code,
			'date'        => $row->booking_date,
			'dateFa'      => cmb_jalali_date( $row->booking_date, 'numeric' ),
			'dateLong'    => cmb_jalali_date( $row->booking_date, 'full' ),
			'block'       => $row->block_key,
			'blockLabel'  => cmb_block_label( $row->block_key ),
			'blockStart'  => cmb_block_start( $row->block_key, (int) $row->branch_id ),
			'service'     => $service ? $service->title : '—',
			'serviceId'   => (int) $row->service_id,
			'name'        => $row->customer_name,
			'phone'       => $row->phone,
			'province'    => isset( $row->province ) ? (string) $row->province : '',
			'city'        => isset( $row->city ) ? (string) $row->city : '',
			'carBrand'    => $row->car_brand,
			'carModel'    => $row->car_model,
			'carYear'     => $row->car_year,
			'carMileage'  => $row->car_mileage,
			'note'        => $row->note,
			'status'      => $row->status,
			'statusLabel' => self::status_label_for( $row ),
			'pay'         => CMB_Payments::summary( $row ),
			// دکمه‌ی حذف فقط برای کسی که اجازه‌اش را دارد
			'actions'     => array_values( array_diff( CMB_Bookings::actions_for( $row ), CMB_Branches::can( 'delete' ) ? array() : array( 'delete' ) ) ),
			'holdLeft'    => ( 'pending' === $row->status && ! empty( $row->hold_until_gmt ) )
				? max( 0, strtotime( $row->hold_until_gmt . ' UTC' ) - cmb_now()->getTimestamp() )
				: 0,
			'createdAt'   => $row->created_at,
			'createdAtFa' => cmb_jalali_date( substr( (string) $row->created_at, 0, 10 ), 'full' )
				. ' — ' . cmb_fa_num( substr( (string) $row->created_at, 11, 5 ) ),
			'branchId'    => (int) $row->branch_id,
			// هزینه‌ی رزرو نمایندگی: held (بلوکه) | captured | released | refunded
			'fee'         => isset( $row->branch_fee ) ? (int) $row->branch_fee : 0,
			'feeStatus'   => isset( $row->fee_status ) ? (string) $row->fee_status : '',
			'feeLabel'    => self::fee_label( $row ),
		);
	}

	/** برچسب وضعیت هزینه‌ی رزرو نمایندگی، برای ردیف نوبت. */
	protected static function fee_label( $row ) {
		if ( empty( $row->branch_fee ) || empty( $row->fee_status ) ) {
			return '';
		}

		$map = array(
			'held'     => 'بلوکه',
			'captured' => 'کسر شد',
			'released' => 'آزاد شد',
			'refunded' => 'برگشت داده شد',
		);

		$label = isset( $map[ $row->fee_status ] ) ? $map[ $row->fee_status ] : $row->fee_status;

		return sprintf( 'هزینه‌ی رزرو %s — %s', cmb_toman( (int) $row->branch_fee ), $label );
	}

	/**
	 * برچسب وضعیت، با ذکر اینکه لغو کارِ چه کسی بوده.
	 *
	 * مسئول رزرو باید در یک نگاه بفهمد نوبت را خودش لغو کرده یا
	 * مشتری — بدون این، هر دو فقط «لغو شده» دیده می‌شوند.
	 */
	protected static function status_label_for( $row ) {
		$label = cmb_status_label( $row->status );

		if ( 'cancelled' !== $row->status || ! isset( $row->cancelled_by ) ) {
			return $label;
		}

		if ( 'customer' === $row->cancelled_by ) {
			return $label . ' (توسط مشتری)';
		}

		if ( 'branch' === $row->cancelled_by ) {
			return $label . ' (توسط شعبه)';
		}

		if ( 'admin' === $row->cancelled_by ) {
			return $label . ' (توسط مدیریت مرکز)';
		}

		return $label;
	}

	/**
	 * تغییر وضعیت یا حذف نوبت.
	 */
	public function update_booking( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( ! $id ) {
			return new WP_Error( 'cmb_bad_id', 'شناسه‌ی نوبت معتبر نیست.', array( 'status' => 400 ) );
		}

		$current = CMB_Bookings::get( $id );

		if ( ! $current ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		// نوبت شعبه‌ی دیگر دیده و تغییر داده نمی‌شود (D4)
		$own = CMB_Branches::assert_branch( (int) $current->branch_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		if ( 'delete' === $status ) {
			if ( ! CMB_Branches::can( 'delete' ) ) {
				return self::role_denied( 'حذف نوبت فقط با مدیر شعبه است؛ اپراتور می‌تواند نوبت را لغو کند.' );
			}

			$deleted = CMB_Bookings::delete( $id );

			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}

			return rest_ensure_response( array( 'success' => true, 'deleted' => true ) );
		}

		$result = CMB_Bookings::set_status(
			$id,
			$status,
			array(
				// مبلغ برگشت دلخواه و ثبت بیش از ظرفیت تصمیم مدیر است؛ اپراتور با پیش‌فرض‌ها
				'refund' => CMB_Branches::can( 'refund_amount' ) ? $request->get_param( 'refund' ) : null,
				'force'  => CMB_Branches::can( 'overbook' ) && (bool) $request->get_param( 'force' ),
				// لغو مدیر سایت روی نوبت نمایندگی: release (پیش‌فرض) | capture
				'fee'    => sanitize_key( (string) $request->get_param( 'fee' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$booking = CMB_Bookings::get( $id );

		return rest_ensure_response(
			array(
				'success' => true,
				'item'    => $booking ? self::row_to_array( $booking ) : null,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * خدمات
	 * --------------------------------------------------------------- */

	public function services( WP_REST_Request $request ) {
		$items = array();
		$branch = CMB_Branches::panel_branch_id();
		$agency = CMB_Branches::is_agency( $branch );
		$define = CMB_Branches::can( 'catalog' );
		$customize = CMB_Branches::can( 'customize_services' );
		if ( $agency ) { CMB_Services::ensure_catalog( $branch ); }
		foreach ( CMB_Services::get_services( $branch, false ) as $service ) {
			if ( ! $define && 2 === (int) $service->is_active ) { continue; }
			$copy = CMB_Services::is_catalog_copy( $service );
			$poster = $service->poster_id ? wp_get_attachment_image_url( (int) $service->poster_id, 'medium' ) : '';
			$item = array(
				'id' => (int) $service->id, 'slug' => $service->slug,
				'title' => $service->title, 'description' => $service->description,
				'price' => (int) $service->price, 'duration' => $service->duration_note,
				'weekdays' => CMB_Services::allowed_weekdays( $service ),
				'active' => (int) $service->is_active, 'posterId' => (int) $service->poster_id,
				'poster' => $poster ? $poster : '', 'sortOrder' => (int) $service->sort_order,
				'ownCapacity' => CMB_Services::own_capacity( $service ),
				'catalog' => ( $copy && ! $customize ) || ( ! $agency && ! $define ),
				'fromCatalog' => $copy,
				'catalogId' => isset( $service->catalog_id ) ? (int) $service->catalog_id : 0,
				'customized' => CMB_Services::catalog_customized( $service ),
				'own' => $agency && ! $copy,
			);
			if ( ! $agency && $define ) {
				$item['deposit'] = isset( $service->deposit_amount ) ? (int) $service->deposit_amount : null;
				$item['cancelRefund'] = isset( $service->cancel_refund_amount ) ? (int) $service->cancel_refund_amount : null;
				$item['depositNow'] = CMB_Payments::service_deposit( $service );
				$item['refundNow'] = CMB_Payments::service_refund( $service );
			}
			$items[] = $item;
		}
		return rest_ensure_response( array(
			'items' => $items, 'catalog' => $agency || ! $define, 'center' => ! $agency,
			'canEdit' => CMB_Branches::can( 'services' ),
			'canAdd' => $agency ? CMB_Branches::can( 'own_services' ) : $define,
			'canCustomize' => $customize,
		) );
	}

	/**
	 * خدمتی که کاربر جاری اجازه‌ی ویرایشش را دارد.
	 *
	 * @return object|WP_Error
	 */
	protected static function own_service( $service_id ) {
		$service = CMB_Services::get_service( (int) $service_id );

		if ( ! $service ) {
			return new WP_Error( 'cmb_not_found', 'خدمت یافت نشد.', array( 'status' => 404 ) );
		}

		$own = CMB_Branches::assert_branch( (int) $service->branch_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		if ( ! CMB_Branches::can( 'services' ) ) {
			return self::role_denied( 'ویرایش خدمات فقط با مدیر شعبه است.' );
		}

		return $service;
	}

	/**
	 * آپلود پوستر خدمت (یا عکس شعبه) به کتابخانه‌ی رسانه.
	 *
	 * با for=branch عکس پروفایل شعبه است (مدیر نمایندگی یا مدیر سایت)؛
	 * پوستر خدمت نمایندگی از مرکز می‌آید و از اینجا عوض نمی‌شود.
	 */
	public function upload_poster( WP_REST_Request $request ) {
		$service_id = (int) $request->get_param( 'service_id' );
		$for_branch = 'branch' === $request->get_param( 'for' );
		$service    = null;

		if ( $for_branch ) {
			if ( ! CMB_Branches::can( 'profile' ) ) {
				return self::role_denied( 'عکس شعبه را فقط مدیر شعبه عوض می‌کند.' );
			}
		} elseif ( $service_id ) {
			$service = self::own_service( $service_id );

			if ( is_wp_error( $service ) ) {
				return $service;
			}

			if ( ( CMB_Services::is_catalog_copy( $service ) && ! CMB_Branches::can( 'customize_services' ) ) || ( ! CMB_Branches::is_agency( (int) $service->branch_id ) && ! CMB_Branches::can( 'catalog' ) ) ) {
				return new WP_Error( 'cmb_catalog_locked', 'پوستر خدمت را مدیر کل در کاتالوگ مرکز تعیین می‌کند.', array( 'status' => 403 ) );
			}
		} elseif ( CMB_Branches::is_agency( CMB_Branches::panel_branch_id() ) ? ! CMB_Branches::can( 'own_services' ) : ! CMB_Branches::can( 'catalog' ) ) {
			return self::role_denied();
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'cmb_no_file', 'فایلی دریافت نشد.', array( 'status' => 400 ) );
		}

		$file = $files['file'];

		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
		$type    = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';

		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error( 'cmb_bad_type', 'فقط JPG، PNG یا WebP قابل آپلود است.', array( 'status' => 400 ) );
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > 4 * MB_IN_BYTES ) {
			return new WP_Error( 'cmb_too_big', 'حجم تصویر باید کمتر از ۴ مگابایت باشد.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_sideload(
			array(
				'name'     => isset( $file['name'] ) ? $file['name'] : 'poster.jpg',
				'tmp_name' => $file['tmp_name'],
			),
			0
		);

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'cmb_upload_failed', 'آپلود ناموفق بود: ' . $id->get_error_message(), array( 'status' => 500 ) );
		}

		if ( $service ) {
			if ( ! CMB_Services::update_service( $service_id, array( 'poster_id' => (int) $id ) ) ) {
				return new WP_Error( 'cmb_db_error', 'تصویر بارگذاری شد اما به خدمت متصل نشد؛ دوباره تلاش کنید.', array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'posterId' => (int) $id,
				'poster'   => wp_get_attachment_image_url( (int) $id, 'medium' ),
			)
		);
	}

	public function save_service( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! CMB_Branches::can( 'services' ) ) { return self::role_denied( 'ویرایش خدمات فقط با مدیر شعبه است.' ); }
		$branch = CMB_Branches::panel_branch_id();
		$agency = CMB_Branches::is_agency( $branch );
		$service = $id ? self::own_service( $id ) : null;
		if ( is_wp_error( $service ) ) { return $service; }
		if ( ! $id && ( $agency ? ! CMB_Branches::can( 'own_services' ) : ! CMB_Branches::can( 'catalog' ) ) ) {
			return self::role_denied( 'ساخت خدمت تازه برای این شعبه مجاز نیست؛ مجوز را مدیر کل تعیین می‌کند.' );
		}
		$locked = $service && (
			( CMB_Services::is_catalog_copy( $service ) && ! CMB_Branches::can( 'customize_services' ) )
			|| ( ! $agency && ! CMB_Branches::can( 'catalog' ) )
		);
		if ( $service && 2 === (int) $service->is_active && ! CMB_Branches::can( 'catalog' ) ) {
			return self::role_denied( 'خدمت آزمایشی را فقط مدیر کل تغییر می‌دهد.' );
		}
		$data = array();
		if ( null !== $request->get_param( 'price' ) ) {
			$amount = cmb_en_num( (string) $request->get_param( 'price' ) );
			$amount = str_replace( array( ',', '٬', ' ' ), '', $amount );
			if ( ! is_numeric( $amount ) || (float) $amount < 0 || (float) $amount > 2000000000 ) {
				return new WP_Error( 'cmb_bad_price', 'قیمت باید عددی بین صفر و دو میلیارد تومان باشد.', array( 'status' => 400 ) );
			}
			$data['price'] = (int) $amount;
		}
		if ( null !== $request->get_param( 'weekdays' ) ) { $data['allowed_weekdays'] = CMB_Services::clean_weekdays( (array) $request->get_param( 'weekdays' ) ); }
		if ( null !== $request->get_param( 'active' ) ) {
			$data['is_active'] = max( 0, min( $agency || ! CMB_Branches::can( 'catalog' ) ? 1 : 2, (int) $request->get_param( 'active' ) ) );
		}
		$params = (array) $request->get_json_params();
		if ( ! $locked ) {
			foreach ( array( 'title' => 'title', 'description' => 'description', 'duration' => 'duration_note' ) as $param => $key ) {
				if ( null !== $request->get_param( $param ) ) {
					$data[ $key ] = 'description' === $param ? sanitize_textarea_field( (string) $request->get_param( $param ) ) : sanitize_text_field( (string) $request->get_param( $param ) );
				}
			}
			if ( null !== $request->get_param( 'sortOrder' ) ) { $data['sort_order'] = (int) $request->get_param( 'sortOrder' ); }
			if ( null !== $request->get_param( 'posterId' ) ) {
				$poster = max( 0, (int) $request->get_param( 'posterId' ) );
				if ( $poster && ! wp_attachment_is_image( $poster ) ) { return new WP_Error( 'cmb_bad_poster', 'تصویر انتخاب‌شده معتبر نیست.', array( 'status' => 400 ) ); }
				$data['poster_id'] = $poster;
			}
			if ( array_key_exists( 'ownCapacity', $params ) ) { $data['own_capacity'] = is_array( $params['ownCapacity'] ) ? $params['ownCapacity'] : null; }
			if ( ! $agency ) {
				foreach ( array( 'deposit' => 'deposit_amount', 'cancelRefund' => 'cancel_refund_amount' ) as $param => $key ) {
					if ( array_key_exists( $param, $params ) ) { $data[ $key ] = $params[ $param ]; }
				}
			}
		}
		if ( array_key_exists( 'title', $data ) && ( '' === $data['title'] || mb_strlen( $data['title'] ) > 190 ) ) {
			return new WP_Error( 'cmb_bad_title', 'عنوان خدمت را بین ۱ تا ۱۹۰ نویسه وارد کنید.', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'deposit_amount', $data ) || array_key_exists( 'cancel_refund_amount', $data ) ) {
			$deposit = array_key_exists( 'deposit_amount', $data ) ? CMB_Services::clean_amount( $data['deposit_amount'] ) : ( $service && isset( $service->deposit_amount ) ? (int) $service->deposit_amount : null );
			$refund = array_key_exists( 'cancel_refund_amount', $data ) ? CMB_Services::clean_amount( $data['cancel_refund_amount'] ) : ( $service && isset( $service->cancel_refund_amount ) ? (int) $service->cancel_refund_amount : null );
			$valid = CMB_Services::validate_amounts( $deposit, $refund );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		if ( ! $id ) {
			$data['branch_id'] = $branch;
			$created = CMB_Services::create_service( $data );
			return is_wp_error( $created ) ? $created : rest_ensure_response( array( 'success' => true, 'id' => $created, 'created' => true, 'message' => 'خدمت تازه ثبت شد.' ) );
		}
		if ( ! CMB_Services::update_service( $id, $data ) ) {
			return new WP_Error( 'cmb_db_error', 'تغییرات خدمت ذخیره نشد؛ دوباره تلاش کنید.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	public function reset_service( WP_REST_Request $request ) {
		$service = self::own_service( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $service ) ) { return $service; }
		$result = CMB_Services::reset_catalog_copy( $service );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true, 'message' => 'الگوی مرکزی بازگردانده شد؛ قیمت و روزهای نمایندگی حفظ شد.' ) );
	}

	public function delete_service( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$service = self::own_service( $id );

		if ( is_wp_error( $service ) ) {
			return $service;
		}

		if ( ! CMB_Branches::is_agency( (int) $service->branch_id ) && ! CMB_Branches::can( 'catalog' ) ) {
			return self::role_denied( 'حذف خدمت از کاتالوگ مرکز فقط با مدیر کل است.' );
		}

		$deleted = CMB_Services::delete_service( $id );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response( array( 'success' => true, 'message' => 'خدمت حذف شد.' ) );
	}

	/* ------------------------------------------------------------------
	 * تنظیمات شیفت
	 * --------------------------------------------------------------- */

	/**
	 * تنظیماتی که از پنل قابل ویرایش‌اند.
	 *
	 * فقط برنامه‌ی کاری: ساعت و ظرفیت شیفت‌ها، بازه‌ی رزرو و لغو.
	 * اطلاعات پیامک، شماره‌های مدیر و حالت توسعه عمداً اینجا نیستند و
	 * فقط در پیشخوان (با دسترسی مدیر کل) قابل تغییرند — مسئول رزرو
	 * نباید بتواند اعتبار پنل پیامک را ببیند یا عوض کند.
	 *
	 * پیش از این هیچ‌کدام از این‌ها در پنل نبود، و چون مسئول رزرو از
	 * پیشخوان به پنل برگردانده می‌شود، عملاً راهی برای تغییر ساعت یا
	 * ظرفیت شیفت نداشت.
	 */
	/**
	 * بررسی سلامت سیستم.
	 *
	 * سایت مدتی است کار می‌کند و داده‌هایش از نسخه‌های مختلف باقی
	 * مانده؛ این فهرست همان چیزهایی را نشان می‌دهد که به‌روزرسانی
	 * نمی‌تواند خودکار درست کند یا باید کسی از درستشان مطمئن شود.
	 */
	public function health( WP_REST_Request $request ) {
		return rest_ensure_response( CMB_Health::run() );
	}

	public function health_sweep( WP_REST_Request $request ) {
		$done = CMB_Health::sweep_past();

		return rest_ensure_response(
			array(
				'success' => true,
				'done'    => $done,
				'message' => $done
					? sprintf( '%s نوبت گذشته «انجام شده» شد.', cmb_fa_num( $done ) )
					: 'نوبت گذشته‌ای برای تعیین وضعیت نبود.',
			)
		);
	}

	public function get_schedule( WP_REST_Request $request ) {
		$out    = array();
		$branch = CMB_Branches::panel_branch_id();
		$agency = CMB_Branches::is_agency( $branch );

		/* شعبه فقط ساعت و ظرفیت شیفت‌های خودش را دارد؛ بازه‌ی رزرو، سقف نوبت
		   مشتری و قواعد لغو برای همه‌ی شعبه‌ها یکی است و فقط مدیر کل
		   تغییرش می‌دهد (D7). */
		$global = current_user_can( 'manage_options' ) && ! $agency;
		$keys   = $global ? array_keys( CMB_Settings::schedule_rules() ) : CMB_Branches::schedule_keys();

		foreach ( $keys as $key ) {
			$out[ $key ] = $agency ? cmb_branch_setting( $branch, $key ) : CMB_Settings::get( $key );
		}

		return rest_ensure_response(
			array(
				'settings' => $out,
				'keys'     => $keys,
				'agency'   => $agency,
				'global'   => $global,
				'canEdit'  => CMB_Branches::can( 'schedule' ),
				// مدیر کل لینک تنظیمات کامل (پیامک و …) را هم می‌بیند
				'fullUrl'  => ( current_user_can( 'manage_options' ) && ! $agency ) ? esc_url_raw( admin_url( 'admin.php?page=cmb-settings' ) ) : '',
			)
		);
	}

	public function save_schedule( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_Error( 'cmb_bad_body', 'داده‌ای ارسال نشد.', array( 'status' => 400 ) );
		}

		if ( ! CMB_Branches::can( 'schedule' ) ) {
			return self::role_denied( 'تغییر برنامه‌ی کاری فقط با مدیر شعبه است.' );
		}

		$branch = CMB_Branches::panel_branch_id();

		// قواعد سراسری (بازه‌ی رزرو، سقف نوبت، لغو) فقط با مدیر کل؛ بقیه فقط شیفت‌ها
		if ( CMB_Branches::is_agency( $branch ) || ! current_user_can( 'manage_options' ) ) {
			$params = array_intersect_key( $params, array_flip( CMB_Branches::schedule_keys() ) );
		}

		$clean = CMB_Branches::save_schedule( $branch, $params );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		cmb_log( 'Schedule settings updated from panel', array( 'user' => get_current_user_id(), 'branch' => $branch, 'keys' => array_keys( $clean ) ) );

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => 'تنظیمات شیفت ذخیره شد.',
				'settings' => $clean,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * مشتری‌ها
	 * --------------------------------------------------------------- */

	/**
	 * فهرست مشتری‌ها، ساخته‌شده از خود نوبت‌ها.
	 *
	 * عمداً جدول جداگانه ندارد. هر کس نوبت ثبت کرده یک مشتری است و
	 * شماره‌ی موبایلش کلید یکتای اوست؛ با جدول جدا، همان داده در دو
	 * جا نگه داشته می‌شد و دیر یا زود از هم فاصله می‌گرفت. این فهرست
	 * همیشه با واقعیت یکی است و با کاربران عمومی سایت قاطی نمی‌شود،
	 * چون فقط کسانی را می‌آورد که واقعاً نوبت گرفته‌اند.
	 */
	public function customers( WP_REST_Request $request ) {
		global $wpdb;

		if ( $request->get_param( 'all' ) && ! CMB_Branches::can( 'export' ) ) {
			return self::role_denied( 'خروجی اکسل مشتری‌ها فقط با مدیر شعبه است.' );
		}

		$table = cmb_table( 'bookings' );
		$q     = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = 25;

		$branch = CMB_Branches::panel_branch_id();
		$scope  = CMB_Branches::scope_sql( $branch );

		// تلاش‌های پرداخت‌نشده مشتری حساب نمی‌شوند؛ فقط مشتری‌های همین شعبه (D4)
		$where  = array( $scope, "phone <> ''", "status NOT IN ('pending','expired')" );
		$params = array();

		if ( '' !== $q ) {
			$like     = '%' . $wpdb->esc_like( cmb_en_num( $q ) ) . '%';
			$cols = array( 'customer_name', 'phone', 'car_brand', 'car_model' );

			if ( cmb_has_column( 'bookings', 'city' ) ) {
				$cols[] = 'city';
			}

			if ( cmb_has_column( 'bookings', 'province' ) ) {
				$cols[] = 'province';
			}

			$where[] = '(' . implode( ' OR ', array_map( function ( $c ) { return $c . ' LIKE %s'; }, $cols ) ) . ')';
			$params  = array_merge( $params, array_fill( 0, count( $cols ), $like ) );
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(DISTINCT phone) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		/* یک کوئری گروهی برای آمار، و یک کوئری برای آخرین نوبتِ هر
		   مشتری (نام و خودرو از تازه‌ترین ثبت خوانده می‌شود، نه از
		   قدیمی‌ترین). */
		$sql = "SELECT phone,
				COUNT(*) AS total,
				MAX(id) AS last_id,
				MIN(created_at) AS first_seen,
				MAX(created_at) AS last_seen,
				MAX(booking_date) AS last_date,
				MAX(user_id) AS user_id,
				SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done,
				SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
				SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS noshow
			FROM {$table}
			WHERE {$where_sql}
			GROUP BY phone
			ORDER BY MAX(created_at) DESC
			LIMIT %d OFFSET %d";

		/* حالت خروجی: همه‌ی مشتری‌ها در یک پاسخ، برای ساخت CSV در
		   مرورگر. سقف دارد تا یک سایت پرترافیک حافظه‌ی PHP را پر
		   نکند. */
		if ( $request->get_param( 'all' ) ) {
			$per  = 5000;
			$page = 1;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore

		$last = array();

		if ( $rows ) {
			$ids          = array_map( 'intval', wp_list_pluck( $rows, 'last_id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$fields = 'id, customer_name, car_brand, car_model, car_year' . ( cmb_has_column( 'bookings', 'city' ) ? ', city' : '' );
			$recent = $wpdb->get_results( $wpdb->prepare( "SELECT {$fields} FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore

			foreach ( $recent as $r ) {
				$last[ (int) $r->id ] = $r;
			}
		}

		/* خدمت‌هایی که هر مشتری گرفته — با یک کوئری برای کل صفحه،
		   نه یکی به ازای هر مشتری. */
		$services_by_phone = array();

		if ( $rows ) {
			$phones       = wp_list_pluck( $rows, 'phone' );
			$placeholders = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
			$svc_table    = cmb_table( 'services' );

			$pairs = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT DISTINCT b.phone, s.title
					 FROM {$table} b
					 LEFT JOIN {$svc_table} s ON s.id = b.service_id
					 WHERE " . CMB_Branches::scope_sql( $branch, 'b.branch_id' ) . " AND b.phone IN ({$placeholders})",
					$phones
				)
			);

			foreach ( $pairs as $pair ) {
				if ( ! $pair->title ) {
					continue;
				}

				if ( ! isset( $services_by_phone[ $pair->phone ] ) ) {
					$services_by_phone[ $pair->phone ] = array();
				}

				$services_by_phone[ $pair->phone ][] = $pair->title;
			}
		}

		// موجودی کیف پولِ مشتری‌های همین صفحه، با یک کوئری. کیف پول مشتری مال مرکز است.
		$show_wallet = CMB_Wallet::ready() && CMB_Branches::can( 'center' );
		$balances    = ( $rows && $show_wallet ) ? CMB_Wallet::balances( wp_list_pluck( $rows, 'phone' ) ) : array();

		$items = array();

		foreach ( (array) $rows as $row ) {
			$recent = isset( $last[ (int) $row->last_id ] ) ? $last[ (int) $row->last_id ] : null;
			$key    = CMB_Wallet::key( $row->phone );
			$bal    = isset( $balances[ $key ] ) ? (int) $balances[ $key ] : 0;

			$items[] = array(
				'phone'     => $row->phone,
				'phoneFa'   => cmb_fa_num( $row->phone ),
				'name'      => $recent ? $recent->customer_name : '',
				'city'      => ( $recent && isset( $recent->city ) ) ? (string) $recent->city : '',
				'car'       => $recent
					? implode( ' · ', array_filter( array( $recent->car_brand, $recent->car_model, $recent->car_year ) ) )
					: '',
				'total'     => (int) $row->total,
				'done'      => (int) $row->done,
				'cancelled' => (int) $row->cancelled,
				'noshow'    => (int) $row->noshow,
				'services'  => isset( $services_by_phone[ $row->phone ] )
					? implode( '، ', $services_by_phone[ $row->phone ] )
					: '',
				'hasUser'   => (int) $row->user_id > 0,
				'userId'    => (int) $row->user_id,
				'firstSeen' => cmb_jalali_date( substr( (string) $row->first_seen, 0, 10 ), 'short' ),
				'lastDate'  => cmb_jalali_date( $row->last_date, 'numeric' ),
				'wallet'    => $bal,
				'walletFa'  => cmb_toman( $bal ),
			);
		}

		return rest_ensure_response(
			array(
				'items'  => $items,
				'total'  => $total,
				'page'   => $page,
				'pages'  => (int) ceil( $total / $per ),
				'wallet' => $show_wallet,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * کیف پول مشتری‌ها
	 * --------------------------------------------------------------- */

	/**
	 * فهرست کیف پول‌ها، جمع موجودی همه، و تعداد برگشت‌های مانده.
	 */
	public function wallets( WP_REST_Request $request ) {
		if ( ! CMB_Wallet::ready() ) {
			return new WP_Error( 'cmb_wallet_off', 'کیف پول فعال نیست.', array( 'status' => 404 ) );
		}

		$filter = 'all' === $request->get_param( 'filter' ) ? 'all' : 'balance';
		$list   = CMB_Wallet::list_wallets(
			sanitize_text_field( (string) $request->get_param( 'q' ) ),
			$filter,
			max( 1, (int) $request->get_param( 'page' ) )
		);

		return rest_ensure_response(
			$list + array(
				'filter'    => $filter,
				'totals'    => CMB_Wallet::totals(),
				'canAdjust' => self::can_refund(),
				'legacy'    => CMB_Payments::count_open_refunds(),
				'migrated'  => get_option( 'cmb_wallet_migrated' ) ? get_option( 'cmb_wallet_migrated' ) : null,
			)
		);
	}

	/**
	 * کیف پول یک مشتری: موجودی و تاریخچه‌ی کامل.
	 */
	public function wallet( WP_REST_Request $request ) {
		if ( ! CMB_Wallet::ready() ) {
			return new WP_Error( 'cmb_wallet_off', 'کیف پول فعال نیست.', array( 'status' => 404 ) );
		}

		$phone = CMB_Wallet::key( (string) $request->get_param( 'phone' ) );

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_wallet_phone', 'شماره‌ی موبایل معتبر نیست.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( self::wallet_payload( $phone ) );
	}

	protected static function wallet_payload( $phone ) {
		$balance = CMB_Wallet::balance( $phone );
		$held    = CMB_Wallet::held( $phone );

		return array(
			'phone'     => $phone,
			'phoneFa'   => cmb_fa_num( $phone ),
			'name'      => CMB_Wallet::name_for( $phone ),
			'balance'   => $balance,
			'balanceFa' => cmb_toman( $balance ),
			'held'      => $held,
			'heldFa'    => cmb_toman( $held ),
			'history'   => CMB_Wallet::history( $phone, 200, true ),
			'canAdjust' => self::can_refund(),
		);
	}

	/**
	 * افزایش یا کاهش دستی کیف پول؛ با توضیح الزامی.
	 */
	public function wallet_adjust( WP_REST_Request $request ) {
		if ( ! self::can_refund() ) {
			return new WP_Error( 'cmb_refund_forbidden', 'تغییر کیف پول مشتری فقط برای مدیر سایت مجاز است.', array( 'status' => 403 ) );
		}

		if ( ! CMB_Wallet::ready() ) {
			return new WP_Error( 'cmb_wallet_off', 'کیف پول فعال نیست.', array( 'status' => 404 ) );
		}

		$amount = (int) cmb_en_num( (string) $request->get_param( 'amount' ) );

		if ( 'dec' === $request->get_param( 'dir' ) ) {
			$amount = -abs( $amount );
		} else {
			$amount = abs( $amount );
		}

		$result = CMB_Wallet::adjust(
			(string) $request->get_param( 'phone' ),
			$amount,
			(string) $request->get_param( 'note' ),
			get_current_user_id(),
			(bool) $request->get_param( 'sms' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) + self::wallet_payload( CMB_Wallet::key( (string) $request->get_param( 'phone' ) ) ) );
	}

	/* ------------------------------------------------------------------
	 * برگشت‌های مانده
	 * --------------------------------------------------------------- */

	/**
	 * آیا کاربر فعلی می‌تواند کیف پول را دستی تغییر دهد و برگشت‌های مانده
	 * را تعیین تکلیف کند؟
	 *
	 * دیدن با مدیر مرکز است؛ تغییر با پول سروکار دارد و پیش‌فرض فقط مدیر
	 * کل — مدیر مرکز فقط اگر در تنظیمات اجازه داده شده باشد.
	 */
	public static function can_refund() {
		return CMB_Branches::can( 'money' );
	}

	/**
	 * برگشت‌های مانده: صف کارتِ پیش از کیف پول، و برگشتی که واریزش به
	 * کیف پول ممکن نشد.
	 */
	public function refunds( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'items'     => CMB_Payments::refund_queue( 'open' ),
				'which'     => 'open',
				'canRefund' => self::can_refund(),
				'open'      => CMB_Payments::count_open_refunds(),
			)
		);
	}

	/**
	 * بیعانه و برگشتیِ هر خدمت، با هشدار و سناریوها.
	 */
	public function pay_review( WP_REST_Request $request ) {
		return rest_ensure_response( CMB_Pay_Review::services() );
	}

	public function pay_report( WP_REST_Request $request ) {
		$days = (int) $request->get_param( 'days' );
		$days = in_array( $days, array( 0, 7, 30, 90, 365 ), true ) ? $days : 30;

		return rest_ensure_response( CMB_Pay_Review::report( $days, (bool) $request->get_param( 'sandbox' ) ) );
	}

	public function update_refund( WP_REST_Request $request ) {
		if ( ! self::can_refund() ) {
			return new WP_Error( 'cmb_refund_forbidden', 'تعیین تکلیف برگشت‌های مانده فقط برای مدیر سایت مجاز است.', array( 'status' => 403 ) );
		}

		$id     = (int) $request->get_param( 'id' );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( 'done' === $action ) {
			// پول قبلاً جور دیگری برگشته (مثلاً پیش از کیف پول، در زرین‌پال)
			$result = CMB_Payments::mark_refunded( $id, (string) $request->get_param( 'ref' ), get_current_user_id() );
		} elseif ( 'wallet' === $action && CMB_Wallet::ready() ) {
			// برگشت مانده ← کیف پول مشتری
			$result = CMB_Wallet::move_legacy( $id, get_current_user_id() );
		} else {
			return new WP_Error( 'cmb_bad_action', 'درخواست معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payment = CMB_Payments::get_payment( $id );

		return rest_ensure_response(
			array(
				'success' => true,
				'error'   => $payment ? (string) $payment->refund_error : '',
				'items'   => CMB_Payments::refund_queue( 'open' ),
				'open'    => CMB_Payments::count_open_refunds(),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * بستن روز / شیفت
	 * --------------------------------------------------------------- */

	public function closures( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();
		$items  = array();

		foreach ( (array) CMB_Availability::get_closures( $branch ) as $row ) {
			$items[] = array(
				'id'     => (int) $row->id,
				'date'   => $row->closure_date,
				'dateFa' => cmb_jalali_date( $row->closure_date, 'full' ),
				'block'  => $row->block_key,
				'label'  => '' === $row->block_key ? 'کل روز' : cmb_block_label( $row->block_key ),
				'reason' => $row->reason,
			);
		}

		return rest_ensure_response( array( 'items' => $items, 'blocks' => self::block_options( $branch ) ) );
	}

	public function save_closure( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! CMB_Branches::can( 'closures' ) ) {
			return self::role_denied( 'بستن یا باز کردن روز فقط با مدیر شعبه است.' );
		}

		$branch = CMB_Branches::panel_branch_id();
		$remove = (int) $request->get_param( 'remove' );

		if ( $remove ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . cmb_table( 'closures' ) . ' WHERE id = %d', $remove ) ); // phpcs:ignore

			if ( ! $row ) {
				return rest_ensure_response( array( 'success' => true ) );
			}

			$own = CMB_Branches::assert_branch( (int) $row->branch_id );

			if ( is_wp_error( $own ) ) {
				return $own;
			}

			CMB_Availability::remove_closure( $remove );

			return rest_ensure_response( array( 'success' => true ) );
		}

		$date  = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$block = sanitize_key( (string) $request->get_param( 'block' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'cmb_bad_date', 'تاریخ معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( '' !== $block && ! array_key_exists( $block, cmb_blocks( $branch ) ) ) {
			return new WP_Error( 'cmb_bad_block', 'شیفت معتبر نیست.', array( 'status' => 400 ) );
		}

		/* نمایندگی: بستن روزی که نوبت فعال دارد، اول هشدار می‌دهد (US-2.3).
		   نوبت‌ها خودکار لغو نمی‌شوند؛ شعبه باید با مشتری‌ها هماهنگ کند. */
		if ( CMB_Branches::is_agency( $branch ) && ! $request->get_param( 'confirm' ) ) {
			$busy = CMB_Branches::future_count( $branch, $date, '' !== $block ? $block : null );

			if ( $busy > 0 ) {
				return new WP_Error(
					'cmb_closure_has_bookings',
					sprintf( 'در این بازه %s نوبت فعال ثبت شده است. بستن آن نوبت‌ها را لغو نمی‌کند؛ اگر لازم است، خودتان با مشتری‌ها هماهنگ و لغوشان کنید.', cmb_fa_num( $busy ) ),
					array( 'status' => 409, 'count' => $busy )
				);
			}
		}

		$done = CMB_Availability::set_closure(
			$branch,
			$date,
			$block,
			sanitize_text_field( (string) $request->get_param( 'reason' ) )
		);

		if ( ! $done ) {
			return new WP_Error( 'cmb_closure_exists', 'این بازه قبلاً بسته شده است.', array( 'status' => 409 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	protected static function block_options( $branch_id = 0 ) {
		$out = array();

		foreach ( cmb_blocks( $branch_id ) as $key => $block ) {
			$out[] = array(
				'key'   => $key,
				'label' => $block['label'],
				'start' => $block['start'],
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * تنظیمات
	 * --------------------------------------------------------------- */




	/* ------------------------------------------------------------------
	 * تست پیامک
	 * --------------------------------------------------------------- */


	/* ------------------------------------------------------------------
	 * شعبه‌ی جاری: پروفایل و کیف پول (نمایندگی)
	 * --------------------------------------------------------------- */

	/** پروفایل شعبه‌ی جاری پنل، کاربرانش و اختیار کاربر. */
	public function branch_get( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();
		$money  = CMB_Branches::can( 'wallet' );
		$out    = CMB_Branches::panel_array( $branch, $money );

		if ( ! $out ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		$out['users']   = CMB_Branches::can( 'profile' ) ? CMB_Branches::users( $branch ) : array();
		$out['canEdit'] = CMB_Branches::can( 'profile' ) && ( CMB_Branches::is_agency( $branch ) || current_user_can( 'manage_options' ) );

		return rest_ensure_response( $out );
	}

	/**
	 * ویرایش پروفایل توسط مدیر نمایندگی (US-2.5): آدرس، تلفن، عکس، لوکیشن.
	 * نام، استان و شهر فقط با مدیر سایت است.
	 */
	public function branch_save( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();

		if ( ! CMB_Branches::can( 'profile' ) || ( ! CMB_Branches::is_agency( $branch ) && ! current_user_can( 'manage_options' ) ) ) {
			return self::role_denied( 'ویرایش پروفایل شعبه فقط با مدیر شعبه است.' );
		}

		$params = (array) $request->get_json_params();
		$in     = array();

		foreach ( array( 'address' => 'address', 'phone' => 'phone', 'photoId' => 'photo_id', 'lat' => 'lat', 'lng' => 'lng' ) as $from => $to ) {
			if ( array_key_exists( $from, $params ) ) {
				$in[ $to ] = $params[ $from ];
			}
		}

		$done = CMB_Branches::update( $branch, $in, 'manager' );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'پروفایل شعبه ذخیره شد.',
				'branch'  => CMB_Branches::panel_array( $branch, CMB_Branches::can( 'wallet' ) ),
			)
		);
	}

	/**
	 * مدیر سایت: پنل کدام شعبه را نشان بدهد. انتخاب روی حساب خودش می‌ماند
	 * تا با تازه‌سازی صفحه عوض نشود.
	 */
	public function branch_switch( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'branch' );

		if ( $id && ( ! CMB_Branches::get( $id ) || 'deleted' === CMB_Branches::status( $id ) ) ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		if ( ! $id || $id === CMB_Branches::center_id() ) {
			delete_user_meta( get_current_user_id(), 'cmb_panel_branch' );
			$id = CMB_Branches::center_id();
		} else {
			update_user_meta( get_current_user_id(), 'cmb_panel_branch', $id );
		}

		CMB_Branches::set_panel_branch( $id );

		$out = self::summary_payload();

		CMB_Branches::set_panel_branch( null );

		return rest_ensure_response( array( 'success' => true ) + $out );
	}

	/**
	 * کیف پول نمایندگی (US-4.1): سه عدد، ظرفیت، تاریخچه و رسیدها. با
	 * pay و tok نتیجه‌ی شارژی را هم برمی‌گرداند که تازه از درگاه برگشته.
	 */
	public function branch_wallet( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();

		if ( ! CMB_Branches::is_agency( $branch ) ) {
			return new WP_Error( 'cmb_not_agency', 'این شعبه کیف پول ندارد.', array( 'status' => 404 ) );
		}

		if ( ! CMB_Branches::can( 'wallet' ) ) {
			return self::role_denied( 'کیف پول نمایندگی را فقط مدیر نمایندگی می‌بیند.' );
		}

		$out = array(
			'summary'    => CMB_Branch_Wallet::summary( $branch ),
			'history'    => CMB_Branch_Wallet::history( $branch, max( 1, (int) $request->get_param( 'page' ) ) ),
			'receipts'   => CMB_Branch_Wallet::topups( $branch, 20 ),
			'presets'    => CMB_Branch_Wallet::topup_presets(),
			'topupMin'   => CMB_Branch_Wallet::topup_min(),
			'topupMinFa' => cmb_toman( CMB_Branch_Wallet::topup_min() ),
			'topupMax'   => CMB_Branch_Wallet::topup_max(),
			'canTopup'   => CMB_Branches::can( 'topup' ) && CMB_Branch_Wallet::topup_ready(),
			'topupReady' => CMB_Branch_Wallet::topup_ready(),
			'fee'        => CMB_Branches::fee(),
			'feeFa'      => cmb_toman( CMB_Branches::fee() ),
		);

		$pay = $request->get_param( 'pay' );

		if ( null !== $pay && '' !== (string) $pay ) {
			$out['topup'] = CMB_Branch_Wallet::topup_state(
				(int) $pay,
				preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'tok' ) ),
				$branch
			);

			// موجودی بعد از نتیجه‌ی تازه
			$out['summary'] = CMB_Branch_Wallet::summary( $branch );
			$out['history'] = CMB_Branch_Wallet::history( $branch, 1 );
		}

		return rest_ensure_response( $out );
	}

	public function branch_topup( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();

		if ( ! CMB_Branches::can( 'topup' ) ) {
			return self::role_denied( 'شارژ کیف پول فقط با مدیر نمایندگی است.' );
		}

		$res = CMB_Branch_Wallet::topup_start( $branch, $request->get_param( 'amount' ), get_current_user_id() );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return rest_ensure_response( array( 'success' => true, 'url' => $res['url'] ) );
	}

	public function branch_receipt( WP_REST_Request $request ) {
		$branch = CMB_Branches::panel_branch_id();

		if ( ! CMB_Branches::is_agency( $branch ) || ! CMB_Branches::can( 'wallet' ) ) {
			return self::role_denied();
		}

		$rc = CMB_Branch_Wallet::receipt( (int) $request->get_param( 'id' ), $branch );

		return is_wp_error( $rc ) ? $rc : rest_ensure_response( $rc );
	}

	/* ------------------------------------------------------------------
	 * مدیر سایت: نمایندگی‌ها (بخش ۶.۵)
	 * --------------------------------------------------------------- */

	/** یک ردیف فهرست نمایندگی‌های مدیر. */
	protected static function admin_branch_row( $b ) {
		$out = CMB_Branches::panel_array( $b, true );

		if ( ! $out ) {
			return null;
		}

		$reason = '';

		$out['bookable']    = CMB_Branches::bookable( $b, $reason );
		$out['reason']      = $reason;
		$out['reasonLabel'] = self::unbookable_label( $reason );
		$out['future']      = CMB_Branches::future_count( (int) $b->id );
		$out['users']       = CMB_Branches::users( (int) $b->id );
		$out['isCenter']    = CMB_Branches::is_center( $b );
		$out['services']    = count( CMB_Services::get_services( (int) $b->id, true ) );

		return $out;
	}

	/** چرا نوبت نمی‌گیرد — فقط برای مدیر؛ مشتری فقط «فعلاً نوبت نمی‌گیرد» می‌بیند (D18). */
	protected static function unbookable_label( $reason ) {
		$map = array(
			'inactive'    => 'فعال نیست',
			'no_services' => 'هیچ خدمت فعالی ندارد',
			'balance'     => 'موجودی آزاد کیف پول کمتر از هزینه‌ی یک رزرو است',
		);

		return isset( $map[ $reason ] ) ? $map[ $reason ] : '';
	}

	public function admin_branches( WP_REST_Request $request ) {
		if ( ! CMB_Branches::ready() ) {
			return new WP_Error( 'cmb_branch_schema', 'دیتابیس هنوز به‌روز نشده است؛ یک بار پیشخوان وردپرس را باز کنید.', array( 'status' => 503 ) );
		}

		$items = array();

		foreach ( CMB_Branches::all() as $b ) {
			$row = self::admin_branch_row( $b );

			if ( $row ) {
				$items[] = $row;
			}
		}

		return rest_ensure_response(
			array(
				'items'    => $items,
				'settings' => self::fee_settings(),
				'maxOps'   => CMB_Branches::MAX_OPERATORS,
			)
		);
	}

	/** ساخت (بدون id) یا ویرایش یک نمایندگی. */
	public function admin_branch_save( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$policy = array();
		foreach ( array( 'allowOwnServices' => 'own', 'allowCustomizeServices' => 'customize' ) as $param => $key ) {
			if ( ! array_key_exists( $param, $params ) ) { continue; }
			if ( ! in_array( (string) $params[ $param ], array( '-1', '0', '1' ), true ) ) {
				return new WP_Error( 'cmb_bad_policy', 'مجوز خدمات معتبر نیست.', array( 'status' => 400 ) );
			}
			$policy[ $key ] = (int) $params[ $param ];
		}
		// شناسه در بدنه، یا در نشانی (PUT /panel/admin/branches/{id})
		$id     = isset( $params['id'] ) ? (int) $params['id'] : (int) $request->get_param( 'id' );
		$map    = array(
			'title'         => 'title',
			'slug'          => 'slug',
			'province'      => 'province',
			'city'          => 'city',
			'address'       => 'address',
			'phone'         => 'phone',
			'lat'           => 'lat',
			'lng'           => 'lng',
			'photoId'       => 'photo_id',
			'contactName'   => 'contact_name',
			'managerPhone'  => 'manager_phone',
			'managerPhones' => 'manager_phones',
			'sortOrder'     => 'sort_order',
		);
		$in     = array();

		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $params ) ) {
				$in[ $to ] = $params[ $from ];
			}
		}

		if ( $id ) {
			unset( $in['manager_phone'] );

			$done = CMB_Branches::update( $id, $in, 'admin' );

			if ( is_wp_error( $done ) ) {
				return $done;
			}
		} else {
			$in['type'] = 'agency';
			$id         = CMB_Branches::create( $in, get_current_user_id() );

			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}

		if ( $policy && CMB_Branches::is_agency( $id ) ) {
			$old = (array) get_option( 'cmb_agency_policy_' . $id, array() );
			update_option( 'cmb_agency_policy_' . $id, array_merge( $old, $policy ), false );
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'اطلاعات نمایندگی ذخیره شد.',
				'item'    => self::admin_branch_row( CMB_Branches::get( $id ) ),
			)
		);
	}

	/**
	 * فعال/غیرفعال کردن. غیرفعال کردنِ نمایندگی‌ای که نوبت آینده دارد، اول
	 * هشدار می‌دهد (نوبت‌ها لغو نمی‌شوند؛ US-1.3).
	 */
	public function admin_branch_status( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( 'inactive' === $status && ! $request->get_param( 'confirm' ) ) {
			$future = CMB_Branches::future_count( $id );

			if ( $future > 0 ) {
				return new WP_Error(
					'cmb_branch_has_future',
					sprintf( 'این نمایندگی %s نوبت آینده دارد. با غیرفعال کردن، نوبت تازه نمی‌گیرد ولی آن نوبت‌ها سر جایشان می‌مانند و باید انجام شوند.', cmb_fa_num( $future ) ),
					array( 'status' => 409, 'count' => $future )
				);
			}
		}

		$res = CMB_Branches::set_status( $id, $status, get_current_user_id() );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$message = 'active' === $status ? 'نمایندگی فعال شد.' : 'نمایندگی غیرفعال شد.';

		if ( ! empty( $res['welcome'] ) ) {
			$message .= sprintf( ' %s اعتبار اولیه به کیف پولش واریز شد.', cmb_toman( (int) $res['welcome'] ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => $message,
				'item'    => self::admin_branch_row( CMB_Branches::get( $id ) ),
			)
		);
	}

	public function admin_branch_delete( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::role_denied(); }
		$id = (int) $request->get_param( 'id' );
		$b = CMB_Branches::get( $id );
		if ( ! $b || (string) $request->get_param( 'confirmTitle' ) !== (string) $b->title ) {
			return new WP_Error( 'cmb_delete_confirm', 'برای تأیید حذف، نام کامل نمایندگی را وارد کنید.', array( 'status' => 400 ) );
		}
		$result = CMB_Branches::delete( $id, get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true, 'message' => 'نمایندگی حذف و دسترسی کاربران آن بسته شد؛ سوابق نوبت‌ها و مالی محفوظ است.' ) );
	}

	/** افزودن یا حذف مدیر/اپراتور یک شعبه. */
	public function admin_branch_users( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$op = sanitize_key( (string) $request->get_param( 'op' ) );

		if ( ! CMB_Branches::get( $id ) || 'deleted' === CMB_Branches::status( $id ) ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		if ( 'remove' === $op ) {
			$res = CMB_Branches::remove_user( $id, (int) $request->get_param( 'userId' ) );
		} elseif ( 'role' === $op ) {
			$res = CMB_Branches::set_user_role( $id, (int) $request->get_param( 'userId' ), sanitize_key( (string) $request->get_param( 'role' ) ) );
		} else {
			$res = CMB_Branches::add_user(
				$id,
				(string) $request->get_param( 'phone' ),
				(string) $request->get_param( 'name' ),
				sanitize_key( (string) $request->get_param( 'role' ) )
			);
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'remove' === $op ? 'دسترسی پنل این کاربر برداشته شد.'
					: ( 'role' === $op ? 'نقش کاربر عوض شد؛ از درخواست بعدی‌اش اعمال می‌شود.' : 'کاربر به پنل شعبه اضافه شد؛ با همین شماره و کد پیامکی وارد می‌شود.' ),
				'users'   => CMB_Branches::users( $id ),
			)
		);
	}

	/** افزایش یا کاهش دستی کیف پول نمایندگی، با دلیل (US-4.6). */
	public function admin_branch_adjust( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$amount = abs( (int) cmb_en_num( (string) $request->get_param( 'amount' ) ) );

		if ( 'dec' === $request->get_param( 'dir' ) ) {
			$amount = -$amount;
		}

		$res = CMB_Branch_Wallet::adjust( $id, $amount, (string) $request->get_param( 'note' ), get_current_user_id() );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'کیف پول نمایندگی به‌روز شد.',
				'item'    => self::admin_branch_row( CMB_Branches::get( $id ) ),
				'history' => CMB_Branch_Wallet::history( $id, 1 ),
			)
		);
	}

	/** برگشت کسر قطعی یک نوبت (US-4.5). */
	public function admin_fee_refund( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'id' );
		$res        = CMB_Branch_Wallet::refund_fee( $booking_id, get_current_user_id(), (string) $request->get_param( 'note' ) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$booking = CMB_Bookings::get( $booking_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'هزینه‌ی این نوبت به کیف پول نمایندگی برگشت.',
				'item'    => $booking ? self::row_to_array( $booking ) : null,
			)
		);
	}

	/**
	 * گزارش نمایندگی‌ها در یک بازه (US-5.1): نوبت‌ها، انجام‌شده، لغو
	 * نمایندگی و نرخش، کسر قطعی، شارژ، موجودی. CSV در مرورگر ساخته می‌شود.
	 */
	public function admin_report( WP_REST_Request $request ) {
		global $wpdb;

		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : cmb_today();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : gmdate( 'Y-m-d', strtotime( $to ) - 29 * DAY_IN_SECONDS );

		if ( strcmp( $from, $to ) > 0 ) {
			list( $from, $to ) = array( $to, $from );
		}

		$table  = cmb_table( 'bookings' );
		$items  = array();
		$totals = array( 'bookings' => 0, 'done' => 0, 'noShow' => 0, 'cancelledBranch' => 0, 'cancelledCustomer' => 0, 'captured' => 0, 'topup' => 0, 'free' => 0 );

		foreach ( CMB_Branches::all( true ) as $b ) {
			if ( ! CMB_Branches::is_agency( $b ) ) {
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM( status NOT IN ('pending','expired') ) AS total_c,
						SUM( status = 'done' )    AS done_c,
						SUM( status = 'no_show' ) AS noshow_c,
						SUM( status = 'cancelled' AND cancelled_by = 'branch' )   AS cb_c,
						SUM( status = 'cancelled' AND cancelled_by = 'customer' ) AS cc_c,
						SUM( status = 'cancelled' AND cancelled_by = 'admin' )    AS ca_c
					 FROM {$table} WHERE branch_id = %d AND booking_date BETWEEN %s AND %s", // phpcs:ignore
					(int) $b->id,
					$from,
					$to
				)
			);

			$sums  = CMB_Branch_Wallet::sums( (int) $b->id, $from, $to );
			$money = CMB_Branch_Wallet::totals( (int) $b->id );
			$total = $row ? (int) $row->total_c : 0;
			$cb    = $row ? (int) $row->cb_c : 0;

			$item = array(
				'id'                => (int) $b->id,
				'title'             => (string) $b->title,
				'city'              => isset( $b->city ) ? (string) $b->city : '',
				'status'            => CMB_Branches::status( $b ),
				'statusLabel'       => CMB_Branches::status_label( CMB_Branches::status( $b ) ),
				'bookings'          => $total,
				'done'              => $row ? (int) $row->done_c : 0,
				'noShow'            => $row ? (int) $row->noshow_c : 0,
				'cancelledBranch'   => $cb,
				'cancelledCustomer' => $row ? (int) $row->cc_c : 0,
				'cancelledAdmin'    => $row ? (int) $row->ca_c : 0,
				'cancelRate'        => $total ? round( 100 * $cb / $total, 1 ) : 0,
				'customerCancelRate' => ( $total && $row ) ? round( 100 * (int) $row->cc_c / $total, 1 ) : 0,
				'captured'          => (int) $sums['captured'],
				'topup'             => (int) $sums['topup'],
				'total'             => (int) $money['total'],
				'held'              => (int) $money['held'],
				'free'              => (int) $money['free'],
			);

			foreach ( array( 'captured', 'topup', 'total', 'held', 'free' ) as $k ) {
				$item[ $k . 'Fa' ] = cmb_toman( $item[ $k ] );
			}

			$items[] = $item;

			$totals['bookings']          += $item['bookings'];
			$totals['done']              += $item['done'];
			$totals['noShow']            += $item['noShow'];
			$totals['cancelledBranch']   += $item['cancelledBranch'];
			$totals['cancelledCustomer'] += $item['cancelledCustomer'];
			$totals['captured']          += $item['captured'];
			$totals['topup']             += $item['topup'];
			$totals['free']              += $item['free'];
		}

		foreach ( array( 'captured', 'topup', 'free' ) as $k ) {
			$totals[ $k . 'Fa' ] = cmb_toman( $totals[ $k ] );
		}

		// خروجی CSV همین جدول (US-6.1 و ?format=csv در بخش ۶.۵)
		if ( 'csv' === $request->get_param( 'format' ) ) {
			return self::report_csv( $items, $from, $to );
		}

		return rest_ensure_response(
			array(
				'from'   => $from,
				'to'     => $to,
				'fromFa' => cmb_jalali_date( $from, 'numeric' ),
				'toFa'   => cmb_jalali_date( $to, 'numeric' ),
				'items'  => $items,
				'totals' => $totals,
			)
		);
	}

	/**
	 * گزارش نمایندگی‌ها به‌صورت فایل CSV (UTF-8 با BOM تا اکسل فارسی را
	 * درست نشان دهد). خانه‌ای که با = + - @ شروع شود با ' شروع می‌شود تا
	 * اکسل آن را فرمول حساب نکند.
	 */
	protected static function report_csv( array $items, $from, $to ) {
		$cell = function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
		};
		$rows = array( array( 'نمایندگی', 'شهر', 'وضعیت', 'نوبت‌ها', 'انجام‌شده', 'عدم مراجعه', 'لغو مشتری', 'لغو نمایندگی', 'لغو مدیریت', 'نرخ لغو نمایندگی (٪)', 'نرخ لغو مشتری (٪)', 'کسر قطعی (تومان)', 'شارژ (تومان)', 'موجودی کل', 'بلوکه', 'موجودی آزاد' ) );

		foreach ( $items as $x ) {
			$rows[] = array_map( $cell, array( $x['title'], $x['city'], $x['statusLabel'], $x['bookings'], $x['done'], $x['noShow'], $x['cancelledCustomer'], $x['cancelledBranch'], $x['cancelledAdmin'], $x['cancelRate'], $x['customerCancelRate'], $x['captured'], $x['topup'], $x['total'], $x['held'], $x['free'] ) );
		}

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r );
		}
		rewind( $fh );
		$csv = "\xEF\xBB\xBF" . stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore

		$name = 'agencies-' . $from . '-' . $to . '.csv';

		// پاسخ REST معمولاً JSON است؛ این یکی خود فایل را می‌فرستد
		add_filter(
			'rest_pre_serve_request',
			function ( $served, $result ) use ( $csv, $name ) {
				if ( $served || ! ( $result instanceof WP_REST_Response ) || $result->get_data() !== $csv ) {
					return $served;
				}

				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $name . '"' );
				echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				return true;
			},
			10,
			2
		);

		return new WP_REST_Response( $csv, 200 );
	}

	/** تنظیمات مالی نمایندگی‌ها (بخش ۶.۷). */
	protected static function fee_settings() {
		$out = array();

		foreach ( array( 'agency_fee', 'agency_welcome_credit', 'agency_low_balance', 'agency_topup_min', 'agency_own_services', 'agency_customize_services' ) as $key ) {
			$out[ $key ] = (int) CMB_Settings::get( $key );
		}

		return $out;
	}

	/**
	 * تغییر مبلغ‌ها. مبلغ هر رزرو روی خود نوبت ذخیره می‌شود، پس تغییرش فقط
	 * روی رزروهای تازه اثر دارد.
	 */
	public function admin_fee_settings( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$limits = array(
			'agency_own_services' => array( 0, 1 ),
			'agency_customize_services' => array( 0, 1 ),
			'agency_fee'            => array( 0, 50000000 ),
			'agency_welcome_credit' => array( 0, 100000000 ),
			'agency_low_balance'    => array( 0, 100000000 ),
			'agency_topup_min'      => array( 10000, 50000000 ),
		);
		$clean  = array();

		foreach ( $limits as $key => $range ) {
			if ( ! array_key_exists( $key, $params ) ) {
				continue;
			}

			$v = (int) cmb_en_num( (string) $params[ $key ] );

			if ( $v < $range[0] || $v > $range[1] ) {
				return new WP_Error( 'cmb_bad_amount', sprintf( 'مبلغ «%s» خارج از بازه‌ی مجاز است.', $key ), array( 'status' => 400 ) );
			}

			$clean[ $key ] = $v;
		}

		if ( $clean ) {
			CMB_Settings::update( $clean );
			CMB_Services::flush_cache();

			cmb_log( 'Agency money settings updated', array( 'user' => get_current_user_id() ) + $clean );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => 'تنظیمات مالی نمایندگی‌ها ذخیره شد؛ مبلغ تازه فقط روی رزروهای بعدی اثر دارد.',
				'settings' => self::fee_settings(),
			)
		);
	}

	/* --------------------------------------------------------------- */

	protected static function day_after( $date ) {
		return gmdate( 'Y-m-d', strtotime( $date ) + DAY_IN_SECONDS );
	}
}
