/*
 * Device Performance charts (the "Metrics" subsystem - never "telemetry";
 * config_telemetry already means anonymous phone-home usage reporting).
 *
 * Drives the pane rendered by agent/includes/asset/metrics_tab.php.
 *
 * Design notes worth knowing before editing:
 *
 * ONE REQUEST PER PAGE. Every chart on the pane is filled from a single batch
 * response. The server aligns all metrics to one bucket grid, so the charts share
 * an x-axis for free and there is no per-chart waterfall.
 *
 * NO PAGE RELOAD. Every other chart in this app changes range with a full form
 * submit. This one refetches and redraws in place, which means chart instances have
 * to be torn down explicitly - see destroyCharts(). A Chart.js instance left behind
 * on a canvas that is about to be redrawn leaks a 2D context per interaction and
 * eventually turns the tab into a slideshow.
 *
 * GAPS ARE GAPS. The server returns a dense grid with `v: null` for buckets it has
 * no sample for. Combined with spanGaps:false those nulls draw as breaks in the
 * line, which is the whole point: a device that was asleep from 02:00 to 06:00 must
 * not be drawn as a straight interpolation across four hours of nothing.
 *
 * NO HARD-CODED COLOURS. Series colours come from window.itflowChartTheme.palette()
 * and .semantic() (js/chart_theme.js), which read the app's --if-* design tokens, so
 * charts follow the active theme and any per-company accent.
 *
 * NO TIME SCALE. Chart.js is loaded without a date adapter, so a `time` scale would
 * silently render nothing. Timestamps are pre-formatted here into category labels.
 *
 * TIME. Every timestamp in the payload is UTC with an explicit `Z` -
 * device_metric_samples.sampled_at is stored in UTC, a deliberate divergence from
 * the rest of ITFlow, which stores local time. Date parsing therefore happens on the
 * `Z` string and all display is in the viewer's own zone.
 *
 * CONFIG comes from data-* attributes on [data-asset-metrics], because this file is
 * external and cannot be interpolated by PHP.
 */
