/*
 * Training › Learning paths (spec §5.7): path cards, the off-canvas path editor and the
 * prerequisite map. Page data comes from #tr-page-data; every string reaches the DOM through
 * textContent / DOM nodes (TrainingUi.el), never an HTML string sink.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        var canEdit = !!data.can_edit;
        var paths = Array.isArray(data.paths) ? data.paths : [];
        var prereqs = Array.isArray(data.prereqs) ? data.prereqs : [];
        var courses = Array.isArray(data.courses) ? data.courses : [];
        var achievements = Array.isArray(data.achievements) ? data.achievements : [];
        var courseById = {};
        courses.forEach(function (c) { courseById[c.id] = c; });

        var grid = document.getElementById('tr-path-grid');
        var countEl = document.getElementById('tr-path-count');
        var showArchived = document.getElementById('tr-show-archived');

        // ------------------------------------------------------------------ formatting
        function fmtMinutes(min) {
            min = Math.max(0, Math.round(Number(min) || 0));
            if (min === 0) { return ''; }
            if (min < 60) { return '~' + min + ' min'; }
            var h = Math.floor(min / 60);
            var m = min % 60;
            return '~' + h + ' h' + (m ? ' ' + m + ' min' : '');
        }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function safeColor(c, fallback) { return (typeof c === 'string' && /^#[0-9A-Fa-f]{6}$/.test(c)) ? c : fallback; }
        function icon(name) { return el('i', { class: 'fas fa-' + name, 'aria-hidden': 'true' }); }
        function medal(a, size) {
            var node = el('span', { class: 'tr-medal' + (size ? ' tr-medal--' + size : ''), role: 'img', 'aria-label': 'Achievement: ' + a.name, title: a.name }, [icon(a.icon || 'award')]);
            node.style.setProperty('--tr-medal', safeColor(a.color, '#D97706'));
            return node;
        }
        function statusBadge(status) {
            if (status === 'published') { return null; }
            return el('span', { class: 'badge tr-badge-status--' + (status === 'archived' ? 'archived' : 'draft') + ' ms-1', text: status === 'archived' ? 'Archived' : 'Draft' });
        }

        // ------------------------------------------------------------------ path cards
        function visiblePaths() {
            return paths.filter(function (p) { return !p.archived || (showArchived && showArchived.checked); });
        }

        function card(p) {
            var color = safeColor(p.color, '#0D9488');
            var cover = el('div', { class: 'tr-path-card__cover' + (p.cover_url ? '' : ' is-fallback') });
            cover.style.setProperty('--tr-path-color', color);
            if (p.cover_url) {
                cover.appendChild(el('img', { src: p.cover_url, alt: '', loading: 'lazy' }));
            } else {
                cover.appendChild(el('span', { class: 'tr-path-card__glyph', 'aria-hidden': 'true' }, [icon('route')]));
            }
            if (p.archived) {
                cover.appendChild(el('span', { class: 'badge tr-badge-status--archived tr-path-card__flag', text: 'Archived' }));
            }
            var open = el('a', { href: '#', class: 'tr-path-card__title stretched-link', role: 'button', text: p.name,
                on: { click: function (e) { e.preventDefault(); (canEdit ? openEditor : openView)(p); } } });
            var meta = [plural(p.course_count, 'course', 'courses'), fmtMinutes(p.est_minutes)].filter(Boolean).join(' · ');
            var foot = el('div', { class: 'tr-path-card__foot' }, [
                el('span', { class: 'tr-chip', title: p.sequential ? 'Courses open one after another' : 'Courses can be taken in any order' },
                    [icon(p.sequential ? 'list-ol' : 'random'), ' ', p.sequential ? 'In order' : 'Any order']),
                p.achievement ? el('span', { class: 'tr-path-card__award' }, [medal(p.achievement, 'xs'), el('span', { class: 'text-truncate', text: p.achievement.name })]) : null
            ]);
            return el('article', { class: 'tr-path-card' + (p.archived ? ' is-archived' : ''), dataset: { pathId: p.id } }, [
                cover,
                el('div', { class: 'tr-path-card__body' }, [
                    el('h3', { class: 'tr-path-card__name' }, [open]),
                    el('div', { class: 'tr-path-card__meta text-muted', text: meta || 'No courses yet' }),
                    p.description ? el('p', { class: 'tr-path-card__desc', text: p.description }) : null,
                    foot
                ])
            ]);
        }

        function renderGrid() {
            var list = visiblePaths();
            grid.textContent = '';
            grid.removeAttribute('aria-busy');
            if (list.length === 0) {
                var actions = canEdit ? [el('button', { type: 'button', class: 'btn btn-primary', on: { click: function () { openEditor(null); } } }, [icon('plus'), ' New path'])] : [];
                grid.appendChild(el('div', { class: 'tr-empty tr-cat-empty' }, [
                    el('div', { class: 'tr-cat-empty__icon', 'aria-hidden': 'true' }, [icon('route')]),
                    el('p', { class: 'tr-cat-empty__title', text: 'No learning paths yet' }),
                    el('p', { class: 'text-muted', text: canEdit ? 'Put courses in order, like "New-Hire Safety Orientation".' : 'Learning paths appear here once they are set up.' }),
                    el('div', {}, actions)
                ]));
            } else {
                list.forEach(function (p) { grid.appendChild(card(p)); });
            }
            var live = paths.filter(function (p) { return !p.archived; }).length;
            countEl.textContent = plural(live, 'learning path', 'learning paths');
        }

        function upsertPath(p) {
            var i = paths.findIndex(function (x) { return x.id === p.id; });
            if (i === -1) { paths.push(p); } else { paths[i] = p; }
            paths.sort(function (a, b) { return (a.archived - b.archived) || a.name.localeCompare(b.name); });
            renderGrid();
        }

        if (showArchived) { showArchived.addEventListener('change', renderGrid); }

        // ------------------------------------------------------------------ read-only view (level 1)
        function openView(p) {
            var body = document.getElementById('tr-path-view-body');
            document.getElementById('tr-path-view-title').textContent = p.name;
            body.textContent = '';
            if (p.description) { body.appendChild(el('p', { text: p.description })); }
            body.appendChild(el('p', { class: 'text-muted small' }, [
                icon(p.sequential ? 'list-ol' : 'random'), ' ',
                p.sequential ? 'Take the courses in order.' : 'The courses can be taken in any order.'
            ]));
            var ol = el('ol', { class: 'tr-path-courses tr-path-courses--view list-unstyled' });
            p.courses.forEach(function (c, i) {
                ol.appendChild(el('li', { class: 'tr-path-course' }, [
                    el('span', { class: 'tr-path-course__num', text: String(i + 1) }),
                    el('div', { class: 'tr-path-course__main' }, [
                        el('div', { class: 'tr-path-course__name', text: c.name }),
                        el('div', { class: 'small text-muted', text: [plural(c.lessons_count, 'lesson', 'lessons'), fmtMinutes(c.est_minutes)].filter(Boolean).join(' · ') })
                    ]),
                    c.required ? null : el('span', { class: 'badge bg-secondary-lt', text: 'Optional' })
                ]));
            });
            body.appendChild(p.courses.length ? ol : el('p', { class: 'text-muted', text: 'No published courses in this path yet.' }));
            if (p.achievement) {
                body.appendChild(el('div', { class: 'tr-path-view__award' }, [medal(p.achievement, 'sm'),
                    el('div', {}, [el('div', { class: 'small text-muted', text: 'Earns' }), el('strong', { text: p.achievement.name })])]));
            }
            window.bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('tr-path-view')).show();
        }

        // ------------------------------------------------------------------ editor (level 2)
        var editorEl = document.getElementById('tr-path-editor');
        var editor = null;          // {path, version, courses:[{course_id, required, name, ...}], cover:{id,url}|null, color, dirty}
        var sortable = null;
        var addSelect = null;
        var discardConfirmed = false;

        function field(id) { return document.getElementById(id); }

        function clearErrors() {
            editorEl.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
            editorEl.querySelectorAll('.invalid-feedback[data-field]').forEach(function (n) { n.textContent = ''; });
            field('tr-path-conflict').classList.add('d-none');
        }

        function showFieldErrors(fields) {
            var inputs = { name: 'tr-path-name', description: 'tr-path-description', achievement_id: 'tr-path-achievement' };
            var shown = false;
            Object.keys(fields || {}).forEach(function (k) {
                var fb = editorEl.querySelector('.invalid-feedback[data-field="' + k + '"]');
                if (fb) { fb.textContent = fields[k]; shown = true; }
                if (inputs[k]) { field(inputs[k]).classList.add('is-invalid'); }
            });
            return shown;
        }

        function markDirty() { if (editor) { editor.dirty = true; } }

        function renderSwatches() {
            var wrap = field('tr-path-colors');
            wrap.textContent = '';
            [null].concat(data.swatches || []).forEach(function (c) {
                var selected = (editor.color || null) === c;
                var b = el('button', {
                    type: 'button', class: 'tr-cat-swatch' + (c ? '' : ' tr-cat-swatch--none') + (selected ? ' is-selected' : ''), role: 'radio',
                    'aria-checked': selected ? 'true' : 'false', 'aria-label': c ? 'Color ' + c : 'No color', title: c || 'No color',
                    on: { click: function () { editor.color = c; markDirty(); renderSwatches(); } }
                }, c ? [] : [icon('ban')]);
                if (c) { b.style.setProperty('--tr-cat-swatch', c); }
                wrap.appendChild(b);
            });
        }

        function renderCover() {
            var img = field('tr-path-cover-img');
            var hint = field('tr-path-cover-hint');
            var box = field('tr-path-cover');
            box.style.setProperty('--tr-path-color', safeColor(editor.color, '#0D9488'));
            if (editor.cover && editor.cover.url) {
                img.src = editor.cover.url;
                img.hidden = false;
                hint.hidden = true;
                field('tr-path-cover-remove').hidden = false;
            } else {
                img.removeAttribute('src');
                img.hidden = true;
                hint.hidden = false;
                field('tr-path-cover-remove').hidden = true;
            }
        }

        function renderCourses() {
            var list = field('tr-path-courses');
            list.textContent = '';
            editor.courses.forEach(function (c, i) {
                var sw = el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id: 'tr-pc-req-' + c.course_id,
                    on: { change: function () { c.required = sw.checked; markDirty(); } } });
                sw.checked = !!c.required;
                list.appendChild(el('li', { class: 'tr-path-course', dataset: { courseId: c.course_id } }, [
                    el('span', { class: 'tr-cat-drag', title: 'Drag to reorder', 'aria-hidden': 'true' }, [icon('grip-vertical')]),
                    el('span', { class: 'tr-path-course__num', text: String(i + 1) }),
                    el('div', { class: 'tr-path-course__main' }, [
                        el('div', { class: 'tr-path-course__name', title: c.name }, [c.name, statusBadge(c.status)]),
                        el('div', { class: 'tr-path-course__sub' }, [
                            el('span', { class: 'small text-muted', text: [c.kind === 'document' ? 'Document' : plural(c.lessons_count || 0, 'lesson', 'lessons'), fmtMinutes(c.est_minutes)].filter(Boolean).join(' · ') }),
                            el('div', { class: 'form-check form-switch tr-path-course__req' }, [sw, el('label', { class: 'form-check-label small', for: 'tr-pc-req-' + c.course_id, text: 'Required' })])
                        ])
                    ]),
                    el('div', { class: 'btn-group btn-group-sm tr-path-course__actions' }, [
                        el('button', { type: 'button', class: 'btn btn-ghost-secondary', 'aria-label': 'Move up', disabled: i === 0, on: { click: function () { move(i, -1); } } }, [icon('arrow-up')]),
                        el('button', { type: 'button', class: 'btn btn-ghost-secondary', 'aria-label': 'Move down', disabled: i === editor.courses.length - 1, on: { click: function () { move(i, 1); } } }, [icon('arrow-down')]),
                        el('button', { type: 'button', class: 'btn btn-ghost-danger', 'aria-label': 'Remove ' + c.name, on: { click: function () { editor.courses.splice(i, 1); markDirty(); renderCourses(); } } }, [icon('times')])
                    ])
                ]));
            });
            field('tr-path-courses-empty').hidden = editor.courses.length > 0;
            var total = editor.courses.reduce(function (s, c) { return s + (Number(c.est_minutes) || 0); }, 0);
            field('tr-path-courses-meta').textContent = [plural(editor.courses.length, 'course', 'courses'), fmtMinutes(total)].filter(Boolean).join(' · ');
            refreshAddOptions();
        }

        function move(i, d) {
            var j = i + d;
            if (j < 0 || j >= editor.courses.length) { return; }
            var tmp = editor.courses[i];
            editor.courses[i] = editor.courses[j];
            editor.courses[j] = tmp;
            markDirty();
            renderCourses();
        }

        function refreshAddOptions() {
            var sel = field('tr-path-course-add');
            var taken = {};
            editor.courses.forEach(function (c) { taken[c.course_id] = true; });
            var opts = courses.filter(function (c) { return !taken[c.id]; });
            if (addSelect) {
                addSelect.clear(true);
                addSelect.clearOptions();
                opts.forEach(function (c) { addSelect.addOption({ value: String(c.id), text: c.name + (c.code ? ' (' + c.code + ')' : '') }); });
                addSelect.refreshOptions(false);
                return;
            }
            sel.textContent = '';
            opts.forEach(function (c) { sel.appendChild(el('option', { value: String(c.id), text: c.name + (c.code ? ' (' + c.code + ')' : '') })); });
        }

        function addChosenCourses() {
            var ids = addSelect ? addSelect.getValue() : Array.prototype.map.call(field('tr-path-course-add').selectedOptions, function (o) { return o.value; });
            (Array.isArray(ids) ? ids : [ids]).filter(Boolean).forEach(function (v) {
                var c = courseById[Number(v)];
                if (!c || editor.courses.length >= (data.max_courses || 50)) { return; }
                editor.courses.push({ course_id: c.id, required: true, name: c.name, status: c.status, kind: c.kind, est_minutes: c.est_minutes, lessons_count: c.lessons_count });
            });
            markDirty();
            renderCourses();
        }

        function renderAchievementSelect(selectedId) {
            var sel = field('tr-path-achievement');
            sel.textContent = '';
            sel.appendChild(el('option', { value: '', text: 'None' }));
            achievements.forEach(function (a) {
                sel.appendChild(el('option', { value: String(a.id), text: a.name + (a.active ? '' : ' (inactive)') }));
            });
            sel.value = selectedId ? String(selectedId) : '';
        }

        function fillEditor(p) {
            clearErrors();
            editor = {
                path: p,
                version: p ? p.version : null,
                color: p ? (p.color || null) : null,
                cover: p && p.cover_media_id ? { id: p.cover_media_id, url: p.cover_url } : null,
                courses: p ? p.courses.map(function (c) { return Object.assign({}, c); }) : [],
                dirty: false
            };
            field('tr-path-editor-title').textContent = p ? 'Edit learning path' : 'New learning path';
            field('tr-path-name').value = p ? p.name : '';
            field('tr-path-description').value = p && p.description ? p.description : '';
            field('tr-path-sequential').checked = p ? !!p.sequential : true;
            editorEl.querySelectorAll('.tr-cat-lang__row').forEach(function (row) {
                var lang = row.dataset.lang;
                var tr = (p && p.i18n && p.i18n[lang]) || {};
                row.querySelector('[data-i18n="name"]').value = tr.name || '';
                row.querySelector('[data-i18n="description"]').value = tr.description || '';
            });
            renderAchievementSelect(p ? p.achievement_id : null);
            renderSwatches();
            renderCover();
            renderCourses();
            field('tr-path-archive').hidden = !p || !!p.archived;
            field('tr-path-restore').hidden = !p || !p.archived;
            field('tr-path-save').disabled = !!(p && p.archived);
            field('tr-path-cover-status').textContent = '';
        }

        function openEditor(p) {
            if (!editorEl) { return; }
            discardConfirmed = false;
            fillEditor(p);
            window.bootstrap.Offcanvas.getOrCreateInstance(editorEl).show();
        }

        function collect() {
            var i18n = {};
            editorEl.querySelectorAll('.tr-cat-lang__row').forEach(function (row) {
                i18n[row.dataset.lang] = {
                    name: row.querySelector('[data-i18n="name"]').value,
                    description: row.querySelector('[data-i18n="description"]').value
                };
            });
            var ach = field('tr-path-achievement').value;
            var body = {
                name: field('tr-path-name').value,
                description: field('tr-path-description').value,
                color: editor.color,
                cover_media_id: editor.cover ? editor.cover.id : null,
                sequential: field('tr-path-sequential').checked,
                achievement_id: ach ? Number(ach) : null,
                courses: editor.courses.map(function (c) { return { course_id: c.course_id, required: !!c.required }; })
            };
            if (Object.keys(i18n).length) { body.i18n = i18n; }
            return body;
        }

        function save() {
            clearErrors();
            if (field('tr-path-name').value.trim() === '') {
                showFieldErrors({ name: 'Give the path a name.' });
                field('tr-path-name').focus();
                return;
            }
            var btn = field('tr-path-save');
            btn.disabled = true;
            var req = { data: collect() };
            if (editor.path) { req.path_id = editor.path.id; req.version = editor.version; }
            api.post('path_save', req).then(function (p) {
                editor.dirty = false;
                upsertPath(p);
                ui.toast(editor.path ? 'Learning path saved.' : 'Learning path created.');
                window.bootstrap.Offcanvas.getOrCreateInstance(editorEl).hide();
            }, function (err) {
                if (err.code === 'conflict' && err.data && err.data.current) {
                    editor.conflict = err.data.current;
                    field('tr-path-conflict').classList.remove('d-none');
                } else if (!showFieldErrors(err.fields)) {
                    ui.toast(err.message, { type: 'error' });
                }
            }).then(function () { btn.disabled = false; });
        }

        function archive(restore) {
            if (!editor.path) { return; }
            var footer = field('tr-path-footer');
            var go = restore ? Promise.resolve(true) : ui.confirmBar(footer.parentNode, {
                message: 'Archive "' + editor.path.name + '"? It stops being offered; you can restore it later.',
                confirmLabel: 'Archive', danger: true
            });
            go.then(function (yes) {
                if (!yes) { return; }
                api.post('path_archive', { path_id: editor.path.id, restore: !!restore }).then(function (p) {
                    editor.dirty = false;
                    upsertPath(p);
                    ui.toast(restore ? 'Learning path restored.' : 'Learning path archived.');
                    window.bootstrap.Offcanvas.getOrCreateInstance(editorEl).hide();
                }, function (err) { ui.toast(err.message, { type: 'error' }); });
            });
        }

        function uploadCover(file) {
            if (!file) { return; }
            var status = field('tr-path-cover-status');
            if (!window.TrainingUploader) {
                status.textContent = 'Uploading is not available on this page.';
                return;
            }
            status.textContent = 'Uploading…';
            var opts = { purpose: 'path_cover', onProgress: function (p) { if (typeof p === 'number') { status.textContent = 'Uploading… ' + Math.round(p * (p <= 1 ? 100 : 1)) + '%'; } } };
            if (editor.path) { opts.pathId = editor.path.id; }
            window.TrainingUploader.upload(file, opts).then(function (d) {
                var m = d && d.media;
                if (!m) { throw new Error('Upload failed.'); }
                editor.cover = { id: m.id, url: m.url };
                markDirty();
                renderCover();
                status.textContent = '';
            }, function (err) {
                status.textContent = (err && err.message) || 'Upload failed.';
            });
        }

        if (editorEl) {
            if (window.Sortable) {
                sortable = new window.Sortable(field('tr-path-courses'), {
                    handle: '.tr-cat-drag', animation: 150,
                    onEnd: function (evt) {
                        if (evt.oldIndex === evt.newIndex) { return; }
                        var moved = editor.courses.splice(evt.oldIndex, 1)[0];
                        editor.courses.splice(evt.newIndex, 0, moved);
                        markDirty();
                        renderCourses();
                    }
                });
            }
            if (window.TomSelect) {
                addSelect = new window.TomSelect(field('tr-path-course-add'), {
                    plugins: ['remove_button'], maxOptions: 200, placeholder: 'Search courses',
                    dropdownParent: editorEl.querySelector('.offcanvas-body')
                });
            }
            field('tr-path-course-add-btn').addEventListener('click', addChosenCourses);
            field('tr-path-save').addEventListener('click', save);
            field('tr-path-archive').addEventListener('click', function () { archive(false); });
            field('tr-path-restore').addEventListener('click', function () { archive(true); });
            field('tr-path-reload').addEventListener('click', function () {
                if (editor && editor.conflict) { upsertPath(editor.conflict); fillEditor(editor.conflict); }
            });
            field('tr-path-form').addEventListener('input', markDirty);
            field('tr-path-form').addEventListener('change', markDirty);
            field('tr-path-form').addEventListener('submit', function (e) { e.preventDefault(); save(); });

            var fileInput = field('tr-path-cover-file');
            field('tr-path-cover-upload').addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () { uploadCover(fileInput.files[0]); fileInput.value = ''; });
            field('tr-path-cover-remove').addEventListener('click', function () { editor.cover = null; markDirty(); renderCover(); });
            var drop = field('tr-path-cover');
            ['dragenter', 'dragover'].forEach(function (t) {
                drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
            });
            ['dragleave', 'drop'].forEach(function (t) {
                drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
            });
            drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { uploadCover(e.dataTransfer.files[0]); } });

            // Closing with unsaved edits asks first (inline confirm strip, never a stacked modal).
            editorEl.addEventListener('hide.bs.offcanvas', function (e) {
                if (!editor || !editor.dirty || discardConfirmed) { return; }
                e.preventDefault();
                ui.confirmBar(field('tr-path-footer').parentNode, { message: 'Discard your changes to this path?', confirmLabel: 'Discard', cancelLabel: 'Keep editing', danger: true })
                    .then(function (yes) {
                        if (yes) { discardConfirmed = true; editor.dirty = false; window.bootstrap.Offcanvas.getOrCreateInstance(editorEl).hide(); }
                    });
            });
            editorEl.addEventListener('hidden.bs.offcanvas', function () { discardConfirmed = false; });
            window.addEventListener('beforeunload', function (e) {
                if (editor && editor.dirty && editorEl.classList.contains('show')) { e.preventDefault(); e.returnValue = ''; }
            });
            var newBtn = document.getElementById('tr-path-new');
            if (newBtn) { newBtn.addEventListener('click', function () { openEditor(null); }); }
        }

        // ------------------------------------------------------------------ prerequisite map
        var prereqBody = document.getElementById('tr-prereq-body');
        var prereqSearch = document.getElementById('tr-prereq-search');
        var panel = document.getElementById('tr-prereq-panel');
        var editing = null;   // {course, chosen:[{course_id, name}]}

        function chips(list) {
            if (!list.length) { return el('span', { class: 'text-muted', text: '—' }); }
            return el('div', { class: 'tr-cat-chips' }, list.map(function (c) { return el('span', { class: 'tr-chip', text: c.name }); }));
        }

        var prereqAll = document.getElementById('tr-prereq-all');

        function renderPrereqs() {
            var q = (prereqSearch && prereqSearch.value || '').trim().toLowerCase();
            var all = !!(prereqAll && prereqAll.checked);
            prereqBody.textContent = '';
            // By default only courses that take part in a prerequisite are listed; a search or
            // "All courses" reaches the rest (so an author can give a course its first one).
            var shown = prereqs.filter(function (c) {
                if (q) { return c.name.toLowerCase().indexOf(q) !== -1 || (c.code || '').toLowerCase().indexOf(q) !== -1; }
                return all || c.requires.length > 0 || c.required_by.length > 0 || (editing && editing.course.course_id === c.course_id);
            });
            if (!shown.length) {
                var msg = !prereqs.length ? 'No courses yet.'
                    : (q ? 'No courses match.' : 'No prerequisites yet.' + (canEdit ? ' Find a course above to add one.' : ''));
                prereqBody.appendChild(el('tr', {}, [el('td', { colspan: canEdit ? 4 : 3, class: 'text-muted text-center py-4', text: msg })]));
                return;
            }
            shown.forEach(function (c) {
                var cells = [
                    el('td', {}, [el('div', { class: 'fw-medium' }, [c.name, statusBadge(c.status)]), c.code ? el('div', { class: 'small text-muted font-monospace', text: c.code }) : null]),
                    el('td', {}, [chips(c.requires)]),
                    el('td', {}, [chips(c.required_by)])
                ];
                if (canEdit) {
                    cells.push(el('td', { class: 'text-end' }, c.status === 'archived' ? [] : [
                        el('button', { type: 'button', class: 'btn btn-sm btn-ghost-primary', 'aria-label': 'Edit prerequisites of ' + c.name,
                            on: { click: function () { openPanel(c); } } }, [icon('pen'), ' Edit'])
                    ]));
                }
                prereqBody.appendChild(el('tr', { class: editing && editing.course.course_id === c.course_id ? 'table-active' : '' }, cells));
            });
        }

        function renderPanel() {
            var list = document.getElementById('tr-prereq-chosen');
            list.textContent = '';
            editing.chosen.forEach(function (c, i) {
                list.appendChild(el('li', { class: 'tr-prereq-chosen__item' }, [
                    el('span', { class: 'text-truncate', text: c.name }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-ghost-danger ms-auto', 'aria-label': 'Remove ' + c.name,
                        on: { click: function () { editing.chosen.splice(i, 1); renderPanel(); } } }, [icon('times')])
                ]));
            });
            if (!editing.chosen.length) { list.appendChild(el('li', { class: 'text-muted small', text: 'No prerequisites.' })); }
            var sel = document.getElementById('tr-prereq-add');
            sel.textContent = '';
            var taken = {};
            editing.chosen.forEach(function (c) { taken[c.course_id] = true; });
            prereqs.forEach(function (c) {
                if (c.course_id === editing.course.course_id || taken[c.course_id] || c.status === 'archived') { return; }
                sel.appendChild(el('option', { value: String(c.course_id), text: c.name }));
            });
            document.getElementById('tr-prereq-add-btn').disabled = sel.options.length === 0 || editing.chosen.length >= (data.max_prereqs || 10);
        }

        function openPanel(c) {
            editing = { course: c, chosen: c.requires.map(function (r) { return { course_id: r.course_id, name: r.name }; }) };
            document.getElementById('tr-prereq-panel-title').textContent = c.name;
            document.getElementById('tr-prereq-error').textContent = '';
            panel.hidden = false;
            renderPanel();
            renderPrereqs();
            document.getElementById('tr-prereq-add').focus();
        }

        function closePanel() {
            editing = null;
            panel.hidden = true;
            renderPrereqs();
        }

        if (panel) {
            document.getElementById('tr-prereq-close').addEventListener('click', closePanel);
            document.getElementById('tr-prereq-cancel').addEventListener('click', closePanel);
            document.getElementById('tr-prereq-add-btn').addEventListener('click', function () {
                var sel = document.getElementById('tr-prereq-add');
                var id = Number(sel.value);
                var c = prereqs.find(function (x) { return x.course_id === id; });
                if (c) { editing.chosen.push({ course_id: c.course_id, name: c.name }); renderPanel(); }
            });
            document.getElementById('tr-prereq-save').addEventListener('click', function () {
                var btn = this;
                var errEl = document.getElementById('tr-prereq-error');
                errEl.textContent = '';
                btn.disabled = true;
                api.post('course_set_prereqs', { course_id: editing.course.course_id, requires: editing.chosen.map(function (c) { return c.course_id; }) })
                    .then(function () { return api.get('prereq_map'); })
                    .then(function (d) {
                        prereqs = d.courses || [];
                        ui.toast('Prerequisites saved.');
                        closePanel();
                    }, function (err) {
                        errEl.textContent = (err.fields && err.fields.requires) || err.message;
                    })
                    .then(function () { btn.disabled = false; });
            });
        }
        if (prereqSearch) { prereqSearch.addEventListener('input', ui.debounce(renderPrereqs, 200)); }
        if (prereqAll) { prereqAll.addEventListener('change', renderPrereqs); }

        renderGrid();
        renderPrereqs();
    });
})();
