/* ═══════════════════════════════════════
   چک موتور — داشبورد مدیریت (فرانت)
   ═══════════════════════════════════════ */
(function () {
'use strict';

var C     = window.CMB_PANEL || {};
var ROOT  = C.root || '/wp-json/cmb/v1/';
var BASE  = C.base || '/reserve/panel';
var NONCE = C.nonce || '';
var NURL  = C.nonceUrl || '/wp-admin/admin-ajax.php?action=cmb_nonce';

var VIEWS = ['summary', 'board', 'bookings', 'customers', 'services', 'closures', 'settings'];

/* وضعیت‌ها ثابت‌اند و نباید از پاسخ سرور خوانده شوند.
   قبلاً گزینه‌های این دراپ‌داون از S.list می‌آمد و S.list هنگام هر
   بارگذاری null می‌شد — یعنی درست بعد از زدن «اعمال»، دراپ‌داون خالی
   و انتخاب کاربر به «همه» برمی‌گشت. بدتر اینکه کلیک بعدی (تغییر تب،
   صفحه‌ی بعد، اعمال دوباره) همان مقدار خالی را می‌خواند و فیلتر وضعیت
   را بی‌صدا حذف می‌کرد. */
var STATUSES = {
  confirmed: 'تایید شده',
  done: 'انجام شده',
  no_show: 'عدم مراجعه',
  cancelled: 'لغو شده'
};

var S = {
  view: 'summary',
  can: !!C.can,
  loading: false, busy: false,
  toasts: [], modal: null,

  summary: (C.boot || null),
  board: null, boardFrom: '',
  list: null, filter: { scope: 'upcoming', status: '', date: '', q: '', page: 1 },
  services: null, svcEdit: null, uploading: false,
  customers: null, custQ: '', custPage: 1, lastQ: '', exporting: false,
  fetching: false,
  sched: null, schedFull: '', health: null, sweeping: false,
  closures: null, closeForm: { date: '', block: '', reason: '' },
  login: freshLogin(),
  menu: false
};

/* اگر به هر دلیلی jalali.js نرسد (کش، افزونه‌ی بهینه‌سازی، خطای شبکه)
   کل پنل نباید سفید شود. این نسخه‌ی حداقلی تاریخ را خام نشان می‌دهد
   ولی بقیه‌ی پنل سر جایش می‌ماند. */
var J = window.CMBJalali || {
  format: function (v) { return String(v || ''); },
  formatTime: function (v) { return String(v || ''); },
  todayG: function () { return new Date().toISOString().slice(0, 10); },
  fieldHTML: function (id, value) {
    return '<input class="pn-in" id="' + id + '" type="date" value="' + (value || '') + '">';
  },
  bind: function () {}
};

/* ─── ابزار ─── */

/* ارقام فارسی فقط اگر سرور تایید کند فونت سایت آن‌ها را دارد؛
   وگرنه مرورگر به‌جای عدد لوزی خالی می‌کشد. */
var FA_DIGITS = !!C.faDigits;

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
function checked(s) { var e = $(s); return e ? e.checked : false; }

function toast(msg, kind) {
  var id = Date.now() + Math.random();
  S.toasts.push({ id: id, msg: msg, kind: kind || '' });
  paintToasts();
  setTimeout(function () {
    S.toasts = S.toasts.filter(function (t) { return t.id !== id; });
    paintToasts();
  }, 4000);
}

/* ─── شبکه ─── */

var nonceWait = null;

function freshNonce() {
  if (nonceWait) { return nonceWait; }

  nonceWait = fetch(NURL, { credentials: 'include', cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (n) {
      if (n && n.nonce) { NONCE = n.nonce; }
      if (n && typeof n.canManage !== 'undefined') { S.can = !!n.canManage; }
      nonceWait = null;
      return n || {};
    })
    .catch(function () { nonceWait = null; return {}; });

  return nonceWait;
}

function req(method, path, body, retried) {
  return fetch(ROOT + path, {
    method: method,
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
    credentials: 'include', cache: 'no-store',
    body: body ? JSON.stringify(body) : undefined
  })
    .then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        var stale = (r.status === 401 || r.status === 403) &&
          (!j.code || String(j.code).indexOf('nonce') !== -1 || String(j.code).indexOf('cookie') !== -1);

        if (stale && !retried) {
          return freshNonce().then(function () { return req(method, path, body, true); });
        }

        if (!r.ok) {
          return { success: false, message: j.message || 'خطایی رخ داد.', code: j.code || '' };
        }

        if (j && typeof j.success === 'undefined') { j.success = true; }
        return j;
      });
    })
    .catch(function () { return { success: false, message: 'ارتباط با سرور برقرار نشد.' }; });
}
function get(p) { return req('GET', p); }
function post(p, b) { return req('POST', p, b); }

/* ═══ آیکون ═══ */

var I = {
  burger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
  engine: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9.5h3.5V7h5v2.5H17l3 3v5.5h-3v2H7v-2H4v-6z"/><path d="M9.5 7V4.5h5V7"/></svg>',
  gauge: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 17a9 9 0 1 1 17 0"/><path d="M12 17l4-5.5"/><circle cx="12" cy="17" r="1.4" fill="currentColor" stroke="none"/></svg>',
  grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3.5" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="3.5" width="7.5" height="7.5" rx="1.8"/><rect x="3" y="13" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="13" width="7.5" height="7.5" rx="1.8"/></svg>',
  list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4" width="17" height="17" rx="2.6"/><path d="M3.5 9h17M8 2.5v3M16 2.5v3M8 13.5h8M8 17h5"/></svg>',
  tag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 13.2l-7.3 7.3a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4.5a2 2 0 0 1 2-2h7.4a2 2 0 0 1 1.4.6l7.1 7.1a2 2 0 0 1 0 2.9z"/><circle cx="7.8" cy="7.8" r="1.4"/></svg>',
  lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.6a4 4 0 0 1 8 0v2.9"/></svg>',
  gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 13a7.6 7.6 0 0 0 0-2l2-1.5-2-3.4-2.3 1a7.6 7.6 0 0 0-2.1-1.2l-.3-2.5h-4l-.3 2.5a7.6 7.6 0 0 0-2.1 1.2l-2.3-1-2 3.4 2 1.5a7.6 7.6 0 0 0 0 2l-2 1.5 2 3.4 2.3-1a7.6 7.6 0 0 0 2.1 1.2l.3 2.5h4l.3-2.5a7.6 7.6 0 0 0 2.1-1.2l2.3 1 2-3.4z"/></svg>',
  cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="16" rx="2.6"/><path d="M3.5 9.5h17M8 2.8v3.4M16 2.8v3.4"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 6.8v5.4l3.3 2"/></svg>',
  car: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14.5l1.7-5a2.4 2.4 0 0 1 2.3-1.6h10a2.4 2.4 0 0 1 2.3 1.6l1.7 5"/><path d="M3 14.5h18v3.6a1 1 0 0 1-1 1h-1.6a1 1 0 0 1-1-1v-.9H6.6v.9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-1.5a4.5 4.5 0 0 0-4.5-4.5h-7A4.5 4.5 0 0 0 4 19.5V21"/><circle cx="12" cy="7.5" r="4.2"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 20.5v-1.6a4 4 0 0 0-4-4h-5a4 4 0 0 0-4 4v1.6"/><circle cx="10" cy="7.5" r="3.7"/><path d="M21.5 20.5v-1.6a4 4 0 0 0-3-3.9"/><path d="M15.5 4.1a4 4 0 0 1 0 7"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6.5L9.3 17.2 4 12"/></svg>',
  ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.2 12.3l2.6 2.6 5-5"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
  trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 6.5h15"/><path d="M9.5 6.5V5a1.5 1.5 0 0 1 1.5-1.5h2A1.5 1.5 0 0 1 14.5 5v1.5"/><path d="M6.5 6.5L7.6 20a1 1 0 0 0 1 .9h6.8a1 1 0 0 0 1-.9l1.1-13.5"/></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 4.5H5A2 2 0 0 0 3 6.5V19a2 2 0 0 0 2 2h12.5a2 2 0 0 0 2-2v-6.5"/><path d="M18 2.8a2.1 2.1 0 0 1 3 3L12 14.8l-4 1 1-4z"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5.5v13M5.5 12h13"/></svg>',
  chevU: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5.5 15L12 8.5 18.5 15"/></svg>',
  chevDn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5.5 9L12 15.5 18.5 9"/></svg>',
  cog: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
  down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v12"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4.5 20.5h15"/></svg>',
  refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v4.5h-4.5"/></svg>',
  info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 3.9L2.6 18a1.6 1.6 0 0 0 1.4 2.4h16a1.6 1.6 0 0 0 1.4-2.4l-8-14.1a1.6 1.6 0 0 0-2.8 0z"/><path d="M12 9.5v4M12 17h.01"/></svg>',
  chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8 8 0 0 1-8.5 8 9 9 0 0 1-3.6-.7L3.5 20.5l1.7-5.2A8 8 0 0 1 4.5 11 8 8 0 0 1 13 3.5a8 8 0 0 1 8 8z"/></svg>',
  out: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 21H5.5A2 2 0 0 1 3.5 19V5a2 2 0 0 1 2-2h4"/><path d="M16 16.5L20.5 12 16 7.5"/><path d="M20.5 12H9.5"/></svg>',
  chevL: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 18.5L8 12l6.5-6.5"/></svg>',
  chevR: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 18.5L16 12 9.5 5.5"/></svg>',
  phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="6.5" y="2.5" width="11" height="19" rx="2.6"/><path d="M11 18.5h2"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v11"/><path d="M8 7.2l4-3.7 4 3.7"/><path d="M8.5 10.5h-2A1.5 1.5 0 0 0 5 12v7a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19v-7a1.5 1.5 0 0 0-1.5-1.5h-2"/></svg>',
  empty: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="16" rx="2.6"/><path d="M3.5 9.5h17M8 2.8v3.4M16 2.8v3.4M9 14.5h6"/></svg>'
};

/* ═══ اجزای مشترک ═══ */

/** عنوان نمای جاری — برای نوار بالای موبایل. */
function viewTitle() {
  for (var i = 0; i < NAV.length; i++) {
    if (NAV[i].k === S.view) { return NAV[i].t; }
  }
  return 'پنل رزرو';
}

var NAV = [
  { k: 'summary',  t: 'خلاصه',        ic: I.gauge },
  { k: 'board',    t: 'تخته روزها',   ic: I.grid },
  { k: 'bookings', t: 'نوبت‌ها',      ic: I.list },
  { k: 'customers', t: 'مشتری‌ها',    ic: I.users },
  { k: 'services', t: 'خدمات',        ic: I.tag },
  { k: 'closures', t: 'بستن روز',     ic: I.lock },
  { k: 'settings', t: 'تنظیمات شیفت', ic: I.cog }
];

function side() {
  var up = S.summary && S.summary.counts ? S.summary.counts.upcoming : 0;

  return '<aside class="pn-side">' +
    '<div class="pn-side__brand">' +
      '<div class="pn-side__logo">' + I.engine + '</div>' +
      '<div><div class="pn-side__t">چک موتور</div>' +
      '<div class="pn-side__s">پنل مدیریت نوبت‌ها</div></div>' +
    '</div>' +
    '<button class="pn-side__x" data-menu-close aria-label="بستن منو">' + I.x + '</button>' +
    '<nav class="pn-side__nav">' +
      NAV.map(function (n) {
        var badge = (n.k === 'bookings' && up) ? '<span class="pn-count">' + fa(up) + '</span>' : '';
        return '<button class="pn-side__b' + (S.view === n.k ? ' is-on' : '') + '" data-view="' + n.k + '">' +
          n.ic + '<span>' + n.t + '</span>' + badge + '</button>';
      }).join('') +
    '</nav>' +
    '<div class="pn-side__foot">' +
      '<div class="pn-side__me">' + esc(C.me || '') + '</div>' +
      installBtn('pn-side__b', 'نصب روی گوشی') +
      '<a class="pn-side__b" href="' + esc(C.app || '/') + '">' + I.out + '<span>دیدن اپ مشتری</span></a>' +
    '</div></aside>';
}

function head(title, sub, actions) {
  return '<div class="pn-head"><div>' +
    '<h1 class="pn-head__t">' + esc(title) + '</h1>' +
    (sub ? '<div class="pn-head__s">' + esc(sub) + '</div>' : '') +
    '</div>' + (actions ? '<div class="pn-head__act">' + actions + '</div>' : '') + '</div>';
}

function note(kind, icon, html) {
  return '<div class="pn-note pn-note--' + kind + '">' + icon + '<div>' + html + '</div></div>';
}

function empty(title, sub) {
  return '<div class="pn-empty"><div class="pn-empty__ic">' + I.empty + '</div>' +
    '<div class="pn-empty__t">' + esc(title) + '</div>' +
    (sub ? '<div class="pn-empty__s">' + esc(sub) + '</div>' : '') + '</div>';
}

