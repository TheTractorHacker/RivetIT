<?php
/*
 * Installs the PHP dependencies after the app code was updated. The in-app Update runs `git pull`, and vendor/ only
 * carries part of the packages (RivetCore and others come from composer), so without this step an updated install
 * would be missing classes until someone ran `composer install` by hand (deploy/update.sh already does).
 *
 * Returns ['ok' => bool, 'skipped' => bool, 'message' => string]. Never throws; a failure is reported, not fatal.
 */
/**
 * A private directory for composer's HOME, one per user, with its ownership and mode verified every time.
 *
 * The name is predictable and lives in a shared temp directory, so another local user could create it first (or plant a
 * symlink) and have this process run composer with a HOME they control - composer reads config and plugins from there. So
 * the directory must belong to the current user, must not be a symlink, and must not be accessible to anyone else;
 * anything else is refused rather than used. The user id in the name also stops one user's directory (the CLI updater
 * runs as a different user) from blocking another's.
 *
 * @param string|null $base parent directory (defaults to the system temp directory); a parameter so it can be tested
 */
function rivetit_composer_home(?string $base = null): ?string
{
    $base = rtrim($base ?? sys_get_temp_dir(), '/');
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $home = $base . '/rivetit-composer-home-' . $uid;

    clearstatcache(true, $home);
    if (!file_exists($home) && !is_link($home)) {
        @mkdir($home, 0700); // fails if someone created it between the check and here; verified below either way
    }
    clearstatcache(true, $home);
    $st = @lstat($home); // lstat: never follow a symlink
    if ($st === false || is_link($home) || !is_dir($home) || $st['uid'] !== $uid || ($st['mode'] & 0077) !== 0) {
        return null;
    }

    return $home;
}

function rivetit_composer_install(string $root): array
{
    if (!is_file($root . '/composer.json')) {
        return ['ok' => true, 'skipped' => true, 'message' => ''];
    }
    $php = '';
    foreach (['/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
        if (is_executable($candidate)) { $php = $candidate; break; }
    }
    $composer = '';
    foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
        if (is_file($candidate)) { $composer = $candidate; break; }
    }
    if ($php === '' || $composer === '') {
        return ['ok' => false, 'skipped' => false, 'message' => 'composer was not found on this server'];
    }
    // The web server user usually has no home directory, so give composer a private, writable one.
    $home = rivetit_composer_home();
    if ($home === null) {
        return ['ok' => false, 'skipped' => false, 'message' => 'could not create a private composer home directory'];
    }
    @set_time_limit(0);
    $output = [];
    $code = 1;
    exec('cd ' . escapeshellarg($root) . ' && COMPOSER_HOME=' . escapeshellarg($home) . ' COMPOSER_NO_INTERACTION=1 '
        . escapeshellarg($php) . ' ' . escapeshellarg($composer) . ' install --no-dev --no-interaction 2>&1', $output, $code);
    if ($code === 0) {
        return ['ok' => true, 'skipped' => false, 'message' => ''];
    }
    $tail = trim(implode(' ', array_slice(array_filter(array_map('trim', $output)), -3)));
    return ['ok' => false, 'skipped' => false, 'message' => substr($tail !== '' ? $tail : 'composer exited with code ' . $code, 0, 300)];
}
