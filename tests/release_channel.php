<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
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

// The Update page's "Release tag" must be an APP release tag, never another tag family on the same commit (the endpoint agent's
// agent-v0.1.0-beta.1 sat on the same commit as v26.10.26 and git describe showed it on a fully updated server).
$tagRepo = "$tmp/tags"; exec('git init -q -b main ' . escapeshellarg($tagRepo));
$commit($tagRepo, 'a.txt', 'a', 'A');
$ok(releaseDescribeTag($tagRepo, 'HEAD') === null, 'no tag at all gives null, so the page falls back to the commit hash');
$g($tagRepo, 'tag -a agent-v0.0.1 -m a');   // an agent tag first: still not an app release
$ok(releaseDescribeTag($tagRepo, 'HEAD') === null, 'a repository with only agent tags gives null');
$commit($tagRepo, 'a.txt', 'ab', 'B');
$g($tagRepo, 'tag -a v1.52.0 -m legacy'); sleep(1);
$g($tagRepo, 'tag -a agent-v0.1.0-beta.1 -m agent');   // newer annotated tag on the very same commit
$ok(trim(shell_exec('git -C ' . escapeshellarg($tagRepo) . ' describe --tags --abbrev=0 2>&1')) === 'agent-v0.1.0-beta.1', 'precondition: plain git describe really does pick the newer agent tag');
$ok(releaseDescribeTag($tagRepo, 'HEAD') === 'v1.52.0', 'the app release tag wins over a newer agent tag on the same commit (legacy v1.x style)');
$commit($tagRepo, 'a.txt', 'abc', 'C'); $g($tagRepo, 'tag -a v26.10.26 -m release'); sleep(1); $g($tagRepo, 'tag -a agent-v0.2.0 -m agent2');
$ok(releaseDescribeTag($tagRepo, 'HEAD') === 'v26.10.26', 'the vYY.MM.N style is matched too and a newer agent tag on the same commit is ignored');
$commit($tagRepo, 'a.txt', 'abcd', 'D');
$ok(releaseDescribeTag($tagRepo, 'HEAD') === 'v26.10.26', 'commits after the release still describe as the nearest app release tag');
$g($tagRepo, 'tag -a vnext -m not-a-version');
$ok(releaseDescribeTag($tagRepo, 'HEAD') === 'v26.10.26', 'a tag that is v plus a non-digit is not an app release tag');
$ok(releaseDescribeTag($tagRepo, 'no-such-ref') === null && releaseDescribeTag("$tmp/not-a-repo", 'HEAD') === null, 'a missing ref or directory gives null, never a warning');

// ---- releaseApplyUpdate(): fast-forward check, vendor autoloader pair, composer on every exit path (pentest IT-1) ----
$origin2 = "$tmp/origin2.git"; $work2 = "$tmp/work2"; $srv2 = "$tmp/server2";
exec("git init -q --bare -b $P " . escapeshellarg($origin2));
exec("git clone -q " . escapeshellarg($origin2) . " " . escapeshellarg($work2) . " 2>&1");
$g($work2, "checkout -q -b $P");
@mkdir("$work2/vendor/composer", 0777, true);
file_put_contents("$work2/vendor/autoload.php", "loader=GOOD\n");
file_put_contents("$work2/vendor/composer/autoload_real.php", "class=GOOD\n");
$commit($work2, 'a.txt', 'a', 'A');
$g($work2, "push -q origin $P");
exec("git clone -q -o $RM -b $P " . escapeshellarg($origin2) . " " . escapeshellarg($srv2) . " 2>&1");
$calls = 0;
$composer = function () use (&$calls) { $calls++; return ['ok' => true, 'message' => '']; };
$dirty = function () use ($srv2) {   // what a composer run does to the tracked generated files
    file_put_contents("$srv2/vendor/autoload.php", "loader=COMPOSER_RUN\n");
    file_put_contents("$srv2/vendor/composer/autoload_real.php", "class=COMPOSER_RUN\n");
};

