<?php

/*
 * ITFlow - Legacy URL. QuickBooks Online sync status now lives under the
 * Sync Status tab of Accounting Settings. Keeping this file so existing
 * bookmarks or deep-links don't 404.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";

redirect('/admin/settings_accounting.php?tab=sync_status', true);
