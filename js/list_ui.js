// Shared list UI: the status badge colour maths, mirrored from includes/list_ui.php (rivet_status_badge_vars).
(function () {
    function rgb(hex) {
        var h = String(hex || '').replace('#', '');
        if (h.length === 3) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
        if (!/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/.test(h)) { return null; }
        return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
    }
    function toHex(c) { return '#' + c.map(function (v) { return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2); }).join(''); }
    function mix(a, b, t) { return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]; }
    function lum(c) {
        var l = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        return 0.2126 * l(c[0]) + 0.7152 * l(c[1]) + 0.0722 * l(c[2]);
    }
    function contrast(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
    function vars(hex) {
        var c = rgb(hex);
        if (!c) { return ''; }
        c = c.slice(0, 3);
        var white = [255, 255, 255], black = [0, 0, 0], surface = [30, 38, 48], t;
        var bgL = mix(c, white, 0.86), fgL = c;
        for (t = 0; t <= 1.0001 && contrast(fgL, bgL) < 4.5; t += 0.05) { fgL = mix(c, black, t); }
        var bgD = mix(c, surface, 0.72), fgD = c;
        for (t = 0; t <= 1.0001 && contrast(fgD, bgD) < 4.5; t += 0.05) { fgD = mix(c, white, t); }
        return '--sb-dot:' + toHex(c) + ';--sb-bg-l:' + toHex(bgL) + ';--sb-fg-l:' + toHex(fgL) + ';--sb-bd-l:' + toHex(mix(c, white, 0.7)) +
            ';--sb-bg-d:' + toHex(bgD) + ';--sb-fg-d:' + toHex(fgD) + ';--sb-bd-d:' + toHex(mix(c, surface, 0.5));
    }
    function apply(el, label, hex) {
        var base = el.className.replace(/\bstatus-badge(-empty|-neutral)?\b/g, '').replace(/\s+/g, ' ').trim();
        el.textContent = '';
        el.removeAttribute('style');
        if (!label) {
            el.className = (base + ' status-badge status-badge-empty').trim();
            el.textContent = 'Set status';
            return;
        }
        var v = vars(hex);
        el.className = (base + ' status-badge' + (v ? '' : ' status-badge-neutral')).trim();
        if (v) { el.setAttribute('style', v); }
        var dot = document.createElement('span'); dot.className = 'status-badge-dot'; dot.setAttribute('aria-hidden', 'true');
        var txt = document.createElement('span'); txt.className = 'status-badge-label'; txt.textContent = label;
        el.appendChild(dot); el.appendChild(txt);
    }
    // Sortable column headers on every list: a faint sort icon on inactive columns, aria-sort, hover/focus styling.
    function enhanceSortHeaders() {
        document.querySelectorAll('table thead th a[href*="sort="]').forEach(function (a) {
            if (a.dataset.sortEnhanced || a.classList.contains('sort-link')) { return; }
            a.dataset.sortEnhanced = '1';
            a.classList.add('sort-link');
            var th = a.closest('th');
            var ic = a.querySelector('i.fa-sort-up, i.fa-sort-down, i.fa-sort');
            var dir = ic ? (ic.classList.contains('fa-sort-down') ? 'descending' : (ic.classList.contains('fa-sort-up') ? 'ascending' : 'none')) : 'none';
            if (th && !th.hasAttribute('aria-sort')) { th.setAttribute('aria-sort', dir); }
            if (ic) {
                ic.classList.add('sort-icon');
                if (dir !== 'none') { a.classList.add('is-active'); }
            } else {
                var i = document.createElement('i');
                i.className = 'fas fa-sort sort-icon';
                i.setAttribute('aria-hidden', 'true');
                a.appendChild(document.createTextNode(' '));
                a.appendChild(i);
            }
            if (!a.getAttribute('title')) { a.setAttribute('title', 'Sort by ' + a.textContent.trim()); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', enhanceSortHeaders); } else { enhanceSortHeaders(); }
    window.RivetListUi = { statusBadgeVars: vars, applyStatusBadge: apply, enhanceSortHeaders: enhanceSortHeaders };
})();
