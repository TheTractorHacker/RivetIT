// Ajax Modal Load Script (Bootstrap 5 / vanilla fetch)

// <script> elements inserted via innerHTML are inert per the HTML spec - the browser
// parses them into the DOM but never executes them. Since modal content below is
// injected via wrapper.innerHTML, every modal-local inline <script> (SLA hints,
// template autofill, tag/select wiring, etc.) would otherwise silently never run.
// This clones each script node into a freshly-created <script> element (which DOES
// execute when attached), preserving a valid CSP nonce along the way.
function executeInjectedScripts(container) {
  // window.CSP_NONCE is set once, server-side, in includes/footer.php - reading
  // a plain global is reliable, unlike trying to read another script tag's live
  // .nonce IDL property (which - at least in this app's testing - came back empty
  // and left every AJAX-modal script silently CSP-blocked).
  var pageNonce = window.CSP_NONCE || '';

  // Run scripts one at a time, in document order. A synchronous forEach isn't
  // enough: an external <script src> (e.g. a plugin) fetches in the background,
  // and a later INLINE script that depends on it would still run immediately on
  // the next loop iteration, before the plugin finishes loading. Each external
  // script's own load/error event is what advances to the next one; inline
  // scripts execute synchronously the moment they're attached, so they advance
  // immediately.
  var oldScripts = Array.prototype.slice.call(container.querySelectorAll('script'));

  function runNext(i) {
    if (i >= oldScripts.length) { return; }
    var oldScript = oldScripts[i];
    var newScript = document.createElement('script');
    for (var j = 0; j < oldScript.attributes.length; j++) {
      var attr = oldScript.attributes[j];
      if (attr.name.toLowerCase() !== 'nonce') {
        newScript.setAttribute(attr.name, attr.value);
      }
    }
    if (pageNonce) { newScript.nonce = pageNonce; }
    newScript.textContent = oldScript.textContent;

    if (newScript.src) {
      newScript.addEventListener('load', function () { runNext(i + 1); });
      newScript.addEventListener('error', function () { runNext(i + 1); });
      oldScript.replaceWith(newScript);
    } else {
      oldScript.replaceWith(newScript);
      runNext(i + 1);
    }
  }

  runNext(0);
}

// Reusable helper: fetch modal content via fetch() and show it in a Bootstrap 5 modal.
// options: { modalTab, onShown(modalEl), onHidden(), onError(error) }
window.openAjaxModal = function (modalUrl, modalSize, options) {
  options = options || {};
  modalSize = modalSize || 'md';
  const modalId = 'ajaxModal_' + Date.now();

  // Where dynamically-loaded modals live (AL4 renamed .content-wrapper -> .app-content)
  const host = document.querySelector('.app-content') || document.querySelector('.app-wrapper') || document.body;

  // Show a loading spinner while fetching content
  const spinner = document.createElement('div');
  spinner.id = 'modal-loading-spinner';
  spinner.className = 'text-center p-5';
  spinner.innerHTML = '<i class="fas fa-spinner fa-spin fa-2x text-muted"></i>';
  host.appendChild(spinner);

  fetch(modalUrl, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (response) {
      if (!response.ok) { throw new Error('HTTP ' + response.status); }
      return response.json();
    })
    .then(function (data) {
      spinner.remove();

      if (data.error) {
        alert(data.error);
        if (options.onError) { options.onError(data.error); }
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.innerHTML =
        '<div class="modal fade" id="' + modalId + '" tabindex="-1">' +
          '<div class="modal-dialog modal-' + modalSize + '">' +
            '<div class="modal-content border-dark">' +
              data.content +
            '</div>' +
          '</div>' +
        '</div>';
      const modalEl = wrapper.firstElementChild;
      host.appendChild(modalEl);
      executeInjectedScripts(modalEl);

      // Bootstrap tooltips/popovers only auto-init on elements present at the page's
      // own DOMContentLoaded (see js/app.js) - anything arriving later via this
      // innerHTML injection needs its own instance, or e.g. a credential's password
      // reveal popover silently does nothing when clicked inside an ajax-loaded modal.
      modalEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
      });
      modalEl.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        bootstrap.Popover.getOrCreateInstance(el, { container: 'body' });
      });

      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();

      // Optionally jump straight to a specific pill/tab inside the modal
      if (options.modalTab) {
        const tabTrigger = modalEl.querySelector('a[href="#' + options.modalTab + '"], [data-bs-target="#' + options.modalTab + '"]');
        if (tabTrigger) { bootstrap.Tab.getOrCreateInstance(tabTrigger).show(); }
      }

      // Auto-remove the modal element from the DOM once it's dismissed
      modalEl.addEventListener('hidden.bs.modal', function () {
        modalEl.remove();
        if (options.onHidden) { options.onHidden(); }
      });

      if (options.onShown) { options.onShown(modalEl); }
    })
    .catch(function (error) {
      const s = document.getElementById('modal-loading-spinner');
      if (s) { s.remove(); }
      alert('Error loading modal content. Please try again.');
      console.error('Modal fetch error:', error);
      if (options.onError) { options.onError(error); }
    });
};

document.addEventListener('click', function (e) {
  const trigger = e.target.closest('.ajax-modal');
  if (!trigger) { return; }
  e.preventDefault();

  // Prefer data-modal-url, fallback to href — read the live DOM attribute so
  // bulk_actions.js updates via setAttribute are honored.
  let modalUrl = trigger.getAttribute('data-modal-url') || trigger.getAttribute('href') || '#';
  const modalSize = trigger.getAttribute('data-modal-size') || 'md';
  const modalTab = trigger.getAttribute('data-modal-tab');

  if (!modalUrl || modalUrl === '#') {
    console.warn('ajax-modal: No modal URL found on trigger:', trigger);
    return;
  }

  window.openAjaxModal(modalUrl, modalSize, { modalTab: modalTab });
});
