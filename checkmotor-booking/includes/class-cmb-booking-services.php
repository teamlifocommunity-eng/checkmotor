<?php
/**
 * چند خدمت در یک نوبت (۱.۵۳.۰).
 *
 * خدمت اصلی همان ستون bookings.service_id است (همه‌ی نوبت‌های قدیمی و
 * شمارش‌های قبلی دست نمی‌خورند). خدمت‌های اضافه‌ای که مشتری برای همان
 * روز و شیفت تیک زده، هر کدام یک ردیف در cmb_booking_services دارند.
 *
 * ظرفیت: هر خدمت انتخاب‌شده یک واحد از استخر خودش کم می‌کند — خدمت با
 * سهمیه‌ی جداگانه از سهمیه‌ی خودش، بقیه از ظرفیت مشترک شیفت. پس نوبتی
 * با دو خدمتِ ظرفیت مشترک، دو جا از آن شیفت می‌گیرد. وضعیت (لغو، عدم
 * مراجعه، پایان مهلت پرداخت) همان وضعیت خود نوبت است؛ با لغو، جای همه‌ی
 * خدمت‌هایش با هم آزاد می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Booking_Services {

	const SCHEMA = '1.13.0';

	/** حداکثر خدمت اضافه در یک نوبت (جدا از خدمت اصلی). */
	const MAX_EXTRA = 5;

	protected static $cache = array();

	public static function init() {
		add_action( 'cmb_booking_deleted', array( __CLASS__, 'delete_for' ), 5, 1 );
	}

	public static function ready() {
		return version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
	}

	public static function table() {
		return cmb_table( 'booking_services' );
	}

	public static function flush() {
		self::$cache = array();
	}

	/** شناسه‌های خدمت اضافه‌ی یک درخواست (آرایه یا «۳,۴»)، یکتا و بی خدمت اصلی. */
	public static function parse_ids( $raw, $primary_id = 0 ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,،]+/', cmb_en_num( $raw ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ) ) ) );

		return array_values( array_diff( $ids, array( (int) $primary_id ) ) );
	}

	/**
	 * خدمت‌های اضافه‌ی معتبر: قابل رزرو و از همان شعبه‌ی خدمت اصلی.
	 *
	 * @return object[]|WP_Error
	 */
	public static function resolve( $primary, array $ids ) {
		if ( ! $ids ) {
			return array();
		}

		if ( ! self::ready() ) {
			return new WP_Error( 'cmb_multi_unready', 'انتخاب چند خدمت هنوز فعال نشده است (یک بار پیشخوان وردپرس را باز کنید). فعلاً یک خدمت انتخاب کنید.', array( 'status' => 409 ) );
		}

		if ( count( $ids ) > self::MAX_EXTRA ) {
			return new WP_Error( 'cmb_multi_many', sprintf( 'در یک نوبت حداکثر %s خدمت می‌شود انتخاب کرد.', cmb_fa_num( self::MAX_EXTRA + 1 ) ), array( 'status' => 400 ) );
		}

		$out = array();

		foreach ( $ids as $id ) {
			$svc = CMB_Services::get_service( (int) $id );

			if ( ! $svc || ! CMB_Services::bookable( $svc ) ) {
				return new WP_Error( 'cmb_service_not_found', 'یکی از خدمت‌های انتخاب‌شده دیگر در دسترس نیست؛ صفحه را تازه کنید.', array( 'status' => 400 ) );
			}

			if ( (int) $svc->branch_id !== (int) $primary->branch_id ) {
				return new WP_Error( 'cmb_multi_branch', 'همه‌ی خدمت‌های یک نوبت باید از همان مرکز باشند.', array( 'status' => 400 ) );
			}

			$out[] = $svc;
		}

		return $out;
	}

	public static function save( $booking_id, array $services ) {
		global $wpdb;

		if ( ! $services || ! self::ready() ) {
			return true;
		}

		$now = cmb_now()->format( 'Y-m-d H:i:s' );

		foreach ( $services as $svc ) {
			$ok = $wpdb->insert(
				self::table(),
				array(
					'booking_id' => (int) $booking_id,
					'service_id' => (int) $svc->id,
					'price'      => (int) $svc->price,
					'created_at' => $now,
				)
			);

			if ( ! $ok ) {
				return false;
			}
		}

		unset( self::$cache[ (int) $booking_id ] );

		return true;
	}

	public static function delete_for( $booking_id ) {
		global $wpdb;

		if ( self::ready() ) {
			$wpdb->delete( self::table(), array( 'booking_id' => (int) $booking_id ) );
		}

		unset( self::$cache[ (int) $booking_id ] );
	}

	/** شناسه‌ی خدمت‌های اضافه‌ی یک نوبت. */
	public static function ids( $booking_id ) {
		$booking_id = (int) $booking_id;

		if ( ! isset( self::$cache[ $booking_id ] ) ) {
			$map = self::ids_map( array( $booking_id ) );
			self::$cache[ $booking_id ] = isset( $map[ $booking_id ] ) ? $map[ $booking_id ] : array();
		}

		return self::$cache[ $booking_id ];
	}

	/** خدمت‌های اضافه‌ی چند نوبت با یک کوئری. */
	public static function ids_map( array $booking_ids ) {
		global $wpdb;

		$booking_ids = array_values( array_filter( array_map( 'intval', $booking_ids ) ) );
		$out         = array();

		if ( ! $booking_ids || ! self::ready() ) {
			return $out;
		}

		$rows = (array) $wpdb->get_results( 'SELECT booking_id, service_id FROM ' . self::table() . ' WHERE booking_id IN (' . implode( ',', $booking_ids ) . ') ORDER BY id ASC' ); // phpcs:ignore

		foreach ( $rows as $r ) {
			$out[ (int) $r->booking_id ][] = (int) $r->service_id;
		}

		foreach ( $booking_ids as $id ) {
			self::$cache[ $id ] = isset( $out[ $id ] ) ? $out[ $id ] : array();
		}

		return $out;
	}

	/** همه‌ی خدمت‌های نوبت: اصلی، بعد اضافه‌ها. */
	public static function all_ids( $booking ) {
		return array_values( array_unique( array_merge( array( (int) $booking->service_id ), self::ids( (int) $booking->id ) ) ) );
	}

	/** عنوان همه‌ی خدمت‌های نوبت. */
	public static function titles( $booking ) {
		$out = array();

		foreach ( self::all_ids( $booking ) as $id ) {
			$svc = CMB_Services::get_service( $id );

			if ( $svc ) {
				$out[] = $svc->title;
			}
		}

		return $out;
	}

	/** «تنظیم موتور + تقویت موتور» برای فهرست‌ها و پیامک‌ها. */
	public static function label( $booking ) {
		return implode( ' + ', self::titles( $booking ) );
	}

	/** جمع قیمت همه‌ی خدمت‌ها. */
	public static function price_sum( $primary, array $extras ) {
		$sum = (int) $primary->price;

		foreach ( $extras as $svc ) {
			$sum += (int) $svc->price;
		}

		return $sum;
	}
}
