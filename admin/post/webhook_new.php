<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// The Add / Edit webhook pages post from their own URL (admin/post.php picks its handler from the Referer): same handler as the list page.
require __DIR__ . '/settings_webhooks.php';
