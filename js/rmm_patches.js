/*
 * RMM patch pages (the Patches tab of the asset page, the Patch approvals page; RivetCore 1.0.0-rc.20, Phase 4). Linked by includes/footer.php only on pages that set $rmm_pat_scripts.
 * Everything works without it: the tick boxes are plain checkboxes and every button is a submit. This only adds "select all" and keeps the Install button off until its confirmation box is ticked.
 * No window.confirm, no inline handlers; server text is never written as HTML.
 */
(function () {
    'use strict';

    // ---- the header checkbox ticks or clears every row checkbox of its form
    document.addEventListener('change', function (e) {
        var all = e.target && e.target.closest ? e.target.closest('[data-pat-all]') : null;
        if (!all) { return; }
        var form = document.querySelector(all.getAttribute('data-pat-all'));
        if (!form) { return; }
        form.querySelectorAll('input[type=checkbox][name="update_ids[]"]').forEach(function (c) { c.checked = all.checked; });
    });

    // ---- a row checkbox that is cleared un-ticks the header box
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (!c || c.name !== 'update_ids[]') { return; }
        var form = c.closest('form');
        var all = form ? form.querySelector('[data-pat-all]') : null;
        if (all && !c.checked) { all.checked = false; }
    });
})();
