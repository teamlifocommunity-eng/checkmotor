<?php
/**
 * قالب «نوبت‌های من».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_user_logged_in() ) {
	echo '<div class="cmb-wrap" dir="rtl"><div class="cmb-notice">برای مشاهده‌ی نوبت‌های خود ابتدا وارد شوید.</div>';
	echo do_shortcode( '[checkmotor_login]' );
	echo '</div>';

	return;
}

$cmb_user_id  = get_current_user_id();
$cmb_bookings = CMB_Bookings::get_user_bookings( $cmb_user_id, 30 );
$cmb_branch   = CMB_Services::get_branch();
?>
<div class="cmb-wrap" dir="rtl">
	<h3 class="cmb-panel__title">نوبت‌های من</h3>

	<?php if ( empty( $cmb_bookings ) ) : ?>
		<div class="cmb-notice">هنوز نوبتی ثبت نکرده‌اید.
			<a href="<?php echo esc_url( CMB_Shortcodes::booking_url() ); ?>">رزرو نوبت</a>
		</div>
	<?php else : ?>
		<div class="cmb-bookings">
			<?php foreach ( $cmb_bookings as $cmb_booking ) : ?>
				<?php $cmb_data = CMB_Bookings::to_array( $cmb_booking ); ?>
				<article class="cmb-booking cmb-booking--<?php echo esc_attr( $cmb_data['status'] ); ?>">
					<header>
						<h4><?php echo esc_html( $cmb_data['service'] ); ?></h4>
						<span class="cmb-badge cmb-badge--<?php echo esc_attr( $cmb_data['status'] ); ?>"><?php echo esc_html( $cmb_data['statusLabel'] ); ?></span>
					</header>

					<ul>
						<li>📅 <?php echo esc_html( $cmb_data['dateFa'] ); ?></li>
						<li>⏰ <?php echo esc_html( $cmb_data['blockLabel'] ); ?> — ساعت <?php echo esc_html( $cmb_data['blockStart'] ); ?></li>
						<li>🚗 <?php echo esc_html( $cmb_data['car'] ); ?></li>
						<li>🔖 کد پیگیری: <b><?php echo esc_html( $cmb_data['code'] ); ?></b></li>
					</ul>
				</article>
			<?php endforeach; ?>
		</div>

		<p class="cmb-note">برای لغو یا جابجایی نوبت، لطفاً با شعبه تماس بگیرید
			<?php if ( $cmb_branch && $cmb_branch->phone ) : ?>
				<a href="tel:<?php echo esc_attr( $cmb_branch->phone ); ?>"><?php echo esc_html( cmb_fa_num( $cmb_branch->phone ) ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
