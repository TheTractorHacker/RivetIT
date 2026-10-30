<?php

/*
 * Update page diagnostics (Administration > Update > Checks). Everything here is local: file permissions, git configuration and
 * refs that were already fetched. It never contacts the remote (the page's own fetch does that and reports its output).
 *
 * Each check is ['id', 'title', 'status' => ok|warn|fail|info, 'detail' => what was found, 'fix' => plain-English next step ('' when fine)].
 * The usual shared setup: the web server user (www-data) runs git against a checkout owned by the person who administers the server,
 * so the checkout needs a shared group (git config core.sharedRepository group, g+w and setgid on the folders) and a safe.directory entry.
 */

require_once __DIR__ . '/release_channel.php';

/**
 * Run git in $dir with a time limit. Arguments are passed one per element and escaped here, never as a raw string.
 *
 * @param list<string> $args
 * @return array{0:int,1:list<string>}
 */
function updateCheckGit(string $dir, array $args): array
{
    $out = [];
    $code = 1;
    @exec('LC_ALL=C timeout 10 git -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);

    return [$code, $out];
}

function updateCheckWebUser(): string
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $name = $uid !== null && function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? '') : (string) get_current_user();

    return $name !== '' ? $name : 'the web server user';
}

/** @return list<array{id:string,title:string,status:string,detail:string,fix:string}> */
function updateChecks(string $dir, ?string $currentDbVersion = null, ?string $latestDbVersion = null): array
{
    $dir = rtrim($dir, '/');
    $user = updateCheckWebUser();
    $checks = [];
    $add = static function (string $id, string $title, string $status, string $detail, string $fix = '') use (&$checks): void {
        $checks[] = ['id' => $id, 'title' => $title, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
    };

    // 1. PHP may run programs, and git exists
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $execOff = in_array('exec', $disabled, true) || !function_exists('exec');
    if ($execOff) {
        $add('exec', 'PHP can run git', 'fail', 'The PHP function exec() is switched off, so the updater cannot run git at all.', 'Remove "exec" from disable_functions in the PHP configuration (php.ini or the php-hardening.ini file), then restart PHP-FPM.');

        return $checks;
    }
    [$code, $out] = updateCheckGit($dir, ['--version']);
    if ($code !== 0) {
        $add('git', 'Git is installed', 'fail', 'Git did not run as ' . $user . '.', 'Install git (for example: apt install git) and make sure it is in the web server\'s PATH.');

        return $checks;
    }
    $add('git', 'Git is installed', 'ok', trim((string) ($out[0] ?? 'git')) . ', run as ' . $user . '.');

    // 2. A git checkout the web user may use
    if (!is_dir($dir . '/.git') && !is_file($dir . '/.git')) {
        $add('repo', 'This folder is a git checkout', 'fail', 'There is no .git folder here, so there is nothing to update from.', (defined('APP_NAME') ? APP_NAME : 'This app') . ' updates itself from git. Install it with git clone, or update with the deploy script instead.');

        return $checks;
    }
    [$code, $out] = updateCheckGit($dir, ['rev-parse', '--is-inside-work-tree']);
    $text = trim(implode(' ', $out));
    if ($code !== 0 && stripos($text, 'dubious ownership') !== false) {
        $add('repo', 'This folder is a git checkout', 'fail', 'Git refuses to work here because the folder is owned by a different user than ' . $user . '.',
            'As root run: git config --system --add safe.directory ' . $dir . '   (and make the folder group-writable for the shared group, see docs/UPDATING.md).');
    } elseif ($code !== 0) {
        $add('repo', 'This folder is a git checkout', 'fail', 'Git could not read the repository: ' . substr($text, 0, 200), 'Check that ' . $user . ' can read the .git folder (ls -ld ' . $dir . '/.git).');
    } else {
        $add('repo', 'This folder is a git checkout', 'ok', 'Git can read the repository as ' . $user . '.');
    }

    // 3. .git writable by the web user (fetch writes FETCH_HEAD and objects; pull writes the index and refs)
    $git = $dir . '/.git';
    $unwritable = [];
    foreach (['', '/objects', '/refs', '/refs/heads', '/refs/remotes'] as $sub) {
        if (is_dir($git . $sub) && !is_writable($git . $sub)) {
            $unwritable[] = '.git' . $sub;
        }
    }
    foreach (['/index', '/HEAD', '/FETCH_HEAD'] as $file) {
        if (is_file($git . $file) && !is_writable($git . $file)) {
            $unwritable[] = '.git' . $file;
        }
    }
    if ($unwritable) {
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int) fileowner($git))['name'] ?? '?') : '?';
        $group = function_exists('posix_getgrgid') ? (posix_getgrgid((int) filegroup($git))['name'] ?? '?') : '?';
        $add('gitwrite', 'The web user can write to .git', 'fail', $user . ' cannot write to: ' . implode(', ', array_slice($unwritable, 0, 5)) . ' (owner ' . $owner . ', group ' . $group . '). Fetching and updating will fail.',
            'Give a shared group write access, as root: chgrp -R ' . $group . ' ' . $dir . '/.git && chmod -R g+rwX ' . $dir . '/.git && find ' . $dir . '/.git -type d -exec chmod g+s {} + && git -C ' . $dir . ' config core.sharedRepository group   (and add ' . $user . ' to that group).');
    } else {
        $add('gitwrite', 'The web user can write to .git', 'ok', $user . ' can write to the repository.');
    }

    // 4. The app folder is writable (git pull replaces files; the database-version files are rewritten by every release)
    $locked = [];
    foreach (['', '/includes', '/admin', '/vendor/composer', '/includes/database_version.php', '/admin/database_updates.php'] as $p) {
        if (file_exists($dir . $p) && !is_writable($dir . $p)) {
            $locked[] = ltrim($p, '/') ?: '(app folder)';
        }
    }
    if ($locked) {
        $add('treewrite', 'The web user can write to the app files', 'fail', $user . ' cannot write to: ' . implode(', ', $locked) . '. Update App would stop partway.',
            'Make the app files writable for the shared group, as root: chmod -R g+rwX ' . $dir . ' (the group must include ' . $user . ').');
    } else {
        $add('treewrite', 'The web user can write to the app files', 'ok', 'The app folder and the files a release replaces are writable.');
    }

    // 5. Remote configured (config only: no network call)
    $remote = RELEASE_REMOTE;
    [$code, $out] = updateCheckGit($dir, ['config', '--get', "remote.$remote.url"]);
    $url = $code === 0 ? trim((string) ($out[0] ?? '')) : '';
    if ($url === '') {
        $add('remote', 'The update source is set', 'fail', 'There is no git remote named "' . $remote . '", so there is nowhere to check for updates.', 'Add it: git -C ' . $dir . ' remote add ' . $remote . ' <repository url>');
    } else {
        $safeUrl = preg_replace('#//[^/@]+@#', '//***@', $url);
        $isSsh = (bool) preg_match('#^(ssh://|[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:)#', $url);
        $detail = 'Remote "' . $remote . '" is ' . $safeUrl . ' (not contacted by this check).';
        $status = 'ok';
        $fix = '';
        if ($isSsh) {
            $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
            $keys = $home !== '' ? (glob($home . '/.ssh/id_*') ?: []) : [];
            $readable = array_filter($keys, static fn($f) => !str_ends_with($f, '.pub') && is_readable($f));
            $hasConfig = $home !== '' && is_readable($home . '/.ssh/config');
            if (!$readable && !$hasConfig && !getenv('GIT_SSH_COMMAND')) {
                $status = 'warn';
                $detail .= ' It uses SSH, but ' . $user . ' has no readable SSH key' . ($home !== '' ? ' in ' . $home . '/.ssh' : '') . '.';
                $fix = 'Create a read-only deploy key for ' . $user . ' (ssh-keygen under ' . ($home ?: 'its home folder') . '/.ssh), add its public key to the repository as a deploy key, or switch the remote to an https URL.';
            }
        }
        $add('remote', 'The update source is set', $status, $detail, $fix);

        // 6. Has a fetch worked before? (local refs only)
        $channel = releaseChannelFromBranch(releaseCurrentBranch($dir));
        $ref = $remote . '/' . releaseChannelBranch($channel);
        [$code] = updateCheckGit($dir, ['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        $fetchHead = $git . '/FETCH_HEAD';
        $last = is_file($fetchHead) ? (int) filemtime($fetchHead) : 0;
        if ($code !== 0) {
            $add('fetched', 'Updates have been downloaded before', 'warn', 'The branch ' . $ref . ' has never been fetched on this server.', 'Open this page again after fixing any red item above; if it stays like this, check the remote address and that the server can reach it (firewall, DNS, SSH key).');
        } else {
            $add('fetched', 'Updates have been downloaded before', 'ok', $ref . ' is known' . ($last ? ', last fetch ' . date('Y-m-d H:i', $last) . '.' : '.'));
        }
    }

    // 7. Local changes that block a pull. Generated files (vendor/composer) are reset by Update App itself.
    [$code, $out] = updateCheckGit($dir, ['status', '--porcelain', '--untracked-files=no']);
    if ($code === 0) {
        $generated = [];
        $other = [];
        foreach ($out as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $path = trim(substr($line, 2));
            if (str_starts_with($path, 'vendor/composer')) {
                $generated[] = $path;
            } else {
                $other[] = $path;
            }
        }
        if ($generated) {
            // Composer rewrites these on EVERY update (the post-update `composer install`), and Update App restores them before it pulls, so
            // they differ after every update by design. That is a note, not a warning: nothing needs fixing.
            $add('composer', 'Composer files are clean', 'info', count($generated) . ' generated file(s) under vendor/composer differ from the release (for example ' . $generated[0] . '). Composer rewrites them after every update; this is normal.',
                'Nothing to do: Update App puts them back automatically before it pulls.');
        } else {
            $add('composer', 'Composer files are clean', 'ok', 'vendor/composer matches the release.');
        }
        if ($other) {
            $add('dirty', 'No hand-edited files', 'warn', count($other) . ' tracked file(s) were changed on this server (for example ' . $other[0] . '). A normal update may refuse to overwrite them.',
                'Keep the changes (commit or stash them), or use FORCE Update App, which discards them.');
        } else {
            $add('dirty', 'No hand-edited files', 'ok', 'No tracked file has been changed on this server.');
        }
    } else {
        $add('composer', 'Composer files are clean', 'warn', 'Could not read the working tree state: ' . substr(trim(implode(' ', $out)), 0, 160), 'Fix the repository problems above first.');
    }

    // 8. Database version against code version
    if ($currentDbVersion !== null && $latestDbVersion !== null) {
        $cmp = version_compare($latestDbVersion, $currentDbVersion);
        if ($cmp > 0) {
            $add('dbversion', 'Database matches the code', 'warn', 'The code expects database version ' . $latestDbVersion . ' but the database is at ' . $currentDbVersion . '.', 'Press Update Database at the top of this page (take a backup first).');
        } elseif ($cmp < 0) {
            $add('dbversion', 'Database matches the code', 'fail', 'The database (' . $currentDbVersion . ') is newer than this code (' . $latestDbVersion . '). The app may misbehave.', 'Do not run more updates. Bring the code forward to the release that matches the database, or restore a backup taken before the database was updated.');
        } else {
            $add('dbversion', 'Database matches the code', 'ok', 'Both are at ' . $currentDbVersion . '.');
        }
    }

    // 9. Composer: informational. The release ships its vendor/ folder, so the server does not need it to update.
    $composer = trim((string) @shell_exec('command -v composer 2>/dev/null'));
    if (!is_file($dir . '/vendor/autoload.php')) {
        $add('vendor', 'PHP libraries are present', 'fail', 'vendor/autoload.php is missing.', 'Run: composer install --no-dev (as the app owner) in ' . $dir . ', or restore vendor/ from the release.');
    } elseif ($composer === '') {
        $add('vendor', 'PHP libraries are present', 'info', 'The libraries are in place. Composer is not installed, which is fine: the release ships vendor/.');
    } else {
        $add('vendor', 'PHP libraries are present', 'ok', 'The libraries are in place and Composer is available (' . $composer . ').');
    }

    return $checks;
}
