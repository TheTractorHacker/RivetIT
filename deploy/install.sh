#!/usr/bin/env bash
# RivetIT — fresh company installer.
#
# Stands up a brand-new, standalone instance of this app on a fresh Ubuntu/
# Debian box, or adds ANOTHER company's independent instance (own vhost, own
# database, own database user) alongside one or more instances already
# running on the same box. This is NOT a multi-tenant installer — every
# company gets its own separate database and its own separate config.php.
#
# Usage:
#   sudo deploy/install.sh --domain=rivetit.example.com [options]
#   sudo deploy/install.sh --help
#
# See print_help() below for the full flag reference.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
# The repository the project is published from today (the same one as
# APP_REPO_URL in includes/branding.php); change both when the project moves.
REPO_URL="https://github.com/TheTractorHacker/RivetIT.git"
REPO_BRANCH="main"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATES_DIR="${SCRIPT_DIR}/templates"
LOG_FILE="/var/log/itflow-install.log"

# The PHP version fresh installs get. Bump this, not the individual
# references below (they all read this constant) — checked working
# (php -l on every app file, a representative page sweep with zero new
# warnings) on 2026-09-28 before adopting it here; see REBRANDING.md.
# An install that already exists keeps whatever PHP version it was
# provisioned with — deploy/update.sh and deploy/harden.sh both detect the
# box's actual running php-fpm version rather than assuming this constant,
# so bumping it here never affects them.
PHP_VERSION="8.5"
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"

# Base packages needed regardless of PHP version. gettext-base provides
# envsubst (used to render the nginx vhost template); rsync is used when
# install.sh is run from inside an already-cloned checkout (see
# provision_app_code). redis-server backs live ticket/chat push, rate limits
# and job locks. The app expects it on 127.0.0.1:6380, not the package's
# default 6379, so ensure_rivetit_redis (deploy/lib/common.sh) starts a
# dedicated rivetit-redis instance there. The app degrades without Redis,
# but installing it here means a fresh install gets full functionality by
# default instead of a silent degrade nobody notices. All are near-universally preinstalled/packaged on Ubuntu, but are
# listed explicitly so a minimal/container base image still works.
REQUIRED_BASE_PACKAGES=(nginx mariadb-server certbot python3-certbot-nginx ufw fail2ban git composer openssl unattended-upgrades gettext-base rsync redis-server poppler-utils util-linux cron)

# poppler-utils (pdfinfo/pdftoppm/pdftotext/pdftohtml) and util-linux (prlimit) are run by the Training PDF
# lessons and the KB PDF import; without them every PDF upload fails with "not a readable PDF".

# php-mysqli is not a real ondrej/php package name — mysqli/pdo_mysql ship
# together in php-mysql. php-sodium does not exist either: libsodium has been
# a PHP core (bundled) extension since PHP 7.2, so it comes for free with
# php-common (a dependency of every package below) — nothing to list.
# php-opcache is ALSO bundled by default as of the 8.5 ondrej/php build (it
# was still its own package for 8.4 and earlier) — confirmed empirically
# 2026-09-28 (`apt-cache policy php8.5-opcache` -> no such package, while
# `php8.5 -v` already reports "with Zend OPcache"); deliberately not listed
# for the same "comes bundled, would 404" reason as sodium above. If a
# future PHP_VERSION reintroduces a separate opcache package, add it back.
REQUIRED_PHP_PACKAGES=("php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath")

# Every uploads/* subdirectory the application writes to. All but `kb` also
# appear in .gitignore, each normally holding a tracked `index.php` placeholder
# that denies directory listing.
#
# `kb` is the KB media tree - DOCX/PDF-imported inline images and article
# attachments. Until now nothing created it here and the importers relied on
# mkdirMissing() at first use, which makes the directory under PHP-FPM's umask
# instead of the ownership and mode set_file_permissions() applies to
# everything else. Creating it here puts it on the same footing. NOTE for
# whoever owns .gitignore: it still has no `uploads/kb/*` entry, so KB images
# imported in a git working copy show up as untracked files; that entry should
# land with the rest of this change.
UPLOAD_SUBDIRS=(contracts clients custom documents document_templates expenses kb recurring_tickets settings training users tmp tickets ticket_templates)

# ---------------------------------------------------------------------------
# Options (defaults — populated by parse_args)
# ---------------------------------------------------------------------------
DOMAIN=""
APP_DIR=""
DB_NAME=""
PROXY_MODE=0
SKIP_FIREWALL=0
SKIP_FAIL2BAN=0
SKIP_TLS=0
SKIP_DEPENDENCIES=0
NON_INTERACTIVE=0
CERTBOT_EMAIL=""
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
LOCALE=""
TIMEZONE=""
CURRENCY=""
COMPANY_NAME=""
COUNTRY=""
ADDRESS=""
CITY=""
STATE=""
ZIP=""
PHONE=""
COMPANY_EMAIL=""
WEBSITE=""
RESTORE_FROM=""
RESTORE_PASSPHRASE_FILE=""
# Network path in front of the app. Empty = not given on the command line (asked, or defaulted below).
LOCAL_PROXIES=""
BEHIND_CLOUDFLARE=""
# 1 when the operator actually answered/passed the network path (as opposed to it being defaulted), so a
# re-run against an existing install never overwrites saved values with defaults.
NETWORK_EXPLICIT=0

# Populated later in main(), after DOMAIN/PROXY_MODE/SKIP_TLS are known.
NEED_CERTBOT=0
SSL_CERT_PATH=""
SSL_CERT_KEY_PATH=""
DB_PASSWORD=""

# ---------------------------------------------------------------------------
# Help / argument parsing
# ---------------------------------------------------------------------------
print_help() {
    cat <<'EOF'
RivetIT — fresh company installer

Usage:
  sudo deploy/install.sh --domain=<fqdn> [options]

Required:
  --domain=<fqdn>          Public hostname for this instance, e.g.
                            rivetit.example.com. Only [a-zA-Z0-9.-] allowed —
                            this value is interpolated into a generated
                            nginx config and shell commands.

Deployment options:
  --app-dir=<path>          Webroot for this instance (default: /var/www/<domain>)
  --db-name=<name>          MariaDB database + username (default: derived
                            from --domain, sanitized to [a-z0-9_])
  --proxy-mode              This box sits behind an existing external reverse
                            proxy that already terminates public TLS
                            elsewhere. Skips certbot; generates a self-signed
                            backend cert instead, and configures the vhost so
                            nginx's own redirects stay relative (no internal
                            hostname/port ever leaks to an end user).
  --skip-tls                 Skip certbot AND proxy-mode's redirect tweaks —
                            serve a self-signed cert directly and leave real
                            TLS for you to configure later (e.g. no public
                            DNS yet). Implies --email is not required.
  --skip-firewall            Do not touch ufw.
  --skip-fail2ban             Do not touch fail2ban.
  --skip-dependencies         Do not install nginx, PHP, MariaDB, Redis, or
                            cron, and do not ask about it either — use this
                            when they are already provisioned the way you
                            want (a different PHP build, a managed database,
                            etc.) and everything else here (the app code,
                            vhost, TLS, hardening) should still be set up
                            against them. Without this flag, an interactive
                            run ASKS first ("Install dependencies? [Y/n]");
                            --non-interactive installs them by default,
                            exactly like today, unless this flag is also
                            given.
  --non-interactive           Fail instead of prompting for anything missing.
                            Requires --admin-name, --admin-email,
                            --admin-password, --locale, --timezone,
                            --currency, --company-name, --country, and
                            --email (unless --proxy-mode or --skip-tls).

Certificates:
  --email=<address>          Contact email for Let's Encrypt registration.
                            Required unless --proxy-mode or --skip-tls.

First admin user (optional — omitted values are prompted for interactively
unless --non-interactive):
  --admin-name=<name>
  --admin-email=<address>
  --admin-password=<password>   WARNING: exposed via `ps` while setup runs.
                            Omit this flag and answer the prompt instead
                            whenever you have an interactive terminal.

