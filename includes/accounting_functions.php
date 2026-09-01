<?php

/*
 * ITFlow - Accounting sync helpers (QuickBooks Online one-way push)
 *
 * Small, self-contained helper layer used by:
 *   - agent/post/invoice.php  and  agent/post/payment.php   (enqueue hooks)
 *   - cron/accounting_sync.php                              (queue worker)
 *   - admin/settings_accounting.php + oauth_quickbooks_*    (settings / connect)
 *
 * Mirrors the webhook_queue retry pattern already used in cron/cron.php.
 * Deliberately does NOT touch functions.php - all new logic lives here.
 */

// Exponential backoff (minutes) between delivery attempts, matching the
// webhook delivery worker: 5m, 30m, 2h, 6h -> then failed on the 5th attempt.
if (!defined('ACCOUNTING_BACKOFFS')) {
    define('ACCOUNTING_BACKOFFS', '5,30,120,360');
}
if (!defined('ACCOUNTING_MAX_ATTEMPTS')) {
    define('ACCOUNTING_MAX_ATTEMPTS', 5);
}

/**
 * Fetch the (single) accounting integration row, or null if none configured.
 * There is one company per install, so we always use the first row.
 */
function getAccountingIntegration(mysqli $mysqli): ?array {
    $res = mysqli_query($mysqli, "SELECT * FROM accounting_integrations ORDER BY accounting_id ASC LIMIT 1");
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/**
 * Is the accounting module switched on for this install?
 */
function accountingModuleEnabled(mysqli $mysqli): bool {
    $res = mysqli_query($mysqli, "SELECT config_module_enable_accounting FROM settings WHERE company_id = 1 LIMIT 1");
    if (!$res) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    return $row && intval($row['config_module_enable_accounting']) === 1;
}

/**
 * Enqueue a local entity (invoice / payment / client / product) for a one-way
 * push to the accounting provider.
 *
 * No-ops silently unless ALL of the following are true:
 *   - the Accounting module is enabled (config_module_enable_accounting)
 *   - an integration row exists, is enabled AND connected (has a refresh token)
 *   - auto-push is turned on (accounting_auto_push)
 *
 * Idempotent: a repeat call for the same (type,id,op) resets the existing job
 * back to pending instead of creating a duplicate, via the unique key.
 *
 * @return bool true if a job was enqueued/reset, false if skipped.
 */
function enqueueAccountingSync(mysqli $mysqli, string $type, int $id, string $op = 'push'): bool {
    if ($id <= 0) {
        return false;
    }

    if (!accountingModuleEnabled($mysqli)) {
        return false;
    }

    $integration = getAccountingIntegration($mysqli);
    if (
        !$integration
        || intval($integration['accounting_enabled']) !== 1
        || intval($integration['accounting_auto_push']) !== 1
        || empty($integration['accounting_refresh_token'])
    ) {
        return false;
    }

    $accounting_id = intval($integration['accounting_id']);
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $op_esc   = mysqli_real_escape_string($mysqli, $op);

    // INSERT ... ON DUPLICATE KEY UPDATE - reset an existing (possibly
    // delivered/failed) job back to pending and clear the backoff state so the
    // next cron run re-pushes the latest version of the entity.
    mysqli_query(
        $mysqli,
        "INSERT INTO accounting_sync_queue
            (queue_accounting_id, queue_local_type, queue_local_id, queue_op, queue_status, queue_attempts, queue_next_attempt_at)
         VALUES
            ($accounting_id, '$type_esc', $id, '$op_esc', 'pending', 0, NOW())
         ON DUPLICATE KEY UPDATE
            queue_status = 'pending',
            queue_attempts = 0,
            queue_last_error = NULL,
            queue_next_attempt_at = NOW(),
            queue_delivered_at = NULL"
    );

    return true;
}

/**
 * Append a row to the accounting sync log (audit trail for pushes/errors).
 */
function accountingLog(mysqli $mysqli, int $accounting_id, string $type, int $id, string $status, string $message): void {
    $type_esc    = mysqli_real_escape_string($mysqli, $type);
    $status_esc  = mysqli_real_escape_string($mysqli, substr($status, 0, 20));
    $message_esc = mysqli_real_escape_string($mysqli, substr($message, 0, 1000));
    mysqli_query(
        $mysqli,
        "INSERT INTO accounting_sync_log
            (log_accounting_id, log_local_type, log_local_id, log_status, log_message)
         VALUES
            ($accounting_id, '$type_esc', $id, '$status_esc', '$message_esc')"
    );
}

/**
 * Look up the remote (QBO) id a local entity is already mapped to, or null.
 */
function getAccountingMap(mysqli $mysqli, int $accounting_id, string $type, int $id): ?array {
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $res = mysqli_query(
        $mysqli,
        "SELECT map_remote_id, map_remote_sync_token
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = '$type_esc' AND map_local_id = $id
         LIMIT 1"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/**
 * Return every client -> QBO customer mapping for this integration, keyed by
 * local client_id. Used by the client-mapping admin page to render status in a
 * single query. Each value is the raw accounting_entity_map row.
 */
function getAllClientMappings(mysqli $mysqli, int $accounting_id): array {
    $out = [];
    $res = mysqli_query(
        $mysqli,
        "SELECT map_local_id, map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = 'client'"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[intval($row['map_local_id'])] = $row;
    }
    return $out;
}

/**
 * Look up a single client -> QBO customer mapping, or null.
 */
function getClientMapping(mysqli $mysqli, int $accounting_id, int $client_id): ?array {
    $res = mysqli_query(
        $mysqli,
        "SELECT map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = 'client' AND map_local_id = $client_id
         LIMIT 1"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/**
 * Upsert a client -> QBO customer mapping (also stores the QBO DisplayName so
 * the mapping list renders without a live QBO round-trip).
 */
function setClientMapping(mysqli $mysqli, int $accounting_id, int $client_id, string $remote_id, string $remote_name = '', string $sync_token = ''): void {
    $remote_id_esc   = mysqli_real_escape_string($mysqli, $remote_id);
    $remote_name_esc = mysqli_real_escape_string($mysqli, substr($remote_name, 0, 255));
    $sync_token_esc  = mysqli_real_escape_string($mysqli, substr($sync_token, 0, 32));
    mysqli_query(
        $mysqli,
        "INSERT INTO accounting_entity_map
            (map_accounting_id, map_local_type, map_local_id, map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at)
         VALUES
            ($accounting_id, 'client', $client_id, '$remote_id_esc', '$remote_name_esc', '$sync_token_esc', NOW())
         ON DUPLICATE KEY UPDATE
            map_remote_id = VALUES(map_remote_id),
            map_remote_name = VALUES(map_remote_name),
            map_remote_sync_token = VALUES(map_remote_sync_token),
            map_last_synced_at = NOW()"
    );
}

/**
 * Delete a client -> QBO customer mapping (Unlink).
 */
function deleteClientMapping(mysqli $mysqli, int $accounting_id, int $client_id): void {
    mysqli_query(
        $mysqli,
        "DELETE FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = 'client' AND map_local_id = $client_id"
    );
}

/* ── Product / Service -> QBO Item mapping ───────────────────────────────────
 *
 * ITFlow's billable catalogue lives in the `products` table, where each row is
 * a product OR a service (products.product_type in {'product','service'}); both
 * become QBO "Items". These helpers mirror the client-mapping helpers above but
 * key on product_id under map_local_type='item', storing the product_type in
 * map_local_subtype so a mapping row is self-describing.
 *
 * The cron sync worker (cron/accounting_sync.php) resolves invoice line items
 * under map_local_type='product'. So setItemMapping/deleteItemMapping ALSO keep
 * that 'product' row in sync with the manual choice - otherwise a manual link
 * would never actually take effect during a push (the same way a 'client'
 * mapping is consumed by the worker). The canonical, display-facing record is
 * the 'item' row (it carries the QBO name + subtype); the 'product' row is the
 * worker-facing mirror.
 */

/**
 * Return every product/service -> QBO Item mapping for this integration, keyed
 * by local product_id. Each value is the raw accounting_entity_map row.
 */
function getAllItemMappings(mysqli $mysqli, int $accounting_id): array {
    $out = [];
    $res = mysqli_query(
        $mysqli,
        "SELECT map_local_id, map_local_subtype, map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = 'item'"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[intval($row['map_local_id'])] = $row;
    }
    return $out;
}

/**
 * Look up a single product/service -> QBO Item mapping, or null.
 */
function getItemMapping(mysqli $mysqli, int $accounting_id, int $product_id): ?array {
    $res = mysqli_query(
        $mysqli,
        "SELECT map_local_id, map_local_subtype, map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = 'item' AND map_local_id = $product_id
         LIMIT 1"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/**
 * Upsert a product/service -> QBO Item mapping. Writes the canonical 'item' row
 * (with the QBO Item name + product/service subtype) AND mirrors the remote id /
 * sync token into the 'product' row the cron worker consumes.
 */
function setItemMapping(mysqli $mysqli, int $accounting_id, int $product_id, string $remote_id, string $remote_name = '', string $sync_token = '', string $subtype = ''): void {
    $remote_id_esc   = mysqli_real_escape_string($mysqli, $remote_id);
    $remote_name_esc = mysqli_real_escape_string($mysqli, substr($remote_name, 0, 255));
    $sync_token_esc  = mysqli_real_escape_string($mysqli, substr($sync_token, 0, 32));
    $subtype_esc     = mysqli_real_escape_string($mysqli, substr($subtype, 0, 20));
    mysqli_query(
        $mysqli,
        "INSERT INTO accounting_entity_map
            (map_accounting_id, map_local_type, map_local_id, map_local_subtype, map_remote_id, map_remote_name, map_remote_sync_token, map_last_synced_at)
         VALUES
            ($accounting_id, 'item', $product_id, '$subtype_esc', '$remote_id_esc', '$remote_name_esc', '$sync_token_esc', NOW())
         ON DUPLICATE KEY UPDATE
            map_local_subtype = VALUES(map_local_subtype),
            map_remote_id = VALUES(map_remote_id),
            map_remote_name = VALUES(map_remote_name),
            map_remote_sync_token = VALUES(map_remote_sync_token),
            map_last_synced_at = NOW()"
    );

    // Mirror into the worker-facing 'product' row so the push actually uses it.
    setAccountingMap($mysqli, $accounting_id, 'product', $product_id, $remote_id, $sync_token);
}

/**
 * Delete a product/service -> QBO Item mapping (Unlink). Removes both the
 * canonical 'item' row and the worker-facing 'product' mirror.
 */
function deleteItemMapping(mysqli $mysqli, int $accounting_id, int $product_id): void {
    mysqli_query(
        $mysqli,
        "DELETE FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type IN ('item','product') AND map_local_id = $product_id"
    );
}

/**
 * Build a remote (QBO) id -> local id reverse index for one local type.
 * Used by the "pull from QuickBooks" import screens (invoices/estimates/
 * payments, mirroring the existing item pull) to determine (a) which QBO
 * records already have a matching ITFlow record - pushed OR pulled, the map
 * doesn't care which direction created it - and (b) to resolve a QBO
 * CustomerRef/LinkedTxn id back to the local client/invoice id it belongs to.
 */
function getRemoteToLocalMap(mysqli $mysqli, int $accounting_id, string $type): array {
    $out = [];
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $res = mysqli_query(
        $mysqli,
        "SELECT map_remote_id, map_local_id
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = '$type_esc'"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        if ((string) $row['map_remote_id'] !== '') {
            $out[(string) $row['map_remote_id']] = intval($row['map_local_id']);
        }
    }
    return $out;
}

/**
 * Persist (upsert) a local->remote mapping after a successful push.
 */
function setAccountingMap(mysqli $mysqli, int $accounting_id, string $type, int $id, string $remote_id, string $sync_token = ''): void {
    $type_esc       = mysqli_real_escape_string($mysqli, $type);
    $remote_id_esc  = mysqli_real_escape_string($mysqli, $remote_id);
    $sync_token_esc = mysqli_real_escape_string($mysqli, substr($sync_token, 0, 32));
    mysqli_query(
        $mysqli,
        "INSERT INTO accounting_entity_map
            (map_accounting_id, map_local_type, map_local_id, map_remote_id, map_remote_sync_token, map_last_synced_at)
         VALUES
            ($accounting_id, '$type_esc', $id, '$remote_id_esc', '$sync_token_esc', NOW())
         ON DUPLICATE KEY UPDATE
            map_remote_id = VALUES(map_remote_id),
            map_remote_sync_token = VALUES(map_remote_sync_token),
            map_last_synced_at = NOW()"
    );
}

/**
 * Build the "find or create the QBO Customer for this local client" closure.
 * Factored out of cron/accounting_sync.php so admin/post/accounting_sync_step.php
 * (the one-job-per-call endpoint behind the live progress UI) can share the
 * exact same dependency-resolution logic instead of duplicating it.
 */
function accountingMakeEnsureCustomer(mysqli $mysqli, QboClient $qbo, int $acc_id): callable {
    return function (int $client_id) use ($mysqli, $qbo, $acc_id): string {
        $existing = getAccountingMap($mysqli, $acc_id, 'client', $client_id);
        if ($existing && !empty($existing['map_remote_id'])) {
            return $existing['map_remote_id'];
        }

        $res = mysqli_query($mysqli, "SELECT client_name, client_website, client_currency_code FROM clients WHERE client_id = $client_id LIMIT 1");
        $client = $res ? mysqli_fetch_assoc($res) : null;
        if (!$client) {
            throw new QboException("Local client $client_id not found.");
        }

        $display_name = trim((string) $client['client_name']);
        if ($display_name === '') {
            $display_name = "Client $client_id";
        }

        // Primary contact for email/phone enrichment + dedupe.
        $email = '';
        $phone = '';
        $cres = mysqli_query($mysqli, "SELECT contact_email, contact_phone FROM contacts WHERE contact_client_id = $client_id AND contact_primary = 1 LIMIT 1");
        if ($cres && ($contact = mysqli_fetch_assoc($cres))) {
            $email = trim((string) ($contact['contact_email'] ?? ''));
            $phone = trim((string) ($contact['contact_phone'] ?? ''));
        }

        // Dedupe against an existing QBO Customer before creating a new one.
        $found = $qbo->findCustomer($display_name, $email);
        if ($found && !empty($found['Id'])) {
            setAccountingMap($mysqli, $acc_id, 'client', $client_id, (string) $found['Id'], (string) ($found['SyncToken'] ?? ''));
            return (string) $found['Id'];
        }

        $created = $qbo->createCustomer([
            'display_name' => $display_name,
            'email'        => $email,
            'phone'        => $phone,
            'website'      => (string) ($client['client_website'] ?? ''),
            'currency'     => (string) ($client['client_currency_code'] ?? ''),
        ]);
        setAccountingMap($mysqli, $acc_id, 'client', $client_id, (string) $created['Id'], (string) ($created['SyncToken'] ?? ''));
        return (string) $created['Id'];
    };
}

/**
 * Build the "find or create the QBO Item for this local product" closure.
 * $income_account_hint is the configured default income account id (may be
 * empty, in which case the QBO account default is looked up lazily on first
 * use and cached for the lifetime of the returned closure).
 */
function accountingMakeEnsureItem(mysqli $mysqli, QboClient $qbo, int $acc_id, string $income_account_hint = ''): callable {
    $acc_income_account = $income_account_hint;
    return function (int $product_id, string $name, float $price, string $description) use ($mysqli, $qbo, $acc_id, &$acc_income_account): string {
        // product_id 0 is the synthetic "generic services" fallback item used for
        // ad-hoc invoice lines that aren't tied to a product catalogue entry.
        $existing = getAccountingMap($mysqli, $acc_id, 'product', $product_id);
        if ($existing && !empty($existing['map_remote_id'])) {
            return $existing['map_remote_id'];
        }

        $item_name = trim($name);
        if ($item_name === '') {
            $item_name = $product_id > 0 ? "Product $product_id" : 'ITFlow Services';
        }

        $found = $qbo->findItem($item_name);
        if ($found && !empty($found['Id'])) {
            setAccountingMap($mysqli, $acc_id, 'product', $product_id, (string) $found['Id'], (string) ($found['SyncToken'] ?? ''));
            return (string) $found['Id'];
        }

        if ($acc_income_account === '') {
            $acc_income_account = (string) ($qbo->getDefaultIncomeAccountId() ?? '');
        }
        if ($acc_income_account === '') {
            throw new QboException('No income account available to create QBO item.');
        }

        $created = $qbo->createItem([
            'name'              => $item_name,
            'price'             => $price,
            'description'       => $description,
            'income_account_id' => $acc_income_account,
        ]);
        setAccountingMap($mysqli, $acc_id, 'product', $product_id, (string) $created['Id'], (string) ($created['SyncToken'] ?? ''));
        return (string) $created['Id'];
    };
}

/**
 * Process exactly one accounting_sync_queue job (push it to QuickBooks) and
 * persist its outcome (delivered / backoff-and-retry / deferred / failed) to
 * the queue row + sync log. Shared by cron/accounting_sync.php (loops over a
 * whole batch, no UI) and admin/post/accounting_sync_step.php (processes one
 * job per AJAX call so the settings UI can show live progress).
 *
 * $ensureCustomer / $ensureItem are built by accountingMakeEnsureCustomer() /
 * accountingMakeEnsureItem() above (they need $qbo/$acc_id in scope).
 *
 * Returns ['queue_id','type','local_id','outcome','message'] where outcome is
 * one of: delivered | already-mapped | skipped | deferred | retry | failed.
 */
function accountingSyncProcessJob(mysqli $mysqli, QboClient $qbo, int $acc_id, array $job, callable $ensureCustomer, callable $ensureItem): array {
    $job_id       = intval($job['queue_id']);
    $job_type     = (string) $job['queue_local_type'];
    $job_local_id = intval($job['queue_local_id']);
    $job_attempts = intval($job['queue_attempts']) + 1;
    $acc_backoffs = array_map('intval', explode(',', ACCOUNTING_BACKOFFS));

    // Atomically claim the job before doing any work. cron/cron.php (hourly),
    // cron/accounting_sync_standalone.php (every 15m), and the "sync now" button
    // in admin/settings_accounting.php can all call this concurrently - without
    // this claim, two callers could both see the same 'pending' row and both push
    // the same entity to the live QuickBooks company before either writes its
    // accounting_entity_map row.
    mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'processing' WHERE queue_id = $job_id AND queue_status = 'pending'");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => 'already-claimed', 'message' => "Job #$job_id was already claimed by another sync run."];
    }

    $deferred = false;
    $outcome  = 'delivered';
    $message  = '';

    try {
        switch ($job_type) {

            case 'client':
                $ensureCustomer($job_local_id);
                $message = "Client #$job_local_id synced to QuickBooks.";
                break;

            case 'product':
                $ensureItem($job_local_id, '', 0.0, '');
                $message = "Product/service #$job_local_id synced to QuickBooks.";
                break;

            case 'invoice': {
                $map = getAccountingMap($mysqli, $acc_id, 'invoice', $job_local_id);
                if ($map && !empty($map['map_remote_id'])) {
                    $outcome = 'already-mapped';
                    $message = "Invoice #$job_local_id was already synced.";
                    break;
                }

                $ires = mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $job_local_id LIMIT 1");
                $invoice = $ires ? mysqli_fetch_assoc($ires) : null;
                if (!$invoice) {
                    throw new QboException("Local invoice $job_local_id not found.");
                }

                $customer_ref = $ensureCustomer(intval($invoice['invoice_client_id']));

                $lines = [];
                $lres = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $job_local_id ORDER BY item_order ASC");
                while ($lres && ($item = mysqli_fetch_assoc($lres))) {
                    $qty         = floatval($item['item_quantity']);
                    $unit_price  = round(floatval($item['item_price']), 2);
                    $line_amount = round($unit_price * $qty, 2);
                    $product_id  = intval($item['item_product_id']);
                    $item_name   = (string) $item['item_name'];
                    $item_desc   = trim((string) ($item['item_description'] ?? ''));

                    $item_ref = $ensureItem($product_id, $item_name, $unit_price, $item_desc);

                    $lines[] = [
                        'DetailType' => 'SalesItemLineDetail',
                        'Amount'     => $line_amount,
                        'Description' => $item_desc !== '' ? $item_desc : $item_name,
                        'SalesItemLineDetail' => [
                            'ItemRef'   => ['value' => $item_ref],
                            'Qty'       => $qty,
                            'UnitPrice' => $unit_price,
                        ],
                    ];
                }

                if (empty($lines)) {
                    accountingLog($mysqli, $acc_id, 'invoice', $job_local_id, 'skipped', 'Invoice has no line items.');
                    $outcome = 'skipped';
                    $message = "Invoice #$job_local_id has no line items - skipped.";
                    break;
                }

                $doc_number = substr((string) $invoice['invoice_prefix'] . $invoice['invoice_number'], 0, 21);

                $created = $qbo->createInvoice([
                    'CustomerRef' => ['value' => $customer_ref],
                    'TxnDate'     => (string) $invoice['invoice_date'],
                    'DueDate'     => (string) $invoice['invoice_due'],
                    'DocNumber'   => $doc_number,
                    'CurrencyRef' => ['value' => (string) $invoice['invoice_currency_code']],
                    'Line'        => $lines,
                ]);
                setAccountingMap($mysqli, $acc_id, 'invoice', $job_local_id, (string) $created['Id'], (string) ($created['SyncToken'] ?? ''));
                // QBO silently substitutes its own auto-number for DocNumber (no error)
                // when the company hasn't enabled "Custom transaction numbers" in
                // Account and Settings > Sales - so the number we asked for and the
                // number QBO actually assigned can silently diverge. Compare what we
                // sent against what came back so that drift is visible in the sync
                // log itself instead of only discoverable by checking QBO's UI.
                $qbo_doc_number = (string) ($created['DocNumber'] ?? '');
                if ($qbo_doc_number !== '' && $qbo_doc_number !== $doc_number) {
                    $message = "Pushed invoice $doc_number -> QBO Invoice #{$created['Id']}, but QBO assigned its own number ($qbo_doc_number) instead - enable \"Custom transaction numbers\" in QBO Account and Settings > Sales to fix.";
                } else {
                    $message = "Pushed invoice $doc_number -> QBO Invoice #{$created['Id']}";
                }
                accountingLog($mysqli, $acc_id, 'invoice', $job_local_id, 'delivered', $message);
                break;
            }

            case 'quote': {
                $map = getAccountingMap($mysqli, $acc_id, 'quote', $job_local_id);
                if ($map && !empty($map['map_remote_id'])) {
                    $outcome = 'already-mapped';
                    $message = "Quote #$job_local_id was already synced.";
                    break;
                }

                $qres = mysqli_query($mysqli, "SELECT * FROM quotes WHERE quote_id = $job_local_id LIMIT 1");
                $quote = $qres ? mysqli_fetch_assoc($qres) : null;
                if (!$quote) {
                    throw new QboException("Local quote $job_local_id not found.");
                }

                $customer_ref = $ensureCustomer(intval($quote['quote_client_id']));

                $lines = [];
                $lres = mysqli_query($mysqli, "SELECT * FROM quote_items WHERE item_quote_id = $job_local_id ORDER BY item_order ASC");
                while ($lres && ($item = mysqli_fetch_assoc($lres))) {
                    $qty         = floatval($item['item_quantity']);
                    $unit_price  = round(floatval($item['item_price']), 2);
                    $line_amount = round($unit_price * $qty, 2);
                    $product_id  = intval($item['item_product_id']);
                    $item_name   = (string) $item['item_name'];
                    $item_desc   = trim((string) ($item['item_description'] ?? ''));

                    $item_ref = $ensureItem($product_id, $item_name, $unit_price, $item_desc);

                    $lines[] = [
                        'DetailType' => 'SalesItemLineDetail',
                        'Amount'     => $line_amount,
                        'Description' => $item_desc !== '' ? $item_desc : $item_name,
                        'SalesItemLineDetail' => [
                            'ItemRef'   => ['value' => $item_ref],
                            'Qty'       => $qty,
                            'UnitPrice' => $unit_price,
                        ],
                    ];
                }

                if (empty($lines)) {
                    accountingLog($mysqli, $acc_id, 'quote', $job_local_id, 'skipped', 'Quote has no line items.');
                    $outcome = 'skipped';
                    $message = "Quote #$job_local_id has no line items - skipped.";
                    break;
                }

                $doc_number = substr((string) $quote['quote_prefix'] . $quote['quote_number'], 0, 21);

                $payload = [
                    'CustomerRef' => ['value' => $customer_ref],
                    'TxnDate'     => (string) $quote['quote_date'],
                    'DocNumber'   => $doc_number,
                    'CurrencyRef' => ['value' => (string) $quote['quote_currency_code']],
                    'Line'        => $lines,
                ];
                if (!empty($quote['quote_expire'])) {
                    $payload['ExpirationDate'] = (string) $quote['quote_expire'];
                }

                $created = $qbo->createEstimate($payload);
                setAccountingMap($mysqli, $acc_id, 'quote', $job_local_id, (string) $created['Id'], (string) ($created['SyncToken'] ?? ''));
                // Same QBO auto-numbering caveat as createInvoice() above - see that
                // comment for why this comparison exists.
                $qbo_doc_number = (string) ($created['DocNumber'] ?? '');
                if ($qbo_doc_number !== '' && $qbo_doc_number !== $doc_number) {
                    $message = "Pushed quote $doc_number -> QBO Estimate #{$created['Id']}, but QBO assigned its own number ($qbo_doc_number) instead - enable \"Custom transaction numbers\" in QBO Account and Settings > Sales to fix.";
                } else {
                    $message = "Pushed quote $doc_number -> QBO Estimate #{$created['Id']}";
                }
                accountingLog($mysqli, $acc_id, 'quote', $job_local_id, 'delivered', $message);
                break;
            }

            case 'payment': {
                $map = getAccountingMap($mysqli, $acc_id, 'payment', $job_local_id);
                if ($map && !empty($map['map_remote_id'])) {
                    $outcome = 'already-mapped';
                    $message = "Payment #$job_local_id was already synced.";
                    break;
                }

                $pres = mysqli_query($mysqli, "SELECT * FROM payments WHERE payment_id = $job_local_id LIMIT 1");
                $payment = $pres ? mysqli_fetch_assoc($pres) : null;
                if (!$payment) {
                    throw new QboException("Local payment $job_local_id not found.");
                }

                $invoice_id = intval($payment['payment_invoice_id']);

                $inv_map = getAccountingMap($mysqli, $acc_id, 'invoice', $invoice_id);
                if (!$inv_map || empty($inv_map['map_remote_id'])) {
                    enqueueAccountingSync($mysqli, 'invoice', $invoice_id);
                    $deferred = true;
                    $message = "Payment #$job_local_id is waiting on invoice #$invoice_id to sync first.";
                    accountingLog($mysqli, $acc_id, 'payment', $job_local_id, 'deferred', $message);
                    break;
                }

                $client_id = intval(getFieldById('invoices', $invoice_id, 'invoice_client_id'));
                $customer_ref = $ensureCustomer($client_id);

                $amount = round(floatval($payment['payment_amount']), 2);
                $created = $qbo->createPayment([
                    'TotalAmt'    => $amount,
                    'CustomerRef' => ['value' => $customer_ref],
                    'TxnDate'     => (string) $payment['payment_date'],
                    'CurrencyRef' => ['value' => (string) $payment['payment_currency_code']],
                    'Line'        => [[
                        'Amount'    => $amount,
                        'LinkedTxn' => [[
                            'TxnId'   => (string) $inv_map['map_remote_id'],
                            'TxnType' => 'Invoice',
                        ]],
                    ]],
                ]);
                setAccountingMap($mysqli, $acc_id, 'payment', $job_local_id, (string) $created['Id'], (string) ($created['SyncToken'] ?? ''));
                $message = "Pushed payment #$job_local_id -> QBO Payment #{$created['Id']}";
                accountingLog($mysqli, $acc_id, 'payment', $job_local_id, 'delivered', $message);
                break;
            }

            default:
                throw new QboException("Unknown queue local type '$job_type'.");
        }

    } catch (Throwable $e) {
        $err = mysqli_real_escape_string($mysqli, substr($e->getMessage(), 0, 500));
        if ($job_attempts >= ACCOUNTING_MAX_ATTEMPTS) {
            mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'failed', queue_attempts = $job_attempts, queue_last_error = '$err' WHERE queue_id = $job_id");
            accountingLog($mysqli, $acc_id, $job_type, $job_local_id, 'failed', $e->getMessage());
            logApp("Accounting", "error", "QBO sync failed permanently for $job_type $job_local_id: " . $e->getMessage());
            return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => 'failed', 'message' => $e->getMessage()];
        }
        $delay = $acc_backoffs[min($job_attempts - 1, count($acc_backoffs) - 1)];
        mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'pending', queue_attempts = $job_attempts, queue_last_error = '$err', queue_next_attempt_at = DATE_ADD(NOW(), INTERVAL $delay MINUTE) WHERE queue_id = $job_id");
        return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => 'retry', 'message' => $e->getMessage() . " (will retry in {$delay}m)"];
    }

    if ($deferred) {
        if ($job_attempts >= ACCOUNTING_MAX_ATTEMPTS) {
            mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'failed', queue_attempts = $job_attempts, queue_last_error = 'Dependency never became available' WHERE queue_id = $job_id");
            accountingLog($mysqli, $acc_id, $job_type, $job_local_id, 'failed', 'Dependency never became available.');
            return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => 'failed', 'message' => 'Dependency never became available.'];
        }
        $delay = $acc_backoffs[min($job_attempts - 1, count($acc_backoffs) - 1)];
        mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'pending', queue_attempts = $job_attempts, queue_next_attempt_at = DATE_ADD(NOW(), INTERVAL $delay MINUTE) WHERE queue_id = $job_id");
        return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => 'deferred', 'message' => $message];
    }

    mysqli_query($mysqli, "UPDATE accounting_sync_queue SET queue_status = 'delivered', queue_attempts = $job_attempts, queue_last_error = NULL, queue_delivered_at = NOW() WHERE queue_id = $job_id");
    return ['queue_id' => $job_id, 'type' => $job_type, 'local_id' => $job_local_id, 'outcome' => $outcome, 'message' => $message];
}
