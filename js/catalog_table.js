/*
 * Repeating-table questions on service catalog request forms. Clones the last row of a [data-catalog-table] block when
 * "Add row" is pressed (up to 25 rows) and renumbers the cells' names. The server drops blank rows and re-validates every
 * cell, so this script is only a convenience.
 */
(function () {
    'use strict';
    var MAX_ROWS = 25;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-catalog-table-add]') : null;
        if (!btn) { return; }
        var wrap = btn.closest('[data-catalog-table]');
        if (!wrap) { return; }
        var body = wrap.querySelector('tbody');
        var rows = body.querySelectorAll('[data-catalog-table-row]');
        if (rows.length >= MAX_ROWS) { return; }
        var clone = rows[rows.length - 1].cloneNode(true);
        var idx = rows.length;
        var cells = clone.querySelectorAll('input');
        for (var i = 0; i < cells.length; i++) {
            cells[i].value = '';
            var n = cells[i].getAttribute('name');
            if (n) { cells[i].setAttribute('name', n.replace(/\]\[\d+\]\[(\d+)\]$/, '][' + idx + '][$1]')); }
            var a = cells[i].getAttribute('aria-label');
            if (a) { cells[i].setAttribute('aria-label', a.replace(/ row \d+$/, ' row ' + (idx + 1))); }
        }
        body.appendChild(clone);
        if (rows.length + 1 >= MAX_ROWS) { btn.disabled = true; }
    });
})();
