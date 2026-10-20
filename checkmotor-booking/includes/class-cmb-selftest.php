<?php
/**
 * آزمون خودکار و «آزمون ثبت» (بخش «آزمون و عیب‌یابی»).
 *
 * مسیر واقعی مشتری روی همین سایت و با همین تنظیمات اجرا می‌شود: تقویم،
 * ورود با کد پیامکی، ثبت نوبت، پرداخت بیعانه، بلوکه‌ی هزینه‌ی نمایندگی،
 * پیامک‌ها، لغو، و ردِ درست حالت‌های نادرست (روز بسته، ظرفیت پر، بیرون از
 * بازه، نمایندگی بی‌موجودی…). همه داخل یک تراکنش دیتابیس انجام و آخر
 * کار برگردانده می‌شود؛ پس هیچ نوبت، کاربر، پرداخت یا ردیف کیف پولی
 * باقی نمی‌ماند.
 *
 * در طول آزمون:
 *   · هیچ درخواستی بیرون نمی‌رود. پیامک و زرین‌پال شبیه‌سازی می‌شوند
 *     (پیامک‌هایی که می‌رفت در گزارش آزمون می‌آیند) و هر درخواست دیگری
 *     رد می‌شود.
 *   · کوکی ورود فرستاده نمی‌شود (مدیر از حساب خودش بیرون نمی‌افتد).
 *   · هوک‌های cmb_booking_* افزونه‌های دیگر صدا زده نمی‌شوند.
 *
 * فقط روی دیتابیسی اجرا می‌شود که برگرداندن تراکنش را واقعاً پشتیبانی کند
 * (InnoDB)؛ پیش از شروع یک آزمایش کوچک برگرداندن انجام می‌شود.
 *
 * @package CheckMotor_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Selftest {

	/** @var bool */
	protected static $active = false;

	protected static $sms     = array();
	protected static $blocked = array();
	protected static $zp      = 0;
	protected static $items   = array();
	protected static $hooks   = array();
	protected static $users   = array();
	protected static $deferred = null;
	protected static $admin   = 0;
	protected static $before  = array();
	protected static $leak    = false;
	protected static $touched = array();
	protected static $trans   = array();

	public static function active() {
		return self::$active;
	}

	public static function touch_option( $name ) {
		self::$touched[ (string) $name ] = true;
	}

	public static function touch_transient( $name ) {
		self::$trans[ (string) $name ] = true;
	}

	/* ------------------------------------------------------------------
	 * پشتیبانی و آماده‌سازی
	 * --------------------------------------------------------------- */

	/**
	 * آیا برگرداندن کامل تراکنش روی این دیتابیس ممکن است؟
	 */
	public static function supported( &$why = '' ) {
		global $wpdb;

		if ( cmb_tx_supported() ) {
			$names = array( $wpdb->users, $wpdb->usermeta, $wpdb->options );

			foreach ( array( 'bookings', 'payments', 'wallet', 'branch_ledger', 'closures', 'services', 'branches', 'otp' ) as $t ) {
				$names[] = cmb_table( $t );
			}

			$in   = implode( ',', array_map( function ( $n ) use ( $wpdb ) { return $wpdb->prepare( '%s', $n ); }, $names ) );
			$rows = $wpdb->get_results( "SELECT TABLE_NAME AS n, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})" ); // phpcs:ignore
			$bad  = array();

			foreach ( (array) $rows as $r ) {
				if ( 'InnoDB' !== $r->e ) {
					$bad[] = $r->n;
				}
			}

			if ( $bad ) {
				$why = 'این جدول‌ها InnoDB نیستند و برگرداندن تغییراتشان ممکن نیست: ' . implode( '، ', $bad ) . '. آزمون خودکار اجرا نشد تا داده‌ی آزمایشی روی سایت نماند.';
				return false;
			}
		}

		$t = cmb_table( 'closures' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
		$wpdb->insert( $t, array( 'branch_id' => 0, 'closure_date' => '1999-01-01', 'block_key' => 'cmbprobe', 'reason' => 'probe', 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE block_key = 'cmbprobe'" ) ) { // phpcs:ignore
			$wpdb->query( "DELETE FROM {$t} WHERE block_key = 'cmbprobe'" ); // phpcs:ignore
			$why = 'دیتابیس این سایت برگرداندن تراکنش را انجام نداد؛ آزمون خودکار اجرا نشد تا داده‌ی آزمایشی روی سایت نماند.';
			return false;
		}

		return true;
	}

	protected static function counts() {
		global $wpdb;

		$out = array( 'users' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) ); // phpcs:ignore

		foreach ( array( 'bookings', 'payments', 'wallet', 'branch_ledger', 'closures', 'otp' ) as $t ) {
			$out[ $t ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . cmb_table( $t ) ); // phpcs:ignore
		}

		return $out;
	}

	protected static function begin() {
		global $wpdb, $wp_filter, $cmb_after_response;

		self::$active   = true;
		self::$sms      = array();
		self::$blocked  = array();
		self::$items    = array();
		self::$users    = array();
		self::$hooks    = array();
		self::$zp       = 0;
		self::$leak     = false;
		self::$touched  = array();
		self::$trans    = array();
		self::$admin    = get_current_user_id();
		self::$before   = self::counts();
		self::$deferred = $cmb_after_response;

		// کارهای پس از پاسخ همین‌جا اجرا می‌شوند (drain)، نه در shutdown
		$cmb_after_response = array();

		CMB_Debug::$mute = true;

		add_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), PHP_INT_MAX, 3 );
		add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

		/* آپشن‌ها و ترنزینت‌هایی که در طول آزمون نوشته می‌شوند: دیتابیس
		   برمی‌گردد ولی کش شیء (به‌خصوص Redis/Memcached) نه؛ آخر کار پاک می‌شوند. */
		add_action( 'added_option', array( __CLASS__, 'touch_option' ) );
		add_action( 'updated_option', array( __CLASS__, 'touch_option' ) );
		add_action( 'deleted_option', array( __CLASS__, 'touch_option' ) );
		add_action( 'setted_transient', array( __CLASS__, 'touch_transient' ) );

		foreach ( array_keys( (array) $wp_filter ) as $tag ) {
			if ( 0 === strpos( (string) $tag, 'cmb_booking_' ) || in_array( $tag, array( 'cmb_user_logged_in', 'wp_login', 'user_register' ), true ) ) {
				self::$hooks[ $tag ] = $wp_filter[ $tag ];
				unset( $wp_filter[ $tag ] );
			}
		}

		$GLOBALS['cmb_tx_outer'] = true;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
	}

	protected static function end() {
		global $wpdb, $wp_filter, $cmb_after_response;

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
		unset( $GLOBALS['cmb_tx_outer'] );

		remove_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), PHP_INT_MAX );
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_action( 'added_option', array( __CLASS__, 'touch_option' ) );
		remove_action( 'updated_option', array( __CLASS__, 'touch_option' ) );
		remove_action( 'deleted_option', array( __CLASS__, 'touch_option' ) );
		remove_action( 'setted_transient', array( __CLASS__, 'touch_transient' ) );

		foreach ( self::$hooks as $tag => $obj ) {
			$wp_filter[ $tag ] = $obj; // phpcs:ignore
		}

		self::$hooks        = array();
		$cmb_after_response = self::$deferred;

		// کش‌هایی که در طول آزمون پر شدند دیگر با دیتابیس نمی‌خوانند
		foreach ( array_unique( array_merge( self::$users, array( self::$admin ) ) ) as $uid ) {
			if ( $uid ) {
				clean_user_cache( $uid );
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		foreach ( array_keys( self::$touched ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		// ترنزینت‌های آزمون (شمارنده‌ها و کش‌ها) از کش بیرونی هم برداشته می‌شوند
		foreach ( array_keys( self::$trans ) as $name ) {
			if ( 'cmb_selftest_lock' !== $name ) {
				delete_transient( $name );
			}
		}
		CMB_Services::flush_cache();
		CMB_Availability::flush_cache();
		CMB_Branches::flush();

		wp_set_current_user( self::$admin );

		self::$leak      = self::counts() !== self::$before;
		CMB_Debug::$mute = false;
		self::$active    = false;
	}

	/**
	 * هیچ درخواستی بیرون نمی‌رود: پیامک و زرین‌پال جواب ساختگی موفق
	 * می‌گیرند، بقیه رد می‌شوند.
	 */
	public static function fake_http( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ok   = function ( $json ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( $json ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};

		if ( false !== strpos( $host, 'payamak-panel.com' ) ) {
			$b = (array) ( isset( $args['body'] ) ? $args['body'] : array() );

			if ( isset( $b['to'] ) ) {
				self::$sms[] = array(
					'to'      => (string) $b['to'],
					'pattern' => isset( $b['bodyId'] ) ? (string) $b['bodyId'] : '',
					'text'    => isset( $b['text'] ) ? (string) $b['text'] : '',
				);
			}

			return $ok( array( 'RetStatus' => 1, 'Value' => '5000000000000001', 'StrRetStatus' => 'Ok' ) );
		}

		if ( false !== strpos( $host, 'zarinpal.com' ) ) {
			if ( false !== strpos( $path, 'request.json' ) ) {
				self::$zp++;

				return $ok( array( 'data' => array( 'code' => 100, 'message' => 'Success', 'authority' => 'S' . str_pad( (string) self::$zp, 35, '0', STR_PAD_LEFT ), 'fee_type' => 'Merchant', 'fee' => 0 ), 'errors' => array() ) );
			}

			if ( false !== strpos( $path, 'verify.json' ) ) {
				return $ok( array( 'data' => array( 'code' => 100, 'message' => 'Paid', 'card_hash' => 'SELFTEST', 'card_pan' => '000000******0000', 'ref_id' => 990000 + self::$zp, 'fee_type' => 'Merchant', 'fee' => 0 ), 'errors' => array() ) );
			}

			return $ok( array( 'data' => array( 'code' => 100, 'message' => 'Success', 'authorities' => array() ), 'errors' => array() ) );
		}

		self::$blocked[ $host ] = true;

		return new WP_Error( 'cmb_selftest_offline', 'در آزمون خودکار درخواست بیرونی فرستاده نمی‌شود.' );
	}

	/** کارهای «پس از پاسخ» (پیامک‌ها) همین حالا، با پیامک شبیه‌سازی‌شده. */
	protected static function drain() {
		global $cmb_after_response;

		for ( $i = 0; $i < 3; $i++ ) {
			$jobs               = (array) $cmb_after_response;
			$cmb_after_response = array();

			foreach ( $jobs as $job ) {
				try {
					call_user_func( $job );
				} catch ( Throwable $e ) {
					self::$items[] = array( 'group' => 'پیامک', 'title' => 'کار پس از پاسخ', 'state' => 'fail', 'detail' => $e->getMessage(), 'ms' => 0 );
				}
			}
		}
	}

	/* ------------------------------------------------------------------
	 * ابزار سناریوها
	 * --------------------------------------------------------------- */

	protected static function rest( $method, $path, $params = array() ) {
		$r = new WP_REST_Request( $method, '/cmb/v1/' . ltrim( $path, '/' ) );
		$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( 'GET' === $method ) {
			$r->set_query_params( $params );
		} else {
			$r->set_header( 'Content-Type', 'application/json' );
			$r->set_body( wp_json_encode( $params ) );
		}

		$res  = rest_do_request( $r );
		$data = $res->get_data();

		return array( (int) $res->get_status(), is_array( $data ) ? $data : array() );
	}

	protected static function err( $d ) {
		$code = isset( $d['code'] ) ? $d['code'] : '';
		$msg  = isset( $d['message'] ) ? $d['message'] : '';

		return trim( $msg . ( $code ? ' [' . $code . ']' : '' ) );
	}

	/**
	 * مشتری تازه‌ی آزمایشی (داخل تراکنش؛ برگردانده می‌شود).
	 *
	 * @param bool $staff برای خدمت «آزمایشی — فقط مدیران».
	 */
	protected static function customer( $staff = false, $phone = '' ) {
		$phone = $phone ? (string) $phone : '0999' . wp_rand( 1000000, 9999999 );
		$role  = $staff ? CMB_Operators_Role::ROLE : ( get_role( 'cmb_customer' ) ? 'cmb_customer' : 'subscriber' );
		$uid   = wp_insert_user(
			array(
				'user_login'   => 'cmbtest_' . $phone . '_' . wp_rand( 100, 999 ),
				'user_pass'    => wp_generate_password( 24 ),
				'role'         => $role,
				'display_name' => 'مشتری آزمون خودکار',
			)
		);

		if ( is_wp_error( $uid ) ) {
			throw new Exception( 'ساخت کاربر آزمایشی ناموفق بود: ' . $uid->get_error_message() );
		}

		update_user_meta( $uid, 'cmb_phone', $phone );
		self::$users[] = (int) $uid;
		wp_set_current_user( $uid );

		return array( (int) $uid, $phone );
	}

	/** پارامترهای فرم مشخصات، مثل اپ. */
	protected static function params( $svc, $date, $block, $b ) {
		$years = cmb_car_years();
		$p     = array(
			'service_id'  => (int) $svc->id,
			'date'        => $date,
			'block'       => $block,
			'name'        => 'مشتری آزمون خودکار',
			'province'    => $b && '' !== (string) $b->province ? (string) $b->province : '',
			'city'        => $b && '' !== (string) $b->city ? (string) $b->city : 'بوشهر',
			'car_brand'   => 'پژو',
			'car_model'   => 'TU5',
			'car_year'    => (string) ( isset( $years[1] ) ? $years[1] : reset( $years ) ),
			'car_mileage' => '120000',
			'note'        => 'آزمون خودکار — برگردانده می‌شود',
		);

		if ( CMB_Payments::service_deposit( $svc ) > 0 ) {
			list( $qs, $q ) = self::rest( 'GET', 'bookings/quote', array( 'service_id' => (int) $svc->id, 'date' => $date, 'block' => $block ) );

			if ( 200 === $qs && ! empty( $q['quote'] ) ) {
				$p['accept_terms'] = 1;
				$p['terms_hash']   = (string) $q['quote']['hash'];
				$p['deposit_seen'] = (int) $q['quote']['deposit'];
			}
		}

		return $p;
	}

	/** نخستین شیفت خالیِ تقویم؛ یا null. */
	protected static function first_open( $cal, $skip = array() ) {
		foreach ( (array) ( isset( $cal['days'] ) ? $cal['days'] : array() ) as $d ) {
			foreach ( $d['blocks'] as $blk ) {
				if ( $blk['available'] && ! in_array( $d['date'] . '|' . $blk['key'], $skip, true ) ) {
					return array( 'date' => $d['date'], 'block' => $blk['key'], 'label' => cmb_fa_num( $d['jalaliFull'] ) . ' — ' . $blk['label'], 'remaining' => (int) $blk['remaining'] );
				}
			}
		}

		return null;
	}

	/**
	 * یک مورد آزمون.
	 *
	 * @param callable $fn برمی‌گرداند array( pass|fail|warn|skip, توضیح )
	 */
	protected static function check( $group, $title, $fn ) {
		$t0 = microtime( true );

		try {
			$r = call_user_func( $fn );
		} catch ( Throwable $e ) {
			$r = array( 'fail', 'خطای PHP: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
		}

		self::$items[] = array(
			'group'  => $group,
			'title'  => $title,
			'state'  => $r[0],
			'detail' => isset( $r[1] ) ? (string) $r[1] : '',
			'ms'     => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
		);

		return 'pass' === $r[0];
	}

	protected static function sms_to( $phone ) {
		return array_values(
			array_filter(
				self::$sms,
				function ( $s ) use ( $phone ) {
					return $s['to'] === $phone;
				}
			)
		);
	}

	/* ------------------------------------------------------------------
	 * آزمون خودکار
	 * --------------------------------------------------------------- */

	/**
	 * @return array|WP_Error
	 */
	public static function run() {
		$why = '';

		if ( ! self::supported( $why ) ) {
			return new WP_Error( 'cmb_selftest_unsupported', $why, array( 'status' => 409 ) );
		}

		if ( get_transient( 'cmb_selftest_lock' ) ) {
			return new WP_Error( 'cmb_selftest_busy', 'آزمون دیگری همین حالا در جریان است؛ یک دقیقه‌ی دیگر دوباره امتحان کنید.', array( 'status' => 409 ) );
		}

		set_transient( 'cmb_selftest_lock', 1, 3 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}

		$t0 = microtime( true );

		self::begin();

		try {
			self::scenarios();
		} catch ( Throwable $e ) {
			self::$items[] = array( 'group' => 'آزمون', 'title' => 'آزمون نیمه‌کاره ماند', 'state' => 'fail', 'detail' => $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'ms' => 0 );
		}

		$sms     = self::$sms;
		$blocked = array_keys( self::$blocked );

		self::end();
		delete_transient( 'cmb_selftest_lock' );

		$n = array( 'pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0 );

		foreach ( self::$items as $i ) {
			$n[ $i['state'] ]++;
		}

		$out = array(
			'items'    => self::$items,
			'passed'   => $n['pass'],
			'failed'   => $n['fail'],
			'warned'   => $n['warn'],
			'skipped'  => $n['skip'],
			'sms'      => array_map(
				function ( $s ) {
					return array( 'to' => CMB_Debug::mask_phone( $s['to'] ), 'pattern' => $s['pattern'] );
				},
				$sms
			),
			'blocked'  => $blocked,
			'leak'     => self::$leak,
			'ms'       => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			'at'       => cmb_fa_num( cmb_jalali_date( cmb_today(), 'full' ) . '، ساعت ' . cmb_now()->format( 'H:i' ) ),
		);

		CMB_Debug::log(
			$n['fail'] ? 'warn' : 'info',
			'selftest',
			'selftest',
			sprintf( 'آزمون خودکار: %d موفق، %d ناموفق، %d هشدار، %d ردشده', $n['pass'], $n['fail'], $n['warn'], $n['skip'] ),
			array(
				'context' => array(
					'failed' => array_values(
						array_map(
							function ( $i ) {
								return $i['group'] . ' / ' . $i['title'] . ': ' . $i['detail'];
							},
							array_filter(
								self::$items,
								function ( $i ) {
									return 'fail' === $i['state'];
								}
							)
						)
					),
				),
			)
		);

		return $out;
	}

	protected static function scenarios() {
		global $wpdb;

		$fee      = CMB_Branches::fee();
		$first    = null;
		$branches = array_values(
			array_filter(
				CMB_Branches::all(),
				function ( $b ) {
					return 'active' === CMB_Branches::status( $b );
				}
			)
		);

		self::check(
			'کلی',
			'مرکز فعال',
			function () use ( $branches ) {
				return $branches ? array( 'pass', cmb_fa_num( count( $branches ) ) . ' مرکز و نمایندگی فعال' ) : array( 'fail', 'هیچ مرکز فعالی نیست؛ مشتری چیزی برای رزرو ندارد.' );
			}
		);

		// فهرست مراکز همان‌طور که اپ می‌گیرد
		wp_set_current_user( 0 );
		self::check(
			'کلی',
			'فهرست مراکز و خدمات برای مشتری',
			function () {
				list( $st, $d ) = self::rest( 'GET', 'branches' );

				if ( 200 !== $st ) {
					return array( 'fail', 'کد ' . $st . ': ' . self::err( $d ) );
				}

				list( $ss, $sd ) = self::rest( 'GET', 'services' );

				return 200 === $ss && isset( $sd['services'] )
					? array( 'pass', sprintf( '%s مرکز قابل انتخاب، %s خدمت در مرکز پیش‌فرض', cmb_fa_num( (int) $d['count'] ), cmb_fa_num( count( (array) $sd['services'] ) ) ) )
					: array( 'fail', 'فهرست خدمات: کد ' . $ss . ' ' . self::err( $sd ) );
			}
		);

		foreach ( array_slice( $branches, 0, 12 ) as $b ) {
			$bid    = (int) $b->id;
			$agency = CMB_Branches::is_agency( $b );
			$group  = (string) $b->title;
			$reason = '';
			$ok     = CMB_Branches::bookable( $b, $reason );

			if ( ! self::check(
				$group,
				'قابل رزرو بودن',
				function () use ( $ok, $reason, $bid ) {
					$why = array(
						'balance'     => 'موجودی آزاد کیف پول نمایندگی (' . cmb_toman( CMB_Branch_Wallet::free( $bid ) ) . ') از هزینه‌ی هر رزرو کمتر است.',
						'no_services' => 'هیچ خدمت فعالی ندارد.',
					);

					return $ok ? array( 'pass', 'بله' ) : array( 'fail', isset( $why[ $reason ] ) ? $why[ $reason ] : $reason );
				}
			) ) {
				continue;
			}

			$services = array_values(
				array_filter(
					CMB_Services::get_services( $bid, false ),
					function ( $s ) {
						return in_array( (int) $s->is_active, array( 1, 2 ), true );
					}
				)
			);

			foreach ( array_slice( $services, 0, 6 ) as $svc ) {
				$staff = 2 === (int) $svc->is_active;
				$label = (string) $svc->title . ( $staff ? ' (آزمایشی)' : '' );

				// تقویم، مثل مشتری مهمان (خدمت آزمایشی فقط با حساب مدیر دیده می‌شود)
				wp_set_current_user( $staff ? self::$admin : 0 );
				list( $st, $cal ) = self::rest( 'GET', 'availability', array( 'service_id' => (int) $svc->id, 'branch_id' => $bid ) );
				$slot = 200 === $st ? self::first_open( $cal ) : null;

				if ( ! self::check(
					$group,
					$label . ': تقویم و نوبت خالی',
					function () use ( $st, $cal, $slot, $bid, $svc ) {
						if ( 200 !== $st ) {
							return array( 'fail', 'کد ' . $st . ': ' . self::err( $cal ) );
						}

						if ( ! $slot ) {
							$s = CMB_Diagnose::service_status( $svc, $bid );

							return array( 'fail', 'در بازه‌ی رزرو هیچ شیفت خالی ندارد — ' . $s['why'] );
						}

						return array( 'pass', 'نخستین نوبت: ' . $slot['label'] . ' (' . cmb_fa_num( $slot['remaining'] ) . ' جای خالی)' );
					}
				) ) {
					continue;
				}

				list( $uid, $phone ) = self::customer( $staff );

				$free0  = $agency ? CMB_Branch_Wallet::free( $bid ) : 0;
				$params = self::params( $svc, $slot['date'], $slot['block'], $b );
				$dep    = CMB_Payments::service_deposit( $svc );

				list( $bs, $bd ) = self::rest( 'POST', 'bookings', $params );
				$bid_row = 200 === $bs && isset( $bd['booking']['id'] ) ? (int) $bd['booking']['id'] : 0;

				if ( ! self::check(
					$group,
					$label . ': ثبت نوبت',
					function () use ( $bs, $bd, $bid_row, $bid ) {
						if ( 200 !== $bs || ! $bid_row ) {
							return array( 'fail', 'کد ' . $bs . ': ' . self::err( $bd ) );
						}

						$row = CMB_Bookings::get( $bid_row );

						if ( (int) $row->branch_id !== $bid ) {
							return array( 'fail', 'نوبت در شعبه‌ی دیگری ثبت شد (' . (int) $row->branch_id . ').' );
						}

						return array( 'pass', 'کد پیگیری ' . $row->tracking_code . ' — وضعیت: ' . cmb_status_label( $row->status ) );
					}
				) ) {
					continue;
				}

				if ( $dep > 0 ) {
					self::check(
						$group,
						$label . ': پرداخت بیعانه (درگاه شبیه‌سازی‌شده)',
						function () use ( $bd, $bid_row, $dep ) {
							if ( empty( $bd['payment']['url'] ) ) {
								return array( 'fail', 'نشانی درگاه برنگشت.' );
							}

							$pay = null;

							foreach ( CMB_Payments::for_booking( $bid_row ) as $p ) {
								$pay = $p;
							}

							$res = $pay ? CMB_Payments::verify_payment( $pay, 'return' ) : 'missing';
							$row = CMB_Bookings::get( $bid_row );

							return 'confirmed' === $row->status && 'paid' === $row->pay_status
								? array( 'pass', 'بیعانه‌ی ' . cmb_toman( $dep ) . ' پرداخت و نوبت قطعی شد.' )
								: array( 'fail', sprintf( 'بعد از پرداخت: %s / %s (%s)', $row->status, $row->pay_status, is_string( $res ) ? $res : '' ) );
						}
					);
				}

				if ( $agency && $fee > 0 ) {
					self::check(
						$group,
						$label . ': بلوکه‌ی هزینه‌ی نمایندگی',
						function () use ( $bid_row, $bid, $free0, $fee ) {
							$row  = CMB_Bookings::get( $bid_row );
							$free = CMB_Branch_Wallet::free( $bid );

							return 'held' === $row->fee_status && (int) $row->branch_fee === $fee && $free === $free0 - $fee
								? array( 'pass', cmb_toman( $fee ) . ' از موجودی آزاد بلوکه شد.' )
								: array( 'fail', sprintf( 'وضعیت هزینه: %s، مبلغ %s، موجودی آزاد %s → %s', $row->fee_status, cmb_toman( (int) $row->branch_fee ), cmb_toman( $free0 ), cmb_toman( $free ) ) );
						}
					);
				}

				$svc_row = CMB_Services::get_service( (int) $svc->id );

				self::check(
					$group,
					$label . ': کم شدن ظرفیت',
					function () use ( $bid, $slot, $svc_row ) {
						CMB_Availability::flush_cache();
						$left = CMB_Availability::remaining( $bid, $slot['date'], $slot['block'], $svc_row );

						return $left === $slot['remaining'] - 1
							? array( 'pass', 'جای خالی ' . cmb_fa_num( $slot['remaining'] ) . ' → ' . cmb_fa_num( $left ) )
							: array( 'fail', sprintf( 'جای خالی باید %s می‌شد، شد %s.', cmb_fa_num( $slot['remaining'] - 1 ), cmb_fa_num( $left ) ) );
					}
				);

				self::drain();

				self::check(
					$group,
					$label . ': پیامک‌ها',
					function () use ( $phone ) {
						if ( ! CMB_SMS::is_enabled() ) {
							return array( 'skip', 'ارسال پیامک خاموش است.' );
						}

						$mine   = self::sms_to( $phone );
						$others = count( self::$sms ) - count( $mine );

						return $mine
							? array( 'pass', sprintf( 'تأیید برای مشتری (پترن %s)%s', $mine[0]['pattern'] ? $mine[0]['pattern'] : 'متن ساده', $others ? '؛ ' . cmb_fa_num( $others ) . ' پیامک دیگر تا اینجا (مدیر/نمایندگی)' : '' ) )
							: array( 'warn', 'برای مشتری پیامک تأیید ساخته نشد؛ پترن «ثبت نوبت» را در تنظیمات پیامک بررسی کنید.' );
					}
				);

				// لغو توسط خود مشتری
				wp_set_current_user( $uid );
				list( $cs, $cd ) = self::rest( 'POST', 'bookings/cancel', array( 'id' => $bid_row, 'reason' => 'آزمون خودکار' ) );

				self::check(
					$group,
					$label . ': لغو توسط مشتری و آزاد شدن ظرفیت',
					function () use ( $cs, $cd, $bid, $slot, $svc_row, $bid_row, $agency, $fee ) {
						if ( 200 !== $cs ) {
							$code = isset( $cd['code'] ) ? $cd['code'] : '';

							return in_array( $code, array( 'cmb_cancel_off', 'cmb_cancel_late' ), true )
								? array( 'warn', 'لغو آنلاین طبق تنظیمات ممکن نیست: ' . self::err( $cd ) )
								: array( 'fail', 'کد ' . $cs . ': ' . self::err( $cd ) );
						}

						CMB_Availability::flush_cache();
						$left = CMB_Availability::remaining( $bid, $slot['date'], $slot['block'], $svc_row );
						$row  = CMB_Bookings::get( $bid_row );

						if ( $left !== $slot['remaining'] ) {
							return array( 'fail', 'ظرفیت بعد از لغو برنگشت (' . cmb_fa_num( $left ) . ').' );
						}

						if ( $agency && $fee > 0 && 'held' === $row->fee_status ) {
							return array( 'fail', 'هزینه‌ی نمایندگی بعد از لغو آزاد نشد.' );
						}

						return array( 'pass', isset( $cd['message'] ) ? $cd['message'] : 'لغو شد.' );
					}
				);

				if ( ! $first && ! $staff ) {
					$first = array( 'b' => $b, 'svc' => $svc_row, 'slot' => $slot, 'cal' => $cal );
				}
			}
		}

		if ( $first ) {
			self::negatives( $first );
		}

		self::otp_flow();
	}

	/**
	 * حالت‌هایی که باید رد شوند — و با پیام درست.
	 */
	protected static function negatives( array $f ) {
		global $wpdb;

		$b     = $f['b'];
		$svc   = $f['svc'];
		$slot  = $f['slot'];
		$bid   = (int) $b->id;
		$group = 'حالت‌های نادرست';

		self::customer();

		$try = function ( $date, $block, $over = array() ) use ( $svc, $b ) {
			return self::rest( 'POST', 'bookings', array_merge( self::params( $svc, $date, $block, $b ), $over ) );
		};

		self::check(
			$group,
			'نام ناقص',
			function () use ( $try, $slot ) {
				list( $st, $d ) = $try( $slot['date'], $slot['block'], array( 'name' => 'ab' ) );

				return 400 === $st ? array( 'pass', self::err( $d ) ) : array( 'fail', 'پذیرفته شد یا خطای دیگری داد: کد ' . $st . ' ' . self::err( $d ) );
			}
		);

		self::check(
			$group,
			'امروز (زودتر از حداقل فاصله)',
			function () use ( $try ) {
				if ( 0 === (int) CMB_Settings::get( 'min_days_ahead', 1 ) && 0 === (int) CMB_Settings::get( 'min_hours_ahead', 24 ) ) {
					return array( 'skip', 'رزرو برای همان روز مجاز است.' );
				}

				list( $st, $d ) = $try( cmb_today(), 'morning' );

				return 200 !== $st ? array( 'pass', self::err( $d ) ) : array( 'fail', 'نوبت امروز پذیرفته شد.' );
			}
		);

		self::check(
			$group,
			'بیرون از بازه‌ی رزرو',
			function () use ( $try, $slot ) {
				$far = ( new DateTime( cmb_today(), cmb_timezone() ) )->modify( '+' . ( (int) CMB_Settings::get( 'min_days_ahead', 1 ) + (int) CMB_Settings::get( 'window_days', 7 ) + 1 ) . ' day' )->format( 'Y-m-d' );

				list( $st, $d ) = $try( $far, $slot['block'] );

				return 200 !== $st ? array( 'pass', self::err( $d ) ) : array( 'fail', 'تاریخ بیرون از بازه پذیرفته شد.' );
			}
		);

		self::check(
			$group,
			'روز بسته‌شده',
			function () use ( $try, $slot, $bid, $svc ) {
				CMB_Availability::set_closure( $bid, $slot['date'], '', 'آزمون خودکار' );
				CMB_Availability::flush_cache();
				CMB_Services::flush_cache();

				wp_set_current_user( 0 );
				list( $cs, $cal ) = self::rest( 'GET', 'availability', array( 'service_id' => (int) $svc->id, 'branch_id' => $bid ) );
				$shown = false;

				foreach ( (array) ( isset( $cal['days'] ) ? $cal['days'] : array() ) as $d ) {
					if ( $d['date'] === $slot['date'] && $d['available'] ) {
						$shown = true;
					}
				}

				self::customer();
				list( $st, $d ) = $try( $slot['date'], $slot['block'] );

				if ( $shown ) {
					return array( 'fail', 'روز بسته در تقویم مشتری هنوز آزاد نشان داده می‌شود.' );
				}

				return 200 !== $st ? array( 'pass', self::err( $d ) ) : array( 'fail', 'نوبت در روز بسته پذیرفته شد.' );
			}
		);

		// باز کردن دوباره‌ی همان روز برای ادامه‌ی آزمون
		$wpdb->delete( cmb_table( 'closures' ), array( 'branch_id' => $bid, 'closure_date' => $slot['date'], 'block_key' => '' ) );
		CMB_Availability::flush_cache();

		self::check(
			$group,
			'ظرفیت پر',
			function () use ( $try, $slot, $svc, $bid ) {
				CMB_Availability::flush_cache();
				$left = CMB_Availability::remaining( $bid, $slot['date'], $slot['block'], $svc );

				if ( $left > 15 ) {
					return array( 'skip', 'ظرفیت این شیفت بیش از ۱۵ است؛ پر کردنش در آزمون لازم نیست.' );
				}

				for ( $i = 0; $i < $left; $i++ ) {
					self::customer();
					list( $st, $d ) = $try( $slot['date'], $slot['block'] );

					if ( 200 !== $st ) {
						return array( 'fail', sprintf( 'نوبت %s از %s جای خالی ثبت نشد: %s', cmb_fa_num( $i + 1 ), cmb_fa_num( $left ), self::err( $d ) ) );
					}

					if ( ! empty( $d['payment'] ) ) {
						foreach ( CMB_Payments::for_booking( (int) $d['payment']['id'] ) as $p ) {
							CMB_Payments::verify_payment( $p, 'return' );
						}
					}
				}

				self::customer();
				list( $st, $d ) = $try( $slot['date'], $slot['block'] );

				return 200 !== $st && 'cmb_block_full' === ( isset( $d['code'] ) ? $d['code'] : '' )
					? array( 'pass', sprintf( '%s نوبت ظرفیت را پر کرد و نوبت بعدی رد شد: %s', cmb_fa_num( $left ), self::err( $d ) ) )
					: array( 'fail', 'بعد از پر شدن ظرفیت: کد ' . $st . ' ' . self::err( $d ) );
			}
		);

		$max = (int) CMB_Settings::get( 'max_active_per_user', 1 );

		self::check(
			$group,
			'سقف نوبت فعال هر مشتری',
			function () use ( $max, $svc, $b, $bid ) {
				if ( $max < 1 || $max > 3 ) {
					return array( 'skip', $max < 1 ? 'سقفی تعیین نشده.' : 'سقف بیش از ۳ است.' );
				}

				self::customer();
				$skip = array();

				for ( $i = 0; $i <= $max; $i++ ) {
					wp_set_current_user( get_current_user_id() );
					list( , $cal ) = self::rest( 'GET', 'availability', array( 'service_id' => (int) $svc->id, 'branch_id' => $bid ) );
					$slot = self::first_open( $cal, $skip );

					if ( ! $slot ) {
						return array( 'skip', 'شیفت خالی کافی برای این آزمون نبود.' );
					}

					$skip[] = $slot['date'] . '|' . $slot['block'];
					list( $st, $d ) = self::rest( 'POST', 'bookings', self::params( $svc, $slot['date'], $slot['block'], $b ) );

					if ( ! empty( $d['payment'] ) ) {
						foreach ( CMB_Payments::for_booking( (int) $d['payment']['id'] ) as $p ) {
							CMB_Payments::verify_payment( $p, 'return' );
						}
					}

					if ( $i < $max && 200 !== $st ) {
						$code = isset( $d['code'] ) ? $d['code'] : '';

						// «یک نوبت از هر خدمت» زودتر از سقف می‌گیرد؛ آن هم درست است
						return 'cmb_same_service' === $code
							? array( 'pass', 'قاعده‌ی «یک نوبت از هر خدمت»: ' . self::err( $d ) )
							: array( 'fail', sprintf( 'نوبت %s (زیر سقف) ثبت نشد: %s', cmb_fa_num( $i + 1 ), self::err( $d ) ) );
					}

					if ( $i === $max ) {
						return 200 !== $st
							? array( 'pass', self::err( $d ) )
							: array( 'fail', 'نوبت بیش از سقف (' . cmb_fa_num( $max ) . ') پذیرفته شد.' );
					}
				}

				return array( 'skip', '' );
			}
		);

		// نمایندگی بی‌موجودی
		$agency = null;

		foreach ( CMB_Branches::all() as $x ) {
			if ( CMB_Branches::is_agency( $x ) && CMB_Branches::bookable( $x ) ) {
				$agency = $x;
				break;
			}
		}

		self::check(
			$group,
			'نمایندگی بی‌موجودی',
			function () use ( $agency ) {
				if ( ! $agency || CMB_Branches::fee() < 1 ) {
					return array( 'skip', 'نمایندگی فعالی با هزینه‌ی رزرو نیست.' );
				}

				$aid  = (int) $agency->id;
				$free = CMB_Branch_Wallet::free( $aid );
				$cut  = CMB_Branch_Wallet::adjust( $aid, -( $free - CMB_Branches::fee() + 1 ), 'آزمون خودکار', self::$admin );

				if ( is_wp_error( $cut ) ) {
					return array( 'skip', 'کم کردن موجودی برای آزمون ممکن نشد: ' . $cut->get_error_message() );
				}

				CMB_Branches::flush();
				$svc = null;

				foreach ( CMB_Services::get_services( $aid, true ) as $s ) {
					$svc = $s;
					break;
				}

				wp_set_current_user( 0 );
				list( $bs, $bd ) = self::rest( 'GET', 'branches' );
				$listed = '';

				foreach ( (array) ( isset( $bd['groups'] ) ? $bd['groups'] : array() ) as $g ) {
					foreach ( (array) ( isset( $g['items'] ) ? $g['items'] : ( isset( $g['branches'] ) ? $g['branches'] : array() ) ) as $it ) {
						if ( isset( $it['id'] ) && (int) $it['id'] === $aid ) {
							$listed = ! empty( $it['bookable'] ) ? 'bookable' : 'off';
						}
					}
				}

				list( $st, $d ) = $svc ? self::rest( 'GET', 'availability', array( 'service_id' => (int) $svc->id, 'branch_id' => $aid ) ) : array( 0, array() );

				if ( 'bookable' === $listed ) {
					return array( 'fail', 'در فهرست مراکز هنوز قابل رزرو نشان داده می‌شود.' );
				}

				return 200 !== $st
					? array( 'pass', 'مرکز «فعلاً نوبت نمی‌گیرد» شد و تقویمش بسته است: ' . self::err( $d ) )
					: array( 'fail', 'تقویم نمایندگی بی‌موجودی هنوز باز است.' );
			}
		);
	}

	/** ورود با کد پیامکی، همان مسیر اپ (پیامک شبیه‌سازی‌شده). */
	protected static function otp_flow() {
		$group = 'ورود با کد پیامکی';
		$phone = '0999' . wp_rand( 1000000, 9999999 );

		wp_set_current_user( 0 );

		list( $st, $d ) = self::rest( 'POST', 'otp/request', array( 'phone' => $phone ) );

		if ( ! self::check(
			$group,
			'درخواست کد',
			function () use ( $st, $d ) {
				return 200 === $st ? array( 'pass', 'کد ساخته و برای ارسال آماده شد.' ) : array( 'fail', 'کد ' . $st . ': ' . self::err( $d ) );
			}
		) ) {
			return;
		}

		$code = '';

		foreach ( self::sms_to( $phone ) as $s ) {
			if ( preg_match( '/\d{' . CMB_OTP::CODE_LENGTH . '}/', cmb_en_num( $s['text'] ), $m ) ) {
				$code = $m[0];
			}
		}

		if ( '' === $code && ! empty( $d['dev_code'] ) ) {
			$code = (string) $d['dev_code'];
		}

		self::check(
			$group,
			'پیامک کد ورود',
			function () use ( $code ) {
				if ( ! CMB_SMS::is_enabled() ) {
					return array( 'warn', 'ارسال پیامک خاموش است؛ مشتری کدی دریافت نمی‌کند.' );
				}

				return '' !== $code ? array( 'pass', 'پیامک کد ساخته شد.' ) : array( 'fail', 'پیامکی با کد ورود ساخته نشد.' );
			}
		);

		if ( '' === $code ) {
			return;
		}

		list( $vs, $vd ) = self::rest( 'POST', 'otp/verify', array( 'phone' => $phone, 'code' => $code, 'name' => 'مشتری آزمون خودکار' ) );

		self::check(
			$group,
			'ورود با کد',
			function () use ( $vs, $vd ) {
				return 200 === $vs && is_user_logged_in() ? array( 'pass', 'حساب ساخته شد و مشتری وارد شد.' ) : array( 'fail', 'کد ' . $vs . ': ' . self::err( $vd ) );
			}
		);

		if ( get_current_user_id() ) {
			self::$users[] = get_current_user_id();
		}
	}

	/* ------------------------------------------------------------------
	 * آزمون ثبت یک شیفت مشخص (شبیه‌ساز)
	 * --------------------------------------------------------------- */

	/**
	 * همان ثبتی که مشتری انجام می‌دهد — با حساب همان شماره اگر هست —
	 * و برگرداندن همه‌چیز. نتیجه: موفق، یا دقیقاً همان خطایی که مشتری می‌بیند.
	 *
	 * @return array|WP_Error
	 */
	public static function dry_run( $service_id, $date, $block, $phone = '' ) {
		$svc = CMB_Services::get_service( $service_id );

		if ( ! $svc ) {
			return new WP_Error( 'cmb_sim_bad', 'خدمت پیدا نشد.', array( 'status' => 400 ) );
		}

		$why = '';

		if ( ! self::supported( $why ) ) {
			return new WP_Error( 'cmb_selftest_unsupported', $why, array( 'status' => 409 ) );
		}

		$b = CMB_Branches::get( (int) $svc->branch_id );

		self::begin();

		$out = array( 'ok' => false );

		try {
			$user = $phone ? CMB_OTP::find_user( $phone ) : null;
			$uid  = $user instanceof WP_User ? (int) $user->ID : (int) $user;

			if ( $uid ) {
				wp_set_current_user( $uid );
				self::$users[] = $uid;
				$out['as'] = 'حساب موجودِ ' . CMB_Debug::mask_phone( $phone );
			} else {
				list( $uid, $phone ) = self::customer( 2 === (int) $svc->is_active, $phone );
				$out['as'] = 'مشتری تازه (' . CMB_Debug::mask_phone( $phone ) . ')';
			}

			list( $st, $d ) = self::rest( 'POST', 'bookings', self::params( $svc, $date, $block, $b ) );

			if ( 200 === $st ) {
				$row = CMB_Bookings::get( (int) $d['booking']['id'] );

				self::drain();

				$out['ok']      = true;
				$out['message'] = 'ثبت می‌شد.';
				$out['booking'] = array(
					'code'    => (string) $row->tracking_code,
					'status'  => cmb_status_label( (string) $row->status ),
					'deposit' => (int) ( isset( $row->deposit_amount ) ? $row->deposit_amount : 0 ),
					'fee'     => (int) ( isset( $row->branch_fee ) ? $row->branch_fee : 0 ),
					'gateway' => ! empty( $d['payment']['url'] ),
				);
			} else {
				$out['status']  = $st;
				$out['code']    = isset( $d['code'] ) ? $d['code'] : '';
				$out['message'] = isset( $d['message'] ) ? $d['message'] : '';
			}
		} catch ( Throwable $e ) {
			$out['message'] = 'خطای PHP: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
		}

		$out['sms'] = array_map(
			function ( $s ) {
				return array( 'to' => CMB_Debug::mask_phone( $s['to'] ), 'pattern' => $s['pattern'] );
			},
			self::$sms
		);

		self::end();

		$out['leak'] = self::$leak;

		return $out;
	}
}
