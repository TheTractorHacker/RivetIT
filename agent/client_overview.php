<?php

// This page is always client-scoped - without client_id it crashes on an
// undefined $client_id further down, since inc_all_client.php only defines
// it when the param is present.
if (!isset($_GET['client_id']) || !intval($_GET['client_id'])) {
    header("Location: clients.php");
    exit();
}

require_once "includes/inc_all_client.php";

/*
 * includes/page_title.php derives the browser tab title mechanically from the
 * FILENAME, so this page announced itself as "Client Overview" while every
 * visible label on it - All Departments, Edit Department, Archive Department -
 * says Department. includes/footer.php composes document.title from $page_title
 * at the very END of the request, so overriding it here (after the include, as
 * agent/ticket.php and agent/project_details.php already do with $tab_title) is
 * enough, and no file rename is needed.
 */
$page_title = 'Department Overview';

// ── Queries ───────────────────────────────────────────────────────────────────

// Roles audit P1: this is a Departments page, but some cards show other modules' records. Each of those
// queries matches nothing when the role lacks the owning module (Technician and admins hold them all, so
// nothing changes for them): tickets, domains, certificates, licenses and shared links need
// Tickets/assets/docs; assets need Assets or Tickets/assets/docs.
$co_support_sql = lookupUserPermission('module_support') >= 1 ? '' : ' AND 1 = 0';
$co_assets_sql  = itflow_can_assets(1) ? '' : ' AND 1 = 0';

