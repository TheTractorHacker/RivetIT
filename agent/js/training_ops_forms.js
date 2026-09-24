/*
 * Training operations: shared helpers and the offcanvas forms (Phase 2 spec §5.2, Lane E).
 *
 *   TrainingOps.init(pageData)            call first from each page (today, level, courses, …)
 *   TrainingOps.open(kind, opts) -> Promise(result|null)
 *       kind: assign | extend | waive | void | hire_date | roster | history | external | evaluation
 *   TrainingOps.sheet(opts)               the single page-level Bootstrap offcanvas (.offcanvas-end)
 *   TrainingOps.PeoplePicker(opts)        people_search combobox with chips
 *   TrainingOps.ScanField(opts)           evidence scan upload (attach token + preview)
 *   TrainingOps.u                         formatters, chips, person cells, states, data adapter
 *
 * Data adapter: pages embed {action, params, state, data|error} from the in-process route
 * call (agent/includes/training_ops/ops.php). u.load() uses the data when present and
 * otherwise fetches the same action, so the pages work unchanged once the engines land.
 *
 * Rules: every string reaches the DOM through textContent / DOM nodes (TrainingUi.el);
 * TomSelect renderers escape(); no inline handlers; POSTs go through TrainingApi (CSRF
 * header, one csrf retry) and are retried once on 409 busy; creates carry a client
 * request_uid that is re-used on retry.
 */