function skel(n) {
  var out = '';
  for (var i = 0; i < (n || 4); i++) { out += '<div class="pn-sk"></div>'; }
  return out;
}

function badge(status, label) {
  return '<span class="pn-badge pn-badge--' + esc(status) + '">' + esc(label) + '</span>';
}

/* ═══ خلاصه ═══ */

function viewSummary() {
  // پاسخ ناقص نباید صفحه را سفید کند
  if (!S.summary || !S.summary.counts) { return head('خلاصه وضعیت', '') + skel(3); }

  var s = S.summary;
  var c = s.counts;

  var html = head('خلاصه وضعیت', s.today ? fa(s.today.jalali) : '',
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  if (s.setup && !s.setup.ready) {
    html += note('warn', I.alert,
      '<b>پیکربندی ناقص:</b> ' + esc(s.setup.missing.join('، ')) +
      ' تنظیم نشده است. تا تکمیل نشدن این موارد، ورود با کد تایید و پیامک‌ها کار نمی‌کند. ' +
      (C.wpSettings ? '<a href="' + esc(C.wpSettings) + '" target="_blank">رفتن به تنظیمات</a>' : ''));
  }

  if (s.setup && s.setup.slugClash) {
    html += note('info', I.info,
      'برگه‌ای به نام «' + esc(s.setup.slugClash) + '» دقیقاً روی نشانی <code>/' +
      esc(s.setup.slug) + '/</code> قرار دارد — همان‌جایی که اپ باز می‌شود. ' +
      'اپ برنده می‌شود و آن برگه دیده نمی‌شود. اگر برگه را لازم ندارید حذفش کنید، ' +
      'وگرنه نشانی اپ را عوض کنید.');
  }

  html += '<div class="pn-stats">' +
    stat('امروز', c.today, I.cal, 'hot') +
    stat('فردا', c.tomorrow, I.cal, '') +
    stat('نوبت‌های پیش‌رو', c.upcoming, I.list, 'ok') +
    stat('ثبت ۷ روز اخیر', c.week, I.gauge, '') +
    stat('عدم مراجعه (۳۰ روز)', c.no_show, I.alert, '') +
    stat('لغو شده (۳۰ روز)', c.cancelled, I.x, 'bad') +
    '</div>';

  if (s.byService && s.byService.length) {
    var max = Math.max.apply(null, s.byService.map(function (x) { return x.total; })) || 1;

    html += '<div class="pn-card"><div class="pn-card__t">' + I.tag + ' خدمات پرتقاضا — ۳۰ روز گذشته</div>';

    s.byService.forEach(function (x) {
      html += '<div style="margin-bottom:14px">' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">' +
        '<span style="font-weight:600">' + esc(x.title) + '</span>' +
        '<span class="num" style="color:var(--ink-3)">' + fa(x.total) + ' نوبت</span></div>' +
        '<div class="pn-bar"><div class="pn-bar__f" style="width:' + Math.round(x.total / max * 100) + '%"></div></div>' +
        '</div>';
    });

    html += '</div>';
  }

  html += '<div class="pn-card"><div class="pn-card__t">' + I.info + ' دسترسی سریع</div>' +
    '<div class="pn-head__act">' +
      '<button class="pn-btn pn-btn--pri" data-view="board">' + I.grid + ' تخته‌ی روزها</button>' +
      '<button class="pn-btn pn-btn--soft" data-view="bookings">' + I.list + ' فهرست نوبت‌ها</button>' +
      '<button class="pn-btn pn-btn--soft" data-view="closures">' + I.lock + ' بستن یک روز</button>' +
      '<a class="pn-btn pn-btn--soft" href="' + esc(C.app || '/') + '" target="_blank">' + I.car + ' دیدن اپ مشتری</a>' +
      installBtn('pn-btn pn-btn--soft', 'نصب پنل روی گوشی') +
      (C.wpAdmin ? '<a class="pn-btn pn-btn--soft" href="' + esc(C.wpAdmin) + '" target="_blank">' + I.gear + ' تنظیمات (پیشخوان وردپرس)</a>' : '') +
    '</div></div>';

  return html;
}

function stat(label, value, icon, kind) {
  return '<div class="pn-stat' + (kind ? ' pn-stat--' + kind : '') + '">' +
    '<div class="pn-stat__k">' + icon + esc(label) + '</div>' +
    '<div class="pn-stat__v">' + fa(value || 0) + '</div></div>';
}

/* ═══ تخته‌ی روزها ═══ */

function viewBoard() {
  var html = head('تخته‌ی روزها', 'ظرفیت و نوبت‌های هر شیفت',
    '<button class="pn-btn pn-btn--soft" data-board-prev>' + I.chevR + ' هفته قبل</button>' +
    '<button class="pn-btn pn-btn--soft" data-board-today>امروز</button>' +
    '<button class="pn-btn pn-btn--soft" data-board-next>هفته بعد ' + I.chevL + '</button>');

  if (!S.board) { return html + skel(4); }

  var days = S.board.days || [];

  if (!days.length) { return html + empty('روزی برای نمایش نیست'); }

  html += '<div class="pn-board">';

  days.forEach(function (d) {
    html += '<div class="pn-dayc' + (d.isToday ? ' is-today' : '') + (d.closed ? ' is-closed' : '') + '">' +
      '<div class="pn-dayc__hd"><div>' +
        '<div class="pn-dayc__d">' + esc(d.weekday) + '، ' + fa(esc(d.jalali)) + '</div>' +
        '<div class="pn-dayc__w">' + J.format(d.date, 'numeric') + (d.isToday ? ' · امروز' : '') + '</div>' +
      '</div>' +
      (d.closed ? '<span class="pn-badge pn-badge--cancelled">تعطیل</span>' : '') +
      '</div>';

    (d.blocks || []).forEach(function (b) {
      var pct = b.capacity ? Math.min(100, Math.round(b.booked / b.capacity * 100)) : 0;
      var full = b.booked >= b.capacity;
      var cls = b.closed ? 'shut' : (full ? 'full' : 'free');
      var txt = b.closed ? 'بسته' : fa(b.booked) + ' از ' + fa(b.capacity);

      html += '<div class="pn-blk">' +
        '<div class="pn-blk__hd">' +
          '<div class="pn-blk__t">' + esc(b.label) + ' <span>' + fa(esc(b.start)) + '</span></div>' +
          '<div class="pn-blk__cap ' + cls + '">' + txt + '</div>' +
        '</div>' +
        '<div class="pn-bar"><div class="pn-bar__f' + (full ? ' full' : '') + '" style="width:' + pct + '%"></div></div>';

      /* خدمت‌های سهمیه‌جدا جدا شمرده می‌شوند و در عدد بالا نیستند؛
         باید معلوم باشد وگرنه «۱ از ۲» با ۸ اسم زیرش گیج‌کننده است. */
      if (b.quick && b.quick.length && !b.closed) {
        html += '<div class="pn-quick">' + b.quick.map(function (q) {
          return '<span class="pn-quick__i' + (q.booked >= q.capacity ? ' is-full' : '') + '">' +
            esc(q.title) + ' <b class="num">' + fa(q.booked) + '/' + fa(q.capacity) + '</b></span>';
        }).join('') + '</div>';
      }

      if (b.items && b.items.length) {
        html += '<div class="pn-mini">';
        b.items.forEach(function (it) {
          html += '<button class="pn-mini__i" data-open="' + it.id + '">' +
            '<span class="pn-mini__n">' + esc(it.name) + '</span>' +
            '<span>' + esc(it.carBrand) + (it.carModel ? ' · ' + esc(it.carModel) : '') + '</span>' +
            '<span class="pn-mini__c pn-code">' + esc(it.code) + '</span></button>';
        });
        html += '</div>';
      }

      html += '</div>';
    });

    html += '</div>';
  });

  html += '</div>';
  return html;
}

/* ═══ فهرست نوبت‌ها ═══ */

function scopeLabel(k) {
  var m = { upcoming: 'پیش‌رو', today: 'امروز', tomorrow: 'فردا', past: 'گذشته', all: 'همه' };
  return m[k] || k;
}

function viewBookings() {
  var f = S.filter;

  var html = head('نوبت‌ها', S.list ? fa(S.list.total) + ' مورد یافت شد' : '',
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  var scopes = [
    { k: 'upcoming', t: 'پیش‌رو' }, { k: 'today', t: 'امروز' },
    { k: 'tomorrow', t: 'فردا' }, { k: 'past', t: 'گذشته' }, { k: 'all', t: 'همه' }
  ];

  html += '<div class="pn-card">' +
    '<div class="pn-filters">' +
      '<div class="pn-tabs">' +
        scopes.map(function (s) {
          /* با تاریخِ انتخاب‌شده، دامنه بی‌اثر است — کم‌رنگ نشان
             داده می‌شود تا کاربر دنبال دلیل نگردد. */
          return '<button class="pn-tabs__b' +
            (f.scope === s.k ? ' is-on' : '') +
            (f.date ? ' is-muted' : '') +
            '" data-scope="' + s.k + '">' + s.t + '</button>';
        }).join('') +
      '</div>' +
      '<label class="pn-field"><span class="pn-field__l">وضعیت</span>' +
        '<select class="pn-in" id="pn-status">' +
          '<option value="">همه</option>' +
          Object.keys(STATUSES).map(function (k) {
            return '<option value="' + k + '"' + (f.status === k ? ' selected' : '') + '>' + esc(STATUSES[k]) + '</option>';
          }).join('') +
        '</select></label>' +
      '<label class="pn-field"><span class="pn-field__l">تاریخ</span>' +
        J.fieldHTML('pn-date', f.date, { placeholder: 'همه‌ی تاریخ‌ها' }) + '</label>' +
      '<label class="pn-field" style="flex:1;min-width:180px"><span class="pn-field__l">جستجو</span>' +
        '<input class="pn-in" id="pn-q" type="search" placeholder="نام، موبایل، کد پیگیری یا خودرو" value="' + esc(f.q) + '"></label>' +
      '<button class="pn-btn pn-btn--dark" data-apply>اعمال</button>' +
      '<button class="pn-btn pn-btn--soft" data-clear>پاک کردن</button>' +
    '</div>';

  // نشان دادن فیلترهای فعال، تا معلوم باشد چرا فهرست این شکلی است
  var chips = [];

  if (f.status) { chips.push({ k: 'status', t: 'وضعیت: ' + (STATUSES[f.status] || f.status) }); }
  if (f.date) { chips.push({ k: 'date', t: 'تاریخ: ' + J.format(f.date, 'long') }); }
  if (f.q) { chips.push({ k: 'q', t: 'جستجو: ' + f.q }); }

  if (chips.length) {
    html += '<div class="pn-active">' +
      chips.map(function (c) {
        return '<button class="pn-active__c" data-unfilter="' + c.k + '">' +
          esc(c.t) + '<span>' + I.x + '</span></button>';
      }).join('') + '</div>';
  }

  /* پیش از این تاریخ و تب دامنه با AND کنار هم می‌نشستند و هر
     ترکیب ناسازگاری فهرست خالی می‌داد. حالا تاریخ برنده است؛ فقط
     باید معلوم باشد که تب کنار گذاشته شده. */
  if (f.date) {
    html += note('info', I.info,
      'فیلتر تاریخ روی همه‌ی نوبت‌های آن روز اعمال می‌شود و تب «' +
      esc(scopeLabel(f.scope)) + '» را نادیده می‌گیرد.');
  }

  if (!S.list) {
    html += skel(4) + '</div>';
    return html;
  }

  if (!S.list.items.length) {
    /* جستجو داخل دامنه‌ی جاری انجام می‌شود، پس جستجوی کد پیگیریِ یک
       نوبت گذشته در تب «پیش‌رو» نتیجه‌ای ندارد. به‌جای بن‌بست، راه
       خروج یک‌کلیکی می‌دهیم. */
    var wide = f.q && !f.date && f.scope !== 'all';

    html += empty(
      'نوبتی پیدا نشد',
      wide
        ? 'در بازه‌ی «' + esc(scopeLabel(f.scope)) + '» چیزی پیدا نشد.'
        : (chips.length
          ? 'با فیلترهای فعلی نتیجه‌ای نیست — یکی از فیلترهای بالا را بردارید.'
          : 'در این بازه نوبتی ثبت نشده است.')
    );

    if (wide) {
      html += '<div class="pn-empty__act">' +
        '<button class="pn-btn pn-btn--soft" data-scope="all">جستجو در همه‌ی تاریخ‌ها</button></div>';
    }

    html += '</div>';
    return html;
  }

  html += '<div class="pn-tablewrap' + (S.fetching ? ' is-fetching' : '') + '"><table class="pn-table"><thead><tr>' +
    '<th>تاریخ و ساعت</th><th>مشتری</th><th>خودرو</th><th>خدمت</th>' +
    '<th>کد</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';

  S.list.items.forEach(function (b) {
    html += '<tr>' +
      '<td class="num" data-label="تاریخ و ساعت"><div class="pn-table__b">' + fa(esc(b.dateFa)) + '</div>' +
        '<div class="pn-table__s">' + esc(b.blockLabel) + ' · ' + fa(esc(b.blockStart)) + '</div></td>' +
      '<td data-label="مشتری"><div class="pn-table__b">' + esc(b.name) + '</div>' +
        '<div class="pn-table__s num"><a href="tel:' + esc(b.phone) + '">' + fa(esc(b.phone)) + '</a>' +
        (b.city ? ' · ' + esc(b.city) : '') + '</div></td>' +
      /* نوع خودرو خط اصلی، نوع موتور و سال خط دوم. سرهم نوشتن این دو
         («۲۰۷ TU5») بدون برچسب معلوم نمی‌کرد کدام کدام است. */
      '<td data-label="خودرو"><div>' + esc(b.carBrand) + '</div>' +
        '<div class="pn-table__s num">' + (b.carModel ? 'موتور ' + esc(b.carModel) + ' · ' : '') +
        fa(esc(b.carYear)) + (b.carMileage ? ' · ' + money(b.carMileage) + ' کیلومتر' : '') + '</div></td>' +
      '<td data-label="خدمت">' + esc(b.service) + '</td>' +
      '<td class="pn-code" data-label="کد">' + esc(b.code) + '</td>' +
      '<td data-label="وضعیت">' + badge(b.status, b.statusLabel) + '</td>' +
      '<td data-label=""><div class="pn-acts">' +
        '<button class="pn-btn pn-btn--soft pn-btn--sm" data-open="' + b.id + '">جزئیات</button>' +
      '</div></td></tr>';
  });

  html += '</tbody></table></div>';

  if (S.list.pages > 1) {
    html += '<div class="pn-head__act" style="margin-top:16px;justify-content:center">' +
      '<button class="pn-btn pn-btn--soft" data-page="' + (f.page - 1) + '"' + (f.page <= 1 ? ' disabled' : '') + '>' + I.chevR + ' قبلی</button>' +
      '<span style="align-self:center;font-size:13px;color:var(--ink-3)" class="num">صفحه ' + fa(f.page) + ' از ' + fa(S.list.pages) + '</span>' +
      '<button class="pn-btn pn-btn--soft" data-page="' + (f.page + 1) + '"' + (f.page >= S.list.pages ? ' disabled' : '') + '>بعدی ' + I.chevL + '</button>' +
      '</div>';
  }

  html += '</div>';
  return html;
}

/* ═══ تنظیمات شیفت ═══ */

/**
 * برنامه‌ی کاری: ساعت و ظرفیت شیفت‌ها، بازه‌ی رزرو، و لغو.
 *
 * پیش از این فقط در پیشخوان وردپرس بود، و مسئول رزرو که از پیشخوان
 * به پنل برگردانده می‌شود هیچ راهی برای تغییرش نداشت. اطلاعات
 * پیامک عمداً اینجا نیست و فقط در پیشخوان می‌ماند.
 */
function viewSettings() {
  var html = head('تنظیمات شیفت', 'ساعت و ظرفیت هر شیفت، و بازه‌ی رزرو آنلاین');
  var st = S.sched;

  if (!st) { return html + skel(3); }

  function num(id, label, value, min, max, unit, hint) {
    return '<label class="pn-field"><span class="pn-field__l">' + label + '</span>' +
      '<div class="pn-unit"><input class="pn-in num" type="number" id="' + id + '" min="' + min + '" max="' + max + '" value="' + Number(value) + '">' +
      (unit ? '<span>' + unit + '</span>' : '') + '</div>' +
      (hint ? '<div class="pn-hint">' + hint + '</div>' : '') + '</label>';
  }

  function time(id, label, value) {
    return '<label class="pn-field"><span class="pn-field__l">' + label + '</span>' +
      '<input class="pn-in num" type="time" id="' + id + '" value="' + esc(value) + '"></label>';
  }

  html += '<div class="pn-card">' +
    '<h3 class="pn-card__t">شیفت‌ها</h3>' +
    '<p class="pn-hint" style="margin:0 0 14px">ظرفیت یعنی چند نوبت از خدماتی که «از ظرفیت اصلی شیفت» کم می‌کنند در هر شیفت پذیرفته شود. ' +
      'خدماتی که سهمیه‌ی جداگانه دارند اینجا حساب نمی‌شوند؛ سهمیه‌شان را در بخش خدمات تنظیم کنید.</p>' +
    '<div class="pn-grid">' +
      time('st-m-start', 'شروع شیفت صبح', st.morning_start) +
      num('st-m-cap', 'ظرفیت شیفت صبح', st.morning_capacity, 0, 50, 'نوبت', '') +
      time('st-a-start', 'شروع شیفت بعدازظهر', st.afternoon_start) +
      num('st-a-cap', 'ظرفیت شیفت بعدازظهر', st.afternoon_capacity, 0, 50, 'نوبت', '') +
    '</div>' +
    '<div class="pn-hint">صفر برای یک شیفت یعنی آن شیفت برای خدمات اصلی بسته است. برای بستن موقت یک روز خاص، از بخش «بستن روز» استفاده کنید.</div>' +
  '</div>';

  html += '<div class="pn-card">' +
    '<h3 class="pn-card__t">بازه‌ی رزرو</h3>' +
    '<div class="pn-grid">' +
      num('st-min', 'حداقل فاصله تا مراجعه', st.min_days_ahead, 0, 30, 'روز', '۱ یعنی رزرو برای همان روز مجاز نیست.') +
      num('st-win', 'تعداد روزهای قابل رزرو', st.window_days, 1, 60, 'روز', 'چند روز جلوتر در تقویم مشتری نمایش داده شود.') +
      num('st-max', 'حداکثر نوبت فعال هر مشتری', st.max_active_per_user, 0, 10, 'نوبت', '۰ یعنی بدون محدودیت.') +
    '</div>' +
    '<label class="pn-check" style="margin-top:14px">' +
      '<input type="checkbox" id="st-one"' + (Number(st.one_per_service) ? ' checked' : '') + '> ' +
      'از هر خدمت هم‌زمان فقط یک نوبت</label>' +
    '<div class="pn-hint">' +
      (Number(st.max_active_per_user) === 1
        ? 'با سقف ۱ این گزینه اثری ندارد، چون مشتری در هر حال فقط یک نوبت فعال می‌تواند داشته باشد.'
        : 'مثال: با سقف ۲ و این گزینه روشن، مشتری می‌تواند یک تنظیم موتور و یک تعویض روغن هم‌زمان داشته باشد، ولی دو تنظیم موتور نه.') +
    '</div>' +
  '</div>';

  html += '<div class="pn-card">' +
    '<h3 class="pn-card__t">لغو توسط مشتری</h3>' +
    '<label class="pn-check" style="margin-bottom:14px">' +
      '<input type="checkbox" id="st-cancel"' + (Number(st.cancel_enabled) ? ' checked' : '') + '> مشتری بتواند نوبتش را خودش لغو کند</label>' +
    '<div class="pn-grid">' +
      num('st-cancel-h', 'مهلت لغو', st.cancel_deadline_hours, 0, 336, 'ساعت پیش از شیفت', '۰ یعنی تا لحظه‌ی شروع شیفت.') +
    '</div>' +
  '</div>';

  html += healthCard();

  html += '<div class="pn-actions">' +
    '<button class="pn-btn pn-btn--pri" data-sched-save' + (S.busy ? ' disabled' : '') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : 'ذخیره‌ی تنظیمات') + '</button>' +
    (S.schedFull
      ? '<a class="pn-btn pn-btn--soft" href="' + esc(S.schedFull) + '">تنظیمات پیامک و متن‌ها در پیشخوان</a>'
      : '') +
  '</div>';

  return html;
}

/**
 * بررسی سلامت.
 *
 * جدا از خود صفحه بار می‌شود تا فرم تنظیمات منتظرش نماند؛ چند
 * کوئری شمارش می‌زند و ممکن است کمی طول بکشد.
 */
function loadHealth() {
  get('panel/health').then(function (r) {
    if (r && Array.isArray(r.checks)) { S.health = r; paint(); }
  });
}

function healthCard() {
  var h = S.health;

  if (!h) {
    return '<div class="pn-card"><h3 class="pn-card__t">بررسی سلامت</h3>' +
      '<div class="pn-hint">در حال بررسی…</div></div>';
  }

  var bad = h.checks.filter(function (c) { return c.level !== 'ok'; });

  var html = '<div class="pn-card"><h3 class="pn-card__t">بررسی سلامت</h3>';

  if (!bad.length) {
    html += '<div class="pn-hl pn-hl--ok"><b>همه‌چیز مرتب است.</b>' +
      ' هر ' + fa(h.checks.length) + ' بررسی بدون ایراد گذشت.</div>';
  } else {
    html += '<p class="pn-hint" style="margin:0 0 12px">' +
      fa(bad.length) + ' مورد از ' + fa(h.checks.length) + ' بررسی نیاز به توجه دارد. ' +
      'این‌ها چیزهایی‌اند که به‌روزرسانی خودکار درستشان نمی‌کند.</p>';

    bad.forEach(function (c) {
      html += '<div class="pn-hl pn-hl--' + esc(c.level) + '">' +
        '<b>' + esc(c.title) + '</b> ' + esc(c.detail) +
        (c.fix ? '<div class="pn-hl__fix">' + esc(c.fix) + '</div>' : '') +
        '</div>';
    });
  }

  html += '<div class="pn-actions" style="margin-top:12px">' +
    '<button class="pn-btn pn-btn--soft" data-health-sweep' + (S.sweeping ? ' disabled' : '') + '>' +
      (S.sweeping ? '<span class="pn-spin"></span> در حال انجام…' : 'تعیین وضعیت نوبت‌های گذشته') + '</button>' +
    '<button class="pn-btn pn-btn--soft" data-health-recheck>بررسی دوباره</button>' +
  '</div></div>';

  return html;
}

function sweepPast() {
  if (S.sweeping) { return; }

  S.sweeping = true;
  paint();

  post('panel/health/sweep', {}).then(function (r) {
    S.sweeping = false;

    toast((r && r.message) || 'انجام شد.', r && r.success === false ? 'bad' : 'ok');

    S.summary = null;
    S.board = null;
    loadHealth();
    paint();
  });
}

function saveSchedule() {
  if (S.busy) { return; }

  var body = {
    morning_start: val('#st-m-start'),
    morning_capacity: en(val('#st-m-cap')),
    afternoon_start: val('#st-a-start'),
    afternoon_capacity: en(val('#st-a-cap')),
    min_days_ahead: en(val('#st-min')),
    window_days: en(val('#st-win')),
    max_active_per_user: en(val('#st-max')),
    one_per_service: checked('#st-one') ? 1 : 0,
    cancel_enabled: checked('#st-cancel') ? 1 : 0,
    cancel_deadline_hours: en(val('#st-cancel-h'))
  };

  // بررسی سمت مرورگر فقط برای پیام سریع‌تر؛ سرور خودش دوباره بررسی می‌کند
  if (body.afternoon_start && body.morning_start && body.afternoon_start <= body.morning_start) {
    toast('ساعت شروع شیفت بعدازظهر باید بعد از شیفت صبح باشد.', 'bad');
    return;
  }

  S.busy = true;
  paint();

  post('panel/settings/save', body).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message || 'ذخیره ناموفق بود.', 'bad');
      paint();
      return;
    }

    // مقادیرِ پاک‌سازی‌شده‌ی سرور برمی‌گردند (مثلاً «9:00» → «09:00»)
    if (r.settings) {
      for (var k in r.settings) {
        if (Object.prototype.hasOwnProperty.call(r.settings, k)) { S.sched[k] = r.settings[k]; }
      }
    }

    // تخته و خلاصه با ظرفیت تازه باید از نو خوانده شوند
    S.board = null;
    S.summary = null;

    toast(r.message || 'تنظیمات ذخیره شد.', 'ok');
    paint();
  });
}