Network path (what sits in front of this app; used to record the real client
address instead of a proxy's — also editable later in Admin > Security):
  --local-proxies=<N>       Number of reverse proxies on YOUR side between the
                            internet (or Cloudflare) and this server, 0-10.
                            Do not count Cloudflare. 0 = users connect straight
                            to this box. Default: 1 with --proxy-mode, else 0.
  --cloudflare=<yes|no>     Whether public traffic arrives through Cloudflare.
                            Default: no.
                            Both are asked interactively when omitted, and
                            defaulted (not asked) with --non-interactive.

Company / localization details (optional — same prompt-if-omitted rule):
  --locale=<locale>              e.g. en_US
  --timezone=<tz>                 e.g. America/New_York
  --currency=<code>                e.g. USD
  --company-name=<name>
  --country=<name>
  --address=<address>
  --city=<city>
  --state=<state>
  --zip=<zip>
  --phone=<phone>
  --company-email=<address>
  --website=<url>

Restore onto this new box instead of a fresh company setup (--restore-from
required; every company/localization/admin-user option above is ignored and
not prompted for — the restored backup already has all of that):
  --restore-from=<path>            Path to EITHER a backup-*.tar.gz.enc
                            produced by deploy/backup.sh, OR an in-app
                            itflow_<timestamp>_*.zip from this app's own
                            Settings > Backup feature (detected by the
                            .zip extension) — handed off to restore.sh or
                            restore_admin_zip.sh respectively.
  --restore-passphrase-file=<path>  600-permission file holding the
                            passphrase that backup was encrypted with.
                            Always required with a backup-*.tar.gz.enc.
                            With a .zip, only required if an admin had a
                            backup passphrase set (Settings > Backup) when
                            that specific backup was taken — restore_admin_zip.sh
                            itself refuses clearly if the backup turns out
                            to need one and none was given; this installer
                            cannot know that ahead of time from the file
                            alone, so it never requires this flag for a
                            .zip, only passes it through when given.

  --help                           Show this help and exit.

Adding another company to a box that already runs a RivetIT
instance: re-run this exact script with a different --domain (and, if
sharing the box, a different --db-name). Already-installed packages and an
already-active firewall/fail2ban are detected and left alone.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --domain=*)          DOMAIN="${arg#*=}" ;;
            --app-dir=*)         APP_DIR="${arg#*=}" ;;
            --db-name=*)         DB_NAME="${arg#*=}" ;;
            --proxy-mode)        PROXY_MODE=1 ;;
            --skip-firewall)     SKIP_FIREWALL=1 ;;
            --skip-fail2ban)     SKIP_FAIL2BAN=1 ;;
            --skip-dependencies) SKIP_DEPENDENCIES=1 ;;
            --skip-tls)          SKIP_TLS=1 ;;
            --non-interactive)   NON_INTERACTIVE=1 ;;
            --email=*)           CERTBOT_EMAIL="${arg#*=}" ;;
            --admin-name=*)      ADMIN_NAME="${arg#*=}" ;;
            --admin-email=*)     ADMIN_EMAIL="${arg#*=}" ;;
            --admin-password=*)  ADMIN_PASSWORD="${arg#*=}" ;;
            --locale=*)          LOCALE="${arg#*=}" ;;
            --timezone=*)        TIMEZONE="${arg#*=}" ;;
            --currency=*)        CURRENCY="${arg#*=}" ;;
            --company-name=*)    COMPANY_NAME="${arg#*=}" ;;
            --country=*)         COUNTRY="${arg#*=}" ;;
            --address=*)         ADDRESS="${arg#*=}" ;;
            --city=*)             CITY="${arg#*=}" ;;
            --state=*)            STATE="${arg#*=}" ;;
            --zip=*)               ZIP="${arg#*=}" ;;
            --phone=*)              PHONE="${arg#*=}" ;;
            --company-email=*)   COMPANY_EMAIL="${arg#*=}" ;;
            --website=*)          WEBSITE="${arg#*=}" ;;
            --local-proxies=*)   LOCAL_PROXIES="${arg#*=}" ;;
            --cloudflare=*)      BEHIND_CLOUDFLARE="${arg#*=}" ;;
            --restore-from=*)              RESTORE_FROM="${arg#*=}" ;;
            --restore-passphrase-file=*)   RESTORE_PASSPHRASE_FILE="${arg#*=}" ;;
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

# add_opt_arg ARRAY_NAME FLAG VALUE — appends --FLAG=VALUE to the named
# array only when VALUE is non-empty. Deliberately not written as the more
# obvious `[[ -n "$value" ]] && arr+=(...)` — that bare form's exit status is
# 1 (false) whenever the condition doesn't hold, and under `set -e` a bare
# top-level `cond && action` statement with no trailing `|| ...` aborts the
# whole script the moment cond is false. Wrapping it in a real function with
# an `if` avoids that footgun at every call site below.
add_opt_arg() {
    local -n arr_ref="$1"
    local flag="$2" value="$3"
    if [[ -n "${value}" ]]; then
        local entry="--${flag}=${value}"
        arr_ref+=("${entry}")
    fi
}

validate_domain() {
    local d="$1"
    if [[ ! "${d}" =~ ^[a-zA-Z0-9.-]+$ ]]; then
        die "Invalid --domain '${d}': only letters, digits, '.' and '-' are allowed (this value is interpolated into a generated nginx config and shell commands)."
    fi
    if [[ "${d}" == .* || "${d}" == *. || "${d}" == -* || "${d}" == *- ]]; then
        die "Invalid --domain '${d}': must not start or end with '.' or '-'."
    fi
    if [[ "${d}" != *.* ]]; then
        warn "--domain '${d}' has no dot in it, which is unusual for a real hostname — proceeding anyway since it passes character validation."
    fi
}

# default_db_name(domain): lowercase the domain and replace every run of
# non-[a-z0-9] characters with a single underscore, trimming leading/
# trailing underscores. Guards against a leading digit (some MariaDB/MySQL
# tooling is unhappy with an unquoted identifier that starts with one) and
# caps the result at 32 characters, since this value doubles as the MariaDB
# username, not just the database name.
default_db_name() {
    local raw="$1" s
    s="$(printf '%s' "${raw}" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/_/g; s/_+/_/g; s/^_+//; s/_+$//')"
    if [[ -z "${s}" ]]; then
        s="rivetit"
    fi
    if [[ "${s}" =~ ^[0-9] ]]; then
        s="db_${s}"
    fi
    printf '%s' "${s:0:32}"
}

validate_restore_args() {
    if [[ -n "${RESTORE_FROM}" || -n "${RESTORE_PASSPHRASE_FILE}" ]]; then
        [[ -n "${RESTORE_FROM}" ]] || die "--restore-passphrase-file was given without --restore-from — both are required together."
        [[ -f "${RESTORE_FROM}" ]] || die "--restore-from '${RESTORE_FROM}' does not exist."

        # The in-app backup .zip's own bytes are never encrypted, unlike
        # deploy/backup.sh's backup-*.tar.gz.enc (restore.sh) - only the
        # latter unconditionally needs a passphrase to decrypt it. A .zip's
        # backup-manifest.json MAY still be passphrase-encrypted (whenever
        # an admin had one set when that backup was taken) - restore_admin_zip.sh
        # itself is what actually knows, and refuses clearly if that specific
        # backup needs one and none was given, so this check stays permissive
        # for .zip either way.
        if [[ "${RESTORE_FROM}" != *.zip && -z "${RESTORE_PASSPHRASE_FILE}" ]]; then
            die "--restore-from was given without --restore-passphrase-file — both are required together (unless --restore-from ends in .zip, which only sometimes needs one - see --help)."
        fi
        if [[ -n "${RESTORE_PASSPHRASE_FILE}" ]]; then
            [[ -f "${RESTORE_PASSPHRASE_FILE}" ]] || die "--restore-passphrase-file '${RESTORE_PASSPHRASE_FILE}' does not exist."
        fi
    fi
}

validate_non_interactive_requirements() {
    [[ "${NON_INTERACTIVE}" -eq 1 ]] || return 0

    local missing=()
    # Restoring a backup skips scripts/setup_cli.php's company/localization/
    # admin-user prompts entirely (run_app_restore uses --config-only
    # instead) — none of those flags apply, and requiring them here would
    # just be dead weight a restore-from-backup script has to pass anyway.
    if [[ -z "${RESTORE_FROM}" ]]; then
        [[ -n "${ADMIN_NAME}" ]]     || missing+=("--admin-name")
        [[ -n "${ADMIN_EMAIL}" ]]    || missing+=("--admin-email")
        [[ -n "${ADMIN_PASSWORD}" ]] || missing+=("--admin-password")
        [[ -n "${LOCALE}" ]]         || missing+=("--locale")
        [[ -n "${TIMEZONE}" ]]       || missing+=("--timezone")
        [[ -n "${CURRENCY}" ]]       || missing+=("--currency")
        [[ -n "${COMPANY_NAME}" ]]   || missing+=("--company-name")
        [[ -n "${COUNTRY}" ]]        || missing+=("--country")
    fi
    if [[ "${NEED_CERTBOT}" -eq 1 && -z "${CERTBOT_EMAIL}" ]]; then
        missing+=("--email")
    fi

    if [[ "${#missing[@]}" -gt 0 ]]; then
        die "--non-interactive requires the following flag(s), missing: ${missing[*]}"
    fi
}

