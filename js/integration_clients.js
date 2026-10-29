// Administration > Integration clients: presets for the general-API scope table (no wildcard is ever sent; a preset only ticks the real boxes).
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ic-preset]');
    if (!btn) {
        return;
    }
    var wrap = btn.closest('.col-md-6, .col-lg-5, .col-12') || document;
    var preset = btn.getAttribute('data-ic-preset');
    wrap.querySelectorAll('.ic-scope-table input[data-ic-action]').forEach(function (box) {
        var action = box.getAttribute('data-ic-action');
        box.checked = preset === 'read' ? action === 'read' : (preset === 'readwrite' ? action !== 'delete' : false);
    });
});
