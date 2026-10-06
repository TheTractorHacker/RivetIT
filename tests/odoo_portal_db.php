<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/* Run only in a disposable configured checkout: RIVETIT_TEST_DB=1 php tests/odoo_portal_db.php */
if (getenv('RIVETIT_TEST_DB') !== '1') {
    fwrite(STDERR, "Set RIVETIT_TEST_DB=1 in a disposable database checkout.\n");
    exit(2);
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/odoo_portal.php';

function assertOdooAccount(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

mysqli_begin_transaction($mysqli);
try {
    mysqli_query($mysqli, "INSERT INTO clients (client_name, client_currency_code, client_net_terms, client_status)
        VALUES ('Odoo SSO test department', 'USD', 0, 'Active')");
    $clientId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO odoo_integrations (base_url, database_name, enabled)
        VALUES ('https://odoo.example.test', 'test', 1)");
    $integrationId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status)
        VALUES ('Odoo SSO test', 'odoo-test@example.test', '', 'odoo', 2, 1)");
    $userId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO contacts (contact_name, contact_client_id, contact_user_id, contact_employment_status)
        VALUES ('Odoo SSO test', $clientId, $userId, 'active')");
    $contactId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id)
        VALUES ($contactId, $integrationId, 123456789)");
    $account = portalOdooEligibleAccount($mysqli, $integrationId, 123456789);
    assertOdooAccount($account !== null && (int) $account['user_id'] === $userId, 'Linked active portal user rejected');
    mysqli_query($mysqli, "UPDATE users SET user_email='renamed@example.test' WHERE user_id=$userId");
    assertOdooAccount(portalOdooEligibleAccount($mysqli, $integrationId, 123456789) !== null,
        'Changing email broke the stable employee link');
    mysqli_query($mysqli, "UPDATE users SET user_status=0 WHERE user_id=$userId");
    assertOdooAccount(portalOdooEligibleAccount($mysqli, $integrationId, 123456789) === null,
        'Disabled portal user accepted');
    mysqli_query($mysqli, "UPDATE users SET user_status=1 WHERE user_id=$userId");
    mysqli_query($mysqli, "UPDATE contacts SET contact_archived_at=NOW() WHERE contact_id=$contactId");
    assertOdooAccount(portalOdooEligibleAccount($mysqli, $integrationId, 123456789) === null,
        'Archived contact accepted');
    mysqli_query($mysqli, "UPDATE contacts SET contact_archived_at=NULL WHERE contact_id=$contactId");
    mysqli_query($mysqli, "UPDATE clients SET client_status='Inactive' WHERE client_id=$clientId");
    assertOdooAccount(portalOdooEligibleAccount($mysqli, $integrationId, 123456789) === null,
        'Inactive department accepted');
    mysqli_query($mysqli, "UPDATE clients SET client_status='Active' WHERE client_id=$clientId");
    mysqli_query($mysqli, "INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status)
        VALUES ('Duplicate Odoo SSO test', 'odoo-test-2@example.test', '', 'odoo', 2, 1)");
    $secondUserId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO contacts (contact_name, contact_client_id, contact_user_id)
        VALUES ('Duplicate Odoo SSO test', $clientId, $secondUserId)");
    $secondContactId = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id)
        VALUES ($secondContactId, $integrationId, 123456789)");
    assertOdooAccount(portalOdooEligibleAccount($mysqli, $integrationId, 123456789) === null,
        'Ambiguous employee mapping accepted');
    echo "Odoo portal account eligibility checks passed.\n";
} finally {
    mysqli_rollback($mysqli);
}
