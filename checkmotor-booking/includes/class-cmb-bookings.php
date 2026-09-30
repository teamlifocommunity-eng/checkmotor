<?php
/**
 * ثبت و مدیریت نوبت‌ها.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Bookings {

	/**
	 * ثبت یک نوبت جدید.
	 *
	 * @param array $data داده‌های پاک‌سازی‌شده‌ی فرم.
	 *
	 * @return array|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$service_id = isset( $data['service_id'] ) ? (int) $data['service_id'] : 0;
		$service    = CMB_Services::get_service( $service_id );

		if ( ! $service ) {
			return new WP_Error( 'cmb_service_not_found', 'خدمت انتخاب‌شده یافت نشد.', array( 'status' => 400 ) );
		}

		$branch_id = (int) $service->branch_id;
		$date      = isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '';
		$block_key = isset( $data['block'] ) ? sanitize_key( $data['block'] ) : '';

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'cmb_not_logged_in', 'برای ثبت نوبت ابتدا با شماره‌ی موبایل خود وارد شوید.', array( 'status' => 401 ) );
		}

		$phone = cmb_get_user_phone( $user_id );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_no_phone', 'شماره‌ی موبایل حساب کاربری شما ثبت نشده است.', array( 'status' => 400 ) );
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( cmb_strlen( $name ) < 3 ) {
			return new WP_Error( 'cmb_bad_name', 'نام و نام خانوادگی را کامل وارد کنید.', array( 'status' => 400 ) );
		}

		/* در عمل دیده شد که بعضی کاربران شماره‌شان را در فیلد نام
		   می‌نویسند. آن رکورد در فهرست مدیریت بی‌معنی می‌شود، پس
		   همین‌جا جلویش گرفته می‌شود. */
		if ( preg_match( '/^[0-9+\s\-()]{6,}$/', cmb_en_num( $name ) ) ) {
			return new WP_Error( 'cmb_name_is_phone', 'در این فیلد نام و نام خانوادگی را بنویسید، نه شماره.', array( 'status' => 400 ) );
		}

		$car_brand   = isset( $data['car_brand'] ) ? sanitize_text_field( $data['car_brand'] ) : '';
		$car_model   = isset( $data['car_model'] ) ? sanitize_text_field( $data['car_model'] ) : '';
		$car_year    = isset( $data['car_year'] ) ? sanitize_text_field( cmb_en_num( $data['car_year'] ) ) : '';
		$car_mileage = isset( $data['car_mileage'] ) ? preg_replace( '/\D/', '', cmb_en_num( $data['car_mileage'] ) ) : '';
		$note        = isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '';

		$city = self::sanitize_city( isset( $data['city'] ) ? $data['city'] : '' );

		if ( is_wp_error( $city ) ) {
			return $city;
		}

		if ( '' === $car_brand || '' === $car_model ) {
			return new WP_Error( 'cmb_bad_car', 'نوع خودرو و نوع موتور الزامی است.', array( 'status' => 400 ) );
		}

		/* سال ساخت حالا از فهرست کشویی می‌آید، پس سرور هم همان فهرست
		   را می‌پذیرد. پیش از این هر عدد چهاررقمی قبول می‌شد — ۰۰۰۰ و
		   ۹۹۹۹ هم. */
		$years = cmb_car_years();

		if ( ! in_array( $car_year, $years, true ) ) {
			return new WP_Error(
				'cmb_bad_year',
				sprintf(
					'سال ساخت خودرو را از فهرست انتخاب کنید (%s تا %s).',
					cmb_fa_num( end( $years ) ),
					cmb_fa_num( reset( $years ) )
				),
				array( 'status' => 400 )
			);
		}

		// محدودیت نوبت‌های هم‌زمان (تعداد کل، و یکی از هر خدمت).
		$limit = self::check_active_limits( $user_id, $service );

		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$lock_name = 'cmb_slot_' . $branch_id . '_' . $date . '_' . $block_key;
		$locked    = self::acquire_lock( $lock_name );

		/* کش ظرفیت درون‌درخواستی است و ممکن است پیش از گرفتن قفل پر
		   شده باشد. شمارش ظرفیت باید حتماً تازه باشد، وگرنه دو رزرو
		   هم‌زمان می‌توانند از سقف رد شوند. */
		CMB_Availability::flush_cache();

		$valid = CMB_Availability::validate_slot( $service, $date, $block_key, $branch_id );

		if ( is_wp_error( $valid ) ) {
			self::release_lock( $lock_name, $locked );
			return $valid;
		}

		$now   = current_time( 'mysql' );
		$table = cmb_table( 'bookings' );
		$code  = cmb_generate_tracking_code();

		$row = array(
				'tracking_code' => $code,
				'branch_id'     => $branch_id,
				'service_id'    => (int) $service->id,
				'user_id'       => $user_id,
				'booking_date'  => $date,
				'block_key'     => $block_key,
				'customer_name' => $name,
				'phone'         => $phone,
				'car_brand'     => $car_brand,
				'car_model'     => $car_model,
				'car_year'      => $car_year,
				'car_mileage'   => $car_mileage,
				'note'          => $note,
				'status'        => 'confirmed',
				'reminder_sent' => 0,
				'ip'            => cmb_get_ip(),
				'created_at'    => $now,
				'updated_at'    => $now,
		);

		// پیش از مهاجرت، ستون شهر وجود ندارد و نوشتنش کل ثبت را خراب می‌کرد
		if ( cmb_has_column( 'bookings', 'city' ) ) {
			$row['city'] = $city;
		}

		$inserted = $wpdb->insert( $table, $row );

		self::release_lock( $lock_name, $locked );

		if ( ! $inserted ) {
			return new WP_Error( 'cmb_db_error', 'ثبت نوبت ناموفق بود. لطفاً دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		/* نام نمایشی فقط وقتی پر می‌شود که حساب هنوز نام واقعی ندارد.
		   قبلاً هر رزرو آن را بازنویسی می‌کرد: اگر کسی در فیلد نام
		   شماره‌اش را می‌نوشت، نام حسابش برای همیشه شماره می‌شد، و اگر
		   دو نفر از یک حساب استفاده می‌کردند نام مدام عوض می‌شد. */
		$me = get_userdata( $user_id );

		if ( $name && $me ) {
			$current = (string) $me->display_name;
			$is_placeholder = ( '' === $current || $current === $phone || $current === $me->user_login );

			// نامی که خودش شماره است، نام نیست.
			$name_is_phone = (bool) preg_match( '/^[0-9+\s-]{6,}$/', $name );

			if ( $is_placeholder && ! $name_is_phone ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $name,
					)
				);
			}
		}

		$booking = self::get( $booking_id );

		do_action( 'cmb_booking_created', $booking_id, $booking );

		self::notify_customer( $booking );
		self::notify_admin( $booking );

		return self::to_array( $booking );
	}

	/**
	 * دریافت یک نوبت.
	 */
	public static function get( $booking_id ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $booking_id ) ); // phpcs:ignore
	}

	/**
	 * دریافت نوبت بر اساس کد پیگیری.
	 */
	public static function get_by_code( $code ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_code = %s", $code ) ); // phpcs:ignore
	}

	/**
	 * نوبت‌های یک کاربر.
	 */
	public static function get_user_bookings( $user_id, $limit = 20 ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY booking_date DESC, id DESC LIMIT %d", // phpcs:ignore
				(int) $user_id,
				(int) $limit
			)
		);
	}

	/**
	 * تعداد نوبت‌های فعال (آینده و تاییدشده) یک کاربر.
	 */
	/**
	 * نوبت‌های فعال کاربر.
	 *
	 * «فعال» یعنی نوبتی که هنوز نرسیده. پیش از این شرط فقط
	 * booking_date >= امروز بود، یعنی نوبتِ ساعت ۱۰ صبحِ امروز تا
	 * نیمه‌شب همان روز «فعال» می‌ماند. نتیجه‌اش بن‌بست بود: مشتری
	 * ساعت ۲۳ نه می‌توانست لغو کند (مهلت لغو خیلی قبل تمام شده) و نه
	 * نوبت تازه بگیرد («شما ۱ نوبت فعال دارید»)، در حالی که وقت
	 * مراجعه‌اش سیزده ساعت قبل گذشته بود.
	 *
	 * حالا به‌محض شروع شیفت، آن نوبت دیگر جلوی رزرو تازه را نمی‌گیرد.
	 *
	 * @return array ردیف‌های نوبت، از نزدیک‌ترین به دورترین.
	 */
	public static function active_bookings_for_user( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return array();
		}

		$table = cmb_table( 'bookings' );
		$today = cmb_today();
		$now   = cmb_now();

		// شیفت‌هایی از امروز که هنوز شروع نشده‌اند
		$upcoming_today = array();

		foreach ( cmb_blocks() as $key => $block ) {
			$start = DateTime::createFromFormat( '!Y-m-d H:i', $today . ' ' . $block['start'], cmb_timezone() );

			if ( $start && $start > $now ) {
				$upcoming_today[] = $key;
			}
		}

		$sql    = "SELECT * FROM {$table} WHERE user_id = %d AND status = 'confirmed' AND ( booking_date > %s";
		$params = array( $user_id, $today );

		if ( $upcoming_today ) {
			$holders = implode( ',', array_fill( 0, count( $upcoming_today ), '%s' ) );
			$sql    .= " OR ( booking_date = %s AND block_key IN ({$holders}) )";
			$params[] = $today;
			$params  = array_merge( $params, $upcoming_today );
		}

		$sql .= ' ) ORDER BY booking_date ASC, block_key ASC';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * شهرِ واردشده را پاک‌سازی و بررسی می‌کند.
	 *
	 * · فاصله‌های اضافه یکی می‌شوند، تا «بندر  ریگ» و «بندر ریگ» در
	 *   گزارش‌ها دو شهر حساب نشوند.
	 * · «ي» و «ك» عربی به «ی» و «ک» فارسی تبدیل می‌شوند — صفحه‌کلیدهای
	 *   عربی هنوز رایج‌اند و بدون این، «کنگان» دو شکل داشت.
	 * · شماره (چهار رقم یا بیشتر) پذیرفته نمی‌شود؛ همان اشتباهی که در
	 *   فیلد نام هم دیده شد.
	 *
	 * @return string|WP_Error
	 */
	public static function sanitize_city( $raw ) {
		$city = sanitize_text_field( (string) $raw );
		$city = strtr( $city, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک' ) );
		$city = trim( preg_replace( '/\s+/u', ' ', $city ) );

		if ( cmb_strlen( $city ) < 2 ) {
			return new WP_Error( 'cmb_bad_city', 'شهر محل سکونت را وارد کنید.', array( 'status' => 400 ) );
		}

		if ( preg_match( '/\d{4,}/', cmb_en_num( $city ) ) ) {
			return new WP_Error( 'cmb_bad_city', 'در فیلد شهر، نام شهر را بنویسید نه شماره.', array( 'status' => 400 ) );
		}

		return cmb_substr( $city, 0, 100 );
	}

	/**
	 * آیا این کاربر می‌تواند نوبت تازه‌ای برای این خدمت بگیرد؟
	 *
	 * دو قاعده، هر دو از تنظیمات:
	 *
	 *   · حداکثر نوبت فعال هم‌زمان (max_active_per_user) — سقف کل.
	 *
	 *   · یک نوبت از هر خدمت (one_per_service) — دو نوبتِ فعالِ یک
	 *     خدمت هم‌زمان ممکن نیست. با سقف ۲ و این گزینه، مشتری
	 *     می‌تواند یک تنظیم موتور و یک تعویض روغن داشته باشد، ولی دو
	 *     تنظیم موتور نه. با سقف ۱ این گزینه اثری ندارد.
	 *
	 * قاعده‌ی خدمت اول بررسی می‌شود، چون دلیل دقیق‌تری می‌دهد: اگر
	 * مشتری همین خدمت را دارد، گفتنِ «سقف پر است» او را گمراه می‌کند.
	 *
	 * @return true|WP_Error
	 */
	public static function check_active_limits( $user_id, $service ) {
		$max_active  = (int) CMB_Settings::get( 'max_active_per_user', 1 );
		$per_service = (bool) CMB_Settings::get( 'one_per_service', 1 );

		if ( $max_active < 1 && ! $per_service ) {
			return true;
		}

		$active = self::active_bookings_for_user( $user_id );

		if ( ! $active ) {
			return true;
		}

		if ( $per_service && $service ) {
			foreach ( $active as $row ) {
				if ( (int) $row->service_id !== (int) $service->id ) {
					continue;
				}

				$message = sprintf(
					'شما برای «%s» در %s (%s) نوبت فعال دارید. از هر خدمت هم‌زمان فقط یک نوبت می‌شود داشت؛',
					$service->title,
					cmb_jalali_date( $row->booking_date, 'full' ),
					cmb_block_label( $row->block_key )
				);

				$message .= true === self::can_user_cancel( $row, $user_id )
					? ' خدمت دیگری انتخاب کنید، یا اگر می‌خواهید زمانش را عوض کنید اول آن نوبت را از «نوبت‌های من» لغو کنید.'
					: ' خدمت دیگری انتخاب کنید، یا برای تغییر آن نوبت با شعبه تماس بگیرید.';

				return new WP_Error( 'cmb_same_service', $message, array( 'status' => 409 ) );
			}
		}

		if ( $max_active > 0 && count( $active ) >= $max_active ) {
			$first = $active[0];

			$message = 1 === $max_active
				? sprintf(
					'شما برای %s (%s) نوبت فعال دارید.',
					cmb_jalali_date( $first->booking_date, 'full' ),
					cmb_block_label( $first->block_key )
				)
				: sprintf(
					'شما %s نوبت فعال دارید که سقف مجاز است. نزدیک‌ترینش %s (%s) است.',
					cmb_fa_num( count( $active ) ),
					cmb_jalali_date( $first->booking_date, 'full' ),
					cmb_block_label( $first->block_key )
				);

			$message .= true === self::can_user_cancel( $first, $user_id )
				? ( 1 === $max_active
					? ' برای گرفتن نوبت تازه، اول آن را از بخش «نوبت‌های من» لغو کنید.'
					: ' برای گرفتن نوبت تازه، اول یکی را از بخش «نوبت‌های من» لغو کنید.' )
				: ' برای تغییر یا لغو با شعبه تماس بگیرید.';

			return new WP_Error( 'cmb_active_limit', $message, array( 'status' => 409 ) );
		}

		return true;
	}

	public static function count_active_for_user( $user_id ) {
		return count( self::active_bookings_for_user( $user_id ) );
	}

	/* --------------------------------------------------------------------
	 * لغو نوبت توسط خود مشتری
	 * ----------------------------------------------------------------- */

	/**
	 * لحظه‌ی شروع شیفتِ یک نوبت، به وقت محلی.
	 *
	 * @return DateTime|null
	 */
	public static function slot_datetime( $booking ) {
		if ( ! $booking || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $booking->booking_date ) ) {
			return null;
		}

		$start = (string) cmb_block_start( $booking->block_key );

		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $start ) ) {
			$start = '00:00';
		}

		/* علامت «!» یعنی ثانیه و بقیه‌ی اجزای پارس‌نشده صفر شوند؛
		   بدون آن، ثانیه‌ی همین لحظه داخل تاریخ می‌نشیند. */
		$dt = DateTime::createFromFormat(
			'!Y-m-d H:i',
			$booking->booking_date . ' ' . $start,
			cmb_timezone()
		);

		return $dt ? $dt : null;
	}

	/**
	 * آخرین لحظه‌ای که مشتری می‌تواند خودش نوبت را لغو کند.
	 *
	 * @return DateTime|null
	 */
	public static function cancel_deadline( $booking ) {
		$slot = self::slot_datetime( $booking );

		if ( ! $slot ) {
			return null;
		}

		$hours = max( 0, (int) CMB_Settings::get( 'cancel_deadline_hours', 24 ) );

		if ( $hours > 0 ) {
			$slot->modify( '-' . $hours . ' hour' );
		}

		return $slot;
	}

	/**
	 * آیا زمان این نوبت گذشته است؟
	 *
	 * ملاک لحظه‌ی شروع شیفت است، نه فقط تاریخ — نوبتِ ساعت ۱۰ صبح در
	 * ساعت ۲۳ همان روز گذشته حساب می‌شود.
	 */
	public static function is_past( $booking ) {
		$slot = self::slot_datetime( $booking );

		if ( ! $slot ) {
			return false;
		}

		return $slot < cmb_now();
	}

	/**
	 * آیا این کاربر همین حالا می‌تواند این نوبت را لغو کند؟
	 *
	 * @return true|WP_Error
	 */
	public static function can_user_cancel( $booking, $user_id ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		if ( ! CMB_Settings::get( 'cancel_enabled', 1 ) ) {
			return new WP_Error( 'cmb_cancel_off', 'لغو آنلاین نوبت فعال نیست. لطفاً با شعبه تماس بگیرید.', array( 'status' => 403 ) );
		}

		if ( (int) $booking->user_id !== (int) $user_id ) {
			return new WP_Error( 'cmb_not_owner', 'این نوبت متعلق به حساب شما نیست.', array( 'status' => 403 ) );
		}

		if ( 'cancelled' === $booking->status ) {
			return new WP_Error( 'cmb_already_cancelled', 'این نوبت قبلاً لغو شده است.', array( 'status' => 409 ) );
		}

		if ( 'confirmed' !== $booking->status ) {
			return new WP_Error( 'cmb_not_cancellable', 'این نوبت دیگر قابل لغو نیست.', array( 'status' => 409 ) );
		}

		$deadline = self::cancel_deadline( $booking );

		if ( ! $deadline ) {
			return new WP_Error( 'cmb_bad_slot', 'زمان این نوبت قابل خواندن نیست. با شعبه تماس بگیرید.', array( 'status' => 409 ) );
		}

		$now = cmb_now();

		if ( $now > $deadline ) {
			$hours = max( 0, (int) CMB_Settings::get( 'cancel_deadline_hours', 24 ) );

			return new WP_Error(
				'cmb_cancel_late',
				$hours > 0
					? sprintf( 'مهلت لغو آنلاین گذشته است؛ لغو تا %s ساعت پیش از شروع شیفت ممکن بود. لطفاً با شعبه تماس بگیرید.', cmb_fa_num( $hours ) )
					: 'زمان این نوبت گذشته است. لطفاً با شعبه تماس بگیرید.',
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * متن مهلت لغو، برای نمایش کنار نوبت.
	 */
	public static function cancel_hint( $booking ) {
		$deadline = self::cancel_deadline( $booking );

		if ( ! $deadline ) {
			return '';
		}

		/* وقتی خودِ زمان نوبت گذشته، نمایش «مهلت لغو» بی‌معنی است و
		   مشتری را دنبال دکمه‌ای می‌فرستد که نیست. */
		if ( self::is_past( $booking ) ) {
			return 'زمان این نوبت گذشته است.';
		}

		/* «short» یعنی «۲۳ شهریور» بدون سال و روز هفته: این متن زیر
		   کارت نوبت می‌نشیند و تاریخ کاملِ خودِ نوبت دو سطر بالاترش
		   نوشته شده — تکرار سال فقط سطر را شلوغ می‌کند.
		   ارقام را هم دست نمی‌زنیم؛ تبدیل به فارسی در جاوااسکریپت و
		   بر اساس تنظیم سایت انجام می‌شود. */
		return sprintf(
			'مهلت لغو: %s، ساعت %s',
			cmb_jalali_date( $deadline->format( 'Y-m-d' ), 'short' ),
			$deadline->format( 'H:i' )
		);
	}

	/**
	 * لغو نوبت به‌درخواست خود مشتری.
	 *
	 * @return array|WP_Error نوبتِ به‌روزشده.
	 */
	public static function cancel_by_user( $booking_id, $user_id, $reason = '' ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		$allowed = self::can_user_cancel( $booking, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$fields = array(
			'status'     => 'cancelled',
			'updated_at' => current_time( 'mysql' ),
		);

		/* ستون‌های ردیابی در نسخه‌ی ۱.۱.۰ اسکیما اضافه شده‌اند. اگر سایت
		   هنوز مهاجرت نکرده باشد (مثلاً مدیر بعد از به‌روزرسانی سری به
		   پیشخوان نزده)، لغو باید باز هم کار کند — فقط بدون ردیابی. */
		if ( self::has_cancel_columns() ) {
			$fields['cancelled_by'] = 'customer';
			$fields['cancelled_at'] = current_time( 'mysql' );
		}

		$updated = $wpdb->update( cmb_table( 'bookings' ), $fields, array( 'id' => (int) $booking->id ) );

		if ( false === $updated ) {
			return new WP_Error( 'cmb_db_error', 'لغو نوبت ناموفق بود. لطفاً دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		/* ظرفیت شیفت بلافاصله آزاد می‌شود: شمارش ظرفیت فقط
		   confirmed و done را حساب می‌کند. کش درون‌درخواستی باید
		   خالی شود وگرنه همین درخواست عدد قدیمی را می‌بیند. */
		CMB_Availability::flush_cache();

		$booking = self::get( $booking->id );

		cmb_log( 'Booking cancelled by customer', array( 'booking' => (int) $booking->id, 'user' => (int) $user_id ) );

		do_action( 'cmb_booking_status_changed', (int) $booking->id, 'cancelled', 'confirmed' );

		/**
		 * پس از لغو نوبت توسط خود مشتری.
		 *
		 * @param int    $booking_id
		 * @param object $booking
		 * @param string $reason
		 */
		do_action( 'cmb_booking_cancelled_by_user', (int) $booking->id, $booking, $reason );

		// اطلاع‌رسانی: هم به مشتری (رسید لغو) هم به شعبه.
		self::notify_cancel( $booking, 'customer' );
		self::notify_admin_cancel( $booking );

		return self::to_array( $booking );
	}

	/**
	 * آیا ستون‌های ردیابی لغو در جدول وجود دارند؟
	 *
	 * نتیجه کش می‌شود تا روی هر لغو یک SHOW COLUMNS زده نشود.
	 */
	public static function has_cancel_columns() {
		global $wpdb;

		$cached = get_transient( 'cmb_has_cancel_cols' );

		if ( 'yes' === $cached ) {
			return true;
		}

		if ( 'no' === $cached ) {
			return false;
		}

		$table = cmb_table( 'bookings' );
		$found = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'cancelled_by' ) ); // phpcs:ignore

		$has = ! empty( $found );

		set_transient( 'cmb_has_cancel_cols', $has ? 'yes' : 'no', $has ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );

		return $has;
	}

	/**
	 * تغییر وضعیت نوبت.
	 */
	public static function set_status( $booking_id, $status ) {
		global $wpdb;

		if ( ! array_key_exists( $status, cmb_statuses() ) ) {
			return new WP_Error( 'cmb_bad_status', 'وضعیت معتبر نیست.' );
		}

		$booking = self::get( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.' );
		}

		$fields = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( 'cancelled' === $status && self::has_cancel_columns() ) {
			$fields['cancelled_by'] = 'branch';
			$fields['cancelled_at'] = current_time( 'mysql' );
		}

		$wpdb->update( cmb_table( 'bookings' ), $fields, array( 'id' => (int) $booking_id ) );

		// ظرفیت شیفت با لغو آزاد می‌شود؛ شمارشِ کش‌شده باید کهنه نماند.
		CMB_Availability::flush_cache();

		do_action( 'cmb_booking_status_changed', $booking_id, $status, $booking->status );

		if ( 'cancelled' === $status && 'cancelled' !== $booking->status ) {
			self::notify_cancel( self::get( $booking_id ), 'branch' );
		}

		return true;
	}

	/**
	 * حذف کامل یک نوبت.
	 */
	public static function delete( $booking_id ) {
		global $wpdb;

		return $wpdb->delete( cmb_table( 'bookings' ), array( 'id' => (int) $booking_id ) );
	}

	/**
	 * خروجی آرایه‌ای برای REST و صفحه‌ی تاییدیه.
	 */
	public static function to_array( $booking ) {
		if ( ! $booking ) {
			return null;
		}

		$service = CMB_Services::get_service( $booking->service_id );
		$branch  = CMB_Services::get_branch( $booking->branch_id );

		return array(
			'id'           => (int) $booking->id,
			'code'         => $booking->tracking_code,
			'service'      => $service ? $service->title : '',
			'servicePrice' => $service ? cmb_fa_num( number_format( (int) $service->price ) ) . ' تومان' : '',
			'branch'       => $branch ? $branch->title : '',
			'branchPhone'  => $branch ? $branch->phone : '',
			'date'         => $booking->booking_date,
			'dateFa'       => cmb_jalali_date( $booking->booking_date, 'full' ),
			'block'        => $booking->block_key,
			'blockLabel'   => cmb_block_label( $booking->block_key ),
			'blockStart'   => cmb_fa_num( cmb_block_start( $booking->block_key ) ),
			'name'         => $booking->customer_name,
			'phone'        => $booking->phone,
			'phoneFa'      => cmb_fa_num( $booking->phone ),
			/* «۲۰۷ · TU5 · ۱۳۹۸» خواناتر از سرهم نوشتن است، حالا که این
			   سه تا سه چیز متفاوت‌اند و نه یک نام خودرو. */
			'city'         => isset( $booking->city ) ? (string) $booking->city : '',
			'car'          => implode(
				' · ',
				array_filter( array( $booking->car_brand, $booking->car_model, $booking->car_year ) )
			),
			'status'       => $booking->status,
			'statusLabel'  => cmb_status_label( $booking->status ),
			'note'         => CMB_Settings::get( 'confirm_note', '' ),
			'lateRule'     => CMB_Settings::get( 'late_rule_note', '' ),
			/* لغو: تصمیم همین‌جا گرفته می‌شود، نه در جاوااسکریپت.
			   کلاینت فقط دکمه را نشان می‌دهد؛ سرور دوباره خودش
			   بررسی می‌کند. */
			'canCancel'    => (bool) ( get_current_user_id()
				&& true === self::can_user_cancel( $booking, get_current_user_id() ) ),
			'cancelHint'   => 'confirmed' === $booking->status ? self::cancel_hint( $booking ) : '',
			'isPast'       => self::is_past( $booking ),
		);
	}

	/* --------------------------------------------------------------------
	 * اطلاع‌رسانی پیامکی
	 * ----------------------------------------------------------------- */

	/**
	 * پیامک تاییدیه برای مشتری.
	 *
	 * متغیرهای پترن به ترتیب:
	 *  {0} نام مشتری، {1} نام خدمت، {2} تاریخ شمسی، {3} شیفت، {4} ساعت شروع، {5} کد پیگیری
	 */
	public static function notify_customer( $booking ) {
		if ( ! $booking ) {
			return;
		}

		$args = array(
			$booking->customer_name,
			self::service_title( $booking ),
			cmb_jalali_date( $booking->booking_date, 'numeric' ),
			cmb_block_label( $booking->block_key ),
			cmb_block_start( $booking->block_key ),
			$booking->tracking_code,
		);

		$fallback = sprintf(
			"چک موتور\nنوبت شما ثبت شد.\nخدمت: %s\nتاریخ: %s\n%s (ساعت %s)\nکد پیگیری: %s",
			$args[1],
			$args[2],
			$args[3],
			$args[4],
			$args[5]
		);

		$result = CMB_SMS::send_event( $booking->phone, 'pattern_booking', $args, $fallback );

		if ( is_wp_error( $result ) ) {
			cmb_log( 'Customer confirmation SMS failed: ' . $result->get_error_message(), array( 'booking' => $booking->id ) );
		}
	}

	/**
	 * پیامک اطلاع‌رسانی به مدیر/کارفرما.
	 *
	 * متغیرهای پترن: {0} نام خدمت، {1} تاریخ، {2} شیفت، {3} نام مشتری، {4} شماره مشتری
	 */
	public static function notify_admin( $booking ) {
		if ( ! $booking || ! CMB_Settings::get( 'admin_sms_enabled', 1 ) ) {
			return;
		}

		$phones = CMB_Settings::admin_phones();

		if ( empty( $phones ) ) {
			return;
		}

		$args = array(
			self::service_title( $booking ),
			cmb_jalali_date( $booking->booking_date, 'numeric' ),
			cmb_block_label( $booking->block_key ),
			$booking->customer_name,
			$booking->phone,
		);

		$fallback = sprintf(
			"نوبت جدید چک موتور\n%s\n%s - %s\nمشتری: %s%s\n%s\nخودرو: %s · موتور: %s · %s",
			$args[0],
			$args[1],
			$args[2],
			$args[3],
			! empty( $booking->city ) ? ' (' . $booking->city . ')' : '',
			$args[4],
			$booking->car_brand,
			$booking->car_model,
			$booking->car_year
		);

		foreach ( $phones as $phone ) {
			$result = CMB_SMS::send_event( $phone, 'pattern_admin', $args, $fallback );

			if ( is_wp_error( $result ) ) {
				cmb_log( 'Admin SMS failed: ' . $result->get_error_message(), array( 'phone' => $phone ) );
			}
		}
	}

	/**
	 * پیامک یادآوری یک روز قبل.
	 *
	 * متغیرهای پترن: {0} نام مشتری، {1} نام خدمت، {2} تاریخ، {3} شیفت، {4} ساعت
	 */
	public static function notify_reminder( $booking ) {
		if ( ! $booking ) {
			return false;
		}

		$args = array(
			$booking->customer_name,
			self::service_title( $booking ),
			cmb_jalali_date( $booking->booking_date, 'numeric' ),
			cmb_block_label( $booking->block_key ),
			cmb_block_start( $booking->block_key ),
		);

		$fallback = sprintf(
			"یادآوری نوبت چک موتور\n%s عزیز، نوبت شما فردا %s، %s ساعت %s است.",
			$args[0],
			$args[2],
			$args[3],
			$args[4]
		);

		$result = CMB_SMS::send_event( $booking->phone, 'pattern_reminder', $args, $fallback );

		if ( is_wp_error( $result ) ) {
			cmb_log( 'Reminder SMS failed: ' . $result->get_error_message(), array( 'booking' => $booking->id ) );
			return false;
		}

		return true;
	}

	/**
	 * پیامک لغو نوبت توسط مدیریت.
	 */
	public static function notify_cancel( $booking, $by = 'branch' ) {
		if ( ! $booking ) {
			return;
		}

		$args = array(
			$booking->customer_name,
			self::service_title( $booking ),
			cmb_jalali_date( $booking->booking_date, 'numeric' ),
			cmb_block_label( $booking->block_key ),
		);

		/* متن جایگزین (حالت ارسال ساده) باید با واقعیت جور باشد:
		   وقتی خود مشتری لغو کرده، «با شعبه تماس بگیرید» گیج‌کننده است. */
		$fallback = 'customer' === $by
			? sprintf(
				"چک موتور\n%s عزیز، نوبت شما در تاریخ %s (%s) به درخواست خودتان لغو شد.\nکد پیگیری: %s",
				$args[0],
				$args[2],
				$args[3],
				$booking->tracking_code
			)
			: sprintf(
				"چک موتور\n%s عزیز، نوبت شما در تاریخ %s (%s) لغو شد. لطفاً با شعبه تماس بگیرید.",
				$args[0],
				$args[2],
				$args[3]
			);

		CMB_SMS::send_event( $booking->phone, 'pattern_cancel', $args, $fallback );
	}

	/**
	 * پیامک اطلاع به شعبه وقتی مشتری خودش نوبت را لغو می‌کند.
	 *
	 * وقتی مسئول رزرو خودش لغو می‌کند این ارسال نمی‌شود — او خبر دارد.
	 *
	 * متغیرهای پترن: {0} نام خدمت، {1} تاریخ، {2} شیفت، {3} نام مشتری، {4} شماره مشتری
	 */
	public static function notify_admin_cancel( $booking ) {
		if ( ! $booking || ! CMB_Settings::get( 'admin_sms_enabled', 1 ) ) {
			return;
		}

		$phones = CMB_Settings::admin_phones();

		if ( empty( $phones ) ) {
			return;
		}

		$args = array(
			self::service_title( $booking ),
			cmb_jalali_date( $booking->booking_date, 'numeric' ),
			cmb_block_label( $booking->block_key ),
			$booking->customer_name,
			$booking->phone,
		);

		$fallback = sprintf(
			"لغو نوبت چک موتور\nمشتری نوبتش را لغو کرد.\n%s\n%s - %s\nمشتری: %s\n%s\nکد: %s",
			$args[0],
			$args[1],
			$args[2],
			$args[3],
			$args[4],
			$booking->tracking_code
		);

		foreach ( $phones as $phone ) {
			$result = CMB_SMS::send_event( $phone, 'pattern_admin_cancel', $args, $fallback );

			if ( is_wp_error( $result ) ) {
				cmb_log( 'Admin cancel SMS failed: ' . $result->get_error_message(), array( 'phone' => $phone ) );
			}
		}
	}

	protected static function service_title( $booking ) {
		$service = CMB_Services::get_service( $booking->service_id );

		return $service ? $service->title : '';
	}

	/* --------------------------------------------------------------------
	 * قفل هم‌زمانی برای جلوگیری از رزرو بیش از ظرفیت
	 * ----------------------------------------------------------------- */

	protected static function acquire_lock( $name ) {
		global $wpdb;

		$key = substr( md5( $name ), 0, 40 );

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) ) === 1;
	}

	protected static function release_lock( $name, $acquired ) {
		global $wpdb;

		if ( ! $acquired ) {
			return;
		}

		$key = substr( md5( $name ), 0, 40 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); // phpcs:ignore
	}
}
