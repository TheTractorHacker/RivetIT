<?php
/* Run with: php tests/odoo_portal.php */
require_once __DIR__ . '/../includes/odoo_portal.php';

function checkOdoo(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$issuer = 'https://odoo.example.test';
checkOdoo(portalOdooBaseUrl('https://odoo.example.test/') === $issuer, 'Trailing slash normalization failed');
checkOdoo(portalOdooBaseUrl('http://odoo.example.test') === null, 'Plain HTTP accepted');
checkOdoo(portalOdooBaseUrl('https://user@odoo.example.test') === null, 'URL credentials accepted');
checkOdoo(portalOdooBaseUrl('https://odoo.example.test/path') === null, 'Unexpected URL path accepted');
checkOdoo(portalOdooBaseUrl('https://odoo.example.test?redirect=evil') === null, 'Query accepted');
checkOdoo(portalOdooBaseUrl('https://odoo.example.test#fragment') === null, 'Fragment accepted');

$identity = [
    'issuer' => $issuer, 'database' => 'production',
    'company_id' => 3, 'user_id' => 12, 'employee_id' => 34,
];
checkOdoo(portalOdooIdentityValid($identity, $issuer, 'production', 3), 'Valid identity rejected');
checkOdoo(!portalOdooIdentityValid([...$identity, 'issuer' => 'https://other.test'], $issuer, 'production', 3), 'Wrong issuer accepted');
checkOdoo(!portalOdooIdentityValid([...$identity, 'database' => 'staging'], $issuer, 'production', 3), 'Wrong database accepted');
checkOdoo(!portalOdooIdentityValid([...$identity, 'company_id' => 4], $issuer, 'production', 3), 'Wrong company accepted');
checkOdoo(!portalOdooIdentityValid([...$identity, 'employee_id' => '34'], $issuer, 'production', 3), 'String employee ID accepted');
checkOdoo(!portalOdooIdentityValid([...$identity, 'user_id' => 0], $issuer, 'production', 3), 'Empty user ID accepted');

$state = portalOdooBase64Url(random_bytes(32));
$pending = ['state' => $state, 'verifier' => portalOdooBase64Url(random_bytes(32)),
    'created' => 1000, 'issuer' => $issuer, 'integration_id' => 7];
checkOdoo(portalOdooPendingValid($pending, $state, $issuer, 7, 1100), 'Valid browser transaction rejected');
checkOdoo(!portalOdooPendingValid(null, $state, $issuer, 7, 1100), 'Callback without browser transaction accepted');
checkOdoo(!portalOdooPendingValid($pending, portalOdooBase64Url(random_bytes(32)), $issuer, 7, 1100), 'Cross-browser state accepted');
checkOdoo(!portalOdooPendingValid($pending, $state, $issuer, 7, 1301), 'Expired browser transaction accepted');
checkOdoo(!portalOdooPendingValid($pending, $state, $issuer, 8, 1100), 'Wrong integration accepted');
checkOdoo(!portalOdooPendingValid($pending, $state, 'https://other.test', 7, 1100), 'Wrong issuer accepted');
checkOdoo(!portalOdooPendingValid([...$pending, 'created' => 1200], $state, $issuer, 7, 1100), 'Future transaction accepted');

echo "Odoo portal URL and identity validation passed.\n";
