// Administration > Webhooks (admin/settings_webhooks.php): search the list and switch a webhook on or off without leaving the page.
(function () {
  'use strict';

  var search = document.querySelector('[data-whl-search]');
  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-whl-row]'));
  var none = document.querySelector('[data-whl-none]');
  var counter = document.querySelector('[data-whl-count]');
  var total = rows.length;

  function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.toLowerCase().trim(), shown = 0;
      rows.forEach(function (r) {
        var on = !q || (r.getAttribute('data-whl-text') || '').indexOf(q) !== -1;
        r.classList.toggle('d-none', !on);
        if (on) { shown++; }
      });
      if (none) { none.classList.toggle('d-none', shown > 0); }
      if (counter) { counter.textContent = q ? shown + ' of ' + plural(total, 'webhook') : plural(total, 'webhook') + '. Delivery counts show the last seven days.'; }
    });
    search.addEventListener('keydown', function (e) { if (e.key === 'Escape' && search.value) { search.value = ''; search.dispatchEvent(new Event('input')); e.stopPropagation(); } });
  }

  // The enable switch saves at once (the same handler as the Edit page); a failed save puts the switch back.
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.matches || !t.matches('[data-whl-toggle]')) { return; }
    var on = t.checked, row = t.closest('[data-whl-row]');
    var tokenInput = document.querySelector('input[name=csrf_token]');
    var fd = new FormData();
    fd.set('csrf_token', tokenInput ? tokenInput.value : (window.csrfToken || ''));
    fd.set('toggle_webhook', '1'); fd.set('wh_ajax', '1'); fd.set('webhook_id', t.getAttribute('data-id')); fd.set('enabled', on ? '1' : '0');
    t.disabled = true;
    fetch('post.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        t.disabled = false;
        if (!d.ok) { throw new Error((d.errors || ['Could not change that.'])[0]); }
        if (row) { row.classList.toggle('is-off', !on); }
        var st = row && row.querySelector('[data-whl-state]'); if (st) { st.textContent = on ? 'Enabled' : 'Disabled'; }
        t.setAttribute('aria-label', (on ? 'Enabled' : 'Disabled') + ': ' + (row ? row.querySelector('.whl-name').textContent : ''));
        if (counter) { counter.textContent = (on ? 'Enabled' : 'Disabled') + ' ' + (row ? row.querySelector('.whl-name').textContent : 'webhook') + '.'; }
      })
      .catch(function (err) {
        t.disabled = false; t.checked = !on;
        if (counter) { counter.textContent = 'Could not save that change: ' + err.message; }
      });
  });
})();
