<?php
/**
 * بررسی بیعانه و کیف پول: جدول خدمات، شبیه‌ساز سناریو، گزارش.
 *
 * همه‌ی عددها با همان توابعی ساخته می‌شوند که هنگام رزرو و لغو واقعاً
 * به کار می‌روند (service_deposit، service_refund، shop_refund_default)،
 * پس آنچه این‌جا دیده می‌شود همان است که برای مشتری اتفاق می‌افتد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Pay_Review {

	/**
	 * جدول همه‌ی خدمات + سناریوها + هشدارها.
	 */
	public static function services() {
		$enabled = CMB_Payments::enabled();
		$hours   = max( 0, (int) CMB_Settings::get( 'cancel_deadline_hours', 24 ) );
		$items   = array();

		foreach ( CMB_Services::get_services( 0, false ) as $service ) {
			$items[] = self::service_row( $service, $hours );
		}

		$global = array();

		if ( ! $enabled ) {
			$global[] = array( 'info', 'پرداخت بیعانه خاموش است؛ این عددها از لحظه‌ی روشن کردن اعمال می‌شوند.' );
		}

		if ( CMB_Payments::sandbox() ) {
			$global[] = array( 'info', 'درگاه آزمایشی روشن است: پرداخت‌ها شبیه‌سازی می‌شوند و پولی جابه‌جا نمی‌شود.' );
		}

		$global[] = array( 'info', 'هر برگشت همان لحظه به کیف پول مشتری در همین سایت می‌رود و برای بیعانه‌ی نوبت بعدی قابل استفاده است؛ برگشت به کارت انجام نمی‌شود.' );

		return array(
			'settings' => array(
				'enabled'   => $enabled,
				'sandbox'   => CMB_Payments::sandbox(),
				'hours'     => $hours,
				'shopPct'   => (int) CMB_Settings::get( 'pay_shop_refund_percent', 100 ),
				'defDeposit' => (int) CMB_Settings::get( 'pay_deposit_default', 100000 ),
				'defRefund'  => (int) CMB_Settings::get( 'pay_cancel_refund_default', 30000 ),
			),
			'global'   => $global,
			'items'    => $items,
		);
	}

	protected static function service_row( $service, $hours ) {
		$own_dep = isset( $service->deposit_amount ) ? $service->deposit_amount : null;
		$own_ref = isset( $service->cancel_refund_amount ) ? $service->cancel_refund_amount : null;
		$deposit = CMB_Payments::service_deposit( $service, true );
		$refund  = CMB_Payments::service_refund( $service, $deposit );
		$shop    = $deposit ? CMB_Payments::shop_refund_default( $deposit ) : 0;
		$price   = (int) $service->price;
		$active  = (int) $service->is_active;

		// برگشتیِ تنظیم‌شده پیش از محدود شدن به بیعانه
		$raw_ref = ( null === $own_ref || '' === $own_ref ) ? (int) CMB_Settings::get( 'pay_cancel_refund_default', 30000 ) : (int) $own_ref;

		$warn = array();

		if ( $deposit > 0 && $deposit < 1000 ) {
			$warn[] = array( 'bad', 'بیعانه کمتر از ۱,۰۰۰ تومان است و زرین‌پال این پرداخت را نمی‌پذیرد.' );
		}

		if ( $deposit > 0 && $raw_ref > $deposit ) {
			$warn[] = array( 'warn', sprintf( 'برگشتیِ تنظیم‌شده (%s) از بیعانه بیشتر است؛ در عمل کل بیعانه برمی‌گردد.', cmb_toman( $raw_ref ) ) );
		}

		if ( $deposit > 0 && $price > 0 && $deposit >= $price ) {
			$warn[] = array( 'warn', 'بیعانه برابر یا بیشتر از قیمت خدمت است.' );
		}

		if ( $deposit > 0 && 0 === $refund ) {
			$warn[] = array( 'info', 'در لغوِ به‌موقع هیچ مبلغی برنمی‌گردد؛ متن قوانین را با همین بخوانید.' );
		}

		return array(
			'id'          => (int) $service->id,
			'title'       => $service->title,
			'active'      => $active,
			'activeFa'    => 1 === $active ? 'برای همه' : ( 2 === $active ? 'آزمایشی (فقط مدیران)' : 'غیرفعال' ),
			'price'       => $price,
			'priceFa'     => $price ? cmb_toman( $price ) : '—',
			'depositMode' => ( null === $own_dep || '' === $own_dep ) ? 'default' : ( 0 === (int) $own_dep ? 'none' : 'custom' ),
			'deposit'     => $deposit,
			'depositFa'   => $deposit ? cmb_toman( $deposit ) : 'بدون بیعانه',
			'refundMode'  => ( null === $own_ref || '' === $own_ref ) ? 'default' : 'custom',
			'refund'      => $refund,
			'refundFa'    => cmb_toman( $refund ),
			'kept'        => $deposit - $refund,
			'keptFa'      => cmb_toman( $deposit - $refund ),
			'shop'        => $shop,
			'shopFa'      => cmb_toman( $shop ),
			'remainingFa' => $price ? cmb_toman( max( 0, $price - $deposit ) ) : '—',
			'warnings'    => $warn,
			'level'       => self::worst( $warn ),
			'scenarios'   => $deposit ? self::scenarios( $deposit, $refund, $shop, $price, $hours ) : array(),
		);
	}

	protected static function worst( array $warn ) {
		$level = 'ok';

		foreach ( $warn as $w ) {
			if ( 'bad' === $w[0] ) {
				return 'bad';
			}

			if ( 'warn' === $w[0] ) {
				$level = 'warn';
			}
		}

		return $level;
	}

	/**
	 * چه کسی چقدر، کِی و چطور.
	 */
	public static function scenarios( $deposit, $refund, $shop, $price, $hours ) {
		$how = function ( $amount, $reason ) {
			if ( $amount < 1 ) {
				return array( 'none', 'برگشتی ندارد' );
			}

			return array(
				'auto',
				'بلافاصله به کیف پول مشتری در همین سایت، با پیامک؛ برای بیعانه‌ی نوبت بعدی. برداشت به کارت ندارد.'
					. ( in_array( $reason, array( 'customer', 'shop' ), true ) ? ' با «بازگردانی» نوبت، همان مبلغ از کیف پول پس گرفته می‌شود (اگر خرج نشده باشد).' : '' ),
			);
		};

		$row = function ( $key, $title, $back, $kept, $reason, $note, $customer ) use ( $how ) {
			$h = $how( $back, $reason );

			return array(
				'key'      => $key,
				'title'    => $title,
				'back'     => (int) $back,
				'backFa'   => cmb_toman( $back ),
				'kept'     => (int) $kept,
				'keptFa'   => cmb_toman( $kept ),
				'how'      => $h[0],
				'when'     => $h[1],
				'note'     => $note,
				'customer' => $customer,
			);
		};

		$eta = function () {
			return CMB_Payments::refund_eta();
		};

		$h = cmb_fa_num( $hours );

		return array(
			$row(
				'cancel_ok',
				'مشتری تا ' . $h . ' ساعت پیش از نوبت لغو می‌کند',
				$refund,
				$deposit - $refund,
				'customer',
				'مبلغ و مهلت همان است که مشتری هنگام پرداخت پذیرفت؛ تغییر بعدی تنظیمات به آن نوبت نمی‌رسد.',
				$refund ? 'نوبت شما لغو شد. ' . cmb_toman( $refund ) . ' ' . $eta() . '.' : 'نوبت شما لغو شد. طبق قوانین رزرو، مبلغی از بیعانه بازگردانده نمی‌شود.'
			),
			$row(
				'cancel_late',
				'مشتری کمتر از ' . $h . ' ساعت مانده می‌خواهد لغو کند',
				0,
				$deposit,
				'',
				'لغو آنلاین ممکن نیست. اگر نیاید، «عدم مراجعه» می‌شود.',
				'مهلت لغو آنلاین گذشته است؛ لغو تا ' . $h . ' ساعت پیش از شروع شیفت ممکن بود.'
			),
			$row(
				'shop_cancel',
				'مجموعه نوبت را لغو می‌کند',
				$shop,
				$deposit - $shop,
				'shop',
				'پیش‌فرض از درصد تنظیمات است و هنگام لغو در پنل قابل تغییر است (از ۰ تا کل بیعانه).',
				'پیامک لغو و پیامک «واریز به کیف پول» برای مشتری می‌رود.'
			),
			$row(
				'no_show',
				'مشتری لغو نمی‌کند و نمی‌آید',
				0,
				$deposit,
				'',
				'در پنل «عدم مراجعه» بزنید؛ کل بیعانه نزد مجموعه می‌ماند.',
				'—'
			),
			$row(
				'done',
				'مشتری می‌آید و خدمت انجام می‌شود',
				0,
				0,
				'',
				'بیعانه از صورت‌حساب کسر می‌شود' . ( $price ? '؛ باقی‌مانده ' . cmb_toman( max( 0, $price - $deposit ) ) : '' ) . '.',
				'—'
			),
			$row(
				'late_no_seat',
				'پول دیر رسید و دیگر جا نبود',
				$deposit,
				0,
				'slot_gone',
				'نوبت ثبت نمی‌شود و کل مبلغ برمی‌گردد.',
				'پرداخت شما رسید ولی ظرفیت آن زمان دیگر خالی نبود؛ کل مبلغ ' . $eta() . '.'
			),
			$row(
				'duplicate',
				'مشتری دو بار برای یک نوبت پرداخت کرد',
				$deposit,
				0,
				'duplicate',
				'پرداخت دوم کامل برمی‌گردد؛ نوبت با پرداخت اول سر جایش است.',
				'—'
			),
			$row(
				'abandoned',
				'مشتری انصراف داد ولی پولش بعداً رسید',
				$deposit,
				0,
				'abandoned',
				'نوبت ثبت نمی‌شود و کل مبلغ برمی‌گردد.',
				'—'
			),
		);
	}

	/**
	 * گزارش بیعانه و کیف پول در یک بازه.
	 *
	 * بیعانه‌ها از روی خود نوبت‌ها (زمان پرداخت)، با سهم کیف پول و
	 * درگاه؛ برگشت‌ها، شارژها، خرج‌ها و تغییرهای دستی از دفتر کیف پول
	 * (زمان ثبت). شارژ درآمد نیست؛ پیش‌پرداخت مشتری است و تا خرج نشده
	 * در «موجودی کیف پول‌ها» (بدهی مجموعه به مشتری‌ها) می‌ماند.
	 */
	public static function report( $days = 30, $with_sandbox = false ) {
		global $wpdb;

		if ( ! CMB_Wallet::ready() ) {
			return array(
				'days'     => (int) $days,
				'totals'   => array(),
				'reasons'  => array(),
				'services' => array(),
			);
		}

		$pt    = cmb_table( 'payments' );
		$bt    = cmb_table( 'bookings' );
		$wt    = cmb_table( 'wallet' );
		$since = $days > 0 ? cmb_now()->modify( '-' . (int) $days . ' days' )->format( 'Y-m-d 00:00:00' ) : '';

		$t = array(
			'paidCount'     => 0,
			'paid'          => 0,
			'paidWallet'    => 0,
			'paidGateway'   => 0,
			'toWallet'      => 0,
			'toWalletCount' => 0,
			'topup'         => 0,
			'topupCount'    => 0,
			'spent'         => 0,
			'reclaimed'     => 0,
			'adjust'        => 0,
			'adjustCount'   => 0,
			'gatewayIn'     => 0,
			'fees'          => 0,
			'kept'          => 0,
			'tests'         => 0,
		);

		$services = array();

		// ۱. بیعانه‌ی نوبت‌هایی که در این بازه قطعی شدند
		$where = 'b.paid_at IS NOT NULL AND b.deposit_amount > 0';

		if ( $since ) {
			$where .= $wpdb->prepare( ' AND b.paid_at >= %s', $since );
		}

		if ( ! $with_sandbox ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$pt} sp WHERE sp.booking_id = b.id AND sp.status = 'paid' AND sp.sandbox = 1 )";
		}

		$rows = $wpdb->get_results( "SELECT b.id, b.service_id, b.deposit_amount, b.wallet_used, b.pay_status, b.refund_amount FROM {$bt} b WHERE {$where}" ); // phpcs:ignore

		foreach ( (array) $rows as $b ) {
			$deposit = (int) $b->deposit_amount;
			$wallet  = min( $deposit, (int) $b->wallet_used );
			$back    = 'refunded' === $b->pay_status ? (int) $b->refund_amount : 0;
			$sid     = (int) $b->service_id;

			$t['paidCount']++;
			$t['paid']        += $deposit;
			$t['paidWallet']  += $wallet;
			$t['paidGateway'] += $deposit - $wallet;
			$t['kept']        += $deposit - $back;

			if ( ! isset( $services[ $sid ] ) ) {
				$svc              = CMB_Services::get_service( $sid );
				$services[ $sid ] = array(
					'title'    => $svc ? $svc->title : '—',
					'count'    => 0,
					'paid'     => 0,
					'refunded' => 0,
					'kept'     => 0,
				);
			}

			$services[ $sid ]['count']++;
			$services[ $sid ]['paid']     += $deposit;
			$services[ $sid ]['refunded'] += $back;
			$services[ $sid ]['kept']     += $deposit - $back;
		}

		// ۲. پول درگاه: بیعانه‌ها، شارژها، کارمزد
		$where = "p.status = 'paid'";

		if ( $since ) {
			$where .= $wpdb->prepare( ' AND p.paid_at >= %s', $since );
		}

		if ( ! $with_sandbox ) {
			$where .= ' AND p.sandbox = 0';
		}

		foreach ( (array) $wpdb->get_results( "SELECT p.kind, p.booking_id, p.amount_rial, p.fee, p.refund_reason FROM {$pt} p WHERE {$where}" ) as $p ) { // phpcs:ignore
			if ( 'selftest' === (string) $p->kind || ( ! (int) $p->booking_id && 'topup' !== (string) $p->kind ) || CMB_Payments::is_selftest_reason( $p->refund_reason ) ) {
				$t['tests']++;
				continue;
			}

			$t['gatewayIn'] += (int) ( $p->amount_rial / 10 );
			$t['fees']      += (int) ( $p->fee / 10 );
		}

		// ۳. دفتر کیف پول
		$where = "w.status = 'done'";

		if ( $since ) {
			$where .= $wpdb->prepare( ' AND w.created_at >= %s', $since );
		}

		if ( ! $with_sandbox ) {
			$where .= ' AND ( p.id IS NULL OR p.sandbox = 0 )';
		}

		$ledger = $wpdb->get_results(
			"SELECT w.type, w.reason, COUNT(*) AS n, COALESCE(SUM(w.amount),0) AS total
			 FROM {$wt} w LEFT JOIN {$pt} p ON p.id = w.payment_id
			 WHERE {$where} GROUP BY w.type, w.reason" // phpcs:ignore
		);

		$reasons = array();
		$labels  = CMB_Payments::refund_reasons();

		foreach ( (array) $ledger as $l ) {
			$sum = (int) $l->total;
			$n   = (int) $l->n;

			switch ( (string) $l->type ) {
				case 'refund':
					$t['toWallet']      += $sum;
					$t['toWalletCount'] += $n;

					$r = (string) $l->reason;

					if ( ! isset( $reasons[ $r ] ) ) {
						$reasons[ $r ] = array(
							'label' => isset( $labels[ $r ] ) ? $labels[ $r ] : $r,
							'count' => 0,
							'sum'   => 0,
						);
					}

					$reasons[ $r ]['count'] += $n;
					$reasons[ $r ]['sum']   += $sum;
					break;
				case 'topup':
					$t['topup']      += $sum;
					$t['topupCount'] += $n;
					break;
				case 'pay':
					$t['spent'] += -$sum;
					break;
				case 'reclaim':
					$t['reclaimed'] += -$sum;
					break;
				case 'adjust':
					$t['adjust']      += $sum;
					$t['adjustCount'] += $n;
					break;
			}
		}

		$now = CMB_Wallet::totals();

		$t['liability'] = (int) $now['liability'];
		$t['wallets']   = (int) $now['wallets'];

		$counts = array( 'paidCount', 'toWalletCount', 'topupCount', 'adjustCount', 'tests', 'wallets' );
		$fa     = array();

		foreach ( $t as $k => $v ) {
			$fa[ $k . 'Fa' ] = in_array( $k, $counts, true ) ? cmb_fa_num( $v ) : ( $v < 0 ? '−' . cmb_toman( -$v ) : cmb_toman( $v ) );
		}

		$fmt = function ( array $list ) {
			return array_values(
				array_map(
					function ( $x ) {
						$x['countFa'] = cmb_fa_num( $x['count'] );

						foreach ( array( 'sum', 'paid', 'refunded', 'kept' ) as $k ) {
							if ( isset( $x[ $k ] ) ) {
								$x[ $k . 'Fa' ] = cmb_toman( $x[ $k ] );
							}
						}

						return $x;
					},
					$list
				)
			);
		};

		return array(
			'days'     => (int) $days,
			'sandbox'  => (bool) $with_sandbox,
			'totals'   => $t + $fa,
			'reasons'  => $fmt( $reasons ),
			'services' => $fmt( $services ),
		);
	}
}
