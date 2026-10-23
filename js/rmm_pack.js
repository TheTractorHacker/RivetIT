/*
 * Starter policies card (agent/rmm_policies.php, markup: includes/rmm_pack_ui.php). Ticking only: "select recommended", "select all", "clear",
 * a live count, and the Plan/Install buttons staying unusable while nothing is ticked. Every decision (what is allowed, what is installed) is
 * Core's, on the server, on the next request. Linked by includes/footer.php only on the Policies list page for an administrator (it sets
 * $rmm_auto_extra_js). No inline handlers (the page CSP blocks them); server text is only ever written with textContent.
 */
(function () {
    'use strict';
    var form = document.getElementById('pack-form');
    if (!form) { return; }
    var picks = function () { return Array.prototype.slice.call(form.querySelectorAll('input.rmm-pack-pick')); };
    var counter = document.getElementById('pack-selected');
    var buttons = ['pack-plan', 'pack-install'].map(function (id) { return document.getElementById(id); }).filter(Boolean);

    function refresh() {
        var n = picks().filter(function (c) { return c.checked; }).length;
        if (counter) { counter.textContent = n === 0 ? 'Nothing selected' : n + ' selected'; }
        var lbl = document.getElementById('pack-install-label');
        if (lbl) { lbl.textContent = n === 0 ? 'Install selected' : 'Install selected (' + n + ')'; }
        buttons.forEach(function (b) {
            b.classList.toggle('disabled', n === 0);
            b.setAttribute('aria-disabled', n === 0 ? 'true' : 'false');
            if (n === 0) { b.setAttribute('title', 'Tick at least one item first'); } else { b.removeAttribute('title'); }
        });
    }

    form.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('rmm-pack-pick')) { refresh(); } });
    form.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-pack-select]') : null;
        if (b) {
            var mode = b.getAttribute('data-pack-select');
            picks().forEach(function (c) {
                if (mode === 'none') { c.checked = false; }
                else if (mode === 'all') { c.checked = c.getAttribute('data-select-all') === '1'; }
                else { c.checked = c.getAttribute('data-recommended') === '1'; }
            });
            refresh();
            return;
        }
        var act = e.target.closest ? e.target.closest('#pack-plan, #pack-install') : null;
        if (act && act.getAttribute('aria-disabled') === 'true') { e.preventDefault(); e.stopPropagation(); if (counter) { counter.focus && counter.focus(); } }
    }, true);

    // State filter (segmented control) and collapsible groups: pure show/hide of rows already on the page.
    var rows = function () { return Array.prototype.slice.call(form.querySelectorAll('#pack-items tbody tr[data-state]')); };
    var filter = 'all';
    function applyView() {
        rows().forEach(function (r) {
            var g = form.querySelector('.rmmx-group-toggle[data-pack-group="' + (r.getAttribute('data-group') || '').replace(/"/g, '') + '"]');
            var collapsed = g && g.getAttribute('aria-expanded') === 'false';
            r.hidden = collapsed || (filter !== 'all' && r.getAttribute('data-state') !== filter);
        });
        form.querySelectorAll('tr.rmmx-group').forEach(function (h) {
            var name = h.getAttribute('data-group-head');
            var any = rows().some(function (r) { return r.getAttribute('data-group') === name && (filter === 'all' || r.getAttribute('data-state') === filter); });
            h.hidden = !any;
        });
    }
    document.addEventListener('click', function (e) {
        var f = e.target.closest ? e.target.closest('[data-pack-filter]') : null;
        if (f) {
            filter = f.getAttribute('data-pack-filter');
            document.querySelectorAll('[data-pack-filter]').forEach(function (b) { var on = b === f; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
            applyView();
            return;
        }
        var g = e.target.closest ? e.target.closest('.rmmx-group-toggle') : null;
        if (g) { g.setAttribute('aria-expanded', g.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'); g.classList.toggle('is-collapsed'); applyView(); }
    });
    refresh();
})();
