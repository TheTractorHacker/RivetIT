<?php
/*
 * ITFlow beta - standalone entry point for the accounting (QuickBooks) sync
 * worker, scheduled independently via /etc/cron.d/itflow-beta.
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
require_once __DIR__ . '/accounting_sync.php';
