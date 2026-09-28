#!/usr/bin/env bash
set -euo pipefail

# RivetIT — restore an admin-panel backup zip (admin/post/backup.php).
#
# The counterpart to restore.sh, for the OTHER backup format this app
# produces: the zip an admin actually clicks "Save to Server" / "Download
# Backup" to get (Settings -> Backup, itflow_<timestamp>_<manual|auto>.zip),
# and what the scheduled cron/backup_cron.php auto-backup writes to
# <app-dir>/backups/ every day. That zip is UNENCRYPTED as a whole (unzip it
# and db.sql/uploads.zip/version.txt are right there) and contains:
#   - db.sql          a full database dump (DROP+CREATE+INSERT per table,
#                      views, triggers — see dump_database_streaming() in
#                      admin/post/backup.php)
#   - uploads.zip      a nested zip of the whole uploads/ directory
#   - version.txt      informational metadata (git commit, app/DB version,
#                      SHA256 checksums) — not used by this restore
#   - backup-manifest.json (or .json.enc if the admin set a backup
#                      passphrase in Admin > Backup) — installation_id and
#                      settings_enc_key, same shape/encryption scheme
#                      deploy/backup.sh's own manifest uses (see
#                      build_backup_manifest() in admin/post/backup.php and
#                      lib/common.sh's read_manifest_dir(), shared with
#                      restore.sh)
#
# This is a DIFFERENT archive format from deploy/backup.sh's
# backup-*.tar.gz.enc (a single openssl-encrypted tar, always via CLI/cron,
# never through the browser) — restore.sh can only open THAT format. This
# script is the admin zip's counterpart, following the same safety
# conventions restore.sh established: --confirm-restore required (no
# interactive y/n to click through), a pre-restore safety backup of the
# target's CURRENT state by default (opt out explicitly), root-required,
# --unattended logging, and settings_enc_key reconciliation via the shared
# manifest reader in lib/common.sh.
#
# Usage:
#   restore_admin_zip.sh --app-dir=<path> --backup=<path/to/itflow_*.zip> --confirm-restore [options]
#   restore_admin_zip.sh --help
#
# Required:
#   --app-dir=<path>            Webroot of the ALREADY-INSTALLED instance to
#                               restore into (must contain config.php — run
#                               deploy/install.sh or
#                               scripts/setup_cli.php --config-only first on
#                               a fresh box).
#   --backup=<path>             Path to the itflow_<timestamp>_<manual|auto>.zip
#                               to restore (produced by admin/post/backup.php,
#                               i.e. Admin > Backup's "Save to Server" /
#                               "Download Backup", or the daily cron auto-backup).
#   --confirm-restore            Required, no-value acknowledgment that this
#                               REPLACES every table in the target database
#                               and everything under --app-dir/uploads with
#                               what's in --backup. Refuses to run without it.
#
# Master-key recovery — exactly two modes, pick ONE (or give both and this
# script uses --passphrase-file first, falling back to --admin-user only if
# the manifest has no usable key):
#
#   --passphrase-file=<path>    600-permission file holding a passphrase.
#                               REQUIRED if --backup's manifest is encrypted
#                               (backup-manifest.json.enc — the admin had a
#                               backup passphrase set in Admin > Backup when
#                               this backup was taken), to decrypt it and
#                               recover settings_enc_key, which is then
#                               applied to --app-dir/config.php. Works for
#                               ANY target, including a brand-new box with no
#                               existing instance on it at all (true
#                               from-scratch disaster recovery) — this is the
#                               only mode that does.
#   --admin-user=<email>        Fallback when the passphrase is lost: the
#                               login email of an existing, active,
#                               Administrator-role (not Technician) account
#                               that ALREADY exists on --app-dir RIGHT NOW,
#                               before this restore touches anything. Give
#                               the password via --admin-password-file (or,
#                               interactively, this script prompts for it).
#                               Verified with the same password_verify()
#                               check + role_is_admin=1 gate the web app's
#                               own admin/includes/inc_all_admin.php enforces
#                               — proving real application-level authority
#                               over THIS box, not just root/SSH access to
#                               it. Requires --app-dir to already be a
#                               reachable, already-set-up instance with its
#                               own live users/user_roles tables (refused
#                               otherwise, see the doc comment above
#                               deploy/lib/admin_auth_check.php). After the
#                               restore, recovers site_encryption_master_key
#                               by decrypting that SAME admin's restored
#                               user_specific_encryption_ciphertext with the
#                               SAME password — reliable when this is a
#                               rollback of the SAME box to an earlier backup
#                               of itself (the common case this exists for);
#                               NOT reliable across two independently-set-up
#                               boxes with unrelated master keys, in which
#                               case this warns rather than silently
#                               pretending it worked. Does NOT recover
#                               settings_enc_key/config.php's own key — use
#                               --passphrase-file for that.
#   --admin-password-file=<path> 600-permission file holding --admin-user's
#                               password. Required with --admin-user under
#                               --unattended; otherwise this script prompts
#                               for it interactively (never accepted as a
#                               bare CLI argument — it would sit in shell
#                               history / `ps` output for the life of the
#                               process).
#
# Other options:
#   --no-pre-restore-backup-confirmed
#                               Explicit opt-out of the pre-restore safety
#                               backup. Only use this if --app-dir has
#                               nothing worth keeping, the box is critically
#                               low on disk space, or deploy/backup.sh's own
#                               passphrase requirement can't be met right
#                               now. Without this flag, the safety backup
#                               always runs — reusing --passphrase-file's
#                               passphrase if one was given, or otherwise a
#                               freshly generated, throwaway one that this
#                               script prints and saves (600, root-only)
#                               alongside the resulting safety-backup file so
#                               it isn't required to also know the admin
#                               zip's own passphrase just to get a safety net.
#   --unattended                 Cron-friendly: output goes to
#                               /var/log/itflow-restore-admin-zip.log only.
#                               Without this flag, output goes to both the
#                               terminal and the logfile.
#   --help                       Show this help and exit.
#
# Must be run as root — needs to read config.php's db credentials, write
# root-owned temp files, invoke deploy/backup.sh for the safety net, and
# re-chown uploads/ back to www-data afterward.
#
# What it does NOT do: stop php-fpm, put the site in any kind of maintenance
# mode, or touch cron. For a real disaster-recovery drill this is moot
# (nothing is live yet); for "restore over a running instance", stop traffic
# to it first if you can.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-restore-admin-zip.log"

