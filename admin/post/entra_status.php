<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// The run buttons on admin/entra_status.php use the same handlers as the Integrations page (Directory Sync / Device Sync tabs).
require_once __DIR__ . '/settings_directory_sync.php';
