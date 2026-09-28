#!/usr/bin/env bash
set -euo pipefail

# RivetIT — restore an encrypted deploy/backup.sh archive.
#
# The counterpart backup.sh never had: decrypts a backup-*.tar.gz.enc
# produced by deploy/backup.sh, then overwrites the target instance's
# database and uploads/ with what's inside. This is the "new server, only
# have my offsite encrypted backup" disaster-recovery path — run
# deploy/install.sh first to stand up a fresh, empty instance (packages, db,
# vhost, first-run setup), then point this script at the backup to restore
# into it.
#
# Usage:
#   restore.sh --app-dir=<path> --backup=<path> --passphrase-file=<path> --confirm-restore [options]
#   restore.sh --help
#
# Required:
#   --app-dir=<path>            Webroot of the ALREADY-INSTALLED instance to
#                               restore into (must contain config.php — run
#                               deploy/install.sh first on a fresh box).
#   --backup=<path>             Path to the backup-*.tar.gz.enc file to
#                               restore (produced by deploy/backup.sh).
#   --passphrase-file=<path>    600-permission file holding the passphrase
#                               that encrypted --backup. Also used, unless
#                               --no-pre-restore-backup-confirmed is passed,
#                               to take a safety backup of --app-dir's
#                               CURRENT state before it's overwritten.
#   --confirm-restore            Required, no-value acknowledgment that this
#                               REPLACES every table in the target database
#                               and everything under --app-dir/uploads with
#                               what's in --backup. Refuses to run without it
#                               — there is no interactive y/n prompt anywhere
#                               in this tooling to accidentally click through.
#
# Options:
#   --no-pre-restore-backup-confirmed
#                               Explicit opt-out of the pre-restore safety
#                               backup. Only use this if --app-dir has
#                               nothing worth keeping (e.g. it was just
#                               created by install.sh and never used) or the
#                               box is critically low on disk space.
#   --unattended                 Cron-friendly: output goes to
#                               /var/log/itflow-restore.log only. Without
#                               this flag, output goes to both the terminal
#                               and the logfile.
#   --help                       Show this help and exit.
#
# Must be run as root — needs to read config.php's db credentials, write
# root-owned temp files, and re-chown uploads/ back to www-data afterward.
#
# What it does NOT do: stop php-fpm, put the site in any kind of maintenance
# mode, or touch cron. mysqldump's own dumps are taken --single-transaction,
# so importing one is consistent even against a live schema, but a ticket
# automation rule or a user editing a record mid-restore can still race the
# import. For a real disaster-recovery drill this is moot (nothing is live
# yet); for a "restore over a running instance" scenario, stop traffic to it
# first if you can.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-restore.log"

APP_DIR=""
BACKUP_FILE=""
PASSPHRASE_FILE=""
CONFIRM_RESTORE=0
NO_PRE_RESTORE_BACKUP_CONFIRMED=0
UNATTENDED=0

# Populated later by read_app_config() / decrypt_and_extract() / read_manifest().
DB_HOST=""
DB_USER=""
DB_PASS=""
DB_NAME=""
INSTALLATION_ID=""
SETTINGS_ENC_KEY=""
EXTRACT_DIR=""
SQL_FILE=""
MANIFEST_SETTINGS_ENC_KEY=""

