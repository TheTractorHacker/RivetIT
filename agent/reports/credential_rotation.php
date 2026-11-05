<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_headline.php";

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
        WHERE credential_archived_at IS NULL
            AND DATE(credential_password_changed_at) < DATE_SUB(CURDATE(), INTERVAL $days DAY)" . \ITFlow\Reports\ReportScope::clause('credential_client_id') . "
        ORDER BY client_name"
);

$cr_rows = [];
while ($r = mysqli_fetch_assoc($passwords_not_rotated_sql)) { $cr_rows[] = $r; }
if ($cr_rows) {
    $oldest = $cr_rows[0];
    foreach ($cr_rows as $r) { if ($r['credential_password_changed_at'] < $oldest['credential_password_changed_at']) { $oldest = $r; } }
    $cr_depts = count(array_unique(array_column($cr_rows, 'client_id')));
    $oldest_days = (int) floor((time() - strtotime($oldest['credential_password_changed_at'])) / 86400);
    reportHeadline('fa-key', count($cr_rows) . ' credential' . (count($cr_rows) === 1 ? ' has' : 's have') . " not been rotated in $days days",
        "Oldest: {$oldest['credential_name']} ($oldest_days days). Spread across $cr_depts department" . ($cr_depts === 1 ? '' : 's') . '.',
        'warning', ['Overdue' => count($cr_rows), 'Oldest (days)' => $oldest_days]);
} else {
    reportHeadline('fa-check-circle', "Every credential was rotated within $days days", 'Nothing to do here.', 'success');
}
?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Department credentials not changed/rotated in the last <?= intval($days) ?> days</h3>
        </div>
        <div class="card-body">

            <form class="mb-3">
                <div class="input-group" style="max-width:220px">
                    <span class="input-group-text">Days</span>
                    <input type="number" min="1" class="form-control auto-submit-select" name="days" aria-label="Days since last change" value="<?= intval($days) ?>">
                </div>
            </form>

            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Credential Name</th>
                        <th>Description</th>
                        <th class="text-end">Password last changed</th>
                    </tr>
                    </thead>
                    <tbody>

                    <?php

                    foreach ($cr_rows as $row) {

                        $credential_id = intval($row['credential_id']);
                        $credential_name = nullable_htmlentities($row['credential_name']);
                        $credential_description = nullable_htmlentities($row['credential_description']);
                        $credential_password_changed = nullable_htmlentities($row['credential_password_changed_at']);
                        $client_id = intval($row['client_id']);
                        $client_name = nullable_htmlentities($row['client_name']);

                        ?>

                        <tr>
                            <td><?php echo $client_name; ?></td>
                            <td><?php echo $credential_name; ?></td>
                            <td><?php echo $credential_description; ?></td>
                            <td class="text-end"><?php echo timeAgo($credential_password_changed) . " (" . $credential_password_changed . ")" ?></td>
                        </tr>

                    <?php } ?>

                    <?php if (!$cr_rows) { ?><tr><td colspan="4" class="text-center text-secondary py-4">Nothing overdue. Every active credential changed within <?= intval($days) ?> days.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php
require_once "../../includes/footer.php";
