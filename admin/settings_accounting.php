<?php

/*
 * ITFlow - Accounting integration settings (QuickBooks Online one-way sync).
 *
 * Single consolidated entry point for everything QuickBooks Online: Connection,
 * Client Mapping, Item Mapping and Sync Status now live here as Bootstrap 5
 * tabs (?tab=connection|client_mapping|item_mapping|sync_status). The three
 * pages this replaced - accounting_client_mapping.php, accounting_item_mapping.php
 * and accounting_sync_status.php - are now thin redirects here so old
 * links/bookmarks keep working.
 *
 * Self-contained admin page: it handles all of its own POSTs/AJAX (save creds,
 * disconnect, client link/create/unlink, item link/create/unlink, sync-now,
 * retry, re-sync all, run worker) BEFORE any output, then renders the tabbed
 * UI. Reachable from Admin > Integrations (the "Accounting" tab links here)
 * and from its own side-nav entry.
 *
 * Every QBO API call is wrapped so a transport / API failure degrades to a
 * flash error (mutations) or a JSON error (search) - never a fatal.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";
require_once __DIR__ . "/../includes/check_login.php";
require_once __DIR__ . "/../includes/accounting_functions.php";
require_once __DIR__ . "/../includes/class_qbo_client.php";

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect('/admin/settings_integrations.php');
}

// Redirect targets, one per tab, used by every mutation below so the user
// lands back on the tab they were working in.
$connection_path     = '/admin/settings_accounting.php?tab=connection';
$client_mapping_path = '/admin/settings_accounting.php?tab=client_mapping';
$item_mapping_path   = '/admin/settings_accounting.php?tab=item_mapping';
$sync_status_path    = '/admin/settings_accounting.php?tab=sync_status';

// Connection state (used by every branch below, on every tab).
$integration   = getAccountingIntegration($mysqli);
$is_connected  = $integration
    && !empty($integration['accounting_realm_id'])
    && !empty($integration['accounting_refresh_token']);
$accounting_id = $integration ? intval($integration['accounting_id']) : 0;

/**
 * Fetch a real (non-archived, non-lead) client plus its primary contact's
 * email/phone, or null. Guards every client-mapping mutation against
 * bad/hidden client ids.
 */
