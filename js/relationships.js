// Relationships: the "Link item" form's live search. Delegated listeners, so it works for the modal and for the inline form.
(function () {
  if (window.__relLoaded) { return; }
  window.__relLoaded = true;

  var timers = new WeakMap();
  // the department of the record being linked; 0 = a global record, where the picker spans departments and so names each one
  function formClient(form) { return parseInt(form.getAttribute('data-rel-client') || '0', 10) > 0; }

  function search(form) {
    var typeSel = form.querySelector('[data-rel-type-select]');
    var box = form.querySelector('[data-rel-search]');
    var results = form.querySelector('[data-rel-results]');
    if (!typeSel || !box || !results) { return; }
    var url = 'ajax.php?relationship_search=1&type=' + encodeURIComponent(typeSel.value) +
      '&client_id=' + encodeURIComponent(form.getAttribute('data-rel-client') || '0') +
      '&q=' + encodeURIComponent(box.value) +
      '&exclude_type=' + encodeURIComponent((form.querySelector('[name=src_type]') || {}).value || '') +
      '&exclude_id=' + encodeURIComponent((form.querySelector('[name=src_id]') || {}).value || '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        results.innerHTML = '';
        var items = (data && data.results) || [];
        if (!items.length) {
          var none = document.createElement('option');
          none.value = '';
          none.textContent = 'No matching records';
          results.appendChild(none);
          return;
        }
        items.forEach(function (it) {
          var o = document.createElement('option');
          o.value = it.id;
          o.textContent = it.name + (it.client_id ? (formClient(form) ? '' : ' (' + (it.client_name || 'department ' + it.client_id) + ')') : ' (global)');
          results.appendChild(o);
        });
      })
      .catch(function () { /* leave the list as it is */ });
  }

  function schedule(form) {
    clearTimeout(timers.get(form));
    timers.set(form, setTimeout(function () { search(form); }, 200));
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('[data-rel-search]')) { schedule(t.closest('form')); }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('[data-rel-type-select]')) { search(t.closest('form')); }
  });
  // A form that has just appeared (modal opened): fill the first list so there is something to pick from.
  var obs = new MutationObserver(function (muts) {
    muts.forEach(function (m) {
      m.addedNodes.forEach(function (n) {
        if (n.nodeType !== 1) { return; }
        var forms = n.matches && n.matches('form[data-rel-form]') ? [n] : (n.querySelectorAll ? n.querySelectorAll('form[data-rel-form]') : []);
        Array.prototype.forEach.call(forms, search);
      });
    });
  });
  obs.observe(document.body, { childList: true, subtree: true });
  document.querySelectorAll('form[data-rel-form]').forEach(search);
})();
