<?php

/*
 * The "Build on my build host" part of the Agent updates card (RivetIT 26.10.57). Markup only; the rules are in includes/rmm_build_host.php, the handler in
 * admin/post/settings_endpoint_agent.php (agent_feed = bh_*), the script in js/rmm_agent_feed.js. A token is printed only by rivetBuildHostReveal(), once, right after it was made.
 * Every value that came from a build host (note, log tail, version, address) is escaped here; log tails are written with textContent by the script and htmlspecialchars here.
 */

require_once __DIR__ . '/rmm_build_host.php';
require_once __DIR__ . '/rmm_agent_feed_ui.php';

const RIVET_BH_DOCS_URL = 'https://github.com/TheTractorHacker/rivet-core/blob/main/docs/rmm/BUILD_HOST.md';
const RIVET_BH_CONF_PATH = '/etc/rivetit/build-host.conf';

/** @return array{label:string,class:string} */
function rivetBuildHostBadge(string $state): array
{
    $map = ['queued' => ['Waiting for the build host', 'text-bg-info'], 'claimed' => ['Picked up', 'text-bg-info'], 'building' => ['Building', 'text-bg-primary'], 'uploading' => ['Uploading', 'text-bg-primary'],
        'verifying' => ['Verifying', 'text-bg-primary'], 'built' => ['Built, not published', 'text-bg-success'], 'published' => ['Published', 'text-bg-success'], 'failed' => ['Failed', 'text-bg-danger'],
        'expired' => ['Expired', 'text-bg-secondary'], 'cancelled' => ['Cancelled', 'text-bg-secondary']];

    return ['label' => $map[$state][0] ?? $state, 'class' => $map[$state][1] ?? 'text-bg-secondary'];
}

/** "3m 12s" for a number of seconds. */
function rivetBuildElapsed(int $s): string
{
    $s = max(0, $s);

    return $s >= 3600 ? intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm' : ($s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's');
}

/**
 * A progress bar. Every number is cast and every word comes from the caller's own strings, never from a build host.
 *
 * @param int|null $pct null = no percent known (an animated bar that says so)
 */
function rivetBuildProgressBar(?int $pct, string $label, bool $estimate = false, string $variant = 'bg-primary'): string
{
    $h = 'rivetAgentFeedH';
    if ($pct === null) {
        return '<div class="progress mt-1" style="height:.75rem" role="progressbar" aria-label="' . $h($label) . '" aria-valuetext="Working"><div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div></div>';
    }
    $pct = max(0, min(100, $pct));

    return '<div class="progress mt-1" style="height:.75rem" role="progressbar" aria-label="' . $h($label) . '" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar ' . $h($variant)
        . ($variant === 'bg-primary' ? ' progress-bar-striped progress-bar-animated' : '') . '" style="width:' . $pct . '%"></div></div>'
        . '<div class="small text-muted" data-bh-pct="1">' . ($estimate ? 'about ' : '') . $pct . '%</div>';
}

/**
 * The stage checklist. Each item is [label, status] with status done, active, pending or failed.
 *
 * @param list<array{0:string,1:string}> $items
 */
function rivetBuildChecklist(array $items): string
{
    $h = 'rivetAgentFeedH';
    $icon = ['done' => ['fas fa-check-circle text-success', 'Done'], 'active' => ['fas fa-spinner fa-spin text-primary', 'Working on it'], 'pending' => ['far fa-circle text-muted', 'Waiting'], 'failed' => ['fas fa-times-circle text-danger', 'Failed']];
    $o = '<ul class="list-unstyled small mb-0 mt-1" data-bh-steps="1">';
    foreach ($items as $it) {
        $st = isset($icon[$it[1]]) ? $it[1] : 'pending';
        $o .= '<li class="' . ($st === 'pending' ? 'text-muted' : ($st === 'active' ? 'fw-semibold' : '')) . '"><i class="' . $icon[$st][0] . ' me-1" aria-hidden="true"></i><span class="visually-hidden">' . $icon[$st][1] . ': </span>' . $h($it[0]) . '</li>';
    }

    return $o . '</ul>';
}

