/*
 * Training › Question Library - page init for agent/training_banks.php (spec §5.6).
 *
 *   Tree   bank_tree (include_quiz_banks when "Show quiz banks" is on), grouped into Shared banks and
 *          Course banks; per-language counts; kebab: Add sub-bank, Rename (inline), Move… (in-page
 *          panel, the keyboard alternative to dragging), Archive (confirm bar; 422 bank_in_use lists
 *          the quizzes that still draw from it). Drag a bank onto another to re-parent it, or onto
 *          "Shared banks" to make it top level (bank_update parent_id; depth, scope and cycles are
 *          checked on the server; 409 busy is retried).
 *   Right  the bank header (path, description autosave, Used by, Export CSV for level 3 as a plain
 *          same-origin link) and TrainingQuizBuilder.mountBank for its questions.
 * Every string reaches the DOM through textContent / TrainingUi.el.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var root = document.getElementById('trb');
    if (!root || !window.TrainingUi || !window.TrainingApi) { return; }
    var UI = window.TrainingUi;
    var el = UI.el;
    var data = UI.readJson('tr-page-data');
    var treeEl = document.getElementById('trb-tree');
    var headEl = document.getElementById('trb-head');
    var qHost = document.getElementById('trb-questions');
    var searchEl = document.getElementById('trb-search');
    var quizToggle = document.getElementById('trb-quizbanks');
    var treeState = document.getElementById('trb-tree-state');
    var newBtn = document.getElementById('trb-new');
    var languages = data.languages || ['en'];
    var defaultLang = data.default_language || languages[0];

    var banks = [];
    var byId = {};
    var selectedId = data.bank_id || null;
    var collapsed = {};
    var renaming = null;
    var dragId = null;
    var library = null;
    var panel = null;
    var COLLAPSE_KEY = 'tr-banks-collapsed';

    try { collapsed = UI.local.get(COLLAPSE_KEY) || {}; } catch (e) { collapsed = {}; }

    function icon(name, extra) { return el('i', { class: 'fas ' + name + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
    function clear(n) { while (n && n.firstChild) { n.removeChild(n.firstChild); } }
    function msg(err) {
        if (err && err.code === 'busy') { return 'Someone else is changing the bank tree. Try again in a moment.'; }
        if (err && err.fields) { var k = Object.keys(err.fields); if (k.length) { return String(err.fields[k[0]]); } }
        return (err && err.message) || 'Something went wrong. Try again.';
    }
    function retryBusy(fn, tries) {
        tries = tries === undefined ? 3 : tries;
        return fn().catch(function (err) {
            if (err && err.code === 'busy' && tries > 0) {
                return new Promise(function (r) { setTimeout(r, 1200); }).then(function () { return retryBusy(fn, tries - 1); });
            }
            throw err;
        });
    }
    function setTreeState(s, text) { treeState.dataset.state = s; treeState.textContent = text || ''; }

    // ---------------- loading ----------------
    /**
     * Quiz banks are always fetched. With "Show quiz banks" off they are folded into their parent:
     * the parent's count includes their questions and its "Used by" lists their quizzes, so a
     * course bank never looks empty and unused while its quizzes hold the questions.
     */
    function shapeTree(list) {
        var show = !!quizToggle.checked;
        return (function fold(nodes) {
            return nodes.map(function (b) {
                var kids = b.children || [];
                var hidden = show ? [] : kids.filter(function (c) { return c.kind === 'quiz'; });
                var out = Object.assign({}, b, { children: fold(show ? kids : kids.filter(function (c) { return c.kind !== 'quiz'; })) });
                out.quizQuestions = 0;
                out.quizUsedBy = [];
                (function sum(xs) {
                    xs.forEach(function (c) {
                        out.quizQuestions += (c.counts && c.counts.questions) || 0;
                        out.quizUsedBy = out.quizUsedBy.concat(c.used_by || []);
                        sum(c.children || []);
                    });
                })(hidden);
                return out;
            });
        })(list);
    }
    function loadTree() {
        treeEl.setAttribute('aria-busy', 'true');
        return TrainingApi.get('bank_tree', { include_quiz_banks: true }).then(function (d) {
            banks = shapeTree(d.banks || []);
            byId = {};
            (function index(list) { list.forEach(function (b) { byId[b.id] = b; index(b.children || []); }); })(banks);
            if (selectedId && !byId[selectedId]) {
                // A quiz bank chosen from a link: show quiz banks so it can be selected.
                if (!quizToggle.checked && data.bank_id === selectedId) { quizToggle.checked = true; return loadTree(); }
                selectedId = null;
            }
            if (!selectedId) { selectedId = firstBank(); }
            renderTree();
            treeEl.setAttribute('aria-busy', 'false');
            renderHead();
            mountQuestions();
        }, function (err) {
            clear(treeEl);
            treeEl.appendChild(el('div', { class: 'trb-empty-tree' }, [
                el('p', { text: "The banks couldn't be loaded. " + msg(err) }),
                el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', on: { click: loadTree } }, 'Try again')
            ]));
            treeEl.setAttribute('aria-busy', 'false');
        });
    }
    function firstBank() {
        var shared = banks.filter(function (b) { return b.kind === 'shared'; })[0];
        return shared ? shared.id : (banks[0] ? banks[0].id : null);
    }

    // ---------------- tree ----------------
    function renderTree() {
        clear(treeEl);
        var needle = (searchEl.value || '').trim().toLowerCase();
        if (!banks.length) {
            treeEl.appendChild(el('div', { class: 'trb-empty-tree' }, [
                el('p', { text: 'No banks yet. A bank is a folder of questions any quiz can draw from.' }),
                el('button', { type: 'button', class: 'btn btn-primary btn-sm', on: { click: function () { createBank(null); } } }, [icon('fa-plus', 'me-1'), 'Create your first bank'])
            ]));
            return;
        }
        var shared = banks.filter(function (b) { return b.kind === 'shared'; });
        var course = banks.filter(function (b) { return b.kind !== 'shared'; });
        treeEl.appendChild(group('Shared banks', shared, needle, true));
        if (course.length) { treeEl.appendChild(group('Course banks', course, needle, false)); }
    }

    function matches(b, needle) {
        if (!needle) { return true; }
        if ((b.label || b.name || '').toLowerCase().indexOf(needle) !== -1) { return true; }
        return (b.children || []).some(function (c) { return matches(c, needle); });
    }

    function group(title, list, needle, isShared) {
        var head = el('div', { class: 'trb-group__head' }, [el('span', { text: title }), el('span', { text: String(list.length) })]);
        if (isShared) {
            // Drop here to make a shared bank top level.
            head.addEventListener('dragover', function (e) {
                var src = byId[dragId];
                if (src && src.kind === 'shared' && src.parent_id !== null) { e.preventDefault(); head.classList.add('is-drop'); }
            });
            head.addEventListener('dragleave', function () { head.classList.remove('is-drop'); });
            head.addEventListener('drop', function (e) {
                head.classList.remove('is-drop');
                if (dragId) { e.preventDefault(); moveBank(byId[dragId], null); }
            });
        }
        var ul = el('ul', { class: 'trb-nodes', role: 'group' });
        list.filter(function (b) { return matches(b, needle); }).forEach(function (b) { ul.appendChild(node(b, 0, needle)); });
        if (!ul.firstChild) { ul.appendChild(el('li', { class: 'text-muted small px-2 py-1', text: needle ? 'No match.' : (isShared ? 'No shared banks yet.' : 'None.') })); }
        return el('div', { class: 'trb-group' }, [head, ul]);
    }

    function countsText(b) {
        var c = b.counts || {};
        var parts = [String((c.questions || 0) + (b.quizQuestions || 0))];
        var tr = c.translated || {};
        Object.keys(tr).forEach(function (l) { if (l !== defaultLang) { parts.push(l.toUpperCase() + ' ' + tr[l]); } });
        return parts.join(' · ');
    }
    function movable(b) { return b.kind !== 'quiz' && !(b.kind === 'course' && b.parent_id === null); }

    function node(b, depth, needle) {
        var kids = (b.children || []).filter(function (c) { return matches(c, needle); });
        var isOpen = needle ? true : !collapsed[b.id];
        var caret = el('button', {
            type: 'button', class: 'trb-node__caret' + (kids.length ? '' : ' is-leaf'), 'aria-label': isOpen ? 'Collapse' : 'Expand', tabindex: kids.length ? null : '-1',
            on: { click: function () { collapsed[b.id] = isOpen; UI.local.set(COLLAPSE_KEY, collapsed); renderTree(); } }
        }, icon(isOpen ? 'fa-chevron-down' : 'fa-chevron-right'));
        var nameEl;
        if (renaming === b.id) {
            nameEl = el('input', { type: 'text', class: 'form-control form-control-sm trb-rename', value: b.name, maxlength: '150', 'aria-label': 'Bank name' });
            var done = false;
            var finish = function (save) {
                if (done) { return; }
                done = true;
                renaming = null;
                var v = nameEl.value.trim();
                if (save && v && v !== b.name) { updateBank(b, { name: v }); } else { renderTree(); }
            };
            nameEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); finish(true); }
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
            });
            nameEl.addEventListener('blur', function () { finish(true); });
            setTimeout(function () { nameEl.focus(); nameEl.select(); }, 0);
        } else {
            nameEl = el('button', {
                type: 'button', class: 'trb-node__open', role: 'treeitem', 'aria-selected': b.id === selectedId ? 'true' : 'false', 'aria-level': String(depth + 1),
                'aria-expanded': kids.length ? (isOpen ? 'true' : 'false') : null, on: { click: function () { select(b.id); } }
            }, [
                icon(b.kind === 'quiz' ? 'fa-pen' : (b.kind === 'course' && b.parent_id === null ? 'fa-book' : 'fa-layer-group'), 'trb-node__icon'),
                el('span', { class: 'trb-node__name', text: b.label || b.name }),
                el('span', { class: 'trb-node__counts', title: b.quizQuestions ? ((b.counts && b.counts.questions) || 0) + ' in this bank and ' + b.quizQuestions + ' written inside its quizzes' : 'Questions (and translated per language)', text: countsText(b) })
            ]);
        }
        var row = el('div', { class: 'trb-node__row' + (b.id === selectedId ? ' is-selected' : ''), style: { paddingLeft: (depth * 16) + 'px' } }, [caret, nameEl, menu(b)]);
        if (movable(b)) {
            row.draggable = true;
            row.addEventListener('dragstart', function (e) {
                dragId = b.id;
                row.classList.add('is-dragging');
                try { e.dataTransfer.setData('text/plain', String(b.id)); e.dataTransfer.effectAllowed = 'move'; } catch (x) { /* ignore */ }
            });
            row.addEventListener('dragend', function () { dragId = null; row.classList.remove('is-dragging'); });
        }
        row.addEventListener('dragover', function (e) {
            if (validTarget(byId[dragId], b)) { e.preventDefault(); row.classList.add('is-drop'); }
        });
        row.addEventListener('dragleave', function () { row.classList.remove('is-drop'); });
        row.addEventListener('drop', function (e) {
            row.classList.remove('is-drop');
            var src = byId[dragId];
            if (validTarget(src, b)) { e.preventDefault(); moveBank(src, b.id); }
        });
        var li = el('li', { class: 'trb-node' }, row);
        if (kids.length && isOpen) {
            var ul = el('ul', { class: 'trb-nodes', role: 'group' });
            kids.forEach(function (c) { ul.appendChild(node(c, depth + 1, needle)); });
            li.appendChild(ul);
        }
        return li;
    }

    function descendants(b) {
        var out = [];
        (function walk(x) { (x.children || []).forEach(function (c) { out.push(c.id); walk(c); }); })(b);
        return out;
    }
    function validTarget(src, target) {
        if (!src || !target || src.id === target.id || !movable(src)) { return false; }
        if (target.kind === 'quiz' || src.parent_id === target.id) { return false; }
        if ((src.course_id || null) !== (target.course_id || null)) { return false; }
        return descendants(src).indexOf(target.id) === -1;
    }

    function menu(b) {
        var items = [];
        if (b.kind !== 'quiz') {
            items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { createBank(b); } } }, [icon('fa-plus', 'me-2'), 'Add sub-bank'])));
        }
        items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { renaming = b.id; renderTree(); } } }, [icon('fa-i-cursor', 'me-2'), 'Rename'])));
        if (movable(b)) {
            items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { openMove(b); } } }, [icon('fa-arrows-alt', 'me-2'), 'Move…'])));
        }
        items.push(el('li', null, el('hr', { class: 'dropdown-divider' })));
        items.push(el('li', null, el('button', { type: 'button', class: 'dropdown-item text-danger', on: { click: function () { archiveBank(b); } } }, [icon('fa-archive', 'me-2'), 'Archive'])));
        return el('div', { class: 'dropdown trb-node__menu' }, [
            el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary', 'aria-label': 'Actions for ' + (b.label || b.name), 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown' } }, icon('fa-ellipsis-h')),
            el('ul', { class: 'dropdown-menu dropdown-menu-end' }, items)
        ]);
    }

    // ---------------- selection + header ----------------
    function select(id) {
        if (id === selectedId) { return; }
        selectedId = id;
        try {
            var u = new URL(window.location.href);
            u.searchParams.set('bank_id', String(id));
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* ignore */ }
        renderTree();
        renderHead();
        mountQuestions();
    }

    function renderHead() {
        var b = byId[selectedId];
        clear(headEl);
        headEl.hidden = !b;
        if (!b) { return; }
        var path = (b.path || b.name || '').split(' / ');
        var desc = el('textarea', { class: 'form-control form-control-sm trb-head__desc', rows: '2', maxlength: '500', placeholder: 'What belongs in this bank? (optional)', 'aria-label': 'Bank description' });
        desc.value = b.description || '';
        var descState = el('span', { class: 'tr-save-state', dataset: { state: 'idle' } });
        var saveDesc = function () {
            var v = desc.value.trim();
            if (v === (b.description || '')) { return; }
            descState.dataset.state = 'saving';
            descState.textContent = 'Saving…';
            retryBusy(function () { return TrainingApi.post('bank_update', { bank_id: b.id, fields: { description: v === '' ? null : v } }); }).then(function (nb) {
                b.description = nb.description;
                descState.dataset.state = 'saved';
                descState.textContent = 'Saved';
            }, function (err) {
                descState.dataset.state = 'error';
                descState.textContent = "Couldn't save";
                UI.toast(msg(err), { type: 'error' });
            });
        };
        desc.addEventListener('blur', saveDesc);
        desc.addEventListener('input', UI.debounce(saveDesc, 1200));
        var actions = el('div', { class: 'd-flex align-items-center gap-2' }, [
            el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', on: { click: function () { renaming = b.id; renderTree(); } } }, [icon('fa-i-cursor', 'me-1'), 'Rename']),
            data.can_export ? el('a', { class: 'btn btn-sm btn-outline-secondary', href: '/agent/training_ajax.php?action=bank_export_csv&bank_id=' + b.id, download: '', title: 'Includes the correct answers' }, [icon('fa-file-export', 'me-1'), 'Export CSV']) : null
        ]);
        var used = (b.used_by || []).concat(b.quizUsedBy || []);
        headEl.appendChild(el('div', { class: 'trb-head__path', text: path.slice(0, -1).join(' › ') || (b.kind === 'shared' ? 'Shared banks' : 'Course banks') }));
        headEl.appendChild(el('div', { class: 'trb-head__row' }, [el('h2', { class: 'trb-head__title', text: b.label || b.name }), actions]));
        headEl.appendChild(el('div', { class: 'd-flex align-items-end gap-2' }, [desc, descState]));
        headEl.appendChild(el('div', { class: 'trb-used' }, [el('span', { class: 'text-muted', text: used.length ? 'Used by' : 'Not used by any quiz yet.' })].concat(used.map(function (u) {
            return el('a', { class: 'tr-chip', href: '/agent/training_quiz.php?lesson_id=' + u.lesson_id }, [icon('fa-question-circle', 'me-1'), u.course_name + ' › ' + (u.lesson_title || 'Quiz')]);
        }))));
        if (b.quizQuestions) {
            headEl.appendChild(el('div', { class: 'small text-muted mt-1' }, [icon('fa-info-circle', 'me-1'),
                b.quizQuestions + (b.quizQuestions === 1 ? ' more question is' : ' more questions are') + ' written inside quizzes of this course. Turn on "Include sub-banks" below, or "Show quiz banks", to see them.']));
        }
    }

    function mountQuestions() {
        var b = byId[selectedId];
        if (!window.TrainingQuizBuilder) { return; }
        var meta = { languages: languages, readOnly: false };
        if (!library) {
            library = TrainingQuizBuilder.mountBank(qHost, {
                bankId: b ? b.id : null, lang: defaultLang, defaultLanguage: defaultLang, languages: languages, canExport: !!data.can_export,
                onChange: UI.debounce(function () { refreshCounts(); }, 600),
                onMoved: function () { refreshCounts(); }
            });
            return;
        }
        library.setBank(b ? b.id : null, meta);
    }

    function refreshCounts() {
        TrainingApi.get('bank_tree', { include_quiz_banks: true }).then(function (d) {
            banks = shapeTree(d.banks || []);
            byId = {};
            (function index(list) { list.forEach(function (x) { byId[x.id] = x; index(x.children || []); }); })(banks);
            renderTree();
            var b = byId[selectedId];
            if (b) {
                var usedEl = headEl.querySelector('.trb-used');
                if (usedEl && usedEl.parentNode) { renderHead(); }
            }
        }, function () { /* counts refresh later */ });
    }

    // ---------------- actions ----------------
    function createBank(parent) {
        setTreeState('saving', 'Saving…');
        var body = { name: parent ? 'New sub-bank' : 'New bank' };
        if (parent) { body.parent_id = parent.id; collapsed[parent.id] = false; }
        retryBusy(function () { return TrainingApi.post('bank_create', body); }).then(function (nb) {
            setTreeState('saved', 'Saved');
            selectedId = nb.id;
            renaming = nb.id;
            return loadTree();
        }, function (err) { setTreeState('error', "Couldn't save"); UI.toast(msg(err), { type: 'error' }); });
    }

    function updateBank(b, fields) {
        setTreeState('saving', 'Saving…');
        return retryBusy(function () { return TrainingApi.post('bank_update', { bank_id: b.id, fields: fields }); }).then(function () {
            setTreeState('saved', 'Saved');
            return loadTree();
        }, function (err) {
            setTreeState('error', "Couldn't save");
            UI.toast(msg(err), { type: 'error' });
            renderTree();
        });
    }

    function moveBank(src, parentId) {
        if (!src) { return; }
        updateBank(src, { parent_id: parentId }).then(function () {
            UI.toast('"' + src.name + '" moved.');
        });
    }

    function archiveBank(b) {
        var host = treeEl.parentNode;
        UI.confirmBar(host, { message: 'Archive "' + (b.label || b.name) + '" and its sub-banks? Their questions stay restorable.', confirmLabel: 'Archive', danger: true, prepend: true }).then(function (ok) {
            if (!ok) { return; }
            retryBusy(function () { return TrainingApi.post('bank_archive', { bank_id: b.id }); }).then(function () {
                UI.toast('Bank archived.');
                if (selectedId === b.id || descendants(b).indexOf(selectedId) !== -1) { selectedId = null; }
                loadTree();
            }, function (err) {
                if (err && err.code === 'bank_in_use' && err.data && err.data.used_by) {
                    var names = err.data.used_by.map(function (u) { return u.course_name + ' › ' + (u.lesson_title || 'Quiz'); });
                    UI.toast('Still used by: ' + names.join('; ') + '. Remove those sources first.', { type: 'error', delay: 9000 });
                    return;
                }
                UI.toast(msg(err), { type: 'error' });
            });
        });
    }

    // ---------------- move panel (keyboard alternative to dragging) ----------------
    function closePanel() {
        if (!panel) { return; }
        var p = panel;
        panel = null;
        if (p.el.parentNode) { p.el.parentNode.removeChild(p.el); }
        if (p.opener && p.opener.focus && document.body.contains(p.opener)) { p.opener.focus(); }
    }
    function openMove(b) {
        closePanel();
        var opener = document.activeElement;
        var list = el('div', { class: 'trq-tree', role: 'listbox', 'aria-label': 'New place for this bank' });
        var err = el('div', { class: 'alert alert-danger py-2 small', hidden: true, role: 'alert' });
        if (b.kind === 'shared' && b.parent_id !== null) {
            list.appendChild(el('button', { type: 'button', class: 'trq-tree__node', on: { click: function () { closePanel(); moveBank(b, null); } } }, [icon('fa-level-up-alt', 'trq-tree__icon'), el('span', { class: 'trq-tree__name', text: 'Top level (Shared banks)' })]));
        }
        (function walk(nodes, depth) {
            nodes.forEach(function (t) {
                var ok = validTarget(b, t);
                if (t.id !== b.id) {
                    list.appendChild(el('button', {
                        type: 'button', class: 'trq-tree__node', disabled: !ok, style: { paddingLeft: (10 + depth * 18) + 'px' },
                        on: { click: function () { closePanel(); moveBank(b, t.id); } }
                    }, [icon('fa-layer-group', 'trq-tree__icon'), el('span', { class: 'trq-tree__name', text: t.label || t.name }),
                        !ok && t.id === b.parent_id ? el('span', { class: 'trq-tree__reason', text: 'Current place' }) : null]));
                }
                if (t.id !== b.id) { walk(t.children || [], depth + 1); }
            });
        })(banks, 0);
        var close = el('button', { type: 'button', class: 'btn-close', 'aria-label': 'Close', on: { click: closePanel } });
        var p = el('aside', { class: 'tr-panel trq-panel trq-panel--fixed', role: 'dialog', 'aria-label': 'Move bank' }, [
            el('div', { class: 'tr-panel__header' }, [el('div', null, [el('h3', { class: 'trq-panel__title', text: 'Move "' + (b.label || b.name) + '"' }),
                el('div', { class: 'small text-muted', text: 'Banks nest up to 5 levels. Course banks stay in their course.' })]), close]),
            el('div', { class: 'tr-panel__body' }, [err, list])
        ]);
        p.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closePanel(); } });
        document.body.appendChild(p);
        panel = { el: p, opener: opener };
        setTimeout(function () { var f = p.querySelector('.trq-tree__node:not(:disabled)'); (f || close).focus(); }, 30);
    }

    // ---------------- wiring ----------------
    searchEl.addEventListener('input', UI.debounce(renderTree, 150));
    quizToggle.addEventListener('change', loadTree);
    newBtn.addEventListener('click', function () { createBank(null); });
    loadTree();
});
