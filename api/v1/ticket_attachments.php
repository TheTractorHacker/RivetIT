<?php
// GET  /api/v1/ticket_attachments.php?ticket_id=N          list a ticket's attachments
// GET  /api/v1/ticket_attachments.php?id=A&download=1      stream one attachment
// POST /api/v1/ticket_attachments.php (multipart: ticket_id, optional reply_id, file)  upload
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';

$uid = intval($api_user_id);

// Same module gate as the ticket endpoints and agent/post/ticket.php (upload = edit level).
api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

// Extensions the web upload (agent/post/ticket.php upload_ticket_attachment) allows. Anything else is refused.
const API_ATTACHMENT_MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt' => 'application/vnd.oasis.opendocument.text', 'csv' => 'text/csv',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odp' => 'application/vnd.oasis.opendocument.presentation',
    'zip' => 'application/zip', 'tar' => 'application/x-tar', 'gz' => 'application/gzip', 'xml' => 'application/xml',
    'msg' => 'application/vnd.ms-outlook', 'json' => 'application/json',
    'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
    'mov' => 'video/quicktime', 'mp4' => 'video/mp4', 'av1' => 'video/av1', 'ovpn' => 'application/x-openvpn-profile',
];

function api_attachment_mime(string $reference_name): string {
    $ext = strtolower(pathinfo($reference_name, PATHINFO_EXTENSION));
    return API_ATTACHMENT_MIME[$ext] ?? 'application/octet-stream';
}

/** Ticket the caller may see (module checked above + department scope), else null. Unknown and out-of-scope are indistinguishable. */
function api_attachment_ticket(int $ticket_id): ?array {
    global $mysqli;
    if ($ticket_id < 1) {
        return null;
    }
    $t = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_id, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id AND " . api_client_scope_sql('ticket_client_id') . " LIMIT 1"));
    return $t ?: null;
}

/** Absolute path of a stored attachment, or null when the name is not a legitimate reference name or the file is not inside the ticket's folder. */
function api_attachment_path(int $ticket_id, string $reference_name, string $document_root): ?string {
    if (!isUploadReferenceName($reference_name)) {
        return null;
    }
    $base = realpath("$document_root/uploads/tickets/$ticket_id");
    if ($base === false) {
        return null;
    }
    $path = realpath("$base/$reference_name");
    if ($path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
        return null;
    }
    return $path;
}

if ($method === 'GET') {
    $select = "SELECT a.ticket_attachment_id, a.ticket_attachment_name, a.ticket_attachment_reference_name, a.ticket_attachment_created_at,
                      a.ticket_attachment_ticket_id, u.user_name
               FROM ticket_attachments a
               LEFT JOIN ticket_replies r ON r.ticket_reply_id = a.ticket_attachment_reply_id
               LEFT JOIN users u ON u.user_id = r.ticket_reply_by AND r.ticket_reply_by > 0";

    if (isset($_GET['id'])) {
        $att_id = intval($_GET['id']);
        $att = mysqli_fetch_assoc(mysqli_query($mysqli, "$select WHERE a.ticket_attachment_id = $att_id LIMIT 1"));
        // Unknown attachment and an attachment of a ticket the caller cannot see give the same answer.
        if (!$att || !api_attachment_ticket(intval($att['ticket_attachment_ticket_id']))) {
            api_error(403, 'Access denied');
        }
        $ticket_id = intval($att['ticket_attachment_ticket_id']);
        $path = api_attachment_path($ticket_id, (string) $att['ticket_attachment_reference_name'], $DOCUMENT_ROOT);

        if (empty($_GET['download'])) {
            api_response(200, ['items' => [[
                'id'          => $att_id,
                'name'        => (string) $att['ticket_attachment_name'],
                'size'        => $path ? intval(filesize($path)) : 0,
                'mime'        => api_attachment_mime((string) $att['ticket_attachment_reference_name']),
                'created_at'  => $att['ticket_attachment_created_at'],
                'uploaded_by' => $att['user_name'],
            ]]]);
        }

        if (!$path) {
            api_error(404, 'File not found');
        }
        // Never let a client-controlled name break out of the header: drop control characters, quotes, backslashes and path parts.
        $display = preg_replace('/[\x00-\x1f\x7f"\\\\\/]+/u', '_', (string) $att['ticket_attachment_name']);
        $display = $display !== null && trim($display) !== '' ? $display : 'attachment';
        $ascii   = preg_replace('/[^A-Za-z0-9._-]+/', '_', $display);
        $ascii   = $ascii !== '' ? $ascii : 'attachment';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Content-Type');
        header('Content-Type: ' . api_attachment_mime((string) $att['ticket_attachment_reference_name']));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($display));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        http_response_code(200);
        readfile($path);
        exit;
    }

    $ticket_id = intval($_GET['ticket_id'] ?? 0);
    if (!api_attachment_ticket($ticket_id)) {
        api_error(403, 'Access denied');
    }
    $items = [];
    $rs = mysqli_query($mysqli, "$select WHERE a.ticket_attachment_ticket_id = $ticket_id ORDER BY a.ticket_attachment_created_at ASC, a.ticket_attachment_id ASC");
    while ($rs && ($att = mysqli_fetch_assoc($rs))) {
        $path = api_attachment_path($ticket_id, (string) $att['ticket_attachment_reference_name'], $DOCUMENT_ROOT);
        $items[] = [
            'id'          => intval($att['ticket_attachment_id']),
            'name'        => (string) $att['ticket_attachment_name'],
            'size'        => $path ? intval(filesize($path)) : 0,
            'mime'        => api_attachment_mime((string) $att['ticket_attachment_reference_name']),
            'created_at'  => $att['ticket_attachment_created_at'],
            'uploaded_by' => $att['user_name'],
        ];
    }
    api_response(200, ['items' => $items]);
}

