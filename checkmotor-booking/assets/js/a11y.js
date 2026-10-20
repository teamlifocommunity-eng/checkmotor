/* Keyboard support for the isolated booking app and management panel. */
(function () {
'use strict';
var active = null, previous = null;
var selector = 'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]';
function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }
function sync(root) {
  if (!root) { return; }
  root.querySelectorAll('svg').forEach(function (el) { el.setAttribute('aria-hidden', 'true'); el.setAttribute('focusable', 'false'); });
  root.querySelectorAll('.cmb-top__t').forEach(function (el) { el.setAttribute('role','heading'); el.setAttribute('aria-level','1'); });
  root.querySelectorAll('[data-tab],.pn-side__b[data-view]').forEach(function (el) { if (el.classList.contains('is-on')) { el.setAttribute('aria-current','page'); } else { el.removeAttribute('aria-current'); } });
  root.querySelectorAll('.pn-modal__x,.cmb-lightbox__x,.cmb-top__back').forEach(function (el) { if (!el.hasAttribute('aria-label')) { el.setAttribute('aria-label',el.classList.contains('cmb-top__back') ? 'بازگشت' : 'بستن پنجره'); } });
  root.querySelectorAll('#pn-toasts,#cmb-toasts').forEach(function (el) { el.setAttribute('role','status'); el.setAttribute('aria-live','polite'); el.setAttribute('aria-atomic','true'); });
  root.querySelectorAll('button').forEach(function (el) { if (!el.getAttribute('type')) { el.setAttribute('type','button'); } });
  root.querySelectorAll('.cmb-field input,.cmb-field select').forEach(function (el) {
    var label = el.closest('label');
    if (label && label.querySelector('i')) { el.setAttribute('aria-required','true'); }
    if (el.classList.contains('is-bad')) {
      el.setAttribute('aria-invalid','true');
      var hint = document.createElement('span'); hint.className = 'cmb-field-error'; hint.id = el.id + '-error';
      hint.textContent = 'لطفاً ' + (label.querySelector('.cmb-field__l') ? label.querySelector('.cmb-field__l').textContent.replace('*','').trim() : 'این فیلد') + ' را به‌درستی وارد کنید.';
      label.appendChild(hint); el.setAttribute('aria-describedby', hint.id);
    }
  });
  root.querySelectorAll('#cmb-otp input,#lg-code').forEach(function (el,index) { el.setAttribute('aria-label',el.id === 'lg-code' ? 'کد تأیید پیامکی' : 'رقم ' + (index + 1) + ' کد تأیید'); });
  var dialog = root.querySelector('.pn-modal,.cmb-lightbox,.cmb-sheet');
  var main = root.querySelector('.pn-main'), side = root.querySelector('.pn-side');
  var drawer = !dialog && root.querySelector('.pn.is-menu') && window.matchMedia('(max-width:980px)').matches;
  if (drawer) { dialog = side; }
  if (main) { main.inert = !!dialog; }
  if (side) { side.inert = !!dialog && dialog !== side; if (dialog !== side) { side.removeAttribute('role'); side.removeAttribute('aria-modal'); } }
  var shell = root.querySelector('.cmb-shell'), nav = root.querySelector('.cmb-nav');
  if (shell) { shell.inert = !!dialog; }
  if (nav) { nav.inert = !!dialog; }
  if (dialog) {
    dialog.setAttribute('role','dialog'); dialog.setAttribute('aria-modal','true');
    var title = dialog.querySelector('.pn-modal__t,.cmb-sheet__t');
    dialog.setAttribute('aria-label',drawer ? 'منوی پنل' : title ? title.textContent : dialog.classList.contains('cmb-lightbox') ? 'نمایش تصویر' : 'جزئیات');
    if (active !== dialog && !dialog.contains(document.activeElement)) {
      var focus = Array.prototype.filter.call(dialog.querySelectorAll(selector),visible)[0];
      if (focus) { focus.focus({preventScroll:true}); }
    }
  } else if (active && previous && document.documentElement.contains(previous)) { previous.focus({preventScroll:true}); }
  active = dialog;
}
document.addEventListener('focusin',function (e) { if (!e.target.closest('.pn-modal,.cmb-sheet,.cmb-lightbox,.pn.is-menu .pn-side')) { previous = e.target; } });
document.addEventListener('keydown',function (e) {
  if (e.key !== 'Tab' || !active || !document.documentElement.contains(active)) { return; }
  var list = Array.prototype.filter.call(active.querySelectorAll(selector),visible);
  if (!list.length) { e.preventDefault(); return; }
  var first = list[0], last = list[list.length - 1];
  if (e.shiftKey && (document.activeElement === first || !active.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && (document.activeElement === last || !active.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
});
window.CMBA11y = {sync:sync};
})();
