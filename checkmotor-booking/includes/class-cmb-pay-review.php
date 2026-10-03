<?php
/**
 * بررسی بیعانه و برگشت: جدول خدمات، شبیه‌ساز سناریو، گزارش.
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
		$auto    = CMB_Payments::auto_on();
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
			$global[] = array( 'info', 'درگاه آزمایشی روشن است: پرداخت‌ها و برگشت‌ها شبیه‌سازی می‌شوند و پولی جابه‌جا نمی‌شود.' );
		}

		if ( ! $auto ) {
			$global[] = array( 'warn', 'برگشت پول دستی است؛ هر برگشت در صف می‌ماند تا از پنل زرین‌پال انجامش دهید.' );
		} elseif ( ! CMB_Payments::sandbox() && ! CMB_Zarinpal_Refund::configured() ) {
			$global[] = array( 'bad', 'برگشت خودکار روشن است ولی توکن دسترسی یا شماره‌ی ترمینال زرین‌پال تنظیم نشده؛ برگشت‌ها شکست می‌خورند.' );
		}

		return array(
			'settings' => array(
				'enabled'   => $enabled,
				'sandbox'   => CMB_Payments::sandbox(),
				'auto'      => $auto,
				'delay'     => CMB_Payments::refund_delay(),
				'method'    => CMB_Payments::refund_method(),
				'methodFa'  => CMB_Payments::method_label( CMB_Payments::refund_method() ),
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

		if ( $refund > 0 && $refund < 2000 ) {
			$warn[] = array( 'bad', 'برگشتیِ لغو کمتر از ۲,۰۰۰ تومان است و زرین‌پال آن را برنمی‌گرداند.' );
		}

		if ( $shop > 0 && $shop < 2000 ) {
			$warn[] = array( 'bad', 'برگشتیِ لغو از طرف مجموعه کمتر از ۲,۰۰۰ تومان می‌شود؛ درصد را در تنظیمات عوض کنید.' );
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
		$auto   = CMB_Payments::auto_on();
		$method = CMB_Payments::method_label( CMB_Payments::refund_method() );
		$delay  = CMB_Payments::refund_delay();

		$how = function ( $amount, $reason ) use ( $auto, $method, $delay ) {
			if ( $amount < 1 ) {
				return array( 'none', 'برگشتی ندارد' );
			}

			if ( ! $auto ) {
				return array( 'manual', 'در صف «بازگشت وجه» می‌نشیند تا از پنل زرین‌پال برگردانید و «ثبت انجام‌شده» بزنید.' );
			}

			$wait = in_array( $reason, CMB_Payments::DELAYED_REASONS, true ) ? $delay : 0;

			return array(
				'auto',
				( $wait ? cmb_fa_num( $wait ) . ' دقیقه بعد از لغو' : 'بلافاصله' ) . '، خودکار با ' . $method
					. ( $wait ? '. تا آن موقع با «بازگردانی» نوبت می‌شود جلویش را گرفت.' : '.' ),
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

		$eta = function ( $reason ) {
			return CMB_Payments::refund_eta( $reason );
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
				$refund ? 'نوبت شما لغو شد. ' . cmb_toman( $refund ) . ' ' . $eta( 'customer' ) . '.' : 'نوبت شما لغو شد. طبق قوانین رزرو، مبلغی از بیعانه بازگردانده نمی‌شود.'
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
				'پیش‌فرض از درصد تنظیمات است و هنگام لغو در پنل قابل تغییر است (۰، یا دست‌کم ۲,۰۰۰ تومان).',
				'پیامک لغو برای مشتری می‌رود؛ با انجام برگشت، پیامک «بازگردانده شد».'
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
				'پرداخت شما رسید ولی ظرفیت آن زمان دیگر خالی نبود؛ کل مبلغ ' . $eta( 'slot_gone' ) . '.'
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
	 * گزارش پرداخت‌ها و برگشت‌ها در یک بازه (بر اساس زمان پرداخت).
	 *
	 * @param int  $days        چند روز اخیر؛ ۰ یعنی همه.
	 * @param bool $with_sandbox پرداخت‌های آزمایشی هم حساب شوند؟
	 */
	public static function report( $days = 30, $with_sandbox = false ) {
		global $wpdb;

		$empty = array(
			'days'    => (int) $days,
			'totals'  => array(),
			'reasons' => array(),
			'methods' => array(),
			'services' => array(),
		);

		if ( ! CMB_Payments::schema_ready() ) {
			return $empty;
		}

		$pt    = cmb_table( 'payments' );
		$bt    = cmb_table( 'bookings' );
		$where = "p.status = 'paid'";

		if ( $days > 0 ) {
			$where .= $wpdb->prepare( ' AND p.paid_at >= %s', cmb_now()->modify( '-' . (int) $days . ' days' )->format( 'Y-m-d 00:00:00' ) );
		}

		if ( ! $with_sandbox ) {
			$where .= ' AND p.sandbox = 0';
		}

		$rows = $wpdb->get_results( "SELECT p.*, b.service_id FROM {$pt} p LEFT JOIN {$bt} b ON b.id = p.booking_id WHERE {$where}" ); // phpcs:ignore

		$t = array(
			'paidCount'     => 0,
			'paid'          => 0,
			'refunded'      => 0,
			'refundedCount' => 0,
			'pending'       => 0,
			'pendingCount'  => 0,
			'failed'        => 0,
			'failedCount'   => 0,
			'fees'          => 0,
			'tests'         => 0,
		);

		$reasons  = array();
		$methods  = array();
		$services = array();
		$labels   = CMB_Payments::refund_reasons();

		foreach ( (array) $rows as $p ) {
			$amount = (int) ( $p->amount_rial / 10 );
			$back   = (int) ( $p->refund_amount_rial / 10 );

			// آزمون ۲,۰۰۰ تومانی جزو درآمد نیست
			if ( 'selftest' === $p->refund_reason ) {
				$t['tests']++;
				continue;
			}

			$t['paidCount']++;
			$t['paid'] += $amount;
			$t['fees'] += (int) ( $p->fee / 10 );

			$sid = (int) $p->service_id;

			if ( ! isset( $services[ $sid ] ) ) {
				$svc              = CMB_Services::get_service( $sid );
				$services[ $sid ] = array(
					'title'    => $svc ? $svc->title : '—',
					'count'    => 0,
					'paid'     => 0,
					'refunded' => 0,
					'open'     => 0,
				);
			}

			$services[ $sid ]['count']++;
			$services[ $sid ]['paid'] += $amount;

			if ( $back < 1 ) {
				continue;
			}

			if ( 'done' === $p->refund_status ) {
				$t['refunded'] += $back;
				$t['refundedCount']++;
				$services[ $sid ]['refunded'] += $back;

				$m = CMB_Payments::done_label( (string) $p->refund_method );
				$m = '' !== $m ? $m : 'دستی';

				if ( ! isset( $methods[ $m ] ) ) {
					$methods[ $m ] = array( 'label' => $m, 'count' => 0, 'sum' => 0 );
				}

				$methods[ $m ]['count']++;
				$methods[ $m ]['sum'] += $back;
			} elseif ( 'failed' === $p->refund_status ) {
				$t['failed'] += $back;
				$t['failedCount']++;
				$services[ $sid ]['open'] += $back;
			} elseif ( in_array( $p->refund_status, array( 'due', 'processing' ), true ) ) {
				$t['pending'] += $back;
				$t['pendingCount']++;
				$services[ $sid ]['open'] += $back;
			}

			$r = (string) $p->refund_reason;

			if ( ! isset( $reasons[ $r ] ) ) {
				$reasons[ $r ] = array( 'label' => isset( $labels[ $r ] ) ? $labels[ $r ] : $r, 'count' => 0, 'sum' => 0 );
			}

			$reasons[ $r ]['count']++;
			$reasons[ $r ]['sum'] += $back;
		}

		$t['kept'] = $t['paid'] - $t['refunded'] - $t['pending'] - $t['failed'];

		$fa = array();

		foreach ( $t as $k => $v ) {
			$fa[ $k . 'Fa' ] = in_array( $k, array( 'paidCount', 'refundedCount', 'pendingCount', 'failedCount', 'tests' ), true ) ? cmb_fa_num( $v ) : cmb_toman( $v );
		}

		$fmt = function ( array $list ) {
			return array_values(
				array_map(
					function ( $x ) {
						$x['countFa'] = cmb_fa_num( $x['count'] );
						$x['sumFa']   = isset( $x['sum'] ) ? cmb_toman( $x['sum'] ) : '';

						foreach ( array( 'paid', 'refunded', 'open' ) as $k ) {
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
			'methods'  => $fmt( $methods ),
			'services' => $fmt( $services ),
		);
	}
}
