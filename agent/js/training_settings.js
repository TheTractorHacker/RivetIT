/*
 * Training settings (agent/training_settings.php): the section nav and the unsaved-changes guard.
 * The same two scripts as the admin one-page settings (admin/settings_training.php, inline there),
 * so both pages behave alike. Everything is kept in memory; nothing is stored in the browser.
 */
// Section nav: marks the section in view (aria-current) and keeps its pill visible when the nav
// scrolls sideways on a phone. The links themselves are plain #anchors. Nothing is stored.
(function () {
    var nav = document.getElementById('tsNav');
    if (!nav) { return; }
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    var sections = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
    var current = null;
    function mark() {
        // The line a section must cross to count as "in view": a little below the sticky nav, past
        // the scroll-margin that anchor jumps leave above a section heading.
        var offset = nav.getBoundingClientRect().bottom + Math.min(140, window.innerHeight * 0.25);
        var idx = 0;
        for (var i = 0; i < sections.length; i++) {
            if (sections[i] && sections[i].getBoundingClientRect().top <= offset) { idx = i; }
        }
        // At the very bottom the last section may be too short to reach the line: count it as in view.
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) { idx = sections.length - 1; }
        if (current === idx) { return; }
        current = idx;
        links.forEach(function (a, i) {
            if (i === idx) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); }
        });
        var a = links[idx];
        if (nav.scrollWidth > nav.clientWidth) {
            var left = a.offsetLeft - nav.offsetLeft;
            if (left < nav.scrollLeft || left + a.offsetWidth > nav.scrollLeft + nav.clientWidth) {
                nav.scrollLeft = Math.max(0, left - 16);
            }
        }
    }
    var queued = false;
    function onScroll() {
        if (queued) { return; }
        queued = true;
        window.requestAnimationFrame(function () { queued = false; mark(); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    window.addEventListener('hashchange', onScroll);
    mark();
})();

// Unsaved changes. Every form on this page saves on its own, so a Save in one section would drop
// edits made in another without a word. A form counts as changed while its fields differ from how
// the page loaded them (typing a value back undoes it). Submitting one form while another has
// changes asks first; leaving the page with changes asks too; the section nav marks sections with
// changes. Everything is kept in memory; nothing is stored in the browser.
(function () {
    var page = document.querySelector('.ts-page');
    if (!page) { return; }
    var nav = document.getElementById('tsNav');

    // The fields a person can change: hidden inputs (CSRF, section markers) and buttons never count.
    function state(form) {
        var out = [];
        Array.prototype.forEach.call(form.elements, function (el) {
            var t = (el.type || '').toLowerCase();
            if (!el.name || el.disabled || t === 'hidden' || t === 'submit' || t === 'button' || t === 'reset' || t === 'file') { return; }
            out.push(el.name + '=' + ((t === 'checkbox' || t === 'radio') ? (el.checked ? 'on:' + el.value : 'off') : el.value));
        });
        return out.length ? out.join('\n') : null;
    }
    function label(form) {
        var named = form.getAttribute('data-ts-label');
        if (named) { return named; }
        var h = form.closest('section') && form.closest('section').querySelector('h2');
        return h ? h.textContent.trim() : 'another part of this page';
    }

    var initial = new Map();
    Array.prototype.forEach.call(page.querySelectorAll('form'), function (f) {
        var s = state(f);
        if (s !== null) { initial.set(f, s); }
    });
    var dirty = new Set();

    function paint() {
        if (!nav) { return; }
        Array.prototype.forEach.call(nav.querySelectorAll('a[href^="#"]'), function (a) {
            var section = document.getElementById(a.getAttribute('href').slice(1));
            var has = false;
            dirty.forEach(function (f) { if (section && section.contains(f)) { has = true; } });
            var note = a.querySelector('.ts-dirty-note');
            a.classList.toggle('ts-dirty', has);
            if (has && !note) {
                note = document.createElement('span');
                note.className = 'visually-hidden ts-dirty-note';
                note.textContent = ' (unsaved changes)';
                a.appendChild(note);
            } else if (!has && note) {
                note.remove();
            }
        });
    }
    function refresh(e) {
        var form = e.target && e.target.form;   // .form follows a form="" attribute too
        if (!form || !initial.has(form)) { return; }
        if (state(form) === initial.get(form)) { dirty.delete(form); } else { dirty.add(form); }
        paint();
    }
    document.addEventListener('input', refresh);
    document.addEventListener('change', refresh);

    var leaving = false;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !page.contains(form)) { return; }   // other forms (e.g. search) leave the page: beforeunload asks
        var others = [];
        dirty.forEach(function (f) {
            var l = label(f);
            if (f !== form && others.indexOf(l) === -1) { others.push(l); }
        });
        if (others.length) {
            var names = others.length === 1 ? others[0] : others.slice(0, -1).join(', ') + ' and ' + others[others.length - 1];
            if (!window.confirm('Unsaved changes in ' + names + ' will be lost.\n\nPress OK to continue anyway, or Cancel to go back and save them first.')) {
                e.preventDefault();
                return;
            }
        }
        leaving = true;   // this submit may drop the changes it asked about: no second prompt on unload
        window.setTimeout(function () { if (e.defaultPrevented) { leaving = false; } }, 0);
    });
    window.addEventListener('beforeunload', function (e) {
        if (leaving || dirty.size === 0) { return undefined; }
        e.preventDefault();
        e.returnValue = '';
        return '';
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { leaving = false; } });
})();
