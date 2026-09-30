<?php
/**
 * Plugin Name:        TeamLIFO PWA — نصب وب‌اپلیکیشن
 * Plugin URI:        https://teamlifo.ir
 * Description:        TeamLIFO PWA is a standalone and fully local plugin for converting a website into a installable web application (PWA). It includes manifest, Service Worker, icon, offline support, Persian install prompt and shortcode [pool_pwa_install]. It is available for any website.
 * Version:           1.0.0
 * Author:            TeamLIFO
 * Text Domain:       teamlifo-pwa
 * License:           GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'POOL_PWA_VER', '2.3.0' );
define( 'POOL_PWA_FILE', __FILE__ );
define( 'POOL_PWA_URL', plugin_dir_url( __FILE__ ) );
define( 'POOL_PWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'POOL_PWA_OPT', 'pool_pwa_settings' );

/* ─────────────────────────────────────────────────────────────────────────
 *  تنظیمات / مقادیر پیش‌فرض
 * ────────────────────────────────────────────────────────────────────────*/
function pool_pwa_defaults() {
	return array(
		'enabled'          => 1,
		'app_name'         => ( get_bloginfo( 'name' ) ?: 'وب‌اپلیکیشن' ),
		'short_name'       => 'اپلیکیشن',
		'description'      => 'نسخه‌ی اپلیکیشن این سایت',
		'theme_color'      => '#0AA0A2',
		'background_color' => '#ffffff',
		'display'          => 'standalone',
		'start_url'        => '/',
		'icon_192'         => '',
		'icon_512'         => '',
		'show_prompt'      => 1,
		'prompt_text'      => 'آیا مایل به نصب اپلیکیشن هستید؟',
		'prompt_pages'     => 'all',
		'offline_enabled'  => 1,
	);
}
function pool_pwa_get( $key ) {
	$o = wp_parse_args( (array) get_option( POOL_PWA_OPT, array() ), pool_pwa_defaults() );
	return isset( $o[ $key ] ) ? $o[ $key ] : null;
}
function pool_pwa_url( $what ) {
	return home_url( '/?pool_pwa=' . $what );
}
function pool_pwa_icon( $size ) {
	$custom = pool_pwa_get( $size === 512 ? 'icon_512' : 'icon_192' );
	if ( $custom ) { return $custom; }
	// آیکون پیش‌فرض محلی (فایل PNG داخل افزونه)
	return POOL_PWA_URL . ( $size === 512 ? 'icon-512.png' : 'icon-192.png' );
}

/* ─────────────────────────────────────────────────────────────────────────
 *  فعال‌سازی
 * ────────────────────────────────────────────────────────────────────────*/
register_activation_hook( __FILE__, function () {
	if ( get_option( POOL_PWA_OPT ) === false ) {
		add_option( POOL_PWA_OPT, pool_pwa_defaults() );
	}
} );

/* ─────────────────────────────────────────────────────────────────────────
 *  سرو فایل‌های پویا (manifest / sw / offline / icon) — روی همه‌ی سرورها
 *  از طریق query-string، خیلی زود، مستقل از permalink و rewrite.
 * ────────────────────────────────────────────────────────────────────────*/
