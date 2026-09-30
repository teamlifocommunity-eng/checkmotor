<?php
/**
 * Plugin Name: چک موتور — سیستم رزرو نوبت
 * Plugin URI:  https://checkmotor.ir
 * Description: سیستم رزرو نوبت آنلاین چک موتور (MVP) — ورود با کد تایید پیامکی ملی‌پیامک، تقویم ۷ روزه، شیفت صبح/بعدازظهر، پنل مدیریت نوبت‌ها.
 * Version:     1.28.0
 * Author:      رضا امام‌حسنی
 * Text Domain: checkmotor-booking
 * Domain Path: /languages
 * Requires at least: 5.3
 * Requires PHP: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMB_VERSION', '1.28.0' );
define( 'CMB_FILE', __FILE__ );
define( 'CMB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMB_URL', plugin_dir_url( __FILE__ ) );
define( 'CMB_BASENAME', plugin_basename( __FILE__ ) );

define( 'CMB_MIN_PHP', '7.0' );
define( 'CMB_MIN_WP', '5.3' );

/**
 * بررسی حداقل نیازمندی‌ها.
 *
 * این تابع عمداً فقط از سینتکس سازگار با PHP 5.2 استفاده می‌کند تا حتی روی
 * میزبان‌های خیلی قدیمی هم به‌جای «خطای مهلک» یک پیام قابل خواندن نشان داده شود.
 *
 * @return string پیام خطا، یا رشته‌ی خالی اگر همه‌چیز درست بود.
 */
function cmb_requirements_error() {
	$errors = array();

	if ( version_compare( PHP_VERSION, CMB_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			'نسخه‌ی PHP این میزبان %1$s است، اما این افزونه حداقل به PHP %2$s نیاز دارد.',
			PHP_VERSION,
			CMB_MIN_PHP
		);
	}

	if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], CMB_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			'نسخه‌ی وردپرس این سایت %1$s است، اما این افزونه حداقل به وردپرس %2$s نیاز دارد.',
			$GLOBALS['wp_version'],
			CMB_MIN_WP
		);
	}

	if ( ! function_exists( 'mb_strlen' ) ) {
		$errors[] = 'اکستنشن mbstring روی این میزبان فعال نیست. لطفاً از پشتیبانی هاست بخواهید آن را فعال کند.';
	}

	if ( empty( $errors ) ) {
		return '';
	}

	return implode( ' ', $errors );
}

/**
 * اگر نیازمندی‌ها برقرار نبود، به‌جای Fatal Error پیام فارسی نمایش بده
 * و از ادامه‌ی بارگذاری افزونه جلوگیری کن.
 */
$cmb_requirements_error = cmb_requirements_error();

if ( '' !== $cmb_requirements_error ) {

	// هنگام فعال‌سازی: توقف تمیز با پیام قابل خواندن به‌جای صفحه‌ی سفید.
	register_activation_hook(
		__FILE__,
		function () {
			$message = cmb_requirements_error();

			if ( '' !== $message ) {
				wp_die(
					'<h1>افزونه فعال نشد</h1><p>' . esc_html( $message ) . '</p>',
					'خطای نیازمندی‌های افزونه',
					array( 'back_link' => true )
				);
			}
		}
	);

	add_action(
		'admin_notices',
		function () use ( $cmb_requirements_error ) {
			echo '<div class="notice notice-error"><p><b>چک موتور — سیستم رزرو نوبت:</b> '
				. esc_html( $cmb_requirements_error )
				. '</p></div>';
		}
	);

	return;
}

/**
 * بارگذاری فایل‌های افزونه.
 *
 * هر فایل قبل از require بررسی می‌شود؛ اگر بسته‌ی نصب‌شده ناقص باشد (مثلاً آپلود
 * FTP نیمه‌کاره مانده باشد) به‌جای Fatal Error یک پیام روشن نمایش داده می‌شود.
 */
