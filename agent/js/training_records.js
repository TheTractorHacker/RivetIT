/*
 * Training › Records & sessions (Phase 2 spec §5.2): the records log, the sessions list and
 * the one-record evidence page (training_record.php). DOM nodes only; evidence images load
 * from /agent/training_evidence.php (authorized by the record that references the scan).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var UI = window.TrainingUi;
        var Ops = window.TrainingOps;
        if (!UI || !Ops) { return; }
        var u = Ops.u;
        var el = u.el;
        var D = UI.readJson('tr-page-data');
        Ops.init(D);
        var level = Number(D.level || 0);
        var routes = D.routes || {};
        var $ = function (id) { return document.getElementById(id); };
        function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }
        function setUrl(params) {
            var qs = new URLSearchParams();
            Object.keys(params).forEach(function (k) { if (params[k] !== null && params[k] !== undefined && params[k] !== '') { qs.set(k, String(params[k])); } });
            try { window.history.replaceState(null, '', window.location.pathname + (qs.toString() ? '?' + qs.toString() : '')); } catch (e) { /* ignore */ }
        }
        function pagerInto(pager, total, shown, pageNo, go) {
            clear(pager);
            pager.hidden = total === 0;
            if (!total) { return; }
            var per = 50;
            var from = (pageNo - 1) * per + 1;
            pager.appendChild(el('span', { text: 'Showing ' + from + '–' + (from + shown - 1) + ' of ' + total }));
            if (total > per) {
                pager.appendChild(el('div', { class: 'btn-group' }, [
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo <= 1, on: { click: function () { go(pageNo - 1); } } }, [u.icon('fas fa-chevron-left me-1'), 'Previous']),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo * per >= total, on: { click: function () { go(pageNo + 1); } } }, ['Next', u.icon('fas fa-chevron-right ms-1')])
                ]));
            }
        }
        function methodCell(c) {
            return el('div', {}, [u.strength(c.strength, c.strength_label), el('span', { class: 'tro-sub', text: u.METHOD_LABELS[c.method] || c.method || '' })]);
        }

        // ---- legend (records page) --------------------------------------------------------
        var legend = $('tro-rec-legend');
        if (legend) {
            var hints = { A: 'employee signed at the kiosk with their PIN', B: 'trainer and employee both signed', C: 'the trainer vouches; the employee did not sign',
                D: 'a scan of a card, certificate or signed sheet is on file', E: 'entered by the office with nothing signed' };
            legend.appendChild(el('div', { class: 'd-grid gap-2' }, ['A', 'B', 'C', 'D', 'E'].map(function (k) {
                return el('div', { class: 'd-flex align-items-center gap-2 flex-wrap' }, [u.strength(k, u.STRENGTH_LABELS[k]), el('span', { class: 'text-muted small', text: hints[k] })]);
            })));
        }

        // ---- header actions (hidden while their action is not installed) ----------------------
        (function () {
            var ext = $('tro-rec-external'), more = $('tro-rec-more'), ev = $('tro-rec-eval'), ses = $('tro-rec-session'), csvA = $('tro-rec-csv');
            if (ext) { ext.hidden = routes.completion_record === false; }
            if (ev) { ev.parentNode.hidden = routes.evaluation_record === false; }
            if (ses) { ses.parentNode.hidden = routes.session_save === false; }
            if (more) { more.hidden = routes.evaluation_record === false && routes.session_save === false; }
            if (ext && more && ext.hidden) { more.classList.remove('dropdown-toggle-split'); more.setAttribute('aria-label', 'Record training'); }
            if (ext && more && ext.hidden && more.hidden) { ext.parentNode.hidden = true; }
            if (csvA) { csvA.hidden = routes.report_csv === false; }
        })();
        var extBtn = $('tro-rec-external');
        if (extBtn) {
            extBtn.addEventListener('click', function () {
                Ops.open('external', {}).then(function (res) { if (res && log) { log.refetch(); } });
            });
        }
        var evalBtn = $('tro-rec-eval');
        if (evalBtn) {
            evalBtn.addEventListener('click', function () {
                Ops.open('evaluation', {}).then(function (res) { if (res && log) { log.refetch(); } });
            });
        }

        // ============================================================================
        // Records log
        // ============================================================================
        var log = null;
        if ($('tro-rec-body')) {
            log = (function () {
                var f = Object.assign({ voided: 'include', page: 1 }, D.filters || {});
                var body = $('tro-rec-body'), empty = $('tro-rec-empty'), pager = $('tro-rec-pager');
                var seq = 0;
                var inputs = { q: $('tro-rec-q'), course_id: $('tro-rec-course'), client_id: $('tro-rec-dept'), method: $('tro-rec-method'), strength: $('tro-rec-strength'), voided: $('tro-rec-voided'), from: $('tro-rec-from'), to: $('tro-rec-to') };
                function params() {
                    var p = {};
                    Object.keys(f).forEach(function (k) { if (f[k] !== null && f[k] !== undefined && f[k] !== '') { p[k] = f[k]; } });
                    if (p.voided === 'include') { delete p.voided; }
                    if (Number(p.page) === 1) { delete p.page; }
                    return p;
                }
                function csvHref() {
                    var p = params();
                    delete p.page;
                    var qs = new URLSearchParams(Object.assign({ action: 'report_csv', report: 'records' }, p));
                    return '/agent/training_ajax.php?' + qs.toString();
                }
                var csv = $('tro-rec-csv');
                function refetch() {
                    var mySeq = ++seq;
                    u.skeletonRows(body, 8, 8);
                    clear(empty);
                    var p = params();
                    setUrl(p);
                    if (csv) { csv.href = csvHref(); }
                    var q = Object.assign({}, p);
                    if (!q.voided) { q.voided = 'include'; }
                    u.fetchAction('completion_list', q).then(function (d) { if (mySeq === seq) { render(d || {}); } }, function (err) {
                        if (mySeq === seq) { clear(body); pager.hidden = true; empty.appendChild(u.failState(err, refetch)); }
                    });
                }
                function render(d) {
                    var rows = d.rows || [];
                    clear(body);
                    clear(empty);
                    rows.forEach(function (c) { body.appendChild(row(c)); });
                    if (!rows.length) {
                        var filtered = Object.keys(params()).length > 0;
                        empty.appendChild(D.scope === 'none'
                            ? u.emptyState({ icon: 'fas fa-user-lock', title: 'No people to show', text: 'Ask an administrator to grant department access to see people.' })
                            : filtered
                                ? u.emptyState({ icon: 'fas fa-filter', title: 'No records match', text: 'Try different filters.', actions: [el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Clear filters', on: { click: clearFilters } })] })
                                : u.emptyState({ icon: 'fas fa-id-card', title: 'No training records yet', text: 'Records appear when people finish courses on the kiosk, when a session is finalized, or when the office records an outside card.',
                                    actions: level >= 2 && routes.completion_record !== false ? [el('button', { type: 'button', class: 'btn btn-primary', text: 'Record an external card', on: { click: function () { extBtn.click(); } } })] : [] }));
                    }
                    pagerInto(pager, Number(d.total || 0), rows.length, Number(f.page || 1), function (n) { f.page = n; refetch(); });
                }
                function row(c) {
                    var voided = !!c.voided;
                    var name = c.person ? c.person.name : '';
                    var who = el('div', {}, [u.personCell({ contact_id: c.person && c.person.contact_id, name: name }, { sub: c.person && c.person.current_name && c.person.current_name !== name ? 'now ' + c.person.current_name : '' })]);
                    return el('tr', { class: voided ? 'is-voided' : null, dataset: { id: c.id } }, [
                        el('td', { class: 'tro-nowrap' }, [el('a', { class: 'tro-mono tro-link-strong' + (voided ? ' tro-strike' : ''), href: u.recordUrl(c.id), text: c.cert_number || ('#' + c.id) }),
                            c.course && c.course.kind === 'document' ? el('span', { class: 'tro-sub', text: 'Acknowledgment' }) : null]),
                        el('td', {}, [who]),
                        el('td', {}, [el('span', { class: voided ? 'tro-strike' : null, text: c.course ? c.course.name : '' }), c.revision && c.revision.number ? el('span', { class: 'tro-sub', text: 'Version ' + c.revision.number }) : null]),
                        el('td', {}, [methodCell(c)]),
                        el('td', { class: 'tro-nowrap' }, [el('span', { class: voided ? 'tro-strike' : null, text: u.fmtDate(c.completed_on) }), c.score_pct ? el('span', { class: 'tro-sub', text: 'Score ' + Math.round(Number(c.score_pct)) + '%' }) : null]),
                        el('td', { class: 'tro-nowrap', text: c.expires_on ? u.fmtDate(c.expires_on) : 'Does not expire' }),
                        el('td', {}, [u.certChip(c.cert_status, voided ? 'Voided' : c.cert_status_label)]),
                        el('td', {}, [el('span', { text: c.recorded_by_name || (c.method === 'online' ? 'Kiosk' : '') }), el('span', { class: 'tro-sub', text: u.relTime(c.recorded_at), title: u.fmtDateTime(c.recorded_at) })])
                    ]);
                }
                function clearFilters() {
                    f = { voided: 'include', page: 1 };
                    Object.keys(inputs).forEach(function (k) { if (inputs[k]) { inputs[k].value = k === 'voided' ? 'include' : ''; } });
                    refetch();
                }
                var onSearch = UI.debounce(function () { f.q = inputs.q.value.trim() || null; f.page = 1; refetch(); }, 300);
                inputs.q.addEventListener('input', onSearch);
                ['course_id', 'client_id', 'method', 'strength', 'voided', 'from', 'to'].forEach(function (k) {
                    inputs[k].addEventListener('change', function () {
                        var v = inputs[k].value;
                        f[k] = v === '' ? null : ((k === 'course_id' || k === 'client_id') ? Number(v) : v);
                        f.page = 1;
                        refetch();
                    });
                });
                $('tro-rec-filters').addEventListener('submit', function (e) { e.preventDefault(); onSearch.flush(); });
                if (csv) { csv.href = csvHref(); }
                u.skeletonRows(body, 8, 8);
                u.load(D.list).then(render, function (err) { clear(body); empty.appendChild(u.failState(err, refetch)); });
                return { refetch: refetch };
            })();
        }

        // ============================================================================
        // Sessions list
        // ============================================================================
        if ($('tro-ses-body')) {
            (function () {
                var f = Object.assign({ page: 1 }, D.sfilters || {});
                var body = $('tro-ses-body'), empty = $('tro-ses-empty'), pager = $('tro-ses-pager'), seg = $('tro-ses-status');
                var seq = 0;
                var STATUS = [[null, 'All'], ['open', 'Open'], ['finalized', 'Finalized'], ['cancelled', 'Cancelled']];
                function renderSeg() {
                    clear(seg);
                    STATUS.forEach(function (s) {
                        seg.appendChild(el('button', { type: 'button', class: 'tro-seg__btn', 'aria-pressed': (f.status || null) === s[0] ? 'true' : 'false',
                            on: { click: function () { f.status = s[0]; f.page = 1; refetch(); } } }, [el('span', { text: s[1] })]));
                    });
                }
                function refetch() {
                    var mySeq = ++seq;
                    renderSeg();
                    u.skeletonRows(body, 7, 5);
                    clear(empty);
                    var p = {};
                    Object.keys(f).forEach(function (k) { if (f[k] !== null && f[k] !== undefined && f[k] !== '') { p[k] = f[k]; } });
                    if (Number(p.page) === 1) { delete p.page; }
                    setUrl(Object.assign({ tab: 'sessions' }, p));
                    u.fetchAction('session_list', p).then(function (d) { if (mySeq === seq) { render(d || {}); } }, function (err) {
                        if (mySeq === seq) { clear(body); pager.hidden = true; empty.appendChild(u.failState(err, refetch)); }
                    });
                }
                var SCHIP = { open: ['warn', 'far fa-edit', 'Draft'], finalized: ['ok', 'fas fa-lock', 'Finalized'], cancelled: ['outline', null, 'Cancelled'] };
                function render(d) {
                    var rows = d.rows || [];
                    clear(body);
                    clear(empty);
                    rows.forEach(function (s) {
                        var st = SCHIP[s.status] || ['outline', null, s.status];
                        var trainer = s.trainer ? (s.trainer.name || '') : (s.trainer_name || '');
                        body.appendChild(el('tr', {}, [
                            el('td', { class: 'tro-nowrap' }, [el('a', { class: 'tro-link-strong', href: '/agent/training_session.php?id=' + encodeURIComponent(s.id), text: u.fmtDate(s.held_on) })]),
                            el('td', { text: s.course ? s.course.name : '' }),
                            el('td', { text: s.topic || '—' }),
                            el('td', { text: trainer || '—' }),
                            el('td', { text: s.department ? s.department.name : '—' }),
                            el('td', { class: 'tro-num', text: (s.present !== undefined ? s.present : '?') + ' / ' + (s.total !== undefined ? s.total : '?') }),
                            el('td', {}, [el('div', { class: 'tro-chips' }, [u.chip(st[2], st[0], st[1]), s.is_backfill ? u.chip('Backfill', 'info', 'fas fa-history') : null])])
                        ]));
                    });
                    if (!rows.length) {
                        empty.appendChild(u.emptyState({ icon: 'fas fa-chalkboard-teacher', title: f.status ? 'No sessions with this status' : 'No sessions yet',
                            text: 'A session records a classroom or hands-on class: who attended, who taught it, and the signed sheet.',
                            actions: level >= 2 && routes.session_save !== false ? [el('a', { class: 'btn btn-primary', href: '/agent/training_session.php?new=1', text: 'New session' })] : [] }));
                    }
                    pagerInto(pager, Number(d.total || 0), rows.length, Number(f.page || 1), function (n) { f.page = n; refetch(); });
                }
                renderSeg();
                u.skeletonRows(body, 7, 5);
                u.load(D.sessions).then(render, function (err) { clear(body); empty.appendChild(u.failState(err, refetch)); });
            })();
        }

        // ============================================================================
        // One record (training_record.php)
        // ============================================================================
        var recHost = $('tro-record');
        if (recHost && D.record_id) {
            var render = function (c) {
                clear(recHost);
                var voided = !!c.voided;
                var name = c.person ? c.person.name : '';
                var current = c.person && c.person.current_name && c.person.current_name !== name ? c.person.current_name : null;
                var isDoc = c.course && c.course.kind === 'document';

                var actions = el('div', { class: 'tro-rec-head__actions' });
                if (c.certificate_url || c.id) {
                    actions.appendChild(el('a', { class: 'btn btn-outline-secondary', href: c.certificate_url || u.certificateUrl(c.id), target: '_blank', rel: 'noopener' }, [u.icon('fas fa-certificate me-2'), isDoc ? 'Open acknowledgment' : 'Open certificate']));
                }
                if (level >= 3 && !voided && routes.completion_void !== false) {
                    actions.appendChild(el('button', { type: 'button', class: 'btn btn-outline-danger', on: { click: function () {
                        Ops.open('void', { container: voidHost, completion: c }).then(function (res) { if (res) { reload(); } });
                    } } }, [u.icon('fas fa-ban me-2'), 'Void…']));
                }
                var headCard = el('div', { class: 'tro-card' });
                headCard.appendChild(el('div', { class: 'tro-rec-head' }, [
                    el('div', { class: 'tro-rec-head__main' }, [
                        el('div', { class: 'd-flex align-items-center gap-3' }, [u.avatar(name, 'lg'), el('div', {}, [
                            el('h2', { class: 'tro-rec-head__title' }, [c.person && c.person.contact_id ? el('a', { class: 'tro-link-strong', href: u.transcriptUrl(c.person.contact_id), text: name }) : el('span', { text: name })]),
                            current ? el('div', { class: 'tro-sub', text: 'Recorded as ' + name + ' · now ' + current }) : null
                        ])]),
                        el('div', { class: 'tro-rec-head__meta' }, [
                            u.certChip(c.cert_status, voided ? 'Voided' : c.cert_status_label),
                            u.strength(c.strength, c.strength_label),
                            c.cert_number ? el('span', { class: 'tro-mono', text: c.cert_number }) : el('span', { text: isDoc ? 'Acknowledgment record' : '' }),
                            c.external ? u.chip(c.method === 'legacy_paper' ? 'Paper record on file' : 'External card recorded', 'info', 'fas fa-id-card') : null
                        ])
                    ]),
                    actions
                ]));
                var voidHost = el('div', { class: 'px-4' });
                headCard.appendChild(voidHost);
                if (voided) {
                    headCard.appendChild(el('div', { class: 'tr-banner alert alert-danger tro-void-banner', role: 'status' }, [u.icon('fas fa-ban mt-1'), el('div', {}, [
                        el('div', { class: 'fw-semibold', text: 'This record was voided' + (c.voided.by_name ? ' by ' + c.voided.by_name : '') + ' on ' + u.fmtDateTime(c.voided.at) + '.' }),
                        el('div', { text: 'Reason: ' + (c.voided.reason || '') }),
                        el('div', { class: 'small', text: 'It stays on file, and its certificate no longer verifies.' })
                    ])]));
                }
                var F = [];
                function add(label, value, cls) { F.push(el('div', {}, [el('dt', { text: label }), el('dd', { class: (value === null || value === undefined || value === '') ? 'is-empty' : (cls || null), text: (value === null || value === undefined || value === '') ? '—' : String(value) })])); }
                add('Record number', c.cert_number || (isDoc ? 'No number (acknowledgment)' : ''), 'tro-mono');
                add('Course', (c.course ? c.course.name : '') + (c.course && c.course.code ? ' (' + c.course.code + ')' : ''));
                add('Version', c.revision ? 'Version ' + c.revision.number : '');
                add('Method', u.METHOD_LABELS[c.method] || c.method);
                add('Evidence', (c.strength ? c.strength + ' · ' : '') + (c.strength_label || ''));
                add('Completed on', u.fmtDate(c.completed_on));
                add('Trained on', u.fmtDate(c.trained_on));
                if (c.evaluated_on) { add('Evaluated on', u.fmtDate(c.evaluated_on)); }
                add('Expires', c.expires_on ? u.fmtDate(c.expires_on) : 'Does not expire');
                if (c.score_pct !== null && c.score_pct !== undefined) { add('Score', Math.round(Number(c.score_pct)) + '%' + (c.pass_mark_pct ? ' (pass mark ' + c.pass_mark_pct + '%)' : '')); }
                if (c.attempts_used) { add('Attempts', c.attempts_used); }
                if (c.duration_minutes) { add('Time spent', c.duration_minutes + ' min'); }
                add('Language', (c.language || '').toUpperCase());
                if (c.trainer_name) { add('Trainer', c.trainer_name); }
                if (c.evaluator_name) { add('Evaluator', c.evaluator_name); }
                if (c.external) { add('Issued by', c.external.issuer); add('Card number', c.external.ref); }
                add('Recorded', u.fmtDateTime(c.recorded_at) + (c.recorded_by_name ? ' by ' + c.recorded_by_name : ''));
                add('Name on the record', name + (current ? ' (now ' + current + ')' : ''));
                if (c.notes) { add('Notes', c.notes); }
                var grid = el('dl', { class: 'tro-fields' }, F);
                if (c.assignment_id) {
                    grid.appendChild(el('div', {}, [el('dt', { text: 'Assignment' }), el('dd', {}, [el('a', { href: '/agent/training_assignments.php?contact_id=' + encodeURIComponent(c.person.contact_id) + '&status=all', text: 'Closed assignment #' + c.assignment_id })])]));
                }
                headCard.appendChild(grid);
                recHost.appendChild(headCard);

                // Components + evidence (left) and Details (right)
                var left = el('div', {});
                var right = el('div', {});
                var comps = c.components || {};
                if (comps.session || comps.evaluation || c.session_id || c.evaluation_id) {
                    var cc = el('div', { class: 'tro-card' }, [el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'How it was done' })])]);
                    var cb = el('div', { class: 'tro-card__body' });
                    if (comps.session || c.session_id) {
                        var s = comps.session || { id: c.session_id };
                        cb.appendChild(el('div', { class: 'd-flex flex-wrap align-items-center gap-2 mb-2' }, [
                            u.icon('fas fa-chalkboard-teacher text-muted'),
                            el('a', { class: 'fw-semibold', href: '/agent/training_session.php?id=' + encodeURIComponent(s.id), text: 'Session' + (s.held_on ? ' on ' + u.fmtDate(s.held_on) : ' #' + s.id) }),
                            s.topic ? el('span', { class: 'text-muted', text: '· ' + s.topic }) : null,
                            s.trainer_name ? el('span', { class: 'text-muted', text: '· ' + s.trainer_name }) : null,
                            s.attendance ? u.chip(s.attendance === 'present' ? 'Present' : s.attendance, s.attendance === 'present' ? 'ok' : 'warn') : null,
                            s.proof ? u.chip(s.proof === 'document' ? 'Signed sheet' : s.proof === 'agent_recorded' ? 'Recorded by office' : s.proof === 'trainer_attested' ? 'Trainer attests' : 'Signed at kiosk', 'outline') : null
                        ]));
                    }
                    if (comps.evaluation) {
                        var e = comps.evaluation;
                        cb.appendChild(el('div', { class: 'd-flex flex-wrap align-items-center gap-2 mb-2' + (comps.session ? ' mt-3' : '') }, [
                            u.icon('fas fa-clipboard-check text-muted'), el('span', { class: 'fw-semibold', text: 'Practical evaluation' + (e.evaluated_on ? ' on ' + u.fmtDate(e.evaluated_on) : '') }),
                            e.evaluator_name ? el('span', { class: 'text-muted', text: '· ' + e.evaluator_name }) : null,
                            e.equipment ? el('span', { class: 'text-muted', text: '· ' + e.equipment }) : null,
                            u.chip(e.result === 'pass' ? 'Pass' : 'Fail', e.result === 'pass' ? 'ok' : 'err', e.result === 'pass' ? 'fas fa-check' : 'fas fa-times')
                        ]));
                        if (Array.isArray(e.checklist) && e.checklist.length) {
                            cb.appendChild(el('ul', { class: 'tro-checklist' }, e.checklist.map(function (it) {
                                return el('li', {}, [el('span', { class: 'tro-checklist__item' }, [el('span', { text: it.item }), it.critical ? u.chip('Critical', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm ms-2' }) : null]),
                                    u.chip(it.result === 'pass' ? 'Pass' : 'Fail', it.result === 'pass' ? 'ok' : 'err', it.result === 'pass' ? 'fas fa-check' : 'fas fa-times')]);
                            })));
                        }
                    }
                    cc.appendChild(cb);
                    left.appendChild(cc);
                }
                // The record's own scan, or else the signed checklist of the evaluation behind it.
                var scanRef = (c.evidence && c.evidence.media_id) ? c.evidence
                    : (comps.evaluation && comps.evaluation.evidence && comps.evaluation.evidence.media_id ? comps.evaluation.evidence : null);
                var ev = el('div', { class: 'tro-card' }, [el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Evidence' }),
                    el('span', { class: 'tro-card__sub', text: scanRef ? (scanRef.original_name || '') + (scanRef !== c.evidence ? ' (evaluation checklist)' : '') : '' })])]);
                var evb = el('div', { class: 'tro-card__body' });
                if (scanRef) {
                    var src = '/agent/training_evidence.php?m=' + encodeURIComponent(scanRef.media_id);
                    if (/^image\//.test(scanRef.mime || '')) {
                        evb.appendChild(el('div', { class: 'tro-evidence' }, [el('a', { href: src, target: '_blank', rel: 'noopener', title: 'Open full size' }, [el('img', { src: src, alt: 'Scan of the ' + (c.method === 'external' ? 'card' : 'signed record') + ' for ' + name })])]));
                        evb.appendChild(el('div', { class: 'mt-2' }, [el('a', { class: 'btn btn-sm btn-outline-secondary', href: src + '&dl=1' }, [u.icon('fas fa-download me-1'), 'Download'])]));
                    } else {
                        evb.appendChild(el('div', { class: 'tro-evidence' }, [el('div', { class: 'tro-evidence__file' }, [u.icon('fas fa-file-pdf'),
                            el('div', { class: 'flex-grow-1' }, [el('div', { class: 'fw-semibold', text: scanRef.original_name || 'Scan (PDF)' }), el('div', { class: 'tro-sub', text: 'PDF · opens as a download' })]),
                            el('a', { class: 'btn btn-sm btn-outline-secondary', href: src + '&dl=1' }, [u.icon('fas fa-download me-1'), 'Download'])])]));
                    }
                } else {
                    evb.appendChild(el('p', { class: 'text-muted mb-0', text: c.method === 'online' ? 'Signed at the kiosk. The signature is part of the record itself.' : 'No scan is attached to this record.' }));
                }
                ev.appendChild(evb);
                left.appendChild(ev);

                var det = el('details', { class: 'tro-details' }, [el('summary', { text: 'Details' })]);
                var dbody = el('div', { class: 'tro-details__body' });
                dbody.appendChild(el('div', { class: 'mb-3' }, [c.hash_ok
                    ? el('span', { class: 'tro-hash tro-hash--ok' }, [u.icon('fas fa-check-circle'), 'Hash verified ✓'])
                    : el('span', { class: 'tro-hash tro-hash--bad' }, [u.icon('fas fa-times-circle'), 'Hash mismatch ✗']),
                    el('div', { class: 'tro-sub', text: c.hash_ok ? 'The stored record matches its fingerprint, so it has not been changed since it was written.' : 'The stored record no longer matches its fingerprint. Tell an administrator.' })]));
                if (c.verify_url) {
                    var vu = el('input', { type: 'text', class: 'form-control', readonly: true, value: c.verify_url, 'aria-label': 'Verification link' });
                    var copyBtn = el('button', { type: 'button', class: 'btn btn-outline-secondary', 'aria-label': 'Copy the verification link' }, [u.icon('far fa-copy')]);
                    copyBtn.addEventListener('click', function () {
                        var done = function () { UI.toast('Verification link copied.'); };
                        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(c.verify_url).then(done, function () { vu.select(); }); }
                        else { vu.select(); }
                    });
                    dbody.appendChild(el('div', { class: 'mb-3' }, [el('div', { class: 'form-label', text: 'Verification link' }), el('div', { class: 'tro-copy' }, [vu, copyBtn]),
                        el('div', { class: 'tro-sub', text: 'Anyone with this link can confirm the certificate is genuine. It is also printed as the QR code.' })]));
                }
                dbody.appendChild(el('div', { class: 'form-label', text: 'Ledger events' }));
                var evs = c.events || [];
                if (evs.length) {
                    dbody.appendChild(el('div', { class: 'tro-table-wrap' }, [el('table', { class: 'table table-sm tro-table' }, [
                        el('thead', {}, [el('tr', {}, [el('th', { text: '#' }), el('th', { text: 'What' }), el('th', { text: 'When' }), el('th', { text: 'By' })])]),
                        el('tbody', {}, evs.map(function (x) {
                            return el('tr', {}, [el('td', { class: 'tro-mono', text: String(x.seq) }), el('td', { text: u.EVENT_LABELS[x.type] || x.type }), el('td', { class: 'tro-nowrap', text: u.fmtDateTime(x.at) }), el('td', { text: x.actor && typeof x.actor === 'object' ? (x.actor.name || x.actor.type || '') : (x.actor || '') })]);
                        }))
                    ])]));
                } else {
                    dbody.appendChild(el('p', { class: 'text-muted small', text: 'No events.' }));
                }
                det.appendChild(dbody);
                right.appendChild(el('div', { class: 'tro-card' }, [det]));
                recHost.appendChild(el('div', { class: 'tro-rec-grid' }, [left, right]));
            };
            var reload = function () {
                u.fetchAction('completion_get', { completion_id: D.record_id }).then(render, function (err) { clear(recHost); recHost.appendChild(el('div', { class: 'tro-card' }, [u.failState(err, reload)])); });
            };
            u.load(D.record).then(render, function (err) { clear(recHost); recHost.appendChild(el('div', { class: 'tro-card' }, [u.failState(err, reload)])); });
        }
    });
})();