add_action( 'init', 'pool_pwa_maybe_serve', 0 );
function pool_pwa_maybe_serve() {
	if ( ! isset( $_GET['pool_pwa'] ) ) { return; }
	$what = sanitize_key( $_GET['pool_pwa'] );

	// تمیزکردن هر بافر خروجی قبلی
	while ( ob_get_level() > 0 ) { @ob_end_clean(); }

	if ( $what === 'manifest' ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: public, max-age=3600' );
		}
		echo wp_json_encode( pool_pwa_manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	if ( $what === 'sw' ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		}
		echo pool_pwa_sw_js();
		exit;
	}

	if ( $what === 'offline' ) {
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		echo pool_pwa_offline_html();
		exit;
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 *  manifest
 * ────────────────────────────────────────────────────────────────────────*/
function pool_pwa_manifest() {
	$start = pool_pwa_get( 'start_url' ) ?: '/';
	return array(
		'id'               => $start,
		'name'             => pool_pwa_get( 'app_name' ),
		'short_name'       => pool_pwa_get( 'short_name' ),
		'description'      => pool_pwa_get( 'description' ),
		'lang'             => 'fa',
		'dir'              => 'rtl',
		'start_url'        => $start,
		'scope'            => '/',
		'display'          => pool_pwa_get( 'display' ) ?: 'standalone',
		'orientation'      => 'any',
		'theme_color'      => pool_pwa_get( 'theme_color' ),
		'background_color' => pool_pwa_get( 'background_color' ),
		'icons'            => array(
			array( 'src' => pool_pwa_icon( 192 ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
			array( 'src' => pool_pwa_icon( 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
			array( 'src' => POOL_PWA_URL . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
		),
	);
}

/* ─────────────────────────────────────────────────────────────────────────
 *  Service Worker
 * ────────────────────────────────────────────────────────────────────────*/
function pool_pwa_sw_js() {
	$cache   = 'pool-pwa-v' . POOL_PWA_VER;
	$offline = pool_pwa_url( 'offline' );
	$offon   = pool_pwa_get( 'offline_enabled' ) ? 'true' : 'false';
	ob_start(); ?>
/* Pool PWA SW <?php echo esc_js( POOL_PWA_VER ); ?> */
const CACHE = '<?php echo esc_js( $cache ); ?>';
const OFFLINE_URL = '<?php echo esc_js( $offline ); ?>';
const OFFLINE_ENABLED = <?php echo $offon; ?>;

function excluded(url){
  const p = url.pathname;
  if (p.indexOf('/wp-admin')!==-1 || p.indexOf('/wp-login')!==-1 || p.indexOf('/wp-json')!==-1 ||
      p.indexOf('/wp-cron')!==-1 || p.indexOf('/xmlrpc')!==-1 || p.indexOf('/cart')!==-1 ||
      p.indexOf('/checkout')!==-1 || p.indexOf('/my-account')!==-1) return true;
  if (url.search && url.search.length) return true; // پرداخت/nonce/فرم‌ها
  return false;
}

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then((c) => c.add(OFFLINE_URL).catch(()=>{})));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks)=>Promise.all(ks.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));
});
self.addEventListener('message', (e) => { if (e.data === 'skipWaiting') self.skipWaiting(); });
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (excluded(url)) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(()=> OFFLINE_ENABLED ? caches.match(OFFLINE_URL) : Response.error()));
    return;
  }
  if (/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|woff2?|ttf|ico)$/i.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then((c)=>c.match(req).then((hit)=>{
      const net = fetch(req).then((res)=>{ if(res&&res.status===200&&res.type==='basic') c.put(req,res.clone()); return res; }).catch(()=>hit);
      return hit || net;
    })));
  }
});
<?php
	return ob_get_clean();
}

/* ─────────────────────────────────────────────────────────────────────────
 *  صفحه‌ی آفلاین
 * ────────────────────────────────────────────────────────────────────────*/
function pool_pwa_offline_html() {
	$name  = esc_html( pool_pwa_get( 'app_name' ) );
	$color = esc_attr( pool_pwa_get( 'theme_color' ) );
	return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1"><title>آفلاین</title><style>'
		. 'body{margin:0;font-family:Tahoma,sans-serif;background:#f6f8f9;color:#1f2d2d;display:flex;'
		. 'min-height:100vh;align-items:center;justify-content:center;text-align:center}.b{padding:30px;max-width:360px}'
		. 'h1{color:' . $color . ';font-size:21px}p{color:#5b6b6b;line-height:2}button{margin-top:18px;background:'
		. $color . ';color:#fff;border:0;border-radius:10px;padding:12px 26px;font:inherit;font-size:15px;cursor:pointer}</style></head>'
		. '<body><div class="b"><div style="font-size:52px">📶</div><h1>اتصال اینترنت برقرار نیست</h1>'
		. '<p>لطفاً اتصال خود را بررسی کنید و دوباره تلاش کنید.</p>'
		. '<button onclick="location.reload()">تلاش مجدد</button></div></body></html>';
}

/* ─────────────────────────────────────────────────────────────────────────
 *  خروجی فرانت‌اند: متاتگ‌ها + اسکریپت/استایل + شورت‌کد
 * ────────────────────────────────────────────────────────────────────────*/
add_action( 'init', function () {
	if ( ! pool_pwa_get( 'enabled' ) ) { return; }
	add_shortcode( 'pool_pwa_install', 'pool_pwa_shortcode' );
}, 5 );

add_action( 'wp_head', function () {
	if ( ! pool_pwa_get( 'enabled' ) || is_admin() ) { return; }
	$theme = esc_attr( pool_pwa_get( 'theme_color' ) );
	echo "\n<!-- Pool PWA " . esc_html( POOL_PWA_VER ) . " -->\n";
	echo '<link rel="manifest" href="' . esc_url( pool_pwa_url( 'manifest' ) ) . '">' . "\n";
	echo '<meta name="theme-color" content="' . $theme . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( pool_pwa_get( 'short_name' ) ) . '">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . esc_url( pool_pwa_icon( 192 ) ) . '">' . "\n";
}, 1 );

