<?php
/**
 * نقاط پایانی REST برای فرم رزرو.
 *
 * مسیر پایه: /wp-json/cmb/v1/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Rest {

	const NAMESPACE_V1 = 'cmb/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_services' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/otp/request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'otp_request' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'phone' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/otp/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'otp_verify' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'phone' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);

		/* بیعانه: مبلغ و قوانین پیش از پرداخت، پرداخت دوباره، انصراف.
		   pay و abandon با توکن نوبت هم کار می‌کنند، چون صفحه‌ی بازگشت از
		   درگاه در آیفون ممکن است در سافاری جدا و بی‌کوکی باز شود. */
		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings/quote',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'quote' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings/pay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pay_again' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings/abandon',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abandon' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'id'     => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'code'   => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reason' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_me' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public function check_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'cmb_bad_nonce', 'نشست شما منقضی شده است. صفحه را تازه کنید.', array( 'status' => 403 ) );
		}

		return true;
	}

	public function check_logged_in( WP_REST_Request $request ) {
		$nonce_check = $this->check_nonce( $request );

		if ( is_wp_error( $nonce_check ) ) {
			return $nonce_check;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cmb_not_logged_in', 'برای ثبت نوبت ابتدا وارد شوید.', array( 'status' => 401 ) );
		}

		return true;
	}

	/* ------------------------------------------------------------------ */

	public function get_services( WP_REST_Request $request ) {
		/* ساخت و کش پاسخ در CMB_Services::payload() است تا داده‌ی
		   تزریق‌شده در صفحه و پاسخ REST هرگز از هم فاصله نگیرند. */
		return rest_ensure_response(
			CMB_Services::payload( (int) $request->get_param( 'branch_id' ) )
		);
	}

	public function get_availability( WP_REST_Request $request ) {
		CMB_Payments::maybe_reconcile();

		$calendar = CMB_Availability::get_calendar(
			(int) $request->get_param( 'service_id' ),
			(int) $request->get_param( 'branch_id' )
		);

		if ( is_wp_error( $calendar ) ) {
			return $calendar;
		}

		return rest_ensure_response( $calendar );
	}

	public function otp_request( WP_REST_Request $request ) {
		$result = CMB_OTP::request_code( $request->get_param( 'phone' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array_merge(
				array( 'success' => true ),
				$result
			)
		);
	}

	public function otp_verify( WP_REST_Request $request ) {
		$result = CMB_OTP::verify_code(
			$request->get_param( 'phone' ),
			$request->get_param( 'code' ),
			(string) $request->get_param( 'name' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// پس از ورود، نانس جدید لازم است چون شناسه‌ی کاربر عوض شده.
		$result['success'] = true;
		$result['nonce']   = wp_create_nonce( 'wp_rest' );

		return rest_ensure_response( $result );
	}

	public function create_booking( WP_REST_Request $request ) {
		$data = array(
			'service_id'  => (int) $request->get_param( 'service_id' ),
			'date'        => (string) $request->get_param( 'date' ),
			'block'       => (string) $request->get_param( 'block' ),
			'name'        => (string) $request->get_param( 'name' ),
			'city'        => (string) $request->get_param( 'city' ),
			'car_brand'   => (string) $request->get_param( 'car_brand' ),
			'car_model'   => (string) $request->get_param( 'car_model' ),
			'car_year'    => (string) $request->get_param( 'car_year' ),
			'car_mileage' => (string) $request->get_param( 'car_mileage' ),
			'note'        => (string) $request->get_param( 'note' ),
			// بیعانه: پذیرش قوانین، و همان قوانین و مبلغی که مشتری دید
			'accept_terms' => (bool) $request->get_param( 'accept_terms' ),
			'terms_hash'   => (string) $request->get_param( 'terms_hash' ),
			'deposit_seen' => (int) $request->get_param( 'deposit_seen' ),
		);

		$result = CMB_Bookings::create( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array(
			'success' => true,
			'booking' => $result,
		);

		// با بیعانه: نوبت هنوز ثبت نهایی نشده و مشتری باید به درگاه برود
		if ( isset( $result['payment'] ) ) {
			$out['payment'] = $result['payment'];
			unset( $out['booking']['payment'] );
		}

		return rest_ensure_response( $out );
	}

	/**
	 * مبلغ بیعانه، مهلت لغو و متن قوانین برای یک شیفت مشخص.
	 */
	public function quote( WP_REST_Request $request ) {
		$service = CMB_Services::get_service( (int) $request->get_param( 'service_id' ) );
		$date    = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$block   = sanitize_key( (string) $request->get_param( 'block' ) );

		if ( ! CMB_Services::bookable( $service ) ) {
			return new WP_Error( 'cmb_service_not_found', 'خدمت انتخاب‌شده در دسترس نیست.', array( 'status' => 404 ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || '' === cmb_block_start( $block ) ) {
			return new WP_Error( 'cmb_bad_slot', 'زمان نوبت معتبر نیست.', array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'quote'   => CMB_Payments::quote( $service, $date, $block ),
			)
		);
	}

	public function pay_again( WP_REST_Request $request ) {
		$booking = CMB_Payments::resolve( (int) $request->get_param( 'id' ), (string) $request->get_param( 'token' ) );

		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		$result = CMB_Payments::retry( $booking );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) + $result );
	}

	public function abandon( WP_REST_Request $request ) {
		$booking = CMB_Payments::resolve( (int) $request->get_param( 'id' ), (string) $request->get_param( 'token' ) );

		if ( ! $booking ) {
			return new WP_Error( 'cmb_not_found', 'نوبت یافت نشد.', array( 'status' => 404 ) );
		}

		$booking = CMB_Payments::abandon( $booking );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'state'   => CMB_Payments::state_of( $booking ),
				'booking' => CMB_Bookings::to_array( $booking ),
			)
		);
	}

	/**
	 * لغو نوبت به‌درخواست خود مشتری.
	 *
	 * مالکیت و مهلت هر دو سمت سرور بررسی می‌شوند؛ به پرچم canCancel
	 * که در پاسخ قبلی رفته اعتماد نمی‌کنیم.
	 */
	public function cancel_booking( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = (int) $request->get_param( 'id' );

		if ( ! $id ) {
			$code    = (string) $request->get_param( 'code' );
			$booking = $code ? CMB_Bookings::get_by_code( $code ) : null;
			$id      = $booking ? (int) $booking->id : 0;
		}

		if ( ! $id ) {
			return new WP_Error( 'cmb_bad_id', 'نوبت مشخص نشده است.', array( 'status' => 400 ) );
		}

		$result = CMB_Bookings::cancel_by_user( $id, $user_id, (string) $request->get_param( 'reason' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// فهرست تازه برمی‌گردد تا کلاینت لازم نباشد درخواست دوم بزند.
		$bookings = array();

		foreach ( CMB_Bookings::get_user_bookings( $user_id, 10 ) as $row ) {
			$bookings[] = CMB_Bookings::to_array( $row );
		}

		$message = 'نوبت شما لغو شد و ظرفیت آزاد شد.';

		if ( ! empty( $result['pay'] ) && 'refund_due' === $result['pay']['status'] ) {
			$message = sprintf( 'نوبت شما لغو شد. %s %s.', $result['pay']['refundFa'], CMB_Payments::refund_eta( 'customer' ) );
		} elseif ( ! empty( $result['pay'] ) && 'kept' === $result['pay']['status'] ) {
			$message = 'نوبت شما لغو شد. طبق قوانین رزرو، مبلغی از بیعانه بازگردانده نمی‌شود.';
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'booking'  => $result,
				'bookings' => $bookings,
				'message'  => $message,
			)
		);
	}

	public function get_me( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return rest_ensure_response( array( 'loggedIn' => false ) );
		}

		$user     = wp_get_current_user();
		$bookings = array();

		foreach ( CMB_Bookings::get_user_bookings( $user->ID, 10 ) as $booking ) {
			$bookings[] = CMB_Bookings::to_array( $booking );
		}

		return rest_ensure_response(
			array(
				'loggedIn' => true,
				'hasPhone' => '' !== cmb_get_user_phone( $user->ID ),
				'name'     => $user->display_name,
				'phone'    => cmb_get_user_phone( $user->ID ),
				'bookings' => $bookings,
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function logout( WP_REST_Request $request ) {
		/* عمداً wp_logout() صدا زده نمی‌شود.
		   افزونه‌های ورود (ورودک، مخفی‌کننده‌ی صفحه‌ی ورود و…) معمولاً
		   روی اکشن wp_logout یک wp_redirect(); exit; می‌گذارند. داخل یک
		   درخواست REST این یعنی پاسخ نیمه‌کاره کشته می‌شود و کلاینت فکر
		   می‌کند خروج انجام نشده — دقیقاً همان چیزی که دیده می‌شد.

		   پس همان سه کار واقعی wp_logout را خودمان انجام می‌دهیم. */
		$user_id = get_current_user_id();

		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		// به افزونه‌های دیگر خبر می‌دهیم، ولی بدون اجازه‌ی ریدایرکت.
		add_filter( 'wp_redirect', '__return_false', 99999 );

		/**
		 * پس از خروج کاربر از اپ رزرو.
		 *
		 * @param int $user_id
		 */
		do_action( 'cmb_after_logout', $user_id );

		remove_filter( 'wp_redirect', '__return_false', 99999 );

		return rest_ensure_response( array( 'success' => true, 'loggedOut' => true ) );
	}
}
