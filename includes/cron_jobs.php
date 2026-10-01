<?php

/** Read only cron.d jobs that invoke this install's own PHP cron scripts. */
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
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
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
                'schedule' => $parts[1],
                'user' => $parts[2],
                'command' => $command,
                'script' => $script[1],
            ];
        }
    }

    return $jobs;
}
