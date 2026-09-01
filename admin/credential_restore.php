<?php

/*
 * ITFlow - Credential Restore
 * One-off recovery tool: upload a manual backup zip, browse credentials from it
 * (loaded into staging tables in the live database), decrypt them with a
 * manually-supplied master key, and restore the username/password into the
 * live credential vault (re-encrypted under the current session's vault key).
 */

require_once "includes/inc_all_admin.php";

$decrypted = false;
$master_key = '';

if (isset($_POST['decrypt_with_master_key'])) {
    validateCSRFToken($_POST['csrf_token']);
    $master_key = (string) ($_POST['master_key'] ?? '');
    $decrypted = $master_key !== '';
}

// The staging tables are shared/global (rebuilt from whatever backup was last
// uploaded), so only the admin who uploaded the currently-staged backup is allowed
// to see it's loaded/browse it - otherwise any admin could browse and restore
// another admin's uploaded backup by just opening this page. Ownership is tracked
// in credential_restore_staging_meta, written by the upload_backup POST handler.
$backup_loaded = false;
$check = mysqli_query($mysqli, "SHOW TABLES LIKE 'credential_restore_staging'");
if ($check && mysqli_num_rows($check) > 0) {
    $meta_check = mysqli_query($mysqli, "SHOW TABLES LIKE 'credential_restore_staging_meta'");
    $staging_owner_user_id = null;
    if ($meta_check && mysqli_num_rows($meta_check) > 0) {
        $meta_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT uploaded_by_user_id FROM credential_restore_staging_meta LIMIT 1"));
        if ($meta_row) {
            $staging_owner_user_id = intval($meta_row['uploaded_by_user_id']);
        }
    }

    if ($staging_owner_user_id !== null && $staging_owner_user_id === intval($session_user_id)) {
        $backup_loaded = true;
    }
}

$results = [];

if ($decrypted && $backup_loaded) {
    $sql = mysqli_query($mysqli, "
        SELECT oc.*, ocl.client_name AS old_client_name, lc.credential_id AS live_credential_id
        FROM credential_restore_staging oc
        LEFT JOIN credential_restore_staging_clients ocl ON ocl.client_id = oc.credential_client_id
        LEFT JOIN credentials lc ON lc.credential_id = oc.credential_id
        WHERE oc.credential_name LIKE '%$q%'
           OR oc.credential_username LIKE '%$q%'
           OR ocl.client_name LIKE '%$q%'
        ORDER BY ocl.client_name, oc.credential_name
    ");

    while ($row = mysqli_fetch_assoc($sql)) {
        $pw_blob = $row['credential_password'];
        if ($pw_blob !== null && $pw_blob !== '') {
            $iv = substr($pw_blob, 0, 16);
            $ct = substr($pw_blob, 16);
            $row['decrypted_password'] = openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv);
        } else {
            $row['decrypted_password'] = '';
        }

        $user_blob = $row['credential_username'];
        if ($user_blob !== null && $user_blob !== '') {
            $iv = substr($user_blob, 0, 16);
            $ct = substr($user_blob, 16);
            $row['decrypted_username'] = openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv);
        } else {
            $row['decrypted_username'] = '';
        }

        $results[] = $row;
    }
}

?>

<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Credential Restore</h3>
    </div>
    <div class="card-body">

        <p class="text-muted">
            Upload a manual backup <code>.zip</code> (one created by the Backup tool) to load its
            <code>credentials</code> and <code>clients</code> tables here. You can then decrypt the
            credentials with a master key you provide below (it is not stored anywhere) and use
            <strong>Restore</strong> on a row to re-encrypt that username/password under your current
            vault and write it back into the live credential.
        </p>

        <form method="post" action="post.php" enctype="multipart/form-data" class="form-inline mb-3">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="input-group me-2 mb-2">
                <input type="file" name="backup_zip" class="form-control" accept=".zip" required>
            </div>
            <button type="submit" name="upload_backup" class="btn btn-secondary mb-2"><i class="fas fa-upload me-1"></i>Load Backup</button>
        </form>

        <?php if ($backup_loaded) { ?>
        <p class="text-muted">
            <i class="fas fa-fw fa-check-circle text-success me-1"></i>
            Backup loaded<?php if (!empty($_SESSION['credential_restore_backup_name'])) { ?>: <code><?php echo nullable_htmlentities($_SESSION['credential_restore_backup_name']); ?></code><?php } ?>
        </p>
        <?php } else { ?>
        <p class="text-muted"><i class="fas fa-fw fa-exclamation-circle text-warning me-1"></i>No backup loaded yet.</p>
        <?php } ?>

        <form method="post" action="credential_restore.php" class="form-inline mb-3">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="input-group me-2 mb-2">
                <input type="password" name="master_key" class="form-control" placeholder="Old master key" value="<?php echo nullable_htmlentities($master_key); ?>" required <?php echo $backup_loaded ? '' : 'disabled'; ?>>
            </div>
            <button type="submit" name="decrypt_with_master_key" class="btn btn-primary mb-2" <?php echo $backup_loaded ? '' : 'disabled'; ?>><i class="fas fa-unlock me-1"></i>Decrypt</button>
        </form>

        <?php if ($decrypted) { ?>

        <form method="get" class="form-inline mb-3">
            <div class="input-group me-2 mb-2">
                <input type="search" name="q" class="form-control" placeholder="Search name / username / client" value="<?php echo nullable_htmlentities($q); ?>">
                <div class="input-group-append">
                    <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                </div>
            </div>
        </form>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Credential</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>Live Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row) { ?>
                    <tr>
                        <td><?php echo nullable_htmlentities($row['old_client_name']); ?></td>
                        <td><?php echo nullable_htmlentities($row['credential_name']); ?></td>
                        <td>
                            <?php if ($row['decrypted_username'] === false) { ?>
                                <span class="text-danger">Decrypt failed - wrong key?</span>
                            <?php } elseif ($row['decrypted_username'] === '') { ?>
                                <span class="text-muted">(none)</span>
                            <?php } else { ?>
                                <code><?php echo nullable_htmlentities($row['decrypted_username']); ?></code>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($row['decrypted_password'] === false) { ?>
                                <span class="text-danger">Decrypt failed - wrong key?</span>
                            <?php } elseif ($row['decrypted_password'] === '') { ?>
                                <span class="text-muted">(none)</span>
                            <?php } else { ?>
                                <code><?php echo nullable_htmlentities($row['decrypted_password']); ?></code>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($row['live_credential_id']) { ?>
                                <span class="badge text-bg-secondary">Exists (#<?php echo intval($row['live_credential_id']); ?>)</span>
                            <?php } else { ?>
                                <span class="badge text-bg-warning">Missing live</span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($row['decrypted_password'] !== false && $row['decrypted_username'] !== false) { ?>
                            <form method="post" action="post.php">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="master_key" value="<?php echo nullable_htmlentities($master_key); ?>">
                                <input type="hidden" name="credential_id" value="<?php echo intval($row['credential_id']); ?>">
                                <button type="submit" name="restore_credential" class="btn btn-sm btn-outline-success confirm-link"><i class="fas fa-undo me-1"></i>Restore</button>
                            </form>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

    </div>
</div>
