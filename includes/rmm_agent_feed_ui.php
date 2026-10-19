<?php

/*
 * The "Agent updates" card of Administration > Endpoint agent (markup only; the rules are in includes/rmm_agent_feed.php, the script is js/rmm_agent_feed.js).
 * Without JavaScript every button is an ordinary form post that the handler in admin/post/settings_endpoint_agent.php answers with a flash message.
 */

require_once __DIR__ . '/rmm_agent_feed.php';
require_once __DIR__ . '/rmm_agent_build_ui.php';   // 26.10.55: the Build a new version box

function rivetAgentFeedH($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "2026-10-18T17:16:53Z" -> "2026-10-18 17:16 UTC". */
function rivetAgentFeedWhen(?string $s): string
{
    if ($s === null || $s === '') {
        return '';
    }
    $t = strtotime(str_replace('T', ' ', rtrim($s, 'Z')) . ' UTC');

    return $t === false ? '' : gmdate('Y-m-d H:i', $t) . ' UTC';
}

function rivetAgentFeedKindLabel(string $kind, string $arch): string
{
    $a = $arch === 'arm64' ? 'ARM64' : ($arch === 'amd64' ? 'x64' : '');
    $map = ['exe' => 'Windows ' . $a . ' agent', 'msi' => 'Windows ' . $a . ' MSI', 'script' => 'install-linux.sh', 'binary' => 'Linux ' . $a . ' agent', 'tarball' => 'Linux ' . $a . ' .tar.gz',
        'deb' => 'Linux ' . $a . ' .deb', 'rpm' => 'Linux ' . $a . ' .rpm'];

    return trim($map[$kind] ?? $kind);
}

/**
 * The card.
 *
 * @param array{csrf:string,module_on:bool,service_ok:bool} $ctx
 */
function rivetAgentFeedCard($mysqli, \RivetCore\Rmm\RmmModule $rmm, array $ctx): string
{
    $h = 'rivetAgentFeedH';
    $out = '<div class="card mb-3" id="agent-updates" data-feed-url="post.php" data-csrf="' . $h($ctx['csrf']) . '">';
    if (!rivetAgentFeedReady($mysqli)) {
        return $out . '<div class="card-header"><h4 class="card-title mb-0"><i class="fas fa-fw fa-cloud-download-alt me-2" aria-hidden="true"></i>Agent updates</h4></div><div class="card-body"><div class="alert alert-warning mb-0">'
            . 'Run the database update first (Administration &rarr; Updates), then reload this page. The agent updates list needs two small tables that this database does not have yet.</div></div></div>';
    }
    $feed = rivetAgentFeedCached($mysqli);
    $st = rivetAgentFeedStatus($mysqli, $feed['releases']);
    $pilot = rivetAgentFeedPilotExists($mysqli);
    $defRing = $pilot ? 'pilot' : 'stable';
    $isDefaultRepo = $feed['repo'] === RIVET_AGENT_FEED_DEFAULT_REPO;
    $newer = $st['latest'] !== null && ($st['published_max'] === null || rivetAgentFeedVersionCmp($st['latest'], $st['published_max']) > 0);

    $out .= '<div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-cloud-download-alt me-2" aria-hidden="true"></i>Agent updates'
        . ' <span id="af-badge">' . ($newer ? '<span class="badge text-bg-warning ms-2" role="status">New version available: ' . $h($st['latest']) . '</span>'
            : ($st['latest'] !== null ? '<span class="badge text-bg-success ms-2">Up to date</span>' : '')) . '</span></h4>'
        . '<form action="post.php" method="post" class="d-flex align-items-center gap-2 mb-0" id="af-check-form"><input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">'
        . '<span class="small text-muted" id="af-checked">' . ($feed['fetched_at'] ? 'Last checked ' . $h(rivetAgentFeedWhen(str_replace(' ', 'T', (string) $feed['fetched_at']) . 'Z')) : 'Not checked yet') . '</span>'
        . '<button class="btn btn-sm btn-outline-primary" type="submit" name="agent_feed" value="check" id="af-check"><i class="fas fa-sync me-1" aria-hidden="true"></i>Check for updates</button></form></div>';
    $out .= '<div class="card-body"><div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 py-2" id="af-installer-hint"><span>Need to put the agent on a PC or server? Build the installer, copy the silent-install command, or get the Linux packages.</span>'
        . '<a class="btn btn-primary btn-sm" href="/agent/rmm_add_device.php" id="af-installer-link"><i class="fas fa-tools me-1" aria-hidden="true"></i>Add device / Build installer</a></div>'
        . '<p class="small text-muted">Releases of the public GitHub repository <code>' . $h($feed['repo']) . '</code> whose tag starts with <code>agent-v</code>. <strong>Publish</strong> downloads the Windows agent (x64 and ARM64) and the '
        . 'MSI template from GitHub, checks every file against the release\'s <code>SHA256SUMS</code> <em>before</em> storing it, and then stores it exactly like an upload in Agent binaries and MSI template below. '
        . 'The Linux files (install script, binary, <code>.tar.gz</code>, <code>.deb</code>, <code>.rpm</code>) are not copied: their address and SHA-256 are recorded so the Add device page can give a Linux install command that checks them. '
        . 'Nothing is offered to your devices unless you leave &ldquo;Offer to enrolled agents&rdquo; ticked.</p>';
    $out .= '<div id="af-alert" class="small" role="status" aria-live="polite">';
    if ($feed['error'] !== '') {
        $out .= '<div class="alert alert-warning py-2">' . $h($feed['error']) . ($feed['releases'] !== [] ? ' The list below is the one saved ' . $h(rivetAgentFeedWhen($feed['fetched_at'] ? str_replace(' ', 'T', (string) $feed['fetched_at']) . 'Z' : '')) . '.' : '') . '</div>';
    } elseif ($feed['releases'] === []) {
        $out .= '<div class="alert alert-info py-2">' . ($feed['fetched_at'] ? 'No agent release (tag <code>agent-v*</code>) was found in ' . $h($feed['repo']) . '.' : 'Press <strong>Check for updates</strong> to read the release list from GitHub. This server contacts GitHub only when you do.') . '</div>';
    }
    $out .= '</div>';
    if (!$ctx['service_ok']) {
        $out .= '<div class="alert alert-warning py-2 small">The service URL is not an https:// address yet (Service and defaults). A release can be stored, but it cannot be offered to enrolled agents until it is.</div>';
    }

    // ---- build a new version (26.10.55)
    $out .= rivetAgentBuildBox($mysqli, $feed['releases'], $feed['repo'], $pilot, $ctx);

    // ---- the releases
    $out .= '<div class="table-responsive"><table class="table table-sm align-middle mb-2" id="af-table"><caption class="visually-hidden">Agent releases on GitHub</caption><thead><tr><th scope="col">Version</th><th scope="col">Released</th>'
        . '<th scope="col">Files</th><th scope="col">On this server</th><th scope="col" class="text-end">Devices</th><th scope="col"></th></tr></thead><tbody>';
    $listed = [];
    foreach ($st['rows'] as $row) {
        $r = $row['release'];
        $v = (string) $r['version'];
        $listed[$v] = true;
        $files = '';
        foreach ($row['plan'] as $p) {
            $files .= '<span class="badge text-bg-light border me-1 mb-1" title="' . $h($p['asset']) . ($p['size'] > 0 ? ', ' . $h(\RivetCore\Rmm\Binaries\BinaryStore::human($p['size'])) : '') . '">' . $h(rivetAgentFeedKindLabel($p['kind'], $p['arch'])) . '</span>';
        }
        $stateBadge = ['published' => '<span class="badge text-bg-success">Published</span>', 'partial' => '<span class="badge text-bg-warning">Partly published</span>',
            'new' => '<span class="badge text-bg-secondary">Not published</span>', 'nothing' => '<span class="badge text-bg-secondary">No usable files</span>'][$row['state']];
        $detail = [];
        if ($row['have']['exe']) {
            $detail[] = 'exe: ' . implode(', ', array_map(static fn ($a) => $a === 'arm64' ? 'ARM64' : 'x64', $row['have']['exe']));
        }
        if ($row['have']['msi']) {
            $detail[] = 'msi: ' . implode(', ', array_map(static fn ($a) => $a === 'arm64' ? 'ARM64' : 'x64', $row['have']['msi']));
        }
        if ($row['have']['linux']) {
            $detail[] = 'Linux files: ' . (int) $row['have']['linux'];
        }
        if ($row['current']) {
            $detail[] = 'used for new installers';
        }
        if ($row['offered']) {
            $detail[] = 'offered: ' . implode(', ', $row['offered']);
        }
        $fid = 'af-pub-' . preg_replace('/[^A-Za-z0-9]/', '-', $v);
        $out .= '<tr data-tag="' . $h($r['tag']) . '"><th scope="row" class="text-nowrap">' . $h($v) . ($r['prerelease'] ? ' <span class="badge text-bg-info" title="GitHub marks this release as a pre-release">Pre-release</span>' : '') . '</th>'
            . '<td class="text-nowrap">' . $h(rivetAgentFeedWhen($r['published_at'])) . '</td><td>' . ($files ?: '<span class="text-muted">none</span>') . '</td>'
            . '<td>' . $stateBadge . ($detail ? '<div class="small text-muted">' . $h(implode('; ', $detail)) . '</div>' : '') . '</td>'
            . '<td class="text-end">' . ((int) $row['devices'] > 0 ? '<a href="/agent/rmm_fleet.php" title="Devices reporting this version">' . (int) $row['devices'] . '</a>' : '<span class="text-muted">0</span>') . '</td>'
            . '<td class="text-end">' . ($row['plan'] ? '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#' . $fid . '" aria-expanded="false" aria-controls="' . $fid . '"><i class="fas fa-upload me-1" aria-hidden="true"></i>'
                . ($row['state'] === 'published' ? 'Publish again' : 'Publish') . '</button>' : '') . '</td></tr>';
        if ($row['plan']) {
            $out .= '<tr class="collapse" id="' . $fid . '"><td colspan="6" class="bg-body-tertiary">' . rivetAgentFeedPublishForm($ctx['csrf'], $r, $row, $defRing, $pilot, $ctx['service_ok']) . '</td></tr>';
        }
    }
    if ($st['rows'] === []) {
        $out .= '<tr><td colspan="6" class="text-muted">No releases to show.</td></tr>';
    }
    $out .= '</tbody></table></div>';

    // devices on versions that are not in the list (older builds, or a different repository)
    $counts = rivetAgentFeedDeviceCounts($mysqli);
    $others = [];
    foreach ($counts['by_version'] as $ver => $n) {
        if (!isset($listed[$ver])) {
            $others[] = $h($ver) . ' (' . (int) $n . ')';
        }
    }
    if ($counts['other'] > 0) {
        $others[] = 'unknown (' . (int) $counts['other'] . ')';
    }
    $out .= '<p class="small text-muted mb-3" id="af-others">' . (int) $counts['total'] . ' enrolled device' . ($counts['total'] === 1 ? '' : 's') . ($others ? '; on versions not in this list: ' . implode(', ', $others) : '') . '.</p>';

    // ---- where the releases come from
    $out .= '<details' . ($isDefaultRepo ? '' : ' open') . ' class="border rounded p-2" id="af-repo"><summary class="small fw-semibold">Where the releases come from</summary>'
        . '<form action="post.php" method="post" class="row g-2 align-items-end mt-1" autocomplete="off"><input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">'
        . '<div class="col-lg-5"><label class="form-label small mb-0" for="af-repo-in">GitHub repository (owner/name)</label><input class="form-control form-control-sm font-monospace" id="af-repo-in" name="repo" value="' . $h($feed['repo']) . '" maxlength="140" required pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,38}/[A-Za-z0-9][A-Za-z0-9._\-]{0,99}"></div>'
        . '<div class="col-lg-5"><div class="form-check"><input class="form-check-input" type="checkbox" id="af-repo-ok" name="confirm_repo" value="1"><label class="form-check-label small" for="af-repo-ok">I trust this repository. Whatever it publishes can be installed on every enrolled device.</label></div></div>'
        . '<div class="col-lg-2"><button class="btn btn-sm btn-outline-primary w-100" name="agent_feed" value="save_repo" type="submit">Save</button></div></form>'
        . '<div class="form-text">Default <code>' . $h(RIVET_AGENT_FEED_DEFAULT_REPO) . '</code>. Saving a different repository clears the saved list. Checksums come from the same repository, so they protect against a damaged download, not against a repository you should not trust.</div></details>';
    $out .= '</div></div>';

    return $out;
}

/**
 * The form of one release: ring, rollout and the two ticks. Plain POST without JavaScript.
 *
 * @param array<string,mixed> $r the release
 * @param array<string,mixed> $row the status row
 */
function rivetAgentFeedPublishForm(string $csrf, array $r, array $row, string $defRing, bool $pilot, bool $serviceOk): string
{
    $h = 'rivetAgentFeedH';
    $id = preg_replace('/[^A-Za-z0-9]/', '-', (string) $r['version']);
    $files = '';
    foreach ($row['plan'] as $p) {
        $files .= '<li>' . $h(rivetAgentFeedKindLabel($p['kind'], $p['arch'])) . ' <span class="text-muted">(' . $h($p['asset']) . ', ' . $h(\RivetCore\Rmm\Binaries\BinaryStore::human($p['size'])) . ')</span> '
            . ($p['store'] ? '<span class="badge text-bg-secondary">downloaded and stored</span>' : '<span class="badge text-bg-light border">linked, not copied</span>') . '</li>';
    }
    $o = '<form action="post.php" method="post" class="p-2" data-af-publish="1" data-tag="' . $h($r['tag']) . '" data-version="' . $h($r['version']) . '" autocomplete="off">'
        . '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="tag" value="' . $h($r['tag']) . '">'
        . '<div class="row g-3"><div class="col-lg-5"><div class="fw-semibold small mb-1">Files of ' . $h($r['tag']) . '</div><ul class="small mb-0 ps-3">' . $files . '</ul>'
        . ($r['notes'] !== '' ? '<details class="mt-2"><summary class="small">Release notes</summary><pre class="small mb-0 text-wrap" style="max-height:10rem;overflow:auto">' . $h($r['notes']) . '</pre></details>' : '') . '</div>'
        . '<div class="col-lg-7">'
        . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-act-' . $id . '" name="activate" value="1" checked><label class="form-check-label small" for="af-act-' . $id . '">Use for new installers (make current)</label></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-off-' . $id . '" name="offer" value="1"' . ($serviceOk ? ' checked' : ' disabled') . ' data-af-offer="1"><label class="form-check-label small" for="af-off-' . $id . '">Offer to enrolled agents as an update</label></div>'
        . '<div class="row g-2 align-items-end" data-af-offer-fields="1"><div class="col-sm-4"><label class="form-label small mb-0" for="af-ring-' . $id . '">Ring</label><select class="form-select form-select-sm" id="af-ring-' . $id . '" name="ring">'
        . '<option value="pilot"' . ($defRing === 'pilot' ? ' selected' : '') . '>Pilot ring' . ($pilot ? '' : ' (no pilot devices yet)') . '</option><option value="stable"' . ($defRing === 'stable' ? ' selected' : '') . '>Stable ring (everyone)</option></select></div>'
        . '<div class="col-sm-8"><label class="form-label small mb-0" for="af-pct-' . $id . '">Roll out to <output id="af-pctv-' . $id . '" data-af-pct-out="1">100</output>% of the ring</label>'
        . '<input type="range" class="form-range" id="af-pct-' . $id . '" name="rollout_pct" min="0" max="100" step="5" value="100" data-af-pct="1" aria-describedby="af-pcth-' . $id . '"></div></div>'
        . '<div class="form-text mb-2" id="af-pcth-' . $id . '">Devices only receive a version newer than the one they run, and a pilot-ring release goes to pilot devices only. ' . ($pilot ? 'The pilot ring is preselected because you have pilot devices.' : 'Choose Pilot to try it on a few PCs first (put a PC on the pilot ring in its settings).') . '</div>'
        . '<button type="submit" name="agent_feed" value="publish" class="btn btn-primary btn-sm" data-af-go="1"><i class="fas fa-upload me-1" aria-hidden="true"></i>Publish ' . $h($r['version']) . '</button> '
        . '<span class="small text-muted ms-2">Takes about a minute. Each file is downloaded, verified and stored in turn.</span>'
        . '<div class="mt-2 d-none" data-af-progress="1" aria-live="polite"><div class="progress mb-2" style="height:.6rem"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-af-bar="1"></div></div>'
        . '<ul class="list-unstyled small mb-0" data-af-steps="1"></ul></div></div></div></form>';

    return $o;
}
