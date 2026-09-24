/*
 * Training external video player (YouTube / Vimeo) - spec §5.4 frame page, §5.9 player, plan A2.
 * Vanilla, no dependencies: used by the in-window check frame (agent/training_video_frame.php),
 * the preview player (js/training_player.js) and, from Phase 3, the kiosk video page.
 *
 *   TrainingVideoEmbed.mount(container, {
 *       provider: 'youtube'|'vimeo', embedUrl, title?, nonce?,
 *       onReady(ctrl), onPlaying(durationS), onError(code, message), onTime({current, duration}),
 *       onState('playing'|'paused'|'ended'|'buffering')
 *   }) -> ctrl {play(), pause(), toggle(), seekBy(s), seekTo(s), getCurrentTime(), getDuration(),
 *               isPlaying(), destroy(), iframe}
 *
 * Rules (plan A2):
 *   - The iframe URL is built server-side (VideoLink::embedUrl); this file only accepts the two
 *     player origins and never builds a URL from free text.
 *   - The iframe is created here and handed to new YT.Player(iframe) / new Vimeo.Player(iframe),
 *     so the player makes no oEmbed request and connect-src stays 'self'.
 *   - referrerpolicy="strict-origin-when-cross-origin" on the iframe (YouTube error 153 otherwise).
 *   - The first play must be a tap inside the player (iOS); nothing is ever laid over the player.
 *   - onPlaying fires once, on the first PLAYING, with the player's own duration (whole seconds).
 *   - Playback speed is pinned to 1x: a speed change is reset and the video paused.
 *   - The API <script> carries the page's CSP nonce (window.CSP_NONCE, opts.nonce, or the nonce of
 *     an existing script); the CSP also allowlists the exact API paths.
 */