function cmb_load_files() {
	$files = array(
		'includes/functions-jalali.php',
		'includes/functions-helpers.php',
		'includes/class-cmb-install.php',
		'includes/class-cmb-settings.php',
		'includes/class-cmb-sms.php',
		'includes/class-cmb-otp.php',
		'includes/class-cmb-services.php',
		'includes/class-cmb-availability.php',
		'includes/class-cmb-bookings.php',
		'includes/class-cmb-rest.php',
		'includes/class-cmb-health.php',
		'includes/class-cmb-panel-api.php',
		'includes/class-cmb-shortcodes.php',
		'includes/class-cmb-cron.php',
		'includes/class-cmb-isolate.php',
	);

	if ( is_admin() ) {
		// توجه: class-cmb-list-table.php عمداً اینجا بارگذاری نمی‌شود.
		// آن فایل از WP_List_Table ارث‌بری می‌کند و WP_List_Table در زمان
		// بارگذاری افزونه هنوز تعریف نشده است؛ بارگذاری زودهنگام آن باعث
		// «Fatal error: Class WP_List_Table not found» و شکست فعال‌سازی می‌شد.
		// این فایل به‌صورت تنبل (lazy) در CMB_Admin::page_bookings() لود می‌شود.
		$files[] = 'includes/class-cmb-admin.php';
		$files[] = 'includes/class-cmb-operators.php';
	}

	$missing = array();

	foreach ( $files as $file ) {
		$path = CMB_DIR . $file;

		if ( ! file_exists( $path ) ) {
			$missing[] = $file;
			continue;
		}

		require_once $path;
	}

	return $missing;
}

$cmb_missing_files = cmb_load_files();

if ( ! empty( $cmb_missing_files ) ) {
	add_action(
		'admin_notices',
		function () use ( $cmb_missing_files ) {
			echo '<div class="notice notice-error"><p><b>چک موتور — سیستم رزرو نوبت:</b> '
				. 'بسته‌ی افزونه ناقص است و این فایل‌ها پیدا نشدند: <code>'
				. esc_html( implode( '</code>, <code>', $cmb_missing_files ) )
				. '</code>. لطفاً افزونه را حذف و دوباره از فایل ZIP نصب کنید.</p></div>';
		}
	);

	return;
}

register_activation_hook( __FILE__, array( 'CMB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CMB_Install', 'deactivate' ) );

/**
 * بوت‌استرپ افزونه.
 */
