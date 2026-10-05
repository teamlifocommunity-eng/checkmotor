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

var TABS = ['home', 'mine', 'wallet', 'me'];

var S = {
  page: 'home',
  logged: !!C.logged,
  hasPhone: !!C.hasPhone,
  me: C.me || { name: '', phone: '' },
  canManage: !!C.canManage,
  cols: Number(C.cols) === 2 ? 2 : 1,

  // داده‌ی اولین رندر از خود صفحه می‌آید، نه از یک درخواست دوم
  /* خدمت‌های «آزمایشی» فقط برای مدیران، بیرون از فهرست کش‌شده‌ی عمومی */
  services: ((C.boot && C.boot.services) || []).concat(C.testServices || []),
  branch: (C.boot && C.boot.branch) || C.branch || null,
  notes: C.notes || (C.boot && C.boot.notes) || {},
  loading: false, busy: false, servicesTried: false,

  /* ویزارد رزرو */
  step: 1,                 // 1 خدمت · 2 زمان · 3 ورود · 4 مشخصات · 5 قوانین و پرداخت (فقط با بیعانه)
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

  /* بیعانه */
  pay: C.pay || { on: false, sandbox: false },
  quote: null, quoteFor: '', termsOk: false, redirecting: false,
  pendingBlock: null,              // نوبتِ در انتظار پرداختی که جلوی ثبت تازه را گرفته
  payResult: C.payResult || null,  // صفحه‌ی بازگشت از درگاه
  payBusy: 0,

  /* کیف پول: برگشت‌ها به آن می‌رود و بیعانه با آن پرداخت می‌شود */
  wallet: C.wallet || null,           // { on, show, spend, balance, held, history[], topup{} }
  walletFresh: !!C.wallet,
  walletLoading: false,
  topupResult: C.topupResult || null, // صفحه‌ی بازگشت از درگاهِ شارژ
  topupAmount: 0, topupBusy: false,
  useWallet: true,                    // تیک «استفاده از کیف پول» در گام پرداخت

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
function money(n) { return fa(Number(n || 0).toLocaleString('en-US')); }
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
  if (p === 'pay') { return 'pay'; }
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
  card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></svg>',
  wallet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7.5V6a2 2 0 0 0-2-2H5.5A2.5 2.5 0 0 0 3 6.5v11A2.5 2.5 0 0 0 5.5 20H19a2 2 0 0 0 2-2v-8.5a2 2 0 0 0-2-2H5.5A2.5 2.5 0 0 1 3 6.5"/><circle cx="16.5" cy="13.75" r="1.3" fill="currentColor" stroke="none"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5.5v13M5.5 12h13"/></svg>',
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
    { k: 'mine', ic: I.list, t: 'نوبت‌های من' }
  ];

  // کیف پول: وقتی بیعانه روشن است، یا مشتری از قبل تراکنشی دارد
  if (walletShown()) { items.push({ k: 'wallet', ic: I.wallet, t: 'کیف پول' }); }

  items.push({ k: 'me', ic: I.user, t: 'حساب من' });

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

  /* نوبتی که پرداختش نیمه‌کاره مانده (مثلاً مشتری از درگاه با «بازگشت»
     برگشته): همین‌جا ادامه یا انصراف، تا جا بی‌جهت نگه داشته نشود. */
  S.bookings.filter(function (x) { return x.status === 'pending' && x.holdLeft > 0; }).slice(0, 1).forEach(function (x) {
    html += '<div class="cmb-card cmb-paynote">' +
      '<div class="cmb-svc__t">' + I.clock + ' پرداخت نوبتتان تمام نشده</div>' +
      '<p class="cmb-hint">' + esc(x.service) + ' — ' + esc(x.dateFa) + '، ' + esc(x.blockLabel) + '. جا تا ' +
        fa(Math.ceil(x.holdLeft / 60)) + ' دقیقه‌ی دیگر برایتان نگه داشته می‌شود.</p>' +
      '<div class="cmb-bk__acts cmb-mt3">' +
        '<button class="cmb-btn cmb-btn--sm cmb-btn--pri" data-pay-retry="' + x.id + '"' + (S.payBusy ? ' disabled' : '') + '>ادامه‌ی پرداخت</button>' +
        '<button class="cmb-btn cmb-btn--sm cmb-btn--soft" data-pay-abandon="' + x.id + '"' + (S.payBusy ? ' disabled' : '') + '>انصراف</button>' +
      '</div></div>';
  });

  // موجودی کیف پول: یادآوری که بیعانه‌ی نوبت بعدی با آن پرداخت می‌شود
  if (S.logged && S.wallet && S.wallet.spend && Number(S.wallet.balance) > 0) {
    html += '<button class="cmb-walletchip" data-tab="wallet">' + I.wallet +
      '<span>موجودی کیف پول: <b>' + money(S.wallet.balance) + ' تومان</b> — در پرداخت بیعانه‌ی نوبت بعدی استفاده می‌شود.</span>' + I.chevL + '</button>';
  }

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
      (s.test ? '<span class="cmb-testtag cmb-testtag--float">آزمایشی</span>' : '') +
      (on ? '<span class="cmb-svc__tick">' + I.check + '</span>' : '') +
      '</button>';
  }

  return '<button class="cmb-svc' + (on ? ' is-on' : '') + '" data-svc="' + s.id + '">' +
    '<div class="cmb-svc__body">' +
      '<div class="cmb-svc__head"><div class="cmb-svc__t">' + esc(s.title) + (s.test ? ' <span class="cmb-testtag">آزمایشی</span>' : '') + '</div>' +
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

/** آیا خدمت انتخاب‌شده بیعانه دارد؟ (گام پنجم: قوانین و پرداخت) */
function needsDeposit() {
  return !!(S.pay.on && S.service && Number(S.service.deposit) > 0);
}

function steps() {
  var total = needsDeposit() ? 5 : 4;
  var out = '<div class="cmb-steps">';
  for (var i = 1; i <= total; i++) {
    out += '<div class="cmb-steps__i' + (i <= S.step ? ' is-done' : '') + '"></div>';
  }
  out += '</div>';

  var names = { 1: 'انتخاب خدمت', 2: 'تاریخ و ساعت', 3: 'تایید شماره', 4: 'مشخصات خودرو', 5: 'قوانین و پرداخت' };
  out += '<div class="cmb-steps__lbl">گام ' + fa(S.step) + ' از ' + fa(total) + ' — ' + names[S.step] + '</div>';
  return out;
}

