<?php
/*
 * Client Portal
 * Landing / Home page for the client portal
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

/*
 * There are deliberately no billing queries here.
 *
 * This page used to open with four aggregate queries - SUM over invoices, SUM
 * over payments, and two SUMs over recurring_invoices - purely to fill two stat
 * boxes, "Account Balance Due" and "Recurring Monthly". Both boxes are gone:
 * this edition has no billing, invoicing or quoting, and the accounting module
 * is force-disabled. The queries went with them rather than being left to run
 * on every portal home page load for a number nothing renders.
 *
 * The invoices, payments and recurring_invoices tables are still there and the
 * page files that read them are still on disk - the scope for accounting removal
 * on this fork is "hide the surface", so the MSP fork can port back. Nothing
 * here needs restoring to bring them back; restore the two boxes below and these
 * queries with them.
 */

// Technical Card Queries
// 8 - 45 Day Warning

// Get Domains Expiring
$sql_domains_expiring = mysqli_query(
    $mysqli,
    "SELECT * FROM domains
    WHERE domain_client_id = $session_client_id
        AND domain_expire IS NOT NULL
        AND domain_archived_at IS NULL
        AND domain_expire > CURRENT_DATE
        AND domain_expire < CURRENT_DATE + INTERVAL 45 DAY
    ORDER BY domain_expire ASC"
);

// Get Certificates Expiring
$sql_certificates_expiring = mysqli_query(
    $mysqli,
    "SELECT * FROM certificates
    WHERE certificate_client_id = $session_client_id
        AND certificate_expire IS NOT NULL
        AND certificate_archived_at IS NULL
        AND certificate_expire > CURRENT_DATE
        AND certificate_expire < CURRENT_DATE + INTERVAL 45 DAY
    ORDER BY certificate_expire ASC"
);

// Get Licenses Expiring
$sql_licenses_expiring = mysqli_query(
    $mysqli,
    "SELECT * FROM software
    WHERE software_client_id = $session_client_id
        AND software_expire IS NOT NULL
        AND software_archived_at IS NULL
        AND software_expire > CURRENT_DATE
        AND software_expire < CURRENT_DATE + INTERVAL 45 DAY
    ORDER BY software_expire ASC"
);

// Get Asset Warranties Expiring
$sql_asset_warranties_expiring = mysqli_query(
    $mysqli,
    "SELECT * FROM assets
    WHERE asset_client_id = $session_client_id
        AND asset_warranty_expire IS NOT NULL
        AND asset_archived_at IS NULL
        AND asset_warranty_expire > CURRENT_DATE
        AND asset_warranty_expire < CURRENT_DATE + INTERVAL 45 DAY
    ORDER BY asset_warranty_expire ASC"
);

// Get Assets Retiring 7 Year
$sql_asset_retire = mysqli_query(
    $mysqli,
    "SELECT * FROM assets
    WHERE asset_client_id = $session_client_id
        AND asset_install_date IS NOT NULL
        AND asset_archived_at IS NULL
        AND asset_install_date + INTERVAL 7 YEAR > CURRENT_DATE
        AND asset_install_date + INTERVAL 7 YEAR <= CURRENT_DATE + INTERVAL 45 DAY
    ORDER BY asset_install_date ASC"
);

/*
 * EXPIRED ITEMS
 */

// Get Domains Expired
$sql_domains_expired = mysqli_query(
    $mysqli,
    "SELECT * FROM domains
    WHERE domain_client_id = $session_client_id
        AND domain_expire IS NOT NULL
        AND domain_archived_at IS NULL
        AND domain_expire < CURRENT_DATE
    ORDER BY domain_expire ASC"
);

// Get Certificates Expired
$sql_certificates_expired = mysqli_query(
    $mysqli,
    "SELECT * FROM certificates
    WHERE certificate_client_id = $session_client_id
        AND certificate_expire IS NOT NULL
        AND certificate_archived_at IS NULL
        AND certificate_expire < CURRENT_DATE
    ORDER BY certificate_expire ASC"
);

// Get Licenses Expired
$sql_licenses_expired = mysqli_query(
    $mysqli,
    "SELECT * FROM software
    WHERE software_client_id = $session_client_id
        AND software_expire IS NOT NULL
        AND software_archived_at IS NULL
        AND software_expire < CURRENT_DATE
    ORDER BY software_expire ASC"
);

