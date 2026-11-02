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

	/** پرش‌های آخرین resolve، برای گزارش عیب‌یابی وقتی موقعیت پیدا نشد. */
	protected static $hops = array();

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

		// بدنه‌ی صفحه: &amp; ،\/ و \u0026 در HTML و JSON
		if ( false !== strpos( $t, '&' ) || false !== strpos( $t, '\\' ) ) {
			$t = str_replace( array( '\\/', '\\u0026', '\\u003d', '\\u003D' ), array( '/', '&', '=', '=' ), $t );
			$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

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
		$d = self::parse_detail( $text );

		return $d ? array( $d[0], $d[1] ) : null;
	}

	/** ارقام فارسی/عربی به انگلیسی (برای خود نشانی لینک، مثل nshn.ir/۲bEY…). */
	public static function latin_digits( $text ) {
		return strtr( (string) $text, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
	}

	/**
	 * مثل parse، با نشانه‌ی دقت.
	 *
	 * «دقیق»: نشانگر محل، مقصد مسیریابی، مختصات صریح. «تقریبی»: فقط مرکز دید نقشه
	 * (@lat,lng گوگل/نشان، #c نشان، #z/lat/lng بلد) که با پنل کناری صفحه تا چند صد متر
	 * با خود محل فاصله دارد؛ مثلاً در نشان مقصد 28.942091,50.935555 ولی #c28.942-50.941.
	 *
	 * @return array|null [lat, lng, approx(bool)]
	 */
	public static function parse_detail( $text ) {
		$t = self::normalize( $text );

		if ( '' === trim( $t ) ) {
			return null;
		}

		$n = '(-?\d{1,3}\.\d+)';

		// دقیق: [الگو، جابه‌جایی طول/عرض]
		$exact = array(
			array( '/!3d' . $n . '!4d' . $n . '/' ),                                   // گوگل: نشانگر
			array( '/\/destination\/' . $n . ',' . $n . '/' ),                          // نشان: مسیریابی
			array( '/[?&](?:q|query|ll|sll|destination|daddr|center|coordinate|coords?|latlng|latLng|to|markers?|location|loc|point|pt)=(?:ll\.|loc:)?' . $n . '\s*,\s*' . $n . '/i' ),
			array( '/[?&]latitude=' . $n . '.*?[?&]longitude=' . $n . '/i' ),         // بلد
			array( '/[?&]lat=' . $n . '.*?[?&](?:lng|lon|long)=' . $n . '/i' ),
			array( '/[?&]mlat=' . $n . '.*?[?&]mlon=' . $n . '/i' ),                   // OpenStreetMap
			array( '/\/(?:place|search)\/' . $n . ',(?:\+|\s)*' . $n . '/' ),           // گوگل: /search/35.7,+51.4
			array( '/\/dir\/(?:[^\/@?#]*\/)*' . $n . ',(?:\+|\s)*' . $n . '(?:[\/?#]|$)/' ), // گوگل: مسیر؛ آخرین نقطه = مقصد
			array( '/geo:' . $n . ',' . $n . '/i' ),
			// داده‌ی صفحه (لینک کوتاهی که به صفحه‌ی مکان می‌رسد)
			array( '/"lat(?:itude)?"\s*:\s*"?' . $n . '"?[^{}\[\]]{0,200}?"(?:lng|lon|long|longitude)"\s*:\s*"?' . $n . '/i' ),
			array( '/"(?:lng|lon|long|longitude)"\s*:\s*"?' . $n . '"?[^{}\[\]]{0,200}?"lat(?:itude)?"\s*:\s*"?' . $n . '/i', true ),
			array( '/"x"\s*:\s*"?' . $n . '"?\s*,\s*"y"\s*:\s*"?' . $n . '/i', true ),  // نشان: x = طول، y = عرض
			array( '/"y"\s*:\s*"?' . $n . '"?\s*,\s*"x"\s*:\s*"?' . $n . '/i' ),
			array( '/latitude["\']\s+content=["\']' . $n . '["\'][\s\S]{0,400}?longitude["\']\s+content=["\']' . $n . '/i' ), // متای place:location
			array( '/content=["\']' . $n . '["\']\s+(?:property|itemprop|name)=["\'][a-z:]*latitude["\'][\s\S]{0,400}?content=["\']' . $n . '["\']\s+(?:property|itemprop|name)=["\'][a-z:]*longitude/i' ),
			array( '/"coordinates"\s*:\s*\[\s*' . $n . '\s*,\s*' . $n . '\s*\]/i', true ), // GeoJSON: [طول، عرض]
			array( '/\[null,null,' . $n . ',' . $n . '\]/' ),                                   // گوگل: نقطه‌ی مکان در صفحه
			// تصویر پیش‌نمایش (og:image / twitter:image) که نقشه‌ی ایستای همان نقطه است، با هر چینش نشانی
			array( '/(?:og:image|twitter:image)["\'][^>]*?content=["\'][^"\']*?[\/=,_|:-]' . $n . '[\/,_|:-]' . $n . '(?:[\/,_|.&?:"\'-]|$)/i' ),
			array( '/content=["\'][^"\']*?[\/=,_|:-]' . $n . '[\/,_|:-]' . $n . '(?:[\/,_|.&?:"\'-][^"\']*)?["\'][^>]*?(?:og:image|twitter:image)/i' ),
		);

		foreach ( $exact as $e ) {
			if ( preg_match( $e[0], $t, $m ) ) {
				$p = empty( $e[1] ) ? self::pair( $m[1], $m[2] ) : self::pair( $m[2], $m[1] );

				if ( $p ) {
					return array( $p[0], $p[1], false );
				}
			}
		}

		// ویز: waze.com/ul/h<geohash>
		if ( preg_match( '~waze\.com/(?:ul/h|[^\s"\']*?[?&]h=)([0-9b-hjkmnp-z]{5,12})~i', $t, $m ) ) {
			$g = self::geohash( $m[1] );
			$p = $g ? self::pair( $g[0], $g[1] ) : null;

			if ( $p ) {
				return array( $p[0], $p[1], false );
			}
		}

		// درجه/دقیقه/ثانیه، مثل «31°19'12.4"N 48°40'09.1"E» (گوگل‌مپ و گوگل‌ارث)
		$dms = '(\d{1,3})\s*°\s*(\d{1,2})\s*[\'′]\s*(\d{1,2}(?:\.\d+)?)\s*(?:"|″|\'\')?\s*';
		if ( preg_match( '/' . $dms . '([NS])[\s,+]*' . $dms . '([EW])/iu', $t, $m ) ) {
			$la = ( $m[1] + $m[2] / 60 + $m[3] / 3600 ) * ( 'S' === strtoupper( $m[4] ) ? -1 : 1 );
			$lo = ( $m[5] + $m[6] / 60 + $m[7] / 3600 ) * ( 'W' === strtoupper( $m[8] ) ? -1 : 1 );
			$p  = self::pair( $la, $lo );

			if ( $p ) {
				return array( $p[0], $p[1], false );
			}
		}

		// دو عدد اعشاری، مثل «35.7, 51.4»؛ یا متن بی‌لینک مثل «مختصات: ۲۸٫۹۴۲۰۹۱، ۵۰٫۹۳۵۵۵۵» (کپی از نشان)
		if ( preg_match( '/^\s*\(?\s*' . $n . '\s*[,\s]\s*' . $n . '\s*\)?\s*$/', $t, $m )
			|| ( false === strpos( $t, '://' ) && strlen( $t ) < 200 && ! preg_match( '/[<>{}\[\]]/', $t ) && preg_match( '/(-?\d{1,3}\.\d{3,})\s*[,\s\/]\s*(-?\d{1,3}\.\d{3,})/', $t, $m ) ) ) {
			$p = self::pair( $m[1], $m[2] );

			if ( $p ) {
				return array( $p[0], $p[1], false );
			}
		}

		// تقریبی: فقط مرکز دید نقشه
		$approx = array(
			array( '/@' . $n . ',' . $n . '/' ),                                       // گوگل و نشان
			array( '/#c' . $n . '-' . $n . '-/' ),                                     // نشان: #c35.7-51.4-17z
			array( '/#map=\d+(?:\.\d+)?\/' . $n . '\/' . $n . '/' ),                    // OpenStreetMap
			array( '/#\d+(?:\.\d+)?\/' . $n . '\/' . $n . '/' ),                        // بلد: #15/35.7/51.4
		);

		foreach ( $approx as $e ) {
			if ( preg_match( $e[0], $t, $m ) ) {
				$p = empty( $e[1] ) ? self::pair( $m[1], $m[2] ) : self::pair( $m[2], $m[1] );

				if ( $p ) {
					return array( $p[0], $p[1], true );
				}
			}
		}

		return null;
	}

	/** آیا لینک روی یکی از میزبان‌های مجاز است؟ */
	public static function allowed_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( (array) apply_filters( 'cmb_geo_link_hosts', self::HOSTS ) as $h ) {
			if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}

		return false;
	}

	/** پرش بعدی که خود صفحه نشان می‌دهد: meta refresh، location در جاوااسکریپت، og:url یا canonical. */
	protected static function next_from_body( $body ) {
		$b = str_replace( array( '\\/', '\\u0026' ), array( '/', '&' ), (string) $body );
		$res = array(
			'~http-equiv=["\']?refresh["\']?[^>]*content=["\'][^"\']*?url\s*=\s*["\']?([^"\'\s>]+)~i',
			'~content=["\'][^"\']*?url\s*=\s*([^"\'\s>]+)["\'][^>]*http-equiv=["\']?refresh~i',
			'~(?:window\.|document\.)?location(?:\.href)?\s*=\s*["\'](https?://[^"\']+)~i',
			'~location\.(?:replace|assign)\(\s*["\'](https?://[^"\']+)~i',
			'~property=["\']og:url["\'][^>]*content=["\']([^"\']+)~i',
			'~content=["\']([^"\']+)["\'][^>]*property=["\']og:url~i',
			'~rel=["\']canonical["\'][^>]*href=["\']([^"\']+)~i',
			'~data-url=["\'](/maps/[^"\']+|https?://[^"\']+)~i',                  // گوگل: صفحه‌ی جست‌وجو
		);

		foreach ( $res as $re ) {
			if ( preg_match( $re, $b, $m ) ) {
				return html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return '';
	}

	/**
	 * مختصات لینک؛ لینک کوتاه گام‌به‌گام تا مقصد دنبال می‌شود (حداکثر ۶ پرش، هر کدام
	 * ۸ ثانیه و بدنه‌ی حداکثر ۵۱۲ کیلوبایت). هر پرش جداست تا Location (حتی اگر به
	 * intent:// یا neshan:// برود)، meta refresh و og:url هر کدام خوانده شوند.
	 *
	 * @return array|WP_Error { lat, lng, source, url }
	 */
	public static function resolve( $url ) {
		$raw = trim( (string) $url );
		$url = $raw;

		if ( preg_match( '~https?://[^\s"\'<>]+~i', $url, $m ) ) {
			// متن «اشتراک‌گذاری» نام مکان را هم همراه لینک دارد؛ ارقام فارسی در خود لینک هم
			$url = self::latin_digits( rtrim( $m[0], '.,;:!?)»،؛' ) );
		}

		$p = self::parse_detail( $url );

		// متن بی‌لینک (مثل مختصات کپی‌شده از نشان)
		if ( ! $p && $url === $raw ) {
			$p = self::parse_detail( $raw );
		}

		$local = $p ? array( 'lat' => $p[0], 'lng' => $p[1], 'approx' => $p[2], 'source' => self::source_name( $url ), 'url' => $url ) : null;

		// دقیق، یا لینکی که نمی‌شود برای دقیق‌ترش باز کرد
		if ( $local && ( ! $local['approx'] || ! preg_match( '~^https?://~i', $url ) || ! self::allowed_host( $url ) ) ) {
			return $local;
		}

		if ( ! preg_match( '~^https?://~i', $url ) || ! self::allowed_host( $url ) ) {
			return new WP_Error( 'cmb_geo_host', 'این لینک از سرویس‌های نقشه‌ی شناخته‌شده نیست. لینک گوگل‌مپ، نشان، بلد یا ویز را بچسبانید، یا مختصات را دستی وارد کنید.', array( 'status' => 400 ) );
		}

		$cache = 'cmb_geo_' . md5( $url );
		$hit   = get_transient( $cache );

		if ( is_array( $hit ) && isset( $hit['lat'], $hit['lng'] ) ) {
			return $hit;
		}

		// اول با نشانی خودمان (بیشتر سرویس‌ها به ربات پیش‌نمایش صفحه‌ی ساده با og می‌دهند)،
		// اگر چیزی پیدا نشد یک بار هم مثل مرورگر معمولی
		$agents = array(
			'Mozilla/5.0 (compatible; CheckMotorBooking/' . ( defined( 'CMB_VERSION' ) ? CMB_VERSION : '1' ) . '; +' . home_url( '/' ) . ')',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
		);
		// گوگل به ربات‌ها نسخه‌ی سبک بی‌مختصات می‌دهد؛ برای گوگل اول مثل مرورگر
		if ( preg_match( '~(?:^|\.)(?:goo\.gl|g\.co|google\.com)$~i', (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$agents = array_reverse( $agents );
		}

		$fetched    = false;
		self::$hops = array();
		$best       = $local;

		foreach ( $agents as $agent ) {
			$out = self::follow( $url, $agent, $fetched );

			if ( $out && ! $out['approx'] ) {
				set_transient( $cache, $out, DAY_IN_SECONDS );
				return $out;
			}

			$best = $best ? $best : $out;
		}

		// فقط مرکز دید نقشه پیدا شد: با نشانه‌ی «تقریبی» تا کاربر روی نقشه بررسی کند
		if ( $best ) {
			// تا روی سایت واقعی دیده شود چرا نشانگر دقیق پیدا نشد
			if ( $fetched && class_exists( 'CMB_Debug' ) ) {
				$best['ref'] = CMB_Debug::log( 'warn', 'geo', 'cmb_geo_approx', 'فقط موقعیت تقریبی (مرکز نقشه) پیدا شد: ' . $url, array( 'context' => array( 'hops' => self::$hops ) ) );
			}

			set_transient( $cache, $best, HOUR_IN_SECONDS );

			return $best;
		}

		if ( class_exists( 'CMB_Debug' ) ) {
			CMB_Debug::log( 'warn', 'geo', $fetched ? 'cmb_geo_none' : 'cmb_geo_fetch', 'لینک نقشه خوانده نشد: ' . $url, array( 'context' => array( 'hops' => self::$hops ) ) );
		}

		if ( ! $fetched ) {
			return new WP_Error( 'cmb_geo_fetch', 'باز کردن لینک ممکن نشد. چند لحظه بعد دوباره بچسبانید، یا لینک کامل نقشه (نه کوتاه‌شده) یا مختصات را وارد کنید.', array( 'status' => 502 ) );
		}

		return new WP_Error( 'cmb_geo_none', 'در این لینک موقعیتی پیدا نشد. در برنامه‌ی نقشه روی محل بزنید و لینک «اشتراک‌گذاری» همان نقطه را بچسبانید، یا مختصات را دستی وارد کنید.', array( 'status' => 422 ) );
	}

	/** دنبال کردن پرش‌به‌پرش؛ مختصات یا null. $fetched: آیا دست‌کم یک پاسخ رسید. */
	protected static function follow( $url, $agent, &$fetched ) {
		$current = $url;
		$seen    = array();
		$trail   = $url;
		$approx  = null;

		for ( $hop = 0; $hop < 6 && '' !== $current && ! isset( $seen[ $current ] ); $hop++ ) {
			$seen[ $current ] = true;

			$res = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => 512 * KB_IN_BYTES,
					'user-agent'          => $agent,
					'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language' => 'fa,en;q=0.8' ),
					// رد شدن از صفحه‌ی رضایت کوکی گوگل برای سرورهای اروپا (همان کار برنامه‌ی متن‌باز GeoShare)
					'cookies'             => preg_match( '~(?:^|\.)google\.[a-z.]+$~i', (string) wp_parse_url( $current, PHP_URL_HOST ) )
						? array( 'CONSENT' => 'PENDING+987', 'SOCS' => 'CAESHAgBEhJnd3NfMjAyMzA4MTAtMF9SQzIaAmRlIAEaBgiAo_CmBg' ) : array(),
				)
			);

			if ( is_wp_error( $res ) ) {
				self::$hops[] = array( 'url' => substr( $current, 0, 300 ), 'error' => $res->get_error_message() );
				return $approx;
			}

			$fetched = true;
			$code    = (int) wp_remote_retrieve_response_code( $res );
			$final   = '';

			if ( isset( $res['http_response'] ) && is_object( $res['http_response'] ) && method_exists( $res['http_response'], 'get_response_object' ) ) {
				$obj   = $res['http_response']->get_response_object();
				$final = isset( $obj->url ) && $obj->url !== $current ? (string) $obj->url : '';
			}

			$location = wp_remote_retrieve_header( $res, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
			$body     = (string) wp_remote_retrieve_body( $res );

			self::$hops[] = array(
				'url'      => substr( $current, 0, 300 ),
				'http'     => $code,
				'location' => substr( $location, 0, 300 ),
				'bytes'    => strlen( $body ),
				'title'    => preg_match( '~<title[^>]*>([^<]{0,150})~i', $body, $tm ) ? trim( $tm[1] ) : '',
				'head'     => substr( preg_replace( '~\s+~', ' ', $body ), 0, 400 ),
			);

			$trail .= ' ' . $final . ' ' . $location . ' ' . $current;

			foreach ( array( $final, $location, $body ) as $k => $candidate ) {
				$p = '' !== $candidate ? self::parse_detail( $candidate ) : null;

				// عددی که از دل صفحه درآمده فقط اگر داخل ایران باشد (نه هر x/y تصادفی)
				if ( $p && 2 === $k && ! ( $p[0] >= 24 && $p[0] <= 40.5 && $p[1] >= 43.5 && $p[1] <= 64 ) ) {
					$p = null;
				}

				if ( ! $p ) {
					continue;
				}

				$out = array( 'lat' => $p[0], 'lng' => $p[1], 'approx' => $p[2], 'source' => self::source_name( $trail ), 'url' => $final ? $final : ( $location ? $location : $current ) );

				if ( ! $p[2] ) {
					return $out;
				}

				// مرکز دید: نگه می‌داریم و پرش بعدی (صفحه‌ی مکان) را برای نشانگر دقیق می‌خوانیم
				$approx = $approx ? $approx : $out;
			}

			$next = '';

			if ( $code >= 300 && $code < 400 && '' !== $location ) {
				$next = WP_Http::make_absolute_url( $location, $current );
				// تغییر مسیر از خود سرویس نقشه به هر میزبان https مجاز است (wp_safe جلوی شبکه‌ی داخلی را می‌گیرد)
				if ( ! preg_match( '~^https?://~i', $next ) ) {
					$next = '';
				}
			} elseif ( '' !== $body ) {
				$next = self::next_from_body( $body );
				$next = '' !== $next ? WP_Http::make_absolute_url( $next, $current ) : '';
				// پرش از دل صفحه فقط به سرویس‌های نقشه
				if ( '' !== $next && ( ! preg_match( '~^https?://~i', $next ) || ! self::allowed_host( $next ) ) ) {
					$next = '';
				}
			}

			$current = $next;
		}

		return $approx;
	}
}
