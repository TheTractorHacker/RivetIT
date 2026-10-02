<?php
// Public, read-only API reference. Scalar renders the same OpenAPI file that
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
  .reference-help { padding: .55rem 1.25rem; border-bottom: 1px solid #e5e9ee; color: #495766; font-size: .85rem; }
  .reference-help summary { color: #176b67; cursor: pointer; display: inline; }
  .reference-help pre { overflow: auto; margin: .7rem 0 0; padding: .9rem; border-radius: .4rem; background: #18212b; color: #f4f6f8; font-size: .8rem; }
  .reference-error { display: none; margin: 2rem; padding: 1rem; border: 1px solid #e5e9ee; border-radius: .5rem; }
  @media (max-width: 600px) { .reference-top, .reference-help { padding-left: .85rem; padding-right: .85rem; } }
</style>
</head>
<body>
<header class="reference-top">
  <strong>RivetIT API Reference</strong>
  <a href="/api/v1/openapi" download="rivetit-openapi.yaml">Download OpenAPI spec</a>
</header>
<div class="reference-help">
  Choose an endpoint, then select Python, PHP, or another client in its request example. C &gt; Libcurl also works in C++.
  <details><summary>Show a C++ example</summary>
    <pre><code>#include &lt;curl/curl.h&gt;

int main() {
    curl_global_init(CURL_GLOBAL_DEFAULT);
    CURL *curl = curl_easy_init();
    if (!curl) return 1;
    curl_slist *headers = curl_slist_append(nullptr, "Authorization: Bearer YOUR_TOKEN");
    curl_easy_setopt(curl, CURLOPT_URL, "https://YOUR_RIVETIT_HOST/api/v1/dashboard");
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    CURLcode result = curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    curl_global_cleanup();
    return result == CURLE_OK ? 0 : 1;
}</code></pre>
  </details>
</div>
<main id="scalar"></main>
<div id="reference-error" class="reference-error" role="alert">
  The reference could not load. <a href="/api/v1/openapi" download="rivetit-openapi.yaml">Download the OpenAPI spec</a> instead.
</div>
<noscript><p class="reference-error" style="display:block">JavaScript is required to browse this reference. <a href="/api/v1/openapi" download="rivetit-openapi.yaml">Download the OpenAPI spec</a> instead.</p></noscript>
<script src="/plugins/scalar/scalar.standalone.1.72.4.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8') ?>">
  try {
    Scalar.createApiReference('#scalar', {
      url: '/api/v1/openapi',
      layout: 'modern',
      withDefaultFonts: false,
      telemetry: false,
      agent: { disabled: true },
      mcp: { name: 'RivetIT', url: '/api/v1', disabled: true },
      hideTestRequestButton: true,
      hideClientButton: true,
      showDeveloperTools: 'never',
      defaultHttpClient: { targetKey: 'python', clientKey: 'requests' }
    });
  } catch (error) {
    document.getElementById('reference-error').style.display = 'block';
    console.error('API reference failed to load', error);
  }
</script>
</body>
</html>
<?php exit;
