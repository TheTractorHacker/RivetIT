<?php

/*
 * Release channel: which line of releases this server follows.
 *
 *   production  the tested, stable line.
 *   beta        early access: newer, changes more often, may carry unfinished work.
 *
 * Each channel is a branch of the project's git repository. The channel is a setting (Administration > Update), the update check and
 * the Update App button follow it, and a server only ever moves FORWARD along a branch: switching to a channel whose latest release is
 * older than the code already running is refused, because the database may already be newer than that code can read.
 */

if (!defined('RELEASE_REMOTE')) {
    define('RELEASE_REMOTE', defined('APP_UPDATE_REMOTE') ? (string) APP_UPDATE_REMOTE : 'origin');
}
if (!defined('RELEASE_BRANCH_PRODUCTION')) {
    define('RELEASE_BRANCH_PRODUCTION', 'main');
}
if (!defined('RELEASE_BRANCH_BETA')) {
    define('RELEASE_BRANCH_BETA', 'beta');
}

/** @return array<string, array{label:string, branch:string, summary:string}> */
function releaseChannels(): array
{
    return [
        'production' => ['label' => 'Production', 'branch' => RELEASE_BRANCH_PRODUCTION, 'summary' => 'Tested, stable releases. Recommended for a live system.'],
        'beta' => ['label' => 'Beta', 'branch' => RELEASE_BRANCH_BETA, 'summary' => 'Early access: new features first, changes more often, may include unfinished work. Use on a test or staging server.'],
    ];
}

function releaseChannelNormalize(?string $channel): string
{
    return isset(releaseChannels()[(string) $channel]) ? (string) $channel : 'production';
}

function releaseChannelBranch(string $channel): string
{
    return releaseChannels()[releaseChannelNormalize($channel)]['branch'];
}

/** The channel a checked-out branch belongs to (anything that is not the beta branch counts as production). */
function releaseChannelFromBranch(string $branch): string
{
    return $branch === RELEASE_BRANCH_BETA ? 'beta' : 'production';
}

/** Run a git command in $dir. @return array{0:int,1:list<string>} exit code and output lines */
function releaseGit(string $dir, string $args): array
{
    $out = [];
    $code = 1;
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);

    return [$code, $out];
}

function releaseCurrentBranch(string $dir): string
{
    [$code, $out] = releaseGit($dir, 'rev-parse --abbrev-ref HEAD');

    return $code === 0 ? trim((string) ($out[0] ?? '')) : '';
}

/** The configured channel, or (when none is stored yet) the one the checked-out branch belongs to. */
function releaseChannelConfigured($mysqli, string $dir): string
{
    $stored = null;
    if ($mysqli instanceof \mysqli) {
        // Before the database update that adds the column has run (the code is deployed first), the query fails; with PHP 8.1+ that
        // is an exception, not a false return, so it must be caught or the Update page - the page that runs the update - goes blank.
        try {
            $res = mysqli_query($mysqli, "SELECT config_release_channel FROM settings WHERE company_id = 1 LIMIT 1");
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $stored = $row['config_release_channel'] ?? null;
        } catch (\Throwable $e) {
            $stored = null;
        }
    }

    return $stored !== null && $stored !== '' ? releaseChannelNormalize($stored) : releaseChannelFromBranch(releaseCurrentBranch($dir));
}

/**
 * Where this checkout stands against a channel, from refs already fetched (it does not fetch).
 *
 * @return array{channel:string, branch:string, current_branch:string, ref:string, ref_exists:bool, same_branch:bool, behind:int, ahead:int, can_switch:bool, reason:string}
 */
function releaseChannelStatus(string $dir, string $channel): array
{
    $channel = releaseChannelNormalize($channel);
    $branch = releaseChannelBranch($channel);
    $ref = RELEASE_REMOTE . '/' . $branch;
    $current = releaseCurrentBranch($dir);
    $st = ['channel' => $channel, 'branch' => $branch, 'current_branch' => $current, 'ref' => $ref, 'ref_exists' => false,
        'same_branch' => $current === $branch, 'behind' => 0, 'ahead' => 0, 'can_switch' => false, 'reason' => ''];

    [$code] = releaseGit($dir, 'rev-parse --verify --quiet ' . escapeshellarg($ref . '^{commit}'));
    if ($code !== 0) {
        $st['reason'] = "The $ref branch is not available yet (the server could not reach the repository, or the channel has no releases).";

        return $st;
    }
    $st['ref_exists'] = true;
    [$code, $out] = releaseGit($dir, 'rev-list --left-right --count HEAD...' . escapeshellarg($ref));
    if ($code === 0 && preg_match('/^(\d+)\s+(\d+)/', (string) ($out[0] ?? ''), $m)) {
        $st['ahead'] = (int) $m[1];   // commits here that the channel does not have
        $st['behind'] = (int) $m[2];  // commits the channel has that this server does not
    }
    if ($st['same_branch'] || $st['ahead'] === 0) {
        $st['can_switch'] = true;
    } else {
        $st['reason'] = "This server is running {$st['ahead']} change(s) that the " . releaseChannels()[$channel]['label'] . ' channel does not have yet. Switching now would install older code on a newer database, so it is blocked until that channel catches up.';
    }

    return $st;
}

/**
 * Put the checkout on the channel's branch (it never discards work: a checkout that would overwrite local changes is refused by git).
 * The caller then updates the code (git pull) as usual.
 *
 * @return array{ok:bool, switched:bool, message:string}
 */
function releaseChannelEnsureBranch(string $dir, string $channel): array
{
    $st = releaseChannelStatus($dir, $channel);
    if ($st['same_branch']) {
        return ['ok' => true, 'switched' => false, 'message' => ''];
    }
    if (!$st['ref_exists'] || !$st['can_switch']) {
        return ['ok' => false, 'switched' => false, 'message' => $st['reason']];
    }
    $branch = escapeshellarg($st['branch']);
    [$exists] = releaseGit($dir, 'rev-parse --verify --quiet ' . escapeshellarg('refs/heads/' . $st['branch']));
    [$code, $out] = $exists === 0
        ? releaseGit($dir, 'checkout ' . $branch)
        : releaseGit($dir, 'checkout -b ' . $branch . ' --track ' . escapeshellarg($st['ref']));
    if ($code !== 0) {
        return ['ok' => false, 'switched' => false, 'message' => 'Could not switch to the ' . $st['branch'] . ' branch: ' . trim(implode(' ', array_slice(array_filter(array_map('trim', $out)), 0, 2)))];
    }
    // An existing local branch can be behind the channel: bring it level straight away so the server is never left on older code.
    if ($exists === 0) {
        [$code, $out] = releaseGit($dir, 'merge --ff-only ' . escapeshellarg($st['ref']));
        if ($code !== 0) {
            releaseGit($dir, 'checkout ' . escapeshellarg($st['current_branch']));

            return ['ok' => false, 'switched' => false, 'message' => 'The local ' . $st['branch'] . ' branch could not be brought up to date, so nothing was changed: ' . trim(implode(' ', array_slice(array_filter(array_map('trim', $out)), 0, 2)))];
        }
    }

    return ['ok' => true, 'switched' => true, 'message' => 'Switched to the ' . $st['branch'] . ' branch.'];
}
