<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Unified integrations page fans out to three existing handler files.
// Only the handler whose action matches the POST key will fire (each exits after responding).
require_once __DIR__ . '/settings_rmm.php';
require_once __DIR__ . '/settings_comet.php';
require_once __DIR__ . '/settings_unifi.php';