// Get Asset Warranties Expired
$sql_asset_warranties_expired = mysqli_query(
    $mysqli,
    "SELECT * FROM assets
    WHERE asset_client_id = $session_client_id
        AND asset_warranty_expire IS NOT NULL
        AND asset_archived_at IS NULL
        AND asset_warranty_expire < CURRENT_DATE
    ORDER BY asset_warranty_expire ASC"
);

// Get Retired Assets
$sql_asset_retired = mysqli_query(
    $mysqli,
    "SELECT * FROM assets
    WHERE asset_client_id = $session_client_id
        AND asset_install_date IS NOT NULL
        AND asset_archived_at IS NULL
        AND asset_install_date + INTERVAL 7 YEAR < CURRENT_DATE  -- Assets retired (installed more than 7 years ago)
    ORDER BY asset_install_date ASC"
);

// Assigned Assets
$sql_assigned_assets = mysqli_query(
    $mysqli,
    "SELECT * FROM assets
    WHERE asset_contact_id = $session_contact_id
        AND $session_contact_id > 0
        AND asset_client_id = $session_client_id
        AND asset_archived_at IS NULL
    ORDER BY asset_name ASC"
);

// Open ticket count for this contact
$sql_open_tickets_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id AND ticket_closed_at IS NULL");
$row = mysqli_fetch_assoc($sql_open_tickets_count);
$open_tickets_count = intval($row['c']);

// Recent tickets for this contact - ordered by last activity (ticket_updated_at,
// which the table already displays as "Last Update"), not creation order. A
// ticket sorted by ticket_id DESC drops off this top-5 the moment 5 other
// tickets get CREATED after it, even if it was just closed and those other
// ones are untouched - so a just-closed ticket could be visible under the
// Closed filter on tickets.php but missing here. ticket_updated_at has
// ON UPDATE current_timestamp() at the schema level, so closing a ticket
// (or any other change to it) already bumps this automatically.
// COALESCE to ticket_created_at: portal-created tickets (client/post.php)
// never set ticket_updated_at at insert time, so a brand-new, never-touched
// ticket has it NULL - sorting bare DESC would push a just-submitted ticket
// to the bottom (MySQL sorts NULL last in DESC), hiding it here too.
$sql_recent_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, ticket_updated_at FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id ORDER BY COALESCE(ticket_updated_at, ticket_created_at) DESC, ticket_id DESC LIMIT 5");

// Build a combined "needs attention" list for technical contacts
$tech_alerts = [];

if ($session_contact_primary == 1 || $session_contact_is_technical_contact) {
    $alert_sources = [
        ['sql' => $sql_domains_expiring, 'name' => 'domain_name', 'date' => 'domain_expire', 'icon' => 'fa-globe', 'label' => 'Domain', 'link' => 'domains.php', 'expired' => false],
        ['sql' => $sql_certificates_expiring, 'name' => 'certificate_name', 'date' => 'certificate_expire', 'icon' => 'fa-certificate', 'label' => 'Certificate', 'link' => 'certificates.php', 'expired' => false],
        ['sql' => $sql_licenses_expiring, 'name' => 'software_name', 'date' => 'software_expire', 'icon' => 'fa-key', 'label' => 'License', 'link' => '#', 'expired' => false],
        ['sql' => $sql_asset_warranties_expiring, 'name' => 'asset_name', 'date' => 'asset_warranty_expire', 'icon' => 'fa-desktop', 'label' => 'Warranty', 'link' => 'assets.php', 'expired' => false],
        ['sql' => $sql_domains_expired, 'name' => 'domain_name', 'date' => 'domain_expire', 'icon' => 'fa-globe', 'label' => 'Domain', 'link' => 'domains.php', 'expired' => true],
        ['sql' => $sql_certificates_expired, 'name' => 'certificate_name', 'date' => 'certificate_expire', 'icon' => 'fa-certificate', 'label' => 'Certificate', 'link' => 'certificates.php', 'expired' => true],
        ['sql' => $sql_licenses_expired, 'name' => 'software_name', 'date' => 'software_expire', 'icon' => 'fa-key', 'label' => 'License', 'link' => '#', 'expired' => true],
        ['sql' => $sql_asset_warranties_expired, 'name' => 'asset_name', 'date' => 'asset_warranty_expire', 'icon' => 'fa-desktop', 'label' => 'Warranty', 'link' => 'assets.php', 'expired' => true],
    ];

    foreach ($alert_sources as $source) {
        while ($row = mysqli_fetch_assoc($source['sql'])) {
            $tech_alerts[] = [
                'name' => nullable_htmlentities($row[$source['name']]),
                'date' => nullable_htmlentities($row[$source['date']]),
                'icon' => $source['icon'],
                'label' => $source['label'],
                'link' => $source['link'],
                'expired' => $source['expired'],
            ];
        }
    }
}

