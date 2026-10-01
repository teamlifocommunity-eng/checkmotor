<?php
/**
 * توابع کمکی عمومی افزونه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * نام جدول‌های افزونه.
 */
function cmb_table( $name ) {
	global $wpdb;

	return $wpdb->prefix . 'cmb_' . $name;
}

/**
 * منطقه‌ی زمانی سایت (پیش‌فرض تهران).
 */
function cmb_timezone() {
	$tz = wp_timezone();
	if ( $tz instanceof DateTimeZone ) {
		return $tz;
	}

	return new DateTimeZone( 'Asia/Tehran' );
}

/**
 * تاریخ امروز به وقت محلی، فرمت Y-m-d.
 */
/**
 * طول رشته بر حسب حرف، نه بایت.
 *
 * mbstring روی بیشتر هاست‌ها هست ولی نه همه. جایگزین قبلی strlen بود
 * که بایت می‌شمارد: هر حرف فارسی دو بایت است، پس «ب» تنها از شرط
 * «دست‌کم ۲ حرف» رد می‌شد. بررسی نام حتی جایگزین نداشت و روی چنین
 * هاستی هر ثبت نوبت با خطای مرگبار متوقف می‌شد.
 */
function cmb_strlen( $s ) {
	$s = (string) $s;

	if ( function_exists( 'mb_strlen' ) ) {
		return mb_strlen( $s, 'UTF-8' );
	}

	return (int) preg_match_all( '/./us', $s );
}

/**
 * برش رشته بر حسب حرف.
 *
 * substr ممکن است وسط یک حرف فارسی ببُرد و UTF-8 نامعتبر بسازد، که
 * MySQL در حالت strict کل INSERT را برایش رد می‌کند.
 */
function cmb_substr( $s, $start, $length ) {
	$s = (string) $s;

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $s, $start, $length, 'UTF-8' );
	}

	preg_match_all( '/./us', $s, $m );

	return implode( '', array_slice( $m[0], $start, $length ) );
}

/**
 * آیا ستونی در یکی از جدول‌های افزونه وجود دارد؟
 *
 * مهاجرت فقط در پیشخوان اجرا می‌شود. تا آن موقع، نوشتن در ستونی که
 * هنوز ساخته نشده کل INSERT را با خطا رد می‌کند — یعنی بعد از
 * به‌روزرسانی، تا مدیر سری به پیشخوان نزند هیچ نوبتی ثبت نمی‌شد.
 * پس ستون‌های تازه فقط وقتی نوشته می‌شوند که واقعاً وجود داشته باشند.
 *
 * جواب کش می‌شود (هفته‌ای یک بار اگر هست، ساعتی یک بار اگر نیست) و
 * create_tables آن را باطل می‌کند.
 */
function cmb_has_column( $table, $column ) {
	global $wpdb;

	$key    = 'cmb_col_' . $table . '_' . $column;
	$cached = get_transient( $key );

	if ( 'yes' === $cached ) {
		return true;
	}

	if ( 'no' === $cached ) {
		return false;
	}

	$full  = cmb_table( $table );
	$found = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM {$full} LIKE %s", $column ) ); // phpcs:ignore
	$has   = ! empty( $found );

	set_transient( $key, $has ? 'yes' : 'no', $has ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );

	return $has;
}

/**
 * پیشنهادهای شهر برای فیلد فرم رزرو.
 *
 * فقط پیشنهاد است و هر شهری قابل تایپ است. هدفش یکدست ماندن
 * نوشتار است: «بوشهر»، «بوشهر » و «Bushehr» در خروجی اکسل سه شهر
 * جدا حساب می‌شوند. پیش‌فرض شهرهای استان بوشهر است، چون شعبه آنجاست.
 *
 *     add_filter( 'cmb_city_suggestions', function ( $list ) {
 *         return array_merge( $list, array( 'شیراز' ) );
 *     } );
 */
function cmb_city_suggestions() {
	return (array) apply_filters(
		'cmb_city_suggestions',
		array( 'بوشهر', 'برازجان', 'گناوه', 'خورموج', 'کنگان', 'عسلویه', 'دیلم', 'جم', 'اهرم', 'دیر', 'بندر ریگ', 'چغادک', 'شبانکاره' )
	);
}

