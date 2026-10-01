/* ═══════════════════════════════════════
   چک موتور — اپ رزرو نوبت
   ═══════════════════════════════════════ */
(function () {
'use strict';

var C     = window.CMB_APP || {};
var ROOT  = C.root || '/wp-json/cmb/v1/';
var BASE  = C.base || '/reserve/';
var NONCE = C.nonce || '';
var NURL  = C.nonceUrl || '/wp-admin/admin-ajax.php?action=cmb_nonce';

var TABS = ['home', 'mine', 'me'];

var S = {
  page: 'home',
  logged: !!C.logged,
  hasPhone: !!C.hasPhone,
  me: C.me || { name: '', phone: '' },
  canManage: !!C.canManage,
  cols: Number(C.cols) === 2 ? 2 : 1,

  // داده‌ی اولین رندر از خود صفحه می‌آید، نه از یک درخواست دوم
  services: (C.boot && C.boot.services) || [],
  branch: (C.boot && C.boot.branch) || C.branch || null,
  notes: C.notes || (C.boot && C.boot.notes) || {},
  loading: false, busy: false, servicesTried: false,

  /* ویزارد رزرو */
  step: 1,                 // 1 خدمت · 2 زمان · 3 ورود · 4 مشخصات
  service: null,
  cal: null, calCache: {}, day: null, block: null,
  form: { name: '', city: '', brand: '', model: '', year: '', mileage: '', note: '' },
  bad: {},

  /* ورود */
  auth: { step: 'phone', phone: '', code: '', masked: '', isNew: false, timer: 0, busy: false, err: '' },

  bookings: (C.bookings || []),
  bookingsFresh: !!(C.bookings && C.bookings.length),
  receipt: null, lightbox: '',
  cancelAsk: 0, cancelBusy: 0,
  toasts: [], sheet: null,
  online: navigator.onLine !== false
};

var timerId = null;

/* ─── ابزار ─── */

/**
 * تبدیل ارقام به فارسی — فقط اگر سرور گفته باشد فونت سایت آن‌ها را دارد.
 *
 * بسیاری از فونت‌ها ارقام فارسی (U+06F0–06F9) را ندارند و مرورگر
 * به‌جای عدد یک لوزی خالی می‌کشد. پیش‌فرض خاموش است.
 */
var FA_DIGITS = !!C.faDigits;

/**
 * سال جاری شمسی، محاسبه‌شده در مرورگر.
 *
 * همان الگوریتم jalali.js است، فقط سال را برمی‌گرداند. این اپ آن
 * فایل را بار نمی‌کند، پس نسخه‌ی کوچکش اینجاست.
 */
function jalaliYearNow() {
  var d = new Date();
  var gy = d.getFullYear();
  var gm = d.getMonth() + 1;
  var gd = d.getDate();

  var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
  var gy2 = (gm > 2) ? gy + 1 : gy;

  var days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) +
             Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];

  var jy = -1595 + 33 * Math.floor(days / 12053);
  days %= 12053;

  jy += 4 * Math.floor(days / 1461);
  days %= 1461;

  if (days > 365) { jy += Math.floor((days - 1) / 365); }

  return jy;
}

/**
 * سال‌های ساخت خودرو.
 *
 * ترجیح با فهرستِ سرور است، چون همان را موقع اعتبارسنجی می‌بیند.
 * ولی اگر نرسید — مثلاً افزونه‌ی کش صفحه، HTMLِ قدیمی را بدون این
 * کلید سرو کند — فیلد نباید به ورودی متنی برگردد. پس همان فهرست
 * در مرورگر ساخته می‌شود.
 */
function carYearList() {
  if (Array.isArray(C.carYears) && C.carYears.length) { return C.carYears; }

  var min = 1395;
  var max = jalaliYearNow();
  var out = [];

  if (!(max > 1300 && max < 1600)) { max = min; }
  if (max < min) { max = min; }

  for (var y = max; y >= min; y--) { out.push(String(y)); }

  return out;
}

var CAR_YEARS = carYearList();

/* پیشنهادهای شهر از سرور؛ فقط پیشنهاد است و هر شهری قابل تایپ است. */
var CITIES = Array.isArray(C.cities) ? C.cities : [];

function fa(v) {
  var s = String(v == null ? '' : v);
  return FA_DIGITS ? s.replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }) : s;
}
function en(v) {
  return String(v == null ? '' : v)
    .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
    .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
}
function esc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function $(s, r) { return (r || document).querySelector(s); }
function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function val(s) { var e = $(s); return e ? e.value : ''; }

function toast(msg, kind) {
  var id = Date.now() + Math.random();
  S.toasts.push({ id: id, msg: msg, kind: kind || '' });
  paintToasts();
  setTimeout(function () {
    S.toasts = S.toasts.filter(function (t) { return t.id !== id; });
    paintToasts();
  }, 3600);
}

/* ─── شبکه ─── */

var nonceWait = null;

/**
 * nonce تازه از admin-ajax.
 * از خود REST نمی‌شود گرفت: وقتی nonce کهنه شده، وردپرس درخواست را
 * «مهمان» می‌بیند و nonceای می‌سازد که برای کاربر صفر است — حلقه‌ای
 * که فقط با رفرش کامل باز می‌شد.
 */
function freshNonce() {
  if (nonceWait) { return nonceWait; }

  nonceWait = fetch(NURL, { credentials: 'include', cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (n) {
      if (n && n.nonce) { NONCE = n.nonce; }
      if (n && typeof n.logged !== 'undefined') { S.logged = !!n.logged; }
      nonceWait = null;
      return n || {};
    })
    .catch(function () { nonceWait = null; return {}; });

  return nonceWait;
}

function req(method, path, body, retried) {
  if (!S.online) {
    return Promise.resolve({ success: false, message: 'اتصال اینترنت برقرار نیست.' });
  }

  return fetch(ROOT + path, {
    method: method,
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
    credentials: 'include',
    cache: 'no-store',
    body: body ? JSON.stringify(body) : undefined
  })
    .then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        var stale = (r.status === 401 || r.status === 403) &&
          (!j.code || String(j.code).indexOf('nonce') !== -1 || String(j.code).indexOf('cookie') !== -1);

        // nonce کهنه: تازه‌اش کن و همان درخواست را دوباره بفرست
        if (stale && !retried) {
          return freshNonce().then(function () { return req(method, path, body, true); });
        }

        if (!r.ok) {
          return { success: false, message: j.message || 'خطایی رخ داد. دوباره تلاش کنید.', code: j.code || '', data: j.data || {} };
        }

        if (j && typeof j.success === 'undefined') { j.success = true; }
        return j;
      });
    })
    .catch(function () {
      return { success: false, message: 'ارتباط با سرور برقرار نشد.' };
    });
}
function get(p) { return req('GET', p); }
function post(p, b) { return req('POST', p, b); }

/* ─── مسیریابی ─── */

function readRoute() {
  var r = (C.route || '').replace(/^\/+|\/+$/g, '');

  if (!r && location.pathname.indexOf(BASE) === 0) {
    r = location.pathname.slice(BASE.length).replace(/^\/+|\/+$/g, '');
  }

  if (!r) { return 'home'; }

  var p = r.split('/')[0];
  if (p === 'book') { return 'book'; }
  if (TABS.indexOf(p) !== -1) { return p; }
  return 'home';
}

function pushURL(page) {
  if (!history.pushState) { return; }
  var path = page === 'home' ? BASE : BASE + page;
  if (location.pathname !== path) { history.pushState({ page: page }, '', path); }
}

function go(page) {
  S.page = page;
  S.sheet = null;
  pushURL(page);
  load(page);
}

/* ═══ آیکون ═══ */

