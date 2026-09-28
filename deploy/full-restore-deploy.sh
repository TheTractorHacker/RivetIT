#!/usr/bin/env bash
# RivetIT — interactive front-end for deploy/install.sh (+ deploy/harden.sh).
#
# install.sh already does everything needed to stand up (or restore) an
# instance, but a handful of decisions — get a real SSL certificate now,
# restore from an existing backup instead of a fresh company, what to name
# the database, and whether to also run the extra hardening pass — are plain
# flags/separate scripts that go unused unless you already know about them.
# This script asks about exactly those (whichever isn't already given on the
# command line), runs install.sh with the resolved flags, and — if asked for
# — runs deploy/harden.sh right after. Every other install.sh flag or prompt
# (--domain, --app-dir, --admin-*, --company-*, --skip-*, ...) is passed
# through untouched and behaves exactly as it does when you call install.sh
# directly.
#
# Usage:
#   sudo deploy/full-restore-deploy.sh [any deploy/install.sh flag]
#   sudo deploy/full-restore-deploy.sh --help
#
# --non-interactive skips every question below and hands argv straight to
# install.sh, exactly as if you had run install.sh directly — including
# skipping deploy/harden.sh, unless --harden is also given explicitly.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

INSTALL_SH="${SCRIPT_DIR}/install.sh"
HARDEN_SH="${SCRIPT_DIR}/harden.sh"

print_help() {
    cat <<'EOF'
RivetIT — interactive installer (wraps deploy/install.sh + deploy/harden.sh)

Usage:
  sudo deploy/full-restore-deploy.sh [any deploy/install.sh flag]

Before handing off to install.sh, this asks about whichever of the
following decisions wasn't already given on the command line:

  1. Restore vs. fresh — restore an existing backup onto this box
                          (--restore-from / --restore-passphrase-file) instead
                          of setting up a brand-new company. Either the app's
                          own itflow_<timestamp>_*.zip (Settings > Backup —
                          not encrypted, no passphrase needed, see
                          deploy/restore_zip.sh) or an encrypted
                          backup-*.tar.gz.enc from deploy/backup.sh (asks for
                          its passphrase file too, see deploy/restore.sh) —
                          install.sh picks the right one by the file
                          extension you give. Either way, install.sh runs
                          that restore path instead of the fresh-company
                          setup.
  2. Database name      — pick one, or leave it blank to auto-generate one
                          from --domain (--db-name).
  3. SSL certificate     — a real Let's Encrypt certificate now (asks for an
                          --email), a self-signed one behind your own
                          reverse proxy (--proxy-mode), or a self-signed
                          placeholder to replace with real TLS later
                          (--skip-tls).
  4. Extra hardening     — also run deploy/harden.sh once install.sh
                          finishes (this script's own --harden / --skip-harden
                          flags, not install.sh's). See 'sudo deploy/harden.sh
                          --help' for exactly what that adds on top of what
                          install.sh already applies inline.

Every other install.sh flag (--domain, --app-dir, --admin-*, --company-*,
--skip-firewall, --skip-fail2ban, --skip-dependencies, --non-interactive,
...) is passed straight through untouched — see 'sudo deploy/install.sh
--help' for the full reference. Giving one of the flags above yourself on
the command line skips the matching question here.

--non-interactive skips every question in this script too and hands your
flags straight to install.sh, exactly as if you had run install.sh directly
— deploy/harden.sh is then only run if you also pass --harden explicitly.
EOF
}

# ---------------------------------------------------------------------------
# Pre-scan argv: figure out what the caller already decided, without
# re-implementing install.sh's own full flag parser. Anything not recognized
# below is simply carried through to install.sh untouched, via
# PASSTHROUGH_ARGS. --harden/--skip-harden are this script's own flags (there
# is no install.sh equivalent), so they're consumed here and never forwarded.
# ---------------------------------------------------------------------------
DOMAIN=""
DB_NAME=""
PROXY_MODE=0
SKIP_TLS=0
CERTBOT_EMAIL=""
RESTORE_FROM=""
RESTORE_PASSPHRASE_FILE=""
NON_INTERACTIVE=0
SHOW_HELP=0
RUN_HARDEN=0
HARDEN_EXPLICIT=0

declare -a PASSTHROUGH_ARGS=()