# normalize_yes_no VALUE — prints 1 for yes-ish, 0 for no-ish, nothing (and returns 1) otherwise.
normalize_yes_no() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        y|yes|1|true|on)  echo 1 ;;
        n|no|0|false|off) echo 0 ;;
        *) return 1 ;;
    esac
}

validate_network_args() {
    if [[ -n "${LOCAL_PROXIES}" ]]; then
        [[ "${LOCAL_PROXIES}" =~ ^[0-9]+$ && "${LOCAL_PROXIES}" -le 10 ]] || die "--local-proxies must be a whole number from 0 to 10 (got: ${LOCAL_PROXIES})."
    fi
    if [[ -n "${BEHIND_CLOUDFLARE}" ]]; then
        BEHIND_CLOUDFLARE="$(normalize_yes_no "${BEHIND_CLOUDFLARE}")" || die "--cloudflare must be yes or no."
    fi
}

# Fills in LOCAL_PROXIES / BEHIND_CLOUDFLARE: asks on an interactive terminal, otherwise takes defaults.
collect_network_path() {
    local default_proxies=0
    [[ "${PROXY_MODE}" -eq 1 ]] && default_proxies=1
    if [[ -n "${LOCAL_PROXIES}" || -n "${BEHIND_CLOUDFLARE}" || ( "${NON_INTERACTIVE}" -eq 0 && -t 0 ) ]]; then
        NETWORK_EXPLICIT=1
    fi

    if [[ "${NON_INTERACTIVE}" -eq 0 && -t 0 ]]; then
        local answer=""
        if [[ -z "${LOCAL_PROXIES}" ]]; then
            while true; do
                read -r -p "How many reverse proxies on your side sit in front of this app (nginx, HAProxy, load balancer; not counting Cloudflare)? [${default_proxies}] " answer || true
                answer="${answer:-${default_proxies}}"
                if [[ "${answer}" =~ ^[0-9]+$ && "${answer}" -le 10 ]]; then
                    LOCAL_PROXIES="${answer}"
                    break
                fi
                echo "Please enter a whole number from 0 to 10."
            done
        fi
        if [[ -z "${BEHIND_CLOUDFLARE}" ]]; then
            while true; do
                read -r -p "Is Cloudflare in front of this app? [y/N] " answer || true
                if BEHIND_CLOUDFLARE="$(normalize_yes_no "${answer:-n}")"; then
                    break
                fi
                echo "Please answer yes or no."
            done
        fi
    fi

    LOCAL_PROXIES="${LOCAL_PROXIES:-${default_proxies}}"
    BEHIND_CLOUDFLARE="${BEHIND_CLOUDFLARE:-0}"
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    # Everything from here on is both shown on the terminal and appended to
    # the logfile. set -x traces every command into that same log — except
    # around the one line in run_app_setup() that carries the DB/admin
    # passwords, which explicitly disables tracing for that single command.
    exec > >(tee -a "${LOG_FILE}") 2>&1
    set -x
}

# ---------------------------------------------------------------------------
# Step 1: packages
# ---------------------------------------------------------------------------
apt_install_if_missing() {
    local pkg missing=()
    for pkg in "$@"; do
        package_installed "${pkg}" || missing+=("${pkg}")
    done
    if [[ "${#missing[@]}" -gt 0 ]]; then
        info "Installing packages: ${missing[*]}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
    else
        info "Requested packages already installed, skipping apt-get install for: $*"
    fi
}

install_packages() {
    info "Updating APT package index..."
    apt-get update -qq

    if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
        warn "php${PHP_VERSION} packages are not available from the currently configured APT repositories (Ubuntu 24.04's default repos ship PHP 8.3)."
        announce "Adding the ondrej/php PPA (ppa:ondrej/php) to provide PHP ${PHP_VERSION} packages."
        package_installed software-properties-common || apt-get install -y software-properties-common
        add-apt-repository -y ppa:ondrej/php
        apt-get update -qq
    fi

    apt_install_if_missing "${REQUIRED_BASE_PACKAGES[@]}" "${REQUIRED_PHP_PACKAGES[@]}"

    # Installing a package does not always start/enable it (and definitely
    # doesn't on a re-run where it was already installed) — make sure the
    # five services this instance needs are actually up before we lean on
    # them below.
    if ! service_is_active "php${PHP_VERSION}-fpm"; then
        systemctl enable --now "php${PHP_VERSION}-fpm"
    fi
    if ! service_is_active mariadb; then
        systemctl enable --now mariadb
    fi
    if ! service_is_active nginx; then
        systemctl enable --now nginx
    fi
    if ! service_is_active cron; then
        systemctl enable --now cron
    fi
}

