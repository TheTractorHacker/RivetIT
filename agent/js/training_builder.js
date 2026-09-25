/*
 * Training › Course builder (spec §5.3).
 *
 *   header    name (inline, autosaved), status / "Unpublished changes" / drift pills, Preview ▾,
 *             Publish (enabled from publish_check.has_changes), kebab
 *   Content   training kind: outline (sections, SortableJS drag-drop, inline rename, lesson rows,
 *             bulk upload with a tray) + sticky aside (course card, to-do, languages, versions,
 *             quick add); document kind: the three steps
 *   Settings  every field autosaves through the page's single course store (per-card state)
 *   Versions  draft diff + timeline (Preview, Compare to draft, Details)
 *
 * Data: #tr-page-data {course_id, level, can_full, user_id, limits, languages, default_language,
 * detail (CourseDetail), categories, tags, courses, flags}. Every string reaches the DOM through
 * textContent / DOM nodes; the only innerHTML sinks are server-purified statement/body HTML.
 */
(function () {
    'use strict';

    var TYPES = {
        article: { label: 'Article', icon: 'file-alt' },
        document: { label: 'Document', icon: 'file-pdf' },
        video: { label: 'Video', icon: 'play-circle' },
        image: { label: 'Image', icon: 'image' },
        quiz: { label: 'Quiz', icon: 'question-circle' },
        acknowledgment: { label: 'Acknowledgment', icon: 'file-signature' }
    };
    var LANG_NAMES = { en: 'English', es: 'Spanish' };
    var TRANSLATABLE = ['name', 'summary', 'description_html', 'attestation_text'];
    var CARD_OF = {
        name: 'basics', code: 'basics', summary: 'basics', description_html: 'basics', category_id: 'basics', cover_media_id: 'basics',
        responsible_user_id: 'basics', color: 'basics', sequential: 'flow', est_minutes: 'flow',
        requires_signature: 'completion', attestation_text: 'completion', validity_months: 'completion', renewal_lead_days: 'completion',
        is_qualification: 'completion', regulation_ref: 'completion', needs_online: 'completion', needs_session: 'completion',
        needs_practical: 'completion', external_only: 'completion', component_window_days: 'completion',
        allow_trainer_attest: 'completion', eval_checklist: 'completion'
    };

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        var up = window.TrainingUploader;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        var detail = data.detail || {};
        var course = detail.course || {};
        var courseId = data.course_id;
        var flags = data.flags || {};
        var canFull = !!data.can_full;
        var readOnly = !!course.archived;
        var root = document.getElementById('tr-builder');
        var isDoc = course.kind === 'document';
        var $ = function (id) { return document.getElementById(id); };

        // ---------------------------------------------------------------- helpers
        function icon(n, extra) { return el('i', { class: 'fas fa-' + n + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function has(o, k) { return Object.prototype.hasOwnProperty.call(o || {}, k); }
        function langName(l) { return LANG_NAMES[l] || String(l || '').toUpperCase(); }
        function safeColor(c, fb) { return (typeof c === 'string' && /^#[0-9A-Fa-f]{6}$/.test(c)) ? c : fb; }
        function fmtMin(min) {
            min = Math.max(0, Math.round(Number(min) || 0));
            if (!min) { return ''; }
            if (min < 60) { return min + ' min'; }
            var h = Math.floor(min / 60);
            var m = min % 60;
            return h + ' h' + (m ? ' ' + m + ' min' : '');
        }
        function fmtDate(iso, withTime) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            return withTime ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
                : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        }
        function prefGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
        function prefSet(k, v) { try { if (v === null) { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) { /* blocked */ } }
        function lessonById(id) { return (detail.lessons || []).find(function (l) { return l.id === id; }) || null; }
        function sectionById(id) { return (detail.sections || []).find(function (s) { return s.id === id; }) || null; }
        function coverColor() { return safeColor(course.color, safeColor(course.category && course.category.color, isDoc ? '#0891B2' : '#475569')); }
        function coverIcon() { return (course.category && course.category.icon) || (isDoc ? 'file-signature' : 'graduation-cap'); }
        /** Every cover box: the course colour, and a soft wash of it behind a cover image (.tr-cover-art; gallery art is transparent). */
        function paintCover(node) {
            if (!node) { return; }
            node.style.setProperty('--tr-cover', coverColor());
            node.style.setProperty('--tr-cover-color', coverColor());
            node.classList.toggle('tr-cover-art', !!course.cover_url);
        }
        var picker = !readOnly && window.TrainingCoverPicker ? window.TrainingCoverPicker : null;
        function mediaIdFromUrl(url) { var m = /[?&]m=(\d+)/.exec(url || ''); return m ? parseInt(m[1], 10) : null; }
        function htmlText(html) {
            if (!html) { return ''; }
            try { return (new DOMParser().parseFromString(String(html), 'text/html').body.textContent || '').replace(/\s+/g, ' ').trim(); } catch (e) { return ''; }
        }
        function sameList(a, b) { return JSON.stringify(a || []) === JSON.stringify(b || []); }

        // ================================================================ course store
        function courseSnap(c) {
            var s = {};
            Object.keys(CARD_OF).forEach(function (k) { s[k] = c[k] === undefined ? null : c[k]; });
            var i18n = c.i18n || {};
            Object.keys(i18n).forEach(function (l) {
                TRANSLATABLE.forEach(function (f) { if (has(i18n[l], f)) { s[l + ':' + f] = i18n[l][f]; } });
            });
            return s;
        }
        function sendCourse(patch, version) {
            var groups = {};
            Object.keys(patch).forEach(function (k) {
                var i = k.indexOf(':');
                var lang = i === -1 ? '' : k.slice(0, i);
                var f = i === -1 ? k : k.slice(i + 1);
                (groups[lang] = groups[lang] || {})[f] = patch[k];
            });
            var langs = Object.keys(groups).sort(function (a, b) { return a === '' ? -1 : (b === '' ? 1 : a.localeCompare(b)); });
            var v = version;
            var last = null;
            var warnings = [];
            return langs.reduce(function (p, lang) {
                return p.then(function () {
                    var body = { course_id: courseId, version: v, fields: groups[lang] };
                    if (lang) { body.lang = lang; }
                    return api.post('course_update', body).then(function (r) {
                        last = r;
                        v = r.version;
                        warnings = warnings.concat(r.warnings || []);
                    });
                });
            }, Promise.resolve()).then(function () {
                last.warnings = warnings;
                return last;
            });
        }

        var activeCards = {};
        var store = window.TrainingStore.entity('course', courseId, {
            version: course.version,
            base: courseSnap(course),
            send: sendCourse,
            versionOf: function (r) { return r && r.version; },
            baseOf: function (r) { return courseSnap(r.course); },
            snapshotOf: function (cur) { return courseSnap(cur); },
            onState: onCourseState,
            onSaved: function (r, patch) {
                course = Object.assign({}, course, r.course);
                detail.course = course;
                (r.warnings || []).forEach(function (w) { if (w && w.message) { ui.toast(w.message, { type: 'warning' }); } });
                Object.keys(patch).forEach(function (k) { markCard(fieldCard(k), 'saved'); clearFieldError(k); });
                renderHeader();
                renderAside();
                if (has(patch, 'est_minutes')) { renderEstimate(); }
                scheduleCheck();
            },
            onError: function (err, patch) {
                var fields = (err && err.fields) || {};
                Object.keys(patch).forEach(function (k) {
                    var f = k.indexOf(':') === -1 ? k : k.slice(k.indexOf(':') + 1);
                    markCard(fieldCard(k), 'error', fields[f] || (err && err.message));
                    showFieldError(f, fields[f] || (Object.keys(fields).length ? '' : (err && err.message) || ''));
                });
            },
            onConflict: function () { /* onState shows it */ }
        });

        function fieldCard(k) { var f = k.indexOf(':') === -1 ? k : k.slice(k.indexOf(':') + 1); return CARD_OF[f] || 'basics'; }
        function cardStateEl(card) {
            var c = document.querySelector('[data-tr-card="' + card + '"]');
            return c ? c.querySelector('.tr-save-state') : null;
        }
        function markCard(card, state, msg) {
            var s = cardStateEl(card);
            if (state === 'saving' || state === 'offline' || state === 'conflict') { activeCards[card] = true; }
            if (state === 'saved') { delete activeCards[card]; }
            if (!s) { return; }
            s.setAttribute('data-state', state);
            s.textContent = '';
            if (state === 'saving') { s.textContent = 'Saving…'; }
            if (state === 'saved') { s.textContent = 'Saved'; }
            if (state === 'offline') { s.textContent = 'Offline, retrying'; }
            if (state === 'error') { s.textContent = msg || 'Not saved'; }
            if (state === 'conflict') {
                s.appendChild(document.createTextNode('Changed in another tab. '));
                s.appendChild(el('button', { type: 'button', text: 'Reload', on: { click: function () { window.location.reload(); } } }));
            }
        }
        function onCourseState(state, info) {
            var h = $('tr-head-save');
            h.setAttribute('data-state', state);
            h.textContent = '';
            if (state === 'saving') { h.textContent = 'Saving…'; }
            else if (state === 'saved') { h.textContent = 'All changes saved'; }
            else if (state === 'offline') { h.textContent = 'Offline. Changes will be retried.'; }
            else if (state === 'error') { h.textContent = (info && info.error && info.error.message) || 'Some changes were not saved'; }
            else if (state === 'conflict') {
                h.appendChild(document.createTextNode('Changed in another tab. '));
                h.appendChild(el('button', { type: 'button', text: 'Reload', on: { click: function () { window.location.reload(); } } }));
            }
            if (state === 'saving' || state === 'offline' || state === 'conflict') {
                Object.keys(activeCards).forEach(function (c) { markCard(c, state); });
            }
        }
        function setCourseField(key, value, delay) {
            if (readOnly) { return; }
            markCard(fieldCard(key), 'saving');
            store.set(key, value, delay || 0);
        }
        function fieldKey(f, lang) { return (!lang || lang === course.default_language || TRANSLATABLE.indexOf(f) === -1) ? f : lang + ':' + f; }
        function courseValue(f, lang) {
            var key = fieldKey(f, lang);
            if (store.isFieldDirty(key)) {
                if (has(store.pending, key)) { return store.pending[key]; }
                if (store.inflight && has(store.inflight, key)) { return store.inflight[key]; }
            }
            if (key.indexOf(':') !== -1) {
                var l = key.slice(0, key.indexOf(':'));
                return ((course.i18n || {})[l] || {})[f] === undefined ? null : course.i18n[l][f];
            }
            return course[f] === undefined ? null : course[f];
        }
        function showFieldError(f, msg) {
            document.querySelectorAll('[data-tr-error="' + f + '"]').forEach(function (n) { n.textContent = msg || ''; });
            if (f === 'name') { $('tr-course-name-error').textContent = msg || ''; }
        }
        function clearFieldError(k) { showFieldError(k.indexOf(':') === -1 ? k : k.slice(k.indexOf(':') + 1), ''); }

        // ================================================================ header
        var check = null;       // last publish_check (network=0)
        var drift = null;       // last course_drift
        function hasChanges() { return check ? !!check.has_changes : !!course.has_changes; }

        function renderHeader() {
            var nameInput = $('tr-course-name');
            if (document.activeElement !== nameInput && !store.isFieldDirty('name')) { nameInput.value = course.name || ''; sizeName(); }
            $('tr-crumb-name').textContent = course.name || 'Untitled course';
            $('tr-course-h1').textContent = course.name || 'Untitled course';
            document.title = (course.name || 'Course') + ' - Training';
            var cov = $('tr-head-cover');
            cov.textContent = '';
            paintCover(cov);
            if (course.cover_url) { cov.appendChild(el('img', { src: course.cover_url, alt: '' })); } else { cov.appendChild(icon(coverIcon())); }

            var badges = $('tr-head-badges');
            badges.textContent = '';
            var rev = course.current_revision;
            if (course.archived) {
                badges.appendChild(el('span', { class: 'tr-badge-status--archived' }, [icon('archive'), 'Archived']));
            } else if (rev) {
                badges.appendChild(el('span', { class: 'tr-badge-status--published' }, [icon('check'), 'Published · Version ' + rev.number]));
            } else {
                badges.appendChild(el('span', { class: 'tr-badge-status--draft' }, [icon('pen'), 'Draft']));
            }
            if (rev && hasChanges() && !course.archived) {
                badges.appendChild(el('span', { class: 'tr-badge-status--changes' }, [icon('circle', 'fa-xs'), 'Unpublished changes']));
            }
            if (isDoc) { badges.appendChild(el('span', { class: 'tr-chip' }, [icon('file-signature'), 'Required document'])); }

            var live = $('tr-head-live');
            if (rev) {
                live.textContent = hasChanges() ? 'Learners still see Version ' + rev.number + ' until you publish.' : 'Version ' + rev.number + ' published ' + fmtDate(rev.published_at) + '.';
            } else {
                live.textContent = 'Not published yet. Nothing is visible to employees.';
            }
            renderDrift();
            renderPublishButton();
            renderPreviewMenu();
        }

        function renderDrift() {
            var host = $('tr-head-drift');
            host.textContent = '';
            if (!drift) { return; }
            var kb = (drift.kb || []).filter(function (k) { return k.draft_drift; });
            var banks = (drift.banks || []).filter(function (b) { return b.drift; });
            var vids = (drift.videos || []).filter(function (v) { return v.problem; });
            if (kb.length) {
                host.appendChild(el('button', { type: 'button', class: 'tr-drift', title: 'A Knowledge Base article changed after it was imported', on: { click: function () {
                    openContent({ lessonId: kb[0].lesson_id, lang: kb[0].lang });
                } } }, [icon('book'), kb.length === 1 ? 'KB changed' : kb.length + ' KB articles changed']));
            }
            if (banks.length) {
                host.appendChild(el('span', { class: 'tr-drift', title: 'Questions in a bank this course draws from changed since the last version' }, [icon('layer-group'), banks.length === 1 ? 'Question bank changed' : banks.length + ' question banks changed']));
            }
            if (vids.length) {
                host.appendChild(el('button', { type: 'button', class: 'tr-drift', title: 'A video needs to be checked by playing it', on: { click: function () {
                    var l = vids[0].lesson_ids && vids[0].lesson_ids[0];
                    if (l) { openContent({ lessonId: l }); }
                } } }, [icon('play-circle'), vids.length === 1 ? 'Video needs a check' : vids.length + ' videos need a check']));
            }
        }

        var publishTip = null;
        function renderPublishButton() {
            var btn = $('tr-publish-btn');
            if (!btn) { return; }
            var wrap = $('tr-publish-wrap');
            var enabled = !readOnly && hasChanges();
            btn.disabled = !enabled;
            var tip = '';
            if (!enabled && course.current_revision) { tip = 'No changes since Version ' + course.current_revision.number; }
            if (!check && !course.current_revision) { tip = ''; btn.disabled = false; }
            wrap.setAttribute('title', tip);
            wrap.tabIndex = tip ? 0 : -1;
            if (window.bootstrap && window.bootstrap.Tooltip) {
                if (publishTip) { publishTip.dispose(); publishTip = null; }
                if (tip) { publishTip = new window.bootstrap.Tooltip(wrap, { title: tip, placement: 'bottom' }); }
            }
            var draftPub = $('tr-draft-publish');
            if (draftPub) { draftPub.hidden = !enabled; }
        }

        function renderPreviewMenu() {
            var menu = $('tr-preview-menu');
            if (!menu) { return; }
            menu.textContent = '';
            menu.appendChild(el('li', {}, [el('a', { class: 'dropdown-item', href: '/agent/training_preview.php?course_id=' + courseId, target: '_blank', rel: 'noopener' }, [icon('pen', 'fa-fw me-2 text-muted'), 'Preview the draft'])]));
            if (course.current_revision) {
                menu.appendChild(el('li', {}, [el('a', { class: 'dropdown-item', href: '/agent/training_preview.php?course_id=' + courseId + '&revision_id=' + course.current_revision.id, target: '_blank', rel: 'noopener' }, [icon('check', 'fa-fw me-2 text-muted'), 'Preview Version ' + course.current_revision.number])]));
            }
        }

        var nameInput = $('tr-course-name');
        function sizeName() { nameInput.size = Math.max(8, Math.min(60, (nameInput.value || '').length + 1)); }
        sizeName();
        nameInput.addEventListener('input', sizeName);
        nameInput.addEventListener('input', function () {
            var v = nameInput.value.trim();
            if (v === '') { showFieldError('name', 'Give the course a name.'); return; }
            showFieldError('name', '');
            setCourseField('name', v, 800);
            var setName = $('tr-set-name');
            if (setName && settingsLang === course.default_language && document.activeElement !== setName) { setName.value = v; }
        });
        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); nameInput.blur(); store.flush(); }
            if (e.key === 'Escape') { e.preventDefault(); nameInput.value = course.name || ''; showFieldError('name', ''); nameInput.blur(); }
        });
        nameInput.addEventListener('blur', function () {
            if (nameInput.value.trim() === '') { nameInput.value = course.name || ''; showFieldError('name', ''); }
        });

        // ================================================================ tabs + hash
        var tabs = { content: $('tr-tab-content'), settings: $('tr-tab-settings'), versions: $('tr-tab-versions') };
        function showTab(name) {
            var b = tabs[name];
            if (b && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(b).show(); }
        }
        $('tr-tabs').addEventListener('shown.bs.tab', function (e) {
            var h = e.target.getAttribute('data-hash');
            try { window.history.replaceState(null, '', window.location.pathname + window.location.search + (h === 'content' ? '' : '#' + h)); } catch (x) { /* ignore */ }
            if (h === 'settings') { initSettingsEditors(); }
            if (h === 'versions') { loadVersions(); }
        });
        document.querySelectorAll('[data-tr-goto]').forEach(function (b) {
            b.addEventListener('click', function () { showTab(b.getAttribute('data-tr-goto')); });
        });

        // ================================================================ content window
        var modalBusy = false;
        function sectionsForModal() { return (detail.sections || []).map(function (s) { return { id: s.id, title: s.title }; }); }
        function openContent(o) {
            if (readOnly) { ui.toast('This course is archived. Restore it to edit.', { type: 'info' }); return; }
            if (!window.TrainingContentModal || modalBusy) { return; }
            modalBusy = true;
            window.TrainingContentModal.open(Object.assign({
                courseId: courseId, course: course, sections: sectionsForModal(), lang: course.default_language
            }, o || {})).then(function (res) {
                modalBusy = false;
                res = res || {};
                var after = Promise.resolve();
                if (res.changed) { after = reloadDetail(); }
                after.then(function () {
                    if (res.deleted && res.lessonId) { undoDeleteToast(res.lessonId); }
                    if (res.again) { openContent({ sectionId: res.again.sectionId, type: res.again.type }); }
                });
            });
        }

        function reloadDetail() {
            return api.get('course_get', { course_id: courseId }).then(function (d) {
                detail = d;
                if (!store.isDirty()) {
                    course = d.course;
                    store.reset(course.version, courseSnap(course));
                } else {
                    course = Object.assign({}, d.course, { name: course.name });
                }
                detail.course = course;
                renderAll();
                scheduleCheck(true);
                loadDrift();
                if (versionsLoaded) { versionsLoaded = false; if (currentTab() === 'versions') { loadVersions(); } }
            }, function (err) {
                ui.toast((err && err.message) || 'The course could not be refreshed.', { type: 'error' });
            });
        }
        function currentTab() {
            var a = document.querySelector('#tr-tabs .nav-link.active');
            return a ? a.getAttribute('data-hash') : 'content';
        }

        function undoDeleteToast(lessonId) {
            ui.toast('Lesson deleted.', {
                type: 'success', delay: 10000,
                action: { label: 'Undo', onClick: function () {
                    api.post('lesson_restore', { lesson_id: lessonId }).then(function () { reloadDetail(); ui.toast('Lesson restored.'); }, function (err) {
                        ui.toast((err && err.message) || 'Could not restore the lesson.', { type: 'error' });
                    });
                } }
            });
        }

        // ================================================================ outline (training kind)
        var outline = $('tr-outline');
        var collapsedKey = 'tr-pref:collapsed:' + courseId;
        var collapsed = {};
        try { (JSON.parse(prefGet(collapsedKey) || '[]') || []).forEach(function (id) { collapsed[id] = true; }); } catch (e) { collapsed = {}; }
        function saveCollapsed() { prefSet(collapsedKey, JSON.stringify(Object.keys(collapsed).map(Number))); }
        var sortables = [];

        function lessonSub(l) {
            var parts = [TYPES[l.type] ? TYPES[l.type].label : l.type];
            if (l.type === 'video' && l.video_provider) { parts.push({ youtube: 'YouTube', vimeo: 'Vimeo', upload: 'Uploaded' }[l.video_provider] || l.video_provider); }
            if (l.duration_s) { parts.push(ui.fmtDuration(l.duration_s)); }
            var qs = (detail.quiz_summaries || {})[l.id];
            if (l.type === 'quiz' && l.quiz) {
                parts.push(plural(l.quiz.question_count || 0, 'question', 'questions'));
                if (qs && qs.must_pass) { parts.push('pass ' + qs.pass_pct + '%'); }
                if (qs && qs.max_attempts) { parts.push(plural(qs.max_attempts, 'try', 'tries')); }
            } else if (l.quiz) {
                parts.push('Quick check · ' + (l.quiz.question_count || 0) + ' Qs');
            }
            if (l.resources_count) { parts.push(plural(l.resources_count, 'resource', 'resources')); }
            return parts.join(' · ');
        }

        function issueMenu(l) {
            var issues = l.issues || [];
            if (!issues.length) {
                return el('span', { class: 'tr-lesson__ready', title: 'Ready', 'aria-label': 'Ready' }, [icon('check-circle')]);
            }
            var menu = el('div', { class: 'dropdown-menu dropdown-menu-end tr-issue-menu' }, [el('h6', { class: 'dropdown-header', text: 'To finish' })].concat(issues.map(function (i) {
                var resumable = i.code === 'pdf_pages_pending';
                return el('button', { type: 'button', class: 'dropdown-item tr-issue--todo', on: { click: function () {
                    if (resumable) { resumeLessonPages(l); return; }
                    openContent({ lessonId: l.id, lang: i.lang || undefined, tab: tabForIssue(i) });
                } } }, [el('span', { class: 'tr-issue__text' }, [
                    el('span', { text: i.message || i.code }),
                    resumable ? el('span', { class: 'tr-issue__where', text: 'Resume preparing the pages' }) : (i.lang ? el('span', { class: 'tr-issue__where', text: langName(i.lang) }) : null)
                ])]);
            })));
            return el('div', { class: 'dropdown tr-lesson__issues' }, [
                el('button', { type: 'button', class: 'tr-issue-btn', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': plural(issues.length, 'thing', 'things') + ' to finish in ' + (l.title || 'this lesson') },
                    [icon('list-ul'), String(issues.length)]),
                menu
            ]);
        }
        function tabForIssue(i) { return i.code === 'quiz_empty' ? 'content' : null; }

        function langDots(l) {
            var langs = course.languages || [];
            if (langs.length < 2) { return null; }
            return el('span', { class: 'tr-langs' }, langs.map(function (lg) {
                var ok = l.languages && l.languages[lg] && l.languages[lg].complete;
                return el('span', { class: 'tr-lang', 'data-state': ok ? 'complete' : 'missing', title: langName(lg) + (ok ? ': complete' : ': not finished'), text: lg });
            }));
        }

        function lessonRow(l, groupLessons, sectionIdx) {
            var idx = groupLessons.indexOf(l.id);
            var title = l.title || '';
            var qs = (detail.quiz_summaries || {})[l.id];
            var badges = [];
            if (l.quiz && l.quiz.role === 'exam') { badges.push(el('span', { class: 'tr-lesson__badge tr-lesson__badge--exam', text: 'Final exam' })); }
            if (!l.required) { badges.push(el('span', { class: 'tr-lesson__badge', text: 'Optional' })); }
            if (l.preview_enabled) { badges.push(el('span', { class: 'tr-lesson__badge tr-lesson__badge--preview', title: 'Learners can open this before starting the course', text: 'Open without starting' })); }
            void qs;
            var menuItems = [
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { openContent({ lessonId: l.id }); } } }, [icon('pen', 'fa-fw me-2 text-muted'), 'Edit'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { duplicateLesson(l); } } }, [icon('copy', 'fa-fw me-2 text-muted'), 'Duplicate'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', disabled: idx <= 0 && sectionIdx <= 0, on: { click: function () { moveLesson(l, -1); } } }, [icon('arrow-up', 'fa-fw me-2 text-muted'), 'Move up'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { moveLesson(l, 1); } } }, [icon('arrow-down', 'fa-fw me-2 text-muted'), 'Move down'])])
            ];
            var others = (detail.sections || []).filter(function (s) { return s.id !== l.section_id; });
            if (others.length || l.section_id) {
                menuItems.push(el('li', {}, [el('hr', { class: 'dropdown-divider' })]));
                menuItems.push(el('li', {}, [el('h6', { class: 'dropdown-header', text: 'Move to section' })]));
                others.forEach(function (s) {
                    menuItems.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item text-truncate', on: { click: function () { moveLessonTo(l, s.id); } } }, [icon('level-down-alt', 'fa-fw me-2 text-muted'), s.title || 'Untitled section'])]));
                });
                if (l.section_id) {
                    menuItems.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { moveLessonTo(l, null); } } }, [icon('level-down-alt', 'fa-fw me-2 text-muted'), 'No section'])]));
                }
            }
            menuItems.push(el('li', {}, [el('hr', { class: 'dropdown-divider' })]));
            menuItems.push(el('li', {}, [el('a', { class: 'dropdown-item', href: '/agent/training_preview.php?course_id=' + courseId + '&lesson=' + encodeURIComponent(l.uid), target: '_blank', rel: 'noopener' }, [icon('eye', 'fa-fw me-2 text-muted'), 'Preview as learner'])]));
            menuItems.push(el('li', {}, [el('hr', { class: 'dropdown-divider' })]));
            menuItems.push(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { deleteLesson(l); } } }, [icon('trash-alt', 'fa-fw me-2'), 'Delete'])]));

            var titleBtn = el('button', { type: 'button', class: 'tr-lesson__title btn btn-link p-0 text-start text-decoration-none d-block w-100' + (title ? '' : ' is-untitled'), text: title || 'Untitled ' + (TYPES[l.type] ? TYPES[l.type].label.toLowerCase() : 'content'),
                on: { click: function (e) { e.stopPropagation(); openContent({ lessonId: l.id }); } } });
            var row = el('li', { class: 'tr-lesson', dataset: { lessonId: l.id } }, [
                readOnly ? null : el('button', { type: 'button', class: 'tr-handle tr-lesson-handle', 'aria-label': 'Drag to reorder ' + (title || 'lesson'), title: 'Drag to reorder' }, [icon('grip-vertical')]),
                el('span', { class: 'tr-type-icon tr-type--' + l.type, 'aria-hidden': 'true' }, [icon(l.type === 'video' && l.video_provider === 'youtube' ? 'play-circle' : TYPES[l.type].icon)]),
                el('div', { class: 'tr-lesson__main' }, [titleBtn, el('div', { class: 'tr-lesson__sub', text: lessonSub(l) })]),
                badges.length ? el('div', { class: 'tr-lesson__badges' }, badges) : null,
                langDots(l),
                readOnly ? null : issueMenu(l),
                readOnly ? null : el('div', { class: 'tr-lesson__actions tr-hover-actions' }, [
                    el('button', { type: 'button', class: 'tr-icon-btn', 'aria-label': 'Edit ' + (title || 'lesson'), title: 'Edit', on: { click: function (e) { e.stopPropagation(); openContent({ lessonId: l.id }); } } }, [icon('pen')]),
                    el('div', { class: 'dropdown' }, [
                        el('button', { type: 'button', class: 'tr-kebab', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'More actions for ' + (title || 'lesson') }, [icon('ellipsis-v')]),
                        el('ul', { class: 'dropdown-menu dropdown-menu-end' }, menuItems)
                    ])
                ])
            ]);
            row.addEventListener('click', function (e) {
                if (readOnly || e.target.closest('button, a, input, .dropdown-menu')) { return; }
                openContent({ lessonId: l.id });
            });
            return row;
        }

        function sectionCard(s, n) {
            var lessons = (s.lesson_ids || []).map(lessonById).filter(Boolean);
            var mins = Math.ceil(lessons.reduce(function (a, l) { return a + (l.duration_s || 0); }, 0) / 60);
            var isCollapsed = !!collapsed[s.id];
            var titleInput = el('input', { type: 'text', class: 'tr-inline-input tr-section__title', value: s.title || '', maxlength: '200', 'aria-label': 'Section ' + n + ' title', placeholder: 'Section title', disabled: readOnly });
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); titleInput.blur(); }
                if (e.key === 'Escape') { e.preventDefault(); titleInput.value = s.title || ''; titleInput.dataset.cancel = '1'; titleInput.blur(); }
            });
            titleInput.addEventListener('blur', function () {
                if (titleInput.dataset.cancel) { delete titleInput.dataset.cancel; return; }
                var v = titleInput.value.trim();
                if (!v) { titleInput.value = s.title || ''; return; }
                if (v === s.title) { return; }
                api.post('section_update', { section_id: s.id, title: v }).then(function (r) {
                    s.title = r.title;
                    markOutlineSaved();
                    scheduleCheck();
                }, function (err) {
                    titleInput.value = s.title || '';
                    ui.toast((err && ((err.fields && err.fields.title) || err.message)) || 'The section could not be renamed.', { type: 'error' });
                });
            });
            var chevBtn = el('button', { type: 'button', class: 'tr-icon-btn', 'aria-expanded': isCollapsed ? 'false' : 'true', 'aria-label': (isCollapsed ? 'Expand' : 'Collapse') + ' section ' + n, title: isCollapsed ? 'Expand' : 'Collapse' }, [icon('chevron-down', 'tr-section__chev')]);
            var card;
            chevBtn.addEventListener('click', function () {
                var c = !card.classList.contains('is-collapsed');
                card.classList.toggle('is-collapsed', c);
                chevBtn.setAttribute('aria-expanded', c ? 'false' : 'true');
                chevBtn.setAttribute('aria-label', (c ? 'Expand' : 'Collapse') + ' section ' + n);
                if (c) { collapsed[s.id] = true; } else { delete collapsed[s.id]; }
                saveCollapsed();
                renderCollapseAll();
            });
            var list = el('ul', { class: 'tr-section__lessons', dataset: { sectionId: s.id }, 'aria-label': 'Lessons in section ' + n });
            lessons.forEach(function (l) { list.appendChild(lessonRow(l, s.lesson_ids, n - 1)); });
            var idx = n - 1;
            var menu = [
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { openContent({ sectionId: s.id }); } } }, [icon('plus', 'fa-fw me-2 text-muted'), 'Add content'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { pickFiles(s.id); } } }, [icon('upload', 'fa-fw me-2 text-muted'), 'Upload files…'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { titleInput.focus(); titleInput.select(); } } }, [icon('i-cursor', 'fa-fw me-2 text-muted'), 'Rename'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', disabled: idx === 0, on: { click: function () { moveSection(s, -1); } } }, [icon('arrow-up', 'fa-fw me-2 text-muted'), 'Move up'])]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', disabled: idx === detail.sections.length - 1, on: { click: function () { moveSection(s, 1); } } }, [icon('arrow-down', 'fa-fw me-2 text-muted'), 'Move down'])]),
                el('li', {}, [el('hr', { class: 'dropdown-divider' })]),
                el('li', {}, [el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { deleteSection(s, card); } } }, [icon('trash-alt', 'fa-fw me-2'), 'Delete'])])
            ];
            card = el('section', { class: 'tr-section tr-section--sortable' + (isCollapsed ? ' is-collapsed' : ''), dataset: { sectionId: s.id }, 'aria-label': 'Section ' + n + ': ' + (s.title || '') }, [
                el('div', { class: 'tr-section__head' }, [
                    readOnly ? null : el('button', { type: 'button', class: 'tr-handle tr-section-handle', 'aria-label': 'Drag to reorder section ' + n, title: 'Drag to reorder' }, [icon('grip-vertical')]),
                    el('span', { class: 'tr-section__label', text: 'Section ' + n }),
                    titleInput,
                    el('span', { class: 'tr-section__meta', text: [plural(lessons.length, 'lesson', 'lessons'), fmtMin(mins)].filter(Boolean).join(' · ') }),
                    chevBtn,
                    readOnly ? null : el('div', { class: 'dropdown' }, [
                        el('button', { type: 'button', class: 'tr-kebab', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'Actions for section ' + n }, [icon('ellipsis-v')]),
                        el('ul', { class: 'dropdown-menu dropdown-menu-end' }, menu)
                    ])
                ]),
                el('div', { class: 'tr-section-confirm' }),
                el('div', { class: 'tr-section__body' }, [list]),
                readOnly ? null : el('div', { class: 'tr-section__foot' }, [
                    el('button', { type: 'button', class: 'tr-add-btn', on: { click: function () { openContent({ sectionId: s.id }); } } }, [icon('plus'), 'Add content']),
                    el('button', { type: 'button', class: 'tr-add-btn tr-add-btn--quiet', on: { click: function () { pickFiles(s.id); } } }, [icon('upload'), 'Upload files…'])
                ]),
                el('div', { class: 'tr-drop-overlay', 'aria-hidden': 'true' }, [el('span', {}, [icon('cloud-upload-alt', 'me-2'), 'Drop files to add them to this section'])])
            ]);
            if (!readOnly && up) { wireSectionDrop(card, s.id); }
            return card;
        }

        function unsectionedCard(ids) {
            var list = el('ul', { class: 'tr-section__lessons', dataset: { sectionId: '' }, 'aria-label': 'Content without a section' });
            ids.map(lessonById).filter(Boolean).forEach(function (l) { list.appendChild(lessonRow(l, ids, 0)); });
            return el('section', { class: 'tr-section tr-section--unsectioned', 'aria-label': 'Not in a section' }, [
                el('div', { class: 'tr-section__head' }, [
                    el('span', { class: 'tr-section__label ps-2', text: 'Not in a section' }),
                    el('span', { class: 'tr-section__title-static text-muted small fw-normal', text: detail.sections.length ? 'Drag these into a section, or leave them here.' : '' })
                ]),
                el('div', { class: 'tr-section__body' }, [list]),
                readOnly ? null : el('div', { class: 'tr-section__foot' }, [
                    el('button', { type: 'button', class: 'tr-add-btn', on: { click: function () { openContent({ sectionId: null }); } } }, [icon('plus'), 'Add content'])
                ])
            ]);
        }

        function emptyHero() {
            var tiles = Object.keys(TYPES).map(function (t) {
                return el('button', { type: 'button', class: 'tr-type-tile', on: { click: function () { openContent({ type: t, sectionId: null }); } } }, [
                    el('span', { class: 'tr-type-icon tr-type--' + t, 'aria-hidden': 'true' }, [icon(TYPES[t].icon)]),
                    el('span', {}, [el('span', { class: 'tr-type-tile__name', text: TYPES[t].label }), el('span', { class: 'tr-type-tile__hint', text: { article: 'Text and pictures', document: 'A PDF, page by page', video: 'MP4, YouTube or Vimeo', image: 'Photo or diagram', quiz: 'Graded questions', acknowledgment: 'Read and sign' }[t] })])
                ]);
            });
            var drop = el('div', { class: 'tr-drop', id: 'tr-hero-drop' }, [
                el('span', { class: 'tr-drop__icon', 'aria-hidden': 'true' }, [icon('cloud-upload-alt')]),
                el('span', { class: 'tr-drop__title' }, ['Drop PDFs, videos, images or Word files here, or ', el('button', { type: 'button', class: 'tr-drop__browse', 'data-tr-upload-button': '', text: 'choose files' })]),
                el('span', { class: 'tr-drop__hint', text: 'Each file becomes a lesson. Titles come from the file names.' })
            ]);
            var hero = el('div', { class: 'tr-hero tr-empty' }, [
                el('div', {}, [el('h2', { class: 'tr-hero__title', text: 'Add the first content' }), el('p', { class: 'tr-hero__text', text: 'Pick a type, or drop files to create several lessons at once. Sections are optional; add them when the course grows.' })]),
                el('div', { class: 'tr-type-grid' }, tiles),
                drop,
                el('div', { class: 'd-flex flex-wrap gap-2' }, [
                    el('button', { type: 'button', class: 'btn btn-outline-secondary', on: { click: function () { pickTarget = 'ask'; fileInput.click(); } } }, [icon('upload', 'me-2'), 'Upload files…']),
                    el('button', { type: 'button', class: 'btn btn-link', on: { click: function () { addSection(); } } }, [icon('plus', 'me-2'), 'Add a section first'])
                ])
            ]);
            if (up) {
                up.dropzone(drop, { multiple: true, onFiles: function (files) { bulkUpload(files, 'ask'); } });
            }
            return hero;
        }

        function renderOutline() {
            if (isDoc || !outline) { return; }
            sortables.forEach(function (s) { try { s.destroy(); } catch (e) { /* ignore */ } });
            sortables = [];
            outline.textContent = '';
            outline.removeAttribute('aria-busy');
            var sections = detail.sections || [];
            var unsectioned = detail.unsectioned_lesson_ids || [];
            var lessons = detail.lessons || [];
            if (!sections.length && !lessons.length) {
                outline.appendChild(emptyHero());
            } else {
                if (unsectioned.length) { outline.appendChild(unsectionedCard(unsectioned)); }
                sections.forEach(function (s, i) { outline.appendChild(sectionCard(s, i + 1)); });
            }
            var exam = lessons.some(function (l) { return l.quiz && l.quiz.role === 'exam'; });
            var nonExam = lessons.filter(function (l) { return !(l.quiz && l.quiz.role === 'exam'); }).length;
            var bits = [];
            if (sections.length) { bits.push(plural(sections.length, 'section', 'sections')); }
            bits.push(plural(nonExam, 'lesson', 'lessons') + (exam ? ' and a final exam' : ''));
            var est = course.est_minutes_effective || course.est_minutes_auto;
            if (est) { bits.push('about ' + fmtMin(est)); }
            $('tr-outline-summary').textContent = lessons.length || sections.length ? bits.join(' · ') : '';
            $('tr-add-section').hidden = readOnly || (!sections.length && !lessons.length);
            $('tr-bulk-upload').hidden = readOnly || (!sections.length && !lessons.length);
            renderCollapseAll();
            if (!readOnly) { initSortables(); }
        }

        function renderCollapseAll() {
            var btn = $('tr-collapse-all');
            if (!btn) { return; }
            var sections = detail.sections || [];
            btn.hidden = sections.length < 2;
            var all = sections.length && sections.every(function (s) { return collapsed[s.id]; });
            btn.querySelector('span').textContent = all ? 'Expand all' : 'Collapse all';
            btn.querySelector('i').className = 'fas fa-angle-double-' + (all ? 'down' : 'up') + ' me-1';
        }
        if ($('tr-collapse-all')) {
            $('tr-collapse-all').addEventListener('click', function () {
                var sections = detail.sections || [];
                var all = sections.every(function (s) { return collapsed[s.id]; });
                collapsed = {};
                if (!all) { sections.forEach(function (s) { collapsed[s.id] = true; }); }
                saveCollapsed();
                renderOutline();
            });
        }

        // ---- drag and drop
        function initSortables() {
            if (!window.Sortable) { return; }
            sortables.push(window.Sortable.create(outline, {
                handle: '.tr-section-handle', draggable: '.tr-section--sortable', animation: 150,
                forceFallback: true, fallbackClass: 'tr-sort-fallback', fallbackOnBody: true,
                onEnd: function (e) { if (e.oldIndex !== e.newIndex) { queueReorder(); } }
            }));
            outline.querySelectorAll('.tr-section__lessons').forEach(function (list) {
                sortables.push(window.Sortable.create(list, {
                    group: 'tr-lessons', handle: '.tr-lesson-handle', draggable: '.tr-lesson', animation: 150,
                    forceFallback: true, fallbackClass: 'tr-sort-fallback', fallbackOnBody: true,
                    onEnd: function (e) { if (e.from !== e.to || e.oldIndex !== e.newIndex) { queueReorder(); } }
                }));
            });
        }

        function outlineFromDom() {
            var sections = [];
            var unsectioned = [];
            outline.querySelectorAll(':scope > .tr-section').forEach(function (card) {
                var list = card.querySelector('.tr-section__lessons');
                var ids = Array.prototype.map.call(list.querySelectorAll(':scope > .tr-lesson'), function (li) { return parseInt(li.getAttribute('data-lesson-id'), 10); });
                if (card.classList.contains('tr-section--unsectioned')) { unsectioned = ids; } else { sections.push({ section_id: parseInt(card.getAttribute('data-section-id'), 10), lesson_ids: ids }); }
            });
            return { sections: sections, unsectioned: unsectioned };
        }

        var reorderSeq = 0;
        var sendReorder = ui.debounce(function () {
            var o = outlineFromDom();
            var my = ++reorderSeq;
            var prev = { sections: detail.sections.map(function (s) { return Object.assign({}, s, { lesson_ids: s.lesson_ids.slice() }); }), unsectioned: detail.unsectioned_lesson_ids.slice() };
            applyOutline(o);
            api.post('outline_reorder', { course_id: courseId, sections: o.sections, unsectioned: o.unsectioned }).then(function () {
                if (my !== reorderSeq) { return; }
                markOutlineSaved();
                scheduleCheck();
            }, function (err) {
                if (my !== reorderSeq) { return; }
                detail.sections = prev.sections;
                detail.unsectioned_lesson_ids = prev.unsectioned;
                renderOutline();
                ui.toast((err && err.code === 'busy') ? 'Someone else is saving this course. Try again.' : ((err && err.message) || 'The new order could not be saved.'), { type: 'error' });
            });
        }, 300);
        function queueReorder() { sendReorder(); }

        /** Mirrors a DOM order into detail (sections order, lesson membership). */
        function applyOutline(o) {
            var byId = {};
            detail.sections.forEach(function (s) { byId[s.id] = s; });
            detail.sections = o.sections.map(function (x) {
                var s = byId[x.section_id];
                s.lesson_ids = x.lesson_ids;
                return s;
            });
            detail.unsectioned_lesson_ids = o.unsectioned;
            detail.sections.forEach(function (s) { s.lesson_ids.forEach(function (id) { var l = lessonById(id); if (l) { l.section_id = s.id; } }); });
            o.unsectioned.forEach(function (id) { var l = lessonById(id); if (l) { l.section_id = null; } });
        }
        function markOutlineSaved() {
            var h = $('tr-head-save');
            if (!store.isDirty()) { h.setAttribute('data-state', 'saved'); h.textContent = 'All changes saved'; }
        }

        function moveSection(s, dir) {
            var arr = detail.sections;
            var i = arr.indexOf(s);
            var j = i + dir;
            if (j < 0 || j >= arr.length) { return; }
            arr.splice(i, 1);
            arr.splice(j, 0, s);
            renderOutline();
            sendReorder();
            var card = outline.querySelector('.tr-section[data-section-id="' + s.id + '"] .tr-section__title');
            if (card) { card.focus(); }
        }

        function moveLesson(l, dir) {
            var groups = [{ id: null, ids: detail.unsectioned_lesson_ids }].concat(detail.sections.map(function (s) { return { id: s.id, ids: s.lesson_ids }; }));
            var gi = groups.findIndex(function (g) { return g.ids.indexOf(l.id) !== -1; });
            if (gi === -1) { return; }
            var g = groups[gi];
            var i = g.ids.indexOf(l.id);
            var j = i + dir;
            if (j >= 0 && j < g.ids.length) {
                g.ids.splice(i, 1);
                g.ids.splice(j, 0, l.id);
            } else {
                var ng = groups[gi + dir];
                if (!ng) { return; }
                g.ids.splice(i, 1);
                if (dir < 0) { ng.ids.push(l.id); } else { ng.ids.unshift(l.id); }
                l.section_id = ng.id;
            }
            renderOutline();
            sendReorder();
            var row = outline.querySelector('.tr-lesson[data-lesson-id="' + l.id + '"] .tr-lesson__title');
            if (row) { row.focus(); }
        }

        function moveLessonTo(l, sectionId) {
            var from = l.section_id ? sectionById(l.section_id) : null;
            var fromIds = from ? from.lesson_ids : detail.unsectioned_lesson_ids;
            fromIds.splice(fromIds.indexOf(l.id), 1);
            var to = sectionId ? sectionById(sectionId) : null;
            (to ? to.lesson_ids : detail.unsectioned_lesson_ids).push(l.id);
            l.section_id = sectionId;
            renderOutline();
            sendReorder();
            ui.toast('Moved to ' + (to ? '"' + (to.title || 'Untitled section') + '"' : 'no section') + '.');
        }

        function duplicateLesson(l) {
            api.post('lesson_duplicate', { lesson_id: l.id }).then(function () {
                ui.toast('Duplicated "' + (l.title || 'lesson') + '".');
                reloadDetail();
            }, function (err) { ui.toast((err && err.message) || 'Could not duplicate.', { type: 'error' }); });
        }

        function deleteLesson(l) {
            var row = outline ? outline.querySelector('.tr-lesson[data-lesson-id="' + l.id + '"]') : null;
            if (row) { row.classList.add('is-deleting'); }
            api.post('lesson_delete', { lesson_id: l.id }).then(function () {
                undoDeleteToast(l.id);
                reloadDetail();
            }, function (err) {
                if (row) { row.classList.remove('is-deleting'); }
                ui.toast((err && err.message) || 'Could not delete.', { type: 'error' });
            });
        }

        function deleteSection(s, card) {
            var host = card.querySelector('.tr-section-confirm');
            host.textContent = '';
            var n = (s.lesson_ids || []).length;
            if (n === 0) {
                api.post('section_delete', { section_id: s.id, mode: 'move' }).then(function () { ui.toast('Section deleted.'); reloadDetail(); }, function (err) {
                    ui.toast((err && err.message) || 'Could not delete the section.', { type: 'error' });
                });
                return;
            }
            var i = detail.sections.indexOf(s);
            var target = i > 0 ? detail.sections[i - 1] : (detail.sections[i + 1] || null);
            var keepLabel = target ? 'Keep lessons (move to "' + (target.title || 'Untitled section') + '")' : 'Keep lessons (no section)';
            var bar = el('div', { class: 'tr-confirm-bar alert alert-danger', role: 'alertdialog', 'aria-label': 'Delete section' }, [
                el('span', { class: 'me-auto', text: 'Delete "' + (s.title || 'this section') + '"?' }),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel', on: { click: function () { host.textContent = ''; } } }),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark', text: keepLabel, on: { click: function () { go('move'); } } }),
                el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Delete section and its ' + plural(n, 'lesson', 'lessons'), on: { click: function () { go('delete_lessons'); } } })
            ]);
            function go(mode) {
                var body = { section_id: s.id, mode: mode };
                if (mode === 'move' && target) { body.target_section_id = target.id; }
                bar.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                api.post('section_delete', body).then(function () {
                    ui.toast(mode === 'move' ? 'Section deleted; its lessons were kept.' : 'Section and its lessons deleted.');
                    reloadDetail();
                }, function (err) {
                    host.textContent = '';
                    ui.toast((err && err.message) || 'Could not delete the section.', { type: 'error' });
                });
            }
            host.appendChild(bar);
            bar.querySelector('.btn-outline-dark').focus();
        }

        function addSection() {
            {
                var last = detail.sections.length ? detail.sections[detail.sections.length - 1] : null;
                var body = { course_id: courseId, title: 'Section ' + (detail.sections.length + 1) };
                if (last) { body.after_section_id = last.id; }
                api.post('section_create', body).then(function (s) {
                    reloadDetail().then(function () {
                        var inp = outline.querySelector('.tr-section[data-section-id="' + s.id + '"] .tr-section__title');
                        if (inp) { inp.focus(); inp.select(); inp.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                    });
                }, function (err) { ui.toast((err && err.message) || 'Could not add a section.', { type: 'error' }); });
            }
        }
        if ($('tr-add-section')) { $('tr-add-section').addEventListener('click', addSection); }

        // ================================================================ bulk upload + tray
        var tray = $('tr-tray');
        var trayList = $('tr-tray-list');
        var trayItems = [];
        var trayRunning = false;
        var fileInput = el('input', { type: 'file', multiple: true, hidden: true, tabindex: '-1', 'aria-hidden': 'true',
            accept: 'application/pdf,.pdf,video/mp4,video/quicktime,.mp4,.m4v,.mov,image/jpeg,image/png,image/webp,image/gif,.docx' });
        document.body.appendChild(fileInput);
        var pickTarget = null;
        function pickFiles(sectionId) { pickTarget = sectionId; fileInput.click(); }
        fileInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(fileInput.files || []);
            fileInput.value = '';
            if (files.length) { bulkUpload(files, pickTarget); }
        });
        if ($('tr-bulk-upload')) {
            $('tr-bulk-upload').addEventListener('click', function () {
                var last = detail.sections.length ? detail.sections[detail.sections.length - 1].id : null;
                pickFiles(last);
            });
        }

        function wireSectionDrop(card, sectionId) {
            var depth = 0;
            function hasFiles(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
            card.addEventListener('dragenter', function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); depth++; card.classList.add('is-over'); });
            card.addEventListener('dragover', function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
            card.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { card.classList.remove('is-over'); } });
            card.addEventListener('drop', function (e) {
                if (!hasFiles(e)) { return; }
                e.preventDefault();
                depth = 0;
                card.classList.remove('is-over');
                bulkUpload(Array.prototype.slice.call(e.dataTransfer.files), sectionId);
            });
        }

        /** target: a section id, null (no section) or 'ask' (empty course: one section per file or all in one). */
        function bulkUpload(files, target) {
            if (!up || readOnly || !files.length) { return; }
            if (target === 'ask') {
                if (files.length === 1) { return enqueue(files, [null]); }
                var host = $('tr-outline-confirm');
                host.textContent = '';
                var bar = el('div', { class: 'tr-confirm-bar alert alert-info', role: 'alertdialog', 'aria-label': 'How to add the files' }, [
                    el('span', { class: 'me-auto', text: 'Add ' + plural(files.length, 'file', 'files') + ':' }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel', on: { click: function () { host.textContent = ''; } } }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', text: 'One section per file', on: { click: function () { host.textContent = ''; perFileSections(files); } } }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'All in one section', on: { click: function () { host.textContent = ''; oneSection(files); } } })
                ]);
                host.appendChild(bar);
                bar.querySelector('.btn-primary').focus();
                return;
            }
            enqueue(files, files.map(function () { return target; }));
        }
        function oneSection(files) {
            api.post('section_create', { course_id: courseId, title: 'Section 1' }).then(function (s) {
                enqueue(files, files.map(function () { return s.id; }));
            }, function (err) { ui.toast((err && err.message) || 'Could not add a section.', { type: 'error' }); });
        }
        function perFileSections(files) {
            var ids = [];
            files.reduce(function (p, f, i) {
                return p.then(function () {
                    return api.post('section_create', { course_id: courseId, title: up.titleFromName(f.name) || ('Section ' + (i + 1)) }).then(function (s) { ids.push(s.id); });
                });
            }, Promise.resolve()).then(function () { enqueue(files, ids); }, function (err) {
                ui.toast((err && err.message) || 'Could not add the sections.', { type: 'error' });
                reloadDetail();
            });
        }

        function enqueue(files, sectionIds) {
            files.forEach(function (f, i) {
                var inf = up.inferPurpose(f);
                var item = { file: f, sectionId: sectionIds[i] === undefined ? null : sectionIds[i], purpose: inf.purpose, state: inf.purpose ? 'queued' : 'skipped', message: inf.message || '', ctrl: null, lessonId: null };
                trayItems.push(item);
                renderTrayItem(item);
            });
            tray.hidden = false;
            updateTrayTitle();
            runTray();
        }

        function renderTrayItem(item) {
            var li = item.node || el('li', { class: 'tr-tray__item' });
            item.node = li;
            li.textContent = '';
            li.className = 'tr-tray__item' + (item.state === 'error' || item.state === 'skipped' ? ' is-error' : '') + (item.state === 'done' ? ' is-done' : '');
            var type = item.purpose ? { lesson_document: 'document', lesson_video: 'video', lesson_image: 'image', docx_import: 'article' }[item.purpose] : null;
            li.appendChild(el('span', { class: 'tr-type-icon tr-type-icon--sm ' + (type ? 'tr-type--' + type : ''), 'aria-hidden': 'true' }, [icon(type ? TYPES[type].icon : 'ban')]));
            li.appendChild(el('span', { class: 'tr-tray__name', title: item.file.name, text: item.file.name }));
            var actions = el('span', { class: 'tr-tray__actions' });
            if (item.state === 'uploading' || item.state === 'queued') {
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-link text-muted', text: 'Cancel', on: { click: function () { cancelItem(item); } } }));
            }
            if (item.state === 'error' && item.code === 'session') {
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-link', text: 'Reload', on: { click: function () { window.location.reload(); } } }));
            }
            if (item.state === 'error') {
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-link', text: 'Retry', on: { click: function () { item.state = 'queued'; item.message = ''; renderTrayItem(item); runTray(); } } }));
            }
            if (item.state === 'error' || item.state === 'skipped' || item.state === 'done' || item.state === 'cancelled') {
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-link text-muted', text: 'Remove', on: { click: function () { removeItem(item); } } }));
            }
            if (item.state === 'done' && item.lessonId) {
                actions.insertBefore(el('button', { type: 'button', class: 'btn btn-link', text: 'Open', on: { click: function () { openContent({ lessonId: item.lessonId }); } } }), actions.firstChild);
            }
            li.appendChild(actions);
            if (item.state === 'uploading') {
                var bar = el('div', { class: 'tr-progress' + (item.fraction === undefined ? ' is-indeterminate' : '') }, [el('div', { class: 'tr-progress__bar' })]);
                if (item.fraction !== undefined) { bar.firstChild.style.width = Math.round(item.fraction * 100) + '%'; }
                li.appendChild(bar);
            }
            var stage = { queued: 'Waiting…', uploading: item.message || 'Uploading…', done: item.message || 'Added', error: item.message || 'Failed', skipped: item.message || 'Not supported', cancelled: 'Cancelled' }[item.state];
            li.appendChild(el('span', { class: 'tr-tray__stage', text: stage }));
            if (!li.parentNode) { trayList.appendChild(li); }
        }
        /** Keeps toasts above the open tray (both sit bottom-right). */
        function positionToasts() {
            var h = tray.hidden ? 0 : tray.offsetHeight + 16;
            document.body.classList.toggle('tr-has-tray', h > 0);
            document.body.style.setProperty('--tr-tray-offset', h + 'px');
        }
        function updateTrayTitle() {
            setTimeout(positionToasts, 0);
            var active = trayItems.filter(function (i) { return i.state === 'queued' || i.state === 'uploading'; }).length;
            var done = trayItems.filter(function (i) { return i.state === 'done'; }).length;
            $('tr-tray-title').textContent = active ? 'Adding ' + plural(active, 'file', 'files') + '…' : (done ? plural(done, 'file', 'files') + ' added' : 'Uploads');
            $('tr-tray-close').disabled = active > 0;
        }
        function cancelItem(item) {
            if (item.ctrl) { item.ctrl.abort(); }
            item.state = 'cancelled';
            renderTrayItem(item);
            updateTrayTitle();
        }
        function removeItem(item) {
            trayItems.splice(trayItems.indexOf(item), 1);
            if (item.node && item.node.parentNode) { item.node.parentNode.removeChild(item.node); }
            if (!trayItems.length) { tray.hidden = true; }
            updateTrayTitle();
        }
        $('tr-tray-close').addEventListener('click', function () {
            trayItems.slice().forEach(function (i) { if (i.state !== 'queued' && i.state !== 'uploading') { removeItem(i); } });
            tray.hidden = !trayItems.length;
        });

        function runTray() {
            if (trayRunning) { return; }
            var item = trayItems.find(function (i) { return i.state === 'queued'; });
            if (!item) {
                updateTrayTitle();
                if (trayItems.some(function (i) { return i.state === 'done' && !i.reloaded; })) {
                    trayItems.forEach(function (i) { i.reloaded = true; });
                    reloadDetail();
                }
                return;
            }
            trayRunning = true;
            item.state = 'uploading';
            item.ctrl = new AbortController();
            renderTrayItem(item);
            updateTrayTitle();
            processItem(item).then(function () {
                item.state = 'done';
            }, function (err) {
                if (item.state === 'cancelled' || (err && err.code === 'aborted')) { item.state = 'cancelled'; } else {
                    item.state = 'error';
                    item.code = err && err.code;
                    item.message = err && err.code === 'session' ? 'Your session needs a refresh.' : ((err && err.message) || 'Failed.');
                }
            }).then(function () {
                trayRunning = false;
                renderTrayItem(item);
                updateTrayTitle();
                runTray();
            });
        }

        function processItem(item) {
            var type = { lesson_document: 'document', lesson_video: 'video', lesson_image: 'image', docx_import: 'article' }[item.purpose];
            var title = up.titleFromName(item.file.name);
            var lang = course.default_language;
            return up.upload(item.file, {
                purpose: item.purpose, courseId: courseId, lang: lang, waitForPages: false, signal: item.ctrl.signal,
                onProgress: function (p) { item.fraction = p.fraction; item.message = 'Uploading ' + Math.round(p.fraction * 100) + '%'; renderTrayItem(item); },
                onStage: function (s) { if (s && s.text && s.stage !== 'done') { item.message = s.text; item.fraction = undefined; renderTrayItem(item); } }
            }).then(function (d) {
                var body = { course_id: courseId, type: type, lang: lang, title: title };
                if (item.sectionId) { body.section_id = item.sectionId; }
                if (d.media) { body.media_id = d.media.id; }
                item.message = 'Creating the lesson…';
                renderTrayItem(item);
                return api.post('lesson_create', body).then(function (lesson) {
                    item.lessonId = lesson.id;
                    if (type === 'article' && d.html) {
                        return api.post('lesson_update', { lesson_id: lesson.id, version: lesson.version, lang: lang, fields: { body_html: d.html } }).then(function (r) {
                            (r.warnings || []).concat(d.warnings || []).forEach(function (w) { ui.toast(typeof w === 'string' ? w : (w.message || ''), { type: 'warning' }); });
                        });
                    }
                    if (type === 'document' && d.media && d.done === false) {
                        return up.renderPages(d.media.id, {
                            signal: item.ctrl.signal,
                            onStage: function (s) { if (s.total) { item.fraction = (s.ready || 0) / s.total; item.message = 'Preparing pages ' + (s.ready || 0) + '/' + s.total; renderTrayItem(item); } }
                        }).then(null, function (err) {
                            // The lesson exists; rendering resumes from the builder or the lesson window.
                            if (err && err.code === 'aborted') { throw err; }
                            item.message = 'Added. ' + ((err && err.message) || 'Some pages are still being prepared.');
                        });
                    }
                    return null;
                }).then(function () {
                    if (!item.message || item.message.indexOf('Added') !== 0) { item.message = 'Added to ' + (item.sectionId && sectionById(item.sectionId) ? '"' + sectionById(item.sectionId).title + '"' : 'the course'); }
                });
            });
        }

        /** Resumes page rendering for a PDF lesson (issue pdf_pages_pending). */
        var resuming = {};
        function resumeLessonPages(l) {
            if (!up || resuming[l.id]) { return; }
            resuming[l.id] = true;
            api.get('lesson_get', { lesson_id: l.id }).then(function (d) {
                var v = d.variants && d.variants[course.default_language];
                var langs = Object.keys(d.variants || {});
                var m = (v && v.media) || null;
                if (!m) {
                    for (var i = 0; i < langs.length; i++) { var x = d.variants[langs[i]]; if (x && x.media && x.media.kind === 'pdf' && x.media.pages_ready < x.media.page_count) { m = x.media; break; } }
                }
                if (!m || m.kind !== 'pdf') { resuming[l.id] = false; return; }
                var item = { file: { name: (d.title || l.title || 'Document') + ' (pages)' }, purpose: 'lesson_document', state: 'uploading', message: 'Preparing pages ' + (m.pages_ready || 0) + '/' + (m.page_count || 0), ctrl: new AbortController(), lessonId: l.id, fraction: (m.pages_ready || 0) / Math.max(1, m.page_count || 1) };
                trayItems.push(item);
                tray.hidden = false;
                renderTrayItem(item);
                updateTrayTitle();
                up.renderPages(m.id, {
                    signal: item.ctrl.signal,
                    onStage: function (s) { if (s.total) { item.fraction = (s.ready || 0) / s.total; item.message = 'Preparing pages ' + (s.ready || 0) + '/' + s.total; renderTrayItem(item); } }
                }).then(function () {
                    item.state = 'done';
                    item.message = 'Pages ready';
                    resuming[l.id] = false;
                    renderTrayItem(item);
                    updateTrayTitle();
                    reloadDetail();
                }, function (err) {
                    resuming[l.id] = false;
                    item.state = err && err.code === 'aborted' ? 'cancelled' : 'error';
                    item.message = (err && err.message) || 'Preparing the pages stopped.';
                    renderTrayItem(item);
                    updateTrayTitle();
                });
            }, function () { resuming[l.id] = false; });
        }
        function autoResumePages() {
            if (readOnly) { return; }
            (detail.lessons || []).forEach(function (l) {
                if ((l.issues || []).some(function (i) { return i.code === 'pdf_pages_pending'; })) { resumeLessonPages(l); }
            });
        }

        // ================================================================ aside (training kind)
        function renderAside() {
            if (!$('tr-aside')) { return; }
            // cover
            var cov = $('tr-cover');
            paintCover(cov);
            var img = $('tr-cover-img');
            $('tr-cover-glyph').textContent = '';
            $('tr-cover-glyph').appendChild(icon(coverIcon()));
            if (course.cover_url) { if (img.getAttribute('src') !== course.cover_url) { img.src = course.cover_url; } img.hidden = false; } else { img.hidden = true; img.removeAttribute('src'); }
            $('tr-cover-gallery').hidden = !picker;
            $('tr-cover-upload-label').textContent = picker ? 'Upload…' : (course.cover_url ? 'Change cover' : 'Add cover');
            $('tr-cover-remove').hidden = !course.cover_url;
            var vthumb = videoThumbMediaId();
            $('tr-cover-video').hidden = !(vthumb && vthumb !== course.cover_media_id);

            // facts
            var facts = $('tr-facts');
            facts.textContent = '';
            var rows = [
                ['Kind', isDoc ? 'Required document' : 'Training'],
                ['Category', course.category ? course.category.name : 'None'],
                ['Valid for', course.validity_months ? plural(course.validity_months, 'month', 'months') : 'No expiry'],
                ['Time', fmtMin(course.est_minutes_effective) ? 'about ' + fmtMin(course.est_minutes_effective) : '—'],
                ['Order', course.sequential ? 'In order' : 'Any order'],
                ['Sign-off', course.requires_signature ? 'Signature' : 'None']
            ];
            rows.forEach(function (r) { facts.appendChild(el('div', {}, [el('dt', { text: r[0] }), el('dd', { text: r[1], title: r[1] })])); });
            var sum = $('tr-aside-summary');
            sum.hidden = !course.summary;
            sum.textContent = course.summary || '';
            var tags = $('tr-aside-tags');
            tags.textContent = '';
            (detail.tags || []).forEach(function (t) { tags.appendChild(el('span', { class: 'tr-chip', text: t.name })); });

            renderTodo();
            renderLangsCard();
            renderVersionsMini();
            fitAside();
        }

        function videoThumbMediaId() {
            var l = (detail.lessons || []).find(function (x) { return x.type === 'video' && x.thumb_url; });
            return l ? mediaIdFromUrl(l.thumb_url) : null;
        }

        function renderTodo() {
            var host = $('tr-todo');
            if (!host) { return; }
            host.textContent = '';
            var lessons = detail.lessons || [];
            var ready = lessons.filter(function (l) { return !(l.issues || []).some(function (i) { return i.severity === 'error'; }); }).length;
            if (lessons.length) {
                var bar = el('div', { class: 'tr-progress' + (ready === lessons.length ? ' tr-progress--ok' : '') }, [el('div', { class: 'tr-progress__bar' })]);
                bar.firstChild.style.width = Math.round(100 * ready / lessons.length) + '%';
                host.appendChild(el('div', { class: 'tr-todo__progress' }, [el('span', { class: 'text-nowrap', text: ready + ' of ' + plural(lessons.length, 'lesson', 'lessons') + ' ready' }), bar]));
            }
            if (!check) {
                host.appendChild(el('span', { class: 'tr-skeleton__line' }));
                return;
            }
            var items = (check.todo || []);
            if (!items.length) {
                host.appendChild(el('div', { class: 'tr-todo__all-good' }, [icon('check-circle'), hasChanges() ? 'Everything is ready to publish.' : 'Nothing to do.']));
                return;
            }
            var list = el('ul', { class: 'tr-issues tr-todo__list' });
            items.slice(0, 8).forEach(function (i) {
                var l = i.lesson_id ? lessonById(i.lesson_id) : null;
                var where = l ? (l.title || 'Untitled') + (i.lang ? ' · ' + langName(i.lang) : '') : (i.lang ? langName(i.lang) : '');
                list.appendChild(el('li', { class: 'tr-issue--todo' }, [el('span', { class: 'tr-issue__text' }, [
                    el('button', { type: 'button', class: 'tr-issue__link', text: i.message || i.code, on: { click: function () { gotoIssue(i); } } }),
                    where ? el('span', { class: 'tr-issue__where', text: where }) : null
                ])]));
            });
            host.appendChild(list);
            if (items.length > 8) { host.appendChild(el('div', { class: 'small text-muted mt-1', text: '+ ' + (items.length - 8) + ' more. Open Publish to see all.' })); }
        }
        function gotoIssue(i) {
            if (i.code === 'pdf_pages_pending' && i.lesson_id) { var l = lessonById(i.lesson_id); if (l) { resumeLessonPages(l); return; } }
            if (i.lesson_id) { openContent({ lessonId: i.lesson_id, lang: i.lang || undefined }); return; }
            if (/^lang_|^course_name|practical/.test(i.code || '')) { showTab('settings'); return; }
            showTab('content');
        }
        if ($('tr-todo-refresh')) { $('tr-todo-refresh').addEventListener('click', function () { runCheck(true); }); }

        function langStats(lg) {
            var lessons = detail.lessons || [];
            var missing = lessons.filter(function (l) { return !(l.languages && l.languages[lg] && l.languages[lg].complete); }).length;
            var qs = detail.quiz_summaries || {};
            Object.keys(qs).forEach(function (k) {
                var q = qs[k];
                if (q.pool_count && (q.translated || {})[lg] < q.pool_count) { missing += q.pool_count - ((q.translated || {})[lg] || 0); }
            });
            return missing;
        }
        function renderLangsCard() {
            var host = $('tr-langs');
            if (!host) { return; }
            host.textContent = '';
            var offered = course.languages || [];
            (data.languages || []).forEach(function (lg) {
                var isDefault = lg === course.default_language;
                var on = offered.indexOf(lg) !== -1;
                var status;
                if (!on) {
                    status = el('span', { class: 'tr-chip', text: 'Not added' });
                } else {
                    var miss = isDefault ? 0 : langStats(lg);
                    status = miss ? el('span', { class: 'tr-chip tr-chip--warn', text: miss + ' to translate' }) : el('span', { class: 'tr-chip tr-chip--ok' }, [icon('check'), 'Complete']);
                    if (!isDefault && (course.required_languages || []).indexOf(lg) !== -1) { status.title = 'Required to publish'; }
                }
                host.appendChild(el('div', { class: 'tr-lang-row' }, [
                    el('span', { class: 'tr-lang-row__code', text: lg.toUpperCase() }),
                    el('span', { class: 'tr-lang-row__name' }, [langName(lg), isDefault ? el('small', { text: ' (default)' }) : null]),
                    status
                ]));
            });
            var missingLangs = (data.languages || []).filter(function (lg) { return offered.indexOf(lg) === -1; });
            if (missingLangs.length && !readOnly) {
                missingLangs.forEach(function (lg) {
                    host.appendChild(el('button', { type: 'button', class: 'btn btn-outline-secondary w-100 mt-2', on: { click: function () { setOffered(lg, true); } } }, [icon('plus', 'me-2'), 'Add ' + langName(lg) + ' version']));
                });
            }
        }
        function renderVersionsMini() {
            var host = $('tr-vmini');
            if (!host) { return; }
            host.textContent = '';
            var rev = course.current_revision;
            if (!rev || hasChanges()) {
                host.appendChild(el('li', {}, [
                    el('div', { class: 'tr-versions-mini__title' }, [rev ? 'Draft' : 'Draft', el('span', { class: 'tr-badge-status--draft', text: rev ? 'Changes' : 'Not published' })]),
                    el('div', { text: rev ? 'Changed since Version ' + rev.number : 'Publish to create Version 1.' }),
                    rev ? el('button', { type: 'button', class: 'tr-aside-link tr-aside-link--accent px-0', text: 'Compare with Version ' + rev.number, on: { click: function () { showTab('versions'); } } }) : null
                ]));
            }
            if (rev) {
                host.appendChild(el('li', { class: 'is-live' }, [
                    el('div', { class: 'tr-versions-mini__title' }, ['Version ' + rev.number, el('span', { class: 'tr-badge-status--published' }, [icon('check'), 'Live'])]),
                    el('div', { text: 'Published ' + fmtDate(rev.published_at) + (rev.published_by_name ? ' by ' + rev.published_by_name : '') })
                ]));
            }
            var note = $('tr-vmini-note');
            note.hidden = !rev;
            if (rev) { note.querySelector('span').textContent = 'People who finished Version ' + rev.number + ' stay current when you publish. You choose whether anyone has to retake it.'; }
        }

        // cover: gallery, upload, video thumbnail, remove (aside + settings). Everything saves through
        // the course store, so the version check and conflict handling are the same as any field.
        function applyCover(mediaId, url, color) {
            if (readOnly) { return; }
            if (mediaId !== course.cover_media_id) {
                course.cover_media_id = mediaId;
                course.cover_url = url;
                setCourseField('cover_media_id', mediaId, 0);
            }
            if (color !== undefined && color !== course.color) {
                course.color = color;
                setCourseField('color', color, 0);
            }
            renderHeader();
            renderAside();
            renderSettingsCover();
        }
        function openGallery(returnFocus) {
            if (!picker) { return; }
            picker.open({
                title: 'Course cover', name: course.name || 'Untitled course', color: safeColor(course.color, null),
                cover: course.cover_media_id ? { id: course.cover_media_id, url: course.cover_url } : null,
                purpose: 'course_cover', uploadOpts: { courseId: courseId }
            }).then(function (res) {
                if (res) { applyCover(res.cover ? res.cover.id : null, res.cover ? res.cover.url : null, res.color || null); }
                if (returnFocus && returnFocus.focus) { returnFocus.focus(); }
            });
        }
        function wireCover(dropEl, button, progressEl) {
            if (!up || !dropEl || readOnly) { return; }
            up.dropzone(dropEl, {
                purpose: 'course_cover', courseId: courseId, button: button,
                onFiles: function (files) {
                    var f = files[0];
                    progressEl.textContent = 'Uploading…';
                    up.upload(f, { purpose: 'course_cover', courseId: courseId, onProgress: function (p) { progressEl.textContent = 'Uploading ' + Math.round(p.fraction * 100) + '%'; } }).then(function (d) {
                        progressEl.textContent = '';
                        applyCover(d.media.id, d.media.url);
                    }, function (err) {
                        progressEl.textContent = '';
                        ui.toast((err && err.message) || 'The cover could not be uploaded.', { type: 'error' });
                    });
                }
            });
        }
        if ($('tr-cover')) {
            $('tr-cover-gallery').addEventListener('click', function () { openGallery($('tr-cover-gallery')); });
            wireCover($('tr-cover'), $('tr-cover-upload'), $('tr-cover-progress'));
            $('tr-cover-remove').addEventListener('click', function () { applyCover(null, null); });
            $('tr-cover-video').addEventListener('click', function () {
                var id = videoThumbMediaId();
                if (id) { applyCover(id, '/agent/training_media.php?m=' + id); }
            });
        }
        document.querySelectorAll('[data-tr-quick]').forEach(function (b) {
            b.addEventListener('click', function () {
                var last = detail.sections.length ? detail.sections[detail.sections.length - 1].id : null;
                openContent({ type: b.getAttribute('data-tr-quick'), sectionId: last });
            });
        });

        // ================================================================ document kind
        var docLessons = { doc: null, ack: null };
        function docLessonIds() {
            var ls = detail.lessons || [];
            var d = ls.find(function (l) { return l.type !== 'acknowledgment'; }) || null;
            var a = ls.find(function (l) { return l.type === 'acknowledgment'; }) || null;
            return { doc: d, ack: a };
        }
        function loadDocDetails() {
            var ids = docLessonIds();
            return Promise.all([ids.doc ? api.get('lesson_get', { lesson_id: ids.doc.id }) : null, ids.ack ? api.get('lesson_get', { lesson_id: ids.ack.id }) : null]).then(function (r) {
                docLessons = { doc: r[0], ack: r[1] };
                renderDoc();
            }, function (err) {
                ui.toast((err && err.message) || 'The document could not be loaded.', { type: 'error' });
            });
        }
        function stepStatus(elId, done, textDone, textTodo) {
            var n = $(elId);
            n.textContent = '';
            n.appendChild(done ? el('span', { class: 'tr-chip tr-chip--ok' }, [icon('check'), textDone]) : el('span', { class: 'tr-chip tr-chip--warn', text: textTodo }));
            var step = n.closest('.tr-step');
            step.classList.toggle('is-done', done);
            step.classList.toggle('is-todo', !done);
        }
        function lessonToggle(lesson, field, value) {
            api.post('lesson_update', { lesson_id: lesson.id, version: lesson.version, lang: course.default_language, fields: (function () { var f = {}; f[field] = value; return f; })() }).then(function (d) {
                Object.assign(lesson, d);
                scheduleCheck();
            }, function (err) {
                if (err && err.code === 'conflict' && err.data && err.data.current) {
                    Object.assign(lesson, err.data.current);
                    lessonToggle(lesson, field, value);
                    return;
                }
                ui.toast((err && err.message) || 'Not saved.', { type: 'error' });
                loadDocDetails();
            });
        }
        function switchRow(id, label, hint, checked, onChange) {
            var inp = el('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id: id, disabled: readOnly });
            inp.checked = !!checked;
            inp.addEventListener('change', function () { onChange(inp.checked); });
            return el('div', { class: 'tr-switch-row border rounded-3 px-3' }, [
                el('div', {}, [el('label', { class: 'tr-switch-row__label', for: id, text: label }), hint ? el('div', { class: 'tr-switch-row__hint', text: hint }) : null]),
                el('div', { class: 'form-check form-switch' }, [inp])
            ]);
        }
        function renderDoc() {
            if (!isDoc) { return; }
            var d = docLessons.doc;
            var a = docLessons.ack;
            var body = $('tr-doc-step-doc-body');
            body.textContent = '';
            var lang = course.default_language;
            var v = d && d.variants ? d.variants[lang] : null;
            var hasPdf = d && d.type === 'document' && v && v.media;
            var hasArticle = d && d.type === 'article' && v && v.body_html;
            stepStatus('tr-doc-step-doc-status', !!(hasPdf || hasArticle), hasPdf ? 'PDF added' : 'Article written', 'To do');
            if (!d) {
                body.appendChild(el('p', { class: 'text-muted mb-0', text: 'The document lesson is missing. Duplicate the course, or contact an administrator.' }));
            } else if (hasPdf) {
                var m = v.media;
                var pages = el('div', { class: 'tr-pages' }, (v.pages || []).slice(0, 4).map(function (p) { return el('img', { src: p.url, alt: 'Page ' + p.n, loading: 'lazy' }); }));
                if (!(v.pages || []).length) { pages.appendChild(el('span', { class: 'tr-page-ph' })); }
                var pending = m.page_count && (m.pages_ready || 0) < m.page_count;
                body.appendChild(el('div', { class: 'd-flex flex-wrap align-items-start gap-3' }, [
                    pages,
                    el('div', { class: 'd-flex flex-column gap-1' }, [
                        el('div', { class: 'fw-semibold', text: v.title || d.title || m.original_name || 'Document' }),
                        el('div', { class: 'small text-muted', text: [plural(m.page_count || 0, 'page', 'pages'), m.original_name, ui.fmtBytes(m.bytes)].filter(Boolean).join(' · ') }),
                        pending ? el('div', { class: 'small text-muted', text: 'Preparing pages ' + (m.pages_ready || 0) + '/' + m.page_count + '…' }) : null,
                        el('div', { class: 'd-flex gap-2 mt-1 tr-edit-only' }, [
                            el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', on: { click: function () { openContent({ lessonId: d.id }); } } }, [icon('pen', 'me-1'), 'Edit']),
                            el('a', { class: 'btn btn-sm btn-link', href: m.download_url || m.url, target: '_blank', rel: 'noopener' }, [icon('download', 'me-1'), 'Original'])
                        ])
                    ])
                ]));
                body.appendChild(switchRow('tr-doc-dl', 'Allow download', 'People can save the PDF from the tablet.', d.allow_download, function (on) { lessonToggle(d, 'allow_download', on); }));
                if (pending) { resumeLessonPages(d); }
            } else if (hasArticle) {
                var text = htmlText(v.body_html);
                body.appendChild(el('p', { class: 'tr-excerpt mb-0', text: text.slice(0, 400) }));
                body.appendChild(el('div', { class: 'd-flex align-items-center gap-2' }, [
                    el('span', { class: 'small text-muted', text: plural(v.word_count || 0, 'word', 'words') }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary ms-auto tr-edit-only', on: { click: function () { openContent({ lessonId: d.id }); } } }, [icon('pen', 'me-1'), 'Edit article'])
                ]));
            } else if (!readOnly) {
                var drop = el('div', { class: 'tr-drop' }, [
                    el('span', { class: 'tr-drop__icon', 'aria-hidden': 'true' }, [icon('file-pdf')]),
                    el('span', { class: 'tr-drop__title' }, ['Drop the PDF here or ', el('button', { type: 'button', class: 'tr-drop__browse', 'data-tr-upload-button': '', text: 'upload it' })]),
                    el('span', { class: 'tr-drop__hint', text: 'Shown page by page on the tablet. PowerPoint or Excel? Save as PDF first.' }),
                    el('span', { class: 'tr-drop__progress' })
                ]);
                var write = el('button', { type: 'button', class: 'tr-type-tile h-100', on: { click: function () {
                    var go = d.type === 'article' ? Promise.resolve(d) : api.post('lesson_set_type', { lesson_id: d.id, version: d.version, type: 'article' });
                    go.then(function (nd) { docLessons.doc = nd; openContent({ lessonId: nd.id }); }, function (err) { ui.toast((err && err.message) || 'Could not switch to an article.', { type: 'error' }); });
                } } }, [
                    el('span', { class: 'tr-type-icon tr-type-icon--lg tr-type--article', 'aria-hidden': 'true' }, [icon('file-alt')]),
                    el('span', {}, [el('span', { class: 'tr-type-tile__name', text: 'Write or import an article' }), el('span', { class: 'tr-type-tile__hint', text: 'Type it, or import a Word file or a Knowledge Base article.' })])
                ]);
                body.appendChild(el('div', { class: 'tr-step__choices' }, [drop, write]));
                if (up) {
                    up.dropzone(drop, {
                        purpose: 'lesson_document',
                        onFiles: function (files) {
                            var prog = drop.querySelector('.tr-drop__progress');
                            drop.classList.add('is-busy');
                            up.upload(files[0], {
                                purpose: 'lesson_document', courseId: courseId, lessonId: d.id, waitForPages: false,
                                onProgress: function (p) { prog.textContent = 'Uploading ' + Math.round(p.fraction * 100) + '%'; },
                                onStage: function (s) { if (s && s.text) { prog.textContent = s.text; } }
                            }).then(function (res) {
                                prog.textContent = 'Saving…';
                                var go = d.type === 'document' ? Promise.resolve(d) : api.post('lesson_set_type', { lesson_id: d.id, version: d.version, type: 'document' });
                                return go.then(function (nd) {
                                    return api.post('lesson_update', { lesson_id: nd.id, version: nd.version, lang: lang, fields: { media_id: res.media.id } });
                                });
                            }).then(function () {
                                drop.classList.remove('is-busy');
                                reloadDetail();
                            }, function (err) {
                                drop.classList.remove('is-busy');
                                drop.classList.add('is-error');
                                prog.textContent = (err && err.message) || 'Upload failed.';
                            });
                        }
                    });
                }
            }

            // step 2: acknowledgment
            var ab = $('tr-doc-step-ack-body');
            ab.textContent = '';
            var av = a && a.variants ? a.variants[lang] : null;
            stepStatus('tr-doc-step-ack-status', !!(av && av.body_html), 'Statement set', 'Write the statement');
            if (a) {
                var stmt = el('div', { class: 'tr-statement' });
                if (av && av.body_html) { stmt.innerHTML = av.body_html; } else { stmt.appendChild(el('p', { class: 'text-muted mb-0', text: 'No statement yet.' })); } // server-purified statement (spec §6.0)
                ab.appendChild(stmt);
                ab.appendChild(el('div', { class: 'row g-2' }, [
                    el('div', { class: 'col-md-6' }, [switchRow('tr-doc-sig', 'Require finger signature', 'Signed on the tablet.', a.ack_require_signature, function (on) { lessonToggle(a, 'ack_require_signature', on); })]),
                    el('div', { class: 'col-md-6' }, [switchRow('tr-doc-pin', 'Require PIN', 'Confirmed with their PIN.', a.ack_require_pin, function (on) { lessonToggle(a, 'ack_require_pin', on); })])
                ]));
            }
            // step 3: knowledge check
            var sw = $('tr-doc-check-switch');
            var cb = $('tr-doc-step-check-body');
            var q = d && d.quiz;
            sw.checked = !!q;
            sw.disabled = readOnly || !d;
            var step3 = $('tr-doc-step-check');
            step3.classList.toggle('is-done', !!(q && q.question_count));
            if (q && d) {
                if (!docCheckMount) {
                    cb.textContent = '';
                    var host = el('div', { class: 'tr-quiz-host' });
                    cb.appendChild(host);
                    try {
                        docCheckMount = window.TrainingQuizBuilder.mount(host, {
                            lessonId: d.id, courseId: courseId, lang: lang, languages: course.languages, defaultLanguage: course.default_language,
                            lessonType: d.type, embedded: true,
                            // The builder's own quiz_changed broadcast never reaches this page (a
                            // BroadcastChannel does not deliver to itself), so it reports here.
                            onChange: function (qz) {
                                var cur = docLessons.doc;
                                if (!qz || !cur || !cur.quiz) { return; }
                                cur.quiz.question_count = qz.question_count;
                                $('tr-doc-step-check').classList.toggle('is-done', qz.question_count > 0);
                                scheduleCheck();
                            }
                        }) || {};
                    } catch (e) { host.appendChild(el('div', { class: 'tr-quiz-missing', text: 'The question builder could not start. Reload the page.' })); }
                }
            } else {
                if (docCheckMount && docCheckMount.destroy) { try { docCheckMount.destroy(); } catch (e) { /* ignore */ } }
                docCheckMount = null;
                cb.textContent = '';
            }
        }
        var docCheckMount = null;
        if (isDoc) {
            $('tr-doc-ack-edit').addEventListener('click', function () { if (docLessons.ack) { openContent({ lessonId: docLessons.ack.id }); } });
            $('tr-doc-check-switch').addEventListener('change', function () {
                var sw = $('tr-doc-check-switch');
                var d = docLessons.doc;
                if (!d) { return; }
                if (sw.checked) {
                    api.post('quiz_attach', { lesson_id: d.id }).then(function (quiz) {
                        d.quiz = { id: quiz.id, role: quiz.role, question_count: (quiz.questions || []).length };
                        renderDoc();
                        scheduleCheck();
                    }, function (err) { sw.checked = false; ui.toast((err && err.message) || 'Could not add the check.', { type: 'error' }); });
                } else {
                    ui.confirmBar($('tr-doc-step-check-body'), { message: 'Remove the knowledge check? Its questions are kept and come back if you turn it on again.', confirmLabel: 'Remove', prepend: true }).then(function (ok) {
                        if (!ok) { sw.checked = true; return; }
                        api.post('quiz_detach', { lesson_id: d.id }).then(function () {
                            d.quiz = null;
                            renderDoc();
                            scheduleCheck();
                        }, function (err) { sw.checked = true; ui.toast((err && err.message) || 'Could not remove the check.', { type: 'error' }); });
                    });
                }
            });
        }

        // ================================================================ settings tab
        var settingsLang = course.default_language;
        var settingsEditorsReady = false;
        function settingsInputs() { return document.querySelectorAll('#tr-settings [data-tr-field]'); }
        function renderSettingsLangBar() {
            var bar = $('tr-set-lang');
            if (!bar) { return; }
            var langs = course.languages || [];
            $('tr-set-langbar').hidden = langs.length < 2;
            if (langs.indexOf(settingsLang) === -1) { settingsLang = course.default_language; }
            bar.textContent = '';
            langs.forEach(function (lg) {
                bar.appendChild(el('button', { type: 'button', class: 'tr-segment__btn', role: 'radio', 'aria-checked': lg === settingsLang ? 'true' : 'false', text: lg.toUpperCase(), title: langName(lg),
                    on: { click: function () { store.flush().then(function () { saveSettingsEditor(); settingsLang = lg; renderSettingsLangBar(); fillSettings(true); }); } } }));
            });
            $('tr-set-lang-hint').textContent = settingsLang === course.default_language ? '' : 'Name, summary, description and the completion statement. ' + langName(course.default_language) + ' is shown in grey.';
        }
        function fillSettings(force) {
            settingsInputs().forEach(function (inp) {
                var f = inp.getAttribute('data-tr-field');
                var type = inp.getAttribute('data-tr-type');
                var i18n = inp.getAttribute('data-tr-i18n') === '1';
                var lang = i18n ? settingsLang : null;
                var key = fieldKey(f, lang);
                if (!force && (document.activeElement === inp || store.isFieldDirty(key))) { return; }
                if (type === 'html' && !force) {
                    var ed = window.tinymce && window.tinymce.get('tr-set-description');
                    if (descDirty || (ed && ed.hasFocus())) { return; }
                }
                var v = courseValue(f, lang);
                if (type === 'bool') { inp.checked = !!v; }
                else if (type === 'html') { setSettingsEditor(v || ''); }
                else if (type === 'select') { inp.value = v === null || v === undefined ? '' : String(v); }
                else { inp.value = v === null || v === undefined ? '' : String(v); }
            });
            document.querySelectorAll('#tr-settings [data-tr-ref]').forEach(function (n) {
                var f = n.getAttribute('data-tr-ref');
                n.textContent = settingsLang !== course.default_language && course[f] ? langName(course.default_language) + ': ' + course[f] : '';
            });
            renderEstimate();
            renderSettingsCover();
            renderLanguageSwitches();
        }
        function renderEstimate() {
            var auto = course.est_minutes_auto || 0;
            var n = $('tr-set-est-auto');
            if (!n) { return; }
            n.textContent = auto ? 'Automatic: ' + fmtMin(auto) : 'Automatic: not known yet';
            $('tr-set-est').placeholder = auto ? String(auto) : '';
            $('tr-set-est-reset').hidden = course.est_minutes === null || course.est_minutes === undefined;
        }
        function renderSettingsCover() {
            var box = $('tr-set-cover');
            if (!box) { return; }
            paintCover(box);
            var img = box.querySelector('img');
            if (course.cover_url) { if (img.getAttribute('src') !== course.cover_url) { img.src = course.cover_url; } img.hidden = false; } else { img.hidden = true; img.removeAttribute('src'); }
            $('tr-set-cover-remove').hidden = !course.cover_url;
            $('tr-set-cover-gallery').hidden = !picker;
            renderTints();
        }
        // Tint swatches (the gallery's tints plus the current colour when it is a custom one).
        var tints = null;
        function renderTints() {
            var row = $('tr-set-tint-row');
            if (!row || !tints || readOnly) { return; }
            var host = $('tr-set-tint');
            host.textContent = '';
            var list = tints.slice();
            var cur = safeColor(course.color, null);
            if (cur && !list.some(function (t) { return t.color.toUpperCase() === cur.toUpperCase(); })) { list.push({ name: 'custom', color: cur }); }
            list.forEach(function (t) {
                var on = !!cur && t.color.toUpperCase() === cur.toUpperCase();
                var name = t.name.charAt(0).toUpperCase() + t.name.slice(1);
                var b = el('button', { type: 'button', class: 'tr-tint__swatch' + (on ? ' is-selected' : ''), role: 'radio', 'aria-checked': on ? 'true' : 'false',
                    'aria-label': name, title: name, tabindex: on || (!cur && t === list[0]) ? '0' : '-1',
                    on: { click: function () { applyCover(course.cover_media_id, course.cover_url, t.color.toUpperCase()); } } });
                b.style.setProperty('--tr-swatch', t.color);
                host.appendChild(b);
            });
            row.hidden = false;
        }
        if (picker && $('tr-set-tint')) {
            picker.presets().then(function (p) { tints = (p && p.tints) || []; renderTints(); }, function () { /* the gallery button still works */ });
            $('tr-set-tint').addEventListener('keydown', function (e) {
                var btns = Array.prototype.slice.call(this.querySelectorAll('[role=radio]'));
                var i = btns.indexOf(document.activeElement);
                var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
                if (i === -1 || !step) { return; }
                e.preventDefault();
                var next = btns[(i + step + btns.length) % btns.length];
                next.click();
                var again = this.querySelectorAll('[role=radio]')[(i + step + btns.length) % btns.length];
                if (again) { again.focus(); }
            });
        }
        function renderLanguageSwitches() {
            document.querySelectorAll('[data-tr-offer]').forEach(function (inp) {
                var lg = inp.getAttribute('data-tr-offer');
                inp.checked = (course.languages || []).indexOf(lg) !== -1;
            });
            document.querySelectorAll('[data-tr-require]').forEach(function (inp) {
                var lg = inp.getAttribute('data-tr-require');
                var offered = (course.languages || []).indexOf(lg) !== -1;
                inp.checked = (course.required_languages || []).indexOf(lg) !== -1;
                inp.disabled = readOnly || !offered;
            });
        }

        function initSettingsEditors() {
            if (settingsEditorsReady) { return; }
            settingsEditorsReady = true;
            var ta = $('tr-set-description');
            if (!window.tinymce || !ta) { return; }
            window.tinymce.init({
                target: ta, plugins: 'link lists autoresize', toolbar: 'bold italic underline | bullist numlist | link | undo redo',
                menubar: false, statusbar: false, promotion: false, branding: false, license_key: 'gpl', convert_urls: false,
                min_height: 180, max_height: 480, autoresize_bottom_margin: 12, readonly: readOnly,
                skin: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'oxide-dark' : 'oxide',
                content_css: [document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'default', flags.article_css],
                setup: function (ed) {
                    ed.on('init', function () { setSettingsEditor(courseValue('description_html', settingsLang) || ''); });
                    ed.on('input change undo redo', function () { if (!settingEditor) { descDirty = true; saveDescSoon(); } });
                    ed.on('blur', function () { saveSettingsEditor(); });
                }
            });
        }
        var settingEditor = false;
        var descDirty = false;
        function setSettingsEditor(html) {
            var ed = window.tinymce && window.tinymce.get('tr-set-description');
            if (!ed) { var ta = $('tr-set-description'); if (ta && !window.tinymce) { ta.value = html; } return; }
            settingEditor = true;
            ed.setContent(html || '');
            ed.undoManager.clear();
            settingEditor = false;
            descDirty = false;
        }
        function saveSettingsEditor() {
            var ed = window.tinymce && window.tinymce.get('tr-set-description');
            if (!ed || !descDirty) { return; }
            descDirty = false;
            var lang = settingsLang;
            ed.uploadImages().then(function () {
                var html = ed.getContent();
                setCourseField(fieldKey('description_html', lang), html.trim() === '' ? null : html, 0);
            });
        }
        var saveDescSoon = ui.debounce(saveSettingsEditor, 1500);

        settingsInputs().forEach(function (inp) {
            var type = inp.getAttribute('data-tr-type');
            if (type === 'html') { return; }
            var f = inp.getAttribute('data-tr-field');
            var i18n = inp.getAttribute('data-tr-i18n') === '1';
            var evt = type === 'bool' || type === 'select' ? 'change' : 'input';
            inp.addEventListener(evt, function () {
                var key = fieldKey(f, i18n ? settingsLang : null);
                var v;
                if (type === 'bool') { v = inp.checked; setCourseField(key, v, 0); return; }
                if (type === 'select') { v = inp.value === '' ? null : parseInt(inp.value, 10); setCourseField(key, v, 0); return; }
                if (type === 'int') {
                    var raw = inp.value.trim();
                    if (raw === '') {
                        if (inp.getAttribute('data-tr-required') === '1') { showFieldError(f, 'Enter a number.'); return; }
                        v = null;
                    } else {
                        v = parseInt(raw, 10);
                        if (isNaN(v)) { showFieldError(f, 'Enter a whole number.'); return; }
                    }
                    showFieldError(f, '');
                    setCourseField(key, v, 800);
                    return;
                }
                v = inp.value;
                if (f === 'name') {
                    if (v.trim() === '' && (!i18n || settingsLang === course.default_language)) { showFieldError('name', 'Give the course a name.'); return; }
                    if (settingsLang === course.default_language && document.activeElement !== nameInput) { nameInput.value = v.trim(); }
                }
                v = v.trim() === '' ? null : (f === 'eval_checklist' || f === 'summary' || f === 'attestation_text' ? v : v.trim());
                setCourseField(key, v, 800);
            });
        });
        if ($('tr-set-est-reset')) {
            $('tr-set-est-reset').addEventListener('click', function () { $('tr-set-est').value = ''; setCourseField('est_minutes', null, 0); course.est_minutes = null; renderEstimate(); });
        }
        if ($('tr-set-cover')) {
            $('tr-set-cover-gallery').addEventListener('click', function () { openGallery($('tr-set-cover-gallery')); });
            wireCover($('tr-set-cover'), $('tr-set-cover-upload'), $('tr-set-cover').querySelector('.tr-drop__progress'));
            $('tr-set-cover-remove').addEventListener('click', function () { applyCover(null, null); });
        }

        // category select
        var catSel = $('tr-set-category');
        if (catSel) {
            catSel.appendChild(el('option', { value: '', text: 'No category' }));
            (data.categories || []).forEach(function (c) { catSel.appendChild(el('option', { value: String(c.id), text: c.name })); });
            if (course.category && !(data.categories || []).some(function (c) { return c.id === course.category.id; })) {
                catSel.appendChild(el('option', { value: String(course.category.id), text: course.category.name + ' (archived)' }));
            }
            catSel.addEventListener('change', function () {
                var id = catSel.value ? parseInt(catSel.value, 10) : null;
                var c = (data.categories || []).find(function (x) { return x.id === id; }) || null;
                course.category = c ? { id: c.id, name: c.name, color: c.color, icon: c.icon } : null;
                renderHeader();
                renderAside();
                renderSettingsCover();
            });
        }

        // tags (course_set_tags)
        var tagSelect = null;
        var tagsSaving = false;
        if ($('tr-set-tags') && typeof window.TomSelect === 'function') {
            tagSelect = new window.TomSelect($('tr-set-tags'), {
                plugins: ['remove_button'], create: function (input) { var v = input.trim().slice(0, 60); return v ? { value: v, text: v } : false; },
                createOnBlur: true, persist: false, maxOptions: 200, placeholder: 'Add tags',
                options: (data.tags || []).map(function (t) { return { value: t.name, text: t.name }; }),
                items: (detail.tags || []).map(function (t) { return t.name; }),
                render: {
                    option: function (d, escape) { return '<div>' + escape(d.text) + '</div>'; },
                    item: function (d, escape) { return '<div>' + escape(d.text) + '</div>'; },
                    option_create: function (d, escape) { return '<div class="create">Add <strong>' + escape(d.input) + '</strong></div>'; }
                },
                onChange: ui.debounce(function () {
                    var names = tagSelect.getValue();
                    names = Array.isArray(names) ? names : String(names || '').split(',').filter(Boolean);
                    if (sameList(names, (detail.tags || []).map(function (t) { return t.name; }))) { return; }
                    tagsSaving = true;
                    markCard('basics', 'saving');
                    api.post('course_set_tags', { course_id: courseId, tags: names }).then(function (r) {
                        tagsSaving = false;
                        detail.tags = r.tags || [];
                        markCard('basics', 'saved');
                        $('tr-set-tags-error').textContent = '';
                        renderAside();
                    }, function (err) {
                        tagsSaving = false;
                        markCard('basics', 'error', (err && err.message) || 'Tags not saved');
                        $('tr-set-tags-error').textContent = (err && ((err.fields && err.fields.tags) || err.message)) || 'Tags not saved.';
                    });
                }, 400)
            });
            if (readOnly) { tagSelect.disable(); }
        }
        void tagsSaving;

        // responsible (TomSelect -> user_search)
        var respSel = null;
        if ($('tr-set-responsible') && typeof window.TomSelect === 'function') {
            respSel = new window.TomSelect($('tr-set-responsible'), {
                valueField: 'id', labelField: 'name', searchField: ['name', 'email'], maxItems: 1, preload: 'focus', maxOptions: 20,
                placeholder: 'Choose a person',
                options: course.responsible_user_id ? [{ id: String(course.responsible_user_id), name: course.responsible_name || ('User #' + course.responsible_user_id), email: '' }] : [],
                items: course.responsible_user_id ? [String(course.responsible_user_id)] : [],
                load: function (q, cb) {
                    api.get('user_search', { q: q || '' }).then(function (d) { cb((d.users || []).map(function (u) { return { id: String(u.id), name: u.name, email: u.email }; })); }, function () { cb(); });
                },
                render: {
                    option: function (d, escape) { return '<div>' + escape(d.name) + (d.email ? ' <span class="text-muted small">' + escape(d.email) + '</span>' : '') + '</div>'; },
                    item: function (d, escape) { return '<div>' + escape(d.name) + '</div>'; }
                },
                onChange: function (v) {
                    var id = v ? parseInt(v, 10) : null;
                    setCourseField('responsible_user_id', id, 0);
                }
            });
            if (readOnly) { respSel.disable(); }
        }

        // prerequisites (course_set_prereqs)
        var prereqSel = null;
        var prereqGood = (detail.prereqs || []).map(function (p) { return String(p.course_id); });
        if ($('tr-set-prereq') && typeof window.TomSelect === 'function') {
            prereqSel = new window.TomSelect($('tr-set-prereq'), {
                plugins: ['remove_button'], valueField: 'id', labelField: 'name', searchField: ['name'], maxItems: 10, maxOptions: 300,
                placeholder: 'Choose courses',
                options: (data.courses || []).map(function (c) { return { id: String(c.id), name: c.name }; }).concat((detail.prereqs || []).filter(function (p) {
                    return !(data.courses || []).some(function (c) { return c.id === p.course_id; });
                }).map(function (p) { return { id: String(p.course_id), name: p.name }; })),
                items: prereqGood.slice(),
                render: {
                    option: function (d, escape) { return '<div>' + escape(d.name) + '</div>'; },
                    item: function (d, escape) { return '<div>' + escape(d.name) + '</div>'; }
                },
                onChange: ui.debounce(function () {
                    var ids = prereqSel.getValue();
                    ids = (Array.isArray(ids) ? ids : String(ids || '').split(',')).filter(Boolean);
                    if (sameList(ids.slice().sort(), prereqGood.slice().sort())) { return; }
                    var st = $('tr-set-prereq-state');
                    st.setAttribute('data-state', 'saving');
                    st.textContent = 'Saving…';
                    api.post('course_set_prereqs', { course_id: courseId, requires: ids.map(Number) }).then(function (r) {
                        detail.prereqs = r.prereqs || [];
                        prereqGood = detail.prereqs.map(function (p) { return String(p.course_id); });
                        st.setAttribute('data-state', 'saved');
                        st.textContent = 'Saved';
                        $('tr-set-prereq-error').textContent = '';
                    }, function (err) {
                        st.setAttribute('data-state', 'error');
                        st.textContent = 'Not saved';
                        $('tr-set-prereq-error').textContent = (err && (err.code === 'prereq_cycle' ? (err.message || 'That would make a loop.') : ((err.fields && err.fields.requires) || err.message))) || 'Not saved.';
                        prereqSel.setValue(prereqGood, true);
                    });
                }, 300)
            });
            if (readOnly) { prereqSel.disable(); }
        }

        // languages (course_set_languages)
        function postLanguages(languages, required, defaultLang) {
            var st = $('tr-set-lang-state');
            if (st) { st.setAttribute('data-state', 'saving'); st.textContent = 'Saving…'; }
            var body = { course_id: courseId, languages: languages, required: required };
            if (defaultLang) { body.default_language = defaultLang; }
            return api.post('course_set_languages', body).then(function (r) {
                if (st) { st.setAttribute('data-state', 'saved'); st.textContent = 'Saved'; }
                if ($('tr-set-lang-error')) { $('tr-set-lang-error').textContent = ''; }
                if (defaultLang && defaultLang !== course.default_language) { window.location.reload(); return; }
                course = Object.assign({}, course, r.course);
                detail.course = course;
                if (!store.isDirty()) { store.reset(course.version, courseSnap(course)); }
                renderAll();
                scheduleCheck(true);
            }, function (err) {
                if (st) { st.setAttribute('data-state', 'error'); st.textContent = 'Not saved'; }
                var msg = (err && ((err.fields && (err.fields.languages || err.fields.required || err.fields.default_language)) || err.message)) || 'Not saved.';
                if ($('tr-set-lang-error')) { $('tr-set-lang-error').textContent = msg; } else { ui.toast(msg, { type: 'error' }); }
                renderLanguageSwitches();
            });
        }
        function setOffered(lg, on) {
            var langs = (course.languages || []).slice();
            var req = (course.required_languages || []).slice();
            if (on) {
                if (langs.indexOf(lg) === -1) { langs.push(lg); }
                return postLanguages(langs, req);
            }
            var withContent = (detail.lessons || []).filter(function (l) { return l.languages && l.languages[lg] && l.languages[lg].complete; }).length;
            var go = function () {
                postLanguages(langs.filter(function (x) { return x !== lg; }), req.filter(function (x) { return x !== lg; }));
            };
            if (withContent || (course.i18n && course.i18n[lg])) {
                ui.confirmBar($('tr-set-lang-confirm'), {
                    message: langName(lg) + ' has content in ' + plural(withContent, 'lesson', 'lessons') + '. It stays saved, but it is not offered or published. Stop offering it?',
                    confirmLabel: 'Stop offering ' + langName(lg), danger: true
                }).then(function (ok) { if (ok) { go(); } else { renderLanguageSwitches(); } });
                return Promise.resolve();
            }
            go();
            return Promise.resolve();
        }
        document.querySelectorAll('[data-tr-offer]').forEach(function (inp) {
            inp.addEventListener('change', function () { setOffered(inp.getAttribute('data-tr-offer'), inp.checked); });
        });
        document.querySelectorAll('[data-tr-require]').forEach(function (inp) {
            inp.addEventListener('change', function () {
                var lg = inp.getAttribute('data-tr-require');
                var req = (course.required_languages || []).filter(function (x) { return x !== lg; });
                if (inp.checked) { req.push(lg); }
                postLanguages((course.languages || []).slice(), req);
            });
        });

        // ================================================================ versions tab
        var versionsLoaded = false;
        function diffList(diff, max) {
            var ul = el('ul', { class: 'tr-diff' });
            var items = (diff && diff.items) || [];
            items.slice(0, max || items.length).forEach(function (it) { ul.appendChild(window.TrainingPublish ? window.TrainingPublish.diffItem(it) : el('li', { text: it.label })); });
            return ul;
        }
        function loadVersions() {
            if (versionsLoaded) { return; }
            versionsLoaded = true;
            var draftBody = $('tr-draft-body');
            var tl = $('tr-timeline-body');
            api.get('revision_list', { course_id: courseId }).then(function (d) {
                var revs = d.revisions || [];
                $('tr-versions-count').textContent = String(revs.length);
                tl.textContent = '';
                if (!revs.length) {
                    tl.appendChild(el('div', { class: 'tr-empty py-3' }, [el('p', { class: 'tr-empty__title', text: 'No published versions yet' }), el('p', { class: 'tr-empty__text mb-0', text: 'Each time you publish, a numbered version is added here. Earlier versions are never changed.' })]));
                    return;
                }
                var ol = el('ol', { class: 'tr-timeline' });
                revs.forEach(function (r) { ol.appendChild(timelineItem(r)); });
                tl.appendChild(ol);
            }, function (err) {
                versionsLoaded = false;
                tl.textContent = '';
                tl.appendChild(el('div', { class: 'alert alert-danger', text: (err && err.message) || 'The versions could not be loaded.' }));
            });
            draftBody.textContent = '';
            draftBody.appendChild(el('span', { class: 'tr-skeleton__line' }));
            if (!course.current_revision) {
                $('tr-draft-card').classList.add('has-changes');
                draftBody.textContent = '';
                draftBody.appendChild(el('p', { class: 'mb-0', text: 'Not published yet. Publishing creates Version 1; your draft stays private until then.' }));
                return;
            }
            api.get('revision_diff', { course_id: courseId }).then(function (diff) {
                draftBody.textContent = '';
                $('tr-draft-card').classList.toggle('has-changes', !!diff.has_changes);
                if (!diff.has_changes) {
                    draftBody.appendChild(el('p', { class: 'mb-0 text-muted', text: 'No changes since Version ' + course.current_revision.number + '.' }));
                    return;
                }
                var c = diff.counts || {};
                draftBody.appendChild(el('p', { class: 'mb-2', text: 'Unpublished changes: ' + ['added', 'changed', 'moved', 'removed'].filter(function (k) { return c[k]; }).map(function (k) { return c[k] + ' ' + k; }).join(', ') + '.' }));
                draftBody.appendChild(diffList(diff, 12));
                if ((diff.items || []).length > 12) { draftBody.appendChild(el('div', { class: 'small text-muted mt-1', text: '+ ' + (diff.items.length - 12) + ' more' })); }
            }, function (err) {
                draftBody.textContent = '';
                draftBody.appendChild(el('div', { class: 'text-danger small', text: (err && err.message) || 'The changes could not be loaded.' }));
            });
        }
        function timelineItem(r) {
            var details = el('div', { class: 'tr-details', hidden: true }, [
                el('div', {}, ['Fingerprint ', el('span', { class: 'tr-mono', text: r.sha12 })]),
                r.verified ? el('div', { class: 'tr-verified' }, [icon('check-circle', 'me-1'), 'Hash verified: this version is exactly as published.'])
                    : el('div', { class: 'tr-mismatch' }, [icon('times-circle', 'me-1'), 'Mismatch: this version no longer matches its record. Tell an administrator.']),
                el('div', { class: 'text-muted', text: 'Published ' + fmtDate(r.published_at, true) })
            ]);
            var chips = [];
            if (r.requires_retraining) {
                var rt = isDoc ? 'Re-acknowledge' : 'Retrain';
                chips.push(el('span', { class: 'tr-chip tr-chip--warn' }, [icon('redo'), r.retrain_due_days ? rt + ' within ' + r.retrain_due_days + ' days' : rt]));
            }
            if (r.languages && r.languages.length) { chips.push(el('span', { class: 'tr-chip' }, [icon('language'), r.languages.join(' · ').toUpperCase()])); }
            if (r.counts) {
                chips.push(el('span', { class: 'tr-chip', text: plural(r.counts.lessons || 0, 'lesson', 'lessons') }));
                if (r.counts.questions) { chips.push(el('span', { class: 'tr-chip', text: plural(r.counts.questions, 'question', 'questions') })); }
            }
            var detailsBtn = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', 'aria-expanded': 'false' }, [icon('info-circle', 'me-1'), 'Details']);
            detailsBtn.addEventListener('click', function () {
                details.hidden = !details.hidden;
                detailsBtn.setAttribute('aria-expanded', details.hidden ? 'false' : 'true');
            });
            return el('li', { class: 'tr-timeline__item' + (r.current ? ' is-current' : '') }, [
                el('span', { class: 'tr-timeline__marker', 'aria-hidden': 'true', text: String(r.number) }),
                el('div', { class: 'tr-timeline__head' }, [
                    el('h3', { class: 'tr-timeline__title', text: 'Version ' + r.number }),
                    r.current ? el('span', { class: 'tr-badge-status--published' }, [icon('check'), 'Live']) : null,
                    el('span', { class: 'tr-timeline__meta', text: fmtDate(r.published_at, true) + (r.published_by_name ? ' · ' + r.published_by_name : '') })
                ]),
                el('p', { class: 'tr-timeline__note', text: r.change_note }),
                chips.length ? el('div', { class: 'tr-timeline__chips' }, chips) : null,
                el('div', { class: 'tr-timeline__actions' }, [
                    el('a', { class: 'btn btn-sm btn-outline-secondary', href: '/agent/training_preview.php?course_id=' + courseId + '&revision_id=' + r.id, target: '_blank', rel: 'noopener' }, [icon('eye', 'me-1'), 'Preview']),
                    el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', on: { click: function () { openCompare(r); } } }, [icon('exchange-alt', 'me-1'), 'Compare to draft']),
                    detailsBtn
                ]),
                details
            ]);
        }
        var comparePanel = $('tr-compare-panel');
        var compareScrim = $('tr-compare-scrim');
        var compareReturn = null;
        function openCompare(r) {
            compareReturn = document.activeElement;
            $('tr-compare-title').textContent = 'Version ' + r.number + ' compared to the draft';
            $('tr-compare-sub').textContent = 'What changes if you publish the draft now.';
            var body = $('tr-compare-body');
            body.textContent = '';
            body.appendChild(el('span', { class: 'tr-skeleton__line' }));
            comparePanel.hidden = false;
            compareScrim.hidden = false;
            $('tr-compare-close').focus();
            api.get('revision_diff', { course_id: courseId, from_revision_id: r.id }).then(function (diff) {
                body.textContent = '';
                if (!diff.has_changes) { body.appendChild(el('p', { class: 'text-muted', text: 'The draft is the same as Version ' + r.number + '.' })); return; }
                body.appendChild(diffList(diff));
            }, function (err) {
                body.textContent = '';
                body.appendChild(el('div', { class: 'text-danger', text: (err && err.message) || 'Could not compare.' }));
            });
        }
        function closeCompare() {
            comparePanel.hidden = true;
            compareScrim.hidden = true;
            if (compareReturn && compareReturn.focus) { compareReturn.focus(); }
        }
        if (comparePanel) {
            // .tr-b is a size container (layout containment), so fixed children would anchor to it.
            document.body.appendChild(compareScrim);
            document.body.appendChild(comparePanel);
            $('tr-compare-close').addEventListener('click', closeCompare);
            compareScrim.addEventListener('click', closeCompare);
            comparePanel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); closeCompare(); } });
        }

        // ================================================================ publish readiness + drift
        var checkSeq = 0;
        var lastCheckAt = 0;
        var checkTimer = null;
        function runCheck(force) {
            if (readOnly) { return Promise.resolve(); }
            var my = ++checkSeq;
            lastCheckAt = Date.now();
            var btn = $('tr-todo-refresh');
            if (btn && force) { btn.disabled = true; }
            return api.get('publish_check', { course_id: courseId, network: 0 }).then(function (d) {
                if (btn) { btn.disabled = false; }
                if (my !== checkSeq) { return; }
                check = d;
                if (!d.has_changes && course.has_changes) { course.has_changes = false; }
                renderHeader();
                renderTodo();
                renderVersionsMini();
                fitAside();
            }, function (err) {
                if (btn) { btn.disabled = false; }
                if (my !== checkSeq) { return; }
                var host = $('tr-todo');
                if (host && !check) {
                    host.textContent = '';
                    host.appendChild(el('div', { class: 'small text-danger', text: (err && err.message) || 'The course could not be checked.' }));
                }
            });
        }
        /** At most every 10 s after saves (spec §5.3); force = now. */
        function scheduleCheck(force) {
            if (checkTimer) { clearTimeout(checkTimer); }
            var wait = force ? 0 : Math.max(1500, 10000 - (Date.now() - lastCheckAt));
            checkTimer = setTimeout(function () { checkTimer = null; runCheck(false); }, wait);
        }
        function loadDrift() {
            if (!course.current_revision && !(detail.lessons || []).some(function (l) { return l.type === 'video' || l.type === 'article'; })) { return; }
            api.get('course_drift', { course_id: courseId }).then(function (d) { drift = d; renderDrift(); }, function () { /* drift pills are optional */ });
        }

        // ================================================================ header actions
        var headConfirm = $('tr-head-confirm');
        function act(name, btn) {
            var host = btn && btn.closest && btn.closest('.tr-danger') && $('tr-danger-confirm') ? $('tr-danger-confirm') : headConfirm;
            if (name === 'duplicate' || name === 'duplicate-training') {
                api.post('course_duplicate', { course_id: courseId, as_training: name === 'duplicate-training' }).then(function (d) {
                    ui.toast('Copy created. Opening it…');
                    window.location.href = d.url;
                }, function (err) { ui.toast((err && err.message) || 'Could not duplicate.', { type: 'error' }); });
            } else if (name === 'archive') {
                archiveForm(host);
            } else if (name === 'restore') {
                api.post('course_restore', { course_id: courseId }).then(function () { window.location.reload(); }, function (err) {
                    ui.toast((err && err.message) || 'Could not restore.', { type: 'error' });
                });
            } else if (name === 'delete') {
                ui.confirmBar(host, { message: 'Delete the draft "' + course.name + '" and everything in it? This cannot be undone.', confirmLabel: 'Delete draft', danger: true }).then(function (ok) {
                    if (!ok) { return; }
                    api.post('course_delete', { course_id: courseId }).then(function () {
                        window.TrainingStore.stopAll();
                        window.location.href = '/agent/training_courses.php';
                    }, function (err) { ui.toast((err && err.message) || 'Could not delete the draft.', { type: 'error' }); });
                });
            } else if (name === 'copy-uid') {
                var uid = course.uid || '';
                var done = function () { ui.toast('Course UID copied: ' + uid); };
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(uid).then(done, function () { ui.toast('Course UID: ' + uid, { type: 'info', delay: 10000 }); }); } else { ui.toast('Course UID: ' + uid, { type: 'info', delay: 10000 }); }
            } else if (name === 'default-language') {
                defaultLanguageForm();
            }
        }
        document.querySelectorAll('[data-tr-act]').forEach(function (b) {
            b.addEventListener('click', function () { act(b.getAttribute('data-tr-act'), b); });
        });
        if ($('tr-restore-course')) { $('tr-restore-course').addEventListener('click', function () { act('restore'); }); }

        function archiveForm(host) {
            host = host || headConfirm;
            host.textContent = '';
            var reason = el('input', { type: 'text', class: 'form-control form-control-sm', maxlength: '500', placeholder: 'Reason (optional)', 'aria-label': 'Reason for archiving' });
            var bar = el('div', { class: 'tr-confirm-bar alert alert-danger', role: 'alertdialog', 'aria-label': 'Archive course' }, [
                el('span', { text: 'Archive "' + course.name + '"? It becomes read-only; versions and records are kept.' }),
                el('div', { class: 'flex-fill tr-w-320' }, [reason]),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel', on: { click: function () { host.textContent = ''; } } }),
                el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Archive', on: { click: function () {
                    store.flush().then(function () {
                        return api.post('course_archive', { course_id: courseId, reason: reason.value.trim() || undefined });
                    }).then(function () { window.location.reload(); }, function (err) { ui.toast((err && err.message) || 'Could not archive.', { type: 'error' }); });
                } } })
            ]);
            host.appendChild(bar);
            reason.focus();
            host.scrollIntoView({ block: 'nearest' });
        }
        function defaultLanguageForm() {
            headConfirm.textContent = '';
            var others = (data.languages || []).filter(function (l) { return l !== course.default_language; });
            if (!others.length) { return; }
            var sel = el('select', { class: 'form-select form-select-sm', 'aria-label': 'New default language' }, others.map(function (l) { return el('option', { value: l, text: langName(l) }); }));
            var bar = el('div', { class: 'tr-confirm-bar alert alert-warning', role: 'alertdialog', 'aria-label': 'Change default language' }, [
                el('span', { text: 'Make another language the default? The course needs a name in that language first.' }),
                el('div', { class: 'tr-w-180' }, [sel]),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel', on: { click: function () { headConfirm.textContent = ''; } } }),
                el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'Make default', on: { click: function () {
                    var lg = sel.value;
                    var langs = (course.languages || []).slice();
                    if (langs.indexOf(lg) === -1) { langs.push(lg); }
                    store.flush().then(function () { postLanguages(langs, (course.required_languages || []).slice(), lg); });
                } } })
            ]);
            headConfirm.appendChild(bar);
            sel.focus();
        }

        // publish
        function openPublish() {
            if (!window.TrainingPublish || !$('tr-pub')) { return; }
            store.flush().then(function () {
                return window.TrainingPublish.open({
                    courseId: courseId, courseName: course.name, kind: course.kind,
                    lessonTitle: function (id) { var l = lessonById(id); return l ? (l.title || 'Untitled') : null; },
                    onOpenIssue: function (i) { gotoIssue(i); }
                });
            }).then(function (res) {
                if (res && res.published) { window.location.reload(); }
            });
        }
        if ($('tr-publish-btn')) { $('tr-publish-btn').addEventListener('click', openPublish); }
        if ($('tr-draft-publish')) { $('tr-draft-publish').addEventListener('click', openPublish); }

        /** Sticky aside taller than the window: stick its bottom edge instead of its top (no inner scrollbar). */
        function fitAside() {
            var a = $('tr-aside');
            if (!a) { return; }
            var h = a.offsetHeight;
            var room = window.innerHeight - 32;
            a.style.top = h > room ? (window.innerHeight - h - 16) + 'px' : '';
        }
        window.addEventListener('resize', ui.debounce(fitAside, 100));

        // ================================================================ render all + boot
        function renderAll() {
            renderHeader();
            if (isDoc) { loadDocDetails(); } else { renderOutline(); renderAside(); }
            renderSettingsLangBar();
            fillSettings(false);
        }

        renderAll();
        var initial = (window.location.hash || '').replace('#', '');
        if (initial === 'settings' || initial === 'versions') { showTab(initial); }
        if (!readOnly) {
            runCheck(false);
            loadDrift();
            autoResumePages();
        } else {
            var todo = $('tr-todo');
            if (todo) { todo.textContent = ''; }
        }
    });
})();
