#!/usr/bin/env bash
# RivetIT deployment tooling — shared helper library.
#
# Sourced (never executed directly) by deploy/install.sh and by the other
# deploy/*.sh scripts in this directory (e.g. deploy/harden.sh,
# deploy/update.sh). Function names/signatures here are a shared interface
# across those scripts — keep them stable and generic rather than tailoring
# them to install.sh's needs specifically.
#
# Usage from a caller:
#   SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#   # shellcheck source=./lib/common.sh
#   source "${SCRIPT_DIR}/lib/common.sh"

set -euo pipefail

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
# Colored only when stderr is an actual terminal that claims 8+ colors —
# never emit raw escape codes into a redirected log file (e.g. install.sh's
# `exec > >(tee -a /var/log/itflow-install.log)`), where they'd just show up
# as literal "\033[..." noise.
if [[ -t 2 ]] && command -v tput >/dev/null 2>&1 && [[ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]]; then
    C_RED=$'\033[0;31m'
    C_GREEN=$'\033[0;32m'
    C_YELLOW=$'\033[0;33m'
    C_BLUE=$'\033[0;34m'
    C_BOLD=$'\033[1m'
    C_RESET=$'\033[0m'
else
    C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_RESET=""
fi

_ts() { date '+%Y-%m-%d %H:%M:%S'; }

# log(): plain, uncolored step marker. Used for routine narration that other
# tooling (e.g. a CI log scraper) might grep for without ANSI codes in the way.
log() {
    printf '%s [%s]\n' "$(_ts)" "$*"
}

# info()/warn()/error(): colored, leveled output. warn/error go to stderr so
# a caller can separate them from stdout even when both are also being
# tee'd to the same logfile.
info() {
    printf '%s %s[INFO]%s %s\n' "$(_ts)" "${C_BLUE}" "${C_RESET}" "$*"
}

success() {
    printf '%s %s[ OK ]%s %s\n' "$(_ts)" "${C_GREEN}" "${C_RESET}" "$*"
}

warn() {
    printf '%s %s[WARN]%s %s\n' "$(_ts)" "${C_YELLOW}" "${C_RESET}" "$*" >&2
}

error() {
    printf '%s %s[FAIL]%s %s\n' "$(_ts)" "${C_RED}" "${C_RESET}" "$*" >&2
}

# die(): print an error and exit non-zero. The one and only way scripts
# using this library should abort on a fatal condition.
die() {
    error "$*"
    exit 1
}

# announce(): for invasive/hard-to-reverse steps (service restarts, ufw
# enable, cert issuance, destructive file writes). Prints what is ABOUT to
# happen, distinctly from routine info(), before the caller does it.
announce() {
    printf '%s %s%s[ACTION]%s %s\n' "$(_ts)" "${C_BOLD}" "${C_YELLOW}" "${C_RESET}" "$*"
}

# ---------------------------------------------------------------------------
# Environment / privilege checks
# ---------------------------------------------------------------------------

# require_root(): dies with a clear, actionable message if not running as
# EUID 0. Every script in deploy/ that touches system packages, /etc, or
# service state should call this before doing anything else.
require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        die "This script must be run as root. Re-run with: sudo $0 $*"
    fi
}

# detect_os(): dies cleanly on anything that isn't a Debian/Ubuntu apt-based
# system — that's the only target this tooling supports in v1. On success,
# exports OS_ID / OS_VERSION_ID / OS_CODENAME / OS_PRETTY_NAME for callers
# that want to branch on specifics (e.g. Ubuntu 24.04 "noble").
detect_os() {
    if [[ ! -r /etc/os-release ]]; then
        die "Cannot detect the operating system (/etc/os-release missing). This installer only supports Debian/Ubuntu (apt-based) systems."
    fi

    # shellcheck disable=SC1091
    . /etc/os-release

    local id="${ID:-}"
    local id_like="${ID_LIKE:-}"
    if [[ "${id}" != "ubuntu" && "${id}" != "debian" && "${id_like}" != *debian* && "${id_like}" != *ubuntu* ]]; then
        die "Unsupported OS '${PRETTY_NAME:-$id}'. This installer only supports Debian/Ubuntu (apt-based) systems."
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        die "apt-get not found. This installer requires an apt-based system."
    fi

    OS_ID="${id}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_CODENAME="${VERSION_CODENAME:-}"
    OS_PRETTY_NAME="${PRETTY_NAME:-$id}"
    export OS_ID OS_VERSION_ID OS_CODENAME OS_PRETTY_NAME

    info "Detected OS: ${OS_PRETTY_NAME}"
}

