<?php

/*
 * ITFlow - POST request handler for the Credential Restore tool
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/**
 * Pull the CREATE TABLE and INSERT INTO statements for $table out of a
 * mysqldump-style db.sql, split into lines.
 *
 * @return array{0: string[], 1: string[]} [create table lines, insert statement lines]
 */
function credentialRestoreExtractTable(array $lines, string $table): array {
    $create = [];
    $inserts = [];
    $inCreate = false;

    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");

        if (!$inCreate && preg_match('/^CREATE TABLE `' . preg_quote($table, '/') . '` \(/', $line)) {
            $inCreate = true;
            $create[] = $line;
            continue;
        }

        if ($inCreate) {
            $create[] = $line;
            if (preg_match('/^\) ENGINE=/', $line)) {
                $inCreate = false;
            }
            continue;
        }

        if (preg_match('/^INSERT INTO `' . preg_quote($table, '/') . '` /', $line)) {
            $inserts[] = $line;
        }
    }

    return [$create, $inserts];
}

/**
 * Rebuild a CREATE TABLE statement under a new (staging) table name, stripping
 * any FOREIGN KEY/CONSTRAINT clauses that may reference tables that won't exist
 * in the staging area.
 */
function credentialRestoreBuildStagingCreate(array $createLines, string $oldTable, string $stagingTable): string {
    $first = preg_replace('/^CREATE TABLE `' . preg_quote($oldTable, '/') . '`/', "CREATE TABLE `$stagingTable`", $createLines[0], 1);
    $last = $createLines[count($createLines) - 1];
    $body = array_slice($createLines, 1, -1);

    $body = array_values(array_filter($body, function ($l) {
        return !preg_match('/^\s*(CONSTRAINT `.*` )?FOREIGN KEY/i', $l);
    }));

    if (!empty($body)) {
        $lastIndex = count($body) - 1;
        $body[$lastIndex] = preg_replace('/,\s*$/', '', $body[$lastIndex]);
    }

    return implode("\n", array_merge([$first], $body, [$last]));
}

