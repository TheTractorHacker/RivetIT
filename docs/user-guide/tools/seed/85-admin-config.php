<?php
/*
 * Demo data for the Administration configuration chapters (group "admin-config").
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/85-admin-config.php
 *
 * WHAT IT DOES
 *   1. Runs the sibling 85-admin-config.sql (all the rows: SLA, templates, categories, mailboxes, ...).
 *      That file can also be applied on its own with `mysql`; every statement is guarded, so running both is safe.
 *   2. Stores one manual and one automatic backup zip in <app>/backups, produced by the app's OWN build_backup()
 *      routine (admin/post/backup.php), so the Backup page's history is real. Skipped when a backup of that
 *      type already exists. No secrets are printed.
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "85-admin-config: cannot find the app (no config.php in '$app'). Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');            // the app uses relative ../ requires, like scripts/setup_cli.php
require_once '../config.php';        // $mysqli and the config_* values
require_once '../functions.php';

// ---- 1. the SQL file --------------------------------------------------------------------------------------------
$sql_file = __DIR__ . '/85-admin-config.sql';
$sql = @file_get_contents($sql_file);
if ($sql === false) {
    fwrite(STDERR, "85-admin-config: cannot read $sql_file\n");
    exit(1);
}
if (!mysqli_multi_query($mysqli, $sql)) {
    fwrite(STDERR, '85-admin-config: SQL failed: ' . mysqli_error($mysqli) . "\n");
    exit(1);
}
do {
    if ($res = mysqli_store_result($mysqli)) {
        mysqli_free_result($res);
    }
} while (mysqli_more_results($mysqli) && mysqli_next_result($mysqli));
if (mysqli_errno($mysqli)) {
    fwrite(STDERR, '85-admin-config: SQL failed: ' . mysqli_error($mysqli) . "\n");
    exit(1);
}
echo "85-admin-config: rows applied\n";

// ---- 2. one real backup of each type ------------------------------------------------------------------------------
$backup_dir = $app . '/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0750, true);
}

$need_manual = !glob($backup_dir . '/itflow_*_manual.zip');
$need_auto = !glob($backup_dir . '/itflow_*_auto.zip');

if ($need_manual || $need_auto) {
    // admin/post/backup.php only defines functions unless a handler flag is present in $_GET/$_POST.
    define('FROM_POST_HANDLER', true);
    $_SERVER['DOCUMENT_ROOT'] = $app;
    chdir($app . '/admin');          // the handler uses ../includes relative requires
    require_once $app . '/admin/post/backup.php';

    if ($need_manual) {
        $r = build_backup($mysqli, 'manual', $BACKUP_DIR);
        echo "85-admin-config: stored {$r['name']}\n";
    }
    if ($need_auto) {
        $r = build_backup($mysqli, 'auto', $BACKUP_DIR);
        echo "85-admin-config: stored {$r['name']}\n";
    }
} else {
    echo "85-admin-config: backups already present\n";
}
