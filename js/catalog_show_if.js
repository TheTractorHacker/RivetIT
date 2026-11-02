/*
 * Conditional fields on service catalog request forms. Wrappers carry data-catalog-key and, when conditional,
 * data-show-if='{"field":..,"op":"equals|in|not_empty","value":..}' (or a compound {"match":"all|any","rules":[..]}). A hidden field is disabled so it is not posted
 * and not validated by the browser. This only mirrors ServiceCatalogService::showIfMet(); the server decides.
 */
(function () {
    'use strict';
    function control(wrap) { return wrap ? wrap.querySelector('input, select, textarea') : null; }

    function answer(wrap) {
        if (!wrap || wrap.hidden) { return ''; }
        if (wrap.hasAttribute('data-catalog-table')) {
            // A repeating table answers with its number of non-blank rows ('' when none), like the server.
            var trs = wrap.querySelectorAll('[data-catalog-table-row]'), n = 0;
            for (var r = 0; r < trs.length; r++) {
                var ins = trs[r].querySelectorAll('input');
                for (var k = 0; k < ins.length; k++) { if (String(ins[k].value || '').trim() !== '') { n++; break; } }
            }
            return n > 0 ? String(n) : '';
        }
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

    // A rule is one {field, op, value} or a compound {match: 'all'|'any', rules: [...]} (mirrors ServiceCatalogService::showIfMet).
    function ruleMet(rule, wrap) {
        if (rule && Array.isArray(rule.rules)) {
            var any = rule.match === 'any';
            for (var i = 0; i < rule.rules.length; i++) {
                var m = ruleMet(rule.rules[i], wrap);
                if (any && m) { return true; }
                if (!any && !m) { return false; }
            }
            return !any;
        }
        return met(rule, answer(sibling(wrap, rule.field)));
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
            var show = ruleMet(rule, w);
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
