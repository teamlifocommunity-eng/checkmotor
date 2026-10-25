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

var VIEWS = ['summary', 'board', 'bookings', 'customers', 'services', 'closures', 'settings', 'refunds',
             'bwallet', 'profile', 'agencies', 'report', 'smslog', 'debug'];
var PAY = C.pay || { on: false, used: false, canRefund: false, shopPct: 100, setup: null };

/* برگشت‌ها به کیف پول مشتری می‌رود، نه کارت: بخش «بیعانه و کیف پول» */


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

// وضعیت‌های پرداخت بیعانه فقط وقتی پرداخت در کار است
if (PAY.used) {
  STATUSES.pending = 'در انتظار پرداخت';
  STATUSES.expired = 'پرداخت نشد';
}

var S = {
  view: 'summary',
  can: !!C.can,
  loading: false, busy: false,
  toasts: [], modal: null,

  summary: (C.boot || null),
  board: null, boardFrom: '',
  list: null, filter: { scope: 'upcoming', status: '', date: '', q: '', page: 1 },
  services: null, svcEdit: null, uploading: false,
  customers: null, custQ: '', custPage: 1, custBranch: '', lastQ: '', exporting: false,
  smslog: null, slKind: '', slStatus: '', slQ: '', slPage: 1,
  fetching: false,
  sched: null, schedFull: '', health: null, sweeping: false,
  closures: null, closeForm: { date: '', block: '', reason: '' },
  refunds: null, refWhich: 'wallet', refEdit: null,   // بیعانه و کیف پول؛ refunds = برگشت‌های مانده
  wallets: null, walQ: '', walFilter: 'balance', walPage: 1,             // کیف پول مشتری‌ها
  payReview: null, simFor: 0,                      // بررسی مبلغ‌های هر خدمت
  payReport: null, repDays: 30, repSandbox: false,  // گزارش
  // نمایندگی‌ها: کیف پول و پروفایل شعبه‌ی جاری، و بخش‌های مدیر سایت
  svcMeta: null, bwallet: null, bwPage: 1, bwAmount: '', profile: null,
  agencies: null, agReport: null, agFrom: '', agTo: '',
  // آزمون و عیب‌یابی (فقط مدیر سایت)
  dbg: { tab: 'checks', checks: null, sim: null, simBranch: 0, simService: 0, simPhone: '', simBusy: false,
         tries: {}, tryBusy: '', st: null, stBusy: false, stHist: null,
         log: null, logF: { level: '', source: '', q: '' }, logPage: 1, logOpen: 0, info: null },
  login: freshLogin(),
  menu: false,
  got: {}   // نشانی هر درخواستِ موفق ← زمانش؛ برای نمایش فوری هنگام جابه‌جایی
};

/* داده‌ی تخته و نوبت‌های پیش‌رو همراه صفحه آمده است (CMB_PANEL.preload). */
(function () {
  var pre = C.preload || {};
  if (pre.board && Array.isArray(pre.board.days)) { S.board = pre.board; }
  if (pre.bookings && Array.isArray(pre.bookings.items)) { S.list = pre.bookings; }
})();

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
var dataEpoch = 0, loadSequence = 0, activeLoads = {}, pendingWrites = 0, switchingBranch = false;

function freshNonce() {
  if (nonceWait) { return nonceWait; }

  var controller = window.AbortController ? new AbortController() : null;
  var timeout = controller ? setTimeout(function () { controller.abort(); }, 15000) : null;
  nonceWait = fetch(NURL, { credentials: 'include', cache: 'no-store', signal: controller ? controller.signal : undefined })
    .then(function (r) { return r.json(); })
    .then(function (n) {
      if (n && n.nonce) { NONCE = n.nonce; }
      if (n && typeof n.canManage !== 'undefined') { S.can = !!n.canManage; }
      if (timeout) { clearTimeout(timeout); }
      nonceWait = null;
      return n || {};
    })
    .catch(function () { if (timeout) { clearTimeout(timeout); } nonceWait = null; return {}; });

  return nonceWait;
}

function req(method, path, body, retried, requestBranch) {
  if (typeof requestBranch === 'undefined') { requestBranch = ctx().isAdmin ? Number(ctx().branchId || 0) : 0; }
  var controller = window.AbortController ? new AbortController() : null;
  var timer = controller ? setTimeout(function () { controller.abort(); }, path === 'panel/debug/selftest' ? 120000 : 30000) : null;
  var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
  if (requestBranch) { headers['X-CMB-Branch'] = String(requestBranch); }
  return fetch(ROOT + path, {
    method: method, headers: headers, credentials: 'include', cache: 'no-store',
    signal: controller ? controller.signal : undefined,
    body: body ? JSON.stringify(body) : undefined
  }).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (timer) { clearTimeout(timer); }
      if (!j || typeof j !== 'object' || Array.isArray(j)) {
        return { success: false, code: 'cmb_bad_response', message: 'پاسخ سرور معتبر نیست. صفحه را تازه کنید یا کمی بعد دوباره تلاش کنید.' };
      }
      var stale = (r.status === 401 || r.status === 403) &&
        (!j.code || String(j.code).indexOf('nonce') !== -1 || String(j.code).indexOf('cookie') !== -1);
      if (stale && !retried) {
        return freshNonce().then(function () { return req(method, path, body, true, requestBranch); });
      }
      if (!r.ok) {
        var ref = j.data && j.data.ref ? String(j.data.ref) : '';
        var msg = j.message || 'خطایی رخ داد.';
        if (ref && ((C.dbg && C.dbg.refs) || r.status >= 500)) { msg += ' (کد پیگیری: ' + ref + ')'; }
        return { success: false, message: msg, code: j.code || '', ref: ref };
      }
      if (typeof j.success === 'undefined') { j.success = true; }
      return j;
    });
  }).catch(function (err) {
    if (timer) { clearTimeout(timer); }
    return { success: false, message: err && err.name === 'AbortError'
      ? (method === 'GET' ? 'پاسخ سرور طول کشید؛ دوباره دریافت اطلاعات را بزنید.' : 'پاسخ سرور طول کشید؛ پیش از تکرار عملیات، نتیجه را با تازه‌سازی بررسی کنید.')
      : 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.' };
  });
}

function get(p) { return req('GET', p); }

function uploadRequest(form, retried, branchId) {
  if (typeof branchId === 'undefined') { branchId = Number(ctx().branchId || 0); }
  var controller = window.AbortController ? new AbortController() : null;
  var timeout = controller ? setTimeout(function () { controller.abort(); }, 45000) : null;
  return fetch(ROOT + 'panel/poster', {
    method: 'POST', headers: { 'X-WP-Nonce': NONCE, 'X-CMB-Branch': String(branchId) },
    credentials: 'include', body: form, signal: controller ? controller.signal : undefined
  }).then(function (r) {
    return r.json().catch(function () { return {}; }).then(function (j) {
      if (timeout) { clearTimeout(timeout); }
      if (!retried && r.status === 403 && /nonce|cookie/.test(String(j.code || ''))) {
        return freshNonce().then(function () { return uploadRequest(form, true, branchId); });
      }
      if (!r.ok || !j || !j.posterId) { return { success: false, message: j && j.message || 'آپلود ناموفق بود؛ دوباره تلاش کنید.' }; }
      return j;
    });
  }).catch(function () {
    if (timeout) { clearTimeout(timeout); }
    return { success: false, message: 'ارسال تصویر کامل نشد. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.' };
  });
}
/* هر تغییری (ثبت، لغو، ذخیره…) می‌تواند داده‌ی هر نمایی را عوض کند؛
   پس بعد از آن، جابه‌جایی بین نماها دوباره از سرور می‌خواند. */
function invalidateData() {
  dataEpoch++;
  activeLoads = {};
  S.fetching = false;
  S.got = {};
}
function post(p, b) {
  if (switchingBranch && p !== 'panel/branch/switch') {
    return Promise.resolve({ success: false, message: 'در حال تغییر شعبه هستیم؛ چند لحظه صبر کنید.' });
  }
  pendingWrites++;
  return req('POST', p, b).then(function (r) {
    pendingWrites--;
    if (r && r.success !== false) { invalidateData(); }
    return r;
  });
}

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
  card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></svg>',
  wallet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7.5V6a2 2 0 0 0-2-2H5.5A2.5 2.5 0 0 0 3 6.5v11A2.5 2.5 0 0 0 5.5 20H19a2 2 0 0 0 2-2v-8.5a2 2 0 0 0-2-2H5.5A2.5 2.5 0 0 1 3 6.5"/><circle cx="16.5" cy="13.75" r="1.3" fill="currentColor" stroke="none"/></svg>',
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
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10.5c0 6.5-8 12.5-8 12.5s-8-6-8-12.5a8 8 0 0 1 16 0z"/><circle cx="12" cy="10.5" r="2.8"/></svg>',
  ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/></svg>',
  share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v11"/><path d="M8 7.2l4-3.7 4 3.7"/><path d="M8.5 10.5h-2A1.5 1.5 0 0 0 5 12v7a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19v-7a1.5 1.5 0 0 0-1.5-1.5h-2"/></svg>',
  bug: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="7.5" y="6.5" width="9" height="13" rx="4.5"/><path d="M12 10v9.5M9.5 4.5l1.3 2M14.5 4.5l-1.3 2M7.5 11H4M20 11h-3.5M7.5 15.5H4.5M19.5 15.5h-3M8 7.8 5.5 6M16 7.8l2.5-1.8"/></svg>',
  empty: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="16" rx="2.6"/><path d="M3.5 9.5h17M8 2.8v3.4M16 2.8v3.4M9 14.5h6"/></svg>'
};

/* ═══ اجزای مشترک ═══ */

