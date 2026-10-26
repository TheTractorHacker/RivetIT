/*
 * Training > Cover Library (agent/training_cover_library.php). Loaded (deferred) after
 * js/training_common.js and agent/js/training_uploader.js.
 *
 * One cover_presets load gives the whole library (30 built-ins + every shared cover). Built-ins are
 * read-only (no media_id, no gallery_id - CoverLibrary::api()). A shared cover's edit/remove only show
 * for its sharer or an admin (the server enforces this regardless; hiding the buttons is only cosmetic -
 * CoverLibrary::addCustom()/removeCustom() check it again on every call).
 */
(function () {
    'use strict';

    var api = window.TrainingApi;
    var ui = window.TrainingUi;
    var el = ui.el;
    var data = JSON.parse(document.getElementById('tr-page-data').textContent);

    var grid = document.getElementById('trcl-grid');
    var empty = document.getElementById('trcl-empty');
    var catsEl = document.getElementById('trcl-cats');
    var searchEl = document.getElementById('trcl-search');

    var presets = null;
    var state = { category: 'All', editing: null };

    function icon(name) { return el('i', { class: 'fas fa-' + name, 'aria-hidden': 'true' }); }

    function canManage(cover) {
        return !!cover.gallery_id && (data.is_admin || cover.added_by === data.user_id);
    }

    function tint(node, color) {
        node.style.setProperty('--tr-cover-color', /^#[0-9A-Fa-f]{6}$/.test(color || '') ? color : '#475569');
    }

    function renderCats() {
        catsEl.textContent = '';
        ['All'].concat(presets.categories).forEach(function (c) {
            var on = state.category === c;
            catsEl.appendChild(el('button', {
                type: 'button', class: 'tr-cover-cat' + (on ? ' is-active' : ''), 'aria-pressed': on ? 'true' : 'false', text: c,
                on: { click: function () { state.category = c; renderCats(); renderGrid(); } }
            }));
        });
    }

    function matches(cover, q) {
        if (state.category !== 'All' && cover.category !== state.category) { return false; }
        if (!q) { return true; }
        var hay = (cover.label + ' ' + cover.category + ' ' + (cover.keywords || []).join(' ')).toLowerCase();
        return q.split(/\s+/).every(function (w) { return hay.indexOf(w) !== -1; });
    }

    function cardFor(cover) {
        var art = el('span', { class: 'tr-cover-art trcl-card__art' }, [el('img', { src: cover.thumb_url, alt: '', loading: 'lazy' })]);
        tint(art, cover.color);
        var meta = [el('span', { class: 'trcl-dot' })];
        tint(meta[0], cover.color);
        meta.push(el('span', { text: cover.category }));
        if (cover.gallery_id) {
            meta.push(el('span', { text: '· Shared by ' + (cover.added_by_name || ('user #' + cover.added_by)) }));
        } else {
            meta.push(el('span', { text: '· Built-in' }));
        }
        var card = el('div', { class: 'trcl-card' + (cover.gallery_id ? '' : ' is-builtin') }, [
            art,
            el('div', { class: 'trcl-card__body' }, [
                el('div', { class: 'trcl-card__label', text: cover.label }),
                el('div', { class: 'trcl-card__meta' }, meta),
                canManage(cover) ? el('div', { class: 'trcl-card__actions' }, [
                    el('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', on: { click: function () { toggleEdit(card, cover); } } }, [icon('pen'), ' Edit']),
                    el('button', { type: 'button', class: 'btn btn-outline-danger btn-sm', on: { click: function () { removeCover(cover); } } }, [icon('trash'), ' Remove'])
                ]) : null
            ])
        ]);
        return card;
    }

    /**
     * The label/category/tint edit form, shared by a grid card's "Edit" and a just-uploaded queue row (so a
     * bulk upload can be relabeled/recategorized right there instead of only from the grid afterward).
     * @param cover {media_id, label, category, tint} - the fields to start from
     * @param opts {saveLabel?, onSaved(cover), onCancel?}
     */
    function buildEditFields(cover, opts) {
        var labelInput = el('input', { type: 'text', class: 'form-control form-control-sm', value: cover.label, maxlength: '100' });
        var catSelect = el('select', { class: 'form-select form-select-sm' });
        presets.categories.forEach(function (c) { catSelect.appendChild(el('option', { value: c, text: c, selected: c === cover.category ? '' : null })); });
        catSelect.appendChild(el('option', { value: '__new__', text: '+ New category…' }));
        var catNew = el('input', { type: 'text', class: 'form-control form-control-sm mt-1', placeholder: 'New category name', maxlength: '50', hidden: true });
        catSelect.addEventListener('change', function () {
            var isNew = catSelect.value === '__new__';
            catNew.hidden = !isNew;
            if (isNew) { catNew.focus(); }
        });
        var swatches = el('div', { class: 'tr-cover-swatches' });
        var chosenTint = cover.tint;
        presets.tints.forEach(function (t) {
            var on = t.name === chosenTint;
            var b = el('button', { type: 'button', class: 'tr-cover-swatch' + (on ? ' is-selected' : ''), 'aria-label': t.name, title: t.name,
                on: { click: function () { chosenTint = t.name; Array.prototype.forEach.call(swatches.querySelectorAll('.tr-cover-swatch'), function (x) { x.classList.remove('is-selected'); }); b.classList.add('is-selected'); } } });
            b.style.setProperty('--tr-swatch', t.color);
            swatches.appendChild(b);
        });
        var status = el('span', { class: 'trcl-edit__status' });
        var saveBtn = el('button', { type: 'button', class: 'btn btn-primary btn-sm', text: opts.saveLabel || 'Save' });
        saveBtn.addEventListener('click', function () {
            var isNewCat = catSelect.value === '__new__';
            var category = (isNewCat ? catNew.value : catSelect.value).trim();
            var label = labelInput.value.trim();
            if (!label) { status.textContent = 'Give it a label.'; labelInput.focus(); return; }
            if (!category) { status.textContent = 'Name the new category.'; catNew.focus(); return; }
            saveBtn.disabled = true;
            status.textContent = 'Saving…';
            api.post('cover_gallery_add', { media_id: cover.media_id, label: label, category: category, tint: chosenTint }).then(function (d) {
                saveBtn.disabled = false;
                status.textContent = 'Saved.';
                var idx = presets.covers.findIndex(function (c) { return c.key === d.cover.key; });
                if (idx !== -1) { presets.covers[idx] = d.cover; } else { presets.covers.unshift(d.cover); }
                if (isNewCat && presets.categories.indexOf(category) === -1) { presets.categories.push(category); renderCats(); }
                renderGrid();
                opts.onSaved(d.cover);
            }, function (err) {
                saveBtn.disabled = false;
                status.textContent = (err && err.message) || 'Not saved.';
            });
        });
        var actions = [status, saveBtn];
        if (opts.onCancel) {
            actions.splice(1, 0, el('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', text: 'Cancel', on: { click: opts.onCancel } }));
        }
        return el('div', { class: 'trcl-edit' }, [
            el('label', { class: 'form-label small mb-0', text: 'Label' }), labelInput,
            el('label', { class: 'form-label small mb-0', text: 'Category' }), catSelect, catNew,
            el('label', { class: 'form-label small mb-0', text: 'Tint' }), swatches,
            el('div', { class: 'trcl-edit__actions' }, actions)
        ]);
    }

    function toggleEdit(card, cover) {
        var existing = card.querySelector('.trcl-edit');
        if (existing) { existing.remove(); return; }
        document.querySelectorAll('.trcl-edit').forEach(function (n) { n.remove(); });
        card.appendChild(buildEditFields(cover, {
            onCancel: function () { var f = card.querySelector('.trcl-edit'); if (f) { f.remove(); } },
            onSaved: function () { var f = card.querySelector('.trcl-edit'); if (f) { f.remove(); } }
        }));
    }

    function removeCover(cover) {
        var go = function () {
            api.post('cover_gallery_remove', { gallery_id: cover.gallery_id }).then(function () {
                presets.covers = presets.covers.filter(function (c) { return c.key !== cover.key; });
                renderGrid();
            }, function (err) {
                ui.toast((err && err.message) || 'Could not remove that cover.', { type: 'error' });
            });
        };
        // RivetDialog is loaded by every layout (tests/no_native_dialogs.php checks that), so there is no native fallback.
        window.RivetDialog.confirm('Remove "' + cover.label + '" from the shared gallery? Courses already using it as their cover keep it - this only takes it off the picker\'s gallery list.',
            { title: 'Remove cover', okText: 'Remove', variant: 'danger' }).then(function (ok) { if (ok) { go(); } });
    }

    function renderGrid() {
        if (!presets) { return; }
        var q = searchEl.value.trim().toLowerCase();
        grid.setAttribute('aria-busy', 'false');
        grid.textContent = '';
        var shown = 0;
        presets.covers.forEach(function (cover) {
            if (!matches(cover, q)) { return; }
            shown++;
            grid.appendChild(cardFor(cover));
        });
        empty.hidden = shown !== 0;
    }

    function load() {
        api.get('cover_presets').then(function (d) {
            presets = d;
            renderCats();
            renderGrid();
        }, function (err) {
            grid.setAttribute('aria-busy', 'false');
            empty.hidden = false;
            empty.textContent = (err && err.message) || 'The library could not be loaded.';
        });
    }

    searchEl.addEventListener('input', ui.debounce(renderGrid, 80));

    // ---- bulk upload + auto-share: one or many at once (TrainingUploader.dropzone() runs the files through its
    // own tested sequential queue - drag/drop, the button, progress - we just track each one's own row and
    // share it the moment its upload finishes, same cover_gallery_add flow as a single upload). ----
    var drop = document.getElementById('trcl-drop');
    var queueEl = document.getElementById('trcl-queue');
    var rows = {};   // file identity (name+size+lastModified) -> <li>

    function labelFromFilename(name) {
        var base = (name || '').replace(/\.[^./\\]+$/, '').replace(/[_-]+/g, ' ').trim();
        return base ? base.charAt(0).toUpperCase() + base.slice(1) : 'Untitled cover';
    }

    function fileKey(file) { return file.name + '|' + file.size + '|' + (file.lastModified || 0); }

    function queueRow(file) {
        var li = el('li', { class: 'list-group-item small' }, [
            el('div', { class: 'd-flex align-items-center gap-2' }, [
                el('span', { class: 'me-auto text-truncate', title: file.name, text: file.name }),
                el('span', { class: 'text-muted', text: 'Queued…' })
            ])
        ]);
        rows[fileKey(file)] = li;
        queueEl.appendChild(li);
        return li;
    }

    function rowSet(file, text, cls) {
        var li = rows[fileKey(file)];
        if (!li) { return; }
        var status = li.firstElementChild.lastElementChild;
        status.textContent = text;
        status.className = cls || 'text-muted';
    }

    /** Once a bulk-uploaded file is shared, its row grows the same label/category/tint fields a grid card's Edit
        uses, pre-filled from the filename-derived label it was just given - so you can fix it right there
        instead of hunting the new card down in the grid afterward. "Done" just dismisses the row. */
    function rowEditable(file, cover) {
        var li = rows[fileKey(file)];
        if (!li) { return; }
        li.appendChild(buildEditFields(cover, {
            saveLabel: 'Save',
            onCancel: function () { li.remove(); delete rows[fileKey(file)]; },
            onSaved: function (updated) {
                cover = updated;
                var actions = li.querySelector('.trcl-edit__actions');
                if (actions.querySelector('.trcl-row-done')) { return; }   // already added on an earlier save
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm trcl-row-done', text: 'Done', on: { click: function () { li.remove(); delete rows[fileKey(file)]; } } }));
            }
        }));
    }

    window.TrainingUploader.dropzone(drop, {
        purpose: 'path_cover',
        multiple: true,
        onStart: function (file) { queueRow(file); },
        onProgress: function (file, p) { rowSet(file, 'Uploading… ' + Math.round((p.fraction || 0) * 100) + '%'); },
        onDone: function (file, d) {
            var m = d && d.media;
            if (!m) { rowSet(file, 'Upload failed.', 'text-danger'); return; }
            rowSet(file, 'Sharing…');
            api.post('cover_gallery_add', { media_id: m.id, label: labelFromFilename(file.name), category: (presets && presets.categories[0]) || 'Custom', tint: 'slate' }).then(function (d2) {
                rowSet(file, 'Added - check the label/category below:', 'text-success');
                if (presets && d2 && d2.cover) { presets.covers.unshift(d2.cover); }
                renderGrid();
                rowEditable(file, d2.cover);
            }, function (err) {
                rowSet(file, (err && err.message) || 'Could not share it.', 'text-danger');
            });
        },
        onError: function (file, err) {
            rowSet(file, (err && err.message) || 'Upload failed.', 'text-danger');
        }
    });

    load();
})();
