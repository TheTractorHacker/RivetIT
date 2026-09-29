/* Client portal UI behaviour: colour-mode toggle, stat count-up, masked-value reveal. */
(function () {
    'use strict';

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
})();