/** عنوان نمای جاری — برای نوار بالای موبایل. */
function viewTitle() {
  var list = nav();
  for (var i = 0; i < list.length; i++) {
    if (list[i].k === S.view) { return list[i].t; }
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

/**
 * منوی کاربر جاری.
 *
 * مرکز همان منوی قبلی را می‌بیند. نمایندگی بخش‌های مرکز (کیف پول
 * مشتری‌ها، برگشت‌ها) را ندارد و «کیف پول نمایندگی» و «پروفایل» دارد؛
 * اپراتور تنظیمات شیفت را نمی‌بیند. مدیر سایت «نمایندگی‌ها» و «گزارش
 * نمایندگی‌ها» را هم دارد.
 */
function nav() {
  var c = ctx();
  var list = NAV.filter(function (n) {
    return n.k !== 'settings' || c.can.schedule;
  });

  // «بیعانه و کیف پول» فقط برای مرکز، و فقط وقتی پرداخت بیعانه روشن است یا روزی بوده
  if (PAY.used && c.can.center && !c.agency) { list.splice(4, 0, { k: 'refunds', t: 'بیعانه و کیف پول', ic: I.wallet }); }

  if (c.agency && c.can.wallet) { list.push({ k: 'bwallet', t: 'کیف پول نمایندگی', ic: I.wallet }); }
  if (c.agency && c.can.profile) { list.push({ k: 'profile', t: 'پروفایل نمایندگی', ic: I.user }); }

  if (c.isAdmin && c.ready) {
    list.push({ k: 'agencies', t: 'نمایندگی‌ها', ic: I.engine });
    list.push({ k: 'report', t: 'گزارش نمایندگی‌ها', ic: I.gauge });
  }

  if (c.isAdmin) { list.push({ k: 'smslog', t: 'پیامک و کد تایید', ic: I.chat }); }
  if (c.isAdmin) { list.push({ k: 'debug', t: 'آزمون و عیب‌یابی', ic: I.bug }); }

  return list;
}

/** آیا این نما در منوی کاربر جاری هست؟ */
function allowed(view) {
  return nav().some(function (n) { return n.k === view; });
}

/** نام شعبه و انتخابگر شعبه‌ی مدیر سایت، زیر نام برند. */
function branchBox() {
  var c = ctx();

  if (!c.branch || (!c.multi && !c.agency)) { return ''; }

  if (c.isAdmin && c.branches && c.branches.length > 1) {
    return '<label class="pn-branch"><span class="pn-branch__l">شعبه‌ی نمایش داده‌شده</span>' +
      '<select class="pn-branch__sel" id="pn-branch" aria-label="انتخاب شعبه">' +
        c.branches.map(function (b) {
          return '<option value="' + b.id + '"' + (b.id === c.branchId ? ' selected' : '') + '>' +
            esc(b.title) + (b.type === 'agency' ? '' : ' (مرکز)') + (b.status !== 'active' ? ' — ' + (b.status === 'draft' ? 'پیش‌نویس' : 'غیرفعال') : '') +
            '</option>';
        }).join('') +
      '</select></label>';
  }

  return '<div class="pn-branch"><span class="pn-branch__l">' + (c.agency ? 'نمایندگی' : 'شعبه') + '</span>' +
    '<b class="pn-branch__t">' + esc(c.branch.title) + '</b>' +
    (c.branch.status !== 'active' ? ' ' + branchBadge(c.branch.status, c.branch.statusLabel) : '') + '</div>';
}

function side() {
  var up = S.summary && S.summary.counts ? S.summary.counts.upcoming : 0;

  return '<aside class="pn-side">' +
    '<div class="pn-side__brand">' +
      '<div class="pn-side__logo">' + I.engine + '</div>' +
      '<div><div class="pn-side__t">چک موتور</div>' +
      '<div class="pn-side__s">پنل مدیریت نوبت‌ها</div></div>' +
    '</div>' +
    branchBox() +
    '<button class="pn-side__x" data-menu-close aria-label="بستن منو">' + I.x + '</button>' +
    '<nav class="pn-side__nav">' +
      nav().map(function (n) {
        var rf = (S.summary && S.summary.counts) ? S.summary.counts.refunds : 0;
        var badge = (n.k === 'bookings' && up) ? '<span class="pn-count">' + fa(up) + '</span>'
          : ((n.k === 'refunds' && rf) ? '<span class="pn-count pn-count--hot">' + fa(rf) + '</span>' : '');
        return '<button class="pn-side__b' + (S.view === n.k ? ' is-on' : '') + '" data-view="' + n.k + '">' +
          n.ic + '<span>' + n.t + '</span>' + badge + '</button>';
      }).join('') +
    '</nav>' +
    '<div class="pn-side__foot">' +
      '<div class="pn-side__me">' + esc(C.me || '') +
        (ctx().roleLabel ? '<span class="pn-side__role">' + esc(ctx().roleLabel) + '</span>' : '') + '</div>' +
      installBtn('pn-side__b', 'نصب روی گوشی') +
      '<a class="pn-side__b" href="' + esc(C.app || '/') + '">' + I.car + '<span>دیدن اپ مشتری</span></a>' +
      '<button class="pn-side__b pn-side__b--out" data-logout>' + I.out + '<span>خروج از حساب</span></button>' +
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

/* ═══ شعبه و نقش ═══

   سرور همراه خلاصه می‌گوید کاربر کدام شعبه را می‌بیند و چه اختیاری دارد
   (ctx). اگر نگفت (پاسخ کهنه)، پنل همان رفتار قبلی مرکز را دارد. سرور
   هر کار را دوباره خودش بررسی می‌کند؛ این فقط برای نمایش است. */
var CTX0 = {
  role: 'manager', isAdmin: false, agency: false, branch: null, multi: false, ready: false, branches: [],
  can: { schedule: true, services: true, createService: true, profile: false, wallet: false, topup: false, center: true }
};

function ctx() {
  if (S.summary && S.summary.ctx) { CTX0 = S.summary.ctx; }
  return CTX0;
}

/** وضعیت شعبه به رنگ نشان‌ها. */
function branchBadge(status, label) {
  var cls = status === 'active' ? 'confirmed' : (status === 'draft' ? 'pending' : 'cancelled');
  return badge(cls, label || status);
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

  html += agencyBanner();

  html += '<div class="pn-stats pn-stats--count">' +
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
    '<div class="pn-head__act pn-quickacts">' +
      '<button class="pn-btn pn-btn--pri" data-view="board">' + I.grid + ' تخته‌ی روزها</button>' +
      '<button class="pn-btn pn-btn--soft" data-view="bookings">' + I.list + ' فهرست نوبت‌ها</button>' +
      '<button class="pn-btn pn-btn--soft" data-view="closures">' + I.lock + ' بستن یک روز</button>' +
      '<a class="pn-btn pn-btn--soft" href="' + esc(C.app || '/') + '" target="_blank">' + I.car + ' دیدن اپ مشتری</a>' +
      installBtn('pn-btn pn-btn--soft', 'نصب پنل روی گوشی') +
      (C.wpAdmin && !ctx().agency ? '<a class="pn-btn pn-btn--soft" href="' + esc(C.wpAdmin) + '" target="_blank">' + I.gear + ' تنظیمات (پیشخوان وردپرس)</a>' : '') +
    '</div></div>';

  return html;
}

/**
 * بالای خلاصه‌ی نمایندگی: وضعیت شعبه و کیف پول (US-4.1). مدیر نمایندگی
 * سه عدد را می‌بیند؛ اپراتور فقط اینکه نوبت گرفتن متوقف شده یا نه.
 */
function agencyBanner() {
  var c = ctx();

  if (!c.agency) { return ''; }

  var html = '';
  var w = c.wallet;

  if (c.branch && c.branch.status !== 'active') {
    html += note('warn', I.lock, c.branch.status === 'draft'
      ? 'این نمایندگی هنوز فعال نشده است و در فهرست مشتری‌ها دیده نمی‌شود. بعد از فعال‌سازی توسط مدیریت مرکز، پیامک اطلاع‌رسانی می‌آید.'
      : 'این نمایندگی غیرفعال است و نوبت تازه نمی‌گیرد. نوبت‌های قبلی سر جایشان‌اند.');
  }

  if (!w) { return html; }

  if (w.blocked) {
    html += note('bad', I.alert, '<b>نوبت‌گیری متوقف است:</b> موجودی آزاد کیف پول (' + esc(fa(w.freeFa)) + ') کمتر از هزینه‌ی یک نوبت (' + esc(fa(c.feeFa)) + ') است و مشتری‌ها فعلاً نمی‌توانند در این نمایندگی نوبت بگیرند.' +
      (c.can.topup ? ' <a href="#" data-view="bwallet">شارژ کیف پول</a>' : ' به مدیر نمایندگی خبر دهید.'));
  } else if (w.low) {
    html += note('warn', I.wallet, 'موجودی کیف پول کم است: فقط برای <b>' + fa(w.capacity) + '</b> نوبت دیگر.' +
      (c.can.topup ? ' <a href="#" data-view="bwallet">شارژ کیف پول</a>' : ''));
  }

  if (c.can.wallet) {
    html += '<div class="pn-stats pn-stats--wallet">' +
      stat('موجودی آزاد', w.free, I.wallet, w.blocked ? 'bad' : (w.low ? 'hot' : 'ok'), w.freeFa) +
      stat('بلوکه برای نوبت‌های پیش‌رو', w.held, I.lock, '', w.heldFa) +
      stat('ظرفیت با موجودی فعلی', w.capacity, I.list, '', fa(w.capacity) + ' نوبت') +
      '</div>';
  }

  return html;
}

function stat(label, value, icon, kind, text) {
  if (typeof text === 'string') {
    return '<div class="pn-stat' + (kind ? ' pn-stat--' + kind : '') + '">' +
      '<div class="pn-stat__k">' + icon + esc(label) + '</div>' +
      '<div class="pn-stat__v pn-stat__v--txt">' + esc(fa(text)) + '</div></div>';
  }

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
    '<div class="pn-filters pn-filters--bk">' +
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
      '<label class="pn-field pn-f-status"><span class="pn-field__l">وضعیت</span>' +
        '<select class="pn-in" id="pn-status">' +
          '<option value="">همه</option>' +
          Object.keys(STATUSES).map(function (k) {
            return '<option value="' + k + '"' + (f.status === k ? ' selected' : '') + '>' + esc(STATUSES[k]) + '</option>';
          }).join('') +
        '</select></label>' +
      '<label class="pn-field pn-f-date"><span class="pn-field__l">تاریخ</span>' +
        J.fieldHTML('pn-date', f.date, { placeholder: 'همه‌ی تاریخ‌ها' }) + '</label>' +
      '<label class="pn-field pn-f-q" style="flex:1;min-width:180px"><span class="pn-field__l">جستجو</span>' +
        '<input class="pn-in" id="pn-q" type="search" placeholder="نام، موبایل، کد پیگیری یا خودرو" value="' + esc(f.q) + '"></label>' +
      '<button class="pn-btn pn-btn--dark pn-f-apply" data-apply>اعمال</button>' +
      '<button class="pn-btn pn-btn--soft pn-f-clear' + (f.status || f.date || f.q ? '' : ' is-idle') + '" data-clear>پاک کردن</button>' +
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
        (b.province || b.city ? ' · ' + esc([b.province, b.city].filter(Boolean).join(' / ')) : '') + '</div></td>' +
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

  /* گوشی: کارت فشرده‌ی هر نوبت (به‌جای جدولی که هر ستونش یک ردیف می‌شد و هر
     نوبت ~۴۷۰ پیکسل بود). کل کارت جزئیات را باز می‌کند؛ شماره‌ی مشتری تماس می‌گیرد. */
  html += '<div class="pn-bkl' + (S.fetching ? ' is-fetching' : '') + '">' + S.list.items.map(function (b) {
    var car = [b.carBrand, b.carModel ? 'موتور ' + b.carModel : ''].filter(Boolean).join(' · ');
    return '<div class="pn-bkc pn-bkc--' + esc(b.status) + '" data-open="' + b.id + '" role="button" tabindex="0" aria-label="' + esc('جزئیات نوبت ' + b.name + ' ' + b.dateFa) + '">' +
      '<div class="pn-bkc__top"><span class="num"><b>' + fa(esc(b.dateFa)) + '</b> · ' + esc(String(b.blockLabel || '').replace(/^شیفت\s+/, '')) + ' ' + fa(esc(b.blockStart)) + '</span>' + badge(b.status, b.statusLabel) + '</div>' +
      '<div class="pn-bkc__who"><b>' + esc(b.name) + '</b><a class="num" href="tel:' + esc(b.phone) + '">' + fa(esc(b.phone)) + '</a></div>' +
      '<div class="pn-bkc__meta">' + esc([car, b.service].filter(Boolean).join(' · ')) + '</div>' +
      '<div class="pn-bkc__foot"><span class="pn-code">' + esc(b.code) + '</span>' +
        (b.city || b.province ? '<span>' + esc([b.city, b.province].filter(Boolean)[0]) + '</span>' : '') +
        '<span class="pn-bkc__go">جزئیات' + I.chevL + '</span></div>' +
    '</div>';
  }).join('') + '</div>';

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
  var agency = ctx().agency;
  var html = head('تنظیمات شیفت', agency ? 'ساعت و ظرفیت شیفت‌های این نمایندگی' : 'ساعت و ظرفیت هر شیفت، و بازه‌ی رزرو آنلاین');
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

  /* شعبه فقط شیفت‌های خودش را دارد؛ بازه‌ی رزرو و قواعد لغو سراسری است
     و فقط مدیر کل تغییرش می‌دهد. */
  if (agency || !S.schedGlobal) {
    return html + note('info', I.info, 'بازه‌ی رزرو آنلاین، سقف نوبت هر مشتری و مهلت لغو برای همه‌ی شعبه‌ها یکی است و فقط مدیر کل تغییرش می‌دهد.') +
      (!agency && ctx().can.center ? healthCard() : '') +
      '<div class="pn-actions">' +
        '<button class="pn-btn pn-btn--pri" data-sched-save' + (S.busy ? ' disabled' : '') + '>' +
          (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : 'ذخیره‌ی تنظیمات') + '</button>' +
      '</div>';
  }

  html += '<div class="pn-card">' +
    '<h3 class="pn-card__t">بازه‌ی رزرو</h3>' +
    '<div class="pn-grid">' +
      num('st-min', 'حداقل فاصله تا مراجعه', st.min_days_ahead, 0, 30, 'روز', '۱ یعنی رزرو برای همان روز مجاز نیست.') +
      num('st-min-h', 'حداقل زمان تا شروع شیفت', st.min_hours_ahead, 0, 336, 'ساعت', 'برای همه‌ی خدمات. با ۲۴، ساعت ۶ عصر امروز شیفت صبح فردا قابل رزرو نیست ولی شیفت عصر فردا هست.') +
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

  if (ctx().can.center) { html += healthCard(); }

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
  var epoch = dataEpoch;
  get('panel/health').then(function (r) {
    if (epoch !== dataEpoch) { return; }
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
    afternoon_capacity: en(val('#st-a-cap'))
  };

  // بقیه‌ی فیلدها فقط در فرم مرکز هستند
  if ($('#st-min')) {
    body.min_days_ahead = en(val('#st-min'));
    body.min_hours_ahead = en(val('#st-min-h'));
    body.window_days = en(val('#st-win'));
    body.max_active_per_user = en(val('#st-max'));
    body.one_per_service = checked('#st-one') ? 1 : 0;
    body.cancel_enabled = checked('#st-cancel') ? 1 : 0;
    body.cancel_deadline_hours = en(val('#st-cancel-h'));
  }

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
/** شعبه‌های یک مشتری برای خروجی اکسل: «مرکز اصلی (۲)، نمایندگی اهواز (۱)». */
function custBranches(m) {
  return (m.branches || []).map(function (b) { return b.title + ' (' + b.n + ')'; }).join('، ');
}

function viewCustomers() {
  var c = S.customers;

  var html = head('مشتری‌ها', c ? fa(c.total) + ' مشتری' : '',
    (ctx().can.export === false ? '' : '<button class="pn-btn pn-btn--dark" data-cust-export' + (S.exporting ? ' disabled' : '') + '>' +
      (S.exporting ? '<span class="pn-spin"></span> در حال آماده‌سازی…' : I.down + ' خروجی اکسل') + '</button>') +
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  /* مدیریت اصلی: مشتری‌ها جدا بر اساس شعبه (همه، مرکز اصلی، هر نمایندگی) */
  if (c && c.byBranch && c.branches && c.branches.length > 2) {
    html += '<div class="pn-custbr" role="group" aria-label="مشتری‌های کدام شعبه">' + c.branches.map(function (b) {
      var on = String(b.id) === String(c.branch);
      return '<button type="button" class="pn-chip' + (on ? ' is-on' : '') + '" data-cust-branch="' + esc(b.id) + '" aria-pressed="' + on + '">' +
        esc(b.title) + ' <small class="num">' + fa(b.n) + '</small></button>';
    }).join('') + '</div>';
  }

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
    '<th>مشتری</th><th>خودرو</th><th>خدمات</th><th>نوبت‌ها</th><th>آخرین نوبت</th>' + (c.wallet ? '<th>کیف پول</th>' : '') + '<th>عملیات</th>' +
    '</tr></thead><tbody>';

  c.items.forEach(function (m) {
    html += '<tr>' +
      '<td data-label="مشتری"><div class="pn-table__b">' + esc(m.name || '—') + '</div>' +
        '<div class="pn-table__s num"><a href="tel:' + esc(m.phone) + '">' + fa(esc(m.phoneFa)) + '</a>' +
        (m.city ? ' · ' + esc(m.city) : '') + '</div>' +
        (c.byBranch && m.branches && m.branches.length ? '<div class="pn-custbr__in">' + m.branches.map(function (b) {
          return '<span class="pn-custbr__tag">' + esc(b.title) + (m.branches.length > 1 ? ' <small class="num">' + fa(b.n) + '</small>' : '') + '</span>';
        }).join('') + '</div>' : '') + '</td>' +
      '<td data-label="خودرو">' + esc(m.car || '—') + '</td>' +
      '<td data-label="خدمات">' + esc(m.services || '—') + '</td>' +
      '<td data-label="نوبت‌ها" class="num">' + fa(m.total) +
        (m.cancelled ? ' <span class="pn-table__s">(' + fa(m.cancelled) + ' لغو)</span>' : '') +
        (m.noshow ? ' <span class="pn-table__s">(' + fa(m.noshow) + ' غیبت)</span>' : '') + '</td>' +
      '<td data-label="آخرین نوبت" class="num">' + fa(esc(m.lastDate)) +
        '<div class="pn-table__s">از ' + fa(esc(m.firstSeen)) + '</div></td>' +
      (c.wallet ? '<td data-label="کیف پول" class="num">' + (m.wallet ? '<b class="pn-wal__in">' + esc(m.walletFa) + '</b>' : '—') + '</td>' : '') +
      '<td data-label="عملیات">' +
        // فهرست نوبت‌ها شعبه‌ی جاری پنل را نشان می‌دهد؛ برای مشتری شعبه‌ی دیگر خالی می‌شد
        (m.inPanel !== false ? '<button class="pn-btn pn-btn--sm pn-btn--soft" data-cust-bookings="' + esc(m.phone) + '">نوبت‌هایش</button>'
          : '<span class="pn-table__s">نوبت‌ها در پنل همان شعبه</span>') +
        (c.wallet ? ' <button class="pn-btn pn-btn--sm pn-btn--soft" data-wal-open="' + esc(m.phone) + '">' + I.wallet + ' کیف پول</button>' : '') +
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

  get('panel/customers?all=1&q=' + encodeURIComponent(S.custQ) + '&branch=' + encodeURIComponent(S.custBranch)).then(function (r) {
    S.exporting = false;

    if (!r || r.success === false || !Array.isArray(r.items)) {
      toast((r && r.message) || 'تهیه‌ی خروجی ناموفق بود.', 'bad');
      paint();
      return;
    }

    var head = ['نام', 'موبایل', 'شهر', 'خودرو', 'خدمات', 'کل نوبت‌ها', 'انجام‌شده', 'لغوشده', 'غیبت', 'اولین مراجعه', 'آخرین نوبت', 'حساب کاربری'];
    if (r.byBranch) { head.push('شعبه'); }

    var lines = [head.map(csvCell).join(',')];

    r.items.forEach(function (m) {
      lines.push([
        m.name, m.phone, m.city, m.car, m.services,
        m.total, m.done, m.cancelled, m.noshow,
        m.firstSeen, m.lastDate,
        m.hasUser ? 'دارد' : 'ندارد'
      ].concat(r.byBranch ? [custBranches(m)] : []).map(csvCell).join(','));
    });

    var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');

    a.href = url;
    a.download = 'customers-' + (r.byBranch && r.branch && r.branch !== 'all' ? 'branch' + r.branch + '-' : '') + new Date().toISOString().slice(0, 10) + '.csv';
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
  var m = S.svcMeta || { catalog: false, canEdit: true, canAdd: true };

  /* نمایندگی: کاتالوگ مرکز (US-3.4) به‌علاوه‌ی خدمات خود نمایندگی. در
     خدمت کاتالوگ عنوان، شرح و پوستر را مرکز تعیین می‌کند و نمایندگی قیمت،
     روزها و روشن/خاموش بودن را؛ خدمتی که خود نمایندگی ساخته همه‌اش مال
     خودش است (جز بیعانه، که مشتری نمایندگی نمی‌دهد). */
  var html = head('خدمات', m.catalog
      ? (m.center ? 'عنوان، شرح و پوستر خدمات را مدیر کل تعیین می‌کند؛ قیمت، روزها و فعال بودن را اینجا تغییر دهید'
        : (m.canCustomize ? 'الگوی خدمات را برای همین نمایندگی شخصی‌سازی کنید؛ تغییرات شما روی شعبه‌های دیگر اثر ندارد' : m.canAdd ? 'خدمات کاتالوگ مرکز را با قیمت خودتان فعال کنید، یا خدمت مخصوص نمایندگی خودتان را اضافه کنید'
          : 'خدمات از کاتالوگ مرکز می‌آید؛ قیمت، روزها و فعال بودن هر خدمت مال شماست'))
      : 'قیمت، شرح و روزهای ارائه‌ی هر خدمت را اینجا تغییر دهید',
    m.canAdd ? '<button class="pn-btn pn-btn--dark" data-svc-new>' + I.plus + ' افزودن خدمت</button>' : '');

  if (!S.services) { return html + skel(3); }

  if (m.catalog && !m.center && !S.services.some(function (x) { return Number(x.active) === 1; })) {
    html += note('warn', I.info, 'هنوز هیچ خدمتی فعال نیست؛ تا یکی فعال نشود، مشتری‌ها نمی‌توانند در این نمایندگی نوبت بگیرند. روی «ویرایش» یک خدمت بزنید، قیمت خودتان را بگذارید و فعالش کنید' +
      (m.canAdd ? '، یا با «افزودن خدمت» خدمت خودتان را بسازید.' : '.'));
  }

  if (!m.canEdit) {
    html += note('info', I.lock, 'تغییر خدمات فقط با مدیر شعبه است.');
  }

  if (!S.services.length) {
    return html + empty('خدمتی تعریف نشده است', m.canAdd ? 'با دکمه‌ی «افزودن خدمت» اولین خدمت را بسازید.' : '');
  }

  S.services.forEach(function (s, i) {
    html += '<div class="pn-card">' +
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
        '<div style="flex:1;min-width:200px">' +
          '<div style="display:flex;align-items:center;gap:10px">' +
            '<h3 style="font-size:17px;font-weight:800">' + esc(s.title) + '</h3>' +
            (s.active ? '<span class="pn-badge pn-badge--confirmed">فعال</span>'
                      : '<span class="pn-badge pn-badge--cancelled">غیرفعال</span>') +
            (m.catalog && !m.center ? (s.fromCatalog || s.catalog ? '<span class="pn-badge pn-badge--quick">' + (s.customized ? 'الگوی شخصی‌سازی‌شده' : 'الگوی مرکز') + '</span>'
                                    : '<span class="pn-badge pn-badge--pending">خدمت نمایندگی</span>') : '') +
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
        (!m.canEdit ? '' : '<div class="pn-svcacts">' +
          /* کارهای پرتکرار بدون باز کردن پنجره: روشن/خاموش کردن،
             جابه‌جایی در ترتیب، و ساختن خدمت تازه از روی همین یکی. */
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc="' + s.id + '">' + I.edit + ' ویرایش</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-toggle="' + s.id + '">' +
            (s.active ? 'غیرفعال کن' : 'فعال کن') + '</button>' +
          (s.catalog ? '</div>' : m.catalog
            ? (m.canAdd ? '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-copy="' + s.id + '">کپی</button>' : '') + '</div>' :
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-copy="' + s.id + '">کپی</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-move="' + s.id + ':up"' +
            (i === 0 ? ' disabled' : '') + ' aria-label="بالا">' + I.chevU + '</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-svc-move="' + s.id + ':down"' +
            (i === S.services.length - 1 ? ' disabled' : '') + ' aria-label="پایین">' + I.chevDn + '</button>' +
        '</div>')) +
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
  }).join('، ');
}

var WD = { 6: 'شنبه', 0: 'یکشنبه', 1: 'دوشنبه', 2: 'سه‌شنبه', 3: 'چهارشنبه', 4: 'پنجشنبه', 5: 'جمعه' };

function weekdayText(list) {
  var order = [6, 0, 1, 2, 3, 4, 5];
  var out = order.filter(function (d) { return (list || []).indexOf(d) !== -1; }).map(function (d) { return WD[d]; });
  return out.length ? out.join('، ') : 'هیچ روزی';
}

/** ویرایش خدمت کاتالوگی نمایندگی: فقط قیمت، روزها، فعال/غیرفعال. */
function catalogModal() {
  var s = S.svcEdit;

  var agency = ctx().agency;

  return '<div class="pn-modal__hd"><div><div class="pn-modal__t">' + esc(s.title) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px">' + (agency ? 'از کاتالوگ مرکز' : 'تعریف خدمت با مدیر کل') + '</div></div>' +
    '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    (s.poster ? '<img class="pn-poster__img" style="margin-bottom:14px" src="' + esc(s.poster) + '" alt="">' : '') +
    (s.description ? '<p class="pn-hint" style="margin:0 0 14px;font-size:13px">' + esc(s.description) + '</p>' : '') +

    '<label class="pn-field"><span class="pn-field__l">' + (agency ? 'قیمت در این نمایندگی (تومان)' : 'قیمت (تومان)') + '</span>' +
    '<input class="pn-in num" id="sv-price" type="number" min="0" step="10000" value="' + Number(s.price) + '"></label>' +

    '<div class="pn-field"><span class="pn-field__l">روزهای قابل رزرو</span>' +
      '<div class="pn-chips" id="sv-days">' +
        [6, 0, 1, 2, 3, 4, 5].map(function (d) {
          var on = (s.weekdays || []).indexOf(d) !== -1;
          return '<button type="button" class="pn-chip' + (on ? ' is-on' : '') + '" data-day="' + d + '">' + WD[d] + '</button>';
        }).join('') +
      '</div></div>' +

    '<div class="pn-field"><span class="pn-field__l">وضعیت</span>' +
      '<div class="pn-seg">' +
        '<button type="button" class="pn-seg__b' + (Number(s.active) === 1 ? ' is-on' : '') + '" data-svc-active="1">ارائه می‌کنیم</button>' +
        '<button type="button" class="pn-seg__b' + (Number(s.active) !== 1 ? ' is-on' : '') + '" data-svc-active="0">ارائه نمی‌کنیم</button>' +
      '</div></div>' +

    (s.customized ? '<button class="pn-btn pn-btn--soft" data-svc-reset="' + s.id + '">بازگشت به الگوی مرکز</button>' : '') +
    '<div class="pn-hint">' + (agency ? 'عنوان، شرح و پوستر را مدیر کل برای همه‌ی نمایندگی‌ها یکسان تعیین می‌کند.'
      : 'عنوان، شرح، پوستر، سهمیه و بیعانه‌ی خدمت را فقط مدیر کل تعیین می‌کند (کاتالوگ مرکزی).') + '</div>' +

    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-svc-save' + (S.busy ? ' disabled' : '') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : 'ذخیره تغییرات') + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>انصراف</button>' +
    '</div>';
}

function svcModal() {
  var s = S.svcEdit;

  if (s.catalog) { return catalogModal(); }

  var isNew = !s.id;
  var agency = ctx().agency;

  return '<div class="pn-modal__hd"><div class="pn-modal__t">' + (isNew ? 'خدمت تازه' : 'ویرایش خدمت') + '</div>' +
    '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    (s.fromCatalog ? note('info', I.info, 'این یک نسخه از الگوی مرکز است. تغییر عنوان، شرح، پوستر و ظرفیت فقط روی همین نمایندگی اثر دارد.') : '') +
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

    (agency ? '' : depositFields(s)) +

    (agency
      ? '<div class="pn-field"><span class="pn-field__l">وضعیت</span>' +
          '<div class="pn-seg">' +
            '<button type="button" class="pn-seg__b' + (Number(s.active) === 1 ? ' is-on' : '') + '" data-svc-active="1">ارائه می‌کنیم</button>' +
            '<button type="button" class="pn-seg__b' + (Number(s.active) !== 1 ? ' is-on' : '') + '" data-svc-active="0">ارائه نمی‌کنیم</button>' +
          '</div>' +
          '<div class="pn-hint">این خدمت فقط در همین نمایندگی به مشتری نشان داده می‌شود. مشتری نمایندگی بیعانه نمی‌دهد.</div>' +
        '</div>'
      : '<div class="pn-field"><span class="pn-field__l">وضعیت</span>' +
      '<div class="pn-seg pn-seg--3">' +
        '<button type="button" class="pn-seg__b' + (Number(s.active) === 1 ? ' is-on' : '') + '" data-svc-active="1">برای همه</button>' +
        '<button type="button" class="pn-seg__b' + (Number(s.active) === 2 ? ' is-on' : '') + '" data-svc-active="2">آزمایشی</button>' +
        '<button type="button" class="pn-seg__b' + (Number(s.active) === 0 ? ' is-on' : '') + '" data-svc-active="0">غیرفعال</button>' +
      '</div>' +
      '<div class="pn-hint">' + (Number(s.active) === 2
        ? 'فقط مدیران و مسئولان رزرو این خدمت را در اپ می‌بینند (با نشان «آزمایشی») — برای امتحان مسیر واقعی رزرو، پرداخت و برگشت به کیف پول. بهتر است سهمیه‌ی جداگانه و بیعانه‌ی ۱,۰۰۰ تومان داشته باشد.'
        : (Number(s.active) === 1 ? 'در اپ برای همه‌ی مشتری‌ها قابل رزرو است.' : 'در اپ دیده نمی‌شود.')) + '</div>' +
    '</div>') +

    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-svc-save' + (S.busy ? ' disabled' : '') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : (isNew ? 'ثبت خدمت' : 'ذخیره تغییرات')) + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>انصراف</button>' +
      (s.fromCatalog ? '<button class="pn-btn pn-btn--soft" data-svc-reset="' + s.id + '">بازگشت به الگوی مرکز</button>'
        : (isNew ? '' : '<button class="pn-btn pn-btn--bad" data-svc-del="' + s.id + '">حذف</button>')) +
    '</div>';
}

/* ═══ بیعانه و کیف پول ═══ */

/**
 * کیف پول مشتری‌ها، بررسی مبلغ‌ها و گزارش؛ و «برگشت‌های مانده» (صف کارتِ
 * پیش از کیف پول، یا برگشتی که واریزش به کیف پول ممکن نشد) فقط وقتی
 * چیزی در آن مانده باشد.
 */
function viewRefunds() {
  var R = S.refunds;
  var html = head('بیعانه و کیف پول', 'کیف پول مشتری‌ها، بررسی مبلغ‌ها و گزارش',
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  var tab = function (k, t) {
    return '<button class="pn-tabs__b' + (S.refWhich === k ? ' is-on' : '') + '" data-ref-which="' + k + '">' + t + '</button>';
  };

  var left = (S.summary && S.summary.counts && S.summary.counts.refunds) || (S.wallets && S.wallets.legacy) || (R && R.open) || 0;

  html += '<div class="pn-tabs pn-reftabs">' +
    tab('wallet', 'کیف پول') +
    (left || S.refWhich === 'open' ? tab('open', 'برگشت‌های مانده' + (left ? ' (' + fa(left) + ')' : '')) : '') +
    tab('review', 'بررسی خدمات') +
    tab('report', 'گزارش') +
    '</div>';

  // فقط مدیر سایت: راه‌اندازی زرین‌پال تا کامل نشده، یادآوری می‌شود
  if (PAY.setup && PAY.setup.ready < PAY.setup.total && S.refWhich !== 'report') {
    html += note('warn', I.alert, '<b>راه‌اندازی زرین‌پال: ' + fa(PAY.setup.ready) + ' از ' + fa(PAY.setup.total) + ' آماده.</b> ' +
      esc(PAY.setup.text) + ' <a href="' + esc(PAY.setup.url) + '" target="_blank" rel="noopener">باز کردن صفحه‌ی راه‌اندازی</a>');
  }

  if (S.refWhich === 'review') { return html + viewPayReview(); }
  if (S.refWhich === 'report') { return html + viewPayReport(); }
  if (S.refWhich === 'wallet') { return html + viewWallets(); }

  html += note('info', I.info, '<b>برگشت‌هایی که به کیف پول نرفته‌اند:</b> مانده از پیش از کیف پول (مثلاً در زرین‌پال در حال انجام بودند)، یا صاحب پرداخت معلوم نبود. ' +
    '«انتقال به کیف پول» همان مبلغ را به کیف پول مشتری می‌برد؛ اگر پول قبلاً جور دیگری به مشتری برگشته، «ثبت انجام‌شده» بزنید.');

  if (!R) { return html + skel(3); }

  if (!R.items.length) { return html + empty('برگشت مانده‌ای نیست', ''); }

  html += '<div class="pn-refunds">' + R.items.map(function (x) { return refundCard(x, R.canRefund); }).join('') + '</div>';

  if (!R.canRefund) {
    html += note('info', I.info, 'تعیین تکلیف این ردیف‌ها فقط برای مدیر سایت مجاز است. مدیر می‌تواند این دسترسی را در تنظیمات پرداخت به مسئولان رزرو هم بدهد.');
  }

  return html;
}

/* ═══ کیف پول مشتری‌ها ═══ */

function viewWallets() {
  var W = S.wallets;
  var html = '';

  if (W && W.totals) {
    html += '<div class="pn-stats pn-stats--rep">' +
      stat('جمع موجودی کیف پول‌ها', W.totals.liabilityFa, I.wallet, '') +
      stat('کیف پول دارای موجودی', fa(W.totals.wallets), I.users, '') +
      (W.legacy ? stat('برگشت‌های مانده', fa(W.legacy), I.alert, 'hot') : '') +
      '</div>';

    html += note('info', I.info, 'هر برگشت (لغو به‌موقع مشتری، لغو از طرف مجموعه، پرداخت تکراری یا دیر) همان لحظه به کیف پول مشتری می‌رود و او با آن بیعانه‌ی نوبت بعدی را می‌پردازد. ' +
      'جمع موجودی‌ها بدهی مجموعه به مشتری‌هاست؛ قابل برداشت به کارت نیست.');

    if (W.migrated && W.migrated.count) {
      html += note('ok', I.check, 'صف قدیمی: ' + fa(W.migrated.count) + ' برگشت به جمع ' + money(W.migrated.amount) + ' تومان خودکار به کیف پول مشتری‌ها منتقل شد' +
        (W.migrated.sms_pending && W.migrated.sms_pending.length ? '؛ پیامکشان با تنظیم پترن «تغییر کیف پول» فرستاده می‌شود.' : '.'));
    }
  }

  var chip = function (k, t) {
    return '<button type="button" class="pn-chip' + (S.walFilter === k ? ' is-on' : '') + '" data-wal-filter="' + k + '">' + t + '</button>';
  };

  html += '<div class="pn-card">' +
    '<div class="pn-filters">' +
      '<label class="pn-field" style="flex:1;min-width:200px"><span class="pn-field__l">جستجو</span>' +
        '<input class="pn-in" id="wl-q" type="search" placeholder="نام یا موبایل" value="' + esc(S.walQ) + '"></label>' +
      '<button class="pn-btn pn-btn--dark" data-wal-search>جستجو</button>' +
      '<div class="pn-chips" style="align-self:flex-end">' + chip('balance', 'دارای موجودی') + chip('all', 'همه') + '</div>' +
    '</div>';

  if (!W) { return html + skel(3) + '</div>'; }

  if (!W.items.length) {
    return html + empty(
      S.walQ ? 'کیف پولی با این جستجو نیست' : (S.walFilter === 'balance' ? 'هیچ مشتری‌ای موجودی ندارد' : 'هنوز تراکنشی نیست'),
      'مبلغ برگشتیِ لغو نوبت‌ها خودکار به کیف پول مشتری می‌رود.'
    ) + '</div>';
  }

  html += '<div class="pn-tablewrap' + (S.fetching ? ' is-fetching' : '') + '"><table class="pn-table"><thead><tr>' +
    '<th>مشتری</th><th>موجودی</th><th>تراکنش‌ها</th><th>آخرین تغییر</th><th>عملیات</th>' +
    '</tr></thead><tbody>';

  W.items.forEach(function (m) {
    html += '<tr>' +
      '<td data-label="مشتری"><div class="pn-table__b">' + esc(m.name || '—') + '</div>' +
        '<div class="pn-table__s num"><a href="tel:' + esc(m.phone) + '">' + fa(esc(m.phoneFa)) + '</a></div></td>' +
      '<td data-label="موجودی" class="num"><b class="' + (m.balance > 0 ? 'pn-wal__in' : (m.balance < 0 ? 'pn-wal__neg' : '')) + '">' + esc(m.balanceFa) + '</b></td>' +
      '<td data-label="تراکنش‌ها" class="num">' + fa(m.count) + '</td>' +
      '<td data-label="آخرین تغییر" class="num">' + fa(esc(m.lastFa)) + '</td>' +
      '<td data-label="عملیات"><button class="pn-btn pn-btn--sm pn-btn--soft" data-wal-open="' + esc(m.phone) + '">' + I.wallet + ' تاریخچه' + (W.canAdjust ? ' و تغییر' : '') + '</button></td>' +
      '</tr>';
  });

  html += '</tbody></table></div>';

  if (W.pages > 1) {
    html += '<div class="pn-head__act" style="margin-top:16px;justify-content:center">' +
      '<button class="pn-btn pn-btn--soft" data-wal-page="' + (W.page - 1) + '"' + (W.page <= 1 ? ' disabled' : '') + '>' + I.chevR + ' قبلی</button>' +
      '<span style="align-self:center;font-size:13px;color:var(--ink-3)" class="num">صفحه ' + fa(W.page) + ' از ' + fa(W.pages) + '</span>' +
      '<button class="pn-btn pn-btn--soft" data-wal-page="' + (W.page + 1) + '"' + (W.page >= W.pages ? ' disabled' : '') + '>بعدی ' + I.chevL + '</button>' +
      '</div>';
  }

  return html + '</div>';
}

/** پنجره‌ی کیف پول یک مشتری: موجودی، تاریخچه، افزایش یا کاهش دستی. */
function walletModal() {
  var M = S.modal;
  var D = M.data;

  var html = '<div class="pn-modal__hd"><div><div class="pn-modal__t">' + I.wallet + ' کیف پول ' + esc((D && D.name) || '') + '</div>' +
    '<div style="font-size:12px;color:var(--ink-3);margin-top:3px" class="num">' + fa(esc(M.phone)) + '</div></div>' +
    '<button class="pn-modal__x" data-close>' + I.x + '</button></div>';

  if (!D) { return html + skel(3); }

  html += '<div class="pn-wal__bal"><span>موجودی</span><b class="num">' + esc(D.balanceFa) + '</b></div>' +
    (D.held > 0 ? note('warn', I.clock, esc(D.heldFa) + ' برای نوبتِ در انتظار پرداخت کنار گذاشته شده (در موجودی بالا کم شده).') : '');

  if (D.canAdjust) {
    var dir = M.dir || 'inc';

    html += '<div class="pn-card pn-wal__form">' +
      '<div class="pn-card__t">تغییر دستی</div>' +
      '<div class="pn-seg" style="margin-bottom:10px">' +
        '<button type="button" class="pn-seg__b' + (dir === 'inc' ? ' is-on' : '') + '" data-wal-dir="inc">افزایش</button>' +
        '<button type="button" class="pn-seg__b' + (dir === 'dec' ? ' is-on' : '') + '" data-wal-dir="dec">کاهش</button>' +
      '</div>' +
      '<div class="pn-grid">' +
        '<label class="pn-field"><span class="pn-field__l">مبلغ (تومان)</span>' +
          '<input class="pn-in num" id="wl-amt" inputmode="numeric" dir="ltr" value="' + esc(M.amount || '') + '"></label>' +
        '<label class="pn-field"><span class="pn-field__l">توضیح (الزامی)</span>' +
          '<input class="pn-in" id="wl-note" maxlength="190" placeholder="مثلاً جبران تأخیر نوبت" value="' + esc(M.note || '') + '"></label>' +
      '</div>' +
      '<label class="pn-check"><input type="checkbox" id="wl-sms" checked> پیامک به مشتری</label>' +
      '<div class="pn-modal__ft">' +
        '<button class="pn-btn pn-btn--pri" data-wal-adjust' + (M.busy ? ' disabled' : '') + '>' + (M.busy ? '<span class="pn-spin"></span> …' : (dir === 'dec' ? 'کم کردن از کیف پول' : 'افزودن به کیف پول')) + '</button>' +
      '</div>' +
      '<div class="pn-hint">توضیح در تاریخچه‌ی خود مشتری هم دیده می‌شود. کاهش بیش از موجودی ممکن نیست. هر تغییر با نام شما ثبت می‌شود.</div>' +
      '</div>';
  } else {
    html += note('info', I.info, 'تغییر کیف پول مشتری فقط برای مدیر سایت مجاز است؛ مدیر می‌تواند در تنظیمات افزونه این اجازه را به مسئولان رزرو هم بدهد.');
  }

  var hist = D.history || [];

  html += '<div class="pn-card__t" style="margin-top:16px">تاریخچه</div>';

  if (!hist.length) { return html + empty('تراکنشی نیست', ''); }

  html += '<div class="pn-wal__list">' + hist.map(function (x) {
    return '<div class="pn-wal__row' + (x.status === 'void' ? ' is-void' : '') + '">' +
      '<div class="pn-wal__main"><div class="pn-wal__t">' + esc(x.title) + '</div>' +
        '<div class="pn-wal__s num">' + fa(esc(x.dateFa)) +
          (x.statusLabel ? ' · ' + esc(x.statusLabel) : '') +
          (x.by ? ' · ' + esc(x.by) : '') +
          (x.note ? ' · ' + esc(x.note) : '') + '</div></div>' +
      '<div class="pn-wal__a ' + (x.in ? 'pn-wal__in' : 'pn-wal__out') + '"><b dir="ltr" class="num">' + (x.in ? '+' : '−') + money(Math.abs(Number(x.amount))) + '</b></div>' +
      '</div>';
  }).join('') + '</div>';

  return html;
}

function openWallet(phone) {
  S.modal = { wallet: true, phone: phone, data: null, dir: 'inc' };
  paintModal();

  get('panel/wallet?phone=' + encodeURIComponent(phone)).then(function (r) {
    if (!S.modal || !S.modal.wallet || S.modal.phone !== phone) { return; }

    if (r.success === false) {
      toast(r.message || 'کیف پول باز نشد.', 'bad');
      S.modal = null;
      paintModal();
      return;
    }

    S.modal.data = r;
    paintModal();
  });
}

function walletAdjust() {
  var M = S.modal;
  if (!M || !M.wallet || M.busy) { return; }

  M.amount = en(val('#wl-amt')).replace(/\D/g, '');
  M.note = val('#wl-note').trim();

  if (!Number(M.amount)) { toast('مبلغ را وارد کنید.', 'bad'); return; }
  if (M.note.length < 3) { toast('توضیح را بنویسید؛ در تاریخچه‌ی مشتری می‌ماند.', 'bad'); return; }

  var dec = M.dir === 'dec';

  if (!window.confirm((dec ? 'از کیف پول این مشتری ' : 'به کیف پول این مشتری ') + money(M.amount) + ' تومان ' + (dec ? 'کم شود؟' : 'اضافه شود؟'))) { return; }

  M.busy = true;
  paintModal();

  post('panel/wallet/adjust', { phone: M.phone, amount: Number(M.amount), dir: dec ? 'dec' : 'inc', note: M.note, sms: checked('#wl-sms') ? 1 : 0 }).then(function (r) {
    M.busy = false;

    if (r.success === false) {
      toast(r.message || 'انجام نشد.', 'bad');
      paintModal();
      return;
    }

    M.data = r;
    M.amount = '';
    M.note = '';
    toast('کیف پول به‌روز شد؛ موجودی: ' + r.balanceFa, 'ok');
    paintModal();

    // فهرست‌ها هم عددهای تازه را بگیرند
    if (S.view === 'refunds' && S.refWhich === 'wallet') { load('refunds'); }
    if (S.view === 'customers') { load('customers'); }
  });
}

/* ═══ بررسی مبلغ‌های هر خدمت + شبیه‌ساز ═══ */

function viewPayReview() {
  var R = S.payReview;
  if (!R) { return skel(3); }

  var st = R.settings || {};
  var chip = function (t, kind) { return '<span class="pn-chipinfo' + (kind ? ' is-' + kind : '') + '">' + t + '</span>'; };

  var html = '<div class="pn-card pn-revset">' +
    '<div class="pn-card__t">' + I.cog + ' تنظیمات مشترک</div>' +
    '<div class="pn-chipline">' +
      chip(st.enabled ? 'پرداخت بیعانه روشن' : 'پرداخت بیعانه خاموش', st.enabled ? 'ok' : 'warn') +
      chip(st.sandbox ? 'درگاه آزمایشی' : 'درگاه واقعی', st.sandbox ? 'warn' : '') +
      chip('برگشت به کیف پول مشتری', 'ok') +
      chip('مهلت لغو مشتری: ' + fa(st.hours) + ' ساعت') +
      chip('لغو از طرف مجموعه: ' + fa(st.shopPct) + '٪ بیعانه') +
    '</div>' +
    (C.wpSettings ? '<a class="pn-revset__link" href="' + esc(C.wpSettings) + '#cmb-pay" target="_blank">تغییر در تنظیمات ←</a>' : '') +
    '</div>';

  (R.global || []).forEach(function (g) { html += note(g[0] === 'bad' ? 'bad' : (g[0] === 'warn' ? 'warn' : 'info'), I.info, esc(g[1])); });

  html += '<div class="pn-revlist">' + R.items.map(function (x) {
    var mode = x.depositMode === 'default' ? 'پیش‌فرض' : (x.depositMode === 'none' ? 'بدون بیعانه' : 'مبلغ دلخواه');
    var open = S.simFor === x.id;

    var card = '<div class="pn-card pn-rev is-' + x.level + '">' +
      '<div class="pn-rev__hd"><div><div class="pn-rev__t">' + esc(x.title) + '</div>' +
        '<div class="pn-rev__s">' + esc(x.activeFa) + ' · قیمت ' + esc(x.priceFa) + '</div></div>' +
        '<span class="pn-badge pn-badge--' + (x.level === 'bad' ? 'cancelled' : (x.level === 'warn' ? 'no_show' : 'confirmed')) + '">' +
          (x.level === 'bad' ? 'نیاز به اصلاح' : (x.level === 'warn' ? 'بررسی شود' : 'درست')) + '</span></div>';

    if (!x.deposit) {
      card += '<div class="pn-hint">' + (x.depositMode === 'none' ? 'این خدمت بیعانه ندارد؛ رزرو رایگان است.' : 'بیعانه ندارد.') + '</div>';
    } else {
      card += '<div class="pn-rev__grid">' +
        kvs('بیعانه (' + mode + ')', x.depositFa) +
        kvs('برگشت در لغوِ به‌موقع' + (x.refundMode === 'custom' ? ' (دلخواه)' : ''), x.refundFa) +
        kvs('سهم مجموعه در همان لغو', x.keptFa) +
        kvs('برگشت در لغو از طرف مجموعه', x.shopFa) +
        kvs('عدم مراجعه', 'کل بیعانه نزد مجموعه') +
        kvs('باقی‌مانده هنگام مراجعه', x.remainingFa) +
        '</div>';
    }

    (x.warnings || []).forEach(function (w) { card += note(w[0] === 'bad' ? 'bad' : (w[0] === 'warn' ? 'warn' : 'info'), I.alert, esc(w[1])); });

    card += '<div class="pn-refund__acts">' +
      (x.deposit ? '<button class="pn-btn ' + (open ? 'pn-btn--dark' : 'pn-btn--soft') + '" data-sim="' + x.id + '">' + (open ? 'بستن شبیه‌ساز' : 'شبیه‌ساز سناریوها') + '</button>' : '') +
      '<button class="pn-btn pn-btn--soft" data-svc-goto="' + x.id + '">ویرایش خدمت</button>' +
      '</div>';

    if (open) { card += simulator(x); }

    return card + '</div>';
  }).join('') + '</div>';

  return html;
}

/** شبیه‌ساز: هر سناریو چه مبلغی، کِی و چطور. */
function simulator(x) {
  return '<div class="pn-sim">' + x.scenarios.map(function (c) {
    return '<div class="pn-sim__row">' +
      '<div class="pn-sim__t">' + esc(c.title) + '</div>' +
      '<div class="pn-sim__nums">' +
        '<span class="pn-sim__back' + (c.back ? '' : ' is-zero') + '">به مشتری: <b>' + esc(c.backFa) + '</b></span>' +
        '<span class="pn-sim__kept">نزد مجموعه: <b>' + esc(c.keptFa) + '</b></span>' +
      '</div>' +
      (c.back ? '<div class="pn-sim__when pn-sim__when--' + c.how + '">' + (c.how === 'auto' ? I.check : I.clock) + '<span>' + esc(c.when) + '</span></div>' : '') +
      (c.note ? '<div class="pn-sim__note">' + esc(c.note) + '</div>' : '') +
      (c.customer && c.customer !== '—' ? '<div class="pn-sim__msg">پیام به مشتری: «' + esc(c.customer) + '»</div>' : '') +
      '</div>';
  }).join('') + '</div>';
}

/* ═══ گزارش ═══ */

function viewPayReport() {
  var R = S.payReport;

  var html = '<div class="pn-repbar">' +
    '<div class="pn-chips">' + [[7, '۷ روز'], [30, '۳۰ روز'], [90, '۳ ماه'], [365, 'یک سال'], [0, 'همه']].map(function (d) {
      return '<button type="button" class="pn-chip' + (S.repDays === d[0] ? ' is-on' : '') + '" data-rep-days="' + d[0] + '">' + d[1] + '</button>';
    }).join('') + '</div>' +
    '<label class="pn-check"><input type="checkbox" id="rep-sandbox"' + (S.repSandbox ? ' checked' : '') + '> با پرداخت‌های آزمایشی</label>' +
    '</div>';

  if (!R || !R.totals) { return html + skel(2); }

  // جدول کیف پول هنوز ساخته نشده (پیش از اولین باز کردن پیشخوان بعد از به‌روزرسانی)
  if (typeof R.totals.liability === 'undefined') {
    return html + empty('گزارش هنوز آماده نیست', 'یک بار پیشخوان وردپرس را باز کنید تا دیتابیس به‌روز شود.');
  }

  return html + walletReport(R);
}

/** گزارش بیعانه و کیف پول. */
function walletReport(R) {
  var t = R.totals;
  var html = '';

  if (!t.paidCount && !t.toWalletCount && !t.topupCount) {
    html += empty('در این بازه پرداختی نبوده', S.repSandbox ? '' : 'پرداخت‌های درگاه آزمایشی حساب نشده‌اند.');
  } else {
    html += '<div class="pn-stats pn-stats--rep">' +
      stat('بیعانه‌ی نوبت‌ها (' + fa(t.paidCount) + ')', t.paidFa, I.card, '') +
      stat('از این مقدار با کیف پول', t.paidWalletFa, I.wallet, '') +
      stat('برگشت به کیف پول (' + fa(t.toWalletCount) + ')', t.toWalletFa, I.check, 'ok') +
      stat('شارژ کیف پول (' + fa(t.topupCount) + ')', t.topupFa, I.plus, '') +
      stat('نزد مجموعه', t.keptFa, I.gauge, '') +
      stat('کارمزد درگاه', t.feesFa, I.tag, '') +
      '</div>';

    var table = function (title, rows, cols) {
      if (!rows.length) { return ''; }
      return '<div class="pn-card"><div class="pn-card__t">' + title + '</div><div class="pn-tablewrap"><table class="pn-table pn-table--rep"><thead><tr>' +
        cols.map(function (c) { return '<th>' + c[0] + '</th>'; }).join('') + '</tr></thead><tbody>' +
        rows.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td data-label="' + esc(c[0]) + '">' + esc(r[c[1]]) + '</td>'; }).join('') + '</tr>'; }).join('') +
        '</tbody></table></div></div>';
    };

    html += table('به تفکیک خدمت', R.services, [['خدمت', 'title'], ['تعداد', 'countFa'], ['بیعانه', 'paidFa'], ['برگشت به کیف پول', 'refundedFa'], ['نزد مجموعه', 'keptFa']]);
    html += table('برگشت به کیف پول به تفکیک دلیل', R.reasons, [['دلیل', 'label'], ['تعداد', 'countFa'], ['مبلغ', 'sumFa']]);
  }

  html += '<div class="pn-card"><div class="pn-card__t">' + I.wallet + ' کیف پول‌ها</div>' +
    kvs('موجودی همه‌ی کیف پول‌ها (همین حالا)', t.liabilityFa + ' — ' + fa(t.wallets) + ' مشتری') +
    kvs('خرج‌شده برای بیعانه در این بازه', t.spentFa) +
    kvs('پس گرفته با بازگرداندن نوبت', t.reclaimedFa) +
    kvs('تغییر دستی (خالص، ' + fa(t.adjustCount) + ' بار)', t.adjustFa) +
    kvs('دریافتی از درگاه (بیعانه و شارژ)', t.gatewayInFa) +
    '</div>';

  if (t.tests) { html += note('info', I.info, fa(t.tests) + ' پرداخت آزمونی در این بازه بوده که جزو درآمد حساب نشده است.'); }

  return html + '<div class="pn-hint">بیعانه‌ها بر اساس زمان قطعی شدن نوبت؛ برگشت‌ها، شارژها و خرج‌ها بر اساس زمان ثبت در کیف پول. شارژ درآمد نیست: پیش‌پرداخت مشتری است و تا خرج نشده در «موجودی کیف پول‌ها» می‌ماند.</div>';
}

function refundCard(x, can) {
  var ed = S.refEdit && S.refEdit.id === x.id ? S.refEdit : null;

  var html = '<div class="pn-card pn-refund">' +
    '<div class="pn-refund__hd">' +
      '<div><div class="pn-refund__name">' + esc(x.name) + '</div>' +
      '<div class="pn-refund__sub"><a href="tel:' + esc(x.phone) + '">' + fa(esc(x.phone)) + '</a>' + (x.code ? ' · <span class="pn-code">' + esc(x.code) + '</span>' : '') +
        (x.sandbox ? ' · <span class="pn-badge pn-badge--expired">آزمایشی</span>' : '') + '</div></div>' +
      '<div class="pn-refund__amount">' + esc(x.amountFa) + '</div>' +
    '</div>' +
    '<div class="pn-refund__grid">' +
      kvs('دلیل', x.reasonLabel) +
      kvs('پرداخت‌شده', x.paidFa) +
      kvs('شماره پیگیری پرداخت', x.refId ? fa(x.refId) : '—') +
      kvs('زمان پرداخت', fa(x.paidAtFa)) +
    '</div>';

  if (x.status === 'processing') { html += note('warn', I.clock, 'این برگشت پیش از کیف پول به زرین‌پال فرستاده شده بود و جوابش معلوم نشد. در پنل زرین‌پال ← تراکنش‌ها ببینید پول برگشته یا نه.'); }
  if (x.error) { html += note('bad', I.alert, esc(x.error)); }

  if (!can) { return html + '</div>'; }

  if (ed) {
    html += '<div class="pn-refund__form">' +
      '<label class="pn-field"><span class="pn-field__l">شماره پیگیری یا توضیح (اختیاری)</span>' +
      '<input class="pn-in" id="rf-ref" value="' + esc(ed.ref || '') + '"></label>' +
      '<div class="pn-refund__acts">' +
        '<button class="pn-btn pn-btn--pri" data-ref-done-yes="' + x.id + '"' + (S.busy ? ' disabled' : '') + '>تأیید: پول قبلاً به مشتری برگشته</button>' +
        '<button class="pn-btn pn-btn--soft" data-ref-cancel>انصراف</button>' +
      '</div></div>';
  } else {
    html += '<div class="pn-refund__acts">' +
      (x.canWallet ? '<button class="pn-btn pn-btn--pri" data-ref-wallet="' + x.id + '"' + (S.busy ? ' disabled' : '') + '>' + I.wallet + ' انتقال ' + esc(x.amountFa) + ' به کیف پول</button>' : '') +
      '<button class="pn-btn pn-btn--soft" data-ref-done="' + x.id + '">ثبت انجام‌شده</button>' +
      '</div>';
  }

  return html + '</div>';
}

function kvs(k, v) {
  return '<div class="pn-kv"><span class="pn-kv__k">' + esc(k) + '</span><span class="pn-kv__v">' + esc(v) + '</span></div>';
}

/** «ویرایش خدمت» از جدول بررسی: پنجره‌ی همان خدمت باز شود. */
function openPendingSvc() {
  if (!S.openSvc || !S.services) { return; }

  var svc = findService(S.openSvc);
  S.openSvc = 0;

  if (svc) {
    S.svcEdit = JSON.parse(JSON.stringify(svc));
    paintModal();
  }
}

function refundAction(id, action) {
  var body = { id: id, action: action };

  if (action === 'wallet') {
    if (!window.confirm('این مبلغ به کیف پول مشتری منتقل شود؟ برای مشتری پیامک کیف پول می‌رود.')) { return; }
  } else {
    body.ref = val('#rf-ref').trim();
  }

  S.busy = true;
  paint();

  post('panel/refund', body).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'انجام نشد.', 'bad'); paint(); return; }

    S.refEdit = null;
    toast(action === 'wallet' ? 'به کیف پول مشتری منتقل شد.' : 'ثبت شد.', 'ok');
    S.wallets = null;
    S.refunds = { items: r.items, open: r.open, canRefund: true, which: 'open' };

    if (S.summary && S.summary.counts) { S.summary.counts.refunds = r.open; shellKey = ''; }
    paint();
  });
}

/**
 * بیعانه‌ی خدمت: پیش‌فرض تنظیمات، بدون بیعانه، یا مبلغ دلخواه.
 * null یعنی پیش‌فرض، ۰ یعنی ندارد.
 */
function depositFields(s) {
  if (!PAY.used) { return ''; }

  var mode = s.deposit === null || typeof s.deposit === 'undefined' ? 'default' : (Number(s.deposit) === 0 ? 'none' : 'custom');
  var rmode = s.cancelRefund === null || typeof s.cancelRefund === 'undefined' ? 'default' : 'custom';

  return '<div class="pn-field"><span class="pn-field__l">بیعانه‌ی رزرو</span>' +
    '<div class="pn-seg">' +
      '<button type="button" class="pn-seg__b' + (mode === 'default' ? ' is-on' : '') + '" data-dep-mode="default">پیش‌فرض' + (s.depositNow && mode === 'default' ? ' (' + money(s.depositNow) + ')' : '') + '</button>' +
      '<button type="button" class="pn-seg__b' + (mode === 'custom' ? ' is-on' : '') + '" data-dep-mode="custom">مبلغ دیگر</button>' +
      '<button type="button" class="pn-seg__b' + (mode === 'none' ? ' is-on' : '') + '" data-dep-mode="none">بدون بیعانه</button>' +
    '</div>' +
    (mode === 'custom'
      ? '<div class="pn-grid" style="margin-top:10px">' +
          '<label class="pn-field"><span class="pn-field__l">مبلغ بیعانه (تومان)</span>' +
          '<input class="pn-in num" id="sv-dep" inputmode="numeric" dir="ltr" value="' + Number(s.deposit || 0) + '"></label>' +
        '</div>'
      : '') +
    (mode !== 'none'
      ? '<div class="pn-grid" style="margin-top:10px">' +
          '<label class="pn-field"><span class="pn-field__l">بازگشتی در لغوِ به‌موقع توسط مشتری (تومان)</span>' +
          '<input class="pn-in num" id="sv-ref" inputmode="numeric" dir="ltr" placeholder="پیش‌فرض' + (s.refundNow ? ' — ' + money(s.refundNow) : '') + '" value="' + (rmode === 'custom' ? Number(s.cancelRefund) : '') + '"></label>' +
        '</div>' +
        '<div id="sv-amt-warn"></div>' +
        '<div class="pn-hint">خالی یعنی پیش‌فرض تنظیمات. ۰ یعنی در لغو هیچ مبلغی برنمی‌گردد. تغییر، فقط روی نوبت‌های تازه اثر دارد.</div>'
      : '<div class="pn-hint">رزرو این خدمت رایگان است و پرداختی ندارد.</div>') +
    '</div>';
}

/**
 * همان قواعد سرور (CMB_Services::validate_amounts)، پیش از ذخیره:
 * بیعانه ۰ یا دست‌کم ۱,۰۰۰؛ برگشتی ۰ یا دست‌کم ۲,۰۰۰ و نه بیشتر از بیعانه.
 */
function amountWarn() {
  var box = $('#sv-amt-warn');
  if (!box) { return; }

  var depEl = $('#sv-dep');
  var dep = depEl ? Number(en(depEl.value).replace(/\D/g, '') || 0) : (S.svcEdit && S.svcEdit.depositNow) || 0;
  var refRaw = en(val('#sv-ref')).replace(/\D/g, '');
  var ref = refRaw === '' ? null : Number(refRaw);
  var msg = '';

  if (depEl && dep > 0 && dep < 1000) { msg = 'بیعانه باید ۰ یا دست‌کم ۱,۰۰۰ تومان باشد؛ زرین‌پال پرداخت کمتر را نمی‌پذیرد.'; }
  else if (ref !== null && dep && ref > dep) { msg = 'برگشتی از بیعانه (' + money(dep) + ') بیشتر است.'; }

  box.innerHTML = msg ? note('bad', I.alert, esc(msg)) : (dep ? '<div class="pn-hint">لغوِ به‌موقع: ' + money(ref === null ? Math.min(dep, (S.svcEdit && S.svcEdit.refundNow) || 0) : ref) + ' تومان به کیف پول مشتری، بقیه نزد مجموعه.</div>' : '');
}

/* ═══ بستن روز ═══ */

function viewClosures() {
  var html = head('بستن روز یا شیفت', 'برای روزهایی که استادکار نیست یا شعبه تعطیل است');

  if (!S.closures) { return html + skel(2); }

  var blocks = S.closures.blocks || [];
  var canClose = ctx().can.closures !== false;

  if (!canClose) {
    html += note('info', I.lock, 'بستن یا باز کردن روز فقط با مدیر شعبه است؛ اینجا فقط بازه‌های بسته را می‌بینید.');
  }

  html += !canClose ? '' : '<div class="pn-card"><div class="pn-card__t">' + I.plus + ' بستن یک بازه</div>' +
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
    html += '<div class="pn-tablewrap"><table class="pn-table pn-table--closures"><thead><tr>' +
      '<th>تاریخ</th><th>محدوده</th><th>دلیل</th><th></th></tr></thead><tbody>';

    S.closures.items.forEach(function (c) {
      html += '<tr>' +
        '<td class="pn-table__b" data-label="تاریخ">' + fa(esc(c.dateFa)) + '</td>' +
        '<td data-label="محدوده">' + esc(c.label) + '</td>' +
        '<td class="pn-table__s" data-label="دلیل">' + (esc(c.reason) || '—') + '</td>' +
        '<td data-label="">' + (canClose ? '<button class="pn-btn pn-btn--soft pn-btn--sm" data-close-del="' + c.id + '">باز کردن</button>' : '') + '</td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';
  }

  html += '</div>';
  return html;
}

/* ═══ کیف پول نمایندگی (US-4.1 تا ۴.۳) ═══ */

function viewBWallet() {
  var W = S.bwallet;
  var html = head('کیف پول نمایندگی', 'هزینه‌ی هر نوبت موفق از همین کیف پول کم می‌شود',
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  if (S.bwNotice) { html += note('bad', I.alert, esc(S.bwNotice)); }

  if (!W) { return html + skel(3); }

  var w = W.summary;
  var t = W.topup;

  if (t) {
    html += note(t.state === 'paid' ? 'ok' : (t.state === 'checking' ? 'warn' : 'bad'),
      t.state === 'paid' ? I.ok : I.alert,
      esc(fa(t.message)) + (t.refId ? ' — کد پیگیری درگاه: <b class="num">' + fa(esc(t.refId)) + '</b>' : ''));
  }

  if (w.blocked) {
    html += note('bad', I.alert, '<b>نوبت‌گیری متوقف است.</b> موجودی آزاد کمتر از هزینه‌ی یک نوبت (' + esc(fa(W.feeFa)) + ') است و مشتری‌ها فعلاً نمی‌توانند در این نمایندگی نوبت بگیرند. با شارژ، همان لحظه دوباره نوبت می‌گیرید.' +
      (W.canTopup ? ' <a href="#bw-amt" data-bw-focus>شارژ کیف پول</a>' : ''));
  } else if (w.low) {
    html += note('warn', I.wallet, 'موجودی کم است: فقط برای <b>' + fa(w.capacity) + '</b> نوبت دیگر.' +
      (W.canTopup ? ' <a href="#bw-amt" data-bw-focus>شارژ کیف پول</a>' : ''));
  }

  html += '<div class="pn-stats pn-stats--wallet">' +
    stat('موجودی کل', w.total, I.wallet, '', w.totalFa) +
    stat('بلوکه برای نوبت‌های پیش‌رو', w.held, I.lock, '', w.heldFa) +
    stat('موجودی آزاد', w.free, I.ok, w.blocked ? 'bad' : (w.low ? 'hot' : 'ok'), w.freeFa) +
    '</div>';

  html += note('info', I.info,
    'هر نوبت موفق ' + esc(fa(W.feeFa)) + ' هزینه دارد: هنگام ثبت نوبت <b>بلوکه</b> می‌شود، بعد از انجام نوبت (یا عدم مراجعه، یا لغو از طرف شما) <b>قطعی کسر</b> می‌شود، و اگر مشتری خودش به‌موقع لغو کند <b>آزاد</b> می‌شود. ' +
    'با موجودی آزاد فعلی جا برای <b>' + fa(w.capacity) + '</b> نوبت دیگر هست.');

  if (W.canTopup) {
    var amt = S.bwAmount || '';

    html += '<div class="pn-card"><div class="pn-card__t">' + I.card + ' شارژ آنلاین</div>' +
      '<div class="pn-chips pn-chips--eq" style="margin-bottom:12px">' +
        (W.presets || []).map(function (p) {
          return '<button type="button" class="pn-chip' + (String(p) === String(amt) ? ' is-on' : '') + '" data-bw-amt="' + p + '"><b>' + fa(money(p)) + '</b> <small>تومان</small></button>';
        }).join('') +
      '</div>' +
      '<div class="pn-filters">' +
        '<label class="pn-field" style="flex:1;min-width:180px"><span class="pn-field__l">مبلغ دلخواه (تومان)</span>' +
          '<input class="pn-in num" id="bw-amt" type="text" inputmode="numeric" dir="ltr" placeholder="' + esc(String(W.topupMin)) + '" value="' + esc(amt) + '"></label>' +
        '<button class="pn-btn pn-btn--pri" data-bw-pay' + (S.busy ? ' disabled' : '') + '>' +
          (S.busy ? '<span class="pn-spin"></span> در حال انتقال به درگاه…' : I.card + ' پرداخت با درگاه') + '</button>' +
      '</div>' +
      '<div class="pn-hint">حداقل شارژ ' + esc(fa(W.topupMinFa)) + '. بعد از پرداخت به همین صفحه برمی‌گردید و مبلغ همان لحظه به موجودی آزاد اضافه می‌شود.</div>' +
    '</div>';
  } else if (!W.topupReady) {
    html += note('warn', I.card, 'درگاه پرداخت سایت هنوز تنظیم نشده است؛ برای شارژ با مدیریت مرکز تماس بگیرید.');
  }

  var h = W.history || { items: [], page: 1, pages: 1 };

  html += '<div class="pn-card"><div class="pn-card__t">' + I.list + ' ریز تراکنش‌ها</div>';

  if (!h.items.length) {
    html += empty('هنوز تراکنشی ثبت نشده است');
  } else {
    html += '<div class="pn-tablewrap"><table class="pn-table pn-table--ledger"><thead><tr>' +
      '<th>تاریخ</th><th>شرح</th><th>مبلغ</th><th>وضعیت</th><th>مانده‌ی آزاد</th></tr></thead><tbody>';

    h.items.forEach(function (x) {
      var kind = x.amount > 0 ? 'confirmed' : (x.status === 'void' ? 'expired' : (x.status === 'held' ? 'pending' : 'cancelled'));

      html += '<tr>' +
        '<td class="num" data-label="تاریخ">' + fa(esc(x.date)) + '</td>' +
        '<td data-label="شرح"><div class="pn-table__b">' + esc(x.typeLabel) + (x.code ? ' <span class="pn-code">' + esc(x.code) + '</span>' : '') + '</div>' +
          ((x.note || x.refId || x.by) ? '<div class="pn-table__s">' + esc(fa([x.note, x.refId ? 'پیگیری ' + x.refId : '', x.by].filter(Boolean).join(' · '))) + '</div>' : '') + '</td>' +
        '<td class="num" data-label="مبلغ" dir="ltr" style="text-align:right"><b class="' + (x.amount > 0 ? 'pn-wal__in' : 'pn-wal__out') + '">' + esc(fa(x.amountFa)) + '</b></td>' +
        '<td data-label="وضعیت">' + badge(kind, x.statusLabel) + '</td>' +
        '<td class="num" data-label="مانده‌ی آزاد">' + (x.status === 'void' ? '—' : esc(fa(x.balanceFa))) + '</td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';

    if (h.pages > 1) {
      html += '<div class="pn-head__act" style="margin-top:16px;justify-content:center">' +
        '<button class="pn-btn pn-btn--soft" data-bw-page="' + (h.page - 1) + '"' + (h.page <= 1 ? ' disabled' : '') + '>' + I.chevR + ' جدیدتر</button>' +
        '<span style="align-self:center;font-size:13px;color:var(--ink-3)" class="num">صفحه ' + fa(h.page) + ' از ' + fa(h.pages) + '</span>' +
        '<button class="pn-btn pn-btn--soft" data-bw-page="' + (h.page + 1) + '"' + (h.page >= h.pages ? ' disabled' : '') + '>قدیمی‌تر ' + I.chevL + '</button>' +
        '</div>';
    }
  }

  html += '</div>';

  if (W.receipts && W.receipts.length) {
    html += '<div class="pn-card"><div class="pn-card__t">' + I.card + ' رسید شارژها</div>' +
      '<div class="pn-tablewrap"><table class="pn-table"><thead><tr><th>تاریخ</th><th>مبلغ</th><th>کد پیگیری</th><th></th></tr></thead><tbody>' +
      W.receipts.map(function (r) {
        return '<tr><td class="num" data-label="تاریخ">' + fa(esc(r.date)) + '</td>' +
          '<td class="num" data-label="مبلغ">' + esc(fa(r.amountFa)) + '</td>' +
          '<td class="num" data-label="کد پیگیری">' + fa(esc(r.refId)) + '</td>' +
          '<td data-label=""><button class="pn-btn pn-btn--soft pn-btn--sm" data-bw-receipt="' + r.id + '">رسید</button></td></tr>';
      }).join('') +
      '</tbody></table></div></div>';
  }

  return html;
}

function receiptModal() {
  var r = S.modal.receipt;

  return '<div class="pn-modal__hd"><div class="pn-modal__t">رسید شارژ کیف پول</div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    '<div id="pn-receipt">' +
      '<div class="pn-kv"><span class="pn-kv__k">نمایندگی</span><span class="pn-kv__v">' + esc(r.branch) + '</span></div>' +
      '<div class="pn-kv"><span class="pn-kv__k">مبلغ</span><span class="pn-kv__v num">' + esc(fa(r.amountFa)) + '</span></div>' +
      '<div class="pn-kv"><span class="pn-kv__k">تاریخ</span><span class="pn-kv__v num">' + fa(esc(r.date)) + '</span></div>' +
      '<div class="pn-kv"><span class="pn-kv__k">کد پیگیری درگاه</span><span class="pn-kv__v num">' + fa(esc(r.refId)) + '</span></div>' +
      (r.card ? '<div class="pn-kv"><span class="pn-kv__k">کارت</span><span class="pn-kv__v num" dir="ltr">' + esc(r.card) + '</span></div>' : '') +
      (r.by ? '<div class="pn-kv"><span class="pn-kv__k">پرداخت‌کننده</span><span class="pn-kv__v">' + esc(r.by) + '</span></div>' : '') +
      '<div class="pn-kv"><span class="pn-kv__k">شماره‌ی رسید</span><span class="pn-kv__v num">' + fa(r.id) + '</span></div>' +
      (r.sandbox ? note('warn', I.info, 'درگاه آزمایشی؛ این پرداخت واقعی نبوده است.') : '') +
    '</div>' +
    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-bw-print>چاپ / ذخیره PDF</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>بستن</button>' +
    '</div>';
}

/** چاپ رسید در پنجره‌ی جدا، تا فقط خود رسید چاپ شود. */
function printReceipt() {
  var box = document.getElementById('pn-receipt');
  var w = box && window.open('', '_blank');

  if (!w) { window.print(); return; }

  w.document.write('<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>رسید شارژ کیف پول</title>' +
    '<style>body{font-family:Tahoma,sans-serif;padding:24px;color:#111}h1{font-size:18px}.pn-kv{display:flex;justify-content:space-between;border-bottom:1px solid #ddd;padding:8px 0}.pn-kv__k{color:#666}</style>' +
    '</head><body><h1>چک موتور — رسید شارژ کیف پول نمایندگی</h1>' + box.innerHTML + '</body></html>');
  w.document.close();
  w.focus();
  w.print();
}

function startTopup() {
  if (S.busy) { return; }

  var amt = Number(en(val('#bw-amt')).replace(/\D/g, '') || 0);
  var min = (S.bwallet && S.bwallet.topupMin) || 0;

  S.bwAmount = amt ? String(amt) : '';

  if (!amt) { toast('مبلغ شارژ را انتخاب یا وارد کنید.', 'bad'); return; }
  if (amt < min) { toast('حداقل مبلغ شارژ ' + fa(money(min)) + ' تومان است.', 'bad'); return; }

  S.busy = true;
  paint();

  post('panel/branch-wallet/topup', { amount: amt }).then(function (r) {
    if (r.success === false || !r.url) {
      S.busy = false;
      toast(r.message || 'اتصال به درگاه ناموفق بود.', 'bad');
      paint();
      return;
    }

    location.href = r.url;
  });
}

/* ═══ پروفایل نمایندگی (US-2.5) ═══ */

function viewProfile() {
  var P = S.profile;
  var html = head('پروفایل نمایندگی', 'آنچه مشتری هنگام انتخاب مرکز و در پیامک تأیید می‌بیند');

  if (!P) { return html + skel(3); }

  var ro = !P.canEdit;

  html += '<div class="pn-card"><div class="pn-card__t">' + I.engine + ' ' + esc(P.title) + ' ' + branchBadge(P.status, P.statusLabel) + '</div>' +
    '<div class="pn-kv"><span class="pn-kv__k">استان و شهر</span><span class="pn-kv__v">' + esc([P.province, P.city].filter(Boolean).join(' / ') || '—') + '</span></div>' +
    (P.activatedAt ? '<div class="pn-kv"><span class="pn-kv__k">فعال از</span><span class="pn-kv__v num">' + fa(esc(P.activatedAt)) + '</span></div>' : '') +
    '<div class="pn-hint">نام، استان و شهر را مدیریت مرکز تعیین می‌کند.</div></div>';

  html += '<div class="pn-card"><div class="pn-card__t">' + I.edit + ' اطلاعات تماس و مکان</div>' +
    '<label class="pn-field"><span class="pn-field__l">نشانی کامل</span>' +
      '<textarea class="pn-in" id="bp-address"' + (ro ? ' disabled' : '') + '>' + esc(P.address) + '</textarea></label>' +
    '<div class="pn-grid">' +
      '<label class="pn-field"><span class="pn-field__l">تلفن تماس مشتری</span>' +
        '<input class="pn-in num" id="bp-phone" dir="ltr" inputmode="tel" value="' + esc(P.phone) + '"' + (ro ? ' disabled' : '') + '></label>' +
      '<label class="pn-field"><span class="pn-field__l">عرض جغرافیایی (lat)</span>' +
        '<input class="pn-in num" id="bp-lat" dir="ltr" inputmode="decimal" placeholder="32.4561" value="' + esc(P.lat === null ? '' : P.lat) + '"' + (ro ? ' disabled' : '') + '></label>' +
      '<label class="pn-field"><span class="pn-field__l">طول جغرافیایی (lng)</span>' +
        '<input class="pn-in num" id="bp-lng" dir="ltr" inputmode="decimal" placeholder="48.3500" value="' + esc(P.lng === null ? '' : P.lng) + '"' + (ro ? ' disabled' : '') + '></label>' +
    '</div>' +
    (window.CMBLocation ? window.CMBLocation.html({editable:!ro,lat:P.lat,lng:P.lng,latInput:'bp-lat',lngInput:'bp-lng',title:P.title}) : '') +
    ((!ro || P.map) ? '<div class="pn-btnrow">' +
      (ro ? '' : '<button class="pn-btn pn-btn--soft" data-bp-geo>' + I.pin + ' مکان فعلی من</button>') +
      (P.map ? '<a class="pn-btn pn-btn--soft" href="' + esc(P.map) + '" target="_blank" rel="noopener">' + I.ext + ' دیدن روی نقشه</a>' : '') +
    '</div>' : '') +
    '<div class="pn-hint">' + (ro ? '' : '«مکان فعلی من» را وقتی در خود مرکز هستید بزنید. ') + 'مشتری با دکمهٔ «مسیریابی با نشان» همین نقطه را می‌بیند.</div>' +
  '</div>';

  html += '<div class="pn-card"><div class="pn-card__t">' + I.grid + ' عکس مرکز</div>' +
    '<div class="pn-poster">' +
      (P.photo ? '<img class="pn-poster__img" src="' + esc(P.photo) + '" alt="">'
               : '<div class="pn-poster__ph">' + I.grid + '<span>عکسی ندارد</span></div>') +
      (ro ? '' : '<div class="pn-poster__act">' +
        '<label class="pn-btn pn-btn--soft pn-btn--sm" style="cursor:pointer">' +
          '<input type="file" id="bp-photo" accept="image/jpeg,image/png,image/webp" hidden>' +
          (S.uploading ? 'در حال آپلود…' : 'انتخاب عکس') + '</label>' +
        (P.photoId ? '<button class="pn-btn pn-btn--soft pn-btn--sm" data-bp-photo-clear>استفاده از تصویر خودکار</button>' : '') +
      '</div>') +
    '</div><div class="pn-hint">نشان اختصاصی شعبه خودکار آماده است و در کارت انتخاب شعبه نمایش داده می‌شود. در صورت تمایل می‌توانید عکس واقعی جایگزین کنید.</div></div>';

  if (P.users && P.users.length) {
    html += '<div class="pn-card"><div class="pn-card__t">' + I.users + ' کاربران پنل</div>' +
      P.users.map(function (u) {
        return '<div class="pn-kv"><span class="pn-kv__k">' + (u.role === 'manager' ? 'مدیر' : 'اپراتور') + '</span>' +
          '<span class="pn-kv__v">' + esc(u.name) + ' — <span class="num">' + fa(esc(u.phone)) + '</span></span></div>';
      }).join('') +
      '<div class="pn-hint">افزودن یا حذف کاربر با مدیریت مرکز است.</div></div>';
  }

  if (!ro) {
    html += '<div class="pn-actions"><button class="pn-btn pn-btn--pri" data-bp-save' + (S.busy ? ' disabled' : '') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : 'ذخیره‌ی پروفایل') + '</button></div>';
  }

  return html;
}

function saveProfile(extra) {
  if (S.busy) { return; }

  var body = {
    address: val('#bp-address').trim(),
    phone: en(val('#bp-phone')).trim(),
    lat: en(val('#bp-lat')).trim(),
    lng: en(val('#bp-lng')).trim()
  };
  Object.keys(extra || {}).forEach(function (key) { body[key] = extra[key]; });

  S.busy = true;
  paint();

  post('panel/branch/save', body).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'ذخیره ناموفق بود.', 'bad'); paint(); return; }

    toast(r.message || 'ذخیره شد.', 'ok');

    if (r.branch) {
      r.branch.users = S.profile ? S.profile.users : [];
      r.branch.canEdit = true;
      S.profile = r.branch;
    }

    paint();
  });
}

function profileGeo() {
  if (!navigator.geolocation) { toast('مرورگر شما موقعیت مکانی نمی‌دهد؛ مختصات را دستی وارد کنید.', 'bad'); return; }

  toast('در حال گرفتن موقعیت…', '');

  navigator.geolocation.getCurrentPosition(function (pos) {
    var la = $('#bp-lat');
    var ln = $('#bp-lng');

    if (la) { la.value = pos.coords.latitude.toFixed(6); }
    if (ln) { ln.value = pos.coords.longitude.toFixed(6); }
    [la, ln].forEach(function (el) { if (el) { el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true})); } });

    toast('موقعیت گذاشته شد؛ «ذخیره‌ی پروفایل» را بزنید.', 'ok');
  }, function () {
    toast('اجازه‌ی موقعیت داده نشد؛ مختصات را دستی وارد کنید.', 'bad');
  }, { enableHighAccuracy: true, timeout: 15000 });
}

/** عکس مرکز: همان مسیر آپلود پوستر، با for=branch. بدون done، روی پروفایل ذخیره می‌شود. */
function uploadBranchPhoto(file, done) {
  if (S.uploading) { return; }
  if (!file) { return; }

  if (file.size > 4 * 1024 * 1024) { toast('حجم تصویر باید کمتر از ۴ مگابایت باشد.', 'bad'); return; }

  var fd = new FormData();
  fd.append('file', file);
  fd.append('for', 'branch');

  S.uploading = true;
  paint();

  uploadRequest(fd)
    .then(function (j) {
      S.uploading = false;

      if (!j || j.success === false || !j.posterId) {
        toast((j && j.message) || 'آپلود ناموفق بود.', 'bad');
        paint();
        return;
      }

      if (done) { done(j); return; }
      post('panel/branch/save', { photoId: j.posterId }).then(function (r) {
        if (r.success === false) { toast(r.message || 'تصویر ذخیره نشد.', 'bad'); paint(); return; }
        if (S.profile && r.branch && S.profile.id === r.branch.id) {
          ['photo', 'photoId', 'photoAuto', 'autoPhoto'].forEach(function (key) { S.profile[key] = r.branch[key]; });
        }
        toast('تصویر شعبه ذخیره شد.', 'ok');
        paint();
      });
    })
    .catch(function () {
      S.uploading = false;
      toast('ارتباط با سرور برقرار نشد.', 'bad');
      paint();
    });
}

/* ═══ مدیر سایت: نمایندگی‌ها (بخش ۶.۵) ═══ */

function viewAgencies() {
  var A = S.agencies;
  var html = head('نمایندگی‌ها', 'مدیریت حساب، خدمات، دسترسی‌ها و موجودی نمایندگی‌ها',
    '<button class="pn-btn pn-btn--dark" data-ag-new>' + I.plus + ' نمایندگی تازه</button>' +
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');
  if (!A) { return html + skel(3); }
  var st = A.settings || {}, items = A.items || [];
  var agencies = items.filter(function (b) { return b.type === 'agency'; });
  html += '<div class="pn-ag-overview">' +
    [['نمایندگی', agencies.length], ['فعال', agencies.filter(function (b) { return b.status === 'active'; }).length],
    ['پیش‌نویس', agencies.filter(function (b) { return b.status === 'draft'; }).length]].map(function (x) {
      return '<div><b>' + fa(x[1]) + '</b><span>' + x[0] + '</span></div>';
    }).join('') + '</div>' +
    '<details class="pn-card pn-ag-settings"' + (S.agSettingsOpen ? ' open' : '') + '><summary>' + I.gear + ' تنظیمات عمومی خدمات و مبالغ</summary>' +
    '<div class="pn-policy-grid">' +
      '<label class="pn-policy"><input type="checkbox" id="ag-own"' + (Number(st.agency_own_services) ? ' checked' : '') + '><span><b>ساخت خدمت اختصاصی</b><small>نمایندگی بتواند خدمتی جدا از کاتالوگ مرکز بسازد.</small></span></label>' +
      '<label class="pn-policy"><input type="checkbox" id="ag-customize"' + (Number(st.agency_customize_services) ? ' checked' : '') + '><span><b>شخصی‌سازی الگوی خدمات</b><small>عنوان، شرح، پوستر و ظرفیت الگو را فقط برای خودش تغییر دهد.</small></span></label>' +
    '</div><p class="pn-hint">این مجوزها برای مدیر نمایندگی است. در ویرایش هر نمایندگی می‌توانید مجوز متفاوتی تعیین کنید. قیمت، روزها و فعال بودن خدمات همیشه با مدیر شعبه است.</p>' +
    '<div class="pn-grid">' +
      [['ag-fee', 'agency_fee', 'هزینه هر رزرو موفق'], ['ag-welcome', 'agency_welcome_credit', 'اعتبار اولیه (یک بار)'],
       ['ag-low', 'agency_low_balance', 'آستانه پیامک هشدار'], ['ag-min', 'agency_topup_min', 'حداقل شارژ آنلاین']].map(function (f) {
        return '<label class="pn-field"><span class="pn-field__l">' + f[2] + '</span><div class="pn-unit"><input class="pn-in num" id="' + f[0] + '" type="number" min="0" value="' + Number(st[f[1]] || 0) + '"><span>تومان</span></div></label>';
      }).join('') +
    '</div><button class="pn-btn pn-btn--pri" data-ag-fees' + (S.busy ? ' disabled' : '') + '>ذخیره تنظیمات عمومی</button>' +
    '<p class="pn-hint">تغییر مبالغ فقط روی رزروهای بعدی اثر دارد.</p></details>';
  items.forEach(function (b) {
    var agency = b.type === 'agency', w = b.wallet;
    html += '<article class="pn-card pn-agc"><div class="pn-agc__hd">' +
      (b.photo ? '<img class="pn-agc__photo" src="' + esc(b.photo) + '" width="160" height="96" alt="' + esc('نشان ' + b.title) + '" loading="lazy">' : '') +
      '<div class="pn-agc__info"><div class="pn-agc__title"><h3>' + esc(b.title) + '</h3>' + branchBadge(b.status, b.statusLabel) +
        (b.displayName && b.displayName !== b.title ? '<div class="pn-agc__dn">برای مشتری: <b>' + esc(b.displayName) + '</b></div>' : '') +
        (agency ? '' : '<span class="pn-badge pn-badge--quick">مرکز اصلی</span>') + '</div>' +
        '<p>' + esc([b.province, b.city].filter(Boolean).join(' / ')) + (b.address ? ' · ' + esc(b.address) : '') + '</p>' +
        '<div class="pn-agc__meta"><span>' + fa(b.future) + ' نوبت آینده</span><span>' + fa(b.services) + ' خدمت فعال</span><span>' + fa((b.users || []).length) + ' کاربر پنل</span></div>' +
        (agency && b.status === 'active' && !b.bookable ? '<p class="pn-agc__warning">' + esc(b.reasonLabel) + '</p>' : '') +
      '</div></div>' +
      (w ? '<div class="pn-agc__balance"><span>موجودی آزاد <b>' + esc(fa(w.freeFa)) + '</b></span><span>بلوکه <b>' + esc(fa(w.heldFa)) + '</b></span><span>کل <b>' + esc(fa(w.totalFa)) + '</b></span></div>' : '') +
      '<div class="pn-svcacts">' +
        '<button class="pn-btn pn-btn--dark pn-btn--sm" data-ag-enter="' + b.id + '">پنل شعبه ' + I.chevL + '</button>' +
        '<button class="pn-btn pn-btn--soft pn-btn--sm" data-ag-edit="' + b.id + '">' + I.edit + ' ویرایش و مجوزها</button>' +
        '<button class="pn-btn pn-btn--soft pn-btn--sm" data-ag-users="' + b.id + '">' + I.users + ' کاربران</button>' +
        (agency ? '<button class="pn-btn pn-btn--soft pn-btn--sm" data-ag-adjust="' + b.id + '">' + I.wallet + ' تغییر موجودی</button>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-ag-status="' + b.id + ':' + (b.status === 'active' ? 'inactive' : 'active') + '">' + (b.status === 'active' ? 'غیرفعال کردن' : 'فعال کردن') + '</button>' +
          '<button class="pn-btn pn-btn--bad pn-btn--sm" data-ag-delete="' + b.id + '">' + I.trash + ' حذف نمایندگی</button>' : '') +
      '</div>' +
      (agency && b.slug ? '<div class="pn-agc__link"><span>لینک رزرو شعبه</span><a dir="ltr" href="' + esc((C.app || '/') + '?branch=' + encodeURIComponent(b.slug)) + '" target="_blank" rel="noopener">' + esc((C.app || '/') + '?branch=' + b.slug) + '</a></div>' : '') +
      (agency && b.status === 'draft' ? '<p class="pn-hint">برای شروع، خدمات و قیمت‌ها را در پنل شعبه آماده کنید و سپس نمایندگی را فعال کنید.</p>' : '') +
    '</article>';
  });
  if (!agencies.length) { html += note('info', I.info, 'اولین نمایندگی را بسازید. تصویر اختصاصی آن خودکار آماده می‌شود؛ نیازی به بارگذاری عکس نیست.'); }
  return html;
}

function findAgency(id) {
  return ((S.agencies && S.agencies.items) || []).filter(function (x) { return x.id === id; })[0];
}

function agencyPolicyFields(f) {
  if (f.isCenter || f.type === 'center') { return ''; }
  var policy = f.servicePolicy || {};
  return '<div class="pn-policy-box"><h3>مجوز خدمات این نمایندگی</h3><div class="pn-grid">' +
    [['ag-policy-own', 'ساخت خدمت اختصاصی', 'allowOwnServices', 'own'], ['ag-policy-customize', 'شخصی‌سازی الگوی مرکز', 'allowCustomizeServices', 'customize']].map(function (x) {
      var v = typeof f[x[2]] !== 'undefined' ? Number(f[x[2]]) : (typeof policy[x[3]] !== 'undefined' ? Number(policy[x[3]]) : -1);
      return '<label class="pn-field"><span class="pn-field__l">' + x[1] + '</span><select class="pn-in" id="' + x[0] + '">' +
        [[-1, 'طبق تنظیم عمومی'], [1, 'مجاز'], [0, 'غیرمجاز']].map(function (o) { return '<option value="' + o[0] + '"' + (v === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>';
    }).join('') +
    '</div><p class="pn-hint">مجوزها فقط برای مدیر همین نمایندگی‌اند. اپراتورها اجازه تغییر خدمات ندارند.</p></div>';
}

/* ─── حذف نمایندگی ───
   پیش از تأیید، سرور می‌گوید حذف دقیقاً چه می‌کند: نوبت‌های آینده (که با
   تیک مدیر لغو می‌شوند)، مانده‌ی کیف پول (که تسویه می‌شود) و کاربرانی که
   دسترسی‌شان بسته می‌شود. حذف در یک مرحله انجام می‌شود. */

function agDeleteModal() {
  var m = S.modal, b = m.agDelete, p = m.preview;
  var html = '<div class="pn-modal__hd"><h2 class="pn-modal__t">حذف نمایندگی</h2><button class="pn-modal__x" data-close aria-label="بستن">' + I.x + '</button></div>';

  if (!p) {
    return html + '<div class="pn-hint" style="margin:8px 0 16px"><span class="pn-spin pn-spin--dark"></span> در حال بررسی نوبت‌ها و کیف پول…</div>' + skel(2);
  }

  if (p.error) {
    return html + note('bad', I.alert, esc(p.error)) +
      '<div class="pn-modal__ft"><button class="pn-btn pn-btn--soft" data-close>بستن</button></div>';
  }

  var w = p.wallet || {};
  var fut = p.future || [];
  var rows = [];

  html += note('warn', I.alert, '«' + esc(b.title) + '» از فهرست نمایندگی‌ها و انتخاب مرکز مشتری حذف می‌شود. سوابق نوبت‌ها و مالی‌اش محفوظ می‌ماند.');

  // نوبت‌های آینده
  if (fut.length) {
    rows.push('<div class="pn-del__i is-warn"><b>' + fa(fut.length) + ' نوبت آینده</b> دارد:' +
      '<ul class="pn-del__list">' + fut.slice(0, 6).map(function (f) {
        return '<li>' + esc(f.date) + ' — ' + esc(f.block) + ' — ' + esc(f.service) + ' — ' + esc(f.name) +
          (f.paid ? ' <span class="pn-dbg-mut">(بیعانه ' + money(f.paid) + ')</span>' : '') + '</li>';
      }).join('') + (fut.length > 6 ? '<li>… و ' + fa(fut.length - 6) + ' نوبت دیگر</li>' : '') + '</ul>' +
      '<label class="pn-check pn-del__ok"><input type="checkbox" id="ag-del-cancel"' + (m.cancelFuture ? ' checked' : '') + '> ' +
        'این ' + fa(fut.length) + ' نوبت لغو شود و به مشتری‌ها پیامک لغو برود' + (fut.some(function (f) { return f.paid; }) ? ' (بیعانه‌ی پرداخت‌شده به کیف پول مشتری برمی‌گردد)' : '') + '</label></div>');
  } else {
    rows.push(delRow(I.check, 'نوبت آینده‌ای ندارد.'));
  }

  // کیف پول
  if (Number(w.total) || Number(w.held)) {
    rows.push(delRow(I.wallet, 'موجودی کیف پول <b>' + money(w.total) + ' تومان</b>' +
      (Number(w.held) ? ' (آزاد ' + money(w.free) + '، بلوکه ' + money(w.held) + ')' : '') +
      ' صفر می‌شود و با عنوان «تسویه‌ی حذف» در دفتر کیف پول ثبت می‌شود.' +
      (Number(p.topups) ? '<div class="pn-dbg-mut">این نمایندگی تا امروز ' + money(p.topups) + ' تومان شارژ آنلاین پرداخت کرده؛ اگر باید بخشی از موجودی به نمایندگی برگردد، بیرون از سیستم پرداخت کنید.</div>' : '')));
  } else {
    rows.push(delRow(I.check, 'کیف پول مانده‌ای ندارد.'));
  }

  if (Number(p.pending)) {
    rows.push(delRow(I.info, fa(p.pending) + ' شارژ نیمه‌کاره دارد؛ هنگام حذف نتیجه‌اش از درگاه پرسیده می‌شود.' +
      (Number(p.wait) > 0 ? ' اگر مدیر نمایندگی هنوز در صفحه‌ی بانک باشد، درگاه تا حدود ' + fa(p.wait) + ' دقیقه‌ی دیگر نتیجه را قطعی می‌کند و حذف تا آن موقع انجام نمی‌شود.' : '')));
  }

  var users = p.users || [];
  rows.push(delRow(I.users, users.length
    ? 'دسترسی پنل ' + fa(users.length) + ' کاربر (' + esc(fa(users.map(function (u) { return u.name; }).join('، '))) + ') بسته می‌شود؛ حساب مشتری‌شان می‌ماند.'
    : 'کاربر پنلی ندارد.'));

  html += '<div class="pn-del">' + rows.join('') + '</div>';

  html += '<label class="pn-field"><span class="pn-field__l">برای تأیید، نام «' + esc(b.title) + '» را بنویسید</span>' +
    '<input class="pn-in" id="ag-delete-title" autocomplete="off" value="' + esc(m.confirmTitle || '') + '"></label>' +
    '<div class="pn-modal__ft"><button class="pn-btn pn-btn--bad" data-ag-delete-confirm' + (agDeleteReady() ? '' : ' disabled') + '>' +
      (S.busy ? '<span class="pn-spin"></span> در حال حذف…' : 'حذف نمایندگی') + '</button>' +
    '<button class="pn-btn pn-btn--soft" data-close>انصراف</button></div>';

  return html;
}

/** یک ردیف خلاصه با آیکون کنار متن. */
function delRow(icon, html) {
  return '<div class="pn-del__i pn-del__i--ic">' + icon + '<div>' + html + '</div></div>';
}

/** دکمه‌ی حذف فقط وقتی فعال است که نام درست باشد و لغو نوبت‌های آینده (اگر هست) تأیید شده باشد. */
function agDeleteReady() {
  var m = S.modal;

  if (!m || !m.agDelete || !m.preview || m.preview.error || S.busy) { return false; }
  if (m.confirmTitle !== m.agDelete.title) { return false; }

  return !(m.preview.future || []).length || !!m.cancelFuture;
}

function agDeleteOpen(agency) {
  S.modal = { agDelete: agency, confirmTitle: '', preview: null, cancelFuture: false };
  paintModal();

  get('panel/admin/branch/delete-check?id=' + agency.id).then(function (r) {
    if (!S.modal || !S.modal.agDelete || S.modal.agDelete.id !== agency.id) { return; }
    S.modal.preview = r.success === false ? { error: r.message || 'بررسی ناموفق بود.' } : r;
    paintModal();
  });
}

function deleteAgency() {
  if (!agDeleteReady()) { return; }
  var m = S.modal;
  S.busy = true; paintModal();
  post('panel/admin/branch/delete', { id: m.agDelete.id, confirmTitle: m.confirmTitle, cancelFuture: m.cancelFuture ? 1 : 0 }).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      toast(r.message, 'bad');

      // وضعیت عوض شده (نوبت تازه، شارژ نیمه‌کاره): پیش‌نمایش دوباره
      if (S.modal && S.modal.agDelete) { agDeleteOpen(m.agDelete); S.modal.confirmTitle = m.confirmTitle; }
      return;
    }

    S.modal = null; S.agencies = null;
    toast(r.message, 'ok');
    if (Number(ctx().branchId) === Number(m.agDelete.id)) { switchBranch(0, 'agencies'); }
    else { refreshSummary(); load('agencies'); }
  });
}

function agencyModal() {
  var f = S.modal.agency;
  var isNew = !f.id;

  function fld(id, label, value, attrs, hint) {
    return '<label class="pn-field"><span class="pn-field__l">' + label + '</span>' +
      '<input class="pn-in" id="' + id + '" value="' + esc(value == null ? '' : value) + '"' + (attrs || '') + '>' +
      (hint ? '<div class="pn-hint">' + hint + '</div>' : '') + '</label>';
  }

  return '<div class="pn-modal__hd"><div class="pn-modal__t">' + (isNew ? 'نمایندگی تازه' : 'ویرایش ' + esc(f.title)) + '</div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    agencyPolicyFields(f) +
    fld('ag-title', 'نام نمایندگی', f.title, '', 'مثلاً «نمایندگی اندیمشک»') +
    '<div class="pn-field"><span class="pn-field__l">نوع</span>' +
      '<div class="pn-in pn-in--ro">' + (f.isCenter ? 'مرکز اصلی (بدون کیف پول و کسر)' : 'نمایندگی (کیف پول و کسر هر رزرو)') + '</div>' +
      '<div class="pn-hint">مرکز اصلی فقط یکی است (بوشهر)؛ هر شعبه‌ی تازه نمایندگی است.</div></div>' +
    '<div class="pn-grid pn-grid--2">' +
      (f.isCenter
        ? fld('ag-slug', 'نامک لینک (لاتین)', f.slug, ' dir="ltr" readonly', 'نامک مرکز اصلی ثابت است.')
        : fld('ag-slug', 'نامک لینک (لاتین)', f.slug, ' dir="ltr" placeholder="andimeshk"', 'در لینک مستقیم: <bdi dir="ltr" style="white-space:nowrap">?branch=andimeshk</bdi>')) +
      fld('ag-sort', 'ترتیب', f.sortOrder || 0, ' type="number" dir="ltr"') +
      agencyPlaceFields(f, fld) +
    '</div>' +
    '<label class="pn-field"><span class="pn-field__l">نشانی</span><textarea class="pn-in" id="ag-address">' + esc(f.address || '') + '</textarea></label>' +
    fld('ag-contact', 'نام و نام خانوادگی مسئول', f.contactName, ' autocomplete="off" placeholder="مثلاً علی رضایی"',
      'مشتری‌ها شعبه را با این نام می‌بینند: <b id="ag-display">' + esc(agencyDisplayName(f.contactName, f.title)) + '</b>') +
    (isNew ? '<div class="pn-grid">' + fld('ag-mphone', 'موبایل مدیر نمایندگی', f.managerPhone, ' dir="ltr" inputmode="tel" placeholder="09…"', 'با همین شماره و کد پیامکی وارد پنل می‌شود.') : '') +
    fld('ag-sms', 'موبایل‌های دریافت پیامک (اختیاری)', f.managerPhones, ' dir="ltr" placeholder="0912…، 0913…"', 'پیامک نوبت جدید و هشدار موجودی؛ مدیر نمایندگی خودکار می‌گیرد.') +
    (isNew ? '</div>' : '') +
    '<div class="pn-grid pn-grid--2">' +
      fld('ag-lat', 'عرض جغرافیایی', f.lat === null ? '' : f.lat, ' dir="ltr" inputmode="decimal" placeholder="32.4561"') +
      fld('ag-lng', 'طول جغرافیایی', f.lng === null ? '' : f.lng, ' dir="ltr" inputmode="decimal" placeholder="48.3500"') +
    '</div>' +
    (window.CMBLocation ? window.CMBLocation.html({editable:true,lat:f.lat,lng:f.lng,latInput:'ag-lat',lngInput:'ag-lng'}) : '') +
    '<div class="pn-field" style="margin-top:16px"><span class="pn-field__l">عکس</span>' +
      '<div class="pn-poster">' +
        (f.photo ? '<img class="pn-poster__img" src="' + esc(f.photo) + '" alt="">'
                 : '<div class="pn-poster__ph">' + I.grid + '<span>عکسی ندارد</span></div>') +
        '<div class="pn-poster__act">' +
          '<label class="pn-btn pn-btn--soft pn-btn--sm" style="cursor:pointer">' +
            '<input type="file" id="ag-photo" accept="image/jpeg,image/png,image/webp" hidden>' +
            (S.uploading ? 'در حال آپلود…' : 'انتخاب عکس') + '</label>' +
          (f.photoId ? '<button type="button" class="pn-btn pn-btn--soft pn-btn--sm" data-ag-photo-clear>استفاده از تصویر خودکار</button>' : '') +
        '</div>' +
      '</div>' +
      '<div class="pn-hint">تصویر اختصاصی با رنگ و کد شعبه به‌صورت خودکار ساخته می‌شود. بارگذاری عکس واقعی اختیاری است.</div>' +
    '</div>' +
    (isNew ? note('info', I.info, 'نمایندگی به‌صورت <b>پیش‌نویس</b> ساخته می‌شود و تا فعالش نکنید به مشتری‌ها نشان داده نمی‌شود. خدمات کاتالوگ مرکز غیرفعال برایش کپی می‌شوند.') : '') +
    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-ag-save' + (S.busy ? ' disabled' : '') + '>' +
        (S.busy ? '<span class="pn-spin"></span> در حال ذخیره…' : (isNew ? 'ساخت پیش‌نویس' : 'ذخیره')) + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>انصراف</button>' +
    '</div>';
}

/** همان قاعده‌ی CMB_Branches::display_name: «چک موتور - نام مسئول»، بی‌نام همان عنوان. */
function agencyDisplayName(person, title) {
  person = String(person || '').replace(/\s+/g, ' ').trim();
  return person ? 'چک موتور - ' + person : (String(title || '').trim() || 'نام شعبه');
}

/* استان و شهر نمایندگی از بانک iran-places.js؛ شهری که در فهرست نیست با
   «شهر دیگر» تایپ می‌شود و مقدار قدیمیِ بیرون از فهرست هم از دست نمی‌رود. */
function agencyPlaceFields(f, fld) {
  var P = window.CMB_PLACES && typeof window.CMB_PLACES === 'object' ? window.CMB_PLACES : null;
  if (!P) { return fld('ag-prov', 'استان', f.province) + fld('ag-city', 'شهر', f.city); }
  var provs = Object.keys(P), prov = f.province || '', cities = P[prov] || [];
  var other = !!f.cityOther || (!!f.city && cities.indexOf(f.city) === -1);
  function opt(v, t, on) { return '<option value="' + esc(v) + '"' + (on ? ' selected' : '') + '>' + esc(t) + '</option>'; }
  return '<label class="pn-field"><span class="pn-field__l">استان</span><select class="pn-in" id="ag-prov" autocomplete="off">' +
      opt('', 'انتخاب استان…', !prov) +
      (prov && provs.indexOf(prov) === -1 ? opt(prov, prov, true) : '') +
      provs.map(function (p) { return opt(p, p, p === prov); }).join('') + '</select></label>' +
    '<label class="pn-field"><span class="pn-field__l">شهر</span><select class="pn-in" id="ag-city-sel" autocomplete="off"' + (prov ? '' : ' disabled') + '>' +
      opt('', prov ? 'انتخاب شهر…' : 'اول استان را انتخاب کنید', !f.city && !other) +
      cities.map(function (c) { return opt(c, c, !other && c === f.city); }).join('') +
      opt('__other__', 'شهر دیگر (تایپ می‌کنم)', other) + '</select>' +
      (other ? '<input class="pn-in" id="ag-city" style="margin-top:8px" value="' + esc(f.city || '') + '" placeholder="نام شهر" autocomplete="off">' : '') +
    '</label>';
}

function agencyCity() {
  var sel = $('#ag-city-sel');
  if (!sel) { return val('#ag-city').trim(); }
  return sel.value === '__other__' ? val('#ag-city').trim() : sel.value;
}

function saveAgency() {
  if (S.busy || S.uploading || !S.modal || !S.modal.agency) { return; }
  var f = S.modal.agency;
  var body = {
    id: f.id || 0,
    title: val('#ag-title').trim(),
    slug: val('#ag-slug').trim().toLowerCase(),
    sortOrder: Number(en(val('#ag-sort')) || 0),
    province: val('#ag-prov').trim(),
    city: agencyCity(),
    address: val('#ag-address').trim(),
    contactName: val('#ag-contact').trim(),
    managerPhones: en(val('#ag-sms')).trim(),
    lat: en(val('#ag-lat')).trim(),
    lng: en(val('#ag-lng')).trim()
  };

  if ($('#ag-policy-own')) { body.allowOwnServices = Number(val('#ag-policy-own')); }
  if ($('#ag-policy-customize')) { body.allowCustomizeServices = Number(val('#ag-policy-customize')); }
  if ($('#ag-mphone')) { body.managerPhone = en(val('#ag-mphone')).trim(); }
  if (typeof f.photoId !== 'undefined') { body.photoId = Number(f.photoId) || 0; }

  // نامک مرکز اصلی عوض نمی‌شود؛ اصلاً فرستاده نمی‌شود
  if (f.isCenter) { delete body.slug; }

  // مقادیر فرم اگر سرور خطا داد از دست نروند
  Object.keys(body).forEach(function (k) { f[k] = body[k]; });

  S.busy = true;
  paintModal();

  post('panel/admin/branch', body).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'ذخیره ناموفق بود.', 'bad'); paintModal(); return; }

    toast(body.id ? 'نمایندگی ذخیره شد.' : 'پیش‌نویس نمایندگی ساخته شد.', 'ok');
    S.modal = null;
    S.agencies = null;
    S.summary = null;
    refreshSummary();
    load('agencies');
  });
}