$sql_important_contacts = mysqli_query($mysqli,
    "SELECT * FROM contacts
     WHERE contact_client_id = $client_id
       AND (contact_important = 1 OR contact_billing = 1 OR contact_technical = 1 OR contact_primary = 1)
       AND contact_archived_at IS NULL
     ORDER BY contact_primary DESC, contact_name DESC LIMIT 5");

$sql_client_locations = mysqli_query($mysqli,
    "SELECT locations.location_id, location_name, location_city, location_state
     FROM department_sites
     INNER JOIN locations ON locations.location_id = department_sites.location_id
     WHERE department_sites.client_id = $client_id AND locations.location_archived_at IS NULL
     ORDER BY location_name ASC");

$sql_favorite_assets = mysqli_query($mysqli,
    "SELECT * FROM assets
     WHERE asset_client_id = $client_id AND asset_favorite = 1 AND asset_archived_at IS NULL $co_assets_sql
     ORDER BY asset_type ASC, asset_name ASC");

$sql_favorite_credentials = mysqli_query($mysqli,
    "SELECT * FROM credentials
     WHERE credential_client_id = $client_id AND credential_favorite = 1 AND credential_archived_at IS NULL
     ORDER BY credential_name ASC");

$sql_open_tickets = mysqli_query($mysqli,
    "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_priority,
            ticket_updated_at, ticket_status_name, ticket_status_color
     FROM tickets
     LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
     WHERE ticket_client_id = $client_id AND ticket_archived_at IS NULL AND ticket_closed_at IS NULL AND ticket_status != 4 $co_support_sql
     ORDER BY ticket_updated_at DESC LIMIT 6");

/*
 * Recent Activity feed.
 *
 * Every visit to a client-scoped page writes an audit row (agent/credentials.php
 * logs "viewed the Credentials page" on every load, for instance), so a plain
 * "ORDER BY log_created_at DESC LIMIT 8" on a quiet department was mostly the
 * same sentence repeated - on department 18 five of the eight rows were the
 * byte-identical page-view line, and the ticket events a technician came for
 * were squeezed into the top third. Grouping identical descriptions collapses
 * that repetition into ONE row carrying a count, so the eight slots hold eight
 * different things. Nothing is lost: the verbatim, uncollapsed sequence is one
 * click away behind the header's "Full log" link.
 */
$sql_recent_activities = mysqli_query($mysqli,
    "SELECT log_description, log_type, log_action,
            MAX(log_created_at) AS log_created_at, COUNT(*) AS log_count
     FROM logs
     WHERE log_client_id = $client_id
     GROUP BY log_description, log_type, log_action
     ORDER BY log_created_at DESC LIMIT 8");

// Last thing that actually HAPPENED in this department - page views excluded,
// since "someone opened the credentials list" is not a change. Drives the
// "Last activity" tile in the at-a-glance strip below.
$last_real_event = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT log_description, log_created_at FROM logs
     WHERE log_client_id = $client_id AND log_action != 'View'
     ORDER BY log_created_at DESC LIMIT 1"));

$sql_shared_items = mysqli_query($mysqli,
    "SELECT * FROM shared_items WHERE item_client_id = $client_id AND item_active = 1 $co_support_sql ORDER BY item_created_at ASC LIMIT 5");

// Stale Tickets (no activity in 7+ days)
$sql_stale_tickets = mysqli_query($mysqli,
    "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_updated_at FROM tickets
     WHERE ticket_client_id = $client_id AND ticket_updated_at < CURRENT_DATE - INTERVAL 7 DAY $co_support_sql
       AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
     ORDER BY ticket_updated_at ASC");

// Expiring (45 day window)
$sql_domains_expiring            = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_client_id=$client_id $co_support_sql AND domain_expire IS NOT NULL AND domain_archived_at IS NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY domain_expire ASC");
$sql_certificates_expiring       = mysqli_query($mysqli, "SELECT * FROM certificates WHERE certificate_client_id=$client_id $co_support_sql AND certificate_expire IS NOT NULL AND certificate_archived_at IS NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY certificate_expire ASC");
$sql_licenses_expiring           = mysqli_query($mysqli, "SELECT * FROM software WHERE software_client_id=$client_id $co_support_sql AND software_expire IS NOT NULL AND software_archived_at IS NULL AND software_expire > CURRENT_DATE AND software_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY software_expire ASC");
$sql_asset_warranties_expiring   = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id $co_assets_sql AND asset_warranty_expire IS NOT NULL AND asset_archived_at IS NULL AND asset_warranty_expire > CURRENT_DATE AND asset_warranty_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY asset_warranty_expire ASC");
$sql_asset_retire                = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id $co_assets_sql AND asset_install_date IS NOT NULL AND asset_archived_at IS NULL AND asset_install_date + INTERVAL 7 YEAR > CURRENT_DATE AND asset_install_date + INTERVAL 7 YEAR <= CURRENT_DATE + INTERVAL 45 DAY ORDER BY asset_install_date ASC");

// Expired
$sql_domains_expired             = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_client_id=$client_id $co_support_sql AND domain_expire IS NOT NULL AND domain_archived_at IS NULL AND domain_expire < CURRENT_DATE ORDER BY domain_expire ASC");
$sql_certificates_expired        = mysqli_query($mysqli, "SELECT * FROM certificates WHERE certificate_client_id=$client_id $co_support_sql AND certificate_expire IS NOT NULL AND certificate_archived_at IS NULL AND certificate_expire < CURRENT_DATE ORDER BY certificate_expire ASC");
$sql_licenses_expired            = mysqli_query($mysqli, "SELECT * FROM software WHERE software_client_id=$client_id $co_support_sql AND software_expire IS NOT NULL AND software_archived_at IS NULL AND software_expire < CURRENT_DATE ORDER BY software_expire ASC");
$sql_asset_warranties_expired    = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id $co_assets_sql AND asset_warranty_expire IS NOT NULL AND asset_archived_at IS NULL AND asset_warranty_expire < CURRENT_DATE ORDER BY asset_warranty_expire ASC");
$sql_asset_retired               = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id $co_assets_sql AND asset_install_date IS NOT NULL AND asset_archived_at IS NULL AND asset_install_date + INTERVAL 7 YEAR < CURRENT_DATE ORDER BY asset_install_date ASC");

/*
 * Counts behind the at-a-glance strip and the attention row further down.
 * mysqli_num_rows() does not move a buffered result's cursor, so every one of
 * these result sets is still walked in full by the cards below - this just reads
 * their size once, up front, instead of each consumer counting again.
 */
$num_stale_tickets = mysqli_num_rows($sql_stale_tickets);
$num_expiring_45   = mysqli_num_rows($sql_domains_expiring) + mysqli_num_rows($sql_certificates_expiring)
                   + mysqli_num_rows($sql_licenses_expiring) + mysqli_num_rows($sql_asset_warranties_expiring)
                   + mysqli_num_rows($sql_asset_retire);
$num_expired_items = mysqli_num_rows($sql_domains_expired) + mysqli_num_rows($sql_certificates_expired)
                   + mysqli_num_rows($sql_licenses_expired) + mysqli_num_rows($sql_asset_warranties_expired)
                   + mysqli_num_rows($sql_asset_retired);

// RMM health
$rmm_client_stats = null;
if ($config_module_enable_rmm && lookupUserPermission('module_rmm') >= 1) {
    $rmm_client_stats = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT SUM(arl.rmm_status='online') as online_cnt, SUM(arl.rmm_status='offline') as offline_cnt, COUNT(arl.id) as total_cnt,
                (SELECT COUNT(*) FROM rmm_alerts ra JOIN asset_rmm_links al2 ON al2.asset_id=ra.asset_id JOIN assets a2 ON a2.asset_id=al2.asset_id WHERE a2.asset_client_id=$client_id AND ra.status='new' AND ra.severity IN ('critical','error')) as crit_alerts,
                (SELECT COUNT(*) FROM rmm_alerts ra JOIN asset_rmm_links al2 ON al2.asset_id=ra.asset_id JOIN assets a2 ON a2.asset_id=al2.asset_id WHERE a2.asset_client_id=$client_id AND ra.status='new') as total_alerts
         FROM asset_rmm_links arl JOIN assets a ON a.asset_id=arl.asset_id WHERE a.asset_client_id=$client_id AND a.asset_archived_at IS NULL"
    ));
}

// Included support issues (residential subscription plans) - null 'included' means not configured for this client
$client_issues_usage = getClientIncludedIssuesUsage($mysqli, $client_id);

?>

<!-- ── RMM health strip ──────────────────────────────────────────────────── -->
<?php if ($rmm_client_stats && intval($rmm_client_stats['total_cnt']) > 0): ?>
<div class="row mb-2">
    <div class="col-12">
        <div class="card mb-0" style="border-left:4px solid <?= intval($rmm_client_stats['crit_alerts']) ? '#dc3545' : (intval($rmm_client_stats['offline_cnt']) ? '#ffc107' : '#28a745') ?>">
            <div class="card-body py-2 d-flex align-items-center flex-wrap" style="gap:12px">
                <span class="text-muted small fw-bold"><i class="fas fa-heartbeat me-1"></i>RMM</span>
                <span>
                    <span class="badge text-bg-success me-1"><?= intval($rmm_client_stats['online_cnt']) ?> Online</span>
                    <span class="badge text-bg-secondary"><?= intval($rmm_client_stats['offline_cnt']) ?> Offline</span>
                </span>
                <?php if (intval($rmm_client_stats['total_alerts']) > 0): ?>
                <a href="rmm_alerts.php?client_id=<?= $client_id ?>&status=new" class="text-decoration-none">
                    <span class="badge text-bg-danger"><i class="fas fa-bell me-1"></i><?= intval($rmm_client_stats['total_alerts']) ?> Alert<?= $rmm_client_stats['total_alerts'] != 1 ? 's' : '' ?></span>
                    <?php if ($rmm_client_stats['crit_alerts'] > 0): ?>
                        <span class="badge text-bg-danger ms-1"><?= intval($rmm_client_stats['crit_alerts']) ?> Critical</span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="rmm_assets.php?client_id=<?= $client_id ?>" class="btn btn-xs btn-outline-secondary ml-auto">
                    <i class="fas fa-desktop me-1"></i>View RMM Assets
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Included support issues strip ─────────────────────────────────────── -->
<?php if ($client_issues_usage['remote']['included'] !== null || $client_issues_usage['onsite']['included'] !== null): ?>
<div class="row mb-2">
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-body py-2 d-flex align-items-center flex-wrap" style="gap:20px">
                <span class="text-muted small fw-bold"><i class="fas fa-house-user me-1"></i>Included Support Hours</span>
                <?php foreach (['remote' => ['icon' => 'fa-laptop', 'label' => 'Remote'], 'onsite' => ['icon' => 'fa-house-user', 'label' => 'Onsite']] as $key => $meta):
                    $u = $client_issues_usage[$key];
                    if ($u['included'] === null) continue;
                ?>
                <span class="d-flex align-items-center" style="gap:6px;border-left:4px solid <?= $u['pct'] !== null && $u['pct'] >= 100 ? '#dc3545' : (($u['pct'] ?? 0) >= 80 ? '#ffc107' : '#28a745') ?>;padding-left:8px">
                    <i class="fas fa-fw <?= $meta['icon'] ?>"></i>
                    <span><?= $meta['label'] ?>: <?= number_format($u['used'], 2) ?> / <?= number_format($u['included'], 2) ?> hrs used this month</span>
                    <?php if ($u['remaining'] !== null && $u['remaining'] < 0): ?>
                        <span class="badge text-bg-danger"><?= number_format(abs($u['remaining']), 2) ?> hrs over</span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Department at a glance ────────────────────────────────────────────────
     The first 600px of this page used to carry no operational signal at all: a
     money widget, an empty textarea and a row of location chips came before the
     ticket list a technician actually opened it for. These tiles answer "what is
     the state of this department?" in one line, every one of them a link to the
     section it counts, and every one of them rendering a calm zero rather than
     disappearing - "no assets recorded" is itself an answer. -->
<?php
$stat_tiles = [];

if ($config_module_enable_ticketing == 1 && lookupUserPermission('module_support') >= 1) {
    $stat_tiles[] = [
        'label' => 'Open tickets',
        'icon'  => 'fa-ticket-alt',
        'value' => intval($num_active_tickets),
        'href'  => "tickets.php?client_id=$client_id",
        'sub'   => $num_stale_tickets > 0
            ? '<span class="text-warning">' . intval($num_stale_tickets) . ' stale</span>'
            : intval($num_closed_tickets) . ' closed',
    ];
}

$stat_tiles[] = [
    'label' => 'Contacts',
    'icon'  => 'fa-users',
    'value' => intval($num_contacts),
    'href'  => "contacts.php?client_id=$client_id",
];

if ($config_module_enable_itdoc == 1 && itflow_can_assets(1)) {   // Assets or Tickets/assets/docs (P4)
    $stat_tiles[] = [
        'label' => 'Assets',
        'icon'  => 'fa-laptop',
        'value' => intval($num_assets),
        'href'  => "assets.php?client_id=$client_id",
    ];
}

if ($config_module_enable_itdoc == 1 && lookupUserPermission('module_credential') >= 1) {
    $stat_tiles[] = [
        'label' => 'Credentials',
        'icon'  => 'fa-key',
        'value' => intval($num_credentials),
        'href'  => "credentials.php?client_id=$client_id",
    ];
}

$stat_tiles[] = [
    'label'       => 'Expiring 45d',
    'icon'        => 'fa-hourglass-half',
    'value'       => $num_expiring_45,
    'value_class' => $num_expiring_45 > 0 ? 'text-warning' : '',
    // Anchors to the attention row further down, but only when that row renders.
    'href'        => ($num_stale_tickets || $num_expiring_45 || $num_expired_items) ? '#dept-attention' : null,
    'sub'         => $num_expired_items > 0 ? '<span class="text-danger">' . $num_expired_items . ' expired</span>' : '',
];

$stat_tiles[] = [
    'label' => 'Last activity',
    'icon'  => 'fa-history',
    'text'  => $last_real_event ? nullable_htmlentities(timeAgo($last_real_event['log_created_at'])) : 'None yet',
    'sub'   => $last_real_event ? nullable_htmlentities($last_real_event['log_description']) : '',
];

// One tile body, whether or not the tile is a link.
$render_stat_tile = function (array $tile): string {
    $value = isset($tile['text']) ? $tile['text'] : number_format($tile['value']);
    $size  = isset($tile['text']) ? 'h4' : 'h2';
    $sub   = $tile['sub'] ?? '';
    $title = $sub !== '' ? ' title="' . htmlspecialchars(strip_tags(html_entity_decode($sub, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<div class="subheader text-truncate"><i class="fas fa-fw ' . $tile['icon'] . ' me-1"></i>' . $tile['label'] . '</div>'
         . '<div class="d-flex align-items-center" style="min-height:2.1rem">'
         . '<span class="' . $size . ' mb-0 lh-1 ' . ($tile['value_class'] ?? '') . '">' . $value . '</span>'
         . '</div>'
         . '<div class="small text-muted text-truncate"' . $title . '>' . ($sub !== '' ? $sub : '&nbsp;') . '</div>';
};
?>
<div class="row">
    <div class="col-12">
        <!-- list-group-horizontal-md, not a hand-rolled grid: Bootstrap gives the
             dividers, the equal columns and the hover state in both themes, and it
             stacks on its own below md instead of squeezing six tiles onto a phone. -->
        <div class="card mb-3 js-dept-stats">
            <div class="list-group list-group-flush list-group-horizontal-md">
                <?php foreach ($stat_tiles as $i => $tile):
                    /*
                     * flex:1 1 0 (not Bootstrap's .flex-fill, which is 1 1 auto)
                     * so the tiles are equal columns instead of columns sized
                     * by their own text; min-width:0 lets the long ones truncate
                     * rather than push the row wider. The divider is dropped on
                     * the last tile, and once the list stacks below md every item
                     * is full width, so that edge lands on the card's own border
                     * and vanishes - no responsive variant needed.
                     */
                    $tile_style = ' style="flex:1 1 0;min-width:0'
                        . ($i < count($stat_tiles) - 1 ? ';border-inline-end:1px solid var(--tblr-border-color)' : '') . '"';
                ?>
                    <?php if (!empty($tile['href'])): ?>
                    <a href="<?= $tile['href'] ?>" class="list-group-item list-group-item-action py-2 px-3 js-dept-stat"<?= $tile_style ?>><?= $render_stat_tile($tile) ?></a>
                    <?php else: ?>
                    <div class="list-group-item py-2 px-3 js-dept-stat"<?= $tile_style ?>><?= $render_stat_tile($tile) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── The department body: one deliberate two-column block ─────────────────
     Everything below used to be paired row by row, so a tall card and a short
     one sat side by side and the row's height was whatever the tallest card
     happened to want - a 280px hole under Open Tickets here, 116px under Key
     Contacts there. Two stacked columns instead: the wide one carries the work
     (what is open, what just happened), the rail carries reference (who to call,
     the scratchpad, which buildings). Both columns always have content - Open
     Tickets and Quick Notes always render - so neither can leave a bare gutter
     the way a lone col-md-8 card did on a department with nothing in it. -->
<?php
$has_key_contacts = mysqli_num_rows($sql_important_contacts) > 0;
$has_activity     = mysqli_num_rows($sql_recent_activities) > 0;
$has_locations    = mysqli_num_rows($sql_client_locations) > 0;
?>
<div class="row">

    <div class="col-lg-8">

        <!-- Open Tickets -->
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">
                    <i class="fas fa-fw fa-ticket-alt me-2"></i>Open Tickets
                    <?php if ($num_active_tickets > 0): ?>
                        <span class="badge text-bg-danger ms-1"><?= $num_active_tickets ?></span>
                    <?php endif; ?>
                </h5>
                <a href="tickets.php?client_id=<?= $client_id ?>" class="text-muted small">View all <i class="fas fa-chevron-right fa-xs"></i></a>
            </div>
            <?php if (mysqli_num_rows($sql_open_tickets) > 0): ?>
            <table class="table table-sm table-hover mb-0">
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_open_tickets)):
                    $tid     = intval($row['ticket_id']);
                    $tnum    = nullable_htmlentities($row['ticket_prefix'] . $row['ticket_number']);
                    $tsubj   = nullable_htmlentities($row['ticket_subject']);
                    $tprio   = nullable_htmlentities($row['ticket_priority']);
                    $tsname  = nullable_htmlentities($row['ticket_status_name']);
                    $tscolor = nullable_htmlentities($row['ticket_status_color']);
                    $tago    = timeAgo($row['ticket_updated_at']);
                    $prio_class = match(strtolower($tprio ?? '')) {
                        'critical' => 'danger', 'high' => 'warning', 'medium' => 'info', default => 'secondary'
                    };
                ?>
                <tr>
                    <td style="width:60px" class="text-muted small text-nowrap"><?= $tnum ?></td>
                    <td>
                        <a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= $tid ?>" class="text-dark"><?= $tsubj ?></a>
                        <div class="small mt-1">
                            <span class="badge rounded-pill badge-<?= $prio_class ?>"><?= $tprio ?></span>
                            <span class="badge rounded-pill <?= tagTextClass($tscolor) ?> ms-1" style="background:<?= $tscolor ?>"><?= $tsname ?></span>
                            <span class="text-muted ms-1"><?= $tago ?></span>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="card-body py-4 text-center text-muted">
                <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>No open tickets
            </div>
            <?php endif; ?>
        </div>

        <?php if ($has_activity): ?>
        <!-- Recent Activity -->
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-history me-2"></i>Recent Activity</h5>
                <?php if ($session_user_role == 3): ?>
                <a href="../admin/audit_log.php?client=<?= $client_id ?>" class="text-muted small">Full log <i class="fas fa-chevron-right fa-xs"></i></a>
                <?php endif; ?>
            </div>
            <!-- Height is capped rather than left to however much eight rows want:
                 this card used to run ~2.9x the height of the card beside it. The
                 header "Full log" link is the single way out to the complete log -
                 the old card-footer pointed at exactly the same URL and cost 41px
                 of a row that was already ragged. -->
            <div class="js-activity-scroll" style="max-height:264px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($sql_recent_activities)):
                        $log_action_raw  = $row['log_action'];
                        $log_is_pageview = strcasecmp($log_action_raw ?? '', 'View') === 0;
                        $log_count       = intval($row['log_count']);
                        $log_created_at  = timeAgo($row['log_created_at']);
                        $log_description = nullable_htmlentities($row['log_description']);

                        /*
                         * "…for client" is MSP-era wording that OTHER pages write
                         * into the audit row itself (agent/credentials.php:22), and
                         * it never names the department it is talking about. It is
                         * rewritten here at render time only - the stored row is
                         * untouched and still reads verbatim in the full audit log.
                         * $client_name is already HTML-escaped; the callback keeps
                         * it out of preg's replacement syntax, where a literal $ or
                         * backslash in a department name would otherwise be eaten.
                         */
                        $log_description = preg_replace_callback('/\bfor client\s*$/i',
                            fn($m) => 'for ' . $client_name, $log_description);
                        $log_description = preg_replace('/\bfor client\b(?=\s+\S)/i', 'for', $log_description);

                        $log_icon = match (strtolower($log_action_raw ?? '')) {
                            'view'     => 'fa-eye',
                            'create'   => 'fa-plus',
                            'edit'     => 'fa-pen',
                            'delete'   => 'fa-trash',
                            'closed'   => 'fa-check-circle',
                            'resolved' => 'fa-check',
                            'reopened' => 'fa-undo',
                            'reply'    => 'fa-reply',
                            default    => 'fa-dot-circle',
                        };
                    ?>
                    <tr>
                        <td class="text-nowrap text-secondary small align-middle" style="width:1%"><?= $log_created_at ?></td>
                        <td class="<?= $log_is_pageview ? 'text-muted' : '' ?>">
                            <i class="fas fa-fw <?= $log_icon ?> text-secondary me-1"></i><?= $log_description ?>
                            <?php if ($log_count > 1): ?>
                                <span class="badge bg-secondary-lt ms-1" title="<?= $log_count ?> identical entries - each one is listed in the full log">&times;<?= $log_count ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-lg-4">

        <?php if ($has_key_contacts): ?>
        <!-- Key Contacts -->
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-users me-2"></i>Key Contacts</h5>
                <a href="contacts.php?client_id=<?= $client_id ?>" class="text-muted small">View all <i class="fas fa-chevron-right fa-xs"></i></a>
            </div>
            <div class="list-group list-group-flush">
                <?php while ($row = mysqli_fetch_assoc($sql_important_contacts)):
                    $contact_id       = intval($row['contact_id']);
                    $contact_name     = nullable_htmlentities($row['contact_name']);
                    $contact_title    = nullable_htmlentities($row['contact_title']);
                    $contact_photo    = nullable_htmlentities($row['contact_photo']);
                    $contact_phone    = nullable_htmlentities(formatPhoneNumber($row['contact_phone'], $row['contact_phone_country_code']));
                    $contact_mobile   = nullable_htmlentities(formatPhoneNumber($row['contact_mobile'], $row['contact_mobile_country_code']));
                    $contact_email    = nullable_htmlentities($row['contact_email']);
                    $contact_initials = initials($contact_name);

                    $badges = [];
                    if ($row['contact_primary'])   $badges[] = '<span class="badge text-bg-success">Primary</span>';
                    if ($row['contact_billing'])   $badges[] = '<span class="badge text-bg-info">Billing</span>';
                    if ($row['contact_technical']) $badges[] = '<span class="badge text-bg-secondary">Technical</span>';
                    if ($row['contact_important']) $badges[] = '<span class="badge text-bg-warning">Important</span>';
                ?>
                <a href="#" class="list-group-item list-group-item-action py-2 ajax-modal"
                    data-modal-size="lg"
                    data-modal-url="modals/contact/contact_details.php?id=<?= $contact_id ?>">
                    <div class="d-flex align-items-center">
                        <?php if ($contact_photo): ?>
                            <img src="../uploads/clients/<?= $client_id ?>/<?= $contact_photo ?>"
                                class="img-circle me-2 flex-shrink-0"
                                style="width:36px;height:36px;object-fit:cover">
                        <?php else: ?>
                            <span class="me-2 flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle bg-secondary text-white"
                                style="width:36px;height:36px;font-size:.8rem"><?= $contact_initials ?></span>
                        <?php endif; ?>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-bold text-dark"><?= $contact_name ?></div>
                            <?php if ($contact_title): ?>
                                <div class="text-muted small"><?= $contact_title ?></div>
                            <?php endif; ?>
                            <?php if ($contact_phone): ?>
                                <div class="small text-muted"><i class="fas fa-phone fa-xs me-1"></i><?= $contact_phone ?></div>
                            <?php elseif ($contact_email): ?>
                                <div class="small text-muted text-truncate"><i class="fas fa-envelope fa-xs me-1"></i><?= $contact_email ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($badges): ?>
                            <div class="ms-2 flex-shrink-0"><?= implode(' ', $badges) ?></div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Notes -->
        <div class="card card-dark mb-3">
            <div class="card-header p-2">
                <h5 class="card-title"><i class="fas fa-fw fa-edit me-2"></i>Quick Notes</h5>
            </div>
            <div class="card-body p-2">
                <!-- No bg-white / border-0 here: that hardcoded a white field
                     which stayed white under data-bs-theme="dark", putting
                     near-white text on white (~1.2:1) and making the textarea the
                     brightest object on a dark page. Plain .form-control takes the
                     themed surface in both modes. rows="3" because this field is
                     empty on nearly every department and has no business setting
                     the height of a whole row; resize:vertical keeps it growable
                     for a heavy note-taker. -->
                <textarea class="form-control js-update-client-notes" rows="3" id="clientNotes"
                    style="resize:vertical"
                    placeholder="Type notes here…"
                    data-client-id="<?= $client_id ?>"><?= $client_notes ?></textarea>
            </div>
        </div>

        <?php if ($has_locations): ?>
        <!-- Locations -->
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-map-marker-alt me-2"></i>Locations</h5>
                <a href="locations.php?client_id=<?= $client_id ?>" class="text-muted small">View all <i class="fas fa-chevron-right fa-xs"></i></a>
            </div>
            <!-- Sites used to be outline BUTTONS on a full-width row, and clicking
                 one opened the site EDIT form - a destructive-capable action dressed
                 as navigation. Now a flush list like Key Contacts: the row itself
                 goes to the site list, and editing is its own explicit pencil. -->
            <div class="list-group list-group-flush">
                <?php while ($loc_row = mysqli_fetch_assoc($sql_client_locations)):
                    $loc_id    = intval($loc_row['location_id']);
                    $loc_name  = nullable_htmlentities($loc_row['location_name']);
                    $loc_place = trim(($loc_row['location_city'] ?: '') . (($loc_row['location_city'] && $loc_row['location_state']) ? ', ' : '') . ($loc_row['location_state'] ?: ''));
                ?>
                <div class="list-group-item py-2 d-flex align-items-center">
                    <i class="fas fa-fw fa-map-marker-alt text-secondary me-2"></i>
                    <a href="locations.php?client_id=<?= $client_id ?>" class="flex-grow-1 text-truncate text-reset text-decoration-none"><?= $loc_name ?><?php if ($loc_place !== ''): ?><span class="text-muted small ms-2"><?= nullable_htmlentities($loc_place) ?></span><?php endif; ?></a>
                    <a href="#" class="btn btn-sm btn-icon text-muted ms-2 ajax-modal" title="Edit this site"
                        data-modal-url="modals/location/location_edit.php?id=<?= $loc_id ?>"><i class="fas fa-fw fa-pen fa-xs"></i></a>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>

<!-- ── Favorites ────────────────────────────────────────────────────────── -->
<?php
$has_fav_assets = mysqli_num_rows($sql_favorite_assets) > 0;
$has_fav_creds  = mysqli_num_rows($sql_favorite_credentials) > 0 && lookupUserPermission('module_credential');
if ($has_fav_assets || $has_fav_creds):
?>
<div class="row">

    <?php if ($has_fav_assets): ?>
    <div class="col-md-<?= $has_fav_creds ? '6' : '12' ?>">
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-star text-warning me-2"></i>Favorite Assets</h5>
                <a href="assets.php?client_id=<?= $client_id ?>" class="text-muted small">View all <i class="fas fa-chevron-right fa-xs"></i></a>
            </div>
            <table class="table table-sm table-hover mb-0">
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_favorite_assets)):
                    $asset_id    = intval($row['asset_id']);
                    $asset_name  = nullable_htmlentities($row['asset_name']);
                    $asset_type  = nullable_htmlentities($row['asset_type']);
                    $asset_icon  = getAssetIcon($asset_type);
                    $asset_make  = nullable_htmlentities($row['asset_make']);
                    $asset_model = nullable_htmlentities($row['asset_model']);
                ?>
                <tr>
                    <td>
                        <a href="#" class="ajax-modal"
                            data-modal-size="lg"
                            data-modal-url="modals/asset/asset_details.php?id=<?= $asset_id ?>">
                            <i class="fas fa-fw fa-<?= $asset_icon ?> text-dark me-1"></i><?= $asset_name ?>
                        </a>
                    </td>
                    <td class="text-muted"><?= trim("$asset_make $asset_model") ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_fav_creds): ?>
    <div class="col-md-<?= $has_fav_assets ? '6' : '12' ?>">
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-star text-warning me-2"></i>Favorite Credentials</h5>
                <a href="credentials.php?client_id=<?= $client_id ?>" class="text-muted small">View all <i class="fas fa-chevron-right fa-xs"></i></a>
            </div>
            <table class="table table-sm table-hover mb-0">
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_favorite_credentials)):
                    $credential_id         = intval($row['credential_id']);
                    $credential_name       = nullable_htmlentities($row['credential_name']);
                    $credential_description= nullable_htmlentities($row['credential_description']);
                    $credential_uri        = sanitize_url($row['credential_uri']);
                    $credential_username_raw = decryptCredentialEntry($row['credential_username']);
                    $credential_password_raw = decryptCredentialEntry($row['credential_password']);
                    $vault_locked           = ($credential_username_raw === null || $credential_password_raw === null);
                    $credential_username   = $vault_locked ? '' : nullable_htmlentities($credential_username_raw);
                    $credential_password   = $vault_locked ? '' : nullable_htmlentities($credential_password_raw);
                    $credential_otp_secret = nullable_htmlentities($row['credential_otp_secret']);

                    $username_display = $credential_username
                        ? "$credential_username<button class='btn btn-sm clipboardjs p-1' type='button' data-clipboard-text='$credential_username'><i class='far fa-copy text-secondary'></i></button>"
                        : '-';
                    $otp_display = $credential_otp_secret
                        ? "<small class='text-secondary'><span onmouseenter='showOTPViaCredentialID($credential_id)'><i class='far fa-clock text-dark'></i> <span id='otp_$credential_id'><i>Hover…</i></span></span></small>"
                        : '';
                ?>
                <tr>
                    <td>
                        <a href="#" class="ajax-modal"
                            data-modal-url="modals/credential/credential_view.php?id=<?= $credential_id ?>">
                            <i class="fas fa-fw fa-key text-dark me-1"></i><?= $credential_name ?>
                        </a>
                    </td>
                    <?php if ($vault_locked): ?>
                    <td colspan="2">
                        <span class="text-muted small" title="Your credential vault session has expired. Sign out and back in to view or copy this password.">
                            <i class="fas fa-lock"></i> Locked - sign in again to view
                        </span>
                    </td>
                    <?php else: ?>
                    <td><?= $username_display ?></td>
                    <td class="text-nowrap">
                        <button class="btn p-0" type="button"
                            data-bs-toggle="popover" data-trigger="focus" data-placement="top"
                            data-content="<?= $credential_password ?>">
                            <i class="fas fa-2x fa-ellipsis-h text-secondary"></i><i class="fas fa-2x fa-ellipsis-h text-secondary"></i>
                        </button>
                        <button class="btn btn-sm clipboardjs" type="button" data-clipboard-text="<?= $credential_password ?>">
                            <i class="far fa-copy text-secondary"></i>
                        </button>
                        <div><?= $otp_display ?></div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ── Needs attention (stale / expiring / expired) ───────────────────────── -->
<?php
// Counted once at the top of this file; the "Expiring 45d" tile in the
// at-a-glance strip links down here whenever any of the three renders.
$has_stale    = $num_stale_tickets > 0;
$has_expiring = $num_expiring_45 > 0;
$has_expired  = $num_expired_items > 0;

if ($has_stale || $has_expiring || $has_expired):
    // Column width follows how many of the 3 alert cards actually render,
    // so a lone card fills the row instead of leaving a dead gap beside it.
    $alert_card_count = ($has_stale ? 1 : 0) + ($has_expiring ? 1 : 0) + ($has_expired ? 1 : 0);
    $alert_col_class = $alert_card_count === 1 ? 'col-md-12' : ($alert_card_count === 2 ? 'col-md-6' : 'col-md-4');
?>
<div class="row" id="dept-attention">

    <?php if ($has_stale): ?>
    <div class="<?= $alert_col_class ?>">
        <div class="card mb-3" style="border-left:4px solid #ffc107">
            <div class="card-header p-2">
                <h5 class="card-title text-warning mb-0"><i class="fas fa-fw fa-clock me-2"></i>Stale Tickets <small class="text-muted fw-normal">(7+ days)</small></h5>
            </div>
            <div class="list-group list-group-flush">
                <?php while ($row = mysqli_fetch_assoc($sql_stale_tickets)):
                    $tid   = intval($row['ticket_id']);
                    $tnum  = nullable_htmlentities($row['ticket_prefix'] . $row['ticket_number']);
                    $tsubj = nullable_htmlentities($row['ticket_subject']);
                    $tago  = timeAgo($row['ticket_updated_at']);
                ?>
                <a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= $tid ?>"
                    class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small me-2 text-nowrap"><?= $tnum ?></span>
                        <span class="flex-grow-1 text-dark text-truncate"><?= $tsubj ?></span>
                        <span class="text-muted small ms-2 text-nowrap"><?= $tago ?></span>
                    </div>
                </a>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_expiring): ?>
    <div class="<?= $alert_col_class ?>">
        <div class="card mb-3" style="border-left:4px solid #fd7e14">
            <div class="card-header p-2">
                <h5 class="card-title mb-0" style="color:#fd7e14"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Expiring (45 days)</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php
                while ($row = mysqli_fetch_assoc($sql_domains_expiring)) {
                    $name = nullable_htmlentities($row['domain_name']);
                    $date = nullable_htmlentities($row['domain_expire']);
                    $ago  = timeAgo($row['domain_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-globe text-muted me-1'></i><a href='domains.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-warning ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Domain &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_certificates_expiring)) {
                    $name = nullable_htmlentities($row['certificate_name']);
                    $date = nullable_htmlentities($row['certificate_expire']);
                    $ago  = timeAgo($row['certificate_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-lock text-muted me-1'></i><a href='certificates.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-warning ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Certificate &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_asset_warranties_expiring)) {
                    $aid  = intval($row['asset_id']);
                    $name = nullable_htmlentities($row['asset_name']);
                    $date = nullable_htmlentities($row['asset_warranty_expire']);
                    $ago  = timeAgo($row['asset_warranty_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-laptop text-muted me-1'></i><a href='asset_details.php?client_id=$client_id&asset_id=$aid'>$name</a></span><small class='text-warning ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Warranty &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_asset_retire)) {
                    $aid         = intval($row['asset_id']);
                    $name        = nullable_htmlentities($row['asset_name']);
                    $install     = $row['asset_install_date'];
                    $retire_date = date('Y-m-d', strtotime($install . ' + 7 years'));
                    $ago         = timeAgo($retire_date);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-desktop text-muted me-1'></i><a href='asset_details.php?client_id=$client_id&asset_id=$aid'>$name</a></span><small class='text-warning ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Retiring &middot; $retire_date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_licenses_expiring)) {
                    $name = nullable_htmlentities($row['software_name']);
                    $date = nullable_htmlentities($row['software_expire']);
                    $ago  = timeAgo($row['software_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-cube text-muted me-1'></i><a href='software.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-warning ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>License &middot; $date</div></div>";
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_expired): ?>
    <div class="<?= $alert_col_class ?>">
        <div class="card mb-3" style="border-left:4px solid #dc3545">
            <div class="card-header p-2">
                <h5 class="card-title text-danger mb-0"><i class="fas fa-fw fa-times-circle me-2"></i>Expired</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php
                while ($row = mysqli_fetch_assoc($sql_domains_expired)) {
                    $name = nullable_htmlentities($row['domain_name']);
                    $date = nullable_htmlentities($row['domain_expire']);
                    $ago  = timeAgo($row['domain_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-globe text-muted me-1'></i><a href='domains.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-danger ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Domain &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_certificates_expired)) {
                    $name = nullable_htmlentities($row['certificate_name']);
                    $date = nullable_htmlentities($row['certificate_expire']);
                    $ago  = timeAgo($row['certificate_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-lock text-muted me-1'></i><a href='certificates.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-danger ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Certificate &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_asset_warranties_expired)) {
                    $aid  = intval($row['asset_id']);
                    $name = nullable_htmlentities($row['asset_name']);
                    $date = nullable_htmlentities($row['asset_warranty_expire']);
                    $ago  = timeAgo($row['asset_warranty_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-laptop text-muted me-1'></i><a href='asset_details.php?client_id=$client_id&asset_id=$aid'>$name</a></span><small class='text-danger ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Warranty &middot; $date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_asset_retired)) {
                    $aid         = intval($row['asset_id']);
                    $name        = nullable_htmlentities($row['asset_name']);
                    $install     = $row['asset_install_date'];
                    $retire_date = date('Y-m-d', strtotime($install . ' + 7 years'));
                    $ago         = timeAgo($retire_date);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-desktop text-muted me-1'></i><a href='asset_details.php?client_id=$client_id&asset_id=$aid'>$name</a></span><small class='text-danger ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>Retired &middot; $retire_date</div></div>";
                }
                while ($row = mysqli_fetch_assoc($sql_licenses_expired)) {
                    $name = nullable_htmlentities($row['software_name']);
                    $date = nullable_htmlentities($row['software_expire']);
                    $ago  = timeAgo($row['software_expire']);
                    echo "<div class='list-group-item py-2'><div class='d-flex justify-content-between'><span><i class='fas fa-fw fa-cube text-muted me-1'></i><a href='software.php?client_id=$client_id&q=$name'>$name</a></span><small class='text-danger ms-2 text-nowrap'>$ago</small></div><div class='text-muted small'>License &middot; $date</div></div>";
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ── Opportunities ────────────────────────────────────────────────────────
     Still gated exactly as it was on module_sales - whether this edition runs a
     sales module at all is the owner's call, not this page's. What changed is
     volume and position: it no longer opens the page above the ticket list, it
     no longer shouts a currency total in a green badge on an internal-IT
     department overview, its "Add" button is a normal outline control instead of
     the largest saturated element anywhere on the page, and, like every other
     optional card here, it renders only when it has something to show. -->
<?php if ($config_module_enable_crm == 1 && lookupUserPermission('module_sales') >= 1):
    $sql_client_opps = mysqli_query($mysqli,
        "SELECT * FROM opportunities
         WHERE opportunity_client_id = $client_id
           AND opportunity_status = 'open'
           AND opportunity_archived_at IS NULL
         ORDER BY opportunity_amount DESC LIMIT 8");
    $client_opp_count = $sql_client_opps ? mysqli_num_rows($sql_client_opps) : 0;
    /* The CARD renders whenever module_sales is granted, not only when a row exists.
       Gating the whole card on a row count removed the only "Add Opportunity" control
       and the "View all" link from exactly the department where you would add the
       first one - a department with none. The other cards made conditional on this
       page (Key Contacts, Locations, Favorites) carry no create affordance, so that
       precedent does not extend here. Only the table body is conditional; with no
       rows the card shows its empty state and keeps both actions. */
?>
<div class="row">
    <div class="col-12">
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-funnel-dollar me-2"></i>Open Opportunities</h5>
                <div>
                    <a href="opportunities.php?client_id=<?= $client_id ?>" class="text-muted small me-2">View all <i class="fas fa-chevron-right fa-xs"></i></a>
                    <?php if (lookupUserPermission('module_sales') >= 2): ?>
                        <button type="button" class="btn btn-outline-secondary btn-xs ajax-modal" data-modal-url="modals/opportunity/opportunity_add.php?client_id=<?= $client_id ?>"><i class="fas fa-plus me-1"></i>Add Opportunity</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($client_opp_count === 0): ?>
                    <p class="text-muted text-center my-3 mb-0">No open opportunities for this department.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php while ($opp_row = mysqli_fetch_assoc($sql_client_opps)):
                        $o_id = intval($opp_row['opportunity_id']);
                        $o_name = nullable_htmlentities($opp_row['opportunity_name']);
                        $o_stage = nullable_htmlentities($opp_row['opportunity_stage']);
                        $o_color = opportunityStageColor($opp_row['opportunity_stage']);
                        $o_amount = floatval($opp_row['opportunity_amount']);
                        $o_prob = intval($opp_row['opportunity_probability']);
                        $o_close = nullable_htmlentities($opp_row['opportunity_close_date']);
                    ?>
                        <tr>
                            <td class="ps-3">
                                <a href="#" class="text-dark ajax-modal" data-modal-url="modals/opportunity/opportunity_edit.php?id=<?= $o_id ?>"><?= $o_name ?></a>
                            </td>
                            <td><span class="badge badge-<?= $o_color ?>"><?= $o_stage ?></span></td>
                            <td class="text-muted small"><?= $o_prob ?>%</td>
                            <td class="text-end pe-3 fw-bold"><?= numfmt_format_currency($currency_format, $o_amount, "$session_company_currency") ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Shared Items ──────────────────────────────────────────────────────── -->
<?php if (mysqli_num_rows($sql_shared_items) > 0): ?>
<div class="row">
    <div class="col-md-12">
        <div class="card mb-3">
            <div class="card-header p-2">
                <h5 class="card-title"><i class="fa fa-fw fa-share-square me-2"></i>Shared Items</h5>
            </div>
            <div class="card-body p-2">
                <table class="table table-borderless table-sm">
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($sql_shared_items)):
                        $item_id         = intval($row['item_id']);
                        $item_type       = nullable_htmlentities($row['item_type']);
                        $item_related_id = intval($row['item_related_id']);
                        $item_recipient  = nullable_htmlentities($row['item_recipient']);
                        $item_views      = nullable_htmlentities($row['item_views']);
                        $item_expire_at  = nullable_htmlentities($row['item_expire_at']);
                        $item_expire_at_human = timeAgo($row['item_expire_at']);

                        if ($item_type == 'Credential') {
                            $r2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_name FROM credentials WHERE credential_id = $item_related_id AND credential_client_id = $client_id"));
                            $item_name = nullable_htmlentities($r2['credential_name'] ?? '');
                            $item_icon = "fas fa-key";
                        } elseif ($item_type == 'Document') {
                            $r2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_name FROM documents WHERE document_id = $item_related_id AND document_client_id = $client_id"));
                            $item_name = nullable_htmlentities($r2['document_name'] ?? '');
                            $item_icon = "fas fa-folder";
                        } else {
                            $r2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT file_name FROM files WHERE file_id = $item_related_id AND file_client_id = $client_id"));
                            $item_name = nullable_htmlentities($r2['file_name'] ?? '');
                            $item_icon = "fas fa-paperclip";
                        }
                    ?>
                    <tr>
                        <td title="<?= $item_type ?>"><i class="<?= $item_icon ?> me-2 text-secondary"></i><?= $item_name ?></td>
                        <td>
                            <div>Views: <?= $item_views ?></div>
                            <div class="text-secondary"><?= $item_recipient ?></div>
                        </td>
                        <td title="Expires at <?= $item_expire_at ?>">Expires <?= $item_expire_at_human ?></td>
                        <td title="Deactivate Link">
                            <a class="text-danger confirm-link" href="post.php?deactivate_shared_item=<?= $item_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                <i class="fas fa-fw fa-calendar-times me-2"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Comet Backup ──────────────────────────────────────────────────────── -->
<?php
// comet_get_users()/comet_get_jobs_for_user() (via comet_last_jobs_per_device())
// each make a blocking curl_exec() to an external Comet Backup server - up to
// 15s timeout apiece, so up to ~30s worst case for this card alone if Comet is
// ever slow/unreachable. That used to run inline in this page's render path,
// meaning the WHOLE page waited on a third-party server's health. Now it's a
// placeholder + async fetch (see js/comet_client_status.js) so client_overview.php
// itself never blocks on it - only the card's own contents are delayed.
if (!empty($config_comet_enabled)) {
    $comet_map = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT map_comet_username FROM comet_client_map WHERE map_client_id = $client_id LIMIT 1"
    ));
    if ($comet_map) {
        $c_username = $comet_map['map_comet_username'];
?>
<div class="card card-dark mt-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto">
            <i class="fas fa-fw fa-cloud-upload-alt me-2"></i>Comet Backup
            <small class="text-muted ms-2"><?= htmlspecialchars($c_username) ?></small>
        </h3>
        <a href="/admin/comet_status.php" class="btn btn-xs btn-outline-secondary">
            <i class="fas fa-expand-alt me-1"></i>Full View
        </a>
    </div>
    <div class="card-body p-0" id="cometBackupCardBody" data-client-id="<?= $client_id ?>">
        <p class="text-muted text-center py-3 mb-0"><i class="fas fa-spinner fa-spin me-2"></i>Loading backup status…</p>
    </div>
</div>
<script src="js/comet_client_status.js"></script>
<?php
    }
}
?>

<script src="js/credential_show_otp_via_id.js"></script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function updateClientNotes(client_id) {
    var notes = document.getElementById("clientNotes").value;
    jQuery.post("ajax.php", {
        client_set_notes: 'TRUE',
        csrf_token: '<?= $_SESSION['csrf_token'] ?>',
        client_id: client_id,
        notes: notes
    });
}
document.addEventListener('blur', function (e) {
    var el = e.target.closest && e.target.closest('.js-update-client-notes');
    if (el) { updateClientNotes(el.dataset.clientId); }
}, true);
</script>

<?php require_once "../includes/footer.php"; ?>
