<?php
/**
 * عیب‌یابی (بخش «آزمون و عیب‌یابی» پنل، فقط مدیر سایت).
 *
 *   · بررسی کامل: سرور و وردپرس، تنظیمات رزرو/پرداخت/پیامک (همان
 *     CMB_Health)، خطاهای ۲۴ ساعت گذشته، و برای هر مرکز و نمایندگی:
 *     قابل رزرو بودن، کیف پول، کاربران، شیفت‌ها و اینکه هر خدمتش در بازه‌ی
 *     رزرو واقعاً نوبت خالی دارد یا نه — و اگر ندارد، چرا.
 *   · شبیه‌ساز رزرو: تقویم یک خدمت با دلیل هر شیفت بسته، کنار نتیجه‌ی
 *     همان بررسی‌ای که موقع ثبت انجام می‌شود (هر ناهماهنگی علامت می‌خورد)،
 *     و وضعیت یک مشتری مشخص (نوبت‌های فعال، سقف، کیف پول).
 *   · اطلاعات سیستم: متنی بی‌رمز برای فرستادن به پشتیبان.
 *
 * هیچ‌کدام چیزی را تغییر نمی‌دهند. «آزمون ثبت» و «آزمون خودکار» در
 * CMB_Selftest داخل تراکنشی اجرا می‌شوند که آخر کار برگردانده می‌شود.
 *
 * @package CheckMotor_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Diagnose {

	const OK   = 'ok';
	const INFO = 'info';
	const WARN = 'warn';
	const BAD  = 'bad';

	/** افزونه‌های کش صفحه (نامک پوشه). */
	const CACHE_PLUGINS = array( 'litespeed-cache', 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'autoptimize', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'cache-enabler', 'comet-cache', 'wp-optimize', 'swift-performance-lite', 'nitropack', 'flying-press', 'wp-cloudflare-page-cache', 'powered-cache', 'hyper-cache', 'cachify', 'borlabs-cache', 'speed-booster-pack' );

	/** افزونه‌های امنیتی که ممکن است REST یا admin-ajax را ببندند. */
	const GUARD_PLUGINS = array( 'wordfence', 'better-wp-security', 'all-in-one-wp-security-and-firewall', 'disable-json-api', 'disable-wp-rest-api', 'wp-hide-security-enhancer', 'sucuri-scanner', 'ninjafirewall', 'wp-simple-firewall', 'wps-hide-login' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$admin = array( CMB_Panel_Api::instance(), 'guard_center' );

		$list = array(
			'panel/debug/checks'   => array( 'GET', 'rest_checks' ),
			'panel/debug/simulate' => array( 'GET', 'rest_simulate' ),
			'panel/debug/try'      => array( 'POST', 'rest_try' ),
			'panel/debug/selftest' => array( 'POST', 'rest_selftest' ),
			'panel/debug/info'     => array( 'GET', 'rest_info' ),
		);

		foreach ( $list as $path => $conf ) {
			register_rest_route(
				'cmb/v1',
				'/' . $path,
				array(
					'methods'             => 'GET' === $conf[0] ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $conf[1] ),
					'permission_callback' => $admin,
				)
			);
		}
	}

	protected static function item( $level, $title, $detail, $fix = '' ) {
		return array(
			'level'  => $level,
			'title'  => $title,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/** سطح بدترین مورد (ok < info < warn < bad). */
	protected static function worst( array $items ) {
		$rank = array( self::OK => 0, self::INFO => 1, self::WARN => 2, self::BAD => 3 );
		$w    = self::OK;

		foreach ( $items as $i ) {
			$l = isset( $i['level'] ) ? $i['level'] : self::OK;

			if ( isset( $rank[ $l ] ) && $rank[ $l ] > $rank[ $w ] ) {
				$w = $l;
			}
		}

		return $w;
	}

	/* ------------------------------------------------------------------
	 * بررسی کامل
	 * --------------------------------------------------------------- */

	public static function rest_checks( WP_REST_Request $request ) {
		return rest_ensure_response( self::checks( ! $request->get_param( 'quick' ) ) );
	}

	/**
	 * @param bool $loopback درخواست آزمایشی سرور به خودش (کند؛ در آزمون‌ها خاموش).
	 */
	public static function checks( $loopback = true ) {
		$system  = self::check_system( $loopback );
		$health  = CMB_Health::run();
		$booking = array_map(
			function ( $c ) {
				return self::item( $c['level'], $c['title'], $c['detail'], isset( $c['fix'] ) ? $c['fix'] : '' );
			},
			$health['checks']
		);
		$log      = self::check_log();
		$branches = array();

		foreach ( CMB_Branches::all() as $b ) {
			$branches[] = self::check_branch( $b );
		}

		$sections = array(
			array( 'key' => 'system', 'title' => 'سرور و وردپرس', 'items' => $system ),
			array( 'key' => 'booking', 'title' => 'رزرو، پرداخت و پیامک', 'items' => $booking ),
			array( 'key' => 'log', 'title' => 'خطاهای ۲۴ ساعت گذشته', 'items' => $log ),
		);

		$all = array_merge( $system, $booking, $log );

		foreach ( $branches as $b ) {
			if ( 'active' === $b['status'] ) {
				$all[] = array( 'level' => $b['level'] );
			}
		}

		foreach ( $sections as &$s ) {
			$s['level'] = self::worst( $s['items'] );
		}
		unset( $s );

		return array(
			'level'    => self::worst( $all ),
			'sections' => $sections,
			'branches' => $branches,
			'now'      => cmb_fa_num( cmb_jalali_date( cmb_today(), 'full' ) . '، ساعت ' . cmb_now()->format( 'H:i' ) ),
		);
	}

	/**
	 * سرور، وردپرس و چیزهایی که بیرون از افزونه رزرو را خراب می‌کنند.
	 */
	protected static function check_system( $loopback ) {
		global $wpdb;

		$out = array();

		// PHP و وردپرس
		$php = PHP_VERSION;
		$out[] = version_compare( $php, '7.4', '<' )
			? self::item( self::WARN, 'نسخه‌ی PHP', sprintf( 'PHP %s قدیمی است. افزونه کار می‌کند، ولی هاست‌ها پشتیبانی امنیتی این نسخه را ندارند.', $php ), 'از پنل هاست نسخه‌ی ۸.۱ یا بالاتر را انتخاب کنید.' )
			: self::item( self::OK, 'نسخه‌ی PHP', 'PHP ' . $php );

		$out[] = self::item( self::OK, 'وردپرس و افزونه', sprintf( 'وردپرس %s، افزونه‌ی رزرو %s، دیتابیس %s', get_bloginfo( 'version' ), CMB_VERSION, (string) get_option( 'cmb_db_version', '?' ) ) );

		// جدول‌ها
		$missing = CMB_Install::missing_tables();

		if ( CMB_Debug::ready() && ! self::table_exists( cmb_table( 'log' ) ) ) {
			$missing[] = cmb_table( 'log' );
		}

		if ( $missing ) {
			$out[] = self::item( self::BAD, 'جدول‌های دیتابیس', 'این جدول‌ها ساخته نشده‌اند: ' . implode( '، ', $missing ) . '. هر بخشی که به آن‌ها نیاز دارد خطا می‌دهد.', 'یک بار وارد پیشخوان وردپرس شوید تا مهاجرت اجرا شود. اگر ماند، افزونه را غیرفعال و دوباره فعال کنید (داده‌ها پاک نمی‌شوند).' );
		}

		// موتور جدول‌ها
		if ( cmb_tx_supported() ) {
			$names = array();

			foreach ( array( 'bookings', 'branch_ledger', 'payments', 'wallet', 'services', 'branches', 'closures' ) as $t ) {
				$names[] = $wpdb->prepare( '%s', cmb_table( $t ) );
			}

			$rows = $wpdb->get_results( 'SELECT TABLE_NAME AS n, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ',', $names ) . ')' ); // phpcs:ignore
			$bad  = array();

			foreach ( (array) $rows as $r ) {
				if ( 'InnoDB' !== $r->e ) {
					$bad[] = $r->n . ' (' . $r->e . ')';
				}
			}

			$out[] = $bad
				? self::item( self::WARN, 'موتور دیتابیس', 'این جدول‌ها InnoDB نیستند: ' . implode( '، ', $bad ) . '. ثبت هم‌زمان نوبت و هزینه‌ی نمایندگی بدون تراکنش انجام می‌شود و «آزمون خودکار» اجرا نمی‌شود.', 'از phpMyAdmin هاست موتور این جدول‌ها را به InnoDB تغییر دهید (پیش از آن پشتیبان بگیرید).' )
				: self::item( self::OK, 'موتور دیتابیس', sprintf( '%s — همه‌ی جدول‌های رزرو InnoDB هستند.', self::db_server() ) );
		}

		// نمایش خطای PHP وسط پاسخ‌ها
		$display = ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) || in_array( strtolower( (string) ini_get( 'display_errors' ) ), array( '1', 'on', 'stdout' ), true );

		$out[] = $display
			? self::item( self::BAD, 'نمایش خطاهای PHP', 'نمایش خطا روشن است: هر هشدار PHP (از هر افزونه‌ای) وسط پاسخ اپ و پنل چاپ می‌شود، پاسخ خراب می‌شود و مشتری فقط «خطایی رخ داد» می‌بیند.', "در wp-config.php بگذارید: define( 'WP_DEBUG_DISPLAY', false ); و @ini_set( 'display_errors', 0 ); — اگر لاگ لازم است WP_DEBUG_LOG را روشن کنید." )
			: self::item( self::OK, 'نمایش خطاهای PHP', 'خاموش است؛ هشدارهای PHP پاسخ‌ها را خراب نمی‌کنند.' );

		// حافظه
		$mem = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $mem > 0 && $mem < 128 * MB_IN_BYTES ) {
			$out[] = self::item( self::WARN, 'حافظه‌ی PHP', sprintf( 'سقف حافظه %s است؛ با چند افزونه‌ی سنگین ممکن است درخواست‌ها نیمه‌کاره بمانند.', ini_get( 'memory_limit' ) ), 'از پنل هاست memory_limit را دست‌کم 256M بگذارید.' );
		}

		// HTTPS
		if ( 0 !== strpos( home_url( '/' ), 'https://' ) ) {
			$out[] = self::item( self::WARN, 'HTTPS', 'نشانی سایت HTTPS نیست؛ مرورگرها فرم‌ها را «ناامن» نشان می‌دهند و درگاه پرداخت بازگشت به آن را نمی‌پذیرد.', 'گواهی SSL را در هاست فعال و نشانی سایت را در «تنظیمات ← عمومی» با https ذخیره کنید.' );
		}

		// پیوندهای یکتا
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = self::item( self::BAD, 'پیوندهای یکتا', 'پیوندهای یکتا «ساده» است؛ نشانی اپ (/' . cmb_app_slug() . '/) و پنل باز نمی‌شوند.', 'در «تنظیمات ← پیوندهای یکتا» گزینه‌ی «نام نوشته» را انتخاب و ذخیره کنید.' );
		}

		// افزونه‌های کش و امنیت
		$slugs = array_map(
			function ( $p ) {
				return strtolower( (string) strtok( (string) $p, '/' ) );
			},
			(array) get_option( 'active_plugins', array() )
		);

		$cache = array_values( array_intersect( $slugs, self::CACHE_PLUGINS ) );
		$guard = array_values( array_intersect( $slugs, self::GUARD_PLUGINS ) );

		if ( $cache ) {
			$out[] = self::item(
				self::WARN,
				'افزونه‌ی کش',
				'فعال: ' . implode( '، ', $cache ) . '. اگر صفحه‌ی اپ یا پنل کش شود، مشتری بعد از چند ساعت «نشست شما منقضی شده» یا اطلاعات کس دیگری را می‌بیند.',
				sprintf( 'در تنظیمات افزونه‌ی کش، نشانی‌های /%1$s/ و /%1$s/panel و /wp-json/cmb/ را از کش مستثنا کنید.', cmb_app_slug() )
			);
		}

		if ( $guard ) {
			$out[] = self::item( self::INFO, 'افزونه‌ی امنیتی', 'فعال: ' . implode( '، ', $guard ) . '. اگر REST API یا admin-ajax را برای مهمان‌ها ببندد، ورود با کد و ثبت نوبت کار نمی‌کند.', 'مسیر /wp-json/cmb/v1/ و admin-ajax.php?action=cmb_nonce باید برای همه باز باشد.' );
		}

		// حالت سریع
		if ( class_exists( 'CMB_Fast' ) ) {
			$fast = CMB_Fast::config();

			if ( ! empty( $fast['auto_off'] ) ) {
				$out[] = self::item( self::WARN, 'حالت سریع', 'حالت سریع پس از یک خطای مهلک خودش خاموش شد: ' . ( is_array( $fast['auto_off'] ) && isset( $fast['auto_off']['message'] ) ? $fast['auto_off']['message'] : 'جزئیات در تنظیمات افزونه' ), 'پیشخوان ← رزرو نوبت ← تنظیمات ← سرعت.' );
			} elseif ( ! empty( $fast['on'] ) && ! CMB_Fast::installed() ) {
				$out[] = self::item( self::INFO, 'حالت سریع', 'روشن است ولی فایل mu-plugins نوشته نشده (پوشه قابل نوشتن نیست)؛ درخواست‌ها با سرعت عادی انجام می‌شوند.' );
			}
		}

		// شیء کش
		if ( wp_using_ext_object_cache() ) {
			$out[] = self::item( self::INFO, 'کش شیء', 'کش شیء بیرونی (Redis/Memcached) فعال است. اگر بعد از تغییر تنظیمات چیزی عوض نشد، کش را خالی کنید.' );
		}

		if ( $loopback ) {
			$out = array_merge( $out, self::check_loopback() );
		}

		// خود ابزار عیب‌یابی
		if ( ! CMB_Debug::on() ) {
			$out[] = self::item( self::INFO, 'گزارش خطا', 'ثبت خطاها خاموش است؛ اگر مشتری خطایی ببیند، ردی از آن نمی‌ماند.', 'در همین بخش، زبانه‌ی «گزارش خطا» آن را روشن کنید.' );
		}

		if ( CMB_Debug::verbose() ) {
			$out[] = self::item( self::INFO, 'حالت عیب‌یابی', 'روشن است: کد پیگیری خطاها به مشتری هم نشان داده می‌شود و رویدادهای عادی هم ثبت می‌شوند.', 'بعد از رفع مشکل، از زبانه‌ی «گزارش خطا» خاموشش کنید.' );
		}

		return $out;
	}

	/**
	 * درخواست سرور به خودش: REST و صفحه‌ی اپ همان‌طور که مرورگر مهمان می‌بیند.
	 */
	protected static function check_loopback() {
		$out  = array();
		$args = array(
			'timeout'     => 8,
			'redirection' => 2,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		);

		$t0  = microtime( true );
		$res = wp_remote_get( rest_url( 'cmb/v1/branches' ), $args );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$out[] = self::item( self::WARN, 'دسترسی به API رزرو', 'سرور نتوانست به خودش درخواست بفرستد (' . $res->get_error_message() . '). روی بعضی هاست‌ها این طبیعی است؛ اگر اپ در مرورگر کار می‌کند نگران نباشید.', 'اگر اپ هم «ارتباط با سرور برقرار نشد» می‌دهد، از هاست بپرسید درخواست به /wp-json/ بسته نیست.' );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$json = json_decode( $body, true );

			if ( 200 === $code && is_array( $json ) ) {
				$out[] = $ms > 3000
					? self::item( self::WARN, 'دسترسی به API رزرو', sprintf( 'کار می‌کند ولی کند است (%s میلی‌ثانیه). مشتری بین هر مرحله معطل می‌شود.', cmb_fa_num( $ms ) ), 'افزونه‌های سنگین سایت را بررسی کنید؛ «حالت سریع» افزونه را هم روشن نگه دارید.' )
					: self::item( self::OK, 'دسترسی به API رزرو', sprintf( 'پاسخ در %s میلی‌ثانیه.', cmb_fa_num( $ms ) ) );
			} elseif ( 200 === $code ) {
				$out[] = self::item( self::BAD, 'دسترسی به API رزرو', 'پاسخ JSON نیست؛ احتمالاً یک افزونه یا قالب پیش از پاسخ چیزی چاپ می‌کند (هشدار PHP، فاصله‌ی خالی، تبلیغ): «' . cmb_substr( wp_strip_all_tags( $body ), 0, 120 ) . '»', 'نمایش خطای PHP را خاموش کنید و افزونه‌ای را که اخیراً نصب شده بررسی کنید.' );
			} else {
				$out[] = self::item( self::BAD, 'دسترسی به API رزرو', sprintf( 'کد پاسخ %d. %s', $code, is_array( $json ) && isset( $json['message'] ) ? '«' . $json['message'] . '»' : '' ), 'یک افزونه‌ی امنیتی یا فایروال هاست REST API را برای مهمان‌ها بسته است؛ مسیر /wp-json/cmb/v1/ را باز کنید.' );
			}
		}

		$res = wp_remote_get( cmb_app_url(), $args );

		if ( ! is_wp_error( $res ) ) {
			$code  = (int) wp_remote_retrieve_response_code( $res );
			$body  = (string) wp_remote_retrieve_body( $res );
			$hit   = '';

			foreach ( array( 'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache', 'x-wp-rocket-cache', 'x-cache-status' ) as $h ) {
				$v = (string) wp_remote_retrieve_header( $res, $h );

				if ( '' !== $v && false !== stripos( $v, 'hit' ) ) {
					$hit = $h . ': ' . $v;
				}
			}

			if ( 404 === $code ) {
				$out[] = self::item( self::BAD, 'صفحه‌ی اپ', 'نشانی اپ (' . cmb_app_url() . ') خطای ۴۰۴ می‌دهد.', 'در «تنظیمات ← پیوندهای یکتا» فقط دکمه‌ی ذخیره را بزنید تا قواعد بازنویسی تازه شوند.' );
			} elseif ( 200 !== $code || false === strpos( $body, 'CMB_APP' ) ) {
				$out[] = self::item( self::WARN, 'صفحه‌ی اپ', sprintf( 'نشانی اپ با کد %d باز شد ولی اپ رزرو در آن پیدا نشد.', $code ), 'صفحه را در مرورگر باز کنید؛ اگر قالب یا صفحه‌ساز چیز دیگری نشان می‌دهد، تداخل نشانی را بررسی کنید.' );
			} elseif ( '' !== $hit ) {
				$out[] = self::item( self::BAD, 'صفحه‌ی اپ', 'صفحه‌ی اپ از کش آمد (' . $hit . '). کد امنیتی صفحه بعد از چند ساعت کهنه می‌شود و ورود و ثبت نوبت خطا می‌دهند.', sprintf( 'نشانی /%s/ را در افزونه‌ی کش یا کلادفلر از کش مستثنا کنید.', cmb_app_slug() ) );
			} else {
				$out[] = self::item( self::OK, 'صفحه‌ی اپ', 'باز می‌شود و کش نشده است.' );
			}
		}

		return $out;
	}

	/** خطاهای ۲۴ ساعت گذشته به تفکیک کد. */
	protected static function check_log() {
		$rows = CMB_Debug::recent_summary();

		if ( null === $rows ) {
			return array( self::item( self::INFO, 'گزارش خطا', 'جدول گزارش هنوز ساخته نشده (مهاجرت 1.10.0).' ) );
		}

		if ( ! $rows ) {
			return array( self::item( self::OK, 'خطا', 'در ۲۴ ساعت گذشته خطایی ثبت نشده است.' ) );
		}

		$labels = array(
			'sms'        => 'پیامک ارسال نشد',
			'gateway'    => 'درگاه پرداخت جواب نداد',
			'js_error'   => 'خطای جاوااسکریپت در مرورگر',
			'php_fatal'  => 'خطای مهلک PHP',
			'cmb_db_error' => 'خطای دیتابیس هنگام ثبت',
			'cmb_pay_gateway' => 'اتصال به درگاه هنگام ثبت نوبت',
		);

		$out = array();

		foreach ( $rows as $r ) {
			$title = isset( $labels[ $r->code ] ) ? $labels[ $r->code ] : $r->code;
			$out[] = self::item( self::BAD, $title, sprintf( '%s بار؛ آخرین: «%s»', cmb_fa_num( (int) $r->c ), cmb_substr( (string) $r->message, 0, 160 ) ), 'جزئیات هر مورد در زبانه‌ی «گزارش خطا».' );
		}

		return $out;
	}

	/**
	 * یک مرکز یا نمایندگی: قابل رزرو بودن، کیف پول، کاربران، شیفت و خدمات.
	 */
	public static function check_branch( $b ) {
		$id     = (int) $b->id;
		$status = CMB_Branches::status( $b );
		$agency = CMB_Branches::is_agency( $b );
		$reason = '';
		$ok     = CMB_Branches::bookable( $b, $reason );
		$items  = array();
		$fee    = CMB_Branches::fee();
		$free   = $agency ? CMB_Branch_Wallet::free( $id ) : 0;

		if ( 'active' !== $status ) {
			$items[] = self::item( self::INFO, 'وضعیت', sprintf( '%s است؛ مشتری‌ها آن را نمی‌بینند.', CMB_Branches::status_label( $status ) ), $agency ? 'از «نمایندگی‌ها» فعالش کنید.' : '' );
		} elseif ( ! $ok ) {
			$why = array(
				'balance'     => sprintf( 'موجودی آزاد کیف پول (%s) از هزینه‌ی هر رزرو (%s) کمتر است. مشتری این مرکز را با برچسب «فعلاً نوبت نمی‌گیرد» می‌بیند و نمی‌تواند نوبت بگیرد.', cmb_toman( $free ), cmb_toman( $fee ) ),
				'no_services' => 'هیچ خدمت فعالی ندارد؛ مشتری این مرکز را «فعلاً نوبت نمی‌گیرد» می‌بیند.',
				'inactive'    => 'فعال نیست.',
			);
			$fix = array(
				'balance'     => 'مدیر نمایندگی از «کیف پول نمایندگی» شارژ کند، یا شما از «نمایندگی‌ها ← تنظیم موجودی» اعتبار بدهید.',
				'no_services' => 'در «خدمات» همین نمایندگی دست‌کم یک خدمت کاتالوگ را فعال کنید.',
			);

			$items[] = self::item( self::BAD, 'قابل رزرو نیست', isset( $why[ $reason ] ) ? $why[ $reason ] : $reason, isset( $fix[ $reason ] ) ? $fix[ $reason ] : '' );
		} else {
			$items[] = self::item( self::OK, 'وضعیت', 'فعال و قابل رزرو.' );
		}

		if ( $agency ) {
			$low = (int) CMB_Settings::get( 'agency_low_balance', 400000 );

			if ( $ok && $fee > 0 && $free < max( $low, $fee * 2 ) ) {
				$items[] = self::item( self::WARN, 'کیف پول نمایندگی', sprintf( 'موجودی آزاد %s — فقط برای %s نوبت دیگر کافی است.', cmb_toman( $free ), cmb_fa_num( (int) floor( $free / max( 1, $fee ) ) ) ), 'یادآوری شارژ به مدیر نمایندگی.' );
			} elseif ( $fee > 0 ) {
				$items[] = self::item( self::OK, 'کیف پول نمایندگی', sprintf( 'موجودی آزاد %s (برای %s نوبت).', cmb_toman( $free ), cmb_fa_num( (int) floor( $free / max( 1, $fee ) ) ) ) );
			}

			$roles = array( 'manager' => 0, 'operator' => 0 );

			foreach ( CMB_Branches::users( $id ) as $u ) {
				if ( isset( $roles[ $u['role'] ] ) ) {
					$roles[ $u['role'] ]++;
				}
			}

			if ( ! $roles['manager'] ) {
				$items[] = self::item( self::WARN, 'کاربران', 'مدیر نمایندگی ندارد؛ کسی نمی‌تواند نوبت‌ها را در پنل ببیند یا کیف پول را شارژ کند.', 'نمایندگی‌ها ← کاربران و نقش‌ها ← افزودن مدیر.' );
			}

			if ( ! CMB_Branches::notify_phones( $b ) ) {
				$items[] = self::item( self::WARN, 'پیامک نمایندگی', 'شماره‌ای برای خبر نوبت تازه ندارد؛ نمایندگی از نوبت‌ها باخبر نمی‌شود.', 'در پروفایل نمایندگی «شماره‌ی مدیر» را وارد کنید.' );
			}

			$missing = array();

			foreach ( array( 'address' => 'نشانی', 'phone' => 'تلفن', 'city' => 'شهر' ) as $k => $label ) {
				if ( '' === trim( (string) ( isset( $b->$k ) ? $b->$k : '' ) ) ) {
					$missing[] = $label;
				}
			}

			if ( $missing ) {
				$items[] = self::item( self::WARN, 'اطلاعات تماس', implode( '، ', $missing ) . ' خالی است؛ در کارت انتخاب مرکز و رسید مشتری چیزی نوشته نمی‌شود.', 'پروفایل نمایندگی را کامل کنید.' );
			}
		}

		// شیفت‌ها
		$cap = 0;

		foreach ( cmb_blocks( $id ) as $blk ) {
			$cap += (int) $blk['capacity'];
		}

		if ( 0 === $cap ) {
			$items[] = self::item( self::BAD, 'ظرفیت شیفت‌ها', 'ظرفیت همه‌ی شیفت‌ها صفر است؛ هیچ خدمتی با ظرفیت مشترک قابل رزرو نیست.', 'تنظیمات شیفت همین شعبه.' );
		}

		// خدمات
		$services = array();

		foreach ( CMB_Services::get_services( $id, false ) as $s ) {
			if ( ! (int) $s->is_active ) {
				continue;
			}

			$services[] = self::service_status( $s, $id );
		}

		foreach ( $services as $s ) {
			if ( 'active' === $status && 1 === $s['active'] && ! $s['open'] ) {
				$items[] = self::item( self::BAD, 'خدمت بی‌نوبت: ' . $s['title'], 'در کل بازه‌ی رزرو هیچ شیفت خالی ندارد — ' . $s['why'], 'روزهای ارائه، ظرفیت/سهمیه و روزهای بسته را بررسی کنید؛ «شبیه‌ساز رزرو» دلیل هر روز را نشان می‌دهد.' );
			}
		}

		$level = 'active' === $status ? self::worst( $items ) : self::INFO;

		return array(
			'id'          => $id,
			'title'       => (string) $b->title,
			'type'        => $agency ? 'agency' : 'center',
			'status'      => $status,
			'statusLabel' => CMB_Branches::status_label( $status ),
			'bookable'    => $ok,
			'reason'      => $reason,
			'level'       => $level,
			'items'       => $items,
			'services'    => $services,
		);
	}

	/**
	 * تعداد شیفت خالی یک خدمت در بازه‌ی رزرو و نخستینش؛ اگر صفر، چرا.
	 */
	public static function service_status( $s, $branch ) {
		CMB_Availability::flush_cache();

		$cal   = CMB_Availability::get_calendar( (int) $s->id, (int) $branch );
		$open  = 0;
		$first = '';
		$why   = array();

		if ( is_wp_error( $cal ) ) {
			$why[ $cal->get_error_message() ] = 1;
		} else {
			foreach ( $cal['days'] as $d ) {
				if ( ! $d['blocks'] && '' !== $d['reason'] ) {
					$why[ $d['reason'] ] = isset( $why[ $d['reason'] ] ) ? $why[ $d['reason'] ] + 1 : 1;
				}

				foreach ( $d['blocks'] as $blk ) {
					if ( $blk['available'] ) {
						$open++;

						if ( '' === $first ) {
							$first = cmb_fa_num( $d['jalaliFull'] ) . ' — ' . $blk['label'];
						}
					} else {
						$r = '' !== $blk['reason'] ? $blk['reason'] : 'تکمیل';
						$why[ $r ] = isset( $why[ $r ] ) ? $why[ $r ] + 1 : 1;
					}
				}
			}
		}

		$parts = array();
		arsort( $why );

		foreach ( $why as $r => $n ) {
			$parts[] = sprintf( '%s (%s)', $r, cmb_fa_num( $n ) );
		}

		return array(
			'id'      => (int) $s->id,
			'title'   => (string) $s->title,
			'active'  => (int) $s->is_active,
			'deposit' => CMB_Payments::service_deposit( $s ),
			'open'    => $open,
			'first'   => $first,
			'why'     => implode( '، ', array_slice( $parts, 0, 4 ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * شبیه‌ساز رزرو
	 * --------------------------------------------------------------- */

	public static function rest_simulate( WP_REST_Request $request ) {
		$out = self::simulate( (int) $request->get_param( 'branch_id' ), (int) $request->get_param( 'service_id' ), (string) $request->get_param( 'phone' ) );

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function simulate( $branch_id, $service_id, $phone = '' ) {
		$branches = array();

		foreach ( CMB_Branches::all() as $b ) {
			$svc = array();

			foreach ( CMB_Services::get_services( (int) $b->id, false ) as $s ) {
				$svc[] = array( 'id' => (int) $s->id, 'title' => (string) $s->title, 'active' => (int) $s->is_active );
			}

			$branches[] = array( 'id' => (int) $b->id, 'title' => (string) $b->title, 'status' => CMB_Branches::status( $b ), 'services' => $svc );
		}

		$out = array(
			'branches' => $branches,
			'rules'    => array(
				'today'    => cmb_fa_num( cmb_jalali_date( cmb_today(), 'full' ) . '، ساعت ' . cmb_now()->format( 'H:i' ) ),
				'minDays'  => (int) CMB_Settings::get( 'min_days_ahead', 1 ),
				'window'   => (int) CMB_Settings::get( 'window_days', 7 ),
				'minHours' => (int) CMB_Settings::get( 'min_hours_ahead', 24 ),
				'maxActive' => (int) CMB_Settings::get( 'max_active_per_user', 1 ),
				'perService' => (bool) CMB_Settings::get( 'one_per_service', 1 ),
			),
		);

		if ( ! $branch_id || ! $service_id ) {
			return $out;
		}

		$b = CMB_Branches::get( $branch_id );
		$s = CMB_Services::get_service( $service_id );

		if ( ! $b || ! $s || (int) $s->branch_id !== (int) $b->id ) {
			return new WP_Error( 'cmb_sim_bad', 'این خدمت مال این مرکز نیست.', array( 'status' => 400 ) );
		}

		$reason = '';
		$out['branch'] = array(
			'id'       => (int) $b->id,
			'title'    => (string) $b->title,
			'status'   => CMB_Branches::status( $b ),
			'bookable' => CMB_Branches::bookable( $b, $reason ),
			'reason'   => $reason,
			'blocks'   => array_values( cmb_blocks( (int) $b->id ) ),
		);
		$out['service'] = array(
			'id'       => (int) $s->id,
			'title'    => (string) $s->title,
			'active'   => (int) $s->is_active,
			'weekdays' => CMB_Services::allowed_weekdays( $s ),
			'deposit'  => CMB_Payments::service_deposit( $s ),
			'own'      => CMB_Services::own_capacity( $s ),
		);

		CMB_Availability::flush_cache();
		$cal = CMB_Availability::get_calendar( (int) $s->id, (int) $b->id );

		if ( is_wp_error( $cal ) ) {
			$out['error'] = $cal->get_error_message();
			return $out;
		}

		$days     = array();
		$mismatch = 0;

		foreach ( $cal['days'] as $d ) {
			$row = array(
				'date'    => $d['date'],
				'label'   => cmb_fa_num( $d['jalaliFull'] ),
				'reason'  => $d['reason'],
				'blocks'  => array(),
			);

			foreach ( $d['blocks'] as $blk ) {
				$check = CMB_Availability::validate_slot( $s, $d['date'], $blk['key'], (int) $b->id );
				$agree = ( true === $check ) === (bool) $blk['available'];

				if ( ! $agree ) {
					$mismatch++;
				}

				$row['blocks'][] = array(
					'key'       => $blk['key'],
					'label'     => $blk['label'],
					'start'     => $blk['startFa'],
					'capacity'  => (int) $blk['capacity'],
					'booked'    => CMB_Availability::booked_for( $s, (int) $b->id, $d['date'], $blk['key'] ),
					'remaining' => (int) $blk['remaining'],
					'available' => (bool) $blk['available'],
					'reason'    => $blk['reason'],
					'check'     => true === $check ? 'ok' : $check->get_error_code(),
					'checkMsg'  => true === $check ? '' : $check->get_error_message(),
					'agree'     => $agree,
				);
			}

			$days[] = $row;
		}

		$out['days']     = $days;
		$out['mismatch'] = $mismatch;

		$phone = cmb_normalize_phone( cmb_en_num( $phone ) );

		if ( $phone ) {
			$out['customer'] = self::customer_state( $phone, $s );
		}

		return $out;
	}

	/**
	 * یک مشتری مشخص: حساب، نوبت‌های فعال، سقف‌ها و کیف پول.
	 */
	public static function customer_state( $phone, $service = null ) {
		$user = CMB_OTP::find_user( $phone );
		$uid  = $user instanceof WP_User ? (int) $user->ID : (int) $user;
		$out = array(
			'phone'  => $phone,
			'exists' => $uid > 0,
			'name'   => $uid ? (string) get_userdata( $uid )->display_name : '',
			'active' => array(),
			'limit'  => '',
			'wallet' => CMB_Wallet::ready() ? CMB_Wallet::balance( $phone ) : null,
		);

		if ( ! $uid ) {
			$out['limit'] = 'حسابی با این شماره نیست؛ اولین ورود با کد پیامکی حساب را می‌سازد.';
			return $out;
		}

		foreach ( CMB_Bookings::active_bookings_for_user( $uid ) as $r ) {
			$svc = CMB_Services::get_service( (int) $r->service_id );
			$br  = CMB_Branches::get( (int) $r->branch_id );

			$out['active'][] = array(
				'code'    => (string) $r->tracking_code,
				'date'    => cmb_fa_num( cmb_jalali_date( (string) $r->booking_date, 'full' ) ),
				'block'   => cmb_block_label( $r->block_key ),
				'service' => $svc ? (string) $svc->title : '',
				'branch'  => $br ? (string) $br->title : '',
				'status'  => cmb_status_label( (string) $r->status ),
			);
		}

		if ( $service ) {
			$limit        = CMB_Bookings::check_active_limits( $uid, $service );
			$out['limit'] = true === $limit ? 'می‌تواند این خدمت را رزرو کند.' : $limit->get_error_message();
			$out['limitOk'] = true === $limit;
		}

		return $out;
	}

	/**
	 * «آزمون ثبت» یک شیفت مشخص، داخل تراکنشی که برگردانده می‌شود.
	 */
	public static function rest_try( WP_REST_Request $request ) {
		$out = CMB_Selftest::dry_run(
			(int) $request->get_param( 'service_id' ),
			sanitize_text_field( (string) $request->get_param( 'date' ) ),
			sanitize_key( (string) $request->get_param( 'block' ) ),
			(string) cmb_normalize_phone( cmb_en_num( (string) $request->get_param( 'phone' ) ) )
		);

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	public static function rest_selftest( WP_REST_Request $request ) {
		$out = CMB_Selftest::run();

		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/* ------------------------------------------------------------------
	 * اطلاعات سیستم
	 * --------------------------------------------------------------- */

	public static function rest_info( WP_REST_Request $request ) {
		return rest_ensure_response( self::info() );
	}

	public static function info() {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$plugins = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			$plugins[] = isset( $all[ $p ] ) ? $all[ $p ]['Name'] . ' ' . $all[ $p ]['Version'] : $p;
		}

		$theme = wp_get_theme();
		$merch = (string) CMB_Payments::merchant();
		$s     = CMB_Settings::all();
		$rows  = array(
			array( 'افزونه‌ی رزرو', CMB_VERSION . ' (دیتابیس ' . get_option( 'cmb_db_version', '?' ) . ')' ),
			array( 'وردپرس', get_bloginfo( 'version' ) . ( is_multisite() ? ' (چندسایته)' : '' ) ),
			array( 'PHP', PHP_VERSION . ' — حافظه ' . ini_get( 'memory_limit' ) . ' — زمان اجرا ' . ini_get( 'max_execution_time' ) . 's' ),
			array( 'دیتابیس', self::db_server() . ( cmb_tx_supported() ? '' : ' (SQLite)' ) ),
			array( 'وب‌سرور', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '?' ),
			array( 'نشانی سایت', home_url( '/' ) ),
			array( 'نشانی اپ / پنل', cmb_app_url() . ' — ' . cmb_app_url( 'panel' ) ),
			array( 'منطقه‌ی زمانی', cmb_timezone()->getName() . ' — اکنون ' . cmb_now()->format( 'Y-m-d H:i' ) ),
			array( 'قالب', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
			array( 'کش شیء', wp_using_ext_object_cache() ? 'بیرونی' : 'ندارد' ),
			array( 'WP_DEBUG', ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'روشن' : 'خاموش' ) . ' — نمایش خطا: ' . ( ini_get( 'display_errors' ) ? 'روشن' : 'خاموش' ) ),
			array( 'پرداخت', ( CMB_Payments::enabled() ? 'روشن' : 'خاموش' ) . ( CMB_Payments::sandbox() ? ' (آزمایشی)' : '' ) . ' — مرچنت ' . ( '' !== $merch ? substr( $merch, 0, 4 ) . '…' . substr( $merch, -4 ) : 'خالی' ) ),
			array( 'پیامک', ( CMB_SMS::is_enabled() ? 'روشن' : 'خاموش' ) . ' — حالت ' . $s['sms_api_mode'] ),
			array( 'بازه‌ی رزرو', sprintf( 'از %d روز بعد، %d روز، حداقل %d ساعت قبل؛ سقف نوبت فعال %d؛ یکی از هر خدمت: %s', (int) $s['min_days_ahead'], (int) $s['window_days'], (int) $s['min_hours_ahead'], (int) $s['max_active_per_user'], $s['one_per_service'] ? 'بله' : 'نه' ) ),
			array( 'استان و شهر', (int) $s['location_mode'] ? 'انتخاب از فهرست' : 'تایپ آزاد' ),
			array( 'گزارش خطا', ( CMB_Debug::on() ? 'روشن' : 'خاموش' ) . ' — حالت عیب‌یابی: ' . ( CMB_Debug::verbose() ? 'روشن' : 'خاموش' ) ),
		);

		$branch_lines = array();

		foreach ( CMB_Branches::all() as $b ) {
			$r = '';
			CMB_Branches::bookable( $b, $r );
			$branch_lines[] = sprintf( '%s [%s, %s%s] خدمات فعال: %d', $b->title, CMB_Branches::is_agency( $b ) ? 'نمایندگی' : 'مرکز', CMB_Branches::status( $b ), $r ? ', ' . $r : '', count( CMB_Services::get_services( (int) $b->id, true ) ) );
		}

		$rows[] = array( 'شعبه‌ها', implode( ' | ', $branch_lines ) );
		$rows[] = array( 'افزونه‌های فعال', implode( '، ', $plugins ) );

		$text = '';

		foreach ( $rows as $r ) {
			$text .= $r[0] . ': ' . $r[1] . "\n";
		}

		return array(
			'rows' => array_map(
				function ( $r ) {
					return array( 'label' => $r[0], 'value' => $r[1] );
				},
				$rows
			),
			'text' => $text,
		);
	}

	/* ------------------------------------------------------------------ */

	protected static function db_server() {
		global $wpdb;

		$v = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version();

		return ( false !== stripos( $v, 'mariadb' ) ? 'MariaDB ' : 'MySQL ' ) . preg_replace( '/^5\.5\.5-/', '', $v );
	}

	protected static function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
