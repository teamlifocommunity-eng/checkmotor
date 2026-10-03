<?php
/**
 * سرویس پرداخت زرین‌پال (REST نسخه‌ی ۴).
 *
 * درخواست پرداخت، تأیید، فهرست پرداخت‌های تأییدنشده، برگرداندن پرداختِ
 * تازه (reverse) و استعلام وضعیت (inquiry). هیچ‌کدام وضعیتی نگه
 * نمی‌دارند؛ ذخیره و تصمیم با CMB_Payments است.
 *
 * مبلغ‌ها همیشه به ریال فرستاده می‌شوند (currency = IRR)، تا جای
 * هیچ تبدیلی در درگاه باقی نماند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Zarinpal {

	const LIVE    = 'https://payment.zarinpal.com';
	const SANDBOX = 'https://sandbox.zarinpal.com';

	/** کدهای «پرداخت موفق»: 100 تأیید تازه، 101 قبلاً تأیید شده. */
	const OK_CODES = array( 100, 101 );

	public static function host( $sandbox ) {
		return $sandbox ? self::SANDBOX : self::LIVE;
	}

	public static function start_url( $authority, $sandbox ) {
		return self::host( $sandbox ) . '/pg/StartPay/' . rawurlencode( (string) $authority );
	}

	/**
	 * درخواست پرداخت.
	 *
	 * @return array|WP_Error { authority, code, fee_type, fee }
	 */
	public static function request( $merchant, $sandbox, $amount_rial, $callback, $description, array $meta = array() ) {
		$body = array(
			'merchant_id'  => (string) $merchant,
			'amount'       => (int) $amount_rial,
			'currency'     => 'IRR',
			'callback_url' => (string) $callback,
			'description'  => (string) $description,
		);

		$metadata = array();

		if ( ! empty( $meta['mobile'] ) ) {
			$metadata['mobile'] = (string) $meta['mobile'];
		}

		if ( ! empty( $meta['order_id'] ) ) {
			$metadata['order_id'] = (string) $meta['order_id'];
		}

		if ( $metadata ) {
			$body['metadata'] = $metadata;
		}

		$res = self::call( $sandbox, '/pg/v4/payment/request.json', $body );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = isset( $res['code'] ) ? (int) $res['code'] : 0;

		if ( 100 !== $code || empty( $res['authority'] ) ) {
			return self::error( $code, isset( $res['message'] ) ? $res['message'] : '' );
		}

		return array(
			'authority' => (string) $res['authority'],
			'code'      => $code,
			'fee_type'  => isset( $res['fee_type'] ) ? (string) $res['fee_type'] : '',
			'fee'       => isset( $res['fee'] ) ? (int) $res['fee'] : 0,
		);
	}

	/**
	 * تأیید پرداخت.
	 *
	 * خطای ارتباطی WP_Error برمی‌گرداند (یعنی «معلوم نیست»؛ باید دوباره
	 * پرسید). پاسخ درگاه، چه موفق چه ناموفق، آرایه برمی‌گرداند و کد
	 * داخلش تعیین‌کننده است.
	 *
	 * @return array|WP_Error { code, message, ref_id, card_pan, card_hash, fee_type, fee }
	 */
	public static function verify( $merchant, $sandbox, $authority, $amount_rial ) {
		$res = self::call(
			$sandbox,
			'/pg/v4/payment/verify.json',
			array(
				'merchant_id' => (string) $merchant,
				'amount'      => (int) $amount_rial,
				'authority'   => (string) $authority,
			),
			true
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = isset( $res['code'] ) ? (int) $res['code'] : 0;

		return array(
			'code'      => $code,
			'message'   => self::message( $code, isset( $res['message'] ) ? (string) $res['message'] : '' ),
			'ref_id'    => isset( $res['ref_id'] ) ? (string) $res['ref_id'] : '',
			'card_pan'  => isset( $res['card_pan'] ) ? (string) $res['card_pan'] : '',
			'card_hash' => isset( $res['card_hash'] ) ? (string) $res['card_hash'] : '',
			'fee_type'  => isset( $res['fee_type'] ) ? (string) $res['fee_type'] : '',
			'fee'       => isset( $res['fee'] ) ? (int) $res['fee'] : 0,
		);
	}

	/**
	 * پرداخت‌های موفقی که هنوز تأیید نشده‌اند (کل مرچنت).
	 *
	 * @return string[]|WP_Error فهرست authorityها.
	 */
	public static function unverified( $merchant, $sandbox ) {
		$res = self::call( $sandbox, '/pg/v4/payment/unVerified.json', array( 'merchant_id' => (string) $merchant ), true );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = array();

		if ( ! empty( $res['authorities'] ) && is_array( $res['authorities'] ) ) {
			foreach ( $res['authorities'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['authority'] ) ) {
					$out[] = (string) $row['authority'];
				}
			}
		}

		return $out;
	}

	/**
	 * برگرداندن کامل پرداختی که کمتر از ۳۰ دقیقه از آن گذشته.
	 *
	 * @return true|WP_Error
	 */
	public static function reverse( $merchant, $sandbox, $authority ) {
		$res = self::call(
			$sandbox,
			'/pg/v4/payment/reverse.json',
			array(
				'merchant_id' => (string) $merchant,
				'authority'   => (string) $authority,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = isset( $res['code'] ) ? (int) $res['code'] : 0;

		return 100 === $code ? true : self::error( $code, isset( $res['message'] ) ? $res['message'] : '' );
	}

	/**
	 * وضعیت یک پرداخت در زرین‌پال: VERIFIED، PAID (تأییدنشده)، IN_BANK
	 * (هنوز در صفحه‌ی بانک)، FAILED یا REVERSED.
	 *
	 * @return array|WP_Error { code, status }
	 */
	public static function inquiry( $merchant, $sandbox, $authority ) {
		$res = self::call(
			$sandbox,
			'/pg/v4/payment/inquiry.json',
			array(
				'merchant_id' => (string) $merchant,
				'authority'   => (string) $authority,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'code'   => isset( $res['code'] ) ? (int) $res['code'] : 0,
			'status' => isset( $res['status'] ) ? strtoupper( (string) $res['status'] ) : '',
		);
	}

	/**
	 * فراخوانی یک نقطه‌ی پایانی.
	 *
	 * زرین‌پال در پاسخ موفق { data: {...}, errors: [] } و در خطا
	 * { data: [], errors: { code, message } } می‌فرستد. هر دو به یک
	 * آرایه‌ی تخت تبدیل می‌شوند که code دارد.
	 *
	 * @param bool $keep_errors خطای درگاه را هم به‌صورت آرایه برگردان
	 *                          (verify باید کد ‎-51 را ببیند، نه WP_Error).
	 *
	 * @return array|WP_Error
	 */
	protected static function call( $sandbox, $path, array $body, $keep_errors = false ) {
		$response = wp_remote_post(
			self::host( $sandbox ) . $path,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cmb_zp_http', 'ارتباط با درگاه زرین‌پال برقرار نشد: ' . $response->get_error_message(), array( 'status' => 502 ) );
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'cmb_zp_http',
				sprintf( 'پاسخ درگاه زرین‌پال قابل خواندن نبود (HTTP %d).', (int) wp_remote_retrieve_response_code( $response ) ),
				array( 'status' => 502 )
			);
		}

		if ( ! empty( $json['data'] ) && is_array( $json['data'] ) && isset( $json['data']['code'] ) ) {
			return $json['data'];
		}

		// unVerified در پاسخ موفق authorities را داخل data دارد، بدون آن‌که کد 100 همیشه باشد
		if ( ! empty( $json['data'] ) && is_array( $json['data'] ) && empty( $json['errors'] ) ) {
			return $json['data'] + array( 'code' => 100 );
		}

		$err = ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) ? $json['errors'] : array();

		// گاهی errors فهرستی از خطاهاست
		if ( isset( $err[0] ) && is_array( $err[0] ) ) {
			$err = $err[0];
		}

		$code    = isset( $err['code'] ) ? (int) $err['code'] : 0;
		$message = isset( $err['message'] ) ? (string) $err['message'] : '';

		if ( $keep_errors && $code ) {
			return array(
				'code'    => $code,
				'message' => $message,
			);
		}

		return self::error( $code, $message );
	}

	protected static function error( $code, $message = '' ) {
		return new WP_Error(
			'cmb_zp_' . ( $code ? abs( (int) $code ) : 'unknown' ),
			self::message( $code, $message ),
			array(
				'status'  => 502,
				'zp_code' => (int) $code,
			)
		);
	}

	/**
	 * پیام فارسی کدهای زرین‌پال.
	 */
	public static function message( $code, $fallback = '' ) {
		$map = array(
			100  => 'پرداخت با موفقیت تأیید شد.',
			101  => 'این پرداخت قبلاً تأیید شده است.',
			-9   => 'اطلاعات ارسالی به درگاه نامعتبر است.',
			-10  => 'مرچنت کد یا آی‌پی سرور سایت در زرین‌پال پذیرفته نشد؛ مرچنت کد را بررسی کنید و اگر در پنل زرین‌پال محدودیت آی‌پی گذاشته‌اید، آی‌پی سرور سایت را اضافه کنید.',
			-11  => 'مرچنت کد فعال نیست؛ با پشتیبانی زرین‌پال تماس بگیرید.',
			-12  => 'تلاش بیش از حد در زمان کوتاه؛ کمی بعد دوباره امتحان کنید.',
			-15  => 'درگاه پرداخت در حالت تعلیق است؛ با پشتیبانی زرین‌پال تماس بگیرید.',
			-16  => 'سطح تأیید حساب زرین‌پال کافی نیست؛ مدارک حساب را در پنل زرین‌پال کامل کنید.',
			-17  => 'سطح حساب زرین‌پال اجازه‌ی این کار را نمی‌دهد؛ با پشتیبانی زرین‌پال تماس بگیرید.',
			-18  => 'نشانی سایت با دامنه‌ی ثبت‌شده‌ی درگاه در زرین‌پال یکی نیست؛ دامنه‌ی همین سایت را در تنظیمات درگاه زرین‌پال ثبت کنید.',
			-19  => 'تراکنش‌های این درگاه از طرف زرین‌پال مسدود شده است؛ با پشتیبانی تماس بگیرید.',
			-30  => 'اجازه‌ی دسترسی به تسویه‌ی اشتراکی شناور وجود ندارد.',
			-40  => 'پارامتر اضافه‌ی درخواست نامعتبر است.',
			-41  => 'مبلغ بیشتر از سقف مجاز زرین‌پال است.',
			-50  => 'مبلغ پرداخت‌شده با مبلغ تأیید متفاوت است.',
			-51  => 'پرداخت ناموفق بود یا لغو شد.',
			-52  => 'خطای غیرمنتظره در درگاه؛ با پشتیبانی زرین‌پال تماس بگیرید.',
			-53  => 'این پرداخت متعلق به این مرچنت کد نیست.',
			-54  => 'شناسه‌ی پرداخت (Authority) نامعتبر است.',
			-55  => 'تراکنش یافت نشد.',
			-60  => 'بانک برگشت فوری این تراکنش را نپذیرفت.',
			-61  => 'تراکنش در وضعیت موفق نیست (شاید قبلاً برگشت خورده باشد).',
			-62  => 'برگشت فوری نیاز به ثبت آی‌پی سرور سایت در پنل زرین‌پال دارد (تنظیمات درگاه ← آی‌پی).',
			-63  => 'بیش از ۳۰ دقیقه از پرداخت گذشته و برگشت فوری دیگر ممکن نیست.',
		);

		if ( isset( $map[ (int) $code ] ) ) {
			return $map[ (int) $code ];
		}

		$fallback = trim( (string) $fallback );

		return '' !== $fallback
			? 'خطای درگاه زرین‌پال: ' . $fallback . ( $code ? ' (کد ' . (int) $code . ')' : '' )
			: 'خطای درگاه زرین‌پال' . ( $code ? ' (کد ' . (int) $code . ')' : '' ) . '.';
	}
}
