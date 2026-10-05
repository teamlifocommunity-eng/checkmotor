<?php
/**
 * کیف پول مشتری.
 *
 * زرین‌پال برای این درگاه استرداد نمی‌دهد، پس پولی که باید به مشتری
 * برگردد (لغو به‌موقع، لغو از طرف مجموعه، پرداخت تکراری یا دیر) به
 * کیف پول همان مشتری در همین سایت می‌رود و با آن بیعانه‌ی نوبت‌های
 * بعدی پرداخت می‌شود. مشتری کیف پول را با درگاه شارژ هم می‌کند؛
 * برداشت به کارت ممکن نیست.
 *
 * دفتر کل: هر واریز و برداشت یک ردیف در cmb_wallet، به تومان و
 * علامت‌دار. کلید هر مشتری شماره‌ی موبایل است، همان چیزی که با آن
 * وارد می‌شود.
 *
 *   refund   برگشت به کیف پول (reason: customer، shop، duplicate، …)
 *   topup    شارژ با درگاه (payment_id)
 *   pay      بیعانه‌ی نوبت، منفی؛ تا رسیدن بخش درگاه «held»
 *   reclaim  پس گرفتن برگشتیِ لغو وقتی مدیر نوبت را بازمی‌گرداند، منفی
 *   adjust   افزایش یا کاهش دستی مدیر، با توضیح
 *
 * هر برداشت زیر قفل نام‌دار همان شماره انجام می‌شود و موجودی داخل
 * همان قفل خوانده می‌شود؛ دو درخواست هم‌زمان نمی‌توانند یک موجودی را
 * دو بار خرج کنند. واریزها موجودی را منفی نمی‌کنند و تکرارشان را خود
 * فراخواننده با به‌روزرسانی شرطیِ نوبت یا پرداخت گرفته است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Wallet {

	/** نسخه‌ی اسکیمایی که جدول کیف پول و ستون‌هایش را دارد. */
	const SCHEMA = '1.6.0';

	/** حداقل مبلغ تراکنش زرین‌پال، تومان. */
	const GATEWAY_MIN = 1000;

	/** حداکثر شارژِ نیمه‌کاره در یک ساعت برای هر شماره. */
	const TOPUP_RATE = 5;

	/* ------------------------------------------------------------------
	 * پیکربندی
	 * --------------------------------------------------------------- */

	public static function schema_ready() {
		return version_compare( (string) get_option( 'cmb_db_version', '0' ), self::SCHEMA, '>=' );
	}

	/** کیف پول جای برگشت به کارت را گرفته و جدولش ساخته شده. */
	public static function ready() {
		return self::schema_ready() && 'wallet' === CMB_Payments::refund_to();
	}

	/** خرج کیف پول فقط در بیعانه، پس فقط وقتی پرداخت بیعانه روشن است. */
	public static function spend_on() {
		return self::ready() && CMB_Payments::enabled();
	}

	public static function topup_on() {
		return self::spend_on() && (bool) CMB_Settings::get( 'wallet_topup', 1 );
	}

	/**
	 * حداقل و حداکثر شارژ، تومان.
	 *
	 * @return int[]
	 */
	public static function topup_limits() {
		$min = max( self::GATEWAY_MIN, (int) CMB_Settings::get( 'wallet_topup_min', 10000 ) );
		$max = max( $min, (int) CMB_Settings::get( 'wallet_topup_max', 5000000 ) );

		return array( $min, $max );
	}

	/** دکمه‌های آماده‌ی شارژ، در بازه‌ی مجاز. */
	public static function presets() {
		list( $min, $max ) = self::topup_limits();

		$out = array();

		foreach ( array( 50000, 100000, 200000, 500000, 1000000 ) as $v ) {
			if ( $v >= $min && $v <= $max ) {
				$out[] = $v;
			}
		}

		if ( ! $out ) {
			$out[] = $min;
		}

		return array_slice( $out, 0, 3 );
	}

	/** شماره‌ی موبایل به شکل کلید کیف پول؛ '' یعنی نامعتبر. */
	public static function key( $phone ) {
		$norm = cmb_normalize_phone( (string) $phone );

		return $norm ? $norm : '';
	}

	/* ------------------------------------------------------------------
	 * موجودی
	 * --------------------------------------------------------------- */

	/**
	 * شرط SQL «این ردیف در موجودی حساب می‌شود»: ردیف‌های done، و
	 * برداشتِ held تا وقتی نوبتش «در انتظار پرداخت» است و مهلت پرداختش
	 * نگذشته — همان شرطی که جا را در شیفت نگه می‌دارد (cmb_occupying_sql).
	 * پس مبلغِ نوبتی که مهلتش گذشته، حتی پیش از پاک‌سازی (release_stale)،
	 * دوباره قابل خرج است.
	 */
	protected static function counted( $w = 'w', $b = 'b' ) {
		global $wpdb;

		return $wpdb->prepare(
			"( {$w}.status = 'done' OR ( {$w}.status = 'held' AND {$b}.status = 'pending' AND {$b}.hold_until_gmt > %s ) )", // phpcs:ignore
			cmb_now_gmt()
		);
	}

	/**
	 * موجودی قابل خرج، تومان.
	 */
	public static function balance( $phone ) {
		global $wpdb;

		$phone = self::key( $phone );

		if ( '' === $phone || ! self::schema_ready() ) {
			return 0;
		}

		$wt = cmb_table( 'wallet' );
		$bt = cmb_table( 'bookings' );

		$cnt = self::counted();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(w.amount),0) FROM {$wt} w LEFT JOIN {$bt} b ON b.id = w.booking_id
				 WHERE w.phone = %s AND {$cnt}", // phpcs:ignore
				$phone
			)
		);
	}

	/** مبلغی که برای نوبت‌های «در انتظار پرداخت» کنار گذاشته شده، تومان. */
	public static function held( $phone ) {
		global $wpdb;

		$phone = self::key( $phone );

		if ( '' === $phone || ! self::schema_ready() ) {
			return 0;
		}

		$wt = cmb_table( 'wallet' );
		$bt = cmb_table( 'bookings' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(-w.amount),0) FROM {$wt} w JOIN {$bt} b ON b.id = w.booking_id
				 WHERE w.phone = %s AND w.status = 'held' AND b.status = 'pending' AND b.hold_until_gmt > %s", // phpcs:ignore
				$phone,
				cmb_now_gmt()
			)
		);
	}

	/**
	 * موجودی چند شماره با یک کوئری (ستون «کیف پول» فهرست مشتری‌ها).
	 *
	 * @return array شماره => موجودی
	 */
	public static function balances( array $phones ) {
		global $wpdb;

		$keys = array();

		foreach ( $phones as $p ) {
			$k = self::key( $p );

			if ( '' !== $k ) {
				$keys[ $k ] = true;
			}
		}

		if ( ! $keys || ! self::schema_ready() ) {
			return array();
		}

		$keys = array_keys( $keys );
		$wt   = cmb_table( 'wallet' );
		$bt   = cmb_table( 'bookings' );
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$cnt  = self::counted();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT w.phone, COALESCE(SUM(w.amount),0) AS bal FROM {$wt} w LEFT JOIN {$bt} b ON b.id = w.booking_id
				 WHERE w.phone IN ({$in}) AND {$cnt}
				 GROUP BY w.phone", // phpcs:ignore
				$keys
			)
		);

		$out = array();

		foreach ( (array) $rows as $r ) {
			$out[ (string) $r->phone ] = (int) $r->bal;
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * نوشتن در دفتر
	 * --------------------------------------------------------------- */

	protected static function lock( $phone ) {
		return cmb_lock( 'cmb_wallet_' . $phone, 10 );
	}

	protected static function unlock( $phone, $state ) {
		cmb_unlock( 'cmb_wallet_' . $phone, $state );
	}

	/**
	 * @return int شناسه‌ی ردیف؛ ۰ یعنی نوشته نشد.
	 */
	protected static function insert( array $row ) {
		global $wpdb;

		$now = CMB_Payments::now_local();
		$row = array_merge(
			array(
				'user_id'    => 0,
				'status'     => 'done',
				'reason'     => '',
				'booking_id' => 0,
				'payment_id' => 0,
				'note'       => '',
				'by_user'    => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$row
		);

		$row['note'] = cmb_substr( (string) $row['note'], 0, 190 );

		return $wpdb->insert( cmb_table( 'wallet' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * ستون‌های مشترک یک ردیف از روی آرگومان‌ها.
	 */
	protected static function row_args( $phone, array $args ) {
		$user = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;

		return array(
			'phone'      => $phone,
			'user_id'    => $user ? $user : self::user_for( $phone ),
			'booking_id' => isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0,
			'payment_id' => isset( $args['payment_id'] ) ? (int) $args['payment_id'] : 0,
			'note'       => isset( $args['note'] ) ? (string) $args['note'] : '',
			'by_user'    => isset( $args['by'] ) ? (int) $args['by'] : 0,
		);
	}

	/**
	 * واریز به کیف پول.
	 *
	 * @param array $args booking_id، payment_id، user_id، by، note
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	public static function credit( $phone, $amount, $type, $reason = '', array $args = array() ) {
		$phone  = self::key( $phone );
		$amount = (int) $amount;

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_wallet_phone', 'شماره‌ی موبایل مشتری معتبر نیست؛ واریز به کیف پول ممکن نشد.', array( 'status' => 400 ) );
		}

		if ( $amount < 1 ) {
			return new WP_Error( 'cmb_wallet_amount', 'مبلغ واریز معتبر نیست.', array( 'status' => 400 ) );
		}

		// واریز موجودی را منفی نمی‌کند؛ قفل فقط برای ترتیب است و اگر نشد هم ادامه
		$lock = self::lock( $phone );

		$id = self::insert(
			array_merge(
				self::row_args( $phone, $args ),
				array(
					'amount' => $amount,
					'type'   => $type,
					'reason' => (string) $reason,
				)
			)
		);

		self::unlock( $phone, $lock );

		if ( ! $id ) {
			cmb_log( 'Wallet credit failed', array( 'type' => $type, 'reason' => $reason, 'booking' => isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0 ) );

			return new WP_Error( 'cmb_db_error', 'ثبت در کیف پول ناموفق بود.', array( 'status' => 500 ) );
		}

		cmb_log( 'Wallet credit', array( 'row' => $id, 'amount' => $amount, 'type' => $type, 'reason' => $reason ) );

		return $id;
	}

	/**
	 * برداشت، زیر قفل و فقط اگر موجودی کافی است.
	 *
	 * @param string $status done، یا held برای بیعانه‌ای که بخش درگاهش مانده.
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	protected static function debit( $phone, $amount, $type, $reason = '', array $args = array(), $status = 'done' ) {
		$phone  = self::key( $phone );
		$amount = (int) $amount;

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_wallet_phone', 'شماره‌ی موبایل معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( $amount < 1 ) {
			return new WP_Error( 'cmb_wallet_amount', 'مبلغ معتبر نیست.', array( 'status' => 400 ) );
		}

		$lock = self::lock( $phone );

		if ( 'busy' === $lock ) {
			return new WP_Error( 'cmb_wallet_busy', 'کیف پول همین حالا در درخواست دیگری در حال استفاده است. چند ثانیه‌ی دیگر دوباره تلاش کنید.', array( 'status' => 503 ) );
		}

		$balance = self::balance( $phone );

		if ( $balance < $amount ) {
			self::unlock( $phone, $lock );

			return new WP_Error(
				'cmb_wallet_low',
				sprintf( 'موجودی کیف پول (%s) کافی نیست.', cmb_toman( $balance ) ),
				array(
					'status'  => 409,
					'balance' => $balance,
				)
			);
		}

		$id = self::insert(
			array_merge(
				self::row_args( $phone, $args ),
				array(
					'amount' => -$amount,
					'type'   => $type,
					'status' => $status,
					'reason' => (string) $reason,
				)
			)
		);

		self::unlock( $phone, $lock );

		if ( ! $id ) {
			return new WP_Error( 'cmb_db_error', 'ثبت در کیف پول ناموفق بود.', array( 'status' => 500 ) );
		}

		cmb_log( 'Wallet debit', array( 'row' => $id, 'amount' => $amount, 'type' => $type, 'status' => $status ) );

		return $id;
	}

	/** یک ردیف برداشت باطل می‌شود (پول به موجودی برمی‌گردد). */
	public static function void_row( $row_id ) {
		global $wpdb;

		if ( ! $row_id ) {
			return false;
		}

		$wt = cmb_table( 'wallet' );

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wt} SET status = 'void', updated_at = %s WHERE id = %d AND amount < 0 AND status IN ('held','done')", // phpcs:ignore
				CMB_Payments::now_local(),
				(int) $row_id
			)
		);
	}

	/* ------------------------------------------------------------------
	 * برگشت به کیف پول
	 * --------------------------------------------------------------- */

	/** صاحب یک پرداخت: شماره‌ی خود پرداخت، وگرنه نوبت، وگرنه حساب کاربری. */
	public static function payment_phone( $payment, $booking = null ) {
		if ( isset( $payment->phone ) && '' !== (string) $payment->phone ) {
			$k = self::key( $payment->phone );

			if ( '' !== $k ) {
				return $k;
			}
		}

		if ( ! $booking && ! empty( $payment->booking_id ) ) {
			$booking = CMB_Bookings::get( $payment->booking_id );
		}

		if ( $booking ) {
			$k = self::key( $booking->phone );

			if ( '' !== $k ) {
				return $k;
			}
		}

		return ! empty( $payment->user_id ) ? self::key( cmb_get_user_phone( (int) $payment->user_id ) ) : '';
	}

	/**
	 * برگشت یک پرداخت درگاه به کیف پول: پرداخت تکراری، پرداخت دیر،
	 * پرداخت بعد از انصراف، نوبت حذف‌شده، و انتقال از صف قدیمی کارت.
	 *
	 * ردیف پرداخت با به‌روزرسانی شرطی برداشته می‌شود، پس دو فراخوانی
	 * هم‌زمان یک پول را دو بار واریز نمی‌کنند.
	 *
	 * @param int  $amount        تومان.
	 * @param bool $touch_booking وضعیت پرداختِ خود نوبت هم «برگشت» شود؟
	 *                            برای پرداخت تکراری نه.
	 *
	 * @return true|WP_Error
	 */
	public static function refund_payment( $payment, $amount, $reason, $by = 0, $touch_booking = true, $note = '', $sms = true ) {
		global $wpdb;

		if ( ! $payment || 'paid' !== $payment->status ) {
			return new WP_Error( 'cmb_refund_no_payment', 'پرداخت موفقی برای برگشت پیدا نشد.', array( 'status' => 409 ) );
		}

		$amount  = max( 0, min( (int) ( $payment->amount_rial / 10 ), (int) $amount ) );
		$booking = $payment->booking_id ? CMB_Bookings::get( $payment->booking_id ) : null;
		$phone   = self::payment_phone( $payment, $booking );

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_wallet_phone', 'صاحب این پرداخت معلوم نیست؛ واریز به کیف پول ممکن نشد.', array( 'status' => 409 ) );
		}

		if ( $amount < 1 ) {
			return new WP_Error( 'cmb_wallet_amount', 'مبلغی برای برگشت نیست.', array( 'status' => 400 ) );
		}

		$pt  = cmb_table( 'payments' );
		$now = CMB_Payments::now_local();

		$won = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$pt} SET refund_status = 'done', refund_reason = %s, refund_amount_rial = %d, refund_method = 'wallet',
				 refund_by = %d, refund_due_at = COALESCE(refund_due_at, %s), refund_done_at = %s, refund_error = '', updated_at = %s
				 WHERE id = %d AND refund_status NOT IN ('processing','done')", // phpcs:ignore
				(string) $reason,
				$amount * 10,
				(int) $by,
				$now,
				$now,
				$now,
				(int) $payment->id
			)
		);

		if ( ! $won ) {
			return new WP_Error( 'cmb_refund_locked', 'برگشت این پرداخت قبلاً انجام شده است.', array( 'status' => 409 ) );
		}

		$row = self::credit(
			$phone,
			$amount,
			'refund',
			$reason,
			array(
				'booking_id' => (int) $payment->booking_id,
				'payment_id' => (int) $payment->id,
				'user_id'    => (int) $payment->user_id,
				'by'         => $by,
				'note'       => $note,
			)
		);

		if ( is_wp_error( $row ) ) {
			// واریز نشد: پرداخت در صف می‌ماند تا پول گم نشود
			$wpdb->update(
				$pt,
				array(
					'refund_status'  => 'due',
					'refund_method'  => '',
					'refund_done_at' => null,
					'refund_error'   => cmb_substr( $row->get_error_message(), 0, 250 ),
				),
				array( 'id' => (int) $payment->id )
			);

			return $row;
		}

		$wpdb->update( $pt, array( 'refund_ref' => 'کیف پول #' . $row ), array( 'id' => (int) $payment->id ) );

		if ( $touch_booking && $booking ) {
			$wpdb->update(
				cmb_table( 'bookings' ),
				array(
					'pay_status'    => 'refunded',
					'refund_amount' => $amount,
					'updated_at'    => $now,
				),
				array( 'id' => (int) $booking->id )
			);

			CMB_Availability::flush_cache();
		}

		if ( $sms ) {
			self::notify( $phone, $amount, 'بابت ' . self::refund_title( $reason, $booking ? $booking->tracking_code : '' ) . ' به کیف پول شما برگشت', $booking ? $booking->customer_name : '' );
		}

		return true;
	}

	/**
	 * برگشت بیعانه‌ی نوبتِ لغوشده به کیف پول (لغو مشتری یا مجموعه).
	 *
	 * سقف: کل بیعانه، چه از درگاه آمده باشد چه از کیف پول. نوبتی که
	 * تمامش با کیف پول پرداخت شده ردیف پرداختی ندارد و همین‌جا کار
	 * می‌کند. فقط نوبتِ «پرداخت‌شده» برگشت می‌گیرد (به‌روزرسانی شرطی).
	 *
	 * @param int $amount تومان؛ ۰ یعنی کل بیعانه نزد مجموعه می‌ماند.
	 *
	 * @return true|WP_Error
	 */
	public static function refund_booking( $booking, $amount, $reason, $by = 0 ) {
		global $wpdb;

		if ( ! $booking || (int) $booking->deposit_amount < 1 ) {
			return new WP_Error( 'cmb_refund_no_payment', 'این نوبت بیعانه ندارد.', array( 'status' => 409 ) );
		}

		$amount = max( 0, min( (int) $booking->deposit_amount, (int) $amount ) );
		$bt     = cmb_table( 'bookings' );
		$now    = CMB_Payments::now_local();

		$won = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$bt} SET pay_status = %s, refund_amount = %d, updated_at = %s WHERE id = %d AND pay_status = 'paid'", // phpcs:ignore
				$amount > 0 ? 'refunded' : 'kept',
				$amount,
				$now,
				(int) $booking->id
			)
		);

		CMB_Availability::flush_cache();

		if ( ! $won ) {
			return new WP_Error( 'cmb_refund_locked', 'بیعانه‌ی این نوبت قبلاً تعیین تکلیف شده است.', array( 'status' => 409 ) );
		}

		if ( $amount < 1 ) {
			return true;
		}

		$main = CMB_Payments::main_payment( $booking->id );
		$row  = self::credit(
			$booking->phone,
			$amount,
			'refund',
			$reason,
			array(
				'booking_id' => (int) $booking->id,
				'payment_id' => $main ? (int) $main->id : 0,
				'user_id'    => (int) $booking->user_id,
				'by'         => $by,
			)
		);

		if ( is_wp_error( $row ) ) {
			$wpdb->update(
				$bt,
				array(
					'pay_status'    => 'paid',
					'refund_amount' => 0,
				),
				array( 'id' => (int) $booking->id )
			);

			return $row;
		}

		self::notify( $booking->phone, $amount, 'بابت ' . self::refund_title( $reason, $booking->tracking_code ) . ' به کیف پول شما برگشت', $booking->customer_name );

		return true;
	}

	/**
	 * مدیر نوبتِ لغوشده را بازمی‌گرداند: برگشتیِ همان لغو از کیف پول
	 * برداشته می‌شود، اگر مشتری هنوز خرجش نکرده باشد.
	 *
	 * @return int|WP_Error مبلغ برداشته‌شده، تومان.
	 */
	public static function reclaim( $booking, $by = 0 ) {
		$net = self::cancel_credit( $booking->id );

		if ( $net < 1 ) {
			return 0;
		}

		$row = self::debit(
			$booking->phone,
			$net,
			'reclaim',
			'restore',
			array(
				'booking_id' => (int) $booking->id,
				'user_id'    => (int) $booking->user_id,
				'by'         => $by,
			)
		);

		if ( is_wp_error( $row ) ) {
			if ( 'cmb_wallet_low' !== $row->get_error_code() ) {
				return $row;
			}

			$data = $row->get_error_data();

			return new WP_Error(
				'cmb_wallet_spent',
				sprintf(
					'با لغو این نوبت %s به کیف پول مشتری برگشته بود و مشتری آن را خرج کرده است (موجودی فعلی: %s). بازگرداندن این نوبت ممکن نیست؛ اگر مشتری هنوز نوبت می‌خواهد، نوبت تازه بگیرد.',
					cmb_toman( $net ),
					cmb_toman( isset( $data['balance'] ) ? $data['balance'] : 0 )
				),
				array( 'status' => 409 )
			);
		}

		self::notify( $booking->phone, $net, 'بابت بازگشت نوبت ' . $booking->tracking_code . ' از کیف پول شما برداشته شد', $booking->customer_name );

		return $net;
	}

	/** برگشتیِ لغوِ یک نوبت که هنوز پس گرفته نشده، تومان. */
	public static function cancel_credit( $booking_id ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return 0;
		}

		$wt = cmb_table( 'wallet' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$wt} WHERE booking_id = %d AND status = 'done'
				 AND ( ( type = 'refund' AND reason IN ('customer','shop') ) OR type = 'reclaim' )", // phpcs:ignore
				(int) $booking_id
			)
		);
	}

	/* ------------------------------------------------------------------
	 * خرج بیعانه
	 * --------------------------------------------------------------- */

	/**
	 * چقدر از بیعانه با کیف پول پرداخت شود، تومان.
	 *
	 * بخش درگاه اگر بماند باید دست‌کم ۱,۰۰۰ تومان باشد (حداقل زرین‌پال).
	 */
	public static function quote_use( $phone, $deposit, $balance = null ) {
		$deposit = (int) $deposit;
		$balance = null === $balance ? self::balance( $phone ) : (int) $balance;

		if ( $deposit < 1 || $balance < 1 ) {
			return 0;
		}

		if ( $balance >= $deposit ) {
			return $deposit;
		}

		$use = $balance;

		if ( $deposit - $use < self::GATEWAY_MIN ) {
			$use = $deposit - self::GATEWAY_MIN;
		}

		return max( 0, $use );
	}

	/**
	 * بخش کیف پول و درگاهِ یک بیعانه، برای مرحله‌ی پرداخت اپ.
	 */
	public static function quote( $phone, $deposit ) {
		$balance = self::balance( $phone );
		$use     = self::quote_use( $phone, $deposit, $balance );
		$gateway = max( 0, (int) $deposit - $use );

		return array(
			'balance'   => $balance,
			'balanceFa' => cmb_toman( $balance ),
			'use'       => $use,
			'useFa'     => cmb_toman( $use ),
			'gateway'   => $gateway,
			'gatewayFa' => cmb_toman( $gateway ),
			'full'      => $use > 0 && 0 === $gateway,
		);
	}

	/**
	 * کل بیعانه از کیف پول: نوبت همین حالا قطعی می‌شود، بی‌درگاه.
	 *
	 * @return true|WP_Error
	 */
	public static function pay_full( $booking ) {
		global $wpdb;

		$deposit = (int) $booking->deposit_amount;
		$row     = self::debit(
			$booking->phone,
			$deposit,
			'pay',
			'',
			array(
				'booking_id' => (int) $booking->id,
				'user_id'    => (int) $booking->user_id,
			)
		);

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$bt = cmb_table( 'bookings' );

		$wpdb->update( $bt, array( 'wallet_used' => $deposit ), array( 'id' => (int) $booking->id ) );

		if ( ! CMB_Payments::confirm_wallet( $booking ) ) {
			self::void_row( $row );
			$wpdb->update( $bt, array( 'wallet_used' => 0 ), array( 'id' => (int) $booking->id ) );

			return new WP_Error( 'cmb_pay_closed', 'مهلت این نوبت همین حالا تمام شد و چیزی از کیف پولتان کم نشد. لطفاً دوباره نوبت بگیرید.', array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * پرداخت ترکیبی: بخش کیف پول تا رسیدن بخش درگاه کنار گذاشته می‌شود.
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	public static function hold( $booking, $amount ) {
		global $wpdb;

		$row = self::debit(
			$booking->phone,
			$amount,
			'pay',
			'',
			array(
				'booking_id' => (int) $booking->id,
				'user_id'    => (int) $booking->user_id,
			),
			'held'
		);

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$wpdb->update( cmb_table( 'bookings' ), array( 'wallet_used' => (int) $amount ), array( 'id' => (int) $booking->id ) );

		return $row;
	}

	/**
	 * بخش درگاهِ نوبتی که بخشی از بیعانه‌اش با کیف پول است رسید: برداشتِ
	 * کنار گذاشته قطعی می‌شود.
	 *
	 * اگر در این فاصله نوبت منقضی شده بود (پرداخت دیر)، آن مبلغ دیگر
	 * کنار گذاشته نبود و شاید خرج شده باشد؛ پس فقط اگر هنوز موجودی هست
	 * دوباره برداشته می‌شود.
	 *
	 * @return int|false شناسه‌ی ردیف برداشت (۰ یعنی این نوبت سهم کیف پول
	 *                   ندارد)، یا false یعنی موجودی دیگر کافی نیست.
	 */
	public static function settle_hold( $booking ) {
		global $wpdb;

		$need = isset( $booking->wallet_used ) ? (int) $booking->wallet_used : 0;

		if ( $need < 1 || ! self::schema_ready() ) {
			return 0;
		}

		$phone = self::key( $booking->phone );
		$wt    = cmb_table( 'wallet' );
		$lock  = self::lock( $phone );
		$fresh = CMB_Bookings::get( $booking->id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wt} WHERE booking_id = %d AND type = 'pay' AND status IN ('held','done') ORDER BY id DESC LIMIT 1", // phpcs:ignore
				(int) $booking->id
			)
		);

		$out      = false;
		$reserved = $row && 'held' === $row->status && $fresh && 'pending' === $fresh->status
			&& $fresh->hold_until_gmt && strtotime( $fresh->hold_until_gmt . ' UTC' ) > cmb_now()->getTimestamp();

		if ( $row && 'done' === $row->status ) {
			$out = (int) $row->id;
		} elseif ( $reserved ) {
			// هنوز کنار گذاشته بود: فقط قطعی می‌شود
			$out = self::finalize( $row->id ) ? (int) $row->id : false;
		} elseif ( 'busy' !== $lock && self::balance( $phone ) >= $need ) {
			if ( $row ) {
				$out = self::finalize( $row->id ) ? (int) $row->id : false;
			} else {
				$id  = self::insert(
					array_merge(
						self::row_args(
							$phone,
							array(
								'booking_id' => (int) $booking->id,
								'user_id'    => (int) $booking->user_id,
							)
						),
						array(
							'amount' => -$need,
							'type'   => 'pay',
						)
					)
				);
				$out = $id ? $id : false;
			}
		}

		self::unlock( $phone, $lock );

		return $out;
	}

	protected static function finalize( $row_id ) {
		global $wpdb;

		$wt = cmb_table( 'wallet' );

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wt} SET status = 'done', updated_at = %s WHERE id = %d AND status = 'held'", // phpcs:ignore
				CMB_Payments::now_local(),
				(int) $row_id
			)
		);
	}

	/** نوبت پرداخت نشد: مبلغِ کنار گذاشته به موجودی برمی‌گردد. */
	public static function release( $booking_id ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return 0;
		}

		$wt = cmb_table( 'wallet' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wt} SET status = 'void', updated_at = %s WHERE booking_id = %d AND type = 'pay' AND status = 'held'", // phpcs:ignore
				CMB_Payments::now_local(),
				(int) $booking_id
			)
		);
	}

	/**
	 * برداشت‌های held نوبت‌هایی که دیگر «در انتظار پرداخت» نیستند.
	 *
	 * موجودی آن‌ها را از قبل حساب نمی‌کند؛ این فقط دفتر را تمیز می‌کند.
	 */
	public static function release_stale() {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return 0;
		}

		$wt = cmb_table( 'wallet' );
		$bt = cmb_table( 'bookings' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wt} SET status = 'void', updated_at = %s
				 WHERE status = 'held' AND booking_id NOT IN ( SELECT id FROM {$bt} WHERE status = 'pending' AND hold_until_gmt > %s )", // phpcs:ignore
				CMB_Payments::now_local(),
				cmb_now_gmt()
			)
		);
	}

	/* ------------------------------------------------------------------
	 * شارژ با درگاه
	 * --------------------------------------------------------------- */

	public static function topup_callback( $pid, $token ) {
		return add_query_arg(
			array(
				'cmb_p' => (int) $pid,
				'cmb_t' => (string) $token,
			),
			cmb_app_url( 'pay/wallet' )
		);
	}

	public static function topup_result_url( $payment ) {
		return add_query_arg(
			array(
				'cmb_p' => (int) $payment->id,
				'cmb_t' => (string) $payment->token,
			),
			cmb_app_url( 'wallet' )
		);
	}

	/**
	 * شروع شارژ کیف پول.
	 *
	 * @param int $amount تومان.
	 *
	 * @return array|WP_Error { url, id, token }
	 */
	public static function topup_start( $user_id, $amount ) {
		global $wpdb;

		if ( ! self::topup_on() ) {
			return new WP_Error( 'cmb_topup_off', 'شارژ کیف پول فعال نیست.', array( 'status' => 403 ) );
		}

		$phone = self::key( cmb_get_user_phone( $user_id ) );

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_no_phone', 'شماره‌ی موبایل حساب کاربری شما ثبت نشده است.', array( 'status' => 400 ) );
		}

		$amount = (int) $amount;

		list( $min, $max ) = self::topup_limits();

		if ( $amount < $min || $amount > $max ) {
			return new WP_Error( 'cmb_topup_amount', sprintf( 'مبلغ شارژ باید بین %s و %s باشد.', cmb_toman( $min ), cmb_toman( $max ) ), array( 'status' => 400 ) );
		}

		$pt    = cmb_table( 'payments' );
		$since = gmdate( 'Y-m-d H:i:s', cmb_now()->getTimestamp() - HOUR_IN_SECONDS );

		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$pt} WHERE kind = 'topup' AND phone = %s AND status <> 'paid' AND created_gmt >= %s", // phpcs:ignore
				$phone,
				$since
			)
		);

		if ( $open >= self::TOPUP_RATE ) {
			return new WP_Error( 'cmb_topup_rate', 'در یک ساعت گذشته چند بار شارژ نیمه‌کاره ماند. کمی بعد دوباره تلاش کنید.', array( 'status' => 429 ) );
		}

		$token = wp_generate_password( 32, false, false );
		$now   = CMB_Payments::now_local();

		$wpdb->insert(
			$pt,
			array(
				'booking_id'  => 0,
				'kind'        => 'topup',
				'phone'       => $phone,
				'token'       => $token,
				'user_id'     => (int) $user_id,
				'sandbox'     => CMB_Payments::sandbox() ? 1 : 0,
				'amount_rial' => $amount * 10,
				'status'      => 'created',
				'ip'          => cmb_get_ip(),
				'created_at'  => $now,
				'created_gmt' => cmb_now_gmt(),
				'updated_at'  => $now,
			)
		);

		$pid = (int) $wpdb->insert_id;

		if ( ! $pid ) {
			return new WP_Error( 'cmb_db_error', 'ثبت پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.', array( 'status' => 500 ) );
		}

		update_option( 'cmb_pay_used', 1, false );

		$res = CMB_Payments::gateway_request(
			$pid,
			self::topup_callback( $pid, $token ),
			'شارژ کیف پول چک موتور',
			array( 'mobile' => $phone )
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'url'   => $res['url'],
			'id'    => $pid,
			'token' => $token,
		);
	}

	/** پرداخت شارژی که شناسه و توکنش با هم می‌خوانند، وگرنه null. */
	public static function topup_by_token( $pid, $tok ) {
		if ( ! $pid || strlen( (string) $tok ) < 20 || ! self::schema_ready() ) {
			return null;
		}

		$p = CMB_Payments::get_payment( $pid );

		if ( ! $p || 'topup' !== (string) $p->kind || '' === (string) $p->token || ! hash_equals( (string) $p->token, (string) $tok ) ) {
			return null;
		}

		return $p;
	}

	/**
	 * پول شارژ رسید (برنده‌ی verify، پس فقط یک بار).
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	public static function credit_topup( $payment ) {
		global $wpdb;

		$wt     = cmb_table( 'wallet' );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wt} WHERE payment_id = %d AND type = 'topup' LIMIT 1", (int) $payment->id ) ); // phpcs:ignore

		if ( $exists ) {
			return $exists;
		}

		$phone  = self::payment_phone( $payment );
		$amount = (int) ( $payment->amount_rial / 10 );

		$row = self::credit(
			$phone,
			$amount,
			'topup',
			'',
			array(
				'payment_id' => (int) $payment->id,
				'user_id'    => (int) $payment->user_id,
			)
		);

		if ( is_wp_error( $row ) ) {
			cmb_log( 'Top-up credit failed: ' . $row->get_error_message(), array( 'payment' => (int) $payment->id ) );

			return $row;
		}

		$user = $payment->user_id ? get_userdata( (int) $payment->user_id ) : null;

		self::notify( $phone, $amount, 'با شارژ به کیف پول شما اضافه شد', $user ? $user->display_name : '' );

		return $row;
	}

	protected static function read_link() {
		// phpcs:disable WordPress.Security.NonceVerification -- توکن تصادفیِ همین پرداخت جای nonce است
		$pid = isset( $_GET['cmb_p'] ) ? absint( $_GET['cmb_p'] ) : 0;
		$tok = isset( $_GET['cmb_t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['cmb_t'] ) ) : '';
		// phpcs:enable

		return array( $pid, $tok );
	}

	/**
	 * بازگشت از درگاه شارژ: verify، بعد صفحه‌ی کیف پول با نتیجه.
	 */
	public static function handle_return() {
		nocache_headers();

		list( $pid, $tok ) = self::read_link();

		$payment = self::topup_by_token( $pid, $tok );

		if ( ! $payment ) {
			wp_safe_redirect( add_query_arg( 'cmb_e', 'link', cmb_app_url( 'wallet' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$authority = isset( $_GET['Authority'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['Authority'] ) ) : '';

		if ( '' !== $authority && $authority === (string) $payment->authority ) {
			CMB_Payments::verify_payment( $payment, 'return' );
		}

		wp_safe_redirect( self::topup_result_url( $payment ) );
		exit;
	}

	/**
	 * نتیجه‌ی شارژ برای صفحه‌ی کیف پول؛ با توکن، بی‌نیاز به ورود (صفحه‌ی
	 * بازگشت از درگاه در آیفون ممکن است در سافاری جدا و بی‌کوکی باز شود).
	 *
	 * @return array|null null یعنی این صفحه نتیجه‌ی شارژ نیست.
	 */
	public static function topup_state() {
		list( $pid, $tok ) = self::read_link();

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['cmb_e'] ) ) {
			return array(
				'state'   => 'invalid',
				'message' => 'نشانی بازگشت از درگاه معتبر نبود. اگر مبلغی از حسابتان کم شده، چند دقیقه‌ی دیگر خودکار بررسی و به کیف پولتان اضافه می‌شود.',
			);
		}

		if ( ! $pid ) {
			return null;
		}

		$p = self::topup_by_token( $pid, $tok );

		if ( ! $p ) {
			return array(
				'state'   => 'invalid',
				'message' => 'این نشانی معتبر نیست. موجودی کیف پولتان را همین‌جا ببینید.',
			);
		}

		// جوابی که هنوز نیامده، همین حالا یک بار دیگر
		if ( 'requested' === $p->status ) {
			CMB_Payments::verify_payment( $p, 'result' );
			$p = CMB_Payments::get_payment( $p->id );
		}

		$amount = (int) ( $p->amount_rial / 10 );
		$base   = array(
			'id'       => (int) $p->id,
			'amount'   => $amount,
			'amountFa' => cmb_toman( $amount ),
		);

		if ( 'paid' === $p->status ) {
			$balance = self::balance( self::payment_phone( $p ) );

			return $base + array(
				'state'     => 'paid',
				'message'   => sprintf( '%s به کیف پول شما اضافه شد.', cmb_toman( $amount ) ),
				'refId'     => (string) $p->ref_id,
				'balance'   => $balance,
				'balanceFa' => cmb_toman( $balance ),
			);
		}

		if ( 'requested' === $p->status ) {
			return $base + array(
				'state'   => 'checking',
				'message' => 'نتیجه‌ی پرداخت هنوز از درگاه نرسیده. اگر مبلغ از حسابتان کم شده، چند دقیقه‌ی دیگر خودکار بررسی و به کیف پولتان اضافه می‌شود.',
			);
		}

		return $base + array(
			'state'   => 'failed',
			'message' => ( $p->gw_message ? rtrim( (string) $p->gw_message, '.' ) . '. ' : '' ) . 'شارژ انجام نشد. اگر مبلغی از حسابتان کم شده، بانک معمولاً تا ۷۲ ساعت خودش برمی‌گرداند.',
		);
	}

	/* ------------------------------------------------------------------
	 * مدیر
	 * --------------------------------------------------------------- */

	/**
	 * افزایش یا کاهش دستی.
	 *
	 * @param int $amount تومان؛ منفی یعنی کاهش.
	 *
	 * @return array|WP_Error { row, balance }
	 */
	public static function adjust( $phone, $amount, $note, $by, $sms = true ) {
		$phone  = self::key( $phone );
		$amount = (int) $amount;
		$note   = trim( sanitize_text_field( (string) $note ) );

		if ( '' === $phone ) {
			return new WP_Error( 'cmb_wallet_phone', 'شماره‌ی موبایل معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( 0 === $amount ) {
			return new WP_Error( 'cmb_wallet_amount', 'مبلغ را وارد کنید.', array( 'status' => 400 ) );
		}

		if ( abs( $amount ) > 100000000 ) {
			return new WP_Error( 'cmb_wallet_amount', 'مبلغ بیش از حد بزرگ است.', array( 'status' => 400 ) );
		}

		if ( cmb_strlen( $note ) < 3 ) {
			return new WP_Error( 'cmb_wallet_note', 'توضیح الزامی است؛ بنویسید این تغییر بابت چیست.', array( 'status' => 400 ) );
		}

		$args = array(
			'by'   => (int) $by,
			'note' => $note,
		);

		$row = $amount > 0
			? self::credit( $phone, $amount, 'adjust', 'manual', $args )
			: self::debit( $phone, -$amount, 'adjust', 'manual', $args );

		if ( is_wp_error( $row ) ) {
			if ( 'cmb_wallet_low' === $row->get_error_code() ) {
				$data = $row->get_error_data();

				return new WP_Error( 'cmb_wallet_low', sprintf( 'کاهش بیش از موجودی ممکن نیست؛ موجودی فعلی %s است.', cmb_toman( isset( $data['balance'] ) ? $data['balance'] : 0 ) ), array( 'status' => 409 ) );
			}

			return $row;
		}

		cmb_log( 'Wallet adjusted by manager', array( 'row' => $row, 'amount' => $amount, 'by' => (int) $by ) );

		if ( $sms ) {
			self::notify( $phone, abs( $amount ), $amount > 0 ? 'توسط مجموعه به کیف پول شما اضافه شد' : 'توسط مجموعه از کیف پول شما کم شد', self::name_for( $phone ) );
		}

		return array(
			'row'     => $row,
			'balance' => self::balance( $phone ),
		);
	}

	/**
	 * عنوان برگشت به کیف پول، به زبان مشتری.
	 */
	public static function refund_title( $reason, $code = '' ) {
		$code = '' !== (string) $code ? ' ' . $code : '';
		$map  = array(
			'customer'   => 'لغو نوبت%s',
			'shop'       => 'لغو نوبت%s از طرف مجموعه',
			'duplicate'  => 'پرداخت تکراری نوبت%s',
			'slot_gone'  => 'پرداخت دیر نوبت%s (ظرفیت پر شده بود)',
			'limit'      => 'پرداخت دیر نوبت%s',
			'abandoned'  => 'پرداخت بعد از انصراف از نوبت%s',
			'cancelled'  => 'پرداخت برای نوبت لغوشده%s',
			'no_booking' => 'پرداخت بی‌نوبت',
			'wallet'     => 'پرداخت دیر نوبت%s (موجودی کیف پول دیگر کافی نبود)',
		);

		return sprintf( isset( $map[ $reason ] ) ? $map[ $reason ] : 'برگشت وجه%s', $code );
	}

	public static function type_labels() {
		return array(
			'refund'  => 'برگشت به کیف پول',
			'topup'   => 'شارژ',
			'pay'     => 'پرداخت بیعانه',
			'reclaim' => 'بازگشت نوبت',
			'adjust'  => 'تغییر دستی',
		);
	}

	/**
	 * تاریخچه‌ی کیف پول یک شماره، از جدید به قدیم.
	 *
	 * @param bool $admin برای پنل: برداشت‌های باطل‌شده و نویسنده هم.
	 */
	public static function history( $phone, $limit = 50, $admin = false ) {
		global $wpdb;

		$phone = self::key( $phone );

		if ( '' === $phone || ! self::schema_ready() ) {
			return array();
		}

		$wt = cmb_table( 'wallet' );
		$bt = cmb_table( 'bookings' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT w.*, b.tracking_code, b.status AS booking_status, b.hold_until_gmt FROM {$wt} w LEFT JOIN {$bt} b ON b.id = w.booking_id
				 WHERE w.phone = %s ORDER BY w.id DESC LIMIT %d", // phpcs:ignore
				$phone,
				max( 1, min( 500, (int) $limit ) )
			)
		);

		$out = array();

		foreach ( (array) $rows as $r ) {
			$status = (string) $r->status;

			// برداشتِ held نوبتی که دیگر در انتظار نیست (یا مهلتش گذشته)، عملاً باطل است
			if ( 'held' === $status && ( 'pending' !== (string) $r->booking_status || ! $r->hold_until_gmt || strtotime( $r->hold_until_gmt . ' UTC' ) <= cmb_now()->getTimestamp() ) ) {
				$status = 'void';
			}

			if ( 'void' === $status && ! $admin ) {
				continue;
			}

			$out[] = self::row_out( $r, $status, $admin );
		}

		return $out;
	}

	protected static function row_out( $r, $status, $admin ) {
		$amount = (int) $r->amount;
		$code   = (string) $r->tracking_code;

		switch ( (string) $r->type ) {
			case 'topup':
				$title = 'شارژ کیف پول';
				break;
			case 'pay':
				$title = 'بیعانه‌ی نوبت' . ( '' !== $code ? ' ' . $code : '' );
				break;
			case 'reclaim':
				$title = 'بازگشت نوبت' . ( '' !== $code ? ' ' . $code : '' ) . '؛ برگشتیِ لغو پس گرفته شد';
				break;
			case 'adjust':
				$title = $amount > 0 ? 'افزایش توسط مجموعه' : 'کاهش توسط مجموعه';
				break;
			default:
				$title = self::refund_title( (string) $r->reason, $code );
		}

		$at = (string) $r->created_at;

		$out = array(
			'id'          => (int) $r->id,
			'amount'      => $amount,
			'amountFa'    => cmb_toman( abs( $amount ) ),
			'in'          => $amount > 0,
			'type'        => (string) $r->type,
			'reason'      => (string) $r->reason,
			'title'       => $title,
			'note'        => (string) $r->note,
			'status'      => $status,
			'statusLabel' => 'held' === $status ? 'تا پرداخت بخش درگاه کنار گذاشته شده' : ( 'void' === $status ? 'باطل شد؛ به موجودی برگشت' : '' ),
			'code'        => $code,
			'bookingId'   => (int) $r->booking_id,
			'dateFa'      => cmb_jalali_date( substr( $at, 0, 10 ), 'numeric' ) . ' ' . substr( $at, 11, 5 ),
		);

		if ( $admin ) {
			$by          = (int) $r->by_user ? get_userdata( (int) $r->by_user ) : null;
			$out['by']   = $by ? $by->display_name : '';
			$out['date'] = $at;
		}

		return $out;
	}

	/** آیا این شماره اصلاً تراکنشی دارد؟ */
	public static function has_rows( $phone ) {
		global $wpdb;

		$phone = self::key( $phone );

		if ( '' === $phone || ! self::schema_ready() ) {
			return false;
		}

		$wt = cmb_table( 'wallet' );

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wt} WHERE phone = %s LIMIT 1", $phone ) ); // phpcs:ignore
	}

	/** نام مشتری از آخرین نوبتش. */
	public static function name_for( $phone ) {
		global $wpdb;

		$bt = cmb_table( 'bookings' );

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM {$bt} WHERE phone = %s ORDER BY id DESC LIMIT 1", self::key( $phone ) ) ); // phpcs:ignore
	}

	/** حساب کاربری صاحب یک شماره (برای نگه داشتن در ردیف دفتر). */
	protected static function user_for( $phone ) {
		global $wpdb;

		if ( '' === $phone ) {
			return 0;
		}

		$bt   = cmb_table( 'bookings' );
		$user = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$bt} WHERE phone = %s AND user_id > 0 ORDER BY id DESC LIMIT 1", $phone ) ); // phpcs:ignore

		if ( $user ) {
			return $user;
		}

		$found = get_users(
			array(
				'meta_key'   => 'cmb_phone', // phpcs:ignore
				'meta_value' => $phone,      // phpcs:ignore
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * داده‌ی کیف پول برای اپ مشتری.
	 */
	public static function payload( $user_id, $limit = 30 ) {
		list( $min, $max ) = self::topup_limits();

		$phone = $user_id ? self::key( cmb_get_user_phone( $user_id ) ) : '';
		$bal   = '' !== $phone ? self::balance( $phone ) : 0;
		$held  = '' !== $phone ? self::held( $phone ) : 0;
		$hist  = '' !== $phone ? self::history( $phone, $limit ) : array();

		return array(
			'on'        => self::ready(),
			// تب کیف پول: وقتی بیعانه روشن است، یا مشتری از قبل تراکنشی دارد
			'show'      => self::ready() && ( CMB_Payments::enabled() || ! empty( $hist ) ),
			'spend'     => self::spend_on(),
			'balance'   => $bal,
			'balanceFa' => cmb_toman( $bal ),
			'held'      => $held,
			'heldFa'    => cmb_toman( $held ),
			'history'   => $hist,
			'topup'     => array(
				'on'      => self::topup_on(),
				'min'     => $min,
				'max'     => $max,
				'minFa'   => cmb_toman( $min ),
				'maxFa'   => cmb_toman( $max ),
				'presets' => self::presets(),
			),
		);
	}

	/**
	 * خلاصه برای پنل: جمع موجودی همه (بدهی مجموعه) و تعداد کیف پول‌ها.
	 */
	public static function totals() {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return array(
				'liability' => 0,
				'wallets'   => 0,
			);
		}

		$wt  = cmb_table( 'wallet' );
		$bt  = cmb_table( 'bookings' );
		$cnt = self::counted();

		$rows = $wpdb->get_results(
			"SELECT w.phone, COALESCE(SUM(w.amount),0) AS bal FROM {$wt} w LEFT JOIN {$bt} b ON b.id = w.booking_id
			 WHERE {$cnt} GROUP BY w.phone" // phpcs:ignore
		);

		$total = 0;
		$count = 0;
		$neg   = 0;

		foreach ( (array) $rows as $r ) {
			if ( (int) $r->bal > 0 ) {
				$total += (int) $r->bal;
				$count++;
			} elseif ( (int) $r->bal < 0 ) {
				$neg++;
			}
		}

		return array(
			'liability'   => $total,
			'liabilityFa' => cmb_toman( $total ),
			'wallets'     => $count,
			'negative'    => $neg,
		);
	}

	/**
	 * فهرست کیف پول‌ها برای پنل.
	 *
	 * @param string $q      نام یا شماره.
	 * @param string $filter balance (دارای موجودی) | all
	 */
	public static function list_wallets( $q = '', $filter = 'balance', $page = 1, $per = 30 ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$wt   = cmb_table( 'wallet' );
		$bt   = cmb_table( 'bookings' );
		$q    = trim( cmb_en_num( (string) $q ) );
		$args = array();
		$cond = '';

		if ( '' !== $q ) {
			$like  = '%' . $wpdb->esc_like( $q ) . '%';
			$cond  = " AND ( w.phone LIKE %s OR w.phone IN ( SELECT phone FROM {$bt} WHERE customer_name LIKE %s ) )";
			$args[] = $like;
			$args[] = $like;
		}

		$cnt    = self::counted();
		$sql    = "SELECT w.phone,
				COALESCE(SUM(CASE WHEN {$cnt} THEN w.amount ELSE 0 END),0) AS bal,
				MAX(w.id) AS last_id, COUNT(*) AS n
			 FROM {$wt} w LEFT JOIN {$bt} b ON b.id = w.booking_id
			 WHERE 1 = 1{$cond} GROUP BY w.phone ORDER BY last_id DESC";

		$all = $args
			? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) // phpcs:ignore
			: $wpdb->get_results( $sql ); // phpcs:ignore

		$all = (array) $all;

		// «دارای موجودی» در PHP (بی HAVING، که مترجم بعضی دیتابیس‌ها خرابش می‌کند)
		if ( 'balance' === $filter ) {
			$all = array_values(
				array_filter(
					$all,
					function ( $r ) {
						return 0 !== (int) $r->bal;
					}
				)
			);
		}

		$total = count( $all );
		$per   = max( 1, min( 100, (int) $per ) );
		$page  = max( 1, (int) $page );
		$slice = array_slice( $all, ( $page - 1 ) * $per, $per );
		$items = array();

		foreach ( $slice as $r ) {
			$last = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, type, amount FROM {$wt} WHERE id = %d", (int) $r->last_id ) ); // phpcs:ignore
			$at   = $last ? (string) $last->created_at : '';

			$items[] = array(
				'phone'      => (string) $r->phone,
				'phoneFa'    => cmb_fa_num( (string) $r->phone ),
				'name'       => self::name_for( $r->phone ),
				'balance'    => (int) $r->bal,
				'balanceFa'  => cmb_toman( (int) $r->bal ),
				'count'      => (int) $r->n,
				'lastFa'     => $at ? cmb_jalali_date( substr( $at, 0, 10 ), 'numeric' ) : '',
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	/* ------------------------------------------------------------------
	 * پیامک
	 * --------------------------------------------------------------- */

	/**
	 * پیامک تغییر کیف پول.
	 *
	 * متغیرهای پترن: {0} نام مشتری، {1} مبلغ (تومان)، {2} موجودی بعد از
	 * این تغییر (تومان)، {3} شرح؛ مثلاً «بابت لغو نوبت CM… به کیف پول شما
	 * برگشت». متن پیشنهادی پترن:
	 *
	 *   {0} عزیز، {1} تومان {3}. موجودی کیف پول شما: {2} تومان
	 */
	public static function notify( $phone, $amount, $subject, $name = '' ) {
		$phone = self::key( $phone );

		if ( '' === $phone ) {
			return;
		}

		$args = array(
			'' !== trim( (string) $name ) ? (string) $name : 'مشتری',
			number_format( (int) $amount ),
			number_format( max( 0, self::balance( $phone ) ) ),
			(string) $subject,
		);

		$text = self::sms_text( $args );

		cmb_after_response(
			function () use ( $phone, $args, $text ) {
				$res = CMB_SMS::send_event( $phone, 'pattern_wallet', $args, $text );

				if ( is_wp_error( $res ) && 'cmb_sms_no_pattern' !== $res->get_error_code() ) {
					cmb_log( 'Wallet SMS failed: ' . $res->get_error_message() );
				}
			}
		);
	}

	public static function sms_text( array $args ) {
		return sprintf( "چک موتور\n%s عزیز، %s تومان %s.\nموجودی کیف پول شما: %s تومان", $args[0], $args[1], $args[3], $args[2] );
	}

	/** متن پیشنهادی پترن «تغییر کیف پول» برای پنل ملی‌پیامک. */
	public static function pattern_hint() {
		return "{0} عزیز، {1} تومان {3}.\nموجودی کیف پول شما: {2} تومان\nچک موتور";
	}

	/* ------------------------------------------------------------------
	 * انتقال صف قدیمی
	 * --------------------------------------------------------------- */

	/**
	 * یک بار، بعد از ارتقا: برگشت‌هایی که در صف کارت مانده‌اند (در صف یا
	 * ناموفق) به کیف پول مشتری می‌روند، با پیامک. «در حال انجام»ها دست
	 * نمی‌خورند، چون شاید زرین‌پال انجامشان داده باشد؛ مدیر در پنل تصمیم
	 * می‌گیرد. آزمون‌های صفحه‌ی راه‌اندازی (پول خود مدیر) هم دست نمی‌خورند.
	 *
	 * @return array|null خلاصه، یا null اگر کاری نبود.
	 */
	public static function migrate_legacy() {
		global $wpdb;

		if ( get_option( 'cmb_wallet_migrated' ) || ! self::ready() ) {
			return null;
		}

		$pt = cmb_table( 'payments' );

		$rows = $wpdb->get_results(
			"SELECT * FROM {$pt} WHERE status = 'paid' AND refund_status IN ('due','failed') AND booking_id > 0 ORDER BY id ASC" // phpcs:ignore
		);

		$count = 0;
		$total = 0;
		$left  = 0;
		$wait  = array();

		/* پترن پیامک کیف پول تا این لحظه نمی‌توانسته تنظیم شده باشد (این
		   تنظیم تازه است). پیامکِ این انتقال‌ها نگه داشته می‌شود و با ذخیره‌ی
		   پترن فرستاده می‌شود (flush_migration_sms). */
		$sms_now = self::sms_ready();

		foreach ( (array) $rows as $p ) {
			$amount = (int) ( $p->refund_amount_rial / 10 );
			$reason = '' !== (string) $p->refund_reason ? (string) $p->refund_reason : 'customer';
			$res    = self::refund_payment( $p, $amount, $reason, 0, 'duplicate' !== $reason, 'انتقال از صف بازگشت وجه', $sms_now );

			if ( true === $res ) {
				$count++;
				$total += $amount;

				if ( ! $sms_now ) {
					$wait[] = (int) $p->id;
				}
			} else {
				$left++;
				cmb_log( 'Legacy refund not moved to wallet: ' . $res->get_error_message(), array( 'payment' => (int) $p->id ) );
			}
		}

		$summary = array(
			'at'          => cmb_now()->getTimestamp(),
			'count'       => $count,
			'amount'      => $total,
			'left'        => $left,
			'sms_pending' => $wait,
			'sms_sent'    => $sms_now ? $count : 0,
		);

		update_option( 'cmb_wallet_migrated', $summary, false );

		return $summary;
	}

	/** پیامک کیف پول واقعاً فرستاده می‌شود؟ (پترن، یا ارسال ساده) */
	public static function sms_ready() {
		return CMB_SMS::is_enabled()
			&& ( '' !== trim( (string) CMB_Settings::get( 'pattern_wallet', '' ) ) || 'simple' === CMB_Settings::get( 'sms_api_mode', 'pattern' ) );
	}

	/**
	 * پیامکِ انتقال‌های صف قدیمی که منتظر تنظیم پترن مانده بودند.
	 *
	 * با هر ذخیره‌ی تنظیمات صدا زده می‌شود؛ فقط یک بار می‌فرستد.
	 *
	 * @return int تعداد پیامک‌ها.
	 */
	public static function flush_migration_sms() {
		global $wpdb;

		$m = get_option( 'cmb_wallet_migrated' );

		if ( ! is_array( $m ) || empty( $m['sms_pending'] ) || ! self::ready() || ! self::sms_ready() ) {
			return 0;
		}

		$ids               = array_map( 'intval', (array) $m['sms_pending'] );
		$m['sms_pending']  = array();
		$m['sms_sent']     = ( isset( $m['sms_sent'] ) ? (int) $m['sms_sent'] : 0 ) + count( $ids );

		// اول خود فهرست خالی می‌شود تا دو ذخیره‌ی هم‌زمان دو بار نفرستند
		update_option( 'cmb_wallet_migrated', $m, false );

		$wt   = cmb_table( 'wallet' );
		$sent = 0;

		foreach ( $ids as $pid ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wt} WHERE payment_id = %d AND type = 'refund' AND status = 'done' ORDER BY id ASC LIMIT 1", $pid ) ); // phpcs:ignore

			if ( ! $row ) {
				continue;
			}

			$booking = $row->booking_id ? CMB_Bookings::get( $row->booking_id ) : null;

			self::notify(
				$row->phone,
				(int) $row->amount,
				'بابت ' . self::refund_title( (string) $row->reason, $booking ? $booking->tracking_code : '' ) . ' به کیف پول شما برگشت',
				$booking ? $booking->customer_name : self::name_for( $row->phone )
			);

			$sent++;
		}

		return $sent;
	}

	/**
	 * انتقال دستی یک ردیف صف قدیمی (مثلاً «در حال انجام») به کیف پول.
	 *
	 * @return true|WP_Error
	 */
	public static function move_legacy( $payment_id, $by ) {
		global $wpdb;

		$p = CMB_Payments::get_payment( $payment_id );

		if ( ! $p || ! in_array( (string) $p->refund_status, array( 'due', 'failed', 'processing' ), true ) || ! (int) $p->booking_id ) {
			return new WP_Error( 'cmb_refund_state', 'این برگشت در صف نیست؛ صفحه را تازه کنید.', array( 'status' => 409 ) );
		}

		// «در حال انجام» به‌روزرسانی شرطیِ refund_payment را رد می‌کند؛ مدیر تصمیمش را گرفته
		if ( 'processing' === $p->refund_status ) {
			$wpdb->update( cmb_table( 'payments' ), array( 'refund_status' => 'failed' ), array( 'id' => (int) $p->id ) );
			$p = CMB_Payments::get_payment( $p->id );
		}

		$reason = '' !== (string) $p->refund_reason ? (string) $p->refund_reason : 'customer';

		return self::refund_payment( $p, (int) ( $p->refund_amount_rial / 10 ), $reason, $by, 'duplicate' !== $reason, 'انتقال دستی از صف بازگشت وجه' );
	}
}
