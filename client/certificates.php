<?php
/*
* Client Portal
* Certificate listing for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    header("Location: post.php?logout");
    exit();
}

$certificates_sql = mysqli_query($mysqli, "SELECT certificate_id, certificate_name, certificate_domain, certificate_issued_by, certificate_expire FROM certificates WHERE certificate_client_id = $session_client_id AND certificate_archived_at IS NULL ORDER BY certificate_expire ASC");
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
                if (mysqli_num_rows($certificates_sql) == 0) { ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No certificates found.</td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($certificates_sql)) {
                    $certificate_name = nullable_htmlentities($row['certificate_name']);
                    $certificate_domain = nullable_htmlentities($row['certificate_domain']);
                    $certificate_issued_by = nullable_htmlentities($row['certificate_issued_by']);
                    $certificate_expire = nullable_htmlentities($row['certificate_expire']);

                    $expire_class = "";
                    if ($certificate_expire && strtotime($certificate_expire) < strtotime('+30 days')) {
                        $expire_class = "text-danger fw-bold";
                    }
                    ?>

                    <tr>
                        <td><?php echo $certificate_name; ?></td>
                        <td><?php echo $certificate_domain; ?></td>
                        <td><?php echo $certificate_issued_by; ?></td>
                        <td class="<?php echo $expire_class; ?>"><?php echo $certificate_expire; ?></td>
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
