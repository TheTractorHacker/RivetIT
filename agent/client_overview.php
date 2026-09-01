<?php

require_once "includes/inc_all_client.php";

// ── Queries ───────────────────────────────────────────────────────────────────

$sql_important_contacts = mysqli_query($mysqli,
    "SELECT * FROM contacts
     WHERE contact_client_id = $client_id
       AND (contact_important = 1 OR contact_billing = 1 OR contact_technical = 1 OR contact_primary = 1)
       AND contact_archived_at IS NULL
     ORDER BY contact_primary DESC, contact_name DESC LIMIT 5");

$sql_favorite_assets = mysqli_query($mysqli,
    "SELECT * FROM assets
     WHERE asset_client_id = $client_id AND asset_favorite = 1 AND asset_archived_at IS NULL
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
     WHERE ticket_client_id = $client_id AND ticket_archived_at IS NULL AND ticket_closed_at IS NULL AND ticket_status != 4
     ORDER BY ticket_updated_at DESC LIMIT 6");

$sql_recent_activities = mysqli_query($mysqli,
    "SELECT * FROM logs WHERE log_client_id = $client_id ORDER BY log_created_at DESC LIMIT 8");

$sql_shared_items = mysqli_query($mysqli,
    "SELECT * FROM shared_items WHERE item_client_id = $client_id AND item_active = 1 ORDER BY item_created_at ASC LIMIT 5");

// Stale Tickets (no activity in 7+ days)
$sql_stale_tickets = mysqli_query($mysqli,
    "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_updated_at FROM tickets
     WHERE ticket_client_id = $client_id AND ticket_updated_at < CURRENT_DATE - INTERVAL 7 DAY
       AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
     ORDER BY ticket_updated_at ASC");

// Expiring (45 day window)
$sql_domains_expiring            = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_client_id=$client_id AND domain_expire IS NOT NULL AND domain_archived_at IS NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY domain_expire ASC");
$sql_certificates_expiring       = mysqli_query($mysqli, "SELECT * FROM certificates WHERE certificate_client_id=$client_id AND certificate_expire IS NOT NULL AND certificate_archived_at IS NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY certificate_expire ASC");
$sql_licenses_expiring           = mysqli_query($mysqli, "SELECT * FROM software WHERE software_client_id=$client_id AND software_expire IS NOT NULL AND software_archived_at IS NULL AND software_expire > CURRENT_DATE AND software_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY software_expire ASC");
$sql_asset_warranties_expiring   = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id AND asset_warranty_expire IS NOT NULL AND asset_archived_at IS NULL AND asset_warranty_expire > CURRENT_DATE AND asset_warranty_expire < CURRENT_DATE + INTERVAL 45 DAY ORDER BY asset_warranty_expire ASC");
$sql_asset_retire                = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id AND asset_install_date IS NOT NULL AND asset_archived_at IS NULL AND asset_install_date + INTERVAL 7 YEAR > CURRENT_DATE AND asset_install_date + INTERVAL 7 YEAR <= CURRENT_DATE + INTERVAL 45 DAY ORDER BY asset_install_date ASC");

// Expired
$sql_domains_expired             = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_client_id=$client_id AND domain_expire IS NOT NULL AND domain_archived_at IS NULL AND domain_expire < CURRENT_DATE ORDER BY domain_expire ASC");
$sql_certificates_expired        = mysqli_query($mysqli, "SELECT * FROM certificates WHERE certificate_client_id=$client_id AND certificate_expire IS NOT NULL AND certificate_archived_at IS NULL AND certificate_expire < CURRENT_DATE ORDER BY certificate_expire ASC");
$sql_licenses_expired            = mysqli_query($mysqli, "SELECT * FROM software WHERE software_client_id=$client_id AND software_expire IS NOT NULL AND software_archived_at IS NULL AND software_expire < CURRENT_DATE ORDER BY software_expire ASC");
$sql_asset_warranties_expired    = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id AND asset_warranty_expire IS NOT NULL AND asset_archived_at IS NULL AND asset_warranty_expire < CURRENT_DATE ORDER BY asset_warranty_expire ASC");
$sql_asset_retired               = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_client_id=$client_id AND asset_install_date IS NOT NULL AND asset_archived_at IS NULL AND asset_install_date + INTERVAL 7 YEAR < CURRENT_DATE ORDER BY asset_install_date ASC");

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
                <span class="text-muted small fw-bold"><i class="fas fa-house-user me-1"></i>Included Support Issues</span>
                <?php foreach (['remote' => ['icon' => 'fa-laptop', 'label' => 'Remote'], 'onsite' => ['icon' => 'fa-house-user', 'label' => 'Onsite']] as $key => $meta):
                    $u = $client_issues_usage[$key];
                    if ($u['included'] === null) continue;
                ?>
                <span class="d-flex align-items-center" style="gap:6px;border-left:4px solid <?= $u['pct'] !== null && $u['pct'] >= 100 ? '#dc3545' : (($u['pct'] ?? 0) >= 80 ? '#ffc107' : '#28a745') ?>;padding-left:8px">
                    <i class="fas fa-fw <?= $meta['icon'] ?>"></i>
                    <span><?= $meta['label'] ?>: <?= $u['used'] ?> / <?= $u['included'] ?> used this month</span>
                    <?php if ($u['remaining'] !== null && $u['remaining'] < 0): ?>
                        <span class="badge text-bg-danger"><?= abs($u['remaining']) ?> over</span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── CRM Opportunities widget ──────────────────────────────────────────── -->
<?php if (lookupUserPermission('module_sales') >= 1):
    $sql_client_opps = mysqli_query($mysqli,
        "SELECT * FROM opportunities
         WHERE opportunity_client_id = $client_id
           AND opportunity_status = 'open'
           AND opportunity_archived_at IS NULL
         ORDER BY opportunity_amount DESC LIMIT 8");
    $client_opps_total = floatval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COALESCE(SUM(opportunity_amount),0) AS t FROM opportunities
         WHERE opportunity_client_id = $client_id AND opportunity_status = 'open' AND opportunity_archived_at IS NULL"))['t']);
?>
<div class="row">
    <div class="col-12">
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-funnel-dollar me-2"></i>Open Opportunities
                    <span class="badge text-bg-success ms-1"><?= numfmt_format_currency($currency_format, $client_opps_total, "$session_company_currency") ?></span>
                </h5>
                <div>
                    <a href="opportunities.php?client_id=<?= $client_id ?>" class="text-muted small me-2">View all <i class="fas fa-chevron-right fa-xs"></i></a>
                    <?php if (lookupUserPermission('module_sales') >= 2): ?>
                        <button type="button" class="btn btn-primary btn-xs ajax-modal" data-modal-url="modals/opportunity/opportunity_add.php?client_id=<?= $client_id ?>"><i class="fas fa-plus me-1"></i>Add Opportunity</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (mysqli_num_rows($sql_client_opps) > 0): ?>
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
                <?php else: ?>
                    <div class="p-3 text-muted text-center">No open opportunities for this client.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Row 1: Quick Notes + Key Contacts ────────────────────────────────── -->
<div class="row">

    <!-- Quick Notes -->
    <div class="col-md-8">
        <div class="card card-dark mb-3">
            <div class="card-header p-2">
                <h5 class="card-title"><i class="fas fa-fw fa-edit me-2"></i>Quick Notes</h5>
            </div>
            <div class="card-body p-2">
                <textarea class="form-control border-0 bg-white js-update-client-notes" rows="7" id="clientNotes"
                    placeholder="Type notes here…"
                    data-client-id="<?= $client_id ?>"><?= $client_notes ?></textarea>
            </div>
        </div>
    </div>

    <!-- Key Contacts -->
    <?php if (mysqli_num_rows($sql_important_contacts) > 0): ?>
    <div class="col-md-4">
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
    </div>
    <?php endif; ?>

</div>

<!-- ── Row 2: Favorites ──────────────────────────────────────────────────── -->
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

<!-- ── Row 3: Open Tickets + Recent Activity ─────────────────────────────── -->
<div class="row">

    <!-- Open Tickets -->
    <div class="col-md-6">
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
    </div>

    <!-- Recent Activity -->
    <?php if (mysqli_num_rows($sql_recent_activities) > 0): ?>
    <div class="col-md-6">
        <div class="card card-dark mb-3">
            <div class="card-header p-2 d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0"><i class="fas fa-fw fa-history me-2"></i>Recent Activity</h5>
                <?php if ($session_user_role == 3): ?>
                <a href="../admin/audit_log.php?client=<?= $client_id ?>" class="text-muted small">Full log <i class="fas fa-chevron-right fa-xs"></i></a>
                <?php endif; ?>
            </div>
            <table class="table table-sm table-hover mb-0">
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_recent_activities)):
                    $log_description = nullable_htmlentities($row['log_description']);
                    $log_created_at  = timeAgo($row['log_created_at']);
                ?>
                <tr>
                    <td class="text-nowrap text-secondary small"><?= $log_created_at ?></td>
                    <td><?= $log_description ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php if ($session_user_role == 3): ?>
            <div class="card-footer p-2">
                <a href="../admin/audit_log.php?client=<?= $client_id ?>">See More…</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Row 4: Alert cards (stale / expiring / expired) ───────────────────── -->
<?php
$has_stale    = mysqli_num_rows($sql_stale_tickets) > 0;
$has_expiring = mysqli_num_rows($sql_domains_expiring) > 0 || mysqli_num_rows($sql_certificates_expiring) > 0
             || mysqli_num_rows($sql_licenses_expiring) > 0 || mysqli_num_rows($sql_asset_warranties_expiring) > 0
             || mysqli_num_rows($sql_asset_retire) > 0;
$has_expired  = mysqli_num_rows($sql_domains_expired) > 0 || mysqli_num_rows($sql_certificates_expired) > 0
             || mysqli_num_rows($sql_licenses_expired) > 0 || mysqli_num_rows($sql_asset_warranties_expired) > 0
             || mysqli_num_rows($sql_asset_retired) > 0;

if ($has_stale || $has_expiring || $has_expired):
    // Column width follows how many of the 3 alert cards actually render,
    // so a lone card fills the row instead of leaving a dead gap beside it.
    $alert_card_count = ($has_stale ? 1 : 0) + ($has_expiring ? 1 : 0) + ($has_expired ? 1 : 0);
    $alert_col_class = $alert_card_count === 1 ? 'col-md-12' : ($alert_card_count === 2 ? 'col-md-6' : 'col-md-4');
?>
<div class="row">

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
