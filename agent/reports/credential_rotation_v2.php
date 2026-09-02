<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_credential');

if (isset($_GET['days']) && intval($_GET['days']) >= 0) {
    $days = intval($_GET['days']);
} else {
    $days = 30;
}

$rotation_due_sql = mysqli_query($mysqli,
    "SELECT credential_id, credential_name, credential_description, credential_rotation_due_at, credential_last_rotated_at, credential_client_id, client_id, client_name
        FROM credentials
        LEFT JOIN clients ON credential_client_id = client_id
        WHERE credential_archived_at IS NULL
            AND credential_rotation_due_at IS NOT NULL
            AND credential_rotation_due_at <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
        ORDER BY credential_rotation_due_at ASC"
);

?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-history me-2"></i>Credentials due for rotation (overdue or within <?= intval($days) ?> days)</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
            </div>
        </div>
        <div class="card-body">

            <form class="mb-3">
                <div class="input-group" style="max-width:220px">
                    <span class="input-group-text">Days ahead</span>
                    <input type="number" min="0" class="form-control auto-submit-select" name="days" value="<?= intval($days) ?>">
                </div>
            </form>

            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Credential Name</th>
                        <th>Credential Description</th>
                        <th class="text-end">Rotation Due</th>
                        <th class="text-end">Last Rotated</th>
                    </tr>
                    </thead>
                    <tbody>

                    <?php

                    if (mysqli_num_rows($rotation_due_sql) == 0) { ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No credentials due for rotation in this window.</td>
                        </tr>
                    <?php }

                    while ($row = mysqli_fetch_assoc($rotation_due_sql)) {

                        $credential_id = intval($row['credential_id']);
                        $credential_name = nullable_htmlentities($row['credential_name']);
                        $credential_description = nullable_htmlentities($row['credential_description']);
                        $rotation_due_at = $row['credential_rotation_due_at'];
                        $last_rotated_at = $row['credential_last_rotated_at'];
                        $client_id = intval($row['client_id']);
                        $client_name = nullable_htmlentities($row['client_name']);

                        $is_overdue = ($rotation_due_at !== null && strtotime($rotation_due_at) < strtotime(date('Y-m-d')));

                        ?>

                        <tr>
                            <td><?php echo $client_name; ?></td>
                            <td>
                                <a href="#" class="ajax-modal" data-modal-url="../modals/credential/credential_view.php?id=<?= $credential_id ?>"><?php echo $credential_name; ?></a>
                            </td>
                            <td><?php echo $credential_description; ?></td>
                            <td class="text-end">
                                <?php if ($is_overdue) { ?>
                                    <span class="badge bg-danger"><?= nullable_htmlentities($rotation_due_at) ?> (overdue)</span>
                                <?php } else { ?>
                                    <?= nullable_htmlentities($rotation_due_at) ?>
                                <?php } ?>
                            </td>
                            <td class="text-end"><?= $last_rotated_at ? timeAgo($last_rotated_at) . " (" . nullable_htmlentities($last_rotated_at) . ")" : '<span class="text-muted">never</span>' ?></td>
                        </tr>

                    <?php } ?>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php
require_once "../../includes/footer.php";