function viewBook() {
  if (S.receipt) { return viewReceipt(); }

  var head = topBar('رزرو نوبت', S.service ? S.service.title : '', true) + steps();

  if (S.step === 1) { return head + stepService(); }
  if (S.step === 2) { return head + stepSlot(); }
  if (S.step === 3) { return head + stepAuth(); }
  if (S.step === 5 && needsDeposit()) { return head + stepPay(); }
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
        '<div class="cmb-chosen__t">' + esc(s.title) + (s.test ? ' <span class="cmb-testtag">آزمایشی</span>' : '') + '</div>' +
      '</div>' +
      '<button class="cmb-chosen__edit" data-change-svc>تغییر</button>' +
    '</div>' +
    ((s.priceLabel || s.duration || s.weekdayText)
      ? '<div class="cmb-chosen__meta">' +
          (s.priceLabel ? '<div class="cmb-chosen__row">' + I.tag + esc(s.priceLabel) + '</div>' : '') +
          (s.duration ? '<div class="cmb-chosen__row">' + I.clock + esc(s.duration) + '</div>' : '') +
          (s.weekdayText ? '<div class="cmb-chosen__row">' + I.cal + esc(s.weekdayText) + '</div>' : '') +
          (S.pay.on && s.deposit > 0 ? '<div class="cmb-chosen__row">' + I.card + 'بیعانه ' + esc(s.depositLabel) + ' — از هزینه کسر می‌شود</div>' : '') +
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
      '<div class="cmb-box__t">' + esc(s.title) + (s.test ? ' <span class="cmb-testtag">آزمایشی</span>' : '') + '</div>' +
      (s.priceLabel ? '<div class="cmb-box__price">' + esc(s.priceLabel) + '</div>' : '') +
    '</div>' +
    (s.description ? '<p class="cmb-box__desc">' + esc(s.description) + '</p>' : '') +
    ((s.duration || s.weekdayText)
      ? '<div class="cmb-box__meta">' +
          (s.duration ? '<div class="cmb-box__row">' + I.clock + esc(s.duration) + '</div>' : '') +
          (s.weekdayText ? '<div class="cmb-box__row">' + I.cal + esc(s.weekdayText) + '</div>' : '') +
          (S.pay.on && s.deposit > 0 ? '<div class="cmb-box__row">' + I.card + 'بیعانه ' + esc(s.depositLabel) + '</div>' : '') +
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

  html += pendingCard();

  html += '<div class="cmb-actionbar"><div class="cmb-actionbar__in">' +
    '<button class="cmb-btn cmb-btn--pri" style="width:100%" data-submit' + (S.busy ? ' disabled' : '') + '>' +
    (S.busy ? '<span class="cmb-spin"></span> در حال ثبت…' : (needsDeposit() ? 'ادامه — قوانین و پرداخت' : 'ثبت نهایی نوبت')) + '</button>' +
    '</div></div>';

  return html;
}

/* ═══ قوانین و پرداخت بیعانه ═══ */

/**
 * کارت «نوبت در انتظار پرداخت دارید».
 *
 * وقتی سرور ثبت تازه را به‌خاطر یک نوبتِ پرداخت‌نشده رد می‌کند،
 * مشتری باید بتواند همان را تمام کند یا رهایش کند — نه اینکه گیر کند.
 */
function pendingCard() {
  var p = S.pendingBlock;
  if (!p) { return ''; }

  return '<div class="cmb-card cmb-paynote cmb-mt3">' +
    '<div class="cmb-svc__t">' + I.clock + ' نوبت پرداخت‌نشده</div>' +
    '<p class="cmb-hint">' + esc(p.message) + '</p>' +
    '<div class="cmb-bk__acts cmb-mt3">' +
      '<button class="cmb-btn cmb-btn--sm cmb-btn--pri" data-pay-retry="' + p.id + '" data-token="' + esc(p.token) + '"' + (S.payBusy ? ' disabled' : '') + '>ادامه‌ی پرداخت آن نوبت</button>' +
      '<button class="cmb-btn cmb-btn--sm cmb-btn--ghostdanger" data-pay-abandon="' + p.id + '" data-token="' + esc(p.token) + '"' + (S.payBusy ? ' disabled' : '') + '>انصراف از آن نوبت</button>' +
    '</div></div>';
}

/** متن قوانین: هر سطر یک بند (شماره‌گذاری همان است که مدیر نوشته). */
function termsHtml(text) {
  return '<div class="cmb-terms">' + String(text || '').split(/\n+/).filter(function (l) { return l.trim(); }).map(function (l) {
    return '<p>' + esc(l) + '</p>';
  }).join('') + '</div>';
}

/**
 * سهم کیف پول در همین بیعانه، با توجه به تیک «پرداخت از کیف پول».
 * عددها از سرور است (quote.wallet)؛ این فقط انتخاب مشتری را اعمال می‌کند.
 */
function walletPart(q) {
  var w = q && q.wallet;
  var use = (w && S.useWallet) ? Number(w.use || 0) : 0;

  return {
    use: use,
    gateway: use > 0 ? Number(w.gateway || 0) : Number(q ? q.deposit : 0),
    full: use > 0 && Number(w.gateway || 0) === 0
  };
}

function stepPay() {
  var bl = (S.day && S.day.blocks || []).filter(function (x) { return x.key === S.block; })[0] || {};
  var q = S.quote;

  var html = '<div class="cmb-page cmb-mt4">';

  html += '<div class="cmb-card cmb-card--flat">' +
    kv('خدمت', S.service ? S.service.title : '') +
    kv('تاریخ', S.day ? S.day.jalaliFull : '') +
    kv('ساعت', (bl.label || '') + ' — ' + fa(bl.start || '')) +
    '</div>';

  if (!q) {
    html += '<div class="cmb-sk cmb-sk--card"></div></div>';
    return html;
  }

  var w = walletPart(q);

  html += '<div class="cmb-card cmb-deposit">' +
    '<div class="cmb-deposit__amount"><span>بیعانه‌ی رزرو</span><b>' + esc(q.depositFa) + '</b></div>' +
    (q.priceFa ? kv('هزینه‌ی خدمت', q.priceFa) + kv('باقی‌مانده هنگام مراجعه', q.remainingFa) : '') +
    '<div class="cmb-deposit__rows">' +
      '<div class="cmb-deposit__row ok">' + I.check + '<div>لغو تا <b>' + esc(q.cancelUntilFa) + '</b>: ' + esc(q.refundFa) +
        ' همان لحظه به کیف پولتان در همین سایت برمی‌گردد.</div></div>' +
      '<div class="cmb-deposit__row bad">' + I.alert + '<div>بعد از آن لغو آنلاین ممکن نیست؛ اگر نیایید کل بیعانه نزد مجموعه می‌ماند.</div></div>' +
    '</div>' +
    '</div>';

  // کیف پول: تیک استفاده، و تقسیم بیعانه بین کیف پول و درگاه
  if (q.wallet && Number(q.wallet.balance) > 0) {
    html += '<div class="cmb-card cmb-wuse">' +
      '<label class="cmb-check cmb-check--bar"><input type="checkbox" id="cmb-usewallet"' + (S.useWallet ? ' checked' : '') + '>' +
      '<span>پرداخت از کیف پول <small>(موجودی ' + esc(q.wallet.balanceFa) + ')</small></span></label>' +
      (w.use > 0
        ? '<div class="cmb-wuse__split">' +
            kv(w.full ? 'کل بیعانه از کیف پول' : 'از کیف پول', q.wallet.useFa) +
            (w.gateway > 0 ? kv('با درگاه', q.wallet.gatewayFa) : '') +
          '</div>'
        : '') +
      '</div>';
  }

  // تیک پذیرش در نوار ثابت پایین است (کنار دکمه‌ی پرداخت)، نه اینجا
  html += '<div class="cmb-card">' +
    '<div class="cmb-svc__t">قوانین و مقررات رزرو</div>' +
    termsHtml(q.terms) +
    '</div>';

  if (S.pay.sandbox) {
    html += note('warn', I.alert, 'درگاه در حالت آزمایشی است و پول واقعی جابه‌جا نمی‌شود.');
  }

  html += note('info', I.info, w.full
    ? 'با زدن دکمه، بیعانه از کیف پولتان کم می‌شود و نوبت همین حالا ثبت می‌شود؛ به درگاه نمی‌روید.'
    : 'پس از پرداخت به همین صفحه برمی‌گردید. جا تا ' + fa(q.holdMinutes) + ' دقیقه برای پرداخت شما نگه داشته می‌شود.');
  html += pendingCard();
  html += '</div>';

  var label = w.full ? 'پرداخت ' + esc(q.wallet.useFa) + ' از کیف پول'
    : 'پرداخت ' + esc(w.use > 0 ? q.wallet.gatewayFa : q.depositFa);

  /* تیک پذیرش و دکمه‌ی پرداخت با هم در نوار ثابت پایین‌اند: مشتری بدون
     اسکرول تیک می‌زند و پرداخت می‌کند. «مشاهده‌ی قوانین» متن کامل را
     در یک برگه باز می‌کند و همان‌جا هم می‌شود پذیرفت. */
  html += '<div class="cmb-actionbar cmb-actionbar--terms"><div class="cmb-actionbar__in">' +
    '<div class="cmb-termsrow">' +
      '<label class="cmb-check cmb-check--bar"><input type="checkbox" id="cmb-terms"' + (S.termsOk ? ' checked' : '') + '>' +
      '<span>قوانین رزرو را خواندم و می‌پذیرم</span></label>' +
      '<button type="button" class="cmb-link" data-terms-open>مشاهده‌ی قوانین</button>' +
    '</div>' +
    '<button class="cmb-btn cmb-btn--pri" style="width:100%" data-pay' + (S.busy || !S.termsOk || S.redirecting ? ' disabled' : '') + '>' +
    (S.redirecting ? '<span class="cmb-spin"></span> در حال انتقال به درگاه…'
      : (S.busy ? '<span class="cmb-spin"></span> در حال ثبت…' : label)) + '</button>' +
    '</div></div>';

  return html;
}

/** برگه‌ی متن کامل قوانین، با دکمه‌ی پذیرش. */
function termsSheet() {
  var q = S.quote;
  if (S.sheet !== 'terms' || !q) { return ''; }

  return '<div class="cmb-scrim" data-sheet-close></div>' +
    '<div class="cmb-sheet cmb-sheet--terms" role="dialog" aria-modal="true" aria-label="قوانین و مقررات رزرو">' +
      '<div class="cmb-sheet__body">' +
        '<div class="cmb-sheet__grip"></div>' +
        '<div class="cmb-sheet__t">قوانین و مقررات رزرو</div>' +
        termsHtml(q.terms) +
      '</div>' +
      // دکمه‌ها بیرون از بخش اسکرولی‌اند: با متن بلند هم همیشه پایین برگه دیده می‌شوند
      '<div class="cmb-sheet__acts">' +
        '<button class="cmb-btn cmb-btn--pri" data-terms-accept>' + (S.termsOk ? 'تأیید' : 'می‌پذیرم') + '</button>' +
        '<button class="cmb-btn cmb-btn--ghost" data-sheet-close>بستن</button>' +
      '</div>' +
    '</div>';
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
        (r.pay && r.pay.status === 'paid' ? kv('بیعانه‌ی پرداخت‌شده', r.pay.depositFa) : '') +
        (r.pay && r.pay.status === 'paid' && r.pay.wallet > 0 ? kv('از کیف پول', r.pay.walletFa) : '') +
        (r.pay && r.pay.status === 'paid' && r.pay.wallet > 0 && r.pay.gateway > 0 ? kv('با درگاه', r.pay.gatewayFa) : '') +
        (r.pay && r.pay.status === 'paid' && r.pay.remainingFa ? kv('باقی‌مانده هنگام مراجعه', r.pay.remainingFa) : '') +
        (r.refId ? kv('شماره پیگیری پرداخت', fa(r.refId)) : '') +
      '</div>' +
    '</div>';

  if (r.pay && r.pay.status === 'paid' && r.cancelHint) { html += note('info', I.info, fa(r.cancelHint)); }
  if (r.note) { html += note('warn', I.alert, r.note); }
  if (r.lateRule) { html += note('info', I.clock, r.lateRule); }

  html += '<button class="cmb-btn cmb-btn--dark cmb-mt5" data-tab="mine">دیدن نوبت‌های من</button>' +
    '<button class="cmb-btn cmb-btn--ghost cmb-mt3" data-tab="home">بازگشت به صفحه اصلی</button>' +
    '</div>';

  return html;
}

/* ═══ نتیجه‌ی پرداخت ═══ */

/**
 * صفحه‌ی بازگشت از درگاه. وضعیت را سرور همراه صفحه فرستاده؛ این
 * صفحه ممکن است بی‌کوکی باز شده باشد، پس به ورود تکیه نمی‌کند.
 */
function viewPay() {
  var R = S.payResult || { state: 'invalid', message: 'این نشانی معتبر نیست.' };
  var b = R.booking || null;

  if (R.state === 'paid' && b) {
    var save = S.receipt;
    S.receipt = Object.assign({}, b, { refId: R.refId || '' });
    var html = viewReceipt();
    S.receipt = save;
    return topBar('نتیجه‌ی پرداخت', '', false) + html;
  }

  var icon = I.alert, tone = 'bad', title = 'پرداخت انجام نشد';
  var bar = '';

  if (R.state === 'checking') { icon = I.clock; tone = 'wait'; title = 'در انتظار نتیجه‌ی پرداخت'; }
  if (R.state === 'refund') { icon = I.card; tone = 'wait'; title = 'پرداخت رسید ولی نوبت ثبت نشد'; }
  if (R.state === 'expired') { title = 'نوبت ثبت نشد'; }
  if (R.state === 'invalid' || R.state === 'closed') { icon = I.info; tone = 'wait'; title = 'وضعیت نوبت'; }

  var out = topBar('نتیجه‌ی پرداخت', '', false) + '<div class="cmb-page cmb-mt5">' +
    '<div class="cmb-card cmb-center cmb-payres cmb-payres--' + tone + '">' +
      '<div class="cmb-payres__ic">' + icon + '</div>' +
      '<div class="cmb-svc__t">' + esc(title) + '</div>' +
      '<p class="cmb-hint">' + esc(R.message || '') + '</p>' +
    '</div>';

  if (b) {
    out += '<div class="cmb-card cmb-card--flat">' +
      kv('خدمت', b.service) +
      kv('تاریخ', b.dateFa) +
      kv('ساعت', b.blockLabel + ' — ' + fa(b.blockStart)) +
      (b.pay ? kv('بیعانه', b.pay.depositFa) : '') +
      kv('کد پیگیری', b.code) +
      '</div>';
  }

  if (R.state === 'failed' && b) {
    var left = Math.ceil((R.holdLeft || 0) / 60);

    if (left > 0) { out += note('info', I.clock, 'جا تا ' + fa(left) + ' دقیقه‌ی دیگر برایتان نگه داشته می‌شود.'); }

    out += '<button class="cmb-btn cmb-btn--ghost cmb-mt4" data-pay-abandon="' + R.id + '" data-token="' + esc(R.token) + '"' + (S.payBusy ? ' disabled' : '') + '>انصراف و آزاد کردن جا</button>';

    // «پرداخت دوباره» در نوار ثابت پایین، بالای نوار ناوبری
    if (R.canRetry) {
      bar = '<div class="cmb-actionbar cmb-actionbar--nav"><div class="cmb-actionbar__in">' +
        '<div class="cmb-actionbar__sum"><div class="cmb-actionbar__k">مبلغ پرداخت</div>' +
        '<div class="cmb-actionbar__v">' + esc(b.pay ? (b.pay.gateway > 0 ? b.pay.gatewayFa : b.pay.depositFa) : '') + '</div></div>' +
        '<button class="cmb-btn cmb-btn--pri" data-pay-retry="' + R.id + '" data-token="' + esc(R.token) + '"' + (S.payBusy || S.redirecting ? ' disabled' : '') + '>' +
          (S.redirecting ? '<span class="cmb-spin"></span> انتقال به درگاه…' : 'پرداخت دوباره') + '</button>' +
        '</div></div>';
    }
  } else if (R.state === 'checking') {
    out += '<button class="cmb-btn cmb-btn--pri cmb-mt4" data-reload>بررسی دوباره</button>';
  } else {
    out += '<button class="cmb-btn cmb-btn--pri cmb-mt4" data-start>نوبت تازه</button>';

    if (R.state === 'refund') {
      out += '<button class="cmb-btn cmb-btn--soft cmb-mt3" data-tab="wallet">' + I.wallet + ' دیدن کیف پول</button>';
    }
  }

  out += '<button class="cmb-btn cmb-btn--ghost cmb-mt3" data-tab="mine">نوبت‌های من</button></div>';
  return out + bar;
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
      '<div class="cmb-bk__ask">' + (b.pay && b.pay.status === 'paid'
        ? 'این نوبت لغو شود؟ ' + esc(b.pay.cancelRefundFa) + ' از بیعانه همان لحظه به کیف پولتان برمی‌گردد (برای بیعانه‌ی نوبت بعدی؛ قابل برداشت به کارت نیست). بقیه طبق قوانین نزد مجموعه می‌ماند. لغو برگشت‌پذیر نیست.'
        : 'این نوبت لغو شود؟ ظرفیت آزاد می‌شود و برگشت‌پذیر نیست.') + '</div>' +
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

/** وضعیت بیعانه زیر هر نوبت. */
function payLine(b) {
  var p = b.pay;
  if (!p || !p.deposit) { return ''; }

  var txt = 'بیعانه ' + p.depositFa;

  if (p.status === 'paid') {
    txt += ' — پرداخت شد' + (p.wallet > 0 ? ' (' + (p.gateway > 0 ? p.walletFa + ' از کیف پول' : 'از کیف پول') + ')' : '') +
      (p.remainingFa ? '؛ باقی‌مانده هنگام مراجعه ' + p.remainingFa : '');
  }
  else if (p.status === 'refund_due' || p.status === 'refunding') { txt += ' — ' + p.refundFa + ' به کیف پول شما برمی‌گردد'; }
  else if (p.status === 'refunded') {
    txt += String(p.statusLabel || '').indexOf('کیف پول') !== -1
      ? ' — ' + p.refundFa + ' به کیف پول شما برگشت'
      : ' — ' + p.refundFa + ' به کارت شما بازگردانده شد';
  }
  else if (p.status === 'kept') { txt += ' — نزد مجموعه ماند'; }
  else if (b.status === 'pending') { txt += ' — هنوز پرداخت نشده'; }
  else { return ''; }

  return '<div class="cmb-bk__row">' + I.card + esc(txt) + '</div>';
}

/** نوبتِ در انتظار پرداخت: ادامه‌ی پرداخت یا انصراف. */
function pendingBox(b) {
  if (b.status !== 'pending') { return ''; }

  var left = Math.ceil((b.holdLeft || 0) / 60);
  var busy = S.payBusy === b.id;

  return '<div class="cmb-bk__foot">' +
    '<span class="cmb-bk__hint">' + (left > 0 ? 'جا تا ' + fa(left) + ' دقیقه‌ی دیگر برایتان نگه داشته می‌شود.' : 'مهلت پرداخت رو به پایان است.') + '</span>' +
    '<div class="cmb-bk__acts">' +
      '<button class="cmb-btn cmb-btn--sm cmb-btn--pri"' + (busy ? ' disabled' : '') + ' data-pay-retry="' + b.id + '">' + (busy ? '…' : 'ادامه‌ی پرداخت') + '</button>' +
      '<button class="cmb-btn cmb-btn--sm cmb-btn--soft"' + (busy ? ' disabled' : '') + ' data-pay-abandon="' + b.id + '">انصراف</button>' +
    '</div></div>';
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
        payLine(b) +
      '</div>' +
      pendingBox(b) +
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
    row(I.list, 'نوبت‌های من', fa(S.bookings.length) + ' مورد', 'tab', 'mine') +
    (walletShown() ? row(I.wallet, 'کیف پول', S.wallet ? money(S.wallet.balance) + ' تومان' : '', 'tab', 'wallet') : '');

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

/* ═══ کیف پول ═══ */

function walletShown() {
  return !!(S.wallet && S.wallet.show);
}

function topupCard() {
  var R = S.topupResult;
  if (!R) { return ''; }

  var tone = R.state === 'paid' ? 'ok' : ((R.state === 'checking' || R.state === 'invalid') ? 'wait' : 'bad');
  var icon = R.state === 'paid' ? I.ok : (R.state === 'checking' ? I.clock : (R.state === 'invalid' ? I.info : I.alert));
  var title = { paid: 'کیف پول شارژ شد', checking: 'در انتظار نتیجه‌ی پرداخت', invalid: 'نتیجه‌ی شارژ', failed: 'شارژ انجام نشد' }[R.state] || 'نتیجه‌ی شارژ';

  return '<div class="cmb-card cmb-center cmb-payres cmb-payres--' + tone + '">' +
    '<div class="cmb-payres__ic">' + icon + '</div>' +
    '<div class="cmb-svc__t">' + esc(title) + '</div>' +
    '<p class="cmb-hint">' + esc(R.message || '') + '</p>' +
    (R.state === 'paid' && R.balanceFa ? '<p class="cmb-hint">موجودی تازه: <b>' + esc(R.balanceFa) + '</b></p>' : '') +
    (R.refId ? '<p class="cmb-hint">شماره پیگیری پرداخت: <span class="cmb-num">' + fa(esc(R.refId)) + '</span></p>' : '') +
    (R.state === 'checking' ? '<button class="cmb-btn cmb-btn--pri cmb-mt3" data-reload>بررسی دوباره</button>' : '') +
    '<button class="cmb-btn cmb-btn--ghost cmb-mt3" data-topup-close>بستن</button>' +
    '</div>';
}

/** یک ردیف تاریخچه: عنوان، تاریخ، مبلغ با علامت. */
function walletRow(x) {
  return '<div class="cmb-wtx' + (x.status === 'held' ? ' is-held' : '') + '">' +
    '<div class="cmb-wtx__ic ' + (x.in ? 'is-in' : 'is-out') + '">' + (x.type === 'topup' ? I.plus : (x.in ? I.check : I.card)) + '</div>' +
    '<div class="cmb-wtx__main">' +
      '<div class="cmb-wtx__t">' + esc(x.title) + '</div>' +
      '<div class="cmb-wtx__s"><span class="cmb-num">' + fa(esc(x.dateFa)) + '</span>' +
        (x.statusLabel ? ' · ' + esc(x.statusLabel) : '') +
        (x.note ? ' · ' + esc(x.note) : '') + '</div>' +
    '</div>' +
    '<div class="cmb-wtx__a ' + (x.in ? 'is-in' : 'is-out') + '"><b dir="ltr">' + (x.in ? '+' : '−') + money(Math.abs(Number(x.amount))) + '</b><small>تومان</small></div>' +
    '</div>';
}

function viewWallet() {
  var W = S.wallet || {};
  var bar = '';
  var html = topBar('کیف پول', '', false) + '<div class="cmb-page cmb-mt4">';

  html += topupCard();

  if (!S.logged || !S.hasPhone) {
    html += '<div class="cmb-card cmb-center">' +
      '<div class="cmb-empty__ic" style="margin:0 auto var(--s3)">' + I.wallet + '</div>' +
      '<div class="cmb-svc__t">کیف پول</div>' +
      '<p class="cmb-hint">مبلغ برگشتیِ لغو نوبت به کیف پول شما در همین سایت می‌رود و با آن بیعانه‌ی نوبت بعدی را می‌پردازید.</p>' +
      '</div>' +
      authCard('برای دیدن موجودی کیف پول با شماره‌تان وارد شوید.') + '</div>';
    return html;
  }

  if (!S.wallet && S.walletLoading) {
    return html + '<div class="cmb-sk cmb-sk--card"></div><div class="cmb-sk cmb-sk--card"></div></div>';
  }

  html += '<div class="cmb-wallet">' +
    '<div class="cmb-wallet__k">' + I.wallet + ' موجودی کیف پول</div>' +
    '<div class="cmb-wallet__v"><b class="cmb-num">' + money(W.balance) + '</b> <small>تومان</small></div>' +
    '<div class="cmb-wallet__s">' + (W.spend
      ? 'در پرداخت بیعانه‌ی نوبت بعدی خودکار استفاده می‌شود؛ اگر کافی نبود، باقی‌مانده با درگاه.'
      : 'پرداخت بیعانه فعلاً خاموش است؛ موجودی سر جایش می‌ماند.') + '</div>' +
    (Number(W.held) > 0 ? '<div class="cmb-wallet__held">' + I.clock + '<span>' + esc(W.heldFa) + ' برای نوبتِ در انتظار پرداخت کنار گذاشته شده</span></div>' : '') +
    '</div>';

  if (W.topup && W.topup.on) {
    var t = W.topup;
    var amt = Number(S.topupAmount || 0);

    html += '<div class="cmb-card cmb-mt4">' +
      '<div class="cmb-svc__t cmb-flex">' + I.plus + ' شارژ کیف پول</div>' +
      '<div class="cmb-chips cmb-mt3">' + (t.presets || []).map(function (v) {
        return '<button type="button" class="cmb-chip' + (amt === v ? ' is-on' : '') + '" data-topup-preset="' + v + '">' + money(v) + ' تومان</button>';
      }).join('') + '</div>' +
      '<label class="cmb-field cmb-mt3"><span class="cmb-field__l">یا مبلغ دلخواه (تومان)</span>' +
      '<input class="cmb-in cmb-num" id="cmb-topup" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="' + esc(String(t.min)) + '" value="' + (amt ? esc(String(amt)) : '') + '"></label>' +
      '<div class="cmb-hint">از ' + esc(t.minFa) + ' تا ' + esc(t.maxFa) + '. پس از پرداخت به همین صفحه برمی‌گردید.</div>' +
      '</div>';

    // دکمه‌ی پرداخت در نوار ثابت پایین، بالای نوار ناوبری
    bar = '<div class="cmb-actionbar cmb-actionbar--nav"><div class="cmb-actionbar__in">' +
      '<div class="cmb-actionbar__sum"><div class="cmb-actionbar__k">مبلغ شارژ</div>' +
      '<div class="cmb-actionbar__v" id="cmb-topup-v">' + topupAmountText() + '</div></div>' +
      '<button class="cmb-btn cmb-btn--pri" data-topup' + (S.topupBusy || S.redirecting ? ' disabled' : '') + '>' +
        topupLabel() + '</button>' +
      '</div></div>';

    if (S.pay.sandbox) { html += note('warn', I.alert, 'درگاه در حالت آزمایشی است و پول واقعی جابه‌جا نمی‌شود.'); }
  }

  html += '<div class="cmb-sec"><div class="cmb-sec__t">تاریخچه</div></div>';

  var hist = W.history || [];

  html += hist.length
    ? '<div class="cmb-wtxs">' + hist.map(walletRow).join('') + '</div>'
    : empty(I.wallet, 'هنوز تراکنشی ندارید', 'مبلغ برگشتیِ لغو نوبت‌ها و شارژهای شما این‌جا می‌آید.');

  html += note('info', I.info, 'موجودی کیف پول قابل برداشت یا انتقال به کارت بانکی نیست و فقط برای پرداخت بیعانه‌ی نوبت‌های بعدی در همین سایت استفاده می‌شود.');
  html += '</div>';

  return html + bar;
}

function topupLabel() {
  if (S.redirecting) { return '<span class="cmb-spin"></span> انتقال به درگاه…'; }
  if (S.topupBusy) { return '<span class="cmb-spin"></span> …'; }

  return 'شارژ با درگاه';
}

/** مبلغ انتخاب‌شده‌ی شارژ، برای نوار پایین. */
function topupAmountText() {
  return S.topupAmount ? money(S.topupAmount) + ' تومان' : 'مبلغ را انتخاب کنید';
}

/** شارژ: درخواست به سرور و رفتن به درگاه. */
function topupStart() {
  var t = (S.wallet && S.wallet.topup) || null;
  var amount = Number(S.topupAmount || 0);

  if (!t || S.topupBusy || S.redirecting) { return; }

  if (!amount) { toast('مبلغ شارژ را انتخاب یا وارد کنید.', 'bad'); return; }

  if (amount < t.min || amount > t.max) {
    toast('مبلغ شارژ باید بین ' + t.minFa + ' و ' + t.maxFa + ' باشد.', 'bad');
    return;
  }

  S.topupBusy = true;
  paint();

  post('wallet/topup', { amount: amount }).then(function (r) {
    if (r.success === false || !r.url) {
      S.topupBusy = false;
      toast((r && r.message) || 'انتقال به درگاه ممکن نشد.', 'bad');
      paint();
      return;
    }

    S.redirecting = true;
    paint();
    location.href = r.url;
  });
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

/**
 * ارتفاع واقعی نوار ثابت پایین را در --bar-full می‌گذارد تا فاصله‌ی پایین
 * صفحه و جای پیام‌ها با آن بخواند. نوار حالا دو ردیف است و بسته به عرض
 * گوشی (برچسب دو خطی) یا اندازه‌ی متن آیفون ارتفاعش فرق می‌کند؛ عدد ثابت
 * یا محتوا را زیر نوار گم می‌کند یا فاصله‌ی اضافه می‌گذارد.
 */
function syncBar() {
  var de = document.documentElement;
  var bar = document.querySelector('#cmb-app > .cmb-actionbar');

  if (bar) {
    de.style.setProperty('--bar-full', Math.ceil(bar.getBoundingClientRect().height) + 'px');
  } else {
    de.style.removeProperty('--bar-full');
  }
}

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
    case 'wallet': inner = viewWallet(); break;
    case 'pay':  inner = viewPay(); break;
    default:     inner = viewHome();
  }

  // فاصله‌ی پایین باید با نواری که واقعاً روی صفحه هست بخواند،
  // وگرنه آخرین بخش محتوا زیر نوار ثابت گم می‌شود.
  var wizard = (S.page === 'book' && !S.receipt);
  var bar = inner.indexOf('cmb-actionbar') !== -1;
  var shellClass = 'cmb-shell';

  if (wizard) {
    shellClass += bar ? ' cmb-shell--bar' : ' cmb-shell--bare';
  } else if (bar) {
    // نوار پرداخت بالای نوار ناوبری (کیف پول، پرداخت ناموفق)
    shellClass += ' cmb-shell--navbar';
  }

  root.classList.toggle('has-navbar', !wizard && bar);

  root.innerHTML = '<div class="' + shellClass + '">' + inner + '</div>' +
    (wizard ? '' : nav()) +
    (S.lightbox
      ? '<div class="cmb-lightbox" data-zoom-close>' +
          '<button class="cmb-lightbox__x" data-zoom-close>✕</button>' +
          '<img src="' + esc(S.lightbox) + '" alt="">' +
        '</div>'
      : '') +
    termsSheet() +
    '<div id="cmb-toasts" class="cmb-toasts"></div>';

  /* نوار پرداخت از پوسته بیرون می‌آید و فرزند مستقیم ریشه می‌شود (کنار
     نوار ناوبری)، تا هیچ والدی جای «ثابت» بودنش را عوض نکند و دکمه‌ی
     پرداخت همیشه پایین صفحه‌ی دید بماند، بی‌اسکرول. */
  var ab = root.querySelector('.cmb-shell .cmb-actionbar');

  if (ab) {
    root.insertBefore(ab, root.querySelector('.cmb-shell').nextSibling);
  }

  syncBar();

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

    if (r.wallet) { S.wallet = r.wallet; S.walletFresh = true; }

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

  if (page === 'wallet') {
    if (!S.logged) { paint(); return; }

    S.walletLoading = !S.walletFresh;
    paint();

    get('wallet').then(function (r) {
      S.walletLoading = false;
      if (r.success !== false && r.wallet) { S.wallet = r.wallet; S.walletFresh = true; }
      paint();
    });
    return;
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
          if (r.wallet) { S.wallet = r.wallet; S.walletFresh = true; }
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
  S.sheet = null;
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

  // با بیعانه: اول قوانین و مبلغ، بعد ثبت و انتقال به درگاه
  if (needsDeposit()) {
    goStep(5);
    loadQuote();
    return;
  }

  sendBooking({});
}

/** مبلغ و قوانین همین شیفت. */
function loadQuote(force) {
  var key = S.service.id + '|' + S.day.date + '|' + S.block;

  if (!force && S.quote && S.quoteFor === key) { return; }

  S.quote = null;
  S.quoteFor = key;
  S.termsOk = false;
  S.sheet = null;
  S.useWallet = true;
  paint();

  get('bookings/quote?service_id=' + S.service.id + '&date=' + encodeURIComponent(S.day.date) + '&block=' + encodeURIComponent(S.block)).then(function (r) {
    // این خدمت دیگر بیعانه ندارد (تنظیمات همین حالا عوض شده): ثبت رایگان
    if (r.success !== false && !r.quote) {
      S.service.deposit = 0;
      S.quoteFor = '';
      goStep(4);
      sendBooking({});
      return;
    }

    if (r.success === false) {
      toast(r.message || 'دریافت قوانین رزرو ناموفق بود.', 'bad');
      S.quoteFor = '';
      goStep(4);
      return;
    }

    S.quote = r.quote;
    paint();
  });
}

function payNow() {
  if (!S.quote || !S.termsOk || S.busy || S.redirecting) { return; }

  var w = walletPart(S.quote);

  sendBooking({
    accept_terms: 1,
    terms_hash: S.quote.hash,
    deposit_seen: S.quote.deposit,
    // همان تقسیمی که مشتری دید؛ اگر موجودی عوض شده، سرور تقسیم تازه را برمی‌گرداند
    use_wallet: w.use > 0 ? 1 : 0,
    wallet_seen: w.use
  });
}

/** ثبت نوبت؛ با بیعانه، پاسخ نشانی درگاه را دارد. */
function sendBooking(extra) {
  var f = S.form;

  S.busy = true;
  S.pendingBlock = null;
  paint();

  var body = {
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
  };

  Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });

  post('bookings', body).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      // قوانین یا مبلغ همین حالا عوض شده: نسخه‌ی تازه، تیک دوباره
      if (r.code === 'cmb_terms_changed' || r.code === 'cmb_terms_required') {
        if (r.data && r.data.quote) {
          S.quote = r.data.quote;
          S.quoteFor = S.service.id + '|' + S.day.date + '|' + S.block;
          // فهرست خدماتِ کش‌شده هنوز «بدون بیعانه» می‌گفت
          S.pay.on = true;
          S.service.deposit = r.data.quote.deposit;
        }
        S.termsOk = false;
        toast(r.message, 'bad');
        if (S.step !== 5) { goStep(5); } else { paint(); }
        return;
      }

      // موجودی کیف پول بین دیدن و زدن دکمه عوض شد: تقسیم تازه
      if (r.code === 'cmb_wallet_changed' && r.data && r.data.quote) {
        S.quote = r.data.quote;
        S.quoteFor = S.service.id + '|' + S.day.date + '|' + S.block;
        S.walletFresh = false;
        toast(r.message, 'bad');
        paint();
        return;
      }

      if (r.code === 'cmb_pending_exists' && r.data && r.data.pending) {
        S.pendingBlock = r.data.pending;
        S.pendingBlock.message = r.message;
        paint();
        return;
      }

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

    // بیعانه: نوبت «در انتظار پرداخت» ثبت شد؛ برو به درگاه
    if (r.payment && r.payment.url) {
      S.redirecting = true;
      S.bookingsFresh = false;
      paint();
      location.href = r.payment.url;
      return;
    }

    S.receipt = r.booking;
    S.bookings = [];
    S.walletFresh = false;
    if (S.wallet && r.booking && r.booking.pay && r.booking.pay.wallet > 0) {
      S.wallet.balance = Math.max(0, Number(S.wallet.balance) - Number(r.booking.pay.wallet));
    }
    toast('نوبت شما با موفقیت ثبت شد.', 'ok');
    window.scrollTo(0, 0);
    paint();
  });
}