/**
 * کاربران پنل یک شعبه (مرکز یا نمایندگی) و نقششان، مطابق جدول بخش ۴ سند:
 * مدیر همه‌ی کارهای همان شعبه را دارد؛ اپراتور نوبت‌ها، مشتری‌ها و تعطیلی را
 * (بدون خدمات، تنظیمات شیفت، پروفایل و کیف پول). همه‌چیزِ همه‌ی شعبه‌ها
 * فقط با مدیر کل.
 */
function agUsersModal() {
  var b = S.modal.agUsers;
  var center = b.type !== 'agency';
  var where = center ? 'مرکز' : 'نمایندگی';

  return '<div class="pn-modal__hd"><div class="pn-modal__t">کاربران پنل ' + esc(b.title) + '</div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    ((b.users || []).length ? (b.users || []).map(function (u) {
      return '<div class="pn-user">' +
        '<div class="pn-user__who"><b>' + esc(u.name) + '</b><span class="num" dir="ltr">' + fa(esc(u.phone)) + '</span></div>' +
        '<div class="pn-seg pn-user__role" role="group" aria-label="نقش ' + esc(u.name) + '">' +
          '<button type="button" class="pn-seg__b' + (u.role === 'manager' ? ' is-on' : '') + '" data-ag-user-role="' + u.id + ':manager"' + (S.busy ? ' disabled' : '') + '>مدیر ' + where + '</button>' +
          '<button type="button" class="pn-seg__b' + (u.role !== 'manager' ? ' is-on' : '') + '" data-ag-user-role="' + u.id + ':operator"' + (S.busy ? ' disabled' : '') + '>اپراتور</button>' +
        '</div>' +
        '<button class="pn-btn pn-btn--bad pn-btn--sm" data-ag-user-del="' + u.id + '"' + (S.busy ? ' disabled' : '') + '>برداشتن دسترسی</button>' +
      '</div>';
    }).join('') : empty('هنوز کاربری ندارد')) +
    '<div class="pn-hint" style="margin-top:8px"><b>مدیر ' + where + ':</b> همه‌ی کارهای همین ' + where + ' (نوبت‌ها، مشتری‌ها، تعطیلی، قیمت و فعال بودن خدمات، تنظیمات شیفت' + (center ? '' : '، پروفایل و کیف پول نمایندگی') + '). ' +
      '<b>اپراتور:</b> نوبت‌ها، مشتری‌ها و تعطیلی؛ خدمات و تنظیمات شیفت' + (center ? '' : ' و کیف پول') + ' را ندارد. ' +
      'تعریف خدمات، کیف پول مشتری‌ها، شعبه‌های دیگر، گزارش‌ها و تنظیمات سایت فقط با مدیر کل است.</div>' +
    '<div class="pn-card__t" style="margin-top:16px">' + I.plus + ' افزودن کاربر</div>' +
    '<div class="pn-grid">' +
      '<label class="pn-field"><span class="pn-field__l">موبایل</span><input class="pn-in num" id="agu-phone" dir="ltr" inputmode="tel" placeholder="09…"></label>' +
      '<label class="pn-field"><span class="pn-field__l">نام</span><input class="pn-in" id="agu-name"></label>' +
      '<label class="pn-field"><span class="pn-field__l">نقش</span><select class="pn-in" id="agu-role">' +
        '<option value="operator">اپراتور (نوبت‌ها، مشتری‌ها و تعطیلی)</option><option value="manager">مدیر ' + where + '</option></select></label>' +
    '</div>' +
    '<div class="pn-hint">' + (center ? '' : 'هر نمایندگی یک مدیر و حداکثر ' + fa((S.agencies && S.agencies.maxOps) || 2) + ' اپراتور دارد. ') + 'هر شماره فقط در یک شعبه کاربر پنل می‌شود. ورود با همین شماره و کد پیامکی است.</div>' +
    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--pri" data-ag-user-add' + (S.busy ? ' disabled' : '') + '>افزودن</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>بستن</button>' +
    '</div>';
}

