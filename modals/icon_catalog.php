<?php

// The Font Awesome icon catalog for the shared icon picker (includes/icon_picker.php, js/icon_picker.js).
// Fetched once per page by the picker; any signed-in user may read it (it is static, public-domain-ish metadata).

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';

header('Content-Type: application/json');
header('Cache-Control: private, max-age=86400');
echo \RivetCore\Ui\IconCatalog::toJson();
exit;
