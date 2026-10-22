/*
 * My dashboard (agent/my_dashboard.php): renders any widget whose chart type was switched to something other than
 * its plain list/table/SVG default (WidgetRenderer::render()'s $chart param) - those widgets emit a bare
 * <canvas class="dash-chartjs" data-chart="{json}"> instead of HTML, and this turns each one into a real Chart.js
 * chart. Runs on every load (not just customise mode), since the chosen chart type is part of the saved layout.
 * Chart.js itself and js/chart_theme.js (Chart.defaults colours/dark-mode) are already loaded on every agent page
 * (includes/footer.php) - this file only has to exist and come after both, which "defer" guarantees.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') { return; }
        var palette = (window.itflowChartTheme && window.itflowChartTheme.palette())
            || ['#0d9488', '#3b82f6', '#f59e0b', '#8b5cf6', '#059669', '#dc2626', '#0891b2', '#64748b'];

        document.querySelectorAll('canvas.dash-chartjs').forEach(function (canvas) {
            var cfg;
            try { cfg = JSON.parse(canvas.getAttribute('data-chart') || '{}'); } catch (e) { return; }
            if (!cfg.type || !Array.isArray(cfg.datasets)) { return; }

            var datasets = cfg.datasets.map(function (ds, i) {
                if (cfg.type === 'pie') {
                    return Object.assign({}, ds, {
                        backgroundColor: (cfg.labels || []).map(function (_, j) { return palette[j % palette.length]; })
                    });
                }
                var color = palette[i % palette.length];
                return Object.assign({}, ds, { backgroundColor: color, borderColor: color });
            });

            new Chart(canvas, {
                type: cfg.type,
                data: { labels: cfg.labels || [], datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: cfg.type === 'pie' || datasets.length > 1 } }
                }
            });
        });
    });
}());
