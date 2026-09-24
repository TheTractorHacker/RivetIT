/*
 * Training › Achievements (spec §5.8): the badge board and its docked editor panel.
 * Page data comes from #tr-page-data. Rule summaries are rendered from {text, strong} parts
 * with textContent; nothing here writes HTML strings.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        var items = Array.isArray(data.achievements) ? data.achievements : [];
        var ruleTypes = Array.isArray(data.rule_types) ? data.rule_types : [];
        var ruleByKey = {};
        ruleTypes.forEach(function (r) { ruleByKey[r.key] = r; });
        var names = { course: {}, category: {}, path: {} };
        (data.courses || []).forEach(function (c) { names.course[c.id] = c.name; });
        (data.categories || []).forEach(function (c) { names.category[c.id] = c.name; });
        (data.paths || []).forEach(function (p) { names.path[p.id] = p.name; });
        var options = { course: data.courses || [], category: data.categories || [], path: data.paths || [] };
        var HEX = /^#[0-9A-Fa-f]{6}$/;

        var grid = document.getElementById('tr-ach-grid');
        var layout = document.getElementById('tr-achievements');
        var panel = document.getElementById('tr-ach-panel');
        var filter = 'all';

        function $(id) { return document.getElementById(id); }
        function icon(name) { return el('i', { class: 'fas fa-' + name, 'aria-hidden': 'true' }); }
        function safeColor(c) { return HEX.test(c || '') ? c : (data.default_color || '#D97706'); }
        function humanIcon(name) { return name.replace(/-/g, ' ').replace(/\balt\b/, '').trim(); }

        function medal(iconName, color, size, label) {
            var m = el('span', { class: 'tr-medal' + (size ? ' tr-medal--' + size : ''), role: 'img', 'aria-label': label || humanIcon(iconName) }, [icon(iconName)]);
            m.style.setProperty('--tr-medal', safeColor(color));
            return m;
        }

        function partsNode(parts, cls) {
            var p = el('p', { class: cls });
            (parts || []).forEach(function (part) { p.appendChild(part.strong ? el('strong', { text: part.text }) : document.createTextNode(part.text)); });
            return p;
        }

        /** Client mirror of AchievementRules::summary() for the live preview (the server's parts are shown once saved). */
        function summaryParts(type, rule) {
            var t = function (s, strong) { return { text: s, strong: !!strong }; };
            var n = function (kind, id) { return id ? (names[kind][id] || null) : null; };
            rule = rule || {};
            switch (type) {
                case 'course_completed': return [t('Completes '), t(n('course', rule.course_id) || 'a course', true)];
                case 'category_completed': return [t('Completes every course in '), t(n('category', rule.category_id) || 'a category', true)];
                case 'path_completed': return [t('Completes the '), t(n('path', rule.path_id) || 'chosen', true), t(' learning path')];
                case 'perfect_score':
                    return rule.course_id ? [t('Scores '), t('100%', true), t(' on the final exam of '), t(n('course', rule.course_id) || 'a course', true)]
                        : [t('Scores '), t('100%', true), t(' on any final exam')];
                case 'first_attempt_pass':
                    return rule.course_id ? [t('Passes the final exam of '), t(n('course', rule.course_id) || 'a course', true), t(' on the first try')]
                        : [t('Passes any final exam on the '), t('first try', true)];
                case 'on_time_streak':
                    var m = Number(rule.months) || 0;
                    return [t('All required training on time for '), t(m === 1 ? '1 month' : m + ' months', true)];
                case 'courses_completed_count':
                    var c = Number(rule.count) || 0;
                    var parts = [t('Completes '), t(c === 1 ? '1 course' : c + ' courses', true)];
                    if (rule.category_id) { parts.push(t(' in '), t(n('category', rule.category_id) || 'a category', true)); }
                    return parts;
                default: return [t('Awarded by a trainer or admin')];
            }
        }

        // ------------------------------------------------------------------ board
        function counts() {
            var c = { all: items.length, automatic: 0, manual: 0 };
            items.forEach(function (a) { c[a.kind === 'manual' ? 'manual' : 'automatic']++; });
            return c;
        }

        function renderFilter() {
            var c = counts();
            document.querySelectorAll('#tr-ach-filter .tr-cat-segment__btn').forEach(function (b) {
                var on = b.dataset.filter === filter;
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                b.classList.toggle('is-active', on);
                b.querySelector('[data-count]').textContent = String(c[b.dataset.filter]);
            });
        }

        function card(a) {
            var sw = el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id: 'tr-ach-active-' + a.id });
            sw.checked = !!a.active;
            sw.addEventListener('change', function () { setActive(a, sw); });
            var menu = el('div', { class: 'dropdown tr-ach-card__menu' }, [
                el('button', { type: 'button', class: 'btn btn-icon btn-ghost-secondary', 'aria-label': 'More options for ' + a.name, 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown' } }, [icon('ellipsis-v')]),
                el('div', { class: 'dropdown-menu dropdown-menu-end' }, [
                    el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { openPanel(a, false); } } }, [icon('pen'), ' Edit']),
                    el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { openPanel(a, true); } } }, [icon('copy'), ' Duplicate']),
                    el('div', { class: 'dropdown-divider' }),
                    el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { archive(a, node); } } }, [icon('archive'), ' Archive'])
                ])
            ]);
            var usedBy = (a.paths || []).map(function (p) { return p.name; });
            var node = el('article', { class: 'tr-ach-card' + (a.active ? '' : ' is-inactive'), dataset: { achievementId: a.id } }, [
                menu,
                el('div', { class: 'tr-ach-card__body' }, [
                    medal(a.icon, a.color, null, a.name + ' badge'),
                    el('h3', { class: 'tr-ach-card__name' }, [
                        el('a', { href: '#', class: 'stretched-link', role: 'button', text: a.name, on: { click: function (e) { e.preventDefault(); openPanel(a, false); } } })
                    ]),
                    a.description ? el('p', { class: 'tr-ach-card__desc', text: a.description }) : null,
                    partsNode(a.summary, 'tr-ach-card__rule'),
                    el('span', { class: 'tr-cat-kind tr-cat-kind--' + a.kind }, [icon(a.kind === 'manual' ? 'hand-paper' : 'bolt'), ' ', a.kind === 'manual' ? 'Manual' : 'Automatic'])
                ]),
                el('div', { class: 'tr-ach-card__foot' }, [
                    el('span', { class: 'small text-muted text-truncate', title: usedBy.join(', '), text: usedBy.length ? 'Path: ' + usedBy.join(', ') : (a.active ? 'Not awarded yet' : 'Inactive') }),
                    el('div', { class: 'form-check form-switch mb-0 ms-auto tr-ach-card__switch' }, [sw, el('label', { class: 'form-check-label small', for: 'tr-ach-active-' + a.id, text: 'Active' })])
                ])
            ]);
            return node;
        }

        function renderGrid() {
            grid.textContent = '';
            grid.removeAttribute('aria-busy');
            var list = items.filter(function (a) { return filter === 'all' || (filter === 'manual' ? a.kind === 'manual' : a.kind !== 'manual'); });
            if (!list.length) {
                grid.appendChild(el('div', { class: 'tr-empty tr-cat-empty' }, [
                    el('div', { class: 'tr-cat-empty__icon', 'aria-hidden': 'true' }, [icon('award')]),
                    el('p', { class: 'tr-cat-empty__title', text: items.length ? 'No achievements of this kind' : 'No achievements yet' }),
                    el('p', { class: 'text-muted', text: 'Badges like "Safety Starter" or "Crane Crew Certified" reward finishing training.' }),
                    items.length ? null : el('button', { type: 'button', class: 'btn btn-primary', on: { click: function () { openPanel(null, false); } } }, [icon('plus'), ' New achievement'])
                ]));
            } else {
                list.forEach(function (a) { grid.appendChild(card(a)); });
            }
            renderFilter();
        }

        function upsert(a) {
            var i = items.findIndex(function (x) { return x.id === a.id; });
            if (i === -1) { items.push(a); } else { items[i] = a; }
            items.sort(function (x, y) { return (x.sort - y.sort) || x.name.localeCompare(y.name); });
            renderGrid();
        }

        function setActive(a, sw) {
            sw.disabled = true;
            api.post('achievement_save', { achievement_id: a.id, version: a.version, data: { active: sw.checked } }).then(function (saved) {
                upsert(saved);
                ui.toast(saved.active ? '"' + saved.name + '" is active.' : '"' + saved.name + '" is inactive.');
            }, function (err) {
                if (err.code === 'conflict' && err.data && err.data.current) {
                    upsert(err.data.current);
                    ui.toast('Someone else changed this achievement. Showing their version.', { type: 'warning' });
                } else {
                    sw.checked = !sw.checked;
                    ui.toast(err.message, { type: 'error' });
                }
            }).then(function () { sw.disabled = false; });
        }

        function archive(a, node) {
            ui.confirmBar(node, { message: 'Archive "' + a.name + '"?', confirmLabel: 'Archive', danger: true }).then(function (yes) {
                if (!yes) { return; }
                api.post('achievement_archive', { achievement_id: a.id }).then(function () {
                    items = items.filter(function (x) { return x.id !== a.id; });
                    if (current && current.id === a.id) { closePanel(true); }
                    renderGrid();
                    ui.toast('Achievement archived.');
                }, function (err) { ui.toast(err.message, { type: 'error' }); });
            });
        }

        document.querySelectorAll('#tr-ach-filter .tr-cat-segment__btn').forEach(function (b) {
            b.addEventListener('click', function () { filter = b.dataset.filter; renderGrid(); });
        });

        // ------------------------------------------------------------------ editor panel
        var current = null;     // the achievement being edited (null = new)
        var form = { icon: data.default_icon || 'award', color: data.default_color || '#D97706', rule: {}, dirty: false, conflict: null };

        function renderIcons() {
            var wrap = $('tr-ach-icons');
            wrap.textContent = '';
            (data.icons || []).forEach(function (name) {
                var on = form.icon === name;
                wrap.appendChild(el('button', {
                    type: 'button', class: 'tr-cat-icon' + (on ? ' is-selected' : ''), role: 'radio', 'aria-checked': on ? 'true' : 'false',
                    'aria-label': humanIcon(name), title: humanIcon(name),
                    on: { click: function () { form.icon = name; form.dirty = true; renderIcons(); renderPreview(); } }
                }, [icon(name)]));
            });
        }

        function renderColors() {
            var wrap = $('tr-ach-colors');
            wrap.textContent = '';
            (data.swatches || []).forEach(function (c) {
                var on = form.color.toUpperCase() === c.toUpperCase();
                var b = el('button', {
                    type: 'button', class: 'tr-cat-swatch' + (on ? ' is-selected' : ''), role: 'radio', 'aria-checked': on ? 'true' : 'false', 'aria-label': 'Color ' + c, title: c,
                    on: { click: function () { form.color = c; form.dirty = true; $('tr-ach-hex').value = c; renderColors(); renderPreview(); } }
                });
                b.style.setProperty('--tr-cat-swatch', c);
                wrap.appendChild(b);
            });
        }

        function paramLabel(p) {
            return { course_id: 'Course', category_id: 'Category', path_id: 'Learning path', months: 'Months in a row', count: 'Number of courses' }[p.name] || p.name;
        }

        function renderParams() {
            var wrap = $('tr-ach-params');
            wrap.textContent = '';
            var def = ruleByKey[$('tr-ach-rule').value];
            if (!def) { return; }
            def.params.forEach(function (p) {
                var id = 'tr-ach-param-' + p.name;
                var input;
                if (p.kind === 'int') {
                    input = el('input', { type: 'number', class: 'form-control', id: id, min: String(p.min), max: String(p.max), step: '1', inputmode: 'numeric' });
                    input.value = form.rule[p.name] !== undefined && form.rule[p.name] !== null ? String(form.rule[p.name]) : '';
                } else {
                    input = el('select', { class: 'form-select', id: id });
                    input.appendChild(el('option', { value: '', text: p.required ? 'Choose…' : (p.kind === 'course' ? 'Any final exam' : 'Any') }));
                    options[p.kind].forEach(function (o) { input.appendChild(el('option', { value: String(o.id), text: o.name })); });
                    var v = form.rule[p.name];
                    if (v && !options[p.kind].some(function (o) { return o.id === Number(v); })) {
                        // A reference that is no longer offered (archived) stays visible so a save does not silently drop it.
                        input.appendChild(el('option', { value: String(v), text: (names[p.kind][v] || '(no longer available)') }));
                    }
                    input.value = v ? String(v) : '';
                }
                input.addEventListener('input', function () {
                    var raw = input.value;
                    form.rule[p.name] = raw === '' ? null : Number(raw);
                    form.dirty = true;
                    renderPreview();
                });
                wrap.appendChild(el('div', { class: 'tr-cat-rule-param' }, [
                    el('span', { class: 'tr-cat-rule-param__elbow', 'aria-hidden': 'true' }),
                    el('div', { class: 'flex-grow-1' }, [
                        el('label', { class: 'form-label', for: id, text: paramLabel(p) + (p.required ? '' : ' (optional)') }),
                        input,
                        el('div', { class: 'invalid-feedback d-block', dataset: { field: 'rule.' + p.name } })
                    ])
                ]));
            });
        }

        function renderPreview() {
            var name = $('tr-ach-name').value.trim() || 'New achievement';
            var m = $('tr-ach-preview-medal');
            m.style.setProperty('--tr-medal', safeColor(form.color));
            m.textContent = '';
            m.appendChild(icon(form.icon));
            m.setAttribute('aria-label', name + ' badge preview');
            $('tr-ach-preview-name').textContent = name;
            var rule = $('tr-ach-preview-rule');
            rule.textContent = '';
            var desc = $('tr-ach-description').value.trim();
            if (desc) {
                rule.textContent = desc;
            } else {
                summaryParts($('tr-ach-rule').value, form.rule).forEach(function (part) {
                    rule.appendChild(part.strong ? el('strong', { text: part.text }) : document.createTextNode(part.text));
                });
            }
        }

        function clearErrors() {
            panel.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
            panel.querySelectorAll('.invalid-feedback[data-field]').forEach(function (n) { n.textContent = ''; });
            $('tr-ach-conflict').classList.add('d-none');
        }

        function showErrors(fields) {
            var shown = false;
            Object.keys(fields || {}).forEach(function (k) {
                var fb = panel.querySelector('.invalid-feedback[data-field="' + k + '"]');
                if (fb) { fb.textContent = fields[k]; shown = true; }
                var input = { name: 'tr-ach-name', description: 'tr-ach-description', color: 'tr-ach-hex' }[k];
                if (input) { $(input).classList.add('is-invalid'); }
                if (k.indexOf('rule.') === 0 && $('tr-ach-param-' + k.slice(5))) { $('tr-ach-param-' + k.slice(5)).classList.add('is-invalid'); }
            });
            return shown;
        }

        function fill(a, asCopy) {
            clearErrors();
            current = a && !asCopy ? a : null;
            form = {
                icon: a ? a.icon : (data.default_icon || 'award'),
                color: a ? a.color : (data.default_color || '#D97706'),
                rule: a && a.rule ? Object.assign({}, a.rule) : {},
                dirty: !!asCopy,
                conflict: null
            };
            $('tr-ach-panel-title').textContent = current ? 'Edit achievement' : 'New achievement';
            $('tr-ach-name').value = a ? (asCopy ? a.name + ' (copy)' : a.name) : '';
            $('tr-ach-description').value = a && a.description ? a.description : '';
            $('tr-ach-active').checked = a ? !!a.active : true;
            $('tr-ach-hex').value = form.color;
            var sel = $('tr-ach-rule');
            sel.value = a ? a.rule_type : 'manual';
            panel.querySelectorAll('.tr-cat-lang__row').forEach(function (row) {
                var tr = (a && a.i18n && a.i18n[row.dataset.lang]) || {};
                row.querySelector('[data-i18n="name"]').value = tr.name || '';
                row.querySelector('[data-i18n="description"]').value = tr.description || '';
            });
            $('tr-ach-archive').hidden = !current;
            renderIcons();
            renderColors();
            renderParams();
            renderPreview();
        }

        function openPanel(a, asCopy) {
            var go = (form.dirty && !panel.hidden) ? ui.confirmBar($('tr-ach-footer').parentNode, { message: 'Discard your unsaved changes?', confirmLabel: 'Discard', cancelLabel: 'Keep editing', danger: true }) : Promise.resolve(true);
            go.then(function (yes) {
                if (!yes) { return; }
                fill(a, asCopy);
                panel.hidden = false;
                layout.classList.add('has-panel');
                $('tr-ach-name').focus();
                if (window.matchMedia && window.matchMedia('(max-width: 1199.98px)').matches) { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            });
        }

        function closePanel(force) {
            var go = (!force && form.dirty) ? ui.confirmBar($('tr-ach-footer').parentNode, { message: 'Discard your unsaved changes?', confirmLabel: 'Discard', cancelLabel: 'Keep editing', danger: true }) : Promise.resolve(true);
            go.then(function (yes) {
                if (!yes) { return; }
                form.dirty = false;
                current = null;
                panel.hidden = true;
                layout.classList.remove('has-panel');
            });
        }

        function collect() {
            var rule = {};
            var def = ruleByKey[$('tr-ach-rule').value];
            (def ? def.params : []).forEach(function (p) {
                var v = form.rule[p.name];
                if (v !== null && v !== undefined && !isNaN(v)) { rule[p.name] = v; }
            });
            var body = {
                name: $('tr-ach-name').value,
                description: $('tr-ach-description').value,
                icon: form.icon,
                color: form.color,
                rule_type: $('tr-ach-rule').value,
                rule: rule,
                active: $('tr-ach-active').checked
            };
            var i18n = {};
            panel.querySelectorAll('.tr-cat-lang__row').forEach(function (row) {
                i18n[row.dataset.lang] = { name: row.querySelector('[data-i18n="name"]').value, description: row.querySelector('[data-i18n="description"]').value };
            });
            if (Object.keys(i18n).length) { body.i18n = i18n; }
            return body;
        }

        function save() {
            clearErrors();
            if ($('tr-ach-name').value.trim() === '') {
                showErrors({ name: 'Give the achievement a name.' });
                $('tr-ach-name').focus();
                return;
            }
            if (!HEX.test(form.color)) {
                showErrors({ color: 'Use a color like #D97706.' });
                return;
            }
            var btn = $('tr-ach-save');
            btn.disabled = true;
            var req = { data: collect() };
            if (current) { req.achievement_id = current.id; req.version = current.version; }
            api.post('achievement_save', req).then(function (a) {
                form.dirty = false;
                upsert(a);
                ui.toast(current ? 'Achievement saved.' : 'Achievement created.');
                closePanel(true);
            }, function (err) {
                if (err.code === 'conflict' && err.data && err.data.current) {
                    form.conflict = err.data.current;
                    $('tr-ach-conflict').classList.remove('d-none');
                } else if (!showErrors(err.fields)) {
                    ui.toast(err.message, { type: 'error' });
                }
            }).then(function () { btn.disabled = false; });
        }

        // Rule type options.
        var ruleSel = $('tr-ach-rule');
        ruleTypes.forEach(function (r) { ruleSel.appendChild(el('option', { value: r.key, text: r.label })); });
        ruleSel.addEventListener('change', function () { form.rule = {}; form.dirty = true; renderParams(); renderPreview(); });
        $('tr-ach-hex').addEventListener('input', function () {
            var v = this.value.trim();
            if (v && v[0] !== '#') { v = '#' + v; }
            if (HEX.test(v)) { form.color = v.toUpperCase(); this.classList.remove('is-invalid'); renderColors(); renderPreview(); }
            form.dirty = true;
        });
        ['tr-ach-name', 'tr-ach-description'].forEach(function (id) { $(id).addEventListener('input', function () { form.dirty = true; renderPreview(); }); });
        $('tr-ach-active').addEventListener('change', function () { form.dirty = true; });
        panel.querySelectorAll('[data-i18n]').forEach(function (n) { n.addEventListener('input', function () { form.dirty = true; }); });
        $('tr-ach-form').addEventListener('submit', function (e) { e.preventDefault(); save(); });
        $('tr-ach-save').addEventListener('click', save);
        $('tr-ach-cancel').addEventListener('click', function () { closePanel(false); });
        $('tr-ach-close').addEventListener('click', function () { closePanel(false); });
        $('tr-ach-archive').addEventListener('click', function () { if (current) { archive(current, $('tr-ach-footer').parentNode); } });
        $('tr-ach-reload').addEventListener('click', function () { if (form.conflict) { upsert(form.conflict); fill(form.conflict, false); } });
        $('tr-ach-new').addEventListener('click', function () { openPanel(null, false); });
        window.addEventListener('beforeunload', function (e) {
            if (form.dirty && !panel.hidden) { e.preventDefault(); e.returnValue = ''; }
        });

        renderGrid();
        if (!items.length) { openPanel(null, false); }
    });
})();