APP_DIR=""
BACKUP_FILE=""
PASSPHRASE_FILE=""
ADMIN_USER=""
ADMIN_PASSWORD_FILE=""
CONFIRM_RESTORE=0
NO_PRE_RESTORE_BACKUP_CONFIRMED=0
UNATTENDED=0

# Populated later by read_app_config() / extract_zip() / import_database().
DB_HOST=""
DB_USER=""
DB_PASS=""
DB_NAME=""
INSTALLATION_ID=""
SETTINGS_ENC_KEY=""
EXTRACT_DIR=""

# Populated by resolve_admin_password(): path to a 600 tmpfile holding
# --admin-user's password (never the password itself in a bash variable
# passed around as an argv value — see resolve_admin_password()'s own
# comment). Empty when --admin-user was not given.
ADMIN_PASSWORD_RESOLVED_FILE=""

# Set true by verify_admin_auth() once it succeeds, so main() knows whether
# to attempt recover_master_key_via_admin() after the restore.
ADMIN_AUTH_VERIFIED=0

PHP_ADMIN_AUTH_SHIM="${SCRIPT_DIR}/lib/admin_auth_check.php"

print_help() {
    cat <<'EOF'
RivetIT — restore an admin-panel backup zip (admin/post/backup.php)

Usage:
  sudo deploy/restore_admin_zip.sh --app-dir=<path> --backup=<path> \
      --confirm-restore [options]

Required:
  --app-dir=<path>            Webroot of the already-installed instance to
                              restore into (must contain config.php).
  --backup=<path>             Path to the itflow_<timestamp>_<manual|auto>.zip
                              to restore (Admin > Backup, or the cron
                              auto-backup — NOT a deploy/backup.sh
                              backup-*.tar.gz.enc; use restore.sh for that).
  --confirm-restore            Required acknowledgment that this overwrites
                              the target database and uploads/ entirely.

Master-key recovery — give ONE of these two (both work; see the top of this
file's comments for the full tradeoffs):
  --passphrase-file=<path>    600-permission file with a passphrase. REQUIRED
                              if --backup's manifest is encrypted
                              (backup-manifest.json.enc). Works even against
                              a brand-new, never-set-up --app-dir.
  --admin-user=<email>        Fallback with no passphrase: an existing,
                              active Administrator account's login email on
                              --app-dir AS IT IS RIGHT NOW (before this
                              restore runs). Requires --app-dir to already
                              be a live, set up instance. Give the password
                              via --admin-password-file, or this script
                              prompts for it interactively.
  --admin-password-file=<path> 600-permission file with --admin-user's
                              password. Required under --unattended.

Neither given: this script refuses to run and explains both options.

Other options:
  --no-pre-restore-backup-confirmed
                              Skip taking a safety backup of --app-dir's
                              current state before overwriting it. Without
                              this flag a safety backup always runs, using
                              --passphrase-file if given or otherwise a
                              freshly generated one-off passphrase this
                              script prints and saves next to that backup.
  --unattended                 Cron-friendly: log file only, no terminal echo.
  --help                       Show this help and exit.

Steps performed, in order: verify --admin-user (if given) against --app-dir's
CURRENT users table -> pre-restore safety backup (or confirmed skip) ->
unzip --backup -> read its manifest (plaintext, encrypted, or absent — see
lib/common.sh's read_manifest_dir()) -> drop every existing table in the
target database and import db.sql -> replace --app-dir/uploads with the
nested uploads.zip's contents -> restore ownership/permissions -> apply the
manifest's settings_enc_key to config.php if --passphrase-file recovered one,
or recover site_encryption_master_key via --admin-user's restored account if
that was used instead (see deploy/lib/admin_auth_check.php for exactly what
this can and cannot guarantee in each case).

To test a restore without touching a real instance, point --app-dir at a
disposable one instead — e.g. scripts/setup_cli.php --config-only against a
scratch database gives you a throwaway config.php to restore into and
inspect, then delete.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --app-dir=*)              APP_DIR="${arg#*=}" ;;
            --backup=*)                BACKUP_FILE="${arg#*=}" ;;
            --passphrase-file=*)      PASSPHRASE_FILE="${arg#*=}" ;;
            --admin-user=*)            ADMIN_USER="${arg#*=}" ;;
            --admin-password-file=*)  ADMIN_PASSWORD_FILE="${arg#*=}" ;;
            --confirm-restore)         CONFIRM_RESTORE=1 ;;
            --no-pre-restore-backup-confirmed) NO_PRE_RESTORE_BACKUP_CONFIRMED=1 ;;
            --unattended)               UNATTENDED=1 ;;
            --help|-h)
                print_help
                exit 0
                ;;
            *)
                print_help
                die "Unknown option: ${arg}"
                ;;
        esac
    done
}

