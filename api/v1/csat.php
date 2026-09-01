<?php
// Public CSAT summary - GET /api/v1/csat. No auth (see the public-endpoint
// carve-out in index.php). Returns only the aggregate rating a visitor could
// already infer from a Google review badge - never client names, contact
// names, or ticket subjects - so it's safe to embed directly in a public
// marketing site with no further filtering by the caller.

header('Cache-Control: public, max-age=300');

$summary_row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(ticket_id) AS total, AVG(ticket_csat_rating) AS avg_rating
     FROM tickets
     WHERE ticket_csat_rating IS NOT NULL AND ticket_archived_at IS NULL"));

$total = intval($summary_row['total'] ?? 0);
$avg   = $summary_row['avg_rating'] !== null ? round(floatval($summary_row['avg_rating']), 2) : null;

$breakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$res = mysqli_query($mysqli,
    "SELECT ticket_csat_rating AS r, COUNT(ticket_id) AS c FROM tickets
     WHERE ticket_csat_rating IS NOT NULL AND ticket_archived_at IS NULL
     GROUP BY ticket_csat_rating");
while ($row = mysqli_fetch_assoc($res)) {
    $r = intval($row['r']);
    if (isset($breakdown[$r])) {
        $breakdown[$r] = intval($row['c']);
    }
}

// Recent positive (4-5 star) testimonial snippets - comment text + rating +
// date only, no name/client/ticket attribution at all, by design.
//
// Two deliberate safeguards beyond that, since this endpoint has no auth:
//  1. Only comments a staff member explicitly marked ticket_csat_public_approved
//     - a CSAT comment is captured with "anything you'd like to add? (optional)",
//     never with the client's knowledge it might be quoted on the public
//     website, and free text can carry PII (names, phone numbers, etc.) that
//     the structured fields deliberately exclude but can't be filtered out of
//     prose automatically. A human reviewing before publish is the only
//     reliable guard against that.
//  2. strip_tags() as defense-in-depth against stored XSS on the site that
//     embeds this - the comment is stored as raw user input (only SQL-escaped,
//     never HTML-escaped, see applyCsatRating()), and this endpoint's entire
//     purpose is "embed this on a public page", so it can't assume every
//     future caller remembers to escape it before rendering.
$testimonials = [];
$res = mysqli_query($mysqli,
    "SELECT ticket_csat_rating AS rating, ticket_csat_comment AS comment, ticket_csat_rated_at AS rated_at
     FROM tickets
     WHERE ticket_csat_rating >= 4 AND ticket_csat_comment IS NOT NULL AND ticket_csat_comment != ''
       AND ticket_csat_public_approved = 1
       AND ticket_archived_at IS NULL
     ORDER BY ticket_csat_rated_at DESC
     LIMIT 10");
while ($row = mysqli_fetch_assoc($res)) {
    $testimonials[] = [
        'rating'  => intval($row['rating']),
        'comment' => strip_tags($row['comment']),
        'date'    => date('Y-m-d', strtotime($row['rated_at'])),
    ];
}

api_response(200, [
    'average_rating'   => $avg,
    'total_ratings'    => $total,
    'rating_breakdown' => $breakdown,
    'testimonials'     => $testimonials,
]);
