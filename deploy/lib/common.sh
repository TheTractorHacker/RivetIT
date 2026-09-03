#!/usr/bin/env bash
# ITFlow-Internal-IT deployment tooling — shared helper library.
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
# `mysql --defaults-extra-file`) should register it here with
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
        shred -u "${f}" 2>/dev/null || rm -f "${f}"
    done
}
trap _cleanup_tmpfiles EXIT
