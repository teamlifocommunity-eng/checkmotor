<?php
/**
 * صفحه‌ی «راه‌اندازی زرین‌پال»: درگاه، بیعانه و کیف پول؛ هر کدام یک قدم
 * با وضعیت خودش (✅ ⚠️ ❌)، فیلدهایش و راهنمای «از کجای پنل زرین‌پال».
 *
 * نتیجه‌ی آزمون درگاه در option «cmb_pay_setup» می‌ماند، با اثرانگشت
 * مرچنت کد؛ اگر مرچنت کد عوض شود، نتیجه‌ی قبلی «کهنه» است.
 *
 * زرین‌پال برای برگشت وجه استفاده نمی‌شود: هر برگشت به کیف پول مشتری
 * در همین سایت می‌رود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMB_Pay_Setup {

	const OPTION = 'cmb_pay_setup';

	/** نتیجه‌هایی که نگه داشته می‌شوند. */
	const KEYS = array( 'merchant' );

	/* ------------------------------------------------------------------
	 * نتیجه‌ی آزمون‌ها
	 * --------------------------------------------------------------- */

	/** اثرانگشت تنظیماتی که نتیجه‌ی آزمون به آن بسته است. */
	public static function fingerprint( $key ) {
		return substr( md5( CMB_Payments::merchant() . '|' . ( CMB_Payments::sandbox() ? 1 : 0 ) ), 0, 10 );
	}

	public static function all() {
		$all = get_option( self::OPTION );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * ثبت نتیجه‌ی آزمون درگاه.
	 *
	 * @param string     $key  merchant
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
	 * سه قدم راه‌اندازی: درگاه، بیعانه، کیف پول.
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
			$s = array( 'bad', cmb_fa_num( $bad ) . ' خدمت مبلغ نامعتبر دارد (زرین‌پال نمی‌پذیرد). در پنل ← «بیعانه و کیف پول» ← «بررسی خدمات» اصلاحش کنید.' );
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

		$steps[] = self::wallet_step();

		foreach ( $steps as $i => $st ) {
			$steps[ $i ]['n'] = $i + 1;
		}

		return $steps;
	}

	/**
	 * قدم کیف پول: پیامک، شارژ، صف قدیمی، متن قوانین.
	 */
	protected static function wallet_step() {
		$todo   = array();
		$legacy = CMB_Payments::count_open_refunds();
		$mig    = get_option( 'cmb_wallet_migrated' );
		$wait   = is_array( $mig ) && ! empty( $mig['sms_pending'] ) ? count( $mig['sms_pending'] ) : 0;

		if ( ! CMB_Wallet::sms_ready() ) {
			$todo[] = 'پترن پیامک «تغییر کیف پول» تنظیم نشده؛ مشتری از برگشت پول به کیف پولش پیامک نمی‌گیرد' . ( $wait ? ' (پیامک ' . cmb_fa_num( $wait ) . ' مشتریِ صف قدیمی هم منتظر همین پترن است)' : '' ) . '.';
		}

		if ( $legacy ) {
			$todo[] = cmb_fa_num( $legacy ) . ' برگشت هنوز تعیین تکلیف نشده (پنل ← بیعانه و کیف پول ← برگشت‌های مانده).';
		}

		if ( CMB_Payments::terms_mention_card() ) {
			$todo[] = 'متن قوانینی که خودتان نوشته‌اید هنوز از برگشت به کارت می‌گوید (تنظیمات ← پرداخت بیعانه).';
		}

		$totals = CMB_Wallet::totals();

		list( $min, $max ) = CMB_Wallet::topup_limits();

		$info = 'برگشت‌ها همان لحظه به کیف پول مشتری می‌رود؛ شارژ کیف پول '
			. ( CMB_Wallet::topup_on() ? 'روشن (' . cmb_toman( $min ) . ' تا ' . cmb_toman( $max ) . ')' : 'خاموش' )
			. '؛ ' . cmb_fa_num( $totals['wallets'] ) . ' کیف پول با جمع موجودی ' . cmb_toman( $totals['liability'] ) . '.';

		if ( is_array( $mig ) && ! empty( $mig['count'] ) ) {
			$info .= ' صف قدیمی: ' . cmb_fa_num( $mig['count'] ) . ' برگشت (' . cmb_toman( $mig['amount'] ) . ') به کیف پول مشتری‌ها منتقل شد.';
		}

		return array(
			'key'    => 'wallet',
			'title'  => 'کیف پول مشتری',
			'status' => $todo ? 'warn' : 'ok',
			'text'   => $todo ? implode( ' ', $todo ) . ' — ' . $info : $info,
			'fix'    => '',
			'at'     => 0,
		);
	}

	/** راه‌حل کوتاه برای خطاهای رایج راه‌اندازی. */
	protected static function fix_for( $r ) {
		if ( ! $r || $r['stale'] || $r['ok'] ) {
			return '';
		}

		$map = array(
			'-10' => 'مرچنت کد را از پنل زرین‌پال دوباره کپی کنید. اگر در تنظیمات درگاه محدودیت آی‌پی گذاشته‌اید، آی‌پی سرور سایت را هم اضافه کنید.',
			'-11' => 'درگاه در زرین‌پال فعال نیست؛ وضعیت درگاه را در پنل زرین‌پال ببینید یا به پشتیبانی تیکت بدهید.',
			'-18' => 'دامنه‌ی ثبت‌شده‌ی درگاه در زرین‌پال باید ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' باشد.',
		);

		return isset( $map[ (string) $r['code'] ] ) ? $map[ (string) $r['code'] ] : '';
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
			$text = 'همه‌چیز آماده است؛ بیعانه با درگاه و کیف پول پرداخت می‌شود و برگشت‌ها به کیف پول مشتری می‌رود.';
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

	/** برای یادآوری در پنل («بیعانه و کیف پول»). */
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

		$num = function ( $key, $min, $max ) {
			return max( $min, min( $max, (int) cmb_en_num( (string) wp_unslash( $_POST[ $key ] ?? '0' ) ) ) );
		};

		$changes = array();

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

			case 'wallet':
				$changes = array(
					'wallet_topup'     => isset( $_POST['wallet_topup'] ) ? 1 : 0,
					'wallet_topup_min' => $num( 'wallet_topup_min', 1000, 100000000 ),
					'wallet_topup_max' => $num( 'wallet_topup_max', 1000, 100000000 ),
					'pattern_wallet'   => sanitize_text_field( wp_unslash( $_POST['pattern_wallet'] ?? '' ) ),
				);

				$changes['wallet_topup_max'] = max( $changes['wallet_topup_min'], $changes['wallet_topup_max'] );
				break;

			default:
				self::back( '', 'قدم نامعتبر.', 'error' );
		}
		// phpcs:enable

		$ok = CMB_Admin::check_pay( array_merge( CMB_Settings::all(), $changes ) );

		if ( is_wp_error( $ok ) ) {
			self::back( 'step-' . $step, $ok->get_error_message(), 'error' );
		}

		// ذخیره‌ی تنظیمات پیامکِ انتقال صف قدیمی را هم می‌فرستد (checkmotor-booking.php)
		CMB_Settings::update( $changes );

		if ( 'test' === $do && 'gateway' === $step ) {
			$r = self::test_gateway();
			self::back( 'step-gateway', $r[1], $r[0] ? 'success' : 'error' );
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
		$type    = isset( $_GET['cmb_type'] ) && in_array( $_GET['cmb_type'], array( 'error', 'warning' ), true ) ? sanitize_key( $_GET['cmb_type'] ) : 'success';
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
					هر قدم جدا ذخیره می‌شود و وضعیتش همین‌جا می‌ماند. برای امتحان بی‌پول، در قدم ۱ «درگاه آزمایشی» را روشن کنید.
					زرین‌پال فقط برای دریافت پول است؛ برگشت به کارت در کار نیست: هر برگشت به کیف پول مشتری در همین سایت می‌رود
					و مشتری با آن بیعانه‌ی نوبت بعدی را می‌پردازد. همه‌ی این تنظیم‌ها در «تنظیمات ← پرداخت بیعانه» هم هستند.
				</p>
				<p class="cmb-su-chips">
					<span><?php echo CMB_Payments::sandbox() ? 'درگاه آزمایشی' : 'درگاه واقعی'; ?></span>
					<span><?php echo CMB_Payments::enabled() ? 'پرداخت بیعانه روشن' : 'پرداخت بیعانه خاموش'; ?></span>
					<span>برگشت به کیف پول مشتری</span>
					<span><?php echo CMB_Wallet::topup_on() ? 'شارژ کیف پول روشن' : 'شارژ کیف پول خاموش'; ?></span>
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
				'در پنل زرین‌پال درگاه همین سایت را باز کنید و به «تنظیمات درگاه» ← «مشخصات درگاه» بروید.',
				'«مرچنت کد» (۳۶ نویسه به شکل xxxxxxxx-xxxx-…) را کپی کنید و اینجا بچسبانید.',
				'دامنه‌ی ثبت‌شده‌ی درگاه باید ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' باشد؛ وگرنه زرین‌پال خطای ‎-18 می‌دهد.',
				'«خدمات‌دهندگان پرداخت» (سامان، ملت، سپهر، …) فقط بانک‌هایی است که زرین‌پال پرداخت را از آن‌ها رد می‌کند؛ این‌جا تنظیمی برایشان لازم نیست.',
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
				بیعانه ۰ یا دست‌کم ۱,۰۰۰ تومان (حداقل زرین‌پال). برگشتی هر مبلغی از ۰ تا خود بیعانه است و همان لحظه به کیف پول مشتری می‌رود؛
				حالا که پول در مجموعه می‌ماند، می‌شود برگشتیِ لغو را بیشتر کرد. مبلغ هر خدمت جدا و شبیه‌ساز «چه کسی چقدر، کِی»:
				<a href="<?php echo esc_url( cmb_app_url( 'panel/refunds' ) ); ?>" target="_blank" rel="noopener">پنل ← بیعانه و کیف پول ← بررسی خدمات</a>.
			</p>
		</form>
		<?php
	}

	protected static function step_wallet() {
		$s   = CMB_Settings::all();
		$mig = get_option( 'cmb_wallet_migrated' );

		self::guide(
			array(
				'هر برگشت — لغو به‌موقع مشتری (همان مبلغ تعیین‌شده‌ی هر خدمت)، لغو از طرف مجموعه، پرداخت تکراری، پرداختی که دیر رسید — همان لحظه به کیف پول مشتری در همین سایت واریز می‌شود و پیامک می‌گیرد.',
				'مشتری در اپ، تب «کیف پول»، موجودی و تاریخچه را می‌بیند و در قدم پرداخت بیعانه‌ی نوبت بعدی، موجودی خودکار کم می‌شود (اگر کافی نبود، باقی‌مانده با درگاه).',
				'موجودی قابل برداشت یا انتقال به کارت نیست و فقط برای بیعانه‌ی نوبت‌های آنلاین همین سایت خرج می‌شود؛ این در متن قوانینی که مشتری پیش از پرداخت می‌پذیرد آمده است.',
				'مدیر در پنل ← «بیعانه و کیف پول» موجودی همه، تاریخچه‌ی هر مشتری و افزایش یا کاهش دستی (با توضیح) دارد.',
				'در ملی‌پیامک یک پترن با چهار متغیر بسازید و شناسه‌اش را این‌جا بگذارید. متن پیشنهادی: ' . str_replace( "\n", ' ⏎ ', CMB_Wallet::pattern_hint() ),
			)
		);

		if ( is_array( $mig ) && ( ! empty( $mig['count'] ) || ! empty( $mig['left'] ) ) ) {
			$wait = ! empty( $mig['sms_pending'] ) ? count( $mig['sms_pending'] ) : 0;
			?>
			<p class="cmb-su__ip">
				<b>انتقال صف قدیمی:</b>
				<?php echo esc_html( cmb_fa_num( (int) $mig['count'] ) . ' برگشت به جمع ' . cmb_toman( (int) $mig['amount'] ) . ' به کیف پول مشتری‌ها رفت' . ( $wait ? '؛ پیامک ' . cmb_fa_num( $wait ) . ' نفرشان با ذخیره‌ی پترن همین‌جا فرستاده می‌شود.' : ( ! empty( $mig['sms_sent'] ) ? '؛ پیامکشان رفت.' : '.' ) ) ); ?>
			</p>
			<?php
		}

		self::form_open( 'wallet' );
		?>
			<p><label><input type="checkbox" name="wallet_topup" value="1" <?php checked( (int) $s['wallet_topup'], 1 ); ?> /> مشتری بتواند کیف پولش را با درگاه شارژ کند</label></p>
			<p>
				<label>از <input type="number" name="wallet_topup_min" min="1000" step="1000" class="small-text" style="width:120px" value="<?php echo esc_attr( $s['wallet_topup_min'] ); ?>" /></label>
				<label>تا <input type="number" name="wallet_topup_max" min="1000" step="1000" class="small-text" style="width:140px" value="<?php echo esc_attr( $s['wallet_topup_max'] ); ?>" /> تومان در هر شارژ</label>
			</p>
			<p>
				<label>شناسه‌ی پترن «تغییر کیف پول» در ملی‌پیامک<br>
				<input type="text" name="pattern_wallet" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['pattern_wallet'] ); ?>" /></label>
				<br><span class="description">متغیرها: {0} نام، {1} مبلغ، {2} موجودی بعد از تغییر، {3} شرح (مثلاً «بابت لغو نوبت CM… به کیف پول شما برگشت»).</span>
			</p>
			<p class="cmb-su__btns"><button type="submit" name="do" value="save" class="button button-primary">ذخیره</button></p>
		</form>
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
			.cmb-su__ip{margin:8px 0;padding:6px 10px;background:#f0f6fc;border-radius:6px;line-height:1.9}
			@media (max-width:600px){
				.cmb-su{padding:10px 12px}
				.cmb-su-wrap .regular-text,.cmb-su-wrap .large-text{width:100%}
			}
		</style>
		<?php
	}
}
