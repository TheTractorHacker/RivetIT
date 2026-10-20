#!/usr/bin/env bash
# RivetIT "RMM Agent Builder": sets up THIS server to build the Windows agent (.exe and .msi) from Administration > Agent updates > Build on my build host.
# Idempotent: run it again at any time (after an upgrade, after a failed run) and it converges to the same state. Needs root.
#
#   sudo deploy/setup-build-host.sh                 install (asks one question about Docker)
#   sudo deploy/setup-build-host.sh --yes           install without asking
#   sudo deploy/setup-build-host.sh --check         change nothing: show what would be done (the three pinned files are still downloaded and verified)
#   sudo deploy/setup-build-host.sh --uninstall     stop and remove it (--purge-docker also removes the docker.io package)
#
# What it does (the same steps that were done by hand on 2026-10-19):
#   1. installs docker.io (and git, curl, python3 if missing) when they are not there
#   2. creates the system user rivetit-build (home /var/lib/rivetit-build-host, no login shell, member of the docker group). www-data is NEVER added to docker.
#   3. downloads build-host.sh, build-host.service and build-host.timer from the RivetCore repository at a PINNED commit and refuses to continue unless each file
#      matches its pinned sha256 (the vendored RivetCore package does not ship endpoint-agent/); installs them root-owned
#   4. makes the build host and its token through the app's own code (scripts/build_host_setup.php, as www-data); the token goes straight into
#      /etc/rivetit/build-host.conf (mode 600, owner rivetit-build) and is never printed, logged or passed on a command line
#   5. enables build-host.timer, runs build-host.sh --register as the build user and prints a clear success or failure summary
#
# SECURITY: membership of the docker group is root-equivalent on this machine (anyone who can run docker can mount / and read or change anything).
# Here that is confined to the one account rivetit-build, which has no password, no shell and runs only the pinned build-host.sh. Do not add other accounts to docker.
#
# Options: --app-dir=DIR  --site-url=URL (default: the base URL saved in the app)  --name=NAME  --core-repo=URL  --allowed-refs="main v* agent-v*"
#          --web-user=USER (default www-data)  --rotate-token  --allow-http (dev only: lets SERVER_URL be http://)  --no-register  --yes  -h|--help
# Test hook: RIVETIT_BH_SOURCE_DIR=DIR reads the three files from DIR instead of GitHub. The sha256 check still applies, so a wrong file is refused.
set -Eeuo pipefail
set +x   # the token passes through shell variables: never trace
umask 022

# ---- the pin: bump all four lines together when RivetCore's build host script changes (and re-check the sums against the commit) --------------------------------------
CORE_COMMIT="26d476ca8a429d4aafb9d962d99f369635a2f9a9"
SHA_BUILD_HOST_SH="8296f46347217f00c810158ae43a353a2b331e4ec75ba466b988c417f5871714"
SHA_BUILD_HOST_SERVICE="4d73f42d88876003ab6804b0f3ebffe6e8fb85c7e91bfd9734706eaf64b1a703"
SHA_BUILD_HOST_TIMER="6cba55037c010ffba258913b265033791c786e6fcf9880805313a3b28b7cd5ba"
RAW_BASE="https://raw.githubusercontent.com/TheTractorHacker/rivet-core/${CORE_COMMIT}/endpoint-agent/scripts"
# ----------------------------------------------------------------------------------------------------------------------------------------------------------------------

BUILD_USER="rivetit-build"
BUILD_HOME="/var/lib/rivetit-build-host"
OPT_DIR="/opt/rivetit-build-host"
CONF_DIR="/etc/rivetit"
CONF="${CONF_DIR}/build-host.conf"
UNIT_DIR="/etc/systemd/system"
TOKEN_RE='^rbh_[0-9a-f]{16}_[0-9a-f]{48}$'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SITE_URL="" HOST_NAME="" CORE_REPO="https://github.com/TheTractorHacker/rivet-core.git" ALLOWED_REFS="main v* agent-v*"
WEB_USER="www-data" CHECK=0 UNINSTALL=0 PURGE_DOCKER=0 ROTATE=0 ALLOW_HTTP=0 NO_REGISTER=0 ASSUME_YES=0
TMP="" FAILED=0
SUMMARY=()

say() { printf '%s\n' "$*"; }
info() { printf '[build-host] %s\n' "$*"; }
warn() { printf '[build-host] WARNING: %s\n' "$*" >&2; }
die() { printf '[build-host] ERROR: %s\n' "$*" >&2; exit 1; }
note() { SUMMARY+=("$*"); }

