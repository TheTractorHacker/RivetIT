#!/usr/bin/env bash
set -euo pipefail

# RivetIT - restore drill for the root-only encrypted archives made by deploy/backup.sh.
#
# The nightly drill (cron/restore_drill.php, runs as www-data) can only read the in-app zips: the encrypted backup-*.tar.gz.enc is
# deliberately root:root 600. This script is its root-side twin. It:
#   1. picks the newest backup-*.tar.gz.enc (or --backup=<file>) in --dest,
#   2. checks it against its .sha256 checksum file,
#   3. decrypts it with the passphrase file into a private temp directory and unpacks it,
#   4. hands the unpacked archive to cron/restore_drill.php, which restores the SQL into a scratch database drill_<date>_enc with the
#      scoped drill_% account, runs every check and records the result (restore_drill_log, kind "enc") - and always drops the scratch
#      database,
#   5. shreds the decrypted material on exit, whatever happens.
# It never touches the live database, never starts the application against the scratch copy, and runs no sync or cron code.
#
# Usage:
#   sudo deploy/restore_drill.sh --app-dir=<path> --passphrase-file=<path> [--backup=<file>] [--dest=<dir>] [--php=<bin>]
# Exit code: that of cron/restore_drill.php (0 pass/warn, 1 fail/error, 2 not configured).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

APP_DIR=""; PASSPHRASE_FILE=""; BACKUP_FILE=""; DEST=""; PHP_BIN="php"
LOG_FILE="${DRILL_LOG_FILE:-/var/log/itflow-restore-drill.log}"

print_help() {
    cat <<'HELP'
RivetIT - restore drill for deploy/backup.sh archives (run as root)

  sudo deploy/restore_drill.sh --app-dir=<path> --passphrase-file=<path> [options]

Required:
  --app-dir=<path>           Webroot of the instance (contains config.php, cron/restore_drill.php).
  --passphrase-file=<path>   600-permission file holding the backup passphrase.
Options:
  --backup=<file>            Drill this archive instead of the newest in --dest.
  --dest=<dir>               Where the archives are. Default: <app-dir>/backups.
  --php=<bin>                PHP CLI to use. Default: php.
The scoped drill_% database account must be set up first: see docs/RECOVERY_RUNBOOK.md.
HELP
}

for arg in "$@"; do
    case "${arg}" in
        --app-dir=*)         APP_DIR="${arg#*=}" ;;
        --passphrase-file=*) PASSPHRASE_FILE="${arg#*=}" ;;
        --backup=*)          BACKUP_FILE="${arg#*=}" ;;
        --dest=*)            DEST="${arg#*=}" ;;
        --php=*)             PHP_BIN="${arg#*=}" ;;
        --help|-h)           print_help; exit 0 ;;
        *)                   print_help; die "Unknown option: ${arg}" ;;
    esac
done

require_root "$@"
[[ -n "${APP_DIR}" && -d "${APP_DIR}" && -f "${APP_DIR}/cron/restore_drill.php" ]] || die "--app-dir must be a RivetIT webroot containing cron/restore_drill.php."
[[ -n "${PASSPHRASE_FILE}" && -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file is required and must exist."
pf_perm="$(stat -c '%a' "${PASSPHRASE_FILE}")"
[[ "${pf_perm: -2}" == "00" ]] || die "--passphrase-file has permissions ${pf_perm}; it must be owner-only (chmod 600)."
[[ -n "${DEST}" ]] || DEST="${APP_DIR}/backups"

touch "${LOG_FILE}" 2>/dev/null && chmod 640 "${LOG_FILE}" && exec > >(tee -a "${LOG_FILE}") 2>&1 || warn "cannot write ${LOG_FILE}; logging to the terminal only"

# 1. which archive
if [[ -z "${BACKUP_FILE}" ]]; then
    BACKUP_FILE="$(find "${DEST}" -maxdepth 1 -type f -name 'backup-*.tar.gz.enc' -printf '%T@ %p\n' | sort -rn | head -n1 | cut -d' ' -f2-)"
fi
[[ -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]] || die "No backup-*.tar.gz.enc found in ${DEST}."
NAME="$(basename "${BACKUP_FILE}")"
MTIME="$(stat -c '%Y' "${BACKUP_FILE}")"
info "=== restore drill: ${NAME} ==="

drill_fail() {   # record "this archive cannot be restored" as the drill result and stop
    error "$1"
    "${PHP_BIN}" "${APP_DIR}/cron/restore_drill.php" --trigger=script --fail="$1" --source-name="${NAME}" || true
    exit 1
}

# 2. checksum file
if [[ -f "${BACKUP_FILE}.sha256" ]]; then
    want="$(awk '{print $1}' "${BACKUP_FILE}.sha256")"
    got="$(sha256sum "${BACKUP_FILE}" | awk '{print $1}')"
    [[ "${want}" == "${got}" ]] || drill_fail "${NAME} does not match its .sha256 checksum file (the archive is damaged or was altered)"
    success "Checksum matches ${NAME}.sha256"
else
    warn "No ${NAME}.sha256 checksum file (archives from before the checksum step have none)."
fi

# 3. decrypt + unpack into a private directory (shredded by the shared EXIT trap)
WORK="$(mktemp -d)"; chmod 700 "${WORK}"; register_tmpfile "${WORK}"
COMBINED="${WORK}/combined.tar.gz"
# Same cipher as deploy/backup.sh / restore.sh. Newer archives may carry a raised PBKDF2 iteration count, so try the known sets in order.
decrypted=0
for extra in "" "-iter 600000"; do
    # shellcheck disable=SC2086
    if openssl enc -d -aes-256-cbc -pbkdf2 ${extra} -in "${BACKUP_FILE}" -out "${COMBINED}" -pass file:"${PASSPHRASE_FILE}" 2>/dev/null \
        && tar -tzf "${COMBINED}" >/dev/null 2>&1; then
        decrypted=1; break
    fi
done
[[ "${decrypted}" -eq 1 ]] || drill_fail "${NAME} could not be decrypted and unpacked with the passphrase file (wrong passphrase, or a damaged archive)"
UNPACKED="${WORK}/unpacked"; mkdir -p "${UNPACKED}"
tar -xzf "${COMBINED}" -C "${UNPACKED}" --no-same-owner --no-same-permissions
shred -u "${COMBINED}" 2>/dev/null || rm -f "${COMBINED}"
success "Decrypted and unpacked."

# 4. the drill itself (PHP does the restore into the scratch database and every check)
set +e
"${PHP_BIN}" "${APP_DIR}/cron/restore_drill.php" --trigger=script --extracted-dir="${UNPACKED}" --source-name="${NAME}" --source-mtime="${MTIME}"
rc=$?
set -e
exit "${rc}"
