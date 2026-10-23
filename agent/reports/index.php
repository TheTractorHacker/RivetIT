<?php

require_once "includes/inc_all_reports.php";

?>

<?php
$reports_show_technical = ($config_module_enable_ticketing == 1 && lookupUserPermission('module_support') >= 1);
if ($reports_show_technical) {
    $reports_open_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL" . \ITFlow\Reports\ReportScope::clause('ticket_client_id')))[0]);
    $reports_unassigned_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0)" . \ITFlow\Reports\ReportScope::clause('ticket_client_id')))[0]);
    $reports_opened_today = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE DATE(ticket_created_at) = CURDATE()" . \ITFlow\Reports\ReportScope::clause('ticket_client_id')))[0]);
    $reports_csat_avg = null;
    if (!empty($config_ticket_csat_enable)) {
        $reports_csat_avg_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT AVG(ticket_csat_rating) AS v FROM tickets WHERE ticket_csat_rated_at >= NOW() - INTERVAL 30 DAY" . \ITFlow\Reports\ReportScope::clause('ticket_client_id')));
        $reports_csat_avg = $reports_csat_avg_row['v'] !== null ? round(floatval($reports_csat_avg_row['v']), 2) : null;
    }
}

// ---------------------------------------------------------------------------
// Report catalog.
//
// The hub used to emit KPI tiles and nothing else: three tiles pointing at two
// distinct reports, floating over ~800px of empty background, with the other
// twenty reports reachable only from the left menu. The catalog below is that
// menu made visible - same files, same order, same permission gates as
// includes/reports_side_nav.php - plus a one-line description of what each
// report answers, so the landing page is a directory rather than a dead end.
//
// Each item: [file, icon class, name, description]. Keep in sync with the
// sidebar when a report is added or removed.
// ---------------------------------------------------------------------------
$report_catalog = [];

$report_catalog_technical = [];
if ($reports_show_technical) {
    $report_catalog_technical[] = ['service_desk.php',            'fas fa-headset',       'Service Desk &amp; SLA',   'Queue health, ticket aging and SLA breaches.'];
    $report_catalog_technical[] = ['ticket_summary.php',          'fas fa-life-ring',     'Tickets',                  'Volume by status, priority and month.'];
    $report_catalog_technical[] = ['ticket_day_breakdown.php',    'fas fa-calendar-day',  'Tickets: Day by Day',      'Created vs. closed, one row per day.'];
    $report_catalog_technical[] = ['ticket_by_client.php',        'fas fa-users',         'Tickets by Department',    'Which departments raise the most work.'];
    $report_catalog_technical[] = ['time_by_tech.php',            'fas fa-business-time', 'Time by Technician',       'Hours logged per technician, per year.'];
    $report_catalog_technical[] = ['technician_performance.php',  'fas fa-user-clock',    'Technician Performance',   'Utilization, tickets closed and handle time.'];
    $report_catalog_technical[] = ['csat.php',                    'fas fa-star',          'Customer Satisfaction',    'Ratings, trend and per-technician CSAT.'];
    $report_catalog_technical[] = ['rmm_health.php',              'fas fa-heartbeat',     'RMM Health',               'Alert volume, severity and noisiest devices.'];
    $report_catalog_technical[] = ['client_ticket_time_detail.php', 'fas fa-history',     'Department Time Detail Audit',    'Every time entry behind a department&rsquo;s hours.'];
    $report_catalog_technical[] = ['included_issues.php',         'fas fa-house-user',    'Included Support Issues', 'Remote and onsite hours included per department.'];
}
if (lookupUserPermission('module_credential') >= 1) {
    $report_catalog_technical[] = ['credential_rotation.php',     'fas fa-key',           'Credential rotation',      'Credentials not changed in the rotation window.'];
    $report_catalog_technical[] = ['credential_rotation_v2.php',  'fas fa-history',       'Credential rotation due',  'Credentials overdue, or due for rotation soon.'];
}
if (!empty($report_catalog_technical)) {
    $report_catalog[] = ['title' => 'Technical', 'items' => $report_catalog_technical];
}

$report_catalog[] = ['title' => 'Delivery', 'items' => [
    ['schedules.php', 'fas fa-paper-plane', 'Scheduled Reports', 'Reports emailed on a recurring schedule.'],
]];


