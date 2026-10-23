/* Shared Neshan location viewer/editor. The SDK is downloaded only on request. */
(function () {
'use strict';
var config = window.CMB_MAP || {};
var sdk = null, pending = null, instances = [], nextId = 0;
var keyFail = null; // پاسخ ردِ کلید از نشان: {code, msg}
var base = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/';
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function number(v) {
  var s = String(v == null ? '' : v).trim().replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
    .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[٫,]/g, '.');
  return s && isFinite(Number(s)) ? Number(s) : null;
}
function coords(lat, lng) {
  lat = number(lat); lng = number(lng);
  return lat !== null && lng !== null && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (lat !== 0 || lng !== 0) ? [lat, lng] : null;
}
function html(o) {
  o = o || {};
  // نمایش برای مشتری فقط با کلید نشان؛ بدون آن، دکمه‌ی «مسیریابی» کافی است
  if (!o.editable && (!coords(o.lat, o.lng) || !config.key)) { return ''; }
  var id = 'cmb-location-' + (++nextId);
  return '<section class="cmb-location" data-cmb-location data-lat="' + esc(o.lat) + '" data-lng="' + esc(o.lng) + '"' +
    ' data-lat-input="' + esc(o.latInput || '') + '" data-lng-input="' + esc(o.lngInput || '') + '" data-editable="' + (o.editable ? '1' : '0') + '" data-title="' + esc(o.title || '') + '"' +
    ' aria-label="' + esc(o.editable ? 'انتخاب موقعیت مرکز' : 'موقعیت ' + (o.title || 'مرکز')) + '">' +
    '<button type="button" class="cmb-location__toggle" data-cmb-map-open aria-expanded="false" aria-controls="' + id + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>' +
      (o.editable ? 'انتخاب موقعیت روی نقشهٔ نشان' : 'نمایش نقشهٔ نشان') + '</button>' +
    '<div class="cmb-location__content" id="' + id + '" hidden><div class="cmb-location__canvas" tabindex="0" role="region" aria-label="نقشهٔ نشان"></div>' +
      '<p class="cmb-location__status" role="status" aria-live="polite"></p>' +
      (o.editable ? '<p class="cmb-location__hint">روی محل مرکز بزنید یا نشانگر را جابه‌جا کنید؛ سپس فرم را ذخیره کنید. ورود دستی مختصات هم امکان‌پذیر است.</p>' : '') +
    '</div></section>';
}
/* SDK نشان پیش از نمایش، کلید را با یک درخواست PUT به /v1/quota می‌سنجد. اگر
   کلید رد شود (مثلاً کلید «سرویس‌ها» به‌جای «نقشهٔ وب»: خطای ۴۸۳)، همهٔ کاشی‌ها
   خاکستری با آرم نشان می‌شوند و کلیک روی آن نقطه‌ای بی‌معنا ثبت می‌کرد. پاسخ همان
   درخواست را می‌خوانیم (بی‌درخواست اضافه) و نقشه را با پیام روشن جایگزین می‌کنیم. */
var KEY_ERR = {
  480: 'کلید نقشه در نشان پیدا نشد یا نادرست وارد شده است.',
  481: 'سقف استفادهٔ این کلید نشان پر شده است.',
  482: 'درخواست‌ها به نشان از حد مجاز گذشته است؛ کمی بعد دوباره امتحان کنید.',
  483: 'این کلید از نوع «سرویس‌ها» است؛ نقشهٔ وب کلیدی از نوع «نقشهٔ وب» می‌خواهد.',
  484: 'دامنهٔ این سایت در «دامنه‌های مجاز» این کلید نشان ثبت نشده است.',
  485: 'سرویس نقشهٔ وب برای این کلید فعال نیست.'
};
function keyMessage(editable) {
  if (!editable) { return 'نمایش نقشه فعلاً در دسترس نیست. از آدرس و دکمهٔ «مسیریابی» استفاده کنید.'; }
  var c = keyFail.code, fix = ' در پنل نشان یک کلید از نوع «نقشهٔ وب» با دامنهٔ همین سایت بسازید و در تنظیمات چک موتور (بخش نمایندگی‌ها) بگذارید.';
  var why = !c ? 'ارتباط با نشان برای تأیید کلید نقشه برقرار نشد. اتصال اینترنت را بررسی کنید؛ اگر درست است، نوع کلید («نقشهٔ وب») و دامنه‌های مجاز آن را در پنل نشان ببینید.'
    : (KEY_ERR[c] || 'نشان کلید نقشه را نپذیرفت (کد ' + c + ').') + (c === 481 || c === 482 ? '' : fix);
  return why + ' تا آن موقع می‌توانید مختصات را دستی وارد کنید.';
}
function rejected(x) {
  var editable = x.host.dataset.editable === '1';
  instances = instances.filter(function (i) { return i !== x; });
  // نقطه‌ای که روی نقشهٔ خالی زده شده بود برمی‌گردد
  if (x.picked) {
    var a = document.getElementById(x.host.dataset.latInput), b = document.getElementById(x.host.dataset.lngInput);
    if (a && b) { a.value = x.orig[0]; b.value = x.orig[1]; a.dispatchEvent(new Event('input', {bubbles:true})); b.dispatchEvent(new Event('input', {bubbles:true})); }
  }
  x.map.remove();
  x.host.querySelector('.cmb-location__canvas').hidden = true;
  status(x.host, keyMessage(editable));
}
function watchKey() {
  if (watchKey.on || !window.XMLHttpRequest) { return; }
  watchKey.on = true;
  var open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url) {
    if (/^https:\/\/api\.neshan\.org\/v\d+\/quota/.test(String(url))) {
      var xhr = this;
      xhr.addEventListener('loadend', function () {
        if (xhr.status >= 200 && xhr.status < 300) { return; }
        var body = {};
        try { body = JSON.parse(xhr.responseText) || {}; } catch (e) { body = {}; }
        keyFail = { code: Number(body.code || xhr.status) || 0, msg: String(body.message || '') };
        instances.slice().forEach(rejected);
      });
    }
    return open.apply(this, arguments);
  };
}
function load() {
  if (sdk) { return Promise.resolve(sdk); }
  if (pending) { return pending; }
  pending = new Promise(function (resolve, reject) {
    var css = document.createElement('link'), script = document.createElement('script'), done = false;
    var timer = setTimeout(function () { finish(new Error('timeout')); }, 15000);
    function finish(err) {
      if (done) { return; } done = true; clearTimeout(timer);
      if (err) { css.remove(); script.remove(); pending = null; reject(err); }
      else { sdk = window.L; resolve(sdk); }
    }
    var cssReady = false, jsReady = false;
    function ready() { if (cssReady && jsReady) { finish(window.L && window.L.Map ? null : new Error('sdk')); } }
    css.rel = 'stylesheet'; css.href = base + 'index.css'; css.onload = function () { cssReady = true; ready(); }; css.onerror = function () { finish(new Error('css')); };
    script.src = base + 'index.js'; script.async = true; script.onload = function () { jsReady = true; ready(); }; script.onerror = function () { finish(new Error('js')); };
    document.head.appendChild(css); document.head.appendChild(script);
  });
  return pending;
}
function read(host) {
  var a = document.getElementById(host.dataset.latInput), b = document.getElementById(host.dataset.lngInput);
  return coords(a ? a.value : host.dataset.lat, b ? b.value : host.dataset.lng);
}
function status(host, text) { var el = host.querySelector('.cmb-location__status'); if (el) { el.textContent = text; } }
function prune() {
  instances = instances.filter(function (x) { if (document.documentElement.contains(x.host)) { return true; } x.map.remove(); return false; });
}
function open(host) {
  prune();
  var button = host.querySelector('[data-cmb-map-open]'), content = host.querySelector('.cmb-location__content');
  if (host.dataset.loading === '1') { return; }
  var existing = instances.filter(function (x) { return x.host === host; })[0];
  if (!existing && (!config.key || keyFail) && !content.hidden) { content.hidden = true; button.setAttribute('aria-expanded','false'); return; }
  if (existing) {
    content.hidden = !content.hidden; button.setAttribute('aria-expanded', String(!content.hidden));
    if (!content.hidden) { existing.map.invalidateSize(); }
    return;
  }
  content.hidden = false; button.setAttribute('aria-expanded', 'true');
  if (config.key && keyFail) {
    host.querySelector('.cmb-location__canvas').hidden = true;
    status(host, keyMessage(host.dataset.editable === '1'));
    return;
  }
  if (!config.key) {
    status(host, host.dataset.editable === '1' ? 'نقشه هنوز راه‌اندازی نشده است. مدیریت سایت باید کلید وب نشان را در تنظیمات وارد کند. می‌توانید مختصات را دستی ثبت کنید.' : 'نمایش نقشه فعلاً در دسترس نیست. از آدرس و دکمهٔ «مسیریابی» استفاده کنید.');
    host.querySelector('.cmb-location__canvas').hidden = true;
    return;
  }
  host.dataset.loading = '1'; button.setAttribute('aria-busy', 'true'); status(host, 'در حال بارگذاری نقشه…');
  load().then(function (L) {
    delete host.dataset.loading; button.removeAttribute('aria-busy');
    if (!document.documentElement.contains(host)) { return; }
    var canvas = host.querySelector('.cmb-location__canvas'), map;
    try {
      canvas.hidden = false;
      var point = read(host), editable = host.dataset.editable === '1';
      var la = document.getElementById(host.dataset.latInput), lo = document.getElementById(host.dataset.lngInput);
      watchKey();
      map = new L.Map(canvas, { key: config.key, maptype: 'dreamy', center: point || [32.4, 53.7], zoom: point ? 15 : 5, scrollWheelZoom: false });
      var instance = { host: host, map: map, marker: null, set: null, picked: false, orig: [la ? la.value : '', lo ? lo.value : ''] };
      instance.set = function (p, move) {
        if (!p) { if (instance.marker) { map.removeLayer(instance.marker); instance.marker = null; } return; }
        if (instance.marker) { instance.marker.setLatLng(p); }
        else {
          instance.marker = L.marker(p, { draggable: editable, title: editable ? 'محل مرکز؛ قابل جابه‌جایی' : 'محل مرکز',
            icon: L.divIcon({className:'cmb-location__marker',html:'<span></span>',iconSize:[28,36],iconAnchor:[14,36]}) }).addTo(map);
          if (editable) { instance.marker.on('dragend', function () { var p = instance.marker.getLatLng(); commit([p.lat, p.lng]); }); }
        }
        if (move) { map.setView(p, 15); }
      };
      function commit(p) {
        var a = document.getElementById(host.dataset.latInput), b = document.getElementById(host.dataset.lngInput);
        if (!a || !b || a.disabled || b.disabled || keyFail) { return; }
        instance.picked = true;
        a.value = p[0].toFixed(6); b.value = p[1].toFixed(6);
        a.dispatchEvent(new Event('input', {bubbles:true})); b.dispatchEvent(new Event('input', {bubbles:true}));
        instance.set(p, false); status(host, 'موقعیت انتخاب شد؛ برای ثبت نهایی، فرم را ذخیره کنید.');
      }
      // مشتری با زدن روی نقشه یا نشانگر، مسیریابی را در برنامه‌ی نقشه‌ی گوشی باز می‌کند
      var go = function () { document.dispatchEvent(new CustomEvent('cmb:mapopen', { detail: { lat: point[0], lng: point[1], title: host.dataset.title || '' } })); };
      if (editable) { map.on('click', function (e) { commit([e.latlng.lat, e.latlng.lng]); }); }
      else if (point) { map.on('click', go); }
      instance.set(point, false); instances.push(instance); map.invalidateSize();
      if (!editable && point && instance.marker) { instance.marker.on('click', go); }
      status(host, editable ? (point ? 'موقعیت ثبت‌شدهٔ مرکز' : 'هنوز نقطه‌ای انتخاب نشده است.') : 'برای مسیریابی با برنامهٔ نقشهٔ گوشی، روی نقشه بزنید.');
      function watch(layer) { if (layer && layer.on) { layer.on('tileerror', function () { status(host, 'دریافت نقشه ناموفق بود؛ اتصال اینترنت و دسترسی نشان را بررسی کنید. آدرس و مختصات همچنان قابل استفاده‌اند.'); }); } }
      map.eachLayer(watch); map.on('layeradd', function (e) { watch(e.layer); });
    } catch (err) {
      if (map) { map.remove(); } status(host, 'نقشه بارگذاری نشد. دوباره تلاش کنید یا مختصات را دستی وارد کنید.'); canvas.hidden = true;
    }
  }, function () {
    delete host.dataset.loading; button.removeAttribute('aria-busy');
    status(host, 'ارتباط با نقشه برقرار نشد. برای تلاش دوباره، دکمهٔ نقشه را بزنید.'); host.querySelector('.cmb-location__canvas').hidden = true;
  });
}
document.addEventListener('click', function (e) {
  var t = e.target;
  while (t && t.nodeType !== 1) { t = t.parentNode; }
  var button = t && t.closest && t.closest('[data-cmb-map-open]');
  if (button) { e.preventDefault(); open(button.closest('[data-cmb-location]')); }
});
document.addEventListener('change', function (e) {
  instances.forEach(function (x) { if (e.target.id && (e.target.id === x.host.dataset.latInput || e.target.id === x.host.dataset.lngInput)) { x.set(read(x.host), true); } });
});
window.CMBLocation = { html: html, sync: prune, coords: coords };
})();
