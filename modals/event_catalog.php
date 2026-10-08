<?php

// The event catalog for the shared event picker (includes/event_picker.php, js/event_picker.js): RivetCore's EventCatalog plus the
// few events this edition adds. Fetched once per page by the picker; any signed-in user may read it (static, public metadata).

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/event_catalog_ext.php';

header('Content-Type: application/json');
header('Cache-Control: private, max-age=86400');
echo json_encode(rivetEventCatalogData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