function agUsers(op, extra) {
  var b = S.modal.agUsers;
  var body = { id: b.id, op: op };

  Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });

  S.busy = true;
  paintModal();

  post('panel/admin/branch/users', body).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'انجام نشد.', 'bad'); paintModal(); return; }

    toast(r.message || 'انجام شد.', 'ok');
    b.users = r.users || [];
    S.agencies = null;
    paintModal();
    load('agencies');
  });
}

function agAdjustModal() {
  var m = S.modal;
  var b = m.agAdjust;
  var dec = m.dir === 'dec';

  return '<div class="pn-modal__hd"><div><div class="pn-modal__t">تغییر موجودی ' + esc(b.title) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px">موجودی آزاد: ' + esc(fa(b.wallet ? b.wallet.freeFa : '')) + '</div></div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    '<div class="pn-seg" style="margin-bottom:14px">' +
      '<button type="button" class="pn-seg__b' + (dec ? '' : ' is-on') + '" data-ag-dir="inc">افزایش</button>' +
      '<button type="button" class="pn-seg__b' + (dec ? ' is-on' : '') + '" data-ag-dir="dec">کاهش</button>' +
    '</div>' +
    '<label class="pn-field"><span class="pn-field__l">مبلغ (تومان)</span>' +
      '<input class="pn-in num" id="aga-amt" type="text" inputmode="numeric" dir="ltr" value="' + esc(m.amount || '') + '"></label>' +
    '<label class="pn-field"><span class="pn-field__l">دلیل (الزامی؛ در تاریخچه‌ی نمایندگی دیده می‌شود)</span>' +
      '<input class="pn-in" id="aga-note" value="' + esc(m.note || '') + '"></label>' +
    '<div class="pn-hint">کاهش از موجودی آزاد بیشتر نمی‌شود. برای برگشت هزینه‌ی یک نوبت مشخص، از جزئیات همان نوبت استفاده کنید.</div>' +
    '<div class="pn-modal__ft">' +
      '<button class="pn-btn ' + (dec ? 'pn-btn--bad' : 'pn-btn--pri') + '" data-ag-adjust-save' + (S.busy ? ' disabled' : '') + '>' + (dec ? 'کاهش موجودی' : 'افزایش موجودی') + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-close>انصراف</button>' +
    '</div>';
}

