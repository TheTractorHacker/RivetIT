// CSAT face rating widget - accessibility enhancement only. The widget itself
// (selection, hover preview, submission) works with this file absent entirely;
// this only adds a live-region announcement for screen reader users, since the
// CSS-only highlight has no semantic meaning without it.
document.addEventListener('change', function (e) {
    var face = e.target.closest('.js-csat-rating-input');
    if (!face) return;

    var form = face.closest('.csat-form');
    var live = form ? form.querySelector('.js-csat-live') : null;
    if (live) {
        var label = face.dataset.label || (face.value + ' out of 5');
        live.textContent = 'You selected: ' + label + '.';
    }
});