var I = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.6L12 3l9 6.6V20a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 20z"/><path d="M9.2 21.5v-6.8h5.6v6.8"/></svg>',
  list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4" width="17" height="17" rx="2.6"/><path d="M3.5 9h17"/><path d="M8 2.5v3M16 2.5v3"/><path d="M8 13.5h8M8 17h5"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-1.5a4.5 4.5 0 0 0-4.5-4.5h-7A4.5 4.5 0 0 0 4 19.5V21"/><circle cx="12" cy="7.5" r="4.2"/></svg>',
  engine: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9.5h3.5V7h5v2.5H17l3 3v5.5h-3v2H7v-2H4v-6z"/><path d="M9.5 7V4.5h5V7"/></svg>',
  car: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14.5l1.7-5a2.4 2.4 0 0 1 2.3-1.6h10a2.4 2.4 0 0 1 2.3 1.6l1.7 5"/><path d="M3 14.5h18v3.6a1 1 0 0 1-1 1h-1.6a1 1 0 0 1-1-1v-.9H6.6v.9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><circle cx="7" cy="16.4" r=".9" fill="currentColor" stroke="none"/><circle cx="17" cy="16.4" r=".9" fill="currentColor" stroke="none"/></svg>',
  cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="16" rx="2.6"/><path d="M3.5 9.5h17M8 2.8v3.4M16 2.8v3.4"/></svg>',
  sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6"/></svg>',
  moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.2A8.6 8.6 0 0 1 9.8 3.5a8.6 8.6 0 1 0 10.7 10.7z"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 6.8v5.4l3.3 2"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6.5L9.3 17.2 4 12"/></svg>',
  chevR: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 18.5L16 12 9.5 5.5"/></svg>',
  chevL: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 18.5L8 12l6.5-6.5"/></svg>',
  info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 3.9L2.6 18a1.6 1.6 0 0 0 1.4 2.4h16a1.6 1.6 0 0 0 1.4-2.4l-8-14.1a1.6 1.6 0 0 0-2.8 0z"/><path d="M12 9.5v4M12 17h.01"/></svg>',
  ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.2 12.3l2.6 2.6 5-5"/></svg>',
  phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16.9v2.6a1.8 1.8 0 0 1-2 1.8 17.6 17.6 0 0 1-7.7-2.7 17.3 17.3 0 0 1-5.3-5.3A17.6 17.6 0 0 1 3.3 5.5 1.8 1.8 0 0 1 5.1 3.5h2.6a1.8 1.8 0 0 1 1.8 1.6c.1.9.3 1.7.6 2.5a1.8 1.8 0 0 1-.4 1.9l-1.1 1.1a14 14 0 0 0 5.3 5.3l1.1-1.1a1.8 1.8 0 0 1 1.9-.4c.8.3 1.6.5 2.5.6a1.8 1.8 0 0 1 1.6 1.9z"/></svg>',
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10.5c0 6.5-8 12.5-8 12.5s-8-6-8-12.5a8 8 0 0 1 16 0z"/><circle cx="12" cy="10.5" r="2.8"/></svg>',
  tag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 13.2l-7.3 7.3a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4.5a2 2 0 0 1 2-2h7.4a2 2 0 0 1 1.4.6l7.1 7.1a2 2 0 0 1 0 2.9z"/><circle cx="7.8" cy="7.8" r="1.4"/></svg>',
  note: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3.5H6.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V9z"/><path d="M14 3.5V9h5.5"/><path d="M8.5 13h7M8.5 16.5h4.5"/></svg>',
  logout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 21H5.5A2 2 0 0 1 3.5 19V5a2 2 0 0 1 2-2h4"/><path d="M16 16.5L20.5 12 16 7.5"/><path d="M20.5 12H9.5"/></svg>',
  gauge: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 17a9 9 0 1 1 17 0"/><path d="M12 17l4-5.5"/><circle cx="12" cy="17" r="1.4" fill="currentColor" stroke="none"/></svg>',
  panel: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M3 9h18M9 9v12"/></svg>',
  zoom: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6M11 8.5v5M8.5 11h5"/></svg>',
  empty: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="16" rx="2.6"/><path d="M3.5 9.5h17M8 2.8v3.4M16 2.8v3.4"/><path d="M9 14.5h6"/></svg>'
};

/* ═══ اجزای مشترک ═══ */

function topBar(title, sub, back) {
  return '<div class="cmb-top">' +
    (back ? '<button class="cmb-top__back" data-back>' + I.chevR + '</button>' : '') +
    '<div class="cmb-top__grow">' +
      '<div class="cmb-top__t">' + esc(title) + '</div>' +
      (sub ? '<div class="cmb-top__s">' + esc(sub) + '</div>' : '') +
    '</div></div>';
}

function note(kind, icon, text) {
  if (!text) { return ''; }
  return '<div class="cmb-note cmb-note--' + kind + '">' + icon + '<div>' + esc(text) + '</div></div>';
}

function empty(icon, title, sub) {
  return '<div class="cmb-empty"><div class="cmb-empty__ic">' + icon + '</div>' +
    '<div class="cmb-empty__t">' + esc(title) + '</div>' +
    (sub ? '<div class="cmb-empty__s">' + esc(sub) + '</div>' : '') + '</div>';
}

function nav() {
  var items = [
    { k: 'home', ic: I.home, t: 'رزرو نوبت' },
    { k: 'mine', ic: I.list, t: 'نوبت‌های من' },
    { k: 'me',   ic: I.user, t: 'حساب من' }
  ];

  var active = (S.page === 'book') ? 'home' : S.page;

  return '<div class="cmb-nav"><div class="cmb-nav__in">' +
    items.map(function (it) {
      return '<button class="cmb-nav__b' + (active === it.k ? ' is-on' : '') + '" data-tab="' + it.k + '">' +
        it.ic + '<span>' + it.t + '</span></button>';
    }).join('') +
    '</div></div>';
}

/* ═══ خانه ═══ */

function viewHome() {
  var b = S.branch || {};

  var html = '<div class="cmb-hero">' +
    '<div class="cmb-hero__row">' +
      '<div class="cmb-hero__logo">' + I.engine + '</div>' +
      '<div><div class="cmb-hero__name">چک موتور</div>' +
      '<div class="cmb-hero__sub">' + esc(b.title || 'رزرو نوبت آنلاین') + '</div></div>' +
    '</div>' +
    '<button class="cmb-hero__cta" data-start>' + I.cal + ' شروع رزرو نوبت</button>' +
    // مهمان باید بداند بدون رزرو جدید هم می‌تواند نوبت‌های قبلی‌اش را ببیند
    (S.logged
      ? ''
      : '<button class="cmb-hero__alt" data-tab="mine">' + I.list + ' قبلاً نوبت گرفته‌ام</button>') +
    '</div>';

  html += '<div class="cmb-page">';

  if (S.loading && !S.services.length) {
    html += '<div class="cmb-sk cmb-sk--card"></div><div class="cmb-sk cmb-sk--card"></div>';
  } else {
    html += '<div class="cmb-sec"><div class="cmb-sec__t">خدمات ما</div></div>';

    if (!S.services.length) {
      html += empty(I.empty, 'خدمتی فعال نیست', '');
    } else {
      html += '<div class="cmb-grid cmb-grid--' + S.cols + '">';
      S.services.forEach(function (s) { html += svcCard(s); });
      html += '</div>';
    }

    if (b.phone) {
      html += '<a class="cmb-btn cmb-btn--soft cmb-mt4" href="tel:' + esc(b.phone) + '">' +
        I.phone + ' تماس با شعبه ' + fa(b.phone) + '</a>';
    }

    if (b.address) {
      html += '<div class="cmb-note cmb-note--info">' + I.pin + '<div>' + esc(b.address) + '</div></div>';
    }
  }

  html += '</div>';
  return html;
}

/**
 * کارت خدمت.
 *
 * وقتی پوستر هست، خودِ پوستر عنوان، قیمت، شرح خدمات و مدت را دارد؛
 * تکرارشان زیر تصویر فقط صفحه را شلوغ می‌کرد. پس با پوستر، کارت
 * فقط تصویر است. متن‌ها تنها وقتی نشان داده می‌شوند که پوستری
 * وجود نداشته باشد.
 */
function svcCard(s) {
  var on = false;

  if (s.poster) {
    return '<button class="cmb-svc cmb-svc--poster' + (on ? ' is-on' : '') + '" data-svc="' + s.id + '">' +
      '<img class="cmb-svc__poster" src="' + esc(s.poster) + '"' +
        (s.posterSet ? ' srcset="' + esc(s.posterSet) + '" sizes="(max-width:460px) 100vw, 460px"' : '') +
        (s.posterW ? ' width="' + s.posterW + '" height="' + s.posterH + '"' : '') +
        ' alt="' + esc(s.title) + '" loading="lazy" decoding="async">' +
      '<span class="cmb-svc__zoom" data-zoom="' + esc(s.posterFull || s.poster) + '">' + I.zoom + '</span>' +
      (on ? '<span class="cmb-svc__tick">' + I.check + '</span>' : '') +
      '</button>';
  }

  return '<button class="cmb-svc' + (on ? ' is-on' : '') + '" data-svc="' + s.id + '">' +
    '<div class="cmb-svc__body">' +
      '<div class="cmb-svc__head"><div class="cmb-svc__t">' + esc(s.title) + '</div>' +
      (s.priceLabel ? '<div class="cmb-svc__price">' + esc(s.priceLabel) + '</div>' : '') + '</div>' +
      (s.description ? '<p class="cmb-svc__desc">' + esc(s.description) + '</p>' : '') +
      (s.duration || s.weekdayText
        ? '<div class="cmb-svc__meta">' +
            (s.duration ? '<div class="cmb-svc__line">' + I.clock + esc(s.duration) + '</div>' : '') +
            (s.weekdayText ? '<div class="cmb-svc__line">' + I.cal + esc(s.weekdayText) + '</div>' : '') +
          '</div>'
        : '') +
    '</div></button>';
}

/* ═══ ویزارد رزرو ═══ */

