<?php
/**
 * مرکز و نمایندگی‌ها.
 *
 * جدول branches از ابتدا چندشعبه‌ای بود؛ این کلاس معنای کسب‌وکاری‌اش را
 * می‌سازد: مرکز بوشهر (type=center، بدون کیف پول) و نمایندگی‌ها
 * (type=agency) که هر رزرو موفقشان مبلغی از کیف پول خودشان کم می‌کند.
 *
 * وضعیت نمایندگی: draft (تازه ساخته، در فهرست مشتری نیست) ← active ←
 * inactive. ستون قدیمی is_active همیشه با status هم‌خوان نگه داشته
 * می‌شود تا کدهای قدیمی‌تر همان جواب را بگیرند.
 *
 * کاربر پنل: هر کاربرِ غیرمدیر دقیقاً به یک شعبه وصل است (user meta
 * cmb_branch_id) و نقشش manager یا operator است (cmb_branch_role).
 * مسئولان رزرو قبلی که هنوز شعبه ندارند، کاربر مرکز با نقش «مدیر مرکز»اند —
 * تا هیچ‌کدام بعد از به‌روزرسانی قفل نشوند. اختیار هر نقش در can() است.
 *
 * تا مهاجرت ۱.۹.۰ اجرا نشده (ready() = false)، همه‌چیز دقیقاً مثل قبل
 * رفتار می‌کند: یک شعبه، بدون کسر، بدون مرحله‌ی انتخاب مرکز.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Branches {

	/** نسخه‌ی اسکیمایی که ستون‌های نمایندگی و دفتر کیف پولش را دارد. */
	const SCHEMA = '1.9.0';

	/** هر نمایندگی یک مدیر و حداکثر این تعداد اپراتور. */
	const MAX_OPERATORS = 2;

	/** شعبه‌ای که مدیر سایت در پنل انتخاب کرده (برای همان درخواست). */
	protected static $panel_branch = null;

	/** @var int[]|null */
	protected static $agency_ids = null;

	/* ------------------------------------------------------------------
	 * پایه
	 * --------------------------------------------------------------- */

	public static function ready() {
		return version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
	}

	public static function get( $branch_id ) {
		$branch_id = (int) $branch_id;

		return $branch_id ? CMB_Services::get_branch( $branch_id ) : null;
	}

	/** شناسه‌ی مرکز (بوشهر). */
	public static function center_id() {
		return (int) CMB_Services::default_branch_id();
	}

	/** @param object|int $branch */
	protected static function row( $branch ) {
		return is_object( $branch ) ? $branch : self::get( (int) $branch );
	}

	public static function is_center( $branch ) {
		$b = self::row( $branch );

		if ( ! $b ) {
			return false;
		}

		if ( ! self::ready() || ! isset( $b->type ) ) {
			return (int) $b->id === self::center_id();
		}

		return 'center' === (string) $b->type;
	}

	public static function is_agency( $branch ) {
		$b = self::row( $branch );

		return $b && self::ready() && isset( $b->type ) && 'agency' === (string) $b->type;
	}

	/** draft | active | inactive */
	public static function status( $branch ) {
		$b = self::row( $branch );

		if ( ! $b ) {
			return 'inactive';
		}

		if ( ! self::ready() || ! isset( $b->status ) || '' === (string) $b->status ) {
			return (int) $b->is_active ? 'active' : 'inactive';
		}

		return (string) $b->status;
	}

	public static function status_label( $status ) {
		$map = array(
			'draft'    => 'پیش‌نویس',
			'active'   => 'فعال',
			'inactive' => 'غیرفعال',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	/** مبلغ هر رزرو نمایندگی، تومان. */
	public static function fee() {
		return max( 0, (int) CMB_Settings::get( 'agency_fee', 200000 ) );
	}

	/**
	 * همه‌ی شعبه‌ها برای مدیر، به ترتیب نمایش.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;

		$table = cmb_table( 'branches' );
		$order = self::ready() ? 'sort_order ASC, id ASC' : 'id ASC';

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$order}" ); // phpcs:ignore
	}

	/**
	 * شعبه‌های فعال برای فهرست مشتری: مرکز و نمایندگی‌های فعال.
	 *
	 * ردیف‌های قدیمیِ دیگرِ جدول شعبه‌ها (اگر سایتی داشته باشد) عمداً
	 * اینجا نمی‌آیند تا با به‌روزرسانی، مرحله‌ی انتخاب مرکز ناگهان برای
	 * مشتری‌ها پیدا نشود.
	 */
	public static function active() {
		$center = self::center_id();

		return array_values(
			array_filter(
				self::all(),
				function ( $b ) use ( $center ) {
					if ( 'active' !== CMB_Branches::status( $b ) ) {
						return false;
					}

					return (int) $b->id === $center || CMB_Branches::is_agency( $b );
				}
			)
		);
	}

	public static function by_slug( $slug ) {
		global $wpdb;

		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return null;
		}

		$table = cmb_table( 'branches' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ); // phpcs:ignore

		return $id ? self::get( $id ) : null;
	}

	/* ------------------------------------------------------------------
	 * برنامه‌ی کاری
	 * --------------------------------------------------------------- */

	/** کلیدهای برنامه‌ی کاریِ جدا برای هر شعبه. */
	public static function schedule_keys() {
		return array( 'morning_start', 'morning_capacity', 'afternoon_start', 'afternoon_capacity' );
	}

	/**
	 * برنامه‌ی کاری ذخیره‌شده‌ی یک شعبه، یا آرایه‌ی خالی.
	 *
	 * مرکز عمداً برنامه‌ی ذخیره‌شده ندارد: از همان تنظیمات سراسری
	 * می‌خواند تا صفحه‌ی تنظیمات پیشخوان مثل همیشه روی بوشهر اثر کند.
	 *
	 * @return array
	 */
	protected static $schedule_cache = array();

	public static function schedule( $branch_id ) {
		$branch_id = (int) $branch_id;

		if ( isset( self::$schedule_cache[ $branch_id ] ) ) {
			return self::$schedule_cache[ $branch_id ];
		}

		$b   = self::get( $branch_id );
		$out = array();

		if ( $b && self::ready() && isset( $b->schedule ) && '' !== (string) $b->schedule && self::is_agency( $b ) ) {
			$raw = json_decode( (string) $b->schedule, true );
			$out = is_array( $raw ) ? array_intersect_key( $raw, array_flip( self::schedule_keys() ) ) : array();
		}

		self::$schedule_cache[ $branch_id ] = $out;

		return $out;
	}

	/** برنامه‌ی کاری فعلی بوشهر، برای کپی در نمایندگی تازه. */
	public static function default_schedule() {
		$out = array();

		foreach ( self::schedule_keys() as $key ) {
			$out[ $key ] = CMB_Settings::get( $key );
		}

		return $out;
	}

	/**
	 * ذخیره‌ی برنامه‌ی کاری: مرکز ← تنظیمات سراسری، نمایندگی ← خودش.
	 *
	 * @return array|WP_Error مقادیر پاک‌سازی‌شده.
	 */
	public static function save_schedule( $branch_id, array $input ) {
		global $wpdb;

		$clean = CMB_Settings::sanitize_schedule( $input, $branch_id );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( ! self::is_agency( $branch_id ) ) {
			CMB_Settings::update( $clean );

			return $clean;
		}

		// فقط کلیدهای شیفت مال نمایندگی است؛ بازه‌ی رزرو و لغو سراسری می‌ماند (D7)
		$own   = array_intersect_key( $clean, array_flip( self::schedule_keys() ) );
		$merge = array_merge( self::default_schedule(), self::schedule( $branch_id ), $own );

		$wpdb->update( cmb_table( 'branches' ), array( 'schedule' => wp_json_encode( $merge ) ), array( 'id' => (int) $branch_id ) );

		self::flush( $branch_id );

		return $own;
	}

	/* ------------------------------------------------------------------
	 * قابلیت رزرو
	 * --------------------------------------------------------------- */

	/**
	 * آیا مشتری همین حالا می‌تواند در این شعبه نوبت بگیرد؟
	 *
	 * @param string $reason inactive | balance | no_services | ''
	 */
	public static function bookable( $branch, &$reason = null ) {
		$reason = '';
		$b      = self::row( $branch );

		if ( ! $b ) {
			$reason = 'inactive';
			return false;
		}

		if ( ! self::ready() ) {
			return true;
		}

		if ( 'active' !== self::status( $b ) ) {
			$reason = 'inactive';
			return false;
		}

		if ( self::is_agency( $b ) ) {
			if ( ! CMB_Services::get_services( (int) $b->id, true ) ) {
				$reason = 'no_services';
				return false;
			}

			if ( CMB_Branch_Wallet::free( (int) $b->id ) < self::fee() ) {
				$reason = 'balance';
				return false;
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * خروجی
	 * --------------------------------------------------------------- */

	public static function photo( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! $attachment_id ) {
			return array( 'url' => '', 'full' => '' );
		}

		$url  = wp_get_attachment_image_url( $attachment_id, 'medium' );
		$full = wp_get_attachment_image_url( $attachment_id, 'large' );

		return array(
			'url'  => $url ? $url : '',
			'full' => $full ? $full : ( $url ? $url : '' ),
		);
	}

	/** A complete, finite coordinate pair; empty coordinates never mean (0,0). */
	public static function coordinates( $b ) {
		if ( ! is_object( $b ) || ! isset( $b->lat, $b->lng ) || '' === trim( (string) $b->lat ) || '' === trim( (string) $b->lng ) || ! is_numeric( $b->lat ) || ! is_numeric( $b->lng ) ) {
			return null;
		}
		$lat = (float) $b->lat;
		$lng = (float) $b->lng;
		return is_finite( $lat ) && is_finite( $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180 && ( 0.0 !== $lat || 0.0 !== $lng ) ? array( $lat, $lng ) : null;
	}

	public static function map_url( $b ) {
		$point = self::coordinates( $b );
		return $point ? 'https://nshn.ir/?lat=' . rawurlencode( (string) $point[0] ) . '&lng=' . rawurlencode( (string) $point[1] ) : '';
	}

	protected static function validate_location( array $clean, $old = null ) {
		if ( ! array_key_exists( 'lat', $clean ) && ! array_key_exists( 'lng', $clean ) ) { return true; }
		$lat = array_key_exists( 'lat', $clean ) ? $clean['lat'] : ( isset( $old->lat ) ? $old->lat : '' );
		$lng = array_key_exists( 'lng', $clean ) ? $clean['lng'] : ( isset( $old->lng ) ? $old->lng : '' );
		if ( ( '' === (string) $lat ) !== ( '' === (string) $lng ) ) {
			return new WP_Error( 'cmb_branch_geo_pair', 'هر دو مختصات موقعیت را وارد کنید، یا هر دو را خالی بگذارید.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * کارت شعبه برای مشتری؛ بدون هیچ عدد مالی (US-2.2).
	 */
	public static function public_array( $b ) {
		$b = self::row( $b );

		if ( ! $b ) {
			return null;
		}

		$point    = self::coordinates( $b );
		$reason   = '';
		$bookable = self::bookable( $b, $reason );
		$photo    = self::photo( isset( $b->photo_id ) ? $b->photo_id : 0 );

		return array(
			'id'       => (int) $b->id,
			'slug'     => (string) $b->slug,
			'title'    => (string) $b->title,
			'type'     => self::is_agency( $b ) ? 'agency' : 'center',
			'province' => isset( $b->province ) ? (string) $b->province : '',
			'city'     => isset( $b->city ) ? (string) $b->city : '',
			'address'  => (string) $b->address,
			'phone'    => (string) $b->phone,
			'photo'    => $photo['url'],
			'map'      => self::map_url( $b ),
			'lat'      => $point ? $point[0] : null,
			'lng'      => $point ? $point[1] : null,
			'verified' => self::is_agency( $b ),
			'bookable' => $bookable,
			// دلیل مالی یا هر دلیل دیگر به مشتری گفته نمی‌شود (D18)
			'unavailable' => $bookable ? '' : 'فعلاً نوبت نمی‌گیرد',
		);
	}

	/**
	 * فهرست مشتری: شعبه‌های فعال، گروه‌بندی‌شده بر اساس استان و در هر
	 * استان مرتب بر اساس شهر.
	 *
	 * @return array[] { province, branches[] }
	 */
	public static function public_groups() {
		$list = array();

		foreach ( self::active() as $b ) {
			$list[] = self::public_array( $b );
		}

		usort(
			$list,
			function ( $a, $b ) {
				$p = cmb_fa_compare( $a['province'], $b['province'] );

				if ( 0 !== $p ) {
					return $p;
				}

				$c = cmb_fa_compare( $a['city'], $b['city'] );

				return 0 !== $c ? $c : cmb_fa_compare( $a['title'], $b['title'] );
			}
		);

		$groups = array();

		foreach ( $list as $item ) {
			$key = '' !== $item['province'] ? $item['province'] : 'سایر';

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'province' => $key,
					'branches' => array(),
				);
			}

			$groups[ $key ]['branches'][] = $item;
		}

		return array_values( $groups );
	}

	/** تعداد شعبه‌های فعال؛ با یکی، مرحله‌ی انتخاب مرکز رد می‌شود. */
	public static function active_count() {
		return self::ready() ? count( self::active() ) : 1;
	}

	/**
	 * داده‌ی کامل شعبه برای پنل (نمایندگی خودش یا مدیر سایت).
	 */
	public static function panel_array( $b, $with_money = true ) {
		$b = self::row( $b );

		if ( ! $b ) {
			return null;
		}

		$photo = self::photo( isset( $b->photo_id ) ? $b->photo_id : 0 );
		$out   = array(
			'id'           => (int) $b->id,
			'slug'         => (string) $b->slug,
			'title'        => (string) $b->title,
			'type'         => self::is_agency( $b ) ? 'agency' : 'center',
			'status'       => self::status( $b ),
			'statusLabel'  => self::status_label( self::status( $b ) ),
			'province'     => isset( $b->province ) ? (string) $b->province : '',
			'city'         => isset( $b->city ) ? (string) $b->city : '',
			'address'      => (string) $b->address,
			'phone'        => (string) $b->phone,
			'lat'          => ( isset( $b->lat ) && '' !== (string) $b->lat ) ? (float) $b->lat : null,
			'lng'          => ( isset( $b->lng ) && '' !== (string) $b->lng ) ? (float) $b->lng : null,
			'photoId'      => isset( $b->photo_id ) ? (int) $b->photo_id : 0,
			'photo'        => $photo['url'],
			'contactName'  => isset( $b->contact_name ) ? (string) $b->contact_name : '',
			'managerPhones' => (string) $b->manager_phones,
			'sortOrder'    => isset( $b->sort_order ) ? (int) $b->sort_order : 0,
			'activatedAt'  => ( isset( $b->activated_at ) && $b->activated_at ) ? cmb_jalali_date( substr( (string) $b->activated_at, 0, 10 ), 'full' ) : '',
			'map'          => self::map_url( $b ),
		);

		if ( $with_money && self::is_agency( $b ) ) {
			$out['wallet'] = CMB_Branch_Wallet::summary( (int) $b->id );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * ساخت و ویرایش (مدیر سایت)
	 * --------------------------------------------------------------- */

	/**
	 * فیلدهای پروفایل از ورودی.
	 *
	 * @param string $scope admin (همه) | manager (آدرس، تلفن، عکس، لوکیشن)
	 *
	 * @return array|WP_Error
	 */
	protected static function clean_profile( array $in, $scope = 'admin' ) {
		$out = array();

		$manager_keys = array( 'address', 'phone', 'photo_id', 'lat', 'lng' );
		$admin_keys   = array_merge( $manager_keys, array( 'title', 'slug', 'province', 'city', 'contact_name', 'manager_phones', 'sort_order', 'type' ) );
		$allowed      = 'admin' === $scope ? $admin_keys : $manager_keys;

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}

			$v = $in[ $key ];

			switch ( $key ) {
				case 'title':
					$v = sanitize_text_field( (string) $v );

					if ( cmb_strlen( $v ) < 2 ) {
						return new WP_Error( 'cmb_branch_title', 'نام نمایندگی را وارد کنید.', array( 'status' => 400 ) );
					}

					$out['title'] = cmb_substr( $v, 0, 190 );
					break;

				case 'slug':
					$v = strtolower( trim( (string) $v ) );

					if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,59}$/', $v ) ) {
						return new WP_Error( 'cmb_branch_slug', 'نامک (slug) فقط حروف کوچک لاتین، عدد و خط تیره باشد؛ مثلاً andimeshk.', array( 'status' => 400 ) );
					}

					$out['slug'] = $v;
					break;

				case 'province':
				case 'city':
					$v = CMB_Bookings::sanitize_province( $v );

					if ( cmb_strlen( $v ) < 2 ) {
						return new WP_Error( 'cmb_branch_place', 'province' === $key ? 'استان را وارد کنید.' : 'شهر را وارد کنید.', array( 'status' => 400 ) );
					}

					$out[ $key ] = cmb_substr( $v, 0, 60 );
					break;

				case 'address':
					$out['address'] = cmb_substr( sanitize_textarea_field( (string) $v ), 0, 500 );
					break;

				case 'phone':
					$out['phone'] = cmb_substr( preg_replace( '/[^0-9+\-\s]/', '', cmb_en_num( (string) $v ) ), 0, 30 );
					break;

				case 'contact_name':
					$out['contact_name'] = cmb_substr( sanitize_text_field( (string) $v ), 0, 120 );
					break;

				case 'manager_phones':
					$phones = array();

					foreach ( preg_split( '/[\s,،;]+/u', (string) $v ) as $p ) {
						$n = cmb_normalize_phone( $p );

						if ( $n ) {
							$phones[] = $n;
						} elseif ( '' !== trim( (string) $p ) ) {
							return new WP_Error( 'cmb_branch_phone', sprintf( 'شماره‌ی «%s» معتبر نیست.', trim( (string) $p ) ), array( 'status' => 400 ) );
						}
					}

					$out['manager_phones'] = implode( ',', array_unique( $phones ) );
					break;

				case 'photo_id':
					$v = (int) $v;

					if ( $v && ! wp_attachment_is_image( $v ) ) {
						return new WP_Error( 'cmb_branch_photo', 'عکس انتخاب‌شده معتبر نیست.', array( 'status' => 400 ) );
					}

					$out['photo_id'] = max( 0, $v );
					break;

				case 'lat':
				case 'lng':
					if ( null === $v || '' === trim( (string) $v ) ) {
						$out[ $key ] = '';
						break;
					}

					// ممیز فارسی «٫» و ویرگول هم پذیرفته می‌شود
					$raw = str_replace( array( '٫', ',' ), '.', trim( cmb_en_num( (string) $v ) ) );
					$max = 'lat' === $key ? 90 : 180;

					if ( ! is_numeric( $raw ) || ! is_finite( (float) $raw ) || abs( (float) $raw ) > $max ) {
						return new WP_Error( 'cmb_branch_geo', 'مختصات نقشه معتبر نیست؛ عدد اعشاری مثل ۳۲٫۴۵۶۱ وارد کنید.', array( 'status' => 400 ) );
					}

					// رشته، تا روی همه‌ی دیتابیس‌ها یکسان ذخیره شود
					$out[ $key ] = (string) round( (float) $raw, 6 );
					break;

				case 'sort_order':
					$out['sort_order'] = (int) $v;
					break;

				case 'type':
					$out['type'] = 'center' === $v ? 'center' : 'agency';
					break;
			}
		}

		return $out;
	}

	protected static function slug_taken( $slug, $except_id = 0 ) {
		global $wpdb;

		$table = cmb_table( 'branches' );

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $slug, (int) $except_id ) ); // phpcs:ignore
	}

	/**
	 * ساخت نمایندگی تازه (پیش‌نویس).
	 *
	 * @param array $data title، slug، province، city، address، phone، lat،
	 *                    lng، photo_id، contact_name، manager_phone، type
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data, $by = 0 ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return new WP_Error( 'cmb_branch_schema', 'دیتابیس هنوز به‌روز نشده است؛ یک بار پیشخوان وردپرس را باز کنید.', array( 'status' => 503 ) );
		}

		foreach ( array( 'title', 'slug', 'province', 'city' ) as $need ) {
			if ( ! isset( $data[ $need ] ) || '' === trim( (string) $data[ $need ] ) ) {
				$labels = array( 'title' => 'نام', 'slug' => 'نامک (slug)', 'province' => 'استان', 'city' => 'شهر' );

				return new WP_Error( 'cmb_branch_required', sprintf( '%s نمایندگی الزامی است.', $labels[ $need ] ), array( 'status' => 400 ) );
			}
		}

		$manager_phone = '';

		if ( ! empty( $data['manager_phone'] ) ) {
			$manager_phone = cmb_normalize_phone( (string) $data['manager_phone'] );

			if ( ! $manager_phone ) {
				return new WP_Error( 'cmb_branch_phone', 'موبایل مدیر نمایندگی معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹', array( 'status' => 400 ) );
			}

			$taken = self::phone_conflict( $manager_phone, 0 );

			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
		}

		if ( ! isset( $data['type'] ) ) {
			$data['type'] = 'agency';
		}

		$clean = self::clean_profile( $data, 'admin' );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$location = self::validate_location( $clean );
		if ( is_wp_error( $location ) ) { return $location; }

		if ( self::slug_taken( $clean['slug'] ) ) {
			return new WP_Error( 'cmb_branch_slug_taken', sprintf( 'نامک «%s» قبلاً برای شعبه‌ی دیگری استفاده شده است. نامک دیگری انتخاب کنید.', $clean['slug'] ), array( 'status' => 409 ) );
		}

		if ( $manager_phone ) {
			$list                     = array_filter( explode( ',', isset( $clean['manager_phones'] ) ? $clean['manager_phones'] : '' ) );
			$list[]                   = $manager_phone;
			$clean['manager_phones'] = implode( ',', array_unique( $list ) );
		}

		$table = cmb_table( 'branches' );
		$max   = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" ); // phpcs:ignore

		$row = array_merge(
			array(
				'address'        => '',
				'phone'          => '',
				'manager_phones' => '',
				'sort_order'     => $max + 10,
			),
			$clean,
			array(
				'status'     => 'draft',
				'is_active'  => 0,
				'low_notice' => 0,
				'schedule'   => wp_json_encode( self::default_schedule() ),
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( ! $wpdb->insert( $table, $row ) ) {
			return new WP_Error( 'cmb_db_error', 'ثبت نمایندگی ناموفق بود.', array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		self::flush( $id );

		// کاتالوگ مرکزی: ردیف خدمات نمایندگی، غیرفعال تا خودش فعالشان کند
		CMB_Services::ensure_catalog( $id );

		if ( $manager_phone ) {
			$added = self::add_user( $id, $manager_phone, isset( $clean['contact_name'] ) ? $clean['contact_name'] : '', 'manager' );

			if ( is_wp_error( $added ) ) {
				cmb_log( 'Agency manager user failed: ' . $added->get_error_message(), array( 'branch' => $id ) );
			}
		}

		cmb_log( 'Branch created', array( 'branch' => $id, 'by' => (int) $by ) );

		return $id;
	}

	/**
	 * ویرایش پروفایل.
	 *
	 * @param string $scope admin | manager
	 *
	 * @return true|WP_Error
	 */
	public static function update( $branch_id, array $data, $scope = 'admin' ) {
		global $wpdb;

		$b = self::get( $branch_id );

		if ( ! $b ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		$clean = self::clean_profile( $data, $scope );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$location = self::validate_location( $clean, $b );
		if ( is_wp_error( $location ) ) { return $location; }

		// نوع مرکز عوض نمی‌شود و نمایندگی هم مرکز نمی‌شود (کیف پول و کسرش می‌ماند)
		unset( $clean['type'] );

		/* نامک مرکز ثابت است: نصب و ارتقا مرکز را با همین نامک پیدا می‌کنند و
		   با نامک دیگر، یک مرکز خالی تازه می‌ساختند و نوبت‌ها جدا می‌افتادند. */
		if ( isset( $clean['slug'] ) && self::is_center( $b ) ) {
			if ( $clean['slug'] !== (string) $b->slug ) {
				return new WP_Error( 'cmb_center_slug', 'نامک مرکز اصلی قابل تغییر نیست.', array( 'status' => 409 ) );
			}

			unset( $clean['slug'] );
		}

		if ( isset( $clean['slug'] ) && self::slug_taken( $clean['slug'], (int) $b->id ) ) {
			return new WP_Error( 'cmb_branch_slug_taken', sprintf( 'نامک «%s» قبلاً برای شعبه‌ی دیگری استفاده شده است.', $clean['slug'] ), array( 'status' => 409 ) );
		}

		if ( $clean ) {
			$wpdb->update( cmb_table( 'branches' ), $clean, array( 'id' => (int) $b->id ) );
		}

		self::flush( (int) $b->id );

		return true;
	}

	/**
	 * فعال یا غیرفعال کردن.
	 *
	 * اولین فعال‌سازی اعتبار رایگان را واریز می‌کند (یک بار برای همیشه:
	 * uniq_ref = welcome:{branch}) و پیامک «پنل شما فعال شد» می‌فرستد.
	 * غیرفعال کردن فقط رزرو تازه را می‌بندد؛ نوبت‌های آینده و ورود
	 * کاربران سر جایشان می‌مانند (US-1.3).
	 *
	 * @return array|WP_Error { status, welcome }
	 */
	public static function set_status( $branch_id, $status, $by = 0 ) {
		global $wpdb;

		$b = self::get( $branch_id );

		if ( ! $b ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			return new WP_Error( 'cmb_bad_status', 'وضعیت معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( self::is_center( $b ) && 'inactive' === $status ) {
			return new WP_Error( 'cmb_center_status', 'مرکز اصلی غیرفعال نمی‌شود.', array( 'status' => 409 ) );
		}

		$first  = 'active' === $status && empty( $b->activated_at );
		$fields = array(
			'status'    => $status,
			'is_active' => 'active' === $status ? 1 : 0,
		);

		if ( $first ) {
			$fields['activated_at'] = current_time( 'mysql' );
		}

		$wpdb->update( cmb_table( 'branches' ), $fields, array( 'id' => (int) $b->id ) );

		self::flush( (int) $b->id );

		$welcome = 0;

		if ( 'active' === $status && self::is_agency( $b ) ) {
			// شرط واقعی یکتایی uniq_ref است، نه activated_at؛ دوباره فعال کردن اثری ندارد
			$welcome = CMB_Branch_Wallet::credit_welcome( (int) $b->id, (int) $by );

			if ( is_wp_error( $welcome ) ) {
				$welcome = 0;
			}

			if ( $first ) {
				$fresh = self::get( (int) $b->id );

				cmb_after_response(
					function () use ( $fresh ) {
						CMB_Branch_Notify::activated( $fresh );
					}
				);
			}
		}

		cmb_log( 'Branch status', array( 'branch' => (int) $b->id, 'status' => $status, 'by' => (int) $by ) );

		return array(
			'status'  => $status,
			'welcome' => (int) $welcome,
		);
	}

	/** نوبت‌های آینده‌ی فعال (برای هشدار پیش از غیرفعال‌سازی یا تعطیلی). */
	public static function future_count( $branch_id, $date = null, $block = null ) {
		global $wpdb;

		$table  = cmb_table( 'bookings' );
		$busy   = cmb_occupying_sql();
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE branch_id = %d AND {$busy}";
		$params = array( (int) $branch_id );

		if ( $date ) {
			$sql     .= ' AND booking_date = %s';
			$params[] = $date;

			if ( $block ) {
				$sql     .= ' AND block_key = %s';
				$params[] = $block;
			}
		} else {
			$sql     .= ' AND booking_date >= %s';
			$params[] = cmb_today();
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	public static function flush( $branch_id = 0 ) {
		CMB_Services::flush_cache();
		CMB_Availability::flush_cache();
	}

	/** کش درون‌درخواستی برنامه‌ی کاری (از CMB_Services::flush_cache). */
	public static function forget() {
		self::$schedule_cache = array();
		self::$agency_ids     = null;
	}

	/* ------------------------------------------------------------------
	 * کاربران پنل
	 * --------------------------------------------------------------- */

	/**
	 * شعبه‌ی یک کاربر پنل.
	 *
	 * کاربری که شعبه ندارد (مسئولان رزرو پیش از نمایندگی‌ها) کاربر مرکز
	 * است. صفر یعنی شعبه‌ای که به آن وصل است دیگر وجود ندارد.
	 */
	public static function user_branch_id( $user_id ) {
		$raw = get_user_meta( (int) $user_id, 'cmb_branch_id', true );

		if ( '' === (string) $raw ) {
			return self::center_id();
		}

		$id = (int) $raw;

		return self::get( $id ) ? $id : 0;
	}

	/**
	 * نقش کاربر در پنل: admin | manager | operator.
	 *
	 * کاربر مرکز بدون نقش صریح مدیر مرکز است (manager، مثل قبل)؛ کاربر
	 * نمایندگی بدون نقش صریح، کمترین اختیار (operator).
	 */
	public static function user_role( $user_id ) {
		$user_id = (int) $user_id;

		if ( user_can( $user_id, 'manage_options' ) ) {
			return 'admin';
		}

		$role = (string) get_user_meta( $user_id, 'cmb_branch_role', true );

		if ( in_array( $role, array( 'manager', 'operator' ), true ) ) {
			return $role;
		}

		return self::is_agency( self::user_branch_id( $user_id ) ) ? 'operator' : 'manager';
	}

	/**
	 * کاربران پنل یک شعبه.
	 *
	 * @return array[]
	 */
	public static function users( $branch_id ) {
		$out   = array();
		$found = get_users( array( 'meta_key' => 'cmb_branch_id', 'meta_value' => (string) (int) $branch_id ) ); // phpcs:ignore
		$seen  = array();

		/* مسئولان رزروی که پیش از نمایندگی‌ها (یا از پیشخوان) ساخته شده‌اند و
		   هنوز شعبه ندارند، کاربر مرکزند و باید در فهرست مرکز دیده شوند. */
		if ( (int) $branch_id === self::center_id() ) {
			$found = array_merge(
				$found,
				get_users(
					array(
						'role'       => CMB_Operators_Role::ROLE,
						'meta_query' => array( array( 'key' => 'cmb_branch_id', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore
					)
				)
			);
		}

		foreach ( $found as $u ) {
			if ( isset( $seen[ $u->ID ] ) || ! CMB_Panel_Api::user_can_manage( $u ) || user_can( $u, 'manage_options' ) ) {
				continue;
			}

			$seen[ $u->ID ] = true;

			$out[] = array(
				'id'      => (int) $u->ID,
				'name'    => (string) $u->display_name,
				'phone'   => cmb_get_user_phone( $u->ID ),
				'phoneFa' => cmb_fa_num( cmb_get_user_phone( $u->ID ) ),
				'role'    => self::user_role( $u->ID ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['role'], $b['role'] );
			}
		);

		return $out;
	}

	/**
	 * آیا این شماره نمی‌تواند کاربر پنل این شعبه شود؟
	 *
	 * @return true|WP_Error
	 */
	protected static function phone_conflict( $phone, $branch_id ) {
		foreach ( CMB_OTP::users_with_phone( $phone ) as $u ) {
			if ( user_can( $u, 'manage_options' ) ) {
				return new WP_Error( 'cmb_phone_admin', 'این شماره مال مدیر سایت است و نمی‌تواند کاربر نمایندگی باشد.', array( 'status' => 409 ) );
			}

			if ( CMB_Panel_Api::user_can_manage( $u ) && self::user_branch_id( $u->ID ) !== (int) $branch_id ) {
				$other = self::get( self::user_branch_id( $u->ID ) );

				return new WP_Error(
					'cmb_phone_other_branch',
					sprintf( 'این شماره همین حالا کاربر پنل «%s» است. یک شماره فقط در یک شعبه می‌تواند کاربر پنل باشد.', $other ? $other->title : 'شعبه‌ی دیگر' ),
					array( 'status' => 409 )
				);
			}
		}

		return true;
	}

	/**
	 * افزودن مدیر یا اپراتور به یک شعبه.
	 *
	 * اگر حسابی با این شماره هست (مثلاً همان شخص قبلاً مشتری بوده)، به
	 * همان حساب دسترسی پنل داده می‌شود؛ یک شماره فقط یک حساب دارد.
	 *
	 * @return int|WP_Error شناسه‌ی کاربر.
	 */
	public static function add_user( $branch_id, $phone, $name, $role ) {
		$b = self::get( $branch_id );

		if ( ! $b ) {
			return new WP_Error( 'cmb_not_found', 'شعبه یافت نشد.', array( 'status' => 404 ) );
		}

		$role  = 'manager' === $role ? 'manager' : 'operator';
		$phone = cmb_normalize_phone( (string) $phone );
		$name  = sanitize_text_field( (string) $name );

		if ( ! $phone ) {
			return new WP_Error( 'cmb_phone', 'شماره موبایل معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹', array( 'status' => 400 ) );
		}

		$conflict = self::phone_conflict( $phone, (int) $b->id );

		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		// نقش با فعال‌سازی افزونه ساخته می‌شود؛ اگر به هر دلیل نبود، همین‌جا
		if ( ! get_role( CMB_Operators_Role::ROLE ) ) {
			add_role( CMB_Operators_Role::ROLE, 'مسئول رزرو چک موتور', array( 'read' => true, CMB_Operators_Role::CAP => true ) );
		}

		$existing = CMB_OTP::users_with_phone( $phone );
		$user     = $existing ? $existing[0] : null;
		$current  = self::users( (int) $b->id );
		$same     = $user ? (int) $user->ID : 0;
		$counts   = array( 'manager' => 0, 'operator' => 0 );

		foreach ( $current as $u ) {
			if ( $u['id'] !== $same ) {
				$counts[ $u['role'] ]++;
			}
		}

		$limits = self::is_agency( $b );

		if ( $limits && 'manager' === $role && $counts['manager'] >= 1 ) {
			return new WP_Error( 'cmb_manager_exists', 'این نمایندگی همین حالا مدیر دارد. اول مدیر فعلی را حذف کنید.', array( 'status' => 409 ) );
		}

		if ( $limits && 'operator' === $role && $counts['operator'] >= self::MAX_OPERATORS ) {
			return new WP_Error( 'cmb_operator_limit', sprintf( 'هر نمایندگی حداکثر %s اپراتور دارد.', cmb_fa_num( self::MAX_OPERATORS ) ), array( 'status' => 409 ) );
		}

		if ( ! $user ) {
			$login = 'cmbp_' . $phone;
			$i     = 2;

			while ( username_exists( $login ) ) {
				$login = 'cmbp_' . $phone . '_' . $i++;
			}

			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'user_email'   => $login . '@panel.checkmotor.local',
					'display_name' => '' !== $name ? $name : $phone,
					'first_name'   => $name,
					'role'         => CMB_Operators_Role::ROLE,
				)
			);

			if ( is_wp_error( $uid ) ) {
				return new WP_Error( 'cmb_user_failed', 'ساخت حساب ناموفق بود: ' . $uid->get_error_message(), array( 'status' => 500 ) );
			}

			$user = get_userdata( $uid );
		} else {
			$user->add_role( CMB_Operators_Role::ROLE );

			if ( '' !== $name && ( '' === (string) $user->display_name || $user->display_name === $phone || $user->display_name === $user->user_login ) ) {
				wp_update_user( array( 'ID' => $user->ID, 'display_name' => $name ) );
			}
		}

		update_user_meta( $user->ID, 'cmb_phone', $phone );
		update_user_meta( $user->ID, 'cmb_branch_id', (int) $b->id );
		update_user_meta( $user->ID, 'cmb_branch_role', $role );

		cmb_log( 'Branch user added', array( 'branch' => (int) $b->id, 'user' => (int) $user->ID, 'role' => $role ) );

		return (int) $user->ID;
	}

	/** آیا این حساب کاربر پنلِ همین شعبه است؟ (کاربر مرکز ممکن است meta شعبه نداشته باشد.) */
	protected static function is_member( $user, $branch_id ) {
		if ( ! $user || self::user_branch_id( $user->ID ) !== (int) $branch_id || ! CMB_Panel_Api::user_can_manage( $user ) ) {
			return false;
		}

		return '' !== (string) get_user_meta( $user->ID, 'cmb_branch_id', true ) || (int) $branch_id === self::center_id();
	}

	/**
	 * تغییر نقش کاربر یک شعبه (مدیر ↔ اپراتور)، با همان سقف نمایندگی:
	 * یک مدیر و حداکثر MAX_OPERATORS اپراتور.
	 *
	 * @return true|WP_Error
	 */
	public static function set_user_role( $branch_id, $user_id, $role ) {
		$user = get_userdata( (int) $user_id );
		$role = 'manager' === $role ? 'manager' : 'operator';
		$b    = self::get( $branch_id );

		if ( ! $b || ! self::is_member( $user, $branch_id ) ) {
			return new WP_Error( 'cmb_not_found', 'این کاربر عضو این شعبه نیست.', array( 'status' => 404 ) );
		}

		if ( self::is_agency( $b ) && self::user_role( $user->ID ) !== $role ) {
			$counts = array( 'manager' => 0, 'operator' => 0 );

			foreach ( self::users( (int) $b->id ) as $u ) {
				if ( $u['id'] !== (int) $user->ID ) {
					$counts[ $u['role'] ]++;
				}
			}

			if ( 'manager' === $role && $counts['manager'] >= 1 ) {
				return new WP_Error( 'cmb_manager_exists', 'این نمایندگی همین حالا مدیر دارد. اول نقش مدیر فعلی را عوض کنید.', array( 'status' => 409 ) );
			}

			if ( 'operator' === $role && $counts['operator'] >= self::MAX_OPERATORS ) {
				return new WP_Error( 'cmb_operator_limit', sprintf( 'هر نمایندگی حداکثر %s اپراتور دارد.', cmb_fa_num( self::MAX_OPERATORS ) ), array( 'status' => 409 ) );
			}
		}

		update_user_meta( $user->ID, 'cmb_branch_id', (int) $b->id );
		update_user_meta( $user->ID, 'cmb_branch_role', $role );

		cmb_log( 'Branch user role', array( 'branch' => (int) $b->id, 'user' => (int) $user->ID, 'role' => $role ) );

		return true;
	}

	/**
	 * گرفتن دسترسی پنل از کاربرِ یک شعبه. حساب خودش (و نوبت‌های مشتری‌اش)
	 * سر جایش می‌ماند.
	 *
	 * @return true|WP_Error
	 */
	public static function remove_user( $branch_id, $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! self::is_member( $user, $branch_id ) ) {
			return new WP_Error( 'cmb_not_found', 'این کاربر عضو این شعبه نیست.', array( 'status' => 404 ) );
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'cmb_user_admin', 'دسترسی مدیر سایت از اینجا گرفته نمی‌شود.', array( 'status' => 409 ) );
		}

		$user->remove_role( CMB_Operators_Role::ROLE );
		$user->remove_cap( CMB_Operators_Role::CAP );

		if ( ! $user->roles ) {
			$user->add_role( get_role( 'cmb_customer' ) ? 'cmb_customer' : 'subscriber' );
		}

		delete_user_meta( $user->ID, 'cmb_branch_id' );
		delete_user_meta( $user->ID, 'cmb_branch_role' );

		return true;
	}

	/**
	 * گیرندگان پیامک یک شعبه: manager_phones به‌علاوه‌ی موبایل مدیرانش.
	 *
	 * @return string[]
	 */
	public static function notify_phones( $branch ) {
		$b = self::row( $branch );

		if ( ! $b ) {
			return array();
		}

		$out = array();

		foreach ( preg_split( '/[\s,،;]+/u', (string) $b->manager_phones ) as $p ) {
			$n = cmb_normalize_phone( $p );

			if ( $n ) {
				$out[ $n ] = true;
			}
		}

		foreach ( self::users( (int) $b->id ) as $u ) {
			if ( 'manager' === $u['role'] && $u['phone'] ) {
				$out[ $u['phone'] ] = true;
			}
		}

		return array_keys( $out );
	}

	/* ------------------------------------------------------------------
	 * شعبه‌ی جاری پنل
	 * --------------------------------------------------------------- */

	/**
	 * شعبه‌ای که پنل برای کاربر جاری نشان می‌دهد.
	 *
	 *   · مدیر سایت: از سرآیند X-CMB-Branch یا پارامتر branch؛ وگرنه آخرین
	 *     انتخابش (user meta)؛ وگرنه مرکز.
	 *   · بقیه: شعبه‌ی خودشان (cmb_branch_id). صفر یعنی دسترسی ندارد.
	 */
	public static function panel_branch_id() {
		if ( null !== self::$panel_branch ) {
			return self::$panel_branch;
		}

		$uid = get_current_user_id();

		if ( ! $uid ) {
			return 0;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return self::user_branch_id( $uid );
		}

		$want = 0;

		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_SERVER['HTTP_X_CMB_BRANCH'] ) ) {
			$want = (int) $_SERVER['HTTP_X_CMB_BRANCH'];
		} elseif ( isset( $_GET['branch'] ) && is_numeric( $_GET['branch'] ) ) {
			$want = (int) $_GET['branch'];
		}
		// phpcs:enable

		if ( ! $want ) {
			$want = (int) get_user_meta( $uid, 'cmb_panel_branch', true );
		}

		return ( $want && self::get( $want ) ) ? $want : self::center_id();
	}

	/** برای تست‌ها و پیش‌بارگذاری پنل. null یعنی دوباره محاسبه شود. */
	public static function set_panel_branch( $branch_id ) {
		self::$panel_branch = null === $branch_id ? null : (int) $branch_id;
	}

	/** شناسه‌ی نمایندگی‌ها (کش درون‌درخواستی). */
	public static function agency_ids() {
		if ( null === self::$agency_ids ) {
			self::$agency_ids = array();

			foreach ( self::ready() ? self::all() : array() as $b ) {
				if ( self::is_agency( $b ) ) {
					self::$agency_ids[] = (int) $b->id;
				}
			}
		}

		return self::$agency_ids;
	}

	/**
	 * آیا ردیفی با این شعبه در دامنه‌ی یک نمای پنل است؟
	 *
	 * پنل مرکز پیش از نمایندگی‌ها همه‌ی ردیف‌ها را نشان می‌داد؛ برای اینکه
	 * چیزی از دیدش پنهان نشود، «هرچه مال نمایندگی نیست» را می‌بیند.
	 * نمایندگی (و هر شعبه‌ی دیگر) فقط ردیف‌های خودش را.
	 */
	public static function in_scope( $row_branch, $view_branch ) {
		$row_branch  = (int) $row_branch;
		$view_branch = (int) $view_branch;

		if ( $view_branch === self::center_id() ) {
			return ! in_array( $row_branch, self::agency_ids(), true );
		}

		return $row_branch === $view_branch;
	}

	/**
	 * همان in_scope به شکل شرط SQL (بدون پارامتر؛ اعداد صحیح‌اند).
	 */
	public static function scope_sql( $view_branch, $col = 'branch_id' ) {
		$view_branch = (int) $view_branch;

		if ( $view_branch === self::center_id() ) {
			$ids = self::agency_ids();

			return $ids ? "{$col} NOT IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' : '1=1';
		}

		return $col . ' = ' . $view_branch;
	}

	/**
	 * ردیفی که مال شعبه‌ی جاری پنل نیست، دیده یا تغییر داده نمی‌شود.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_branch( $branch_id ) {
		if ( self::in_scope( $branch_id, self::panel_branch_id() ) ) {
			return true;
		}

		return new WP_Error( 'cmb_branch_forbidden', 'این مورد مال شعبه‌ی دیگری است و به آن دسترسی ندارید.', array( 'status' => 403 ) );
	}

	/**
	 * اختیار کاربر جاری در پنل، مطابق جدول بخش ۴ و بند ۶.۳ سند نمایندگی‌ها.
	 * فقط ادمین مرکز (مدیر سایت) همه‌چیز را دارد؛ بقیه فقط شعبه‌ی خودشان.
	 *
	 *   کار (بند سند)                                ادمین  مدیر   اپراتور  مدیر      اپراتور
	 *                                                       مرکز   مرکز     نمایندگی  نمایندگی
	 *   bookings  نوبت‌ها: دیدن، تغییر وضعیت، لغو، حذف و
	 *             بازگرداندن — «همان امکانات پنل بوشهر» (US-3.2)  ✓  ✓  ✓  ✓  ✓
	 *   export    مشتری‌های همان شعبه و خروجی‌شان            ✓    ✓      ✓        ✓         ✓
	 *   closures  تعطیلی روز/بلاک — اپراتور «فقط تعطیلی»    ✓    ✓      ✓        ✓         ✓
	 *   schedule  ساعت و ظرفیت بلاک‌ها                      ✓    ✓      –        ✓         –
	 *   services  فعال‌سازی خدمت، قیمت و روزها (US-3.4)      ✓    ✓      –        ✓         –
	 *   catalog   تعریف کاتالوگ مرکزی: ساخت، عنوان، شرح،
	 *             پوستر، سهمیه، بیعانه و حذف خدمت مرکز     ✓    –      –        –         –
	 *   profile / wallet  پروفایل و کیف پول نمایندگی        ✓    –      –        ✓         –
	 *   topup     شارژ آنلاین کیف پول نمایندگی              –    –      –        ✓         –
	 *   center / money  کیف پول مشتری، بازگشت وجه، سلامت
	 *             سیستم و بررسی بیعانه (۶.۳)               ✓    –      –        –         –
	 *   rules     بازه‌ی رزرو و قواعد لغو سراسری (D7)      ✓    –      –        –         –
	 *   own_services  ساخت خدمت مخصوص نمایندگی — خارج از سند
	 *             (US-3.4)؛ فقط اگر ادمین در تنظیمات روشن کند   ✓*  –   –   ✓*  –
	 *
	 * ادمین شارژ آنلاین نمی‌کند (جدول ۴)؛ واریز یا برداشت دستی دارد. مدیریت
	 * نمایندگی‌ها و کاربرانشان، گزارش، مبلغ‌ها و جابه‌جایی بین شعبه‌ها هم فقط
	 * با ادمین است (guard_admin). «مدیر مرکز / اپراتور مرکز» برای کاربران
	 * بوشهر است؛ کاربران فعلی بوشهر مدیر مرکزند.
	 */
	public static function can( $what ) {
		$branch = self::panel_branch_id();
		$admin  = current_user_can( 'manage_options' );

		if ( 'own_services' === $what ) {
			return (bool) CMB_Settings::get( 'agency_own_services', 0 ) && $branch > 0 && self::is_agency( $branch )
				&& ( $admin || 'manager' === self::user_role( get_current_user_id() ) );
		}

		if ( $admin ) {
			return 'topup' !== $what;
		}

		if ( $branch < 1 ) {
			return false;
		}

		$agency  = self::is_agency( $branch );
		$manager = 'manager' === self::user_role( get_current_user_id() );

		switch ( $what ) {
			case 'bookings':
			case 'delete':
			case 'overbook':
			case 'refund_amount':
			case 'export':
			case 'closures':
				return true;

			case 'schedule':
			case 'services':
				return $manager;

			case 'profile':
			case 'wallet':
			case 'topup':
				return $manager && $agency;

			default:
				// center | money | catalog | rules: فقط ادمین
				return false;
		}
	}

	/** برچسب نقش کاربر جاری برای نمایش در پنل. */
	public static function role_label( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( user_can( $user_id, 'manage_options' ) ) {
			return 'مدیر کل';
		}

		$where = self::is_agency( self::user_branch_id( $user_id ) ) ? 'نمایندگی' : 'مرکز';

		return ( 'manager' === self::user_role( $user_id ) ? 'مدیر ' : 'اپراتور ' ) . $where;
	}
}

