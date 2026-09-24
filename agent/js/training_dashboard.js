/*
 * Training › Overview (Phase 2 spec §5.1, M11 + S7). The page is server-rendered; this file
 * only draws the compliance trend (Chart.js with the app chart theme) and switches the
 * "Expiring soon" window (30 / 60 / 90 days) without a reload. The department × course
 * drill-down lives in training_reports.js (shared with Reports › Matrix).
 */
(function () {
    'use strict';

    function cssVar(name, fallback) {
        try {
            var v = getComputedStyle(document.body).getPropertyValue(name).trim();
            return v || fallback;
        } catch (e) { return fallback; }
    }

    function rgbaFromToken(rgbToken, alpha, fallback) {
        var t = cssVar(rgbToken, '');
        return t ? 'rgba(' + t + ', ' + alpha + ')' : fallback;
    }

    function trendChart(trend) {
        var canvas = document.getElementById('trr-trend-chart');
        if (!canvas || !trend || typeof window.Chart === 'undefined') { return; }
        if (window.itflowChartTheme && window.itflowChartTheme.apply) { window.itflowChartTheme.apply(); }
        var primary = cssVar('--if-primary', '#0d9488');
        var ink = cssVar('--if-ink', '#16232a');
        var muted = cssVar('--if-muted', '#5d6f76');
        var values = trend.values || [];
        var known = values.filter(function (v) { return typeof v === 'number'; });
        var target = typeof trend.target === 'number' ? trend.target : 95;
        var lo = Math.min.apply(null, known.concat([target]));
        var min = Math.max(0, Math.floor((lo - 10) / 10) * 10);
        var lastIdx = -1;
        var firstIdx = -1;
        values.forEach(function (v, i) {
            if (typeof v === 'number') { lastIdx = i; if (firstIdx === -1) { firstIdx = i; } }
        });
        var family = (window.Chart.defaults && window.Chart.defaults.font && window.Chart.defaults.font.family) || 'sans-serif';

        var endLabels = {
            id: 'trrEndLabels',
            afterDatasetsDraw: function (chart) {
                var meta = chart.getDatasetMeta(0);
                var ctx = chart.ctx;
                ctx.save();
                ctx.font = '600 12px ' + family;
                ctx.fillStyle = ink;
                [firstIdx, lastIdx].forEach(function (i, n) {
                    if (i < 0 || !meta.data[i] || (n === 0 && firstIdx === lastIdx)) { return; }
                    var pt = meta.data[i];
                    ctx.textAlign = n === 0 ? 'left' : 'right';
                    ctx.fillText(values[i] + '%', pt.x + (n === 0 ? 2 : 4), pt.y - 10);
                });
                // Target caption above the dashed line, at the left edge.
                var y = chart.scales.y.getPixelForValue(target);
                ctx.font = '500 11px ' + family;
                ctx.fillStyle = muted;
                ctx.textAlign = 'left';
                ctx.fillText('Target ' + target + '%', chart.chartArea.left + 6, y - 6);
                ctx.restore();
            }
        };

        // eslint-disable-next-line no-new
        new window.Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: trend.labels || [],
                datasets: [
                    {
                        label: 'Compliance',
                        data: values,
                        borderColor: primary,
                        backgroundColor: rgbaFromToken('--if-primary-rgb', '.10', 'rgba(13,148,136,.10)'),
                        fill: 'start',
                        tension: 0.25,
                        spanGaps: true,
                        pointRadius: values.map(function (v, i) { return i === lastIdx ? 5 : 3; }),
                        pointBackgroundColor: primary,
                        pointBorderColor: cssVar('--if-surface', '#ffffff'),
                        pointBorderWidth: 1.5,
                        borderWidth: 2
                    },
                    {
                        label: 'Target',
                        data: (trend.labels || []).map(function () { return target; }),
                        borderColor: muted,
                        borderDash: [5, 5],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        pointHoverRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 16, right: 8 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        filter: function (item) { return item.datasetIndex === 0 && typeof item.raw === 'number'; },
                        callbacks: { label: function (item) { return 'Compliance ' + item.raw + '%'; } }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { min: min, max: 100, ticks: { stepSize: 10, callback: function (v) { return v + '%'; } }, title: { display: true, text: 'Compliance (%)' } }
                }
            },
            plugins: [endLabels]
        });
    }

    function expiringSwitch() {
        var card = document.getElementById('trr-expiring');
        if (!card) { return; }
        var buttons = card.querySelectorAll('[data-trr-window]');
        var rows = card.querySelectorAll('#trr-exp-rows [data-days]');
        var none = document.getElementById('trr-exp-none');
        var showing = document.getElementById('trr-exp-showing');
        var all = document.getElementById('trr-exp-all');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var w = Number(btn.getAttribute('data-trr-window'));
                var total = Number(card.getAttribute('data-total' + w) || 0);
                var shown = 0;
                buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
                rows.forEach(function (li) {
                    var on = Number(li.getAttribute('data-days')) <= w;
                    li.hidden = !on;
                    if (on) { shown++; }
                });
                if (none) { none.hidden = shown > 0; }
                if (showing) { showing.textContent = 'Showing ' + shown + ' of ' + total; }
                if (all) {
                    all.textContent = 'View all ' + total;
                    try {
                        var url = new URL(all.getAttribute('href'), window.location.href);
                        url.searchParams.set('days', String(w));
                        all.setAttribute('href', url.pathname + url.search);
                    } catch (e) { /* keep the link */ }
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var data = window.TrainingUi && window.TrainingUi.readJson ? window.TrainingUi.readJson('tr-page-data') : {};
        if (data && data.trend && data.trend.points >= 2) { trendChart(data.trend); }
        expiringSwitch();
    });
})();
