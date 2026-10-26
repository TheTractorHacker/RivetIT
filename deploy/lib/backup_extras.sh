#!/usr/bin/env bash
# RivetIT - recovery additions for deploy/backup.sh: checksum file, table snapshot, status report to the application, optional
# off-site copy with retention. Sourced by backup.sh; kept out of backup.sh so the backup procedure itself stays as it was.
#
# Variables used (set by backup.sh): APP_DIR, DEST, RETENTION_DAYS, OFFSITE_ENABLED, OFFSITE_CONFIG, OFFSITE_RETENTION_OVERRIDE,
# SCRIPT_DIR, LOG_FILE. State kept here: BACKUP_STARTED_TS, BACKUP_RESULT_FILE, BACKUP_STATUS_SENT, BACKUP_OFFSITE_RESULT.

# shellcheck source=./offsite.sh
source "${SCRIPT_DIR}/lib/offsite.sh"

BACKUP_STARTED_TS="$(date +%s)"
BACKUP_RESULT_FILE=""
BACKUP_STATUS_SENT=0
BACKUP_OFFSITE_RESULT=""

# backup_make_snapshot <dest-file>: table snapshot (names + counts, no data) for the restore drill; silently absent on failure.
backup_make_snapshot() {
    local out="$1"
    : > "${out}"; chmod 600 "${out}"
    php "${SCRIPT_DIR}/lib/table_snapshot.php" "${APP_DIR}" "${out}" || true
    [[ -s "${out}" ]]
}

# backup_postgres_dump <dest-file>: RivetCore 1.0.0-rc.41 (issue #89 phase 4), docs/rmm/POSTGRES.md section 8. Only when the RMM data store
# is 'postgres' (read from the control database, never assumed): pg_dump of the whole RMM store to <dest-file>, via
# rmm-postgres-backup.sh, using the SAME encrypted archive and retention as the control database dump instead of a second file/pipeline -
# this edition's own backup.sh already encrypts one combined tar.gz with openssl, so the PostgreSQL dump travels inside it (as
# rmm-postgres.dump) rather than through POSTGRES.md's generic age/gpg example, which is for an edition with no existing pipeline.
# Connection details come ONLY from the stored data store setting (pg_dump_env.php), never from a command-line argument; the password
# reaches pg_dump only via PGPASSFILE (0600, shredded in the same subshell it is used in). Returns 1 (skip, not a failure) on MariaDB.
backup_postgres_dump() {
    local out="$1" env_lines pgpassfile=""
    if ! env_lines="$(php "${SCRIPT_DIR}/lib/pg_dump_env.php" "${APP_DIR}")"; then
        error "Could not read the RMM PostgreSQL connection (pg_dump_env.php failed); see its stderr above. No PostgreSQL dump was produced this run."
        return 2
    fi
    [[ -n "${env_lines}" ]] || return 1   # data store is 'mariadb': nothing to dump here
    : > "${out}"; chmod 600 "${out}"
    # register_tmpfile() appends to an array in THIS shell; a subshell's copy of that array is discarded when it exits, so the
    # PGPASSFILE is shredded explicitly inside the subshell instead, before it exits either way.
    (
        eval "${env_lines}"
        trap 'shred -u "${PGPASSFILE}" 2>/dev/null || rm -f "${PGPASSFILE}"' EXIT
        info "Dumping the RMM PostgreSQL store (${RIVETCORE_PG_DATABASE})..."
        "${APP_DIR}/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh" > "${out}"
    )
    local rc=$?
    if [[ ${rc} -ne 0 || ! -s "${out}" ]]; then
        error "rmm-postgres-backup.sh failed (exit ${rc}). No usable backup was produced this run; nothing sensitive was left on disk."
        die "PostgreSQL dump failed; aborting (the control database dump alone would not be a complete backup while the data store is postgres)."
    fi
    success "RMM PostgreSQL dump complete ($(du -h "${out}" | awk '{print $1}'))."
    return 0
}

# backup_write_checksum <encrypted-file>: writes <file>.sha256 ("<hash>  <name>", readable by sha256sum -c) and prints the hash.
backup_write_checksum() {
    local enc="$1" hash
    hash="$(sha256sum "${enc}" | awk '{print $1}')"
    printf '%s  %s\n' "${hash}" "$(basename "${enc}")" > "${enc}.sha256"
    chmod 600 "${enc}.sha256"; chown root:root "${enc}.sha256" 2>/dev/null || true
    printf '%s' "${hash}"
}

