<?php
/**
 * موتور ظرفیت و تقویم رزرو.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Availability {

	/**
	 * تقویم پنجره‌ی چرخشی برای یک خدمت.
	 *
	 * @param int $service_id شناسه‌ی خدمت.
	 * @param int $branch_id  شناسه‌ی شعبه.
	 *
	 * @return array|WP_Error
	 */
	public static function get_calendar( $service_id, $branch_id = 0 ) {
		$service = CMB_Services::get_service( $service_id );

		if ( ! $service || ! $service->is_active ) {
			return new WP_Error( 'cmb_service_not_found', 'خدمت انتخاب‌شده در دسترس نیست.', array( 'status' => 404 ) );
		}

		$branch_id = $branch_id ? (int) $branch_id : (int) $service->branch_id;
		$weekdays  = CMB_Services::allowed_weekdays( $service );

		$min_days = (int) CMB_Settings::get( 'min_days_ahead', 1 );
		$window   = (int) CMB_Settings::get( 'window_days', 7 );

		$days     = array();
		$blocks   = cmb_blocks();
		$earliest = cmb_earliest_slot();
		$hours    = max( 0, (int) CMB_Settings::get( 'min_hours_ahead', 24 ) );

		$start = new DateTime( cmb_today(), cmb_timezone() );
		$start->modify( '+' . $min_days . ' day' );

		for ( $i = 0; $i < $window; $i++ ) {
			$date = clone $start;
			$date->modify( '+' . $i . ' day' );

			$ymd     = $date->format( 'Y-m-d' );
			$weekday = (int) $date->format( 'w' );

			$day = array(
				'date'        => $ymd,
				'weekday'     => $weekday,
				'weekdayName' => cmb_weekday_name( $ymd ),
				'jalali'      => cmb_jalali_date( $ymd, 'short' ),
				'jalaliFull'  => cmb_jalali_date( $ymd, 'full' ),
				'available'   => false,
				'reason'      => '',
				'blocks'      => array(),
			);

			// روز برای این خدمت مجاز نیست (طبق جدول ۴.۱ سند اسپک).
			if ( ! in_array( $weekday, $weekdays, true ) ) {
				$day['reason'] = self::is_weekend( $weekday ) ? 'تعطیل' : 'این خدمت در این روز ارائه نمی‌شود';
				$days[]        = $day;
				continue;
			}

			// بستن کامل روز توسط مدیر.
			if ( self::is_closed( $branch_id, $ymd, '' ) ) {
				$day['reason'] = 'تعطیل توسط مدیریت';
				$days[]        = $day;
				continue;
			}

			$soon_blocks = 0;

			foreach ( $blocks as $key => $block ) {
				/* ظرفیت و شمارش از استخرِ همین خدمت: اشتراکی یا سهمیه‌ی
				   جداگانه‌ی خودش. */
				$capacity = self::capacity_for( $service, $key );
				$booked   = self::booked_for( $service, $branch_id, $ymd, $key );
				$closed   = self::is_closed( $branch_id, $ymd, $key );
				$free     = max( 0, $capacity - $booked );

				// خدمت جداگانه‌ای که در این شیفت سهمیه ندارد، اصلاً نمایش داده نمی‌شود.
				if ( 0 === $capacity && null !== CMB_Services::own_capacity( $service ) ) {
					continue;
				}

				// کمتر از «حداقل زمان تا نوبت» به شروع این شیفت مانده
				$slot = cmb_slot_start( $ymd, $key );
				$soon = $earliest && $slot && $slot < $earliest;

				if ( $soon ) {
					$soon_blocks++;
				}

				$open = ! $closed && ! $soon && $free > 0;

				$day['blocks'][] = array(
					'key'       => $key,
					'label'     => $block['label'],
					'start'     => $block['start'],
					'startFa'   => cmb_fa_num( $block['start'] ),
					'capacity'  => $capacity,
					'remaining' => ( $closed || $soon ) ? 0 : $free,
					'available' => $open,
					'soon'      => $soon,
					'reason'    => $closed ? 'بسته شده' : ( $soon ? sprintf( 'کمتر از %s ساعت', cmb_fa_num( $hours ) ) : ( $free > 0 ? '' : 'تکمیل' ) ),
				);

				if ( $open ) {
					$day['available'] = true;
				}
			}

			if ( ! $day['available'] && '' === $day['reason'] ) {
				$day['reason'] = ( $soon_blocks && $soon_blocks === count( $day['blocks'] ) )
					? sprintf( 'نوبت باید دست‌کم %s ساعت پیش از شروع شیفت گرفته شود؛ برای این روز دیگر دیر است.', cmb_fa_num( $hours ) )
					: 'ظرفیت تکمیل است';
			}

			$days[] = $day;
		}

		return array(
			'service'  => CMB_Services::to_array( $service ),
			'days'     => $days,
			'window'   => $window,
			'minDays'  => $min_days,
			'minHours' => $hours,
			'notes'    => array(
				'minHours'    => $hours > 0 ? sprintf( 'نوبت را دست‌کم %s ساعت پیش از شروع شیفت بگیرید؛ شیفت‌هایی که زودتر شروع می‌شوند قابل انتخاب نیستند.', cmb_fa_num( $hours ) ) : '',
				'ecu'         => CMB_Settings::get( 'ecu_note', '' ),
				'outOfWindow' => CMB_Settings::get( 'out_of_window_note', '' ),
				'lateRule'    => CMB_Settings::get( 'late_rule_note', '' ),
			),
		);
	}

	/**
	 * تعداد نوبت‌های ثبت‌شده‌ی هر شیفت در یک روز.
	 *
	 * @return array<string,int>
	 */
	/**
	 * کش درون‌درخواستی.
	 *
	 * تخته‌ی روزها برای ۷ روز، ۴۲ کوئری می‌زد: هر روز و هر شیفت
	 * جداگانه پرسیده می‌شد. حالا هر کلید فقط یک بار از دیتابیس
	 * خوانده می‌شود و بقیه‌ی درخواست‌ها از حافظه پاسخ می‌گیرند.
	 */
	protected static $cache = array();

	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * شناسه‌ی خدمت‌هایی که ظرفیت جداگانه دارند.
	 *
	 * از فهرست کش‌شده‌ی خدمات خوانده می‌شود، نه با JOIN روی ستون
	 * own_capacity. دلیلش: اگر مهاجرت هنوز اجرا نشده باشد، JOIN روی
	 * ستونی که وجود ندارد کل تقویم رزرو را از کار می‌انداخت. این‌طور
	 * پیش از مهاجرت فهرست خالی است و کوئری دقیقاً همان قبلی می‌ماند.
	 *
	 * @return int[]
	 */
	public static function own_pool_ids( $branch_id ) {
		$key = 'o:' . (int) $branch_id;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$ids = array();

		foreach ( CMB_Services::get_services( $branch_id, false ) as $service ) {
			if ( null !== CMB_Services::own_capacity( $service ) ) {
				$ids[] = (int) $service->id;
			}
		}

		self::$cache[ $key ] = $ids;

		return $ids;
	}

	/**
	 * تعداد نوبت‌های ظرفیت اشتراکیِ هر شیفت در یک روز.
	 *
	 * نوبت‌های خدمت‌هایی که سهمیه‌ی جداگانه دارند اینجا شمرده
	 * نمی‌شوند — همین است که تعویض روغن دیگر جای تنظیم موتور را
	 * نمی‌گیرد.
	 *
	 * @return array<string,int>
	 */
	public static function get_booked_counts( $branch_id, $date ) {
		global $wpdb;

		$key = 'c:' . (int) $branch_id . ':' . $date;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$table   = cmb_table( 'bookings' );
		$exclude = self::own_pool_ids( $branch_id );
		$params  = array( (int) $branch_id, $date );
		$not_in  = '';

		if ( $exclude ) {
			$not_in = ' AND service_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params = array_merge( $params, $exclude );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_key, COUNT(*) AS total FROM {$table}
				 WHERE branch_id = %d AND booking_date = %s AND status IN ('confirmed','done'){$not_in}
				 GROUP BY block_key", // phpcs:ignore
				$params
			)
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row->block_key ] = (int) $row->total;
		}

		self::$cache[ $key ] = $counts;

		return $counts;
	}

	/**
	 * تعداد نوبت‌های یک خدمتِ مشخص در هر شیفت یک روز.
	 *
	 * @return array<string,int>
	 */
	public static function get_service_counts( $service_id, $branch_id, $date ) {
		global $wpdb;

		$key = 's:' . (int) $service_id . ':' . (int) $branch_id . ':' . $date;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$table = cmb_table( 'bookings' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT block_key, COUNT(*) AS total FROM {$table}
				 WHERE service_id = %d AND branch_id = %d AND booking_date = %s AND status IN ('confirmed','done')
				 GROUP BY block_key", // phpcs:ignore
				(int) $service_id,
				(int) $branch_id,
				$date
			)
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row->block_key ] = (int) $row->total;
		}

		self::$cache[ $key ] = $counts;

		return $counts;
	}

	/**
	 * ظرفیت یک شیفت برای یک خدمت.
	 *
	 * خدمت با سهمیه‌ی جداگانه، سهمیه‌ی خودش را دارد؛ بقیه ظرفیت اصلی
	 * شیفت را.
	 */
	public static function capacity_for( $service, $block_key ) {
		$own = CMB_Services::own_capacity( $service );

		if ( null !== $own ) {
			return isset( $own[ $block_key ] ) ? (int) $own[ $block_key ] : 0;
		}

		$blocks = cmb_blocks();

		return isset( $blocks[ $block_key ] ) ? (int) $blocks[ $block_key ]['capacity'] : 0;
	}

	/**
	 * تعداد نوبت‌های ثبت‌شده‌ای که از همان استخرِ این خدمت کم می‌کنند.
	 */
	public static function booked_for( $service, $branch_id, $date, $block_key ) {
		$counts = ( null !== CMB_Services::own_capacity( $service ) )
			? self::get_service_counts( (int) $service->id, $branch_id, $date )
			: self::get_booked_counts( $branch_id, $date );

		return isset( $counts[ $block_key ] ) ? (int) $counts[ $block_key ] : 0;
	}

	/**
	 * ظرفیت باقی‌مانده‌ی یک شیفت.
	 *
	 * بدون خدمت، ظرفیت اشتراکی شیفت را برمی‌گرداند (رفتار قدیمی).
	 * با خدمت، ظرفیت همان استخری که آن خدمت از آن کم می‌کند.
	 */
	public static function remaining( $branch_id, $date, $block_key, $service = null ) {
		$blocks = cmb_blocks();

		if ( ! isset( $blocks[ $block_key ] ) ) {
			return 0;
		}

		if ( self::is_closed( $branch_id, $date, '' ) || self::is_closed( $branch_id, $date, $block_key ) ) {
			return 0;
		}

		if ( $service ) {
			return max( 0, self::capacity_for( $service, $block_key ) - self::booked_for( $service, $branch_id, $date, $block_key ) );
		}

		$counts = self::get_booked_counts( $branch_id, $date );
		$booked = isset( $counts[ $block_key ] ) ? (int) $counts[ $block_key ] : 0;

		return max( 0, (int) $blocks[ $block_key ]['capacity'] - $booked );
	}

	/**
	 * آیا روز/شیفت توسط مدیر بسته شده است؟
	 *
	 * @param string $block_key رشته‌ی خالی یعنی کل روز.
	 */
	public static function is_closed( $branch_id, $date, $block_key = '' ) {
		/* همه‌ی بازه‌های بسته‌ی یک روز با یک کوئری خوانده می‌شوند،
		   نه یکی برای کل روز و یکی برای هر شیفت. */
		$closed = self::closures_for_date( $branch_id, $date );

		return in_array( (string) $block_key, $closed, true );
	}

	/**
	 * کلید بازه‌های بسته‌ی یک روز. '' یعنی کل روز.
	 *
	 * @return string[]
	 */
	public static function closures_for_date( $branch_id, $date ) {
		global $wpdb;

		$key = 'x:' . (int) $branch_id . ':' . $date;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$table = cmb_table( 'closures' );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT block_key FROM {$table} WHERE branch_id = %d AND closure_date = %s", // phpcs:ignore
				(int) $branch_id,
				$date
			)
		);

		$out = array_map( 'strval', (array) $rows );

		self::$cache[ $key ] = $out;

		return $out;
	}

	/**
	 * اعتبارسنجی کامل یک اسلات پیش از ثبت نوبت.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_slot( $service, $date, $block_key, $branch_id = 0 ) {
		if ( is_numeric( $service ) ) {
			$service = CMB_Services::get_service( $service );
		}

		if ( ! $service || ! $service->is_active ) {
			return new WP_Error( 'cmb_service_not_found', 'خدمت انتخاب‌شده در دسترس نیست.', array( 'status' => 400 ) );
		}

		$branch_id = $branch_id ? (int) $branch_id : (int) $service->branch_id;
		$blocks    = cmb_blocks();

		if ( ! isset( $blocks[ $block_key ] ) ) {
			return new WP_Error( 'cmb_bad_block', 'شیفت معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return new WP_Error( 'cmb_bad_date', 'تاریخ معتبر نیست.', array( 'status' => 400 ) );
		}

		$min_days = (int) CMB_Settings::get( 'min_days_ahead', 1 );
		$window   = (int) CMB_Settings::get( 'window_days', 7 );

		$today = new DateTime( cmb_today(), cmb_timezone() );
		$then  = new DateTime( $date, cmb_timezone() );
		$diff  = (int) $today->diff( $then )->format( '%r%a' );

		if ( $diff < $min_days ) {
			return new WP_Error(
				'cmb_too_soon',
				sprintf( 'رزرو باید حداقل %s روز قبل انجام شود.', cmb_fa_num( $min_days ) ),
				array( 'status' => 400 )
			);
		}

		if ( $diff > ( $min_days + $window - 1 ) ) {
			return new WP_Error( 'cmb_too_far', CMB_Settings::get( 'out_of_window_note', 'این تاریخ خارج از بازه‌ی رزرو آنلاین است.' ), array( 'status' => 400 ) );
		}

		$earliest = cmb_earliest_slot();
		$slot     = cmb_slot_start( $date, $block_key );

		if ( $earliest && $slot && $slot < $earliest ) {
			$hours = max( 0, (int) CMB_Settings::get( 'min_hours_ahead', 24 ) );

			return new WP_Error(
				'cmb_too_soon',
				sprintf( 'نوبت باید دست‌کم %s ساعت پیش از شروع شیفت گرفته شود و این شیفت زودتر شروع می‌شود. شیفت دیگری انتخاب کنید.', cmb_fa_num( $hours ) ),
				array( 'status' => 400 )
			);
		}

		$weekday = (int) $then->format( 'w' );

		if ( ! in_array( $weekday, CMB_Services::allowed_weekdays( $service ), true ) ) {
			return new WP_Error(
				'cmb_bad_weekday',
				sprintf( 'خدمت «%s» در روز %s قابل رزرو نیست.', $service->title, cmb_weekday_name( $date ) ),
				array( 'status' => 400 )
			);
		}

		if ( self::is_closed( $branch_id, $date, '' ) ) {
			return new WP_Error( 'cmb_day_closed', 'این روز توسط مدیریت بسته شده است.', array( 'status' => 409 ) );
		}

		if ( self::is_closed( $branch_id, $date, $block_key ) ) {
			return new WP_Error( 'cmb_block_closed', 'این شیفت بسته شده است.', array( 'status' => 409 ) );
		}

		if ( 0 === self::capacity_for( $service, $block_key ) ) {
			return new WP_Error(
				'cmb_block_not_offered',
				sprintf( 'خدمت «%s» در این شیفت ارائه نمی‌شود.', $service->title ),
				array( 'status' => 400 )
			);
		}

		if ( self::remaining( $branch_id, $date, $block_key, $service ) < 1 ) {
			return new WP_Error( 'cmb_block_full', 'ظرفیت این شیفت تکمیل شده است. شیفت دیگری انتخاب کنید.', array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * پنجشنبه (۴) و جمعه (۵) تعطیل هستند.
	 */
	public static function is_weekend( $weekday ) {
		return in_array( (int) $weekday, array( 4, 5 ), true );
	}

	/**
	 * بستن/بازکردن یک روز یا شیفت توسط مدیر.
	 */
	public static function set_closure( $branch_id, $date, $block_key, $reason = '' ) {
		global $wpdb;

		$table = cmb_table( 'closures' );

		return $wpdb->insert(
			$table,
			array(
				'branch_id'    => (int) $branch_id,
				'closure_date' => $date,
				'block_key'    => $block_key,
				'reason'       => $reason,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	public static function remove_closure( $closure_id ) {
		global $wpdb;

		return $wpdb->delete( cmb_table( 'closures' ), array( 'id' => (int) $closure_id ) );
	}

	/**
	 * فهرست بسته‌شده‌ها از امروز به بعد.
	 */
	public static function get_closures( $branch_id = 0, $from = null ) {
		global $wpdb;

		$branch_id = $branch_id ? (int) $branch_id : CMB_Services::default_branch_id();
		$from      = $from ? $from : cmb_today();
		$table     = cmb_table( 'closures' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE branch_id = %d AND closure_date >= %s ORDER BY closure_date ASC", // phpcs:ignore
				$branch_id,
				$from
			)
		);
	}
}
