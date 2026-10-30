/*
 * project_kanban.js
 * Vanilla-JS (no jQuery) SortableJS glue for the project task kanban.
 * Mirrors tickets_kanban.js but persists via fetch() to
 * ajax.php?update_project_task_kanban. Lanes are fixed task_status values.
 *
 * The whole card is the drag surface (no handle). Mouse: drag starts at once. Touch: press and hold ~250 ms, then drag; a quick
 * swipe (moving more than a few px before the hold completes) cancels the hold and scrolls as normal.
 * Also: sort (manual / priority) and filter (priority, label, assignee) as a view-only layer, the per-card menu, and the open-detail keys.
 */
document.addEventListener('DOMContentLoaded', function () {

    var toolbar = document.getElementById('kanban-toolbar');
    var sortSel = document.getElementById('kanban-sort');
    var fPriority = document.getElementById('kanban-filter-priority');
    var fLabel = document.getElementById('kanban-filter-label');
    var fAssignee = document.getElementById('kanban-filter-assignee');
    var clearBtn = document.getElementById('kanban-filter-clear');
    var showing = document.getElementById('kanban-showing');
    var storeKey = 'projectKanbanView:' + (toolbar ? toolbar.getAttribute('data-project-id') : '0');
    var sorters = [];

    // -------------------------------
    // Drag: tasks within/between lanes
    // -------------------------------
    document.querySelectorAll('.kanban-status').forEach(function (statusCol) {
        sorters.push(new Sortable(statusCol, {
            group: 'projecttasks',
            animation: 150,
            draggable: '.task',
            // The card menu buttons stay clickable and never start a drag; everything else on the card (title, badges, empty space) drags.
            filter: '.task-tools',
            preventOnFilter: false,
            // Touch only: hold ~250 ms to lift a card so a normal swipe scrolls. Mouse drags immediately.
            delay: 250,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            onStart: hidePlaceholders,
            onEnd: function (evt) {
                const target = evt.to;
                const status = target.getAttribute('data-status');
                const byPriority = sortSel && sortSel.value === 'priority';

                // While sorted by priority the on-screen order is not the manual order: keep everyone's manual position and append
                // the dropped card to the end of its lane, so viewing by priority never rewrites the saved order.
                let cards = Array.from(target.querySelectorAll('.task'));
                if (byPriority) {
                    cards.sort(function (a, b) {
                        const ao = a === evt.item ? Infinity : parseInt(a.getAttribute('data-order'), 10);
                        const bo = b === evt.item ? Infinity : parseInt(b.getAttribute('data-order'), 10);
                        return ao - bo;
                    });
                }

                const positions = cards.map(function (card, index) {
                    card.setAttribute('data-task-status', status); // keep DOM in sync
                    card.setAttribute('data-order', index);
                    return {
                        task_id: parseInt(card.getAttribute('data-task-id'), 10),
                        task_order: index,
                        task_status: status
                    };
                });

                const body = new URLSearchParams();
                body.append('update_project_task_kanban', '1');
                body.append('csrf_token', PROJECT_KANBAN_CSRF);
                positions.forEach(function (p, i) {
                    body.append('positions[' + i + '][task_id]', p.task_id);
                    body.append('positions[' + i + '][task_order]', p.task_order);
                    body.append('positions[' + i + '][task_status]', p.task_status);
                });

                fetch('ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); })
                  .then(function () {
                      applyView();
                  })
                  .catch(function (err) {
                      console.error('Error updating project task kanban:', err);
                      if (window.toastr) { toastr.error('Could not save task move'); }
                  });
            }
        }));
    });

    // Links/badges inside a card must not start a native link drag that would compete with the card drag
    document.querySelectorAll('#kanban-board .task a').forEach(function (a) { a.setAttribute('draggable', 'false'); });

    // -------------------------------
    // Card menu: the dropdown must escape the lane's overflow clipping
    // -------------------------------
    if (window.bootstrap && bootstrap.Dropdown) {
        document.querySelectorAll('.task-menu-toggle').forEach(function (el) {
            bootstrap.Dropdown.getOrCreateInstance(el, {
                popperConfig: function (defaults) { return Object.assign({}, defaults, { strategy: 'fixed' }); }
            });
        });
    }

    // Duplicate / Delete from the card menu (post through the page's one hidden form; delete asks first)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-task-action]');
        if (!btn) { return; }
        const form = document.getElementById('task-action-form');
        const action = btn.getAttribute('data-task-action');
        const go = function () {
            form.querySelector('input[name="task_id"]').value = btn.getAttribute('data-task-id');
            const flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = action;
            flag.value = '1';
            form.appendChild(flag);
            form.submit();
        };
        if (action === 'delete_project_task' && window.RivetDialog) {
            RivetDialog.confirm('Delete task "' + (btn.getAttribute('data-task-name') || '') + '"? This cannot be undone.', { variant: 'danger', okText: 'Delete' })
                .then(function (ok) { if (ok) { go(); } });
        } else {
            go();
        }
    });

    // Title is a span (so the whole card, title included, can be dragged): Enter/Space opens details for keyboard users
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('task-title')) {
            e.preventDefault();
            e.target.click();
        }
    });

    // -------------------------------
    // Sort + filter (view only)
    // -------------------------------
    function applyView() {
        const byPriority = sortSel && sortSel.value === 'priority';
        const pr = fPriority ? fPriority.value : '';
        const lb = fLabel ? fLabel.value : '';
        const as = fAssignee ? fAssignee.value : '';
        let shown = 0;
        let total = 0;

        document.querySelectorAll('.kanban-status').forEach(function (lane) {
            const cards = Array.from(lane.querySelectorAll('.task'));
            cards.sort(function (a, b) {
                const ao = parseInt(a.getAttribute('data-order'), 10);
                const bo = parseInt(b.getAttribute('data-order'), 10);
                if (byPriority) {
                    const d = parseInt(b.getAttribute('data-priority'), 10) - parseInt(a.getAttribute('data-priority'), 10);
                    if (d !== 0) { return d; }
                }
                return ao - bo;
            });
            cards.forEach(function (card) {
                lane.appendChild(card);
                total++;
                let ok = true;
                if (pr !== '' && card.getAttribute('data-priority') !== pr) { ok = false; }
                if (lb !== '' && (',' + card.getAttribute('data-labels') + ',').indexOf(',' + lb + ',') === -1) { ok = false; }
                if (as !== '') {
                    // data-assignees lists every person on the card (primary first); "0" means unassigned. Falls back to the primary-only attribute.
                    const people = card.hasAttribute('data-assignees') ? card.getAttribute('data-assignees') : card.getAttribute('data-assignee');
                    const list = people === '' || people === '0' ? [] : people.split(',');
                    if (as === '0' ? list.length !== 0 : list.indexOf(as) === -1) { ok = false; }
                }
                card.classList.toggle('is-filtered-out', !ok);
                if (ok) { shown++; }
            });
        });

        // Priority view: lanes cannot be re-ordered by hand (cross-lane moves still work)
        sorters.forEach(function (s) { s.option('sort', !byPriority); });

        const filtering = pr !== '' || lb !== '' || as !== '';
        if (clearBtn) { clearBtn.hidden = !filtering; }
        if (showing) { showing.textContent = filtering ? ('Showing ' + shown + ' of ' + total + ' tasks') : ''; }
        updateLaneCounts();
        showPlaceholders();
        saveView();
    }

    function saveView() {
        try {
            localStorage.setItem(storeKey, JSON.stringify({
                sort: sortSel ? sortSel.value : 'manual',
                priority: fPriority ? fPriority.value : '',
                label: fLabel ? fLabel.value : '',
                assignee: fAssignee ? fAssignee.value : ''
            }));
        } catch (e) { /* storage blocked: the view just does not persist */ }
    }

    function restoreView() {
        try {
            const v = JSON.parse(localStorage.getItem(storeKey) || 'null');
            if (!v) { return; }
            if (sortSel && (v.sort === 'manual' || v.sort === 'priority')) { sortSel.value = v.sort; }
            if (fPriority && v.priority !== undefined) { fPriority.value = v.priority; }
            if (fLabel && v.label !== undefined) { fLabel.value = v.label; }
            if (fAssignee && v.assignee !== undefined) { fAssignee.value = v.assignee; }
        } catch (e) { /* ignore */ }
    }

    [sortSel, fPriority, fLabel, fAssignee].forEach(function (el) { if (el) { el.addEventListener('change', applyView); } });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (fPriority) { fPriority.value = ''; }
            if (fLabel) { fLabel.value = ''; }
            if (fAssignee) { fAssignee.value = ''; }
            applyView();
        });
    }

    // -------------------------------
    // Empty-column placeholders
    // -------------------------------
    function showPlaceholders() {
        document.querySelectorAll('.kanban-status').forEach(function (status) {
            const existing = status.querySelector('.empty-placeholder');
            if (existing) existing.remove();
            if (status.querySelectorAll('.task:not(.is-filtered-out)').length === 0) {
                const placeholder = document.createElement('div');
                placeholder.className = 'empty-placeholder text-muted text-center p-2';
                placeholder.innerText = status.querySelectorAll('.task').length ? 'No tasks match the filters' : 'Drop task here';
                placeholder.style.pointerEvents = 'none';
                status.appendChild(placeholder);
            }
        });
    }

    function hidePlaceholders() {
        document.querySelectorAll('.empty-placeholder').forEach(function (el) { el.remove(); });
    }

    // -------------------------------
    // Keep the per-lane count badges accurate after a move or filter
    // -------------------------------
    function updateLaneCounts() {
        document.querySelectorAll('#kanban-board .kanban-column').forEach(function (col) {
            const count = col.querySelectorAll('.kanban-status .task:not(.is-filtered-out)').length;
            const badge = col.querySelector('.panel-title .badge');
            if (badge) { badge.textContent = count; }
        });
    }

    restoreView();
    applyView();
});
