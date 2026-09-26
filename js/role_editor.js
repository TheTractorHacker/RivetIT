/*
 * Admin › Roles and Admin › Users helpers (admin/modals/role/role_lib.php renders the markup).
 *
 *  - Role form (.js-role-form): "Start from…" presets filled in the browser (nothing is stored
 *    until the form is saved), the help line for each permission's chosen level, the admin
 *    switch (permissions don't apply to admins) and the "this role will see" sidebar preview.
 *  - User form Access tab (.js-user-access-help): says what the department ticks mean for the
 *    chosen role, including Training's "no ticks = nobody" rule for Read / Modify.
 *
 * ajax_modal.js runs this file again on every modal open, so each init marks its element and
 * every listener is bound to the modal's own elements (nothing piles up on document).
 * Everything user-visible is built with textContent.
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }

  function parseJson(raw, fallback) {
    try { return JSON.parse(raw || ''); } catch (e) { return fallback; }
  }

  // ---------------------------------------------------------------- sidebar preview model
  // Mirrors agent/includes/side_nav.php. P.scoped = the module-access layer is installed
  // (includes/module_access.php): logins without Departments / Tickets / Assets then get no
  // Dashboard, Work, CRM, Billing, Products, Finance, custom links or search bar, and Calendar
  // needs Tickets, assets & docs.
  function sidebarFor(L, admin, P) {
    var on = P.on || {};
    var scoped = !!P.scoped;
    function lv(m) { return admin ? 3 : (L[m] || 0); }
    var client = lv('module_client');
    var support = lv('module_support');
    var assets = lv('module_assets');
    var cred = lv('module_credential');
    var sales = lv('module_sales');
    var fin = lv('module_financial');
    var rep = lv('module_reporting');
    var kb = lv('module_kb');
    var rmm = lv('module_rmm');
    var alerts = lv('module_rmm_alerts');
    var tr = lv('module_training');
    var kiosk = lv('module_training_kiosk');
    var limited = scoped && !admin && client < 1 && support < 1 && assets < 1;
    var out = [];
    function add(title, items) { out.push({ title: title, items: items || [] }); }

    if (!limited) { add('Dashboard'); }
    if (alerts >= 1) { add('Alerts'); }
    if (client >= 1) { add('Organization', ['Departments', 'Org Chart']); }
    if (!limited && on.crm && sales >= 1) { add('CRM', ['Pipeline', 'Opportunities', 'Campaigns', 'Segments']); }
    if (support >= 1 && on.ticketing) {
      add('Service Desk', ['Tickets', 'Recurring Tickets', 'Request Something'].concat(on.csat ? ['CSAT Ratings'] : [], ['Requests', 'Problems', 'Changes']));
    }

    var work = [];
    if (support >= 1 && on.ticketing) { work.push('Projects'); }
    if (scoped ? support >= 1 : true) { work.push('Calendar'); }
    if (work.length) { add('Work', work); }

    var know = [];
    if (on.kb && kb >= 1) { know.push('Knowledge Base'); }
    if (on.itdoc && support >= 1 && cred >= 1) { know.push('Credentials'); }
    if (on.itdoc && support >= 1) { know.push('Printers', 'Network Drives'); }
    if (know.length) { add('Knowledge', know); }

    if (on.training && tr >= 1) {
      var t = ['Overview', 'Courses'];
      if (tr >= 2) { t.push('Question Library'); }
      t.push('Learning Paths', 'Assignments', 'Records & sessions', 'Reports');
      if (tr >= 2) { t.push('Achievements'); }
      t.push('People');
      if (kiosk >= 1) { t.push('Devices & PINs'); }
      if (tr >= 2) { t.push('Locked courses', 'Awarded badges'); }
      if (scoped && tr >= 3) { t.push('Training settings'); }
      add('Training', t);
    }

    if (on.itdoc && support >= 1) {
      add('Infrastructure', ['Assets', 'Locations', 'Vendors', 'Licenses', 'Domains', 'Certificates']);
    } else if (on.itdoc && scoped && assets >= 1) {
      add('Infrastructure', ['Assets']);
    }

    if (!limited && on.accounting && sales >= 1) {
      add('Billing', ['Quotes', 'Invoices', 'Recurring Invoices', 'Revenues', 'Products']);
    } else if (!limited && on.ticket_charges && sales >= 1) {
      add('Products');
    }

    if (on.accounting && (scoped ? (!limited && fin >= 1) : true)) {
      var f = [];
      if (fin >= 1) { f.push('Payments', 'Expenses', 'Recurring Expenses', 'Accounts', 'Transfers'); }
      if (!scoped || fin >= 1) { f.push('Trips'); }
      if (f.length) { add('Finance', f); }
    }

    var ep = [];
    if (on.intune && (!scoped || client >= 1)) { ep.push('Intune Devices'); }
    if (on.rmm && rmm >= 1) { ep.push('RMM Dashboard', 'Assets', 'RMM Alerts', 'Scripts', 'Check Policies', 'Network'); }
    if (ep.length) { add('Endpoints', ep); }

    if (on.comet && rmm >= 1) { add('Backups'); }
    if (client >= 1) { add('People'); }
    if (rep >= 1) { add('Reports'); }
    if (!limited && P.custom_links && P.custom_links.length) { add('Custom links', P.custom_links); }

    // Where a limited login lands: includes/module_access.php itflow_limited_home_for(), same order.
    var home = 'their account page';
    if (on.training && tr >= 1) { home = 'Training › Overview'; }
    else if (on.training && kiosk >= 1) { home = 'Training › Devices & PINs'; }
    else if (on.kb && kb >= 1) { home = 'Knowledge Base'; }
    else if (rep >= 1) { home = 'Reports'; }
    else if (on.rmm && rmm >= 1) { home = 'RMM Dashboard'; }
    else if (alerts >= 1) { home = 'Alerts'; }

    return { sections: out, limited: limited, home: home, training: (on.training && tr >= 1) ? tr : 0 };
  }

  // ---------------------------------------------------------------- role form
  function initRoleForm(root) {
    if (root.getAttribute('data-role-init') === '1') { return; }
    root.setAttribute('data-role-init', '1');

    var cfg = parseJson(root.getAttribute('data-role-config'), {});
    var presets = cfg.presets || {};
    var P = cfg.preview || { on: {}, custom_links: [] };
    var form = root.closest('form') || root;
    var rows = Array.prototype.slice.call(root.querySelectorAll('.js-role-perm'));
    var adminRadios = Array.prototype.slice.call(root.querySelectorAll('.js-role-admin'));
    var presetSel = root.querySelector('.js-role-preset');
    var presetStatus = root.querySelector('.js-role-preset-status');
    var previewBox = root.querySelector('.js-role-preview');
    var adminNote = root.querySelector('.js-role-admin-note');
    var selfWarn = root.querySelector('.js-role-self-warning');
    var perms = root.querySelector('.js-role-perms');
    var nameInput = root.querySelector('.js-role-name');
    var descInput = root.querySelector('.js-role-description');
    var submitWord = cfg.isNew ? 'Create' : 'Save';

    function isAdmin() {
      var yes = adminRadios.filter(function (r) { return r.value === '1'; })[0];
      return !!(yes && yes.checked);
    }

    function rowLevel(row) {
      var c = row.querySelector('input[type="radio"]:checked');
      return c ? Math.max(0, Math.min(3, parseInt(c.value, 10) || 0)) : 0;
    }

    function levels() {
      var L = {};
      rows.forEach(function (row) { L[row.getAttribute('data-module')] = rowLevel(row); });
      return L;
    }

    function syncActive(row) {
      row.querySelectorAll('label.btn').forEach(function (label) {
        var input = label.querySelector('input');
        label.classList.toggle('active', !!(input && input.checked));
      });
    }

    function updateHelp(row) {
      var help = parseJson(row.getAttribute('data-help'), {});
      var names = parseJson(row.getAttribute('data-level-names'), {});
      var level = rowLevel(row);
      var box = row.querySelector('.js-role-perm-help');
      if (!box) { return; }
      box.textContent = '';
      box.appendChild(el('span', 'fw-bold', (names[level] || '') + ':'));
      box.appendChild(document.createTextNode(' ' + (help[level] || '')));
    }

    function setLevel(row, level) {
      var flag = row.getAttribute('data-flag') === '1';
      var radios = Array.prototype.slice.call(row.querySelectorAll('input[type="radio"]'));
      var target = null;
      radios.forEach(function (r) {
        var v = parseInt(r.value, 10) || 0;
        if (flag ? ((v > 0) === (level > 0)) : (v === level)) { target = r; }
      });
      if (target) { target.checked = true; }
      syncActive(row);
      updateHelp(row);
    }

    function renderPreview() {
      if (!previewBox) { return; }
      var admin = isAdmin();
      var model = sidebarFor(levels(), admin, P);
      previewBox.textContent = '';


      if (admin) {
        previewBox.appendChild(el('div', 'small', 'Everything: every menu and every department, plus Admin settings.'));
        return;
      }

      if (!model.sections.length) {
        previewBox.appendChild(el('div', 'small text-secondary', 'Nothing yet. Choose at least one permission.'));
      } else {
        var list = el('ul', 'small mb-2');
        model.sections.forEach(function (s) {
          var li = el('li');
          li.appendChild(el('span', 'role-preview-section', s.title));
          if (s.items.length) {
            li.appendChild(el('div', 'text-secondary', s.items.join(' · ')));
          }
          list.appendChild(li);
        });
        previewBox.appendChild(list);
      }

      var L = levels();
      var facts = el('div', 'small border-top pt-2');
      var opens;
      if (model.limited) {
        opens = model.home;
      } else if (!P.scoped && P.start_page === 'dashboard.php' && model.training && !(L.module_client >= 1) && !(L.module_support >= 1)) {
        // agent/dashboard.php sends Training-only roles on to the Training overview
        opens = 'Training › Overview';
      } else {
        opens = P.start_label || 'Dashboard';
      }
      var line = el('div');
      line.appendChild(el('span', 'fw-bold', 'Opens on: '));
      line.appendChild(document.createTextNode(opens));
      facts.appendChild(line);
      if (P.scoped) {
        var search = el('div');
        search.appendChild(el('span', 'fw-bold', 'Search bar: '));
        search.appendChild(document.createTextNode(model.limited ? 'hidden' : 'shown, with results only from the menus above'));
        facts.appendChild(search);
      }
      if (model.training === 1 || model.training === 2) {
        facts.appendChild(el('div', 'text-secondary mt-1', 'Training shows only the departments ticked on each user\'s Access tab. No ticks = nobody.'));
      } else if (model.training === 3) {
        facts.appendChild(el('div', 'text-secondary mt-1', 'Training Full sees every department; Access-tab ticks don\'t narrow it.'));
      }
      previewBox.appendChild(facts);
    }

    function applyAdminState() {
      var admin = isAdmin();
      rows.forEach(function (row) {
        row.querySelectorAll('input[type="radio"]').forEach(function (r) { r.disabled = admin; });
      });
      if (presetSel) { presetSel.disabled = admin; }
      if (perms) { perms.classList.toggle('role-perms-disabled', admin); }
      if (adminNote) { adminNote.hidden = !admin; }
      if (selfWarn) { selfWarn.hidden = !(cfg.isOwnRole && cfg.wasAdmin && !admin); }
    }

    function applyPreset(key) {
      var p = presets[key];
      if (!p) { return; }
      var L = p.levels || {};
      rows.forEach(function (row) {
        var m = row.getAttribute('data-module');
        setLevel(row, Math.max(0, Math.min(3, parseInt(L[m], 10) || 0)));
      });
      var no = adminRadios.filter(function (r) { return r.value === '0'; })[0];
      if (no && !no.disabled) { no.checked = true; }
      if (nameInput && !nameInput.value.trim() && p.name) { nameInput.value = p.name; }
      if (descInput && !descInput.value.trim() && p.description) { descInput.value = p.description; }
      applyAdminState();
      renderPreview();
      if (presetStatus) {
        presetStatus.textContent = '';
        presetStatus.appendChild(el('span', 'fw-bold', 'Filled from "' + p.label + '". '));
        presetStatus.appendChild(document.createTextNode((p.note || '') + ' Nothing is saved until you click ' + submitWord + '.'));
      }
    }

    // Level buttons and the admin switch
    form.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || t.type !== 'radio' || !root.contains(t)) { return; }
      if (t.classList.contains('js-role-admin')) {
        applyAdminState();
        renderPreview();
        return;
      }
      var row = t.closest('.js-role-perm');
      if (row) {
        syncActive(row);
        updateHelp(row);
        renderPreview();
      }
    });

    if (presetSel) {
      presetSel.addEventListener('change', function () {
        var key = presetSel.value;
        presetSel.value = '';
        applyPreset(key);
      });
    }

    // "start from a preset" link on the Details tab of a new role
    root.querySelectorAll('.js-role-goto-permissions').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var pill = root.querySelector('.nav-link[href="' + link.getAttribute('href') + '"]');
        if (pill && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(pill).show(); }
        if (presetSel) { setTimeout(function () { presetSel.focus(); }, 200); }
      });
    });

    rows.forEach(function (row) { syncActive(row); updateHelp(row); });
    applyAdminState();
    renderPreview();
  }

  // ---------------------------------------------------------------- user form Access tab
  function initAccessHelp(box) {
    if (box.getAttribute('data-access-init') === '1') { return; }
    box.setAttribute('data-access-init', '1');

    var roles = parseJson(box.getAttribute('data-roles'), {});
    var trainingOn = box.getAttribute('data-training-on') === '1';
    var select = document.getElementById(box.getAttribute('data-role-select') || '');
    var pane = box.closest('.tab-pane');
    var form = box.closest('form');
    var out = box.querySelector('.js-user-access-role');
    if (!select || !pane || !out || !form) { return; }

    function render() {
      var r = roles[select.value];
      out.textContent = '';
      if (!r) { out.hidden = true; return; }
      out.hidden = false;
      var ticked = pane.querySelectorAll('.client-checkbox:checked').length;
      var head = el('div', 'fw-bold mt-2', 'For the ' + r.name + ' role:');
      out.appendChild(head);
      var ul = el('ul', 'mb-0 ps-3');
      function li(text, cls) { ul.appendChild(el('li', cls || '', text)); }
      if (r.admin) {
        li('Administrator: every department everywhere. Ticks are ignored.');
      } else {
        li(ticked ? 'Most pages: only the ' + ticked + ' ticked department' + (ticked === 1 ? '' : 's') + '.' : 'Most pages: every department (nothing is ticked).');
        if (trainingOn && r.training >= 3) {
          li('Training: every department (Training Full ignores ticks).');
        } else if (trainingOn && r.training >= 1) {
          if (ticked) {
            li('Training: only people in the ' + ticked + ' ticked department' + (ticked === 1 ? '' : 's') + '.');
          } else {
            li('Training: nobody yet. Tick at least one department for this person to see anyone in Training.', 'text-danger fw-bold');
          }
        }
      }
      out.appendChild(ul);
    }

    select.addEventListener('change', render);
    pane.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('client-checkbox')) { render(); }
    });
    // "Select all" sets .checked without a change event
    pane.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('.js-toggle-all-clients')) { setTimeout(render, 0); }
    });
    render();
  }

  function initAll() {
    document.querySelectorAll('.js-role-form').forEach(initRoleForm);
    document.querySelectorAll('.js-user-access-help').forEach(initAccessHelp);
  }

  // Exposed for tests / the console only.
  window.itflowRoleEditor = { sidebarFor: sidebarFor, init: initAll };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
