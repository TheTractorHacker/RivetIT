// Auto-open a modal when its id is present in the URL (Bootstrap 5 / vanilla)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (modalEl) {
        if (!modalEl.id) { return; }
        if (window.location.href.indexOf('#' + modalEl.id) !== -1) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });
});
