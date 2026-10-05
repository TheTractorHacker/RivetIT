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
    // The web server user usually has no home directory, so give composer a private one. The name is random and the
    // directory is created fresh (mkdir fails if it already exists), so another local user cannot pre-create it to feed
    // composer a config or plugin; it is removed afterwards. Plugins and scripts are off for this step.
    $home = rtrim(sys_get_temp_dir(), '/') . '/rivetit-composer-' . bin2hex(random_bytes(8));
    if (!@mkdir($home, 0700)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'could not create a private composer home directory'];
    }
    @set_time_limit(0);
    $output = [];
    $code = 1;
    exec('cd ' . escapeshellarg($root) . ' && COMPOSER_HOME=' . escapeshellarg($home) . ' COMPOSER_NO_INTERACTION=1 '
        . escapeshellarg($php) . ' ' . escapeshellarg($composer) . ' install --no-dev --no-plugins --no-scripts --no-interaction 2>&1', $output, $code);
    rivetit_composer_remove_dir($home);
    if ($code === 0) {
        return ['ok' => true, 'skipped' => false, 'message' => ''];
    }
    $tail = trim(implode(' ', array_slice(array_filter(array_map('trim', $output)), -3)));
    return ['ok' => false, 'skipped' => false, 'message' => substr($tail !== '' ? $tail : 'composer exited with code ' . $code, 0, 300)];
}

/** Removes the private composer home created above. Only ever called with that directory. */
function rivetit_composer_remove_dir(string $dir): void
{
    if (strpos(basename($dir), 'rivetit-composer-') !== 0 || !is_dir($dir) || is_link($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}
