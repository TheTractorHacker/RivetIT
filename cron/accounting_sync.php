<?php

/*
 * ITFlow - Accounting sync worker (QuickBooks Online one-way push).
 *
 * require_once'd from cron/cron.php alongside the other split workers. Expects
 * $mysqli to be in scope. Processes pending accounting_sync_queue jobs with the
 * same exponential-backoff retry pattern as the webhook delivery worker:
 *   backoff 5m, 30m, 2h, 6h -> failed on the 5th attempt.
 *
 * Dependency resolution:
 *   - a Customer (+ Items) is pushed before its Invoice
 *   - an Invoice is pushed before its Payment
 *   - if a dependency isn't mapped yet, it is enqueued and the job is deferred
 * Idempotency is guaranteed by the accounting_entity_map unique key: an entity
 * that already has a remote id is never re-created.
 */

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    return;
}

require_once dirname(__DIR__) . '/includes/accounting_functions.php';
require_once dirname(__DIR__) . '/includes/class_qbo_client.php';

if (!accountingModuleEnabled($mysqli)) {
    return;
}

$acc_integration = getAccountingIntegration($mysqli);
if (
    !$acc_integration
    || intval($acc_integration['accounting_enabled']) !== 1
    || empty($acc_integration['accounting_realm_id'])
    || empty($acc_integration['accounting_refresh_token'])
) {
    return; // Not connected / nothing to do.
}

$acc_id = intval($acc_integration['accounting_id']);

try {
    $qbo = new QboClient($mysqli, $acc_integration);
    // Fail fast (and re-fetch the possibly-rotated integration row) if we can't
    // get a token at all - avoids hammering the API for every queued job.
    $qbo->getAccessToken();
    $acc_integration = getAccountingIntegration($mysqli); // refresh rotated tokens
    $qbo = new QboClient($mysqli, $acc_integration);
} catch (Throwable $e) {
    logApp("Accounting", "error", "QBO auth failed, skipping sync run: " . $e->getMessage());
    accountingLog($mysqli, $acc_id, 'integration', $acc_id, 'error', 'Auth failed: ' . $e->getMessage());
    return;
}

// Dependency-resolution closures (find-or-create the QBO Customer/Item behind
// an invoice/quote/payment line) - shared with admin/post/accounting_sync_step.php
// via these factories in includes/accounting_functions.php, so there's one
// implementation instead of one per caller.
$ensureCustomer = accountingMakeEnsureCustomer($mysqli, $qbo, $acc_id);
$ensureItem     = accountingMakeEnsureItem($mysqli, $qbo, $acc_id, (string) ($acc_integration['accounting_default_income_account_id'] ?? ''));

// ── Fetch a batch of due jobs ────────────────────────────────────────────────

$sql_jobs = mysqli_query(
    $mysqli,
    "SELECT queue_id, queue_local_type, queue_local_id, queue_op, queue_attempts
     FROM accounting_sync_queue
     WHERE queue_accounting_id = $acc_id
       AND queue_status = 'pending'
       AND queue_next_attempt_at <= NOW()
     ORDER BY queue_id ASC
     LIMIT 50"
);

while ($sql_jobs && ($job = mysqli_fetch_assoc($sql_jobs))) {
    // Per-job logic lives in accountingSyncProcessJob() (includes/accounting_functions.php)
    // so admin/post/accounting_sync_step.php can drive the exact same push
    // logic one job at a time for the live-progress UI, instead of duplicating it.
    accountingSyncProcessJob($mysqli, $qbo, $acc_id, $job, $ensureCustomer, $ensureItem);
}

// Prune old delivered/failed queue rows after 30 days (matches webhook worker).
mysqli_query($mysqli, "DELETE FROM accounting_sync_queue WHERE queue_status IN ('delivered','failed') AND queue_created_at < NOW() - INTERVAL 30 DAY");
