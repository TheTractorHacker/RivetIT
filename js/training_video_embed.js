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
 *               isPlaying(), getVideoId(), destroy(), iframe}
 *       getVideoId() (P3 §7.7): the id the provider says is loaded - YouTube getVideoData().video_id,
 *       Vimeo getVideoId() (read once through a cached promise) - or null; the kiosk sends it with ticks.
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
 *   - opts.transport === 'postmessage' (the kiosk video page): NO provider script is loaded at all. The
 *     page drives the player iframe with the providers' own postMessage protocols (YouTube's widget
 *     channel: "listening" / "command" out, "onReady" / "infoDelivery" / "onStateChange" / "onError" in;
 *     Vimeo's {method, value} out, {event, data} / {method, value} in), accepting messages only from the
 *     iframe's window and the provider's origin. Third-party code then never runs in the kiosk origin,
 *     so it can never open or read another kiosk page (the full session CSRF token lives there).
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

    /**
     * YouTube without iframe_api: the same widget protocol iframe_api speaks. The embed URL carries
     * enablejsapi=1 and origin=<this site> (VideoLink::embedUrl), so the player posts to this page.
     */
    function ytBridge(iframe, h) {
        var ORIGIN = 'https://www.youtube-nocookie.com';
        var id = 1 + Math.floor(Math.random() * 100000);
        var info = { currentTime: 0, duration: 0, playerState: -1, videoData: null, playbackRate: 1 };
        var ready = false;
        var heard = false;
        var listenTimer = null;
        function send(o) { try { iframe.contentWindow.postMessage(JSON.stringify(o), ORIGIN); } catch (e) { /* frame gone */ } }
        function cmd(func, args) { send({ event: 'command', func: func, args: args || [], id: id, channel: 'widget' }); }
        function num(v) { v = Number(v); return isFinite(v) ? v : null; }
        function absorb(i) {
            if (!i || typeof i !== 'object') { return; }
            if (num(i.currentTime) !== null) { info.currentTime = num(i.currentTime); }
            if (num(i.duration) !== null && num(i.duration) > 0) { info.duration = num(i.duration); }
            if (i.videoData && typeof i.videoData === 'object') { info.videoData = { video_id: String(i.videoData.video_id || '') }; }
            if (num(i.playbackRate) !== null && num(i.playbackRate) !== info.playbackRate) { info.playbackRate = num(i.playbackRate); h.rate(info.playbackRate); }
            if (num(i.playerState) !== null && num(i.playerState) !== info.playerState) { info.playerState = num(i.playerState); h.state(info.playerState); }
        }
        function onMsg(e) {
            if (e.source !== iframe.contentWindow || e.origin !== ORIGIN) { return; }
            var d = e.data;
            if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
            if (!d || typeof d !== 'object' || typeof d.event !== 'string') { return; }
            heard = true;
            if (listenTimer) { clearInterval(listenTimer); listenTimer = null; }
            if (d.event === 'onReady' || d.event === 'initialDelivery') {
                absorb(d.info);
                if (!ready) {
                    ready = true;
                    ['onStateChange', 'onError', 'onPlaybackRateChange'].forEach(function (ev) { cmd('addEventListener', [ev]); });
                    h.ready();
                }
            } else if (d.event === 'infoDelivery') {
                absorb(d.info);
            } else if (d.event === 'onStateChange') {
                if (num(d.info) !== null && num(d.info) !== info.playerState) { info.playerState = num(d.info); h.state(info.playerState); }
            } else if (d.event === 'onError') {
                h.error(d.info);
            } else if (d.event === 'onPlaybackRateChange') {
                if (num(d.info) !== null) { info.playbackRate = num(d.info); h.rate(info.playbackRate); }
            }
        }
        window.addEventListener('message', onMsg);
        function listen() { if (!heard) { send({ event: 'listening', id: id, channel: 'widget' }); } }
        iframe.addEventListener('load', function () { listen(); if (!listenTimer && !heard) { listenTimer = setInterval(listen, 250); } });
        var giveUp = setTimeout(function () { if (!ready) { h.fail(); } }, API_TIMEOUT_MS);
        return {
            playVideo: function () { cmd('playVideo'); },
            pauseVideo: function () { cmd('pauseVideo'); },
            seekTo: function (sec, ahead) { cmd('seekTo', [sec, !!ahead]); info.currentTime = sec; },
            setPlaybackRate: function (r) { cmd('setPlaybackRate', [r]); },
            getDuration: function () { return info.duration || 0; },
            getCurrentTime: function () { return info.currentTime || 0; },
            getVideoData: function () { return info.videoData; },
            isReady: function () { return ready; },
            destroy: function () { clearTimeout(giveUp); if (listenTimer) { clearInterval(listenTimer); } window.removeEventListener('message', onMsg); }
        };
    }

    /** Vimeo without player.js: the embed's own {method, value} message API. */
    function vimeoBridge(iframe, h) {
        var ORIGIN = 'https://player.vimeo.com';
        var ready = false;
        var heard = false;
        var pingTimer = null;
        var duration = 0;
        var current = 0;
        var videoId = null;
        function send(method, value) {
            var m = { method: method };
            if (value !== undefined) { m.value = value; }
            try { iframe.contentWindow.postMessage(m, ORIGIN); } catch (e) { /* frame gone */ }
        }
        function onMsg(e) {
            if (e.source !== iframe.contentWindow || e.origin !== ORIGIN) { return; }
            var d = e.data;
            if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
            if (!d || typeof d !== 'object') { return; }
            if (!heard) {
                heard = true;
                if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }
                ['play', 'playing', 'pause', 'ended', 'timeupdate', 'playbackratechange', 'error'].forEach(function (ev) { send('addEventListener', ev); });
                send('getDuration');
                send('getVideoId');
            }
            if (d.method === 'getDuration' && Number(d.value) > 0) { duration = Number(d.value); if (!ready) { ready = true; h.ready(); } return; }
            if (d.method === 'getVideoId' && /^[0-9]{1,12}$/.test(String(d.value))) { videoId = String(d.value); return; }
            var data = d.data && typeof d.data === 'object' ? d.data : {};
            if (typeof data.seconds === 'number') { current = data.seconds; }
            if (typeof data.duration === 'number' && data.duration > 0) { duration = data.duration; }
            switch (d.event) {
                case 'ready': if (!ready && duration > 0) { ready = true; h.ready(); } else { send('getDuration'); } break;
                case 'play': h.play(false); break;
                case 'playing': h.play(true); break;
                case 'pause': h.pause(); break;
                case 'ended': h.ended(); break;
                case 'timeupdate': h.time(); break;
                case 'playbackratechange': if (typeof data.playbackRate === 'number') { h.rate(data.playbackRate); } break;
                case 'error': h.error(data.name || 'error'); break;
                default: break;
            }
        }
        window.addEventListener('message', onMsg);
        function ping() { if (!heard) { send('ping'); send('addEventListener', 'ready'); } }
        iframe.addEventListener('load', function () { ping(); if (!pingTimer && !heard) { pingTimer = setInterval(ping, 400); } });
        var giveUp = setTimeout(function () { if (!ready) { h.fail(); } }, API_TIMEOUT_MS);
        var resolved = function (v) { return Promise.resolve(v); };
        return {
            play: function () { send('play'); return resolved(); },
            pause: function () { send('pause'); return resolved(); },
            setCurrentTime: function (sec) { send('setCurrentTime', sec); current = sec; return resolved(sec); },
            setPlaybackRate: function (r) { send('setPlaybackRate', r); return resolved(r); },
            getDuration: function () { return resolved(duration); },
            getCurrentTime: function () { return resolved(current); },
            getVideoId: function () { return videoId ? resolved(videoId) : Promise.reject(new Error('unknown')); },
            unload: function () { clearTimeout(giveUp); if (pingTimer) { clearInterval(pingTimer); } window.removeEventListener('message', onMsg); return resolved(); }
        };
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
        var vimeoId = null;
        var vimeoIdAsked = false;

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
            getVideoId: function () {
                if (!player) { return null; }
                if (provider === 'youtube') {
                    try {
                        var d = player.getVideoData ? player.getVideoData() : null;
                        return d && typeof d.video_id === 'string' && /^[A-Za-z0-9_-]{6,20}$/.test(d.video_id) ? d.video_id : null;
                    } catch (e) { return null; }
                }
                if (!vimeoIdAsked && player.getVideoId) {
                    vimeoIdAsked = true;
                    player.getVideoId().then(function (id) { if (/^[0-9]{1,12}$/.test(String(id))) { vimeoId = String(id); } }, function () { /* ignore */ });
                }
                return vimeoId;
            },
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

        if (opts.transport === 'postmessage' && provider === 'youtube') {
            var ytRead = function () { return Promise.resolve(player ? player.getCurrentTime() : 0); };
            var ytDur = function () { return Promise.resolve(player ? player.getDuration() : 0); };
            player = ytBridge(iframe, {
                ready: function () { lastDuration = Number(player.getDuration()) || 0; call('onReady', ctrl); },
                state: function (st) {
                    if (st === 1) { onPlay(ytDur, ytRead); return; }
                    if (st === 2 || st === 0) {
                        playing = false;
                        stopPoll();
                        lastTime = Number(player.getCurrentTime()) || lastTime;
                        call('onTime', { current: lastTime, duration: lastDuration });
                        call('onState', st === 0 ? 'ended' : 'paused');
                        return;
                    }
                    if (st === 3) { call('onState', 'buffering'); }
                },
                rate: function (r) { if (r !== 1) { player.setPlaybackRate(1); player.pauseVideo(); } },
                error: function (code) { playing = false; stopPoll(); fail(safeCode('yt_', code)); },
                fail: function () { fail('api_load_failed'); }
            });
            return ctrl;
        }
        if (opts.transport === 'postmessage' && provider === 'vimeo') {
            player = vimeoBridge(iframe, {
                ready: function () { player.getDuration().then(function (d) { lastDuration = Number(d) || 0; call('onReady', ctrl); }); },
                play: function (confirmed) { onPlay(function () { return player.getDuration(); }, function () { return player.getCurrentTime(); }, confirmed); },
                pause: function () { playing = false; stopPoll(); player.getCurrentTime().then(function (t) { lastTime = t; }); call('onState', 'paused'); },
                ended: function () { playing = false; stopPoll(); call('onState', 'ended'); },
                time: function () {
                    player.getCurrentTime().then(function (t) { lastTime = t; });
                    player.getDuration().then(function (d) { if (d > 0) { lastDuration = d; } });
                    // no 'playing' event from an older embed: time moving while playing is the proof of play
                    if (playing && !firedPlaying && lastTime > 0.5) { onPlay(function () { return player.getDuration(); }, function () { return player.getCurrentTime(); }, true); }
                },
                rate: function (r) { if (r !== 1) { player.setPlaybackRate(1); player.pause(); } },
                error: function (name) { playing = false; stopPoll(); fail(safeCode('vimeo_', name)); },
                fail: function () { fail('api_load_failed'); }
            });
            return ctrl;
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