/**
 * نقش مسئول رزرو؛ جدا از CMB_Operators که فقط در پیشخوان بار می‌شود.
 */
class CMB_Operators_Role {
	const CAP  = 'cmb_manage_bookings';
	const ROLE = 'cmb_operator';
}

/** شعبه‌ی جاری پنل (بخش ۶.۳ سند). */
function cmb_panel_branch_id() {
	return CMB_Branches::panel_branch_id();
}

/** @return true|WP_Error */
function cmb_assert_branch( $branch_id ) {
	return CMB_Branches::assert_branch( $branch_id );
}

/**
 * یک تنظیم برنامه‌ی کاری برای یک شعبه: برنامه‌ی خود نمایندگی، وگرنه
 * تنظیمات سراسری (که برنامه‌ی مرکز است).
 */
function cmb_branch_setting( $branch_id, $key ) {
	if ( $branch_id && in_array( $key, CMB_Branches::schedule_keys(), true ) ) {
		$own = CMB_Branches::schedule( $branch_id );

		if ( isset( $own[ $key ] ) && '' !== (string) $own[ $key ] ) {
			return $own[ $key ];
		}
	}

	return CMB_Settings::get( $key );
}

/**
 * مقایسه‌ی دو رشته به ترتیب الفبای فارسی (برای مرتب کردن استان و شهر).
 */
