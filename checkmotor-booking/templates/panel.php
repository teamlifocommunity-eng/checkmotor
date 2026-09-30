<?php
/**
 * قالب داشبورد مدیریت — مسیر /{slug}/panel
 *
 * پنل دارایی‌های خودش را دارد و از قالب سایت مستقل است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

cmb_enqueue_panel();

if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
	define( 'DONOTCACHEOBJECT', true );
}
nocache_headers();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl" class="cmb-html">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#13202B">
<meta name="robots" content="noindex,nofollow">
<title>پنل مدیریت — چک موتور</title>

<?php
/* manifest و آیکون خود پنل، پیش از هر چیز دیگری. بدون این، «Add to
   Home Screen» روی آیفون manifest افزونه‌ی PWA سایت را برمی‌داشت و
   آیکون ساخته‌شده صفحه‌ی اپ اصلی را باز می‌کرد نه پنل را. */
CMB_Panel_Pwa::head_tags();
?>

<link rel="preload" as="font" type="font/woff2" crossorigin
      href="<?php echo esc_url( CMB_URL . 'assets/fonts/IRANYekanX-Regular.woff2' ); ?>">
<link rel="preload" as="font" type="font/woff2" crossorigin
      href="<?php echo esc_url( CMB_URL . 'assets/fonts/IRANYekanX-Bold.woff2' ); ?>">

<?php CMB_Panel_Pwa::wp_head(); /* همان wp_head، بدون manifest و تگ‌های نصب دیگران */ ?>

<style>
	html, body { margin: 0; padding: 0; background: #F4F6F8; }
	#wpadminbar { display: none !important; }
	html { margin-top: 0 !important; }
	@keyframes cmbboot { to { transform: rotate(360deg) } }
</style>
</head>
<body class="cmb-body cmb-panel-body">

<div id="cmb-panel">
	<div style="display:flex;align-items:center;justify-content:center;min-height:100svh;flex-direction:column;gap:14px">
		<div style="width:34px;height:34px;border:3px solid #E7EBEF;border-top-color:#FF6B2C;border-radius:50%;animation:cmbboot .6s linear infinite"></div>
		<p style="font-family:IRANYekanX,Tahoma,system-ui,sans-serif;color:#8B96A1;font-size:13px;margin:0">در حال بارگذاری پنل…</p>
	</div>
</div>

<?php wp_footer(); ?>
</body>
</html>
