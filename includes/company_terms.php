<?php
/*
 * The company's own terms-and-conditions link shown on the outtake and worksheet signing pages (Admin > Settings > Tickets).
 * It used to be a fixed address written for one company, which every other install's customers were sent to; it is now a setting and
 * nothing is shown until an administrator sets it.
 */

/** The configured terms link when it is a plain http(s) address of a sensible length, otherwise '' (so no link is drawn). */
function itflow_company_terms_url($raw): string
{
    $url = trim((string) $raw);
    // Control characters, spaces, quotes, angle brackets and backslashes never belong in a link printed into an attribute.
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $url) === 1) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return '';
    }
    return $url;
}
