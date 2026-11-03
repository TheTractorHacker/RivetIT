<?php
/*
* Client Portal
* Certificate listing for PTC / technical contacts
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

$certificates_sql = mysqli_query($mysqli, "SELECT certificate_id, certificate_name, certificate_domain, certificate_issued_by, certificate_expire FROM certificates WHERE certificate_client_id = $session_client_id AND certificate_archived_at IS NULL ORDER BY certificate_expire ASC");
$certificate_rows = mysqli_fetch_all($certificates_sql, MYSQLI_ASSOC);
$expiry_note = portal_expiry_summary(array_column($certificate_rows, 'certificate_expire'), 'certificate');
?>

    <div class="row mb-4">
        <div class="col">
            <h3><i class="fas fa-fw fa-certificate me-2"></i>Web Certificates</h3>
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
                    <th>Certificate Name</th>
                    <th>FQDN</th>
                    <th>Issuer</th>
                    <th>Expiry</th>
                </tr>
                </thead>
                <tbody>

                <?php
                if (count($certificate_rows) == 0) { ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No certificates found.</td>
                    </tr>
                <?php }
                foreach ($certificate_rows as $row) {
                    $certificate_name = nullable_htmlentities($row['certificate_name']);
                    $certificate_domain = nullable_htmlentities($row['certificate_domain']);
                    $certificate_issued_by = nullable_htmlentities($row['certificate_issued_by']);
                    $certificate_expire = nullable_htmlentities($row['certificate_expire']);

                    [$exp_mod, $exp_label] = portal_expiry_state($row['certificate_expire']);
                    ?>

                    <tr>
                        <td><?php echo $certificate_name; ?></td>
                        <td><?php echo $certificate_domain; ?></td>
                        <td><?php echo $certificate_issued_by; ?></td>
                        <td><div class="portal-expiry-cell"><span class="portal-expiry-date"><?php echo $certificate_expire; ?></span><span class="portal-badge portal-badge--<?php echo $exp_mod; ?>"><?php echo $exp_label; ?></span></div></td>
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