render_page_header(
    'Reports',
    'Every report you have access to, grouped by area. Reporting permission plus read access to that area (support or financial) decides what appears here.'
);
?>

<style nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
  /* Report catalog tiles. Same visual family as .it-stat-card (surface, border,
     radius, hover lift) but list-density rather than KPI-density, so a directory
     of ~20 entries stays scannable. Tokens only - no raw colors. */
  .rc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(15.5rem, 1fr)); gap: .75rem; }
  .rc-item {
      display: flex; align-items: flex-start; gap: .7rem;
      padding: .8rem .9rem; background: var(--if-surface);
      border: 1px solid var(--if-border); border-radius: var(--if-radius-sm);
      color: inherit; text-decoration: none !important;
      transition: box-shadow .15s ease, border-color .15s ease, transform .15s ease;
  }
  .rc-item:hover, .rc-item:focus-visible { border-color: var(--if-border-strong); box-shadow: var(--if-shadow); transform: translateY(-1px); }
  .rc-icon {
      width: 1.9rem; height: 1.9rem; min-width: 1.9rem; border-radius: var(--if-radius-sm);
      display: flex; align-items: center; justify-content: center; font-size: .8rem;
      background: var(--if-bg); color: var(--if-muted);
  }
  /* The red accent stays out of the resting state and only marks intent on hover. */
  .rc-item:hover .rc-icon, .rc-item:focus-visible .rc-icon { background: var(--if-primary-soft); color: var(--if-primary); }
  .rc-title { display: block; font-weight: 600; font-size: .875rem; line-height: 1.25; }
  .rc-desc { display: block; font-size: .75rem; color: var(--if-muted); line-height: 1.35; margin-top: .15rem; }

  /* Put the KPI row on the same column rhythm as the catalog beneath it, so the
     whole hub reads as one grid instead of two unrelated ones. Page-scoped -
     .it-stat-grid keeps its own auto-fit sizing everywhere else. */
  .it-stat-grid { grid-template-columns: repeat(auto-fill, minmax(15.5rem, 1fr)); gap: .75rem; }
</style>

<?php if ($reports_show_technical) { ?>
<div class="it-section">
    <div class="it-section-header">Snapshot</div>
    <div class="it-stat-grid">
        <?php if ($reports_show_technical) { ?>
            <?php // Tints are semantic, and only one thing on this row is actually wrong:
                  // open/opened-today are neutral counts, unassigned is the alarm. ?>
            <?php render_stat_card('Open Tickets', (string) $reports_open_tickets, 'fas fa-life-ring', 'info', '/agent/reports/ticket_summary.php'); ?>
            <?php render_stat_card('Unassigned Tickets', (string) $reports_unassigned_tickets, 'fas fa-user-slash', 'danger', '/agent/reports/service_desk.php'); ?>
            <?php render_stat_card('Opened Today', (string) $reports_opened_today, 'fas fa-calendar-day', 'slate', '/agent/reports/ticket_summary.php'); ?>
            <?php if (!empty($config_ticket_csat_enable)) { ?>
            <?php render_stat_card('CSAT (30 days)', $reports_csat_avg !== null ? $reports_csat_avg . '/5' : '—', 'fas fa-star', 'warning', '/agent/reports/csat.php'); ?>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php foreach ($report_catalog as $report_group) { ?>
<div class="it-section">
    <div class="it-section-header"><?php echo $report_group['title']; ?></div>
    <div class="rc-grid">
        <?php foreach ($report_group['items'] as $report_item) { ?>
        <a class="rc-item" href="/agent/reports/<?php echo $report_item[0]; ?>">
            <span class="rc-icon"><i class="<?php echo $report_item[1]; ?>"></i></span>
            <span>
                <span class="rc-title"><?php echo $report_item[2]; ?></span>
                <span class="rc-desc"><?php echo $report_item[3]; ?></span>
            </span>
        </a>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!$reports_show_technical) {
    render_empty_state(
        'fas fa-lock',
        'No reporting area unlocked yet',
        "You don't currently have read access to a specific reporting area, so there are no figures to summarise here. Ask an administrator for Support reporting permission; anything you can already open is listed above."
    );
} ?>

<?php require_once "../../includes/footer.php"; ?>