add_action( 'wp_footer', function () {
	if ( ! pool_pwa_get( 'enabled' ) || is_admin() ) { return; }
	$cfg = array(
		'swUrl'       => pool_pwa_url( 'sw' ),
		'showPrompt'  => (bool) pool_pwa_get( 'show_prompt' ),
		'promptText'  => pool_pwa_get( 'prompt_text' ),
		'promptPages' => pool_pwa_get( 'prompt_pages' ),
		'isHome'      => ( is_front_page() || is_home() ),
		'appName'     => pool_pwa_get( 'app_name' ),
		'icon'        => pool_pwa_icon( 192 ),
	);
	echo '<style>' . pool_pwa_css() . '</style>';
	echo '<script>window.PoolPWA=' . wp_json_encode( $cfg ) . ';</script>';
	echo '<script>' . pool_pwa_front_js() . '</script>';
} );

/* شورت‌کد دکمه/کارت نصب */
function pool_pwa_shortcode( $atts ) {
	$a = shortcode_atts( array(
		'text' => 'نصب وب‌اپلیکیشن',
		'full' => '0',
		'card' => '0',
	), $atts, 'pool_pwa_install' );

	$icon = esc_url( pool_pwa_icon( 192 ) );
	$name = esc_html( pool_pwa_get( 'app_name' ) );
	$desc = esc_html( pool_pwa_get( 'description' ) );
	$txt  = esc_html( $a['text'] );
	$cls  = 'poolpwa-install-btn' . ( ( $a['full'] === '1' ) ? ' poolpwa-full' : '' );

	if ( $a['card'] === '1' ) {
		return '<div class="poolpwa-card poolpwa-hidden" dir="rtl">'
			. '<img src="' . $icon . '" class="poolpwa-card-icon" alt="">'
			. '<div class="poolpwa-card-body"><div class="poolpwa-card-name">' . $name . '</div>'
			. '<div class="poolpwa-card-desc">' . $desc . '</div></div>'
			. '<button type="button" class="' . esc_attr( $cls ) . '">' . $txt . '</button></div>';
	}
	return '<button type="button" class="' . esc_attr( $cls ) . ' poolpwa-hidden" dir="rtl">' . $txt . '</button>';
}

/* ─────────────────────────────────────────────────────────────────────────
 *  CSS و JS فرانت (درون‌خطی، بدون فایل جدا)
 * ────────────────────────────────────────────────────────────────────────*/
function pool_pwa_css() {
	return '.poolpwa-hidden{display:none !important}.poolpwa-install-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#0AA0A2;'
		. 'color:#fff;border:0;border-radius:12px;padding:13px 26px;font-size:15px;font-weight:700;cursor:pointer;'
		. 'font-family:inherit;box-shadow:0 4px 14px rgba(10,160,162,.28)}.poolpwa-install-btn::before{content:"\\2B07";font-size:16px}'
		. '.poolpwa-install-btn:hover{filter:brightness(.96)}.poolpwa-install-btn[disabled]{background:#94a3b8;box-shadow:none;cursor:default}'
		. '.poolpwa-full{width:100%}.poolpwa-card{display:flex;align-items:center;gap:14px;flex-direction:row-reverse;background:#fff;'
		. 'border:1px solid #e2e8f0;border-radius:16px;padding:16px;max-width:460px;font-family:inherit;box-shadow:0 4px 18px rgba(0,0,0,.06)}'
		. '.poolpwa-card-icon{width:56px;height:56px;border-radius:13px;flex-shrink:0}.poolpwa-card-body{flex:1;text-align:right}'
		. '.poolpwa-card-name{font-size:16px;font-weight:800;color:#133334}.poolpwa-card-desc{font-size:12.5px;color:#64748b;margin-top:3px}'
		. '.poolpwa-card .poolpwa-install-btn{padding:10px 18px;font-size:14px}'
		. '.poolpwa-banner{position:fixed;left:14px;right:14px;bottom:14px;z-index:99999;background:#fff;border:1px solid #e2e8f0;'
		. 'border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.18);padding:16px;max-width:440px;margin:0 auto;font-family:Tahoma,sans-serif;'
		. 'direction:rtl;transform:translateY(160%);opacity:0;transition:.35s}.poolpwa-banner.show{transform:translateY(0);opacity:1}'
		. '.poolpwa-row{display:flex;align-items:center;gap:12px;flex-direction:row-reverse;text-align:right}'
		. '.poolpwa-ic{width:48px;height:48px;border-radius:12px;flex-shrink:0}.poolpwa-tt{flex:1}.poolpwa-t1{font-size:15px;font-weight:800;color:#133334}'
		. '.poolpwa-t2{font-size:12.5px;color:#64748b;margin-top:2px}.poolpwa-act{display:flex;gap:8px;margin-top:13px;flex-direction:row-reverse}'
		. '.poolpwa-b{flex:1;height:42px;border:0;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;background:#0AA0A2;color:#fff}'
		. '.poolpwa-b.ghost{background:#f1f5f9;color:#475569}'
		. '.poolpwa-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;'
		. 'padding:20px;opacity:0;transition:.25s;font-family:Tahoma,sans-serif}.poolpwa-modal.show{opacity:1}'
		. '.poolpwa-mb{background:#fff;border-radius:18px;padding:26px 24px;max-width:360px;width:100%;text-align:center}'
		. '.poolpwa-mi{width:64px;height:64px;border-radius:15px;margin-bottom:12px}.poolpwa-mt{font-size:18px;font-weight:800;color:#133334;margin-bottom:12px}'
		. '.poolpwa-ms{font-size:14px;color:#4C6667;line-height:2;margin-bottom:20px}.poolpwa-mc{background:#0AA0A2;color:#fff;border:0;border-radius:10px;'
		. 'padding:11px 30px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}.poolpwa-share{font-weight:800;color:#0AA0A2;font-size:17px}'
		. '.poolpwa-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);z-index:100001;'
		. 'background:rgba(20,30,30,.92);color:#fff;font-family:Tahoma,sans-serif;font-size:13.5px;font-weight:600;'
		. 'padding:12px 20px;border-radius:24px;opacity:0;transition:.3s;max-width:90%;text-align:center;box-shadow:0 6px 20px rgba(0,0,0,.3)}'
		. '.poolpwa-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}';
}

