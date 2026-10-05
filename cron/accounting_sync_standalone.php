<?php
/*
 * RivetIT - standalone entry point for the accounting (QuickBooks) sync
 * worker, used on the beta install and scheduled there via /etc/cron.d/itflow-beta.
 *
 * Unlike production, beta has no cron schedule wired to the full cron/cron.php
 * bundle (RMM sync, backups, automation rules, etc.) - only this narrower,
 * lower-risk job runs automatically here: it pushes invoices/payments/quotes
 * that a human already marked Sent/finalized to the connected QuickBooks
 * company. See cron/accounting_sync.php for the actual worker logic.
 */

chdir(__DIR__);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('accounting_sync_standalone', 1800);

require_once __DIR__ . '/accounting_sync.php';
