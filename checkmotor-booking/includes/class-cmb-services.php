<?php
/**
 * مدیریت خدمات و شعب.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Services {

	/**
	 * شناسه‌ی شعبه‌ی پیش‌فرض (فعلاً بوشهر).
	 */
	public static function default_branch_id() {
		$id = (int) get_option( 'cmb_default_branch_id', 0 );

		if ( $id ) {
			return $id;
		}

		global $wpdb;
		$table = cmb_table( 'branches' );
		$id    = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1" ); // phpcs:ignore

		if ( $id ) {
			update_option( 'cmb_default_branch_id', $id );
		}

		return $id;
	}

	/**
	 * کش درون‌درخواستی شعبه.
	 *
	 * to_array() هر نوبت یک بار get_branch() می‌زد؛ در فهرست «نوبت‌های
	 * من» با ده ردیف یعنی ده کوئری کاملاً یکسان. شعبه در طول یک
	 * درخواست عوض نمی‌شود.
	 */
	protected static $branch_cache = array();

	/**
	 * اطلاعات یک شعبه.
	 */
	public static function get_branch( $branch_id = 0 ) {
		global $wpdb;

		$branch_id = $branch_id ? (int) $branch_id : self::default_branch_id();

		if ( array_key_exists( $branch_id, self::$branch_cache ) ) {
			return self::$branch_cache[ $branch_id ];
		}

		$table = cmb_table( 'branches' );

		self::$branch_cache[ $branch_id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $branch_id ) ); // phpcs:ignore

		return self::$branch_cache[ $branch_id ];
	}

	/**
	 * فهرست شعب.
	 */
	public static function get_branches( $only_active = true ) {
		global $wpdb;

		$table = cmb_table( 'branches' );
		$where = $only_active ? 'WHERE is_active = 1' : '';

		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC" ); // phpcs:ignore
	}

	/**
	 * فهرست خدمات یک شعبه.
	 *
	 * @return array[] آرایه‌ای از آبجکت‌های خدمت.
	 */
	public static function get_services( $branch_id = 0, $only_active = true ) {
		global $wpdb;

		$branch_id = $branch_id ? (int) $branch_id : self::default_branch_id();
		$table     = cmb_table( 'services' );

		$sql = "SELECT * FROM {$table} WHERE branch_id = %d";

		if ( $only_active ) {
			$sql .= ' AND is_active = 1';
		}

		$sql .= ' ORDER BY sort_order ASC, id ASC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $branch_id ) ); // phpcs:ignore
	}

	/**
	 * کش درون‌درخواستی خدمات.
	 *
	 * هر ردیف نوبت برای گرفتن عنوان خدمت یک کوئری جدا می‌زد؛ در فهرستی
	 * با ۲۵ ردیف یعنی ۲۵ کوئری اضافه، در حالی که خدمات چند تا بیشتر
	 * نیستند و همه‌شان با یک کوئری خوانده می‌شوند.
	 */
	protected static $service_cache = null;

	/**
	 * شناسه‌ی شعبه‌ای که واقعاً وجود دارد.
	 *
	 * شناسه از پارامتر درخواست می‌آید و مستقیم داخل کلید کش می‌نشست؛
	 * یعنی هر کس با /services?branch_id=1..9999 می‌توانست هزار ترنزینت
	 * بی‌مصرف در جدول options بسازد. حالا شناسه‌ی ناموجود به صفر
	 * (شعبه‌ی پیش‌فرض) برمی‌گردد، پس مجموعه‌ی کلیدها محدود و معلوم است.
	 */
	public static function resolve_branch_id( $branch_id ) {
		$branch_id = max( 0, (int) $branch_id );

		if ( 0 === $branch_id ) {
			return 0;
		}

		return self::get_branch( $branch_id ) ? $branch_id : 0;
	}

	/**
	 * داده‌ی مشترک «شعبه + خدمات + یادداشت‌ها».
	 *
	 * هم پاسخ /services است، هم داده‌ی تزریق‌شده در خود صفحه. پیش از
	 * این دو نسخه‌ی جدا از همین ساختار وجود داشت (یکی در REST، یکی در
	 * فایل اصلی) که با هر تغییر باید هر دو دست می‌خورد.
	 */
	public static function payload( $branch_id = 0 ) {
		$branch_id = self::resolve_branch_id( $branch_id );
		$key       = 'cmb_services_' . $branch_id;
		$cached    = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$branch   = self::get_branch( $branch_id );
		$services = array();

		foreach ( self::get_services( $branch_id ) as $service ) {
			$services[] = self::to_array( $service );
		}

		$payload = array(
			'branch'   => $branch ? array(
				'id'      => (int) $branch->id,
				'title'   => $branch->title,
				'phone'   => $branch->phone,
				'address' => $branch->address,
			) : null,
			'services' => $services,
			'notes'    => array(
				'ecu'         => CMB_Settings::get( 'ecu_note', '' ),
				'outOfWindow' => CMB_Settings::get( 'out_of_window_note', '' ),
			),
		);

		set_transient( $key, $payload, 12 * HOUR_IN_SECONDS );

		return $payload;
	}

	public static function flush_cache() {
		self::$service_cache = null;
		self::$branch_cache  = array();

		/* کش پاسخ REST هم باید باطل شود، وگرنه تغییرات تا ۱۲ ساعت دیده
		   نمی‌شود. پیش از این فقط کلید صفر و شعبه‌ی پیش‌فرض پاک می‌شد،
		   پس روی سایت چندشعبه‌ای تغییر خدمات یک شعبه‌ی غیرپیش‌فرض
		   نیم روز دیده نمی‌شد. */
		delete_transient( 'cmb_services_0' );

		foreach ( self::get_branches( false ) as $branch ) {
			delete_transient( 'cmb_services_' . (int) $branch->id );
		}
	}

	/**
	 * یک خدمت بر اساس شناسه.
	 */
	public static function get_service( $service_id ) {
		global $wpdb;

		$service_id = (int) $service_id;

		if ( null === self::$service_cache ) {
			$table = cmb_table( 'services' );
			$rows  = $wpdb->get_results( "SELECT * FROM {$table}" ); // phpcs:ignore

			self::$service_cache = array();

			foreach ( (array) $rows as $row ) {
				self::$service_cache[ (int) $row->id ] = $row;
			}
		}

		return isset( self::$service_cache[ $service_id ] ) ? self::$service_cache[ $service_id ] : null;
	}

	/**
	 * روزهای هفته‌ی مجاز یک خدمت به صورت آرایه‌ی عددی (0=یکشنبه … 6=شنبه).
	 *
	 * @return int[]
	 */
	public static function allowed_weekdays( $service ) {
		if ( is_numeric( $service ) ) {
			$service = self::get_service( $service );
		}

		if ( ! $service ) {
			return array();
		}

		$parts = array_filter( array_map( 'trim', explode( ',', (string) $service->allowed_weekdays ) ), 'strlen' );

		return array_map( 'intval', $parts );
	}

	/**
	 * آماده‌سازی خدمت برای خروجی JSON.
	 */
	public static function to_array( $service ) {
		if ( ! $service ) {
			return null;
		}

		$poster = self::poster_data( (int) $service->poster_id );

		return array(
			'id'          => (int) $service->id,
			'slug'        => $service->slug,
			'title'       => $service->title,
			'description' => $service->description,
			'price'       => (int) $service->price,
			'priceLabel'  => $service->price ? cmb_fa_num( number_format( (int) $service->price ) ) . ' تومان' : '',
			'duration'    => $service->duration_note,
			'poster'      => $poster['url'],
			'posterSet'   => $poster['srcset'],
			'posterFull'  => $poster['full'],
			'posterW'     => $poster['w'],
			'posterH'     => $poster['h'],
			'weekdays'    => self::allowed_weekdays( $service ),
			'weekdayText' => self::weekday_text( self::allowed_weekdays( $service ) ),
			'ownCapacity' => self::own_capacity( $service ),
		);
	}

	/**
	 * اطلاعات پوستر برای نمایش سبک.
	 *
	 * قبلاً همیشه نسخه‌ی large فرستاده می‌شد؛ برای پوستر پرتره یعنی
	 * حدود ۱۰۲۴×۱۵۳۶ و چند صد کیلوبایت که روی موبایل کند بود. حالا
	 * srcset می‌فرستیم تا مرورگر خودش کوچک‌ترین نسخه‌ی کافی را بگیرد،
	 * به‌علاوه ابعاد واقعی تا صفحه هنگام لود نپرد.
	 */
	public static function poster_data( $attachment_id ) {
		$empty = array( 'url' => '', 'srcset' => '', 'full' => '', 'w' => 0, 'h' => 0 );

		$attachment_id = (int) $attachment_id;

		if ( ! $attachment_id ) {
			return $empty;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'medium_large' );

		if ( ! $src ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
		}

		if ( ! $src ) {
			return $empty;
		}

		$full = wp_get_attachment_image_url( $attachment_id, 'large' );

		return array(
			'url'    => $src[0],
			'srcset' => (string) wp_get_attachment_image_srcset( $attachment_id, 'medium_large' ),
			'full'   => $full ? $full : $src[0],
			'w'      => (int) $src[1],
			'h'      => (int) $src[2],
		);
	}

	/**
	 * توضیح فارسی روزهای مجاز.
	 */
	public static function weekday_text( array $weekdays ) {
		$names = array(
			6 => 'شنبه',
			0 => 'یکشنبه',
			1 => 'دوشنبه',
			2 => 'سه‌شنبه',
			3 => 'چهارشنبه',
			4 => 'پنجشنبه',
			5 => 'جمعه',
		);

		$ordered = array();

		foreach ( array( 6, 0, 1, 2, 3, 4, 5 ) as $day ) {
			if ( in_array( $day, $weekdays, true ) ) {
				$ordered[] = $names[ $day ];
			}
		}

		return implode( '، ', $ordered );
	}

	/**
	 * به‌روزرسانی یک خدمت از پنل مدیریت.
	 */
	/* --------------------------------------------------------------------
	 * ظرفیت جداگانه
	 * ----------------------------------------------------------------- */

	/**
	 * ظرفیت جداگانه‌ی یک خدمت، اگر داشته باشد.
	 *
	 * خدمت‌ها دو نوع‌اند:
	 *
	 *   · اشتراکی (پیش‌فرض) — از ظرفیت اصلی شیفت کم می‌کنند. تنظیم
	 *     موتور و تقویت موتور از این دسته‌اند: هر نوبتشان یک جای
	 *     کامل از شیفت را می‌گیرد.
	 *
	 *   · جداگانه — سهمیه‌ی خودشان را در هر شیفت دارند و از ظرفیت
	 *     اصلی کم نمی‌کنند. برای کارهای کوتاه مثل تعویض روغن که ده
	 *     دقیقه طول می‌کشد و نباید یک جای کامل شیفت را اشغال کند.
	 *
	 * ذخیره به‌صورت JSON است: {"morning":15,"afternoon":10}. شیفتی که در
	 * آن نباشد یا صفر باشد، یعنی این خدمت در آن شیفت ارائه نمی‌شود.
	 *
	 * @return array|null آرایه‌ی «کلید شیفت => ظرفیت»، یا null برای اشتراکی.
	 */
	public static function own_capacity( $service ) {
		// پیش از مهاجرت، ستون وجود ندارد و همه‌ی خدمت‌ها اشتراکی‌اند.
		if ( ! $service || ! isset( $service->own_capacity ) || '' === (string) $service->own_capacity ) {
			return null;
		}

		$raw = json_decode( (string) $service->own_capacity, true );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$out = array();

		foreach ( array_keys( cmb_blocks() ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? max( 0, (int) $raw[ $key ] ) : 0;
		}

		return $out;
	}

	/**
	 * ورودی فرم را به رشته‌ی ذخیره‌شدنی تبدیل می‌کند.
	 *
	 * null یا آرایه‌ی خالی یعنی «اشتراکی»، که با رشته‌ی خالی ذخیره
	 * می‌شود. کلیدهای ناشناس دور ریخته می‌شوند.
	 */
	public static function encode_own_capacity( $input ) {
		if ( ! is_array( $input ) || empty( $input ) ) {
			return '';
		}

		$out = array();

		foreach ( array_keys( cmb_blocks() ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? max( 0, min( 999, (int) cmb_en_num( $input[ $key ] ) ) ) : 0;
		}

		// همه صفر یعنی خدمت هیچ‌جا قابل رزرو نیست؛ به‌جایش غیرفعالش کنید.
		if ( 0 === array_sum( $out ) ) {
			return '';
		}

		return wp_json_encode( $out );
	}

	/**
	 * آیا ستون own_capacity در جدول هست؟
	 *
	 * بدون این بررسی، ذخیره‌ی هر خدمت پیش از مهاجرت با «ستون
	 * ناشناخته» شکست می‌خورد — یعنی بعد از به‌روزرسانی، تا مدیر سری
	 * به پیشخوان نزند، هیچ خدمتی قابل ویرایش نبود.
	 */
	public static function has_own_capacity_column() {
		global $wpdb;

		$cached = get_transient( 'cmb_has_owncap_col' );

		if ( 'yes' === $cached ) {
			return true;
		}

		if ( 'no' === $cached ) {
			return false;
		}

		$table = cmb_table( 'services' );
		$found = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'own_capacity' ) ); // phpcs:ignore
		$has   = ! empty( $found );

		set_transient( 'cmb_has_owncap_col', $has ? 'yes' : 'no', $has ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );

		return $has;
	}

	/**
	 * ساخت خدمت تازه.
	 *
	 * تا این نسخه فقط دو خدمتِ زمان نصب وجود داشت و هیچ راهی برای
	 * افزودن خدمت سوم نبود — نه در پیشخوان، نه در پنل.
	 *
	 * @return int|WP_Error شناسه‌ی خدمت تازه.
	 */
	public static function create_service( array $data ) {
		global $wpdb;

		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'cmb_no_title', 'عنوان خدمت الزامی است.', array( 'status' => 400 ) );
		}

		$branch_id = isset( $data['branch_id'] ) ? (int) $data['branch_id'] : 0;
		$branch_id = self::resolve_branch_id( $branch_id );

		if ( ! $branch_id ) {
			$branch_id = self::default_branch_id();
		}

		$table = cmb_table( 'services' );

		/* نامک از عنوان ساخته می‌شود. عنوان فارسی معمولاً نامک خالی
		   می‌دهد، پس در آن حالت از شناسه‌ی زمانی استفاده می‌کنیم.
		   نامک باید در هر شعبه یکتا بماند. */
		$base = sanitize_title( $title );

		if ( '' === $base ) {
			$base = 'service';
		}

		/* ستون نامک ۶۰ نویسه است و عنوان فارسی در وردپرس نامک فارسی
		   می‌دهد — یک عنوان بلند از ستون سرریز می‌کرد و در حالت
		   strict خطای دیتابیس می‌داد. */
		$base = cmb_substr( $base, 0, 45 );

		$slug = $base;
		$i    = 2;

		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND branch_id = %d", $slug, $branch_id ) ) ) { // phpcs:ignore
			$slug = $base . '-' . $i;
			$i++;

			if ( $i > 50 ) {
				$slug = $base . '-' . wp_rand( 1000, 9999 );
				break;
			}
		}

		// خدمت تازه ته فهرست می‌نشیند، نه وسط آن.
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE branch_id = %d", $branch_id ) ); // phpcs:ignore

		$row = array(
				'branch_id'        => $branch_id,
				'slug'             => $slug,
				'title'            => $title,
				'description'      => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
				'poster_id'        => 0,
				'price'            => isset( $data['price'] ) ? max( 0, (int) cmb_en_num( $data['price'] ) ) : 0,
				'duration_note'    => isset( $data['duration_note'] ) ? sanitize_text_field( $data['duration_note'] ) : '',
				'allowed_weekdays' => isset( $data['allowed_weekdays'] ) ? self::clean_weekdays( $data['allowed_weekdays'] ) : '',
				'sort_order'       => $max + 10,
				'is_active'        => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
				'created_at'       => current_time( 'mysql' ),
		);

		if ( isset( $data['own_capacity'] ) && self::has_own_capacity_column() ) {
			$row['own_capacity'] = self::encode_own_capacity( $data['own_capacity'] );
		}

		$ok = $wpdb->insert( $table, $row );

		if ( ! $ok ) {
			return new WP_Error( 'cmb_db_error', 'ثبت خدمت ناموفق بود.', array( 'status' => 500 ) );
		}

		self::flush_cache();

		$id = (int) $wpdb->insert_id;

		cmb_log( 'Service created', array( 'service' => $id, 'title' => $title ) );

		return $id;
	}

	/**
	 * حذف خدمت.
	 *
	 * اگر نوبتی به این خدمت وصل باشد حذف نمی‌شود: ردیف نوبت‌های
	 * گذشته به شناسه‌ی خدمت اشاره می‌کند و با حذفش، تاریخچه عنوان
	 * خودش را از دست می‌دهد. در آن حالت غیرفعال کردن راه درست است.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_service( $service_id ) {
		global $wpdb;

		$service_id = (int) $service_id;
		$service    = self::get_service( $service_id );

		if ( ! $service ) {
			return new WP_Error( 'cmb_not_found', 'خدمت یافت نشد.', array( 'status' => 404 ) );
		}

		$bookings = cmb_table( 'bookings' );
		$used     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE service_id = %d", $service_id ) ); // phpcs:ignore

		if ( $used > 0 ) {
			return new WP_Error(
				'cmb_service_in_use',
				sprintf(
					'این خدمت روی %s نوبت ثبت شده و حذفش تاریخچه را خراب می‌کند. به‌جای حذف، غیرفعالش کنید تا در فرم رزرو نمایش داده نشود.',
					cmb_fa_num( $used )
				),
				array( 'status' => 409 )
			);
		}

		$wpdb->delete( cmb_table( 'services' ), array( 'id' => $service_id ) );

		self::flush_cache();

		cmb_log( 'Service deleted', array( 'service' => $service_id ) );

		return true;
	}

	/**
	 * روزهای هفته را به رشته‌ی ذخیره‌شدنی تبدیل می‌کند.
	 */
	public static function clean_weekdays( $days ) {
		if ( ! is_array( $days ) ) {
			$days = explode( ',', (string) $days );
		}

		$clean = array();

		foreach ( $days as $d ) {
			$d = (int) $d;

			if ( $d >= 0 && $d <= 6 && ! in_array( $d, $clean, true ) ) {
				$clean[] = $d;
			}
		}

		sort( $clean );

		return implode( ',', $clean );
	}

	public static function update_service( $service_id, array $data ) {
		self::flush_cache();

		global $wpdb;

		$table  = cmb_table( 'services' );
		$fields = array();

		$map = array(
			'title'            => '%s',
			'description'      => '%s',
			'price'            => '%d',
			'duration_note'    => '%s',
			'allowed_weekdays' => '%s',
			'poster_id'        => '%d',
			'is_active'        => '%d',
			'sort_order'       => '%d',
		);

		foreach ( $map as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = '%d' === $format ? (int) $data[ $key ] : $data[ $key ];
			}
		}

		if ( array_key_exists( 'own_capacity', $data ) && self::has_own_capacity_column() ) {
			$fields['own_capacity'] = self::encode_own_capacity( $data['own_capacity'] );
		}

		if ( empty( $fields ) ) {
			return false;
		}

		return false !== $wpdb->update( $table, $fields, array( 'id' => (int) $service_id ) );
	}
}