/**
 * What a build job on a build host is doing right now, in plain words, with a bar and the checklist. Everything shown is derived from the job's state, the allowlisted
 * stage and two integers; the detail text is only used for "7 of 23" after it matched a strict pattern.
 */
function rivetBuildHostProgressBlock(array $j): string
{
    $state = (string) $j['state'];
    $stages = RIVET_BH_STAGES;
    $order = array_keys($stages);
    $cur = array_search((string) ($j['progress_stage'] ?? ''), $order, true);
    $pct = (int) ($j['progress_pct'] ?? 0);
    $start = $j['claimed_at'] ?? null;
    $end = $j['finished_at'] ?? null;
    $elapsed = is_string($start) && $start !== '' ? ((is_string($end) && $end !== '' ? (int) strtotime($end . ' UTC') : time()) - (int) strtotime($start . ' UTC')) : null;
    $upload = null;
    if (preg_match('/^(?:Uploading |[Cc]hunk )?(\d{1,4}) ?(?:of|\/) ?(\d{1,4})$/', (string) ($j['progress_detail'] ?? ''), $m) === 1 && (int) $m[1] <= (int) $m[2]) {
        $upload = 'Uploading ' . (int) $m[1] . ' of ' . (int) $m[2];
    }
    $o = '<div class="mt-1" data-bh-progress="1">';
    if ($state === 'queued') {
        return $o . '<div class="small text-muted">Waiting for the build host to pick it up.</div></div>';
    }
    if (in_array($state, ['built', 'published'], true)) {
        $o .= rivetBuildProgressBar(100, 'Build progress', false, 'bg-success') . ($elapsed !== null ? '<div class="small text-muted">Took ' . rivetBuildElapsed($elapsed) . '</div>' : '');

        return $o . '</div>';
    }
    if ($cur === false) {   // an older build script (or one that has not reported yet): no stages, no percent
        $words = ['claimed' => 'Picked up', 'building' => 'Building', 'uploading' => 'Uploading the files', 'verifying' => 'Checking the files'][$state] ?? '';
        $o .= '<div class="small fw-semibold" data-bh-now="1">' . $words . '</div>' . (in_array($state, RIVET_BH_ACTIVE, true) ? rivetBuildProgressBar(null, 'Build progress') : '');
        $o .= $elapsed !== null ? '<div class="small text-muted" data-bh-elapsed="1">Running for ' . rivetBuildElapsed($elapsed) . '</div>' : '';

        return $o . '</div>';
    }
    $failed = $state === 'failed';
    $now = $failed ? 'Stopped while: ' . lcfirst($stages[$order[$cur]][0]) : ($order[$cur] === 'upload' && $upload !== null ? $upload : $stages[$order[$cur]][0]);
    $o .= '<div class="small fw-semibold" data-bh-now="1">' . rivetAgentFeedH($now) . '</div>';
    $o .= rivetBuildProgressBar($pct, 'Build progress', false, $failed ? 'bg-danger' : 'bg-primary');
    $o .= $elapsed !== null ? '<div class="small text-muted" data-bh-elapsed="1">' . ($failed ? 'Ran for ' : 'Running for ') . rivetBuildElapsed($elapsed) . '</div>' : '';
    $items = [];
    foreach ($order as $i => $k) {
        $items[] = [$stages[$k][1] . ($k === 'upload' && $upload !== null && $i === $cur && !$failed ? ' (' . substr($upload, 10) . ')' : ''), $i < $cur ? 'done' : ($i === $cur ? ($failed ? 'failed' : 'active') : 'pending')];
    }

    return $o . rivetBuildChecklist($items) . '</div>';
}

/** The address the build host must use: the address this site is configured for. */
function rivetBuildHostServerUrl(): string
{
    $base = trim((string) ($GLOBALS['config_base_url'] ?? ''));
    if ($base === '') {
        $base = (string) ($_SERVER['SERVER_NAME'] ?? 'your-server');
    }

    return (empty($GLOBALS['config_https_only']) ? 'http' : 'https') . '://' . preg_replace('#^https?://#i', '', rtrim($base, '/'));
}

