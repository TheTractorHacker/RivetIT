<?php

/*
 * Build a new agent version from the Agent updates card (RivetIT 26.10.55, DB 2.6.176).
 *
 * The card used to fetch FINISHED releases. This file lets an administrator START the build: it asks GitHub to run the workflow `endpoint-agent.yml` of the configured repository
 * (REST: POST /repos/{owner}/{repo}/actions/workflows/endpoint-agent.yml/dispatches {ref:"main", inputs:{version}}), follows the run (runs API) and, when the release agent-v<version>
 * appears, hands it to the same feed / publish code as the rest of the card. Optionally the release is published automatically (ring + rollout chosen when the build was started).
 *
 * THE TOKEN (read before changing anything)
 *  - A fine-grained personal access token with only "Actions: read and write" on the one repository. It is stored in endpoint_agent_build.gh_token through encryptSetting() (the settings
 *    vault: v3 envelope, bound to the context below). It is shown to nobody: not in a page, a form value, a JSON answer, a flash message, an audit line or a log. The page only learns
 *    "a token is saved" and the text of the last test. Saving replaces it, "Remove" deletes it.
 *  - It is sent only to https://api.github.com, only on the first request of a call, and an answer that redirects such a call is refused (rivetAgentFeedGet()). The repository in every
 *    path is the one configured for the feed (matched against the owner/name pattern); the URL policy of rmm_agent_feed.php (host, https, port 443, resolved address) applies.
 *  - Changing the repository removes the token (it was made for the old one).
 *
 * WHAT THIS FILE NEVER DECIDES: who may publish. The final publish still goes through RmmAdmin (rmm.binary.publish) as the user who started the build.
 */

require_once __DIR__ . '/rmm_agent_feed.php';

const RIVET_AGENT_BUILD_WORKFLOW = 'endpoint-agent.yml';
const RIVET_AGENT_BUILD_REF = 'main';
const RIVET_AGENT_BUILD_CONTEXT = 'agent_build.github_token';
const RIVET_AGENT_BUILD_VERSION_RE = '/^(\d{1,5})\.(\d{1,5})\.(\d{1,5})(?:-([0-9A-Za-z][0-9A-Za-z.-]{0,19}))?\z/';
const RIVET_AGENT_BUILD_TOKEN_RE = '/^(?:github_pat_[A-Za-z0-9_]{20,255}|gh[pousr]_[A-Za-z0-9]{20,255})\z/';
const RIVET_AGENT_BUILD_POLL_MIN_S = 8;        // never ask GitHub about the same build more often than this
const RIVET_AGENT_BUILD_START_WAIT_S = 900;    // no run appeared this long after the dispatch: failed
const RIVET_AGENT_BUILD_MAX_S = 10800;         // a build still running after three hours is shown as failed (the workflow takes minutes)
const RIVET_AGENT_BUILD_RELEASE_WAIT_S = 1800; // a finished run whose release is not listed after this long: auto-publish gives up

function rivetAgentBuildReady($mysqli = null): bool
{
    static $memo = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    $key = $mysqli instanceof \mysqli ? spl_object_id($mysqli) : 0;
    if (!isset($memo[$key]) || $memo[$key] === false) {
        $ok = false;
        if ($mysqli instanceof \mysqli) {
            $a = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_build'");
            $ok = $a && mysqli_num_rows($a) > 0 && rivetAgentFeedReady($mysqli);
        }
        $memo[$key] = $ok;
    }

    return $memo[$key];
}

/** @return array<string,mixed> the single row (defaults when the table is missing) */
function rivetAgentBuildRow($mysqli): array
{
    $row = null;
    if (rivetAgentBuildReady($mysqli)) {
        $r = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_build WHERE id = 1');
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if (!$row) {
            mysqli_query($mysqli, 'INSERT IGNORE INTO endpoint_agent_build (id) VALUES (1)');
            $r = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_build WHERE id = 1');
            $row = $r ? mysqli_fetch_assoc($r) : null;
        }
    }

    return is_array($row) ? $row : ['gh_token' => '', 'token_set_at' => null, 'token_status' => '', 'build_version' => '', 'build_repo' => '', 'requested_at' => null, 'requested_by' => 0, 'run_id' => 0,
        'run_state' => '', 'run_note' => '', 'checked_at' => null, 'finished_at' => null, 'auto_publish' => 0, 'auto_opts' => '', 'auto_state' => '', 'auto_note' => ''];
}

