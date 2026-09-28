#!/usr/bin/env bash
set -euo pipefail

# RivetIT — restore the in-app backup format (.zip from Settings > Backup /
# admin/post/backup.php), the CLI counterpart to restore.sh.
#
# restore.sh restores an ENCRYPTED backup-*.tar.gz.enc from deploy/backup.sh's
# own disaster-recovery timer. This restores the app's own on-demand .zip
# instead (itflow_<timestamp>_(manual|auto).zip - unencrypted, see
# deploy/README.md's backup.sh section for why the two formats coexist).
# Until now the .zip could only be restored through a browser upload
# (setup/index.php's "Restore from Backup" step); this drives the exact same
# hardened restore logic (scripts/restore_zip_cli.php, which shares its
# extraction/validation helpers with that browser path) from the command line
# instead, with the restore's own progress printed to this terminal as it runs.
#
# Usage:
#   restore_zip.sh --app-dir=<path> --zip=<path> --confirm-restore [options]
#   restore_zip.sh --help
#
# Required:
#   --app-dir=<path>             Webroot of the ALREADY-INSTALLED instance to
#                               restore into (must contain config.php — run
#                               deploy/install.sh first on a fresh box).
#   --zip=<path>                 Path to the itflow_<timestamp>_*.zip file to
#                               restore (produced by this app's own Settings >
#                               Backup feature).
#   --confirm-restore             Required, no-value acknowledgment that this
#                               REPLACES every table in the target database
#                               and everything under --app-dir/uploads with
#                               what's in --zip. Refuses to run without it —
#                               there is no interactive y/n prompt anywhere in
#                               this tooling to accidentally click through.
#
# Options:
#   --passphrase-file=<path>     600-permission file holding the passphrase
#                               used for the pre-restore safety backup of
#                               --app-dir's CURRENT state (via backup.sh) —
#                               --zip itself is never encrypted, so nothing
#                               here needs it to read --zip. Required unless
#                               --no-pre-restore-backup-confirmed is passed.
#   --no-pre-restore-backup-confirmed
#                               Explicit opt-out of the pre-restore safety
#                               backup. Only use this if --app-dir has
#                               nothing worth keeping (e.g. it was just
#                               created by install.sh and never used) or the
#                               box is critically low on disk space.
#   --unattended                 Cron-friendly: output goes to
#                               /var/log/itflow-restore-zip.log only. Without
#                               this flag, output goes to both the terminal
#                               and the logfile.
#   --help                       Show this help and exit.
#
# Must be run as root — needs to read config.php's db credentials indirectly
# (via scripts/restore_zip_cli.php, run as www-data so extracted uploads/ and
# the rewritten config.php stay www-data-owned) and write root-owned temp/log
# files.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-restore-zip.log"

APP_DIR=""
ZIP_FILE=""
PASSPHRASE_FILE=""
CONFIRM_RESTORE=0
NO_PRE_RESTORE_BACKUP_CONFIRMED=0
UNATTENDED=0

# Populated later by read_app_config() (lib/common.sh) — used only for the
# "Target database:" log line here; the actual restore reads config.php
# itself (see scripts/restore_zip_cli.php).
DB_NAME=""

# Populated later by stage_zip_for_www_data().
STAGED_ZIP=""