/* ═══ مشتری‌ها ═══ */

/**
 * فهرست مشتری‌ها.
 *
 * داده‌اش از خود جدول نوبت‌ها می‌آید و بر اساس شماره‌ی موبایل گروه
 * می‌شود. یعنی اینجا فقط کسانی هستند که واقعاً نوبت گرفته‌اند — نه
 * همه‌ی کاربران وردپرس سایت.
 */
function viewCustomers() {
  var c = S.customers;

  var html = head('مشتری‌ها', c ? fa(c.total) + ' مشتری' : '',
    '<button class="pn-btn pn-btn--dark" data-cust-export' + (S.exporting ? ' disabled' : '') + '>' +
      (S.exporting ? '<span class="pn-spin"></span> در حال آماده‌سازی…' : I.down + ' خروجی اکسل') + '</button>' +
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  html += '<div class="pn-card">' +
    '<div class="pn-filters">' +
      '<label class="pn-field" style="flex:1;min-width:200px"><span class="pn-field__l">جستجو</span>' +
        '<input class="pn-in" id="cu-q" type="search" placeholder="نام، موبایل، شهر یا خودرو" value="' + esc(S.custQ) + '"></label>' +
      '<button class="pn-btn pn-btn--dark" data-cust-search>جستجو</button>' +
      (S.custQ ? '<button class="pn-btn pn-btn--soft" data-cust-clear>پاک کردن</button>' : '') +
    '</div>';

  if (!c) { return html + skel(4) + '</div>'; }

  if (!c.items.length) {
    html += empty(
      'مشتری‌ای پیدا نشد',
      S.custQ ? 'با این جستجو نتیجه‌ای نیست.' : 'هنوز کسی نوبت نگرفته است.'
    ) + '</div>';
    return html;
  }

  html += '<div class="pn-tablewrap' + (S.fetching ? ' is-fetching' : '') + '"><table class="pn-table"><thead><tr>' +
    '<th>مشتری</th><th>خودرو</th><th>خدمات</th><th>نوبت‌ها</th><th>آخرین نوبت</th><th>عملیات</th>' +
    '</tr></thead><tbody>';

  c.items.forEach(function (m) {
    html += '<tr>' +
      '<td data-label="مشتری"><div class="pn-table__b">' + esc(m.name || '—') + '</div>' +
        '<div class="pn-table__s num"><a href="tel:' + esc(m.phone) + '">' + fa(esc(m.phoneFa)) + '</a>' +
        (m.city ? ' · ' + esc(m.city) : '') + '</div></td>' +
      '<td data-label="خودرو">' + esc(m.car || '—') + '</td>' +
      '<td data-label="خدمات">' + esc(m.services || '—') + '</td>' +
      '<td data-label="نوبت‌ها" class="num">' + fa(m.total) +
        (m.cancelled ? ' <span class="pn-table__s">(' + fa(m.cancelled) + ' لغو)</span>' : '') +
        (m.noshow ? ' <span class="pn-table__s">(' + fa(m.noshow) + ' غیبت)</span>' : '') + '</td>' +
      '<td data-label="آخرین نوبت" class="num">' + fa(esc(m.lastDate)) +
        '<div class="pn-table__s">از ' + fa(esc(m.firstSeen)) + '</div></td>' +
      '<td data-label="عملیات">' +
        '<button class="pn-btn pn-btn--sm pn-btn--soft" data-cust-bookings="' + esc(m.phone) + '">نوبت‌هایش</button>' +
      '</td></tr>';
  });

  html += '</tbody></table></div>';

  // همان شکل صفحه‌بندی فهرست نوبت‌ها، تا رفتار پنل یکدست بماند
  if (c.pages > 1) {
    html += '<div class="pn-head__act" style="margin-top:16px;justify-content:center">' +
      '<button class="pn-btn pn-btn--soft" data-cust-page="' + (c.page - 1) + '"' + (c.page <= 1 ? ' disabled' : '') + '>' + I.chevR + ' قبلی</button>' +
      '<span style="align-self:center;font-size:13px;color:var(--ink-3)" class="num">صفحه ' + fa(c.page) + ' از ' + fa(c.pages) + '</span>' +
      '<button class="pn-btn pn-btn--soft" data-cust-page="' + (c.page + 1) + '"' + (c.page >= c.pages ? ' disabled' : '') + '>بعدی ' + I.chevL + '</button>' +
      '</div>';
  }

  return html + '</div>';
}

/**
 * خروجی CSV فهرست مشتری‌ها.
 *
 * فایل در خود مرورگر ساخته می‌شود تا برای مسئول رزرو هم کار کند —
 * او به پیشخوان وردپرس دسترسی ندارد و مسیرهای دانلود آنجا برایش
 * بسته است.
 *
 * دو نکته‌ی فنی: BOM لازم است وگرنه اکسل فارسی را جویده نشان
 * می‌دهد؛ و هر مقداری که با = + - @ شروع شود با یک نقل‌قول
 * بی‌اثر می‌شود، چون اکسل آن را فرمول حساب می‌کند.
 */
function csvCell(v) {
  var t = String(v === null || v === undefined ? '' : v);

  if (/^[=+\-@]/.test(t)) { t = "'" + t; }

  return '"' + t.replace(/"/g, '""') + '"';
}

function exportCustomers() {
  if (S.exporting) { return; }

  S.exporting = true;
  paint();

  get('panel/customers?all=1&q=' + encodeURIComponent(S.custQ)).then(function (r) {
    S.exporting = false;

    if (!r || r.success === false || !Array.isArray(r.items)) {
      toast((r && r.message) || 'تهیه‌ی خروجی ناموفق بود.', 'bad');
      paint();
      return;
    }

    var head = ['نام', 'موبایل', 'شهر', 'خودرو', 'خدمات', 'کل نوبت‌ها', 'انجام‌شده', 'لغوشده', 'غیبت', 'اولین مراجعه', 'آخرین نوبت', 'حساب کاربری'];

    var lines = [head.map(csvCell).join(',')];

    r.items.forEach(function (m) {
      lines.push([
        m.name, m.phone, m.city, m.car, m.services,
        m.total, m.done, m.cancelled, m.noshow,
        m.firstSeen, m.lastDate,
        m.hasUser ? 'دارد' : 'ندارد'
      ].map(csvCell).join(','));
    });

    var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');

    a.href = url;
    a.download = 'customers-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);

    toast(fa(r.items.length) + ' مشتری در فایل ذخیره شد.', 'ok');
    paint();
  });
}

/* ═══ خدمات ═══ */

function viewServices() {
  var html = head('خدمات', 'قیمت، شرح و روزهای ارائه‌ی هر خدمت را اینجا تغییر دهید',
    '<button class="pn-btn pn-btn--dark" data-svc-new>' + I.plus + ' افزودن خدمت</button>');

  if (!S.services) { return html + skel(3); }

  if (!S.services.length) {
    return html + empty('خدمتی تعریف نشده است', 'با دکمه‌ی «افزودن خدمت» اولین خدمت را بسازید.');
  }

  S.services.forEach(function (s, i) {
    html += '<div class="pn-card">' +
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
        '<div style="flex:1;min-width:200px">' +
          '<div style="display:flex;align-items:center;gap:10px">' +
            '<h3 style="font-size:17px;font-weight:800">' + esc(s.title) + '</h3>' +
            (s.active ? '<span class="pn-badge pn-badge--confirmed">فعال</span>'
                      : '<span class="pn-badge pn-badge--cancelled">غیرفعال</span>') +
          '</div>' +
          (s.description ? '<p style="font-size:13px;color:var(--ink-2);margin-top:8px;line-height:1.85">' + esc(s.description) + '</p>' : '') +
          '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:var(--ink-3)">' +
            '<span class="num">' + money(s.price) + ' تومان</span>' +
            (s.duration ? '<span>' + esc(s.duration) + '</span>' : '') +
            '<span>' + esc(weekdayText(s.weekdays)) + '</span>' +
            (s.ownCapacity
              ? '<span class="pn-badge pn-badge--quick">سهمیه‌ی جدا: ' + capText(s.ownCapacity) + '</span>'
              : '<span>از ظرفیت اصلی شیفت</span>') +
          '</div>' +
        '</div>' +
        '<div class="pn-svcacts">' +
          /* کارهای پرتکرار بدون باز کردن پنجره: روشن/خاموش کردن،
             جابه‌جایی در ترتیب، و ساختن خدمت تازه از روی همین یکی. */
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc="' + s.id + '">' + I.edit + ' ویرایش</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-toggle="' + s.id + '">' +
            (s.active ? 'غیرفعال کن' : 'فعال کن') + '</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-copy="' + s.id + '">کپی</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-move="' + s.id + ':up"' +
            (i === 0 ? ' disabled' : '') + ' aria-label="بالا">' + I.chevU + '</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-move="' + s.id + ':down"' +
            (i === S.services.length - 1 ? ' disabled' : '') + ' aria-label="پایین">' + I.chevDn + '</button>' +
        '</div>' +
      '</div></div>';
  });

  return html;
}

var BLOCKS = C.blocks || [
  { key: 'morning', label: 'صبح' },
  { key: 'afternoon', label: 'عصر' }
];

function capText(cap) {
  return BLOCKS.map(function (b) {
    return esc(b.label) + ' ' + fa(cap[b.key] || 0);
  }).join(' · ');
}

var WD = { 6: 'شنبه', 0: 'یکشنبه', 1: 'دوشنبه', 2: 'سه‌شنبه', 3: 'چهارشنبه', 4: 'پنجشنبه', 5: 'جمعه' };

function weekdayText(list) {
  var order = [6, 0, 1, 2, 3, 4, 5];
  var out = order.filter(function (d) { return (list || []).indexOf(d) !== -1; }).map(function (d) { return WD[d]; });
  return out.length ? out.join('، ') : 'هیچ روزی';
}

function svcModal() {
  var s = S.svcEdit;

  var isNew = !s.id;

  return '<div class="pn-modal__hd"><div class="pn-modal__t">' + (isNew ? 'خدمت تازه' : 'ویرایش خدمت') + '</div>' +
    '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    '<label class="pn-field"><span class="pn-field__l">عنوان خدمت</span>' +
    '<input class="pn-in" id="sv-title" value="' + esc(s.title) + '"></label>' +

    '<label class="pn-field"><span class="pn-field__l">شرح / اجزای پکیج</span>' +
    '<textarea class="pn-in" id="sv-desc">' + esc(s.description) + '</textarea></label>' +

    '<div class="pn-grid" style="margin-bottom:16px">' +
      '<label class="pn-field"><span class="pn-field__l">قیمت (تومان)</span>' +
      '<input class="pn-in num" id="sv-price" type="number" min="0" step="10000" value="' + Number(s.price) + '"></label>' +
      '<label class="pn-field"><span class="pn-field__l">مدت تخمینی</span>' +
      '<input class="pn-in" id="sv-dur" value="' + esc(s.duration) + '"></label>' +
    '</div>' +

    '<div class="pn-field"><span class="pn-field__l">روزهای قابل رزرو</span>' +
      '<div class="pn-chips" id="sv-days">' +
        [6, 0, 1, 2, 3, 4, 5].map(function (d) {
          var on = (s.weekdays || []).indexOf(d) !== -1;
          return '<button type="button" class="pn-chip' + (on ? ' is-on' : '') + '" data-day="' + d + '">' + WD[d] + '</button>';
        }).join('') +
      '</div>' +
      '<div class="pn-hint">پنجشنبه و جمعه معمولاً تعطیل است؛ اگر انتخاب نشود، آن روز در تقویم مشتری بسته می‌ماند.</div>' +
    '</div>' +

    /* آپلود پوستر شناسه‌ی خدمت را لازم دارد، پس تا ذخیره‌ی اول
       نمایش داده نمی‌شود — وگرنه کاربر تصویر انتخاب می‌کند و آپلود
       بی‌صدا شکست می‌خورد. */
    (isNew
      ? '<div class="pn-hint" style="margin-bottom:16px">پوستر را بعد از ذخیره‌ی اول می‌توانید اضافه کنید.</div>'
      : '<div class="pn-field"><span class="pn-field__l">پوستر خدمت</span>' +
      '<div class="pn-poster">' +
        (s.poster
          ? '<img class="pn-poster__img" src="' + esc(s.poster) + '" alt="">'
          : '<div class="pn-poster__ph">' + I.grid + '<span>پوستری ندارد</span></div>') +
        '<div class="pn-poster__act">' +
          '<label class="pn-btn pn-btn--soft pn-btn--sm" style="cursor:pointer">' +
            '<input type="file" id="sv-poster" accept="image/jpeg,image/png,image/webp" hidden>' +
            (S.uploading ? 'در حال آپلود…' : 'انتخاب تصویر') + '</label>' +
          (s.poster ? '<button class="pn-btn pn-btn--bad pn-btn--sm" data-poster-clear>حذف</button>' : '') +
        '</div>' +
      '</div>' +
      '<div class="pn-hint">JPG، PNG یا WebP — حداکثر ۴ مگابایت. نسبت پیشنهادی ۱۶:۹.</div>' +
    '</div>') +

    /* ظرفیت: اشتراکی یا سهمیه‌ی جدا. متن توضیح عمداً با مثال است،
       چون تفاوت این دو از روی اسمشان معلوم نیست. */
    '<div class="pn-field"><span class="pn-field__l">ظرفیت</span>' +
      '<div class="pn-seg">' +
        '<button type="button" class="pn-seg__b' + (s.ownCapacity ? '' : ' is-on') + '" data-cap-mode="shared">از ظرفیت اصلی شیفت</button>' +
        '<button type="button" class="pn-seg__b' + (s.ownCapacity ? ' is-on' : '') + '" data-cap-mode="own">سهمیه‌ی جداگانه</button>' +
      '</div>' +
      (s.ownCapacity
        ? '<div class="pn-grid" style="margin-top:10px">' +
            BLOCKS.map(function (b) {
              return '<label class="pn-field"><span class="pn-field__l">چند نفر در ' + esc(b.label) + '</span>' +
                '<input class="pn-in num" type="number" min="0" max="999" id="sv-cap-' + esc(b.key) + '" value="' + Number(s.ownCapacity[b.key] || 0) + '"></label>';
            }).join('') +
          '</div>' +
          '<div class="pn-hint">این خدمت از ظرفیت اصلی شیفت کم نمی‌کند. برای کارهای کوتاه مثل تعویض روغن که یک جای کامل شیفت را نباید بگیرند. صفر یعنی در آن شیفت ارائه نمی‌شود.</div>'
        : '<div class="pn-hint">هر نوبت این خدمت یک جا از ظرفیت اصلی شیفت می‌گیرد — مناسب کارهای طولانی مثل تنظیم موتور.</div>') +
    '</div>' +

    '<label class="pn-check" style="margin-bottom:16px">' +
    '<input type="checkbox" id="sv-active"' + (s.active ? ' checked' : '') + '> در اپ قابل رزرو باشد</label>' +

    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-svc-save' + (S.busy ? ' disabled' : '') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : (isNew ? 'ثبت خدمت' : 'ذخیره تغییرات')) + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>انصراف</button>' +
      (isNew ? '' : '<button class="pn-btn pn-btn--bad" data-svc-del="' + s.id + '">حذف</button>') +
    '</div>';
}

/* ═══ بستن روز ═══ */

function viewClosures() {
  var html = head('بستن روز یا شیفت', 'برای روزهایی که استادکار نیست یا شعبه تعطیل است');

  if (!S.closures) { return html + skel(2); }

  var blocks = S.closures.blocks || [];

  html += '<div class="pn-card"><div class="pn-card__t">' + I.plus + ' بستن یک بازه</div>' +
    '<div class="pn-grid" style="margin-bottom:16px">' +
      '<label class="pn-field"><span class="pn-field__l">تاریخ</span>' +
      J.fieldHTML('cl-date', S.closeForm.date, { min: J.todayG() }) + '</label>' +
      '<label class="pn-field"><span class="pn-field__l">محدوده</span>' +
      '<select class="pn-in" id="cl-block"><option value="">کل روز</option>' +
        blocks.map(function (b) {
          return '<option value="' + esc(b.key) + '"' + (S.closeForm.block === b.key ? ' selected' : '') + '>' + esc(b.label) + '</option>';
        }).join('') +
      '</select></label>' +
      '<label class="pn-field"><span class="pn-field__l">دلیل (اختیاری)</span>' +
      '<input class="pn-in" id="cl-reason" placeholder="مثلاً مسافرت استادکار" value="' + esc(S.closeForm.reason) + '"></label>' +
    '</div>' +
    '<button class="pn-btn pn-btn--pri" data-close-add' + (S.busy ? ' disabled' : '') + '>' +
    (S.busy ? '<span class="pn-spin"></span> در حال ثبت…' : 'بستن این بازه') + '</button>' +
    '</div>';

  html += note('info', I.info,
    'بستن یک بازه فقط جلوی <b>رزروهای جدید</b> را می‌گیرد. نوبت‌های ثبت‌شده‌ی قبلی حذف نمی‌شوند — ' +
    'اگر لازم است، آن‌ها را از بخش «نوبت‌ها» لغو کنید تا مشتری پیامک اطلاع‌رسانی بگیرد.');

  html += '<div class="pn-card"><div class="pn-card__t">' + I.lock + ' بازه‌های بسته‌شده</div>';

  if (!S.closures.items.length) {
    html += empty('بازه‌ی بسته‌ای وجود ندارد');
  } else {
    html += '<div class="pn-tablewrap"><table class="pn-table" style="min-width:520px"><thead><tr>' +
      '<th>تاریخ</th><th>محدوده</th><th>دلیل</th><th></th></tr></thead><tbody>';

    S.closures.items.forEach(function (c) {
      html += '<tr>' +
        '<td class="pn-table__b" data-label="تاریخ">' + fa(esc(c.dateFa)) + '</td>' +
        '<td data-label="محدوده">' + esc(c.label) + '</td>' +
        '<td class="pn-table__s" data-label="دلیل">' + (esc(c.reason) || '—') + '</td>' +
        '<td data-label=""><button class="pn-btn pn-btn--soft pn-btn--sm" data-close-del="' + c.id + '">باز کردن</button></td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';
  }

  html += '</div>';
  return html;
}

/* ═══ مودال نوبت ═══ */

function bookingModal() {
  var b = S.modal.item;

  var acts = [
    { s: 'done', t: 'انجام شد', c: 'pn-btn--pri' },
    { s: 'no_show', t: 'عدم مراجعه', c: 'pn-btn--soft' },
    { s: 'confirmed', t: 'بازگردانی', c: 'pn-btn--soft' },
    { s: 'cancelled', t: 'لغو نوبت', c: 'pn-btn--bad' }
  ].filter(function (a) { return a.s !== b.status; });

  return '<div class="pn-modal__hd">' +
      '<div><div class="pn-modal__t">' + esc(b.name) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px" class="pn-code">کد پیگیری ' + esc(b.code) + '</div></div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    '<div style="margin-bottom:16px">' + badge(b.status, b.statusLabel) + '</div>' +

    '<div class="pn-kv"><span class="pn-kv__k">خدمت</span><span class="pn-kv__v">' + esc(b.service) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">تاریخ</span><span class="pn-kv__v">' + fa(esc(b.dateLong)) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">ساعت</span><span class="pn-kv__v">' + esc(b.blockLabel) + ' — ' + fa(esc(b.blockStart)) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">موبایل</span><span class="pn-kv__v num"><a href="tel:' + esc(b.phone) + '">' + fa(esc(b.phone)) + '</a></span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">شهر</span><span class="pn-kv__v">' + esc(b.city || '—') + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">نوع خودرو</span><span class="pn-kv__v">' + esc(b.carBrand) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">نوع موتور</span><span class="pn-kv__v">' + esc(b.carModel) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">سال ساخت</span><span class="pn-kv__v num">' + fa(esc(b.carYear)) + '</span></div>' +
    (b.carMileage ? '<div class="pn-kv"><span class="pn-kv__k">کارکرد</span><span class="pn-kv__v num">' + money(b.carMileage) + ' کیلومتر</span></div>' : '') +
    (b.note ? '<div class="pn-kv"><span class="pn-kv__k">توضیحات</span><span class="pn-kv__v">' + esc(b.note) + '</span></div>' : '') +
    '<div class="pn-kv"><span class="pn-kv__k">زمان ثبت</span><span class="pn-kv__v num">' + esc(J.formatTime(b.createdAt, FA_DIGITS) || fa(b.createdAt)) + '</span></div>' +

    (b.status !== 'cancelled' ? '' :
      note('warn', I.alert, 'این نوبت لغو شده و ظرفیتش آزاد شده است.')) +

    '<div class="pn-modal__ft" style="flex-wrap:wrap">' +
      acts.map(function (a) {
        return '<button class="pn-btn ' + a.c + '" data-status="' + a.s + '"' + (S.busy ? ' disabled' : '') + '>' + a.t + '</button>';
      }).join('') +
      '<button class="pn-btn pn-btn--bad pn-btn--sm" data-status="delete" style="margin-inline-start:auto">' + I.trash + ' حذف کامل</button>' +
    '</div>' +
    '<div class="pn-hint">«لغو نوبت» برای مشتری پیامک اطلاع‌رسانی می‌فرستد. «حذف کامل» رکورد را برای همیشه پاک می‌کند و پیامکی نمی‌رود.</div>';
}

/* ═══ رندر ═══ */

/* ═══ رندر ═══

   پیش از این هر تغییر وضعیت، کل پنل را از نو می‌ساخت: نوار کناری با
   همه‌ی آیکون‌ها، نوار بالا، و بدنه. روی فهرستی با ۲۵ ردیف این یعنی
   یک بازچینش سنگین در هر کلیک — که حس کندی و پرش می‌داد، تمرکز
   کادرها را می‌پراند و صفحه را به بالا می‌برد.

   سه تغییر:
   ۱. رندر در یک فریم جمع می‌شود، پس چند بار صدا زدن paint() در یک
      هندلر فقط یک بار به DOM می‌نویسد.
   ۲. تا وقتی «پوسته» (نما، منو، وضعیت دسترسی) عوض نشده، فقط بدنه
      بازنویسی می‌شود و نوار کناری دست‌نخورده می‌ماند.
   ۳. تمرکز، مکان‌نما و اسکرول پیش از نوشتن ذخیره و بعدش برگردانده
      می‌شوند، تا تایپ وسط جستجو قطع نشود.                     */

var rafId = 0;
var shellKey = '';

function paint() {
  if (rafId) { return; }

  rafId = (window.requestAnimationFrame || function (f) { return setTimeout(f, 16); })(function () {
    rafId = 0;
    render();
  });
}

/** وضعیت کادر فعال، برای برگرداندن بعد از بازنویسی. */
function grabFocus() {
  var el = document.activeElement;

  if (!el || !el.id || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) { return null; }

  var f = { id: el.id, start: null, end: null };

  try {
    f.start = el.selectionStart;
    f.end = el.selectionEnd;
  } catch (e) { /* روی input[type=number] و مشابهش خطا می‌دهد */ }

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
  var root = document.getElementById('cmb-panel');
  if (!root) { return; }

  if (!S.can) {
    keepLogin();

    root.innerHTML = loginView() + '<div id="pn-toasts" class="pn-toasts"></div><div id="pn-modal"></div>';

    shellKey = 'login';
    paintToasts();
    paintModal();

    // اولین کادر خالی؛ روی گوشی بدون لمس کاربر صفحه‌کلید باز نمی‌شود
    var lf = $('#lg-user') || $('#lg-phone') || $('#lg-code');
    if (lf && !lf.value) { setTimeout(function () { try { lf.focus(); } catch (e) {} }, 60); }
    return;
  }

  var body;

  switch (S.view) {
    case 'board':    body = viewBoard(); break;
    case 'bookings': body = viewBookings(); break;
    case 'customers': body = viewCustomers(); break;
    case 'services': body = viewServices(); break;
    case 'closures': body = viewClosures(); break;
    case 'settings': body = viewSettings(); break;
    default:         body = viewSummary();
  }

  /* اگر پوسته همان است، فقط بدنه عوض می‌شود. */
  var key = S.view + '|' + (S.menu ? '1' : '0');
  var inner = document.querySelector('#cmb-panel .pn-main__in');

  if (key === shellKey && inner) {
    var f = grabFocus();
    var y = window.pageYOffset;

    inner.innerHTML = body;

    restoreFocus(f);
    window.scrollTo(0, y);

    paintToasts();
    paintModal();
    return;
  }

  shellKey = key;

  root.innerHTML =
    '<div class="pn' + (S.menu ? ' is-menu' : '') + '">' +
      // پرده‌ی پشت منوی کشویی — فقط روی موبایل دیده می‌شود
      '<div class="pn-drawerscrim" data-menu-close></div>' +
      side() +
      '<main class="pn-main">' +
        // نوار بالا: فقط روی نمایشگر کوچک
        '<header class="pn-topbar">' +
          '<button class="pn-topbar__burger" data-menu aria-label="منو">' + I.burger + '</button>' +
          '<div class="pn-topbar__t">' + esc(viewTitle()) + '</div>' +
          '<a class="pn-topbar__app" href="' + esc(C.app || '/') + '" aria-label="اپ مشتری">' + I.car + '</a>' +
        '</header>' +
        '<div class="pn-main__in">' + body + '</div>' +
      '</main>' +
    '</div>' +
    '<div id="pn-toasts" class="pn-toasts"></div><div id="pn-modal"></div>';

  paintToasts();
  paintModal();
}

function paintToasts() {
  var host = document.getElementById('pn-toasts');
  if (!host) { return; }

  host.innerHTML = S.toasts.map(function (t) {
    var ic = t.kind === 'bad' ? I.alert : (t.kind === 'ok' ? I.ok : I.info);
    return '<div class="pn-toast' + (t.kind ? ' pn-toast--' + t.kind : '') + '">' + ic + '<div>' + esc(t.msg) + '</div></div>';
  }).join('');
}

function paintModal() {
  var host = document.getElementById('pn-modal');
  if (!host) { return; }

  if (!S.modal && !S.svcEdit) { host.innerHTML = ''; return; }

  var inner = S.svcEdit ? svcModal() : (S.modal.install ? installModal() : bookingModal());
  host.innerHTML = '<div class="pn-scrim" data-scrim><div class="pn-modal">' + inner + '</div></div>';
}

/* ═══ بارگذاری ═══ */

function load(view, force) {
  if (!S.can) { paint(); return; }

  var f = S.filter;

  var jobs = {
    summary:  ['panel/summary', function (r) { S.summary = r; }],
    board:    ['panel/board?days=7' + (S.boardFrom ? '&from=' + S.boardFrom : ''), function (r) { S.board = r; }],
    bookings: ['panel/bookings?scope=' + f.scope + '&status=' + encodeURIComponent(f.status) +
               '&date=' + encodeURIComponent(f.date) + '&q=' + encodeURIComponent(f.q) +
               '&page=' + f.page, function (r) { S.list = r; }],
    customers: ['panel/customers?q=' + encodeURIComponent(S.custQ) + '&page=' + S.custPage,
                function (r) { S.customers = r; }],
    services: ['panel/services', function (r) { S.services = r.items || []; }],
    closures: ['panel/closures', function (r) { S.closures = r; }],
    settings: ['panel/settings', function (r) { S.sched = r.settings; S.schedFull = r.fullUrl || ''; loadHealth(); }]
  };

  var job = jobs[view];
  if (!job) { paint(); return; }

  /* داده‌ی قبلی سر جایش می‌ماند و فقط کم‌رنگ می‌شود. اسکلت فقط
     وقتی دیده می‌شود که هنوز هیچ داده‌ای نداریم — وگرنه هر
     تازه‌سازی، جدول را یک لحظه از صفحه پاک می‌کرد. */
  S.fetching = true;
  paint();

  get(job[0]).then(function (r) {
    S.fetching = false;

    if (r.success === false) {
      toast(r.message || 'دریافت اطلاعات ناموفق بود.', 'bad');

      if (r.code === 'cmb_panel_forbidden' || r.code === 'cmb_panel_auth') { S.can = false; }

      paint();
      return;
    }

    /* پاسخ باید شکل مورد انتظار را داشته باشد. اگر افزونه‌ای وسط
       راه چیز دیگری برگرداند، داده‌ی سالم فعلی حفظ می‌شود به‌جای
       اینکه نما با مقدار ناقص بترکد. */
    if (looksValid(view, r)) {
      job[1](r);
    } else {
      toast('پاسخ سرور نامعتبر بود.', 'bad');
    }

    paint();
  });

  // خلاصه همیشه در پس‌زمینه تازه بماند تا شمارنده‌ی کنار منو درست باشد
  if (view !== 'summary' && !S.summary) {
    get('panel/summary').then(function (r) {
      if (r.success !== false && looksValid('summary', r)) {
        S.summary = r;
        paint();
      }
    });
  }
}

/** آیا پاسخ شکل مورد انتظار این نما را دارد؟ */
function looksValid(view, r) {
  if (!r || typeof r !== 'object') { return false; }

  switch (view) {
    case 'summary':  return !!r.counts;
    case 'board':    return Array.isArray(r.days);
    case 'bookings': return Array.isArray(r.items);
    case 'customers': return Array.isArray(r.items);
    case 'services': return Array.isArray(r.items);
    case 'closures': return Array.isArray(r.items);
    case 'settings': return !!r.settings;
    default:         return true;
  }
}

function go(view) {
  if (VIEWS.indexOf(view) === -1) { view = 'summary'; }

  S.view = view;
  S.menu = false;
  S.modal = null;
  S.svcEdit = null;

  if (history.pushState) {
    var path = BASE + (view === 'summary' ? '' : '/' + view);
    if (location.pathname !== path) { history.pushState({ view: view }, '', path); }
  }

  window.scrollTo(0, 0);
  load(view);
}

function readRoute() {
  var p = location.pathname.replace(/\/+$/, '');
  var i = p.indexOf('/panel');
  if (i === -1) { return 'summary'; }

  var rest = p.slice(i + 6).replace(/^\/+/, '');
  return VIEWS.indexOf(rest) !== -1 ? rest : 'summary';
}

/* ═══ کنش‌ها ═══ */

/**
 * خواندن فیلترها از فرم.
 *
 * هر فیلد فقط وقتی خوانده می‌شود که واقعاً روی صفحه باشد؛ وگرنه
 * مقدار قبلی در state دست‌نخورده می‌ماند. بدون این شرط، کلیک روی
 * تب یا صفحه‌ی بعد در حالتی که فرم هنوز رندر نشده، فیلترها را
 * بی‌صدا پاک می‌کرد.
 */
function grabFilters() {
  if ($('#pn-status')) { S.filter.status = val('#pn-status'); }
  if ($('#pn-date')) { S.filter.date = val('#pn-date'); }
  if ($('#pn-q')) { S.filter.q = val('#pn-q').trim(); }
}

function setStatus(id, status) {
  if (status === 'delete' && !window.confirm('این نوبت برای همیشه حذف شود؟ این کار برگشت‌پذیر نیست.')) { return; }
  if (status === 'cancelled' && !window.confirm('با لغو نوبت، پیامک اطلاع‌رسانی برای مشتری ارسال می‌شود. مطمئن هستید؟')) { return; }

  S.busy = true;
  paintModal();

  post('panel/booking', { id: id, status: status }).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message || 'تغییر وضعیت ناموفق بود.', 'bad');
      paintModal();
      return;
    }

    toast(r.deleted ? 'نوبت حذف شد.' : 'وضعیت نوبت به‌روز شد.', 'ok');

    S.modal = null;
    S.list = null;
    S.board = null;
    S.summary = null;

    load(S.view);
  });
}

