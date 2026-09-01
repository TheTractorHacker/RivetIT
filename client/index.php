<?php
/*
 * Client Portal
 * Landing / Home page for the client portal
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

// Billing Card Queries
 //Add up all the payments for the invoice and get the total amount paid to the invoice
$sql_invoice_amounts = mysqli_query($mysqli, "SELECT SUM(invoice_amount) AS invoice_amounts FROM invoices WHERE invoice_client_id = $session_client_id AND invoice_status != 'Draft' AND invoice_status != 'Cancelled' AND invoice_status != 'Non-Billable'");
$row = mysqli_fetch_assoc($sql_invoice_amounts);

$invoice_amounts = floatval($row['invoice_amounts']);

$sql_amount_paid = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments, invoices WHERE payment_invoice_id = invoice_id AND invoice_client_id = $session_client_id");
$row = mysqli_fetch_assoc($sql_amount_paid);

$amount_paid = floatval($row['amount_paid']);

$balance = $invoice_amounts - $amount_paid;

//Get Monthly Recurring Total
$sql_recurring_monthly_total = mysqli_query($mysqli, "SELECT SUM(recurring_invoice_amount) AS recurring_monthly_total FROM recurring_invoices WHERE recurring_invoice_status = 1 AND recurring_invoice_frequency = 'month' AND recurring_invoice_client_id = $session_client_id");
$row = mysqli_fetch_assoc($sql_recurring_monthly_total);

$recurring_monthly_total = floatval($row['recurring_monthly_total']);

//Get Yearly Recurring Total
$sql_recurring_yearly_total = mysqli_query($mysqli, "SELECT SUM(recurring_invoice_amount) AS recurring_yearly_total FROM recurring_invoices WHERE recurring_invoice_status = 1 AND recurring_invoice_frequency = 'year' AND recurring_invoice_client_id = $session_client_id");
$row = mysqli_fetch_assoc($sql_recurring_yearly_total);

$recurring_yearly_total = floatval($row['recurring_yearly_total']) / 12;

$recurring_monthly = $recurring_monthly_total + $recurring_yearly_total;

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

    <div class="col-lg-3 col-md-6 col-sm-12">
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

    <?php if ($session_contact_primary == 1 || $session_contact_is_billing_contact) { ?>

        <div class="col-lg-3 col-md-6 col-sm-12">
            <a class="small-box <?php echo $balance > 0 ? 'bg-danger' : 'bg-success'; ?>" href="<?php echo $balance > 0 ? 'unpaid_invoices.php' : 'invoices.php'; ?>">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $balance, $session_company_currency); ?></h3>
                    <p>Account Balance Due</p>
                </div>
                <div class="icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
            </a>
        </div>

        <div class="col-lg-3 col-md-6 col-sm-12">
            <a class="small-box bg-primary" href="recurring_invoices.php">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $recurring_monthly, $session_company_currency); ?></h3>
                    <p>Recurring Monthly</p>
                </div>
                <div class="icon">
                    <i class="fas fa-sync-alt"></i>
                </div>
            </a>
        </div>

    <?php } ?>

    <?php if ($session_contact_primary == 1 || $session_contact_is_technical_contact) { ?>

        <div class="col-lg-3 col-md-6 col-sm-12">
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

        <div class="col-lg-3 col-md-6 col-sm-12">
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
                <div class="row text-center">

                    <div class="col-6 col-md-2 mb-3">
                        <a href="tickets.php" class="text-decoration-none">
                            <i class="fas fa-2x fa-ticket-alt text-primary mb-2"></i>
                            <div>Tickets</div>
                        </a>
                    </div>

                    <?php if ($session_contact_primary == 1 || $session_contact_is_billing_contact) { ?>
                        <div class="col-6 col-md-2 mb-3">
                            <a href="invoices.php" class="text-decoration-none">
                                <i class="fas fa-2x fa-file-invoice-dollar text-primary mb-2"></i>
                                <div>Invoices</div>
                            </a>
                        </div>
                        <div class="col-6 col-md-2 mb-3">
                            <a href="quotes.php" class="text-decoration-none">
                                <i class="fas fa-2x fa-file-signature text-primary mb-2"></i>
                                <div>Quotes</div>
                            </a>
                        </div>
                    <?php } ?>

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