function steps() {
  var out = '<div class="cmb-steps">';
  for (var i = 1; i <= 4; i++) {
    out += '<div class="cmb-steps__i' + (i <= S.step ? ' is-done' : '') + '"></div>';
  }
  out += '</div>';

  var labels = { 1: 'گام ۱ از ۴ — انتخاب خدمت', 2: 'گام ۲ از ۴ — تاریخ و ساعت', 3: 'گام ۳ از ۴ — تایید شماره', 4: 'گام ۴ از ۴ — مشخصات خودرو' };
  out += '<div class="cmb-steps__lbl">' + labels[S.step] + '</div>';
  return out;
}

function viewBook() {
  if (S.receipt) { return viewReceipt(); }

  var head = topBar('رزرو نوبت', S.service ? S.service.title : '', true) + steps();

  if (S.step === 1) { return head + stepService(); }
  if (S.step === 2) { return head + stepSlot(); }
  if (S.step === 3) { return head + stepAuth(); }
  return head + stepDetails();
}

/**
 * خلاصه‌ی خدمت انتخاب‌شده.
 *
 * بعد از انتخاب، دیگر همه‌ی پوسترها را نشان نمی‌دهیم: یک باکس فشرده
 * می‌ماند که دقیقاً می‌گوید چه چیزی انتخاب شده. هم معلوم است کجای
 * کار هستیم، هم صفحه بی‌جهت بلند نمی‌شود.
 */
function svcChosen(s) {
  return '<div class="cmb-chosen">' +
    '<div class="cmb-chosen__hd">' +
      '<span class="cmb-chosen__tick">' + I.check + '</span>' +
      '<div class="cmb-chosen__grow">' +
        '<div class="cmb-chosen__k">خدمت انتخابی</div>' +
        '<div class="cmb-chosen__t">' + esc(s.title) + '</div>' +
      '</div>' +
      '<button class="cmb-chosen__edit" data-change-svc>تغییر</button>' +
    '</div>' +
    ((s.priceLabel || s.duration || s.weekdayText)
      ? '<div class="cmb-chosen__meta">' +
          (s.priceLabel ? '<div class="cmb-chosen__row">' + I.tag + esc(s.priceLabel) + '</div>' : '') +
          (s.duration ? '<div class="cmb-chosen__row">' + I.clock + esc(s.duration) + '</div>' : '') +
          (s.weekdayText ? '<div class="cmb-chosen__row">' + I.cal + esc(s.weekdayText) + '</div>' : '') +
        '</div>'
      : '') +
    '</div>';
}

/**
 * باکس انتخاب خدمت.
 *
 * در ویزارد رزرو، انتخاب بین باکس‌هاست نه بین پوسترها: پوستر برای
 * مرور در صفحه‌ی اصلی خوب است ولی وقتی کاربر آمده نوبت بگیرد،
 * می‌خواهد سریع بین گزینه‌ها مقایسه و انتخاب کند — قیمت و مدت و
 * روزها کنار هم، بدون اسکرول طولانی.
 */
function svcBox(s) {
  return '<button class="cmb-box" data-svc="' + s.id + '">' +
    '<div class="cmb-box__hd">' +
      '<div class="cmb-box__t">' + esc(s.title) + '</div>' +
      (s.priceLabel ? '<div class="cmb-box__price">' + esc(s.priceLabel) + '</div>' : '') +
    '</div>' +
    (s.description ? '<p class="cmb-box__desc">' + esc(s.description) + '</p>' : '') +
    ((s.duration || s.weekdayText)
      ? '<div class="cmb-box__meta">' +
          (s.duration ? '<div class="cmb-box__row">' + I.clock + esc(s.duration) + '</div>' : '') +
          (s.weekdayText ? '<div class="cmb-box__row">' + I.cal + esc(s.weekdayText) + '</div>' : '') +
        '</div>'
      : '') +
    '<div class="cmb-box__go">انتخاب و ادامه ' + I.chevL + '</div>' +
    '</button>';
}

function stepService() {
  var html = '<div class="cmb-page cmb-mt3">';

  // انتخاب که انجام شد، فهرست جای خودش را به خلاصه می‌دهد
  if (S.service) {
    html += svcChosen(S.service) +
      '<button class="cmb-btn cmb-btn--pri cmb-mt5" data-next-slot>ادامه — انتخاب تاریخ</button>' +
      '</div>';
    return html;
  }

  if (!S.services.length) {
    html += empty(I.empty, 'خدمتی در دسترس نیست', '');
  } else {
    html += '<div class="cmb-sec" style="margin-top:0"><div class="cmb-sec__t">خدمت مورد نظر را انتخاب کنید</div></div>';
    S.services.forEach(function (s) { html += svcBox(s); });
  }

  html += '</div>';
  return html;
}

function stepSlot() {
  var html = '<div class="cmb-page cmb-mt3">';

  if (S.service) { html += svcChosen(S.service); }

  if (!S.cal) {
    html += '<div class="cmb-sk cmb-sk--line" style="width:60%"></div><div class="cmb-sk cmb-sk--card"></div>';
    html += '</div>';
    return html;
  }

  var days = S.cal.days || [];

  html += '<div class="cmb-sec" style="margin-top:0"><div class="cmb-sec__t">تاریخ مراجعه</div></div>';
  html += '<div class="cmb-days">';

  days.forEach(function (d) {
    var free = d.available;
    var on = S.day && S.day.date === d.date;
    var parts = String(d.jalali || '').split(' ');

    html += '<button class="cmb-day' + (on ? ' is-on' : '') + (free ? '' : ' is-off') + '" data-day="' + esc(d.date) + '"' + (free ? '' : ' disabled') + '>' +
      '<div class="cmb-day__w">' + esc(d.weekdayName) + '</div>' +
      '<div class="cmb-day__d">' + fa(parts[0] || '') + '</div>' +
      '<div class="cmb-day__m">' + esc(parts.slice(1).join(' ')) + '</div>' +
      '<div class="cmb-day__dot' + (free ? '' : ' is-full') + '"></div>' +
      '</button>';
  });

  html += '</div>';

  var chosen = S.day;

  if (!chosen) {
    html += note('info', I.info, 'یک روز را انتخاب کنید.');
  } else if (!chosen.available) {
    html += note('warn', I.alert, chosen.reason || 'این روز ظرفیت خالی ندارد.');
  } else {
    html += '<div class="cmb-sec"><div class="cmb-sec__t">شیفت</div>' +
      '<div class="cmb-sec__a">' + esc(chosen.jalaliFull) + '</div></div>';

    (chosen.blocks || []).forEach(function (bl) {
      var on = S.block === bl.key;
      var ic = bl.key === 'morning' ? I.sun : I.moon;
      var tag, cls;

      if (!bl.available) { tag = bl.reason || 'تکمیل'; cls = 'no'; }
      else if (bl.remaining <= 1) { tag = 'آخرین ظرفیت'; cls = 'low'; }
      else { tag = fa(bl.remaining) + ' ظرفیت'; cls = 'ok'; }

      html += '<button class="cmb-block' + (on ? ' is-on' : '') + '" data-block="' + esc(bl.key) + '"' + (bl.available ? '' : ' disabled') + '>' +
        '<div class="cmb-block__ic">' + ic + '</div>' +
        '<div class="cmb-block__grow">' +
          '<div class="cmb-block__t">' + esc(bl.label) + '</div>' +
          '<div class="cmb-block__s">شروع ساعت ' + fa(bl.start) + '</div>' +
        '</div>' +
        '<div class="cmb-block__tag ' + cls + '">' + esc(tag) + '</div>' +
        '</button>';
    });
  }

  if (S.notes.minHours) { html += note('info', I.clock, S.notes.minHours); }
  if (S.notes.lateRule) { html += note('warn', I.clock, S.notes.lateRule); }
  if (S.notes.outOfWindow) { html += note('info', I.info, S.notes.outOfWindow); }

  html += '</div>';

  if (S.day && S.block) {
    var bl2 = (S.day.blocks || []).filter(function (x) { return x.key === S.block; })[0] || {};
    html += actionBar(S.day.jalali, (bl2.label || '') + ' · ' + fa(bl2.start || ''), 'ادامه', 'next-auth');
  }

  return html;
}

/**
 * کارت ورود — هم داخل ویزارد استفاده می‌شود هم به‌عنوان صفحه‌ی مستقل.
 *
 * @param intro متن بالای کارت، متناسب با جایی که از آن آمده‌ایم.
 */