function pool_pwa_front_js() {
	ob_start(); ?>
(function(){
  var cfg = window.PoolPWA || {};
  window.PoolPWA = cfg; cfg._deferred = null;
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && 'ontouchend' in document);
  var isSafari = /^((?!chrome|android|crios|fxios|edg).)*safari/i.test(ua);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  // فقط روی موبایل (اندروید/آیفون/هر گوشی) — روی دسکتاپ هیچ‌چیز نشان داده نشود
  var isMobile = /android|iphone|ipad|ipod|iemobile|blackberry|opera mini|mobile/i.test(ua)
                 || ( 'ontouchend' in document && Math.min(screen.width, screen.height) < 820 );
  cfg._isMobile = isMobile;

  // ثبت Service Worker
  if (cfg.swUrl && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register(cfg.swUrl, {scope:'/'}).then(function(reg){
      try{console.log('[Pool PWA] SW ثبت شد:', reg.scope);}catch(e){}
      reg.update();
    }).catch(function(err){ try{console.error('[Pool PWA] خطای ثبت SW:', err);}catch(e){} });
  }

  function dismissed(){ try{ var t=parseInt(localStorage.getItem('poolpwa_x'),10); return t && (Date.now()-t<7*864e5);}catch(e){return false;} }
  function setDismiss(){ try{localStorage.setItem('poolpwa_x',String(Date.now()));}catch(e){}}
  function rm(el){ el.classList.remove('show'); setTimeout(function(){el.remove();},300); }

  function instructions(){
    if(document.querySelector('.poolpwa-modal')) return;
    var steps = isIOS
      ? 'روی دکمه‌ی <strong>اشتراک‌گذاری</strong> <span class="poolpwa-share">&#x2191;</span> بزنید، سپس <strong>«Add to Home Screen»</strong> را انتخاب کنید.'
      : 'از منوی مرورگر (&#8942;)، گزینه‌ی <strong>«Install app»</strong> یا <strong>«Add to Home screen»</strong> را انتخاب کنید.';
    var icon = cfg.icon ? '<img src="'+cfg.icon+'" class="poolpwa-mi" alt="">' : '';
    var ov=document.createElement('div'); ov.className='poolpwa-modal'; ov.dir='rtl';
    ov.innerHTML='<div class="poolpwa-mb">'+icon+'<div class="poolpwa-mt">'+(cfg.appName||'نصب اپلیکیشن')+'</div><div class="poolpwa-ms">'+steps+'</div><button class="poolpwa-mc">متوجه شدم</button></div>';
    document.body.appendChild(ov); requestAnimationFrame(function(){ov.classList.add('show');});
    ov.addEventListener('click',function(e){ if(e.target===ov||e.target.classList.contains('poolpwa-mc')){ov.classList.remove('show');setTimeout(function(){ov.remove();},250);} });
  }

  window.poolPwaInstall = function(){
    if(cfg._deferred){ cfg._deferred.prompt(); cfg._deferred.userChoice.then(function(){cfg._deferred=null;}); }
    else { instructions(); }
  };

  function banner(native){
    if(document.querySelector('.poolpwa-banner')||standalone||dismissed()) return;
    var icon = cfg.icon ? '<img src="'+cfg.icon+'" class="poolpwa-ic" alt="">' : '';
    var w=document.createElement('div'); w.className='poolpwa-banner'; w.dir='rtl';
    w.innerHTML='<div class="poolpwa-row">'+icon+'<div class="poolpwa-tt"><div class="poolpwa-t1">'+(cfg.promptText||'نصب اپلیکیشن')+'</div><div class="poolpwa-t2">'+(cfg.appName||'')+'</div></div></div>'+
      '<div class="poolpwa-act"><button class="poolpwa-b go">'+(native?'نصب اپلیکیشن':'روش نصب')+'</button><button class="poolpwa-b ghost x">بعداً</button></div>';
    document.body.appendChild(w); requestAnimationFrame(function(){w.classList.add('show');});
    w.querySelector('.x').addEventListener('click',function(){setDismiss();rm(w);});
    w.querySelector('.go').addEventListener('click',function(){ window.poolPwaInstall(); if(!cfg._deferred) rm(w); });
  }

  // رویداد نصب بومی (اندروید/کروم/اج) — فقط موبایل
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault(); cfg._deferred = e;
    try{console.log('[Pool PWA] آماده‌ی نصب');}catch(_){}
    if(!isMobile) return;
    var b=document.querySelectorAll('.poolpwa-install-btn'); for(var i=0;i<b.length;i++) b[i].removeAttribute('disabled');
    if(cfg.showPrompt && (cfg.promptPages==='all'||cfg.isHome)) banner(true);
  });
  window.addEventListener('appinstalled', function(){
    cfg._deferred=null; setDismiss();
    var b=document.querySelector('.poolpwa-banner'); if(b) rm(b);
    var bs=document.querySelectorAll('.poolpwa-install-btn'); for(var i=0;i<bs.length;i++){bs[i].textContent='\u2713 نصب شد';bs[i].setAttribute('disabled','');}
  });

  // آی‌اواس/سافاری: بنر دستی — فقط موبایل
  if(isMobile && (isIOS||isSafari) && !standalone){
    setTimeout(function(){ if(!cfg._deferred && cfg.showPrompt && (cfg.promptPages==='all'||cfg.isHome)) banner(false); }, 2500);
  }

  // اتصال دکمه‌های شورت‌کد
  function wire(){
    var b=document.querySelectorAll('.poolpwa-install-btn');
    for(var i=0;i<b.length;i++){ (function(x){ if(x._w)return; x._w=1;
      if(!isMobile){ return; }   // روی دسکتاپ مخفی می‌ماند (کلاس poolpwa-hidden)
      // نمایش روی موبایل: حذف کلاس مخفی از دکمه و کارت والد
      x.classList.remove('poolpwa-hidden');
      var card = x.closest ? x.closest('.poolpwa-card') : null;
      if(card) card.classList.remove('poolpwa-hidden');
      if(standalone){x.textContent='\u2713 قبلاً نصب شده';x.setAttribute('disabled','');return;}
      x.addEventListener('click',function(e){e.preventDefault();window.poolPwaInstall();});
    })(b[i]); }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',wire); else wire();

  // ── مدیریت دکمه/حرکت back فقط در حالت وب‌اپلیکیشن (standalone) ──
  // قانون: هر جای سایت back زد → به صفحه/بخش قبلی برگردد.
  //        در ابتدای اپ (صفحه‌ی اصلی، بدون تاریخچه) → پیام «دوباره بزنید»، بار دوم خروج.
  if (standalone) {
    var POOL_EXIT_KEY = 'poolpwa_exit_armed';
    // یک ورودی نگهبان به تاریخچه اضافه می‌کنیم تا back اول، اپ را نبندد
    try { history.pushState({ poolpwa: 'guard' }, '', location.href); } catch (e) {}

    var exitArmed = false, exitTimer = null;
    function showExitToast() {
      if (document.querySelector('.poolpwa-toast')) return;
      var t = document.createElement('div');
      t.className = 'poolpwa-toast'; t.dir = 'rtl';
      t.textContent = 'برای خروج، دوباره دکمه‌ی بازگشت را بزنید';
      document.body.appendChild(t);
      requestAnimationFrame(function(){ t.classList.add('show'); });
      setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 300); }, 1900);
    }

    window.addEventListener('popstate', function () {
      // آیا واقعاً در ابتدای اپ هستیم؟ (صفحه‌ی اصلی سایت)
      var atHome = false;
      try {
        var p = location.pathname.replace(/\/+$/, '');
        atHome = ( p === '' || p === '/' );
      } catch (e) {}

      // اگر هنوز جایی برای برگشت در تاریخچه هست و در صفحه‌ی اصلی نیستیم،
      // مرورگر خودش به صفحه‌ی قبلی رفته است (رفتار طبیعی back). کاری نمی‌کنیم.
      if (!atHome) {
        // دوباره نگهبان را بگذار تا back بعدی هم مدیریت شود
        try { history.pushState({ poolpwa: 'guard' }, '', location.href); } catch (e) {}
        return;
      }

      // در صفحه‌ی اصلی: منطق «دوبار back برای خروج»
      if (exitArmed) {
        // بار دوم → اجازه بده اپ بسته شود (دیگر نگهبان نمی‌گذاریم)
        clearTimeout(exitTimer);
        exitArmed = false;
        history.back(); // خروج از اپ
        return;
      }
      // بار اول → نگهبان را برگردان و پیام بده
      exitArmed = true;
      try { history.pushState({ poolpwa: 'guard' }, '', location.href); } catch (e) {}
      showExitToast();
      exitTimer = setTimeout(function(){ exitArmed = false; }, 2000);
    });
  }
})();
<?php
	return ob_get_clean();
}

