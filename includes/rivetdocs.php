<?php

/**
 * RivetDocs add-on helpers (optional companion app, off by default).
 *
 * RivetDocs is a separate self-hosted documentation app. This edition only stores its address and links to it;
 * nothing is sent to RivetDocs and no credentials are shared. RivetDocs pulls from this edition's /api/v1 itself.
 * The same file ships in RivetIT and RivetMSP.
 */

/**
 * Normalise an admin-entered RivetDocs address: http(s) only, no credentials, query or fragment, no trailing slash.
 * Returns '' when it is not a usable address.
 */
function rivetdocsNormalizeUrl(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 255 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $raw)) {
        return '';
    }
    $p = parse_url($raw);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return '';
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return '';
    }
    $url = strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    return $url . rtrim($p['path'] ?? '', '/');
}

/** Link to the company list in RivetDocs, filtered to the client's name. '' when the add-on is off or unconfigured. */
function rivetdocsClientLink(bool $enabled, string $baseUrl, string $clientName): string
{
    $base = $enabled ? rivetdocsNormalizeUrl($baseUrl) : '';
    if ($base === '') {
        return '';
    }
    return $base . '/admin/companies?q=' . rawurlencode($clientName);
}