for arg in "$@"; do
    case "${arg}" in
        --domain=*)                  DOMAIN="${arg#*=}" ;;
        --db-name=*)                 DB_NAME="${arg#*=}" ;;
        --proxy-mode)                PROXY_MODE=1 ;;
        --skip-tls)                  SKIP_TLS=1 ;;
        --email=*)                   CERTBOT_EMAIL="${arg#*=}" ;;
        --restore-from=*)            RESTORE_FROM="${arg#*=}" ;;
        --restore-passphrase-file=*) RESTORE_PASSPHRASE_FILE="${arg#*=}" ;;
        --non-interactive)           NON_INTERACTIVE=1 ;;
        --harden)                    RUN_HARDEN=1; HARDEN_EXPLICIT=1 ;;
        --skip-harden)                RUN_HARDEN=0; HARDEN_EXPLICIT=1 ;;
        --help|-h)                   SHOW_HELP=1 ;;
    esac
    # The flags this script resolves interactively (including
    # --non-interactive/--help/--harden/--skip-harden, all handled explicitly
    # above/below) are left OUT of the passthrough array — the install.sh-
    # facing ones are re-added, fully resolved, by the argument build below,
    # so a value decided here never ends up duplicated alongside a stale
    # copy still sitting in argv; --harden/--skip-harden are never forwarded
    # at all, since install.sh has no such flag.
    case "${arg}" in
        --domain=*|--db-name=*|--proxy-mode|--skip-tls|--email=*| \
        --restore-from=*|--restore-passphrase-file=*|--non-interactive| \
        --harden|--skip-harden|--help|-h) ;;
        *) PASSTHROUGH_ARGS+=("${arg}") ;;
    esac
done

if [[ "${SHOW_HELP}" -eq 1 ]]; then
    print_help
    echo
    "${INSTALL_SH}" --help
    exit 0
fi

require_root "$@"

# ask_yes_no PROMPT DEFAULT("y"|"n") -> prints "y" or "n" on stdout.
# `read` failing (piped-in/non-interactive stdin hitting EOF) is tolerated,
# same as install.sh's own "Install dependencies? [Y/n]" prompt, and just
# falls back to DEFAULT below.
ask_yes_no() {
    local prompt_text="$1" default="$2" hint ans
    if [[ "${default}" == "y" ]]; then hint="Y/n"; else hint="y/N"; fi
    ans=""
    read -r -p "${prompt_text} [${hint}] " ans || true
    ans="${ans:-${default}}"
    case "${ans}" in
        [yY]*) printf 'y' ;;
        *)     printf 'n' ;;
    esac
}

# read_required VARNAME PROMPT: like `read -r -p PROMPT VARNAME`, but dies
# with a clear message on end-of-input instead of leaving a
# `while [[ -z "$VARNAME" ]]; do read_required ...; done` loop below calling
# it again and again against an already-closed stdin forever (piped-in
# automation that ran one answer short, or a stray Ctrl+D) — `read`'s own
# failure on EOF leaves the target variable untouched, so a bare
# `read ... || true` inside such a loop never lets the loop condition change.
read_required() {
    local -n out_ref="$1"
    local prompt_text="$2"
    if ! read -r -p "${prompt_text}" out_ref; then
        echo
        die "No more input while waiting for an answer (\"${prompt_text}\"). Re-run with a real terminal, or pass every value as a flag and use --non-interactive."
    fi
}

