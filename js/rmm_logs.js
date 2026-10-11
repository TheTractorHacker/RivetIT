/*
 * RMM log pages (the Logs page, the Log rules page and the Logs tab of the asset page; RivetCore 1.0.0-rc.11, Phase 8). Linked by includes/footer.php only on pages that
 * set $rmm_log_scripts. Everything works without it: the message text is in <details> elements, the custom range inputs are always visible, the search is a plain GET form.
 * This only tidies: it hides the custom range until it is chosen and adds "show all messages". Server text is never written as HTML. No window.confirm, no inline handlers.
 */
(function () {
    'use strict';

    // ---- the custom range inputs matter only when "Custom range" is picked
    function syncRange(form) {
        var sel = form.querySelector('select[name$="range"]');
        if (!sel) { return; }
        var custom = sel.value === 'custom';
        form.querySelectorAll('.rmm-log-custom').forEach(function (box) {
            box.classList.toggle('d-none', !custom);
            box.querySelectorAll('input').forEach(function (i) { i.disabled = !custom; });
        });
    }
    document.querySelectorAll('[data-rmm-log-filter]').forEach(function (form) {
        syncRange(form);
        form.addEventListener('change', function (e) {
            if (e.target && e.target.name && /range$/.test(e.target.name)) {
                syncRange(form);
                if (e.target.value === 'custom') {
                    var first = form.querySelector('.rmm-log-custom input');
                    if (first) { first.focus(); }
                }
            }
        });
    });

    // ---- show / hide every long message at once
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rmm-log-toggle-all]');
        if (!b) { return; }
        e.preventDefault();
        var open = b.getAttribute('aria-pressed') !== 'true';
        var scope = b.closest('section, .tab-pane, body') || document;
        scope.querySelectorAll('details.rmm-log-msg').forEach(function (d) { d.open = open; });
        b.setAttribute('aria-pressed', open ? 'true' : 'false');
        var label = b.querySelector('span');
        if (label) { label.textContent = open ? 'Hide all messages' : 'Show all messages'; }
        var icon = b.querySelector('i');
        if (icon) { icon.className = 'fas ' + (open ? 'fa-compress-alt' : 'fa-expand-alt') + ' me-1'; }
    });
})();
