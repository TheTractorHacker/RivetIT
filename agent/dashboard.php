<?php
// Module-only (limited) logins - no Departments, Tickets/assets/docs or Assets, e.g. Training only - never
// see this dashboard: includes/check_login.php sends them to their own home (itflow_home_url()) before any
// markup, and there is no ?home=1 way around that any more (roles audit P0/F6).
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
if (itflow_is_limited_user()) {   // belt and braces; check_login.php already redirected
    header('Location: ' . itflow_home_url());
    exit;
}
require_once "includes/inc_all.php";
require_once "../includes/sla_functions.php";

// Roles audit P1f: every widget checks the module it shows, and ticket widgets are scoped to the user's
// departments. Admins and full-access roles (Technician) see exactly what they saw before: they hold every
// module these widgets need and have no department restriction rows (or are admins).
$dash_can_tickets = lookupUserPermission('module_support') >= 1;
$dash_can_clients = lookupUserPermission('module_client') >= 1;
$dash_can_assets  = itflow_can_assets(1);
$dash_can_sales   = lookupUserPermission('module_sales') >= 1;
$dash_can_finance = $dash_can_sales || lookupUserPermission('module_financial') >= 1;
$dash_scoped = ($client_access_string && !$session_is_admin);
$dash_scope_tickets   = !$dash_can_tickets ? 'AND 1 = 0' : ($dash_scoped ? "AND ticket_client_id IN ($client_access_string)" : '');
$dash_scope_tickets_t = !$dash_can_tickets ? 'AND 1 = 0' : ($dash_scoped ? "AND t.ticket_client_id IN ($client_access_string)" : '');

// Get current year or the selected year
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Chart-type preference for each section is a free-text-looking VARCHAR(20)
// column, so unlike the enable flags (a simple intval) it goes through an
// allow-list before it ever reaches SQL or a Chart.js `type` option - never
// trust it as a bare string off the query string. The two lists are also the
// <select> options below and are ordered to match each section's current,
// pre-existing default (line for Financial, bar for Technical) first.
$dash_chart_type_options = [
    'financial' => ['line', 'bar'],
    'technical' => ['bar', 'line'],
];

// Update user settings based on GET parameters
if (isset($_GET['enable_financial'])) {
    $enable_financial = intval($_GET['enable_financial']);
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_dashboard_financial_enable = $enable_financial WHERE user_id = $session_user_id");
}

if (isset($_GET['financial_chart_type']) && in_array($_GET['financial_chart_type'], $dash_chart_type_options['financial'], true)) {
    $financial_chart_type_set = $_GET['financial_chart_type'];
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_dashboard_financial_chart_type = '$financial_chart_type_set' WHERE user_id = $session_user_id");
}

if (isset($_GET['technical_chart_type']) && in_array($_GET['technical_chart_type'], $dash_chart_type_options['technical'], true)) {
    $technical_chart_type_set = $_GET['technical_chart_type'];
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_dashboard_technical_chart_type = '$technical_chart_type_set' WHERE user_id = $session_user_id");
}

// Fetch User Dashboard Settings
$sql_user_dashboard_settings = mysqli_query($mysqli, "SELECT * FROM user_settings WHERE user_id = $session_user_id");
$row = mysqli_fetch_assoc($sql_user_dashboard_settings);
$user_config_dashboard_financial_enable = intval($row['user_config_dashboard_financial_enable']);
// Re-validate what came back from the DB too (belt and suspenders against a
// hand-edited row, and it doubles as the fallback for an upgrade that hasn't
// run the migration yet) before it's ever echoed into a Chart.js `type`.
$user_config_dashboard_financial_chart_type = in_array($row['user_config_dashboard_financial_chart_type'] ?? null, $dash_chart_type_options['financial'], true)
    ? $row['user_config_dashboard_financial_chart_type'] : 'line';
$user_config_dashboard_technical_chart_type = in_array($row['user_config_dashboard_technical_chart_type'] ?? null, $dash_chart_type_options['technical'], true)
    ? $row['user_config_dashboard_technical_chart_type'] : 'bar';
?>

