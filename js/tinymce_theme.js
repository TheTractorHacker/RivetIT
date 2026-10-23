/* TinyMCE ignores the app theme: in dark mode every editor stayed a white box. Wrap tinymce.init once so each editor gets the
   matching skin and content stylesheet unless a page asks for its own. Loaded straight after tinymce.min.js. */
(function () {
  if (typeof tinymce === 'undefined' || tinymce.__rivetThemed) { return; }
  tinymce.__rivetThemed = true;
  var init = tinymce.init.bind(tinymce);
  tinymce.init = function (cfg) {
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    if (dark) { cfg = Object.assign({ skin: 'oxide-dark', content_css: 'dark' }, cfg || {}); }
    return init(cfg);
  };
})();