function agAdjust() {
  var m = S.modal;
  var amt = en(val('#aga-amt')).replace(/\D/g, '');
  var noteTxt = val('#aga-note').trim();

  m.amount = amt;
  m.note = noteTxt;

  if (!amt || Number(amt) < 1) { toast('مبلغ را وارد کنید.', 'bad'); return; }
  if (noteTxt.length < 3) { toast('دلیل این تغییر را بنویسید.', 'bad'); return; }

  S.busy = true;
  paintModal();

  post('panel/admin/branch/adjust', { id: m.agAdjust.id, amount: Number(amt), dir: m.dir === 'dec' ? 'dec' : 'inc', note: noteTxt }).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'انجام نشد.', 'bad'); paintModal(); return; }

    toast(r.message || 'انجام شد.', 'ok');
    S.modal = null;
    S.agencies = null;
    load('agencies');
  });
}

function agStatus(id, status, confirmed) {
  if (S.busy) { return; }
  if (!confirmed && status === 'active' && !window.confirm('این نمایندگی فعال شود و در فهرست مشتری‌ها بیاید؟')) { return; }

  S.busy = true;
  post('panel/admin/branch/status', { id: id, status: status, confirm: confirmed ? 1 : 0 }).then(function (r) {
    S.busy = false;
    if (r.success === false && r.code === 'cmb_branch_has_future') {
      if (window.confirm(r.message + ' غیرفعال شود؟')) { agStatus(id, status, true); }
      return;
    }

    if (r.success === false) { toast(r.message || 'انجام نشد.', 'bad'); return; }

    toast(r.message || 'انجام شد.', 'ok');
    S.agencies = null;
    S.summary = null;
    refreshSummary();
    load('agencies');
  });
}

function saveFees() {
  if (S.busy) { return; }
  var body = {
    agency_own_services: checked('#ag-own') ? 1 : 0,
    agency_customize_services: checked('#ag-customize') ? 1 : 0,
    agency_fee: en(val('#ag-fee')),
    agency_welcome_credit: en(val('#ag-welcome')),
    agency_low_balance: en(val('#ag-low')),
    agency_topup_min: en(val('#ag-min'))
  };

  if (S.agencies) { S.agencies.settings = Object.assign({}, S.agencies.settings, body); }
  S.agSettingsOpen = true;
  S.busy = true;
  paint();

  post('panel/admin/fees', body).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'ذخیره ناموفق بود.', 'bad'); paint(); return; }

    toast('تنظیمات عمومی ذخیره شد.', 'ok');
    if (S.agencies) { S.agencies.settings = r.settings; }
    paint();
  });
}

/** مدیر سایت: پنل یک شعبه‌ی دیگر را ببیند. */
function switchBranch(id, then) {
  if (switchingBranch || pendingWrites || S.busy || S.uploading) { toast('ابتدا منتظر پایان عملیات جاری بمانید.', 'bad'); return; }
  switchingBranch = true;
  invalidateData();
  S.modal = null; S.svcEdit = null;
  paint();
  post('panel/branch/switch', { branch: Number(id) }).then(function (r) {
    switchingBranch = false;
    if (r.success === false || !r.counts) { toast(r.message || 'تغییر شعبه ناموفق بود.', 'bad'); paint(); return; }

    // همه‌ی داده‌های شعبه‌ی قبلی کنار می‌رود
    S.board = S.list = S.services = S.svcMeta = S.customers = S.closures = S.sched = null;
    S.custBranch = '';
    S.refunds = S.wallets = S.payReview = S.payReport = S.bwallet = S.profile = null;
    S.health = null;
    S.got = {};
    S.filter = { scope: 'upcoming', status: '', date: '', q: '', page: 1 };
    S.summary = r;
    S.got[jobs().summary[0]] = Date.now();
    shellKey = '';

    toast('پنل ' + ((r.ctx && r.ctx.branch && r.ctx.branch.title) || '') + ' باز شد.', 'ok');
    go(then || (allowed(S.view) ? S.view : 'summary'));
  });
}

/* ═══ مدیر سایت: گزارش نمایندگی‌ها (US-5.1) ═══ */

