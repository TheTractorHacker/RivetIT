/*
 * Training › Locked courses (P3 spec §5.8, L-10). Lists locked and blocked kiosk runs
 * (run_locked_list) and unlocks them with a reason (run_unlock). Everything is built with
 * TrainingUi.el / textContent; no HTML strings, no inline handlers.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var body = document.getElementById('tr-locked-rows');
        var deptSel = document.getElementById('tr-locked-dept');
        var countEl = document.getElementById('tr-locked-count');
        var runs = [];

        function fmtWhen(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        }

        function why(r) {
            if (r.state === 'locked') {
                var t = 'Out of tries' + (r.lesson && r.lesson.title ? ' on "' + r.lesson.title + '"' : '');
                return t + ' (' + r.attempts_used + ' used)';
            }
            if (r.blocked_reason === 'video_changed') { return 'A lesson video changed since it was published'; }
            if (r.blocked_reason === 'video_unavailable') { return 'A lesson video is no longer available'; }
            return 'Needs attention';
        }

        function message(text, cls) {
            body.textContent = '';
            body.appendChild(el('tr', {}, [el('td', { colspan: '5', class: 'py-4 text-center ' + (cls || 'text-secondary'), text: text })]));
        }

        function fillDepts() {
            var keep = deptSel.value;
            var seen = {};
            while (deptSel.options.length > 1) { deptSel.remove(1); }
            runs.forEach(function (r) {
                var d = r.contact.dept || '';
                if (d !== '' && !seen[d]) { seen[d] = true; }
            });
            Object.keys(seen).sort().forEach(function (d) { deptSel.appendChild(el('option', { value: d, text: d })); });
            deptSel.value = seen[keep] ? keep : '';
        }

        function reasonForm(r, mode, cell) {
            cell.textContent = '';
            var id = 'tr-locked-reason-' + r.run_id;
            var input = el('input', { type: 'text', class: 'form-control form-control-sm', id: id, maxlength: '255', minlength: '5',
                placeholder: mode === 'extra' ? 'Why one more try? (e.g. reviewed the material together)' : 'Why restart? (e.g. video was replaced)',
                'aria-label': 'Reason' });
            var err = el('div', { class: 'invalid-feedback' });
            var go = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: mode === 'extra' ? 'Give 1 more try' : 'Restart' });
            var cancel = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
            cancel.addEventListener('click', function () { render(); });
            go.addEventListener('click', function () {
                var reason = input.value.trim();
                if (reason.length < 5) {
                    input.classList.add('is-invalid');
                    err.textContent = 'Give a reason (at least 5 characters).';
                    input.focus();
                    return;
                }
                go.disabled = true;
                cancel.disabled = true;
                api.post('run_unlock', { run_id: r.run_id, mode: mode, extra: 1, reason: reason }).then(function () {
                    ui.toast(mode === 'extra' ? 'One more try given to ' + r.contact.name + '.' : r.contact.name + ' can restart on the current version.');
                    load();
                }, function (e) {
                    go.disabled = false;
                    cancel.disabled = false;
                    input.classList.add('is-invalid');
                    err.textContent = (e && e.message) || 'That did not work. Try again.';
                });
            });
            input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { go.click(); } });
            cell.appendChild(el('div', { class: 'd-flex flex-wrap gap-2 justify-content-end align-items-start' }, [
                el('div', { class: 'flex-grow-1', style: { minWidth: '16rem' } }, [input, err]), go, cancel
            ]));
            input.focus();
        }

        function render() {
            var dept = deptSel.value;
            var shown = runs.filter(function (r) { return dept === '' || r.contact.dept === dept; });
            countEl.textContent = shown.length === 1 ? '1 course' : shown.length + ' courses';
            if (shown.length === 0) {
                message(runs.length === 0 ? 'Nobody is locked out of a course right now.' : 'Nobody in this department is locked out.');
                return;
            }
            body.textContent = '';
            shown.forEach(function (r) {
                var actions = el('td', { class: 'text-end' });
                var btns = el('div', { class: 'd-inline-flex flex-wrap gap-2 justify-content-end' });
                if (r.state === 'locked') {
                    var more = el('button', { type: 'button', class: 'btn btn-sm btn-primary' }, [el('i', { class: 'fas fa-redo me-1', 'aria-hidden': 'true' }), 'Give 1 more try']);
                    more.addEventListener('click', function () { reasonForm(r, 'extra', actions); });
                    btns.appendChild(more);
                }
                var restart = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary' }, [el('i', { class: 'fas fa-undo me-1', 'aria-hidden': 'true' }), 'Restart on current version']);
                restart.addEventListener('click', function () { reasonForm(r, 'restart', actions); });
                btns.appendChild(restart);
                actions.appendChild(btns);
                var chip = r.state === 'locked'
                    ? el('span', { class: 'badge text-bg-danger me-2' }, [el('i', { class: 'fas fa-lock me-1', 'aria-hidden': 'true' }), 'Locked'])
                    : el('span', { class: 'badge text-bg-warning me-2' }, [el('i', { class: 'fas fa-exclamation-triangle me-1', 'aria-hidden': 'true' }), 'Blocked']);
                body.appendChild(el('tr', {}, [
                    el('td', {}, [el('div', { class: 'fw-semibold', text: r.contact.name }), el('div', { class: 'small text-secondary', text: r.contact.dept || '' })]),
                    el('td', {}, [el('div', { text: r.course.name }), r.course.newer_version ? el('div', { class: 'small text-secondary', text: 'A newer version is published' }) : null]),
                    el('td', {}, [chip, el('span', { text: why(r) })]),
                    el('td', { class: 'text-nowrap', text: fmtWhen(r.locked_at || r.last_activity) }),
                    actions
                ]));
            });
        }

        function load() {
            api.get('run_locked_list', {}).then(function (d) {
                runs = Array.isArray(d.runs) ? d.runs : [];
                fillDepts();
                render();
            }, function (e) {
                message((e && e.message) || 'Could not load the list.', 'text-danger');
            });
        }

        deptSel.addEventListener('change', render);
        load();
    });
})();
