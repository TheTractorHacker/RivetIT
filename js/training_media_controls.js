/*
 * Training video options (owner ask 2026-09-27: volume, "CC titles", pick up where you left off).
 * Shared by the learner player (js/training_player.js: kiosk course page and agent Preview as
 * learner) and the kiosk YouTube/Vimeo page (js/training_kiosk_video.js). Vanilla, no HTML sinks:
 * every string reaches the DOM through textContent, caption cue text included.
 *
 *   TrainingMediaControls.canSetVolume() -> false on iPhone / iPad (iOS ignores a page's volume
 *       for <video> and for the YouTube / Vimeo players alike: the hardware buttons own it), so the
 *       hosts show only Mute / Unmute there; true elsewhere (feature-tested on a <video>).
 *   TrainingMediaControls.prefs
 *       .volume() -> {level 0..1, muted}      per device (localStorage; 80 % unmuted when unset or
 *       .setVolume(level, muted)              when storage is unavailable - private mode, blocked)
 *       .cc(owner) -> true|false|null         captions on/off as the learner chose it; null = no choice yet (the
 *       .setCc(on, owner)                     host then uses its default). owner = null: per device (preview);
 *       .clearSession()                       owner = the kiosk session's opaque id (Kiosk.prefsOwner(), never a
 *                                             name): only that session reads it back, and sign-out clears it
 *   TrainingMediaControls.volume({labels:{mute, unmute, volume, hint?}, level, muted, onChange(level, muted), controllable?})
 *       -> {el, hintEl|null, set(level, muted), step(delta), controllable}
 *       hintEl: where only Mute works (iOS), a one-line "Louder: use the iPad's volume buttons" the host places
 *   TrainingMediaControls.ccButton({labels:{cc, none}, on, onToggle(on)}) -> {el, set(on), available('yes'|'no'|'unknown')}
 *   TrainingMediaControls.langSwitch({langs:[..], current, labelOf(lang), label, onPick(lang)}) -> {el, set(lang), show(bool)}
 *   TrainingMediaControls.cueBox() -> {el, show(lines), clear()}         big white-on-black caption text
 *   TrainingMediaControls.resumeBar({labels:{resume, start_over}, onStartOver}) -> {el, show(text), hide()}
 *   TrainingMediaControls.textTracks(video, captions, {onCue(lines)}) -> {langs, setMode(on, lang), destroy()}
 *       <track kind=subtitles> per caption file (served like the video: /agent/training_media.php or
 *       /kiosk/media.php); the chosen track is 'hidden' (loaded, cues fire) and rendered by cueBox, so
 *       caption text is the same size on an iPad, in full screen and on a PC.
 *   TrainingMediaControls.fitStage(stage, card, {scroller?, bottomBar?, min?, apply(heightPx|null)})
 *       keeps a video card's own controls above the lesson's bottom bar without scrolling: when the card
 *       would run past the visible area, the picture (stage) is made shorter (the video letterboxes inside
 *       it) - never below `min` px. -> {refit(), destroy()}
 *   TrainingMediaControls.fmt(seconds) -> "3:42" / "1:02:03"
 */