# ---------------------------------------------------------------------------
# Step 2: application code
# ---------------------------------------------------------------------------
provision_app_code() {
    if [[ -f "${APP_DIR}/functions.php" && -f "${APP_DIR}/db.sql" ]]; then
        info "Application code already present at ${APP_DIR}; skipping clone/copy."
        return 0
    fi

    if [[ -e "${APP_DIR}" ]] && [[ -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
        die "${APP_DIR} already exists, is non-empty, and does not look like a RivetIT checkout (missing functions.php/db.sql). Refusing to overwrite it — remove it first or choose a different --app-dir."
    fi

    mkdir -p "$(dirname "${APP_DIR}")"

    if [[ -d "${REPO_ROOT}/.git" ]]; then
        # This exact file's own directory tree contains .git two levels up —
        # install.sh is running from inside an already-cloned checkout, so a
        # company that already has the code locally doesn't need network
        # access to GitHub. .git itself is deliberately copied along with
        # everything else so scripts/update_cli.php's `git pull` keeps
        # working against the new copy.
        info "Running from inside an existing checkout (${REPO_ROOT}); copying it to ${APP_DIR} instead of cloning from GitHub."
        mkdir -p "${APP_DIR}"
        # /uploads/ and /backups/ are EXCLUDED even though they may contain
        # real, non-git-tracked data: if ${REPO_ROOT} is itself a live
        # instance (e.g. an admin adding a second company by running
        # /var/www/company1.com/deploy/install.sh --domain=company2.com),
        # those two directories are where company1's actual client
        # files/contracts/ticket attachments and encrypted DB backups live —
        # copying them into a brand-new company's webroot would leak one
        # customer's data straight into another's install. setup_upload_dirs
        # (called right after this) recreates empty per-instance uploads/
        # subdirectories; backups/ is likewise recreated empty.
        rsync -a \
            --exclude='/config.php' \
            --exclude='/node_modules/' \
            --exclude='/.beta_db_pw' \
            --exclude='/keystore_base64.txt' \
            --exclude='/.claude/' \
            --exclude='/uploads/' \
            --exclude='/backups/' \
            "${REPO_ROOT}/" "${APP_DIR}/"
    else
        info "Cloning ${REPO_URL} (branch: ${REPO_BRANCH}) into ${APP_DIR}..."
        git clone --branch "${REPO_BRANCH}" --single-branch "${REPO_URL}" "${APP_DIR}"
    fi

    if [[ -f "${APP_DIR}/composer.json" ]] && command_exists composer; then
        info "Installing PHP dependencies via composer..."
        ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction )
    fi
}

setup_upload_dirs() {
    info "Ensuring uploads/ and backups/ directories exist..."
    local d
    for d in "${UPLOAD_SUBDIRS[@]}"; do
        mkdir -p "${APP_DIR}/uploads/${d}"
        # Placeholder that denies directory listing — already tracked in
        # git for every directory except uploads/contracts (see
        # .gitignore), so only create it when actually missing.
        [[ -f "${APP_DIR}/uploads/${d}/index.php" ]] || : > "${APP_DIR}/uploads/${d}/index.php"
    done
    mkdir -p "${APP_DIR}/backups"
}

set_file_permissions() {
    info "Setting ownership (www-data:www-data) and permissions under ${APP_DIR}..."
    chown -R www-data:www-data "${APP_DIR}"
    find "${APP_DIR}" -type d -exec chmod 750 {} +
    find "${APP_DIR}" -type f -exec chmod 640 {} +

    # Re-widen exactly what the app needs to write to or execute directly.
    chmod -R u+rwX,g+rwX "${APP_DIR}/uploads" "${APP_DIR}/backups"

    # u+x,g+x only (not a bare +x, which would also grant other-execute on
    # top of the 640 these files already have from the chmod above — a
    # readable-only-by-owner/group file gaining other-execute is harmless in
    # practice here since "other" still can't read it to run it, but it's
    # unintended and easy to avoid).
    if [[ -f "${APP_DIR}/scripts/setup_cli.php" ]]; then
        chmod u+x,g+x "${APP_DIR}/scripts/setup_cli.php"
    fi
    if [[ -f "${APP_DIR}/scripts/update_cli.php" ]]; then
        chmod u+x,g+x "${APP_DIR}/scripts/update_cli.php"
    fi
    if [[ -d "${APP_DIR}/deploy" ]]; then
        find "${APP_DIR}/deploy" -maxdepth 1 -name '*.sh' -exec chmod u+x,g+x {} +
    fi
    if [[ -d "${APP_DIR}/cron" ]]; then
        find "${APP_DIR}/cron" -maxdepth 1 -name '*.php' -exec chmod u+x,g+x {} +
    fi
    success "Ownership and permissions set."
}

# ---------------------------------------------------------------------------
# Step 3: database
# ---------------------------------------------------------------------------
provision_database() {
    # Once config.php exists, the app-level setup this DB feeds into
    # (run_app_setup, below) has either already completed or refuses to
    # run again — so re-provisioning here on a later re-run of this script
    # would do nothing useful and (worse, see the ALTER USER below) would
    # reset a live, in-use password out from under a working config.php.
    if [[ -f "${APP_DIR}/config.php" ]]; then
        info "config.php already exists in ${APP_DIR}; leaving the existing database/user/password as-is."
        return 0
    fi

    info "Provisioning MariaDB database and user '${DB_NAME}'..."

    local defaults_file
    defaults_file="$(mktemp)"
    chmod 600 "${defaults_file}"
    register_tmpfile "${defaults_file}"
    # Fresh Ubuntu/Debian mariadb-server installs authenticate root@localhost
    # via unix_socket (no password) when connected as the OS root user,
    # which install.sh already is (require_root). This file exists so the
    # invocation below never needs `-p<password>` on argv even if that
    # assumption ever stops holding on some box.
    cat > "${defaults_file}" <<'EOF'
[client]
user=root
EOF

    # Least privilege: SELECT/INSERT/UPDATE/DELETE for normal operation, plus
    # CREATE/ALTER/DROP/INDEX because the app's own versioned migration
    # runner (admin/database_updates.php, invoked via scripts/update_cli.php
    # --update_db) executes live schema changes as this same app-level user
    # — and because db.sql's own CREATE TABLE statements need them for this
    # initial import. Deliberately NOT granted: anything on *.*, and no
    # SUPER/FILE/PROCESS/GRANT OPTION. The user is bound to 'localhost' only.
    mysql --defaults-extra-file="${defaults_file}" <<SQL || die "Database/user provisioning failed for '${DB_NAME}'."
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_NAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
-- CREATE USER IF NOT EXISTS silently no-ops (and does NOT update the
-- password) when '${DB_NAME}'@'localhost' already exists from a prior
-- run that generated a DB_PASSWORD but died before config.php got
-- written (config.php not existing is what let us reach this point at
-- all — see the guard above) — so the freshly generated DB_PASSWORD
-- above and the user's actual stored password would otherwise silently
-- diverge. ALTER USER unconditionally syncs it to what config.php is
-- about to be written with, making this self-healing on retry.
ALTER USER '${DB_NAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE TEMPORARY TABLES ON \`${DB_NAME}\`.* TO '${DB_NAME}'@'localhost';
FLUSH PRIVILEGES;
SQL

    success "Database '${DB_NAME}' and user '${DB_NAME}'@'localhost' are ready."
}

# ---------------------------------------------------------------------------
# Step 4: certificates + nginx vhost
# ---------------------------------------------------------------------------
bootstrap_selfsigned_cert() {
    if [[ -f "${SSL_CERT_PATH}" && -f "${SSL_CERT_KEY_PATH}" ]]; then
        info "Certificate files already present at ${SSL_CERT_PATH} / ${SSL_CERT_KEY_PATH}; leaving them as-is."
        return 0
    fi

    # A cert must exist on disk before `nginx -t` will accept a vhost that
    # references it — including on the certbot path below, which needs a
    # config that ALREADY passes `nginx -t` before certbot can parse and
    # rewrite it. So this bootstrap cert is generated unconditionally, even
    # when certbot is about to replace it moments later: certbot's own run
    # then updates ssl_certificate/ssl_certificate_key in place and reloads
    # nginx itself. In --proxy-mode or --skip-tls, this cert is what
    # actually stays in service long-term, hence the 10-year validity.
    announce "Generating a self-signed certificate for ${DOMAIN} (10-year validity) as a bootstrap/fallback cert."
    mkdir -p "$(dirname "${SSL_CERT_KEY_PATH}")" "$(dirname "${SSL_CERT_PATH}")"
    openssl req -x509 -nodes -newkey rsa:2048 \
        -keyout "${SSL_CERT_KEY_PATH}" \
        -out "${SSL_CERT_PATH}" \
        -days 3650 \
        -subj "/CN=${DOMAIN}"
    chmod 600 "${SSL_CERT_KEY_PATH}"
    chmod 644 "${SSL_CERT_PATH}"
    chown root:root "${SSL_CERT_KEY_PATH}" "${SSL_CERT_PATH}"
}

render_nginx_vhost() {
    local nginx_template="${TEMPLATES_DIR}/nginx-vhost.conf.template"
    local vhost_available="/etc/nginx/sites-available/${DOMAIN}.conf"
    local vhost_enabled="/etc/nginx/sites-enabled/${DOMAIN}.conf"
    local rate_limit_conf="/etc/nginx/conf.d/itflow-rate-limit.conf"

    [[ -f "${nginx_template}" ]] || die "nginx vhost template not found at ${nginx_template} (expected alongside this script under deploy/templates/)."
    [[ -f /etc/nginx/snippets/fastcgi-php.conf ]] || die "Expected /etc/nginx/snippets/fastcgi-php.conf (normally installed by the nginx package) is missing."
    grep -q '# __PROXY_MODE_BLOCK__' "${nginx_template}" || die "nginx-vhost.conf.template is missing the '# __PROXY_MODE_BLOCK__' sentinel this script relies on to strip proxy-mode/direct-TLS blocks correctly. Refusing to render a possibly-mixed-mode vhost."

    # limit_req_zone must live in nginx's http{} context, not inside a
    # server{} block, so it's a separate top-level file shared by every
    # RivetIT vhost on this box — write it once, never twice (nginx refuses
    # to start if the same zone name is defined in two included files).
    if [[ ! -f "${rate_limit_conf}" ]]; then
        info "Writing shared rate-limit zone: ${rate_limit_conf}"
        # Deployed text: kept as it was before the RivetIT rename so boxes
        # built before and after it get the same file (see REBRANDING.md).
        cat > "${rate_limit_conf}" <<'EOF'
# Shared across every ITFlow-Internal-IT vhost on this box, written once by
# deploy/install.sh. Do not duplicate this zone name in another file under
# conf.d/ — nginx refuses to start if the same zone is defined twice.
limit_req_zone $binary_remote_addr zone=itflow_login:10m rate=5r/m;
EOF
    else
        info "Shared rate-limit zone already present at ${rate_limit_conf}; leaving as-is."
    fi

    backup_if_exists "${vhost_available}"

    info "Rendering nginx vhost for ${DOMAIN} (mode: $([[ "${PROXY_MODE}" -eq 1 ]] && echo proxy || echo direct-tls))..."
    local APP_ROOT="${APP_DIR}"
    export DOMAIN APP_ROOT PHP_SOCK SSL_CERT_PATH SSL_CERT_KEY_PATH
    # Restrict envsubst to exactly these placeholders. The template is
    # otherwise full of nginx's OWN $variables ($request_uri, $host, ...)
    # that must be left untouched, not substituted as if they were shell
    # environment variables — see the template's own header comment.
    envsubst '${DOMAIN} ${APP_ROOT} ${PHP_SOCK} ${SSL_CERT_PATH} ${SSL_CERT_KEY_PATH}' \
        < "${nginx_template}" > "${vhost_available}"

    # Strip the template's own leading documentation header (everything
    # before the first real `server {` line) before touching markers below.
    # Verified by hand: that header's prose explaining the marker
    # convention itself contains a line mentioning both
    # "# BEGIN DIRECT-TLS ONLY" and "# END DIRECT-TLS ONLY" together (as
    # inline-code documentation, not a real marker pair). GNU sed's
    # `/start/,/end/` range does not test the end-pattern against the same
    # line that matched the start-pattern, so applying the mode sed below
    # directly against the un-stripped file makes that single doc line open
    # a range that only closes at the REAL "# END DIRECT-TLS ONLY" marker
    # far below — silently deleting everything in between, including both
    # PROXY-MODE-ONLY blocks. Confirmed by rendering both modes and diffing
    # against the un-stripped output before adding this line.
    sed -i '1,/^server {/{ /^server {/!d }' "${vhost_available}"

    # PROXY MODE vs DIRECT-TLS MODE — the template documents this exact
    # sed contract in its own header comment; kept in sync with it here.
    if [[ "${PROXY_MODE}" -eq 1 ]]; then
        sed -i '/# BEGIN DIRECT-TLS ONLY/,/# END DIRECT-TLS ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN PROXY-MODE ONLY/d; /# END PROXY-MODE ONLY/d' "${vhost_available}"
    else
        sed -i '/# BEGIN PROXY-MODE ONLY/,/# END PROXY-MODE ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN DIRECT-TLS ONLY/d; /# END DIRECT-TLS ONLY/d' "${vhost_available}"
    fi

    ln -sf "${vhost_available}" "${vhost_enabled}"

    local nginx_test_log
    nginx_test_log="$(mktemp)"
    if ! nginx -t >"${nginx_test_log}" 2>&1; then
        error "nginx -t failed after rendering ${vhost_available}:"
        cat "${nginx_test_log}" >&2
        rm -f "${vhost_enabled}" "${nginx_test_log}"
        die "Refusing to leave a broken site enabled. ${vhost_available} was left in place for inspection; its sites-enabled symlink was removed."
    fi
    rm -f "${nginx_test_log}"

    announce "Reloading nginx to activate the vhost for ${DOMAIN}."
    systemctl reload nginx
    success "nginx vhost for ${DOMAIN} is live."
}

maybe_run_certbot() {
    if [[ "${NEED_CERTBOT}" -eq 0 ]]; then
        if [[ "${PROXY_MODE}" -eq 1 ]]; then
            info "Proxy mode: skipping certbot. The public TLS endpoint is expected to be terminated by the upstream reverse proxy; this box serves the self-signed backend cert generated above on :8443."
        else
            warn "Skipping certbot (--skip-tls). ${DOMAIN} is currently serving a self-signed certificate that browsers will not trust. Run 'certbot --nginx -d ${DOMAIN}' manually once DNS/networking is ready, or re-run this installer without --skip-tls."
        fi
        return 0
    fi

    announce "Requesting a Let's Encrypt certificate for ${DOMAIN} via certbot (contact: ${CERTBOT_EMAIL})."
    certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${CERTBOT_EMAIL}" --redirect
    success "Let's Encrypt certificate installed for ${DOMAIN}."
}

# ---------------------------------------------------------------------------
# Step 5: PHP-FPM / MariaDB hardening drop-ins
# ---------------------------------------------------------------------------
apply_php_hardening() {
    local src="${TEMPLATES_DIR}/php-hardening.ini"
    local dst="/etc/php/${PHP_VERSION}/fpm/conf.d/99-itflow-hardening.ini"

    if [[ ! -f "${src}" ]]; then
        warn "PHP hardening template not found at ${src}; skipping PHP-FPM hardening."
        return 0
    fi

    # On a box that already runs another RivetIT instance, this template is
    # identical every time — skip the restart entirely rather than bouncing
    # php${PHP_VERSION}-fpm (and every other company's in-flight requests on it) for a
    # no-op file write.
    if [[ -f "${dst}" ]] && cmp -s "${src}" "${dst}"; then
        info "PHP-FPM hardening already up to date at ${dst}; not restarting php${PHP_VERSION}-fpm."
        return 0
    fi

    backup_if_exists "${dst}"
    cp "${src}" "${dst}"
    chmod 644 "${dst}"
    chown root:root "${dst}"

    if ! "php-fpm${PHP_VERSION}" -t; then
        die "php-fpm${PHP_VERSION} -t failed after installing ${dst}. Not restarting php${PHP_VERSION}-fpm with a config that fails to validate — inspect the file and re-run."
    fi

    announce "Restarting php${PHP_VERSION}-fpm to apply hardening settings."
    if ! systemctl restart "php${PHP_VERSION}-fpm"; then
        error "php${PHP_VERSION}-fpm failed to restart after applying ${dst}; rolling back."
        rm -f "${dst}"
        systemctl restart "php${PHP_VERSION}-fpm" || die "php${PHP_VERSION}-fpm did not come back up even after rollback — manual intervention required."
        die "Rolled back ${dst}; php${PHP_VERSION}-fpm is back on its previous config. Investigate before re-applying hardening."
    fi
    if ! service_is_active "php${PHP_VERSION}-fpm"; then
        die "php${PHP_VERSION}-fpm is not active after restart even though the restart command itself succeeded — investigate manually."
    fi
    success "PHP-FPM hardening applied and php${PHP_VERSION}-fpm restarted."
}

apply_mariadb_hardening() {
    local src="${TEMPLATES_DIR}/mariadb-hardening.cnf"
    local dst="/etc/mysql/mariadb.conf.d/99-itflow-hardening.cnf"

    if [[ ! -f "${src}" ]]; then
        warn "MariaDB hardening template not found at ${src}; skipping."
        return 0
    fi

    # Same reasoning as apply_php_hardening: an unchanged file on a
    # multi-instance box must never trigger `systemctl restart mariadb`,
    # which drops every other company's live DB connections on this host.
    if [[ -f "${dst}" ]] && cmp -s "${src}" "${dst}"; then
        info "MariaDB hardening already up to date at ${dst}; not restarting mariadb."
        return 0
    fi

    backup_if_exists "${dst}"
    cp "${src}" "${dst}"
    chmod 644 "${dst}"
    chown root:root "${dst}"

    announce "Restarting mariadb to apply hardening settings."
    if ! systemctl restart mariadb; then
        error "mariadb failed to restart after applying ${dst}; rolling back."
        rm -f "${dst}"
        systemctl restart mariadb || die "mariadb did not come back up even after rollback — manual intervention required."
        die "Rolled back ${dst}; mariadb is back on its previous config (this box's data is otherwise untouched). Investigate before re-applying hardening."
    fi
    if ! service_is_active mariadb; then
        die "mariadb is not active after restart even though the restart command itself succeeded — investigate manually."
    fi
    success "MariaDB hardening applied and mariadb restarted."
}

# ---------------------------------------------------------------------------
# Step 6: firewall / fail2ban
# ---------------------------------------------------------------------------
configure_firewall() {
    if [[ "${SKIP_FIREWALL}" -eq 1 ]]; then
        info "Skipping firewall configuration (--skip-firewall)."
        return 0
    fi

    local ssh_port
    ssh_port="$(detect_ssh_port)"

    # SSH must be allowed BEFORE `ufw --force enable`, unconditionally — a
    # default-deny-incoming firewall with no SSH rule yet would lock out the
    # very session running this installer.
    announce "Allowing SSH (port ${ssh_port}/tcp) through ufw before enabling it."
    ufw allow "${ssh_port}/tcp" comment 'SSH'

    announce "Allowing HTTP/HTTPS (80,443/tcp) through ufw for ${DOMAIN}."
    ufw allow 'Nginx Full'

    if ufw status | grep -q '^Status: active'; then
        info "ufw is already active (rules from a prior instance on this box, if any, are preserved) — the allow rules above were added to it."
    else
        announce "Enabling ufw (default-deny incoming; SSH and HTTP/HTTPS are already allowed above)."
        ufw --force enable
    fi
    success "Firewall rules applied."
}

configure_fail2ban() {
    if [[ "${SKIP_FAIL2BAN}" -eq 1 ]]; then
        info "Skipping fail2ban configuration (--skip-fail2ban)."
        return 0
    fi

    local src="${TEMPLATES_DIR}/jail-itflow.local"
    local dst="/etc/fail2ban/jail.d/itflow.local"
    local filter_dst="/etc/fail2ban/filter.d/itflow-auth.conf"

    if [[ ! -f "${src}" ]]; then
        warn "fail2ban jail template not found at ${src}; skipping fail2ban configuration."
        return 0
    fi

    # Same reasoning as the PHP/MariaDB hardening steps: don't restart
    # fail2ban (and drop its current ban-state tracking) for a no-op
    # re-application of an already-identical jail on a multi-instance box.
    if [[ -f "${dst}" ]] && cmp -s "${src}" "${dst}" && [[ -f "${filter_dst}" ]]; then
        info "fail2ban jail already up to date at ${dst}; not restarting fail2ban."
        return 0
    fi

    backup_if_exists "${dst}"
    cp "${src}" "${dst}"
    chmod 644 "${dst}"
    chown root:root "${dst}"

    # The [itflow-auth] jail in jail-itflow.local references filter =
    # itflow-auth, which must exist under filter.d/ or fail2ban refuses to
    # start — jail.d/ and filter.d/ are separate directories, so the jail
    # file's own header comment documents this exact content rather than
    # carrying it itself; reproduced here so the two halves stay in sync.
    if [[ ! -f "${filter_dst}" ]]; then
        cat > "${filter_dst}" <<'EOF'
[Definition]
failregex = ^<HOST> .* "POST /(login|post)\.php[^"]*" (401|403|429)
ignoreregex =
EOF
        chmod 644 "${filter_dst}"
        chown root:root "${filter_dst}"
    fi

    announce "Restarting fail2ban to apply the sshd and itflow-auth jails."
    if ! systemctl restart fail2ban; then
        error "fail2ban failed to restart after applying ${dst}; rolling back."
        rm -f "${dst}"
        systemctl restart fail2ban || die "fail2ban did not come back up even after rollback — manual intervention required."
        die "Rolled back ${dst}; fail2ban is back on its previous config. Investigate before re-applying."
    fi
    success "fail2ban configured (sshd + itflow-auth jails)."
}