validate_args() {
    [[ -n "${APP_DIR}" ]] || { print_help; die "--app-dir is required."; }
    [[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path (got: ${APP_DIR})"
    [[ -d "${APP_DIR}" ]] || die "--app-dir '${APP_DIR}' does not exist or is not a directory."
    [[ -f "${APP_DIR}/config.php" ]] || die "No config.php found under ${APP_DIR} — this restore script overwrites an EXISTING instance's data, it does not create one. Run deploy/install.sh or scripts/setup_cli.php --config-only first, then re-run this against the resulting --app-dir."

    [[ -n "${BACKUP_FILE}" ]] || { print_help; die "--backup is required."; }
    [[ -f "${BACKUP_FILE}" ]] || die "--backup '${BACKUP_FILE}' does not exist."
    case "${BACKUP_FILE}" in
        *.zip) ;;
        *) die "--backup '${BACKUP_FILE}' does not look like an admin-panel backup (expected a .zip). A deploy/backup.sh archive (backup-*.tar.gz.enc) is restored with deploy/restore.sh instead, not this script." ;;
    esac

    # Master-key recovery: require exactly one of --passphrase-file /
    # --admin-user up front, matching restore.sh's existing
    # require-explicit-choice pattern (backup vs --no-backup-confirmed)
    # rather than silently proceeding with no way to recover secrets at all.
    if [[ -z "${PASSPHRASE_FILE}" && -z "${ADMIN_USER}" ]]; then
        print_help
        die "Give either --passphrase-file=<path> (if you have the backup passphrase set in Admin > Backup when this backup was taken) or --admin-user=<email> (an existing Administrator account's login email already on --app-dir, as a fallback if you don't). Neither was given — this script refuses to guess which one you meant. See --help for the tradeoffs of each."
    fi

    if [[ -n "${PASSPHRASE_FILE}" ]]; then
        [[ -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file '${PASSPHRASE_FILE}' does not exist."
        local pf_perm
        pf_perm="$(stat -c '%a' "${PASSPHRASE_FILE}")"
        if [[ "${pf_perm: -2}" != "00" ]]; then
            die "--passphrase-file '${PASSPHRASE_FILE}' has permissions ${pf_perm} (group/other can access it). Expected 600, owner-only. Fix with: chmod 600 '${PASSPHRASE_FILE}'"
        fi
    fi

    if [[ -n "${ADMIN_USER}" ]]; then
        if [[ -n "${ADMIN_PASSWORD_FILE}" ]]; then
            [[ -f "${ADMIN_PASSWORD_FILE}" ]] || die "--admin-password-file '${ADMIN_PASSWORD_FILE}' does not exist."
            local apf_perm
            apf_perm="$(stat -c '%a' "${ADMIN_PASSWORD_FILE}")"
            if [[ "${apf_perm: -2}" != "00" ]]; then
                die "--admin-password-file '${ADMIN_PASSWORD_FILE}' has permissions ${apf_perm} (group/other can access it). Expected 600, owner-only. Fix with: chmod 600 '${ADMIN_PASSWORD_FILE}'"
            fi
        elif [[ "${UNATTENDED}" -eq 1 ]]; then
            die "--admin-user was given without --admin-password-file under --unattended — there is no terminal to prompt on. Supply --admin-password-file=<600-permission file> instead."
        fi
        [[ -f "${PHP_ADMIN_AUTH_SHIM}" ]] || die "Internal error: ${PHP_ADMIN_AUTH_SHIM} is missing."
    fi

    if [[ "${CONFIRM_RESTORE}" -ne 1 ]]; then
        print_help
        die "Refusing to run without --confirm-restore. This REPLACES every table in ${APP_DIR}'s database and everything under ${APP_DIR}/uploads with the contents of ${BACKUP_FILE}. Re-run with --confirm-restore once you're sure."
    fi

    command_exists unzip || die "unzip is required to open --backup but is not on PATH."
    command_exists openssl || die "openssl is required (manifest decryption / the pre-restore safety backup) but is not on PATH."
    command_exists mysql || die "The mysql client is required to import the database dump but is not on PATH."
    command_exists rsync || die "rsync is required to restore uploads/ but is not on PATH. Install it: apt-get install rsync"
    command_exists php || die "php is required (manifest parsing, and --admin-user's auth/key-recovery shim) but is not on PATH."
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    if [[ "${UNATTENDED}" -eq 1 ]]; then
        exec >> "${LOG_FILE}" 2>&1
    else
        exec > >(tee -a "${LOG_FILE}") 2>&1
    fi
}

# resolve_admin_password(): populates ADMIN_PASSWORD_RESOLVED_FILE with a 600
# tmpfile holding --admin-user's password — from --admin-password-file if
# given, otherwise an interactive `read -rs` prompt (validate_args already
# refused this combination under --unattended). The password is never held
# in a plain bash variable that could end up in argv/an error trace; every
# consumer (verify_admin_auth / recover_master_key_via_admin) reads it back
# out of this same file, mirroring how PASSPHRASE_FILE is already handled.
resolve_admin_password() {
    if [[ -n "${ADMIN_PASSWORD_FILE}" ]]; then
        ADMIN_PASSWORD_RESOLVED_FILE="${ADMIN_PASSWORD_FILE}"
        return 0
    fi

    local tmp_pw
    tmp_pw="$(mktemp)"
    chmod 600 "${tmp_pw}"
    register_tmpfile "${tmp_pw}"

    local pw1
    read -rs -p "Password for admin account '${ADMIN_USER}': " pw1
    printf '\n' >&2
    printf '%s' "${pw1}" > "${tmp_pw}"
    unset pw1
    ADMIN_PASSWORD_RESOLVED_FILE="${tmp_pw}"
}

# verify_admin_auth(): the authorization gate for --admin-user mode, run
# BEFORE anything below touches --app-dir. Delegates the actual check to
# deploy/lib/admin_auth_check.php (real password_verify()/role_is_admin
# logic against the app's own users/user_roles tables — see that file's own
# doc comment for exactly what "verify" mode checks and why). Dies with a
# specific, correct explanation for every distinct failure mode: no live
# instance to check against at all (--app-dir was never set up), no such
# user, wrong role, wrong password — never a generic "auth failed".
verify_admin_auth() {
    announce "Verifying --admin-user '${ADMIN_USER}' against ${APP_DIR}'s CURRENT users/user_roles tables before touching anything..."

    local rc=0
    local out_file err_file
    out_file="$(mktemp)"; register_tmpfile "${out_file}"
    err_file="$(mktemp)"; register_tmpfile "${err_file}"
    php "${PHP_ADMIN_AUTH_SHIM}" verify "${APP_DIR}" "${ADMIN_USER}" "${ADMIN_PASSWORD_RESOLVED_FILE}" \
        1>"${out_file}" 2>"${err_file}" || rc=$?
    local shim_stderr
    shim_stderr="$(cat "${err_file}" 2>/dev/null || true)"

    case "${rc}" in
        0)
            ADMIN_AUTH_VERIFIED=1
            success "Admin authorization OK: '${ADMIN_USER}' is an active Administrator on ${APP_DIR}."
            ;;
        3)
            die "--admin-user mode requires ${APP_DIR} to already be a reachable, already-set-up instance with its own live users/user_roles tables — that isn't the case here (${shim_stderr}). This looks like the true from-scratch disaster-recovery scenario: there is no existing account on this box to prove authority with. Use --passphrase-file instead (it works even against a brand-new box)."
            ;;
        4|5|6|7|8|9)
            die "Admin authorization refused for '${ADMIN_USER}': ${shim_stderr} ${APP_DIR} was NOT touched. Fix the account/role/password issue and try again, or use --passphrase-file instead if you have this backup's manifest passphrase."
            ;;
        *)
            die "Admin authorization check failed unexpectedly (exit ${rc}): ${shim_stderr}"
            ;;
    esac
}

