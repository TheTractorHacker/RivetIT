/*
 * My dashboard (agent/my_dashboard.php), customise mode only: drag-and-drop reorder of widgets, replacing the
 * old Move up/Move down buttons. SortableJS (plugins/SortableJS/Sortable.min.js) handles the drag itself;
 * on drop we just read the new widget order back off the DOM and save it (DashboardLayout::reorder()) - the
 * cards are already in their new order visually, so this is a background save, not a page reload.
 */
(function () {
    'use strict';

    var container = document.getElementById('dash-widgets');
    if (!container || !window.Sortable) { return; }

    var csrf = container.getAttribute('data-csrf') || '';
    var msg = null;
    function flash(text, ok) {
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'small mt-2';
            container.insertAdjacentElement('afterend', msg);
        }
        msg.textContent = text;
        msg.className = 'small mt-2 ' + (ok ? 'text-muted' : 'text-danger');
        if (ok) { window.setTimeout(function () { if (msg) { msg.textContent = ''; } }, 2000); }
    }

    new window.Sortable(container, {
        handle: '.dash-drag',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function (evt) {
            if (evt.oldIndex === evt.newIndex) { return; }
            var order = Array.prototype.map.call(container.querySelectorAll('[data-widget]'), function (el) {
                return el.getAttribute('data-widget');
            });
            var body = new URLSearchParams({ layout_action: 'reorder', order: JSON.stringify(order), csrf_token: csrf, customise: '1' });
            fetch('my_dashboard.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (d) { flash(d && d.success ? 'Order saved.' : 'Could not save the order.', !!(d && d.success)); })
                .catch(function () { flash('Network error - the order was not saved.', false); });
        }
    });
})();
