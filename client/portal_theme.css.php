<?php
/*
 * Client Portal
 * The company accent colour / card radius as a stylesheet. Portal pages cannot use an inline <style> (CSP), so the
 * header links this file instead. The values ride in the query string (validated below), so no session or database
 * is needed and the response is immutable per URL.
 */

require_once __DIR__ . '/../includes/theme_accent.php';

$hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) ($_GET['a'] ?? '')));
$hex = strlen($hex) === 6 ? '#' . $hex : '';

header('Content-Type: text/css; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');

echo itflow_theme_accent_css($hex, (string) ($_GET['r'] ?? ''));
