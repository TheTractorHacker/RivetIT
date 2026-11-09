<?php
/*
* Client Portal
* Contact management for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect.
ob_start();
require_once "includes/inc_all.php";

// Department administrators (primary / technical contacts) see the department's assets and can switch between "All in my
// department" and "Mine". Every other contact sees ONLY the devices assigned to them: the scope is forced to mine, so a
// crafted ?scope=all does nothing. (Before the employee portal this page logged non-administrators out.)
$assets_is_admin = ($session_contact_primary == 1 || $session_contact_is_technical_contact);
$assets_scope = ($assets_is_admin && ($_GET['scope'] ?? 'all') !== 'mine') ? 'all' : 'mine';

if ($assets_scope === 'mine') {
    // contact_id 0 (admin preview) must never match unassigned assets (asset_contact_id = 0)
    $assets_where = "asset_contact_id = $session_contact_id AND $session_contact_id > 0";
} else {
    $assets_where = "1 = 1";
}

$assets_sql = mysqli_query($mysqli, "SELECT assets.*, contacts.contact_name, status_cat.category_color AS asset_status_color FROM assets LEFT JOIN contacts ON asset_contact_id = contact_id LEFT JOIN categories AS status_cat ON status_cat.category_name = assets.asset_status AND status_cat.category_type = 'asset_status' AND status_cat.category_archived_at IS NULL WHERE asset_client_id = $session_client_id AND $assets_where AND asset_archived_at IS NULL ORDER BY asset_type ASC, asset_name ASC");
?>

    <div class="row mb-4">
        <div class="col">
            <h2><i class="fas fa-fw fa-desktop me-2"></i><?php echo $assets_scope === 'mine' ? 'My devices' : 'Assets'; ?></h2>
        </div>
        <?php if ($assets_is_admin) { ?>
        <div class="col-auto">
            <div class="btn-group" role="group" aria-label="Which assets">
                <a href="?scope=mine" class="btn btn-sm <?php echo $assets_scope === 'mine' ? 'btn-primary' : 'btn-outline-primary'; ?>">Mine</a>
                <a href="?scope=all" class="btn btn-sm <?php echo $assets_scope === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All in my department</a>
            </div>
        </div>
        <?php } ?>
    </div>

<?php
if ($assets_scope === 'mine') {
    // One card per device with the facts an employee asks about (OS, last seen, warranty). Read-only; no credentials, IPs or notes.
    require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
    $my_devices = (new \ITFlow\Portal\EmployeeHome($mysqli))->myDeviceDetails(intval($session_contact_id), intval($session_client_id));
    ?>
    <div class="row g-3" id="my-devices">
        <?php if (!$my_devices) { ?>
            <div class="col-12"><div class="card portal-card"><div class="card-body"><div class="portal-empty"><i class="fas fa-laptop" aria-hidden="true"></i>No devices are assigned to you.</div></div></div></div>
        <?php }
        foreach ($my_devices as $d) {
            [$seen_class, $seen_text] = \ITFlow\Portal\EmployeeHome::seenLabel($d);
            [$war_class, $war_text] = \ITFlow\Portal\EmployeeHome::warrantyLabel($d); ?>
            <div class="col-lg-6 col-md-12">
                <div class="card portal-card h-100 portal-device" data-asset-id="<?= intval($d['id']) ?>">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h3 class="card-title mb-0"><span class="portal-card-chip"><i class="fas fa-desktop" aria-hidden="true"></i></span><?= nullable_htmlentities($d['name']) ?></h3>
                        <a class="btn btn-sm btn-outline-secondary text-nowrap" href="ticket_add.php?asset_id=<?= intval($d['id']) ?>"><i class="fas fa-fw fa-life-ring me-1" aria-hidden="true"></i>Report a problem</a>
                    </div>
                    <div class="card-body">
                        <?php if ($d['description'] !== '') { ?><p class="text-secondary mb-2"><?= nullable_htmlentities($d['description']) ?></p><?php } ?>
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-secondary fw-normal">Type</dt><dd class="col-7"><?= nullable_htmlentities($d['type']) ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Make and model</dt><dd class="col-7"><?= nullable_htmlentities(trim($d['make'] . ' ' . $d['model'])) ?: '-' ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Serial number</dt><dd class="col-7"><?= nullable_htmlentities($d['serial']) ?: '-' ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Operating system</dt><dd class="col-7"><?= $d['os'] !== null ? nullable_htmlentities($d['os']) : '-' ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Status</dt><dd class="col-7"><?= nullable_htmlentities($d['status']) ?: '-' ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Last seen</dt><dd class="col-7"><span class="text-<?= $seen_class ?>"><?= nullable_htmlentities($seen_text) ?></span><?php if ($d['reboot_pending']) { ?> <span class="badge text-bg-warning">Restart needed</span><?php } ?></dd>
                            <dt class="col-5 text-secondary fw-normal">Warranty</dt><dd class="col-7"><span class="badge text-bg-<?= $war_class ?>"><?= nullable_htmlentities($war_text) ?></span></dd>
                            <dt class="col-5 text-secondary fw-normal">Purchased</dt><dd class="col-7"><?= $d['purchase_date'] ? nullable_htmlentities($d['purchase_date']) : '-' ?></dd>
                            <dt class="col-5 text-secondary fw-normal">In service since</dt><dd class="col-7"><?= $d['install_date'] ? nullable_htmlentities($d['install_date']) : '-' ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
<?php
    require_once "includes/footer.php";
    exit;
}
?>

    <div class="row">

        <div class="col-md-12">

            <div class="card card-outline card-primary">
                <div class="card-body p-0">
                    <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>Assigned</th>
                    <th>Purchase</th>
                    <th>Warranty</th>
                    <th>Status</th>
                    <th>URI</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                <?php
                if (mysqli_num_rows($assets_sql) == 0) { ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4"><?php echo $assets_scope === 'mine' ? 'No devices are assigned to you.' : 'No assets found.'; ?></td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($assets_sql)) {
                    $asset_id = intval($row['asset_id']);
                    $asset_name = nullable_htmlentities($row['asset_name']);
                    $asset_description = nullable_htmlentities($row['asset_description']);
                    $asset_type = nullable_htmlentities($row['asset_type']);
                    $asset_make = nullable_htmlentities($row['asset_make']);
                    $asset_model = nullable_htmlentities($row['asset_model']);
                    $asset_serial = nullable_htmlentities($row['asset_serial']);
                    $asset_purchase_date = nullable_htmlentities($row['asset_purchase_date'] ?? "-");
                    $asset_warranty_expire = nullable_htmlentities($row['asset_warranty_expire'] ?? "-");
                    $assigned_to = nullable_htmlentities($row['contact_name'] ?? "-");
                    $asset_status = nullable_htmlentities($row['asset_status']);
                    $asset_status_color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $row['asset_status_color']) ? $row['asset_status_color'] : '';
                    $asset_uri_client = sanitize_url($row['asset_uri_client']);

                    ?>

                    <tr>
                        <td>
                            <a href="#"><?php echo $asset_name ?></a>
                            <br>
                            <small class="text-secondary"><?php echo $asset_description; ?></small>
                        </td>
                        <td><?php echo $asset_type; ?></td>
                        <td><?php echo "$asset_make<br><span class='text-secondary'>$asset_model</span>"; ?></td>
                        <td><?php echo $asset_serial; ?></td>
                        <td><?php echo $assigned_to; ?></td>
                        <td><?php echo $asset_purchase_date; ?></td>
                        <td><?php echo $asset_warranty_expire; ?></td>
                        <td><span class="badge badge-secondary"><?php echo $asset_status; ?></span></td>
                        <td>
                            <?php if ($asset_uri_client) { ?>
                            <i class="fa fa-fw fa-link text-secondary me-1"></i><a href="<?php echo $asset_uri_client; ?>" target="_blank" title="<?php echo $asset_uri_client; ?>"><?php echo truncate($asset_uri_client, 40); ?></a>
                            <?php } else { ?>
                            -
                        <?php } ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($session_contact_id > 0 && intval($row['asset_contact_id']) === intval($session_contact_id)) { ?>
                                <a class="btn btn-sm btn-outline-secondary" href="ticket_add.php?asset_id=<?php echo $asset_id; ?>"><i class="fas fa-fw fa-life-ring me-1" aria-hidden="true"></i>Report a problem</a>
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

<?php
require_once "includes/footer.php";
