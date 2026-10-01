<?php

/** Find cron.d jobs that invoke this install's own PHP cron scripts. */
function rivetit_cron_jobs_for_app(string $app_root, string $cron_dir = '/etc/cron.d'): array
{
    $app_root = realpath($app_root) ?: rtrim($app_root, '/');
    $script_prefix = $app_root . '/cron/';
    $jobs = [];

    foreach (glob(rtrim($cron_dir, '/') . '/*') ?: [] as $file) {
        // Debian cron ignores backup files with dots in their names.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', basename($file)) || !is_file($file) || !is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line_number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^((?:\S+\s+){4}\S+)\s+(\S+)\s+(.+)$/', $line, $parts)) {
                continue;
            }
            $command = $parts[3];
            $pattern = '~^/usr/bin/php(?:[0-9.]+)?\s+(' . preg_quote($script_prefix, '~') . '[A-Za-z0-9_.-]+\.php)(?:\s|$)~';
            if (!preg_match($pattern, $command, $script)) {
                continue;
            }
            $jobs[] = [
                'file' => $file,
                'line' => $line_number + 1,
                'schedule' => $parts[1],
                'user' => $parts[2],
                'command' => $command,
                'command_hash' => hash('sha256', $command),
                'script' => $script[1],
            ];
        }
    }

    return $jobs;
}

/** Match this app root to the root-owned Cron Manager registration. */
function rivetit_cron_manager_instance(string $app_root, string $config_dir = '/etc/rivetit'): ?string
{
    $app_root = realpath($app_root);
    if ($app_root === false) {
        return null;
    }

    foreach (glob(rtrim($config_dir, '/') . '/cron-manager-*.json') ?: [] as $file) {
        if (!is_file($file) || !is_readable($file) || is_link($file)) {
            continue;
        }
        $name = basename($file);
        if (!preg_match('/^cron-manager-([A-Za-z0-9-]+)\.json$/', $name, $match)) {
            continue;
        }
        $config = json_decode(file_get_contents($file), true);
        if (is_array($config) && ($config['app_root'] ?? null) === $app_root) {
            return $match[1];
        }
    }

    return null;
}