/**
 * آپلود پوستر.
 *
 * بدنه multipart است نه JSON، پس req() که همیشه JSON می‌فرستد
 * به کار نمی‌آید و مستقیم fetch می‌زنیم. Content-Type را دستی
 * ست نمی‌کنیم تا مرورگر boundary را خودش بگذارد.
 */
function uploadPoster(file) {
  if (!file) { return; }

  if (file.size > 4 * 1024 * 1024) {
    toast('حجم تصویر باید کمتر از ۴ مگابایت باشد.', 'bad');
    return;
  }

  var fd = new FormData();
  fd.append('file', file);
  fd.append('service_id', S.svcEdit.id);

  S.uploading = true;
  paintModal();

  fetch(ROOT + 'panel/poster', {
    method: 'POST',
    headers: { 'X-WP-Nonce': NONCE },
    credentials: 'include',
    body: fd
  })
    .then(function (r) { return r.json().catch(function () { return {}; }); })
    .then(function (j) {
      S.uploading = false;

      if (!j || j.success === false || !j.posterId) {
        toast((j && j.message) || 'آپلود ناموفق بود.', 'bad');
        paintModal();
        return;
      }

      S.svcEdit.posterId = j.posterId;
      S.svcEdit.poster = j.poster || '';
      S.services = null;

      toast('پوستر آپلود شد.', 'ok');
      paintModal();
    })
    .catch(function () {
      S.uploading = false;
      toast('ارتباط با سرور برقرار نشد.', 'bad');
      paintModal();
    });
}

