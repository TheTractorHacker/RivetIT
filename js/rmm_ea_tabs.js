/* Administration > Endpoint agent: tabs. Keyboard (arrows, Home, End), aria, the #hash (#tab-checks or the id of any card inside a tab),
 * and the hidden ea_tab field so a Save returns to the tab it was pressed in. No inline handlers. Without this file all panes show. */
(function () {
    'use strict';
    var root = document.getElementById('ea-tabs');
    if (!root) { return; }
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-ea-tab]'));
    var panes = {};
    tabs.forEach(function (t) { panes[t.getAttribute('data-ea-tab')] = document.getElementById('tab-' + t.getAttribute('data-ea-tab')); });

    function show(key, focus, hash) {
        if (!panes[key]) { key = tabs[0].getAttribute('data-ea-tab'); }
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-ea-tab') === key, p = panes[t.getAttribute('data-ea-tab')];
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            p.hidden = !on;
            p.classList.toggle('active', on);
            p.classList.toggle('show', on);
            if (on && focus) { t.focus(); }
            if (on && t.scrollIntoView) { try { t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) { /* old browser */ } }
        });
        if (hash && window.history && history.replaceState) { history.replaceState(null, '', '#tab-' + key); }
    }

    function fromHash(scroll) {
        var id = decodeURIComponent((location.hash || '').replace(/^#/, ''));
        var key = null, target = null;
        if (id.indexOf('tab-') === 0 && panes[id.slice(4)]) { key = id.slice(4); }
        else if (id) {
            target = document.getElementById(id);
            var pane = target && target.closest ? target.closest('.ea-pane') : null;
            if (pane) { key = pane.id.slice(4); }
        }
        show(key || tabs[0].getAttribute('data-ea-tab'), false, false);
        if (target && key && scroll) { target.scrollIntoView(); }
    }

    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t.getAttribute('data-ea-tab'), false, true); });
        t.addEventListener('keydown', function (e) {
            var n = null;
            if (e.key === 'ArrowRight') { n = (i + 1) % tabs.length; }
            else if (e.key === 'ArrowLeft') { n = (i - 1 + tabs.length) % tabs.length; }
            else if (e.key === 'Home') { n = 0; }
            else if (e.key === 'End') { n = tabs.length - 1; }
            if (n === null) { return; }
            e.preventDefault();
            show(tabs[n].getAttribute('data-ea-tab'), true, true);
        });
    });
    window.addEventListener('hashchange', function () { fromHash(true); });

    // A Save returns to the tab of the button that was pressed (a control may sit in another tab than its form).
    document.addEventListener('submit', function (e) {
        var f = e.target, who = e.submitter || f;
        var pane = who.closest ? who.closest('.ea-pane') : null;
        if (!pane || !f.querySelector) { return; }
        var h = f.querySelector('input[name="ea_tab"]');
        if (!h && f.getAttribute('method') && f.getAttribute('method').toLowerCase() === 'post') {
            h = document.createElement('input'); h.type = 'hidden'; h.name = 'ea_tab'; f.appendChild(h);
        }
        if (h) { h.value = pane.id.slice(4); }
    }, true);

    fromHash(true);
})();