/* ─────────────────────────────────────────────────────────────────────────
 *  صفحه‌ی تنظیمات در پیشخوان
 * ────────────────────────────────────────────────────────────────────────*/
add_action( 'admin_menu', function () {
	add_menu_page( 'تنظیمات PWA', 'اپلیکیشن (PWA)', 'manage_options', 'pool-pwa', 'pool_pwa_settings_page', 'dashicons-smartphone', 81 );
} );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pool-pwa' ) ) . '">تنظیمات</a>' );
	return $links;
} );
add_action( 'admin_enqueue_scripts', function ( $h ) {
	if ( strpos( (string) $h, 'pool-pwa' ) !== false ) { wp_enqueue_media(); }
} );

add_action( 'admin_init', function () {
	if ( ! isset( $_POST['pool_pwa_nonce'] ) ) { return; }
	if ( ! wp_verify_nonce( $_POST['pool_pwa_nonce'], 'pool_pwa_save' ) || ! current_user_can( 'manage_options' ) ) { return; }
	$d = pool_pwa_defaults();
	$o = array(
		'enabled'          => isset( $_POST['enabled'] ) ? 1 : 0,
		'show_prompt'      => isset( $_POST['show_prompt'] ) ? 1 : 0,
		'offline_enabled'  => isset( $_POST['offline_enabled'] ) ? 1 : 0,
		'app_name'         => sanitize_text_field( wp_unslash( $_POST['app_name'] ?? $d['app_name'] ) ),
		'short_name'       => sanitize_text_field( wp_unslash( $_POST['short_name'] ?? $d['short_name'] ) ),
		'description'      => sanitize_text_field( wp_unslash( $_POST['description'] ?? $d['description'] ) ),
		'theme_color'      => sanitize_hex_color( $_POST['theme_color'] ?? $d['theme_color'] ) ?: $d['theme_color'],
		'background_color' => sanitize_hex_color( $_POST['background_color'] ?? $d['background_color'] ) ?: $d['background_color'],
		'display'          => in_array( $_POST['display'] ?? '', array( 'standalone', 'fullscreen', 'minimal-ui' ), true ) ? $_POST['display'] : 'standalone',
		'start_url'        => esc_url_raw( wp_unslash( $_POST['start_url'] ?? '/' ) ) ?: '/',
		'prompt_text'      => sanitize_text_field( wp_unslash( $_POST['prompt_text'] ?? $d['prompt_text'] ) ),
		'prompt_pages'     => ( ( $_POST['prompt_pages'] ?? 'all' ) === 'home' ) ? 'home' : 'all',
		'icon_192'         => esc_url_raw( wp_unslash( $_POST['icon_192'] ?? '' ) ),
		'icon_512'         => esc_url_raw( wp_unslash( $_POST['icon_512'] ?? '' ) ),
	);
	update_option( POOL_PWA_OPT, $o );
	add_settings_error( 'pool_pwa', 'saved', 'تنظیمات ذخیره شد ✓', 'updated' );
} );

