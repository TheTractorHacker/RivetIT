<?php

/*
 * The "Build a new version" box of the Agent updates card (RivetIT 26.10.55). Markup only; the rules are in includes/rmm_agent_build.php, the handler in
 * admin/post/settings_endpoint_agent.php, the script in js/rmm_agent_feed.js. Without JavaScript every button is an ordinary form post.
 * The token is never printed: the only form field that can hold one is empty and is never given a value.
 */

require_once __DIR__ . '/rmm_agent_build.php';
require_once __DIR__ . '/rmm_agent_feed_ui.php';
require_once __DIR__ . '/rmm_build_host_ui.php';   // 26.10.57: Build on my build host

/** @return array{label:string,class:string} */
function rivetAgentBuildBadge(string $state): array
{
    $map = ['dispatching' => ['Starting', 'text-bg-info'], 'queued' => ['Waiting in GitHub\'s queue', 'text-bg-info'], 'building' => ['Building', 'text-bg-primary'],
        'finished' => ['Built', 'text-bg-success'], 'failed' => ['Failed', 'text-bg-danger']];
    $m = $map[$state] ?? ['', 'text-bg-secondary'];

    return ['label' => $m[0], 'class' => $m[1]];
}

/**
 * @param list<array<string,mixed>> $releases the listed releases
 * @param array{csrf:string,module_on:bool,service_ok:bool} $ctx
 */
