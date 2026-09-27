<?php

/*
 * Public certificate check: {baseUrl}/verify/?t=<token>[&lang=es] (Phase 5 spec §5.1, M1/M2;
 * plan A5, C4). The QR code on every certificate P2 prints points here, forever.
 *
 * Minimal fields only (name, course, issued, expires, number, "External card recorded", status),
 * looked up by P2's 24-character token through Upstream\RecordsGateway::verify(), which also
 * re-hashes the token, completion and void rows before a status is shown.
 *
 * No session, no cookies and no JavaScript: this file includes no session, login or guest
 * bootstrap (the spec's grep gate checks for them). Order (spec §5.1):
 *   1 security headers on every response      2 GET/HEAD only (else 405)
 *   3 file-based throttle, before ANY query    4 ONE query: module switch, time zone, company
 *   5 malformed token -> 404, no record query  6 verify page switched off -> 503
 *   7 RecordsGateway::verify()                 8 render (HEAD: headers only)
 * Every value is escaped with htmlspecialchars. DB errors are a generic 503 plus error_log.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Training\Certificates\VerifyStrings;
use ITFlow\Training\Certificates\VerifyThrottle;

// ---- 1 headers --------------------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-transform');
header('X-Robots-Tag: noindex, nofollow');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header_remove('X-Powered-By');

$tv_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$tv_lang = VerifyStrings::pick($_GET['lang'] ?? null, isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null);
$tv_token = $_GET['t'] ?? null;
$tv_company = null;
$tv_logo = null;

$tv_h = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** Sends the page for $state (a VerifyStrings state) with $status and stops. $r = the verify result for record states. */
$tv_render = static function (string $state, int $status, array $r = []) use (&$tv_lang, &$tv_company, &$tv_logo, $tv_method, $tv_token, $tv_h): never {
    http_response_code($status);
    if ($tv_method === 'HEAD') {
        exit;
    }
    $s = VerifyStrings::for($tv_lang);
    [$word, $sentence] = $s['states'][$state];
    $sentence = strtr($sentence, ['{date}' => VerifyStrings::date($r['expires_on'] ?? null, $tv_lang)]);
    $facts = in_array($state, ['valid', 'expiring', 'expired', 'revoked'], true);
    $mark = match ($state) {
        'valid' => '<path d="M20 6 9 17l-5-5"/>',
        'expiring' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'expired' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'revoked' => '<circle cx="12" cy="12" r="8.5"/><path d="m6 18 12-12"/>',
        'throttled' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        default => '<path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    };
    $css = '/css/training_verify.css';
    $mtime = @filemtime(__DIR__ . '/..' . $css);
    // The other-language link keeps a well-formed token (P2's frozen format) and nothing else from the request.
    $switch = '?' . (is_string($tv_token) && preg_match('/^[A-Za-z0-9_-]{24}$/D', $tv_token) === 1 ? 't=' . $tv_token . '&' : '') . 'lang=' . $s['switch_lang'];
    $title = $s['title'] . ($tv_company !== null ? ' · ' . $tv_company : '');
    ?>
<!doctype html>
<html lang="<?= $tv_h($s['lang']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="light dark">
<title><?= $tv_h($title) ?></title>
<link rel="stylesheet" href="<?= $tv_h($css . ($mtime ? '?v=' . $mtime : '')) ?>">
</head>
<body class="tv-body">
<main class="tv-page">
  <header class="tv-head">
    <?php if ($tv_logo !== null) { ?><img class="tv-logo" src="/uploads/settings/<?= $tv_h(rawurlencode($tv_logo)) ?>" alt="<?= $tv_h(strtr($s['logo_alt'], ['{company}' => (string) $tv_company])) ?>"><?php } ?>
    <div>
      <h1 class="tv-title"><?= $tv_h($s['title']) ?></h1>
      <?php if ($tv_company !== null) { ?><div class="tv-sub"><?= $tv_h($tv_company) ?></div><?php } ?>
    </div>
  </header>
  <section class="tv-card tv-card--<?= $tv_h($state) ?>" aria-labelledby="tv-result">
    <div class="tv-result">
      <div class="tv-mark" aria-hidden="true"><span class="tv-mark__dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><?= $mark ?></svg></span></div>
      <h2 id="tv-result" class="tv-state"><?= $tv_h($word) ?></h2>
      <p class="tv-msg"><?= $tv_h($sentence) ?></p>
      <p class="tv-checked"><?= $tv_h(VerifyStrings::checked(time(), $tv_lang)) ?></p>
    </div>
    <?php if ($facts) {
        $external = !empty($r['external']);
        $expires = ($r['expires_on'] ?? null) !== null ? VerifyStrings::date($r['expires_on'], $tv_lang) : $s['no_expiry']; ?>
    <dl class="tv-facts">
      <div class="tv-row"><dt><?= $tv_h($s['name']) ?></dt><dd class="tv-strong"><?= $tv_h($r['name'] ?? '') ?></dd></div>
      <div class="tv-row"><dt><?= $tv_h($s['course']) ?></dt><dd class="tv-strong"><?= $tv_h($r['course'] ?? '') ?></dd></div>
      <div class="tv-row tv-row--pair">
        <dt><?= $tv_h($s['issued']) ?></dt><dd class="tv-strong"><?= $tv_h(VerifyStrings::date($r['issued_on'] ?? null, $tv_lang)) ?></dd>
        <dt><?= $tv_h($s['expires']) ?></dt><dd class="tv-strong"><?= $tv_h($expires) ?></dd>
      </div>
      <?php if (($r['cert_number'] ?? null) !== null) { ?>
      <div class="tv-row"><dt><?= $tv_h($external ? $s['record_no'] : $s['certificate']) ?></dt><dd class="tv-mono"><?= $tv_h($r['cert_number']) ?></dd></div>
      <?php } ?>
    </dl>
    <?php if ($external) { ?><p class="tv-chiprow"><span class="tv-chip"><?= $tv_h($s['external']) ?></span></p><?php } ?>
    <?php } ?>
  </section>
  <p class="tv-note"><svg class="tv-note__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    <span><?= $tv_h($tv_company !== null ? strtr($s['note'], ['{company}' => $tv_company]) : $s['note_plain']) ?></span></p>
  <p class="tv-lang"><a href="<?= $tv_h($switch) ?>" hreflang="<?= $tv_h($s['switch_lang']) ?>" lang="<?= $tv_h($s['switch_lang']) ?>"><?= $tv_h($s['switch']) ?></a></p>
</main>
</body>
</html>
<?php
    exit;
};

// ---- 2 GET / HEAD only ------------------------------------------------------------------------
if ($tv_method !== 'GET' && $tv_method !== 'HEAD') {
    header('Allow: GET, HEAD');
    $tv_render('unavailable', 405);
}

// ---- 3 throttle, before any query -------------------------------------------------------------
$tv_throttle = new VerifyThrottle(
    (string) ($database ?? ''),
    defined('ITFLOW_TRAINING_TEST_VERIFY_ROOT') ? (string) constant('ITFLOW_TRAINING_TEST_VERIFY_ROOT') : null,   // test harness prepend only
    defined('ITFLOW_TRAINING_TEST_VERIFY_CAPS') ? (array) constant('ITFLOW_TRAINING_TEST_VERIFY_CAPS') : null
);
$tv_wait = $tv_throttle->hit(time(), $_SERVER);
if ($tv_wait > 0) {
    header('Retry-After: ' . $tv_wait);
    $tv_render('throttled', 429);
}

// ---- 4 one query: module switch, time zone, company -------------------------------------------
try {
    $tv_res = mysqli_query($mysqli, "SELECT s.config_module_enable_training, s.config_timezone, c.company_name, c.company_logo
        FROM settings s JOIN companies c ON c.company_id = 1 WHERE s.company_id = 1");
    $tv_row = $tv_res ? mysqli_fetch_assoc($tv_res) : null;
    if (!is_array($tv_row)) {
        throw new \RuntimeException('no settings row');
    }
} catch (\Throwable $e) {
    error_log('Training verify: settings query failed: ' . get_class($e) . ': ' . $e->getMessage());
    $tv_render('unavailable', 503);
}
$tv_tz = (string) ($tv_row['config_timezone'] ?? '');
if ($tv_tz !== '' && in_array($tv_tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tv_tz);
}
$tv_company = trim((string) ($tv_row['company_name'] ?? '')) !== '' ? trim((string) $tv_row['company_name']) : null;
$tv_logo_file = (string) ($tv_row['company_logo'] ?? '');
if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp)$/D', $tv_logo_file) === 1 && is_file(__DIR__ . '/../uploads/settings/' . $tv_logo_file)) {
    $tv_logo = $tv_logo_file;
}
if (intval($tv_row['config_module_enable_training'] ?? 0) !== 1) {
    $tv_render('unavailable', 503);
}
// Phase 5 Upstream (Lane A) must be deployed; without it the page degrades to "not available".
if (!class_exists(\ITFlow\Training\Upstream\CertTokens::class) || !class_exists(\ITFlow\Training\Upstream\RecordsGateway::class)) {
    error_log('Training verify: Upstream classes are missing');
    $tv_render('unavailable', 503);
}

