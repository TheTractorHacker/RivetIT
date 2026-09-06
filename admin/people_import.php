<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 2);

$preview = $_SESSION['people_import_preview'] ?? null;

?>

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-fw fa-file-import me-2"></i>People Import</h3>
    </div>
    <div class="card-body">

        <?php if (!$preview) { ?>

            <p class="text-muted">Bulk-create or update department contacts (people) from a CSV file. Nothing is written until you review and approve the preview.</p>

            <div class="alert alert-info">
                <strong>Required columns:</strong> <code>name</code>, <code>email</code>, <code>department</code> (must match an existing Department name exactly)<br>
                <strong>Optional columns:</strong> <code>employee_id</code>, <code>job_title</code>, <code>site</code>, <code>manager_email</code>, <code>phone</code>, <code>mobile</code>, <code>start_date</code> (YYYY-MM-DD), <code>employee_type</code> (employee/contractor/vendor/intern/service_account_owner), <code>employment_status</code> (pre-hire/active/leave/suspended/transfer_pending/termination_pending/terminated/archived), <code>work_arrangement</code> (remote/hybrid/onsite)
            </div>

            <p><a href="post.php?download_people_import_template&csrf_token=<?= $_SESSION['csrf_token'] ?>">Download a sample CSV template</a></p>

            <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <input type="file" class="form-control-file" name="file" accept=".csv" required>
                </div>
                <hr>
                <button type="submit" name="preview_people_import" class="btn btn-primary text-bold"><i class="fas fa-eye me-2"></i>Preview Import</button>
            </form>

        <?php } else { ?>

            <?php
            $total = count($preview['rows']);
            $errorCount = count(array_filter($preview['rows'], fn($r) => $r['action'] === 'error'));
            $createCount = count(array_filter($preview['rows'], fn($r) => $r['action'] === 'create'));
            $updateCount = count(array_filter($preview['rows'], fn($r) => $r['action'] === 'update'));
            ?>

            <p>
                <strong><?= $total ?></strong> row(s) parsed from <strong><?= nullable_htmlentities($preview['filename']) ?></strong>:
                <span class="badge text-bg-success"><?= $createCount ?> to create</span>
                <span class="badge text-bg-info"><?= $updateCount ?> to update</span>
                <span class="badge text-bg-danger"><?= $errorCount ?> with errors (will be skipped)</span>
            </p>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Site</th>
                            <th>Manager</th>
                            <th>Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['rows'] as $r) { ?>
                            <tr class="<?= $r['action'] === 'error' ? 'table-danger' : '' ?>">
                                <td>
                                    <?php if ($r['action'] === 'create') { ?><span class="badge text-bg-success">Create</span>
                                    <?php } elseif ($r['action'] === 'update') { ?><span class="badge text-bg-info">Update</span>
                                    <?php } else { ?><span class="badge text-bg-danger">Skip</span><?php } ?>
                                </td>
                                <td><?= nullable_htmlentities($r['raw']['name'] ?? '') ?></td>
                                <td><?= nullable_htmlentities($r['raw']['email'] ?? '') ?></td>
                                <td><?= nullable_htmlentities($r['raw']['department'] ?? '') ?></td>
                                <td><?= nullable_htmlentities($r['raw']['site'] ?? '') ?></td>
                                <td><?= nullable_htmlentities($r['raw']['manager_email'] ?? '') ?></td>
                                <td class="text-danger small"><?= nullable_htmlentities(implode('; ', $r['errors'])) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <form action="post.php" method="post" autocomplete="off" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="approve_people_import" class="btn btn-primary text-bold" <?= ($createCount + $updateCount) === 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-check me-2"></i>Approve &amp; Import <?= $createCount + $updateCount ?> Row(s)
                </button>
            </form>
            <form action="post.php" method="post" autocomplete="off" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="cancel_people_import" class="btn btn-light"><i class="fas fa-times me-2"></i>Cancel, Start Over</button>
            </form>

        <?php } ?>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