# ---------------------------------------------------------------------------
# Secrets
# ---------------------------------------------------------------------------

# gen_secret([length]): a random secret safe to drop into shell command
# lines, my.cnf/ini files, or URLs without quoting gymnastics — alphanumeric
# only, so '/', '+', '=' (base64's non-alnum characters) can never land in
# it. Loops rather than a single truncated call so the result always has the
# requested length even after stripping. Default length is 32.
gen_secret() {
    local length="${1:-32}"
    local secret=""
    while [[ "${#secret}" -lt "${length}" ]]; do
        secret+="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9')"
    done
    printf '%s' "${secret:0:${length}}"
}

# ---------------------------------------------------------------------------
# Package / service state
# ---------------------------------------------------------------------------

# package_installed(name): true if a .deb package is installed (any state
# short of fully-installed, e.g. "half-configured", counts as NOT installed
# so callers re-attempt installation rather than trusting a broken package).
package_installed() {
    local status
    status="$(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true)"
    [[ "${status}" == "install ok installed" ]]
}

# service_is_active(name): true if a systemd unit is currently active.
service_is_active() {
    systemctl is-active --quiet "$1" 2>/dev/null
}

# command_exists(name): thin wrapper kept for readability at call sites.
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# Application config (config.php)
# ---------------------------------------------------------------------------

# read_app_config(app_dir): populates DB_HOST/DB_USER/DB_PASS/DB_NAME/
# INSTALLATION_ID/SETTINGS_ENC_KEY by shelling out to `php -r` with a small
# trusted snippet that requires app_dir/config.php and echoes the six values
# it defines. Shared by backup.sh (to capture INSTALLATION_ID/
# SETTINGS_ENC_KEY into its backup manifest) and restore.sh (to find the
# target database). Deliberately NOT parsed out of the file with grep/sed
# (config.php's values go through var_export(), so they can contain escaped
# quotes, unicode, etc. — a text-munging parse would be fragile) and
# deliberately NOT eval'd as arbitrary PHP from an untrusted source —
# config.php is a trusted local file this same install already wrote (or
# that scripts/setup_cli.php --config-only just wrote), so requiring it here
# carries no more risk than the app's own every-request bootstrap already
# does.
read_app_config() {
    local app_dir="$1"
    info "Reading configuration from ${app_dir}/config.php..."
    local raw
    if ! raw="$(php -r '
        require $argv[1];
        echo $dbhost . "\n" . $dbusername . "\n" . $dbpassword . "\n" . $database . "\n";
        echo ($installation_id ?? "") . "\n";
        echo ($config_settings_enc_key ?? "") . "\n";
    ' -- "${app_dir}/config.php")"; then
        die "Failed to read configuration from ${app_dir}/config.php via 'php -r' (see PHP's error output above). config.php connects to MySQL as a side effect of being require()'d — this usually means the database is unreachable, not just a bad config file."
    fi

    local -a cfg_lines
    mapfile -t cfg_lines <<< "${raw}"
    DB_HOST="${cfg_lines[0]:-}"
    DB_USER="${cfg_lines[1]:-}"
    DB_PASS="${cfg_lines[2]:-}"
    DB_NAME="${cfg_lines[3]:-}"
    INSTALLATION_ID="${cfg_lines[4]:-}"
    SETTINGS_ENC_KEY="${cfg_lines[5]:-}"

    if [[ -z "${DB_HOST}" || -z "${DB_USER}" || -z "${DB_NAME}" ]]; then
        die "config.php did not yield usable database settings (host='${DB_HOST}' user='${DB_USER}' database='${DB_NAME}'). Refusing to proceed with an incomplete target."
    fi
}

