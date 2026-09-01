<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$cron_file  = '/etc/cron.d/itflow';
$cron_php   = '/usr/bin/php /var/www/itflow.foleyit.com/cron/cron.php';

// Valid cron expression: 5 fields, each alphanumeric with */,-
function isValidCronExpr(string $expr): bool {
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return false;
    foreach ($fields as $f) {
        if (!preg_match('/^[\d*\/,\-]+$/', $f)) return false;
    }
    return true;
}

// Save new schedule for the main cron.php job
if (isset($_POST['save_cron_schedule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $preset = $_POST['cron_preset'] ?? '';
    $custom = trim($_POST['cron_custom'] ?? '');
    $new_schedule = $preset === 'custom' ? $custom : $preset;

    if (!isValidCronExpr($new_schedule)) {
        flash_alert("Invalid cron expression.", "danger");
        redirect("/admin/cron.php");
    }

    // Read the existing file, replace the main cron.php line
    $lines = file_exists($cron_file) ? file($cron_file, FILE_IGNORE_NEW_LINES) : [];
    $new_lines = [];
    $replaced = false;
    foreach ($lines as $line) {
        if (!$replaced && !str_starts_with(trim($line), '#') &&
            str_contains($line, 'cron/cron.php')) {
            $new_lines[] = "$new_schedule www-data $cron_php";
            $replaced = true;
        } else {
            $new_lines[] = $line;
        }
    }
    if (!$replaced) {
        $new_lines[] = "$new_schedule www-data $cron_php";
    }

    $content = implode("\n", $new_lines) . "\n";
    $cmd = 'echo ' . escapeshellarg($content) . ' | sudo /usr/bin/tee ' . escapeshellarg($cron_file);
    exec($cmd, $out, $code);

    if ($code === 0) {
        logAction("Cron", "Schedule", "Cron schedule updated to: $new_schedule");
        flash_alert("Cron schedule updated to <code>$new_schedule</code>.");
    } else {
        flash_alert("Failed to write cron file. Check server permissions.", "danger");
    }
    redirect("/admin/cron.php");
}

// Run cron now
if (isset($_POST['run_cron_now'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $cron_script = '/var/www/itflow.foleyit.com/cron/cron.php';
    exec('/usr/bin/php ' . escapeshellarg($cron_script) . ' > /dev/null 2>&1 &');

    flash_alert("Cron started in the background. Check App Logs for results.");
    redirect("/admin/cron.php");
}
