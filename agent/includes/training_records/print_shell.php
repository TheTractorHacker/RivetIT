<?php
defined('TRAINING_PAGE') || exit;

/*
 * Standalone print documents (certificate, printable transcript) - Phase 2 spec §5.1.
 *
 * These pages do not use inc_all.php: they are single sheets meant for the printer or "Save as
 * PDF". The shell sends its own security headers (CSP with a per-response nonce, frame-ancestors
 * 'none', no-store) and loads only /css/itflow_training_print.css and /js/training_print.js.
 * The Cloudflare beacon hosts are allowed because the edge injects the beacon into every HTML
 * response (the same two hosts includes/header.php allows).
 */

if (!function_exists('trr_print_begin')) {
    /**
     * Opens the document. $orientation is 'landscape' or 'portrait' (US Letter either way).
     * Returns the CSP nonce for any inline <script> the page needs (normally none).
     */
    function trr_print_begin(string $title, string $orientation): string
    {
        $nonce = base64_encode(random_bytes(16));
        $orientation = $orientation === 'landscape' ? 'landscape' : 'portrait';
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://cloudflareinsights.com; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');

        $root = dirname(__DIR__, 3);
        $cssV = @filemtime($root . '/css/itflow_training_print.css') ?: 1;
        $jsV = @filemtime($root . '/js/training_print.js') ?: 1;
        $accent = trr_print_accent();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= nullable_htmlentities($title) ?></title>
<link rel="stylesheet" href="/css/itflow_training_print.css?v=<?= (int) $cssV ?>">
<style>
:root { --trr-accent: <?= $accent ?>; }
@page { size: letter <?= $orientation ?>; margin: .5in; }
</style>
<script src="/js/training_print.js?v=<?= (int) $jsV ?>" nonce="<?= nullable_htmlentities($nonce) ?>" defer></script>
</head>
<body class="trr-print trr-print--<?= $orientation ?>">
<?php
        return $nonce;
    }

    function trr_print_end(): void
    {
        echo "\n</body>\n</html>\n";
    }

    /**
     * The company accent (the same resolution as includes/header.php: a custom #RRGGBB wins,
     * else the preset name), defaulting to the neutral slate ink - never a hard-coded brand red.
     */
    function trr_print_accent(): string
    {
        $custom = (string) ($GLOBALS['config_theme_accent_custom'] ?? '');
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $custom) === 1) {
            return strtoupper($custom);
        }
        $presets = ['teal' => '#0D9488', 'blue' => '#2563EB', 'indigo' => '#4F46E5', 'purple' => '#7C3AED', 'green' => '#16A34A',
            'red' => '#DC2626', 'orange' => '#EA580C', 'pink' => '#DB2777', 'cyan' => '#0891B2', 'yellow' => '#D97706',
            'lime' => '#65A30D', 'fuchsia' => '#C026D3', 'navy' => '#1E3A8A', 'maroon' => '#9F1239', 'gray' => '#475569'];
        return $presets[(string) ($GLOBALS['config_theme'] ?? '')] ?? '#3F4D5A';
    }

    /** A plain not-found sheet (scope refusals are 404, spec §0 #3). */
    function trr_print_not_found(string $what): never
    {
        http_response_code(404);
        trr_print_begin('Not found', 'portrait');
        echo '<main class="trr-print-missing"><h1>Not found</h1><p>' . nullable_htmlentities($what) . '</p></main>';
        trr_print_end();
        exit;
    }
}