print_help() {
    cat <<'EOF'
RivetIT — restore the in-app backup format (.zip)

Usage:
  sudo deploy/restore_zip.sh --app-dir=<path> --zip=<path> \
      --confirm-restore [options]

Required:
  --app-dir=<path>            Webroot of the already-installed instance to
                              restore into (must contain config.php).
  --zip=<path>                Path to the itflow_<timestamp>_*.zip to restore.
  --confirm-restore            Required acknowledgment that this overwrites
                              the target database and uploads/ entirely.

Options:
  --passphrase-file=<path>    600-permission file for the pre-restore safety
                              backup's own encryption (backup.sh) — not used
                              to read --zip, which is never encrypted.
                              Required unless --no-pre-restore-backup-confirmed
                              is passed.
  --no-pre-restore-backup-confirmed
                              Skip taking a safety backup of --app-dir's
                              current state before overwriting it.
  --unattended                 Cron-friendly: log file only, no terminal echo.
  --help                       Show this help and exit.

Steps performed, in order: pre-restore safety backup (or confirmed skip) ->
scripts/restore_zip_cli.php (as www-data) extracts --zip, replaces every
table in the target database with its db.sql, and replaces --app-dir/uploads
with its uploads.zip. Its own progress is printed live as it runs.

To restore a deploy/backup.sh archive (backup-*.tar.gz.enc) instead, use
restore.sh — the two backup formats are not interchangeable.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --app-dir=*)                       APP_DIR="${arg#*=}" ;;
            --zip=*)                            ZIP_FILE="${arg#*=}" ;;
            --passphrase-file=*)               PASSPHRASE_FILE="${arg#*=}" ;;
            --confirm-restore)                  CONFIRM_RESTORE=1 ;;
            --no-pre-restore-backup-confirmed)  NO_PRE_RESTORE_BACKUP_CONFIRMED=1 ;;
            --unattended)                        UNATTENDED=1 ;;
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

    [[ -n "${ZIP_FILE}" ]] || { print_help; die "--zip is required."; }
    [[ -f "${ZIP_FILE}" ]] || die "--zip '${ZIP_FILE}' does not exist."

    if [[ "${CONFIRM_RESTORE}" -ne 1 ]]; then
        print_help
        die "Refusing to run without --confirm-restore. This REPLACES every table in ${APP_DIR}'s database and everything under ${APP_DIR}/uploads with the contents of ${ZIP_FILE}. Re-run with --confirm-restore once you're sure."
    fi

    if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -ne 1 ]]; then
        [[ -n "${PASSPHRASE_FILE}" ]] || die "--passphrase-file is required (for the pre-restore safety backup) unless --no-pre-restore-backup-confirmed is passed."
        [[ -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file '${PASSPHRASE_FILE}' does not exist."
    fi

    command_exists php || die "php is required to run scripts/restore_zip_cli.php but is not on PATH."
    [[ -f "${APP_DIR}/scripts/restore_zip_cli.php" ]] || die "scripts/restore_zip_cli.php not found under ${APP_DIR} — is this an up-to-date checkout? Run deploy/update.sh first."
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

# Mirrors restore.sh's own run_pre_restore_backup(): same mandatory-safety-net
# idiom, same underlying backup.sh call — this is the same kind of destructive
# step, so it gets the same "back up first, or make the operator explicitly
# opt out" gate.
run_pre_restore_backup() {
    if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
        warn "Proceeding WITHOUT a pre-restore safety backup (--no-pre-restore-backup-confirmed was passed). ${APP_DIR}'s current data is about to be overwritten with no way back except --zip itself."
        return 0
    fi

    announce "Taking a safety backup of ${APP_DIR}'s CURRENT state via deploy/backup.sh before overwriting anything."
    if ! "${SCRIPT_DIR}/backup.sh" --app-dir="${APP_DIR}" --passphrase-file="${PASSPHRASE_FILE}"; then
        die "Pre-restore safety backup failed (see deploy/backup.sh's output above). Aborting the restore entirely; ${APP_DIR} was NOT touched. Fix the backup failure, or re-run with --no-pre-restore-backup-confirmed if you accept the risk, and try again."
    fi
    success "Pre-restore safety backup completed successfully."
}

# stage_zip_for_www_data(): scripts/restore_zip_cli.php runs AS www-data (so
# the uploads/ it extracts and the config.php it finalizes come out
# www-data-owned, with no separate chown pass needed afterward) — but --zip
# itself was very likely placed by root or an admin's own login (an scp'd
# file, something dropped in /root or a home directory), which www-data
# normally can't read at all. Rather than requiring the operator to chmod
# their backup file for a system account by hand, root (this script) stages
# a www-data-readable copy in /tmp and hands THAT path to the CLI script.
# Registered via register_tmpfile so lib/common.sh's shared EXIT trap shreds
# it on the way out, success or failure.
stage_zip_for_www_data() {
    local staged
    staged="$(mktemp --suffix=.zip)"
    register_tmpfile "${staged}"
    cp "${ZIP_FILE}" "${staged}"
    chown www-data:www-data "${staged}"
    chmod 640 "${staged}"
    STAGED_ZIP="${staged}"
}

run_restore() {
    announce "Restoring ${ZIP_FILE} into ${APP_DIR} (scripts/restore_zip_cli.php, running as www-data)..."
    if ! ( cd "${APP_DIR}/scripts" && sudo -u www-data php restore_zip_cli.php --zip="${STAGED_ZIP}" --confirm-restore ); then
        die "scripts/restore_zip_cli.php failed. See its output above."
    fi
    success "Restore complete; ${APP_DIR} now reflects ${ZIP_FILE}."
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetIT zip-backup restore starting: ${ZIP_FILE} -> ${APP_DIR} ==="

    read_app_config "${APP_DIR}"
    info "Target database: ${DB_NAME}"

    run_pre_restore_backup
    stage_zip_for_www_data
    run_restore

    success "=== Restore complete: ${APP_DIR} now reflects ${ZIP_FILE} ==="
    log "RESTORE_ZIP OK app_dir=${APP_DIR} zip=${ZIP_FILE} database=${DB_NAME}"
}

main "$@"