<!-- Dashboard layout, chart wells and status-strip styles -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  .chart-h-240 { position: relative; height: 240px; }
  /* If you want charts to shrink on very small screens, you can tweak with media queries:
  @media (max-width: 576px) {
    .chart-h-320 { height: 240px; }
    .chart-h-240 { height: 200px; }
  } */

  /* ---- Tile and card grids -------------------------------------------------
     Every stat row on this page used to pick its own Bootstrap column span
     (col-md-3 at the top, col-lg-4 + col-lg-3 in the technical block,
     col-lg-2 and col-lg-6 in the financial one), so a row only filled the
     width when its tile count happened to match that span. The counts are
     data-dependent - the accounting, CSAT and workflow tiles come and go - so
     three tiles sat in a 4-up grid and left a quarter-width notch at the top
     of the page, and one row carried two different tile widths at once.

     auto-fit collapses the tracks it does not need, so a short row spreads to
     fill instead of trailing off, and every tile in a row is the same width
     whatever the count. min() keeps the track from overflowing a phone. */
  .dash-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(17rem, 100%), 1fr));
    gap: 1rem;
  }
  .dash-tiles > * { margin: 0; }

  /* Same rule for the chart and table cards, at a wider minimum so a chart is
     never squeezed. Grid stretch also trues the bottoms of side-by-side cards,
     which is what h-100 was doing by hand wherever anyone remembered it. */
  .dash-charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(20rem, 100%), 1fr));
    gap: 1rem;
    align-items: stretch;
  }
  /* These stacks space themselves with `gap`, so css/itflow_design.css's
     `.card + .card { margin-top: 1rem }` stands down inside them - the same
     idiom that file already applies to .ticket-sidebar. */
  .dash-charts > .card { margin-bottom: 0; }
  .dash-charts > .card + .card { margin-top: 0; }

  /* A quiet rule between tile groups, so "Historical" reads as a new band
     rather than as more of the row above it. */
  .dash-section-title {
    display: flex; align-items: center; gap: .45rem;
    font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em;
    color: var(--if-muted, #5d6f76);
    margin: 1.75rem 0 .75rem;
  }

  /* Year picker: .form-select paints its chevron at `calc(100% - 16px)`, but
     css/itflow_design.css gives the control a symmetric .7rem padding - so on
     a select shrink-wrapped to "2026" the chevron was drawn straight through
     the digits. Reserve the gutter the chevron actually occupies. */
  .dash-year-select { width: auto; min-width: 7rem; padding-right: 2.25rem; }

  /* Calm empty state: a breakdown with nothing to break down says so once,
     instead of rendering as a row of blank chart boxes that read as charts
     that failed to load. */
  .dash-empty {
    display: flex; align-items: center; gap: .6rem;
    padding: 1rem 1.1rem;
    border: 1px dashed var(--if-border-strong, #d3dbdc);
    border-radius: var(--if-radius, 12px);
    color: var(--if-muted, #5d6f76); font-size: .875rem;
  }

  /* The stat tiles themselves live in css/itflow_design.css (.small-box, its
     seven hues and its icon chip). This page used to restate all of it inline
     in hardcoded light-mode hexes - #0b0b0b numbers, #52514e labels and a
     #e6e5e0 border - which in dark mode put black text on a near-black tile
     (1.1:1) inside a near-white outline. The stylesheet's version is written
     against --if-ink / --if-muted / --if-border and follows the theme, so the
     page-local copy is gone rather than divergent. */

  /* "Needs attention" status strip: a NOC-style checklist, not another stat
     tile. A clear item recedes to a muted outline so the eye jumps straight to
     whatever is actually amber - the same "quiet unless it matters" convention
     already used for sidebar active-states and table row accents. It recedes
     by colour, not by opacity: `opacity:.5` faded the ink AND the chip toward
     the card together, which held the label and its count at about 3:1 and
     made the counts the faintest text on the page. */
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
  .dash-attention-chip.is-clear {
    color: var(--if-muted, #5d6f76);
    background: transparent;
    border-color: var(--if-border, #e3e9ea);
  }
  .dash-attention-chip.is-active {
    border-color: rgba(217,119,6,.35); background: rgba(217,119,6,.1); color: #9a5b00;
  }
  :root[data-bs-theme="dark"] .dash-attention-chip.is-active { color: #f2b84b; }
</style>


<?php
// Dashboard stat cards
$dash_open_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL AND ticket_resolved_at IS NULL"))[0]);
$dash_active_clients = $dash_can_clients ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM clients WHERE client_archived_at IS NULL"))[0]) : 0;
$dash_my_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_assigned_to = $session_user_id AND ticket_closed_at IS NULL"))[0]);
$dash_pending_invoices = $dash_can_sales ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoices WHERE invoice_status IN ('Sent', 'Viewed', 'Partial')"))[0]) : 0;
$dash_hour = intval(date('G'));
$dash_greeting = $dash_hour < 12 ? 'Good morning' : ($dash_hour < 17 ? 'Good afternoon' : 'Good evening');

// "Needs attention" signals - computed unconditionally (regardless of the
// Financial/Technical toggles below) so the top-of-page status strip always
// reflects reality. Cheap single-table counts, reused (not re-queried) by the
// Technical section further down if it's enabled.
$dash_unassigned_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0)"))[0]);
$dash_expiring_domains = !$dash_can_tickets ? 0 : intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 30 DAY AND domain_archived_at IS NULL"))[0]);
$dash_expiring_certificates = !$dash_can_tickets ? 0 : intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 30 DAY AND certificate_archived_at IS NULL"))[0]);

$dash_attention_items = [];
if ($dash_can_tickets) {
    $dash_attention_items = [
        ['count' => $dash_unassigned_tickets,      'label' => 'Unassigned tickets',        'href' => 'tickets.php?assigned=0',                              'icon' => 'fa-user-slash'],
        ['count' => $dash_expiring_domains,         'label' => 'Domains expiring (30d)',    'href' => 'domains.php?sort=domain_expire&order=ASC',            'icon' => 'fa-globe'],
        ['count' => $dash_expiring_certificates,    'label' => 'Certificates expiring (30d)','href' => 'certificates.php?sort=certificate_expire&order=ASC',  'icon' => 'fa-lock'],
    ];
}
if ($config_module_enable_accounting && $dash_can_sales) {
    $dash_attention_items[] = ['count' => $dash_pending_invoices, 'label' => 'Unpaid invoices', 'href' => 'invoices.php', 'icon' => 'fa-file-invoice-dollar'];
}

$dash_csat_avg = null;
if ($config_module_enable_ticketing == 1 && !empty($config_ticket_csat_enable) && $dash_can_tickets) {
    $dash_csat_needs_followup = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_csat_rating IS NOT NULL AND ticket_csat_rating <= $config_ticket_csat_low_rating_threshold AND ticket_status != 5"))[0]);
    $dash_attention_items[] = ['count' => $dash_csat_needs_followup, 'label' => 'Needs CSAT follow-up', 'href' => 'reports/csat.php', 'icon' => 'fa-star-half-alt'];

    $dash_csat_avg_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT AVG(ticket_csat_rating) AS v FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_csat_rated_at >= NOW() - INTERVAL 30 DAY"));
    $dash_csat_avg = $dash_csat_avg_row['v'] !== null ? round(floatval($dash_csat_avg_row['v']), 2) : null;
}

// Training (Phase 2, S9): overdue assignments and expiring qualifications in the user's fail-closed training scope.
if (($config_module_enable_training ?? 0) == 1 && lookupUserPermission('module_training') >= 1) {
    try {
        $tr_chip = \ITFlow\Training\Compliance\NavCounts::forCtx(\ITFlow\Training\Core\Access::ctx($mysqli), true);
        $dash_attention_items[] = ['count' => intval($tr_chip['overdue'] ?? 0), 'label' => 'Training overdue', 'href' => 'training_assignments.php?status=overdue', 'icon' => 'fa-hard-hat'];
        $dash_attention_items[] = ['count' => intval($tr_chip['expiring_30'] ?? 0), 'label' => 'Qualifications expiring (30d)', 'href' => 'training_reports.php?tab=expiring&days=30', 'icon' => 'fa-id-card'];
    } catch (\Throwable $e) { error_log('Training dashboard chips: ' . $e->getMessage()); }
}

$dash_attention_total = array_sum(array_column($dash_attention_items, 'count'));

// Onboarding / offboarding workflow runs + assets-by-status (formerly the
// separate "Internal IT Dashboard" page - folded in here so there's one
// dashboard, not two). $access_permission_query assumes a `clients` table
// join that none of these queries have, so scope by client_access_string
// directly instead, same as the page it replaces did.
// Workflow runs are Departments data (agent/workflow_run.php needs it); assets need Assets or Tickets/assets/docs.
$dash_scope_wf_contact = !$dash_can_clients ? 'AND 1 = 0' : ($dash_scoped ? "AND c.contact_client_id IN ($client_access_string)" : '');
$dash_scope_assets = !$dash_can_assets ? 'AND 1 = 0' : ($dash_scoped ? "AND asset_client_id IN ($client_access_string)" : '');

$dash_onboarding_in_progress = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM workflow_runs wr INNER JOIN contacts c ON c.contact_id = wr.contact_id
     WHERE wr.type = 'onboarding' AND wr.status = 'in_progress' $dash_scope_wf_contact"))[0]);
$dash_offboarding_in_progress = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM workflow_runs wr INNER JOIN contacts c ON c.contact_id = wr.contact_id
     WHERE wr.type = 'offboarding' AND wr.status = 'in_progress' $dash_scope_wf_contact"))[0]);
$dash_workflows_in_progress = $dash_onboarding_in_progress + $dash_offboarding_in_progress;

$sql_wf_in_progress = mysqli_query($mysqli,
    "SELECT wr.run_id, wr.type, wr.started_at, c.contact_name, c.contact_client_id, cl.client_name
     FROM workflow_runs wr
     INNER JOIN contacts c ON c.contact_id = wr.contact_id
     LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
     WHERE wr.status = 'in_progress' $dash_scope_wf_contact
     ORDER BY wr.started_at ASC
     LIMIT 25");

$sql_assets_by_status = mysqli_query($mysqli,
    "SELECT COALESCE(asset_status, 'Unknown') AS status_name, COUNT(*) AS c
     FROM assets WHERE asset_archived_at IS NULL $dash_scope_assets
     GROUP BY status_name ORDER BY c DESC");
$dash_total_assets = intval(mysqli_fetch_row(mysqli_query($mysqli,
    "SELECT COUNT(*) FROM assets WHERE asset_archived_at IS NULL $dash_scope_assets"))[0]);
?>
<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
    <div>
        <h4 class="mb-0 fw-bold"><?= $dash_greeting ?>, <?= nullable_htmlentities($session_name) ?>!</h4>
        <small class="text-muted"><?= date('l, F j, Y') ?></small>
    </div>
</div>

<div class="dash-attention mb-4">
    <div class="dash-attention-label">
        <?php if ($dash_attention_total === 0): ?>
            <i class="fas fa-check-circle"></i> All clear
        <?php else: ?>
            <i class="fas fa-bolt"></i> Needs attention
        <?php endif; ?>
    </div>
    <div class="dash-attention-items">
        <?php foreach ($dash_attention_items as $dash_ai): ?>
            <a href="<?= $dash_ai['href'] ?>" class="dash-attention-chip <?= $dash_ai['count'] > 0 ? 'is-active' : 'is-clear' ?>">
                <i class="fas <?= $dash_ai['icon'] ?>"></i>
                <span class="dash-attention-count"><?= $dash_ai['count'] ?></span>
                <span class="dash-attention-text"><?= $dash_ai['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
// Between three and six tiles land here depending on which modules are on, so
// the row is a grid that fits itself to the count rather than a fixed 4-up
// span that only filled at exactly four. The tile IS the link - the old
// wrapper <div><a><div class="small-box"> nesting gave the top row a different
// height from every other tile row on the page.
?>
<div class="dash-tiles mb-4">
    <?php if ($dash_can_tickets) { ?>
    <a href="tickets.php" class="small-box text-bg-primary">
        <div class="inner">
            <h3><?= $dash_open_tickets ?></h3>
            <p>Open Tickets</p>
        </div>
        <div class="icon"><i class="fas fa-ticket-alt"></i></div>
    </a>
    <a href="tickets.php?assigned_to=<?= $session_user_id ?>" class="small-box text-bg-info">
        <div class="inner">
            <h3><?= $dash_my_tickets ?></h3>
            <p>My Tickets</p>
        </div>
        <div class="icon"><i class="fas fa-user-check"></i></div>
    </a>
    <?php } ?>
    <?php if ($dash_can_clients) { ?>
    <a href="clients.php" class="small-box text-bg-success">
        <div class="inner">
            <h3><?= $dash_active_clients ?></h3>
            <p>Active Departments</p>
        </div>
        <div class="icon"><i class="fas fa-building"></i></div>
    </a>
    <?php } ?>
    <?php if ($config_module_enable_accounting && $dash_can_sales) { ?>
    <a href="invoices.php" class="small-box text-bg-warning">
        <div class="inner">
            <h3><?= $dash_pending_invoices ?></h3>
            <p>Unpaid Invoices</p>
        </div>
        <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
    </a>
    <?php } ?>
    <?php if ($dash_csat_avg !== null) { ?>
    <a href="reports/csat.php" class="small-box text-bg-secondary">
        <div class="inner">
            <h3><?= $dash_csat_avg ?>/5</h3>
            <p>CSAT (30 days)</p>
        </div>
        <div class="icon"><i class="fas fa-star"></i></div>
    </a>
    <?php } ?>
    <?php if ($dash_workflows_in_progress > 0) { ?>
    <div class="small-box bg-pink">
        <div class="inner">
            <h3><?= $dash_workflows_in_progress ?></h3>
            <p>Onboarding/Offboarding In Progress</p>
        </div>
        <div class="icon"><i class="fas fa-user-clock"></i></div>
    </div>
    <?php } ?>
</div>

<?php if ($dash_total_assets > 0 || $dash_workflows_in_progress > 0) { ?>
<?php
// Two cards side by side on the shared card grid: it stretches them to a
// common bottom edge, so neither needs h-100 and neither trails the other.
?>
<div class="dash-charts mb-3">
    <?php if ($dash_can_assets) { ?>
    <div class="card card-dark">
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
                        <td style="width:45%;"><?= nullable_htmlentities($ar['status_name']) ?></td>
                        <td>
                            <div class="progress" style="height:12px;">
                                <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                            </div>
                        </td>
                        <td class="text-end fw-semibold" style="width:3.5rem;"><?= intval($ar['c']) ?></td>
                    </tr>
                    <?php } ?>
                    <?php if (!$any_asset_row) { ?>
                    <tr><td colspan="3" class="text-muted">No assets found.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php } ?>

    <?php if ($dash_can_clients) { ?>
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
    <?php } ?>
</div>
<?php } ?>

<?php
// Every other accounting surface in this app (Quick Links, the agent
// nav's Finance dropdown - agent/includes/side_nav.php's
// `config_module_enable_accounting == 1` checks) hides itself when
// accounting is off system-wide. This switch used to skip that check
// for role 1 (admin), so an admin could enable and see Cash Flow /
// revenue charts on an Internal IT install with accounting force-
// disabled. Gated for every role now, same as the section render
// below - see the note there on why the user's stored on/off
// preference is left alone rather than cleared when this hides it.
//
// This form is the ONLY thing that ever lived in the top filter bar now
// that Select Year (moved to Reports) and the Technical switch (Technical
// is always on) are both gone - so on an install with accounting off (like
// this one), rendering the wrapping .card here would just be an empty
// white bar. The Technical chart-type <select>, further down in the
// "Tickets Opened vs Resolved" card header, still needs *some* <form> to
// submit via its form="dashboardFiltersForm" attribute (auto-submit-select
// calls el.form.submit()) regardless of whether Financial's own controls
// render, so an invisible form is always emitted as the else-branch.
?>
<?php if ($config_module_enable_accounting == 1 && $dash_can_finance && ($session_user_role == 1 || $session_user_role == 3)) { ?>
<div class="card card-body mb-4">
    <?php
    // The form is a flex row with `gap`, so the per-control me-*/mb-* margins it
    // used to carry only fought that gap. `col-sm-2` was a grid span on a
    // control that is not in a grid, and `width:auto` shrink-wrapped the select
    // to "2026" - which is what put the chevron on top of the digits.
    ?>
    <form id="dashboardFiltersForm" class="d-flex flex-wrap align-items-center gap-3">
        <input type="hidden" name="enable_financial" value="0">
        <div class="form-check form-switch mb-0">
            <input type="checkbox" class="form-check-input auto-submit-select" id="customSwitch1" name="enable_financial" value="1" <?php if ($user_config_dashboard_financial_enable == 1) { echo "checked"; } ?>>
            <label class="form-check-label" for="customSwitch1">Financial</label>
        </div>
        <div class="d-flex align-items-center gap-1">
            <label for="financial_chart_type" class="mb-0 small text-muted">Chart</label>
            <select id="financial_chart_type" name="financial_chart_type" class="form-select form-select-sm dash-year-select auto-submit-select">
                <option value="line" <?php if ($user_config_dashboard_financial_chart_type === 'line') { echo "selected"; } ?>>Line</option>
                <option value="bar" <?php if ($user_config_dashboard_financial_chart_type === 'bar') { echo "selected"; } ?>>Bar</option>
            </select>
        </div>
    </form>
</div>
<?php } else { ?>
<form id="dashboardFiltersForm" class="d-none"></form>
<?php } ?>

<?php
// Gated on config_module_enable_accounting too, not just the user's own
// switch above: this section is Cash Flow / revenue / expense charts, the
// same accounting surface Quick Links and the nav Finance dropdown already
// hide when accounting is off system-wide (the default on this Internal IT
// edition) - the switch alone let a user see it regardless.
//
// Deliberately NOT clearing user_config_dashboard_financial_enable when
// accounting is off: it's the user's own saved preference, accounting can be
// off temporarily (or per-deployment), and silently zeroing everyone's
// stored choice the moment an admin flips the module would lose their intent
// and make them re-enable it by hand later for no reason. Suppress the
// render, leave the stored value alone - identical to how the switch itself
// just disappears above rather than uncheck-and-save.
if ($user_config_dashboard_financial_enable == 1 && $config_module_enable_accounting == 1 && $dash_can_finance) {

    // Fetch financial data for the dashboard
    // Define variables to avoid errors in logs
    $largest_income_month = 0;

    $sql_total_payments_to_invoices = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS total_payments_to_invoices FROM payments WHERE YEAR(payment_date) = $year");
    $row = mysqli_fetch_assoc($sql_total_payments_to_invoices);
    $total_payments_to_invoices = floatval($row['total_payments_to_invoices']);

    $sql_total_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS total_revenues FROM revenues WHERE YEAR(revenue_date) = $year AND revenue_category_id > 0");
    $row = mysqli_fetch_assoc($sql_total_revenues);
    $total_revenues = floatval($row['total_revenues']);

    $total_income = $total_payments_to_invoices + $total_revenues;

    $sql_total_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS total_expenses FROM expenses WHERE expense_vendor_id > 0 AND YEAR(expense_date) = $year");
    $row = mysqli_fetch_assoc($sql_total_expenses);
    $total_expenses = floatval($row['total_expenses']);

    $sql_invoice_totals = mysqli_query($mysqli, "SELECT SUM(invoice_amount) AS invoice_totals FROM invoices WHERE invoice_status != 'Draft' AND invoice_status != 'Cancelled' AND invoice_status != 'Non-Billable' AND YEAR(invoice_date) = $year");
    $row = mysqli_fetch_assoc($sql_invoice_totals);
    $invoice_totals = floatval($row['invoice_totals']);

    $sql_total_payments_to_invoices_all_years = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS total_payments_to_invoices_all_years FROM payments");
    $row = mysqli_fetch_assoc($sql_total_payments_to_invoices_all_years);
    $total_payments_to_invoices_all_years = floatval($row['total_payments_to_invoices_all_years']);

    $sql_invoice_totals_all_years = mysqli_query($mysqli, "SELECT SUM(invoice_amount) AS invoice_totals_all_years FROM invoices WHERE invoice_status != 'Draft' AND invoice_status != 'Cancelled' AND invoice_status != 'Non-Billable'");
    $row = mysqli_fetch_assoc($sql_invoice_totals_all_years);
    $invoice_totals_all_years = floatval($row['invoice_totals_all_years']);

    $receivables = $invoice_totals_all_years - $total_payments_to_invoices_all_years;

    $profit = $total_income - $total_expenses;

    $sql_accounts = mysqli_query($mysqli, "SELECT * FROM accounts WHERE account_archived_at IS NULL ORDER BY account_name ASC");

    $sql_latest_invoice_payments = mysqli_query($mysqli, "
        SELECT * FROM payments
        JOIN invoices ON payment_invoice_id = invoice_id
        JOIN clients ON invoice_client_id = client_id
        ORDER BY payment_id DESC LIMIT 5
    ");

    $sql_latest_expenses = mysqli_query($mysqli, "
        SELECT * FROM expenses
        JOIN vendors ON expense_vendor_id = vendor_id
        JOIN categories ON expense_category_id = category_id
        ORDER BY expense_id DESC LIMIT 5
    ");

    // Get recurring invoice totals
    $sql_recurring_yearly_total = mysqli_query($mysqli, "SELECT SUM(recurring_invoice_amount) AS recurring_yearly_total FROM recurring_invoices WHERE recurring_invoice_status = 1 AND recurring_invoice_frequency = 'year' AND YEAR(recurring_invoice_created_at) <= $year");
    $row = mysqli_fetch_assoc($sql_recurring_yearly_total);
    $recurring_yearly_total = floatval($row['recurring_yearly_total']);

    $sql_recurring_monthly_total = mysqli_query($mysqli, "SELECT SUM(recurring_invoice_amount) AS recurring_monthly_total FROM recurring_invoices WHERE recurring_invoice_status = 1 AND recurring_invoice_frequency = 'month' AND YEAR(recurring_invoice_created_at) <= $year");
    $row = mysqli_fetch_assoc($sql_recurring_monthly_total);
    $recurring_monthly_total = floatval($row['recurring_monthly_total']) + ($recurring_yearly_total / 12);

    // Recurring expenses totals
    $sql_recurring_expense_yearly_total = mysqli_query($mysqli, "SELECT SUM(recurring_expense_amount) AS recurring_expense_yearly_total FROM recurring_expenses WHERE recurring_expense_status = 1 AND recurring_expense_frequency = 2 AND YEAR(recurring_expense_created_at) <= $year");
    $row = mysqli_fetch_assoc($sql_recurring_expense_yearly_total);
    $recurring_expense_yearly_total = floatval($row['recurring_expense_yearly_total']);

    $sql_recurring_expense_monthly_total = mysqli_query($mysqli, "SELECT SUM(recurring_expense_amount) AS recurring_expense_monthly_total FROM recurring_expenses WHERE recurring_expense_status = 1 AND recurring_expense_frequency = 1 AND YEAR(recurring_expense_created_at) <= $year");
    $row = mysqli_fetch_assoc($sql_recurring_expense_monthly_total);
    $recurring_expense_monthly_total = floatval($row['recurring_expense_monthly_total']) + ($recurring_expense_yearly_total / 12);

    // Get miles driven
    $sql_miles_driven = mysqli_query($mysqli, "SELECT SUM(trip_miles) AS total_miles FROM trips WHERE YEAR(trip_date) = $year");
    $row = mysqli_fetch_assoc($sql_miles_driven);
    $total_miles = floatval($row['total_miles']);

    if ($config_module_enable_ticketing && $config_module_enable_accounting) {
        $sql_unbilled_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS unbilled_tickets FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL AND ticket_billable = 1 AND ticket_invoice_id = 0 AND YEAR(ticket_created_at) = $year");
        $row = mysqli_fetch_assoc($sql_unbilled_tickets);
        $unbilled_tickets = intval($row['unbilled_tickets']);
    } else {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(recurring_invoice_id) AS recurring_invoices_added FROM recurring_invoices WHERE YEAR(recurring_invoice_created_at) = $year"));
        $recurring_invoices_added = intval($row['recurring_invoices_added']);
    }

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(client_id) AS clients_added FROM clients WHERE YEAR(client_created_at) = $year AND client_archived_at IS NULL"));
    $clients_added = intval($row['clients_added']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(vendor_id) AS vendors_added FROM vendors WHERE YEAR(vendor_created_at) = $year AND vendor_client_id = 0 AND vendor_archived_at IS NULL"));
    $vendors_added = intval($row['vendors_added']);
?>
<?php
// The financial and technical blocks used to sit inside a `.card.card-body`
// wrapper, which put a second white panel behind every card they contain and
// indented their rows by the wrapper's padding - so the top stat row started
// 18px to the left of everything under it. They are page sections, not cards.
?>
<div class="dash-section">
    <div class="dash-tiles mb-4">
        <a class="small-box bg-primary" href="payments.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo numfmt_format_currency($currency_format, $total_income, "$session_company_currency"); ?></h3>
                <p>Income</p>
                <p class="mt-1"><small>Receivables: <?php echo numfmt_format_currency($currency_format, $receivables, "$session_company_currency"); ?></small></p>
            </div>
            <div class="icon"><i class="fa fa-hand-holding-usd"></i></div>
        </a>

        <a class="small-box bg-danger" href="expenses.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo numfmt_format_currency($currency_format, $total_expenses, "$session_company_currency"); ?></h3>
                <p>Expenses</p>
            </div>
            <div class="icon"><i class="fa fa-shopping-cart"></i></div>
        </a>

        <a class="small-box bg-success" href="reports/profit_loss.php">
            <div class="inner">
                <h3><?php echo numfmt_format_currency($currency_format, $profit, "$session_company_currency"); ?></h3>
                <p>Profit</p>
            </div>
            <div class="icon"><i class="fa fa-balance-scale"></i></div>
        </a>

        <a class="small-box bg-info" href="reports/recurring_by_client.php">
            <div class="inner">
                <h3><?php echo numfmt_format_currency($currency_format, $recurring_monthly_total, "$session_company_currency"); ?></h3>
                <p>Monthly Recurring Income</p>
            </div>
            <div class="icon"><i class="fa fa-sync-alt"></i></div>
        </a>

        <a class="small-box bg-pink" href="recurring_expenses.php">
            <div class="inner">
                <h3><?php echo numfmt_format_currency($currency_format, $recurring_expense_monthly_total, "$session_company_currency"); ?></h3>
                <p>Monthly Recurring Expense</p>
            </div>
            <div class="icon"><i class="fa fa-clock"></i></div>
        </a>

        <?php if ($config_module_enable_ticketing && $config_module_enable_accounting) { ?>
        <a class="small-box bg-secondary" href="reports/tickets_unbilled.php">
            <div class="inner">
                <h3><?php echo $unbilled_tickets; ?></h3>
                <p>Unbilled Ticket<?php if ($unbilled_tickets > 1 || $unbilled_tickets == 0) { echo "s"; } ?></p>
            </div>
            <div class="icon"><i class="fa fa-ticket-alt"></i></div>
        </a>
        <?php } else { ?>
        <a class="small-box bg-secondary" href="recurring_invoices.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo $recurring_invoices_added; ?></h3>
                <p>Recurring Invoices Added</p>
            </div>
            <div class="icon"><i class="fa fa-file-invoice"></i></div>
        </a>
        <?php } ?>

        <a class="small-box bg-secondary" href="clients.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo $clients_added; ?></h3>
                <p>New Departments</p>
            </div>
            <div class="icon"><i class="fa fa-users"></i></div>
        </a>

        <a class="small-box bg-secondary" href="vendors.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo $vendors_added; ?></h3>
                <p>New Vendors</p>
            </div>
            <div class="icon"><i class="fa fa-building"></i></div>
        </a>

        <a class="small-box bg-secondary" href="trips.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo number_format($total_miles, 2); ?></h3>
                <p>Miles Traveled</p>
            </div>
            <div class="icon"><i class="fa fa-route"></i></div>
        </a>
    </div>

    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-fw fa-chart-area me-2"></i>Cash Flow</h3>
            <div class="card-tools">
                <a href="reports/income_summary.php" class="btn btn-tool"><i class="fas fa-eye"></i></a>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-h-320"><canvas id="cashFlow"></canvas></div>
        </div>
    </div>

    <div class="dash-charts mb-3">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-chart-pie me-2"></i>Income by Category <small>(Top 5)</small></h3>
            </div>
            <div class="card-body">
                <div class="chart-h-240"><canvas id="incomeByCategoryPieChart"></canvas></div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-fw fa-shopping-cart me-2"></i>Expenses by Category <small>(Top 5)</small></h3>
            </div>
            <div class="card-body">
                <div class="chart-h-240"><canvas id="expenseByCategoryPieChart"></canvas></div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-fw fa-building me-2"></i>Expenses by Vendor <small>(Top 5)</small></h3>
            </div>
            <div class="card-body">
                <div class="chart-h-240"><canvas id="expenseByVendorPieChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="dash-charts mb-3">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-fw fa-piggy-bank me-2"></i>Account Balances</h3>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($sql_accounts)) {
                            $account_id = intval($row['account_id']);
                            $account_name = nullable_htmlentities($row['account_name']);
                            $opening_balance = floatval($row['opening_balance']);
                        ?>
                            <tr>
                                <td><?php echo $account_name; ?></td>
                                <?php
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS total_payments FROM payments WHERE payment_account_id = $account_id");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $total_payments = floatval($row['total_payments']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS total_revenues FROM revenues WHERE revenue_account_id = $account_id");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $total_revenues = floatval($row['total_revenues']);

                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS total_expenses FROM expenses WHERE expense_account_id = $account_id");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $total_expenses = floatval($row['total_expenses']);

                                $balance = $opening_balance + $total_payments + $total_revenues - $total_expenses;

                                if ($balance == '') {
                                    $balance = '0.00';
                                }
                                ?>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $balance, "$session_company_currency"); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-credit-card me-2"></i>Latest Income</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Invoice</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($sql_latest_invoice_payments)) {
                            $payment_date = nullable_htmlentities($row['payment_date']);
                            $payment_amount = floatval($row['payment_amount']);
                            $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
                            $invoice_number = intval($row['invoice_number']);
                            $client_name = nullable_htmlentities($row['client_name']);
                        ?>
                            <tr>
                                <td><?php echo $payment_date; ?></td>
                                <td><?php echo $client_name; ?></td>
                                <td><?php echo "$invoice_prefix$invoice_number"; ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_amount, "$session_company_currency"); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-shopping-cart me-2"></i>Latest Expenses</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Vendor</th>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($sql_latest_expenses)) {
                            $expense_date = nullable_htmlentities($row['expense_date']);
                            $expense_amount = floatval($row['expense_amount']);
                            $vendor_name = nullable_htmlentities($row['vendor_name']);
                            $category_name = nullable_htmlentities($row['category_name']);
                        ?>
                            <tr>
                                <td><?php echo $expense_date; ?></td>
                                <td><?php echo $vendor_name; ?></td>
                                <td><?php echo $category_name; ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_amount, "$session_company_currency"); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-fw fa-route me-2"></i>Trip Flow</h3>
            <div class="card-tools">
                <a href="trips.php" class="btn btn-tool"><i class="fas fa-eye"></i></a>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-h-320"><canvas id="tripFlow"></canvas></div>
        </div>
    </div>
</div> <!-- .dash-section -->

<?php } ?>

<!-- Technical Dashboard -->

<?php
if (true) {  // Technical dashboard is always shown now - no more enable toggle.

    // Fetch technical data for the dashboard
    $sql_clients = $dash_can_clients ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(client_id) AS clients_added FROM clients WHERE YEAR(client_created_at) = $year")) : ['clients_added' => 0];
    $clients_added = $sql_clients['clients_added'];

    $sql_contacts = $dash_can_clients ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(contact_id) AS contacts_added FROM contacts WHERE YEAR(contact_created_at) = $year")) : ['contacts_added' => 0];
    $contacts_added = $sql_contacts['contacts_added'];

    $sql_assets = $dash_can_assets ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(asset_id) AS assets_added FROM assets WHERE YEAR(asset_created_at) = $year")) : ['assets_added' => 0];
    $assets_added = $sql_assets['assets_added'];

    $sql_tickets = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS active_tickets FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL"));
    $active_tickets = $sql_tickets['active_tickets'];

    $sql_your_tickets = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS your_tickets FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL AND ticket_assigned_to = $session_user_id"));
    $your_tickets = $sql_your_tickets['your_tickets'];

    $sql_your_tickets = mysqli_query($mysqli, "
        SELECT * FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE ticket_assigned_to = $session_user_id $dash_scope_tickets
        AND ticket_closed_at IS NULL
        ORDER BY ticket_number DESC
    ");

    $sql_your_automation_runs = mysqli_query($mysqli, "
        SELECT r.*, t.ticket_subject, t.ticket_number, t.ticket_prefix, t.ticket_client_id, c.client_name
        FROM ticket_automation_runs r
        INNER JOIN tickets t ON t.ticket_id = r.ticket_id
        LEFT JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE t.ticket_assigned_to = $session_user_id $dash_scope_tickets_t
        ORDER BY r.id DESC
        LIMIT 10
    ");

    // Ticket metrics
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND DATE(ticket_created_at) = CURDATE()"));
    $tickets_opened_today = intval($row['c']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL AND ticket_closed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"));
    $tickets_resolved_week = intval($row['c']);

    // "Waiting on Employee": the internal-IT name of upstream's "Waiting on Customer" status. Statuses are per
    // install and matched by name (either name counts), since setup does not seed this status at all.
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL AND ticket_status_name IN ('Waiting on Employee', 'Waiting on Customer')"));
    $tickets_waiting_customer = intval($row['c']);

    // Priority breakdown (open tickets)
    $sql_by_priority = mysqli_query($mysqli, "SELECT ticket_priority, COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL GROUP BY ticket_priority ORDER BY FIELD(ticket_priority,'High','Medium','Low')");
    $priority_labels = $priority_counts = [];
    while ($r = mysqli_fetch_assoc($sql_by_priority)) { $priority_labels[] = $r['ticket_priority']; $priority_counts[] = intval($r['c']); }

    // Status breakdown (open tickets)
    $sql_by_status = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_color, COUNT(ticket_id) AS c FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL GROUP BY ticket_status_name, ticket_status_color ORDER BY c DESC LIMIT 8");
    $status_labels = $status_counts = $status_colors = [];
    while ($r = mysqli_fetch_assoc($sql_by_status)) { $status_labels[] = $r['ticket_status_name']; $status_counts[] = intval($r['c']); $status_colors[] = $r['ticket_status_color'] ?: '#6c757d'; }

    // Category breakdown (open tickets)
    $sql_by_cat = mysqli_query($mysqli, "SELECT COALESCE(category_name,'Uncategorized') AS cat, category_color, COUNT(ticket_id) AS c FROM tickets LEFT JOIN categories ON ticket_category = category_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL GROUP BY cat, category_color ORDER BY c DESC LIMIT 8");
    $cat_labels = $cat_counts = $cat_colors = [];
    while ($r = mysqli_fetch_assoc($sql_by_cat)) { $cat_labels[] = $r['cat']; $cat_counts[] = intval($r['c']); $cat_colors[] = $r['category_color'] ?: '#6c757d'; }

    // Monthly opened vs resolved for selected year
    $monthly_opened = $monthly_resolved = [];
    for ($m = 1; $m <= 12; $m++) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND YEAR(ticket_created_at)=$year AND MONTH(ticket_created_at)=$m"));
        $monthly_opened[] = intval($r['c']);
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at)=$year AND MONTH(ticket_closed_at)=$m"));
        $monthly_resolved[] = intval($r['c']);
    }

    // Top techs by open ticket count
    $sql_top_techs = mysqli_query($mysqli, "SELECT user_name, COUNT(ticket_id) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NULL AND ticket_assigned_to > 0 GROUP BY user_name ORDER BY c DESC LIMIT 8");
    $tech_rows = [];
    while ($r = mysqli_fetch_assoc($sql_top_techs)) $tech_rows[] = $r;

    // An empty chart card (a bare axis or legend over nothing) reads as a chart
    // that failed to load, so each card carries its own gate. The three
    // breakdowns run off OPEN tickets while the flow chart runs off the whole
    // year, so with the year busy and the queue empty - the normal healthy
    // state - one OR'd flag across all of them switched on three permanently
    // blank doughnuts. $dash_has_ticket_flow drives the chart it describes;
    // $dash_ticket_breakdowns is the list of breakdown cards that actually
    // have something to draw.
    $dash_has_ticket_flow = array_sum($monthly_opened) > 0 || array_sum($monthly_resolved) > 0;

    $dash_ticket_breakdowns = [];
    if (!empty($priority_labels)) { $dash_ticket_breakdowns[] = ['id' => 'ticketPriorityChart', 'icon' => 'fa-chart-pie',  'title' => 'By Priority']; }
    if (!empty($status_labels))   { $dash_ticket_breakdowns[] = ['id' => 'ticketStatusChart',   'icon' => 'fa-chart-pie',  'title' => 'By Status']; }
    if (!empty($cat_labels))      { $dash_ticket_breakdowns[] = ['id' => 'ticketCategoryChart', 'icon' => 'fa-chart-pie',  'title' => 'By Category']; }

    // Historical ticket metrics for selected year
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND YEAR(ticket_created_at) = $year"));
    $tickets_created_year = intval($r['c']);

    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year"));
    $tickets_resolved_year = intval($r['c']);

    $avg_resolution_hours = $dash_can_tickets ? getAvgResolutionTimeHours($mysqli, $year) : 0;

    // Resolved by technician for selected year
    $sql_resolved_by_tech = mysqli_query($mysqli, "SELECT user_name, COUNT(ticket_id) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year AND ticket_assigned_to > 0 GROUP BY user_name ORDER BY c DESC LIMIT 8");
    $resolved_tech_rows = [];
    while ($r = mysqli_fetch_assoc($sql_resolved_by_tech)) $resolved_tech_rows[] = $r;

    // Recently resolved tickets
    $sql_recent_resolved = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_subject, ticket_prefix, ticket_number, ticket_closed_at, ticket_priority, client_name, ticket_client_id, ticket_status_name, ticket_status_color FROM tickets LEFT JOIN clients ON ticket_client_id = client_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE 1 = 1 $dash_scope_tickets AND ticket_closed_at IS NOT NULL ORDER BY ticket_closed_at DESC LIMIT 10");
?>

<div class="dash-section">
    <div class="dash-tiles mb-4">
        <?php if ($dash_can_clients) { ?>
        <a class="small-box bg-secondary" href="clients.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
            <div class="inner">
                <h3><?php echo $clients_added; ?></h3>
                <p>New Departments</p>
            </div>
            <div class="icon"><i class="fa fa-users"></i></div>
        </a>

        <a class="small-box bg-success" href="contacts.php">
            <div class="inner">
                <h3><?php echo $contacts_added; ?></h3>
                <p>New Contacts</p>
            </div>
            <div class="icon"><i class="fa fa-user"></i></div>
        </a>
        <?php } ?>

        <?php if ($dash_can_assets) { ?>
        <a class="small-box bg-info" href="assets.php">
            <div class="inner">
                <h3><?php echo $assets_added; ?></h3>
                <p>New Assets</p>
            </div>
            <div class="icon"><i class="fa fa-desktop"></i></div>
        </a>
        <?php } ?>

        <?php if ($dash_can_tickets) { ?>
        <a class="small-box bg-danger" href="tickets.php">
            <div class="inner">
                <h3><?php echo $active_tickets; ?></h3>
                <p>Active Tickets</p>
            </div>
            <div class="icon"><i class="fa fa-ticket-alt"></i></div>
        </a>

        <a class="small-box bg-info" href="tickets.php">
            <div class="inner">
                <h3><?php echo $tickets_opened_today; ?></h3>
                <p>Opened Today</p>
            </div>
            <div class="icon"><i class="fa fa-plus-circle"></i></div>
        </a>

        <a class="small-box bg-success" href="tickets.php?status=Closed">
            <div class="inner">
                <h3><?php echo $tickets_resolved_week; ?></h3>
                <p>Resolved This Week</p>
            </div>
            <div class="icon"><i class="fa fa-check-circle"></i></div>
        </a>

        <a class="small-box bg-warning" href="tickets.php">
            <div class="inner">
                <h3><?php echo $tickets_waiting_customer; ?></h3>
                <p>Waiting on Employee</p>
            </div>
            <div class="icon"><i class="fa fa-clock"></i></div>
        </a>
        <?php } ?>
    </div>

    <?php if ($dash_can_tickets) { // ticket charts, history and recently resolved: Tickets/assets/docs only ?>
    <!-- Ticket Charts -->
    <?php if ($dash_has_ticket_flow) { ?>
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-fw fa-chart-line me-2"></i>Tickets Opened vs Resolved <small>(<?php echo $year; ?>)</small></h3>
            <div class="card-tools">
                <div class="d-flex align-items-center gap-1">
                    <label for="technical_chart_type" class="mb-0 small text-muted">Chart</label>
                    <select id="technical_chart_type" name="technical_chart_type" form="dashboardFiltersForm" class="form-select form-select-sm auto-submit-select">
                        <option value="bar" <?php if ($user_config_dashboard_technical_chart_type === 'bar') { echo "selected"; } ?>>Bar</option>
                        <option value="line" <?php if ($user_config_dashboard_technical_chart_type === 'line') { echo "selected"; } ?>>Line</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-h-240"><canvas id="ticketFlowChart"></canvas></div>
        </div>
    </div>
    <?php } else { ?>
    <div class="dash-empty mb-3">
        <i class="fas fa-fw fa-chart-line"></i>
        <span>No tickets were opened or resolved in <?php echo $year; ?> - the flow chart fills in once tickets start moving.</span>
    </div>
    <?php } ?>

    <?php if ($dash_ticket_breakdowns || $tech_rows) { ?>
    <div class="dash-charts mb-3">
        <?php foreach ($dash_ticket_breakdowns as $dash_bd) { ?>
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw <?= $dash_bd['icon'] ?> me-2"></i><?= $dash_bd['title'] ?></h3>
            </div>
            <div class="card-body">
                <div class="chart-h-240"><canvas id="<?= $dash_bd['id'] ?>"></canvas></div>
            </div>
        </div>
        <?php } ?>

        <?php if ($tech_rows) { ?>
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-users me-2"></i>Open Tickets by Technician</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach ($tech_rows as $tr) { ?>
                        <tr>
                            <td style="width:45%;"><?= nullable_htmlentities($tr['user_name']) ?></td>
                            <td>
                                <div class="progress" style="height:12px;">
                                    <div class="progress-bar bg-primary" style="width:<?= min(100, round($tr['c'] / max(1, $active_tickets) * 100)) ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end fw-semibold" style="width:3.5rem;"><?= intval($tr['c']) ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    </div>
    <?php } elseif ($active_tickets == 0 && $dash_has_ticket_flow) { ?>
    <?php // Only worth saying when the year DID have traffic; on an install with
          // no tickets at all the flow note above already covers it. ?>
    <div class="dash-empty mb-3">
        <i class="fas fa-fw fa-check-circle"></i>
        <span>No open tickets right now - the priority, status, category and technician breakdowns appear as soon as the queue has something in it.</span>
    </div>
    <?php } ?>

    <!-- Past / Historical Ticket Metrics -->
    <h6 class="dash-section-title"><i class="fas fa-fw fa-history"></i>Historical Tickets (<?php echo $year; ?>)</h6>

    <div class="dash-tiles mb-3">
        <a class="small-box bg-secondary" href="tickets.php">
            <div class="inner">
                <h3><?php echo $tickets_created_year; ?></h3>
                <p>Created in <?php echo $year; ?></p>
            </div>
            <div class="icon"><i class="fa fa-ticket-alt"></i></div>
        </a>

        <a class="small-box bg-success" href="tickets.php">
            <div class="inner">
                <h3><?php echo $tickets_resolved_year; ?></h3>
                <p>Resolved in <?php echo $year; ?></p>
            </div>
            <div class="icon"><i class="fa fa-check-double"></i></div>
        </a>

        <?php if ($config_dashboard_avg_resolution_enable) { ?>
        <div class="small-box bg-info">
            <div class="inner">
                <h3><?php echo $avg_resolution_hours > 0 ? $avg_resolution_hours . 'h' : 'N/A'; ?></h3>
                <p>Avg Resolution Time</p>
            </div>
            <div class="icon"><i class="fa fa-stopwatch"></i></div>
        </div>
        <?php } ?>
    </div>

    <div class="dash-charts mb-3">
        <?php if ($resolved_tech_rows) { ?>
        <?php
        // The bar reads as a share of the year's resolved tickets, so the name
        // gets a fixed 45% and the count sits outside the bar: the count used
        // to be printed inside the fill, which put it at the far right of a
        // 128px track with a 473px void beside the name.
        ?>
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-user-check me-2"></i>Resolved by Technician (<?php echo $year; ?>)</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach ($resolved_tech_rows as $tr) { ?>
                        <tr>
                            <td style="width:45%;"><?= nullable_htmlentities($tr['user_name']) ?></td>
                            <td>
                                <div class="progress" style="height:12px;">
                                    <div class="progress-bar bg-success" style="width:<?= min(100, round($tr['c'] / max(1, $tickets_resolved_year) * 100)) ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end fw-semibold" style="width:3.5rem;"><?= intval($tr['c']) ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>

        <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-check-circle me-2"></i>Recently Resolved</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Department</th>
                                <th>Resolved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $any_recent_resolved = false;
                            while ($rr = mysqli_fetch_assoc($sql_recent_resolved)) {
                                $any_recent_resolved = true;
                                $rr_tid = intval($rr['ticket_id']);
                                $rr_cid = intval($rr['ticket_client_id']);
                                $rr_prefix = nullable_htmlentities($rr['ticket_prefix']);
                                $rr_num = intval($rr['ticket_number']);
                                $rr_subj = nullable_htmlentities($rr['ticket_subject']);
                                $rr_client = nullable_htmlentities($rr['client_name']);
                                $rr_closed = timeAgo($rr['ticket_closed_at']);
                                $rr_prio = $rr['ticket_priority'];
                                $rr_pcolor = $rr_prio === 'High' ? 'danger' : ($rr_prio === 'Medium' ? 'warning' : 'info');
                            ?>
                            <tr>
                                <td><a href="ticket.php?ticket_id=<?= $rr_tid ?>&client_id=<?= $rr_cid ?>"><?= $rr_prefix . $rr_num ?></a></td>
                                <td><a href="ticket.php?ticket_id=<?= $rr_tid ?>&client_id=<?= $rr_cid ?>"><?= $rr_subj ?></a></td>
                                <td><?= $rr_client ?></td>
                                <td><?= $rr_closed ?></td>
                            </tr>
                            <?php }
                            if (!$any_recent_resolved) { ?>
                            <tr><td colspan="4" class="text-muted">No tickets resolved yet.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
        </div>
    </div>
    <?php } // $dash_can_tickets ?>

    <?php if ($your_tickets) { ?>
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-fw fa-life-ring me-2"></i>Your Open Tickets</h3>
            </div>
            <div class="table-responsive-sm">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Subject</th>
                            <th>Department</th>
                            <th>Contact</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <th>Last Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($sql_your_tickets)) {
                            $ticket_id = intval($row['ticket_id']);
                            $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                            $ticket_number = intval($row['ticket_number']);
                            $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                            $ticket_priority = nullable_htmlentities($row['ticket_priority']);
                            $ticket_status_id = intval($row['ticket_status']);
                            $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                            $ticket_status_color = nullable_htmlentities($row['ticket_status_color']);
                            $ticket_created_at = nullable_htmlentities($row['ticket_created_at']);
                            $ticket_created_at_time_ago = timeAgo($row['ticket_created_at']);
                            $ticket_updated_at = nullable_htmlentities($row['ticket_updated_at']);
                            $ticket_updated_at_time_ago = timeAgo($row['ticket_updated_at']);

                            $ticket_updated_at_display = empty($ticket_updated_at) ? (empty($ticket_closed_at) ? "<p class='text-danger'>Never</p>" : "<p>Never</p>") : $ticket_updated_at_time_ago;

                            $client_id = intval($row['ticket_client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);
                            $contact_id = intval($row['ticket_contact_id']);
                            $contact_name = nullable_htmlentities($row['contact_name']);
                            if ($client_id) {
                                $has_client = "&client_id=$client_id";
                            } else {
                                $has_client = "";
                            }

                            $ticket_priority_color = $ticket_priority == "High" ? "danger" : ($ticket_priority == "Medium" ? "warning" : "info");
                            $contact_display = empty($contact_name) ? "-" : "<a href='contact_details.php?client_id=$client_id&contact_id=$contact_id'>$contact_name</a>";

                            $_sla_due = null;
                            if (empty($row['ticket_first_response_at']) && !empty($row['ticket_sla_response_due'])) {
                                $_sla_due = $row['ticket_sla_response_due'];
                            } elseif (!empty($row['ticket_sla_resolution_due'])) {
                                $_sla_due = $row['ticket_sla_resolution_due'];
                            }
                            $sla_badge = "<span class='text-muted'>-</span>";
                            if ($_sla_due) {
                                $_sla_state = slaDueState($mysqli, $row, $_sla_due);
                                $_sla_breached = $_sla_state['breached'];
                                $_sla_color = $_sla_breached ? 'danger' : ($_sla_state['paused'] ? 'secondary' : ($_sla_state['remaining'] < 7200 ? 'warning' : 'success'));
                                $_sla_label = $_sla_breached ? 'Breached' : ($_sla_state['paused'] ? 'Paused' : 'OK');
                                $sla_badge = "<span class='badge rounded-pill text-bg-$_sla_color'>$_sla_label</span>";
                            }
                        ?>
                            <tr class="<?php echo empty($ticket_updated_at) ? 'fw-bold' : ''; ?>">
                                <td>
                                    <a class="text-dark"
                                        href="ticket.php?ticket_id=<?= "$ticket_id$has_client" ?>"><?= "$ticket_prefix$ticket_number" ?>
                                    </a>
                                </td>
                                <td><a href="ticket.php?ticket_id=<?= "$ticket_id$has_client" ?>"><?= $ticket_subject ?></a></td>
                                <td><a href="tickets.php?client_id=<?php echo $client_id; ?>"><strong><?php echo $client_name; ?></strong></a></td>
                                <td><?php echo $contact_display; ?></td>
                                <td><span class='p-2 badge rounded-pill text-bg-<?php echo $ticket_priority_color; ?>'><?php echo $ticket_priority; ?></span></td>
                                <td><span class='badge rounded-pill <?php echo tagTextClass($ticket_status_color); ?> p-2' style="background-color: <?php echo $ticket_status_color; ?>"><?php echo $ticket_status_name; ?></span></td>
                                <td><?php echo $sla_badge; ?></td>
                                <td><?php echo $ticket_updated_at_display; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php } ?>

    <?php if (mysqli_num_rows($sql_your_automation_runs) > 0) { ?>
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Automation Activity</h3>
            </div>
            <div class="table-responsive-sm">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Rule</th>
                            <th>Trigger</th>
                            <th>Ticket</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $automation_trigger_labels = [
                            'schedule'      => 'Scheduled check',
                            'rmm_alert'     => 'New RMM alert',
                            'asset_offline' => 'Asset offline',
                            'asset_online'  => 'Asset online',
                        ];
                        while ($ar = mysqli_fetch_assoc($sql_your_automation_runs)) {
                            $ar_time_ago = timeAgo($ar['created_at']);
                            $ar_rule_name = nullable_htmlentities($ar['rule_name']);
                            $ar_trigger = $automation_trigger_labels[$ar['trigger_type']] ?? nullable_htmlentities($ar['trigger_type']);
                            $ar_summary = nullable_htmlentities($ar['summary']);
                            $ar_ticket_id = intval($ar['ticket_id']);
                            $ar_ticket_client_id = intval($ar['ticket_client_id']);
                            $ar_ticket_label = nullable_htmlentities($ar['ticket_prefix'] . $ar['ticket_number']);
                            $ar_ticket_subject = nullable_htmlentities($ar['ticket_subject']);
                        ?>
                            <tr>
                                <td><?php echo $ar_time_ago; ?></td>
                                <td><?php echo $ar_rule_name; ?></td>
                                <td><span class="badge text-bg-info"><?php echo $ar_trigger; ?></span></td>
                                <td>
                                    <?php if ($ar_ticket_id) { ?>
                                        <a href="ticket.php?ticket_id=<?php echo $ar_ticket_id; ?>&client_id=<?php echo $ar_ticket_client_id; ?>">
                                            <?php echo $ar_ticket_label; ?> <?php echo $ar_ticket_subject; ?>
                                        </a>
                                    <?php } else { ?>
                                        -
                                    <?php } ?>
                                </td>
                                <td><?php echo $ar_summary; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php } ?>
</div> <!-- .dash-section -->

<?php } ?>

<?php require_once "../includes/footer.php"; ?>

<?php // Same combined gate as the render block above - these <script> blocks read
      // PHP variables ($largest_income_month etc.) that only exist when that
      // block actually ran, so the two conditions must stay identical. ?>
<?php if ($user_config_dashboard_financial_enable == 1 && $config_module_enable_accounting == 1 && $dash_can_finance) { ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap-like defaults for Chart.js v4

    // CASH FLOW
    (function () {
        var ctx = document.getElementById("cashFlow");
        if (!ctx) return;

        var myLineChart = new Chart(ctx, {
            // Reads the saved per-user preference (financial_chart_type
            // select next to the "Financial" switch above); validated
            // server-side against ['line','bar'] before it ever lands here.
            // Bar mode needs its own backgroundColor per dataset below - the
            // line-only styling (fill/point*) is simply ignored by the bar
            // controller, but a bar with no backgroundColor falls back to
            // Chart.js's translucent default and every series reads as the
            // same grey block.
            type: '<?php echo $user_config_dashboard_financial_chart_type; ?>',
            data: {
                labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
                datasets: [
                    {
                        label: "Income",
                        fill: false,
                        borderColor: "#007bff",
                        backgroundColor: "#007bff",
                        pointBackgroundColor: "#007bff",
                        pointBorderColor: "#007bff",
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: "#007bff",
                        pointBorderWidth: 2,
                        data: [
                            <?php
                            for ($month = 1; $month <= 12; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payments_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id > 0 AND YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenues_for_month = floatval($row['revenue_amount_for_month']);

                                $income_for_month = $payments_for_month + $revenues_for_month;

                                if ($income_for_month > 0 && $income_for_month > $largest_income_month) {
                                    $largest_income_month = $income_for_month;
                                }
                                echo "$income_for_month,";
                            }
                            ?>
                        ],
                    },
                    {
                        label: "LY Income",
                        fill: false,
                        borderColor: "#9932CC",
                        backgroundColor: "#9932CC",
                        pointBackgroundColor: "#9932CC",
                        pointBorderColor: "#9932CC",
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: "#9932CC",
                        pointBorderWidth: 2,
                        data: [
                            <?php
                            for ($month = 1; $month <= 12; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year-1 AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payments_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id > 0 AND YEAR(revenue_date) = $year-1 AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenues_for_month = floatval($row['revenue_amount_for_month']);

                                $income_for_month = $payments_for_month + $revenues_for_month;

                                if ($income_for_month > 0 && $income_for_month > $largest_income_month) {
                                    $largest_income_month = $income_for_month;
                                }
                                echo "$income_for_month,";
                            }
                            ?>
                        ],
                    },
                    {
                        label: "Projected",
                        fill: false,
                        borderColor: "black",
                        backgroundColor: "black",
                        pointBackgroundColor: "black",
                        pointBorderColor: "black",
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: "black",
                        pointBorderWidth: 2,
                        data: [
                            <?php
                            $largest_invoice_month = 0;
                            for ($month = 1; $month <= 12; $month++) {
                                $sql_projected = mysqli_query($mysqli, "SELECT SUM(invoice_amount) AS invoice_amount_for_month FROM invoices WHERE YEAR(invoice_due) = $year AND MONTH(invoice_due) = $month AND invoice_status != 'Cancelled' AND invoice_status != 'Draft' AND invoice_status != 'Non-Billable'");
                                $row = mysqli_fetch_assoc($sql_projected);
                                $invoice_for_month = floatval($row['invoice_amount_for_month']);

                                if ($invoice_for_month > 0 && $invoice_for_month > $largest_invoice_month) {
                                    $largest_invoice_month = $invoice_for_month;
                                }
                                echo "$invoice_for_month,";
                            }
                            ?>
                        ],
                    },
                    {
                        label: "Expense",
                        tension: 0.3, // v4 name; v2 used lineTension
                        fill: false,
                        borderColor: "#dc3545",
                        backgroundColor: "#dc3545",
                        pointBackgroundColor: "#dc3545",
                        pointBorderColor: "#dc3545",
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: "#dc3545",
                        pointBorderWidth: 2,
                        data: [
                            <?php
                            $largest_expense_month = 0;
                            for ($month = 1; $month <= 12; $month++) {
                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month AND expense_vendor_id > 0");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $expenses_for_month = floatval($row['expense_amount_for_month']);

                                if ($expenses_for_month > 0 && $expenses_for_month > $largest_expense_month) {
                                    $largest_expense_month = $expenses_for_month;
                                }
                                echo "$expenses_for_month,";
                            }
                            ?>
                        ],
                    }
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        min: 0,
                        max: <?php $max = max(1000, $largest_expense_month, $largest_income_month, $largest_invoice_month); echo roundUpToNearestMultiple($max); ?>,
                        ticks: { maxTicksLimit: 5 },
                        grid: { color: "rgba(0, 0, 0, .125)" }
                    }
                },
                plugins: {
                    legend: { display: true }
                }
            }
        });
    })();

    // TRIP FLOW
    (function () {
        var ctx = document.getElementById("tripFlow");
        if (!ctx) return;

        var myLineChart = new Chart(ctx, {
            // Same saved Financial preference as Cash Flow above - the two are
            // "the two line charts" this control governs, kept in lockstep.
            type: '<?php echo $user_config_dashboard_financial_chart_type; ?>',
            data: {
                labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
                datasets: [{
                    label: "Trip",
                    tension: 0.3,
                    fill: false,
                    backgroundColor: "red",
                    borderColor: "darkred",
                    pointRadius: 5,
                    pointBackgroundColor: "red",
                    pointBorderColor: "red",
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: "darkred",
                    pointBorderWidth: 2,
                    data: [
                        <?php
                        $largest_trip_miles_month = 0;
                        for ($month = 1; $month <= 12; $month++) {
                            $sql_trips = mysqli_query($mysqli, "SELECT SUM(trip_miles) AS trip_miles_for_month FROM trips WHERE YEAR(trip_date) = $year AND MONTH(trip_date) = $month");
                            $row = mysqli_fetch_assoc($sql_trips);
                            $trip_miles_for_month = floatval($row['trip_miles_for_month']);

                            if ($trip_miles_for_month > 0 && $trip_miles_for_month > $largest_trip_miles_month) {
                                $largest_trip_miles_month = $trip_miles_for_month;
                            }
                            echo "$trip_miles_for_month,";
                        }
                        ?>
                    ],
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        min: 0,
                        max: <?php $max = max(1000, $largest_trip_miles_month); echo roundUpToNearestMultiple($max); ?>,
                        ticks: { maxTicksLimit: 5 },
                        grid: { color: "rgba(0, 0, 0, .125)" }
                    },
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    })();

    // INCOME BY CATEGORY (Doughnut)
    (function () {
        var ctx = document.getElementById("incomeByCategoryPieChart");
        if (!ctx) return;

        var myPieChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php
                    mysqli_query($mysqli, "CREATE TEMPORARY TABLE TopCategories SELECT category_name, category_id, SUM(invoice_amount) AS total_income FROM categories, invoices WHERE invoice_category_id = category_id AND invoice_status = 'Paid' AND YEAR(invoice_date) = $year GROUP BY category_name, category_id ORDER BY total_income DESC LIMIT 5");
                    $sql_categories = mysqli_query($mysqli, "SELECT category_name FROM TopCategories");
                    while ($row = mysqli_fetch_assoc($sql_categories)) {
                        $category_name = json_encode($row['category_name']);
                        echo "$category_name,";
                    }

                    $sql_other_categories = mysqli_query($mysqli, "SELECT SUM(invoices.invoice_amount) AS other_income FROM categories LEFT JOIN TopCategories ON categories.category_id = TopCategories.category_id INNER JOIN invoices ON categories.category_id = invoices.invoice_category_id WHERE TopCategories.category_id IS NULL AND invoice_status = 'Paid' AND YEAR(invoice_date) = $year");
                    $row = mysqli_fetch_assoc($sql_other_categories);
                    $other_income = floatval($row['other_income']);
                    if ($other_income > 0) {
                        echo "'Others',";
                    }
                    ?>
                ],
                datasets: [{
                    data: [
                        <?php
                        $sql_categories = mysqli_query($mysqli, "SELECT total_income FROM TopCategories");
                        while ($row = mysqli_fetch_assoc($sql_categories)) {
                            $total_income = floatval($row['total_income']);
                            echo "$total_income,";
                        }
                        if ($other_income > 0) {
                            echo "$other_income,";
                        }
                        ?>
                    ],
                    backgroundColor: [
                        <?php
                        $sql_categories = mysqli_query($mysqli, "SELECT category_color FROM TopCategories JOIN categories ON TopCategories.category_id = categories.category_id");
                        while ($row = mysqli_fetch_assoc($sql_categories)) {
                            $category_color = json_encode($row['category_color']);
                            echo "$category_color,";
                        }
                        if ($other_income > 0) {
                            echo "'#999999',"; // color for 'Others'
                        }
                        ?>
                    ],
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'right'
                    }
                }
            }
        });
    })();

    // EXPENSE BY CATEGORY (Doughnut)
    (function () {
        var ctx = document.getElementById("expenseByCategoryPieChart");
        if (!ctx) return;

        var myPieChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php
                    mysqli_query($mysqli, "CREATE TEMPORARY TABLE TopExpenseCategories SELECT category_name, category_id, SUM(expense_amount) AS total_expense FROM categories, expenses WHERE expense_category_id = category_id AND expense_vendor_id > 0 AND YEAR(expense_date) = $year GROUP BY category_name, category_id ORDER BY total_expense DESC LIMIT 5");
                    $sql_categories = mysqli_query($mysqli, "SELECT category_name FROM TopExpenseCategories");
                    while ($row = mysqli_fetch_assoc($sql_categories)) {
                        $category_name = json_encode($row['category_name']);
                        echo "$category_name,";
                    }

                    $sql_other_categories = mysqli_query($mysqli, "SELECT SUM(expenses.expense_amount) AS other_expense FROM categories LEFT JOIN TopExpenseCategories ON categories.category_id = TopExpenseCategories.category_id INNER JOIN expenses ON categories.category_id = expenses.expense_category_id WHERE TopExpenseCategories.category_id IS NULL AND expense_vendor_id > 0 AND YEAR(expense_date) = $year");
                    $row = mysqli_fetch_assoc($sql_other_categories);
                    $other_expense = floatval($row['other_expense']);
                    if ($other_expense > 0) {
                        echo "'Others',";
                    }
                    ?>
                ],
                datasets: [{
                    data: [
                        <?php
                        $sql_categories = mysqli_query($mysqli, "SELECT total_expense FROM TopExpenseCategories");
                        while ($row = mysqli_fetch_assoc($sql_categories)) {
                            $total_expense = floatval($row['total_expense']);
                            echo "$total_expense,";
                        }
                        if ($other_expense > 0) {
                            echo "$other_expense,";
                        }
                        ?>
                    ],
                    backgroundColor: [
                        <?php
                        $sql_categories = mysqli_query($mysqli, "SELECT category_color FROM TopExpenseCategories JOIN categories ON TopExpenseCategories.category_id = categories.category_id");
                        while ($row = mysqli_fetch_assoc($sql_categories)) {
                            $category_color = json_encode($row['category_color']);
                            echo "$category_color,";
                        }
                        if ($other_expense > 0) {
                            echo "'#999999',"; // color for 'Others'
                        }
                        ?>
                    ],
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'right'
                    }
                }
            }
        });
    })();

    // EXPENSE BY VENDOR (Doughnut)
    (function () {
        var ctx = document.getElementById("expenseByVendorPieChart");
        if (!ctx) return;

        var myPieChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php
                    mysqli_query($mysqli, "CREATE TEMPORARY TABLE TopVendors SELECT vendor_name, vendor_id, SUM(expense_amount) AS total_expense FROM vendors, expenses WHERE expense_vendor_id = vendor_id AND YEAR(expense_date) = $year GROUP BY vendor_name, vendor_id ORDER BY total_expense DESC LIMIT 5");
                    $sql_vendors = mysqli_query($mysqli, "SELECT vendor_name FROM TopVendors");
                    while ($row = mysqli_fetch_assoc($sql_vendors)) {
                        $vendor_name = json_encode($row['vendor_name']);
                        echo "$vendor_name,";
                    }

                    $sql_other_vendors = mysqli_query($mysqli, "SELECT SUM(expenses.expense_amount) AS other_expense FROM vendors LEFT JOIN TopVendors ON vendors.vendor_id = TopVendors.vendor_id INNER JOIN expenses ON vendors.vendor_id = expenses.expense_vendor_id WHERE TopVendors.vendor_id IS NULL AND YEAR(expense_date) = $year");
                    $row = mysqli_fetch_assoc($sql_other_vendors);
                    $other_expense = floatval($row['other_expense']);
                    if ($other_expense > 0) {
                        echo "'Others',";
                    }
                    ?>
                ],
                datasets: [{
                    data: [
                        <?php
                        $sql_vendors = mysqli_query($mysqli, "SELECT total_expense FROM TopVendors");
                        while ($row = mysqli_fetch_assoc($sql_vendors)) {
                            $total_expense = floatval($row['total_expense']);
                            echo "$total_expense,";
                        }
                        if ($other_expense > 0) {
                            echo "$other_expense,";
                        }
                        ?>
                    ],
                    backgroundColor: [
                        <?php
                        $sql_vendors = mysqli_query($mysqli, "SELECT vendor_id FROM TopVendors");
                        while ($row = mysqli_fetch_assoc($sql_vendors)) {
                            // Generate random color for each vendor
                            echo "'#" . substr(md5(rand()), 0, 6) . "',";
                        }
                        if ($other_expense > 0) {
                            echo "'#999999',"; // color for 'Others'
                        }
                        ?>
                    ],
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'right'
                    }
                }
            }
        });
    })();
});
</script>

<?php } ?>

<?php if (true) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {

// Chart ink comes from the theme, not from hardcoded hexes: the axis ticks and
// grid lines used to be rgba(0,0,0,.1) / Chart.js defaults, which are invisible
// on the dark surface. Series colours are deliberate and stay fixed in both
// themes - #2a78d6 / #0ca30c are the same two hues the "Open Tickets" and
// "Resolved This Week" tiles wear, and they clear a colourblind-separation
// check that the old red/green pair failed badly (deuteranopia dE 3.5).
var dashTheme = (function () {
    var cs = getComputedStyle(document.documentElement);
    var tok = function (name, fallback) { return (cs.getPropertyValue(name) || '').trim() || fallback; };
    return {
        ink:     tok('--if-ink', '#16232a'),
        muted:   tok('--if-muted', '#5d6f76'),
        line:    tok('--if-border', '#e3e9ea'),
        surface: tok('--if-surface', '#ffffff'),
        opened:   '#2a78d6',
        resolved: '#0ca30c'
    };
})();

// TICKET FLOW (Opened vs Resolved)
// Bars, not filled areas. Two translucent area fills composited into a flat
// khaki band wherever the series overlapped - which for an opened-vs-resolved
// pair is the normal case - and Chart.js drew the legend key as that same ~8%
// fill inside a 3px line-coloured border, so each key read as an empty
// checkbox. Monthly counts are discrete anyway: a smoothed line drew a bell
// curve through August and October that never happened.
(function() {
    var ctx = document.getElementById('ticketFlowChart');
    if (!ctx) return;
    new Chart(ctx, {
        // Reads the saved Technical preference (technical_chart_type select
        // next to the "Technical" switch above; validated server-side
        // against ['bar','line']). If it's switched to 'line', `fill: false`
        // plus an explicit `borderColor` on both datasets below are what
        // avoid the exact two defects the comment above the bar version was
        // written to fix: a filled area chart composites two overlapping
        // fills into a khaki band and draws its legend key as that same
        // translucent box, and Chart.js v4 already defaults line tension to
        // 0 (straight segments) so the "bell curve" smoothing doesn't come
        // back either. `borderRadius`/`borderSkipped`/`maxBarThickness` below
        // are bar-only options the line controller just ignores.
        type: '<?php echo $user_config_dashboard_technical_chart_type; ?>',
        data: {
            labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
            datasets: [
                {
                    label: 'Opened',
                    backgroundColor: dashTheme.opened,
                    hoverBackgroundColor: dashTheme.opened,
                    borderColor: dashTheme.opened,
                    borderRadius: 4,
                    borderSkipped: 'bottom',
                    maxBarThickness: 22,
                    fill: false,
                    pointRadius: 3,
                    pointBackgroundColor: dashTheme.opened,
                    data: [<?php echo implode(',', $monthly_opened); ?>]
                },
                {
                    label: 'Resolved',
                    backgroundColor: dashTheme.resolved,
                    hoverBackgroundColor: dashTheme.resolved,
                    borderColor: dashTheme.resolved,
                    borderRadius: 4,
                    borderSkipped: 'bottom',
                    maxBarThickness: 22,
                    fill: false,
                    pointRadius: 3,
                    pointBackgroundColor: dashTheme.resolved,
                    data: [<?php echo implode(',', $monthly_resolved); ?>]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false }, border: { color: dashTheme.line }, ticks: { color: dashTheme.muted } },
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: dashTheme.line },
                    ticks: { color: dashTheme.muted, precision: 0, maxTicksLimit: 5 }
                }
            },
            plugins: {
                legend: {
                    display: true, position: 'top', align: 'end',
                    labels: {
                        color: dashTheme.ink, usePointStyle: true, pointStyle: 'rectRounded',
                        boxWidth: 10, boxHeight: 10, padding: 16
                    }
                },
                tooltip: { boxPadding: 4 }
            }
        }
    });
})();

// Open-ticket breakdowns. Each canvas only exists when its query returned rows
// (see $dash_ticket_breakdowns), so these are no-ops rather than blank charts
// when the queue is empty. The 2px ring in the surface colour separates
// adjacent slices instead of letting two similar status colours run together.
function dashDoughnut(id, labels, data, colors) {
    var ctx = document.getElementById(id);
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderColor: dashTheme.surface,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: {
                    display: true, position: 'right',
                    labels: {
                        color: dashTheme.ink, usePointStyle: true, pointStyle: 'circle',
                        boxWidth: 8, boxHeight: 8, padding: 12
                    }
                }
            }
        }
    });
}

dashDoughnut(
    'ticketPriorityChart',
    [<?php echo implode(',', array_map('json_encode', $priority_labels)); ?>],
    [<?php echo implode(',', $priority_counts); ?>],
    <?php
        // Priority is a status scale, so it keeps the app's status hues rather
        // than a categorical ramp: attention now / attention soon / routine.
        $pc = [];
        foreach ($priority_labels as $p) {
            $pc[] = $p === 'High' ? '"#d03b3b"' : ($p === 'Medium' ? '"#fab219"' : '"#2a78d6"');
        }
        echo '[' . implode(',', $pc) . ']';
    ?>
);

dashDoughnut(
    'ticketStatusChart',
    [<?php echo implode(',', array_map('json_encode', $status_labels)); ?>],
    [<?php echo implode(',', $status_counts); ?>],
    [<?php echo implode(',', array_map('json_encode', $status_colors)); ?>]
);

dashDoughnut(
    'ticketCategoryChart',
    [<?php echo implode(',', array_map('json_encode', $cat_labels)); ?>],
    [<?php echo implode(',', $cat_counts); ?>],
    [<?php echo implode(',', array_map('json_encode', $cat_colors)); ?>]
);

});
</script>
<?php } ?>