# recover_master_key_via_admin(): run AFTER the restore (db.sql import) has
# completed, once --app-dir/users holds the BACKUP's own original rows.
# Non-fatal on failure — the restore's data/uploads are already complete by
# this point, so a failure here is reported as a clear warning, not a die().
# See deploy/lib/admin_auth_check.php's own doc comment for exactly when
# this can and cannot succeed (reliable for a same-box rollback, not
# guaranteed across two independently-set-up boxes).
recover_master_key_via_admin() {
    announce "Recovering site_encryption_master_key via '${ADMIN_USER}''s restored account..."

    local rc=0
    local out_file err_file
    out_file="$(mktemp)"; register_tmpfile "${out_file}"
    err_file="$(mktemp)"; register_tmpfile "${err_file}"
    php "${PHP_ADMIN_AUTH_SHIM}" recover "${APP_DIR}" "${ADMIN_USER}" "${ADMIN_PASSWORD_RESOLVED_FILE}" \
        1>"${out_file}" 2>"${err_file}" || rc=$?
    local shim_stderr
    shim_stderr="$(cat "${err_file}" 2>/dev/null || true)"

    if [[ "${rc}" -eq 0 ]]; then
        success "site_encryption_master_key recovered and applied as the canonical vault key — the credential vault should decrypt normally for every user (each user's own per-user copy self-heals automatically at their next login)."
    else
        warn "Could not recover the credential vault's master key via '${ADMIN_USER}' (exit ${rc}): ${shim_stderr} The rest of the restore (database + uploads) completed normally regardless. Note this path never recovers config_settings_enc_key either way — SMTP/IMAP passwords and other settings-table secrets encrypted directly with the ORIGINAL box's config_settings_enc_key need --passphrase-file to recover if this --app-dir's own key differs from the backup's original."
    fi
}

