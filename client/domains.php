<?php
/*
* Client Portal
* Domain listing for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect.
ob_start();
require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    ob_end_clean();
    // a contact without this role used to be signed out of the whole portal; send them home with a reason instead
    flash_alert(portal_no_access_message(__FILE__), 'warning');
    header("Location: index.php");
    exit();
}

$domains_sql = mysqli_query($mysqli, "SELECT domain_id, domain_name, domain_expire FROM domains WHERE domain_client_id = $session_client_id AND domain_archived_at IS NULL ORDER BY domain_expire ASC");
$domain_rows = mysqli_fetch_all($domains_sql, MYSQLI_ASSOC);
$expiry_note = portal_expiry_summary(array_column($domain_rows, 'domain_expire'), 'domain');
?>

    <div class="row mb-4">
        <div class="col">
            <h3><i class="fas fa-fw fa-globe me-2"></i>Domains</h3>
        </div>
    </div>
    <div class="row">

        <div class="col-md-12">

            <div class="card card-outline card-primary">
                <div class="card-body p-0">
                    <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                <tr>
                    <th>Domain Name</th>
                    <th>Expiry</th>
                </tr>
                </thead>
                <tbody>

                <?php
                if (count($domain_rows) == 0) { ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted py-4">No domains found.</td>
                    </tr>
                <?php }
                foreach ($domain_rows as $row) {
                    $domain_name = nullable_htmlentities($row['domain_name']);
                    $domain_expire = nullable_htmlentities($row['domain_expire']);

                    [$exp_mod, $exp_label] = portal_expiry_state($row['domain_expire']);
                    ?>

                    <tr>
                        <td><i class="fas fa-fw fa-globe text-secondary me-2"></i><?php echo $domain_name; ?></td>
                        <td><div class="portal-expiry-cell"><span class="portal-expiry-date"><?php echo $domain_expire; ?></span><span class="portal-badge portal-badge--<?php echo $exp_mod; ?>"><?php echo $exp_label; ?></span></div></td>
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
