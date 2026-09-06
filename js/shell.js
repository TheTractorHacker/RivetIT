/* =============================================================================
   js/shell.js - application shell behaviour (replaces plugins/adminlte4/js/adminlte.min.js)

   AdminLTE 4 exported six widgets; only two were ever exercised by this app:
   PushMenu (the top-navbar hamburger) and Treeview (sidebar group expand). Both
   are reimplemented here in vanilla JS, with no dependency on adminlte.min.js,
   no jQuery, and no inline handlers (CSP: script-src 'self' 'nonce-...').
   A third behaviour, which AdminLTE never had, keeps a nav taller than the
   viewport usable: see "behaviour 3" below.

   MARKUP CONTRACT - all six sidebars (agent, admin, client, client-overview,
   reports, user) emit the same Tabler shape:

       aside.navbar.navbar-vertical.navbar-expand-lg
         > .container-fluid
           > button.navbar-toggler[data-bs-toggle=collapse][data-bs-target="#sidebar-menu"]
           > .navbar-brand
           > .collapse.navbar-collapse#sidebar-menu
               > ul.navbar-nav
                   > li.nav-item.dropdown[.active]
                       > a.nav-link.dropdown-toggle[data-if-toggle=submenu][.show]
                       > div.dropdown-menu#nav-group-<name>[.show]

   and includes/top_nav.php emits the hamburger as
       button#itflowSidebarToggle[data-lte-toggle=sidebar][aria-controls=sidebar-menu]
   (the data-lte-toggle attribute is kept deliberately as the stable selector).

   This file also OWNS two classes on the aside - .has-scroll-start and
   .has-scroll-end - whose only consumer is the sidebar scroll affordance block
   in includes/header.php. Rename one and you must rename it there too. It owns
   a third, .is-opening, on a nav group's panel: see "motion" below.

   WHAT THIS FILE DOES NOT DO
   - It does not read a breakpoint out of a CSS ::before content string the way
     AL4's PushMenu did. The breakpoint lives here, in one matchMedia() query,
     matching Tabler's own navbar-expand-lg (>= 992px).
   - It does not re-wire the sidebar's own .navbar-toggler: that button carries
     data-bs-toggle="collapse" and bootstrap.bundle.min.js already owns it. This
     file drives the SAME collapse instance so the two togglers never disagree,
     and listens to the collapse events so state stays in sync either way.
   - It does not fight Bootstrap's dropdown data-api: the sidebar group toggles
     carry data-if-toggle="submenu", NOT data-bs-toggle="dropdown".

   Safe to load on pages with no sidebar (guest portal, login) - init() returns
   early and binds nothing.
   ============================================================================= */
