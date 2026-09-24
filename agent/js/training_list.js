/*
 * Training › Courses (spec §5.2): the course list (cards or table), filters kept in the URL,
 * the stat strip, the "Start from a template" strip, the New course window and the category
 * manager. Page data comes from #tr-page-data; every string reaches the DOM through
 * textContent / DOM nodes (TrainingUi.el). TomSelect renders go through escape().
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        var canAuthor = !!data.can_author;
        var canFull = !!data.can_full;
        var previewAvailable = !!data.preview_available;
        var templates = Array.isArray(data.templates) ? data.templates : [];
        var categories = Array.isArray(data.categories) ? data.categories : [];
        var list = data.list || { courses: [], facets: {} };
        var courses = Array.isArray(list.courses) ? list.courses : [];
        var facets = list.facets || {};

        var results = document.getElementById('tr-results');
        var countEl = document.getElementById('tr-count');
        var qInput = document.getElementById('tr-q');
        var kindGroup = document.getElementById('tr-kind');
        var statusSel = document.getElementById('tr-status');
        var sortSel = document.getElementById('tr-sort');
        var mineInput = document.getElementById('tr-mine');
        var chipsHost = document.getElementById('tr-cat-chips');
        var tagsSel = document.getElementById('tr-tags');
        var viewGrid = document.getElementById('tr-view-grid');
        var viewList = document.getElementById('tr-view-list');

        var PREF_VIEW = 'tr-pref:courses-view';
        var PREF_TPL = 'tr-pref:hide-templates';
        var KIND_ICON = { training: 'graduation-cap', document: 'file-signature' };
        var TYPE_ICON = { article: 'file-alt', document: 'file-pdf', video: 'play-circle', image: 'image', quiz: 'question-circle', acknowledgment: 'file-signature' };

        // ------------------------------------------------------------------ small helpers
        function icon(name, extra) { return el('i', { class: 'fas fa-' + name + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function safeColor(c, fallback) { return (typeof c === 'string' && /^#[0-9A-Fa-f]{6}$/.test(c)) ? c : fallback; }
        function fmtMinutes(min) {
            min = Math.max(0, Math.round(Number(min) || 0));
            if (min === 0) { return ''; }
            if (min < 60) { return min + ' min'; }
            var h = Math.floor(min / 60);
            var m = min % 60;
            return h + ' h' + (m ? ' ' + m + ' min' : '');
        }
        function prefGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
        function prefSet(k, v) { try { if (v === null) { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) { /* storage blocked */ } }
        function absTime(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            return isNaN(d.getTime()) ? '' : d.toLocaleString();
        }
        function relTime(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            var s = Math.round((Date.now() - d.getTime()) / 1000);
            if (s < 45) { return 'just now'; }
            var m = Math.round(s / 60);
            if (m < 60) { return plural(m, 'minute', 'minutes') + ' ago'; }
            var h = Math.round(m / 60);
            if (h < 24) { return plural(h, 'hour', 'hours') + ' ago'; }
            var days = Math.round(h / 24);
            if (days < 30) { return days === 1 ? 'yesterday' : days + ' days ago'; }
            return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        }
        function builderUrl(c) { return '/agent/training_course.php?course_id=' + encodeURIComponent(c.id); }
        function previewUrl(c) {
            // The preview page shows the draft to authors and the published version to readers.
            return '/agent/training_preview.php?course_id=' + encodeURIComponent(c.id);
        }
        function openUrl(c) {
            if (canAuthor) { return builderUrl(c); }
            return previewAvailable ? previewUrl(c) : null;
        }
        function coverColor(c) {
            return safeColor(c.color, safeColor(c.category && c.category.color, c.kind === 'document' ? '#0891B2' : '#475569'));
        }
        function glyphIcon(c) {
            return (c.category && c.category.icon) || KIND_ICON[c.kind] || 'graduation-cap';
        }

        function statusBits(c) {
            var bits = [];
            if (c.status === 'archived') {
                bits.push(el('span', { class: 'tr-badge-status--archived' }, [icon('archive'), 'Archived']));
            } else if (c.status === 'published') {
                bits.push(el('span', { class: 'tr-badge-status--published' }, [icon('check'), 'Published']));
            } else {
                bits.push(el('span', { class: 'tr-badge-status--draft' }, [icon('pen'), 'Draft']));
            }
            if (c.current_revision_number) {
                bits.push(el('span', { text: 'Version ' + c.current_revision_number }));
            } else if (c.status !== 'archived') {
                bits.push(el('span', { text: 'Not published yet' }));
            }
            if (c.has_changes && c.status === 'published') {
                bits.push(el('span', { class: 'tr-badge-status--changes', title: 'The draft has changes that are not published yet' }, [icon('circle', 'fa-xs'), 'Unpublished changes']));
            }
            return bits;
        }

        function langDots(c) {
            var langs = Array.isArray(c.languages) ? c.languages : [];
            if (langs.length < 2) { return null; }
            return el('span', { class: 'tr-langs', 'aria-label': 'Languages: ' + langs.join(', ').toUpperCase() },
                langs.map(function (l) { return el('span', { class: 'tr-lang', text: l, title: l.toUpperCase() }); }));
        }

        function metaText(c) {
            var parts = [];
            if (c.kind === 'document') {
                parts.push('Document');
            } else {
                parts.push(plural(c.lessons_count || 0, 'lesson', 'lessons'));
            }
            var t = fmtMinutes(c.est_minutes);
            if (t) { parts.push(t); }
            if (c.has_exam) { parts.push('Exam'); }
            return parts.join(' · ');
        }

        // ------------------------------------------------------------------ actions menu
        function actionsMenu(c, extraClass) {
            var items = [];
            var open = openUrl(c);
            if (canAuthor && c.status !== 'archived') {
                items.push(el('li', {}, [el('a', { class: 'dropdown-item', href: builderUrl(c) }, [icon('edit', 'fa-fw me-2 text-muted'), 'Edit'])]));
            }
            if (previewAvailable && (canAuthor || c.current_revision_number)) {
                items.push(el('li', {}, [el('a', { class: 'dropdown-item', href: previewUrl(c), target: '_blank', rel: 'noopener' }, [icon('eye', 'fa-fw me-2 text-muted'), 'Preview'])]));
            }
            if (canAuthor && c.status !== 'archived') {
                items.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { duplicate(c); } } }, [icon('copy', 'fa-fw me-2 text-muted'), 'Duplicate'])]));
            }
            if (canFull) {
                if (items.length) { items.push(el('li', {}, [el('hr', { class: 'dropdown-divider' })])); }
                if (c.status === 'archived') {
                    items.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { restore(c); } } }, [icon('undo', 'fa-fw me-2 text-muted'), 'Restore'])]));
                } else {
                    items.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { archive(c); } } }, [icon('archive', 'fa-fw me-2 text-muted'), 'Archive'])]));
                }
                if (!c.current_revision_number) {
                    items.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { removeDraft(c); } } }, [icon('trash-alt', 'fa-fw me-2'), 'Delete draft'])]));
                }
            }
            if (!items.length && !open) { return null; }
            if (!items.length) { return null; }
            return el('div', { class: 'dropdown ' + (extraClass || '') }, [
                el('button', { type: 'button', class: 'tr-kebab', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'Actions for ' + c.name }, [icon('ellipsis-h')]),
                el('ul', { class: 'dropdown-menu dropdown-menu-end' }, items)
            ]);
        }

        // ------------------------------------------------------------------ card and row
        function coverNode(c) {
            var cover = el('div', { class: 'tr-course-card__cover' });
            cover.style.setProperty('--tr-cover', coverColor(c));
            if (c.cover_url) {
                cover.appendChild(el('img', { src: c.cover_url, alt: '', loading: 'lazy' }));
            } else {
                cover.appendChild(el('div', { class: 'tr-course-card__glyph', 'aria-hidden': 'true' }, [
                    icon(glyphIcon(c)),
                    c.code ? el('span', { class: 'tr-course-card__code', text: c.code }) : null
                ]));
            }
            var flags = el('div', { class: 'tr-course-card__flags' }, [
                el('span', { class: 'tr-course-card__flag' }, [icon(KIND_ICON[c.kind] || 'graduation-cap'), c.kind === 'document' ? 'Document' : 'Training'])
            ]);
            cover.appendChild(flags);
            return cover;
        }

        function card(c) {
            var open = openUrl(c);
            var title = el('h3', { class: 'tr-course-card__title' }, [
                open ? el('a', { href: open, text: c.name || 'Untitled course' }) : el('span', { text: c.name || 'Untitled course' })
            ]);
            var tagList = Array.isArray(c.tags) ? c.tags : [];
            var chips = [];
            if (c.category) {
                var catChip = el('span', { class: 'tr-chip tr-chip--dot' }, [c.category.name]);
                catChip.style.setProperty('--tr-chip-color', safeColor(c.category.color, '#64748b'));
                chips.push(catChip);
            }
            tagList.slice(0, 3).forEach(function (t) { chips.push(el('span', { class: 'tr-chip', text: t.name })); });
            if (tagList.length > 3) {
                chips.push(el('span', { class: 'tr-chip', text: '+' + (tagList.length - 3), title: tagList.slice(3).map(function (t) { return t.name; }).join(', ') }));
            }
            var menu = actionsMenu(c, 'tr-course-card__menu');
            var node = el('article', { class: 'tr-course-card tr-lift' + (c.status === 'archived' ? ' is-archived' : ''), dataset: { courseId: c.id } }, [
                coverNode(c),
                menu,
                el('div', { class: 'tr-course-card__body' }, [
                    el('div', { class: 'tr-course-card__status' }, statusBits(c)),
                    title,
                    c.summary ? el('p', { class: 'tr-course-card__summary', text: c.summary }) : null,
                    chips.length ? el('div', { class: 'tr-course-card__tags' }, chips) : null,
                    c.counts ? el('div', { class: 'tr-course-card__counts', text: countsText(c.counts) }) : null,
                    el('div', { class: 'tr-course-card__foot' }, [
                        el('span', { class: 'tr-course-card__meta', text: metaText(c) }),
                        langDots(c)
                    ])
                ])
            ]);
            return node;
        }

        function countsText(counts) {
            // Phase 2 fills this slot (assigned / completed); render whatever arrives, as text.
            if (!counts || typeof counts !== 'object') { return ''; }
            return Object.keys(counts).map(function (k) { return counts[k] + ' ' + k; }).join(' · ');
        }

        function tableRow(c) {
            var open = openUrl(c);
            var thumb = el('span', { class: 'tr-course-table__thumb', 'aria-hidden': 'true' }, [
                c.cover_url ? el('img', { src: c.cover_url, alt: '', loading: 'lazy' }) : icon(glyphIcon(c))
            ]);
            thumb.style.setProperty('--tr-cover', coverColor(c));
            var name = el('div', { class: 'tr-course-table__name' }, [
                thumb,
                el('div', { class: 'tr-min0' }, [
                    open ? el('a', { class: 'tr-course-table__title', href: open, text: c.name || 'Untitled course' }) : el('span', { class: 'tr-course-table__title', text: c.name || 'Untitled course' }),
                    el('div', { class: 'tr-course-table__sub' }, [
                        c.kind === 'document' ? 'Required document' : 'Training',
                        c.code ? el('span', { class: 'tr-mono' }, [' · ', c.code]) : null
                    ])
                ])
            ]);
            var cat = c.category ? el('span', { class: 'tr-chip tr-chip--dot' }, [c.category.name]) : el('span', { class: 'text-muted', text: '—' });
            if (c.category) { cat.style.setProperty('--tr-chip-color', safeColor(c.category.color, '#64748b')); }
            return el('tr', { dataset: { courseId: c.id } }, [
                el('td', {}, [name]),
                el('td', {}, [cat]),
                el('td', {}, [el('div', { class: 'd-flex flex-wrap align-items-center gap-1' }, statusBits(c))]),
                el('td', { class: 'text-end tr-mono', text: c.kind === 'document' ? '—' : String(c.lessons_count || 0) }),
                el('td', { class: 'text-nowrap', text: fmtMinutes(c.est_minutes) || '—' }),
                el('td', {}, [langDots(c) || el('span', { class: 'tr-lang', text: (c.languages && c.languages[0]) || 'en' })]),
                el('td', { class: 'text-nowrap text-muted', title: absTime(c.updated_at), text: relTime(c.updated_at) }),
                el('td', { class: 'text-end' }, [actionsMenu(c)])
            ]);
        }

        // ------------------------------------------------------------------ state
        var filters = Object.assign({ q: null, kind: null, status: null, category_id: null, tag_ids: [], mine: false, sort: null }, data.filters || {});
        var view = prefGet(PREF_VIEW) === 'list' ? 'list' : 'grid';
        var loadError = data.list_error || null;
        var reqSeq = 0;

        function hasFilters() {
            return !!(filters.q || filters.kind || (filters.status && filters.status !== 'active') || filters.category_id
                || (filters.tag_ids && filters.tag_ids.length) || filters.mine);
        }

        function syncUrl() {
            var p = new URLSearchParams();
            if (filters.q) { p.set('q', filters.q); }
            if (filters.kind) { p.set('kind', filters.kind); }
            if (filters.status && filters.status !== 'active') { p.set('status', filters.status); }
            if (filters.category_id) { p.set('category_id', String(filters.category_id)); }
            (filters.tag_ids || []).forEach(function (t) { p.append('tag_ids[]', String(t)); });
            if (filters.mine) { p.set('mine', '1'); }
            if (filters.sort && filters.sort !== 'updated') { p.set('sort', filters.sort); }
            var qs = p.toString();
            try { window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* ignore */ }
        }

        function render() {
            results.textContent = '';
            results.removeAttribute('aria-busy');
            var stats = facets.stats || {};
            countEl.textContent = plural(courses.length, 'course', 'courses');

            if (loadError) {
                results.appendChild(el('div', { class: 'alert alert-danger d-flex align-items-center gap-2', role: 'alert' }, [
                    icon('exclamation-triangle'), el('span', { class: 'me-auto', text: loadError }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: 'Try again', on: { click: function () { refresh(); } } })
                ]));
                return;
            }

            if (courses.length === 0) {
                if (!hasFilters() && (stats.total || 0) === 0 && (stats.archived || 0) === 0) {
                    results.appendChild(emptyFirst());
                } else {
                    results.appendChild(el('div', { class: 'tr-empty card' }, [
                        el('div', { class: 'tr-empty__icon', 'aria-hidden': 'true' }, [icon('search')]),
                        el('p', { class: 'tr-empty__title', text: 'No courses match' }),
                        el('p', { class: 'tr-empty__text', text: 'Try a different search, or clear the filters to see everything.' }),
                        el('div', { class: 'tr-empty__actions' }, [el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Clear filters', on: { click: clearFilters } })])
                    ]));
                }
                return;
            }

            if (view === 'list') {
                var tbody = el('tbody', {}, courses.map(tableRow));
                results.appendChild(el('div', { class: 'card' }, [el('div', { class: 'table-responsive' }, [
                    el('table', { class: 'table table-vcenter card-table tr-course-table' }, [
                        el('thead', {}, [el('tr', {}, [
                            el('th', { scope: 'col', text: 'Course' }),
                            el('th', { scope: 'col', text: 'Category' }),
                            el('th', { scope: 'col', text: 'Status' }),
                            el('th', { scope: 'col', class: 'text-end', text: 'Lessons' }),
                            el('th', { scope: 'col', text: 'Time' }),
                            el('th', { scope: 'col', text: 'Languages' }),
                            el('th', { scope: 'col', text: 'Updated' }),
                            el('th', { scope: 'col', class: 'w-1' }, [el('span', { class: 'visually-hidden', text: 'Actions' })])
                        ])]),
                        tbody
                    ])
                ])]));
            } else {
                results.appendChild(el('div', { class: 'tr-card-grid' }, courses.map(card)));
            }
        }

        function emptyFirst() {
            if (!canAuthor) {
                return el('div', { class: 'tr-empty card' }, [
                    el('div', { class: 'tr-empty__icon', 'aria-hidden': 'true' }, [icon('graduation-cap')]),
                    el('p', { class: 'tr-empty__title', text: 'No published courses yet' }),
                    el('p', { class: 'tr-empty__text', text: 'Courses appear here once an author publishes them.' })
                ]);
            }
            var tiles = templates.filter(function (t) { return t.group === 'template'; }).slice(0, 6).map(function (t) {
                var ic = el('span', { class: 'tr-tpl-card__icon', 'aria-hidden': 'true' }, [icon(t.icon || 'graduation-cap')]);
                ic.style.setProperty('--tr-tpl', safeColor(t.color, '#0D9488'));
                return el('button', { type: 'button', class: 'tr-tpl-card tr-lift', on: { click: function () { NewCourse.open(t.key); } } }, [
                    ic,
                    el('span', { class: 'tr-tpl-card__text' }, [
                        el('span', { class: 'tr-tpl-card__name', text: t.name.en }),
                        el('span', { class: 'tr-tpl-card__desc', text: t.description.en })
                    ])
                ]);
            });
            return el('div', { class: 'tr-empty card p-4' }, [
                el('div', { class: 'tr-empty__icon', 'aria-hidden': 'true' }, [icon('graduation-cap')]),
                el('p', { class: 'tr-empty__title', text: 'Create your first course' }),
                el('p', { class: 'tr-empty__text', text: 'Start from a template that already has the right steps, or from a blank course. Nothing is visible to employees until you publish.' }),
                el('div', { class: 'tr-tpl-grid text-start mb-3' }, tiles),
                el('div', { class: 'tr-empty__actions' }, [
                    el('button', { type: 'button', class: 'btn btn-primary', on: { click: function () { NewCourse.open('training'); } } }, [icon('plus', 'me-2'), 'Blank training course']),
                    el('button', { type: 'button', class: 'btn btn-outline-secondary', on: { click: function () { NewCourse.open('document'); } } }, [icon('file-signature', 'me-2'), 'Required document'])
                ])
            ]);
        }

        function updateStats() {
            var stats = facets.stats || {};
            document.querySelectorAll('[data-tr-stat]').forEach(function (box) {
                var v = box.querySelector('.it-stat-value');
                var key = box.getAttribute('data-tr-stat');
                if (v && Object.prototype.hasOwnProperty.call(stats, key)) { v.textContent = String(stats[key]); }
            });
        }

        function renderChips() {
            chipsHost.textContent = '';
            var cats = Array.isArray(facets.categories) ? facets.categories : [];
            var all = el('button', { type: 'button', class: 'tr-chip', 'aria-pressed': filters.category_id ? 'false' : 'true', text: 'All categories',
                on: { click: function () { setFilter('category_id', null); } } });
            chipsHost.appendChild(all);
            cats.forEach(function (c) {
                if (!c.count && filters.category_id !== c.id) { return; }
                var chip = el('button', { type: 'button', class: 'tr-chip tr-chip--dot tr-cat-chip', 'aria-pressed': filters.category_id === c.id ? 'true' : 'false',
                    on: { click: function () { setFilter('category_id', filters.category_id === c.id ? null : c.id); } } }, [
                    c.name, el('span', { class: 'tr-chip__count', text: String(c.count) })
                ]);
                chip.style.setProperty('--tr-chip-color', safeColor(c.color, '#64748b'));
                chipsHost.appendChild(chip);
            });
        }

        var tagSelect = null;
        function initTags() {
            if (!tagsSel || typeof window.TomSelect !== 'function') {
                if (tagsSel) { tagsSel.closest('.tr-tags-select').hidden = true; }
                return;
            }
            tagSelect = new window.TomSelect(tagsSel, {
                plugins: ['remove_button'],
                valueField: 'id',
                labelField: 'name',
                searchField: ['name'],
                options: (facets.tags || []).map(function (t) { return { id: String(t.id), name: t.name, count: t.count }; }),
                items: (filters.tag_ids || []).map(String),
                maxOptions: 300,
                hideSelected: true,
                placeholder: 'Filter by tag',
                render: {
                    option: function (d, escape) { return '<div>' + escape(d.name) + ' <span class="text-muted small">' + escape(String(d.count || 0)) + '</span></div>'; },
                    item: function (d, escape) { return '<div>' + escape(d.name) + '</div>'; },
                    no_results: function () { return '<div class="no-results">No tags</div>'; }
                },
                onChange: function (vals) {
                    var ids = (Array.isArray(vals) ? vals : String(vals || '').split(',')).map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; });
                    setFilter('tag_ids', ids);
                }
            });
            if (!(facets.tags || []).length) { tagsSel.closest('.tr-tags-select').hidden = true; }
        }
        function refreshTagOptions() {
            if (!tagSelect) { return; }
            var tags = facets.tags || [];
            tagsSel.closest('.tr-tags-select').hidden = tags.length === 0 && !(filters.tag_ids || []).length;
            tags.forEach(function (t) {
                var id = String(t.id);
                if (tagSelect.options[id]) { tagSelect.updateOption(id, { id: id, name: t.name, count: t.count }); } else { tagSelect.addOption({ id: id, name: t.name, count: t.count }); }
            });
        }

        // ------------------------------------------------------------------ fetching
        function params() {
            var p = {};
            if (filters.q) { p.q = filters.q; }
            if (filters.kind) { p.kind = filters.kind; }
            if (filters.status) { p.status = filters.status; }
            if (filters.category_id) { p.category_id = filters.category_id; }
            if (filters.tag_ids && filters.tag_ids.length) { p.tag_ids = filters.tag_ids; }
            if (filters.mine) { p.mine = true; }
            if (filters.sort) { p.sort = filters.sort; }
            return p;
        }

        function refresh() {
            var seq = ++reqSeq;
            results.setAttribute('aria-busy', 'true');
            return api.get('course_list', params()).then(function (d) {
                if (seq !== reqSeq) { return; }
                loadError = null;
                courses = Array.isArray(d.courses) ? d.courses : [];
                facets = d.facets || facets;
                updateStats();
                renderChips();
                refreshTagOptions();
                render();
            }, function (err) {
                if (seq !== reqSeq) { return; }
                loadError = (err && err.message) || 'The course list could not be loaded.';
                render();
            });
        }
        var refreshSoon = ui.debounce(refresh, 200);

        function setFilter(k, v) {
            filters[k] = v;
            syncUrl();
            if (k === 'category_id') { renderChips(); }
            refresh();
        }

        function clearFilters() {
            filters = { q: null, kind: null, status: null, category_id: null, tag_ids: [], mine: false, sort: filters.sort };
            qInput.value = '';
            setKindUi();
            if (statusSel) { statusSel.value = 'active'; }
            if (mineInput) { mineInput.checked = false; }
            if (tagSelect) { tagSelect.clear(true); }
            syncUrl();
            renderChips();
            refresh();
        }

        // ------------------------------------------------------------------ filter wiring
        qInput.addEventListener('input', function () {
            var v = qInput.value.trim();
            filters.q = v === '' ? null : v.slice(0, 100);
            syncUrl();
            refreshSoon();
        });
        qInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); refreshSoon.flush(); } });

        function setKindUi() {
            kindGroup.querySelectorAll('[role=radio]').forEach(function (b) {
                var on = (b.getAttribute('data-value') || null) === (filters.kind || null);
                b.setAttribute('aria-checked', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
            });
        }
        setKindUi();
        kindGroup.addEventListener('click', function (e) {
            var b = e.target.closest('[role=radio]');
            if (!b) { return; }
            setFilter('kind', b.getAttribute('data-value') || null);
            setKindUi();
        });
        kindGroup.addEventListener('keydown', function (e) {
            if (['ArrowLeft', 'ArrowRight'].indexOf(e.key) === -1) { return; }
            var btns = Array.prototype.slice.call(kindGroup.querySelectorAll('[role=radio]'));
            var i = btns.indexOf(document.activeElement);
            if (i === -1) { return; }
            e.preventDefault();
            var next = btns[(i + (e.key === 'ArrowRight' ? 1 : btns.length - 1)) % btns.length];
            next.focus();
            next.click();
        });
        if (statusSel) { statusSel.addEventListener('change', function () { setFilter('status', statusSel.value === 'active' ? null : statusSel.value); }); }
        sortSel.addEventListener('change', function () { setFilter('sort', sortSel.value === 'updated' ? null : sortSel.value); });
        if (mineInput) { mineInput.addEventListener('change', function () { setFilter('mine', mineInput.checked); }); }

        function setView(v) {
            view = v;
            prefSet(PREF_VIEW, v === 'list' ? 'list' : null);
            viewGrid.setAttribute('aria-pressed', v === 'grid' ? 'true' : 'false');
            viewList.setAttribute('aria-pressed', v === 'list' ? 'true' : 'false');
            render();
        }
        viewGrid.addEventListener('click', function () { setView('grid'); });
        viewList.addEventListener('click', function () { setView('list'); });
        viewGrid.setAttribute('aria-pressed', view === 'grid' ? 'true' : 'false');
        viewList.setAttribute('aria-pressed', view === 'list' ? 'true' : 'false');

        // Template strip: remembered "Hide templates".
        var strip = document.getElementById('tr-tpl-strip');
        if (strip) {
            if (prefGet(PREF_TPL) === '1') { strip.hidden = true; }
            document.getElementById('tr-tpl-hide').addEventListener('click', function () {
                strip.hidden = true;
                prefSet(PREF_TPL, '1');
                ui.toast('Templates hidden. They are still under New ▾ › From template…', { type: 'info' });
            });
            strip.addEventListener('click', function (e) {
                var b = e.target.closest('[data-tr-template]');
                if (b) { NewCourse.open(b.getAttribute('data-tr-template')); }
            });
        }

        document.querySelectorAll('[data-tr-new]').forEach(function (b) {
            b.addEventListener('click', function () {
                var mode = b.getAttribute('data-tr-new');
                if (mode === 'template' && strip && strip.hidden) { prefSet(PREF_TPL, null); strip.hidden = false; }
                NewCourse.open(mode);
            });
        });

        // ------------------------------------------------------------------ row actions
        function duplicate(c) {
            api.post('course_duplicate', { course_id: c.id }).then(function (d) {
                ui.toast('Copied "' + c.name + '".', { type: 'success', action: { label: 'Open copy', onClick: function () { window.location.href = d.url; } } });
                refresh();
            }, function (err) { ui.toast((err && err.message) || 'The course could not be copied.', { type: 'error' }); });
        }
        function archive(c) {
            ui.confirmBar(results, {
                message: 'Archive "' + c.name + '"? It leaves the list and becomes read-only. Published versions and records are kept.',
                confirmLabel: 'Archive', danger: true, prepend: true
            }).then(function (ok) {
                if (!ok) { return; }
                api.post('course_archive', { course_id: c.id }).then(function () {
                    ui.toast('"' + c.name + '" archived.', { type: 'success', action: { label: 'Undo', onClick: function () { restore(c); } } });
                    refresh();
                }, function (err) { ui.toast((err && err.message) || 'The course could not be archived.', { type: 'error' }); });
            });
        }
        function restore(c) {
            api.post('course_restore', { course_id: c.id }).then(function () {
                ui.toast('"' + c.name + '" restored.', { type: 'success' });
                refresh();
            }, function (err) { ui.toast((err && err.message) || 'The course could not be restored.', { type: 'error' }); });
        }
        function removeDraft(c) {
            ui.confirmBar(results, {
                message: 'Delete the draft "' + c.name + '" and everything in it? This cannot be undone.',
                confirmLabel: 'Delete draft', danger: true, prepend: true
            }).then(function (ok) {
                if (!ok) { return; }
                api.post('course_delete', { course_id: c.id }).then(function () {
                    ui.toast('Draft deleted.', { type: 'success' });
                    refresh();
                }, function (err) { ui.toast((err && err.message) || 'The draft could not be deleted.', { type: 'error' }); });
            });
        }

        // ================================================================== New course window
        var NewCourse = (function () {
            var modalEl = document.getElementById('tr-new-course');
            if (!modalEl || !window.bootstrap) { return { open: function () {} }; }
            var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            var cardsHost = document.getElementById('tr-nc-cards');
            var nameInput = document.getElementById('tr-nc-name');
            var nameErr = document.getElementById('tr-nc-name-error');
            var catSel = document.getElementById('tr-nc-category');
            var note = document.getElementById('tr-nc-note');
            var errEl = document.getElementById('tr-nc-error');
            var createBtn = document.getElementById('tr-nc-create');
            var rail = modalEl.querySelectorAll('.tr-nc__rail-btn');
            var group = 'scratch';
            var choice = { kind: 'training', template: null };
            var autoName = '';
            var busy = false;

            var SCRATCH = [
                { key: '__training', kind: 'training', icon: 'graduation-cap', color: '#2563EB', name: 'Training course',
                  description: 'Sections and lessons: articles, PDFs, videos, images, quizzes and sign-offs, with an optional final exam.' },
                { key: '__document', kind: 'document', icon: 'file-signature', color: '#0891B2', name: 'Required document',
                  description: 'One policy, SOP or handbook page that people read and sign. An optional short check.' }
            ];

            function fillCategories() {
                catSel.textContent = '';
                catSel.appendChild(el('option', { value: '', text: 'No category' }));
                categories.filter(function (c) { return !c.archived; }).forEach(function (c) {
                    catSel.appendChild(el('option', { value: String(c.id), text: c.name }));
                });
            }

            function categoryIdByName(name) {
                if (!name) { return ''; }
                var hit = categories.find(function (c) { return !c.archived && String(c.name).toLowerCase() === String(name).toLowerCase(); });
                return hit ? String(hit.id) : '';
            }

            function outline(t) {
                var items = [];
                (t.sections || []).forEach(function (s) {
                    items.push(el('li', {}, [el('b', { text: s.title.en })]));
                    (s.lessons || []).slice(0, 4).forEach(function (l) {
                        items.push(el('li', { title: (l.hint && l.hint.en) || '' }, [
                            el('span', { class: 'tr-type-icon tr-type-icon--sm tr-type--' + l.type, 'aria-hidden': 'true' }, [icon(TYPE_ICON[l.type] || 'file-alt')]),
                            el('span', { text: l.title.en })
                        ]));
                    });
                    if ((s.lessons || []).length > 4) { items.push(el('li', { text: '+ ' + ((s.lessons || []).length - 4) + ' more' })); }
                });
                return items.length ? el('ul', { class: 'tr-nc-card__outline', 'aria-label': 'Outline' }, items.slice(0, 12)) : null;
            }

            function cardFor(t, isScratch) {
                var selected = isScratch ? (choice.template === null && choice.kind === t.kind) : choice.template === t.key;
                var ic = el('span', { class: 'tr-tpl-card__icon', 'aria-hidden': 'true' }, [icon(t.icon || 'graduation-cap')]);
                ic.style.setProperty('--tr-tpl', safeColor(t.color, '#0D9488'));
                var btn = el('button', { type: 'button', class: 'tr-nc-card', role: 'radio', 'aria-checked': selected ? 'true' : 'false', tabindex: selected ? '0' : '-1',
                    dataset: { key: t.key } }, [
                    el('span', { class: 'tr-nc-card__check', 'aria-hidden': 'true' }, [icon('check')]),
                    el('span', { class: 'tr-nc-card__head' }, [ic, el('span', { class: 'tr-nc-card__name', text: isScratch ? t.name : t.name.en })]),
                    el('span', { class: 'tr-nc-card__desc', text: isScratch ? t.description : t.description.en }),
                    isScratch ? null : outline(t)
                ]);
                btn.addEventListener('click', function () { select(t, isScratch); });
                return btn;
            }

            function renderCards() {
                cardsHost.textContent = '';
                cardsHost.setAttribute('aria-labelledby', 'tr-nc-tab-' + group);
                var list;
                if (group === 'scratch') {
                    list = SCRATCH.map(function (t) { return cardFor(t, true); });
                } else {
                    list = templates.filter(function (t) { return t.group === group; }).map(function (t) { return cardFor(t, false); });
                }
                list.forEach(function (n) { cardsHost.appendChild(n); });
                if (!cardsHost.querySelector('[aria-checked="true"]') && cardsHost.firstChild) { cardsHost.firstChild.tabIndex = 0; }
            }

            function setGroup(g) {
                group = g;
                rail.forEach(function (b) {
                    var on = b.getAttribute('data-group') === g;
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                    b.tabIndex = on ? 0 : -1;
                });
                renderCards();
            }

            function select(t, isScratch) {
                errEl.textContent = '';
                if (isScratch) {
                    choice = { kind: t.kind, template: null };
                    if (nameInput.value.trim() === autoName) { nameInput.value = ''; autoName = ''; }
                    note.textContent = t.kind === 'document'
                        ? 'Creates the document step and the acknowledgment. Upload the PDF or write the text next.'
                        : '';
                } else {
                    choice = { kind: t.kind, template: t.key };
                    if (nameInput.value.trim() === '' || nameInput.value.trim() === autoName) {
                        nameInput.value = t.name.en;
                        autoName = t.name.en;
                    }
                    var cat = categoryIdByName(t.category);
                    if (cat) { catSel.value = cat; }
                    var bits = [];
                    var d = t.defaults || {};
                    if (d.regulation_ref) { bits.push('Suggested reference: ' + d.regulation_ref); }
                    if (d.validity_months) { bits.push('suggested validity ' + d.validity_months + ' months'); }
                    if (d.needs_practical) { bits.push('practical evaluation on'); }
                    var n = bits.length ? bits.join(' · ') + '. All editable in Settings; confirm with your Safety Manager.' : '';
                    if (t.notes && t.notes.en) { n = (n ? n + ' ' : '') + t.notes.en; }
                    note.textContent = n;
                }
                cardsHost.querySelectorAll('[role=radio]').forEach(function (b) {
                    var on = b.getAttribute('data-key') === t.key;
                    b.setAttribute('aria-checked', on ? 'true' : 'false');
                    b.tabIndex = on ? 0 : -1;
                });
            }

            /** Switching groups keeps the choice visible: pick the group's first card when the choice is elsewhere. */
            function setGroupAndChoose(g) {
                setGroup(g);
                if (!cardsHost.querySelector('[aria-checked="true"]')) {
                    if (g === 'scratch') { select(SCRATCH[0], true); return; }
                    var first = templates.find(function (t) { return t.group === g; });
                    if (first) { select(first, false); }
                }
            }
            rail.forEach(function (b) {
                b.addEventListener('click', function () { setGroupAndChoose(b.getAttribute('data-group')); });
                b.addEventListener('keydown', function (e) {
                    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
                    e.preventDefault();
                    var arr = Array.prototype.slice.call(rail);
                    var i = arr.indexOf(b);
                    var next = arr[(i + ((e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : arr.length - 1)) % arr.length];
                    next.focus();
                    setGroupAndChoose(next.getAttribute('data-group'));
                });
            });
            cardsHost.addEventListener('keydown', function (e) {
                if (['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) === -1) { return; }
                var arr = Array.prototype.slice.call(cardsHost.querySelectorAll('[role=radio]'));
                var i = arr.indexOf(document.activeElement);
                if (i === -1) { return; }
                e.preventDefault();
                var next = arr[(i + ((e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : arr.length - 1)) % arr.length];
                next.focus();
                next.click();
            });

            function create() {
                if (busy) { return; }
                errEl.textContent = '';
                nameInput.classList.remove('is-invalid');
                var name = nameInput.value.trim();
                if (name === '') {
                    nameInput.classList.add('is-invalid');
                    nameErr.textContent = 'Give the course a name.';
                    nameInput.focus();
                    return;
                }
                var langs = ['en'];
                modalEl.querySelectorAll('[data-tr-lang]').forEach(function (cb) { if (cb.checked) { langs.push(cb.getAttribute('data-tr-lang')); } });
                var body = { kind: choice.kind, name: name, languages: langs };
                if (catSel.value) { body.category_id = parseInt(catSel.value, 10); }
                if (choice.template) { body.template_key = choice.template; }
                busy = true;
                createBtn.disabled = true;
                createBtn.querySelector('.spinner-border').classList.remove('d-none');
                api.post('course_create', body).then(function (d) {
                    window.location.href = d.url;
                }, function (err) {
                    busy = false;
                    createBtn.disabled = false;
                    createBtn.querySelector('.spinner-border').classList.add('d-none');
                    var f = (err && err.fields) || {};
                    if (f.name) {
                        nameInput.classList.add('is-invalid');
                        nameErr.textContent = f.name;
                    }
                    errEl.textContent = f.template_key || f.languages || f.category_id || (err && err.message) || 'The course could not be created.';
                });
            }
            createBtn.addEventListener('click', create);
            document.getElementById('tr-nc-form').addEventListener('submit', function (e) { e.preventDefault(); create(); });
            nameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); create(); } });
            nameInput.addEventListener('input', function () { nameInput.classList.remove('is-invalid'); });
            modalEl.addEventListener('shown.bs.modal', function () { nameInput.focus(); if (nameInput.value) { nameInput.select(); } });

            fillCategories();

            return {
                open: function (mode) {
                    errEl.textContent = '';
                    nameInput.classList.remove('is-invalid');
                    nameInput.value = '';
                    autoName = '';
                    catSel.value = '';
                    note.textContent = '';
                    modalEl.querySelectorAll('[data-tr-lang]').forEach(function (cb) { cb.checked = false; });
                    var t = templates.find(function (x) { return x.key === mode; });
                    if (t) {
                        setGroup(t.group);
                        select(t, false);
                    } else if (mode === 'template') {
                        choice = { kind: 'training', template: null };
                        setGroupAndChoose('template');
                    } else {
                        setGroup('scratch');
                        select(SCRATCH[mode === 'document' ? 1 : 0], true);
                    }
                    modal.show();
                },
                refreshCategories: fillCategories
            };
        })();

        // ================================================================== Category manager
        (function () {
            var panel = document.getElementById('tr-cat-panel');
            var scrim = document.getElementById('tr-cat-scrim');
            var openBtn = document.getElementById('tr-cat-manage');
            if (!panel || !openBtn) { return; }
            var listEl = document.getElementById('tr-cat-list');
            var form = document.getElementById('tr-cat-form');
            var idInput = document.getElementById('tr-cat-id');
            var nameInput = document.getElementById('tr-cat-name');
            var colorInput = document.getElementById('tr-cat-color');
            var iconSel = document.getElementById('tr-cat-icon');
            var errEl = document.getElementById('tr-cat-error');
            var formTitle = document.getElementById('tr-cat-form-title');
            var lastFocus = null;

            (data.icons || []).forEach(function (i) { iconSel.appendChild(el('option', { value: i, text: i.replace(/-/g, ' ') })); });

            function rowFor(c) {
                var dot = el('span', { class: 'tr-type-icon tr-type-icon--sm', 'aria-hidden': 'true' }, [icon(c.icon || 'graduation-cap')]);
                dot.style.setProperty('--tr-type', safeColor(c.color, '#64748b'));
                return el('li', { class: 'd-flex align-items-center gap-2 p-2 border rounded-2' + (c.archived ? ' opacity-50' : '') }, [
                    dot,
                    el('span', { class: 'flex-fill text-truncate fw-medium', text: c.name }),
                    c.archived ? el('span', { class: 'tr-badge-status--archived', text: 'Archived' }) : null,
                    c.archived ? null : el('button', { type: 'button', class: 'tr-icon-btn', 'aria-label': 'Edit ' + c.name, title: 'Edit', on: { click: function () { edit(c); } } }, [icon('pen')]),
                    c.archived ? null : el('button', { type: 'button', class: 'tr-icon-btn', 'aria-label': 'Archive ' + c.name, title: 'Archive', on: { click: function () { archiveCat(c); } } }, [icon('archive')])
                ]);
            }
            function renderList() {
                listEl.textContent = '';
                categories.forEach(function (c) { listEl.appendChild(rowFor(c)); });
                if (!categories.length) { listEl.appendChild(el('li', { class: 'text-muted small', text: 'No categories yet.' })); }
            }
            function reset() {
                idInput.value = '';
                nameInput.value = '';
                colorInput.value = '#0D9488';
                iconSel.value = 'graduation-cap';
                errEl.textContent = '';
                formTitle.textContent = 'New category';
            }
            function edit(c) {
                idInput.value = String(c.id);
                nameInput.value = c.name;
                colorInput.value = safeColor(c.color, '#0D9488');
                iconSel.value = c.icon;
                errEl.textContent = '';
                formTitle.textContent = 'Edit "' + c.name + '"';
                nameInput.focus();
            }
            function reload() {
                return api.get('category_list', { include_archived: true }).then(function (d) {
                    categories = d.categories || [];
                    renderList();
                    NewCourse.refreshCategories();
                    refresh();
                });
            }
            function archiveCat(c) {
                ui.confirmBar(panel.querySelector('.tr-panel__body'), { message: 'Archive "' + c.name + '"? Courses keep it, but it is no longer offered.', confirmLabel: 'Archive', danger: true, prepend: true }).then(function (ok) {
                    if (!ok) { return; }
                    api.post('category_archive', { id: c.id }).then(reload, function (err) { errEl.textContent = (err && err.message) || 'Could not archive.'; });
                });
            }
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                errEl.textContent = '';
                var body = { name: nameInput.value.trim(), color: colorInput.value.toUpperCase(), icon: iconSel.value };
                if (idInput.value) { body.id = parseInt(idInput.value, 10); }
                if (!body.name) { errEl.textContent = 'Give the category a name.'; nameInput.focus(); return; }
                api.post('category_save', body).then(function () { reset(); reload(); ui.toast('Category saved.'); }, function (err) {
                    var f = (err && err.fields) || {};
                    errEl.textContent = f.name || f.color || f.icon || (err && err.message) || 'Could not save.';
                });
            });
            document.getElementById('tr-cat-reset').addEventListener('click', reset);
            function open() {
                lastFocus = document.activeElement;
                panel.hidden = false;
                scrim.hidden = false;
                renderList();
                reset();
                nameInput.focus();
            }
            function close() {
                panel.hidden = true;
                scrim.hidden = true;
                if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
            }
            openBtn.addEventListener('click', open);
            document.getElementById('tr-cat-close').addEventListener('click', close);
            scrim.addEventListener('click', close);
            panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
        })();

        // ------------------------------------------------------------------ boot
        renderChips();
        initTags();
        render();
    });
})();
