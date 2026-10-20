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
        if (counter) { counter.textContent = n === 0 ? 'Nothing selected.' : n + ' selected.'; }
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

    refresh();
})();
