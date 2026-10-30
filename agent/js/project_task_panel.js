/*
 * project_task_panel.js
 * Behaviour of the task pop-up's panels (checklist, subtasks, comments, files). One delegated set of listeners on the document, bound
 * once (the pop-up re-runs this file every time it opens). Each action POSTs to /agent/project_task_ajax.php and the server answers
 * with the fresh HTML of the panel(s) it changed; nothing is built from text here except through textContent / .value.
 * The panels sit inside the pop-up's <form>, so Enter in their inputs adds the item instead of saving the task.
 */
(function () {
    if (window.__ptPanelBound) { return; }
    window.__ptPanelBound = true;

    function csrfFor(root) {
        var f = root.closest('form');
        var i = f && f.querySelector('input[name="csrf_token"]');
        return i ? i.value : (window.csrfToken || '');
    }

    function showError(section, message) {
        var old = section.querySelector('.pt-error');
        if (old) { old.remove(); }
        if (!message) { return; }
        var p = document.createElement('p');
        p.className = 'pt-error';
        p.setAttribute('role', 'alert');
        p.textContent = message;
        section.appendChild(p);
    }

    // The Kanban card counts are server-rendered: when the pop-up closes after a change, reload the board so they are right
    function markDirty(root) {
        var modal = root.closest('.modal');
        if (!modal || modal.dataset.ptDirty) { return; }
        modal.dataset.ptDirty = '1';
        modal.addEventListener('hidden.bs.modal', function () {
            if (document.getElementById('kanban-board')) { window.location.reload(); }
        });
    }

    function send(section, fields, file) {
        var root = section.closest('.task-panels');
        var body = new FormData();
        body.append('csrf_token', csrfFor(root));
        body.append('task_id', root.getAttribute('data-task-id'));
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        if (file) { body.append('file', file); }
        section.classList.add('pt-busy');
        return fetch(root.getAttribute('data-endpoint'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent an unexpected answer (HTTP ' + r.status + ').' }; }); })
            .then(function (data) {
                section.classList.remove('pt-busy');
                var panels = data.panels || {};
                Object.keys(panels).forEach(function (name) {
                    var target = root.querySelector('[data-pt-panel="' + name + '"]');
                    if (target) { target.innerHTML = panels[name]; }
                });
                var holder = root.querySelector('[data-pt-panel="' + (Object.keys(panels)[0] || section.getAttribute('data-pt-panel')) + '"]') || section;
                showError(holder, data.ok ? '' : (data.error || 'That did not work.'));
                if (data.ok) { markDirty(root); }
                return data;
            })
            .catch(function () {
                section.classList.remove('pt-busy');
                showError(section, 'Could not reach the server. Check your connection and try again.');
            });
    }

    function confirmThen(message, fn) {
        if (window.RivetDialog && RivetDialog.confirm) {
            RivetDialog.confirm(message, { variant: 'danger', okText: 'Delete' }).then(function (ok) { if (ok) { fn(); } });
        } else if (window.confirm(message)) {
            fn();
        }
    }

    function sectionOf(el) { return el.closest('[data-pt-panel]'); }
    function itemOf(el) { return el.closest('[data-item-id]'); }

    // ---- clicks
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.task-panels [data-pt-act]');
        if (!btn || btn.tagName === 'INPUT') { return; }
        var act = btn.getAttribute('data-pt-act');
        var section = sectionOf(btn);
        var item = itemOf(btn);
        var sub = btn.closest('[data-subtask-id]');
        var comment = btn.closest('[data-comment-id]');
        var file = btn.closest('[data-attachment-id]');

        if (act === 'checklist_add' || act === 'subtask_add') {
            var input = section.querySelector('[data-pt-input="' + act + '"]');
            var text = input.value.trim();
            if (!text) { input.focus(); return; }
            send(section, act === 'subtask_add' ? { action: act, name: text } : { action: act, text: text });
        } else if (act === 'checklist_up' || act === 'checklist_down') {
            send(section, { action: act, item_id: item.getAttribute('data-item-id') });
        } else if (act === 'checklist_delete') {
            send(section, { action: act, item_id: item.getAttribute('data-item-id') });
        } else if (act === 'checklist_edit') {
            var span = item.querySelector('.pt-text');
            var field = document.createElement('input');
            field.type = 'text';
            field.className = 'form-control form-control-sm pt-text';
            field.maxLength = 255;
            field.value = span.textContent;
            span.replaceWith(field);
            field.focus();
            var done = false;
            var save = function () {
                if (done) { return; }
                done = true;
                if (field.value.trim() === '' || field.value === span.textContent) { field.replaceWith(span); return; }
                send(section, { action: 'checklist_edit', item_id: item.getAttribute('data-item-id'), text: field.value });
            };
            field.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); save(); }
                if (ev.key === 'Escape') { ev.preventDefault(); done = true; field.replaceWith(span); }
            });
            field.addEventListener('blur', save);
        } else if (act === 'subtask_detach' && sub) {
            send(section, { action: act, subtask_id: sub.getAttribute('data-subtask-id') });
        } else if (act === 'subtask_detach_self') {
            send(section, { action: act });
        } else if (act === 'comment_add') {
            var area = section.querySelector('[data-pt-input="comment_add"]');
            if (!area.value.trim()) { area.focus(); return; }
            send(section, { action: act, body: area.value });
        } else if (act === 'comment_edit' && comment) {
            var bodyEl = comment.querySelector('.pt-comment-body');
            var raw = comment.querySelector('[data-pt-raw]').value;
            var wrap = document.createElement('div');
            var ta = document.createElement('textarea');
            ta.className = 'form-control mb-2';
            ta.rows = 3;
            ta.maxLength = 20000;
            ta.value = raw;
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-primary btn-sm me-2';
            saveBtn.textContent = 'Save';
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-light btn-sm';
            cancelBtn.textContent = 'Cancel';
            wrap.append(ta, saveBtn, cancelBtn);
            bodyEl.hidden = true;
            bodyEl.after(wrap);
            ta.focus();
            cancelBtn.addEventListener('click', function () { wrap.remove(); bodyEl.hidden = false; });
            saveBtn.addEventListener('click', function () {
                send(section, { action: 'comment_edit', comment_id: comment.getAttribute('data-comment-id'), body: ta.value });
            });
        } else if (act === 'comment_delete' && comment) {
            confirmThen('Delete this comment?', function () { send(section, { action: act, comment_id: comment.getAttribute('data-comment-id') }); });
        } else if (act === 'file_delete' && file) {
            confirmThen('Remove this file from the task?', function () { send(section, { action: act, attachment_id: file.getAttribute('data-attachment-id') }); });
        }
    });

    // ---- ticking a checklist item or a subtask
    document.addEventListener('change', function (e) {
        var box = e.target.closest('.task-panels input[data-pt-act]');
        if (box && (box.getAttribute('data-pt-act') === 'checklist_toggle' || box.getAttribute('data-pt-act') === 'subtask_toggle')) {
            var section = sectionOf(box);
            var act = box.getAttribute('data-pt-act');
            var fields = { action: act, done: box.checked ? '1' : '0' };
            if (act === 'checklist_toggle') { fields.item_id = itemOf(box).getAttribute('data-item-id'); }
            else { fields.subtask_id = box.closest('[data-subtask-id]').getAttribute('data-subtask-id'); }
            send(section, fields);
            return;
        }
        var up = e.target.closest('.task-panels [data-pt-upload]');
        if (up && up.files.length) {
            var sec = sectionOf(up);
            var files = Array.prototype.slice.call(up.files);
            up.value = '';
            files.reduce(function (chain, f) {
                return chain.then(function () { return send(sec, { action: 'file_upload' }, f); });
            }, Promise.resolve());
        }
    });

    // ---- Enter adds (the panels live inside the pop-up's form, where Enter would otherwise save the whole task)
    document.addEventListener('keydown', function (e) {
        var input = e.target.closest ? e.target.closest('.task-panels [data-pt-input]') : null;
        if (!input || e.key !== 'Enter') { return; }
        if (input.tagName === 'TEXTAREA' && !(e.ctrlKey || e.metaKey)) { return; }
        e.preventDefault();
        var section = sectionOf(input);
        var add = section.querySelector('[data-pt-act="' + input.getAttribute('data-pt-input') + '"]');
        if (add) { add.click(); }
    });
})();
