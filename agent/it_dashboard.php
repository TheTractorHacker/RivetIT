<?php
require_once "includes/inc_all.php";

$page_title = "Internal IT Dashboard";

// Optional site-level rollup (Section 40.3): ?location_id=X scopes every metric
// below to one location. Domains/certificates have no location column of their
// own, so for those two tables the scope falls back to the location's client.
$location_id = intval($_GET['location_id'] ?? 0);
$location = null;
if ($location_id) {
    $location = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT l.location_id, l.location_name, l.location_client_id, cl.client_name
        FROM locations l LEFT JOIN clients cl ON cl.client_id = l.location_client_id
        WHERE l.location_id = $location_id"));
    if (!$location) {
        flash_alert('Location not found.', 'error');
        redirect('it_dashboard.php');
    }
    enforceClientAccess(intval($location['location_client_id']));
}

$scoped = ($client_access_string && !$session_is_admin);
$scope_tickets    = $scoped ? "AND ticket_client_id IN ($client_access_string)" : '';
$scope_contacts   = $scoped ? "AND contact_client_id IN ($client_access_string)" : '';
$scope_assets     = $scoped ? "AND asset_client_id IN ($client_access_string)" : '';
$scope_domains    = $scoped ? "AND domain_client_id IN ($client_access_string)" : '';
$scope_certs      = $scoped ? "AND certificate_client_id IN ($client_access_string)" : '';
$scope_wf_contact = $scoped ? "AND c.contact_client_id IN ($client_access_string)" : '';
$scope_locations  = $scoped ? "AND l.location_client_id IN ($client_access_string)" : '';

$loc_tickets  = $location_id ? "AND ticket_location_id = $location_id" : '';
$loc_contacts = $location_id ? "AND contact_location_id = $location_id" : '';
$loc_assets   = $location_id ? "AND asset_location_id = $location_id" : '';
$loc_wf       = $location_id ? "AND c.contact_location_id = $location_id" : '';
// No location column on domains/certificates - fall back to the location's client.
$loc_domains  = $location_id ? "AND domain_client_id = " . intval($location['location_client_id']) : '';
$loc_certs    = $location_id ? "AND certificate_client_id = " . intval($location['location_client_id']) : '';

// Ticket counts
$dash_open_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL $scope_tickets $loc_tickets"))[0]);
$dash_critical_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_priority = 'Critical' AND ticket_archived_at IS NULL $scope_tickets $loc_tickets"))[0]);
$dash_unassigned_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_archived_at IS NULL AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0) $scope_tickets $loc_tickets"))[0]);

// Active people
$dash_active_people = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM contacts WHERE contact_employment_status = 'active' AND contact_archived_at IS NULL $scope_contacts $loc_contacts"))[0]);

// Onboarding / offboarding workflow runs (Phase 9 - already live)
$dash_onboarding_in_progress = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM workflow_runs wr INNER JOIN contacts c ON c.contact_id = wr.contact_id
     WHERE wr.type = 'onboarding' AND wr.status = 'in_progress' $scope_wf_contact $loc_wf"))[0]);
$dash_offboarding_in_progress = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM workflow_runs wr INNER JOIN contacts c ON c.contact_id = wr.contact_id
     WHERE wr.type = 'offboarding' AND wr.status = 'in_progress' $scope_wf_contact $loc_wf"))[0]);

