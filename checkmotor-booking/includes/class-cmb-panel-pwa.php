<?php
/**
 * وب‌اپ پنل مدیریت — نصب جداگانه‌ی «مدیریت رزرو» روی گوشی.
 *
 * افزونه‌ی PWA سایت روی همه‌ی صفحه‌ها یک manifest مشترک چاپ می‌کند
 * که start_url آن صفحه‌ی اپ اصلی (پروفایل) است. آیفون و اندروید موقع
 * «Add to Home Screen» همان start_url را برمی‌دارند؛ یعنی حتی اگر
 * مدیر روی پنل بود، آیکونی ساخته می‌شد که صفحه‌ی پروفایل را باز
 * می‌کرد نه پنل را.
 *
 * حالا پنل manifest خودش را دارد: start_url خود پنل، id جدا، نام و
 * آیکون جدا. روی صفحه‌ی پنل هم تگ‌های PWA دیگران برداشته می‌شوند تا
 * مرورگر فقط manifest پنل را ببیند. نتیجه یک اپ دوم و مستقل است و اپ
 * اصلی سایت دست نمی‌خورد.
 *
 * برای خاموش کردن:  update_option( 'cmb_panel_pwa', 0 );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Panel_Pwa {

	/** همان رنگ نوار بالای پنل. */
	const THEME = '#13202B';

	/** همان رنگ پس‌زمینه‌ی پنل؛ صفحه‌ی آغاز اپ روی اندروید با این رنگ کشیده می‌شود. */
	const BG = '#F4F6F8';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// مثل خود افزونه‌ی PWA: خیلی زود و مستقل از قواعد بازنویسی.
		add_action( 'init', array( $this, 'maybe_serve' ), 0 );

		/* افزونه‌ی PWA سایت (TeamLIFO PWA از نسخه‌ی 2.4.0) روی صفحه‌ای
		   که این فیلتر true برگرداند manifest، متاتگ‌ها و بنر نصب خودش
		   را چاپ نمی‌کند و فقط Service Worker را ثبت می‌کند. با نسخه‌های
		   قدیمی‌تر هم کار می‌کند: strip_foreign() تگ‌هایش را برمی‌دارد. */
		add_filter( 'pool_pwa_skip_page', array( $this, 'skip_site_pwa' ) );
	}

	/**
	 * آیا قابلیت روشن است؟ پیش‌فرض: روشن.
	 */
	public static function on() {
		return (bool) get_option( 'cmb_panel_pwa', 1 );
	}

	/**
	 * آیا درخواست جاری صفحه‌ی پنل است؟ همان شرط template_redirect.
	 */
	public static function is_panel() {
		return (bool) get_query_var( 'cmb_app' ) && 0 === strpos( (string) get_query_var( 'cmb_route' ), 'panel' );
	}

	public function skip_site_pwa( $skip ) {
		return ( self::on() && self::is_panel() ) ? true : $skip;
	}

	/**
	 * نامی که زیر آیکون روی صفحه‌ی اصلی گوشی نوشته می‌شود.
	 */
	public static function name() {
		$name = trim( (string) get_option( 'cmb_panel_app_name', '' ) );

		return '' !== $name ? $name : 'مدیریت رزرو';
	}

	public static function manifest_url() {
		return add_query_arg( 'cmb_pwa', 'manifest', home_url( '/' ) );
	}

	public static function icon_url( $file ) {
		return CMB_URL . 'assets/img/' . $file;
	}

	/**
	 * manifest پنل.
	 *
	 * id باید با id اپ اصلی فرق داشته باشد؛ وگرنه اندروید این را همان
	 * اپ نصب‌شده می‌داند و دکمه‌ی نصب نمی‌دهد. scope کل مسیر اپ است نه
	 * فقط پنل: go() در panel.js نشانی خلاصه را بدون اسلش آخر می‌سازد
	 * (/reserve/panel) و «دیدن اپ مشتری» هم به /reserve/ می‌رود؛ هر دو
	 * باید داخل همان پنجره‌ی اپ بمانند.
	 *
	 * @return array
	 */
	public static function manifest() {
		$start = cmb_app_url( 'panel' );
		$name  = self::name();

		$manifest = array(
			'id'               => (string) wp_parse_url( $start, PHP_URL_PATH ),
			'name'             => $name,
			'short_name'       => $name,
			'description'      => 'پنل مدیریت نوبت‌های چک موتور',
			'lang'             => 'fa',
			'dir'              => 'rtl',
			'start_url'        => $start,
			'scope'            => cmb_app_url(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'theme_color'      => self::THEME,
			'background_color' => self::BG,
			'icons'            => array(
				array(
					'src'     => self::icon_url( 'panel-icon-192.png' ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => self::icon_url( 'panel-icon-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => self::icon_url( 'panel-icon-maskable-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);

		/**
		 * برای تغییر نام، آیکون یا هر فیلد دیگر manifest پنل.
		 */
		return (array) apply_filters( 'cmb_panel_pwa_manifest', $manifest );
	}

	/**
	 * تحویل manifest روی ‎/?cmb_pwa=manifest
	 */
	public function maybe_serve() {
		if ( ! isset( $_GET['cmb_pwa'] ) || 'manifest' !== $_GET['cmb_pwa'] || ! self::on() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: public, max-age=3600' );
		}

		echo wp_json_encode( self::manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * تگ‌های نصب پنل — پیش از wp_head چاپ می‌شوند تا اولین manifest
	 * صفحه همین باشد (مرورگر فقط اولی را می‌خواند).
	 *
	 * status-bar-style روی black-translucent است چون نوار بالای پنل
	 * خودش تیره است و فاصله‌ی safe-area-inset-top را حساب می‌کند.
	 */
	public static function head_tags() {
		if ( ! self::on() ) {
			return;
		}

		$name = self::name();
		?>
<link rel="manifest" href="<?php echo esc_url( self::manifest_url() ); ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $name ); ?>">
<meta name="application-name" content="<?php echo esc_attr( $name ); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( self::icon_url( 'panel-icon-180.png' ) ); ?>">
		<?php
	}

	/**
	 * wp_head بدون تگ‌های PWA دیگران.
	 *
	 * فقط ترتیب کافی نیست: برای عنوان و آیکون آیفون روشن نیست کدام
	 * تگ تکراری برنده می‌شود، و خود وردپرس هم اگر «نماد سایت» تنظیم
	 * شده باشد apple-touch-icon سایت را چاپ می‌کند.
	 */
	public static function wp_head() {
		if ( ! self::on() ) {
			wp_head();
			return;
		}

		ob_start();
		wp_head();

		echo self::strip_foreign( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * برداشتن تگ‌های manifest و نصب آیفون از یک تکه HTML.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function strip_foreign( $html ) {
		$drop = array(
			'link' => array( 'manifest', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'apple-touch-startup-image' ),
			'meta' => array(
				'apple-mobile-web-app-capable',
				'apple-mobile-web-app-title',
				'apple-mobile-web-app-status-bar-style',
				'mobile-web-app-capable',
				'application-name',
				'theme-color',
			),
		);

		$out = preg_replace_callback(
			'#<(link|meta)\s[^>]*>[ \t]*\R?#i',
			function ( $m ) use ( $drop ) {
				$tag  = strtolower( $m[1] );
				$attr = ( 'link' === $tag ) ? 'rel' : 'name';

				if ( ! preg_match( '#\s' . $attr . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $m[0], $a ) ) {
					return $m[0];
				}

				$tokens = preg_split( '#\s+#', strtolower( trim( implode( '', array_slice( $a, 1 ) ) ) ) );

				return array_intersect( $tokens, $drop[ $tag ] ) ? '' : $m[0];
			},
			$html
		);

		// اگر regex به هر دلیلی شکست خورد، سر صفحه نباید خالی شود.
		return null === $out ? $html : $out;
	}

	/**
	 * داده‌ی دکمه‌ی «نصب روی گوشی» در panel.js.
	 *
	 * @return array|null
	 */
	public static function js_config() {
		if ( ! self::on() ) {
			return null;
		}

		return array(
			'name' => self::name(),
			'icon' => self::icon_url( 'panel-icon-192.png' ),
		);
	}
}
