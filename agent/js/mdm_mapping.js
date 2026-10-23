// ManageEngine MDM mapping screens (agent/mdm_devices.php?view=mapping and ?view=users).
//
//  - .mdm-typeahead: a search-as-you-type picker. data-kind="asset" asks /agent/ajax.php?mdm_search_assets, data-kind="contact" asks the
//    existing /agent/ajax.php?search_contacts (optionally narrowed to one department with data-client-id). The chosen id goes into the
//    hidden input named by data-target; typing again clears it. Nothing is submitted until the person presses a button.
//  - .mdm-suggest: a "Propose matches" suggestion; clicking it fills the row's picker (never links by itself).
//  - .mdm-select-all: ticks every row checkbox. [data-confirm] on a button asks before submitting.
(function () {
    'use strict';

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function setChoice(input, id, label) {
        var hidden = document.getElementById(input.getAttribute('data-target'));
        if (hidden) { hidden.value = id ? String(id) : ''; }
        input.value = label || '';
        input.classList.toggle('is-valid', !!id);
        // Map stays disabled until a contact is chosen
        var form = input.closest('form'), map = form && form.querySelector('.mdm-map');
        if (map) { map.disabled = !id; map.title = id ? '' : 'Choose a contact first'; }
    }

    function closeList(input) {
        var list = input._mdmList;
        if (list && list.parentNode) { list.parentNode.removeChild(list); }
        input._mdmList = null;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    // The list is a body-level popup positioned under the input (not inside the table cell), so it can never be clipped by the table
    // or stacked under the next row's controls.
    function placeList(input, list) {
        var r = input.getBoundingClientRect();
        var below = window.innerHeight - r.bottom, h = Math.min(list.scrollHeight, 300);
        list.style.left = Math.max(8, Math.min(r.left, window.innerWidth - Math.max(r.width, 320) - 8)) + 'px';
        list.style.minWidth = Math.max(r.width, 320) + 'px';
        if (below < h + 12 && r.top > below) { list.style.top = ''; list.style.bottom = (window.innerHeight - r.top + 4) + 'px'; }
        else { list.style.bottom = ''; list.style.top = (r.bottom + 4) + 'px'; }
    }

    function highlight(text, q) {
        var i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i < 0 || !q) { return document.createTextNode(text); }
        var f = document.createDocumentFragment(), m = document.createElement('mark');
        f.appendChild(document.createTextNode(text.slice(0, i)));
        m.textContent = text.slice(i, i + q.length); f.appendChild(m);
        f.appendChild(document.createTextNode(text.slice(i + q.length)));
        return f;
    }

    function openList(input, items) {
        closeList(input);
        var q = input.value.trim();
        var list = document.createElement('div');
        list.className = 'mdm-pick-list shadow';
        list.setAttribute('role', 'listbox');
        list.id = 'mdm-pick-' + Math.random().toString(36).slice(2);
        if (!items.length) {
            var none = document.createElement('div');
            none.className = 'mdm-pick-empty';
            none.textContent = 'No match for \u201c' + q + '\u201d';
            list.appendChild(none);
        }
        items.forEach(function (it, n) {
            var b = document.createElement('button');
            b.type = 'button';
            b.id = list.id + '-' + n;
            b.setAttribute('role', 'option');
            b.className = 'mdm-pick-item';
            var t = document.createElement('span'); t.className = 'mdm-pick-title'; t.appendChild(highlight(it.title || it.label, q));
            b.appendChild(t);
            var sub = [it.email, it.dept, it.note].filter(Boolean).join(' \u00b7 ');
            if (sub) { var s = document.createElement('span'); s.className = 'mdm-pick-sub'; s.appendChild(highlight(sub, q)); b.appendChild(s); }
            b.addEventListener('mousedown', function (e) {
                e.preventDefault();
                setChoice(input, it.id, it.title || it.label);
                closeList(input);
            });
            list.appendChild(b);
        });
        document.body.appendChild(list);
        placeList(input, list);
        input._mdmList = list;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', list.id);
    }

    function move(input, step) {
        var list = input._mdmList; if (!list) { return; }
        var opts = [].slice.call(list.querySelectorAll('.mdm-pick-item')); if (!opts.length) { return; }
        var cur = opts.findIndex(function (o) { return o.classList.contains('active'); });
        opts.forEach(function (o) { o.classList.remove('active'); o.removeAttribute('aria-selected'); });
        var next = (cur + step + opts.length) % opts.length; if (cur < 0) { next = step > 0 ? 0 : opts.length - 1; }
        opts[next].classList.add('active'); opts[next].setAttribute('aria-selected', 'true'); opts[next].scrollIntoView({ block: 'nearest' });
        input.setAttribute('aria-activedescendant', opts[next].id);
    }

    function search(input) {
        var q = input.value.trim();
        if (q.length < 2) { closeList(input); return; }
        var kind = input.getAttribute('data-kind');
        var url = kind === 'contact'
            ? '/agent/ajax.php?search_contacts=1&q=' + encodeURIComponent(q) + (input.getAttribute('data-client-id') ? '&client_id=' + encodeURIComponent(input.getAttribute('data-client-id')) : '')
            : '/agent/ajax.php?mdm_search_assets=1&q=' + encodeURIComponent(q);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (input.value.trim() !== q) { return; }
                var items = [];
                if (kind === 'contact') {
                    (data.contacts || []).forEach(function (c) {
                        items.push({ id: c.contact_id, title: c.contact_name, email: c.contact_email || '', dept: c.client_name || '', note: '' });
                    });
                } else {
                    (data.assets || []).forEach(function (a) {
                        items.push({ id: a.asset_id, label: a.label, note: a.linked_to ? 'already linked to ' + a.linked_to : '' });
                    });
                }
                openList(input, items);
            })
            .catch(function () { closeList(input); });
    }

    document.querySelectorAll('.mdm-typeahead').forEach(function (input) {
        var run = debounce(function () { search(input); }, 250);
        input.addEventListener('input', function () {
            setChoice(input, 0, input.value);   // typing invalidates the earlier pick
            run();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(input, 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(input, -1); }
            else if (e.key === 'Escape' && input._mdmList) { e.preventDefault(); e.stopPropagation(); closeList(input); }
            else if (e.key === 'Enter') {   // Enter picks the highlighted option; it must never submit the form's first button
                e.preventDefault();
                var act = input._mdmList && input._mdmList.querySelector('.mdm-pick-item.active');
                if (act) { act.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })); }
            }
        });
        window.addEventListener('scroll', function () { if (input._mdmList) { placeList(input, input._mdmList); } }, true);
        window.addEventListener('resize', function () { if (input._mdmList) { placeList(input, input._mdmList); } });
        input.addEventListener('blur', function () { setTimeout(function () { closeList(input); }, 150); });
    });

    document.addEventListener('click', function (e) {
        var s = e.target.closest('.mdm-suggest');
        if (s) {
            e.preventDefault();
            var input = document.getElementById(s.getAttribute('data-input'));
            if (input) { setChoice(input, s.getAttribute('data-asset-id'), s.getAttribute('data-label')); }
            return;
        }
        var all = e.target.closest('.mdm-select-all');
        if (all) {
            document.querySelectorAll('.mdm-row-check').forEach(function (c) { c.checked = all.checked; });
            return;
        }
        var btn = e.target.closest('button[data-confirm]');
        if (btn) { RivetDialog.gate(e, btn, btn.getAttribute('data-confirm')); }
    });
})();
