/* ═══════════════════════════════════════════════════════
   چک موتور — تقویم شمسی

   یک کتابخانه‌ی کوچک و بدون وابستگی برای تبدیل تاریخ و یک
   انتخابگر تاریخ شمسی که جای <input type="date"> می‌نشیند.

   ورودی/خروجی به سرور همیشه میلادی (YYYY-MM-DD) می‌ماند —
   فقط چیزی که کاربر می‌بیند شمسی است. این‌طور نه دیتابیس
   عوض می‌شود نه منطق موجود.
   ═══════════════════════════════════════════════════════ */
(function (root) {
'use strict';

var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
              'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

// هفته از شنبه شروع می‌شود
var WEEK = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

function div(a, b) { return Math.floor(a / b); }

/** میلادی → شمسی. ورودی: اعداد. خروجی: [jy, jm, jd] */
function toJalali(gy, gm, gd) {
  var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
  var gy2 = (gm > 2) ? gy + 1 : gy;
  var days = 355666 + 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) +
             div(gy2 + 399, 400) + gd + g_d_m[gm - 1];

  var jy = -1595 + 33 * div(days, 12053);
  days %= 12053;

  jy += 4 * div(days, 1461);
  days %= 1461;

  if (days > 365) {
    jy += div(days - 1, 365);
    days = (days - 1) % 365;
  }

  var jm, jd;

  if (days < 186) {
    jm = 1 + div(days, 31);
    jd = 1 + (days % 31);
  } else {
    jm = 7 + div(days - 186, 30);
    jd = 1 + ((days - 186) % 30);
  }

  return [jy, jm, jd];
}

/** شمسی → میلادی. خروجی: [gy, gm, gd] */
function toGregorian(jy, jm, jd) {
  jy += 1595;

  var days = -355668 + 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd +
             ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);

  var gy = 400 * div(days, 146097);
  days %= 146097;

  if (days > 36524) {
    gy += 100 * div(--days, 36524);
    days %= 36524;
    if (days >= 365) { days++; }
  }

  gy += 4 * div(days, 1461);
  days %= 1461;

  if (days > 365) {
    gy += div(days - 1, 365);
    days = (days - 1) % 365;
  }

  var gd = days + 1;
  var sal_a = [0, 31, (isLeapG(gy) ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  var gm;

  for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) { gd -= sal_a[gm]; }

  return [gy, gm, gd];
}

function isLeapG(gy) {
  return (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
}

/** آیا سال شمسی کبیسه است؟ */
function isLeapJ(jy) {
  var mod = jy % 33;
  return [1, 5, 9, 13, 17, 22, 26, 30].indexOf(mod) !== -1;
}

/** تعداد روزهای ماه شمسی */
function monthLen(jy, jm) {
  if (jm <= 6) { return 31; }
  if (jm <= 11) { return 30; }
  return isLeapJ(jy) ? 30 : 29;
}

/* ─── تبدیل رشته ─── */

/** '2026-08-24' → [1405, 6, 2] */
function parseG(s) {
  var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
  if (!m) { return null; }
  return toJalali(+m[1], +m[2], +m[3]);
}

/** [1405,6,2] → '2026-08-24' */
function formatG(jy, jm, jd) {
  var g = toGregorian(jy, jm, jd);
  return g[0] + '-' + pad(g[1]) + '-' + pad(g[2]);
}

function pad(n) { return (n < 10 ? '0' : '') + n; }

/**
 * '2026-08-24' → '۲ شهریور ۱۴۰۵'
 *
 * @param style 'long' (پیش‌فرض) | 'short' (۲ شهریور) | 'numeric' (۱۴۰۵/۰۶/۰۲)
 */
function format(s, style, faDigits) {
  var j = parseG(s);
  if (!j) { return ''; }

  var out;

  if (style === 'numeric') {
    out = j[0] + '/' + pad(j[1]) + '/' + pad(j[2]);
  } else if (style === 'short') {
    out = j[2] + ' ' + MONTHS[j[1] - 1];
  } else {
    out = j[2] + ' ' + MONTHS[j[1] - 1] + ' ' + j[0];
  }

  return faDigits === false ? out : faNum(out);
}

/** تاریخ+ساعت: '2026-08-20 11:04:00' → '۳۰ مرداد ۱۴۰۵ — ۱۱:۰۴' */
function formatTime(s, faDigits) {
  var str = String(s || '');
  var date = format(str, 'long', faDigits);

  if (!date) { return ''; }

  var t = /(\d{2}):(\d{2})/.exec(str);

  if (!t) { return date; }

  var time = t[1] + ':' + t[2];

  return date + ' — ' + (faDigits === false ? time : faNum(time));
}

function faNum(v) {
  return String(v).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
}

/** امروز به میلادی */
function todayG() {
  var d = new Date();
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

/* ═══════════════════════════════════════════════════════
   انتخابگر
   ═══════════════════════════════════════════════════════ */

/**
 * ساخت HTML یک فیلد تاریخ شمسی.
 *
 * مقدار واقعی داخل یک input مخفی به‌صورت میلادی نگه داشته
 * می‌شود تا بقیه‌ی کد دست‌نخورده بماند.
 *
 * @param id    شناسه‌ی input مخفی (همان چیزی که کد قبلی می‌خواند)
 * @param value مقدار میلادی فعلی
 * @param opts  { min: '2026-08-24', placeholder: '…' }
 */
function fieldHTML(id, value, opts) {
  opts = opts || {};

  var label = value ? format(value, 'long') : '';

  return '<div class="jd" data-jd="' + id + '"' +
      (opts.min ? ' data-jd-min="' + opts.min + '"' : '') + '>' +
    '<input type="hidden" id="' + id + '" value="' + (value || '') + '">' +
    '<button type="button" class="pn-in jd__btn" data-jd-open>' +
      '<span class="jd__val' + (label ? '' : ' is-empty') + '">' +
        (label || (opts.placeholder || 'انتخاب تاریخ')) + '</span>' +
      (value ? '<span class="jd__x" data-jd-clear>×</span>' : '') +
    '</button>' +
    '<div class="jd__pop" hidden></div>' +
  '</div>';
}

/** رندر شبکه‌ی یک ماه */
function monthHTML(jy, jm, selected, min) {
  var head = '<div class="jd__hd">' +
    '<button type="button" class="jd__nav" data-jd-move="-1">‹</button>' +
    '<div class="jd__title">' + faNum(MONTHS[jm - 1] + ' ' + jy) + '</div>' +
    '<button type="button" class="jd__nav" data-jd-move="1">›</button>' +
    '</div>';

  var week = '<div class="jd__week">' +
    WEEK.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>';

  // روز هفته‌ی اول ماه؛ شنبه = ۰
  var first = toGregorian(jy, jm, 1);
  var dow = new Date(first[0], first[1] - 1, first[2]).getDay(); // یکشنبه=۰
  var offset = (dow + 1) % 7;

  var cells = '';

  for (var i = 0; i < offset; i++) { cells += '<span class="jd__pad"></span>'; }

  var len = monthLen(jy, jm);
  var today = todayG();

  for (var d = 1; d <= len; d++) {
    var g = formatG(jy, jm, d);
    var cls = 'jd__day';

    if (g === selected) { cls += ' is-on'; }
    if (g === today) { cls += ' is-today'; }

    var disabled = min && g < min;
    if (disabled) { cls += ' is-off'; }

    cells += '<button type="button" class="' + cls + '" data-jd-pick="' + g + '"' +
      (disabled ? ' disabled' : '') + '>' + faNum(d) + '</button>';
  }

  var foot = '<div class="jd__foot">' +
    '<button type="button" class="jd__today" data-jd-pick="' + today + '">امروز</button>' +
    '</div>';

  return head + week + '<div class="jd__grid">' + cells + '</div>' + foot;
}

/**
 * فعال‌سازی. یک بار روی document صدا زده می‌شود و همه‌ی
 * فیلدهای .jd را — حتی آن‌هایی که بعداً ساخته می‌شوند — می‌گرداند.
 */
function bind(onChange) {
  document.addEventListener('click', function (e) {
    var t = e.target;
    var wrap = t.closest ? t.closest('.jd') : null;

    // کلیک بیرون: همه‌ی پاپ‌آپ‌ها بسته شوند
    if (!wrap) { closeAll(); return; }

    var pop = wrap.querySelector('.jd__pop');
    var hidden = wrap.querySelector('input[type=hidden]');

    if (t.closest('[data-jd-clear]')) {
      e.preventDefault();
      e.stopPropagation();
      setValue(wrap, '');
      if (onChange) { onChange(hidden.id, ''); }
      return;
    }

    if (t.closest('[data-jd-open]')) {
      e.preventDefault();

      if (!pop.hidden) { pop.hidden = true; return; }

      closeAll();

      var cur = hidden.value || todayG();
      var j = parseG(cur);

      pop.dataset.y = j[0];
      pop.dataset.m = j[1];
      pop.innerHTML = monthHTML(j[0], j[1], hidden.value, wrap.dataset.jdMin || '');
      pop.hidden = false;
      return;
    }

    var mv = t.closest('[data-jd-move]');

    if (mv) {
      e.preventDefault();

      var y = +pop.dataset.y;
      var m = +pop.dataset.m + (+mv.getAttribute('data-jd-move'));

      if (m < 1) { m = 12; y--; }
      if (m > 12) { m = 1; y++; }

      pop.dataset.y = y;
      pop.dataset.m = m;
      pop.innerHTML = monthHTML(y, m, hidden.value, wrap.dataset.jdMin || '');
      return;
    }

    var pick = t.closest('[data-jd-pick]');

    if (pick) {
      e.preventDefault();

      var g = pick.getAttribute('data-jd-pick');
      setValue(wrap, g);
      pop.hidden = true;

      if (onChange) { onChange(hidden.id, g); }
    }
  });
}

function setValue(wrap, g) {
  var hidden = wrap.querySelector('input[type=hidden]');
  var val = wrap.querySelector('.jd__val');

  hidden.value = g;
  val.textContent = g ? format(g, 'long') : 'انتخاب تاریخ';
  val.classList.toggle('is-empty', !g);

  var x = wrap.querySelector('.jd__x');

  if (g && !x) {
    var b = document.createElement('span');
    b.className = 'jd__x';
    b.setAttribute('data-jd-clear', '');
    b.textContent = '×';
    wrap.querySelector('.jd__btn').appendChild(b);
  } else if (!g && x) {
    x.remove();
  }
}

function closeAll() {
  var pops = document.querySelectorAll('.jd__pop');

  for (var i = 0; i < pops.length; i++) { pops[i].hidden = true; }
}

/* ─── خروجی ─── */

root.CMBJalali = {
  toJalali: toJalali,
  toGregorian: toGregorian,
  format: format,
  formatTime: formatTime,
  fieldHTML: fieldHTML,
  bind: bind,
  todayG: todayG,
  months: MONTHS,
  monthLen: monthLen,
  isLeap: isLeapJ
};

})(typeof window !== 'undefined' ? window : this);