(function () {
    'use strict';

    var YT_API = 'https://www.youtube.com/iframe_api';
    var VIMEO_API = 'https://player.vimeo.com/api/player.js';
    var ORIGINS = {
        youtube: 'https://www.youtube-nocookie.com/embed/',
        vimeo: 'https://player.vimeo.com/video/'
    };
    var API_TIMEOUT_MS = 15000;
    var apiPromises = {};

    function pageNonce(opts) {
        if (opts && typeof opts.nonce === 'string' && opts.nonce) { return opts.nonce; }
        if (typeof window.CSP_NONCE === 'string' && window.CSP_NONCE) { return window.CSP_NONCE; }
        var s = document.querySelector('script[nonce]');
        return s ? (s.nonce || s.getAttribute('nonce') || '') : '';
    }

    function loadScript(src, nonce) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;
            if (nonce) { s.nonce = nonce; }
            s.addEventListener('load', function () { resolve(); });
            s.addEventListener('error', function () { reject(new Error('api_load_failed')); });
            document.head.appendChild(s);
        });
    }

    function withTimeout(p, ms) {
        return new Promise(function (resolve, reject) {
            var t = setTimeout(function () { reject(new Error('api_load_failed')); }, ms);
            p.then(function (v) { clearTimeout(t); resolve(v); }, function (e) { clearTimeout(t); reject(e); });
        });
    }

    function loadYouTube(nonce) {
        if (apiPromises.youtube) { return apiPromises.youtube; }
        apiPromises.youtube = withTimeout(new Promise(function (resolve, reject) {
            if (window.YT && window.YT.Player) { resolve(window.YT); return; }
            var prev = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                if (typeof prev === 'function') { try { prev(); } catch (e) { /* ignore */ } }
                resolve(window.YT);
            };
            loadScript(YT_API, nonce).catch(reject);
        }), API_TIMEOUT_MS);
        apiPromises.youtube.catch(function () { apiPromises.youtube = null; });
        return apiPromises.youtube;
    }

    function loadVimeo(nonce) {
        if (apiPromises.vimeo) { return apiPromises.vimeo; }
        apiPromises.vimeo = withTimeout(new Promise(function (resolve, reject) {
            if (window.Vimeo && window.Vimeo.Player) { resolve(window.Vimeo); return; }
            loadScript(VIMEO_API, nonce).then(function () {
                if (window.Vimeo && window.Vimeo.Player) { resolve(window.Vimeo); } else { reject(new Error('api_load_failed')); }
            }, reject);
        }), API_TIMEOUT_MS);
        apiPromises.vimeo.catch(function () { apiPromises.vimeo = null; });
        return apiPromises.vimeo;
    }

    /** Plain-language text for a player error code (shown by the frame page and the player). */
    function errorMessage(code) {
        switch (String(code || '')) {
            case 'yt_2': return "This link doesn't point to a playable video. Check the link.";
            case 'yt_5': return "This video can't be played in this browser.";
            case 'yt_100': return 'This video was removed or is private. In YouTube Studio set Visibility to Unlisted.';
            case 'yt_101':
            case 'yt_150': return "The owner doesn't allow embedding. In YouTube Studio: Video › Show more › Allow embedding.";
            case 'yt_153': return "The player didn't receive this site's address (error 153). Reload and try again.";
            case 'vimeo_PrivacyError': return 'This Vimeo video is private, or the link is missing its privacy code.';
            case 'vimeo_NotFoundError': return 'This Vimeo video was not found. Check the link.';
            case 'api_load_failed': return "The video player couldn't load. Check the internet connection and try again.";
            case 'bad_url': return 'This video link is not valid.';
            default: return "This video can't be played right now.";
        }
    }

    function safeCode(prefix, raw) {
        var c = prefix + String(raw === undefined || raw === null ? 'error' : raw).replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 30);
        return c.length > 40 ? c.slice(0, 40) : c;
    }

    function mount(container, opts) {
        opts = opts || {};
        var provider = opts.provider === 'vimeo' ? 'vimeo' : (opts.provider === 'youtube' ? 'youtube' : null);
        var url = String(opts.embedUrl || '');
        var destroyed = false;
        var player = null;
        var playing = false;
        var firedPlaying = false;
        var pollTimer = null;
        var lastTime = 0;
        var lastDuration = 0;

        function call(name, a, b) {
            if (destroyed || typeof opts[name] !== 'function') { return; }
            try { opts[name](a, b); } catch (e) { /* host callback errors never break the player */ }
        }
        function fail(code) { call('onError', code, errorMessage(code)); }

        var wrap = document.createElement('div');
        wrap.className = 'trv-embed';
        var iframe = document.createElement('iframe');
        iframe.className = 'trv-embed__frame';
        iframe.title = opts.title || 'Video';
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('frameborder', '0');

        var ctrl = {
            iframe: iframe,
            play: function () { if (!player) { return; } if (provider === 'youtube') { player.playVideo(); } else { player.play().catch(function () { /* needs a tap first */ }); } },
            pause: function () { if (!player) { return; } if (provider === 'youtube') { player.pauseVideo(); } else { player.pause().catch(function () { /* ignore */ }); } },
            toggle: function () { if (playing) { ctrl.pause(); } else { ctrl.play(); } },
            seekTo: function (s) {
                if (!player) { return; }
                s = Math.max(0, Number(s) || 0);
                if (provider === 'youtube') { player.seekTo(s, true); } else { player.setCurrentTime(s).catch(function () { /* ignore */ }); }
                lastTime = s;
            },
            seekBy: function (d) { ctrl.seekTo(Math.max(0, lastTime + (Number(d) || 0))); },
            getCurrentTime: function () { return lastTime; },
            getDuration: function () { return lastDuration; },
            isPlaying: function () { return playing; },
            destroy: function () {
                destroyed = true;
                stopPoll();
                try { if (player && provider === 'youtube' && player.destroy) { player.destroy(); } } catch (e) { /* ignore */ }
                try { if (player && provider === 'vimeo' && player.unload) { player.unload(); } } catch (e) { /* ignore */ }
                if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
            }
        };

        if (!provider || url.indexOf(ORIGINS[provider]) !== 0) {
            setTimeout(function () { fail('bad_url'); }, 0);
            return ctrl;
        }
        iframe.src = url;
        wrap.appendChild(iframe);
        container.appendChild(wrap);

        function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
        function startPoll(read) {
            stopPoll();
            pollTimer = setInterval(function () {
                read().then(function (t) {
                    if (typeof t === 'number' && !isNaN(t)) { lastTime = t; }
                    call('onTime', { current: lastTime, duration: lastDuration });
                }, function () { /* ignore */ });
            }, 500);
        }
        function durationThen(read, tries) {
            return read().then(function (d) {
                d = Number(d) || 0;
                if (d > 0 || tries <= 0) { return d; }
                return new Promise(function (r) { setTimeout(r, 250); }).then(function () { return durationThen(read, tries - 1); });
            });
        }
        function onPlay(readDuration, readTime, confirmed) {
            if (!playing) {
                playing = true;
                call('onState', 'playing');
                startPoll(readTime);
            }
            if (confirmed !== false && !firedPlaying) {
                firedPlaying = true;
                durationThen(readDuration, 20).then(function (d) {
                    lastDuration = d;
                    call('onPlaying', Math.round(d));
                }, function () { call('onPlaying', 0); });
            }
        }

        if (provider === 'youtube') {
            loadYouTube(pageNonce(opts)).then(function (YT) {
                if (destroyed) { return; }
                var readDuration = function () { return Promise.resolve(player && player.getDuration ? player.getDuration() : 0); };
                var readTime = function () { return Promise.resolve(player && player.getCurrentTime ? player.getCurrentTime() : 0); };
                player = new YT.Player(iframe, {
                    events: {
                        onReady: function () {
                            try { lastDuration = Number(player.getDuration()) || 0; } catch (e) { lastDuration = 0; }
                            call('onReady', ctrl);
                        },
                        onStateChange: function (e) {
                            var s = e && e.data;
                            if (s === 1) { onPlay(readDuration, readTime); return; }
                            if (s === 2 || s === 0) {
                                playing = false;
                                stopPoll();
                                readTime().then(function (t) { lastTime = Number(t) || lastTime; call('onTime', { current: lastTime, duration: lastDuration }); });
                                call('onState', s === 0 ? 'ended' : 'paused');
                                return;
                            }
                            if (s === 3) { call('onState', 'buffering'); }
                        },
                        onPlaybackRateChange: function (e) {
                            if (e && e.data !== 1) { try { player.setPlaybackRate(1); player.pauseVideo(); } catch (x) { /* ignore */ } }
                        },
                        onError: function (e) { playing = false; stopPoll(); fail(safeCode('yt_', e && e.data)); }
                    }
                });
            }, function () { fail('api_load_failed'); });
        } else {
            loadVimeo(pageNonce(opts)).then(function (Vimeo) {
                if (destroyed) { return; }
                player = new Vimeo.Player(iframe);
                var readDuration = function () { return player.getDuration(); };
                var readTime = function () { return player.getCurrentTime(); };
                player.ready().then(function () {
                    player.getDuration().then(function (d) { lastDuration = Number(d) || 0; call('onReady', ctrl); }, function () { call('onReady', ctrl); });
                }, function (err) { fail(safeCode('vimeo_', err && err.name)); });
                player.on('playing', function () { onPlay(readDuration, readTime, true); });
                player.on('play', function () { onPlay(readDuration, readTime, false); });
                player.on('pause', function (d) { playing = false; stopPoll(); if (d && typeof d.seconds === 'number') { lastTime = d.seconds; } call('onState', 'paused'); });
                player.on('ended', function () { playing = false; stopPoll(); call('onState', 'ended'); });
                player.on('timeupdate', function (d) {
                    if (d && typeof d.seconds === 'number') { lastTime = d.seconds; }
                    if (d && typeof d.duration === 'number' && d.duration > 0) { lastDuration = d.duration; }
                    // Older player.js builds have no 'playing' event: time moving forward while
                    // playing is the proof of play.
                    if (playing && !firedPlaying && d && d.seconds > 0.5) { onPlay(readDuration, readTime, true); }
                });
                player.on('playbackratechange', function (d) {
                    if (d && d.playbackRate !== 1) { player.setPlaybackRate(1).catch(function () { /* ignore */ }); player.pause().catch(function () { /* ignore */ }); }
                });
                player.on('error', function (err) { playing = false; stopPoll(); fail(safeCode('vimeo_', err && err.name)); });
            }, function () { fail('api_load_failed'); });
        }
        return ctrl;
    }

    window.TrainingVideoEmbed = { mount: mount, errorMessage: errorMessage };
})();