# ---------------------------------------------------------------------------
# Step 7: cron
# ---------------------------------------------------------------------------
cron_job_exists_for_app() {
    local target="$1" entry
    for entry in /etc/cron.d/*; do
        [[ -f "${entry}" && "${entry##*/}" =~ ^[A-Za-z0-9_-]+$ ]] || continue
        if awk -v target="${target}" '$0 !~ /^[[:space:]]*#/ && index($0, target) { found=1; exit } END { exit !found }' "${entry}"; then
            return 0
        fi
    done
    return 1
}

ensure_cron_job() {
    local cron_file="$1" app_root="$2" script="$3" schedule="$4" log="$5" args="${6:-}"
    local target="${app_root}/cron/${script}${args:+ ${args}}"
    if ! cron_job_exists_for_app "${target}"; then
        printf '%s www-data /usr/bin/php %s/cron/%s%s >> %s 2>&1\n' \
            "${schedule}" "${app_root}" "${script}" "${args:+ ${args}}" "${log}" >> "${cron_file}"
    fi
}

install_cron_entry() {
    local safe_name cron_file cron_log mail_log parser_log backup_log training_log metrics_log sync_log refresh_log legacy_file existing_file
    safe_name="$(printf '%s' "${DOMAIN}" | tr -c 'a-zA-Z0-9' '-')"
    cron_file="/etc/cron.d/rivetit-${safe_name}"
    legacy_file="/etc/cron.d/itflow-${safe_name}"
    cron_log="/var/log/rivetit-cron-${safe_name}.log"
    mail_log="/var/log/rivetit-mail-${safe_name}.log"
    parser_log="/var/log/rivetit-mail-parser-${safe_name}.log"
    backup_log="/var/log/rivetit-backup-${safe_name}.log"
    training_log="/var/log/rivetit-training-${safe_name}.log"
    metrics_log="/var/log/rivetit-metrics-${safe_name}.log"
    sync_log="/var/log/rivetit-sync-${safe_name}.log"
    refresh_log="/var/log/rivetit-refresh-${safe_name}.log"

    command_exists cron || die "cron is not installed. Install the cron package and rerun the installer."
    if ! service_is_active cron; then
        systemctl enable --now cron || die "Could not start the cron service; no scheduled jobs will run."
    fi

    # An existing shared installation may deliberately use only selected
    # jobs. Do not silently add the full dispatcher on a rerun; it can send
    # duplicate mail when another instance shares client/SMTP data.
    if [[ -f "${APP_DIR}/config.php" && ! -f "${cron_file}" ]] &&
       ! { [[ -f "${legacy_file}" ]] && grep -Fq -- "${APP_DIR}/cron/cron.php" "${legacy_file}"; }; then
        for existing_file in /etc/cron.d/*; do
            [[ -f "${existing_file}" && "${existing_file##*/}" =~ ^[A-Za-z0-9_-]+$ ]] || continue
            if grep -Fq -- "/usr/bin/php ${APP_DIR}/cron/" "${existing_file}"; then
                warn "Existing jobs already target ${APP_DIR}; leaving the full cron/cron.php job absent. Review mail and integration effects before adding it manually."
                return 0
            fi
        done
    fi

    # Cron runs commands as www-data, including their shell redirections.
    # Without writable logs the shell fails before PHP even starts.
    for existing_file in "${cron_log}" "${mail_log}" "${parser_log}" "${backup_log}" "${training_log}" "${metrics_log}" "${sync_log}" "${refresh_log}"; do
        touch "${existing_file}"
        chown www-data:www-data "${existing_file}"
        chmod 640 "${existing_file}"
    done

    # Keep an administrator's existing schedule when the installer is rerun.
    if [[ -f "${cron_file}" ]] && [[ "$(grep -Fc -- "${APP_DIR}/cron/cron.php" "${cron_file}" || true)" -eq 1 ]]; then
        info "Preserving existing cron schedule in ${cron_file}."
    else
        backup_if_exists "${cron_file}"
        cat > "${cron_file}" <<EOF
