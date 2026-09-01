// Confirmation modal for a.confirm-link (Bootstrap 5 / vanilla)
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('confirmationModal');
    if (!modalEl) { return; }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.confirm-link, button.confirm-link');
        if (!link) { return; }
        e.preventDefault();

        var href = link.getAttribute('href');
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        var confirmBtn = document.getElementById('confirmSubmitBtn');
        if (confirmBtn) {
            // Replace the node to clear any previously-bound handler
            var fresh = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(fresh, confirmBtn);
            fresh.addEventListener('click', function () {
                if (href) {
                    window.location.href = href;
                } else if (link.form) {
                    // form.submit() does NOT include the activating submit
                    // button's name/value (there's no "clicked button" in a
                    // programmatic submit) - many handlers key off exactly
                    // that (isset($_POST['run_worker']) etc), so a plain
                    // submit() silently no-ops. requestSubmit(link) submits
                    // as if the button itself were clicked.
                    if (link.tagName === 'BUTTON' && typeof link.form.requestSubmit === 'function') {
                        link.form.requestSubmit(link);
                    } else {
                        link.form.submit();
                    }
                }
            });
        }

        modal.show();
    });
});
