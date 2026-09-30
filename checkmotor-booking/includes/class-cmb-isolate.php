<?php
/**
 * جداسازی — روی مسیر اپ، قالب سایت و افزونه‌های دیگر نباید دخالت کنند.
 *
 * اپ رزرو یک دیزاین‌سیستم بسته دارد. اگر استایل قالب فعال بماند،
 * فونت، فاصله‌ها و رنگ‌ها به‌هم می‌ریزد و هر سایتی ظاهر متفاوتی می‌گیرد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Isolate {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'strip' ), 9999 );
		add_filter( 'show_admin_bar', array( $this, 'no_bar' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// روی هوک wp اجرا می‌شود، درست بعد از اینکه پرچم کنواس ست شد
		// و پیش از اینکه صفحه‌سازها خروجی‌شان را ثبت کنند.
		add_action( 'wp', array( $this, 'silence_builders' ), 2 );
	}

	/**
	 * خاموش کردن صفحه‌سازها روی مسیر اپ.
	 *
	 * حذف دارایی‌ها بر اساس هندل کافی نیست: المنتور فونت گوگل، استایل
	 * درون‌خطی هر برگه و قالب‌های هدر/فوتر خودش را از راه‌های دیگری
	 * چاپ می‌کند. اپ یک بوم بسته است و هیچ‌کدام از این‌ها را نمی‌خواهد.
	 */
	public function silence_builders() {
		if ( ! self::is_app() ) {
			return;
		}

		// المنتور
		add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
		add_filter( 'elementor_pro/frontend/print_google_fonts', '__return_false' );
		add_filter( 'elementor/theme/need_override_location', '__return_false' );
		add_filter( 'elementor/frontend/builder_content_data', '__return_empty_array' );

		// جت‌پک، ووکامرس و افزونه‌های مشابه که روی wp_head استایل می‌ریزند
		remove_action( 'wp_head', 'wp_resource_hints', 2 );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );

		/**
		 * برای خاموش کردن چیزهای دیگر روی مسیر اپ.
		 */
		do_action( 'cmb_isolate_app' );
	}

	/**
	 * آیا درخواست جاری روی مسیر اپ است؟
	 */
	public static function is_app() {
		return (bool) get_query_var( 'cmb_app' );
	}

	/**
	 * حذف دارایی‌های غیرخودی.
	 *
	 * فقط هندل‌های خود افزونه نگه داشته می‌شوند. jQuery هم می‌ماند چون
	 * بعضی افزونه‌های امنیتی بدون آن روی درخواست‌های REST گیر می‌دهند.
	 */
	public function strip() {
		if ( ! self::is_app() ) {
			return;
		}

		global $wp_styles, $wp_scripts;

		$keep_css = array( 'cmb-app', 'cmb-panel' );
		$keep_js  = array( 'cmb-app', 'cmb-panel', 'cmb-jalali', 'jquery', 'jquery-core', 'jquery-migrate' );

		if ( $wp_styles instanceof WP_Styles ) {
			foreach ( (array) $wp_styles->queue as $handle ) {
				if ( ! in_array( $handle, $keep_css, true ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		if ( $wp_scripts instanceof WP_Scripts ) {
			foreach ( (array) $wp_scripts->queue as $handle ) {
				if ( ! in_array( $handle, $keep_js, true ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}

		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
	}

	public function no_bar( $show ) {
		return self::is_app() ? false : $show;
	}

	public function body_class( array $classes ) {
		if ( self::is_app() ) {
			$classes[] = 'cmb-body';
		}
		return $classes;
	}
}
