<?php
require_once "includes/inc_all.php";

// Get current year or the selected year
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Update user settings based on GET parameters
if (isset($_GET['enable_financial'])) {
    $enable_financial = intval($_GET['enable_financial']);
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_dashboard_financial_enable = $enable_financial WHERE user_id = $session_user_id");
}

if (isset($_GET['enable_technical'])) {
    $enable_technical = intval($_GET['enable_technical']);
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_dashboard_technical_enable = $enable_technical WHERE user_id = $session_user_id");
}

// Fetch User Dashboard Settings
$sql_user_dashboard_settings = mysqli_query($mysqli, "SELECT * FROM user_settings WHERE user_id = $session_user_id");
$row = mysqli_fetch_assoc($sql_user_dashboard_settings);
$user_config_dashboard_financial_enable = intval($row['user_config_dashboard_financial_enable']);
$user_config_dashboard_technical_enable = intval($row['user_config_dashboard_technical_enable']);

// Get unique years from expenses, payments, invoices, revenues, tickets, clients, and users
$sql_years_select = mysqli_query($mysqli, "
    SELECT YEAR(expense_date) AS all_years FROM expenses
    UNION DISTINCT SELECT YEAR(payment_date) FROM payments
    UNION DISTINCT SELECT YEAR(revenue_date) FROM revenues
    UNION DISTINCT SELECT YEAR(invoice_date) FROM invoices
    UNION DISTINCT SELECT YEAR(ticket_created_at) FROM tickets
    UNION DISTINCT SELECT YEAR(client_created_at) FROM clients
    UNION DISTINCT SELECT YEAR(user_created_at) FROM users
    ORDER BY all_years DESC
");
?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  .chart-h-240 { position: relative; height: 240px; }
  /* If you want charts to shrink on very small screens, you can tweak with media queries:
  @media (max-width: 576px) {
    .chart-h-320 { height: 240px; }
    .chart-h-240 { height: 200px; }
  } */

  /* Dashboard stat tiles: every tile keeps a color identity (a soft tint,
     not the old solid saturated fill), so the dashboard still feels lively -
     but success/warning/danger are reserved status colors (good / attention
     soon / attention now) while primary/info/secondary/pink are just
     identity hues for informational counts, never implying good or bad. */
  .small-box.bg-primary, .small-box.text-bg-primary,
  .small-box.bg-info, .small-box.text-bg-info,
  .small-box.bg-success, .small-box.text-bg-success,
  .small-box.bg-warning, .small-box.text-bg-warning,
  .small-box.bg-danger, .small-box.text-bg-danger,
  .small-box.bg-secondary, .small-box.text-bg-secondary, .small-box.bg-pink {
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
  .small-box .inner p small { color: #898781 !important; opacity: 1; }

  /* Icon: a small fixed chip instead of a giant faded watermark */
  .small-box .icon {
    position: absolute; top: 14px; right: 14px;
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    opacity: 1;
  }
  .small-box .icon i {
    position: static !important;
    font-size: 15px !important;
    transform: none !important;
  }
  .small-box:hover .icon i { transform: none !important; }

  /* Identity hues - informational counts, no good/bad meaning */
  .small-box.bg-primary, .small-box.text-bg-primary { background: rgba(42,120,214,.07) !important; border-left-color: #2a78d6; }
  .small-box.bg-primary .icon, .small-box.text-bg-primary .icon { background: rgba(42,120,214,.16); }
  .small-box.bg-primary .icon i, .small-box.text-bg-primary .icon i { color: #2a78d6; }

  .small-box.bg-info, .small-box.text-bg-info { background: rgba(27,175,122,.07) !important; border-left-color: #1baf7a; }
  .small-box.bg-info .icon, .small-box.text-bg-info .icon { background: rgba(27,175,122,.16); }
  .small-box.bg-info .icon i, .small-box.text-bg-info .icon i { color: #159763; }

  .small-box.bg-secondary, .small-box.text-bg-secondary { background: rgba(74,58,167,.06) !important; border-left-color: #4a3aa7; }
  .small-box.bg-secondary .icon, .small-box.text-bg-secondary .icon { background: rgba(74,58,167,.14); }
  .small-box.bg-secondary .icon i, .small-box.text-bg-secondary .icon i { color: #4a3aa7; }

  .small-box.bg-pink { background: rgba(232,123,164,.10) !important; border-left-color: #e87ba4; }
  .small-box.bg-pink .icon { background: rgba(232,123,164,.20); }
  .small-box.bg-pink .icon i { color: #c94c7c; }

  /* Status hues - reserved meaning: good / attention soon / attention now.
     The number stays in primary ink regardless of tile color; a colored
     numeral is harder to read and the tint + icon + label already carry
     the meaning. */
  .small-box.bg-success, .small-box.text-bg-success { background: rgba(12,163,12,.07) !important; border-left-color: #0ca30c; }
  .small-box.bg-success .icon, .small-box.text-bg-success .icon { background: rgba(12,163,12,.16); }
  .small-box.bg-success .icon i, .small-box.text-bg-success .icon i { color: #0ca30c; }

  .small-box.bg-warning, .small-box.text-bg-warning { background: rgba(250,178,25,.12) !important; border-left-color: #fab219; }
  .small-box.bg-warning .icon, .small-box.text-bg-warning .icon { background: rgba(250,178,25,.22); }
  .small-box.bg-warning .icon i, .small-box.text-bg-warning .icon i { color: #9c6b04; }

  .small-box.bg-danger, .small-box.text-bg-danger { background: rgba(208,59,59,.07) !important; border-left-color: #d03b3b; }
  .small-box.bg-danger .icon, .small-box.text-bg-danger .icon { background: rgba(208,59,59,.16); }
  .small-box.bg-danger .icon i, .small-box.text-bg-danger .icon i { color: #d03b3b; }

  /* "Needs attention" status strip: a NOC-style checklist, not another stat
     tile. Items dim to near-invisible when clear so the eye jumps straight
     to whatever is actually amber - the same "quiet unless it matters"
     convention already used for sidebar active-states and table row accents. */
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
  .dash-attention-chip.is-active {
    border-color: rgba(217,119,6,.35); background: rgba(217,119,6,.1); color: #9a5b00;
  }
  :root[data-bs-theme="dark"] .dash-attention-chip.is-active { color: #f2b84b; }
</style>


<?php
// Dashboard stat cards
$dash_open_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL"))[0]);
$dash_active_clients = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM clients WHERE client_archived_at IS NULL"))[0]);
$dash_my_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_assigned_to = $session_user_id AND ticket_closed_at IS NULL"))[0]);
$dash_pending_invoices = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoices WHERE invoice_status IN ('Sent', 'Viewed', 'Partial')"))[0]);
$dash_hour = intval(date('G'));
$dash_greeting = $dash_hour < 12 ? 'Good morning' : ($dash_hour < 17 ? 'Good afternoon' : 'Good evening');

// "Needs attention" signals - computed unconditionally (regardless of the
// Financial/Technical toggles below) so the top-of-page status strip always
// reflects reality. Cheap single-table counts, reused (not re-queried) by the
// Technical section further down if it's enabled.
$dash_unassigned_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0)"))[0]);
$dash_expiring_domains = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURRENT_DATE AND domain_expire < CURRENT_DATE + INTERVAL 30 DAY AND domain_archived_at IS NULL"))[0]);
$dash_expiring_certificates = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURRENT_DATE AND certificate_expire < CURRENT_DATE + INTERVAL 30 DAY AND certificate_archived_at IS NULL"))[0]);

$dash_attention_items = [
    ['count' => $dash_unassigned_tickets,      'label' => 'Unassigned tickets',        'href' => 'tickets.php?assigned=0',                              'icon' => 'fa-user-slash'],
    ['count' => $dash_pending_invoices,         'label' => 'Unpaid invoices',           'href' => 'invoices.php',                                        'icon' => 'fa-file-invoice-dollar'],
    ['count' => $dash_expiring_domains,         'label' => 'Domains expiring (30d)',    'href' => 'domains.php?sort=domain_expire&order=ASC',            'icon' => 'fa-globe'],
    ['count' => $dash_expiring_certificates,    'label' => 'Certificates expiring (30d)','href' => 'certificates.php?sort=certificate_expire&order=ASC',  'icon' => 'fa-lock'],
];

$dash_csat_avg = null;
if ($config_module_enable_ticketing == 1 && !empty($config_ticket_csat_enable)) {
    $dash_csat_needs_followup = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_csat_rating IS NOT NULL AND ticket_csat_rating <= $config_ticket_csat_low_rating_threshold AND ticket_status != 5"))[0]);
    $dash_attention_items[] = ['count' => $dash_csat_needs_followup, 'label' => 'Needs CSAT follow-up', 'href' => 'reports/csat.php', 'icon' => 'fa-star-half-alt'];

    $dash_csat_avg_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT AVG(ticket_csat_rating) AS v FROM tickets WHERE ticket_csat_rated_at >= NOW() - INTERVAL 30 DAY"));
    $dash_csat_avg = $dash_csat_avg_row['v'] !== null ? round(floatval($dash_csat_avg_row['v']), 2) : null;
}

$dash_attention_total = array_sum(array_column($dash_attention_items, 'count'));
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

<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <a href="tickets.php" class="text-decoration-none">
        <div class="small-box text-bg-primary bg-gradient mb-0">
            <div class="inner">
                <h3><?= $dash_open_tickets ?></h3>
                <p>Open Tickets</p>
            </div>
            <div class="icon"><i class="fas fa-ticket-alt"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="tickets.php?assigned_to=<?= $session_user_id ?>" class="text-decoration-none">
        <div class="small-box text-bg-info bg-gradient mb-0">
            <div class="inner">
                <h3><?= $dash_my_tickets ?></h3>
                <p>My Tickets</p>
            </div>
            <div class="icon"><i class="fas fa-user-check"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="clients.php" class="text-decoration-none">
        <div class="small-box text-bg-success bg-gradient mb-0">
            <div class="inner">
                <h3><?= $dash_active_clients ?></h3>
                <p>Active Clients</p>
            </div>
            <div class="icon"><i class="fas fa-building"></i></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <a href="invoices.php" class="text-decoration-none">
        <div class="small-box text-bg-warning bg-gradient mb-0">
            <div class="inner">
                <h3><?= $dash_pending_invoices ?></h3>
                <p>Unpaid Invoices</p>
            </div>
            <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
        </div>
        </a>
    </div>
    <?php if ($dash_csat_avg !== null) { ?>
    <div class="col-6 col-md-3 mb-3">
        <a href="reports/csat.php" class="text-decoration-none">
        <div class="small-box text-bg-secondary bg-gradient mb-0">
            <div class="inner">
                <h3><?= $dash_csat_avg ?>/5</h3>
                <p>CSAT (30 days)</p>
            </div>
            <div class="icon"><i class="fas fa-star"></i></div>
        </div>
        </a>
    </div>
    <?php } ?>
</div>

<div class="card card-body">
    <form class="d-flex flex-wrap align-items-center gap-2">
        <input type="hidden" name="enable_financial" value="0">
        <input type="hidden" name="enable_technical" value="0">

        <label for="year" class="me-sm-2">Select Year:</label>
        <select id="year" class="form-select me-sm-3 col-sm-2 mb-3 mb-sm-0 auto-submit-select" style="width:auto;" name="year">
            <?php while ($row = mysqli_fetch_assoc($sql_years_select)) {
                $year_select = $row['all_years'];
                if (empty($year_select)) {
                    $year_select = date('Y');
                }
            ?>
                <option value="<?php echo $year_select; ?>" <?php if ($year == $year_select) { echo "selected"; } ?>>
                    <?php echo $year_select; ?>
                </option>
            <?php } ?>
        </select>

        <?php if ($session_user_role == 1 || ($session_user_role == 3 && $config_module_enable_accounting == 1)) { ?>
            <div class="form-check form-switch me-3">
                <input type="checkbox" class="form-check-input auto-submit-select" id="customSwitch1" name="enable_financial" value="1" <?php if ($user_config_dashboard_financial_enable == 1) { echo "checked"; } ?>>
                <label class="form-check-label" for="customSwitch1">Financial</label>
            </div>
        <?php } ?>

        <?php if ($session_user_role >= 2 && $config_module_enable_ticketing == 1) { ?>
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input auto-submit-select" id="customSwitch2" name="enable_technical" value="1" <?php if ($user_config_dashboard_technical_enable == 1) { echo "checked"; } ?>>
                <label class="form-check-label" for="customSwitch2">Technical</label>
            </div>
        <?php } ?>
    </form>
</div>

<?php
if ($user_config_dashboard_financial_enable == 1) {

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
        $sql_unbilled_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS unbilled_tickets FROM tickets WHERE ticket_closed_at IS NOT NULL AND ticket_billable = 1 AND ticket_invoice_id = 0 AND YEAR(ticket_created_at) = $year");
        $row = mysqli_fetch_assoc($sql_unbilled_tickets);
        $unbilled_tickets = intval($row['unbilled_tickets']);
    } else {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(recurring_invoice_id) AS recurring_invoices_added FROM recurring_invoices WHERE YEAR(recurring_invoice_created_at) = $year"));
        $recurring_invoices_added = intval($row['recurring_invoices_added']);
    }

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(client_id) AS clients_added FROM clients WHERE YEAR(client_created_at) = $year AND client_lead = 0 AND client_archived_at IS NULL"));
    $clients_added = intval($row['clients_added']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(client_id) AS leads_added FROM clients WHERE YEAR(client_created_at) = $year AND client_lead = 1 AND client_archived_at IS NULL"));
    $leads_added = intval($row['leads_added']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(vendor_id) AS vendors_added FROM vendors WHERE YEAR(vendor_created_at) = $year AND vendor_client_id = 0 AND vendor_archived_at IS NULL"));
    $vendors_added = intval($row['vendors_added']);
?>
<div class="card card-body">
    <!-- Icon Cards-->
    <div class="row">
        <div class="col-lg-4 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-primary" href="payments.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $total_income, "$session_company_currency"); ?></h3>
                    <p>Income</p>
                    <hr>
                    <small>Receivables: <?php echo numfmt_format_currency($currency_format, $receivables, "$session_company_currency"); ?></small>
                </div>
                <div class="icon">
                    <i class="fa fa-hand-holding-usd"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-danger" href="expenses.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $total_expenses, "$session_company_currency"); ?></h3>
                    <p>Expenses</p>
                </div>
                <div class="icon">
                    <i class="fa fa-shopping-cart"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-success" href="reports/profit_loss.php">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $profit, "$session_company_currency"); ?></h3>
                    <p>Profit</p>
                </div>
                <div class="icon">
                    <i class="fa fa-balance-scale"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-6 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-info" href="reports/recurring_by_client.php">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $recurring_monthly_total, "$session_company_currency"); ?></h3>
                    <p>Monthly Recurring Income</p>
                </div>
                <div class="icon">
                    <i class="fa fa-sync-alt"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-6 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-pink" href="recurring_expenses.php">
                <div class="inner">
                    <h3><?php echo numfmt_format_currency($currency_format, $recurring_expense_monthly_total, "$session_company_currency"); ?></h3>
                    <p>Monthly Recurring Expense</p>
                </div>
                <div class="icon">
                    <i class="fa fa-clock"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <?php if ($config_module_enable_ticketing && $config_module_enable_accounting) { ?>
            <div class="col-lg-2 col-md-6 col-sm-12">
                <!-- small box -->
                <a class="small-box bg-secondary" href="reports/tickets_unbilled.php">
                    <div class="inner">
                        <h3><?php echo $unbilled_tickets; ?></h3>
                        <p>Unbilled Ticket<?php if ($unbilled_tickets > 1 || $unbilled_tickets == 0) { echo "s"; } ?></p>
                    </div>
                    <div class="icon">
                        <i class="fa fa-ticket-alt"></i>
                    </div>
                </a>
            </div>
        <?php } else { ?>
            <div class="col-lg-3 col-md-6 col-sm-12">
                <!-- small box -->
                <a class="small-box bg-secondary" href="recurring_invoices.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                    <div class="inner">
                        <h3><?php echo $recurring_invoices_added; ?></h3>
                        <p>Recurring Invoices Added</p>
                    </div>
                    <div class="icon">
                        <i class="fa fa-file-invoice"></i>
                    </div>
                </a>
            </div>
        <?php } ?>

        <div class="col-lg-2 col-6">
            <!-- small box -->
            <a class="small-box bg-secondary" href="clients.php?leads=1&dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo $leads_added; ?></h3>
                    <p>New Leads</p>
                </div>
                <div class="icon">
                    <i class="fa fa-users"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-2 col-6">
            <!-- small box -->
            <a class="small-box bg-secondary" href="clients.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo $clients_added; ?></h3>
                    <p>New Clients</p>
                </div>
                <div class="icon">
                    <i class="fa fa-users"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-2 col-6">
            <!-- small box -->
            <a class="small-box bg-secondary" href="vendors.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo $vendors_added; ?></h3>
                    <p>New Vendors</p>
                </div>
                <div class="icon">
                    <i class="fa fa-building"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-3 col-md-6 col-sm-12">
            <!-- small box -->
            <a class="small-box bg-secondary" href="trips.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo number_format($total_miles, 2); ?></h3>
                    <p>Miles Traveled</p>
                </div>
                <div class="icon">
                    <i class="fa fa-route"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-md-12">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-area me-2"></i>Cash Flow</h3>
                    <div class="card-tools">
                        <a href="reports/income_summary.php" class="btn btn-tool">
                            <i class="fas fa-eye"></i>
                        </a>
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-320">
                        <canvas id="cashFlow"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-pie me-2"></i>Income by Category <small>(Top 5)</small></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240">
                        <canvas id="incomeByCategoryPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-fw fa-shopping-cart me-2"></i>Expenses by Category <small>(Top 5)</small></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240">
                        <canvas id="expenseByCategoryPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-fw fa-building me-2"></i>Expenses by Vendor <small>(Top 5)</small></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240">
                        <canvas id="expenseByVendorPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-fw fa-piggy-bank me-2"></i>Account Balances</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
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
        </div> <!-- .col -->

        <div class="col-md-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-credit-card me-2"></i>Latest Income</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
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
        </div> <!-- .col -->

        <div class="col-md-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-shopping-cart me-2"></i>Latest Expenses</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
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
        </div> <!-- .col -->

        <div class="col-md-12">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-route me-2"></i>Trip Flow</h3>
                    <div class="card-tools">
                        <a href="trips.php" class="btn btn-tool">
                            <i class="fas fa-eye"></i>
                        </a>
                        <button type="button" class="btn btn-tool" data-card-widget="remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-320">
                        <canvas id="tripFlow"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- row -->
</div> <!-- card -->

<?php } ?>

<!-- Technical Dashboard -->

<?php
if ($user_config_dashboard_technical_enable == 1) {

    // Fetch technical data for the dashboard
    $sql_clients = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(client_id) AS clients_added FROM clients WHERE YEAR(client_created_at) = $year"));
    $clients_added = $sql_clients['clients_added'];

    $sql_contacts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(contact_id) AS contacts_added FROM contacts WHERE YEAR(contact_created_at) = $year"));
    $contacts_added = $sql_contacts['contacts_added'];

    $sql_assets = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(asset_id) AS assets_added FROM assets WHERE YEAR(asset_created_at) = $year"));
    $assets_added = $sql_assets['assets_added'];

    $sql_tickets = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS active_tickets FROM tickets WHERE ticket_closed_at IS NULL"));
    $active_tickets = $sql_tickets['active_tickets'];

    $sql_your_tickets = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS your_tickets FROM tickets WHERE ticket_closed_at IS NULL AND ticket_assigned_to = $session_user_id"));
    $your_tickets = $sql_your_tickets['your_tickets'];

    // Already computed unconditionally above (for the top-of-page attention strip) - reuse rather than re-query.
    $expiring_domains = $dash_expiring_domains;
    $expiring_certificates = $dash_expiring_certificates;

    $sql_your_tickets = mysqli_query($mysqli, "
        SELECT * FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE ticket_assigned_to = $session_user_id
        AND ticket_closed_at IS NULL
        ORDER BY ticket_number DESC
    ");

    $sql_your_automation_runs = mysqli_query($mysqli, "
        SELECT r.*, t.ticket_subject, t.ticket_number, t.ticket_prefix, t.ticket_client_id, c.client_name
        FROM ticket_automation_runs r
        INNER JOIN tickets t ON t.ticket_id = r.ticket_id
        LEFT JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE t.ticket_assigned_to = $session_user_id
        ORDER BY r.id DESC
        LIMIT 10
    ");

    // Ticket metrics
    $unassigned_tickets = $dash_unassigned_tickets; // already computed above for the attention strip

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE DATE(ticket_created_at) = CURDATE()"));
    $tickets_opened_today = intval($row['c']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE ticket_closed_at IS NOT NULL AND ticket_closed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"));
    $tickets_resolved_week = intval($row['c']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_closed_at IS NULL AND ticket_status_name = 'Waiting on Customer'"));
    $tickets_waiting_customer = intval($row['c']);

    // Priority breakdown (open tickets)
    $sql_by_priority = mysqli_query($mysqli, "SELECT ticket_priority, COUNT(ticket_id) AS c FROM tickets WHERE ticket_closed_at IS NULL GROUP BY ticket_priority ORDER BY FIELD(ticket_priority,'High','Medium','Low')");
    $priority_labels = $priority_counts = [];
    while ($r = mysqli_fetch_assoc($sql_by_priority)) { $priority_labels[] = $r['ticket_priority']; $priority_counts[] = intval($r['c']); }

    // Status breakdown (open tickets)
    $sql_by_status = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_color, COUNT(ticket_id) AS c FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_closed_at IS NULL GROUP BY ticket_status_name, ticket_status_color ORDER BY c DESC LIMIT 8");
    $status_labels = $status_counts = $status_colors = [];
    while ($r = mysqli_fetch_assoc($sql_by_status)) { $status_labels[] = $r['ticket_status_name']; $status_counts[] = intval($r['c']); $status_colors[] = $r['ticket_status_color'] ?: '#6c757d'; }

    // Category breakdown (open tickets)
    $sql_by_cat = mysqli_query($mysqli, "SELECT COALESCE(category_name,'Uncategorized') AS cat, category_color, COUNT(ticket_id) AS c FROM tickets LEFT JOIN categories ON ticket_category = category_id WHERE ticket_closed_at IS NULL GROUP BY cat, category_color ORDER BY c DESC LIMIT 8");
    $cat_labels = $cat_counts = $cat_colors = [];
    while ($r = mysqli_fetch_assoc($sql_by_cat)) { $cat_labels[] = $r['cat']; $cat_counts[] = intval($r['c']); $cat_colors[] = $r['category_color'] ?: '#6c757d'; }

    // Monthly opened vs resolved for selected year
    $monthly_opened = $monthly_resolved = [];
    for ($m = 1; $m <= 12; $m++) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at)=$year AND MONTH(ticket_created_at)=$m"));
        $monthly_opened[] = intval($r['c']);
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at)=$year AND MONTH(ticket_closed_at)=$m"));
        $monthly_resolved[] = intval($r['c']);
    }

    // Top techs by open ticket count
    $sql_top_techs = mysqli_query($mysqli, "SELECT user_name, COUNT(ticket_id) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id WHERE ticket_closed_at IS NULL AND ticket_assigned_to > 0 GROUP BY user_name ORDER BY c DESC LIMIT 8");
    $tech_rows = [];
    while ($r = mysqli_fetch_assoc($sql_top_techs)) $tech_rows[] = $r;

    // Historical ticket metrics for selected year
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at) = $year"));
    $tickets_created_year = intval($r['c']);

    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year"));
    $tickets_resolved_year = intval($r['c']);

    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, ticket_created_at, ticket_closed_at)),1) AS avg_h FROM tickets WHERE ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year"));
    $avg_resolution_hours = floatval($r['avg_h']);

    // Resolved by technician for selected year
    $sql_resolved_by_tech = mysqli_query($mysqli, "SELECT user_name, COUNT(ticket_id) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id WHERE ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year AND ticket_assigned_to > 0 GROUP BY user_name ORDER BY c DESC LIMIT 8");
    $resolved_tech_rows = [];
    while ($r = mysqli_fetch_assoc($sql_resolved_by_tech)) $resolved_tech_rows[] = $r;

    // Recently resolved tickets
    $sql_recent_resolved = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_subject, ticket_prefix, ticket_number, ticket_closed_at, ticket_priority, client_name, ticket_client_id, ticket_status_name, ticket_status_color FROM tickets LEFT JOIN clients ON ticket_client_id = client_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_closed_at IS NOT NULL ORDER BY ticket_closed_at DESC LIMIT 10");
?>

<div class="card card-body">
    <!-- Icon Cards-->
    <div class="row">
        <div class="col-lg-4 col-6">
            <!-- small box -->
            <a class="small-box bg-secondary" href="clients.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                <div class="inner">
                    <h3><?php echo $clients_added; ?></h3>
                    <p>New Clients</p>
                </div>
                <div class="icon">
                    <i class="fa fa-users"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-6">
            <a class="small-box bg-success" href="contacts.php">
                <div class="inner">
                    <h3><?php echo $contacts_added; ?></h3>
                    <p>New Contacts</p>
                </div>
                <div class="icon">
                    <i class="fa fa-user"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-6">
            <a class="small-box bg-info" href="assets.php">
                <div class="inner">
                    <h3><?php echo $assets_added; ?></h3>
                    <p>New Assets</p>
                </div>
                <div class="icon">
                    <i class="fa fa-desktop"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-6">
            <a class="small-box bg-danger" href="tickets.php">
                <div class="inner">
                    <h3><?php echo $active_tickets; ?></h3>
                    <p>Active Tickets</p>
                </div>
                <div class="icon">
                    <i class="fa fa-ticket-alt"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-6">
            <a class="small-box bg-warning" href="domains.php?sort=domain_expire&order=ASC">
                <div class="inner">
                    <h3><?php echo $expiring_domains; ?></h3>
                    <p>Expiring Domains <small>30 Day</small></p>
                </div>
                <div class="icon">
                    <i class="fa fa-globe"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-4 col-6">
            <a class="small-box bg-primary" href="certificates.php?sort=certificate_expire&order=ASC">
                <div class="inner">
                    <h3><?php echo $expiring_certificates; ?></h3>
                    <p>Expiring Certificates<small>30 Day</small></p>
                </div>
                <div class="icon">
                    <i class="fa fa-lock"></i>
                </div>
            </a>
        </div>
        <!-- ./col -->

        <div class="col-lg-3 col-6">
            <a class="small-box bg-danger" href="tickets.php?assigned=0">
                <div class="inner">
                    <h3><?php echo $unassigned_tickets; ?></h3>
                    <p>Unassigned Tickets</p>
                </div>
                <div class="icon"><i class="fa fa-user-slash"></i></div>
            </a>
        </div>

        <div class="col-lg-3 col-6">
            <a class="small-box bg-info" href="tickets.php">
                <div class="inner">
                    <h3><?php echo $tickets_opened_today; ?></h3>
                    <p>Opened Today</p>
                </div>
                <div class="icon"><i class="fa fa-plus-circle"></i></div>
            </a>
        </div>

        <div class="col-lg-3 col-6">
            <a class="small-box bg-success" href="tickets.php?status=Closed">
                <div class="inner">
                    <h3><?php echo $tickets_resolved_week; ?></h3>
                    <p>Resolved This Week</p>
                </div>
                <div class="icon"><i class="fa fa-check-circle"></i></div>
            </a>
        </div>

        <div class="col-lg-3 col-6">
            <a class="small-box bg-warning" href="tickets.php">
                <div class="inner">
                    <h3><?php echo $tickets_waiting_customer; ?></h3>
                    <p>Waiting on Customer</p>
                </div>
                <div class="icon"><i class="fa fa-clock"></i></div>
            </a>
        </div>
    </div> <!-- row -->

    <!-- Ticket Charts -->
    <div class="row">
        <div class="col-md-12">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-line me-2"></i>Tickets Opened vs Resolved <small>(<?php echo $year; ?>)</small></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240"><canvas id="ticketFlowChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-pie me-2"></i>By Priority</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240"><canvas id="ticketPriorityChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-pie me-2"></i>By Status</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240"><canvas id="ticketStatusChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-chart-pie me-2"></i>By Category</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-h-240"><canvas id="ticketCategoryChart"></canvas></div>
                </div>
            </div>
        </div>

        <?php if ($tech_rows) { ?>
        <div class="col-lg-6">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-users me-2"></i>Open Tickets by Technician</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            <?php foreach ($tech_rows as $tr) { ?>
                            <tr>
                                <td><?= nullable_htmlentities($tr['user_name']) ?></td>
                                <td>
                                    <div class="progress" style="height:18px;">
                                        <div class="progress-bar bg-primary" style="width:<?= min(100, round($tr['c'] / max(1, $active_tickets) * 100)) ?>%">
                                            <?= intval($tr['c']) ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php } ?>
    </div> <!-- ticket charts row -->

    <!-- Past / Historical Ticket Metrics -->
    <div class="row">
        <div class="col-12">
            <h5 class="mt-2 mb-3 text-muted"><i class="fas fa-fw fa-history me-2"></i>Historical Tickets (<?php echo $year; ?>)</h5>
        </div>

        <div class="col-lg-4 col-6">
            <a class="small-box bg-secondary" href="tickets.php">
                <div class="inner">
                    <h3><?php echo $tickets_created_year; ?></h3>
                    <p>Created in <?php echo $year; ?></p>
                </div>
                <div class="icon"><i class="fa fa-ticket-alt"></i></div>
            </a>
        </div>

        <div class="col-lg-4 col-6">
            <a class="small-box bg-success" href="tickets.php">
                <div class="inner">
                    <h3><?php echo $tickets_resolved_year; ?></h3>
                    <p>Resolved in <?php echo $year; ?></p>
                </div>
                <div class="icon"><i class="fa fa-check-double"></i></div>
            </a>
        </div>

        <div class="col-lg-4 col-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3><?php echo $avg_resolution_hours > 0 ? $avg_resolution_hours . 'h' : 'N/A'; ?></h3>
                    <p>Avg Resolution Time</p>
                </div>
                <div class="icon"><i class="fa fa-stopwatch"></i></div>
            </div>
        </div>

        <?php if ($resolved_tech_rows) { ?>
        <div class="col-lg-6">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-user-check me-2"></i>Resolved by Technician (<?php echo $year; ?>)</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            <?php foreach ($resolved_tech_rows as $tr) { ?>
                            <tr>
                                <td><?= nullable_htmlentities($tr['user_name']) ?></td>
                                <td>
                                    <div class="progress" style="height:18px;">
                                        <div class="progress-bar bg-success" style="width:<?= min(100, round($tr['c'] / max(1, $tickets_resolved_year) * 100)) ?>%">
                                            <?= intval($tr['c']) ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php } ?>

        <div class="col-lg-6">
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-fw fa-check-circle me-2"></i>Recently Resolved</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Client</th>
                                <th>Resolved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($rr = mysqli_fetch_assoc($sql_recent_resolved)) {
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
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div> <!-- historical row -->

    <?php if ($your_tickets) { ?>
        <div class="row">
            <div class="col-12">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-fw fa-life-ring me-2"></i>Your Open Tickets</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-sm">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Number</th>
                                    <th>Subject</th>
                                    <th>Client</th>
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
                                        $_sla_breached = $_sla_due < date('Y-m-d H:i:s');
                                        $_sla_color = $_sla_breached ? 'danger' : (strtotime($_sla_due) - time() < 7200 ? 'warning' : 'success');
                                        $_sla_label = $_sla_breached ? 'Breached' : 'OK';
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
            </div>
        </div>
    <?php } ?>

    <?php if (mysqli_num_rows($sql_your_automation_runs) > 0) { ?>
        <div class="row">
            <div class="col-12">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Automation Activity</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
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
            </div>
        </div>
    <?php } ?>
</div> <!-- card -->

<?php } ?>

<?php require_once "../includes/footer.php"; ?>

<?php if ($user_config_dashboard_financial_enable == 1) { ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap-like defaults for Chart.js v4
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // CASH FLOW
    (function () {
        var ctx = document.getElementById("cashFlow");
        if (!ctx) return;

        var myLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
                datasets: [
                    {
                        label: "Income",
                        fill: false,
                        borderColor: "#007bff",
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
            type: 'line',
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

<?php if ($user_config_dashboard_technical_enable == 1) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
// TICKET FLOW (Opened vs Resolved)
(function() {
    var ctx = document.getElementById('ticketFlowChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
            datasets: [
                {
                    label: 'Opened',
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220,53,69,0.08)',
                    pointBackgroundColor: '#dc3545',
                    fill: true,
                    tension: 0.3,
                    data: [<?php echo implode(',', $monthly_opened); ?>]
                },
                {
                    label: 'Resolved',
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40,167,69,0.08)',
                    pointBackgroundColor: '#28a745',
                    fill: true,
                    tension: 0.3,
                    data: [<?php echo implode(',', $monthly_resolved); ?>]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { maxTicksLimit: 5 }, grid: { color: 'rgba(0,0,0,.1)' } }
            },
            plugins: { legend: { display: true } }
        }
    });
})();

// TICKET BY PRIORITY
(function() {
    var ctx = document.getElementById('ticketPriorityChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [<?php echo implode(',', array_map('json_encode', $priority_labels)); ?>],
            datasets: [{
                data: [<?php echo implode(',', $priority_counts); ?>],
                backgroundColor: <?php
                    $pc = [];
                    foreach ($priority_labels as $p) {
                        $pc[] = $p === 'High' ? '"#dc3545"' : ($p === 'Medium' ? '"#ffc107"' : '"#17a2b8"');
                    }
                    echo '[' . implode(',', $pc) . ']';
                ?>
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'right' } } }
    });
})();

// TICKET BY STATUS
(function() {
    var ctx = document.getElementById('ticketStatusChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [<?php echo implode(',', array_map('json_encode', $status_labels)); ?>],
            datasets: [{
                data: [<?php echo implode(',', $status_counts); ?>],
                backgroundColor: [<?php echo implode(',', array_map('json_encode', $status_colors)); ?>]
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'right' } } }
    });
})();

// TICKET BY CATEGORY
(function() {
    var ctx = document.getElementById('ticketCategoryChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [<?php echo implode(',', array_map('json_encode', $cat_labels)); ?>],
            datasets: [{
                data: [<?php echo implode(',', $cat_counts); ?>],
                backgroundColor: [<?php echo implode(',', array_map('json_encode', $cat_colors)); ?>]
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'right' } } }
    });
})();
});
</script>
<?php } ?>
