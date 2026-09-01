<?php
// One-click CSAT star rating, clickable directly from the "please rate this
// ticket" email (industry-standard pattern - Delighted/Zendesk/etc. all embed
// 5 star links directly in the email body rather than requiring a click-through
// to a full page just to then click a star again).
//
// This is a deliberate, scoped exception to this app's "state changes must be
// POST" convention (see the security remediation pass that converted this exact
// guest flow's close/reopen/feedback actions from GET to POST): an email link can
// only ever be a GET request, so a true one-click rating requires accepting that.
// The risk is bounded and low-stakes compared to close/reopen:
//   - scoped by the same unguessable per-ticket url_key bearer token as the rest
//     of the guest flow (not a guessable/enumerable URL)
//   - idempotent (applyCsatRating() only lets the FIRST request win via
//     WHERE ticket_csat_rating IS NULL) - a repeat visit, or an email security
//     gateway prefetching all 5 star links, cannot re-trigger the low-rating
//     reopen/notify side effects or overwrite an existing rating
//   - a rating is soft, correctable data, not a destructive/disruptive action
//
// Known, accepted industry-wide limitation: if a mail gateway prefetches all 5
// links, whichever one it fetches first "wins" the rating rather than the
// recipient's real click. Every one-click email CSAT tool has this same
// limitation - there is no fully reliable technical fix for it that doesn't
// defeat the one-click premise. always lands on the ticket view either way,
// which is the same landing experience.

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";

session_start();

require_once "../includes/inc_set_timezone.php";

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$url_key = sanitizeInput($_GET['url_key'] ?? '');
$rating = intval($_GET['rating'] ?? 0);

$landing_url = "guest_view_ticket.php?ticket_id=" . $ticket_id . "&url_key=" . urlencode($_GET['url_key'] ?? '');

if ($ticket_id && $url_key && $rating >= 1 && $rating <= 5 && !empty($config_ticket_csat_enable)) {
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key' AND ticket_closed_at IS NOT NULL");

    if (mysqli_num_rows($sql) == 1) {
        applyCsatRating($mysqli, $ticket_id, $rating, '', 'a guest', $config_ticket_csat_low_rating_threshold);
        customAction('ticket_feedback', $ticket_id);
    }
}

redirect($landing_url);