# run_pre_restore_backup(): same mandatory-safety-net idiom restore.sh uses —
# reuses deploy/backup.sh itself (rather than re-implementing a second backup
# mechanism here) to snapshot --app-dir's CURRENT state before anything below
# overwrites it. Not the admin zip's OWN backup mechanism (invoking
# build_backup() headlessly would need a working, already-correct
# config.php/DB connection on a target that might be exactly what's broken)
# — deploy/backup.sh is the more robust, already-hardened choice for "take a
# safety copy of whatever is here right now, no matter how broken."
#
# deploy/backup.sh always requires its own --passphrase-file (never writes an
# unencrypted dump). --admin-user mode may not have one at all (that's the
# whole point of the fallback), so when PASSPHRASE_FILE is empty this
# generates a one-off random passphrase, uses it for THIS safety backup only,
# and saves a copy next to the resulting backup file (600, root-only) so it
# isn't silently unrecoverable — the operator is never forced to choose
# between "no safety net" and "must also know the admin zip's own passphrase".
run_pre_restore_backup() {
    if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
        warn "Proceeding WITHOUT a pre-restore safety backup (--no-pre-restore-backup-confirmed was passed). ${APP_DIR}'s current data is about to be overwritten with no way back except --backup itself."
        return 0
    fi

    local safety_passphrase_file="${PASSPHRASE_FILE}"
    local generated_passphrase=0
    if [[ -z "${safety_passphrase_file}" ]]; then
        info "No --passphrase-file given; generating a one-off passphrase for the pre-restore safety backup only (deploy/backup.sh always requires one)."
        safety_passphrase_file="$(mktemp)"
        chmod 600 "${safety_passphrase_file}"
        register_tmpfile "${safety_passphrase_file}"
        gen_secret 40 > "${safety_passphrase_file}"
        generated_passphrase=1
    fi

    local dest="${APP_DIR}/backups"
    local before_sentinel
    before_sentinel="$(mktemp)"
    register_tmpfile "${before_sentinel}"

    announce "Taking a safety backup of ${APP_DIR}'s CURRENT state via deploy/backup.sh before overwriting anything."
    if ! "${SCRIPT_DIR}/backup.sh" --app-dir="${APP_DIR}" --passphrase-file="${safety_passphrase_file}"; then
        die "Pre-restore safety backup failed (see deploy/backup.sh's output above). Aborting the restore entirely; ${APP_DIR} was NOT touched. Fix the backup failure, or re-run with --no-pre-restore-backup-confirmed if you accept the risk, and try again."
    fi
    success "Pre-restore safety backup completed successfully."

    if [[ "${generated_passphrase}" -eq 1 ]]; then
        local newest
        newest="$(find "${dest}" -maxdepth 1 -type f -name 'backup-*.tar.gz.enc' -newer "${before_sentinel}" -print 2>/dev/null | sort | tail -n1)"
        if [[ -n "${newest}" ]]; then
            local saved="${newest}.passphrase"
            cp "${safety_passphrase_file}" "${saved}"
            chmod 600 "${saved}"
            chown root:root "${saved}"
            warn "This safety backup's passphrase was auto-generated (no --passphrase-file was given) and saved to ${saved} (600, root-only) — that file is the ONLY copy. Move it somewhere durable if you want to keep this safety backup usable; it will NOT survive past this script's cleanup otherwise being findable only via that saved copy."
        else
            warn "Could not identify the safety backup's own output file to save its auto-generated passphrase next to. The passphrase itself was only ever held in a tmpfile that has now been shredded — that safety backup is effectively unrecoverable. Re-run with --passphrase-file next time to avoid this."
        fi
    fi
}

