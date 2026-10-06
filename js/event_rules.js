// Event rules page (admin/event_rules.php). Loaded only there. Vanilla JS; reads its data from <script id="er-data"> and talks to
// /admin/modals/event_rules_api.php. Saving and deleting stay ordinary form posts; everything here is convenience, the server decides.
(function () {
  'use strict';

  var dataEl = document.getElementById('er-data');
  if (!dataEl) { return; }
  var D = JSON.parse(dataEl.textContent);

  // ------------------------------------------------------------------ helpers
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function el(tag, cls, text, attrs) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    return n;
  }
  function icon(name) { var i = el('i', 'fas ' + name); i.setAttribute('aria-hidden', 'true'); return i; }
  function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }
  function announce(msg) { var l = document.getElementById('er-live'); if (l) { l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 20); } }

  function toast(msg, kind) {
    var box = document.getElementById('er-toasts');
    if (!box) { return; }
    var t = el('div', 'er-toast er-toast-' + (kind || 'ok'), msg);
    t.setAttribute('role', kind === 'bad' ? 'alert' : 'status');
    box.appendChild(t);
    setTimeout(function () { t.classList.add('is-gone'); setTimeout(function () { t.remove(); }, 300); }, kind === 'bad' ? 7000 : 3500);
    announce(msg);
  }
  try {
    var pending = sessionStorage.getItem('er-toast');
    if (pending) { sessionStorage.removeItem('er-toast'); toast(pending); }
  } catch (e) { /* storage unavailable */ }

  function toParams(src) {
    if (src instanceof URLSearchParams) { return new URLSearchParams(src.toString()); }
    var p = new URLSearchParams();
    if (src instanceof FormData) { src.forEach(function (v, k) { if (typeof v === 'string') { p.append(k, v); } }); }
    else if (src) { Object.keys(src).forEach(function (k) { [].concat(src[k]).forEach(function (v) { p.append(k, v); }); }); }
    return p;
  }
  // GET (params in the URL) or POST (params in the body plus the CSRF token); resolves with the JSON, rejects with a readable Error.
  function api(action, params, post) {
    var p = toParams(params);
    var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    var url = D.api;
    if (post) {
      p.set('a', action); p.set('csrf_token', D.csrf);
      opts.method = 'POST'; opts.body = p;
    } else {
      p.set('a', action); url += '?' + p.toString();
    }
    return fetch(url, opts).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
        if (!j) { throw new Error('The server answered with an unexpected response (HTTP ' + r.status + ').'); }
        if (j.ok === false) { var err = new Error(j.error || 'The request failed.'); err.data = j; throw err; }
        return j;
      });
    });
  }

  // ------------------------------------------------------------------ drawer (right panel on desktop, bottom sheet on phones)
  var drawer = null;
  function closeDrawer() {
    if (!drawer) { return; }
    var d = drawer; drawer = null;
    d.overlay.remove();
    document.body.classList.remove('er-noscroll');
    document.removeEventListener('keydown', d.onKey, true);
    if (d.opener && document.contains(d.opener)) { d.opener.focus(); }
  }
  function openDrawer(title, opener) {
    closeDrawer();
    var overlay = el('div', 'er-overlay');
    var dlg = el('div', 'er-drawer');
    dlg.setAttribute('role', 'dialog'); dlg.setAttribute('aria-modal', 'true'); dlg.setAttribute('aria-labelledby', 'er-drawer-title');
    var head = el('div', 'er-drawer-head');
    var h = el('h4', 'er-drawer-title', title, { id: 'er-drawer-title' });
    var x = el('button', 'btn-close', null, { type: 'button', 'aria-label': 'Close' });
    head.appendChild(h); head.appendChild(x);
    var body = el('div', 'er-drawer-body');
    dlg.appendChild(head); dlg.appendChild(body); overlay.appendChild(dlg);
    document.body.appendChild(overlay);
    document.body.classList.add('er-noscroll');
    function onKey(e) {
      if (e.key === 'Escape') { e.stopPropagation(); closeDrawer(); return; }
      if (e.key !== 'Tab') { return; }
      var f = $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', dlg).filter(function (n) { return n.offsetParent !== null; });
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    document.addEventListener('keydown', onKey, true);
    x.addEventListener('click', closeDrawer);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { closeDrawer(); } });
    drawer = { overlay: overlay, onKey: onKey, opener: opener || null };
    x.focus();
    return { body: body, title: h };
  }

  function statusBadge(status) {
    var cls = status === 'ok' ? 'success' : (status === 'failed' ? 'danger' : 'warning');
    return el('span', 'badge text-bg-' + cls, status);
  }
  function eventLabel(id) { return (D.eventLabels && D.eventLabels[id]) || id; }

  // ------------------------------------------------------------------ test drawer (dry run)
  // ctx: {ruleId, event, name, form (function returning FormData of the unsaved editor form, or null), opener}
  function openTest(ctx) {
    var dr = openDrawer('Test rule' + (ctx.name ? ': ' + ctx.name : ''), ctx.opener);
    var body = dr.body;
    if (!ctx.event) { body.appendChild(el('div', 'alert alert-warning', 'Choose the event the rule listens for first.')); return; }
    body.appendChild(el('div', 'alert alert-info small', 'This is a dry run: the conditions are checked and the action only reports what it WOULD do. Nothing is written, queued or sent.'));
    var form = el('div', 'er-test-form');
    body.appendChild(form);
    var out = el('div', 'er-test-out', null, { 'aria-live': 'polite' });
    body.appendChild(out);
    form.appendChild(el('p', 'text-muted', 'Loading sample events...'));
    api('recent', { event: ctx.event }, false).then(function (rec) {
      form.textContent = '';
      var lab = el('label', 'form-label', 'Sample event', { 'for': 'er-src' });
      var sel = el('select', 'form-select', null, { id: 'er-src' });
      function opt(v, t) { var o = el('option', null, t); o.value = v; sel.appendChild(o); }
      if (rec.tickets.length) { opt('ticket', 'A recent ticket (' + rec.tickets.length + ')'); }
      if (rec.audit.length) { opt('audit', 'A recent ' + ctx.event + ' from the audit trail (' + rec.audit.length + ')'); }
      opt('sample', 'A sample event with plausible values');
      opt('synthetic', 'My own event data (JSON)');
      var pickWrap = el('div', 'mt-2');
      function renderPick() {
        pickWrap.textContent = '';
        if (sel.value === 'ticket') {
          var s = el('select', 'form-select', null, { id: 'er-pick', 'aria-label': 'Ticket' });
          rec.tickets.forEach(function (t) { var o = el('option', null, t.label); o.value = t.id; s.appendChild(o); });
          pickWrap.appendChild(s);
        } else if (sel.value === 'audit') {
          var s2 = el('select', 'form-select', null, { id: 'er-pick', 'aria-label': 'Audit trail entry' });
          rec.audit.forEach(function (t) { var o = el('option', null, t.label); o.value = t.id; s2.appendChild(o); });
          pickWrap.appendChild(s2);
        } else if (sel.value === 'synthetic') {
          var ta = el('textarea', 'form-control font-monospace small', null, { id: 'er-pick', rows: '8', 'aria-label': 'Event data as JSON' });
          ta.value = rec.sample;
          pickWrap.appendChild(ta);
        }
      }
      sel.addEventListener('change', renderPick); renderPick();
      var run = el('button', 'btn btn-primary mt-3', null, { type: 'button' });
      run.appendChild(icon('fa-vial')); run.appendChild(document.createTextNode(' Run test'));
      form.appendChild(lab); form.appendChild(sel); form.appendChild(pickWrap); form.appendChild(run);

      function sourceParams() {
        var pick = $('#er-pick', form);
        var p = { source: sel.value };
        if (sel.value === 'ticket') { p.sample_ticket = pick.value; }
        if (sel.value === 'audit') { p.sample_audit = pick.value; }
        if (sel.value === 'synthetic') { p.synthetic_json = pick.value; }
        return p;
      }
      function params() {
        var base = ctx.form ? ctx.form() : new FormData();
        var p = toParams(base);
        if (!ctx.form) { p.set('rule_id', ctx.ruleId); } else if (ctx.ruleId) { p.set('rule_id', ctx.ruleId); }
        var sp = sourceParams();
        Object.keys(sp).forEach(function (k) { p.set(k, sp[k]); });
        return p;
      }
      run.addEventListener('click', function () {
        out.textContent = ''; out.appendChild(el('p', 'text-muted', 'Testing...'));
        run.disabled = true;
        api('test', params(), true).then(function (r) { renderTest(r, out, ctx, params); }, function (e) {
          out.textContent = '';
          var box = el('div', 'alert alert-danger', e.message);
          if (e.data && e.data.problems) {
            var ul = el('ul', 'mb-0');
            Object.keys(e.data.problems).forEach(function (k) { ul.appendChild(el('li', null, e.data.problems[k])); });
            box.appendChild(ul);
          }
          out.appendChild(box);
        }).then(function () { run.disabled = false; });
      });
      if (ctx.autorun) { run.click(); }
    }, function (e) { form.textContent = ''; form.appendChild(el('div', 'alert alert-danger', e.message)); });
  }

  function renderTest(r, out, ctx, params) {
    out.textContent = '';
    out.appendChild(el('p', 'er-sentence-big', r.summary));
    var head = el('p', 'mb-2');
    head.appendChild(el('span', 'badge text-bg-' + (r.matched ? 'success' : 'secondary'), r.matched ? 'Conditions matched' : 'Conditions did not match'));
    head.appendChild(document.createTextNode(' for ' + r.sample));
    out.appendChild(head);
    if (!r.valid) { out.appendChild(el('div', 'alert alert-danger', 'The conditions are not valid, so this rule never fires.')); }
    if (r.conditions && r.conditions.length) {
      var ul = el('ul', 'er-cond-result');
      r.conditions.forEach(function (c) {
        var li = el('li', c.ok ? 'is-ok' : 'is-no');
        li.appendChild(icon(c.ok ? 'fa-check-circle' : 'fa-times-circle'));
        li.appendChild(el('span', 'er-cond-text', ' ' + c.text.replace(/^\s+/, '')));
        li.style.paddingLeft = (c.text.length - c.text.replace(/^\s+/, '').length) * 4 + 'px';
        ul.appendChild(li);
      });
      out.appendChild(ul);
      if (!r.matched) {
        var failed = r.conditions.filter(function (c) { return !c.ok && !/^\s*group/.test(c.text); });
        if (failed.length) { out.appendChild(el('p', 'small text-muted', 'Not matched because: ' + failed.map(function (c) { return c.text.replace(/\s+\(event has[\s\S]*$/, '').trim(); }).join('; '))); }
      }
    } else if (r.matched) { out.appendChild(el('p', 'small text-muted', 'The rule has no conditions, so it runs for every ' + eventLabel(r.event) + '.')); }
    if (r.guard) { out.appendChild(el('div', 'alert alert-warning', r.guard)); }
    if (r.action) {
      var a = el('div', 'alert alert-' + (r.action.ok ? 'success' : 'danger'));
      a.appendChild(el('strong', null, r.action.ok ? 'Would run: ' : 'Would fail: '));
      a.appendChild(document.createTextNode(r.action.message));
      out.appendChild(a);
    } else if (!r.matched) { out.appendChild(el('p', 'text-muted', 'The rule would not run for this event, so no action would happen.')); }
    if (r.can_run && r.matched && !r.guard) {
      var rb = el('button', 'btn btn-outline-danger', null, { type: 'button' });
      rb.appendChild(icon('fa-bolt')); rb.appendChild(document.createTextNode(' Run for real now'));
      var note = el('p', 'small text-muted mt-1', 'Really runs the SAVED rule once for this sample, so the technicians receive the notification. Dry runs above never do.');
      out.appendChild(rb); out.appendChild(note);
      rb.addEventListener('click', function () {
        if (!window.confirm('Run this rule for real now? It will send its notification to all technicians.')) { return; }
        rb.disabled = true;
        api('run_now', params(), true).then(function (x) {
          out.appendChild(el('div', 'alert alert-' + (x.status === 'ok' ? 'success' : 'warning'), 'Result: ' + x.status + ' - ' + x.message));
        }, function (e) { out.appendChild(el('div', 'alert alert-danger', e.message)); }).then(function () { rb.disabled = false; });
      });
    }
  }

  // ------------------------------------------------------------------ history drawer
  function openHistory(ruleId, name, opener) {
    var dr = openDrawer('History' + (name ? ': ' + name : ''), opener);
    var body = dr.body;
    var failedOnly = false, offset = 0;
    var top = el('div', 'er-hist-top');
    var counts = el('div', 'er-hist-counts');
    var filt = el('label', 'form-check er-hist-filter');
    var cb = el('input', 'form-check-input', null, { type: 'checkbox' });
    filt.appendChild(cb); filt.appendChild(el('span', 'form-check-label', 'Failed runs only'));
    top.appendChild(counts); top.appendChild(filt);
    var note = el('p', 'small text-muted');
    var wrap = el('div', 'table-responsive');
    var tbl = el('table', 'table table-sm er-hist-table');
    var thead = el('thead'); var tr = el('tr');
    ['When', 'Event', 'Status', 'Message', 'Took'].forEach(function (t) { tr.appendChild(el('th', null, t, { scope: 'col' })); });
    thead.appendChild(tr); tbl.appendChild(thead);
    var tb = el('tbody'); tbl.appendChild(tb); wrap.appendChild(tbl);
    var more = el('button', 'btn btn-outline-secondary mt-2', 'Load more', { type: 'button', hidden: 'hidden' });
    var empty = el('p', 'text-muted', 'No runs recorded.', { hidden: 'hidden' });
    body.appendChild(top); body.appendChild(note); body.appendChild(wrap); body.appendChild(empty); body.appendChild(more);
    function ago(s) {
      if (s < 60) { return 'just now'; } if (s < 3600) { return Math.floor(s / 60) + ' min ago'; }
      if (s < 86400) { return Math.floor(s / 3600) + ' h ago'; } return Math.floor(s / 86400) + ' d ago';
    }
    function load(reset) {
      if (reset) { offset = 0; tb.textContent = ''; }
      api('history', { rule_id: ruleId, failed: failedOnly ? 1 : 0, offset: offset }, false).then(function (h) {
        counts.textContent = '';
        ['ok', 'failed', 'throttled', 'loop_blocked', 'skipped'].forEach(function (k) {
          if (h.counts[k]) { var b = statusBadge(k); b.textContent = k.replace('_', ' ') + ': ' + h.counts[k]; counts.appendChild(b); counts.appendChild(document.createTextNode(' ')); }
        });
        note.textContent = 'Rate limit: ' + h.rule.rate + ' run' + (h.rule.rate === 1 ? '' : 's') + ' per minute. "throttled" rows (one is kept per minute) mean the limit was hit; "loop blocked" means the loop guard stopped the rule from triggering itself.';
        h.rows.forEach(function (r) {
          var row = el('tr');
          var t = el('td', 'text-nowrap'); t.appendChild(el('div', null, r.created_at)); t.appendChild(el('div', 'small text-muted', ago(r.age))); row.appendChild(t);
          row.appendChild(el('td', null, r.event_type));
          var s = el('td'); s.appendChild(statusBadge(r.status)); row.appendChild(s);
          row.appendChild(el('td', 'er-hist-msg', r.message));
          row.appendChild(el('td', 'text-nowrap', r.duration_ms + ' ms'));
          tb.appendChild(row);
        });
        offset += h.rows.length;
        more.hidden = offset >= h.total;
        empty.hidden = tb.children.length > 0;
        empty.textContent = failedOnly ? 'No failed runs.' : 'No runs recorded.';
      }, function (e) { body.appendChild(el('div', 'alert alert-danger', e.message)); });
    }
    cb.addEventListener('change', function () { failedOnly = cb.checked; load(true); });
    more.addEventListener('click', function () { load(false); });
    load(true);
  }

  // ================================================================== LIST
  if (D.view === 'list') {
    var list = document.getElementById('er-list');
    var filters = document.getElementById('er-filters');
    if (filters) {
      $$('select', filters).forEach(function (s) { s.addEventListener('change', function () { filters.submit(); }); });
    }
    var strip = { on: document.getElementById('er-n-on'), off: document.getElementById('er-n-off') };

    if (list) {
      list.addEventListener('click', function (e) {
        var li = e.target.closest('.er-rule');
        if (!li) { return; }
        var id = li.getAttribute('data-rule'), name = li.getAttribute('data-name');
        var t;
        if ((t = e.target.closest('[data-er-toggle]'))) {
          var want = t.getAttribute('aria-checked') !== 'true';
          setSwitch(li, want);
          t.disabled = true;
          api('toggle', { rule_id: id, enabled: want ? '1' : '0' }, true).then(function (r) {
            setSwitch(li, r.enabled);
            bump(r.enabled ? 1 : -1);
            toast('"' + name + '" turned ' + (r.enabled ? 'on' : 'off') + '.');
          }, function (err) { setSwitch(li, !want); toast(err.message, 'bad'); }).then(function () { t.disabled = false; });
        } else if ((t = e.target.closest('[data-er-test]'))) {
          openTest({ ruleId: id, event: li.getAttribute('data-trigger'), name: name, form: null, opener: t });
        } else if ((t = e.target.closest('[data-er-history]'))) {
          openHistory(id, name, t);
        } else if ((t = e.target.closest('[data-er-duplicate]'))) {
          t.disabled = true;
          api('duplicate', { rule_id: id }, true).then(function () {
            try { sessionStorage.setItem('er-toast', 'Copied "' + name + '". The copy is turned off until you turn it on.'); } catch (x) { /* ignore */ }
            window.location.reload();
          }, function (err) { t.disabled = false; toast(err.message, 'bad'); });
        } else if ((t = e.target.closest('[data-er-move]'))) {
          moveRule(li, parseInt(t.getAttribute('data-er-move'), 10), t);
        }
      });
    }
    function setSwitch(li, on) {
      var b = $('[data-er-toggle]', li);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      b.setAttribute('aria-label', 'Rule "' + li.getAttribute('data-name') + '" is ' + (on ? 'on' : 'off'));
      li.classList.toggle('is-off', !on);
      li.setAttribute('data-enabled', on ? '1' : '0');
      var ob = $('.er-offbadge', li); if (ob) { ob.hidden = on; }
    }
    function bump(d) {
      if (strip.on) { strip.on.textContent = Math.max(0, parseInt(strip.on.textContent, 10) + d); }
      if (strip.off) { strip.off.textContent = Math.max(0, parseInt(strip.off.textContent, 10) - d); }
    }

    // ---- ordering (priority): up/down buttons and drag and drop, only among rules of the same event
    function siblings(li) { return $$('.er-rule', list).filter(function (n) { return n.getAttribute('data-trigger') === li.getAttribute('data-trigger'); }); }
    function saveOrder(li) {
      var sibs = siblings(li);
      if (sibs.length < 2) { return; }
      api('reorder', { 'ids[]': sibs.map(function (n) { return n.getAttribute('data-rule'); }) }, true).then(function () {
        sibs.forEach(function (n, i) { var p = $('.er-prio', n); if (p) { p.textContent = '#' + ((i + 1) * 10); } });
        toast('Order saved. Rules for ' + li.getAttribute('data-trigger') + ' now run top to bottom.');
      }, function (err) { toast(err.message, 'bad'); setTimeout(function () { window.location.reload(); }, 1200); });
    }
    function moveRule(li, dir, btn) {
      var sibs = siblings(li), i = sibs.indexOf(li), target = sibs[i + dir];
      if (!target) { toast('This rule is already ' + (dir < 0 ? 'first' : 'last') + ' for ' + li.getAttribute('data-trigger') + '.'); return; }
      list.insertBefore(li, dir < 0 ? target : target.nextSibling);
      if (btn) { btn.focus(); }
      saveOrder(li);
    }
    var dragging = null;
    if (list && list.getAttribute('data-reorder') === '1') {
      list.addEventListener('dragstart', function (e) {
        var li = e.target.closest && e.target.closest('.er-rule');
        if (!li) { return; }
        dragging = li; li.classList.add('is-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', li.getAttribute('data-rule')); } catch (x) { /* ignore */ }
      });
      list.addEventListener('dragover', function (e) {
        if (!dragging) { return; }
        var li = e.target.closest('.er-rule');
        if (!li || li === dragging) { return; }
        $$('.er-drop-before, .er-drop-after', list).forEach(function (n) { n.classList.remove('er-drop-before', 'er-drop-after'); });
        if (li.getAttribute('data-trigger') !== dragging.getAttribute('data-trigger')) { return; }
        e.preventDefault();
        var r = li.getBoundingClientRect();
        li.classList.add(e.clientY < r.top + r.height / 2 ? 'er-drop-before' : 'er-drop-after');
      });
      list.addEventListener('drop', function (e) {
        if (!dragging) { return; }
        var li = e.target.closest('.er-rule');
        if (!li || li === dragging) { return; }
        e.preventDefault();
        if (li.getAttribute('data-trigger') !== dragging.getAttribute('data-trigger')) {
          toast('Order only matters between rules for the same event: drop it among the rules for ' + dragging.getAttribute('data-trigger') + '.', 'bad');
          return;
        }
        var r = li.getBoundingClientRect();
        list.insertBefore(dragging, e.clientY < r.top + r.height / 2 ? li : li.nextSibling);
        saveOrder(dragging);
      });
      list.addEventListener('dragend', function () {
        if (dragging) { dragging.classList.remove('is-dragging'); }
        $$('.er-drop-before, .er-drop-after', list).forEach(function (n) { n.classList.remove('er-drop-before', 'er-drop-after'); });
        dragging = null;
      });
    }
    return;
  }

  // ================================================================== EDITOR
  var form = document.getElementById('ruleForm');
  if (!form) { return; }
  var S = D.state;
  var OPS = ['eq', 'ne', 'in', 'contains', 'gt', 'lt', 'is_empty'];
  var OP_LABEL = { eq: 'is', ne: 'is not', 'in': 'is one of', contains: 'contains', gt: 'is greater than', lt: 'is less than', is_empty: 'is empty' };
  var EV = null;           // what the chosen event offers (API 'event')
  var touched = {};        // sections whose problems are shown
  if (D.touched) { ['trigger', 'conditions', 'action', 'name', 'settings'].forEach(function (s) { touched[s] = true; }); }
  var picker = document.getElementById('rule-trigger-picker');

  function trigger() {
    var i = form.querySelector('[data-ep-values] input[name="trigger_event"]') || form.querySelector('select[name="trigger_event"]');
    return i ? (i.value || '').trim() : '';
  }
  function touch(sec) { if (!touched[sec]) { touched[sec] = true; } }

  // ---- event info: description, severity, the fields it carries
  var lastTrigger = null;
  function loadEvent() {
    var t = trigger();
    if (t === lastTrigger) { return; }
    lastTrigger = t;
    var box = document.getElementById('er-event-info');
    if (!t) { EV = null; box.textContent = ''; box.appendChild(el('p', 'text-muted small mb-0', 'Choose an event below. You will see what it means and which fields of the event you can use in conditions and messages.')); afterEvent(); return; }
    api('event', { event: t }, false).then(function (ev) {
      if (trigger() !== t) { return; }
      EV = ev;
      box.textContent = '';
      var head = el('div', 'er-evhead');
      head.appendChild(el('strong', null, ev.label));
      head.appendChild(el('code', null, ev.id));
      if (ev.groupLabel) { head.appendChild(el('span', 'badge text-bg-light', ev.groupLabel)); }
      if (ev.severity && ev.severity !== 'info') { head.appendChild(el('span', 'badge text-bg-' + (ev.severity === 'critical' ? 'danger' : 'warning'), ev.severity)); }
      box.appendChild(head);
      box.appendChild(el('p', 'small text-muted mb-2', ev.description || (ev.known ? '' : 'This event is not in the catalog: it is one this server has recorded in its audit trail.')));
      var det = el('details', 'er-fields');
      det.appendChild(el('summary', null, 'Fields this event carries (' + ev.fields.length + '): use them in conditions and as {placeholders}'));
      var tbl = el('table', 'table table-sm mb-0');
      var tb = el('tbody');
      ev.fields.forEach(function (f) {
        var r = el('tr');
        r.appendChild(el('td', null, f.path, { 'class': 'er-fpath' }));
        r.appendChild(el('td', 'text-muted', f.type));
        r.appendChild(el('td', null, f.description || ''));
        r.appendChild(el('td', 'text-muted er-fsample', f.sample !== null && f.sample !== undefined ? 'e.g. ' + f.sample : ''));
        tb.appendChild(r);
      });
      tbl.appendChild(tb); det.appendChild(tbl); box.appendChild(det);
      afterEvent();
    }, function (e) { box.textContent = ''; box.appendChild(el('div', 'alert alert-danger', e.message)); });
  }
  function afterEvent() { Builder.render(); Chips.refresh(); updateTicketNote(); scheduleSummary(); }
  if (picker) { picker.addEventListener('change', function () { touch('trigger'); loadEvent(); scheduleSummary(); }); }

  // ---- condition builder ---------------------------------------------------------------------------------
  var Builder = (function () {
    var root = document.getElementById('er-builder');
    var hidden = document.getElementById('er-cond-inputs');
    var uid = 0;
    var st = { mode: S.mode === 'any' ? 'any' : 'all', rows: [], groups: [], groupModes: {} };
    (S.rows || []).forEach(function (r) { st.rows.push({ id: ++uid, field: r.field, op: r.op || 'eq', value: r.value || '', group: r.group || '' }); if (r.group && st.groups.indexOf(r.group) < 0) { st.groups.push(r.group); } });
    Object.keys(S.groupModes || {}).forEach(function (g) { st.groupModes[g] = S.groupModes[g] === 'all' ? 'all' : 'any'; if (st.groups.indexOf(g) < 0) { st.groups.push(g); } });
    st.groups.sort();
    var selects = [];

    function fieldInfo(path) { var f = null; (EV ? EV.fields : []).forEach(function (x) { if (x.path === path) { f = x; } }); return f; }
    function valueSource(field) {
      var map = function (o, extra) { var a = extra ? extra.slice() : []; Object.keys(o || {}).forEach(function (k) { a.push({ value: String(k), text: o[k] }); }); return a; };
      switch (field) {
        case 'ticket_priority': return D.priorities.map(function (v) { return { value: v, text: v }; });
        case 'ticket_status': return D.statuses.map(function (v) { return { value: v, text: v }; });
        case 'assigned_to_user_id': return map(D.agents, [{ value: '0', text: 'Unassigned' }]);
        case 'actor_user_id': return map(D.agents);
        case 'client_id': return map(D.clients, [{ value: '0', text: 'No client' }]);
        case 'sla_clock': return [{ value: 'response', text: 'response' }, { value: 'resolution', text: 'resolution' }];
      }
      return null;
    }
    function allowedOps(field) {
      var f = fieldInfo(field);
      if (f && f.type === 'string' && !valueSource(field)) { return ['eq', 'ne', 'in', 'contains', 'is_empty']; }
      if (f && f.type === 'string') { return ['eq', 'ne', 'in', 'contains', 'is_empty']; }
      return OPS;
    }
    function count() { return st.rows.length; }

    function sync() {
      hidden.textContent = '';
      function add(n, v) { var i = el('input', null, null, { type: 'hidden', name: n }); i.value = v; hidden.appendChild(i); }
      add('cond_mode', st.mode);
      var used = {};
      st.rows.forEach(function (r) {
        if (!r.field) { return; }
        add('cond_field[]', r.field); add('cond_op[]', r.op); add('cond_value[]', r.op === 'is_empty' ? '' : r.value); add('cond_group[]', r.group);
        if (r.group) { used[r.group] = true; }
      });
      Object.keys(used).forEach(function (g) { add('group_mode[' + g + ']', st.groupModes[g] || 'any'); });
    }
    function changed() { touch('conditions'); sync(); scheduleSummary(); st.rows.forEach(function (r) { if (rowEls[r.id]) { hintRow(r); } }); }

    function tsAvailable() { return typeof window.TomSelect === 'function'; }
    function destroyAll() { selects.forEach(function (s) { try { s.destroy(); } catch (e) { /* ignore */ } }); selects = []; }

    function fieldControl(row, cell) {
      var sel = el('select', 'form-select form-select-sm', null, { 'aria-label': 'Field' });
      cell.appendChild(sel);
      var fields = EV ? EV.fields : [];
      if (!tsAvailable()) {
        var inp = el('input', 'form-control form-control-sm', null, { type: 'text', 'aria-label': 'Field', maxlength: '100', placeholder: 'field, e.g. ticket_priority', list: 'er-fieldlist' });
        sel.replaceWith(inp); inp.value = row.field;
        inp.addEventListener('input', function () { row.field = inp.value.trim(); changed(); });
        inp.addEventListener('change', function () { rerenderRow(row); });
        return;
      }
      var options = fields.map(function (f) { return { value: f.path, text: f.path, description: f.description || '', type: f.type, grp: 'Fields on this event' }; });
      if (row.field && !options.some(function (o) { return o.value === row.field; })) { options.push({ value: row.field, text: row.field, description: '', type: '', grp: 'Other fields' }); }
      var ts = new window.TomSelect(sel, {
        options: options, items: row.field ? [row.field] : [], maxItems: 1, create: function (input) { input = input.trim(); return /^[A-Za-z0-9_.]{1,100}$/.test(input) ? { value: input, text: input, description: 'custom field', grp: 'Other fields' } : false; },
        createFilter: /^[A-Za-z0-9_.]{1,100}$/, placeholder: 'Choose or type a field', openOnFocus: true,
        optgroupField: 'grp', optgroups: [{ value: 'Fields on this event', label: 'Fields on this event' }, { value: 'Other fields', label: 'Other fields' }], lockOptgroupOrder: true,
        searchField: ['text', 'description'], dropdownParent: 'body',
        render: {
          option: function (d, esc) { return '<div class="er-fopt"><span class="er-fopt-path">' + esc(d.text) + '</span>' + (d.description ? '<span class="er-fopt-desc">' + esc(d.description) + '</span>' : '') + '</div>'; },
          item: function (d, esc) { return '<div>' + esc(d.text) + '</div>'; },
          option_create: function (d, esc) { return '<div class="create">Use the field <strong>' + esc(d.input) + '</strong></div>'; }
        },
        onChange: function (v) { row.field = v || ''; if (allowedOps(row.field).indexOf(row.op) < 0) { row.op = 'eq'; } changed(); rerenderRow(row); }
      });
      selects.push(ts);
    }

    function valueControl(row, cell) {
      cell.textContent = '';
      if (row.op === 'is_empty') { cell.appendChild(el('span', 'text-muted small', '(no value needed)')); return; }
      var src = valueSource(row.field);
      if (row.op === 'gt' || row.op === 'lt') {
        var n = el('input', 'form-control form-control-sm', null, { type: 'number', step: 'any', 'aria-label': 'Value', placeholder: 'a number' });
        n.value = row.value; n.addEventListener('input', function () { row.value = n.value; changed(); }); cell.appendChild(n); return;
      }
      if (src && tsAvailable() && (row.op === 'eq' || row.op === 'ne' || row.op === 'in')) {
        var multi = row.op === 'in';
        var s = el('select', 'form-select form-select-sm', null, { 'aria-label': 'Value' });
        if (multi) { s.multiple = true; }
        cell.appendChild(s);
        var current = multi ? row.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean) : (row.value !== '' ? [row.value] : []);
        var opts = src.slice();
        current.forEach(function (c) { if (!opts.some(function (o) { return o.value === c; })) { opts.push({ value: c, text: c }); } });
        var ts = new window.TomSelect(s, {
          options: opts, items: current, maxItems: multi ? null : 1, plugins: multi ? ['remove_button'] : [], placeholder: multi ? 'Pick one or more' : 'Pick a value', openOnFocus: true,
          create: function (input) { input = input.trim(); return input && input.indexOf(',') < 0 ? { value: input, text: input } : false; }, createOnBlur: true, dropdownParent: 'body',
          onChange: function (v) { row.value = Array.isArray(v) ? v.join(',') : (v || ''); changed(); }
        });
        selects.push(ts);
        return;
      }
      var t = el('input', 'form-control form-control-sm', null, { type: 'text', 'aria-label': 'Value', maxlength: row.op === 'in' ? '500' : '200', placeholder: row.op === 'in' ? 'a, b, c' : (row.op === 'contains' ? 'text to look for' : 'value') });
      t.value = row.value; t.addEventListener('input', function () { row.value = t.value; changed(); }); cell.appendChild(t);
    }

    var rowEls = {};
    function hintRow(row) {
      var wrap = rowEls[row.id]; if (!wrap) { return; }
      var need = row.field && row.op !== 'is_empty' && String(row.value).trim() === '';
      wrap.classList.toggle('has-hint', !!need);
      var h = $('.er-row-hint', wrap);
      if (need && !h) { wrap.appendChild(el('div', 'er-row-hint', 'Enter a value to compare with: an empty value ' + (row.op === 'contains' ? 'never matches.' : 'only matches an empty field.'))); }
      else if (!need && h) { h.remove(); }
    }
    function rerenderRow(row) {
      var wrap = rowEls[row.id]; if (!wrap) { return; }
      var oc = $('.er-op', wrap), vc = $('.er-val', wrap);
      oc.textContent = '';
      var os = el('select', 'form-select form-select-sm', null, { 'aria-label': 'Operator' });
      allowedOps(row.field).forEach(function (o) { var op = el('option', null, OP_LABEL[o]); op.value = o; if (o === row.op) { op.selected = true; } os.appendChild(op); });
      os.addEventListener('change', function () { row.op = os.value; changed(); valueControl(row, vc); hintRow(row); });
      oc.appendChild(os);
      valueControl(row, vc);
      hintRow(row);
    }
    function buildRow(row, prefix) {
      var wrap = el('div', 'er-cond-row', null, { role: 'group', 'aria-label': 'Condition' });
      wrap.appendChild(el('span', 'er-join', prefix));
      var fc = el('div', 'er-fld'); var oc = el('div', 'er-op'); var vc = el('div', 'er-val');
      var rm = el('button', 'btn btn-sm btn-outline-secondary er-rm', null, { type: 'button', 'aria-label': 'Remove this condition', title: 'Remove this condition' });
      rm.appendChild(icon('fa-times'));
      rm.addEventListener('click', function () { st.rows = st.rows.filter(function (r) { return r !== row; }); changed(); render(); });
      wrap.appendChild(fc); wrap.appendChild(oc); wrap.appendChild(vc); wrap.appendChild(rm);
      rowEls[row.id] = wrap;
      fieldControl(row, fc);
      rerenderRow(row);
      return wrap;
    }
    function modeSelect(get, set, label) {
      var wrap = el('div', 'er-mode');
      var lab = el('label', null, label + ' ');
      var s = el('select', 'form-select form-select-sm d-inline-block w-auto');
      [['all', 'ALL'], ['any', 'ANY']].forEach(function (o) { var op = el('option', null, o[1]); op.value = o[0]; if (get() === o[0]) { op.selected = true; } s.appendChild(op); });
      var hint = el('span', 'er-mode-hint text-muted small');
      function showHint() { hint.textContent = s.value === 'all' ? 'of these must be true (AND)' : 'of these must be true (OR)'; }
      s.addEventListener('change', function () { set(s.value); showHint(); changed(); render(); });
      showHint();
      lab.appendChild(s); wrap.appendChild(lab); wrap.appendChild(document.createTextNode(' ')); wrap.appendChild(hint);
      return wrap;
    }
    function addBtn(text, ic, onClick, cls) {
      var b = el('button', cls || 'btn btn-sm btn-outline-secondary', null, { type: 'button' });
      b.appendChild(icon(ic)); b.appendChild(document.createTextNode(' ' + text));
      b.addEventListener('click', onClick);
      if (count() >= D.maxConditions) { b.disabled = true; b.title = 'At most ' + D.maxConditions + ' conditions'; }
      return b;
    }
    function render() {
      destroyAll(); rowEls = {}; root.textContent = '';
      var evLabel = EV ? EV.label : (S.trigger ? eventLabel(S.trigger) : 'event');
      var top = st.rows.filter(function (r) { return r.group === ''; });
      if (!st.rows.length && !st.groups.length) {
        var e = el('div', 'er-cond-empty');
        e.appendChild(icon('fa-infinity'));
        var t = el('span'); t.appendChild(document.createTextNode(' No conditions: runs for every ')); t.appendChild(el('strong', null, evLabel)); t.appendChild(document.createTextNode('.'));
        e.appendChild(t); root.appendChild(e);
      } else {
        var box = el('div', 'er-cbox');
        box.appendChild(modeSelect(function () { return st.mode; }, function (v) { st.mode = v; }, 'Match'));
        top.forEach(function (r, i) { box.appendChild(buildRow(r, i === 0 ? 'if' : (st.mode === 'all' ? 'and' : 'or'))); });
        st.groups.forEach(function (g, gi) {
          var gb = el('div', 'er-cbox er-cgroup');
          gb.appendChild(el('span', 'er-join er-join-group', top.length || gi > 0 ? (st.mode === 'all' ? 'and' : 'or') : 'if'));
          var head = el('div', 'er-cgroup-head');
          head.appendChild(modeSelect(function () { return st.groupModes[g] || 'any'; }, function (v) { st.groupModes[g] = v; }, 'Group: match'));
          var rg = el('button', 'btn btn-sm btn-outline-secondary', null, { type: 'button', 'aria-label': 'Remove this group' });
          rg.appendChild(icon('fa-trash')); rg.appendChild(document.createTextNode(' Remove group'));
          rg.addEventListener('click', function () { st.rows = st.rows.filter(function (r) { return r.group !== g; }); st.groups = st.groups.filter(function (x) { return x !== g; }); delete st.groupModes[g]; changed(); render(); });
          head.appendChild(rg); gb.appendChild(head);
          st.rows.filter(function (r) { return r.group === g; }).forEach(function (r, i) { gb.appendChild(buildRow(r, i === 0 ? 'if' : ((st.groupModes[g] || 'any') === 'all' ? 'and' : 'or'))); });
          gb.appendChild(addBtn('Add condition to this group', 'fa-plus', function () { st.rows.push({ id: ++uid, field: '', op: 'eq', value: '', group: g }); render(); focusLast(); }));
          box.appendChild(gb);
        });
        root.appendChild(box);
      }
      var bar = el('div', 'er-cbar');
      bar.appendChild(addBtn('Add condition', 'fa-plus', function () { st.rows.push({ id: ++uid, field: '', op: 'eq', value: '', group: '' }); render(); focusLast(); }, 'btn btn-sm btn-outline-secondary'));
      var free = D.groups.filter(function (g) { return st.groups.indexOf(g) < 0; })[0];
      if (free) {
        bar.appendChild(addBtn('Add group (any/all of several)', 'fa-layer-group', function () { st.groups.push(free); st.groups.sort(); st.groupModes[free] = 'any'; st.rows.push({ id: ++uid, field: '', op: 'eq', value: '', group: free }); render(); focusLast(); }, 'btn btn-sm btn-outline-secondary'));
      }
      if (typeof window.TomSelect !== 'function') {
        var dl = el('datalist', null, null, { id: 'er-fieldlist' });
        (EV ? EV.fields : []).forEach(function (f) { var o = el('option'); o.value = f.path; o.textContent = f.description || ''; dl.appendChild(o); });
        bar.appendChild(dl);
      }
      root.appendChild(bar);
      sync();
    }
    // Enter inside a condition row picks an option or confirms a value; it must not submit (save) the whole form.
    root.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); } });
    function focusLast() { var all = $$('.er-cond-row', root); var last = all[all.length - 1]; if (last) { var f = $('input, select', last); var c = $('.ts-control input', last); (c || f || {}).focus && (c || f).focus(); } }
    return { render: render, sync: sync, state: st };
  }());

  // ---- action picker --------------------------------------------------------------------------------------
  var actionBoxes = $$('.er-act-box', form);
  function currentAction() { var r = form.querySelector('input[name="action_type"]:checked'); return r ? r.value : ''; }
  function showAction() {
    var a = currentAction();
    actionBoxes.forEach(function (b) { b.hidden = b.getAttribute('data-for') !== a; });
    $$('.er-action-card', form).forEach(function (c) { c.classList.toggle('is-selected', c.getAttribute('data-action') === a); });
    updateTicketNote();
  }
  function updateTicketNote() {
    var note = $('.er-ticket-note', form), a = currentAction();
    var needs = D.ticketActions.indexOf(a) >= 0;
    var t = trigger();
    var ticketEvent = /^(ticket\.|catalog\.request_)/.test(t);
    if (note) { note.hidden = !(needs && !ticketEvent); }
  }
  $$('input[name="action_type"]', form).forEach(function (r) { r.addEventListener('change', function () { touch('action'); showAction(); Chips.refresh(); scheduleSummary(); }); });
  var af = document.getElementById('er-action-filter');
  if (af) {
    af.addEventListener('input', function () {
      var q = af.value.trim().toLowerCase();
      $$('.er-action-card', form).forEach(function (c) { c.hidden = q !== '' && c.getAttribute('data-search').indexOf(q) < 0 && !c.classList.contains('is-selected'); });
    });
  }
  // dependent sub-fields
  function toggleSub() {
    var mode = $('#cfg_as_mode'); if (mode) { $$('[data-as]', form).forEach(function (n) { n.hidden = n.getAttribute('data-as') !== mode.value; }); }
    var to = $('#cfg_mail_to'); if (to) { $$('[data-mail]', form).forEach(function (n) { n.hidden = n.getAttribute('data-mail') !== to.value; }); }
  }
  ['cfg_as_mode', 'cfg_mail_to'].forEach(function (id) { var n = document.getElementById(id); if (n) { n.addEventListener('change', toggleSub); } });

  // ---- placeholder chips + rendered preview for templated text ---------------------------------------------
  var Chips = (function () {
    var fields = $$('[data-er-template]', form);
    var wraps = [];
    fields.forEach(function (f) {
      var chips = el('div', 'er-chips', null, { 'aria-label': 'Insert a value from the event' });
      var prev = el('div', 'er-preview small');
      f.parentNode.insertBefore(chips, f.nextSibling);
      chips.parentNode.insertBefore(prev, chips.nextSibling);
      wraps.push({ f: f, chips: chips, prev: prev });
      f.addEventListener('input', function () { touch('action'); preview(); });
    });
    function render(text, ctx, missing) {
      return text.replace(/\{([A-Za-z0-9_.]{1,100})\}/g, function (m, k) {
        if (ctx && Object.prototype.hasOwnProperty.call(ctx, k)) { return String(ctx[k]).slice(0, 500); }
        missing.push(k); return '';
      });
    }
    function preview() {
      wraps.forEach(function (w) {
        w.prev.textContent = '';
        if (!w.f.value.trim() || w.f.closest('.er-act-box').hidden) { return; }
        var missing = [];
        var out = render(w.f.value, EV ? EV.context : null, missing);
        var line = el('div', 'er-preview-line');
        line.appendChild(el('span', 'text-muted', 'Preview with sample data: '));
        line.appendChild(el('span', 'er-preview-text', out.replace(/[\x00-\x1F]+/g, w.f.tagName === 'TEXTAREA' ? '\n' : ' ')));
        w.prev.appendChild(line);
        if (EV && missing.length) { w.prev.appendChild(el('div', 'text-warning-emphasis', '{' + missing.filter(function (v, i, a) { return a.indexOf(v) === i; }).join('}, {') + ' is not a field of this event and will be empty.')); }
      });
    }
    function insert(f, text) {
      f.focus();
      var s = typeof f.selectionStart === 'number' ? f.selectionStart : f.value.length, e = typeof f.selectionEnd === 'number' ? f.selectionEnd : s;
      f.value = f.value.slice(0, s) + text + f.value.slice(e);
      var pos = s + text.length; try { f.setSelectionRange(pos, pos); } catch (x) { /* ignore */ }
      f.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function refresh() {
      wraps.forEach(function (w) {
        w.chips.textContent = '';
        var list = EV ? EV.fields : [];
        if (!list.length) { return; }
        w.chips.appendChild(el('span', 'er-chips-l', 'Insert a value:'));
        list.forEach(function (fl) {
          var b = el('button', 'er-chip', '{' + fl.path + '}', { type: 'button', title: (fl.description || fl.path) + (fl.sample ? ' (e.g. ' + fl.sample + ')' : '') });
          b.addEventListener('click', function () { insert(w.f, '{' + fl.path + '}'); });
          w.chips.appendChild(b);
        });
      });
      preview();
    }
    return { refresh: refresh, preview: preview };
  }());

  // ---- live summary + validation --------------------------------------------------------------------------
  var sentenceEl = document.getElementById('er-sentence');
  var checks = {};
  $$('#er-checks li').forEach(function (li) { checks[li.getAttribute('data-check')] = li; });
  var lastProblems = {};
  var seq = 0;
  function setCheck(sec, state, text) {
    var li = checks[sec]; if (!li) { return; }
    li.className = 'is-' + state;
    var i = $('i', li); i.className = 'fas ' + (state === 'ok' ? 'fa-check-circle' : (state === 'bad' ? 'fa-exclamation-circle' : 'fa-circle'));
    var em = $('em', li); em.textContent = text;
  }
  function showProblems(p) {
    lastProblems = p || {};
    $$('.er-problem', form).forEach(function (n) {
      var sec = n.getAttribute('data-section');
      var msg = lastProblems[sec];
      var show = !!msg && touched[sec];
      n.hidden = !show; n.textContent = show ? msg : '';
    });
    var nameEl = document.getElementById('rule_name');
    if (nameEl) { nameEl.setAttribute('aria-invalid', lastProblems.name && touched.name ? 'true' : 'false'); }
    var t = trigger(), aName = '';
    var al = (D.actions.filter(function (a) { return a.key === currentAction(); })[0] || {}).label;
    var nCond = Builder.state.rows.filter(function (r) { return r.field; }).length;
    function put(sec, okText, todoText) {
      if (lastProblems[sec] && touched[sec]) { setCheck(sec, 'bad', lastProblems[sec]); }
      else if (lastProblems[sec]) { setCheck(sec, 'todo', todoText); }
      else { setCheck(sec, 'ok', okText); }
    }
    put('trigger', t ? eventLabel(t) : '', 'choose an event');
    put('conditions', nCond ? nCond + ' condition' + (nCond === 1 ? '' : 's') : 'every event (no conditions)', 'check the conditions');
    put('action', al || '', 'choose an action');
    put('name', (nameEl && nameEl.value.trim()) || '', 'give the rule a name');
    put('settings', 'ok', 'check the settings');
    // a suggestion for the name
    var sug = document.getElementById('er-suggest-name');
    if (sug && nameEl) {
      if (!nameEl.value.trim() && t && al) { aName = eventLabel(t) + ': ' + al; sug.hidden = false; $('span', sug).textContent = aName; sug.setAttribute('data-name', aName); }
      else { sug.hidden = true; }
    }
  }
  function summaryNow() {
    var my = ++seq;
    var p = toParams(new FormData(form));
    api('preview', p, true).then(function (r) {
      if (my !== seq) { return; }
      sentenceEl.textContent = r.summary;
      showProblems(r.problems);
    }, function (e) { if (my === seq) { sentenceEl.textContent = e.message; } });
  }
  var scheduleSummary = debounce(summaryNow, 300);
  var nameInput = document.getElementById('rule_name');
  if (nameInput) {
    nameInput.addEventListener('input', function () { touch('name'); scheduleSummary(); });
    nameInput.addEventListener('blur', function () { touch('name'); showProblems(lastProblems); });
  }
  var sug = document.getElementById('er-suggest-name');
  if (sug) { sug.addEventListener('click', function () { nameInput.value = sug.getAttribute('data-name') || ''; touch('name'); nameInput.focus(); scheduleSummary(); }); }
  ['er-sec-action', 'er-sec-settings'].forEach(function (id) {
    var sec = document.getElementById(id), name = id === 'er-sec-action' ? 'action' : 'settings';
    if (sec) { sec.addEventListener('input', function () { touch(name); scheduleSummary(); }); sec.addEventListener('change', function () { touch(name); scheduleSummary(); }); }
  });

  // ---- check against recent events (conditions only) -------------------------------------------------------
  var checkBtn = document.getElementById('er-check');
  var checkOut = document.getElementById('er-check-out');
  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      Builder.sync();
      if (!trigger()) { touch('trigger'); showProblems(lastProblems); toast('Choose the event first.', 'bad'); return; }
      checkOut.hidden = false; checkOut.textContent = ''; checkOut.appendChild(el('p', 'text-muted mb-0', 'Checking...'));
      api('check', new FormData(form), true).then(function (r) {
        checkOut.textContent = '';
        var head = el('p', 'mb-2');
        head.appendChild(el('strong', null, r.matched + ' of ' + r.total));
        head.appendChild(document.createTextNode(' recent events would match (' + r.basis + ').'));
        checkOut.appendChild(head);
        if (!r.total) { checkOut.appendChild(el('p', 'text-muted small mb-0', 'There are no recent events of this kind to check against yet.')); return; }
        var ul = el('ul', 'er-check-list');
        r.items.forEach(function (it) {
          var li = el('li', it.matched ? 'is-ok' : 'is-no');
          li.appendChild(icon(it.matched ? 'fa-check-circle' : 'fa-times-circle'));
          li.appendChild(document.createTextNode(' ' + it.label));
          if (!it.matched && it.failed.length) { li.appendChild(el('div', 'small text-muted er-fail', 'Not matched: ' + it.failed.map(function (t) { return t.replace(/\s+\(event has[\s\S]*$/, ''); }).join('; '))); }
          ul.appendChild(li);
        });
        checkOut.appendChild(ul);
      }, function (e) { checkOut.textContent = ''; checkOut.appendChild(el('div', 'alert alert-danger mb-0', e.message)); });
    });
  }

  // ---- test without saving / after saving ------------------------------------------------------------------
  function editorTest(opener, autorun) {
    Builder.sync();
    openTest({ ruleId: S.rule_id || 0, event: trigger(), name: (nameInput && nameInput.value.trim()) || '', form: function () { Builder.sync(); return new FormData(form); }, opener: opener, autorun: autorun });
  }
  var tb = document.getElementById('er-test-form');
  if (tb) { tb.addEventListener('click', function () { editorTest(tb, false); }); }

  // ---- submit: sync the builder, stop an obviously incomplete form with inline hints (the server is still the judge)
  form.addEventListener('submit', function (e) {
    Builder.sync();
    var miss = [];
    if (!trigger()) { miss.push('trigger'); }
    if (!currentAction()) { miss.push('action'); }
    if (!nameInput || !nameInput.value.trim()) { miss.push('name'); }
    if (miss.length) {
      e.preventDefault();
      ['trigger', 'conditions', 'action', 'name', 'settings'].forEach(touch);
      var msgs = { trigger: 'Choose the event that triggers the rule.', action: 'Choose what the rule does.', name: 'Give the rule a name (up to 200 characters).' };
      var p = {}; miss.forEach(function (m) { p[m] = msgs[m]; });
      showProblems(Object.assign({}, lastProblems, p));
      var target = document.getElementById(miss[0] === 'name' ? 'rule_name' : (miss[0] === 'trigger' ? 'er-sec-trigger' : 'er-sec-action'));
      if (target) { if (target.scrollIntoView) { target.scrollIntoView({ block: 'center' }); } if (target.focus && target.tagName === 'INPUT') { target.focus(); } }
    }
  });

  // ---- start up -------------------------------------------------------------------------------------------
  showAction(); toggleSub(); Builder.render(); Builder.sync();
  loadEvent();
  summaryNow();
  if (D.touched) { var es = document.getElementById('er-error-summary'); if (es) { es.focus(); } }
  if (/[?&]test=1\b/.test(window.location.search) && S.rule_id) { editorTest(null, false); }
}());
