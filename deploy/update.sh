#!/usr/bin/env bash
set -euo pipefail

# RivetIT — safe update wrapper around scripts/update_cli.php.
#
# This script does NOT reimplement update_cli.php's git-pull / migration
# logic — it orchestrates it safely: take a backup first (or make the
# operator explicitly opt out), run the update as the file-owning user
# update_cli.php itself requires, refresh Composer dependencies, run DB migrations,
# deps, and reload php-fpm so opcache never keeps serving pre-update bytecode.
#
# Usage:
#   update.sh --app-dir=<path> [--passphrase-file=<path>] [--no-backup-confirmed] [--unattended]
#   update.sh --help
#
# Required:
#   --app-dir=<path>            Webroot of the instance to update.
#
# Backup (exactly one of these two must apply — see main() below):
#   --passphrase-file=<path>    Runs deploy/backup.sh with this passphrase
#                               file before touching anything. The whole
#                               update aborts if that backup fails — this
#                               script never updates an instance without a
#                               fresh, successful backup unless told to.
#   --no-backup-confirmed       Explicit, required opt-out when you are not
#                               providing --passphrase-file. Without EITHER
#                               flag, this script refuses to run at all.
#
# Options:
#   --unattended                 Cron-friendly mode: see setup_logging()
#                               below for exactly what this changes.
#   --help                       Show this help and exit.
#
# Must be run as root — reloading php-fpm requires it, and update_cli.php's
# own steps are then run via `sudo -u <owner-of-update_cli.php>` internally,
# matching the file-owner enforcement update_cli.php has always had.
#
# KNOWN ISSUE (do not silently "fix" by editing update_cli.php — see this
# project's shared context): update_cli.php's --force_update hardcodes
# `git reset --hard origin/master`, but this repository's real default
# branch is `main`. This script therefore deliberately NEVER passes
# --force_update — only plain `--update` (a `git pull`, which tracks
# whatever branch is actually checked out and is unaffected by that
# hardcoded name).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-update.log"

APP_DIR=""
PASSPHRASE_FILE=""
NO_BACKUP_CONFIRMED=0
UNATTENDED=0

# Populated later in main() by determine_owner().
OWNER=""

print_help() {
    cat <<'EOF'
RivetIT — safe update wrapper

Usage:
  sudo deploy/update.sh --app-dir=<path> [options]

Required:
  --app-dir=<path>            Webroot of the instance to update.

Backup (pass exactly one; the script refuses to run with neither):
  --passphrase-file=<path>    Take a backup (via deploy/backup.sh) before
                              updating. Aborts the whole update if the
                              backup fails.
  --no-backup-confirmed       Explicitly proceed WITHOUT taking a backup
                              first. Only use this if you already have a
                              recent backup from elsewhere.

Options:
  --unattended                 Cron-friendly: output goes to
                              /var/log/itflow-update.log only, not the
                              terminal (there usually isn't one). Without
                              this flag, output goes to both.
  --help                       Show this help and exit.

Steps performed, in order: pre-update backup (or confirmed skip) -> git
pull via scripts/update_cli.php --update (run as that file's owner) ->
composer install (if composer.json exists) -> database migrations via
scripts/update_cli.php --update_db -> php-fpm reload (auto-detected) -> web server
routes check (a dry run, and only from a root-owned copy of deploy/apply-web-routes.sh;
otherwise it prints the command: update.sh never changes nginx itself).
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --app-dir=*)             APP_DIR="${arg#*=}" ;;
            --passphrase-file=*)     PASSPHRASE_FILE="${arg#*=}" ;;
            --no-backup-confirmed)   NO_BACKUP_CONFIRMED=1 ;;
            --unattended)             UNATTENDED=1 ;;
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
    [[ -f "${APP_DIR}/scripts/update_cli.php" ]] || die "No scripts/update_cli.php found under ${APP_DIR} — is this an installed RivetIT instance?"
    [[ -f "${APP_DIR}/config.php" ]] || die "No config.php found under ${APP_DIR} — update_cli.php requires it (it require_once's ../config.php) and this instance doesn't look set up yet."

    if [[ -n "${PASSPHRASE_FILE}" && "${NO_BACKUP_CONFIRMED}" -eq 1 ]]; then
        die "Pass --passphrase-file OR --no-backup-confirmed, not both — it's ambiguous whether you want a backup taken."
    fi
    if [[ -z "${PASSPHRASE_FILE}" && "${NO_BACKUP_CONFIRMED}" -eq 0 ]]; then
        print_help
        die "Refusing to update without a backup. Pass --passphrase-file=<path> to take one automatically first, or pass --no-backup-confirmed to explicitly proceed without one."
    fi
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    if [[ "${UNATTENDED}" -eq 1 ]]; then
        # Cron-friendly: there is normally no terminal attached to a cron/
        # systemd-timer invocation, so duplicating output there via tee (as
        # the interactive branch below does) would be pointless — append
        # straight to the persistent logfile instead. Exit code is still
        # what a cron wrapper / systemd OnFailure= hook should alert on;
        # the logfile is where the "clear summary" this script prints ends
        # up for later review.
        exec >> "${LOG_FILE}" 2>&1
    else
        # Interactive default: a human is presumably watching, so show
        # progress live AND keep the same persistent record unattended runs
        # get, for consistency.
        exec > >(tee -a "${LOG_FILE}") 2>&1
    fi
}

