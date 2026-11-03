/*
 * Portal home "How can we help?" box. Describe the problem in a few words: matching knowledge-base answers and service requests
 * appear under the box (client/help_suggest.php), and the last row always turns what was typed into a ticket with the subject
 * filled in (ticket_add.php?subject=). Without JavaScript the form still submits to the service catalog search.
 * All text from the server is placed with textContent.
 */
(function () {
    'use strict';
    var form = document.getElementById('portalHelpForm');
    if (!form) { return; }
    var input = form.querySelector('input[name="q"]');
    var panel = document.getElementById('portalHelpResults');
    var raise = document.getElementById('portalHelpRaise');
    if (!input || !panel) { return; }

    var seq = 0, timer = null, rows = [], active = -1;

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
    function ticketUrl(q) { return 'ticket_add.php?subject=' + encodeURIComponent(q); }
    function close() { panel.hidden = true; panel.textContent = ''; rows = []; active = -1; input.setAttribute('aria-expanded', 'false'); }

    function setActive(i) {
        if (!rows.length) { return; }
        if (rows[active]) { rows[active].classList.remove('is-active'); }
        active = (i + rows.length) % rows.length;
        rows[active].classList.add('is-active');
        rows[active].scrollIntoView({ block: 'nearest' });
    }

    function add(parent, icon, title, sub, href, cls) {
        var a = el('a', 'ph-row' + (cls ? ' ' + cls : ''));
        a.href = href; a.setAttribute('role', 'option');
        var i = el('span', 'ph-ic'); i.appendChild(el('i', 'fas ' + icon)); a.appendChild(i);
        var t = el('span', 'ph-tx'); t.appendChild(el('span', 'ph-title', title)); if (sub) { t.appendChild(el('span', 'ph-sub', sub)); } a.appendChild(t);
        a.addEventListener('mousemove', function () { var n = rows.indexOf(a); if (n !== active) { if (rows[active]) { rows[active].classList.remove('is-active'); } active = n; a.classList.add('is-active'); } });
        parent.appendChild(a); rows.push(a);
        return a;
    }

    function render(q, d) {
        panel.textContent = ''; rows = []; active = -1;
        var kb = (d && d.kb) || [], cat = (d && d.catalog) || [];
        if (kb.length) {
            panel.appendChild(el('div', 'ph-section', 'Quick answers'));
            kb.forEach(function (k) { add(panel, 'fa-book-open', k.title, k.category, k.url); });
        }
        if (cat.length) {
            panel.appendChild(el('div', 'ph-section', 'Request something'));
            cat.forEach(function (c) { add(panel, 'fa-concierge-bell', c.name, c.description, c.url); });
        }
        panel.appendChild(el('div', 'ph-section', kb.length || cat.length ? 'Still stuck?' : 'No ready answer found'));
        add(panel, 'fa-ticket-alt', 'Raise a ticket: “' + q + '”', 'Opens the form with this as the subject', ticketUrl(q), 'ph-raise');
        panel.hidden = false; input.setAttribute('aria-expanded', 'true');
        setActive(0);
    }

    function search(q) {
        var mine = ++seq;
        fetch('help_suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (mine === seq && input.value.trim() === q) { render(q, d && d.ok ? d : null); } })
            .catch(function () { if (mine === seq) { render(q, null); } });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        if (timer) { clearTimeout(timer); }
        if (raise) { raise.href = q ? ticketUrl(q) : 'ticket_add.php'; }
        if (q.length < 2) { seq++; close(); return; }
        timer = setTimeout(function () { search(q); }, 200);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); return; }
        if (panel.hidden) { return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter' && rows[active]) { e.preventDefault(); location.href = rows[active].href; }
    });
    form.addEventListener('submit', function (e) {
        var q = input.value.trim();
        if (!q) { return; }
        e.preventDefault();
        if (!panel.hidden && rows[active]) { location.href = rows[active].href; } else { location.href = ticketUrl(q); }
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) { close(); } });
})();


/* Phones: label table cells from their column header so css/itflow_portal.css can stack rows as cards. */
(function () {
    'use strict';
    var TITLES = /^(subject|name|course|title|document|domain|certificate|article)$/i;
    document.querySelectorAll('.table-responsive > table.table').forEach(function (t) {
        var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (h) { return h.textContent.trim(); });
        if (!heads.length) { return; }
        t.querySelectorAll('tbody tr').forEach(function (tr) {
            Array.prototype.forEach.call(tr.children, function (td, i) {
                if (td.tagName !== 'TD' || td.hasAttribute('colspan')) { return; }
                if (heads[i] === '#') { td.classList.add('portal-cell-num'); return; }
                if (TITLES.test(heads[i] || '') && !td.querySelector('button, input, select, form')) { td.classList.add('portal-cell-title'); }
                else {
                    td.setAttribute('data-label', heads[i] || '');
                    if (td.childNodes.length > 1) { var w = document.createElement('span'); w.className = 'portal-cell-val'; while (td.firstChild) { w.appendChild(td.firstChild); } td.appendChild(w); }
                }
            });
        });
    });
})();

/* New ticket: while the subject is typed, offer matching knowledge-base answers and requests (same endpoint as the home help box). */
(function () {
    'use strict';
    var subject = document.querySelector('input[name="subject"]');
    var box = document.getElementById('portalSubjectSuggest');
    if (!subject || !box) { return; }
    var timer = null, seq = 0;
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
    function render(d) {
        box.textContent = '';
        var items = [];
        ((d && d.kb) || []).forEach(function (k) { items.push(['fa-book-open', k.title, k.category, k.url]); });
        ((d && d.catalog) || []).forEach(function (c) { items.push(['fa-concierge-bell', c.name, 'Request', c.url]); });
        if (!items.length) { box.hidden = true; return; }
        box.appendChild(el('div', 'ps-head', 'Might already answer this'));
        items.slice(0, 5).forEach(function (it) {
            var a = el('a'); a.href = it[3]; a.target = '_blank'; a.rel = 'noopener';
            a.appendChild(el('i', 'fas fa-fw ' + it[0])); a.appendChild(el('span', null, it[1]));
            if (it[2]) { a.appendChild(el('span', 'ps-sub', it[2])); }
            box.appendChild(a);
        });
        box.hidden = false;
    }
    function run() {
        var q = subject.value.trim(), mine = ++seq;
        if (q.length < 3) { box.hidden = true; return; }
        fetch('help_suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (mine === seq) { render(d && d.ok ? d : null); } })
            .catch(function () { if (mine === seq) { box.hidden = true; } });
    }
    subject.addEventListener('input', function () { if (timer) { clearTimeout(timer); } timer = setTimeout(run, 250); });
})();

/* Ticket list: instant filter by subject or number. */
(function () {
    'use strict';
    var f = document.getElementById('ticketFilter'), t = document.getElementById('ticketTable'), none = document.getElementById('ticketNoMatch');
    if (!f || !t) { return; }
    f.addEventListener('input', function () {
        var q = f.value.trim().toLowerCase(), shown = 0;
        t.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.querySelector('td[colspan]')) { return; }
            var hit = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
            tr.hidden = !hit; if (hit) { shown++; }
        });
        if (none) { none.hidden = shown > 0 || !q; }
    });
})();
