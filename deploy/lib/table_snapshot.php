<?php
/*
 * RivetIT - write the table snapshot (table names, row estimates, exact counts of the core tables; no row data, no secrets) that
 * deploy/backup.sh packs into its archive next to the dump, so the restore drill can prove rows came back.
 *
 *   php deploy/lib/table_snapshot.php <app-dir> <output-file>
 * Best effort: prints a warning and exits 0 without a file if it cannot connect, and the backup carries no snapshot.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[$self, $app, $out] = $argv + [null, '', ''];
$app = rtrim((string) $app, '/');
if ($app === '' || $out === '' || !is_file($app . '/config.php')) {
    fwrite(STDERR, "usage: table_snapshot.php <app-dir> <output-file>\n");
    exit(0);
}
try {
    chdir($app);
    ob_start();
    require $app . '/config.php';
    require_once $app . '/vendor/autoload.php';
    ob_end_clean();
    $json = json_encode(\ITFlow\Recovery\TableSnapshot::build($mysqli), JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($out, $json) === false) {
        throw new RuntimeException('cannot write ' . $out);
    }
    @chmod($out, 0600);
} catch (\Throwable $e) {
    fwrite(STDERR, 'table_snapshot: ' . $e->getMessage() . "\n");
    @unlink($out);
}
exit(0);