function pool_pwa_settings_page() {
	$g = 'pool_pwa_get';
	$manifest_u = pool_pwa_url( 'manifest' );
	$sw_u       = pool_pwa_url( 'sw' );
	$https      = ( is_ssl() || strpos( home_url(), 'https://' ) === 0 );
	$dot = function ( $ok ) { return '<span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . ( $ok ? '#16a34a' : '#dc2626' ) . ';margin-left:8px;vertical-align:middle"></span>'; };
	?>
	<div class="wrap" dir="rtl" style="max-width:780px">
		<h1>تنظیمات اپلیکیشن (PWA) <span style="font-size:13px;color:#16a34a;font-weight:normal">نسخه <?php echo esc_html( POOL_PWA_VER ); ?> ✓ فعال</span></h1>
		<?php settings_errors( 'pool_pwa' ); ?>

		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:14px 0">
			<h2 style="margin:0 0 12px;font-size:16px">📋 وضعیت زنده</h2>
			<table style="width:100%;border-collapse:collapse;font-size:14px;line-height:2.2">
				<tr><td><?php echo $dot( (bool) $g( 'enabled' ) ); ?> فعال‌بودن</td><td style="text-align:left;color:#555"><?php echo $g( 'enabled' ) ? 'روشن' : 'خاموش'; ?></td></tr>
				<tr><td><?php echo $dot( $https ); ?> HTTPS</td><td style="text-align:left;color:#555"><?php echo $https ? 'دارد ✓' : 'ندارد (الزامی)'; ?></td></tr>
				<tr><td><span id="st-m"><?php echo $dot( false ); ?></span> دسترسی به manifest</td><td style="text-align:left;color:#555"><a id="st-mt" href="<?php echo esc_url( $manifest_u ); ?>" target="_blank">بررسی…</a></td></tr>
				<tr><td><span id="st-s"><?php echo $dot( false ); ?></span> دسترسی به Service Worker</td><td style="text-align:left;color:#555"><a id="st-st" href="<?php echo esc_url( $sw_u ); ?>" target="_blank">بررسی…</a></td></tr>
				<tr><td><span id="st-i"><?php echo $dot( false ); ?></span> دسترسی به آیکون</td><td style="text-align:left;color:#555"><a id="st-it" href="<?php echo esc_url( pool_pwa_icon( 192 ) ); ?>" target="_blank">بررسی…</a></td></tr>
				<tr><td><span id="st-sc"><?php echo $dot( false ); ?></span> شورت‌کد</td><td style="text-align:left;color:#555">برای تست در یک صفحه بنویسید: <code>[pool_pwa_install card="1"]</code></td></tr>
			</table>
			<p style="color:#777;font-size:12px;margin:12px 0 0">«ثبت SW در مرورگر» فقط در فرانت سایت اتفاق می‌افتد، نه اینجا. برای تست، صفحه‌ی اصلی سایت را در مرورگر باز کنید و کنسول (F12) را ببینید.</p>
		</div>
		<script>
		(function(){
			function d(id,ok){var e=document.getElementById(id);if(e)e.querySelector('span').style.background=ok?'#16a34a':'#dc2626';}
			fetch('<?php echo esc_js( $manifest_u ); ?>',{cache:'no-store'}).then(function(r){d('st-m',r.ok);document.getElementById('st-mt').textContent=r.ok?'فعال ✓':'خطا '+r.status;}).catch(function(){document.getElementById('st-mt').textContent='در دسترس نیست';});
			fetch('<?php echo esc_js( $sw_u ); ?>',{cache:'no-store'}).then(function(r){d('st-s',r.ok);document.getElementById('st-st').textContent=r.ok?'فعال ✓':'خطا '+r.status;}).catch(function(){document.getElementById('st-st').textContent='در دسترس نیست';});
			fetch('<?php echo esc_js( pool_pwa_icon( 192 ) ); ?>',{cache:'no-store'}).then(function(r){d('st-i',r.ok);document.getElementById('st-it').textContent=r.ok?'فعال ✓':'خطا '+r.status;}).catch(function(){document.getElementById('st-it').textContent='در دسترس نیست';});
			document.getElementById('st-sc').querySelector('span').style.background='#16a34a';
		})();
		</script>

		<form method="post">
			<?php wp_nonce_field( 'pool_pwa_save', 'pool_pwa_nonce' ); ?>
			<table class="form-table">
				<tr><th>فعال‌سازی</th><td><label><input type="checkbox" name="enabled" <?php checked( $g( 'enabled' ), 1 ); ?>> قابلیت اپلیکیشن فعال باشد</label></td></tr>
				<tr><th>نام اپلیکیشن</th><td><input type="text" name="app_name" value="<?php echo esc_attr( $g( 'app_name' ) ); ?>" class="regular-text"></td></tr>
				<tr><th>نام کوتاه</th><td><input type="text" name="short_name" value="<?php echo esc_attr( $g( 'short_name' ) ); ?>" class="regular-text"></td></tr>
				<tr><th>توضیح</th><td><input type="text" name="description" value="<?php echo esc_attr( $g( 'description' ) ); ?>" class="large-text"></td></tr>
				<tr><th>رنگ اصلی</th><td><input type="text" name="theme_color" value="<?php echo esc_attr( $g( 'theme_color' ) ); ?>" placeholder="#0AA0A2"></td></tr>
				<tr><th>رنگ پس‌زمینه</th><td><input type="text" name="background_color" value="<?php echo esc_attr( $g( 'background_color' ) ); ?>" placeholder="#ffffff"></td></tr>
				<tr><th>حالت نمایش</th><td><select name="display">
					<?php $dp = $g( 'display' ); ?>
					<option value="standalone" <?php selected( $dp, 'standalone' ); ?>>standalone</option>
					<option value="fullscreen" <?php selected( $dp, 'fullscreen' ); ?>>fullscreen</option>
					<option value="minimal-ui" <?php selected( $dp, 'minimal-ui' ); ?>>minimal-ui</option>
				</select></td></tr>
				<tr><th>آدرس شروع</th><td><input type="text" name="start_url" value="<?php echo esc_attr( $g( 'start_url' ) ); ?>" class="regular-text"></td></tr>
				<?php pool_pwa_icon_field( 'icon_192', 'آیکون ۱۹۲ (اختیاری)' ); ?>
				<?php pool_pwa_icon_field( 'icon_512', 'آیکون ۵۱۲ (اختیاری)' ); ?>
				<tr><th>نمایش پرامپت نصب</th><td><label><input type="checkbox" name="show_prompt" <?php checked( $g( 'show_prompt' ), 1 ); ?>> پرامپت نصب نمایش داده شود</label></td></tr>
				<tr><th>متن پرامپت</th><td><input type="text" name="prompt_text" value="<?php echo esc_attr( $g( 'prompt_text' ) ); ?>" class="large-text"></td></tr>
				<tr><th>نمایش در</th><td><select name="prompt_pages">
					<?php $pp = $g( 'prompt_pages' ); ?>
					<option value="all" <?php selected( $pp, 'all' ); ?>>همه‌ی صفحات</option>
					<option value="home" <?php selected( $pp, 'home' ); ?>>فقط صفحه‌ی اصلی</option>
				</select></td></tr>
				<tr><th>پشتیبانی آفلاین</th><td><label><input type="checkbox" name="offline_enabled" <?php checked( $g( 'offline_enabled' ), 1 ); ?>> صفحه‌ی آفلاین فعال باشد</label></td></tr>
			</table>
			<?php submit_button( 'ذخیره تنظیمات' ); ?>
		</form>
		<p style="color:#555">شورت‌کد دکمه‌ی نصب: <code>[pool_pwa_install]</code> — کارت کامل: <code>[pool_pwa_install card="1"]</code> — تمام‌عرض: <code>[pool_pwa_install full="1"]</code></p>
		<script>
		jQuery(function($){
			$('.ppwa-pick').on('click',function(e){e.preventDefault();var t=$(this),tg=t.data('t');var f=wp.media({title:'انتخاب آیکون',multiple:false,library:{type:'image'}});f.on('select',function(){var u=f.state().get('selection').first().toJSON().url;$('#'+tg).val(u);t.closest('td').find('img').attr('src',u).show();});f.open();});
			$('.ppwa-clr').on('click',function(e){e.preventDefault();var t=$(this);t.closest('td').find('input[type=hidden]').val('');t.closest('td').find('img').hide();});
		});
		</script>
	</div>
	<?php
}
function pool_pwa_icon_field( $key, $label ) {
	$v = pool_pwa_get( $key );
	?>
	<tr><th><?php echo esc_html( $label ); ?></th><td>
		<input type="hidden" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<img src="<?php echo esc_attr( $v ); ?>" style="width:56px;height:56px;border-radius:10px;border:1px solid #ddd;vertical-align:middle;<?php echo $v ? '' : 'display:none'; ?>">
		<button class="button ppwa-pick" data-t="<?php echo esc_attr( $key ); ?>">انتخاب</button>
		<button class="button ppwa-clr">حذف</button>
	</td></tr>
	<?php
}