?>
<?php
$portal_is_tech = ($session_contact_primary == 1 || $session_contact_is_technical_contact);
$portal_assigned_count = mysqli_num_rows($sql_assigned_assets);
$portal_training_on = intval($config_module_enable_training ?? 0) === 1 && !empty($config_training_schema_ready);
// No billing queries or tiles: this edition has no billing/invoicing/quoting (page files stay on disk for the MSP fork).
?>

<!-- Stat cards -->
<div class="portal-stats portal-stagger">

    <a class="portal-stat <?php echo $open_tickets_count > 0 ? 'portal-stat--blue' : 'portal-stat--slate'; ?>" href="tickets.php">
        <span class="portal-stat-icon"><i class="fas fa-ticket-alt" aria-hidden="true"></i></span>
        <span class="portal-stat-body">
            <span class="portal-stat-num portal-count" data-count="<?php echo $open_tickets_count; ?>"><?php echo $open_tickets_count; ?></span>
            <span class="portal-stat-label">Open ticket<?php echo $open_tickets_count == 1 ? '' : 's'; ?></span>
        </span>
        <i class="fas fa-arrow-right portal-stat-go" aria-hidden="true"></i>
    </a>

    <?php if ($portal_is_tech) { ?>

        <a class="portal-stat <?php echo count($tech_alerts) > 0 ? 'portal-stat--amber' : 'portal-stat--green'; ?>" href="#tech-alerts">
            <span class="portal-stat-icon"><i class="fas <?php echo count($tech_alerts) > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>" aria-hidden="true"></i></span>
            <span class="portal-stat-body">
                <span class="portal-stat-num portal-count" data-count="<?php echo count($tech_alerts); ?>"><?php echo count($tech_alerts); ?></span>
                <span class="portal-stat-label">Item<?php echo count($tech_alerts) == 1 ? '' : 's'; ?> needing attention</span>
            </span>
            <i class="fas fa-arrow-right portal-stat-go" aria-hidden="true"></i>
        </a>

        <a class="portal-stat portal-stat--slate" href="assets.php">
            <span class="portal-stat-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>
            <span class="portal-stat-body">
                <span class="portal-stat-num portal-count" data-count="<?php echo $portal_assigned_count; ?>"><?php echo $portal_assigned_count; ?></span>
                <span class="portal-stat-label">Assigned asset<?php echo $portal_assigned_count == 1 ? '' : 's'; ?></span>
            </span>
            <i class="fas fa-arrow-right portal-stat-go" aria-hidden="true"></i>
        </a>

    <?php } ?>

</div>

