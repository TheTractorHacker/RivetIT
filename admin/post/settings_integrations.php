<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Unified integrations page fans out to the existing handler files.
// Only the handler whose action matches the POST key will fire (each exits after responding).
require_once __DIR__ . '/settings_rmm.php';
require_once __DIR__ . '/settings_comet.php';
require_once __DIR__ . '/settings_unifi.php';
require_once __DIR__ . '/settings_directory_sync.php';
// The Odoo tab hosts the employee-link and nightly-sync forms; their handlers live in the compliance file.
require_once __DIR__ . '/settings_training_compliance.php';
