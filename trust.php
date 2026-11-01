<?php

// Public "Security and trust" page. No sign-in, no session, no database: it renders docs/security/EDITION_BASELINE.json and the approved wording of
// RivetCore's CLAIMS.md (see src/Security/TrustPage.php). It carries no badge or seal and makes no certification claim.

$csp_nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/app_version.php';

$page = new \ITFlow\Security\TrustPage(__DIR__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security and trust - <?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/css/itflow_custom.css">
</head>
<body>
<main class="container py-4" style="max-width: 880px">
    <h1 class="h2">Security and trust</h1>
    <?php echo $page->html(APP_VERSION, false); ?>
</main>
</body>
</html>
