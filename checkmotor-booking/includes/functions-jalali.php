<?php
/**
 * توابع تبدیل تاریخ میلادی <-> شمسی (جلالی) بدون وابستگی به اکستنشن intl.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cmb_div' ) ) {
	function cmb_div( $a, $b ) {
		return (int) ( $a / $b );
	}
}

/**
 * میلادی به شمسی.
 *
 * @return array [year, month, day]
 */
function cmb_gregorian_to_jalali( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

	$gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days = 355666 + ( 365 * $gy ) + cmb_div( $gy2 + 3, 4 ) - cmb_div( $gy2 + 99, 100 )
		+ cmb_div( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];

	$jy = -1595 + ( 33 * cmb_div( $days, 12053 ) );
	$days %= 12053;

	$jy += 4 * cmb_div( $days, 1461 );
	$days %= 1461;

	if ( $days > 365 ) {
		$jy += cmb_div( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}

	if ( $days < 186 ) {
		$jm = 1 + cmb_div( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + cmb_div( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}

	return array( $jy, $jm, $jd );
}

/**
 * شمسی به میلادی.
 *
 * @return array [year, month, day]
 */
function cmb_jalali_to_gregorian( $jy, $jm, $jd ) {
	$jy += 1595;
	$days = -355668 + ( 365 * $jy ) + ( cmb_div( $jy, 33 ) * 8 ) + cmb_div( ( $jy % 33 ) + 3, 4 ) + $jd
		+ ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );

	$gy = 400 * cmb_div( $days, 146097 );
	$days %= 146097;

	if ( $days > 36524 ) {
		$gy += 100 * cmb_div( --$days, 36524 );
		$days %= 36524;
		if ( $days >= 365 ) {
			$days++;
		}
	}

	$gy += 4 * cmb_div( $days, 1461 );
	$days %= 1461;

	if ( $days > 365 ) {
		$gy += cmb_div( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}

	$gd = $days + 1;
	$sal_a = array(
		0,
		31,
		( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 ) ) ? 29 : 28,
		31,
		30,
		31,
		30,
		31,
		31,
		30,
		31,
		30,
		31,
	);

	$gm = 0;
	while ( $gm < 13 && $gd > $sal_a[ $gm ] ) {
		$gd -= $sal_a[ $gm ];
		$gm++;
	}

	return array( $gy, $gm, $gd );
}

/**
 * تبدیل تاریخ میلادی Y-m-d به رشته‌ی شمسی.
 *
 * @param string $date   تاریخ به فرمت Y-m-d
 * @param string $format 'full' | 'short' | 'numeric'
 */
function cmb_jalali_date( $date, $format = 'full' ) {
	$parts = explode( '-', $date );
	if ( count( $parts ) !== 3 ) {
		return $date;
	}

	list( $jy, $jm, $jd ) = cmb_gregorian_to_jalali( (int) $parts[0], (int) $parts[1], (int) $parts[2] );

	$months = cmb_jalali_month_names();

	switch ( $format ) {
		case 'numeric':
			return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
		case 'short':
			return $jd . ' ' . $months[ $jm - 1 ];
		case 'full':
		default:
			return cmb_weekday_name( $date ) . '، ' . $jd . ' ' . $months[ $jm - 1 ] . ' ' . $jy;
	}
}

function cmb_jalali_month_names() {
	return array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
}

/**
 * نام روز هفته به فارسی بر اساس تاریخ میلادی Y-m-d.
 */
function cmb_weekday_name( $date ) {
	$names = array(
		0 => 'یکشنبه',
		1 => 'دوشنبه',
		2 => 'سه‌شنبه',
		3 => 'چهارشنبه',
		4 => 'پنجشنبه',
		5 => 'جمعه',
		6 => 'شنبه',
	);

	$w = (int) gmdate( 'w', strtotime( $date . ' 00:00:00' ) );

	return isset( $names[ $w ] ) ? $names[ $w ] : '';
}

/**
 * تبدیل ارقام لاتین به فارسی.
 */
/**
 * تبدیل ارقام لاتین به فارسی.
 *
 * IRANYekanX همراه افزونه است و هر پنج وزنش ارقام فارسی
 * (U+06F0–06F9) را کامل دارند، پس پیش‌فرض روشن است.
 *
 * اگر فونت را با فونت دیگری عوض کردید که این ارقام را ندارد،
 * از «تنظیمات ← ظاهر و فونت» خاموشش کنید تا به‌جای عدد
 * لوزی خالی دیده نشود.
 */
function cmb_persian_digits_on() {
	return (bool) get_option( 'cmb_persian_digits', 1 );
}

function cmb_fa_num( $string ) {
	if ( ! cmb_persian_digits_on() ) {
		return (string) $string;
	}

	$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	return str_replace( $en, $fa, (string) $string );
}

/**
 * فیلد تاریخ شمسی برای صفحات وردپرس.
 *
 * سه انتخابگر روز/ماه/سال می‌دهد. مقدار با cmb_read_jalali_field()
 * خوانده و به میلادی تبدیل می‌شود، پس دیتابیس دست‌نخورده می‌ماند.
 *
 * @param string $name  پیشوند نام فیلدها
 * @param string $value مقدار میلادی فعلی (اختیاری)
 * @param array  $args  ['years' => [از, تا], 'empty' => 'همه']
 */
function cmb_jalali_field( $name, $value = '', $args = array() ) {
	$months = cmb_jalali_month_names();
	$today  = cmb_gregorian_to_jalali( (int) gmdate( 'Y' ), (int) gmdate( 'm' ), (int) gmdate( 'd' ) );

	$sel_y = 0;
	$sel_m = 0;
	$sel_d = 0;

	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m ) ) {
		list( $sel_y, $sel_m, $sel_d ) = cmb_gregorian_to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	$from  = isset( $args['years'][0] ) ? $args['years'][0] : $today[0] - 1;
	$to    = isset( $args['years'][1] ) ? $args['years'][1] : $today[0] + 2;
	$empty = isset( $args['empty'] ) ? $args['empty'] : '—';

	ob_start();
	?>
	<span class="cmb-jdate" style="display:inline-flex;gap:6px;align-items:center">
		<select name="<?php echo esc_attr( $name ); ?>_d">
			<option value=""><?php echo esc_html( $empty ); ?></option>
			<?php for ( $d = 1; $d <= 31; $d++ ) : ?>
				<option value="<?php echo (int) $d; ?>" <?php selected( $sel_d, $d ); ?>><?php echo esc_html( cmb_fa_num( $d ) ); ?></option>
			<?php endfor; ?>
		</select>

		<select name="<?php echo esc_attr( $name ); ?>_m">
			<option value=""><?php echo esc_html( $empty ); ?></option>
			<?php foreach ( $months as $i => $label ) : ?>
				<option value="<?php echo (int) ( $i + 1 ); ?>" <?php selected( $sel_m, $i + 1 ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="<?php echo esc_attr( $name ); ?>_y">
			<option value=""><?php echo esc_html( $empty ); ?></option>
			<?php for ( $y = $from; $y <= $to; $y++ ) : ?>
				<option value="<?php echo (int) $y; ?>" <?php selected( $sel_y, $y ); ?>><?php echo esc_html( cmb_fa_num( $y ) ); ?></option>
			<?php endfor; ?>
		</select>
	</span>
	<?php
	return ob_get_clean();
}

/**
 * خواندن فیلد بالا از $_POST یا $_GET و تبدیل به میلادی.
 *
 * @return string 'YYYY-MM-DD' یا رشته‌ی خالی
 */
function cmb_read_jalali_field( $name, $source = null ) {
	$src = null === $source ? $_REQUEST : $source; // phpcs:ignore

	$d = isset( $src[ $name . '_d' ] ) ? (int) $src[ $name . '_d' ] : 0;
	$m = isset( $src[ $name . '_m' ] ) ? (int) $src[ $name . '_m' ] : 0;
	$y = isset( $src[ $name . '_y' ] ) ? (int) $src[ $name . '_y' ] : 0;

	if ( ! $d || ! $m || ! $y ) {
		return '';
	}

	if ( $m < 1 || $m > 12 || $d < 1 || $d > 31 ) {
		return '';
	}

	list( $gy, $gm, $gd ) = cmb_jalali_to_gregorian( $y, $m, $d );

	$date = sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );

	// اعتبارسنجی نهایی: ۳۱ اسفند وجود ندارد و باید رد شود.
	list( $by, $bm, $bd ) = cmb_gregorian_to_jalali( $gy, $gm, $gd );

	if ( (int) $by !== $y || (int) $bm !== $m || (int) $bd !== $d ) {
		return '';
	}

	return $date;
}

/**
 * تبدیل ارقام فارسی/عربی به لاتین.
 */
function cmb_en_num( $string ) {
	$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
	$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

	return str_replace( $ar, $en, str_replace( $fa, $en, (string) $string ) );
}

/**
 * سال جاری شمسی.
 */
function cmb_current_jalali_year() {
	$parts = explode( '-', cmb_today() );

	if ( count( $parts ) !== 3 ) {
		return 0;
	}

	list( $jy ) = cmb_gregorian_to_jalali( (int) $parts[0], (int) $parts[1], (int) $parts[2] );

	return (int) $jy;
}

/**
 * سال‌های قابل انتخاب برای ساخت خودرو.
 *
 * از یک سال ثابت تا سال جاری، نزولی — تازه‌ترین بالای فهرست، چون
 * بیشتر مراجعه‌ها خودروی نه‌چندان قدیمی است. سقفِ فهرست هر نوروز
 * خودش یک پله بالا می‌رود و نیاز به دست بردن در کد نیست.
 *
 * کفِ فهرست با فیلتر cmb_car_year_min قابل تغییر است، اگر روزی
 * خودروهای قدیمی‌تر هم پذیرفته شوند.
 *
 * @return array فهرست رشته‌ای سال‌ها، نزولی.
 */
function cmb_car_years() {
	$min = (int) apply_filters( 'cmb_car_year_min', 1395 );
	$max = cmb_current_jalali_year();

	if ( $max < $min ) {
		$max = $min;
	}

	return array_map( 'strval', range( $max, $min ) );
}