/**
 * پرداخت دوباره‌ی نوبتی که در انتظار است. با توکن (صفحه‌ی نتیجه که
 * شاید بی‌کوکی باز شده) یا بی‌توکن (نوبت‌های من؛ مالکیت از ورود).
 */
function payRetry(id, token) {
  if (S.payBusy) { return; }

  S.payBusy = id;
  paint();

  post('bookings/pay', { id: id, token: token || '' }).then(function (r) {
    if (r.success === false) {
      S.payBusy = 0;
      toast(r.message || 'انتقال به درگاه ممکن نشد.', 'bad');
      S.bookingsFresh = false;
      if (S.page === 'mine') { load('mine'); } else { paint(); }
      return;
    }

    if (r.url) {
      S.redirecting = true;
      paint();
      location.href = r.url;
      return;
    }

    // در همین فاصله پول رسید و نوبت ثبت شد
    S.payBusy = 0;
    toast('پرداخت این نوبت انجام شده و نوبت ثبت است.', 'ok');
    if (S.page === 'pay') { location.reload(); return; }
    S.pendingBlock = null;
    S.bookingsFresh = false;
    go('mine');
  });
}

function payAbandon(id, token) {
  if (S.payBusy) { return; }
  if (!window.confirm('از پرداخت این نوبت انصراف می‌دهید؟ جای رزروشده آزاد می‌شود.')) { return; }

  S.payBusy = id;
  paint();

  post('bookings/abandon', { id: id, token: token || '' }).then(function (r) {
    S.payBusy = 0;

    if (r.success === false) {
      toast(r.message || 'انجام نشد. دوباره تلاش کنید.', 'bad');
      paint();
      return;
    }

    if (S.page === 'pay') {
      S.payResult = r.state;
      paint();
      return;
    }

    if (S.pendingBlock && S.pendingBlock.id === id) {
      S.pendingBlock = null;
      toast('انصراف ثبت شد. حالا می‌توانید نوبت تازه را ثبت کنید.', 'ok');
      paint();
      return;
    }

    toast(r.state && r.state.state === 'paid' ? 'پرداخت این نوبت پیش‌تر انجام شده بود و نوبت ثبت است.' : 'انصراف ثبت شد و جا آزاد شد.', 'ok');
    S.bookingsFresh = false;
    load('mine');
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

    if (up('[data-pay]')) { e.preventDefault(); payNow(); return; }

    if ((el = up('[data-pay-retry]'))) {
      e.preventDefault();
      payRetry(Number(el.getAttribute('data-pay-retry')), el.getAttribute('data-token') || '');
      return;
    }

    if ((el = up('[data-pay-abandon]'))) {
      e.preventDefault();
      payAbandon(Number(el.getAttribute('data-pay-abandon')), el.getAttribute('data-token') || '');
      return;
    }

    if (up('[data-reload]')) { e.preventDefault(); location.reload(); return; }

    if ((el = up('[data-topup-preset]'))) {
      e.preventDefault();
      S.topupAmount = Number(el.getAttribute('data-topup-preset'));
      paint();
      return;
    }

    if (up('[data-topup]')) { e.preventDefault(); topupStart(); return; }

    if (up('[data-topup-close]')) {
      e.preventDefault();
      S.topupResult = null;
      if (history.replaceState) { history.replaceState({ page: 'wallet' }, '', BASE + 'wallet'); }
      paint();
      return;
    }

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

    // برگه‌ی متن کامل قوانین
    if (up('[data-terms-open]')) { e.preventDefault(); S.sheet = 'terms'; paint(); return; }
    if (up('[data-terms-accept]')) { e.preventDefault(); S.termsOk = true; S.sheet = null; paint(); return; }
    if (up('[data-sheet-close]')) { e.preventDefault(); S.sheet = null; paint(); return; }

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
    // مبلغ دلخواه شارژ: فقط عدد؛ دکمه همان لحظه به‌روز می‌شود، بی‌رندر کل صفحه
    if (e.target.id === 'cmb-topup') {
      S.topupAmount = Number(en(e.target.value).replace(/\D/g, '')) || 0;
      $$('.cmb-chip[data-topup-preset]').forEach(function (c) { c.classList.toggle('is-on', Number(c.getAttribute('data-topup-preset')) === S.topupAmount); });
      var tv = document.getElementById('cmb-topup-v');
      if (tv) { tv.textContent = topupAmountText(); }
      return;
    }

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
    if (e.key === 'Escape' && S.sheet) { S.sheet = null; paint(); return; }

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

  /* ارتفاع نوار پایین با چرخش گوشی، تغییر اندازه و آمدن فونت عوض می‌شود */
  window.addEventListener('resize', syncBar);
  window.addEventListener('orientationchange', syncBar);

  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(syncBar); }

  /* مقادیر فرم قبل از رندر مجدد از بین نروند */
  document.addEventListener('change', function (e) {
    if (e.target.id && e.target.id.indexOf('cmb-f-') === 0) { grabForm(); }
    if (e.target.id === 'cmb-terms') { S.termsOk = !!e.target.checked; paint(); }
    if (e.target.id === 'cmb-usewallet') { S.useWallet = !!e.target.checked; paint(); }
  });

  /* برگشت از درگاه با دکمه‌ی «بازگشت» مرورگر: صفحه از حافظه (bfcache)
     با همان حالت «در حال انتقال…» برمی‌گردد. نوبتِ ثبت‌شده حالا در
     «نوبت‌های من» با دکمه‌ی ادامه‌ی پرداخت یا انصراف است. */
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted || !S.redirecting) { return; }

    S.redirecting = false;
    S.payBusy = 0;
    S.topupBusy = false;
    S.bookingsFresh = false;
    S.walletFresh = false;
    go(S.page === 'wallet' ? 'wallet' : 'mine');
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

  /* بازگشت از درگاه شارژ: نتیجه را نشان بده و توکن را از نشانی بردار
     (مگر هنوز در انتظار باشد و «بررسی دوباره» لازمش داشته باشد). */
  if (S.topupResult) {
    S.page = 'wallet';
    if (S.topupResult.state !== 'checking' && history.replaceState) {
      history.replaceState({ page: 'wallet' }, '', BASE + 'wallet');
    }
  }
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
