/*
 * Script library, schedules, approvals and the "Run a library script" card (RMM Phase 2). Loaded after js/rmm_automation.js by the pages that list it in
 * $rmm_auto_extra_js. Two small jobs only: (1) the live size counter of the script editor, (2) keeping the confirmation sentence of a run button in step
 * with the version, script and target the person picked. The sentence is composed here from the same words the server uses for the first render; the server
 * (RivetCore) decides everything that matters, this only informs the person before they confirm. Server text is written with textContent only.
 */
(function () {
    'use strict';

    // ---- editor counter: characters and bytes against the limits (PowerShell is limited in characters)
    var counter = document.querySelector('[data-scr-counter]');
    if (counter) {
        var area = document.querySelector(counter.getAttribute('data-scr-counter'));
        var langSel = counter.getAttribute('data-lang-source') ? document.querySelector(counter.getAttribute('data-lang-source')) : null;
        var maxBytes = parseInt(counter.getAttribute('data-max-bytes'), 10) || 102400;
        var psChars = parseInt(counter.getAttribute('data-ps-chars'), 10) || 10000;
        var enc = window.TextEncoder ? new TextEncoder() : null;
        var update = function () {
            var text = area.value.replace(/\r\n/g, '\n');
            var chars = Array.from(text).length;
            var bytes = enc ? enc.encode(text).length : text.length;
            var lang = langSel ? langSel.value : counter.getAttribute('data-lang');
            var msg = chars.toLocaleString() + ' characters, ' + bytes.toLocaleString() + ' of ' + maxBytes.toLocaleString() + ' bytes';
            var over = bytes > maxBytes;
            if (lang === 'powershell') {
                msg += '; PowerShell limit ' + psChars.toLocaleString() + ' characters';
                over = over || chars > psChars;
            }
            counter.textContent = msg + (over ? ' - too long, it will be refused' : '');
            counter.classList.toggle('text-danger', over);
        };
        if (area) {
            area.addEventListener('input', update);
            if (langSel) { langSel.addEventListener('change', update); }
            update();
        }
    }

    // ---- run buttons: the sentence of the confirmation dialog names the script, the version and the target
    function sentence(name, version, target, destructive) {
        return 'Run the script "' + name + '", version ' + version + ', on ' + target + '. One job is queued for each matching device that is linked and able to run it; a device that is offline runs it when it returns, until the job expires.'
            + (destructive ? ' This script is marked destructive: it changes or removes things on the devices.' : '');
    }
    function apply(btn, need, text, destructive) {
        if (!btn) { return; }
        if (need) {
            btn.setAttribute('data-rmm-confirm', text);
            btn.setAttribute('data-rmm-confirm-label', 'Run script');
            if (destructive) { btn.setAttribute('data-rmm-confirm-check', 'I confirm this destructive script may run now.'); } else { btn.removeAttribute('data-rmm-confirm-check'); }
        } else {
            btn.removeAttribute('data-rmm-confirm');
            btn.removeAttribute('data-rmm-confirm-check');
        }
    }
    function targetText(form) {
        var typeSel = form.querySelector('[data-rmm-scope-type]');
        if (!typeSel) { return { type: 'device', text: 'the chosen target' }; }
        var type = typeSel.value;
        var word = typeSel.options[typeSel.selectedIndex] ? typeSel.options[typeSel.selectedIndex].text : type;
        if (type === 'all') { return { type: type, text: 'all devices' }; }
        var holder = form.querySelector('[data-rmm-scope-for="' + type + '"]');
        var inner = holder ? holder.querySelector('select') : null;
        var which = inner && inner.options[inner.selectedIndex] ? inner.options[inner.selectedIndex].text : '';
        return { type: type, text: word.toLowerCase() + (which ? ' "' + which + '"' : '') };
    }
    function refreshRun(form) {
        var btn = form.querySelector('[data-scr-go]');
        var destructive = form.getAttribute('data-destructive') === '1';
        var vSel = form.querySelector('#scr_run_version');
        var t = targetText(form);
        apply(btn, destructive || t.type !== 'device', sentence(form.getAttribute('data-script-name'), vSel ? vSel.value : '', t.text, destructive), destructive);
    }
    function refreshDevice(form) {
        var btn = form.querySelector('[data-scr-go]');
        var sel = form.querySelector('#rmm-ds-script');
        var opt = sel && sel.options[sel.selectedIndex];
        if (!opt) { return; }
        var destructive = opt.getAttribute('data-destructive') === '1';
        var label = opt.text;
        var m = /^(.*) \(([^()]*), v(\d+)\)$/.exec(label);
        apply(btn, destructive, sentence(m ? m[1] : label, m ? m[3] : '', 'this device (' + form.getAttribute('data-scr-device') + ')', destructive), destructive);
    }
    // the extra checkbox of the confirmation dialog IS the explicit confirmation Core asks for on a destructive script: copy it into the form just before the
    // dialog's Confirm button submits it (capture phase, so this runs before js/rmm_automation.js submits the form)
    var lastGo = null;
    document.addEventListener('click', function (e) {
        var go = e.target.closest ? e.target.closest('[data-scr-go]') : null;
        if (go) { lastGo = go; }
        if (e.target.closest && e.target.closest('#rmmAutoConfirmGo') && lastGo && lastGo.form && lastGo.hasAttribute('data-rmm-confirm-check')) {
            lastGo.form.querySelectorAll('input[name=confirm]:not(:disabled)').forEach(function (i) { i.value = '1'; });
        }
    }, true);
    document.querySelectorAll('form[data-scr-run]').forEach(function (f) {
        var go = function () { refreshRun(f); };
        f.addEventListener('change', go);
        go();
    });
    document.querySelectorAll('form[data-scr-device]').forEach(function (f) {
        var go = function () { refreshDevice(f); };
        f.addEventListener('change', go);
        go();
    });
})();