// ---- 5 malformed token: 404, no record query --------------------------------------------------
if (!\ITFlow\Training\Upstream\CertTokens::wellFormed($tv_token)) {
    $tv_render('not_found', 404);
}

// ---- 6 page switched off in Training settings -------------------------------------------------
if (class_exists(\ITFlow\Training\Automation\AutomationSettings::class) && \ITFlow\Training\Automation\AutomationSettings::loadVerify($mysqli) === false) {
    $tv_render('unavailable', 503);
}

// ---- 7 lookup + integrity ---------------------------------------------------------------------
try {
    $tv_r = (new \ITFlow\Training\Upstream\RecordsGateway($mysqli))->verify($tv_token, date('Y-m-d'));
} catch (\Throwable $e) {
    error_log('Training verify: lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
    $tv_render('unavailable', 503);
}
$tv_state = (string) ($tv_r['state'] ?? 'unavailable');

if ($tv_state === 'integrity') {
    error_log('Training verify: a certificate record failed its integrity check (token sha ' . substr(hash('sha256', (string) $tv_token), 0, 12) . ')');
    try {
        if (class_exists(\ITFlow\Training\Automation\Notify::class) && class_exists(\ITFlow\Training\Automation\Recipients::class)
            && \ITFlow\Training\Upstream\Schema::has($mysqli, \ITFlow\Training\Upstream\Schema::P5)) {
            $tv_notify = new \ITFlow\Training\Automation\Notify($mysqli);
            foreach (\ITFlow\Training\Automation\Recipients::withLevel($mysqli, 3) as $tv_u) {
                if (empty($tv_u['is_admin'])) {
                    continue;
                }
                try {
                    // One alert per admin per day (Notify's dedupe log). Type 'Training' = the ledger integrity type: unmapped, never muted.
                    $tv_notify->once((int) $tv_u['user_id'], date('Y-m-d'), 'verify_integrity', 'Training',
                        'Certificate check: a training record no longer matches its fingerprint, so the public check shows "Not available" for it. Run Verify now under Training settings › Records ledger.',
                        '/admin/settings_training.php#ledger', ['integrity' => 1]);
                } catch (\Throwable $e) {
                    error_log('Training verify: integrity alert failed: ' . get_class($e));
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Training verify: integrity alert failed: ' . get_class($e));
    }
    $tv_render('unavailable', 503);
}

// ---- 8 render ---------------------------------------------------------------------------------
match ($tv_state) {
    'valid', 'expiring', 'expired', 'revoked' => $tv_render($tv_state, 200, $tv_r),
    'not_found' => $tv_render('not_found', 404),
    default => $tv_render('unavailable', 503),
};
