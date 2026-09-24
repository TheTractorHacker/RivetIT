/*
 * Training › Session (Phase 2 spec §5.2, S2): create, edit, finalize and cancel a classroom or
 * hands-on session, and the read-only finalized / cancelled views.
 *
 *   session_get      first data (embedded by the page) and reloads
 *   session_save     {session_id?, version?, request_uid? (create), course_id, held_on, start_time?,
 *                     duration_minutes?, client_id, location, topic, notes, trainer_contact_id? | trainer_name,
 *                     evidence_token?, attendees:[{contact_id, attendance, practical, proof, attest_reason?, notes?}]}
 *   session_finalize {session_id, version, attest:true}   (a dirty form is saved first)
 *   session_cancel   {session_id, reason}
 *
 * The request_uid for a new session is generated once on page load and re-used on retry; after
 * the first save the URL becomes ?id=<id>. A 409 conflict offers a reload. DOM nodes only.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var UI = window.TrainingUi;
        var Ops = window.TrainingOps;
        var host = document.getElementById('tro-session');
        if (!UI || !Ops || !host) { return; }
        var u = Ops.u;
        var el = u.el;
        var D = UI.readJson('tr-page-data');
        Ops.init(D);
        var level = Number(D.level || 0);
        var routes = D.routes || {};
        var $ = function (id) { return document.getElementById(id); };
        var clear = u.clear;
        var alerts = $('tro-ses-alerts');
        var headActions = $('tro-ses-head-actions');
        var requestUid = u.uid32();

        if (!D.session_id && !D.is_new) { return; }

        var ATT = [['present', 'Present'], ['partial', 'Partial'], ['absent', 'Absent']];
        var PRAC = [['pass', 'Pass'], ['fail', 'Fail'], ['not_evaluated', 'Not evaluated']];
        var PROOF = { document: 'Signed the sheet', agent_recorded: 'Recorded by office', trainer_attested: 'Trainer attests', self_pin_signature: 'Signed at the kiosk', self_pin: 'PIN at the kiosk' };

        var TRAINERS = [];   // filled at boot from the embedded trainer_list (or the endpoint)
        function trainersList() {
            return TRAINERS.filter(function (x) { return x.active !== false && (!x.flags || x.flags.can_train !== false); });
        }
        function sessionCourses() {
            var list = (D.courses || []).filter(function (c) { return c.kind !== 'document'; });
            return list.slice().sort(function (a, b) {
                var sa = a.needs && a.needs.session ? 0 : 1, sb = b.needs && b.needs.session ? 0 : 1;
                return sa - sb || String(a.name).localeCompare(String(b.name));
            });
        }
        function banner(type, iconCls, title, text, actions) {
            clear(alerts);
            if (!title) { return; }
            alerts.appendChild(el('div', { class: 'tr-banner alert alert-' + type + ' d-flex gap-2 align-items-start', role: type === 'danger' ? 'alert' : 'status' }, [
                u.icon(iconCls + ' mt-1'),
                el('div', { class: 'flex-grow-1' }, [el('div', { class: 'fw-semibold', text: title }), text ? el('div', { text: text }) : null]),
                actions ? el('div', { class: 'd-flex gap-2' }, actions) : null
            ]));
        }
        function trainerName(s) { return s && s.trainer ? (s.trainer.name || '') : ''; }
        /** Page title and last breadcrumb follow the session once it exists ("{course} · {date}"). */
        function setTitle(s) {
            if (!s || !s.id || !s.course) { return; }
            var h1 = document.querySelector('#tro-session-page .it-page-title');
            var sub = document.querySelector('#tro-session-page .it-page-subtitle');
            var crumb = document.querySelector('#tro-session-page .breadcrumb-item.active');
            if (h1) { h1.textContent = s.course.name || 'Session'; }
            if (sub) { sub.textContent = 'Session on ' + u.fmtDate(s.held_on) + (trainerName(s) ? ' · ' + trainerName(s) : ''); }
            else if (h1 && h1.parentNode) { h1.parentNode.appendChild(el('p', { class: 'it-page-subtitle', text: 'Session on ' + u.fmtDate(s.held_on) + (trainerName(s) ? ' · ' + trainerName(s) : '') })); }
            if (crumb) { crumb.textContent = 'Session'; }
            document.title = (s.course.name || 'Session') + ' · Session';
        }

        // ------------------------------------------------------------------------------------
        // Read-only view (finalized, cancelled, or level 1)
        // ------------------------------------------------------------------------------------
        function renderReadOnly(s, result) {
            setTitle(s);
            clear(host);
            clear(headActions);
            var course = s.course || {};
            var needsPractical = !!(course.needs && course.needs.practical);
            if (s.status === 'finalized') {
                headActions.appendChild(el('span', { class: 'tro-digest', title: 'The finalized session is sealed with this fingerprint. Any later change would be detected.' }, [u.icon('fas fa-lock'), 'Finalized · ' + (s.sha12 || '')]));
            } else if (s.status === 'cancelled') {
                headActions.appendChild(u.chip('Cancelled', 'outline', 'fas fa-ban'));
                banner('secondary', 'fas fa-ban', 'This session was cancelled.', s.cancel_reason ? 'Reason: ' + s.cancel_reason : 'No records were issued from it.');
            } else {
                headActions.appendChild(u.chip('Draft', 'warn', 'far fa-edit'));
            }
            if (result) {
                var issued = (result.issued || []).length, pending = (result.pending || []).length;
                banner('success', 'fas fa-check-circle', 'Session finalized.', (issued ? u.plural(issued, 'training record') + ' issued.' : 'No records were issued.') + (pending ? ' ' + u.plural(pending, 'person', 'people') + ' still need another part of the course.' : ''));
            }
            var F = [];
            function add(label, value) { F.push(el('div', {}, [el('dt', { text: label }), el('dd', { class: value ? null : 'is-empty', text: value || '—' })])); }
            add('Course', course.name + (course.code ? ' (' + course.code + ')' : ''));
            add('Held on', u.fmtDate(s.held_on) + (s.start_time ? ' at ' + String(s.start_time).slice(0, 5) : ''));
            add('Length', s.duration_minutes ? s.duration_minutes + ' min' : '');
            add('Trainer', trainerName(s));
            add('Department', s.department ? s.department.name : '');
            add('Location', s.location || '');
            add('Topic', s.topic || '');
            if (s.notes) { add('Notes', s.notes); }
            if (s.finalized_at) { add('Finalized', u.fmtDateTime(s.finalized_at)); }
            var details = el('div', { class: 'tro-card' }, [
                el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Session details' }), s.is_backfill ? u.chip('Backfill', 'info', 'fas fa-history') : null]),
                el('dl', { class: 'tro-fields' }, F)
            ]);
            var list = el('ul', { class: 'tro-att tro-att--ro' });
            var attendees = (s.attendees || []).filter(function (a) { return !a.removed_at; });
            attendees.forEach(function (a) {
                var outcome;
                if (a.completion_id) {
                    outcome = el('a', { class: 'tro-chip tro-chip--ok', href: u.recordUrl(a.completion_id) }, [u.icon('fas fa-check'), el('span', { text: 'Record issued' })]);
                } else if (a.pending) {
                    outcome = u.chip('Pending: ' + a.pending, 'warn', 'far fa-clock');
                } else if (s.status === 'finalized') {
                    outcome = u.chip(a.attendance === 'present' ? 'No record' : 'No record (' + a.attendance + ')', 'outline');
                } else {
                    outcome = null;
                }
                list.appendChild(el('li', {}, [
                    u.personCell(a.person),
                    el('div', { class: 'tro-att__ctl' }, [
                        u.chip(a.attendance === 'present' ? 'Present' : a.attendance === 'partial' ? 'Partial' : 'Absent', a.attendance === 'present' ? 'ok' : a.attendance === 'partial' ? 'warn' : 'outline'),
                        needsPractical ? u.chip('Practical: ' + (a.practical === 'pass' ? 'Pass' : a.practical === 'fail' ? 'Fail' : 'not evaluated'), a.practical === 'pass' ? 'ok' : a.practical === 'fail' ? 'err' : 'outline') : null
                    ]),
                    el('div', { class: 'tro-att__proof' }, [el('span', { class: 'small', text: PROOF[a.proof] || a.proof || '' }), a.attest_reason ? el('span', { class: 'tro-sub', text: a.attest_reason }) : null, a.notes ? el('span', { class: 'tro-sub', text: a.notes }) : null]),
                    el('div', { class: 'tro-att__outcome' }, [outcome])
                ]));
            });
            var att = el('div', { class: 'tro-card' }, [
                el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Attendance' }),
                    el('span', { class: 'tro-card__sub', text: attendees.filter(function (a) { return a.attendance === 'present'; }).length + ' present of ' + attendees.length })]),
                attendees.length ? list : el('div', { class: 'tro-card__body text-muted', text: 'No one is on this session.' })
            ]);
            var aside = el('div', {});
            aside.appendChild(evidenceCard(s, null));
            if (s.status === 'open' && level < 2) {
                aside.appendChild(el('div', { class: 'tro-card tro-card__body small text-muted', text: 'This session is still a draft. Someone with Training level 2 finishes it.' }));
            }
            host.appendChild(el('div', { class: 'tro-session' }, [el('div', {}, [details, att]), aside]));
        }
        function evidenceCard(s, scan) {
            var ev = s && s.evidence;
            var body = el('div', { class: 'tro-card__body' });
            if (ev && ev.media_id) {
                var src = '/agent/training_evidence.php?m=' + encodeURIComponent(ev.media_id);
                body.appendChild(el('div', { class: 'tro-scan is-attached' }, [
                    /^image\//.test(ev.mime || '') ? el('img', { class: 'tro-scan__thumb', src: src, alt: '' }) : el('span', { class: 'tro-scan__thumb' }, [u.icon('fas fa-file-pdf')]),
                    el('div', { class: 'tro-scan__text' }, [el('div', { class: 'tro-scan__name', text: ev.original_name || 'Sign-in sheet' }), el('div', { class: 'tro-sub', text: 'On file' })]),
                    el('a', { class: 'btn btn-sm btn-outline-secondary', href: src + '&dl=1', text: 'Download' })
                ]));
                if (scan) { body.appendChild(el('div', { class: 'form-hint mt-2', text: 'Upload another scan to replace it.' })); }
            }
            if (scan) { body.appendChild(scan.root); }
            if (!ev && !scan) { body.appendChild(el('p', { class: 'text-muted mb-0', text: 'No sign-in sheet is attached.' })); }
            return el('div', { class: 'tro-card' }, [el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Signed sheet' })]), body]);
        }

        // ------------------------------------------------------------------------------------
        // Editor (new, or an open session at level 2)
        // ------------------------------------------------------------------------------------
        function renderEditor(s) {
            s = s || null;
            setTitle(s);
            clear(host);
            clear(headActions);
            clear(alerts);
            var st = {
                id: s ? s.id : null,
                version: s ? s.version : null,
                dirty: false,
                attendees: []
            };
            (s ? (s.attendees || []) : []).forEach(function (a) {
                if (a.removed_at) { return; }
                st.attendees.push({ person: a.person, attendance: a.attendance || 'present', practical: a.practical || 'not_evaluated', proof: a.proof || 'document', attest_reason: a.attest_reason || '', notes: a.notes || '' });
            });
            headActions.appendChild(u.chip(s ? 'Draft' : 'New', 'warn', 'far fa-edit'));

            // ---- details ----------------------------------------------------------------
            var course = el('select', { class: 'form-select', required: true });
            course.appendChild(el('option', { value: '', text: 'Choose a course…' }));
            var gSes = el('optgroup', { label: 'Classroom or hands-on courses' }), gOther = el('optgroup', { label: 'Other courses' });
            sessionCourses().forEach(function (c) {
                var o = el('option', { value: String(c.id), text: c.name + (c.code ? ' (' + c.code + ')' : '') });
                if (s && s.course && Number(s.course.id) === Number(c.id)) { o.selected = true; }
                (c.needs && c.needs.session ? gSes : gOther).appendChild(o);
            });
            [gSes, gOther].forEach(function (g) { if (g.children.length) { course.appendChild(g); } });
            if (s && s.course && !u.courseById(s.course.id)) {
                course.appendChild(el('option', { value: String(s.course.id), text: s.course.name + ' (no longer published)', selected: true }));
            }
            var courseMeta = el('div', { class: 'form-hint' });
            var heldOn = u.dateInput({ value: s ? s.held_on : u.today(), max: u.today(), required: true });
            var start = el('input', { type: 'time', class: 'form-control', value: s && s.start_time ? String(s.start_time).slice(0, 5) : null });
            var duration = el('input', { type: 'number', class: 'form-control', min: '1', max: '1440', step: '1', inputmode: 'numeric', value: s && s.duration_minutes ? String(s.duration_minutes) : null, placeholder: 'e.g. 60' });
            var trainer = el('select', { class: 'form-select' });
            trainer.appendChild(el('option', { value: '', text: 'Choose the trainer…' }));
            var tList = trainersList();
            tList.forEach(function (t) {
                trainer.appendChild(el('option', { value: String(t.contact_id), text: (t.person ? t.person.name : 'Trainer #' + t.contact_id) + (t.title ? ' · ' + t.title : '') }));
            });
            trainer.appendChild(el('option', { value: 'outside', text: 'Outside instructor (type a name)…' }));
            var outside = el('input', { type: 'text', class: 'form-control mt-2', maxlength: '200', placeholder: 'Instructor name and company', hidden: true });
            if (s && s.trainer) {
                if (s.trainer.contact_id && tList.some(function (t) { return Number(t.contact_id) === Number(s.trainer.contact_id); })) {
                    trainer.value = String(s.trainer.contact_id);
                } else if (s.trainer.name) {
                    trainer.value = 'outside';
                    outside.hidden = false;
                    outside.value = s.trainer.name;
                }
            }
            var dept = el('select', { class: 'form-select' });
            dept.appendChild(el('option', { value: '0', text: 'Several departments / none' }));
            (D.departments || []).forEach(function (d) {
                var o = el('option', { value: String(d.id), text: d.name });
                if (s && s.department && Number(s.department.id) === Number(d.id)) { o.selected = true; }
                dept.appendChild(o);
            });
            var location = el('input', { type: 'text', class: 'form-control', maxlength: '200', value: s && s.location ? s.location : null, placeholder: 'e.g. Main Shop, bay 2' });
            var topic = el('input', { type: 'text', class: 'form-control', maxlength: '200', value: s && s.topic ? s.topic : null, placeholder: 'e.g. Pre-lift checks and hand signals' });
            var notes = u.textArea({ rows: 2, max: 2000, placeholder: 'Optional' });
            notes.value = s && s.notes ? s.notes : '';

            var form = el('form', { novalidate: true, autocomplete: 'off', class: 'tro-card__body' });
            var cf = u.field({ label: 'Course', control: course, name: 'course_id', required: true });
            cf.insertBefore(courseMeta, cf.querySelector('.invalid-feedback'));
            form.appendChild(cf);
            var heldField = u.field({ label: 'Held on', control: heldOn, name: 'held_on', required: true });
            var backfillHint = el('div', { class: 'form-hint' });
            heldField.insertBefore(backfillHint, heldField.querySelector('.invalid-feedback'));
            form.appendChild(el('div', { class: 'tro-field-row tro-field-row--3' }, [heldField,
                u.field({ label: 'Start time', control: start, name: 'start_time' }),
                u.field({ label: 'Length (minutes)', control: duration, name: 'duration_minutes' })]));
            var tf = u.field({ label: 'Trainer', control: trainer, name: 'trainer_contact_id', required: true, hint: tList.length ? null : 'No trainers are set up yet (People › Trainers). Type an outside instructor’s name for now.' });
            tf.insertBefore(outside, tf.querySelector('.form-hint') || tf.querySelector('.invalid-feedback'));
            form.appendChild(el('div', { class: 'tro-field-row' }, [tf, u.field({ label: 'Department', control: dept, name: 'client_id' })]));
            form.appendChild(el('div', { class: 'tro-field-row' }, [u.field({ label: 'Location', control: location, name: 'location' }), u.field({ label: 'Topic', control: topic, name: 'topic' })]));
            form.appendChild(u.field({ label: 'Notes', control: notes, name: 'notes' }));
            var detailsCard = el('div', { class: 'tro-card' }, [el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Session details' })]), form]);

            // ---- attendance -----------------------------------------------------------------
            var picker = new Ops.PeoplePicker({ placeholder: 'Add a person by name', onChange: function (people) {
                people.forEach(function (p) { addPerson(p); });
                if (people.length) { picker.clear(); }
            } });
            picker.chips.hidden = true;
            var addDept = el('select', { class: 'form-select', 'aria-label': 'Department to add' });
            (D.departments || []).forEach(function (d) { addDept.appendChild(el('option', { value: String(d.id), text: d.name + ' (' + Number(d.people || 0) + ')' })); });
            var addAll = el('button', { type: 'button', class: 'btn btn-outline-secondary' }, [u.icon('fas fa-users me-2'), 'Add everyone']);
            var attList = el('ul', { class: 'tro-att tro-att--edit' });
            var attEmpty = el('div', { class: 'tro-card__body text-muted', text: 'No one yet. Add people by name, or everyone in a department.' });
            var attCount = el('span', { class: 'tro-card__sub' });
            var attTools = el('div', { class: 'tro-att-tools' }, [
                el('div', { class: 'tro-att-tools__search' }, [picker.root]),
                el('div', { class: 'tro-att-tools__dept' }, [addDept, addAll])
            ]);
            if (!(D.departments || []).length || routes.people_roster === false) { attTools.lastChild.hidden = true; }
            var attCard = el('div', { class: 'tro-card' }, [
                el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Attendance' }), attCount]),
                attTools, attList, attEmpty
            ]);

            // ---- aside: evidence, summary, attest, buttons ------------------------------------
            var scan = routes.evidence_upload === false ? null : new Ops.ScanField({ onChange: function () { markDirty(); sync(); }, emptyText: 'Scan of the signed sign-in sheet' });
            var evCard = evidenceCard(s, scan);
            var evReq = el('div', { class: 'tro-sub mb-2' });
            evCard.querySelector('.tro-card__body').insertBefore(evReq, evCard.querySelector('.tro-card__body').firstChild);
            var summary = el('div', { class: 'tro-ses-sum' });
            var attest = el('input', { type: 'checkbox', class: 'form-check-input', id: 'tro-ses-attest' });
            var attestBox = el('div', { class: 'tro-attest' }, [attest, el('label', { class: 'form-check-label', for: 'tro-ses-attest', text: 'I witnessed this session, or transcribed it from the attached sheet.' })]);
            var why = el('div', { class: 'tro-ses-why', 'aria-live': 'polite' });
            var saveBtn = el('button', { type: 'button', class: 'btn btn-outline-secondary' }, [u.icon('far fa-save me-2'), 'Save draft']);
            var finBtn = el('button', { type: 'button', class: 'btn btn-primary' }, [u.icon('fas fa-lock me-2'), 'Finalize']);
            var cancelBtn = el('button', { type: 'button', class: 'btn btn-link text-danger px-0' }, [u.icon('fas fa-ban me-2'), 'Cancel session…']);
            var cancelHost = el('div', {});
            var finCard = el('div', { class: 'tro-card' }, [
                el('div', { class: 'tro-card__head' }, [el('h2', { class: 'tro-card__title', text: 'Finish' })]),
                el('div', { class: 'tro-card__body d-grid gap-3' }, [summary, attestBox, why,
                    el('div', { class: 'd-flex flex-wrap gap-2' }, [saveBtn, finBtn]),
                    st.id && routes.session_cancel !== false ? el('div', {}, [cancelBtn, cancelHost]) : null
                ])
            ]);
            host.appendChild(el('div', { class: 'tro-session' }, [el('div', {}, [detailsCard, attCard]), el('div', { class: 'tro-ses-aside' }, [evCard, finCard])]));

            // ---- behaviour ---------------------------------------------------------------------
            function card() { return u.courseById(course.value) || (s && s.course && Number(s.course.id) === Number(course.value) ? s.course : null); }
            function needsPractical() { var c = card(); return !!(c && c.needs && c.needs.practical); }
            function isBackfill() { return u.isYmd(heldOn.value) && heldOn.value < u.addDays(u.today(), -1); }
            function hasEvidence() { return !!((scan && scan.token()) || (s && s.evidence && s.evidence.media_id)); }
            function trainerOk() { return trainer.value === 'outside' ? outside.value.trim() !== '' : trainer.value !== ''; }
            function markDirty() { st.dirty = true; }

            function tri(options, value, label, onPick) {
                var wrap = el('div', { class: 'tro-tri', role: 'group', 'aria-label': label });
                options.forEach(function (o) {
                    var b = el('button', { type: 'button', class: o[0] === 'pass' ? 'is-pass' : o[0] === 'fail' ? 'is-fail' : null, 'aria-pressed': o[0] === value ? 'true' : 'false', text: o[1] });
                    b.addEventListener('click', function () {
                        Array.prototype.forEach.call(wrap.children, function (x) { x.setAttribute('aria-pressed', 'false'); });
                        b.setAttribute('aria-pressed', 'true');
                        onPick(o[0]);
                    });
                    wrap.appendChild(b);
                });
                return wrap;
            }
            function renderAttendees() {
                clear(attList);
                var np = needsPractical();
                st.attendees.forEach(function (a, i) {
                    var reason = el('input', { type: 'text', class: 'form-control form-control-sm', maxlength: '255', value: a.attest_reason || null, placeholder: 'Why is there no signature?', hidden: a.proof !== 'agent_recorded', 'aria-label': 'Reason for ' + a.person.name });
                    reason.addEventListener('input', function () { a.attest_reason = reason.value; markDirty(); sync(); });
                    var proof = el('select', { class: 'form-select form-select-sm', 'aria-label': 'Proof for ' + a.person.name }, [
                        el('option', { value: 'document', text: 'Signed the sheet' }), el('option', { value: 'agent_recorded', text: 'Recorded by office' })
                    ]);
                    if (a.proof !== 'document' && a.proof !== 'agent_recorded') { proof.appendChild(el('option', { value: a.proof, text: PROOF[a.proof] || a.proof })); }
                    proof.value = a.proof;
                    proof.addEventListener('change', function () { a.proof = proof.value; reason.hidden = a.proof !== 'agent_recorded'; if (!reason.hidden) { reason.focus(); } markDirty(); sync(); });
                    var remove = el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary btn-icon tro-att__remove', 'aria-label': 'Remove ' + a.person.name, title: 'Remove from this session' }, [u.icon('fas fa-times')]);
                    remove.addEventListener('click', function () { st.attendees.splice(i, 1); markDirty(); renderAttendees(); sync(); });
                    attList.appendChild(el('li', { dataset: { id: a.person.contact_id } }, [
                        u.personCell(a.person),
                        el('div', { class: 'tro-att__ctl' }, [
                            tri(ATT, a.attendance, 'Attendance for ' + a.person.name, function (v) { a.attendance = v; markDirty(); sync(); }),
                            np ? tri(PRAC, a.practical, 'Practical for ' + a.person.name, function (v) { a.practical = v; markDirty(); sync(); }) : null
                        ]),
                        el('div', { class: 'tro-att__proof' }, [proof, reason]),
                        remove
                    ]));
                });
                attEmpty.hidden = st.attendees.length > 0;
                attList.hidden = st.attendees.length === 0;
            }
            function addPerson(p, silent) {
                if (!p || !p.contact_id) { return false; }
                if (st.attendees.some(function (a) { return Number(a.person.contact_id) === Number(p.contact_id); })) { return false; }
                st.attendees.push({ person: p, attendance: 'present', practical: 'not_evaluated', proof: 'document', attest_reason: '', notes: '' });
                markDirty();
                if (!silent) { renderAttendees(); sync(); }
                return true;
            }
            addAll.addEventListener('click', function () {
                var cid = Number(addDept.value);
                var dname = addDept.options[addDept.selectedIndex] ? addDept.options[addDept.selectedIndex].text.replace(/ \(\d+\)$/, '') : '';
                u.busy(addAll, true, 'Adding…');
                var added = 0, pageNo = 1;
                function step() {
                    return u.fetchAction('people_roster', { client_id: cid, state: 'eligible', page: pageNo }).then(function (d) {
                        var rows = (d && (d.people || d.rows)) || [];
                        rows.forEach(function (p) { if (p.eligible !== false && addPerson(p, true)) { added++; } });
                        if (rows.length >= 50 && pageNo < 6 && pageNo * 50 < Number(d.total || 0)) { pageNo++; return step(); }
                        return null;
                    });
                }
                step().then(function () {
                    u.busy(addAll, false);
                    renderAttendees();
                    sync();
                    UI.toast(added ? 'Added ' + u.plural(added, 'person', 'people') + ' from ' + dname + '.' : 'Everyone in ' + dname + ' is already on the list.');
                }, function (err) {
                    u.busy(addAll, false);
                    UI.toast(u.errorText(err), { type: 'error' });
                });
            });
            function syncAddAll() {
                var name = addDept.options[addDept.selectedIndex] ? addDept.options[addDept.selectedIndex].text.replace(/ \(\d+\)$/, '') : '';
                addAll.lastChild.textContent = 'Add everyone in ' + name;
            }
            addDept.addEventListener('change', syncAddAll);
            dept.addEventListener('change', function () { if (Number(dept.value) > 0) { addDept.value = dept.value; syncAddAll(); } });
            if (s && s.department) { addDept.value = String(s.department.id); }
            syncAddAll();

            function sync() {
                var c = card();
                courseMeta.textContent = c ? u.courseSummary(c) : '';
                outside.hidden = trainer.value !== 'outside';
                var bf = isBackfill();
                backfillHint.textContent = bf ? 'More than a day ago: this is a backfill, so the signed sheet is required.' : '';
                if (scan) { scan.setRequired(bf && !hasEvidence()); }
                evReq.textContent = bf ? 'Required: the session is more than a day old.' : 'Recommended. Required when the session is entered more than a day later.';
                evReq.className = bf && !hasEvidence() ? 'small fw-semibold text-warning mb-2' : 'tro-sub mb-2';

                var n = st.attendees.length;
                var present = st.attendees.filter(function (a) { return a.attendance === 'present'; });
                var np = needsPractical();
                var online = !!(c && c.needs && c.needs.online);
                var earn = present.filter(function (a) { return !np || a.practical === 'pass'; }).length;
                attCount.textContent = n ? present.length + ' present of ' + n : '';
                clear(summary);
                var lines = [];
                if (!c) { lines.push('Choose the course first.'); }
                else if (online) { lines.push('The ' + u.plural(present.length, 'person', 'people') + ' marked Present get credit for the classroom part. Their record is issued when they finish the online part on the kiosk.'); }
                else if (np) { lines.push('Finalizing issues a training record to the ' + u.plural(earn, 'person', 'people') + ' marked Present who passed the practical. Everyone else keeps their attendance on file.'); }
                else { lines.push('Finalizing issues a training record to each of the ' + u.plural(present.length, 'person', 'people') + ' marked Present.'); }
                if (st.attendees.some(function (a) { return a.attendance === 'partial'; })) { lines.push('Partial attendance does not earn a record.'); }
                summary.appendChild(el('div', { class: 'tro-ses-sum__n' }, [el('b', { text: String(c && !online ? earn : present.length) }), el('span', { text: c && !online ? (earn === 1 ? ' record will be issued' : ' records will be issued') : ' present' })]));
                lines.forEach(function (t) { summary.appendChild(el('div', { class: 'small text-muted', text: t })); });

                var problems = [];
                if (!course.value) { problems.push('Choose the course.'); }
                if (!(u.isYmd(heldOn.value) && heldOn.value <= u.today())) { problems.push('Set the date (today or earlier).'); }
                if (!trainerOk()) { problems.push('Choose the trainer, or type an outside instructor’s name.'); }
                var saveOk = problems.length === 0;
                var missingReason = st.attendees.some(function (a) { return a.proof === 'agent_recorded' && String(a.attest_reason || '').trim().length < 5; });
                if (missingReason) { problems.push('Say why each person “Recorded by office” did not sign (at least 5 characters).'); }
                if (!present.length) { problems.push('Mark at least one person Present.'); }
                if (bf && !hasEvidence()) { problems.push('Attach the signed sheet: the session is more than a day old.'); }
                if (!attest.checked) { problems.push('Tick the box to confirm you witnessed or transcribed it.'); }
                clear(why);
                if (problems.length) {
                    why.appendChild(el('div', { class: 'small fw-semibold', text: 'Before you finalize:' }));
                    why.appendChild(el('ul', { class: 'small mb-0 ps-3' }, problems.map(function (t) { return el('li', { text: t }); })));
                }
                saveBtn.disabled = !saveOk || routes.session_save === false || (!!st.id && !st.dirty && !(scan && scan.token()));
                finBtn.disabled = problems.length > 0 || routes.session_finalize === false || routes.session_save === false;
            }
            [course, heldOn, start, duration, trainer, outside, dept, location, topic, notes].forEach(function (n) {
                n.addEventListener('input', function () { markDirty(); sync(); });
                n.addEventListener('change', function () { markDirty(); if (n === course) { renderAttendees(); } sync(); });
            });
            attest.addEventListener('change', sync);

            function payload() {
                var body = {
                    course_id: Number(course.value),
                    held_on: heldOn.value,
                    start_time: start.value ? start.value + ':00' : null,
                    duration_minutes: duration.value ? Number(duration.value) : null,
                    client_id: Number(dept.value || 0),
                    location: location.value.trim() || null,
                    topic: topic.value.trim() || null,
                    notes: notes.value.trim() || null,
                    trainer_contact_id: trainer.value && trainer.value !== 'outside' ? Number(trainer.value) : null,
                    trainer_name: trainer.value === 'outside' ? outside.value.trim() : null,
                    attendees: st.attendees.map(function (a) {
                        return { contact_id: Number(a.person.contact_id), attendance: a.attendance, practical: needsPractical() ? a.practical : 'not_evaluated', proof: a.proof,
                            attest_reason: a.proof === 'agent_recorded' ? (String(a.attest_reason || '').trim() || null) : null, notes: String(a.notes || '').trim() || null };
                    })
                };
                if (scan && scan.token()) { body.evidence_token = scan.token(); }
                if (st.id) { body.session_id = st.id; body.version = st.version; } else { body.request_uid = requestUid; }
                return body;
            }
            function conflict(err) {
                if (err && (err.code === 'conflict' || err.code === 'session_finalized' || err.code === 'session_cancelled')) {
                    banner('warning', 'fas fa-exclamation-triangle', err.code === 'conflict' ? 'Someone else changed this session.' : 'This session is no longer open.',
                        'Reload it to see the latest version. Your unsaved changes on this page will be lost.',
                        [el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Reload', on: { click: function () { window.location.href = '/agent/training_session.php?id=' + encodeURIComponent(st.id); } } })]);
                    return true;
                }
                return false;
            }
            function save() {
                u.clearFieldErrors(form);
                clear(alerts);
                return u.post('session_save', payload()).then(function (d) {
                    var ns = d && (d.session || d);
                    var created = !st.id;
                    st.id = ns && ns.id ? ns.id : st.id;
                    st.version = ns && ns.version !== undefined ? ns.version : st.version;
                    st.dirty = false;
                    if (ns && ns.evidence) { s = Object.assign({}, s || {}, { evidence: ns.evidence }); }
                    if (created && st.id) {
                        try { window.history.replaceState(null, '', '/agent/training_session.php?id=' + encodeURIComponent(st.id)); } catch (e) { /* ignore */ }
                    }
                    return ns;
                });
            }
            saveBtn.addEventListener('click', function () {
                if (saveBtn.disabled) { return; }
                var wasNew = !st.id;
                u.busy(saveBtn, true, 'Saving…');
                save().then(function (ns) {
                    u.busy(saveBtn, false);
                    UI.toast('Draft saved. Finalize it when the attendance is complete.');
                    if (wasNew && ns) { renderEditor(ns); } else { sync(); }
                }, function (err) {
                    u.busy(saveBtn, false);
                    if (conflict(err)) { return; }
                    if (!u.showFieldErrors(form, err) || err.code !== 'validation') { banner('danger', 'fas fa-exclamation-circle', 'The draft was not saved.', u.errorText(err)); }
                    sync();
                });
            });
            finBtn.addEventListener('click', function () {
                if (finBtn.disabled) { return; }
                u.busy(finBtn, true, 'Finalizing…');
                saveBtn.disabled = true;
                var first = (st.dirty || !st.id || (scan && scan.token())) ? save() : Promise.resolve(null);
                first.then(function () {
                    return u.post('session_finalize', { session_id: st.id, version: st.version, attest: true });
                }).then(function (d) {
                    d = d || {};
                    var fs = d.session || null;
                    if (fs) { renderReadOnly(fs, d); window.scrollTo({ top: 0, behavior: 'smooth' }); }
                    else { window.location.href = '/agent/training_session.php?id=' + encodeURIComponent(st.id); }
                }, function (err) {
                    u.busy(finBtn, false);
                    if (conflict(err)) { return; }
                    if (!u.showFieldErrors(form, err) || err.code !== 'validation') { banner('danger', 'fas fa-exclamation-circle', 'The session was not finalized.', u.errorText(err)); }
                    sync();
                });
            });
            cancelBtn.addEventListener('click', function () {
                if (cancelHost.firstChild) { clear(cancelHost); return; }
                var reason = u.reasonInput(5, 255, 'Why is it cancelled? For example: "Rescheduled to next week" or "Entered twice"');
                var yes = el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Cancel session', disabled: true });
                var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Keep it' });
                var msg = el('div', { class: 'small fw-semibold' });
                cancelHost.appendChild(el('div', { class: 'tr-confirm-bar tro-reason-bar alert alert-warning mt-2 mb-0', role: 'alertdialog', 'aria-label': 'Cancel this session' }, [
                    el('div', { class: 'fw-semibold', text: 'Cancel this session?' }),
                    el('div', { class: 'small', text: 'No records are issued from a cancelled session. It stays on file with the reason.' }),
                    reason.input, reason.counter, msg, el('div', { class: 'tro-reason-bar__row' }, [el('span', { class: 'me-auto' }), no, yes])
                ]));
                reason.input.focus();
                reason.input.addEventListener('input', function () { yes.disabled = !reason.ok(); });
                no.addEventListener('click', function () { clear(cancelHost); });
                yes.addEventListener('click', function () {
                    u.busy(yes, true, 'Cancelling…');
                    u.post('session_cancel', { session_id: st.id, reason: reason.input.value.trim() }).then(function (d) {
                        var cs = d && (d.session || d);
                        UI.toast('Session cancelled.');
                        renderReadOnly(Object.assign({}, cs || s || {}, { status: 'cancelled', cancel_reason: reason.input.value.trim() }), null);
                    }, function (err) {
                        u.busy(yes, false);
                        if (!conflict(err)) { msg.textContent = u.errorText(err); }
                    });
                });
            });
            window.addEventListener('beforeunload', function (e) {
                if (st.dirty && host.contains(saveBtn)) { e.preventDefault(); e.returnValue = ''; }
            });

            renderAttendees();
            sync();
            st.dirty = false;
            sync();
        }

        // ------------------------------------------------------------------------------------
        // Boot
        // ------------------------------------------------------------------------------------
        function show(s) {
            if (s.status === 'open' && level >= 2 && routes.session_save !== false) { renderEditor(s); } else { renderReadOnly(s, null); }
        }
        function boot() {
        if (D.is_new) {
            if (level < 2) {
                clear(host);
                host.appendChild(el('div', { class: 'tro-card' }, [u.emptyState({ icon: 'fas fa-lock', title: 'You cannot record sessions', text: 'Recording a session needs Training level 2 (records and assignments).' })]));
                return;
            }
            if (routes.session_save === false) {
                clear(host);
                host.appendChild(el('div', { class: 'tro-card' }, [u.emptyState({ icon: 'fas fa-tools', title: 'Not available yet', text: 'Sessions are still being installed. They appear here after the next update.' })]));
                return;
            }
            renderEditor(null);
            return;
        }
        function reload() {
            u.fetchAction('session_get', { session_id: D.session_id }).then(show, function (err) { clear(host); host.appendChild(el('div', { class: 'tro-card' }, [u.failState(err, reload)])); });
        }
        u.load(D.session).then(show, function (err) { clear(host); host.appendChild(el('div', { class: 'tro-card' }, [u.failState(err, reload)])); });
        }
        // Trainers first (the editor's trainer picker); a failure just leaves "Outside instructor".
        u.load(D.trainers).then(function (d) {
            TRAINERS = (d && (d.trainers || d.rows)) || (Array.isArray(d) ? d : []);
        }, function () { TRAINERS = []; }).then(boot);
    });
})();