(function () {
    'use strict';

    var DASH = '—';

    /* chartId -> Chart instance, per pane. Keyed on the pane element via a WeakMap so
     * two panes on one document cannot stomp each other's registries. */
    var registries = new WeakMap();

    // ------------------------------------------------------------------ formatting

    function fmtBytes(v, perSecond) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        var neg = v < 0;
        var n = Math.abs(v);
        var i = 0;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        var digits = n < 10 && i > 0 ? 1 : 0;
        return (neg ? '-' : '') + n.toFixed(digits) + ' ' + units[i] + (perSecond ? '/s' : '');
    }

    function fmtDuration(seconds) {
        var s = Math.max(0, Math.floor(seconds));
        var d = Math.floor(s / 86400);
        var h = Math.floor((s % 86400) / 3600);
        var m = Math.floor((s % 3600) / 60);
        if (d > 0) { return d + 'd ' + h + 'h'; }
        if (h > 0) { return h + 'h ' + m + 'm'; }
        return m + 'm';
    }

    function fmtNumber(v, precision) {
        var p = typeof precision === 'number' && precision >= 0 ? precision : 0;
        if (Math.abs(v) >= 10000) {
            return Math.round(v).toLocaleString();
        }
        return v.toFixed(p);
    }

    /* One formatter for tiles, chart readouts, tooltips and axis ticks, so the same
     * number never appears in two different shapes on the same screen. */
    function fmtValue(value, unit, precision) {
        if (value === null || value === undefined || !isFinite(value)) {
            return DASH;
        }
        switch (unit) {
            case 'percent':  return fmtNumber(value, precision) + '%';
            case 'bytes':    return fmtBytes(value, false);
            case 'bytes/s':  return fmtBytes(value, true);
            case 'seconds':  return fmtDuration(value);
            case 'ms':       return fmtNumber(value, precision) + ' ms';
            case 'celsius':  return fmtNumber(value, precision) + '°C';
            case 'iops':     return fmtNumber(value, precision) + ' IOPS';
            case 'bool':     return value >= 0.5 ? 'Yes' : 'No';
            default:         return fmtNumber(value, precision);
        }
    }

    /* Parses the server's ISO-8601-with-Z instants. Safari has historically been
     * fussy about anything looser, and the payload is always this exact shape. */
    function parseUtc(iso) {
        var t = Date.parse(iso);
        return isNaN(t) ? null : new Date(t);
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /* Axis labels are deliberately terse and the tooltip title carries the full local
     * timestamp, so a 90-day chart does not turn its x-axis into a wall of dates. */
    function shortLabel(date, bucketSeconds) {
        if (!date) { return ''; }
        if (bucketSeconds >= 86400) {
            return MONTHS[date.getMonth()] + ' ' + date.getDate();
        }
        if (bucketSeconds >= 3600) {
            return MONTHS[date.getMonth()] + ' ' + date.getDate() + ' ' + pad2(date.getHours()) + ':00';
        }
        return pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    }

    function fullLabel(date) {
        if (!date) { return ''; }
        return MONTHS[date.getMonth()] + ' ' + date.getDate() + ', ' +
            pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    }

    function relativeFromNow(date) {
        if (!date) { return ''; }
        var secs = Math.round((Date.now() - date.getTime()) / 1000);
        if (secs < 90) { return 'just now'; }
        if (secs < 5400) { return Math.round(secs / 60) + ' min ago'; }
        if (secs < 172800) { return Math.round(secs / 3600) + ' h ago'; }
        return Math.round(secs / 86400) + ' d ago';
    }

    // --------------------------------------------------------------------- theming

    function palette() {
        if (window.itflowChartTheme && typeof window.itflowChartTheme.palette === 'function') {
            return window.itflowChartTheme.palette();
        }
        // chart_theme.js is loaded from includes/footer.php on every authenticated
        // page; this branch only runs if that ordering is ever broken. Falling back to
        // Chart.js' own default colour keeps the chart readable without this file
        // introducing a hex of its own.
        return [(window.Chart && Chart.defaults && Chart.defaults.borderColor) || '#888888'];
    }

    function semantic() {
        if (window.itflowChartTheme && typeof window.itflowChartTheme.semantic === 'function') {
            return window.itflowChartTheme.semantic();
        }
        return {};
    }

    function seriesColor(index) {
        var p = palette();
        return p[index % p.length];
    }

    /* Chart.js needs a fill colour with alpha. The palette hands back whatever the CSS
     * token holds - #rrggbb, rgb(), or an hsl() - so alpha is applied by wrapping
     * rather than by parsing, except in the one case that is trivially parseable. */
    function withAlpha(color, alpha) {
        if (typeof color !== 'string') { return color; }
        var m = color.trim().match(/^#([0-9a-f]{6})$/i);
        if (m) {
            var rgbInt = parseInt(m[1], 16);
            return 'rgba(' + ((rgbInt >> 16) & 255) + ',' + ((rgbInt >> 8) & 255) + ',' + (rgbInt & 255) + ',' + alpha + ')';
        }
        m = color.trim().match(/^rgb\(([^)]+)\)$/i);
        if (m) {
            return 'rgba(' + m[1] + ',' + alpha + ')';
        }
        return color;
    }

    // ------------------------------------------------------------------ statistics

    /* Current / average / peak, computed from exactly the points that were drawn, so
     * the readout can never disagree with the line above it. Nulls (gaps) are skipped
     * rather than treated as zero - a sleeping laptop did not have 0% CPU. */
    function summarise(seriesList) {
        var sum = 0, count = 0, peak = null, current = null, currentIndex = -1;
        seriesList.forEach(function (series) {
            var points = series.points || [];
            for (var i = 0; i < points.length; i++) {
                var v = points[i].v;
                if (v === null || v === undefined || !isFinite(v)) { continue; }
                sum += v;
                count++;
                if (peak === null || v > peak) { peak = v; }
                // "Current" is the newest bucket that has any data at all; across
                // several series (four volumes, sixteen cores) the worst one is the
                // one worth showing.
                if (i > currentIndex) { currentIndex = i; current = v; }
                else if (i === currentIndex && (current === null || v > current)) { current = v; }
            }
        });
        return {
            current: current,
            avg: count > 0 ? sum / count : null,
            peak: peak,
            count: count
        };
    }

    // --------------------------------------------------------------------- fetching

    function buildUrl(base, params) {
        var qs = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + qs;
    }

    function fetchJson(url, csrf) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrf || '',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (res) {
            return res.text().then(function (text) {
                var body = null;
                try { body = JSON.parse(text); } catch (e) { body = null; }
                if (!res.ok) {
                    var msg = (body && body.error) ? body.error : ('Request failed (HTTP ' + res.status + ')');
                    throw new Error(msg);
                }
                if (!body || typeof body !== 'object') {
                    // Almost always a redirect to the login page: the session expired
                    // mid-visit and an HTML document came back instead of JSON.
                    throw new Error('Unexpected response. Your session may have expired - reload the page.');
                }
                return body;
            });
        });
    }

    // ---------------------------------------------------------------------- drawing

    function destroyCharts(pane) {
        var registry = registries.get(pane);
        if (!registry) { return; }
        Object.keys(registry).forEach(function (id) {
            try { registry[id].destroy(); } catch (e) { /* already torn down */ }
            delete registry[id];
        });
    }

    function seriesLabel(metric, series, showMetricName) {
        var instance = series.host_level
            ? ''
            : (series.instance_label || series.instance_key || ('#' + series.instance_id));
        if (showMetricName && instance) { return metric.display + ' — ' + instance; }
        if (instance) { return instance; }
        return metric.display;
    }

    function axisConfig(axis, unit, precision) {
        var scale = { beginAtZero: true, ticks: {} };
        if (axis === 'percent') {
            scale.min = 0;
            scale.max = 100;
            scale.ticks.stepSize = 25;
            scale.ticks.callback = function (v) { return v + '%'; };
        } else if (axis === 'bool') {
            scale.min = 0;
            scale.max = 1;
            scale.ticks.stepSize = 1;
            scale.ticks.callback = function (v) { return v >= 0.5 ? 'Yes' : 'No'; };
        } else {
            scale.ticks.maxTicksLimit = 5;
            scale.ticks.callback = function (v) { return fmtValue(v, unit, precision); };
        }
        return scale;
    }

    function renderChart(pane, block, payload) {
        var registry = registries.get(pane);
        var chartId = block.getAttribute('data-chart-id');
        var keys = (block.getAttribute('data-metric-keys') || '').split(',').filter(Boolean);
        var headline = block.getAttribute('data-headline') || '';
        var axis = block.getAttribute('data-axis') || 'auto';
        var unit = block.getAttribute('data-unit') || '';
        var precision = parseInt(block.getAttribute('data-precision'), 10) || 0;

        var canvas = block.querySelector('[data-role="canvas"]');
        var overlay = block.querySelector('[data-role="overlay"]');
        var legendBox = block.querySelector('[data-role="legend"]');

        if (registry[chartId]) {
            try { registry[chartId].destroy(); } catch (e) { /* already torn down */ }
            delete registry[chartId];
        }

        var byKey = {};
        (payload.metrics || []).forEach(function (m) { byKey[m.key] = m; });

        var bucketSeconds = payload.bucket_seconds || 300;
        var labels = [];
        var titles = [];
        var gridBuilt = false;

        var datasets = [];
        var statsSource = [];
        var colorIndex = 0;
        var pointedKeys = keys.filter(function (k) { return byKey[k]; });
        var showMetricName = pointedKeys.length > 1;

        pointedKeys.forEach(function (key) {
            var metric = byKey[key];
            (metric.series || []).forEach(function (series) {
                var points = series.points || [];
                if (!gridBuilt && points.length) {
                    points.forEach(function (p) {
                        var d = parseUtc(p.t);
                        labels.push(shortLabel(d, bucketSeconds));
                        titles.push(fullLabel(d));
                    });
                    gridBuilt = true;
                }
                var color = seriesColor(colorIndex++);
                datasets.push({
                    label: seriesLabel(metric, series, showMetricName),
                    // null -> a break in the line. Never 0, never interpolated.
                    data: points.map(function (p) { return p.v; }),
                    borderColor: color,
                    backgroundColor: withAlpha(color, datasets.length === 0 && pointedKeys.length === 1 ? 0.12 : 0.05),
                    borderWidth: 1.75,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    tension: 0.25,
                    fill: pointedKeys.length === 1 && (metric.series || []).length === 1,
                    spanGaps: false,
                    itfUnit: metric.unit,
                    itfPrecision: metric.precision
                });
                if (!headline || headline === key) {
                    statsSource.push(series);
                }
            });
        });

        // Readouts first: they must be right even when there is nothing to draw.
        var stats = summarise(statsSource);
        setText(block, 'current', fmtValue(stats.current, unit, precision));
        setText(block, 'avg', fmtValue(stats.avg, unit, precision));
        setText(block, 'peak', fmtValue(stats.peak, unit, precision));

        if (legendBox) {
            legendBox.innerHTML = '';
            if (datasets.length > 1) {
                datasets.forEach(function (ds) {
                    var item = document.createElement('span');
                    item.className = 'ifm-legend-item';
                    var swatch = document.createElement('i');
                    swatch.className = 'ifm-legend-swatch';
                    swatch.style.backgroundColor = ds.borderColor;
                    item.appendChild(swatch);
                    item.appendChild(document.createTextNode(ds.label));
                    legendBox.appendChild(item);
                });
            }
        }

        // State: supported, but nothing inside THIS window. Distinct from unsupported
        // (the whole block would be absent) and from offline (a banner above).
        if (!datasets.length || stats.count === 0) {
            if (overlay) {
                overlay.textContent = 'No samples in this range.';
                overlay.hidden = false;
            }
            if (canvas) { canvas.style.visibility = 'hidden'; }
            return;
        }
        if (overlay) { overlay.hidden = true; }
        if (canvas) { canvas.style.visibility = ''; }

        if (typeof Chart === 'undefined' || !canvas) { return; }

        registry[chartId] = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                normalized: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // The custom legend above is rendered in HTML so it can wrap and
                    // stay readable with sixteen CPU cores in it.
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                return items.length ? (titles[items[0].dataIndex] || '') : '';
                            },
                            label: function (item) {
                                var ds = item.dataset || {};
                                return ds.label + ': ' + fmtValue(item.parsed.y, ds.itfUnit || unit,
                                    typeof ds.itfPrecision === 'number' ? ds.itfPrecision : precision);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
                    },
                    y: axisConfig(axis, unit, precision)
                }
            }
        });
    }

    function renderCards(pane, payload) {
        var byKey = {};
        (payload.metrics || []).forEach(function (m) { byKey[m.key] = m; });

        pane.querySelectorAll('[data-metric-card]').forEach(function (card) {
            var key = card.getAttribute('data-metric-key');
            var unit = card.getAttribute('data-unit') || '';
            var precision = parseInt(card.getAttribute('data-precision'), 10) || 0;
            var metric = byKey[key];
            var stats = summarise(metric ? (metric.series || []) : []);

            var valueEl = card.querySelector('.it-stat-value');
            if (valueEl) { valueEl.textContent = fmtValue(stats.current, unit, precision); }
            setText(card, 'avg', fmtValue(stats.avg, unit, precision));
            setText(card, 'peak', fmtValue(stats.peak, unit, precision));
        });
    }

    function setText(scope, role, text) {
        var el = scope.querySelector('[data-role="' + role + '"]');
        if (el) { el.textContent = text; }
    }

    function showError(pane, message) {
        var box = pane.querySelector('[data-role="error"]');
        if (!box) { return; }
        if (!message) {
            box.hidden = true;
            box.textContent = '';
            return;
        }
        box.textContent = message;
        box.hidden = false;
    }

    // ------------------------------------------------------------------- the pane

    function resolutionNote(payload) {
        var res = payload.resolution;
        if (res === 'raw') { return 'raw samples'; }
        if (res === 'hour') { return 'hourly averages'; }
        if (res === 'day') { return 'daily averages'; }
        return '';
    }

    function load(pane) {
        var endpoint = pane.getAttribute('data-endpoint');
        var csrf = pane.getAttribute('data-csrf');
        var assetId = pane.getAttribute('data-asset-id');
        var range = pane.getAttribute('data-range');
        var keys = pane.getAttribute('data-metric-keys') || '';
        var blocks = pane.querySelectorAll('[data-metric-chart]');

        if (!keys || !blocks.length) { return; }

        pane.classList.add('ifm-loading');
        setText(pane, 'status', 'Loading' + '…');
        showError(pane, '');

        fetchJson(buildUrl(endpoint, {
            action: 'batch',
            asset_id: assetId,
            range: range,
            metrics: keys,
            csrf_token: csrf
        }), csrf).then(function (body) {
            var payload = body.data || {};
            renderCards(pane, payload);
            blocks.forEach(function (block) { renderChart(pane, block, payload); });

            var to = parseUtc(payload.to);
            var note = resolutionNote(payload);
            setText(pane, 'status', (note ? note + ' · ' : '') + 'updated ' + relativeFromNow(new Date()));
            if (to) { pane.setAttribute('data-window-end', payload.to); }
        }).catch(function (err) {
            // Leave whatever is already drawn alone. A failed refresh must not wipe a
            // chart that was correct thirty seconds ago and replace it with nothing.
            showError(pane, err && err.message ? err.message : 'Could not load device metrics.');
            setText(pane, 'status', '');
        }).then(function () {
            pane.classList.remove('ifm-loading');
        });
    }

    function bind(pane) {
        if (pane.hasAttribute('data-metrics-bound')) { return; }
        pane.setAttribute('data-metrics-bound', '1');
        registries.set(pane, {});

        pane.querySelectorAll('[data-role="range"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var range = btn.getAttribute('data-range');
                if (!range || range === pane.getAttribute('data-range')) { return; }
                pane.setAttribute('data-range', range);
                pane.querySelectorAll('[data-role="range"]').forEach(function (other) {
                    var on = other === btn;
                    other.classList.toggle('active', on);
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                // No form submit, no reload - this is the first chart page in the app
                // that redraws in place, which is why destroyCharts() exists.
                load(pane);
            });
        });

        var refresh = pane.querySelector('[data-role="refresh"]');
        if (refresh) {
            refresh.addEventListener('click', function () { load(pane); });
        }

        /* Tearing the charts down when the pane is removed (a turbo-style navigation,
         * or a modal that replaced this markup) rather than waiting for GC to notice a
         * canvas that Chart.js still holds a reference to. */
        window.addEventListener('pagehide', function () { destroyCharts(pane); });
    }

    function init(pane) {
        bind(pane);

        if (typeof Chart === 'undefined') {
            showError(pane, 'Charts could not be initialised: the charting library did not load.');
            return;
        }

        /* Charts inside a Bootstrap tab that has never been shown are laid out inside a
         * zero-width container and come out 0px wide. Draw immediately if this pane is
         * already visible, otherwise wait for the tab to be shown. */
        if (pane.offsetParent !== null) {
            load(pane);
            return;
        }

        /* asset_details.php's RMM tab strip wires its triggers with href="#pane-id";
         * data-bs-target is the other Bootstrap 5 spelling, so both are looked for. */
        var tabPane = pane.closest('.tab-pane');
        var trigger = tabPane && tabPane.id
            ? document.querySelector('[data-bs-toggle="tab"][href="#' + tabPane.id + '"], ' +
                                     '[data-bs-toggle="tab"][data-bs-target="#' + tabPane.id + '"]')
            : null;

        if (trigger) {
            trigger.addEventListener('shown.bs.tab', function () {
                if (!pane.hasAttribute('data-metrics-loaded')) {
                    pane.setAttribute('data-metrics-loaded', '1');
                    load(pane);
                } else {
                    // Already drawn once, but the canvas may have been sized while
                    // hidden. A resize is cheap and fixes a squashed first paint.
                    var registry = registries.get(pane) || {};
                    Object.keys(registry).forEach(function (id) {
                        try { registry[id].resize(); } catch (e) { /* mid-teardown */ }
                    });
                }
            });
        } else {
            load(pane);
        }
    }

    function boot() {
        document.querySelectorAll('[data-asset-metrics]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