function authCard(intro) {
  var a = S.auth;

  if (a.step === 'phone') {
    return '<div class="cmb-card">' +
      '<div class="cmb-svc__t">شماره موبایل خود را وارد کنید</div>' +
      '<p class="cmb-hint">' + esc(intro || 'یک کد ۵ رقمی برایتان پیامک می‌شود.') + '</p>' +
      '<label class="cmb-field"><span class="cmb-field__l">شماره موبایل <i>*</i></span>' +
      '<input class="cmb-in' + (a.err ? ' is-bad' : '') + '" id="cmb-phone" type="tel" inputmode="numeric" maxlength="15" placeholder="09123456789" value="' + esc(a.phone) + '"></label>' +
      (a.err ? '<div class="cmb-hint" style="color:var(--bad)">' + esc(a.err) + '</div>' : '') +
      '<button class="cmb-btn cmb-btn--pri cmb-mt5" data-send-otp' + (a.busy ? ' disabled' : '') + '>' +
        (a.busy ? '<span class="cmb-spin"></span> در حال ارسال…' : 'ارسال کد تایید') + '</button>' +
      '</div>';
  }

  return '<div class="cmb-card">' +
    '<div class="cmb-svc__t">کد تایید را وارد کنید</div>' +
    '<p class="cmb-hint">کد ۵ رقمی به <b>' + fa(esc(a.masked)) + '</b> ارسال شد. ' +
    '<button class="cmb-link" data-change-phone style="display:inline">تغییر شماره</button></p>' +
    '<div class="cmb-otp" id="cmb-otp">' +
      '<input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code">' +
      '<input type="text" inputmode="numeric" maxlength="1">' +
      '<input type="text" inputmode="numeric" maxlength="1">' +
      '<input type="text" inputmode="numeric" maxlength="1">' +
      '<input type="text" inputmode="numeric" maxlength="1">' +
    '</div>' +
    (a.isNew ? '<label class="cmb-field"><span class="cmb-field__l">نام و نام خانوادگی <i>*</i></span>' +
      '<input class="cmb-in" id="cmb-authname" type="text" placeholder="مثلاً رضا محمدی" value="' + esc(S.form.name) + '"></label>' : '') +
    (a.err ? '<div class="cmb-hint cmb-mt3" style="color:var(--bad)">' + esc(a.err) + '</div>' : '') +
    '<button class="cmb-btn cmb-btn--pri cmb-mt5" data-verify-otp' + (a.busy ? ' disabled' : '') + '>' +
      (a.busy ? '<span class="cmb-spin"></span> در حال بررسی…' : 'تایید و ادامه') + '</button>' +
    '<div class="cmb-center cmb-mt3">' +
      '<button class="cmb-link" data-resend' + (a.timer > 0 ? ' disabled' : '') + '>' +
      (a.timer > 0 ? 'ارسال مجدد تا ' + fa(a.timer) + ' ثانیه دیگر' : 'ارسال مجدد کد') + '</button>' +
    '</div></div>';
}

function stepAuth() {
  var html = '<div class="cmb-page cmb-mt4">';

  if (S.logged && S.hasPhone) {
    html += '<div class="cmb-card cmb-center">' +
      '<div class="cmb-empty__ic" style="margin:0 auto var(--s3);background:var(--ok-bg);color:var(--ok)">' + I.ok + '</div>' +
      '<div class="cmb-svc__t">شماره تایید شده</div>' +
      '<p class="cmb-hint cmb-num">' + fa(esc(S.me.phone)) + '</p>' +
      '<button class="cmb-btn cmb-btn--pri cmb-mt4" data-next-details>ادامه</button>' +
      '<button class="cmb-link cmb-mt3" data-logout>شماره‌ی دیگر</button>' +
      '</div></div>';
    return html;
  }

  // وارد شده ولی حسابش شماره ندارد (مثلاً با فرم ورود سایت آمده)
  html += authCard(S.logged
    ? 'برای ثبت نوبت باید یک شماره موبایل به حسابتان وصل شود.'
    : '');

  if (S.logged) {
    html += '<button class="cmb-link cmb-mt3" data-logout>خروج از این حساب</button>';
  }

  html += '</div>';
  return html;
}

function stepDetails() {
  var f = S.form;

  /* شهر معمولاً عوض نمی‌شود؛ از آخرین نوبت مشتری پر می‌شود تا
     دوباره تایپش نکند. فقط اگر خالی باشد — ورودی خودش را پاک نکنیم. */
  if (!f.city) {
    for (var i = 0; i < S.bookings.length; i++) {
      if (S.bookings[i].city) { f.city = S.bookings[i].city; break; }
    }
  }
  var bl = (S.day && S.day.blocks || []).filter(function (x) { return x.key === S.block; })[0] || {};

  var html = '<div class="cmb-page cmb-mt4">';

  html += '<div class="cmb-card cmb-card--flat">' +
    kv('خدمت', S.service ? S.service.title : '') +
    kv('تاریخ', S.day ? S.day.jalaliFull : '') +
    kv('ساعت', (bl.label || '') + ' — ' + fa(bl.start || '')) +
    (S.service && S.service.priceLabel ? kv('هزینه', S.service.priceLabel) : '') +
    '</div>';

  html += '<div class="cmb-card">' +
    '<div class="cmb-svc__t">مشخصات خودرو</div>' +
    field('name', 'نام و نام خانوادگی', 'text', 'مثلاً رضا محمدی', f.name, true) +
    cityField(f.city) +
    '<div class="cmb-grid2">' +
      /* ستون راست در RTL همان فیلد اول است. کلیدها (brand/model) و
         ستون‌های دیتابیس عوض نشده‌اند؛ فقط معنی‌شان برای این کسب‌وکار
         عوض شده: نوع خودرو و نوع موتور. */
      field('brand', 'نوع خودرو', 'text', 'مثلاً ۲۰۷', f.brand, true) +
      field('model', 'نوع موتور', 'text', 'مثلاً TU5', f.model, true) +
      (CAR_YEARS.length
        ? selectField('year', 'سال ساخت', CAR_YEARS, f.year, true, 'انتخاب کنید')
        : field('year', 'سال ساخت', 'tel', '۱۴۰۰', f.year, true)) +
      field('mileage', 'کارکرد (کیلومتر)', 'tel', '۱۲۰۰۰۰', f.mileage, false) +
    '</div>' +
    '<label class="cmb-field"><span class="cmb-field__l">توضیحات (اختیاری)</span>' +
    '<textarea class="cmb-in" id="cmb-f-note" placeholder="ECU خودرو چیست؟">' + esc(f.note) + '</textarea></label>' +
    '</div>';

  if (S.notes.ecu) { html += note('info', I.info, S.notes.ecu); }

  html += '</div>';

  html += '<div class="cmb-actionbar"><div class="cmb-actionbar__in">' +
    '<button class="cmb-btn cmb-btn--pri" style="width:100%" data-submit' + (S.busy ? ' disabled' : '') + '>' +
    (S.busy ? '<span class="cmb-spin"></span> در حال ثبت…' : 'ثبت نهایی نوبت') + '</button>' +
    '</div></div>';

  return html;
}

function field(key, label, type, ph, value, req) {
  return '<label class="cmb-field"><span class="cmb-field__l">' + esc(label) + (req ? ' <i>*</i>' : '') + '</span>' +
    '<input class="cmb-in' + (S.bad[key] ? ' is-bad' : '') + '" id="cmb-f-' + key + '" type="' + type + '"' +
    (type === 'tel' ? ' inputmode="numeric"' : '') +
    ' placeholder="' + esc(ph) + '" value="' + esc(value) + '"></label>';
}

/**
 * فیلد شهر.
 *
 * ورودی متنی با datalist: هر شهری قابل تایپ است، ولی حین تایپ
 * پیشنهاد می‌دهد تا نوشتار یکدست بماند — «بوشهر» و «Bushehr» در
 * خروجی اکسل دو شهر جدا حساب می‌شوند. کشویی اجباری نشد، چون هر
 * فهرستی ناقص است و مشتری‌ای که شهرش در فهرست نیست نباید گیر کند.
 */
function cityField(value) {
  return '<label class="cmb-field"><span class="cmb-field__l">شهر <i>*</i></span>' +
    '<input class="cmb-in' + (S.bad.city ? ' is-bad' : '') + '" id="cmb-f-city" type="text"' +
    ' list="cmb-cities" autocomplete="address-level2"' +
    ' placeholder="مثلاً بوشهر" value="' + esc(value) + '">' +
    (CITIES.length
      ? '<datalist id="cmb-cities">' + CITIES.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist>'
      : '') +
    '</label>';
}

/**
 * فیلد کشویی، هم‌شکل با field().
 *
 * گزینه‌ی اول خالی و غیرقابل انتخاب است تا «انتخاب کنید» به‌جای
 * مقدارِ پیش‌فرضِ ناخواسته ثبت نشود. متن گزینه‌ها فارسی‌شده است
 * ولی مقدارشان لاتین می‌ماند، چون سرور همان را می‌خواهد.
 */