if [[ "${NON_INTERACTIVE}" -eq 0 ]]; then

    # --- Domain (required by install.sh; asked here too so the questions
    # below can refer to it) ------------------------------------------------
    if [[ -z "${DOMAIN}" ]]; then
        echo
        while [[ -z "${DOMAIN}" ]]; do
            read_required DOMAIN "Domain name for this instance (e.g. rivetit.example.com): "
        done
    fi

    # --- Restore vs. fresh --------------------------------------------------
    if [[ -z "${RESTORE_FROM}" && -z "${RESTORE_PASSPHRASE_FILE}" ]]; then
        echo
        restore_answer="$(ask_yes_no "Restore ${DOMAIN} from an existing backup instead of setting up a brand-new company?" n)"
        if [[ "${restore_answer}" == "y" ]]; then
            while [[ -z "${RESTORE_FROM}" ]]; do
                read_required RESTORE_FROM "Path to the backup file — either an itflow_<timestamp>_*.zip (Settings > Backup) or an encrypted backup-*.tar.gz.enc (deploy/backup.sh): "
                if [[ -n "${RESTORE_FROM}" && ! -f "${RESTORE_FROM}" ]]; then
                    warn "File not found: ${RESTORE_FROM}"
                    RESTORE_FROM=""
                fi
            done
            # The in-app .zip format isn't encrypted (see deploy/restore_zip.sh) —
            # only the backup-*.tar.gz.enc archive needs a passphrase to decrypt.
            if [[ "${RESTORE_FROM}" != *.zip ]]; then
                while [[ -z "${RESTORE_PASSPHRASE_FILE}" ]]; do
                    read_required RESTORE_PASSPHRASE_FILE "Path to the passphrase file that backup was encrypted with (chmod 600): "
                    if [[ -n "${RESTORE_PASSPHRASE_FILE}" && ! -f "${RESTORE_PASSPHRASE_FILE}" ]]; then
                        warn "File not found: ${RESTORE_PASSPHRASE_FILE}"
                        RESTORE_PASSPHRASE_FILE=""
                    fi
                done
            fi
            info "Restoring from ${RESTORE_FROM} — the company/localization/admin-user questions install.sh would otherwise ask are skipped; the restored backup already has all of that."
        fi
    fi

    # --- Database name ------------------------------------------------------
    if [[ -z "${DB_NAME}" ]]; then
        echo
        read -r -p "Database name for this instance — also becomes the database username (leave blank to auto-generate one from the domain): " DB_NAME || true
        while [[ -n "${DB_NAME}" && ! "${DB_NAME}" =~ ^[a-z][a-z0-9_]{0,31}$ ]]; do
            warn "'${DB_NAME}' is not a valid database name: must start with a lowercase letter and contain only lowercase letters, digits, and underscores (max 32 characters)."
            read_required DB_NAME "Database name (leave blank to auto-generate one from the domain): "
        done
        if [[ -z "${DB_NAME}" ]]; then
            info "No database name given — install.sh will auto-generate one from the domain."
        fi
    fi

    # --- SSL certificate -----------------------------------------------------
    if [[ "${PROXY_MODE}" -eq 0 && "${SKIP_TLS}" -eq 0 && -z "${CERTBOT_EMAIL}" ]]; then
        echo
        ssl_answer="$(ask_yes_no "Generate a free Let's Encrypt SSL certificate for ${DOMAIN} now?" y)"
        if [[ "${ssl_answer}" == "y" ]]; then
            while [[ -z "${CERTBOT_EMAIL}" ]]; do
                read_required CERTBOT_EMAIL "Contact email for Let's Encrypt certificate registration: "
            done
        else
            proxy_answer="$(ask_yes_no "Is this box behind another reverse proxy that already provides HTTPS?" n)"
            if [[ "${proxy_answer}" == "y" ]]; then
                PROXY_MODE=1
            else
                warn "Proceeding without a real certificate (--skip-tls) — ${DOMAIN} will serve a self-signed certificate until you configure real TLS by hand."
                SKIP_TLS=1
            fi
        fi
    fi

    # --- Extra hardening pass ------------------------------------------------
    if [[ "${HARDEN_EXPLICIT}" -eq 0 ]]; then
        echo
        harden_answer="$(ask_yes_no "Also run the extra hardening pass (deploy/harden.sh) once install.sh finishes? It removes anonymous MySQL users/the test db and sets up unattended security upgrades, on top of the PHP-FPM/MariaDB/fail2ban/ufw hardening install.sh already applies." y)"
        if [[ "${harden_answer}" == "y" ]]; then
            RUN_HARDEN=1
        else
            RUN_HARDEN=0
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Build the final install.sh argument list.
# ---------------------------------------------------------------------------
declare -a FINAL_ARGS=("${PASSTHROUGH_ARGS[@]}")
# Written as `if`/`fi` rather than a bare top-level `[[ cond ]] && action` —
# see install.sh's own add_opt_arg() comment for why that form is avoided
# throughout this codebase's deploy scripts.
if [[ -n "${DOMAIN}" ]]; then FINAL_ARGS+=("--domain=${DOMAIN}"); fi
if [[ -n "${DB_NAME}" ]]; then FINAL_ARGS+=("--db-name=${DB_NAME}"); fi
if [[ "${PROXY_MODE}" -eq 1 ]]; then FINAL_ARGS+=("--proxy-mode"); fi
if [[ "${SKIP_TLS}" -eq 1 ]]; then FINAL_ARGS+=("--skip-tls"); fi
if [[ -n "${CERTBOT_EMAIL}" ]]; then FINAL_ARGS+=("--email=${CERTBOT_EMAIL}"); fi
if [[ -n "${RESTORE_FROM}" ]]; then FINAL_ARGS+=("--restore-from=${RESTORE_FROM}"); fi
if [[ -n "${RESTORE_PASSPHRASE_FILE}" ]]; then FINAL_ARGS+=("--restore-passphrase-file=${RESTORE_PASSPHRASE_FILE}"); fi
if [[ "${NON_INTERACTIVE}" -eq 1 ]]; then FINAL_ARGS+=("--non-interactive"); fi

if [[ "${NON_INTERACTIVE}" -eq 0 ]]; then
    echo
    info "=== Handing off to install.sh ==="
    info "Domain:  ${DOMAIN:-<will be required by install.sh>}"
    info "Database: ${DB_NAME:-<auto-generated from domain>}"
    if [[ -n "${RESTORE_FROM}" ]]; then
        info "Mode: restore from ${RESTORE_FROM}"
    else
        info "Mode: fresh company setup"
    fi
    if [[ "${PROXY_MODE}" -eq 1 ]]; then
        info "TLS: self-signed backend cert (--proxy-mode)"
    elif [[ "${SKIP_TLS}" -eq 1 ]]; then
        info "TLS: self-signed placeholder (--skip-tls) — configure real TLS later"
    else
        info "TLS: Let's Encrypt via certbot (${CERTBOT_EMAIL})"
    fi
    if [[ "${RUN_HARDEN}" -eq 1 ]]; then
        info "Extra hardening: deploy/harden.sh will run once install.sh finishes"
    else
        info "Extra hardening: skipped (run 'sudo deploy/harden.sh' yourself later if you change your mind)"
    fi
    announce "Running: deploy/install.sh ${FINAL_ARGS[*]}"
fi

"${INSTALL_SH}" "${FINAL_ARGS[@]}"

if [[ "${RUN_HARDEN}" -eq 1 ]]; then
    announce "install.sh finished — running deploy/harden.sh for the extra box-wide hardening pass."
    "${HARDEN_SH}"
fi
