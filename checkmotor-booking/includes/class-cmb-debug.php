<?php
/**
 * گزارش خطا (بخش «آزمون و عیب‌یابی»).
 *
 * سیستم در حال توسعه است و خطایی که مشتری می‌بیند تا امروز هیچ ردی
 * نمی‌گذاشت: پیامک ناموفق، درگاهی که جواب نداد، نوبتی که رد شد یا خطای
 * جاوااسکریپت روی گوشی مشتری. این کلاس همه را در یک جدول کوچک نگه می‌دارد
 * تا مدیر سایت در پنل ببیند دقیقاً چه چیزی، کجا و برای چه کسی خراب شده.
 *
 *   · خطاهای REST افزونه (cmb/v1): کد، پیام، مسیر و پارامترهای بی‌خطر. هر
 *     خطا یک «کد پیگیری» کوتاه می‌گیرد که در پاسخ هم برمی‌گردد؛ مشتری
 *     همان را می‌گوید و مدیر در گزارش پیدایش می‌کند.
 *   · خطای جاوااسکریپت اپ و پنل (از مسیر عمومی /log، با سقف تعداد).
 *   · خطای مهلک PHP در فایل‌های همین افزونه.
 *   · رویدادهای cmb_log() (مثل «پیامک ناموفق» و «درخواست زرین‌پال ناموفق»).
 *
 * هیچ کد ورود، رمز، توکن یا شماره‌ی کامل ذخیره نمی‌شود؛ شماره‌ها
 * نیمه‌پوشیده‌اند. گزارش ۱۴ روز و حداکثر ۳۰۰۰ ردیف نگه داشته می‌شود.
 *
 * @package CheckMotor_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Debug {

	const SCHEMA   = '1.10.0';
	const KEEP     = 14;    // روز
	const MAX_ROWS = 3000;

	/** کلیدهایی که مقدارشان هرگز ذخیره نمی‌شود (کد ورود، رمز، توکن، متن پیامک…). */
	const SECRET = '/^(code|otp|pin|tok|key|api_key|args|text|message|authority|card|card_pan|card_hash|merchant|merchant_id|terms_hash)$|pass|token|nonce|secret/i';

	/** @var bool|null */
	protected static $ready = null;

	/** وقتی آزمون خودکار در جریان است، خطاهای عمدی‌اش ثبت نمی‌شوند. */
	public static $mute = false;

	/** @var bool جلوی ثبت تودرتو (خطای خود ثبت) را می‌گیرد. */
	protected static $writing = false;

	/** @var callable|null گیرنده‌ی خطای قبلی (مثلاً Query Monitor)؛ کنار گذاشته نمی‌شود. */
	protected static $prev = null;

	public static function init() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'capture_rest' ), 999, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );

		if ( self::verbose() ) {
			self::$prev = set_error_handler( array( __CLASS__, 'capture_warning' ), E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED ); // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------------
	 * وضعیت
	 * --------------------------------------------------------------- */

	/** جدول گزارش ساخته شده است؟ (مهاجرت 1.10.0) */
	public static function ready() {
		if ( null === self::$ready ) {
			self::$ready = version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
		}

		return self::$ready;
	}

	/** ثبت خطاها روشن است؟ (پیش‌فرض روشن) */
	public static function on() {
		return (bool) CMB_Settings::get( 'debug_log', 1 );
	}

	/**
	 * حالت عیب‌یابی: کد پیگیری هر خطا به مشتری هم نشان داده می‌شود،
	 * رویدادهای عادی (نه فقط خطا) هم ثبت می‌شوند، هشدارهای PHP همین
	 * افزونه ثبت می‌شوند و مدیر در اپ ابزار عیب‌یابی دارد.
	 */
	public static function verbose() {
		return (bool) CMB_Settings::get( 'debug_mode', 0 );
	}

	/* ------------------------------------------------------------------
	 * ثبت
	 * --------------------------------------------------------------- */

	/**
	 * یک ردیف گزارش.
	 *
	 * @param string $level   error | warn | info
	 * @param string $source  api | app | panel | php | server | selftest
	 * @param array  $extra   route, status, branch_id, context (آرایه)
	 *
	 * @return string کد پیگیری، یا رشته‌ی خالی اگر ثبت نشد.
	 */
	public static function log( $level, $source, $code, $message, array $extra = array() ) {
		global $wpdb;

		if ( self::$writing || self::$mute || ! self::ready() || ! self::on() ) {
			return '';
		}

		self::$writing = true;

		$user  = get_current_user_id();
		$phone = $user && function_exists( 'cmb_get_user_phone' ) ? cmb_get_user_phone( $user ) : '';
		$ref   = self::new_ref();
		$ctx   = isset( $extra['context'] ) ? self::clean( (array) $extra['context'] ) : array();

		if ( ! empty( $wpdb->last_error ) && ! isset( $ctx['db'] ) && 'info' !== $level ) {
			$ctx['db'] = cmb_substr( (string) $wpdb->last_error, 0, 300 );
		}

		$row = array(
			'ref'        => $ref,
			'level'      => in_array( $level, array( 'error', 'warn', 'info' ), true ) ? $level : 'error',
			'source'     => substr( sanitize_key( $source ), 0, 20 ),
			'code'       => substr( (string) $code, 0, 60 ),
			'message'    => cmb_substr( wp_strip_all_tags( (string) $message ), 0, 500 ),
			'route'      => isset( $extra['route'] ) ? substr( (string) $extra['route'], 0, 190 ) : '',
			'status'     => isset( $extra['status'] ) ? (int) $extra['status'] : 0,
			'user_id'    => (int) $user,
			'phone'      => self::mask_phone( $phone ),
			'branch_id'  => isset( $extra['branch_id'] ) ? (int) $extra['branch_id'] : 0,
			'ip'         => self::mask_ip( cmb_get_ip() ),
			'ua'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'context'    => $ctx ? substr( (string) wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ), 0, 8000 ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		$saved_error = $wpdb->last_error;
		$ok          = $wpdb->insert( cmb_table( 'log' ), $row );
		$wpdb->last_error = $saved_error;

		self::$writing = false;

		if ( $ok && 0 === wp_rand( 0, 49 ) ) {
			self::prune();
		}

		return $ok ? $ref : '';
	}

	/**
	 * رویدادی که از cmb_log() آمده. فقط شکست‌ها همیشه ثبت می‌شوند؛ بقیه
	 * (پیامک رفت، نمایندگی ساخته شد…) فقط در حالت عیب‌یابی.
	 */
	public static function event( $message, $context = array() ) {
		$failed = (bool) preg_match( '/fail|error|unexpected|HTTP status|invalid|denied|ناموفق|خطا/i', (string) $message );

		if ( ! $failed && ! self::verbose() ) {
			return;
		}

		$code = 'event';

		if ( preg_match( '/^SMS/i', (string) $message ) || false !== stripos( (string) $message, ' SMS' ) ) {
			$code = 'sms';
		} elseif ( false !== stripos( (string) $message, 'zarinpal' ) ) {
			$code = 'gateway';
		}

		self::log( $failed ? 'error' : 'info', 'server', $code, $message, array( 'context' => is_array( $context ) ? $context : array( 'value' => $context ) ) );
	}

	/** کد پیگیری شش‌نویسه‌ای بدون حروف مبهم (۰/O، ۱/I). */
	protected static function new_ref() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$ref   = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$ref .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}

		return $ref;
	}

	public static function mask_phone( $phone ) {
		$phone = (string) $phone;

		if ( strlen( $phone ) < 8 ) {
			return $phone;
		}

		return substr( $phone, 0, 4 ) . '***' . substr( $phone, -4 );
	}

	protected static function mask_ip( $ip ) {
		if ( false !== strpos( $ip, '.' ) ) {
			return preg_replace( '/\.\d+$/', '.x', $ip );
		}

		return preg_replace( '/:[0-9a-f]*:[0-9a-f]*$/i', ':x:x', $ip );
	}

	/**
	 * پاک‌سازی بافت: کلیدهای محرمانه حذف، شماره‌ها نیمه‌پوشیده، مقدارهای
	 * بلند کوتاه.
	 */
	public static function clean( array $ctx, $depth = 0 ) {
		$out = array();

		foreach ( $ctx as $k => $v ) {
			if ( count( $out ) >= 40 ) {
				break;
			}

			if ( is_string( $k ) && preg_match( self::SECRET, $k ) ) {
				$out[ $k ] = '[پنهان]';
				continue;
			}

			if ( is_array( $v ) || is_object( $v ) ) {
				$out[ $k ] = $depth < 2 ? self::clean( (array) $v, $depth + 1 ) : '[…]';
				continue;
			}

			if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
				$out[ $k ] = $v;
				continue;
			}

			$v = (string) $v;

			if ( preg_match( '/^(\+98|0)?9\d{9}$/', cmb_en_num( $v ) ) ) {
				$v = self::mask_phone( cmb_en_num( $v ) );
			}

			$out[ $k ] = cmb_substr( $v, 0, 300 );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * گرفتن خطاها
	 * --------------------------------------------------------------- */

	/**
	 * هر پاسخ خطای cmb/v1: ثبت، و برگرداندن کد پیگیری در پاسخ.
	 *
	 * @param WP_REST_Response $response
	 */
	public static function capture_rest( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		$route  = (string) $request->get_route();
		$status = (int) $response->get_status();

		if ( $status < 400 || 0 !== strpos( $route, '/cmb/v1/' ) || '/cmb/v1/log' === $route ) {
			return $response;
		}

		$data = $response->get_data();
		$code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'http_' . $status;

		// ورودِ نشده به پنل و nonce کهنه (که اپ خودش تازه می‌کند) سروصدای عادی‌اند
		if ( in_array( $code, array( 'cmb_panel_auth' ), true ) ) {
			return $response;
		}

		$level = $status >= 500 ? 'error' : ( in_array( $status, array( 401, 403 ), true ) ? 'info' : 'warn' );

		// همان خطا از همان کاربر و مسیر در یک دقیقه یک بار
		$dupe = 'cmb_logd_' . md5( $code . '|' . $route . '|' . cmb_get_ip() . '|' . get_current_user_id() );

		if ( get_transient( $dupe ) ) {
			return $response;
		}

		set_transient( $dupe, 1, MINUTE_IN_SECONDS );

		$params = array_merge( (array) $request->get_query_params(), (array) $request->get_json_params(), (array) $request->get_body_params() );
		$branch = isset( $params['branch_id'] ) ? (int) $params['branch_id'] : 0;

		if ( ! $branch && ! empty( $params['service_id'] ) && class_exists( 'CMB_Services' ) ) {
			$svc    = CMB_Services::get_service( (int) $params['service_id'] );
			$branch = $svc ? (int) $svc->branch_id : 0;
		}

		$ref = self::log(
			$level,
			0 === strpos( $route, '/cmb/v1/panel' ) ? 'panel' : 'api',
			$code,
			is_array( $data ) && isset( $data['message'] ) ? $data['message'] : '',
			array(
				'route'     => $request->get_method() . ' ' . $route,
				'status'    => $status,
				'branch_id' => $branch,
				'context'   => array( 'params' => $params ),
			)
		);

		if ( '' !== $ref && is_array( $data ) ) {
			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				$data['data'] = array( 'status' => $status );
			}

			$data['data']['ref'] = $ref;
			$response->set_data( $data );
			$response->header( 'X-CMB-Ref', $ref );
		}

		return $response;
	}

	/** خطای مهلک PHP در فایل‌های همین افزونه. */
	public static function capture_fatal() {
		$e = error_get_last();

		if ( ! $e || ! in_array( (int) $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}

		if ( ! defined( 'CMB_DIR' ) || 0 !== strpos( wp_normalize_path( (string) $e['file'] ), wp_normalize_path( CMB_DIR ) ) ) {
			return;
		}

		self::log(
			'error',
			'php',
			'php_fatal',
			(string) $e['message'],
			array(
				'route'   => isset( $_SERVER['REQUEST_URI'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 190 ) : '',
				'context' => array( 'file' => str_replace( wp_normalize_path( CMB_DIR ), '', wp_normalize_path( (string) $e['file'] ) ), 'line' => (int) $e['line'] ),
			)
		);
	}

	/**
	 * هشدار و اخطار PHP فقط در فایل‌های همین افزونه (حالت عیب‌یابی). رفتار
	 * عادی PHP عوض نمی‌شود: false یعنی «ادامه بده».
	 */
	public static function capture_warning( $no, $str, $file = '', $line = 0 ) {
		if ( defined( 'CMB_DIR' ) && 0 === strpos( wp_normalize_path( (string) $file ), wp_normalize_path( CMB_DIR ) ) ) {
			$key = 'cmb_logw_' . md5( $file . ':' . $line );

			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, HOUR_IN_SECONDS );
				self::log( 'warn', 'php', 'php_warning', (string) $str, array( 'context' => array( 'file' => str_replace( wp_normalize_path( CMB_DIR ), '', wp_normalize_path( (string) $file ) ), 'line' => (int) $line, 'type' => (int) $no ) ) );
			}
		}

		if ( self::$prev && is_callable( self::$prev ) ) {
			return call_user_func( self::$prev, $no, $str, $file, $line );
		}

		return false;
	}

	/* ------------------------------------------------------------------
	 * مسیرها
	 * --------------------------------------------------------------- */

	public static function routes() {
		$admin = array( CMB_Panel_Api::instance(), 'guard_center' );

		// خطای جاوااسکریپت اپ و پنل؛ عمداً بدون nonce (خطا ممکن است درست
		// همان لحظه‌ای باشد که nonce کهنه شده) ولی با سقف تعداد.
		register_rest_route(
			'cmb/v1',
			'/log',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'client_log' ),
				'permission_callback' => '__return_true',
			)
		);

		$list = array(
			'panel/debug/log'          => array( 'GET', 'rest_list' ),
			'panel/debug/log/clear'    => array( 'POST', 'rest_clear' ),
			'panel/debug/settings'     => array( 'POST', 'rest_settings' ),
		);

		foreach ( $list as $path => $conf ) {
			register_rest_route(
				'cmb/v1',
				'/' . $path,
				array(
					'methods'             => 'GET' === $conf[0] ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $conf[1] ),
					'permission_callback' => $admin,
				)
			);
		}
	}

	/**
	 * گزارش خطای سمت مرورگر.
	 */
	public static function client_log( WP_REST_Request $request ) {
		if ( ! self::ready() || ! self::on() ) {
			return rest_ensure_response( array( 'success' => true, 'ref' => '' ) );
		}

		// هر IP حداکثر ۳۰ گزارش در ده دقیقه
		$key = 'cmb_logc_' . md5( cmb_get_ip() );
		$n   = (int) get_transient( $key );

		if ( $n >= 30 ) {
			return new WP_Error( 'cmb_log_rate', 'گزارش بیش از حد.', array( 'status' => 429 ) );
		}

		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

		$p      = (array) $request->get_json_params();
		$source = isset( $p['source'] ) && 'panel' === $p['source'] ? 'panel' : 'app';
		$kind   = isset( $p['kind'] ) ? sanitize_key( (string) $p['kind'] ) : 'js';
		$msg    = isset( $p['message'] ) ? sanitize_textarea_field( (string) $p['message'] ) : '';

		if ( '' === $msg ) {
			return new WP_Error( 'cmb_log_empty', 'پیام خالی است.', array( 'status' => 400 ) );
		}

		$ctx = array(
			'url'   => isset( $p['url'] ) ? esc_url_raw( (string) $p['url'] ) : '',
			'where' => isset( $p['where'] ) ? sanitize_text_field( (string) $p['where'] ) : '',
			'stack' => isset( $p['stack'] ) ? cmb_substr( sanitize_textarea_field( (string) $p['stack'] ), 0, 1500 ) : '',
			'state' => isset( $p['state'] ) && is_array( $p['state'] ) ? $p['state'] : array(),
			'ver'   => isset( $p['ver'] ) ? sanitize_text_field( (string) $p['ver'] ) : '',
		);

		$level = 'report' === $kind ? 'info' : 'error';
		$ref   = self::log( $level, $source, 'report' === $kind ? 'client_report' : 'js_error', $msg, array( 'context' => $ctx ) );

		return rest_ensure_response( array( 'success' => true, 'ref' => $ref ) );
	}

	/**
	 * فهرست گزارش برای پنل.
	 */
	public static function rest_list( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'ready' => false, 'settings' => self::settings_payload() ) );
		}

		$t      = cmb_table( 'log' );
		$where  = array( '1=1' );
		$args   = array();
		$level  = sanitize_key( (string) $request->get_param( 'level' ) );
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		$q      = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 40;

		if ( in_array( $level, array( 'error', 'warn', 'info' ), true ) ) {
			$where[] = 'level = %s';
			$args[]  = $level;
		}

		if ( '' !== $source ) {
			$where[] = 'source = %s';
			$args[]  = $source;
		}

		if ( '' !== $q ) {
			$q       = cmb_en_num( $q );
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '( ref = %s OR code LIKE %s OR message LIKE %s OR route LIKE %s OR phone LIKE %s )';
			$args    = array_merge( $args, array( strtoupper( $q ), $like, $like, $like, $like ) );
		}

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore
		$list_sql  = "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore

		$items = array();

		foreach ( (array) $rows as $r ) {
			$items[] = self::to_array( $r );
		}

		$since  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore
		$counts = array( 'error' => 0, 'warn' => 0, 'info' => 0 );

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT level, COUNT(*) AS c FROM {$t} WHERE created_at >= %s GROUP BY level", $since ) ) as $c ) { // phpcs:ignore
			$counts[ $c->level ] = (int) $c->c;
		}

		return rest_ensure_response(
			array(
				'items'    => $items,
				'total'    => $total,
				'pages'    => (int) ceil( $total / $per ),
				'page'     => $page,
				'day'      => $counts,
				'ready'    => true,
				'settings' => self::settings_payload(),
			)
		);
	}

	public static function to_array( $r ) {
		$branch = (int) $r->branch_id && class_exists( 'CMB_Branches' ) ? CMB_Branches::get( (int) $r->branch_id ) : null;
		$ctx    = '' !== (string) $r->context ? json_decode( (string) $r->context, true ) : null;

		return array(
			'id'      => (int) $r->id,
			'ref'     => (string) $r->ref,
			'level'   => (string) $r->level,
			'source'  => (string) $r->source,
			'code'    => (string) $r->code,
			'message' => (string) $r->message,
			'route'   => (string) $r->route,
			'status'  => (int) $r->status,
			'phone'   => (string) $r->phone,
			'user'    => (int) $r->user_id,
			'branch'  => $branch ? (string) $branch->title : '',
			'ip'      => (string) $r->ip,
			'ua'      => (string) $r->ua,
			'context' => is_array( $ctx ) ? $ctx : null,
			'at'      => (string) $r->created_at,
			'atFa'    => cmb_jalali_date( substr( (string) $r->created_at, 0, 10 ), 'short' ) . ' ' . substr( (string) $r->created_at, 11, 5 ),
		);
	}

	public static function rest_clear( WP_REST_Request $request ) {
		global $wpdb;

		if ( self::ready() ) {
			$wpdb->query( 'DELETE FROM ' . cmb_table( 'log' ) ); // phpcs:ignore
		}

		return rest_ensure_response( array( 'success' => true, 'message' => 'گزارش خطا پاک شد.' ) );
	}

	public static function rest_settings( WP_REST_Request $request ) {
		$values = array();

		foreach ( array( 'debug_log', 'debug_mode' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$values[ $k ] = $request->get_param( $k ) ? 1 : 0;
			}
		}

		if ( $values ) {
			CMB_Settings::update( $values );
		}

		return rest_ensure_response( array( 'success' => true, 'settings' => self::settings_payload() ) );
	}

	public static function settings_payload() {
		return array(
			'log'     => self::on(),
			'verbose' => self::verbose(),
		);
	}

	/** نگه‌داری: ۱۴ روز و حداکثر ۳۰۰۰ ردیف. */
	public static function prune() {
		global $wpdb;

		if ( ! self::ready() ) {
			return;
		}

		$t      = cmb_table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::KEEP * DAY_IN_SECONDS ); // phpcs:ignore

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore

		$edge = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) ); // phpcs:ignore

		if ( $edge ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id <= %d", $edge ) ); // phpcs:ignore
		}
	}

	/** خلاصه‌ی ۲۴ ساعت گذشته برای «بررسی کامل». */
	public static function recent_summary() {
		global $wpdb;

		if ( ! self::ready() ) {
			return null;
		}

		$t     = cmb_table( 'log' );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT code, COUNT(*) AS c, MAX(message) AS message FROM {$t} WHERE created_at >= %s AND level = 'error' GROUP BY code ORDER BY c DESC LIMIT 5", $since ) ); // phpcs:ignore

		return (array) $rows;
	}
}