print_help() {
    cat <<'EOF'
RivetIT — restore an encrypted deploy/backup.sh archive

Usage:
  sudo deploy/restore.sh --app-dir=<path> --backup=<path> \
      --passphrase-file=<path> --confirm-restore [options]

Required:
  --app-dir=<path>            Webroot of the already-installed instance to
                              restore into (must contain config.php).
  --backup=<path>             Path to the backup-*.tar.gz.enc file to restore.
  --passphrase-file=<path>    600-permission file holding the decryption
                              passphrase. Also used for the pre-restore
                              safety backup unless opted out (see below).
  --confirm-restore            Required acknowledgment that this overwrites
                              the target database and uploads/ entirely.

Options:
  --no-pre-restore-backup-confirmed
                              Skip taking a safety backup of --app-dir's
                              current state before overwriting it.
  --unattended                 Cron-friendly: log file only, no terminal echo.
  --help                       Show this help and exit.

Steps performed, in order: pre-restore safety backup (or confirmed skip) ->
decrypt + extract --backup -> import its database dump -> replace
--app-dir/uploads with its uploads/ -> restore ownership/permissions ->
apply the backup's settings-encryption key to config.php, if its manifest
has one (see backup.sh; backups taken before this existed have no manifest
and print a warning instead - SMTP/IMAP/RMM/webhook secrets will need to be
re-entered by hand in that case).

To test a backup without touching a real instance, point --app-dir at a
disposable one instead - e.g. scripts/setup_cli.php --config-only against a
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
    [[ -f "${APP_DIR}/config.php" ]] || die "No config.php found under ${APP_DIR} — this restore script overwrites an EXISTING instance's data, it does not create one. Run deploy/install.sh first, then re-run this against the resulting --app-dir."

    [[ -n "${BACKUP_FILE}" ]] || { print_help; die "--backup is required."; }
    [[ -f "${BACKUP_FILE}" ]] || die "--backup '${BACKUP_FILE}' does not exist."

    [[ -n "${PASSPHRASE_FILE}" ]] || { print_help; die "--passphrase-file is required."; }
    [[ -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file '${PASSPHRASE_FILE}' does not exist."
    local pf_perm
    pf_perm="$(stat -c '%a' "${PASSPHRASE_FILE}")"
    if [[ "${pf_perm: -2}" != "00" ]]; then
        die "--passphrase-file '${PASSPHRASE_FILE}' has permissions ${pf_perm} (group/other can access it). Expected 600, owner-only. Fix with: chmod 600 '${PASSPHRASE_FILE}'"
    fi

    if [[ "${CONFIRM_RESTORE}" -ne 1 ]]; then
        print_help
        die "Refusing to run without --confirm-restore. This REPLACES every table in ${APP_DIR}'s database and everything under ${APP_DIR}/uploads with the contents of ${BACKUP_FILE}. Re-run with --confirm-restore once you're sure."
    fi

    command_exists openssl || die "openssl is required to decrypt --backup but is not on PATH."
    command_exists mysql || die "The mysql client is required to import the database dump but is not on PATH."
    command_exists rsync || die "rsync is required to restore uploads/ but is not on PATH. Install it: apt-get install rsync"
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

# DB_HOST/DB_USER/DB_PASS/DB_NAME/INSTALLATION_ID/SETTINGS_ENC_KEY are
# populated by lib/common.sh's shared read_app_config() (same helper
# backup.sh uses to write its manifest in the first place). Only the DB_*
# fields are actually used below — INSTALLATION_ID/SETTINGS_ENC_KEY here
# describe the TARGET's current config, not the backup being restored; see
# read_manifest() for the backup's own settings_enc_key.

# run_pre_restore_backup(): same mandatory-safety-net idiom update.sh uses
# for updates — this is the same kind of destructive step, so it gets the
# same "back up first, or make the operator explicitly opt out" gate.
run_pre_restore_backup() {
    if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
        warn "Proceeding WITHOUT a pre-restore safety backup (--no-pre-restore-backup-confirmed was passed). ${APP_DIR}'s current data is about to be overwritten with no way back except --backup itself."
        return 0
    fi

    announce "Taking a safety backup of ${APP_DIR}'s CURRENT state via deploy/backup.sh before overwriting anything."
    if ! "${SCRIPT_DIR}/backup.sh" --app-dir="${APP_DIR}" --passphrase-file="${PASSPHRASE_FILE}"; then
        die "Pre-restore safety backup failed (see deploy/backup.sh's output above). Aborting the restore entirely; ${APP_DIR} was NOT touched. Fix the backup failure, or re-run with --no-pre-restore-backup-confirmed if you accept the risk, and try again."
    fi
    success "Pre-restore safety backup completed successfully."
}

decrypt_and_extract() {
    announce "Decrypting ${BACKUP_FILE}..."
    local combined
    combined="$(mktemp)"
    chmod 600 "${combined}"
    register_tmpfile "${combined}"

    if ! openssl enc -d -aes-256-cbc -pbkdf2 \
        -in "${BACKUP_FILE}" -out "${combined}" \
        -pass file:"${PASSPHRASE_FILE}"; then
        die "Decryption failed. Either --passphrase-file doesn't match the passphrase ${BACKUP_FILE} was encrypted with, or the file is corrupt."
    fi
    success "Decrypted OK."

    EXTRACT_DIR="$(mktemp -d)"
    register_tmpfile "${EXTRACT_DIR}"

    info "Extracting archive..."
    if ! tar -xzf "${combined}" -C "${EXTRACT_DIR}"; then
        die "tar extraction failed. ${BACKUP_FILE} may be corrupt, or --passphrase-file decrypted it into garbage (wrong passphrase can produce a file that 'looks' decrypted but isn't valid gzip)."
    fi

    local -a sql_matches
    mapfile -t sql_matches < <(find "${EXTRACT_DIR}" -maxdepth 1 -name '*.sql')
    if [[ "${#sql_matches[@]}" -eq 0 ]]; then
        die "No .sql file found inside ${BACKUP_FILE} after extraction — this doesn't look like a deploy/backup.sh archive."
    fi
    if [[ "${#sql_matches[@]}" -gt 1 ]]; then
        die "Multiple .sql files found inside ${BACKUP_FILE} (${sql_matches[*]}) — expected exactly one. Refusing to guess which one to import."
    fi
    SQL_FILE="${sql_matches[0]}"
    success "Extracted OK ($(basename "${SQL_FILE}"))."
}

# read_manifest(): thin wrapper around lib/common.sh's read_manifest_dir(),
# shared with restore_admin_zip.sh (see its own comment there for the full
# three-case plaintext/encrypted/missing-manifest behavior). deploy/backup.sh's
# own manifest is always plaintext JSON -- the outer .tar.gz.enc is what's
# encrypted, not the manifest a second time inside it -- so this is always
# called with no passphrase_file. MANIFEST_SETTINGS_ENC_KEY (and friends) come
# back set as a side effect, exactly as before this was factored out into
# lib/common.sh.
read_manifest() {
    read_manifest_dir "${EXTRACT_DIR}" ""
}

import_database() {
    announce "Importing $(basename "${SQL_FILE}") into database '${DB_NAME}'. This replaces every table the dump contains."
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

    if ! mysql --defaults-extra-file="${defaults_file}" "${DB_NAME}" < "${SQL_FILE}"; then
        if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
            die "Database import failed partway through (see mysql's output above). The database may now be partially restored, and no pre-restore safety backup was taken. Investigate manually before running the app."
        else
            die "Database import failed partway through (see mysql's output above). The database may now be partially restored — restore the safety backup taken at the start of this run (via this same script, or by hand) before running the app."
        fi
    fi
    success "Database import complete."
}

restore_uploads() {
    local src_uploads="${EXTRACT_DIR}/uploads"
    if [[ ! -d "${src_uploads}" ]]; then
        warn "${BACKUP_FILE} contains no uploads/ directory; leaving ${APP_DIR}/uploads untouched."
        return 0
    fi

    announce "Replacing ${APP_DIR}/uploads with the backup's uploads/ (rsync --delete — anything added since the backup that isn't in it will be removed)."
    mkdir -p "${APP_DIR}/uploads"
    if ! rsync -a --delete "${src_uploads}/" "${APP_DIR}/uploads/"; then
        die "rsync failed while restoring uploads/. The database has already been imported at this point — investigate the uploads/ mismatch manually."
    fi

    info "Restoring ownership (www-data:www-data) and permissions under ${APP_DIR}/uploads..."
    chown -R www-data:www-data "${APP_DIR}/uploads"
    find "${APP_DIR}/uploads" -type d -exec chmod 750 {} +
    find "${APP_DIR}/uploads" -type f -exec chmod 640 {} +
    # Same widening install.sh applies: uploads/ needs to stay writable by
    # the www-data group for the app to save new files into it.
    chmod -R u+rwX,g+rwX "${APP_DIR}/uploads"
    success "uploads/ restored."
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetIT restore starting: ${BACKUP_FILE} -> ${APP_DIR} ==="

    read_app_config "${APP_DIR}"
    run_pre_restore_backup
    decrypt_and_extract
    read_manifest
    import_database
    restore_uploads
    apply_settings_enc_key "${APP_DIR}/config.php" "${MANIFEST_SETTINGS_ENC_KEY}"

    success "=== Restore complete: ${APP_DIR} now reflects ${BACKUP_FILE} ==="
    log "RESTORE OK app_dir=${APP_DIR} backup=${BACKUP_FILE} database=${DB_NAME}"
    info "Reminder: credential vault data decrypts with each user's own password-derived key, not a separate secret in the dump. If the admin password changed after this backup was taken, use Settings -> Backup's 'retrieve master key' option (admin only) if vault access is needed."
}

main "$@"
