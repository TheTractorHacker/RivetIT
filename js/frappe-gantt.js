/*
 * frappe-gantt.js (ITFlow self-hosted, vanilla JS build)
 * -------------------------------------------------------
 * A compact, dependency-free SVG Gantt chart. No jQuery, no CDN.
 * Reads the same task array shape as frappe-gantt:
 *   { id, name, start:'YYYY-MM-DD', end:'YYYY-MM-DD', progress, dependencies, custom_class }
 *
 * Public API (subset compatible with frappe-gantt usage in ITFlow):
 *   const g = new Gantt(wrapper, tasks, {
 *       view_mode: 'Day' | 'Week' | 'Month',
 *       on_date_change: (task, start, end) => {},
 *       on_progress_change: (task, progress) => {},
 *       on_click: (task) => {},
 *   });
 *   g.change_view_mode('Week');
 *   g.refresh(tasks);
 *
 * Bars support drag-to-move, edge-resize and a progress handle.
 * Milestones (custom_class containing "milestone") render as diamonds.
 */
(function (global) {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // ---- date helpers (day granularity, local time) ----
    function toDate(value) {
        if (value instanceof Date) return value;
        if (!value) return new Date();
        var parts = String(value).substring(0, 10).split('-');
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }
    function fmt(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }
    function addDays(date, n) {
        var x = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        x.setDate(x.getDate() + n);
        return x;
    }
    function diffDays(a, b) {
        // whole days between two dates (a - b), DST-safe
        var utcA = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate());
        var utcB = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate());
        return Math.round((utcA - utcB) / 86400000);
    }

    // ---- svg helper ----
    function svgEl(tag, attrs, parent) {
        var e = document.createElementNS(SVG_NS, tag);
        if (attrs) {
            for (var k in attrs) {
                if (!attrs.hasOwnProperty(k)) continue;
                if (k === 'text') {
                    e.appendChild(document.createTextNode(attrs[k]));
                } else {
                    e.setAttribute(k, attrs[k]);
                }
            }
        }
        if (parent) parent.appendChild(e);
        return e;
    }

    function Gantt(wrapper, tasks, options) {
        this.wrapper = typeof wrapper === 'string' ? document.querySelector(wrapper) : wrapper;
        this.options = Object.assign({
            bar_height: 20,
            padding: 18,
            header_height: 50,
            column_width: null,
            view_mode: 'Week',
            on_date_change: null,
            on_progress_change: null,
            on_click: null
        }, options || {});

        this.view_modes = {
            Day: { step: 1, column_width: 38, lower: 'day', upper: 'month' },
            Week: { step: 7, column_width: 140, lower: 'week', upper: 'month' },
            Month: { step: 30, column_width: 120, lower: 'month', upper: 'year' }
        };

        this.set_tasks(tasks);
        this.change_view_mode(this.options.view_mode);
    }

    Gantt.prototype.set_tasks = function (tasks) {
        var self = this;
        this.tasks = (tasks || []).map(function (t, i) {
            var task = Object.assign({}, t);
            task._start = toDate(t.start);
            task._end = toDate(t.end);
            if (diffDays(task._end, task._start) < 0) { task._end = new Date(task._start); }
            task.start = fmt(task._start);
            task.end = fmt(task._end);
            task._index = i;
            task.progress = Math.max(0, Math.min(100, parseFloat(t.progress) || 0));
            task.is_milestone = /milestone/i.test(task.custom_class || '');
            if (typeof t.dependencies === 'string') {
                task.dependencies = t.dependencies.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            } else if (Array.isArray(t.dependencies)) {
                task.dependencies = t.dependencies.slice();
            } else {
                task.dependencies = [];
            }
            return task;
        });
        this.task_map = {};
        this.tasks.forEach(function (t) { self.task_map[t.id] = t; });
    };

    Gantt.prototype.refresh = function (tasks) {
        this.set_tasks(tasks);
        this.render();
    };

    Gantt.prototype.change_view_mode = function (mode) {
        this.view_mode = this.view_modes[mode] ? mode : 'Week';
        this.view = this.view_modes[this.view_mode];
        this.column_width = this.options.column_width || this.view.column_width;
        this.step = this.view.step;
        this.render();
    };

    Gantt.prototype.setup_date_range = function () {
        var min = null, max = null;
        this.tasks.forEach(function (t) {
            if (min === null || t._start < min) min = t._start;
            if (max === null || t._end > max) max = t._end;
        });
        if (min === null) { min = new Date(); max = new Date(); }

        var padBefore = this.view_mode === 'Day' ? 3 : this.step;
        var padAfter = this.view_mode === 'Day' ? 5 : this.step * 2;
        this.gantt_start = addDays(min, -padBefore);
        this.gantt_end = addDays(max, padAfter);
        this.total_days = diffDays(this.gantt_end, this.gantt_start) + 1;
        this.columns = Math.max(1, Math.ceil(this.total_days / this.step));
    };

    Gantt.prototype.x_for = function (date) {
        return (diffDays(date, this.gantt_start) / this.step) * this.column_width;
    };

    Gantt.prototype.render = function () {
        if (!this.wrapper) return;
        this.setup_date_range();

        this.wrapper.innerHTML = '';
        this.wrapper.classList.add('gantt-container');

        var opt = this.options;
        var rows = this.tasks.length || 1;
        var width = this.columns * this.column_width;
        var height = opt.header_height + rows * (opt.bar_height + opt.padding);

        this.svg = svgEl('svg', { class: 'gantt', width: width, height: height }, this.wrapper);

        this.layers = {};
        ['grid', 'date', 'arrow', 'bar'].forEach(function (name) {
            this.layers[name] = svgEl('g', { class: 'layer-' + name }, this.svg);
        }, this);

        this.make_grid(width, height, rows);
        this.make_dates();
        this.make_bars();
        this.make_arrows();
    };

    Gantt.prototype.make_grid = function (width, height, rows) {
        var opt = this.options;
        var grid = this.layers.grid;

        svgEl('rect', { x: 0, y: 0, width: width, height: height, class: 'grid-background' }, grid);

        var row_height = opt.bar_height + opt.padding;
        var y = opt.header_height;
        for (var i = 0; i < rows; i++) {
            svgEl('rect', { x: 0, y: y, width: width, height: row_height, class: 'grid-row' + (i % 2 ? ' odd' : '') }, grid);
            svgEl('line', { x1: 0, y1: y + row_height, x2: width, y2: y + row_height, class: 'row-line' }, grid);
            y += row_height;
        }

        svgEl('rect', { x: 0, y: 0, width: width, height: opt.header_height, class: 'grid-header' }, grid);

        for (var c = 0; c <= this.columns; c++) {
            var x = c * this.column_width;
            svgEl('line', { x1: x, y1: opt.header_height, x2: x, y2: height, class: 'tick' }, grid);
        }

        if (this.view_mode === 'Day') {
            for (var d = 0; d < this.columns; d++) {
                var day = addDays(this.gantt_start, d * this.step);
                var dow = day.getDay();
                if (dow === 0 || dow === 6) {
                    svgEl('rect', {
                        x: d * this.column_width, y: opt.header_height,
                        width: this.column_width, height: height - opt.header_height,
                        class: 'weekend-highlight'
                    }, grid);
                }
            }
        }

        var today = new Date();
        today = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (today >= this.gantt_start && today <= this.gantt_end) {
            var tx = this.x_for(today);
            svgEl('rect', { x: tx, y: 0, width: 2, height: height, class: 'today-line' }, grid);
        }
    };

    Gantt.prototype.make_dates = function () {
        var opt = this.options;
        var last_upper = '';
        for (var c = 0; c < this.columns; c++) {
            var d = addDays(this.gantt_start, c * this.step);
            var x = c * this.column_width;

            var lower = '';
            if (this.view.lower === 'day') lower = d.getDate();
            else if (this.view.lower === 'week') lower = d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()];
            else if (this.view.lower === 'month') lower = MONTHS_SHORT[d.getMonth()];

            svgEl('text', {
                x: x + this.column_width / 2, y: opt.header_height - 10,
                class: 'lower-text', 'text-anchor': 'middle', text: String(lower)
            }, this.layers.date);

            var upper = '';
            if (this.view.upper === 'month') upper = MONTHS[d.getMonth()] + ' ' + d.getFullYear();
            else if (this.view.upper === 'year') upper = String(d.getFullYear());

            if (upper !== last_upper) {
                svgEl('text', {
                    x: x + 6, y: opt.header_height / 2 - 4,
                    class: 'upper-text', 'text-anchor': 'start', text: upper
                }, this.layers.date);
                last_upper = upper;
            }
        }
    };

    Gantt.prototype.progress_points = function (px, y, h) {
        return (px - 5) + ',' + (y + h + 1) + ' ' + (px + 5) + ',' + (y + h + 1) + ' ' + px + ',' + (y + h - 4);
    };

    Gantt.prototype.make_bars = function () {
        var opt = this.options;
        var self = this;
        this.tasks.forEach(function (task, i) {
            var row_height = opt.bar_height + opt.padding;
            var y = opt.header_height + opt.padding / 2 + i * row_height;
            var x = self.x_for(task._start);
            var width = self.x_for(addDays(task._end, 1)) - x; // end date inclusive
            if (width < 1) width = 1;

            var g = svgEl('g', { class: 'bar-wrapper ' + (task.custom_class || ''), 'data-id': task.id }, self.layers.bar);

            if (task.is_milestone) {
                var cx = x, cy = y + opt.bar_height / 2, s = opt.bar_height / 1.4;
                svgEl('path', {
                    d: 'M ' + cx + ' ' + (cy - s) + ' L ' + (cx + s) + ' ' + cy + ' L ' + cx + ' ' + (cy + s) + ' L ' + (cx - s) + ' ' + cy + ' Z',
                    class: 'bar-milestone-shape'
                }, g);
                svgEl('text', {
                    x: cx + s + 6, y: cy, class: 'bar-label outside',
                    'dominant-baseline': 'central', text: task.name
                }, g);
                task._geom = { x: x, y: y, width: width, cx: cx, cy: cy };
                task._els = { g: g };
                if (self.options.on_click) {
                    g.addEventListener('click', function () { self.options.on_click(task); });
                }
                return;
            }

            var bar = svgEl('rect', { x: x, y: y, width: width, height: opt.bar_height, rx: 3, ry: 3, class: 'bar' }, g);
            var pw = width * task.progress / 100;
            var prog = svgEl('rect', { x: x, y: y, width: pw, height: opt.bar_height, rx: 3, ry: 3, class: 'bar-progress' }, g);
            var label = svgEl('text', { x: x + width / 2, y: y + opt.bar_height / 2, class: 'bar-label', 'dominant-baseline': 'central', 'text-anchor': 'middle', text: task.name }, g);
            var lh = svgEl('rect', { x: x - 3, y: y, width: 8, height: opt.bar_height, class: 'handle left' }, g);
            var rh = svgEl('rect', { x: x + width - 5, y: y, width: 8, height: opt.bar_height, class: 'handle right' }, g);
            var ph = svgEl('polygon', { points: self.progress_points(x + pw, y, opt.bar_height), class: 'handle progress' }, g);

            task._geom = { x: x, y: y, width: width };
            task._els = { g: g, bar: bar, prog: prog, label: label, lh: lh, rh: rh, ph: ph };

            self.place_label(task);
            self.bind_bar_events(task);
        });
    };

    Gantt.prototype.place_label = function (task) {
        var opt = this.options;
        var label = task._els.label;
        if (!label) return;
        var g = task._geom;
        var tw = 0;
        try { tw = label.getBBox().width; } catch (e) { tw = String(task.name).length * 6.5; }
        if (tw + 12 < g.width) {
            label.setAttribute('x', g.x + g.width / 2);
            label.setAttribute('text-anchor', 'middle');
            label.classList.add('inside');
            label.classList.remove('outside');
        } else {
            label.setAttribute('x', g.x + g.width + 8);
            label.setAttribute('text-anchor', 'start');
            label.classList.add('outside');
            label.classList.remove('inside');
        }
    };

    Gantt.prototype.update_bar_position = function (task) {
        var opt = this.options;
        var x = this.x_for(task._start);
        var width = this.x_for(addDays(task._end, 1)) - x;
        if (width < 1) width = 1;
        var y = task._geom.y;
        task._geom.x = x;
        task._geom.width = width;

        var e = task._els;
        e.bar.setAttribute('x', x);
        e.bar.setAttribute('width', width);
        var pw = width * task.progress / 100;
        e.prog.setAttribute('x', x);
        e.prog.setAttribute('width', pw);
        e.lh.setAttribute('x', x - 3);
        e.rh.setAttribute('x', x + width - 5);
        e.ph.setAttribute('points', this.progress_points(x + pw, y, opt.bar_height));
        this.place_label(task);
    };

    Gantt.prototype.bind_bar_events = function (task) {
        var self = this;
        var e = task._els;

        function start_drag(ev, action) {
            ev.preventDefault();
            ev.stopPropagation();
            var startX = ev.clientX;
            var orig = {
                start: new Date(task._start),
                end: new Date(task._end),
                width: task._geom.width,
                progress: task.progress
            };
            e.g.classList.add('active');

            function onMove(mv) {
                var dx = mv.clientX - startX;
                if (action === 'progress') {
                    var np = orig.progress + (dx / (orig.width || 1)) * 100;
                    task.progress = Math.max(0, Math.min(100, Math.round(np)));
                } else {
                    var days = Math.round(dx / self.column_width * self.step);
                    if (action === 'move') {
                        task._start = addDays(orig.start, days);
                        task._end = addDays(orig.end, days);
                    } else if (action === 'left') {
                        var ns = addDays(orig.start, days);
                        if (diffDays(task._end, ns) < 0) ns = new Date(task._end);
                        task._start = ns;
                    } else if (action === 'right') {
                        var ne = addDays(orig.end, days);
                        if (diffDays(ne, task._start) < 0) ne = new Date(task._start);
                        task._end = ne;
                    }
                }
                self.update_bar_position(task);
                self.make_arrows();
            }

            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                e.g.classList.remove('active');

                if (action === 'progress') {
                    if (task.progress !== orig.progress && self.options.on_progress_change) {
                        self.options.on_progress_change(task, task.progress);
                    }
                } else {
                    task.start = fmt(task._start);
                    task.end = fmt(task._end);
                    var changed = task.start !== fmt(orig.start) || task.end !== fmt(orig.end);
                    if (changed && self.options.on_date_change) {
                        self.options.on_date_change(task, task.start, task.end);
                    }
                }
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }

        e.bar.addEventListener('mousedown', function (ev) { start_drag(ev, 'move'); });
        e.lh.addEventListener('mousedown', function (ev) { start_drag(ev, 'left'); });
        e.rh.addEventListener('mousedown', function (ev) { start_drag(ev, 'right'); });
        e.ph.addEventListener('mousedown', function (ev) { start_drag(ev, 'progress'); });

        var moved = false;
        e.bar.addEventListener('mousedown', function () { moved = false; });
        e.bar.addEventListener('mousemove', function () { moved = true; });
        e.bar.addEventListener('click', function () {
            if (!moved && self.options.on_click) self.options.on_click(task);
        });
    };

    Gantt.prototype.make_arrows = function () {
        var opt = this.options;
        var layer = this.layers.arrow;
        while (layer.firstChild) layer.removeChild(layer.firstChild);
        var self = this;

        this.tasks.forEach(function (task) {
            (task.dependencies || []).forEach(function (depId) {
                var from = self.task_map[depId];
                if (!from || !from._geom || !task._geom) return;
                var fx = from._geom.x + from._geom.width;
                var fy = from._geom.y + opt.bar_height / 2;
                var tx = task._geom.x;
                var ty = task._geom.y + opt.bar_height / 2;
                var elbow = fx + 12;
                svgEl('path', {
                    d: 'M ' + fx + ' ' + fy + ' L ' + elbow + ' ' + fy + ' L ' + elbow + ' ' + ty + ' L ' + tx + ' ' + ty,
                    class: 'arrow'
                }, layer);
                svgEl('polygon', {
                    points: tx + ',' + ty + ' ' + (tx - 7) + ',' + (ty - 4) + ' ' + (tx - 7) + ',' + (ty + 4),
                    class: 'arrow-head'
                }, layer);
            });
        });
    };

    global.Gantt = Gantt;

})(typeof window !== 'undefined' ? window : this);
