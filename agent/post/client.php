<?php

/*
 * RivetIT - GET/POST request handler for clients/customers (overview)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_client'])) {

    // JQ - Using Prepared MySQLi Statements here for show this is not our standard and is only used in the client add/edit POST.

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    require_once 'client_model.php';

    // Locations - optional, links to any number of EXISTING locations
    // (department_sites) rather than typing a fresh address inline every
    // time a department is created. A department can have multiple
    // locations, and a location can be shared by more than one department.
    $selected_location_ids = array_map('intval', $_POST['locations'] ?? []);

    // Contact inputs
    $contact = cleanInput($_POST['contact']);
    $title = cleanInput($_POST['title']);
    $contact_phone_country_code = preg_replace("/[^0-9]/", '', $_POST['contact_phone_country_code']);
    $contact_phone = preg_replace("/[^0-9]/", '', $_POST['contact_phone']);
    $contact_extension = preg_replace("/[^0-9]/", '', $_POST['contact_extension']);
    $contact_mobile_country_code = preg_replace("/[^0-9]/", '', $_POST['contact_mobile_country_code']);
    $contact_mobile = preg_replace("/[^0-9]/", '', $_POST['contact_mobile']);
    $contact_email = cleanInput($_POST['contact_email']);

    $extended_log_description = '';

    // Insert client using SET
    $query = mysqli_prepare(
        $mysqli,
        "INSERT INTO clients SET
        client_name = ?,
        client_type = ?,
        client_website = ?,
        client_referral = ?,
        client_rate = ?,
        client_currency_code = ?,
        client_net_terms = ?,
        client_tax_id_number = ?,
        client_lead = ?,
        client_abbreviation = ?,
        client_notes = ?,
        client_accessed_at = NOW()"
    );
    mysqli_stmt_bind_param(
        $query,
        "ssssdsiisss",
        $name,
        $type,
        $website,
        $referral,
        $rate,
        $session_company_currency,
        $net_terms,
        $tax_id_number,
        $lead,
        $abbreviation,
        $notes
    );
    mysqli_stmt_execute($query);
    $client_id = mysqli_insert_id($mysqli);

    // CRM lead qualification fields (supplementary update keeps the core prepared statement intact)
    $lead_source_sql = $lead_source !== '' ? "'" . mysqli_real_escape_string($mysqli, $lead_source) . "'" : "NULL";
    $lead_status_sql = $lead_status !== '' ? "'" . mysqli_real_escape_string($mysqli, $lead_status) . "'" : "NULL";
    $lead_owner_sql  = $lead_owner > 0 ? intval($lead_owner) : "NULL";
    $lead_score_sql  = $lead_score > 0 ? intval($lead_score) : "NULL";
    mysqli_query($mysqli, "UPDATE clients SET
        client_lead_source = $lead_source_sql,
        client_lead_status = $lead_status_sql,
        client_lead_owner = $lead_owner_sql,
        client_lead_score = $lead_score_sql
        WHERE client_id = $client_id");

    // Master-plan Phase 1 department fields (supplementary update, same
    // reasoning as the CRM fields above - keeps the core prepared statement
    // untouched). Only editable via client_edit.php's modal today; the add
    // modal doesn't expose these yet, so this applies client_model.php's
    // defaults (Active/General) on create.
    $cost_center_sql = mysqli_real_escape_string($mysqli, $cost_center);
    $status_sql = mysqli_real_escape_string($mysqli, $status);
    $security_classification_sql = mysqli_real_escape_string($mysqli, $security_classification);
    mysqli_query($mysqli, "UPDATE clients SET
        client_cost_center = '$cost_center_sql',
        client_status = '$status_sql',
        client_security_classification = '$security_classification_sql'
        WHERE client_id = $client_id");

    // Create client folder
    $client_folder = $_SERVER['DOCUMENT_ROOT'] . "/uploads/clients/$client_id";
    if (!file_exists($client_folder)) {
        mkdir($client_folder);
        file_put_contents("$client_folder/index.php", "");
    }

    // Create referral category if it doesn't exist
    $query = mysqli_prepare($mysqli, "SELECT category_name FROM categories WHERE category_type = 'Referral' AND category_archived_at IS NULL AND category_name = ?");
    mysqli_stmt_bind_param($query, "s", $referral);
    mysqli_stmt_execute($query);
    mysqli_stmt_store_result($query);
    if (mysqli_stmt_num_rows($query) == 0) {
        $query = mysqli_prepare($mysqli, "INSERT INTO categories SET category_name = ?, category_type = 'Referral'");
        mysqli_stmt_bind_param($query, "s", $referral);
        mysqli_stmt_execute($query);

        logAction("Category", "Create", "$session_name created referral category $referral");
    }

    // Link the selected existing location(s) (optional) - department_sites,
    // not location_client_id, so a location can be shared by more than one
    // department and a department can have more than one location.
    foreach ($selected_location_ids as $selected_location_id) {
        if ($selected_location_id > 0) {
            mysqli_query($mysqli, "INSERT IGNORE INTO department_sites SET client_id = $client_id, location_id = $selected_location_id");
            $extended_log_description .= ", linked to location #$selected_location_id";
        }
    }

    // Insert primary contact using SET
    if (!empty($contact) || !empty($title) || !empty($contact_phone) || !empty($contact_mobile) || !empty($contact_email)) {
        $query = mysqli_prepare(
            $mysqli,
            "INSERT INTO contacts SET
            contact_name = ?,
            contact_title = ?,
            contact_phone_country_code = ?,
            contact_phone = ?,
            contact_extension = ?,
            contact_mobile_country_code = ?,
            contact_mobile = ?,
            contact_email = ?,
            contact_primary = 1,
            contact_important = 1,
            contact_client_id = ?"
        );
        mysqli_stmt_bind_param(
            $query,
            "ssssssssi",
            $contact,
            $title,
            $contact_phone_country_code,
            $contact_phone,
            $contact_extension,
            $contact_mobile_country_code,
            $contact_mobile,
            $contact_email,
            $client_id
        );
        mysqli_stmt_execute($query);
        $extended_log_description .= ", primary contact $contact added";
    }

    // Add tags
    if (isset($_POST['tags'])) {
        $query = mysqli_prepare($mysqli, "INSERT INTO client_tags SET client_id = ?, tag_id = ?");
        foreach ($_POST['tags'] as $tag) {
            $tag = intval($tag);
            mysqli_stmt_bind_param($query, "ii", $client_id, $tag);
            mysqli_stmt_execute($query);
        }
    }

    // Insert domain and SSL using SET
    if (!empty($website) && filter_var($website, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        $expire = getDomainExpirationDate($website);
        $records = getDomainRecords($website);
        $a = cleanInput($records['a']);
        $ns = cleanInput($records['ns']);
        $mx = cleanInput($records['mx']);
        $whois = cleanInput($records['whois']);

        try {
            $query = mysqli_prepare(
                $mysqli,
                "INSERT INTO domains SET
                domain_name = ?,
                domain_registrar = 0,
                domain_webhost = 0,
                domain_expire = ?,
                domain_ip = ?,
                domain_name_servers = ?,
                domain_mail_servers = ?,
                domain_raw_whois = ?,
                domain_client_id = ?"
            );
            mysqli_stmt_bind_param($query, "ssssssi", $website, $expire, $a, $ns, $mx, $whois, $client_id);
            mysqli_stmt_execute($query);
            $extended_log_description .= ", domain $website added";
        } catch (Exception $e) {
            $extended_log_description .= ", domain not added";
            logApp("Department", "warning", "Failed to add domain $website during department creation");
        }

        $domain_id = mysqli_insert_id($mysqli);
        $certificate = getSSL($website);

        if ($certificate['success'] == "TRUE") {
            $expire = cleanInput($certificate['expire']);
            $issued_by = cleanInput($certificate['issued_by']);
            $public_key = cleanInput($certificate['public_key']);

            $query = mysqli_prepare(
                $mysqli,
                "INSERT INTO certificates SET
                certificate_name = ?,
                certificate_domain = ?,
                certificate_issued_by = ?,
                certificate_expire = ?,
                certificate_public_key = ?,
                certificate_domain_id = ?,
                certificate_client_id = ?"
            );
            mysqli_stmt_bind_param(
                $query,
                "sssssii",
                $website,
                $website,
                $issued_by,
                $expire,
                $public_key,
                $domain_id,
                $client_id
            );
            mysqli_stmt_execute($query);

            $extended_log_description .= ", SSL certificate $website added";
        }
    }

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Create", "$session_name created department $name$extended_log_description", $client_id, $client_id);

    flash_alert("Department <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_client'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    require_once 'client_model.php';

    $client_id = intval($_POST['client_id']);

    enforceClientAccess($client_id);

    // The edit form has no Type field: only change Type when one was actually posted (every save used to clear it).
    $type_value = array_key_exists('type', $_POST) ? $type : null;

    // Update client using prepared statement
    $query = mysqli_prepare(
        $mysqli,
        "UPDATE clients SET
        client_name = ?,
        client_type = COALESCE(?, client_type),
        client_website = ?,
        client_referral = ?,
        client_rate = ?,
        client_net_terms = ?,
        client_tax_id_number = ?,
        client_lead = ?,
        client_abbreviation = ?,
        client_notes = ?
        WHERE client_id = ?"
    );
    mysqli_stmt_bind_param(
        $query,
        "ssssdisissi",
        $name,
        $type_value,
        $website,
        $referral,
        $rate,
        $net_terms,
        $tax_id_number,
        $lead,
        $abbreviation,
        $notes,
        $client_id
    );
    mysqli_stmt_execute($query);

    // CRM lead qualification fields (supplementary update keeps the core prepared statement intact)
    $lead_source_sql = $lead_source !== '' ? "'" . mysqli_real_escape_string($mysqli, $lead_source) . "'" : "NULL";
    $lead_status_sql = $lead_status !== '' ? "'" . mysqli_real_escape_string($mysqli, $lead_status) . "'" : "NULL";
    $lead_owner_sql  = $lead_owner > 0 ? intval($lead_owner) : "NULL";
    $lead_score_sql  = $lead_score > 0 ? intval($lead_score) : "NULL";
    mysqli_query($mysqli, "UPDATE clients SET
        client_lead_source = $lead_source_sql,
        client_lead_status = $lead_status_sql,
        client_lead_owner = $lead_owner_sql,
        client_lead_score = $lead_score_sql
        WHERE client_id = $client_id");

    // Master-plan Phase 1 department fields (supplementary update, same
    // reasoning as the CRM fields above - keeps the core prepared statement
    // untouched). Only editable via client_edit.php's modal today; the add
    // modal doesn't expose these yet, so this applies client_model.php's
    // defaults (Active/General) on create.
    $cost_center_sql = mysqli_real_escape_string($mysqli, $cost_center);
    $status_sql = mysqli_real_escape_string($mysqli, $status);
    $security_classification_sql = mysqli_real_escape_string($mysqli, $security_classification);
    mysqli_query($mysqli, "UPDATE clients SET
        client_cost_center = '$cost_center_sql',
        client_status = '$status_sql',
        client_security_classification = '$security_classification_sql'
        WHERE client_id = $client_id");

    // Create referral category if it doesn't exist
    $query = mysqli_prepare($mysqli, "SELECT category_name FROM categories WHERE category_type = 'Referral' AND category_archived_at IS NULL AND category_name = ?");
    mysqli_stmt_bind_param($query, "s", $referral);
    mysqli_stmt_execute($query);
    mysqli_stmt_store_result($query);
    if (mysqli_stmt_num_rows($query) == 0) {
        $query = mysqli_prepare($mysqli, "INSERT INTO categories SET category_name = ?, category_type = 'Referral'");
        mysqli_stmt_bind_param($query, "s", $referral);
        mysqli_stmt_execute($query);

        logAction("Category", "Create", "$session_name created referral category $referral");
    }

    // Tags - delete existing and re-insert
    $query = mysqli_prepare($mysqli, "DELETE FROM client_tags WHERE client_id = ?");
    mysqli_stmt_bind_param($query, "i", $client_id);
    mysqli_stmt_execute($query);

    if (isset($_POST['tags'])) {
        $query = mysqli_prepare($mysqli, "INSERT INTO client_tags SET client_id = ?, tag_id = ?");
        foreach ($_POST['tags'] as $tag) {
            $tag = intval($tag);
            mysqli_stmt_bind_param($query, "ii", $client_id, $tag);
            mysqli_stmt_execute($query);
        }
    }

    // Locations - full sync (delete then re-add), mirrors location.php's own
    // Departments-tab sync. A department can have multiple locations, and a
    // location can be shared by more than one department.
    mysqli_query($mysqli, "DELETE FROM department_sites WHERE client_id = $client_id");
    if (isset($_POST['locations']) && is_array($_POST['locations'])) {
        foreach ($_POST['locations'] as $location_id) {
            $location_id = intval($location_id);
            if ($location_id > 0) {
                mysqli_query($mysqli, "INSERT IGNORE INTO department_sites SET client_id = $client_id, location_id = $location_id");
            }
        }
    }

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Edit", "$session_name edited department $name", $client_id, $client_id);

    flash_alert("Department <strong>$name</strong> updated");

    redirect();

}

// CRM - one-click convert a lead into a full client
if (isset($_GET['convert_lead'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_client', 2);

    $client_id = intval($_GET['convert_lead']);

    enforceClientAccess();

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id"));
    $client_name = sanitizeInput($row['client_name'] ?? '');

    mysqli_query($mysqli, "UPDATE clients SET client_lead = 0, client_lead_status = 'Converted' WHERE client_id = $client_id");

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Convert", "$session_name converted lead $client_name to a department", $client_id, $client_id);

    flash_alert("Lead <strong>$client_name</strong> converted to a department");

    redirect("clients.php");
}

if (isset($_GET['archive_client'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_client', 2);

    $client_id = intval($_GET['archive_client']);

    // Archive is a scoped write - confirm this user is allowed to touch this
    // department before we act on an ID that came straight off the query string
    enforceClientAccess($client_id);

    // Archive client
    mysqli_query($mysqli, "UPDATE clients SET client_archived_at = NOW() WHERE client_id = $client_id");

    // Stop recurring invoices
    $sql_recurring_invoices = mysqli_query($mysqli, "SELECT * FROM recurring_invoices WHERE recurring_invoice_client_id = $client_id AND recurring_invoice_status = 1");
    while ($row = mysqli_fetch_assoc($sql_recurring_invoices)) {
        $recurring_invoice_id = intval($row['recurring_invoice_id']);
        mysqli_query($mysqli,"UPDATE recurring_invoices SET recurring_invoice_status = 0 WHERE recurring_invoice_id = $recurring_invoice_id AND recurring_invoice_client_id = $client_id");
        mysqli_query($mysqli,"INSERT INTO history SET history_status = 0, history_description = 'Recurring Invoice inactive as department archived', history_recurring_invoice_id = $recurring_invoice_id");
    }

    // Get Client Name
    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Archive", "$session_name archived department $client_name", $client_id, $client_id);

    flash_alert("Department <strong>$client_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_client'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_client', 2);

    $client_id = intval($_GET['restore_client']);

    // Restore un-archives a department, so it needs the same scoping check as
    // archive - the ID is attacker-supplied via the query string
    enforceClientAccess($client_id);

    // Get Client Name
    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    mysqli_query($mysqli, "UPDATE clients SET client_archived_at = NULL WHERE client_id = $client_id");

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Restored", "$session_name restored department $client_name", $client_id);

    flash_alert("Department <strong>$client_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_client'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_client', 3);

    $client_id = intval($_GET['delete_client']);

    // Delete is irreversible (hard DELETE across every child table plus the
    // client's upload directory), so scoping has to be enforced before the
    // first DELETE runs - module_client full access alone is not enough
    enforceClientAccess($client_id);

    // Training (Phase 2, S14): a department with training records (its people, sessions or completion
    // snapshots) is never hard-deleted; refuse before the first DELETE.
    if (class_exists(\ITFlow\Training\Records\RecordGuard::class) && \ITFlow\Training\Records\RecordGuard::clientHasRecords($mysqli, $client_id)) {
        flash_alert('This department has training records. Archive it instead of deleting it.', 'error');
        redirect();
    }

    // Get Client Name
    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    // Delete Associations
    // Delete Client Data
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM certificates WHERE certificate_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM documents WHERE document_client_id = $client_id");

    // Delete Contacts
    mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_client_id = $client_id");

    // Delete Assets
    mysqli_query($mysqli, "DELETE FROM assets WHERE asset_client_id = $client_id");

    // Delete Domains and associated records
    mysqli_query($mysqli, "DELETE FROM domains WHERE domain_client_id = $client_id");

    mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM files WHERE file_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM folders WHERE folder_client_id = $client_id");

    //Delete Invoices and Invoice Referencing data
    $sql = mysqli_query($mysqli, "SELECT invoice_id FROM invoices WHERE invoice_client_id = $client_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $invoice_id = $row['invoice_id'];
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $invoice_id");
        mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id = $invoice_id");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $invoice_id");
    }
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_client_id = $client_id");

    // Delete Locations and location tags
    mysqli_query($mysqli, "DELETE FROM locations WHERE location_client_id = $client_id");

    mysqli_query($mysqli, "DELETE FROM credentials WHERE credential_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM networks WHERE network_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_client_id = $client_id");

    //Delete Quote and related items
    $sql = mysqli_query($mysqli, "SELECT quote_id FROM quotes WHERE quote_client_id = $client_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $quote_id = $row['quote_id'];

        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = $quote_id");
    }
    mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_client_id = $client_id");

    // Delete Recurring Invoices and associated items
    $sql = mysqli_query($mysqli, "SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_client_id = $client_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $recurring_invoice_id = $row['recurring_invoice_id'];
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = $recurring_invoice_id");
    }
    mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_client_id = $client_id");

    mysqli_query($mysqli, "DELETE FROM revenues WHERE revenue_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM recurring_tickets WHERE recurring_ticket_client_id = $client_id");

    // Delete Services
    mysqli_query($mysqli, "DELETE FROM services WHERE service_client_id = $client_id");

    // Delete Shared Items
    mysqli_query($mysqli, "DELETE FROM shared_items WHERE item_client_id = $client_id");

    // Delete Software
    mysqli_query($mysqli, "DELETE FROM software WHERE software_client_id = $client_id");

    // Delete tickets and related data
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_client_id = $client_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $ticket_id = $row['ticket_id'];
        mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id");
        mysqli_query($mysqli, "DELETE FROM ticket_views WHERE view_ticket_id = $ticket_id");
    }
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM trips WHERE trip_client_id = $client_id");
    mysqli_query($mysqli, "DELETE FROM vendors WHERE vendor_client_id = $client_id");

    //Delete Client Files
    removeDirectory("../uploads/clients/$client_id");

    //Finally Remove the Client
    mysqli_query($mysqli, "DELETE FROM clients WHERE client_id = $client_id");

    // Training: keep the department job groups in step (one per active department). Never blocks the department save.
    try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
    catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

    logAction("Department", "Deleted", "$session_name deleted Department $client_name and all associated data");

    flash_alert("Department <strong>$client_name</strong> deleted along with all associated data", 'error');

    redirect('clients.php');

}

/*
 * READ-ONLY DEPARTMENT PORTAL PREVIEW
 * ===================================
 *
 * "Show me what this department sees in the client portal."
 *
 * This is NOT impersonation and must never be allowed to become it. There is no
 * portal login on this install to borrow (260 contacts, none with
 * contact_user_id, and no user_type = 2 users), and the portal gate
 * client/includes/check_login.php reads $_SESSION['user_id'] - the SAME key the
 * agent gate includes/auth_check.php reads - so signing the admin in as a
 * contact would overwrite the admin's own identity in their own browser
 * session. These two handlers instead drive a separate, session-only preview
 * state owned by client/includes/portal_preview.php:
 *
 *   portalPreviewEnter($client_id)  sets the state and audit-logs "Entered"
 *   portalPreviewExit($reason)      clears the state and audit-logs "Exited"
 *
 * That state is not a credential. portal_preview.php re-derives the admin from
 * the database on every single request, and every write on the portal side is
 * blocked while a preview is running, so the admin can look and can do nothing.
 *
 * Both handlers are idempotent: entering twice ends at one preview, and exiting
 * when nothing is running is a no-op.
 */

