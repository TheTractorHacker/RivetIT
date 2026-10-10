#!/usr/bin/env bash
set -euo pipefail

# RivetIT — single-instance encrypted backup.
#
# Dumps one instance's database (mysqldump), its uploads/ directory
# (user-uploaded contracts/documents/tickets/etc — the content that isn't
# reproducible by re-cloning the git repo), and a small backup-manifest.json
# (installation_id + config_settings_enc_key, needed to restore onto a fresh
# instance — see the comment above the manifest write in do_backup() below),
# bundles all of it into one archive, encrypts it, and enforces a retention
# window on old encrypted backups. See deploy/restore.sh for the matching
# restore path.
#
# Meant to be driven by deploy/templates/itflow-backup.{service,timer} (a
# daily systemd timer) or root's own crontab — see this script's own
# argument parsing below for exactly what it needs. It is also called
# directly by deploy/update.sh as the mandatory pre-update backup step.
#
# Usage:
#   backup.sh --app-dir=<path> --passphrase-file=<path> [options]
#   backup.sh --help
#
# Required:
#   --app-dir=<path>            Webroot of the RivetIT instance to
#                               back up (the directory containing config.php).
#   --passphrase-file=<path>    Path to a 600-permission file holding the
#                               encryption passphrase. REQUIRED — this script
#                               refuses to run without it rather than ever
#                               silently writing an unencrypted dump to disk.
#                               The database dump contains password hashes
#                               and this app's own encrypted-but-still-
#                               sensitive credential vault ciphertexts, so an
#                               unencrypted backup is a real exposure, not a
#                               theoretical one.
#
# Options:
#   --retention-days=<N>        Delete encrypted backups older than N days
#                               from --dest. Default: 14.
#   --dest=<dir>                Where encrypted backups are written. Default:
#                               <app-dir>/backups (see the long comment above
#                               cleanup_old_backups() for why sharing that
#                               directory with the app's own admin-panel
#                               backup feature is fine).
#
# Must be run as root (see require_root below): the final encrypted backup
# is deliberately chmod 600, owned root:root — NOT readable by www-data —
# since a webshell that somehow got code execution as www-data should not
# be able to read this instance's entire backup history. That means this
# script itself has to be run by something already running as root: root's
# own crontab, or (the recommended path) the itflow-backup.service systemd
# unit shipped in deploy/templates/, which runs as User=root.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-backup.log"

# Recovery additions: checksum file, status report to the app, optional off-site copy (see lib/backup_extras.sh).
# shellcheck source=./lib/backup_extras.sh
source "${SCRIPT_DIR}/lib/backup_extras.sh"

APP_DIR=""
RETENTION_DAYS=14
DEST=""
PASSPHRASE_FILE=""
OFFSITE_ENABLED=0
OFFSITE_DISABLED=0
OFFSITE_CONFIG="/etc/itflow/offsite.conf"
OFFSITE_RETENTION_OVERRIDE=""

