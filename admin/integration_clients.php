<?php

// "Integration clients" is now "API Keys" (admin/api_keys.php). Old links and bookmarks land there; nothing is processed here, so no
// login or admin check is needed (api_keys.php runs its own).
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: /admin/api_keys.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