# ---------------------------------------------------------------------------
# Backup manifest (backup-manifest.json[.enc])
# ---------------------------------------------------------------------------
# Shared by restore.sh (deploy/backup.sh archives — manifest always plaintext,
# since the whole archive around it is already openssl-encrypted) and
# restore_admin_zip.sh (admin/post/backup.php zips — the manifest ITSELF may
# be openssl-encrypted with an admin-set config_backup_passphrase, same
# aes-256-cbc/-pbkdf2/-salt scheme, see build_backup_manifest() in
# admin/post/backup.php). One reader handles both shapes so both restore
# tools recover settings_enc_key the same way instead of drifting apart.

MANIFEST_SETTINGS_ENC_KEY=""
MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT=""
# Optional: file holding the original config_settings_enc_key (written by backup.sh next to each archive as
# <archive>.settings-key). Set by restore.sh / restore_admin_zip.sh from --settings-key-file or the archive's sidecar.
SETTINGS_KEY_FILE=""
MANIFEST_INSTALLATION_ID=""
MANIFEST_DB_NAME=""
MANIFEST_BACKUP_TIMESTAMP=""

# read_manifest_dir(dir, [passphrase_file]): looks for backup-manifest.json
# (plaintext) then backup-manifest.json.enc (openssl-encrypted) directly
# inside `dir`, and populates MANIFEST_SETTINGS_ENC_KEY/MANIFEST_INSTALLATION_ID/
# MANIFEST_DB_NAME/MANIFEST_BACKUP_TIMESTAMP from whichever it finds (always
# reset to empty first, so a caller can tell "found nothing" from a stale
# previous call).
#
# Three cases:
#   - No manifest file at all (a backup taken before this feature existed):
#     WARN and return 0 — the restore itself (data, uploads) is still fully
#     valid without it, only encrypted-setting columns are affected.
#   - Plaintext backup-manifest.json: read and parse it, no passphrase needed.
#   - Encrypted backup-manifest.json.enc: `passphrase_file` is REQUIRED to
#     decrypt it. Unlike the "no manifest" case above, this is "a manifest
#     exists but can't be read" — die() rather than silently degrading, the
#     same "never silently treat explicit encryption as if it weren't there"
#     rule build_backup_manifest() itself follows when writing one. A caller
#     that genuinely has no passphrase for this backup must supply the
#     correct one before a restore can recover its settings_enc_key.
read_manifest_dir() {
    local dir="$1" passphrase_file="${2:-}"
    MANIFEST_SETTINGS_ENC_KEY=""
    MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT=""
    MANIFEST_INSTALLATION_ID=""
    MANIFEST_DB_NAME=""
    MANIFEST_BACKUP_TIMESTAMP=""

    local plain="${dir}/backup-manifest.json"
    local enc="${dir}/backup-manifest.json.enc"
    local manifest_file=""

    if [[ -f "${plain}" ]]; then
        manifest_file="${plain}"
    elif [[ -f "${enc}" ]]; then
        [[ -n "${passphrase_file}" ]] || die "This backup's manifest (${enc}) is encrypted, but no --passphrase-file was given. Supply the passphrase that was set in Admin > Backup when this backup was taken."
        [[ -f "${passphrase_file}" ]] || die "--passphrase-file '${passphrase_file}' does not exist."
        local tmp_decrypted
        tmp_decrypted="$(mktemp)"
        chmod 600 "${tmp_decrypted}"
        register_tmpfile "${tmp_decrypted}"
        if ! openssl enc -d -aes-256-cbc -pbkdf2 -salt -in "${enc}" -out "${tmp_decrypted}" -pass file:"${passphrase_file}" 2>/dev/null; then
            die "Failed to decrypt ${enc} with --passphrase-file. Wrong passphrase, or the file is corrupt."
        fi
        manifest_file="${tmp_decrypted}"
    else
        warn "No backup-manifest.json or backup-manifest.json.enc found in ${dir} — this backup predates manifest capture. The target's config_settings_enc_key will be left as-is, which will NOT match what encrypted this backup's SMTP/IMAP passwords, RMM/webhook secrets, and wrapped vault master key — those will need to be re-entered manually after this restore completes."
        return 0
    fi

    local raw
    if ! raw="$(php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($data)) { fwrite(STDERR, "not a JSON object\n"); exit(1); }
        echo ($data["settings_enc_key"] ?? "") . "\n";
        echo ($data["installation_id"] ?? "") . "\n";
        echo ($data["db_name"] ?? "") . "\n";
        echo ($data["backup_timestamp"] ?? "") . "\n";
        echo ($data["settings_enc_key_fingerprint"] ?? "") . "\n";
    ' -- "${manifest_file}")"; then
        warn "Found ${manifest_file##*/} but failed to parse it as JSON; proceeding as if it were absent (secrets will need to be re-entered manually — see the warning above)."
        return 0
    fi

    local -a m_lines
    mapfile -t m_lines <<< "${raw}"
    MANIFEST_SETTINGS_ENC_KEY="${m_lines[0]:-}"
    MANIFEST_INSTALLATION_ID="${m_lines[1]:-}"
    MANIFEST_DB_NAME="${m_lines[2]:-}"
    MANIFEST_BACKUP_TIMESTAMP="${m_lines[3]:-}"
    MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT="${m_lines[4]:-}"

    if [[ -z "${MANIFEST_SETTINGS_ENC_KEY}" ]]; then
        # Manifests written since the key moved out of the archive hold a fingerprint only. The operator supplies the key.
        if [[ -n "${SETTINGS_KEY_FILE}" ]]; then
            load_settings_key_file "${SETTINGS_KEY_FILE}" "${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}"
            return 0
        fi
        if [[ -n "${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}" ]]; then
            warn "${manifest_file##*/} does not contain the settings-encryption key (key fingerprint ${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}). Supply it with --settings-key-file=<path> (the <archive>.settings-key file backup.sh wrote, or a file holding the original config.php's \$config_settings_enc_key). Without it SMTP/IMAP passwords, RMM/webhook secrets and the credential vault will not decrypt after this restore unless this config.php already has that key."
        else
            warn "${manifest_file##*/} is present but has no usable settings_enc_key; proceeding as if it were absent."
        fi
        return 0
    fi
    success "Found this backup's settings-encryption key in its manifest (installation_id=${MANIFEST_INSTALLATION_ID:-N/A}, taken ${MANIFEST_BACKUP_TIMESTAMP:-N/A}) — will apply it to config.php after import."
}

