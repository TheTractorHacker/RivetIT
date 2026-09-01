<?php

/*
 * ITFlow - Legacy URL. QuickBooks Online item mapping now lives under the
 * Item Mapping tab of Accounting Settings. Keeping this file so existing
 * bookmarks or deep-links don't 404.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";

redirect('/admin/settings_accounting.php?tab=item_mapping', true);
