/*
 * Administration > Endpoint agent > Checks the agents run (markup: includes/rmm_check_schedule_ui.php, rules and translation: includes/rmm_check_schedule.php,
 * handler: admin/post/settings_endpoint_agent.php).
 *
 * This script only helps: presets and Restore defaults fill the options, script rows can be added and removed, and after every change the server is asked what the
 * options would produce (action cs_preview, nothing is saved) so the page can show a one-line summary, plain-language messages and a read-only technical view. The
 * server rebuilds and validates the check list from the options again on Save; nothing here is trusted. Server text is written with textContent only.
 */
(function () {
    'use strict';
    var root = document.getElementById('cs_editor');
    if (!root) { return; }
    var form = root.closest('form');
    var data = {};
    try { data = JSON.parse(root.getAttribute('data-cs') || '{}'); } catch (e) { data = {}; }
    var timer = null, seq = 0;
    var el = function (id) { return document.getElementById(id); };

    function setList(sel, items, cls) {
        var box = el(sel);
        if (!box) { return; }
        box.textContent = '';
        items.forEach(function (t) { var d = document.createElement('div'); d.textContent = t; box.appendChild(d); });
    }

    function collect() {
        var fd = new URLSearchParams();
        fd.set('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        fd.set('cs_preview', '1');
        var dflt = el('cs_defaults');
        if (dflt && dflt.value === '1') { fd.set('cs_defaults', '1'); }
        Array.prototype.forEach.call(form.elements, function (f) {
            if (!f.name || f.name.indexOf('cs[') !== 0 || f.disabled) { return; }
            if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) { return; }
            fd.append(f.name, f.value);
        });
        return fd;
    }

    function refresh() {
        var mine = ++seq;
        fetch(form.getAttribute('action') || 'post.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: collect().toString() })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (mine !== seq) { return; }
                el('cs_summary').textContent = j.summary || '';
                setList('cs_errors', j.errors || []);
                setList('cs_warnings', j.warnings || []);
                var ul = el('cs_lines');
                ul.textContent = '';
                (j.lines || []).forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
                el('cs_tech').textContent = j.json || '';
                root.setAttribute('data-cs-ok', j.success ? '1' : '0');
            })
            .catch(function () { if (mine === seq) { setList('cs_errors', ['Could not reach the server to check these options. They are checked again when you save.']); } });
    }
    function later() { clearTimeout(timer); timer = setTimeout(refresh, 250); }

    function setValues(v) {
        var set = function (name, val) { var f = form.querySelector('[name="cs[' + name + ']"]'); if (f) { if (f.type === 'checkbox') { f.checked = !!Number(val); } else { f.value = String(val); } } };
        set('disk_on', v.disk_on); set('reboot_on', v.reboot_on); set('interval', v.interval);
        var sel = form.querySelector('[name="cs[disk_warn]"]');
        if (sel) {
            if (!Array.prototype.some.call(sel.options, function (o) { return o.value === String(v.disk_warn); })) {
                var o = document.createElement('option'); o.value = String(v.disk_warn); o.textContent = v.disk_warn + '% free'; sel.appendChild(o);
            }
            sel.value = String(v.disk_warn);
        }
        Array.prototype.forEach.call(form.querySelectorAll('input[name="cs[svc][]"]'), function (c) { c.checked = (v.svc || []).indexOf(c.value) !== -1; });
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) { return; }
        if (b.hasAttribute('data-cs-preset')) {
            var k = b.getAttribute('data-cs-preset');
            if (data.presets && data.presets[k]) {
                setValues(data.presets[k]);
                el('cs_defaults').value = '';
                el('cs_preset_about').textContent = b.getAttribute('title') + ' Press Save settings to use it.';
                later();
            }
        } else if (b.hasAttribute('data-cs-defaults')) {
            setValues(data.defaults || {});
            el('cs_defaults').value = '1';
            el('cs_preset_about').textContent = 'The built-in checks are shown. Press Save settings to go back to them (your own scripts and other earlier checks are removed).';
            later();
        } else if (b.hasAttribute('data-cs-add-script')) {
            var tpl = el('cs_script_tpl'), n = Number(b.getAttribute('data-next') || '0');
            if (document.querySelectorAll('.cs-script-row').length >= 10) { return; }
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.innerHTML.split('__I__').join(String(n));
            el('cs_scripts_box').appendChild(wrap.firstElementChild);
            b.setAttribute('data-next', String(n + 1));
            el('cs_scripts_on').checked = true;
            later();
        } else if (b.hasAttribute('data-cs-remove-script')) {
            var row = b.closest('.cs-script-row');
            if (row) { row.parentNode.removeChild(row); later(); }
        }
    });
    root.addEventListener('input', function (e) { if (e.isTrusted !== false) { var d = el('cs_defaults'); if (d && d.value === '1' && !e.target.closest('[data-cs-defaults]')) { d.value = ''; el('cs_preset_about').textContent = ''; } } later(); });
    root.addEventListener('change', function () { var d = el('cs_defaults'); if (d && d.value === '1') { d.value = ''; el('cs_preset_about').textContent = ''; } later(); });
    refresh();
})();