# load_settings_key_file(path, [fingerprint]): reads the original config_settings_enc_key from `path`
# (the last line that is not a # comment; backup.sh writes <archive>.settings-key this way) into
# MANIFEST_SETTINGS_ENC_KEY. When the manifest gave a fingerprint, the key must match it or this dies:
# applying the wrong key would make every stored secret unreadable.
load_settings_key_file() {
    local path="$1" want_fp="${2:-}"
    [[ -f "${path}" ]] || die "--settings-key-file '${path}' does not exist."
    local perm
    perm="$(stat -c '%a' "${path}")"
    if [[ "${perm}" =~ [0-7][0-7][1-7]$ || "${perm}" =~ [0-7][1-7][0-7]$ ]]; then
        warn "${path} has permissions ${perm}; it holds a secret and should be 600."
    fi
    local key
    key="$(grep -v '^[[:space:]]*#' "${path}" | grep -v '^[[:space:]]*$' | tail -n 1 | tr -d '[:space:]')"
    [[ "${key}" =~ ^[0-9a-fA-F]{32,128}$ ]] || die "${path} does not contain a hex settings-encryption key."
    if [[ -n "${want_fp}" ]]; then
        local got_fp
        got_fp="$(php -r 'echo substr(hash("sha256", "rivetit-settings-key-fingerprint|v1|" . $argv[1]), 0, 16);' -- "${key}")"
        [[ "${got_fp}" == "${want_fp}" ]] || die "The key in ${path} (fingerprint ${got_fp}) does not match this backup (fingerprint ${want_fp}). Refusing to apply it."
    fi
    MANIFEST_SETTINGS_ENC_KEY="${key}"
    success "Settings-encryption key read from ${path}$( [[ -n "${want_fp}" ]] && printf ' (fingerprint matches the backup)' ) - will apply it to config.php after import."
}

