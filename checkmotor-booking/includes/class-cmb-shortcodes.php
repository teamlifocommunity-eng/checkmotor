<?php
/**
 * شورت‌کدهای افزونه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Shortcodes {

	private static $instance = null;

	/**
	 * آیا در این درخواست به فایل‌های استاتیک نیاز است؟
	 */
	private $needs_assets = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'checkmotor_booking', array( $this, 'render_booking' ) );
		add_shortcode( 'checkmotor_my_bookings', array( $this, 'render_my_bookings' ) );
		add_shortcode( 'checkmotor_login', array( $this, 'render_login' ) );

		add_action( 'wp', array( $this, 'detect_shortcodes' ) );
	}

	/**
	 * تشخیص وجود شورت‌کد در محتوا برای بارگذاری شرطی assets.
	 */
	public function detect_shortcodes() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post ) {
			return;
		}

		foreach ( array( 'checkmotor_booking', 'checkmotor_my_bookings', 'checkmotor_login' ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				$this->needs_assets = true;
				break;
			}
		}
	}

	public function needs_assets() {
		return $this->needs_assets;
	}

	/**
	 * [checkmotor_booking]
	 */
	public function render_booking( $atts = array() ) {
		$this->needs_assets = true;

		$atts = shortcode_atts(
			array(
				'branch' => 0,
			),
			$atts,
			'checkmotor_booking'
		);

		$branch_id = (int) $atts['branch'];
		$branch    = CMB_Services::get_branch( $branch_id );
		$services  = CMB_Services::get_services( $branch_id );

		if ( empty( $services ) ) {
			return '<div class="cmb-notice cmb-notice--error">در حال حاضر خدمتی برای رزرو تعریف نشده است.</div>';
		}

		ob_start();
		include CMB_DIR . 'templates/booking-form.php';

		return ob_get_clean();
	}

	/**
	 * [checkmotor_my_bookings]
	 */
	public function render_my_bookings( $atts = array() ) {
		$this->needs_assets = true;

		ob_start();
		include CMB_DIR . 'templates/my-bookings.php';

		return ob_get_clean();
	}

	/**
	 * [checkmotor_login] — فرم مستقل ورود با کد تایید.
	 */
	public function render_login( $atts = array() ) {
		$this->needs_assets = true;

		$atts = shortcode_atts(
			array(
				'redirect' => '',
			),
			$atts,
			'checkmotor_login'
		);

		ob_start();
		include CMB_DIR . 'templates/login-form.php';

		return ob_get_clean();
	}

	/**
	 * نشانی صفحه‌ی رزرو.
	 */
	public static function booking_url() {
		$page_id = (int) get_option( 'cmb_page_booking' );

		return $page_id ? get_permalink( $page_id ) : home_url( '/' );
	}
}