function selectField(key, label, options, value, req, ph) {
  return '<label class="cmb-field"><span class="cmb-field__l">' + esc(label) + (req ? ' <i>*</i>' : '') + '</span>' +
    '<select class="cmb-in' + (S.bad[key] ? ' is-bad' : '') + '" id="cmb-f-' + key + '">' +
    '<option value="" disabled' + (value ? '' : ' selected') + '>' + esc(ph) + '</option>' +
    options.map(function (o) {
      return '<option value="' + esc(o) + '"' + (String(value) === String(o) ? ' selected' : '') + '>' + fa(esc(o)) + '</option>';
    }).join('') +
    '</select></label>';
}

function kv(k, v) {
  return '<div class="cmb-kv"><span class="cmb-kv__k">' + esc(k) + '</span><span class="cmb-kv__v">' + esc(v) + '</span></div>';
}

function actionBar(k, v, label, action) {
  return '<div class="cmb-actionbar"><div class="cmb-actionbar__in">' +
    '<div class="cmb-actionbar__sum"><div class="cmb-actionbar__k">' + esc(k) + '</div>' +
    '<div class="cmb-actionbar__v">' + esc(v) + '</div></div>' +
    '<button class="cmb-btn cmb-btn--pri" data-' + action + '>' + esc(label) + '</button>' +
    '</div></div>';
}

/* ═══ رسید ═══ */

function viewReceipt() {
  var r = S.receipt;

  var html = '<div class="cmb-page cmb-mt5">' +
    '<div class="cmb-receipt">' +
      '<div class="cmb-receipt__hd">' +
        '<div class="cmb-receipt__ic">' + I.check + '</div>' +
        '<div class="cmb-receipt__t">نوبت شما ثبت شد</div>' +
        '<div class="cmb-receipt__s">پیامک تاییدیه ارسال شد</div>' +
        '<div class="cmb-receipt__code">' + esc(r.code) + '</div>' +
      '</div>' +
      '<div class="cmb-receipt__body">' +
        kv('خدمت', r.service) +
        kv('تاریخ', r.dateFa) +
        kv('ساعت', r.blockLabel + ' — ' + fa(r.blockStart)) +
        kv('خودرو', r.car) +
        kv('نام', r.name) +
        kv('موبایل', r.phoneFa) +
        (r.servicePrice ? kv('هزینه', r.servicePrice) : '') +
      '</div>' +
    '</div>';

  if (r.note) { html += note('warn', I.alert, r.note); }
  if (r.lateRule) { html += note('info', I.clock, r.lateRule); }

  html += '<button class="cmb-btn cmb-btn--dark cmb-mt5" data-tab="mine">دیدن نوبت‌های من</button>' +
    '<button class="cmb-btn cmb-btn--ghost cmb-mt3" data-tab="home">بازگشت به صفحه اصلی</button>' +
    '</div>';

  return html;
}

/* ═══ نوبت‌های من ═══ */

/**
 * بخش لغو در پای هر نوبت.
 *
 * سه حالت دارد: مهلت باقی است (دکمه)، همان دکمه در حالت «مطمئنید؟»
 * (تایید دو مرحله‌ای، چون لغو برگشت‌پذیر نیست)، و مهلت گذشته (فقط
 * راهنما). خودِ سرور هم دوباره بررسی می‌کند؛ این فقط رابط است.
 */
function cancelBox(b) {
  if (b.status !== 'confirmed') { return ''; }

  if (!b.canCancel) {
    return b.cancelHint
      ? '<div class="cmb-bk__foot"><span class="cmb-bk__hint">' + fa(esc(b.cancelHint)) + '</span></div>'
      : '';
  }

  if (S.cancelAsk === b.id) {
    var busy = S.cancelBusy === b.id;

    return '<div class="cmb-bk__foot">' +
      '<div class="cmb-bk__ask">این نوبت لغو شود؟ ظرفیت آزاد می‌شود و برگشت‌پذیر نیست.</div>' +
      '<div class="cmb-bk__acts">' +
        '<button class="cmb-btn cmb-btn--sm cmb-btn--danger"' + (busy ? ' disabled' : '') +
          ' data-cancel-yes="' + b.id + '">' + (busy ? 'در حال لغو…' : 'بله، لغو کن') + '</button>' +
        '<button class="cmb-btn cmb-btn--sm cmb-btn--soft"' + (busy ? ' disabled' : '') +
          ' data-cancel-no>انصراف</button>' +
      '</div></div>';
  }

  return '<div class="cmb-bk__foot">' +
    (b.cancelHint ? '<span class="cmb-bk__hint">' + fa(esc(b.cancelHint)) + '</span>' : '') +
    '<button class="cmb-btn cmb-btn--sm cmb-btn--ghostdanger" data-cancel="' + b.id + '">لغو نوبت</button>' +
    '</div>';
}

function viewMine() {
  var html = topBar('نوبت‌های من', '', false) + '<div class="cmb-page cmb-mt4">';

  if (!S.logged || !S.hasPhone) {
    html += '<div class="cmb-card cmb-center">' +
      '<div class="cmb-empty__ic" style="margin:0 auto var(--s3)">' + I.list + '</div>' +
      '<div class="cmb-svc__t">نوبت‌های من</div>' +
      '</div>' +
      authCard('با شماره‌ای که نوبت گرفته‌اید وارد شوید.') +
      '<button class="cmb-btn cmb-btn--ghost cmb-mt3" data-start>نوبت جدید</button>' +
      '</div>';
    return html;
  }

  if (S.loading && !S.bookings.length) {
    html += '<div class="cmb-sk cmb-sk--card"></div><div class="cmb-sk cmb-sk--card"></div></div>';
    return html;
  }

  if (!S.bookings.length) {
    html += empty(I.empty, 'نوبتی ندارید', '') +
      '<button class="cmb-btn cmb-btn--pri" data-start>رزرو نوبت</button></div>';
    return html;
  }

  var anyCancellable = false;

  S.bookings.forEach(function (b) {
    if (b.canCancel) { anyCancellable = true; }

    html += '<div class="cmb-bk">' +
      '<div class="cmb-bk__top">' +
        '<div><div class="cmb-bk__t">' + esc(b.service) + '</div>' +
        '<div class="cmb-bk__c">کد پیگیری: <span class="cmb-code">' + esc(b.code) + '</span></div></div>' +
        '<span class="cmb-badge cmb-badge--' + (b.isPast && b.status === 'confirmed' ? 'past' : esc(b.status)) + '">' +
        (b.isPast && b.status === 'confirmed' ? 'زمان گذشته' : esc(b.statusLabel)) + '</span>' +
      '</div>' +
      '<div class="cmb-bk__body">' +
        '<div class="cmb-bk__row">' + I.cal + '<b>' + esc(b.dateFa) + '</b></div>' +
        '<div class="cmb-bk__row">' + I.clock + esc(b.blockLabel) + ' — ساعت ' + fa(esc(b.blockStart)) + '</div>' +
        '<div class="cmb-bk__row">' + I.car + esc(b.car) + '</div>' +
      '</div>' +
      cancelBox(b) +
      '</div>';
  });

  var ph = (S.branch && S.branch.phone) || '';

  var anyPast = S.bookings.some(function (b) { return b.isPast && b.status === 'confirmed'; });

  html += note(
    'info',
    I.info,
    anyPast
      ? 'نوبتی که زمانش گذشته جلوی گرفتن نوبت تازه را نمی‌گیرد؛ می‌توانید همین حالا دوباره رزرو کنید.'
      : (anyCancellable
        ? 'برای جابجایی نوبت با شعبه تماس بگیرید. لغو را می‌توانید تا مهلت اعلام‌شده خودتان انجام دهید.'
        : 'برای لغو یا جابجایی، با شعبه تماس بگیرید.')
  );

  if (ph) {
    html += '<a class="cmb-btn cmb-btn--soft cmb-mt3" href="tel:' + esc(ph) + '">' + I.phone + ' تماس با شعبه</a>';
  }

  html += '</div>';
  return html;
}

/* ═══ حساب من ═══ */

