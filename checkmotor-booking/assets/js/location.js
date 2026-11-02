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
/* ۱.۵۰.۰: خواندن مختصات از لینک نقشه — همان قواعد CMB_Geo_Link در PHP. */
function inIran(lat, lng) { return lat >= 24 && lat <= 40.5 && lng >= 43.5 && lng <= 64; }
function pair(a, b) {
  a = Number(a); b = Number(b);
  if (!inIran(a, b) && inIran(b, a)) { var t = a; a = b; b = t; }
  if (!isFinite(a) || !isFinite(b) || Math.abs(a) > 90 || Math.abs(b) > 180 || (a === 0 && b === 0)) { return null; }
  return [Math.round(a * 1e6) / 1e6, Math.round(b * 1e6) / 1e6];
}
function geohash(h) {
  var chars = '0123456789bcdefghjkmnpqrstuvwxyz', lat = [-90, 90], lng = [-180, 180], even = true;
  h = String(h).toLowerCase();
  for (var i = 0; i < h.length; i++) {
    var v = chars.indexOf(h.charAt(i));
    if (v < 0) { return null; }
    for (var bit = 4; bit >= 0; bit--) {
      var on = (v >> bit) & 1, r = even ? lng : lat, mid = (r[0] + r[1]) / 2;
      r[on ? 0 : 1] = mid; even = !even;
    }
  }
  return [(lat[0] + lat[1]) / 2, (lng[0] + lng[1]) / 2];
}
function decodeAll(t) { for (var i = 0; i < 2; i++) { try { t = decodeURIComponent(t); } catch (e) { break; } } return t; }
function parseLink(text) {
  var t = String(text == null ? '' : text).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
    .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/٫/g, '.').replace(/،/g, ',');
  t = decodeAll(t);
  if (!t.trim()) { return null; }
  var n = '(-?\\d{1,3}\\.\\d+)';
  var res = [
    '!3d' + n + '!4d' + n,
    '[?&](?:q|query|ll|sll|destination|daddr|center|coordinate|coords?|latlng|latLng|to|markers?|location|loc|point|pt)=(?:ll\\.|loc:)?' + n + '\\s*,\\s*' + n,
    '[?&]latitude=' + n + '.*?[?&]longitude=' + n,
    '[?&]lat=' + n + '.*?[?&](?:lng|lon|long)=' + n,
    '[?&]mlat=' + n + '.*?[?&]mlon=' + n,
    '/(?:place|search)/' + n + ',(?:\\+|\\s)*' + n,
    '/dir/(?:[^/@?#]*/)*' + n + ',(?:\\+|\\s)*' + n + '(?:[/?#]|$)',
    '@' + n + ',' + n,
    '#c' + n + '-' + n + '-',
    '#map=\\d+(?:\\.\\d+)?/' + n + '/' + n,
    '#\\d+(?:\\.\\d+)?/' + n + '/' + n,
    '/destination/' + n + ',' + n,
    'geo:' + n + ',' + n
  ];
  for (var i = 0; i < res.length; i++) {
    var m = new RegExp(res[i], 'i').exec(t);
    var p = m && pair(m[1], m[2]);
    if (p) { return p; }
  }
  var w = /waze\.com\/ul\/h([0-9b-hjkmnp-z]{5,12})/i.exec(t);
  if (w) { var g = geohash(w[1]); if (g) { return pair(g[0], g[1]); } }
  var dms = '(\\d{1,3})\\s*°\\s*(\\d{1,2})\\s*[\'′]\\s*(\\d{1,2}(?:\\.\\d+)?)\\s*(?:"|″|\'\')?\\s*';
  var d = new RegExp(dms + '([NS])[\\s,+]*' + dms + '([EW])', 'i').exec(t);
  if (d) {
    return pair((+d[1] + d[2] / 60 + d[3] / 3600) * (/s/i.test(d[4]) ? -1 : 1), (+d[5] + d[6] / 60 + d[7] / 3600) * (/w/i.test(d[8]) ? -1 : 1));
  }
  var plain = new RegExp('^\\s*\\(?\\s*' + n + '\\s*[,\\s]\\s*' + n + '\\s*\\)?\\s*$').exec(t);
  return plain ? pair(plain[1], plain[2]) : null;
}
function sourceName(t) {
  t = String(t || '').toLowerCase();
  var map = [['goo.gl', 'گوگل‌مپ'], ['google.', 'گوگل‌مپ'], ['neshan', 'نشان'], ['nshn.ir', 'نشان'], ['balad', 'بلد'], ['waze', 'ویز'], ['apple.com', 'اپل‌مپ'], ['openstreetmap', 'OpenStreetMap'], ['osm.org', 'OpenStreetMap'], ['geo:', 'لینک geo']];
  for (var i = 0; i < map.length; i++) { if (t.indexOf(map[i][0]) !== -1) { return map[i][1]; } }
  return '';
}
function looksLikeMapLink(t) {
  t = String(t || '');
  return /https?:\/\/|geo:|waze:\/\//i.test(t) && /goo\.gl|google\.|neshan|nshn\.ir|balad|waze|apple\.com|openstreetmap|osm\.org|geo:/i.test(t);
}
function fa(v) { return String(v).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
/* لینک را روی یک بخش موقعیت اعمال می‌کند: مختصات در فیلدها، نشانگر نقشه جابه‌جا. */
function applyLink(host, text) {
  if (!host) { return; }
  var msg = host.querySelector('.cmb-location__msg');
  var say = function (txt, kind) { if (msg) { msg.textContent = txt; msg.className = 'cmb-location__msg' + (kind ? ' is-' + kind : ''); } };
  var url = (/(https?:\/\/\S+|geo:\S+)/i.exec(String(text)) || [String(text)])[0];
  var put = function (p, src) {
    var a = document.getElementById(host.dataset.latInput), b = document.getElementById(host.dataset.lngInput);
    if (!a || !b || a.disabled || b.disabled) { return; }
    a.value = p[0].toFixed(6); b.value = p[1].toFixed(6);
    [a, b].forEach(function (el) { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
    say('✓ موقعیت' + (src ? ' از ' + src : '') + ' خوانده شد: ' + fa(p[0].toFixed(5)) + '، ' + fa(p[1].toFixed(5)) +
      (inIran(p[0], p[1]) ? '. برای ثبت، فرم را ذخیره کنید.' : '. این نقطه داخل ایران نیست؛ لینک را بررسی کنید.'), inIran(p[0], p[1]) ? 'ok' : 'warn');
  };
  var p = parseLink(text);
  if (p) { put(p, sourceName(text)); return; }
  if (!String(text).trim()) { say(''); return; }
  if (!looksLikeMapLink(url) || !window.CMBLocation.resolve) {
    say('مختصاتی در این لینک پیدا نشد. لینک «اشتراک‌گذاری» گوگل‌مپ، نشان، بلد یا ویز را بچسبانید، یا مختصات را دستی وارد کنید.', 'bad');
    return;
  }
  say('در حال خواندن لینک کوتاه…');
  window.CMBLocation.resolve(url).then(function (r) {
    if (r && r.success !== false && r.lat !== undefined) { put([Number(r.lat), Number(r.lng)], r.source || sourceName(url)); }
    else { say((r && r.message) || 'در این لینک موقعیتی پیدا نشد.', 'bad'); }
  }, function () { say('ارتباط با سرور برقرار نشد؛ دوباره امتحان کنید یا مختصات را دستی وارد کنید.', 'bad'); });
}
function html(o) {
  o = o || {};
  // نمایش برای مشتری فقط با کلید نشان؛ بدون آن، دکمه‌ی «مسیریابی» کافی است
  if (!o.editable && (!coords(o.lat, o.lng) || !config.key)) { return ''; }
  var id = 'cmb-location-' + (++nextId);
  var paste = o.editable ? '<div class="cmb-location__paste">' +
      '<label class="cmb-location__pl" for="' + id + '-link">لینک موقعیت را بچسبانید</label>' +
      '<input class="cmb-location__link" id="' + id + '-link" type="url" dir="ltr" inputmode="url" autocomplete="off" data-cmb-map-link placeholder="https://maps.app.goo.gl/…">' +
      '<p class="cmb-location__msg" role="status" aria-live="polite"></p>' +
      '<p class="cmb-location__sub">از گوگل‌مپ، نشان، بلد، ویز یا هر نقشه‌ی دیگر «اشتراک‌گذاری ← کپی لینک»؛ مختصات خودکار پر می‌شود.</p></div>' : '';
  return '<section class="cmb-location" data-cmb-location data-lat="' + esc(o.lat) + '" data-lng="' + esc(o.lng) + '"' +
    ' data-lat-input="' + esc(o.latInput || '') + '" data-lng-input="' + esc(o.lngInput || '') + '" data-editable="' + (o.editable ? '1' : '0') + '" data-title="' + esc(o.title || '') + '"' +
    ' aria-label="' + esc(o.editable ? 'انتخاب موقعیت مرکز' : 'موقعیت ' + (o.title || 'مرکز')) + '">' + paste +
    '<button type="button" class="cmb-location__toggle" data-cmb-map-open aria-expanded="false" aria-controls="' + id + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>' +
      (o.editable ? 'انتخاب موقعیت روی نقشهٔ نشان' : 'نمایش روی نقشه') + '</button>' +
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
/* چسباندن یا تایپ لینک در کادر «لینک موقعیت» */
var linkTimer = null;
document.addEventListener('input', function (e) {
  var el = e.target;
  if (!el || !el.hasAttribute || !el.hasAttribute('data-cmb-map-link')) { return; }
  clearTimeout(linkTimer);
  linkTimer = setTimeout(function () {
    // متن «اشتراک‌گذاری» (مثل «معبر بدون نام» + لینک نشان): در کادر فقط خود لینک بماند
    var only = /(https?:\/\/[^\s"'<>]+)/i.exec(el.value);
    if (only && only[1] !== el.value.trim()) { el.value = only[1]; }
    applyLink(el.closest('[data-cmb-location]'), el.value);
  }, 250);
});
/* لینک نقشه‌ای که اشتباهی در کادر «نشانی» چسبانده شود: موقعیت خوانده می‌شود و متن
   نشانی دست نمی‌خورد. کادر نشانی باید data-cmb-address داشته باشد. */
document.addEventListener('paste', function (e) {
  var el = e.target;
  if (!el || !el.hasAttribute || !el.hasAttribute('data-cmb-address')) { return; }
  var text = (e.clipboardData || window.clipboardData) ? (e.clipboardData || window.clipboardData).getData('text') : '';
  if (!looksLikeMapLink(text)) { return; }
  var form = el.closest('.pn-modal, form, .pn-card') || document;
  var host = form.querySelector('[data-cmb-location][data-editable="1"]');
  if (!host) { return; }
  e.preventDefault();
  var box = host.querySelector('[data-cmb-map-link]');
  if (box) { box.value = ((/(https?:\/\/[^\s"'<>]+)/i.exec(text) || [text.trim()])[0]); }
  applyLink(host, text);
  if (box) { box.scrollIntoView({ block: 'nearest' }); }
});
window.CMBLocation = { html: html, sync: prune, coords: coords, parse: parseLink, apply: applyLink, resolve: null };
})();
