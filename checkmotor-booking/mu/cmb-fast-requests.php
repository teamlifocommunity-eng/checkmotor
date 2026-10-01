<?php
/**
 * چک موتور — درخواست‌های سریع (فایل must-use)
 *
 * cmb-fast-version: 1
 *
 * این فایل عمداً سربرگ افزونه (نام افزونه) ندارد. اگر داشت، وردپرس بعد
 * از بارگذاری zip آن را با فایل اصلی افزونه‌ی رزرو اشتباه می‌گرفت (هر دو
 * داخل یک پوشه‌اند و این یکی به ترتیب الفبا جلوتر است) و لینک «فعال‌سازی»
 * به این فایل می‌رفت: «این افزونه header معتبر ندارد». CMB_Fast::contents()
 * سربرگ را فقط هنگام نوشتن نسخه‌ی mu-plugins اضافه می‌کند.
 *
 * چرا: بیشتر زمان هر درخواست، بالا آمدن وردپرس با همه‌ی افزونه‌های
 * سایت است (فروشگاه، صفحه‌ساز، سئو و…) نه کار خود سیستم رزرو که چند
 * میلی‌ثانیه است. پنل و اپ رزرو هیچ‌کدام از آن افزونه‌ها را لازم
 * ندارند، پس روی همین درخواست‌ها بارگذاری نمی‌شوند. بقیه‌ی سایت هیچ
 * تغییری نمی‌کند.
 *
 * ورود و خروج (کد تایید، رمز) عمداً با بارگذاری کامل می‌ماند: افزونه‌های
 * امنیتی و ورود سایت ممکن است روی آن‌ها کار داشته باشند.
 *
 * اگر روی یکی از همین درخواست‌ها خطای مهلک رخ بدهد (مثلاً قالبی که بدون
 * بررسی تابع یک افزونه‌ی دیگر را صدا می‌زند)، حالت سریع خودش خاموش
 * می‌شود و دلیلش در تنظیمات افزونه نشان داده می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cmb_fast_kind' ) ) {

	/**
	 * نوع درخواست: api، page یا رشته‌ی خالی (یعنی کاری نکن).
	 */
	function cmb_fast_kind( array $conf ) {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path  = rawurldecode( (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
		$route = null;
		$pos   = strpos( $path, '/wp-json/cmb/v1/' );

		if ( false !== $pos ) {
			$route = substr( $path, $pos + strlen( '/wp-json/cmb/v1/' ) );
		} elseif ( isset( $_GET['rest_route'] ) && 0 === strpos( (string) $_GET['rest_route'], '/cmb/v1/' ) ) { // phpcs:ignore
			$route = substr( (string) $_GET['rest_route'], strlen( '/cmb/v1/' ) ); // phpcs:ignore
		}

		if ( null !== $route ) {
			$full = array( 'otp/request', 'otp/verify', 'logout', 'panel/login', 'panel/otp/send', 'panel/otp/verify' );

			return in_array( trim( (string) $route, '/' ), $full, true ) ? '' : 'api';
		}

		// تازه‌سازی nonce اپ و پنل
		if ( 'admin-ajax.php' === basename( $path ) && isset( $_REQUEST['action'] ) && 'cmb_nonce' === $_REQUEST['action'] ) { // phpcs:ignore
			return 'api';
		}

		if ( empty( $conf['pages'] ) ) {
			return '';
		}

		// صفحه‌های اپ و پنل: /{slug}/…
		$slug = trim( rawurldecode( (string) get_option( 'cmb_app_slug', 'reserve' ) ), '/' );
		$slug = '' !== $slug ? $slug : 'reserve';
		$home = rtrim( rawurldecode( (string) parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ), '/' );
		$base = strtolower( $home . '/' . $slug );
		$low  = strtolower( $path );

		return ( $low === $base || 0 === strpos( $low, $base . '/' ) ) ? 'page' : '';
	}

	/**
	 * افزونه‌هایی که اگر مدیر فهرست را تنظیم نکرده باشد، نگه داشته می‌شوند:
	 * افزونه‌ی PWA (manifest و Service Worker صفحه‌های اپ) و افزونه‌های امنیتی.
	 */
	function cmb_fast_keep_default( $plugin ) {
		$dirs = array(
			'wordfence',
			'better-wp-security',
			'ithemes-security-pro',
			'all-in-one-wp-security-and-firewall',
			'sucuri-scanner',
			'wp-cerber',
			'defender-security',
			'wp-defender',
			'ninjafirewall',
			'wp-simple-firewall',
		);

		return 'pool-pwa.php' === basename( $plugin ) || in_array( dirname( $plugin ), $dirs, true );
	}

	/**
	 * فهرست افزونه‌های فعال، بدون آن‌هایی که این درخواست لازم ندارد.
	 */
	function cmb_fast_filter( $plugins, array $conf ) {
		$plugins = (array) $plugins;
		$self    = '';

		foreach ( $plugins as $plugin ) {
			if ( ( isset( $conf['self'] ) && $plugin === $conf['self'] ) || 'checkmotor-booking.php' === basename( $plugin ) ) {
				$self = $plugin;
				break;
			}
		}

		// خود سیستم رزرو فعال نیست: دست نمی‌زنیم.
		if ( '' === $self ) {
			return $plugins;
		}

		$keep = ( isset( $conf['keep'] ) && is_array( $conf['keep'] ) ) ? $conf['keep'] : null;
		$out  = array();

		foreach ( $plugins as $plugin ) {
			if ( $plugin === $self || ( null === $keep ? cmb_fast_keep_default( $plugin ) : in_array( $plugin, $keep, true ) ) ) {
				$out[] = $plugin;
			}
		}

		return $out;
	}
}

$cmb_fast_conf = get_option( 'cmb_fast' );
$cmb_fast_conf = ( is_array( $cmb_fast_conf ) ? $cmb_fast_conf : array() ) + array(
	'on'    => 1,
	'pages' => 1,
);
$cmb_fast_kind = empty( $cmb_fast_conf['on'] ) ? '' : cmb_fast_kind( $cmb_fast_conf );

if ( '' !== $cmb_fast_kind ) {
	define( 'CMB_FAST_REQUEST', $cmb_fast_kind );

	if ( ! headers_sent() ) {
		header( 'X-CMB-Fast: ' . $cmb_fast_kind );
	}

	add_filter(
		'option_active_plugins',
		function ( $plugins ) use ( $cmb_fast_conf ) {
			return cmb_fast_filter( $plugins, $cmb_fast_conf );
		},
		1
	);

	add_filter(
		'site_option_active_sitewide_plugins',
		function ( $plugins ) use ( $cmb_fast_conf ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}

			return array_intersect_key( $plugins, array_flip( cmb_fast_filter( array_keys( $plugins ), $cmb_fast_conf ) ) );
		},
		1
	);

	/* فهرستِ کوتاه‌شده هرگز نباید ذخیره شود؛ اگر کدی در همین درخواست
	   active_plugins را بنویسد، بقیه‌ی افزونه‌های سایت غیرفعال می‌شدند. */
	add_filter(
		'pre_update_option_active_plugins',
		function ( $value, $old ) {
			return $old;
		},
		1,
		2
	);

	/* تور ایمنی: خطای مهلک در درخواست سریع یعنی چیزی (معمولاً قالب) به
	   افزونه‌ای که بارگذاری نشده وابسته است. حالت سریع خاموش می‌شود تا
	   درخواست بعدی کامل بارگذاری شود، و دلیل در تنظیمات دیده می‌شود. */
	register_shutdown_function(
		function () {
			$error = error_get_last();

			if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				return;
			}

			$conf = get_option( 'cmb_fast' );
			$conf = is_array( $conf ) ? $conf : array();

			$conf['on']       = 0;
			$conf['auto_off'] = array(
				'time'    => time(),
				'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? substr( (string) $_SERVER['REQUEST_URI'], 0, 200 ) : '',
				'message' => substr( (string) strtok( (string) $error['message'], "\n" ), 0, 300 ),
				'file'    => (string) $error['file'] . ':' . (int) $error['line'],
			);

			update_option( 'cmb_fast', $conf );
		}
	);
}

unset( $cmb_fast_conf, $cmb_fast_kind );
