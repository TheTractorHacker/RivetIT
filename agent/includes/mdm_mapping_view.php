<?php
/*
 * ManageEngine MDM devices > Mapping: every MDM device (linked, unmatched, ignored) with its current asset, search and filters, per-row
 * Link / Re-link / Unlink and bulk actions, and the "Propose matches" helper. Included by agent/mdm_devices.php for an unrestricted user only
 * (a department-scoped non-admin never reaches it, and the POST handler refuses them too). Posts to agent/post/mdm_mapping.php.
 * Variables from the including page: $mysqli, $mdm, $iid, $csrf, $can_edit, $icon_for.
 */

use ITFlow\Integrations\ManageEngine\MdmMatchSuggester;

$map_q = trim((string) ($_GET['q'] ?? ''));
$map_status = in_array($_GET['status'] ?? '', ['linked', 'unmatched', 'ignored'], true) ? $_GET['status'] : 'all';
$map_platform = mb_substr(trim((string) ($_GET['mplatform'] ?? '')), 0, 30);
$map_client = intval($_GET['mclient'] ?? 0);
$propose = !empty($_GET['propose']) && $can_edit;
$cap = 500;

// Prepared statements throughout: the WHERE text below is fixed code, every filter value is a bound parameter.
$fetch = static function (string $sql, string $types, array $params) use ($mysqli): array {
    $stmt = mysqli_prepare($mysqli, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $out = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    return $out;
};
$qlike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $map_q) . '%';
$plat_lower = strtolower($map_platform);

$rows = [];
$capped = false;
if ($map_status === 'all' || $map_status === 'linked') {
    $w = 'l.integration_id = ?';
    $types = 'i';
    $params = [$iid];
    if ($map_platform !== '') {
        $w .= " AND LOWER(COALESCE(NULLIF(l.platform, ''), 'unknown')) = ?";
        $types .= 's';
        $params[] = $plat_lower;
    }
    if ($map_client) {
        $w .= ' AND a.asset_client_id = ?';
        $types .= 'i';
        $params[] = $map_client;
    }
    if ($map_q !== '') {
        $w .= ' AND (l.device_name LIKE ? OR l.serial_number LIKE ? OR l.imei LIKE ? OR l.udid LIKE ? OR l.user_name LIKE ? OR l.user_email LIKE ? OR a.asset_name LIKE ?)';
        $types .= 'sssssss';
        array_push($params, $qlike, $qlike, $qlike, $qlike, $qlike, $qlike, $qlike);
    }
    $found = $fetch("SELECT l.id, l.mdm_device_id, l.device_name, l.serial_number, l.imei, l.platform, l.model, l.user_name, l.user_email, l.match_method,
            a.asset_id, a.asset_name, a.asset_client_id, a.asset_archived_at, c.client_name
        FROM asset_mdm_links l LEFT JOIN assets a ON a.asset_id = l.asset_id LEFT JOIN clients c ON c.client_id = a.asset_client_id WHERE $w ORDER BY l.device_name ASC LIMIT " . ($cap + 1), $types, $params);
    foreach ($found as $n => $r) {
        if ($n >= $cap) { $capped = true; break; }
        $rows[] = ['kind' => 'l', 'token' => 'l' . (int) $r['id'], 'state' => 'linked'] + $r;
    }
}
if (!$map_client && ($map_status === 'all' || $map_status === 'unmatched' || $map_status === 'ignored')) {
    $w = 'u.integration_id = ?';
    $types = 'i';
    $params = [$iid];
    if ($map_platform !== '') {
        $w .= " AND LOWER(COALESCE(NULLIF(u.platform, ''), 'unknown')) = ?";
        $types .= 's';
        $params[] = $plat_lower;
    }
    if ($map_status === 'unmatched') {
        $w .= ' AND u.dismissed = 0';
    } elseif ($map_status === 'ignored') {
        $w .= ' AND u.dismissed = 1';
    }
    if ($map_q !== '') {
        $w .= ' AND (u.device_name LIKE ? OR u.serial_number LIKE ? OR u.imei LIKE ? OR u.udid LIKE ? OR u.user_name LIKE ? OR u.user_email LIKE ?)';
        $types .= 'ssssss';
        array_push($params, $qlike, $qlike, $qlike, $qlike, $qlike, $qlike);
    }
    $found = $fetch("SELECT u.* FROM mdm_unmatched_devices u WHERE $w ORDER BY u.device_name ASC LIMIT " . ($cap + 1), $types, $params);
    foreach ($found as $n => $r) {
        if ($n >= $cap) { $capped = true; break; }
        $rows[] = ['kind' => 'u', 'token' => 'u' . (int) $r['id'], 'state' => $r['dismissed'] ? 'ignored' : 'unmatched'] + $r;
    }
}
usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['device_name'], (string) $b['device_name']));