# run_pre_update_backup(): implements the mandatory-backup-or-explicit-
# opt-out gate. validate_args() already guarantees exactly one of
# PASSPHRASE_FILE / NO_BACKUP_CONFIRMED is set by the time this runs.
run_pre_update_backup() {
    if [[ -n "${PASSPHRASE_FILE}" ]]; then
        announce "Taking a pre-update backup via deploy/backup.sh before touching anything."
        if ! "${SCRIPT_DIR}/backup.sh" --app-dir="${APP_DIR}" --passphrase-file="${PASSPHRASE_FILE}"; then
            die "Pre-update backup failed (deploy/backup.sh exited non-zero — see its output above). Aborting the update entirely; ${APP_DIR} was NOT touched. Fix the backup failure and re-run."
        fi
        success "Pre-update backup completed successfully."
    else
        warn "Proceeding WITHOUT a pre-update backup (--no-backup-confirmed was passed). If this update goes wrong, there is nothing from this run to roll back to."
    fi
}

# determine_owner(): update_cli.php enforces posix_geteuid() === its own
# fileowner() and refuses to run otherwise — mirror that requirement here
# by finding that owner and running every PHP/composer step as them via
# sudo -u, rather than trying to run update_cli.php as root and having it
# reject us with its own error.
determine_owner() {
    OWNER="$(stat -c '%U' "${APP_DIR}/scripts/update_cli.php")"
    [[ -n "${OWNER}" ]] || die "Could not determine the owner of ${APP_DIR}/scripts/update_cli.php via stat."
    info "scripts/update_cli.php is owned by '${OWNER}'; running update_cli.php and composer as that user (sudo -u ${OWNER})."
}

run_git_pull() {
    local before_hash after_hash
    before_hash="$(sudo -u "${OWNER}" git -C "${APP_DIR}" rev-parse --short HEAD 2>/dev/null || echo unknown)"

    announce "Running scripts/update_cli.php --update (git pull) as ${OWNER}."
    # Deliberately --update, never --force_update — see this script's own
    # header comment on the origin/master vs. main branch-name mismatch.
    # update_cli.php's own stdout streams straight through here (this
    # command is not captured into a variable) so its own "Update
    # successful" / "Already up to date." messages reach the log verbatim,
    # per this script's own requirement to surface that output, not
    # swallow it.
    if ! sudo -u "${OWNER}" php "${APP_DIR}/scripts/update_cli.php" --update; then
        die "scripts/update_cli.php --update failed (see its output above). Database migrations were NOT run."
    fi

    after_hash="$(sudo -u "${OWNER}" git -C "${APP_DIR}" rev-parse --short HEAD 2>/dev/null || echo unknown)"
    if [[ "${before_hash}" == "${after_hash}" ]]; then
        info "Code unchanged (still at ${after_hash})."
    else
        success "Code updated: ${before_hash} -> ${after_hash}"
    fi
}

