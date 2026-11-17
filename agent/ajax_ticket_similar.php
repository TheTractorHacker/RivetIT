<?php

/*
 * "Similar open tickets" hint for the New Ticket dialog (modals/ticket/ticket_add_v2.php).
 * Read-only GET, JSON. Open (not resolved, not archived) tickets whose subject shares words with the typed subject,
 * scoped to the signed-in role's departments. Nothing is written.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');

if (lookupUserPermission('module_support') < 1) {
    echo json_encode(['tickets' => []]);
    exit;
}

$raw = trim((string) ($_GET['q'] ?? ''));
$client_id = intval($_GET['client_id'] ?? 0);

$stop = ['with', 'from', 'that', 'this', 'have', 'will', 'cant', 'cannot', 'not', 'and', 'the', 'for', 'are', 'was', 'when', 'issue', 'problem', 'help', 'please', 'need'];
$words = [];
foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) as $w) {
    if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) {
        $words[$w] = true;
    }
}
$words = array_slice(array_keys($words), 0, 6);

if (!$words) {
    echo json_encode(['tickets' => []]);
    exit;
}

$score = [];
$where = [];
foreach ($words as $w) {
    $like = "'%" . mysqli_real_escape_string($mysqli, addcslashes($w, '%_')) . "%'";
    $score[] = "(ticket_subject LIKE $like)";
    $where[] = "ticket_subject LIKE $like";
}
$score_sql = implode(' + ', $score);
$where_sql = implode(' OR ', $where);
$client_sql = $client_id ? "AND ticket_client_id = $client_id" : '';

$sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, client_name, ($score_sql) AS match_score
    FROM tickets
    LEFT JOIN clients ON tickets.ticket_client_id = clients.client_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_archived_at IS NULL AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL
        AND ($where_sql)
        $client_sql
        $access_permission_query
    ORDER BY match_score DESC, ticket_id DESC LIMIT 4");

$out = [];
while ($sql && $row = mysqli_fetch_assoc($sql)) {
    $out[] = [
        'label' => $row['ticket_prefix'] . $row['ticket_number'],
        'subject' => mb_substr((string) $row['ticket_subject'], 0, 90),
        'status' => (string) $row['ticket_status_name'],
        'client' => (string) $row['client_name'],
        'url' => '/agent/ticket.php?client_id=' . intval($row['ticket_client_id']) . '&ticket_id=' . intval($row['ticket_id']),
    ];
}

echo json_encode(['tickets' => $out]);