function findService(id) {
  return (S.services || []).filter(function (x) { return x.id === id; })[0];
}

/** ارسال یک خدمت به سرور بدون باز کردن پنجره‌ی ویرایش. */
function pushService(svc, okMsg) {
  post('panel/service', {
    id: svc.id, title: svc.title, description: svc.description,
    price: svc.price, duration: svc.duration, weekdays: svc.weekdays,
    posterId: svc.posterId || 0, active: svc.active, sortOrder: svc.sortOrder
  }).then(function (r) {
    if (r.success === false) {
      toast(r.message || 'ذخیره ناموفق بود.', 'bad');
      load('services');
      return;
    }

    if (okMsg) { toast(okMsg, 'ok'); }

    load('services');
  });
}

/**
 * روشن/خاموش کردن خدمت از روی کارت.
 *
 * تغییر همان لحظه در صفحه دیده می‌شود و بعد به سرور می‌رود؛ اگر
 * سرور خطا داد، فهرست از نو خوانده می‌شود و مقدار واقعی برمی‌گردد.
 */
function toggleService(id) {
  var svc = findService(id);
  if (!svc) { return; }

  svc.active = svc.active ? 0 : 1;
  paint();

  pushService(svc, svc.active ? 'خدمت فعال شد.' : 'خدمت غیرفعال شد.');
}

