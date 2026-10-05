<?php
/*
 * Installs the PHP dependencies after the app code was updated. The in-app Update runs `git pull`, and vendor/ only
 * carries part of the packages (RivetCore and others come from composer), so without this step an updated install
 * would be missing classes until someone ran `composer install` by hand (deploy/update.sh already does).
 *
 * Returns ['ok' => bool, 'skipped' => bool, 'message' => string]. Never throws; a failure is reported, not fatal.
 */
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
    $home = rtrim(sys_get_temp_dir(), '/') . '/rivetit-composer-home';
    if (!is_dir($home)) {
        @mkdir($home, 0700, true);
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
