<?php
/*
 * Client Portal
 * Functions
 */

/*
 * Verifies a contact has access to a particular ticket ID, and that the ticket is in the correct state (open/closed) to perform an action
 */
function verifyContactTicketAccess($requested_ticket_id, $expected_ticket_state)
{

    // Access the global variables
    global $mysqli, $session_contact_id, $session_contact_primary, $session_contact_is_technical_contact, $session_client_id;

    // Setup
    if ($expected_ticket_state == "Closed") {
        // Closed tickets
        $ticket_state_snippet = "ticket_status = 5";
    } else {
        // Open (working/hold) tickets
        $ticket_state_snippet = "ticket_status != 5";
    }

    // Verify the contact has access to the provided ticket ID
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $requested_ticket_id AND $ticket_state_snippet AND ticket_client_id = $session_client_id LIMIT 1"));
    if ($row) {
        $ticket_id = $row['ticket_id'];

        if (intval($ticket_id) && ($session_contact_id == $row['ticket_contact_id'] || $session_contact_primary == 1 || $session_contact_is_technical_contact)) {
            // Client is ticket owner, primary contact, or a technical contact
            return true;
        }
    }

    // Client is NOT ticket owner or primary/tech contact
    return false;

}

/*
 * Returns appropriate FontAwesome icon for file extension
 */
function getFileIcon($file_extension) {
    $file_extension = strtolower($file_extension);

    // Document icons
    if (in_array($file_extension, ['pdf'])) {
        return 'file-pdf';
    } elseif (in_array($file_extension, ['doc', 'docx'])) {
        return 'file-word';
    } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
        return 'file-excel';
    } elseif (in_array($file_extension, ['ppt', 'pptx'])) {
        return 'file-powerpoint';
    } elseif (in_array($file_extension, ['txt', 'md', 'rtf'])) {
        return 'file-alt';
    } elseif (in_array($file_extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
        return 'file-archive';
    } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
        return 'file-image';
    } elseif (in_array($file_extension, ['mp4', 'avi', 'mov', 'wmv', 'flv'])) {
        return 'file-video';
    } elseif (in_array($file_extension, ['mp3', 'wav', 'ogg', 'flac'])) {
        return 'file-audio';
    } elseif (in_array($file_extension, ['html', 'htm', 'css', 'js', 'php', 'py', 'java'])) {
        return 'file-code';
    } else {
        return 'file';
    }
}

/*
 * Formats bytes into human readable file sizes
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/*
 * Message shown on the portal home page when a contact opens a page reserved for their department's
 * primary / technical contacts. Names the page so the banner explains itself (the old text was generic).
 */
function portal_no_access_message(string $script): string
{
    $pages = [
        'documents.php' => 'Documents', 'document.php' => 'Documents', 'contacts.php' => 'Contacts',
        'contact_add.php' => 'Contacts', 'contact_edit.php' => 'Contacts', 'contracts.php' => 'Contracts',
        'domains.php' => 'Domains', 'certificates.php' => 'Certificates', 'allowance.php' => 'Support allowance',
        'ticket_view_all.php' => 'All company tickets',
    ];
    $name = $pages[basename($script)] ?? 'That page';
    return $name . " is only available to your department's primary or technical contacts, so your account can't open it. Everything else in the portal still works. Need access? Ask your department's primary contact.";
}

/*
 * Expiry pill for the Domains / Certificates pages: tells the reader at a glance whether a date is a problem.
 * Returns [css modifier, label, days]. $date is a Y-m-d (or datetime) string, empty = no expiry on record.
 */
function portal_expiry_state(?string $date): array
{
    $ts = $date ? strtotime($date) : false;
    if (!$ts) {
        return ['muted', 'No expiry set', null];
    }
    $days = (int) floor(($ts - strtotime('today')) / 86400);
    if ($days < 0) {
        $n = abs($days);
        return ['bad', 'Expired ' . $n . ($n === 1 ? ' day ago' : ' days ago'), $days];
    }
    if ($days === 0) {
        return ['bad', 'Expires today', $days];
    }
    if ($days <= 30) {
        return ['warn', 'Expires in ' . $days . ($days === 1 ? ' day' : ' days'), $days];
    }
    return ['ok', 'Valid, ' . $days . ' days left', $days];
}

/* One-line summary above an expiry table: "1 expired, 1 expiring within 30 days". Empty string when all is well. */
function portal_expiry_summary(array $dates, string $noun): string
{
    $bad = 0; $warn = 0;
    foreach ($dates as $d) {
        $s = portal_expiry_state($d)[0];
        if ($s === 'bad') { $bad++; } elseif ($s === 'warn') { $warn++; }
    }
    if (!$bad && !$warn) {
        return '';
    }
    $parts = [];
    if ($bad) { $parts[] = $bad . ' expired'; }
    if ($warn) { $parts[] = $warn . ' expiring within 30 days'; }
    return ucfirst(implode(', ', $parts)) . '. Raise a ticket and IT will renew the ' . $noun . '.';
}

/* Bootstrap badge class for a ticket status name (resolved/closed green, hold/waiting amber, otherwise neutral). */
function portal_ticket_status_badge(string $name): string
{
    $n = strtolower($name);
    if (strpos($n, 'resolved') !== false || strpos($n, 'closed') !== false) { return 'text-bg-success'; }
    if (strpos($n, 'hold') !== false || strpos($n, 'wait') !== false || strpos($n, 'pending') !== false) { return 'text-bg-warning'; }
    return 'text-bg-secondary';
}
