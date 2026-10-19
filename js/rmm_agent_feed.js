/*
 * Administration > Endpoint agent > Agent updates (markup: includes/rmm_agent_feed_ui.php, handler: admin/post/settings_endpoint_agent.php, rules: includes/rmm_agent_feed.php).
 *
 * "Check for updates" and "Publish" are ordinary form posts; this script only makes them friendlier. Publish asks the server for the list of files of the release, then asks it to
 * download, verify and store them ONE AT A TIME so the page can show what is happening and which file failed. Every decision (what may be contacted, the checksums, who may publish)
 * is the server's; server text is written with textContent only. Without JavaScript the forms post normally and the server does every file in one request.
 */
(function () {
    'use strict';
    var card = document.getElementById('agent-updates');
    if (!card) { return; }
    var url = card.getAttribute('data-feed-url') || 'post.php';
    var csrf = card.getAttribute('data-csrf') || '';
    var busy = false;

    function post(fields) {
        var body = new URLSearchParams();
        body.set('csrf_token', csrf);
        Object.keys(fields).forEach(function (k) { if (fields[k] !== null && fields[k] !== undefined) { body.set(k, fields[k]); } });
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
            .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e) { j = null; } return { status: r.status, json: j }; }); });
    }

    function say(text, kind) {
        var box = document.getElementById('af-alert');
        if (!box) { return; }
        box.textContent = '';
        if (!text) { return; }
        var d = document.createElement('div');
        d.className = 'alert alert-' + (kind || 'info') + ' py-2';
        d.textContent = text;
        box.appendChild(d);
    }

    // The server sends the whole card again after a check or a publish; swap its inside (this element, its listeners and its data attributes stay).
    function swap(html) {
        if (!html) { return; }
        var t = document.createElement('template');
        t.innerHTML = html;
        var fresh = t.content.getElementById('agent-updates') || t.content.querySelector('#agent-updates');
        if (!fresh) { return; }
        var keep = document.getElementById('af-alert') ? document.getElementById('af-alert').innerHTML : '';
        card.innerHTML = fresh.innerHTML;
        var box = document.getElementById('af-alert');
        if (box && keep) { box.innerHTML = keep; }
    }

    function human(msg) { return String(msg || ''); }

    function bad(r) { return !r.json ? 'The server did not answer in the expected way (HTTP ' + r.status + '). Reload the page.' : human(r.json.message); }

    // ---- Check for updates
    card.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.id === 'af-check-form') {
            e.preventDefault();
            if (busy) { return; }
            busy = true;
            var btn = document.getElementById('af-check');
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            say('Asking GitHub for the release list...', 'info');
            post({ agent_feed: 'check' }).then(function (r) {
                var ok = r.json && r.json.success;
                swap(r.json && r.json.html);
                say(bad(r), ok ? 'success' : 'warning');
            }).catch(function () { say('Could not reach this server. Try again.', 'danger'); }).then(function () { busy = false; var b = document.getElementById('af-check'); if (b) { b.disabled = false; b.removeAttribute('aria-busy'); } });
            return;
        }
        if (f.hasAttribute('data-af-publish')) {
            e.preventDefault();
            publish(f);
            return;
        }
        if (f.hasAttribute('data-af-act')) {
            e.preventDefault();
            act(f, e.submitter);
        }
    });

    // ---- Build a new version: token buttons, Build, Refresh status. Every decision is the server's; its text is shown with textContent only.
    function act(f, btn) {
        if (busy || !btn || !btn.name) { return; }
        var q = f.getAttribute('data-af-confirm');
        if (q && !window.confirm(q)) { return; }
        var fields = {};
        new FormData(f).forEach(function (v, k) { if (k !== 'csrf_token' && typeof v === 'string') { fields[k] = v; } });
        fields[btn.name] = btn.value;
        busy = true;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        var tokenIn = f.querySelector('[name="gh_token"]');
        post(fields).then(function (r) {
            var ok = r.json && r.json.success;
            if (r.json && r.json.html) { swap(r.json.html); }
            say(bad(r), ok ? 'success' : 'danger');
            if (tokenIn) { tokenIn.value = ''; }
        }).catch(function () { say('Could not reach this server. Try again.', 'danger'); })
            .then(function () { busy = false; btn.disabled = false; btn.removeAttribute('aria-busy'); watch(); });
    }

    var STATE_LABEL = { dispatching: 'Starting', queued: 'Waiting in GitHub\'s queue', building: 'Building', finished: 'Built', failed: 'Failed' };
    var STATE_CLASS = { dispatching: 'text-bg-info', queued: 'text-bg-info', building: 'text-bg-primary', finished: 'text-bg-success', failed: 'text-bg-danger' };
    var timer = null;

    // While a build runs (or a finished one waits to be published) ask the server every ten seconds; the server asks GitHub, at most every eight seconds.
    function watch() {
        var box = document.getElementById('af-build');
        if (timer) { clearTimeout(timer); timer = null; }
        if (!box || box.getAttribute('data-active') !== '1') { return; }
        timer = setTimeout(poll, 10000);
    }
    function poll() {
        timer = null;
        if (busy) { watch(); return; }
        post({ agent_feed: 'build_status' }).then(function (r) {
            if (!r.json) { return; }
            var badge = document.getElementById('af-build-badge');
            var note = document.getElementById('af-build-note');
            var link = document.getElementById('af-build-link');
            if (badge && STATE_LABEL[r.json.state]) { badge.textContent = STATE_LABEL[r.json.state]; badge.className = 'badge ' + STATE_CLASS[r.json.state]; }
            if (note && r.json.message) { note.textContent = String(r.json.message); }
            if (link && typeof r.json.url === 'string' && r.json.url.indexOf('https://github.com/') === 0) { link.href = r.json.url; link.classList.remove('d-none'); }
            if (r.json.html) { swap(r.json.html); }
            var b2 = document.getElementById('af-build');
            if (b2) { b2.setAttribute('data-active', r.json.active ? '1' : '0'); }
        }).catch(function () { /* try again next time */ }).then(watch);
    }
    watch();

    // ---- the options of a release
    card.addEventListener('input', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-af-pct')) {
            var out = t.form.querySelector('[data-af-pct-out]');
            if (out) { out.textContent = t.value; }
        }
    });
    card.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-af-offer')) {
            var wrap = t.form.querySelector('[data-af-offer-fields]');
            if (wrap) { wrap.querySelectorAll('select,input').forEach(function (i) { i.disabled = !t.checked; }); }
        }
    });

    function li(list, text, kind) {
        var l = document.createElement('li');
        var icon = document.createElement('i');
        icon.className = 'fas fa-fw me-1 ' + (kind === 'ok' ? 'fa-check-circle text-success' : kind === 'bad' ? 'fa-times-circle text-danger' : 'fa-spinner fa-spin text-muted');
        icon.setAttribute('aria-hidden', 'true');
        l.appendChild(icon);
        var s = document.createElement('span');
        s.textContent = text;
        l.appendChild(s);
        list.appendChild(l);
        return { li: l, icon: icon, text: s };
    }

    function publish(f) {
        if (busy) { return; }
        var tag = f.getAttribute('data-tag');
        var version = f.getAttribute('data-version');
        var offer = f.querySelector('[name="offer"]');
        var ring = f.querySelector('[name="ring"]');
        var pct = f.querySelector('[name="rollout_pct"]');
        var act = f.querySelector('[name="activate"]');
        var question = 'Publish ' + tag + '? Its files are downloaded from GitHub, checked against the release checksums and stored on this server.'
            + (act && act.checked ? ' It becomes the version new installers are built from.' : '')
            + (offer && offer.checked ? ' It is offered to enrolled agents on the ' + ring.value + ' ring at ' + pct.value + '%.' : ' It is not offered to enrolled agents.');
        if (!window.confirm(question)) { return; }
        busy = true;
        var go = f.querySelector('[data-af-go]');
        var prog = f.querySelector('[data-af-progress]');
        var bar = f.querySelector('[data-af-bar]');
        var list = f.querySelector('[data-af-steps]');
        go.disabled = true;
        go.setAttribute('aria-busy', 'true');
        prog.classList.remove('d-none');
        list.textContent = '';
        bar.style.width = '0%';
        var opts = { tag: tag, activate: act && act.checked ? '1' : null, offer: offer && offer.checked ? '1' : null, ring: ring ? ring.value : 'stable', rollout_pct: pct ? pct.value : '100' };
        var done = 0, failed = 0, summary = [], results = [];
        // the card is redrawn at the end; what happened to each file stays under the message (written with textContent)
        function report() {
            var box = document.getElementById('af-alert');
            if (!box || !results.length) { return; }
            var ul = document.createElement('ul');
            ul.className = 'list-unstyled small mb-0 mt-1';
            ul.setAttribute('data-af-result', '1');
            results.forEach(function (r) { li(ul, r.text, r.ok ? 'ok' : 'bad'); });
            box.firstChild ? box.firstChild.appendChild(ul) : box.appendChild(ul);
        }
        function finish(msg, kind) {
            post({ agent_feed: 'finish', tag: tag, summary: summary.join('; ') }).then(function (r) {
                swap(r.json && r.json.html);
                say(msg, kind);
                report();
            }).catch(function () { say(msg, kind); report(); }).then(function () { busy = false; });
        }
        post({ agent_feed: 'plan', tag: tag }).then(function (r) {
            if (!r.json || !r.json.success) { say(bad(r), 'danger'); busy = false; go.disabled = false; go.removeAttribute('aria-busy'); return; }
            var steps = r.json.steps || [];
            var i = 0;
            function next() {
                if (i >= steps.length) {
                    bar.style.width = '100%';
                    bar.setAttribute('aria-valuenow', '100');
                    finish(failed === 0 ? 'Published ' + version + ': all ' + steps.length + ' files are in place.' : failed + ' of ' + steps.length + ' files of ' + version + ' were not stored. See the list for the reason.', failed === 0 ? 'success' : 'warning');
                    return;
                }
                var s = steps[i];
                var row = li(list, (s.store ? 'Downloading and verifying ' : 'Recording ') + s.label + ' (' + s.asset + ')...', 'work');
                post(Object.assign({ agent_feed: 'step', asset: s.asset }, opts)).then(function (rs) {
                    var ok = rs.json && rs.json.success;
                    row.icon.className = 'fas fa-fw me-1 ' + (ok ? 'fa-check-circle text-success' : 'fa-times-circle text-danger');
                    row.text.textContent = bad(rs);
                    if (ok) { done++; } else { failed++; }
                    results.push({ ok: !!ok, text: bad(rs) });
                    summary.push(s.asset + ':' + (rs.json && rs.json.status ? rs.json.status : 'error'));
                }).catch(function () {
                    failed++;
                    row.icon.className = 'fas fa-fw me-1 fa-times-circle text-danger';
                    row.text.textContent = s.asset + ': the request failed (is this server reachable?).';
                    results.push({ ok: false, text: s.asset + ': the request failed (is this server reachable?).' });
                    summary.push(s.asset + ':error');
                }).then(function () {
                    i++;
                    var w = Math.round(i * 100 / steps.length);
                    bar.style.width = w + '%';
                    bar.setAttribute('aria-valuenow', String(w));
                    next();
                });
            }
            next();
        }).catch(function () { say('Could not reach this server. Try again.', 'danger'); busy = false; go.disabled = false; go.removeAttribute('aria-busy'); });
    }
})();
