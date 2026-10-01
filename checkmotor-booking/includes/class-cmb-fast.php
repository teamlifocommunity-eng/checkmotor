<?php
/**
 * «حالت سریع» — نصب و نگه‌داری فایل mu/cmb-fast-requests.php.
 *
 * فیلتر کردن افزونه‌ها باید پیش از بارگذاری افزونه‌ها انجام شود، پس
 * کارش از داخل خود این افزونه ممکن نیست و یک must-use plugin لازم است.
 * این کلاس آن فایل را در wp-content/mu-plugins کپی، به‌روز و حذف می‌کند.
 *
 * تنظیمات در گزینه‌ی cmb_fast:
 *   on       روشن/خاموش (پیش‌فرض روشن)
 *   pages    صفحه‌های اپ و پنل هم سریع شوند (پیش‌فرض روشن)
 *   keep     افزونه‌هایی که روی این درخواست‌ها هم بارگذاری می‌شوند؛
 *            null یعنی پیش‌فرض (PWA و افزونه‌های امنیتی)
 *   self     نام همین افزونه، تا فایل mu بداند کدام را نگه دارد
 *   auto_off اگر حالت سریع به‌خاطر خطای مهلک خودش خاموش شده باشد
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Fast {

	const FILE = 'cmb-fast-requests.php';

	public static function config() {
		$conf = get_option( 'cmb_fast' );

		return array_merge(
			array(
				'on'    => 1,
				'pages' => 1,
				'keep'  => null,
			),
			is_array( $conf ) ? $conf : array()
		);
	}

	/**
	 * ذخیره و اعمال فوری.
	 *
	 * @return true|WP_Error
	 */
	public static function save( array $changes ) {
		$conf         = array_merge( self::config(), $changes );
		$conf['self'] = CMB_BASENAME;

		// روشن کردن دوباره یعنی خطای قبلی دیده و رفع شده است.
		if ( ! empty( $conf['on'] ) ) {
			unset( $conf['auto_off'] );
		}

		update_option( 'cmb_fast', $conf );

		return self::sync();
	}

	public static function source() {
		return CMB_DIR . 'mu/' . self::FILE;
	}

	public static function target() {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::FILE;
	}

	public static function installed() {
		return file_exists( self::target() );
	}

	/**
	 * محتوای فایلی که در mu-plugins نوشته می‌شود: همان الگو، به‌اضافه‌ی
	 * سربرگ «Plugin Name» تا در فهرست Must-Use نام درستی داشته باشد.
	 *
	 * الگوی داخل بسته عمداً این سربرگ را ندارد؛ وردپرس فایل‌های یک پوشه
	 * پایین‌تر از پوشه‌ی افزونه را هم برای پیدا کردن فایل اصلی می‌گردد و
	 * لینک «فعال‌سازی» بعد از بارگذاری zip به این فایل می‌رفت.
	 *
	 * @return string
	 */
	public static function contents() {
		$code   = (string) file_get_contents( self::source() ); // phpcs:ignore
		$header = "<?php\n/**\n"
			. " * Plugin Name: چک موتور — درخواست‌های سریع\n"
			. ' * Description: روی درخواست‌های خود سیستم رزرو چک موتور فقط افزونه‌های لازم بارگذاری می‌شوند. افزونه‌ی «چک موتور — سیستم رزرو نوبت» این فایل را خودش نصب و حذف می‌کند؛ برای خاموش کردن از «رزرو نوبت ← تنظیمات ← سرعت» استفاده کنید.' . "\n"
			. " */\n";

		return (string) preg_replace( '/^<\?php\s*/', $header, $code, 1 );
	}

	/**
	 * فایل mu را با تنظیمات هم‌خوان می‌کند.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		$conf = self::config();

		return empty( $conf['on'] ) ? self::remove() : self::install();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function install() {
		$src = self::source();
		$dst = self::target();

		if ( ! file_exists( $src ) ) {
			return new WP_Error( 'cmb_fast_src', 'فایل حالت سریع داخل بسته‌ی افزونه پیدا نشد؛ افزونه را دوباره نصب کنید.' );
		}

		$code = self::contents();

		if ( file_exists( $dst ) && md5_file( $dst ) === md5( $code ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || false === @file_put_contents( $dst, $code ) ) { // phpcs:ignore
			return new WP_Error(
				'cmb_fast_write',
				sprintf( 'نوشتن در پوشه‌ی %s ممکن نشد. دسترسی نوشتن این پوشه را از هاست بررسی کنید، یا فایل %s را دستی همان‌جا کپی کنید.', WPMU_PLUGIN_DIR, self::source() )
			);
		}

		return true;
	}

	/**
	 * فقط فایل خودمان را پاک می‌کند، نه هر فایل هم‌نامی را.
	 *
	 * @return true
	 */
	public static function remove() {
		$dst = self::target();

		if ( file_exists( $dst ) && false !== strpos( (string) file_get_contents( $dst ), 'cmb-fast-version' ) ) { // phpcs:ignore
			@unlink( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return true;
	}

	/**
	 * بعد از هر به‌روزرسانی افزونه (که هوک فعال‌سازی را صدا نمی‌زند)
	 * یا خاموش شدن خودکار، فایل mu را هم‌خوان می‌کند. روی پیشخوان اجرا
	 * می‌شود و در حالت عادی فقط یک گزینه می‌خواند.
	 */
	public static function maybe_sync() {
		$conf  = self::config();
		$stamp = CMB_VERSION . '|' . (int) ! empty( $conf['on'] ) . '|' . (int) self::installed();

		if ( get_option( 'cmb_fast_stamp' ) === $stamp ) {
			return;
		}

		if ( empty( $conf['self'] ) || CMB_BASENAME !== $conf['self'] ) {
			$conf['self'] = CMB_BASENAME;
			update_option( 'cmb_fast', $conf );
		}

		$result = self::sync();

		update_option(
			'cmb_fast_stamp',
			is_wp_error( $result ) ? 'failed' : CMB_VERSION . '|' . (int) ! empty( $conf['on'] ) . '|' . (int) self::installed(),
			false
		);
	}

	/**
	 * آیا این افزونه در حالت سریع (با تنظیمات فعلی) بارگذاری می‌شود؟
	 */
	public static function kept( $plugin ) {
		$conf = self::config();

		if ( CMB_BASENAME === $plugin ) {
			return true;
		}

		if ( is_array( $conf['keep'] ) ) {
			return in_array( $plugin, $conf['keep'], true );
		}

		return self::keep_by_default( $plugin );
	}

	/**
	 * همان cmb_fast_keep_default() داخل فایل mu. آن فایل هنگام include
	 * کد اجرا می‌کند، پس این‌جا جداگانه تکرار شده؛ هر دو باید یکی بمانند.
	 */
	public static function keep_by_default( $plugin ) {
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
}
