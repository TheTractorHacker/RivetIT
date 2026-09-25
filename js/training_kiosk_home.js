/*
 * Learning Center (kiosk/me.php, P3 spec §5.3, mockup Kiosk-LearningCenter). Renders the page data
 * with DOM nodes and textContent only. Cards link to course.php?c=<id> (or sign.php for a run that
 * is waiting for the signature).
 */
(function () {
    'use strict';
    var K = window.Kiosk;
    if (!K) { return; }
    var el = K.ui.el;
    var icon = K.ui.icon;
    var t = K.t;
    var root = document.getElementById('kl-home');
    if (!root) { return; }
    var P = K.data().page || {};
    var S = K.data().session || {};
    var lang = K.lang();

    function date(ymd, opts) {
        if (!ymd || !/^\d{4}-\d{2}-\d{2}/.test(ymd)) { return ''; }
        var p = ymd.slice(0, 10).split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        try { return d.toLocaleDateString(lang === 'es' ? 'es-MX' : 'en-US', opts || { month: 'short', day: 'numeric' }); } catch (e) { return ymd.slice(0, 10); }
    }
    function dayWord(ymd) {
        if (!ymd) { return ''; }
        var today = P.today || '';
        if (ymd.slice(0, 10) === today) { return t('home.today'); }
        var p = today.split('-');
        var y = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]) - 1);
        var ys = y.getFullYear() + '-' + ('0' + (y.getMonth() + 1)).slice(-2) + '-' + ('0' + y.getDate()).slice(-2);
        return ymd.slice(0, 10) === ys ? t('home.yesterday') : date(ymd);
    }
    function list(a) { return Array.isArray(a) ? a : []; }
    function courseUrl(c) { return '/kiosk/course.php?c=' + encodeURIComponent(String(c.course_id)); }

    // ---------------------------------------------------------------- greeting
    var required = list(P.required);
    var documents = list(P.documents);
    var counts = P.counts || {};
    var overdue = Number(counts.overdue || 0);
    var nudge = overdue === 1 ? t('home.nudge_overdue_1') : (overdue > 1 ? t('home.nudge_overdue_n', { n: overdue })
        : (required.length || documents.length ? t('home.nudge_required') : t('home.nudge_clear')));
    var todayLong = date(P.today, { weekday: 'long', month: 'long', day: 'numeric' });
    root.appendChild(el('header', { class: 'kl-greet' }, [
        el('h1', { class: 'kl-greet__title', text: t('home.greet_' + (P.greet || 'morning'), { first: S.first || '' }) }),
        el('p', { class: 'kl-greet__sub', text: (todayLong ? todayLong + ' · ' : '') + nudge })
    ]));

    // ---------------------------------------------------------------- notices
    list(P.notices).forEach(function (n) {
        var text = n.kind === 'pin_changed' ? t('home.notice_pin_changed', { date: date(n.date) })
            : (n.who ? t('home.notice_pin_reset', { date: date(n.date), who: n.who }) : t('home.notice_pin_reset_nowho', { date: date(n.date) }));
        root.appendChild(el('div', { class: 'kx-alert kx-alert--warn kl-notice', role: 'status' }, [icon('fa-key'), el('span', { text: text })]));
    });
    if (!P.records) {
        root.appendChild(el('div', { class: 'kx-alert kx-alert--info kl-notice', role: 'status' }, [icon('fa-info-circle'), el('span', { text: t('home.records_unavailable') })]));
    }

    // ---------------------------------------------------------------- stat tiles
    function stat(n, label, ic, tone) {
        return el('div', { class: 'kl-stat' + (tone ? ' kl-stat--' + tone : '') }, [
            el('span', { class: 'kl-stat__icon', 'aria-hidden': 'true' }, icon(ic)),
            el('div', { class: 'kl-stat__body' }, [el('strong', { class: 'kl-stat__num', text: String(n) }), el('span', { class: 'kl-stat__label', text: label })])
        ]);
    }
    var toSign = Number(counts.to_sign || 0);
    root.appendChild(el('section', { class: 'kl-stats', 'aria-label': t('home.title') }, [
        stat(Number(counts.completed || 0), t('home.stat_completed'), 'fa-check-circle', 'ok'),
        stat(Number(counts.in_progress || 0), t('home.stat_in_progress'), 'fa-play-circle', 'info'),
        stat(overdue, t('home.stat_overdue'), 'fa-exclamation-triangle', overdue > 0 ? 'bad' : 'muted'),
        stat(toSign, toSign === 1 ? t('home.stat_to_sign_1') : t('home.stat_to_sign_n'), 'fa-file-signature', 'muted')
    ]));

    // ---------------------------------------------------------------- course cards
    function kindLine(c) {
        if (c.kind === 'document') {
            var bits = [];
            if (c.version) { bits.push(t('home.version_n', { n: c.version })); }
            if (c.pages) { bits.push(c.pages === 1 ? t('home.pages_1') : t('home.pages_n', { n: c.pages })); }
            if (c.minutes) { bits.push(t('home.min_total', { n: c.minutes })); }
            return bits.join(' · ');
        }
        var kind = c.state === 'session' && !c.lessons ? t('home.kind_session')
            : (c.needs_practical ? t('home.kind_online_practical') : (c.needs_session ? t('home.kind_online_session') : t('home.kind_online')));
        var parts = [kind];
        if (c.lessons) { parts.push(c.lessons === 1 ? t('home.lessons_1') : t('home.lessons_n', { n: c.lessons })); }
        if (c.minutes_left && c.progress_pct > 0) { parts.push(t('home.min_left', { n: c.minutes_left })); }
        else if (c.minutes) { parts.push(t('home.min_total', { n: c.minutes })); }
        return parts.join(' · ');
    }
    function dueChip(c) {
        if (c.status === 'overdue' || c.status === 'expired' || (typeof c.days_left === 'number' && c.days_left < 0)) {
            return { tone: 'bad', icon: 'fa-exclamation-triangle', text: t('home.chip_overdue') };
        }
        if (c.kind === 'document') { return { tone: 'muted', icon: 'fa-pen', text: t('home.chip_not_signed') }; }
        if (c.status === 'due_soon' || c.status === 'expiring' || (typeof c.days_left === 'number' && c.days_left <= 14)) {
            return { tone: 'warn', icon: 'fa-clock', text: t('home.chip_due_soon') };
        }
        if (c.state === 'continue') { return { tone: 'info', icon: 'fa-play', text: t('home.chip_in_progress') }; }
        return { tone: 'muted', icon: 'fa-clipboard-check', text: c.due_on ? t('home.chip_required') : t('home.chip_in_progress') };
    }
    function dueLine(c) {
        if (!c.due_on) { return null; }
        var od = c.status === 'overdue' || c.status === 'expired' || (typeof c.days_left === 'number' && c.days_left < 0);
        var text;
        if (c.kind === 'document' && !od) { text = t('home.sign_by', { date: date(c.due_on) }); }
        else if (od) { text = t('home.overdue_since', { date: date(c.due_on) }); }
        else if (c.days_left === 0) { text = t('home.due_today'); }
        else if (c.days_left === 1) { text = t('home.due_days_1', { date: date(c.due_on) }); }
        else if (typeof c.days_left === 'number') { text = t('home.due_days_n', { date: date(c.due_on), n: c.days_left }); }
        else { text = t('home.chip_due', { date: date(c.due_on) }); }
        return el('p', { class: 'kl-due' + (od ? ' is-bad' : (c.days_left !== null && c.days_left <= 14 ? ' is-warn' : '')) }, [icon('fa-calendar-alt'), el('span', { text: text })]);
    }
    function action(c) {
        var st = c.state;
        if (st === 'locked') { return { note: t('home.state_locked'), icon: 'fa-lock', tone: 'bad' }; }
        if (st === 'blocked') { return { note: t('home.state_blocked'), icon: 'fa-exclamation-circle', tone: 'bad' }; }
        if (st === 'session') { return { note: t('home.state_session'), icon: 'fa-users', tone: 'info', href: courseUrl(c), label: t('home.btn_view') }; }
        if (st === 'evaluation') { return { note: t('home.state_evaluation'), icon: 'fa-hard-hat', tone: 'info', href: courseUrl(c), label: t('home.btn_view') }; }
        if (st === 'sign') { return { href: courseUrl(c), label: t('home.btn_sign'), icon: 'fa-pen-nib', primary: true }; }
        if (c.kind === 'document') { return { href: courseUrl(c), label: t('home.btn_read_sign'), icon: 'fa-pen', primary: false, iconFirst: true }; }
        if (st === 'continue') { return { href: courseUrl(c), label: t('home.btn_continue'), icon: 'fa-arrow-right', primary: true }; }
        return { href: courseUrl(c), label: t('home.btn_start'), icon: 'fa-arrow-right', primary: false };
    }
    // Courses without a chosen cover or colour still look different from each other: a tint picked
    // from the course id and the course's initials on the cover (Forklift and LOTO no longer match).
    var TINTS = ['#0d9488', '#2563eb', '#7c3aed', '#d97706', '#16a34a', '#0891b2', '#db2777', '#475569'];
    function monogram(name) {
        var w = String(name || '').replace(/[^A-Za-z0-9À-ɏ\s/-]/g, ' ').split(/[\s/-]+/).filter(Boolean);
        return (w.length > 1 ? w[0].charAt(0) + w[1].charAt(0) : (w[0] || '').slice(0, 2)).toUpperCase();
    }
    function cover(c) {
        var art = el('div', { class: 'kl-card__cover kl-cover--' + (c.kind === 'document' ? 'doc' : 'course') });
        var tint = c.color || (c.kind === 'document' ? null : TINTS[Math.abs(Number(c.course_id) || 0) % TINTS.length]);
        if (tint) { art.style.setProperty('--kl-tint', tint); }
        if (!c.cover_url) { art.appendChild(el('span', { class: 'kl-card__mono', 'aria-hidden': 'true', text: monogram(c.name) })); }
        art.appendChild(el('span', { class: 'kl-card__art', 'aria-hidden': 'true' }, icon(c.kind === 'document' ? 'fa-file-signature' : 'fa-hard-hat')));
        if (c.cover_url) {
            var img = el('img', { src: c.cover_url, alt: '', loading: 'lazy', decoding: 'async' });
            img.addEventListener('error', function () { if (img.parentNode) { img.parentNode.removeChild(img); } });
            art.appendChild(img);
        }
        var chip = dueChip(c);
        art.appendChild(el('span', { class: 'kl-chip kl-chip--' + chip.tone }, [icon(chip.icon), el('span', { text: chip.text })]));
        return art;
    }
    function courseCard(c, i) {
        var a = action(c);
        if (i === 0 && a.href && c.kind !== 'document') { a.primary = true; }   // the most urgent course leads
        var body = [
            el('h3', { class: 'kl-card__title', text: c.name }),
            el('p', { class: 'kl-card__meta', text: kindLine(c) })
        ];
        if (c.kind !== 'document' && c.state !== 'session') {
            var pct = Math.max(0, Math.min(100, Number(c.progress_pct || 0)));
            body.push(el('div', { class: 'kl-prog' }, [
                el('div', { class: 'kl-prog__row' }, [el('span', { text: t('home.progress') }), el('strong', { text: pct + '%' })]),
                el('div', { class: 'kl-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct), 'aria-label': t('home.progress') },
                    el('span', { class: 'kl-bar__fill', style: 'width:' + pct + '%' }))
            ]));
        }
        var due = dueLine(c);
        if (due) { body.push(due); }
        if (c.resume && c.resume.title) {
            body.push(el('p', { class: 'kl-hint' }, [icon('fa-bookmark'), el('span', { text: t('home.pick_up', { n: c.resume.n, title: c.resume.title }) })]));
        } else if (c.needs_practical && c.state !== 'evaluation') {
            body.push(el('p', { class: 'kl-hint' }, [icon('fa-hard-hat'), el('span', { text: t('home.includes_practical') })]));
        } else if (c.needs_session && c.state !== 'session') {
            body.push(el('p', { class: 'kl-hint' }, [icon('fa-users'), el('span', { text: t('home.includes_session') })]));
        } else if (c.reason && c.kind === 'document') {
            body.push(el('p', { class: 'kl-hint' }, [icon('fa-info-circle'), el('span', { text: c.reason })]));
        }
        var foot = [];
        if (a.note) {
            foot.push(el('p', { class: 'kl-state kl-state--' + a.tone, role: 'status' }, [icon(a.icon), el('span', { text: a.note })]));
        }
        if (a.href) {
            var btn = el('a', { class: 'kx-btn kl-card__btn' + (a.primary ? ' kx-btn--primary' : ''), href: a.href },
                a.iconFirst ? [icon(a.icon), el('span', { text: a.label })] : [el('span', { text: a.label }), icon(a.icon)]);
            btn.addEventListener('click', function () { K.ui.busy(btn, true); });
            foot.push(btn);
        }
        return el('article', { class: 'kl-card' + (c.state === 'locked' || c.state === 'blocked' ? ' is-stuck' : '') }, [
            cover(c), el('div', { class: 'kl-card__body' }, body), el('div', { class: 'kl-card__foot' }, foot)
        ]);
    }
    function sectionHead(title, n) {
        return el('h2', { class: 'kl-h2' }, [el('span', { text: title }), typeof n === 'number' ? el('span', { class: 'kl-count', text: String(n) }) : null]);
    }

    // Required now + Documents to sign
    var top = el('div', { class: 'kl-top' + (documents.length ? '' : ' is-single') });
    var reqCards = required.slice().sort(function (a, b) {
        var ao = a.status === 'overdue' ? 0 : 1; var bo = b.status === 'overdue' ? 0 : 1;
        if (ao !== bo) { return ao - bo; }
        return String(a.due_on || '9999').localeCompare(String(b.due_on || '9999'));
    });
    if (P.records) {
        var reqSec = el('section', { class: 'kl-sec kl-sec--required', 'aria-labelledby': 'kl-h-req' });
        var h = sectionHead(t('home.required'), reqCards.length);
        h.id = 'kl-h-req';
        reqSec.appendChild(h);
        if (reqCards.length) {
            reqSec.appendChild(el('div', { class: 'kl-cards' }, reqCards.map(courseCard)));
        } else {
            reqSec.appendChild(el('div', { class: 'kl-empty' }, [
                el('span', { class: 'kl-empty__icon', 'aria-hidden': 'true' }, icon('fa-check-circle')),
                el('div', null, [el('strong', { text: t('home.empty_required') }), el('p', { text: t('home.empty_required_sub') })])
            ]));
        }
        top.appendChild(reqSec);
        if (documents.length) {
            var docSec = el('section', { class: 'kl-sec kl-sec--docs', 'aria-labelledby': 'kl-h-docs' });
            var hd = sectionHead(t('home.documents'), documents.length);
            hd.id = 'kl-h-docs';
            docSec.appendChild(hd);
            docSec.appendChild(el('div', { class: 'kl-cards' }, documents.map(function (c) { return courseCard(c, -1); })));
            top.appendChild(docSec);
        }
        root.appendChild(top);
    }
    var inProg = list(P.in_progress);
    if (inProg.length) {
        var ip = el('section', { class: 'kl-sec' }, [sectionHead(t('home.stat_in_progress'), inProg.length), el('div', { class: 'kl-cards' }, inProg.map(function (c) { return courseCard(c, -1); }))]);
        root.appendChild(ip);
    }

    // ---------------------------------------------------------------- bottom row
    function expandable(items, first, render, moreLabel) {
        var ul = el('ul', { class: 'kl-list' });
        var shown = false;
        function paint() {
            while (ul.firstChild) { ul.removeChild(ul.firstChild); }
            (shown ? items : items.slice(0, first)).forEach(function (x) { ul.appendChild(render(x)); });
        }
        paint();
        var wrap = el('div', { class: 'kl-panel' }, [ul]);
        if (items.length > first) {
            var more = el('button', { type: 'button', class: 'kl-more' }, [el('span', { text: moreLabel }), icon('fa-chevron-right')]);
            more.addEventListener('click', function () {
                shown = !shown;
                paint();
                more.firstChild.textContent = shown ? t('home.show_less') : moreLabel;
                more.classList.toggle('is-open', shown);
            });
            wrap.appendChild(more);
        }
        return wrap;
    }
    var bottom = el('div', { class: 'kl-bottom' });
    var completed = list(P.completed);
    if (P.records) {
        var cs = el('section', { class: 'kl-sec' }, [sectionHead(t('home.my_courses'))]);
        if (completed.length) {
            cs.appendChild(expandable(completed, 3, function (c) {
                var chip = c.kind === 'document'
                    ? el('span', { class: 'kl-chip kl-chip--ok' }, [icon('fa-check'), el('span', { text: t('home.acknowledged', { date: date(c.completed_on) }) })])
                    : el('span', { class: 'kl-chip kl-chip--ok' }, [icon('fa-check'), el('span', { text: c.score_pct !== null && c.score_pct !== undefined ? t('home.passed_pct', { pct: Math.round(Number(c.score_pct)) }) : t('home.done_chip') })]);
                return el('li', { class: 'kl-item' }, [
                    el('span', { class: 'kl-item__ok', 'aria-hidden': 'true' }, icon('fa-check')),
                    el('div', { class: 'kl-item__main' }, [el('strong', { class: 'kl-item__title', text: c.name }),
                        el('div', { class: 'kl-item__sub' }, [chip, c.kind === 'document' ? null : el('span', { class: 'kl-muted', text: dayWord(c.completed_on) })])])
                ]);
            }, t('home.see_all_completed', { n: Math.max(completed.length, Number(P.completed_total || 0)) })));
        } else {
            cs.appendChild(el('div', { class: 'kl-panel kl-panel--empty' }, el('p', { class: 'kl-muted', text: t('home.empty_courses') })));
        }
        bottom.appendChild(cs);

        var certs = list(P.certificates);
        var cert = el('section', { class: 'kl-sec' }, [sectionHead(t('home.certificates'))]);
        if (certs.length) {
            cert.appendChild(expandable(certs, 2, function (c) {
                var st = c.status === 'expired' ? 'expired' : (/expir|due_soon|renew/.test(c.status || '') ? 'expiring' : 'valid');
                var chip = el('span', { class: 'kl-chip kl-chip--' + (st === 'expired' ? 'bad' : (st === 'expiring' ? 'warn' : 'ok')) },
                    [icon(st === 'expired' ? 'fa-times' : (st === 'expiring' ? 'fa-clock' : 'fa-check')), el('span', { text: t('home.cert_' + st) })]);
                var exp = c.expires_on ? (st === 'expired' ? t('home.cert_expired_on', { date: date(c.expires_on, { month: 'short', day: 'numeric', year: 'numeric' }) })
                    : t('home.cert_expires', { date: date(c.expires_on, { month: 'short', day: 'numeric', year: 'numeric' }) })) : t('home.cert_no_expiry');
                return el('li', { class: 'kl-cert kl-cert--' + st }, [
                    el('span', { class: 'kl-cert__icon', 'aria-hidden': 'true' }, icon('fa-award')),
                    el('div', { class: 'kl-item__main' }, [
                        el('strong', { class: 'kl-item__title', text: c.course_name }),
                        el('div', { class: 'kl-cert__no', text: c.cert_number || t('home.cert_pending') }),
                        el('div', { class: 'kl-item__sub' }, [chip, el('span', { class: 'kl-muted', text: exp })])
                    ])
                ]);
            }, t('home.see_all_certs', { n: certs.length })));
        } else {
            cert.appendChild(el('div', { class: 'kl-panel kl-panel--empty' }, el('p', { class: 'kl-muted', text: t('home.empty_certs') })));
        }
        bottom.appendChild(cert);
    }

    var awards = list(P.achievements);
    var prog = list(P.award_progress);
    var ach = el('section', { class: 'kl-sec' }, [sectionHead(t('home.achievements'))]);
    if (awards.length || prog.length) {
        var grid = el('div', { class: 'kl-ach' });
        function medal(a) {
            var ic = /^[a-z0-9-]+$/.test(String(a.icon || '')) ? 'fa-' + String(a.icon).replace(/^fa-/, '') : 'fa-medal';
            var m = el('span', { class: 'kl-ach__medal', 'aria-hidden': 'true' }, icon(ic));
            if (/^#[0-9a-fA-F]{6}$/.test(String(a.color || ''))) { m.style.setProperty('--kl-ach', a.color); }
            return m;
        }
        awards.slice(0, 6).forEach(function (a) {
            grid.appendChild(el('div', { class: 'kl-ach__tile' }, [medal(a), el('strong', { class: 'kl-ach__name', text: a.name }),
                el('span', { class: 'kl-muted', text: t('home.earned', { date: date(a.awarded_at) }) })]));
        });
        prog.slice(0, Math.max(0, 6 - Math.min(6, awards.length))).forEach(function (p) {
            var pct = Math.round(Math.min(1, p.have / p.need) * 100);
            grid.appendChild(el('div', { class: 'kl-ach__tile is-progress' }, [medal(p), el('strong', { class: 'kl-ach__name', text: p.name }),
                el('span', { class: 'kl-muted', text: t('home.progress_of', { have: p.have, need: p.need }) }),
                el('span', { class: 'kl-bar kl-bar--sm', 'aria-hidden': 'true' }, el('span', { class: 'kl-bar__fill', style: 'width:' + pct + '%' }))]));
        });
        ach.appendChild(el('div', { class: 'kl-panel kl-panel--pad' }, grid));
    } else {
        ach.appendChild(el('div', { class: 'kl-panel kl-panel--empty' }, [el('span', { class: 'kl-empty__icon', 'aria-hidden': 'true' }, icon('fa-medal')), el('p', { class: 'kl-muted', text: t('home.empty_ach') })]));
    }
    bottom.appendChild(ach);
    root.appendChild(bottom);
}());
