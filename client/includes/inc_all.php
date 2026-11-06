<?php
/*
 * Client Portal
 * Includes for all pages (except login)
 */

/*
 * PORTAL CONTENT-SECURITY-POLICY, defaulted here rather than per page.
 *
 * Every portal page carried its own copy-pasted
 * header("Content-Security-Policy: default-src 'self'") line, and six of them
 * had drifted without one - including client/ticket.php and
 * client/ticket_add.php, the two pages that render the most agent-authored HTML
 * and are therefore the two that want a policy most. Defaulting it in the one
 * file every portal page includes ends that class of omission: a new page is
 * covered the moment it is written.
 *
 * Pages that need a WIDER policy (client/document.php, client/kb_article.php and
 * the two ticket pages add `img-src 'self' data:` for embedded images) still set
 * their own, and they do it BEFORE this include - which is why this checks
 * headers_list() first. Without that check, header() would replace theirs with
 * the narrower default and break the very images they widened it for.
 */
$portal_csp_already_set = false;
$portal_csp_narrow = false;
foreach (headers_list() as $portal_sent_header) {
    if (stripos($portal_sent_header, 'Content-Security-Policy:') === 0) {
        $portal_csp_already_set = true;
        // The plain per-page `default-src 'self'` line is widened by data: IMAGES only (never scripts or styles): Tabler draws every
        // <select> arrow, checkbox tick and radio dot as a data: SVG background, and `default-src 'self'` alone made them all vanish.
        $portal_csp_narrow = (trim(substr($portal_sent_header, strlen('Content-Security-Policy:'))) === "default-src 'self'");
        break;
    }
}
if (!headers_sent() && ($portal_csp_narrow || !$portal_csp_already_set)) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:");
}
unset($portal_csp_already_set, $portal_csp_narrow, $portal_sent_header);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/header.php';