function viewReport() {
  var R = S.agReport;
  var html = head('گزارش نمایندگی‌ها', R ? 'از ' + fa(R.fromFa) + ' تا ' + fa(R.toFa) : '',
    '<button class="pn-btn pn-btn--dark" data-agrep-csv' + (R && R.items.length ? '' : ' disabled') + '>' + I.down + ' خروجی اکسل</button>' +
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  html += '<div class="pn-card"><div class="pn-filters">' +
    '<label class="pn-field"><span class="pn-field__l">از تاریخ</span>' + J.fieldHTML('rp-from', S.agFrom || (R ? R.from : ''), {}) + '</label>' +
    '<label class="pn-field"><span class="pn-field__l">تا تاریخ</span>' + J.fieldHTML('rp-to', S.agTo || (R ? R.to : ''), {}) + '</label>' +
    '<button class="pn-btn pn-btn--dark" data-agrep-apply>نمایش</button>' +
  '</div>';

  if (!R) { return html + skel(3) + '</div>'; }

  if (!R.items.length) { return html + empty('هنوز نمایندگی‌ای نیست') + '</div>'; }

  html += '<div class="pn-tablewrap"><table class="pn-table"><thead><tr>' +
    '<th>نمایندگی</th><th>نوبت‌ها</th><th>انجام‌شده</th><th>عدم مراجعه</th><th>لغو مشتری</th><th>لغو نمایندگی</th><th>کسر قطعی</th><th>شارژ</th><th>موجودی آزاد</th>' +
    '</tr></thead><tbody>';

  R.items.forEach(function (x) {
    html += '<tr>' +
      '<td data-label="نمایندگی"><div class="pn-table__b">' + esc(x.title) + '</div><div class="pn-table__s">' + esc(x.city) + ' · ' + esc(x.statusLabel) + '</div></td>' +
      '<td class="num" data-label="نوبت‌ها">' + fa(x.bookings) + '</td>' +
      '<td class="num" data-label="انجام‌شده">' + fa(x.done) + '</td>' +
      '<td class="num" data-label="عدم مراجعه">' + fa(x.noShow) + '</td>' +
      '<td class="num" data-label="لغو مشتری">' + fa(x.cancelledCustomer || 0) + ' <span class="pn-table__s">(' + fa(x.customerCancelRate || 0) + '٪)</span></td>' +
      '<td class="num" data-label="لغو نمایندگی">' + fa(x.cancelledBranch) + ' <span class="pn-table__s">(' + fa(x.cancelRate) + '٪)</span></td>' +
      '<td class="num" data-label="کسر قطعی">' + esc(fa(x.capturedFa)) + '</td>' +
      '<td class="num" data-label="شارژ">' + esc(fa(x.topupFa)) + '</td>' +
      '<td class="num" data-label="موجودی آزاد">' + esc(fa(x.freeFa)) + '</td>' +
    '</tr>';
  });

  var t = R.totals;

  html += '<tr class="pn-table__total"><td data-label="">جمع</td>' +
    '<td class="num" data-label="نوبت‌ها">' + fa(t.bookings) + '</td><td class="num" data-label="انجام‌شده">' + fa(t.done) + '</td>' +
    '<td class="num" data-label="عدم مراجعه">' + fa(t.noShow) + '</td><td class="num" data-label="لغو مشتری">' + fa(t.cancelledCustomer || 0) + '</td><td class="num" data-label="لغو نمایندگی">' + fa(t.cancelledBranch) + '</td>' +
    '<td class="num" data-label="کسر قطعی">' + esc(fa(t.capturedFa)) + '</td><td class="num" data-label="شارژ">' + esc(fa(t.topupFa)) + '</td>' +
    '<td class="num" data-label="موجودی آزاد">' + esc(fa(t.freeFa)) + '</td></tr>';

  html += '</tbody></table></div>' +
    '<div class="pn-hint">نوبت‌ها بر اساس تاریخ نوبت در بازه شمرده می‌شوند؛ کسر قطعی بر اساس لحظه‌ی کسر (منهای برگشت‌ها)، و شارژ بر اساس لحظه‌ی پرداخت. موجودی، موجودی همین حالاست.</div>' +
    '</div>';

  return html;
}

function exportAgReport() {
  var R = S.agReport;
  if (!R || !R.items.length) { return; }

  var lines = [['نمایندگی', 'شهر', 'وضعیت', 'نوبت‌ها', 'انجام‌شده', 'عدم مراجعه', 'لغو نمایندگی', 'لغو مشتری', 'لغو مدیریت', 'نرخ لغو نمایندگی (٪)', 'نرخ لغو مشتری (٪)', 'کسر قطعی (تومان)', 'شارژ (تومان)', 'موجودی کل', 'بلوکه', 'موجودی آزاد'].map(csvCell).join(',')];

  R.items.forEach(function (x) {
    lines.push([x.title, x.city, x.statusLabel, x.bookings, x.done, x.noShow, x.cancelledBranch, x.cancelledCustomer, x.cancelledAdmin, x.cancelRate, x.customerCancelRate, x.captured, x.topup, x.total, x.held, x.free].map(csvCell).join(','));
  });

  var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');

  a.href = url;
  a.download = 'agencies-' + R.from + '-' + R.to + '.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);

  setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}

/* ═══ مودال نوبت ═══ */

function bookingModal() {
  var b = S.modal.item;

  // لغو نوبتِ پرداخت‌شده: اول مبلغ بازگشتی پرسیده می‌شود
  if (S.modal.cancelPaid) { return cancelPaidModal(b); }

  // لغو نوبت نمایندگی توسط مدیر سایت: هزینه آزاد شود یا کسر؟
  if (S.modal.cancelFee) { return cancelFeeModal(b); }

  var labels = {
    done:      { t: 'انجام شد', c: 'pn-btn--pri' },
    no_show:   { t: 'عدم مراجعه', c: 'pn-btn--soft' },
    confirmed: { t: 'بازگردانی', c: 'pn-btn--soft' },
    cancelled: { t: b.status === 'pending' ? 'لغو و آزاد کردن جا' : 'لغو نوبت', c: 'pn-btn--bad' }
  };

  /* دکمه‌ها را سرور تعیین می‌کند (همان قواعد پیشخوان)؛ برای پاسخ‌های
     قدیمی‌تر که actions ندارند، همان چهار دکمه‌ی قبلی. */
  var list = b.actions || ['done', 'no_show', 'confirmed', 'cancelled', 'delete'].filter(function (x) { return x !== b.status; });
  var acts = list.filter(function (x) { return labels[x]; });
  var canDelete = list.indexOf('delete') !== -1;
  var p = b.pay;

  var payHtml = '';

  if (p && p.deposit) {
    payHtml = '<div class="pn-kv"><span class="pn-kv__k">بیعانه</span><span class="pn-kv__v">' + esc(p.depositFa) +
      (p.wallet > 0 ? ' (' + (p.gateway > 0 ? 'از کیف پول ' + esc(p.walletFa) + '، از درگاه ' + esc(p.gatewayFa) : 'کامل از کیف پول') + ')' : '') +
      (p.statusLabel ? ' — ' + esc(p.statusLabel) : '') + '</span></div>';

    if (b.status === 'pending') {
      payHtml += note('warn', I.clock, 'مشتری هنوز پرداخت نکرده است. جا تا ' + fa(Math.ceil((b.holdLeft || 0) / 60)) + ' دقیقه‌ی دیگر برایش نگه داشته می‌شود و اگر پرداخت نکند خودکار آزاد می‌شود.');
    } else if (p.status === 'paid' && (b.status === 'confirmed' || b.status === 'done')) {
      payHtml += note('ok', I.card, 'بیعانه‌ی ' + esc(p.depositFa) + ' پرداخت شده؛ هنگام مراجعه از صورت‌حساب کسر شود' +
        (p.remainingFa ? '. باقی‌مانده: <b>' + esc(p.remainingFa) + '</b>' : '') + '.');
    } else if (p.status === 'refund_due' || p.status === 'refunding') {
      payHtml += note('warn', I.card, esc(p.refundFa) + ' هنوز به کیف پول مشتری نرفته؛ در «بیعانه و کیف پول ← برگشت‌های مانده» است.');
    } else if (p.status === 'refunded') {
      payHtml += String(p.statusLabel || '').indexOf('کیف پول') !== -1
        ? note('info', I.wallet, esc(p.refundFa) + ' به کیف پول مشتری برگشت.' + (b.status === 'cancelled' ? ' با «بازگردانی»، همین مبلغ از کیف پولش پس گرفته می‌شود (اگر خرجش نکرده باشد).' : ''))
        : note('info', I.card, esc(p.refundFa) + ' به مشتری برگشت داده شد.');
    } else if (p.status === 'kept') {
      payHtml += note('info', I.card, 'بیعانه نزد مجموعه ماند.');
    }

    if (b.phone && ctx().can.center) {
      payHtml += '<div style="margin-top:8px"><button class="pn-btn pn-btn--sm pn-btn--soft" data-wal-open="' + esc(b.phone) + '">' + I.wallet + ' کیف پول این مشتری</button></div>';
    }
  }

  return '<div class="pn-modal__hd">' +
      '<div><div class="pn-modal__t">' + esc(b.name) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px" class="pn-code">کد پیگیری ' + esc(b.code) + '</div></div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +

    '<div style="margin-bottom:16px">' + badge(b.status, b.statusLabel) + '</div>' +

    '<div class="pn-kv"><span class="pn-kv__k">خدمت</span><span class="pn-kv__v">' + esc(b.service) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">تاریخ</span><span class="pn-kv__v">' + fa(esc(b.dateLong)) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">ساعت</span><span class="pn-kv__v">' + esc(b.blockLabel) + ' — ' + fa(esc(b.blockStart)) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">موبایل</span><span class="pn-kv__v num"><a href="tel:' + esc(b.phone) + '">' + fa(esc(b.phone)) + '</a></span></div>' +
    (b.province ? '<div class="pn-kv"><span class="pn-kv__k">استان</span><span class="pn-kv__v">' + esc(b.province) + '</span></div>' : '') +
    '<div class="pn-kv"><span class="pn-kv__k">شهر</span><span class="pn-kv__v">' + esc(b.city || '—') + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">نوع خودرو</span><span class="pn-kv__v">' + esc(b.carBrand) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">نوع موتور</span><span class="pn-kv__v">' + esc(b.carModel) + '</span></div>' +
    '<div class="pn-kv"><span class="pn-kv__k">سال ساخت</span><span class="pn-kv__v num">' + fa(esc(b.carYear)) + '</span></div>' +
    (b.carMileage ? '<div class="pn-kv"><span class="pn-kv__k">کارکرد</span><span class="pn-kv__v num">' + money(b.carMileage) + ' کیلومتر</span></div>' : '') +
    (b.note ? '<div class="pn-kv"><span class="pn-kv__k">توضیحات</span><span class="pn-kv__v">' + esc(b.note) + '</span></div>' : '') +
    '<div class="pn-kv"><span class="pn-kv__k">زمان ثبت</span><span class="pn-kv__v num">' + esc(J.formatTime(b.createdAt, FA_DIGITS) || fa(b.createdAt)) + '</span></div>' +
    payHtml +
    feeHtml(b) +

    (b.status !== 'cancelled' ? '' :
      note('warn', I.alert, 'این نوبت لغو شده و ظرفیتش آزاد شده است.')) +
    (b.status !== 'expired' ? '' :
      note('info', I.info, 'بیعانه‌ی این نوبت پرداخت نشد و جایش آزاد است. وضعیتش قابل تغییر نیست.')) +

    '<div class="pn-modal__ft" style="flex-wrap:wrap">' +
      acts.map(function (a) {
        return '<button class="pn-btn ' + labels[a].c + '" data-status="' + a + '"' + (S.busy ? ' disabled' : '') + '>' + labels[a].t + '</button>';
      }).join('') +
      (canDelete ? '<button class="pn-btn pn-btn--bad pn-btn--sm" data-status="delete" style="margin-inline-start:auto">' + I.trash + ' حذف کامل</button>' : '') +
    '</div>' +
    '<div class="pn-hint">«لغو نوبت» برای مشتری پیامک اطلاع‌رسانی می‌فرستد.' +
      (canDelete ? ' «حذف کامل» رکورد را برای همیشه پاک می‌کند و پیامکی نمی‌رود.' : '') +
      (p && p.deposit && !canDelete ? ' نوبتی که بیعانه‌اش پرداخت شده حذف نمی‌شود؛ سابقه‌ی پرداخت باید بماند.' : '') + '</div>';
}

/**
 * هزینه‌ی رزرو نمایندگی روی نوبت: وضعیتش، و برای مدیر سایت دکمه‌ی
 * برگشت کسرِ قطعی (US-4.5).
 */
function feeHtml(b) {
  if (!b.fee || !b.feeLabel) { return ''; }

  var c = ctx();
  var html = '<div class="pn-kv"><span class="pn-kv__k">کیف پول نمایندگی</span><span class="pn-kv__v">' + esc(fa(b.feeLabel)) + '</span></div>';

  if (b.feeStatus === 'held' && c.agency && !c.isAdmin && b.status === 'confirmed') {
    html += '<div class="pn-hint">با «انجام شد» یا «عدم مراجعه»، و همچنین با لغو این نوبت از طرف شما، این مبلغ قطعی کسر می‌شود. اگر مشتری خودش به‌موقع لغو کند، آزاد می‌شود.</div>';
  }

  if (c.isAdmin && b.feeStatus === 'captured') {
    html += '<div style="margin-top:8px"><button class="pn-btn pn-btn--sm pn-btn--soft" data-fee-refund="' + b.id + '"' + (S.busy ? ' disabled' : '') + '>' + I.refresh + ' برگشت هزینه به کیف پول نمایندگی</button></div>';
  }

  return html;
}

/** لغو نوبت نمایندگی توسط مدیر سایت: انتخاب سرنوشت هزینه (D13). */
function cancelFeeModal(b) {
  return '<div class="pn-modal__hd"><div><div class="pn-modal__t">لغو نوبت ' + esc(b.name) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px">نوبت نمایندگی — هزینه‌ی رزرو: ' + esc(fa(money(b.fee))) + ' تومان</div></div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    '<p class="pn-hint" style="font-size:13px;margin:0 0 14px">لغو برای مشتری پیامک اطلاع‌رسانی می‌فرستد. هزینه‌ی رزرو این نوبت از کیف پول نمایندگی چه شود؟</p>' +
    '<div class="pn-modal__ft" style="flex-wrap:wrap">' +
      '<button class="pn-btn pn-btn--bad" data-cancel-fee="release"' + (S.busy ? ' disabled' : '') + '>لغو و آزاد کردن هزینه</button>' +
      '<button class="pn-btn pn-btn--soft" data-cancel-fee="capture"' + (S.busy ? ' disabled' : '') + '>لغو و کسر هزینه</button>' +
      '<button class="pn-btn pn-btn--soft" data-cancel-back>بازگشت</button>' +
    '</div>' +
    '<div class="pn-hint">«آزاد» یعنی مبلغ بلوکه‌شده به موجودی آزاد نمایندگی برمی‌گردد؛ «کسر» یعنی مثل لغو توسط خود نمایندگی، قطعی کسر می‌شود.</div>';
}

/** برگشت کسرِ قطعیِ یک نوبت، با دلیل. */
function feeRefund(id) {
  var reason = window.prompt('دلیل برگشت هزینه (در تاریخچه‌ی کیف پول نمایندگی ثبت می‌شود):', '');
  if (reason === null) { return; }

  S.busy = true;
  paintModal();

  post('panel/admin/fee-refund', { id: id, note: reason }).then(function (r) {
    S.busy = false;

    if (r.success === false) { toast(r.message || 'برگشت ناموفق بود.', 'bad'); paintModal(); return; }

    toast(r.message || 'هزینه برگشت داده شد.', 'ok');

    if (r.item) {
      if (S.modal) { S.modal.item = r.item; }
      if (S.list && S.list.items) {
        S.list.items = S.list.items.map(function (x) { return x.id === id ? r.item : x; });
      }
    }

    paint();
    refreshSummary();
  });
}

/** پنجره‌ی لغو نوبت پرداخت‌شده: چقدر به مشتری برگردد؟ */
function cancelPaidModal(b) {
  var p = b.pay;
  var def = S.modal.refund;

  return '<div class="pn-modal__hd"><div><div class="pn-modal__t">لغو نوبت ' + esc(b.name) + '</div>' +
      '<div style="font-size:12px;color:var(--ink-3);margin-top:3px">بیعانه‌ی پرداخت‌شده: ' + esc(p.depositFa) + '</div></div>' +
      '<button class="pn-modal__x" data-close>' + I.x + '</button></div>' +
    '<label class="pn-field"><span class="pn-field__l">مبلغی که به کیف پول مشتری برمی‌گردد (تومان)</span>' +
      '<input class="pn-in num" id="pn-refund" type="text" inputmode="numeric" dir="ltr" value="' + esc(def) + '"></label>' +
    '<div class="pn-chips" style="margin-bottom:12px">' +
      '<button type="button" class="pn-chip" data-refund-fill="' + p.deposit + '">کل بیعانه</button>' +
      (p.cancelRefund ? '<button type="button" class="pn-chip" data-refund-fill="' + p.cancelRefund + '">مثل لغو مشتری (' + money(p.cancelRefund) + ')</button>' : '') +
      '<button type="button" class="pn-chip" data-refund-fill="0">هیچ</button>' +
    '</div>' +
    '<div class="pn-hint">بین ۰ و ' + money(p.deposit) + ' تومان. مبلغ همان لحظه به کیف پول مشتری در سایت می‌رود (اگر بعداً نوبت را بازگردانید، از کیف پولش پس گرفته می‌شود). برای مشتری پیامک لغو و پیامک کیف پول می‌رود.</div>' +
    '<div class="pn-modal__ft">' +
      '<button class="pn-btn pn-btn--bad" data-cancel-paid' + (S.busy ? ' disabled' : '') + '>' + (S.busy ? '<span class="pn-spin"></span> …' : 'لغو نوبت') + '</button>' +
      '<button class="pn-btn pn-btn--soft" data-cancel-back>بازگشت</button>' +
    '</div>';
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

/* ═══ آزمون و عیب‌یابی (فقط مدیر سایت) ═══

   پنج زبانه، هیچ‌کدام چیزی را روی سایت تغییر نمی‌دهند:
   بررسی کامل · شبیه‌ساز رزرو · آزمون خودکار (داخل تراکنشی که برگردانده
   می‌شود) · گزارش خطا · اطلاعات سیستم. */

var DBG_TABS = [
  ['checks', 'بررسی کامل'],
  ['sim', 'شبیه‌ساز رزرو'],
  ['selftest', 'آزمون خودکار'],
  ['log', 'گزارش خطا'],
  ['info', 'اطلاعات سیستم']
];

var LV = { ok: ['confirmed', 'سالم'], info: ['done', 'اطلاع'], warn: ['no_show', 'توجه'], bad: ['cancelled', 'مشکل'],
           error: ['cancelled', 'خطا'], pass: ['confirmed', 'موفق'], fail: ['cancelled', 'ناموفق'], skip: ['done', 'ردشده'] };

var SRC = { api: 'اپ مشتری (سرور)', app: 'اپ مشتری (مرورگر)', panel: 'پنل', php: 'PHP', server: 'رویداد سرور', selftest: 'آزمون خودکار' };

function lvBadge(l) {
  var m = LV[l] || ['done', l];
  return badge(m[0], m[1]);
}

/** یک مورد بررسی: مشکل‌ها کامل، موارد سالم در یک خط. */
function dbgItem(i) {
  if (i.level === 'ok') {
    return '<div class="pn-dbg-ok">' + I.check + '<span><b>' + esc(i.title) + '</b> — ' + esc(fa(i.detail)) + '</span></div>';
  }

  var cls = i.level === 'info' ? 'pn-note pn-note--info' : 'pn-hl pn-hl--' + esc(i.level);

  return '<div class="' + cls + '"><div><b>' + esc(i.title) + '</b> ' + esc(fa(i.detail)) +
    (i.fix ? '<div class="pn-hl__fix">راه‌حل: ' + esc(i.fix) + '</div>' : '') + '</div></div>';
}

/* ═══ لاگ پیامک و کد تایید (۱.۴۵.۰) ═══ */

/** متن لاگ: متغیرهای پترن با « · » (نه «;»)، ارقام فارسی؛ نشانی‌های وب دست‌نخورده. */
function slText(r) {
  var t = r.channel === 'simple' ? r.text : r.text.split(';').join(' · ');
  return t.split(/(https?:\/\/\S+)/).map(function (part, i) { return i % 2 ? '<bdi dir="ltr">' + esc(part) + '</bdi>' : esc(fa(part)); }).join('');
}

function viewSmsLog() {
  var d = S.smslog;
  var html = head('پیامک و کد تایید', 'هر پیامک و هر رویداد کد تایید؛ ' + fa(d && d.keep ? d.keep : 7) + ' روز نگه داشته و بعد خودکار پاک می‌شود',
    '<button class="pn-btn pn-btn--soft" data-reload>' + I.refresh + ' تازه‌سازی</button>');

  if (!d) { return html + skel(4); }

  if (d.ready === false) {
    return html + note('warn', I.alert, 'جدول لاگ هنوز ساخته نشده است. یک بار پیشخوان وردپرس را باز کنید تا به‌روزرسانی افزونه انجام شود.');
  }

  if (d.smsOn === false) {
    html += note('warn', I.alert, 'پیامک در تنظیمات خاموش است؛ هیچ پیامکی واقعاً فرستاده نمی‌شود و در لاگ «ارسال نشد (پیامک خاموش)» ثبت می‌شود.');
  }

  var s = d.summary || {};
  html += '<div class="pn-sl__cap">آمار ' + fa(d.keep || 7) + ' روز گذشته</div>' +
    '<div class="pn-stats pn-stats--sl">' +
      stat('پیامک ارسال‌شده', s.smsOk, I.chat, 'ok') +
      stat('پیامک ارسال‌نشده', s.smsFail, I.alert, s.smsFail ? 'bad' : '') +
      stat('کد تایید ارسال‌شده', s.otpSent, I.lock, '') +
      stat('ورود موفق با کد', s.otpVerified, I.ok, 'ok') +
      stat('کد اشتباه یا منقضی', s.otpFailed, I.x, s.otpFailed ? 'bad' : '') +
    '</div>';

  var kinds = [['', 'همه'], ['sms', 'پیامک‌ها'], ['otp', 'کد تایید']];
  html += '<div class="pn-card">' +
    '<div class="pn-filters">' +
      '<div class="pn-tabs" role="group" aria-label="نوع">' + kinds.map(function (k) {
        return '<button class="pn-tabs__b' + (S.slKind === k[0] ? ' is-on' : '') + '" data-sl-kind="' + k[0] + '">' + k[1] + '</button>';
      }).join('') + '</div>' +
      '<label class="pn-field"><span class="pn-field__l">نتیجه</span><select class="pn-in" id="sl-status">' +
        [['', 'همه'], ['ok', 'موفق'], ['fail', 'ناموفق']].map(function (o) {
          return '<option value="' + o[0] + '"' + (S.slStatus === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('') + '</select></label>' +
      '<label class="pn-field" style="flex:1;min-width:180px"><span class="pn-field__l">شماره موبایل</span>' +
        '<input class="pn-in num" id="sl-q" type="search" inputmode="numeric" dir="ltr" placeholder="0912…" value="' + esc(S.slQ) + '"></label>' +
      '<button class="pn-btn pn-btn--dark" data-sl-search>جستجو</button>' +
      (S.slQ || S.slStatus || S.slKind ? '<button class="pn-btn pn-btn--soft" data-sl-clear>پاک کردن</button>' : '') +
    '</div>';

  if (!d.items.length) {
    return html + empty('چیزی ثبت نشده است', S.slQ || S.slStatus || S.slKind ? 'با این فیلتر موردی در ' + fa(d.keep || 7) + ' روز گذشته نیست.' : 'از این به بعد هر پیامک و هر کد تایید اینجا دیده می‌شود.') + '</div>';
  }

  html += '<div class="pn-tablewrap' + (S.fetching ? ' is-fetching' : '') + '"><table class="pn-table pn-sl"><thead><tr>' +
    '<th>زمان</th><th>شماره</th><th>نوع</th><th>متن / جزئیات</th><th>نتیجه</th></tr></thead><tbody>';

  d.items.forEach(function (r) {
    var badge = r.status === 'ok' ? 'confirmed' : (r.status === 'fail' ? 'cancelled' : 'expired');
    var how = r.channel === 'pattern' ? 'پترن ' + fa(r.bodyId) : (r.channel === 'simple' ? 'متن ساده' : '');
    html += '<tr class="pn-sl__r pn-sl__r--' + esc(r.status) + '">' +
      '<td data-label="زمان" class="num">' + esc(r.atFa) + '</td>' +
      '<td data-label="شماره" class="num">' + (r.phone ? '<a href="tel:' + esc(r.phone) + '" dir="ltr">' + esc(r.phoneFa) + '</a>' : '—') + '</td>' +
      '<td data-label="نوع"><div class="pn-table__b">' + (r.kind === 'otp' ? I.lock : I.chat) + esc(r.label) + '</div>' +
        (how ? '<div class="pn-table__s">' + esc(how) + '</div>' : '') + '</td>' +
      '<td data-label="متن / جزئیات"><div class="pn-sl__txt">' + (r.text ? slText(r) : '—') + '</div></td>' +
      '<td data-label="نتیجه"><span class="pn-badge pn-badge--' + badge + '">' + esc(r.eventFa) + '</span>' +
        (r.error ? '<div class="pn-sl__err">' + esc(r.error) + '</div>' : '') + '</td>' +
      '</tr>';
  });

  html += '</tbody></table></div>';

  if (d.pages > 1) {
    html += '<div class="pn-head__act" style="margin-top:16px;justify-content:center">' +
      '<button class="pn-btn pn-btn--soft" data-sl-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>' + I.chevR + ' قبلی</button>' +
      '<span style="align-self:center;font-size:13px;color:var(--ink-3)" class="num">صفحه ' + fa(d.page) + ' از ' + fa(d.pages) + ' — ' + fa(d.total) + ' مورد</span>' +
      '<button class="pn-btn pn-btn--soft" data-sl-page="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>بعدی ' + I.chevL + '</button>' +
      '</div>';
  }

  return html + '</div>';
}

function viewDebug() {
  var D = S.dbg;
  var html = head('آزمون و عیب‌یابی', 'برای پیدا کردن دلیل هر مشکل رزرو. هیچ‌کدام از این بخش‌ها چیزی را روی سایت تغییر نمی‌دهند.',
    '<button class="pn-btn pn-btn--soft" data-dbg-reload>' + I.refresh + ' تازه‌سازی</button>');

  html += '<div class="pn-tabs pn-reftabs pn-dbgtabs">' + DBG_TABS.map(function (t) {
    var n = t[0] === 'log' && D.log && D.log.day ? D.log.day.error : 0;
    return '<button class="pn-tabs__b' + (D.tab === t[0] ? ' is-on' : '') + '" data-dbg-tab="' + t[0] + '">' + t[1] +
      (n ? ' <span class="pn-count pn-count--hot">' + fa(n) + '</span>' : '') + '</button>';
  }).join('') + '</div>';

  switch (D.tab) {
    case 'sim':      return html + dbgSim();
    case 'selftest': return html + dbgSelftest();
    case 'log':      return html + dbgLog();
    case 'info':     return html + dbgInfo();
    default:         return html + dbgChecks();
  }
}

/* ─── بررسی کامل ─── */

function dbgChecks() {
  var c = S.dbg.checks;

  if (!c) { return skel(4); }

  var all = [];
  c.sections.forEach(function (s) { all = all.concat(s.items); });
  c.branches.forEach(function (b) { if (b.status === 'active') { all = all.concat(b.items); } });

  var bad = all.filter(function (i) { return i.level === 'bad'; }).length;
  var warn = all.filter(function (i) { return i.level === 'warn'; }).length;

  var html = bad
    ? '<div class="pn-note pn-note--bad">' + I.alert + '<div><b>' + fa(bad) + ' مشکل</b> پیدا شد که جلوی رزرو یا کار درست سیستم را می‌گیرد' +
      (warn ? ' و ' + fa(warn) + ' مورد نیاز به توجه دارد' : '') + '. هر مورد راه‌حلش را زیر خودش دارد.</div></div>'
    : (warn
      ? '<div class="pn-note pn-note--warn">' + I.info + '<div>مشکلی که جلوی رزرو را بگیرد نیست؛ ' + fa(warn) + ' مورد نیاز به توجه دارد.</div></div>'
      : '<div class="pn-note pn-note--ok">' + I.ok + '<div><b>همه‌چیز مرتب است.</b> همه‌ی بررسی‌ها بدون ایراد گذشت.</div></div>');

  html += '<p class="pn-hint" style="margin:0 0 14px">زمان بررسی: ' + esc(c.now) + '</p>';

  html += '<h2 class="pn-dbg-h">مرکزها و نمایندگی‌ها</h2>';

  c.branches.forEach(function (b) {
    html += '<div class="pn-card pn-dbg-br">' +
      '<div class="pn-dbg-br__h"><h3 class="pn-card__t">' + esc(b.title) + '</h3>' +
        branchBadge(b.status, b.statusLabel) + ' ' + (b.status === 'active' ? lvBadge(b.level) : '') + '</div>';

    html += b.items.map(dbgItem).join('');

    if (b.services.length) {
      html += '<div class="pn-dbg-svcs">' + b.services.map(function (s) {
        return '<div class="pn-dbg-svc' + (s.open ? '' : ' is-empty') + '">' +
          '<div class="pn-dbg-svc__t"><b>' + esc(s.title) + '</b>' + (s.active === 2 ? ' <span class="pn-badge pn-badge--quick">آزمایشی</span>' : '') +
            (s.deposit ? ' <span class="pn-dbg-mut">بیعانه ' + money(s.deposit) + '</span>' : '') + '</div>' +
          '<div class="pn-dbg-svc__d">' + (s.open
            ? fa(s.open) + ' شیفت خالی در بازه — نخستین: ' + esc(s.first)
            : '<span class="pn-dbg-bad">بدون نوبت خالی</span>' + (s.why ? ' — ' + esc(s.why) : '')) + '</div>' +
          '<button class="pn-btn pn-btn--soft pn-btn--sm" data-dbg-goto="' + b.id + ':' + s.id + '">شبیه‌ساز</button>' +
        '</div>';
      }).join('') + '</div>';
    } else if (b.status === 'active') {
      html += '<p class="pn-hint">خدمت فعالی ندارد.</p>';
    }

    html += '</div>';
  });

  c.sections.forEach(function (s) {
    html += '<h2 class="pn-dbg-h">' + esc(s.title) + ' ' + lvBadge(s.level) + '</h2><div class="pn-card">';
    var sorted = s.items.slice().sort(function (a, b) {
      var r = { bad: 0, warn: 1, info: 2, ok: 3 };
      return (r[a.level] || 0) - (r[b.level] || 0);
    });
    html += sorted.map(dbgItem).join('') + '</div>';
  });

  return html;
}

/* ─── شبیه‌ساز رزرو ─── */

function dbgSim() {
  var D = S.dbg;
  var R = D.sim;

  // درخواست تمام شد (موفق یا ناموفق): دکمه دوباره فعال
  if (!S.fetching) { D.simBusy = false; }

  if (!R) { return skel(3); }

  var br = (R.branches || []).filter(function (b) { return b.id === D.simBranch; })[0] || (R.branches || [])[0];
  var svcs = br ? br.services : [];

  var html = '<div class="pn-card"><div class="pn-grid">' +
    '<label class="pn-field"><span class="pn-field__l">مرکز</span><select class="pn-in" id="dbg-br">' +
      (R.branches || []).map(function (b) {
        return '<option value="' + b.id + '"' + (br && b.id === br.id ? ' selected' : '') + '>' + esc(b.title) + (b.status !== 'active' ? ' (غیرفعال)' : '') + '</option>';
      }).join('') + '</select></label>' +
    '<label class="pn-field"><span class="pn-field__l">خدمت</span><select class="pn-in" id="dbg-svc">' +
      svcs.map(function (s) {
        return '<option value="' + s.id + '"' + (s.id === D.simService ? ' selected' : '') + '>' + esc(s.title) +
          (s.active === 2 ? ' (آزمایشی)' : (s.active ? '' : ' (غیرفعال)')) + '</option>';
      }).join('') + '</select></label>' +
    '<label class="pn-field"><span class="pn-field__l">شماره‌ی مشتری (اختیاری)</span>' +
      '<input class="pn-in" id="dbg-phone" inputmode="tel" dir="ltr" placeholder="09…" value="' + esc(D.simPhone) + '"></label>' +
  '</div>' +
  '<p class="pn-hint">تقویم همین خدمت را همان‌طور که مشتری می‌بیند نشان می‌دهد، با دلیل هر شیفت بسته. با شماره‌ی مشتری، نوبت‌های فعال و سقفش هم دیده می‌شود و «آزمون ثبت» با حساب خود او انجام می‌شود.</p>' +
  '<div class="pn-actions"><button class="pn-btn pn-btn--dark" data-dbg-sim-run' + (D.simBusy ? ' disabled' : '') + '>' +
    (D.simBusy ? '<span class="pn-spin"></span> ' : '') + 'نمایش</button></div></div>';

  if (R.rules) {
    html += '<p class="pn-hint" style="margin:-4px 0 12px">' + esc(R.rules.today) + ' — نوبت از ' + fa(R.rules.minDays) + ' روز بعد تا ' +
      fa(R.rules.window) + ' روز، دست‌کم ' + fa(R.rules.minHours) + ' ساعت پیش از شروع شیفت؛ سقف نوبت فعال هر مشتری ' +
      (R.rules.maxActive ? fa(R.rules.maxActive) : 'ندارد') + (R.rules.perService ? '، از هر خدمت یکی' : '') + '.</p>';
  }

  if (!R.days) {
    return html + (R.error ? '<div class="pn-hl pn-hl--bad">' + esc(R.error) + '</div>' : '');
  }

  var b = R.branch;

  if (b.status !== 'active') {
    html += '<div class="pn-hl pn-hl--warn"><b>این مرکز فعال نیست.</b> مشتری آن را نمی‌بیند؛ تقویم زیر فقط برای بررسی است.</div>';
  } else if (!b.bookable) {
    html += '<div class="pn-hl pn-hl--bad"><b>این مرکز همین حالا نوبت نمی‌گیرد</b> (' +
      esc({ balance: 'موجودی کیف پول نمایندگی کمتر از هزینه‌ی یک رزرو است', no_services: 'هیچ خدمت فعالی ندارد' }[b.reason] || b.reason) +
      '). مشتری با برچسب «فعلاً نوبت نمی‌گیرد» می‌بیندش، حتی اگر شیفت خالی باشد.</div>';
  }

  if (R.mismatch) {
    html += '<div class="pn-hl pn-hl--bad"><b>' + fa(R.mismatch) + ' شیفت ناهماهنگ:</b> تقویم مشتری و بررسی هنگام ثبت درباره‌ی این شیفت‌ها نتیجه‌ی متفاوتی دارند (با علامت قرمز). این یک باگ است؛ همین صفحه را برای پشتیبان بفرستید.</div>';
  }

  var sv = R.service;
  var wd = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
  var days = [6, 0, 1, 2, 3, 4, 5].filter(function (d) { return sv.weekdays.indexOf(d) !== -1; })
    .map(function (d) { return wd[d]; });

  html += '<p class="pn-hint" style="margin:0 0 10px"><b>' + esc(sv.title) + '</b> — روزهای ارائه: ' + esc(days.join('، ') || 'هیچ روزی') +
    (sv.deposit ? '؛ بیعانه ' + money(sv.deposit) + ' تومان' : '؛ بدون بیعانه') +
    (sv.own ? '؛ سهمیه‌ی جدا' : '؛ ظرفیت مشترک شیفت') + '</p>';

  html += '<div class="pn-dbg-days">' + R.days.map(function (d) {
    var out = '<div class="pn-dbg-day"><div class="pn-dbg-day__t">' + esc(d.label) + '</div>';

    if (!d.blocks.length) {
      return out + '<div class="pn-dbg-slot is-off"><span>' + esc(d.reason || 'بسته') + '</span></div></div>';
    }

    return out + d.blocks.map(function (k) {
      var key = d.date + '|' + k.key;
      var t = D.tries[key];
      var st = k.available ? 'is-open' : 'is-off';

      return '<div class="pn-dbg-slot ' + st + (k.agree ? '' : ' is-bad') + '">' +
        '<div class="pn-dbg-slot__h"><b>' + esc(k.label) + ' ' + esc(k.start) + '</b>' +
          '<span>' + (k.available ? fa(k.remaining) + ' از ' + fa(k.capacity) + ' خالی' : esc(k.reason || 'بسته') + ' — ' + fa(k.booked) + '/' + fa(k.capacity)) + '</span></div>' +
        (k.check !== 'ok' ? '<div class="pn-dbg-mut">هنگام ثبت: ' + esc(k.checkMsg) + ' <code dir="ltr">' + esc(k.check) + '</code></div>' : '') +
        (!k.agree ? '<div class="pn-dbg-bad">ناهماهنگ: تقویم «' + (k.available ? 'آزاد' : 'بسته') + '» ولی ثبت «' + (k.check === 'ok' ? 'مجاز' : 'رد') + '»</div>' : '') +
        '<button class="pn-btn pn-btn--soft pn-btn--sm" data-dbg-try="' + esc(key) + '"' + (D.tryBusy === key ? ' disabled' : '') + '>' +
          (D.tryBusy === key ? '<span class="pn-spin pn-spin--dark"></span> ' : '') + 'آزمون ثبت</button>' +
        (t ? dbgTryResult(t) : '') +
      '</div>';
    }).join('') + '</div>';
  }).join('') + '</div>';

  if (R.customer) { html += dbgCustomer(R.customer); }

  return html;
}

function dbgTryResult(t) {
  if (t.error) { return '<div class="pn-dbg-try is-bad">' + esc(t.error) + '</div>'; }

  var sms = t.sms && t.sms.length ? ' — ' + fa(t.sms.length) + ' پیامک ساخته می‌شد' : '';

  if (t.ok) {
    var b = t.booking || {};
    return '<div class="pn-dbg-try is-ok">' + I.check + ' <b>ثبت می‌شد</b> با ' + esc(t.as) + ' — وضعیت «' + esc(b.status) + '»' +
      (b.deposit ? '، بیعانه ' + money(b.deposit) + (b.gateway ? ' (درگاه باز می‌شد)' : '') : '') +
      (b.fee ? '، هزینه‌ی نمایندگی ' + money(b.fee) : '') + sms + '. چیزی ذخیره نشد.</div>';
  }

  return '<div class="pn-dbg-try is-bad"><b>ثبت نمی‌شد</b> با ' + esc(t.as || '') + ': ' + esc(t.message || '') +
    (t.code ? ' <code dir="ltr">' + esc(t.code) + '</code>' : '') + '</div>';
}

function dbgCustomer(c) {
  var html = '<h2 class="pn-dbg-h">مشتری ' + esc(fa(c.phone)) + '</h2><div class="pn-card">';

  if (!c.exists) {
    return html + '<p class="pn-hint" style="margin:0">' + esc(c.limit) + '</p></div>';
  }

  html += '<div class="pn-kv"><span class="pn-kv__k">نام</span><span class="pn-kv__v">' + esc(c.name) + '</span></div>' +
    (c.wallet !== null ? '<div class="pn-kv"><span class="pn-kv__k">کیف پول</span><span class="pn-kv__v">' + money(c.wallet) + ' تومان</span></div>' : '') +
    '<div class="pn-kv"><span class="pn-kv__k">نوبت فعال</span><span class="pn-kv__v">' + fa(c.active.length) + '</span></div>';

  c.active.forEach(function (a) {
    html += '<div class="pn-dbg-ok">' + I.cal + '<span>' + esc(a.date) + ' — ' + esc(a.block) + ' — ' + esc(a.service) +
      ' (' + esc(a.branch) + ') — ' + esc(a.status) + ' <code dir="ltr">' + esc(a.code) + '</code></span></div>';
  });

  html += '<div class="' + (c.limitOk ? 'pn-hl pn-hl--ok' : 'pn-hl pn-hl--warn') + '" style="margin-top:10px">' + esc(c.limit) + '</div>';

  return html + '</div>';
}

/* ─── آزمون خودکار ─── */

function dbgSelftest() {
  var D = S.dbg;
  var R = D.st;

  var html = '<div class="pn-card"><h3 class="pn-card__t">' + I.check + ' آزمون خودکار کل مسیر رزرو</h3>' +
    '<p class="pn-hint" style="margin:0 0 10px">مسیر واقعی مشتری روی همین سایت و با همین تنظیمات اجرا می‌شود: فهرست مراکز، تقویم هر خدمت، ثبت نوبت، پرداخت بیعانه، ' +
    'بلوکه‌ی هزینه‌ی نمایندگی، پیامک‌ها، لغو، ورود با کد، و رد شدن درست حالت‌های نادرست (روز بسته، ظرفیت پر، بیرون از بازه، نمایندگی بی‌موجودی…).</p>' +
    '<div class="pn-note pn-note--info">' + I.info + '<div>همه‌چیز داخل یک تراکنش دیتابیس انجام و آخر کار <b>برگردانده</b> می‌شود: هیچ نوبت، کاربر، پرداخت یا تراکنش کیف پولی باقی نمی‌ماند، ' +
    'هیچ پیامکی واقعاً ارسال نمی‌شود و به درگاه پولی نمی‌رود. مشتری‌های واقعی در این چند ثانیه چیزی حس نمی‌کنند.</div></div>' +
    '<div class="pn-actions"><button class="pn-btn pn-btn--pri" data-dbg-st-run' + (D.stBusy ? ' disabled' : '') + '>' +
      (D.stBusy ? '<span class="pn-spin"></span> در حال اجرا…' : 'اجرای آزمون خودکار') + '</button></div></div>';

  if (R && R.error) {
    html += '<div class="pn-hl pn-hl--bad">' + esc(R.error) + '</div>';
  } else if (R) {
    html += '<div class="pn-stats">' +
      '<div class="pn-stat pn-stat--ok"><div class="pn-stat__k">موفق</div><div class="pn-stat__v">' + fa(R.passed) + '</div></div>' +
      '<div class="pn-stat' + (R.failed ? ' pn-stat--bad' : '') + '"><div class="pn-stat__k">ناموفق</div><div class="pn-stat__v">' + fa(R.failed) + '</div></div>' +
      '<div class="pn-stat"><div class="pn-stat__k">هشدار</div><div class="pn-stat__v">' + fa(R.warned) + '</div></div>' +
      '<div class="pn-stat"><div class="pn-stat__k">زمان</div><div class="pn-stat__v">' + fa((R.ms / 1000).toFixed(1)) + ' ث</div></div>' +
    '</div>';

    if (R.leak) {
      html += '<div class="pn-hl pn-hl--bad"><b>توجه:</b> تعداد ردیف‌های دیتابیس بعد از آزمون با پیش از آن یکی نیست. ممکن است بخشی از داده‌ی آزمایشی مانده باشد (کاربر «مشتری آزمون خودکار»). با پشتیبان تماس بگیرید.</div>';
    }

    var groups = [];
    var by = {};

    R.items.forEach(function (i) {
      if (!by[i.group]) { by[i.group] = []; groups.push(i.group); }
      by[i.group].push(i);
    });

    groups.forEach(function (g) {
      html += '<div class="pn-card pn-dbg-st"><h3 class="pn-card__t">' + esc(g) + '</h3>' + by[g].map(function (i) {
        return '<div class="pn-dbg-st__i is-' + esc(i.state) + '">' + lvBadge(i.state) +
          '<div><b>' + esc(i.title) + '</b>' + (i.detail ? '<div class="pn-dbg-mut">' + esc(fa(i.detail)) + '</div>' : '') + '</div></div>';
      }).join('') + '</div>';
    });

    if (R.sms && R.sms.length) {
      var pat = {};
      R.sms.forEach(function (x) { var k = x.pattern || 'متن ساده'; pat[k] = (pat[k] || 0) + 1; });
      html += '<p class="pn-hint">' + fa(R.sms.length) + ' پیامک در این آزمون ساخته شد و ارسال نشد — ' +
        Object.keys(pat).map(function (k) { return 'پترن ' + esc(fa(k)) + ' × ' + fa(pat[k]); }).join('، ') + '</p>';
    }

    html += '<p class="pn-hint">اجرا: ' + esc(R.at) + '</p>';
  }

  var hist = D.stHist && D.stHist.items ? D.stHist.items : [];

  if (hist.length) {
    html += '<h2 class="pn-dbg-h">اجراهای قبلی</h2><div class="pn-card">' + hist.slice(0, 10).map(function (h) {
      return '<div class="pn-dbg-ok">' + (h.level === 'info' ? I.check : I.alert) + '<span>' + esc(fa(h.atFa)) + ' — ' + esc(fa(h.message)) + '</span></div>';
    }).join('') + '</div>';
  }

  return html;
}

/* ─── گزارش خطا ─── */

function dbgLog() {
  var D = S.dbg;
  var L = D.log;

  if (!L) { return skel(4); }

  var st = L.settings || { log: true, verbose: false };
  var f = D.logF;

  var html = '<div class="pn-card"><div class="pn-dbg-sw">' +
    '<label class="pn-check"><input type="checkbox" id="dbg-on"' + (st.log ? ' checked' : '') + '> <b>ثبت خطاها</b></label>' +
    '<p class="pn-hint">هر خطایی که مشتری یا کاربر پنل می‌بیند، پیامکی که نرفت، درگاهی که جواب نداد و خطای جاوااسکریپت گوشی مشتری با یک «کد پیگیری» ثبت می‌شود. کد ورود، رمز و شماره‌ی کامل ذخیره نمی‌شود؛ گزارش ۱۴ روز می‌ماند.</p>' +
    '<label class="pn-check"><input type="checkbox" id="dbg-verbose"' + (st.verbose ? ' checked' : '') + '> <b>حالت عیب‌یابی</b></label>' +
    '<p class="pn-hint">کد پیگیری هر خطا به خود مشتری هم نشان داده می‌شود (تا بگوید و شما اینجا پیدایش کنید)، رویدادهای عادی هم ثبت می‌شوند و در اپ مشتری برای مدیر دکمه‌ی «عیب‌یابی» با جزئیات هر درخواست ظاهر می‌شود. بعد از رفع مشکل خاموشش کنید.</p>' +
  '</div></div>';

  if (!L.ready) {
    return html + '<div class="pn-hl pn-hl--warn">جدول گزارش هنوز ساخته نشده؛ یک بار وارد پیشخوان وردپرس شوید.</div>';
  }

  html += '<div class="pn-stats">' +
    '<div class="pn-stat' + (L.day.error ? ' pn-stat--bad' : '') + '"><div class="pn-stat__k">خطا (۲۴ ساعت)</div><div class="pn-stat__v">' + fa(L.day.error) + '</div></div>' +
    '<div class="pn-stat"><div class="pn-stat__k">توجه (۲۴ ساعت)</div><div class="pn-stat__v">' + fa(L.day.warn) + '</div></div>' +
    '<div class="pn-stat"><div class="pn-stat__k">اطلاع (۲۴ ساعت)</div><div class="pn-stat__v">' + fa(L.day.info) + '</div></div>' +
  '</div>';

  html += '<div class="pn-card"><div class="pn-filters">' +
    '<label class="pn-field"><span class="pn-field__l">سطح</span><select class="pn-in" id="dbg-lv">' +
      [['', 'همه'], ['error', 'خطا'], ['warn', 'توجه'], ['info', 'اطلاع']].map(function (o) {
        return '<option value="' + o[0] + '"' + (f.level === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
      }).join('') + '</select></label>' +
    '<label class="pn-field"><span class="pn-field__l">منبع</span><select class="pn-in" id="dbg-src">' +
      [['', 'همه']].concat(Object.keys(SRC).map(function (k) { return [k, SRC[k]]; })).map(function (o) {
        return '<option value="' + o[0] + '"' + (f.source === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
      }).join('') + '</select></label>' +
    '<label class="pn-field"><span class="pn-field__l">جستجو (کد پیگیری، شماره، متن)</span><input class="pn-in" id="dbg-q" value="' + esc(f.q) + '"></label>' +
    '<div class="pn-actions"><button class="pn-btn pn-btn--dark" data-dbg-log-go>جستجو</button>' +
      '<button class="pn-btn pn-btn--bad" data-dbg-clear>پاک کردن همه</button></div>' +
  '</div></div>';

  if (!L.items.length) {
    return html + '<div class="pn-empty"><div class="pn-empty__ic">' + I.ok + '</div><div class="pn-empty__t">موردی ثبت نشده</div>' +
      '<div class="pn-empty__s">' + (f.level || f.source || f.q ? 'با این فیلتر چیزی پیدا نشد.' : 'هنوز خطایی ثبت نشده است.') + '</div></div>';
  }

  html += '<div class="pn-dbg-log' + (S.fetching ? ' is-fetching' : '') + '">' + L.items.map(function (r) {
    var open = D.logOpen === r.id;

    return '<div class="pn-dbg-row' + (open ? ' is-open' : '') + '">' +
      '<button class="pn-dbg-row__h" data-dbg-row="' + r.id + '">' +
        lvBadge(r.level) +
        '<span class="pn-dbg-row__m"><b>' + esc(r.message || r.code) + '</b>' +
          '<span class="pn-dbg-mut">' + esc(fa(r.atFa)) + ' · ' + esc(SRC[r.source] || r.source) +
          (r.branch ? ' · ' + esc(r.branch) : '') + (r.phone ? ' · ' + esc(fa(r.phone)) : '') + '</span></span>' +
        '<code class="pn-dbg-ref" dir="ltr">' + esc(r.ref) + '</code>' +
      '</button>' +
      (open ? dbgRowDetail(r) : '') +
    '</div>';
  }).join('') + '</div>';

  if (L.pages > 1) {
    html += '<div class="pn-actions" style="justify-content:center;margin-top:12px">' +
      '<button class="pn-btn pn-btn--soft" data-dbg-page="' + (L.page - 1) + '"' + (L.page <= 1 ? ' disabled' : '') + '>قبلی</button>' +
      '<span class="pn-hint" style="margin:0 8px">صفحه‌ی ' + fa(L.page) + ' از ' + fa(L.pages) + '</span>' +
      '<button class="pn-btn pn-btn--soft" data-dbg-page="' + (L.page + 1) + '"' + (L.page >= L.pages ? ' disabled' : '') + '>بعدی</button></div>';
  }

  return html;
}

function dbgRowDetail(r) {
  return '<div class="pn-dbg-row__d">' +
    '<div class="pn-kv"><span class="pn-kv__k">کد خطا</span><span class="pn-kv__v" dir="ltr">' + esc(r.code) + (r.status ? ' (' + r.status + ')' : '') + '</span></div>' +
    (r.route ? '<div class="pn-kv"><span class="pn-kv__k">مسیر</span><span class="pn-kv__v" dir="ltr">' + esc(r.route) + '</span></div>' : '') +
    '<div class="pn-kv"><span class="pn-kv__k">زمان</span><span class="pn-kv__v" dir="ltr">' + esc(r.at) + '</span></div>' +
    (r.user ? '<div class="pn-kv"><span class="pn-kv__k">کاربر</span><span class="pn-kv__v">#' + r.user + (r.phone ? ' — ' + esc(fa(r.phone)) : '') + '</span></div>' : '') +
    (r.ip ? '<div class="pn-kv"><span class="pn-kv__k">IP</span><span class="pn-kv__v" dir="ltr">' + esc(r.ip) + '</span></div>' : '') +
    (r.ua ? '<div class="pn-kv"><span class="pn-kv__k">مرورگر</span><span class="pn-kv__v pn-dbg-ua" dir="ltr">' + esc(r.ua) + '</span></div>' : '') +
    (r.context ? '<pre class="pn-dbg-pre" dir="ltr">' + esc(JSON.stringify(r.context, null, 2)) + '</pre>' : '') +
    '<div class="pn-actions"><button class="pn-btn pn-btn--soft pn-btn--sm" data-dbg-copy-row="' + r.id + '">کپی برای پشتیبان</button></div>' +
  '</div>';
}

/* ─── اطلاعات سیستم ─── */

function dbgInfo() {
  var R = S.dbg.info;

  if (!R) { return skel(3); }

  return '<div class="pn-card">' + R.rows.map(function (r) {
    return '<div class="pn-kv"><span class="pn-kv__k">' + esc(r.label) + '</span><span class="pn-kv__v pn-dbg-info">' + esc(r.value) + '</span></div>';
  }).join('') +
  '<div class="pn-actions" style="margin-top:12px"><button class="pn-btn pn-btn--dark" data-dbg-copy-info>کپی برای پشتیبان</button></div>' +
  '<p class="pn-hint">رمز، توکن و مرچنت کد کامل در این متن نیست؛ با خیال راحت برای پشتیبان بفرستید.</p></div>';
}

/* ─── کنش‌ها ─── */

function dbgJob() {
  var D = S.dbg;

  switch (D.tab) {
    case 'sim':
      return ['panel/debug/simulate?branch_id=' + (D.simBranch || 0) + '&service_id=' + (D.simService || 0) +
        '&phone=' + encodeURIComponent(D.simPhone), function (r) { D.sim = r; D.simBusy = false; dbgSimDefaults(); }];
    case 'selftest':
      return ['panel/debug/log?source=selftest&page=1', function (r) { D.stHist = r; }];
    case 'log':
      return ['panel/debug/log?level=' + D.logF.level + '&source=' + D.logF.source + '&q=' + encodeURIComponent(D.logF.q) +
        '&page=' + D.logPage, function (r) { D.log = r; }];
    case 'info':
      return ['panel/debug/info', function (r) { D.info = r; }];
    default:
      return ['panel/debug/checks', function (r) { D.checks = r; }];
  }
}

/** شبیه‌ساز بدون انتخاب: نخستین مرکز فعال و نخستین خدمت فعالش، و نمایش. */
function dbgSimDefaults() {
  var D = S.dbg;
  var R = D.sim;

  if (!R || !R.branches || !R.branches.length || D.simBranch) { return; }

  var b = R.branches.filter(function (x) { return x.status === 'active'; })[0] || R.branches[0];
  var s = b.services.filter(function (x) { return x.active; })[0] || b.services[0];

  D.simBranch = b.id;
  D.simService = s ? s.id : 0;

  if (D.simService) { load('debug', true); }
}

function dbgData() {
  var D = S.dbg;
  return { checks: D.checks, sim: D.sim, selftest: D.stHist || {}, log: D.log, info: D.info }[D.tab];
}

function dbgTry(key) {
  var D = S.dbg;
  var p = key.split('|');

  if (D.tryBusy) { return; }

  D.tryBusy = key;
  paint();

  post('panel/debug/try', { service_id: D.simService, date: p[0], block: p[1], phone: D.simPhone }).then(function (r) {
    D.tryBusy = '';
    D.tries[key] = r.success === false ? { error: r.message } : r;
    paint();
  });
}

function dbgSelftestRun() {
  var D = S.dbg;

  if (D.stBusy) { return; }

  D.stBusy = true;
  D.st = null;
  paint();

  post('panel/debug/selftest', {}).then(function (r) {
    D.stBusy = false;
    D.st = r.success === false ? { error: r.message } : r;
    D.stHist = null;
    load('debug', true);
  });
}

function dbgSaveSettings() {
  var on = $('#dbg-on');
  var vb = $('#dbg-verbose');

  post('panel/debug/settings', { debug_log: on && on.checked ? 1 : 0, debug_mode: vb && vb.checked ? 1 : 0 }).then(function (r) {
    if (r.success === false) { toast(r.message || 'ذخیره نشد.', 'bad'); return; }
    if (S.dbg.log) { S.dbg.log.settings = r.settings; }
    toast('ذخیره شد.', 'ok');
    paint();
  });
}

function copyText(text) {
  var done = function () { toast('کپی شد.', 'ok'); };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done, function () { copyFallback(text); done(); });
    return;
  }

  copyFallback(text);
  done();
}

function copyFallback(text) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) { /* بی‌اهمیت */ }
  document.body.removeChild(ta);
}

function dbgRowText(r) {
  return [
    'کد پیگیری: ' + r.ref,
    'زمان: ' + r.at,
    'سطح: ' + r.level + ' — منبع: ' + r.source,
    'کد: ' + r.code + (r.status ? ' (' + r.status + ')' : ''),
    'پیام: ' + r.message,
    r.route ? 'مسیر: ' + r.route : '',
    r.branch ? 'شعبه: ' + r.branch : '',
    r.phone ? 'شماره: ' + r.phone : '',
    r.ua ? 'مرورگر: ' + r.ua : '',
    r.context ? 'جزئیات: ' + JSON.stringify(r.context) : ''
  ].filter(Boolean).join('\n');
}

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
    case 'refunds':  body = viewRefunds(); break;
    case 'bwallet':  body = viewBWallet(); break;
    case 'profile':  body = viewProfile(); break;
    case 'agencies': body = viewAgencies(); break;
    case 'report':   body = viewReport(); break;
    case 'smslog':   body = viewSmsLog(); break;
    case 'debug':    body = viewDebug(); break;
    default:         body = viewSummary();
  }

  /* اگر پوسته همان است، فقط بدنه عوض می‌شود. شعبه و منوی کاربر هم جزو
     پوسته‌اند (تغییر شعبه‌ی مدیر یا رسیدن خلاصه‌ی تازه). */
  var cx = ctx();
  var key = S.view + '|' + (S.menu ? '1' : '0') + '|' + (cx.branchId || 0) + '|' + nav().length +
    '|' + (cx.branch ? cx.branch.status : '');
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

  /* منو با هر تعویض بخش از نو کشیده می‌شود؛ جای پیمایشش نگه داشته
     می‌شود تا با زدن گزینه‌های پایین منو به بالا نپرد. */
  var oldNav = $('.pn-side__nav');
  var oldSide = $('.pn-side');
  var navY = oldNav ? oldNav.scrollTop : 0;
  var sideY = oldSide ? oldSide.scrollTop : 0;

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

  var navEl = $('.pn-side__nav');
  var sideEl = $('.pn-side');
  if (navEl) { navEl.scrollTop = navY; }
  if (sideEl) { sideEl.scrollTop = sideY; }
  navIntoView();

  paintToasts();
  paintModal();
}

/** گزینه‌ی فعال منو اگر بیرون از دید است، به وسط منو بیاید. */
function navIntoView() {
  var nav = $('.pn-side__nav');
  var on = $('.pn-side__b.is-on');

  if (!nav || !on || nav.scrollHeight <= nav.clientHeight) { return; }

  var n = nav.getBoundingClientRect();
  var o = on.getBoundingClientRect();

  if (o.top < n.top || o.bottom > n.bottom) {
    nav.scrollTop += (o.top - n.top) - (nav.clientHeight - o.height) / 2;
  }
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

  if (!S.modal && !S.svcEdit) { host.innerHTML = ''; if (window.CMBLocation) { window.CMBLocation.sync(); } if (window.CMBA11y) { window.CMBA11y.sync(document.getElementById('cmb-panel')); } return; }

  var focusBefore = grabFocus();
  var oldModal = host.querySelector('.pn-modal');
  var modalScroll = oldModal ? oldModal.scrollTop : 0;
  if (S.svcEdit) { readSvcForm(); }
  var m = S.modal || {};
  var inner = m.agDelete ? agDeleteModal() : S.svcEdit ? svcModal()
    : (m.install ? installModal()
    : (m.wallet ? walletModal()
    : (m.agency ? agencyModal()
    : (m.agUsers ? agUsersModal()
    : (m.agAdjust ? agAdjustModal()
    : (m.receipt ? receiptModal() : bookingModal()))))));
  host.innerHTML = '<div class="pn-scrim" data-scrim><div class="pn-modal">' + inner + '</div></div>';

  if (window.CMBLocation) { window.CMBLocation.sync(); }
  if (window.CMBA11y) { window.CMBA11y.sync(document.getElementById('cmb-panel')); }
  if (S.svcEdit) { amountWarn(); }
  var newModal = host.querySelector('.pn-modal');
  if (newModal) { newModal.scrollTop = modalScroll; }
  restoreFocus(focusBefore);
}

/* ═══ بارگذاری ═══ */

/** نشانی و جای داده‌ی هر نما. */
function jobs() {
  var f = S.filter;

  return {
    summary:  ['panel/summary', function (r) { S.summary = r; }],
    board:    ['panel/board?days=7' + (S.boardFrom ? '&from=' + S.boardFrom : ''), function (r) { S.board = r; }],
    bookings: ['panel/bookings?scope=' + f.scope + '&status=' + encodeURIComponent(f.status) +
               '&date=' + encodeURIComponent(f.date) + '&q=' + encodeURIComponent(f.q) +
               '&page=' + f.page, function (r) { S.list = r; }],
    customers: ['panel/customers?q=' + encodeURIComponent(S.custQ) + '&page=' + S.custPage + '&branch=' + encodeURIComponent(S.custBranch),
                function (r) { S.customers = r; if (r && r.branch) { S.custBranch = String(r.branch); } }],
    services: ['panel/services', function (r) {
      S.services = r.items || [];
      S.svcMeta = { catalog: !!r.catalog, center: !!r.center, canEdit: r.canEdit !== false, canAdd: r.canAdd !== false, canCustomize: !!r.canCustomize };
      openPendingSvc();
    }],
    bwallet:  ['panel/branch-wallet?page=' + S.bwPage +
               (S.btPay ? '&pay=' + encodeURIComponent(S.btPay.id) + '&tok=' + encodeURIComponent(S.btPay.tok) : ''),
               function (r) { S.bwallet = r; S.btPay = null; }],
    profile:  ['panel/branch', function (r) { S.profile = r; }],
    agencies: ['panel/admin/branches', function (r) { S.agencies = r; }],
    smslog:   ['panel/smslog?kind=' + S.slKind + '&status=' + S.slStatus + '&q=' + encodeURIComponent(S.slQ) + '&page=' + S.slPage,
               function (r) { S.smslog = r; }],
    report:   ['panel/admin/report?from=' + encodeURIComponent(S.agFrom) + '&to=' + encodeURIComponent(S.agTo),
               function (r) { S.agReport = r; }],
    closures: ['panel/closures', function (r) { S.closures = r; }],
    debug:    dbgJob(),
    settings: ['panel/settings', function (r) { S.sched = r.settings; S.schedFull = r.fullUrl || ''; S.schedGlobal = r.global !== false && !r.agency; }],
    refunds:  S.refWhich === 'review' ? ['panel/pay-review', function (r) { S.payReview = r; }]
      : (S.refWhich === 'report' ? ['panel/pay-report?days=' + S.repDays + '&sandbox=' + (S.repSandbox ? 1 : 0), function (r) { S.payReport = r; }]
      : (S.refWhich === 'wallet' ? ['panel/wallets?q=' + encodeURIComponent(S.walQ) + '&filter=' + S.walFilter + '&page=' + S.walPage, function (r) { S.wallets = r; }]
      : ['panel/refunds?which=' + S.refWhich, function (r) { S.refunds = r; }]))
  };
}

function hasData(view) {
  var m = { summary: S.summary, board: S.board, bookings: S.list, customers: S.customers,
            services: S.services, closures: S.closures, settings: S.sched,
            bwallet: S.bwallet, profile: S.profile, agencies: S.agencies, report: S.agReport, debug: dbgData(),
            refunds: S.refWhich === 'review' ? S.payReview : (S.refWhich === 'report' ? S.payReport : (S.refWhich === 'wallet' ? S.wallets : S.refunds)) };
  return !!m[view];
}

/* تا این مدت بعد از گرفتن، نما بدون درخواست تازه نشان داده می‌شود. */
var FRESH_MS = 15000;

/**
 * رفتن به یک نما: اگر داده‌اش را داریم همان لحظه نشان بده، و فقط اگر
 * کهنه شده در پس‌زمینه تازه کن. پیش‌تر هر جابه‌جایی یک رفت‌وبرگشت
 * کامل با سرور بود، حتی برای داده‌ای که چند ثانیه پیش گرفته شده بود.
 */
function show(view) {
  if (!S.can) { paint(); return; }

  var job = jobs()[view];
  var at = job ? S.got[job[0]] : 0;

  if (at && hasData(view)) {
    paint();
    if (Date.now() - at > FRESH_MS) { load(view); }
    return;
  }

  load(view);
}

function load(view, force) {
  if (!S.can) { paint(); return; }

  var job = jobs()[view];
  if (!job) { paint(); return; }

  // سلامت سیستم هم‌زمان با تنظیمات، نه بعد از آن (فقط مرکز؛ نمایندگی بخش سلامت ندارد)
  if (view === 'settings' && ctx().can.center) { loadHealth(); }

  /* داده‌ی قبلی سر جایش می‌ماند و فقط کم‌رنگ می‌شود. اسکلت فقط
     وقتی دیده می‌شود که هنوز هیچ داده‌ای نداریم — وگرنه هر
     تازه‌سازی، جدول را یک لحظه از صفحه پاک می‌کرد. */
  var epoch = dataEpoch, ticket = ++loadSequence;
  activeLoads[view] = ticket;
  S.fetching = !!activeLoads[S.view];
  paint();

  get(job[0]).then(function (r) {
    if (epoch !== dataEpoch || activeLoads[view] !== ticket) { return; }
    delete activeLoads[view];
    S.fetching = !!activeLoads[S.view];
    var currentJob = jobs()[view];
    if (!currentJob || currentJob[0] !== job[0]) { paint(); return; }

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
      S.got[job[0]] = Date.now();
    } else {
      toast('پاسخ سرور نامعتبر بود.', 'bad');
    }

    paint();
  });

  // خلاصه همیشه در پس‌زمینه تازه بماند تا شمارنده‌ی کنار منو درست باشد
  if (view !== 'summary' && !S.summary) { refreshSummary(); }
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
    case 'refunds':  return S.refWhich === 'report' ? !!r.totals : Array.isArray(r.items);
    case 'bwallet':  return !!r.summary;
    case 'profile':  return !!r.id;
    case 'agencies': return Array.isArray(r.items);
    case 'report':   return Array.isArray(r.items);
    case 'smslog':   return Array.isArray(r.items);
    case 'debug':    return true;
    default:         return true;
  }
}

