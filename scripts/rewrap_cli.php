#!/usr/bin/env php
<?php

/*
 * Rewrap every encrypted column to the active key of the key file (RivetCore\Crypto Rewrapper). Resumable (progress is a small state file
 * per column), idempotent (a value that is already current is skipped), compare-and-set per row (a row edited meanwhile is left for the
 * next pass), one audit row per column per run. It never prints a value.
 *
 *   php scripts/rewrap_cli.php --dry-run                  what would change, nothing written
 *   php scripts/rewrap_cli.php                            run until a pass changes nothing (at most --passes, default 5)
 *   php scripts/rewrap_cli.php --only=settings            one column ("settings.config_smtp_password") or one table
 *   php scripts/rewrap_cli.php --status                   counts by format and the next steps
 *   php scripts/rewrap_cli.php --vault --dry-run          also the credential vault columns (needs the vault v3 DEK; see docs/KEY_MANAGEMENT.md)
 *
 * Options: --batch=200  --max-batches=N  --passes=5  --actor=<user id for the audit rows>  --json
 * Exit status: 0 clean, 1 failures or conflicts remain, 2 usage or refusal.
 *
 * Order for a rotation: keys_cli.php add-key; rewrap_cli.php --dry-run; rewrap_cli.php; keys_cli.php status --inventory (all on the new
 * kid); keep the old key for one backup cycle; keys_cli.php retire <old kid>.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
chdir($root);
$flags = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $eq = strpos($a, '=');
        $flags[$eq === false ? substr($a, 2) : substr($a, 2, $eq - 2)] = $eq === false ? true : substr($a, $eq + 1);
    }
}
if (isset($flags['help'])) {
    echo "Usage: php scripts/rewrap_cli.php [--dry-run] [--only=<column|table>] [--status] [--vault] [--batch=N] [--max-batches=N] [--passes=N] [--actor=ID] [--json]\n";
    exit(0);
}

require_once $root . '/config.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';

use ITFlow\Crypto\RewrapService;

$mysqliConn = $GLOBALS['mysqli'] ?? null;
if (!$mysqliConn instanceof mysqli) {
    fwrite(STDERR, "Error: no database connection.\n");
    exit(2);
}
$actor = isset($flags['actor']) ? (int) $flags['actor'] : null;
$svc = new RewrapService($mysqliConn, $actor);
$withVault = isset($flags['vault']);
if ($withVault) {
    ITFlow\Crypto\VaultV3::addRewrapJobs($svc, $mysqliConn);
}
$json = isset($flags['json']);
$only = isset($flags['only']) && is_string($flags['only']) ? $flags['only'] : null;

try {
    if (isset($flags['status'])) {
        $inv = $svc->inventory();
        $plan = ITFlow\Crypto\KeyStore::load()->ring->hasActive() ? $svc->plan($inv) : null;
        if ($json) {
            echo json_encode(['inventory' => $inv, 'plan' => $plan?->toArray(), 'steps' => $plan?->steps()], JSON_PRETTY_PRINT) . "\n";
            exit(0);
        }
        foreach ($inv['sources'] as $name => $counts) {
            echo sprintf("%-52s %s\n", $name, $counts ? implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($counts), $counts)) : '(empty)');
        }
        echo "Totals: " . implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($inv['totals']), $inv['totals'])) . "\n";
        if ($inv['skipped_cleartext'] > 0) {
            echo "Cleartext values left alone (their columns still have raw readers): " . $inv['skipped_cleartext'] . "\n";
        }
        foreach ($plan?->steps() ?? [] as $s) {
            echo " - $s\n";
        }
        exit(0);
    }

    $dry = isset($flags['dry-run']);
    $batch = max(1, min(2000, (int) ($flags['batch'] ?? 200)));
    $maxBatches = isset($flags['max-batches']) ? max(1, (int) $flags['max-batches']) : null;
    $passes = $dry ? 1 : max(1, min(20, (int) ($flags['passes'] ?? 5)));
    $clean = true;
    $all = [];
    for ($pass = 1; $pass <= $passes; $pass++) {
        $reports = $svc->run($only, $dry, $batch, $maxBatches);
        $changed = 0;
        $bad = 0;
        foreach ($reports as $r) {
            $changed += $r->rewrapped;
            $bad += $r->failed + $r->conflicts;
            $all[$pass][] = $r->toArray();
            if (!$json && ($r->rewrapped > 0 || $r->failed > 0 || $r->conflicts > 0)) {
                echo sprintf("[pass %d] %-52s scanned %d, %s %d, skipped %d, conflicts %d, failed %d%s\n", $pass, $r->job, $r->scanned, $dry ? 'would rewrap' : 'rewrapped', $r->rewrapped, $r->skipped, $r->conflicts, $r->failed, $r->failedIds ? ' (ids: ' . implode(',', array_slice($r->failedIds, 0, 10)) . ')' : '');
            }
        }
        $clean = $bad === 0;
        if (!$json) {
            echo sprintf("Pass %d%s: %d value(s) %s, %d failed or conflicted.\n", $pass, $dry ? ' (dry run)' : '', $changed, $dry ? 'would change' : 'changed', $bad);
        }
        if ($changed === 0 || $dry) {
            break;
        }
    }
    if ($json) {
        echo json_encode(['dry_run' => $dry, 'passes' => $all, 'clean' => $clean], JSON_PRETTY_PRINT) . "\n";
    } elseif (!$dry) {
        echo $clean ? "Done: the last pass changed nothing that failed. Check: php scripts/keys_cli.php status --inventory\n" : "Some values failed or conflicted: run again; unreadable ones need their key restored.\n";
    }
    exit($clean ? 0 : 1);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Refused: " . $e->getMessage() . "\n");
    exit(2);
}