# apply_settings_enc_key(config_file, key): rewrites config.php's
# $config_settings_enc_key line in place to `key` (typically
# MANIFEST_SETTINGS_ENC_KEY from read_manifest_dir above), so every secret
# column the just-imported dump contains keeps decrypting correctly under
# the instance's new config.php. A no-op when `key` is empty (the caller's
# manifest read found nothing — the warning was already printed there).
# setup_cli.php always emits this exact `$config_settings_enc_key = '...';`
# line (single-quoted hex string, no embedded quotes/backslashes possible —
# it's bin2hex output) whether config.php was just generated fresh by
# --config-only or is this instance's original one, so a plain sed
# substitution is safe here without needing config.php's own PHP parser.
apply_settings_enc_key() {
    local config_file="$1" key="$2"
    if [[ -z "${key}" ]]; then
        return 0
    fi

    if ! grep -q '^\$config_settings_enc_key = ' "${config_file}"; then
        warn "${config_file} has no \$config_settings_enc_key line to replace; leaving it untouched. This instance's config.php may predate that setting — investigate before trusting restored SMTP/IMAP/RMM/webhook secrets."
        return 0
    fi

    info "Applying the backup's settings-encryption key to ${config_file}..."
    sed -i "s/^\\\$config_settings_enc_key = '.*';\$/\\\$config_settings_enc_key = '${key}';/" "${config_file}"
    success "config_settings_enc_key restored from the backup's manifest — SMTP/IMAP passwords, RMM/webhook secrets, and the wrapped vault master key should now decrypt normally."
}

# ---------------------------------------------------------------------------
# Misc
# ---------------------------------------------------------------------------

# backup_if_exists(path): if `path` already exists, copy it aside to
# path.bak-<timestamp> before a caller overwrites it. Keeps re-running an
# install/update idempotent-but-non-destructive of local edits, per this
# tooling's general rule of never silently clobbering an existing file.
backup_if_exists() {
    local target="$1"
    if [[ -e "${target}" ]]; then
        local backup
        backup="${target}.bak-$(date +%Y%m%d%H%M%S)"
        cp -a "${target}" "${backup}"
        warn "Existing file backed up: ${target} -> ${backup}"
    fi
}

# detect_ssh_port(): best-effort read of the configured sshd port from
# /etc/ssh/sshd_config AND /etc/ssh/sshd_config.d/*.conf (Ubuntu 24.04's
# default layout drops a machine-generated port override into the latter,
# not the former — checking only sshd_config misses it), falling back to
# the IANA default (22) when no directive is found or nothing is readable.
# Used before any `ufw enable` so the firewall never locks out the very
# session running the installer.
detect_ssh_port() {
    local port=""
    port="$( { [[ -f /etc/ssh/sshd_config ]] && grep -E '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config;
               compgen -G '/etc/ssh/sshd_config.d/*.conf' >/dev/null && grep -hE '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config.d/*.conf; } \
             2>/dev/null | awk '{print $2; exit}' || true )"
    printf '%s' "${port:-22}"
}

# ---------------------------------------------------------------------------
# Temp-file cleanup (secrets)
# ---------------------------------------------------------------------------
# Any script that writes a secret to a temp file (a generated DB password, a
# `mysql --defaults-extra-file`) OR extracts one into a temp directory (e.g.
# a decrypted backup archive) should register it here with
# register_tmpfile() rather than rolling its own trap. This is the ONE EXIT
# trap for the whole process — bash keeps only a single handler per signal,
# so a script that later called `trap ... EXIT` again would silently replace
# this one and skip secret cleanup. Add cleanup work by calling
# register_tmpfile(), never by setting a second EXIT trap.
declare -a _ITFLOW_TMPFILES=()

register_tmpfile() {
    _ITFLOW_TMPFILES+=("$1")
}

_cleanup_tmpfiles() {
    local f
    for f in "${_ITFLOW_TMPFILES[@]:-}"; do
        [[ -n "${f}" && -e "${f}" ]] || continue
        if [[ -d "${f}" ]]; then
            # A registered directory (e.g. restore.sh's decrypted-archive
            # extraction dir) may hold sensitive files of its own — shred
            # each one individually before removing the tree, same intent
            # as the plain-file branch below, just recursive.
            find "${f}" -type f -exec shred -u {} + 2>/dev/null
            rm -rf "${f}"
        else
            shred -u "${f}" 2>/dev/null || rm -f "${f}"
        fi
    done
}
# ignore_git_filemode APP_DIR [OWNER]: install.sh's set_file_permissions rewrites every file to 640/750, which git sees as
# mode changes on the tracked executables (cron/*.php, scripts/*.php, deploy/*.sh). A later `git pull` that touches any of
# them aborts with "Your local changes would be overwritten", leaving the instance un-updatable. Mode bits are not content,
# so tell this checkout to ignore them. Idempotent, never fatal.
ignore_git_filemode() {
    local app="${1:?app dir}" owner="${2:-}"
    [[ -d "${app}/.git" ]] || return 0
    local -a g=(git -C "${app}")
    [[ -n "${owner}" ]] && g=(sudo -u "${owner}" git -C "${app}")
    [[ "$("${g[@]}" config --get core.fileMode 2>/dev/null || true)" == "false" ]] && return 0
    "${g[@]}" config core.fileMode false || warn "Could not set core.fileMode=false in ${app}; a later update may be blocked by file-mode differences."
}

