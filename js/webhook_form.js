// Administration > Webhooks: the guided Add / Edit flow (platform chooser, form, setup guide), Send test, Preview payload, copy buttons.
// Everything is bound by event delegation on document, so it works for forms injected later into AJAX-loaded modals.
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  // ---- loading a step into the open modal ------------------------------------------------------------------------
  function loadInto(trigger, url) {
    var content = trigger.closest('.modal-content');
    if (!content) { return; }
    content.classList.add('opacity-50');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (data) {
        if (data.error) { throw new Error(data.error); }
        var doc = new DOMParser().parseFromString('<div>' + data.content + '</div>', 'text/html');
        // Scripts the modal footer adds (csrf token, app.js) are already on the page: do not run them twice.
        // <noscript> fallbacks (the no-JS event list) would become live form controls when parsed here: drop them too.
        Array.prototype.forEach.call(doc.querySelectorAll('script, noscript'), function (s) { s.remove(); });
        content.textContent = '';
        Array.prototype.forEach.call(doc.body.firstChild.childNodes, function (n) { content.appendChild(document.importNode(n, true)); });
        content.classList.remove('opacity-50');
        initForm(content);
      })
      .catch(function (e) {
        content.classList.remove('opacity-50');
        alert('Could not load that step: ' + e.message);
      });
  }

  // ---- form set-up -----------------------------------------------------------------------------------------------
  function applyAuthMode(form) {
    var sel = form.querySelector('[data-wh-auth-mode]');
    if (!sel) { return; }
    var mode = sel.value;
    Array.prototype.forEach.call(form.querySelectorAll('[data-wh-auth-fields]'), function (box) {
      box.hidden = box.getAttribute('data-wh-auth-fields') !== mode;
    });
    var none = form.querySelector('[data-wh-auth-none]');
    if (none) { none.hidden = mode === 'bearer' || mode === 'basic' || mode === 'header'; }
  }

  var tplTimer = null;
  function validateTemplate(form) {
    var input = form.querySelector('[data-wh-tpl-input]');
    var state = form.querySelector('[data-wh-tpl-state]');
    if (!input || !state) { return; }
    var fd = new FormData();
    fd.append('csrf_token', form.querySelector('[name=csrf_token]').value);
    fd.append('wh_action', 'validate_template');
    fd.append('webhook_template', input.value);
    fd.append('webhook_template_encoding', (form.querySelector('[data-wh-tpl-enc]') || {}).value || 'json');
    state.textContent = 'Checking...';
    state.className = 'small text-secondary';
    fetch(form.getAttribute('data-wh-action-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { state.textContent = 'Valid. Sample output: ' + String(d.sample).slice(0, 120); state.className = 'small text-success'; }
        else { state.textContent = (d.errors || ['Invalid template']).join(' '); state.className = 'small text-danger'; }
      })
      .catch(function () { state.textContent = ''; });
  }

  function initForm(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('[data-wh-form]'), function (form) {
      if (form._whInit) { return; }
      form._whInit = true;
      applyAuthMode(form);
      if (form.querySelector('[data-wh-tpl-input]')) { validateTemplate(form); }
    });
  }

  // ---- results ---------------------------------------------------------------------------------------------------
  function showResult(form, build) {
    var box = form.querySelector('[data-wh-result]');
    box.hidden = false;
    box.className = 'wh-result';
    box.textContent = '';
    build(box);
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function formData(form, action, extra) {
    var fd = new FormData(form);
    fd.delete('add_webhook');
    fd.delete('edit_webhook');
    fd.append('wh_action', action);
    Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
    return fd;
  }

  function post(form, action, extra) {
    return fetch(form.getAttribute('data-wh-action-url'), { method: 'POST', body: formData(form, action, extra), credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { throw new Error('The server answered HTTP ' + r.status + '.'); }); });
  }

  function showErrors(form, errors) {
    showResult(form, function (box) {
      box.classList.add('is-bad');
      box.appendChild(el('strong', '', 'Fix this first:'));
      var ul = el('ul', 'mb-0');
      errors.forEach(function (m) { ul.appendChild(el('li', '', m)); });
      box.appendChild(ul);
    });
  }

  function runTest(form, btn) {
    btn.disabled = true;
    showResult(form, function (box) { box.appendChild(el('span', 'text-secondary', 'Sending a test event...')); });
    post(form, 'test').then(function (d) {
      btn.disabled = false;
      if (!d.ok) { showErrors(form, d.errors || ['The test could not be sent.']); return; }
      var r = d.result;
      showResult(form, function (box) {
        box.classList.add(r.ok ? 'is-ok' : 'is-bad');
        box.appendChild(el('strong', '', r.ok ? 'Delivered' : 'Failed'));
        box.appendChild(document.createTextNode(': ' + (r.http_status ? 'HTTP ' + r.http_status : 'no response') + ' in ' + r.duration_ms + ' ms'));
        if (r.error && !r.ok) { box.appendChild(el('div', 'text-danger small', r.error)); }
        if (r.response) { box.appendChild(el('div', 'small text-secondary mt-1', 'Response body (cut):')); box.appendChild(el('pre', '', r.response)); }
        box.appendChild(el('div', 'small text-secondary mt-1', 'A test event was sent through the real delivery path; saved webhooks log it once as a "test." entry.'));
      });
    }).catch(function (e) { btn.disabled = false; showErrors(form, [e.message]); });
  }

  function runPreview(form, sample) {
    post(form, 'preview', sample ? { sample_event: sample } : {}).then(function (d) {
      if (!d.ok) { showErrors(form, d.errors || ['Nothing to preview.']); return; }
      var p = d.preview;
      showResult(form, function (box) {
        var head = el('div', 'd-flex flex-wrap gap-2 align-items-center mb-2');
        head.appendChild(el('strong', '', 'Preview'));
        var sel = el('select', 'form-select form-select-sm w-auto');
        sel.setAttribute('data-wh-sample', '');
        sel.setAttribute('aria-label', 'Sample event');
        d.events.forEach(function (ev) { var o = el('option', '', ev); o.value = ev; if (ev === d.event) { o.selected = true; } sel.appendChild(o); });
        head.appendChild(sel);
        box.appendChild(head);
        box.appendChild(el('div', 'small mb-1', p.method + ' ' + p.url + '  (body format: ' + p.format + ')'));
        box.appendChild(el('div', 'small text-secondary', 'Headers (secrets masked)'));
        box.appendChild(el('pre', '', p.headers.join('\n')));
        box.appendChild(el('div', 'small text-secondary', 'Body'));
        box.appendChild(el('pre', '', p.body));
        box.appendChild(el('div', 'small text-secondary', 'Sample data only; nothing was sent. JSON is shown indented; the signature covers the compact bytes that are actually sent.'));
      });
    }).catch(function (e) { showErrors(form, [e.message]); });
  }

  // ---- delegated events ------------------------------------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var t = e.target;
    var card = t.closest && t.closest('.wh-card[data-wh-dest]');
    if (card) { loadInto(card, 'modals/webhook/webhook_add.php?dest=' + encodeURIComponent(card.getAttribute('data-wh-dest'))); return; }
    var back = t.closest && t.closest('[data-wh-back]');
    if (back) { loadInto(back, back.getAttribute('data-wh-back')); return; }
    var copy = t.closest && t.closest('[data-wh-copy]');
    if (copy) {
      var src = document.querySelector(copy.getAttribute('data-wh-copy'));
      if (!src) { return; }
      var text = src.textContent;
      var done = function () { var old = copy.innerHTML; copy.textContent = 'Copied'; setTimeout(function () { copy.innerHTML = old; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); }
      else {
        var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* nothing to do */ }
        ta.remove();
      }
      return;
    }
    var gen = t.closest && t.closest('[data-wh-gen-secret]');
    if (gen) {
      var input = document.querySelector(gen.getAttribute('data-wh-gen-secret'));
      var bytes = new Uint8Array(20); crypto.getRandomValues(bytes);
      input.value = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      input.type = 'text';
      input.focus();
      return;
    }
    var ins = t.closest && t.closest('[data-wh-insert]');
    if (ins) {
      var form = ins.closest('[data-wh-form]'), ta2 = form.querySelector('[data-wh-tpl-input]'), v = ins.getAttribute('data-wh-insert');
      var s = ta2.selectionStart || 0, en = ta2.selectionEnd || 0;
      ta2.value = ta2.value.slice(0, s) + v + ta2.value.slice(en);
      ta2.focus(); ta2.selectionStart = ta2.selectionEnd = s + v.length;
      validateTemplate(form);
      return;
    }
    var test = t.closest && t.closest('[data-wh-test]');
    if (test) { runTest(test.closest('[data-wh-form]'), test); return; }
    var prev = t.closest && t.closest('[data-wh-preview]');
    if (prev) { runPreview(prev.closest('[data-wh-form]')); return; }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches && t.matches('[data-wh-auth-mode]')) { applyAuthMode(t.closest('[data-wh-form]')); }
    if (t.matches && t.matches('[data-wh-switch]')) { loadInto(t, t.getAttribute('data-wh-switch') + '&dest=' + encodeURIComponent(t.value)); }
    if (t.matches && t.matches('[data-wh-sample]')) { runPreview(t.closest('[data-wh-form]'), t.value); }
    if (t.matches && t.matches('[data-wh-tpl-enc]')) { validateTemplate(t.closest('[data-wh-form]')); }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.matches && t.matches('[data-wh-chooser-search]')) {
      var q = t.value.toLowerCase().trim(), any = false;
      var root = t.closest('[data-wh-chooser]');
      Array.prototype.forEach.call(root.querySelectorAll('.wh-card'), function (c) {
        var show = !q || c.getAttribute('data-wh-search').indexOf(q) !== -1;
        c.hidden = !show;
        if (show) { any = true; }
      });
      Array.prototype.forEach.call(root.querySelectorAll('[data-wh-cat]'), function (cat) {
        cat.hidden = !cat.querySelector('.wh-card:not([hidden])');
      });
      root.querySelector('[data-wh-chooser-none]').classList.toggle('d-none', any);
    }
    if (t.matches && t.matches('[data-wh-tpl-input]')) {
      clearTimeout(tplTimer);
      tplTimer = setTimeout(function () { validateTemplate(t.closest('[data-wh-form]')); }, 500);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-wh-chooser-search]')) {
      e.preventDefault();
      var first = e.target.closest('[data-wh-chooser]').querySelector('.wh-card:not([hidden])');
      if (first) { first.click(); }
    }
  });

  // Refuse a submit with no events chosen (the server checks too), and keep Enter in a text field from submitting by accident.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('[data-wh-form]')) { return; }
    var picker = form.querySelector('[data-event-picker]');
    if (picker) {
      var any = Array.prototype.some.call(picker.querySelectorAll('[data-ep-values] input'), function (i) { return i.value !== ''; });
      if (!any) { e.preventDefault(); showErrors(form, ['Choose at least one event.']); }
    }
  });

  // Forms arrive inside AJAX modals: set them up as they appear.
  function start() {
    initForm(document);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) { initForm(n); } });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