print_help() {
    cat <<'EOF'
RivetIT — single-instance encrypted backup

Usage:
  sudo deploy/backup.sh --app-dir=<path> --passphrase-file=<path> [options]

Required:
  --app-dir=<path>           Webroot of the instance to back up (must
                              contain config.php).
  --passphrase-file=<path>   600-permission file holding the encryption
                              passphrase. Refused if missing or not locked
                              down to owner-only access.

Options:
  --retention-days=<N>       Delete encrypted backups older than N days
                              from --dest. Default: 14.
  --dest=<dir>                Directory encrypted backups are written to.
                              Default: <app-dir>/backups.
  --offsite[=<config>]        Also copy each archive (and its .sha256 checksum
                              file) off this machine and apply retention there
                              too. <config> defaults to /etc/itflow/offsite.conf
                              (template: deploy/etc/offsite.conf.example; rclone,
                              S3, SFTP or a mounted directory).
  --no-offsite                Skip the off-site copy even if /etc/itflow/offsite.conf
                              exists (without --offsite/--no-offsite, that file's
                              presence turns the off-site copy on).
  --offsite-retention-days=<N>  Retention for the off-site copies (default: the
                              config's OFFSITE_RETENTION_DAYS, else --retention-days).
  --help                      Show this help and exit.

Must be run as root. Every run's outcome (success + file size + duration,
or the specific failure) is logged to /var/log/itflow-backup.log.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --app-dir=*)          APP_DIR="${arg#*=}" ;;
            --retention-days=*)   RETENTION_DAYS="${arg#*=}" ;;
            --dest=*)              DEST="${arg#*=}" ;;
            --passphrase-file=*)  PASSPHRASE_FILE="${arg#*=}" ;;
            --offsite)            OFFSITE_ENABLED=1 ;;
            --no-offsite)         OFFSITE_DISABLED=1 ;;
            --offsite=*)          OFFSITE_ENABLED=1; OFFSITE_CONFIG="${arg#*=}" ;;
            --offsite-retention-days=*) OFFSITE_RETENTION_OVERRIDE="${arg#*=}" ;;
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
    [[ -f "${APP_DIR}/config.php" ]] || die "No config.php found under ${APP_DIR} — is this an installed RivetIT instance? (delete a partial install and re-run deploy/install.sh, or point --app-dir at the correct instance)."

    [[ -n "${PASSPHRASE_FILE}" ]] || { print_help; die "--passphrase-file is required — refusing to run without an encryption passphrase rather than silently writing an unencrypted database dump to disk."; }
    [[ -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file '${PASSPHRASE_FILE}' does not exist."

    local pf_perm
    pf_perm="$(stat -c '%a' "${PASSPHRASE_FILE}")"
    if [[ "${pf_perm: -2}" != "00" ]]; then
        die "--passphrase-file '${PASSPHRASE_FILE}' has permissions ${pf_perm} (group/other can access it). Expected 600, owner-only — this file gates the key that decrypts every backup this instance has ever taken. Fix with: chmod 600 '${PASSPHRASE_FILE}'"
    fi

    [[ "${RETENTION_DAYS}" =~ ^[0-9]+$ ]] || die "--retention-days must be a non-negative integer (got: '${RETENTION_DAYS}')."

    if [[ -z "${DEST}" ]]; then
        DEST="${APP_DIR}/backups"
    fi

    # The default off-site config being present is the opt-in, so an existing systemd unit needs no edit: drop the file in place.
    if [[ "${OFFSITE_ENABLED}" -eq 0 && "${OFFSITE_DISABLED}" -eq 0 && -f "${OFFSITE_CONFIG}" ]]; then
        OFFSITE_ENABLED=1
    fi
    [[ "${OFFSITE_DISABLED}" -eq 1 ]] && OFFSITE_ENABLED=0
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    # Every run's narration lands in both places: the terminal (useful when
    # run by hand) and the persistent logfile (what a systemd timer / cron
    # run leaves behind for later inspection or monitoring to grep). No
    # set -x here, unlike install.sh — every command below that touches a
    # secret already keeps it off argv (defaults-extra-file, -pass file:),
    # so nothing needs to be hidden from tracing, but a full command trace
    # would just add noise against the "log the outcome" ask this script
    # actually has.
    exec > >(tee -a "${LOG_FILE}") 2>&1
}

# cleanup_old_backups(): enforces --retention-days on this script's OWN
# output (backup-*.enc) in --dest. When --dest is left at its default
# (<app-dir>/backups), that directory is shared with the app's own built-in
# admin-panel backup feature (admin/post/backup.php), which writes its own
# unencrypted dumps/zips there under different names — this only ever
# touches files matching this script's own backup-*.enc naming, so the two
# features coexist without either one deleting the other's files.
cleanup_old_backups() {
    info "Enforcing retention: removing backup-*.enc older than ${RETENTION_DAYS} day(s) from ${DEST}..."
    local -a deleted=()
    while IFS= read -r -d '' f; do
        deleted+=("$(basename "${f}")")
    done < <(find "${DEST}" -maxdepth 1 -type f -name 'backup-*.enc' -mtime "+${RETENTION_DAYS}" -print0)

    if [[ "${#deleted[@]}" -gt 0 ]]; then
        find "${DEST}" -maxdepth 1 -type f -name 'backup-*.enc' -mtime "+${RETENTION_DAYS}" -delete
        info "Deleted ${#deleted[@]} backup(s) older than ${RETENTION_DAYS}d: ${deleted[*]}"
    else
        info "No backups older than ${RETENTION_DAYS} day(s) to delete."
    fi
}

do_backup() {
    local start_ts end_ts duration
    start_ts="$(date +%s)"

    mkdir -p "${DEST}"

    local timestamp
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    local sql_file="${DEST}/backup-${DB_NAME}-${timestamp}.sql"
    local combined="${DEST}/backup-${DB_NAME}-${timestamp}.tar.gz"
    local encrypted="${combined}.enc"

    # Pre-create both intermediates chmod 600 BEFORE anything writes to
    # them. A plain `mysqldump > file` / `tar -czf file ...` creates the
    # file fresh via O_CREAT, which applies the process umask (typically
    # leaving it group/world-readable) — but open() only applies that mode
    # when it actually CREATES the file; if the file already exists with
    # tighter permissions, writing into it does not loosen them. So these
    # dumps are never even briefly world/group-readable on disk, not just
    # "cleaned up eventually" once encryption finishes.
    : > "${sql_file}"
    chmod 600 "${sql_file}"
    register_tmpfile "${sql_file}"

    : > "${combined}"
    chmod 600 "${combined}"
    register_tmpfile "${combined}"

    info "Dumping database '${DB_NAME}'..."
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

    if ! mysqldump --defaults-extra-file="${defaults_file}" \
        --single-transaction --quick --routines --triggers \
        "${DB_NAME}" > "${sql_file}"; then
        die "mysqldump failed for database '${DB_NAME}'. No encrypted backup was produced this run; the (600, root-only) partial dump will be shredded on exit."
    fi
    success "Database dump complete ($(du -h "${sql_file}" | awk '{print $1}'))."

    # backup-manifest.json travels inside the encrypted archive alongside the
    # dump so deploy/restore.sh can recover config_settings_enc_key on a
    # brand-new box — that key never appears in the SQL dump itself (it lives
    # only in config.php, which this script does not back up), so without
    # this manifest a restore onto a fresh instance would decrypt every
    # SMTP/IMAP password, RMM/webhook secret, and the wrapped credential-vault
    # master key to garbage. The whole archive is openssl-encrypted end to
    # end below, same as the SQL dump itself, so this is no less protected
    # than the data it travels with.
    local manifest_file="${DEST}/backup-manifest.json"
    : > "${manifest_file}"
    chmod 600 "${manifest_file}"
    register_tmpfile "${manifest_file}"
    php -r '
        $data = [
            "schema_version"    => 1,
            "db_name"           => $argv[1],
            "installation_id"   => $argv[2],
            "settings_enc_key"  => $argv[3],
            "backup_timestamp"  => $argv[4],
        ];
        file_put_contents($argv[5], json_encode($data, JSON_PRETTY_PRINT));
    ' -- "${DB_NAME}" "${INSTALLATION_ID}" "${SETTINGS_ENC_KEY}" "${timestamp}" "${manifest_file}"

    # Table snapshot for the restore drill (names + counts only); the backup is complete without it.
    local snapshot_file="${DEST}/table-snapshot.json"
    register_tmpfile "${snapshot_file}"
    local -a snapshot_member=()
    if backup_make_snapshot "${snapshot_file}"; then snapshot_member=("$(basename "${snapshot_file}")"); fi

    info "Bundling the database dump with ${APP_DIR}/uploads into ${combined}..."
    local -a tar_members=(-C "$(dirname "${sql_file}")" "$(basename "${sql_file}")" "$(basename "${manifest_file}")" "${snapshot_member[@]}")
    if [[ -d "${APP_DIR}/uploads" ]]; then
        tar_members+=(-C "${APP_DIR}" uploads)
    else
        warn "${APP_DIR}/uploads does not exist; backing up the database only. (Every RivetIT install creates this directory — check --app-dir is correct.)"
    fi
    if ! tar -czf "${combined}" "${tar_members[@]}"; then
        die "tar failed while bundling the backup archive. No encrypted backup was produced this run."
    fi
    success "Archive bundled ($(du -h "${combined}" | awk '{print $1}'))."

    info "Encrypting archive..."
    if ! openssl enc -aes-256-cbc -pbkdf2 -salt \
        -in "${combined}" -out "${encrypted}" \
        -pass file:"${PASSPHRASE_FILE}"; then
        rm -f "${encrypted}"
        die "openssl encryption failed. No usable backup was produced this run; the unencrypted intermediates will be shredded on exit, nothing sensitive was left on disk."
    fi

    # The unencrypted sql_file/combined are shredded by common.sh's shared
    # EXIT trap (they were register_tmpfile'd above) — no separate cleanup
    # needed here, and it fires whether this function returns normally or
    # the script dies partway through a later step.
    chmod 600 "${encrypted}"
    chown root:root "${encrypted}"

    end_ts="$(date +%s)"
    duration=$(( end_ts - start_ts ))
    local size_human
    size_human="$(du -h "${encrypted}" | awk '{print $1}')"

    BACKUP_RESULT_FILE="${encrypted}"
    success "Backup complete: ${encrypted} (${size_human}, ${duration}s)"
    log "BACKUP OK database=${DB_NAME} file=${encrypted} size=${size_human} duration=${duration}s"

    cleanup_old_backups
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetIT backup starting for ${APP_DIR} ==="
    read_app_config "${APP_DIR}"
    # Called as a plain statement, deliberately NOT as `if ! do_backup;
    # then ...` — bash suspends `set -e` for the ENTIRE body of a function
    # called as an if/while condition, not just its final return value, so
    # wrapping it that way would silently swallow a failure in any command
    # inside do_backup that isn't individually guarded with its own
    # `if ! ...; then die; fi` (mysqldump/tar/openssl are; a handful of
    # simpler steps like `mkdir -p` are not). Calling it as a bare statement
    # keeps normal `set -e` propagation intact for the whole function, and
    # do_backup's own die() calls already log a specific, actionable message
    # for every failure that matters before this script exits non-zero.
    register_exit_hook backup_exit_hook
    do_backup
    backup_post_steps
}

main "$@"