(function () {
    'use strict';

    var UI = window.TrainingUi;
    var Api = window.TrainingApi;
    var el = UI.el;

    var S = {
        today: null,
        level: 0,
        userId: 0,
        courses: [],
        departments: [],
        settings: { due_soon_days: 30, reissue_days: 14, evidence_max_bytes: 20 * 1048576 },
        links: { transcript: true, certificate: true, record: true },
        routes: {}
    };

    // ------------------------------------------------------------------------------------
    // Dates (business dates are local Y-m-d strings from the server; never the browser zone)
    // ------------------------------------------------------------------------------------

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function parseYmd(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function toYmd(ms) {
        var d = new Date(ms);
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
    }
    function isYmd(s) { return parseYmd(s) !== null && toYmd(parseYmd(s)) === s; }
    function addDays(s, n) { var t = parseYmd(s); return t === null ? null : toYmd(t + n * 86400000); }
    function addMonths(s, n) {
        var t = parseYmd(s);
        if (t === null) { return null; }
        var d = new Date(t);
        var y = d.getUTCFullYear(), m = d.getUTCMonth() + n, day = d.getUTCDate();
        var last = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
        return toYmd(Date.UTC(y, m, Math.min(day, last)));
    }
    /** b - a in days. */
    function diffDays(a, b) {
        var ta = parseYmd(a), tb = parseYmd(b);
        return (ta === null || tb === null) ? null : Math.round((tb - ta) / 86400000);
    }
    function today() {
        if (S.today) { return S.today; }
        var d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    var DF = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
    var DF_SHORT = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
    var DTF = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    function fmtDate(s, short) {
        var t = parseYmd(s);
        if (t === null) { return ''; }
        if (short && new Date(t).getUTCFullYear() === new Date(parseYmd(today())).getUTCFullYear()) { return DF_SHORT.format(new Date(t)); }
        return DF.format(new Date(t));
    }
    /** ISO-8601 with offset, or a 'Y-m-d H:i:s[.v]' UTC string. */
    function parseTime(v) {
        if (!v) { return null; }
        var s = String(v);
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)) { s = s.replace(' ', 'T') + 'Z'; }
        var t = Date.parse(s);
        return isNaN(t) ? null : new Date(t);
    }
    function fmtDateTime(v) { var d = parseTime(v); return d ? DTF.format(d) : ''; }
    function relTime(v) {
        var d = parseTime(v);
        if (!d) { return ''; }
        var s = Math.round((Date.now() - d.getTime()) / 1000);
        var fut = s < 0;
        s = Math.abs(s);
        var out;
        if (s < 45) { return 'just now'; }
        if (s < 3600) { out = Math.round(s / 60) + ' min'; }
        else if (s < 86400) { out = Math.round(s / 3600) + ' h'; }
        else if (s < 86400 * 30) { var dd = Math.round(s / 86400); out = dd + (dd === 1 ? ' day' : ' days'); }
        else { return fmtDateTime(v); }
        return fut ? 'in ' + out : out + ' ago';
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }

    // ------------------------------------------------------------------------------------
    // Small DOM pieces
    // ------------------------------------------------------------------------------------

    function icon(cls) { return el('i', { class: cls, 'aria-hidden': 'true' }); }
    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) { return '?'; }
        var a = parts[0].charAt(0), b = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (a + b).toUpperCase();
    }
    function avatar(name, size) {
        return el('span', { class: 'tro-avatar' + (size ? ' tro-avatar--' + size : ''), 'aria-hidden': 'true', text: initials(name) });
    }
    function chip(text, variant, iconCls, attrs) {
        var a = Object.assign({ class: 'tro-chip' + (variant ? ' tro-chip--' + variant : '') }, attrs || {});
        return el('span', a, [iconCls ? icon(iconCls) : null, el('span', { text: text })]);
    }
    function uid32() {
        var b = new Uint8Array(16);
        window.crypto.getRandomValues(b);
        return Array.prototype.map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
    }
    function transcriptUrl(contactId) { return '/agent/training_transcript.php?contact_id=' + encodeURIComponent(contactId); }
    function recordUrl(id) { return '/agent/training_record.php?id=' + encodeURIComponent(id); }
    function certificateUrl(id) { return '/agent/training_certificate.php?id=' + encodeURIComponent(id); }

    function personMeta(p) {
        if (!p) { return ''; }
        var bits = [];
        if (p.title) { bits.push(p.title); }
        bits.push(p.department && p.department.name ? p.department.name : 'No department');
        return bits.join(' · ');
    }
    /** Avatar + name (link to the transcript) + "title · department". */
    function personCell(p, opts) {
        opts = opts || {};
        p = p || {};
        var name = p.name || p.current_name || ('Person #' + (p.contact_id || '?'));
        var nameNode = (opts.link !== false && p.contact_id && S.links.transcript)
            ? el('a', { class: 'tro-link-strong', href: transcriptUrl(p.contact_id), text: name })
            : el('span', { class: 'fw-semibold', text: name });
        var sub = opts.sub !== undefined ? opts.sub : personMeta(p);
        return el('div', { class: 'tro-person' }, [
            avatar(name, opts.size || 'sm'),
            el('div', { class: 'tro-person__text' }, [nameNode, sub ? el('span', { class: 'tro-sub', text: sub }) : null,
                p.archived ? chip('Archived', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm mt-1' }) : null])
        ]);
    }

    var STATUS = {
        overdue: ['err', 'fas fa-exclamation-circle', 'Overdue'],
        due_soon: ['warn', 'far fa-clock', 'Due soon'],
        due: ['info', null, 'Assigned'],
        completed: ['ok', 'fas fa-check', 'Completed'],
        waived: ['outline', 'fas fa-pause', 'Waived'],
        cancelled: ['outline', null, 'Cancelled']
    };
    /** Assignment display_status chip; overdue adds the day count. */
    function statusChip(status, row, opts) {
        var s = STATUS[status] || ['outline', null, String(status || '')];
        var label = s[2];
        if (status === 'overdue' && row && row.days_overdue > 0 && !(opts && opts.days === false)) { label = 'Overdue ' + plural(row.days_overdue, 'day'); }
        if (status === 'waived' && row && row.waived_until) { label = 'Waived to ' + fmtDate(row.waived_until, true); }
        return chip(label, s[0], s[1]);
    }
    /** Certificate status chip (PairRules::certStatus). */
    function certChip(status, label) {
        var map = { valid: ['ok', 'fas fa-check'], expiring: ['warn', 'far fa-clock'], expired: ['err', 'fas fa-times-circle'], revoked: ['outline', 'fas fa-ban'] };
        var m = map[status] || ['outline', null];
        return chip(label || (status ? status.charAt(0).toUpperCase() + status.slice(1) : '—'), m[0], m[1]);
    }
    var STRENGTH_LABELS = { A: 'PIN + signature', B: 'Trainer session', C: 'Trainer attests', D: 'Scan on file', E: 'Recorded by office' };
    var STRENGTH_BARS = { A: 5, B: 4, C: 3, D: 2, E: 1 };
    function strength(letter, label) {
        letter = String(letter || '').toUpperCase();
        var bars = el('span', { class: 'tro-strength__bars', 'aria-hidden': 'true' });
        for (var i = 1; i <= 5; i++) { bars.appendChild(el('span', { class: i <= (STRENGTH_BARS[letter] || 0) ? 'is-on' : null })); }
        return el('span', { class: 'tro-strength', title: 'Evidence strength ' + letter }, [
            el('span', { class: 'tro-strength__letter', text: letter || '?' }), bars,
            el('span', { text: label || STRENGTH_LABELS[letter] || '' })
        ]);
    }
    var METHOD_LABELS = { online: 'Online course', session: 'Instructor-led session', blended: 'Blended', evaluation: 'Practical evaluation', external: 'External card', legacy_paper: 'Paper record' };

    function courseSummary(c) {
        if (!c) { return ''; }
        var bits = [];
        if (c.revision_number) { bits.push('Version ' + c.revision_number); }
        if (c.est_minutes) { bits.push('about ' + c.est_minutes + ' min'); }
        if (c.kind === 'document') { bits.push('read and sign'); }
        else if (c.has_exam) { bits.push('quiz, pass mark ' + (c.pass_pct || 0) + '%'); }
        var needs = c.needs || {};
        if (needs.session) { bits.push('classroom session'); }
        if (needs.practical) { bits.push('practical evaluation'); }
        if (needs.external_only) { bits.push('outside card'); }
        bits.push(c.validity_months ? 'valid ' + plural(c.validity_months, 'month') : 'does not expire');
        return bits.join(' · ');
    }
    function courseById(id) {
        id = Number(id);
        for (var i = 0; i < S.courses.length; i++) { if (Number(S.courses[i].id) === id) { return S.courses[i]; } }
        return null;
    }
    /** <select> of published courses grouped by kind. filter(card) narrows it. */
    function courseSelect(opts) {
        opts = opts || {};
        var sel = el('select', { class: 'form-select', id: opts.id || null, name: opts.name || 'course_id', required: opts.required ? true : null });
        sel.appendChild(el('option', { value: '', text: opts.placeholder || 'Choose a course…' }));
        var groups = { training: el('optgroup', { label: 'Training courses' }), document: el('optgroup', { label: 'Documents to sign' }) };
        var list = S.courses.filter(function (c) { return !opts.filter || opts.filter(c); });
        if (opts.sort) { list = list.slice().sort(opts.sort); }
        list.forEach(function (c) {
            var o = el('option', { value: String(c.id), text: c.name + (c.code ? ' (' + c.code + ')' : '') });
            if (Number(opts.value) === Number(c.id)) { o.selected = true; }
            (groups[c.kind] || groups.training).appendChild(o);
        });
        ['training', 'document'].forEach(function (k) { if (groups[k].children.length) { sel.appendChild(groups[k]); } });
        return sel;
    }

    function emptyState(o) {
        return el('div', { class: 'tro-empty' }, [
            el('div', { class: 'tro-empty__icon' }, [icon(o.icon || 'fas fa-inbox')]),
            el('p', { class: 'tro-empty__title', text: o.title || 'Nothing here yet' }),
            o.text ? el('p', { class: 'tro-empty__text', text: o.text }) : null,
            (o.actions && o.actions.length) ? el('div', { class: 'tro-empty__actions' }, o.actions) : null
        ]);
    }
    function skeletonRows(tbody, cols, n) {
        while (tbody.firstChild) { tbody.removeChild(tbody.firstChild); }
        var widths = ['tro-skel--w80', 'tro-skel--w60', 'tro-skel--w40'];
        for (var i = 0; i < (n || 6); i++) {
            var tr = el('tr', { class: 'tro-skel-row', 'aria-hidden': 'true' });
            for (var j = 0; j < cols; j++) { tr.appendChild(el('td', {}, [el('span', { class: 'tro-skel ' + widths[(i + j) % 3] })])); }
            tbody.appendChild(tr);
        }
    }
    function errorText(err) {
        if (!err) { return 'Something went wrong. Try again.'; }
        if (err.code === 'unavailable') { return 'This part of Training is still being installed.'; }
        return err.message || 'Something went wrong. Try again.';
    }
    /** A state block for a failed load: not found, not installed yet, or retry. */
    function failState(err, retry) {
        if (err && err.code === 'not_found') {
            return emptyState({ icon: 'fas fa-search', title: 'Not found', text: 'It may have been removed, or it belongs to a department you do not have access to.' });
        }
        if (err && err.code === 'unavailable') {
            return emptyState({ icon: 'fas fa-tools', title: 'Not available yet', text: 'This part of Training is still being installed. It appears here after the next update.' });
        }
        return emptyState({
            icon: 'fas fa-exclamation-triangle', title: 'Could not load this', text: errorText(err),
            actions: retry ? [el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Try again', on: { click: retry } })] : []
        });
    }
    function busy(btn, on, label) {
        if (!btn) { return; }
        if (on) {
            btn.dataset.troLabel = btn.dataset.troLabel || btn.textContent;
            btn.disabled = true;
            while (btn.firstChild) { btn.removeChild(btn.firstChild); }
            btn.appendChild(el('span', { class: 'spinner-border spinner-border-sm me-2', 'aria-hidden': 'true' }));
            btn.appendChild(document.createTextNode(label || btn.dataset.troLabel));
        } else {
            btn.disabled = false;
            if (btn.dataset.troLabel) {
                while (btn.firstChild) { btn.removeChild(btn.firstChild); }
                btn.appendChild(document.createTextNode(label || btn.dataset.troLabel));
                delete btn.dataset.troLabel;
            }
        }
    }
    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    /** Row kebab menu: items are {label, icon, onClick|href, danger} or '-' (divider); null items are skipped. */
    function kebab(label, items) {
        items = (items || []).filter(Boolean);
        while (items.length && items[0] === '-') { items.shift(); }
        while (items.length && items[items.length - 1] === '-') { items.pop(); }
        if (!items.length) { return null; }
        var menu = el('ul', { class: 'dropdown-menu dropdown-menu-end' }, items.map(function (it) {
            if (it === '-') { return el('li', {}, [el('hr', { class: 'dropdown-divider' })]); }
            var node = it.href
                ? el('a', { class: 'dropdown-item' + (it.danger ? ' text-danger' : ''), href: it.href }, [icon(it.icon + ' fa-fw me-2'), it.label])
                : el('button', { type: 'button', class: 'dropdown-item' + (it.danger ? ' text-danger' : ''), on: { click: it.onClick } }, [icon(it.icon + ' fa-fw me-2'), it.label]);
            return el('li', {}, [node]);
        }));
        return el('div', { class: 'dropdown' }, [
            el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary btn-icon', 'aria-label': label, 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown', bsPopperConfig: '{"strategy":"fixed"}' } }, [icon('fas fa-ellipsis-v')]),
            menu
        ]);
    }
    /** "Showing a–b of n" + Previous/Next into a .tro-pager (50 per page). */
    function pager(node, total, shown, pageNo, go, per) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
        per = per || 50;
        node.hidden = !total;
        if (!total) { return; }
        var from = (pageNo - 1) * per + 1;
        node.appendChild(el('span', { text: 'Showing ' + from + '–' + (from + shown - 1) + ' of ' + total }));
        if (total > per) {
            node.appendChild(el('div', { class: 'btn-group' }, [
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo <= 1, on: { click: function () { go(pageNo - 1); } } }, [icon('fas fa-chevron-left me-1'), 'Previous']),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo * per >= total, on: { click: function () { go(pageNo + 1); } } }, ['Next', icon('fas fa-chevron-right ms-1')])
            ]));
        }
    }
    /** Keeps the page URL in step with the filters (drops empty values). */
    function setUrl(params) {
        var qs = new URLSearchParams();
        Object.keys(params).forEach(function (k) { if (params[k] !== null && params[k] !== undefined && params[k] !== '') { qs.set(k, String(params[k])); } });
        try { window.history.replaceState(null, '', window.location.pathname + (qs.toString() ? '?' + qs.toString() : '')); } catch (e) { /* ignore */ }
    }
    function clearNode(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }
    /** A Bootstrap form-switch row: returns {root, input}. */
    function switchRow(o) {
        var id = o.id || ('tro-sw-' + Math.random().toString(36).slice(2, 9));
        var input = el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id: id, checked: o.checked ? true : null, disabled: o.disabled ? true : null });
        var root = el('div', { class: 'tro-switch-row' + (o.class ? ' ' + o.class : '') }, [
            el('div', { class: 'tro-switch-row__text' }, [el('label', { class: 'tro-switch-row__title', for: id, text: o.label }), o.hint ? el('div', { class: 'tro-switch-row__hint', text: o.hint }) : null]),
            el('div', { class: 'form-check form-switch m-0' }, [input])
        ]);
        return { root: root, input: input };
    }

    /** POST with the spec's client rule: a 409 busy is retried once. */
    function post(action, body) {
        return Api.post(action, body).catch(function (err) {
            if (err && err.code === 'busy') {
                return sleep(1200).then(function () { return Api.post(action, body); });
            }
            throw err;
        });
    }

    /** Initial-data adapter: embedded route result, else the same action over the endpoint. */
    function load(spec) {
        if (!spec || !spec.action) { return Promise.reject(new window.TrainingApiError(0, 'server', 'Nothing to load.')); }
        if (spec.state === 'ok') { return Promise.resolve(spec.data); }
        if (spec.state === 'error' && spec.error && spec.error.code !== 'server') {
            return Promise.reject(new window.TrainingApiError(spec.error.status || 400, spec.error.code, spec.error.message));
        }
        return fetchAction(spec.action, spec.params || {});
    }
    /** GET that reports a route that is not installed yet as code 'unavailable'. */
    function fetchAction(action, params) {
        return Api.get(action, params).catch(function (err) {
            if (err && err.status === 404 && err.code === 'not_found' && /unknown action/i.test(err.message || '')) {
                err.code = 'unavailable';
            }
            throw err;
        });
    }

    /** Marks the fields a 422 names; returns true when at least one was shown inline. */
    function showFieldErrors(root, err) {
        clearFieldErrors(root);
        var shown = false;
        var fields = (err && err.fields) || {};
        Object.keys(fields).forEach(function (f) {
            var fb = root.querySelector('[data-field="' + f.replace(/[^a-z0-9_]/gi, '') + '"]');
            if (fb) {
                fb.textContent = String(fields[f]);
                var group = fb.closest('.tro-field');
                var ctl = group ? group.querySelector('input, select, textarea') : null;
                if (ctl) { ctl.classList.add('is-invalid'); }
                shown = true;
            }
        });
        return shown;
    }
    function clearFieldErrors(root) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-field]'), function (n) { n.textContent = ''; });
        Array.prototype.forEach.call(root.querySelectorAll('.is-invalid'), function (n) { n.classList.remove('is-invalid'); });
    }
    function alertBox(root, message, type) {
        var old = root.querySelector(':scope > .tro-form-alert');
        if (old) { old.parentNode.removeChild(old); }
        if (!message) { return; }
        var box = el('div', { class: 'tro-form-alert alert alert-' + (type || 'danger') + ' d-flex gap-2', role: 'alert' }, [
            icon(type === 'warning' ? 'fas fa-exclamation-triangle mt-1' : 'fas fa-exclamation-circle mt-1'), el('div', { text: message })
        ]);
        root.insertBefore(box, root.firstChild);
    }

    /** One labelled field: {label, control, hint, name (for 422 feedback), required, id}. */
    function field(o) {
        // The label points at the control itself, or at the first real input inside a composite
        // control (people picker, scan field, segmented buttons).
        var target = null;
        if (o.control && o.control.matches) {
            target = o.control.matches('input, select, textarea') ? o.control
                : o.control.querySelector('input:not([type=hidden]):not(.d-none):not(.btn-check), select, textarea');
        }
        var id = o.id || (target && target.id) || ('tro-f-' + Math.random().toString(36).slice(2, 9));
        if (target && !target.id) { target.id = id; }
        return el('div', { class: 'tro-field' + (o.class ? ' ' + o.class : '') }, [
            o.label ? el(target ? 'label' : 'span', { class: 'form-label d-block' + (o.required ? ' required' : ''), for: target ? id : null, text: o.label }) : null,
            o.control,
            o.hint ? el('div', { class: 'form-hint', text: o.hint }) : null,
            el('div', { class: 'invalid-feedback', dataset: { field: o.name || '' } })
        ]);
    }
    function dateInput(opts) {
        opts = opts || {};
        return el('input', { type: 'date', class: 'form-control', name: opts.name || null, value: opts.value || null, min: opts.min || null, max: opts.max || null, required: opts.required ? true : null });
    }
    function textArea(opts) {
        opts = opts || {};
        return el('textarea', { class: 'form-control', name: opts.name || null, rows: String(opts.rows || 3), maxlength: opts.max ? String(opts.max) : null, placeholder: opts.placeholder || null });
    }
    /** A textarea that counts toward a minimum ("7 more characters"). */
    function reasonInput(min, max, placeholder) {
        var ta = textArea({ rows: 3, max: max || 500, placeholder: placeholder || '' });
        var counter = el('div', { class: 'form-hint' });
        function upd() {
            var n = ta.value.trim().length;
            counter.textContent = n >= min ? '' : (min - n) + ' more ' + (min - n === 1 ? 'character' : 'characters') + ' needed';
        }
        ta.addEventListener('input', upd);
        upd();
        return { input: ta, counter: counter, ok: function () { return ta.value.trim().length >= min; } };
    }

    // ------------------------------------------------------------------------------------
    // The page-level offcanvas
    // ------------------------------------------------------------------------------------

    var oc = null;
    function ensureSheet() {
        if (oc) { return oc; }
        var title = el('h2', { class: 'offcanvas-title', id: 'tro-oc-title' });
        var sub = el('span', { class: 'tro-oc__sub' });
        var body = el('div', { class: 'offcanvas-body' });
        var foot = el('div', { class: 'tro-oc__foot' });
        var root = el('div', { class: 'offcanvas offcanvas-end tro-oc', tabindex: '-1', id: 'tro-oc', 'aria-labelledby': 'tro-oc-title', 'data-bs-backdrop': 'static' }, [
            el('div', { class: 'offcanvas-header' }, [el('div', { class: 'me-auto' }, [title, sub]),
                el('button', { type: 'button', class: 'btn-close', 'aria-label': 'Close', dataset: { bsDismiss: 'offcanvas' } })]),
            body, foot
        ]);
        document.body.appendChild(root);
        oc = { root: root, title: title, sub: sub, body: body, foot: foot, onHidden: null, instance: null };
        root.addEventListener('hidden.bs.offcanvas', function () {
            var fn = oc.onHidden;
            oc.onHidden = null;
            while (body.firstChild) { body.removeChild(body.firstChild); }
            while (foot.firstChild) { foot.removeChild(foot.firstChild); }
            if (typeof fn === 'function') { fn(); }
        });
        return oc;
    }
    /**
     * Opens the offcanvas with {title, subtitle, body: Node, foot: [Node], onHidden, wide}.
     * Returns {body, foot, close(), setFoot([nodes]), setBody(node), setTitle(t, sub)}.
     */
    function sheet(o) {
        var s = ensureSheet();
        var prev = s.onHidden;
        s.onHidden = null;
        if (typeof prev === 'function') { prev(); }
        var h = {
            body: s.body, foot: s.foot, root: s.root,
            setTitle: function (t, sub) { s.title.textContent = t || ''; s.sub.textContent = sub || ''; s.sub.hidden = !sub; },
            setBody: function (node) { while (s.body.firstChild) { s.body.removeChild(s.body.firstChild); } if (node) { s.body.appendChild(node); } s.body.scrollTop = 0; },
            setFoot: function (nodes) { while (s.foot.firstChild) { s.foot.removeChild(s.foot.firstChild); } (nodes || []).forEach(function (n) { if (n) { s.foot.appendChild(n); } }); s.foot.hidden = !(nodes && nodes.length); },
            close: function () { if (s.instance) { s.instance.hide(); } }
        };
        h.setTitle(o.title, o.subtitle);
        h.setBody(o.body || null);
        h.setFoot(o.foot || []);
        s.root.style.width = o.wide ? 'min(720px, 100vw)' : '';
        s.onHidden = o.onHidden || null;
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            s.instance = window.bootstrap.Offcanvas.getOrCreateInstance(s.root);
            s.instance.show();
        } else {
            s.root.classList.add('show');
            s.root.style.visibility = 'visible';
        }
        return h;
    }
    function cancelBtn(label) {
        return el('button', { type: 'button', class: 'btn btn-outline-secondary', text: label || 'Cancel', dataset: { bsDismiss: 'offcanvas' } });
    }

    // ------------------------------------------------------------------------------------
    // PeoplePicker: combobox over people_search (eligible, in scope)
    // ------------------------------------------------------------------------------------

    var pickerSeq = 0;
    function PeoplePicker(o) {
        var self = this;
        o = o || {};
        this.multiple = o.multiple !== false;
        this.max = o.max || 200;
        this.onChange = o.onChange || function () {};
        this.chosen = [];
        this.results = [];
        this.active = -1;
        var idBase = 'tro-pp-' + (++pickerSeq);
        this.input = el('input', {
            type: 'search', class: 'form-control', id: o.id || idBase, autocomplete: 'off', placeholder: o.placeholder || 'Search by name, title or department',
            role: 'combobox', 'aria-expanded': 'false', 'aria-controls': idBase + '-list', 'aria-autocomplete': 'list'
        });
        this.list = el('ul', { class: 'tro-picker__list', id: idBase + '-list', role: 'listbox', hidden: true });
        this.chips = el('div', { class: 'tro-picker__chips', 'aria-live': 'polite' });
        this.root = el('div', { class: 'tro-picker' }, [this.chips, this.input, this.list]);
        var search = UI.debounce(function () { self.search(); }, 250);
        this.input.addEventListener('input', search);
        this.input.addEventListener('focus', function () { if (self.input.value.trim().length >= 2) { self.search(); } });
        this.input.addEventListener('keydown', function (e) { self.onKey(e); });
        this.input.addEventListener('blur', function () { setTimeout(function () { self.hide(); }, 150); });
        (o.initial || []).forEach(function (p) { self.add(p, true); });
    }
    PeoplePicker.prototype.ids = function () { return this.chosen.map(function (p) { return Number(p.contact_id); }); };
    PeoplePicker.prototype.people = function () { return this.chosen.slice(); };
    PeoplePicker.prototype.has = function (id) { return this.ids().indexOf(Number(id)) !== -1; };
    PeoplePicker.prototype.add = function (p, silent) {
        if (!p || !p.contact_id || this.has(p.contact_id)) { return; }
        if (!this.multiple) { this.chosen = []; }
        if (this.chosen.length >= this.max) { UI.toast('You can pick up to ' + this.max + ' people at a time.', { type: 'warning' }); return; }
        this.chosen.push(p);
        this.renderChips();
        if (!silent) { this.onChange(this.people()); }
    };
    PeoplePicker.prototype.remove = function (id) {
        this.chosen = this.chosen.filter(function (p) { return Number(p.contact_id) !== Number(id); });
        this.renderChips();
        this.onChange(this.people());
    };
    PeoplePicker.prototype.clear = function () { this.chosen = []; this.renderChips(); this.onChange([]); };
    PeoplePicker.prototype.renderChips = function () {
        var self = this;
        while (this.chips.firstChild) { this.chips.removeChild(this.chips.firstChild); }
        this.chosen.forEach(function (p) {
            self.chips.appendChild(el('span', { class: 'tro-pchip', title: personMeta(p) }, [
                avatar(p.name), el('span', { class: 'tro-pchip__name', text: p.name }),
                el('button', { type: 'button', class: 'tro-pchip__x', 'aria-label': 'Remove ' + p.name, on: { click: function () { self.remove(p.contact_id); self.input.focus(); } } }, [icon('fas fa-times')])
            ]));
        });
        if (!this.multiple) { this.input.hidden = this.chosen.length > 0; }
    };
    PeoplePicker.prototype.hide = function () { this.list.hidden = true; this.input.setAttribute('aria-expanded', 'false'); this.active = -1; };
    PeoplePicker.prototype.message = function (text) {
        while (this.list.firstChild) { this.list.removeChild(this.list.firstChild); }
        this.list.appendChild(el('li', { class: 'tro-picker__msg', role: 'presentation', text: text }));
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    };
    PeoplePicker.prototype.search = function () {
        var self = this;
        var q = this.input.value.trim();
        if (q.length < 2) { this.hide(); return; }
        var seq = (this.seq = (this.seq || 0) + 1);
        this.message('Searching…');
        fetchAction('people_search', { q: q, limit: 20 }).then(function (d) {
            if (seq !== self.seq) { return; }
            self.results = (d && d.people) || [];
            self.renderResults();
        }, function (err) {
            if (seq !== self.seq) { return; }
            self.message(err && err.code === 'unavailable' ? 'People search is not available yet.' : errorText(err));
        });
    };
    PeoplePicker.prototype.renderResults = function () {
        var self = this;
        while (this.list.firstChild) { this.list.removeChild(this.list.firstChild); }
        if (!this.results.length) { this.message('No one on the roster matches.'); return; }
        this.results.forEach(function (p, i) {
            var chosen = self.has(p.contact_id);
            var li = el('li', { class: 'tro-picker__opt' + (chosen ? ' is-chosen' : ''), role: 'option', id: self.list.id + '-o' + i, 'aria-selected': 'false' }, [
                avatar(p.name, 'sm'),
                el('div', { class: 'tro-person__text' }, [el('div', { class: 'fw-semibold', text: p.name }), el('span', { class: 'tro-sub', text: personMeta(p) })]),
                chosen ? el('span', { class: 'ms-auto tro-sub', text: 'Added' }) : null
            ]);
            li.addEventListener('mousedown', function (e) { e.preventDefault(); self.pick(i); });
            self.list.appendChild(li);
        });
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.setActive(0);
    };
    PeoplePicker.prototype.setActive = function (i) {
        var opts = this.list.querySelectorAll('.tro-picker__opt');
        if (!opts.length) { return; }
        this.active = Math.max(0, Math.min(opts.length - 1, i));
        Array.prototype.forEach.call(opts, function (n, j) { n.setAttribute('aria-selected', j === this.active ? 'true' : 'false'); }, this);
        this.input.setAttribute('aria-activedescendant', opts[this.active].id);
        opts[this.active].scrollIntoView({ block: 'nearest' });
    };
    PeoplePicker.prototype.pick = function (i) {
        var p = this.results[i];
        if (!p) { return; }
        if (!this.has(p.contact_id)) { this.add(p); }   // already added: removal is the chip's x
        if (this.multiple) { this.input.value = ''; this.hide(); this.input.focus(); } else { this.hide(); }
    };
    PeoplePicker.prototype.onKey = function (e) {
        if (this.list.hidden) { return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); this.setActive(this.active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.setActive(this.active - 1); }
        else if (e.key === 'Enter') { e.preventDefault(); if (this.active >= 0) { this.pick(this.active); } }
        else if (e.key === 'Escape') { e.stopPropagation(); this.hide(); }
    };

    // ------------------------------------------------------------------------------------
    // ScanField: evidence upload -> attach token (Lane C endpoint)
    // ------------------------------------------------------------------------------------

    var EVIDENCE_ENDPOINT = '/agent/training_evidence_upload.php';
    function uploadEvidence(file, onProgress) {
        return new Promise(function (resolve, reject) {
            var ApiErr = window.TrainingApiError;
            var fd = new FormData();
            var token = typeof window.csrfToken === 'string' ? window.csrfToken : '';
            fd.append('file', file, file.name || 'scan');
            fd.append('csrf_token', token);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', EVIDENCE_ENDPOINT, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-CSRF-Token', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable && onProgress) { onProgress(e.loaded / e.total); } });
            xhr.addEventListener('load', function () {
                var status = xhr.status;
                var ctype = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
                if (status === 413 && ctype.indexOf('application/json') === -1) { reject(new ApiErr(413, 'too_large', 'That file is too large.')); return; }
                if (status === 404 && ctype.indexOf('application/json') === -1) { reject(new ApiErr(404, 'unavailable', 'Scan upload is not available yet.')); return; }
                if (ctype.indexOf('application/json') === -1) { reject(new ApiErr(status, 'session', 'Your session needs a refresh.')); return; }
                var json;
                try { json = JSON.parse(xhr.responseText); } catch (e) { reject(new ApiErr(status, 'session', 'Your session needs a refresh.')); return; }
                if (json && json.ok === true) { resolve(json.data || {}); return; }
                var err = (json && json.error) || {};
                reject(new ApiErr(status, err.code || 'server', err.message || 'The upload failed. Try again.', err.fields));
            });
            xhr.addEventListener('error', function () { reject(new ApiErr(0, 'network', 'The upload was interrupted. Check your connection and retry.')); });
            xhr.send(fd);
        });
    }
    /**
     * {required, label, hint, onChange(token|null)}. .token() is the attach token. Accepts only
     * PDF/JPEG/PNG (iOS converts HEIC to JPEG when accept= says so).
     */
    function ScanField(o) {
        var self = this;
        o = o || {};
        this.tokenValue = null;
        this.onChange = o.onChange || function () {};
        this.required = !!o.required;
        var max = S.settings.evidence_max_bytes || 20 * 1048576;
        this.file = el('input', { type: 'file', class: 'd-none', accept: 'application/pdf,image/jpeg,image/png' });
        this.thumb = el('span', { class: 'tro-scan__thumb' }, [icon('fas fa-file-upload')]);
        this.name = el('div', { class: 'tro-scan__name', text: o.emptyText || 'No scan attached' });
        this.status = el('div', { class: 'tro-sub', text: 'PDF, JPEG or PNG, up to ' + UI.fmtBytes(max) + '.' });
        this.pick = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', on: { click: function () { self.file.click(); } } }, [icon('fas fa-upload me-1'), 'Upload scan…']);
        this.clearBtn = el('button', { type: 'button', class: 'btn btn-sm btn-link text-danger', text: 'Remove', hidden: true, on: { click: function () { self.clear(); } } });
        this.box = el('div', { class: 'tro-scan' + (this.required ? ' is-required' : '') }, [this.thumb, el('div', { class: 'tro-scan__text' }, [this.name, this.status]), this.pick, this.clearBtn]);
        this.root = el('div', {}, [this.box, this.file]);
        this.file.addEventListener('change', function () {
            var f = self.file.files && self.file.files[0];
            self.file.value = '';
            if (!f) { return; }
            if (f.size > max) { self.fail('That file is ' + UI.fmtBytes(f.size) + '. The limit is ' + UI.fmtBytes(max) + '.'); return; }
            if (/heic|heif/i.test(f.type) || /\.hei[cf]$/i.test(f.name)) { self.fail('HEIC photos are not supported. Take the photo as JPEG, or pick it from the Photos app so it is converted.'); return; }
            self.upload(f);
        });
    }
    ScanField.prototype.token = function () { return this.tokenValue; };
    ScanField.prototype.setRequired = function (req) { this.required = !!req; this.box.classList.toggle('is-required', this.required && !this.tokenValue); };
    ScanField.prototype.fail = function (msg) { this.status.textContent = msg; this.status.classList.add('text-danger'); };
    ScanField.prototype.upload = function (f) {
        var self = this;
        this.status.classList.remove('text-danger');
        this.status.textContent = 'Uploading… 0%';
        this.pick.disabled = true;
        uploadEvidence(f, function (fr) { self.status.textContent = 'Uploading… ' + Math.round(fr * 100) + '%'; }).then(function (d) {
            self.pick.disabled = false;
            self.tokenValue = d.attach_token || null;
            var m = d.media || {};
            self.name.textContent = m.original_name || f.name;
            self.status.textContent = (m.bytes ? UI.fmtBytes(m.bytes) + ' · ' : '') + 'Attached';
            while (self.thumb.firstChild) { self.thumb.removeChild(self.thumb.firstChild); }
            if (m.preview_url && /^image\//.test(m.mime || '') && /^\/agent\/training_evidence\.php\?/.test(m.preview_url)) {
                self.thumb.appendChild(el('img', { src: m.preview_url, alt: '', class: 'tro-scan__thumb' }));
            } else {
                self.thumb.appendChild(icon('fas fa-file-pdf'));
            }
            self.box.classList.add('is-attached');
            self.box.classList.remove('is-required');
            self.clearBtn.hidden = false;
            self.pick.lastChild.textContent = 'Replace…';
            self.onChange(self.tokenValue);
        }, function (err) {
            self.pick.disabled = false;
            self.fail(errorText(err));
        });
    };
    ScanField.prototype.clear = function () {
        this.tokenValue = null;
        this.name.textContent = 'No scan attached';
        this.status.textContent = 'PDF, JPEG or PNG.';
        while (this.thumb.firstChild) { this.thumb.removeChild(this.thumb.firstChild); }
        this.thumb.appendChild(icon('fas fa-file-upload'));
        this.box.classList.remove('is-attached');
        this.box.classList.toggle('is-required', this.required);
        this.clearBtn.hidden = true;
        this.pick.lastChild.textContent = 'Upload scan…';
        this.onChange(null);
    };

    // ------------------------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------------------------

    function summaryBox(rows) {
        return el('dl', { class: 'tro-fields tro-card mb-3', style: { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' } }, rows.filter(Boolean).map(function (r) {
            return el('div', {}, [el('dt', { text: r[0] }), el('dd', { class: r[1] ? null : 'is-empty', text: r[1] || '—' })]);
        }));
    }
    function assignmentSummary(a) {
        return summaryBox([
            ['Person', a.person ? a.person.name : ''],
            ['Course', a.course ? a.course.name : ''],
            ['Due', fmtDate(a.due_on) + (a.original_due_on && a.original_due_on !== a.due_on ? ' (was ' + fmtDate(a.original_due_on) + ')' : '')],
            ['Why', a.anchor_label || '']
        ]);
    }

    /** Assign training (manual). opts: {people:[PersonRef], courseId}. */
    function openAssign(opts) {
        return new Promise(function (resolve) {
            var result = null;
            var requestUid = uid32();
            var body = el('form', { novalidate: true, autocomplete: 'off' });
            var picker = new PeoplePicker({ initial: opts.people || [], onChange: sync, placeholder: 'Type a name, title or department' });
            var course = courseSelect({ value: opts.courseId, required: true });
            var due = dateInput({ value: addDays(today(), 14), min: today(), required: true });
            var note = textArea({ rows: 2, max: 500, placeholder: 'Optional: why this is assigned (shows on the assignment)' });
            var quick = el('div', { class: 'd-flex flex-wrap gap-1 mt-2' }, [7, 14, 30].map(function (n) {
                return el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'In ' + n + ' days', on: { click: function () { due.value = addDays(today(), n); sync(); } } });
            }));
            var courseMeta = el('div', { class: 'form-hint' });
            body.appendChild(field({ label: 'People', control: picker.root, name: 'contact_ids', required: true, hint: 'Only people on the training roster in your departments are listed.' }));
            body.appendChild(field({ label: 'Course or document', control: course, name: 'course_id', required: true }));
            course.parentNode.insertBefore(courseMeta, course.nextSibling);
            var dueField = field({ label: 'Due date', control: due, name: 'due_on', required: true });
            dueField.insertBefore(quick, dueField.querySelector('.invalid-feedback'));
            body.appendChild(dueField);
            body.appendChild(field({ label: 'Note', control: note, name: 'note' }));

            var submit = el('button', { type: 'submit', class: 'btn btn-primary', text: 'Assign' });
            submit.setAttribute('form', 'tro-assign-form');
            body.id = 'tro-assign-form';
            var h = sheet({
                title: 'Assign training', subtitle: 'Asks these people to complete a course by a date.', body: body,
                foot: [el('span', { class: 'tro-oc__note', text: 'Nothing is assigned until you press Assign.' }), cancelBtn(), submit],
                onHidden: function () { resolve(result); }
            });
            function sync() {
                var n = picker.ids().length;
                submit.textContent = n > 1 ? 'Assign to ' + n + ' people' : 'Assign';
                var c = courseById(course.value);
                courseMeta.textContent = c ? courseSummary(c) : '';
                submit.disabled = !(n > 0 && course.value && isYmd(due.value) && due.value >= today());
            }
            course.addEventListener('change', sync);
            due.addEventListener('input', sync);
            sync();
            body.addEventListener('submit', function (e) {
                e.preventDefault();
                if (submit.disabled) { return; }
                clearFieldErrors(body);
                alertBox(body, null);
                var names = {};
                picker.people().forEach(function (p) { names[p.contact_id] = p; });
                busy(submit, true, 'Assigning…');
                post('assign_manual', { request_uid: requestUid, contact_ids: picker.ids(), course_id: Number(course.value), due_on: due.value, note: note.value.trim() || null })
                    .then(function (d) {
                        result = d || {};
                        showOutcomes(h, result, names, due.value, courseById(course.value));
                    }, function (err) {
                        busy(submit, false);
                        if (!showFieldErrors(body, err) || err.code !== 'validation') { alertBox(body, errorText(err)); }
                    });
            });
            setTimeout(function () { if (!(opts.people || []).length) { picker.input.focus(); } else { course.focus(); } }, 350);
        });
    }
    var OUTCOME = {
        assigned: ['ok', 'fas fa-check', 'Assigned'],
        already_assigned: ['info', null, 'Already assigned'],
        current: ['ok', null, 'Already current'],
        waived: ['outline', null, 'Waived'],
        not_eligible: ['warn', 'fas fa-exclamation-triangle', 'Not on the roster']
    };
    function showOutcomes(h, d, names, dueOn, course) {
        var outcomes = d.outcomes || [];
        var counts = {};
        outcomes.forEach(function (o) { counts[o.outcome] = (counts[o.outcome] || 0) + 1; });
        var list = el('ul', { class: 'tro-outcomes' }, outcomes.map(function (o) {
            var p = names[o.contact_id] || { name: 'Person #' + o.contact_id };
            var m = OUTCOME[o.outcome] || ['outline', null, String(o.outcome)];
            return el('li', {}, [avatar(p.name, 'sm'), el('div', { class: 'tro-person__text' }, [el('div', { class: 'fw-semibold', text: p.name }), el('span', { class: 'tro-sub', text: personMeta(p) })]),
                chip(m[2] + (o.outcome === 'assigned' ? ' · due ' + fmtDate(dueOn, true) : ''), m[0], m[1])]);
        }));
        var head = (counts.assigned || 0) > 0
            ? plural(counts.assigned, 'person', 'people') + ' assigned ' + (course ? course.name : 'the course') + '.'
            : 'No one new was assigned.';
        var notes = [];
        if (counts.already_assigned) { notes.push(plural(counts.already_assigned, 'person', 'people') + ' already had it assigned.'); }
        if (counts.current) { notes.push(plural(counts.current, 'person', 'people') + ' are already current.'); }
        if (counts.not_eligible) { notes.push(plural(counts.not_eligible, 'person', 'people') + ' are not on the training roster (no department or excluded).'); }
        var rec = d.reconcile || {};
        var wrap = el('div', {}, [
            el('div', { class: 'alert alert-success d-flex gap-2', role: 'status' }, [icon('fas fa-check-circle mt-1'), el('div', {}, [el('div', { class: 'fw-semibold', text: head }), notes.length ? el('div', { text: notes.join(' ') }) : null])]),
            rec.error ? el('div', { class: 'alert alert-warning', text: 'Saved. Assignments will update on the next recalculation.' }) : null,
            list
        ]);
        h.setTitle('Assign training', 'Done');
        h.setBody(wrap);
        h.setFoot([el('button', { type: 'button', class: 'btn btn-primary ms-auto', text: 'Done', dataset: { bsDismiss: 'offcanvas' } })]);
    }

    /** Extend the due date. opts: {assignment}. */
    function openExtend(opts) {
        return new Promise(function (resolve) {
            var a = opts.assignment || {};
            var result = null;
            var body = el('form', { novalidate: true });
            var minDue = addDays(today(), 1);
            var due = dateInput({ value: a.due_on && a.due_on > today() ? addDays(a.due_on, 14) : addDays(today(), 14), min: minDue, required: true });
            var reason = reasonInput(5, 255, 'For example: "On leave until Oct 20" or "Next class is Oct 12"');
            body.appendChild(assignmentSummary(a));
            body.appendChild(field({ label: 'New due date', control: due, name: 'due_on', required: true, hint: 'The original due date stays on record.' }));
            var rf = field({ label: 'Reason', control: reason.input, name: 'reason', required: true });
            rf.insertBefore(reason.counter, rf.querySelector('.invalid-feedback'));
            body.appendChild(rf);
            var submit = el('button', { type: 'button', class: 'btn btn-primary', text: 'Move due date' });
            var h = sheet({ title: 'Extend due date', subtitle: a.person ? a.person.name : '', body: body, foot: [cancelBtn(), submit], onHidden: function () { resolve(result); } });
            function sync() { submit.disabled = !(isYmd(due.value) && due.value > today() && reason.ok()); }
            due.addEventListener('input', sync);
            reason.input.addEventListener('input', sync);
            sync();
            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                busy(submit, true, 'Saving…');
                alertBox(body, null);
                post('assignment_extend', { assignment_id: a.id, due_on: due.value, reason: reason.input.value.trim() }).then(function (d) {
                    result = d || {};
                    UI.toast('Due date moved to ' + fmtDate(due.value) + '.');
                    h.close();
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err)) { alertBox(body, errorText(err)); }
                });
            });
        });
    }

    /** Waive an assignment. opts: {assignment}. */
    function openWaive(opts) {
        return new Promise(function (resolve) {
            var a = opts.assignment || {};
            var result = null;
            var body = el('form', { novalidate: true });
            var until = dateInput({ min: addDays(today(), 1) });
            var reason = reasonInput(5, 255, 'For example: "Holds a current card from a previous employer" or "Office role, never operates the equipment"');
            body.appendChild(assignmentSummary(a));
            body.appendChild(field({ label: 'Waive until', control: until, name: 'until', hint: 'Leave empty to waive it with no end date.' }));
            var rf = field({ label: 'Reason', control: reason.input, name: 'reason', required: true });
            rf.insertBefore(reason.counter, rf.querySelector('.invalid-feedback'));
            body.appendChild(rf);
            body.appendChild(el('div', { class: 'tro-consequence' }, [icon('fas fa-info-circle'), el('div', { text: 'While waived, ' + (a.person ? a.person.name : 'this person') + ' is not asked to complete this course and is left out of compliance numbers. The waiver and its reason stay on record.' })]));
            var submit = el('button', { type: 'button', class: 'btn btn-primary', text: 'Waive' });
            var h = sheet({ title: 'Waive assignment', subtitle: a.course ? a.course.name : '', body: body, foot: [cancelBtn(), submit], onHidden: function () { resolve(result); } });
            function sync() { submit.disabled = !(reason.ok() && (!until.value || (isYmd(until.value) && until.value > today()))); }
            until.addEventListener('input', sync);
            reason.input.addEventListener('input', sync);
            sync();
            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                busy(submit, true, 'Saving…');
                alertBox(body, null);
                post('assignment_waive', { assignment_id: a.id, until: until.value || null, reason: reason.input.value.trim() }).then(function (d) {
                    result = d || {};
                    UI.toast(until.value ? 'Waived until ' + fmtDate(until.value) + '.' : 'Waived.');
                    h.close();
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err)) { alertBox(body, errorText(err)); }
                });
            });
        });
    }

    /**
     * Void a record: an inline confirm strip (the Phase 1 .tr-confirm-bar contract) with a
     * reason of at least 10 characters. opts: {container, completion, prepend}.
     */
    function openVoid(opts) {
        return new Promise(function (resolve) {
            var c = opts.completion || {};
            var container = opts.container;
            if (!container) { resolve(null); return; }
            var old = container.querySelector(':scope > .tro-void-bar');
            if (old) { old.parentNode.removeChild(old); }
            var reason = reasonInput(10, 1000, 'Why is this record wrong? For example: "Entered for the wrong person" or "Card was forged"');
            var yes = el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Void record', disabled: true });
            var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
            var who = c.person ? c.person.name : 'this person';
            var consequence = c.course && c.course.kind === 'document'
                ? 'The acknowledgment stays on file, marked void.'
                : 'The record stays on file, marked void, and its certificate stops verifying. If ' + who + ' has no other valid record, a redo assignment due in ' + plural(S.settings.reissue_days || 14, 'day') + ' is created.';
            var msg = el('div', { class: 'tro-feedback' });
            var bar = el('div', { class: 'tr-confirm-bar tro-void-bar tro-reason-bar alert alert-danger', role: 'alertdialog', 'aria-label': 'Void this record' }, [
                el('div', { class: 'fw-semibold', text: 'Void ' + (c.cert_number ? 'record ' + c.cert_number : 'this record') + '? This cannot be undone.' }),
                el('div', { class: 'small', text: consequence }),
                reason.input, reason.counter, msg,
                el('div', { class: 'tro-reason-bar__row' }, [el('span', { class: 'me-auto' }), no, yes])
            ]);
            if (opts.prepend) { container.insertBefore(bar, container.firstChild); } else { container.appendChild(bar); }
            reason.input.focus();
            reason.input.addEventListener('input', function () { yes.disabled = !reason.ok(); });
            function done(v) { if (bar.parentNode) { bar.parentNode.removeChild(bar); } resolve(v); }
            no.addEventListener('click', function () { done(null); });
            bar.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); done(null); } });
            yes.addEventListener('click', function () {
                if (!reason.ok()) { return; }
                busy(yes, true, 'Voiding…');
                no.disabled = true;
                post('completion_void', { completion_id: c.id, reason: reason.input.value.trim() }).then(function (d) {
                    UI.toast('Record voided.');
                    done(d || {});
                }, function (err) {
                    busy(yes, false);
                    no.disabled = false;
                    msg.textContent = errorText(err);
                    msg.className = 'tro-feedback small fw-semibold';
                });
            });
        });
    }

    /** Hire date / rehired. opts: {person}. */
    function openHireDate(opts) {
        return new Promise(function (resolve) {
            var p = opts.person || {};
            var result = null;
            var body = el('form', { novalidate: true });
            var date = dateInput({ value: p.hire_date || today(), max: addDays(today(), 60), required: true });
            var rehired = el('input', { type: 'checkbox', class: 'form-check-input', id: 'tro-rehired' });
            var reason = reasonInput(5, 255, 'For example: "Start date from HR file" or "Rehired after 2 years away"');
            body.appendChild(summaryBox([['Person', p.name], ['Current hire date', p.hire_date ? fmtDate(p.hire_date) : 'Not set']]));
            body.appendChild(field({ label: 'Hire date', control: date, name: 'hire_date', required: true, hint: 'Up to 60 days ahead, for people who have not started yet.' }));
            body.appendChild(el('div', { class: 'tro-field form-check' }, [rehired, el('label', { class: 'form-check-label', for: 'tro-rehired', text: 'Rehired (they left and came back)' }),
                el('div', { class: 'form-hint', text: 'Onboarding training that only counts after the hire date starts again.' })]));
            var rf = field({ label: 'Reason', control: reason.input, name: 'reason', required: true });
            rf.insertBefore(reason.counter, rf.querySelector('.invalid-feedback'));
            body.appendChild(rf);
            body.appendChild(el('div', { class: 'tro-consequence' }, [icon('fas fa-info-circle'), el('div', { text: 'New-hire rules use this date. Saving it can assign onboarding training right away.' })]));
            var submit = el('button', { type: 'button', class: 'btn btn-primary', text: 'Save hire date' });
            var h = sheet({ title: 'Set hire date', subtitle: p.name || '', body: body, foot: [cancelBtn(), submit], onHidden: function () { resolve(result); } });
            function sync() { submit.disabled = !(isYmd(date.value) && reason.ok()); }
            date.addEventListener('input', sync);
            reason.input.addEventListener('input', sync);
            sync();
            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                busy(submit, true, 'Saving…');
                alertBox(body, null);
                post('hire_date_set', { contact_id: p.contact_id, hire_date: date.value, rehired: rehired.checked, reason: reason.input.value.trim() }).then(function (d) {
                    result = d || {};
                    UI.toast('Hire date saved.');
                    h.close();
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err)) { alertBox(body, errorText(err)); }
                });
            });
        });
    }

    /** Roster include / exclude / automatic. opts: {person, state}. */
    function openRoster(opts) {
        return new Promise(function (resolve) {
            var p = opts.person || {};
            var st = opts.state;
            var result = null;
            var titles = { include: 'Include on the roster', exclude: 'Exclude from training', auto: 'Back to automatic' };
            var texts = {
                include: p.name + ' has no department, so rules do not reach them. Including them puts them on the training roster.',
                exclude: p.name + ' stops receiving training assignments. Their open assignments are cancelled; their records stay.',
                auto: p.name + ' is on the roster whenever they have a department.'
            };
            var body = el('form', { novalidate: true });
            var reason = reasonInput(st === 'auto' ? 0 : 5, 255, st === 'exclude' ? 'For example: "Contractor, trained by their own company"' : 'For example: "Temporary worker assigned to the shop"');
            body.appendChild(el('div', { class: 'tro-consequence mb-3' + (st === 'exclude' ? ' tro-consequence--warn' : '') }, [icon('fas fa-info-circle'), el('div', { text: texts[st] || '' })]));
            var rf = field({ label: 'Reason', control: reason.input, name: 'reason', required: st !== 'auto' });
            rf.insertBefore(reason.counter, rf.querySelector('.invalid-feedback'));
            body.appendChild(rf);
            var submit = el('button', { type: 'button', class: 'btn ' + (st === 'exclude' ? 'btn-danger' : 'btn-primary'), text: titles[st] || 'Save' });
            var h = sheet({ title: titles[st] || 'Roster', subtitle: p.name || '', body: body, foot: [cancelBtn(), submit], onHidden: function () { resolve(result); } });
            function sync() { submit.disabled = !reason.ok(); }
            reason.input.addEventListener('input', sync);
            sync();
            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                busy(submit, true, 'Saving…');
                alertBox(body, null);
                post('roster_set', { contact_id: p.contact_id, state: st, reason: reason.input.value.trim() || null }).then(function (d) {
                    result = d || {};
                    UI.toast(st === 'exclude' ? p.name + ' excluded.' : st === 'include' ? p.name + ' included.' : 'Roster set back to automatic.');
                    h.close();
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err)) { alertBox(body, errorText(err)); }
                });
            });
        });
    }

    var EVENT_LABELS = {
        'assignment.created': 'Assigned', 'assignment.reopened': 'Reopened', 'assignment.completed': 'Completed',
        'assignment.cancelled': 'Cancelled', 'assignment.waived': 'Waived', 'assignment.due_changed': 'Due date changed',
        'completion.recorded': 'Record added', 'completion.voided': 'Record voided', 'cert.token_issued': 'Certificate issued',
        'evaluation.recorded': 'Evaluation recorded', 'session.finalized': 'Session finalized'
    };
    var CLOSE_REASONS = { superseded: 'replaced by a newer assignment', no_longer_required: 'no longer required by any rule', contact_ineligible: 'person left the roster', completed: 'completed' };
    var TRIGGERS = { rule_save: 'rule saved', rule_archive: 'rule archived', assign_manual: 'assigned by hand', cron: 'nightly check', dashboard: 'automatic check', directory_sync: 'directory sync', completion: 'record added', void: 'record voided', session: 'session finalized', roster: 'roster change', hire_date: 'hire date change', jobgroup: 'job group change', reconcile_now: 'recalculated', publish_retrain: 'new version needs retraining', contact_edit: 'contact edited', kiosk: 'kiosk sign-in', course_archived: 'course archived', course_restored: 'course restored' };
    function eventDetail(ev) {
        var p = ev.payload || {};
        var bits = [];
        if (p.from && p.to) { bits.push(fmtDate(p.from) + ' → ' + fmtDate(p.to)); }
        if (p.due_on && ev.type !== 'assignment.due_changed') { bits.push('due ' + fmtDate(p.due_on)); }
        if (p.until) { bits.push('until ' + fmtDate(p.until)); }
        if (p.close_reason && CLOSE_REASONS[p.close_reason]) { bits.push(CLOSE_REASONS[p.close_reason]); }
        if (p.reason && typeof p.reason === 'string' && ev.type !== 'assignment.created') { bits.push('“' + p.reason + '”'); }
        if (p.trigger && TRIGGERS[p.trigger]) { bits.push('via ' + TRIGGERS[p.trigger]); }
        return bits.join(' · ');
    }
    function timeline(events) {
        if (!events || !events.length) { return el('p', { class: 'text-muted small', text: 'No history yet.' }); }
        return el('ol', { class: 'tro-timeline' }, events.map(function (ev) {
            var a = ev.actor;
            var who = ev.actor_name || (a && typeof a === 'object' ? (a.name || (a.type === 'system' ? 'System' : '')) : a) || (ev.actor_type === 'system' ? 'System' : '');
            return el('li', {}, [
                el('div', { class: 'tro-timeline__what', text: EVENT_LABELS[ev.type] || ev.type }),
                eventDetail(ev) ? el('div', { class: 'small', text: eventDetail(ev) }) : null,
                el('div', { class: 'tro-timeline__when', text: [fmtDateTime(ev.at || ev.at_utc), who].filter(Boolean).join(' · ') + (ev.seq ? ' · #' + ev.seq : '') })
            ]);
        }));
    }
    /** Assignment history (assignment_get). opts: {assignmentId, assignment?}. */
    function openHistory(opts) {
        return new Promise(function (resolve) {
            var holder = el('div', {}, [el('div', { class: 'tr-skeleton' }, [el('div', { class: 'tro-skel tro-skel--w60 mb-3' }), el('div', { class: 'tro-skel tro-skel--w80 mb-3' }), el('div', { class: 'tro-skel tro-skel--w40' })])]);
            var h = sheet({ title: 'Assignment history', subtitle: opts.assignment && opts.assignment.person ? opts.assignment.person.name : '', body: holder, foot: [], onHidden: function () { resolve(null); } });
            fetchAction('assignment_get', { assignment_id: opts.assignmentId }).then(function (d) {
                var a = (d && d.assignment) || {};
                var wrap = el('div', {}, [
                    el('div', { class: 'd-flex align-items-center gap-2 mb-3' }, [statusChip(a.display_status || a.status, a), a.required === false ? chip('Optional', 'outline') : null]),
                    summaryBox([
                        ['Person', a.person ? a.person.name : ''], ['Course', a.course ? a.course.name : ''],
                        ['Why', a.anchor_label || ''], ['Rule', a.requirement ? a.requirement.name : 'None'],
                        ['Due', fmtDate(a.due_on)], ['Originally due', fmtDate(a.original_due_on)],
                        ['Assigned', fmtDateTime(a.created_at)], a.closed_at ? ['Closed', fmtDateTime(a.closed_at) + (a.close_reason ? ' · ' + (CLOSE_REASONS[a.close_reason] || a.close_reason) : '')] : null,
                        a.close_note ? ['Note', a.close_note] : null, a.reopened_count ? ['Reopened', plural(a.reopened_count, 'time')] : null
                    ]),
                    el('h3', { class: 'h5 mt-3 mb-2', text: 'What happened' }),
                    timeline((d && d.history) || [])
                ]);
                h.setBody(wrap);
            }, function (err) {
                h.setBody(failState(err));
            });
        });
    }

    /** Office entry of an external card / paper record (M8). opts: {person, courseId}. */
    function openExternal(opts) {
        return new Promise(function (resolve) {
            var result = null;
            var requestUid = uid32();
            var body = el('form', { novalidate: true, autocomplete: 'off' });
            var picker = new PeoplePicker({ multiple: false, initial: opts.person ? [opts.person] : [], placeholder: 'Who is this record for?', onChange: sync });
            var course = courseSelect({ value: opts.courseId, required: true });
            var comp = null;   // course_components for the chosen course
            var methodExternal = el('input', { type: 'radio', class: 'btn-check', name: 'tro-x-method', id: 'tro-x-m-ext', value: 'external', checked: true });
            var methodPaper = el('input', { type: 'radio', class: 'btn-check', name: 'tro-x-method', id: 'tro-x-m-paper', value: 'legacy_paper' });
            var method = el('div', { class: 'btn-group w-100', role: 'group', 'aria-label': 'Kind of record' }, [
                methodExternal, el('label', { class: 'btn btn-outline-secondary', for: 'tro-x-m-ext' }, [icon('fas fa-id-card me-2'), 'External card']),
                methodPaper, el('label', { class: 'btn btn-outline-secondary', for: 'tro-x-m-paper' }, [icon('fas fa-file-alt me-2'), 'Paper record'])
            ]);
            var issuer = el('input', { type: 'text', class: 'form-control', maxlength: '200', placeholder: 'Company or trainer that issued it' });
            var ref = el('input', { type: 'text', class: 'form-control', maxlength: '100', placeholder: 'Optional' });
            var trained = dateInput({ max: today() });
            var evaluated = dateInput({ max: today() });
            var issued = dateInput({ max: today(), required: true, value: today() });
            var expires = dateInput({});
            var expiresHint = el('div', { class: 'form-hint' });
            var expiresTouched = false;
            var version = el('select', { class: 'form-select' });
            var scan = new ScanField({ onChange: sync });
            var noScan = el('input', { type: 'checkbox', class: 'form-check-input', id: 'tro-x-noscan' });
            var noScanReason = reasonInput(10, 255, 'Why is there no scan? For example: "Card lost, confirmed by phone with the issuer"');
            var notes = textArea({ rows: 2, max: 2000 });

            body.appendChild(field({ label: 'Person', control: picker.root, name: 'contact_id', required: true }));
            var courseField = field({ label: 'Course', control: course, name: 'course_id', required: true });
            var courseMeta = el('div', { class: 'form-hint' });
            course.parentNode.insertBefore(courseMeta, course.nextSibling);
            body.appendChild(courseField);
            body.appendChild(field({ label: 'Kind of record', control: method, name: 'method' }));
            var issuerField = field({ label: 'Issued by', control: issuer, name: 'issuer', required: true });
            var refField = field({ label: 'Card number', control: ref, name: 'ref' });
            body.appendChild(el('div', { class: 'tro-field-row' }, [issuerField, refField]));
            var trainedField = field({ label: 'Trained on', control: trained, name: 'trained_on' });
            var evaluatedField = field({ label: 'Evaluated on', control: evaluated, name: 'evaluated_on' });
            body.appendChild(el('div', { class: 'tro-field-row' }, [trainedField, evaluatedField]));
            var issuedField = field({ label: 'Issue date', control: issued, name: 'completed_on', required: true });
            var expiresField = field({ label: 'Expires', control: expires, name: 'expires_on' });
            expiresField.insertBefore(expiresHint, expiresField.querySelector('.invalid-feedback'));
            body.appendChild(el('div', { class: 'tro-field-row' }, [issuedField, expiresField]));
            var versionField = field({ label: 'Version signed', control: version, name: 'revision_id', hint: 'The version of the document this paper acknowledgment is for.' });
            body.appendChild(versionField);
            var scanField = field({ label: 'Scan of the card or record', control: scan.root, name: 'evidence_token' });
            scanField.appendChild(el('div', { class: 'form-check mt-2' }, [noScan, el('label', { class: 'form-check-label', for: 'tro-x-noscan', text: 'No scan available' })]));
            var noScanWrap = el('div', { class: 'mt-2', hidden: true }, [noScanReason.input, noScanReason.counter, el('div', { class: 'invalid-feedback', dataset: { field: 'no_evidence_reason' } })]);
            scanField.appendChild(noScanWrap);
            body.appendChild(scanField);
            body.appendChild(field({ label: 'Notes', control: notes, name: 'notes' }));

            var submit = el('button', { type: 'button', class: 'btn btn-primary', text: 'Save record' });
            var h = sheet({
                title: 'Record external card or paper record', subtitle: 'Training done outside the LMS, entered by the office.', body: body,
                foot: [el('span', { class: 'tro-oc__note', text: 'Records cannot be edited after saving. A wrong record is voided.' }), cancelBtn(), submit],
                onHidden: function () { resolve(result); }
            });

            function card() { return courseById(course.value); }
            function isExternal() { return methodExternal.checked; }
            function validity() { var c = (comp && comp.course) || card(); return c && c.validity_months ? Number(c.validity_months) : null; }
            function refreshExpiry() {
                var v = validity();
                if (!expiresTouched) { expires.value = (v && isYmd(issued.value)) ? addMonths(issued.value, v) : ''; }
                expiresHint.textContent = v ? 'Course default: ' + plural(v, 'month') + ' after the issue date.' + (expiresTouched ? ' ' : '') : 'This course does not expire. Set a date only if the card has one.';
                if (expiresTouched && v) {
                    var reset = el('a', { href: '#', class: 'ms-1', text: 'Use default', on: { click: function (e) { e.preventDefault(); expiresTouched = false; refreshExpiry(); sync(); } } });
                    expiresHint.appendChild(reset);
                }
            }
            function refreshCourse() {
                var c = card();
                courseMeta.textContent = c ? courseSummary(c) : '';
                comp = null;
                var isDoc = c && c.kind === 'document';
                versionField.hidden = !isDoc;
                evaluatedField.hidden = !(c && c.needs && c.needs.practical);
                while (version.firstChild) { version.removeChild(version.firstChild); }
                if (c) {
                    version.appendChild(el('option', { value: String(c.revision_id || ''), text: 'Version ' + (c.revision_number || '?') + ' (current)' }));
                    fetchAction('course_components', { course_id: c.id }).then(function (d) {
                        if (Number(course.value) !== Number(c.id)) { return; }
                        comp = d || null;
                        var revs = (d && d.revisions) || [];
                        if (revs.length) {
                            while (version.firstChild) { version.removeChild(version.firstChild); }
                            revs.forEach(function (r, i) { version.appendChild(el('option', { value: String(r.id), text: 'Version ' + r.number + (i === 0 ? ' (current)' : '') })); });
                        }
                        refreshExpiry();
                    }, function () { /* the card from the page is enough */ });
                }
                refreshExpiry();
            }
            function sync() {
                issuerField.querySelector('.form-label').classList.toggle('required', isExternal());
                noScanWrap.hidden = !noScan.checked;
                scan.root.hidden = noScan.checked;
                var ok = picker.ids().length === 1 && !!course.value && isYmd(issued.value) && issued.value <= today()
                    && (!isExternal() || issuer.value.trim() !== '')
                    && (noScan.checked ? noScanReason.ok() : !!scan.token());
                submit.disabled = !ok;
            }
            [methodExternal, methodPaper].forEach(function (r) { r.addEventListener('change', sync); });
            course.addEventListener('change', function () { refreshCourse(); sync(); });
            issued.addEventListener('input', function () { refreshExpiry(); sync(); });
            expires.addEventListener('input', function () { expiresTouched = true; refreshExpiry(); });
            [issuer, noScan].forEach(function (n) { n.addEventListener('input', sync); n.addEventListener('change', sync); });
            noScanReason.input.addEventListener('input', sync);
            refreshCourse();
            sync();

            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                clearFieldErrors(body);
                alertBox(body, null);
                var c = card();
                var payload = {
                    request_uid: requestUid,
                    contact_id: picker.ids()[0],
                    course_id: Number(course.value),
                    method: isExternal() ? 'external' : 'legacy_paper',
                    issuer: issuer.value.trim() || null,
                    ref: ref.value.trim() || null,
                    trained_on: trained.value || null,
                    evaluated_on: evaluatedField.hidden ? null : (evaluated.value || null),
                    completed_on: issued.value,
                    expires_on: expires.value || null,
                    revision_id: c && c.kind === 'document' && version.value ? Number(version.value) : null,
                    evidence_token: noScan.checked ? null : scan.token(),
                    no_evidence_reason: noScan.checked ? noScanReason.input.value.trim() : null,
                    notes: notes.value.trim() || null
                };
                busy(submit, true, 'Saving…');
                post('completion_record', payload).then(function (d) {
                    result = d || {};
                    var comp2 = result.completion || {};
                    var wrap = el('div', {}, [
                        el('div', { class: 'alert alert-success d-flex gap-2', role: 'status' }, [icon('fas fa-check-circle mt-1'), el('div', {}, [
                            el('div', { class: 'fw-semibold', text: 'Record saved' + (comp2.cert_number ? ': ' + comp2.cert_number : '') + '.' }),
                            el('div', { text: (picker.people()[0] || {}).name + ' · ' + (c ? c.name : '') })
                        ])])
                    ]);
                    h.setTitle('Record external card or paper record', 'Saved');
                    h.setBody(wrap);
                    h.setFoot([
                        comp2.id ? el('a', { class: 'btn btn-outline-secondary', href: recordUrl(comp2.id), text: 'Open record' }) : null,
                        (result.certificate_url || comp2.id) ? el('a', { class: 'btn btn-outline-secondary', href: result.certificate_url || certificateUrl(comp2.id), target: '_blank', rel: 'noopener', text: 'Open certificate' }) : null,
                        el('button', { type: 'button', class: 'btn btn-primary ms-auto', text: 'Done', dataset: { bsDismiss: 'offcanvas' } })
                    ]);
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err) || err.code !== 'validation') { alertBox(body, errorText(err)); }
                });
            });
            setTimeout(function () { if (!opts.person) { picker.input.focus(); } }, 350);
        });
    }

    /** Practical evaluation (S1). opts: {person, courseId, trainers}. */
    function openEvaluation(opts) {
        return new Promise(function (resolve) {
            var result = null;
            var requestUid = uid32();
            var body = el('form', { novalidate: true, autocomplete: 'off' });
            var picker = new PeoplePicker({ multiple: false, initial: opts.person ? [opts.person] : [], placeholder: 'Who was evaluated?', onChange: sync });
            var course = courseSelect({ value: opts.courseId, required: true, filter: function (c) { return c.needs && c.needs.practical; }, placeholder: 'Choose a course with a practical…' });
            var evaluatorSel = el('select', { class: 'form-select' }, [el('option', { value: '', text: 'Choose the evaluator…' })]);
            var outsideName = el('input', { type: 'text', class: 'form-control mt-2', maxlength: '200', placeholder: 'Outside evaluator’s name and company', hidden: true });
            var date = dateInput({ value: today(), max: today(), required: true });
            var equipment = el('input', { type: 'text', class: 'form-control', maxlength: '200', placeholder: 'For example: Toyota 8FGU25, unit #4' });
            var checklistBox = el('div', {});
            var resultPass = el('button', { type: 'button', class: 'is-pass', 'aria-pressed': 'true', text: 'Pass' });
            var resultFail = el('button', { type: 'button', class: 'is-fail', 'aria-pressed': 'false', text: 'Fail' });
            var resultCtl = el('div', { class: 'tro-tri', role: 'group', 'aria-label': 'Result' }, [resultPass, resultFail]);
            var resultNote = el('div', { class: 'form-hint' });
            var notes = textArea({ rows: 2, max: 2000 });
            var scan = new ScanField({ required: true, onChange: sync, emptyText: 'Scan of the signed checklist (required)' });
            var items = [];   // [{item, critical, result}]
            var chosenResult = 'pass';

            body.appendChild(field({ label: 'Person evaluated', control: picker.root, name: 'contact_id', required: true }));
            var cf = field({ label: 'Course', control: course, name: 'course_id', required: true });
            body.appendChild(cf);
            var evalField = field({ label: 'Evaluator', control: evaluatorSel, name: 'evaluator_contact_id', required: true });
            evalField.insertBefore(outsideName, evalField.querySelector('.invalid-feedback'));
            body.appendChild(evalField);
            body.appendChild(el('div', { class: 'tro-field-row' }, [field({ label: 'Date', control: date, name: 'evaluated_on', required: true }), field({ label: 'Equipment', control: equipment, name: 'equipment' })]));
            body.appendChild(field({ label: 'Checklist', control: checklistBox, name: 'checklist' }));
            var rf = field({ label: 'Result', control: resultCtl, name: 'result' });
            rf.insertBefore(resultNote, rf.querySelector('.invalid-feedback'));
            body.appendChild(rf);
            body.appendChild(field({ label: 'Scan', control: scan.root, name: 'evidence_token', required: true, hint: 'The signed checklist is the evidence for this record.' }));
            body.appendChild(field({ label: 'Notes', control: notes, name: 'notes' }));

            var submit = el('button', { type: 'button', class: 'btn btn-primary', text: 'Save evaluation' });
            var h = sheet({ title: 'Record practical evaluation', subtitle: 'Hands-on check by a qualified evaluator.', body: body, foot: [cancelBtn(), submit], onHidden: function () { resolve(result); } });

            // Evaluators: active trainers who can evaluate (trainer_list), or an outside name.
            fetchAction('trainer_list', {}).then(function (d) {
                ((d && d.trainers) || []).filter(function (t) { return t.active !== false && t.flags && t.flags.can_evaluate; }).forEach(function (t) {
                    evaluatorSel.appendChild(el('option', { value: String(t.contact_id), text: (t.person ? t.person.name : 'Trainer #' + t.contact_id) + (t.title ? ' · ' + t.title : '') }));
                });
            }, function () { /* outside evaluator still works */ }).then(function () {
                evaluatorSel.appendChild(el('option', { value: 'outside', text: 'Outside evaluator (type a name)…' }));
            });
            evaluatorSel.addEventListener('change', function () { outsideName.hidden = evaluatorSel.value !== 'outside'; if (!outsideName.hidden) { outsideName.focus(); } sync(); });
            outsideName.addEventListener('input', sync);

            function setResult(r) {
                chosenResult = r;
                resultPass.setAttribute('aria-pressed', r === 'pass' ? 'true' : 'false');
                resultFail.setAttribute('aria-pressed', r === 'fail' ? 'true' : 'false');
                sync();
            }
            resultPass.addEventListener('click', function () { if (!resultPass.disabled) { setResult('pass'); } });
            resultFail.addEventListener('click', function () { setResult('fail'); });

            function renderChecklist() {
                while (checklistBox.firstChild) { checklistBox.removeChild(checklistBox.firstChild); }
                var c = courseById(course.value);
                var src = (c && c.checklist) || [];
                items = src.map(function (it) { return { item: it.item, critical: !!it.critical, result: 'pass' }; });
                if (!c) { checklistBox.appendChild(el('div', { class: 'form-hint', text: 'Choose a course to load its checklist.' })); return; }
                if (!items.length) { checklistBox.appendChild(el('div', { class: 'form-hint', text: 'This course has no checklist. Record the overall result below.' })); return; }
                var list = el('ul', { class: 'tro-checklist' });
                items.forEach(function (it) {
                    var p = el('button', { type: 'button', class: 'is-pass', 'aria-pressed': 'true', text: 'Pass' });
                    var f = el('button', { type: 'button', class: 'is-fail', 'aria-pressed': 'false', text: 'Fail' });
                    function set(r) { it.result = r; p.setAttribute('aria-pressed', r === 'pass' ? 'true' : 'false'); f.setAttribute('aria-pressed', r === 'fail' ? 'true' : 'false'); applyCritical(); }
                    p.addEventListener('click', function () { set('pass'); });
                    f.addEventListener('click', function () { set('fail'); });
                    list.appendChild(el('li', {}, [el('div', { class: 'tro-checklist__item' }, [el('span', { text: it.item }), it.critical ? chip('Critical', 'err', null, { class: 'tro-chip tro-chip--err tro-chip--sm ms-2' }) : null]),
                        el('div', { class: 'tro-tri', role: 'group', 'aria-label': it.item }, [p, f])]));
                });
                checklistBox.appendChild(list);
            }
            function applyCritical() {
                var critFail = items.some(function (it) { return it.critical && it.result === 'fail'; });
                resultPass.disabled = critFail;
                if (critFail) { setResult('fail'); resultNote.textContent = 'A critical item failed, so the result is Fail.'; }
                else { resultNote.textContent = items.some(function (it) { return it.result === 'fail'; }) ? 'Some items failed. Choose the overall result.' : ''; sync(); }
            }
            course.addEventListener('change', function () { renderChecklist(); applyCritical(); sync(); });
            date.addEventListener('input', sync);
            renderChecklist();

            function evaluatorOk() { return evaluatorSel.value === 'outside' ? outsideName.value.trim() !== '' : evaluatorSel.value !== ''; }
            function sync() {
                submit.disabled = !(picker.ids().length === 1 && course.value && evaluatorOk() && isYmd(date.value) && date.value <= today() && scan.token());
                if (evaluatorSel.value !== 'outside' && evaluatorSel.value !== '' && picker.ids()[0] === Number(evaluatorSel.value)) {
                    submit.disabled = true;
                    resultNote.textContent = 'The evaluator cannot evaluate themselves.';
                }
            }
            sync();

            submit.addEventListener('click', function () {
                if (submit.disabled) { return; }
                clearFieldErrors(body);
                alertBox(body, null);
                var outside = evaluatorSel.value === 'outside';
                var payload = {
                    request_uid: requestUid,
                    contact_id: picker.ids()[0],
                    course_id: Number(course.value),
                    evaluator_contact_id: outside ? null : Number(evaluatorSel.value),
                    evaluator_name: outside ? outsideName.value.trim() : null,
                    evaluated_on: date.value,
                    equipment: equipment.value.trim() || null,
                    checklist: items.map(function (it) { return { item: it.item, critical: it.critical, result: it.result }; }),
                    result: chosenResult,
                    notes: notes.value.trim() || null,
                    evidence_token: scan.token()
                };
                busy(submit, true, 'Saving…');
                post('evaluation_record', payload).then(function (d) {
                    result = d || {};
                    var passed = result.result === 'pass';
                    var line = passed
                        ? (result.completion_id ? 'A training record was issued.' : (result.pending ? 'Saved. The record is issued once the rest of the course is done: ' + (typeof result.pending === 'string' ? result.pending : 'other parts are still open') + '.' : 'Saved.'))
                        : 'No record is issued for a failed evaluation. Schedule another one when they are ready.';
                    h.setTitle('Record practical evaluation', 'Saved');
                    h.setBody(el('div', { class: 'alert ' + (passed ? 'alert-success' : 'alert-warning') + ' d-flex gap-2', role: 'status' }, [icon((passed ? 'fas fa-check-circle' : 'fas fa-times-circle') + ' mt-1'), el('div', {}, [
                        el('div', { class: 'fw-semibold', text: 'Evaluation recorded: ' + (passed ? 'Pass' : 'Fail') }), el('div', { text: line })
                    ])]));
                    h.setFoot([
                        result.completion_id ? el('a', { class: 'btn btn-outline-secondary', href: recordUrl(result.completion_id), text: 'Open record' }) : null,
                        el('button', { type: 'button', class: 'btn btn-primary ms-auto', text: 'Done', dataset: { bsDismiss: 'offcanvas' } })
                    ]);
                }, function (err) {
                    busy(submit, false);
                    if (!showFieldErrors(body, err) || err.code !== 'validation') { alertBox(body, errorText(err)); }
                });
            });
        });
    }

    var FORMS = {
        assign: openAssign, extend: openExtend, waive: openWaive, 'void': openVoid, hire_date: openHireDate,
        roster: openRoster, history: openHistory, external: openExternal, evaluation: openEvaluation
    };

    window.TrainingOps = {
        init: function (d) {
            d = d || {};
            if (d.today) { S.today = d.today; }
            S.level = Number(d.level || 0);
            S.userId = Number(d.user_id || 0);
            S.courses = Array.isArray(d.courses) ? d.courses : S.courses;
            S.departments = Array.isArray(d.departments) ? d.departments : S.departments;
            if (d.settings) { S.settings = Object.assign({}, S.settings, d.settings); }
            if (d.links) { S.links = Object.assign({}, S.links, d.links); }
            if (d.routes) { S.routes = d.routes; }
            return S;
        },
        state: S,
        can: function (n) { return S.level >= n; },
        open: function (kind, opts) {
            var fn = FORMS[kind];
            if (!fn) { return Promise.reject(new Error('Unknown form ' + kind)); }
            return fn(opts || {});
        },
        sheet: sheet,
        PeoplePicker: PeoplePicker,
        ScanField: ScanField,
        u: {
            el: el, icon: icon, chip: chip, avatar: avatar, initials: initials, plural: plural,
            today: today, addDays: addDays, addMonths: addMonths, diffDays: diffDays, isYmd: isYmd,
            fmtDate: fmtDate, fmtDateTime: fmtDateTime, relTime: relTime,
            personCell: personCell, personMeta: personMeta, statusChip: statusChip, certChip: certChip, strength: strength,
            STRENGTH_LABELS: STRENGTH_LABELS, METHOD_LABELS: METHOD_LABELS, EVENT_LABELS: EVENT_LABELS,
            courseSummary: courseSummary, courseById: courseById, courseSelect: courseSelect,
            emptyState: emptyState, failState: failState, skeletonRows: skeletonRows, errorText: errorText,
            busy: busy, post: post, load: load, fetchAction: fetchAction, uid32: uid32,
            kebab: kebab, pager: pager, setUrl: setUrl, clear: clearNode, switchRow: switchRow,
            field: field, dateInput: dateInput, textArea: textArea, reasonInput: reasonInput, cancelBtn: cancelBtn,
            showFieldErrors: showFieldErrors, clearFieldErrors: clearFieldErrors, alertBox: alertBox, timeline: timeline,
            transcriptUrl: transcriptUrl, recordUrl: recordUrl, certificateUrl: certificateUrl, uploadEvidence: uploadEvidence
        }
    };
})();
