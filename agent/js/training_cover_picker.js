/*
 * Training cover picker (plan A20). Loaded (deferred) after js/training_common.js and, for the
 * "Upload your own" tab, agent/js/training_uploader.js.
 *
 *   TrainingCoverPicker.open({name, color, cover:{id,url}|null, purpose:'course_cover'|'path_cover',
 *                             uploadOpts:{courseId?|pathId?}, title?})
 *       -> Promise({cover:{id,url}|null, color:string|null})   or null when cancelled
 *
 * Gallery covers come from cover_presets. Choosing one only previews it; "Use this cover" runs
 * cover_preset_ingest (the server stores the PNG once, deduplicated) and hands back the media id.
 * The caller saves it with its own course_update / path_save, so version checks stay there.
 *
 * Picking a gallery cover also switches the tint to that cover's own tint, until the author
 * picks a tint themselves in this dialog. No HTML sinks: every node comes from TrainingUi.el.
 */
(function () {
    'use strict';

    var api = window.TrainingApi;
    var ui = window.TrainingUi;
    var el = ui.el;
    var HEX_RE = /^#[0-9A-Fa-f]{6}$/;

    var presets = null;         // cover_presets response, cached for the page
    var presetsLoading = null;
    var modal = null;           // built once
    var state = null;           // the open dialog's state

    function icon(name) { return el('i', { class: 'fas fa-' + name, 'aria-hidden': 'true' }); }

    function loadPresets() {
        if (presets) { return Promise.resolve(presets); }
        if (!presetsLoading) {
            presetsLoading = api.get('cover_presets').then(function (d) {
                presets = d;
                return d;
            }, function (err) {
                presetsLoading = null;
                throw err;
            });
        }
        return presetsLoading;
    }

    /** Applies a tint to any .tr-cover-art box (the picker, cards, the path editor). */
    function tint(node, color) {
        node.style.setProperty('--tr-cover-color', HEX_RE.test(color || '') ? color : '#475569');
    }

    // --------------------------------------------------------------------------------- build
    function build() {
        var n = {};
        n.search = el('input', { type: 'search', class: 'form-control', placeholder: 'Search covers: forklift, chemical, PPE…', 'aria-label': 'Search covers' });
        n.cats = el('div', { class: 'tr-cover-cats', role: 'group', 'aria-label': 'Cover categories' });
        n.grid = el('div', { class: 'tr-cover-grid', role: 'listbox', 'aria-label': 'Covers' });
        n.empty = el('div', { class: 'tr-cover-empty text-muted', hidden: true, text: 'No cover matches that search.' });
        n.loading = el('div', { class: 'tr-cover-empty text-muted' }, [el('span', { class: 'spinner-border spinner-border-sm me-2', 'aria-hidden': 'true' }), 'Loading covers…']);

        n.fileInput = el('input', { type: 'file', class: 'd-none', accept: 'image/jpeg,image/png,image/webp,image/gif' });
        n.uploadBtn = el('button', { type: 'button', class: 'btn btn-outline-primary', on: { click: function () { n.fileInput.click(); } } }, [icon('upload'), ' Choose an image…']);
        n.uploadStatus = el('div', { class: 'small text-muted mt-2', 'aria-live': 'polite' });
        n.drop = el('div', { class: 'tr-cover-drop' }, [
            el('div', { class: 'tr-cover-drop__icon' }, [icon('image')]),
            el('div', { class: 'fw-medium', text: 'Drop a photo or image here' }),
            el('div', { class: 'small text-muted mb-3', text: 'JPEG, PNG, WebP or GIF. A wide image (16:9) fills the card best; a PNG with a transparent background sits on the tint like the gallery art.' }),
            n.uploadBtn, n.fileInput, n.uploadStatus
        ]);

        n.tabGallery = el('button', { type: 'button', class: 'nav-link active', role: 'tab', 'aria-selected': 'true', text: 'Gallery', on: { click: function () { showTab('gallery'); } } });
        n.tabUpload = el('button', { type: 'button', class: 'nav-link', role: 'tab', 'aria-selected': 'false', text: 'Upload your own', on: { click: function () { showTab('upload'); } } });
        n.paneGallery = el('div', { class: 'tr-cover-pane' }, [el('div', { class: 'mb-2' }, [n.search]), n.cats, n.loading, n.grid, n.empty]);
        n.paneUpload = el('div', { class: 'tr-cover-pane', hidden: true }, [n.drop]);

        n.previewArt = el('div', { class: 'tr-cover-art tr-cover-preview__art' });
        n.previewImg = el('img', { alt: '', hidden: true });
        n.previewNone = el('div', { class: 'tr-cover-preview__none' }, [icon('image'), el('span', { text: 'No cover' })]);
        n.previewArt.appendChild(n.previewImg);
        n.previewArt.appendChild(n.previewNone);
        n.previewName = el('div', { class: 'tr-cover-preview__name' });
        n.previewLabel = el('div', { class: 'tr-cover-preview__meta' });
        n.swatches = el('div', { class: 'tr-cover-swatches', role: 'radiogroup', 'aria-label': 'Tint' });
        n.custom = el('input', { type: 'color', class: 'tr-cover-custom', title: 'Custom tint', 'aria-label': 'Custom tint' });

        n.remove = el('button', { type: 'button', class: 'btn btn-link text-danger me-auto', text: 'Remove cover' });
        n.cancel = el('button', { type: 'button', class: 'btn btn-outline-secondary', dataset: { bsDismiss: 'modal' }, text: 'Cancel' });
        n.apply = el('button', { type: 'button', class: 'btn btn-primary' }, [el('span', { text: 'Use this cover' })]);
        n.title = el('h5', { class: 'modal-title', id: 'tr-cover-picker-title' });
        n.error = el('div', { class: 'alert alert-danger py-2 mb-0 mt-3', hidden: true, role: 'alert' });

        n.root = el('div', { class: 'modal fade tr-cover-picker', tabindex: '-1', 'aria-labelledby': 'tr-cover-picker-title', 'aria-hidden': 'true' }, [
            el('div', { class: 'modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down' }, [
                el('div', { class: 'modal-content' }, [
                    el('div', { class: 'modal-header' }, [n.title, el('button', { type: 'button', class: 'btn-close', dataset: { bsDismiss: 'modal' }, 'aria-label': 'Close' })]),
                    el('div', { class: 'modal-body' }, [
                        el('div', { class: 'tr-cover-layout' }, [
                            el('div', { class: 'tr-cover-main' }, [
                                el('nav', { class: 'nav nav-tabs mb-3', role: 'tablist' }, [n.tabGallery, n.tabUpload]),
                                n.paneGallery, n.paneUpload
                            ]),
                            el('aside', { class: 'tr-cover-side' }, [
                                el('div', { class: 'tr-cover-side__label', text: 'Preview' }),
                                el('div', { class: 'tr-cover-preview' }, [n.previewArt, el('div', { class: 'tr-cover-preview__body' }, [n.previewName, n.previewLabel])]),
                                el('div', { class: 'tr-cover-side__label mt-3', text: 'Tint' }),
                                n.swatches,
                                el('div', { class: 'form-hint mt-1', text: 'Cards show a soft wash of this color behind the cover.' }),
                                n.error
                            ])
                        ])
                    ]),
                    el('div', { class: 'modal-footer' }, [n.remove, n.cancel, n.apply])
                ])
            ])
        ]);
        document.body.appendChild(n.root);

        n.search.addEventListener('input', ui.debounce(renderGrid, 80));
        n.search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var first = n.grid.querySelector('.tr-cover-tile');
                if (first) { first.click(); first.focus(); }
            }
        });
        n.custom.addEventListener('input', function () { setColor(n.custom.value.toUpperCase(), true); });
        n.remove.addEventListener('click', function () {
            state.choice = { kind: 'none' };
            renderGrid();
            renderPreview();
        });
        n.apply.addEventListener('click', apply);
        n.grid.addEventListener('keydown', gridKeys);

        n.fileInput.addEventListener('change', function () { upload(n.fileInput.files[0]); n.fileInput.value = ''; });
        ['dragenter', 'dragover'].forEach(function (t) {
            n.drop.addEventListener(t, function (e) { e.preventDefault(); n.drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (t) {
            n.drop.addEventListener(t, function (e) { e.preventDefault(); n.drop.classList.remove('is-over'); });
        });
        n.drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { upload(e.dataTransfer.files[0]); } });

        n.root.addEventListener('hidden.bs.modal', function () {
            if (state && state.resolve) { state.resolve(null); }   // closed without "Use this cover"
            state = null;
        });
        return n;
    }

    // --------------------------------------------------------------------------------- state
    function showTab(which) {
        var g = which === 'gallery';
        modal.tabGallery.classList.toggle('active', g);
        modal.tabGallery.setAttribute('aria-selected', g ? 'true' : 'false');
        modal.tabUpload.classList.toggle('active', !g);
        modal.tabUpload.setAttribute('aria-selected', g ? 'false' : 'true');
        modal.paneGallery.hidden = !g;
        modal.paneUpload.hidden = g;
        if (!g && !window.TrainingUploader) {
            modal.uploadStatus.textContent = 'Uploading is not available on this page.';
            modal.uploadBtn.disabled = true;
        }
    }

    function setColor(color, byUser) {
        state.color = HEX_RE.test(color || '') ? color.toUpperCase() : null;
        if (byUser) { state.colorTouched = true; }
        renderSwatches();
        renderGrid();
        renderPreview();
    }

    function coverByKey(key) {
        var list = presets ? presets.covers : [];
        for (var i = 0; i < list.length; i++) { if (list[i].key === key) { return list[i]; } }
        return null;
    }

    function choosePreset(cover) {
        state.choice = { kind: 'preset', key: cover.key };
        if (!state.colorTouched) {
            state.color = cover.color;
            renderSwatches();
        }
        renderGrid();
        renderPreview();
    }

    // -------------------------------------------------------------------------------- render
    function renderCats() {
        modal.cats.textContent = '';
        ['All'].concat(presets.categories).forEach(function (c) {
            var on = state.category === c;
            modal.cats.appendChild(el('button', {
                type: 'button', class: 'tr-cover-cat' + (on ? ' is-active' : ''), 'aria-pressed': on ? 'true' : 'false', text: c,
                on: { click: function () { state.category = c; renderCats(); renderGrid(); } }
            }));
        });
    }

    function matches(cover, q) {
        if (!q) { return true; }
        var hay = (cover.label + ' ' + cover.category + ' ' + cover.keywords.join(' ')).toLowerCase();
        return q.split(/\s+/).every(function (w) { return hay.indexOf(w) !== -1; });
    }

    function renderGrid() {
        if (!presets || !state) { return; }
        var q = modal.search.value.trim().toLowerCase();
        modal.grid.textContent = '';
        var shown = 0;
        presets.covers.forEach(function (cover) {
            if (state.category !== 'All' && cover.category !== state.category && !q) { return; }
            if (!matches(cover, q)) { return; }
            shown++;
            var selected = state.choice.kind === 'preset' && state.choice.key === cover.key;
            var art = el('span', { class: 'tr-cover-art tr-cover-tile__art' }, [el('img', { src: cover.thumb_url, alt: '', loading: 'lazy' })]);
            tint(art, state.colorTouched || selected ? state.color : cover.color);
            var tile = el('button', {
                type: 'button', class: 'tr-cover-tile' + (selected ? ' is-selected' : ''), role: 'option',
                'aria-selected': selected ? 'true' : 'false', tabindex: selected || (shown === 1 && state.choice.kind !== 'preset') ? '0' : '-1',
                dataset: { key: cover.key },
                on: { click: function () { choosePreset(cover); } }
            }, [art, el('span', { class: 'tr-cover-tile__label', text: cover.label }),
                selected ? el('span', { class: 'tr-cover-tile__check', 'aria-hidden': 'true' }, [icon('check')]) : null]);
            modal.grid.appendChild(tile);
        });
        modal.empty.hidden = shown !== 0;
    }

    function gridKeys(e) {
        var tiles = Array.prototype.slice.call(modal.grid.querySelectorAll('.tr-cover-tile'));
        var i = tiles.indexOf(document.activeElement);
        if (i === -1) { return; }
        var cols = Math.max(1, Math.round(modal.grid.clientWidth / tiles[0].getBoundingClientRect().width));
        var next = { ArrowRight: i + 1, ArrowLeft: i - 1, ArrowDown: i + cols, ArrowUp: i - cols, Home: 0, End: tiles.length - 1 }[e.key];
        if (next === undefined) { return; }
        e.preventDefault();
        next = Math.max(0, Math.min(tiles.length - 1, next));
        tiles.forEach(function (t) { t.tabIndex = -1; });
        tiles[next].tabIndex = 0;
        tiles[next].focus();
    }

    function renderSwatches() {
        modal.swatches.textContent = '';
        var tints = presets ? presets.tints : [];
        var found = false;
        tints.forEach(function (t) {
            var on = state.color === t.color;
            found = found || on;
            var b = el('button', {
                type: 'button', class: 'tr-cover-swatch' + (on ? ' is-selected' : ''), role: 'radio', 'aria-checked': on ? 'true' : 'false',
                'aria-label': t.name, title: t.name.charAt(0).toUpperCase() + t.name.slice(1),
                on: { click: function () { setColor(t.color, true); } }
            });
            b.style.setProperty('--tr-swatch', t.color);
            modal.swatches.appendChild(b);
        });
        modal.custom.value = state.color || '#475569';
        modal.custom.classList.toggle('is-selected', !!state.color && !found);
        modal.swatches.appendChild(modal.custom);
    }

    function renderPreview() {
        var url = null;
        var label = '';
        if (state.choice.kind === 'preset') {
            var c = coverByKey(state.choice.key);
            url = c ? c.image_url : null;
            label = c ? 'Gallery · ' + c.label : '';
        } else if (state.choice.kind === 'media') {
            url = state.choice.url;
            label = state.choice.uploaded ? 'Your upload' : 'Current cover';
        } else {
            label = 'No cover';
        }
        tint(modal.previewArt, state.color);
        if (url) {
            modal.previewImg.src = url;
            modal.previewImg.hidden = false;
            modal.previewNone.hidden = true;
        } else {
            modal.previewImg.removeAttribute('src');
            modal.previewImg.hidden = true;
            modal.previewNone.hidden = false;
        }
        modal.previewName.textContent = state.opts.name || 'Untitled';
        modal.previewLabel.textContent = label;
        modal.remove.hidden = state.choice.kind === 'none';
        modal.error.hidden = true;
    }

    // --------------------------------------------------------------------------------- actions
    function upload(file) {
        if (!file || !window.TrainingUploader) { return; }
        var status = modal.uploadStatus;
        status.textContent = 'Uploading…';
        var opts = Object.assign({ purpose: state.opts.purpose || 'course_cover' }, state.opts.uploadOpts || {});
        opts.onProgress = function (p) { if (typeof p === 'number') { status.textContent = 'Uploading… ' + Math.round(p * (p <= 1 ? 100 : 1)) + '%'; } };
        var token = state;
        window.TrainingUploader.upload(file, opts).then(function (d) {
            if (state !== token) { return; }
            var m = d && d.media;
            if (!m) { throw new Error('Upload failed.'); }
            state.choice = { kind: 'media', id: m.id, url: m.url, uploaded: true };
            status.textContent = 'Uploaded. Check the preview, then choose "Use this cover".';
            renderGrid();
            renderPreview();
        }).catch(function (err) {
            if (state === token) { status.textContent = (err && err.message) || 'Upload failed.'; }
        });
    }

    function busy(on) {
        modal.apply.disabled = on;
        modal.apply.firstChild.textContent = on ? 'Saving…' : 'Use this cover';
    }

    function apply() {
        var s = state;
        var done = function (cover) {
            // Hand the result back now (the caller updates its form while the dialog fades out).
            var resolve = s.resolve;
            s.resolve = null;
            resolve({ cover: cover, color: s.color });
            window.bootstrap.Modal.getOrCreateInstance(modal.root).hide();
        };
        if (s.choice.kind === 'none') { done(null); return; }
        if (s.choice.kind === 'media') { done({ id: s.choice.id, url: s.choice.url }); return; }
        busy(true);
        api.post('cover_preset_ingest', { key: s.choice.key }).then(function (d) {
            if (state !== s) { return; }
            done({ id: d.media.id, url: d.media.url, key: d.key });
        }, function (err) {
            if (state !== s) { return; }
            modal.error.textContent = err.message || 'The cover could not be saved. Try again.';
            modal.error.hidden = false;
        }).then(function () { busy(false); });
    }

    // ------------------------------------------------------------------------------------ open
    function open(opts) {
        opts = opts || {};
        modal = modal || build();
        if (state && state.resolve) { state.resolve(null); }
        var cur = opts.cover && opts.cover.id ? { kind: 'media', id: opts.cover.id, url: opts.cover.url, uploaded: false } : { kind: 'none' };
        state = { opts: opts, choice: cur, color: HEX_RE.test(opts.color || '') ? opts.color.toUpperCase() : null,
                  colorTouched: HEX_RE.test(opts.color || ''), category: 'All', resolve: null };
        modal.title.textContent = opts.title || 'Choose a cover';
        modal.search.value = '';
        modal.uploadStatus.textContent = '';
        modal.uploadBtn.disabled = false;
        busy(false);
        showTab('gallery');
        renderPreview();

        var p = new Promise(function (resolve) { state.resolve = resolve; });
        var token = state;
        modal.loading.hidden = !!presets;
        loadPresets().then(function () {
            if (state !== token) { return; }
            modal.loading.hidden = true;
            renderCats();
            renderSwatches();
            renderGrid();
        }, function (err) {
            if (state !== token) { return; }
            modal.loading.textContent = (err && err.message) || 'The gallery could not be loaded.';
        });
        window.bootstrap.Modal.getOrCreateInstance(modal.root).show();
        modal.root.addEventListener('shown.bs.modal', function focusSearch() {
            modal.root.removeEventListener('shown.bs.modal', focusSearch);
            if (window.matchMedia('(pointer: fine)').matches) { modal.search.focus(); }
        });
        return p;
    }

    window.TrainingCoverPicker = { open: open, tint: tint, presets: loadPresets };
})();