/**
 * لحظه‌ی جاری به وقت محلی.
 *
 * همه‌ی مقایسه‌های زمانی از همین یک نقطه می‌گذرند تا ساعتِ مرجع در
 * سراسر افزونه یکی باشد — و بشود در تست جایش را گرفت.
 */
function cmb_now() {
	return new DateTime( 'now', cmb_timezone() );
}

function cmb_today() {
	$now = new DateTime( 'now', cmb_timezone() );

	return $now->format( 'Y-m-d' );
}

/**
 * نرمال‌سازی شماره موبایل ایران به فرمت 09xxxxxxxxx.
 *
 * @return string|false
 */
function cmb_normalize_phone( $phone ) {
	$phone = cmb_en_num( trim( (string) $phone ) );
	$phone = preg_replace( '/[^0-9+]/', '', $phone );

	if ( '' === $phone ) {
		return false;
	}

	if ( 0 === strpos( $phone, '+98' ) ) {
		$phone = '0' . substr( $phone, 3 );
	} elseif ( 0 === strpos( $phone, '0098' ) ) {
		$phone = '0' . substr( $phone, 4 );
	} elseif ( 0 === strpos( $phone, '98' ) && strlen( $phone ) === 12 ) {
		$phone = '0' . substr( $phone, 2 );
	} elseif ( 0 === strpos( $phone, '9' ) && strlen( $phone ) === 10 ) {
		$phone = '0' . $phone;
	}

	if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
		return false;
	}

	return $phone;
}

/**
 * شماره موبایل کاربر وردپرس.
 */
/**
 * شماره‌ی موبایل کاربر.
 *
 * فقط cmb_phone را نگاه نمی‌کنیم: سایت ممکن است از قبل یک سیستم ورود
 * پیامکی داشته باشد و شماره را جای خودش ذخیره کرده باشد، یا اصلاً نام
 * کاربری خودِ شماره باشد. اگر این‌ها را نبینیم، کاربری که سال‌هاست
 * عضو سایت است پیام «شماره‌ی موبایل حساب کاربری شما ثبت نشده» می‌گیرد.
 */
function cmb_get_user_phone( $user_id ) {
	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return '';
	}

	$own = (string) get_user_meta( $user_id, 'cmb_phone', true );

	if ( '' !== $own ) {
		return $own;
	}

	$keys = class_exists( 'CMB_OTP' )
		? CMB_OTP::phone_meta_keys()
		: array( 'billing_phone', 'digits_phone', 'mobile' );

	foreach ( $keys as $key ) {
		if ( 'cmb_phone' === $key ) {
			continue;
		}

		$value = cmb_normalize_phone( (string) get_user_meta( $user_id, $key, true ) );

		if ( $value ) {
			return $value;
		}
	}

	// نام کاربری که خودش شماره است (رفتار افزونه‌های ورود پیامکی).
	$user = get_userdata( $user_id );

	if ( $user ) {
		$from_login = cmb_normalize_phone( (string) $user->user_login );

		if ( $from_login ) {
			return $from_login;
		}
	}

	return '';
}

/**
 * آی‌پی بازدیدکننده (برای محدودسازی نرخ ارسال پیامک).
 */
function cmb_get_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return substr( $ip, 0, 45 );
}

/**
 * کلید شیفت‌های زمانی و برچسب فارسی آن‌ها.
 */
function cmb_blocks() {
	$blocks = array(
		'morning'   => array(
			'label'    => 'شیفت صبح',
			'start'    => CMB_Settings::get( 'morning_start', '10:00' ),
			'capacity' => (int) CMB_Settings::get( 'morning_capacity', 2 ),
		),
		'afternoon' => array(
			'label'    => 'شیفت بعدازظهر',
			'start'    => CMB_Settings::get( 'afternoon_start', '17:00' ),
			'capacity' => (int) CMB_Settings::get( 'afternoon_capacity', 3 ),
		),
	);

	return apply_filters( 'cmb_blocks', $blocks );
}

function cmb_block_label( $key ) {
	$blocks = cmb_blocks();

	return isset( $blocks[ $key ] ) ? $blocks[ $key ]['label'] : $key;
}

function cmb_block_start( $key ) {
	$blocks = cmb_blocks();

	return isset( $blocks[ $key ] ) ? $blocks[ $key ]['start'] : '';
}

/**
 * وضعیت‌های نوبت.
 */
