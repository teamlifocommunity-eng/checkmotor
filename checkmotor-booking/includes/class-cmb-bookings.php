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

		/* بیعانه: مشتری باید همان قوانین و مبلغی را پذیرفته باشد که الان
		   معتبر است. اگر بین دیدن و زدن دکمه چیزی عوض شده، قوانین تازه
		   دوباره نشان داده می‌شود. */
		$deposit = CMB_Payments::service_deposit( $service );
		$quote   = null;

		if ( $deposit > 0 ) {
			$quote = CMB_Payments::quote( $service, $date, $block_key );

			if ( empty( $data['accept_terms'] ) ) {
				return new WP_Error( 'cmb_terms_required', 'برای ثبت نوبت، قوانین رزرو را بخوانید و تیک پذیرش را بزنید.', array( 'status' => 400, 'quote' => $quote ) );
			}

			if ( ! $quote || empty( $data['terms_hash'] ) || ! hash_equals( $quote['hash'], (string) $data['terms_hash'] )
				|| (int) ( isset( $data['deposit_seen'] ) ? $data['deposit_seen'] : 0 ) !== $deposit ) {
				return new WP_Error(
					'cmb_terms_changed',
					'مبلغ بیعانه یا قوانین رزرو همین حالا تغییر کرده است. قوانین تازه را ببینید و دوباره تأیید کنید.',
					array( 'status' => 409, 'quote' => $quote )
				);
			}

			$rate = self::deposit_rate_limit( $user_id );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
		}

		/* کیف پول: چقدر از بیعانه با موجودی پرداخت شود. مشتری همان تقسیمی
		   را تأیید کرده که دید (wallet_seen)؛ اگر موجودی در این فاصله عوض
		   شده، تقسیم تازه دوباره نشان داده می‌شود. کلاینتِ قدیمی که
		   use_wallet نمی‌فرستد، مثل قبل کل مبلغ را با درگاه می‌پردازد. */
		$wallet_use = 0;

		if ( $deposit > 0 && ! empty( $data['use_wallet'] ) && CMB_Wallet::spend_on() ) {
			$wallet_use = CMB_Wallet::quote_use( $phone, $deposit );
			$seen       = isset( $data['wallet_seen'] ) && '' !== $data['wallet_seen'] ? (int) $data['wallet_seen'] : -1;

			if ( $seen !== $wallet_use ) {
				return new WP_Error(
					'cmb_wallet_changed',
					'موجودی کیف پولتان همین حالا تغییر کرد. مبلغ‌ها را دوباره ببینید و تأیید کنید.',
					array(
						'status' => 409,
						'quote'  => CMB_Payments::quote_for( $service, $date, $block_key, $phone ),
					)
				);
			}
		}

		$lock_name = 'cmb_slot_' . $branch_id . '_' . $date . '_' . $block_key;
		$locked    = cmb_lock( $lock_name, 5 );

		/* قفل گرفته نشد یعنی درخواست دیگری همین حالا روی همین شیفت
		   است. پیش از این بدون قفل ادامه می‌داد و دو رزرو هم‌زمان
		   می‌توانستند هر دو آخرین جا را بگیرند. («none» یعنی دیتابیس
		   قفل نام‌دار ندارد؛ آن‌جا مثل قبل ادامه می‌دهیم.) */
		if ( 'busy' === $locked ) {
			return new WP_Error( 'cmb_busy', 'سرور همین حالا مشغول ثبت نوبت دیگری برای همین شیفت است. چند ثانیه‌ی دیگر دوباره «ثبت» را بزنید.', array( 'status' => 503 ) );
		}

		/* کش ظرفیت درون‌درخواستی است و ممکن است پیش از گرفتن قفل پر
		   شده باشد. شمارش ظرفیت باید حتماً تازه باشد، وگرنه دو رزرو
		   هم‌زمان می‌توانند از سقف رد شوند. */
		CMB_Availability::flush_cache();

		$valid = CMB_Availability::validate_slot( $service, $date, $block_key, $branch_id );

		if ( is_wp_error( $valid ) ) {
			cmb_unlock( $lock_name, $locked );
			return $valid;
		}

		$now   = cmb_now()->format( 'Y-m-d H:i:s' );
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
				'status'        => $deposit > 0 ? 'pending' : 'confirmed',
				'reminder_sent' => 0,
				'ip'            => cmb_get_ip(),
				'created_at'    => $now,
				'updated_at'    => $now,
		);

		// پیش از مهاجرت، ستون شهر وجود ندارد و نوشتنش کل ثبت را خراب می‌کرد
		if ( cmb_has_column( 'bookings', 'city' ) ) {
			$row['city'] = $city;
		}

		if ( CMB_Payments::schema_ready() ) {
			$row['price_at_booking'] = (int) $service->price;

			if ( $deposit > 0 ) {
				$until = CMB_Payments::cancel_until( $date, $block_key );

				// شرایطی که مشتری پذیرفت؛ تغییر بعدی تنظیمات به این نوبت نمی‌رسد
				$row['deposit_amount']       = $deposit;
				$row['cancel_refund_amount'] = (int) $quote['refund'];
				$row['cancel_until']         = $until ? $until->format( 'Y-m-d H:i:s' ) : null;
				$row['terms_accepted_at']    = $now;
				$row['terms_hash']           = $quote['hash'];
				$row['pay_status']           = 'unpaid';
				$row['pay_token']            = wp_generate_password( 40, false, false );
				$row['hold_until_gmt']       = gmdate( 'Y-m-d H:i:s', cmb_now()->getTimestamp() + CMB_Payments::hold_minutes() * MINUTE_IN_SECONDS );
			}
		}

		$inserted = $wpdb->insert( $table, $row );

		cmb_unlock( $lock_name, $locked );

		// شمارش ظرفیتِ کش‌شده دیگر این نوبت تازه را ندارد
		CMB_Availability::flush_cache();

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

		/* با بیعانه: نوبت تا پرداخت «در انتظار» است. نه پیامکی می‌رود و
		   نه هوک ثبت؛ هر دو وقتی پول رسید (CMB_Payments::confirm). */
		if ( $deposit > 0 ) {
			if ( $wallet_use > 0 ) {
				/* کل بیعانه از کیف پول: نوبت همین حالا قطعی می‌شود (هوک‌ها و
				   پیامک تأیید در CMB_Payments::confirm_wallet). وگرنه بخش
				   کیف پول تا رسیدن بخش درگاه کنار گذاشته می‌شود. */
				$paid = $wallet_use >= $deposit
					? CMB_Wallet::pay_full( $booking )
					: CMB_Wallet::hold( $booking, $wallet_use );

				if ( is_wp_error( $paid ) ) {
					self::expire_unpaid( $booking_id, 'wallet' );

					if ( 'cmb_wallet_low' === $paid->get_error_code() ) {
						return new WP_Error(
							'cmb_wallet_changed',
							'موجودی کیف پولتان همین حالا تغییر کرد و نوبت ثبت نشد. مبلغ‌ها را دوباره ببینید و تأیید کنید.',
							array(
								'status' => 409,
								'quote'  => CMB_Payments::quote_for( $service, $date, $block_key, $phone ),
							)
						);
					}

					return $paid;
				}

				if ( $wallet_use >= $deposit ) {
					return self::to_array( self::get( $booking_id ) );
				}

				$booking = self::get( $booking_id );
			}

			$pay = CMB_Payments::start( $booking );

			if ( is_wp_error( $pay ) ) {
				// درگاه جواب نداد: جا و مبلغ کنار گذاشته‌ی کیف پول همین حالا آزاد می‌شوند
				self::expire_unpaid( $booking_id, 'gateway' );

				return new WP_Error(
					'cmb_pay_gateway',
					'اتصال به درگاه پرداخت برقرار نشد و نوبت ثبت نشد. چند دقیقه‌ی دیگر دوباره تلاش کنید. (' . $pay->get_error_message() . ')',
					array( 'status' => 502 )
				);
			}

			$out            = self::to_array( $booking );
			$out['payment'] = array(
				'url'         => $pay['url'],
				'id'          => (int) $booking->id,
				'token'       => (string) $booking->pay_token,
				'holdMinutes' => CMB_Payments::hold_minutes(),
			);

			return $out;
		}

		do_action( 'cmb_booking_created', $booking_id, $booking );

		/* پیامک‌ها بعد از رسیدن پاسخ: مشتری کد پیگیری را همان لحظه
		   می‌بیند، نه بعد از دو سه رفت‌وبرگشت با سرویس پیامک. */
		cmb_after_response(
			function () use ( $booking ) {
				self::notify_customer( $booking );
				self::notify_admin( $booking );
			}
		);

		return self::to_array( $booking );
	}

	/**
	 * نوبتِ «در انتظار پرداخت» که پرداختش شروع نشد: جا و مبلغ کنار
	 * گذاشته‌ی کیف پول همین حالا آزاد می‌شوند.
	 */
	protected static function expire_unpaid( $booking_id, $reason ) {
		global $wpdb;

		$wpdb->update(
			cmb_table( 'bookings' ),
			array(
				'status'         => 'expired',
				'expire_reason'  => $reason,
				'pay_status'     => 'unpaid',
				'hold_until_gmt' => null,
				'updated_at'     => current_time( 'mysql' ),
			),
			array(
				'id'     => (int) $booking_id,
				'status' => 'pending',
			)
		);

		CMB_Wallet::release( $booking_id );
		CMB_Availability::flush_cache();
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

		/* تلاش‌های پرداخت‌نشده بعد از دو روز از فهرست مشتری کنار می‌روند؛
		   وگرنه هر انصراف از درگاه یک ردیف «پرداخت نشد» می‌ماند. */
		$hide = '';

		if ( CMB_Payments::schema_ready() ) {
			$hide = $wpdb->prepare(
				" AND NOT ( status = 'expired' AND pay_status IN ('','unpaid') AND created_at < %s )",
				cmb_now()->modify( '-2 days' )->format( 'Y-m-d H:i:s' )
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d{$hide} ORDER BY booking_date DESC, id DESC LIMIT %d", // phpcs:ignore
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
	public static function active_bookings_for_user( $user_id, $exclude_id = 0 ) {
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

		/* «در انتظار پرداخت» هم تا پایان مهلتش فعال است؛ وگرنه کسی که
		   هنوز در درگاه است می‌توانست هم‌زمان نوبت دیگری بگیرد. */
		$active = ( class_exists( 'CMB_Payments' ) && CMB_Payments::schema_ready() )
			? "( status = 'confirmed' OR ( status = 'pending' AND hold_until_gmt > '" . esc_sql( cmb_now_gmt() ) . "' ) )"
			: "status = 'confirmed'";

		$sql    = "SELECT * FROM {$table} WHERE user_id = %d AND id <> %d AND {$active} AND ( booking_date > %s";
		$params = array( $user_id, (int) $exclude_id, $today );

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
	public static function check_active_limits( $user_id, $service, $exclude_id = 0 ) {
		$max_active  = (int) CMB_Settings::get( 'max_active_per_user', 1 );
		$per_service = (bool) CMB_Settings::get( 'one_per_service', 1 );

		if ( $max_active < 1 && ! $per_service ) {
			return true;
		}

		$active = self::active_bookings_for_user( $user_id, $exclude_id );

		if ( ! $active ) {
			return true;
		}

		$error = self::limit_error( $active, $user_id, $service, $max_active, $per_service );

		if ( true === $error ) {
			return true;
		}

		/* اگر چیزی که جلوی نوبت تازه را گرفته یک نوبتِ «در انتظار
		   پرداخت» است، مشتری باید بتواند همان را تمام کند یا رهایش کند —
		   نه اینکه پیام «با شعبه تماس بگیرید» ببیند. */
		foreach ( $active as $row ) {
			if ( 'pending' !== $row->status ) {
				continue;
			}

			return new WP_Error(
				'cmb_pending_exists',
				sprintf(
					'یک نوبت در انتظار پرداخت دارید (%s، %s). پرداختش را تمام کنید یا از آن انصراف دهید تا بتوانید نوبت دیگری بگیرید.',
					cmb_jalali_date( $row->booking_date, 'full' ),
					cmb_block_label( $row->block_key )
				),
				array(
					'status'  => 409,
					'pending' => array(
						'id'       => (int) $row->id,
						'token'    => (string) $row->pay_token,
						'code'     => (string) $row->tracking_code,
						'sameSlot' => $service && (int) $row->service_id === (int) $service->id,
					),
				)
			);
		}

		return $error;
	}

	/**
	 * خطای سقف نوبت‌ها، یا true.
	 */
	protected static function limit_error( array $active, $user_id, $service, $max_active, $per_service ) {

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

	/**
	 * سقف ثبت نوبت بیعانه‌دار: هر نوبتِ در انتظار پرداخت یک جا را تا
	 * پایان مهلتش نگه می‌دارد، پس بدون سقف می‌شد با چند حساب کل
	 * شیفت‌ها را بی‌پرداخت قفل کرد.
	 *
	 * @return true|WP_Error
	 */
	protected static function deposit_rate_limit( $user_id ) {
		global $wpdb;

		$table = cmb_table( 'bookings' );
		$since = cmb_now()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );

		$by_user = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND deposit_amount > 0 AND created_at >= %s", (int) $user_id, $since ) // phpcs:ignore
		);

		$by_ip = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip = %s AND deposit_amount > 0 AND created_at >= %s", cmb_get_ip(), $since ) // phpcs:ignore
		);

		if ( $by_user >= 5 || $by_ip >= 20 ) {
			return new WP_Error( 'cmb_pay_rate', 'در یک ساعت گذشته چند بار نوبت ثبت و پرداخت نشده است. کمی بعد دوباره تلاش کنید یا با شعبه تماس بگیرید.', array( 'status' => 429 ) );
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
		if ( ! $booking ) {
			return null;
		}

		return cmb_slot_start( (string) $booking->booking_date, $booking->block_key );
	}

	/**
	 * آخرین لحظه‌ای که مشتری می‌تواند خودش نوبت را لغو کند.
	 *
	 * @return DateTime|null
	 */
	public static function cancel_deadline( $booking ) {
		/* نوبت بیعانه‌دار: همان مهلتی که مشتری هنگام پرداخت پذیرفت، حتی
		   اگر بعداً تنظیمات یا ساعت شیفت عوض شود. */
		if ( ! empty( $booking->cancel_until ) && ! empty( $booking->deposit_amount ) ) {
			$fixed = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $booking->cancel_until, cmb_timezone() );

			if ( $fixed ) {
				return $fixed;
			}
		}

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
		$hint = sprintf(
			'مهلت لغو: %s، ساعت %s',
			cmb_jalali_date( $deadline->format( 'Y-m-d' ), 'short' ),
			$deadline->format( 'H:i' )
		);

		if ( isset( $booking->pay_status ) && 'paid' === $booking->pay_status ) {
			$hint .= ' — با لغو، ' . number_format( (int) $booking->cancel_refund_amount ) . ' تومان به کیف پولتان برمی‌گردد';
		}

		return $hint;
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

		// شرطی: دو درخواست هم‌زمانِ لغو (دو بار زدن دکمه) یک بار لغو می‌کنند
		$updated = $wpdb->update(
			cmb_table( 'bookings' ),
			$fields,
			array(
				'id'     => (int) $booking->id,
				'status' => 'confirmed',
			)
		);

		if ( false === $updated ) {
			return new WP_Error( 'cmb_db_error', 'لغو نوبت ناموفق بود. لطفاً دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		if ( 0 === (int) $updated ) {
			return new WP_Error( 'cmb_already_cancelled', 'این نوبت همین حالا لغو شده است. صفحه را تازه کنید.', array( 'status' => 409 ) );
		}

		/* ظرفیت شیفت بلافاصله آزاد می‌شود: شمارش ظرفیت فقط
		   confirmed و done را حساب می‌کند. کش درون‌درخواستی باید
		   خالی شود وگرنه همین درخواست عدد قدیمی را می‌بیند. */
		CMB_Availability::flush_cache();

		/* بیعانه: همان مبلغ بازگشتی که مشتری هنگام پرداخت پذیرفت
		   (cancel_refund_amount روی خود نوبت)، نه مقدار فعلی تنظیمات. */
		if ( isset( $booking->pay_status ) && 'paid' === $booking->pay_status ) {
			CMB_Payments::refund_booking( $booking, (int) $booking->cancel_refund_amount, 'customer', (int) $user_id );
		}

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

		// اطلاع‌رسانی: هم به مشتری (رسید لغو) هم به شعبه — بعد از پاسخ.
		cmb_after_response(
			function () use ( $booking ) {
				self::notify_cancel( $booking, 'customer' );
				self::notify_admin_cancel( $booking );
			}
		);

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
	 * آیا مسئول رزرو می‌تواند وضعیت این نوبت را به $to تغییر دهد؟
	 *
	 * تنها مرجع این قواعد؛ پنل و پیشخوان هر دو از همین می‌خوانند.
	 *
	 * @return true|WP_Error
	 */
	public static function can_transition( $booking, $to ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		$from = (string) $booking->status;

		if ( ! array_key_exists( $to, cmb_manual_statuses() ) ) {
			return new WP_Error( 'cmb_bad_status', 'وضعیت معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( $from === $to ) {
			return new WP_Error( 'cmb_same_status', 'نوبت همین حالا «' . cmb_status_label( $to ) . '» است.', array( 'status' => 409 ) );
		}

		if ( 'pending' === $from ) {
			return 'cancelled' === $to
				? true
				: new WP_Error( 'cmb_unpaid', 'این نوبت هنوز پرداخت نشده؛ فقط می‌شود لغوش کرد.', array( 'status' => 409 ) );
		}

		if ( 'expired' === $from ) {
			return new WP_Error( 'cmb_unpaid', 'بیعانه‌ی این نوبت پرداخت نشد؛ وضعیتش قابل تغییر نیست. اگر مشتری هنوز نوبت می‌خواهد، نوبت تازه بگیرد.', array( 'status' => 409 ) );
		}

		$pay = isset( $booking->pay_status ) ? (string) $booking->pay_status : '';

		if ( 'cancelled' === $from && in_array( $pay, array( 'refund_due', 'refunding', 'refunded', 'kept' ), true ) ) {
			/* برگشتِ کیف پولی پس گرفتنی است (CMB_Wallet::reclaim)؛ برگشتی که
			   پیش از کیف پول به کارت رفته بود نه. */
			$locked = 'refunding' === $pay
				|| ( 'refunded' === $pay && CMB_Wallet::cancel_credit( $booking->id ) < 1 );

			if ( $locked ) {
				return new WP_Error( 'cmb_refund_locked', 'بیعانه‌ی این نوبت به مشتری برگشت داده شده است؛ بازگرداندن نوبت ممکن نیست. نوبت تازه ثبت شود.', array( 'status' => 409 ) );
			}

			if ( 'confirmed' !== $to ) {
				return new WP_Error( 'cmb_restore_first', 'نوبت لغوشده‌ی بیعانه‌دار فقط قابل بازگردانی است.', array( 'status' => 409 ) );
			}
		}

		return true;
	}

	/**
	 * وضعیت‌هایی که پنل برای این نوبت دکمه نشان می‌دهد.
	 *
	 * @return string[]
	 */
	public static function actions_for( $booking ) {
		$out = array();

		foreach ( array_keys( cmb_manual_statuses() ) as $to ) {
			if ( true === self::can_transition( $booking, $to ) ) {
				$out[] = $to;
			}
		}

		if ( true === self::can_delete( $booking ) ) {
			$out[] = 'delete';
		}

		return $out;
	}

	/**
	 * تغییر وضعیت نوبت.
	 *
	 * @param array $args refund: مبلغ برگشتی (تومان) در لغو نوبت پرداخت‌شده؛
	 *                    force: بازگردانی حتی وقتی ظرفیت پر است.
	 *
	 * @return true|WP_Error
	 */
	public static function set_status( $booking_id, $status, array $args = array() ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		$allowed = self::can_transition( $booking, $status );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$now = current_time( 'mysql' );

		// لغو نوبتِ پرداخت‌نشده = پایان مهلت پرداخت؛ جا همین حالا آزاد می‌شود
		if ( 'pending' === $booking->status ) {
			$wpdb->update(
				cmb_table( 'bookings' ),
				array(
					'status'         => 'expired',
					'expire_reason'  => 'admin',
					'pay_status'     => 'unpaid',
					'hold_until_gmt' => null,
					'updated_at'     => $now,
				),
				array( 'id' => (int) $booking_id )
			);

			CMB_Wallet::release( $booking_id );
			CMB_Availability::flush_cache();
			do_action( 'cmb_booking_status_changed', (int) $booking_id, 'expired', 'pending' );

			return true;
		}

		$pay  = isset( $booking->pay_status ) ? (string) $booking->pay_status : '';
		$main = 'refund_due' === $pay ? CMB_Payments::main_payment( $booking->id ) : null;

		/* بیعانه دارد؟ نوبت ممکن است تمامش با کیف پول پرداخت شده باشد و
		   ردیف پرداختی نداشته باشد. */
		$deposit = ! empty( $booking->deposit_amount ) && in_array( $pay, array( 'paid', 'refund_due', 'kept', 'refunded' ), true );

		// بازگردانی: جا باید هنوز باشد (مگر مدیر عمداً بخواهد)
		if ( 'cancelled' === $booking->status && 'confirmed' === $status && empty( $args['force'] ) ) {
			$service = CMB_Services::get_service( $booking->service_id );

			CMB_Availability::flush_cache();

			if ( $service && CMB_Availability::remaining( (int) $booking->branch_id, $booking->booking_date, $booking->block_key, $service ) < 1 ) {
				return new WP_Error(
					'cmb_restore_full',
					'ظرفیت این شیفت پر است. با بازگرداندن این نوبت، تعداد نوبت‌ها از ظرفیت بیشتر می‌شود.',
					array(
						'status'  => 409,
						'canForce' => true,
					)
				);
			}
		}

		// مبلغ برگشت در لغو از طرف مجموعه؛ پیش از هر تغییری بررسی می‌شود
		$refund = null;

		if ( 'cancelled' === $status && $deposit && 'paid' === $pay ) {
			// کل بیعانه، چه از درگاه آمده باشد چه از کیف پول
			$paid   = (int) $booking->deposit_amount;
			$refund = ( isset( $args['refund'] ) && '' !== $args['refund'] && null !== $args['refund'] )
				? (int) cmb_en_num( (string) $args['refund'] )
				: CMB_Payments::shop_refund_default( $paid );

			$valid = CMB_Payments::validate_refund_amount( $refund, $paid );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		// برگشتی که هنوز در صف مانده بود (واریز به کیف پول ممکن نشده بود) برداشته می‌شود
		if ( 'cancelled' === $booking->status && 'confirmed' === $status && $main ) {
			$cleared = CMB_Payments::clear_refund( $main );

			if ( is_wp_error( $cleared ) ) {
				return $cleared;
			}
		}

		$fields = array(
			'status'     => $status,
			'updated_at' => $now,
		);

		/* بازگرداندن نوبتی که بیعانه‌اش به کیف پول برگشته بود: همان مبلغ
		   از کیف پول پس گرفته می‌شود، اگر مشتری هنوز خرجش نکرده. */
		if ( 'cancelled' === $booking->status && 'confirmed' === $status && 'refunded' === $pay ) {
			$taken = CMB_Wallet::reclaim( $booking, get_current_user_id() );

			if ( is_wp_error( $taken ) ) {
				return $taken;
			}

			$fields['pay_status']    = 'paid';
			$fields['refund_amount'] = 0;
		}

		if ( 'cancelled' === $status && self::has_cancel_columns() ) {
			$fields['cancelled_by'] = 'branch';
			$fields['cancelled_at'] = $now;
		}

		// عدم مراجعه: بیعانه نزد مجموعه می‌ماند؛ برگشت از آن، دوباره «پرداخت‌شده»
		if ( $deposit && 'no_show' === $status && 'paid' === $pay ) {
			$fields['pay_status'] = 'kept';
		} elseif ( $deposit && 'kept' === $pay && in_array( $status, array( 'confirmed', 'done' ), true ) ) {
			$fields['pay_status'] = 'paid';
		}

		$wpdb->update( cmb_table( 'bookings' ), $fields, array( 'id' => (int) $booking_id ) );

		if ( null !== $refund ) {
			CMB_Payments::refund_booking( $booking, $refund, 'shop', get_current_user_id() );
		}

		// ظرفیت شیفت با لغو آزاد می‌شود؛ شمارشِ کش‌شده باید کهنه نماند.
		CMB_Availability::flush_cache();

		do_action( 'cmb_booking_status_changed', $booking_id, $status, $booking->status );

		if ( 'cancelled' === $status && 'cancelled' !== $booking->status ) {
			$cancelled = self::get( $booking_id );

			cmb_after_response(
				function () use ( $cancelled ) {
					self::notify_cancel( $cancelled, 'branch' );
				}
			);
		}

		return true;
	}

	/**
	 * نوبتی که پولش آمده یا در راه است حذف نمی‌شود؛ ردیف پرداخت و صف
	 * برگشت وجه به آن اشاره می‌کنند.
	 *
	 * @return true|WP_Error
	 */
	public static function can_delete( $booking ) {
		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		foreach ( CMB_Payments::for_booking( $booking->id ) as $p ) {
			if ( in_array( $p->status, array( 'paid', 'requested' ), true ) ) {
				return new WP_Error( 'cmb_has_payment', 'برای این نوبت بیعانه پرداخت شده و حذفش سابقه‌ی پرداخت را بی‌صاحب می‌کند. به‌جای حذف، لغوش کنید.', array( 'status' => 409 ) );
			}
		}

		// بیعانه‌ای که با کیف پول پرداخت شده هم ردیف پرداخت ندارد ولی سابقه دارد
		if ( CMB_Wallet::schema_ready() ) {
			global $wpdb;

			$wt = cmb_table( 'wallet' );

			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wt} WHERE booking_id = %d AND status IN ('done','held') LIMIT 1", (int) $booking->id ) ) ) { // phpcs:ignore
				return new WP_Error( 'cmb_has_payment', 'این نوبت در کیف پول مشتری سابقه دارد و حذفش آن سابقه را بی‌صاحب می‌کند. به‌جای حذف، لغوش کنید.', array( 'status' => 409 ) );
			}
		}

		return true;
	}

	/**
	 * حذف کامل یک نوبت.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $booking_id ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		$allowed = self::can_delete( $booking );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$wpdb->delete( cmb_table( 'bookings' ), array( 'id' => (int) $booking_id ) );

		if ( CMB_Payments::schema_ready() ) {
			$wpdb->delete( cmb_table( 'payments' ), array( 'booking_id' => (int) $booking_id ) );
		}

		CMB_Availability::flush_cache();

		return true;
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
			/* بیعانه: مبلغ، وضعیت و برگشت. null برای نوبت بی‌بیعانه. */
			'pay'          => CMB_Payments::summary( $booking ),
			'holdLeft'     => ( 'pending' === $booking->status && ! empty( $booking->hold_until_gmt ) )
				? max( 0, strtotime( $booking->hold_until_gmt . ' UTC' ) - cmb_now()->getTimestamp() )
				: 0,
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
}
