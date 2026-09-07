<?php
// GET  /api/v1/expenses   list
// POST /api/v1/expenses   create (multipart with optional receipt)
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

$uid = $api_user_id;
// Reading the expense list is level 1, but creating one (and uploading a receipt with it)
// is a write: the web equivalent, agent/post/expense.php, requires level 2. Same idiom as
// tickets.php/worksheets.php - a read-only financial role could previously POST here.
api_require_module_permission($mysqli, $uid, 'module_financial', $method === 'GET' ? 1 : 2);

// Client-scope restriction, mirroring tickets.php/client_tabs.php.
$expense_client_scope_clause = api_client_scope_sql('e.expense_client_id');

if ($method === 'GET') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $total    = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM expenses e WHERE e.expense_archived_at IS NULL AND $expense_client_scope_clause"))['c']);
    $expenses = [];
    $sql      = mysqli_query($mysqli,
        "SELECT e.expense_id, e.expense_description, e.expense_amount, e.expense_currency_code,
                e.expense_date, e.expense_reference, e.expense_payment_method, e.expense_receipt,
                c.client_name
         FROM expenses e LEFT JOIN clients c ON e.expense_client_id = c.client_id
         WHERE e.expense_archived_at IS NULL AND $expense_client_scope_clause
         ORDER BY e.expense_date DESC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $expenses[] = [
            'id'             => intval($row['expense_id']),
            'description'    => $row['expense_description'],
            'amount'         => floatval($row['expense_amount']),
            'currency'       => $row['expense_currency_code'],
            'date'           => $row['expense_date'],
            'reference'      => $row['expense_reference'],
            'payment_method' => $row['expense_payment_method'],
            'has_receipt'    => !empty($row['expense_receipt']),
            'client'         => $row['client_name'],
        ];
    }
    api_response(200, ['data' => $expenses, 'total' => $total]);
}

if ($method === 'POST') {
    $description    = mysqli_real_escape_string($mysqli, trim($_POST['description'] ?? ''));
    $amount         = floatval($_POST['amount'] ?? 0);
    $date           = mysqli_real_escape_string($mysqli, trim($_POST['date'] ?? date('Y-m-d')));
    $reference      = mysqli_real_escape_string($mysqli, trim($_POST['reference'] ?? ''));
    $payment_method = mysqli_real_escape_string($mysqli, trim($_POST['payment_method'] ?? ''));
    $client_id      = intval($_POST['client_id'] ?? 0);
    $currency       = mysqli_real_escape_string($mysqli, trim($_POST['currency'] ?? 'USD'));

    if (!$description || $amount <= 0) api_error(400, 'description and amount required');

    if ($client_id && !api_client_scope_ok($client_id)) {
        api_error(403, 'Access denied');
    }

    $receipt_name = 'NULL';
    if (!empty($_FILES['receipt']['tmp_name'])) {
        // The stored extension is derived from the sniffed MIME type, never from the client's
        // filename. Taking it verbatim let a polyglot through: a file starting "GIF89a;" and
        // ending in script passes the finfo check as image/gif, but if it was uploaded as
        // "x.html" it was saved as .html under the web root and served back as text/html from
        // this app's own origin. This is the one upload path that doesn't go through
        // checkFileUpload(), which enforces an extension allow-list for the web app.
        $allowed = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
        ];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($_FILES['receipt']['tmp_name']);
        if (!isset($allowed[$mime])) api_error(400, 'Invalid receipt file type');

        $ext      = $allowed[$mime];
        $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest     = $_SERVER['DOCUMENT_ROOT'] . '/uploads/expenses/' . $filename;
        @mkdir(dirname($dest), 0755, true);
        if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
            api_error(500, 'Failed to save receipt');
        }
        $receipt_name = "'" . mysqli_real_escape_string($mysqli, $filename) . "'";
    }

    mysqli_query($mysqli,
        "INSERT INTO expenses (expense_description, expense_amount, expense_currency_code, expense_date,
                               expense_reference, expense_payment_method, expense_receipt, expense_client_id)
         VALUES ('$description', $amount, '$currency', '$date', '$reference', '$payment_method', $receipt_name, $client_id)"
    );

    api_response(201, ['id' => mysqli_insert_id($mysqli)]);
}

api_error(405, 'Method not allowed');
