<?php
/**
 * پنل مدیریت افزونه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Admin {

	const CAPABILITY = 'manage_options';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_cmb_save_service', array( $this, 'handle_save_service' ) );
		add_action( 'admin_post_cmb_add_service', array( $this, 'handle_add_service' ) );
		add_action( 'admin_post_cmb_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cmb_add_closure', array( $this, 'handle_add_closure' ) );
		add_action( 'admin_post_cmb_remove_closure', array( $this, 'handle_remove_closure' ) );
		add_action( 'admin_post_cmb_update_booking', array( $this, 'handle_update_booking' ) );
		add_action( 'admin_post_cmb_test_sms', array( $this, 'handle_test_sms' ) );
		add_action( 'admin_post_cmb_export_bookings', array( $this, 'handle_export' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
	}

	/* ------------------------------------------------------------------
	 * منوها
	 * --------------------------------------------------------------- */

	public function register_menu() {
		$today_count = $this->count_upcoming();

		$title = 'رزرو نوبت';

		if ( $today_count ) {
			$title .= ' <span class="update-plugins count-' . $today_count . '"><span class="update-count">' . cmb_fa_num( $today_count ) . '</span></span>';
		}

		add_menu_page(
			'رزرو نوبت چک موتور',
			$title,
			self::CAPABILITY,
			'cmb-bookings',
			array( $this, 'page_bookings' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page( 'cmb-bookings', 'نوبت‌ها', 'نوبت‌ها', self::CAPABILITY, 'cmb-bookings', array( $this, 'page_bookings' ) );
		add_submenu_page( 'cmb-bookings', 'خدمات و پوسترها', 'خدمات و پوسترها', self::CAPABILITY, 'cmb-services', array( $this, 'page_services' ) );
		add_submenu_page( 'cmb-bookings', 'بستن روز / شیفت', 'بستن روز / شیفت', self::CAPABILITY, 'cmb-closures', array( $this, 'page_closures' ) );
		add_submenu_page( 'cmb-bookings', 'تنظیمات', 'تنظیمات', self::CAPABILITY, 'cmb-settings', array( $this, 'page_settings' ) );

		add_submenu_page(
			'cmb-bookings',
			'کاربران پنل رزرو',
			'کاربران پنل',
			'manage_options',
			'cmb-operators',
			array( 'CMB_Operators', 'render' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'cmb-' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'cmb-admin', CMB_URL . 'assets/css/admin.css', array(), CMB_VERSION );
		wp_enqueue_script( 'cmb-admin', CMB_URL . 'assets/js/admin.js', array( 'jquery' ), CMB_VERSION, true );
	}

	/**
	 * هشدار پیکربندی اولیه.
	 */
	public function setup_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || false === strpos( (string) $screen->id, 'cmb-' ) ) {
			return;
		}

		$missing = array();

		if ( '' === trim( (string) CMB_Settings::get( 'sms_username', '' ) ) ) {
			$missing[] = 'نام کاربری/رمز ملی‌پیامک';
		}

		if ( '' === trim( (string) CMB_Settings::get( 'pattern_otp', '' ) ) ) {
			$missing[] = 'شناسه‌ی پترن کد تایید (ورود)';
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><b>پیکربندی ناقص:</b> %s تنظیم نشده است. تا زمانی که این موارد تکمیل نشود، ورود با کد تایید کار نمی‌کند. <a href="%s">رفتن به تنظیمات</a></p></div>',
			esc_html( implode( '، ', $missing ) ),
			esc_url( admin_url( 'admin.php?page=cmb-settings' ) )
		);
	}

	protected function count_upcoming() {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND booking_date >= %s", // phpcs:ignore
				cmb_today()
			)
		);
	}

	/* ------------------------------------------------------------------
	 * صفحه‌ی نوبت‌ها
	 * --------------------------------------------------------------- */

	public function page_bookings() {
		// بارگذاری تنبل جدول: WP_List_Table تنها داخل صفحات wp-admin در دسترس است.
		if ( ! class_exists( 'CMB_Bookings_List_Table' ) ) {
			$list_table_file = CMB_DIR . 'includes/class-cmb-list-table.php';

			if ( file_exists( $list_table_file ) ) {
				require_once $list_table_file;
			}
		}

		if ( ! class_exists( 'CMB_Bookings_List_Table' ) ) {
			echo '<div class="wrap cmb-admin" dir="rtl"><h1>نوبت‌های ثبت‌شده</h1>'
				. '<div class="notice notice-error"><p>جدول نوبت‌ها بارگذاری نشد. '
				. 'لطفاً افزونه را غیرفعال و دوباره فعال کنید.</p></div></div>';
			return;
		}

		$table = new CMB_Bookings_List_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=cmb_export_bookings' ), 'cmb_export' );
		?>
		<div class="wrap cmb-admin" dir="rtl">
			<h1 class="wp-heading-inline">نوبت‌های ثبت‌شده</h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">خروجی CSV</a>

			<?php if ( function_exists( 'cmb_app_url' ) ) : ?>
				<a href="<?php echo esc_url( cmb_app_url( 'panel' ) ); ?>" class="page-title-action" target="_blank">داشبورد مدیریت</a>
				<a href="<?php echo esc_url( cmb_app_url() ); ?>" class="page-title-action" target="_blank">اپ مشتری</a>
			<?php endif; ?>

			<?php $this->render_notice(); ?>

			<?php $this->render_today_summary(); ?>

			<form method="get">
				<input type="hidden" name="page" value="cmb-bookings" />
				<?php
				$table->search_box( 'جستجو (نام / موبایل / کد)', 'cmb-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * خلاصه‌ی ظرفیت امروز و فردا.
	 */
	protected function render_today_summary() {
		$branch_id = CMB_Services::default_branch_id();
		$blocks    = cmb_blocks();
		$days      = array( cmb_today(), gmdate( 'Y-m-d', strtotime( cmb_today() . ' +1 day' ) ) );
		?>
		<div class="cmb-cards">
			<?php foreach ( $days as $index => $date ) : ?>
				<?php $counts = CMB_Availability::get_booked_counts( $branch_id, $date ); ?>
				<div class="cmb-card">
					<h3><?php echo esc_html( 0 === $index ? 'امروز' : 'فردا' ); ?> — <?php echo esc_html( cmb_jalali_date( $date, 'full' ) ); ?></h3>
					<ul>
						<?php foreach ( $blocks as $key => $block ) : ?>
							<?php
							$booked = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
							$closed = CMB_Availability::is_closed( $branch_id, $date, $key ) || CMB_Availability::is_closed( $branch_id, $date, '' );
							?>
							<li>
								<b><?php echo esc_html( $block['label'] ); ?></b>
								(<?php echo esc_html( cmb_fa_num( $block['start'] ) ); ?>):
								<?php if ( $closed ) : ?>
									<span class="cmb-pill cmb-pill--closed">بسته</span>
								<?php else : ?>
									<?php echo esc_html( cmb_fa_num( $booked ) ); ?> از <?php echo esc_html( cmb_fa_num( $block['capacity'] ) ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * صفحه‌ی خدمات
	 * --------------------------------------------------------------- */

	public function page_services() {
		$services = CMB_Services::get_services( 0, false );
		$weekdays = array(
			6 => 'شنبه',
			0 => 'یکشنبه',
			1 => 'دوشنبه',
			2 => 'سه‌شنبه',
			3 => 'چهارشنبه',
			4 => 'پنجشنبه',
			5 => 'جمعه',
		);
		?>
		<div class="wrap cmb-admin" dir="rtl">
			<h1>خدمات و پوسترها</h1>
			<p class="description">قیمت، شرح، روزهای ارائه و تصویر پوستر هر خدمت را می‌توانید بدون نیاز به توسعه‌دهنده تغییر دهید.</p>

			<?php $this->render_notice(); ?>

			<?php foreach ( $services as $service ) : ?>
				<?php
				$allowed    = CMB_Services::allowed_weekdays( $service );
				$poster_url = $service->poster_id ? wp_get_attachment_image_url( (int) $service->poster_id, 'medium' ) : '';
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmb-box">
					<?php wp_nonce_field( 'cmb_save_service_' . $service->id ); ?>
					<input type="hidden" name="action" value="cmb_save_service" />
					<input type="hidden" name="service_id" value="<?php echo esc_attr( $service->id ); ?>" />

					<h2><?php echo esc_html( $service->title ); ?></h2>

					<table class="form-table">
						<tr>
							<th><label>عنوان خدمت</label></th>
							<td><input type="text" name="title" class="regular-text" value="<?php echo esc_attr( $service->title ); ?>" required /></td>
						</tr>
						<tr>
							<th><label>شرح / اجزای پکیج</label></th>
							<td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $service->description ); ?></textarea></td>
						</tr>
						<tr>
							<th><label>قیمت (تومان)</label></th>
							<td>
								<input type="number" name="price" min="0" step="1000" value="<?php echo esc_attr( $service->price ); ?>" />
								<p class="description">اگر قیمت روی پوستر نمایش داده می‌شود، این عدد فقط برای پیامک و خلاصه‌ی رزرو استفاده می‌شود.</p>
							</td>
						</tr>
						<tr>
							<th><label>مدت زمان تخمینی</label></th>
							<td><input type="text" name="duration_note" class="regular-text" value="<?php echo esc_attr( $service->duration_note ); ?>" /></td>
						</tr>
						<tr>
							<th><label>روزهای قابل رزرو</label></th>
							<td>
								<?php foreach ( $weekdays as $index => $label ) : ?>
									<label class="cmb-check">
										<input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( $index ); ?>" <?php checked( in_array( $index, $allowed, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">طبق سند اسپک: شنبه تا دوشنبه فقط تقویت موتور؛ سه‌شنبه و چهارشنبه هر دو خدمت؛ پنجشنبه و جمعه تعطیل.</p>
							</td>
						</tr>
						<tr>
							<th><label>پوستر خدمت</label></th>
							<td class="cmb-poster-field">
								<input type="hidden" name="poster_id" class="cmb-poster-id" value="<?php echo esc_attr( $service->poster_id ); ?>" />
								<div class="cmb-poster-preview">
									<?php if ( $poster_url ) : ?>
										<img src="<?php echo esc_url( $poster_url ); ?>" alt="" />
									<?php endif; ?>
								</div>
								<button type="button" class="button cmb-poster-select">انتخاب / تعویض تصویر</button>
								<button type="button" class="button cmb-poster-remove">حذف تصویر</button>
							</td>
						</tr>
						<?php $this->render_capacity_fields( CMB_Services::own_capacity( $service ), (int) $service->id ); ?>
						<tr>
							<th><label>وضعیت</label></th>
							<td>
								<label class="cmb-check">
									<input type="checkbox" name="is_active" value="1" <?php checked( (int) $service->is_active, 1 ); ?> />
									این خدمت در سایت قابل رزرو باشد
								</label>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" class="button button-primary">ذخیره‌ی «<?php echo esc_html( $service->title ); ?>»</button>
					</p>
				</form>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmb-box">
				<?php wp_nonce_field( 'cmb_add_service' ); ?>
				<input type="hidden" name="action" value="cmb_add_service" />

				<h2>افزودن خدمت تازه</h2>
				<p class="description">
					بعد از ثبت، خدمت تازه در همین صفحه ظاهر می‌شود و می‌توانید پوستر، شرح و
					روزهای ارائه‌اش را کامل کنید. تا وقتی «قابل رزرو» را تیک نزنید، در اپ
					مشتری نمایش داده نمی‌شود.
				</p>

				<table class="form-table">
					<tr>
						<th><label>عنوان خدمت</label></th>
						<td><input type="text" name="title" class="regular-text" required placeholder="مثلاً شست‌وشوی انژکتور" /></td>
					</tr>
					<tr>
						<th><label>قیمت (تومان)</label></th>
						<td><input type="number" name="price" min="0" step="10000" value="0" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label>مدت تخمینی</label></th>
						<td><input type="text" name="duration_note" class="regular-text" placeholder="مثلاً حدود ۲ ساعت" /></td>
					</tr>
					<tr>
						<th><label>روزهای ارائه</label></th>
						<td>
							<?php foreach ( $weekdays as $num => $label ) : ?>
								<label class="cmb-check" style="margin-inline-end:14px">
									<input type="checkbox" name="allowed_weekdays[]" value="<?php echo esc_attr( $num ); ?>" <?php checked( in_array( $num, array( 6, 0, 1, 2, 3 ), true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<?php $this->render_capacity_fields( null, 0 ); ?>
					<tr>
						<th><label>وضعیت</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="is_active" value="1" checked />
								همین حالا قابل رزرو باشد
							</label>
						</td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary">ثبت خدمت تازه</button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * ثبت خدمت تازه از پیشخوان.
	 */
	public function handle_add_service() {
		$this->guard( 'cmb_add_service' );

		$created = CMB_Services::create_service(
			array(
				'title'            => wp_unslash( $_POST['title'] ?? '' ),          // phpcs:ignore
				'price'            => wp_unslash( $_POST['price'] ?? 0 ),           // phpcs:ignore
				'duration_note'    => wp_unslash( $_POST['duration_note'] ?? '' ),  // phpcs:ignore
				'allowed_weekdays' => (array) ( $_POST['allowed_weekdays'] ?? array() ), // phpcs:ignore
				'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,         // phpcs:ignore
				'own_capacity'     => $this->read_own_capacity(),
			)
		);

		if ( is_wp_error( $created ) ) {
			$this->redirect( 'cmb-services', $created->get_error_message(), 'error' );
		}

		$this->redirect( 'cmb-services', 'خدمت تازه ثبت شد. حالا می‌توانید پوستر و شرحش را کامل کنید.' );
	}

	/* ------------------------------------------------------------------
	 * صفحه‌ی بستن روز / شیفت
	 * --------------------------------------------------------------- */

	public function page_closures() {
		$branch_id = CMB_Services::default_branch_id();
		$closures  = CMB_Availability::get_closures( $branch_id );
		$blocks    = cmb_blocks();
		?>
		<div class="wrap cmb-admin" dir="rtl">
			<h1>بستن روز یا شیفت</h1>
			<p class="description">برای روزهایی که استادکار حضور ندارد یا شعبه تعطیل است، رزرو آنلاین را ببندید.</p>

			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmb-box">
				<?php wp_nonce_field( 'cmb_add_closure' ); ?>
				<input type="hidden" name="action" value="cmb_add_closure" />

				<table class="form-table">
					<tr>
						<th><label>تاریخ</label></th>
						<td>
							<?php echo cmb_jalali_field( 'closure_date' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<p class="description">روز، ماه و سال شمسی را انتخاب کنید.</p>
						</td>
					</tr>
					<tr>
						<th><label>محدوده</label></th>
						<td>
							<select name="block_key">
								<option value="">کل روز</option>
								<?php foreach ( $blocks as $key => $block ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $block['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label>دلیل (اختیاری)</label></th>
						<td><input type="text" name="reason" class="regular-text" placeholder="مثلاً مسافرت استادکار" /></td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary">بستن این بازه</button></p>
			</form>

			<h2>بازه‌های بسته‌شده</h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>تاریخ</th>
						<th>محدوده</th>
						<th>دلیل</th>
						<th>عملیات</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $closures ) ) : ?>
						<tr><td colspan="4">موردی ثبت نشده است.</td></tr>
					<?php else : ?>
						<?php foreach ( $closures as $closure ) : ?>
							<tr>
								<td><?php echo esc_html( cmb_jalali_date( $closure->closure_date, 'full' ) ); ?></td>
								<td><?php echo esc_html( '' === $closure->block_key ? 'کل روز' : cmb_block_label( $closure->block_key ) ); ?></td>
								<td><?php echo esc_html( $closure->reason ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmb_remove_closure&closure_id=' . $closure->id ), 'cmb_remove_closure_' . $closure->id ) ); ?>">باز کردن</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * صفحه‌ی تنظیمات
	 * --------------------------------------------------------------- */

	public function page_settings() {
		$s      = CMB_Settings::all();
		$branch = CMB_Services::get_branch();
		?>
		<div class="wrap cmb-admin" dir="rtl">
			<h1>تنظیمات رزرو نوبت</h1>

			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmb_save_settings' ); ?>
				<input type="hidden" name="action" value="cmb_save_settings" />

				<h2 class="title">ظرفیت و زمان‌بندی</h2>
				<table class="form-table">
					<tr>
						<th><label>شیفت صبح</label></th>
						<td>
							ساعت شروع <input type="time" name="morning_start" value="<?php echo esc_attr( $s['morning_start'] ); ?>" />
							&nbsp; ظرفیت <input type="number" name="morning_capacity" min="0" max="50" value="<?php echo esc_attr( $s['morning_capacity'] ); ?>" class="small-text" /> ماشین
						</td>
					</tr>
					<tr>
						<th><label>شیفت بعدازظهر</label></th>
						<td>
							ساعت شروع <input type="time" name="afternoon_start" value="<?php echo esc_attr( $s['afternoon_start'] ); ?>" />
							&nbsp; ظرفیت <input type="number" name="afternoon_capacity" min="0" max="50" value="<?php echo esc_attr( $s['afternoon_capacity'] ); ?>" class="small-text" /> ماشین
						</td>
					</tr>
					<tr>
						<th><label>حداقل فاصله تا مراجعه</label></th>
						<td><input type="number" name="min_days_ahead" min="0" max="30" value="<?php echo esc_attr( $s['min_days_ahead'] ); ?>" class="small-text" /> روز
							<p class="description">۱ یعنی رزرو برای همان روز مجاز نیست.</p>
						</td>
					</tr>
					<tr>
						<th><label>طول پنجره‌ی رزرو</label></th>
						<td><input type="number" name="window_days" min="1" max="60" value="<?php echo esc_attr( $s['window_days'] ); ?>" class="small-text" /> روز</td>
					</tr>
					<tr>
						<th><label>حداکثر نوبت فعال هر کاربر</label></th>
						<td><input type="number" name="max_active_per_user" min="0" max="10" value="<?php echo esc_attr( $s['max_active_per_user'] ); ?>" class="small-text" />
							<p class="description">۰ یعنی بدون محدودیت.</p>
						</td>
					</tr>
					<tr>
						<th><label>یک نوبت از هر خدمت</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="one_per_service" value="1" <?php checked( (int) $s['one_per_service'], 1 ); ?> />
								از هر خدمت هم‌زمان فقط یک نوبت فعال
							</label>
							<p class="description">
								مثال: با سقف ۲ و این گزینه روشن، مشتری می‌تواند یک تنظیم موتور و یک تعویض روغن هم‌زمان داشته باشد، ولی دو تنظیم موتور نه.
								با سقف ۱ اثری ندارد.
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title">لغو نوبت توسط مشتری</h2>
				<table class="form-table">
					<tr>
						<th><label>لغو آنلاین</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="cancel_enabled" value="1" <?php checked( (int) $s['cancel_enabled'], 1 ); ?> /> مشتری بتواند نوبتش را خودش لغو کند
							</label>
							<p class="description">اگر خاموش باشد، دکمه‌ی لغو در اپ نمایش داده نمی‌شود و درخواست‌های لغو رد می‌شوند.</p>
						</td>
					</tr>
					<tr>
						<th><label>مهلت لغو</label></th>
						<td>تا <input type="number" name="cancel_deadline_hours" min="0" max="336" value="<?php echo esc_attr( $s['cancel_deadline_hours'] ); ?>" class="small-text" /> ساعت پیش از شروع شیفت
							<p class="description">
								مثال: با مقدار ۲۴ و شیفت صبح ساعت <?php echo esc_html( $s['morning_start'] ); ?>، لغو تا ساعت
								<?php echo esc_html( $s['morning_start'] ); ?> روز قبل ممکن است. مقدار ۰ یعنی تا لحظه‌ی شروع شیفت.
								بعد از این مهلت، مشتری پیام «با شعبه تماس بگیرید» می‌بیند.
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title">اتصال به ملی‌پیامک</h2>
				<table class="form-table">
					<tr>
						<th><label>ارسال پیامک</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="sms_enabled" value="1" <?php checked( (int) $s['sms_enabled'], 1 ); ?> /> فعال باشد
							</label>
						</td>
					</tr>
					<tr>
						<th><label>نام کاربری</label></th>
						<td><input type="text" name="sms_username" class="regular-text" value="<?php echo esc_attr( $s['sms_username'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label>رمز عبور</label></th>
						<td><input type="password" name="sms_password" class="regular-text" value="<?php echo esc_attr( $s['sms_password'] ); ?>" autocomplete="new-password" /></td>
					</tr>
					<tr>
						<th><label>روش ارسال</label></th>
						<td>
							<select name="sms_api_mode">
								<option value="pattern" <?php selected( $s['sms_api_mode'], 'pattern' ); ?>>پترن (خدمات پیشرفته)</option>
								<option value="simple" <?php selected( $s['sms_api_mode'], 'simple' ); ?>>متن آزاد با خط اختصاصی</option>
							</select>
							<p class="description">برای کد تایید حتماً باید از پترن استفاده شود.</p>
						</td>
					</tr>
					<tr>
						<th><label>شماره‌ی فرستنده</label></th>
						<td><input type="text" name="sms_from" class="regular-text" value="<?php echo esc_attr( $s['sms_from'] ); ?>" placeholder="فقط برای حالت متن آزاد" /></td>
					</tr>
				</table>

				<h2 class="title">شناسه‌ی پترن‌ها (bodyId)</h2>
				<p class="description">هر پترن را در پنل ملی‌پیامک بسازید و شناسه‌ی آن را اینجا وارد کنید. ترتیب متغیرها باید دقیقاً مطابق توضیح هر ردیف باشد.</p>

				<details style="margin:12px 0;max-width:840px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px">
					<summary style="cursor:pointer;font-weight:600">متن آماده‌ی پترن‌ها — برای ثبت در ملی‌پیامک</summary>

					<p class="description" style="margin-top:10px">
						این متن‌ها را در پنل ملی‌پیامک ثبت کنید. ترتیب <code>{0}</code>، <code>{1}</code> …
						باید دقیقاً همین باشد، وگرنه مقادیر جابه‌جا در پیامک می‌نشینند.
						هر پترن که تایید شد، شناسه‌اش را در ردیف مربوطه‌ی پایین بگذارید.
					</p>

					<?php
					$cmb_pattern_texts = array(
						'کد تایید ورود' => "کد ورود شما به چک موتور:\n{0}\nاین کد را در اختیار کسی قرار ندهید.",
						'تاییدیه‌ی رزرو (مشتری)' => "{0} عزیز، نوبت شما در چک موتور ثبت شد.\nخدمت: {1}\nتاریخ: {2}\n{3} - ساعت {4}\nکد پیگیری: {5}",
						'اطلاع به مدیر' => "نوبت جدید چک موتور\nخدمت: {0}\nتاریخ: {1} - {2}\nمشتری: {3}\nموبایل: {4}",
						'یادآوری یک روز قبل' => "{0} عزیز، یادآوری نوبت فردای شما در چک موتور.\nخدمت: {1}\nتاریخ: {2}\n{3} - ساعت {4}",
						'لغو نوبت' => "{0} عزیز، نوبت شما در چک موتور لغو شد.\nخدمت: {1}\nتاریخ: {2} - {3}\nبرای هماهنگی مجدد تماس بگیرید.",
					);

					foreach ( $cmb_pattern_texts as $cmb_label => $cmb_text ) :
						?>
						<p style="margin:14px 0 4px"><b><?php echo esc_html( $cmb_label ); ?></b></p>
						<textarea readonly rows="<?php echo (int) ( substr_count( $cmb_text, "\n" ) + 1 ); ?>"
							onclick="this.select()"
							style="width:100%;max-width:640px;font-family:inherit;direction:rtl;background:#f6f7f7"
						><?php echo esc_textarea( $cmb_text ); ?></textarea>
					<?php endforeach; ?>

					<p class="description" style="margin-top:12px">
						نکته: ملی‌پیامک معمولاً پترن‌های حاوی لینک یا کلمات تبلیغاتی را رد می‌کند.
						اگر پترنی تایید نشد، جمله را ساده‌تر کنید و دوباره بفرستید.
					</p>
				</details>

				<table class="form-table">
					<tr>
						<th><label>کد تایید ورود</label></th>
						<td>
							<input type="text" name="pattern_otp" class="regular-text" value="<?php echo esc_attr( $s['pattern_otp'] ); ?>" />
							<p class="description">متغیرها: <code>{0}</code> کد تایید</p>
						</td>
					</tr>
					<tr>
						<th><label>تاییدیه‌ی رزرو (مشتری)</label></th>
						<td>
							<input type="text" name="pattern_booking" class="regular-text" value="<?php echo esc_attr( $s['pattern_booking'] ); ?>" />
							<p class="description">متغیرها: <code>{0}</code> نام، <code>{1}</code> خدمت، <code>{2}</code> تاریخ، <code>{3}</code> شیفت، <code>{4}</code> ساعت، <code>{5}</code> کد پیگیری</p>
						</td>
					</tr>
					<tr>
						<th><label>اطلاع به مدیر</label></th>
						<td>
							<input type="text" name="pattern_admin" class="regular-text" value="<?php echo esc_attr( $s['pattern_admin'] ); ?>" />
							<p class="description">متغیرها: <code>{0}</code> خدمت، <code>{1}</code> تاریخ، <code>{2}</code> شیفت، <code>{3}</code> نام مشتری، <code>{4}</code> موبایل مشتری</p>
						</td>
					</tr>
					<tr>
						<th><label>یادآوری یک روز قبل</label></th>
						<td>
							<input type="text" name="pattern_reminder" class="regular-text" value="<?php echo esc_attr( $s['pattern_reminder'] ); ?>" />
							<p class="description">متغیرها: <code>{0}</code> نام، <code>{1}</code> خدمت، <code>{2}</code> تاریخ، <code>{3}</code> شیفت، <code>{4}</code> ساعت</p>
						</td>
					</tr>
					<tr>
						<th><label>لغو نوبت</label></th>
						<td>
							<input type="text" name="pattern_cancel" class="regular-text" value="<?php echo esc_attr( $s['pattern_cancel'] ); ?>" />
							<p class="description">متغیرها: <code>{0}</code> نام، <code>{1}</code> خدمت، <code>{2}</code> تاریخ، <code>{3}</code> شیفت</p>
						</td>
					</tr>
					<tr>
						<th><label>اطلاع لغو به مدیر</label></th>
						<td>
							<input type="text" name="pattern_admin_cancel" class="regular-text" value="<?php echo esc_attr( $s['pattern_admin_cancel'] ); ?>" />
							<p class="description">
								وقتی مشتری خودش نوبت را لغو می‌کند به شماره‌های مدیر ارسال می‌شود.
								متغیرها: <code>{0}</code> خدمت، <code>{1}</code> تاریخ، <code>{2}</code> شیفت، <code>{3}</code> نام مشتری، <code>{4}</code> موبایل مشتری
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title">کد تایید (OTP)</h2>
				<table class="form-table">
					<tr>
						<th><label>اعتبار کد</label></th>
						<td><input type="number" name="otp_ttl_seconds" min="60" max="900" value="<?php echo esc_attr( $s['otp_ttl_seconds'] ); ?>" class="small-text" /> ثانیه</td>
					</tr>
					<tr>
						<th><label>فاصله‌ی ارسال مجدد</label></th>
						<td><input type="number" name="otp_resend_seconds" min="30" max="600" value="<?php echo esc_attr( $s['otp_resend_seconds'] ); ?>" class="small-text" /> ثانیه</td>
					</tr>
					<tr>
						<th><label>حداکثر تلاش اشتباه</label></th>
						<td><input type="number" name="otp_max_attempts" min="1" max="10" value="<?php echo esc_attr( $s['otp_max_attempts'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label>سقف ساعتی هر شماره</label></th>
						<td><input type="number" name="otp_hourly_limit_phone" min="1" max="50" value="<?php echo esc_attr( $s['otp_hourly_limit_phone'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label>سقف ساعتی هر IP</label></th>
						<td><input type="number" name="otp_hourly_limit_ip" min="1" max="200" value="<?php echo esc_attr( $s['otp_hourly_limit_ip'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label>حالت توسعه</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="otp_dev_mode" value="1" <?php checked( (int) $s['otp_dev_mode'], 1 ); ?> />
								کد تایید در پاسخ سرور برگردانده شود (فقط برای تست — روی سایت واقعی خاموش باشد)
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title">اطلاع‌رسانی</h2>
				<table class="form-table">
					<tr>
						<th><label>پیامک به مدیر</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="admin_sms_enabled" value="1" <?php checked( (int) $s['admin_sms_enabled'], 1 ); ?> /> فعال باشد
							</label>
						</td>
					</tr>
					<tr>
						<th><label>شماره‌های مدیر</label></th>
						<td>
							<input type="text" name="admin_phones" class="regular-text" value="<?php echo esc_attr( $s['admin_phones'] ); ?>" placeholder="09121234567، 09351234567" />
							<p class="description">چند شماره را با کاما جدا کنید.</p>
						</td>
					</tr>
					<tr>
						<th><label>یادآوری یک روز قبل</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="reminder_enabled" value="1" <?php checked( (int) $s['reminder_enabled'], 1 ); ?> /> فعال باشد
							</label>
							&nbsp; ساعت ارسال:
							<input type="number" name="reminder_hour" min="0" max="23" value="<?php echo esc_attr( $s['reminder_hour'] ); ?>" class="small-text" />
							<p class="description">ارسال به وردپرس-کرون وابسته است؛ برای دقت بیشتر یک cron واقعی روی سرور تنظیم کنید.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">متن‌ها و اطلاعات شعبه</h2>
				<table class="form-table">
					<tr>
						<th><label>پیام صفحه‌ی تاییدیه</label></th>
						<td><input type="text" name="confirm_note" class="large-text" value="<?php echo esc_attr( $s['confirm_note'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label>یادداشت ECU</label></th>
						<td><input type="text" name="ecu_note" class="large-text" value="<?php echo esc_attr( $s['ecu_note'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label>پیام خارج از بازه</label></th>
						<td><input type="text" name="out_of_window_note" class="large-text" value="<?php echo esc_attr( $s['out_of_window_note'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label>قانون تاخیر</label></th>
						<td><input type="text" name="late_rule_note" class="large-text" value="<?php echo esc_attr( $s['late_rule_note'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label>تلفن شعبه</label></th>
						<td>
							<input type="text" name="branch_phone" class="regular-text" value="<?php echo esc_attr( $branch ? $branch->phone : '' ); ?>" />
							<p class="description">در صفحه‌ی رزرو برای موارد خارج از بازه نمایش داده می‌شود.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">ظاهر و دسترسی</h2>
				<table class="form-table">
					<tr>
						<th><label>ارقام فارسی</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="cmb_persian_digits" value="1" <?php checked( (int) get_option( 'cmb_persian_digits', 1 ), 1 ); ?> /> اعداد با ارقام فارسی نمایش داده شوند (۱۲۳)
							</label>
							<p class="description">فونت IRANYekanX همراه افزونه است و ارقام فارسی را کامل دارد. فقط اگر فونت را عوض کردید و ارقام به شکل لوزی خالی دیده شد، این را خاموش کنید.</p>
						</td>
					</tr>
					<tr>
						<th><label>چیدمان پوستر خدمات</label></th>
						<td>
							<select name="cmb_poster_cols">
								<option value="1" <?php selected( (int) get_option( 'cmb_poster_cols', 1 ), 1 ); ?>>یک ستون — پوستر تمام‌عرض</option>
								<option value="2" <?php selected( (int) get_option( 'cmb_poster_cols', 1 ), 2 ); ?>>دو ستون — فشرده‌تر</option>
							</select>
							<p class="description">دو ستون فضای کمتری می‌گیرد و همه‌ی خدمات یک‌جا دیده می‌شوند؛ یک ستون جزئیات پوستر را خواناتر نشان می‌دهد.</p>
						</td>
					</tr>
					<tr>
						<th><label>برگه‌ی رزرو تمام‌صفحه</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="cmb_canvas" value="1" <?php checked( (int) get_option( 'cmb_canvas', 1 ), 1 ); ?> /> بدون هدر، منو و فوتر قالب نمایش داده شود
							</label>
						</td>
					</tr>
					<tr>
						<th><label>نشانی مسیر اپ</label></th>
						<td>
							<input type="text" name="cmb_app_slug" class="regular-text" dir="ltr" value="<?php echo esc_attr( get_option( 'cmb_app_slug', 'reserve' ) ); ?>" />
							<p class="description">
								اپ روی <code><?php echo esc_html( home_url( '/' . get_option( 'cmb_app_slug', 'reserve' ) . '/' ) ); ?></code> باز می‌شود.
								<?php
								$cmb_clash = get_page_by_path( (string) get_option( 'cmb_app_slug', 'reserve' ) );

								if ( $cmb_clash ) :
									?>
									<br><b style="color:#b32d2e">توجه:</b>
									برگه‌ای به نام «<?php echo esc_html( $cmb_clash->post_title ); ?>» روی همین نشانی است و دیگر دیده نمی‌شود.
									یا آن برگه را حذف کنید یا این نشانی را عوض کنید.
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label>صفحه‌ی ورود سایت</label></th>
						<td>
							<input type="text" name="cmb_login_url" class="regular-text" dir="ltr" value="<?php echo esc_attr( get_option( 'cmb_login_url', '' ) ); ?>" placeholder="<?php echo esc_attr( wp_login_url() ); ?>" />
							<p class="description">اگر سایت فرم ورود اختصاصی دارد نشانی‌اش را بگذارید. خالی یعنی ورود پیش‌فرض وردپرس.</p>
						</td>
					</tr>
					<tr>
						<th><label>نصب پنل روی گوشی</label></th>
						<td>
							<label class="cmb-check">
								<input type="checkbox" name="cmb_panel_pwa" value="1" <?php checked( (int) get_option( 'cmb_panel_pwa', 1 ), 1 ); ?> /> پنل مدیریت جدا از اپ اصلی سایت به صفحه‌ی اصلی گوشی اضافه شود
							</label>
							<p class="description">
								روی <code><?php echo esc_html( cmb_app_url( 'panel' ) ); ?></code> گزینه‌ی «Add to Home Screen» آیکونی می‌سازد که مستقیم همین پنل را باز می‌کند،
								نه صفحه‌ای که افزونه‌ی PWA سایت برای اپ اصلی تعیین کرده. اپ اصلی سایت تغییری نمی‌کند.
								در خود پنل هم دکمه‌ی «نصب روی گوشی» با راهنمای آیفون و اندروید هست.
							</p>
						</td>
					</tr>
					<tr>
						<th><label>نام اپ پنل</label></th>
						<td>
							<input type="text" name="cmb_panel_app_name" class="regular-text" value="<?php echo esc_attr( get_option( 'cmb_panel_app_name', '' ) ); ?>" placeholder="مدیریت رزرو" />
							<p class="description">زیر آیکون روی صفحه‌ی اصلی گوشی نوشته می‌شود. کوتاه باشد (حدود ۱۲ حرف) تا بریده نشود. خالی یعنی «مدیریت رزرو».</p>
						</td>
					</tr>
					<tr>
						<th><label>فایل فونت (اختیاری)</label></th>
						<td>
							<input type="text" name="cmb_font_url" class="regular-text" dir="ltr" value="<?php echo esc_attr( get_option( 'cmb_font_url', '' ) ); ?>" placeholder="https://example.com/font.woff2" />
							<p class="description">فقط اگر می‌خواهید فونت پیش‌فرض را عوض کنید.</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">ذخیره‌ی تنظیمات</button>
				</p>
			</form>

			<hr />

			<h2>تست اتصال پیامک</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmb_test_sms' ); ?>
				<input type="hidden" name="action" value="cmb_test_sms" />
				<p>
					<input type="text" name="test_phone" placeholder="09121234567" class="regular-text" />
					<button type="submit" class="button">ارسال کد تایید آزمایشی</button>
				</p>
				<p class="description">یک کد تایید واقعی با پترن ورود ارسال می‌شود و اعتبار پنل هم بررسی می‌گردد.</p>
			</form>

			<hr />

			<h2>شورت‌کدها</h2>
			<table class="widefat striped">
				<tr><td><code>[checkmotor_booking]</code></td><td>فرم کامل رزرو نوبت</td></tr>
				<tr><td><code>[checkmotor_my_bookings]</code></td><td>فهرست نوبت‌های کاربر واردشده</td></tr>
				<tr><td><code>[checkmotor_login]</code></td><td>فرم مستقل ورود با کد تایید</td></tr>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * پردازش فرم‌ها
	 * --------------------------------------------------------------- */

	protected function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی مجاز نیست.' );
		}

		check_admin_referer( $nonce_action );
	}

	protected function redirect( $page, $message = '', $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'cmb_message' => rawurlencode( $message ),
					'cmb_type'    => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	protected function render_notice() {
		if ( empty( $_GET['cmb_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['cmb_message'] ) ) ); // phpcs:ignore
		$type    = isset( $_GET['cmb_type'] ) ? sanitize_key( wp_unslash( $_GET['cmb_type'] ) ) : 'success'; // phpcs:ignore
		$class   = 'error' === $type ? 'notice-error' : 'notice-success';

		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * سهمیه‌ی جداگانه از فرم پیشخوان.
	 *
	 * @return array|null null یعنی «از ظرفیت اصلی شیفت».
	 */
	protected function read_own_capacity() {
		if ( empty( $_POST['cap_mode'] ) || 'own' !== $_POST['cap_mode'] ) { // phpcs:ignore
			return null;
		}

		$out = array();

		foreach ( array_keys( cmb_blocks() ) as $key ) {
			$out[ $key ] = isset( $_POST['cap'][ $key ] ) ? (int) $_POST['cap'][ $key ] : 0; // phpcs:ignore
		}

		return $out;
	}

	/**
	 * فیلدهای ظرفیت در فرم خدمت پیشخوان.
	 */
	protected function render_capacity_fields( $own, $uid ) {
		$is_own = null !== $own;
		?>
		<tr>
			<th><label>ظرفیت</label></th>
			<td>
				<label class="cmb-check" style="display:block;margin-bottom:6px">
					<input type="radio" name="cap_mode" value="shared" <?php checked( ! $is_own ); ?> />
					از ظرفیت اصلی شیفت — هر نوبت یک جا از شیفت می‌گیرد (مناسب کارهای طولانی مثل تنظیم موتور)
				</label>
				<label class="cmb-check" style="display:block">
					<input type="radio" name="cap_mode" value="own" <?php checked( $is_own ); ?> />
					سهمیه‌ی جداگانه — از ظرفیت اصلی کم نمی‌کند (مناسب کارهای کوتاه مثل تعویض روغن)
				</label>

				<p style="margin-top:10px">
					<?php foreach ( cmb_blocks() as $key => $block ) : ?>
						<label style="margin-inline-end:18px">
							<?php echo esc_html( $block['label'] ); ?>:
							<input type="number" min="0" max="999" class="small-text"
								name="cap[<?php echo esc_attr( $key ); ?>]"
								id="<?php echo esc_attr( 'cap-' . $uid . '-' . $key ); ?>"
								value="<?php echo esc_attr( $is_own ? (int) $own[ $key ] : 10 ); ?>" /> نفر
						</label>
					<?php endforeach; ?>
				</p>
				<p class="description">عددها فقط در حالت «سهمیه‌ی جداگانه» اعمال می‌شوند. صفر یعنی در آن شیفت ارائه نمی‌شود.</p>
			</td>
		</tr>
		<?php
	}

	public function handle_save_service() {
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;

		$this->guard( 'cmb_save_service_' . $service_id );

		$weekdays = isset( $_POST['weekdays'] ) ? array_map( 'intval', (array) $_POST['weekdays'] ) : array(); // phpcs:ignore

		CMB_Services::update_service(
			$service_id,
			array(
				'title'            => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
				'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'price'            => (int) ( $_POST['price'] ?? 0 ),
				'duration_note'    => sanitize_text_field( wp_unslash( $_POST['duration_note'] ?? '' ) ),
				'allowed_weekdays' => implode( ',', $weekdays ),
				'poster_id'        => (int) ( $_POST['poster_id'] ?? 0 ),
				'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,
				'own_capacity'     => $this->read_own_capacity(),
			)
		);

		$this->redirect( 'cmb-services', 'خدمت با موفقیت ذخیره شد.' );
	}

	public function handle_save_settings() {
		$this->guard( 'cmb_save_settings' );

		$text_keys = array(
			'morning_start',
			'afternoon_start',
			'sms_username',
			'sms_password',
			'sms_from',
			'sms_api_mode',
			'pattern_otp',
			'pattern_booking',
			'pattern_reminder',
			'pattern_admin',
			'pattern_cancel',
			'pattern_admin_cancel',
			'admin_phones',
			'confirm_note',
			'ecu_note',
			'out_of_window_note',
			'late_rule_note',
		);

		$int_keys = array(
			'morning_capacity',
			'afternoon_capacity',
			'min_days_ahead',
			'window_days',
			'max_active_per_user',
			'cancel_deadline_hours',
			'otp_ttl_seconds',
			'otp_resend_seconds',
			'otp_max_attempts',
			'otp_hourly_limit_ip',
			'otp_hourly_limit_phone',
			'reminder_hour',
		);

		$bool_keys = array(
			'one_per_service',
			'cancel_enabled',
			'sms_enabled',
			'admin_sms_enabled',
			'reminder_enabled',
			'otp_dev_mode',
		);

		$values = array();

		foreach ( $text_keys as $key ) {
			$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
		}

		foreach ( $int_keys as $key ) {
			$values[ $key ] = (int) ( $_POST[ $key ] ?? 0 );
		}

		foreach ( $bool_keys as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}

		// برنامه‌ی کاری با همان قواعد پنل بررسی می‌شود
		$schedule = CMB_Settings::sanitize_schedule( $values );

		if ( is_wp_error( $schedule ) ) {
			$this->redirect( 'cmb-settings', $schedule->get_error_message(), 'error' );
		}

		$values = array_merge( $values, $schedule );

		CMB_Settings::update( $values );

		/* گزینه‌های ظاهر و دسترسی در wp_options ذخیره می‌شوند، نه در
		   تنظیمات افزونه — چون بعضی‌شان قبل از بارگذاری تنظیمات لازم‌اند. */
		update_option( 'cmb_persian_digits', isset( $_POST['cmb_persian_digits'] ) ? 1 : 0 );
		update_option( 'cmb_canvas', isset( $_POST['cmb_canvas'] ) ? 1 : 0 );

		if ( isset( $_POST['cmb_poster_cols'] ) ) {
			update_option( 'cmb_poster_cols', 2 === (int) $_POST['cmb_poster_cols'] ? 2 : 1 );
		}

		if ( isset( $_POST['cmb_login_url'] ) ) {
			update_option( 'cmb_login_url', esc_url_raw( wp_unslash( $_POST['cmb_login_url'] ) ) );
		}

		if ( isset( $_POST['cmb_font_url'] ) ) {
			update_option( 'cmb_font_url', esc_url_raw( wp_unslash( $_POST['cmb_font_url'] ) ) );
		}

		update_option( 'cmb_panel_pwa', isset( $_POST['cmb_panel_pwa'] ) ? 1 : 0 );

		if ( isset( $_POST['cmb_panel_app_name'] ) ) {
			update_option( 'cmb_panel_app_name', sanitize_text_field( wp_unslash( $_POST['cmb_panel_app_name'] ) ) );
		}

		/* عوض شدن نشانی اپ یعنی قواعد بازنویسی باید دوباره نوشته شوند،
		   وگرنه مسیر تازه ۴۰۴ می‌دهد. */
		if ( isset( $_POST['cmb_app_slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_POST['cmb_app_slug'] ) );
			$slug = $slug ? $slug : 'reserve';

			if ( $slug !== get_option( 'cmb_app_slug', 'reserve' ) ) {
				update_option( 'cmb_app_slug', $slug );
				delete_option( 'cmb_rewrite_stamp' );

				if ( function_exists( 'cmb_register_rewrites' ) ) {
					cmb_register_rewrites();
				}

				flush_rewrite_rules();
			}
		}

		// تلفن شعبه روی رکورد شعبه ذخیره می‌شود.
		if ( isset( $_POST['branch_phone'] ) ) {
			global $wpdb;

			$wpdb->update(
				cmb_table( 'branches' ),
				array( 'phone' => sanitize_text_field( wp_unslash( $_POST['branch_phone'] ) ) ),
				array( 'id' => CMB_Services::default_branch_id() )
			);
		}

		$this->redirect( 'cmb-settings', 'تنظیمات ذخیره شد.' );
	}

	public function handle_add_closure() {
		$this->guard( 'cmb_add_closure' );

		$date  = cmb_read_jalali_field( 'closure_date', $_POST ); // phpcs:ignore
		$block = sanitize_key( wp_unslash( $_POST['block_key'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$this->redirect( 'cmb-closures', 'تاریخ معتبر نیست.', 'error' );
		}

		if ( '' !== $block && ! array_key_exists( $block, cmb_blocks() ) ) {
			$this->redirect( 'cmb-closures', 'شیفت معتبر نیست.', 'error' );
		}

		$result = CMB_Availability::set_closure(
			CMB_Services::default_branch_id(),
			$date,
			$block,
			sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) )
		);

		if ( ! $result ) {
			$this->redirect( 'cmb-closures', 'این بازه قبلاً بسته شده است.', 'error' );
		}

		$this->redirect( 'cmb-closures', 'بازه با موفقیت بسته شد. نوبت‌های ثبت‌شده‌ی قبلی حذف نمی‌شوند؛ در صورت نیاز آن‌ها را لغو کنید.' );
	}

	public function handle_remove_closure() {
		$closure_id = isset( $_GET['closure_id'] ) ? (int) $_GET['closure_id'] : 0;

		$this->guard( 'cmb_remove_closure_' . $closure_id );

		CMB_Availability::remove_closure( $closure_id );

		$this->redirect( 'cmb-closures', 'بازه دوباره باز شد.' );
	}

	public function handle_update_booking() {
		$booking_id = isset( $_REQUEST['booking_id'] ) ? (int) $_REQUEST['booking_id'] : 0;

		$this->guard( 'cmb_update_booking_' . $booking_id );

		$new_status = sanitize_key( wp_unslash( $_REQUEST['status'] ?? '' ) );

		if ( 'delete' === $new_status ) {
			CMB_Bookings::delete( $booking_id );
			$this->redirect( 'cmb-bookings', 'نوبت حذف شد.' );
		}

		$result = CMB_Bookings::set_status( $booking_id, $new_status );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'cmb-bookings', $result->get_error_message(), 'error' );
		}

		$this->redirect( 'cmb-bookings', 'وضعیت نوبت به «' . cmb_status_label( $new_status ) . '» تغییر کرد.' );
	}

	public function handle_test_sms() {
		$this->guard( 'cmb_test_sms' );

		$phone = cmb_normalize_phone( wp_unslash( $_POST['test_phone'] ?? '' ) );

		if ( ! $phone ) {
			$this->redirect( 'cmb-settings', 'شماره‌ی آزمایشی معتبر نیست.', 'error' );
		}

		$credit  = CMB_SMS::get_credit();
		$message = is_wp_error( $credit )
			? 'بررسی اعتبار ناموفق: ' . $credit->get_error_message()
			: 'اعتبار پنل: ' . cmb_fa_num( number_format( (float) $credit ) );

		$result = CMB_SMS::send_event( $phone, 'pattern_otp', array( wp_rand( 10000, 99999 ) ), 'پیام آزمایشی چک موتور' );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'cmb-settings', $message . ' — ارسال ناموفق: ' . $result->get_error_message(), 'error' );
		}

		$this->redirect( 'cmb-settings', $message . ' — پیامک آزمایشی ارسال شد.' );
	}

	/**
	 * خروجی CSV نوبت‌ها (با BOM برای نمایش صحیح فارسی در اکسل).
	 */
	/**
	 * خنثی کردن تزریق فرمول در CSV.
	 *
	 * اکسل هر سلولی که با = + - @ شروع شود را فرمول می‌بیند و اجرا
	 * می‌کند. اسم و توضیحات را مشتری وارد می‌کند، پس یک نام مثل
	 * «=HYPERLINK(...)» روی کامپیوتر مدیر اجرا می‌شد.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی مجاز نیست.' );
		}

		check_admin_referer( 'cmb_export' );

		global $wpdb;

		$table = cmb_table( 'bookings' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY booking_date DESC, id DESC" ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=checkmotor-bookings-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		fwrite( $output, "\xEF\xBB\xBF" ); // BOM

		fputcsv( $output, array( 'کد پیگیری', 'تاریخ شمسی', 'تاریخ میلادی', 'شیفت', 'خدمت', 'نام', 'موبایل', 'شهر', 'نوع خودرو', 'نوع موتور', 'سال', 'کارکرد', 'وضعیت', 'ثبت' ) );

		foreach ( (array) $rows as $row ) {
			$service = CMB_Services::get_service( $row->service_id );

			fputcsv(
				$output,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
					$row->tracking_code,
					cmb_jalali_date( $row->booking_date, 'numeric' ),
					$row->booking_date,
					cmb_block_label( $row->block_key ),
					$service ? $service->title : '',
					$row->customer_name,
					$row->phone,
					isset( $row->city ) ? $row->city : '',
					$row->car_brand,
					$row->car_model,
					$row->car_year,
					$row->car_mileage,
					cmb_status_label( $row->status ),
					cmb_jalali_date( substr( (string) $row->created_at, 0, 10 ), 'numeric' )
						. ' ' . substr( (string) $row->created_at, 11, 5 ),
					)
				)
			);
		}

		fclose( $output );
		exit;
	}
}
