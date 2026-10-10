#!/usr/bin/env bash
# RivetIT - off-site copy of deploy/backup.sh archives (rclone, S3 via the aws CLI, SFTP, or a mounted directory).
#
# Sourced (never executed) by deploy/backup.sh. The encrypted archive and its .sha256 checksum file are pushed; the archive is
# already encrypted end to end, so nothing readable leaves the box. Retention is applied to the off-site copy too, judged from the
# timestamp inside each file name (backup-<db>-YYYYMMDDTHHMMSSZ.tar.gz.enc), so it works the same for every method.
#
# Configuration is a root-owned KEY=VALUE file (see deploy/etc/offsite.conf.example). It is PARSED, never sourced, only the keys
# listed in OFFSITE_KEYS are read, and it must not be group/world writable. Credentials never live in it: rclone reads its own
# config file, the aws CLI its credentials file, SFTP a key file.

OFFSITE_KEYS=(OFFSITE_METHOD OFFSITE_RETENTION_DAYS OFFSITE_RCLONE_REMOTE OFFSITE_RCLONE_CONFIG OFFSITE_S3_URI OFFSITE_S3_ENDPOINT
    OFFSITE_S3_CREDENTIALS_FILE OFFSITE_S3_PROFILE OFFSITE_SFTP_TARGET OFFSITE_SFTP_KEY OFFSITE_SFTP_PORT OFFSITE_SFTP_KNOWN_HOSTS OFFSITE_LOCAL_DIR)

OFFSITE_METHOD=""; OFFSITE_RETENTION_DAYS=""; OFFSITE_RCLONE_REMOTE=""; OFFSITE_RCLONE_CONFIG=""; OFFSITE_S3_URI=""; OFFSITE_S3_ENDPOINT=""
OFFSITE_S3_CREDENTIALS_FILE=""; OFFSITE_S3_PROFILE=""; OFFSITE_SFTP_TARGET=""; OFFSITE_SFTP_KEY=""; OFFSITE_SFTP_PORT="22"; OFFSITE_SFTP_KNOWN_HOSTS=""; OFFSITE_LOCAL_DIR=""