// Lazily load the preview library for whichever of the two handlers below runs.
//  Lazily, because agent/post.php require_once's EVERY post/*.php file on EVERY
//  agent request - a top-level require here would drag this into the path of
//  hundreds of unrelated requests, and a deploy that landed this file before the
//  portal one would take the whole agent side down instead of failing one menu
//  item. portal_preview.php is include-guarded, so requiring it is safe.
if (isset($_GET['view_client_portal']) || isset($_GET['exit_client_portal'])) {
    $portal_preview_library = __DIR__ . '/../../client/includes/portal_preview.php';
    if (file_exists($portal_preview_library)) {
        require_once $portal_preview_library;
    }
}

if (isset($_GET['view_client_portal'])) {

    validateCSRFToken($_GET['csrf_token']);

    $client_id = intval($_GET['view_client_portal']);

    /*
     * ADMIN, specifically - deliberately not a module_client level check.
     * Looking at a department's portal is an administrative act, so it uses the
     * same test the admin area itself uses (admin/post.php:26,
     * admin/includes/inc_all_admin.php:7): $session_is_admin, which
     * includes/load_user_session.php derives from user_roles.role_is_admin, not
     * a legacy role number and not a permission level. This is the front door,
     * not the only lock - portal_preview.php re-runs the equivalent check
     * against the database on every later request.
     */
    if (empty($session_is_admin)) {
        logAction("Portal Preview", "Denied", "$session_name was denied a read-only portal preview of department $client_id (not an administrator)", $client_id, $session_user_id);
        flash_alert('Access Denied - only administrators can preview a department portal.', 'error');
        redirect('clients.php');
    }

    /*
     * Department scoping. Redundant while enforceClientAccess() short-circuits
     * for admins, but it is the check every other handler in this file that
     * takes a client_id straight off the query string was recently fixed to
     * make, and it is what keeps this honest if admin scoping ever tightens.
     */
    enforceClientAccess($client_id);

    if (!function_exists('portalPreviewEnter')) {
        flash_alert('Portal preview is not available on this install.', 'error');
        redirect("client_overview.php?client_id=$client_id");
    }

    // Nothing to preview if contacts cannot reach the portal at all. Checked
    // here only for the error message - portalPreviewEnter() enforces it too.
    if (isset($config_client_portal_enable) && intval($config_client_portal_enable) !== 1) {
        flash_alert('The client portal is switched off, so there is nothing to preview.', 'error');
        redirect("client_overview.php?client_id=$client_id");
    }

    // Switching departments mid-preview: close the old one explicitly so the
    // audit trail reads Entered -> Exited -> Entered instead of silently
    // swapping one department for another under a single "Entered" line.
    $current_preview_client_id = portalPreviewClientId();
    if ($current_preview_client_id > 0 && $current_preview_client_id !== $client_id) {
        portalPreviewExit('switched_department');
    }

    if (!portalPreviewEnter($client_id)) {
        /*
         * enter() refuses without saying why: the department is archived or
         * gone, the portal module is off, or the database says this session is
         * not a live admin agent after all. Fail closed, say so plainly, and
         * leave the admin where they were.
         */
        logAction("Portal Preview", "Failed", "$session_name could not open a read-only portal preview of department $client_id", $client_id, $session_user_id);
        flash_alert('Could not open a read-only preview of that department portal.', 'error');
        redirect("client_overview.php?client_id=$client_id");
    }

    // portalPreviewEnter() has already written the "Entered" audit line. No
    // flash alert: the portal shell renders a persistent banner that says far
    // more than a one-shot toast would.
    redirect('/client/index.php');

}

