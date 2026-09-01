<?php

/*
 * ITFlow - Legacy URL. QuickBooks Online client mapping now lives under the
 * Client Mapping tab of Accounting Settings. Keeping this file so existing
 * bookmarks or deep-links don't 404.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";

redirect('/admin/settings_accounting.php?tab=client_mapping', true);