trap _cleanup_tmpfiles EXIT

# ---------------------------------------------------------------------------
# RivetIT's own Redis instance
# ---------------------------------------------------------------------------
# ensure_rivetit_redis <templates_dir> [port]
#
# The app expects Redis on 127.0.0.1:6380 (includes/redis_functions.php), not the distribution's default 6379,
# which another service may already use. This installs a dedicated, loopback-only, non-persistent instance as the
# systemd unit rivetit-redis and starts it. Idempotent and never fatal: Redis is optional (every feature fails
# open), so any problem is a warning and the caller carries on. The config file is written once and never
# overwritten, so later edits and Administration > Redis "memory limit" saves survive re-runs.
ensure_rivetit_redis() {
    local template_dir="${1:?templates dir}" port="${2:-6380}"
    local conf_dir=/etc/redis-rivetit unit=/etc/systemd/system/rivetit-redis.service

    if ! command_exists redis-server; then
        warn "redis-server is not installed; skipping RivetIT Redis (live ticket/chat push stays off; the app runs without it)."
        return 0
    fi
    if [[ ! -d /run/systemd/system ]] || ! command_exists systemctl; then
        warn "systemd is not running here; skipping the rivetit-redis service. Start Redis yourself on 127.0.0.1:${port}."
        return 0
    fi
    if ! getent passwd redis >/dev/null 2>&1; then
        warn "No 'redis' system user (the redis-server package normally creates it); skipping RivetIT Redis."
        return 0
    fi
    if [[ ! -f "${template_dir}/rivetit-redis.service" || ! -f "${template_dir}/rivetit-redis.conf" ]]; then
        warn "Redis templates not found in ${template_dir}; skipping RivetIT Redis."
        return 0
    fi

    if ! service_is_active rivetit-redis && command_exists ss && ss -ltn "sport = :${port}" 2>/dev/null | grep -q LISTEN; then
        warn "Something other than rivetit-redis is already listening on port ${port}; leaving it alone. Point RivetIT at it in Administration > Redis if that is intended."
        return 0
    fi

    install -d -o redis -g redis -m 0750 "$conf_dir" || { warn "Could not create ${conf_dir}; skipping RivetIT Redis."; return 0; }
    if [[ ! -f "${conf_dir}/redis.conf" ]]; then
        install -o redis -g redis -m 0640 "${template_dir}/rivetit-redis.conf" "${conf_dir}/redis.conf" \
            || { warn "Could not write ${conf_dir}/redis.conf; skipping RivetIT Redis."; return 0; }
        sed -i "s/^port .*/port ${port}/" "${conf_dir}/redis.conf"
    fi
    if ! cmp -s "${template_dir}/rivetit-redis.service" "$unit"; then
        install -o root -g root -m 0644 "${template_dir}/rivetit-redis.service" "$unit" \
            || { warn "Could not install ${unit}; skipping RivetIT Redis."; return 0; }
        systemctl daemon-reload || true
    fi

    systemctl enable --now rivetit-redis >/dev/null 2>&1 || { warn "Could not start rivetit-redis. See: journalctl -u rivetit-redis"; return 0; }

    for _ in 1 2 3 4 5 6 7 8 9 10; do
        if command_exists redis-cli && [[ "$(redis-cli -h 127.0.0.1 -p "$port" ping 2>/dev/null)" == "PONG" ]]; then
            success "RivetIT Redis is running on 127.0.0.1:${port}."
            return 0
        fi
        sleep 0.5
    done
    warn "rivetit-redis started but did not answer on 127.0.0.1:${port}. See: journalctl -u rivetit-redis"
    return 0
}
