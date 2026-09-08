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
        AND asset_archived_at IS NULL
    ORDER BY asset_name ASC"
);

// Open ticket count for this contact
$sql_open_tickets_count = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id AND ticket_closed_at IS NULL");
$row = mysqli_fetch_assoc($sql_open_tickets_count);
$open_tickets_count = intval($row['c']);

// Recent tickets for this contact
$sql_recent_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, ticket_updated_at FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id ORDER BY ticket_id DESC LIMIT 5");

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
<div class="row mb-3">
    <div class="col-md-3">
        <a href="ticket_add.php" class="btn btn-primary btn-block mb-3"><i class="fas fa-fw fa-plus me-1"></i>New Ticket</a>
    </div>
</div>

<!-- Stat boxes -->
<div class="row mb-3">

    <div class="col-lg-4 col-md-6 col-sm-12">
        <a class="small-box <?php echo $open_tickets_count > 0 ? 'bg-info' : 'bg-secondary'; ?>" href="tickets.php">
            <div class="inner">
                <h3><?php echo $open_tickets_count; ?></h3>
                <p>Open Ticket<?php echo $open_tickets_count == 1 ? '' : 's'; ?></p>
            </div>
            <div class="icon">
                <i class="fas fa-ticket-alt"></i>
            </div>
        </a>
    </div>

    <?php if ($session_contact_primary == 1 || $session_contact_is_technical_contact) { ?>

        <div class="col-lg-4 col-md-6 col-sm-12">
            <a class="small-box <?php echo count($tech_alerts) > 0 ? 'bg-warning' : 'bg-success'; ?>" href="#tech-alerts">
                <div class="inner">
                    <h3><?php echo count($tech_alerts); ?></h3>
                    <p>Item<?php echo count($tech_alerts) == 1 ? '' : 's'; ?> Needing Attention</p>
                </div>
                <div class="icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </a>
        </div>

        <div class="col-lg-4 col-md-6 col-sm-12">
            <a class="small-box bg-secondary" href="assets.php">
                <div class="inner">
                    <h3><?php echo mysqli_num_rows($sql_assigned_assets); ?></h3>
                    <p>Assigned Asset<?php echo mysqli_num_rows($sql_assigned_assets) == 1 ? '' : 's'; ?></p>
                </div>
                <div class="icon">
                    <i class="fas fa-desktop"></i>
                </div>
            </a>
        </div>

    <?php } ?>

</div>