$sql_wf_in_progress = mysqli_query($mysqli,
    "SELECT wr.run_id, wr.type, wr.started_at, c.contact_name, c.contact_client_id, cl.client_name
     FROM workflow_runs wr
     INNER JOIN contacts c ON c.contact_id = wr.contact_id
     LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
     WHERE wr.status = 'in_progress' $scope_wf_contact $loc_wf
     ORDER BY wr.started_at ASC
     LIMIT 25");

// Assets by status
$sql_assets_by_status = mysqli_query($mysqli,
    "SELECT COALESCE(asset_status, 'Unknown') AS status_name, COUNT(*) AS c
     FROM assets WHERE asset_archived_at IS NULL $scope_assets $loc_assets
     GROUP BY status_name ORDER BY c DESC");
$dash_total_assets = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM assets WHERE asset_archived_at IS NULL $scope_assets $loc_assets"))[0]);

// Domains / certificates expiring - same 30-day query pattern as the main dashboard,
// repeated at 60/90 day thresholds.
$dash_domains_30 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 30 DAY AND domain_archived_at IS NULL $scope_domains $loc_domains"))[0]);
$dash_domains_60 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 60 DAY AND domain_archived_at IS NULL $scope_domains $loc_domains"))[0]);
$dash_domains_90 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 90 DAY AND domain_archived_at IS NULL $scope_domains $loc_domains"))[0]);

$dash_certs_30 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 30 DAY AND certificate_archived_at IS NULL $scope_certs $loc_certs"))[0]);
$dash_certs_60 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 60 DAY AND certificate_archived_at IS NULL $scope_certs $loc_certs"))[0]);
$dash_certs_90 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 90 DAY AND certificate_archived_at IS NULL $scope_certs $loc_certs"))[0]);

$sql_expiring_items = mysqli_query($mysqli,
    "SELECT d.domain_name AS item_name, 'Domain' AS item_type, d.domain_expire AS item_expire, cl.client_name AS item_client_name, cl.client_id AS item_client_id
     FROM domains d LEFT JOIN clients cl ON cl.client_id = d.domain_client_id
     WHERE d.domain_expire IS NOT NULL AND d.domain_expire > CURRENT_DATE AND d.domain_expire < CURRENT_DATE + INTERVAL 90 DAY AND d.domain_archived_at IS NULL $scope_domains $loc_domains
     UNION ALL
     SELECT ce.certificate_name AS item_name, 'Certificate' AS item_type, ce.certificate_expire AS item_expire, cl2.client_name AS item_client_name, cl2.client_id AS item_client_id
     FROM certificates ce LEFT JOIN clients cl2 ON cl2.client_id = ce.certificate_client_id
     WHERE ce.certificate_expire IS NOT NULL AND ce.certificate_expire > CURRENT_DATE AND ce.certificate_expire < CURRENT_DATE + INTERVAL 90 DAY AND ce.certificate_archived_at IS NULL $scope_certs $loc_certs
     ORDER BY item_expire ASC
     LIMIT 25");

// Location picker (scoped to accessible, non-archived clients)
$sql_locations = mysqli_query($mysqli,
    "SELECT l.location_id, l.location_name, cl.client_name
     FROM locations l
     LEFT JOIN clients cl ON cl.client_id = l.location_client_id
     WHERE l.location_archived_at IS NULL AND (cl.client_archived_at IS NULL OR cl.client_id IS NULL) $scope_locations
     ORDER BY cl.client_name ASC, l.location_name ASC");
?>

<style>
  /* Mirrors the tinted small-box + "needs attention" strip look from the main
     agent/dashboard.php, so this page reads as a sibling rather than a
     different visual style. */
  .small-box.bg-primary, .small-box.text-bg-primary,
  .small-box.bg-info, .small-box.text-bg-info,
  .small-box.bg-success, .small-box.text-bg-success,
  .small-box.bg-warning, .small-box.text-bg-warning,
  .small-box.bg-danger, .small-box.text-bg-danger,
  .small-box.bg-secondary, .small-box.text-bg-secondary {
    color: #0b0b0b !important;
    border: 1px solid #e6e5e0;
    border-left-width: 4px;
    box-shadow: none;
    transition: box-shadow .15s ease, border-color .15s ease;
    overflow: visible;
  }
  .small-box:hover { box-shadow: 0 3px 10px rgba(0,0,0,.08); }
  .small-box > .inner { padding: 14px 16px; }
  .small-box .inner h3 { font-size: 1.9rem; margin-bottom: 2px; }
  .small-box .inner p { color: #52514e; opacity: 1; font-weight: 500; font-size: .9rem; margin-bottom: 0; }
  .small-box .icon {
    position: absolute; top: 14px; right: 14px;
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    opacity: 1;
  }
  .small-box .icon i { position: static !important; font-size: 15px !important; transform: none !important; }

  .small-box.bg-primary, .small-box.text-bg-primary { background: rgba(42,120,214,.07) !important; border-left-color: #2a78d6; }
  .small-box.bg-primary .icon, .small-box.text-bg-primary .icon { background: rgba(42,120,214,.16); }
  .small-box.bg-primary .icon i, .small-box.text-bg-primary .icon i { color: #2a78d6; }

  .small-box.bg-info, .small-box.text-bg-info { background: rgba(27,175,122,.07) !important; border-left-color: #1baf7a; }
  .small-box.bg-info .icon, .small-box.text-bg-info .icon { background: rgba(27,175,122,.16); }
  .small-box.bg-info .icon i, .small-box.text-bg-info .icon i { color: #159763; }

  .small-box.bg-secondary, .small-box.text-bg-secondary { background: rgba(74,58,167,.06) !important; border-left-color: #4a3aa7; }
  .small-box.bg-secondary .icon, .small-box.text-bg-secondary .icon { background: rgba(74,58,167,.14); }
  .small-box.bg-secondary .icon i, .small-box.text-bg-secondary .icon i { color: #4a3aa7; }

  .small-box.bg-success, .small-box.text-bg-success { background: rgba(12,163,12,.07) !important; border-left-color: #0ca30c; }
  .small-box.bg-success .icon, .small-box.text-bg-success .icon { background: rgba(12,163,12,.16); }
  .small-box.bg-success .icon i, .small-box.text-bg-success .icon i { color: #0ca30c; }

  .small-box.bg-warning, .small-box.text-bg-warning { background: rgba(250,178,25,.12) !important; border-left-color: #fab219; }
  .small-box.bg-warning .icon, .small-box.text-bg-warning .icon { background: rgba(250,178,25,.22); }
  .small-box.bg-warning .icon i, .small-box.text-bg-warning .icon i { color: #9c6b04; }

  .small-box.bg-danger, .small-box.text-bg-danger { background: rgba(208,59,59,.07) !important; border-left-color: #d03b3b; }
  .small-box.bg-danger .icon, .small-box.text-bg-danger .icon { background: rgba(208,59,59,.16); }
  .small-box.bg-danger .icon i, .small-box.text-bg-danger .icon i { color: #d03b3b; }

  .dash-attention {
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea);
    border-radius: var(--if-radius, 12px); padding: .65rem 1rem;
  }
  .dash-attention-label {
    font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em;
    color: var(--if-muted, #5d6f76); white-space: nowrap; display: flex; align-items: center; gap: .4rem;
  }
  .dash-attention-items { display: flex; flex-wrap: wrap; gap: .5rem; flex: 1; }
  .dash-attention-chip {
    display: inline-flex; align-items: center; gap: .45rem; padding: .35rem .75rem;
    border-radius: 999px; text-decoration: none; font-size: .82rem; font-weight: 500;
    border: 1px solid var(--if-border-strong, #d3dbdc); color: var(--if-ink, #16232a);
    background: var(--if-bg, #eef2f2); transition: transform .12s ease, box-shadow .12s ease;
  }
  .dash-attention-chip:hover { transform: translateY(-1px); box-shadow: var(--if-shadow, 0 1px 2px rgba(20,35,42,.04)); text-decoration: none; color: var(--if-ink, #16232a); }
  .dash-attention-chip .dash-attention-count { font-weight: 700; font-family: var(--if-mono, monospace); }
  .dash-attention-chip.is-clear { opacity: .5; }
  .dash-attention-chip.is-active { border-color: rgba(217,119,6,.35); background: rgba(217,119,6,.1); color: #9a5b00; }
  :root[data-bs-theme="dark"] .dash-attention-chip.is-active { color: #f2b84b; }
</style>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
    <div>
        <h4 class="mb-0 fw-bold"><i class="fas fa-th-large me-2"></i>Internal IT Dashboard</h4>
        <small class="text-muted">
            <?php if ($location) { ?>
                Site rollup for <?= nullable_htmlentities($location['location_name']) ?><?= $location['client_name'] ? ' &middot; ' . nullable_htmlentities($location['client_name']) : '' ?>
            <?php } else { ?>
                All sites
            <?php } ?>
        </small>
    </div>
    <form class="d-flex align-items-center gap-2">
        <label for="location_id" class="me-1 text-muted small">Site:</label>
        <select id="location_id" name="location_id" class="form-select form-select-sm auto-submit-select" style="width:auto;">
            <option value="0">All Locations</option>
            <?php while ($loc_row = mysqli_fetch_assoc($sql_locations)) { ?>
                <option value="<?= intval($loc_row['location_id']) ?>" <?= $loc_row['location_id'] == $location_id ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($loc_row['client_name']) ?> &middot; <?= nullable_htmlentities($loc_row['location_name']) ?>
                </option>
            <?php } ?>
        </select>
    </form>
</div>

<div class="dash-attention mb-4">
    <div class="dash-attention-label">
        <i class="fas fa-bolt"></i> Expiring soon
    </div>
    <div class="dash-attention-items">
        <a href="domains.php?sort=domain_expire&order=ASC" class="dash-attention-chip <?= $dash_domains_30 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-globe"></i><span class="dash-attention-count"><?= $dash_domains_30 ?></span><span>Domains (30d)</span>
        </a>
        <a href="domains.php?sort=domain_expire&order=ASC" class="dash-attention-chip <?= $dash_domains_60 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-globe"></i><span class="dash-attention-count"><?= $dash_domains_60 ?></span><span>Domains (60d)</span>
        </a>
        <a href="domains.php?sort=domain_expire&order=ASC" class="dash-attention-chip <?= $dash_domains_90 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-globe"></i><span class="dash-attention-count"><?= $dash_domains_90 ?></span><span>Domains (90d)</span>
        </a>
        <a href="certificates.php?sort=certificate_expire&order=ASC" class="dash-attention-chip <?= $dash_certs_30 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-lock"></i><span class="dash-attention-count"><?= $dash_certs_30 ?></span><span>Certificates (30d)</span>
        </a>
        <a href="certificates.php?sort=certificate_expire&order=ASC" class="dash-attention-chip <?= $dash_certs_60 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-lock"></i><span class="dash-attention-count"><?= $dash_certs_60 ?></span><span>Certificates (60d)</span>
        </a>
        <a href="certificates.php?sort=certificate_expire&order=ASC" class="dash-attention-chip <?= $dash_certs_90 > 0 ? 'is-active' : 'is-clear' ?>">
            <i class="fas fa-lock"></i><span class="dash-attention-count"><?= $dash_certs_90 ?></span><span>Certificates (90d)</span>
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <a href="tickets.php" class="text-decoration-none">
        <div class="small-box text-bg-primary bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_open_tickets ?></h3><p>Open Tickets</p></div>
            <div class="icon"><i class="fas fa-ticket-alt"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="tickets.php" class="text-decoration-none">
        <div class="small-box text-bg-danger bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_critical_tickets ?></h3><p>Critical Open Tickets</p></div>
            <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="tickets.php?assigned=0" class="text-decoration-none">
        <div class="small-box text-bg-warning bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_unassigned_tickets ?></h3><p>Unassigned Tickets</p></div>
            <div class="icon"><i class="fas fa-user-slash"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="contacts.php" class="text-decoration-none">
        <div class="small-box text-bg-success bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_active_people ?></h3><p>Active People</p></div>
            <div class="icon"><i class="fas fa-user-check"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="small-box text-bg-info bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_onboarding_in_progress ?></h3><p>Onboarding In Progress</p></div>
            <div class="icon"><i class="fas fa-user-plus"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="small-box text-bg-secondary bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_offboarding_in_progress ?></h3><p>Offboarding In Progress</p></div>
            <div class="icon"><i class="fas fa-user-minus"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="assets.php" class="text-decoration-none">
        <div class="small-box text-bg-primary bg-gradient mb-0">
            <div class="inner"><h3><?= $dash_total_assets ?></h3><p>Assets</p></div>
            <div class="icon"><i class="fas fa-desktop"></i></div>
        </div>
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h5 class="card-title"><i class="fas fa-fw fa-desktop me-2"></i>Assets by Status</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php
                        $any_asset_row = false;
                        while ($ar = mysqli_fetch_assoc($sql_assets_by_status)) {
                            $any_asset_row = true;
                            $pct = $dash_total_assets > 0 ? min(100, round(intval($ar['c']) / $dash_total_assets * 100)) : 0;
                        ?>
                        <tr>
                            <td style="width:40%;"><?= nullable_htmlentities($ar['status_name']) ?></td>
                            <td>
                                <div class="progress" style="height:18px;">
                                    <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"><?= intval($ar['c']) ?></div>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                        <?php if (!$any_asset_row) { ?>
                        <tr><td class="text-muted">No assets found.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-3">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h5 class="card-title"><i class="fas fa-fw fa-clock me-2"></i>Expiring within 90 Days</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead>
                        <tr><th>Item</th><th>Type</th><th>Department</th><th class="text-end">Expires</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $any_expiring_row = false;
                        while ($er = mysqli_fetch_assoc($sql_expiring_items)) {
                            $any_expiring_row = true;
                        ?>
                        <tr>
                            <td><?= nullable_htmlentities($er['item_name']) ?></td>
                            <td><span class="badge <?= $er['item_type'] === 'Domain' ? 'text-bg-info' : 'text-bg-secondary' ?>"><?= $er['item_type'] ?></span></td>
                            <td><?= $er['item_client_id'] ? '<a href="clients.php?client_id=' . intval($er['item_client_id']) . '">' . nullable_htmlentities($er['item_client_name']) . '</a>' : '-' ?></td>
                            <td class="text-end"><?= nullable_htmlentities($er['item_expire']) ?></td>
                        </tr>
                        <?php } ?>
                        <?php if (!$any_expiring_row) { ?>
                        <tr><td colspan="4" class="text-muted">Nothing expiring in the next 90 days.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 mb-3">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h5 class="card-title"><i class="fas fa-fw fa-tasks me-2"></i>Onboarding / Offboarding In Progress</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead>
                        <tr><th>Person</th><th>Type</th><th>Department</th><th>Started</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $any_wf_row = false;
                        while ($wr = mysqli_fetch_assoc($sql_wf_in_progress)) {
                            $any_wf_row = true;
                        ?>
                        <tr>
                            <td><?= nullable_htmlentities($wr['contact_name']) ?></td>
                            <td><span class="badge <?= $wr['type'] === 'onboarding' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= ucfirst($wr['type']) ?></span></td>
                            <td><?= nullable_htmlentities($wr['client_name']) ?></td>
                            <td><?= nullable_htmlentities($wr['started_at']) ?></td>
                            <td class="text-end"><a href="workflow_run.php?run_id=<?= intval($wr['run_id']) ?>" class="btn btn-sm btn-default"><i class="fas fa-arrow-right"></i></a></td>
                        </tr>
                        <?php } ?>
                        <?php if (!$any_wf_row) { ?>
                        <tr><td colspan="5" class="text-muted">No onboarding or offboarding runs in progress.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
