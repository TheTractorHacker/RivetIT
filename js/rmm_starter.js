/*
 * Starter pack page (agent/rmm_starter_pack.php, markup: includes/rmm_starter_ui.php). Ticking only: "select all shown", "select read-only", "clear", a live count, and the
 * action buttons staying unusable while nothing is ticked. Every decision (what is allowed, what is installed) is Core's, on the server, on the next request. Linked by
 * includes/footer.php only on a page that set $rmm_auto_extra_js. No inline handlers (the page CSP blocks them); server text is only ever written with textContent.
 */
(function () {
    'use strict';
    var form = document.getElementById('starter-form');
    if (!form) { return; }
    var picks = function () { return Array.prototype.slice.call(form.querySelectorAll('input.starter-pick')); };
    var all = document.getElementById('starter-all');
    var counter = document.getElementById('starter-selected');
    var buttons = ['starter-plan', 'starter-install', 'starter-uninstall'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
    var installForbidden = {};   // buttons the server rendered disabled stay disabled
    buttons.forEach(function (b) { installForbidden[b.id] = b.hasAttribute('disabled'); });

    function refresh() {
        var list = picks(), n = list.filter(function (c) { return c.checked; }).length;
        if (counter) { counter.textContent = n === 0 ? 'Nothing selected' : n + ' selected'; }
        var lbl = document.getElementById('starter-install-label');
        if (lbl) { lbl.textContent = n === 0 ? 'Install selected' : 'Install selected (' + n + ')'; }
        buttons.forEach(function (b) {
            var off = n === 0 || installForbidden[b.id];
            if (installForbidden[b.id]) { return; }
            b.classList.toggle('disabled', off);
            b.setAttribute('aria-disabled', off ? 'true' : 'false');
            if (off) { b.setAttribute('data-title', b.getAttribute('title') || ''); b.setAttribute('title', 'Tick at least one item first'); } else if (b.hasAttribute('data-title')) { b.setAttribute('title', b.getAttribute('data-title')); }
        });
        if (all) {
            var scripts = list.filter(function (c) { return c.getAttribute('data-risk') !== 'policy'; });
            var on = scripts.filter(function (c) { return c.checked; }).length;
            all.checked = scripts.length > 0 && on === scripts.length;
            all.indeterminate = on > 0 && on < scripts.length;
        }
    }

    form.addEventListener('change', function (e) { if (e.target && e.target.classList && (e.target.classList.contains('starter-pick') || e.target.id === 'starter-all')) {
        if (e.target.id === 'starter-all') { picks().forEach(function (c) { if (c.getAttribute('data-risk') !== 'policy') { c.checked = e.target.checked; } }); }
        refresh();
    } });
    form.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-starter-select]') : null;
        if (b) {
            var mode = b.getAttribute('data-starter-select');
            picks().forEach(function (c) {
                if (mode === 'none') { c.checked = false; }
                else if (mode === 'all') { c.checked = c.getAttribute('data-risk') !== 'policy'; }
                else { c.checked = c.getAttribute('data-risk') === mode; }
            });
            refresh();
            return;
        }
        var act = e.target.closest ? e.target.closest('#starter-plan, #starter-install, #starter-uninstall') : null;
        if (act && act.getAttribute('aria-disabled') === 'true' && !act.hasAttribute('disabled')) { e.preventDefault(); e.stopPropagation(); if (counter) { counter.focus && counter.focus(); } }
    }, true);
    refresh();
})();