# Managed by deploy/install.sh for the RivetIT instance at
# ${DOMAIN} (${APP_DIR}). This fires every 5 minutes unconditionally —
# whether cron/cron.php actually does anything is gated by the app's own
# "config_enable_cron" setting (a DB row, defaulting to OFF). An admin must
# turn it on from within the app (Settings) before this has any effect.
# The OS schedule below controls frequency; the app setting only enables or
# disables the job's work.
*/5 * * * * www-data /usr/bin/php ${APP_DIR}/cron/cron.php >> ${cron_log} 2>&1
EOF
    fi
    # An earlier installer used itflow-<domain>. Retire that file before
    # checking for companion jobs so entries in the old file are recreated
    # under the RivetIT name instead of disappearing with the old file.
    if [[ -f "${legacy_file}" ]]; then
        if grep -Fq -- "${APP_DIR}/cron/cron.php" "${legacy_file}"; then
            backup_if_exists "${legacy_file}"
            rm -f "${legacy_file}"
        else
            warn "Leaving unrelated legacy cron file untouched: ${legacy_file}"
        fi
    fi
    # Add standalone jobs once. Each script checks its own feature setting;
    # modules that are off remain idle until an admin enables them.
    ensure_cron_job "${cron_file}" "${APP_DIR}" mail_queue.php '*/5 * * * *' "${mail_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" ticket_email_parser.php '*/5 * * * *' "${parser_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" backup_cron.php '7 * * * *' "${backup_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" metrics_collect.php '*/5 * * * *' "${metrics_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" outlook_schedule_sync.php '*/15 * * * *' "${sync_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" domain_refresher.php '0 3 * * *' "${refresh_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" certificate_refresher.php '0 4 * * *' "${refresh_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" odoo_sync_cron.php '30 4 * * *' "${training_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" training_cron.php '15 5 * * *' "${training_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" training_kiosk_cron.php '0-59/10 * * * *' "${training_log}"
    ensure_cron_job "${cron_file}" "${APP_DIR}" training_worker.php '*/10 * * * *' "${training_log}" '--task=odoo'
    ensure_cron_job "${cron_file}" "${APP_DIR}" training_worker.php '40 5 * * *' "${training_log}" '--task=daily'
    chmod 644 "${cron_file}"
    chown root:root "${cron_file}"
    success "Installed system cron entry: ${cron_file} (log: ${cron_log})"
}

install_cron_manager_helper() {
    local safe_name config_file config_temp sudoers_file sudoers_temp
    safe_name="$(printf '%s' "${DOMAIN}" | tr -c 'a-zA-Z0-9' '-')"
    config_file="/etc/rivetit/cron-manager-${safe_name}.json"
    sudoers_file="/etc/sudoers.d/rivetit-cron-${safe_name}"

    install -o root -g root -m 0755 "${APP_DIR}/deploy/cron_schedule.py" /usr/local/sbin/rivetit-cron-schedule
    install -d -o root -g root -m 0755 /etc/rivetit
    config_temp="$(mktemp)"
    python3 - "${APP_DIR}" > "${config_temp}" <<'PY'
import json
import os
import sys
print(json.dumps({'app_root': os.path.realpath(sys.argv[1])}))
PY
    install -o root -g root -m 0644 "${config_temp}" "${config_file}"
    rm -f "${config_temp}"

    sudoers_temp="$(mktemp)"
    printf 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/rivetit-cron-schedule --set %s *, /usr/local/sbin/rivetit-cron-schedule --check %s *\n' "${safe_name}" "${safe_name}" > "${sudoers_temp}"
    chmod 0440 "${sudoers_temp}"
    visudo -cf "${sudoers_temp}" || die "Invalid Cron Manager sudoers rule"
    install -o root -g root -m 0440 "${sudoers_temp}" "${sudoers_file}"
    rm -f "${sudoers_temp}"
    success "Cron Manager can edit schedules for ${DOMAIN}."
}