# backup_cleanup_checksums: remove .sha256 files whose archive retention has already removed.
backup_cleanup_checksums() {
    local s
    while IFS= read -r -d '' s; do
        [[ -f "${s%.sha256}" ]] || rm -f -- "${s}"
    done < <(find "${DEST}" -maxdepth 1 -type f -name 'backup-*.enc.sha256' -print0)
}

# backup_report_status <ok 0|1> [error]: tell the application (best effort, never fails the backup).
backup_report_status() {
    local ok="$1" err="${2:-}" size="" sha=""
    [[ "${BACKUP_STATUS_SENT}" -eq 1 ]] && return 0
    BACKUP_STATUS_SENT=1
    [[ -f "${APP_DIR}/config.php" && -f "${SCRIPT_DIR}/lib/backup_status.php" ]] || return 0
    local -a args=(--app-dir="${APP_DIR}" --ok="${ok}" --started="${BACKUP_STARTED_TS}")
    if [[ "${ok}" == "1" && -n "${BACKUP_RESULT_FILE}" && -f "${BACKUP_RESULT_FILE}" ]]; then
        size="$(stat -c '%s' "${BACKUP_RESULT_FILE}")"
        sha="$(awk '{print $1}' "${BACKUP_RESULT_FILE}.sha256" 2>/dev/null || true)"
        args+=(--file="$(basename "${BACKUP_RESULT_FILE}")" --size="${size}" --sha256="${sha}")
    fi
    [[ -n "${err}" ]] && args+=(--error="${err}")
    [[ -n "${BACKUP_OFFSITE_RESULT}" ]] && args+=(--offsite="${BACKUP_OFFSITE_RESULT}")
    php "${SCRIPT_DIR}/lib/backup_status.php" "${args[@]}" >/dev/null 2>&1 || true
}

# backup_exit_hook <exit-code>: registered with register_exit_hook; reports a run that died before backup_post_steps finished.
backup_exit_hook() {
    local rc="$1" err=""
    [[ "${BACKUP_STATUS_SENT}" -eq 1 ]] && return 0
    [[ "${rc}" -eq 0 ]] && return 0
    err="$(grep -F '[FAIL]' "${LOG_FILE:-/dev/null}" 2>/dev/null | tail -n1 | sed 's/\x1b\[[0-9;]*m//g' | sed 's/^.*\[FAIL\][[:space:]]*//' | cut -c1-500 || true)"
    BACKUP_RESULT_FILE=""
    backup_report_status 0 "${err:-backup.sh exited with status ${rc}}"
}

# backup_post_steps: after do_backup succeeded. Checksum, off-site copy (when asked), retention there too, then the status report.
# An off-site failure never deletes or hides the good local archive; it is recorded and makes the script exit non-zero (3) so the
# systemd unit shows as failed.
backup_post_steps() {
    local rc=0 encrypted="${BACKUP_RESULT_FILE}"
    [[ -f "${encrypted}" ]] || return 0
    backup_write_checksum "${encrypted}" >/dev/null
    backup_cleanup_checksums
    success "Checksum file written: ${encrypted}.sha256"

    if [[ "${OFFSITE_ENABLED}" -eq 1 ]]; then
        if ! offsite_load_config "${OFFSITE_CONFIG}"; then
            BACKUP_OFFSITE_RESULT="failed: offsite config ${OFFSITE_CONFIG} is not usable"
            error "${BACKUP_OFFSITE_RESULT}"
            rc=3
        else
            info "Copying to off-site destination (${OFFSITE_METHOD})..."
            if BACKUP_OFFSITE_RESULT="$(offsite_push "${encrypted}" "${encrypted}.sha256")"; then
                success "Off-site: ${BACKUP_OFFSITE_RESULT}"
                local days="${OFFSITE_RETENTION_OVERRIDE:-${OFFSITE_RETENTION_DAYS:-${RETENTION_DAYS}}}" removed
                removed="$(offsite_apply_retention "${days}" | tr '\n' ' ')"
                info "Off-site retention (${days} days): ${removed:-nothing to remove}"
            else
                error "Off-site copy FAILED: ${BACKUP_OFFSITE_RESULT}. The local archive is intact."
                rc=3
            fi
        fi
    else
        BACKUP_OFFSITE_RESULT="not configured"
        warn "No off-site copy (create ${OFFSITE_CONFIG} from deploy/etc/offsite.conf.example, or pass --offsite=<config>). Copies on this disk share its fate."
    fi
    backup_report_status 1
    return "${rc}"
}
