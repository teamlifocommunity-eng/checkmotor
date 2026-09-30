<?php
/**
 * قالب فرم مستقل ورود با کد تایید پیامکی.
 *
 * @var array $atts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmb_redirect = ! empty( $atts['redirect'] ) ? esc_url_raw( $atts['redirect'] ) : '';

if ( is_user_logged_in() ) {
	$cmb_user = wp_get_current_user();
	?>
	<div class="cmb-wrap" dir="rtl">
		<div class="cmb-notice">
			شما با شماره <b><?php echo esc_html( cmb_fa_num( cmb_get_user_phone( $cmb_user->ID ) ) ); ?></b> وارد شده‌اید.
			<a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>">خروج</a>
		</div>
	</div>
	<?php
	return;
}
?>
<div class="cmb-wrap cmb-standalone-login" id="cmb-login" data-redirect="<?php echo esc_attr( $cmb_redirect ); ?>" dir="rtl">
	<h3 class="cmb-panel__title">ورود / ثبت‌نام با شماره موبایل</h3>

	<div class="cmb-alert" id="cmb-login-alert" role="alert" hidden></div>

	<div class="cmb-auth" id="cmb-login-auth">
		<div class="cmb-auth__phone" id="cmb-login-phone-step">
			<label class="cmb-field">
				<span>شماره موبایل <i>*</i></span>
				<input type="tel" id="cmb-login-phone" inputmode="numeric" autocomplete="tel" maxlength="15" placeholder="09123456789" />
			</label>
			<button type="button" class="cmb-btn cmb-btn--primary" id="cmb-login-send">ارسال کد تایید</button>
		</div>

		<div class="cmb-auth__code" id="cmb-login-code-step" hidden>
			<p class="cmb-auth__hint">کد ۵ رقمی ارسال‌شده به <b id="cmb-login-phone-label"></b> را وارد کنید.
				<button type="button" class="cmb-link" id="cmb-login-change">تغییر شماره</button>
			</p>

			<div class="cmb-otp-inputs" id="cmb-login-otp" dir="ltr">
				<input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" />
				<input type="text" inputmode="numeric" maxlength="1" />
				<input type="text" inputmode="numeric" maxlength="1" />
				<input type="text" inputmode="numeric" maxlength="1" />
				<input type="text" inputmode="numeric" maxlength="1" />
			</div>

			<label class="cmb-field cmb-field--name" id="cmb-login-name-field" hidden>
				<span>نام و نام خانوادگی <i>*</i></span>
				<input type="text" id="cmb-login-name" autocomplete="name" placeholder="مثلاً رضا محمدی" />
			</label>

			<button type="button" class="cmb-btn cmb-btn--primary" id="cmb-login-verify">تایید و ورود</button>
			<button type="button" class="cmb-link cmb-resend" id="cmb-login-resend" disabled></button>
		</div>
	</div>
</div>
