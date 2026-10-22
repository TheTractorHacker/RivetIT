/*
 * Shell-wide accessible-name pass. Hundreds of server-rendered controls are icon-only (search/copy/row-action
 * buttons), bare checkboxes (select-all / bulk-select) or TomSelect-hidden <select>s with no <label>, and the
 * pages are far too many to retrofit one by one. This names them once, from information already on the page
 * (title, placeholder, icon, column header), and re-runs for content inserted later (modals, ajax tables).
 * It only ever ADDS a missing name; an existing aria-label/label/text is never touched.
 */
(function () {
    'use strict';

    var ICON_NAMES = [
        ['fa-search', 'Search'], ['fa-magnifying-glass', 'Search'], ['fa-plus', 'Add'], ['fa-trash', 'Delete'],
        ['fa-pencil', 'Edit'], ['fa-edit', 'Edit'], ['fa-pen', 'Edit'], ['fa-copy', 'Copy'], ['fa-clone', 'Copy'],
        ['fa-ellipsis', 'More actions'], ['fa-eye', 'View'], ['fa-times', 'Close'], ['fa-xmark', 'Close'],
        ['fa-arrow-right', 'Open'], ['fa-external-link', 'Open in new tab'], ['fa-download', 'Download'],
        ['fa-upload', 'Upload'], ['fa-sync', 'Refresh'], ['fa-redo', 'Refresh'], ['fa-print', 'Print'],
        ['fa-filter', 'Filter'], ['fa-cog', 'Settings'], ['fa-gear', 'Settings'], ['fa-star', 'Favourite'],
        ['fa-archive', 'Archive'], ['fa-key', 'Credentials'], ['fa-play', 'Start'], ['fa-check', 'Confirm']
    ];

    function hasName(el) {
        if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) { return true; }
        if (el.tagName !== 'SELECT' && el.tagName !== 'INPUT' && el.textContent && el.textContent.trim() !== '') { return true; }
        if (el.getAttribute('title')) { return true; }
        if (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]')) { return true; }
        if (el.closest && el.closest('label')) { return true; }
        return !!el.querySelector('img[alt]:not([alt=""])');
    }

    function iconName(el) {
        var icons = el.querySelectorAll('i[class*="fa"], svg');
        for (var i = 0; i < icons.length; i++) {
            var cls = icons[i].getAttribute('class') || '';
            for (var j = 0; j < ICON_NAMES.length; j++) {
                if (cls.indexOf(ICON_NAMES[j][0]) !== -1) { return ICON_NAMES[j][1]; }
            }
        }
        return '';
    }

    function nameButtonsAndLinks(root) {
        root.querySelectorAll('button, a[href], [role="button"]').forEach(function (el) {
            if (el.disabled || hasName(el)) { return; }
            var name = el.getAttribute('data-bs-original-title') || el.getAttribute('data-title') || '';
            if (!name && el.classList.contains('dropdown-toggle-split')) { name = 'More options'; }
            if (!name && el.getAttribute('data-bs-toggle') === 'dropdown') { name = 'Actions'; }
            if (!name && el.classList.contains('clipboardjs')) { name = 'Copy to clipboard'; }
            if (!name) { name = iconName(el); }
            if (!name && el.type === 'submit' && el.form) {
                var q = el.form.querySelector('input[type="search"], input[name="q"], input[name="query"]');
                if (q) { name = 'Search'; }
            }
            if (!name && el.tagName === 'A') {
                // an avatar/icon-only link to the same place as a text link in its row takes that link's text
                var row = el.closest('tr, .media, li, .card');
                var twin = row && el.getAttribute('href') && [].slice.call(row.querySelectorAll('a[href="' + el.getAttribute('href').replace(/"/g, '') + '"]')).filter(function (a) { return a !== el && a.textContent.trim() !== ''; })[0];
                if (twin) { name = twin.textContent.trim().replace(/\s+/g, ' ').slice(0, 60); }
            }
            if (name) { el.setAttribute('aria-label', name); }
        });
    }

    function placeholderOf(el) {
        return el.getAttribute('data-placeholder') || el.getAttribute('placeholder') || el.getAttribute('data-bs-placeholder') || '';
    }

    function nameCheckboxes(root) {
        root.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (el) {
            if (hasName(el)) { return; }
            var id = el.id || '';
            var name = '';
            if (id === 'selectAllCheckbox' || /select-?all/i.test(el.className + id)) { name = 'Select all rows'; }
            else if (/bulk-select|row-select|alert-chk|-chk\b/.test(el.className) || /\[\]$/.test(el.name || '')) { name = 'Select row'; }
            else if (el.name) { name = el.name.replace(/[\[\]_]+/g, ' ').trim(); }
            if (!name) {
                var host = el.closest('td, li, .form-check, .form-group, div');
                var t = host && host.textContent.replace(/\s+/g, ' ').trim();
                name = (t && t.length < 60) ? t : (el.className.match(/js-([a-z0-9-]+)/) || [])[1] || 'Toggle option';
                name = name.replace(/-/g, ' ');
            }
            if (name) { el.setAttribute('aria-label', name); }
        });
    }

    function nameSelects(root) {
        root.querySelectorAll('select').forEach(function (el) {
            if (hasName(el)) { return; }
            var name = placeholderOf(el);
            if (!name) {
                var first = el.options && el.options[0];
                if (first && first.value === '' && first.text.trim() !== '') { name = first.text.replace(/^[-\s]+|[-\s]+$/g, ''); }
            }
            if (!name && (el.name || el.id)) { name = (el.name || el.id).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[\[\]_-]+/g, ' ').trim(); }
            if (name) { el.setAttribute('aria-label', name.charAt(0).toUpperCase() + name.slice(1)); }
        });
        // Tom Select search input mirrors its <select>'s name
        root.querySelectorAll('.ts-wrapper').forEach(function (w) {
            var input = w.querySelector('.ts-control input');
            var sel = w.parentElement && w.parentElement.querySelector('select');
            if (input && !input.getAttribute('aria-label') && sel) {
                var n = sel.getAttribute('aria-label') || placeholderOf(sel) || (sel.name || '').replace(/[\[\]_]+/g, ' ').trim();
                if (n) { input.setAttribute('aria-label', n); }
            }
        });
    }

    function nameTableHeaders(root) {
        root.querySelectorAll('th').forEach(function (th) {
            if (th.textContent.trim() !== '' || th.getAttribute('aria-label') || th.querySelector('input,button,select,img,svg')) { return; }
            var span = document.createElement('span');
            span.className = 'visually-hidden';
            span.textContent = th.querySelector('input') ? 'Select' : 'Actions';
            // visually-hidden is position:absolute: without a positioned ancestor it escapes a .table-responsive scroll box and widens the page
            if (getComputedStyle(th).position === 'static') { th.style.position = 'relative'; }
            th.appendChild(span);
        });
    }

    function nameProgressBars(root) {
        root.querySelectorAll('[role="progressbar"]').forEach(function (el) {
            if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) { return; }
            var row = el.closest('.d-flex, tr, li, .card-body > div');
            var label = row && row.querySelector('.text-truncate, .small, span, a');
            var text = label ? label.textContent.trim() : '';
            el.setAttribute('aria-label', text || el.getAttribute('title') || 'Progress');
        });
    }

    // User-chosen tag/status colours arrive as inline background-color with a fixed text-light/text-dark class;
    // pick whichever of black/white actually reaches 4.5:1 on that colour.
    var colourCtx = null;
    function parseRgb(str) {
        // computed colours can come back as color(srgb ...) / oklch(): let the canvas normalise anything to rgb
        if (!str || str === 'transparent') { return null; }
        try {
            colourCtx = colourCtx || document.createElement('canvas').getContext('2d', { willReadFrequently: true });
            colourCtx.clearRect(0, 0, 1, 1);
            colourCtx.fillStyle = '#000'; colourCtx.fillStyle = str;
            colourCtx.fillRect(0, 0, 1, 1);
            var d = colourCtx.getImageData(0, 0, 1, 1).data;
            return d[3] === 0 ? null : [d[0], d[1], d[2], d[3] / 255];
        } catch (e) { return null; }
    }
    function lum(c) {
        var a = c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    function toHex(c) { return '#' + c.map(function (v) { return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2); }).join(''); }
    function fixBadgeContrast(root) {
        root.querySelectorAll('.badge[style*="background"], .badge[class*="bg-"], .badge[class*="badge-"]').forEach(function (el) {
            if (el.getAttribute('data-contrast-fixed')) { return; }
            var cs = getComputedStyle(el), bg = parseRgb(cs.backgroundColor), fg = parseRgb(cs.color);
            // translucent fills depend on what is behind them; only repair solid colours
            if (!bg || !fg || bg[3] < 0.95) { return; }
            el.setAttribute('data-contrast-fixed', '1');
            var ratio = function (l1, l2) { var hi = Math.max(l1, l2), lo = Math.min(l1, l2); return (hi + 0.05) / (lo + 0.05); };
            if (ratio(lum(bg), lum(fg)) >= 4.6) { return; }
            if (lum(fg) > 0.5) {
                // light text: keep the badge's hue, deepen the fill until white-ish text reaches 4.5:1
                var c = bg.slice(0, 3);
                for (var i = 0; i < 12 && ratio(lum(c), lum(fg)) < 4.6; i++) { c = c.map(function (v) { return v * 0.9; }); }
                el.style.setProperty('background-color', toHex(c), 'important');
            } else {
                el.style.setProperty('color', ratio(lum(bg), 0) >= ratio(lum(bg), 1) ? '#000' : '#fff', 'important');
            }
        });
    }

    function nameInputs(root) {
        root.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]), textarea').forEach(function (el) {
            if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]')) || (el.closest && el.closest('label'))) { return; }
            var lab = el.closest('.form-group, .mb-3, .col, div') && el.closest('.form-group, .mb-3, .col, div').querySelector('label');
            var n = el.getAttribute('title') || placeholderOf(el) || (lab && lab.textContent.trim()) || (el.id || el.name || '').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[\[\]_-]+/g, ' ').trim();
            if (n) { el.setAttribute('aria-label', n.charAt(0).toUpperCase() + n.slice(1)); }
        });
        // title-only controls: mirror the title into aria-label (a title alone is not a reliable name)
        root.querySelectorAll('input[title]:not([aria-label]), select[title]:not([aria-label])').forEach(function (el) {
            el.setAttribute('aria-label', el.getAttribute('title'));
        });
        root.querySelectorAll('.ts-control[role="combobox"]:not([aria-label])').forEach(function (c) {
            var sel = c.closest('.ts-wrapper') && c.closest('.ts-wrapper').parentElement.querySelector('select');
            var n = sel && (sel.getAttribute('aria-label') || placeholderOf(sel) || (sel.name || '').replace(/[\[\]_]+/g, ' ').trim());
            if (n) { c.setAttribute('aria-label', n); }
        });
        root.querySelectorAll('iframe.tox-edit-area__iframe:not([title])').forEach(function (f) { f.setAttribute('title', 'Rich text editor'); });
    }

    // Icons that carry an aria-label (tooltip status dots) are images to a screen reader; aria-label on a bare <i> is prohibited.
    function roleIcons(root) {
        root.querySelectorAll('i[aria-label]:not([role])').forEach(function (el) { el.setAttribute('role', 'img'); });
    }

    // Headings must descend one level at a time. Legacy cards use whatever tag looked right (h6 under h1, h3 tiles);
    // keep the tag and styling, correct only the exposed level (aria-level) where it would skip.
    function fixHeadingOrder() {
        var main = document.getElementById('main-content');
        if (!main) { return; }
        var prev = 0;
        [].slice.call(main.querySelectorAll('h1,h2,h3,h4,h5,h6,[role="heading"]')).forEach(function (h) {
            if (!h.offsetParent && getComputedStyle(h).position !== 'fixed') { return; }
            var level = parseInt(h.getAttribute('aria-level') || (/^H[1-6]$/.test(h.tagName) ? h.tagName.charAt(1) : '2'), 10);
            if (prev === 0) { level = 1; } else if (level > prev + 1) { level = prev + 1; }
            var native = /^H[1-6]$/.test(h.tagName) ? parseInt(h.tagName.charAt(1), 10) : 0;
            if (level !== native || h.getAttribute('aria-level')) { h.setAttribute('role', 'heading'); h.setAttribute('aria-level', String(level)); }
            prev = level;
        });
    }

    // Leftovers: dangling references and decorative/empty widgets (FullCalendar writes aria-controls="" on "+n more")
    function tidyAria(root) {
        root.querySelectorAll('a[aria-expanded]:not([role]):not([data-bs-toggle])').forEach(function (a) { a.setAttribute('role', 'button'); });
        root.querySelectorAll('[aria-controls=""]').forEach(function (el) { el.removeAttribute('aria-controls'); });
        root.querySelectorAll('[role="img"]:not([aria-label]):not([aria-labelledby])').forEach(function (el) {
            var t = el.getAttribute('title') || el.textContent.trim();
            if (t) { el.setAttribute('aria-label', t); } else { el.setAttribute('aria-hidden', 'true'); }
        });
        // a link whose only content is empty markup (e.g. a ticket with no department) is not a control
        root.querySelectorAll('a[href]').forEach(function (a) {
            if (!a.getAttribute('aria-label') && !a.getAttribute('title') && a.textContent.trim() === '' && !a.querySelector('img,svg,i,input')) {
                a.setAttribute('aria-hidden', 'true'); a.setAttribute('tabindex', '-1');
            }
        });
        root.querySelectorAll('input[disabled]:not([aria-label]), input[readonly]:not([aria-label])').forEach(function (el) {
            var g = el.closest('.input-group');
            var t = (g && g.querySelector('.input-group-text') && g.querySelector('.input-group-text').textContent.trim()) || el.value;
            if (t && !(el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]'))) { el.setAttribute('aria-label', t); }
        });
    }

    function run(root) {
        root = root || document;
        try {
            nameButtonsAndLinks(root); nameCheckboxes(root); nameSelects(root);
            nameTableHeaders(root); nameProgressBars(root); roleIcons(root); nameInputs(root); fixBadgeContrast(root); tidyAria(root); fixHeadingOrder();
        } catch (e) { /* a11y polish must never break a page */ }
    }

    var pending = false;
    function schedule() {
        if (pending) { return; }
        pending = true;
        setTimeout(function () { pending = false; run(document); }, 150);
    }

    function init() {
        run(document);
        if (window.MutationObserver) {
            new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-label'] });
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
