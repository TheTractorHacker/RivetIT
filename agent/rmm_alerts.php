<?php
/*
 * The old "RMM Alerts" page. Alerts now have one home, agent/alerts.php (endpoint agent, RMM integrations, backup and network in one list), so
 * this URL only redirects, carrying the filters it understood: status, severity, client_id, asset_id and q.
 * Kept so bookmarks, e-mailed links and the mobile app's deep links still land somewhere useful.
 */
$q = [];
foreach (['status', 'severity', 'client_id', 'asset_id', 'q'] as $k) {
    if (isset($_GET[$k]) && is_scalar($_GET[$k]) && $_GET[$k] !== '') {
        $q[$k] = (string) $_GET[$k];
    }
}
header('Location: /agent/alerts.php' . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
