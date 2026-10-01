<?php
/**
 * وظایف دوره‌ای — بدون وابستگی به کرون.
 *
 * روی این سایت کرون واقعی وجود ندارد و WP-Cron هم فقط وقتی اجرا
 * می‌شود که کسی صفحه‌ای را باز کند، پس نمی‌شود به آن تکیه کرد.
 *
 * به‌جایش «تیک فرصت‌طلبانه» می‌زنیم: روی هر بازدید عادی سایت یک
 * گزینه خوانده می‌شود؛ اگر از آخرین اجرا به اندازه‌ی کافی گذشته
 * باشد، وظایف همان‌جا اجرا می‌شوند. هزینه‌ی حالت عادی فقط خواندن
 * یک option است که وردپرس خودش کش کرده.
 *
 * اگر روزی کرون واقعی اضافه شد، همان هوک قدیمی هم کار می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Cron {

	const HOOK_REMINDER = 'cmb_hourly_tasks';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const TICK_OPTION = 'cmb_last_tick';
	const LOCK        = 'cmb_tick_lock';

	private function __construct() {
		// اگر کرون واقعی وجود داشت، از همین هوک استفاده می‌شود.
		add_action( self::HOOK_REMINDER, array( __CLASS__, 'run_hourly' ) );

		/* تیک اصلی: روی shutdown اجرا می‌شود تا کاربر منتظر نماند —
		   صفحه قبلاً برایش فرستاده شده است. */
		add_action( 'shutdown', array( __CLASS__, 'tick' ), 99 );

		/* تکمیل خودکار نوبت‌ها جدا از تیک ساعتی اجرا می‌شود، چون باید
		   همان لحظه‌ای که تاریخ عوض می‌شود اعمال شده باشد — نه تا یک
		   ساعت بعد. گارد روزانه‌ی داخل خودش تضمین می‌کند روزی فقط یک
		   کوئری بزند. */
		add_action( 'shutdown', array( __CLASS__, 'auto_complete_past' ), 98 );
	}

	/**
	 * دیگر چیزی زمان‌بندی نمی‌کنیم؛ برای سازگاری با نسخه‌های قبل نگه
	 * داشته شده و زمان‌بندی قدیمی را هم پاک می‌کند.
	 */
	public static function schedule_events() {
		self::clear_events();
	}

	/**
	 * تیک فرصت‌طلبانه.
	 *
	 * روی هر بازدید صدا زده می‌شود ولی فقط یک بار در ساعت کار می‌کند.
	 */
	public static function tick() {
		/* درخواست‌های حساس را معطل نمی‌کنیم.
		   REST مهم‌ترینشان است: کاربر منتظر پاسخ ثبت نوبت است و
		   نباید همان لحظه ارسال پیامک‌های یادآوری روی دستش بیفتد. */
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( is_admin() && ! wp_doing_cron() ) {
			// پیشخوان هم منتظر نماند؛ بازدیدهای فرانت برای تیک کافی‌اند.
			return;
		}

		$last = (int) get_option( self::TICK_OPTION, 0 );
		$now  = time();

		if ( $now - $last < HOUR_IN_SECONDS ) {
			return;
		}

		// قفل کوتاه تا دو بازدید هم‌زمان دوبار اجرا نکنند.
		if ( get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		update_option( self::TICK_OPTION, $now, false );

		/* اگر سرور اجازه بدهد، اول پاسخ را برای مرورگر می‌بندیم و بعد
		   کارها را انجام می‌دهیم. بدون این، کاربر تا پایان ارسال
		   پیامک‌ها منتظر می‌ماند حتی اگر صفحه‌اش کامل رسیده باشد.
		   پیش‌تر فقط PHP-FPM پشتیبانی می‌شد و روی لایت‌اسپید بازدیدکننده
		   تا آخرین پیامک یادآوری معطل می‌ماند. */
		ignore_user_abort( true );
		cmb_finish_request();

		self::run_hourly();

		delete_transient( self::LOCK );
	}

	public static function clear_events() {
		$timestamp = wp_next_scheduled( self::HOOK_REMINDER );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_REMINDER );
			$timestamp = wp_next_scheduled( self::HOOK_REMINDER );
		}
	}

	/**
	 * وظایف ساعتی.
	 */
	/**
	 * بستن خودکار نوبت‌های روزهای گذشته.
	 *
	 * اگر تا پایان روزِ نوبت کسی در پنل وضعیت را مشخص نکرده باشد،
	 * نوبت «انجام شده» در نظر گرفته می‌شود. بدون این، نوبت‌های قدیمی
	 * تا ابد «تایید شده» می‌مانند و فهرست پیش‌رو را شلوغ می‌کنند.
	 *
	 * وضعیت‌هایی که مدیر خودش ثبت کرده (لغو، عدم مراجعه، انجام شده)
	 * دست‌نخورده می‌مانند — فقط confirmedهای معطل‌مانده بسته می‌شوند.
	 *
	 * @return int تعداد نوبت‌هایی که بسته شدند.
	 */
	public static function auto_complete_past( $force = false ) {
		global $wpdb;

		$today = cmb_today();

		/* روزی یک بار کافی است. تاریخِ آخرین اجرا را نگه می‌داریم؛
		   تا وقتی امروز عوض نشده، حتی یک کوئری هم زده نمی‌شود. */
		if ( ! $force && get_option( 'cmb_autocomplete_day' ) === $today ) {
			return 0;
		}

		if ( ! CMB_Settings::get( 'auto_complete', 1 ) ) {
			return 0;
		}

		$table = cmb_table( 'bookings' );

		$done = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'done' WHERE status = 'confirmed' AND booking_date < %s", // phpcs:ignore
				$today
			)
		);

		/* پرچم «امروز اجرا شد» بعد از کوئری نوشته می‌شود، نه قبلش:
		   اگر کوئری شکست بخورد، اجرای بعدی باید دوباره تلاش کند. */
		if ( ! $force ) {
			update_option( 'cmb_autocomplete_day', $today, false );
		}

		if ( $done > 0 ) {
			cmb_log( sprintf( '%d نوبت گذشته خودکار «انجام شده» شد.', $done ) );
		}

		return $done;
	}

	public static function run_hourly() {
		CMB_OTP::cleanup();
		self::auto_complete_past();
		self::send_reminders();

		// پرداخت‌های بی‌جواب و نوبت‌های پرداخت‌نشده‌ی مانده
		if ( class_exists( 'CMB_Payments' ) ) {
			CMB_Payments::reconcile( true );
		}
	}

	/**
	 * ارسال یادآوری برای نوبت‌های فردا، در ساعت تعیین‌شده.
	 */
	public static function send_reminders() {
		if ( ! CMB_Settings::get( 'reminder_enabled', 1 ) ) {
			return;
		}

		$target_hour  = (int) CMB_Settings::get( 'reminder_hour', 18 );
		$current_hour = (int) current_time( 'G' );

		/* بدون کرون، تیک دقیقاً سر ساعت نمی‌افتد. اگر شرط «برابر بودن»
		   بماند و اولین بازدید بعد از ساعت هدف مثلاً ۱۹:۳۰ باشد،
		   یادآوری آن روز کلاً از دست می‌رفت.
		   پس «از ساعت هدف به بعد» را می‌پذیریم؛ ستون reminder_sent
		   خودش جلوی ارسال تکراری را می‌گیرد. */
		if ( $current_hour < $target_hour ) {
			return;
		}

		global $wpdb;

		$table    = cmb_table( 'bookings' );
		$tomorrow = ( new DateTime( cmb_today(), cmb_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE booking_date = %s AND status = 'confirmed' AND reminder_sent = 0", // phpcs:ignore
				$tomorrow
			)
		);

		foreach ( (array) $rows as $booking ) {
			$sent = CMB_Bookings::notify_reminder( $booking );

			if ( $sent ) {
				$wpdb->update(
					$table,
					array( 'reminder_sent' => 1 ),
					array( 'id' => (int) $booking->id )
				);
			}
		}
	}
}
