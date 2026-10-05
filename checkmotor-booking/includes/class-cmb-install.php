<?php
/**
 * نصب، ساخت جداول و داده‌های اولیه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Install {

	const DB_VERSION = '1.7.0';

	public static function activate() {
		global $wpdb;

		// اگر خطایی رخ دهد، به‌جای صفحه‌ی سفید یک پیام فارسی قابل خواندن نشان می‌دهیم.
		try {
			self::register_roles();

			if ( class_exists( 'CMB_Operators' ) ) {
				CMB_Operators::register_role();
			}

			self::create_tables();

			// اگر ساخت جداول شکست خورد، همین‌جا با پیام روشن متوقف شو.
			$missing = self::missing_tables();

			if ( ! empty( $missing ) ) {
				$detail = $wpdb->last_error ? ' پیام دیتابیس: ' . $wpdb->last_error : '';

				wp_die(
					'<h1>افزونه فعال نشد</h1><p>ساخت جدول‌های زیر در دیتابیس ناموفق بود: <code>'
						. esc_html( implode( '</code>, <code>', $missing ) ) . '</code>.'
						. esc_html( $detail )
						. '</p><p>معمولاً یعنی کاربر دیتابیس اجازه‌ی <code>CREATE TABLE</code> ندارد. '
						. 'لطفاً با پشتیبانی هاست تماس بگیرید.</p>',
					'خطای ساخت جداول',
					array( 'back_link' => true )
				);
			}

			self::seed_defaults();
			// دیگر چیزی زمان‌بندی نمی‌شود؛ این فقط زمان‌بندی نسخه‌های
			// قبلی را پاک می‌کند تا کرون تکراری باقی نماند.
			CMB_Cron::clear_events();

			update_option( 'cmb_db_version', self::DB_VERSION );

			self::after_upgrade();

			// قواعد باید اول ثبت شوند، بعد flush — وگرنه مسیر اپ تا ذخیره‌ی
			// دستی «پیوندهای یکتا» خطای ۴۰۴ می‌دهد.
			if ( function_exists( 'cmb_register_rewrites' ) ) {
				cmb_register_rewrites();
			}

			delete_option( 'cmb_rewrite_stamp' );
			flush_rewrite_rules();

		} catch ( Throwable $e ) {
			self::activation_failed( $e );
		} catch ( Exception $e ) {
			self::activation_failed( $e );
		}
	}

	/**
	 * نمایش خطای فعال‌سازی به‌صورت قابل خواندن به‌جای Fatal Error خام.
	 *
	 * @param Throwable|Exception $e
	 */
	protected static function activation_failed( $e ) {
		wp_die(
			'<h1>افزونه فعال نشد</h1>'
				. '<p>هنگام فعال‌سازی خطای زیر رخ داد:</p>'
				. '<p><code>' . esc_html( $e->getMessage() ) . '</code></p>'
				. '<p><small>' . esc_html( $e->getFile() . ' : خط ' . $e->getLine() ) . '</small></p>',
			'خطای فعال‌سازی افزونه',
			array( 'back_link' => true )
		);
	}

	/**
	 * فهرست جدول‌هایی که ساخته نشده‌اند.
	 *
	 * @return string[]
	 */
	public static function missing_tables() {
		global $wpdb;

		$missing = array();

		foreach ( array( 'branches', 'services', 'bookings', 'closures', 'otp', 'payments', 'wallet' ) as $name ) {
			$table = cmb_table( $name );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore

			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	public static function deactivate() {
		CMB_Cron::clear_events();
		flush_rewrite_rules();
	}

	/**
	 * نقش «مشتری چک موتور» — بدون هیچ دسترسی مدیریتی.
	 */
	public static function register_roles() {
		if ( ! get_role( 'cmb_customer' ) ) {
			add_role( 'cmb_customer', 'مشتری چک موتور', array( 'read' => true ) );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'cmb_db_version' ) === self::DB_VERSION ) {
			return;
		}

		// مهاجرت فقط در پیشخوان/کرون اجرا می‌شود.
		// اجرای create_tables() روی درخواست‌های فرانت‌اند نیازمند بارگذاری
		// wp-admin/includes/upgrade.php است و روی بعضی میزبان‌ها باعث
		// تداخل و خطای مهلک در صفحات عمومی سایت می‌شد.
		if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// از اجرای هم‌زمان چند مهاجرت جلوگیری کن.
		if ( get_transient( 'cmb_upgrading' ) ) {
			return;
		}

		set_transient( 'cmb_upgrading', 1, 5 * MINUTE_IN_SECONDS );

		self::register_roles();
		self::create_tables();
		self::seed_defaults();

		if ( class_exists( 'CMB_Cron' ) ) {
			CMB_Cron::clear_events();
		}

		update_option( 'cmb_db_version', self::DB_VERSION );

		self::after_upgrade();

		delete_transient( 'cmb_upgrading' );
	}

	/**
	 * کارهای داده‌ای بعد از ساخت جدول‌ها (هر کدام فقط یک بار اثر دارد).
	 */
	protected static function after_upgrade() {
		global $wpdb;

		$payments = cmb_table( 'payments' );

		/* پرداخت‌های بی‌نوبتِ پیش از 1.6.0 همه آزمون‌های صفحه‌ی
		   راه‌اندازی بودند؛ حالا شارژ کیف پول هم بی‌نوبت است و نوعش را
		   خودش دارد. */
		if ( cmb_has_column( 'payments', 'kind' ) ) {
			$wpdb->query( "UPDATE {$payments} SET kind = 'selftest' WHERE booking_id = 0 AND kind = 'booking'" ); // phpcs:ignore
		}

		// برگشت‌های مانده در صف کارت ← کیف پول (یک بار، با پیامک)
		if ( class_exists( 'CMB_Wallet' ) ) {
			CMB_Wallet::migrate_legacy();
		}

		self::drop_card_refund();
	}

	/**
	 * 1.7.0: برگشت وجه از زرین‌پال کلاً حذف شد (همه‌ی برگشت‌ها به کیف
	 * پول). توکن دسترسی API استرداد، تنظیمات برگشت به کارت و نتیجه‌ی
	 * آزمون‌هایش دیگر به کاری نمی‌آیند و نگه داشته نمی‌شوند.
	 */
	protected static function drop_card_refund() {
		delete_option( 'cmb_zp_token' );
		delete_option( 'cmb_pay_selftest' );
		delete_transient( 'cmb_server_ip' );

		$old      = array_flip( array( 'pay_refund_mode', 'pay_refund_delay', 'zp_refund_method', 'zp_terminal_id', 'zp_reverse', 'pattern_refund' ) );
		$settings = get_option( 'cmb_settings' );

		if ( is_array( $settings ) && array_intersect_key( $settings, $old ) ) {
			update_option( 'cmb_settings', array_diff_key( $settings, $old ) );
		}

		$setup = get_option( 'cmb_pay_setup' );

		if ( is_array( $setup ) && array_diff_key( $setup, array( 'merchant' => 1 ) ) ) {
			update_option( 'cmb_pay_setup', array_intersect_key( $setup, array( 'merchant' => 1 ) ), false );
		}
	}

	/**
	 * ساخت/به‌روزرسانی جداول.
	 *
	 * ساختار از ابتدا چندشعبه‌ای طراحی شده تا افزودن شعبه‌ی بعدی
	 * بدون تغییر اسکیما ممکن باشد (بخش ۱۰ سند اسپک).
	 */
	public static function create_tables() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

			if ( file_exists( $upgrade_file ) ) {
				require_once $upgrade_file;
			}
		}

		// بدون dbDelta نمی‌توان جداول را ساخت؛ به‌جای Fatal Error خارج شو.
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset = $wpdb->get_charset_collate();

		$branches = cmb_table( 'branches' );
		$services = cmb_table( 'services' );
		$bookings = cmb_table( 'bookings' );
		$closures = cmb_table( 'closures' );
		$otp      = cmb_table( 'otp' );
		$payments = cmb_table( 'payments' );
		$wallet   = cmb_table( 'wallet' );

		$sql = array();

		$sql[] = "CREATE TABLE {$branches} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(60) NOT NULL,
			title VARCHAR(190) NOT NULL,
			phone VARCHAR(30) DEFAULT '' NOT NULL,
			address TEXT NULL,
			manager_phones VARCHAR(255) DEFAULT '' NOT NULL,
			is_active TINYINT(1) DEFAULT 1 NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		$sql[] = "CREATE TABLE {$services} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			slug VARCHAR(60) NOT NULL,
			title VARCHAR(190) NOT NULL,
			description TEXT NULL,
			poster_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			price BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			duration_note VARCHAR(190) DEFAULT '' NOT NULL,
			allowed_weekdays VARCHAR(30) DEFAULT '' NOT NULL,
			own_capacity VARCHAR(190) DEFAULT '' NOT NULL,
			deposit_amount INT UNSIGNED NULL DEFAULT NULL,
			cancel_refund_amount INT UNSIGNED NULL DEFAULT NULL,
			sort_order INT DEFAULT 0 NOT NULL,
			is_active TINYINT(1) DEFAULT 1 NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY branch_id (branch_id),
			KEY slug (slug)
		) {$charset};";

		$sql[] = "CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tracking_code VARCHAR(20) NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			service_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			booking_date DATE NOT NULL,
			block_key VARCHAR(20) NOT NULL,
			customer_name VARCHAR(190) NOT NULL,
			phone VARCHAR(20) NOT NULL,
			city VARCHAR(100) DEFAULT '' NOT NULL,
			car_brand VARCHAR(120) DEFAULT '' NOT NULL,
			car_model VARCHAR(120) DEFAULT '' NOT NULL,
			car_year VARCHAR(10) DEFAULT '' NOT NULL,
			car_mileage VARCHAR(20) DEFAULT '' NOT NULL,
			note TEXT NULL,
			status VARCHAR(20) DEFAULT 'confirmed' NOT NULL,
			cancelled_by VARCHAR(20) DEFAULT '' NOT NULL,
			cancelled_at DATETIME NULL,
			reminder_sent TINYINT(1) DEFAULT 0 NOT NULL,
			ip VARCHAR(45) DEFAULT '' NOT NULL,
			price_at_booking BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			deposit_amount INT UNSIGNED DEFAULT 0 NOT NULL,
			cancel_refund_amount INT UNSIGNED DEFAULT 0 NOT NULL,
			cancel_until DATETIME NULL,
			terms_accepted_at DATETIME NULL,
			terms_hash VARCHAR(64) DEFAULT '' NOT NULL,
			pay_status VARCHAR(20) DEFAULT '' NOT NULL,
			refund_amount INT UNSIGNED DEFAULT 0 NOT NULL,
			hold_until_gmt DATETIME NULL,
			paid_at DATETIME NULL,
			pay_token VARCHAR(64) DEFAULT '' NOT NULL,
			expire_reason VARCHAR(20) DEFAULT '' NOT NULL,
			wallet_used INT UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tracking_code (tracking_code),
			KEY slot (branch_id,booking_date,block_key,status),
			KEY user_id (user_id),
			KEY phone (phone),
			KEY hold (status,hold_until_gmt),
			KEY pay_status (pay_status)
		) {$charset};";

		/* پرداخت‌های بیعانه: هر تلاش پرداخت (هر authority زرین‌پال) یک
		   ردیف. مبلغ‌ها به ریال، همان واحدی که به درگاه فرستاده می‌شود.
		   برگشت وجه روی همان ردیف پرداخت ثبت می‌شود، چون زرین‌پال هم
		   برای هر تراکنش فقط یک برگشت می‌پذیرد. */
		$sql[] = "CREATE TABLE {$payments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			sandbox TINYINT(1) DEFAULT 0 NOT NULL,
			authority VARCHAR(64) NULL DEFAULT NULL,
			amount_rial BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) DEFAULT 'created' NOT NULL,
			gw_code INT DEFAULT 0 NOT NULL,
			gw_message VARCHAR(255) DEFAULT '' NOT NULL,
			ref_id VARCHAR(40) DEFAULT '' NOT NULL,
			card_pan VARCHAR(32) DEFAULT '' NOT NULL,
			card_hash VARCHAR(128) DEFAULT '' NOT NULL,
			fee_type VARCHAR(20) DEFAULT '' NOT NULL,
			fee BIGINT DEFAULT 0 NOT NULL,
			checks SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
			next_check_gmt DATETIME NULL,
			paid_at DATETIME NULL,
			refund_status VARCHAR(20) DEFAULT '' NOT NULL,
			refund_reason VARCHAR(30) DEFAULT '' NOT NULL,
			refund_amount_rial BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			refund_method VARCHAR(20) DEFAULT '' NOT NULL,
			refund_ref VARCHAR(80) DEFAULT '' NOT NULL,
			zp_session_id VARCHAR(40) DEFAULT '' NOT NULL,
			refund_error VARCHAR(255) DEFAULT '' NOT NULL,
			refund_by BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			refund_due_at DATETIME NULL,
			refund_done_at DATETIME NULL,
			refund_after_gmt DATETIME NULL,
			refund_tries SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
			kind VARCHAR(10) DEFAULT 'booking' NOT NULL,
			phone VARCHAR(20) DEFAULT '' NOT NULL,
			token VARCHAR(64) DEFAULT '' NOT NULL,
			raw TEXT NULL,
			ip VARCHAR(45) DEFAULT '' NOT NULL,
			created_at DATETIME NOT NULL,
			created_gmt DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY authority (authority),
			KEY booking_id (booking_id),
			KEY status_check (status,next_check_gmt),
			KEY refund_status (refund_status),
			KEY refund_after (refund_status,refund_after_gmt),
			KEY kind_phone (kind,phone)
		) {$charset};";

		/* کیف پول: دفتر کل، یک ردیف برای هر واریز و برداشت. مبلغ‌ها
		   تومان و علامت‌دار (مثبت واریز، منفی برداشت). کلید هر مشتری
		   شماره‌ی موبایل است، همان چیزی که با آن وارد می‌شود. موجودی =
		   جمع ردیف‌های done، به‌علاوه‌ی برداشت‌های held نوبتی که هنوز در
		   انتظار پرداخت است (CMB_Wallet::balance). */
		$sql[] = "CREATE TABLE {$wallet} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone VARCHAR(20) NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			amount BIGINT NOT NULL,
			type VARCHAR(10) NOT NULL,
			status VARCHAR(10) DEFAULT 'done' NOT NULL,
			reason VARCHAR(30) DEFAULT '' NOT NULL,
			booking_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			payment_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			note VARCHAR(190) DEFAULT '' NOT NULL,
			by_user BIGINT UNSIGNED DEFAULT 0 NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_status (phone,status),
			KEY booking_id (booking_id),
			KEY payment_id (payment_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$closures} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			branch_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			closure_date DATE NOT NULL,
			block_key VARCHAR(20) DEFAULT '' NOT NULL,
			reason VARCHAR(190) DEFAULT '' NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_closure (branch_id,closure_date,block_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$otp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone VARCHAR(20) NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			purpose VARCHAR(30) DEFAULT 'login' NOT NULL,
			attempts TINYINT UNSIGNED DEFAULT 0 NOT NULL,
			is_used TINYINT(1) DEFAULT 0 NOT NULL,
			ip VARCHAR(45) DEFAULT '' NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY expires_at (expires_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		/* پاسخ‌های کش‌شده‌ی «آیا این ستون هست؟» بعد از مهاجرت کهنه‌اند. */
		delete_transient( 'cmb_has_cancel_cols' );
		delete_transient( 'cmb_has_owncap_col' );
		delete_transient( 'cmb_col_bookings_city' );

		foreach ( array( 'deposit_amount', 'pay_status', 'hold_until_gmt', 'wallet_used' ) as $col ) {
			delete_transient( 'cmb_col_bookings_' . $col );
			delete_transient( 'cmb_col_services_' . $col );
		}

		delete_transient( 'cmb_col_payments_kind' );
	}

	/**
	 * داده‌های اولیه: شعبه‌ی بوشهر و دو خدمت پیش‌فرض.
	 */
	public static function seed_defaults() {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$branches = cmb_table( 'branches' );
		$services = cmb_table( 'services' );

		$branch_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$branches} WHERE slug = %s", 'bushehr' ) ); // phpcs:ignore

		if ( ! $branch_id ) {
			$wpdb->insert(
				$branches,
				array(
					'slug'           => 'bushehr',
					'title'          => 'شعبه بوشهر',
					'phone'          => '',
					'address'        => '',
					'manager_phones' => '',
					'is_active'      => 1,
					'created_at'     => $now,
				)
			);
			$branch_id = (int) $wpdb->insert_id;
		}

		update_option( 'cmb_default_branch_id', $branch_id );

		$defaults = array(
			array(
				'slug'             => 'tanzim-motor',
				'title'            => 'تنظیم موتور',
				'description'      => 'سرویس سوزن انژکتور، سرویس دریچه گاز، سرویس سنسور فشار، برنامه‌ریزی دیاگ',
				'price'            => 2500000,
				'duration_note'    => '۱ تا ۱.۵ ساعت (حداکثر ۲ ساعت)',
				// سه‌شنبه (2) و چهارشنبه (3) بر اساس شماره‌گذاری هفته در PHP.
				'allowed_weekdays' => '2,3',
				'sort_order'       => 10,
			),
			array(
				'slug'             => 'taghviat-motor',
				'title'            => 'تقویت موتور',
				'description'      => 'شمع، وایر شمع، ریمپ، اجرت تعویض — ۱ ماه ضمانت',
				'price'            => 9800000,
				'duration_note'    => 'حداقل ۱ تا حداکثر ۲ ساعت؛ معمولاً یک جلسه',
				// شنبه (6)، یکشنبه (0)، دوشنبه (1)، سه‌شنبه (2)، چهارشنبه (3).
				'allowed_weekdays' => '6,0,1,2,3',
				'sort_order'       => 20,
			),
		);

		foreach ( $defaults as $service ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s AND branch_id = %d", $service['slug'], $branch_id ) ); // phpcs:ignore

			if ( $exists ) {
				continue;
			}

			$service['branch_id']  = $branch_id;
			$service['is_active']  = 1;
			$service['poster_id']  = 0;
			$service['created_at'] = $now;

			$wpdb->insert( $services, $service );
		}

		CMB_Settings::install_defaults();
		self::create_pages();
	}

	/**
	 * ساخت خودکار صفحه‌ی رزرو نوبت و صفحه‌ی نوبت‌های من.
	 */
	public static function create_pages() {
		/* اسلاگ این برگه عمداً «reserve» نیست: قاعده‌ی بازنویسی اپ
		   دقیقاً روی /reserve/ می‌نشیند و با اولویت top برنده می‌شود،
		   پس برگه‌ای با همان اسلاگ هرگز دیده نمی‌شد و پنل هم درست
		   درباره‌اش هشدار می‌داد — هشداری که خودِ افزونه می‌ساخت.
		   این برگه فقط نگه‌دارنده‌ی شورت‌کد برای حالت بدون کنواس است
		   و شناساییِ آن به محتوایش است، نه به اسلاگش. */
		$pages = array(
			'cmb_page_booking' => array(
				'title'     => 'رزرو نوبت',
				'content'   => '[checkmotor_booking]',
				'post_name' => 'reserve-form',
			),
			'cmb_page_my'      => array(
				'title'     => 'نوبت‌های من',
				'content'   => '[checkmotor_my_bookings]',
				'post_name' => 'my-bookings',
			),
		);

		foreach ( $pages as $option => $data ) {
			$existing = (int) get_option( $option );

			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $data['title'],
					'post_name'    => $data['post_name'],
					'post_content' => $data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( $option, (int) $page_id );
			}
		}
	}
}
