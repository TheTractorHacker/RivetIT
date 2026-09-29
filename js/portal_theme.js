/* Applies the visitor's saved portal colour mode before first paint (loaded
   synchronously in <head>). Sets html[data-bs-theme] now and body.dark-mode the
   moment <body> exists; no saved choice leaves the server-rendered default. */
(function () {
    var mode = null;
    try { mode = window.localStorage.getItem('portal_theme'); } catch (e) { /* blocked storage */ }
    if (mode !== 'dark' && mode !== 'light') { return; }
    var root = document.documentElement;
    root.setAttribute('data-bs-theme', mode);
    function paintBody() {
        if (!document.body) { return false; }
        document.body.classList.toggle('dark-mode', mode === 'dark');
        return true;
    }
    if (!paintBody()) {
        var mo = new MutationObserver(function () { if (paintBody()) { mo.disconnect(); } });
        mo.observe(root, { childList: true });
    }
})();
