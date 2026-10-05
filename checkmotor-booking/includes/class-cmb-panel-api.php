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

		return (bool) apply_filters( 'cmb_can_manage', $allowed, get_current_user_id() );
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

		return (bool) apply_filters( 'cmb_can_manage', $allowed, (int) $user->ID );
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

		return true;
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

		$routes = array(
			'panel/summary'   => array( 'GET', 'summary' ),
			'panel/board'     => array( 'GET', 'board' ),
			'panel/bookings'  => array( 'GET', 'bookings' ),
			'panel/booking'   => array( 'POST', 'update_booking' ),
			'panel/services'  => array( 'GET', 'services' ),
			'panel/service'   => array( 'POST', 'save_service' ),
			'panel/service/delete' => array( 'POST', 'delete_service' ),
			'panel/customers' => array( 'GET', 'customers' ),
			'panel/settings'  => array( 'GET', 'get_schedule' ),
			'panel/settings/save' => array( 'POST', 'save_schedule' ),
			'panel/health'    => array( 'GET', 'health' ),
			'panel/health/sweep' => array( 'POST', 'health_sweep' ),
			'panel/closures'  => array( 'GET', 'closures' ),
			'panel/closure'   => array( 'POST', 'save_closure' ),
			'panel/refunds'   => array( 'GET', 'refunds' ),
			'panel/refund'    => array( 'POST', 'update_refund' ),
			'panel/pay-review' => array( 'GET', 'pay_review' ),
			'panel/pay-report' => array( 'GET', 'pay_report' ),
			'panel/wallets'   => array( 'GET', 'wallets' ),
			'panel/wallet'    => array( 'GET', 'wallet' ),
			'panel/wallet/adjust' => array( 'POST', 'wallet_adjust' ),
		);

		foreach ( $routes as $path => $conf ) {
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					'methods'             => 'GET' === $conf[0] ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $conf[1] ),
					'permission_callback' => $guard,
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
		$branch = CMB_Services::default_branch_id();

		/* شش شمارش با یک کوئری، نه شش کوئری جدا. */
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
				 FROM {$table}", // phpcs:ignore
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
			'refunds'   => CMB_Payments::count_open_refunds(),
			'today'     => $row ? (int) $row->today_c : 0,
			'tomorrow'  => $row ? (int) $row->tomorrow_c : 0,
			'upcoming'  => $row ? (int) $row->upcoming_c : 0,
			'week'      => $row ? (int) $row->week_c : 0,
			'no_show'   => $row ? (int) $row->no_show_c : 0,
			'cancelled' => $row ? (int) $row->cancelled_c : 0,
		);

		// آمار خدمات در ۳۰ روز گذشته.
		$since    = gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) AS total FROM {$table} WHERE booking_date >= %s AND status IN ('confirmed','done') GROUP BY service_id ORDER BY total DESC", $since ) ); // phpcs:ignore
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
			) : null,
			'setup'     => self::setup_state(),
			'today'     => array(
				'date'   => $today,
				'jalali' => cmb_jalali_date( $today, 'full' ),
			),
		);
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
		$branch = CMB_Services::default_branch_id();
		$days   = max( 1, min( 21, (int) $request->get_param( 'days' ) ?: 7 ) );
		$from   = sanitize_text_field( (string) $request->get_param( 'from' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = cmb_today();
		}

		$blocks = cmb_blocks();
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

		$where  = array( '1=1' );
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
			'blockStart'  => cmb_block_start( $row->block_key ),
			'service'     => $service ? $service->title : '—',
			'serviceId'   => (int) $row->service_id,
			'name'        => $row->customer_name,
			'phone'       => $row->phone,
			'city'        => isset( $row->city ) ? (string) $row->city : '',
			'carBrand'    => $row->car_brand,
			'carModel'    => $row->car_model,
			'carYear'     => $row->car_year,
			'carMileage'  => $row->car_mileage,
			'note'        => $row->note,
			'status'      => $row->status,
			'statusLabel' => self::status_label_for( $row ),
			'pay'         => CMB_Payments::summary( $row ),
			'actions'     => CMB_Bookings::actions_for( $row ),
			'holdLeft'    => ( 'pending' === $row->status && ! empty( $row->hold_until_gmt ) )
				? max( 0, strtotime( $row->hold_until_gmt . ' UTC' ) - cmb_now()->getTimestamp() )
				: 0,
			'createdAt'   => $row->created_at,
			'createdAtFa' => cmb_jalali_date( substr( (string) $row->created_at, 0, 10 ), 'full' )
				. ' — ' . cmb_fa_num( substr( (string) $row->created_at, 11, 5 ) ),
		);
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

		if ( 'delete' === $status ) {
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
				'refund' => $request->get_param( 'refund' ),
				'force'  => (bool) $request->get_param( 'force' ),
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

		foreach ( CMB_Services::get_services( 0, false ) as $service ) {
			$poster = $service->poster_id ? wp_get_attachment_image_url( (int) $service->poster_id, 'medium' ) : '';

			$items[] = array(
				'id'          => (int) $service->id,
				'slug'        => $service->slug,
				'title'       => $service->title,
				'description' => $service->description,
				'price'       => (int) $service->price,
				'duration'    => $service->duration_note,
				'weekdays'    => CMB_Services::allowed_weekdays( $service ),
				'active'      => (int) $service->is_active,
				'posterId'    => (int) $service->poster_id,
				'poster'      => $poster ? $poster : '',
				'sortOrder'   => (int) $service->sort_order,
				'ownCapacity' => CMB_Services::own_capacity( $service ),
				// null = پیش‌فرض تنظیمات، ۰ = بدون بیعانه
				'deposit'      => ( isset( $service->deposit_amount ) && null !== $service->deposit_amount ) ? (int) $service->deposit_amount : null,
				'cancelRefund' => ( isset( $service->cancel_refund_amount ) && null !== $service->cancel_refund_amount ) ? (int) $service->cancel_refund_amount : null,
				'depositNow'   => CMB_Payments::service_deposit( $service ),
				'refundNow'    => CMB_Payments::service_refund( $service ),
			);
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * آپلود پوستر خدمت به کتابخانه‌ی رسانه.
	 */
	public function upload_poster( WP_REST_Request $request ) {
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

		$service_id = (int) $request->get_param( 'service_id' );

		if ( $service_id ) {
			CMB_Services::update_service( $service_id, array( 'poster_id' => (int) $id ) );
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
		/* شناسه‌ی صفر یعنی «خدمت تازه». پیش از این با خطا رد می‌شد،
		   چون هیچ مسیری برای ساخت خدمت وجود نداشت. */
		$id = (int) $request->get_param( 'id' );

		$weekdays = (array) $request->get_param( 'weekdays' );
		$weekdays = array_values( array_unique( array_map( 'intval', $weekdays ) ) );

		$data = array(
			'title'            => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'description'      => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'price'            => (int) $request->get_param( 'price' ),
			'duration_note'    => sanitize_text_field( (string) $request->get_param( 'duration' ) ),
			'allowed_weekdays' => implode( ',', $weekdays ),
			// ۱ برای همه، ۲ آزمایشی (فقط مدیران)، ۰ غیرفعال
			'is_active'        => max( 0, min( 2, (int) $request->get_param( 'active' ) ) ),
			'sort_order'       => (int) $request->get_param( 'sortOrder' ),
		);

		if ( null !== $request->get_param( 'posterId' ) ) {
			$data['poster_id'] = (int) $request->get_param( 'posterId' );
		}

		/* ظرفیت جداگانه: null یعنی «از ظرفیت اصلی شیفت کم کند». فقط وقتی
		   کلید در درخواست باشد دست زده می‌شود، تا ذخیره‌ی سریع (مثلاً
		   روشن/خاموش کردن) سهمیه را پاک نکند. */
		$params = $request->get_json_params();

		if ( is_array( $params ) && array_key_exists( 'ownCapacity', $params ) ) {
			$data['own_capacity'] = is_array( $params['ownCapacity'] ) ? $params['ownCapacity'] : null;
		}

		// بیعانه‌ی خدمت: null = پیش‌فرض، ۰ = ندارد، عدد = مبلغ دلخواه (تومان)
		if ( is_array( $params ) && array_key_exists( 'deposit', $params ) ) {
			$data['deposit_amount'] = $params['deposit'];
		}

		if ( is_array( $params ) && array_key_exists( 'cancelRefund', $params ) ) {
			$data['cancel_refund_amount'] = $params['cancelRefund'];
		}

		if ( array_key_exists( 'deposit_amount', $data ) || array_key_exists( 'cancel_refund_amount', $data ) ) {
			$valid = CMB_Services::validate_amounts(
				array_key_exists( 'deposit_amount', $data ) ? CMB_Services::clean_amount( $data['deposit_amount'] ) : null,
				array_key_exists( 'cancel_refund_amount', $data ) ? CMB_Services::clean_amount( $data['cancel_refund_amount'] ) : null
			);

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		if ( '' === $data['title'] ) {
			return new WP_Error( 'cmb_bad_title', 'عنوان خدمت نمی‌تواند خالی باشد.', array( 'status' => 400 ) );
		}

		if ( ! $id ) {
			$created = CMB_Services::create_service( $data );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'id'      => $created,
					'created' => true,
					'message' => 'خدمت تازه ثبت شد.',
				)
			);
		}

		CMB_Services::update_service( $id, $data );

		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	public function delete_service( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
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
		$out = array();

		foreach ( array_keys( CMB_Settings::schedule_rules() ) as $key ) {
			$out[ $key ] = CMB_Settings::get( $key );
		}

		return rest_ensure_response(
			array(
				'settings' => $out,
				// مدیر کل لینک تنظیمات کامل (پیامک و …) را هم می‌بیند
				'fullUrl'  => current_user_can( 'manage_options' ) ? esc_url_raw( admin_url( 'admin.php?page=cmb-settings' ) ) : '',
			)
		);
	}

	public function save_schedule( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_Error( 'cmb_bad_body', 'داده‌ای ارسال نشد.', array( 'status' => 400 ) );
		}

		$clean = CMB_Settings::sanitize_schedule( $params );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		CMB_Settings::update( $clean );

		cmb_log( 'Schedule settings updated from panel', array( 'user' => get_current_user_id(), 'keys' => array_keys( $clean ) ) );

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

		$table = cmb_table( 'bookings' );
		$q     = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$page  = max( 1, (int) $request->get_param( 'page' ) );
		$per   = 25;

		// تلاش‌های پرداخت‌نشده مشتری حساب نمی‌شوند
		$where  = array( "phone <> ''", "status NOT IN ('pending','expired')" );
		$params = array();

		if ( '' !== $q ) {
			$like     = '%' . $wpdb->esc_like( cmb_en_num( $q ) ) . '%';
			$cols = array( 'customer_name', 'phone', 'car_brand', 'car_model' );

			if ( cmb_has_column( 'bookings', 'city' ) ) {
				$cols[] = 'city';
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
					 WHERE b.phone IN ({$placeholders})",
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

		// موجودی کیف پولِ مشتری‌های همین صفحه، با یک کوئری
		$balances = ( $rows && CMB_Wallet::ready() ) ? CMB_Wallet::balances( wp_list_pluck( $rows, 'phone' ) ) : array();

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
				'wallet' => CMB_Wallet::ready(),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * کیف پول مشتری‌ها
	 * --------------------------------------------------------------- */

	/**
	 * فهرست کیف پول‌ها، جمع موجودی همه، و تعداد ردیف‌های مانده در صف
	 * قدیمی برگشت به کارت.
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
	 * بازگشت وجه
	 * --------------------------------------------------------------- */

	/**
	 * آیا کاربر فعلی می‌تواند برگشت وجه را ثبت کند؟
	 *
	 * دیدن صف برای همه‌ی مسئولان رزرو آزاد است؛ ثبتِ «انجام شد» با پول
	 * سروکار دارد و پیش‌فرض فقط مدیر کل.
	 */
	public static function can_refund() {
		return current_user_can( 'manage_options' ) || (bool) CMB_Settings::get( 'pay_refund_operators', 0 );
	}

	public function refunds( WP_REST_Request $request ) {
		$which = 'done' === $request->get_param( 'which' ) ? 'done' : 'open';

		return rest_ensure_response(
			array(
				'items'     => CMB_Payments::refund_queue( $which ),
				'which'     => $which,
				'canRefund' => self::can_refund(),
				'open'      => CMB_Payments::count_open_refunds(),
			)
		);
	}

	/**
	 * بیعانه و برگشت هر خدمت، با هشدار و سناریوها.
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
			return new WP_Error( 'cmb_refund_forbidden', 'ثبت برگشت وجه فقط برای مدیر سایت مجاز است.', array( 'status' => 403 ) );
		}

		$id     = (int) $request->get_param( 'id' );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		$outcome = '';

		if ( 'amount' === $action ) {
			$result = CMB_Payments::edit_refund( $id, (int) cmb_en_num( (string) $request->get_param( 'amount' ) ), get_current_user_id() );
		} elseif ( 'auto' === $action ) {
			$result = CMB_Payments::run_now( $id );

			if ( ! is_wp_error( $result ) ) {
				$outcome = $result;
				$result  = true;
			}
		} elseif ( 'done' === $action ) {
			$result = CMB_Payments::mark_refunded( $id, (string) $request->get_param( 'ref' ), get_current_user_id(), (bool) $request->get_param( 'sms' ) );
		} elseif ( 'wallet' === $action && CMB_Wallet::ready() ) {
			// ردیف مانده از صف قدیمی کارت ← کیف پول مشتری
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
				'outcome' => $outcome,
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
		$branch = CMB_Services::default_branch_id();
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

		return rest_ensure_response( array( 'items' => $items, 'blocks' => self::block_options() ) );
	}

	public function save_closure( WP_REST_Request $request ) {
		$branch = CMB_Services::default_branch_id();
		$remove = (int) $request->get_param( 'remove' );

		if ( $remove ) {
			CMB_Availability::remove_closure( $remove );

			return rest_ensure_response( array( 'success' => true ) );
		}

		$date  = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$block = sanitize_key( (string) $request->get_param( 'block' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'cmb_bad_date', 'تاریخ معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( '' !== $block && ! array_key_exists( $block, cmb_blocks() ) ) {
			return new WP_Error( 'cmb_bad_block', 'شیفت معتبر نیست.', array( 'status' => 400 ) );
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

	protected static function block_options() {
		$out = array();

		foreach ( cmb_blocks() as $key => $block ) {
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


	/* --------------------------------------------------------------- */

	protected static function day_after( $date ) {
		return gmdate( 'Y-m-d', strtotime( $date ) + DAY_IN_SECONDS );
	}
}
