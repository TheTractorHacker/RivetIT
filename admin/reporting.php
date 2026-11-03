<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Reporting\EditionViews;
use ITFlow\Reporting\ReportingAdmin;
use RivetCore\Reporting\ReportingRole;

// Administration > Reporting / Power BI (RivetCore issue #91, phase 4). Administrators only. The reporting views hold ALL clients' data, so a technician
// scoped to some clients never gets here (inc_all_admin refuses everyone who is not an administrator).
$csrf = $_SESSION['csrf_token'];
$h = static fn ($s): string => nullable_htmlentities((string) $s);
$db_name = (string) (mysqli_fetch_row(mysqli_query($mysqli, 'SELECT DATABASE()'))[0] ?? '');
$st = ReportingAdmin::status($mysqli, $db_name);
$test_result = $_SESSION['reporting_test_result'] ?? null;
unset($_SESSION['reporting_test_result']);

require_once dirname(__DIR__) . '/includes/event_bus.php';
require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
$data_store = 'mariadb';
try { $data_store = (string) rivetRmmModule($mysqli)->settings()->dataStore(); } catch (\Throwable $e) { $data_store = 'mariadb'; }
$is_pg = $data_store === 'postgres';
$package = dirname(__DIR__) . '/vendor/rivet/rivet-core';
$default_host = (string) ($GLOBALS['dbhost'] ?? 'localhost');
$view_total = count($st['core']) + count($st['edition']);
$view_have = count(array_filter($st['core'], fn ($v) => $v['exists'])) + count(array_filter($st['edition'], fn ($v) => $v['exists']));
$warnings = ReportingAdmin::warnings($mysqli, '%', true);
$pq_view = preg_match('/^rpt_[a-z0-9_]+$/', (string) ($_GET['pq'] ?? '')) ? (string) $_GET['pq'] : 'rpt_devices';
$pq_server = trim((string) ($_GET['pq_server'] ?? ''));
$pq = $pq_server !== '' ? ReportingAdmin::powerQuery('mysql-mariadb', $pq_view, $pq_server, $db_name, $package) : null;
?>
<div class="card card-dark mb-3">
    <div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw fa-chart-bar mr-2"></i>Reporting / Power BI</h3></div>
    <div class="card-body">
        <p class="text-muted">Read-only reporting views of your fleet and tickets that Power BI (or any SQL tool) can read through a dedicated database account. The account can read the views and nothing else.
        <strong>The views hold every client's data.</strong> Create the account for the gateway that needs it, rotate its password when people leave, and drop it when you stop using it.
        Guide: <code>vendor/rivet/rivet-core/docs/reporting/POWER_BI.md</code>.</p>
        <p class="mb-1"><strong><?= $view_have ?></strong> of <?= $view_total ?> views exist in <code><?= $h($db_name) ?></code>. Core migration 0047 is <?= $st['coreMigration'] ? 'applied' : '<span class="text-danger">NOT applied</span> (run the database update)' ?>.</p>
        <?php if ($st['editionZone'] !== null) { $z = $st['editionZone']; ?>
            <p class="mb-0 small <?= (!$z['utc'] && !$z['converted']) ? 'text-danger' : 'text-muted' ?>">
                Time zone: RivetIT stores its ticket and asset times in the company time zone (<code><?= $h($z['zone'] ?? 'unknown') ?></code>).
                <?php if ($z['utc']) { ?>That is UTC, so the views need no conversion.
                <?php } elseif ($z['converted']) { ?>The RivetIT views convert them to UTC.
                <?php } else { ?><strong>The RivetIT views are NOT converted to UTC<?= $z['usable'] ? ' (the server can convert: rebuild them below)' : ': the database server\'s time zone tables are not loaded (run mariadb-tzinfo-to-sql), then rebuild them' ?>.</strong><?php } ?>
            </p>
        <?php } ?>
        <form action="post.php" method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button class="btn btn-sm btn-outline-secondary" name="rebuild_reporting_views" value="1" type="submit">Rebuild the RivetIT views</button></form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Views</h4></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>View</th><th>What a row is</th><th>Client scoped</th><th>Store</th><th>Exists</th></tr></thead><tbody>
        <?php foreach ($st['core'] as $v) { ?>
            <tr><td><code><?= $h($v['name']) ?></code></td><td><?= $h($v['grain']) ?></td><td><?= $v['scoped'] ? 'yes' : 'no' ?></td><td class="text-nowrap"><?= $h($v['store']) ?></td><td><?= $v['exists'] ? 'yes' : '<span class="text-danger">no</span>' ?></td></tr>
        <?php } foreach ($st['edition'] as $v) { ?>
            <tr><td><code><?= $h($v['name']) ?></code></td><td>RivetIT: <?= $h(implode(', ', $v['columns'])) ?></td><td><?= $v['scoped'] ? 'yes' : 'no' ?></td><td class="text-nowrap">control</td><td><?= $v['exists'] ? 'yes' : '<span class="text-danger">no</span>' ?></td></tr>
        <?php } ?></tbody></table></div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Create or rotate the reporting account (<?= $is_pg ? 'MariaDB / MySQL; PostgreSQL below' : 'MariaDB / MySQL' ?>)</h4></div>
    <div class="card-body">
        <?php foreach ($warnings as $w) { ?><div class="alert alert-warning py-2 mb-2"><?= $h($w) ?></div><?php } ?>
        <form action="post.php" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label">Account name</label><input class="form-control" name="account_user" value="<?= $h(ReportingRole::DEFAULT_USER) ?>" pattern="[A-Za-z][A-Za-z0-9_]{0,31}" required></div>
                <div class="col-md-4"><label class="form-label">Host part (who may connect)</label><input class="form-control" name="account_host" placeholder="10.0.5.20" required><div class="form-text">The address of the Power BI gateway. <code>%</code> allows any address and is flagged.</div></div>
                <div class="col-md-4"><label class="form-label">Host name Power BI will use</label><input class="form-control" name="public_host" placeholder="db.example.com"><div class="form-text">Shown with the password. The application's own host (<code><?= $h($default_host) ?></code>) is often not reachable from Power BI.</div></div>
            </div>
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="require_tls" name="require_tls" value="1" checked><label class="form-check-label" for="require_tls">Require TLS (recommended)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="include_audit" name="include_audit" value="1"><label class="form-check-label" for="include_audit">Also grant <code>rpt_audit_events</code> (the audit trail; it is not limited to any client)</label></div>
            <hr>
            <p class="small text-muted mb-2">A database administrator login is used <strong>once</strong> to create the account. It is not stored, logged or shown.</p>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label">Database server</label><input class="form-control" name="db_host" value="<?= $h($default_host) ?>" required></div>
                <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" type="number" name="db_port" value="3306" min="1" max="65535" required></div>
                <div class="col-md-3"><label class="form-label">Administrator login</label><input class="form-control" name="admin_user" autocomplete="off" required></div>
                <div class="col-md-3"><label class="form-label">Administrator password</label><input class="form-control" type="password" name="admin_pass" autocomplete="new-password"></div>
            </div>
            <button class="btn btn-primary mt-3" name="provision_reporting" value="1" type="submit">Create / rotate account</button>
        </form>
        <p class="small text-muted mt-3 mb-0"><?= $h(implode(' ', ReportingRole::notes('mariadb'))) ?></p>
    </div>