/**
 * ساخت خدمت تازه از روی یکی از خدمت‌های موجود.
 *
 * پنجره‌ی ویرایش با همان قیمت و روزها و شرح باز می‌شود و فقط
 * عنوانش «(کپی)» می‌گیرد — برای خدمت‌هایی که با هم فرق کمی دارند
 * خیلی سریع‌تر از پر کردن فرم خالی است.
 */
function copyService(id) {
  var svc = findService(id);
  if (!svc) { return; }

  var copy = JSON.parse(JSON.stringify(svc));

  copy.id = 0;
  copy.title = svc.title + ' (کپی)';
  copy.poster = '';
  copy.posterId = 0;
  copy.sortOrder = 0;

  S.svcEdit = copy;
  paintModal();
}

/** جابه‌جایی ترتیب نمایش در اپ مشتری. */
function moveService(id, dir) {
  var list = S.services || [];
  var i = -1;

  list.forEach(function (x, n) { if (x.id === id) { i = n; } });

  var j = 'up' === dir ? i - 1 : i + 1;

  if (i === -1 || j < 0 || j >= list.length) { return; }

  /* ترتیب را با گام ۱۰ از نو شماره می‌زنیم تا مقادیر قدیمیِ
     نامرتب هم سر جای خودشان بیفتند. */
  var tmp = list[i];
  list[i] = list[j];
  list[j] = tmp;

  list.forEach(function (x, n) { x.sortOrder = (n + 1) * 10; });

  paint();

  pushService(list[i], '');
  pushService(list[j], 'ترتیب عوض شد.');
}

/**
 * حذف خدمت.
 *
 * سرور اگر نوبتی به این خدمت وصل باشد حذف را رد می‌کند و پیام
 * می‌دهد که به‌جایش غیرفعالش کنید — تاریخچه نباید عنوانش را از
 * دست بدهد.
 */
function deleteService(id) {
  if (!id) { return; }
  if (!window.confirm('این خدمت حذف شود؟ اگر نوبتی رویش ثبت شده باشد، حذف انجام نمی‌شود.')) { return; }

  S.busy = true;
  paintModal();

  post('panel/service/delete', { id: id }).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message || 'حذف ناموفق بود.', 'bad');
      paintModal();
      return;
    }

    toast(r.message || 'خدمت حذف شد.', 'ok');
    S.svcEdit = null;
    S.services = null;
    load('services');
  });
}

/** مقادیر فعلی فرم خدمت را در S.svcEdit می‌نشاند. */
function readSvcForm() {
  var s = S.svcEdit;
  if (!s || !$('#sv-title')) { return; }

  s.title = val('#sv-title').trim();
  s.description = val('#sv-desc').trim();
  s.price = Number(en(val('#sv-price')).replace(/\D/g, '') || 0);
  s.duration = val('#sv-dur').trim();
  s.active = checked('#sv-active') ? 1 : 0;

  if (s.ownCapacity) {
    BLOCKS.forEach(function (b) {
      var box = $('#sv-cap-' + b.key);
      if (box) { s.ownCapacity[b.key] = Math.max(0, Number(en(box.value).replace(/\D/g, '') || 0)); }
    });
  }
}

function saveService() {
  var s = S.svcEdit;

  readSvcForm();

  if (s.ownCapacity) {
    var total = BLOCKS.reduce(function (n, b) { return n + (s.ownCapacity[b.key] || 0); }, 0);

    if (!total) {
      toast('سهمیه‌ی جداگانه همه صفر است — دست‌کم یک شیفت باید ظرفیت داشته باشد.', 'bad');
      return;
    }
  }

  if (!s.title) { toast('عنوان خدمت را وارد کنید.', 'bad'); return; }

  S.busy = true;
  paintModal();

  post('panel/service', {
    id: s.id, title: s.title, description: s.description,
    price: s.price, duration: s.duration, weekdays: s.weekdays,
    posterId: s.posterId || 0,
    active: s.active, sortOrder: s.sortOrder,
    ownCapacity: s.ownCapacity || null
  }).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'ذخیره ناموفق بود.', 'bad'); paintModal(); return; }

    toast(r.message || 'خدمت ذخیره شد.', 'ok');
    S.svcEdit = null;
    S.services = null;
    load('services');
  });
}

function addClosure() {
  S.closeForm.date = val('#cl-date');
  S.closeForm.block = val('#cl-block');
  S.closeForm.reason = val('#cl-reason').trim();

  if (!S.closeForm.date) { toast('تاریخ را انتخاب کنید.', 'bad'); return; }

  S.busy = true;
  paint();

  post('panel/closure', S.closeForm).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'ثبت ناموفق بود.', 'bad'); paint(); return; }

    toast('بازه بسته شد.', 'ok');
    S.closeForm = { date: '', block: '', reason: '' };
    S.closures = null;
    S.board = null;
    load('closures');
  });
}

/* ═══ ورود ═══

   دو روش: کد پیامکی (اگر پیامک سایت تنظیم شده باشد) و نام کاربری/رمز.
   کد با autocomplete=one-time-code روی آیفون و اندروید از خود پیامک
   پیشنهاد می‌شود و با کامل شدن رقم‌ها خودکار فرستاده می‌شود. */

var otpTimer = 0;

function freshLogin() {
  return {
    mode: (C.otp && C.otp.on) ? 'otp' : 'pass',
    user: '', err: '', busy: false,
    phone: '', step: 'phone', masked: '', code: '', wait: 0
  };
}

