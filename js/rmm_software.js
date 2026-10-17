/*
 * RMM software deployment pages (the deployment block of the Software tab, the catalog and assignments pages; RivetCore 1.0.0-rc.21, Phase 5). Linked by includes/footer.php only on
 * pages that set $rmm_sw_scripts. Everything works without it: every button is a plain submit and the server re-checks every confirmation. This only keeps the "Run for real" button off until
 * its confirmation box is ticked. No window.confirm, no inline handlers; server text is never written as HTML.
 */
(function () {
    'use strict';

    function sync(btn) {
        var box = document.querySelector(btn.getAttribute('data-sw-needs-confirm'));
        if (box) { btn.disabled = !box.checked; }
    }

    function bind() {
        document.querySelectorAll('[data-sw-needs-confirm]').forEach(function (btn) {
            var box = document.querySelector(btn.getAttribute('data-sw-needs-confirm'));
            if (!box || btn.getAttribute('data-sw-bound') === '1') { return; }
            btn.setAttribute('data-sw-bound', '1');
            box.addEventListener('change', function () { sync(btn); });
            sync(btn);
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind); } else { bind(); }
})();