# ---------------------------------------------------------------------------
# Step 8: application-level setup (scripts/setup_cli.php)
# ---------------------------------------------------------------------------
run_app_setup() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        info "config.php already exists in ${APP_DIR}; scripts/setup_cli.php refuses to run again. Skipping app-level setup (already completed by a previous run)."
        return 0
    fi

    info "Running the application's own CLI installer (scripts/setup_cli.php) as www-data..."

    local -a setup_args=(
        --host=localhost
        --username="${DB_NAME}"
        --database="${DB_NAME}"
        --base-url="${DOMAIN}"
    )
    add_opt_arg setup_args locale "${LOCALE}"
    add_opt_arg setup_args timezone "${TIMEZONE}"
    add_opt_arg setup_args currency "${CURRENCY}"
    add_opt_arg setup_args company-name "${COMPANY_NAME}"
    add_opt_arg setup_args country "${COUNTRY}"
    add_opt_arg setup_args address "${ADDRESS}"
    add_opt_arg setup_args city "${CITY}"
    add_opt_arg setup_args state "${STATE}"
    add_opt_arg setup_args zip "${ZIP}"
    add_opt_arg setup_args phone "${PHONE}"
    add_opt_arg setup_args company-email "${COMPANY_EMAIL}"
    add_opt_arg setup_args website "${WEBSITE}"
    add_opt_arg setup_args user-name "${ADMIN_NAME}"
    add_opt_arg setup_args user-email "${ADMIN_EMAIL}"

    if [[ -n "${ADMIN_PASSWORD}" ]]; then
        warn "--admin-password was supplied: it is passed to setup_cli.php via the RIVETIT_ADMIN_PASSWORD environment variable (not argv), so it never appears in 'ps' output — but it did appear on THIS script's own command line/shell history. Omit --admin-password (and --non-interactive) whenever you have an interactive terminal, and answer setup_cli.php's prompt instead, which never touches argv, env, or history."
    fi
    if [[ "${NON_INTERACTIVE}" -eq 1 ]]; then
        setup_args+=(--non-interactive)
    fi

    # setup_cli.php (patched alongside this installer) reads the DB and admin
    # passwords from RIVETIT_DB_PASSWORD / RIVETIT_ADMIN_PASSWORD in preference
    # to --password/--user-password on argv, so neither secret is ever passed
    # as a command-line argument here — env vars are invisible to `ps` and to
    # any other local user without root/ptrace access to this process's
    # /proc/<pid>/environ (root can always read it, but root can read
    # everything anyway). Deliberately NOT setting --password/--user-password
    # as a fallback: doing so would silently reintroduce the exact argv
    # exposure this exists to avoid the moment either env var were ever unset.
    # ITFLOW_DB_PASSWORD / ITFLOW_ADMIN_PASSWORD are the deprecated names of the
    # same variables (setup_cli.php still honours them); they are set too, so
    # an older setup_cli.php in an already-installed app directory still works.

    # Never let `set -x` echo DB_PASSWORD/ADMIN_PASSWORD into the install
    # log — disable tracing for exactly this one invocation, nothing else.
    set +x
    local setup_status=0
    if ( cd "${APP_DIR}/scripts" && sudo -u www-data env RIVETIT_DB_PASSWORD="${DB_PASSWORD}" RIVETIT_ADMIN_PASSWORD="${ADMIN_PASSWORD}" ITFLOW_DB_PASSWORD="${DB_PASSWORD}" ITFLOW_ADMIN_PASSWORD="${ADMIN_PASSWORD}" php setup_cli.php "${setup_args[@]}" ); then
        setup_status=0
    else
        setup_status=$?
    fi
    set -x

    if [[ "${setup_status}" -ne 0 ]]; then
        die "scripts/setup_cli.php exited with status ${setup_status}. See its output above (no secret from this run was written to ${LOG_FILE})."
    fi

    if [[ -f "${APP_DIR}/config.php" ]]; then
        chmod 640 "${APP_DIR}/config.php"
        chown www-data:www-data "${APP_DIR}/config.php"
    fi
    success "Application setup complete."
}

# run_app_restore(): the --restore-from counterpart to run_app_setup() —
# writes config.php via setup_cli.php --config-only (no schema import, no
# admin user/company created, since the backup already has all of that),
# then hands off to deploy/restore.sh (backup-*.tar.gz.enc) or
# deploy/restore_admin_zip.sh (an in-app *.zip, detected by extension) to
# import it. restore_admin_zip.sh's other auth mode, --admin-user (an
# existing Administrator's login as a fallback when the backup passphrase is
# lost), never applies here - this function only ever restores onto a box
# that JUST had config.php written moments ago, with no existing admin
# account of its own to authenticate against yet; --passphrase-file is the
# only mode a from-scratch install can use. Only config_enable_setup is left
# for THIS function to set afterward:
# setup_cli.php --config-only
# deliberately does not set it, so a restore that fails partway still leaves
# the box bouncing to /setup for a retry instead of claiming to be ready.
run_app_restore() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        info "config.php already exists in ${APP_DIR}; skipping restore (already completed by a previous run)."
        return 0
    fi

    info "Writing config.php for the restore target (scripts/setup_cli.php --config-only)..."
    set +x
    # --non-interactive unconditionally: every value --config-only needs
    # (host/username/database/base-url via flags, password via the env var
    # below) is always already known here, so there is no legitimate prompt
    # to wait on — only a risk of silently hanging on STDIN if that were
    # ever untrue in an unattended run.
    if ! ( cd "${APP_DIR}/scripts" && sudo -u www-data env RIVETIT_DB_PASSWORD="${DB_PASSWORD}" ITFLOW_DB_PASSWORD="${DB_PASSWORD}" php setup_cli.php \
        --config-only --non-interactive --host=localhost --username="${DB_NAME}" --database="${DB_NAME}" --base-url="${DOMAIN}" ); then
        set -x
        die "scripts/setup_cli.php --config-only failed. See its output above."
    fi
    set -x
    if [[ -f "${APP_DIR}/config.php" ]]; then
        chmod 640 "${APP_DIR}/config.php"
        chown www-data:www-data "${APP_DIR}/config.php"
    fi

    # --no-pre-restore-backup-confirmed on both branches below: config.php
    # was just written above against a database provision_database() created
    # moments ago in this same run — there is nothing yet in it worth a
    # safety backup of.
    if [[ "${RESTORE_FROM}" == *.zip ]]; then
        announce "Restoring ${RESTORE_FROM} into ${APP_DIR} (deploy/restore_admin_zip.sh)..."
        local -a zip_restore_args=(--app-dir="${APP_DIR}" --backup="${RESTORE_FROM}" --confirm-restore --no-pre-restore-backup-confirmed)
        [[ -n "${RESTORE_PASSPHRASE_FILE}" ]] && zip_restore_args+=(--passphrase-file="${RESTORE_PASSPHRASE_FILE}")
        if ! "${SCRIPT_DIR}/restore_admin_zip.sh" "${zip_restore_args[@]}"; then
            die "deploy/restore_admin_zip.sh failed. See its output above; ${APP_DIR}/config.php exists but config_enable_setup was NOT disabled, so it still bounces to /setup — fix the failure and re-run restore_admin_zip.sh directly (this installer refuses to re-run app-level setup once config.php exists). If it refused for lacking a passphrase, that specific backup had one set when it was taken - re-run with --restore-passphrase-file=<path>."
        fi
    else
        announce "Restoring ${RESTORE_FROM} into ${APP_DIR} (deploy/restore.sh)..."
        if ! "${SCRIPT_DIR}/restore.sh" --app-dir="${APP_DIR}" --backup="${RESTORE_FROM}" \
            --passphrase-file="${RESTORE_PASSPHRASE_FILE}" --confirm-restore \
            --no-pre-restore-backup-confirmed; then
            die "deploy/restore.sh failed. See its output above; ${APP_DIR}/config.php exists but config_enable_setup was NOT disabled, so it still bounces to /setup — fix the failure and re-run restore.sh directly (this installer refuses to re-run app-level setup once config.php exists)."
        fi
    fi

    # Mirrors setup_cli.php's own finalize step, and only reached once the
    # restore above actually succeeded. Neither restore_admin_zip.sh nor
    # restore.sh sets this line themselves (it's an app-level concept, not a
    # backup/restore one) — kept unconditional for both branches so this
    # function has exactly one "did the restore finish" contract regardless
    # of which one ran.
    local config_file="${APP_DIR}/config.php"
    if ! grep -q '^\$config_enable_setup = 0;' "${config_file}"; then
        printf '$config_enable_setup = 0;\n\n' | sudo -u www-data tee -a "${config_file}" >/dev/null
    fi
    success "Restore complete; ${DOMAIN} is ready to log in with the restored data."
}

