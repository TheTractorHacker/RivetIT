<?php

/*
 * Launches RivetCore's scripts/rmm-store-migrate.php and scripts/rmm-store-verify.php as DETACHED background processes (never inside the web request
 * that clicked the button - docs/design/rmm-data-store.md section 13.7 decision 5), and reads back their JSON-lines progress for the Data store card's
 * poll endpoint. The migration's own state (phase, dual-write, cut-over) lives in the database (rmm_store_migration, read through
 * RmmModule::dataStoreSwitch()->status()); this file's log is only the human-readable event stream shown while a run is in flight.
 */

require_once __DIR__ . '/rmm_bootstrap.php';   // rivetRmmModule()

/** backups/ is denied by every web server template (same convention as EA_TRANSFER_DIR); created with 0700 on first use. */
function rivetRmmDataStoreRunDir(): string
{
    $dir = dirname(__DIR__) . '/backups/rmm-store-migrate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

/** Absolute path to the CLI bootstrap file RivetCore\Rmm\Store\Cli\CliBootstrap::load() reads (this edition's includes/rmm_store_cli_bootstrap.php). */
function rivetRmmDataStoreBootstrapFile(): string
{
    return __DIR__ . '/rmm_store_cli_bootstrap.php';
}

/**
 * Start `scripts/rmm-store-migrate.php` with $args, detached from this request (setsid; stdout+stderr to a private log file under backups/). Never
 * waits for it to finish. Returns the log file path and the shell's reported PID (best-effort; the state of truth is the database row).
 *
 * @param list<string> $args already-built --flag / --flag=value tokens; this function adds --bootstrap
 * @return array{ok:bool,log:?string,pid:?int,error:?string}
 */
function rivetRmmDataStoreLaunchMigrate(array $args): array
{
    return rivetRmmDataStoreLaunchScript('rmm-store-migrate.php', $args);
}

/** Same as {@see rivetRmmDataStoreLaunchMigrate()} for `scripts/rmm-store-verify.php` (used after a restore, not from this card, but shares the launcher). */
function rivetRmmDataStoreLaunchVerify(array $args): array
{
    return rivetRmmDataStoreLaunchScript('rmm-store-verify.php', $args);
}

/**
 * @return array{ok:bool,log:?string,pid:?int,error:?string}
 *
 * Why exec() with a shell string, not proc_open() with an array command: this must be fire-and-forget (a migration can run for hours, and
 * the web request must not wait for it). proc_open()'s process handle blocks on close - explicitly, or implicitly when PHP destroys the
 * resource at request shutdown - until the CHILD exits, even though the child itself is detached by setsid; there is no array-form
 * equivalent of a shell "&" that avoids this. A shell string is the only way to background it and let this request return immediately.
 * Every dynamic element ($php, $scriptPath, every entry of $argv, $log) is wrapped in escapeshellarg() individually before concatenation,
 * so each becomes exactly one shell word no matter what it contains; none of them is request text to begin with (see the call sites).
 */
function rivetRmmDataStoreLaunchScript(string $script, array $args): array
{
    $root = dirname(__DIR__);
    $scriptPath = $root . '/vendor/rivet/rivet-core/scripts/' . $script;
    if (!is_file($scriptPath)) {
        return ['ok' => false, 'log' => null, 'pid' => null, 'error' => 'The script ' . $script . ' is missing from the vendor directory (composer install?).'];
    }
    // PHP_BINARY inside a PHP-FPM worker is the php-fpm binary, not the CLI one; '/usr/bin/php' is the CLI binary this box uses (same convention as
    // admin/post/cron.php's "Run now" button for a catalog job).
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : (PHP_BINARY ?: '/usr/bin/php');
    $dir = rivetRmmDataStoreRunDir();
    $log = $dir . '/' . preg_replace('/\.php$/', '', $script) . '-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.log';
    $argv = array_merge(['--bootstrap=' . rivetRmmDataStoreBootstrapFile()], $args);
    $cmd = 'setsid ' . escapeshellarg($php) . ' ' . escapeshellarg($scriptPath);
    foreach ($argv as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $cmd .= ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $out = [];
    // nosemgrep: php.lang.security.exec-use.exec-use -- every dynamic token above is individually escapeshellarg()'d (see the docblock); the
    // only unescaped characters are the fixed shell syntax ('setsid', '>', '2>&1', '< /dev/null', '& echo $!'), not request text.
    exec($cmd, $out);
    $pid = isset($out[0]) && ctype_digit(trim($out[0])) ? (int) trim($out[0]) : null;
    if ($pid === null) {
        return ['ok' => false, 'log' => $log, 'pid' => null, 'error' => 'The command could not be started (exec() returned no PID).'];
    }

    return ['ok' => true, 'log' => $log, 'pid' => $pid, 'error' => null];
}

/**
 * The last $lines JSON events of a run's log file, newest last (as the script printed them), plus the final {"summary": ...} line when the run finished.
 *
 * @return list<array<string,mixed>>
 */
function rivetRmmDataStoreTailLog(?string $logFile, int $lines = 80): array
{
    if ($logFile === null || !is_file($logFile) || !is_readable($logFile)) {
        return [];
    }
    $all = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($all)) {
        return [];
    }
    $tail = array_slice($all, -$lines);
    $events = [];
    foreach ($tail as $line) {
        $d = json_decode($line, true);
        if (is_array($d)) {
            $events[] = $d;
        } else {
            $events[] = ['event' => 'raw', 'line' => mb_substr($line, 0, 500)];
        }
    }

    return $events;
}

/** Remember which log file belongs to the current/most recent run, so the poll endpoint finds it without a path from the client. */
function rivetRmmDataStoreRememberLog(string $logFile): void
{
    $_SESSION['rmm_datastore_run_log'] = $logFile;
}

function rivetRmmDataStoreCurrentLog(): ?string
{
    $f = $_SESSION['rmm_datastore_run_log'] ?? null;

    return is_string($f) && $f !== '' ? $f : null;
}
