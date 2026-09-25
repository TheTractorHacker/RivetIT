/*
 * Training › Awarded badges (P3 spec §5.8 [S], lane K6).
 * Lists awards from award_list (scoped on the server) and gives manual badges through
 * award_manual. Page data comes from #tr-page-data; every value reaches the DOM through
 * TrainingUi.el / textContent, icons are checked against ^[a-z0-9-]+$ and colors against #RRGGBB.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data') || {};
        var HEX = /^#[0-9A-Fa-f]{6}$/;
        var ICON = /^[a-z0-9-]+$/;
        var RULES = {
            course_completed: 'Finished a course',
            category_completed: 'Finished a category',
            path_completed: 'Finished a learning path',
            courses_completed_count: 'Finished enough courses',
            perfect_score: 'Perfect exam score',
            first_attempt_pass: 'Passed on the first try',
            on_time_streak: 'On time, month after month',
            manual: 'Given by hand'
        };

        var achievements = Array.isArray(data.achievements) ? data.achievements : [];
        var byId = {};
        achievements.forEach(function (a) { byId[a.id] = a; });
        var people = Array.isArray(data.people) ? data.people : [];
        var rows = [];
        var counts = {};
        var more = false;
        var filterId = data.achievement_id || null;
        var contact = data.contact_id ? { id: data.contact_id, name: null } : null;
        var query = '';

        function $(id) { return document.getElementById(id); }
        function icon(name) { return el('i', { class: 'fas fa-' + (ICON.test(name || '') ? name : 'award'), 'aria-hidden': 'true' }); }
        function medal(iconName, color, size, label) {
            var m = el('span', { class: 'tr-medal tr-medal--' + size + ' flex-shrink-0', role: 'img', 'aria-label': label }, [icon(iconName)]);
            m.style.setProperty('--tr-medal', HEX.test(color || '') ? color : '#D97706');
            return m;
        }
        function fmtDate(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        }
        function fmtFull(iso) {
            var d = new Date(iso || '');
            return isNaN(d.getTime()) ? '' : d.toLocaleString();
        }

        // ------------------------------------------------------------------ list
        function renderFilter() {
            var sel = $('tr-aw-filter');
            var total = 0;
            Object.keys(counts).forEach(function (k) { total += counts[k]; });
            while (sel.firstChild) { sel.removeChild(sel.firstChild); }
            sel.appendChild(el('option', { value: '', text: 'All badges (' + total + ')' }));
            achievements.forEach(function (a) {
                var n = counts[a.id] || 0;
                if (a.archived && n === 0) { return; }
                sel.appendChild(el('option', { value: String(a.id), text: a.name + (a.archived ? ' (archived)' : '') + ' (' + n + ')' }));
            });
            sel.value = filterId ? String(filterId) : '';
        }

        function howCell(r) {
            if (r.source === 'manual') {
                var by = r.awarded_by || {};
                var who = by.name ? (by.kind === 'contact' ? 'Trainer ' + by.name : by.name) : (by.kind === 'contact' ? 'A trainer' : 'An agent');
                return el('td', {}, [
                    el('span', { class: 'badge bg-purple-lt me-1' }, [el('i', { class: 'fas fa-hand-paper me-1', 'aria-hidden': 'true' }), 'Manual']),
                    el('div', { class: 'small text-muted mt-1', text: 'Given by ' + who + (r.kiosk_id ? ' on the kiosk' : '') })
                ]);
            }
            return el('td', {}, [
                el('span', { class: 'badge bg-teal-lt me-1' }, [el('i', { class: 'fas fa-bolt me-1', 'aria-hidden': 'true' }), 'Automatic']),
                el('div', { class: 'small text-muted mt-1', text: RULES[r.rule_type] || 'Rule' })
            ]);
        }

        function personNode(c) {
            var name = c.name + (c.archived ? ' (archived)' : '');
            var main = data.transcript
                ? el('a', { href: '/agent/training_transcript.php?contact_id=' + encodeURIComponent(c.id), text: name })
                : el('span', { class: 'fw-medium', text: name });
            var only = contact ? null : el('button', {
                type: 'button', class: 'btn btn-link p-0 align-baseline small', text: 'Only this person',
                'aria-label': 'Show only ' + c.name + '’s badges',
                on: { click: function () { setContact({ id: c.id, name: c.name }); } }
            });
            return el('td', {}, [
                el('div', {}, [main]),
                el('div', { class: 'small text-muted' }, [c.dept || 'No department', only ? ' · ' : null, only])
            ]);
        }

        function rowNode(r) {
            var a = r.achievement || {};
            var tr = el('tr', { dataset: { awardId: r.id } }, [
                el('td', {}, [el('div', { class: 'd-flex align-items-center gap-2' }, [
                    medal(a.icon, a.color, 'xs', a.name + ' badge'),
                    el('span', { class: 'fw-medium', text: a.name })
                ])]),
                personNode(r.contact || {}),
                howCell(r),
                el('td', { class: 'd-none d-lg-table-cell small text-wrap', style: { maxWidth: '22rem' }, text: r.reason || '—' }),
                el('td', { class: 'text-nowrap' }, [el('time', { datetime: r.awarded_at || '', title: fmtFull(r.awarded_at), text: fmtDate(r.awarded_at) })])
            ]);
            return tr;
        }

        function renderRows() {
            var body = $('tr-aw-rows');
            body.setAttribute('aria-busy', 'false');
            while (body.firstChild) { body.removeChild(body.firstChild); }
            var q = query.toLowerCase();
            var shown = rows.filter(function (r) {
                if (!q) { return true; }
                var c = r.contact || {};
                return (c.name || '').toLowerCase().indexOf(q) !== -1 || (c.dept || '').toLowerCase().indexOf(q) !== -1;
            });
            shown.forEach(function (r) { body.appendChild(rowNode(r)); });
            var empty = $('tr-aw-empty');
            empty.hidden = shown.length > 0;
            $('tr-aw-table').hidden = shown.length === 0;
            if (shown.length === 0) {
                var filtered = !!(q || filterId || contact);
                $('tr-aw-empty-title').textContent = filtered ? 'No badges match' : 'No badges awarded yet';
                $('tr-aw-empty-text').textContent = filtered
                    ? 'Try another badge or clear the search.'
                    : 'Badges appear here as people finish courses and pass exams on the kiosk.';
            }
            $('tr-aw-more').hidden = !more;
            $('tr-aw-summary').textContent = shown.length === 1 ? '1 badge' : shown.length + ' badges';
            var chip = $('tr-aw-person-chip');
            chip.classList.toggle('d-none', !contact);
            chip.classList.toggle('d-flex', !!contact);
            if (contact) { $('tr-aw-person-name').textContent = contact.name || ('Person #' + contact.id); }
        }

        function load() {
            if (!data.ready) { rows = []; renderFilter(); renderRows(); return Promise.resolve(); }
            var params = {};
            if (filterId) { params.achievement_id = filterId; }
            if (contact) { params.contact_id = contact.id; }
            $('tr-aw-rows').setAttribute('aria-busy', 'true');
            return api.get('award_list', params).then(function (d) {
                rows = Array.isArray(d.awards) ? d.awards : [];
                counts = d.counts || {};
                more = !!d.more;
                if (contact && !contact.name && rows[0] && rows[0].contact) { contact.name = rows[0].contact.name; }
                renderFilter();
                renderRows();
            }).catch(function (err) {
                rows = [];
                renderRows();
                if (err && err.code === 'not_found' && contact) {
                    $('tr-aw-empty-title').textContent = 'Person not found';
                    $('tr-aw-empty-text').textContent = 'This person does not exist, or they are outside the departments you can see.';
                } else {
                    ui.toast((err && err.message) || 'The badge list could not be loaded. Try again.', { type: 'error' });
                }
            });
        }

        function setContact(c) {
            contact = c;
            syncUrl();
            load();
        }
        function syncUrl() {
            try {
                var u = new URL(window.location.href);
                u.searchParams.delete('contact_id');
                u.searchParams.delete('achievement_id');
                if (contact) { u.searchParams.set('contact_id', String(contact.id)); }
                if (filterId) { u.searchParams.set('achievement_id', String(filterId)); }
                window.history.replaceState(null, '', u.pathname + u.search);
            } catch (e) { /* ignore */ }
        }

        $('tr-aw-filter').addEventListener('change', function (e) {
            filterId = e.target.value ? parseInt(e.target.value, 10) : null;
            syncUrl();
            load();
        });
        $('tr-aw-search').addEventListener('input', ui.debounce(function (e) {
            query = (e.target.value || '').trim();
            renderRows();
        }, 150));
        $('tr-aw-person-clear').addEventListener('click', function () { setContact(null); });

        // ------------------------------------------------------------------ award manually
        var panel = $('tr-aw-panel');
        var layout = $('tr-awards');
        var openBtn = $('tr-aw-open');
        var busy = false;

        function manualBadges() { return achievements.filter(function (a) { return a.can_award; }); }

        function fillBadges() {
            var sel = $('tr-aw-badge');
            while (sel.firstChild) { sel.removeChild(sel.firstChild); }
            manualBadges().forEach(function (a) { sel.appendChild(el('option', { value: String(a.id), text: a.name })); });
            if (filterId && byId[filterId] && byId[filterId].can_award) { sel.value = String(filterId); }
            syncMedal();
        }
        function syncMedal() {
            var a = byId[parseInt($('tr-aw-badge').value, 10)];
            var m = $('tr-aw-badge-medal');
            if (!a) { return; }
            m.style.setProperty('--tr-medal', HEX.test(a.color || '') ? a.color : '#D97706');
            m.setAttribute('aria-label', a.name + ' badge');
            while (m.firstChild) { m.removeChild(m.firstChild); }
            m.appendChild(icon(a.icon));
        }
        function fillPeople() {
            var sel = $('tr-aw-person');
            var q = ($('tr-aw-person-q').value || '').trim().toLowerCase();
            var keep = sel.value;
            while (sel.firstChild) { sel.removeChild(sel.firstChild); }
            var list = people.filter(function (p) {
                return !q || p.name.toLowerCase().indexOf(q) !== -1 || (p.dept || '').toLowerCase().indexOf(q) !== -1;
            });
            list.slice(0, 200).forEach(function (p) {
                sel.appendChild(el('option', { value: String(p.id), text: p.name + (p.dept ? ' — ' + p.dept : '') }));
            });
            if (keep && list.some(function (p) { return String(p.id) === keep; })) { sel.value = keep; }
            else if (list.length === 1) { sel.value = String(list[0].id); }
            $('tr-aw-person-hint').textContent = people.length === 0
                ? 'Nobody is in the departments you can see.'
                : (list.length > 200 ? 'Showing 200 of ' + list.length + '. Type more of the name.' : (list.length === 0 ? 'No one matches.' : ''));
        }
        function countReason() {
            var n = ($('tr-aw-reason').value || '').trim().length;
            $('tr-aw-reason-count').textContent = n + ' / ' + (data.reason_max || 500);
        }
        function clearErrors() {
            panel.querySelectorAll('[data-field]').forEach(function (f) { f.textContent = ''; });
            panel.querySelectorAll('.is-invalid').forEach(function (f) { f.classList.remove('is-invalid'); });
            var box = $('tr-aw-error');
            box.classList.add('d-none');
            box.textContent = '';
        }
        function fieldError(field, msg, input) {
            var f = panel.querySelector('[data-field="' + field + '"]');
            if (f) { f.textContent = msg; }
            if (input) { input.classList.add('is-invalid'); }
        }
        function openPanel() {
            clearErrors();
            fillBadges();
            if (contact && people.some(function (p) { return p.id === contact.id; })) {
                $('tr-aw-person-q').value = contact.name || '';
            }
            fillPeople();
            if (contact) { $('tr-aw-person').value = String(contact.id); }
            countReason();
            panel.hidden = false;
            layout.classList.add('has-panel');
            if (openBtn) { openBtn.setAttribute('aria-expanded', 'true'); }
            $('tr-aw-badge').focus();
        }
        function closePanel() {
            panel.hidden = true;
            layout.classList.remove('has-panel');
            if (openBtn) { openBtn.setAttribute('aria-expanded', 'false'); openBtn.focus(); }
        }
        function save() {
            if (busy) { return; }
            clearErrors();
            var badge = parseInt($('tr-aw-badge').value, 10);
            var person = parseInt($('tr-aw-person').value, 10);
            var reason = ($('tr-aw-reason').value || '').trim();
            var bad = false;
            if (!badge) { fieldError('achievement_id', 'Choose a badge.', $('tr-aw-badge')); bad = true; }
            if (!person) { fieldError('contact_id', 'Choose a person.', $('tr-aw-person')); bad = true; }
            if (reason.length < (data.reason_min || 5)) { fieldError('reason', 'Write at least ' + (data.reason_min || 5) + ' characters.', $('tr-aw-reason')); bad = true; }
            if (bad) { return; }
            busy = true;
            var btn = $('tr-aw-save');
            btn.disabled = true;
            btn.classList.add('btn-loading');
            api.post('award_manual', { achievement_id: badge, contact_id: person, reason: reason }).then(function (d) {
                var aw = d.award;
                ui.toast('Awarded “' + aw.achievement.name + '” to ' + aw.contact.name + '.');
                $('tr-aw-reason').value = '';
                countReason();
                closePanel();
                return load();
            }).catch(function (err) {
                var f = (err && err.fields) || {};
                var shown = false;
                if (f.achievement_id) { fieldError('achievement_id', f.achievement_id, $('tr-aw-badge')); shown = true; }
                if (f.contact_id) { fieldError('contact_id', f.contact_id, $('tr-aw-person')); shown = true; }
                if (f.reason) { fieldError('reason', f.reason, $('tr-aw-reason')); shown = true; }
                if (!shown) {
                    var box = $('tr-aw-error');
                    box.textContent = (err && err.message) || 'The badge was not awarded. Try again.';
                    box.classList.remove('d-none');
                }
            }).then(function () {
                busy = false;
                btn.disabled = false;
                btn.classList.remove('btn-loading');
            });
        }

        if (panel && openBtn) {
            openBtn.setAttribute('aria-controls', 'tr-aw-panel');
            openBtn.setAttribute('aria-expanded', 'false');
            openBtn.addEventListener('click', function () { if (panel.hidden) { openPanel(); } else { closePanel(); } });
            $('tr-aw-close').addEventListener('click', closePanel);
            $('tr-aw-cancel').addEventListener('click', closePanel);
            $('tr-aw-save').addEventListener('click', save);
            $('tr-aw-form').addEventListener('submit', function (e) { e.preventDefault(); save(); });
            $('tr-aw-badge').addEventListener('change', syncMedal);
            $('tr-aw-person-q').addEventListener('input', ui.debounce(fillPeople, 120));
            $('tr-aw-reason').addEventListener('input', countReason);
            panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); closePanel(); } });
        }

        renderFilter();
        load();
    });
})();
