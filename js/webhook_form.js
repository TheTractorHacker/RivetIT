// Administration > Webhooks: the copy buttons of the setup-guide code blocks (includes/webhook_guide.php), used by the guide slide-over on the
// Add / Edit webhook pages. Loaded on every admin page by includes/footer.php and bound by delegation on document, so it also works for
// guide HTML that is fetched later. The Add / Edit flow itself lives in js/webhook_wizard.js (loaded only by those pages); the list page in
// js/webhook_list.js; the Guides page has its own script.
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var copy = e.target.closest && e.target.closest('[data-wh-copy]');
    if (!copy) { return; }
    var src = document.querySelector(copy.getAttribute('data-wh-copy'));
    if (!src) { return; }
    var text = src.textContent;
    var done = function () { var old = copy.innerHTML; copy.textContent = 'Copied'; setTimeout(function () { copy.innerHTML = old; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
    var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { /* nothing to do */ }
    ta.remove();
  });
})();