/**
 * What an administrator sees once after a token was made: the token, the config file and the commands, with the real address and the real token.
 *
 * @param array{name:string,token:string} $r
 */
function rivetBuildHostReveal(array $r): string
{
    $h = 'rivetAgentFeedH';
    $conf = "SERVER_URL=" . rivetBuildHostServerUrl() . "/api/v1/build_host\nTOKEN=" . $r['token'] . "\nCORE_REPO=https://github.com/TheTractorHacker/rivet-core.git\nALLOWED_REFS=\"main v* agent-v*\"\nWORKDIR=/var/lib/rivetit-build-host\n";
    $cmd = 'sudo install -d /etc/rivetit && sudo install -o rivetit-build -g rivetit-build -m 0600 /dev/null ' . RIVET_BH_CONF_PATH . " && sudo tee " . RIVET_BH_CONF_PATH . " >/dev/null <<'EOF'\n" . $conf . "EOF";

    return '<div class="alert alert-warning mb-3" id="af-bh-reveal" role="alert" data-af-reveal="1"><div class="fw-semibold mb-1"><i class="fas fa-key me-1" aria-hidden="true"></i>Token for build host &ldquo;' . $h($r['name']) . '&rdquo;. Copy it now: it is shown only once.</div>'
        . '<div class="input-group input-group-sm mb-2"><input class="form-control font-monospace" id="af-bh-token" readonly value="' . $h($r['token']) . '" aria-label="Build host token">'
        . '<button class="btn btn-outline-secondary" type="button" data-af-copy="af-bh-token" id="af-bh-copy"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy</button></div>'
        . '<div class="small">Set up the build host (about five minutes). On the Linux machine that will build:</div>'
        . '<ol class="small ps-3 mb-2"><li>Install Docker and git, and get the build script from <a href="' . $h(RIVET_BH_DOCS_URL) . '" target="_blank" rel="noopener noreferrer">the build host guide</a> (<code>endpoint-agent/scripts/build-host.sh</code>, the service and the timer).</li>'
        . '<li>Save this configuration (the command below creates the file readable by root only and writes it):'
        . '<div class="input-group input-group-sm my-1"><textarea class="form-control font-monospace" id="af-bh-conf" rows="9" readonly aria-label="Install command">' . $h($cmd) . '</textarea></div>'
        . '<button class="btn btn-outline-secondary btn-sm" type="button" data-af-copy="af-bh-conf" id="af-bh-copycmd"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy the command</button></li>'
        . '<li>Check the connection: <code>sudo /opt/rivetit-build-host/build-host.sh --register</code> (as the build user) prints one status line. The host shows as online here within a minute of the timer starting.</li></ol>'
        . '<div class="small text-muted">If the token is lost, use <strong>New token</strong> next to the host: the old one stops working at once.</div></div>';
}

/** The token made by a request without JavaScript is kept in the server-side session for the very next page and removed when it is read. */
function rivetBuildHostTakeOnce(): ?array
{
    $r = $_SESSION['bh_reveal_once'] ?? null;
    unset($_SESSION['bh_reveal_once']);

    return is_array($r) && isset($r['name'], $r['token']) ? ['name' => (string) $r['name'], 'token' => (string) $r['token']] : null;
}