</div>

<?php if ($is_pg) { ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">PostgreSQL reporting role (RMM data store)</h4></div>
    <div class="card-body">
        <p class="small text-muted">Uses the data store's own saved connection; that role must be allowed to create roles. <?= $h(implode(' ', ReportingRole::notes('postgres'))) ?></p>
        <form action="post.php" method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-4"><label class="form-label">Role name</label><input class="form-control" name="account_user" value="<?= $h(ReportingRole::DEFAULT_USER) ?>" pattern="[A-Za-z][A-Za-z0-9_]{0,62}" required></div>
            <div class="col-md-8"><button class="btn btn-primary" name="provision_reporting_pg" value="1" type="submit">Create / rotate role</button></div>
        </form>
        <form action="post.php" method="post" class="row g-2 align-items-end mt-2" data-confirm-submit="Drop the PostgreSQL reporting role?"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-4"><label class="form-label">Type the role name to drop it</label><input class="form-control" name="confirm_name" autocomplete="off"><input type="hidden" name="account_user" value="<?= $h(ReportingRole::DEFAULT_USER) ?>"></div>
            <div class="col-md-8"><button class="btn btn-outline-danger" name="drop_reporting_pg" value="1" type="submit">Drop role</button></div>
        </form>
    </div>
</div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Test the connection</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" class="row g-2" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-3"><label class="form-label">Server</label><input class="form-control" name="test_host" value="<?= $h($default_host) ?>" required></div>
            <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" type="number" name="test_port" value="3306" required></div>
            <div class="col-md-3"><label class="form-label">Account name</label><input class="form-control" name="account_user" value="<?= $h(ReportingRole::DEFAULT_USER) ?>" required></div>
            <div class="col-md-4"><label class="form-label">Account password (not stored)</label><input class="form-control" type="password" name="test_pass" autocomplete="new-password" required></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="test_tls" name="test_tls" value="1" checked><label class="form-check-label" for="test_tls">Connect with TLS</label></div></div>
            <div class="col-12"><button class="btn btn-primary" name="test_reporting" value="1" type="submit">Test</button></div>
        </form>
        <?php if (is_array($test_result)) { ?>
        <table class="table table-sm mt-3 mb-0"><tbody><?php foreach ($test_result as $c) { ?>
            <tr><td><?= $c['ok'] ? '<span class="badge bg-success">PASS</span>' : '<span class="badge bg-danger">FAIL</span>' ?></td><td><?= $h($c['name']) ?></td><td class="small text-muted"><?= $h($c['detail']) ?></td></tr>
        <?php } ?></tbody></table><?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Power Query for this install (MariaDB / MySQL)</h4></div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label">View</label><select class="form-select" name="pq">
            <?php foreach ($st['core'] as $v) { ?><option value="<?= $h($v['name']) ?>"<?= $v['name'] === $pq_view ? ' selected' : '' ?>><?= $h($v['name']) ?></option><?php } ?></select></div>
            <div class="col-md-5"><label class="form-label">Server Power BI connects to (host:port)</label><input class="form-control" name="pq_server" value="<?= $h($pq_server) ?>" placeholder="db.example.com:3306"></div>
            <div class="col-md-3"><button class="btn btn-outline-primary" type="submit">Show</button></div></form>
        <?php if ($pq !== null) { ?>
            <div class="mt-3"><button type="button" class="btn btn-sm btn-outline-secondary clipboardjs mb-2" data-clipboard-text="<?= $h($pq) ?>">Copy</button><pre class="border rounded p-2 small mb-0" style="white-space: pre-wrap"><?= $h($pq) ?></pre></div>
        <?php } elseif ($pq_server !== '') { ?><p class="mt-3 text-muted mb-0">There is no sample for that view.</p><?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">One report for several clients</h4></div>
    <div class="card-body small">
        <p>Every client-scoped view carries <code>client_id</code>. In Power BI, row-level security (RLS) can limit each viewer to one client's rows; see the "Row-level security per client" section of <code>POWER_BI.md</code>.</p>
        <p class="mb-0"><strong>RLS is not a tenant boundary.</strong> Anyone who can open the report file or the dataset settings, or who knows the account password, can read every client's rows. The account created here sees all clients. Per-client accounts and tokens are not offered.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Drop the reporting account</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off" class="row g-2" data-confirm-submit="Drop this reporting account? Power BI loses access immediately."><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-3"><label class="form-label">Account name</label><input class="form-control" name="account_user" value="<?= $h(ReportingRole::DEFAULT_USER) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Host part</label><input class="form-control" name="account_host" required></div>
            <div class="col-md-3"><label class="form-label">Type the account name to confirm</label><input class="form-control" name="confirm_name" required></div>
            <div class="col-md-3"><label class="form-label">Database server</label><input class="form-control" name="db_host" value="<?= $h($default_host) ?>" required></div>
            <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" type="number" name="db_port" value="3306" required></div>
            <div class="col-md-3"><label class="form-label">Administrator login</label><input class="form-control" name="admin_user" required></div>
            <div class="col-md-3"><label class="form-label">Administrator password</label><input class="form-control" type="password" name="admin_pass" autocomplete="new-password"></div>
            <div class="col-12"><button class="btn btn-outline-danger" name="drop_reporting" value="1" type="submit">Drop account</button></div>
        </form>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>
