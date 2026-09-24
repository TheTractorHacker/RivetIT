/*
 * Training in-window video check frame - page init for agent/training_video_frame.php (spec §5.4).
 *
 * Mounts the player (js/training_video_embed.js). On the first PLAYING it POSTs video_verify with
 * the player's own duration; on a player error it POSTs {ok:false, error_code}. Every outcome is
 * reported to the framing window with postMessage(msg, location.origin) - never '*' - and on
 * BroadcastChannel('itflow-training') as {type, payload}, so other open tabs (another content
 * window using the same video, a preview) can update too.
 *
 *   {type:'tr-video-ready',    provider, ext_id, ext_hash}
 *   {type:'tr-video-verified', provider, ext_id, ext_hash, duration_s, check}
 *   {type:'tr-video-error',    provider, ext_id, ext_hash, error_code, message, check}
 *
 * No training_common.js here (the frame has its own tiny CSP surface); the CSRF token comes from
 * the page's nonce'd JSON block. Text only ever reaches the DOM through textContent.
 */
(function () {
    'use strict';

    function readData() {
        var node = document.getElementById('trv-data');
        if (!node) { return null; }
        try { return JSON.parse(node.textContent || 'null'); } catch (e) { return null; }
    }

    function start() {
        var data = readData();
        var host = document.getElementById('trv-player');
        var statusEl = document.getElementById('trv-status');
        if (!data || !host || !window.TrainingVideoEmbed) { return; }
        var framed = window.parent && window.parent !== window;
        var ext = data.ext || {};
        var base = { provider: ext.provider, ext_id: ext.id, ext_hash: ext.hash ? ext.hash : null };
        var bc = null;
        try { bc = new BroadcastChannel('itflow-training'); } catch (e) { bc = null; }

        function report(type, extra) {
            var msg = Object.assign({ type: type }, base, extra || {});
            if (framed) {
                try { window.parent.postMessage(msg, window.location.origin); } catch (e) { /* ignore */ }
            }
            if (bc) {
                var payload = Object.assign({}, msg);
                delete payload.type;
                try { bc.postMessage({ type: type, payload: payload }); } catch (e) { /* ignore */ }
            }
        }

        function status(text, kind) {
            // Only shown when the page is opened on its own; in a frame the parent shows the result.
            if (!statusEl || framed) { return; }
            statusEl.hidden = !text;
            statusEl.textContent = text || '';
            statusEl.className = 'trv-status' + (kind ? ' is-' + kind : '');
        }

        function post(body) {
            return fetch(data.verify_url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': data.csrf || '' },
                body: JSON.stringify(body)
            }).then(function (resp) {
                var ctype = (resp.headers.get('Content-Type') || '').toLowerCase();
                if (resp.type === 'opaqueredirect' || ctype.indexOf('application/json') === -1) {
                    return { ok: false, error: { code: 'session', message: 'Your session needs a refresh. Reload the window.' } };
                }
                return resp.json().catch(function () {
                    return { ok: false, error: { code: 'session', message: 'Your session needs a refresh. Reload the window.' } };
                });
            }, function () {
                return { ok: false, error: { code: 'network', message: 'You appear to be offline. Play the video again when you are back online.' } };
            });
        }

        var verified = false;
        window.TrainingVideoEmbed.mount(host, {
            provider: data.provider,
            embedUrl: data.embed_url,
            title: 'Video check',
            onReady: function () { report('tr-video-ready'); },
            onPlaying: function (durationS) {
                if (verified) { return; }
                if (!durationS || durationS < 1) {
                    report('tr-video-error', { error_code: 'no_duration', message: "The player didn't report the video's length. Play it again.", check: null });
                    return;
                }
                verified = true;
                post(Object.assign({}, base, { ext_hash: base.ext_hash || '', duration_s: durationS, ok: true })).then(function (env) {
                    if (env && env.ok) {
                        var check = env.data ? env.data.check : null;
                        status('Verified · ' + fmt(durationS), 'ok');
                        report('tr-video-verified', { duration_s: durationS, check: check });
                    } else {
                        verified = false;
                        var err = (env && env.error) || {};
                        status(err.message || 'The check could not be saved.', 'error');
                        report('tr-video-error', { error_code: err.code || 'server', message: err.message || 'The check could not be saved.', check: null });
                    }
                });
            },
            onError: function (code, message) {
                status(message, 'error');
                post(Object.assign({}, base, { ext_hash: base.ext_hash || '', ok: false, error_code: code })).then(function (env) {
                    report('tr-video-error', { error_code: code, message: message, check: env && env.ok && env.data ? env.data.check : null });
                });
            }
        });
    }

    function fmt(s) {
        s = Math.max(0, Math.round(Number(s) || 0));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return h > 0 ? h + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