<div class="row g-3 mb-4">

    <!-- Recent Tickets -->
    <div class="col-lg-7 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-ticket-alt" aria-hidden="true"></i></span>Recent tickets</h3>
                <a href="tickets.php" class="btn btn-sm btn-outline-primary">View all</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Last update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (mysqli_num_rows($sql_recent_tickets) == 0) { ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="portal-empty"><i class="far fa-life-ring" aria-hidden="true"></i>No tickets yet. Need help? <a href="ticket_add.php">Open a ticket</a>.</div>
                                    </td>
                                </tr>
                            <?php }
                            while ($row = mysqli_fetch_assoc($sql_recent_tickets)) {
                                $ticket_id = intval($row['ticket_id']);
                                $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                                $ticket_number = intval($row['ticket_number']);
                                $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                                $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                                $ticket_updated_at = $row['ticket_updated_at'] ? timeAgo($row['ticket_updated_at']) : '-';
                            ?>
                                <tr>
                                    <td class="text-nowrap"><a href="ticket.php?id=<?php echo $ticket_id; ?>">#<?php echo "$ticket_prefix$ticket_number"; ?></a></td>
                                    <td><a href="ticket.php?id=<?php echo $ticket_id; ?>"><?php echo $ticket_subject; ?></a></td>
                                    <td><span class="badge text-bg-secondary"><?php echo $ticket_status; ?></span></td>
                                    <td class="text-secondary"><?php echo $ticket_updated_at; ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Assigned Assets -->
    <div class="col-lg-5 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-desktop" aria-hidden="true"></i></span>Your assigned assets</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <tbody>
                        <?php
                        if ($portal_assigned_count == 0) { ?>
                            <tr>
                                <td><div class="portal-empty"><i class="fas fa-laptop" aria-hidden="true"></i>No assets assigned to you.</div></td>
                            </tr>
                        <?php }
                        mysqli_data_seek($sql_assigned_assets, 0);
                        while ($row = mysqli_fetch_assoc($sql_assigned_assets)) {
                            $asset_name = nullable_htmlentities($row['asset_name']);
                            $asset_type = nullable_htmlentities($row['asset_type']);
                            $asset_uri_client = sanitize_url($row['asset_uri_client']);

                            ?>
                            <tr>
                                <td><i class="fas fa-fw fa-desktop text-secondary me-2"></i><?php echo $asset_name; ?> <span class="text-secondary">(<?php echo $asset_type; ?>)</span></td>
                                <td class="text-end">
                                    <?php if ($asset_uri_client) { ?>
                                        <a href="<?= $asset_uri_client ?>" target="_blank" rel="noopener noreferrer" aria-label="Open"><i class="fas fa-external-link-alt"></i></a>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?php if ($portal_is_tech) { ?>
<!-- Needs Attention -->
<div class="card portal-card mb-4" id="tech-alerts">
    <div class="card-header">
        <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>Needs attention</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (count($tech_alerts) == 0) { ?>
                        <tr>
                            <td colspan="4"><div class="portal-empty"><i class="fas fa-check-circle text-success" aria-hidden="true"></i>Nothing needs your attention right now.</div></td>
                        </tr>
                    <?php }
                    foreach ($tech_alerts as $alert) {
                        ?>
                        <tr>
                            <td><i class="fas fa-fw <?php echo $alert['icon']; ?> text-secondary me-2"></i><?php echo $alert['label']; ?></td>
                            <td><?php if ($alert['link'] != '#') { ?><a href="<?php echo $alert['link']; ?>"><?php echo $alert['name']; ?></a><?php } else { echo $alert['name']; } ?></td>
                            <td><?php echo $alert['date']; ?></td>
                            <td>
                                <?php if ($alert['expired']) { ?>
                                    <span class="badge text-bg-danger">Expired</span>
                                <?php } else { ?>
                                    <span class="badge text-bg-warning text-white">Expiring soon</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php } ?>

<!-- Quick Links -->
<div class="card portal-card">
    <div class="card-header">
        <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-th-large" aria-hidden="true"></i></span>Quick links</h3>
    </div>
    <div class="card-body">
        <div class="portal-tiles portal-stagger">
            <a href="ticket_add.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-plus" aria-hidden="true"></i></span>New ticket</a>
            <a href="tickets.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-ticket-alt" aria-hidden="true"></i></span>Tickets</a>
            <a href="service_catalog.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-concierge-bell" aria-hidden="true"></i></span>Request something</a>
            <?php if ($config_module_enable_kb == 1) { ?>
                <a href="kb_articles.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-book" aria-hidden="true"></i></span>Knowledge base</a>
            <?php } ?>
            <?php if ($portal_training_on) { ?>
                <a href="training.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-graduation-cap" aria-hidden="true"></i></span>Training</a>
            <?php } ?>
            <?php if ($portal_is_tech) { ?>
                <a href="assets.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>Assets</a>
                <a href="domains.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-globe" aria-hidden="true"></i></span>Domains</a>
                <a href="documents.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></span>Documents</a>
                <a href="contacts.php" class="portal-tile"><span class="portal-tile-icon"><i class="fas fa-address-book" aria-hidden="true"></i></span>Contacts</a>
            <?php } ?>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>
