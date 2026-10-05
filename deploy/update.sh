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
scripts/update_cli.php --update_db -> php-fpm reload (auto-detected).
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

    run_git_pull
    run_composer_install
    run_db_migrations
    # Existing installs gain the dedicated Redis instance on their next update (idempotent, never fatal).
    ensure_rivetit_redis "${SCRIPT_DIR}/templates"
    reload_php_fpm

    success "=== Update complete for ${APP_DIR} ==="
}

main "$@"