(function () {
    'use strict';

    /* Tabler's navbar-expand-lg breakpoint. Below it the sidebar is an off-canvas
       collapse; at or above it the sidebar is a fixed column and the hamburger
       folds it to Tabler's 4rem icon rail (.navbar-folded, which also retargets
       --tblr-sidebar-width for the following .page-wrapper, so content reflows). */
    var MOBILE_QUERY = '(max-width: 991.98px)';
    var FOLD_KEY = 'itflow.sidebar.folded';
    var SCROLL_SLACK = 4;       // px of overflow too small to be worth marking
    var REVEAL_PAD = 24;        // breathing room kept around an entry scrolled into view

    function readPref(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function writePref(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* private mode / blocked site data */ }
    }

    /* --- motion ---------------------------------------------------------------
       css/itflow_motion.css owns every animation in this app, including the one
       global @media (prefers-reduced-motion: reduce) guard. This file adds only
       the two things a stylesheet cannot express:

       1. WHO opened a nav group. The group holding the current page is emitted
          .show server-side, so a rule keyed on .show alone would replay the
          submenu reveal on every single page load, next to the page entrance,
          forever. Only a click - or an unfold that restores a group the user had
          clicked open - can add .is-opening, so only a user-initiated expand
          animates. The panel's HEIGHT is still not animated by anyone: it snaps
          in one reflow and the rows slide into space already allocated, so
          reveal() below still measures a settled box.
       2. The preference itself. The CSS guard collapses durations to 1ms; read
          here it means a reduced-motion user gets no animation at all rather
          than a very short one, and this stays correct even with the stylesheet
          absent or still deploying.

       Deliberately NOT here, so a later pass does not "add the missing piece":
       an IntersectionObserver that reveals below-the-fold content on scroll. The
       motion spec gives .page-body exactly one 280ms entrance and rules out
       per-card, per-tile and staggered list reveals - the dashboard alone has 13
       tiles and 4 charts, and choreographing them puts a third of a second in
       front of the number the user came to read. No stylesheet declares a reveal
       class either, so an observer would cost one observer per long page to
       toggle a class nothing paints. */
    var reduceMotionMq = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;
    function motionAllowed() {
        return !(reduceMotionMq && reduceMotionMq.matches);
    }
    /* Restart-safe with no forced reflow: .dropdown-menu:not(.show) is
       display:none, and an element entering display:block starts its animations
       fresh, so remove-then-add in the same tick is enough to replay it. */
    function markOpening(panel, open) {
        if (!panel) { return; }
        panel.classList.remove('is-opening');
        if (open && motionAllowed()) { panel.classList.add('is-opening'); }
    }

    function init() {
        var sidebar = document.querySelector('aside.navbar-vertical');
        if (!sidebar) { return; }                                   // guest / login pages

        var menu = document.getElementById('sidebar-menu');
        var toggles = document.querySelectorAll('[data-lte-toggle="sidebar"]');
        var mq = window.matchMedia(MOBILE_QUERY);
        var foldedGroups = [];                                      // groups closed by folding, to restore on unfold

        function isMobile() { return mq.matches; }

        /* --- shared state broadcast ------------------------------------------
           body.sidebar-open is set/cleared on every state change. No first-party
           CSS consumes it today (the .sidebar-open .main-sidebar rule died with
           the AdminLTE-3-generation sidebars), but it is the documented contract
           for this toggle and is what any future off-canvas CSS should key on. */
        function sync() {
            var open = isMobile()
                ? !!(menu && menu.classList.contains('show'))
                : !sidebar.classList.contains('navbar-folded');
            document.body.classList.toggle('sidebar-open', open);
            for (var i = 0; i < toggles.length; i++) {
                toggles[i].setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            markScrollEdges();      // folding and off-canvas both change what fits
        }

        /* --- behaviour 2: nav group expand / collapse (replaces Treeview) ----- */
        function groupPanel(toggle) {
            var id = toggle.getAttribute('aria-controls') ||
                     (toggle.getAttribute('href') || '').replace(/^#/, '');
            var panel = id ? document.getElementById(id) : null;
            if (panel) { return panel; }
            var next = toggle.nextElementSibling;
            return (next && next.classList.contains('dropdown-menu')) ? next : null;
        }
        function setGroup(toggle, open) {
            var panel = groupPanel(toggle);
            var item = toggle.closest('.nav-item');
            toggle.classList.toggle('show', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (panel) {
                panel.classList.toggle('show', open);
                markOpening(panel, open);
            }
            if (item) { item.classList.toggle('active', open); }
        }
        /* --- behaviour 3: keep a long nav navigable --------------------------
           #sidebar-menu is the sidebar's own scroll container and the aside is
           position:fixed, so once the nav outgrows the viewport (admin at 1100px:
           1182px of items in a 1061px column) the overflow is cut flush at the
           bottom edge. Scrolling the WINDOW moves none of it, and overlay
           scrollbars - what current Chromium draws here - paint nothing at rest,
           so the cut carries no hint that there is more and the current page's own
           entry can sit permanently below the fold.

           Two halves, and both are needed: put the server-marked .active entry on
           screen at first paint, and mark which edge still has nav behind it so
           the gradient/brand shadow declared in includes/header.php can show it.
           Nothing here is load-bearing for navigation - with JS off the sidebar
           still renders and still scrolls, it just does neither for you. */
        function overflow() {
            return menu ? menu.scrollHeight - menu.clientHeight : 0;
        }
        function markScrollEdges() {
            var slack = overflow();
            var scrollable = !isMobile() && slack > SCROLL_SLACK;
            sidebar.classList.toggle('has-scroll-start', scrollable && menu.scrollTop > SCROLL_SLACK);
            sidebar.classList.toggle('has-scroll-end', scrollable && menu.scrollTop < slack - SCROLL_SLACK);
        }
        /* Nudge by the least that clears the overhanging edge, so whatever context
           surrounds the entry (its section heading, its siblings) is kept. An
           element taller than the column can only satisfy one edge, so the
           downward nudge is clamped at "its top reaches the pad line": a long
           group just opened shows its head, never its tail. */
        function reveal(el) {
            if (!el || !menu || overflow() <= SCROLL_SLACK) { return; }
            var er = el.getBoundingClientRect();
            if (!er.height) { return; }                             // inside a closed group
            var mr = menu.getBoundingClientRect();
            var below = er.bottom - (mr.bottom - REVEAL_PAD);
            var above = (mr.top + REVEAL_PAD) - er.top;
            if (above > 0) {
                menu.scrollTop -= above;
            } else if (below > 0) {
                menu.scrollTop += Math.min(below, -above);
            }
        }
        /* The group holding the current page is emitted already open server-side,
           so the active .dropdown-item is normally measurable. If some page ever
           marks one inside a CLOSED group, fall back to that group's own row -
           a zero-height target would just be skipped. */
        function currentEntry() {
            if (!menu) { return null; }
            var item = menu.querySelector('.dropdown-item.active');
            if (item) {
                return item.getBoundingClientRect().height ? item : item.closest('.nav-item');
            }
            return menu.querySelector('.nav-link.active');
        }

        /* Delegated, so it also covers sidebars swapped in later. Multiple groups
           may be open at once (AdminLTE forced accordion behaviour through a
           hardcoded constant while the markup asked for data-accordion="false";
           single-open was never intentional). The group containing the current
           page is emitted already open server-side and is never collapsed here. */
        sidebar.addEventListener('click', function (e) {
            var toggle = e.target.closest && e.target.closest('[data-if-toggle="submenu"]');
            if (!toggle || !sidebar.contains(toggle)) { return; }
            e.preventDefault();
            var open = !toggle.classList.contains('show');
            setGroup(toggle, open);
            /* A group opened at the bottom of a full column would otherwise expand
               entirely below the cut edge. */
            if (open && !isMobile()) { reveal(toggle.closest('.nav-item') || toggle); }
            markScrollEdges();
        });

        /* .is-opening is transient and must clear itself. One delegated listener
           rather than one addEventListener per expand, so nothing accumulates
           however many times a group is toggled; animationend bubbles, so the
           class test is what keeps an event from a descendant (or from .page-body
           further up, which never reaches here) out of it. If no stylesheet paints
           the class there is no animation and no event, and the leftover class is
           inert - the next toggle clears it either way. */
        sidebar.addEventListener('animationend', function (e) {
            var el = e.target;
            if (el && el.classList && el.classList.contains('is-opening')) {
                el.classList.remove('is-opening');
            }
        });

        /* Folding turns every open group into an absolutely positioned flyout
           (Tabler: .navbar-folded .dropdown-menu { position:absolute; inset-inline-start:100% }),
           so a server-opened group would hang over the page content permanently.
           Close them on fold, restore exactly those on unfold. */
        function closeGroupsForFold() {
            foldedGroups = [];
            var open = sidebar.querySelectorAll('[data-if-toggle="submenu"].show');
            for (var i = 0; i < open.length; i++) {
                foldedGroups.push(open[i]);
                setGroup(open[i], false);
            }
        }
        function restoreGroupsAfterUnfold() {
            for (var i = 0; i < foldedGroups.length; i++) { setGroup(foldedGroups[i], true); }
            foldedGroups = [];
        }

        /* --- behaviour 1: sidebar toggle (replaces PushMenu) ------------------ */
        function setFolded(folded) {
            if (folded === sidebar.classList.contains('navbar-folded')) { return; }
            sidebar.classList.toggle('navbar-folded', folded);
            if (folded) { closeGroupsForFold(); } else { restoreGroupsAfterUnfold(); }
            sync();
        }
        function setMenu(show) {
            if (!menu) { return; }
            if (window.bootstrap && window.bootstrap.Collapse) {
                var c = window.bootstrap.Collapse.getOrCreateInstance(menu, { toggle: false });
                if (show) { c.show(); } else { c.hide(); }
            } else {
                menu.classList.toggle('show', show);                 // .collapse:not(.show){display:none}
            }
            sync();
        }

        for (var t = 0; t < toggles.length; t++) {
            toggles[t].addEventListener('click', function (e) {
                e.preventDefault();
                if (isMobile()) {
                    setMenu(!(menu && menu.classList.contains('show')));
                } else {
                    var folded = !sidebar.classList.contains('navbar-folded');
                    setFolded(folded);
                    writePref(FOLD_KEY, folded ? '1' : '0');
                }
            });
        }

        /* Click-outside and Escape dismiss the off-canvas sidebar on mobile.
           AdminLTE had no equivalent; without it the open sidebar could only be
           closed from the hamburger it covers on narrow screens. */
        document.addEventListener('click', function (e) {
            if (!isMobile() || !menu || !menu.classList.contains('show')) { return; }
            if (sidebar.contains(e.target)) { return; }
            if (e.target.closest && e.target.closest('[data-lte-toggle="sidebar"]')) { return; }
            setMenu(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isMobile() && menu && menu.classList.contains('show')) {
                setMenu(false);
            }
        });

        /* markScrollEdges() reads scrollHeight/clientHeight - a forced layout -
           and then writes two classes that a header.php rule fades on. Scrolling
           the nav and dragging a window edge both fire dozens of times a second,
           so coalesce those two to one read/write pair per frame; rAF runs before
           the next paint, so the affordance still updates in the same frame the
           user sees. Every other caller stays synchronous, because they run once
           per user action and one of them (init) must settle before first paint. */
        var edgeFrame = 0;
        function markScrollEdgesSoon() {
            if (!window.requestAnimationFrame) { markScrollEdges(); return; }
            if (edgeFrame) { return; }
            edgeFrame = window.requestAnimationFrame(function () {
                edgeFrame = 0;
                markScrollEdges();
            });
        }

        /* Keep state honest when the SIDEBAR's own .navbar-toggler (Bootstrap's
           collapse data-api) drives the same #sidebar-menu. */
        if (menu) {
            menu.addEventListener('shown.bs.collapse', sync);
            menu.addEventListener('hidden.bs.collapse', sync);
            menu.addEventListener('scroll', markScrollEdgesSoon, { passive: true });
        }
        window.addEventListener('resize', markScrollEdgesSoon);

        /* Crossing the breakpoint: leaving mobile drops the off-canvas open state
           (at >= lg Bootstrap forces .navbar-collapse visible anyway, so this only
           prevents the sidebar reappearing already-open on the way back down). */
        var onMediaChange = function () {
            if (!isMobile() && menu && menu.classList.contains('show')) { setMenu(false); }
            sync();
        };
        if (mq.addEventListener) { mq.addEventListener('change', onMediaChange); }
        else if (mq.addListener) { mq.addListener(onMediaChange); }     // Safari < 14

        /* Restore the persisted desktop fold preference. AdminLTE advertised this
           through data-enable-remember="TRUE" but AL4 beta3 shipped no persistence
           code at all, so this is new behaviour, not a preserved one. Degrades to
           "unfolded" whenever localStorage is unavailable or throws. */
        if (readPref(FOLD_KEY) === '1') { setFolded(true); }
        sync();

        /* Last, so it reads the column the fold preference actually produced. */
        reveal(currentEntry());
        markScrollEdges();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();                                                      // defer + already-parsed
    }
})();
