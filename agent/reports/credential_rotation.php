<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_credential');

if (isset($_GET['days']) && intval($_GET['days']) > 0) {
    $days = intval($_GET['days']);
} else {
    $days = 90;
}

$passwords_not_rotated_sql = mysqli_query($mysqli,
    "SELECT credential_id, credential_name, credential_description, credential_password_changed_at, credential_client_id, client_id, client_name
        FROM credentials
        LEFT JOIN clients ON credential_client_id = client_id
        WHERE DATE(credential_password_changed_at) < DATE_SUB(CURDATE(), INTERVAL $days DAY)
        ORDER BY client_name"
);

?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Client credentials not changed/rotated in the last <?= intval($days) ?> days</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
            </div>
        </div>
        <div class="card-body">

            <form class="mb-3">
                <div class="input-group" style="max-width:220px">
                    <span class="input-group-text">Days</span>
                    <input type="number" min="1" class="form-control auto-submit-select" name="days" value="<?= intval($days) ?>">
                </div>
            </form>

            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Client</th>
                        <th class="text-end">Credential Name</th>
                        <th class="text-end">Credential Description</th>
                        <th class="text-end">Credential Password Last Changed</th>
                    </tr>
                    </thead>
                    <tbody>

                    <?php

                    while ($row = mysqli_fetch_assoc($passwords_not_rotated_sql)) {

                        $credential_id = intval($row['credential_id']);
                        $credential_name = nullable_htmlentities($row['credential_name']);
                        $credential_description = nullable_htmlentities($row['credential_description']);
                        $credential_password_changed = nullable_htmlentities($row['credential_password_changed_at']);
                        $client_id = intval($row['client_id']);
                        $client_name = nullable_htmlentities($row['client_name']);

                        ?>

                        <tr>
                            <td><?php echo $client_name; ?></td>
                            <td class="text-end"><?php echo $credential_name; ?></td>
                            <td class="text-end"><?php echo $credential_description; ?></td>
                            <td class="text-end"><?php echo timeAgo($credential_password_changed) . " (" . $credential_password_changed . ")" ?></td>
                        </tr>

                    <?php } ?>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php
require_once "../../includes/footer.php";