function rivetAgentBuildHasToken($mysqli): bool
{
    return (string) rivetAgentBuildRow($mysqli)['gh_token'] !== '';
}

/** The token in clear text, for the one HTTP call that needs it. Never pass this to output. */
function rivetAgentBuildToken($mysqli): string
{
    $stored = (string) rivetAgentBuildRow($mysqli)['gh_token'];

    return $stored === '' ? '' : decryptSetting($stored, RIVET_AGENT_BUILD_CONTEXT);
}

/** @return string|null error text */
function rivetAgentBuildSaveToken($mysqli, #[\SensitiveParameter] string $token, int $userId): ?string
{
    if (!rivetAgentBuildReady($mysqli)) {
        return 'Run the database update first (Administration > Updates).';
    }
    $token = trim($token);
    if (preg_match(RIVET_AGENT_BUILD_TOKEN_RE, $token) !== 1) {
        return 'That does not look like a GitHub token. A fine-grained token starts with github_pat_ and has no spaces.';
    }
    try {
        $enc = encryptSetting($token, RIVET_AGENT_BUILD_CONTEXT);
    } catch (\Throwable $e) {
        return 'The token could not be stored encrypted on this server, so it was not saved.';
    }
    if ($enc === '' || str_contains($enc, $token)) {
        return 'The token could not be stored encrypted on this server, so it was not saved.';
    }
    rivetAgentBuildRow($mysqli);
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET gh_token = '" . mysqli_real_escape_string($mysqli, $enc) . "', token_set_at = UTC_TIMESTAMP(), token_set_by = " . (int) $userId . ", token_status = '' WHERE id = 1");

    return mysqli_errno($mysqli) === 0 ? null : 'The token could not be saved.';
}

function rivetAgentBuildClearToken($mysqli): void
{
    if (rivetAgentBuildReady($mysqli)) {
        mysqli_query($mysqli, "UPDATE endpoint_agent_build SET gh_token = '', token_set_at = NULL, token_set_by = 0, token_status = '' WHERE id = 1");
    }
}

/** Text for an HTTP failure of an authenticated call; never contains the token or GitHub's own words. */
function rivetAgentBuildExplain(array $res, string $what): string
{
    $s = (int) $res['status'];
    if ($res['offline']) {
        return 'This server could not reach GitHub (' . $res['error'] . ')';
    }
    if ($s === 401) {
        return 'GitHub does not accept the token (mistyped, expired or removed). Create a new one and save it again.';
    }
    if ($s === 403) {
        return 'GitHub refused ' . $what . ': the token has no permission for it (it needs "Actions: read and write" on this repository), or the rate limit is used up.';
    }
    if ($s === 404) {
        return 'GitHub cannot find ' . $what . ': the token is not allowed to see this repository, or the repository has no workflow ' . RIVET_AGENT_BUILD_WORKFLOW . '.';
    }
    if ($s === 422) {
        return 'GitHub did not accept the request (HTTP 422): the workflow may not accept this version number or may not exist on the main branch.';
    }

    return $res['error'] !== '' ? $res['error'] : 'GitHub answered HTTP ' . $s . '.';
}

/** @return array{ok:bool,message:string} */
function rivetAgentBuildTestToken($mysqli): array
{
    $token = rivetAgentBuildToken($mysqli);
    if ($token === '') {
        return ['ok' => false, 'message' => 'No token is saved.'];
    }
    $repo = rivetAgentFeedRepo($mysqli);
    $res = rivetAgentFeedGet(rivetAgentFeedApiBase() . '/repos/' . $repo . '/actions/workflows/' . RIVET_AGENT_BUILD_WORKFLOW, ['role' => 'api', 'token' => $token, 'max_bytes' => 262144, 'timeout' => 20]);
    if (!$res['ok']) {
        $msg = rivetAgentBuildExplain($res, 'the build workflow of ' . $repo);
        $ok = false;
    } else {
        $j = json_decode((string) $res['body'], true);
        $state = is_array($j) && is_string($j['state'] ?? null) ? $j['state'] : '';
        if ($state !== '' && $state !== 'active') {
            $msg = 'The token works, but the workflow ' . RIVET_AGENT_BUILD_WORKFLOW . ' of ' . $repo . ' is switched off on GitHub (Actions tab, enable it).';
            $ok = false;
        } else {
            $msg = 'The token works: it can see the build workflow of ' . $repo . '. (Whether it may start a build is proven when you press Build.)';
            $ok = true;
        }
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET token_status = '" . mysqli_real_escape_string($mysqli, ($ok ? 'ok: ' : 'failed: ') . gmdate('Y-m-d H:i') . ' UTC. ' . mb_substr($msg, 0, 230)) . "' WHERE id = 1");

    return ['ok' => $ok, 'message' => $msg];
}

// ------------------------------------------------------------------ versions

/** @return array{0:int,1:int,2:int,3:string}|null */
function rivetAgentBuildParse(string $v): ?array
{
    return preg_match(RIVET_AGENT_BUILD_VERSION_RE, $v, $m) === 1 ? [(int) $m[1], (int) $m[2], (int) $m[3], (string) ($m[4] ?? '')] : null;
}

/** The highest version among the listed releases (and a pending build), or null. */
function rivetAgentBuildLatest(array $releases, string $pending = ''): ?string
{
    $best = null;
    $all = array_map(static fn (array $r): string => (string) $r['version'], $releases);
    if ($pending !== '' && rivetAgentBuildParse($pending) !== null) {
        $all[] = $pending;
    }
    foreach ($all as $v) {
        if ($best === null || rivetAgentFeedVersionCmp($v, $best) > 0) {
            $best = $v;
        }
    }

    return $best;
}

/** The version the box is filled with: the latest known one with the patch number plus one (a suffix is dropped). */
function rivetAgentBuildNextVersion(array $releases): string
{
    $latest = rivetAgentBuildLatest($releases);
    $p = $latest === null ? null : rivetAgentBuildParse($latest);
    if ($p === null) {
        return '0.1.0';
    }

    return $p[0] . '.' . $p[1] . '.' . ($p[2] + 1);
}

/** @return string|null the reason the version cannot be built */
function rivetAgentBuildCheckVersion(string $v, array $releases): ?string
{
    if (rivetAgentBuildParse($v) === null) {
        return 'The version must look like 0.9.1 (three numbers), optionally with a suffix such as 0.9.1-rc1.';
    }
    foreach ($releases as $r) {
        if (rivetAgentFeedVersionCmp($v, (string) $r['version']) === 0) {
            return 'Version ' . $v . ' already exists on GitHub (agent-v' . $v . '). Choose a higher number.';
        }
    }
    $latest = rivetAgentBuildLatest($releases);
    if ($latest !== null && rivetAgentFeedVersionCmp($v, $latest) < 0) {
        return 'Version ' . $v . ' is lower than the latest version ' . $latest . '. Agents only update to a higher number.';
    }

    return null;
}

// ------------------------------------------------------------------ the build in progress

function rivetAgentBuildIsActive(array $row): bool
{
    if (!in_array($row['run_state'], ['dispatching', 'queued', 'building'], true) || !is_string($row['requested_at'] ?? null)) {
        return false;
    }

    return strtotime($row['requested_at'] . ' UTC') > time() - RIVET_AGENT_BUILD_MAX_S;
}

/** Does a finished build still wait for its automatic publish (or for the release to show up)? */
function rivetAgentBuildAutoPending(array $row): bool
{
    return (int) $row['auto_publish'] === 1 && $row['run_state'] === 'finished' && $row['auto_state'] === 'pending';
}

/**
 * Start a build.
 *
 * @param array{ring:?string,pct:int,activate:bool,offer:bool}|null $autoOpt publish automatically when built, with these options (null: do not)
 * @return array{ok:bool,message:string}
 */
function rivetAgentBuildStart($mysqli, string $version, ?array $autoOpt, int $userId): array
{
    if (!rivetAgentBuildReady($mysqli)) {
        return ['ok' => false, 'message' => 'Run the database update first (Administration > Updates).'];
    }
    $token = rivetAgentBuildToken($mysqli);
    if ($token === '') {
        return ['ok' => false, 'message' => 'No GitHub token is saved. Save one first, or start the build on GitHub yourself.'];
    }
    $version = trim($version);
    // the list is read again so a release made a minute ago is known
    rivetAgentFeedRefresh($mysqli, true);
    $feed = rivetAgentFeedCached($mysqli);
    $err = rivetAgentBuildCheckVersion($version, $feed['releases']);
    if ($err !== null) {
        return ['ok' => false, 'message' => $err];
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $repo = $feed['repo'];
    // one build at a time: claim the row atomically (a claim older than the maximum build time is free again)
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_state = 'dispatching', build_version = '" . $e($version) . "', build_repo = '" . $e($repo) . "', requested_at = UTC_TIMESTAMP(), requested_by = " . (int) $userId
        . ", run_id = 0, run_note = '', checked_at = NULL, finished_at = NULL, auto_publish = " . ($autoOpt !== null ? 1 : 0) . ", auto_opts = '" . $e($autoOpt !== null ? (string) json_encode($autoOpt) : '')
        . "', auto_state = '" . ($autoOpt !== null ? 'pending' : '') . "', auto_note = '' WHERE id = 1 AND (run_state NOT IN ('dispatching','queued','building') OR requested_at IS NULL OR requested_at < UTC_TIMESTAMP() - INTERVAL " . RIVET_AGENT_BUILD_MAX_S . ' SECOND)');
    if (mysqli_affected_rows($mysqli) !== 1) {
        return ['ok' => false, 'message' => 'A build is already running. Wait for it to finish.'];
    }
    $res = rivetAgentFeedGet(rivetAgentFeedApiBase() . '/repos/' . $repo . '/actions/workflows/' . RIVET_AGENT_BUILD_WORKFLOW . '/dispatches',
        ['role' => 'api', 'method' => 'POST', 'token' => $token, 'json' => ['ref' => RIVET_AGENT_BUILD_REF, 'inputs' => ['version' => $version]], 'max_bytes' => 65536, 'timeout' => 20]);
    if (!$res['ok'] || $res['status'] !== 204) {
        $msg = rivetAgentBuildExplain($res, 'the build in ' . $repo);
        mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_state = 'failed', run_note = '" . $e(mb_substr($msg, 0, 290)) . "', finished_at = UTC_TIMESTAMP(), auto_state = '' WHERE id = 1");

        return ['ok' => false, 'message' => $msg];
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_state = 'queued', run_note = 'GitHub accepted the request. Waiting for the run to start.' WHERE id = 1");

    return ['ok' => true, 'message' => 'GitHub accepted the request: version ' . $version . ' is being built. This usually takes a few minutes.'];
}

/**
 * Ask GitHub how the build is doing and record it. Throttled; does nothing when no build is active.
 *
 * @return array{changed:bool,state:string}
 */
function rivetAgentBuildPoll($mysqli, bool $force = false): array
{
    $row = rivetAgentBuildRow($mysqli);
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $reqAge = is_string($row['requested_at'] ?? null) ? time() - (int) strtotime($row['requested_at'] . ' UTC') : 0;
    $stale = in_array($row['run_state'], ['queued', 'building'], true) && !rivetAgentBuildIsActive($row);
    if ($row['run_state'] === 'dispatching' && $reqAge > 120 || $stale) {   // a request that never finished, or a build never seen to finish: stop showing it as running
        mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_state = 'failed', run_note = '" . ($stale ? 'The build was not seen to finish within three hours. Check the run on GitHub.' : 'The request to GitHub did not finish.') . "', finished_at = UTC_TIMESTAMP(), auto_state = '' WHERE id = 1");

        return ['changed' => true, 'state' => 'failed'];
    }
    if (!in_array($row['run_state'], ['queued', 'building'], true)) {
        return ['changed' => false, 'state' => (string) $row['run_state']];
    }
    $last = is_string($row['checked_at'] ?? null) ? (int) strtotime($row['checked_at'] . ' UTC') : 0;
    if (!$force && $last > time() - RIVET_AGENT_BUILD_POLL_MIN_S) {
        return ['changed' => false, 'state' => (string) $row['run_state']];
    }
    mysqli_query($mysqli, 'UPDATE endpoint_agent_build SET checked_at = UTC_TIMESTAMP() WHERE id = 1');
    $repo = preg_match(RIVET_AGENT_FEED_REPO_RE, (string) $row['build_repo']) === 1 ? (string) $row['build_repo'] : rivetAgentFeedRepo($mysqli);
    $token = rivetAgentBuildToken($mysqli);
    $opt = ['role' => 'api', 'token' => $token, 'max_bytes' => 1048576, 'timeout' => 20];
    $run = null;
    $runId = (int) $row['run_id'];
    if ($runId > 0) {
        $res = rivetAgentFeedGet(rivetAgentFeedApiBase() . '/repos/' . $repo . '/actions/runs/' . $runId, $opt);
        $j = $res['ok'] ? json_decode((string) $res['body'], true) : null;
        $run = is_array($j) && (int) ($j['id'] ?? 0) === $runId ? $j : null;
    } else {
        $res = rivetAgentFeedGet(rivetAgentFeedApiBase() . '/repos/' . $repo . '/actions/workflows/' . RIVET_AGENT_BUILD_WORKFLOW . '/runs?event=workflow_dispatch&branch=' . RIVET_AGENT_BUILD_REF . '&per_page=10', $opt);
        $j = $res['ok'] ? json_decode((string) $res['body'], true) : null;
        $since = (int) strtotime($row['requested_at'] . ' UTC') - 60;
        $best = null;
        foreach (is_array($j) && is_array($j['workflow_runs'] ?? null) ? array_slice($j['workflow_runs'], 0, 30) : [] as $r) {
            $t = is_array($r) && is_string($r['created_at'] ?? null) ? (int) strtotime($r['created_at']) : 0;
            if ($t >= $since && is_int($r['id'] ?? null) && $r['id'] > 0 && ($best === null || $t < $best[0])) {
                $best = [$t, $r];   // only one build runs at a time, so the OLDEST run since the request is ours
            }
        }
        $run = $best !== null ? $best[1] : null;
    }
    if (!$res['ok']) {   // transient trouble: keep the state, say so
        $note = rivetAgentBuildExplain($res, 'the run');
        mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_note = '" . $e(mb_substr($note . ' Trying again.', 0, 290)) . "' WHERE id = 1");

        return ['changed' => false, 'state' => (string) $row['run_state']];
    }
    if ($run === null) {
        if (time() - strtotime($row['requested_at'] . ' UTC') > RIVET_AGENT_BUILD_START_WAIT_S) {
            mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_state = 'failed', run_note = 'GitHub did not start a run. Open the Actions tab of the repository to see why.', finished_at = UTC_TIMESTAMP(), auto_state = '' WHERE id = 1");

            return ['changed' => true, 'state' => 'failed'];
        }

        return ['changed' => false, 'state' => (string) $row['run_state']];
    }
    $status = is_string($run['status'] ?? null) ? $run['status'] : '';
    $concl = is_string($run['conclusion'] ?? null) ? preg_replace('/[^a-z_]/', '', $run['conclusion']) : '';
    if ($status === 'completed') {
        $state = $concl === 'success' ? 'finished' : 'failed';
        $note = $state === 'finished' ? 'The build finished.' : 'The build did not succeed (' . ($concl !== '' ? str_replace('_', ' ', $concl) : 'unknown reason') . '). Open the run on GitHub to see why.';
    } elseif ($status === 'in_progress') {
        $state = 'building';
        $note = 'GitHub is building.';
    } else {
        $state = 'queued';
        $note = 'Waiting in GitHub\'s queue.';
    }
    $changed = $state !== $row['run_state'] || (int) $run['id'] !== $runId;
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET run_id = " . (int) $run['id'] . ", run_state = '$state', run_note = '" . $e($note) . "'"
        . ($state === 'finished' || $state === 'failed' ? ', finished_at = UTC_TIMESTAMP()' : '') . ($state === 'failed' ? ", auto_state = ''" : '') . ' WHERE id = 1');

    return ['changed' => $changed, 'state' => $state];
}

/** The link to the run, built here from the repository and the number (never taken from GitHub's answer). */
function rivetAgentBuildRunUrl(array $row): string
{
    return (int) $row['run_id'] > 0 && preg_match(RIVET_AGENT_FEED_REPO_RE, (string) $row['build_repo']) === 1
        ? 'https://github.com/' . $row['build_repo'] . '/actions/runs/' . (int) $row['run_id'] : '';
}

/**
 * Publish the finished build automatically, once, if it was asked for. Safe to call from the card's poll and from cron at the same time: the row is claimed first.
 *
 * @return array{done:bool,message:string}
 */
function rivetAgentBuildAutoPublish($mysqli, \RivetCore\Rmm\RmmModule $rmm): array
{
    $row = rivetAgentBuildRow($mysqli);
    if (!rivetAgentBuildAutoPending($row)) {
        return ['done' => false, 'message' => ''];
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $tag = 'agent-v' . $row['build_version'];
    $rel = rivetAgentFeedFindRelease($mysqli, $tag);
    if ($rel === null) {
        rivetAgentFeedRefresh($mysqli, true);
        $rel = rivetAgentFeedFindRelease($mysqli, $tag);
    }
    if ($rel === null) {
        $age = time() - (int) strtotime(($row['finished_at'] ?? $row['requested_at']) . ' UTC');
        if ($age > RIVET_AGENT_BUILD_RELEASE_WAIT_S) {
            mysqli_query($mysqli, "UPDATE endpoint_agent_build SET auto_state = 'failed', auto_note = '" . $e('The release ' . $tag . ' did not appear on GitHub, so it was not published. Publish it by hand once it is there.') . "' WHERE id = 1 AND auto_state = 'pending'");
        }

        return ['done' => false, 'message' => 'Waiting for the release ' . $tag . ' to appear on GitHub.'];
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET auto_state = 'running' WHERE id = 1 AND auto_state = 'pending'");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return ['done' => false, 'message' => 'Another request is publishing it.'];
    }
    $o = json_decode((string) $row['auto_opts'], true);
    $opt = rivetAgentFeedOptions(is_array($o) ? ['ring' => $o['ring'] ?? 'stable', 'rollout_pct' => $o['pct'] ?? 100, 'activate' => !empty($o['activate']), 'offer' => !empty($o['offer'])] : []);
    $uid = (int) $row['requested_by'];
    $name = '';
    $u = $uid > 0 ? mysqli_query($mysqli, 'SELECT user_name FROM users WHERE user_id = ' . $uid . ' AND user_archived_at IS NULL LIMIT 1') : false;
    $ur = $u ? mysqli_fetch_row($u) : null;
    $name = $ur ? (string) $ur[0] : '';
    if ($name === '') {
        mysqli_query($mysqli, "UPDATE endpoint_agent_build SET auto_state = 'failed', auto_note = 'The user who started the build no longer exists, so nothing was published.' WHERE id = 1");

        return ['done' => true, 'message' => 'The user who started the build no longer exists.'];
    }
    $r = rivetAgentFeedPublishAll($mysqli, $rmm, rivetRmmPrincipal($uid, $name), $tag, $opt);
    $note = mb_substr(($r['ok'] ? 'Published automatically. ' : 'Automatic publish did not finish. ') . $r['message'], 0, 290);
    mysqli_query($mysqli, "UPDATE endpoint_agent_build SET auto_state = '" . ($r['ok'] ? 'done' : 'failed') . "', auto_note = '" . $e($note) . "' WHERE id = 1");
    logAction('Endpoint Agent', 'Agent Build Auto Publish', "$name's build $tag was published automatically: " . ($r['ok'] ? 'ok' : 'not done'));

    return ['done' => true, 'message' => $note];
}

/**
 * One full look: poll the run; when it finished, read the release list and publish if asked. Used by the card's poll and by cron/agent_build_check.php.
 *
 * @return array{changed:bool,state:string,message:string}
 */
function rivetAgentBuildTick($mysqli, \RivetCore\Rmm\RmmModule $rmm, bool $force = false): array
{
    $p = rivetAgentBuildPoll($mysqli, $force);
    $msg = '';
    $row = rivetAgentBuildRow($mysqli);
    if ($row['run_state'] === 'finished' && $row['finished_at'] !== null) {
        if ($p['changed']) {
            rivetAgentFeedRefresh($mysqli, true);   // the new release shows up in the list
        }
        if (rivetAgentBuildAutoPending($row)) {
            $a = rivetAgentBuildAutoPublish($mysqli, $rmm);
            $msg = $a['message'];
            $p['changed'] = $p['changed'] || $a['done'];
        }
    }

    return ['changed' => $p['changed'], 'state' => $p['state'], 'message' => $msg];
}
