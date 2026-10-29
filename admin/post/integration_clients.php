<?php

/*
 * RivetIT - kept for old Referers: Administration > API Keys moved to admin/api_keys.php (formerly "Integration clients").
 * admin/post.php picks the handler from the Referer's page name, so a request that still names the old page runs the same handler.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/api_keys.php';