function amp_get_client(mysqli $mysqli, int $client_id): ?array {
    if ($client_id <= 0) {
        return null;
    }
    $res = mysqli_query(
        $mysqli,
        "SELECT c.client_id, c.client_name, c.client_website,
                ct.contact_email AS primary_email, ct.contact_phone AS primary_phone
         FROM clients c
         LEFT JOIN contacts ct
                ON ct.contact_client_id = c.client_id AND ct.contact_primary = 1
         WHERE c.client_id = $client_id
           AND c.client_archived_at IS NULL
           AND c.client_lead = 0
         LIMIT 1"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/**
 * Fetch a real (non-archived) product/service catalogue row, or null. Guards
 * every item-mapping mutation against bad/hidden product ids.
 */
function aim_get_product(mysqli $mysqli, int $product_id): ?array {
    if ($product_id <= 0) {
        return null;
    }
    $res = mysqli_query(
        $mysqli,
        "SELECT product_id, product_name, product_type, product_price, product_description
         FROM products
         WHERE product_id = $product_id
           AND product_archived_at IS NULL
         LIMIT 1"
    );
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

// ═══════════════════════════════════════════════════════════════════════════
// CONNECTION TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

// ── POST: save settings ──────────────────────────────────────────────────────
if (isset($_POST['save_accounting_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $client_id   = sanitizeInput(trim($_POST['accounting_client_id'] ?? ''));
    $client_secret_raw = (string) ($_POST['accounting_client_secret'] ?? '');
    $environment = ($_POST['accounting_environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';
    $income_acct = sanitizeInput(trim($_POST['accounting_default_income_account_id'] ?? ''));
    $auto_push   = isset($_POST['accounting_auto_push']) ? 1 : 0;
    $enabled     = isset($_POST['accounting_enabled']) ? 1 : 0;

    $client_id_esc   = mysqli_real_escape_string($mysqli, $client_id);
    $environment_esc = mysqli_real_escape_string($mysqli, $environment);
    $income_acct_esc = mysqli_real_escape_string($mysqli, $income_acct);

    if (!$integration) {
        // First-time save - create the single integration row.
        $secret_esc = mysqli_real_escape_string($mysqli, encryptSetting($client_secret_raw));
        mysqli_query($mysqli, "INSERT INTO accounting_integrations SET
            accounting_provider = 'quickbooks_online',
            accounting_client_id = '$client_id_esc',
            accounting_client_secret = '$secret_esc',
            accounting_environment = '$environment_esc',
            accounting_default_income_account_id = '$income_acct_esc',
            accounting_auto_push = $auto_push,
            accounting_enabled = $enabled
        ");
    } else {
        $save_accounting_id = intval($integration['accounting_id']);
        // Only overwrite the secret if a new value was actually entered.
        $secret_sql = '';
        if ($client_secret_raw !== '') {
            $secret_esc = mysqli_real_escape_string($mysqli, encryptSetting($client_secret_raw));
            $secret_sql = "accounting_client_secret = '$secret_esc', ";
        }
        mysqli_query($mysqli, "UPDATE accounting_integrations SET
            accounting_client_id = '$client_id_esc',
            {$secret_sql}
            accounting_environment = '$environment_esc',
            accounting_default_income_account_id = '$income_acct_esc',
            accounting_auto_push = $auto_push,
            accounting_enabled = $enabled
            WHERE accounting_id = $save_accounting_id
        ");
    }

    logAction("Settings", "Edit", "$session_name edited QuickBooks / accounting integration settings");
    flash_alert("Accounting settings saved");
    redirect($connection_path);
}

// ── GET: disconnect ──────────────────────────────────────────────────────────
if (isset($_GET['disconnect'])) {

    validateCSRFToken($_GET['csrf_token']);

    if ($integration) {
        $disc_accounting_id = intval($integration['accounting_id']);
        mysqli_query($mysqli, "UPDATE accounting_integrations SET
            accounting_realm_id = NULL,
            accounting_access_token = NULL,
            accounting_refresh_token = NULL,
            accounting_token_expires_at = NULL,
            accounting_enabled = 0,
            accounting_connected_at = NULL
            WHERE accounting_id = $disc_accounting_id
        ");
        accountingLog($mysqli, $disc_accounting_id, 'integration', $disc_accounting_id, 'disconnected', "Disconnected from QuickBooks by $session_name");
        logAction("Settings", "Edit", "$session_name disconnected QuickBooks Online");
    }

    flash_alert("QuickBooks Online disconnected");
    redirect($connection_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// CLIENT MAPPING TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

// ── AJAX: search QBO customers (returns JSON) ────────────────────────────────
if (($_POST['action'] ?? '') === 'search_qbo_customers') {
    header('Content-Type: application/json');

    // Manual CSRF check so a bad token returns JSON, not an index.php redirect.
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    if (!$is_connected) {
        echo json_encode(['ok' => false, 'error' => 'QuickBooks Online is not connected.']);
        exit;
    }

    try {
        $qbo       = new QboClient($mysqli, $integration);
        $customers = $qbo->searchCustomers(trim((string) ($_POST['q'] ?? '')));
        echo json_encode(['ok' => true, 'customers' => $customers]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: link an existing QBO customer ──────────────────────────────────────
if (isset($_POST['link_client'])) {

    validateCSRFToken($_POST['csrf_token']);

    $client_id   = intval($_POST['client_id'] ?? 0);
    $remote_id   = trim((string) ($_POST['qbo_customer_id'] ?? ''));
    $remote_name = trim((string) ($_POST['qbo_customer_name'] ?? ''));
    $sync_token  = trim((string) ($_POST['qbo_sync_token'] ?? ''));

    $client = amp_get_client($mysqli, $client_id);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
    } elseif (!$client) {
        flash_alert("Client not found.", 'error');
    } elseif ($remote_id === '') {
        flash_alert("No QuickBooks customer was selected.", 'error');
    } else {
        setClientMapping($mysqli, $accounting_id, $client_id, $remote_id, $remote_name, $sync_token);
        accountingLog($mysqli, $accounting_id, 'client', $client_id, 'mapped',
            "Linked '{$client['client_name']}' to QBO customer '{$remote_name}' (Id {$remote_id}) by $session_name");
        logAction("Accounting", "Edit", "$session_name linked client {$client['client_name']} to QuickBooks customer $remote_id", $client_id);
        flash_alert("Linked " . $client['client_name'] . " to QuickBooks customer " . ($remote_name !== '' ? $remote_name : $remote_id) . ".");
    }

    redirect($client_mapping_path);
}

// ── POST: create a new QBO customer from the client ──────────────────────────
if (isset($_POST['create_qbo_customer'])) {

    validateCSRFToken($_POST['csrf_token']);

    $client_id = intval($_POST['client_id'] ?? 0);
    $client    = amp_get_client($mysqli, $client_id);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($client_mapping_path);
    }
    if (!$client) {
        flash_alert("Client not found.", 'error');
        redirect($client_mapping_path);
    }
    if (getClientMapping($mysqli, $accounting_id, $client_id)) {
        flash_alert("That client is already mapped. Unlink it first to remap.", 'warning');
        redirect($client_mapping_path);
    }

    try {
        $qbo = new QboClient($mysqli, $integration);

        // Avoid QBO's "duplicate DisplayName" fault: reuse an existing customer
        // with the same name/email if one is already in the books.
        $existing = $qbo->findCustomer($client['client_name'], (string) ($client['primary_email'] ?? ''));
        if ($existing && !empty($existing['Id'])) {
            setClientMapping(
                $mysqli, $accounting_id, $client_id,
                (string) $existing['Id'],
                (string) ($existing['DisplayName'] ?? $client['client_name']),
                (string) ($existing['SyncToken'] ?? '')
            );
            accountingLog($mysqli, $accounting_id, 'client', $client_id, 'mapped',
                "Matched '{$client['client_name']}' to existing QBO customer Id {$existing['Id']} by $session_name");
            logAction("Accounting", "Edit", "$session_name matched client {$client['client_name']} to existing QuickBooks customer {$existing['Id']}", $client_id);
            flash_alert("A QuickBooks customer with that name already existed - linked to it instead of creating a duplicate.", 'warning');
            redirect($client_mapping_path);
        }

        $created = $qbo->createCustomer([
            'display_name' => $client['client_name'],
            'email'        => (string) ($client['primary_email'] ?? ''),
            'phone'        => (string) ($client['primary_phone'] ?? ''),
            'website'      => (string) ($client['client_website'] ?? ''),
        ]);

        setClientMapping(
            $mysqli, $accounting_id, $client_id,
            (string) $created['Id'],
            (string) ($created['DisplayName'] ?? $client['client_name']),
            (string) ($created['SyncToken'] ?? '')
        );
        accountingLog($mysqli, $accounting_id, 'client', $client_id, 'created',
            "Created QBO customer Id {$created['Id']} for '{$client['client_name']}' by $session_name");
        logAction("Accounting", "Create", "$session_name created QuickBooks customer {$created['Id']} for client {$client['client_name']}", $client_id);
        flash_alert("Created QuickBooks customer for " . $client['client_name'] . ".");
    } catch (Throwable $e) {
        accountingLog($mysqli, $accounting_id, 'client', $client_id, 'error',
            "Create QBO customer failed for '{$client['client_name']}': " . $e->getMessage());
        flash_alert("Could not create QuickBooks customer: " . $e->getMessage(), 'error');
    }

    redirect($client_mapping_path);
}

// ── POST: unlink ─────────────────────────────────────────────────────────────
if (isset($_POST['unlink_client'])) {

    validateCSRFToken($_POST['csrf_token']);

    $client_id = intval($_POST['client_id'] ?? 0);
    if ($client_id > 0 && $accounting_id > 0) {
        deleteClientMapping($mysqli, $accounting_id, $client_id);
        accountingLog($mysqli, $accounting_id, 'client', $client_id, 'unmapped', "Unlinked client mapping by $session_name");
        logAction("Accounting", "Delete", "$session_name unlinked client $client_id from QuickBooks", $client_id);
        flash_alert("Client mapping removed.");
    }

    redirect($client_mapping_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// ITEM MAPPING TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

// ── AJAX: search QBO items (returns JSON) ────────────────────────────────────
if (($_POST['action'] ?? '') === 'search_qbo_items') {
    header('Content-Type: application/json');

    // Manual CSRF check so a bad token returns JSON, not an index.php redirect.
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    if (!$is_connected) {
        echo json_encode(['ok' => false, 'error' => 'QuickBooks Online is not connected.']);
        exit;
    }

    try {
        $qbo   = new QboClient($mysqli, $integration);
        $items = $qbo->searchItems(trim((string) ($_POST['q'] ?? '')));
        echo json_encode(['ok' => true, 'items' => $items]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: link an existing QBO item ──────────────────────────────────────────
if (isset($_POST['link_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    $product_id  = intval($_POST['product_id'] ?? 0);
    $remote_id   = trim((string) ($_POST['qbo_item_id'] ?? ''));
    $remote_name = trim((string) ($_POST['qbo_item_name'] ?? ''));
    $sync_token  = trim((string) ($_POST['qbo_sync_token'] ?? ''));

    $product = aim_get_product($mysqli, $product_id);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
    } elseif (!$product) {
        flash_alert("Product/service not found.", 'error');
    } elseif ($remote_id === '') {
        flash_alert("No QuickBooks item was selected.", 'error');
    } else {
        setItemMapping($mysqli, $accounting_id, $product_id, $remote_id, $remote_name, $sync_token, (string) $product['product_type']);
        accountingLog($mysqli, $accounting_id, 'item', $product_id, 'mapped',
            "Linked '{$product['product_name']}' to QBO item '{$remote_name}' (Id {$remote_id}) by $session_name");
        logAction("Accounting", "Edit", "$session_name linked product {$product['product_name']} to QuickBooks item $remote_id", $product_id);
        flash_alert("Linked " . $product['product_name'] . " to QuickBooks item " . ($remote_name !== '' ? $remote_name : $remote_id) . ".");
    }

    redirect($item_mapping_path);
}

/**
 * Create a new ITFlow product/service from a QBO Item and map it immediately.
 * Shared by the single-item and "import all" handlers below.
 */
function aim_import_qbo_item(mysqli $mysqli, int $accounting_id, string $remote_id, string $remote_name, string $remote_price, string $remote_type, string $remote_sync_token): int {
    $product_type = ($remote_type === 'Service') ? 'service' : 'product';
    $price        = $remote_price !== '' ? round((float) $remote_price, 2) : 0.0;
    $name_esc     = mysqli_real_escape_string($mysqli, substr($remote_name !== '' ? $remote_name : 'QuickBooks Item ' . $remote_id, 0, 200));

    mysqli_query(
        $mysqli,
        "INSERT INTO products (product_name, product_type, product_price, product_currency_code, product_category_id)
         VALUES ('$name_esc', '$product_type', $price, 'USD', 0)"
    );
    $product_id = mysqli_insert_id($mysqli);

    setItemMapping($mysqli, $accounting_id, $product_id, $remote_id, $remote_name, $remote_sync_token, $product_type);
    accountingLog($mysqli, $accounting_id, 'item', $product_id, 'mapped', "Imported QBO item '{$remote_name}' (Id {$remote_id}) as a new $product_type");

    return $product_id;
}

// ── POST: import one QBO-only item as a new ITFlow product/service ──────────
if (isset($_POST['import_qbo_item'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($item_mapping_path);
    }

    $remote_id     = trim((string) ($_POST['qbo_item_id'] ?? ''));
    $remote_name   = trim((string) ($_POST['qbo_item_name'] ?? ''));
    $remote_price  = trim((string) ($_POST['qbo_item_price'] ?? ''));
    $remote_type   = trim((string) ($_POST['qbo_item_type'] ?? ''));
    $remote_sync   = trim((string) ($_POST['qbo_sync_token'] ?? ''));

    if ($remote_id === '') {
        flash_alert("No QuickBooks item was specified.", 'error');
        redirect($item_mapping_path);
    }

    aim_import_qbo_item($mysqli, $accounting_id, $remote_id, $remote_name, $remote_price, $remote_type, $remote_sync);
    logAction("Accounting", "Create", "$session_name imported QuickBooks item '$remote_name' into ITFlow");
    flash_alert("Imported \"" . ($remote_name !== '' ? $remote_name : $remote_id) . "\" from QuickBooks.");
    redirect($item_mapping_path);
}

// ── POST: import every currently-unmapped QBO item in one go ────────────────
if (isset($_POST['import_all_qbo_items'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($item_mapping_path);
    }

    $imported = 0;
    $failed   = 0;
    try {
        $qbo_bulk = new QboClient($mysqli, $integration);
        $existing_remote_ids = [];
        foreach (getAllItemMappings($mysqli, $accounting_id) as $m) {
            if (!empty($m['map_remote_id'])) {
                $existing_remote_ids[(string) $m['map_remote_id']] = true;
            }
        }
        foreach ($qbo_bulk->searchItems('', 1000) as $qi) {
            if (isset($existing_remote_ids[(string) $qi['Id']])) {
                continue;
            }
            try {
                aim_import_qbo_item($mysqli, $accounting_id, (string) $qi['Id'], (string) $qi['Name'], (string) $qi['UnitPrice'], (string) $qi['Type'], (string) $qi['SyncToken']);
                $imported++;
            } catch (Throwable $e) {
                $failed++;
            }
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
        redirect($item_mapping_path);
    }

    logAction("Accounting", "Create", "$session_name bulk-imported $imported item(s) from QuickBooks" . ($failed ? " ($failed failed)" : ""));
    flash_alert("Imported $imported item(s) from QuickBooks" . ($failed ? ", $failed failed" : "") . ".");
    redirect($item_mapping_path);
}

// ── POST: create a new QBO item from the product/service ─────────────────────
if (isset($_POST['create_qbo_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    $product_id = intval($_POST['product_id'] ?? 0);
    $product    = aim_get_product($mysqli, $product_id);

    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($item_mapping_path);
    }
    if (!$product) {
        flash_alert("Product/service not found.", 'error');
        redirect($item_mapping_path);
    }
    if (getItemMapping($mysqli, $accounting_id, $product_id)) {
        flash_alert("That product is already mapped. Unlink it first to remap.", 'warning');
        redirect($item_mapping_path);
    }

    try {
        $qbo = new QboClient($mysqli, $integration);

        // Avoid QBO's "duplicate Name" fault: reuse an existing item with the
        // same name if one is already in the books.
        $existing = $qbo->findItem((string) $product['product_name']);
        if ($existing && !empty($existing['Id'])) {
            setItemMapping(
                $mysqli, $accounting_id, $product_id,
                (string) $existing['Id'],
                (string) ($existing['Name'] ?? $product['product_name']),
                (string) ($existing['SyncToken'] ?? ''),
                (string) $product['product_type']
            );
            accountingLog($mysqli, $accounting_id, 'item', $product_id, 'mapped',
                "Matched '{$product['product_name']}' to existing QBO item Id {$existing['Id']} by $session_name");
            logAction("Accounting", "Edit", "$session_name matched product {$product['product_name']} to existing QuickBooks item {$existing['Id']}", $product_id);
            flash_alert("A QuickBooks item with that name already existed - linked to it instead of creating a duplicate.", 'warning');
            redirect($item_mapping_path);
        }

        // A sellable QBO Item needs an income account.
        $income_account = (string) ($integration['accounting_default_income_account_id'] ?? '');
        if ($income_account === '') {
            $income_account = (string) ($qbo->getDefaultIncomeAccountId() ?? '');
        }
        if ($income_account === '') {
            throw new QboException('No QuickBooks income account is available. Set a Default Income Account ID in Accounting Settings.');
        }

        $created = $qbo->createItem([
            'name'              => (string) $product['product_name'],
            'price'             => floatval($product['product_price']),
            'description'       => (string) ($product['product_description'] ?? ''),
            'income_account_id' => $income_account,
        ]);

        setItemMapping(
            $mysqli, $accounting_id, $product_id,
            (string) $created['Id'],
            (string) ($created['Name'] ?? $product['product_name']),
            (string) ($created['SyncToken'] ?? ''),
            (string) $product['product_type']
        );
        accountingLog($mysqli, $accounting_id, 'item', $product_id, 'created',
            "Created QBO item Id {$created['Id']} for '{$product['product_name']}' by $session_name");
        logAction("Accounting", "Create", "$session_name created QuickBooks item {$created['Id']} for product {$product['product_name']}", $product_id);
        flash_alert("Created QuickBooks item for " . $product['product_name'] . ".");
    } catch (Throwable $e) {
        accountingLog($mysqli, $accounting_id, 'item', $product_id, 'error',
            "Create QBO item failed for '{$product['product_name']}': " . $e->getMessage());
        flash_alert("Could not create QuickBooks item: " . $e->getMessage(), 'error');
    }

    redirect($item_mapping_path);
}

// ── POST: unlink ─────────────────────────────────────────────────────────────
if (isset($_POST['unlink_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    $product_id = intval($_POST['product_id'] ?? 0);
    if ($product_id > 0 && $accounting_id > 0) {
        deleteItemMapping($mysqli, $accounting_id, $product_id);
        accountingLog($mysqli, $accounting_id, 'item', $product_id, 'unmapped', "Unlinked item mapping by $session_name");
        logAction("Accounting", "Delete", "$session_name unlinked product $product_id from QuickBooks", $product_id);
        flash_alert("Item mapping removed.");
    }

    redirect($item_mapping_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// SYNC STATUS TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

// Statuses that count as a "finalized" (pushable) invoice.
$finalized_not_in = "'Draft','Cancelled','Non-Billable'";

// ── POST: sync a single invoice now ──────────────────────────────────────────
if (isset($_POST['sync_invoice'])) {
    validateCSRFToken($_POST['csrf_token']);
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    if ($invoice_id > 0 && enqueueAccountingSync($mysqli, 'invoice', $invoice_id)) {
        accountingLog($mysqli, $accounting_id, 'invoice', $invoice_id, 'queued', "Queued for sync by $session_name");
        flash_alert("Invoice queued for sync. It will push on the next sync run.");
    } else {
        flash_alert("Could not queue invoice - check that Accounting is enabled, connected and auto-push is on.", 'warning');
    }
    redirect($sync_status_path);
}

// ── POST: sync a single quote/estimate now ───────────────────────────────────
if (isset($_POST['sync_quote'])) {
    validateCSRFToken($_POST['csrf_token']);
    $quote_id = intval($_POST['quote_id'] ?? 0);
    if ($quote_id > 0 && enqueueAccountingSync($mysqli, 'quote', $quote_id)) {
        accountingLog($mysqli, $accounting_id, 'quote', $quote_id, 'queued', "Queued for sync by $session_name");
        flash_alert("Quote queued for sync. It will push on the next sync run.");
    } else {
        flash_alert("Could not queue quote - check that Accounting is enabled, connected and auto-push is on.", 'warning');
    }
    redirect($sync_status_path);
}

// ── POST: sync a single payment now ──────────────────────────────────────────
if (isset($_POST['sync_payment'])) {
    validateCSRFToken($_POST['csrf_token']);
    $payment_id = intval($_POST['payment_id'] ?? 0);
    if ($payment_id > 0 && enqueueAccountingSync($mysqli, 'payment', $payment_id)) {
        accountingLog($mysqli, $accounting_id, 'payment', $payment_id, 'queued', "Queued for sync by $session_name");
        flash_alert("Payment queued for sync. It will push on the next sync run.");
    } else {
        flash_alert("Could not queue payment - check that Accounting is enabled, connected and auto-push is on.", 'warning');
    }
    redirect($sync_status_path);
}

// ── POST: retry a failed queue row ───────────────────────────────────────────
if (isset($_POST['retry_queue'])) {
    validateCSRFToken($_POST['csrf_token']);
    $queue_id = intval($_POST['queue_id'] ?? 0);
    if ($queue_id > 0 && $accounting_id > 0) {
        mysqli_query(
            $mysqli,
            "UPDATE accounting_sync_queue
                SET queue_status = 'pending', queue_attempts = 0,
                    queue_last_error = NULL, queue_next_attempt_at = NOW()
             WHERE queue_id = $queue_id AND queue_accounting_id = $accounting_id"
        );
        if (mysqli_affected_rows($mysqli) > 0) {
            flash_alert("Queue job reset to pending - it will retry on the next sync run.");
        } else {
            flash_alert("Queue job not found.", 'warning');
        }
    }
    redirect($sync_status_path);
}

// ── POST: re-sync all finalized invoices that aren't mapped yet ──────────────
if (isset($_POST['resync_all_unmapped'])) {
    validateCSRFToken($_POST['csrf_token']);
    $queued = 0;
    if ($accounting_id > 0) {
        $res = mysqli_query(
            $mysqli,
            "SELECT i.invoice_id
             FROM invoices i
             LEFT JOIN accounting_entity_map m
                    ON m.map_accounting_id = $accounting_id
                   AND m.map_local_type = 'invoice'
                   AND m.map_local_id = i.invoice_id
             WHERE i.invoice_archived_at IS NULL
               AND i.invoice_status NOT IN ($finalized_not_in)
               AND (m.map_remote_id IS NULL OR m.map_remote_id = '')
             ORDER BY i.invoice_id ASC"
        );
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if (enqueueAccountingSync($mysqli, 'invoice', intval($r['invoice_id']))) {
                $queued++;
            }
        }
    }
    if ($queued > 0) {
        accountingLog($mysqli, $accounting_id, 'invoice', 0, 'queued', "Bulk-queued $queued unmapped invoice(s) by $session_name");
        logAction("Accounting", "Edit", "$session_name bulk-queued $queued unmapped invoices for QuickBooks sync");
        flash_alert("Queued $queued unmapped invoice(s) for sync.");
    } else {
        flash_alert("No unmapped finalized invoices to queue (or Accounting sync is off).", 'warning');
    }
    redirect($sync_status_path);
}

// ── POST: run the sync worker inline (idempotent) ────────────────────────────
// Kept as a plain-POST fallback (e.g. JS disabled). The "Run sync worker now"
// button itself drives run_sync_step below instead, so the user sees live
// per-job progress rather than waiting for the whole batch silently.
if (isset($_POST['run_worker'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        // Run the shared cron worker in an isolated scope. It expects $mysqli in
        // scope and no-ops unless the integration is enabled & connected.
        $runner = function () { global $mysqli; require dirname(__DIR__) . '/cron/accounting_sync.php'; };
        $runner();
        flash_alert("Sync worker ran. Review the queue and log below for results.");
    } catch (Throwable $e) {
        flash_alert("Sync worker error: " . $e->getMessage(), 'error');
    }
    redirect($sync_status_path);
}

// ── AJAX: process exactly one due sync queue job (returns JSON) ──────────────
// Powers the live-progress "Run sync worker now" button: the frontend calls
// this repeatedly, one queue job per call, appending each result to an
// on-page log instead of a single opaque full-batch POST + flash message.
if (($_POST['action'] ?? '') === 'run_sync_step') {
    header('Content-Type: application/json');

    // Manual CSRF check so a bad token returns JSON, not an index.php redirect.
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    if (!$is_connected || !accountingModuleEnabled($mysqli)) {
        echo json_encode(['ok' => false, 'error' => 'QuickBooks Online is not connected or Accounting sync is disabled.']);
        exit;
    }

    try {
        $qbo = new QboClient($mysqli, $integration);
        $qbo->getAccessToken();
        $integration = getAccountingIntegration($mysqli); // refresh rotated tokens
        $qbo = new QboClient($mysqli, $integration);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'QuickBooks auth failed: ' . $e->getMessage()]);
        exit;
    }

    $job_row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT queue_id, queue_local_type, queue_local_id, queue_op, queue_attempts
         FROM accounting_sync_queue
         WHERE queue_accounting_id = $accounting_id
           AND queue_status = 'pending'
           AND queue_next_attempt_at <= NOW()
         ORDER BY queue_id ASC
         LIMIT 1"
    ));

    if (!$job_row) {
        echo json_encode(['ok' => true, 'done' => true, 'remaining' => 0]);
        exit;
    }

    $ensureCustomer = accountingMakeEnsureCustomer($mysqli, $qbo, $accounting_id);
    $ensureItem     = accountingMakeEnsureItem($mysqli, $qbo, $accounting_id, (string) ($integration['accounting_default_income_account_id'] ?? ''));

    $result = accountingSyncProcessJob($mysqli, $qbo, $accounting_id, $job_row, $ensureCustomer, $ensureItem);

    $remaining = intval(mysqli_fetch_row(mysqli_query(
        $mysqli,
        "SELECT COUNT(*) FROM accounting_sync_queue
         WHERE queue_accounting_id = $accounting_id
           AND queue_status = 'pending'
           AND queue_next_attempt_at <= NOW()"
    ))[0] ?? 0);

    echo json_encode([
        'ok'        => true,
        'done'      => false,
        'remaining' => $remaining,
        'result'    => $result,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// SYNC STATUS TAB - pull from QuickBooks (invoices / estimates / payments)
// ═══════════════════════════════════════════════════════════════════════════
//
// Mirrors the Item Mapping tab's "Import from QuickBooks" pull feature. One
// hard prerequisite per type (everything else on a QBO line is just text +
// amount, stored directly - no local product/item needs to exist):
//   - invoice / estimate: the QBO CustomerRef must already be mapped to a
//     local client (Client Mapping tab) - ITFlow invoices always belong to a
//     real client row, there's no "no client" invoice.
//   - payment: the linked QBO Invoice must already exist locally (imported or
//     pushed) - ITFlow payments always belong to a real invoice row.
// A local product mapping is used opportunistically (nicer item names / a
// real product link) but is never required.

/**
 * Create a new ITFlow invoice from a QBO Invoice and map it immediately (so
 * the push worker never re-sends it back to QuickBooks as a "new" invoice).
 * Returns the new invoice_id, or 0 if the customer isn't linked yet.
 */
function aim_import_qbo_invoice(mysqli $mysqli, int $accounting_id, array $qi, string $invoice_prefix, string $default_currency, array $remote_to_local_client, array $remote_to_local_item, array $already_imported = []): int {
    $remote_id = (string) ($qi['Id'] ?? '');
    if ($remote_id !== '' && isset($already_imported[$remote_id])) {
        return 0; // already imported - guards double-click/multi-tab resubmits
    }

    $customer_id = (string) ($qi['CustomerRef']['value'] ?? '');
    if ($customer_id === '' || !isset($remote_to_local_client[$customer_id])) {
        return 0;
    }
    $client_id = $remote_to_local_client[$customer_id];

    $sync_token = (string) ($qi['SyncToken'] ?? '');
    $total      = round(floatval($qi['TotalAmt'] ?? 0), 2);
    $balance    = isset($qi['Balance']) ? round(floatval($qi['Balance']), 2) : $total;
    $txn_date   = (string) ($qi['TxnDate'] ?? date('Y-m-d'));
    $due_date   = (string) ($qi['DueDate'] ?? $txn_date);
    $currency   = (string) ($qi['CurrencyRef']['value'] ?? $default_currency);

    if ($balance <= 0) {
        $status = 'Paid';
    } elseif ($balance < $total) {
        $status = 'Partial';
    } else {
        $status = 'Sent';
    }

    // QBO reports a discount as its own Line (DetailType DiscountLineDetail);
    // ITFlow tracks it as a separate header amount rather than a line item.
    $discount = 0.0;
    foreach (($qi['Line'] ?? []) as $line) {
        if (($line['DetailType'] ?? '') === 'DiscountLineDetail') {
            $discount += floatval($line['Amount'] ?? 0);
        }
    }
    $discount = round($discount, 2);

    // QBO's sales tax is a single transaction-level figure (TxnTaxDetail.TotalTax),
    // not a per-line amount - allocate it across the SalesItemLineDetail lines
    // proportionally by their pre-tax Amount share, so item_total (which ITFlow
    // treats as tax-inclusive: item_total = item_subtotal + item_tax, see
    // agent/post/invoice.php's add_invoice_item) sums back to exactly TotalAmt
    // once the header is recomputed as SUM(item_total) - discount. Without this,
    // any tax on the invoice silently disappears the first time a line item is
    // added/edited through the normal UI.
    $total_tax  = round(floatval($qi['TxnTaxDetail']['TotalTax'] ?? 0), 2);
    $line_taxes = aim_allocate_line_tax($qi['Line'] ?? [], $total_tax);

    mysqli_begin_transaction($mysqli);
    try {
        // Atomically increment and get the new (local) invoice number - same
        // pattern as agent/post/invoice.php's add_invoice handler.
        mysqli_query($mysqli, "
            UPDATE settings
            SET config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
                config_invoice_next_number = config_invoice_next_number + 1
            WHERE company_id = 1
        ");
        $invoice_number = mysqli_insert_id($mysqli);
        $url_key        = randomString(32);

        $prefix_esc   = mysqli_real_escape_string($mysqli, $invoice_prefix);
        $status_esc   = mysqli_real_escape_string($mysqli, $status);
        $currency_esc = mysqli_real_escape_string($mysqli, $currency);
        $date_esc     = mysqli_real_escape_string($mysqli, $txn_date);
        $due_esc      = mysqli_real_escape_string($mysqli, $due_date);
        $url_key_esc  = mysqli_real_escape_string($mysqli, $url_key);

        mysqli_query(
            $mysqli,
            "INSERT INTO invoices
                (invoice_prefix, invoice_number, invoice_status, invoice_date, invoice_due,
                 invoice_discount_amount, invoice_amount, invoice_currency_code, invoice_category_id, invoice_url_key, invoice_client_id)
             VALUES
                ('$prefix_esc', $invoice_number, '$status_esc', '$date_esc', '$due_esc',
                 $discount, $total, '$currency_esc', 0, '$url_key_esc', $client_id)"
        );
        $invoice_id = mysqli_insert_id($mysqli);

        mysqli_query($mysqli, "INSERT INTO history SET history_status = '$status_esc', history_description = 'Imported from QuickBooks', history_invoice_id = $invoice_id");

        $order = 0;
        foreach (($qi['Line'] ?? []) as $i => $line) {
            if (($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue; // skip subtotal/discount/description-only lines
            }
            aim_import_line($mysqli, 'invoice_items', 'item_invoice_id', $invoice_id, $line, $order, $remote_to_local_item, $line_taxes[$i] ?? 0.0);
            $order++;
        }

        setAccountingMap($mysqli, $accounting_id, 'invoice', $invoice_id, $remote_id, $sync_token);
        accountingLog($mysqli, $accounting_id, 'invoice', $invoice_id, 'mapped', "Imported QBO invoice (Id $remote_id) as a new ITFlow invoice");

        mysqli_commit($mysqli);
        return $invoice_id;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

/**
 * Proportionally allocate a transaction's total tax across its
 * SalesItemLineDetail lines by each line's pre-tax Amount share (QBO reports
 * tax as one transaction-level figure, not per line). The last allocated line
 * absorbs the rounding remainder so the parts always sum to exactly $total_tax.
 * Returns [line-array-index => tax amount] using the same indices as $lines.
 */
function aim_allocate_line_tax(array $lines, float $total_tax): array {
    $subtotal = 0.0;
    $indices  = [];
    foreach ($lines as $i => $line) {
        if (($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
            $subtotal += floatval($line['Amount'] ?? 0);
            $indices[] = $i;
        }
    }
    $tax_by_index = [];
    if ($total_tax == 0.0 || $subtotal <= 0 || empty($indices)) {
        foreach ($indices as $i) {
            $tax_by_index[$i] = 0.0;
        }
        return $tax_by_index;
    }
    $allocated = 0.0;
    $last      = count($indices) - 1;
    foreach ($indices as $n => $i) {
        if ($n === $last) {
            $tax_by_index[$i] = round($total_tax - $allocated, 2);
        } else {
            $share = round($total_tax * (floatval($lines[$i]['Amount'] ?? 0) / $subtotal), 2);
            $tax_by_index[$i] = $share;
            $allocated += $share;
        }
    }
    return $tax_by_index;
}

/**
 * Create a new ITFlow quote from a QBO Estimate and map it immediately.
 * Returns the new quote_id, or 0 if the customer isn't linked yet.
 */
function aim_import_qbo_quote(mysqli $mysqli, int $accounting_id, array $qe, string $quote_prefix, string $default_currency, array $remote_to_local_client, array $remote_to_local_item, array $already_imported = []): int {
    $remote_id = (string) ($qe['Id'] ?? '');
    if ($remote_id !== '' && isset($already_imported[$remote_id])) {
        return 0; // already imported - guards double-click/multi-tab resubmits
    }

    $customer_id = (string) ($qe['CustomerRef']['value'] ?? '');
    if ($customer_id === '' || !isset($remote_to_local_client[$customer_id])) {
        return 0;
    }
    $client_id = $remote_to_local_client[$customer_id];

    $sync_token = (string) ($qe['SyncToken'] ?? '');
    $total      = round(floatval($qe['TotalAmt'] ?? 0), 2);
    $txn_date   = (string) ($qe['TxnDate'] ?? date('Y-m-d'));
    $expire     = (string) ($qe['ExpirationDate'] ?? '');
    $currency   = (string) ($qe['CurrencyRef']['value'] ?? $default_currency);

    // A LinkedTxn back to an Invoice is the authoritative "already invoiced"
    // signal; QBO's own TxnStatus values ("Accepted"/"Rejected"/"Closed") don't
    // reliably line up with that (a real "Closed" estimate observed live had no
    // invoice link at all, while the invoiced one was still marked "Accepted").
    // Fall back to 'Sent' (it already exists in QuickBooks, so not a local Draft).
    $already_invoiced = false;
    foreach (($qe['LinkedTxn'] ?? []) as $linked) {
        if (($linked['TxnType'] ?? '') === 'Invoice') {
            $already_invoiced = true;
            break;
        }
    }
    $txn_status = (string) ($qe['TxnStatus'] ?? '');
    if ($already_invoiced) {
        $status = 'Invoiced';
    } elseif ($txn_status === 'Accepted') {
        $status = 'Accepted';
    } elseif ($txn_status === 'Rejected') {
        $status = 'Declined';
    } else {
        $status = 'Sent';
    }

    // Same discount-line convention as invoices.
    $discount = 0.0;
    foreach (($qe['Line'] ?? []) as $line) {
        if (($line['DetailType'] ?? '') === 'DiscountLineDetail') {
            $discount += floatval($line['Amount'] ?? 0);
        }
    }
    $discount = round($discount, 2);

    // Same tax-allocation convention as invoices - see aim_allocate_line_tax().
    $total_tax  = round(floatval($qe['TxnTaxDetail']['TotalTax'] ?? 0), 2);
    $line_taxes = aim_allocate_line_tax($qe['Line'] ?? [], $total_tax);

    mysqli_begin_transaction($mysqli);
    try {
        mysqli_query($mysqli, "
            UPDATE settings
            SET config_quote_next_number = LAST_INSERT_ID(config_quote_next_number),
                config_quote_next_number = config_quote_next_number + 1
            WHERE company_id = 1
        ");
        $quote_number = mysqli_insert_id($mysqli);
        $url_key      = randomString(32);

        $prefix_esc   = mysqli_real_escape_string($mysqli, $quote_prefix);
        $status_esc   = mysqli_real_escape_string($mysqli, $status);
        $currency_esc = mysqli_real_escape_string($mysqli, $currency);
        $date_esc     = mysqli_real_escape_string($mysqli, $txn_date);
        $url_key_esc  = mysqli_real_escape_string($mysqli, $url_key);
        $expire_sql   = $expire !== '' ? "'" . mysqli_real_escape_string($mysqli, $expire) . "'" : 'NULL';

        mysqli_query(
            $mysqli,
            "INSERT INTO quotes
                (quote_prefix, quote_number, quote_status, quote_date, quote_expire,
                 quote_discount_amount, quote_amount, quote_currency_code, quote_category_id, quote_url_key, quote_client_id)
             VALUES
                ('$prefix_esc', $quote_number, '$status_esc', '$date_esc', $expire_sql,
                 $discount, $total, '$currency_esc', 0, '$url_key_esc', $client_id)"
        );
        $quote_id = mysqli_insert_id($mysqli);

        mysqli_query($mysqli, "INSERT INTO history SET history_status = '$status_esc', history_description = 'Imported from QuickBooks', history_quote_id = $quote_id");

        $order = 0;
        foreach (($qe['Line'] ?? []) as $i => $line) {
            if (($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            aim_import_line($mysqli, 'quote_items', 'item_quote_id', $quote_id, $line, $order, $remote_to_local_item, $line_taxes[$i] ?? 0.0);
            $order++;
        }

        setAccountingMap($mysqli, $accounting_id, 'quote', $quote_id, $remote_id, $sync_token);
        accountingLog($mysqli, $accounting_id, 'quote', $quote_id, 'mapped', "Imported QBO estimate (Id $remote_id) as a new ITFlow quote");

        mysqli_commit($mysqli);
        return $quote_id;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

/**
 * Shared invoice_items/quote_items row insert for one QBO SalesItemLineDetail
 * line. Line items are stored as a per-line snapshot (name/description/price),
 * so a local product mapping is used only to fill in item_product_id when one
 * exists - it is never required for the import to succeed.
 *
 * $item_tax is this line's share of the transaction's total tax (see
 * aim_allocate_line_tax()). ITFlow treats item_total as tax-inclusive
 * (item_total = item_subtotal + item_tax, matching agent/post/invoice.php's
 * add_invoice_item handler), so item_total here is NOT just the QBO line
 * Amount - it must include the allocated tax or the header total silently
 * drifts the next time a line item is added/edited through the normal UI.
 */
function aim_import_line(mysqli $mysqli, string $table, string $parent_col, int $parent_id, array $line, int $order, array $remote_to_local_item, float $item_tax = 0.0): void {
    $detail      = $line['SalesItemLineDetail'] ?? [];
    $item_ref_id = (string) ($detail['ItemRef']['value'] ?? '');
    $product_id  = $remote_to_local_item[$item_ref_id] ?? 0;
    $qty         = isset($detail['Qty']) ? floatval($detail['Qty']) : 1.0;
    $line_amount = round(floatval($line['Amount'] ?? 0), 2);
    $item_total  = round($line_amount + $item_tax, 2);
    $unit_price  = isset($detail['UnitPrice']) ? round(floatval($detail['UnitPrice']), 2) : ($qty > 0 ? round($line_amount / $qty, 2) : $line_amount);
    $item_name   = (string) ($detail['ItemRef']['name'] ?? ($line['Description'] ?? 'QuickBooks line item'));
    $item_desc   = (string) ($line['Description'] ?? '');

    $name_esc = mysqli_real_escape_string($mysqli, substr($item_name, 0, 200));
    $desc_esc = mysqli_real_escape_string($mysqli, $item_desc);
    $table_esc = $table === 'quote_items' ? 'quote_items' : 'invoice_items'; // whitelist, never user input

    mysqli_query(
        $mysqli,
        "INSERT INTO $table_esc
            (item_name, item_description, item_quantity, item_price, item_subtotal, item_tax, item_total, item_order, item_product_id, $parent_col)
         VALUES
            ('$name_esc', '$desc_esc', $qty, $unit_price, $line_amount, $item_tax, $item_total, $order, $product_id, $parent_id)"
    );
}

/**
 * Create one or more new ITFlow payments from a QBO Payment and map each
 * immediately. Returns the number of local payments created (0 if none of its
 * linked invoices are in ITFlow yet, or it's already been imported).
 *
 * A QBO payment can apply to several invoices at once (Line[].LinkedTxn, one
 * entry per invoice it was applied to); ITFlow's payment model ties one
 * payment record to exactly one invoice, so a split payment becomes multiple
 * local payment records - each carrying only that invoice's own Line.Amount
 * share, never the whole payment TotalAmt (crediting the full amount to a
 * single invoice would both overpay that invoice and silently lose track of
 * whatever the other invoices were actually owed).
 */
function aim_import_qbo_payment(mysqli $mysqli, int $accounting_id, array $qp, int $default_account_id, string $default_currency, array $remote_to_local_invoice, array $already_imported = []): int {
    $remote_id = (string) ($qp['Id'] ?? '');
    if ($remote_id !== '' && isset($already_imported[$remote_id])) {
        return 0; // already imported - guards double-click/multi-tab resubmits
    }

    $sync_token = (string) ($qp['SyncToken'] ?? '');
    $txn_date   = (string) ($qp['TxnDate'] ?? date('Y-m-d'));
    $currency   = (string) ($qp['CurrencyRef']['value'] ?? $default_currency);
    $method     = (string) ($qp['PaymentMethodRef']['name'] ?? 'QuickBooks');
    $ref_no     = (string) ($qp['PaymentRefNum'] ?? '');

    $date_esc     = mysqli_real_escape_string($mysqli, $txn_date);
    $currency_esc = mysqli_real_escape_string($mysqli, $currency);
    $method_esc   = mysqli_real_escape_string($mysqli, substr($method, 0, 200));
    $ref_esc      = mysqli_real_escape_string($mysqli, substr($ref_no !== '' ? $ref_no : "QuickBooks Payment $remote_id", 0, 200));

    mysqli_begin_transaction($mysqli);
    try {
        $created = 0;
        foreach (($qp['Line'] ?? []) as $line) {
            foreach (($line['LinkedTxn'] ?? []) as $linked) {
                if (($linked['TxnType'] ?? '') !== 'Invoice') {
                    continue;
                }
                $remote_invoice_id = (string) ($linked['TxnId'] ?? '');
                if (!isset($remote_to_local_invoice[$remote_invoice_id])) {
                    continue; // that invoice isn't in ITFlow yet - skip just its share
                }
                $invoice_id  = $remote_to_local_invoice[$remote_invoice_id];
                $line_amount = round(floatval($line['Amount'] ?? 0), 2);

                mysqli_query(
                    $mysqli,
                    "INSERT INTO payments
                        (payment_date, payment_amount, payment_currency_code, payment_account_id, payment_method, payment_reference, payment_invoice_id)
                     VALUES
                        ('$date_esc', $line_amount, '$currency_esc', $default_account_id, '$method_esc', '$ref_esc', $invoice_id)"
                );
                $payment_id = mysqli_insert_id($mysqli);

                // Recompute the invoice's paid status from ALL of its local
                // payments - same rule agent/post/payment.php's add_payment uses.
                $sum_row    = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(payment_amount) AS total_paid FROM payments WHERE payment_invoice_id = $invoice_id"));
                $total_paid = floatval($sum_row['total_paid'] ?? 0);
                $inv_amount = floatval(getFieldById('invoices', $invoice_id, 'invoice_amount'));
                $new_status = ($inv_amount - $total_paid) <= 0 ? 'Paid' : 'Partial';
                $new_status_esc = mysqli_real_escape_string($mysqli, $new_status);
                mysqli_query($mysqli, "UPDATE invoices SET invoice_status = '$new_status_esc' WHERE invoice_id = $invoice_id AND invoice_status NOT IN ('Cancelled','Non-Billable')");

                setAccountingMap($mysqli, $accounting_id, 'payment', $payment_id, $remote_id, $sync_token);
                accountingLog($mysqli, $accounting_id, 'payment', $payment_id, 'mapped', "Imported QBO payment (Id $remote_id) as a new ITFlow payment");
                $created++;
            }
        }

        mysqli_commit($mysqli);
        return $created;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

/**
 * First non-archived financial account, used as the default deposit account
 * for imported payments (QBO payments don't reliably carry a usable local
 * account reference).
 */
function ass_default_account_id(mysqli $mysqli): int {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT account_id FROM accounts WHERE account_archived_at IS NULL ORDER BY account_id ASC LIMIT 1"));
    return $row ? intval($row['account_id']) : 0;
}

// ── POST: import one QBO-only invoice as a new ITFlow invoice ───────────────
if (isset($_POST['import_qbo_invoice'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $remote_id = trim((string) ($_POST['qbo_invoice_id'] ?? ''));
    if ($remote_id === '') {
        flash_alert("No QuickBooks invoice was specified.", 'error');
        redirect($sync_status_path);
    }
    try {
        $qbo_pull = new QboClient($mysqli, $integration);
        $found = null;
        foreach ($qbo_pull->searchInvoices(1000) as $qi) {
            if ((string) $qi['Id'] === $remote_id) { $found = $qi; break; }
        }
        if (!$found) {
            flash_alert("That invoice could not be found in QuickBooks anymore.", 'error');
            redirect($sync_status_path);
        }
        $already_imported       = getRemoteToLocalMap($mysqli, $accounting_id, 'invoice');
        $remote_to_local_client = getRemoteToLocalMap($mysqli, $accounting_id, 'client');
        $remote_to_local_item   = getRemoteToLocalMap($mysqli, $accounting_id, 'item');
        $new_id = aim_import_qbo_invoice($mysqli, $accounting_id, $found, (string) $config_invoice_prefix, (string) $session_company_currency, $remote_to_local_client, $remote_to_local_item, $already_imported);
        if ($new_id > 0) {
            logAction("Accounting", "Create", "$session_name imported QuickBooks invoice (Id $remote_id) into ITFlow");
            flash_alert("Imported invoice from QuickBooks.");
        } elseif (isset($already_imported[$remote_id])) {
            flash_alert("That invoice was already imported.", 'warning');
        } else {
            flash_alert("Could not import - that QuickBooks customer isn't linked to an ITFlow client yet. Map it on the Client Mapping tab first.", 'warning');
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
    }
    redirect($sync_status_path);
}

// ── POST: import every currently-unmapped QBO invoice in one go ─────────────
if (isset($_POST['import_all_qbo_invoices'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $imported = 0; $skipped = 0; $failed = 0;
    try {
        $qbo_bulk = new QboClient($mysqli, $integration);
        $existing = getRemoteToLocalMap($mysqli, $accounting_id, 'invoice');
        $remote_to_local_client = getRemoteToLocalMap($mysqli, $accounting_id, 'client');
        $remote_to_local_item   = getRemoteToLocalMap($mysqli, $accounting_id, 'item');
        foreach ($qbo_bulk->searchInvoices(1000) as $qi) {
            if (isset($existing[(string) $qi['Id']])) { continue; }
            try {
                $new_id = aim_import_qbo_invoice($mysqli, $accounting_id, $qi, (string) $config_invoice_prefix, (string) $session_company_currency, $remote_to_local_client, $remote_to_local_item, $existing);
                if ($new_id > 0) { $imported++; } else { $skipped++; }
            } catch (Throwable $e) {
                $failed++;
            }
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
        redirect($sync_status_path);
    }
    logAction("Accounting", "Create", "$session_name bulk-imported $imported invoice(s) from QuickBooks" . ($skipped ? ", $skipped skipped" : "") . ($failed ? ", $failed failed" : ""));
    flash_alert("Imported $imported invoice(s) from QuickBooks" . ($skipped ? ", $skipped skipped (customer not linked)" : "") . ($failed ? ", $failed failed" : "") . ".");
    redirect($sync_status_path);
}

// ── POST: import one QBO-only estimate as a new ITFlow quote ────────────────
if (isset($_POST['import_qbo_quote'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $remote_id = trim((string) ($_POST['qbo_quote_id'] ?? ''));
    if ($remote_id === '') {
        flash_alert("No QuickBooks estimate was specified.", 'error');
        redirect($sync_status_path);
    }
    try {
        $qbo_pull = new QboClient($mysqli, $integration);
        $found = null;
        foreach ($qbo_pull->searchEstimates(1000) as $qe) {
            if ((string) $qe['Id'] === $remote_id) { $found = $qe; break; }
        }
        if (!$found) {
            flash_alert("That estimate could not be found in QuickBooks anymore.", 'error');
            redirect($sync_status_path);
        }
        $already_imported       = getRemoteToLocalMap($mysqli, $accounting_id, 'quote');
        $remote_to_local_client = getRemoteToLocalMap($mysqli, $accounting_id, 'client');
        $remote_to_local_item   = getRemoteToLocalMap($mysqli, $accounting_id, 'item');
        $new_id = aim_import_qbo_quote($mysqli, $accounting_id, $found, (string) $config_quote_prefix, (string) $session_company_currency, $remote_to_local_client, $remote_to_local_item, $already_imported);
        if ($new_id > 0) {
            logAction("Accounting", "Create", "$session_name imported QuickBooks estimate (Id $remote_id) into ITFlow");
            flash_alert("Imported estimate from QuickBooks.");
        } elseif (isset($already_imported[$remote_id])) {
            flash_alert("That estimate was already imported.", 'warning');
        } else {
            flash_alert("Could not import - that QuickBooks customer isn't linked to an ITFlow client yet. Map it on the Client Mapping tab first.", 'warning');
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
    }
    redirect($sync_status_path);
}

// ── POST: import every currently-unmapped QBO estimate in one go ────────────
if (isset($_POST['import_all_qbo_quotes'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $imported = 0; $skipped = 0; $failed = 0;
    try {
        $qbo_bulk = new QboClient($mysqli, $integration);
        $existing = getRemoteToLocalMap($mysqli, $accounting_id, 'quote');
        $remote_to_local_client = getRemoteToLocalMap($mysqli, $accounting_id, 'client');
        $remote_to_local_item   = getRemoteToLocalMap($mysqli, $accounting_id, 'item');
        foreach ($qbo_bulk->searchEstimates(1000) as $qe) {
            if (isset($existing[(string) $qe['Id']])) { continue; }
            try {
                $new_id = aim_import_qbo_quote($mysqli, $accounting_id, $qe, (string) $config_quote_prefix, (string) $session_company_currency, $remote_to_local_client, $remote_to_local_item, $existing);
                if ($new_id > 0) { $imported++; } else { $skipped++; }
            } catch (Throwable $e) {
                $failed++;
            }
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
        redirect($sync_status_path);
    }
    logAction("Accounting", "Create", "$session_name bulk-imported $imported estimate(s) from QuickBooks" . ($skipped ? ", $skipped skipped" : "") . ($failed ? ", $failed failed" : ""));
    flash_alert("Imported $imported estimate(s) from QuickBooks" . ($skipped ? ", $skipped skipped (customer not linked)" : "") . ($failed ? ", $failed failed" : "") . ".");
    redirect($sync_status_path);
}

// ── POST: import one QBO-only payment as a new ITFlow payment ───────────────
if (isset($_POST['import_qbo_payment'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $remote_id = trim((string) ($_POST['qbo_payment_id'] ?? ''));
    if ($remote_id === '') {
        flash_alert("No QuickBooks payment was specified.", 'error');
        redirect($sync_status_path);
    }
    try {
        $qbo_pull = new QboClient($mysqli, $integration);
        $found = null;
        foreach ($qbo_pull->searchPayments(1000) as $qp) {
            if ((string) $qp['Id'] === $remote_id) { $found = $qp; break; }
        }
        if (!$found) {
            flash_alert("That payment could not be found in QuickBooks anymore.", 'error');
            redirect($sync_status_path);
        }
        $already_imported        = getRemoteToLocalMap($mysqli, $accounting_id, 'payment');
        $remote_to_local_invoice = getRemoteToLocalMap($mysqli, $accounting_id, 'invoice');
        $new_id = aim_import_qbo_payment($mysqli, $accounting_id, $found, ass_default_account_id($mysqli), (string) $session_company_currency, $remote_to_local_invoice, $already_imported);
        if ($new_id > 0) {
            logAction("Accounting", "Create", "$session_name imported QuickBooks payment (Id $remote_id) into ITFlow");
            flash_alert("Imported payment from QuickBooks.");
        } elseif (isset($already_imported[$remote_id])) {
            flash_alert("That payment was already imported.", 'warning');
        } else {
            flash_alert("Could not import - its linked invoice isn't in ITFlow yet. Import or sync that invoice first.", 'warning');
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
    }
    redirect($sync_status_path);
}

// ── POST: import every currently-unmapped QBO payment in one go ─────────────
if (isset($_POST['import_all_qbo_payments'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!$is_connected) {
        flash_alert("QuickBooks Online is not connected.", 'error');
        redirect($sync_status_path);
    }
    $imported = 0; $skipped = 0; $failed = 0;
    try {
        $qbo_bulk = new QboClient($mysqli, $integration);
        $existing = getRemoteToLocalMap($mysqli, $accounting_id, 'payment');
        $remote_to_local_invoice = getRemoteToLocalMap($mysqli, $accounting_id, 'invoice');
        $default_account_id = ass_default_account_id($mysqli);
        foreach ($qbo_bulk->searchPayments(1000) as $qp) {
            if (isset($existing[(string) $qp['Id']])) { continue; }
            try {
                $new_id = aim_import_qbo_payment($mysqli, $accounting_id, $qp, $default_account_id, (string) $session_company_currency, $remote_to_local_invoice, $existing);
                if ($new_id > 0) { $imported++; } else { $skipped++; }
            } catch (Throwable $e) {
                $failed++;
            }
        }
    } catch (Throwable $e) {
        flash_alert("Could not reach QuickBooks: " . $e->getMessage(), 'error');
        redirect($sync_status_path);
    }
    logAction("Accounting", "Create", "$session_name bulk-imported $imported payment(s) from QuickBooks" . ($skipped ? ", $skipped skipped" : "") . ($failed ? ", $failed failed" : ""));
    flash_alert("Imported $imported payment(s) from QuickBooks" . ($skipped ? ", $skipped skipped (invoice not in ITFlow)" : "") . ($failed ? ", $failed failed" : "") . ".");
    redirect($sync_status_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// Render
// ═══════════════════════════════════════════════════════════════════════════

require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

$active_tab = in_array($_GET['tab'] ?? '', ['connection', 'client_mapping', 'item_mapping', 'sync_status'], true)
    ? $_GET['tab']
    : 'connection';

// ── Connection tab data ───────────────────────────────────────────────────────
$acc_client_id   = $integration['accounting_client_id'] ?? '';
$acc_environment = $integration['accounting_environment'] ?? 'production';
$acc_income_acct = $integration['accounting_default_income_account_id'] ?? '';
$acc_auto_push   = isset($integration['accounting_auto_push']) ? intval($integration['accounting_auto_push']) : 1;
$acc_enabled     = isset($integration['accounting_enabled']) ? intval($integration['accounting_enabled']) : 0;
$acc_realm       = $integration['accounting_realm_id'] ?? '';
$acc_expires     = $integration['accounting_token_expires_at'] ?? '';
$acc_connected_at = $integration['accounting_connected_at'] ?? '';
$has_secret      = !empty($integration['accounting_client_secret']);

$module_on = ($config_module_enable_accounting ?? 0) == 1;

// Recent sync log + queue counts (Connection tab summary).
$log_rows = [];
$conn_counts = ['pending' => 0, 'delivered' => 0, 'failed' => 0];
if ($integration) {
    $lres = mysqli_query($mysqli, "SELECT * FROM accounting_sync_log WHERE log_accounting_id = $accounting_id ORDER BY log_id DESC LIMIT 15");
    while ($lres && ($lr = mysqli_fetch_assoc($lres))) {
        $log_rows[] = $lr;
    }
    $cres = mysqli_query($mysqli, "SELECT queue_status, COUNT(*) AS n FROM accounting_sync_queue WHERE queue_accounting_id = $accounting_id GROUP BY queue_status");
    while ($cres && ($cr = mysqli_fetch_assoc($cres))) {
        $conn_counts[$cr['queue_status']] = intval($cr['n']);
    }
}

// ── Client mapping tab data ───────────────────────────────────────────────────
$clients = [];
$cres = mysqli_query(
    $mysqli,
    "SELECT client_id, client_name
     FROM clients
     WHERE client_archived_at IS NULL AND client_lead = 0
     ORDER BY client_name ASC"
);
while ($cres && ($c = mysqli_fetch_assoc($cres))) {
    $clients[] = $c;
}

$client_mappings = $accounting_id > 0 ? getAllClientMappings($mysqli, $accounting_id) : [];
$total_clients = count($clients);
$mapped_client_count = 0;
foreach ($clients as $c) {
    if (isset($client_mappings[intval($c['client_id'])])) {
        $mapped_client_count++;
    }
}

// ── Item mapping tab data ─────────────────────────────────────────────────────
$products = [];
$pres = mysqli_query(
    $mysqli,
    "SELECT product_id, product_name, product_type, product_price, product_currency_code
     FROM products
     WHERE product_archived_at IS NULL
     ORDER BY product_type ASC, product_name ASC"
);
while ($pres && ($p = mysqli_fetch_assoc($pres))) {
    $products[] = $p;
}

$item_mappings = $accounting_id > 0 ? getAllItemMappings($mysqli, $accounting_id) : [];
$total_products = count($products);
$mapped_product_count = 0;
foreach ($products as $p) {
    if (isset($item_mappings[intval($p['product_id'])])) {
        $mapped_product_count++;
    }
}

// QBO items that have no corresponding ITFlow product/service yet ("pull"
// direction - everything else on this tab is ITFlow -> QBO). Best-effort: a
// QBO API hiccup here should never break the rest of the page.
$qbo_unmapped_items = [];
$qbo_pull_error = '';
if ($is_connected) {
    $mapped_remote_ids = [];
    foreach ($item_mappings as $m) {
        if (!empty($m['map_remote_id'])) {
            $mapped_remote_ids[(string) $m['map_remote_id']] = true;
        }
    }
    try {
        $qbo_for_pull = new QboClient($mysqli, $integration);
        foreach ($qbo_for_pull->searchItems('', 1000) as $qi) {
            if (!isset($mapped_remote_ids[(string) $qi['Id']])) {
                $qbo_unmapped_items[] = $qi;
            }
        }
    } catch (Throwable $e) {
        $qbo_pull_error = $e->getMessage();
    }
}

// ── Sync status tab data ──────────────────────────────────────────────────────

/**
 * Key accounting_entity_map rows of one local type by local_id.
 */
function ass_map_by_id(mysqli $mysqli, int $accounting_id, string $type): array {
    $out = [];
    if ($accounting_id <= 0) {
        return $out;
    }
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $res = mysqli_query(
        $mysqli,
        "SELECT map_local_id, map_remote_id, map_remote_name, map_last_synced_at
         FROM accounting_entity_map
         WHERE map_accounting_id = $accounting_id AND map_local_type = '$type_esc'"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[intval($row['map_local_id'])] = $row;
    }
    return $out;
}

/**
 * Key accounting_sync_queue rows of one local type by local_id.
 */
function ass_queue_by_id(mysqli $mysqli, int $accounting_id, string $type): array {
    $out = [];
    if ($accounting_id <= 0) {
        return $out;
    }
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $res = mysqli_query(
        $mysqli,
        "SELECT queue_id, queue_local_id, queue_status, queue_attempts, queue_last_error, queue_next_attempt_at, queue_delivered_at
         FROM accounting_sync_queue
         WHERE queue_accounting_id = $accounting_id AND queue_local_type = '$type_esc'"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[intval($row['queue_local_id'])] = $row;
    }
    return $out;
}

/**
 * Latest sync-log message per local_id for one local type.
 */
function ass_last_log_by_id(mysqli $mysqli, int $accounting_id, string $type): array {
    $out = [];
    if ($accounting_id <= 0) {
        return $out;
    }
    $type_esc = mysqli_real_escape_string($mysqli, $type);
    $res = mysqli_query(
        $mysqli,
        "SELECT log_local_id, log_status, log_message, log_created_at
         FROM accounting_sync_log
         WHERE log_accounting_id = $accounting_id AND log_local_type = '$type_esc'
         ORDER BY log_id DESC"
    );
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $lid = intval($row['log_local_id']);
        if (!isset($out[$lid])) {
            $out[$lid] = $row; // first seen == most recent
        }
    }
    return $out;
}

/**
 * Render a Bootstrap badge for a queue row (or "—" when there is none).
 */
function ass_queue_badge(?array $q): string {
    if (!$q) {
        return '<span class="text-muted">&mdash;</span>';
    }
    $status = (string) $q['queue_status'];
    $map = ['pending' => 'text-bg-info', 'delivered' => 'text-bg-success', 'failed' => 'text-bg-danger'];
    $cls = $map[$status] ?? 'text-bg-secondary';
    $attempts = intval($q['queue_attempts']);
    $html = '<span class="badge ' . $cls . '">' . nullable_htmlentities(ucfirst($status)) . '</span>';
    if ($attempts > 0) {
        $html .= ' <span class="text-muted small">' . $attempts . ' attempt' . ($attempts === 1 ? '' : 's') . '</span>';
    }
    if ($status === 'pending' && !empty($q['queue_next_attempt_at'])) {
        $html .= ' <span class="text-muted small">next ' . nullable_htmlentities($q['queue_next_attempt_at']) . '</span>';
    }
    if (!empty($q['queue_last_error'])) {
        $html .= '<br><span class="text-danger small">' . nullable_htmlentities(mb_strimwidth((string) $q['queue_last_error'], 0, 120, '…')) . '</span>';
    }
    return $html;
}

// Recent invoices (+ client) and payments (+ invoice/client).
$invoices = [];
$ires = mysqli_query(
    $mysqli,
    "SELECT i.invoice_id, i.invoice_prefix, i.invoice_number, i.invoice_status,
            i.invoice_amount, i.invoice_currency_code, i.invoice_date, c.client_name
     FROM invoices i
     LEFT JOIN clients c ON c.client_id = i.invoice_client_id
     WHERE i.invoice_archived_at IS NULL
     ORDER BY i.invoice_id DESC
     LIMIT 100"
);
while ($ires && ($row = mysqli_fetch_assoc($ires))) {
    $invoices[] = $row;
}

$payments = [];
$syncpay_res = mysqli_query(
    $mysqli,
    "SELECT p.payment_id, p.payment_amount, p.payment_currency_code, p.payment_date,
            p.payment_invoice_id, i.invoice_prefix, i.invoice_number, c.client_name
     FROM payments p
     LEFT JOIN invoices i ON i.invoice_id = p.payment_invoice_id
     LEFT JOIN clients c ON c.client_id = i.invoice_client_id
     WHERE p.payment_archived_at IS NULL
     ORDER BY p.payment_id DESC
     LIMIT 100"
);
while ($syncpay_res && ($row = mysqli_fetch_assoc($syncpay_res))) {
    $payments[] = $row;
}

$quotes = [];
$qtres = mysqli_query(
    $mysqli,
    "SELECT q.quote_id, q.quote_prefix, q.quote_number, q.quote_status,
            q.quote_amount, q.quote_currency_code, q.quote_date, c.client_name
     FROM quotes q
     LEFT JOIN clients c ON c.client_id = q.quote_client_id
     WHERE q.quote_archived_at IS NULL
     ORDER BY q.quote_id DESC
     LIMIT 100"
);
while ($qtres && ($row = mysqli_fetch_assoc($qtres))) {
    $quotes[] = $row;
}

$inv_map   = ass_map_by_id($mysqli, $accounting_id, 'invoice');
$pay_map   = ass_map_by_id($mysqli, $accounting_id, 'payment');
$quote_map = ass_map_by_id($mysqli, $accounting_id, 'quote');
$inv_queue = ass_queue_by_id($mysqli, $accounting_id, 'invoice');
$pay_queue = ass_queue_by_id($mysqli, $accounting_id, 'payment');
$quote_queue = ass_queue_by_id($mysqli, $accounting_id, 'quote');
$inv_log   = ass_last_log_by_id($mysqli, $accounting_id, 'invoice');
$pay_log   = ass_last_log_by_id($mysqli, $accounting_id, 'payment');
$quote_log = ass_last_log_by_id($mysqli, $accounting_id, 'quote');

// Queue summary counts (Sync Status tab).
$sync_counts = ['pending' => 0, 'delivered' => 0, 'failed' => 0];
if ($accounting_id > 0) {
    $cres = mysqli_query($mysqli, "SELECT queue_status, COUNT(*) AS n FROM accounting_sync_queue WHERE queue_accounting_id = $accounting_id GROUP BY queue_status");
    while ($cres && ($cr = mysqli_fetch_assoc($cres))) {
        $sync_counts[$cr['queue_status']] = intval($cr['n']);
    }
}

// QBO invoices/estimates/payments that have no corresponding ITFlow record yet
// ("pull" direction, mirrors the Item Mapping tab's QBO-item pull). Best-effort:
// a QBO API hiccup here should never break the rest of the page.
$remote_to_local_client_pull  = $accounting_id > 0 ? getRemoteToLocalMap($mysqli, $accounting_id, 'client') : [];
$inv_map_by_remote            = $accounting_id > 0 ? getRemoteToLocalMap($mysqli, $accounting_id, 'invoice') : [];
$quote_map_by_remote          = $accounting_id > 0 ? getRemoteToLocalMap($mysqli, $accounting_id, 'quote') : [];
$pay_map_by_remote            = $accounting_id > 0 ? getRemoteToLocalMap($mysqli, $accounting_id, 'payment') : [];

$qbo_unmapped_invoices = [];
$qbo_invoice_pull_error = '';
$qbo_unmapped_quotes = [];
$qbo_quote_pull_error = '';
$qbo_unmapped_payments = [];
$qbo_payment_pull_error = '';
if ($is_connected) {
    try {
        $qbo_for_pull = new QboClient($mysqli, $integration);
        foreach ($qbo_for_pull->searchInvoices(1000) as $qi) {
            if (!isset($inv_map_by_remote[(string) $qi['Id']])) {
                $qbo_unmapped_invoices[] = $qi;
            }
        }
    } catch (Throwable $e) {
        $qbo_invoice_pull_error = $e->getMessage();
    }
    try {
        $qbo_for_pull = new QboClient($mysqli, $integration);
        foreach ($qbo_for_pull->searchEstimates(1000) as $qe) {
            if (!isset($quote_map_by_remote[(string) $qe['Id']])) {
                $qbo_unmapped_quotes[] = $qe;
            }
        }
    } catch (Throwable $e) {
        $qbo_quote_pull_error = $e->getMessage();
    }
    try {
        $qbo_for_pull = new QboClient($mysqli, $integration);
        foreach ($qbo_for_pull->searchPayments(1000) as $qp) {
            if (!isset($pay_map_by_remote[(string) $qp['Id']])) {
                $qbo_unmapped_payments[] = $qp;
            }
        }
    } catch (Throwable $e) {
        $qbo_payment_pull_error = $e->getMessage();
    }
}

// Safe currency formatter (mirrors functions.php): use the company NumberFormatter
// set up by inc_all_admin, falling back to number_format if intl is unavailable.
$money = static function ($v, $ccy) use ($currency_format) {
    $ccy = $ccy !== '' ? $ccy : 'USD';
    if (isset($currency_format) && $currency_format instanceof NumberFormatter) {
        return numfmt_format_currency($currency_format, (float) $v, $ccy);
    }
    return number_format((float) $v, 2);
};
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Accounting <small class="text-muted">QuickBooks Online</small></h4>
</div>

<?php if (!$module_on): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-1"></i>
    The Invoicing / Accounting module is currently <strong>disabled</strong>. Enable it under
    <a href="settings_module.php">Settings &raquo; Modules</a> for the sync to run.
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" id="accountingTabs">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'connection' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-connection" data-tabkey="connection">
            <i class="fas fa-plug me-1"></i>Connection
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'client_mapping' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-client_mapping" data-tabkey="client_mapping">
            <i class="fas fa-people-arrows me-1"></i>Client Mapping
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'item_mapping' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-item_mapping" data-tabkey="item_mapping">
            <i class="fas fa-boxes me-1"></i>Item Mapping
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'sync_status' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-sync_status" data-tabkey="sync_status">
            <i class="fas fa-sync-alt me-1"></i>Sync Status
        </a>
    </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════════════════════
     CONNECTION TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'connection' ? 'show active' : '' ?>" id="tab-connection">

    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-plug me-2"></i>Connection Status</h3>
            <?php if ($is_connected): ?>
                <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i>Connected</span>
            <?php else: ?>
                <span class="badge text-bg-secondary"><i class="fas fa-times-circle me-1"></i>Not Connected</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($is_connected): ?>
                <ul class="list-unstyled mb-3">
                    <li><strong>QBO Company (Realm) ID:</strong> <?php echo nullable_htmlentities($acc_realm); ?></li>
                    <li><strong>Environment:</strong> <?php echo nullable_htmlentities(ucfirst($acc_environment)); ?></li>
                    <li><strong>Connected at:</strong> <?php echo nullable_htmlentities($acc_connected_at); ?></li>
                    <li><strong>Access token expires:</strong> <?php echo nullable_htmlentities($acc_expires); ?> <small class="text-muted">(auto-refreshed)</small></li>
                    <li><strong>Auto-push:</strong> <?php echo $acc_auto_push ? 'On' : 'Off'; ?></li>
                    <li><strong>Sync enabled:</strong> <?php echo $acc_enabled ? 'Yes' : 'Paused'; ?></li>
                </ul>
                <div class="mb-2">
                    <span class="badge text-bg-info">Pending: <?php echo intval($conn_counts['pending']); ?></span>
                    <span class="badge text-bg-success">Delivered: <?php echo intval($conn_counts['delivered']); ?></span>
                    <span class="badge text-bg-danger">Failed: <?php echo intval($conn_counts['failed']); ?></span>
                </div>
                <a href="oauth_quickbooks_connect.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-sync me-1"></i>Reconnect
                </a>
                <a href="settings_accounting.php?disconnect=1&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"
                   class="btn btn-outline-danger btn-sm confirm-link">
                    <i class="fas fa-unlink me-1"></i>Disconnect
                </a>
                <a href="settings_accounting.php?tab=client_mapping" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-people-arrows me-1"></i>Client Mapping
                </a>
                <a href="settings_accounting.php?tab=item_mapping" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-boxes me-1"></i>Item Mapping
                </a>
                <a href="settings_accounting.php?tab=sync_status" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-sync-alt me-1"></i>Sync Status
                </a>
            <?php else: ?>
                <p class="text-muted">Save your QuickBooks app credentials below, then connect. One-way sync pushes ITFlow customers, items, invoices and payments to QuickBooks Online.</p>
                <?php if (!empty($acc_client_id) && $has_secret): ?>
                    <a href="oauth_quickbooks_connect.php" class="btn btn-success">
                        <i class="fas fa-plug me-1"></i>Connect to QuickBooks
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-success" disabled>
                        <i class="fas fa-plug me-1"></i>Connect to QuickBooks
                    </button>
                    <small class="d-block text-muted mt-1">Enter and save a Client ID and Client Secret to enable connecting.</small>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-key me-2"></i>QuickBooks App Credentials</h3>
        </div>
        <div class="card-body">
            <form action="settings_accounting.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" class="form-control" name="accounting_client_id"
                           value="<?php echo nullable_htmlentities($acc_client_id); ?>"
                           placeholder="Intuit app Client ID">
                </div>

                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" class="form-control" name="accounting_client_secret"
                           placeholder="<?php echo $has_secret ? 'Stored - leave blank to keep current' : 'Intuit app Client Secret'; ?>">
                    <small class="form-text text-muted">Stored encrypted. Leave blank to keep the existing secret.</small>
                </div>

                <div class="form-group">
                    <label>Environment</label>
                    <select class="form-control" name="accounting_environment">
                        <option value="production" <?php echo $acc_environment === 'production' ? 'selected' : ''; ?>>Production</option>
                        <option value="sandbox" <?php echo $acc_environment === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Default Income Account ID <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" name="accounting_default_income_account_id"
                           value="<?php echo nullable_htmlentities($acc_income_acct); ?>"
                           placeholder="QBO income account Id used for new items">
                    <small class="form-text text-muted">Used when creating new QuickBooks items. If blank, the first active Income account is used.</small>
                </div>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="accAutoPush" name="accounting_auto_push" value="1" <?php echo $acc_auto_push ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="accAutoPush">Auto-push finalized invoices &amp; payments</label>
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="accEnabled" name="accounting_enabled" value="1" <?php echo $acc_enabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="accEnabled">Sync enabled</label>
                    </div>
                    <small class="form-text text-muted">Turn off to pause pushing without disconnecting.</small>
                </div>

                <button type="submit" name="save_accounting_settings" class="btn btn-primary text-bold">
                    <i class="fas fa-check me-2"></i>Save
                </button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-list me-2"></i>Recent Sync Activity</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Local ID</th>
                            <th>Status</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log_rows)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No sync activity yet.</td></tr>
                        <?php else: foreach ($log_rows as $lr): ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($lr['log_created_at']); ?></td>
                                <td><?php echo nullable_htmlentities($lr['log_local_type']); ?></td>
                                <td><?php echo intval($lr['log_local_id']); ?></td>
                                <td><?php echo nullable_htmlentities($lr['log_status']); ?></td>
                                <td><small><?php echo nullable_htmlentities($lr['log_message']); ?></small></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /#tab-connection -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     CLIENT MAPPING TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'client_mapping' ? 'show active' : '' ?>" id="tab-client_mapping">

    <?php if (!$is_connected): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-1"></i>
        QuickBooks Online is <strong>not connected</strong>. Connect it under the
        <a href="settings_accounting.php?tab=connection">Connection tab</a> before mapping clients to QuickBooks customers.
    </div>
    <?php endif; ?>

    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-link me-2"></i>Client &rarr; QuickBooks Customer</h3>
            <span class="badge text-bg-info"><?php echo intval($mapped_client_count); ?> of <?php echo intval($total_clients); ?> clients mapped</span>
        </div>
        <div class="card-body p-0">
            <?php if ($total_clients === 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-users fa-3x mb-3"></i>
                    <p class="mb-0">No active clients to map.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">ITFlow Client</th>
                        <th>QuickBooks Customer</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c):
                    $cid    = intval($c['client_id']);
                    $cname  = $c['client_name'];
                    $map    = $client_mappings[$cid] ?? null;
                    $is_mapped = $map && !empty($map['map_remote_id']);
                ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?php echo nullable_htmlentities($cname); ?></td>
                        <td>
                            <?php if ($is_mapped): ?>
                                <span class="badge text-bg-success me-1"><i class="fas fa-check-circle me-1"></i>Mapped</span>
                                <?php if (!empty($map['map_remote_name'])): ?>
                                    <span class="fw-semibold"><?php echo nullable_htmlentities($map['map_remote_name']); ?></span>
                                <?php endif; ?>
                                <span class="text-muted small">(QBO Id <?php echo nullable_htmlentities($map['map_remote_id']); ?>)</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><i class="fas fa-unlink me-1"></i>Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3" style="white-space:nowrap">
                            <?php if ($is_mapped): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                                    <button type="submit" name="unlink_client" class="btn btn-xs btn-outline-danger confirm-link">
                                        <i class="fas fa-unlink me-1"></i>Unlink
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-xs btn-primary js-amp-open-link"
                                        data-client-id="<?php echo $cid; ?>"
                                        data-client-name="<?php echo htmlspecialchars($cname, ENT_QUOTES); ?>"
                                        <?php echo $is_connected ? '' : 'disabled'; ?>>
                                    <i class="fas fa-search me-1"></i>Link
                                </button>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                                    <button type="submit" name="create_qbo_customer" class="btn btn-xs btn-success confirm-link"
                                            <?php echo $is_connected ? '' : 'disabled'; ?>>
                                        <i class="fas fa-plus me-1"></i>Create in QBO
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Link modal -->
    <div class="modal fade" id="ampLinkModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Link <span id="ampLinkClientName"></span> to a QuickBooks Customer</h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="ampSearchInput" placeholder="Search QuickBooks customers by name...">
                        <button type="button" class="btn btn-primary" id="ampSearchBtn">
                            <i class="fas fa-search me-1"></i>Search
                        </button>
                    </div>
                    <div id="ampSearchStatus" class="text-muted small mb-2"></div>
                    <div class="table-responsive" style="max-height:340px;overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Customer</th><th>Email</th><th class="text-end">&nbsp;</th></tr>
                            </thead>
                            <tbody id="ampSearchResults">
                                <tr><td colspan="3" class="text-center text-muted py-3">Enter a search term (or leave blank) and press Search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form used to submit the chosen customer -->
    <form action="settings_accounting.php" method="post" id="ampLinkForm" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="client_id" id="ampLinkClientId" value="">
        <input type="hidden" name="qbo_customer_id" id="ampLinkCustomerId" value="">
        <input type="hidden" name="qbo_customer_name" id="ampLinkCustomerName" value="">
        <input type="hidden" name="qbo_sync_token" id="ampLinkSyncToken" value="">
        <input type="hidden" name="link_client" value="1">
    </form>

</div><!-- /#tab-client_mapping -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     ITEM MAPPING TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'item_mapping' ? 'show active' : '' ?>" id="tab-item_mapping">

    <?php if (!$is_connected): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-1"></i>
        QuickBooks Online is <strong>not connected</strong>. Connect it under the
        <a href="settings_accounting.php?tab=connection">Connection tab</a> before mapping products &amp; services to QuickBooks items.
    </div>
    <?php endif; ?>

    <?php if ($is_connected): ?>
    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Import from QuickBooks</h3>
            <span class="badge text-bg-secondary"><?php echo count($qbo_unmapped_items); ?> not yet in ITFlow</span>
        </div>
        <div class="card-body p-0">
            <?php if ($qbo_pull_error !== ''): ?>
                <div class="alert alert-danger m-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Could not read items from QuickBooks: <?php echo nullable_htmlentities($qbo_pull_error); ?></div>
            <?php elseif (empty($qbo_unmapped_items)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle me-1"></i>Every QuickBooks item already has a matching ITFlow product/service.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end p-2 border-bottom">
                    <form action="settings_accounting.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="import_all_qbo_items" class="btn btn-sm btn-success confirm-link">
                            <i class="fas fa-cloud-download-alt me-1"></i>Import All <?php echo count($qbo_unmapped_items); ?> Shown
                        </button>
                    </form>
                </div>
                <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">QuickBooks Item</th>
                            <th>Type</th>
                            <th class="text-end">Price</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qbo_unmapped_items as $qi): ?>
                        <tr>
                            <td class="ps-3 fw-bold"><?php echo nullable_htmlentities($qi['Name']); ?></td>
                            <td><span class="badge text-bg-secondary"><?php echo nullable_htmlentities($qi['Type'] ?: 'Unknown'); ?></span></td>
                            <td class="text-end"><?php echo $qi['UnitPrice'] !== '' ? nullable_htmlentities($money((float) $qi['UnitPrice'], 'USD')) : '—'; ?></td>
                            <td class="text-end pe-3">
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="qbo_item_id" value="<?php echo nullable_htmlentities($qi['Id']); ?>">
                                    <input type="hidden" name="qbo_item_name" value="<?php echo nullable_htmlentities($qi['Name']); ?>">
                                    <input type="hidden" name="qbo_item_price" value="<?php echo nullable_htmlentities($qi['UnitPrice']); ?>">
                                    <input type="hidden" name="qbo_item_type" value="<?php echo nullable_htmlentities($qi['Type']); ?>">
                                    <input type="hidden" name="qbo_sync_token" value="<?php echo nullable_htmlentities($qi['SyncToken']); ?>">
                                    <button type="submit" name="import_qbo_item" class="btn btn-xs btn-outline-success">
                                        <i class="fas fa-plus me-1"></i>Add to ITFlow
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-2 text-muted small">
            One-way pull: creates a new ITFlow product/service for each QuickBooks item and links it immediately. Existing ITFlow items are never overwritten.
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-link me-2"></i>Product / Service &rarr; QuickBooks Item</h3>
            <span class="badge text-bg-info"><?php echo intval($mapped_product_count); ?> of <?php echo intval($total_products); ?> mapped</span>
        </div>
        <div class="card-body p-0">
            <?php if ($total_products === 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-boxes fa-3x mb-3"></i>
                    <p class="mb-0">No active products or services to map.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">ITFlow Product / Service</th>
                        <th>QuickBooks Item</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p):
                    $pid    = intval($p['product_id']);
                    $pname  = $p['product_name'];
                    $ptype  = (string) $p['product_type'];
                    $map    = $item_mappings[$pid] ?? null;
                    $is_mapped = $map && !empty($map['map_remote_id']);
                    $type_badge = $ptype === 'product' ? 'text-bg-primary' : 'text-bg-warning';
                ?>
                    <tr>
                        <td class="ps-3">
                            <span class="fw-bold"><?php echo nullable_htmlentities($pname); ?></span>
                            <span class="badge <?php echo $type_badge; ?> ms-1" style="text-transform:capitalize;"><?php echo nullable_htmlentities($ptype); ?></span>
                        </td>
                        <td>
                            <?php if ($is_mapped): ?>
                                <span class="badge text-bg-success me-1"><i class="fas fa-check-circle me-1"></i>Mapped</span>
                                <?php if (!empty($map['map_remote_name'])): ?>
                                    <span class="fw-semibold"><?php echo nullable_htmlentities($map['map_remote_name']); ?></span>
                                <?php endif; ?>
                                <span class="text-muted small">(QBO Id <?php echo nullable_htmlentities($map['map_remote_id']); ?>)</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><i class="fas fa-unlink me-1"></i>Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3" style="white-space:nowrap">
                            <?php if ($is_mapped): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                                    <button type="submit" name="unlink_item" class="btn btn-xs btn-outline-danger confirm-link">
                                        <i class="fas fa-unlink me-1"></i>Unlink
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-xs btn-primary js-aim-open-link"
                                        data-product-id="<?php echo $pid; ?>"
                                        data-product-name="<?php echo htmlspecialchars($pname, ENT_QUOTES); ?>"
                                        <?php echo $is_connected ? '' : 'disabled'; ?>>
                                    <i class="fas fa-search me-1"></i>Link
                                </button>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                                    <button type="submit" name="create_qbo_item" class="btn btn-xs btn-success confirm-link"
                                            <?php echo $is_connected ? '' : 'disabled'; ?>>
                                        <i class="fas fa-plus me-1"></i>Create in QBO
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Link modal -->
    <div class="modal fade" id="aimLinkModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Link <span id="aimLinkProductName"></span> to a QuickBooks Item</h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="aimSearchInput" placeholder="Search QuickBooks items by name...">
                        <button type="button" class="btn btn-primary" id="aimSearchBtn">
                            <i class="fas fa-search me-1"></i>Search
                        </button>
                    </div>
                    <div id="aimSearchStatus" class="text-muted small mb-2"></div>
                    <div class="table-responsive" style="max-height:340px;overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Item</th><th>Type</th><th class="text-end">Price</th><th class="text-end">&nbsp;</th></tr>
                            </thead>
                            <tbody id="aimSearchResults">
                                <tr><td colspan="4" class="text-center text-muted py-3">Enter a search term (or leave blank) and press Search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form used to submit the chosen item -->
    <form action="settings_accounting.php" method="post" id="aimLinkForm" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="product_id" id="aimLinkProductId" value="">
        <input type="hidden" name="qbo_item_id" id="aimLinkItemId" value="">
        <input type="hidden" name="qbo_item_name" id="aimLinkItemName" value="">
        <input type="hidden" name="qbo_sync_token" id="aimLinkSyncToken" value="">
        <input type="hidden" name="link_item" value="1">
    </form>

</div><!-- /#tab-item_mapping -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     SYNC STATUS TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'sync_status' ? 'show active' : '' ?>" id="tab-sync_status">

    <?php if (!$is_connected): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-1"></i>
        QuickBooks Online is <strong>not connected</strong>. Connect it under the
        <a href="settings_accounting.php?tab=connection">Connection tab</a> to push invoices &amp; payments.
    </div>
    <?php endif; ?>

    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                <span class="badge text-bg-info">Pending: <?php echo intval($sync_counts['pending']); ?></span>
                <span class="badge text-bg-success">Delivered: <?php echo intval($sync_counts['delivered']); ?></span>
                <span class="badge text-bg-danger">Failed: <?php echo intval($sync_counts['failed']); ?></span>
            </div>
            <form action="settings_accounting.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" name="resync_all_unmapped" class="btn btn-sm btn-outline-primary confirm-link" <?php echo $is_connected ? '' : 'disabled'; ?>>
                    <i class="fas fa-layer-group me-1"></i>Re-sync all unmapped invoices
                </button>
            </form>
            <button type="button" id="assRunBtn" class="btn btn-sm btn-success" <?php echo $is_connected ? '' : 'disabled'; ?>>
                <i class="fas fa-play me-1"></i>Run sync worker now
            </button>
        </div>
        <div class="card-footer py-2 text-muted small">
            The worker also runs automatically on the ITFlow cron schedule. "Run now" processes due jobs immediately, one at a time, with live progress below.
        </div>
        <div id="assProgressWrap" class="card-body border-top py-2" style="display:none;">
            <div class="progress mb-2" style="height: .5rem;">
                <div id="assProgressBar" class="progress-bar bg-success" style="width: 0%;"></div>
            </div>
            <div id="assProgressStatus" class="small text-muted mb-2"></div>
            <ul id="assProgressLog" class="list-unstyled small mb-0" style="max-height: 220px; overflow-y: auto;"></ul>
        </div>
    </div>

    <?php if ($is_connected): ?>
    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Import Invoices from QuickBooks</h3>
            <span class="badge text-bg-secondary"><?php echo count($qbo_unmapped_invoices); ?> not yet in ITFlow</span>
        </div>
        <div class="card-body p-0">
            <?php if ($qbo_invoice_pull_error !== ''): ?>
                <div class="alert alert-danger m-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Could not read invoices from QuickBooks: <?php echo nullable_htmlentities($qbo_invoice_pull_error); ?></div>
            <?php elseif (empty($qbo_unmapped_invoices)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle me-1"></i>Every QuickBooks invoice already has a matching ITFlow invoice.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end p-2 border-bottom">
                    <form action="settings_accounting.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="import_all_qbo_invoices" class="btn btn-sm btn-success confirm-link">
                            <i class="fas fa-cloud-download-alt me-1"></i>Import All <?php echo count($qbo_unmapped_invoices); ?> Shown
                        </button>
                    </form>
                </div>
                <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">QuickBooks Invoice</th>
                            <th>Customer</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Balance</th>
                            <th>Date</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qbo_unmapped_invoices as $qi):
                        $q_customer_id = (string) ($qi['CustomerRef']['value'] ?? '');
                        $q_linked = isset($remote_to_local_client_pull[$q_customer_id]);
                        $q_ccy = (string) ($qi['CurrencyRef']['value'] ?? 'USD');
                    ?>
                        <tr>
                            <td class="ps-3 fw-bold">#<?php echo nullable_htmlentities($qi['DocNumber'] ?? $qi['Id']); ?></td>
                            <td><?php echo nullable_htmlentities($qi['CustomerRef']['name'] ?? '—'); ?></td>
                            <td class="text-end"><?php echo nullable_htmlentities($money(floatval($qi['TotalAmt'] ?? 0), $q_ccy)); ?></td>
                            <td class="text-end"><?php echo nullable_htmlentities($money(floatval($qi['Balance'] ?? 0), $q_ccy)); ?></td>
                            <td><small class="text-muted"><?php echo nullable_htmlentities($qi['TxnDate'] ?? ''); ?></small></td>
                            <td class="text-end pe-3">
                                <?php if ($q_linked): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="qbo_invoice_id" value="<?php echo nullable_htmlentities($qi['Id']); ?>">
                                    <button type="submit" name="import_qbo_invoice" class="btn btn-xs btn-outline-success">
                                        <i class="fas fa-plus me-1"></i>Add to ITFlow
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small" title="Map this customer on the Client Mapping tab first">Customer not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-2 text-muted small">
            One-way pull: creates a new ITFlow invoice for each QuickBooks invoice (its customer must already be linked) and links it immediately - it will never be pushed back to QuickBooks as a duplicate.
        </div>
    </div>
    <?php endif; ?>

    <!-- Invoices -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>Recent Invoices</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Invoice</th>
                        <th>Client</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>QBO</th>
                        <th>Queue</th>
                        <th>Last Log</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No invoices yet.</td></tr>
                <?php else: foreach ($invoices as $inv):
                    $iid    = intval($inv['invoice_id']);
                    $number = trim((string) $inv['invoice_prefix']) . $inv['invoice_number'];
                    $map    = $inv_map[$iid] ?? null;
                    $q      = $inv_queue[$iid] ?? null;
                    $log    = $inv_log[$iid] ?? null;
                    $mapped = $map && !empty($map['map_remote_id']);
                ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?php echo nullable_htmlentities($number); ?></td>
                        <td><?php echo nullable_htmlentities($inv['client_name'] ?? '—'); ?></td>
                        <td class="text-end"><?php echo nullable_htmlentities($money(floatval($inv['invoice_amount']), (string) $inv['invoice_currency_code'])); ?></td>
                        <td><span class="badge text-bg-secondary"><?php echo nullable_htmlentities($inv['invoice_status']); ?></span></td>
                        <td>
                            <?php if ($mapped): ?>
                                <span class="badge text-bg-success" title="QBO Id <?php echo nullable_htmlentities($map['map_remote_id']); ?>"><i class="fas fa-check me-1"></i>Id <?php echo nullable_htmlentities($map['map_remote_id']); ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ass_queue_badge($q); ?></td>
                        <td><small class="text-muted"><?php echo $log ? nullable_htmlentities(mb_strimwidth((string) $log['log_message'], 0, 90, '…')) : '—'; ?></small></td>
                        <td class="text-end pe-3" style="white-space:nowrap">
                            <form action="settings_accounting.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="invoice_id" value="<?php echo $iid; ?>">
                                <button type="submit" name="sync_invoice" class="btn btn-xs btn-outline-primary" <?php echo $is_connected ? '' : 'disabled'; ?>>
                                    <i class="fas fa-paper-plane me-1"></i>Sync now
                                </button>
                            </form>
                            <?php if ($q && $q['queue_status'] === 'failed'): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="queue_id" value="<?php echo intval($q['queue_id']); ?>">
                                    <button type="submit" name="retry_queue" class="btn btn-xs btn-outline-warning">
                                        <i class="fas fa-redo me-1"></i>Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <?php if ($is_connected): ?>
    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Import Estimates from QuickBooks</h3>
            <span class="badge text-bg-secondary"><?php echo count($qbo_unmapped_quotes); ?> not yet in ITFlow</span>
        </div>
        <div class="card-body p-0">
            <?php if ($qbo_quote_pull_error !== ''): ?>
                <div class="alert alert-danger m-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Could not read estimates from QuickBooks: <?php echo nullable_htmlentities($qbo_quote_pull_error); ?></div>
            <?php elseif (empty($qbo_unmapped_quotes)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle me-1"></i>Every QuickBooks estimate already has a matching ITFlow quote.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end p-2 border-bottom">
                    <form action="settings_accounting.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="import_all_qbo_quotes" class="btn btn-sm btn-success confirm-link">
                            <i class="fas fa-cloud-download-alt me-1"></i>Import All <?php echo count($qbo_unmapped_quotes); ?> Shown
                        </button>
                    </form>
                </div>
                <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">QuickBooks Estimate</th>
                            <th>Customer</th>
                            <th class="text-end">Total</th>
                            <th>Date</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qbo_unmapped_quotes as $qe):
                        $q_customer_id = (string) ($qe['CustomerRef']['value'] ?? '');
                        $q_linked = isset($remote_to_local_client_pull[$q_customer_id]);
                        $q_ccy = (string) ($qe['CurrencyRef']['value'] ?? 'USD');
                    ?>
                        <tr>
                            <td class="ps-3 fw-bold">#<?php echo nullable_htmlentities($qe['DocNumber'] ?? $qe['Id']); ?></td>
                            <td><?php echo nullable_htmlentities($qe['CustomerRef']['name'] ?? '—'); ?></td>
                            <td class="text-end"><?php echo nullable_htmlentities($money(floatval($qe['TotalAmt'] ?? 0), $q_ccy)); ?></td>
                            <td><small class="text-muted"><?php echo nullable_htmlentities($qe['TxnDate'] ?? ''); ?></small></td>
                            <td class="text-end pe-3">
                                <?php if ($q_linked): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="qbo_quote_id" value="<?php echo nullable_htmlentities($qe['Id']); ?>">
                                    <button type="submit" name="import_qbo_quote" class="btn btn-xs btn-outline-success">
                                        <i class="fas fa-plus me-1"></i>Add to ITFlow
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small" title="Map this customer on the Client Mapping tab first">Customer not linked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-2 text-muted small">
            One-way pull: creates a new ITFlow quote for each QuickBooks estimate (its customer must already be linked) and links it immediately - it will never be pushed back to QuickBooks as a duplicate.
        </div>
    </div>
    <?php endif; ?>

    <!-- Quotes / Estimates -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-file-signature me-2"></i>Recent Quotes <small class="text-muted">(pushed as QBO Estimates)</small></h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Quote</th>
                        <th>Client</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>QBO</th>
                        <th>Queue</th>
                        <th>Last Log</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($quotes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No quotes yet.</td></tr>
                <?php else: foreach ($quotes as $qt):
                    $qtid   = intval($qt['quote_id']);
                    $number = trim((string) $qt['quote_prefix']) . $qt['quote_number'];
                    $map    = $quote_map[$qtid] ?? null;
                    $q      = $quote_queue[$qtid] ?? null;
                    $log    = $quote_log[$qtid] ?? null;
                    $mapped = $map && !empty($map['map_remote_id']);
                ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?php echo nullable_htmlentities($number); ?></td>
                        <td><?php echo nullable_htmlentities($qt['client_name'] ?? '—'); ?></td>
                        <td class="text-end"><?php echo nullable_htmlentities($money(floatval($qt['quote_amount']), (string) $qt['quote_currency_code'])); ?></td>
                        <td><span class="badge text-bg-secondary"><?php echo nullable_htmlentities($qt['quote_status']); ?></span></td>
                        <td>
                            <?php if ($mapped): ?>
                                <span class="badge text-bg-success" title="QBO Id <?php echo nullable_htmlentities($map['map_remote_id']); ?>"><i class="fas fa-check me-1"></i>Id <?php echo nullable_htmlentities($map['map_remote_id']); ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ass_queue_badge($q); ?></td>
                        <td><small class="text-muted"><?php echo $log ? nullable_htmlentities(mb_strimwidth((string) $log['log_message'], 0, 90, '…')) : '—'; ?></small></td>
                        <td class="text-end pe-3" style="white-space:nowrap">
                            <form action="settings_accounting.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="quote_id" value="<?php echo $qtid; ?>">
                                <button type="submit" name="sync_quote" class="btn btn-xs btn-outline-primary" <?php echo $is_connected ? '' : 'disabled'; ?>>
                                    <i class="fas fa-paper-plane me-1"></i>Sync now
                                </button>
                            </form>
                            <?php if ($q && $q['queue_status'] === 'failed'): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="queue_id" value="<?php echo intval($q['queue_id']); ?>">
                                    <button type="submit" name="retry_queue" class="btn btn-xs btn-outline-warning">
                                        <i class="fas fa-redo me-1"></i>Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <?php if ($is_connected): ?>
    <div class="card mb-3" style="border-top:3px solid #2ca01c;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Import Payments from QuickBooks</h3>
            <span class="badge text-bg-secondary"><?php echo count($qbo_unmapped_payments); ?> not yet in ITFlow</span>
        </div>
        <div class="card-body p-0">
            <?php if ($qbo_payment_pull_error !== ''): ?>
                <div class="alert alert-danger m-3 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Could not read payments from QuickBooks: <?php echo nullable_htmlentities($qbo_payment_pull_error); ?></div>
            <?php elseif (empty($qbo_unmapped_payments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle me-1"></i>Every QuickBooks payment already has a matching ITFlow payment.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-end p-2 border-bottom">
                    <form action="settings_accounting.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" name="import_all_qbo_payments" class="btn btn-sm btn-success confirm-link">
                            <i class="fas fa-cloud-download-alt me-1"></i>Import All <?php echo count($qbo_unmapped_payments); ?> Shown
                        </button>
                    </form>
                </div>
                <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">QuickBooks Payment</th>
                            <th>Customer</th>
                            <th class="text-end">Amount</th>
                            <th>Date</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qbo_unmapped_payments as $qp):
                        $q_ccy = (string) ($qp['CurrencyRef']['value'] ?? 'USD');
                        $q_invoice_linked = false;
                        foreach (($qp['Line'] ?? []) as $q_line) {
                            foreach (($q_line['LinkedTxn'] ?? []) as $q_linked) {
                                if (($q_linked['TxnType'] ?? '') === 'Invoice' && isset($inv_map_by_remote[(string) ($q_linked['TxnId'] ?? '')])) {
                                    $q_invoice_linked = true;
                                    break 2;
                                }
                            }
                        }
                    ?>
                        <tr>
                            <td class="ps-3 fw-bold">#<?php echo nullable_htmlentities($qp['PaymentRefNum'] ?? $qp['Id']); ?></td>
                            <td><?php echo nullable_htmlentities($qp['CustomerRef']['name'] ?? '—'); ?></td>
                            <td class="text-end"><?php echo nullable_htmlentities($money(floatval($qp['TotalAmt'] ?? 0), $q_ccy)); ?></td>
                            <td><small class="text-muted"><?php echo nullable_htmlentities($qp['TxnDate'] ?? ''); ?></small></td>
                            <td class="text-end pe-3">
                                <?php if ($q_invoice_linked): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="qbo_payment_id" value="<?php echo nullable_htmlentities($qp['Id']); ?>">
                                    <button type="submit" name="import_qbo_payment" class="btn btn-xs btn-outline-success">
                                        <i class="fas fa-plus me-1"></i>Add to ITFlow
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small" title="Import or sync its invoice first">Invoice not in ITFlow</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-2 text-muted small">
            One-way pull: creates a new ITFlow payment for each QuickBooks payment (its linked invoice must already be in ITFlow) and links it immediately - it will never be pushed back to QuickBooks as a duplicate.
        </div>
    </div>
    <?php endif; ?>

    <!-- Payments -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-hand-holding-usd me-2"></i>Recent Payments</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Payment</th>
                        <th>Invoice</th>
                        <th>Client</th>
                        <th class="text-end">Amount</th>
                        <th>QBO</th>
                        <th>Queue</th>
                        <th>Last Log</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No payments yet.</td></tr>
                <?php else: foreach ($payments as $pay):
                    $pid    = intval($pay['payment_id']);
                    $inv_no = trim((string) $pay['invoice_prefix']) . $pay['invoice_number'];
                    $map    = $pay_map[$pid] ?? null;
                    $q      = $pay_queue[$pid] ?? null;
                    $log    = $pay_log[$pid] ?? null;
                    $mapped = $map && !empty($map['map_remote_id']);
                ?>
                    <tr>
                        <td class="ps-3 fw-bold">#<?php echo $pid; ?></td>
                        <td><?php echo $pay['payment_invoice_id'] ? nullable_htmlentities($inv_no) : '—'; ?></td>
                        <td><?php echo nullable_htmlentities($pay['client_name'] ?? '—'); ?></td>
                        <td class="text-end"><?php echo nullable_htmlentities($money(floatval($pay['payment_amount']), (string) $pay['payment_currency_code'])); ?></td>
                        <td>
                            <?php if ($mapped): ?>
                                <span class="badge text-bg-success" title="QBO Id <?php echo nullable_htmlentities($map['map_remote_id']); ?>"><i class="fas fa-check me-1"></i>Id <?php echo nullable_htmlentities($map['map_remote_id']); ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ass_queue_badge($q); ?></td>
                        <td><small class="text-muted"><?php echo $log ? nullable_htmlentities(mb_strimwidth((string) $log['log_message'], 0, 90, '…')) : '—'; ?></small></td>
                        <td class="text-end pe-3" style="white-space:nowrap">
                            <form action="settings_accounting.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="payment_id" value="<?php echo $pid; ?>">
                                <button type="submit" name="sync_payment" class="btn btn-xs btn-outline-primary" <?php echo $is_connected ? '' : 'disabled'; ?>>
                                    <i class="fas fa-paper-plane me-1"></i>Sync now
                                </button>
                            </form>
                            <?php if ($q && $q['queue_status'] === 'failed'): ?>
                                <form action="settings_accounting.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="queue_id" value="<?php echo intval($q['queue_id']); ?>">
                                    <button type="submit" name="retry_queue" class="btn btn-xs btn-outline-warning">
                                        <i class="fas fa-redo me-1"></i>Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

</div><!-- /#tab-sync_status -->

</div><!-- /.tab-content -->

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.querySelectorAll('#accountingTabs [data-bs-toggle="tab"]').forEach(function (el) {
    el.addEventListener('shown.bs.tab', function (e) {
        const tab = e.target.getAttribute('data-tabkey');
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        history.replaceState(null, '', url);
    });
});

const AMP_CSRF = '<?= $_SESSION['csrf_token'] ?>';
let ampLinkModal;

function ampEscape(str) {
    const div = document.createElement('div');
    div.textContent = (str === null || str === undefined) ? '' : String(str);
    return div.innerHTML;
}

function ampOpenLink(clientId, clientName) {
    document.getElementById('ampLinkClientId').value = clientId;
    document.getElementById('ampLinkClientName').textContent = clientName;
    document.getElementById('ampSearchInput').value = clientName;
    document.getElementById('ampSearchResults').innerHTML =
        '<tr><td colspan="3" class="text-center text-muted py-3">Press Search to look up QuickBooks customers.</td></tr>';
    document.getElementById('ampSearchStatus').textContent = '';
    ampLinkModal = ampLinkModal || new bootstrap.Modal(document.getElementById('ampLinkModal'));
    ampLinkModal.show();
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-amp-open-link');
    if (!btn) return;
    ampOpenLink(btn.getAttribute('data-client-id'), btn.getAttribute('data-client-name'));
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-amp-choose');
    if (!btn) return;
    ampChoose(btn.dataset.id, btn.dataset.name, btn.dataset.syncToken);
});

function ampChoose(id, name, syncToken) {
    document.getElementById('ampLinkCustomerId').value = id;
    document.getElementById('ampLinkCustomerName').value = name;
    document.getElementById('ampLinkSyncToken').value = syncToken || '';
    document.getElementById('ampLinkForm').submit();
}

function ampRunSearch() {
    const q = document.getElementById('ampSearchInput').value.trim();
    const status = document.getElementById('ampSearchStatus');
    const body = document.getElementById('ampSearchResults');
    status.textContent = 'Searching QuickBooks...';
    body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading...</td></tr>';

    fetch('/admin/settings_accounting.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=search_qbo_customers&csrf_token=' + encodeURIComponent(AMP_CSRF) + '&q=' + encodeURIComponent(q)
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) {
            status.textContent = '';
            body.innerHTML = '<tr><td colspan="3" class="text-danger py-3">' + ampEscape(d.error || 'Search failed.') + '</td></tr>';
            return;
        }
        const list = d.customers || [];
        status.textContent = list.length + ' customer(s) found.';
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No matching QuickBooks customers.</td></tr>';
            return;
        }
        body.innerHTML = '';
        list.forEach(function (cust) {
            const tr = document.createElement('tr');
            const tdName = document.createElement('td');
            tdName.textContent = cust.DisplayName || '';
            const tdEmail = document.createElement('td');
            tdEmail.className = 'text-muted small';
            tdEmail.textContent = cust.Email || '';
            const tdBtn = document.createElement('td');
            tdBtn.className = 'text-end';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-xs btn-success js-amp-choose';
            btn.dataset.id = cust.Id || '';
            btn.dataset.name = cust.DisplayName || '';
            btn.dataset.syncToken = cust.SyncToken || '';
            btn.innerHTML = '<i class="fas fa-link me-1"></i>Select';
            tdBtn.appendChild(btn);
            tr.append(tdName, tdEmail, tdBtn);
            body.appendChild(tr);
        });
    })
    .catch(function () {
        status.textContent = '';
        body.innerHTML = '<tr><td colspan="3" class="text-danger py-3">Network error while contacting QuickBooks.</td></tr>';
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('ampSearchBtn');
    const input = document.getElementById('ampSearchInput');
    if (btn) btn.addEventListener('click', ampRunSearch);
    if (input) input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); ampRunSearch(); }
    });
});

const AIM_CSRF = '<?= $_SESSION['csrf_token'] ?>';
let aimLinkModal;

function aimEscape(str) {
    const div = document.createElement('div');
    div.textContent = (str === null || str === undefined) ? '' : String(str);
    return div.innerHTML;
}

function aimOpenLink(productId, productName) {
    document.getElementById('aimLinkProductId').value = productId;
    document.getElementById('aimLinkProductName').textContent = productName;
    document.getElementById('aimSearchInput').value = productName;
    document.getElementById('aimSearchResults').innerHTML =
        '<tr><td colspan="4" class="text-center text-muted py-3">Press Search to look up QuickBooks items.</td></tr>';
    document.getElementById('aimSearchStatus').textContent = '';
    aimLinkModal = aimLinkModal || new bootstrap.Modal(document.getElementById('aimLinkModal'));
    aimLinkModal.show();
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-aim-open-link');
    if (!btn) return;
    aimOpenLink(btn.getAttribute('data-product-id'), btn.getAttribute('data-product-name'));
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-aim-choose');
    if (!btn) return;
    aimChoose(btn.dataset.id, btn.dataset.name, btn.dataset.syncToken);
});

function aimChoose(id, name, syncToken) {
    document.getElementById('aimLinkItemId').value = id;
    document.getElementById('aimLinkItemName').value = name;
    document.getElementById('aimLinkSyncToken').value = syncToken || '';
    document.getElementById('aimLinkForm').submit();
}

function aimRunSearch() {
    const q = document.getElementById('aimSearchInput').value.trim();
    const status = document.getElementById('aimSearchStatus');
    const body = document.getElementById('aimSearchResults');
    status.textContent = 'Searching QuickBooks...';
    body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading...</td></tr>';

    fetch('/admin/settings_accounting.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=search_qbo_items&csrf_token=' + encodeURIComponent(AIM_CSRF) + '&q=' + encodeURIComponent(q)
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) {
            status.textContent = '';
            body.innerHTML = '<tr><td colspan="4" class="text-danger py-3">' + aimEscape(d.error || 'Search failed.') + '</td></tr>';
            return;
        }
        const list = d.items || [];
        status.textContent = list.length + ' item(s) found.';
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No matching QuickBooks items.</td></tr>';
            return;
        }
        body.innerHTML = '';
        list.forEach(function (it) {
            const price = (it.UnitPrice === '' || it.UnitPrice === null || it.UnitPrice === undefined) ? '' : it.UnitPrice;
            const tr = document.createElement('tr');
            const tdName = document.createElement('td');
            tdName.textContent = it.Name || '';
            const tdType = document.createElement('td');
            tdType.className = 'text-muted small';
            tdType.textContent = it.Type || '';
            const tdPrice = document.createElement('td');
            tdPrice.className = 'text-end text-muted small';
            tdPrice.textContent = price;
            const tdBtn = document.createElement('td');
            tdBtn.className = 'text-end';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-xs btn-success js-aim-choose';
            btn.dataset.id = it.Id || '';
            btn.dataset.name = it.Name || '';
            btn.dataset.syncToken = it.SyncToken || '';
            btn.innerHTML = '<i class="fas fa-link me-1"></i>Select';
            tdBtn.appendChild(btn);
            tr.append(tdName, tdType, tdPrice, tdBtn);
            body.appendChild(tr);
        });
    })
    .catch(function () {
        status.textContent = '';
        body.innerHTML = '<tr><td colspan="4" class="text-danger py-3">Network error while contacting QuickBooks.</td></tr>';
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('aimSearchBtn');
    const input = document.getElementById('aimSearchInput');
    if (btn) btn.addEventListener('click', aimRunSearch);
    if (input) input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); aimRunSearch(); }
    });
});

// ── Sync Status tab: live per-job progress for "Run sync worker now" ────────
// Calls run_sync_step once per queue job instead of one opaque full-batch
// POST, so the user sees each job's outcome as it happens.
let assProcessed = 0;

function assOutcomeBadge(outcome) {
    const map = {
        'delivered': 'success',
        'already-mapped': 'secondary',
        'skipped': 'secondary',
        'deferred': 'info',
        'retry': 'warning',
        'failed': 'danger'
    };
    return map[outcome] || 'secondary';
}

function assFinishRun(reloadAfter) {
    const btn = document.getElementById('assRunBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-play me-1"></i>Run sync worker now';
    if (reloadAfter) {
        setTimeout(function () { window.location.reload(); }, 1200);
    }
}

function assRunStep() {
    fetch('/admin/settings_accounting.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=run_sync_step&csrf_token=' + encodeURIComponent(AIM_CSRF)
    })
    .then(r => r.json())
    .then(d => {
        const status = document.getElementById('assProgressStatus');
        const log = document.getElementById('assProgressLog');
        const bar = document.getElementById('assProgressBar');

        if (!d.ok) {
            status.textContent = 'Error: ' + (d.error || 'Sync step failed.');
            bar.classList.remove('bg-success');
            bar.classList.add('bg-danger');
            assFinishRun();
            return;
        }

        if (d.done) {
            status.textContent = assProcessed === 0
                ? 'Nothing to sync - queue is empty.'
                : 'Sync complete - ' + assProcessed + ' job(s) processed.';
            bar.style.width = '100%';
            assFinishRun(true);
            return;
        }

        assProcessed++;
        const total = assProcessed + d.remaining;
        const pct = total > 0 ? Math.round((assProcessed / total) * 100) : 100;
        bar.style.width = pct + '%';

        const li = document.createElement('li');
        li.className = 'mb-1';
        const badge = document.createElement('span');
        badge.className = 'badge text-bg-' + assOutcomeBadge(d.result.outcome) + ' me-2';
        badge.textContent = d.result.outcome;
        li.appendChild(badge);
        const text = document.createElement('span');
        text.textContent = d.result.type + ' #' + d.result.local_id + ': ' + (d.result.message || '');
        li.appendChild(text);
        log.appendChild(li);
        log.scrollTop = log.scrollHeight;

        status.textContent = assProcessed + ' job(s) processed, ' + d.remaining + ' remaining...';

        assRunStep();
    })
    .catch(function () {
        document.getElementById('assProgressStatus').textContent = 'Network error while contacting the server.';
        assFinishRun();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const runBtn = document.getElementById('assRunBtn');
    if (runBtn) {
        runBtn.addEventListener('click', function () {
            assProcessed = 0;
            runBtn.disabled = true;
            runBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Running...';
            document.getElementById('assProgressWrap').style.display = '';
            document.getElementById('assProgressLog').innerHTML = '';
            const bar = document.getElementById('assProgressBar');
            bar.style.width = '0%';
            bar.classList.remove('bg-danger');
            bar.classList.add('bg-success');
            document.getElementById('assProgressStatus').textContent = 'Starting...';
            assRunStep();
        });
    }
});
</script>

<?php
require_once "../includes/footer.php";