// Candidate asset names (the sync's own candidates) and, for "Propose matches", loose suggestions. Suggestions are only ever shown.
$cand_names = [];
$suggest = [];
if ($can_edit) {
    $cand_ids = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'u') {
            foreach (array_filter(array_map('intval', explode(',', (string) $r['candidate_asset_ids']))) as $cid) { $cand_ids[$cid] = true; }
        }
    }
    if ($cand_ids) {
        $res = mysqli_query($mysqli, 'SELECT asset_id, asset_name, asset_serial FROM assets WHERE asset_id IN (' . implode(',', array_map('intval', array_keys($cand_ids))) . ')');
        while ($res && ($r = mysqli_fetch_assoc($res))) { $cand_names[(int) $r['asset_id']] = $r['asset_name'] . ($r['asset_serial'] ? ' (SN ' . $r['asset_serial'] . ')' : '') . ' #' . (int) $r['asset_id']; }
    }
    if ($propose) {
        $pool = [];
        $res = mysqli_query($mysqli, "SELECT a.asset_id, a.asset_name, a.asset_serial, a.asset_tag FROM assets a WHERE a.asset_archived_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM asset_mdm_links l WHERE l.asset_id = a.asset_id AND l.integration_id = $iid) LIMIT 5000");
        while ($res && ($r = mysqli_fetch_assoc($res))) { $pool[(int) $r['asset_id']] = $r; }
        $done = 0;
        foreach ($rows as $r) {
            if ($r['state'] !== 'unmatched' || ++$done > 200) { continue; }
            $s = MdmMatchSuggester::suggest($r, array_values($pool), 3);
            if ($s) {
                $suggest[$r['token']] = array_map(static fn (array $x): array => $x + ['label' => $pool[$x['asset_id']]['asset_name'] . ($pool[$x['asset_id']]['asset_serial'] ? ' (SN ' . $pool[$x['asset_id']]['asset_serial'] . ')' : '') . ' #' . $x['asset_id']], $s);
            }
        }
    }
}
$state_badge = ['linked' => 'success', 'unmatched' => 'warning', 'ignored' => 'secondary'];
$reason_text = ['no_match' => 'No asset has this serial', 'ambiguous' => 'More than one possible asset', 'unlinked' => 'Unlinked by a person'];
$qs = static fn (array $over): string => '?' . http_build_query(array_filter(['view' => 'mapping', 'q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '', 'mplatform' => $_GET['mplatform'] ?? '', 'mclient' => $_GET['mclient'] ?? ''], static fn ($v) => $v !== '' && $v !== null) + [] , '', '&') . ($over ? '&' . http_build_query($over) : '');
?>

<div class="card card-dark mb-2"><div class="card-body py-2">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="view" value="mapping">
        <input type="search" name="q" value="<?= nullable_htmlentities($map_q) ?>" class="form-control form-control-sm w-auto" placeholder="Search name, serial, IMEI, user, asset" style="min-width:260px">
        <select name="status" class="form-select form-select-sm w-auto">
            <option value="">All devices</option>
            <option value="linked" <?= $map_status === 'linked' ? 'selected' : '' ?>>Linked</option>
            <option value="unmatched" <?= $map_status === 'unmatched' ? 'selected' : '' ?>>Unmatched</option>
            <option value="ignored" <?= $map_status === 'ignored' ? 'selected' : '' ?>>Ignored</option>
        </select>
        <select name="mplatform" class="form-select form-select-sm w-auto">
            <option value="">Any platform</option>
            <?php foreach (array_keys($by_platform) as $p) { ?><option value="<?= nullable_htmlentities($p) ?>" <?= strcasecmp($p, $map_platform) === 0 ? 'selected' : '' ?>><?= nullable_htmlentities($p) ?></option><?php } ?>
        </select>
        <select name="mclient" class="form-select form-select-sm w-auto">
            <option value="">Any department (linked)</option>
            <?php foreach ($clients_list as $cl) { ?><option value="<?= (int) $cl['client_id'] ?>" <?= $map_client === (int) $cl['client_id'] ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option><?php } ?>
        </select>
        <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
        <?php if ($map_q !== '' || $map_status !== 'all' || $map_platform !== '' || $map_client) { ?><a href="?view=mapping" class="btn btn-sm btn-secondary">Clear</a><?php } ?>
        <?php if ($can_edit) { ?><a href="<?= $propose ? $qs([]) : $qs(['propose' => 1]) ?>" class="btn btn-sm btn-outline-info ms-auto" title="Suggest assets for unmatched devices by similar serial or name. Suggestions only: nothing is linked until you press Link."><i class="fas fa-magic me-1"></i><?= $propose ? 'Hide suggestions' : 'Propose matches' ?></a><?php } ?>
    </form>
</div></div>

<?php if ($propose) { ?>
    <div class="alert alert-info small py-2">
        <?= count($suggest) ?> unmatched device(s) have a suggestion (similar serial or name). A suggestion only fills the asset box on that row; nothing is linked until you press <strong>Link</strong>.
        Linking by hand is final for that device until you unlink it, so check the asset first.
    </div>
<?php } ?>

<form action="post.php" method="post" id="mdmMapForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <?php if ($can_edit && $rows) { ?>
    <div class="card card-dark mb-2"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <span class="small text-muted me-2">With the selected devices:</span>
        <button type="submit" name="mdm_map_bulk" value="link" class="btn btn-sm btn-primary"><i class="fas fa-link me-1"></i>Link (asset chosen on each row)</button>
        <button type="submit" name="mdm_map_bulk" value="unlink" class="btn btn-sm btn-outline-danger" data-confirm="Unlink the selected devices? They go back to the review list; the next sync links them again if their serial still matches an asset."><i class="fas fa-unlink me-1"></i>Unlink</button>
        <button type="submit" name="mdm_map_bulk" value="ignore" class="btn btn-sm btn-outline-secondary">Ignore</button>
        <button type="submit" name="mdm_map_bulk" value="restore" class="btn btn-sm btn-outline-secondary">Restore</button>
        <div class="form-check ms-3">
            <input class="form-check-input" type="checkbox" name="replace" value="1" id="mdmReplace">
            <label class="form-check-label small" for="mdmReplace">Replace an existing link (an asset can have only one MDM link; the device that held it returns to the review list)</label>
        </div>
    </div></div>
    <?php } ?>

    <div class="card card-dark"><div class="card-body p-0">
    <?php if (!$rows) { ?>
        <div class="text-center text-muted py-5"><i class="fas fa-tablet-alt fa-3x mb-3"></i><p>No devices match.</p></div>
    <?php } else { ?>
        <table class="table table-sm mb-0 align-middle">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th class="ps-3" style="width:30px"><?php if ($can_edit) { ?><input type="checkbox" class="form-check-input mdm-select-all" title="Select all"><?php } ?></th>
                    <th>Device</th><th>MDM user</th><th>State</th><th>Current asset</th><th style="min-width:360px">Link to asset</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) { $tok = $r['token']; ?>
                <tr>
                    <td class="ps-3"><?php if ($can_edit) { ?><input type="checkbox" class="form-check-input mdm-row-check" name="rows[]" value="<?= nullable_htmlentities($tok) ?>"><?php } ?></td>
                    <td>
                        <i class="fab <?= $icon_for((string) $r['platform']) ?> me-1"></i><strong><?= nullable_htmlentities($r['device_name'] ?: '(no name)') ?></strong>
                        <div class="small text-muted"><?= nullable_htmlentities($r['model']) ?> &middot; SN <?= nullable_htmlentities($r['serial_number'] ?: '-') ?><?= !empty($r['imei']) ? ' &middot; IMEI ' . nullable_htmlentities($r['imei']) : '' ?></div>
                    </td>
                    <td class="small text-muted"><?= nullable_htmlentities($r['user_name'] ?: $r['user_email']) ?: '&mdash;' ?></td>
                    <td>
                        <span class="badge text-bg-<?= $state_badge[$r['state']] ?>"><?= ucfirst($r['state']) ?></span>
                        <?php if ($r['kind'] === 'u') { ?><div class="small text-muted"><?= nullable_htmlentities($reason_text[$r['reason']] ?? $r['reason']) ?></div>
                        <?php } else { ?><div class="small text-muted">by <?= nullable_htmlentities($r['match_method'] ?: 'sync') ?></div><?php } ?>
                    </td>
                    <td class="small">
                        <?php if ($r['kind'] === 'l' && $r['asset_id']) { ?>
                            <a href="/agent/asset_details.php?client_id=<?= (int) $r['asset_client_id'] ?>&asset_id=<?= (int) $r['asset_id'] ?>"><?= nullable_htmlentities($r['asset_name']) ?></a>
                            <?= $r['asset_archived_at'] ? '<span class="badge text-bg-dark">archived</span>' : '' ?>
                            <div class="text-muted"><?= nullable_htmlentities((string) $r['client_name']) ?></div>
                        <?php } else { ?><span class="text-muted">&mdash;</span><?php } ?>
                    </td>
                    <td>
                    <?php if ($can_edit && $r['state'] !== 'ignored') {
                        $first_cand = $r['kind'] === 'u' && count($cs = array_values(array_filter(array_map('intval', explode(',', (string) $r['candidate_asset_ids']))))) === 1 ? $cs[0] : 0; ?>
                        <div class="d-flex gap-1 mb-1">
                            <input type="text" class="form-control form-control-sm mdm-typeahead" id="mdm_in_<?= nullable_htmlentities($tok) ?>" data-kind="asset" data-target="asset_for_<?= nullable_htmlentities($tok) ?>"
                                   value="<?= $first_cand ? nullable_htmlentities($cand_names[$first_cand] ?? '') : '' ?>" placeholder="Search assets by name or serial..." autocomplete="off">
                            <input type="hidden" id="asset_for_<?= nullable_htmlentities($tok) ?>" name="asset_for[<?= nullable_htmlentities($tok) ?>]" value="<?= $first_cand ?: '' ?>">
                            <button type="submit" name="mdm_map_one" value="<?= nullable_htmlentities($tok) ?>|link" class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-link me-1"></i><?= $r['kind'] === 'l' ? 'Re-link' : 'Link' ?></button>
                            <?php if ($r['kind'] === 'l') { ?><button type="submit" name="mdm_map_one" value="<?= nullable_htmlentities($tok) ?>|unlink" class="btn btn-sm btn-outline-danger text-nowrap" data-confirm="Unlink this device from its asset?"><i class="fas fa-unlink"></i></button>
                            <?php } else { ?><button type="submit" name="mdm_map_one" value="<?= nullable_htmlentities($tok) ?>|ignore" class="btn btn-sm btn-outline-secondary">Ignore</button><?php } ?>
                        </div>
                        <?php foreach ($suggest[$tok] ?? [] as $s) { ?>
                            <button type="button" class="btn btn-xs btn-outline-info mdm-suggest me-1 mb-1" data-input="mdm_in_<?= nullable_htmlentities($tok) ?>" data-asset-id="<?= (int) $s['asset_id'] ?>" data-label="<?= nullable_htmlentities($s['label']) ?>" title="Suggested: <?= nullable_htmlentities($s['why']) ?>">
                                <?= nullable_htmlentities($s['label']) ?> &middot; <?= (int) round($s['score'] * 100) ?>% <?= nullable_htmlentities($s['why']) ?>
                            </button>
                        <?php } ?>
                    <?php } elseif ($can_edit) { ?>
                        <button type="submit" name="mdm_map_one" value="<?= nullable_htmlentities($tok) ?>|restore" class="btn btn-sm btn-outline-secondary">Restore to review</button>
                    <?php } else { ?><span class="text-muted small">Needs edit access</span><?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($capped) { ?><div class="p-2 small text-muted">Showing the first <?= $cap ?> devices of a type. Narrow the search to see the rest.</div><?php } ?>
    <?php } ?>
    </div></div>
</form>
<script src="/agent/js/mdm_mapping.js?v=<?= filemtime(__DIR__ . '/../js/mdm_mapping.js') ?>"></script>
