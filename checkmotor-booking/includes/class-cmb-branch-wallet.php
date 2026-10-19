<?php
/**
 * کیف پول نمایندگی.
 *
 * مثل پیش‌پرداخت شارژ سیم‌کارت: نمایندگی شارژ می‌کند و هر نوبتِ سایت
 * مبلغ ثابتی (agency_fee) از موجودی آزادش کنار می‌گذارد (held). بعد از
 * گذشتن زمان نوبت، همان مبلغ قطعی کسر می‌شود (done)؛ با لغو به‌موقع
 * مشتری آزاد می‌شود (void). چون پول هر نوبت از قبل کنار گذاشته شده،
 * موجودی هرگز منفی نمی‌شود.
 *
 * دفتر: جدول cmb_branch_ledger، تومان و علامت‌دار.
 *
 *   welcome  اعتبار رایگان اولین فعال‌سازی، یک بار (welcome:{branch})
 *   topup    شارژ آنلاین زرین‌پال، یک بار برای هر پرداخت (topup:{payment})
 *   fee      هزینه‌ی هر نوبت، منفی (fee:{booking})؛ held ← done یا void
 *   refund   برگشت یک کسر قطعی توسط مدیر سایت (refund:{booking})
 *   adjust   واریز یا برداشت دستی مدیر سایت، با دلیل
 *
 * هر برداشت زیر قفل نام‌دار همان شعبه انجام می‌شود و موجودی داخل همان
 * قفل خوانده می‌شود. ستون یکتای uniq_ref جلوی هر واریز یا کسر تکراری
 * را در خود دیتابیس می‌گیرد؛ پس تکرار هر مرحله (رفرش صفحه‌ی بازگشت،
 * دوبار اجرای کرون) بی‌اثر است.
 *
 * وضعیت هزینه‌ی هر نوبت روی خود نوبت هم هست (bookings.fee_status):
 * held، captured، released، refunded. sync_fee() از روی وضعیت نوبت
 * تصمیم می‌گیرد و reconcile() هر مرحله‌ی جاافتاده را جبران می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Branch_Wallet {

	/* ------------------------------------------------------------------
	 * موجودی
	 * --------------------------------------------------------------- */

	public static function ready() {
		return CMB_Branches::ready();
	}

	/**
	 * @return array { total, held, free } تومان؛ held مثبت گزارش می‌شود.
	 */
	public static function totals( $branch_id ) {
		global $wpdb;

		if ( ! self::ready() ) {
			return array( 'total' => 0, 'held' => 0, 'free' => 0 );
		}

		$t   = cmb_table( 'branch_ledger' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN status = 'done' THEN amount ELSE 0 END),0) AS total,
					COALESCE(SUM(CASE WHEN status = 'held' THEN amount ELSE 0 END),0) AS held
				 FROM {$t} WHERE branch_id = %d", // phpcs:ignore
				(int) $branch_id
			)
		);

		$total = $row ? (int) $row->total : 0;
		$held  = $row ? (int) $row->held : 0;

		return array(
			'total' => $total,
			'held'  => -$held,
			'free'  => $total + $held,
		);
	}

	public static function free( $branch_id ) {
		$t = self::totals( $branch_id );

		return $t['free'];
	}

	/** خلاصه برای پنل (US-4.1). */
	public static function summary( $branch_id ) {
		$t    = self::totals( $branch_id );
		$fee  = CMB_Branches::fee();
		$low  = max( 0, (int) CMB_Settings::get( 'agency_low_balance', 400000 ) );
		$cap  = $fee > 0 ? (int) floor( max( 0, $t['free'] ) / $fee ) : 0;

		return array(
			'total'    => $t['total'],
			'held'     => $t['held'],
			'free'     => $t['free'],
			'totalFa'  => cmb_toman( $t['total'] ),
			'heldFa'   => cmb_toman( $t['held'] ),
			'freeFa'   => cmb_toman( $t['free'] ),
			'fee'      => $fee,
			'feeFa'    => cmb_toman( $fee ),
			'capacity' => $cap,
			'low'      => $t['free'] < $low,
			'blocked'  => $t['free'] < $fee,
		);
	}

	/* ------------------------------------------------------------------
	 * نوشتن
	 * --------------------------------------------------------------- */

	public static function lock( $branch_id ) {
		return cmb_lock( 'cmb_bwallet_' . (int) $branch_id, 10 );
	}

	public static function unlock( $branch_id, $state ) {
		cmb_unlock( 'cmb_bwallet_' . (int) $branch_id, $state );
	}

	public static function by_ref( $ref ) {
		global $wpdb;

		$t = cmb_table( 'branch_ledger' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE uniq_ref = %s", (string) $ref ) ); // phpcs:ignore
	}

	/**
	 * درج یک ردیف. با uniq_ref تکراری، ردیف موجود برگردانده می‌شود.
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	protected static function insert( array $row ) {
		global $wpdb;

		$now = cmb_now()->format( 'Y-m-d H:i:s' );
		$row = array_merge(
			array(
				'status'     => 'done',
				'booking_id' => 0,
				'payment_id' => 0,
				'uniq_ref'   => null,
				'note'       => '',
				'by_user'    => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$row
		);

		$row['note'] = cmb_substr( (string) $row['note'], 0, 190 );

		if ( null !== $row['uniq_ref'] ) {
			$existing = self::by_ref( $row['uniq_ref'] );

			if ( $existing ) {
				return (int) $existing->id;
			}
		}

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( cmb_table( 'branch_ledger' ), $row );
		$wpdb->suppress_errors( $suppress );

		if ( $ok ) {
			return (int) $wpdb->insert_id;
		}

		// درخواست هم‌زمانی همین حالا همان ردیف را نوشت (کلید یکتا)
		if ( null !== $row['uniq_ref'] ) {
			$existing = self::by_ref( $row['uniq_ref'] );

			if ( $existing ) {
				return (int) $existing->id;
			}
		}

		cmb_log( 'Branch ledger insert failed', array( 'type' => $row['type'], 'branch' => $row['branch_id'], 'error' => $wpdb->last_error ) );

		return new WP_Error( 'cmb_db_error', 'ثبت در کیف پول نمایندگی ناموفق بود.', array( 'status' => 500 ) );
	}

	/**
	 * اعتبار رایگان اولین فعال‌سازی (D15). تکرارش بی‌اثر است.
	 *
	 * @return int|WP_Error مبلغ واریزشده در همین فراخوانی (۰ اگر قبلاً واریز شده).
	 */
	public static function credit_welcome( $branch_id, $by = 0 ) {
		$amount = max( 0, (int) CMB_Settings::get( 'agency_welcome_credit', 1000000 ) );

		if ( $amount < 1 || ! CMB_Branches::is_agency( $branch_id ) ) {
			return 0;
		}

		if ( self::by_ref( 'welcome:' . (int) $branch_id ) ) {
			return 0;
		}

		$id = self::insert(
			array(
				'branch_id' => (int) $branch_id,
				'amount'    => $amount,
				'type'      => 'welcome',
				'uniq_ref'  => 'welcome:' . (int) $branch_id,
				'note'      => 'اعتبار رایگان شروع کار',
				'by_user'   => (int) $by,
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::check_notice( $branch_id );

		return $amount;
	}

	/**
	 * کنار گذاشتن هزینه‌ی نوبت. فقط داخل قفل شعبه (CMB_Bookings::create).
	 *
	 * @return int|WP_Error شناسه‌ی ردیف.
	 */
	public static function hold_fee( $booking_id, $branch_id, $fee ) {
		return self::insert(
			array(
				'branch_id'  => (int) $branch_id,
				'amount'     => -abs( (int) $fee ),
				'type'       => 'fee',
				'status'     => 'held',
				'booking_id' => (int) $booking_id,
				'uniq_ref'   => 'fee:' . (int) $booking_id,
			)
		);
	}

	/** ردیف هزینه‌ی یک نوبت. */
	public static function fee_row( $booking_id ) {
		return self::by_ref( 'fee:' . (int) $booking_id );
	}

	protected static function set_row_status( $row_id, $from, $to ) {
		global $wpdb;

		return (int) $wpdb->update(
			cmb_table( 'branch_ledger' ),
			array(
				'status'     => $to,
				'updated_at' => cmb_now()->format( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => (int) $row_id,
				'status' => $from,
			)
		);
	}

	protected static function set_fee_status( $booking_id, $status ) {
		global $wpdb;

		$wpdb->update( cmb_table( 'bookings' ), array( 'fee_status' => $status ), array( 'id' => (int) $booking_id ) );
	}

	/**
	 * هزینه‌ی یک نوبت را با وضعیت فعلی‌اش هم‌خوان می‌کند.
	 *
	 *   confirmed           ← held (اگر آزاد یا کسر شده بود، دوباره کنار گذاشته می‌شود)
	 *   done، no_show       ← کسر قطعی
	 *   cancelled مشتری     ← آزاد
	 *   cancelled نمایندگی  ← کسر قطعی (D13)
	 *   cancelled مدیر سایت ← انتخاب مدیر ($choice: release | capture)
	 *   expired             ← آزاد
	 *
	 * برگشت‌خورده (refunded) دست نمی‌خورد. امن برای فراخوانی چندباره.
	 *
	 * @param string|null $choice برای لغو توسط مدیر سایت.
	 *
	 * @return true|WP_Error
	 */
	public static function sync_fee( $booking, $choice = null ) {
		if ( ! self::ready() || ! $booking || empty( $booking->branch_fee ) ) {
			return true;
		}

		$row = self::fee_row( $booking->id );

		if ( ! $row || 'refunded' === (string) $booking->fee_status ) {
			return true;
		}

		$status = (string) $booking->status;
		$want   = 'held';

		if ( in_array( $status, array( 'done', 'no_show' ), true ) ) {
			$want = 'done';
		} elseif ( 'expired' === $status ) {
			$want = 'void';
		} elseif ( 'cancelled' === $status ) {
			$by = isset( $booking->cancelled_by ) ? (string) $booking->cancelled_by : '';

			if ( 'customer' === $by ) {
				$want = 'void';
			} elseif ( 'admin' === $by ) {
				if ( 'capture' === $choice ) {
					$want = 'done';
				} elseif ( 'release' === $choice ) {
					$want = 'void';
				} else {
					// بدون انتخاب: همان چیزی که قبلاً تصمیم گرفته شده
					$want = 'captured' === (string) $booking->fee_status ? 'done' : 'void';
				}
			} else {
				$want = 'done';
			}
		}

		$have = (string) $row->status;

		if ( $have === $want ) {
			self::set_fee_status( $booking->id, self::fee_label_key( $want ) );
			return true;
		}

		$branch = (int) $row->branch_id;

		if ( 'held' === $want && 'void' === $have ) {
			/* بازگرداندن نوبتی که هزینه‌اش آزاد شده بود: دوباره باید جا
			   در موجودی داشته باشد. */
			$lock = self::lock( $branch );

			if ( self::free( $branch ) < abs( (int) $row->amount ) ) {
				self::unlock( $branch, $lock );

				return new WP_Error( 'cmb_branch_low', 'موجودی آزاد کیف پول نمایندگی برای بازگرداندن این نوبت کافی نیست.', array( 'status' => 409 ) );
			}

			self::set_row_status( $row->id, 'void', 'held' );
			self::unlock( $branch, $lock );
		} else {
			self::set_row_status( $row->id, $have, $want );
		}

		self::set_fee_status( $booking->id, self::fee_label_key( $want ) );
		self::check_notice( $branch );

		return true;
	}

	protected static function fee_label_key( $ledger_status ) {
		$map = array(
			'held' => 'held',
			'done' => 'captured',
			'void' => 'released',
		);

		return isset( $map[ $ledger_status ] ) ? $map[ $ledger_status ] : '';
	}

	/**
	 * جبران مرحله‌های جاافتاده (کرون ساعتی و تکمیل خودکار).
	 *
	 * فقط ردیف‌های held بررسی می‌شوند و هر به‌روزرسانی شرطی است؛ پس
	 * دوبار اجرا همان نتیجه را دارد و هیچ نوبتی دو کسر قطعی نمی‌گیرد.
	 *
	 * @return array { captured, released }
	 */
	public static function reconcile() {
		global $wpdb;

		$out = array( 'captured' => 0, 'released' => 0 );

		if ( ! self::ready() ) {
			return $out;
		}

		$t   = cmb_table( 'branch_ledger' );
		$b   = cmb_table( 'bookings' );
		$now = cmb_now()->format( 'Y-m-d H:i:s' );

		$out['captured'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = 'done', updated_at = %s
				 WHERE type = 'fee' AND status = 'held' AND booking_id IN (
					SELECT id FROM {$b} WHERE status IN ('done','no_show')
						OR ( status = 'cancelled' AND fee_status = 'captured' )
						OR ( status = 'cancelled' AND cancelled_by = 'branch' )
				 )", // phpcs:ignore
				$now
			)
		);

		$out['released'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = 'void', updated_at = %s
				 WHERE type = 'fee' AND status = 'held' AND (
					booking_id NOT IN ( SELECT id FROM {$b} )
					OR booking_id IN (
						SELECT id FROM {$b} WHERE status = 'expired'
							OR ( status = 'cancelled' AND cancelled_by = 'customer' )
							OR ( status = 'cancelled' AND fee_status = 'released' )
					)
				 )", // phpcs:ignore
				$now
			)
		);

		// نشانه‌ی روی نوبت هم با دفتر هم‌خوان شود
		$wpdb->query( "UPDATE {$b} SET fee_status = 'captured' WHERE branch_fee > 0 AND fee_status = 'held' AND id IN ( SELECT booking_id FROM {$t} WHERE type = 'fee' AND status = 'done' )" ); // phpcs:ignore
		$wpdb->query( "UPDATE {$b} SET fee_status = 'released' WHERE branch_fee > 0 AND fee_status = 'held' AND id IN ( SELECT booking_id FROM {$t} WHERE type = 'fee' AND status = 'void' )" ); // phpcs:ignore

		if ( $out['captured'] || $out['released'] ) {
			// حداکثر ده نمایندگی؛ پله‌ی هشدار همه دوباره سنجیده می‌شود
			foreach ( CMB_Branches::all() as $br ) {
				if ( CMB_Branches::is_agency( $br ) ) {
					self::check_notice( (int) $br->id );
				}
			}

			cmb_log( 'Branch fees reconciled', $out );
		}

		return $out;
	}

	/**
	 * برگشت کسرِ قطعیِ یک نوبت توسط مدیر سایت (استثنای لغو نمایندگی یا
	 * عدم مراجعه؛ D13 و D14).
	 *
	 * @return int|WP_Error شناسه‌ی ردیف برگشت.
	 */
	public static function refund_fee( $booking_id, $by, $note = '' ) {
		$booking = CMB_Bookings::get( $booking_id );
		$row     = self::fee_row( $booking_id );

		if ( ! $booking || ! $row ) {
			return new WP_Error( 'cmb_not_found', 'این نوبت هزینه‌ی نمایندگی ندارد.', array( 'status' => 404 ) );
		}

		if ( 'done' !== (string) $row->status ) {
			return new WP_Error( 'cmb_fee_not_captured', 'هزینه‌ی این نوبت هنوز قطعی کسر نشده؛ چیزی برای برگشت نیست.', array( 'status' => 409 ) );
		}

		if ( self::by_ref( 'refund:' . (int) $booking_id ) ) {
			return new WP_Error( 'cmb_fee_refunded', 'هزینه‌ی این نوبت قبلاً برگشت داده شده است.', array( 'status' => 409 ) );
		}

		$id = self::insert(
			array(
				'branch_id'  => (int) $row->branch_id,
				'amount'     => abs( (int) $row->amount ),
				'type'       => 'refund',
				'booking_id' => (int) $booking_id,
				'uniq_ref'   => 'refund:' . (int) $booking_id,
				'note'       => '' !== trim( (string) $note ) ? sanitize_text_field( (string) $note ) : 'برگشت هزینه‌ی نوبت ' . $booking->tracking_code,
				'by_user'    => (int) $by,
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::set_fee_status( $booking_id, 'refunded' );
		self::check_notice( (int) $row->branch_id );

		return $id;
	}

	/**
	 * واریز یا برداشت دستی مدیر سایت، با دلیل اجباری. برداشت از موجودی
	 * آزاد بیشتر نمی‌شود.
	 *
	 * @param int $amount تومان؛ منفی یعنی برداشت.
	 *
	 * @return int|WP_Error
	 */
	public static function adjust( $branch_id, $amount, $note, $by ) {
		$amount = (int) $amount;
		$note   = trim( sanitize_text_field( (string) $note ) );

		if ( ! CMB_Branches::is_agency( $branch_id ) ) {
			return new WP_Error( 'cmb_not_agency', 'این شعبه کیف پول ندارد.', array( 'status' => 400 ) );
		}

		if ( 0 === $amount || abs( $amount ) > 1000000000 ) {
			return new WP_Error( 'cmb_bw_amount', 'مبلغ معتبر نیست.', array( 'status' => 400 ) );
		}

		if ( cmb_strlen( $note ) < 3 ) {
			return new WP_Error( 'cmb_bw_note', 'دلیل این تغییر را بنویسید.', array( 'status' => 400 ) );
		}

		$lock = self::lock( $branch_id );

		if ( $amount < 0 && self::free( $branch_id ) < abs( $amount ) ) {
			self::unlock( $branch_id, $lock );

			return new WP_Error( 'cmb_bw_low', 'برداشت نمی‌تواند از موجودی آزاد نمایندگی بیشتر باشد.', array( 'status' => 409 ) );
		}

		$id = self::insert(
			array(
				'branch_id' => (int) $branch_id,
				'amount'    => $amount,
				'type'      => 'adjust',
				'note'      => $note,
				'by_user'   => (int) $by,
			)
		);

		self::unlock( $branch_id, $lock );

		if ( ! is_wp_error( $id ) ) {
			self::check_notice( $branch_id );
		}

		return $id;
	}

	/**
	 * پول شارژ رسید (برنده‌ی verify). تکرارش (رفرش، reconcile) بی‌اثر است.
	 *
	 * @return int|WP_Error
	 */
	public static function credit_topup( $payment ) {
		$ref = 'topup:' . (int) $payment->id;

		if ( self::by_ref( $ref ) ) {
			return (int) self::by_ref( $ref )->id;
		}

		$branch = isset( $payment->branch_id ) ? (int) $payment->branch_id : 0;
		$amount = (int) ( (int) $payment->amount_rial / 10 );

		if ( ! $branch || $amount < 1 ) {
			return new WP_Error( 'cmb_bw_topup', 'پرداخت شارژ شعبه‌ی معتبری ندارد.', array( 'status' => 400 ) );
		}

		$id = self::insert(
			array(
				'branch_id'  => $branch,
				'amount'     => $amount,
				'type'       => 'topup',
				'payment_id' => (int) $payment->id,
				'uniq_ref'   => $ref,
				'by_user'    => (int) $payment->user_id,
			)
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::check_notice( $branch );

		$free = self::free( $branch );

		cmb_after_response(
			function () use ( $branch, $amount, $free ) {
				CMB_Branch_Notify::topup( $branch, $amount, $free );
			}
		);

		return $id;
	}

	/* ------------------------------------------------------------------
	 * هشدار موجودی
	 * --------------------------------------------------------------- */

	/**
	 * دو پله: زیر آستانه‌ی هشدار (۱) و زیر مبلغ یک رزرو (۲). هر پله یک
	 * پیامک، فقط هنگام عبور به پایین؛ با شارژ بالای پله دوباره مسلح
	 * می‌شود (branches.low_notice).
	 */
	public static function check_notice( $branch_id ) {
		global $wpdb;

		$b = CMB_Branches::get( $branch_id );

		if ( ! $b || ! CMB_Branches::is_agency( $b ) ) {
			return;
		}

		$free  = self::free( $branch_id );
		$fee   = CMB_Branches::fee();
		$low   = max( 0, (int) CMB_Settings::get( 'agency_low_balance', 400000 ) );
		$level = $free < $fee ? 2 : ( $free < $low ? 1 : 0 );
		$had   = isset( $b->low_notice ) ? (int) $b->low_notice : 0;

		if ( $level === $had ) {
			return;
		}

		$wpdb->update( cmb_table( 'branches' ), array( 'low_notice' => $level ), array( 'id' => (int) $branch_id ) );
		CMB_Services::flush_cache();

		// فقط عبور به پایین پیامک دارد؛ و فقط برای نمایندگیِ فعال
		if ( $level > $had && 'active' === CMB_Branches::status( $b ) ) {
			$cap = $fee > 0 ? (int) floor( max( 0, $free ) / $fee ) : 0;

			cmb_after_response(
				function () use ( $branch_id, $free, $cap ) {
					CMB_Branch_Notify::low_balance( $branch_id, $free, $cap );
				}
			);
		}
	}

	/* ------------------------------------------------------------------
	 * تاریخچه
	 * --------------------------------------------------------------- */

	public static function type_labels() {
		return array(
			'welcome' => 'اعتبار اولیه',
			'topup'   => 'شارژ آنلاین',
			'fee'     => 'هزینه‌ی نوبت',
			'refund'  => 'برگشت هزینه',
			'adjust'  => 'تغییر دستی',
		);
	}

	public static function status_labels() {
		return array(
			'held' => 'بلوکه',
			'done' => 'قطعی',
			'void' => 'آزاد شد',
		);
	}

	/**
	 * ریز تراکنش‌ها، جدیدترین بالا، با «مانده‌ی آزاد بعد از تراکنش».
	 *
	 * مانده با وضعیت فعلی هر ردیف حساب می‌شود (held و done در موجودی آزاد
	 * اثر دارند، void نه)؛ پس عدد آخرین ردیف همان موجودی آزاد فعلی است.
	 *
	 * @return array { items[], total, page, pages }
	 */
	public static function history( $branch_id, $page = 1, $per = 30 ) {
		global $wpdb;

		$t    = cmb_table( 'branch_ledger' );
		$b    = cmb_table( 'bookings' );
		$p    = cmb_table( 'payments' );
		$page = max( 1, (int) $page );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, bk.tracking_code, pm.ref_id
				 FROM {$t} l
				 LEFT JOIN {$b} bk ON bk.id = l.booking_id
				 LEFT JOIN {$p} pm ON pm.id = l.payment_id
				 WHERE l.branch_id = %d ORDER BY l.id ASC", // phpcs:ignore
				(int) $branch_id
			)
		);

		$types    = self::type_labels();
		$statuses = self::status_labels();
		$running  = 0;
		$items    = array();
		$names    = array();

		foreach ( $rows as $r ) {
			if ( in_array( $r->status, array( 'held', 'done' ), true ) ) {
				$running += (int) $r->amount;
			}

			$by = (int) $r->by_user;

			if ( $by && ! isset( $names[ $by ] ) ) {
				$u           = get_userdata( $by );
				$names[ $by ] = $u ? $u->display_name : '';
			}

			$items[] = array(
				'id'          => (int) $r->id,
				'type'        => (string) $r->type,
				'typeLabel'   => isset( $types[ $r->type ] ) ? $types[ $r->type ] : $r->type,
				'status'      => (string) $r->status,
				'statusLabel' => isset( $statuses[ $r->status ] ) ? $statuses[ $r->status ] : $r->status,
				'amount'      => (int) $r->amount,
				'amountFa'    => ( (int) $r->amount > 0 ? '+' : '−' ) . cmb_toman( abs( (int) $r->amount ) ),
				'balance'     => $running,
				'balanceFa'   => cmb_toman( $running ),
				'date'        => cmb_jalali_date( substr( (string) $r->created_at, 0, 10 ), 'numeric' ) . ' ' . substr( (string) $r->created_at, 11, 5 ),
				'code'        => $r->tracking_code ? (string) $r->tracking_code : '',
				'bookingId'   => (int) $r->booking_id,
				'paymentId'   => (int) $r->payment_id,
				'refId'       => $r->ref_id ? (string) $r->ref_id : '',
				'note'        => (string) $r->note,
				'by'          => $by ? $names[ $by ] : '',
			);
		}

		$items = array_reverse( $items );
		$total = count( $items );

		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $per, $per ),
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / $per ) ),
		);
	}

	/**
	 * جمع کسر و شارژ یک شعبه در یک بازه (گزارش مدیر).
	 *
	 * @return array { captured, topup }
	 */
	public static function sums( $branch_id, $from, $to ) {
		global $wpdb;

		$t   = cmb_table( 'branch_ledger' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN type = 'fee' AND status = 'done' THEN -amount ELSE 0 END),0)
					 - COALESCE(SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END),0) AS captured,
					COALESCE(SUM(CASE WHEN type = 'topup' THEN amount ELSE 0 END),0) AS topup
				 FROM {$t} WHERE branch_id = %d AND created_at >= %s AND created_at < %s", // phpcs:ignore
				(int) $branch_id,
				$from . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $to ) + DAY_IN_SECONDS ) . ' 00:00:00'
			)
		);

		return array(
			'captured' => $row ? (int) $row->captured : 0,
			'topup'    => $row ? (int) $row->topup : 0,
		);
	}
}
