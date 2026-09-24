<?php

/*
 * Training › in-window video check frame: /agent/training_video_frame.php?p=youtube|vimeo&id=…[&h=…]
 * (spec §5.4 "Frame page", §4.4, §4.6). Level 2.
 *
 * The one agent page the app frames itself: the Create Content window (and anything else on this
 * origin) embeds it to verify a YouTube / Vimeo video by playing it, without leaving the window.
 *
 *   - Lean bootstrap; the session is closed right after the CSRF token is read.
 *   - It does NOT include includes/header.php: no app chrome, no author HTML, no X-Frame-Options.
 *     Instead it sends its own CSP with frame-ancestors 'self' (only this origin may frame it) and
 *     only the player hosts in script-src / frame-src.
 *   - p / id / h are validated by VideoLink::fromParts; the embed URL is rebuilt server-side with
 *     origin= from $config_base_url (Ctx::baseUrl), never from the Host header.
 *   - js/training_video_embed.js mounts the player; agent/js/training_video_frame.js POSTs
 *     video_verify on the first PLAYING (or {ok:false} on a player error) and reports to the
 *     parent with postMessage(…, location.origin) and on BroadcastChannel('itflow-training').
 *
 * Message contract (both postMessage data and the BroadcastChannel payload):
 *   {type:'tr-video-ready',    provider, ext_id, ext_hash}
 *   {type:'tr-video-verified', provider, ext_id, ext_hash, duration_s, check}        check = VideoCheck
 *   {type:'tr-video-error',    provider, ext_id, ext_hash, error_code, message, check|null}
 * BroadcastChannel messages are wrapped as {type, payload} (TrainingUi.channel() shape).
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

// Read the CSRF token, then release the per-user session lock at once.
$tr_csrf = is_string($_SESSION['csrf_token'] ?? null) ? $_SESSION['csrf_token'] : '';
session_write_close();

$tr_nonce = base64_encode(random_bytes(16));

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; "
    . "script-src 'nonce-$tr_nonce' 'self' https://static.cloudflareinsights.com https://www.youtube.com/iframe_api https://www.youtube.com/s/player/ https://player.vimeo.com/api/player.js; "
    . "frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://cloudflareinsights.com; "
    . "frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$tr_error = null;
$tr_status = 200;
$tr_data = null;

if (!\ITFlow\Training\Core\Access::enabled()) {
    $tr_status = 404;
    $tr_error = 'Training is turned off.';
} elseif (\ITFlow\Training\Core\Access::level() < 2) {
    $tr_status = 403;
    $tr_error = "You don't have access to check videos.";
} else {
    $tr_p = is_string($_GET['p'] ?? null) ? $_GET['p'] : '';
    $tr_id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $tr_h = is_string($_GET['h'] ?? null) ? $_GET['h'] : '';
    $tr_video = \ITFlow\Training\Media\VideoLink::fromParts($tr_p, $tr_id, $tr_h);
    if ($tr_video === null) {
        $tr_status = 400;
        $tr_error = 'That is not a valid YouTube or Vimeo video.';
    } else {
        $tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
        try {
            $tr_embed = \ITFlow\Training\Media\VideoLink::embedUrl($tr_video, $tr_ctx->baseUrl);
            $tr_data = [
                'provider' => $tr_video['provider'],
                'embed_url' => $tr_embed,
                'csrf' => $tr_csrf,
                'verify_url' => '/agent/training_ajax.php?action=video_verify',
                'ext' => ['provider' => $tr_video['provider'], 'id' => $tr_video['id'], 'hash' => $tr_video['hash']],
            ];
        } catch (\InvalidArgumentException $e) {
            $tr_status = 400;
            $tr_error = 'That is not a valid YouTube or Vimeo video.';
        }
    }
}
http_response_code($tr_status);

$tr_v_embed = filemtime(__DIR__ . '/../js/training_video_embed.js');
$tr_v_frame = filemtime(__DIR__ . '/js/training_video_frame.js');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Video check</title>
<style>
    html, body { margin: 0; height: 100%; background: #0f1417; color: #e7eeef; font: 14px/1.4 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    .trv { position: relative; width: 100%; height: 100%; min-width: 200px; min-height: 200px; display: flex; flex-direction: column; }
    .trv-player { position: relative; flex: 1 1 auto; min-height: 200px; }
    .trv-embed, .trv-embed__frame { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
    .trv-status { flex: none; padding: 8px 12px; background: #16232a; font-size: 13px; }
    .trv-status[hidden] { display: none; }
    .trv-status.is-error { background: #7f1d1d; color: #fff; }
    .trv-status.is-ok { background: #14532d; color: #fff; }
    .trv-msg { margin: auto; padding: 24px; max-width: 420px; text-align: center; }
</style>
</head>
<body>
<?php if ($tr_error !== null) { ?>
<div class="trv"><p class="trv-msg" role="alert"><?= nullable_htmlentities($tr_error) ?></p></div>
<?php } else { ?>
<div class="trv" id="trv-root">
    <div class="trv-player" id="trv-player"></div>
    <div class="trv-status" id="trv-status" role="status" aria-live="polite" hidden></div>
</div>
<script type="application/json" id="trv-data" nonce="<?= $tr_nonce ?>"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/js/training_video_embed.js?v=<?= $tr_v_embed ?>" nonce="<?= $tr_nonce ?>"></script>
<script src="/agent/js/training_video_frame.js?v=<?= $tr_v_frame ?>" nonce="<?= $tr_nonce ?>"></script>
<?php } ?>
</body>
</html>