<div class="row mb-3">

    <!-- Recent Tickets -->
    <div class="col-lg-7 col-md-12">
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-ticket-alt me-2"></i>Recent Tickets</h3>
                <div class="card-tools">
                    <a href="tickets.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Last Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (mysqli_num_rows($sql_recent_tickets) == 0) { ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No tickets yet. Need help? <a href="ticket_add.php">Open a ticket</a>.</td>
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
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-desktop me-2"></i>Your Assigned Assets</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <tbody>
                        <?php
                        if (mysqli_num_rows($sql_assigned_assets) == 0) { ?>
                            <tr>
                                <td class="text-center text-muted py-4">No assets assigned to you.</td>
                            </tr>
                        <?php }
                        while ($row = mysqli_fetch_assoc($sql_assigned_assets)) {
                            $asset_name = nullable_htmlentities($row['asset_name']);
                            $asset_type = nullable_htmlentities($row['asset_type']);
                            $asset_uri_client = sanitize_url($row['asset_uri_client']);

                            ?>
                            <tr>
                                <td><i class="fas fa-fw fa-desktop text-secondary me-2"></i><?php echo $asset_name; ?> <span class="text-secondary">(<?php echo $asset_type; ?>)</span></td>
                                <td class="text-end">
                                    <?php if ($asset_uri_client) { ?>
                                        <a href="<?= $asset_uri_client ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt"></i></a>
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

<?php if ($session_contact_primary == 1 || $session_contact_is_technical_contact) { ?>
<!-- Needs Attention -->
<div class="row mb-3" id="tech-alerts">
    <div class="col-md-12">
        <div class="card card-outline card-warning">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Needs Attention</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
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
                                    <td colspan="4" class="text-center text-muted py-4"><i class="fas fa-check-circle text-success me-1"></i>Nothing needs your attention right now.</td>
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
                                            <span class="badge text-bg-warning text-white">Expiring Soon</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- Quick Links -->
<div class="row">
    <div class="col-md-12">
        <div class="card card-outline card-secondary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-fw fa-th-large me-2"></i>Quick Links</h3>
            </div>
            <div class="card-body">
                <?php
                /* justify-content-center, because this row's tile COUNT is not
                   fixed - it is 5 for a primary/technical contact and 2 for
                   everyone else, and `col-md-2` means neither total fills the
                   12-column row.

                   It was already ragged before the two billing tiles came out,
                   and worse: 7 tiles is 14 columns, so at 1500px it measured as
                   6 tiles on one row and "Contacts" stranded alone on a second.
                   Left-aligning 5 tiles would just have moved the ragged gap to
                   the right-hand 2 columns. Centring is the one arrangement that
                   stays balanced for both gate outcomes and at all three widths.

                   Measured after, primary contact, 5 tiles:
                     1500px  one row, tiles x=217..1273 inside a row spanning
                             112..1379 - 105px clear left, 106px clear right
                      768px  one row, tiles x=98..660 inside a row 42..717
                      390px  col-6 stack, 2 + 2 + 1, with the last tile at
                             x=106 w=167 inside a row 23..358, i.e. centred
                             rather than hanging off the left */
                ?>
                <div class="row text-center justify-content-center">

                    <div class="col-6 col-md-2 mb-3">
                        <a href="tickets.php" class="text-decoration-none">
                            <i class="fas fa-2x fa-ticket-alt text-primary mb-2"></i>
                            <div>Tickets</div>
                        </a>
                    </div>

                    <?php
                    /* The Invoices and Quotes tiles were here, gated on
                       ($session_contact_primary == 1 ||
                        $session_contact_is_billing_contact)
                       and on NOTHING else - in particular with no
                       $config_module_enable_accounting check, unlike the Finance
                       dropdown in client/includes/header.php (the $config_module_enable_accounting
                       gate on the Finance dropdown) which has always
                       had one. So on this install, where that setting is 0, the
                       navbar correctly showed no Finance menu while this card
                       still offered two tiles straight into /client/invoices.php
                       and /client/quotes.php.

                       Removed rather than gated: this edition has no billing,
                       invoicing or quoting at all, so a gate would be dead code
                       guarding a surface that must never come back here. The page
                       files themselves stay on disk - the scope for accounting
                       removal on this fork is "hide the surface", so the MSP fork
                       can still port back. Restoring the tiles means restoring
                       this block, nothing more. */
                    ?>

                    <?php if ($session_contact_primary == 1 || $session_contact_is_technical_contact) { ?>
                        <div class="col-6 col-md-2 mb-3">
                            <a href="assets.php" class="text-decoration-none">
                                <i class="fas fa-2x fa-desktop text-primary mb-2"></i>
                                <div>Assets</div>
                            </a>
                        </div>
                        <div class="col-6 col-md-2 mb-3">
                            <a href="domains.php" class="text-decoration-none">
                                <i class="fas fa-2x fa-globe text-primary mb-2"></i>
                                <div>Domains</div>
                            </a>
                        </div>
                        <div class="col-6 col-md-2 mb-3">
                            <a href="documents.php" class="text-decoration-none">
                                <i class="fas fa-2x fa-file-alt text-primary mb-2"></i>
                                <div>Documents</div>
                            </a>
                        </div>
                    <?php } ?>

                    <div class="col-6 col-md-2 mb-3">
                        <a href="contacts.php" class="text-decoration-none">
                            <i class="fas fa-2x fa-address-book text-primary mb-2"></i>
                            <div>Contacts</div>
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>