extract_zip() {
    announce "Extracting ${BACKUP_FILE}..."
    EXTRACT_DIR="$(mktemp -d)"
    register_tmpfile "${EXTRACT_DIR}"

    if ! unzip -q -o "${BACKUP_FILE}" -d "${EXTRACT_DIR}"; then
        die "unzip failed on ${BACKUP_FILE}. It may be corrupt, or not actually an admin-panel backup zip."
    fi

    [[ -f "${EXTRACT_DIR}/db.sql" ]] || die "No db.sql found inside ${BACKUP_FILE} after extraction — this doesn't look like an admin/post/backup.php archive."
    [[ -f "${EXTRACT_DIR}/uploads.zip" ]] || die "No uploads.zip found inside ${BACKUP_FILE} after extraction — this doesn't look like an admin/post/backup.php archive."
    success "Extracted OK (db.sql, uploads.zip$( [[ -f "${EXTRACT_DIR}/version.txt" ]] && printf ', version.txt' )$( { [[ -f "${EXTRACT_DIR}/backup-manifest.json" ]] || [[ -f "${EXTRACT_DIR}/backup-manifest.json.enc" ]]; } && printf ', manifest' ))."

    if [[ -f "${EXTRACT_DIR}/version.txt" ]]; then
        info "Backup metadata (version.txt):"
        sed 's/^/    /' "${EXTRACT_DIR}/version.txt"
    fi
}

