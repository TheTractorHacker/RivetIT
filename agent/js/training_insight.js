/*
 * Training › Item analysis (Phase 5 spec §5.5, S7). Loads insight_items for the filters in the
 * toolbar and builds the KPI strip and the question table. Every string reaches the DOM through
 * TrainingUi.el / textContent: no HTML strings, no inline handlers. Filter changes re-fetch in place
 * and keep the URL (history.replaceState) and the Export CSV link in step; changing the course
 * loads that course's page.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        var root = document.getElementById('tri-root');
        if (!ui || !api || !root) { return; }
        var el = ui.el;
        var cfg = ui.readJson('tr-page-data');
        var kpis = document.getElementById('tri-kpis');
        var body = document.getElementById('tri-body');
        var sub = document.getElementById('tri-q-sub');
        var csv = document.getElementById('tri-csv');
        var sortSel = document.getElementById('tri-sort');
        var sortWrap = sortSel ? sortSel.closest('.tri-sort') : null;
        var f = {
            course: document.getElementById('tri-f-course'),
            revision: document.getElementById('tri-f-revision'),
            lang: document.getElementById('tri-f-lang'),
            kind: document.getElementById('tri-f-kind'),
            period: document.getElementById('tri-f-period')
        };
        var FLAG = {
            hard: { label: 'Hard', cls: 'warn', icon: 'fas fa-arrow-down', title: 'Fewer than 40 % answered it correctly.' },
            easy: { label: 'Very easy', cls: 'info', icon: 'fas fa-arrow-up', title: 'More than 95 % answered it correctly.' },
            check_key: { label: 'Check the key', cls: 'err', icon: 'fas fa-key', title: 'Stronger learners miss it more than weaker ones, or a wrong answer is picked more often than the right one.' },
            few: { label: 'Few answers', cls: 'neutral', icon: 'fas fa-hourglass-half', title: 'Answered fewer than ' + (cfg.few_n || 10) + ' times: discrimination and flags are hidden.' }
        };
        var TYPE = { single: 'Single choice', multi: 'Multiple choice', truefalse: 'True / false' };
        var report = null;
        var seq = 0;
        var open = {};

        // ---- filters -------------------------------------------------------------------------
        function state() {
            return {
                course_id: cfg.course_id,
                revision_id: f.revision && f.revision.value !== '' ? f.revision.value : null,
                lang: f.lang && f.lang.value !== '' ? f.lang.value : null,
                kind: f.kind ? f.kind.value : 'exam',
                period: f.period ? f.period.value : 'all'
            };
        }
        function sinceFor(period) {
            if (!period || period === 'all' || !/^\d{4}-\d{2}-\d{2}$/.test(cfg.today || '')) { return null; }
            var p = cfg.today.split('-');
            var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
            d.setUTCDate(d.getUTCDate() - (parseInt(period, 10) - 1));
            return d.toISOString().slice(0, 10);
        }
        function params(s) {
            var out = { course_id: s.course_id, kind: s.kind };
            if (s.revision_id) { out.revision_id = s.revision_id; }
            if (s.lang) { out.lang = s.lang; }
            var since = sinceFor(s.period);
            if (since) { out.since = since; }
            return out;
        }
        function qs(obj) {
            return Object.keys(obj).filter(function (k) { return obj[k] !== null && obj[k] !== undefined && obj[k] !== ''; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k])); }).join('&');
        }
        function syncUrl(s) {
            var q = { course_id: s.course_id, revision_id: s.revision_id, lang: s.lang, kind: s.kind === 'exam' ? null : s.kind, period: s.period === 'all' ? null : s.period };
            try { window.history.replaceState(null, '', (cfg.endpoints && cfg.endpoints.self || window.location.pathname) + '?' + qs(q)); } catch (e) { /* ignore */ }
            if (csv && cfg.endpoints && cfg.endpoints.csv) { csv.setAttribute('href', cfg.endpoints.csv + '&' + qs(params(s))); }
        }

        // ---- formatting ------------------------------------------------------------------------
        function pct(v) { return v === null || v === undefined ? '—' : (Math.round(Number(v) * 10) / 10).toFixed(Number(v) % 1 === 0 ? 0 : 1) + '%'; }
        function dnum(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(2); }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function duration(s) {
            if (s === null || s === undefined) { return null; }
            var m = Math.floor(s / 60);
            var sec = s % 60;
            if (m >= 60) { return Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; }
            return m > 0 ? m + ' min ' + (sec < 10 ? '0' : '') + sec + ' s' : sec + ' s';
        }
        function chip(cls, text, icon, title) {
            return el('span', { class: 'trr-chip trr-chip--' + cls, title: title || null }, [icon ? el('i', { class: icon, 'aria-hidden': 'true' }) : null, text]);
        }
        function dChip(d) {
            if (d === null || d === undefined) { return null; }
            if (d < 0) { return chip('err', 'Check key', 'fas fa-key'); }
            if (d < 0.1) { return chip('warn', 'Weak'); }
            if (d < 0.3) { return chip('info', 'Fair'); }
            return chip('ok', 'Good', 'fas fa-check');
        }
        function versions(nums) {
            if (!nums || !nums.length) { return '—'; }
            return nums.length === 1 ? 'Rev ' + nums[0] : 'Rev ' + nums[0] + ' → ' + nums[nums.length - 1];
        }
        function bar(value, cls) {
            var fill = el('div', { class: 'tr-progress__bar' });
            fill.style.width = Math.max(0, Math.min(100, Number(value) || 0)) + '%';
            return el('div', { class: 'tr-progress ' + (cls || ''), 'aria-hidden': 'true' }, [fill]);
        }

        // ---- KPI strip -------------------------------------------------------------------------
        function kpi(label, icon, iconCls, value, subText) {
            return el('div', { class: 'trr-kpi' }, [
                el('div', { class: 'trr-kpi__label' }, [label, el('span', { class: 'trr-kpi__icon trr-kpi__icon--' + iconCls }, [el('i', { class: icon, 'aria-hidden': 'true' })])]),
                el('div', { class: 'trr-kpi__value', text: value }),
                el('div', { class: 'trr-kpi__sub', text: subText })
            ]);
        }
        function renderKpis(sm) {
            kpis.textContent = '';
            kpis.setAttribute('aria-busy', 'false');
            var hasAny = sm && sm.attempts > 0;
            kpis.appendChild(kpi('Attempts', 'far fa-file-alt', 'info', String(sm ? sm.attempts : 0), 'Submitted quiz attempts'));
            kpis.appendChild(kpi('People', 'fas fa-users', 'neutral', String(sm ? sm.people : 0), hasAny ? 'Different people in your departments' : 'Nobody yet'));
            kpis.appendChild(kpi('First-try pass', 'far fa-flag', 'ok', hasAny ? pct(sm.first_try_pass_pct) : '—', 'First tries that passed'));
            kpis.appendChild(kpi('Mean score', 'fas fa-bullseye', 'info', hasAny ? pct(sm.mean_score_pct) : '—', 'Average attempt score'));
            var med = hasAny ? duration(sm.median_duration_s) : null;
            kpis.appendChild(kpi('Median time', 'far fa-clock', 'neutral', med || '—', 'Time per attempt'));
        }

        // ---- table ---------------------------------------------------------------------------------
        function empty(icon, title, text) {
            body.textContent = '';
            body.setAttribute('aria-busy', 'false');
            body.appendChild(el('div', { class: 'card-body pt-0' }, [
                el('div', { class: 'tr-empty tri-empty' }, [
                    el('div', { class: 'tr-empty__icon' }, [el('i', { class: icon, 'aria-hidden': 'true' })]),
                    el('p', { class: 'tr-empty__title', text: title }),
                    text ? el('p', { class: 'tr-empty__text', text: text }) : null
                ])
            ]));
            if (sortWrap) { sortWrap.hidden = true; }
        }

        function optionList(q) {
            var list = el('ul', { class: 'tri-options', role: 'list' });
            q.options.forEach(function (o) {
                list.appendChild(el('li', { class: 'tri-option' + (o.correct ? ' tri-option--correct' : '') }, [
                    el('div', { class: 'tri-option__label' }, [
                        o.correct ? el('span', { class: 'tri-option__mark' }, [el('i', { class: 'fas fa-check', 'aria-hidden': 'true' }), el('span', { class: 'tri-option__word', text: 'correct' })]) : null,
                        el('span', { class: 'tri-option__text', text: o.label === '' ? '(no text)' : o.label }),
                        o.retired ? el('span', { class: 'trr-chip trr-chip--outline', title: 'This answer is not in the newest version of the question.', text: 'Earlier version' }) : null
                    ]),
                    el('div', { class: 'tri-option__bar' }, [bar(o.chosen_pct, o.correct ? 'tr-progress--ok' : 'tri-progress--wrong')]),
                    el('div', { class: 'tri-option__num trr-num' }, [el('strong', { text: pct(o.chosen_pct) }), el('span', { class: 'trr-muted', text: ' · ' + plural(o.chosen_n, 'pick', 'picks') })])
                ]));
            });
            var notes = [];
            if (q.type === 'multi') { notes.push('Multiple choice: one answer can pick several options, so the bars can add up to more than 100 %.'); }
            if (q.changed_across_revisions) { notes.push('The wording or answers changed between the versions these answers came from, so the numbers mix both.'); }
            return el('div', { class: 'tri-detail__inner' }, [
                el('p', { class: 'tri-detail__title', text: 'How the answers were spread (' + plural(q.n, 'answer', 'answers') + ')' }),
                list,
                notes.length ? el('p', { class: 'trr-muted small mb-0', text: notes.join(' ') }) : null
            ]);
        }

        function questionCell(q, detailId) {
            var meta = [];
            meta.push(TYPE[q.type] || q.type);
            if (q.quiz && q.quiz.title) { meta.push((q.quiz.kind === 'check' ? 'Quick check · ' : '') + q.quiz.title); }
            if (q.topic) { meta.push(q.topic); }
            var chips = [];
            if (q.critical) { chips.push(chip('err', 'Critical', 'fas fa-exclamation-triangle', 'A critical question must be answered correctly to pass.')); }
            q.flags.forEach(function (fl) { var d = FLAG[fl]; if (d) { chips.push(chip(d.cls, d.label, d.icon, d.title)); } });
            var toggle = el('button', {
                type: 'button', class: 'tri-q__toggle', 'aria-expanded': open[q.uid] ? 'true' : 'false', 'aria-controls': detailId,
                on: { click: function () { toggleRow(q.uid, toggle, detailId); } }
            }, [
                el('i', { class: 'fas fa-chevron-right tri-q__chev', 'aria-hidden': 'true' }),
                el('span', { class: 'tri-q__text', text: q.text === '' ? '(no question text)' : q.text })
            ]);
            return el('td', { class: 'tri-col-q', 'data-label': 'Question' }, [
                toggle,
                el('div', { class: 'tri-q__meta', text: meta.join(' · ') }),
                chips.length ? el('div', { class: 'tri-q__chips' }, chips) : null
            ]);
        }

        function toggleRow(uid, btn, detailId) {
            open[uid] = !open[uid];
            btn.setAttribute('aria-expanded', open[uid] ? 'true' : 'false');
            var row = document.getElementById(detailId);
            if (row) { row.hidden = !open[uid]; }
            var tr = btn.closest('tr');
            if (tr) { tr.classList.toggle('is-open', !!open[uid]); }
        }

        function sorted(qs) {
            var by = sortSel ? sortSel.value : 'number';
            var list = qs.slice();
            var nz = function (v, dflt) { return v === null || v === undefined ? dflt : v; };
            if (by === 'hardest') { list.sort(function (a, b) { return nz(a.correct_pct, 101) - nz(b.correct_pct, 101) || a.number - b.number; }); }
            if (by === 'discrimination') { list.sort(function (a, b) { return nz(a.discrimination, 9) - nz(b.discrimination, 9) || a.number - b.number; }); }
            if (by === 'answered') { list.sort(function (a, b) { return b.n - a.n || a.number - b.number; }); }
            if (by === 'number') { list.sort(function (a, b) { return a.number - b.number; }); }
            return list;
        }

        function renderTable() {
            var qs = report.questions;
            body.textContent = '';
            body.setAttribute('aria-busy', 'false');
            if (sortWrap) { sortWrap.hidden = qs.length < 2; }
            var tbody = el('tbody');
            sorted(qs).forEach(function (q) {
                var few = q.flags.indexOf('few') !== -1;
                var detailId = 'tri-detail-' + q.uid;
                var dReason = few ? 'Needs ' + (cfg.few_n || 10) + ' answers' : 'Too few answers in the top or bottom group';
                var row = el('tr', { class: 'tri-row' + (open[q.uid] ? ' is-open' : '') + (few ? ' tri-row--few' : '') }, [
                    el('td', { class: 'tri-col-num trr-num', 'data-label': '#', text: String(q.number) }),
                    questionCell(q, detailId),
                    el('td', { class: 'tri-col-n trr-num', 'data-label': 'Answered', text: String(q.n) }),
                    el('td', { class: 'tri-col-pct', 'data-label': '% correct' }, [
                        el('div', { class: 'tri-pct' }, [el('strong', { class: 'trr-num', text: pct(q.correct_pct) }), bar(q.correct_pct, q.correct_pct !== null && q.correct_pct < 40 ? 'tri-progress--low' : '')])
                    ]),
                    el('td', { class: 'tri-col-d', 'data-label': 'Discrimination' }, q.discrimination === null
                        ? [el('span', { class: 'trr-muted', title: dReason, text: '—' }), el('span', { class: 'visually-hidden', text: ' ' + dReason })]
                        : [el('span', { class: 'trr-num tri-d', text: dnum(q.discrimination) }), ' ', dChip(q.discrimination)]),
                    el('td', { class: 'tri-col-wrong', 'data-label': 'Most-chosen wrong answer' }, q.wrong_top
                        ? [el('span', { class: 'tri-wrong__label', text: '“' + q.wrong_top.label + '”' }), ' ', el('span', { class: 'trr-muted small trr-num', text: pct(q.wrong_top.pct) })]
                        : [el('span', { class: 'trr-muted', text: '—' })]),
                    el('td', { class: 'tri-col-rev', 'data-label': 'Versions' }, [
                        chip('neutral', versions(q.revision_numbers)),
                        q.changed_across_revisions ? chip('warn', 'Changed', 'fas fa-pen', 'The wording or answers changed between these versions.') : null
                    ])
                ]);
                var detail = el('tr', { class: 'tri-detail', id: detailId, hidden: !open[q.uid] }, [
                    el('td', { colspan: '7' }, [optionList(q)])
                ]);
                tbody.appendChild(row);
                tbody.appendChild(detail);
            });
            var table = el('table', { class: 'table table-vcenter card-table trr-table tri-table' }, [
                el('caption', { class: 'visually-hidden', text: 'Quiz questions of ' + (cfg.course_name || 'this course') + '. Select a question to see how its answers were spread.' }),
                el('thead', {}, [el('tr', {}, [
                    el('th', { scope: 'col', class: 'tri-col-num', text: '#' }),
                    el('th', { scope: 'col', text: 'Question' }),
                    el('th', { scope: 'col', class: 'tri-col-n', text: 'Answered' }),
                    el('th', { scope: 'col', text: '% correct' }),
                    el('th', { scope: 'col', text: 'Discrimination' }),
                    el('th', { scope: 'col', text: 'Most-chosen wrong answer' }),
                    el('th', { scope: 'col', text: 'Versions' })
                ])]),
                tbody
            ]);
            body.appendChild(el('div', { class: 'trr-table-wrap tri-table-wrap' }, [table]));
        }

        function render() {
            ui.banner(null);
            if (!report.available) {
                renderKpis(null);
                empty('fas fa-tablet-alt', 'No quiz results yet', 'Results appear after employees take this course on a kiosk.');
                sub.textContent = 'How often each question was answered correctly.';
                return;
            }
            if (report.scope_none) {
                ui.banner('Ask an administrator to grant department access to see results.', { type: 'info' });
            }
            renderKpis(report.summary);
            if (!report.questions.length) {
                empty('far fa-question-circle', 'No quiz attempts match these filters.', report.scope_none ? 'Your account has no departments yet.' : 'Try all versions, all languages or a longer period.');
                sub.textContent = 'How often each question was answered correctly.';
                return;
            }
            var few = report.questions.filter(function (q) { return q.flags.indexOf('few') !== -1; }).length;
            sub.textContent = plural(report.questions.length, 'question', 'questions') + ' from ' + plural(report.summary.attempts, 'attempt', 'attempts')
                + ' by ' + plural(report.summary.people, 'person', 'people') + '. Select a question to see its answers.'
                + (few ? ' ' + plural(few, 'question has', 'questions have') + ' fewer than ' + (cfg.few_n || 10) + ' answers.' : '');
            renderTable();
        }

        function skeleton() {
            kpis.setAttribute('aria-busy', 'true');
            body.setAttribute('aria-busy', 'true');
            Array.prototype.forEach.call(kpis.children, function (k) { k.classList.add('tri-loading'); });
            body.classList.add('tri-loading');
        }

        function load() {
            var s = state();
            syncUrl(s);
            var my = ++seq;
            skeleton();
            api.get(cfg.endpoints.items, params(s)).then(function (data) {
                if (my !== seq) { return; }
                body.classList.remove('tri-loading');
                report = data;
                render();
            }, function (err) {
                if (my !== seq) { return; }
                body.classList.remove('tri-loading');
                Array.prototype.forEach.call(kpis.children, function (k) { k.classList.remove('tri-loading'); });
                if (err && (err.code === 'forbidden' || err.code === 'module_disabled')) { return; }   // TrainingApi showed its banner
                var msg = err && err.code === 'validation' ? 'Those filters are not valid for this course. They were reset.' : ((err && err.message) || 'Item analysis could not be loaded.');
                ui.banner(msg, { type: 'danger', actions: [{ label: 'Try again', onClick: function () { load(); } }] });
                if (err && err.code === 'validation' && f.revision && f.revision.value !== '') { f.revision.value = ''; }
                if (!report) { empty('fas fa-exclamation-circle', 'Item analysis could not be loaded.', 'Try again in a moment.'); }
            });
        }

        // ---- wiring -------------------------------------------------------------------------------
        var form = document.getElementById('tri-filters');
        if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); load(); }); }
        ['revision', 'lang', 'kind', 'period'].forEach(function (k) {
            if (f[k]) { f[k].addEventListener('change', function () { open = {}; load(); }); }
        });
        if (f.course) {
            f.course.addEventListener('change', function () {
                var s = state();
                var q = { course_id: f.course.value, lang: s.lang, kind: s.kind === 'exam' ? null : s.kind, period: s.period === 'all' ? null : s.period };
                window.location.assign((cfg.endpoints && cfg.endpoints.self || window.location.pathname) + '?' + qs(q));
            });
        }
        if (sortSel) { sortSel.addEventListener('change', function () { if (report && report.questions.length) { renderTable(); } }); }
        load();
    });
})();
