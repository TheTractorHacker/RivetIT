<?php

/*
 * Training ledger verifier (CLI only; nginx already denies /scripts/*.php).
 *
 * Walks the training hash chain and every hashed training table (Core\LedgerVerifier) and
 * prints the result as JSON. Exit code 0 when the ledger verifies, 2 on a break (or an
 * incomplete run), 1 on a usage or connection error.
 *
 *   sudo -u www-data php scripts/training_ledger_verify.php [--deep] [--max-breaks=N]
 *       Uses config.php. Must run as www-data: config.php is www-data 0640, and --deep
 *       re-hashes media files, which are 0640 www-data.
 *
 *   sudo -n php scripts/training_ledger_verify.php --db=<scratch db> [--deep] [--media-root=DIR]
 *       Connects over the unix socket as the invoking OS user (root under sudo) to a scratch
 *       or verify database only - a hard allowlist, so this mode can never point at the live
 *       database. Allowed: midwest_itflow_scratch, midwest_itflow_verify, and per-lane test
 *       copies named midwest_itflow_<lane>_scratch / midwest_itflow_<lane>_verify.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

chdir(__DIR__);

$options = getopt('', ['db:', 'deep', 'max-breaks:', 'media-root:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php training_ledger_verify.php [--deep] [--max-breaks=N] [--db=<scratch db> [--media-root=DIR]]\n";
    exit(0);
}

function training_verify_db_allowed(string $name): bool
{
    return in_array($name, ['midwest_itflow_scratch', 'midwest_itflow_verify'], true)
        || preg_match('/^midwest_itflow_[a-z0-9]{1,16}_(scratch|verify)$/', $name) === 1;
}

if (isset($options['db'])) {
    $dbName = (string) $options['db'];
    if (!training_verify_db_allowed($dbName)) {
        fwrite(STDERR, "Refusing --db=$dbName: only scratch/verify databases are allowed here.\n");
        exit(1);
    }
    $osUser = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
    if ($osUser === '') {
        fwrite(STDERR, "Cannot determine the invoking OS user for socket authentication.\n");
        exit(1);
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $mysqli = new mysqli('localhost', $osUser, null, $dbName);
    } catch (\mysqli_sql_exception $e) {
        fwrite(STDERR, "Connection to $dbName failed: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    if (!is_readable(__DIR__ . '/../config.php')) {
        fwrite(STDERR, "Cannot read config.php - run as www-data: sudo -u www-data php " . basename(__FILE__) . "\n");
        exit(1);
    }
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        fwrite(STDERR, "config.php did not open a database connection.\n");
        exit(1);
    }
}

$mysqli->set_charset('utf8mb4');

$verifyOpts = [
    'deep' => isset($options['deep']),
    'max_breaks' => isset($options['max-breaks']) ? max(1, (int) $options['max-breaks']) : 20,
];
if (isset($options['media-root'])) {
    $verifyOpts['media_root'] = (string) $options['media-root'];
}

try {
    $result = \ITFlow\Training\Core\LedgerVerifier::verify($mysqli, $verifyOpts);
} catch (\Throwable $e) {
    fwrite(STDERR, "Verify failed: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

$result['summary'] = \ITFlow\Training\Core\LedgerVerifier::resultLine($result);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

exit($result['ok'] ? 0 : 2);