function otpLen() { return (C.otp && C.otp.length) || 5; }

/* صفحه‌ی ورود با هر paint از نو ساخته می‌شود؛ آنچه تایپ شده نباید
   بپرد. رمز عمداً نگه داشته نمی‌شود. */
function keepLogin() {
  var u = $('#lg-user'), p = $('#lg-phone'), c = $('#lg-code');
  if (u) { S.login.user = u.value; }
  if (p) { S.login.phone = p.value; }
  if (c) { S.login.code = c.value; }
}

function clock(sec) {
  var m = Math.floor(sec / 60), r = sec % 60;
  return fa(m + ':' + (r < 10 ? '0' : '') + r);
}

function loginView() {
  var L = S.login;
  var otpOn = !!(C.otp && C.otp.on);
  var mode = otpOn ? L.mode : 'pass';
  var busy = L.busy ? ' disabled' : '';
  var form, btn;

  if (mode === 'pass') {
    form = '<p class="pn-login__p">با نام کاربری و رمزی که برایتان تعریف شده وارد شوید.</p>' +
      '<label class="pn-field"><span class="pn-field__l">نام کاربری</span>' +
      '<input class="pn-in" id="lg-user" dir="ltr" autocomplete="username" autocapitalize="none" value="' + esc(L.user) + '"></label>' +
      '<label class="pn-field"><span class="pn-field__l">رمز عبور</span>' +
      '<input class="pn-in" id="lg-pass" type="password" dir="ltr" autocomplete="current-password"></label>' +
      '<label class="pn-check" style="margin-bottom:16px">' +
      '<input type="checkbox" id="lg-remember" checked> مرا به خاطر بسپار</label>';
    btn = '<button class="pn-btn pn-btn--pri pn-btn--block" data-login' + busy + '>' +
      (L.busy ? '<span class="pn-spin"></span> در حال ورود…' : 'ورود به پنل') + '</button>';
  } else if (L.step === 'phone') {
    form = '<p class="pn-login__p">شماره موبایلی را که برای حساب پنل ثبت شده وارد کنید تا کد تایید پیامک شود.</p>' +
      '<label class="pn-field"><span class="pn-field__l">شماره موبایل</span>' +
      '<input class="pn-in" id="lg-phone" type="tel" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="09123456789" value="' + esc(L.phone) + '"></label>';
    btn = '<button class="pn-btn pn-btn--pri pn-btn--block" data-otp-send' + busy + '>' +
      (L.busy ? '<span class="pn-spin"></span> در حال ارسال…' : 'ارسال کد تایید') + '</button>';
  } else {
    form = '<p class="pn-login__p">کد ' + fa(otpLen()) + ' رقمی به <bdi dir="ltr">' + esc(fa(L.masked)) + '</bdi> پیامک شد.</p>' +
      '<label class="pn-field"><span class="pn-field__l">کد تایید</span>' +
      '<input class="pn-in pn-otp" id="lg-code" type="text" dir="ltr" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"' +
      ' maxlength="' + otpLen() + '" value="' + esc(L.code) + '"></label>';
    btn = '<button class="pn-btn pn-btn--pri pn-btn--block" data-otp-verify' + busy + '>' +
      (L.busy ? '<span class="pn-spin"></span> در حال ورود…' : 'ورود به پنل') + '</button>' +
      '<div class="pn-login__again">' +
        '<button class="pn-link" data-otp-edit>ویرایش شماره</button>' +
        (L.wait > 0
          ? '<span id="lg-wait">ارسال دوباره تا ' + clock(L.wait) + '</span>'
          : '<button class="pn-link" data-otp-send>ارسال دوباره‌ی کد</button>') +
      '</div>';
  }

  return '<div class="pn-login"><div class="pn-login__box">' +
    '<div class="pn-login__ic">' + I.engine + '</div>' +
    '<h2 class="pn-login__t">پنل رزرو نوبت</h2>' +
    (otpOn
      ? '<div class="pn-tabs pn-login__tabs">' +
          '<button class="pn-tabs__b' + (mode === 'otp' ? ' is-on' : '') + '" data-login-mode="otp">کد پیامکی</button>' +
          '<button class="pn-tabs__b' + (mode === 'pass' ? ' is-on' : '') + '" data-login-mode="pass">نام کاربری و رمز</button>' +
        '</div>'
      : '') +
    '<div class="pn-login__form">' + form +
      (L.err ? '<div class="pn-note pn-note--bad">' + I.alert + '<div>' + esc(L.err) + '</div></div>' : '') +
    '</div>' +
    btn +
    '<a class="pn-btn pn-btn--soft pn-btn--block" style="margin-top:8px" href="' + esc(C.app || '/') + '">بازگشت به اپ</a>' +
    installBtn('pn-btn pn-btn--block pn-login__inst', 'نصب پنل روی گوشی') +
    '</div></div>';
}

/* شمارش معکوس فقط متن خودش را عوض می‌کند؛ paint هر ثانیه کد نیمه‌تایپ‌شده
   را جابه‌جا می‌کرد و روی آیفون صفحه‌کلید را می‌بست. */
function startWait(sec) {
  clearInterval(otpTimer);
  S.login.wait = sec;

  otpTimer = setInterval(function () {
    S.login.wait = Math.max(0, S.login.wait - 1);

    if (S.login.wait <= 0) {
      clearInterval(otpTimer);
      otpTimer = 0;
      // دکمه‌ی «ارسال دوباره» ظاهر شود؛ اگر کاربر رفته سراغ تب رمز،
      // فرم را از نو نمی‌سازیم که رمزِ نیمه‌تایپ‌شده نپرد.
      if (!S.can && S.login.mode === 'otp' && S.login.step === 'code') { paint(); }
      return;
    }

    var el = document.getElementById('lg-wait');
    if (el) { el.textContent = 'ارسال دوباره تا ' + clock(S.login.wait); }
  }, 1000);
}

function otpSend() {
  var L = S.login;
  keepLogin();

  var phone = en(L.phone).replace(/\D/g, '');
  L.err = '';

  if (phone.length < 10) {
    L.err = 'شماره موبایل را کامل وارد کنید.';
    paint();
    return;
  }

  L.busy = true;
  paint();

  post('panel/otp/send', { phone: phone }).then(function (r) {
    L.busy = false;

    if (r.success === false) {
      L.err = r.message || 'ارسال کد ناموفق بود.';
      paint();
      return;
    }

    L.step = 'code';
    L.masked = r.masked || '';
    L.code = '';
    startWait(Number(r.resendAfter) || 120);
    paint();
  });
}

function otpVerify() {
  var L = S.login;
  keepLogin();

  var code = en(L.code).replace(/\D/g, '');
  L.err = '';

  if (code.length !== otpLen()) {
    L.err = 'کد تایید را کامل وارد کنید.';
    paint();
    return;
  }

  L.busy = true;
  paint();

  post('panel/otp/verify', { phone: en(L.phone).replace(/\D/g, ''), code: code }).then(function (r) {
    L.busy = false;

    if (r.success === false) {
      L.err = r.message || 'ورود ناموفق بود.';
      L.code = '';
      var c = $('#lg-code');
      if (c) { c.value = ''; }
      paint();
      return;
    }

    loggedIn(r);
  });
}

function doLogin() {
  S.login.user = val('#lg-user').trim();
  var pass = val('#lg-pass');
  var remember = checked('#lg-remember');

  S.login.err = '';

  if (!S.login.user || !pass) {
    S.login.err = 'نام کاربری و رمز را وارد کنید.';
    paint();
    return;
  }

  S.login.busy = true;
  paint();

  post('panel/login', { user: S.login.user, pass: pass, remember: remember }).then(function (r) {
    S.login.busy = false;

    if (r.success === false) {
      S.login.err = r.message || 'ورود ناموفق بود.';
      paint();
      return;
    }

    loggedIn(r);
  });
}

function loggedIn(r) {
  // بعد از ورود شناسه‌ی کاربر عوض شده، پس nonce تازه لازم است
  if (r.nonce) { NONCE = r.nonce; }

  clearInterval(otpTimer);
  otpTimer = 0;

  S.can = true;
  S.login = freshLogin();

  toast('خوش آمدید' + (r.name ? '، ' + r.name : '') + '.', 'ok');
  load(S.view);
}

function shiftBoard(days) {
  var base = S.boardFrom || (S.board && S.board.from) || new Date().toISOString().slice(0, 10);
  var d = new Date(base + 'T00:00:00');
  d.setDate(d.getDate() + days);

  S.boardFrom = d.toISOString().slice(0, 10);
  S.board = null;
  load('board');
}

/* ═══ رویدادها ═══ */