/** The hosts and the jobs: the part the page refreshes by itself while a build runs. */
function rivetBuildHostLive($mysqli, array $ctx): string
{
    $h = 'rivetAgentFeedH';
    $csrf = '<input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">';
    $when = static fn (?string $t): string => $t ? $h(rivetAgentFeedWhen(str_replace(' ', 'T', $t) . 'Z')) : '';
    $hosts = rivetBuildHostList($mysqli);
    $o = '<div id="af-bh-live">';
    $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-2" id="af-bh-hosts"><caption class="visually-hidden">Build hosts</caption><thead><tr><th scope="col">Build host</th><th scope="col">Status</th>'
        . '<th scope="col">Last seen</th><th scope="col"></th></tr></thead><tbody>';
    if ($hosts === []) {
        $o .= '<tr><td colspan="4" class="text-muted small">No build host yet. Add one below.</td></tr>';
    }
    foreach ($hosts as $r) {
        $st = rivetBuildHostStatus($r);
        $dot = ['online' => ['text-success', 'Online'], 'idle' => ['text-warning', 'Seen in the last day'], 'offline' => ['text-danger', 'Offline'], 'never' => ['text-secondary', 'Never connected'], 'disabled' => ['text-secondary', 'Disabled']][$st];
        $id = (int) $r['host_id'];
        $form = static fn (string $action, string $label, string $cls, string $confirm = '') => '<form action="post.php" method="post" class="d-inline" data-af-act="1"' . ($confirm !== '' ? ' data-af-confirm="' . $h($confirm) . '"' : '') . '>' . $csrf
            . '<input type="hidden" name="host_id" value="' . $id . '"><button class="btn btn-sm ' . $cls . '" type="submit" name="agent_feed" value="' . $action . '">' . $label . '</button></form> ';
        $o .= '<tr data-host="' . $id . '"><td><strong>' . $h($r['name']) . '</strong>' . ((int) $r['open_jobs'] > 0 ? ' <span class="badge text-bg-primary">busy</span>' : '') . '<div class="small text-muted">'
            . ($r['host_version'] !== '' ? 'script ' . $h($r['host_version']) . '; ' : '') . ($r['capabilities'] !== '' ? $h(str_replace(',', ', ', $r['capabilities'])) : '') . '</div></td>'
            . '<td><i class="fas fa-circle ' . $dot[0] . ' me-1" aria-hidden="true"></i><span class="small">' . $dot[1] . '</span></td>'
            . '<td class="small">' . ($r['last_seen_at'] ? $when((string) $r['last_seen_at']) . ($r['last_ip'] !== '' ? ' from ' . $h($r['last_ip']) : '') : 'never') . '</td><td class="text-end text-nowrap">'
            . ((int) $r['enabled'] ? $form('bh_disable', 'Disable', 'btn-outline-secondary', 'Disable this build host? Its waiting and running builds are cancelled and its token stops working.') : $form('bh_enable', 'Enable', 'btn-outline-primary'))
            . $form('bh_rotate', 'New token', 'btn-outline-primary', 'Make a new token for this build host? The old one stops working at once; the host must be given the new one.')
            . $form('bh_delete', 'Delete', 'btn-outline-danger', 'Delete this build host and its build history? Its token stops working.') . '</td></tr>';
    }
    $o .= '</tbody></table></div>';

    $jobs = rivetBuildHostJobs($mysqli, ['limit' => 10]);
    $o .= '<h6 class="mt-3 mb-1">Builds on build hosts</h6><div class="table-responsive"><table class="table table-sm align-middle mb-2" id="af-bh-jobs"><caption class="visually-hidden">Builds on build hosts</caption><thead><tr>'
        . '<th scope="col">Version</th><th scope="col">Host</th><th scope="col">Status</th><th scope="col">Started</th><th scope="col"></th></tr></thead><tbody>';
    if ($jobs === []) {
        $o .= '<tr><td colspan="5" class="text-muted small">No build was started on a build host yet.</td></tr>';
    }
    foreach ($jobs as $j) {
        $b = rivetBuildHostBadge((string) $j['state']);
        $id = (int) $j['job_id'];
        $open = in_array($j['state'], RIVET_BH_OPEN, true);
        $jf = static fn (string $action, string $label, string $cls, string $confirm = '') => '<form action="post.php" method="post" class="d-inline" data-af-act="1"' . ($confirm !== '' ? ' data-af-confirm="' . $h($confirm) . '"' : '') . '>' . $csrf
            . '<input type="hidden" name="job_id" value="' . $id . '"><button class="btn btn-sm ' . $cls . '" type="submit" name="agent_feed" value="' . $action . '">' . $label . '</button></form> ';
        $actions = '';
        if ($open || $j['state'] === 'built') {
            $actions .= $jf('bh_cancel', 'Cancel', 'btn-outline-danger', 'Cancel this build? The build host stops working on it the next time it reports.');
        }
        if ($j['state'] === 'built') {
            $o2 = $j['release_ring'] !== '' && (int) $j['offer'] ? ' It is offered to the ' . $j['release_ring'] . ' ring at ' . (int) $j['rollout_pct'] . '%.' : ' It is not offered to enrolled agents.';
            $actions = $jf('bh_publish', 'Publish', 'btn-primary', 'Publish version ' . $j['version'] . '? The verified files are stored on this server.' . ((int) $j['activate'] ? ' It becomes the version new installers are built from.' : '') . $o2) . $actions;
        }
        $log = trim((string) $j['log_tail']);
        $o .= '<tr data-job="' . $id . '"><td><span class="font-monospace">' . $h($j['version']) . '</span><div class="small text-muted">' . $h($j['source_ref']) . ($j['commit_sha'] !== '' ? ' @ ' . $h(substr((string) $j['commit_sha'], 0, 10)) : '') . '</div></td>'
            . '<td>' . $h($j['host_name'] ?? '(deleted)') . '</td><td><span class="badge ' . $b['class'] . '">' . $h($b['label']) . '</span>' . rivetBuildHostProgressBlock($j) . '<div class="small" data-bh-note="1">' . $h($j['note']) . '</div>'
            . ($log !== '' ? '<details class="small"><summary>Log tail</summary><pre class="small border rounded p-2 mb-0 bg-body-tertiary" style="max-height:14rem;overflow:auto;white-space:pre-wrap" data-bh-log="1">' . $h($log) . '</pre></details>' : '') . '</td>'
            . '<td class="small">' . $when((string) $j['created_at']) . '</td><td class="text-end text-nowrap">' . $actions . '</td></tr>';
    }
    $o .= '</tbody></table></div></div>';

    return $o;
}

