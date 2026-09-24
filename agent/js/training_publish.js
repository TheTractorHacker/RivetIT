/*
 * Publish modal (spec §5.10, level 3).
 *
 *   TrainingPublish.open({courseId, courseName, kind, previewAvailable, lessonTitle(id)?,
 *                         onOpenIssue(issue)?}) -> Promise<{published:boolean, revision?}>
 *
 * Loads publish_check?network=1 (fresh video checks, 20 s budget on the server), shows
 * readiness (errors in red link to their lesson; warnings need "I've reviewed these
 * warnings"), the diff, the change note ("First version" prefilled for the first one) and
 * retrain / re-acknowledge. A 422 re-renders the issues; a 409 busy says someone else is
 * publishing. Every string goes through textContent.
 */
(function () {
    'use strict';

    var NOTE_MIN = 5;
    var NOTE_MAX = 1000;

    function init() {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        var modalEl = document.getElementById('tr-pub');
        if (!ui || !api || !modalEl || !window.bootstrap) { return null; }
        var el = ui.el;
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        var $ = function (id) { return document.getElementById(id); };
        var titleEl = $('tr-pub-title');
        var versionEl = $('tr-pub-version');
        var checking = $('tr-pub-checking');
        var loadError = $('tr-pub-load-error');
        var form = $('tr-pub-form');
        var readyEl = $('tr-pub-ready');
        var summaryEl = $('tr-pub-summary');
        var diffEl = $('tr-pub-diff');
        var note = $('tr-pub-note');
        var noteCount = $('tr-pub-note-count');
        var noteErr = $('tr-pub-note-error');
        var retrainSection = $('tr-pub-retrain-section');
        var retrain = $('tr-pub-retrain');
        var daysRow = $('tr-pub-days-row');
        var days = $('tr-pub-days');
        var daysErr = $('tr-pub-days-error');
        var errEl = $('tr-pub-error');
        var submit = $('tr-pub-submit');
        var submitLabel = $('tr-pub-submit-label');
        var footer = $('tr-pub-footer');
        var success = $('tr-pub-success');

        var opts = {};
        var check = null;
        var ackBox = null;
        var busy = false;
        var resolveFn = null;
        var result = { published: false };
        var seq = 0;

        function icon(n, extra) { return el('i', { class: 'fas fa-' + n + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
        function nextNumber() { return (check && check.next_revision_number) || 1; }

        function issueRow(issue, cls) {
            var where = null;
            if (issue.lesson_id && typeof opts.lessonTitle === 'function') {
                var t = opts.lessonTitle(issue.lesson_id);
                if (t) { where = t + (issue.lang ? ' · ' + String(issue.lang).toUpperCase() : ''); }
            }
            var text = el('span', { class: 'tr-issue__text' }, [
                issue.lesson_id && typeof opts.onOpenIssue === 'function'
                    ? el('button', { type: 'button', class: 'tr-issue__link', text: issue.message || issue.code, on: { click: function () { openIssue(issue); } } })
                    : el('span', { text: issue.message || issue.code }),
                where ? el('span', { class: 'tr-issue__where', text: where }) : null
            ]);
            return el('li', { class: cls }, [text]);
        }

        function openIssue(issue) {
            modalEl.addEventListener('hidden.bs.modal', function once() {
                modalEl.removeEventListener('hidden.bs.modal', once);
                opts.onOpenIssue(issue);
            });
            modal.hide();
        }

        function renderReady(errors, warnings) {
            readyEl.textContent = '';
            ackBox = null;
            if (!errors.length && !warnings.length) {
                readyEl.appendChild(el('div', { class: 'tr-pub__ready' }, [icon('check-circle'), 'Ready to publish. No problems found.']));
                return;
            }
            if (errors.length) {
                readyEl.appendChild(el('p', { class: 'small mb-2', text: 'Fix ' + plural(errors.length, 'problem', 'problems') + ' before publishing:' }));
                readyEl.appendChild(el('ul', { class: 'tr-issues mb-3' }, errors.map(function (i) { return issueRow(i, 'tr-issue--error'); })));
            }
            if (warnings.length) {
                readyEl.appendChild(el('ul', { class: 'tr-issues' }, warnings.map(function (i) { return issueRow(i, 'tr-issue--warning'); })));
                ackBox = el('input', { class: 'form-check-input', type: 'checkbox', id: 'tr-pub-ack' });
                ackBox.addEventListener('change', validate);
                readyEl.appendChild(el('div', { class: 'form-check tr-pub__ack' }, [
                    ackBox, el('label', { class: 'form-check-label', for: 'tr-pub-ack', text: "I've reviewed these warnings" })
                ]));
            }
        }

        function renderDiff(diff) {
            diffEl.textContent = '';
            var items = (diff && Array.isArray(diff.items)) ? diff.items : [];
            if (diff && diff.first) {
                diffEl.appendChild(el('li', { class: 'tr-diff__item' }, [el('span', { class: 'tr-diff__kind tr-diff__kind--added', text: 'New' }), el('span', { class: 'tr-diff__label', text: 'The first published version of this course.' })]));
                return;
            }
            if (!items.length) {
                diffEl.appendChild(el('li', { class: 'tr-diff__item text-muted', text: 'Nothing has changed since the published version.' }));
                return;
            }
            items.forEach(function (it) { diffEl.appendChild(diffItem(it)); });
        }

        function renderSummary(s) {
            summaryEl.textContent = '';
            if (!s) { return; }
            var chips = [
                plural(s.lessons || 0, 'lesson', 'lessons'),
                plural(s.questions || 0, 'question', 'questions'),
                plural(s.media_count || 0, 'file', 'files')
            ];
            chips.forEach(function (t) { summaryEl.appendChild(el('span', { class: 'tr-chip', text: t })); });
            if (Array.isArray(s.languages) && s.languages.length) {
                summaryEl.appendChild(el('span', { class: 'tr-chip tr-chip--ok' }, [icon('language'), s.languages.join(' · ').toUpperCase()]));
            }
            if (Array.isArray(s.excluded_languages) && s.excluded_languages.length) {
                summaryEl.appendChild(el('span', { class: 'tr-chip tr-chip--warn', title: 'Not finished, so left out of this version' }, [icon('minus-circle'), 'Without ' + s.excluded_languages.join(', ').toUpperCase()]));
            }
        }

        function validate() {
            var n = note.value.trim().length;
            noteCount.textContent = note.value.length + ' / ' + NOTE_MAX;
            var ok = !!check && check.has_changes && !busy && (check.errors || []).length === 0;
            if ((check && (check.warnings || []).length) && !(ackBox && ackBox.checked)) { ok = false; }
            if (n < NOTE_MIN || n > NOTE_MAX) { ok = false; }
            if (retrain.checked && !retrainSection.hidden) {
                var d = parseInt(days.value, 10);
                if (!(d >= 1 && d <= 365)) { ok = false; }
            }
            submit.disabled = !ok;
        }

        function applyCheck(d) {
            check = d;
            var number = nextNumber();
            versionEl.textContent = 'Version ' + number;
            submitLabel.textContent = 'Publish Version ' + number;
            renderReady(d.errors || [], d.warnings || []);
            renderSummary(d.summary);
            renderDiff(d.diff);
            if (!d.has_changes && d.current_revision) {
                readyEl.insertBefore(el('div', { class: 'alert alert-info py-2 mb-2', text: 'Nothing has changed since Version ' + d.current_revision.number + '.' }), readyEl.firstChild);
            }
            var showRetrain = !!d.has_previous;
            retrainSection.hidden = !showRetrain;
            var isDoc = d.kind === 'document';
            $('tr-pub-retrain-heading').textContent = isDoc ? 'Re-acknowledge' : 'Retrain';
            $('tr-pub-retrain-label').textContent = isDoc
                ? 'People who signed an earlier version must read and sign this one'
                : 'People who finished an earlier version must take it again';
            if (!d.has_previous && note.value.trim() === '') { note.value = 'First version'; }
            checking.hidden = true;
            checking.classList.add('d-none');
            form.hidden = false;
            validate();
            if (modalEl.classList.contains('show')) { focusFirst(); }
        }

        function load() {
            var my = ++seq;
            check = null;
            busy = false;
            checking.hidden = false;
            checking.classList.remove('d-none');
            loadError.classList.add('d-none');
            form.hidden = true;
            success.hidden = true;
            footer.hidden = false;
            errEl.textContent = '';
            submit.disabled = true;
            var slow = setTimeout(function () {
                if (my === seq && !check) { $('tr-pub-checking-sub').textContent = 'Still checking videos. Almost there…'; }
            }, 8000);
            api.get('publish_check', { course_id: opts.courseId, network: 1 }).then(function (d) {
                clearTimeout(slow);
                if (my !== seq) { return; }
                applyCheck(d);
            }, function (err) {
                clearTimeout(slow);
                if (my !== seq) { return; }
                checking.hidden = true;
                checking.classList.add('d-none');
                loadError.textContent = '';
                loadError.appendChild(el('span', { text: (err && err.message) || 'The course could not be checked.' }));
                loadError.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger ms-2', text: 'Try again', on: { click: load } }));
                loadError.classList.remove('d-none');
            });
        }

        function publish() {
            if (busy || submit.disabled) { return; }
            errEl.textContent = '';
            noteErr.textContent = '';
            daysErr.textContent = '';
            var body = {
                course_id: opts.courseId,
                change_note: note.value.trim(),
                requires_retraining: !retrainSection.hidden && retrain.checked,
                acknowledge_warnings: !!(ackBox && ackBox.checked)
            };
            if (body.requires_retraining) { body.retrain_due_days = parseInt(days.value, 10); }
            busy = true;
            submit.disabled = true;
            submit.querySelector('.spinner-border').classList.remove('d-none');
            api.post('publish', body).then(function (r) {
                busy = false;
                submit.querySelector('.spinner-border').classList.add('d-none');
                result = { published: true, revision: r };
                showSuccess(r);
            }, function (err) {
                busy = false;
                submit.querySelector('.spinner-border').classList.add('d-none');
                var code = err && err.code;
                var f = (err && err.fields) || {};
                if (err && err.status === 422 && err.data && (Array.isArray(err.data.errors) || Array.isArray(err.data.warnings))) {
                    check.errors = err.data.errors || [];
                    check.warnings = err.data.warnings || [];
                    renderReady(check.errors, check.warnings);
                    errEl.textContent = err.message || 'Fix the problems above before publishing.';
                } else if (code === 'busy') {
                    errEl.textContent = 'Someone else is publishing this course. Try again in a moment.';
                } else if (code === 'no_changes') {
                    errEl.textContent = err.message || 'Nothing has changed since the last version.';
                    check.has_changes = false;
                } else if (f.change_note) {
                    noteErr.textContent = f.change_note;
                } else if (f.retrain_due_days) {
                    daysErr.textContent = f.retrain_due_days;
                } else {
                    errEl.textContent = (err && err.message) || 'The course could not be published.';
                }
                validate();
            });
        }

        function showSuccess(r) {
            form.hidden = true;
            footer.hidden = true;
            success.hidden = false;
            $('tr-pub-success-title').textContent = 'Version ' + r.number + ' published';
            var langs = Array.isArray(r.languages) ? r.languages.join(' · ').toUpperCase() : '';
            $('tr-pub-success-sub').textContent = (langs ? 'Published in ' + langs + '. ' : '') + 'Employees will see it once Training goes live.';
            var prev = $('tr-pub-success-preview');
            if (opts.previewAvailable) {
                prev.hidden = false;
                prev.href = '/agent/training_preview.php?course_id=' + encodeURIComponent(opts.courseId) + '&revision_id=' + encodeURIComponent(r.revision_id);
                prev.textContent = '';
                prev.appendChild(icon('eye', 'me-1'));
                prev.appendChild(document.createTextNode('Preview Version ' + r.number));
            } else {
                prev.hidden = true;
            }
            var det = $('tr-pub-success-details');
            det.textContent = '';
            det.appendChild(el('div', {}, ['Fingerprint ', el('span', { class: 'tr-mono', text: r.sha12 || String(r.sha256 || '').slice(0, 12) })]));
            if (r.published_at) { det.appendChild(el('div', { text: 'Published ' + new Date(r.published_at).toLocaleString() })); }
            $('tr-pub-success-done').focus();
        }

        note.addEventListener('input', validate);
        retrain.addEventListener('change', function () { daysRow.hidden = !retrain.checked; validate(); });
        days.addEventListener('input', validate);
        submit.addEventListener('click', publish);
        $('tr-pub-success-done').addEventListener('click', function () { modal.hide(); });
        modalEl.addEventListener('shown.bs.modal', function () { focusFirst(); });
        function focusFirst() {
            if (form.hidden || !check) { return; }
            var firstErr = readyEl.querySelector('.tr-issue--error .tr-issue__link');
            if (firstErr) { firstErr.focus({ preventScroll: true }); $('tr-pub-body').scrollTop = 0; return; }
            if (ackBox) { ackBox.focus({ preventScroll: true }); return; }
            note.focus({ preventScroll: true });
        }
        modalEl.addEventListener('hidden.bs.modal', function () {
            seq++;
            var fn = resolveFn;
            resolveFn = null;
            if (fn) { fn(result); }
        });

        return {
            open: function (o) {
                opts = o || {};
                result = { published: false };
                titleEl.textContent = 'Publish ' + (opts.courseName || 'course');
                versionEl.textContent = '';
                note.value = '';
                retrain.checked = false;
                daysRow.hidden = true;
                days.value = '30';
                noteErr.textContent = '';
                daysErr.textContent = '';
                $('tr-pub-checking-sub').textContent = 'Checking videos with YouTube and Vimeo. This takes up to 20 seconds.';
                return new Promise(function (resolve) {
                    resolveFn = resolve;
                    modal.show();
                    load();
                });
            }
        };
    }

    /** One diff item (shared with the Versions tab through TrainingPublish.diffItem). */
    function diffItem(it) {
        var el = window.TrainingUi.el;
        var KIND = { added: 'Added', removed: 'Removed', changed: 'Changed', moved: 'Moved' };
        var AREA = { course: 'Course', languages: 'Language', outline: 'Order', section: 'Section', lesson: 'Lesson', question: 'Question', bank: 'Question bank' };
        return el('li', { class: 'tr-diff__item' }, [
            el('span', { class: 'tr-diff__kind tr-diff__kind--' + (KIND[it.kind] ? it.kind : 'changed'), text: KIND[it.kind] || it.kind }),
            el('span', { class: 'tr-min0' }, [
                it.area === 'course' ? null : el('span', { class: 'tr-diff__area', text: (AREA[it.area] || it.area || '') + ' ' }),
                el('span', { class: 'tr-diff__label', text: it.label || '(untitled)' }),
                Array.isArray(it.fields) && it.fields.length ? el('span', { class: 'tr-diff__fields', text: it.fields.join(', ') }) : null
            ])
        ]);
    }

    var instance = null;
    window.TrainingPublish = {
        open: function (o) {
            if (!instance) { instance = init(); }
            if (!instance) { return Promise.resolve({ published: false }); }
            return instance.open(o);
        },
        diffItem: diffItem
    };
})();