function bind() {
  // انتخابگر تاریخ شمسی: مقدار میلادی را در input مخفی می‌گذارد
  J.bind(function (id, g) {
    if (id === 'cl-date') { S.closeForm.date = g; }

    /* تاریخ مثل تب‌های دامنه بلافاصله اعمال می‌شود. پیش از این فقط
       در state می‌نشست و تا زدن «اعمال» هیچ نشانه‌ای در صفحه دیده
       نمی‌شد — نه چیپ فیلتر فعال، نه تغییری در فهرست. */
    if (id === 'pn-date') {
      S.filter.date = g;
      S.filter.page = 1;
      S.list = null;
      load('bookings');
    }
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    function up(s) { return t.closest ? t.closest(s) : null; }
    var el;

    if ((el = up('[data-view]'))) { e.preventDefault(); go(el.getAttribute('data-view')); return; }
    if (up('[data-menu]')) { e.preventDefault(); S.menu = true; paint(); return; }
    if (up('[data-menu-close]')) { e.preventDefault(); S.menu = false; paint(); return; }

    if (up('[data-login]')) { e.preventDefault(); doLogin(); return; }

    if ((el = up('[data-login-mode]'))) {
      e.preventDefault();
      /* زدن تب فعلی نباید فرم را از نو بسازد؛ رمزی که پر شده (مثلاً با
         پرکردن خودکار Keychain آیفون) عمداً نگه داشته نمی‌شود و می‌پرید. */
      if (el.getAttribute('data-login-mode') === S.login.mode) { return; }
      keepLogin();
      S.login.mode = el.getAttribute('data-login-mode');
      S.login.err = '';
      paint();
      return;
    }

    if (up('[data-otp-send]')) { e.preventDefault(); otpSend(); return; }
    if (up('[data-otp-verify]')) { e.preventDefault(); otpVerify(); return; }

    if (up('[data-otp-edit]')) {
      e.preventDefault();
      clearInterval(otpTimer);
      otpTimer = 0;
      S.login.step = 'phone';
      S.login.code = '';
      S.login.wait = 0;
      S.login.err = '';
      var cd = $('#lg-code');
      if (cd) { cd.value = ''; }
      paint();
      return;
    }

    if (up('[data-install]')) {
      e.preventDefault();
      if (S.menu) { S.menu = false; paint(); }
      install();
      return;
    }

    if (up('[data-reload]')) { e.preventDefault(); S[S.view] = null; S.summary = null; load(S.view); return; }

    if ((el = up('[data-open]'))) {
      e.preventDefault();
      var id = Number(el.getAttribute('data-open'));
      var item = findBooking(id);
      if (item) { S.modal = { item: item }; paintModal(); }
      return;
    }

    if ((el = up('[data-status]'))) { e.preventDefault(); setStatus(S.modal.item.id, el.getAttribute('data-status')); return; }

    if (up('[data-close]') || (up('[data-scrim]') && t.hasAttribute && t.hasAttribute('data-scrim'))) {
      e.preventDefault();
      S.modal = null;
      S.svcEdit = null;
      paintModal();
      return;
    }

    /* ── مشتری‌ها ── */

    if (up('[data-health-sweep]')) {
      e.preventDefault();
      sweepPast();
      return;
    }

    if (up('[data-health-recheck]')) {
      e.preventDefault();
      S.health = null;
      paint();
      loadHealth();
      return;
    }

    if (up('[data-sched-save]')) {
      e.preventDefault();
      saveSchedule();
      return;
    }

    if (up('[data-cust-export]')) {
      e.preventDefault();
      exportCustomers();
      return;
    }

    if (up('[data-cust-search]')) {
      e.preventDefault();
      S.custQ = ($('#cu-q') ? val('#cu-q').trim() : '');
      S.custPage = 1;
      load('customers');
      return;
    }

    if (up('[data-cust-clear]')) {
      e.preventDefault();
      S.custQ = '';
      S.custPage = 1;
      load('customers');
      return;
    }

    if ((el = up('[data-cust-page]'))) {
      e.preventDefault();
      S.custPage = Number(el.getAttribute('data-cust-page'));
      load('customers');
      return;
    }

    /* «نوبت‌هایش» مشتری را در فهرست نوبت‌ها باز می‌کند: شماره در
       کادر جستجو و دامنه روی همه‌ی تاریخ‌ها، وگرنه نوبت‌های گذشته‌اش
       دیده نمی‌شد. */
    if ((el = up('[data-cust-bookings]'))) {
      e.preventDefault();
      S.filter = { scope: 'all', status: '', date: '', q: el.getAttribute('data-cust-bookings'), page: 1 };
      S.list = null;
      go('bookings');
      return;
    }

    if ((el = up('[data-scope]'))) {
      e.preventDefault();
      grabFilters();

      /* تاریخ و دامنه هر دو روی همان محور (روزِ نوبت) کار می‌کنند و
         تاریخ برنده است. اگر با انتخاب دامنه پاک نشود، کلیک روی
         تب‌ها هیچ اثری ندارد و کاربر در فیلتر تاریخ گیر می‌افتد.
         آخرین کلیک تعیین‌کننده است. */
      S.filter.date = '';
      S.filter.scope = el.getAttribute('data-scope');
      S.filter.page = 1;
      S.list = null;
      load('bookings');
      return;
    }

    if ((el = up('[data-unfilter]'))) {
      e.preventDefault();
      grabFilters();
      S.filter[el.getAttribute('data-unfilter')] = '';
      S.filter.page = 1;
      S.list = null;
      load('bookings');
      return;
    }

    if (up('[data-apply]')) { e.preventDefault(); grabFilters(); S.filter.page = 1; S.list = null; load('bookings'); return; }

    if (up('[data-clear]')) {
      e.preventDefault();
      S.filter = { scope: 'upcoming', status: '', date: '', q: '', page: 1 };
      S.list = null;
      load('bookings');
      return;
    }

    if ((el = up('[data-page]'))) {
      e.preventDefault();
      grabFilters();
      S.filter.page = Number(el.getAttribute('data-page'));
      S.list = null;
      load('bookings');
      return;
    }

    if (up('[data-svc-new]')) {
      e.preventDefault();

      /* شناسه‌ی صفر یعنی «هنوز ذخیره نشده»؛ سرور همین را به‌عنوان
         علامت ساخت خدمت تازه می‌شناسد. شنبه تا چهارشنبه پیش‌فرض
         است، چون پنجشنبه و جمعه معمولاً تعطیل‌اند. */
      S.svcEdit = {
        id: 0, title: '', description: '', price: 0, duration: '',
        weekdays: [6, 0, 1, 2, 3], active: 1, poster: '', posterId: 0, sortOrder: 0
      };

      paintModal();
      return;
    }

    if ((el = up('[data-svc-toggle]'))) {
      e.preventDefault();
      toggleService(Number(el.getAttribute('data-svc-toggle')));
      return;
    }

    if ((el = up('[data-svc-copy]'))) {
      e.preventDefault();
      copyService(Number(el.getAttribute('data-svc-copy')));
      return;
    }

    if ((el = up('[data-svc-move]'))) {
      e.preventDefault();
      var mv = el.getAttribute('data-svc-move').split(':');
      moveService(Number(mv[0]), mv[1]);
      return;
    }

    if ((el = up('[data-svc-del]'))) {
      e.preventDefault();
      deleteService(Number(el.getAttribute('data-svc-del')));
      return;
    }

    if ((el = up('[data-svc]'))) {
      e.preventDefault();
      var sid = Number(el.getAttribute('data-svc'));
      var svc = (S.services || []).filter(function (x) { return x.id === sid; })[0];
      if (svc) { S.svcEdit = JSON.parse(JSON.stringify(svc)); paintModal(); }
      return;
    }

    if ((el = up('[data-cap-mode]'))) {
      e.preventDefault();

      // مقادیر فعلی فرم پیش از بازسازی پنجره حفظ شوند
      readSvcForm();

      if (el.getAttribute('data-cap-mode') === 'own') {
        if (!S.svcEdit.ownCapacity) {
          var def = {};
          BLOCKS.forEach(function (b) { def[b.key] = 10; });
          S.svcEdit.ownCapacity = def;
        }
      } else {
        S.svcEdit.ownCapacity = null;
      }

      paintModal();
      return;
    }

    if ((el = up('#sv-days [data-day]'))) {
      e.preventDefault();
      var d = Number(el.getAttribute('data-day'));
      var list = S.svcEdit.weekdays || [];
      var i = list.indexOf(d);

      if (i === -1) { list.push(d); } else { list.splice(i, 1); }

      S.svcEdit.weekdays = list;
      el.classList.toggle('is-on', i === -1);
      return;
    }

    if (up('[data-poster-clear]')) {
      e.preventDefault();
      S.svcEdit.posterId = 0;
      S.svcEdit.poster = '';
      paintModal();
      return;
    }

    if (up('[data-svc-save]')) { e.preventDefault(); saveService(); return; }
    if (up('[data-close-add]')) { e.preventDefault(); addClosure(); return; }

    if ((el = up('[data-close-del]'))) {
      e.preventDefault();
      post('panel/closure', { remove: Number(el.getAttribute('data-close-del')) }).then(function () {
        toast('بازه دوباره باز شد.', 'ok');
        S.closures = null;
        S.board = null;
        load('closures');
      });
      return;
    }


    if (up('[data-board-prev]')) { e.preventDefault(); shiftBoard(-7); return; }
    if (up('[data-board-next]')) { e.preventDefault(); shiftBoard(7); return; }
    if (up('[data-board-today]')) { e.preventDefault(); S.boardFrom = ''; S.board = null; load('board'); return; }
  });

  /* جستجوی زنده: نتیجه حین تایپ می‌آید و لازم نیست کاربر دکمه بزند.
     ۳۵۰ میلی‌ثانیه صبر می‌کنیم تا هر حرف یک درخواست نشود. */
  var typeTimer = 0;

  document.addEventListener('input', function (e) {
    var id = e.target && e.target.id;

    /* کد: ارقام فارسی به لاتین، و با کامل شدن خودکار فرستاده می‌شود
       (پیشنهاد کد از پیامک، همه‌ی رقم‌ها را یک‌جا می‌گذارد). */
    if (id === 'lg-code') {
      var v = en(e.target.value).replace(/\D/g, '').slice(0, otpLen());
      if (v !== e.target.value) { e.target.value = v; }
      if (v.length === otpLen() && !S.login.busy) { otpVerify(); }
      return;
    }

    if (id !== 'pn-q' && id !== 'cu-q') { return; }

    clearTimeout(typeTimer);

    typeTimer = setTimeout(function () {
      if (id === 'cu-q') {
        var q = val('#cu-q').trim();
        if (q === S.custQ) { return; }

        S.custQ = q;
        S.custPage = 1;
        load('customers');
        return;
      }

      grabFilters();

      if (S.filter.q === S.lastQ) { return; }

      S.lastQ = S.filter.q;
      S.filter.page = 1;
      load('bookings');
    }, 350);
  });

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'sv-poster' && e.target.files && e.target.files[0]) {
      uploadPoster(e.target.files[0]);
    }

    /* وضعیت هم بلافاصله اعمال می‌شود تا سه فیلترِ تک‌انتخابی
       (دامنه، وضعیت، تاریخ) رفتار یکسانی داشته باشند. «اعمال» برای
       کادر جستجو می‌ماند که تایپی است. */
    if (e.target && e.target.id === 'pn-status') {
      grabFilters();
      S.filter.page = 1;
      S.list = null;
      load('bookings');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && S.menu) { S.menu = false; paint(); return; }

    if (e.key === 'Escape' && (S.modal || S.svcEdit)) {
      S.modal = null;
      S.svcEdit = null;
      paintModal();
    }

    if (e.key === 'Enter' && $('#lg-pass')) { e.preventDefault(); doLogin(); return; }
    if (e.key === 'Enter' && $('#lg-phone')) { e.preventDefault(); otpSend(); return; }
    if (e.key === 'Enter' && $('#lg-code')) { e.preventDefault(); otpVerify(); return; }

    if (e.key === 'Enter' && $('#pn-q') && document.activeElement === $('#pn-q')) {
      e.preventDefault();
      grabFilters();
      S.filter.page = 1;
      S.list = null;
      load('bookings');
    }
  });

  window.addEventListener('popstate', function () { S.view = readRoute(); load(S.view); });
}

/** پیدا کردن یک نوبت از هر جایی که در حافظه داریم. */
function findBooking(id) {
  var hit = null;

  (S.list && S.list.items || []).forEach(function (x) { if (x.id === id) { hit = x; } });
  if (hit) { return hit; }

  ((S.board && S.board.days) || []).forEach(function (d) {
    (d.blocks || []).forEach(function (b) {
      (b.items || []).forEach(function (x) { if (x.id === id) { hit = x; } });
    });
  });

  return hit;
}

/* ═══ نصب روی گوشی ═══

   پنل manifest خودش را دارد (CMB_Panel_Pwa) و جدا از اپ اصلی سایت نصب
   می‌شود. اندروید و کروم دسکتاپ پنجره‌ی نصب بومی دارند؛ آیفون ندارد و
   فقط از منوی اشتراک‌گذاری می‌شود، پس آن‌جا راهنما نشان داده می‌شود. */

var PWA = C.pwa || null;
var installEvt = null;
var UA = navigator.userAgent || '';
var IS_IOS = /iphone|ipad|ipod/i.test(UA) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
var IS_MOBILE = IS_IOS || /android|mobile/i.test(UA);

function standalone() {
  return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
    window.navigator.standalone === true;
}

/** دکمه فقط وقتی معنی دارد که واقعاً کاری از دستش بربیاید. */
function canInstall() {
  return !!PWA && !standalone() && (!!installEvt || IS_MOBILE);
}

/* دکمه همیشه ساخته می‌شود (مگر داخل خود اپ نصب‌شده) و فقط پنهان/پیدا
   می‌شود. رویداد نصب کروم ممکن است بعد از رندر برسد؛ بازسازی صفحه در
   آن لحظه نام کاربری نیمه‌تایپ‌شده‌ی فرم ورود را پاک می‌کرد. */
function installBtn(cls, label) {
  if (!PWA || standalone()) { return ''; }

  return '<button class="' + cls + '" data-install' + (canInstall() ? '' : ' hidden') + '>' +
    I.phone + '<span>' + label + '</span></button>';
}

function syncInstall() {
  var show = canInstall();
  $$('[data-install]').forEach(function (el) { el.hidden = !show; });
}

function install() {
  if (installEvt) {
    var ev = installEvt;
    installEvt = null; // هر رویداد فقط یک بار prompt می‌شود

    try {
      ev.prompt();
      Promise.resolve(ev.userChoice).then(function (c) {
        if (c && c.outcome === 'accepted') { toast('پنل نصب شد.', 'ok'); }
        syncInstall();
      }, syncInstall);
      return;
    } catch (err) { /* می‌رسیم به راهنما */ }
  }

  S.modal = { install: true };
  paintModal();
}

function installModal() {
  var name = esc((PWA && PWA.name) || 'مدیریت رزرو');

  var steps = IS_IOS ? [
    'این صفحه را در <b>Safari</b> باز کنید.',
    'دکمه‌ی <b>اشتراک‌گذاری</b> ' + I.share + ' را بزنید (پایین یا بالای صفحه).',
    'گزینه‌ی <b><bdi>Add to Home Screen</bdi></b> یا «افزودن به صفحه‌ی اصلی» را انتخاب کنید و <b><bdi>Add</bdi></b> را بزنید.'
  ] : [
    'منوی مرورگر <b><bdi>⋮</bdi></b> را باز کنید.',
    'گزینه‌ی <b><bdi>Install app</bdi></b> یا <b><bdi>Add to Home screen</bdi></b> («نصب برنامه» یا «افزودن به صفحه‌ی اصلی») را بزنید.'
  ];

  return '<div class="pn-modal__hd"><div class="pn-modal__t">نصب پنل روی گوشی</div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    '<div class="pn-install">' +
      (PWA && PWA.icon ? '<img class="pn-install__ic" src="' + esc(PWA.icon) + '" alt="">' : '') +
      '<div><div class="pn-install__n">' + name + '</div>' +
      '<div class="pn-install__s">آیکونی جدا از اپ اصلی سایت که مستقیم همین پنل را باز می‌کند.</div></div>' +
    '</div>' +

    '<ol class="pn-steps">' + steps.map(function (s, i) {
      return '<li><span class="pn-steps__n">' + fa(i + 1) + '</span><div>' + s + '</div></li>';
    }).join('') + '</ol>' +

    note('info', I.info, 'اگر بار اول با باز کردن آیکون صفحه‌ی ورود دیدید، یک بار با همین نام کاربری و رمز وارد شوید.') +

    '<div class="pn-modal__ft"><button class="pn-btn pn-btn--pri pn-btn--block" data-close>متوجه شدم</button></div>';
}

if (PWA) {
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); // پنجره‌ی خودکار کروم نه؛ با دکمه‌ی پنل نشان داده می‌شود
    installEvt = e;
    syncInstall();
  });

  window.addEventListener('appinstalled', function () {
    installEvt = null;
    syncInstall();
  });
}

/* ═══ راه‌اندازی ═══ */

function boot() {
  S.view = readRoute();
  bind();
  load(S.view);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

})();
