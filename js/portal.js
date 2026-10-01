/* Client portal UI behaviour: colour-mode toggle, stat count-up, masked-value reveal. */
(function () {
    'use strict';

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Keep the current destination visible inside portal dropdowns as well as
    // on the top-level link. The server still marks section parents active.
    document.querySelectorAll('.client-portal-nav a.nav-link.active:not([data-bs-toggle="dropdown"])').forEach(function (link) {
        link.setAttribute('aria-current', 'page');
    });
    document.querySelectorAll('.client-portal-nav a.dropdown-item[href]').forEach(function (link) {
        if (new URL(link.href, window.location.href).pathname === window.location.pathname) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
    });

    document.querySelectorAll('[data-portal-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            document.body.classList.toggle('dark-mode', next === 'dark');
            try { window.localStorage.setItem('portal_theme', next); } catch (e) { /* blocked storage */ }
        });
    });

    if (!reduce) {
        document.querySelectorAll('.portal-count[data-count]').forEach(function (el) {
            var target = parseInt(el.getAttribute('data-count'), 10);
            if (!isFinite(target) || target < 1) { return; }
            var start = null, dur = 700;
            el.textContent = '0';
            function tick(ts) {
                if (start === null) { start = ts; }
                var p = Math.min((ts - start) / dur, 1);
                el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
                if (p < 1) { window.requestAnimationFrame(tick); }
            }
            window.requestAnimationFrame(tick);
        });
    }

    document.querySelectorAll('[data-portal-secret]').forEach(function (wrap) {
        var out = wrap.querySelector('[data-portal-secret-value]');
        var btn = wrap.querySelector('button');
        if (!out || !btn) { return; }
        var real = out.getAttribute('data-portal-secret-value');
        var shown = false;
        function paint() {
            out.textContent = shown ? real : '•'.repeat(Math.max(real.length, 4));
            btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
            btn.querySelector('i').className = shown ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
        btn.addEventListener('click', function () { shown = !shown; paint(); });
        paint();
    });

    document.querySelectorAll('[data-portal-filter]').forEach(function (bar) {
        var input = bar.querySelector('[data-portal-filter-input]');
        var chips = bar.querySelectorAll('[data-portal-filter-chip]');
        var list = document.querySelector('[data-portal-filter-list]');
        var empty = document.querySelector('[data-portal-filter-empty]');
        if (!list) { return; }
        var items = list.querySelectorAll('[data-portal-filter-item]');
        var other = list.querySelector('[data-portal-filter-other]');
        var cat = '';
        function apply() {
            var q = input ? input.value.trim().toLowerCase() : '';
            var shown = 0;
            items.forEach(function (it) {
                var ok = (cat === '' || it.getAttribute('data-cat') === cat) &&
                         (q === '' || (it.getAttribute('data-search') || '').indexOf(q) !== -1);
                it.hidden = !ok;
                if (ok) { shown++; }
            });
            if (other) { other.hidden = shown === 0 && (q !== '' || cat !== ''); }
            if (empty) { empty.classList.toggle('d-none', shown !== 0); }
        }
        if (input) { input.addEventListener('input', apply); }
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                cat = chip.getAttribute('data-portal-filter-chip') || '';
                chips.forEach(function (c) {
                    var on = c === chip;
                    c.classList.toggle('is-active', on);
                    c.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                apply();
            });
        });
    });
})();