/** @param array{csrf:string,module_on:bool,service_ok:bool} $ctx */
function rivetBuildHostBox($mysqli, bool $pilot, array $ctx, ?array $reveal = null): string
{
    $h = 'rivetAgentFeedH';
    if (!rivetBuildHostReady($mysqli)) {
        return '<div class="alert alert-warning py-2 small" id="af-build-local">&ldquo;Build on my build host&rdquo; needs a few small tables: run the database update (Administration &rarr; Updates), then reload this page.</div>';
    }
    rivetBuildHostSweep($mysqli);
    $csrf = '<input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">';
    $hosts = array_values(array_filter(rivetBuildHostList($mysqli), static fn (array $r): bool => (int) $r['enabled'] === 1));
    $active = rivetBuildHostJobs($mysqli, ['open' => true, 'limit' => 1]) !== [];
    $o = '<div id="af-build-local" data-active="' . ($active ? '1' : '0') . '"><p class="small text-muted mb-2">The build runs on a Linux machine you control (Docker and git), started from here. The machine only makes outgoing requests to this server: '
        . 'no inbound connection, no compiler on this server. Files are checked here (SHA-256, executable type, architecture, version) before anything is stored. '
        . '<a href="' . $h(RIVET_BH_DOCS_URL) . '" target="_blank" rel="noopener noreferrer">Build host guide</a></p>';
    if ($reveal !== null) {
        $o .= rivetBuildHostReveal($reveal);
    } else {
        $o .= '<div id="af-bh-reveal" class="d-none" data-af-reveal="1"></div>';
    }

    // ---- ask for a build
    if ($hosts === []) {
        $o .= '<div class="alert alert-secondary py-2 small mb-2">Add a build host first (below), set it up with the steps shown once, then come back to start a build.</div>';
    } else {
        $o .= '<form action="post.php" method="post" class="row g-2 align-items-end mb-2" data-af-act="1" autocomplete="off" id="af-bh-request">' . $csrf
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="af-bh-host">Build host</label><select class="form-select form-select-sm" id="af-bh-host" name="host_id">';
        foreach ($hosts as $r) {
            $o .= '<option value="' . (int) $r['host_id'] . '">' . $h($r['name']) . ' (' . $h(rivetBuildHostStatus($r)) . ')</option>';
        }
        $o .= '</select></div><div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="af-bh-version">New version</label>'
            . '<input class="form-control form-control-sm font-monospace" id="af-bh-version" name="version" value="' . $h(rivetBuildHostNextVersion($mysqli)) . '" maxlength="40" required pattern="\d{1,5}\.\d{1,5}\.\d{1,5}(-[0-9A-Za-z][0-9A-Za-z.\-]{0,19})?"></div>'
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="af-bh-ref">Source (branch or tag)</label><input class="form-control form-control-sm font-monospace" id="af-bh-ref" name="source_ref" value="main" maxlength="60" required pattern="[A-Za-z0-9][A-Za-z0-9._\-\/]{0,59}" aria-describedby="af-bh-refh"></div>'
            . '<div class="col-lg-3"><button class="btn btn-primary btn-sm" type="submit" name="agent_feed" value="bh_request" id="af-bh-go"><i class="fas fa-hammer me-1" aria-hidden="true"></i>Build on this host</button></div>'
            . '<div class="col-12 form-text" id="af-bh-refh">The build host builds only the branches and tags its own configuration allows, and refuses any other. Versions that exist already or are lower than the latest are refused.</div>'
            . '<div class="col-12"><details><summary class="small fw-semibold">Publish automatically when it is built (optional)</summary><div class="mt-2">'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-bh-auto-cb" name="build_auto" value="1"><label class="form-check-label small" for="af-bh-auto-cb">Publish it automatically as soon as it is built and verified, with the choices below. Without this it stops at &ldquo;Built, not published&rdquo; and you press Publish.</label></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-bh-act" name="activate" value="1" checked><label class="form-check-label small" for="af-bh-act">Use for new installers (make current)</label></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-bh-off" name="offer" value="1"' . ($ctx['service_ok'] ? ' checked' : ' disabled') . ' data-af-offer="1"><label class="form-check-label small" for="af-bh-off">Offer to enrolled agents as an update</label></div>'
            . '<div class="row g-2 align-items-end" data-af-offer-fields="1"><div class="col-sm-4"><label class="form-label small mb-0" for="af-bh-ring">Ring</label><select class="form-select form-select-sm" id="af-bh-ring" name="ring">'
            . '<option value="pilot"' . ($pilot ? ' selected' : '') . '>Pilot ring</option><option value="stable"' . ($pilot ? '' : ' selected') . '>Stable ring (everyone)</option></select></div>'
            . '<div class="col-sm-8"><label class="form-label small mb-0" for="af-bh-pct">Roll out to <output data-af-pct-out="1">100</output>% of the ring</label><input type="range" class="form-range" id="af-bh-pct" name="rollout_pct" min="0" max="100" step="5" value="100" data-af-pct="1"></div></div>'
            . '</div></details></div></form>';
    }

    $o .= rivetBuildHostLive($mysqli, $ctx);

    // ---- add a host
    $o .= '<form action="post.php" method="post" class="row g-2 align-items-end border-top pt-2" data-af-act="1" autocomplete="off" id="af-bh-add">' . $csrf
        . '<div class="col-sm-6 col-lg-4"><label class="form-label small mb-0" for="af-bh-name">New build host</label><input class="form-control form-control-sm" id="af-bh-name" name="name" maxlength="80" required placeholder="for example: build-server in the lab"></div>'
        . '<div class="col-sm-6"><button class="btn btn-sm btn-outline-primary" type="submit" name="agent_feed" value="bh_add" id="af-bh-addgo"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add build host</button></div>'
        . '<div class="col-12 form-text">You get a token to copy (shown once, stored here only as a salted hash) and the exact commands to set the machine up. A token can only poll for builds and upload their files; it opens nothing else.</div></form>';

    return $o . '</div>';
}
