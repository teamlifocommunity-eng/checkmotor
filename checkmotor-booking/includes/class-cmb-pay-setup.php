<?php
/**
 * صفحه‌ی «راه‌اندازی زرین‌پال»: هر چیزی که برگشت خودکار لازم دارد، یک
 * قدم با وضعیت خودش (✅ ⚠️ ❌)، فیلدهایش، راهنمای «از کجای پنل زرین‌پال»
 * و دکمه‌ی آزمایش خودش.
 *
 * وضعیت آزمون‌ها در option «cmb_pay_setup» می‌ماند و با اثرانگشت
 * تنظیماتِ همان بخش؛ اگر مثلاً توکن عوض شود، نتیجه‌ی قبلی «کهنه» است.
 * خود موتور برگشت هم نتیجه‌ی واقعی هر برگشت فوری و استرداد را اینجا
 * ثبت می‌کند، پس وضعیت‌ها بعد از راه‌اندازی هم زنده می‌مانند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Pay_Setup {

	const OPTION = 'cmb_pay_setup';

	const IP_KEY = 'cmb_server_ip';

	/** نتیجه‌هایی که نگه داشته می‌شوند. */
	const KEYS = array( 'merchant', 'api', 'reverse', 'refund' );

	/* ------------------------------------------------------------------
	 * نتیجه‌ی آزمون‌ها
	 * --------------------------------------------------------------- */

	/** اثرانگشت تنظیماتی که نتیجه‌ی هر آزمون به آن بسته است. */
	public static function fingerprint( $key ) {
		$token = CMB_Zarinpal_Refund::token();
		$tok   = '' === $token ? '' : substr( hash( 'sha256', $token ), 0, 12 );

		switch ( $key ) {
			case 'merchant':
				$parts = array( CMB_Payments::merchant(), CMB_Payments::sandbox() ? 1 : 0 );
				break;
			case 'reverse':
				$parts = array( CMB_Payments::merchant() );
				break;
			default:
				$parts = array( CMB_Zarinpal_Refund::terminal(), $tok );
		}

		return substr( md5( implode( '|', $parts ) ), 0, 10 );
	}

	public static function all() {
		$all = get_option( self::OPTION );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * ثبت نتیجه‌ی یک آزمون یا یک برگشت واقعی.
	 *
	 * @param string     $key  merchant | api | reverse | refund
	 * @param bool       $ok
	 * @param string     $msg  متن فارسی برای نمایش.
	 * @param string|int $code کد زرین‌پال یا نوع خطا.
	 */
	public static function record( $key, $ok, $msg, $code = '' ) {
		if ( ! in_array( $key, self::KEYS, true ) ) {
			return;
		}

		$all         = self::all();
		$all[ $key ] = array(
			'ok'   => (bool) $ok,
			'msg'  => cmb_substr( (string) $msg, 0, 300 ),
			'code' => (string) $code,
			'at'   => cmb_now()->getTimestamp(),
			'fp'   => self::fingerprint( $key ),
		);

		update_option( self::OPTION, $all, false );
	}

	/**
	 * آخرین نتیجه با «stale» اگر تنظیمات مربوط از آن موقع عوض شده.
	 *
	 * @return array|null
	 */
	public static function result( $key ) {
		$all = self::all();

		if ( empty( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return null;
		}

		$r          = $all[ $key ];
		$r['stale'] = ( isset( $r['fp'] ) ? $r['fp'] : '' ) !== self::fingerprint( $key );

		return $r;
	}

	protected static function when( $ts ) {
		$ts = (int) $ts;

		if ( ! $ts ) {
			return '';
		}

		$dt = cmb_now()->setTimestamp( $ts );

		return cmb_fa_num( cmb_jalali_date( $dt->format( 'Y-m-d' ), 'numeric' ) . ' ' . $dt->format( 'H:i' ) );
	}

	/* ------------------------------------------------------------------
	 * آزمون‌ها
	 * --------------------------------------------------------------- */

	/**
	 * درخواست پرداخت ۱,۰۰۰ تومانی (پرداخت نمی‌شود): دسترسی سرور به
	 * زرین‌پال، مرچنت کد و دامنه‌ی درگاه.
	 *
	 * @return array [ok, message]
	 */
	public static function test_gateway() {
		$merchant = CMB_Payments::merchant();

		if ( '' === $merchant ) {
			self::record( 'merchant', false, 'مرچنت کد زرین‌پال وارد نشده است.' );
			return array( false, 'مرچنت کد زرین‌پال وارد نشده است.' );
		}

		$sandbox = CMB_Payments::sandbox();
		$res     = CMB_Zarinpal::request( $merchant, $sandbox, 10000, cmb_app_url( 'pay/return' ), 'آزمایش اتصال سیستم رزرو چک موتور' );

		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			$code = is_array( $data ) && isset( $data['zp_code'] ) ? (int) $data['zp_code'] : 0;
			$msg  = 'درگاه نپذیرفت: ' . $res->get_error_message();

			self::record( 'merchant', false, $msg, $code );

			return array( false, $msg );
		}

		$msg = sprintf(
			'اتصال به زرین‌پال%s برقرار است و مرچنت کد پذیرفته شد (شناسه‌ی آزمایشی %s). این پرداخت انجام نمی‌شود.',
			$sandbox ? ' (درگاه آزمایشی)' : '',
			$res['authority']
		);

		self::record( 'merchant', true, $msg, 100 );

		return array( true, $msg );
	}

	/**
	 * پرسش خواندنی از API استرداد: توکن و ترمینال.
	 *
	 * @return array [ok, message]
	 */
	public static function test_api() {
		$res = CMB_Zarinpal_Refund::test_connection();

		if ( is_wp_error( $res ) ) {
			$msg = 'API برگشت زرین‌پال: ' . $res->get_error_message();
			self::record( 'api', false, $msg, CMB_Zarinpal_Refund::kind( $res ) );

			return array( false, $msg );
		}

		$last = $res['last'];
		$msg  = 'توکن و شماره‌ی ترمینال درست است؛ API برگشت زرین‌پال پاسخ داد.'
			. ( $last
				? sprintf( ' آخرین تراکنش: %s ریال، %s.', number_format( (int) $last['amount'] ), isset( $last['created_at'] ) ? $last['created_at'] : '' )
				: ' هنوز تراکنشی روی این ترمینال نیست.' );

		self::record( 'api', true, $msg, 'ok' );

		return array( true, $msg );
	}

	/**
	 * آی‌پی بیرونی سرور سایت، برای ثبت در پنل زرین‌پال (برگشت فوری).
	 * فقط با کلیک «پیدا کردن» از api.ipify.org پرسیده می‌شود.
	 */
	public static function server_ip( $refresh = false ) {
		$ip = get_transient( self::IP_KEY );

		if ( ! $refresh ) {
			return is_string( $ip ) ? $ip : '';
		}

		$ip  = '';
		$res = wp_remote_get( 'https://api.ipify.org?format=json', array( 'timeout' => 8 ) );

		if ( ! is_wp_error( $res ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );

			if ( is_array( $json ) && ! empty( $json['ip'] ) && filter_var( $json['ip'], FILTER_VALIDATE_IP ) ) {
				$ip = (string) $json['ip'];
			}
		}

		// نشد: آی‌پی خود سرور، اگر عمومی باشد
		if ( '' === $ip && ! empty( $_SERVER['SERVER_ADDR'] ) ) {
			$local = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );

			if ( filter_var( $local, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$ip = $local;
			}
		}

		if ( '' !== $ip ) {
			set_transient( self::IP_KEY, $ip, DAY_IN_SECONDS );
		}

		return $ip;
	}

	/* ------------------------------------------------------------------
	 * قدم‌ها
	 * --------------------------------------------------------------- */

	/**
	 * وضعیت یک قدم از روی نتیجه‌ی آزمونش.
	 *
	 * @return array [status, text]
	 */
	protected static function from_result( $key, $todo_text ) {
		$r = self::result( $key );

		if ( ! $r ) {
			return array( 'todo', $todo_text );
		}

		if ( $r['stale'] ) {
			return array( 'warn', 'تنظیمات این بخش بعد از آخرین آزمون عوض شده؛ دوباره آزمایش کنید. (آخرین نتیجه: ' . $r['msg'] . ')' );
		}

		return array( $r['ok'] ? 'ok' : 'bad', $r['msg'] );
	}

	/**
	 * هفت قدم راه‌اندازی.
	 *
	 * @return array[] { key, n, title, status: ok|warn|bad|todo|skip, text, fix, at }
	 */
	public static function steps() {
		$sandbox = CMB_Payments::sandbox();
		$https   = 0 === strpos( home_url( '/' ), 'https://' );
		$steps   = array();

		// ۱. درگاه و مرچنت کد
		if ( ! CMB_Payments::schema_ready() ) {
			$s = array( 'bad', 'ساختار دیتابیس هنوز به‌روز نشده است؛ یک بار صفحه را تازه کنید.' );
		} elseif ( '' === CMB_Payments::merchant() ) {
			$s = array( 'todo', 'مرچنت کد وارد نشده است.' );
		} elseif ( ! $sandbox && ! $https ) {
			$s = array( 'bad', 'نشانی سایت HTTPS نیست؛ درگاه واقعی فقط روی HTTPS کار می‌کند.' );
		} else {
			$s = self::from_result( 'merchant', 'مرچنت کد هست؛ «ذخیره و آزمایش» را بزنید.' );
		}

		$r = self::result( 'merchant' );
		$steps[] = array(
			'key'    => 'gateway',
			'title'  => 'درگاه و مرچنت کد',
			'status' => $s[0],
			'text'   => $s[1] . ( $sandbox ? ' — درگاه آزمایشی روشن است؛ برای پول واقعی خاموشش کنید و دوباره آزمایش کنید.' : '' ),
			'fix'    => self::fix_for( $r ),
			'at'     => $r ? $r['at'] : 0,
		);

		// ۲. پرداخت بیعانه و مبلغ‌ها
		$review = CMB_Pay_Review::services();
		$bad    = 0;
		$warn   = 0;
		$with   = 0;

		foreach ( $review['items'] as $it ) {
			if ( 0 === (int) $it['active'] ) {
				continue;
			}

			$with += $it['deposit'] > 0 ? 1 : 0;
			$bad  += 'bad' === $it['level'] ? 1 : 0;
			$warn += 'warn' === $it['level'] ? 1 : 0;
		}

		if ( $bad ) {
			$s = array( 'bad', cmb_fa_num( $bad ) . ' خدمت مبلغ نامعتبر دارد (زرین‌پال نمی‌پذیرد). در پنل ← «بیعانه و برگشت» ← «بررسی خدمات» اصلاحش کنید.' );
		} elseif ( ! CMB_Payments::enabled() ) {
			$s = array( 'todo', 'پرداخت بیعانه خاموش است؛ رزروها رایگان‌اند. بعد از آماده شدن قدم‌های دیگر روشنش کنید.' );
		} elseif ( $warn ) {
			$s = array( 'warn', cmb_fa_num( $warn ) . ' خدمت هشدار دارد (مثلاً بیعانه برابر قیمت). در «بررسی خدمات» ببینید.' );
		} else {
			$s = array( 'ok', 'پرداخت بیعانه روشن است؛ ' . cmb_fa_num( $with ) . ' خدمت فعال بیعانه دارد و مبلغ‌ها درست است.' );
		}

		$steps[] = array(
			'key'    => 'deposit',
			'title'  => 'پرداخت بیعانه و مبلغ‌ها',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => '',
			'at'     => 0,
		);

		// ۳. آی‌پی سرور برای برگشت فوری
		$r = self::result( 'reverse' );

		if ( ! CMB_Payments::reverse_on() ) {
			$s = array( 'skip', 'خاموش است؛ همه‌ی برگشت‌ها با استرداد انجام می‌شوند (کارمزد دارد و از کیف پول کم می‌شود).' );
		} else {
			$s = self::from_result( 'reverse', 'آی‌پی سرور را در پنل زرین‌پال ثبت کنید؛ درستی‌اش با «آزمون برگشت فوری» (قدم ۶) معلوم می‌شود. تا آن موقع اگر برگشت فوری نشود، خودکار استرداد جایش را می‌گیرد.' );
		}

		$off     = 'skip' === $s[0];
		$steps[] = array(
			'key'    => 'ip',
			'title'  => 'آی‌پی سرور (برگشت فوری)',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => $off ? '' : self::fix_for( $r ),
			'at'     => ( $r && ! $off ) ? $r['at'] : 0,
		);

		// ۴. ترمینال و توکن
		$r = self::result( 'api' );

		if ( '' === CMB_Zarinpal_Refund::terminal() || '' === CMB_Zarinpal_Refund::token() ) {
			$s = array( 'todo', 'شماره‌ی ترمینال یا توکن دسترسی وارد نشده است.' );
		} else {
			$s = self::from_result( 'api', 'ترمینال و توکن وارد شده؛ «ذخیره و آزمایش» را بزنید.' );
		}

		$steps[] = array(
			'key'    => 'api',
			'title'  => 'ترمینال و توکن دسترسی (استرداد)',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => self::fix_for( $r ),
			'at'     => $r ? $r['at'] : 0,
		);

		// ۵. سرویس استرداد و کیف پول
		$r = self::result( 'refund' );

		if ( ! $r && 'ok' !== $steps[3]['status'] ) {
			$s = array( 'todo', 'اول قدم ۴ را آماده کنید؛ بعد با «آزمون استرداد» (قدم ۶) معلوم می‌شود.' );
		} else {
			$s = self::from_result( 'refund', 'فعال بودن سرویس استرداد و موجودی کیف پول فقط با «آزمون استرداد» (قدم ۶) معلوم می‌شود.' );
		}

		$steps[] = array(
			'key'    => 'refund',
			'title'  => 'سرویس استرداد و کیف پول',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => self::fix_for( $r ),
			'at'     => $r ? $r['at'] : 0,
		);

		// ۶. آزمون‌های ۲,۰۰۰ تومانی
		$fast    = CMB_Payments::selftest_result( 'fast' );
		$refund  = CMB_Payments::selftest_result( 'refund' );
		$real    = array();
		$passed  = array();
		$failed  = false;
		$pending = false;

		foreach ( array( 'fast' => $fast, 'refund' => $refund ) as $path => $res ) {
			if ( ! $res ) {
				continue;
			}

			$name = 'fast' === $path ? 'برگشت فوری' : 'استرداد';

			if ( $res['ok'] ) {
				$passed[] = $name . ( $res['sandbox'] ? ' (آزمایشی)' : '' );

				if ( ! $res['sandbox'] ) {
					$real[ $path ] = $name;
				}
			} elseif ( self::waiting( $res ) ) {
				$pending = true;
			} else {
				$failed = true;
			}
		}

		// استرداد همیشه لازم است؛ برگشت فوری فقط وقتی روشن است
		$need = CMB_Payments::reverse_on() ? array( 'fast', 'refund' ) : array( 'refund' );

		if ( $failed ) {
			$s = array( 'bad', 'یکی از آزمون‌ها کامل نشد؛ کارت نتیجه‌اش را پایین همین قدم ببینید.' );
		} elseif ( ! array_diff( $need, array_keys( $real ) ) ) {
			$s = array( 'ok', 'با پول واقعی آزموده شد: ' . implode( ' و ', $real ) . '.' );
		} elseif ( $passed ) {
			$s = array( 'warn', 'آزموده شد: ' . implode( ' و ', $passed ) . '. ' . ( CMB_Payments::sandbox() ? 'برای اطمینان با درگاه واقعی هم آزمون بگیرید.' : 'آزمون دیگر هم مانده.' ) );
		} elseif ( $pending ) {
			$s = array( 'todo', 'آزمون شروع شده و هنوز تمام نشده؛ کارت نتیجه را ببینید.' );
		} else {
			$s = array( 'todo', 'هنوز آزمونی گرفته نشده.' );
		}

		$steps[] = array(
			'key'    => 'tests',
			'title'  => 'آزمون‌های ۲,۰۰۰ تومانی',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => '',
			'at'     => max( $fast ? $fast['at'] : 0, $refund ? $refund['at'] : 0 ),
		);

		// ۷. برگشت خودکار
		$missing = array();

		foreach ( array( 0, 3, 4 ) as $i ) {
			if ( 'ok' !== $steps[ $i ]['status'] ) {
				$missing[] = cmb_fa_num( $i + 1 );
			}
		}

		if ( 'auto' !== CMB_Payments::refund_mode() ) {
			$s = array( 'todo', 'برگشت پول «دستی» است: هر برگشت در صف پنل می‌ماند تا از پنل زرین‌پال انجامش دهید.' . ( $missing ? '' : ' قدم‌های لازم آماده‌اند؛ می‌توانید خودکار کنید.' ) );
		} elseif ( $sandbox ) {
			$s = array( 'warn', 'خودکار است، ولی درگاه آزمایشی روشن است: برگشت‌ها شبیه‌سازی می‌شوند.' );
		} elseif ( $missing ) {
			$s = array( 'warn', 'خودکار است، ولی قدم ' . implode( '، ', $missing ) . ' آماده نیست؛ برگشت‌هایی که شکست بخورند با دلیل در صف پنل می‌مانند.' );
		} else {
			$s = array( 'ok', 'خودکار است: ' . ( CMB_Payments::reverse_on() ? 'برگشت‌های کامل تا ۳۰ دقیقه بعد از پرداخت با برگشت فوری، بقیه ' : '' ) . 'با استرداد ' . CMB_Payments::method_label( CMB_Payments::refund_method() ) . '، ' . cmb_fa_num( CMB_Payments::refund_delay() ) . ' دقیقه بعد از لغو.' );
		}

		$steps[] = array(
			'key'    => 'auto',
			'title'  => 'روشن کردن برگشت خودکار',
			'status' => $s[0],
			'text'   => $s[1],
			'fix'    => '',
			'at'     => 0,
		);

		foreach ( $steps as $i => $st ) {
			$steps[ $i ]['n'] = $i + 1;
		}

		return $steps;
	}

	/** آزمونی که هنوز منتظر درگاه یا برگشت است، شکست حساب نمی‌شود. */
	protected static function waiting( $res ) {
		foreach ( $res['steps'] as $st ) {
			if ( 'bad' === $st[0] ) {
				return false;
			}
		}

		return true;
	}

	/** راه‌حل کوتاه برای خطاهای رایج راه‌اندازی. */
	protected static function fix_for( $r ) {
		if ( ! $r || $r['ok'] || $r['stale'] ) {
			return '';
		}

		$code = (string) $r['code'];
		$map  = array(
			'-10'  => 'مرچنت کد را از پنل زرین‌پال دوباره کپی کنید. اگر در تنظیمات درگاه محدودیت آی‌پی گذاشته‌اید، آی‌پی سرور (قدم ۳) را هم اضافه کنید.',
			'-11'  => 'درگاه در زرین‌پال فعال نیست؛ وضعیت درگاه را در پنل زرین‌پال ببینید یا به پشتیبانی تیکت بدهید.',
			'-18'  => 'دامنه‌ی ثبت‌شده‌ی درگاه در زرین‌پال باید ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' باشد.',
			'-62'  => 'آی‌پی سرور (دکمه‌ی «پیدا کردن آی‌پی سرور») را در پنل زرین‌پال ثبت کنید، بعد دوباره «آزمون برگشت فوری» بگیرید.',
			'auth' => 'توکن تازه بسازید (یا از پشتیبانی بگیرید) و در قدم ۴ بچسبانید.',
		);

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		if ( false !== strpos( $r['msg'], 'کیف پول' ) ) {
			return 'کیف پول زرین‌پال را شارژ کنید و دوباره «آزمون استرداد» بگیرید.';
		}

		if ( false !== strpos( $r['msg'], 'سرویس «استرداد وجه»' ) ) {
			return 'به پشتیبانی زرین‌پال تیکت بدهید: «لطفاً سرویس استرداد وجه و دسترسی API استرداد را برای ترمینال من فعال کنید.»';
		}

		return '';
	}

	/**
	 * جمع‌بندی: چند قدم آماده و کدام‌ها مانده.
	 *
	 * @return array { ready, total, left[], text }
	 */
	public static function summary( $steps = null ) {
		$steps = null === $steps ? self::steps() : $steps;
		$ready = 0;
		$left  = array();

		foreach ( $steps as $st ) {
			if ( in_array( $st['status'], array( 'ok', 'skip' ), true ) ) {
				$ready++;
			} else {
				$left[] = $st;
			}
		}

		if ( ! $left ) {
			$text = 'همه‌چیز آماده است؛ برگشت خودکار با پول واقعی کار می‌کند.';
		} else {
			$names = array();

			foreach ( $left as $st ) {
				$names[] = cmb_fa_num( $st['n'] ) . ') ' . $st['title'];
			}

			$text = 'مانده: ' . implode( '، ', $names ) . '.';
		}

		return array(
			'ready' => $ready,
			'total' => count( $steps ),
			'left'  => wp_list_pluck( $left, 'n' ),
			'text'  => $text,
		);
	}

	/** برای یادآوری در پنل («بیعانه و برگشت»). */
	public static function panel_info() {
		$sum = self::summary();

		return array(
			'ready' => $sum['ready'],
			'total' => $sum['total'],
			'text'  => $sum['text'],
			'url'   => admin_url( 'admin.php?page=cmb-zarinpal' ),
		);
	}

	/* ------------------------------------------------------------------
	 * ذخیره و آزمایش (admin-post)
	 * --------------------------------------------------------------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی مجاز نیست.' );
		}

		check_admin_referer( 'cmb_pay_setup' );

		// phpcs:disable WordPress.Security.NonceVerification -- بالا بررسی شد
		$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? 'save' ) );

		if ( 'selftest' === $do ) {
			$path = 'fast' === sanitize_key( wp_unslash( $_POST['path'] ?? '' ) ) ? 'fast' : 'refund';
			$url  = CMB_Payments::selftest_start( $path );

			if ( is_wp_error( $url ) ) {
				self::back( 'test-' . $path, 'آزمون شروع نشد: ' . $url->get_error_message(), 'error' );
			}

			// نشانی درگاه زرین‌پال؛ wp_safe_redirect دامنه‌ی بیرونی را نمی‌پذیرد
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		if ( 'ip' === $do ) {
			$ip = self::server_ip( true );

			self::back(
				'step-ip',
				'' !== $ip ? 'آی‌پی سرور سایت: ' . $ip . ' — همین را در پنل زرین‌پال ثبت کنید.' : 'آی‌پی سرور پیدا نشد؛ از شرکت هاستینگ «آی‌پی خروجی سرور» را بپرسید.',
				'' !== $ip ? 'success' : 'error'
			);
		}

		$num = function ( $key, $min, $max ) {
			return max( $min, min( $max, (int) cmb_en_num( (string) wp_unslash( $_POST[ $key ] ?? '0' ) ) ) );
		};

		$changes = array();
		$token   = null;

		switch ( $step ) {
			case 'gateway':
				$changes = array(
					'zp_merchant_id' => strtolower( trim( sanitize_text_field( wp_unslash( $_POST['zp_merchant_id'] ?? '' ) ) ) ),
					'zp_sandbox'     => isset( $_POST['zp_sandbox'] ) ? 1 : 0,
				);
				break;

			case 'deposit':
				$changes = array(
					'pay_enabled'               => isset( $_POST['pay_enabled'] ) ? 1 : 0,
					'pay_deposit_default'       => $num( 'pay_deposit_default', 0, 100000000 ),
					'pay_cancel_refund_default' => $num( 'pay_cancel_refund_default', 0, 100000000 ),
				);
				break;

			case 'ip':
				$changes = array( 'zp_reverse' => isset( $_POST['zp_reverse'] ) ? 1 : 0 );
				break;

			case 'api':
				$changes = array( 'zp_terminal_id' => preg_replace( '/\D/', '', cmb_en_num( (string) wp_unslash( $_POST['zp_terminal_id'] ?? '' ) ) ) );
				$token   = CMB_Zarinpal_Refund::token_from_post();
				break;

			case 'auto':
				$changes = array(
					'pay_refund_mode'  => 'auto' === sanitize_key( wp_unslash( $_POST['pay_refund_mode'] ?? '' ) ) ? 'auto' : 'manual',
					'pay_refund_delay' => $num( 'pay_refund_delay', 0, 1440 ),
					'zp_refund_method' => 'CARD' === sanitize_text_field( wp_unslash( $_POST['zp_refund_method'] ?? '' ) ) ? 'CARD' : 'PAYA',
				);
				break;

			default:
				self::back( '', 'قدم نامعتبر.', 'error' );
		}
		// phpcs:enable

		$check = array_merge( CMB_Settings::all(), $changes );

		if ( null !== $token ) {
			$check['__token'] = $token;
		}

		$ok = CMB_Admin::check_pay( $check );

		if ( is_wp_error( $ok ) ) {
			self::back( 'step-' . $step, $ok->get_error_message(), 'error' );
		}

		CMB_Zarinpal_Refund::save_token( $token );
		CMB_Settings::update( $changes );

		if ( 'test' === $do && 'gateway' === $step ) {
			$r = self::test_gateway();
			self::back( 'step-gateway', $r[1], $r[0] ? 'success' : 'error' );
		}

		if ( 'test' === $do && 'api' === $step ) {
			$r = self::test_api();
			self::back( 'step-api', $r[1], $r[0] ? 'success' : 'error' );
		}

		self::back( 'step-' . $step, 'ذخیره شد.' );
	}

	protected static function back( $anchor, $message, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'cmb_message' => rawurlencode( $message ),
					'cmb_type'    => $type,
				),
				admin_url( 'admin.php?page=cmb-zarinpal' )
			) . ( '' !== $anchor ? '#' . $anchor : '' )
		);
		exit;
	}

	/* ------------------------------------------------------------------
	 * صفحه
	 * --------------------------------------------------------------- */

	const LABELS = array(
		'ok'   => '✅ آماده',
		'warn' => '⚠️ بررسی کنید',
		'bad'  => '❌ نیاز به اصلاح',
		'todo' => '⏳ انجام نشده',
		'skip' => '— خاموش',
	);

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی مجاز نیست.' );
		}

		$steps = self::steps();
		$sum   = self::summary( $steps );
		$pct   = $sum['total'] ? (int) round( 100 * $sum['ready'] / $sum['total'] ) : 0;

		// phpcs:disable WordPress.Security.NonceVerification
		$message = isset( $_GET['cmb_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['cmb_message'] ) ) ) : '';
		$type    = isset( $_GET['cmb_type'] ) && 'error' === $_GET['cmb_type'] ? 'error' : 'success';
		// phpcs:enable
		?>
		<div class="wrap cmb-admin cmb-su-wrap" dir="rtl">
			<?php self::styles(); ?>
			<h1>راه‌اندازی زرین‌پال</h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="cmb-su-head">
				<div class="cmb-su-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
				<p class="cmb-su-count"><b><?php echo esc_html( cmb_fa_num( $sum['ready'] ) . ' از ' . cmb_fa_num( $sum['total'] ) . ' قدم آماده' ); ?></b> — <?php echo esc_html( $sum['text'] ); ?></p>
				<p class="description">
					هر قدم جدا ذخیره و آزمایش می‌شود و وضعیتش همین‌جا می‌ماند. برای امتحان بی‌پول، در قدم ۱ «درگاه آزمایشی» را روشن کنید؛
					برای پول واقعی خاموشش کنید و آزمون‌ها را دوباره بگیرید. همه‌ی این تنظیم‌ها در «تنظیمات ← پرداخت بیعانه» هم هستند.
				</p>
				<p class="cmb-su-chips">
					<span><?php echo CMB_Payments::sandbox() ? 'درگاه آزمایشی' : 'درگاه واقعی'; ?></span>
					<span><?php echo CMB_Payments::enabled() ? 'پرداخت بیعانه روشن' : 'پرداخت بیعانه خاموش'; ?></span>
					<span><?php echo 'auto' === CMB_Payments::refund_mode() ? 'برگشت خودکار' : 'برگشت دستی'; ?></span>
					<span><?php echo CMB_Payments::reverse_on() ? 'برگشت فوری روشن' : 'برگشت فوری خاموش'; ?></span>
				</p>
			</div>

			<ol class="cmb-su-list">
				<?php foreach ( $steps as $st ) : ?>
					<li class="cmb-su is-<?php echo esc_attr( $st['status'] ); ?>" id="step-<?php echo esc_attr( $st['key'] ); ?>">
						<div class="cmb-su__head">
							<span class="cmb-su__n"><?php echo esc_html( cmb_fa_num( $st['n'] ) ); ?></span>
							<h2><?php echo esc_html( $st['title'] ); ?></h2>
							<span class="cmb-su__badge"><?php echo esc_html( self::LABELS[ $st['status'] ] ); ?></span>
						</div>
						<p class="cmb-su__text"><?php echo esc_html( $st['text'] ); ?></p>
						<?php if ( $st['fix'] ) : ?>
							<p class="cmb-su__fix"><b>راه‌حل:</b> <?php echo esc_html( $st['fix'] ); ?></p>
						<?php endif; ?>
						<?php if ( $st['at'] ) : ?>
							<p class="cmb-su__at">آخرین نتیجه: <?php echo esc_html( self::when( $st['at'] ) ); ?></p>
						<?php endif; ?>
						<?php call_user_func( array( __CLASS__, 'step_' . $st['key'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<p class="description" style="max-width:760px">
				این صفحه از روی مستندات درگاه زرین‌پال ساخته شده: پرداخت (request / verify)، برگشت فوری (reverse، تا ۳۰ دقیقه، بی‌کارمزد)،
				استعلام (inquiry) و استرداد (AddRefund در API زرین‌پال). اگر منوها در پنل زرین‌پال جای دیگری بود، از پشتیبانی همان مورد را بخواهید.
			</p>
		</div>
		<?php
	}

	protected static function form_open( $step ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmb-su__form">
			<?php wp_nonce_field( 'cmb_pay_setup' ); ?>
			<input type="hidden" name="action" value="cmb_pay_setup" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
		<?php
	}

	protected static function guide( array $lines ) {
		?>
		<details class="cmb-su__guide">
			<summary>از کجای پنل زرین‌پال؟</summary>
			<ol>
				<?php foreach ( $lines as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}

	protected static function step_gateway() {
		$s      = CMB_Settings::all();
		$source = CMB_Payments::merchant_source();

		self::guide(
			array(
				'در پنل زرین‌پال به «درگاه‌ها» بروید و درگاه همین سایت را باز کنید.',
				'«مرچنت کد» (۳۶ نویسه به شکل xxxxxxxx-xxxx-…) را کپی کنید و اینجا بچسبانید.',
				'دامنه‌ی ثبت‌شده‌ی درگاه باید ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' باشد؛ وگرنه زرین‌پال خطای ‎-18 می‌دهد.',
				'در درگاه آزمایشی (sandbox) هر مرچنت کد ۳۶ نویسه‌ای پذیرفته می‌شود و پولی جابه‌جا نمی‌شود.',
			)
		);
		self::form_open( 'gateway' );
		?>
			<p>
				<label>مرچنت کد<br>
				<input type="text" name="zp_merchant_id" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['zp_merchant_id'] ); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></label>
				<?php if ( 'woocommerce' === $source ) : ?>
					<br><span class="description">خالی است، پس مرچنت کد افزونه‌ی زرین‌پال ووکامرس استفاده می‌شود.</span>
				<?php endif; ?>
			</p>
			<p><label><input type="checkbox" name="zp_sandbox" value="1" <?php checked( (int) $s['zp_sandbox'], 1 ); ?> /> درگاه آزمایشی (پول واقعی جابه‌جا نمی‌شود)</label></p>
			<p class="cmb-su__btns">
				<button type="submit" name="do" value="test" class="button button-primary">ذخیره و آزمایش اتصال</button>
				<button type="submit" name="do" value="save" class="button">فقط ذخیره</button>
			</p>
			<p class="description">آزمایش یک درخواست پرداخت ۱,۰۰۰ تومانی می‌فرستد که پرداخت نمی‌شود؛ فقط دسترسی سرور به زرین‌پال، مرچنت کد و دامنه را می‌سنجد.</p>
		</form>
		<?php
	}

	protected static function step_deposit() {
		$s = CMB_Settings::all();
		self::form_open( 'deposit' );
		?>
			<p><label><input type="checkbox" name="pay_enabled" value="1" <?php checked( (int) $s['pay_enabled'], 1 ); ?> /> پرداخت بیعانه روشن (رزرو خدمت‌های بیعانه‌دار فقط بعد از پرداخت ثبت می‌شود)</label></p>
			<p>
				<label>بیعانه‌ی پیش‌فرض <input type="number" name="pay_deposit_default" min="0" step="1000" class="small-text" style="width:120px" value="<?php echo esc_attr( $s['pay_deposit_default'] ); ?>" /> تومان</label>
				&nbsp;
				<label>برگشتی در لغوِ به‌موقع <input type="number" name="pay_cancel_refund_default" min="0" step="1000" class="small-text" style="width:120px" value="<?php echo esc_attr( $s['pay_cancel_refund_default'] ); ?>" /> تومان</label>
			</p>
			<p class="cmb-su__btns"><button type="submit" name="do" value="save" class="button button-primary">ذخیره</button></p>
			<p class="description">
				بیعانه ۰ یا دست‌کم ۱,۰۰۰ تومان، و برگشتی ۰ یا دست‌کم ۲,۰۰۰ تومان (حداقل‌های زرین‌پال). مبلغ هر خدمت جدا و شبیه‌ساز «چه کسی چقدر، کِی»:
				<a href="<?php echo esc_url( cmb_app_url( 'panel/refunds' ) ); ?>" target="_blank" rel="noopener">پنل ← بیعانه و برگشت ← بررسی خدمات</a>.
			</p>
		</form>
		<?php
	}

	protected static function step_ip() {
		$ip = self::server_ip();

		self::guide(
			array(
				'«برگشت فوری» (reverse) کل مبلغ یک پرداخت را تا ۳۰ دقیقه بعد برمی‌گرداند؛ بی‌کارمزد، بدون کیف پول و بدون سرویس استرداد.',
				'شرطش ثبت آی‌پی سرور سایت در پنل زرین‌پال است: تنظیمات همان درگاه ← بخش آی‌پی (محدودیت آی‌پی ترمینال).',
				'اگر این بخش را پیدا نکردید، به پشتیبانی تیکت بدهید: «لطفاً محدودیت آی‌پی ترمینال را با آی‌پی … فعال کنید» (خطای ‎-62 یعنی همین).',
				'در هاست اشتراکی آی‌پی ممکن است عوض شود؛ آی‌پی خروجی ثابت را از شرکت هاستینگ بپرسید.',
			)
		);
		?>
		<p class="cmb-su__ip">آی‌پی سرور سایت: <code dir="ltr"><?php echo '' !== $ip ? esc_html( $ip ) : '—'; ?></code></p>
		<?php self::form_open( 'ip' ); ?>
			<p><label><input type="checkbox" name="zp_reverse" value="1" <?php checked( CMB_Payments::reverse_on() ); ?> /> برگشت فوری روشن (اگر نشد، همان لحظه استرداد جایش را می‌گیرد)</label></p>
			<p class="cmb-su__btns">
				<button type="submit" name="do" value="save" class="button button-primary">ذخیره</button>
				<button type="submit" name="do" value="ip" class="button">پیدا کردن آی‌پی سرور</button>
			</p>
			<p class="description">«پیدا کردن» یک بار از سرویس api.ipify.org می‌پرسد سرور سایت با چه آی‌پی‌ای به اینترنت وصل است.</p>
		</form>
		<?php
	}

	protected static function step_api() {
		$src = CMB_Zarinpal_Refund::token_source();

		self::guide(
			array(
				'شماره‌ی ترمینال: در پنل زرین‌پال ← «درگاه‌ها»، شناسه‌ی عددی همین درگاه.',
				'توکن دسترسی (Access Token): در پنل زرین‌پال، بخش توکن‌ها / دسترسی API، یک توکن بسازید و کپی کنید.',
				'اگر چنین بخشی ندیدید، از پشتیبانی زرین‌پال «توکن دسترسی برای API استرداد وجه» بخواهید (مستندات API زرین‌پال دریافت دسترسی را از طریق پشتیبانی هم گفته است).',
				'توکن جدا از بقیه‌ی تنظیمات نگه داشته می‌شود و در هیچ صفحه‌ای نمایش داده نمی‌شود.',
			)
		);
		self::form_open( 'api' );
		?>
			<p>
				<label>شماره‌ی ترمینال<br>
				<input type="text" name="zp_terminal_id" class="regular-text" dir="ltr" inputmode="numeric" value="<?php echo esc_attr( CMB_Settings::get( 'zp_terminal_id', '' ) ); ?>" placeholder="349555" /></label>
			</p>
			<?php if ( 'constant' === $src ) : ?>
				<p>✅ توکن از ثابت <code>CMB_ZP_ACCESS_TOKEN</code> در wp-config خوانده می‌شود.</p>
			<?php else : ?>
				<p>
					<label>توکن دسترسی<br>
					<input type="password" name="zp_access_token" class="large-text" dir="ltr" autocomplete="new-password" value="" placeholder="<?php echo 'option' === $src ? 'ذخیره شده — برای تغییر، توکن تازه را بچسبانید' : 'توکن را اینجا بچسبانید'; ?>" /></label>
					<?php if ( 'option' === $src ) : ?>
						<br><label><input type="checkbox" name="zp_token_clear" value="1" /> پاک کردن توکن ذخیره‌شده</label>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<p class="cmb-su__btns">
				<button type="submit" name="do" value="test" class="button button-primary">ذخیره و آزمایش</button>
				<button type="submit" name="do" value="save" class="button">فقط ذخیره</button>
			</p>
			<p class="description">آزمایش فقط می‌خواند (آخرین تراکنش این ترمینال)؛ پولی جابه‌جا نمی‌شود.</p>
		</form>
		<?php
	}

	protected static function step_refund() {
		self::guide(
			array(
				'به پشتیبانی زرین‌پال تیکت بدهید: «لطفاً سرویس استرداد وجه و دسترسی API استرداد را برای ترمینال من فعال کنید.»',
				'کیف پول زرین‌پال را کمی شارژ کنید؛ هر استرداد (نه برگشت فوری) از آن کم می‌شود.',
				'هر تراکنش فقط یک بار و تا ۲ ماه بعد از پرداخت قابل استرداد است؛ حداقل ۲,۰۰۰ تومان.',
				'بعد در قدم ۶ «آزمون استرداد» بگیرید.',
			)
		);
	}

	protected static function step_tests() {
		$sandbox = CMB_Payments::sandbox();
		?>
		<p class="description">
			<?php if ( $sandbox ) : ?>
				<b>درگاه آزمایشی روشن است:</b> هر دو آزمون بدون پول واقعی اجرا می‌شوند و خودِ برگشت شبیه‌سازی می‌شود.
			<?php else : ?>
				به درگاه می‌روید و ۲,۰۰۰ تومان می‌پردازید؛ بعد از بازگشت، همان کدی که پول مشتری‌ها را برمی‌گرداند کل مبلغ را برمی‌گرداند و نتیجه‌ی هر مرحله همین‌جا می‌آید.
				نوبتی ساخته نمی‌شود. آزمون‌ها حتی با برگشت «دستی» هم کار می‌کنند.
			<?php endif; ?>
		</p>
		<?php
		$tests = array(
			'fast'   => array( 'آزمون برگشت فوری ۲,۰۰۰ تومانی', 'قدم ۳: بی‌کارمزد، همان لحظه. اگر نشد، استرداد جایش امتحان می‌شود و دلیل نشدنش را می‌بینید.' ),
			'refund' => array( 'آزمون استرداد ۲,۰۰۰ تومانی', 'قدم ۴ و ۵: پیدا کردن تراکنش و استرداد با ' . CMB_Payments::method_label( CMB_Payments::refund_method() ) . '؛ همان مسیری که برگشتِ لغو مشتری‌ها می‌رود.' ),
		);

		foreach ( $tests as $path => $t ) :
			?>
			<div class="cmb-su__test" id="test-<?php echo esc_attr( $path ); ?>">
				<?php self::render_result( CMB_Payments::selftest_result( $path ), $t[0] ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmb_pay_setup' ); ?>
					<input type="hidden" name="action" value="cmb_pay_setup" />
					<input type="hidden" name="step" value="tests" />
					<input type="hidden" name="do" value="selftest" />
					<input type="hidden" name="path" value="<?php echo esc_attr( $path ); ?>" />
					<p class="cmb-su__btns"><button type="submit" class="button button-primary"><?php echo esc_html( 'شروع ' . $t[0] ); ?></button></p>
					<p class="description"><?php echo esc_html( $t[1] ); ?></p>
				</form>
			</div>
			<?php
		endforeach;
	}

	protected static function step_auto() {
		$mode = CMB_Payments::refund_mode();
		self::form_open( 'auto' );
		?>
			<p>
				<label style="display:block;margin-bottom:6px"><input type="radio" name="pay_refund_mode" value="manual" <?php checked( $mode, 'manual' ); ?> /> دستی — از پنل زرین‌پال برمی‌گردانید و در پنل رزرو «ثبت انجام‌شده» می‌زنید</label>
				<label style="display:block"><input type="radio" name="pay_refund_mode" value="auto" <?php checked( $mode, 'auto' ); ?> /> خودکار — سیستم خودش برمی‌گرداند</label>
			</p>
			<p>
				<label>تأخیر بعد از لغو <input type="number" name="pay_refund_delay" min="0" max="1440" class="small-text" value="<?php echo esc_attr( CMB_Payments::refund_delay() ); ?>" /> دقیقه</label>
				&nbsp;
				<label>روش استرداد
					<select name="zp_refund_method">
						<option value="PAYA" <?php selected( CMB_Payments::refund_method(), 'PAYA' ); ?>>پایا — چرخه‌ی بعدی</option>
						<option value="CARD" <?php selected( CMB_Payments::refund_method(), 'CARD' ); ?>>کارت — فوری</option>
					</select>
				</label>
			</p>
			<p class="cmb-su__btns"><button type="submit" name="do" value="save" class="button button-primary">ذخیره</button></p>
			<p class="description">
				تأخیر فقط برای لغو مشتری یا مجموعه است تا لغو اشتباهی با «بازگردانی» نوبت قابل جبران باشد. برگشت‌های سیستمی (پرداخت تکراری، پرداخت دیر)
				همان لحظه انجام می‌شوند. هر برگشتی که شکست بخورد با دلیلش در صف پنل می‌ماند.
			</p>
		</form>
		<?php
	}

	/**
	 * کارت نتیجه‌ی یک آزمون.
	 */
	public static function render_result( $r, $title ) {
		if ( ! $r ) {
			return;
		}

		$icons = array(
			'ok'   => '✅',
			'bad'  => '❌',
			'wait' => '⏳',
		);
		?>
		<div class="cmb-su__result <?php echo $r['ok'] ? 'is-ok' : 'is-warn'; ?>">
			<p><b><?php echo esc_html( 'آخرین ' . $title ); ?></b> — <?php echo esc_html( cmb_fa_num( $r['when'] ) ); ?><?php echo $r['sandbox'] ? ' (درگاه آزمایشی)' : ''; ?></p>
			<ol>
				<?php foreach ( $r['steps'] as $st ) : ?>
					<li><?php echo esc_html( $icons[ $st[0] ] . ' ' . $st[1] ); ?>: <?php echo esc_html( $st[2] ); ?></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( $r['summary'] ) : ?>
				<p class="cmb-su__sum"><b><?php echo esc_html( $r['summary'] ); ?></b></p>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function styles() {
		?>
		<style>
			.cmb-su-wrap{max-width:860px}
			.cmb-su-head{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0 16px}
			.cmb-su-bar{height:10px;background:#f0f0f1;border-radius:6px;overflow:hidden}
			.cmb-su-bar span{display:block;height:100%;background:#1b7f4b;border-radius:6px}
			.cmb-su-count{font-size:14px;margin:10px 0 4px}
			.cmb-su-chips span{display:inline-block;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:12px;padding:1px 10px;margin:2px 0 2px 4px;font-size:12px}
			.cmb-su-list{list-style:none;margin:0;padding:0}
			.cmb-su{background:#fff;border:1px solid #dcdcde;border-inline-start:5px solid #a7aaad;border-radius:8px;padding:12px 16px;margin:0 0 12px}
			.cmb-su.is-ok{border-inline-start-color:#1b7f4b}
			.cmb-su.is-warn{border-inline-start-color:#dba617}
			.cmb-su.is-bad{border-inline-start-color:#d63638}
			.cmb-su__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
			.cmb-su__head h2{margin:0;font-size:16px;flex:1 1 auto}
			.cmb-su__n{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#f0f0f1;font-weight:700}
			.cmb-su.is-ok .cmb-su__n{background:#1b7f4b;color:#fff}
			.cmb-su__badge{font-size:12px;white-space:nowrap}
			.cmb-su__text{margin:8px 0 4px;line-height:1.9}
			.cmb-su__fix{margin:4px 0;padding:6px 10px;background:#fcf0f1;border-radius:6px;line-height:1.9}
			.cmb-su__at{margin:2px 0;color:#646970;font-size:12px}
			.cmb-su__guide{margin:8px 0;background:#f6f7f7;border-radius:6px;padding:6px 10px}
			.cmb-su__guide summary{cursor:pointer;font-weight:600}
			.cmb-su__guide ol{margin:6px 20px 2px 0;line-height:1.9}
			.cmb-su__form{margin-top:6px}
			.cmb-su__btns .button{margin:0 0 4px 6px}
			.cmb-su__test{border-top:1px dashed #dcdcde;padding-top:8px;margin-top:8px}
			.cmb-su__result{border:1px solid #dcdcde;border-inline-start:4px solid #dba617;border-radius:6px;padding:6px 12px;margin:6px 0;background:#fcfcfc}
			.cmb-su__result.is-ok{border-inline-start-color:#1b7f4b}
			.cmb-su__result ol{margin:0 20px 0 0;line-height:1.9}
			.cmb-su__sum{color:#1d2327}
			.cmb-su__result.is-ok .cmb-su__sum{color:#1b7f4b}
			@media (max-width:600px){
				.cmb-su{padding:10px 12px}
				.cmb-su-wrap .regular-text,.cmb-su-wrap .large-text{width:100%}
			}
		</style>
		<?php
	}
}
