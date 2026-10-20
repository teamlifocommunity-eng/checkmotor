/* Shared Neshan location viewer/editor. The SDK is downloaded only on request. */
(function () {
'use strict';
var config = window.CMB_MAP || {};
var sdk = null, pending = null, instances = [], nextId = 0;
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
  if (!o.editable && !coords(o.lat, o.lng)) { return ''; }
  var id = 'cmb-location-' + (++nextId);
  return '<section class="cmb-location" data-cmb-location data-lat="' + esc(o.lat) + '" data-lng="' + esc(o.lng) + '"' +
    ' data-lat-input="' + esc(o.latInput || '') + '" data-lng-input="' + esc(o.lngInput || '') + '" data-editable="' + (o.editable ? '1' : '0') + '"' +
    ' aria-label="' + esc(o.editable ? 'انتخاب موقعیت مرکز' : 'موقعیت ' + (o.title || 'مرکز')) + '">' +
    '<button type="button" class="cmb-location__toggle" data-cmb-map-open aria-expanded="false" aria-controls="' + id + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>' +
      (o.editable ? 'انتخاب موقعیت روی نقشهٔ نشان' : 'نمایش نقشهٔ نشان') + '</button>' +
    '<div class="cmb-location__content" id="' + id + '" hidden><div class="cmb-location__canvas" tabindex="0" role="region" aria-label="نقشهٔ نشان"></div>' +
      '<p class="cmb-location__status" role="status" aria-live="polite"></p>' +
      (o.editable ? '<p class="cmb-location__hint">روی محل مرکز بزنید یا نشانگر را جابه‌جا کنید؛ سپس فرم را ذخیره کنید. ورود دستی مختصات هم امکان‌پذیر است.</p>' : '') +
    '</div></section>';
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
  if (!existing && !config.key && !content.hidden) { content.hidden = true; button.setAttribute('aria-expanded','false'); return; }
  if (existing) {
    content.hidden = !content.hidden; button.setAttribute('aria-expanded', String(!content.hidden));
    if (!content.hidden) { existing.map.invalidateSize(); }
    return;
  }
  content.hidden = false; button.setAttribute('aria-expanded', 'true');
  if (!config.key) {
    status(host, host.dataset.editable === '1' ? 'نقشه هنوز راه‌اندازی نشده است. مدیریت سایت باید کلید وب نشان را در تنظیمات وارد کند. می‌توانید مختصات را دستی ثبت کنید.' : 'نمایش نقشه فعلاً در دسترس نیست. از آدرس و دکمهٔ «مسیریابی با نشان» استفاده کنید.');
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
      map = new L.Map(canvas, { key: config.key, maptype: 'dreamy', center: point || [32.4, 53.7], zoom: point ? 15 : 5, scrollWheelZoom: false });
      var instance = { host: host, map: map, marker: null, set: null };
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
        if (!a || !b || a.disabled || b.disabled) { return; }
        a.value = p[0].toFixed(6); b.value = p[1].toFixed(6);
        a.dispatchEvent(new Event('input', {bubbles:true})); b.dispatchEvent(new Event('input', {bubbles:true}));
        instance.set(p, false); status(host, 'موقعیت انتخاب شد؛ برای ثبت نهایی، فرم را ذخیره کنید.');
      }
      if (editable) { map.on('click', function (e) { commit([e.latlng.lat, e.latlng.lng]); }); }
      instance.set(point, false); instances.push(instance); map.invalidateSize();
      status(host, editable ? (point ? 'موقعیت ثبت‌شدهٔ مرکز' : 'هنوز نقطه‌ای انتخاب نشده است.') : 'موقعیت مرکز؛ برای مسیریابی از دکمهٔ نشان استفاده کنید.');
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
  var button = e.target.closest && e.target.closest('[data-cmb-map-open]');
  if (button) { e.preventDefault(); open(button.closest('[data-cmb-location]')); }
});
document.addEventListener('change', function (e) {
  instances.forEach(function (x) { if (e.target.id && (e.target.id === x.host.dataset.latInput || e.target.id === x.host.dataset.lngInput)) { x.set(read(x.host), true); } });
});
window.CMBLocation = { html: html, sync: prune, coords: coords };
})();
