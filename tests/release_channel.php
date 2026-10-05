<?php
/*
 * Release channel logic (includes/release_channel.php) against REAL throwaway git repositories: a bare "origin" with main and beta
 * branches and a server clone. No database, no network, nothing outside a temp directory.
 *   php tests/release_channel.php
 */
require_once __DIR__ . '/../includes/release_channel.php';

$P = RELEASE_BRANCH_PRODUCTION; $RM = RELEASE_REMOTE;   // this edition's production branch and updater remote
$tmp = sys_get_temp_dir() . '/release_channel_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
$g = function (string $dir, string $args) { exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c); return [$c, implode("\n", $o)]; };
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$commit = function (string $dir, string $file, string $text, string $msg) use ($g) { file_put_contents("$dir/$file", $text); $g($dir, 'add -A'); $g($dir, 'commit -q -m ' . escapeshellarg($msg)); };

// origin: main has A,B ; beta has A,B,C (beta is ahead of main - the normal state before a release)
$origin = "$tmp/origin.git"; $work = "$tmp/work"; $server = "$tmp/server";
exec("git init -q --bare -b $P " . escapeshellarg($origin));
exec("git clone -q " . escapeshellarg($origin) . " " . escapeshellarg($work) . " 2>&1");
$g($work, "checkout -q -b $P");
$commit($work, 'a.txt', 'a', 'A'); $commit($work, 'a.txt', 'ab', 'B');
$g($work, "push -q origin $P");
$g($work, 'checkout -q -b beta'); $commit($work, 'c.txt', 'c', 'C'); $g($work, 'push -q origin beta');

exec("git clone -q -o $RM -b $P " . escapeshellarg($origin) . " " . escapeshellarg($server) . " 2>&1");
$ok(releaseCurrentBranch($server) === $P, 'a server cloned from the production branch is on it');
$ok(releaseChannelFromBranch('main') === 'production' && releaseChannelFromBranch('master') === 'production' && releaseChannelFromBranch('beta') === 'beta', 'branch -> channel: beta is beta, everything else is production');
$ok(releaseChannelNormalize('bogus') === 'production' && releaseChannelNormalize('beta') === 'beta' && releaseChannelNormalize(null) === 'production', 'unknown channel values fall back to production');
$ok(releaseChannelBranch('production') === $P && releaseChannelBranch('beta') === 'beta', 'channels map to branches');

// Production server on production: nothing to switch.
$st = releaseChannelStatus($server, 'production');
$ok($st['same_branch'] && $st['can_switch'] && $st['ahead'] === 0 && $st['behind'] === 0, 'production server / production channel: same branch, level');
$r = releaseChannelEnsureBranch($server, 'production');
$ok($r['ok'] && !$r['switched'], 'ensure-branch is a no-op when already on the channel branch');

// Production server -> beta: beta is ahead (forward), allowed, and it switches.
$g($server, "fetch -q $RM"); // fetch is the caller's job
$st = releaseChannelStatus($server, 'beta');
$ok($st['ref_exists'] && !$st['same_branch'] && $st['ahead'] === 0 && $st['behind'] === 1 && $st['can_switch'], 'switching production -> beta is forward (beta is 1 change ahead) and allowed');
$r = releaseChannelEnsureBranch($server, 'beta');
$ok($r['ok'] && $r['switched'] && releaseCurrentBranch($server) === 'beta' && file_exists("$server/c.txt"), 'the server moves onto the beta branch and gets the beta code');

// Beta server -> production while beta has unreleased work: BLOCKED (would downgrade).
$st = releaseChannelStatus($server, 'production');
$ok(!$st['can_switch'] && $st['ahead'] === 1 && str_contains($st['reason'], 'older code'), 'switching beta -> production while beta is ahead is blocked, with a reason');
$r = releaseChannelEnsureBranch($server, 'production');
$ok(!$r['ok'] && releaseCurrentBranch($server) === 'beta' && file_exists("$server/c.txt"), 'and nothing changes on the server');

// Production catches up (release): now beta -> production is level, allowed.
$g($work, "checkout -q $P"); $g($work, 'merge -q --ff-only beta'); $g($work, "push -q origin $P");
$g($server, "fetch -q $RM");
$st = releaseChannelStatus($server, 'production');
$ok($st['can_switch'] && $st['ahead'] === 0, 'after production catches up, beta -> production is allowed');
$r = releaseChannelEnsureBranch($server, 'production');
$ok($r['ok'] && $r['switched'] && releaseCurrentBranch($server) === $P, 'and the server returns to the production branch');

// An existing local branch that is behind is brought level straight away (never left on older code).
$g($work, 'checkout -q beta'); $commit($work, 'd.txt', 'd', 'D'); $g($work, 'push -q origin beta');
$g($work, "checkout -q $P"); $g($work, 'merge -q --ff-only beta'); $g($work, "push -q origin $P");
$g($server, "fetch -q $RM");
$r = releaseChannelEnsureBranch($server, 'beta');
$ok($r['ok'] && file_exists("$server/d.txt"), 'switching to a stale local beta branch fast-forwards it to the channel tip');

// Local uncommitted changes that would be overwritten stop the switch and leave the server as it was.
$g($server, "checkout -q $P"); $g($server, "merge -q --ff-only $RM/$P");
$g($work, 'checkout -q beta'); $commit($work, 'a.txt', 'beta-edit', 'E'); $g($work, 'push -q origin beta');
$g($server, "fetch -q $RM");
file_put_contents("$server/a.txt", 'hand edit');
$r = releaseChannelEnsureBranch($server, 'beta');
$ok(!$r['ok'] && releaseCurrentBranch($server) === $P && file_get_contents("$server/a.txt") === 'hand edit', 'hand-edited files in the way refuse the switch and are left untouched');

// Missing channel branch (no beta releases yet).
exec("git -C " . escapeshellarg($origin) . " branch -D beta 2>&1");
$g($server, "fetch -q --prune $RM");
$st = releaseChannelStatus($server, 'beta');
$ok(!$st['ref_exists'] && !$st['can_switch'] && $st['reason'] !== '', 'a channel with no branch yet reports it clearly');
$ok(!releaseChannelEnsureBranch($server, 'beta')['ok'], 'and cannot be switched to');

echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
