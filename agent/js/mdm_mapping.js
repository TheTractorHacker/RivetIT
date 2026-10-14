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
    }

    function closeList(input) {
        var list = input._mdmList;
        if (list && list.parentNode) { list.parentNode.removeChild(list); }
        input._mdmList = null;
    }

    function openList(input, items) {
        closeList(input);
        var list = document.createElement('div');
        list.className = 'list-group position-absolute shadow';
        list.style.zIndex = 1080;
        list.style.maxHeight = '260px';
        list.style.overflowY = 'auto';
        list.style.minWidth = input.offsetWidth + 'px';
        if (!items.length) {
            var none = document.createElement('div');
            none.className = 'list-group-item small text-muted';
            none.textContent = 'No match';
            list.appendChild(none);
        }
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'list-group-item list-group-item-action small';
            b.textContent = it.label + (it.note ? '  [' + it.note + ']' : '');
            b.addEventListener('mousedown', function (e) {
                e.preventDefault();
                setChoice(input, it.id, it.label);
                closeList(input);
            });
            list.appendChild(b);
        });
        input.parentNode.style.position = 'relative';
        input.parentNode.appendChild(list);
        input._mdmList = list;
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
                        items.push({ id: c.contact_id, label: c.contact_name + (c.contact_email ? ' <' + c.contact_email + '>' : '') + (c.client_name ? ' - ' + c.client_name : ''), note: '' });
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
            if (e.key === 'Enter') { e.preventDefault(); }   // Enter must not submit the form's first button
        });
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
        if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
})();
