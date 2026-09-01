/*
 * project_kanban.js
 * Vanilla-JS (no jQuery) SortableJS glue for the project task kanban.
 * Mirrors tickets_kanban.js but persists via fetch() to
 * ajax.php?update_project_task_kanban. Lanes are fixed task_status values.
 */
document.addEventListener('DOMContentLoaded', function () {

    // -------------------------------
    // Drag: tasks within/between lanes
    // -------------------------------
    document.querySelectorAll('.kanban-status').forEach(function (statusCol) {
        new Sortable(statusCol, {
            group: 'projecttasks',
            animation: 150,
            handle: isTouchDevice() ? '.drag-handle-class' : undefined,
            onStart: hidePlaceholders,
            onEnd: function (evt) {
                const target = evt.to;
                const status = target.getAttribute('data-status');

                const positions = Array.from(target.querySelectorAll('.task')).map(function (card, index) {
                    card.setAttribute('data-task-status', status); // keep DOM in sync
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
                      updateLaneCounts();
                      showPlaceholders();
                  })
                  .catch(function (err) {
                      console.error('Error updating project task kanban:', err);
                      if (window.toastr) { toastr.error('Could not save task move'); }
                  });
            }
        });
    });

    // -------------------------------
    // Touch support: reveal drag handles
    // -------------------------------
    if (isTouchDevice()) {
        document.querySelectorAll('.drag-handle-class').forEach(function (el) { el.style.display = 'inline'; });
    }

    // -------------------------------
    // Empty-column placeholders
    // -------------------------------
    function showPlaceholders() {
        document.querySelectorAll('.kanban-status').forEach(function (status) {
            const existing = status.querySelector('.empty-placeholder');
            if (existing) existing.remove();
            if (status.querySelectorAll('.task').length === 0) {
                const placeholder = document.createElement('div');
                placeholder.className = 'empty-placeholder text-muted text-center p-2';
                placeholder.innerText = 'Drop task here';
                placeholder.style.pointerEvents = 'none';
                status.appendChild(placeholder);
            }
        });
    }

    function hidePlaceholders() {
        document.querySelectorAll('.empty-placeholder').forEach(function (el) { el.remove(); });
    }

    // -------------------------------
    // Keep the per-lane count badges accurate after a move
    // -------------------------------
    function updateLaneCounts() {
        document.querySelectorAll('#kanban-board .kanban-column').forEach(function (col) {
            const count = col.querySelectorAll('.kanban-status .task').length;
            const badge = col.querySelector('.panel-title .badge');
            if (badge) { badge.textContent = count; }
        });
    }

    // -------------------------------
    // Utility: detect touch device
    // -------------------------------
    function isTouchDevice() {
        return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    }

    showPlaceholders();
});