(function () {
    'use strict';

    var PREFIX = 'tr-media:';
    var DEFAULT_LEVEL = 0.8;

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) { return; }
            if (k === 'class') { n.className = v; }
            else if (k === 'text') { n.textContent = String(v); }
            else if (/^on/i.test(k) || k === 'html' || k === 'innerHTML') { throw new Error('TrainingMediaControls: no inline handlers or HTML sinks'); }
            else if (v === true) { n.setAttribute(k, ''); }
            else { n.setAttribute(k, String(v)); }
        });
        (Array.isArray(children) ? children : [children]).forEach(function (c) {
            if (c === null || c === undefined || c === false) { return; }
            n.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
        });
        return n;
    }
    function icon(name) { return el('i', { class: 'fas ' + name, 'aria-hidden': 'true' }); }
    function clamp(x) { x = Number(x); return isFinite(x) ? Math.max(0, Math.min(1, x)) : DEFAULT_LEVEL; }
    function fmt(seconds) {
        var s = Math.max(0, Math.round(Number(seconds) || 0));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var r = s % 60;
        var p = function (x) { return (x < 10 ? '0' : '') + x; };
        return h > 0 ? h + ':' + p(m) + ':' + p(r) : m + ':' + p(r);
    }

    // ---------------------------------------------------------------- device facts
    function isIOS() {
        try {
            var ua = navigator.userAgent || '';
            if (/\b(iPad|iPhone|iPod)\b/.test(ua)) { return true; }
            // iPadOS 13+ reports a desktop Mac; a Mac has no touch screen.
            return /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;
        } catch (e) { return false; }
    }
    var volumeOk = null;
    function canSetVolume() {
        if (volumeOk !== null) { return volumeOk; }
        if (isIOS()) { volumeOk = false; return false; }
        try {
            var v = document.createElement('video');
            v.volume = 0.5;
            volumeOk = Math.abs(v.volume - 0.5) < 0.01;
        } catch (e) { volumeOk = false; }
        return volumeOk;
    }

    // ---------------------------------------------------------------- remembered choices
    function storage() { try { return window.localStorage || null; } catch (e) { return null; } }
    function read(k) {
        try {
            var s = storage();
            var raw = s ? s.getItem(PREFIX + k) : null;
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }
    function write(k, v) {
        try {
            var s = storage();
            if (!s) { return; }
            if (v === null) { s.removeItem(PREFIX + k); } else { s.setItem(PREFIX + k, JSON.stringify(v)); }
        } catch (e) { /* storage full or blocked: the choice lasts for this page only */ }
    }
    var prefs = {
        volume: function () {
            var v = read('volume');
            if (!v || typeof v !== 'object' || typeof v.level !== 'number' || !isFinite(v.level)) { return { level: DEFAULT_LEVEL, muted: false }; }
            return { level: clamp(v.level), muted: !!v.muted };
        },
        setVolume: function (level, muted) { write('volume', { level: Math.round(clamp(level) * 100) / 100, muted: !!muted }); },
        cc: function (owner) {
            var c = read(owner ? 'cc-session' : 'cc');
            if (!c || typeof c !== 'object' || typeof c.on !== 'boolean') { return null; }
            if (owner && c.owner !== owner) { return null; }
            return c.on;
        },
        setCc: function (on, owner) {
            if (owner) { write('cc-session', { on: !!on, owner: String(owner) }); } else { write('cc', { on: !!on }); }
        },
        clearSession: function () { write('cc-session', null); }
    };

    // ---------------------------------------------------------------- volume: mute button (+ slider)
    function volume(o) {
        var labels = o.labels || {};
        var controllable = o.controllable !== undefined ? !!o.controllable : canSetVolume();
        var level = clamp(o.level === undefined ? DEFAULT_LEVEL : o.level);
        var muted = !!o.muted;
        var btn = el('button', { type: 'button', class: 'tmc-btn tmc-mute' });
        var range = controllable ? el('input', { type: 'range', class: 'tmc-range', min: '0', max: '100', step: '5', 'aria-label': labels.volume || 'Volume' }) : null;
        var wrap = el('div', { class: 'tmc-vol' + (controllable ? '' : ' tmc-vol--muteonly'), role: 'group', 'aria-label': labels.volume || 'Volume' }, [btn, range]);
        // iOS: only Mute works from the page; say where "louder" is (a stand may hide the side buttons' labels).
        var hintEl = !controllable && labels.hint ? el('span', { class: 'tmc-vol__hint' }, [icon('fa-volume-up'), el('span', { text: labels.hint })]) : null;
        function paint() {
            var silent = muted || level < 0.01;
            while (btn.firstChild) { btn.removeChild(btn.firstChild); }
            btn.appendChild(icon(silent ? 'fa-volume-mute' : (level < 0.5 ? 'fa-volume-down' : 'fa-volume-up')));
            if (!controllable) { btn.appendChild(el('span', { class: 'tmc-btn__txt', text: muted ? (labels.unmute || 'Unmute') : (labels.mute || 'Mute') })); }
            btn.setAttribute('aria-label', muted ? (labels.unmute || 'Unmute') : (labels.mute || 'Mute'));
            btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
            btn.classList.toggle('is-on', muted);
            if (range) {
                var pct = Math.round((muted ? 0 : level) * 100);
                range.value = String(pct);
                range.setAttribute('aria-valuetext', pct + '%');
                range.style.setProperty('--tmc-fill', pct + '%');
            }
        }
        function emit() { if (typeof o.onChange === 'function') { try { o.onChange(level, muted); } catch (e) { /* host errors never break the control */ } } }
        btn.addEventListener('click', function () {
            muted = !muted;
            if (!muted && level < 0.05) { level = 0.5; }
            paint();
            emit();
        });
        if (range) {
            range.addEventListener('input', function () {
                level = clamp(Number(range.value) / 100);
                muted = level < 0.01;
                paint();
                emit();
            });
        }
        paint();
        return {
            el: wrap,
            hintEl: hintEl,
            controllable: controllable,
            set: function (l, m) { level = clamp(l); muted = !!m; paint(); },
            /** Keyboard up/down (Windows): +-10 %; unmutes. No-op where the page cannot set a volume (iOS). */
            step: function (delta) {
                if (!controllable) { return false; }
                level = clamp((muted ? 0 : level) + delta);
                muted = level < 0.01;
                paint();
                emit();
                return true;
            }
        };
    }

    // ---------------------------------------------------------------- captions (CC) button
    function ccButton(o) {
        var labels = o.labels || {};
        var on = !!o.on;
        var state = 'unknown';
        var btn = el('button', { type: 'button', class: 'tmc-btn tmc-cc', 'aria-pressed': 'false', 'aria-label': labels.cc || 'Captions' }, [
            icon('fa-closed-captioning'), el('span', { class: 'tmc-btn__txt', text: labels.cc_short || 'CC' })
        ]);
        function paint() {
            btn.setAttribute('aria-pressed', on && state !== 'no' ? 'true' : 'false');
            btn.classList.toggle('is-on', on && state !== 'no');
            btn.disabled = state === 'no';
            if (state === 'no') { btn.title = labels.none || ''; } else { btn.removeAttribute('title'); }
        }
        btn.addEventListener('click', function () {
            if (state === 'no') { return; }
            on = !on;
            paint();
            if (typeof o.onToggle === 'function') { try { o.onToggle(on); } catch (e) { /* ignore */ } }
        });
        paint();
        return {
            el: btn,
            set: function (v) { on = !!v; paint(); },
            isOn: function () { return on && state !== 'no'; },
            available: function (s) { state = s === 'yes' || s === 'no' ? s : 'unknown'; paint(); }
        };
    }

    // ---------------------------------------------------------------- caption language (EN | ES)
    function langSwitch(o) {
        var current = o.current;
        var btns = {};
        var wrap = el('div', { class: 'tmc-cclang', role: 'group', 'aria-label': o.label || '' });
        (o.langs || []).forEach(function (lg) {
            var b = el('button', { type: 'button', class: 'tmc-cclang__btn', lang: lg, text: String(lg).toUpperCase(), 'aria-label': o.labelOf ? o.labelOf(lg) : lg });
            b.addEventListener('click', function () {
                if (lg === current) { return; }
                current = lg;
                paint();
                if (typeof o.onPick === 'function') { try { o.onPick(lg); } catch (e) { /* ignore */ } }
            });
            btns[lg] = b;
            wrap.appendChild(b);
        });
        function paint() { Object.keys(btns).forEach(function (lg) { btns[lg].setAttribute('aria-pressed', lg === current ? 'true' : 'false'); }); }
        paint();
        return { el: wrap, set: function (lg) { current = lg; paint(); }, show: function (v) { wrap.hidden = !v; } };
    }

    // ---------------------------------------------------------------- caption text over the video
    function cueBox() {
        var box = el('div', { class: 'tmc-cues', 'aria-hidden': 'true', hidden: true });
        return {
            el: box,
            show: function (lines) {
                while (box.firstChild) { box.removeChild(box.firstChild); }
                var any = false;
                (lines || []).forEach(function (line) {
                    line = String(line || '').trim();
                    if (!line) { return; }
                    any = true;
                    box.appendChild(el('span', { class: 'tmc-cues__line', text: line }));
                });
                box.hidden = !any;
            },
            clear: function () { while (box.firstChild) { box.removeChild(box.firstChild); } box.hidden = true; }
        };
    }

    /** Plain lines of a cue: the parsed cue fragment's text (entities decoded, any markup dropped), split on line breaks. */
    function cueLines(cue) {
        var text = '';
        try {
            if (cue && typeof cue.getCueAsHTML === 'function') {
                var frag = cue.getCueAsHTML();
                text = frag ? String(frag.textContent || '') : '';
            } else if (cue && typeof cue.text === 'string') {
                text = cue.text.replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&');
            }
        } catch (e) { text = ''; }
        return text.split(/\r?\n/);
    }

    function textTracks(video, captions, opts) {
        opts = opts || {};
        var list = (Array.isArray(captions) ? captions : []).filter(function (c) {
            return c && typeof c.src_url === 'string' && /^\/(agent\/training_media|kiosk\/media)\.php\?m=\d+(&r=\d+)?$/.test(c.src_url)
                && typeof c.lang === 'string' && /^[a-z]{2,3}(-[A-Za-z]{2,4})?$/.test(c.lang);
        });
        var tracks = [];
        var active = null;
        function onCue(e) {
            var tt = e.target;
            if (tt !== active) { return; }
            var lines = [];
            var cues = tt.activeCues || [];
            for (var i = 0; i < cues.length; i++) { lines = lines.concat(cueLines(cues[i])); }
            if (typeof opts.onCue === 'function') { opts.onCue(lines); }
        }
        list.forEach(function (c) {
            var t = el('track', { kind: 'subtitles', srclang: c.lang, label: opts.labelOf ? opts.labelOf(c.lang) : c.lang, src: c.src_url });
            video.appendChild(t);
            tracks.push({ lang: c.lang, el: t });
        });
        function textTrackOf(entry) { return entry && entry.el ? entry.el.track : null; }
        function setMode(on, lang) {
            var pick = null;
            tracks.forEach(function (x) { if (!pick && x.lang === lang) { pick = x; } });
            if (!pick) { pick = tracks[0] || null; }
            tracks.forEach(function (x) {
                var tt = textTrackOf(x);
                if (!tt) { return; }
                var want = on && x === pick ? 'hidden' : 'disabled';
                if (tt.mode !== want) { tt.mode = want; }
                if (want === 'hidden') {
                    if (active !== tt) {
                        if (active) { active.removeEventListener('cuechange', onCue); }
                        active = tt;
                        tt.addEventListener('cuechange', onCue);
                    }
                }
            });
            if (!on || !pick) {
                if (active) { active.removeEventListener('cuechange', onCue); }
                active = null;
                if (typeof opts.onCue === 'function') { opts.onCue([]); }
            } else if (active) {
                onCue({ target: active });   // the cue showing right now, at once
            }
            return pick ? pick.lang : null;
        }
        return {
            langs: tracks.map(function (x) { return x.lang; }),
            setMode: setMode,
            destroy: function () { if (active) { active.removeEventListener('cuechange', onCue); } active = null; }
        };
    }

    // ---------------------------------------------------------------- "Resuming at 3:42 · Start over"
    function resumeBar(o) {
        var labels = o.labels || {};
        var text = el('span', { class: 'tmc-resume__text' });
        var btn = el('button', { type: 'button', class: 'tmc-resume__btn' }, [icon('fa-undo'), el('span', { text: labels.start_over || 'Start over' })]);
        var bar = el('div', { class: 'tmc-resume', role: 'status', hidden: true }, [el('span', { class: 'tmc-resume__icon', 'aria-hidden': 'true' }, icon('fa-history')), text, btn]);
        btn.addEventListener('click', function () {
            bar.hidden = true;
            if (typeof o.onStartOver === 'function') { try { o.onStartOver(); } catch (e) { /* ignore */ } }
        });
        return {
            el: bar,
            show: function (msg) { text.textContent = String(msg || ''); bar.hidden = false; },
            hide: function () { bar.hidden = true; },
            shown: function () { return !bar.hidden; }
        };
    }

    // ---------------------------------------------------------------- keep the controls above the bottom bar
    function fitStage(stage, card, o) {
        o = o || {};
        var min = o.min || 200;
        var margin = o.margin === undefined ? 12 : o.margin;
        var applied = null;
        var raf = 0;
        var off = false;
        var ro = null;
        function box() {
            var cr = card.getBoundingClientRect();
            if (o.scroller) {
                var sr = o.scroller.getBoundingClientRect();
                return { top: cr.top - sr.top + o.scroller.scrollTop, limit: o.scroller.clientHeight, card: cr.height };
            }
            var bar = o.bottomBar && o.bottomBar.isConnected ? o.bottomBar.getBoundingClientRect().height : 0;
            return { top: cr.top + (window.pageYOffset || 0), limit: window.innerHeight - bar, card: cr.height };
        }
        function measure() {
            raf = 0;
            if (off || !stage.isConnected || !card.isConnected || document.fullscreenElement || document.webkitFullscreenElement) { return; }
            var sb = stage.getBoundingClientRect();
            if (!sb.width) { return; }
            var natural = Math.round(sb.width * 9 / 16);   // the picture's own 16:9 height
            var b = box();
            var room = Math.floor(b.limit - b.top - (b.card - sb.height) - margin);
            var want = room >= natural ? null : Math.max(min, room);
            if (want === applied) { return; }
            applied = want;
            if (typeof o.apply === 'function') { try { o.apply(want); } catch (e) { /* ignore */ } }
        }
        function refit() { if (!raf && !off) { raf = window.requestAnimationFrame(measure); } }
        // Deferred to the next frame: resizing the picture inside the observer callback would loop.
        if (typeof window.ResizeObserver === 'function') {
            ro = new window.ResizeObserver(refit);
            ro.observe(card);
            if (o.scroller) { ro.observe(o.scroller); }
        }
        window.addEventListener('resize', refit);
        refit();
        return {
            refit: refit,
            destroy: function () {
                off = true;
                if (raf) { window.cancelAnimationFrame(raf); }
                if (ro) { ro.disconnect(); }
                window.removeEventListener('resize', refit);
            }
        };
    }

    window.TrainingMediaControls = {
        canSetVolume: canSetVolume,
        isIOS: isIOS,
        prefs: prefs,
        volume: volume,
        ccButton: ccButton,
        langSwitch: langSwitch,
        cueBox: cueBox,
        textTracks: textTracks,
        resumeBar: resumeBar,
        fitStage: fitStage,
        fmt: fmt
    };
})();
