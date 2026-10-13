/*
 * Multi-choice ticket filters: checkbox groups used in the Tickets filter bar (as dropdowns) and in the saved-view dialogs
 * (as fieldsets). Markup comes from ticketFilterCheckGroup() in agent/includes/ticket_view_filters.php:
 *
 *   [data-tv-group]              one group (a .dropdown or a fieldset)
 *   [data-tv-count]              the badge showing how many boxes are ticked
 *   [data-tv-all] [data-tv-clear] the "Select all" / "Clear" buttons
 *   data-tv-autosubmit           (on the group) apply the filters by submitting the form when the dropdown closes
 *   data-tv-radio="name=value"   (on the group) ticking a box also picks that radio button of the same form
 *   data-tv-exclusive            the status group: its radio buttons (All open / closed / both) and its specific-status
 *                                boxes cancel each other
 *
 * Delegated listeners only, so groups inside dialogs loaded later work and nothing is inline (strict CSP).
 */
(function () {
    'use strict';

    function boxes(group) {
        return Array.prototype.slice.call(group.querySelectorAll('input[type=checkbox]'));
    }

    function refresh(group) {
        var n = boxes(group).filter(function (c) { return c.checked; }).length;
        var badge = group.querySelector('[data-tv-count]');
        if (badge) {
            badge.textContent = String(n);
            badge.hidden = n === 0;
        }
        var btn = group.querySelector('.tv-ms-btn');
        if (btn) {
            btn.classList.toggle('tv-active', n > 0);
        }
        var label = group.querySelector('[data-tv-label]');
        if (label && group.hasAttribute('data-tv-exclusive')) {
            var radio = group.querySelector('input[type=radio]:checked');
            label.textContent = n > 0 ? 'Status' : (radio ? (radio.getAttribute('data-tv-label') || radio.value) : 'Status');
        }
    }

    function exclusive(group, target) {
        var radios = Array.prototype.slice.call(group.querySelectorAll('input[type=radio]'));
        if (target.type === 'radio') {
            boxes(group).forEach(function (c) { c.checked = false; });
        } else if (boxes(group).some(function (c) { return c.checked; })) {
            radios.forEach(function (r) { r.checked = false; });
        } else if (!radios.some(function (r) { return r.checked; }) && radios.length) {
            radios[0].checked = true;    // nothing left ticked: back to "All open"
        }
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        var group = t.closest ? t.closest('[data-tv-group]') : null;
        if (!group || (t.type !== 'checkbox' && t.type !== 'radio')) {
            return;
        }
        if (group.hasAttribute('data-tv-exclusive')) {
            exclusive(group, t);
        }
        var link = group.getAttribute('data-tv-radio');
        if (link && t.type === 'checkbox' && t.checked && t.form) {
            var parts = link.split('=');
            var radio = t.form.querySelector('input[type=radio][name="' + parts[0] + '"][value="' + parts[1] + '"]');
            if (radio) {
                radio.checked = true;
            }
        }
        group.setAttribute('data-tv-changed', '1');
        refresh(group);
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-tv-all],[data-tv-clear]') : null;
        if (!btn) {
            return;
        }
        var group = btn.closest('[data-tv-group]');
        if (!group) {
            return;
        }
        e.preventDefault();
        var all = btn.hasAttribute('data-tv-all');
        var list = boxes(group);
        list.forEach(function (c) { c.checked = all; });
        if (list.length) {
            // Let the change handler do the bookkeeping (counts, exclusive status choice, linked radio)
            list[list.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    // A filter-bar dropdown applies its choices when it closes (once, and only if something changed).
    document.addEventListener('hidden.bs.dropdown', function (e) {
        var group = e.target.closest ? e.target.closest('[data-tv-group]') : null;
        if (!group || !group.hasAttribute('data-tv-autosubmit') || !group.hasAttribute('data-tv-changed')) {
            return;
        }
        group.removeAttribute('data-tv-changed');
        var form = group.closest('form');
        if (form) {
            form.submit();
        }
    });
})();
