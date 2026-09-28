<?php

/**
 * RivetIT — admin-credential fallback shim for deploy/restore_admin_zip.sh.
 *
 * Runs the REAL app functions (functions.php's decryptUserSpecificKey() /
 * setCanonicalVaultKey()) against a target instance's own config.php +
 * database, instead of reimplementing that crypto in bash (per the design
 * brief for this feature). Two modes, both invoked from restore_admin_zip.sh:
 *
 *   verify  — run BEFORE the destructive restore, against --app-dir's
 *             CURRENT (pre-restore) users/user_roles tables. This is the
 *             authorization gate: proves the operator holds a real,
 *             currently-active Administrator account on THIS box right now
 *             — the same bar admin/includes/inc_all_admin.php's
 *             session_is_admin check enforces for the web UI (role_is_admin
 *             = 1 on a non-archived, active, agent-type user), verified with
 *             the same password_verify() convention login.php itself uses.
 *             Exits 0 (stdout: AUTH_OK) only if every check passes.
 *
 *   recover — run AFTER the destructive restore, once db.sql has been
 *             imported and --app-dir/users now holds the BACKUP's own
 *             original rows. Looks up the SAME email again, and tries
 *             decryptUserSpecificKey() on THAT (now-restored) row's
 *             user_specific_encryption_ciphertext using the SAME password
 *             verify() already accepted. This recovers the real
 *             site_encryption_master_key correctly ONLY WHEN it is the same
 *             key that encrypted this backup in the first place — reliably
 *             true when restoring a box back onto an earlier backup of
 *             ITSELF (config.php's $config_settings_enc_key never changed,
 *             and this admin's password was the same at both points in
 *             time), NOT reliably true when restoring onto a different,
 *             independently-set-up box (its own site_encryption_master_key
 *             was separately randomly generated at ITS OWN setup and has no
 *             relationship to the backup's original one — see
 *             randomString() in functions.php / setCanonicalVaultKey() in
 *             scripts/setup_cli.php). This mode fails closed: if decryption
 *             does not yield a plausible key (wrong password for that
 *             restored row, or the email does not exist in the backup at
 *             all), it reports failure rather than writing anything.
 *             On success, writes the recovered key into the just-restored
 *             database as the CANONICAL vault key (setCanonicalVaultKey()),
 *             encrypted under --app-dir's OWN, UNCHANGED
 *             $config_settings_enc_key — exactly what the app's normal
 *             runtime read path (getCanonicalVaultKey()) expects, so every
 *             other user's own per-user wrapped copy then self-heals
 *             automatically at their next login (repairUserSpecificKey(),
 *             already-existing app behavior, untouched by this script).
 *             The raw key value is never printed or returned to the caller
 *             — it exists only inside this one PHP process.
 *
 * Usage:
 *   php admin_auth_check.php verify  <app_dir> <email> <password_file>
 *   php admin_auth_check.php recover <app_dir> <email> <password_file>
 *
 * <password_file> must be a 600-permission file holding the plaintext
 * password (restore_admin_zip.sh's convention for every secret it handles —
 * never passed as a bare CLI arg, which would sit in `ps` output / shell
 * history for any other process on the box to read while this runs).
 *
 * Exit codes (verify mode; restore_admin_zip.sh matches on these):
 *   0  AUTH_OK              - every check passed
 *   2  usage error
 *   3  DB_UNREACHABLE_OR_NO_SCHEMA - config.php's own connect failed, OR
 *      users/user_roles do not exist yet (this --app-dir was never taken
 *      through setup — not a valid target for --admin-user mode at all)
 *   4  NO_SUCH_USER          - no user row for that email
 *   5  NOT_AN_AGENT_ACCOUNT  - a client/portal login, not a staff account
 *   6  USER_INACTIVE         - user_status != 1
 *   7  USER_ARCHIVED         - user_archived_at is set
 *   8  NOT_AN_ADMINISTRATOR  - user_roles.role_is_admin != 1 (e.g. Technician)
 *   9  INCORRECT_PASSWORD    - password_verify() failed
 *
 * Exit codes (recover mode):
 *   0  RECOVERED_AND_APPLIED
 *   2  usage error
 *   3  DB_UNREACHABLE_OR_NO_SCHEMA
 *   4  NO_SUCH_USER_IN_RESTORED_DATA - email from verify step isn't in the
 *      backup that was just imported
 *   5  NO_CIPHERTEXT          - that row has no user_specific_encryption_ciphertext
 *   6  DECRYPT_FAILED_OR_IMPLAUSIBLE - wrong key material / not this backup's
 *      admin (see the mode doc above for why this is expected in the
 *      different-box case, not a bug)
 *   7  APPLY_FAILED            - decrypted fine, but writing the canonical
 *      vault key back to the database failed
 */

if ($argc < 5) {
    fwrite(STDERR, "Usage: php admin_auth_check.php <verify|recover> <app_dir> <email> <password_file>\n");
    exit(2);
}

[, $mode, $appDir, $email, $passwordFile] = $argv;

if (!in_array($mode, ['verify', 'recover'], true)) {
    fwrite(STDERR, "Unknown mode '$mode' (expected 'verify' or 'recover').\n");
    exit(2);
}

if (!is_file($passwordFile)) {
    fwrite(STDERR, "Password file '$passwordFile' does not exist.\n");
    exit(2);
}
$password = file_get_contents($passwordFile);
if ($password === false) {
    fwrite(STDERR, "Could not read password file '$passwordFile'.\n");
    exit(2);
}
// Interactive `read -rs` / a file written with `printf '%s'` both omit the
// trailing newline, but a file created via a text editor or `echo` would not
// — strip a single trailing \n or \r\n so either convention works.
$password = preg_replace('/\r?\n$/', '', $password);

