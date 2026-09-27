/*
 * The Create Content window (spec §5.4, plan A13).
 *
 *   TrainingContentModal.open({courseId, lessonId|null, sectionId|null, type|null, lang, tab|null,
 *                              course, sections, afterLessonId?, files?})
 *       -> Promise<{changed, lessonId, deleted?, again?:{sectionId, type}}>
 *
 * course / sections come from the builder's CourseDetail (languages, default language, kind,
 * section titles). The window never opens anything on top of itself: KB search, compare,
 * confirm-replace and the like are the in-window .tr-panel or .tr-confirm-bar strips, and
 * TomSelect / dropdowns use the modal as their parent.
 *
 * Autosave (spec §5.4 table):
 *   - a new lesson is created by the first meaningful change (a title, text, a file or a checked
 *     link); every later patch waits behind that create in the lesson's TrainingStore;
 *   - title / caption 600 ms, TinyMCE 1500 ms and on blur (after uploadImages()), toggles at once;
 *   - 409 conflict rebases in the store; a real clash shows "Changed in another tab. Reload";
 *   - unsaved HTML is mirrored to localStorage (tr-draft:<user>:<lesson>:<lang>, 7 days) and
 *     offered back on reopen;
 *   - server echoes never overwrite a focused or dirty field;
 *   - a created lesson left without a title or content is deleted on close.
 * No HTML string sinks except server-purified body_html / description_html (spec §6.0).
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
    var DURATION_NOTE = {
        video: 'from the video length', pages: '30 sec a page', words: 'from the word count', image: 'the usual for an image',
        ack: 'reading and signing', quiz: 'from the question count', override: 'set by you', none: ''
    };
    var PROBE_CODEC = { avc1: 'H.264', avc3: 'H.264', hvc1: 'HEVC', hev1: 'HEVC', mp4a: 'AAC' };
    var MEANINGFUL = ['title', 'body_html', 'description_html', 'media_id', 'video_check_token', 'caption'];
    var FILE_PURPOSE = { lesson_document: 'document', lesson_video: 'video', lesson_image: 'image', docx_import: 'article' };

    var instance = null;

    function create() {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        var up = window.TrainingUploader;
        var modalEl = document.getElementById('tr-cm');
        if (!ui || !api || !modalEl || !window.bootstrap) { return null; }
        var el = ui.el;
        var page = ui.readJson('tr-page-data');
        var flags = page.flags || {};
        var userId = page.user_id || 0;
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false });
        var $ = function (id) { return document.getElementById(id); };
        var content = $('tr-cm-content');

        var E = {
            title: $('tr-cm-title'), context: $('tr-cm-context'), langs: $('tr-cm-langs'), close: $('tr-cm-close'),
            alerts: $('tr-cm-alerts'), deleted: $('tr-cm-deleted'), top: $('tr-cm-top'),
            name: $('tr-cm-name'), nameRef: $('tr-cm-name-ref'), nameErr: $('tr-cm-name-error'),
            tagList: $('tr-cm-tag-list'), tagInput: $('tr-cm-tag-input'), tagAdd: $('tr-cm-tag-add'), tagOpts: $('tr-cm-tag-options'),
            thumb: $('tr-cm-thumb'), thumbImg: $('tr-cm-thumb-img'), thumbAuto: $('tr-cm-thumb-auto'), thumbUpload: $('tr-cm-thumb-upload'),
            thumbRemove: $('tr-cm-thumb-remove'), thumbProgress: $('tr-cm-thumb-progress'),
            tabs: $('tr-cm-tabs'), body: $('tr-cm-body'),
            typepick: $('tr-cm-typepick'), types: $('tr-cm-types'), typeHint: $('tr-cm-type-hint'), typepill: $('tr-cm-typepill'),
            typepillIcon: $('tr-cm-typepill-icon'), typepillText: $('tr-cm-typepill-text'), typepillMenu: $('tr-cm-typepill-menu'),
            typepillNote: $('tr-cm-typepill-note'), typeConfirm: $('tr-cm-type-confirm'), grid: $('tr-cm-grid'),
            save: $('tr-cm-save'), issues: $('tr-cm-issues'), issuesBtn: $('tr-cm-issues-btn'), issuesMenu: $('tr-cm-issues-menu'),
            preview: $('tr-cm-preview'), del: $('tr-cm-delete'), again: $('tr-cm-again'), done: $('tr-cm-done'),
            scrim: $('tr-cm-scrim'), panel: $('tr-cm-panel'), panelTitle: $('tr-cm-panel-title'), panelSub: $('tr-cm-panel-sub'),
            panelBody: $('tr-cm-panel-body'), panelFooter: $('tr-cm-panel-footer'), panelClose: $('tr-cm-panel-close'),
            zoom: $('tr-cm-zoom'), zoomImg: $('tr-cm-zoom-img'),
            resCount: $('tr-cm-res-count'), quizCount: $('tr-cm-quiz-count'), quizTabLi: $('tr-cm-tab-quiz-li')
        };

        var st = null;           // per-open state
        var dropzones = {};      // purpose -> TrainingUploader.dropzone handle (created once)
        var respSelect = null;   // TomSelect for the responsible person
        var channelBound = false;

        // =============================================================================== helpers
        function icon(n, extra) { return el('i', { class: 'fas fa-' + n + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function has(o, k) { return Object.prototype.hasOwnProperty.call(o || {}, k); }
        function langName(l) { return LANG_NAMES[l] || String(l).toUpperCase(); }
        function isDefaultLang() { return st.lang === st.course.default_language; }
        function variant(d, l) { return (d && d.variants && d.variants[l || st.lang]) || null; }
        function fmtDate(iso) { if (!iso) { return ''; } var d = new Date(iso); return isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }
        function daysAgo(iso) {
            if (!iso) { return null; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return null; }
            return Math.floor((Date.now() - d.getTime()) / 86400000);
        }
        function show(node, on) { if (node) { node.hidden = !on; } }
        function setDisabled(node, on) { if (node) { node.disabled = !!on; } }
        function bytes(n) { return ui.fmtBytes(n); }
        function parseMmSs(v) {
            v = String(v || '').trim();
            if (v === '') { return null; }
            var m = v.match(/^(\d{1,4})(?::(\d{1,2}))?$/);
            if (!m) { return NaN; }
            var sec = m[2] === undefined ? parseInt(m[1], 10) * 60 : parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
            if (m[2] !== undefined && parseInt(m[2], 10) > 59) { return NaN; }
            return sec;
        }
        function tagNames(d) { return ((d && d.tags) || []).map(function (t) { return typeof t === 'string' ? t : t.name; }); }
        /** Title from a file name. " - " between words is the author's own separator, so it stays ("LOTO Procedure - Press Brake 4"). */
        function fileTitle(name) {
            var s = String(name || '');
            var parts = s.split(/\s+[-\u2013\u2014]\s+/);
            if (!up) { return s; }
            if (parts.length < 2) { return up.titleFromName(s); }
            var last = up.titleFromName(parts.pop());
            return parts.map(function (x) { return x.replace(/_+/g, ' ').replace(/\s+/g, ' ').trim(); }).filter(Boolean).concat([last]).join(' - ');
        }
        /** Stopwatch time for video; "about 30 sec" / "about 4 min" for everything else (it is an estimate). */
        function fmtLength(sec, type) {
            if (!sec) { return ''; }
            if (type === 'video') { return ui.fmtDuration(sec); }
            if (sec < 60) { return 'about ' + Math.max(5, Math.round(sec / 5) * 5) + ' sec'; }
            return 'about ' + Math.round(sec / 60) + ' min';
        }

        /** The store's snapshot of a LessonDetail for one language (what the autosave compares). */
        function snapshot(d, l) {
            var v = variant(d, l) || {};
            return {
                title: v.title || '',
                description_html: v.description_html || null,
                body_html: v.body_html || null,
                media_id: v.media ? v.media.id : null,
                caption: v.caption || null,
                caption_media_id: v.caption_file ? v.caption_file.id : null,
                video_provider: v.video ? v.video.provider : null,
                required: !!d.required,
                duration_s: d.duration_source === 'override' ? d.duration_s : null,
                allow_download: !!d.allow_download,
                preview_enabled: !!d.preview_enabled,
                responsible_user_id: d.responsible_user_id === undefined ? null : d.responsible_user_id,
                thumb_media_id: d.thumb_media_id === undefined ? null : d.thumb_media_id,
                min_watch_pct: d.min_watch_pct,
                ack_require_signature: !!d.ack_require_signature,
                ack_require_pin: !!d.ack_require_pin,
                section_id: d.section_id === undefined ? null : d.section_id,
                tags: tagNames(d)
            };
        }

        /** Default values for a lesson that does not exist yet (display only). */
        function blankLesson() {
            return {
                id: null, uid: null, type: st.type, required: true, duration_s: 0, duration_auto_s: 0, duration_source: 'none',
                allow_download: false, preview_enabled: false, responsible_user_id: null, responsible_name: null,
                thumb_media_id: null, thumb_url: null, thumb_auto: true, min_watch_pct: 90, ack_require_signature: true,
                ack_require_pin: true, section_id: st.sectionId, tags: [], variants: {}, resources: [], quiz: null, issues: []
            };
        }

        // =============================================================================== alerts area
        function clearAlert(key) {
            var old = E.alerts.querySelector('[data-alert="' + key + '"]');
            if (old) { old.parentNode.removeChild(old); }
        }
        function alertBox(key, cls, children) {
            clearAlert(key);
            var node = el('div', { class: 'alert ' + cls + ' d-flex flex-wrap align-items-center gap-2 py-2 mb-2', role: 'status', dataset: { alert: key } }, children);
            E.alerts.appendChild(node);
            return node;
        }

        // =============================================================================== save state
        function renderSave(state, info) {
            if (!st) { return; }
            E.save.setAttribute('data-state', state);
            E.save.textContent = '';
            if (state === 'saving') {
                E.save.textContent = 'Saving…';
            } else if (state === 'saved') {
                E.save.textContent = 'All changes saved';
            } else if (state === 'offline') {
                E.save.textContent = 'Offline. Your changes are kept and will be retried.';
            } else if (state === 'conflict') {
                E.save.appendChild(document.createTextNode('Changed in another tab. '));
                E.save.appendChild(el('button', { type: 'button', text: 'Reload lesson', on: { click: reloadLesson } }));
            } else if (state === 'error') {
                var err = info && info.error;
                var msg = (err && err.message) || 'Not saved.';
                if (err && err.code === 'too_large') { msg = err.message || 'That is too large to save.'; }
                E.save.textContent = msg;
            } else if (state === 'draft') {
                E.save.setAttribute('data-state', 'idle');
                E.save.textContent = st.type ? 'Type a title to start. Nothing is saved before that.' : '';
            } else {
                E.save.textContent = st.lessonId ? 'Changes save automatically' : '';
            }
            refreshFooter();
        }

        // =============================================================================== store
        function ensureStore() {
            if (st.store) { return st.store; }
            var key = st.lessonId || null;
            if (key) { window.TrainingStore.forget('lesson', key); } else { window.TrainingStore.forget('lesson', 'new'); }
            st.store = window.TrainingStore.entity('lesson', key, {
                version: st.lesson && st.lesson.id ? st.lesson.version : 0,
                base: st.lesson && st.lesson.id ? snapshot(st.lesson, st.lang) : {},
                create: createLesson,
                send: function (patch, version) {
                    return api.post('lesson_update', { lesson_id: st.lessonId, version: version, lang: st.lang, fields: patch });
                },
                idOf: function (d) { return d && d.id; },
                versionOf: function (d) { return d && d.version; },
                baseOf: function (d) { return snapshot(d, st.lang); },
                snapshotOf: function (cur) { return snapshot(cur, st.lang); },
                onState: function (state, info) { renderSave(state, info); },
                onSaved: function (d, patch) { afterSave(d, patch); },
                onError: function (err, patch) { onSaveError(err, patch); },
                onConflict: function () { renderSave('conflict'); }
            });
            return st.store;
        }

        function createLesson(patch) {
            var body = { course_id: st.courseId, type: st.type, lang: st.lang };
            if (st.sectionId) { body.section_id = st.sectionId; }
            if (st.afterLessonId) { body.after_lesson_id = st.afterLessonId; }
            var rest = Object.assign({}, patch);
            if (has(rest, 'title')) { body.title = rest.title; delete rest.title; }
            if (has(rest, 'media_id') && rest.media_id) { body.media_id = rest.media_id; delete rest.media_id; }
            if (has(rest, 'video_check_token') && rest.video_check_token) {
                body.video_check_token = rest.video_check_token;
                delete rest.video_check_token;
                delete rest.video_provider;
            }
            var ctx = st;
            return api.post('lesson_create', body).then(function (d) {
                if (ctx !== st) { return d; }
                st.lessonId = d.id;
                st.lesson = d;
                st.changed = true;
                onCreated();
                // Whatever the create call could not carry (toggles, text) follows as an update;
                // the store sends it right after this create settles.
                if (Object.keys(rest).length) { st.store.patch(rest, 0); }
                return d;
            });
        }

        function onCreated() {
            E.title.textContent = 'Edit content';
            updateContext();
            collapseTypePicker();
            refreshTabs();
            refreshFooter();
            renderResources();
            renderQuizTab();
            if (st.type === 'quiz') { renderQuizPane(); }
        }

        /** Queues a field. Before the lesson exists, only a meaningful change creates it. */
        function setField(f, v, delay) {
            if (!st || st.readOnly) { return; }
            clearFieldError(f);
            if (!st.lessonId && !st.store) {
                var meaningful = MEANINGFUL.indexOf(f) !== -1 && v !== null && v !== '' && v !== undefined;
                if (!meaningful || !st.type) {
                    st.preCreate[f] = v;
                    renderSave('draft');
                    return;
                }
                var patch = Object.assign({}, st.preCreate);
                patch[f] = v;
                st.preCreate = {};
                ensureStore().patch(patch, delay || 0);
                return;
            }
            ensureStore().set(f, v, delay || 0);
        }
        function setFields(obj, delay) {
            var keys = Object.keys(obj);
            if (!st.lessonId && !st.store) {
                var meaningful = keys.some(function (k) { return MEANINGFUL.indexOf(k) !== -1 && obj[k] !== null && obj[k] !== ''; });
                if (!meaningful || !st.type) { Object.assign(st.preCreate, obj); renderSave('draft'); return; }
                var patch = Object.assign({}, st.preCreate, obj);
                st.preCreate = {};
                ensureStore().patch(patch, delay || 0);
                return;
            }
            ensureStore().patch(obj, delay || 0);
        }
        function fieldDirty(f) {
            if (!st) { return false; }
            if (has(st.preCreate, f)) { return true; }
            return !!(st.store && st.store.isFieldDirty(f));
        }

        var FIELD_ERR = { title: 'tr-cm-name-error', caption: 'tr-cm-caption-error', caption_media_id: 'tr-cm-cap-error', description_html: 'tr-cm-desc-error', responsible_user_id: 'tr-cm-resp-error', duration_s: 'tr-cm-dur-error' };
        function bodyErrId() { return st && st.type === 'acknowledgment' ? 'tr-cm-ack-error' : 'tr-cm-body-error'; }
        function clearFieldError(f) {
            var id = f === 'body_html' ? bodyErrId() : FIELD_ERR[f];
            if (id && $(id)) { $(id).textContent = ''; }
        }
        function onSaveError(err, patch) {
            if (!st) { return; }
            if (err && err.code === 'not_found') { showDeleted(); return; }
            if (err && err.code === 'archived') { alertBox('archived', 'alert-warning', [icon('archive'), el('span', { text: 'This course was archived. Changes can no longer be saved.' })]); return; }
            var fields = (err && err.fields) || {};
            var shown = false;
            Object.keys(fields).forEach(function (f) {
                var id = f === 'body_html' ? bodyErrId() : (FIELD_ERR[f] || (f === 'video_check_token' ? 'tr-cm-vurl-error' : null));
                if (id && $(id)) { $(id).textContent = fields[f]; shown = true; }
            });
            if (!shown && err && err.message && Object.keys(patch || {}).some(function (k) { return k === 'body_html' || k === 'description_html'; })) {
                var id2 = has(patch, 'body_html') ? bodyErrId() : 'tr-cm-desc-error';
                $(id2).textContent = err.message;
            }
            if (err && (err.code === 'video_live')) { $('tr-cm-vurl-error').textContent = err.message; }
        }

        function afterSave(d, patch) {
            if (!st || !d) { return; }
            st.changed = true;
            var warnings = d.warnings || [];
            st.lesson = d;
            if (has(patch, 'body_html') || has(patch, 'description_html')) { clearLocalDraft(patch); }
            warnings.forEach(function (w) { if (w && w.message) { ui.toast(w.message, { type: 'warning' }); } });
            renderFromDetail(false);
        }

        // =============================================================================== local HTML mirror
        function draftKey() { return st && st.lessonId ? 'tr-draft:' + userId + ':' + st.lessonId + ':' + st.lang : null; }
        function mirrorHtml(field, html) {
            var k = draftKey();
            if (!k) { return; }
            var cur = ui.local.get(k) || {};
            cur[field] = html;
            ui.local.set(k, cur);
        }
        function clearLocalDraft(patch) {
            var k = draftKey();
            if (!k) { return; }
            var cur = ui.local.get(k);
            if (!cur) { return; }
            Object.keys(patch).forEach(function (f) { if (cur[f] === patch[f]) { delete cur[f]; } });
            if (Object.keys(cur).length === 0) { ui.local.remove(k); } else { ui.local.set(k, cur); }
        }
        function offerLocalDraft() {
            var k = draftKey();
            if (!k) { return; }
            var saved = ui.local.get(k);
            var at = ui.local.savedAt(k);
            if (!saved || !at) { return; }
            var v = variant(st.lesson) || {};
            var serverAt = v.updated_at ? new Date(v.updated_at).getTime() : 0;
            var differs = Object.keys(saved).some(function (f) { return saved[f] !== (v[f] || null) && saved[f] !== (v[f] || ''); });
            if (!differs || at <= serverAt) { ui.local.remove(k); return; }
            var when = new Date(at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            alertBox('draft', 'alert-warning', [
                icon('history'),
                el('span', { class: 'me-auto', text: 'Unsaved changes from ' + when + ' are still in this browser.' }),
                el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'Restore', on: { click: function () {
                    clearAlert('draft');
                    Object.keys(saved).forEach(function (f) {
                        if (f === 'body_html') { setEditorContent(st.type === 'acknowledgment' ? 'ack' : 'body', saved[f]); }
                        if (f === 'description_html') { setEditorContent('desc', saved[f]); }
                        setField(f, saved[f], 0);
                    });
                } } }),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Discard', on: { click: function () { ui.local.remove(k); clearAlert('draft'); } } })
            ]);
        }

        // =============================================================================== TinyMCE
        var EDITORS = { body: 'tr-cm-body-editor', ack: 'tr-cm-ack-editor', desc: 'tr-cm-desc-editor' };
        var FIELD_OF = { body: 'body_html', ack: 'body_html', desc: 'description_html' };

        function darkMode() { return document.documentElement.getAttribute('data-bs-theme') === 'dark'; }

        function editorFor(kind) { return window.tinymce ? window.tinymce.get(EDITORS[kind]) : null; }

        function initEditor(kind) {
            if (!window.tinymce) {
                var ta = $(EDITORS[kind]);
                ta.classList.add('form-control');
                ta.rows = 10;
                return Promise.resolve(null);
            }
            var existing = editorFor(kind);
            if (existing) { return Promise.resolve(existing); }
            if (st.editorInit[kind]) { return st.editorInit[kind]; }
            var rich = kind === 'body';
            var contentCss = [];
            contentCss.push(darkMode() ? 'dark' : 'default');
            contentCss.push(flags.article_css);
            var ctx = st;
            st.editorInit[kind] = window.tinymce.init({
                target: $(EDITORS[kind]),
                plugins: rich ? 'link image lists table code fullscreen autoresize' : 'link lists autoresize',
                toolbar: rich
                    ? 'blocks | bold italic underline forecolor | bullist numlist | alignleft aligncenter alignright | table image link | code fullscreen | undo redo'
                    : 'bold italic underline | bullist numlist | link | undo redo',
                menubar: false,
                statusbar: false,
                promotion: false,
                branding: false,
                license_key: 'gpl',
                convert_urls: false,
                browser_spellcheck: true,
                contextmenu: false,
                paste_data_images: rich,
                images_file_types: 'jpg,jpeg,png,webp,gif',
                automatic_uploads: true,
                image_uploadtab: rich,
                min_height: rich ? 300 : 160,
                max_height: rich ? 620 : 360,
                autoresize_bottom_margin: 16,
                skin: darkMode() ? 'oxide-dark' : 'oxide',
                content_css: contentCss,
                body_class: darkMode() ? 'tra-dark' : '',
                link_default_target: '_blank',
                link_assume_external_targets: 'https',
                images_upload_handler: function (blobInfo, progress) {
                    return new Promise(function (resolve, reject) {
                        if (!up) { reject({ message: 'Uploads are not available.', remove: true }); return; }
                        var file = blobInfo.blob();
                        var t = st;
                        if (t) { t.uploads++; refreshFooter(); }
                        up.upload(file, {
                            purpose: 'article_image', courseId: st.courseId, lessonId: st.lessonId || undefined,
                            filename: blobInfo.filename(),
                            onProgress: function (p) { try { progress(Math.round(p.fraction * 100)); } catch (e) { /* ignore */ } }
                        }).then(function (d) {
                            if (t) { t.uploads--; refreshFooter(); }
                            resolve((d && (d.location || (d.media && d.media.url))) || '');
                        }, function (err) {
                            if (t) { t.uploads--; refreshFooter(); }
                            ui.toast((err && err.message) || 'The image could not be uploaded.', { type: 'error' });
                            reject({ message: (err && err.message) || 'Upload failed', remove: true });
                        });
                    });
                },
                setup: function (ed) {
                    ed.on('init', function () {
                        if (ctx !== st) { return; }
                        ed.getContainer().style.visibility = '';
                        loadEditor(kind);
                        if (st.readOnly) { ed.mode.set('readonly'); }
                    });
                    var onChange = function () {
                        if (!st || st.settingEditor[kind]) { return; }
                        st.editorDirty[kind] = true;
                        scheduleEditorSave(kind);
                        if (kind === 'body') { updateWordCount(); }
                    };
                    ed.on('input change undo redo', onChange);
                    ed.on('blur', function () { if (st && st.editorDirty[kind]) { saveEditor(kind); } });
                }
            }).then(function (eds) { return eds && eds[0] ? eds[0] : null; });
            return st.editorInit[kind];
        }

        function loadEditor(kind) {
            var ed = editorFor(kind);
            if (!ed || !st) { return; }
            var v = variant(st.lesson) || {};
            var html = kind === 'desc' ? (v.description_html || '') : (v.body_html || '');
            if (kind === 'ack' && st.type !== 'acknowledgment') { html = ''; }
            if (kind === 'body' && st.type !== 'article') { html = ''; }
            setEditorContent(kind, html);
        }

        function setEditorContent(kind, html) {
            var ed = editorFor(kind);
            if (!ed || !st) { if (!window.tinymce && st) { $(EDITORS[kind]).value = html || ''; } return; }
            st.settingEditor[kind] = true;
            ed.setContent(html || '');
            ed.undoManager.clear();
            ed.setDirty(false);
            st.settingEditor[kind] = false;
            st.editorDirty[kind] = false;
            if (kind === 'body') { updateWordCount(); }
        }

        function scheduleEditorSave(kind) {
            if (!st.editorTimers[kind]) {
                st.editorTimers[kind] = ui.debounce(function () { saveEditor(kind); }, 1500);
            }
            st.editorTimers[kind]();
            var ed = editorFor(kind);
            if (ed && st.lessonId) { mirrorHtml(FIELD_OF[kind], ed.getContent()); }
        }

        /** uploadImages() first, so no blob: or data: source is ever sent. */
        function saveEditor(kind) {
            if (!st) { return Promise.resolve(); }
            var t = st;
            if (t.editorTimers[kind]) { t.editorTimers[kind].cancel(); }
            var ed = editorFor(kind);
            if (!ed) {
                if (!window.tinymce) {
                    var raw = $(EDITORS[kind]).value;
                    setField(FIELD_OF[kind], raw.trim() === '' ? null : raw, 0);
                }
                return Promise.resolve();
            }
            if (!t.editorDirty[kind]) { return Promise.resolve(); }
            t.htmlBusy++;
            refreshFooter();
            return ed.uploadImages().then(function () {
                if (t !== st) { return; }
                t.editorDirty[kind] = false;
                var html = ed.getContent();
                if (/\ssrc=["'](?:blob|data):/i.test(html)) {
                    $(kind === 'desc' ? 'tr-cm-desc-error' : bodyErrId()).textContent = 'An image did not upload. Remove it or try pasting it again.';
                    return;
                }
                setField(FIELD_OF[kind], html.trim() === '' ? null : html, 0);
            }, function () { /* the failing image was removed by TinyMCE; the next change saves */ }).then(function () {
                t.htmlBusy--;
                refreshFooter();
            });
        }

        function removeEditors() {
            if (!window.tinymce) { return; }
            Object.keys(EDITORS).forEach(function (k) {
                var ed = editorFor(k);
                if (ed) { ed.remove(); }
            });
        }

        function updateWordCount() {
            var ed = editorFor('body');
            var wc = $('tr-cm-wc');
            if (!ed) { wc.textContent = ''; return; }
            var text = ed.getContent({ format: 'text' }) || '';
            var words = (text.trim().match(/\S+/g) || []).length;
            wc.textContent = words ? plural(words, 'word', 'words') + ' · ≈ ' + Math.max(1, Math.round(words / 200)) + ' min read' : '';
        }

        // =============================================================================== type
        function panes() { return content.querySelectorAll('.tr-cm__pane'); }
        function showPane(type) {
            panes().forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== (type || 'none'); });
            var aside = type && type !== 'quiz' && type !== 'article';
            E.grid.classList.toggle('has-aside', !!aside);
            content.querySelectorAll('[data-tr-show-types]').forEach(function (n) {
                n.hidden = !type || n.getAttribute('data-tr-show-types').split(',').indexOf(type) === -1;
            });
        }

        function renderTypeTiles() {
            E.types.querySelectorAll('[role=radio]').forEach(function (b) {
                var on = b.getAttribute('data-type') === st.type;
                b.setAttribute('aria-checked', on ? 'true' : 'false');
                b.tabIndex = on || (!st.type && b === E.types.firstElementChild) ? 0 : -1;
                var allowed = allowedTypes().indexOf(b.getAttribute('data-type')) !== -1;
                b.disabled = !allowed;
            });
        }

        function allowedTypes() {
            if (st.course.kind === 'document') {
                if (st.lesson && st.lesson.type === 'acknowledgment') { return ['acknowledgment']; }
                return ['document', 'article'];
            }
            return Object.keys(TYPES);
        }

        function collapseTypePicker() {
            var hasContent = !!st.lessonId;
            show(E.typepick, !hasContent);
            show(E.typepill, hasContent && !!st.type);
            if (hasContent && st.type) {
                E.typepillIcon.className = 'tr-type-icon tr-type--' + st.type;
                E.typepillIcon.textContent = '';
                E.typepillIcon.appendChild(icon(TYPES[st.type].icon));
                E.typepillText.textContent = 'Type: ' + TYPES[st.type].label;
                var locked = st.course.kind === 'document' && st.type === 'acknowledgment';
                $('tr-cm-typepill-btn').disabled = locked;
                E.typepillNote.textContent = locked ? "The acknowledgment of a required document can't change type." : '';
                E.typepillMenu.querySelectorAll('[data-type]').forEach(function (b) {
                    var t = b.getAttribute('data-type');
                    b.parentNode.hidden = t === st.type || allowedTypes().indexOf(t) === -1;
                });
            }
        }

        function chooseType(type) {
            if (!st || st.readOnly || allowedTypes().indexOf(type) === -1) { return; }
            if (st.lessonId) { changeType(type); return; }
            st.type = type;
            renderTypeTiles();
            applyType();
            E.typeHint.textContent = 'You can change this until you save.';
            var pending = Object.keys(st.preCreate).some(function (k) { return MEANINGFUL.indexOf(k) !== -1 && st.preCreate[k]; });
            if (pending) { setFields({}, 0); }
            renderSave('draft');
            if (!E.name.value.trim()) { E.name.focus(); }
        }

        function changeType(type) {
            ui.confirmBar(E.typeConfirm, {
                message: 'Change this to ' + TYPES[type].label + '? The file, link or text of the current type is removed in every language' +
                    (type === 'article' || type === 'acknowledgment' ? ' (text is kept).' : '.'),
                confirmLabel: 'Change type'
            }).then(function (ok) {
                if (!ok) { return; }
                flushAll().then(function () {
                    return api.post('lesson_set_type', { lesson_id: st.lessonId, version: st.store ? st.store.version : st.lesson.version, type: type });
                }).then(function (d) {
                    st.changed = true;
                    adoptDetail(d);
                    st.type = d.type;
                    applyType();
                    collapseTypePicker();
                    ui.toast('Changed to ' + TYPES[d.type].label + '.');
                }, function (err) {
                    ui.toast((err && err.message) || 'The type could not be changed.', { type: 'error' });
                });
            });
        }

        /** Shows the panes, editors and tabs that belong to st.type. */
        function applyType() {
            showPane(st.type);
            refreshTabs();
            setHeaderEnabled(!!st.type);
            if (st.type === 'article') { initEditor('body'); }
            if (st.type === 'acknowledgment') { initEditor('ack'); }
            if (st.type) { initEditor('desc'); }
            if (st.type === 'quiz') { renderQuizPane(); }
            if (st.type === 'video') { renderVideo(); }
            if (st.type === 'document') { renderDocument(); }
            if (st.type === 'image') { renderImage(); }
            if (st.type === 'acknowledgment') { renderAck(); }
            if (st.type === 'article') { renderArticle(); }
            renderSettings();
            renderQuizTab();
        }

        function setHeaderEnabled(on) {
            var ro = st.readOnly;
            E.tagAdd.disabled = !on || ro;
            E.thumbUpload.disabled = !on || ro;
            E.thumb.classList.toggle('is-disabled', !on || ro);
            if (dropzones.thumb) { dropzones.thumb.setDisabled(!on || ro); }
            E.tagList.querySelectorAll('button').forEach(function (b) { b.disabled = !on || ro; });
        }

        function refreshTabs() {
            var on = !!st.type;
            E.tabs.querySelectorAll('.nav-link').forEach(function (b) {
                if (b.getAttribute('data-tab') !== 'content') { b.disabled = !on; b.classList.toggle('disabled', !on); }
            });
            E.quizTabLi.hidden = !on || st.type === 'quiz' || st.type === 'acknowledgment';
        }

        // =============================================================================== header: title, tags, thumbnail
        function renderHeader(force) {
            var v = variant(st.lesson) || {};
            if (force || (document.activeElement !== E.name && !fieldDirty('title'))) {
                E.name.value = v.title || (has(st.preCreate, 'title') ? st.preCreate.title : '');
            }
            if (!isDefaultLang() && st.lesson) {
                var dv = variant(st.lesson, st.course.default_language) || {};
                E.nameRef.textContent = dv.title || '';
            } else {
                E.nameRef.textContent = '';
            }
            renderTags();
            renderThumb();
        }

        function currentTags() {
            if (has(st.preCreate, 'tags')) { return st.preCreate.tags.slice(); }
            if (st.store && st.store.isFieldDirty('tags')) { return (st.pendingTags || tagNames(st.lesson)).slice(); }
            return tagNames(st.lesson);
        }
        function renderTags() {
            E.tagList.textContent = '';
            currentTags().forEach(function (t) {
                E.tagList.appendChild(el('span', { class: 'tr-tag' }, [t,
                    el('button', { type: 'button', 'aria-label': 'Remove tag ' + t, disabled: !st.type || st.readOnly, on: { click: function () { removeTag(t); } } }, [icon('times')])
                ]));
            });
        }
        function setTags(list) {
            st.pendingTags = list;
            setField('tags', list, 0);
            renderTags();
        }
        function addTag(name) {
            name = String(name || '').trim().replace(/\s+/g, ' ').slice(0, 60);
            if (!name) { return; }
            var list = currentTags();
            if (list.some(function (t) { return t.toLowerCase() === name.toLowerCase(); })) { return; }
            list.push(name);
            setTags(list);
        }
        function removeTag(name) { setTags(currentTags().filter(function (t) { return t !== name; })); }

        function renderThumb(localUrl) {
            var d = st.lesson || {};
            var custom = has(st.preCreate, 'thumb_media_id') ? st.preCreate.thumb_media_id : d.thumb_media_id;
            var url = localUrl || (custom ? (st.thumbUrl || d.thumb_url) : (d.thumb_auto ? d.thumb_url : null));
            if (url) {
                E.thumbImg.src = url;
                E.thumbImg.hidden = false;
                E.thumb.classList.add('has-img');
            } else {
                E.thumbImg.removeAttribute('src');
                E.thumbImg.hidden = true;
                E.thumb.classList.remove('has-img');
            }
            E.thumbAuto.hidden = !(url && !custom && !localUrl);
            E.thumbRemove.hidden = !custom;
        }

        // =============================================================================== language
        function renderLangs() {
            var langs = st.course.languages || [];
            E.langs.textContent = '';
            E.langs.hidden = langs.length < 2;
            langs.forEach(function (l) {
                var b = el('button', { type: 'button', class: 'tr-segment__btn', role: 'radio', 'aria-checked': l === st.lang ? 'true' : 'false',
                    tabindex: l === st.lang ? '0' : '-1', title: langName(l), text: l.toUpperCase(), on: { click: function () { switchLang(l); } } });
                E.langs.appendChild(b);
            });
            clearAlert('lang');
            if (!isDefaultLang()) {
                var node = alertBox('lang', 'alert-info', [
                    icon('language'),
                    el('span', { class: 'me-auto', text: 'Editing the ' + langName(st.lang) + ' version. English text is shown in grey for reference.' })
                ]);
                if (st.lessonId && !st.readOnly) {
                    node.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', text: 'Copy from ' + langName(st.course.default_language), on: { click: copyFromDefault } }));
                }
            }
        }

        function switchLang(l) {
            if (!st || l === st.lang) { return; }
            flushAll().then(function () {
                st.lang = l;
                if (st.store && st.lesson && st.lesson.id) { st.store.reset(st.store.version, snapshot(st.lesson, l)); }
                renderLangs();
                renderFromDetail(true);
                Object.keys(EDITORS).forEach(function (k) { loadEditor(k); });
                renderRefs();
                offerLocalDraft();
            });
        }

        function renderRefs() {
            var def = st.course.default_language;
            var dv = (st.lesson && variant(st.lesson, def)) || {};
            var on = !isDefaultLang() && !!st.lesson;
            function ref(detailsId, boxId, html) {
                var d = $(detailsId);
                var box = $(boxId);
                box.textContent = '';
                d.hidden = !on || !html;
                if (on && html) { box.innerHTML = html; } // server-purified body_html / description_html (spec §6.0)
            }
            ref('tr-cm-body-ref', 'tr-cm-body-ref-box', st.type === 'article' ? dv.body_html : null);
            ref('tr-cm-ack-ref', 'tr-cm-ack-ref-box', st.type === 'acknowledgment' ? dv.body_html : null);
            ref('tr-cm-desc-ref', 'tr-cm-desc-ref-box', dv.description_html);
            $('tr-cm-caption-ref').textContent = on && dv.caption ? 'English: ' + dv.caption : '';
        }

        function copyFromDefault() {
            var from = st.course.default_language;
            var to = st.lang;
            var v = variant(st.lesson, to);
            var hasContent = !!(v && ((v.title || '').trim() || v.body_html || v.description_html || v.media || (v.video && v.video.provider)));
            function run(overwrite) {
                flushAll().then(function () {
                    return api.post('lesson_copy_variant', { lesson_id: st.lessonId, from: from, to: to, overwrite: overwrite });
                }).then(adoptAfterCopy, function (err) {
                    if (err && err.fields && err.fields.overwrite && !overwrite) { ask(); return; }
                    ui.toast((err && err.message) || 'Could not copy.', { type: 'error' });
                });
            }
            function ask() {
                ui.confirmBar(E.alerts, {
                    message: langName(to) + ' already has content. Replace it with the ' + langName(from) + ' version?',
                    confirmLabel: 'Replace', danger: true, prepend: true
                }).then(function (ok) { if (ok) { run(true); } });
            }
            if (hasContent) { ask(); } else { run(false); }
        }
        function adoptAfterCopy(d) {
            st.changed = true;
            adoptDetail(d);
            ui.toast('Copied. Now translate the text.', { type: 'success' });
        }

        /** Replaces the whole state from a fresh LessonDetail (reload, copy, set type, KB import). */
        function adoptDetail(d) {
            st.lesson = d;
            st.lessonId = d.id;
            st.type = d.type;
            if (st.store) { st.store.reset(d.version, snapshot(d, st.lang)); } else { ensureStore(); }
            st.preCreate = {};
            st.pendingTags = null;
            renderFromDetail(true);
            Object.keys(EDITORS).forEach(function (k) { loadEditor(k); });
            renderRefs();
        }

        function reloadLesson() {
            if (!st || !st.lessonId) { return; }
            api.get('lesson_get', { lesson_id: st.lessonId }).then(function (d) {
                adoptDetail(d);
                renderSave('idle');
            }, function (err) {
                if (err && err.code === 'not_found') { showDeleted(); return; }
                ui.toast((err && err.message) || 'Could not reload.', { type: 'error' });
            });
        }

        // =============================================================================== render from the detail
        function renderFromDetail(force) {
            if (!st) { return; }
            renderHeader(force);
            if (st.type === 'document') { renderDocument(); }
            if (st.type === 'video') { renderVideo(); }
            if (st.type === 'image') { renderImage(force); }
            if (st.type === 'acknowledgment') { renderAck(); }
            if (st.type === 'article') { renderArticle(); }
            if (st.type === 'quiz') { renderQuizPane(); }
            renderSettings(force);
            renderResources();
            renderQuizTab();
            renderIssues();
            refreshFooter();
        }

        // ---- article
        function renderArticle() {
            var v = variant(st.lesson) || {};
            show($('tr-cm-kb-btn'), !!flags.kb && !st.readOnly);
            show($('tr-cm-kb-help'), !!flags.kb);
            var chip = $('tr-cm-kbchip');
            chip.textContent = '';
            var kb = v.kb_source;
            if (kb) {
                chip.appendChild(el('div', { class: 'tr-kb-chip' + (kb.drift ? ' is-drift' : '') }, [
                    icon('book'),
                    el('span', { text: 'From KB: ' + (kb.title || ('article #' + kb.article_id)) + (kb.imported_at ? ' · imported ' + fmtDate(kb.imported_at) : '') }),
                    kb.drift ? el('span', { class: 'fw-semibold', text: '· KB changed since' }) : null,
                    kb.locally_edited ? el('span', { class: 'text-muted', text: '· edited here' }) : null,
                    el('span', { class: 'ms-auto d-flex gap-1' }, [
                        flags.kb ? el('button', { type: 'button', class: 'btn btn-link btn-sm', text: 'Re-import', on: { click: function () { kbImport(kb.article_id, kb.title, false); } } }) : null,
                        el('button', { type: 'button', class: 'btn btn-link btn-sm', text: 'Unlink', on: { click: function () { setField('kb_source', null, 0); } } })
                    ])
                ]));
            }
            updateWordCount();
        }

        // ---- document
        function renderDocument() {
            var v = variant(st.lesson) || {};
            var m = v.media;
            show($('tr-cm-doc-drop'), !m);
            show($('tr-cm-doc-state'), !!m);
            if (!m) { return; }
            var total = m.page_count || 0;
            var ready = m.pages_ready || 0;
            $('tr-cm-doc-meta').textContent = (m.original_name || 'Document') + ' · ' + plural(total, 'page', 'pages') + ' · ' + bytes(m.bytes);
            var dl = $('tr-cm-doc-download');
            dl.href = m.download_url || m.url;
            var strip = $('tr-cm-doc-pages');
            strip.textContent = '';
            (v.pages || []).slice(0, 12).forEach(function (p) {
                strip.appendChild(el('img', { src: p.url, alt: 'Page ' + p.n, loading: 'lazy', width: 96 }));
            });
            if ((v.pages || []).length > 12) { strip.appendChild(el('span', { class: 'align-self-center small text-muted text-nowrap px-2', text: '+ ' + ((v.pages || []).length - 12) + ' more' })); }
            var pending = total > 0 && ready < total;
            show($('tr-cm-doc-progress-wrap'), pending || st.rendering);
            if (pending) {
                $('tr-cm-doc-progress-text').textContent = 'Preparing pages ' + ready + '/' + total;
                $('tr-cm-doc-progress-bar').style.width = Math.round(100 * ready / Math.max(1, total)) + '%';
                if (!st.rendering) { resumePages(m.id); }
            }
            setCheckbox('allow_download');
        }

        function resumePages(mediaId) {
            if (!up || !st || st.rendering) { return; }
            var t = st;
            t.rendering = true;
            t.renderAbort = new AbortController();
            $('tr-cm-doc-resume').hidden = true;
            up.renderPages(mediaId, {
                signal: t.renderAbort.signal,
                onStage: function (s) {
                    if (t !== st) { return; }
                    if (s.total) {
                        $('tr-cm-doc-progress-text').textContent = 'Preparing pages ' + (s.ready || 0) + '/' + s.total;
                        $('tr-cm-doc-progress-bar').style.width = Math.round(100 * (s.ready || 0) / s.total) + '%';
                    }
                }
            }).then(function () {
                if (t !== st) { return; }
                t.rendering = false;
                refreshDetailQuietly();
            }, function (err) {
                if (t !== st) { return; }
                t.rendering = false;
                if (err && err.code === 'aborted') { return; }
                $('tr-cm-doc-progress-text').textContent = (err && err.message) || 'Preparing the pages stopped.';
                $('tr-cm-doc-resume').hidden = !(err && (err.code === 'network' || err.code === 'server'));
            });
        }

        /** lesson_get for display-only parts (pages, video check, quiz counts); never touches the store. */
        function refreshDetailQuietly() {
            if (!st || !st.lessonId) { return Promise.resolve(); }
            var t = st;
            return api.get('lesson_get', { lesson_id: st.lessonId }).then(function (d) {
                if (t !== st) { return; }
                // Keep the variant fields the author is editing; take everything display-only.
                st.lesson = d;
                renderFromDetail(false);
            }, function (err) {
                if (t === st && err && err.code === 'not_found') { showDeleted(); }
            });
        }

        // ---- video
        function currentVideoSource() {
            if (st.videoSource) { return st.videoSource; }
            var v = variant(st.lesson) || {};
            if (v.video && v.video.provider) { return v.video.provider; }
            // Another language with no video yet: start on the default language's source (below "Use the English video").
            var dv = !isDefaultLang() && st.lesson ? defaultVideo() : null;
            return dv ? dv.provider : 'youtube';
        }
        function renderVideo() {
            var src = currentVideoSource();
            $('tr-cm-vsrc').querySelectorAll('[role=radio]').forEach(function (b) {
                var on = b.getAttribute('data-src') === src;
                b.setAttribute('aria-checked', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
            });
            var isUpload = src === 'upload';
            content.querySelector('[data-vsrc="upload"]').hidden = !isUpload;
            content.querySelector('[data-vsrc="link"]').hidden = isUpload;
            var v = variant(st.lesson) || {};
            // upload
            var m = v.media && v.media.kind === 'video' ? v.media : null;
            show($('tr-cm-vid-drop'), isUpload && !m);
            show($('tr-cm-vid-state'), isUpload && !!m);
            if (m) {
                var player = $('tr-cm-vid-player');
                if (player.getAttribute('src') !== m.url) { player.setAttribute('src', m.url); }
                var probe = $('tr-cm-vid-probe');
                probe.textContent = '';
                var chips = [];
                if (m.duration_ms) { chips.push(ui.fmtDuration(m.duration_ms / 1000)); }
                if (m.width && m.height) { chips.push(m.width + '×' + m.height); }
                if (m.video_codec) { chips.push(PROBE_CODEC[m.video_codec] || m.video_codec); }
                chips.push(m.audio_codec ? 'Sound' : 'No sound');
                chips.push(bytes(m.bytes));
                chips.forEach(function (c) { probe.appendChild(el('span', { class: 'tr-chip', text: c })); });
                show($('tr-cm-vid-hevc'), (m.warnings || []).indexOf('hevc') !== -1);
                show($('tr-cm-vid-faststart'), (m.info || []).indexOf('not_faststart') !== -1);
            }
            renderCaptions(isUpload && !!m, v);
            renderVideoShare(v);
            // link
            if (!isUpload) {
                var vid = v.video && (v.video.provider === 'youtube' || v.video.provider === 'vimeo') ? v.video : null;
                var urlInput = $('tr-cm-vurl');
                if (document.activeElement !== urlInput && !st.linkEditing) {
                    urlInput.value = st.linkCheck ? (st.linkCheck.canonical_url || urlInput.value) : (vid && vid.provider === src ? (vid.url || '') : '');
                }
                urlInput.placeholder = src === 'vimeo' ? 'https://vimeo.com/…' : 'https://youtu.be/…';
                $('tr-cm-vurl-label').textContent = (src === 'vimeo' ? 'Vimeo' : 'YouTube') + ' link';
                renderVideoCard(vid && vid.provider === src ? vid : null);
            }
            var watch = $('tr-cm-watch');
            if (document.activeElement !== watch && !fieldDirty('min_watch_pct')) {
                watch.value = String(currentValue('min_watch_pct'));
            }
            $('tr-cm-watch-val').textContent = watch.value + '%';
        }

        /**
         * "Use the English video": on another language's tab with no video yet, one button points this language at
         * the default language's video (upload or YouTube / Vimeo). Only the video changes (lesson_use_video): the
         * translated title and text stay, and an uploaded video then shows this language's caption drop zone.
         */
        function defaultVideo() {
            var dv = variant(st.lesson, st.course.default_language) || {};
            var vid = dv.video && dv.video.provider ? dv.video : null;
            if (!vid) { return null; }
            if (vid.provider === 'upload') { return dv.media && dv.media.kind === 'video' ? vid : null; }
            return vid.ext_id ? vid : null;
        }
        function renderVideoShare(v) {
            var box = $('tr-cm-vshare');
            if (!box) { return; }
            var dvid = defaultVideo();
            var own = !!(v.video && v.video.provider && (v.video.provider !== 'upload' || (v.media && v.media.kind === 'video')));
            box.hidden = isDefaultLang() || !st.lessonId || st.readOnly || !dvid || own;
            if (box.hidden) { return; }
            var def = langName(st.course.default_language);
            var here = langName(st.lang);
            $('tr-cm-vshare-title').textContent = 'No ' + here + ' video yet';
            $('tr-cm-vshare-sub').textContent = dvid.provider === 'upload'
                ? 'Play the ' + def + ' video here' + (flags.captions ? ' and add ' + here + ' captions to it' : '') + '. Your ' + here + ' title and text stay as they are.'
                : 'Play the same ' + (dvid.provider === 'vimeo' ? 'Vimeo' : 'YouTube') + ' video here; learners turn on its own captions with CC. Your ' + here + ' title and text stay as they are.';
            $('tr-cm-vshare-btn').textContent = 'Use the ' + def + ' video';
        }
        function useDefaultVideo() {
            if (!st || !st.lessonId || st.readOnly || isDefaultLang()) { return; }
            var t = st;
            var btn = $('tr-cm-vshare-btn');
            btn.disabled = true;
            flushAll().then(function () {
                return api.post('lesson_use_video', { lesson_id: st.lessonId, from: st.course.default_language, to: st.lang });
            }).then(function (d) {
                btn.disabled = false;
                if (t !== st || !d) { return; }
                st.videoSource = null;   // show the source the variant now has
                st.linkCheck = null;
                st.changed = true;
                adoptDetail(d);
                var vid = (variant(d) || {}).video;
                ui.toast(vid && vid.provider === 'upload' && flags.captions
                    ? 'The ' + langName(st.lang) + ' version now plays the ' + langName(st.course.default_language) + ' video. Add ' + langName(st.lang) + ' captions below.'
                    : 'The ' + langName(st.lang) + ' version now plays the ' + langName(st.course.default_language) + ' video.', { type: 'success' });
            }, function (err) {
                btn.disabled = false;
                if (t !== st) { return; }
                ui.toast((err && err.message) || 'Could not use that video.', { type: 'error' });
            });
        }

        /** Closed captions of this language's uploaded video: a .vtt / .srt file (made plain WebVTT by the server). */
        function renderCaptions(on, v) {
            var box = $('tr-cm-cap');
            if (!box) { return; }
            box.hidden = !on || !flags.captions;
            if (box.hidden) { return; }
            var f = v.caption_file || null;
            $('tr-cm-cap-lang').textContent = st.course.languages && st.course.languages.length > 1 ? '· ' + langName(st.lang) : '';
            show($('tr-cm-cap-drop'), !f);
            show($('tr-cm-cap-state'), !!f);
            if (f) {
                $('tr-cm-cap-name').textContent = f.original_name || ('captions-' + f.id + '.vtt');
                var meta = [];
                if (f.duration_ms) { meta.push('captions run to ' + ui.fmtDuration(Math.round(f.duration_ms / 1000))); }
                meta.push(bytes(f.bytes));
                var vm = v.media && v.media.duration_ms ? v.media.duration_ms : 0;
                if (f.duration_ms && vm && f.duration_ms > vm + 2000) { meta.push('runs past the end of the video - check it is the right file'); }
                $('tr-cm-cap-meta').textContent = meta.join(' · ');
            }
        }

        function uploadCaption(file) {
            if (!up || !st || st.readOnly || !file) { return; }
            var t = st;
            var drop = $('tr-cm-cap-drop');
            var prog = drop.querySelector('.tr-drop__progress');
            show(drop, true);
            show($('tr-cm-cap-state'), false);
            $('tr-cm-cap-error').textContent = '';
            drop.classList.remove('is-error');
            drop.classList.add('is-busy');
            prog.textContent = 'Uploading…';
            t.uploads++;
            refreshFooter();
            up.upload(file, { purpose: 'lesson_caption', courseId: st.courseId, lessonId: st.lessonId || undefined, lang: st.lang }).then(function (d) {
                t.uploads--;
                drop.classList.remove('is-busy');
                prog.textContent = '';
                refreshFooter();
                if (t !== st || !d || !d.media) { return; }
                var v = variant(st.lesson) || {};
                st.lesson.variants = st.lesson.variants || {};
                st.lesson.variants[st.lang] = Object.assign({}, v, { caption_file: d.media });
                setField('caption_media_id', d.media.id, 0);
                renderCaptions(true, st.lesson.variants[st.lang]);
                var c = d.caption || {};
                var msg = 'Captions added' + (typeof c.cues === 'number' ? ': ' + plural(c.cues, 'caption', 'captions') : '') + (c.format === 'srt' ? ' (converted from SRT)' : '') + '.';
                ui.toast(msg, { type: 'success' });
                var w = d.media.warnings || [];
                if (w.indexOf('caption_markup_removed') !== -1 || w.indexOf('caption_links_removed') !== -1) {
                    ui.toast('Formatting, code and web links in the caption file were removed. Only the caption text is kept.', { type: 'warning' });
                }
                if (w.indexOf('caption_cues_skipped') !== -1) {
                    ui.toast('Some captions had no text or impossible times and were left out.', { type: 'warning' });
                }
            }, function (err) {
                t.uploads--;
                drop.classList.remove('is-busy');
                drop.classList.add('is-error');
                refreshFooter();
                if (t !== st) { return; }
                prog.textContent = (err && err.message) || 'Upload failed.';
                var had = (variant(st.lesson) || {}).caption_file;
                if (had) { show($('tr-cm-cap-state'), true); ui.toast(prog.textContent, { type: 'error' }); }
            });
        }

        function removeCaption() {
            if (!st || st.readOnly) { return; }
            var v = variant(st.lesson) || {};
            if (!v.caption_file) { return; }
            st.lesson.variants[st.lang] = Object.assign({}, v, { caption_file: null });
            setField('caption_media_id', null, 0);
            renderCaptions(true, st.lesson.variants[st.lang]);
        }

        function renderVideoCard(vid) {
            var card = $('tr-cm-vcard');
            var lc = st.linkCheck;
            var check = (vid && vid.check) || (lc && lc.check) || null;
            if (!vid && !lc) { card.hidden = true; $('tr-cm-vframe-wrap').hidden = true; return; }
            card.hidden = false;
            card.textContent = '';
            var title = (lc && lc.title) || (check && check.title) || 'Video';
            var author = (lc && lc.author) || (check && check.author) || '';
            var dur = (lc && lc.duration_s) || (check && (check.duration_s || check.meta_duration_s || check.play_duration_s)) || null;
            var thumb = (lc && lc.thumb_data_uri) || (check && check.thumb_url) || null;
            var status = (lc && lc.status) || (check && check.status) || null;
            var statusMsg = lc ? lc.status_message : (check ? check.status_message : null);
            var thumbBox = el('div', { class: 'tr-media-card__thumb' }, [
                thumb ? thumbImg(thumb) : icon('play'),
                dur ? el('span', { class: 'tr-media-card__dur', text: ui.fmtDuration(dur) }) : null
            ]);
            var chip;
            if (status === 'ok' || (check && check.verified_fresh)) {
                chip = el('span', { class: 'tr-chip tr-chip--ok' }, [icon('check-circle'), 'Link works']);
            } else if (status === 'error') {
                chip = el('span', { class: 'tr-chip tr-chip--warn' }, [icon('exclamation-triangle'), 'Could not reach the video service']);
            } else if (status) {
                var label = { private: 'Private', embed_disabled: 'Embedding is off', not_found: 'Not found', live: 'Live stream' }[status] || status;
                chip = el('span', { class: 'tr-chip tr-chip--err' }, [icon('times-circle'), label]);
            }
            card.appendChild(el('div', { class: 'tr-media-card' }, [
                thumbBox,
                el('div', { class: 'tr-media-card__body' }, [
                    el('div', { class: 'tr-media-card__title', text: title }),
                    el('div', { class: 'tr-media-card__meta', text: [author, dur ? ui.fmtDuration(dur) : 'Length: set when you play it'].filter(Boolean).join(' · ') }),
                    el('div', { class: 'tr-media-card__status' }, [chip || null, statusMsg ? el('span', { text: statusMsg }) : null])
                ])
            ]));
            renderFrame(vid, check);
        }

        /** The link-check thumbnail arrives as a data: URI (not stored); el() refuses data: URLs, so set it directly after a strict shape check. */
        function thumbImg(src) {
            var img = document.createElement('img');
            img.alt = '';
            if (/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/.test(src) || /^\/agent\/training_media\.php\?m=\d+$/.test(src)) { img.src = src; }
            return img;
        }

        function renderFrame(vid, check) {
            var wrap = $('tr-cm-vframe-wrap');
            var host = $('tr-cm-vframe-host');
            var cap = $('tr-cm-vframe-caption');
            var saved = vid && vid.ext_id ? vid : null;
            if (!saved) {
                wrap.hidden = !st.linkCheck;
                if (st.linkCheck) {
                    host.textContent = '';
                    setCaption(cap, 'wait', st.lessonId ? 'Saving the link…' : 'Give the content a title to save the link, then press play to confirm it works.');
                }
                return;
            }
            wrap.hidden = false;
            var src = '/agent/training_video_frame.php?p=' + encodeURIComponent(saved.provider) + '&id=' + encodeURIComponent(saved.ext_id) + (saved.hash ? '&h=' + encodeURIComponent(saved.hash) : '');
            var frame = host.querySelector('iframe');
            if (!frame || frame.getAttribute('src') !== src) {
                host.textContent = '';
                host.appendChild(el('iframe', {
                    class: 'tr-frame', src: src, title: 'Video check', allow: 'autoplay; encrypted-media; picture-in-picture',
                    referrerpolicy: 'strict-origin-when-cross-origin', loading: 'lazy'
                }));
            }
            // What the player just reported in this window wins; then the saved check.
            var lastErr = st.videoError && st.videoError.ext_id === saved.ext_id ? st.videoError : null;
            if (lastErr) {
                setCaption(cap, 'err', playerErrorText(lastErr.code, lastErr.message) + ' Press play again to re-check.');
            } else if (check && check.verified_fresh) {
                captionVerified(cap, check);
            } else if (check && check.last_error) {
                setCaption(cap, 'err', playerErrorText(check.last_error) + ' Press play again to re-check.');
            } else {
                setCaption(cap, 'wait', 'Press play once to confirm this video works.');
            }
        }

        /** The frame reports the player's plain-language message; a code alone (from a saved check) maps here. */
        var PLAYER_ERRORS = {   // same wording as js/training_video_embed.js errorMessage()
            yt_2: "This link doesn't point to a playable video. Check the link.",
            yt_5: "This video can't be played in this browser.",
            yt_100: 'This video was removed or is private. In YouTube Studio set Visibility to Unlisted.',
            yt_101: "The owner doesn't allow embedding. In YouTube Studio: Video › Show more › Allow embedding.",
            yt_150: "The owner doesn't allow embedding. In YouTube Studio: Video › Show more › Allow embedding.",
            vimeo_PrivacyError: 'This Vimeo video is private, or the link is missing its privacy code.',
            vimeo_NotFoundError: 'This Vimeo video was not found. Check the link.',
            api_load_failed: "The video player couldn't load. Check the internet connection and try again.",
            no_duration: "The player didn't report the video's length."
        };
        function playerErrorText(code, message) {
            if (message) { return /[.!?]$/.test(message) ? message : message + '.'; }
            return PLAYER_ERRORS[code] || 'The player reported a problem (' + code + ').';
        }

        function captionVerified(cap, check) {
            var ago = daysAgo(check.verified_at);
            var inherited = check.verified_at && st.openedAt && new Date(check.verified_at).getTime() < st.openedAt;
            var dur = check.play_duration_s || check.duration_s || check.meta_duration_s;
            var text = inherited && ago !== null
                ? 'Verified ' + (ago === 0 ? 'today' : plural(ago, 'day', 'days') + ' ago') + ' (same video)'
                : 'Verified' + (dur ? ' · ' + ui.fmtDuration(dur) : '') + (check.verified_by_name ? ' · by ' + check.verified_by_name : '') + (check.verified_at ? ', ' + fmtDate(check.verified_at) : '');
            setCaption(cap, 'ok', text);
        }

        function setCaption(cap, kind, text) {
            cap.className = 'tr-frame-caption' + (kind === 'ok' ? ' is-ok' : kind === 'err' ? ' is-err' : '');
            cap.textContent = '';
            cap.appendChild(icon(kind === 'ok' ? 'check-circle' : kind === 'err' ? 'exclamation-circle' : 'play-circle'));
            cap.appendChild(el('span', { text: text }));
        }

        function checkLink() {
            var input = $('tr-cm-vurl');
            var url = input.value.trim();
            var errEl = $('tr-cm-vurl-error');
            errEl.textContent = '';
            if (!url) { return; }
            if (st.linkChecking === url) { return; }
            st.linkChecking = url;
            var t = st;
            var btn = $('tr-cm-vurl-check');
            btn.disabled = true;
            btn.textContent = 'Checking…';
            api.post('video_link_check', { url: url }).then(function (r) {
                if (t !== st) { return; }
                st.linkChecking = null;
                st.linkEditing = false;
                btn.disabled = false;
                btn.textContent = 'Check link';
                var src = currentVideoSource();
                if (r.provider !== src) { st.videoSource = r.provider; }
                st.linkCheck = r;
                if (r.status === 'live') {
                    errEl.textContent = r.status_message || "Live streams and Premieres can't be used.";
                    renderVideo();
                    return;
                }
                var patch = { video_check_token: r.check_token };
                if (!E.name.value.trim() && r.title) {
                    E.name.value = r.title.slice(0, 200);
                    patch.title = E.name.value;
                }
                setFields(patch, 0);
                renderVideo();
            }, function (err) {
                if (t !== st) { return; }
                st.linkChecking = null;
                btn.disabled = false;
                btn.textContent = 'Check link';
                errEl.textContent = (err && ((err.fields && err.fields.url) || err.message)) || 'That link could not be checked.';
            });
        }

        var lastVideoMsg = { key: '', at: 0 };
        function onVideoMessage(type, payload) {
            if (!st || !st.lessonId || st.type !== 'video') { return; }
            var v = variant(st.lesson) || {};
            var vid = v.video;
            if (!vid || !vid.ext_id) { return; }
            if (payload && payload.ext_id && payload.ext_id !== vid.ext_id) { return; }
            // The check frame reports twice (postMessage to this window + the BroadcastChannel for
            // other tabs); act once.
            var key = type + ':' + vid.ext_id + ':' + ((payload && payload.error_code) || '');
            if (key === lastVideoMsg.key && Date.now() - lastVideoMsg.at < 3000) { return; }
            lastVideoMsg = { key: key, at: Date.now() };
            st.linkCheck = null;
            if (type === 'tr-video-verified') {
                st.videoError = null;
                ui.toast('Video verified.', { type: 'success' });
            } else {
                st.videoError = { ext_id: vid.ext_id, code: (payload && payload.error_code) || 'error', message: (payload && payload.message) || '' };
                var cap = $('tr-cm-vframe-caption');
                if (cap) { setCaption(cap, 'err', playerErrorText(st.videoError.code, st.videoError.message) + ' Press play again to re-check.'); }
            }
            refreshDetailQuietly();
        }

        // ---- image
        function renderImage() {
            var v = variant(st.lesson) || {};
            var m = v.media && v.media.kind === 'image' ? v.media : null;
            show($('tr-cm-img-drop'), !m);
            show($('tr-cm-img-state'), !!m);
            if (m) {
                var img = $('tr-cm-img');
                if (img.getAttribute('src') !== m.url) { img.src = m.url; }
                img.alt = v.caption || v.title || 'Lesson image';
                $('tr-cm-img-meta').textContent = [m.width && m.height ? m.width + '×' + m.height : '', bytes(m.bytes)].filter(Boolean).join(' · ');
            }
            var cap = $('tr-cm-caption');
            if (document.activeElement !== cap && !fieldDirty('caption')) { cap.value = v.caption || ''; }
        }

        // ---- acknowledgment
        function renderAck() {
            var v = variant(st.lesson) || {};
            var prev = $('tr-cm-ack-preview');
            prev.textContent = '';
            if (v.body_html) {
                prev.innerHTML = v.body_html; // server-purified statement (spec §6.0)
            } else {
                prev.appendChild(el('p', { class: 'text-muted mb-0', text: 'Write the statement above. It appears here once it is saved.' }));
            }
            setCheckbox('ack_require_signature');
            setCheckbox('ack_require_pin');
            $('tr-cm-ack-prev-sig').hidden = !currentValue('ack_require_signature');
            $('tr-cm-ack-prev-pin').hidden = !currentValue('ack_require_pin');
        }

        // ---- quiz (content of a Quiz lesson)
        function renderQuizPane() {
            var gate = $('tr-cm-quiz-gate');
            var host = $('tr-cm-quiz-host');
            var full = $('tr-cm-quiz-full');
            var examRow = $('tr-cm-exam-row');
            gate.hidden = !!st.lessonId;
            examRow.hidden = !st.lessonId || st.course.kind === 'document';
            full.hidden = !st.lessonId;
            if (st.lessonId) { full.href = '/agent/training_quiz.php?lesson_id=' + encodeURIComponent(st.lessonId); }
            var q = st.lesson && st.lesson.quiz;
            var exam = $('tr-cm-exam');
            if (!st.examBusy) { exam.checked = !!(q && q.role === 'exam'); }
            if (!st.lessonId) { host.textContent = ''; return; }
            mountBuilder('quiz', host);
        }

        function mountBuilder(slot, host) {
            if (st.mounts[slot]) { return; }
            host.textContent = '';
            var t = st;
            try {
                st.mounts[slot] = window.TrainingQuizBuilder.mount(host, {
                    lessonId: st.lessonId, courseId: st.courseId, lang: st.lang,
                    languages: st.course.languages || [st.lang], defaultLanguage: st.course.default_language,
                    lessonType: st.type, embedded: true,
                    // The builder's quiz_changed broadcast never reaches this window (a
                    // BroadcastChannel does not deliver to itself), so it reports here.
                    onChange: function (qz) { onQuizChange(t, qz); },
                    onSaveState: function (state) { onQuizSaveState(t, state); }
                });
            } catch (e) {
                st.mounts[slot] = null;
                host.appendChild(el('div', { class: 'tr-quiz-missing', text: 'The question builder could not start. Reload the page and try again.' }));
            }
        }

        function onQuizChange(t, qz) {
            if (t !== st || !qz || !st.lesson) { return; }
            var before = st.lesson.quiz;
            var moved = !before || before.id !== qz.id || before.question_count !== qz.question_count || before.role !== qz.role;
            if (moved) { st.changed = true; }
            st.lesson.quiz = Object.assign({}, before || {}, { id: qz.id, role: qz.role, question_count: qz.question_count });
            E.quizCount.textContent = String(qz.question_count || 0);
            if (!st.examBusy && st.type === 'quiz') { $('tr-cm-exam').checked = qz.role === 'exam'; }
            // "Add at least one question" and the like come from the server: ask again once the
            // questions settle, so the footer's "things to finish" never goes stale.
            if (moved || (st.lesson.issues || []).some(function (i) { return i.code === 'quiz_empty'; })) {
                if (!st.issuesSoon) { st.issuesSoon = ui.debounce(function () { if (t === st) { refreshDetailQuietly(); } }, 900); }
                st.issuesSoon();
            }
        }

        /** The embedded quiz builder saves on its own; its state shows in the footer while the lesson itself is idle. */
        function onQuizSaveState(t, state) {
            if (t !== st) { return; }
            var own = st.store ? st.store.state : 'idle';
            if (own !== 'idle' && own !== 'saved') { return; }
            if (state === 'saving' || state === 'saved') { renderSave(state); return; }
            if (state === 'error' || state === 'conflict' || state === 'offline') {
                E.save.setAttribute('data-state', state === 'offline' ? 'offline' : 'error');
                E.save.textContent = state === 'offline' ? 'Offline. Questions will be retried.' : (state === 'conflict' ? 'A question changed in another tab.' : 'A question was not saved. See the question card.');
            }
        }

        function unmountBuilder(slot) {
            var m = st && st.mounts[slot];
            if (m && typeof m.destroy === 'function') { try { m.destroy(); } catch (e) { /* ignore */ } }
            if (st) { st.mounts[slot] = null; }
        }

        function toggleExam() {
            var box = $('tr-cm-exam');
            var want = box.checked;
            st.examBusy = true;
            api.get('quiz_get', { lesson_id: st.lessonId }).then(function (quiz) {
                return api.post('quiz_update', { quiz_id: quiz.id, version: quiz.version, fields: { role: want ? 'exam' : 'standalone' } }).then(null, function (err) {
                    if (want && err && err.data && err.data.exam_lesson_id) {
                        return ui.confirmBar($('tr-cm-exam-confirm'), {
                            message: 'Another lesson is the final exam. Make this one the final exam instead?', confirmLabel: 'Make this the final exam'
                        }).then(function (ok) {
                            if (!ok) { throw { code: 'cancelled' }; }
                            return api.post('quiz_update', { quiz_id: quiz.id, version: quiz.version, fields: { role: 'exam' }, replace_exam: true });
                        });
                    }
                    throw err;
                });
            }).then(function () {
                st.examBusy = false;
                st.changed = true;
                if (st.lesson && st.lesson.quiz) { st.lesson.quiz.role = want ? 'exam' : 'standalone'; }
                var m = st.mounts.quiz;
                if (m && typeof m.refresh === 'function') { m.refresh(); }
                ui.toast(want ? 'This quiz is now the final exam.' : 'This quiz is no longer the final exam.');
                refreshDetailQuietly();
            }, function (err) {
                st.examBusy = false;
                box.checked = !want;
                if (err && err.code === 'cancelled') { return; }
                ui.toast((err && err.message) || 'Could not change the final exam.', { type: 'error' });
            });
        }

        // ---- quiz tab (quick check on content lessons)
        function renderQuizTab() {
            var q = st.lesson && st.lesson.quiz;
            E.quizCount.textContent = String(q ? (q.question_count || 0) : 0);
            if (st.type === 'quiz' || st.type === 'acknowledgment' || !st.type) { return; }
            var sw = $('tr-cm-check');
            var gate = $('tr-cm-check-gate');
            var host = $('tr-cm-check-host');
            var full = $('tr-cm-check-full');
            gate.hidden = !!st.lessonId;
            sw.disabled = !st.lessonId || st.readOnly;
            if (!st.checkBusy) { sw.checked = !!q; }
            full.hidden = !(q && st.lessonId);
            if (st.lessonId) { full.href = '/agent/training_quiz.php?lesson_id=' + encodeURIComponent(st.lessonId); }
            if (q && st.lessonId) {
                if (st.activeTab === 'quiz') { mountBuilder('check', host); }
            } else {
                unmountBuilder('check');
                host.textContent = '';
            }
        }

        function toggleCheck() {
            var sw = $('tr-cm-check');
            var want = sw.checked;
            if (want) {
                st.checkBusy = true;
                api.post('quiz_attach', { lesson_id: st.lessonId }).then(function (quiz) {
                    st.checkBusy = false;
                    st.changed = true;
                    st.lesson.quiz = { id: quiz.id, role: quiz.role, question_count: (quiz.questions || []).length, translated: {} };
                    renderQuizTab();
                    mountBuilder('check', $('tr-cm-check-host'));
                }, function (err) {
                    st.checkBusy = false;
                    sw.checked = false;
                    ui.toast((err && err.message) || 'Could not add the quick check.', { type: 'error' });
                });
                return;
            }
            ui.confirmBar($('tr-cm-pane-quiz'), {
                message: 'Remove the quick check? Its questions are kept and come back if you turn it on again.', confirmLabel: 'Remove', prepend: true
            }).then(function (ok) {
                if (!ok) { sw.checked = true; return; }
                st.checkBusy = true;
                api.post('quiz_detach', { lesson_id: st.lessonId }).then(function () {
                    st.checkBusy = false;
                    st.changed = true;
                    st.lesson.quiz = null;
                    unmountBuilder('check');
                    renderQuizTab();
                }, function (err) {
                    st.checkBusy = false;
                    sw.checked = true;
                    ui.toast((err && err.message) || 'Could not remove the quick check.', { type: 'error' });
                });
            });
        }

        // ---- resources
        function renderResources() {
            var list = $('tr-cm-res-list');
            var res = (st.lesson && st.lesson.resources) || [];
            E.resCount.textContent = String(res.length);
            $('tr-cm-res-gate').hidden = !!st.lessonId;
            show($('tr-cm-res-actions'), !!st.lessonId && !st.readOnly);
            $('tr-cm-res-empty').hidden = !st.lessonId || res.length > 0;
            if (st.resEditing) { return; }
            list.textContent = '';
            res.forEach(function (r) { list.appendChild(resourceRow(r)); });
            if (st.resSortable) { st.resSortable.destroy(); st.resSortable = null; }
            if (window.Sortable && res.length > 1 && !st.readOnly) {
                st.resSortable = window.Sortable.create(list, {
                    handle: '.tr-handle', animation: 150, forceFallback: true, fallbackClass: 'tr-sort-fallback',
                    onEnd: function () {
                        var ids = Array.prototype.map.call(list.children, function (li) { return parseInt(li.getAttribute('data-id'), 10); });
                        api.post('resources_reorder', { lesson_id: st.lessonId, ids: ids }).then(function () {
                            st.changed = true;
                            var byId = {};
                            st.lesson.resources.forEach(function (r) { byId[r.id] = r; });
                            st.lesson.resources = ids.map(function (id) { return byId[id]; }).filter(Boolean);
                        }, function (err) {
                            ui.toast((err && err.message) || 'Could not reorder.', { type: 'error' });
                            renderResources();
                        });
                    }
                });
            }
        }

        function resourceRow(r) {
            var langs = st.course.languages || [];
            var title = el('input', { type: 'text', class: 'tr-inline-input fw-medium', value: r.title, maxlength: '200', 'aria-label': 'Resource title', disabled: st.readOnly });
            title.addEventListener('focus', function () { st.resEditing = true; });
            title.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); title.blur(); }
                if (e.key === 'Escape') { e.stopPropagation(); title.value = r.title; title.blur(); }
            });
            title.addEventListener('blur', function () {
                st.resEditing = false;
                var v = title.value.trim();
                if (!v) { title.value = r.title; return; }
                if (v === r.title) { return; }
                updateResource(r, { title: v });
            });
            var langSel = null;
            if (langs.length > 1) {
                langSel = el('select', { class: 'form-select form-select-sm', 'aria-label': 'Language', disabled: st.readOnly });
                langSel.appendChild(el('option', { value: '', text: 'All languages' }));
                langs.forEach(function (l) { langSel.appendChild(el('option', { value: l, text: l.toUpperCase() + ' only' })); });
                langSel.value = r.lang || '';
                langSel.addEventListener('change', function () { updateResource(r, { lang: langSel.value || null }); });
            }
            var href = r.kind === 'link' ? r.url : (r.media && (r.media.download_url || r.media.url));
            var meta = r.kind === 'link' ? r.url : (r.media ? [r.media.original_name, bytes(r.media.bytes)].filter(Boolean).join(' · ') : 'File missing');
            return el('li', { class: 'tr-res-row', dataset: { id: r.id } }, [
                st.readOnly ? null : el('button', { type: 'button', class: 'tr-handle', 'aria-label': 'Drag to reorder ' + r.title }, [icon('grip-vertical')]),
                el('span', { class: 'tr-res-row__icon', 'aria-hidden': 'true' }, [icon(r.kind === 'link' ? 'link' : (r.media && r.media.kind === 'pdf' ? 'file-pdf' : (r.media && r.media.kind === 'image' ? 'image' : 'file')))]),
                el('div', { class: 'tr-res-row__title' }, [title, el('div', { class: 'tr-res-row__meta', text: meta || '' })]),
                langSel,
                href ? el('a', { class: 'tr-icon-btn', href: href, target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'Open ' + r.title, title: 'Open' }, [icon('external-link-alt')]) : null,
                st.readOnly ? null : el('button', { type: 'button', class: 'tr-icon-btn', 'aria-label': 'Remove ' + r.title, title: 'Remove', on: { click: function () { deleteResource(r); } } }, [icon('trash-alt')])
            ]);
        }

        function updateResource(r, fields) {
            api.post('resource_update', { resource_id: r.id, fields: fields }).then(function (d) {
                st.changed = true;
                Object.assign(r, d || fields);
            }, function (err) {
                ui.toast((err && ((err.fields && (err.fields.title || err.fields.lang)) || err.message)) || 'Could not save the resource.', { type: 'error' });
                renderResources();
            });
        }
        function deleteResource(r) {
            api.post('resource_delete', { resource_id: r.id }).then(function () {
                st.changed = true;
                st.lesson.resources = st.lesson.resources.filter(function (x) { return x.id !== r.id; });
                renderResources();
                ui.toast('Resource removed.');
            }, function (err) { ui.toast((err && err.message) || 'Could not remove it.', { type: 'error' }); });
        }
        function addResource(body) {
            return api.post('resource_add', Object.assign({ lesson_id: st.lessonId }, body)).then(function (r) {
                st.changed = true;
                st.lesson.resources = (st.lesson.resources || []).concat([r]);
                renderResources();
                return r;
            });
        }

        // ---- settings (lesson-level fields)
        function currentValue(f) {
            if (has(st.preCreate, f)) { return st.preCreate[f]; }
            if (st.store && st.store.isFieldDirty(f)) {
                var p = st.store.pending;
                if (has(p, f)) { return p[f]; }
                if (st.store.inflight && has(st.store.inflight, f)) { return st.store.inflight[f]; }
                var failed = st.store.failedFields();
                if (has(failed, f)) { return failed[f]; }
            }
            var d = st.lesson || blankLesson();
            return d[f];
        }
        function setCheckbox(f) {
            content.querySelectorAll('[data-tr-lfield="' + f + '"]').forEach(function (inp) {
                if (inp.type === 'checkbox') { inp.checked = !!currentValue(f); }
                else if (document.activeElement !== inp) { inp.value = String(currentValue(f)); }
                inp.disabled = st.readOnly;
            });
        }
        function renderSettings() {
            ['required', 'allow_download', 'preview_enabled', 'ack_require_signature', 'ack_require_pin', 'min_watch_pct'].forEach(setCheckbox);
            var d = st.lesson || blankLesson();
            // duration
            var autoS = d.duration_auto_s || 0;
            var override = has(st.preCreate, 'duration_s') ? st.preCreate.duration_s : (d.duration_source === 'override' ? d.duration_s : null);
            var durInput = $('tr-cm-dur');
            if (document.activeElement !== durInput && !fieldDirty('duration_s')) { durInput.value = override ? ui.fmtDuration(override) : ''; }
            durInput.placeholder = autoS ? ui.fmtDuration(autoS) : 'mm:ss';
            var note = DURATION_NOTE[autoSource(d)] || '';
            var ltype = d.type || st.type;
            $('tr-cm-dur-auto').textContent = autoS ? 'Auto: ' + fmtLength(autoS, ltype) + (note ? ' (' + note + ')' : '') : 'Auto: not known yet';
            $('tr-cm-dur-reset').hidden = !override;
            $('tr-cm-aside-dur').textContent = (override || autoS) ? fmtLength(override || autoS, ltype) : '—';
            $('tr-cm-aside-dur-note').textContent = override ? '(set by you)' : (DURATION_NOTE[autoSource(d)] ? '(' + DURATION_NOTE[autoSource(d)] + ')' : '');
            // responsible
            $('tr-cm-aside-resp').textContent = d.responsible_name || 'Nobody yet';
            if (respSelect && !respSelect.isFocused && !fieldDirty('responsible_user_id')) {
                respSelect.clear(true);
                if (d.responsible_user_id) {
                    respSelect.addOption({ id: String(d.responsible_user_id), name: d.responsible_name || ('User #' + d.responsible_user_id), email: '' });
                    respSelect.setValue(String(d.responsible_user_id), true);
                }
            }
            // section
            var sec = $('tr-cm-section');
            if (document.activeElement !== sec) {
                sec.textContent = '';
                sec.appendChild(el('option', { value: '', text: 'No section' }));
                (st.sections || []).forEach(function (s) { sec.appendChild(el('option', { value: String(s.id), text: s.title || 'Untitled section' })); });
                var sid = currentValue('section_id');
                sec.value = sid ? String(sid) : '';
            }
            sec.disabled = st.readOnly || st.course.kind === 'document';
        }
        function autoSource(d) {
            if (d.duration_source !== 'override') { return d.duration_source; }
            return ({ video: 'video', document: 'pages', article: 'words', image: 'image', acknowledgment: 'ack', quiz: 'quiz' })[d.type] || 'none';
        }

        // =============================================================================== issues + footer
        function renderIssues() {
            var issues = (st.lesson && st.lesson.issues) || [];
            E.issues.hidden = !st.lessonId || issues.length === 0;
            E.issuesBtn.textContent = '';
            E.issuesBtn.appendChild(icon('list-ul', 'me-1'));
            E.issuesBtn.appendChild(document.createTextNode(plural(issues.length, 'thing', 'things') + ' to finish'));
            E.issuesMenu.textContent = '';
            E.issuesMenu.appendChild(el('h6', { class: 'dropdown-header', text: 'Before this can be published' }));
            issues.forEach(function (i) {
                E.issuesMenu.appendChild(el('button', { type: 'button', class: 'dropdown-item tr-issue--todo', on: { click: function () { gotoIssue(i); } } }, [
                    el('span', { class: 'tr-issue__text' }, [
                        el('span', { text: i.message || i.code }),
                        i.lang ? el('span', { class: 'tr-issue__where', text: langName(i.lang) }) : null
                    ])
                ]));
            });
        }
        function gotoIssue(i) {
            if (i.lang && i.lang !== st.lang && (st.course.languages || []).indexOf(i.lang) !== -1) { switchLang(i.lang); }
            if (i.code === 'title_missing') { showTab('content'); E.name.focus(); return; }
            showTab('content');
        }

        function refreshFooter() {
            if (!st) { return; }
            var busy = st.uploads > 0 || st.htmlBusy > 0;
            E.done.disabled = st.uploads > 0;
            E.again.disabled = st.uploads > 0 || st.course.kind === 'document';
            E.again.hidden = st.course.kind === 'document';
            E.done.title = st.uploads > 0 ? 'Wait for the upload to finish' : '';
            E.preview.hidden = !st.lessonId;
            E.del.hidden = !st.lessonId || st.readOnly || (st.course.kind === 'document' && st.type === 'acknowledgment');
            E.done.textContent = busy ? 'Saving…' : 'Done';
        }

        // =============================================================================== panels (in-window)
        var panelOnClose = null;
        var panelReturn = null;
        function focusables(root) {
            return Array.prototype.filter.call(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'), function (n) {
                return !n.closest('[hidden]') && n.getClientRects().length > 0;
            });
        }
        function openPanel(title, sub, bodyNodes, footerNodes, onClose) {
            if (E.panel.hidden) { panelReturn = document.activeElement; }
            E.panelTitle.textContent = title;
            E.panelSub.textContent = sub || '';
            E.panelBody.textContent = '';
            (bodyNodes || []).forEach(function (n) { if (n) { E.panelBody.appendChild(n); } });
            E.panelFooter.textContent = '';
            (footerNodes || []).forEach(function (n) { if (n) { E.panelFooter.appendChild(n); } });
            E.panelFooter.hidden = !(footerNodes && footerNodes.length);
            E.panel.hidden = false;
            E.scrim.hidden = false;
            panelOnClose = onClose || null;
            var first = E.panelBody.querySelector('input, button, select, textarea');
            (first || E.panelClose).focus();
        }
        function closePanel() {
            if (E.panel.hidden) { return; }
            E.panel.hidden = true;
            E.scrim.hidden = true;
            var fn = panelOnClose;
            panelOnClose = null;
            var back = panelReturn;
            panelReturn = null;
            if (fn) { fn(); }
            // Focus goes back to what opened the panel (Import from KB, Re-import), never to <body>.
            if (st && back && document.body.contains(back) && focusables(content).indexOf(back) !== -1) { back.focus(); }
            else if (st && modalEl.classList.contains('show')) { E.close.focus(); }
        }
        E.panelClose.addEventListener('click', closePanel);
        E.scrim.addEventListener('click', closePanel);
        // The panel is a modal dialog inside the window: Tab and Shift+Tab wrap inside it.
        E.panel.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') { return; }
            var f = focusables(E.panel);
            if (!f.length) { return; }
            var i = f.indexOf(document.activeElement);
            if (e.shiftKey && (i <= 0)) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && (i === -1 || i === f.length - 1)) { e.preventDefault(); f[0].focus(); }
        });
        content.addEventListener('focusin', function (e) {
            if (!E.panel.hidden && !E.panel.contains(e.target)) {
                var f = focusables(E.panel);
                (f[0] || E.panelClose).focus();
            }
        });

        // ---- KB import panel
        function openKbPanel() {
            var input = el('input', { type: 'search', class: 'form-control', placeholder: 'Search Knowledge Base articles', 'aria-label': 'Search Knowledge Base', autocomplete: 'off', maxlength: '200' });
            var status = el('div', { class: 'small text-muted mt-2', role: 'status', text: 'Type at least 2 characters.' });
            var list = el('ul', { class: 'tr-kb-results', role: 'listbox', 'aria-label': 'Articles' });
            var chosen = null;
            var importBtn = el('button', { type: 'button', class: 'btn btn-primary', text: 'Import', disabled: true });
            var seq = 0;
            var search = ui.debounce(function () {
                var q = input.value.trim();
                list.textContent = '';
                chosen = null;
                importBtn.disabled = true;
                if (q.length < 2) { status.textContent = 'Type at least 2 characters.'; return; }
                var my = ++seq;
                status.textContent = 'Searching…';
                api.get('kb_search', { q: q }).then(function (d) {
                    if (my !== seq) { return; }
                    var arts = d.articles || [];
                    status.textContent = arts.length ? plural(arts.length, 'article', 'articles') : 'No articles match.';
                    arts.forEach(function (a) {
                        var b = el('button', { type: 'button', class: 'tr-kb-result', role: 'option', 'aria-selected': 'false' }, [
                            el('span', { class: 'tr-kb-result__title', text: a.title }),
                            el('span', { class: 'tr-kb-result__meta', text: [a.department, a.updated_at ? 'Updated ' + fmtDate(a.updated_at) : ''].filter(Boolean).join(' · ') }),
                            a.excerpt ? el('span', { class: 'tr-kb-result__excerpt', text: a.excerpt }) : null
                        ]);
                        b.addEventListener('click', function () {
                            list.querySelectorAll('[role=option]').forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
                            b.setAttribute('aria-selected', 'true');
                            chosen = a;
                            importBtn.disabled = false;
                        });
                        b.addEventListener('dblclick', function () { chosen = a; importBtn.click(); });
                        list.appendChild(el('li', {}, [b]));
                    });
                }, function (err) {
                    if (my !== seq) { return; }
                    status.textContent = (err && err.message) || 'Search failed.';
                });
            }, 250);
            input.addEventListener('input', search);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search.flush(); } });
            importBtn.addEventListener('click', function () {
                if (!chosen) { return; }
                var a = chosen;
                var hasText = (variant(st.lesson) || {}).body_html || (editorFor('body') && editorFor('body').getContent().trim());
                var go = function () { closePanel(); kbImport(a.id, a.title, false); };
                if (hasText) {
                    ui.confirmBar(E.panelBody, { message: 'Replace the current text with "' + a.title + '"?', confirmLabel: 'Replace', danger: true, prepend: true }).then(function (ok) { if (ok) { go(); } });
                } else {
                    go();
                }
            });
            openPanel('Import from the Knowledge Base', 'A copy of the article, with its pictures. Later KB edits show as "KB changed".',
                [input, status, list],
                [el('button', { type: 'button', class: 'btn btn-link', text: 'Cancel', on: { click: closePanel } }), importBtn]);
        }

        function ensureCreated(titleHint) {
            if (st.lessonId) { return flushAll(); }
            if (!E.name.value.trim() && titleHint) { E.name.value = titleHint.slice(0, 200); }
            if (!E.name.value.trim()) { E.name.value = 'Untitled'; }
            setField('title', E.name.value.trim(), 0);
            return flushAll();
        }

        function kbImport(articleId, title, confirmEdits) {
            var t = st;
            alertBox('kb', 'alert-info', [el('span', { class: 'spinner-border spinner-border-sm', 'aria-hidden': 'true' }), el('span', { text: 'Importing "' + (title || 'article') + '"…' })]);
            ensureCreated(title).then(function () {
                if (!st.lessonId) { throw { message: 'Give the content a title first.' }; }
                var body = { lesson_id: st.lessonId, version: st.store.version, lang: st.lang, kb_article_id: articleId };
                if (confirmEdits) { body.confirm_overwrite_edits = true; }
                return api.post('kb_import', body);
            }).then(function (d) {
                if (t !== st) { return; }
                clearAlert('kb');
                st.changed = true;
                adoptDetail(d.lesson);
                (d.warnings || []).forEach(function (w) { ui.toast(typeof w === 'string' ? w : (w.message || ''), { type: 'warning' }); });
                ui.toast('Imported from the Knowledge Base.', { type: 'success' });
            }, function (err) {
                if (t !== st) { return; }
                clearAlert('kb');
                if (err && err.code === 'kb_local_edits' && err.data) {
                    openComparePanel(articleId, title, err.data);
                    return;
                }
                if (err && err.code === 'conflict') {
                    alertBox('kb', 'alert-warning', [el('span', { class: 'me-auto', text: 'This content changed in another tab. Reload it, then import again.' }),
                        el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark', text: 'Reload lesson', on: { click: function () { clearAlert('kb'); reloadLesson(); } } })]);
                    return;
                }
                alertBox('kb', 'alert-danger', [icon('exclamation-triangle'), el('span', { class: 'me-auto', text: (err && err.message) || 'The article could not be imported.' })]);
            });
        }

        function openComparePanel(articleId, title, data) {
            var cur = el('div', { class: 'tr-article-box' });
            var imp = el('div', { class: 'tr-article-box' });
            if (data.current_html) { cur.innerHTML = data.current_html; } // server-purified body_html (spec §6.0)
            if (data.imported_html) { imp.innerHTML = data.imported_html; } // server-purified import (spec §6.0)
            openPanel('Replace your edits?', 'The text was changed here since it was imported. Re-importing replaces those changes.', [
                el('div', { class: 'tr-compare' }, [
                    el('div', { class: 'tr-compare__col' }, [el('div', { class: 'tr-compare__label', text: 'Current (your edits)' }), cur]),
                    el('div', { class: 'tr-compare__col' }, [el('div', { class: 'tr-compare__label', text: 'From the KB now' }), imp])
                ])
            ], [
                el('button', { type: 'button', class: 'btn btn-link', text: 'Keep my edits', on: { click: closePanel } }),
                el('button', { type: 'button', class: 'btn btn-danger', text: 'Replace my edits', on: { click: function () { closePanel(); kbImport(articleId, title || data.imported_title, true); } } })
            ]);
            E.panel.classList.add('tr-panel--wide');
            panelOnClose = function () { E.panel.classList.remove('tr-panel--wide'); };
        }

        // ---- DOCX import
        var docxInput = el('input', { type: 'file', hidden: true, accept: up ? up.accepts('docx_import') : '.docx', tabindex: '-1', 'aria-hidden': 'true' });
        content.appendChild(docxInput);
        docxInput.addEventListener('change', function () {
            var f = docxInput.files && docxInput.files[0];
            docxInput.value = '';
            if (f) { importDocx(f); }
        });
        function importDocx(file) {
            if (!up) { return; }
            var status = $('tr-cm-docx-status');
            var t = st;
            status.className = 'small text-muted';
            status.textContent = 'Importing ' + file.name + '…';
            t.uploads++;
            refreshFooter();
            up.upload(file, { purpose: 'docx_import', courseId: st.courseId, lessonId: st.lessonId || undefined }).then(function (d) {
                t.uploads--;
                refreshFooter();
                if (t !== st) { return; }
                status.textContent = '';
                var apply = function () {
                    initEditor('body').then(function () {
                        setEditorContent('body', d.html || '');
                        st.editorDirty.body = true;
                        if (!E.name.value.trim()) {
                            E.name.value = fileTitle(file.name);
                            setFields({ title: E.name.value, body_html: d.html || null }, 0);
                        } else {
                            setField('body_html', d.html || null, 0);
                        }
                        st.editorDirty.body = false;
                    });
                    (d.warnings || []).forEach(function (w) { ui.toast(typeof w === 'string' ? w : (w.message || ''), { type: 'warning' }); });
                };
                var ed = editorFor('body');
                if (ed && ed.getContent().trim()) {
                    ui.confirmBar($('tr-cm-pane-content').querySelector('[data-pane="article"]'), {
                        message: 'Replace the current text with "' + file.name + '"?', confirmLabel: 'Replace', danger: true, prepend: true
                    }).then(function (ok) { if (ok) { apply(); } });
                } else {
                    apply();
                }
            }, function (err) {
                t.uploads--;
                refreshFooter();
                if (t !== st) { return; }
                status.className = 'small text-danger';
                status.textContent = (err && err.message) || 'The Word file could not be imported.';
            });
        }

        // =============================================================================== uploads (document / video / image / thumb)
        function uploadMain(file, purpose) {
            if (!up || !st) { return; }
            var type = FILE_PURPOSE[purpose];
            var t = st;
            var dropId = { lesson_document: 'tr-cm-doc-drop', lesson_video: 'tr-cm-vid-drop', lesson_image: 'tr-cm-img-drop' }[purpose];
            var drop = $(dropId);
            var prog = drop.querySelector('.tr-drop__progress');
            if (type === 'document' || type === 'video' || type === 'image') {
                show(drop, true);
                if (type === 'document') { show($('tr-cm-doc-state'), false); }
                if (type === 'video') { show($('tr-cm-vid-state'), false); }
                if (type === 'image') { show($('tr-cm-img-state'), false); }
            }
            drop.classList.remove('is-error');
            drop.classList.add('is-busy');
            prog.textContent = 'Uploading…';
            t.uploads++;
            refreshFooter();
            up.upload(file, {
                purpose: purpose, courseId: st.courseId, lessonId: st.lessonId || undefined, lang: st.lang, waitForPages: false,
                onProgress: function (p) { prog.textContent = 'Uploading ' + Math.round(p.fraction * 100) + '%'; },
                onStage: function (s) { if (s && s.text) { prog.textContent = s.text; } }
            }).then(function (d) {
                t.uploads--;
                drop.classList.remove('is-busy');
                prog.textContent = '';
                refreshFooter();
                if (t !== st) { return; }
                var m = d.media;
                if (!m) { return; }
                if ((m.info || []).indexOf('pdf_owner_restricted') !== -1) { $('tr-cm-doc-restricted').hidden = false; }
                (m.warnings || []).forEach(function (w) { if (w === 'hevc') { $('tr-cm-vid-hevc').hidden = false; } });
                // Show the file at once; the save follows.
                var v = variant(st.lesson) || {};
                if (!st.lesson) { st.lesson = blankLesson(); }
                st.lesson.variants = st.lesson.variants || {};
                st.lesson.variants[st.lang] = Object.assign({}, v, { media: m, pages: v.pages || null });
                if (type === 'video') { st.videoSource = 'upload'; }
                var patch = { media_id: m.id };
                if (!E.name.value.trim()) {
                    E.name.value = fileTitle(file.name);
                    patch.title = E.name.value;
                }
                setFields(patch, 0);
                renderFromDetail(false);
                if (type === 'video' && !currentValue('thumb_media_id')) { st.grabPoster = true; }
            }, function (err) {
                t.uploads--;
                drop.classList.remove('is-busy');
                drop.classList.add('is-error');
                refreshFooter();
                if (t !== st) { return; }
                prog.textContent = (err && err.message) || 'Upload failed.';
                // A failed Replace keeps showing the file that is still saved.
                var had = (variant(st.lesson) || {}).media;
                if (had) {
                    show($(type === 'document' ? 'tr-cm-doc-state' : type === 'video' ? 'tr-cm-vid-state' : 'tr-cm-img-state'), true);
                    ui.toast(prog.textContent, { type: 'error' });
                }
            });
        }

        function setupDropzones() {
            if (!up || dropzones.doc) { return; }
            dropzones.doc = up.dropzone($('tr-cm-doc-drop'), { purpose: 'lesson_document', onFiles: function (files) { uploadMain(files[0], 'lesson_document'); } });
            dropzones.vid = up.dropzone($('tr-cm-vid-drop'), { purpose: 'lesson_video', onFiles: function (files) { uploadMain(files[0], 'lesson_video'); } });
            dropzones.img = up.dropzone($('tr-cm-img-drop'), { purpose: 'lesson_image', onFiles: function (files) { uploadMain(files[0], 'lesson_image'); } });
            dropzones.thumb = up.dropzone(E.thumb, {
                purpose: 'lesson_thumb', button: E.thumbUpload,
                onFiles: function (files) { uploadThumb(files[0]); }
            });
            if ($('tr-cm-cap-drop')) {
                dropzones.cap = up.dropzone($('tr-cm-cap-drop'), { purpose: 'lesson_caption', onFiles: function (files) { uploadCaption(files[0]); } });
                $('tr-cm-cap-replace').addEventListener('click', function () { dropzones.cap.open(); });
                $('tr-cm-cap-remove').addEventListener('click', removeCaption);
            }
            $('tr-cm-doc-replace').addEventListener('click', function () { dropzones.doc.open(); });
            $('tr-cm-img-replace').addEventListener('click', function () { dropzones.img.open(); });
        }

        function uploadThumb(file, silent) {
            if (!up || !st || !st.type) { return Promise.resolve(); }
            var t = st;
            E.thumbProgress.textContent = 'Uploading…';
            t.uploads++;
            refreshFooter();
            return up.upload(file, {
                purpose: 'lesson_thumb', courseId: st.courseId, lessonId: st.lessonId || undefined,
                onProgress: function (p) { E.thumbProgress.textContent = Math.round(p.fraction * 100) + '%'; }
            }).then(function (d) {
                t.uploads--;
                refreshFooter();
                E.thumbProgress.textContent = '';
                if (t !== st) { return; }
                st.thumbUrl = d.media.url;
                setField('thumb_media_id', d.media.id, 0);
                renderThumb(d.media.url);
            }, function (err) {
                t.uploads--;
                refreshFooter();
                E.thumbProgress.textContent = '';
                if (!silent) { ui.toast((err && err.message) || 'The thumbnail could not be uploaded.', { type: 'error' }); }
            });
        }

        /** An optional poster frame from an uploaded video when no thumbnail is set (best effort). */
        function grabPoster() {
            var player = $('tr-cm-vid-player');
            if (!st || !st.grabPoster || !player || currentValue('thumb_media_id')) { return; }
            st.grabPoster = false;
            try {
                var w = player.videoWidth;
                var h = player.videoHeight;
                if (!w || !h) { return; }
                var scale = Math.min(1, 1280 / w);
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(w * scale);
                canvas.height = Math.round(h * scale);
                canvas.getContext('2d').drawImage(player, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) {
                    if (blob && st && !currentValue('thumb_media_id')) {
                        try { uploadThumb(new File([blob], 'poster.jpg', { type: 'image/jpeg' }), true); } catch (e) { /* File() unsupported */ }
                    }
                }, 'image/jpeg', 0.85);
            } catch (e) { /* codec or security error: skip the poster */ }
        }
        $('tr-cm-vid-player').addEventListener('loadeddata', function () {
            var p = $('tr-cm-vid-player');
            if (st && st.grabPoster && p.duration > 2) {
                p.addEventListener('seeked', function once() { p.removeEventListener('seeked', once); grabPoster(); });
                try { p.currentTime = Math.min(1, p.duration / 4); } catch (e) { grabPoster(); }
            } else {
                grabPoster();
            }
        });

        /** Files dropped anywhere on the window: pick the type from the file (spec §5.4). */
        function onWindowFiles(files) {
            var f = files[0];
            if (!f || !up || st.readOnly) { return; }
            var inf = up.inferPurpose(f);
            if (!inf.purpose) { ui.toast(inf.message || 'That file cannot be used here.', { type: 'warning' }); return; }
            var type = FILE_PURPOSE[inf.purpose];
            if (!st.type) {
                if (allowedTypes().indexOf(type) === -1) { ui.toast('A required document takes a PDF or a Word file.', { type: 'warning' }); return; }
                chooseType(type);
            } else if (st.type !== type) {
                ui.toast('This is ' + TYPES[st.type].label.toLowerCase() + ' content. Change the type first to use that file.', { type: 'warning' });
                return;
            }
            if (inf.purpose === 'docx_import') { importDocx(f); } else { uploadMain(f, inf.purpose); }
        }
        var depth = 0;
        content.addEventListener('dragenter', function (e) { if (st && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { depth++; e.preventDefault(); } });
        content.addEventListener('dragover', function (e) { if (st && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { e.preventDefault(); } });
        content.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); });
        content.addEventListener('drop', function (e) {
            depth = 0;
            if (!st || e.defaultPrevented || !e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) { return; }
            e.preventDefault();
            onWindowFiles(e.dataTransfer.files);
        });

        // =============================================================================== responsible (TomSelect)
        function setupResponsible() {
            if (respSelect || typeof window.TomSelect !== 'function') { return; }
            respSelect = new window.TomSelect($('tr-cm-resp'), {
                valueField: 'id', labelField: 'name', searchField: ['name', 'email'], maxItems: 1, maxOptions: 20,
                dropdownParent: content, preload: 'focus', allowEmptyOption: true, placeholder: 'Choose a person',
                load: function (q, cb) {
                    api.get('user_search', { q: q || '' }).then(function (d) {
                        cb((d.users || []).map(function (u) { return { id: String(u.id), name: u.name, email: u.email }; }));
                    }, function () { cb(); });
                },
                render: {
                    option: function (d, escape) { return '<div>' + escape(d.name) + (d.email ? ' <span class="text-muted small">' + escape(d.email) + '</span>' : '') + '</div>'; },
                    item: function (d, escape) { return '<div>' + escape(d.name) + '</div>'; }
                },
                onChange: function (v) {
                    if (!st || st.fillingResp) { return; }
                    var id = v ? parseInt(v, 10) : null;
                    setField('responsible_user_id', id, 0);
                    var o = id ? respSelect.options[String(id)] : null;
                    if (st.lesson) { st.lesson.responsible_name = o ? o.name : null; st.lesson.responsible_user_id = id; }
                    $('tr-cm-aside-resp').textContent = o ? o.name : 'Nobody yet';
                }
            });
        }

        // =============================================================================== tabs
        function showTab(name) {
            var btn = E.tabs.querySelector('[data-tab="' + name + '"]');
            if (!btn || btn.disabled) { return; }
            window.bootstrap.Tab.getOrCreateInstance(btn).show();
        }
        E.tabs.addEventListener('shown.bs.tab', function (e) {
            if (!st) { return; }
            st.activeTab = e.target.getAttribute('data-tab');
            if (st.activeTab === 'quiz') { renderQuizTab(); }
            if (st.activeTab === 'description') { initEditor('desc'); }
            E.body.scrollTop = 0;
        });
        content.querySelectorAll('[data-tr-cm-tab]').forEach(function (b) {
            b.addEventListener('click', function () { showTab(b.getAttribute('data-tr-cm-tab')); });
        });

        // =============================================================================== wiring (once)
        E.types.addEventListener('click', function (e) {
            var b = e.target.closest('[data-type]');
            if (b && !b.disabled) { chooseType(b.getAttribute('data-type')); }
        });
        E.types.addEventListener('keydown', function (e) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) === -1) { return; }
            var btns = Array.prototype.filter.call(E.types.querySelectorAll('[role=radio]'), function (b) { return !b.disabled; });
            var i = btns.indexOf(document.activeElement);
            if (i === -1) { return; }
            e.preventDefault();
            var next = btns[(i + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : btns.length - 1)) % btns.length];
            next.focus();
        });
        E.typepillMenu.addEventListener('click', function (e) {
            var b = e.target.closest('[data-type]');
            if (b) { changeType(b.getAttribute('data-type')); }
        });

        E.name.addEventListener('input', function () {
            if (!st) { return; }
            E.nameErr.textContent = '';
            setField('title', E.name.value.trim(), 600);
            if (!st.lessonId && !st.type) { renderSave('draft'); }
        });
        E.name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (st && st.store) { st.store.flush(); } } });

        E.tagAdd.addEventListener('click', function () {
            E.tagInput.hidden = false;
            E.tagAdd.hidden = true;
            E.tagInput.value = '';
            E.tagInput.focus();
        });
        function closeTagInput(commit) {
            if (commit) { addTag(E.tagInput.value); }
            E.tagInput.value = '';
            E.tagInput.hidden = true;
            E.tagAdd.hidden = false;
        }
        E.tagInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(E.tagInput.value); E.tagInput.value = ''; }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeTagInput(false); E.tagAdd.focus(); }
        });
        E.tagInput.addEventListener('blur', function () { closeTagInput(true); });

        E.thumbRemove.addEventListener('click', function () { st.thumbUrl = null; setField('thumb_media_id', null, 0); if (st.lesson) { st.lesson.thumb_media_id = null; } renderThumb(); });

        content.querySelectorAll('[data-tr-lfield]').forEach(function (inp) {
            var f = inp.getAttribute('data-tr-lfield');
            var handler = function () {
                if (!st) { return; }
                var v;
                if (inp.type === 'checkbox') { v = inp.checked; } else {
                    v = parseInt(inp.value, 10);
                    if (isNaN(v)) { return; }
                    v = Math.max(0, Math.min(100, v));
                }
                setField(f, v, inp.type === 'range' ? 400 : 0);
                if (st.lesson) { st.lesson[f] = v; }
                content.querySelectorAll('[data-tr-lfield="' + f + '"]').forEach(function (o) {
                    if (o === inp) { return; }
                    if (o.type === 'checkbox') { o.checked = !!v; } else { o.value = String(v); }
                });
                if (f === 'min_watch_pct') { $('tr-cm-watch-val').textContent = v + '%'; }
                if (f === 'ack_require_signature' || f === 'ack_require_pin') { renderAck(); }
            };
            inp.addEventListener(inp.type === 'checkbox' ? 'change' : 'input', handler);
        });

        $('tr-cm-caption').addEventListener('input', function () { setField('caption', $('tr-cm-caption').value.trim() || null, 600); });
        $('tr-cm-vsrc').addEventListener('click', function (e) {
            var b = e.target.closest('[data-src]');
            if (!b) { return; }
            st.videoSource = b.getAttribute('data-src');
            st.linkCheck = null;
            renderVideo();
        });
        $('tr-cm-vshare-btn').addEventListener('click', useDefaultVideo);
        $('tr-cm-vurl').addEventListener('input', function () { st.linkEditing = true; $('tr-cm-vurl-error').textContent = ''; });
        $('tr-cm-vurl').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); checkLink(); } });
        $('tr-cm-vurl').addEventListener('paste', function () { setTimeout(checkLink, 50); });
        $('tr-cm-vurl').addEventListener('change', checkLink);
        $('tr-cm-vurl-check').addEventListener('click', checkLink);
        $('tr-cm-doc-resume').addEventListener('click', function () {
            var v = variant(st.lesson) || {};
            if (v.media) { resumePages(v.media.id); }
        });
        $('tr-cm-img-zoom').addEventListener('click', function () {
            E.zoomImg.src = $('tr-cm-img').src;
            E.zoom.hidden = false;
            E.zoom.focus();
        });
        E.zoom.tabIndex = -1;
        E.zoom.addEventListener('click', function () { E.zoom.hidden = true; $('tr-cm-img-zoom').focus(); });
        $('tr-cm-exam').addEventListener('change', toggleExam);
        $('tr-cm-check').addEventListener('change', toggleCheck);
        $('tr-cm-kb-btn').addEventListener('click', function () { openKbPanel(); });
        $('tr-cm-docx-btn').addEventListener('click', function () { docxInput.click(); });

        // resources
        var resInput = el('input', { type: 'file', hidden: true, accept: up ? up.accepts('resource_file') : '', tabindex: '-1', 'aria-hidden': 'true' });
        content.appendChild(resInput);
        $('tr-cm-res-upload').addEventListener('click', function () { resInput.click(); });
        resInput.addEventListener('change', function () {
            var f = resInput.files && resInput.files[0];
            resInput.value = '';
            if (!f || !up || !st.lessonId) { return; }
            var t = st;
            var prog = $('tr-cm-res-progress');
            prog.textContent = 'Uploading ' + f.name + '…';
            t.uploads++;
            refreshFooter();
            up.upload(f, { purpose: 'resource_file', courseId: st.courseId, lessonId: st.lessonId, onProgress: function (p) { prog.textContent = 'Uploading ' + Math.round(p.fraction * 100) + '%'; } }).then(function (d) {
                t.uploads--;
                refreshFooter();
                if (t !== st) { return; }
                return addResource({ kind: 'file', title: fileTitle(f.name), media_id: d.media.id }).then(function () { prog.textContent = ''; });
            }).catch(function (err) {
                if (t.uploads > 0 && prog.textContent.indexOf('Uploading') === 0) { t.uploads--; refreshFooter(); }
                prog.textContent = (err && err.message) || 'The file could not be added.';
            });
        });
        var linkForm = $('tr-cm-res-linkform');
        $('tr-cm-res-link').addEventListener('click', function () { linkForm.hidden = false; $('tr-cm-res-link-title').focus(); });
        $('tr-cm-res-link-cancel').addEventListener('click', function () { linkForm.hidden = true; $('tr-cm-res-link-error').textContent = ''; });
        linkForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = $('tr-cm-res-link-title').value.trim();
            var url = $('tr-cm-res-link-url').value.trim();
            var err = $('tr-cm-res-link-error');
            err.textContent = '';
            if (!title) { err.textContent = 'Give the link a title.'; return; }
            if (!/^https:\/\/[^\s]+$/i.test(url)) { err.textContent = 'Links must start with https://'; return; }
            addResource({ kind: 'link', title: title, url: url }).then(function () {
                linkForm.hidden = true;
                $('tr-cm-res-link-title').value = '';
                $('tr-cm-res-link-url').value = '';
            }, function (e2) { err.textContent = (e2 && e2.fields && (e2.fields.url || e2.fields.title)) || (e2 && e2.message) || 'Could not add the link.'; });
        });

        // settings tab: duration + section
        $('tr-cm-dur').addEventListener('change', function () {
            var s = parseMmSs($('tr-cm-dur').value);
            if (isNaN(s)) { $('tr-cm-dur-error').textContent = 'Use minutes and seconds, like 4:30.'; return; }
            $('tr-cm-dur-error').textContent = '';
            setField('duration_s', s === null ? null : Math.max(1, s), 0);
        });
        $('tr-cm-dur-reset').addEventListener('click', function () { $('tr-cm-dur').value = ''; setField('duration_s', null, 0); });
        $('tr-cm-section').addEventListener('change', function () {
            var v = $('tr-cm-section').value;
            var id = v ? parseInt(v, 10) : null;
            st.sectionId = id;
            setField('section_id', id, 0);
            updateContext();
        });

        // footer
        E.done.addEventListener('click', function () { finish(false); });
        E.again.addEventListener('click', function () { finish(true); });
        E.close.addEventListener('click', function () { finish(false); });
        E.preview.addEventListener('click', function () {
            var w = window.open('about:blank', '_blank');
            flushAll().then(function () {
                var url = '/agent/training_preview.php?course_id=' + encodeURIComponent(st.courseId) + '&lang=' + encodeURIComponent(st.lang) +
                    (st.lesson && st.lesson.uid ? '&lesson=' + encodeURIComponent(st.lesson.uid) : '');
                if (w) { w.opener = null; w.location.href = url; } else { window.open(url, '_blank', 'noopener'); }
            });
        });
        E.del.addEventListener('click', function () {
            ui.confirmBar(E.alerts, { message: 'Delete this content? You can undo it from the course page.', confirmLabel: 'Delete', danger: true, prepend: true }).then(function (ok) {
                if (!ok) { return; }
                flushAll().then(function () { return api.post('lesson_delete', { lesson_id: st.lessonId }); }).then(function () {
                    st.changed = true;
                    st.deleted = true;
                    modal.hide();
                }, function (err) { ui.toast((err && err.message) || 'Could not delete.', { type: 'error' }); });
            });
        });
        $('tr-cm-deleted-close').addEventListener('click', function () { st.changed = true; if (st.store) { st.store.stop(); } modal.hide(); });
        $('tr-cm-deleted-restore').addEventListener('click', function () {
            api.post('lesson_restore', { lesson_id: st.lessonId }).then(function (d) {
                st.changed = true;
                showDeleted(false);
                adoptDetail(d);
                renderSave('idle');
            }, function (err) { ui.toast((err && err.message) || 'Could not restore.', { type: 'error' }); });
        });

        content.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                // Handled here: Bootstrap's static-backdrop "bounce" would otherwise move focus to the window.
                if (!E.zoom.hidden) { e.preventDefault(); e.stopPropagation(); E.zoom.hidden = true; $('tr-cm-img-zoom').focus(); return; }
                if (!E.panel.hidden) { e.preventDefault(); e.stopPropagation(); closePanel(); return; }
                if (e.target.closest('.tox, .ts-wrapper, .dropdown-menu, .tr-confirm-bar')) { return; }
                e.preventDefault();
                finish(false);
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                flushAll().then(function () { if (st && st.lessonId) { ui.toast('Saved.'); } });
            }
        });

        modalEl.addEventListener('shown.bs.modal', function () {
            if (!st) { return; }
            if (st.type) { applyType(); }
            if (st.initialTab && st.initialTab !== 'content') { showTab(st.initialTab); }
            if (!st.type) {
                var first = E.types.querySelector('[role=radio]:not(:disabled)');
                if (first) { first.focus(); }
            } else if (!st.lessonId) {
                E.name.focus();
            }
            if (st.initialFiles && st.initialFiles.length) { onWindowFiles(st.initialFiles); st.initialFiles = null; }
        });
        modalEl.addEventListener('hidden.bs.modal', onHidden);

        function bindChannel() {
            if (channelBound) { return; }
            channelBound = true;
            var ch = ui.channel();
            ch.on('tr-video-verified', function (p) { onVideoMessage('tr-video-verified', p); });
            ch.on('tr-video-error', function (p) { onVideoMessage('tr-video-error', p); });
            ch.on('quiz_changed', function (p) {
                if (!st || !st.lessonId) { return; }
                if (p && p.lesson_id && p.lesson_id !== st.lessonId) { return; }
                refreshDetailQuietly();
            });
            window.addEventListener('message', function (e) {
                if (e.origin !== window.location.origin || !e.data || typeof e.data.type !== 'string') { return; }
                if (e.data.type === 'tr-video-verified' || e.data.type === 'tr-video-error') { onVideoMessage(e.data.type, e.data); }
            });
        }

        // Editor text waits up to 1.5 s before it reaches the autosave queue (TrainingStore's own
        // leave-page check cannot see it yet), and a new lesson has no browser copy: warn meanwhile.
        window.addEventListener('beforeunload', function (e) {
            if (!st || st.readOnly) { return undefined; }
            var pending = st.htmlBusy > 0 || st.uploads > 0 || Object.keys(EDITORS).some(function (k) {
                return st.editorDirty[k] || (st.editorTimers[k] && st.editorTimers[k].pending());
            });
            if (!pending) { return undefined; }
            e.preventDefault();
            e.returnValue = '';
            return '';
        });

        // =============================================================================== finish / close
        function flushAll() {
            if (!st) { return Promise.resolve(); }
            var t = st;
            var editorSaves = Object.keys(EDITORS).map(function (k) {
                if (t.editorTimers[k] && t.editorTimers[k].pending()) { return saveEditor(k); }
                if (t.editorDirty[k]) { return saveEditor(k); }
                return Promise.resolve();
            });
            // Questions typed in the embedded quiz builder autosave on their own timer; send them too.
            var quizSaves = ['quiz', 'check'].map(function (slot) {
                var m = t.mounts && t.mounts[slot];
                return m && typeof m.flush === 'function' ? Promise.resolve(m.flush()).catch(function () { /* its card shows the error */ }) : Promise.resolve();
            });
            return Promise.all(editorSaves.concat(quizSaves)).then(function () {
                // A title typed but not sent yet goes now.
                return t.store ? t.store.flush() : undefined;
            });
        }

        function finish(again) {
            if (!st || st.finishing) { return; }
            if (st.uploads > 0) {
                ui.toast('Wait for the upload to finish.', { type: 'warning' });
                return;
            }
            st.finishing = true;
            E.done.disabled = true;
            flushAll().then(function () {
                var s = st.store;
                if (s && s.isDirty() && (s.state === 'error' || s.state === 'conflict')) {
                    st.finishing = false;
                    E.done.disabled = false;
                    ui.confirmBar(E.alerts, {
                        message: 'Some changes were not saved: ' + (E.save.textContent || 'save failed') + ' Close anyway?',
                        confirmLabel: 'Close without saving', danger: true, prepend: true
                    }).then(function (ok) {
                        if (!ok) { return; }
                        s.discard();
                        s.stop();
                        st.again = again;
                        modal.hide();
                    });
                    return;
                }
                st.again = again;
                modal.hide();
            });
        }

        function isEmptyLesson() {
            var d = st.lesson;
            if (!d || !d.id) { return false; }
            var v = variant(d, st.course.default_language) || {};
            var anyTitle = Object.keys(d.variants || {}).some(function (l) { return d.variants[l] && (d.variants[l].title || '').trim() !== ''; });
            var anyContent = Object.keys(d.variants || {}).some(function (l) {
                var x = d.variants[l];
                return x && (x.body_html || x.media || (x.video && x.video.provider) || x.description_html);
            });
            return !anyTitle && !anyContent && !(d.quiz && d.quiz.question_count) && !(d.resources || []).length && !v.caption;
        }

        function onHidden() {
            if (!st) { return; }
            var t = st;
            closePanel();
            if (t.renderAbort) { try { t.renderAbort.abort(); } catch (e) { /* ignore */ } }
            unmountBuilder('quiz');
            unmountBuilder('check');
            removeEditors();
            var player = $('tr-cm-vid-player');
            try { player.pause(); player.removeAttribute('src'); player.load(); } catch (e) { /* ignore */ }
            $('tr-cm-vframe-host').textContent = '';
            if (t.resSortable) { t.resSortable.destroy(); }
            var result = { changed: !!t.changed, lessonId: t.lessonId || null };
            if (t.deleted) { result.deleted = true; }
            if (t.again) { result.again = { sectionId: t.sectionId, type: t.type }; }
            var cleanup = Promise.resolve();
            if (!t.deleted && t.lessonId && isEmptyLesson()) {
                cleanup = api.post('lesson_delete', { lesson_id: t.lessonId }).then(function () {
                    var k = 'tr-draft:' + userId + ':' + t.lessonId + ':' + t.lang;
                    ui.local.remove(k);
                    result.discardedEmpty = true;
                }, function () { /* best effort */ });
            }
            if (t.store && !t.store.isDirty()) { window.TrainingStore.forget('lesson', t.lessonId || 'new'); }
            st = null;
            E.alerts.textContent = '';
            cleanup.then(function () { if (t.resolve) { t.resolve(result); } });
        }

        function showDeleted(on) {
            if (on === undefined) { on = true; }
            if (!st) { return; }
            E.deleted.hidden = !on;
            E.top.hidden = on;
            E.tabs.hidden = on;
            E.body.hidden = on;
            if (on && st.store) { st.store.stop(); }
        }

        function updateContext() {
            var s = (st.sections || []).find(function (x) { return x.id === st.sectionId; });
            if (st.course.kind === 'document') {
                E.context.textContent = 'Required document';
            } else if (s) {
                var idx = st.sections.indexOf(s) + 1;
                var label = 'Section ' + idx;
                var t = String(s.title || '').trim();
                // a default "Section N" title would read "Section 1, Section 1"
                if (t && t.toLowerCase() !== label.toLowerCase()) { label += ', ' + t; }
                E.context.textContent = (st.lessonId ? 'in ' : 'to ') + label;
            } else {
                E.context.textContent = st.lessonId ? '' : 'to this course';
            }
        }

        // =============================================================================== open
        function open(o) {
            o = o || {};
            if (st) { return Promise.resolve({ changed: false, lessonId: null, busy: true }); }
            setupDropzones();
            setupResponsible();
            bindChannel();
            var course = o.course || {};
            st = {
                courseId: o.courseId, course: course, sections: o.sections || [], lessonId: o.lessonId || null, lesson: null,
                type: o.type || null, lang: o.lang || course.default_language || 'en', sectionId: o.sectionId || null,
                afterLessonId: o.afterLessonId || null, initialTab: o.tab || null, initialFiles: o.files || null,
                store: null, preCreate: {}, changed: false, uploads: 0, htmlBusy: 0, editorInit: {}, editorDirty: {},
                editorTimers: {}, settingEditor: {}, mounts: {}, readOnly: !!o.readOnly, openedAt: Date.now(),
                videoSource: null, linkCheck: null, pendingTags: null, activeTab: 'content'
            };
            if ((course.languages || []).indexOf(st.lang) === -1) { st.lang = course.default_language || 'en'; }
            var t = st;
            var promise = new Promise(function (resolve) { t.resolve = resolve; });

            // reset the shell
            E.alerts.textContent = '';
            showDeleted(false);
            E.typeConfirm.textContent = '';
            E.name.value = '';
            E.nameErr.textContent = '';
            E.nameRef.textContent = '';
            $('tr-cm-docx-status').textContent = '';
            $('tr-cm-vurl').value = '';
            $('tr-cm-vurl-error').textContent = '';
            $('tr-cm-vcard').hidden = true;
            $('tr-cm-vframe-wrap').hidden = true;
            $('tr-cm-vframe-host').textContent = '';
            $('tr-cm-doc-restricted').hidden = true;
            $('tr-cm-vid-hevc').hidden = true;
            $('tr-cm-res-linkform').hidden = true;
            ['tr-cm-doc-drop', 'tr-cm-vid-drop', 'tr-cm-img-drop'].forEach(function (id) {
                var d = $(id);
                d.classList.remove('is-error', 'is-busy');
                d.querySelector('.tr-drop__progress').textContent = '';
            });
            E.thumbProgress.textContent = '';
            E.tagInput.hidden = true;
            E.tagAdd.hidden = false;
            E.tagOpts.textContent = '';
            (page.tags || []).forEach(function (tg) { E.tagOpts.appendChild(el('option', { value: tg.name })); });
            modalEl.classList.toggle('is-readonly', st.readOnly);
            window.bootstrap.Tab.getOrCreateInstance($('tr-cm-tab-content')).show();
            renderLangs();
            updateContext();

            if (st.lessonId) {
                E.title.textContent = 'Edit content';
                show(E.typepick, false);
                show(E.typepill, false);
                showPane(null);
                panes().forEach(function (p) { p.hidden = true; });
                E.save.setAttribute('data-state', 'saving');
                E.save.textContent = 'Loading…';
                modal.show();
                api.get('lesson_get', { lesson_id: st.lessonId }).then(function (d) {
                    if (t !== st) { return; }
                    st.lesson = d;
                    st.type = d.type;
                    st.sectionId = d.section_id;
                    ensureStore();
                    renderTypeTiles();
                    collapseTypePicker();
                    updateContext();
                    renderLangs();
                    renderFromDetail(true);
                    renderRefs();
                    if (modalEl.classList.contains('show')) { applyType(); }
                    if (st.initialTab && st.initialTab !== 'content') { showTab(st.initialTab); }
                    renderSave('idle');
                    offerLocalDraft();
                }, function (err) {
                    if (t !== st) { return; }
                    if (err && err.code === 'not_found') { showDeleted(); return; }
                    alertBox('load', 'alert-danger', [icon('exclamation-triangle'), el('span', { class: 'me-auto', text: (err && err.message) || 'This content could not be loaded.' }),
                        el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: 'Close', on: { click: function () { modal.hide(); } } })]);
                    E.save.textContent = '';
                });
            } else {
                E.title.textContent = st.course.kind === 'document' ? 'Document content' : 'Add content';
                st.lesson = null;
                show(E.typepick, true);
                show(E.typepill, false);
                if (st.type && allowedTypes().indexOf(st.type) === -1) { st.type = null; }
                renderTypeTiles();
                E.typeHint.textContent = st.type ? 'You can change this until you save.' : 'Pick one, or drop a file anywhere in this window.';
                showPane(st.type);
                refreshTabs();
                setHeaderEnabled(!!st.type);
                renderFromDetail(true);
                renderSave(st.type ? 'draft' : 'idle');
                modal.show();
            }
            return promise;
        }

        return { open: open };
    }

    window.TrainingContentModal = {
        open: function (o) {
            if (!instance) { instance = create(); }
            if (!instance) { return Promise.resolve({ changed: false, lessonId: null }); }
            return instance.open(o);
        }
    };
})();