run_db_migrations() {
    announce "Running scripts/update_cli.php --update_db as ${OWNER}."
    if ! sudo -u "${OWNER}" php "${APP_DIR}/scripts/update_cli.php" --update_db; then
        die "scripts/update_cli.php --update_db failed (see its output above). Code was already updated by the previous step — the database schema may now be behind the code. Investigate before running the app."
    fi
}

run_composer_install() {
    if [[ ! -f "${APP_DIR}/composer.json" ]]; then
        info "No composer.json at ${APP_DIR}; skipping composer install."
        return 0
    fi
    if ! command_exists composer; then
        die "composer.json exists at ${APP_DIR} but the 'composer' command is not on PATH. Install Composer before updating this release."
    fi

    announce "Running composer install as ${OWNER}."
    if ! sudo -u "${OWNER}" bash -c "cd '${APP_DIR}' && composer install --no-dev --optimize-autoloader --no-interaction"; then
        die "composer install failed (see its output above). Code was updated but database migrations have not run. Investigate before running the app."
    fi
    success "composer dependencies refreshed."
}

reload_php_fpm() {
    # Belt-and-suspenders: php-hardening.ini ships
    # opcache.validate_timestamps=1 specifically so a self-update's changed
    # files get picked up without this step — but reloading here means
    # updated code is guaranteed live immediately after this script exits,
    # rather than after opcache's next timestamp-revalidation window.
    #
    # The PHP version is NOT assumed (deploy/install.sh provisions whatever
    # PHP_VERSION says at install time, which changes over time — an install
    # from before 2026-09-28 is on 8.4, a fresh one now is on 8.5): detect
    # the box's own running php-fpm service the same way deploy/harden.sh
    # already does, via `php -v`'s own major.minor, rather than hardcode one.
    local php_ver php_fpm_svc
    php_ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    if [[ -z "${php_ver}" ]]; then
        warn "Could not detect the running PHP version (php -v failed); skipping the php-fpm reload. Reload it yourself: systemctl reload php<version>-fpm."
        return 0
    fi
    php_fpm_svc="php${php_ver}-fpm"
    if ! service_is_active "${php_fpm_svc}"; then
        warn "${php_fpm_svc} is not active; skipping reload (nothing to reload)."
        return 0
    fi
    announce "Reloading ${php_fpm_svc} to drop any opcache-cached pre-update bytecode."
    systemctl reload "${php_fpm_svc}"
    success "${php_fpm_svc} reloaded."
}

