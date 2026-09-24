/*
 * Training › People (Phase 2 spec §5.2): roster, job groups, trainers and Odoo link states.
 *
 * Renders the server's first data (#tr-page-data) and refetches through people_roster,
 * jobgroup_list / jobgroup_get / jobgroup_titles and trainer_list. Level 3 edits: roster
 * include/exclude (TrainingOps 'roster'), hire date (TrainingOps 'hire_date'), the job-group
 * and trainer editors (offcanvas, jobgroup_save / jobgroup_archive / trainer_save with the
 * row version; a 409 offers a reload). DOM nodes only.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var UI = window.TrainingUi;
        var Ops = window.TrainingOps;
        var page = document.getElementById('tro-people-page');
        if (!UI || !Ops || !page) { return; }
        var u = Ops.u;
        var el = u.el;
        var D = UI.readJson('tr-page-data');
        Ops.init(D);
        var level = Number(D.level || 0);
        var routes = D.routes || {};
        var $ = function (id) { return document.getElementById(id); };
        var clear = u.clear;

        var LINK = {
            ok: ['ok', 'fas fa-link', 'Linked'],
            unchecked: ['outline', null, 'Not checked yet'],
            mismatch: ['warn', 'fas fa-exclamation-triangle', 'Name differs'],
            missing: ['err', 'fas fa-unlink', 'Not found in Odoo'],
            repointed: ['err', 'fas fa-random', 'Points at another employee']
        };
        var LINK_TEXT = {
            ok: 'The Odoo employee still matches this person.',
            unchecked: 'The link has not been checked since it was created.',
            mismatch: 'The name in Odoo no longer matches the name that was linked. The Odoo employee may have been reused for someone else.',
            missing: 'The linked Odoo employee was not in the last directory sync. It may have been deleted in Odoo.',
            repointed: 'The link now points at a different Odoo employee than before.'
        };
        var ISSUE_STATES = ['mismatch', 'missing', 'repointed'];
        function linkChip(p) {
            if (!p.odoo_linked && !p.link_state) { return u.chip('Not linked', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm' }); }
            var m = LINK[p.link_state] || ['outline', null, String(p.link_state || 'Unknown')];
            return u.chip(m[2], m[0], m[1]);
        }
        function deptName(id) {
            id = Number(id);
            for (var i = 0; i < (D.departments || []).length; i++) { if (Number(D.departments[i].id) === id) { return D.departments[i].name; } }
            return null;
        }
        function noPeopleState() {
            return u.emptyState({ icon: 'fas fa-user-lock', title: 'No people to show', text: 'Ask an administrator to grant department access to see people.' });
        }

        // ============================================================================
        // Roster
        // ============================================================================
        if ($('tro-pr-body')) {
            (function () {
                var f = Object.assign({ page: 1 }, D.filters || {});
                var body = $('tro-pr-body'), empty = $('tro-pr-empty'), pagerNode = $('tro-pr-pager');
                var q = $('tro-pr-q'), dept = $('tro-pr-dept'), state = $('tro-pr-state');
                var seq = 0;
                var flashId = null;

                function params() {
                    var p = {};
                    ['q', 'client_id', 'state', 'page'].forEach(function (k) { if (f[k] !== null && f[k] !== undefined && f[k] !== '') { p[k] = f[k]; } });
                    if (Number(p.page) === 1) { delete p.page; }
                    return p;
                }
                function refetch() {
                    var mySeq = ++seq;
                    u.skeletonRows(body, 7, 8);
                    clear(empty);
                    var p = params();
                    u.setUrl(p);
                    return u.fetchAction('people_roster', p).then(function (d) { if (mySeq === seq) { render(d || {}); } }, function (err) {
                        if (mySeq === seq) { fail(err); }
                    });
                }
                function fail(err) { clear(body); clear(empty); pagerNode.hidden = true; empty.appendChild(u.failState(err, refetch)); }
                function render(d) {
                    var rows = d.people || d.rows || [];
                    clear(body);
                    clear(empty);
                    rows.forEach(function (p) { body.appendChild(row(p)); });
                    if (!rows.length) {
                        var filtered = !!(f.q || f.state || (f.client_id !== null && f.client_id !== undefined && f.client_id !== ''));
                        empty.appendChild(D.scope === 'none' ? noPeopleState()
                            : filtered ? u.emptyState({ icon: 'fas fa-filter', title: 'No one matches', text: 'Try another department or state, or clear the search.',
                                actions: [el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Clear filters', on: { click: clearFilters } })] })
                                : u.emptyState({ icon: 'fas fa-users', title: 'No people yet', text: 'People come from Contacts. Everyone with a department is on the training roster automatically.' }));
                    }
                    u.pager(pagerNode, Number(d.total || 0), rows.length, Number(f.page || 1), function (n) { f.page = n; refetch(); });
                    if (flashId) {
                        var tr = body.querySelector('tr[data-id="' + flashId + '"]');
                        if (tr) { tr.classList.add('is-flash'); }
                        flashId = null;
                    }
                }
                function rosterCell(p) {
                    var st = p.roster_state || 'auto';
                    var chipNode, sub;
                    if (p.archived) {
                        chipNode = u.chip('Archived', 'outline');
                        sub = 'Archived contacts are not on the roster';
                    } else if (st === 'exclude') {
                        chipNode = u.chip('Excluded', 'err', 'fas fa-user-slash');
                        sub = p.roster_reason || 'Excluded by hand';
                    } else if (p.eligible) {
                        chipNode = u.chip('On the roster', 'ok', 'fas fa-check');
                        sub = st === 'include' ? 'Included by hand' + (p.roster_reason ? ': ' + p.roster_reason : '') : 'Automatic';
                    } else if (!p.department) {
                        chipNode = u.chip('No department', 'warn', 'fas fa-exclamation-triangle');
                        sub = 'Rules do not reach them until they get a department or are included';
                    } else {
                        chipNode = u.chip('Not on the roster', 'outline');
                        sub = p.roster_reason || '';
                    }
                    return el('td', {}, [chipNode, sub ? el('span', { class: 'tro-sub mt-1', text: sub }) : null]);
                }
                function row(p) {
                    var who = p.name || ('Person #' + p.contact_id);
                    var st = p.roster_state || 'auto';
                    var hire = el('td', { class: 'tro-nowrap' });
                    if (p.hire_date) {
                        hire.appendChild(el('span', { text: u.fmtDate(p.hire_date) }));
                        var dd = u.diffDays(u.today(), p.hire_date);
                        if (dd !== null && dd > 0) { hire.appendChild(el('span', { class: 'tro-sub', text: 'starts in ' + u.plural(dd, 'day') })); }
                        else if (dd !== null && dd >= -90) { hire.appendChild(el('span', { class: 'tro-sub', text: 'new hire' })); }
                    } else {
                        hire.appendChild(el('span', { class: 'text-muted', text: 'Not set' }));
                    }
                    var items = [];
                    if (level >= 3 && routes.roster_set !== false && !p.archived) {
                        if (st !== 'exclude') { items.push({ label: 'Exclude from training…', icon: 'fas fa-user-slash', danger: true, onClick: function () { roster(p, 'exclude'); } }); }
                        if (!p.department && st !== 'include') { items.push({ label: 'Include on the roster…', icon: 'fas fa-user-check', onClick: function () { roster(p, 'include'); } }); }
                        if (st !== 'auto') { items.push({ label: 'Back to automatic…', icon: 'fas fa-undo', onClick: function () { roster(p, 'auto'); } }); }
                    }
                    if (level >= 3 && routes.hire_date_set !== false && !p.archived) {
                        items.push({ label: p.hire_date ? 'Change hire date / Rehired…' : 'Set hire date…', icon: 'far fa-calendar-alt', onClick: function () { hireDate(p); } });
                    }
                    if (level >= 2 && p.eligible && routes.assign_manual !== false) {
                        items.push({ label: 'Assign training…', icon: 'fas fa-user-plus', onClick: function () { Ops.open('assign', { people: [p] }); } });
                    }
                    items.push('-');
                    items.push({ label: 'Open transcript', icon: 'fas fa-id-badge', href: u.transcriptUrl(p.contact_id) });
                    items.push({ label: 'Assignments', icon: 'fas fa-tasks', href: '/agent/training_assignments.php?contact_id=' + encodeURIComponent(p.contact_id) + '&status=all' });
                    var jobLoc = el('td', {}, [
                        el('span', { class: p.job ? null : 'text-muted', text: p.job ? p.job.name : 'No job position' }),
                        p.location ? el('span', { class: 'tro-sub', text: p.location.name }) : null
                    ]);
                    return el('tr', { dataset: { id: p.contact_id } }, [
                        el('td', {}, [u.personCell(p, { sub: [p.title, p.employee_no ? '#' + p.employee_no : null].filter(Boolean).join(' · ') })]),
                        el('td', { class: p.department ? null : 'text-muted', text: p.department ? p.department.name : 'No department' }),
                        jobLoc,
                        hire,
                        rosterCell(p),
                        el('td', {}, [linkChip(p)]),
                        el('td', { class: 'tro-actions' }, [u.kebab('Actions for ' + who, items)])
                    ]);
                }
                function roster(p, st) {
                    Ops.open('roster', { person: p, state: st }).then(function (res) { if (res) { flashId = p.contact_id; refetch(); } });
                }
                function hireDate(p) {
                    Ops.open('hire_date', { person: p }).then(function (res) { if (res) { flashId = p.contact_id; refetch(); } });
                }
                function clearFilters() {
                    f = { page: 1 };
                    q.value = ''; dept.value = ''; state.value = '';
                    refetch();
                }
                var onSearch = UI.debounce(function () { f.q = q.value.trim() || null; f.page = 1; refetch(); }, 300);
                q.addEventListener('input', onSearch);
                dept.addEventListener('change', function () { f.client_id = dept.value === '' ? null : Number(dept.value); f.page = 1; refetch(); });
                state.addEventListener('change', function () { f.state = state.value || null; f.page = 1; refetch(); });
                $('tro-pr-filters').addEventListener('submit', function (e) { e.preventDefault(); onSearch.flush(); });
                u.skeletonRows(body, 7, 8);
                u.load(D.roster).then(render, fail);
            })();
        }

        // ============================================================================
        // Job groups
        // ============================================================================
        if ($('tro-pg-grid')) {
            (function () {
                var grid = $('tro-pg-grid'), empty = $('tro-pg-empty');
                var showArchived = false;
                var groups = [];
                var titleCache = null;

                function listOf(d) { return (d && (d.groups || d.rows || d.job_groups)) || (Array.isArray(d) ? d : []); }
                function refetch() {
                    skeleton();
                    return u.fetchAction('jobgroup_list', {}).then(function (d) { render(listOf(d)); }, fail);
                }
                function skeleton() {
                    clear(grid); clear(empty);
                    for (var i = 0; i < 3; i++) {
                        grid.appendChild(el('div', { class: 'tro-tile', 'aria-hidden': 'true' }, [el('span', { class: 'tro-skel tro-skel--w60' }), el('span', { class: 'tro-skel tro-skel--w80' }), el('span', { class: 'tro-skel tro-skel--w40' })]));
                    }
                }
                function fail(err) { clear(grid); clear(empty); empty.appendChild(u.failState(err, refetch)); }
                function render(list) {
                    groups = list;
                    clear(grid); clear(empty);
                    var active = list.filter(function (g) { return !g.archived; });
                    var archived = list.filter(function (g) { return !!g.archived; });
                    (showArchived ? list : active).forEach(function (g) { grid.appendChild(tile(g)); });
                    if (!active.length && !showArchived) {
                        empty.appendChild(u.emptyState({ icon: 'fas fa-users-cog', title: 'No job groups yet', text: 'Group people by job title, for example every "Welder" and "Lead Welder", so a rule can require training for all of them.',
                            actions: level >= 3 && routes.jobgroup_save !== false ? [el('button', { type: 'button', class: 'btn btn-primary', on: { click: function () { edit(null); } } }, [u.icon('fas fa-plus me-2'), 'New job group'])] : [] }));
                    }
                    if (archived.length) {
                        empty.appendChild(el('div', { class: 'px-4 pb-3' }, [el('button', { type: 'button', class: 'btn btn-link btn-sm p-0', text: showArchived ? 'Hide archived groups' : 'Show archived groups (' + archived.length + ')',
                            on: { click: function () { showArchived = !showArchived; render(groups); } } })]));
                    }
                }
                function tile(g) {
                    var titles = Array.isArray(g.titles) ? g.titles : [];
                    var named = g.member_ids ? g.member_ids.length : (Array.isArray(g.members) ? g.members.length : (g.named_count !== undefined ? Number(g.named_count) : null));
                    var meta = [];
                    if (g.member_count !== undefined && g.member_count !== null) { meta.push(u.plural(Number(g.member_count), 'person', 'people')); }
                    meta.push(u.plural(titles.length, 'job title'));
                    if (named !== null) { meta.push(named + ' named'); }
                    var chips = el('div', { class: 'tro-chips' });
                    titles.slice(0, 5).forEach(function (t) { chips.appendChild(el('span', { class: 'tro-crit', text: t })); });
                    if (titles.length > 5) { chips.appendChild(el('span', { class: 'tro-crit', text: '+' + (titles.length - 5), title: titles.slice(5).join(', ') })); }
                    var node = el('div', { class: 'tro-tile' + (g.archived ? ' is-archived' : ''), dataset: { id: g.id } }, [
                        el('div', { class: 'd-flex align-items-start gap-2' }, [el('div', { class: 'tro-tile__title flex-grow-1', text: g.name }),
                            g.archived ? u.chip('Archived', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm' }) : null]),
                        g.description ? el('div', { class: 'small', text: g.description }) : null,
                        el('div', { class: 'tro-tile__meta', text: meta.join(' · ') }),
                        titles.length ? chips : null
                    ]);
                    var foot = el('div', { class: 'tro-tile__foot' });
                    var canEdit = level >= 3 && routes.jobgroup_save !== false && !g.archived;
                    foot.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', on: { click: function () { edit(g); } } }, [u.icon((canEdit ? 'fas fa-pen' : 'far fa-eye') + ' me-1'), canEdit ? 'Edit' : 'View']));
                    if (canEdit && routes.jobgroup_archive !== false) {
                        foot.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost-danger ms-auto', on: { click: function () { archive(g, node); } } }, [u.icon('fas fa-archive me-1'), 'Archive…']));
                    }
                    node.appendChild(foot);
                    return node;
                }
                function archive(g, node) {
                    var old = node.querySelector('.tr-confirm-bar');
                    if (old) { old.parentNode.removeChild(old); return; }
                    var yes = el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Archive group' });
                    var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
                    var msg = el('div', { class: 'small fw-semibold' });
                    var bar = el('div', { class: 'tr-confirm-bar tro-reason-bar alert alert-warning mb-0 mt-2', role: 'alertdialog', 'aria-label': 'Archive job group ' + g.name }, [
                        el('div', { class: 'fw-semibold', text: 'Archive "' + g.name + '"?' }),
                        el('div', { class: 'small', text: 'Rules that target this group stop matching its people, and their open assignments from those rules are cancelled. Records stay.' }),
                        msg, el('div', { class: 'tro-reason-bar__row' }, [el('span', { class: 'me-auto' }), no, yes])
                    ]);
                    node.appendChild(bar);
                    yes.focus();
                    no.addEventListener('click', function () { bar.parentNode.removeChild(bar); });
                    yes.addEventListener('click', function () {
                        u.busy(yes, true, 'Archiving…');
                        u.post('jobgroup_archive', { id: g.id }).then(function () {
                            UI.toast('Job group archived.');
                            refetch();
                        }, function (err) { u.busy(yes, false); msg.textContent = u.errorText(err); });
                    });
                }

                function loadTitles() {
                    if (titleCache) { return Promise.resolve(titleCache); }
                    if (level < 3 || routes.jobgroup_titles === false) { return Promise.resolve([]); }
                    return u.fetchAction('jobgroup_titles', {}).then(function (d) { titleCache = (d && d.titles) || []; return titleCache; }, function () { return []; });
                }
                function edit(g) {
                    var canEdit = level >= 3 && routes.jobgroup_save !== false && !(g && g.archived);
                    var holder = el('div', {}, [el('div', { class: 'tr-skeleton' }, [el('div', { class: 'tro-skel tro-skel--w60 mb-3' }), el('div', { class: 'tro-skel tro-skel--w80 mb-3' }), el('div', { class: 'tro-skel tro-skel--w40' })])]);
                    var h = Ops.sheet({ title: g ? (canEdit ? 'Edit job group' : g.name) : 'New job group', subtitle: g && canEdit ? g.name : '', body: holder, foot: [], wide: true });
                    var detail = g ? u.fetchAction('jobgroup_get', { jobgroup_id: g.id }).then(function (d) { return (d && (d.group || d)) || g; }) : Promise.resolve(null);
                    Promise.all([detail, canEdit ? loadTitles() : Promise.resolve([])]).then(function (res) {
                        var full = res[0];
                        if (canEdit) { editor(h, full, res[1]); } else { viewer(h, full || g); }
                    }, function (err) { h.setBody(u.failState(err)); });
                }
                function viewer(h, g) {
                    var titles = Array.isArray(g.titles) ? g.titles : [];
                    var members = Array.isArray(g.members) ? g.members : [];
                    var wrap = el('div', {}, [
                        g.description ? el('p', { text: g.description }) : null,
                        el('h3', { class: 'h5 mt-2', text: 'Job titles' }),
                        titles.length ? el('div', { class: 'tro-chips mb-3' }, titles.map(function (t) { return el('span', { class: 'tro-crit', text: t }); })) : el('p', { class: 'text-muted small', text: 'No titles. Only the people named below are in this group.' }),
                        el('h3', { class: 'h5 mt-2', text: 'Named people' }),
                        members.length ? el('ul', { class: 'tro-outcomes' }, members.map(function (p) { return el('li', {}, [u.personCell(p)]); })) : el('p', { class: 'text-muted small', text: 'No one is named by hand.' })
                    ]);
                    h.setBody(wrap);
                    h.setFoot([el('button', { type: 'button', class: 'btn btn-primary ms-auto', text: 'Close', dataset: { bsDismiss: 'offcanvas' } })]);
                }
                function editor(h, g, titleOpts) {
                    g = g || null;
                    var form = el('form', { novalidate: true, autocomplete: 'off' });
                    var name = el('input', { type: 'text', class: 'form-control', maxlength: '100', required: true, value: g ? g.name : null, placeholder: 'For example: Welders' });
                    var desc = el('input', { type: 'text', class: 'form-control', maxlength: '255', value: g && g.description ? g.description : null, placeholder: 'Optional: who belongs here' });
                    var chosen = {};
                    (Array.isArray(g && g.titles) ? g.titles : []).forEach(function (t) { chosen[String(t).toLowerCase()] = true; });
                    var counts = {};
                    titleOpts.forEach(function (t) { counts[String(t.title).toLowerCase()] = Number(t.count || 0); });
                    Object.keys(chosen).forEach(function (t) { if (counts[t] === undefined) { counts[t] = 0; } });
                    var filter = el('input', { type: 'search', class: 'form-control mb-2', placeholder: 'Filter job titles', 'aria-label': 'Filter job titles' });
                    var list = el('div', { class: 'tro-check-list', role: 'group', 'aria-label': 'Job titles' });
                    var picked = el('div', { class: 'form-hint' });
                    var picker = new Ops.PeoplePicker({ initial: (g && Array.isArray(g.members)) ? g.members : [], onChange: sync, placeholder: 'Add someone by name' });
                    var summary = el('div', { class: 'tro-consequence mt-3' }, [u.icon('fas fa-users'), el('div', {})]);

                    function renderTitles() {
                        clear(list);
                        var q = filter.value.trim().toLowerCase();
                        var keys = Object.keys(counts).sort(function (a, b) { return (chosen[b] ? 1 : 0) - (chosen[a] ? 1 : 0) || a.localeCompare(b); });
                        var shown = 0;
                        keys.forEach(function (t, i) {
                            if (q && t.indexOf(q) === -1) { return; }
                            shown++;
                            var id = 'tro-jg-t' + i;
                            var cb = el('input', { type: 'checkbox', class: 'form-check-input', id: id, checked: chosen[t] ? true : null });
                            cb.addEventListener('change', function () { if (cb.checked) { chosen[t] = true; } else { delete chosen[t]; } sync(); });
                            list.appendChild(el('div', { class: 'form-check' }, [cb, el('label', { class: 'form-check-label', for: id, text: t }),
                                el('span', { class: 'tro-count', text: counts[t] ? u.plural(counts[t], 'person', 'people') : 'no one now' })]));
                        });
                        if (!shown) { list.appendChild(el('div', { class: 'text-muted small py-2', text: keys.length ? 'No job title matches.' : 'No job titles on the roster yet.' })); }
                    }
                    function sync() {
                        var ts = Object.keys(chosen);
                        var byTitle = ts.reduce(function (s, t) { return s + (counts[t] || 0); }, 0);
                        var n = picker.ids().length;
                        picked.textContent = ts.length ? ts.length + ' selected' : 'None selected';
                        summary.lastChild.textContent = (ts.length || n)
                            ? 'About ' + u.plural(byTitle + n, 'person', 'people') + ': ' + byTitle + ' by job title and ' + n + ' named (someone in both is counted once). People who get one of these titles later join automatically.'
                            : 'Pick at least one job title or person.';
                        save.disabled = !(name.value.trim() && (ts.length || n));
                    }
                    filter.addEventListener('input', renderTitles);
                    name.addEventListener('input', sync);

                    form.appendChild(u.field({ label: 'Name', control: name, name: 'name', required: true }));
                    form.appendChild(u.field({ label: 'Description', control: desc, name: 'description' }));
                    var tf = u.field({ label: 'Job titles', control: el('div', {}, [filter, list, picked]), name: 'titles', hint: 'Everyone whose title matches (not case-sensitive) is in the group.' });
                    form.appendChild(tf);
                    form.appendChild(u.field({ label: 'Named people', control: picker.root, name: 'members', hint: 'People who belong whatever their title says.' }));
                    form.appendChild(summary);
                    var save = el('button', { type: 'submit', class: 'btn btn-primary', text: g ? 'Save group' : 'Create group' });
                    form.id = 'tro-jg-form';
                    save.setAttribute('form', 'tro-jg-form');
                    h.setTitle(g ? 'Edit job group' : 'New job group', g ? g.name : 'Rules can target it once saved.');
                    h.setBody(form);
                    h.setFoot([el('span', { class: 'tro-oc__note', text: 'Saving can change who is assigned training right away.' }), u.cancelBtn(), save]);
                    renderTitles();
                    sync();
                    setTimeout(function () { name.focus(); }, 300);
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (save.disabled) { return; }
                        u.clearFieldErrors(form);
                        u.alertBox(form, null);
                        u.busy(save, true, 'Saving…');
                        var body = { name: name.value.trim(), description: desc.value.trim() || null, members: picker.ids(), titles: Object.keys(chosen) };
                        if (g) { body.id = g.id; body.version = g.version; }
                        u.post('jobgroup_save', body).then(function (d) {
                            var rec = (d && d.reconcile) || {};
                            UI.toast(rec.error ? 'Job group saved. Assignments will update on the next recalculation.' : 'Job group saved.', { type: rec.error ? 'warning' : 'success' });
                            h.close();
                            refetch();
                        }, function (err) {
                            u.busy(save, false);
                            if (err && err.code === 'conflict') {
                                u.alertBox(form, 'Someone else changed this group while you were editing. Reload it to see their changes, then make yours again.', 'warning');
                                var box = form.querySelector('.tro-form-alert > div');
                                if (box) { box.appendChild(el('div', { class: 'mt-2' }, [el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Reload the group', on: { click: function () { edit(g); } } })])); }
                                return;
                            }
                            if (!u.showFieldErrors(form, err) || err.code !== 'validation') { u.alertBox(form, u.errorText(err)); }
                        });
                    });
                }

                var newBtn = $('tro-pg-new');
                if (newBtn) { newBtn.hidden = routes.jobgroup_save === false; newBtn.addEventListener('click', function () { edit(null); }); }
                skeleton();
                u.load(D.groups).then(function (d) { render(listOf(d)); }, fail);
            })();
        }

        // ============================================================================
        // Trainers
        // ============================================================================
        if ($('tro-pt-body')) {
            (function () {
                var body = $('tro-pt-body'), empty = $('tro-pt-empty');
                var FLAGS = [
                    ['can_train', 'Teach sessions', 'Can be picked as the trainer on a classroom or hands-on session.'],
                    ['can_evaluate', 'Run practical evaluations', 'Can sign off hands-on checks (forklift, crane, and so on).'],
                    ['can_view_team', 'See their team', 'Sees the training status of the people they train on the kiosk.'],
                    ['can_setup_pins', 'Set up kiosk PINs', 'Helps people create their kiosk PIN.'],
                    ['can_unlock', 'Unlock kiosk sign-ins', 'Unlocks a person who was locked out after wrong PINs.']
                ];
                var FLAG_SHORT = { can_train: 'Teaches', can_evaluate: 'Evaluates', can_view_team: 'Sees team', can_setup_pins: 'Sets up PINs', can_unlock: 'Unlocks' };

                function listOf(d) { return (d && (d.trainers || d.rows)) || (Array.isArray(d) ? d : []); }
                function refetch() {
                    u.skeletonRows(body, 6, 3);
                    clear(empty);
                    return u.fetchAction('trainer_list', {}).then(function (d) { render(listOf(d)); }, fail);
                }
                function fail(err) { clear(body); clear(empty); empty.appendChild(u.failState(err, refetch)); }
                function render(list) {
                    clear(body); clear(empty);
                    list.slice().sort(function (a, b) { return (b.active !== false) - (a.active !== false) || String(a.person ? a.person.name : '').localeCompare(String(b.person ? b.person.name : '')); })
                        .forEach(function (t) { body.appendChild(row(t)); });
                    if (!list.length) {
                        empty.appendChild(u.emptyState({ icon: 'fas fa-chalkboard-teacher', title: 'No trainers yet', text: 'Add the people who teach sessions or run practical evaluations. Sessions can still name an outside instructor.',
                            actions: level >= 3 && routes.trainer_save !== false ? [el('button', { type: 'button', class: 'btn btn-primary', on: { click: function () { edit(null); } } }, [u.icon('fas fa-user-plus me-2'), 'Add trainer'])] : [] }));
                    }
                }
                function names(ids, lookup, max) {
                    var out = (ids || []).map(function (id) { return lookup(id); }).filter(Boolean);
                    return { text: out.slice(0, max).join(', ') + (out.length > max ? ' +' + (out.length - max) : ''), all: out.join(', ') };
                }
                function courseName(id) { var c = u.courseById(id); return c ? c.name : null; }
                function row(t) {
                    var p = t.person || { contact_id: t.contact_id, name: 'Person #' + t.contact_id };
                    var flags = t.flags || {};
                    var may = el('div', { class: 'tro-chips' });
                    Object.keys(FLAG_SHORT).forEach(function (k) { if (flags[k]) { may.appendChild(u.chip(FLAG_SHORT[k], k === 'can_train' || k === 'can_evaluate' ? 'info' : 'outline', null, { class: 'tro-chip tro-chip--' + (k === 'can_train' || k === 'can_evaluate' ? 'info' : 'outline') + ' tro-chip--sm' })); } });
                    if (!may.childNodes.length) { may.appendChild(el('span', { class: 'text-muted', text: 'Nothing yet' })); }
                    var cn = t.all_courses ? { text: 'All courses', all: '' } : names(t.course_ids, courseName, 2);
                    var dn = t.all_departments ? { text: 'All departments', all: '' } : names(t.client_ids, deptName, 2);
                    var inactive = t.active === false;
                    var canEdit = level >= 3 && routes.trainer_save !== false;
                    var tr = el('tr', { class: inactive ? 'is-voided' : null, dataset: { id: t.contact_id } }, [
                        el('td', {}, [u.personCell(p, { sub: t.title || p.title || '' }), inactive ? u.chip('Inactive', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm mt-1' }) : null]),
                        el('td', {}, [may]),
                        el('td', { title: cn.all || null, text: cn.text || 'None' }),
                        el('td', { title: dn.all || null, text: dn.text || 'None' }),
                        el('td', { class: 'small', text: t.qualifications || '—', title: t.qualifications || null }),
                        el('td', { class: 'tro-actions' }, [canEdit ? u.kebab('Actions for ' + p.name, [
                            { label: 'Edit…', icon: 'fas fa-pen', onClick: function () { edit(t); } },
                            '-',
                            inactive ? { label: 'Make active', icon: 'fas fa-user-check', onClick: function () { setActive(t, true); } }
                                : { label: 'Make inactive', icon: 'fas fa-user-slash', danger: true, onClick: function () { setActive(t, false); } }
                        ]) : null])
                    ]);
                    return tr;
                }
                function payload(t, over) {
                    return Object.assign({
                        contact_id: t.contact_id, version: t.version, title: t.title || null, flags: t.flags || {}, all_courses: !!t.all_courses, all_departments: !!t.all_departments,
                        course_ids: t.course_ids || [], client_ids: t.client_ids || [], qualifications: t.qualifications || null, active: t.active !== false
                    }, over || {});
                }
                function setActive(t, on) {
                    u.post('trainer_save', payload(t, { active: on })).then(function () {
                        UI.toast(on ? 'Trainer is active again.' : 'Trainer made inactive. Past sessions keep their name.');
                        refetch();
                    }, function (err) { UI.toast(u.errorText(err), { type: 'error' }); });
                }
                function checkList(items, selected, label) {
                    var sel = {};
                    (selected || []).forEach(function (id) { sel[Number(id)] = true; });
                    var box = el('div', { class: 'tro-check-list', role: 'group', 'aria-label': label });
                    var inputs = [];
                    items.forEach(function (it, i) {
                        var id = 'tro-tr-' + label.replace(/\W+/g, '') + i;
                        var cb = el('input', { type: 'checkbox', class: 'form-check-input', id: id, value: String(it.id), checked: sel[Number(it.id)] ? true : null });
                        inputs.push(cb);
                        box.appendChild(el('div', { class: 'form-check' }, [cb, el('label', { class: 'form-check-label', for: id, text: it.name }), it.meta ? el('span', { class: 'tro-count', text: it.meta }) : null]));
                    });
                    if (!items.length) { box.appendChild(el('div', { class: 'text-muted small py-2', text: 'Nothing to choose from yet.' })); }
                    return { root: box, inputs: inputs, ids: function () { return inputs.filter(function (c) { return c.checked; }).map(function (c) { return Number(c.value); }); } };
                }
                function edit(t) {
                    var isNew = !t;
                    t = t || { flags: { can_train: true, can_evaluate: false, can_setup_pins: false, can_unlock: false, can_view_team: true }, all_courses: true, all_departments: true, active: true };
                    var form = el('form', { novalidate: true, autocomplete: 'off', id: 'tro-tr-form' });
                    var picker = isNew ? new Ops.PeoplePicker({ multiple: false, placeholder: 'Who is the trainer?', onChange: sync }) : null;
                    var title = el('input', { type: 'text', class: 'form-control', maxlength: '100', value: t.title || null, placeholder: 'For example: Safety Coordinator' });
                    var flagSw = {};
                    var flagBox = el('div', { class: 'tro-flags' });
                    FLAGS.forEach(function (fl, i) {
                        if (i === 3) { flagBox.appendChild(el('div', { class: 'tro-flag-group', text: 'Kiosk (takes effect when the training kiosk is installed)' })); }
                        var sw = u.switchRow({ label: fl[1], hint: fl[2], checked: !!(t.flags || {})[fl[0]] });
                        flagSw[fl[0]] = sw.input;
                        flagBox.appendChild(sw.root);
                    });
                    var allC = u.switchRow({ label: 'All courses', hint: 'Can teach or evaluate any course.', checked: !!t.all_courses });
                    var courseItems = (D.courses || []).filter(function (c) { return c.kind !== 'document'; }).map(function (c) { return { id: c.id, name: c.name, meta: c.code || '' }; });
                    var cList = checkList(courseItems, t.course_ids, 'Courses');
                    var allD = u.switchRow({ label: 'All departments', hint: 'Can run sessions for anyone.', checked: !!t.all_departments });
                    var dList = checkList((D.departments || []).filter(function (d) { return Number(d.id) > 0; }).map(function (d) { return { id: d.id, name: d.name, meta: u.plural(Number(d.people || 0), 'person', 'people') }; }), t.client_ids, 'Departments');
                    var quals = u.textArea({ rows: 3, max: 2000, placeholder: 'For example: OSHA 30 (2025), Forklift train-the-trainer, card expires Mar 2027' });
                    quals.value = t.qualifications || '';
                    var active = u.switchRow({ label: 'Active', hint: 'Inactive trainers cannot be picked for new sessions or evaluations.', checked: t.active !== false });

                    if (picker) { form.appendChild(u.field({ label: 'Person', control: picker.root, name: 'contact_id', required: true })); }
                    else { form.appendChild(el('div', { class: 'tro-card tro-card__body mb-3' }, [u.personCell(t.person || { contact_id: t.contact_id, name: 'Person #' + t.contact_id })])); }
                    form.appendChild(u.field({ label: 'Title on certificates', control: title, name: 'title', hint: 'Printed under their signature line.' }));
                    form.appendChild(u.field({ label: 'What they may do', control: flagBox, name: 'flags' }));
                    var cf = u.field({ label: 'Courses', control: el('div', {}, [allC.root, cList.root]), name: 'course_ids' });
                    form.appendChild(cf);
                    var df = u.field({ label: 'Departments', control: el('div', {}, [allD.root, dList.root]), name: 'client_ids' });
                    form.appendChild(df);
                    form.appendChild(u.field({ label: 'Qualifications', control: quals, name: 'qualifications' }));
                    form.appendChild(active.root);
                    var save = el('button', { type: 'submit', class: 'btn btn-primary', text: isNew ? 'Add trainer' : 'Save trainer' });
                    save.setAttribute('form', 'tro-tr-form');
                    var hint = el('span', { class: 'tro-oc__note' });
                    var h = Ops.sheet({ title: isNew ? 'Add trainer' : 'Edit trainer', subtitle: isNew ? '' : (t.person ? t.person.name : ''), body: form, foot: [hint, u.cancelBtn(), save], wide: true });

                    function sync() {
                        cList.root.hidden = allC.input.checked;
                        dList.root.hidden = allD.input.checked;
                        var problems = [];
                        if (picker && picker.ids().length !== 1) { problems.push('Pick the person.'); }
                        if (!allC.input.checked && !cList.ids().length) { problems.push('Pick at least one course, or All courses.'); }
                        if (!allD.input.checked && !dList.ids().length) { problems.push('Pick at least one department, or All departments.'); }
                        if (!flagSw.can_train.checked && !flagSw.can_evaluate.checked) { problems.push('Let them teach or evaluate.'); }
                        hint.textContent = problems[0] || '';
                        save.disabled = problems.length > 0;
                    }
                    [allC.input, allD.input, flagSw.can_train, flagSw.can_evaluate].forEach(function (n) { n.addEventListener('change', sync); });
                    cList.inputs.concat(dList.inputs).forEach(function (n) { n.addEventListener('change', sync); });
                    sync();
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (save.disabled) { return; }
                        u.clearFieldErrors(form);
                        u.alertBox(form, null);
                        var flags = {};
                        FLAGS.forEach(function (fl) { flags[fl[0]] = !!flagSw[fl[0]].checked; });
                        var body = {
                            contact_id: picker ? picker.ids()[0] : t.contact_id,
                            title: title.value.trim() || null, flags: flags,
                            all_courses: allC.input.checked, all_departments: allD.input.checked,
                            course_ids: allC.input.checked ? [] : cList.ids(), client_ids: allD.input.checked ? [] : dList.ids(),
                            qualifications: quals.value.trim() || null, active: active.input.checked
                        };
                        if (!isNew) { body.version = t.version; }
                        u.busy(save, true, 'Saving…');
                        u.post('trainer_save', body).then(function () {
                            UI.toast(isNew ? 'Trainer added.' : 'Trainer saved.');
                            h.close();
                            refetch();
                        }, function (err) {
                            u.busy(save, false);
                            if (err && err.code === 'conflict') {
                                u.alertBox(form, 'Someone else changed this trainer while you were editing. Close this panel and open it again to see their changes.', 'warning');
                                return;
                            }
                            if (!u.showFieldErrors(form, err) || err.code !== 'validation') { u.alertBox(form, u.errorText(err)); }
                        });
                    });
                }
                var newBtn = $('tro-pt-new');
                if (newBtn) { newBtn.hidden = routes.trainer_save === false; newBtn.addEventListener('click', function () { edit(null); }); }
                u.skeletonRows(body, 6, 3);
                u.load(D.trainers).then(function (d) { render(listOf(d)); }, fail);
            })();
        }

        // ============================================================================
        // Odoo links (read-only)
        // ============================================================================
        if ($('tro-pl-body')) {
            (function () {
                var body = $('tro-pl-body'), empty = $('tro-pl-empty'), head = $('tro-pl-head'), legend = $('tro-pl-legend');
                var checked = D.settings && D.settings.link_checked_at;
                clear(head);
                head.appendChild(el('div', { class: 'd-flex flex-wrap align-items-center gap-3' }, [
                    el('div', { class: 'flex-grow-1' }, [
                        el('div', { class: 'fw-semibold', text: checked ? 'Last checked ' + u.relTime(checked) : 'Not checked yet' }),
                        el('div', { class: 'tro-sub', text: checked ? u.fmtDateTime(checked) : 'Links are checked by every directory sync, or with Check now in Admin › Training compliance.' })
                    ]),
                    el('div', { class: 'tro-links-count', id: 'tro-pl-count' })
                ]));
                head.appendChild(el('p', { class: 'text-muted small mb-0 mt-2', text: 'Each person on the roster can be linked to an Odoo employee. The kiosk will only let someone sign in with their Odoo PIN while their link is healthy. '
                    + (D.is_admin ? 'Fix the links below in Admin › Training compliance.' : 'An administrator fixes these in Admin › Training compliance.') }));
                legend.appendChild(el('div', { class: 'd-grid gap-2' }, ['ok', 'mismatch', 'missing', 'repointed', 'unchecked'].map(function (k) {
                    return el('div', { class: 'd-flex align-items-center gap-2 flex-wrap' }, [linkChip({ odoo_linked: true, link_state: k }), el('span', { class: 'text-muted small', text: LINK_TEXT[k] })]);
                })));

                function refetch() {
                    u.skeletonRows(body, 4, 4);
                    clear(empty);
                    return u.fetchAction('people_roster', { state: 'link_issues' }).then(render, fail);
                }
                function fail(err) { clear(body); clear(empty); empty.appendChild(u.failState(err, refetch)); }
                function render(d) {
                    var rows = ((d && (d.people || d.rows)) || []);
                    var issues = rows.filter(function (p) { return ISSUE_STATES.indexOf(p.link_state) !== -1; });
                    clear(body); clear(empty);
                    issues.forEach(function (p) {
                        body.appendChild(el('tr', { dataset: { id: p.contact_id } }, [
                            el('td', {}, [u.personCell(p, { sub: p.title || '' })]),
                            el('td', { text: p.department ? p.department.name : 'No department' }),
                            el('td', {}, [linkChip(p)]),
                            el('td', { class: 'small' }, [el('span', { text: LINK_TEXT[p.link_state] || '' }), p.link_detail ? el('span', { class: 'tro-sub', text: p.link_detail }) : null])
                        ]));
                    });
                    var count = $('tro-pl-count');
                    clear(count);
                    count.appendChild(issues.length ? u.chip(u.plural(issues.length, 'link needs', 'links need') + ' review', 'warn', 'fas fa-exclamation-triangle') : u.chip('No links need review', 'ok', 'fas fa-check'));
                    if (!issues.length) {
                        empty.appendChild(D.scope === 'none' ? noPeopleState()
                            : u.emptyState({ icon: 'fas fa-link', title: 'All Odoo links look right', text: 'Nobody in your departments has a link that needs review.' }));
                    }
                }
                u.skeletonRows(body, 4, 4);
                u.load(D.links).then(render, fail);
            })();
        }
    });
})();