if ($method === 'POST') {
    // post_max_size exceeded: PHP discards the whole body, so there is no ticket_id or file to look at.
    if (empty($_POST) && empty($_FILES) && intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        api_error(413, 'Upload is too large');
    }

    $ticket_id = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($ticket_id === false || $ticket_id === null) {
        api_error(422, 'ticket_id is required');
    }
    $reply_id = null;
    if (isset($_POST['reply_id']) && $_POST['reply_id'] !== '') {
        $reply_id = filter_var($_POST['reply_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reply_id === false) {
            api_error(422, 'reply_id must be a positive integer');
        }
    }

    // Ticket access (incl. department scope) is checked before the file is even looked at.
    $ticket = api_attachment_ticket($ticket_id);
    if (!$ticket) {
        api_error(403, 'Access denied');
    }
    if ($reply_id !== null) {
        $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_id = $reply_id AND ticket_reply_ticket_id = $ticket_id LIMIT 1"));
        if (!$reply) {
            api_error(422, 'reply_id does not belong to this ticket');
        }
    }

    $file = $_FILES['file'] ?? null;
    if (!$file || is_array($file['name'])) {
        api_error(422, 'Send one file in the "file" field');
    }
    $err = intval($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        api_error(413, 'File is too large');
    }
    if ($err === UPLOAD_ERR_NO_FILE) {
        api_error(422, 'No file uploaded');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        api_error(400, 'File upload failed');
    }

    // The web allow-list (identical to agent/post/ticket.php).
    $allowed = array_keys(API_ATTACHMENT_MIME);

    // Client-supplied name: keep only the final path component for display, and look at EVERY extension segment, so
    // "shell.php.png" is refused even though only the last one is stored.
    $client_name = str_replace('\\', '/', (string) $file['name']);
    $client_name = basename($client_name);
    $client_name = preg_replace('/[\x00-\x1f\x7f]+/u', '', $client_name) ?? '';
    $client_name = mb_substr(trim(cleanInput($client_name)), 0, 200);
    if ($client_name === '' || $client_name === '.' || $client_name === '..') {
        api_error(422, 'Invalid file name');
    }
    $segments = explode('.', strtolower($client_name));
    array_shift($segments);
    $last = array_pop($segments);
    foreach ($segments as $seg) {
        if (preg_match('/^(php\d?|phtml|pht|phar|phps|cgi|pl|py|sh|bash|asp|aspx|jsp|exe|dll|bat|cmd|com|scr|html?|xhtml|js|svg)$/', $seg)) {
            api_error(422, 'File type not allowed');
        }
    }

    $check_file = ['name' => $client_name, 'tmp_name' => $file['tmp_name'], 'size' => $file['size']];
    $ref_name = checkFileUpload($check_file, $allowed);
    if ($ref_name === 'File size exceeds the limit.') {
        api_error(413, 'File is too large');
    }
    if (!isUploadReferenceName($ref_name)) {
        api_error(422, 'File type not allowed');
    }

    // Content sniffing: never trust the extension or the client's Content-Type. An image extension must hold an image, and
    // nothing that is script/markup/executable is stored whatever it is called.
    $ext = strtolower(pathinfo($ref_name, PATHINFO_EXTENSION));
    $detected = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : '';
    $denied_types = ['text/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml', 'text/x-shellscript', 'application/x-sh',
        'application/x-dosexec', 'application/x-executable', 'application/x-msdownload', 'application/javascript', 'text/javascript',
        'image/svg+xml', 'text/x-script.python', 'text/x-perl'];
    if ($detected !== '' && in_array($detected, $denied_types, true)) {
        api_error(422, 'File content not allowed');
    }
    if (in_array($ext, ['jpg', 'jpeg', 'gif', 'png', 'webp'], true) && strpos($detected, 'image/') !== 0) {
        api_error(422, 'File content does not match its type');
    }

    $upload_dir = "$DOCUMENT_ROOT/uploads/tickets/$ticket_id/";
    mkdirMissing("$DOCUMENT_ROOT/uploads/tickets/");
    mkdirMissing($upload_dir);
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $ref_name)) {
        api_error(500, 'Could not store the file');
    }

    $new_id = api_exec(
        "INSERT INTO ticket_attachments (ticket_attachment_name, ticket_attachment_reference_name, ticket_attachment_reply_id, ticket_attachment_ticket_id)
         VALUES (?, ?, " . ($reply_id === null ? 'NULL' : intval($reply_id)) . ", $ticket_id)",
        'ss',
        [$client_name, $ref_name]
    );
    if (!$new_id) {
        @unlink($upload_dir . $ref_name);
        api_error(500, 'Could not record the file');
    }
    mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $ticket_id");

    api_mobile_audit_context();
    logAction('Ticket', 'Edit', "Uploaded attachment $client_name to ticket via the mobile API", intval($ticket['ticket_client_id']), $ticket_id);

    api_response(201, ['ok' => true, 'id' => $new_id]);
}

api_error(405, 'Method not allowed');