# The trust check below is the same text as in deploy/apply-web-routes.sh (tests/web_routes_trust.php keeps them identical).
# BEGIN trust-check
# rivetit_path_trusted PATH: succeeds only when PATH, what it resolves to, and every parent directory of both are owned by root
# and not writable by group or others. On failure it prints why. (Test hook: a non-root test run may name another trusted owner.)
rivetit_path_trusted() {
    local p="$1" want=0 stop="/" q d u m
    if [[ "${RIVETIT_ROUTES_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 ]]; then want="${RIVETIT_TRUST_UID:-0}"; stop="${RIVETIT_TRUST_STOP:-/}"; fi
    [[ "${p}" == /* ]] || { echo "not an absolute path: ${p}"; return 1; }
    for q in "${p}" "$(readlink -f -- "${p}" 2>/dev/null || echo "${p}")"; do
        [[ -e "${q}" ]] || { echo "missing: ${q}"; return 1; }
        d="${q}"
        while :; do
            read -r u m < <(stat -c '%u %a' -- "${d}")
            [[ "${u}" == "${want}" ]] || { echo "${d} is not owned by root"; return 1; }
            if (( (8#${m} & 8#022) != 0 )); then echo "${d} is writable by group or others (mode ${m})"; return 1; fi
            [[ "${d}" == "/" || "${d}" == "${stop}" ]] && break
            d="$(dirname -- "${d}")"
        done
    done
    return 0
}
# END trust-check

# apply_web_routes(): a release can need a new nginx route (Remote MCP sign-in, kiosk links, ...). Routes live in one shared file that
# deploy/apply-web-routes.sh keeps current. THIS SCRIPT NEVER APPLIES THEM, because it runs as root and the app tree is normally owned
# by the web user: it will only run the apply tool when the tool and everything it loads (and every parent directory) is root-owned and
# not group/world writable, and then only as a DRY RUN that shows the diff and prints the exact command to apply it after a human has
# read it. Otherwise it just prints the command. Never fatal.
apply_web_routes() {
    local domain cmd rc=0 cand why f untrusted="" tool_dir script="" out
    domain="$(sed -n "s/^\\\$config_base_url = ['\"]\\([^'\"]*\\)['\"];.*/\\1/p" "${APP_DIR}/config.php" 2>/dev/null | head -n1)"
    domain="${domain%%:*}"
    if ! command_exists nginx || [[ ! -d /etc/nginx/sites-enabled ]]; then
        info "Web server routes: nginx is not managed here (Docker/Apache install?); skipping."
        return 0
    fi
    if [[ ! -f "${APP_DIR}/deploy/apply-web-routes.sh" ]]; then
        info "Web server routes: this release has no deploy/apply-web-routes.sh; skipping."
        return 0
    fi
    if [[ -z "${domain}" || ! "${domain}" =~ ^[A-Za-z0-9.-]+$ ]]; then
        warn "Web server routes: could not read this site's domain from ${APP_DIR}/config.php. When ready, review with: sudo ${APP_DIR}/deploy/apply-web-routes.sh --domain <your domain> --dry-run"
        return 0
    fi
    cmd="sudo ${APP_DIR}/deploy/apply-web-routes.sh --domain ${domain} --dry-run"
    # A root-owned copy (apply-web-routes.sh --install-root-copy) or a root-owned checkout may be run; a web-writable one may not.
    for cand in /usr/local/lib/rivetit "${APP_DIR}"; do
        untrusted=""
        for f in deploy/apply-web-routes.sh deploy/lib/web_routes_tool.php src/Ops/NginxConf.php src/Ops/WebRoutes.php src/Ops/WebRoutesConverter.php deploy/templates/rivetit-locations.conf; do
            if ! why="$(rivetit_path_trusted "${cand}/${f}")"; then untrusted="${why}"; break; fi
        done
        if [[ -z "${untrusted}" ]]; then script="${cand}/deploy/apply-web-routes.sh"; break; fi
    done
    if [[ "$(id -u)" -ne 0 || -z "${script}" ]]; then
        warn "Web server routes were NOT applied by this update (on purpose: ${untrusted:-not running as root}). A release may add routes; review and apply them with:"
        warn "    ${cmd}"
        warn "  (then the --yes-diff-sha command it prints). To make this automatic-to-review, install a root-owned copy once: sudo ${APP_DIR}/deploy/apply-web-routes.sh --install-root-copy"
        return 0
    fi
    announce "Checking the web server routes with the root-owned tool (dry run, nothing is changed)."
    out="$("${script}" --domain "${domain}" --dry-run 2>&1)" || rc=$?
    printf '%s\n' "${out}"
    if [[ "${rc}" -ne 0 ]]; then
        warn "Web server routes could not be checked (exit ${rc}). The update itself is complete. Look at the message above, then run: ${cmd}"
    elif grep -q '^DIFF-SHA: ' <<< "${out}"; then
        warn "The web server routes are out of date. Read the diff above, then apply it with the command printed above (--yes-diff-sha ...). This update did not change nginx."
    else
        success "Web server routes are current."
    fi
    return 0
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetIT update starting for ${APP_DIR} ==="

    run_pre_update_backup

    # OWNER is intentionally a plain (non-local) global here, set by
    # determine_owner() and read by every run_*() step below — same
    # single-script "populated later in main()" convention install.sh uses
    # for DB_PASSWORD, not a scoping accident.
    determine_owner
    ignore_git_filemode "${APP_DIR}" "${OWNER}"

    run_git_pull
    run_composer_install
    run_db_migrations
    # Existing installs gain the dedicated Redis instance on their next update (idempotent, never fatal).
    ensure_rivetit_redis "${SCRIPT_DIR}/templates"
    reload_php_fpm
    apply_web_routes

    success "=== Update complete for ${APP_DIR} ==="
}

main "$@"
