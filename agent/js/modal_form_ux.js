/* Shared form UX for ajax-modal dialogs (agent/modals/*).
 * Loaded by a modal with <script src="/agent/js/modal_form_ux.js"> (ajax_modal.js re-executes it on every open).
 *  1. Required fields whose label has no marker get a red asterisk (and aria-required).
 *  2. Native "required" validation is replaced by inline messages: the offending field gets .is-invalid and a
 *     message under it, and if it sits in a hidden tab the tab is opened and flagged with a red dot (a required
 *     field in a hidden tab, or a hidden TomSelect, used to block submit without any visible reason).
 *  3. Messages clear as soon as the user fixes the field.
 */
(function () {
  'use strict';

  if (!document.getElementById('mfux-style')) {
    var st = document.createElement('style');
    st.id = 'mfux-style';
    if (window.CSP_NONCE) { st.setAttribute('nonce', window.CSP_NONCE); }
    st.textContent =
      '.mfux-msg{display:block;width:100%;margin-top:.25rem;font-size:.8125rem;color:var(--bs-danger,#dc3545)}' +
      '.mfux-tab-err::after{content:"";display:inline-block;width:.5rem;height:.5rem;margin-left:.4rem;border-radius:50%;background:var(--bs-danger,#dc3545);vertical-align:middle}' +
      '.ts-wrapper.is-invalid .ts-control,.select2-container.is-invalid .select2-selection{border-color:var(--bs-danger,#dc3545)}';
    (document.head || document.documentElement).appendChild(st);
  }

  function groupOf(el) {
    return el.closest('.form-group, .mb-3, [class*="col-"]') || el.parentElement;
  }

  function markRequired(form) {
    form.querySelectorAll('[required]').forEach(function (el) {
      el.setAttribute('aria-required', 'true');
      var g = groupOf(el);
      var label = g && g.querySelector('label:not(.star-toggle):not(.form-check-label)');
      if (!label || label.querySelector('.text-danger') || /\*/.test(label.textContent)) { return; }
      var star = document.createElement('strong');
      star.className = 'text-danger';
      star.textContent = '*';
      label.appendChild(document.createTextNode(' '));
      label.appendChild(star);
    });
  }

  // The visible stand-in for a field (TomSelect / select2 hide the real <select>).
  function shell(el) {
    var n = el.nextElementSibling;
    if (n && (n.classList.contains('ts-wrapper') || n.classList.contains('select2-container'))) { return n; }
    return el;
  }

  function clearErr(el) {
    var sh = shell(el);
    el.classList.remove('is-invalid');
    sh.classList.remove('is-invalid');
    var box = sh.closest('.input-group') || sh;
    var m = box.nextElementSibling;
    if (m && m.classList.contains('mfux-msg')) { m.remove(); }
    var pane = el.closest('.tab-pane');
    if (pane && !pane.querySelector('.is-invalid')) {
      var form = el.form && (el.form.closest('.modal-content') || el.form);
      var link = form && form.querySelector('[href="#' + pane.id + '"].mfux-tab-err');
      if (link) { link.classList.remove('mfux-tab-err'); }
    }
  }

  function showErr(el) {
    var sh = shell(el);
    el.classList.add('is-invalid');
    sh.classList.add('is-invalid');
    var box = sh.closest('.input-group') || sh;
    var m = box.nextElementSibling;
    if (!(m && m.classList.contains('mfux-msg'))) {
      m = document.createElement('div');
      m.className = 'mfux-msg';
      m.setAttribute('role', 'alert');
      box.insertAdjacentElement('afterend', m);
    }
    m.textContent = el.validity && el.validity.valueMissing ? 'This field is required.' : (el.validationMessage || 'Please check this value.');
    var onChange = function () { if (el.checkValidity()) { clearErr(el); } };
    el.addEventListener('input', onChange);
    el.addEventListener('change', onChange);
    if (window.jQuery) { window.jQuery(el).one('change', onChange); }
  }

  function focusField(el) {
    var sh = shell(el);
    var target = sh !== el ? (sh.querySelector('input, .ts-control') || sh) : el;
    try { target.focus({ preventScroll: false }); } catch (e) { /* ignore */ }
    sh.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function bind(form) {
    if (form.dataset.mfux) { return; }
    form.dataset.mfux = '1';
    form.noValidate = true;       // we run validation ourselves so hidden tabs are handled
    markRequired(form);

    var root = form.closest('.modal-content') || form;   // tab strips can sit outside the <form>
    form.addEventListener('submit', function (e) {
      var bad = Array.prototype.filter.call(form.elements, function (el) {
        return el.willValidate && !el.disabled && !el.checkValidity();
      });
      form.querySelectorAll('.is-invalid').forEach(function (el) { if (el.matches('input,select,textarea') && el.checkValidity()) { clearErr(el); } });
      if (!bad.length) { return; }
      e.preventDefault();
      e.stopImmediatePropagation();
      bad.forEach(function (el) {
        showErr(el);
        var pane = el.closest('.tab-pane');
        var link = pane && root.querySelector('[href="#' + pane.id + '"], [data-bs-target="#' + pane.id + '"]');
        if (link) { link.classList.add('mfux-tab-err'); }
      });
      var first = bad[0];
      var pane = first.closest('.tab-pane');
      var link = pane && root.querySelector('[href="#' + pane.id + '"], [data-bs-target="#' + pane.id + '"]');
      if (link && !pane.classList.contains('active') && window.bootstrap) {
        link.addEventListener('shown.bs.tab', function () { focusField(first); }, { once: true });
        window.bootstrap.Tab.getOrCreateInstance(link).show();
      } else {
        focusField(first);
      }
    }, true);
  }

  document.querySelectorAll('.modal form').forEach(bind);
})();