function viewMe() {
  var html = topBar('حساب من', '', false) + '<div class="cmb-page cmb-mt4">';

  if (!S.logged || !S.hasPhone) {
    var gPhone = (S.branch && S.branch.phone) || '';

    /* واردشده ولی بدون شماره (مثلاً مدیری که با رمز وارد پنل شده):
       پیش از این «وارد نشده‌اید» می‌دید و راهی برای خروج نداشت. */
    if (S.logged) {
      html += '<div class="cmb-card cmb-center">' +
        '<div class="cmb-empty__ic" style="margin:0 auto var(--s3);background:var(--steel-50);color:var(--steel-700)">' + I.user + '</div>' +
        '<div class="cmb-svc__t">' + esc(S.me.name || 'حساب کاربری') + '</div>' +
        '<div class="cmb-hint">به این حساب شماره موبایلی وصل نیست؛ برای رزرو نوبت شماره‌تان را تایید کنید.</div>' +
        '</div>' +
        authCard('یک کد ۵ رقمی برایتان پیامک می‌شود.');
    } else {
      html += '<div class="cmb-card cmb-center">' +
        '<div class="cmb-empty__ic" style="margin:0 auto var(--s3)">' + I.user + '</div>' +
        '<div class="cmb-svc__t">وارد نشده‌اید</div>' +
        '</div>' +
        authCard('ورود با کد پیامکی — رمز لازم نیست.');
    }

    // مهمان هم باید بتواند شعبه را پیدا کند و تماس بگیرد
    if (gPhone || (S.branch && S.branch.address)) {
      html += '<div class="cmb-rows">';

      if (gPhone) {
        html += '<a class="cmb-row" href="tel:' + esc(gPhone) + '">' +
          '<div class="cmb-row__ic">' + I.phone + '</div>' +
          '<div class="cmb-row__t">تماس با شعبه</div>' +
          '<div class="cmb-row__v cmb-num">' + fa(esc(gPhone)) + '</div></a>';
      }

      if (S.branch && S.branch.address) {
        html += '<div class="cmb-row">' +
          '<div class="cmb-row__ic">' + I.pin + '</div>' +
          '<div class="cmb-row__t" style="font-weight:400;font-size:var(--t-sb)">' + esc(S.branch.address) + '</div></div>';
      }

      html += '</div>';
    }

    if (S.logged) {
      html += '<div class="cmb-rows">';

      if (S.canManage && C.panel) {
        html += '<a class="cmb-row" href="' + esc(C.panel) + '">' +
          '<div class="cmb-row__ic">' + I.panel + '</div>' +
          '<div class="cmb-row__t">پنل مدیریت</div>' +
          '<div class="cmb-row__ch">' + I.chevL + '</div></a>';
      }

      html += '<button class="cmb-row cmb-row--bad" data-logout>' +
        '<div class="cmb-row__ic">' + I.logout + '</div>' +
        '<div class="cmb-row__t">خروج از حساب</div></button></div>';
    }

    html += '</div>';
    return html;
  }

  html += '<div class="cmb-card cmb-center">' +
    '<div class="cmb-empty__ic" style="margin:0 auto var(--s3);background:var(--steel-50);color:var(--steel-700)">' + I.user + '</div>' +
    '<div class="cmb-svc__t">' + esc(S.me.name || 'کاربر چک موتور') + '</div>' +
    '<div class="cmb-hint cmb-num">' + fa(esc(S.me.phone)) + '</div>' +
    '</div>';

  html += '<div class="cmb-rows">' +
    row(I.list, 'نوبت‌های من', fa(S.bookings.length) + ' مورد', 'tab', 'mine');

  if (S.canManage && C.panel) {
    html += '<a class="cmb-row" href="' + esc(C.panel) + '">' +
      '<div class="cmb-row__ic">' + I.panel + '</div>' +
      '<div class="cmb-row__t">پنل مدیریت</div>' +
      '<div class="cmb-row__ch">' + I.chevL + '</div></a>';
  }

  var ph = (S.branch && S.branch.phone) || '';

  if (ph) {
    html += '<a class="cmb-row" href="tel:' + esc(ph) + '">' +
      '<div class="cmb-row__ic">' + I.phone + '</div>' +
      '<div class="cmb-row__t">تماس با شعبه</div>' +
      '<div class="cmb-row__v cmb-num">' + fa(esc(ph)) + '</div></a>';
  }

  html += '<button class="cmb-row cmb-row--bad" data-logout>' +
    '<div class="cmb-row__ic">' + I.logout + '</div>' +
    '<div class="cmb-row__t">خروج از حساب</div></button>';

  html += '</div></div>';
  return html;
}

function row(ic, title, value, attr, val2) {
  return '<button class="cmb-row" data-' + attr + '="' + esc(val2) + '">' +
    '<div class="cmb-row__ic">' + ic + '</div>' +
    '<div class="cmb-row__t">' + esc(title) + '</div>' +
    (value ? '<div class="cmb-row__v">' + esc(value) + '</div>' : '') +
    '<div class="cmb-row__ch">' + I.chevL + '</div></button>';
}

/* ═══ رندر ═══ */

/* رندر در یک فریم جمع می‌شود و تمرکز/مکان‌نما/اسکرول حفظ می‌شود.
   پیش از این هر تغییر وضعیت، کل صفحه را از نو می‌ساخت؛ وسط پر کردن
   فرم یعنی پریدن مکان‌نما و بالا رفتن صفحه. */
var rafId = 0;

function paint() {
  if (rafId) { return; }

  rafId = (window.requestAnimationFrame || function (f) { return setTimeout(f, 16); })(function () {
    rafId = 0;
    render();
  });
}

function grabFocus() {
  var el = document.activeElement;

  if (!el || !el.id || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) { return null; }

  var f = { id: el.id, start: null, end: null };

  try {
    f.start = el.selectionStart;
    f.end = el.selectionEnd;
  } catch (e) { /* روی بعضی نوع‌های input خطا می‌دهد */ }

  return f;
}

function restoreFocus(f) {
  if (!f) { return; }

  var el = document.getElementById(f.id);
  if (!el) { return; }

  try {
    el.focus({ preventScroll: true });
    if (f.start !== null) { el.setSelectionRange(f.start, f.end); }
  } catch (e) { /* بی‌اهمیت */ }
}

function render() {
  var root = document.getElementById('cmb-app');
  if (!root) { return; }

  var keep = grabFocus();
  var y = window.pageYOffset;

  var inner;

  switch (S.page) {
    case 'book': inner = viewBook(); break;
    case 'mine': inner = viewMine(); break;
    case 'me':   inner = viewMe(); break;
    default:     inner = viewHome();
  }

  // فاصله‌ی پایین باید با نواری که واقعاً روی صفحه هست بخواند،
  // وگرنه آخرین بخش محتوا زیر نوار ثابت گم می‌شود.
  var wizard = (S.page === 'book' && !S.receipt);
  var shellClass = 'cmb-shell';

  if (wizard) {
    shellClass += inner.indexOf('cmb-actionbar') !== -1 ? ' cmb-shell--bar' : ' cmb-shell--bare';
  }

  root.innerHTML = '<div class="' + shellClass + '">' + inner + '</div>' +
    (wizard ? '' : nav()) +
    (S.lightbox
      ? '<div class="cmb-lightbox" data-zoom-close>' +
          '<button class="cmb-lightbox__x" data-zoom-close>✕</button>' +
          '<img src="' + esc(S.lightbox) + '" alt="">' +
        '</div>'
      : '') +
    '<div id="cmb-toasts" class="cmb-toasts"></div>';

  /* اگر فیلدی کد تایید نیست، همان جایی که بود برمی‌گردد. کادرهای
     کد تایید قاعده‌ی خودشان را دارند (پرش خودکار به خانه‌ی بعد). */
  if (keep && keep.id.indexOf('cmb-otp') === -1) {
    restoreFocus(keep);
    window.scrollTo(0, y);
  }

  paintToasts();
  focusOtp();
}

/**
 * لغو نوبت.
 *
 * پاسخ، فهرست تازه‌ی نوبت‌ها را هم برمی‌گرداند تا لازم نباشد
 * بلافاصله یک درخواست دوم برای /me زده شود.
 */
function cancelBooking(id) {
  if (!id || S.cancelBusy) { return; }

  S.cancelBusy = id;
  paint();

  post('bookings/cancel', { id: id }).then(function (r) {
    S.cancelBusy = 0;

    if (!r.success) {
      S.cancelAsk = 0;
      toast(r.message || 'لغو نوبت انجام نشد.', 'bad');
      paint();
      return;
    }

    S.cancelAsk = 0;

    if (r.bookings) {
      S.bookings = r.bookings;
      S.bookingsFresh = true;
    }

    toast(r.message || 'نوبت شما لغو شد.', 'ok');
    paint();
  });
}


function paintToasts() {
  var host = document.getElementById('cmb-toasts');
  if (!host) { return; }

  host.innerHTML = S.toasts.map(function (t) {
    var ic = t.kind === 'bad' ? I.alert : (t.kind === 'ok' ? I.ok : I.info);
    return '<div class="cmb-toast' + (t.kind ? ' cmb-toast--' + t.kind : '') + '">' + ic + '<div>' + esc(t.msg) + '</div></div>';
  }).join('');
}

/** بعد از رندر مرحله‌ی کد، اولین خانه فوکوس بگیرد. */
function focusOtp() {
  var box = document.getElementById('cmb-otp');
  if (!box) { return; }

  var first = box.querySelector('input');
  if (first && document.activeElement !== first) {
    setTimeout(function () { try { first.focus(); } catch (e) {} }, 60);
  }
}

/* ═══ بارگذاری داده ═══ */