# offsite_load_config <file>: returns 0 on success, 1 with a message on stderr otherwise.
offsite_load_config() {
    local file="$1" line key val k known
    [[ -f "${file}" ]] || { echo "offsite config '${file}' does not exist" >&2; return 1; }
    local mode owner
    mode="$(stat -c '%a' "${file}")"; owner="$(stat -c '%u' "${file}")"
    if [[ "${owner}" != "0" && "${EUID}" -eq 0 ]]; then echo "offsite config '${file}' must be owned by root" >&2; return 1; fi
    if [[ "${mode: -1}" =~ [2367] || "${mode: -2:1}" =~ [2367] ]]; then echo "offsite config '${file}' is group/world writable (mode ${mode})" >&2; return 1; fi
    while IFS= read -r line || [[ -n "${line}" ]]; do
        line="${line%%#*}"                                  # strip comments
        [[ "${line}" =~ ^[[:space:]]*([A-Z0-9_]+)[[:space:]]*=[[:space:]]*(.*)[[:space:]]*$ ]] || continue
        key="${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
        val="${val%"${val##*[![:space:]]}"}"               # trim trailing space
        if [[ "${val}" =~ ^\"(.*)\"$ || "${val}" =~ ^\'(.*)\'$ ]]; then val="${BASH_REMATCH[1]}"; fi
        known=0
        for k in "${OFFSITE_KEYS[@]}"; do [[ "${k}" == "${key}" ]] && known=1; done
        if [[ "${known}" -eq 1 ]]; then printf -v "${key}" '%s' "${val}"; else echo "offsite config: ignoring unknown key ${key}" >&2; fi
    done < "${file}"
    case "${OFFSITE_METHOD}" in
        rclone) [[ -n "${OFFSITE_RCLONE_REMOTE}" ]] || { echo "offsite config: OFFSITE_RCLONE_REMOTE is required for rclone" >&2; return 1; } ;;
        s3)     [[ "${OFFSITE_S3_URI}" == s3://* ]] || { echo "offsite config: OFFSITE_S3_URI (s3://bucket/prefix/) is required for s3" >&2; return 1; } ;;
        sftp)   [[ "${OFFSITE_SFTP_TARGET}" == *@*:* && -n "${OFFSITE_SFTP_KEY}" ]] || { echo "offsite config: OFFSITE_SFTP_TARGET (user@host:/dir) and OFFSITE_SFTP_KEY are required for sftp" >&2; return 1; } ;;
        local)  [[ "${OFFSITE_LOCAL_DIR}" == /* ]] || { echo "offsite config: OFFSITE_LOCAL_DIR (absolute path) is required for local" >&2; return 1; } ;;
        *)      echo "offsite config: OFFSITE_METHOD must be one of rclone, s3, sftp, local (got '${OFFSITE_METHOD}')" >&2; return 1 ;;
    esac
    [[ -z "${OFFSITE_RETENTION_DAYS}" || "${OFFSITE_RETENTION_DAYS}" =~ ^[0-9]+$ ]] || { echo "offsite config: OFFSITE_RETENTION_DAYS must be a number" >&2; return 1; }
    [[ "${OFFSITE_SFTP_PORT}" =~ ^[0-9]+$ ]] || { echo "offsite config: OFFSITE_SFTP_PORT must be a number" >&2; return 1; }
    return 0
}

# offsite_name_epoch <name>: epoch seconds (UTC) parsed from the YYYYMMDDTHHMMSSZ in a backup file name; empty if none.
offsite_name_epoch() {
    local name="$1"
    if [[ "${name}" =~ ([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})Z ]]; then
        date -u -d "${BASH_REMATCH[1]}-${BASH_REMATCH[2]}-${BASH_REMATCH[3]} ${BASH_REMATCH[4]}:${BASH_REMATCH[5]}:${BASH_REMATCH[6]} UTC" +%s 2>/dev/null || true
    fi
}

# offsite_expired_names <cutoff_epoch>: reads names on stdin, prints those older than the cutoff. Only names that match
# backup-*.tar.gz.enc or backup-*.tar.gz.enc.sha256 AND carry a parseable timestamp are ever printed - nothing else in the
# destination can be selected for deletion.
offsite_expired_names() {
    local cutoff="$1" name ep
    while IFS= read -r name; do
        [[ "${name}" =~ ^backup-.+\.tar\.gz\.enc(\.sha256)?$ ]] || continue
        ep="$(offsite_name_epoch "${name}")"
        [[ -n "${ep}" && "${ep}" -lt "${cutoff}" ]] && printf '%s\n' "${name}"
    done
    return 0
}

_offsite_sftp() {   # _offsite_sftp <batch commands on stdin>
    local -a opts=(-b - -P "${OFFSITE_SFTP_PORT}" -i "${OFFSITE_SFTP_KEY}" -o BatchMode=yes -o StrictHostKeyChecking=yes)
    [[ -n "${OFFSITE_SFTP_KNOWN_HOSTS}" ]] && opts+=(-o "UserKnownHostsFile=${OFFSITE_SFTP_KNOWN_HOSTS}")
    sftp "${opts[@]}" "${OFFSITE_SFTP_TARGET%%:*}"
}
_offsite_sftp_dir() { printf '%s' "${OFFSITE_SFTP_TARGET#*:}"; }

_offsite_rclone() {
    local -a a=(rclone); [[ -n "${OFFSITE_RCLONE_CONFIG}" ]] && a+=(--config "${OFFSITE_RCLONE_CONFIG}")
    "${a[@]}" "$@"
}
_offsite_aws() {
    local -a a=(aws); [[ -n "${OFFSITE_S3_ENDPOINT}" ]] && a+=(--endpoint-url "${OFFSITE_S3_ENDPOINT}"); [[ -n "${OFFSITE_S3_PROFILE}" ]] && a+=(--profile "${OFFSITE_S3_PROFILE}")
    if [[ -n "${OFFSITE_S3_CREDENTIALS_FILE}" ]]; then AWS_SHARED_CREDENTIALS_FILE="${OFFSITE_S3_CREDENTIALS_FILE}" "${a[@]}" "$@"; else "${a[@]}" "$@"; fi
}
_offsite_s3_base() { local u="${OFFSITE_S3_URI}"; printf '%s' "${u%/}/"; }
_offsite_rclone_base() { local r="${OFFSITE_RCLONE_REMOTE}"; printf '%s' "${r%/}/"; }

# offsite_put <local_file>: copy one file to the destination under its own name (written under a temporary name and renamed where
# the method allows, so a half-uploaded archive is never mistaken for a good one).
offsite_put() {
    local src="$1" name; name="$(basename "${src}")"
    case "${OFFSITE_METHOD}" in
        local)  mkdir -p "${OFFSITE_LOCAL_DIR}" && cp -f "${src}" "${OFFSITE_LOCAL_DIR}/.${name}.partial" && mv -f "${OFFSITE_LOCAL_DIR}/.${name}.partial" "${OFFSITE_LOCAL_DIR}/${name}" ;;
        rclone) _offsite_rclone copyto "${src}" "$(_offsite_rclone_base)${name}" ;;
        s3)     _offsite_aws s3 cp --only-show-errors "${src}" "$(_offsite_s3_base)${name}" ;;
        sftp)   printf 'put "%s" "%s"\nrename "%s" "%s"\n' "${src}" "$(_offsite_sftp_dir)/.${name}.partial" "$(_offsite_sftp_dir)/.${name}.partial" "$(_offsite_sftp_dir)/${name}" | _offsite_sftp >/dev/null ;;
    esac
}

# offsite_list: one remote file name per line.
offsite_list() {
    case "${OFFSITE_METHOD}" in
        local)  [[ -d "${OFFSITE_LOCAL_DIR}" ]] && find "${OFFSITE_LOCAL_DIR}" -maxdepth 1 -type f -printf '%f\n' ;;
        rclone) _offsite_rclone lsf --files-only "$(_offsite_rclone_base)" ;;
        s3)     _offsite_aws s3 ls "$(_offsite_s3_base)" | awk '{print $4}' ;;
        sftp)   printf 'ls -1 "%s"\n' "$(_offsite_sftp_dir)" | _offsite_sftp | grep -v '^sftp>' | sed 's#.*/##' ;;
    esac
}

# offsite_size <name>: size in bytes of a remote file; empty if missing.
offsite_size() {
    local name="$1"
    case "${OFFSITE_METHOD}" in
        local)  stat -c '%s' "${OFFSITE_LOCAL_DIR}/${name}" 2>/dev/null || true ;;
        rclone) _offsite_rclone lsf --files-only --format s --include "${name}" "$(_offsite_rclone_base)" 2>/dev/null | head -n1 || true ;;
        s3)     _offsite_aws s3 ls "$(_offsite_s3_base)${name}" 2>/dev/null | awk '{print $3}' | head -n1 || true ;;
        sftp)   printf 'ls -l "%s"\n' "$(_offsite_sftp_dir)/${name}" | _offsite_sftp 2>/dev/null | grep -v '^sftp>' | awk '{print $5}' | head -n1 || true ;;
    esac
}

