<?php
/*
 * Shared outtake form signing logic.
 * Used by guest/guest_post.php (token-based customer signing) and
 * agent/post/outtake.php (agent-initiated in-person signing).
 */

// Validates inputs, marks the outtake form signed, generates the signed PDF,
// stores it in the client's files + the ticket's attachments, and adds an
// Internal ticket reply noting the signature.
// Returns ['ok' => true, 'ticket_id', 'client_id', 'pdf_name', 'file_id', 'signed_name', 'signed_at']
// or ['ok' => false, 'error' => '...'] on failure.
function signOuttakeForm($mysqli, $outtake_id, $signed_name, $signature) {
    $outtake_id = intval($outtake_id);
    $signed_name = sanitizeInput($signed_name);

    if (empty($signed_name)) {
        return ['ok' => false, 'error' => 'Please enter a full name.'];
    }

    if ($signature && !preg_match('/^data:image\/(png|jpeg|jpg|gif);base64,[A-Za-z0-9+\/=]+$/', $signature)) {
        $signature = '';
    }

    if (empty($signature)) {
        return ['ok' => false, 'error' => 'Please provide a signature.'];
    }

    $sql = mysqli_query($mysqli, "SELECT ot.*, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details, t.ticket_created_at, t.ticket_client_id, c.client_name, co.contact_name
        FROM ticket_outtake_forms ot
        JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
        LEFT JOIN clients c ON t.ticket_client_id = c.client_id
        LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
        WHERE ot.outtake_id = $outtake_id AND ot.outtake_signed_at IS NULL LIMIT 1");

    if (mysqli_num_rows($sql) !== 1) {
        return ['ok' => false, 'error' => 'This form has already been signed or does not exist.'];
    }

    $row           = mysqli_fetch_assoc($sql);
    $ticket_id     = intval($row['outtake_ticket_id']);
    $client_id     = intval($row['ticket_client_id']);
    $ticket_num    = $row['ticket_prefix'] . intval($row['ticket_number']);
    $ticket_subj   = $row['ticket_subject'];
    $ticket_detail = $row['ticket_details'] ? trim(strip_tags($row['ticket_details'])) : '';
    $client_name   = $row['client_name'];
    $contact_name  = $row['contact_name'];
    $tech_notes    = $row['outtake_tech_notes'];
    $signed_at_str = date('F j, Y \a\t g:i A');

    // Save signature + signed info
    $sig_escaped = mysqli_real_escape_string($mysqli, $signature);
    mysqli_query($mysqli, "UPDATE ticket_outtake_forms SET outtake_signed_name = '$signed_name', outtake_signed_at = NOW(), outtake_signature = '$sig_escaped' WHERE outtake_id = $outtake_id");

    // --- Generate PDF ---
    $co = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1 LIMIT 1"));
    $co_name    = $co['company_name'];
    $co_address = $co['company_address'];
    $co_city    = $co['company_city'];
    $co_state   = $co['company_state'];
    $co_zip     = $co['company_zip'];
    $co_phone   = $co['company_phone'];
    $co_email   = $co['company_email'];
    $co_logo    = $co['company_logo'];

    // Ticket replies
    $replies_text = '';
    $sql_replies = mysqli_query($mysqli, "SELECT tr.ticket_reply, tr.ticket_reply_type, tr.ticket_reply_created_at, u.user_name
        FROM ticket_replies tr LEFT JOIN users u ON tr.ticket_reply_by = u.user_id
        WHERE tr.ticket_reply_ticket_id = $ticket_id AND tr.ticket_reply_archived_at IS NULL
        ORDER BY tr.ticket_reply_id ASC LIMIT 10");
    while ($r = mysqli_fetch_assoc($sql_replies)) {
        $by   = htmlspecialchars($r['user_name'] ?: 'Client');
        $dt   = date('M j, Y', strtotime($r['ticket_reply_created_at']));
        $body = nl2br(htmlspecialchars(html_entity_decode(substr(strip_tags($r['ticket_reply']), 0, 300), ENT_HTML5, 'UTF-8')));
        $replies_text .= "<tr>
            <td style='font-size:9pt;color:#555;width:110px;vertical-align:top;padding:4px;'>$by<br><span style='color:#999;'>$dt</span></td>
            <td style='font-size:9pt;vertical-align:top;padding:4px;'>$body</td>
        </tr>";
    }

    // Clean ticket_detail of HTML entities
    $ticket_detail_clean = html_entity_decode(trim(strip_tags($ticket_detail)), ENT_HTML5, 'UTF-8');

    $webroot = dirname(__DIR__);

    if (!class_exists('TCPDF')) {
        require_once "$webroot/plugins/TCPDF/tcpdf.php";
    }
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $page_w    = $pdf->getPageWidth();
    $margin_l  = 12;
    $content_w = $page_w - $margin_l - 12;

    $logo_abs  = "$webroot/uploads/settings/$co_logo";
    $logo_tag  = ($co_logo && file_exists($logo_abs))
        ? '<img src="' . $logo_abs . '" height="18"><br>' : '';

    $hdr_td        = 'background-color:#1a1a2e;color:#ffffff;padding:8px;vertical-align:top;';
    $addr_line     = htmlspecialchars(trim("$co_address, $co_city, $co_state $co_zip"));
    $contact_line  = htmlspecialchars(trim(implode(' &bull; ', array_filter([$co_phone, $co_email]))));
    $ticket_right  = 'Ticket: ' . htmlspecialchars($ticket_num) . '<br>'
        . 'Date: ' . date('m/d/Y', strtotime($row['ticket_created_at'])) . '<br>'
        . 'Client: ' . htmlspecialchars($client_name) . '<br>'
        . ($contact_name ? 'Contact: ' . htmlspecialchars($contact_name) . '<br>' : '')
        . 'Subject: ' . htmlspecialchars($ticket_subj);

    $section_bar = 'background-color:#eef0f3;font-weight:bold;font-size:9pt;'
        . 'text-transform:uppercase;letter-spacing:1px;'
        . 'border-top:1px solid #cccccc;border-bottom:1px solid #cccccc;padding:4px;';
    $html = '<table width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td width="55%" style="' . $hdr_td . '">
            ' . $logo_tag . '
            <span style="font-size:13pt;font-weight:bold;color:#ffffff;">' . htmlspecialchars($co_name) . '</span><br>
            <span style="font-size:8pt;color:#cccccc;">' . $addr_line . '<br>' . $contact_line . '</span>
        </td>
        <td width="45%" style="' . $hdr_td . 'text-align:right;">
            <span style="font-size:18pt;font-weight:bold;color:#ffffff;">OUTTAKE FORM</span><br>
            <span style="font-size:8pt;color:#cccccc;line-height:150%;">' . $ticket_right . '</span>
        </td>
    </tr>
    </table><br>';

    if ($ticket_detail_clean) {
        $html .= '<table width="100%" cellpadding="4" cellspacing="0">
            <tr><td style="' . $section_bar . '">TICKET PROBLEM</td></tr>
            <tr><td style="font-size:9pt;padding:6px 4px;">' . nl2br(htmlspecialchars($ticket_detail_clean)) . '</td></tr>
        </table><br>';
    }

    if ($replies_text) {
        $html .= '<table width="100%" cellpadding="0" cellspacing="0">
            <tr><td colspan="2" style="' . $section_bar . '">TICKET COMMENTS</td></tr>'
            . $replies_text .
        '</table><br>';
    }

    if ($tech_notes) {
        $html .= '<table width="100%" cellpadding="4" cellspacing="0">
            <tr><td style="' . $section_bar . '">WORK COMPLETED</td></tr>
            <tr><td style="font-size:9pt;padding:6px 4px;">' . nl2br(htmlspecialchars($tech_notes)) . '</td></tr>
        </table><br>';
    }

    $html .= '<table width="100%" cellpadding="4" cellspacing="0">
        <tr><td style="' . $section_bar . '">DISCLAIMER</td></tr>
        <tr><td style="font-size:9pt;padding:6px 4px;">
            By signing this outtake form, the undersigned acknowledges the following:<br><br>
            &bull; <strong>Receipt of Equipment:</strong> The undersigned confirms that any equipment has been delivered and received in satisfactory condition. Details of parts used are available upon request and will be reflected on the final invoice.<br><br>
            &bull; <strong>Payment for Parts and Services:</strong> The undersigned agrees to pay for all parts and services rendered per the agreed service agreement or standard payment policy.<br><br>
            &bull; <strong>Acknowledgement of Work:</strong> The undersigned acknowledges that the work described in this form has been completed.
        </td></tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');

    // ── Signature block (native TCPDF for reliable image embedding) ───────
    $pdf->Ln(4);
    $left_w  = $content_w * 0.58;
    $right_w = $content_w * 0.42;
    $right_x = $margin_l + $left_w;
    $lh      = 5;

    // Build right-column lines and calculate needed height
    $sig_lines = [
        ['B', 'Signed by:'], ['', $signed_name],
        ['B', 'Date:'],      ['', $signed_at_str],
    ];
    if ($client_name) { $sig_lines[] = ['B', 'Client:']; $sig_lines[] = ['', $client_name]; }
    $box_h = max(36, count($sig_lines) * ($lh + 1) + 14);

    // Section bar
    $pdf->SetFillColor(238, 240, 243);
    $pdf->SetDrawColor(204, 204, 204);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(85, 85, 85);
    $pdf->SetX($margin_l);
    $pdf->Cell($content_w, 6, 'CUSTOMER SIGNATURE', 'LTR', 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $box_y = $pdf->GetY();

    // Cell borders
    $pdf->Rect($margin_l, $box_y, $left_w,  $box_h);
    $pdf->Rect($right_x,  $box_y, $right_w, $box_h);

    // Signature image in left cell
    $sig_binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature));
    if ($sig_binary) {
        $pdf->Image('@' . $sig_binary, $margin_l + 2, $box_y + 2, $left_w - 4, $box_h - 4, 'PNG');
    }

    // Right column — SetXY before EVERY cell to maintain correct X
    $rx = $right_x + 4;
    $rw = $right_w - 8;
    $ry = $box_y + 5;
    foreach ($sig_lines as [$style, $text]) {
        $pdf->SetFont('helvetica', $style, 9);
        $pdf->SetXY($rx, $ry);
        $pdf->Cell($rw, $lh, $text, 0, 0);
        $ry += $lh + 1;
    }

    $pdf->SetY($box_y + $box_h + 2);

    // Save PDF to client uploads folder (absolute path required for TCPDF)
    $upload_dir = "$webroot/uploads/clients/$client_id/";
    mkdirMissing($upload_dir);

    $pdf_ref  = bin2hex(random_bytes(8)) . 'TL.pdf';
    $pdf_name = "Outtake_Form_{$ticket_num}_" . date('Y-m-d') . '.pdf';
    $pdf->Output($upload_dir . $pdf_ref, 'F');

    $pdf_size = filesize($upload_dir . $pdf_ref) ?: 0;

    // Get/create "Outtake Forms" folder for client
    $folder_sql = mysqli_query($mysqli, "SELECT folder_id FROM folders WHERE folder_name = 'Outtake Forms' AND parent_folder = 0 AND folder_client_id = $client_id LIMIT 1");
    if (mysqli_num_rows($folder_sql) == 1) {
        $folder_id = intval(mysqli_fetch_assoc($folder_sql)['folder_id']);
    } else {
        mysqli_query($mysqli, "INSERT INTO folders SET folder_name = 'Outtake Forms', parent_folder = 0, folder_location = 1, folder_client_id = $client_id");
        $folder_id = mysqli_insert_id($mysqli);
    }

    $pdf_name_esc = mysqli_real_escape_string($mysqli, $pdf_name);
    mysqli_query($mysqli, "INSERT INTO files SET
        file_reference_name = '$pdf_ref',
        file_name = '$pdf_name_esc',
        file_description = 'Signed outtake form for ticket $ticket_num',
        file_ext = 'pdf',
        file_mime_type = 'application/pdf',
        file_size = $pdf_size,
        file_folder_id = $folder_id,
        file_client_id = $client_id,
        file_created_by = 0");
    $file_id = mysqli_insert_id($mysqli);

    // Add internal ticket reply noting PDF is attached
    $reply_msg = mysqli_real_escape_string($mysqli, "Outtake form signed by $signed_name on $signed_at_str. PDF saved to client files: $pdf_name");
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$reply_msg', ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

    // Also copy the signed PDF into the ticket's attachments
    $att_upload_dir = "$webroot/uploads/tickets/$ticket_id/";
    mkdirMissing("$webroot/uploads/tickets/");
    mkdirMissing($att_upload_dir);

    $att_ref = bin2hex(random_bytes(8)) . '.pdf';
    copy($upload_dir . $pdf_ref, $att_upload_dir . $att_ref);

    $att_name_esc = mysqli_real_escape_string($mysqli, $pdf_name);
    mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name='$att_name_esc', ticket_attachment_reference_name='$att_ref', ticket_attachment_reply_id=NULL, ticket_attachment_ticket_id=$ticket_id");

    logAction("Outtake", "Sign", "Outtake form signed by $signed_name — PDF attached ($pdf_name)", $client_id, $ticket_id);

    return [
        'ok'          => true,
        'ticket_id'   => $ticket_id,
        'client_id'   => $client_id,
        'pdf_name'    => $pdf_name,
        'file_id'     => $file_id,
        'signed_name' => $signed_name,
        'signed_at'   => $signed_at_str,
    ];
}