# import_database(): unlike restore.sh (a deploy/backup.sh dump only ever
# contains the tables that existed in ITS source database, and every table
# it does contain starts with its own DROP TABLE IF EXISTS — restore.sh
# trusts that), this drops EVERY table currently in the target database
# first, matching what setup/index.php's own browser-based admin-zip restore
# already does. That guarantees a true 1:1 result even if the target has
# leftover tables the backup's source database never had (a newer schema
# migration applied after this backup was taken, manual cruft, etc.) — a
# plain `mysql < db.sql` would silently leave those behind, which is not
# "an entire 1 to 1 copy".
import_database() {
    announce "Importing $(basename "${EXTRACT_DIR}/db.sql") into database '${DB_NAME}'. Every existing table in '${DB_NAME}' is dropped first, then everything db.sql contains is created and loaded."
    local defaults_file
    defaults_file="$(mktemp)"
    chmod 600 "${defaults_file}"
    register_tmpfile "${defaults_file}"
    cat > "${defaults_file}" <<EOF
[client]
host=${DB_HOST}
user=${DB_USER}
password=${DB_PASS}
EOF

    # Tables AND views (both live in information_schema.tables, VIEWS included — a
    # plain DROP TABLE IF EXISTS on a view name is a no-op in MySQL/MariaDB, so views
    # need their own DROP VIEW). Also stored procedures/functions
    # (information_schema.ROUTINES) — db.sql now carries these too (see
    # dump_database_streaming()'s routine-capture block in admin/post/backup.php), but
    # they are NOT covered by the table drop above, so a routine that exists on the
    # TARGET but isn't in this backup (e.g. added after this backup was taken, or a
    # leftover from unrelated target-side work) would otherwise silently survive the
    # "restore" — not a true 1:1 result. Triggers are attached to their table and are
    # dropped automatically along with it, so they need no separate handling here.
    local drop_sql
    drop_sql="$(mysql --defaults-extra-file="${defaults_file}" -N -B \
        -e "SELECT CONCAT('DROP ', IF(table_type='VIEW','VIEW','TABLE'), ' IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = '${DB_NAME}'
            UNION ALL
            SELECT CONCAT('DROP ', routine_type, ' IF EXISTS \`', routine_name, '\`;') FROM information_schema.routines WHERE routine_schema = '${DB_NAME}';" \
        "${DB_NAME}" 2>/dev/null || true)"
    if [[ -n "${drop_sql}" ]]; then
        local drop_count
        drop_count="$(printf '%s\n' "${drop_sql}" | grep -c '^DROP' || true)"
        info "Dropping ${drop_count} existing table(s)/view(s)/routine(s) in '${DB_NAME}' before import..."
        if ! { printf 'SET FOREIGN_KEY_CHECKS = 0;\n%s\nSET FOREIGN_KEY_CHECKS = 1;\n' "${drop_sql}" \
            | mysql --defaults-extra-file="${defaults_file}" "${DB_NAME}"; }; then
            die "Failed while dropping ${DB_NAME}'s existing tables/views/routines ahead of import. The database may now be partially dropped — investigate manually, or restore the pre-restore safety backup taken at the start of this run."
        fi
    else
        info "No existing tables, views or routines in '${DB_NAME}' to drop."
    fi

    if ! mysql --defaults-extra-file="${defaults_file}" "${DB_NAME}" < "${EXTRACT_DIR}/db.sql"; then
        if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
            die "Database import failed partway through (see mysql's output above). The database may now be partially restored (every old table was already dropped), and no pre-restore safety backup was taken. Investigate manually before running the app."
        else
            die "Database import failed partway through (see mysql's output above). The database may now be partially restored (every old table was already dropped) — restore the safety backup taken at the start of this run before running the app."
        fi
    fi
    success "Database import complete."
}

restore_uploads() {
    announce "Extracting the backup's uploads.zip and replacing ${APP_DIR}/uploads with it (rsync --delete — anything added since the backup that isn't in it will be removed)."
    local uploads_extract
    uploads_extract="$(mktemp -d)"
    register_tmpfile "${uploads_extract}"

    if ! unzip -q -o "${EXTRACT_DIR}/uploads.zip" -d "${uploads_extract}"; then
        die "unzip failed on the backup's nested uploads.zip. It may be corrupt. The database has already been imported at this point — investigate the uploads mismatch manually."
    fi

    mkdir -p "${APP_DIR}/uploads"
    if ! rsync -a --delete "${uploads_extract}/" "${APP_DIR}/uploads/"; then
        die "rsync failed while restoring uploads/. The database has already been imported at this point — investigate the uploads/ mismatch manually."
    fi

    info "Restoring ownership (www-data:www-data) and permissions under ${APP_DIR}/uploads..."
    chown -R www-data:www-data "${APP_DIR}/uploads"
    find "${APP_DIR}/uploads" -type d -exec chmod 750 {} +
    find "${APP_DIR}/uploads" -type f -exec chmod 640 {} +
    # Same widening install.sh/restore.sh apply: uploads/ needs to stay
    # writable by the www-data group for the app to save new files into it.
    chmod -R u+rwX,g+rwX "${APP_DIR}/uploads"
    success "uploads/ restored."
}

# read_manifest_or_skip(): calls lib/common.sh's read_manifest_dir(), except
# when the manifest is encrypted (backup-manifest.json.enc) AND no
# --passphrase-file was given at all — read_manifest_dir() would normally
# die() in that case (correct for restore.sh, which has no other way to
# recover settings_enc_key), but here that's the expected, supported shape
# of --admin-user-only mode: the operator deliberately has no passphrase and
# is relying on recover_master_key_via_admin() instead. A plaintext manifest
# is still always read regardless of PASSPHRASE_FILE (no passphrase needed
# for it), and an encrypted one IS still read/enforced normally whenever
# --passphrase-file was in fact given, same as before.
read_manifest_or_skip() {
    if [[ -z "${PASSPHRASE_FILE}" && -f "${EXTRACT_DIR}/backup-manifest.json.enc" ]]; then
        warn "This backup's manifest (backup-manifest.json.enc) is encrypted and no --passphrase-file was given. Skipping manifest-based settings_enc_key recovery — relying on --admin-user instead to recover the credential vault's master key after the restore. Note: --admin-user recovery does NOT restore config_settings_enc_key itself, so SMTP/IMAP passwords and other settings-table secrets encrypted directly with the backup's ORIGINAL config_settings_enc_key will only decrypt if this --app-dir's own key already matches (true for a same-box rollback, not guaranteed otherwise)."
        return 0
    fi
    read_manifest_dir "${EXTRACT_DIR}" "${PASSPHRASE_FILE}"
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetIT admin-zip restore starting: ${BACKUP_FILE} -> ${APP_DIR} ==="

    read_app_config "${APP_DIR}"

    if [[ -n "${ADMIN_USER}" ]]; then
        resolve_admin_password
        verify_admin_auth
    fi

    run_pre_restore_backup
    extract_zip
    read_manifest_or_skip
    import_database
    restore_uploads
    apply_settings_enc_key "${APP_DIR}/config.php" "${MANIFEST_SETTINGS_ENC_KEY}"

    if [[ "${ADMIN_AUTH_VERIFIED}" -eq 1 ]]; then
        recover_master_key_via_admin
    fi

    success "=== Restore complete: ${APP_DIR} now reflects ${BACKUP_FILE} ==="
    log "RESTORE OK app_dir=${APP_DIR} backup=${BACKUP_FILE} database=${DB_NAME} admin_user_mode=${ADMIN_AUTH_VERIFIED}"
    info "Reminder: credential vault data decrypts with each user's own password-derived key, not a separate secret in the dump. If the admin password changed after this backup was taken, use Settings -> Backup's 'retrieve master key' option (admin only) if vault access is needed."
}

main "$@"
