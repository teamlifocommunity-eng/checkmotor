<?php
/**
 * استرداد وجه زرین‌پال (API گراف‌کیوال).
 *
 * از روی SDK رسمی زرین‌پال (ZarinPal/zarinpal-php-sdk):
 *
 *   · POST https://next.zarinpal.com/api/v4/graphql/
 *     Authorization: Bearer <توکن دسترسی از پنل زرین‌پال>
 *   · کوئری Session(terminal_id, reference_id, …) ⇒ شناسه‌ی تراکنش (session)
 *   · mutation AddRefund(session_id, amount, description, method, reason)
 *
 * مبلغ به ریال و حداقل ۲۰٬۰۰۰. برگشت sandbox ندارد؛ پرداخت‌های آزمایشی
 * اصلاً به این کلاس نمی‌رسند (CMB_Payments شبیه‌سازی‌شان می‌کند).
 *
 * خطاها WP_Error هستند و در داده‌شان «kind» دارند:
 *
 *   auth     توکن نامعتبر یا بدون دسترسی — تا اصلاح تنظیمات بی‌فایده است
 *   final    زرین‌پال قطعاً رد کرد (موجودی، سرویس غیرفعال، مهلت، …)
 *   already  این تراکنش قبلاً استرداد شده
 *   retry    موقت (۴۲۹، ۵xx) — کمی بعد دوباره
 *   unknown  پاسخی نرسید؛ معلوم نیست زرین‌پال انجامش داده یا نه
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Zarinpal_Refund {

	const URL = 'https://next.zarinpal.com/api/v4/graphql/';

	const MIN_RIAL = 20000;

	/**
	 * توکن دسترسی: ثابت wp-config مقدم است، بعد option جدا (autoload=no).
	 */
	public static function token() {
		if ( defined( 'CMB_ZP_ACCESS_TOKEN' ) && '' !== trim( (string) CMB_ZP_ACCESS_TOKEN ) ) {
			return trim( (string) CMB_ZP_ACCESS_TOKEN );
		}

		return trim( (string) get_option( 'cmb_zp_token', '' ) );
	}

	/** constant | option | '' */
	public static function token_source() {
		if ( defined( 'CMB_ZP_ACCESS_TOKEN' ) && '' !== trim( (string) CMB_ZP_ACCESS_TOKEN ) ) {
			return 'constant';
		}

		return '' !== self::token() ? 'option' : '';
	}

	public static function terminal() {
		return preg_replace( '/\D/', '', (string) CMB_Settings::get( 'zp_terminal_id', '' ) );
	}

	public static function configured() {
		return '' !== self::token() && '' !== self::terminal();
	}

	/**
	 * ذخیره یا پاک کردن توکن (جدا از بقیه‌ی تنظیمات، autoload=no).
	 * «Bearer » اول متن چسبانده‌شده برداشته می‌شود.
	 *
	 * @param string|null $token null یعنی «همان قبلی»، '' یعنی پاک کن.
	 */
	public static function save_token( $token ) {
		if ( null === $token ) {
			return;
		}

		$token = sanitize_text_field( preg_replace( '/^Bearer\s+/i', '', trim( (string) $token ) ) );

		if ( '' === $token ) {
			delete_option( 'cmb_zp_token' );
		} else {
			update_option( 'cmb_zp_token', $token, false );
		}
	}

	/**
	 * توکن چسبانده‌شده در فرم: null اگر چیزی نیامده (همان قبلی)، '' اگر
	 * تیک پاک کردن خورده.
	 */
	public static function token_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification -- فراخواننده nonce را بررسی کرده
		if ( ! empty( $_POST['zp_token_clear'] ) ) {
			return '';
		}

		$token = trim( (string) wp_unslash( $_POST['zp_access_token'] ?? '' ) );
		// phpcs:enable

		return '' === $token ? null : preg_replace( '/^Bearer\s+/i', '', $token );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * شناسه‌ی تراکنش (session) از روی شماره‌ی پیگیری پرداخت.
	 *
	 * @return string|WP_Error
	 */
	public static function find_session( $ref_id, $amount_rial ) {
		$data = self::call(
			'query Sessions($terminal_id: ID!, $reference_id: String, $limit: Int) {
				Session(terminal_id: $terminal_id, reference_id: $reference_id, limit: $limit) { id, status, amount, description, created_at }
			}',
			array(
				'terminal_id'  => self::terminal(),
				'reference_id' => (string) $ref_id,
				'limit'        => 5,
			),
			false
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$rows = ( isset( $data['Session'] ) && is_array( $data['Session'] ) ) ? $data['Session'] : array();

		foreach ( $rows as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}

			/* مبلغ باید همان پرداخت باشد (به ریال؛ اگر ترمینال تومانی گزارش
			   کند، ده برابرش). اگر نبود، به تراکنش اشتباهی برگشت نمی‌زنیم. */
			$amount = isset( $row['amount'] ) ? (int) $row['amount'] : 0;

			if ( ! $amount || $amount === (int) $amount_rial || $amount * 10 === (int) $amount_rial ) {
				return (string) $row['id'];
			}
		}

		return self::error( 'final', 'تراکنش این پرداخت در زرین‌پال پیدا نشد. شماره‌ی ترمینال را بررسی کنید: باید شناسه‌ی درگاه زرین‌پال باشد، نه «شماره پایانه»ی بانک‌ها در «خدمات‌دهندگان پرداخت».' );
	}

	/**
	 * درگاه‌های حساب زرین‌پالِ این توکن (کوئری Terminals در مستندات API).
	 * id همان «شماره‌ی ترمینال» است و key همان مرچنت کد.
	 *
	 * @return array[]|WP_Error [{ id, status, domain, key, name }]
	 */
	public static function terminals() {
		$data = self::call( 'query { Terminals { id, status, domain, key, name } }', array(), false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = array();

		foreach ( isset( $data['Terminals'] ) ? (array) $data['Terminals'] : array() as $t ) {
			if ( ! is_array( $t ) || empty( $t['id'] ) ) {
				continue;
			}

			$out[] = array(
				'id'     => preg_replace( '/\D/', '', (string) $t['id'] ),
				'status' => isset( $t['status'] ) ? (string) $t['status'] : '',
				'domain' => isset( $t['domain'] ) ? (string) $t['domain'] : '',
				'key'    => isset( $t['key'] ) ? strtolower( trim( (string) $t['key'] ) ) : '',
				'name'   => isset( $t['name'] ) ? (string) $t['name'] : '',
			);
		}

		return $out;
	}

	/**
	 * شماره‌ی ترمینالِ درگاه همین سایت: درگاهی که مرچنت کدش همان مرچنت
	 * کد تنظیمات است؛ وگرنه درگاهی با دامنه‌ی همین سایت.
	 *
	 * @return array|WP_Error { id, status, domain, key, name, by: key|domain|only }
	 */
	public static function detect_terminal( $merchant, $host ) {
		$list = self::terminals();

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		if ( ! $list ) {
			return self::error( 'final', 'در حساب زرین‌پالِ این توکن هیچ درگاهی پیدا نشد؛ توکن را از همان حسابی بسازید که درگاه این سایت در آن است.' );
		}

		$merchant = strtolower( trim( (string) $merchant ) );

		foreach ( $list as $t ) {
			if ( '' !== $merchant && $t['key'] === $merchant ) {
				return $t + array( 'by' => 'key' );
			}
		}

		$host  = self::bare_host( $host );
		$match = array();

		foreach ( $list as $t ) {
			if ( '' !== $host && self::bare_host( $t['domain'] ) === $host ) {
				$match[] = $t;
			}
		}

		if ( 1 === count( $match ) ) {
			return $match[0] + array( 'by' => 'domain' );
		}

		// فقط یک درگاه، و چیزی خلافش نمی‌گوید
		if ( 1 === count( $list ) && ( '' === $merchant || '' === $list[0]['key'] ) ) {
			return $list[0] + array( 'by' => 'only' );
		}

		return self::error( 'final', 'هیچ درگاهی در حساب زرین‌پالِ این توکن با مرچنت کد یا دامنه‌ی این سایت نمی‌خواند. درگاه‌های این حساب: ' . self::describe( $list ) . '.' );
	}

	/** «1915487 (example.com)، …» برای پیام‌ها. */
	public static function describe( array $list ) {
		$parts = array();

		foreach ( array_slice( $list, 0, 5 ) as $t ) {
			$parts[] = $t['id'] . ( '' !== $t['domain'] ? ' (' . $t['domain'] . ')' : '' );
		}

		return implode( '، ', $parts ) . ( count( $list ) > 5 ? '، …' : '' );
	}

	protected static function bare_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '#^[a-z]+://#', '', $host );
		$host = preg_replace( '#[/:].*$#', '', $host );

		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * آیا این تراکنش در زرین‌پال استرداد شده است؟
	 *
	 * @return bool|WP_Error
	 */
	public static function is_refunded( $session_id ) {
		$data = self::call(
			'query Sessions($terminal_id: ID!, $id: ID, $filter: FilterEnum, $limit: Int) {
				Session(terminal_id: $terminal_id, id: $id, filter: $filter, limit: $limit) { id, status, amount }
			}',
			array(
				'terminal_id' => self::terminal(),
				'id'          => (string) $session_id,
				'filter'      => 'REFUNDED',
				'limit'       => 1,
			),
			false
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( ( isset( $data['Session'] ) ? (array) $data['Session'] : array() ) as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === (string) $session_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * درخواست استرداد.
	 *
	 * reason همیشه CUSTOMER_REQUEST است: مستندات AddRefund فقط همین را
	 * نام می‌برد. جزئیات (پرداخت تکراری، آزمون) در description می‌آید.
	 *
	 * @param string $method PAYA | CARD
	 *
	 * @return array|WP_Error { id, amount, status, time }
	 */
	public static function add_refund( $session_id, $amount_rial, $method, $description ) {
		$data = self::call(
			'mutation AddRefund($session_id: ID!, $amount: BigInteger!, $description: String, $method: InstantPayoutActionTypeEnum, $reason: RefundReasonEnum) {
				resource: AddRefund(session_id: $session_id, amount: $amount, description: $description, method: $method, reason: $reason) {
					terminal_id, id, amount, timeline { refund_amount, refund_time, refund_status }
				}
			}',
			array(
				'session_id'  => (string) $session_id,
				'amount'      => (int) $amount_rial,
				'description' => (string) $description,
				'method'      => 'CARD' === $method ? 'CARD' : 'PAYA',
				'reason'      => 'CUSTOMER_REQUEST',
			),
			true
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$res = isset( $data['resource'] ) && is_array( $data['resource'] ) ? $data['resource'] : array();
		$tl  = isset( $res['timeline'] ) && is_array( $res['timeline'] ) ? $res['timeline'] : array();

		// گاهی timeline فهرست است
		if ( isset( $tl[0] ) && is_array( $tl[0] ) ) {
			$tl = end( $tl );
		}

		return array(
			'id'     => isset( $res['id'] ) ? (string) $res['id'] : '',
			'amount' => isset( $res['amount'] ) ? (int) $res['amount'] : (int) $amount_rial,
			'status' => isset( $tl['refund_status'] ) ? (string) $tl['refund_status'] : '',
			'time'   => isset( $tl['refund_time'] ) ? (string) $tl['refund_time'] : '',
		);
	}

	/**
	 * آزمایش توکن و ترمینال، فقط خواندنی: آخرین تراکنش ترمینال.
	 *
	 * @return array|WP_Error { count, last }
	 */
	public static function test_connection() {
		if ( '' === self::token() ) {
			return self::error( 'auth', 'توکن دسترسی زرین‌پال وارد نشده است.' );
		}

		if ( '' === self::terminal() ) {
			return self::error( 'final', 'شماره‌ی ترمینال (درگاه) زرین‌پال وارد نشده است.' );
		}

		$data = self::call(
			'query Sessions($terminal_id: ID!, $limit: Int) {
				Session(terminal_id: $terminal_id, limit: $limit) { id, status, amount, description, created_at }
			}',
			array(
				'terminal_id' => self::terminal(),
				'limit'       => 1,
			),
			false
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$rows = isset( $data['Session'] ) ? (array) $data['Session'] : array();

		return array(
			'count' => count( $rows ),
			'last'  => $rows ? $rows[0] : null,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @param bool $mutation برای تغییردهنده‌ها، قطع ارتباط یعنی «نامشخص»؛
	 *                       برای پرس‌وجو فقط «دوباره بپرس».
	 *
	 * @return array|WP_Error بخش data پاسخ.
	 */
	protected static function call( $query, array $vars, $mutation ) {
		$token = self::token();

		if ( '' === $token ) {
			return self::error( 'auth', 'توکن دسترسی زرین‌پال وارد نشده است.' );
		}

		$response = wp_remote_post(
			self::URL,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => $vars,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error(
				$mutation ? 'unknown' : 'retry',
				'ارتباط با زرین‌پال برقرار نشد (' . $response->get_error_message() . ').'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return self::error( 'auth', self::message( 'unauthenticated', $code ) );
		}

		if ( 429 === $code || $code >= 500 ) {
			return self::error( 'retry', sprintf( 'زرین‌پال موقتاً پاسخ نداد (HTTP %d). کمی بعد دوباره امتحان می‌شود.', $code ) );
		}

		if ( ! is_array( $json ) ) {
			return self::error( $mutation ? 'unknown' : 'retry', sprintf( 'پاسخ زرین‌پال قابل خواندن نبود (HTTP %d).', $code ) );
		}

		if ( ! empty( $json['errors'] ) ) {
			$first = is_array( $json['errors'] ) ? reset( $json['errors'] ) : array();
			$raw   = is_array( $first ) && isset( $first['message'] ) ? (string) $first['message'] : wp_json_encode( $json['errors'] );

			return self::classify( $raw, $code );
		}

		if ( 200 !== $code || ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return self::error( $mutation ? 'unknown' : 'retry', sprintf( 'پاسخ ناقص از زرین‌پال (HTTP %d).', $code ) );
		}

		return $json['data'];
	}

	/**
	 * خطای گراف‌کیوال ⇒ نوع و پیام فارسی.
	 */
	protected static function classify( $raw, $code ) {
		$low = strtolower( (string) $raw );

		if ( preg_match( '/unauthenti|unauthori|invalid token|token (is )?(expired|invalid)|access denied/', $low ) ) {
			return self::error( 'auth', self::message( 'unauthenticated', $code ), $raw );
		}

		if ( preg_match( '/already|before been refunded|has been refunded|duplicate refund/', $low ) ) {
			return self::error( 'already', 'این تراکنش پیش‌تر در زرین‌پال استرداد شده است.', $raw );
		}

		if ( preg_match( '/too many|rate limit|try again|timeout|temporar/', $low ) ) {
			return self::error( 'retry', 'زرین‌پال موقتاً درخواست را نپذیرفت؛ کمی بعد دوباره امتحان می‌شود.', $raw );
		}

		return self::error( 'final', self::message( $raw, $code ), $raw );
	}

	/**
	 * پیام فارسی خطاهای رایج استرداد.
	 */
	public static function message( $raw, $code = 0 ) {
		$low = strtolower( (string) $raw );

		if ( false !== strpos( $low, 'unauthenti' ) || false !== strpos( $low, 'unauthori' ) ) {
			return 'توکن دسترسی زرین‌پال نامعتبر، منقضی یا بدون دسترسی است. از پنل زرین‌پال توکن تازه بسازید و در تنظیمات بگذارید.';
		}

		if ( preg_match( '/balance|insufficient|not enough|wallet/', $low ) ) {
			return 'موجودی کیف پول زرین‌پال برای این برگشت کافی نیست. کیف پول را شارژ کنید؛ بعد «برگشت خودکار همین حالا» را بزنید.';
		}

		if ( preg_match( '/refund.*(not active|inactive|disabled|not enabled|permission|not allowed)|(permission|not allowed|forbidden)/', $low ) ) {
			return 'سرویس «استرداد وجه» روی حساب زرین‌پال فعال نیست یا توکن اجازه‌ی آن را ندارد. با تیکت به پشتیبانی زرین‌پال درخواست فعال‌سازی دهید.';
		}

		if ( preg_match( '/expire|60 day|two month|deadline|time limit/', $low ) ) {
			return 'مهلت ۲ ماهه‌ی استرداد این تراکنش در زرین‌پال گذشته است؛ مبلغ را کارت‌به‌کارت برگردانید.';
		}

		if ( preg_match( '/amount|minimum|maximum/', $low ) ) {
			return 'زرین‌پال مبلغ برگشت را نپذیرفت (حداقل ۲,۰۰۰ تومان و حداکثر مبلغ پرداخت).';
		}

		if ( preg_match( '/session|not found|transaction/', $low ) ) {
			return 'تراکنش این پرداخت در زرین‌پال پیدا نشد یا قابل استرداد نیست.';
		}

		return 'زرین‌پال استرداد را نپذیرفت: ' . cmb_substr( (string) $raw, 0, 200 ) . ( $code && 200 !== (int) $code ? ' (HTTP ' . (int) $code . ')' : '' );
	}

	protected static function error( $kind, $message, $raw = '' ) {
		return new WP_Error(
			'cmb_zp_refund_' . $kind,
			$message,
			array(
				'kind' => $kind,
				'raw'  => cmb_substr( (string) $raw, 0, 300 ),
			)
		);
	}

	public static function kind( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;

		return ( is_array( $data ) && isset( $data['kind'] ) ) ? $data['kind'] : 'final';
	}
}
