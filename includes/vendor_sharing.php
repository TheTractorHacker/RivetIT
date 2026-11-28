<?php
declare(strict_types=1);

/*
 * Shared central vendors.
 *
 * A central vendor is a vendors row with vendor_client_id = 0 (added in Vendors without a department). It is private to the
 * central list unless an administrator ticks "Available to all departments" (vendors.vendor_shared = 1). A shared central
 * vendor is then offered by every department's pickers (asset, ticket, service, software, domain, document link, relationships)
 * and shows read-only in each department's Vendors tab. vendor_shared means nothing on a department's own vendor.
 * (vendor_template_id is a different concept - the base template a vendor was created from - and is not touched here.)
 *
 * Every department-scoped vendor query and every server-side check that stores a vendor on a department record goes through
 * vendorVisibleSql() / vendorAssignableToClient() below; nothing else may spell out "vendor_client_id = $client_id".
 *
 * Toggling a vendor back to unshared only removes it from pickers and lists for NEW use. Links that already exist on tickets,
 * assets, domains and so on keep showing the vendor's name, because those displays join vendors by id and never filter on sharing.
 * Archiving a shared vendor still hides it everywhere (every caller keeps its own vendor_archived_at condition).
 *
 * Every function tolerates the column not existing yet (before DB 2.6.237 has run): nothing is shared, no SQL error.
 */

/** Does vendors.vendor_shared exist yet? (False until the database update that adds it has run.) */
function vendorSharedColumnExists($mysqli = null, bool $refresh = false): bool
{
    static $exists = null;
    if ($refresh) {
        $exists = null;
    }
    if ($exists === null) {
        $mysqli ??= $GLOBALS['mysqli'] ?? null;
        $res = $mysqli ? @mysqli_query($mysqli, "SHOW COLUMNS FROM vendors LIKE 'vendor_shared'") : false;
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }
    return $exists;
}

/** "a." for an alias ('v' or 'v.' or 'vendors'), '' for none. $alias is a trusted identifier, never user input. */
function vendorSqlPrefix(string $alias): string
{
    $alias = rtrim(trim($alias), '.');
    return $alias === '' ? '' : preg_replace('/[^A-Za-z0-9_]/', '', $alias) . '.';
}

/** SQL condition: this row is a central vendor flagged shared. "0 = 1" while the column does not exist. */
function vendorSharedCentralSql(string $alias = '', $mysqli = null): string
{
    if (!vendorSharedColumnExists($mysqli)) {
        return '0 = 1';
    }
    $p = vendorSqlPrefix($alias);
    return "({$p}vendor_client_id = 0 AND {$p}vendor_shared = 1)";
}

/**
 * The ONE department vendor scope: the department's own vendors plus the shared central ones. Returns a parenthesised condition
 * to AND into a WHERE clause. Callers add their own vendor_archived_at condition.
 */
function vendorVisibleSql($client_id, string $alias = '', $mysqli = null): string
{
    $client_id = (int) $client_id;
    $p = vendorSqlPrefix($alias);
    if ($client_id <= 0) {
        return "({$p}vendor_client_id = 0)";   // the central list itself: shared or not
    }
    return "({$p}vendor_client_id = $client_id OR " . vendorSharedCentralSql($alias, $mysqli) . ')';
}

/**
 * Global search / department-restricted lists join clients and append $access_permission_query ("AND clients.client_id IN (...)",
 * '' for unrestricted users). That drops central rows (no client). Widen it so shared central vendors stay visible to restricted users.
 */
function vendorAccessScopeSql(string $access_permission_query, $mysqli = null): string
{
    $access_permission_query = trim($access_permission_query);
    if ($access_permission_query === '') {
        return '';
    }
    $inner = preg_replace('/^AND\s+/i', '', $access_permission_query);
    return 'AND (' . $inner . ' OR ' . vendorSharedCentralSql('', $mysqli) . ')';
}

/** Is this vendor a central vendor that is currently shared? */
function vendorIsSharedCentral($mysqli, int $vendor_id): bool
{
    if ($vendor_id <= 0 || !vendorSharedColumnExists($mysqli)) {
        return false;
    }
    $res = mysqli_query($mysqli, "SELECT 1 FROM vendors WHERE vendor_id = $vendor_id AND " . vendorSharedCentralSql('', $mysqli) . ' LIMIT 1');
    return (bool) ($res && mysqli_num_rows($res) > 0);
}

/**
 * May this vendor be stored on a record of this department? 0 (no vendor) is always fine. Otherwise the vendor must exist, be the
 * department's own, or be a shared central one. Another department's vendor and an unshared central vendor are refused.
 * This is the tenant-isolation check behind every POST that stores a vendor id; it does not look at archived state, so an
 * edit that leaves an already-linked archived vendor in place still saves. A record with no department (client 0) may use central vendors,
 * shared or not: it is not a department.
 */
