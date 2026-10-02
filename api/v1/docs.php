<?php
// Public, read-only API reference. Redoc renders the same OpenAPI file that
// integrations can download; its assets are hosted by this installation.
defined('FROM_API') || die();

$csp_nonce = base64_encode(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RivetIT API Reference</title>
<style>
  body { margin: 0; color: #202935; background: #fff; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
  .reference-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .65rem 1.25rem; border-bottom: 1px solid #e5e9ee; }
  .reference-top strong { font-size: .95rem; }
  .reference-top a { color: #176b67; font-size: .85rem; }
  .reference-error { display: none; margin: 2rem; padding: 1rem; border: 1px solid #e5e9ee; border-radius: .5rem; }
  @media (max-width: 600px) { .reference-top { padding: .65rem .85rem; } }
</style>
</head>
<body>
<header class="reference-top">
  <strong>RivetIT API Reference</strong>
  <a href="/api/v1/openapi.yaml">Download OpenAPI spec</a>
</header>
<main id="redoc"></main>
<div id="reference-error" class="reference-error" role="alert">
  The reference could not load. <a href="/api/v1/openapi.yaml">Download the OpenAPI spec</a> instead.
</div>
<noscript><p class="reference-error" style="display:block">JavaScript is required to browse this reference. <a href="/api/v1/openapi.yaml">Download the OpenAPI spec</a> instead.</p></noscript>
<script src="/plugins/redoc/redoc.standalone.2.5.4-rivetit.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
  Redoc.init('/api/v1/openapi.yaml', {
    disableGoogleFont: true,
    hideDownloadButton: true,
    theme: { typography: { fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' } }
  }, document.getElementById('redoc'), function (error) {
    if (error) document.getElementById('reference-error').style.display = 'block';
  });
</script>
</body>
</html>
<?php exit;
