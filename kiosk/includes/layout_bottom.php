<?php

/*
 * Kiosk page shell, bottom half (P3 spec §5.1): the k-page-data JSON block (JSON_HEX_* - it can
 * never close its own <script>), the nonce, the runtime and the page scripts (defer).
 *
 * k-page-data = {csrf, lang, strings:{en,es}, idle:{idle_s, warn_s, absolute_left_s},
 *                device:{label, ends_in_s, ends_at, ends_epoch}|null, session:{role, first, name, dept, initials}|null,
 *                brand, api, video?, page:{…}}
 * device.ends_in_s / ends_at / ends_epoch: a TEMPORARY device's seconds left (counted here, so the
 * device's own clock doesn't matter), its end as a local clock time ("3:13 PM") and as server epoch
 * seconds (for /kiosk/?ended=, in case the cron revoked it first); all null for a permanent one.
 */

defined('KIOSK_BOOTSTRAP') || exit;

use ITFlow\Training\Kiosk\Core\KioskCsrf;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\KTime;

$k_sess = $kctx->ksess;
$k_data = [
    'csrf' => is_string($k_page['csrf'] ?? null) ? $k_page['csrf'] : KioskCsrf::pageToken($kctx),
    'lang' => $kctx->lang,
    'strings' => KioskStrings::all(),
    'idle' => [
        'idle_s' => (int) ($k_sess['ksess_idle_limit_s'] ?? $kctx->ks->idleS),
        'warn_s' => 30,
        'absolute_left_s' => $k_sess === null ? null : max(0, (int) floor(KTime::secondsUntil($k_sess['ksess_absolute_until_utc'] ?? null) ?? 0)),
    ],
    'device' => $kctx->device === null ? null : [
        'label' => (string) $kctx->device['kiosk_label'],
        'ends_in_s' => ($kctx->device['kiosk_expires_at_utc'] ?? null) === null ? null
            : max(0, (int) floor(KTime::secondsUntil($kctx->device['kiosk_expires_at_utc']) ?? 0)),
        'ends_at' => ($kctx->device['kiosk_expires_at_utc'] ?? null) === null ? null : KTime::localClock($kctx->device['kiosk_expires_at_utc'], $kctx->lang),
        'ends_epoch' => ($kctx->device['kiosk_expires_at_utc'] ?? null) === null ? null : (int) floor(KTime::epoch($kctx->device['kiosk_expires_at_utc']) ?? 0),
    ],
    'session' => $k_sess === null ? null : [
        'role' => (string) $k_sess['ksess_role'],
        'first' => (string) $k_sess['first'],
        'name' => (string) $k_sess['contact_name'],
        'dept' => (string) $k_sess['dept'],
        'initials' => (string) $k_sess['initials'],
    ],
    'brand' => $kctx->ks->brandWord(),
    'api' => '/kiosk/api.php',
    'page' => is_array($k_page['data'] ?? null) ? $k_page['data'] : new \stdClass(),
];
if (isset($k_page['video']['run_id'], $k_page['video']['lesson_uid'])) {
    $k_data['video'] = ['run_id' => (int) $k_page['video']['run_id'], 'lesson_uid' => (string) $k_page['video']['lesson_uid']];
}
$k_json = json_encode($k_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
$k_js = array_merge(['/js/training_kiosk.js'], array_values(array_filter((array) ($k_page['js'] ?? []), 'is_string')));
$k_nonce = htmlspecialchars($kiosk_csp_nonce, ENT_QUOTES, 'UTF-8');
?>
</main>
<script type="application/json" id="k-page-data"><?= $k_json ?></script>
<script nonce="<?= $k_nonce ?>">window.CSP_NONCE = '<?= $k_nonce ?>';</script>
<?php foreach ($k_js as $k_src) { $k_url = kiosk_asset($k_src); if ($k_url !== null) { ?>
<script src="<?= htmlspecialchars($k_url, ENT_QUOTES, 'UTF-8') ?>" nonce="<?= $k_nonce ?>" defer></script>
<?php } } ?>
</body>
</html>