function vendorAssignableToClient($mysqli, int $vendor_id, int $client_id): bool
{
    if ($vendor_id === 0) {
        return true;
    }
    if ($vendor_id < 0 || $client_id < 0) {
        return false;
    }
    $res = mysqli_query($mysqli, "SELECT 1 FROM vendors WHERE vendor_id = $vendor_id AND " . vendorVisibleSql($client_id, '', $mysqli) . ' LIMIT 1');
    return (bool) ($res && mysqli_num_rows($res) > 0);
}

/**
 * Refuse a POST that names a vendor the department may not use. Same flash-and-redirect refusal the handlers use elsewhere.
 * $unchanged_id: the vendor already stored on the record being edited - leaving it as it is never fails (history, toggled-off vendors).
 */
function enforceVendorAssignable($vendor_id, $client_id, int $unchanged_id = 0): void
{
    global $mysqli;
    $vendor_id = (int) $vendor_id;
    if ($vendor_id === 0 || ($unchanged_id > 0 && $vendor_id === $unchanged_id)) {
        return;
    }
    if (!vendorAssignableToClient($mysqli, $vendor_id, (int) $client_id)) {
        flash_alert('That vendor is not available for this department.', 'error');
        redirect();
        exit;
    }
}

/**
 * Same as enforceVendorAssignable() for a multi-select (a service's vendors): every id must pass before anything is written.
 * $unchanged_ids: vendors already linked to the record, which keep saving.
 *
 * @param mixed $vendor_ids raw $_POST value
 * @param list<int> $unchanged_ids
 */
function enforceVendorListAssignable($vendor_ids, $client_id, array $unchanged_ids = []): void
{
    foreach ((array) $vendor_ids as $vendor_id) {
        enforceVendorAssignable($vendor_id, $client_id, in_array((int) $vendor_id, $unchanged_ids, true) ? (int) $vendor_id : 0);
    }
}

/** Toggle audit text for a central vendor's sharing flag. */
function vendorSharedLabel(int $shared): string
{
    return $shared ? 'shared with all departments' : 'not shared';
}

/**
 * Set a central vendor's sharing flag and write the audit row (who, which vendor, old -> new). Only a central vendor
 * (vendor_client_id = 0) can be shared; anything else, an unknown id or a missing column returns false and changes nothing.
 * The caller has already enforced the central vendor permission. Returns true when the stored value actually changed.
 */
function vendorSetShared($mysqli, int $vendor_id, bool $shared, ?int $actor_user_id = null, string $actor_name = ''): bool
{
    if ($vendor_id <= 0 || !vendorSharedColumnExists($mysqli)) {
        return false;
    }
    $res = mysqli_query($mysqli, "SELECT vendor_name, vendor_client_id, vendor_shared FROM vendors WHERE vendor_id = $vendor_id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row || (int) $row['vendor_client_id'] !== 0) {
        return false;
    }
    $old = (int) $row['vendor_shared'];
    $new = $shared ? 1 : 0;
    if ($old === $new) {
        return false;
    }
    mysqli_query($mysqli, "UPDATE vendors SET vendor_shared = $new WHERE vendor_id = $vendor_id");
    $summary = ($actor_name !== '' ? $actor_name : 'A user') . ' changed vendor ' . $row['vendor_name'] . ' sharing: ' . vendorSharedLabel($old) . ' -> ' . vendorSharedLabel($new);
    if (function_exists('logAction')) {
        logAction('Vendor', 'Share', $summary, 0, $vendor_id);
    }
    try {
        \ITFlow\Audit\AuditService::record('vendor.share', $actor_user_id, 'vendor', $vendor_id, 'edit', $summary, ['vendor_shared_old' => $old, 'vendor_shared_new' => $new]);
    } catch (\Throwable $e) {
        // auditing never breaks the action
    }
    return true;
}

/**
 * The "Available to all departments" checkbox for the central vendor add / edit forms ('' for a department's own vendor, or
 * before the column exists). Returns HTML; the help text says what unticking does.
 */
function vendorSharedCheckboxHtml(int $vendor_client_id, bool $checked = false): string
{
    if ($vendor_client_id !== 0 || !vendorSharedColumnExists()) {
        return '';
    }
    return '<div class="form-check form-switch mb-3 mt-2">'
        . '<input class="form-check-input" type="checkbox" role="switch" id="vendorSharedToggle" name="vendor_shared" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<label class="form-check-label fw-bold" for="vendorSharedToggle">Available to all departments</label>'
        . '<div class="form-text">Ticked: every department can pick this vendor on its tickets, assets, services, software, domains and documents, and sees it read-only (marked Central) in its Vendors tab. '
        . 'Unticked: it stays in this central list only. Turning it off later does not remove the vendor from records that already use it (their history keeps the name); '
        . 'it just stops being offered for new use in departments.</div>'
        . '</div>';
}
