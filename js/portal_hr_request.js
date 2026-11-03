// Portal HR request form (client/hr_request.php): shows only the fields that belong to the chosen request type and disables the rest,
// so a hidden field is never posted. External file because the portal's Content-Security-Policy blocks inline scripts.
(function () {
    var sel = document.getElementById('hr_type');
    if (!sel) { return; }
    function apply() {
        var t = sel.value;
        document.querySelectorAll('[data-hr-show]').forEach(function (el) {
            var on = el.getAttribute('data-hr-show').split(' ').indexOf(t) !== -1;
            el.style.display = on ? '' : 'none';
            el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
        });
        document.querySelectorAll('[data-hr-label]').forEach(function (el) { el.style.display = el.getAttribute('data-hr-label') === t ? '' : 'none'; });
    }
    sel.addEventListener('change', apply);
    apply();
})();