if (isset($_POST['upload_backup'])) {

    validateCSRFToken($_POST['csrf_token']);

    $secureFilename = checkFileUpload($_FILES['backup_zip'] ?? ['name' => '', 'tmp_name' => '', 'size' => 0], ['zip']);

    if (!$secureFilename || !is_string($secureFilename)) {
        flash_alert("Please upload a valid .zip backup file (max 500 MB)", "error");
        redirect();
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup_zip']['tmp_name']) !== true) {
        flash_alert("Could not open the uploaded zip file", "error");
        redirect();
    }

    $sqlContent = $zip->getFromName('db.sql');
    if ($sqlContent === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (basename($name) === 'db.sql') {
                $sqlContent = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();

    if ($sqlContent === false) {
        flash_alert("Could not find a db.sql file inside the uploaded zip", "error");
        redirect();
    }

    $lines = explode("\n", $sqlContent);
    unset($sqlContent);

    $tables = [
        'credentials' => 'credential_restore_staging',
        'clients' => 'credential_restore_staging_clients',
    ];

    foreach ($tables as $table => $staging) {

        [$create, $inserts] = credentialRestoreExtractTable($lines, $table);

        if (empty($create)) {
            flash_alert("Could not find a `$table` table in the uploaded db.sql", "error");
            redirect();
        }

        $createSql = credentialRestoreBuildStagingCreate($create, $table, $staging);

        mysqli_query($mysqli, "DROP TABLE IF EXISTS `$staging`");

        if (!mysqli_query($mysqli, $createSql)) {
            flash_alert("Failed to create staging table for `$table`: " . mysqli_error($mysqli), "error");
            redirect();
        }

        foreach ($inserts as $insertLine) {
            $insertSql = preg_replace('/^INSERT INTO `' . preg_quote($table, '/') . '`/', "INSERT INTO `$staging`", $insertLine, 1);
            mysqli_query($mysqli, $insertSql);
        }
    }

    // The staging tables above are rebuilt straight from the uploaded mysqldump, so we
    // can't add an ownership column to them without rewriting every INSERT tuple. Instead,
    // track who currently owns the staged backup in a small dedicated table so that only
    // the admin who uploaded it can browse/restore from it (see admin/credential_restore.php
    // and the restore_credential handler below).
    mysqli_query($mysqli, "DROP TABLE IF EXISTS `credential_restore_staging_meta`");
    mysqli_query($mysqli, "CREATE TABLE `credential_restore_staging_meta` (
        uploaded_by_user_id INT NOT NULL,
        uploaded_at DATETIME NOT NULL
    )");
    mysqli_query($mysqli, "INSERT INTO `credential_restore_staging_meta` (uploaded_by_user_id, uploaded_at) VALUES (" . intval($session_user_id) . ", NOW())");

    $_SESSION['credential_restore_backup_name'] = $_FILES['backup_zip']['name'];

    logAction("Credential", "Restore", "$session_name uploaded a backup (" . sanitizeInput($_FILES['backup_zip']['name']) . ") for the Credential Restore tool");

    flash_alert("Backup loaded - you can now decrypt and browse its credentials below");

    redirect();

}

if (isset($_POST['restore_credential'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Only the admin who uploaded the currently-staged backup may restore from it -
    // otherwise any admin could restore/overwrite credentials from a backup someone
    // else uploaded, just by guessing a credential_id and a master key.
    $staging_owner_result = mysqli_query($mysqli, "SELECT uploaded_by_user_id FROM credential_restore_staging_meta LIMIT 1");
    $staging_owner = $staging_owner_result ? mysqli_fetch_assoc($staging_owner_result) : false;
    if (!$staging_owner || intval($staging_owner['uploaded_by_user_id']) !== intval($session_user_id)) {
        flash_alert("No backup uploaded by you is currently staged. Please upload a backup first.", "error");
        redirect();
    }

    $credential_id = intval($_POST['credential_id']);
    $master_key = (string) ($_POST['master_key'] ?? '');

    $old = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM credential_restore_staging WHERE credential_id = $credential_id"));

    if (!$old || $master_key === '') {
        flash_alert("Could not find backup record or master key missing", "error");
        redirect();
    }

    $pw_blob = $old['credential_password'];
    $plain_password = '';
    if ($pw_blob !== null && $pw_blob !== '') {
        $iv = substr($pw_blob, 0, 16);
        $ct = substr($pw_blob, 16);
        $plain_password = openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv);
        if ($plain_password === false) {
            flash_alert("Decryption failed - master key is incorrect", "error");
            redirect();
        }
    }

    $user_blob = $old['credential_username'];
    $plain_username = '';
    if ($user_blob !== null && $user_blob !== '') {
        $iv = substr($user_blob, 0, 16);
        $ct = substr($user_blob, 16);
        $plain_username = openssl_decrypt($ct, 'aes-128-cbc', $master_key, 0, $iv);
        if ($plain_username === false) {
            flash_alert("Decryption failed - master key is incorrect", "error");
            redirect();
        }
    }

    $new_ciphertext = encryptCredentialEntry($plain_password);
    $esc_password = mysqli_real_escape_string($mysqli, $new_ciphertext);
    $esc_username = mysqli_real_escape_string($mysqli, encryptCredentialEntry($plain_username));

    $live = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_id FROM credentials WHERE credential_id = $credential_id"));

    if ($live) {

        mysqli_query($mysqli, "UPDATE credentials SET credential_username = '$esc_username', credential_password = '$esc_password' WHERE credential_id = $credential_id");

    } else {

        $esc_name = mysqli_real_escape_string($mysqli, $old['credential_name']);
        $esc_description = mysqli_real_escape_string($mysqli, $old['credential_description'] ?? '');
        $esc_category = mysqli_real_escape_string($mysqli, $old['credential_category'] ?? '');
        $esc_uri = mysqli_real_escape_string($mysqli, $old['credential_uri'] ?? '');
        $esc_uri_2 = mysqli_real_escape_string($mysqli, $old['credential_uri_2'] ?? '');
        $esc_otp = mysqli_real_escape_string($mysqli, $old['credential_otp_secret'] ?? '');
        $esc_note = mysqli_real_escape_string($mysqli, $old['credential_note'] ?? '');

        mysqli_query($mysqli, "INSERT INTO credentials SET
            credential_id = $credential_id,
            credential_name = '$esc_name',
            credential_description = '$esc_description',
            credential_category = '$esc_category',
            credential_uri = '$esc_uri',
            credential_uri_2 = '$esc_uri_2',
            credential_username = '$esc_username',
            credential_password = '$esc_password',
            credential_otp_secret = '$esc_otp',
            credential_note = '$esc_note',
            credential_important = " . intval($old['credential_important']) . ",
            credential_favorite = " . intval($old['credential_favorite']) . ",
            credential_folder_id = " . intval($old['credential_folder_id']) . ",
            credential_contact_id = " . intval($old['credential_contact_id']) . ",
            credential_asset_id = " . intval($old['credential_asset_id']) . ",
            credential_vendor_id = " . intval($old['credential_vendor_id']) . ",
            credential_software_id = " . intval($old['credential_software_id']) . ",
            credential_client_id = " . intval($old['credential_client_id']) . "
        ");

    }

    logAction("Credential", "Restore", "$session_name restored credential " . sanitizeInput($old['credential_name']) . " from an uploaded backup", intval($old['credential_client_id']), $credential_id);

    flash_alert("Credential <strong>" . nullable_htmlentities($old['credential_name']) . "</strong> restored from backup");

    redirect();

}