function go(view) {
  if (VIEWS.indexOf(view) === -1 || !allowed(view)) { view = 'summary'; }

  S.view = view;
  S.menu = false;
  S.modal = null;
  S.svcEdit = null;

  if (history.pushState) {
    var path = BASE + (view === 'summary' ? '' : '/' + view);
    if (location.pathname !== path) { history.pushState({ view: view }, '', path); }
  }

  window.scrollTo(0, 0);
  show(view);
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

function setStatus(id, status, extra) {
  var item = S.modal && S.modal.item;
  var payBefore = item && item.pay ? item.pay.status : '';

  /* لغو نوبتی که بیعانه‌اش پرداخت شده: پنجره‌ی مبلغ بازگشتی. پیش‌فرض
     درصدِ تنظیمات است (معمولاً کل مبلغ؛ مجموعه نوبت را لغو کرده). */
  if (status === 'cancelled' && !extra && item && item.pay && item.pay.status === 'paid') {
    var pct = Math.max(0, Math.min(100, Number(PAY.shopPct)));
    S.modal.cancelPaid = true;
    S.modal.refund = pct >= 100 ? item.pay.deposit : Math.floor(item.pay.deposit * pct / 100 / 1000) * 1000;
    paintModal();
    return;
  }

  /* نوبت نمایندگی با هزینه‌ی بلوکه: مدیر سایت انتخاب می‌کند آزاد شود یا
     کسر؛ خود نمایندگی باید بداند لغوش هزینه دارد (D13). */
  if (status === 'cancelled' && !extra && item && item.fee && item.feeStatus === 'held') {
    var cx = ctx();

    if (cx.isAdmin) {
      S.modal.cancelFee = true;
      paintModal();
      return;
    }

    if (!window.confirm('با لغو این نوبت، ' + fa(money(item.fee)) + ' تومان از کیف پول شما کسر می‌شود و برای مشتری پیامک لغو می‌رود. مطمئن هستید؟')) { return; }

    extra = { fee: 'capture' };
  }

  if (status === 'delete' && !window.confirm('این نوبت برای همیشه حذف شود؟ این کار برگشت‌پذیر نیست.')) { return; }
  if (status === 'cancelled' && !extra && !window.confirm(item && item.status === 'pending'
    ? 'نوبت پرداخت‌نشده لغو شود و جایش آزاد شود؟'
    : 'با لغو نوبت، پیامک اطلاع‌رسانی برای مشتری ارسال می‌شود. مطمئن هستید؟')) { return; }

  S.busy = true;
  paintModal();

  var body = { id: id, status: status };
  Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });

  post('panel/booking', body).then(function (r) {
    S.busy = false;

    if (r.success === false) {
      // شیفت پر است: فقط با تأیید صریح، بیش از ظرفیت
      if (r.code === 'cmb_restore_full' && ctx().can.overbook && window.confirm(r.message + ' با این حال بازگردانده شود؟')) {
        setStatus(id, status, { force: 1 });
        return;
      }

      toast(r.message || 'تغییر وضعیت ناموفق بود.', 'bad');
      paintModal();
      return;
    }

    var ip = r.item && r.item.pay;

    toast(r.deleted ? 'نوبت حذف شد.'
      : ((ip && ip.status === 'refund_due' && status === 'cancelled')
        ? 'نوبت لغو شد؛ ' + ip.refundFa + ' هنوز به کیف پول مشتری نرفته (بیعانه و کیف پول ← برگشت‌های مانده).'
        : ((ip && ip.status === 'refunded' && status === 'cancelled' && String(ip.statusLabel || '').indexOf('کیف پول') !== -1)
          ? 'نوبت لغو شد؛ ' + ip.refundFa + ' به کیف پول مشتری رفت.'
          : ((payBefore === 'refunded' && status === 'confirmed')
            ? 'نوبت بازگردانده شد و مبلغ برگشتی از کیف پول مشتری پس گرفته شد.'
            : 'وضعیت نوبت به‌روز شد.'))), 'ok');

    /* تغییر همین حالا در فهرست دیده شود؛ پیش‌تر فهرست پاک می‌شد و تا
       رسیدن پاسخ دوم فقط اسکلت خالی دیده می‌شد. */
    if (S.list && S.list.items) {
      S.list.items = r.deleted
        ? S.list.items.filter(function (x) { return x.id !== id; })
        : S.list.items.map(function (x) { return (x.id === id && r.item) ? r.item : x; });
    }

    S.modal = null;
    paint();

    // کیف پول و برگشت‌های مانده ممکن است عوض شده باشند
    if (PAY.used) { S.refunds = null; S.wallets = null; delete S.got['panel/refunds?which=open']; }

    // تازه‌سازی در پس‌زمینه، بدون پاک کردن آنچه روی صفحه است
    load(S.view);
    if (S.view !== 'summary') { refreshSummary(); }
  });
}