function load(page) {
  if (page === 'home' || page === 'book') {
    // خدمات معمولاً از boot آمده‌اند؛ فقط اگر خالی بود درخواست می‌زنیم
    if (!S.services.length && !S.servicesTried) {
      S.servicesTried = true;
      S.loading = true;
      paint();

      get('services').then(function (r) {
        S.loading = false;
        if (r.success !== false) {
          S.services = r.services || [];
          S.branch = r.branch || S.branch;
          S.notes = r.notes || S.notes;
        }
        paint();
      });
      return;
    }
  }

  if (page === 'mine' || page === 'me') {
    if (!S.logged) { paint(); return; }

    // داده از boot آمده: همان لحظه نشان بده و در پس‌زمینه تازه کن
    S.loading = !S.bookingsFresh;
    paint();

    get('me').then(function (r) {
      S.loading = false;
      if (r.success !== false) {
        S.logged = !!r.loggedIn;
        S.hasPhone = !!r.hasPhone;
        if (r.loggedIn) {
          S.me = { name: r.name || '', phone: r.phone || '' };
          S.bookings = r.bookings || [];
          S.bookingsFresh = true;
          if (r.nonce) { NONCE = r.nonce; }
        }
      }
      paint();
    });
    return;
  }

  paint();
}

/** گرفتن تقویم ظرفیت یک خدمت. */
/**
 * گرفتن تقویم — با کش و پیش‌واکشی.
 *
 * @param quiet اگر true باشد فقط در پس‌زمینه می‌گیرد و چیزی رندر نمی‌کند.
 */
function loadCalendar(quiet) {
  var id = S.service.id;

  /* اگر قبلاً گرفته‌ایم، بدون هیچ انتظاری نشان بده. ولی نه برای همیشه:
     ظرفیت‌ها عوض می‌شوند و با گذشت زمان شیفت نزدیک «کمتر از ۲۴ ساعت»
     می‌شود؛ تقویمِ کهنه‌تر از دو دقیقه دوباره گرفته می‌شود. */
  if (S.calCache[id] && Date.now() - S.calCache[id]._at < 120000) {
    if (!quiet) {
      applyCalendar(S.calCache[id]);
      paint();
    }
    return;
  }

  if (!quiet) {
    S.cal = null;
    S.day = null;
    S.block = null;
    paint();
  }

  get('availability?service_id=' + id).then(function (r) {
    if (r.success !== false) { r._at = Date.now(); S.calCache[id] = r; }

    if (quiet) { return; }
    (function (r) {
      if (r.success === false) {
        toast(r.message || 'دریافت ظرفیت‌ها ناموفق بود.', 'bad');
        S.step = 1;
        paint();
        return;
      }

      applyCalendar(r);
      paint();
    })(r);
  });
}

/** نشاندن پاسخ تقویم در state. */
function applyCalendar(r) {
  S.cal = r;
  S.notes = r.notes || S.notes;

  // اولین روز باز، خودکار انتخاب شود تا کاربر یک کلیک کمتر بزند
  var first = (r.days || []).filter(function (d) { return d.available; })[0];

  S.day = first || null;
  S.block = null;
}

/* ═══ کنش‌ها ═══ */

function startBooking() {
  S.receipt = null;
  S.step = 1;
  S.bad = {};
  go('book');

  /* اگر فقط یک خدمت هست، کاربر قطعاً همان را می‌زند — پس تقویمش را
     همین حالا می‌گیریم. با چند خدمت هم اولی محتمل‌ترین است. */
  if (S.services.length && !S.service) {
    var probe = S.services[0];
    var keep = S.service;

    S.service = probe;
    loadCalendar(true);
    S.service = keep;
  }
}

function goStep(n) {
  S.step = n;
  window.scrollTo(0, 0);
  paint();
}

/** خواندن مقادیر فرم از DOM به state (قبل از هر رندر مجدد). */
function grabForm() {
  if (!$('#cmb-f-name')) { return; }

  S.form.name    = val('#cmb-f-name').trim();
  S.form.city    = val('#cmb-f-city').trim().replace(/\s+/g, ' ');
  S.form.brand   = val('#cmb-f-brand').trim();
  S.form.model   = val('#cmb-f-model').trim();
  S.form.year    = en(val('#cmb-f-year')).replace(/\D/g, '');
  S.form.mileage = en(val('#cmb-f-mileage')).replace(/\D/g, '');
  S.form.note    = val('#cmb-f-note').trim();
}

function submitBooking() {
  grabForm();

  var f = S.form;
  S.bad = {};
  S.nameIsPhone = false;

  if (f.name.length < 3) { S.bad.name = 1; }
  // شماره در فیلد نام — سرور هم رد می‌کند، ولی بهتر است زودتر بفهمد
  if (/^[0-9+\s\-()]{6,}$/.test(en(f.name))) { S.bad.name = 1; S.nameIsPhone = true; }
  if (f.city.length < 2 || /\d{4,}/.test(en(f.city))) { S.bad.city = 1; }
  if (!f.brand) { S.bad.brand = 1; }
  if (!f.model) { S.bad.model = 1; }
  if (!/^\d{4}$/.test(f.year)) { S.bad.year = 1; }

  if (Object.keys(S.bad).length) {
    toast(S.nameIsPhone
      ? 'در فیلد نام، نام و نام خانوادگی بنویسید نه شماره.'
      : 'فیلدهای ستاره‌دار را کامل کنید.', 'bad');
    S.nameIsPhone = false;
    paint();
    return;
  }

  S.busy = true;
  paint();

  post('bookings', {
    service_id: S.service.id,
    date: S.day.date,
    block: S.block,
    name: f.name,
    city: f.city,
    car_brand: f.brand,
    car_model: f.model,
    car_year: f.year,
    car_mileage: f.mileage,
    note: f.note
  }).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message || 'ثبت نوبت ناموفق بود.', 'bad');

      // ظرفیت پر شده یا روز بسته شده: کاربر باید دوباره زمان انتخاب کند
      if (['cmb_block_full', 'cmb_block_closed', 'cmb_day_closed', 'cmb_too_soon', 'cmb_too_far'].indexOf(r.code) !== -1) {
        // تقویم کش‌شده همان است که این انتخاب را نشان داده بود
        delete S.calCache[S.service.id];
        S.step = 2;
        loadCalendar();
        return;
      }

      if (r.code === 'cmb_not_logged_in') {
        S.logged = false;
        S.step = 3;
      }

      paint();
      return;
    }

    S.receipt = r.booking;
    S.bookings = [];
    toast('نوبت شما با موفقیت ثبت شد.', 'ok');
    window.scrollTo(0, 0);
    paint();
  });
}

/* ─── ورود ─── */

function sendOtp() {
  var phone = en(val('#cmb-phone')).replace(/[^\d+]/g, '');

  S.auth.phone = phone;
  S.auth.err = '';

  if (!/^09\d{9}$/.test(phone)) {
    S.auth.err = 'شماره موبایل معتبر نیست.';
    paint();
    return;
  }

  S.auth.busy = true;
  paint();

  post('otp/request', { phone: phone }).then(function (r) {
    S.auth.busy = false;

    if (r.success === false) {
      S.auth.err = r.message || 'ارسال کد ناموفق بود.';

      // محدودیت ارسال مجدد: مستقیم به مرحله‌ی کد برویم
      if (r.code === 'cmb_otp_throttled') {
        S.auth.step = 'code';
        S.auth.masked = phone;
        startTimer(Number((r.data && r.data.retry_after) || 60));
      }

      paint();
      return;
    }

    S.auth.step = 'code';
    S.auth.masked = r.masked_phone || phone;
    S.auth.isNew = !!r.is_new_user;
    S.auth.code = '';
    S.auth.err = '';

    startTimer(Number(r.resend_after || 120));

    if (r.dev_code) { toast('کد تست: ' + r.dev_code); }
    else { toast('کد تایید ارسال شد.', 'ok'); }

    paint();
  });
}

function verifyOtp() {
  var code = $$('#cmb-otp input').map(function (i) { return en(i.value).replace(/\D/g, ''); }).join('');

  S.auth.err = '';

  if (code.length !== 5) {
    S.auth.err = 'کد تایید را کامل وارد کنید.';
    paint();
    return;
  }

  var name = $('#cmb-authname') ? val('#cmb-authname').trim() : '';

  if (S.auth.isNew && name.length < 3) {
    S.auth.err = 'نام و نام خانوادگی را کامل وارد کنید.';
    paint();
    return;
  }

  S.auth.busy = true;
  paint();

  post('otp/verify', { phone: S.auth.phone, code: code, name: name }).then(function (r) {
    S.auth.busy = false;

    if (r.success === false) {
      S.auth.err = r.message || 'کد تایید درست نیست.';
      $$('#cmb-otp input').forEach(function (i) { i.value = ''; });
      paint();
      return;
    }

    // بعد از ورود، nonce عوض می‌شود چون شناسه‌ی کاربر تغییر کرده
    if (r.nonce) { NONCE = r.nonce; }

    S.logged = true;
    S.hasPhone = true;
    S.me = { name: r.display_name || name, phone: r.phone || S.auth.phone };
    // display_name کاربرهای OTP خودِ شماره است؛ نباید در فیلد نام بنشیند
    var dn = String(r.display_name || name || '');
    if (!S.form.name && dn && !/^[0-9+]{6,}$/.test(dn.replace(/\s/g, ''))) { S.form.name = dn; }

    stopTimer();
    toast('خوش آمدید!', 'ok');

    // اگر از داخل ویزارد وارد شده بود، به گام بعدی برود؛
    // اگر از تب «نوبت‌های من» یا «حساب من» وارد شده، همان‌جا بماند.
    if (S.page === 'book') {
      goStep(4);
      return;
    }

    S.bookings = [];
    load(S.page);
  });
}

