<?php
/**
 * بررسی سلامت سیستم.
 *
 * سایت مدت‌هاست در حال استفاده است و داده‌هایش از نسخه‌های مختلف
 * افزونه باقی مانده. این کلاس همان چیزهایی را بررسی می‌کند که
 * به‌روزرسانی‌ها نمی‌توانند خودکار درست کنند یا لازم است کسی از
 * درست بودنشان مطمئن شود: مهاجرت اجرا شده؟ خدمتی هست که هیچ‌وقت
 * قابل رزرو نیست؟ داده‌ی قدیمی جایی بی‌صاحب مانده؟
 *
 * هیچ بررسی‌ای چیزی را تغییر نمی‌دهد. اصلاح فقط با درخواست صریح و
 * از مسیر جداگانه انجام می‌شود.
 *
 * @package CheckMotor_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Health {

	const OK   = 'ok';
	const WARN = 'warn';
	const BAD  = 'bad';

	/**
	 * همه‌ی بررسی‌ها.
	 *
	 * @return array
	 */
	public static function run() {
		$checks = array_merge(
			self::check_schema(),
			self::check_schedule(),
			self::check_timezone(),
			self::check_payments(),
			self::check_services(),
			self::check_stale_bookings(),
			self::check_orphans(),
			self::check_car_years(),
			self::check_legacy_limits(),
			self::check_sms(),
			self::check_cron()
		);

		$worst = self::OK;

		foreach ( $checks as $c ) {
			if ( self::BAD === $c['level'] ) {
				$worst = self::BAD;
				break;
			}

			if ( self::WARN === $c['level'] ) {
				$worst = self::WARN;
			}
		}

		return array(
			'level'  => $worst,
			'checks' => $checks,
		);
	}

	protected static function item( $level, $title, $detail, $fix = '' ) {
		return array(
			'level'  => $level,
			'title'  => $title,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/* ------------------------------------------------------------------
	 * بررسی‌ها
	 * --------------------------------------------------------------- */

	/**
	 * مهاجرت جدول‌ها.
	 *
	 * مهاجرت فقط در پیشخوان اجرا می‌شود. اگر مدیر بعد از به‌روزرسانی
	 * سری به پیشخوان نزده باشد، ستون‌های تازه ساخته نشده‌اند و
	 * قابلیت‌هایشان بی‌صدا کار نمی‌کنند.
	 */
	protected static function check_schema() {
		$stored  = (string) get_option( 'cmb_db_version', '' );
		$current = CMB_Install::DB_VERSION;

		if ( $stored === $current ) {
			return array( self::item( self::OK, 'ساختار دیتابیس', 'به‌روز است (نسخه ' . $current . ').' ) );
		}

		return array(
			self::item(
				self::BAD,
				'ساختار دیتابیس',
				sprintf(
					'نسخه‌ی ذخیره‌شده %s است ولی افزونه %s می‌خواهد. مهاجرت هنوز اجرا نشده، پس ستون‌های تازه وجود ندارند: ردیابی «چه کسی لغو کرد» و «سهمیه‌ی جداگانه‌ی خدمات» ذخیره نمی‌شوند.',
					$stored ? $stored : 'نامشخص',
					$current
				),
				'برای اجرای مهاجرت، یک بار وارد پیشخوان وردپرس شوید (هر صفحه‌ای). اگر دسترسی مدیر کل ندارید، از مدیر سایت بخواهید.'
			),
		);
	}

	/**
	 * ظرفیت و ساعت شیفت‌ها.
	 */
	protected static function check_schedule() {
		$out    = array();
		$blocks = cmb_blocks();
		$total  = 0;

		foreach ( $blocks as $b ) {
			$total += (int) $b['capacity'];
		}

		if ( 0 === $total ) {
			$out[] = self::item(
				self::BAD,
				'ظرفیت شیفت‌ها',
				'ظرفیت هر دو شیفت صفر است، پس هیچ خدمتی که از ظرفیت اصلی استفاده می‌کند قابل رزرو نیست.',
				'در «تنظیمات شیفت» ظرفیت هر شیفت را تعیین کنید.'
			);
		} else {
			$out[] = self::item( self::OK, 'ظرفیت شیفت‌ها', 'تعیین شده است.' );
		}

		$window = (int) CMB_Settings::get( 'window_days', 7 );
		$min    = (int) CMB_Settings::get( 'min_days_ahead', 1 );

		if ( $window < 1 ) {
			$out[] = self::item(
				self::BAD,
				'بازه‌ی رزرو',
				'تعداد روزهای قابل رزرو صفر است، پس تقویم مشتری خالی می‌ماند.',
				'در «تنظیمات شیفت» این عدد را دست‌کم ۱ بگذارید.'
			);
		} elseif ( $min >= $window + $min ) {
			$out[] = self::item( self::WARN, 'بازه‌ی رزرو', 'ترکیب حداقل فاصله و طول بازه، روز قابل رزروی باقی نمی‌گذارد.' );
		}

		return $out;
	}

	/**
	 * منطقه‌ی زمانی سایت.
	 *
	 * ساعت شروع شیفت‌ها، «حداقل ۲۴ ساعت تا نوبت»، مهلت لغو و یادآوری‌ها
	 * همه با ساعت سایت حساب می‌شوند. ایران از ۱۴۰۱ ساعت تابستانی ندارد و
	 * اختلافش با UTC همیشه ۳:۳۰ است؛ ولی داده‌ی منطقه‌ی زمانیِ PHP روی
	 * سرورهای به‌روزنشده هنوز «Asia/Tehran» را در تابستان ۴:۳۰ حساب
	 * می‌کند و همه‌چیز یک ساعت جابه‌جا می‌شود.
	 */
	protected static function check_timezone() {
		$tz     = cmb_timezone();
		$name   = $tz->getName();
		$year   = (int) cmb_now()->format( 'Y' );
		$summer = $tz->getOffset( new DateTime( $year . '-07-01 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$winter = $tz->getOffset( new DateTime( $year . '-01-01 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$iran   = 12600; // ۳:۳۰ ساعت
		$fix    = 'در «تنظیمات ← عمومی» وردپرس، منطقه‌ی زمانی را «UTC+3:30» بگذارید. این گزینه به داده‌ی منطقه‌ی زمانی سرور وابسته نیست.';
		$title  = 'منطقه‌ی زمانی سایت';

		if ( $iran === $summer && $iran === $winter ) {
			return array( self::item( self::OK, $title, sprintf( 'ساعت سایت با ساعت ایران یکی است (%s).', $name ) ) );
		}

		if ( $iran === $winter && $summer !== $iran ) {
			return array(
				self::item(
					self::BAD,
					$title,
					'سرور هنوز برای ایران ساعت تابستانی حساب می‌کند (ایران از ۱۴۰۱ ساعت تابستانی ندارد). در نیمه‌ی اول سال ساعت شیفت‌ها، مهلت لغو و قانون ۲۴ ساعت یک ساعت جابه‌جا حساب می‌شوند.',
					$fix
				),
			);
		}

		return array(
			self::item(
				self::WARN,
				$title,
				sprintf( 'منطقه‌ی زمانی سایت «%s» است، نه ایران؛ ساعت شیفت‌ها، مهلت لغو و قانون ۲۴ ساعت با این ساعت حساب می‌شوند.', $name ),
				$fix
			),
		);
	}

	/**
	 * پرداخت بیعانه: پیکربندی درگاه، پرداخت‌های بی‌جواب، کیف پول، و پولی
	 * که آمده ولی نه نوبتی گرفته و نه به کیف پول برگشته است.
	 */
	protected static function check_payments() {
		global $wpdb;

		if ( ! CMB_Payments::in_use() ) {
			return array();
		}

		$out   = array();
		$title = 'پرداخت بیعانه';

		if ( CMB_Settings::get( 'pay_enabled', 0 ) ) {
			if ( '' === CMB_Payments::merchant() ) {
				$out[] = self::item( self::BAD, $title, 'پرداخت روشن است ولی مرچنت کد زرین‌پال وارد نشده؛ رزرو خدمت‌های بیعانه‌دار ممکن نیست.', 'در تنظیمات افزونه، بخش «پرداخت بیعانه»، مرچنت کد را وارد کنید.' );
			} elseif ( CMB_Payments::sandbox() ) {
				$out[] = self::item( self::WARN, $title, 'درگاه در حالت آزمایشی (sandbox) است؛ پرداخت‌ها واقعی نیستند و نوبت‌ها بدون پول واقعی ثبت می‌شوند.', 'بعد از آزمایش، «درگاه آزمایشی» را در تنظیمات خاموش کنید.' );
			} elseif ( 0 !== strpos( home_url( '/' ), 'https://' ) ) {
				$out[] = self::item( self::BAD, $title, 'نشانی سایت HTTPS نیست و زرین‌پال بازگشت به آن را نمی‌پذیرد.', 'گواهی SSL را فعال کنید.' );
			} else {
				$out[] = self::item( self::OK, $title, 'روشن است و درگاه تنظیم شده.' );
			}
		}

		$pt    = cmb_table( 'payments' );
		$bt    = cmb_table( 'bookings' );
		$hour  = gmdate( 'Y-m-d H:i:s', cmb_now()->getTimestamp() - HOUR_IN_SECONDS );
		$stuck = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pt} WHERE status = 'requested' AND next_check_gmt IS NOT NULL AND next_check_gmt < %s", $hour ) ); // phpcs:ignore

		if ( $stuck ) {
			$out[] = self::item(
				self::WARN,
				'بررسی پرداخت‌ها',
				sprintf( '%s پرداخت بیش از یک ساعت است بی‌جواب مانده؛ یعنی بررسی خودکار اجرا نمی‌شود (سایت بازدید ندارد یا کرون غیرفعال است).', cmb_fa_num( $stuck ) ),
				'نشانی کرون پرداخت (تنظیمات ← پرداخت بیعانه) را در کرون‌جاب هاست هر ۵ دقیقه صدا بزنید.'
			);
		}

		if ( CMB_Wallet::ready() ) {
			$out = array_merge( $out, self::check_wallet() );
		}

		$bad_svc = array();

		foreach ( CMB_Pay_Review::services()['items'] as $row ) {
			if ( 'bad' === $row['level'] && $row['active'] ) {
				$bad_svc[] = $row['title'];
			}
		}

		if ( $bad_svc ) {
			$out[] = self::item( self::BAD, 'مبلغ‌های بیعانه', 'مبلغ بیعانه یا برگشتیِ این خدمت‌ها با قواعد زرین‌پال جور نیست: ' . implode( '، ', $bad_svc ) . '.', 'پنل رزرو ← بیعانه و کیف پول ← بررسی خدمات.' );
		}

		// شارژ کیف پول نوبتی ندارد و قرار هم نیست داشته باشد
		$not_topup = CMB_Wallet::schema_ready() ? " AND p.kind <> 'topup'" : '';

		$orphan = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$pt} p LEFT JOIN {$bt} b ON b.id = p.booking_id
			 WHERE p.status = 'paid' AND p.refund_status = '' AND p.refund_reason = ''{$not_topup}
			   AND ( b.id IS NULL OR b.status NOT IN ('confirmed','done','no_show','cancelled') )"
		);

		if ( $orphan ) {
			$out[] = self::item(
				self::BAD,
				'پرداخت بی‌نوبت',
				sprintf( '%s پرداخت موفق هست که نه نوبتی برایش ثبت شده و نه به کیف پول برگشته است.', cmb_fa_num( $orphan ) ),
				'با پشتیبانی افزونه تماس بگیرید؛ این حالت نباید پیش بیاید.'
			);
		}

		return $out;
	}

	/**
	 * کیف پول: موجودی منفی، مبلغِ کنار گذاشته‌ی بی‌صاحب، برگشت‌های مانده،
	 * قوانینی که هنوز از کارت می‌گویند، و پترن پیامک.
	 */
	protected static function check_wallet() {
		global $wpdb;

		$out    = array();
		$title  = 'کیف پول';
		$totals = CMB_Wallet::totals();

		if ( $totals['negative'] ) {
			$out[] = self::item( self::BAD, $title, sprintf( 'موجودی %s کیف پول منفی است؛ این حالت نباید پیش بیاید.', cmb_fa_num( $totals['negative'] ) ), 'پنل رزرو ← بیعانه و کیف پول: تاریخچه‌ی آن مشتری را ببینید و با «تغییر دستی» درستش کنید، و به پشتیبانی افزونه خبر دهید.' );
		} else {
			$out[] = self::item( self::OK, $title, sprintf( 'برگشت‌ها به کیف پول مشتری می‌رود. جمع موجودی %s کیف پول: %s.', cmb_fa_num( $totals['wallets'] ), cmb_toman( $totals['liability'] ) ) );
		}

		$wt    = cmb_table( 'wallet' );
		$bt    = cmb_table( 'bookings' );
		$stale = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wt} WHERE status = 'held' AND booking_id NOT IN ( SELECT id FROM {$bt} WHERE status = 'pending' AND hold_until_gmt > %s )", cmb_now_gmt() ) ); // phpcs:ignore

		if ( $stale ) {
			$out[] = self::item( self::WARN, 'مبلغ کنار گذاشته‌ی کیف پول', sprintf( '%s مبلغ برای نوبت‌هایی کنار گذاشته مانده که دیگر در انتظار پرداخت نیستند. در موجودی مشتری حساب نمی‌شوند و با بررسی دوره‌ای بعدی آزاد می‌شوند.', cmb_fa_num( $stale ) ), 'اگر ماند، نشانی کرون پرداخت را در کرون‌جاب هاست تنظیم کنید.' );
		}

		$legacy = CMB_Payments::count_open_refunds();

		if ( $legacy ) {
			$out[] = self::item( self::WARN, 'برگشت‌های مانده', sprintf( '%s برگشت هنوز به کیف پول نرفته و تعیین تکلیف نشده (از پیش از کیف پول مانده، یا صاحب پرداخت معلوم نبود).', cmb_fa_num( $legacy ) ), 'پنل رزرو ← بیعانه و کیف پول ← برگشت‌های مانده: «انتقال به کیف پول»، یا اگر پول قبلاً برگشته «ثبت انجام‌شده».' );
		}

		if ( CMB_Payments::terms_mention_card() ) {
			$out[] = self::item( self::WARN, 'متن قوانین رزرو', 'متن قوانینی که خودتان نوشته‌اید هنوز از برگشت پول به کارت می‌گوید، ولی برگشت‌ها حالا به کیف پول می‌رود.', 'تنظیمات افزونه ← پرداخت بیعانه ← متن قوانین را خالی کنید تا متن تازه‌ی پیش‌فرض (با کیف پول) استفاده شود، یا خودتان اصلاحش کنید.' );
		}

		if ( '' === trim( (string) CMB_Settings::get( 'pattern_wallet', '' ) ) && 'simple' !== CMB_Settings::get( 'sms_api_mode', 'pattern' ) ) {
			$out[] = self::item( self::WARN, 'پیامک کیف پول', 'پترن «تغییر کیف پول» تنظیم نشده؛ مشتری وقتی پولی به کیف پولش برمی‌گردد پیامک نمی‌گیرد.', 'در ملی‌پیامک پترنی با متن «{0} عزیز، {1} تومان {3}. موجودی کیف پول شما: {2} تومان» بسازید و شناسه‌اش را در تنظیمات افزونه ← پیامک بگذارید.' );
		}

		return $out;
	}

	/**
	 * خدمتی که فعال است ولی عملاً قابل رزرو نیست.
	 */
	protected static function check_services() {
		$broken  = array();
		$active  = 0;
		$blocks  = cmb_blocks();

		foreach ( CMB_Services::get_services( 0, false ) as $service ) {
			if ( ! $service->is_active ) {
				continue;
			}

			$active++;

			if ( ! CMB_Services::allowed_weekdays( $service ) ) {
				$broken[] = $service->title . ' (هیچ روزی از هفته انتخاب نشده)';
				continue;
			}

			$own = CMB_Services::own_capacity( $service );

			if ( null !== $own && 0 === array_sum( $own ) ) {
				$broken[] = $service->title . ' (سهمیه‌ی جداگانه در همه‌ی شیفت‌ها صفر است)';
			}
		}

		if ( ! $active ) {
			return array(
				self::item(
					self::BAD,
					'خدمات',
					'هیچ خدمت فعالی وجود ندارد، پس فرم رزرو چیزی برای انتخاب ندارد.',
					'در بخش «خدمات» دست‌کم یک خدمت را فعال کنید.'
				),
			);
		}

		if ( $broken ) {
			return array(
				self::item(
					self::WARN,
					'خدمات',
					'این خدمت‌ها فعال‌اند ولی هیچ‌وقت قابل رزرو نمی‌شوند: ' . implode( '، ', $broken ) . '.',
					'یا تنظیماتشان را کامل کنید یا غیرفعالشان کنید تا در فرم دیده نشوند.'
				),
			);
		}

		return array( self::item( self::OK, 'خدمات', cmb_fa_num( $active ) . ' خدمت فعال، همه قابل رزرو.' ) );
	}

	/**
	 * نوبت‌های گذشته‌ای که هنوز «تایید شده» مانده‌اند.
	 *
	 * جاروی خودکار روزی یک بار اجرا می‌شود و به بازدید از سایت وابسته
	 * است؛ روی سایت کم‌بازدید ممکن است عقب بیفتد.
	 */
	protected static function check_stale_bookings() {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND booking_date < %s", // phpcs:ignore
				cmb_today()
			)
		);

		if ( ! $count ) {
			return array( self::item( self::OK, 'نوبت‌های گذشته', 'همه‌ی نوبت‌های گذشته تعیین وضعیت شده‌اند.' ) );
		}

		return array(
			self::item(
				self::WARN,
				'نوبت‌های گذشته',
				sprintf( '%s نوبت مربوط به روزهای گذشته هنوز «تایید شده» مانده و تعیین وضعیت نشده.', cmb_fa_num( $count ) ),
				'دکمه‌ی «تعیین وضعیت نوبت‌های گذشته» آن‌ها را «انجام شده» می‌کند. اگر کسی مراجعه نکرده، وضعیتش را دستی روی «غیبت» بگذارید.'
			),
		);
	}

	/**
	 * نوبت‌هایی که خدمتشان دیگر وجود ندارد.
	 */
	protected static function check_orphans() {
		global $wpdb;

		$bookings = cmb_table( 'bookings' );
		$services = cmb_table( 'services' );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$bookings} b LEFT JOIN {$services} s ON s.id = b.service_id WHERE s.id IS NULL" // phpcs:ignore
		);

		if ( ! $count ) {
			return array( self::item( self::OK, 'پیوند نوبت و خدمت', 'همه‌ی نوبت‌ها به خدمت موجود وصل‌اند.' ) );
		}

		return array(
			self::item(
				self::WARN,
				'پیوند نوبت و خدمت',
				sprintf( '%s نوبت به خدمتی اشاره می‌کنند که دیگر وجود ندارد و عنوان خدمتشان خالی نمایش داده می‌شود.', cmb_fa_num( $count ) ),
				'این نوبت‌ها سالم‌اند و فقط عنوان خدمت را از دست داده‌اند. برای جلوگیری از تکرار، به‌جای حذف خدمت آن را غیرفعال کنید.'
			),
		);
	}

	/**
	 * سال ساختِ خودروهای واقعی در برابر فهرست کشویی.
	 *
	 * فهرست از ۱۳۹۵ شروع می‌شود. اگر بخش زیادی از مشتری‌های موجود
	 * خودروی قدیمی‌تر داشته باشند، یعنی همان‌ها دیگر نمی‌توانند نوبت
	 * بگیرند — چیزی که تا وقتی کسی شکایت نکند دیده نمی‌شود.
	 */
	protected static function check_car_years() {
		global $wpdb;

		$years = cmb_car_years();

		if ( ! $years ) {
			return array();
		}

		$table    = cmb_table( 'bookings' );
		$holders  = implode( ',', array_fill( 0, count( $years ), '%s' ) );

		$row = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, MIN(car_year) AS oldest
				 FROM {$table}
				 WHERE car_year <> '' AND car_year NOT IN ({$holders})",
				$years
			)
		);

		$count = $row ? (int) $row->total : 0;

		if ( ! $count ) {
			return array( self::item( self::OK, 'سال ساخت خودروها', 'سال ساخت همه‌ی نوبت‌های ثبت‌شده در بازه‌ی فهرست کشویی است.' ) );
		}

		return array(
			self::item(
				self::WARN,
				'سال ساخت خودروها',
				sprintf(
					'%s نوبت با سال ساختی بیرون از فهرست کشویی ثبت شده‌اند (قدیمی‌ترین: %s). این مشتری‌ها دیگر نمی‌توانند خودشان نوبت بگیرند، چون فهرست از %s شروع می‌شود.',
					cmb_fa_num( $count ),
					cmb_fa_num( (string) $row->oldest ),
					cmb_fa_num( end( $years ) )
				),
				'اگر چنین خودروهایی واقعاً مشتری شما هستند، کف فهرست را در functions.php قالب پایین بیاورید: add_filter( \'cmb_car_year_min\', function () { return 1385; } );'
			),
		);
	}

	/**
	 * نوبت‌هایی که پیش از قاعده‌های تازه ثبت شده و با آن‌ها نمی‌خوانند.
	 *
	 * قاعده‌ی «یک نوبت از هر خدمت» و سقف نوبت فعال فقط موقع ثبت نوبت
	 * تازه بررسی می‌شوند؛ هیچ نوبت موجودی لغو یا تغییر نمی‌کند. پس
	 * مشتری‌ای که پیش از به‌روزرسانی دو تنظیم موتور گرفته، هر دو را
	 * نگه می‌دارد. این‌ها معتبرند و انجام می‌شوند — این بررسی فقط
	 * نشانشان می‌دهد تا اگر خواستید تماس بگیرید.
	 *
	 * چنین نوبت‌هایی فقط وقتی ممکن است که سقف پیش‌تر ۲ یا بیشتر (یا
	 * بدون سقف) بوده. با سقف پیش‌فرض ۱ اصلاً به وجود نمی‌آیند.
	 */
	protected static function check_legacy_limits() {
		global $wpdb;

		$bookings = cmb_table( 'bookings' );
		$services = cmb_table( 'services' );
		$today    = cmb_today();
		$out      = array();

		if ( CMB_Settings::get( 'one_per_service', 1 ) ) {
			$dups = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					/* زیرکوئری به‌جای HAVING: نتیجه یکی است، ولی مترجم بعضی
					   دیتابیس‌ها (افزونه‌ی SQLite وردپرس) HAVING را خراب می‌کند. */
					"SELECT * FROM (
						SELECT b.phone, MAX(b.customer_name) AS name, s.title, COUNT(*) AS c
						FROM {$bookings} b
						LEFT JOIN {$services} s ON s.id = b.service_id
						WHERE b.status = 'confirmed' AND b.booking_date >= %s
						GROUP BY b.phone, b.service_id, s.title
					 ) t WHERE t.c > 1
					 ORDER BY t.c DESC
					 LIMIT 20",
					$today
				)
			);

			if ( $dups ) {
				$out[] = self::item(
					self::WARN,
					'نوبت تکراری از یک خدمت',
					sprintf(
						'%s مشتری پیش از فعال شدن قاعده‌ی «یک نوبت از هر خدمت» بیش از یک نوبت از یک خدمت گرفته‌اند: %s.',
						cmb_fa_num( count( $dups ) ),
						self::people( $dups, true )
					),
					'این نوبت‌ها معتبرند و لغو نمی‌شوند؛ قاعده فقط جلوی ثبت تکراری تازه را می‌گیرد. اگر یکی از آن‌ها اضافی است، با مشتری تماس بگیرید و از پنل لغوش کنید.'
				);
			} else {
				$out[] = self::item( self::OK, 'نوبت تکراری از یک خدمت', 'هیچ مشتری‌ای دو نوبت فعال از یک خدمت ندارد.' );
			}
		}

		$max = (int) CMB_Settings::get( 'max_active_per_user', 1 );

		if ( $max > 0 ) {
			$over = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT * FROM (
						SELECT phone, MAX(customer_name) AS name, COUNT(*) AS c
						FROM {$bookings}
						WHERE status = 'confirmed' AND booking_date >= %s
						GROUP BY phone
					 ) t WHERE t.c > %d
					 ORDER BY t.c DESC
					 LIMIT 20",
					$today,
					$max
				)
			);

			if ( $over ) {
				$out[] = self::item(
					self::WARN,
					'بیش از سقف نوبت فعال',
					sprintf(
						'%s مشتری بیشتر از سقف فعلی (%s) نوبت فعال دارند، چون پیش از تغییر سقف ثبت شده‌اند: %s.',
						cmb_fa_num( count( $over ) ),
						cmb_fa_num( $max ),
						self::people( $over, false )
					),
					'این نوبت‌ها هم معتبرند. تا وقتی تعدادشان زیر سقف نیاید، این مشتری‌ها نوبت تازه نمی‌توانند بگیرند.'
				);
			}
		}

		return $out;
	}

	/**
	 * فهرست کوتاه مشتری‌ها برای متن هشدار.
	 */
	protected static function people( $rows, $with_service ) {
		$names = array();

		foreach ( array_slice( $rows, 0, 5 ) as $r ) {
			$label = trim( (string) $r->name ) !== '' ? $r->name : cmb_fa_num( $r->phone );

			$names[] = $with_service && ! empty( $r->title )
				? sprintf( '%s (%s × %s)', $label, $r->title, cmb_fa_num( (int) $r->c ) )
				: sprintf( '%s (%s نوبت)', $label, cmb_fa_num( (int) $r->c ) );
		}

		if ( count( $rows ) > 5 ) {
			$names[] = 'و ' . cmb_fa_num( count( $rows ) - 5 ) . ' نفر دیگر';
		}

		return implode( '، ', $names );
	}

	protected static function check_sms() {
		if ( ! CMB_Settings::get( 'sms_enabled', 1 ) ) {
			return array( self::item( self::WARN, 'پیامک', 'ارسال پیامک خاموش است، پس کد ورود و تاییدیه‌ی نوبت ارسال نمی‌شود.' ) );
		}

		$user = trim( (string) CMB_Settings::get( 'sms_username', '' ) );
		$pass = trim( (string) CMB_Settings::get( 'sms_password', '' ) );

		if ( '' === $user || '' === $pass ) {
			return array(
				self::item(
					self::BAD,
					'پیامک',
					'ارسال پیامک روشن است ولی نام کاربری یا رمز پنل پیامک خالی است. یعنی هیچ‌کس نمی‌تواند وارد شود، چون کد ورود ارسال نمی‌شود.',
					'اطلاعات پنل پیامک را در پیشخوان وردپرس (رزرو نوبت ← تنظیمات) وارد کنید.'
				),
			);
		}

		$out = array( self::item( self::OK, 'پیامک', 'روشن و تنظیم‌شده است.' ) );

		if ( CMB_Settings::get( 'otp_dev_mode', 0 ) ) {
			$out[] = self::item(
				self::BAD,
				'حالت توسعه',
				'«حالت توسعه» روشن است و کد ورود را داخل پاسخ سایت برمی‌گرداند. هر کسی که شماره‌ی یک مشتری را بداند می‌تواند به حسابش وارد شود.',
				'این گزینه را در پیشخوان (رزرو نوبت ← تنظیمات) خاموش کنید.'
			);
		}

		if ( ! CMB_Settings::admin_phones() ) {
			$out[] = self::item( self::WARN, 'شماره‌ی مدیر', 'شماره‌ای برای اطلاع از نوبت تازه و لغو ثبت نشده است.' );
		}

		return $out;
	}

	/**
	 * زمان‌بند.
	 *
	 * کار زمان‌بند (یادآوری و جاروی نوبت‌های گذشته) به بازدید از سایت
	 * وابسته است. اگر مدت زیادی اجرا نشده، یادآوری‌ها هم نرفته‌اند.
	 */
	protected static function check_cron() {
		$last = (int) get_option( CMB_Cron::TICK_OPTION, 0 );

		if ( ! $last ) {
			return array( self::item( self::WARN, 'زمان‌بند', 'هنوز یک بار هم اجرا نشده است. اگر افزونه تازه نصب شده، طبیعی است.' ) );
		}

		$hours = (int) floor( ( time() - $last ) / HOUR_IN_SECONDS );

		if ( $hours > 24 ) {
			return array(
				self::item(
					self::WARN,
					'زمان‌بند',
					sprintf( 'آخرین اجرا %s ساعت پیش بوده. پیامک یادآوری و تعیین وضعیت خودکار عقب می‌افتند.', cmb_fa_num( $hours ) ),
					'زمان‌بند با بازدید از سایت اجرا می‌شود. روی سایت کم‌بازدید، یک کرون واقعی روی سرور مطمئن‌تر است.'
				),
			);
		}

		return array( self::item( self::OK, 'زمان‌بند', 'به‌تازگی اجرا شده است.' ) );
	}

	/* ------------------------------------------------------------------
	 * اصلاح
	 * --------------------------------------------------------------- */

	/**
	 * تعیین وضعیت نوبت‌های گذشته، همین حالا.
	 *
	 * تنها اصلاح خودکارِ این صفحه است و برگشت‌پذیر هم هست: وضعیت هر
	 * نوبت را مسئول رزرو می‌تواند دستی عوض کند.
	 *
	 * @return int تعداد نوبت‌های تغییریافته.
	 */
	public static function sweep_past() {
		$done = CMB_Cron::auto_complete_past( true );

		CMB_Availability::flush_cache();

		return (int) $done;
	}
}
