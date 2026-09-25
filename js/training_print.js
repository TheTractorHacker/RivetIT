/*
 * Training print documents (certificate, printable transcript) - Phase 2 spec §5.1.
 * CSP forbids inline handlers, so the .js-print button is wired here.
 */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.js-print') : null;
        if (btn) {
            e.preventDefault();
            window.print();
        }
    });
})();
