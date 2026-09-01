<?php
/*
* Client Portal
* Domain listing for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    header("Location: post.php?logout");
    exit();
}

$domains_sql = mysqli_query($mysqli, "SELECT domain_id, domain_name, domain_expire FROM domains WHERE domain_client_id = $session_client_id AND domain_archived_at IS NULL ORDER BY domain_expire ASC");
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
                if (mysqli_num_rows($domains_sql) == 0) { ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted py-4">No domains found.</td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($domains_sql)) {
                    $domain_name = nullable_htmlentities($row['domain_name']);
                    $domain_expire = nullable_htmlentities($row['domain_expire']);

                    $expire_class = "";
                    if ($domain_expire && strtotime($domain_expire) < strtotime('+30 days')) {
                        $expire_class = "text-danger fw-bold";
                    }
                    ?>

                    <tr>
                        <td><i class="fas fa-fw fa-globe text-secondary me-2"></i><?php echo $domain_name; ?></td>
                        <td class="<?php echo $expire_class; ?>"><?php echo $domain_expire; ?></td>
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