# run_db_migrations(): scripts/update_cli.php --update_db applies ONE version step per call, so repeat it
# until the database reports the latest version (or stops advancing). Needed after a fresh install (db.sql is
# an older schema snapshot) and after a restore (the backup may be older than this code).
run_db_migrations() {
    info "Bringing the database schema up to date (scripts/update_cli.php --update_db)..."
    local i out
    for (( i = 1; i <= 200; i++ )); do
        if ! out="$( cd "${APP_DIR}/scripts" && sudo -u www-data php update_cli.php --update_db 2>&1 )"; then
            warn "update_cli.php --update_db failed: ${out}"
            return 1
        fi
        if grep -q "already at the latest version" <<<"${out}"; then
            success "Database schema is current."
            return 0
        fi
    done
    warn "Database schema still not current after 200 update steps; run scripts/update_cli.php --update_db manually and check for errors."
    return 1
}

# apply_network_settings(): writes the proxy count / Cloudflare answers into the app's settings.
apply_network_settings() {
    info "Recording network path: ${LOCAL_PROXIES} local reverse prox$([[ "${LOCAL_PROXIES}" -eq 1 ]] && echo y || echo ies), Cloudflare $([[ "${BEHIND_CLOUDFLARE}" -eq 1 ]] && echo yes || echo no)..."
    if ! ( cd "${APP_DIR}/scripts" && sudo -u www-data php set_network_cli.php --proxies="${LOCAL_PROXIES}" --cloudflare="$([[ "${BEHIND_CLOUDFLARE}" -eq 1 ]] && echo yes || echo no)" ); then
        warn "Could not save the network path. Set it later in Admin > Security > Network path."
        return 0
    fi
    success "Network path saved (change it any time in Admin > Security)."
}

# ---------------------------------------------------------------------------
# Final summary
# ---------------------------------------------------------------------------
print_summary() {
    local mode_desc
    if [[ "${PROXY_MODE}" -eq 1 ]]; then
        mode_desc="reverse-proxy backend (self-signed cert on :8443 — a proxy in front of this box owns the public TLS endpoint)"
    else
        mode_desc="direct TLS"
    fi

    cat <<EOF

=============================================================================
  RivetIT installed for ${DOMAIN}
=============================================================================

  URL:              https://${DOMAIN}/
  App directory:    ${APP_DIR}
  Database:         ${DB_NAME} (user: ${DB_NAME}@localhost)
  Mode:             ${mode_desc}
  Network path:     ${LOCAL_PROXIES} local reverse prox$([[ "${LOCAL_PROXIES}" -eq 1 ]] && echo y || echo ies), Cloudflare $([[ "${BEHIND_CLOUDFLARE}" -eq 1 ]] && echo yes || echo no)
                    (change in Admin > Security > Network path; its self-check shows
                    the client address the app sees)

  The generated database password was written straight into
  ${APP_DIR}/config.php by scripts/setup_cli.php and was never printed to
  this terminal or to ${LOG_FILE}. It's readable there by root and
  www-data only if you need it again.

  NEXT STEPS
  ----------
  1. $([[ -n "${RESTORE_FROM}" ]] && echo "Log in at the URL above with an account from the restored backup (${RESTORE_FROM})." || echo "Log in at the URL above with the admin account you just created (or were prompted to create).")
  2. The main job in /etc/cron.d/rivetit-${DOMAIN//./-} fires every 5 minutes, but cron/cron.php
     does nothing until you turn on "Enable Cron" in Settings inside the
     app — it defaults to off. Edit the root-owned cron file to change
     frequency. Cron Manager lists this instance's jobs and lets admins edit
     their schedules from the web UI.
  3. See deploy/README.md for updates and backups.
  4. See docs/ISO27001-COMPLIANCE.md for the full Annex A control mapping
     this deployment supports.

  ANOTHER COMPANY?
  -----------------
  Re-run this exact script with a different --domain (and, if sharing this
  box, a different --db-name) to add another company's independent
  instance alongside this one. Already-installed packages and an
  already-active ufw/fail2ban are detected and left alone.

=============================================================================
EOF
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    parse_args "$@"

    if [[ -z "${DOMAIN}" ]]; then
        print_help
        die "--domain is required."
    fi
    validate_domain "${DOMAIN}"

    require_root "$@"
    detect_os

    if [[ -z "${APP_DIR}" ]]; then
        APP_DIR="/var/www/${DOMAIN}"
    fi
    [[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path (got: ${APP_DIR})"

    if [[ -n "${DB_NAME}" ]]; then
        [[ "${DB_NAME}" =~ ^[a-z][a-z0-9_]{0,31}$ ]] || die "--db-name must start with a lowercase letter and contain only [a-z0-9_], max 32 characters (it also becomes the MariaDB username)."
    else
        DB_NAME="$(default_db_name "${DOMAIN}")"
    fi

    NEED_CERTBOT=0
    if [[ "${PROXY_MODE}" -eq 0 && "${SKIP_TLS}" -eq 0 ]]; then
        NEED_CERTBOT=1
    fi
    SSL_CERT_PATH="/etc/ssl/certs/${DOMAIN}.crt"
    SSL_CERT_KEY_PATH="/etc/ssl/private/${DOMAIN}.key"

    validate_restore_args
    validate_non_interactive_requirements
    validate_network_args
    collect_network_path

    setup_logging

    info "=== RivetIT installer starting for ${DOMAIN} ==="
    info "App directory: ${APP_DIR}"
    info "Database name / user: ${DB_NAME}"
    info "Mode: $([[ "${PROXY_MODE}" -eq 1 ]] && echo 'reverse-proxy backend (self-signed cert)' || echo 'direct TLS')"
    info "Network path: ${LOCAL_PROXIES} local reverse prox$([[ "${LOCAL_PROXIES}" -eq 1 ]] && echo y || echo ies), Cloudflare $([[ "${BEHIND_CLOUDFLARE}" -eq 1 ]] && echo yes || echo no)"

    if [[ "${SKIP_DEPENDENCIES}" -eq 1 ]]; then
        info "Skipping dependency installation (--skip-dependencies): nginx, PHP ${PHP_VERSION}, MariaDB, Redis, and cron must already be installed and available."
    elif [[ "${NON_INTERACTIVE}" -eq 1 ]]; then
        install_packages
    else
        # An interactive run always asks, even with other flags supplied — this is a genuine
        # system-level change (adds a PPA, runs apt-get install, enables/starts services) that
        # --skip-firewall/--skip-fail2ban's silent-flag convention doesn't fit as well: those
        # only affect THIS instance, installing packages affects the whole box. Default answer
        # is yes (a bare Enter installs) since that is what a genuinely fresh box needs.
        install_deps_answer=""
        read -r -p "Install dependencies (nginx, PHP ${PHP_VERSION}, MariaDB, Redis, cron)? [Y/n] " install_deps_answer || true
        case "${install_deps_answer}" in
            [nN]*)
                info "Skipping dependency installation (answered no): nginx, PHP ${PHP_VERSION}, MariaDB, Redis, and cron must already be installed and available."
                ;;
            *)
                install_packages
                ;;
        esac
    fi
    provision_app_code
    setup_upload_dirs
    set_file_permissions

    # set -x is already active here (setup_logging above) — disable tracing
    # for exactly this assignment so the secret never lands in the terminal
    # or in ${LOG_FILE} as a "+ DB_PASSWORD=..." trace line.
    set +x
    DB_PASSWORD="$(gen_secret 32)"
    set -x
    ensure_rivetit_redis "${SCRIPT_DIR}/templates"
    provision_database

    bootstrap_selfsigned_cert
    render_nginx_vhost
    maybe_run_certbot

    apply_php_hardening
    apply_mariadb_hardening

    configure_firewall
    configure_fail2ban

    install_cron_entry
    install_cron_manager_helper
    local fresh_app=0
    [[ -f "${APP_DIR}/config.php" ]] || fresh_app=1
    if [[ -n "${RESTORE_FROM}" ]]; then
        run_app_restore
    else
        run_app_setup
    fi
    run_db_migrations || true
    if [[ "${fresh_app}" -eq 1 || "${NETWORK_EXPLICIT}" -eq 1 ]]; then
        apply_network_settings
    else
        info "Existing install and no --local-proxies/--cloudflare given: leaving the saved network path unchanged."
    fi

    set +x
    print_summary
}

main "$@"