usage() { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; }

cleanup() { if [ -n "${TMP}" ] && [ -d "${TMP}" ]; then rm -rf -- "${TMP}"; fi; return 0; }
trap cleanup EXIT

for arg in "$@"; do
  case "${arg}" in
    --app-dir=*) APP_DIR="${arg#*=}" ;;
    --site-url=*) SITE_URL="${arg#*=}" ;;
    --name=*) HOST_NAME="${arg#*=}" ;;
    --core-repo=*) CORE_REPO="${arg#*=}" ;;
    --allowed-refs=*) ALLOWED_REFS="${arg#*=}" ;;
    --web-user=*) WEB_USER="${arg#*=}" ;;
    --check|--dry-run) CHECK=1 ;;
    --uninstall) UNINSTALL=1 ;;
    --purge-docker) PURGE_DOCKER=1 ;;
    --rotate-token) ROTATE=1 ;;
    --allow-http) ALLOW_HTTP=1 ;;
    --no-register) NO_REGISTER=1 ;;
    --yes|-y|--non-interactive) ASSUME_YES=1 ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "unknown option: ${arg}" ;;
  esac
done

# ---- validation of everything that ends up in a file or a command -----------------------------------------------------------------------------------------------------
[[ "${WEB_USER}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "--web-user must be a plain user name"
[[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path"
[[ "${CORE_REPO}" =~ ^https://[A-Za-z0-9._/-]+\.git$ ]] || die "--core-repo must be an https:// URL ending in .git (letters, digits, . _ / -)"
[[ "${ALLOWED_REFS}" =~ ^[A-Za-z0-9._/*\ -]{1,200}$ ]] || die "--allowed-refs may only hold letters, digits, . _ / - * and spaces"
if [ -z "${HOST_NAME}" ]; then
  HOST_NAME="Local build host ($(hostname -s 2>/dev/null | tr -cd 'A-Za-z0-9._-' | cut -c1-40))"
fi
[[ "${HOST_NAME}" =~ ^[A-Za-z0-9._:@()/+\ -]{1,80}$ ]] || die "--name must be 1-80 characters: letters, digits, spaces and . - _ : @ ( ) / +"
if [ -n "${SITE_URL}" ]; then
  [[ "${SITE_URL}" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._/-]*)?$ ]] || die "--site-url must look like https://rivetit.example.com (no credentials, no spaces)"
fi

[ "$(id -u)" -eq 0 ] || die "this must run as root, because it installs packages, creates a system user and writes /etc and /opt. Run: sudo $0 ${*:-}"

CHECK_NOTE=""
[ "${CHECK}" -eq 1 ] && CHECK_NOTE=" (check only, nothing changed)"

# In --check mode nothing is changed: every state-changing step goes through run().
run() { if [ "${CHECK}" -eq 1 ]; then info "would run: $*"; else "$@"; fi; }
as_web() { if command -v runuser >/dev/null 2>&1; then runuser -u "${WEB_USER}" -- "$@"; else sudo -u "${WEB_USER}" "$@"; fi; }
as_build() { if command -v runuser >/dev/null 2>&1; then runuser -u "${BUILD_USER}" -- "$@"; else sudo -u "${BUILD_USER}" "$@"; fi; }
have_user() { getent passwd "${BUILD_USER}" >/dev/null 2>&1; }
in_group() { id -nG "$1" 2>/dev/null | tr ' ' '\n' | grep -qx "$2"; }
setup_cli="${APP_DIR}/scripts/build_host_setup.php"

# ---- uninstall ---------------------------------------------------------------------------------------------------------------------------------------------------------
if [ "${UNINSTALL}" -eq 1 ]; then
  info "Removing the RMM Agent Builder from this server."
  if [ "${CHECK}" -eq 0 ] && [ "${ASSUME_YES}" -eq 0 ]; then
    read -r -p "Stop the build timer and remove the build user, script, units and configuration? [y/N] " a || a=""
    case "${a}" in [yY]*) ;; *) die "cancelled" ;; esac
  fi
  if command -v systemctl >/dev/null 2>&1; then
    run systemctl disable --now build-host.timer || true
    run systemctl stop build-host.service || true
  fi
  if [ -f "${setup_cli}" ]; then
    if [ "${CHECK}" -eq 1 ]; then
      info "would disable the build host row \"${HOST_NAME}\" in the app"
    elif as_web php "${setup_cli}" disable "--name=${HOST_NAME}" >/dev/null 2>&1; then
      note "build host row disabled in the app"
    else
      note "build host row NOT disabled (not found, or the app could not be reached): disable it on Agent updates if it still shows"
    fi
  fi
  run rm -f -- "${UNIT_DIR}/build-host.service" "${UNIT_DIR}/build-host.timer" "${CONF}"
  run rm -rf -- "${OPT_DIR}"
  if command -v systemctl >/dev/null 2>&1; then run systemctl daemon-reload || true; fi
  if have_user; then run userdel -r "${BUILD_USER}" || run userdel "${BUILD_USER}" || true; fi
  note "removed: timer, units, ${OPT_DIR}, ${CONF}, user ${BUILD_USER} with its home"
  if [ "${PURGE_DOCKER}" -eq 1 ]; then
    run env DEBIAN_FRONTEND=noninteractive apt-get purge -y docker.io
    note "docker.io purged (images and containers under /var/lib/docker were not deleted)"
  else
    note "Docker was left installed (add --purge-docker to remove the docker.io package)"
  fi
  say ""
  say "RMM Agent Builder removed${CHECK_NOTE}."
  printf ' - %s\n' "${SUMMARY[@]}"
  exit 0
fi

# ---- the warning, once -------------------------------------------------------------------------------------------------------------------------------------------------
say "RMM Agent Builder setup${CHECK_NOTE}."
say "Note: membership of the \"docker\" group is root-equivalent on this machine. It is given to ONE account, ${BUILD_USER} (no password, no shell, runs only the"
say "pinned build-host.sh). ${WEB_USER} is never added to docker."
if [ "${CHECK}" -eq 0 ] && [ "${ASSUME_YES}" -eq 0 ]; then
  read -r -p "Continue? [y/N] " a || a=""
  case "${a}" in [yY]*) ;; *) die "cancelled" ;; esac
fi

# ---- 1. packages -------------------------------------------------------------------------------------------------------------------------------------------------------
need_pkgs=()
command -v docker >/dev/null 2>&1 || need_pkgs+=(docker.io)
command -v git >/dev/null 2>&1 || need_pkgs+=(git)
command -v curl >/dev/null 2>&1 || need_pkgs+=(curl)
command -v python3 >/dev/null 2>&1 || need_pkgs+=(python3)
command -v flock >/dev/null 2>&1 || need_pkgs+=(util-linux)
command -v sha256sum >/dev/null 2>&1 || need_pkgs+=(coreutils)
if [ "${#need_pkgs[@]}" -gt 0 ]; then
  command -v apt-get >/dev/null 2>&1 || die "missing: ${need_pkgs[*]}, and apt-get is not available. Install them with your package manager and run this again."
  info "Installing: ${need_pkgs[*]}"
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${need_pkgs[@]}"
  note "installed packages: ${need_pkgs[*]}"
else
  info "Docker, git, curl and python3 are already installed."
  note "packages already present (docker, git, curl, python3)"
fi
if command -v systemctl >/dev/null 2>&1; then run systemctl enable --now docker || warn "could not enable docker.service (is systemd running?)"; fi

# ---- 2. user -----------------------------------------------------------------------------------------------------------------------------------------------------------
if ! getent group docker >/dev/null 2>&1 && [ "${CHECK}" -eq 0 ]; then
  die "there is no \"docker\" group: the docker.io package did not install properly"
fi
if have_user; then
  info "User ${BUILD_USER} exists."
else
  run useradd --system --user-group --home-dir "${BUILD_HOME}" --create-home --shell /usr/sbin/nologin --comment "RivetIT agent build host" "${BUILD_USER}"
  note "created system user ${BUILD_USER}"
fi
if ! in_group "${BUILD_USER}" docker; then run usermod -aG docker "${BUILD_USER}"; fi
run install -d -o "${BUILD_USER}" -g "${BUILD_USER}" -m 0750 "${BUILD_HOME}"
if in_group "${WEB_USER}" docker; then
  warn "${WEB_USER} is in the docker group (this script did not do that). That gives the web application root on this machine. Remove it: gpasswd -d ${WEB_USER} docker"
fi

# ---- 3. the pinned files -----------------------------------------------------------------------------------------------------------------------------------------------
TMP="$(mktemp -d)"
fetch_verify() { # name sha256
  local name="$1" want="$2" got
  if [ -n "${RIVETIT_BH_SOURCE_DIR:-}" ]; then
    [ -f "${RIVETIT_BH_SOURCE_DIR}/${name}" ] || die "${RIVETIT_BH_SOURCE_DIR}/${name} not found"
    cp -- "${RIVETIT_BH_SOURCE_DIR}/${name}" "${TMP}/${name}"
  else
    curl -fsSL --proto '=https' --tlsv1.2 --max-time 60 --max-filesize 1048576 -o "${TMP}/${name}" "${RAW_BASE}/${name}" || die "could not download ${RAW_BASE}/${name}"
  fi
  got="$(sha256sum -- "${TMP}/${name}" | cut -d' ' -f1)"
  if [ "${got}" != "${want}" ]; then
    die "checksum mismatch for ${name}: expected ${want}, got ${got}. Nothing was installed from it. (Pinned commit ${CORE_COMMIT}.)"
  fi
}
info "Fetching the build host files from RivetCore commit ${CORE_COMMIT:0:12} and checking their sha256."
fetch_verify build-host.sh "${SHA_BUILD_HOST_SH}"
fetch_verify build-host.service "${SHA_BUILD_HOST_SERVICE}"
fetch_verify build-host.timer "${SHA_BUILD_HOST_TIMER}"
note "build-host.sh, build-host.service, build-host.timer verified against the pinned sha256 (commit ${CORE_COMMIT:0:12})"
run install -d -o root -g root -m 0755 "${OPT_DIR}"
run install -o root -g root -m 0755 "${TMP}/build-host.sh" "${OPT_DIR}/build-host.sh"
run install -o root -g root -m 0644 "${TMP}/build-host.service" "${UNIT_DIR}/build-host.service"
run install -o root -g root -m 0644 "${TMP}/build-host.timer" "${UNIT_DIR}/build-host.timer"

# ---- 4. build host row, token and configuration -----------------------------------------------------------------------------------------------------------------------
[ -f "${setup_cli}" ] || die "${setup_cli} not found: pass --app-dir=DIR (the folder that holds scripts/build_host_setup.php)"
if [ -z "${SITE_URL}" ]; then
  SERVER_URL="$(as_web php "${setup_cli}" server-url)" || die "could not read the site address from the app. Pass --site-url=https://your-site"
else
  SERVER_URL="${SITE_URL%/}/api/v1/build_host"
fi
case "${SERVER_URL}" in
  https://*) ;;
  http://*) [ "${ALLOW_HTTP}" -eq 1 ] || die "the site address is http://. The build host sends its token with every request, so it must be https://. (--allow-http is for a throwaway test server only.)" ;;
  *) die "unexpected SERVER_URL" ;;
esac
[[ "${SERVER_URL}" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._/-]*)?$ ]] || die "the site address contains characters that are not allowed in the configuration"

old_token=""
if [ -f "${CONF}" ]; then
  old_token="$(sed -n 's/^TOKEN=//p' "${CONF}" | head -n1)"
  [[ "${old_token}" =~ ${TOKEN_RE} ]] || old_token=""
fi
host_status="$(as_web php "${setup_cli}" status "--name=${HOST_NAME}")" || die "the app could not be asked about build hosts (is the database update done?)"
if [ "${CHECK}" -eq 1 ]; then
  case "${host_status}" in
    "exists=0 "*) info "would create the build host \"${HOST_NAME}\" and write its token to ${CONF} (mode 600, owner ${BUILD_USER})" ;;
    "exists=1 enabled=1 "*)
      if [ -n "${old_token}" ] && [ "${ROTATE}" -eq 0 ]; then info "the build host row exists and ${CONF} holds a token: the token stays"; else info "would give the build host a new token"; fi ;;
    *) info "would enable the build host and give it a new token" ;;
  esac
  old_token=""
else
  new_token=""
  case "${host_status}" in
    "exists=0 "*) new_token="$(as_web php "${setup_cli}" create "--name=${HOST_NAME}")"; note "build host \"${HOST_NAME}\" created in the app" ;;
    "exists=1 enabled=1 "*)
      if [ -n "${old_token}" ] && [ "${ROTATE}" -eq 0 ]; then
        note "kept the existing token"
      else
        new_token="$(as_web php "${setup_cli}" rotate "--name=${HOST_NAME}")"; note "new token issued"
      fi ;;
    *) new_token="$(as_web php "${setup_cli}" rotate "--name=${HOST_NAME}")"; note "build host enabled with a new token" ;;
  esac
  new_token="${new_token#TOKEN=}"
  if [ -n "${new_token}" ]; then
    [[ "${new_token}" =~ ${TOKEN_RE} ]] || die "the app returned something that is not a token"
    token="${new_token}"
  else
    token="${old_token}"
  fi
  [ -n "${token}" ] || die "no token available"
  new_token=""
  [ -d "${CONF_DIR}" ] || install -d -o root -g root -m 0755 "${CONF_DIR}"   # an existing directory keeps its mode
  conf_tmp="$(mktemp "${CONF_DIR}/.build-host.conf.XXXXXX")"
  chmod 0600 "${conf_tmp}"
  {
    printf '# Written by deploy/setup-build-host.sh. Mode 600, owner %s. Re-running the script rewrites it (the token is kept).\n' "${BUILD_USER}"
    printf 'SERVER_URL=%s\n' "${SERVER_URL}"
    printf 'TOKEN=%s\n' "${token}"
    printf 'CORE_REPO=%s\n' "${CORE_REPO}"
    printf 'ALLOWED_REFS="%s"\n' "${ALLOWED_REFS}"
    printf 'WORKDIR=%s\n' "${BUILD_HOME}"
    printf 'BUILD_CPUS=2\nBUILD_MEMORY=4g\nBUILD_TIMEOUT_S=1500\nHTTP_TIMEOUT=120\n'
    if [ "${ALLOW_HTTP}" -eq 1 ]; then printf 'BUILD_HOST_ALLOW_HTTP=1\n'; fi
  } > "${conf_tmp}"
  token=""; old_token=""
  chown "${BUILD_USER}:${BUILD_USER}" "${conf_tmp}"
  mv -f -- "${conf_tmp}" "${CONF}"
  if ! as_build test -r "${CONF}"; then
    # /etc/rivetit may be root:www-data 0750 on an existing box: grant the build user traverse permission on that one directory, nothing more
    if command -v setfacl >/dev/null 2>&1 && setfacl -m "u:${BUILD_USER}:x" "${CONF_DIR}" && as_build test -r "${CONF}"; then
      note "gave ${BUILD_USER} search permission on ${CONF_DIR} (ACL) so it can read its configuration"
    else
      die "${BUILD_USER} cannot read ${CONF}: ${CONF_DIR} is not searchable by it. Run: setfacl -m u:${BUILD_USER}:x ${CONF_DIR} (or chmod o+x ${CONF_DIR}) and run this script again."
    fi
  fi
  note "configuration written to ${CONF} (mode 600, owner ${BUILD_USER}; the token was not printed)"
fi

# ---- 5. timer and verification ----------------------------------------------------------------------------------------------------------------------------------------
if command -v systemctl >/dev/null 2>&1; then
  run systemctl daemon-reload
  run systemctl enable --now build-host.timer
  note "build-host.timer enabled and started (asks the server every minute)"
else
  warn "systemctl not found: the timer was not started"
  note "NOT started: systemctl is not available"
fi

if [ "${CHECK}" -eq 1 ]; then
  info "would run: ${OPT_DIR}/build-host.sh --register as ${BUILD_USER}"
elif [ "${NO_REGISTER}" -eq 1 ]; then
  note "connection check skipped (--no-register)"
else
  info "Checking the connection: build-host.sh --register as ${BUILD_USER}"
  if register_out="$(as_build "${OPT_DIR}/build-host.sh" --config "${CONF}" --register 2>&1)"; then
    note "connection check passed: ${register_out##*$'\n'}"
  else
    FAILED=1
    note "CONNECTION CHECK FAILED: ${register_out##*$'\n'}"
  fi
fi

say ""
if [ "${FAILED}" -eq 0 ]; then
  say "RMM Agent Builder: done${CHECK_NOTE}."
else
  say "RMM Agent Builder is installed but the connection check FAILED."
fi
printf ' - %s\n' "${SUMMARY[@]}"
if [ "${FAILED}" -eq 0 ]; then
  say "Next: Administration > Agent updates > Build a new version > Build on my build host. The host shows as online within a minute."
else
  say "Fix the problem above (is the site reachable over https from this machine? see: journalctl -u build-host.service), then run this script again."
  say "If the token was changed elsewhere, add --rotate-token."
  exit 1
fi