$configFile = rtrim($appDir, '/') . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "No config.php at '$appDir'.\n");
    exit(3);
}

// config.php itself performs the mysqli_connect() (see scripts/setup_cli.php's
// generated template) and die()s with its own message on failure — that die()
// is what we want here too: a config.php that can't reach its database is not
// a usable --admin-user target, full stop.
require $configFile;

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    fwrite(STDERR, "config.php did not establish a \$mysqli connection.\n");
    exit(3);
}

// mysqli throws mysqli_sql_exception on error by default since PHP 8.1 (this whole
// codebase relies on that and deliberately never calls mysqli_report() to turn it
// off — see src/KB/MediaToken.php's own doc comment on the same fact), so a missing
// users/user_roles table (a config-only, schema-less --app-dir) raises an exception
// here rather than mysqli_prepare() simply returning false. Catch it explicitly and
// turn it into the same controlled "no existing instance" exit this shim already
// documents, instead of letting it escape as an uncaught fatal error/stack trace.
try {
    $stmt = mysqli_prepare(
        $mysqli,
        "SELECT users.user_id, users.user_password, users.user_status, users.user_archived_at,
                users.user_type, users.user_specific_encryption_ciphertext, user_roles.role_is_admin
         FROM users
         LEFT JOIN user_roles ON users.user_role_id = user_roles.role_id
         WHERE users.user_email = ?"
    );
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
} catch (\mysqli_sql_exception $e) {
    // Most likely: users/user_roles don't exist at all (a config-only, schema-less
    // --app-dir — see restore_admin_zip.sh's own detection of this same case).
    fwrite(STDERR, "Could not query users/user_roles at '$appDir': " . $e->getMessage() . "\n");
    fwrite(STDERR, "This usually means --app-dir has no existing set-up instance (no schema yet) to check admin credentials against.\n");
    exit(3);
}

if ($mode === 'verify') {
    if (!$row) {
        fwrite(STDERR, "NO_SUCH_USER: no user with email '$email' on this instance.\n");
        exit(4);
    }
    if ((int) $row['user_type'] !== 1) {
        fwrite(STDERR, "NOT_AN_AGENT_ACCOUNT: '$email' is a client/portal login, not a staff account.\n");
        exit(5);
    }
    if ((int) $row['user_status'] !== 1) {
        fwrite(STDERR, "USER_INACTIVE: '$email' is not an active user.\n");
        exit(6);
    }
    if ($row['user_archived_at'] !== null) {
        fwrite(STDERR, "USER_ARCHIVED: '$email' is an archived user.\n");
        exit(7);
    }
    if ((int) ($row['role_is_admin'] ?? 0) !== 1) {
        fwrite(STDERR, "NOT_AN_ADMINISTRATOR: '$email' does not have the Administrator role (Technician or another non-admin role). Only a true Administrator can authorize a restore this way.\n");
        exit(8);
    }
    if (!password_verify($password, $row['user_password'])) {
        fwrite(STDERR, "INCORRECT_PASSWORD for '$email'.\n");
        exit(9);
    }
    echo "AUTH_OK\n";
    exit(0);
}

// mode === 'recover'
if (!$row) {
    fwrite(STDERR, "NO_SUCH_USER_IN_RESTORED_DATA: '$email' does not exist in the backup that was just restored — the credential vault's master key could not be recovered this way (that account may only exist on this box's PRE-restore state, e.g. an independently-set-up instance whose admin account is not the same one the backup was originally taken from).\n");
    exit(4);
}
$ciphertext = $row['user_specific_encryption_ciphertext'] ?? '';
if ($ciphertext === '' || $ciphertext === null) {
    fwrite(STDERR, "NO_CIPHERTEXT: '$email' in the restored data has no user_specific_encryption_ciphertext to decrypt.\n");
    exit(5);
}

require_once rtrim($appDir, '/') . '/functions.php';

$masterKey = decryptUserSpecificKey($ciphertext, $password);
// site_encryption_master_key is always randomString()'s default output: exactly
// 16 characters from a base64url-safe alphabet (see functions.php). A wrong key
// (openssl_decrypt returning false on a PKCS7 padding failure, or succeeding but
// on garbage from a mismatched key) is very unlikely to happen to also match
// this shape, so it doubles as a real correctness check, not just a null check.
if ($masterKey === false || $masterKey === '' || !preg_match('/^[A-Za-z0-9_-]{16}$/', $masterKey)) {
    fwrite(STDERR, "DECRYPT_FAILED_OR_IMPLAUSIBLE: decrypting '$email''s restored user_specific_encryption_ciphertext with the verified password did not yield a usable master key. This backup's data was very likely encrypted under a DIFFERENT site_encryption_master_key than this account currently has (e.g. restoring onto an independently-set-up box, not a rollback of this same box) — the --admin-user fallback cannot recover it in that case. Every other part of the restore is still complete; re-run recovery with --passphrase-file if you have this backup's manifest passphrase, or re-enter the affected secrets by hand.\n");
    exit(6);
}

try {
    setCanonicalVaultKey($mysqli, $masterKey);
} catch (\Throwable $e) {
    fwrite(STDERR, "APPLY_FAILED: recovered a usable master key but failed to write it back as the canonical vault key: " . $e->getMessage() . "\n");
    exit(7);
}

echo "RECOVERED_AND_APPLIED\n";
exit(0);
