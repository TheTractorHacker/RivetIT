/* Client-side sorting for .rmmx-table headers with <button class="rmmx-sort" data-sort="key">: rows carry data-<key>; numeric when both values are numbers.
 * Updates aria-sort on the th, works with Enter/Space (it is a button), keeps the group order stable (stable sort). No inline handlers (CSP). */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.rmmx-sort') : null;
        if (!btn) { return; }
        var table = btn.closest('table'), th = btn.closest('th'), tbody = table.tBodies[0], key = btn.getAttribute('data-sort');
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        table.querySelectorAll('th[aria-sort]').forEach(function (x) { x.setAttribute('aria-sort', 'none'); var i = x.querySelector('i'); if (i) { i.className = 'fas fa-sort'; } });
        th.setAttribute('aria-sort', dir);
        var ic = btn.querySelector('i'); if (ic) { ic.className = 'fas fa-sort-' + (dir === 'ascending' ? 'up' : 'down'); }
        var rows = Array.prototype.slice.call(tbody.rows).map(function (r, i) { return { r: r, i: i, v: r.getAttribute('data-' + key) || '' }; });
        rows.sort(function (a, b) {
            var na = parseFloat(a.v), nb = parseFloat(b.v), c = (!isNaN(na) && !isNaN(nb)) ? na - nb : a.v.localeCompare(b.v, undefined, { numeric: true });
            return (dir === 'ascending' ? c : -c) || a.i - b.i;
        });
        rows.forEach(function (x) { tbody.appendChild(x.r); });
    });
})();