// 1. normal fast-forward update with dirty generated files: pulled, BOTH files restored together, composer ran once
$commit($work2, 'b.txt', 'b', 'B'); $g($work2, "push -q origin $P");
$dirty();
$r = releaseApplyUpdate($srv2, 'production', false, $composer);
$ok($r['ok'] && $calls === 1 && is_file("$srv2/b.txt"), 'fast-forward update pulls the new commit and runs composer once');
$ok(file_get_contents("$srv2/vendor/autoload.php") === "loader=GOOD\n" && file_get_contents("$srv2/vendor/composer/autoload_real.php") === "class=GOOD\n", 'vendor/autoload.php is restored together with vendor/composer');

// 2. diverged checkout: refused BEFORE touching files, composer still runs, HEAD unchanged
$commit($srv2, 'local.txt', 'local', 'LOCAL');
$commit($work2, 'c.txt', 'c', 'C'); $g($work2, "push -q origin $P");
$head = $g($srv2, 'rev-parse HEAD')[1];
$dirty(); $calls = 0;
$r = releaseApplyUpdate($srv2, 'production', false, $composer);
$ok(!$r['ok'] && strpos($r['message'], 'fast-forward') !== false, 'a diverged checkout is refused with a fast-forward message');
$ok($g($srv2, 'rev-parse HEAD')[1] === $head && !is_file("$srv2/c.txt"), 'the refused update changed no commit and no file');
$ok($calls === 1, 'composer install still runs when the update is refused');
$ok(file_get_contents("$srv2/vendor/autoload.php") === "loader=COMPOSER_RUN\n", 'refusal leaves the generated files exactly as composer wrote them (consistent pair)');

// 3. git failure after the reset (an untracked file blocks the pull): reported, composer runs, vendor pair restored
$g($srv2, 'reset -q --hard HEAD~1');   // drop LOCAL: level with origin minus C
file_put_contents("$srv2/c.txt", "untracked blocker\n");
$dirty(); $calls = 0;
$r = releaseApplyUpdate($srv2, 'production', false, $composer);
$ok(!$r['ok'] && $r['message'] !== '' && $calls === 1, 'a failing git pull is reported and composer still runs');
$ok(file_get_contents("$srv2/vendor/autoload.php") === "loader=GOOD\n" && file_get_contents("$srv2/vendor/composer/autoload_real.php") === "class=GOOD\n", 'after a failed pull the vendor pair is consistent (both restored)');
@unlink("$srv2/c.txt");

// 4. a throwing post-update step is contained and reported
$r = releaseApplyUpdate($srv2, 'production', false, function () { throw new RuntimeException('boom'); });
$ok(!$r['post']['ok'] && strpos($r['post']['message'], 'boom') !== false, 'a throwing composer step is caught and reported');

// 5. force update discards the diverged commit
$commit($srv2, 'local2.txt', 'x', 'LOCAL2');
$r = releaseApplyUpdate($srv2, 'production', true, $composer);
$ok($r['ok'] && !is_file("$srv2/local2.txt") && is_file("$srv2/c.txt"), 'force update resets to the channel branch');

// 6. static: neither entry point resets only vendor/composer or pulls without --ff-only
$src_up = file_get_contents(__DIR__ . '/../admin/post/update.php') . file_get_contents(__DIR__ . '/../scripts/update_cli.php');
$ok(strpos($src_up, 'exec("git pull') === false && strpos($src_up, 'exec("git reset') === false && strpos($src_up, "checkout -- ':/vendor/composer'") === false, 'update entry points have no bare git pull and no vendor/composer-only reset');
$ok(substr_count($src_up, 'rivetit_composer_install(') === 2, 'both update entry points run composer through releaseApplyUpdate');
$ok(strpos(file_get_contents(__DIR__ . '/../includes/release_channel.php'), "':/vendor/autoload.php'") !== false, 'the generated-file reset includes vendor/autoload.php');

echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
