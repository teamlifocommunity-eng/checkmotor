<?php
/**
 * خواندن موقعیت جغرافیایی از لینک نقشه (۱.۵۰.۰).
 *
 * مدیر شعبه یا نمایندگی لینک «اشتراک‌گذاری» گوگل‌مپ، نشان، بلد، ویز، اپل‌مپ،
 * OpenStreetMap یا متن «۳۵.۷, ۵۱.۴» را می‌چسباند و مختصات خودکار پر می‌شود.
 * همین قواعد در assets/js/location.js هم هست تا بیشتر لینک‌ها بی‌درخواست به
 * سرور خوانده شوند؛ این‌جا برای لینک‌های کوتاه (maps.app.goo.gl، nshn.ir،
 * balad.ir/p/…) که مختصات ندارند و باید تا مقصد دنبال شوند.
 *
 * ترتیب طول و عرض: بعضی سرویس‌ها (مثل مسیریابی بلد) اول طول را می‌نویسند.
 * چون محدوده‌ی عرض ایران (۲۴ تا ۴۰٫۵) و طول آن (۴۴ تا ۶۴) هم‌پوشانی ندارد،
 * جفتی که برعکس داخل ایران می‌افتد جابه‌جا می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Geo_Link {

	/** میزبان‌هایی که سرور اجازه دارد لینک کوتاهشان را دنبال کند. */
	const HOSTS = array(
		'maps.app.goo.gl', 'goo.gl', 'g.co', 'google.com', 'maps.google.com', 'www.google.com', 'consent.google.com',
		'nshn.ir', 'neshan.org', 'www.neshan.org',
		'balad.ir', 'www.balad.ir',
		'waze.com', 'www.waze.com', 'ul.waze.com',
		'maps.apple.com', 'openstreetmap.org', 'www.openstreetmap.org', 'osm.org',
	);

	/** نام فارسی منبع، برای پیام «موقعیت از … خوانده شد». */
	public static function source_name( $text ) {
		$t = strtolower( (string) $text );
		$map = array(
			'goo.gl' => 'گوگل‌مپ', 'google.' => 'گوگل‌مپ', 'g.co/' => 'گوگل‌مپ',
			'neshan' => 'نشان', 'nshn.ir' => 'نشان',
			'balad' => 'بلد', 'waze' => 'ویز', 'apple.com' => 'اپل‌مپ',
			'openstreetmap' => 'OpenStreetMap', 'osm.org' => 'OpenStreetMap', 'geo:' => 'لینک geo',
		);

		foreach ( $map as $needle => $name ) {
			if ( false !== strpos( $t, $needle ) ) {
				return $name;
			}
		}

		return '';
	}

	/** ارقام فارسی و عربی، ویرگول فارسی و ممیز فارسی به انگلیسی. */
	protected static function normalize( $text ) {
		$t = (string) $text;
		$t = strtr( $t, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '،' => ',' ) );

		// دو بار، برای لینک‌هایی که داخل پارامتر continue/link کدگذاری شده‌اند
		return rawurldecode( rawurldecode( $t ) );
	}

	/** جفت معتبر، با جابه‌جایی طول/عرض برعکسِ داخل ایران. */
	protected static function pair( $a, $b ) {
		$a = (float) $a;
		$b = (float) $b;

		$in_iran = function ( $lat, $lng ) {
			return $lat >= 24 && $lat <= 40.5 && $lng >= 43.5 && $lng <= 64;
		};

		if ( ! $in_iran( $a, $b ) && $in_iran( $b, $a ) ) {
			list( $a, $b ) = array( $b, $a );
		}

		if ( abs( $a ) > 90 || abs( $b ) > 180 || ( 0.0 === $a && 0.0 === $b ) ) {
			return null;
		}

		return array( round( $a, 6 ), round( $b, 6 ) );
	}

	/** کدگشایی geohash (لینک‌های waze.com/ul/h…). */
	public static function geohash( $hash ) {
		$chars = '0123456789bcdefghjkmnpqrstuvwxyz';
		$lat   = array( -90.0, 90.0 );
		$lng   = array( -180.0, 180.0 );
		$even  = true;

		foreach ( str_split( strtolower( (string) $hash ) ) as $c ) {
			$v = strpos( $chars, $c );

			if ( false === $v ) {
				return null;
			}

			for ( $bit = 4; $bit >= 0; $bit-- ) {
				$on = ( $v >> $bit ) & 1;

				if ( $even ) {
					$mid = ( $lng[0] + $lng[1] ) / 2;
					$lng[ $on ? 0 : 1 ] = $mid;
				} else {
					$mid = ( $lat[0] + $lat[1] ) / 2;
					$lat[ $on ? 0 : 1 ] = $mid;
				}

				$even = ! $even;
			}
		}

		return array( ( $lat[0] + $lat[1] ) / 2, ( $lng[0] + $lng[1] ) / 2 );
	}

	/**
	 * مختصات از متن لینک (بی‌درخواست شبکه).
	 *
	 * @return array|null [lat, lng]
	 */
	public static function parse( $text ) {
		$t = self::normalize( $text );

		if ( '' === trim( $t ) ) {
			return null;
		}

		$n = '(-?\d{1,3}\.\d+)';

		// به ترتیب دقت: نشانگر محل پیش از مرکز دید نقشه
		$patterns = array(
			'/!3d' . $n . '!4d' . $n . '/',                                   // گوگل: محل دقیق
			'/[?&](?:q|query|ll|sll|destination|daddr|center|coordinate|to)=(?:ll\.|loc:)?' . $n . '\s*,\s*' . $n . '/i',
			'/[?&]latitude=' . $n . '.*?[?&]longitude=' . $n . '/i',         // بلد
			'/[?&]lat=' . $n . '.*?[?&](?:lng|lon|long)=' . $n . '/i',
			'/[?&]mlat=' . $n . '.*?[?&]mlon=' . $n . '/i',                   // OpenStreetMap
			'/\/place\/' . $n . ',\s*' . $n . '/',
			'/@' . $n . ',' . $n . '/',                                       // گوگل و نشان: مرکز دید
			'/#c' . $n . '-' . $n . '-/',                                     // نشان: #c35.7-51.4-17z
			'/#map=\d+(?:\.\d+)?\/' . $n . '\/' . $n . '/',                    // OpenStreetMap
			'/#\d+(?:\.\d+)?\/' . $n . '\/' . $n . '/',                        // بلد: #15/35.7/51.4
			'/\/destination\/' . $n . ',' . $n . '/',                         // نشان: مسیریابی
			'/geo:' . $n . ',' . $n . '/i',
			'/"latitude"\s*:\s*"?' . $n . '"?\s*,\s*"longitude"\s*:\s*"?' . $n . '/i',
			'/"lat"\s*:\s*"?' . $n . '"?\s*,\s*"(?:lng|lon)"\s*:\s*"?' . $n . '/i',
			'/"coordinates"\s*:\s*\[\s*' . $n . '\s*,\s*' . $n . '\s*\]/i',      // GeoJSON: [طول، عرض]؛ pair() برمی‌گرداند
		);

		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $t, $m ) ) {
				$p = self::pair( $m[1], $m[2] );

				if ( $p ) {
					return $p;
				}
			}
		}

		// ویز: waze.com/ul/h<geohash>
		if ( preg_match( '~waze\.com/ul/h([0-9b-hjkmnp-z]{5,12})~i', $t, $m ) ) {
			$g = self::geohash( $m[1] );

			if ( $g ) {
				return self::pair( $g[0], $g[1] );
			}
		}

		// فقط دو عدد اعشاری، مثل «35.7, 51.4» یا «35.7 51.4»
		if ( preg_match( '/^\s*\(?\s*' . $n . '\s*[,\s]\s*' . $n . '\s*\)?\s*$/', $t, $m ) ) {
			return self::pair( $m[1], $m[2] );
		}

		return null;
	}

	/** آیا لینک روی یکی از میزبان‌های مجاز است؟ */
	public static function allowed_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( self::HOSTS as $h ) {
			if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * مختصات لینک؛ لینک کوتاه تا مقصد دنبال می‌شود (فقط میزبان‌های مجاز،
	 * حداکثر ۵ تغییر مسیر، ۸ ثانیه، بدنه‌ی حداکثر ۵۱۲ کیلوبایت).
	 *
	 * @return array|WP_Error { lat, lng, source, url }
	 */
	public static function resolve( $url ) {
		$url = trim( (string) $url );

		if ( preg_match( '~https?://\S+~i', $url, $m ) ) {
			$url = $m[0]; // متن «اشتراک‌گذاری» گاهی جمله‌ای همراه لینک است
		}

		$p = self::parse( $url );

		if ( $p ) {
			return array( 'lat' => $p[0], 'lng' => $p[1], 'source' => self::source_name( $url ), 'url' => $url );
		}

		if ( ! preg_match( '~^https?://~i', $url ) || ! self::allowed_host( $url ) ) {
			return new WP_Error( 'cmb_geo_host', 'این لینک از سرویس‌های نقشه‌ی شناخته‌شده نیست. لینک گوگل‌مپ، نشان، بلد یا ویز را بچسبانید، یا مختصات را دستی وارد کنید.', array( 'status' => 400 ) );
		}

		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 5,
				'limit_response_size' => 512 * KB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; CheckMotorBooking/' . ( defined( 'CMB_VERSION' ) ? CMB_VERSION : '1' ) . ')',
				'headers'             => array( 'Accept-Language' => 'fa,en;q=0.8' ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'cmb_geo_fetch', 'باز کردن لینک ممکن نشد. لینک کامل نقشه (نه کوتاه‌شده) را بچسبانید یا مختصات را دستی وارد کنید.', array( 'status' => 502 ) );
		}

		$final = '';

		if ( isset( $res['http_response'] ) && is_object( $res['http_response'] ) && method_exists( $res['http_response'], 'get_response_object' ) ) {
			$obj   = $res['http_response']->get_response_object();
			$final = isset( $obj->url ) ? (string) $obj->url : '';
		}

		$location = (string) wp_remote_retrieve_header( $res, 'location' );

		foreach ( array( $final, $location, (string) wp_remote_retrieve_body( $res ) ) as $candidate ) {
			$p = '' !== $candidate ? self::parse( $candidate ) : null;

			if ( $p ) {
				return array( 'lat' => $p[0], 'lng' => $p[1], 'source' => self::source_name( $url . ' ' . $final ), 'url' => $final ? $final : $url );
			}
		}

		return new WP_Error( 'cmb_geo_none', 'در این لینک موقعیتی پیدا نشد. در برنامه‌ی نقشه روی محل بزنید و لینک «اشتراک‌گذاری» همان نقطه را بچسبانید، یا مختصات را دستی وارد کنید.', array( 'status' => 422 ) );
	}
}
