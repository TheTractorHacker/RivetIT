<?php

/*
 * RivetIT - POST handler for the Template Library page.
 * Its "New" buttons open the same add modals as each template's own page, but admin/post.php picks the handler from the
 * Referer, which here is template_library.php, so there was no handler and every create silently did nothing.
 * Load the real handlers; each only reacts to its own submit button name.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

foreach (['ticket_template', 'canned_responses', 'worksheet_template', 'contract_template', 'project_template', 'document_template',
          'onboarding_template', 'vendor_template', 'software_template'] as $template_handler) {
    if (is_file(__DIR__ . "/$template_handler.php")) {
        include_once __DIR__ . "/$template_handler.php";
    }
}
