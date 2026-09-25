/*
 * Training reports: shared behaviour for the Lane D pages (Phase 2 spec §5.1).
 *
 *   - filter bars: a <select data-trr-autosubmit> submits its GET form on change;
 *   - the "who is missing" drill-down: any [data-trr-cell] button opens the page's
 *     Bootstrap offcanvas (#trr-cell-panel) and lists report_cell_people;
 *   - Reports › Course: the score-distribution bar chart (Chart.js, app chart theme).
 *
 * Every string reaches the DOM through textContent / DOM nodes (TrainingUi.el); no inline
 * handlers (CSP). Business dates are local Y-m-d strings from the server and are formatted
 * here without touching the browser's time zone.
 */
(function () {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function ui() { return window.TrainingUi; }

    function el(tag, attrs, children) {
        if (ui() && ui().el) { return ui().el(tag, attrs, children); }
        // Minimal fallback (training_common.js not loaded): text and attributes only.
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) { return; }
            if (k === 'class') { node.className = v; } else if (k === 'text') { node.textContent = String(v); } else { node.setAttribute(k, String(v)); }
        });
        (Array.isArray(children) ? children : (children ? [children] : [])).forEach(function (c) {
            if (c !== null && c !== undefined && c !== false) { node.appendChild(c instanceof Node ? c : document.createTextNode(String(c))); }
        });
        return node;
    }

    function icon(cls) { return el('i', { class: cls, 'aria-hidden': 'true' }); }

    function isYmd(s) { return typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s); }

    /** "Sep 8" in the current year, else "Sep 8, 2025". */
    function fmtDate(ymd, long) {
        if (!isYmd(ymd)) { return ''; }
        var p = ymd.split('-');
        var out = MONTHS[Number(p[1]) - 1] + ' ' + Number(p[2]);
        if (long || p[0] !== String(new Date().getFullYear())) { out += ', ' + p[0]; }
        return out;
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/[\s-]+/).filter(Boolean);
        if (!parts.length) { return '?'; }
        return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }

    var STATUS = {
        current: ['ok', 'fas fa-check', 'Current'],
        expiring: ['warn', 'far fa-clock', 'Expiring'],
        retrain_due: ['warn', 'fas fa-redo', 'Retrain due'],
        overdue: ['err', 'fas fa-exclamation-circle', 'Overdue'],
        due_soon: ['warn', 'far fa-clock', 'Due soon'],
        due: ['info', 'far fa-calendar', 'Assigned'],
        waived: ['neutral', 'fas fa-ban', 'Waived'],
        expired: ['err', 'fas fa-times-circle', 'Expired'],
        not_started: ['neutral', 'far fa-circle', 'Not assigned yet']
    };

    /** Pair status chip: short text, an icon, and the frozen label in title. */
    function pairChip(pair) {
        var s = STATUS[pair.status] || ['neutral', 'far fa-circle', String(pair.status || '').replace(/_/g, ' ')];
        var text = s[2];
        if (pair.status === 'expired' && /^Revoked/.test(pair.label || '')) { s = ['err', 'fas fa-ban', 'Revoked']; text = 'Revoked'; }
        var date = pair.status === 'expiring' ? pair.expires_on : pair.due_on;
        if (date && ['expiring', 'due_soon', 'due', 'overdue', 'retrain_due'].indexOf(pair.status) !== -1) { text += ' · ' + fmtDate(date); }
        return el('span', { class: 'trr-chip trr-chip--' + s[0], title: pair.label || null }, [icon(s[1]), text]);
    }

    function transcriptUrl(contactId) { return '/agent/training_transcript.php?contact_id=' + encodeURIComponent(String(contactId)); }

    function errorText(err) {
        if (err && err.code === 'not_found') { return 'That department or course is not in your view.'; }
        if (err && err.code === 'network') { return 'You appear to be offline. Try again.'; }
        return (err && err.message) || 'The list could not be loaded. Try again.';
    }

    // ------------------------------------------------------------------------------------
    // Filter bars
    // ------------------------------------------------------------------------------------

    function wireFilters(root) {
        (root || document).querySelectorAll('select[data-trr-autosubmit]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var form = sel.form;
                if (!form) { return; }
                // Drop empty values so the URL stays short and bookmarkable.
                Array.prototype.forEach.call(form.elements, function (f) {
                    if (f.name && f.value === '' && f.tagName !== 'BUTTON') { f.disabled = true; }
                });
                form.submit();
            });
        });
    }

    // ------------------------------------------------------------------------------------
    // "Who is missing" drill-down (report_cell_people)
    // ------------------------------------------------------------------------------------

    var panel = null;
    var loadSeq = 0;

    function panelParts() {
        var root = document.getElementById('trr-cell-panel');
        if (!root) { return null; }
        return { root: root, title: document.getElementById('trr-cell-title'), sub: document.getElementById('trr-cell-sub'), body: document.getElementById('trr-cell-body') };
    }

    function showPanel(parts) {
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            panel = panel || window.bootstrap.Offcanvas.getOrCreateInstance(parts.root);
            panel.show();
        } else {
            parts.root.classList.add('show');
            parts.root.style.visibility = 'visible';
        }
    }

    function skeleton(n) {
        var list = el('ul', { class: 'trr-people', 'aria-hidden': 'true' });
        for (var i = 0; i < n; i++) {
            list.appendChild(el('li', null, [el('span', { class: 'trr-avatar tr-skeleton' }), el('div', { class: 'trr-people__main' }, [
                el('div', { class: 'tr-skeleton', style: { height: '12px', width: (50 + (i * 13) % 40) + '%' } }),
                el('div', { class: 'tr-skeleton mt-1', style: { height: '10px', width: '35%' } })
            ])]));
        }
        return list;
    }

    function renderCell(parts, d) {
        parts.body.textContent = '';
        var courseLabel = d.course.code ? d.course.code + ' · ' + d.course.name : d.course.name;
        parts.title.textContent = d.department.name;
        parts.sub.textContent = courseLabel;
        var missing = d.people.filter(function (r) { return r.pair.status !== 'waived'; }).length;
        var waived = d.people.length - missing;
        parts.body.appendChild(el('div', { class: 'trr-cellsum' }, [
            el('span', { class: 'trr-cellsum__pct', text: d.pct === null ? '—' : d.pct + '%' }),
            el('span', { class: 'trr-muted', text: d.current + ' of ' + d.required + ' current' }),
            missing > 0 ? el('span', { class: 'trr-chip trr-chip--err' }, [icon('fas fa-user-times'), plural(missing, 'person', 'people') + ' missing']) : null,
            waived > 0 ? el('span', { class: 'trr-chip trr-chip--neutral' }, [icon('fas fa-ban'), waived + ' waived']) : null
        ]));
        if (!d.people.length) {
            parts.body.appendChild(el('div', { class: 'tr-empty trr-empty-line' }, [icon('fas fa-check-circle me-2'), 'Everyone required here is current.']));
            return;
        }
        var list = el('ul', { class: 'trr-people' });
        d.people.forEach(function (row) {
            var p = row.person;
            var pair = row.pair;
            var meta = [];
            if (p.title) { meta.push(p.title); }
            if (pair.status === 'overdue' && pair.days_overdue > 0) { meta.push(plural(pair.days_overdue, 'day') + ' late'); }
            if (pair.label && STATUS[pair.status] && pair.label !== STATUS[pair.status][2]) { meta.push(pair.label); }
            list.appendChild(el('li', null, [
                el('span', { class: 'trr-avatar', 'aria-hidden': 'true', text: p.initials || initials(p.name) }),
                el('div', { class: 'trr-people__main' }, [
                    el('a', { href: transcriptUrl(p.contact_id), text: p.name }),
                    meta.length ? el('div', { class: 'trr-people__sub', text: meta.join(' · ') }) : null
                ]),
                pairChip(pair)
            ]));
        });
        parts.body.appendChild(list);
    }

    function openCell(btn) {
        var parts = panelParts();
        if (!parts) { return; }
        var clientId = btn.getAttribute('data-client');
        var courseId = btn.getAttribute('data-course');
        var seq = ++loadSeq;
        parts.title.textContent = btn.getAttribute('data-dept-name') || 'Who is missing';
        parts.sub.textContent = btn.getAttribute('data-course-name') || '';
        parts.body.textContent = '';
        parts.body.setAttribute('aria-busy', 'true');
        parts.body.appendChild(skeleton(4));
        showPanel(parts);
        if (!window.TrainingApi) {
            parts.body.textContent = '';
            parts.body.appendChild(el('div', { class: 'tr-banner alert alert-danger', role: 'alert', text: 'The page did not finish loading. Reload and try again.' }));
            return;
        }
        var params = { client_id: clientId, course_id: courseId };
        if (btn.hasAttribute('data-job')) { params.job_id = btn.getAttribute('data-job'); }
        if (btn.hasAttribute('data-location')) { params.location_id = btn.getAttribute('data-location'); }
        window.TrainingApi.get('report_cell_people', params).then(function (d) {
            if (seq !== loadSeq) { return; }
            parts.body.removeAttribute('aria-busy');
            renderCell(parts, d);
        }, function (err) {
            if (seq !== loadSeq) { return; }
            parts.body.removeAttribute('aria-busy');
            parts.body.textContent = '';
            var retry = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary ms-auto', text: 'Try again' });
            retry.addEventListener('click', function () { openCell(btn); });
            parts.body.appendChild(el('div', { class: 'tr-banner alert alert-danger d-flex flex-wrap align-items-center gap-2', role: 'alert' }, [el('span', { text: errorText(err) }), retry]));
            if (ui() && ui().toast) { ui().toast(errorText(err), { type: 'error' }); }
        });
    }

    function wireCells() {
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-trr-cell]') : null;
            if (!btn) { return; }
            e.preventDefault();
            openCell(btn);
        });
    }

    // ------------------------------------------------------------------------------------
    // Reports › Course: score distribution
    // ------------------------------------------------------------------------------------

    function cssVar(name, fallback) {
        try {
            var v = getComputedStyle(document.body).getPropertyValue(name).trim();
            return v || fallback;
        } catch (e) { return fallback; }
    }

    function scoreChart(data) {
        var canvas = document.getElementById('trr-score-chart');
        if (!canvas || typeof window.Chart === 'undefined' || !data || !Array.isArray(data.buckets)) { return; }
        if (window.itflowChartTheme && window.itflowChartTheme.apply) { window.itflowChartTheme.apply(); }
        var sem = window.itflowChartTheme && window.itflowChartTheme.semantic ? window.itflowChartTheme.semantic() : {};
        var primary = sem.primary || cssVar('--if-primary', '#0d9488');
        var danger = cssVar('--trr-danger', sem.danger || '#dc2626');
        var pass = typeof data.pass_mark === 'number' ? data.pass_mark : null;
        // Show from 50–59 up (the mockup), or lower when there are scores (or a pass mark) below 50.
        var first = 9;
        data.buckets.forEach(function (n, idx) { if (n > 0 && idx < first) { first = idx; } });
        var start = Math.min(first, 5, pass !== null ? Math.max(0, Math.floor(pass / 10) - 1) : 5);
        var labels = [];
        var colors = [];
        var values = [];
        for (var i = start; i < 10; i++) {
            labels.push(i === 9 ? '90–100' : (i * 10) + '–' + (i * 10 + 9));
            colors.push(pass !== null && (i + 1) * 10 <= pass ? danger : primary);
            values.push(data.buckets[i] || 0);
        }
        var passIndex = pass !== null ? pass / 10 - 0.5 - start : null;
        var passLine = {
            id: 'trrPassLine',
            afterDatasetsDraw: function (chart) {
                if (passIndex === null) { return; }
                var x = chart.scales.x;
                var y = chart.scales.y;
                var px = x.getPixelForValue(passIndex);
                var ctx = chart.ctx;
                ctx.save();
                ctx.strokeStyle = cssVar('--if-ink', '#16232a');
                ctx.setLineDash([4, 4]);
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(px, y.top);
                ctx.lineTo(px, y.bottom);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.fillStyle = cssVar('--if-ink', '#16232a');
                ctx.font = '600 11px ' + (window.Chart.defaults.font.family || 'sans-serif');
                ctx.textAlign = 'right';
                ctx.fillText('Pass mark ' + pass + '%', px - 4, y.top + 10);
                ctx.restore();
            }
        };
        var valueLabels = {
            id: 'trrValueLabels',
            afterDatasetsDraw: function (chart) {
                var meta = chart.getDatasetMeta(0);
                var ctx = chart.ctx;
                ctx.save();
                ctx.fillStyle = cssVar('--if-ink', '#16232a');
                ctx.font = '600 11px ' + (window.Chart.defaults.font.family || 'sans-serif');
                ctx.textAlign = 'center';
                meta.data.forEach(function (bar, idx) {
                    var v = chart.data.datasets[0].data[idx];
                    if (v > 0) { ctx.fillText(String(v), bar.x, bar.y - 5); }
                });
                ctx.restore();
            }
        };
        // eslint-disable-next-line no-new
        new window.Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: data.basis === 'attempts' ? 'Attempts' : 'Records', data: values, backgroundColor: colors, borderRadius: 3, maxBarThickness: 64 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 18 } },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { title: function (items) { return 'Score ' + items[0].label + '%'; } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false }, title: { display: true, text: 'Score (%)' } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: data.basis === 'attempts' ? 'Attempts' : 'Records' } }
                }
            },
            plugins: [passLine, valueLabels]
        });
    }

    window.TrrReports = { el: el, icon: icon, fmtDate: fmtDate, initials: initials, plural: plural, pairChip: pairChip, cssVar: cssVar, openCell: openCell };

    document.addEventListener('DOMContentLoaded', function () {
        wireFilters(document);
        wireCells();
        var data = ui() && ui().readJson ? ui().readJson('tr-page-data') : {};
        if (data && data.scores) { scoreChart(data.scores); }
    });
})();