/** شمارنده‌های خلاصه (و عدد کنار «نوبت‌ها» در منو) بی‌صدا تازه شوند. */
function refreshSummary() {
  var epoch = dataEpoch, ticket = ++loadSequence;
  activeLoads.summary = ticket;
  get('panel/summary').then(function (r) {
    if (epoch !== dataEpoch || activeLoads.summary !== ticket) { return; }
    delete activeLoads.summary;
    S.fetching = !!activeLoads[S.view];
    if (r.success !== false && looksValid('summary', r)) {
      S.summary = r;
      S.got[jobs().summary[0]] = Date.now();
      paint();
    }
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
  if (!file || S.uploading) { return; }

  if (file.size > 4 * 1024 * 1024) {
    toast('حجم تصویر باید کمتر از ۴ مگابایت باشد.', 'bad');
    return;
  }

  var fd = new FormData();
  fd.append('file', file);
  var editingService = S.svcEdit;
  if (!editingService || !editingService.id) { return; }
  fd.append('service_id', editingService.id);

  S.uploading = true;
  paintModal();

  uploadRequest(fd)
    .then(function (j) {
      S.uploading = false;

      if (!j || j.success === false || !j.posterId) {
        toast((j && j.message) || 'آپلود ناموفق بود.', 'bad');
        paintModal();
        return;
      }

      editingService.posterId = j.posterId;
      editingService.poster = j.poster || '';
      invalidateData();
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
  post('panel/service', svc.catalog
    ? { id: svc.id, price: svc.price, weekdays: svc.weekdays, active: svc.active ? 1 : 0 }
    : {
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
  if (S.busy) { return; }
  var svc = findService(id);
  if (!svc) { return; }

  svc.active = svc.active ? 0 : 1;
  paint();

  S.busy = true;
  post('panel/service', { id: svc.id, active: svc.active }).then(function (r) {
    S.busy = false;
    toast(r.success === false ? r.message : (svc.active ? 'خدمت فعال شد.' : 'خدمت غیرفعال شد.'), r.success === false ? 'bad' : 'ok');
    load('services');
  });
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
  copy.catalog = false; copy.fromCatalog = false; copy.customized = false; copy.catalogId = 0;
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
function resetService(id) {
  if (S.busy || !window.confirm('عنوان، شرح، پوستر و ظرفیت به الگوی مرکز برگردد؟ قیمت، روزها و وضعیت حفظ می‌شود.')) { return; }
  S.busy = true; paintModal();
  post('panel/service/reset', { id: id }).then(function (r) {
    S.busy = false;
    if (r.success === false) { toast(r.message, 'bad'); paintModal(); return; }
    S.svcEdit = null; S.services = null;
    toast(r.message, 'ok'); load('services');
  });
}

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

  // پنجره‌ی کاتالوگ فقط قیمت دارد
  if (s && s.catalog && $('#sv-price')) {
    s.price = Number(en(val('#sv-price')).replace(/\D/g, '') || 0);
    return;
  }

  if (!s || !$('#sv-title')) { return; }

  s.title = val('#sv-title').trim();
  s.description = val('#sv-desc').trim();
  s.price = Number(en(val('#sv-price')).replace(/\D/g, '') || 0);
  s.duration = val('#sv-dur').trim();

  if (s.ownCapacity) {
    BLOCKS.forEach(function (b) {
      var box = $('#sv-cap-' + b.key);
      if (box) { s.ownCapacity[b.key] = Math.max(0, Number(en(box.value).replace(/\D/g, '') || 0)); }
    });
  }

  if ($('#sv-dep')) { s.deposit = Number(en(val('#sv-dep')).replace(/\D/g, '') || 0); }

  if ($('#sv-ref')) {
    var rv = en(val('#sv-ref')).replace(/\D/g, '');
    s.cancelRefund = rv === '' ? null : Number(rv);
  }
}

function saveService() {
  if (S.busy || S.uploading || !S.svcEdit) { return; }
  var s = S.svcEdit;

  readSvcForm();

  if (s.catalog) {
    S.busy = true;
    paintModal();

    post('panel/service', { id: s.id, price: s.price, weekdays: s.weekdays, active: Number(s.active) === 1 ? 1 : 0 }).then(function (r) {
      S.busy = false;

      if (r.success === false) { toast(r.message || 'ذخیره ناموفق بود.', 'bad'); paintModal(); return; }

      toast('خدمت ذخیره شد.', 'ok');
      S.svcEdit = null;
      S.services = null;
      S.summary = null;
      load('services');
      refreshSummary();
    });
    return;
  }

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
    ownCapacity: s.ownCapacity || null,
    // بیعانه فقط وقتی پرداخت در کار است و نه در نمایندگی؛ نبودنِ کلید یعنی دست نزن
    deposit: PAY.used && !ctx().agency ? (typeof s.deposit === 'undefined' ? null : s.deposit) : undefined,
    cancelRefund: PAY.used && !ctx().agency ? (typeof s.cancelRefund === 'undefined' ? null : s.cancelRefund) : undefined
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

    // نمایندگی: این بازه نوبت فعال دارد؛ اول هشدار (US-2.3)
    if (r.success === false && r.code === 'cmb_closure_has_bookings') {
      if (window.confirm(r.message + ' با این حال بسته شود؟')) {
        S.closeForm.confirm = 1;
        post('panel/closure', S.closeForm).then(function (r2) {
          delete S.closeForm.confirm;
          if (r2.success === false) { toast(r2.message || 'ثبت ناموفق بود.', 'bad'); paint(); return; }
          toast('بازه بسته شد.', 'ok');
          S.closeForm = { date: '', block: '', reason: '' };
          S.closures = null;
          S.board = null;
          load('closures');
        });
        return;
      }

      paint();
      return;
    }

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

  // نام زیر منو؛ C.me مال لحظه‌ی بارگذاری صفحه بود که کسی وارد نشده بود
  if (r.name) { C.me = r.name; }
  shellKey = '';

  toast('خوش آمدید' + (r.name ? '، ' + r.name : '') + '.', 'ok');
  load(S.view);
}

/* خروج: همان مسیر خروج اپ مشتری (کوکی و نشست را پاک می‌کند). بعد
   صفحه از نو بارگذاری می‌شود تا هیچ داده‌ای از حساب قبلی در حافظه
   نماند و فرم ورود پنل بالا بیاید. لینک wp-login.php به کار نمی‌آمد:
   در اپ نصب‌شده بیرون از محدوده‌ی اپ باز می‌شد و روی بیشتر سایت‌ها
   صفحه‌ی ورود جابه‌جا شده است. */
function logout() {
  if (S.busy) { return; }
  if (!window.confirm('از حساب خارج می‌شوید. برای ورود دوباره، رمز یا کد تایید لازم است.')) { return; }

  S.busy = true;
  S.menu = false;
  paint();

  post('logout').then(function (r) {
    if (r.success === false) {
      S.busy = false;
      toast(r.message || 'خروج انجام نشد. دوباره تلاش کنید.', 'bad');
      return;
    }

    location.replace(BASE);
  });
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
  document.addEventListener('toggle', function (e) {
    if (e.target && e.target.isConnected && e.target.classList && e.target.classList.contains('pn-ag-settings')) { S.agSettingsOpen = e.target.open; }
  }, true);
  document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'ag-delete-title' && S.modal && S.modal.agDelete) {
      S.modal.confirmTitle = e.target.value;
      var delButton = $('[data-ag-delete-confirm]');
      if (delButton) { delButton.disabled = !agDeleteReady(); }
    }

    var id = e.target.id, p = {'bp-address':'address','bp-phone':'phone','bp-lat':'lat','bp-lng':'lng'};
    if (S.profile && p[id]) { S.profile[p[id]] = e.target.value; }
    if (S.modal && S.modal.agency && /^ag-/.test(id)) {
      var f = {'ag-title':'title','ag-slug':'slug','ag-sort':'sortOrder','ag-prov':'province','ag-city':'city','ag-address':'address','ag-contact':'contactName','ag-mphone':'managerPhone','ag-sms':'managerPhones','ag-lat':'lat','ag-lng':'lng'};
      if (f[id]) { S.modal.agency[f[id]] = e.target.value; }
      if ((id === 'ag-contact' || id === 'ag-title') && $('#ag-display')) { $('#ag-display').textContent = agencyDisplayName(S.modal.agency.contactName, S.modal.agency.title); }
      if (id === 'ag-policy-own') { S.modal.agency.allowOwnServices = Number(e.target.value); }
      if (id === 'ag-policy-customize') { S.modal.agency.allowCustomizeServices = Number(e.target.value); }
    }
  });
  // انتخابگر تاریخ شمسی: مقدار میلادی را در input مخفی می‌گذارد
  J.bind(function (id, g) {
    if (id === 'cl-date') { S.closeForm.date = g; }
    if (id === 'rp-from') { S.agFrom = g; }
    if (id === 'rp-to') { S.agTo = g; }

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
    // هدف رویداد در بعضی مرورگرهای گوشی گره‌ی متنی یا document است؛ نزدیک‌ترین عنصر واقعی را می‌گیریم
    while (t && t.nodeType !== 1) { t = t.parentNode; }
    if (!t) { return; }
    function up(s) { return t.closest ? t.closest(s) : null; }
    var el;

    if ((el = up('[data-view]'))) { e.preventDefault(); go(el.getAttribute('data-view')); return; }
    if (up('[data-menu]')) { e.preventDefault(); S.menu = true; paint(); return; }
    if (up('[data-menu-close]')) { e.preventDefault(); S.menu = false; paint(); return; }

    if (up('[data-login]')) { e.preventDefault(); doLogin(); return; }
    if (up('[data-logout]')) { e.preventDefault(); logout(); return; }

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

    if (up('[data-reload]')) {
      e.preventDefault();
      S[S.view] = null;
      S.summary = null;
      if (S.view === 'bwallet') { S.bwNotice = ''; }
      load(S.view);
      if (S.view !== 'summary') { refreshSummary(); }
      return;
    }

    // شماره‌ی تلفن داخل کارت نوبت تماس می‌گیرد، نه باز کردن جزئیات
    if ((el = up('[data-open]')) && !up('a[href]')) {
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
    if ((el = up('[data-sl-kind]'))) { e.preventDefault(); S.slKind = el.getAttribute('data-sl-kind'); S.slPage = 1; load('smslog'); return; }
    if (up('[data-sl-search]')) { e.preventDefault(); S.slQ = val('#sl-q').trim(); S.slPage = 1; load('smslog'); return; }
    if (up('[data-sl-clear]')) { e.preventDefault(); S.slQ = ''; S.slStatus = ''; S.slKind = ''; S.slPage = 1; load('smslog'); return; }
    if ((el = up('[data-sl-page]'))) { e.preventDefault(); S.slPage = Number(el.getAttribute('data-sl-page')) || 1; load('smslog'); return; }

    if ((el = up('[data-cust-branch]'))) {
      e.preventDefault();
      S.custBranch = el.getAttribute('data-cust-branch');
      S.custPage = 1;
      load('customers');
      return;
    }

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

    if ((el = up('[data-svc-reset]'))) { e.preventDefault(); resetService(Number(el.getAttribute('data-svc-reset'))); return; }
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

    if ((el = up('[data-svc-active]'))) {
      e.preventDefault();
      readSvcForm();
      S.svcEdit.active = Number(el.getAttribute('data-svc-active'));
      paintModal();
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

    if ((el = up('[data-dep-mode]'))) {
      e.preventDefault();
      readSvcForm();

      var dm = el.getAttribute('data-dep-mode');
      S.svcEdit.deposit = dm === 'default' ? null : (dm === 'none' ? 0 : (Number(S.svcEdit.deposit) > 0 ? S.svcEdit.deposit : (S.svcEdit.depositNow || 100000)));
      paintModal();
      return;
    }

    /* ── لغو نوبت پرداخت‌شده با مبلغ ── */

    if ((el = up('[data-refund-fill]'))) {
      e.preventDefault();
      var rb = $('#pn-refund');
      if (rb) { rb.value = el.getAttribute('data-refund-fill'); }
      return;
    }

    if (up('[data-cancel-back]')) { e.preventDefault(); S.modal.cancelPaid = false; S.modal.cancelFee = false; paintModal(); return; }

    if (up('[data-cancel-paid]')) {
      e.preventDefault();
      var amt = en(val('#pn-refund')).replace(/\D/g, '');
      if (amt === '') { toast('مبلغ بازگشتی را وارد کنید (۰ یعنی هیچ).', 'bad'); return; }
      S.modal.refund = amt;
      setStatus(S.modal.item.id, 'cancelled', { refund: Number(amt) });
      return;
    }

    /* ── آزمون و عیب‌یابی ── */

    if ((el = up('[data-dbg-tab]'))) {
      e.preventDefault();
      S.dbg.tab = el.getAttribute('data-dbg-tab');
      show('debug');
      return;
    }

    if (up('[data-dbg-reload]')) { e.preventDefault(); load('debug', true); return; }

    if ((el = up('[data-dbg-goto]'))) {
      e.preventDefault();
      var gp = el.getAttribute('data-dbg-goto').split(':');
      S.dbg.tab = 'sim';
      S.dbg.simBranch = Number(gp[0]);
      S.dbg.simService = Number(gp[1]);
      S.dbg.tries = {};
      S.dbg.simBusy = true;
      window.scrollTo(0, 0);
      load('debug', true);
      return;
    }

    if (up('[data-dbg-sim-run]')) {
      e.preventDefault();
      S.dbg.simBranch = Number(val('#dbg-br')) || S.dbg.simBranch;
      S.dbg.simService = Number(val('#dbg-svc')) || S.dbg.simService;
      S.dbg.simPhone = en(val('#dbg-phone')).replace(/\D/g, '');
      S.dbg.tries = {};
      S.dbg.simBusy = true;
      load('debug', true);
      return;
    }

    if ((el = up('[data-dbg-try]'))) { e.preventDefault(); dbgTry(el.getAttribute('data-dbg-try')); return; }
    if (up('[data-dbg-st-run]')) { e.preventDefault(); dbgSelftestRun(); return; }

    if ((el = up('[data-dbg-row]'))) {
      e.preventDefault();
      var rid = Number(el.getAttribute('data-dbg-row'));
      S.dbg.logOpen = S.dbg.logOpen === rid ? 0 : rid;
      paint();
      return;
    }

    if ((el = up('[data-dbg-copy-row]'))) {
      e.preventDefault();
      var cid = Number(el.getAttribute('data-dbg-copy-row'));
      var row = (S.dbg.log && S.dbg.log.items || []).filter(function (x) { return x.id === cid; })[0];
      if (row) { copyText(dbgRowText(row)); }
      return;
    }

    if (up('[data-dbg-copy-info]')) { e.preventDefault(); if (S.dbg.info) { copyText(S.dbg.info.text); } return; }

    if (up('[data-dbg-log-go]')) {
      e.preventDefault();
      S.dbg.logF.q = val('#dbg-q').trim();
      S.dbg.logPage = 1;
      load('debug', true);
      return;
    }

    if ((el = up('[data-dbg-page]'))) {
      e.preventDefault();
      S.dbg.logPage = Math.max(1, Number(el.getAttribute('data-dbg-page')));
      load('debug', true);
      return;
    }

    if (up('[data-dbg-clear]')) {
      e.preventDefault();
      if (!window.confirm('همه‌ی گزارش خطا پاک شود؟ این کار برگشت ندارد.')) { return; }
      post('panel/debug/log/clear', {}).then(function (r) {
        toast(r.message || 'پاک شد.', r.success === false ? 'bad' : 'ok');
        S.dbg.log = null;
        load('debug', true);
      });
      return;
    }

    /* ── بیعانه و کیف پول ── */

    if ((el = up('[data-ref-which]'))) {
      e.preventDefault();
      S.refWhich = el.getAttribute('data-ref-which');
      if (S.refWhich === 'open') { S.refunds = null; }
      S.refEdit = null;
      load('refunds');
      return;
    }

    if ((el = up('[data-sim]'))) {
      e.preventDefault();
      var sid = Number(el.getAttribute('data-sim'));
      S.simFor = S.simFor === sid ? 0 : sid;
      paint();
      return;
    }

    if ((el = up('[data-svc-goto]'))) {
      e.preventDefault();
      S.openSvc = Number(el.getAttribute('data-svc-goto'));
      go('services');
      openPendingSvc();
      return;
    }

    if ((el = up('[data-rep-days]'))) {
      e.preventDefault();
      S.repDays = Number(el.getAttribute('data-rep-days'));
      S.payReport = null;
      load('refunds');
      return;
    }

    if ((el = up('[data-ref-wallet]'))) { e.preventDefault(); refundAction(Number(el.getAttribute('data-ref-wallet')), 'wallet'); return; }

    /* ── کیف پول ── */

    if ((el = up('[data-wal-open]'))) { e.preventDefault(); openWallet(el.getAttribute('data-wal-open')); return; }

    if ((el = up('[data-wal-dir]'))) {
      e.preventDefault();
      S.modal.amount = en(val('#wl-amt')).replace(/\D/g, '');
      S.modal.note = val('#wl-note');
      S.modal.dir = el.getAttribute('data-wal-dir');
      paintModal();
      return;
    }

    if (up('[data-wal-adjust]')) { e.preventDefault(); walletAdjust(); return; }

    if (up('[data-wal-search]')) {
      e.preventDefault();
      S.walQ = $('#wl-q') ? val('#wl-q').trim() : '';
      S.walPage = 1;
      load('refunds');
      return;
    }

    if ((el = up('[data-wal-filter]'))) {
      e.preventDefault();
      S.walFilter = el.getAttribute('data-wal-filter');
      S.walPage = 1;
      S.wallets = null;
      load('refunds');
      return;
    }

    if ((el = up('[data-wal-page]'))) {
      e.preventDefault();
      S.walPage = Number(el.getAttribute('data-wal-page'));
      load('refunds');
      return;
    }

    if ((el = up('[data-ref-done]'))) { e.preventDefault(); S.refEdit = { id: Number(el.getAttribute('data-ref-done')), mode: 'done' }; paint(); return; }
    if (up('[data-ref-cancel]')) { e.preventDefault(); S.refEdit = null; paint(); return; }
    if ((el = up('[data-ref-done-yes]'))) { e.preventDefault(); refundAction(Number(el.getAttribute('data-ref-done-yes')), 'done'); return; }

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


    /* ── نمایندگی: لغو با انتخاب هزینه، برگشت هزینه ── */

    if ((el = up('[data-cancel-fee]'))) {
      e.preventDefault();
      setStatus(S.modal.item.id, 'cancelled', { fee: el.getAttribute('data-cancel-fee') });
      return;
    }

    if ((el = up('[data-fee-refund]'))) { e.preventDefault(); feeRefund(Number(el.getAttribute('data-fee-refund'))); return; }

    /* ── کیف پول نمایندگی ── */

    if (up('[data-bw-focus]')) {
      e.preventDefault();
      var amtBox = $('#bw-amt');
      if (amtBox) { amtBox.scrollIntoView({ block: 'center' }); amtBox.focus(); }
      return;
    }

    if ((el = up('[data-bw-amt]'))) {
      e.preventDefault();
      S.bwAmount = el.getAttribute('data-bw-amt');
      var ba = $('#bw-amt');
      if (ba) { ba.value = S.bwAmount; }
      $$('[data-bw-amt]').forEach(function (x) { x.classList.toggle('is-on', x === el); });
      return;
    }

    if (up('[data-bw-pay]')) { e.preventDefault(); startTopup(); return; }

    if ((el = up('[data-bw-page]'))) {
      e.preventDefault();
      S.bwPage = Math.max(1, Number(el.getAttribute('data-bw-page')));
      load('bwallet');
      return;
    }

    if ((el = up('[data-bw-receipt]'))) {
      e.preventDefault();
      get('panel/branch-wallet/receipt?id=' + Number(el.getAttribute('data-bw-receipt'))).then(function (r) {
        if (r.success === false) { toast(r.message || 'رسید پیدا نشد.', 'bad'); return; }
        S.modal = { receipt: r };
        paintModal();
      });
      return;
    }

    if (up('[data-bw-print]')) { e.preventDefault(); printReceipt(); return; }

    /* ── پروفایل نمایندگی ── */

    if (up('[data-bp-save]')) { e.preventDefault(); saveProfile(); return; }
    if (up('[data-bp-geo]')) { e.preventDefault(); profileGeo(); return; }
    if (up('[data-bp-photo-clear]')) { e.preventDefault(); saveProfile({ photoId: 0 }); return; }
    if (up('[data-ag-photo-clear]') && S.modal && S.modal.agency) { e.preventDefault(); S.modal.agency.photoId = 0; S.modal.agency.photo = S.modal.agency.autoPhoto || ''; paintModal(); return; }

    /* ── مدیر سایت: نمایندگی‌ها ── */

    if (up('[data-ag-new]')) {
      e.preventDefault();
      S.modal = { agency: { id: 0, title: '', slug: '', province: '', city: '', address: '', phone: '', contactName: '', managerPhone: '', managerPhones: '', lat: '', lng: '', sortOrder: 0 } };
      paintModal();
      return;
    }

    if ((el = up('[data-ag-edit]'))) {
      e.preventDefault();
      var ag = findAgency(Number(el.getAttribute('data-ag-edit')));
      if (ag) { S.modal = { agency: JSON.parse(JSON.stringify(ag)) }; paintModal(); }
      return;
    }

    if ((el = up('[data-ag-delete]'))) {
      e.preventDefault();
      var deleting = findAgency(Number(el.getAttribute('data-ag-delete')));
      if (deleting && deleting.type === 'agency') { agDeleteOpen(deleting); }
      return;
    }
    if (up('[data-ag-delete-confirm]')) { e.preventDefault(); deleteAgency(); return; }
    if (up('[data-ag-save]')) { e.preventDefault(); saveAgency(); return; }

    if ((el = up('[data-ag-status]'))) {
      e.preventDefault();
      var as = el.getAttribute('data-ag-status').split(':');
      agStatus(Number(as[0]), as[1], false);
      return;
    }

    if ((el = up('[data-ag-users]'))) {
      e.preventDefault();
      var au = findAgency(Number(el.getAttribute('data-ag-users')));
      if (au) { S.modal = { agUsers: JSON.parse(JSON.stringify(au)) }; paintModal(); }
      return;
    }

    if (up('[data-ag-user-add]')) {
      e.preventDefault();
      agUsers('add', { phone: en(val('#agu-phone')).trim(), name: val('#agu-name').trim(), role: val('#agu-role') });
      return;
    }

    if ((el = up('[data-ag-user-role]'))) {
      e.preventDefault();
      var ur = el.getAttribute('data-ag-user-role').split(':');
      if (!el.classList.contains('is-on')) { agUsers('role', { userId: Number(ur[0]), role: ur[1] }); }
      return;
    }

    if ((el = up('[data-ag-user-del]'))) {
      e.preventDefault();
      if (window.confirm('دسترسی پنل این کاربر برداشته شود؟ حساب و نوبت‌های مشتری‌اش سر جایش می‌ماند.')) {
        agUsers('remove', { userId: Number(el.getAttribute('data-ag-user-del')) });
      }
      return;
    }

    if ((el = up('[data-ag-adjust]'))) {
      e.preventDefault();
      var aj = findAgency(Number(el.getAttribute('data-ag-adjust')));
      if (aj) { S.modal = { agAdjust: aj, dir: 'inc', amount: '', note: '' }; paintModal(); }
      return;
    }

    if ((el = up('[data-ag-dir]'))) {
      e.preventDefault();
      S.modal.amount = en(val('#aga-amt')).replace(/\D/g, '');
      S.modal.note = val('#aga-note');
      S.modal.dir = el.getAttribute('data-ag-dir');
      paintModal();
      return;
    }

    if (up('[data-ag-adjust-save]')) { e.preventDefault(); agAdjust(); return; }
    if (up('[data-ag-fees]')) { e.preventDefault(); saveFees(); return; }

    if ((el = up('[data-ag-enter]'))) { e.preventDefault(); switchBranch(el.getAttribute('data-ag-enter'), 'summary'); return; }

    /* ── گزارش نمایندگی‌ها ── */

    if (up('[data-agrep-apply]')) {
      e.preventDefault();
      if ($('#rp-from')) { S.agFrom = val('#rp-from'); }
      if ($('#rp-to')) { S.agTo = val('#rp-to'); }
      S.agReport = null;
      load('report');
      return;
    }

    if (up('[data-agrep-csv]')) { e.preventDefault(); exportAgReport(); return; }

    if (up('[data-board-prev]')) { e.preventDefault(); shiftBoard(-7); return; }
    if (up('[data-board-next]')) { e.preventDefault(); shiftBoard(7); return; }
    if (up('[data-board-today]')) { e.preventDefault(); S.boardFrom = ''; S.board = null; load('board'); return; }
  });

  /* جستجوی زنده: نتیجه حین تایپ می‌آید و لازم نیست کاربر دکمه بزند.
     ۳۵۰ میلی‌ثانیه صبر می‌کنیم تا هر حرف یک درخواست نشود. */
  var typeTimer = 0;

  document.addEventListener('input', function (e) {
    // هشدار زنده‌ی مبلغ بیعانه و برگشت در ویرایشگر خدمت
    if (e.target && (e.target.id === 'sv-dep' || e.target.id === 'sv-ref')) { amountWarn(); }

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

    if (e.target && e.target.id === 'bp-photo' && e.target.files && e.target.files[0]) {
      uploadBranchPhoto(e.target.files[0]);
    }

    // استان عوض شد: شهرهای همان استان؛ «شهر دیگر»: کادر تایپ
    if (e.target && S.modal && S.modal.agency && (e.target.id === 'ag-prov' || e.target.id === 'ag-city-sel')) {
      var ag = S.modal.agency;
      if (e.target.id === 'ag-prov') { ag.province = e.target.value; ag.city = ''; ag.cityOther = false; }
      else { ag.cityOther = e.target.value === '__other__'; ag.city = ag.cityOther ? '' : e.target.value; }
      paintModal();
      if (ag.cityOther && $('#ag-city')) { $('#ag-city').focus(); }
    }

    // فرم ساخت/ویرایش نمایندگی (ادمین): عکس با همان فرم ذخیره می‌شود (US-1.1)
    if (e.target && e.target.id === 'ag-photo' && e.target.files && e.target.files[0] && S.modal && S.modal.agency) {
      var editingAgency = S.modal.agency;
      uploadBranchPhoto(e.target.files[0], function (j) {
        editingAgency.photoId = j.posterId;
        editingAgency.photo = j.poster || '';
        paintModal();
      });
    }

    if (e.target && e.target.id === 'ag-del-cancel' && S.modal && S.modal.agDelete) {
      S.modal.cancelFuture = !!e.target.checked;
      var db = $('[data-ag-delete-confirm]');
      if (db) { db.disabled = !agDeleteReady(); }
    }

    if (e.target && e.target.id === 'sl-status') { S.slStatus = e.target.value; S.slPage = 1; load('smslog'); }

    // مدیر سایت: نمایش پنل یک شعبه‌ی دیگر
    if (e.target && e.target.id === 'pn-branch') {
      S.menu = false;
      switchBranch(e.target.value);
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

    /* آزمون و عیب‌یابی: انتخاب مرکز/خدمت شبیه‌ساز، فیلتر و تنظیم گزارش خطا */
    if (e.target && e.target.id === 'dbg-br') {
      var nb = Number(e.target.value);
      var bl = (S.dbg.sim && S.dbg.sim.branches || []).filter(function (x) { return x.id === nb; })[0];
      var ns = bl ? (bl.services.filter(function (x) { return x.active; })[0] || bl.services[0]) : null;
      S.dbg.simBranch = nb;
      S.dbg.simService = ns ? ns.id : 0;
      S.dbg.simPhone = en(val('#dbg-phone')).replace(/\D/g, '');
      S.dbg.tries = {};
      S.dbg.simBusy = true;
      load('debug', true);
    }

    if (e.target && e.target.id === 'dbg-svc') {
      S.dbg.simService = Number(e.target.value);
      S.dbg.simPhone = en(val('#dbg-phone')).replace(/\D/g, '');
      S.dbg.tries = {};
      S.dbg.simBusy = true;
      load('debug', true);
    }

    if (e.target && (e.target.id === 'dbg-lv' || e.target.id === 'dbg-src')) {
      S.dbg.logF.level = val('#dbg-lv');
      S.dbg.logF.source = val('#dbg-src');
      S.dbg.logPage = 1;
      load('debug', true);
    }

    if (e.target && (e.target.id === 'dbg-on' || e.target.id === 'dbg-verbose')) { dbgSaveSettings(); }

    if (e.target && e.target.id === 'rep-sandbox') {
      S.repSandbox = !!e.target.checked;
      S.payReport = null;
      load('refunds');
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

    // کارت نوبت (گوشی) با صفحه‌کلید هم باز می‌شود
    if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.classList && e.target.classList.contains('pn-bkc')) {
      e.preventDefault();
      e.target.click();
      return;
    }

    if (e.key === 'Enter' && e.target && e.target.id === 'sl-q') {
      e.preventDefault();
      S.slQ = e.target.value.trim(); S.slPage = 1;
      load('smslog');
      return;
    }

    if (e.key === 'Enter' && e.target && e.target.id === 'dbg-q') {
      e.preventDefault();
      S.dbg.logF.q = e.target.value.trim();
      S.dbg.logPage = 1;
      load('debug', true);
      return;
    }

    if (e.key === 'Enter' && e.target && e.target.id === 'dbg-phone') {
      e.preventDefault();
      var rb = $('[data-dbg-sim-run]');
      if (rb) { rb.click(); }
      return;
    }
    if (e.key === 'Enter' && $('#lg-phone')) { e.preventDefault(); otpSend(); return; }
    if (e.key === 'Enter' && $('#lg-code')) { e.preventDefault(); otpVerify(); return; }

    if (e.key === 'Enter' && $('#wl-q') && document.activeElement === $('#wl-q')) {
      e.preventDefault();
      S.walQ = val('#wl-q').trim();
      S.walPage = 1;
      load('refunds');
      return;
    }

    if (e.key === 'Enter' && $('#pn-q') && document.activeElement === $('#pn-q')) {
      e.preventDefault();
      grabFilters();
      S.filter.page = 1;
      S.list = null;
      load('bookings');
    }
  });

  window.addEventListener('popstate', function () {
    S.view = readRoute();
    if (!allowed(S.view)) { S.view = 'summary'; }
    show(S.view);
  });
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

/* خطای جاوااسکریپت پنل در «گزارش خطا» (حداکثر پنج مورد در هر بار باز شدن). */
function watchErrors() {
  var sent = 0;
  var send = function (msg, extra) {
    if (!(C.dbg && C.dbg.log) || sent >= 5) { return; }
    sent++;
    try {
      fetch(ROOT + 'log', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', keepalive: true,
        body: JSON.stringify({ source: 'panel', kind: 'js', message: String(msg).slice(0, 500), url: location.href, ver: C.ver || '',
          where: extra.where || '', stack: extra.stack || '', state: { view: S.view } })
      }).catch(function () {});
    } catch (e) { /* بی‌اهمیت */ }
  };

  window.addEventListener('error', function (e) {
    var file = e.filename || '';
    if (!e.message || /^Script error\.?$/.test(e.message) || /^(chrome|moz|safari)-extension:/.test(file)) { return; }
    send(e.message, { where: file + ':' + e.lineno + ':' + e.colno, stack: e.error && e.error.stack ? String(e.error.stack).slice(0, 1500) : '' });
  });

  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    send('Unhandled promise: ' + (r && r.message ? r.message : String(r)), { stack: r && r.stack ? String(r.stack).slice(0, 1500) : '' });
  });
}

function boot() {
  watchErrors();
  S.view = readRoute();

  /* بازگشت از درگاه شارژ کیف پول نمایندگی: نتیجه در همان تب کیف پول؛
     نشانیِ توکن‌دار از نوار آدرس پاک می‌شود. */
  if (C.btopup) {
    if (C.btopup.id === 'link') {
      S.bwNotice = 'نشانی بازگشت از درگاه معتبر نبود. اگر مبلغی از حسابتان کم شده، چند دقیقه‌ی دیگر خودکار بررسی و به کیف پول اضافه می‌شود.';
    } else {
      S.btPay = { id: C.btopup.id, tok: C.btopup.tok };
    }

    S.view = 'bwallet';

    if (history.replaceState) { history.replaceState({ view: 'bwallet' }, '', BASE + '/bwallet'); }
  }

  if (S.can && !allowed(S.view)) { S.view = 'summary'; }

  /* خلاصه، تخته و نوبت‌های پیش‌رو همین حالا همراه صفحه رسیده‌اند؛
     درخواست دوباره‌شان فقط یک رفت‌وبرگشت بی‌حاصل بود. */
  var now = Date.now(), J0 = jobs();
  if (S.summary) { S.got[J0.summary[0]] = now; }
  if (C.preload && C.preload.board && S.board) { S.got[J0.board[0]] = now; }
  if (C.preload && C.preload.bookings && S.list) { S.got[J0.bookings[0]] = now; }

  bind();
  show(S.view);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

})();