offsite_delete() {
    local name="$1"
    [[ "${name}" =~ ^backup-[A-Za-z0-9._-]+\.tar\.gz\.enc(\.sha256)?$ ]] || return 1   # never a path, never a wildcard
    case "${OFFSITE_METHOD}" in
        local)  rm -f -- "${OFFSITE_LOCAL_DIR}/${name}" ;;
        rclone) _offsite_rclone deletefile "$(_offsite_rclone_base)${name}" ;;
        s3)     _offsite_aws s3 rm --only-show-errors "$(_offsite_s3_base)${name}" ;;
        sftp)   printf 'rm "%s"\n' "$(_offsite_sftp_dir)/${name}" | _offsite_sftp >/dev/null ;;
    esac
}

# offsite_push <encrypted_file> <sha256_file>: push both, verify each by size, print one result line on stdout
# ("ok: <method> <name>" or "failed: <reason>"). Returns 0 only when both copies are verified.
offsite_push() {
    local enc="$1" sum="$2" f name want got
    for f in "${enc}" "${sum}"; do
        name="$(basename "${f}")"
        if ! offsite_put "${f}" >/dev/null 2>&1; then echo "failed: could not copy ${name} with ${OFFSITE_METHOD}"; return 1; fi
        want="$(stat -c '%s' "${f}")"; got="$(offsite_size "${name}" | tr -d '[:space:]')"
        if [[ "${got}" != "${want}" ]]; then echo "failed: ${name} is ${got:-missing} bytes at the destination, expected ${want}"; return 1; fi
    done
    echo "ok: ${OFFSITE_METHOD} $(basename "${enc}") + checksum"
}

# offsite_apply_retention <days>: delete off-site archives (and their checksum files) older than <days>. Prints the names removed.
# Never deletes the last remaining archive: if every archive is "expired" (clock skew, a long outage), it keeps the newest one.
offsite_apply_retention() {
    local days="$1" cutoff name total expired_archives newest
    [[ "${days}" =~ ^[0-9]+$ && "${days}" -gt 0 ]] || return 0
    cutoff=$(( $(date -u +%s) - days * 86400 ))
    local listing; listing="$(offsite_list || true)"
    total="$(grep -cE '^backup-.+\.tar\.gz\.enc$' <<< "${listing}" || true)"
    expired_archives="$(offsite_expired_names "${cutoff}" <<< "${listing}" | grep -cE '\.tar\.gz\.enc$' || true)"
    if [[ "${total:-0}" -gt 0 && "${expired_archives:-0}" -ge "${total}" ]]; then
        newest="$(grep -E '^backup-.+\.tar\.gz\.enc$' <<< "${listing}" | sort | tail -n1)"
        warn "offsite retention: every archive is older than ${days} days; keeping the newest (${newest})"
        listing="$(grep -v -F "${newest}" <<< "${listing}" || true)"
    fi
    while IFS= read -r name; do
        [[ -n "${name}" ]] || continue
        if offsite_delete "${name}"; then printf '%s\n' "${name}"; fi
    done < <(offsite_expired_names "${cutoff}" <<< "${listing}")
    return 0
}