function logout() {
  if (S.busy) { return; }

  S.busy = true;
  paint();

  post('logout', {}).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message || 'خروج انجام نشد. دوباره تلاش کنید.', 'bad');
      paint();
      return;
    }

    S.logged = false;
    S.hasPhone = false;
    S.me = { name: '', phone: '' };
    S.bookings = [];
    S.auth = { step: 'phone', phone: '', code: '', masked: '', isNew: false, timer: 0, busy: false, err: '' };

    stopTimer();

    /* بارگذاری کامل، نه فقط رندر دوباره.
       کوکی‌ها سمت سرور پاک شده‌اند ولی این صفحه هنوز nonce و تنظیمات
       کاربرِ قبلی را در خودش دارد. رفرش تضمین می‌کند هیچ چیز کهنه‌ای
       باقی نماند. */
    location.href = BASE;
  });
}

function startTimer(sec) {
  stopTimer();
  S.auth.timer = Math.max(0, sec);

  timerId = setInterval(function () {
    S.auth.timer--;

    if (S.auth.timer <= 0) {
      stopTimer();
      paint();
      return;
    }

    // فقط متن دکمه را عوض کن، کل صفحه را دوباره نساز
    var b = $('[data-resend]');
    if (b) { b.textContent = 'ارسال مجدد تا ' + fa(S.auth.timer) + ' ثانیه دیگر'; }
  }, 1000);
}

function stopTimer() {
  if (timerId) { clearInterval(timerId); timerId = null; }
  S.auth.timer = 0;
}

/* ═══ رویدادها ═══ */

function bind() {
  document.addEventListener('click', function (e) {
    var t = e.target;

    function up(sel) { return t.closest ? t.closest(sel) : null; }

    var el;

    if ((el = up('[data-tab]'))) {
      e.preventDefault();
      S.receipt = null;
      go(el.getAttribute('data-tab'));
      return;
    }

    if (up('[data-start]')) { e.preventDefault(); startBooking(); return; }

    if ((el = up('[data-cancel]'))) {
      e.preventDefault();
      S.cancelAsk = Number(el.getAttribute('data-cancel'));
      paint();
      return;
    }

    if (up('[data-cancel-no]')) {
      e.preventDefault();
      S.cancelAsk = 0;
      paint();
      return;
    }

    if ((el = up('[data-cancel-yes]'))) {
      e.preventDefault();
      cancelBooking(Number(el.getAttribute('data-cancel-yes')));
      return;
    }

    if (up('[data-back]')) {
      e.preventDefault();

      if (S.receipt) { S.receipt = null; go('home'); return; }
      if (S.step > 1) { goStep(S.step - 1); return; }
      go('home');
      return;
    }

    if ((el = up('[data-zoom]'))) {
      e.preventDefault();
      e.stopPropagation();
      S.lightbox = el.getAttribute('data-zoom');
      paint();
      return;
    }

    if (up('[data-zoom-close]')) {
      e.preventDefault();
      S.lightbox = '';
      paint();
      return;
    }

    if ((el = up('[data-svc]'))) {
      e.preventDefault();
      var id = Number(el.getAttribute('data-svc'));
      var svc = S.services.filter(function (s) { return s.id === id; })[0];
      if (!svc) { return; }

      S.service = svc;

      if (S.page !== 'book') { startBooking(); return; }

      // داخل ویزارد: انتخاب یعنی همان لحظه خلاصه نشان داده شود
      window.scrollTo(0, 0);
      paint();

      // تقویم را همین حالا در پس‌زمینه می‌گیریم تا گام بعد فوری باز شود
      loadCalendar(true);
      return;
    }

    if (up('[data-change-svc]')) {
      e.preventDefault();
      S.service = null;
      S.cal = null;
      S.day = null;
      S.block = null;
      goStep(1);
      return;
    }

    if (up('[data-next-slot]')) {
      e.preventDefault();
      goStep(2);
      loadCalendar();
      return;
    }

    if ((el = up('[data-day]'))) {
      e.preventDefault();
      var d = el.getAttribute('data-day');
      S.day = (S.cal.days || []).filter(function (x) { return x.date === d; })[0] || null;
      S.block = null;
      paint();
      return;
    }

    if ((el = up('[data-block]'))) {
      e.preventDefault();
      S.block = el.getAttribute('data-block');
      paint();
      return;
    }

    if (up('[data-next-auth]')) {
      e.preventDefault();
      if (S.logged && !S.form.name && S.me.name && !/^[0-9+]{6,}$/.test(S.me.name.replace(/\s/g, ''))) { S.form.name = S.me.name; }
      goStep(S.logged && S.hasPhone ? 4 : 3);
      return;
    }

    if (up('[data-next-details]')) { e.preventDefault(); goStep(4); return; }
    if (up('[data-send-otp]')) { e.preventDefault(); sendOtp(); return; }
    if (up('[data-verify-otp]')) { e.preventDefault(); verifyOtp(); return; }
    if (up('[data-resend]')) { e.preventDefault(); S.auth.step = 'phone'; paint(); setTimeout(sendOtp, 30); return; }

    if (up('[data-change-phone]')) {
      e.preventDefault();
      stopTimer();
      S.auth.step = 'phone';
      S.auth.err = '';
      paint();
      return;
    }

    if (up('[data-submit]')) { e.preventDefault(); submitBooking(); return; }
    if (up('[data-logout]')) { e.preventDefault(); logout(); return; }
  });

  /* خانه‌های کد تایید: پرش خودکار، بازگشت با Backspace، چسباندن کد کامل */
  document.addEventListener('input', function (e) {
    var box = document.getElementById('cmb-otp');
    if (!box || !box.contains(e.target)) { return; }

    var inputs = $$('input', box);
    var i = inputs.indexOf(e.target);

    e.target.value = en(e.target.value).replace(/\D/g, '').slice(0, 1);
    e.target.classList.toggle('is-full', !!e.target.value);

    if (e.target.value && i < inputs.length - 1) { inputs[i + 1].focus(); }

    var all = inputs.map(function (x) { return x.value; }).join('');
    if (all.length === 5) { setTimeout(verifyOtp, 120); }
  });

  document.addEventListener('keydown', function (e) {
    var box = document.getElementById('cmb-otp');

    if (box && box.contains(e.target) && e.key === 'Backspace' && !e.target.value) {
      var inputs = $$('input', box);
      var i = inputs.indexOf(e.target);
      if (i > 0) { inputs[i - 1].focus(); }
      return;
    }

    if (e.key === 'Escape' && S.lightbox) { S.lightbox = ''; paint(); return; }

    if (e.key === 'Enter' && $('#cmb-phone') && document.activeElement === $('#cmb-phone')) {
      e.preventDefault();
      sendOtp();
    }
  });

  document.addEventListener('paste', function (e) {
    var box = document.getElementById('cmb-otp');
    if (!box || !box.contains(e.target)) { return; }

    var text = (e.clipboardData || window.clipboardData).getData('text');
    var digits = en(text).replace(/\D/g, '').slice(0, 5);
    if (!digits) { return; }

    e.preventDefault();
    var inputs = $$('input', box);

    digits.split('').forEach(function (d, i) {
      if (inputs[i]) { inputs[i].value = d; inputs[i].classList.add('is-full'); }
    });

    if (digits.length === 5) { setTimeout(verifyOtp, 120); }
    else if (inputs[digits.length]) { inputs[digits.length].focus(); }
  });

  /* مقادیر فرم قبل از رندر مجدد از بین نروند */
  document.addEventListener('change', function (e) {
    if (e.target.id && e.target.id.indexOf('cmb-f-') === 0) { grabForm(); }
  });

  window.addEventListener('popstate', function () {
    var p = readRoute();
    S.page = p;
    load(p);
  });

  window.addEventListener('online', function () { S.online = true; });
  window.addEventListener('offline', function () {
    S.online = false;
    toast('اتصال اینترنت قطع شد.', 'bad');
  });
}

/* ═══ راه‌اندازی ═══ */

function boot() {
  S.page = readRoute();

  if (S.page === 'book') { S.page = 'home'; }
  if (S.logged && S.me.name && !/^[0-9+]{6,}$/.test(S.me.name.replace(/\s/g, ''))) { S.form.name = S.me.name; }

  bind();
  load(S.page);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

})();
