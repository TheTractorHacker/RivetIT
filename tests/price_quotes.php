<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Price Quotes (agent/price_quotes.php, agent/price_quote.php, agent/post/price_quote.php) - the light,
 * standalone "give management a price" tool that replaced the old invoice-conversion quotes.php/quote.php:
 *   - line-item totals compute correctly (qty * unit price, summed into the quote total);
 *   - department scoping: a department-restricted read only sees its own department's quotes;
 *   - wiring: every write path requires module_client (edit level) and calls enforceClientAccess(), so a
 *     user without permission, or outside the department, cannot see/create one. No live login session
 *     exists in this harness, so the permission/access CALLS are asserted in the handler source (the same
 *     "no database" wiring-check style tests/payment_department_scope.php used for the handler it tested).
 *
 * Needs a THROWAWAY database that holds this app's schema (import db.sql + run the DB 2.6.193 migration,
 * or this test creates the two tables itself if they are missing). Never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/price_quotes.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "DB connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$q("SET SESSION sql_mode=''");
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// Create the tables if this scratch DB predates the DB 2.6.193 migration (admin/database_updates.php).
if (mysqli_num_rows($q("SHOW TABLES LIKE 'price_quotes'")) === 0) {
    $q("CREATE TABLE `price_quotes` (
        `price_quote_id` INT(11) NOT NULL AUTO_INCREMENT,
        `price_quote_title` VARCHAR(255) NOT NULL,
        `price_quote_description` TEXT DEFAULT NULL,
        `price_quote_status` VARCHAR(20) NOT NULL DEFAULT 'Draft',
        `price_quote_client_id` INT(11) NOT NULL,
        `price_quote_created_by` INT(11) NOT NULL DEFAULT 0,
        `price_quote_updated_by` INT(11) NOT NULL DEFAULT 0,
        `price_quote_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `price_quote_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        `price_quote_archived_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`price_quote_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
if (mysqli_num_rows($q("SHOW TABLES LIKE 'price_quote_items'")) === 0) {
    $q("CREATE TABLE `price_quote_items` (
        `price_quote_item_id` INT(11) NOT NULL AUTO_INCREMENT,
        `price_quote_item_price_quote_id` INT(11) NOT NULL,
        `price_quote_item_description` VARCHAR(255) NOT NULL,
        `price_quote_item_quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        `price_quote_item_unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `price_quote_item_order` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`price_quote_item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

foreach (['price_quote_items', 'price_quotes', 'clients'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients (client_id, client_name) VALUES (1,'Dept A'),(2,'Dept B')");

// --- Totals: free-text line items, no tax, no product catalog
$q("INSERT INTO price_quotes SET price_quote_title='Workstation refresh', price_quote_status='Draft', price_quote_client_id=1, price_quote_created_by=7, price_quote_updated_by=7");
$quoteA = (int) $one("SELECT price_quote_id FROM price_quotes WHERE price_quote_client_id = 1");
$q("INSERT INTO price_quote_items SET price_quote_item_price_quote_id=$quoteA, price_quote_item_description='Laptop', price_quote_item_quantity=3, price_quote_item_unit_price=950.00, price_quote_item_order=1");
$q("INSERT INTO price_quote_items SET price_quote_item_price_quote_id=$quoteA, price_quote_item_description='Setup labor', price_quote_item_quantity=2.5, price_quote_item_unit_price=75.00, price_quote_item_order=2");
$total = (float) $one("SELECT SUM(price_quote_item_quantity * price_quote_item_unit_price) FROM price_quote_items WHERE price_quote_item_price_quote_id = $quoteA");
$ok(abs($total - (3 * 950.00 + 2.5 * 75.00)) < 0.001, "line item totals sum correctly (3*950 + 2.5*75 = $total)");

// A line item with a fractional quantity and price still rounds the same way the page displays it (cents).
$q("INSERT INTO price_quote_items SET price_quote_item_price_quote_id=$quoteA, price_quote_item_description='Cable', price_quote_item_quantity=4, price_quote_item_unit_price=12.99, price_quote_item_order=3");
$total2 = (float) $one("SELECT SUM(price_quote_item_quantity * price_quote_item_unit_price) FROM price_quote_items WHERE price_quote_item_price_quote_id = $quoteA");
$ok(round($total2, 2) === round($total + 4 * 12.99, 2), "adding a line item updates the quote total ($total2)");

// Deleting a line item removes it from the total (agent/post/price_quote.php delete_price_quote_item).
$itemId = (int) $one("SELECT price_quote_item_id FROM price_quote_items WHERE price_quote_item_description = 'Cable'");
$q("DELETE FROM price_quote_items WHERE price_quote_item_id = $itemId");
$total3 = (float) $one("SELECT SUM(price_quote_item_quantity * price_quote_item_unit_price) FROM price_quote_items WHERE price_quote_item_price_quote_id = $quoteA");
$ok(round($total3, 2) === round($total, 2), "deleting a line item drops it from the total");

// --- Department scoping: a second quote belongs to Dept B
$q("INSERT INTO price_quotes SET price_quote_title='Network upgrade', price_quote_status='Sent', price_quote_client_id=2, price_quote_created_by=8, price_quote_updated_by=8");
$quoteB = (int) $one("SELECT price_quote_id FROM price_quotes WHERE price_quote_client_id = 2");
$q("INSERT INTO price_quote_items SET price_quote_item_price_quote_id=$quoteB, price_quote_item_description='Switch', price_quote_item_quantity=1, price_quote_item_unit_price=500.00, price_quote_item_order=1");

// agent/price_quotes.php builds its list query as "... WHERE ... $access_permission_query", the same
// pattern every other client-scoped list page uses (includes/load_user_session.php sets it from the
// signed-in user's client_access_string). Exercised directly here, same as the real query shape.
$restrictedScope = "AND clients.client_id IN (1)";
$unrestrictedScope = "";
$restricted = [];
$res = $q("SELECT price_quotes.price_quote_id, client_name FROM price_quotes LEFT JOIN clients ON clients.client_id = price_quote_client_id WHERE 1=1 $restrictedScope ORDER BY price_quote_id");
while ($row = mysqli_fetch_assoc($res)) { $restricted[] = $row['client_name']; }
$ok($restricted === ['Dept A'], 'a department-restricted read sees only its own department (got ' . implode(',', $restricted) . ')');

$all = [];
$res = $q("SELECT price_quotes.price_quote_id, client_name FROM price_quotes LEFT JOIN clients ON clients.client_id = price_quote_client_id WHERE 1=1 $unrestrictedScope ORDER BY price_quote_id");
while ($row = mysqli_fetch_assoc($res)) { $all[] = $row['client_name']; }
$ok($all === ['Dept A', 'Dept B'], 'an unrestricted read sees both departments');

// --- Wiring: every write path requires module_client level 2 and checks the department before writing
$postSrc = file_get_contents(__DIR__ . '/../agent/post/price_quote.php');
foreach (['add_price_quote', 'edit_price_quote', 'edit_price_quote_status', 'add_price_quote_item', 'edit_price_quote_item', 'delete_price_quote_item'] as $action) {
    $ok((bool) preg_match('/isset\(\$_(POST|GET)\[\'' . $action . '\'\]\)\)\s*\{(?:(?!^\})[\s\S])*?enforceUserPermission\(\'module_client\',\s*2\)/m', $postSrc), "$action requires module_client level 2 before writing");
    $ok((bool) preg_match('/isset\(\$_(POST|GET)\[\'' . $action . '\'\]\)\)\s*\{(?:(?!^\})[\s\S])*?enforceClientAccess\(/m', $postSrc), "$action checks the department (enforceClientAccess) before writing");
}
$ok(strpos($postSrc, "FROM price_quotes") !== false, 'the handler resolves a quote\'s own department before trusting any posted client_id for edits');

$pageSrc = file_get_contents(__DIR__ . '/../agent/price_quotes.php');
$ok(strpos($pageSrc, "enforceUserPermission('module_client')") !== false, 'the list page requires module_client to view');
$ok(strpos($pageSrc, '$access_permission_query') !== false, 'the list page applies the department-scoping clause');

$detailSrc = file_get_contents(__DIR__ . '/../agent/price_quote.php');
$ok(strpos($detailSrc, "enforceUserPermission('module_client')") !== false, 'the detail page requires module_client to view');
// Must check the QUOTE's own client_id unconditionally - not merely be present somewhere in the file, and not
// gated behind "only when client_id is absent from the URL". A version of this page once called
// enforceClientAccess() only inside `if (!isset($_GET['client_id']))`, which left the common case (every real
// link to this page includes client_id) relying solely on includes/inc_all_client.php's check of the URL's
// client_id - that check has no idea which quote is being requested, so pairing a guessed/enumerated
// price_quote_id with the user's own (legitimately accessible) client_id in the URL bypassed the department
// boundary entirely (IDOR). The fix moved the real check outside that conditional, against the row's own
// price_quote_client_id, every time - assert the exact unconditional call text, and that the vulnerable
// conditional-only pattern is gone.
$ok(strpos($detailSrc, "enforceClientAccess(intval(\$row['price_quote_client_id']));") !== false,
    'the detail page unconditionally checks the department against the QUOTE\'s own client_id (not the URL\'s)');
$ok(!preg_match('/if\s*\(\s*!isset\(\$_GET\[.client_id.\]\)\s*\)\s*\{\s*enforceClientAccess/', $detailSrc),
    'the detail page\'s access check is not skipped when client_id is present in the URL (the IDOR this guards against)');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