final class CMB_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		// مشتری‌ها نباید به پیشخوان وردپرس دسترسی داشته باشند.
		add_action( 'admin_init', array( $this, 'block_customer_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );

		CMB_Rest::instance();
		CMB_Panel_Api::instance();
		CMB_Shortcodes::instance();
		CMB_Cron::instance();
		CMB_Isolate::instance();

		if ( is_admin() && class_exists( 'CMB_Admin' ) ) {
			CMB_Admin::instance();
			CMB_Operators::instance();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'checkmotor-booking', false, dirname( CMB_BASENAME ) . '/languages' );
	}

	public function init() {
		// اگر نسخه‌ی دیتابیس عوض شده بود، مهاجرت انجام شود.
		CMB_Install::maybe_upgrade();
	}

	/**
	 * جلوگیری از ورود مشتری به wp-admin (به‌جز درخواست‌های AJAX).
	 */
	public function block_customer_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		/* مسئول رزرو کاری در پیشخوان ندارد — مستقیم به پنل خودش. */
		if ( ! current_user_can( 'manage_options' ) && current_user_can( 'cmb_manage_bookings' ) ) {
			wp_safe_redirect( cmb_app_url( 'panel' ) );
			exit;
		}

		if ( in_array( 'cmb_customer', (array) $user->roles, true ) && ! current_user_can( 'edit_posts' ) ) {
			wp_safe_redirect( cmb_app_url() );
			exit;
		}
	}

	public function hide_admin_bar( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		$user = wp_get_current_user();

		if ( in_array( 'cmb_customer', (array) $user->roles, true ) ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) && current_user_can( 'cmb_manage_bookings' ) ) {
			return false;
		}

		return $show;
	}

	public function frontend_assets() {
		if ( ! CMB_Shortcodes::instance()->needs_assets() ) {
			return;
		}

		wp_enqueue_style( 'cmb-frontend', CMB_URL . 'assets/css/frontend.css', array(), CMB_VERSION );
		wp_enqueue_script( 'cmb-frontend', CMB_URL . 'assets/js/frontend.js', array(), CMB_VERSION, true );

		wp_localize_script(
			'cmb-frontend',
			'CMB_DATA',
			array(
				'root'         => esc_url_raw( rest_url( 'cmb/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn'   => is_user_logged_in(),
				'currentPhone' => is_user_logged_in() ? cmb_get_user_phone( get_current_user_id() ) : '',
				'currentName'  => is_user_logged_in() ? wp_get_current_user()->display_name : '',
				'otpLength'    => CMB_OTP::CODE_LENGTH,
				'resendAfter'  => (int) CMB_Settings::get( 'otp_resend_seconds', 120 ),
				'i18n'         => cmb_js_strings(),
			)
		);
	}
}

/* ═══════════════════════════════════════════════════════════
   حالت کنواس برای برگه‌ی رزرو

   اگر برگه‌ای شورت‌کد [checkmotor_booking] دارد، به‌جای اینکه فرم
   لای هدر و منو و فوتر قالب گم شود، همان برگه تمام‌صفحه با اپ
   تحویل داده می‌شود — بدون هیچ عنصر دیگری.

   برای خاموش کردن:  update_option( 'cmb_canvas', 0 );
   ═══════════════════════════════════════════════════════════ */

function cmb_canvas_on() {
	return (bool) get_option( 'cmb_canvas', 1 );
}

/**
 * آیا برگه‌ی جاری شورت‌کد رزرو را دارد؟
 */
/**
 * آیا این برگه باید تمام‌صفحه با اپ تحویل شود؟
 *
 * فقط post_content کافی نیست: صفحه‌سازها (المنتور، WPBakery، دیوی و…)
 * محتوا را در متای خودشان نگه می‌دارند و post_content عملاً خالی است.
 * نتیجه‌اش این بود که برگه‌ی ساخته‌شده با المنتور اصلاً تشخیص داده
 * نمی‌شد، حالت کنواس فعال نمی‌شد و کل قالب — هدر، منو و بخش‌های
 * دیگر سایت — بالای فرم رزرو ظاهر می‌شد.
 */
/**
 * شورت‌کدهایی که باید تمام‌صفحه تحویل شوند.
 *
 * @return string[]
 */
function cmb_shortcode_tags() {
	return array( 'checkmotor_booking', 'checkmotor_my_bookings', 'checkmotor_login' );
}

function cmb_page_has_booking_shortcode() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();

	if ( ! $post ) {
		return false;
	}

	// اگر اسلاگ برگه همان اسلاگ اپ باشد، همیشه اپ تحویل داده می‌شود.
	// این حالت وقتی پیش می‌آید که قواعد بازنویسی هنوز تازه نشده‌اند.
	if ( $post->post_name === cmb_app_slug() ) {
		return true;
	}

	/* هر سه شورت‌کد کنواس می‌شوند. «نوبت‌های من» و «ورود» هم اپ
	   خودشان را دارند (تب‌های mine و me)، پس نباید رابط قدیمی را
	   لای قالب نشان بدهند. */
	foreach ( cmb_shortcode_tags() as $tag ) {
		if ( '' !== (string) $post->post_content && has_shortcode( $post->post_content, $tag ) ) {
			return true;
		}
	}

	/* محتوای صفحه‌سازها. دنبال خودِ نام شورت‌کد می‌گردیم چون داده‌ی
	   المنتور JSON اسکیپ‌شده است و has_shortcode روی آن جواب نمی‌دهد. */
	$builder_keys = (array) apply_filters(
		'cmb_builder_meta_keys',
		array( '_elementor_data', '_vc_post_settings', 'et_pb_post_content', 'panels_data' )
	);

	foreach ( $builder_keys as $key ) {
		$raw = get_post_meta( $post->ID, $key, true );

		if ( ! $raw ) {
			continue;
		}

		if ( ! is_string( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}

		foreach ( cmb_shortcode_tags() as $tag ) {
			if ( false !== strpos( (string) $raw, $tag ) ) {
				return true;
			}
		}
	}

	return false;
}

/* پرچم کنواس باید خیلی زود ست شود — قبل از اینکه CMB_Isolate و
   صفحه‌سازها تصمیم بگیرند چه چیزی چاپ کنند. پس تشخیص در wp (که
   کوئری اصلی آماده است) انجام می‌شود، نه در template_redirect. */
add_action(
	'wp',
	function () {
		if ( get_query_var( 'cmb_app' ) || ! cmb_canvas_on() ) {
			return;
		}

		if ( ! cmb_page_has_booking_shortcode() ) {
			return;
		}

		set_query_var( 'cmb_app', 1 );

		/* اگر برگه شورت‌کد «نوبت‌های من» یا «ورود» داشت، اپ روی
		   همان تب باز شود نه صفحه‌ی اصلی. */
		$post = get_post();

		if ( $post ) {
			$haystack = (string) $post->post_content . ' ' . (string) get_post_meta( $post->ID, '_elementor_data', true );

			if ( false === strpos( $haystack, 'checkmotor_booking' ) ) {
				if ( false !== strpos( $haystack, 'checkmotor_my_bookings' ) ) {
					set_query_var( 'cmb_route', 'mine' );
				} elseif ( false !== strpos( $haystack, 'checkmotor_login' ) ) {
					set_query_var( 'cmb_route', 'me' );
				}
			}
		}
	},
	1
);

add_action(
	'template_redirect',
	function () {
		if ( ! get_query_var( 'cmb_app' ) ) {
			return;
		}

		$route = (string) get_query_var( 'cmb_route' );

		status_header( 200 );

		if ( 0 === strpos( $route, 'panel' ) ) {
			include CMB_DIR . 'templates/panel.php';
		} else {
			include CMB_DIR . 'templates/app.php';
		}

		exit;
	},
	5
);

CMB_Plugin::instance();

/* ═══════════════════════════════════════════════════════════
   مسیریابی — کل اپ روی یک مسیر، بدون نیاز به ساختن برگه
   ═══════════════════════════════════════════════════════════ */

/**
 * اسلاگ مسیر اپ. پیش‌فرض: /reserve/
 */
function cmb_app_slug() {
	$slug = trim( sanitize_title( (string) get_option( 'cmb_app_slug', 'reserve' ) ) );

	return $slug ? $slug : 'reserve';
}

/**
 * ساخت نشانی یک مسیر داخل اپ.
 */
function cmb_app_url( $route = '' ) {
	return home_url( '/' . cmb_app_slug() . '/' . ltrim( (string) $route, '/' ) );
}

/**
 * ثبت قواعد بازنویسی.
 */
function cmb_register_rewrites() {
	$slug = cmb_app_slug();

	add_rewrite_tag( '%cmb_app%', '1' );
	add_rewrite_tag( '%cmb_route%', '(.*)' );

	add_rewrite_rule( '^' . $slug . '/?$', 'index.php?cmb_app=1', 'top' );
	add_rewrite_rule( '^' . $slug . '/(.+?)/?$', 'index.php?cmb_app=1&cmb_route=$matches[1]', 'top' );
}
add_action( 'init', 'cmb_register_rewrites' );

/**
 * اگر اسلاگ یا نسخه عوض شد، پیوندها یک بار خودکار بازنویسی شوند.
 *
 * بدون این، بعد از هر به‌روزرسانی کاربر باید دستی به «تنظیمات ← پیوندهای
 * یکتا» می‌رفت و ذخیره می‌زد تا مسیر اپ کار کند.
 */
add_action(
	'init',
	function () {
		$stamp = cmb_app_slug() . '|' . CMB_VERSION;

		if ( get_option( 'cmb_rewrite_stamp' ) !== $stamp ) {
			cmb_register_rewrites();
			flush_rewrite_rules();
			update_option( 'cmb_rewrite_stamp', $stamp );
		}
	},
	99
);

/* ─── دارایی‌ها ─── */

/**
 * فونت اپ.
 *
 * افزونه هیچ فایل فونتی همراه خودش ندارد و نباید داشته باشد (حجم و
 * مجوز). به‌جای اینکه فرض کنیم فونت خاصی روی دستگاه کاربر نصب است،
 * پیش‌فرض را به Tahoma و فونت سیستم می‌سپاریم که همه‌جا فارسی را
 * درست می‌کشند.
 *
 * اگر سایت فونت اختصاصی دارد، کافی است مقدار گزینه را ست کنید:
 *
 *     update_option( 'cmb_font_stack', "'IRANYekanX', Tahoma, sans-serif" );
 *
 * و در صورت نیاز، فایل @font-face را با هوک زیر تزریق کنید:
 *
 *     add_action( 'wp_head', function () { echo '<style>@font-face{…}</style>'; } );
 */
/**
 * نشانی صفحه‌ی ورود/عضویت سایت.
 *
 * اگر سایت فرم ورود اختصاصی دارد، نشانی‌اش را در «تنظیمات ← ظاهر و
 * دسترسی» بگذارید تا اپ به‌جای wp-login.php کاربر را آن‌جا بفرستد.
 */
function cmb_login_url( $redirect = '' ) {
	$custom = trim( (string) get_option( 'cmb_login_url', '' ) );

	if ( '' === $redirect ) {
		$redirect = cmb_app_url();
	}

	if ( '' !== $custom ) {
		return add_query_arg( 'redirect_to', rawurlencode( $redirect ), $custom );
	}

	return wp_login_url( $redirect );
}

function cmb_font_css() {
	/* فونت پیش‌فرض (IRANYekanX) با @font-face داخل خود CSS تعریف شده
	   و همراه افزونه می‌آید؛ اینجا فقط در صورتی دخالت می‌کنیم که مدیر
	   بخواهد فونت دیگری بگذارد. */
	$css = '';

	$url = trim( (string) get_option( 'cmb_font_url', '' ) );

	if ( $url ) {
		$ext    = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$format = ( 'woff' === $ext ) ? 'woff' : 'woff2';

		$css .= "@font-face{font-family:'CMB Custom';font-style:normal;font-weight:100 900;"
			. "font-display:swap;src:url('" . esc_url( $url ) . "') format('" . $format . "');}";
	}

	$stack = trim( (string) get_option( 'cmb_font_stack', '' ) );

	if ( '' === $stack && $url ) {
		$stack = "'CMB Custom', 'IRANYekanX', Tahoma, sans-serif";
	}

	if ( '' !== $stack ) {
		$css .= ':root{--cmb-font:' . wp_strip_all_tags( $stack ) . '}';
	}

	return $css;
}

/**
 * نوبت‌های کاربر، با همان شکلی که /me برمی‌گرداند.
 *
 * @return array
 */
function cmb_user_bookings_payload( $user_id ) {
	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return array();
	}

	$out = array();

	foreach ( CMB_Bookings::get_user_bookings( $user_id, 10 ) as $booking ) {
		$out[] = CMB_Bookings::to_array( $booking );
	}

	return $out;
}

