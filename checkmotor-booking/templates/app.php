<?php
/**
 * قالب تمام‌صفحه‌ی اپ مشتری — مسیر /{slug}/
 *
 * این قالب عمداً از قالب سایت استفاده نمی‌کند: اپ رزرو یک دیزاین‌سیستم
 * بسته دارد و باید روی هر قالبی یکسان دیده شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

cmb_enqueue_app();

/* صفحه‌ی اپ نباید کش شود: nonce داخلش برای همان کاربر و همان نشست است.
   اگر افزونه‌ی کشی این صفحه را ذخیره کند، کاربر بعدی nonce غریبه می‌گیرد
   و هر درخواستی با «نشست منقضی شده» رد می‌شود. */
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
	define( 'DONOTCACHEOBJECT', true );
}
nocache_headers();

$cmb_branch = CMB_Services::get_branch();
$cmb_title  = $cmb_branch ? $cmb_branch->title : 'چک موتور';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl" class="cmb-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#13202B">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="چک موتور">
<meta name="robots" content="noindex,nofollow">
<title>رزرو نوبت — <?php echo esc_html( $cmb_title ); ?></title>

<link rel="preload" as="font" type="font/woff2" crossorigin
      href="<?php echo esc_url( CMB_URL . 'assets/fonts/IRANYekanX-Regular.woff2' ); ?>">
<link rel="preload" as="font" type="font/woff2" crossorigin
      href="<?php echo esc_url( CMB_URL . 'assets/fonts/IRANYekanX-Bold.woff2' ); ?>">

<?php
/* پوستر اولین خدمت بزرگ‌ترین عنصر صفحه است و دیرترین چیزی که
   می‌رسد. با preload هم‌زمان با خود صفحه شروع به دانلود می‌کند
   به‌جای اینکه منتظر اجرای جاوااسکریپت بماند. */
$cmb_boot  = function_exists( 'cmb_boot_payload' ) ? cmb_boot_payload() : array();
$cmb_first = ! empty( $cmb_boot['services'][0]['poster'] ) ? $cmb_boot['services'][0] : null;

if ( $cmb_first ) :
	?>
	<link rel="preload" as="image" fetchpriority="high"
		href="<?php echo esc_url( $cmb_first['poster'] ); ?>"
		<?php if ( ! empty( $cmb_first['posterSet'] ) ) : ?>
			imagesrcset="<?php echo esc_attr( $cmb_first['posterSet'] ); ?>"
			imagesizes="(max-width:460px) 100vw, 460px"
		<?php endif; ?>>
<?php endif; ?>

<?php wp_head(); ?>

<style>
	html, body { margin: 0; padding: 0; background: #F4F6F8; }
	#wpadminbar { display: none !important; }
	html { margin-top: 0 !important; }
	@keyframes cmbboot { to { transform: rotate(360deg) } }
</style>
</head>
<body <?php body_class( 'cmb-body' ); ?>>

<div id="cmb-app">
	<!-- اسکلت اولیه: تا اجرای اسکریپت، همان چیدمان واقعی دیده می‌شود
	     نه یک دایره‌ی چرخان. با boot معمولاً کسری از ثانیه دوام دارد. -->
	<div class="cmb-shell">
		<div class="cmb-hero">
			<div class="cmb-hero__row">
				<div class="cmb-hero__logo"></div>
				<div>
					<div class="cmb-hero__name"><?php echo esc_html( $cmb_title ); ?></div>
					<div class="cmb-hero__sub">رزرو نوبت آنلاین</div>
				</div>
			</div>
			<div class="cmb-hero__cta" style="opacity:.65">در حال آماده‌سازی…</div>
		</div>
		<div class="cmb-page">
			<div class="cmb-sec"><div class="cmb-sec__t">خدمات ما</div></div>
			<div class="cmb-sk" style="height:300px;border-radius:18px;margin-top:12px"></div>
			<div class="cmb-sk" style="height:300px;border-radius:18px;margin-top:12px"></div>
		</div>
	</div>
</div>

<noscript>
	<div style="max-width:460px;margin:40px auto;padding:20px;font-family:Tahoma,sans-serif;direction:rtl;text-align:center">
		<p>برای رزرو نوبت، جاوااسکریپت مرورگر باید فعال باشد.</p>
		<?php if ( $cmb_branch && $cmb_branch->phone ) : ?>
			<p>یا با شعبه تماس بگیرید: <a href="tel:<?php echo esc_attr( $cmb_branch->phone ); ?>"><?php echo esc_html( $cmb_branch->phone ); ?></a></p>
		<?php endif; ?>
	</div>
</noscript>

<?php wp_footer(); ?>
</body>
</html>
