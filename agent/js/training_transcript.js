/*
 * Training › Transcript (Phase 2 spec §5.1, M10). The page is server-rendered; this file:
 *   - hides / shows revoked qualification rows ("Show revoked", remembered per browser);
 *   - keeps the chosen tab in the URL hash (#history …) so a reload or a shared link lands there;
 *   - opens Lane E's "Set hire date / Rehired" form (TrainingOps, level 3) and reloads after a save.
 * No inline handlers (CSP); nothing here writes HTML.
 */
(function () {
    'use strict';

    var PREF = 'itflow.training.transcript.showRevoked';

    function readPref() {
        try { return window.localStorage.getItem(PREF); } catch (e) { return null; }
    }

    function writePref(v) {
        try { window.localStorage.setItem(PREF, v ? '1' : '0'); } catch (e) { /* storage unavailable */ }
    }

    function revokedToggle() {
        var box = document.getElementById('trr-show-revoked');
        if (!box) { return; }
        var rows = document.querySelectorAll('#trr-pane-quals tr[data-trr-revoked]');
        function apply() {
            rows.forEach(function (tr) { tr.hidden = !box.checked; });
        }
        if (readPref() === '0') { box.checked = false; }
        apply();
        box.addEventListener('change', function () { writePref(box.checked); apply(); });
    }

    function tabHash() {
        var map = { '#qualifications': 'trr-tab-quals', '#assignments': 'trr-tab-assign', '#history': 'trr-tab-history', '#achievements': 'trr-tab-ach' };
        var back = {};
        Object.keys(map).forEach(function (h) { back[map[h]] = h; });
        var start = map[window.location.hash];
        if (start && window.bootstrap && window.bootstrap.Tab) {
            var btn = document.getElementById(start);
            if (btn) { window.bootstrap.Tab.getOrCreateInstance(btn).show(); }
        }
        document.querySelectorAll('#trr-transcript [data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function () {
                var h = back[btn.id];
                if (h && window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.pathname + window.location.search + (h === '#qualifications' ? '' : h));
                }
            });
        });
    }

    function hireDate(data) {
        var btn = document.querySelector('[data-trr-hire]');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            if (!window.TrainingOps) {
                if (window.TrainingUi) { window.TrainingUi.toast('The hire-date form did not load. Reload the page and try again.', { type: 'error' }); }
                return;
            }
            window.TrainingOps.init({ today: data.today, level: data.level, user_id: data.user_id });
            window.TrainingOps.open('hire_date', { person: data.person }).then(function (result) {
                if (result) { window.location.reload(); }
            }, function (err) {
                if (window.TrainingUi) { window.TrainingUi.toast((err && err.message) || 'The hire date was not saved.', { type: 'error' }); }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var data = window.TrainingUi && window.TrainingUi.readJson ? window.TrainingUi.readJson('tr-page-data') : {};
        revokedToggle();
        tabHash();
        hireDate(data || {});
    });
})();