/**
 * داده‌ی اولین رندر.
 *
 * بدون این، ترتیب کار چنین بود: مرورگر صفحه را می‌گرفت، اسپینر
 * نشان می‌داد، بعد جاوااسکریپت یک درخواست REST برای گرفتن خدمات
 * می‌زد و وردپرس **دوباره** کامل بالا می‌آمد. یعنی کاربر تا پایان
 * رفت‌وبرگشت دوم فقط «در حال بارگذاری…» می‌دید.
 *
 * حالا همان داده داخل خود صفحه تزریق می‌شود و اپ بلافاصله رندر
 * می‌شود — بدون هیچ درخواستی.
 *
 * از همان کش پاسخ /services استفاده می‌کند، پس هزینه‌ی اضافه ندارد.
 */
function cmb_boot_payload() {
	return CMB_Services::payload( 0 );
}

/**
 * بارگذاری دارایی‌های اپ مشتری.
 */
function cmb_enqueue_app() {
	wp_enqueue_style( 'cmb-app', CMB_URL . 'assets/css/app.css', array(), CMB_VERSION );
	wp_enqueue_script( 'cmb-app', CMB_URL . 'assets/js/app.js', array(), CMB_VERSION, false );

	$font = cmb_font_css();

	if ( $font ) {
		wp_add_inline_style( 'cmb-app', $font );
	}

	$user = wp_get_current_user();
	$boot = cmb_boot_payload();

	wp_localize_script(
		'cmb-app',
		'CMB_APP',
		array(
			'boot'      => $boot,
			'root'      => esc_url_raw( rest_url( 'cmb/v1/' ) ),
			'base'      => '/' . cmb_app_slug() . '/',
			'route'     => (string) get_query_var( 'cmb_route' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'nonceUrl'  => esc_url_raw( admin_url( 'admin-ajax.php?action=cmb_nonce' ) ),
			'logged'    => is_user_logged_in(),
			/* «وارد شده» با «شماره تاییدشده» یکی نیست: کاربر ممکن است با
			   فرم ورود خود سایت وارد شده باشد و هیچ شماره‌ای نداشته باشد. */
			'hasPhone'  => is_user_logged_in() && '' !== cmb_get_user_phone( get_current_user_id() ),
			'loginUrl'  => cmb_login_url(),
			'faDigits'  => cmb_persian_digits_on(),
			/* فهرست سال‌های ساخت بیرون از کش ۱۲ ساعته‌ی payload ساخته
			   می‌شود تا شب سال تحویل، فهرست کهنه تحویل کسی نشود. */
			'carYears'  => cmb_car_years(),
			'cities'    => array_values( cmb_city_suggestions() ),
			'cols'      => (int) get_option( 'cmb_poster_cols', 1 ),
			'canManage' => CMB_Panel_Api::can(),
			'panel'     => cmb_app_url( 'panel' ),
			'me'        => array(
				'name'  => $user->ID ? $user->display_name : '',
				'phone' => $user->ID ? cmb_get_user_phone( $user->ID ) : '',
			),
			/* نوبت‌های کاربر هم همین‌جا می‌آید تا تب «نوبت‌های من»
			   بدون انتظار باز شود. برای مهمان‌ها آرایه‌ی خالی است و
			   هزینه‌ای ندارد. */
			'bookings'  => cmb_user_bookings_payload( $user->ID ),
			'branch'    => $boot['branch'],
			'notes'     => array(
				'ecu'         => $boot['notes']['ecu'],
				'outOfWindow' => $boot['notes']['outOfWindow'],
				'lateRule'    => CMB_Settings::get( 'late_rule_note', '' ),
			),
		)
	);
}

/**
 * بارگذاری دارایی‌های داشبورد مدیریت.
 */
function cmb_enqueue_panel() {
	wp_enqueue_style( 'cmb-panel', CMB_URL . 'assets/css/panel.css', array(), CMB_VERSION );
	wp_enqueue_script( 'cmb-jalali', CMB_URL . 'assets/js/jalali.js', array(), CMB_VERSION, false );
	wp_enqueue_script( 'cmb-panel', CMB_URL . 'assets/js/panel.js', array( 'cmb-jalali' ), CMB_VERSION, false );

	$font = cmb_font_css();

	if ( $font ) {
		wp_add_inline_style( 'cmb-panel', $font );
	}

	$user = wp_get_current_user();

	wp_localize_script(
		'cmb-panel',
		'CMB_PANEL',
		array(
			'root'     => esc_url_raw( rest_url( 'cmb/v1/' ) ),
			'base'     => '/' . cmb_app_slug() . '/panel',
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => esc_url_raw( admin_url( 'admin-ajax.php?action=cmb_nonce' ) ),
			'can'      => CMB_Panel_Api::can(),
			'faDigits' => cmb_persian_digits_on(),
			/* خلاصه از قبل داخل صفحه است تا پنل بدون انتظار باز شود. */
			'boot'     => CMB_Panel_Api::can() ? CMB_Panel_Api::summary_payload() : null,
			'me'       => $user->ID ? $user->display_name : '',
			'app'      => cmb_app_url(),
			'login'    => cmb_login_url( cmb_app_url( 'panel' ) ),
			'wpAdmin'  => esc_url_raw( admin_url( 'admin.php?page=cmb-bookings' ) ),
			'wpSettings' => esc_url_raw( admin_url( 'admin.php?page=cmb-settings' ) ),
			/* نام شیفت‌ها برای فرم سهمیه‌ی جداگانه. از خود cmb_blocks()
			   می‌آید تا اگر شیفتی با فیلتر اضافه شد، فرم هم آن را داشته باشد. */
			'blocks'   => array_values(
				array_map(
					function ( $key, $b ) {
						return array(
							'key'   => $key,
							'label' => $b['label'],
						);
					},
					array_keys( cmb_blocks() ),
					cmb_blocks()
				)
			),
		)
	);
}

/* ═══════════════════════════════════════════════════════════
   تازه‌سازی nonce
   ═══════════════════════════════════════════════════════════

   nonce تازه را نمی‌شود از خود REST گرفت: احراز هویت کوکی در REST
   خودش به nonce معتبر نیاز دارد. وقتی nonce کهنه می‌شود وردپرس
   درخواست را «مهمان» می‌بیند و nonceای می‌سازد که برای کاربرِ صفر
   ساخته شده و هیچ‌وقت اعتبار پیدا نمی‌کند — حلقه‌ای که فقط با رفرش
   کامل صفحه باز می‌شد.

   admin-ajax با کوکی معمولی وردپرس کار می‌کند و این مشکل را ندارد.
   ═══════════════════════════════════════════════════════════ */

function cmb_send_nonce() {
	nocache_headers();

	wp_send_json(
		array(
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'logged'    => is_user_logged_in(),
			'canManage' => CMB_Panel_Api::can(),
		)
	);
}
/**
 * اسکریپت‌های اپ در <head> بارگذاری می‌شوند تا دانلودشان زودتر شروع
 * شود، ولی با defer تا آماده شدن HTML اجرا نمی‌شوند — پس رندر را
 * بلاک نمی‌کنند و در عین حال زودتر از حالت فوتر آماده‌اند.
 */
add_filter(
	'script_loader_tag',
	function ( $tag, $handle ) {
		if ( ! in_array( $handle, array( 'cmb-app', 'cmb-panel', 'cmb-jalali' ), true ) ) {
			return $tag;
		}

		if ( false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	},
	10,
	2
);

add_action( 'wp_ajax_cmb_nonce', 'cmb_send_nonce' );
add_action( 'wp_ajax_nopriv_cmb_nonce', 'cmb_send_nonce' );

/* ─── لینک‌های میان‌بر در فهرست افزونه‌ها ─── */

add_filter(
	'plugin_action_links_' . CMB_BASENAME,
	function ( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( cmb_app_url() ) . '" target="_blank">دیدن اپ</a>',
			'<a href="' . esc_url( cmb_app_url( 'panel' ) ) . '" target="_blank">پنل مدیریت</a>'
		);

		return $links;
	}
);