function cmb_fa_compare( $a, $b ) {
	static $order = null;

	if ( null === $order ) {
		$alphabet = 'آاأإبپتثجچحخدذرزژسشصضطظعغفقکگلمنوهیيكءئؤ';
		$order    = array();

		foreach ( preg_split( '//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY ) as $i => $ch ) {
			$order[ $ch ] = $i + 1;
		}

		$order['ي'] = $order['ی'];
		$order['ك'] = $order['ک'];
	}

	$ca = preg_split( '//u', (string) $a, -1, PREG_SPLIT_NO_EMPTY );
	$cb = preg_split( '//u', (string) $b, -1, PREG_SPLIT_NO_EMPTY );
	$n  = min( count( $ca ), count( $cb ) );

	for ( $i = 0; $i < $n; $i++ ) {
		if ( $ca[ $i ] === $cb[ $i ] ) {
			continue;
		}

		$x = isset( $order[ $ca[ $i ] ] ) ? $order[ $ca[ $i ] ] : 1000 + ( function_exists( 'mb_ord' ) ? mb_ord( $ca[ $i ], 'UTF-8' ) : ord( $ca[ $i ] ) );
		$y = isset( $order[ $cb[ $i ] ] ) ? $order[ $cb[ $i ] ] : 1000 + ( function_exists( 'mb_ord' ) ? mb_ord( $cb[ $i ], 'UTF-8' ) : ord( $cb[ $i ] ) );

		if ( $x !== $y ) {
			return $x < $y ? -1 : 1;
		}
	}

	return count( $ca ) - count( $cb );
}