function rivetAgentBuildBox($mysqli, array $releases, string $repo, bool $pilot, array $ctx): string
{
    $h = 'rivetAgentFeedH';
    if (!rivetAgentBuildReady($mysqli)) {
        return '<div class="alert alert-warning py-2 small" id="af-build">The &ldquo;Build a new version&rdquo; box needs one more small table: run the database update (Administration &rarr; Updates), then reload this page.</div>';
    }
    $row = rivetAgentBuildRow($mysqli);
    $has = (string) $row['gh_token'] !== '';
    $active = rivetAgentBuildIsActive($row);
    $autoWait = rivetAgentBuildAutoPending($row);
    $next = rivetAgentBuildNextVersion($releases);
    $csrf = '<input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">';
    $actionsUrl = 'https://github.com/' . $repo . '/actions/workflows/' . RIVET_AGENT_BUILD_WORKFLOW;
    $b = rivetAgentBuildBadge((string) $row['run_state']);
    $runUrl = rivetAgentBuildRunUrl($row);

    $hostsKnown = rivetBuildHostReady($mysqli) && rivetBuildHostList($mysqli) !== [];
    $localFirst = $hostsKnown && !$has;
    $o = '<div class="border rounded p-3 mb-3" id="af-build" data-active="' . ($active || $autoWait ? '1' : '0') . '" data-provider="' . ($localFirst ? 'host' : 'github') . '"><h5 class="mb-1"><i class="fas fa-fw fa-hammer me-1" aria-hidden="true"></i>Build a new version</h5>'
        . '<fieldset class="mb-2" id="af-provider"><legend class="visually-hidden">Where to build</legend>'
        . '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="af_provider" id="af-prov-gh" value="github"' . ($localFirst ? '' : ' checked') . '><label class="form-check-label" for="af-prov-gh">Build on GitHub</label></div>'
        . '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="af_provider" id="af-prov-host" value="host"' . ($localFirst ? ' checked' : '') . '><label class="form-check-label" for="af-prov-host">Build on my build host</label></div></fieldset>'
        . '<div id="af-build-gh"' . ($localFirst ? ' class="d-none"' : '') . '>'
        . '<p class="small text-muted mb-2">Starts the agent build on GitHub (Windows and Linux agents and the MSI, from the <code>main</code> branch of <code>' . $h($repo) . '</code>) and publishes the new release as <code>agent-v&lt;version&gt;</code>. '
        . 'A version with a suffix (for example <code>0.9.1-rc1</code>) becomes a pre-release.</p>';

    // ---- what the last / current build is doing
    $o .= '<div id="af-build-state" class="small mb-2" role="status" aria-live="polite">';
    if ($row['run_state'] !== '') {
        $o .= 'Version <strong>' . $h($row['build_version']) . '</strong>: <span class="badge ' . $h($b['class']) . '" id="af-build-badge">' . $h($b['label']) . '</span> <span id="af-build-note">' . $h($row['run_note']) . '</span> '
            . '<a id="af-build-link" href="' . $h($runUrl !== '' ? $runUrl : '#') . '" target="_blank" rel="noopener noreferrer"' . ($runUrl === '' ? ' class="d-none"' : '') . '>See the run on GitHub</a>';
        if ($row['auto_state'] !== '') {
            $o .= '<div class="mt-1" id="af-build-auto">Publish automatically: ' . $h(['pending' => 'waiting for the build', 'running' => 'publishing now', 'done' => 'done', 'failed' => 'did not finish'][$row['auto_state']] ?? $row['auto_state'])
                . ($row['auto_note'] !== '' ? '. ' . $h($row['auto_note']) : '') . '</div>';
        }
        $gsteps = $row['run_state'] === 'building' ? rivetAgentBuildSteps($row) : [];
        if ($gsteps !== []) {   // the steps of the running GitHub job, as the same checklist the build host shows, with a percent estimate (finished steps out of all steps)
            $o .= '<div class="mt-1" id="af-build-steps" data-bh-progress="1"><div class="fw-semibold">GitHub is building</div>' . rivetBuildProgressBar(rivetAgentBuildStepsPct($gsteps), 'Build progress (estimate)', true) . rivetBuildChecklist($gsteps) . '</div>';
        }
    } else {
        $o .= '<span id="af-build-note" class="text-muted">No build was started from this page yet.</span>';
    }
    $o .= '</div>';

    if ($has) {
        $o .= '<form action="post.php" method="post" class="row g-2 align-items-end" data-af-act="1" autocomplete="off">' . $csrf
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="af-build-version">New version</label>'
            . '<input class="form-control form-control-sm font-monospace" id="af-build-version" name="version" value="' . $h($next) . '" maxlength="40" required pattern="\d{1,5}\.\d{1,5}\.\d{1,5}(-[0-9A-Za-z][0-9A-Za-z.\-]{0,19})?" aria-describedby="af-build-vh"></div>'
            . '<div class="col-sm-8 col-lg-9"><button class="btn btn-primary btn-sm" type="submit" name="agent_feed" value="build_start" id="af-build-go"' . ($active ? ' disabled' : '') . '><i class="fas fa-hammer me-1" aria-hidden="true"></i>Build</button> '
            . '<button class="btn btn-outline-secondary btn-sm" type="submit" name="agent_feed" value="build_status" id="af-build-refresh" formnovalidate><i class="fas fa-sync me-1" aria-hidden="true"></i>Refresh status</button></div>'
            . '<div class="col-12 form-text mt-1" id="af-build-vh">Filled with the next patch number after the latest known version' . ($releases === [] ? '' : ' (' . $h((string) rivetAgentBuildLatest($releases)) . ')') . '. Versions that exist already or are lower than the latest are refused.</div>'
            . '<div class="col-12"><details' . ($row['auto_publish'] ? ' open' : '') . '><summary class="small fw-semibold">Publish automatically when it is built (optional)</summary>'
            . '<div class="mt-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-build-auto-cb" name="build_auto" value="1"><label class="form-check-label small" for="af-build-auto-cb">Publish it automatically as soon as GitHub has built it, with the choices below. This server notices when the page is open and every five minutes through its scheduled job.</label></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-build-act" name="activate" value="1" checked><label class="form-check-label small" for="af-build-act">Use for new installers (make current)</label></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="af-build-off" name="offer" value="1"' . ($ctx['service_ok'] ? ' checked' : ' disabled') . ' data-af-offer="1"><label class="form-check-label small" for="af-build-off">Offer to enrolled agents as an update</label></div>'
            . '<div class="row g-2 align-items-end" data-af-offer-fields="1"><div class="col-sm-4"><label class="form-label small mb-0" for="af-build-ring">Ring</label><select class="form-select form-select-sm" id="af-build-ring" name="ring">'
            . '<option value="pilot"' . ($pilot ? ' selected' : '') . '>Pilot ring</option><option value="stable"' . ($pilot ? '' : ' selected') . '>Stable ring (everyone)</option></select></div>'
            . '<div class="col-sm-8"><label class="form-label small mb-0" for="af-build-pct">Roll out to <output data-af-pct-out="1">100</output>% of the ring</label><input type="range" class="form-range" id="af-build-pct" name="rollout_pct" min="0" max="100" step="5" value="100" data-af-pct="1"></div></div>'
            . '</div></details></div></form>';
    } else {
        $o .= '<div class="alert alert-secondary py-2 small mb-2"><div class="fw-semibold mb-1">No GitHub token is saved, so this server cannot start the build itself. Start it on GitHub (3 steps):</div>'
            . '<ol class="mb-2 ps-3"><li>Press <strong>Start the build on GitHub</strong> below, then press <strong>Run workflow</strong> (sign in to GitHub if it asks).</li>'
            . '<li>In the box that opens, type the version, for example <code>' . $h($next) . '</code>, and press the green <strong>Run workflow</strong> button.</li>'
            . '<li>Come back here when the run is green (a few minutes) and press <strong>Check for updates</strong>. The new release appears in the list with a Publish button.</li></ol>'
            . '<a class="btn btn-primary btn-sm" href="' . $h($actionsUrl) . '" target="_blank" rel="noopener noreferrer" id="af-build-fallback"><i class="fab fa-github me-1" aria-hidden="true"></i>Start the build on GitHub</a></div>';
    }

    // ---- the token
    $o .= '<details class="mt-2' . ($has ? '' : ' border rounded p-2') . '" id="af-token"' . ($has ? '' : '') . '><summary class="small fw-semibold">' . ($has ? 'GitHub token: saved' : 'Build from here with one click (GitHub token)') . '</summary><div class="mt-2">';
    if ($has) {
        $o .= '<p class="small mb-2">A token is saved, encrypted on this server' . ($row['token_set_at'] ? ' (' . $h(rivetAgentFeedWhen(str_replace(' ', 'T', (string) $row['token_set_at']) . 'Z')) . ')' : '') . '. It is never shown again.'
            . ($row['token_status'] !== '' ? ' <span id="af-token-status">Last test: ' . $h($row['token_status']) . '</span>' : '') . '</p>'
            . '<form action="post.php" method="post" class="d-inline" data-af-act="1">' . $csrf . '<button class="btn btn-sm btn-outline-primary" type="submit" name="agent_feed" value="build_test_token" id="af-token-test">Test token</button></form> '
            . '<form action="post.php" method="post" class="d-inline" data-af-act="1" data-af-confirm="Remove the saved GitHub token from this server? Builds can still be started on GitHub by hand.">' . $csrf
            . '<button class="btn btn-sm btn-outline-danger" type="submit" name="agent_feed" value="build_clear_token" id="af-token-clear">Remove token</button></form>';
    }
    $o .= '<div class="small mt-2"><div class="fw-semibold mb-1">How to create the token (about two minutes)</div><ol class="ps-3 mb-2">'
        . '<li>Sign in to GitHub with an account that may run workflows in <code>' . $h($repo) . '</code>, then open <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Settings &rarr; Developer settings &rarr; Fine-grained tokens &rarr; Generate new token</a>.</li>'
        . '<li>Name it <em>RivetIT agent build</em>, pick an expiry (90 days is fine; set a reminder), and under <em>Resource owner</em> choose the owner of <code>' . $h($repo) . '</code>.</li>'
        . '<li>Under <em>Repository access</em> choose <em>Only select repositories</em> and pick <code>' . $h($repo) . '</code> and nothing else.</li>'
        . '<li>Under <em>Permissions &rarr; Repository permissions</em> set <strong>Actions</strong> to <strong>Read and write</strong>. Leave everything else on &ldquo;No access&rdquo;.</li>'
        . '<li>Press <em>Generate token</em>, copy it (it starts with <code>github_pat_</code>), paste it below, press <strong>' . ($has ? 'Replace token' : 'Save token') . '</strong>, then <strong>Test token</strong>. If the repository belongs to an organization that approves tokens, an owner has to approve it first.</li></ol>'
        . '<form action="post.php" method="post" class="row g-2 align-items-end" data-af-act="1" autocomplete="off">' . $csrf
        . '<div class="col-lg-7"><label class="form-label small mb-0" for="af-token-in">' . ($has ? 'New token' : 'Token') . '</label><input class="form-control form-control-sm font-monospace" type="password" id="af-token-in" name="gh_token" value="" autocomplete="new-password" spellcheck="false" maxlength="300" required></div>'
        . '<div class="col-lg-5"><button class="btn btn-sm btn-outline-primary" type="submit" name="agent_feed" value="build_save_token" id="af-token-save">' . ($has ? 'Replace token' : 'Save token') . '</button></div></form>'
        . '<div class="form-text">Stored encrypted (settings vault), sent only to api.github.com, never displayed, and removed if you change the repository. Anyone who can use it can start builds of this one repository, so give it only the permission above.</div>'
        . '</div></div></details></div>';   // end of the token box and of the GitHub part

    $o .= '<div id="af-build-local-wrap"' . ($localFirst ? '' : ' class="d-none"') . '>' . rivetBuildHostBox($mysqli, $pilot, $ctx, $ctx['bh_reveal'] ?? rivetBuildHostTakeOnce()) . '</div></div>';

    return $o;
}