if (isset($_GET['exit_client_portal'])) {

    validateCSRFToken($_GET['csrf_token']);

    $requested_client_id = intval($_GET['exit_client_portal']);

    // Same admin gate as entry, on purpose. A non-admin never holds a valid
    // preview (portal_preview.php drops one the instant the database stops
    // saying "live admin agent"), so this is about keeping both halves of the
    // pair on identical terms rather than about stopping anything.
    if (empty($session_is_admin)) {
        flash_alert('Access Denied - only administrators can preview a department portal.', 'error');
        redirect('clients.php');
    }

    if (!function_exists('portalPreviewExit')) {
        flash_alert('Portal preview is not available on this install.', 'error');
        redirect($requested_client_id > 0 ? "client_overview.php?client_id=$requested_client_id" : 'clients.php');
    }

    // Where to land afterwards: the department actually being previewed, and
    // only if there is none, whatever the link carried. Never a dead end.
    $preview_client_id = portalPreviewClientId();
    if ($preview_client_id < 1) {
        $preview_client_id = $requested_client_id;
    }

    /*
     * Tear down FIRST, then work out where to send them. Exiting must never be
     * blockable: if the scoping check below bounces this admin to clients.php,
     * the preview is still off. portalPreviewExit() writes the "Exited" audit
     * line itself, and is a silent no-op when no preview is running - which is
     * what makes this handler safe to hit twice.
     */
    portalPreviewExit('agent_exit');

    if ($preview_client_id > 0) {
        enforceClientAccess($preview_client_id);
        flash_alert('Read-only portal preview ended.');
        redirect("client_overview.php?client_id=$preview_client_id");
    }

    flash_alert('Read-only portal preview ended.');
    redirect('clients.php');

}

