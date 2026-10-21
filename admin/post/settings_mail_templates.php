<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_mail_template'])) {
    validateCSRFToken($_POST['csrf_token']);

    $mt_event = (string) ($_POST['event'] ?? '');
    $mt_defaults = mailTemplateDefaults();
    if (!isset($mt_defaults[$mt_event])) {
        flash_alert("Unknown email template.", "error");
        redirect();
    }
    $mt_subject = trim((string) ($_POST['subject'] ?? ''));
    $mt_body = (string) ($_POST['body'] ?? '');
    $mt_enabled = isset($_POST['enabled']) ? 1 : 0;
    if ($mt_subject === '' || $mt_body === '') {
        flash_alert("Subject and body cannot be empty.", "error");
        redirect();
    }

    $mt_event_esc = mysqli_real_escape_string($mysqli, $mt_event);
    $mt_subject_esc = mysqli_real_escape_string($mysqli, $mt_subject);
    $mt_body_esc = mysqli_real_escape_string($mysqli, $mt_body);
    mysqli_query($mysqli, "INSERT INTO mail_templates (mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_at, mail_template_updated_by)
        VALUES ('$mt_event_esc', '$mt_subject_esc', '$mt_body_esc', $mt_enabled, NOW(), " . (int) $session_user_id . ")
        ON DUPLICATE KEY UPDATE mail_template_subject = '$mt_subject_esc', mail_template_body = '$mt_body_esc', mail_template_enabled = $mt_enabled, mail_template_updated_at = NOW(), mail_template_updated_by = " . (int) $session_user_id);

    logAction("Settings", "Edit", "$session_name edited the \"$mt_event\" email template" . ($mt_enabled ? '' : ' and disabled it'));
    flash_alert("Email template saved.");
    redirect();
}

if (isset($_POST['reset_mail_template'])) {
    validateCSRFToken($_POST['csrf_token']);

    $mt_event = (string) ($_POST['event'] ?? '');
    if (!mailTemplateResetToDefault($mysqli, $mt_event, (int) $session_user_id)) {
        flash_alert("Unknown email template.", "error");
        redirect();
    }

    logAction("Settings", "Edit", "$session_name reset the \"$mt_event\" email template to its default text");
    flash_alert("Email template reset to the default text.");
    redirect();
}

if (isset($_POST['save_mail_template_header_footer'])) {
    validateCSRFToken($_POST['csrf_token']);

    $mt_header_esc = mysqli_real_escape_string($mysqli, (string) ($_POST['header_html'] ?? ''));
    $mt_footer_esc = mysqli_real_escape_string($mysqli, (string) ($_POST['footer_html'] ?? ''));
    mysqli_query($mysqli, "UPDATE settings SET config_mail_template_header_html = '$mt_header_esc', config_mail_template_footer_html = '$mt_footer_esc' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited the shared email header/footer");
    flash_alert("Header and footer saved.");
    redirect();
}

// AJAX: preview with sample data, or (send_test=1) queue one real copy to the admin's own email through the normal mail queue.
if (isset($_POST['preview_mail_template'])) {
    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');

    $mt_event = (string) ($_POST['event'] ?? '');
    $mt_defaults = mailTemplateDefaults();
    if (!isset($mt_defaults[$mt_event])) {
        echo json_encode(['ok' => false, 'error' => 'Unknown email template.']);
        exit;
    }
    $mt_subject = trim((string) ($_POST['subject'] ?? '')) ?: $mt_defaults[$mt_event]['subject'];
    $mt_body = (string) ($_POST['body'] ?? '') ?: $mt_defaults[$mt_event]['body'];

    // Render the UNSAVED draft text the admin is currently looking at, not whatever is in the database - a preview/test must
    // show the admin what they are about to save, including a template with mistakes they have not saved yet.
    $mt_vars = mailTemplateSampleVars($mt_event);
    $mt_rendered_body = mailTemplateApplyPlaceholders($mt_body, $mt_vars, 'html');
    $mt_rendered_subject = mailTemplateApplyPlaceholders($mt_subject, $mt_vars, 'text');
    $mt_hf = mailTemplateHeaderFooter($mysqli);
    [$mt_company_name] = array_values(getCompanyNameAndPhone());
    $mt_rendered_body = mailTemplateWrapShell($mt_rendered_body, $mt_company_name, $mt_hf['header'], $mt_hf['footer']);

    if (!empty($_POST['send_test'])) {
        $mt_me = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_email, user_name FROM users WHERE user_id = " . (int) $session_user_id));
        if (!$mt_me || !filter_var($mt_me['user_email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Your own account has no valid email address on file.']);
            exit;
        }
        $mt_from = resolveTicketFromIdentity(0);
        addToMailQueue([[
            'from' => $mt_from['email'] ?: $config_ticket_from_email,
            'from_name' => $mt_from['name'] ?: $config_ticket_from_name,
            'recipient' => $mt_me['user_email'],
            'recipient_name' => $mt_me['user_name'],
            'subject' => '[TEST] ' . $mt_rendered_subject,
            'body' => '<div style="background:#fff3cd;border:1px solid #ffecb5;padding:.75rem;margin-bottom:1rem;border-radius:.375rem;">This is a test send of the "' . nullable_htmlentities($mt_event) . '" email template, with sample data, not a real ticket.</div>' . $mt_rendered_body,
        ]]);
        logAction("Settings", "View", "$session_name sent a test copy of the \"$mt_event\" email template to themselves");
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => true, 'subject' => $mt_rendered_subject, 'body' => $mt_rendered_body]);
    exit;
}
