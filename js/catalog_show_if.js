/*
 * Conditional fields on service catalog request forms. Wrappers carry data-catalog-key and, when conditional,
 * data-show-if='{"field":..,"op":"equals|in|not_empty","value":..}'. A hidden field is disabled so it is not posted
 * and not validated by the browser. This only mirrors ServiceCatalogService::showIfMet(); the server decides.
 */
(function () {
    'use strict';
    function control(wrap) { return wrap ? wrap.querySelector('input, select, textarea') : null; }

    function answer(wrap) {
        if (!wrap || wrap.hidden) { return ''; }
        var c = control(wrap);
        if (!c) { return ''; }
        if (c.type === 'checkbox') { return c.checked ? 'Yes' : ''; }
        return String(c.value || '').trim();
    }

    function met(rule, v) {
        if (rule.op === 'equals') { return v === String(rule.value); }
        if (rule.op === 'in') { return Array.isArray(rule.value) && rule.value.map(String).indexOf(v) !== -1; }
        if (rule.op === 'not_empty') { return v !== ''; }
        return true;
    }

    function sibling(wrap, key) {
        var kids = wrap.parentElement ? wrap.parentElement.children : [];
        for (var i = 0; i < kids.length; i++) {
            if (kids[i].getAttribute('data-catalog-key') === key) { return kids[i]; }
        }
        return null;
    }

    function refresh(root) {
        var wraps = (root || document).querySelectorAll('[data-catalog-key][data-show-if]');
        // Fields are in form order and a rule only points backwards, so one top-to-bottom pass is enough.
        for (var i = 0; i < wraps.length; i++) {
            var w = wraps[i], rule;
            try { rule = JSON.parse(w.getAttribute('data-show-if')); } catch (e) { continue; }
            var show = met(rule, answer(sibling(w, rule.field)));
            w.hidden = !show;
            var cs = w.querySelectorAll('input, select, textarea');
            for (var j = 0; j < cs.length; j++) { cs[j].disabled = !show; }
        }
    }

    window.rivetCatalogShowIf = refresh;
    if (!window.__rivetCatalogShowIfBound) {
        window.__rivetCatalogShowIfBound = true;
        var h = function (e) { if (e.target.closest && e.target.closest('[data-catalog-key]')) { refresh(); } };
        document.addEventListener('input', h);
        document.addEventListener('change', h);
    }
    refresh();
})();