function cmb_statuses() {
	return array(
		'confirmed' => 'تایید شده',
		'done'      => 'انجام شده',
		'no_show'   => 'عدم مراجعه',
		'cancelled' => 'لغو شده',
	);
}

function cmb_status_label( $status ) {
	$all = cmb_statuses();

	return isset( $all[ $status ] ) ? $all[ $status ] : $status;
}

/**
 * تولید کد پیگیری یکتا برای نوبت.
 */
function cmb_generate_tracking_code() {
	global $wpdb;

	$table = cmb_table( 'bookings' );

	do {
		$code   = 'CM' . wp_rand( 100000, 999999 );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_code = %s", $code ) ); // phpcs:ignore
	} while ( $exists );

	return $code;
}

/**
 * رشته‌های قابل ترجمه‌ی سمت جاوااسکریپت.
 */
function cmb_js_strings() {
	return array(
		'selectService'   => 'ابتدا خدمت مورد نظر را انتخاب کنید.',
		'selectSlot'      => 'تاریخ و شیفت را انتخاب کنید.',
		'invalidPhone'    => 'شماره موبایل معتبر نیست. نمونه: ۰۹۱۲۳۴۵۶۷۸۹',
		'invalidCode'     => 'کد تایید را کامل وارد کنید.',
		'sending'         => 'در حال ارسال…',
		'submitting'      => 'در حال ثبت نوبت…',
		'networkError'    => 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.',
		'resendIn'        => 'ارسال مجدد تا %s ثانیه دیگر',
		'resend'          => 'ارسال مجدد کد',
		'full'            => 'تکمیل',
		'closed'          => 'تعطیل',
		'remainingOne'    => 'فقط ۱ ظرفیت باقی مانده',
		'remaining'       => '%s ظرفیت خالی',
		'requiredFields'  => 'لطفاً همه‌ی فیلدهای ستاره‌دار را تکمیل کنید.',
	);
}

/**
 * لاگ داخلی افزونه (فقط وقتی WP_DEBUG روشن است).
 */
function cmb_log( $message, $context = array() ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	if ( ! empty( $context ) ) {
		$message .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
	}

	error_log( '[checkmotor-booking] ' . $message ); // phpcs:ignore
}

/**
 * بستن پاسخ برای مرورگر، در حالی که PHP به کارش ادامه می‌دهد.
 *
 * PHP-FPM تابع fastcgi_finish_request دارد و لایت‌اسپید (رایج‌ترین
 * وب‌سرور هاست‌های ایرانی) litespeed_finish_request. روی Apache با
 * mod_php هیچ‌کدام نیست؛ آن‌جا کار مثل قبل پیش از بسته شدن پاسخ انجام
 * می‌شود.
 *
 * @return bool آیا پاسخ واقعاً بسته شد.
 */
function cmb_finish_request() {
	static $done = false;

	if ( $done ) {
		return true;
	}

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		$done = (bool) fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		$done = (bool) litespeed_finish_request();
	}

	return $done;
}

/**
 * انجام یک کار بعد از رسیدن پاسخ به کاربر.
 *
 * برای پیامک‌های اطلاع‌رسانی: ثبت یا لغو نوبت نباید منتظر جواب
 * سرویس پیامک بماند (هر پیامک تا ۲۰ ثانیه مهلت دارد و ثبت نوبت دو یا
 * چند پیامک می‌فرستد). نتیجه‌ی این پیامک‌ها فقط لاگ می‌شد، پس دیرتر
 * فرستادنشان چیزی را از کاربر پنهان نمی‌کند.
 *
 * @param callable $callback
 */
function cmb_after_response( $callback ) {
	global $cmb_after_response;

	if ( ! is_array( $cmb_after_response ) ) {
		$cmb_after_response = array();

		add_action(
			'shutdown',
			function () {
				global $cmb_after_response;

				$jobs               = (array) $cmb_after_response;
				$cmb_after_response = array();

				if ( ! $jobs ) {
					return;
				}

				ignore_user_abort( true );
				cmb_finish_request();

				foreach ( $jobs as $job ) {
					try {
						call_user_func( $job );
					} catch ( Throwable $e ) {
						cmb_log( 'Deferred task failed: ' . $e->getMessage() );
					}
				}
			},
			0
		);
	}

	$cmb_after_response[] = $callback;
}