if (isset($_POST['export_clients_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 1);

    //get records from database
    $sql = mysqli_query($mysqli, "SELECT * FROM clients
        LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
        LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
        WHERE client_archived_at IS NULL $access_permission_query
        ORDER BY client_name ASC
    ");

    $num_rows = mysqli_num_rows($sql);

    if ($num_rows > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '\\';   // backslash
        $filename = sanitize_filename($session_company_name . "-Departments-" . date('Y-m-d_H-i-s') . ".csv");

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Department Name', 'Industry', 'Referral', 'Website', 'Primary Location Name', 'Location Phone', 'Location Address', 'City', 'State', 'Postal Code', 'Country', 'Primary Contact Name', 'Title', 'Contact Phone', 'Extension', 'Contact Mobile', 'Contact Email', 'Hourly Rate', 'Currency', 'Payment Terms', 'Tax ID', 'Abbreviation');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while($row = $sql->fetch_assoc()) {
            $lineData = array($row['client_name'], $row['client_type'], $row['client_referral'], $row['client_website'], $row['location_name'], formatPhoneNumber($row['location_phone']), $row['location_address'], $row['location_city'], $row['location_state'], $row['location_zip'], $row['location_country'], $row['contact_name'], $row['contact_title'], formatPhoneNumber($row['contact_phone']), $row['contact_extension'], formatPhoneNumber($row['contact_mobile']), $row['contact_email'], $row['client_rate'], $row['client_currency_code'], $row['client_net_terms'], $row['client_tax_id_number'], $row['client_abbreviation']);
            fputcsv($f, $lineData, $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);

        logAction("Department", "Export", "$session_name exported $num_rows department(s) to a CSV file");

    }

    exit;

}

if (isset($_POST["import_clients_csv"])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);
    $error = false;

    if (!empty($_FILES["file"]["tmp_name"])) {
        $file_name = $_FILES["file"]["tmp_name"];
    } else {
        flash_alert("Please select a file to upload.", 'error');
        redirect();
    }

    //Check file is CSV
    $file_extension = strtolower(end(explode('.',$_FILES['file']['name'])));
    $allowed_file_extensions = array('csv');
    if (in_array($file_extension,$allowed_file_extensions) === false) {
        $error = true;
        flash_alert("Bad file extension", 'error');
    }

    //Check file isn't empty
    elseif ($_FILES["file"]["size"] < 1) {
        $error = true;
        flash_alert("Bad file size (empty?)", 'error');
    }

    //(Else)Check column count
    $f = fopen($file_name, "r");
    $f_columns = fgetcsv($f, 1000, ",");
    if (!$error & count($f_columns) != 22) {
        $error = true;
        flash_alert("Bad column count.", 'error');
    }

    //Else, parse the file
    if (!$error) {
        $file = fopen($file_name, "r");
        fgetcsv($file, 1000, ","); // Skip first line
        $row_count = 0;
        $duplicate_count = 0;
        while(($column = fgetcsv($file, 1000, ",")) !== false) {
            $duplicate_detect = 0;
            if (isset($column[0])) {
                $name = sanitizeInput($column[0]);
                if (mysqli_num_rows(mysqli_query($mysqli,"SELECT * FROM clients WHERE client_name = '$name'")) > 0) {
                    $duplicate_detect = 1;
                }
            }

            $industry = '';
            if (isset($column[1])) {
                $industry = sanitizeInput($column[1]);
            }

            $referral = '';
            if (isset($column[2])) {
                $referral = sanitizeInput($column[2]);
            }

            $website = '';
            if (isset($column[3])) {
                $website = sanitizeInput(preg_replace("(^https?://)", "", $column[3]));
            }

            $location_name = '';
            if (isset($column[4])) {
                $location_name = sanitizeInput($column[4]);
            }

            $location_phone = '';
            if (isset($column[5])) {
                $location_phone = preg_replace("/[^0-9]/", '', $column[5]);
            }

            $address = '';
            if (isset($column[6])) {
                $address = sanitizeInput($column[6]);
            }

            $city = '';
            if (isset($column[7])) {
                $city = sanitizeInput($column[7]);
            }

            $state = '';
            if (isset($column[8])) {
                $state = sanitizeInput($column[8]);
            }

            $zip = '';
            if (isset($column[9])) {
                $zip = sanitizeInput($column[9]);
            }

            $country = '';
            if (isset($column[10])) {
                $country = sanitizeInput($column[10]);
            }

            $contact_name = '';
            if (isset($column[11])) {
                $contact_name = sanitizeInput($column[11]);
            }

            $title = '';
            if (isset($column[12])) {
                $title = sanitizeInput($column[12]);
            }

            $contact_phone = '';
            if (isset($column[13])) {
                $contact_phone = preg_replace("/[^0-9]/", '',$column[13]);
            }

            $contact_extension = '';
            if (isset($column[14])) {
                $contact_extension = preg_replace("/[^0-9]/", '',$column[14]);
            }

            $contact_mobile = '';
            if (isset($column[15])) {
                $contact_mobile = preg_replace("/[^0-9]/", '',$column[15]);
            }

            $contact_email = '';
            if (isset($column[16])) {
                $contact_email = sanitizeInput($column[16]);
            }

            $hourly_rate = $config_default_hourly_rate;
            if (isset($column[17])) {
                $hourly_rate = floatval($column[17]);
            }

            $currency_code = sanitizeInput($session_company_currency);
            if (isset($column[18])) {
                $currency_code = sanitizeInput($column[18]);
            }

            $payment_terms = sanitizeInput($config_default_net_terms);
            if (isset($column[19])) {
                $payment_terms = intval($column[19]);
            }

            $tax_id_number = '';
            if (isset($column[20])) {
                $tax_id_number = sanitizeInput($column[20]);
            }

            $abbreviation = '';
            if (isset($column[21])) {
                $abbreviation = sanitizeInput($column[21]);
            }

            // Check if duplicate was detected
            if ($duplicate_detect == 0) {
                //Add
                // Create client
                mysqli_query($mysqli, "INSERT INTO clients SET client_name = '$name', client_type = '$industry', client_website = '$website', client_referral = '$referral', client_rate = $hourly_rate, client_currency_code = '$currency_code', client_net_terms = $payment_terms, client_tax_id_number = '$tax_id_number', client_abbreviation = '$abbreviation'");

                $client_id = mysqli_insert_id($mysqli);

                if (!file_exists("../uploads/clients/$client_id")) {
                    mkdir("../uploads/clients/$client_id");
                    file_put_contents("../uploads/clients/$client_id/index.php", "");
                }

                // Create Referral if it doesn't exist
                $sql = mysqli_query($mysqli, "SELECT category_name FROM categories WHERE category_type = 'Referral' AND category_archived_at IS NULL AND category_name = '$referral'");
                if(mysqli_num_rows($sql) == 0) {
                    mysqli_query($mysqli, "INSERT INTO categories SET category_name = '$referral', category_type = 'Referral'");
                    // Logging
                    logAction("Category", "Create", "$session_name created new refferal category $referral");
                }

                // Create Location
                mysqli_query($mysqli, "INSERT INTO locations SET location_name = '$location_name', location_address = '$address', location_city = '$city', location_state = '$state', location_zip = '$zip', location_phone = '$location_phone', location_country = '$country', location_primary = 1, location_client_id = $client_id");
                $new_location_id = mysqli_insert_id($mysqli);
                mysqli_query($mysqli, "INSERT IGNORE INTO department_sites SET client_id = $client_id, location_id = $new_location_id");

                // Create Contact
                mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$contact_name', contact_title = '$title', contact_phone = '$contact_phone', contact_extension = '$contact_extension', contact_mobile = '$contact_mobile', contact_email = '$contact_email', contact_primary = 1, contact_important = 1, contact_client_id = $client_id");

                $row_count = $row_count + 1;

            } else {

                $duplicate_count = $duplicate_count + 1;

            }

        }
        fclose($file);

        // Training: keep the department job groups in step (one per active department). Never blocks the department save.
        try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
        catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

        logAction("Department", "Import", "$session_name imported $row_count department(s) via CSV file, $duplicate_count duplicate(s) found");

        flash_alert("<strong>$row_count</strong> Department(s) added, <strong>$duplicate_count</strong> duplicate(s) found");

        redirect();

    }

    //Check for any errors, if there are notify user and redirect
    if ($error) {
        redirect();
    }
}

if (isset($_GET['download_clients_csv_template'])) {

    $delimiter = ",";
    $enclosure = '"';
    $escape    = '\\';   // backsla
    $filename = "Departments-Template.csv";

    //create a file pointer
    $f = fopen('php://memory', 'w');

    //set column headers
    $fields = array('Department Name', 'Industry', 'Referral', 'Website', 'Primary Location Name', 'Location Phone', 'Location Address', 'City', 'State', 'Postal Code', 'Country', 'Primary Contact Name', 'Title', 'Contact Phone', 'Extension', 'Contact Mobile', 'Contact Email', 'Hourly Rate', 'Currency', 'Payment Terms', 'Tax ID', 'Abbreviation');
    fputcsv($f, $fields, $delimiter, $enclosure, $escape);

    //move back to beginning of file
    fseek($f, 0);

    //set headers to download file rather than displayed
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '";');

    //output all remaining data on a file pointer
    fpassthru($f);

    exit;

}

if (isset($_POST['bulk_add_client_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $assigned_to = resolveTicketAssignee(intval($_POST['bulk_assigned_to']));
    $ticket_status = resolveTicketCreationStatus($assigned_to);
    $subject = sanitizeInput($_POST['bulk_subject']);
    $priority = sanitizeInput($_POST['bulk_priority']);
    $category_id = resolveTicketCategory(intval($_POST['bulk_category']));
    $details = mysqli_real_escape_string($mysqli, $_POST['bulk_details']);
    $project_id = intval($_POST['bulk_project']);
    $use_primary_contact = intval($_POST['use_primary_contact']);
    $ticket_template_id = intval($_POST['bulk_ticket_template_id']);
    $billable = intval($_POST['bulk_billable'] ?? 0);

    // Check to see if adding a ticket by template
    if($ticket_template_id) {
        $sql = mysqli_query($mysqli, "SELECT * FROM ticket_templates WHERE ticket_template_id = $ticket_template_id");
        $row = mysqli_fetch_assoc($sql);

        // Override Template Subject
        if(empty($subject)) {
            $subject = sanitizeInput($row['ticket_template_subject']);
        }
        $details = mysqli_escape_string($mysqli, $row['ticket_template_details']);

        // Get Associated Tasks from the ticket template
        $sql_task_templates = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id");

    }

    // Create ticket for each selected asset
    if (isset($_POST['client_ids'])) {

        // Get a Asset Count
        $client_count = count($_POST['client_ids']);

        foreach ($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);

            $client_name = sanitizeInput($row['client_name']);

            // Atomically increment and get the new ticket number
            mysqli_query($mysqli, "
                UPDATE settings
                SET
                    config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                    config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1
            ");

            $ticket_number = mysqli_insert_id($mysqli);

            // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
            $config_ticket_prefix = sanitizeInput($config_ticket_prefix);
            $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
            $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
            $config_base_url = sanitizeInput($config_base_url);

            //Generate a unique URL key for clients to access
            $url_key = randomString(32);

            mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_category = $category_id, ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = $billable, ticket_status = $ticket_status, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned_to, ticket_url_key = '$url_key', ticket_client_id = $client_id, ticket_project_id = $project_id");

            $ticket_id = mysqli_insert_id($mysqli);

            // Add Tasks
            if (!empty($_POST['tasks'])) {
                foreach ($_POST['tasks'] as $task) {
                    $task_name = sanitizeInput($task);
                    // Check that task_name is not-empty (For some reason the !empty on the array doesnt work here like in watchers)
                    if (!empty($task_name)) {
                        mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_name', task_ticket_id = $ticket_id");
                    }
                }
            }

            // Add Tasks from Template if Template was selected
            if($ticket_template_id) {
                if (mysqli_num_rows($sql_task_templates) > 0) {
                    while ($row = mysqli_fetch_assoc($sql_task_templates)) {
                        $task_order = intval($row['task_template_order']);
                        $task_name = sanitizeInput($row['task_template_name']);

                        mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_name', task_order = $task_order, task_ticket_id = $ticket_id");
                    }
                }
            }

            // Custom action/notif handler
            customAction('ticket_create', $ticket_id);
        }

        logAction("Ticket", "Bulk Create", "$session_name created $client_count tickets for $client_name");

        flash_alert("<strong>$client_count</strong> tickets created for selected departments");

    }

    redirect();

}

if (isset($_POST['bulk_edit_client_industry'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $industry = sanitizeInput($_POST['bulk_industry']);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_type = '$industry' WHERE client_id = $client_id");

            logAction("Department", "Edit", "$session_name set Industry to $industry for $client_name", $client_id);

        }

        logAction("Department", "Bulk Edit", "$session_name set the department $industry for $count department(s)", $client_id);

        flash_alert("Set the Industry to <strong>$industry</strong> for <strong>$count</strong> departments");
    }

    redirect();

}

if (isset($_POST['bulk_edit_client_referral'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $referral = sanitizeInput($_POST['bulk_referral']);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_referral = '$referral' WHERE client_id = $client_id");

            logAction("Department", "Edit", "$session_name set Referral to $referral for $client_name", $client_id);

        }

        logAction("Department", "Bulk Edit", "$session_name set the referral $referral for $count department(s)", $client_id);

        flash_alert("Set the Referral to <strong>$referral</strong> for <strong>$count</strong> departments");
    }

    redirect();

}

if (isset($_POST['bulk_edit_client_hourly_rate'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $rate = floatval($_POST['bulk_rate']);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_rate = '$rate' WHERE client_id = $client_id");

            logAction("Department", "Edit", "$session_name set Hourly Rate to" . numfmt_format_currency($currency_format, $rate, $session_company_currency) . "for $client_name", $client_id);

        }

        logAction("Department", "Bulk Edit", "$session_name set the hourly rate" . numfmt_format_currency($currency_format, $rate, $session_company_currency) . "for $count department(s)", $client_id);

        flash_alert("Set the Hourly Rate to <strong>" . numfmt_format_currency($currency_format, $rate, $session_company_currency) . "</strong> for <strong>$count</strong> department(s)");
    }

    redirect();

}

if (isset($_POST['bulk_edit_client_net_terms'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $net_terms = intval($_POST['net_terms']);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_net_terms = $net_terms WHERE client_id = $client_id");

            logAction("Department", "Edit", "$session_name set net terms to $net_terms days for $client_name", $client_id);

        }

        logAction("Department", "Bulk Edit", "$session_name set the net terms to $net_terms days for $count department(s)", $client_id);

        flash_alert("Set Net Term to <strong>$net_terms days</strong> for <strong>$count</strong> department(s)");
    }

    redirect();

}

if (isset($_POST['bulk_assign_client_tags'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach($_POST['client_ids'] as $client_id) {
            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            if ($_POST['bulk_remove_tags']) {
                mysqli_query($mysqli, "DELETE FROM client_tags WHERE client_id = $client_id");
            }

            if (isset($_POST['bulk_tags'])) {
                foreach($_POST['bulk_tags'] as $tag) {
                    $tag = intval($tag);

                    $sql = mysqli_query($mysqli,"SELECT * FROM client_tags WHERE client_id = $client_id AND tag_id = $tag");
                    if (mysqli_num_rows($sql) == 0) {
                        mysqli_query($mysqli, "INSERT INTO client_tags SET client_id = $client_id, tag_id = $tag");
                    }
                }
            }

            logAction("Department", "Edit", "$session_name added tags to $client_name", $client_id, $client_id);

        }

        logAction("Department", "Bulk Edit", "$session_name added tags for $count departments", $client_id);

        flash_alert("Assigned tags for <strong>$count</strong> departments");
    }

    redirect();

}

if (isset($_POST['bulk_send_client_email']) && isset($_POST['client_ids'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 1);

    $client_ids = array_map('intval', $_POST['client_ids']);
    $count = count($client_ids);

    foreach ($client_ids as $bulk_email_client_id) {
        enforceClientAccess($bulk_email_client_id);
    }

    // Email metadata
    $mail_from = sanitizeInput($_POST['mail_from']);
    $mail_from_name = sanitizeInput($_POST['mail_from_name']);
    $subject = sanitizeInput($_POST['subject']);
    $body = mysqli_real_escape_string($mysqli, $_POST['body']);
    $queued_at = sanitizeInput($_POST['queued_at']);

    // Build contact type filters
    $filters = [];

    if (!empty($_POST['primary_contacts'])) {
        $filters[] = "contact_primary = 1";
    }
    if (!empty($_POST['important_contacts'])) {
        $filters[] = "contact_important = 1";
    }
    if (!empty($_POST['billing_contacts'])) {
        $filters[] = "contact_billing = 1";
    }
    if (!empty($_POST['technical_contacts'])) {
        $filters[] = "contact_technical = 1";
    }

    if (empty($filters)) {
        flash_alert("Select at least one recipient group (primary, important, billing or technical).", 'error');
        redirect();
    }

    $contact_filter_query = '';
    if (!empty($filters)) {
        $contact_filter_query = ' AND (' . implode(' OR ', $filters) . ')';
    }

    // Prepare client ID list for SQL
    $client_ids_str = implode(',', $client_ids);

    // SQL to fetch matching contacts
    $sql = "SELECT * FROM contacts
            WHERE contact_client_id IN ($client_ids_str)
            AND contact_archived_at IS NULL
            $contact_filter_query";

    $result = mysqli_query($mysqli, $sql);

    $data = [];
    $unique_contacts = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $contact_email = sanitizeInput($row['contact_email']);

        // Skip if email is missing or invalid
        if (empty($contact_email) || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        // Skip duplicates (same email)
        if (isset($unique_contacts[$contact_email])) {
            continue;
        }
        $unique_contacts[$contact_email] = true;

        $contact_name = sanitizeInput($row['contact_name']);

        $data[] = [
            'from' => $mail_from,
            'from_name' => $mail_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => $subject,
            'body' => $body,
            'queued_at' => $queued_at
        ];
    }

    if (!empty($data)) {
        addToMailQueue($data);
        logAction("Bulk Mail", "Send", "$session_name sent " . count($data) . " messages via bulk mail");
        flash_alert("<strong>" . count($data) . "</strong> messages queued");
    } else {
        flash_alert("No valid contacts found to queue emails.", 'error');
    }

    redirect();

}

if (isset($_POST['bulk_archive_clients'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    if (isset($_POST['client_ids'])) {

        $count = 0;

        foreach ($_POST['client_ids'] as $client_id) {

            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_archived_at = NOW() WHERE client_id = $client_id");

            logAction("Department", "Archive", "$session_name archived $client_name", $client_id);

            $count++;

        }

        // Training: keep the department job groups in step (one per active department). Never blocks the department save.
        try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
        catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

        logAction("Department", "Bulk Archive", "$session_name archived $count departments", $client_id);

        flash_alert("Archived $count department(s)", 'error');

    }

    redirect();

}

if (isset($_POST['bulk_unarchive_clients'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    if (isset($_POST['client_ids'])) {

        $count = count($_POST['client_ids']);

        foreach ($_POST['client_ids'] as $client_id) {

            $client_id = intval($client_id);

            enforceClientAccess($client_id);

            $sql = mysqli_query($mysqli,"SELECT client_name FROM clients WHERE client_id = $client_id");
            $row = mysqli_fetch_assoc($sql);
            $client_name = sanitizeInput($row['client_name']);

            mysqli_query($mysqli,"UPDATE clients SET client_archived_at = NULL WHERE client_id = $client_id");

            logAction("Department", "Restore", "$session_name restored $client_name", $client_id);

        }

        // Training: keep the department job groups in step (one per active department). Never blocks the department save.
        try { if (($config_module_enable_training ?? 0) == 1 && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) { \ITFlow\Training\People\DepartmentGroups::safeSync($mysqli, 'department'); } }
        catch (\Throwable $e) { error_log('Training: department job group sync failed: ' . $e->getMessage()); }

        logAction("Department", "Bulk Restore", "$session_name restored $count department(s)", $client_id);

        flash_alert("You restored <strong>$count</strong> department(s)");

    }

    redirect();

}

if (isset($_POST["export_client_pdf"])) {

    validateCSRFToken($_POST['csrf_token']);

    // Enforce permissions
    enforceUserPermission("module_client", 3);
    enforceUserPermission("module_support", 1);
    enforceUserPermission("module_sales", 1);
    enforceUserPermission("module_financial", 1);

    $sql = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_name = nullable_htmlentities($row['company_name']);
    $company_phone_country_code = nullable_htmlentities($row['company_phone_country_code']);
    $company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
    $company_email = nullable_htmlentities($row['company_email']);
    $company_website = nullable_htmlentities($row['company_website']);
    $company_logo = nullable_htmlentities($row['company_logo']);

    $client_id = intval($_POST["client_id"]);

    enforceClientAccess($client_id);

    $export_contacts = intval($_POST["export_contacts"]);
    $export_locations = intval($_POST["export_locations"]);
    $export_assets = intval($_POST["export_assets"]);
    $export_software = intval($_POST["export_software"]);
    $export_credentials = 0;
    if (lookupUserPermission("module_credential") >= 1) {
        $export_credentials = intval($_POST["export_credentials"] ?? 0);
    }
    $export_networks = intval($_POST["export_networks"]);
    $export_certificates = intval($_POST["export_certificates"]);
    $export_domains = intval($_POST["export_domains"]);
    $export_tickets = intval($_POST["export_tickets"]);
    $export_recurring_tickets = intval($_POST["export_recurring_tickets"]);
    $export_vendors = intval($_POST["export_vendors"]);
    $export_invoices = intval($_POST["export_invoices"]);
    $export_recurring_invoices = intval($_POST["export_recurring_invoices"]);
    $export_quotes = intval($_POST["export_quotes"]);
    $export_payments = intval($_POST["export_payments"]);
    $export_trips = intval($_POST["export_trips"]);
    $export_logs = intval($_POST["export_logs"]);

    logAction("Department", "Export", "$session_name exported department data to a PDF file", $client_id, $client_id);

    // Get client record (joining primary contact and primary location)
    $sql = mysqli_query($mysqli, "SELECT * FROM clients
        LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
        LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
        WHERE client_id = $client_id
    ");
    $row = mysqli_fetch_assoc($sql);

    // Immediately sanitize retrieved values
    $client_name = nullable_htmlentities($row["client_name"]);
    $location_address = nullable_htmlentities($row["location_address"]);
    $location_city = nullable_htmlentities($row["location_city"]);
    $location_state = nullable_htmlentities($row["location_state"]);
    $location_zip = nullable_htmlentities($row["location_zip"]);
    $contact_name = nullable_htmlentities($row["contact_name"]);
    $contact_phone_country_code = nullable_htmlentities($row["contact_phone_country_code"]);
    $contact_phone = nullable_htmlentities(formatPhoneNumber($row["contact_phone"], $contact_phone_country_code));
    $contact_extension = nullable_htmlentities($row["contact_extension"]);
    $contact_mobile_country_code = nullable_htmlentities($row["contact_mobile_country_code"]);
    $contact_mobile = nullable_htmlentities(formatPhoneNumber($row["contact_mobile"], $contact_mobile_country_code));
    $contact_email = nullable_htmlentities($row["contact_email"]);
    $client_website = nullable_htmlentities($row["client_website"]);

    // Other queries remain unchanged
    $sql_contacts = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_name ASC");
    $sql_locations = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_client_id = $client_id AND location_archived_at IS NULL ORDER BY location_name ASC");
    $sql_vendors = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_client_id = $client_id AND vendor_archived_at IS NULL ORDER BY vendor_name ASC");
    $sql_credentials = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_client_id = $client_id ORDER BY credential_name ASC");
    $sql_assets = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN contacts ON asset_contact_id = contact_id
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND asset_archived_at IS NULL
        ORDER BY asset_type ASC"
    );
    $sql_asset_workstations = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN contacts ON asset_contact_id = contact_id
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND (asset_type = 'desktop' OR asset_type = 'laptop')
        AND asset_archived_at IS NULL
        ORDER BY asset_name ASC"
    );
    $sql_asset_servers = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND asset_type = 'server'
        AND asset_archived_at IS NULL
        ORDER BY asset_name ASC"
    );
    $sql_asset_vms = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND asset_type = 'virtual machine'
        AND asset_archived_at IS NULL
        ORDER BY asset_name ASC"
    );
    $sql_asset_network = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND (asset_type = 'Firewall/Router' OR asset_type = 'Switch' OR asset_type = 'Access Point')
        AND asset_archived_at IS NULL
        ORDER BY asset_type ASC"
    );
    $sql_asset_other = mysqli_query($mysqli, "SELECT * FROM assets
        LEFT JOIN contacts ON asset_contact_id = contact_id
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id
        AND (asset_type NOT LIKE 'laptop' AND asset_type NOT LIKE 'desktop' AND asset_type NOT LIKE 'server' AND asset_type NOT LIKE 'virtual machine' AND asset_type NOT LIKE 'firewall/router' AND asset_type NOT LIKE 'switch' AND asset_type NOT LIKE 'access point')
        AND asset_archived_at IS NULL
        ORDER BY asset_type ASC"
    );
    $sql_networks = mysqli_query($mysqli, "SELECT * FROM networks WHERE network_client_id = $client_id AND network_archived_at IS NULL ORDER BY network_name ASC");
    $sql_domains = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_client_id = $client_id AND domain_archived_at IS NULL ORDER BY domain_name ASC");
    $sql_certficates = mysqli_query($mysqli, "SELECT * FROM certificates WHERE certificate_client_id = $client_id AND certificate_archived_at IS NULL ORDER BY certificate_name ASC");
    $sql_software = mysqli_query($mysqli, "SELECT * FROM software WHERE software_client_id = $client_id AND software_archived_at IS NULL ORDER BY software_name ASC");

    $sql_user_licenses = mysqli_query($mysqli, "
        SELECT
            contact_name,
            software_name
        FROM
            software_contacts
        JOIN
            contacts ON software_contacts.contact_id = contacts.contact_id
        JOIN
            software ON software_contacts.software_id = software.software_id
        WHERE software_archived_at IS NULL
        AND contact_archived_at IS NULL
        AND software_client_id = $client_id
        AND contact_client_id = $client_id
        ORDER BY
            contact_name, software_name;"
    );

    $sql_asset_licenses = mysqli_query($mysqli, "
        SELECT
            asset_name,
            software_name
        FROM
            software_assets
        JOIN
            assets ON software_assets.asset_id = assets.asset_id
        JOIN
            software ON software_assets.software_id = software.software_id
        WHERE software_archived_at IS NULL
        AND asset_archived_at IS NULL
        AND software_client_id = $client_id
        AND asset_client_id = $client_id
        ORDER BY
            asset_name, software_name;"
    );

    require_once("../plugins/TCPDF/tcpdf.php");

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, "UTF-8", false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($session_company_name);
    $pdf->SetTitle("$client_name - IT Documentation");

    // TODO: Add page numbers to footer, but can't work out how to do it without the ugly line
    //    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    //    $pdf->setFooterData();

    // Enable auto page breaks with a margin from the bottom
    $pdf->SetAutoPageBreak(true, 15);

    // ----- Start Main Content -----
    $pdf->AddPage();
    $pdf->SetFont("freeserif", "", 10);

    // Build the HTML content with enhanced styling and semantic markup
    $html = "
    <style>
      body { font-family: sans-serif; margin: 0; padding: 0; }
      .cover { text-align: center; margin-top: 50px; margin-bottom: 30px; }
      .cover h1 { font-size: 28px; color: #000; margin-bottom: 10px; }
      .cover h2 { font-size: 20px; color: #000; margin-bottom: 10px; }
      .cover p { font-size: 14px; color: #000; }
      .client-header { padding: 10px 20px; margin-bottom: 20px; border: 1px solid #000; }
      .client-header p { margin: 4px 0; }
      .client-header strong { display: inline-block; width: 140px; }
      h2.section-title { color: #000; border-bottom: 2px solid #000; padding-bottom: 5px; margin-top: 30px; font-size: 18px; }
      h3.subsection-title { color: #000; margin-top: 20px; font-size: 16px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
      table, th, td { border: 1px solid #000; }
      table th { background-color: #000; color: #fff; padding: 10px; text-align: left; font-weight: bold; }
      table td { padding: 10px; }
      thead { display: table-header-group; }
      tbody tr { page-break-inside: avoid; }
      tr:nth-child(even) { background-color: #f2f2f2; }
      hr { border: 0; border-top: 2px solid #000; margin: 20px 0; }
    </style>
    ";

    // Cover page section (for main content, not the TOC)
    $html .= '<div class="cover">';
    if (!empty($company_logo)) {
        //$pdf->Image('../uploads/settings/' . $company_logo, '', '', 35, 35, '', '', 'L', false, 300, '', false, false, 1, false, false, false);
        $html .= '<div style="text-align:right;">
        <img src="' . realpath('../uploads/settings/' . $company_logo) . '" width="120">
        </div>';
    }

    $html .= "
     <h1>IT Documentation</h1>
     <h2>$client_name</h2>
    ";

    $html .= "
    <h4>Prepared by $session_name on " . date("F j, Y") . "</h4>
    </div>
    ";

    $html .= "
      <br>
      <h4>$session_company_name</h4>
      $company_phone<br>$company_email<br>
    ";

    $html .= '<hr>';

    // Client header information (non-table)
    $html .= "
    <div class='client-header'>
      <h3>$client_name</h3>
      <p><strong>Address:</strong> $location_address</p>
      <p><strong>City State Zip:</strong> $location_city $location_state $location_zip</p>
      <p><strong>Phone:</strong> $contact_phone</p>
      <p><strong>Website:</strong> $client_website</p>
      <p><strong>Contact:</strong> $contact_name</p>
      <p><strong>Email:</strong> $contact_email</p>
    </div>";

    // Add bookmarks and TOC entries for each major section:

    // Contacts Section
    if (mysqli_num_rows($sql_contacts) > 0 && $export_contacts == 1) {
        $pdf->Bookmark("Contacts", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Contacts</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Title</th>
              <th>Department</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Mobile</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_contacts)) {
            $contact_name = nullable_htmlentities(getFallBack($row["contact_name"]));
            $contact_title = nullable_htmlentities(getFallBack($row["contact_title"]));
            $contact_department = nullable_htmlentities($row["contact_department"]);
            $contact_email = nullable_htmlentities($row["contact_email"]);
            $contact_phone_country_code = nullable_htmlentities($row["contact_phone_country_code"]);
            $contact_phone = nullable_htmlentities(formatPhoneNumber($row["contact_phone"], $contact_phone_country_code));
            $contact_extension = nullable_htmlentities($row["contact_extension"]);
            if (!empty($contact_extension)) {
                $contact_extension = "x$contact_extension";
            }
            $contact_mobile_country_code = nullable_htmlentities($row["contact_mobile_country_code"]);
            $contact_mobile = nullable_htmlentities(formatPhoneNumber($row["contact_mobile"], $contact_mobile_country_code));
            $html .= "
            <tr>
              <td>$contact_name</td>
              <td>$contact_title</td>
              <td>$contact_department</td>
              <td>$contact_email</td>
              <td>$contact_phone $contact_extension</td>
              <td>$contact_mobile</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Locations Section
    if (mysqli_num_rows($sql_locations) > 0 && $export_locations == 1) {
        $pdf->Bookmark("Locations", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Locations</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Address</th>
              <th>Phone</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_locations)) {
            $location_name = nullable_htmlentities($row["location_name"]);
            $location_address = nullable_htmlentities($row["location_address"]);
            $location_city = nullable_htmlentities($row["location_city"]);
            $location_state = nullable_htmlentities($row["location_state"]);
            $location_zip = nullable_htmlentities($row["location_zip"]);
            $location_phone_country_code = nullable_htmlentities($row["location_phone_country_code"]);
            $location_phone = nullable_htmlentities(formatPhoneNumber($row["location_phone"], $location_phone_country_code));
            $html .= "
            <tr>
              <td>$location_name</td>
              <td>$location_address $location_city $location_state $location_zip</td>
              <td>$location_phone</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Vendors Section
    if (mysqli_num_rows($sql_vendors) > 0 && $export_vendors == 1) {
        $pdf->Bookmark("Vendors", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Vendors</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th>Phone</th>
              <th>Website</th>
              <th>Account Number</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_vendors)) {
            $vendor_name = nullable_htmlentities($row["vendor_name"]);
            $vendor_description = nullable_htmlentities($row["vendor_description"]);
            $vendor_account_number = nullable_htmlentities($row["vendor_account_number"]);
            $vendor_phone_country_code = nullable_htmlentities($row["vendor_phone_country_code"]);
            $vendor_phone = nullable_htmlentities(formatPhoneNumber($row["vendor_phone"], $vendor_phone_country_code));
            $vendor_website = nullable_htmlentities($row["vendor_website"]);
            $html .= "
            <tr>
              <td>$vendor_name</td>
              <td>$vendor_description</td>
              <td>$vendor_phone</td>
              <td>$vendor_website</td>
              <td>$vendor_account_number</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Credentials Section
    if (mysqli_num_rows($sql_credentials) > 0 && $export_credentials == 1) {
        $pdf->Bookmark("Credentials", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Credentials</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th>Username</th>
              <th>Password</th>
              <th>TOTP</th>
              <th>URI</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_credentials)) {
            $credential_name = nullable_htmlentities($row["credential_name"]);
            $credential_description = getFallback(nullable_htmlentities($row["credential_description"]));
            $credential_username = nullable_htmlentities(decryptCredentialEntry($row["credential_username"]));
            $credential_password = nullable_htmlentities(decryptCredentialEntry($row["credential_password"]));
            $credential_totp_secret = getFallback(nullable_htmlentities($row['credential_otp_secret']));
            $credential_uri = getFallback(nullable_htmlentities($row["credential_uri"]));
            $html .= "
            <tr>
              <td>$credential_name</td>
              <td>$credential_description</td>
              <td>$credential_username</td>
              <td>$credential_password</td>
              <td>$credential_totp_secret</td>
              <td>$credential_uri</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Assets Section Header
    if (mysqli_num_rows($sql_assets) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Assets", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Assets</h2>";
    }

    // Workstations
    if (mysqli_num_rows($sql_asset_workstations) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Workstations", 1, 0, "", "", array(0,0,0));
        $html .= "
        <h3 class='subsection-title'>Workstations</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Model</th>
              <th>Serial</th>
              <th>OS</th>
              <th>Purchase Date</th>
              <th>Warranty Expire</th>
              <th>Install Date</th>
              <th>Assigned To</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_workstations)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $asset_type = nullable_htmlentities($row["asset_type"]);
            $asset_make = nullable_htmlentities($row["asset_make"]);
            $asset_model = nullable_htmlentities($row["asset_model"]);
            $asset_serial = nullable_htmlentities($row["asset_serial"]);
            $asset_os = nullable_htmlentities($row["asset_os"]);
            $asset_purchase_date = nullable_htmlentities($row["asset_purchase_date"]);
            $asset_warranty_expire = nullable_htmlentities($row["asset_warranty_expire"]);
            $asset_install_date = nullable_htmlentities($row["asset_install_date"]);
            $contact_name = nullable_htmlentities($row["contact_name"]);
            $location_name = nullable_htmlentities($row["location_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$asset_type</td>
              <td>$asset_make $asset_model</td>
              <td>$asset_serial</td>
              <td>$asset_os</td>
              <td>$asset_purchase_date</td>
              <td>$asset_warranty_expire</td>
              <td>$asset_install_date</td>
              <td>$contact_name</td>
              <td>$location_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Servers
    if (mysqli_num_rows($sql_asset_servers) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Servers", 1, 0, "", "", array(0,0,0));
        $html .= "
        <h3 class='subsection-title'>Servers</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Model</th>
              <th>Serial</th>
              <th>OS</th>
              <th>IP</th>
              <th>Purchase Date</th>
              <th>Warranty Expire</th>
              <th>Install Date</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_servers)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $asset_make = nullable_htmlentities($row["asset_make"]);
            $asset_model = nullable_htmlentities($row["asset_model"]);
            $asset_serial = nullable_htmlentities($row["asset_serial"]);
            $asset_os = nullable_htmlentities($row["asset_os"]);
            $asset_ip = nullable_htmlentities($row["interface_ip"]);
            $asset_purchase_date = nullable_htmlentities($row["asset_purchase_date"]);
            $asset_warranty_expire = nullable_htmlentities($row["asset_warranty_expire"]);
            $asset_install_date = nullable_htmlentities($row["asset_install_date"]);
            $location_name = nullable_htmlentities($row["location_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$asset_make $asset_model</td>
              <td>$asset_serial</td>
              <td>$asset_os</td>
              <td>$asset_ip</td>
              <td>$asset_purchase_date</td>
              <td>$asset_warranty_expire</td>
              <td>$asset_install_date</td>
              <td>$location_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Virtual Machines
    if (mysqli_num_rows($sql_asset_vms) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Virtual Machines", 1, 0, "", "", array(0,0,0));
        $html .= "
        <h3 class='subsection-title'>Virtual Machines</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>OS</th>
              <th>IP</th>
              <th>Install Date</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_vms)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $asset_os = nullable_htmlentities($row["asset_os"]);
            $asset_ip = nullable_htmlentities($row["interface_ip"]);
            $asset_install_date = nullable_htmlentities($row["asset_install_date"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$asset_os</td>
              <td>$asset_ip</td>
              <td>$asset_install_date</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Network Devices
    if (mysqli_num_rows($sql_asset_network) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Network Devices", 1, 0, "", "", array(0,0,0));
        $html .= "
        <h3 class='subsection-title'>Network Devices</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Model</th>
              <th>Serial</th>
              <th>IP</th>
              <th>Purchase Date</th>
              <th>Warranty Expire</th>
              <th>Install Date</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_network)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $asset_type = nullable_htmlentities($row["asset_type"]);
            $asset_make = nullable_htmlentities($row["asset_make"]);
            $asset_model = nullable_htmlentities($row["asset_model"]);
            $asset_serial = nullable_htmlentities($row["asset_serial"]);
            $asset_ip = nullable_htmlentities($row["interface_ip"]);
            $asset_purchase_date = nullable_htmlentities($row["asset_purchase_date"]);
            $asset_warranty_expire = nullable_htmlentities($row["asset_warranty_expire"]);
            $asset_install_date = nullable_htmlentities($row["asset_install_date"]);
            $location_name = nullable_htmlentities($row["location_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$asset_type</td>
              <td>$asset_make $asset_model</td>
              <td>$asset_serial</td>
              <td>$asset_ip</td>
              <td>$asset_purchase_date</td>
              <td>$asset_warranty_expire</td>
              <td>$asset_install_date</td>
              <td>$location_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Other Devices
    if (mysqli_num_rows($sql_asset_other) > 0 && $export_assets == 1) {
        $pdf->Bookmark("Other Devices", 1, 0, "", "", array(0,0,0));
        $html .= "
        <h3 class='subsection-title'>Other Devices</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Model</th>
              <th>Serial</th>
              <th>IP</th>
              <th>Purchase Date</th>
              <th>Warranty Expire</th>
              <th>Install Date</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_other)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $asset_type = nullable_htmlentities($row["asset_type"]);
            $asset_make = nullable_htmlentities($row["asset_make"]);
            $asset_model = nullable_htmlentities($row["asset_model"]);
            $asset_serial = nullable_htmlentities($row["asset_serial"]);
            $asset_ip = nullable_htmlentities($row["interface_ip"]);
            $asset_purchase_date = nullable_htmlentities($row["asset_purchase_date"]);
            $asset_warranty_expire = nullable_htmlentities($row["asset_warranty_expire"]);
            $asset_install_date = nullable_htmlentities($row["asset_install_date"]);
            $location_name = nullable_htmlentities($row["location_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$asset_type</td>
              <td>$asset_make $asset_model</td>
              <td>$asset_serial</td>
              <td>$asset_ip</td>
              <td>$asset_purchase_date</td>
              <td>$asset_warranty_expire</td>
              <td>$asset_install_date</td>
              <td>$location_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Software Section
    if (mysqli_num_rows($sql_software) > 0 && $export_software == 1) {
        $pdf->Bookmark("Software", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Software</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>License</th>
              <th>License Key</th>
              <th>Purchase Date</th>
              <th>Expiration Date</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_software)) {
            $software_name = nullable_htmlentities($row["software_name"]);
            $software_type = nullable_htmlentities($row["software_type"]);
            $software_license_type = nullable_htmlentities($row["software_license_type"]);
            $software_key = nullable_htmlentities($row["software_key"]);
            $software_purchase = nullable_htmlentities($row['software_purchase']);
            $software_expire = nullable_htmlentities($row['software_expire']);
            $software_notes = nullable_htmlentities($row["software_notes"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$software_name</td>
              <td>$software_type</td>
              <td>$software_license_type</td>
              <td>$software_key</td>
              <td>$software_purchase</td>
              <td>$software_expire</td>
              <td>$software_notes</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // User Assigned Software Licenses
    if (mysqli_num_rows($sql_user_licenses) > 0 && $export_software == 1) {
        $pdf->Bookmark("User Assigned Licenses", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>User Assigned Licenses</h2>
        <table>
          <thead>
            <tr>
              <th>User</th>
              <th>Software</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_user_licenses)) {
            $contact_name = nullable_htmlentities($row["contact_name"]);
            $software_name = nullable_htmlentities($row["software_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$contact_name</td>
              <td>$software_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Asset Assigned Software Licenses
    if (mysqli_num_rows($sql_asset_licenses) > 0 && $export_software == 1) {
        $pdf->Bookmark("Asset Assigned Licenses", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Asset Assigned Licenses</h2>
        <table>
          <thead>
            <tr>
              <th>Asset</th>
              <th>Software</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_asset_licenses)) {
            $asset_name = nullable_htmlentities($row["asset_name"]);
            $software_name = nullable_htmlentities($row["software_name"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$asset_name</td>
              <td>$software_name</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Networks Section
    if (mysqli_num_rows($sql_networks) > 0 && $export_networks == 1) {
        $pdf->Bookmark("Networks", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Networks</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>vLAN</th>
              <th>Network Subnet</th>
              <th>Gateway</th>
              <th>DHCP Range</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_networks)) {
            $network_name = nullable_htmlentities($row["network_name"]);
            $network_vlan = nullable_htmlentities($row["network_vlan"]);
            $network = nullable_htmlentities($row["network"]);
            $network_gateway = nullable_htmlentities($row["network_gateway"]);
            $network_dhcp_range = nullable_htmlentities($row["network_dhcp_range"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$network_name</td>
              <td>$network_vlan</td>
              <td>$network</td>
              <td>$network_gateway</td>
              <td>$network_dhcp_range</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Domains Section
    if (mysqli_num_rows($sql_domains) > 0 && $export_domains == 1) {
        $pdf->Bookmark("Domains", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Domains</h2>
        <table>
          <thead>
            <tr>
              <th>Domain Name</th>
              <th>Expiration Date</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_domains)) {
            $domain_name = nullable_htmlentities($row["domain_name"]);
            $domain_expire = nullable_htmlentities($row["domain_expire"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$domain_name</td>
              <td>$domain_expire</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Certificates Section
    if (mysqli_num_rows($sql_certficates) > 0 && $export_certificates == 1) {
        $pdf->Bookmark("Certificates", 0, 0, "", "B", array(0,0,0));
        $html .= "
        <h2 class='section-title'>Certificates</h2>
        <table>
          <thead>
            <tr>
              <th>Certificate Name</th>
              <th>Domain Name</th>
              <th>Issuer</th>
              <th>Expiration Date</th>
            </tr>
          </thead>
          <tbody>";
        while ($row = mysqli_fetch_assoc($sql_certficates)) {
            $certificate_name = nullable_htmlentities($row["certificate_name"]);
            $certificate_domain = nullable_htmlentities($row["certificate_domain"]);
            $certificate_issued_by = nullable_htmlentities($row["certificate_issued_by"]);
            $certificate_expire = nullable_htmlentities($row["certificate_expire"]);
            $html .= "
            <tr style='page-break-inside: avoid;'>
              <td>$certificate_name</td>
              <td>$certificate_domain</td>
              <td>$certificate_issued_by</td>
              <td>$certificate_expire</td>
            </tr>";
        }
        $html .= "
          </tbody>
        </table>";
    }

    // Write the HTML content to the PDF document
    $pdf->writeHTML($html, true, false, true, false, "");

    // Output the PDF document for download
    $pdf->Output(strtoAZaz09($client_name) . "-IT_Documentation-" . date("Y-m-d") . ".pdf", "D");
    exit;

}
