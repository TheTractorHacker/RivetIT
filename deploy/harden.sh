#!/usr/bin/env bash
set -euo pipefail

# ITFlow-Internal-IT box hardening.
#
# Applies deploy/templates/{php-hardening.ini,mariadb-hardening.cnf,
# jail-itflow.local,nginx-vhost.conf.template} to the running system, plus a
# handful of hardening steps that don't have a static template of their own
# (mysql_secure_installation-equivalent SQL, unattended-upgrades, ufw).
#
# STANDALONE BY DESIGN: deploy/install.sh calls this after provisioning a
# new instance, but it is also meant to be run BY ITSELF, directly, by a
# company that already has ITFlow-Internal-IT running from a manual/older
# setup and just wants to retrofit this hardening onto it:
#   sudo deploy/harden.sh                 # box-wide hardening only
#   sudo deploy/harden.sh --domain itflow.example.com --app-root /var/www/itflow.example.com \
#       --ssl-cert /etc/ssl/certs/itflow.example.com.crt \
#       --ssl-cert-key /etc/ssl/private/itflow.example.com.key
#                                          # also (re)render that vhost hardened
#   sudo deploy/harden.sh --dry-run       # preview every action, change nothing
#
# This script does NOT install nginx, php-fpm, or mariadb-server themselves -
# it hardens infrastructure that's assumed to already be there (that's
# install.sh's job when standing up a brand new box). It DOES install the
# hardening tools proper (fail2ban, ufw, unattended-upgrades) if missing,
# since a company retrofitting hardening onto an existing box may well not
# have those yet.
#
# Every package/file/service action below checks-before-acting so this is
# safe to run repeatedly, including against a box that already has one or
# more OTHER ITFlow instances hardened by an earlier run of this script.

# ---------------------------------------------------------------------------
# Bring in shared helpers if deploy/lib/common.sh exists (log/success/warn/die/
# announce, require_root, detect_os, package_installed, register_tmpfile).
# Fall back to minimal local definitions of the same names otherwise, so this
# script still works if it's ever copied out on its own or run before that
# file exists - `command -v` guards mean a real common.sh definition always
# wins when both are present.
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATES_DIR="${SCRIPT_DIR}/templates"

# shellcheck source=lib/common.sh
if [[ -f "${SCRIPT_DIR}/lib/common.sh" ]]; then
    # shellcheck disable=SC1091
    source "${SCRIPT_DIR}/lib/common.sh"
fi

command -v log      >/dev/null 2>&1 || log()      { printf '[*] %s\n' "$*"; }
command -v success  >/dev/null 2>&1 || success()  { printf '[+] %s\n' "$*"; }
command -v warn      >/dev/null 2>&1 || warn()      { printf '[!] %s\n' "$*" >&2; }
command -v die       >/dev/null 2>&1 || die()       { printf '[x] %s\n' "$*" >&2; exit 1; }
# announce(): common.sh's dedicated marker for invasive/hard-to-reverse steps
# (service restarts, ufw enable, destructive file writes) - distinct from
# routine log() output so it's easy to grep the "about to do something that
# matters" moments back out of a run's output.
command -v announce  >/dev/null 2>&1 || announce()  { printf '[!] ACTION: %s\n' "$*"; }

# require_root()/detect_os(): prefer common.sh's versions when present (they
# also export OS_ID/OS_CODENAME/etc that a future script might want) -
# otherwise fall back to locally-defined equivalents further down, called
# from Preflight below.
if ! command -v require_root >/dev/null 2>&1; then
    require_root() {
        [[ "$(id -u)" -eq 0 ]] || die "Must be run as root (sudo deploy/harden.sh ...) - every step here touches system-owned paths (/etc/php, /etc/mysql, /etc/nginx, /etc/fail2ban, ufw, apt)."
    }
fi
if ! command -v detect_os >/dev/null 2>&1; then
    detect_os() {
        [[ -r /etc/os-release ]] || die "Cannot read /etc/os-release - this script only supports Debian/Ubuntu (apt, ufw, unattended-upgrades are all Debian-family tools)."
        # shellcheck disable=SC1091
        local id id_like
        id="$(. /etc/os-release && echo "$ID")"
        id_like="$(. /etc/os-release && echo "${ID_LIKE:-}")"
        if [[ "$id" != "ubuntu" && "$id" != "debian" && "$id_like" != *debian* ]]; then
            die "Unsupported OS ($id) - this script only supports Debian/Ubuntu."
        fi
    }
fi
# package_installed(): prefer common.sh's stricter dpkg-query check (treats
# "half-configured"/"rc" states as not-installed) over a plain `dpkg -s`.
if ! command -v package_installed >/dev/null 2>&1; then
    package_installed() {
        dpkg -s "$1" >/dev/null 2>&1
    }
fi

# ---------------------------------------------------------------------------
# Temp-file cleanup
# ---------------------------------------------------------------------------
# common.sh owns THE ONE EXIT trap for the process (it shreds any secret temp
# files registered via its register_tmpfile()) - a second `trap ... EXIT` set
# here would silently replace it and skip that cleanup, exactly the footgun
# common.sh's own comment warns callers about. So: when register_tmpfile()
# exists (common.sh was sourced), use it and never touch the trap ourselves.
# Only when running truly standalone (no common.sh) do we set our own.
if ! command -v register_tmpfile >/dev/null 2>&1; then
    TMP_FILES=()
    register_tmpfile() { TMP_FILES+=("$1"); }
    _cleanup_tmp_files() { rm -f "${TMP_FILES[@]}"; }
    trap _cleanup_tmp_files EXIT
fi

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

DRY_RUN=false
DOMAIN=""
APP_ROOT=""
PHP_SOCK_ARG=""
SSL_CERT_PATH=""
SSL_CERT_KEY_PATH=""
PROXY_MODE=false
EXTRA_PORTS=()
SKIP_PHP=false
SKIP_MARIADB=false
SKIP_MYSQL_SECURE=false
SKIP_FAIL2BAN=false
SKIP_UFW=false
SKIP_UNATTENDED_UPGRADES=false
SKIP_NGINX=false

usage() {
    cat <<'EOF'
Usage: harden.sh [options]

  --dry-run                  Print every action without changing anything.
  --domain DOMAIN             Also render/harden this instance's nginx vhost
                               from deploy/templates/nginx-vhost.conf.template.
  --app-root PATH              Webroot for --domain (required with --domain).
  --php-sock PATH               PHP-FPM socket (default: auto-detected php-fpm
                               version's default socket path).
  --ssl-cert PATH               Cert path for --domain (required with --domain).
  --ssl-cert-key PATH            Key path for --domain (required with --domain).
  --proxy-mode                 Render the vhost for the reverse-proxy-backend
                               pattern (adds :8443, strips HSTS) instead of
                               direct-TLS mode.
  --allow-port PORT[/PROTO]     Extra ufw rule to add (repeatable).
  --skip-php                   Don't touch php-fpm hardening.
  --skip-mariadb                 Don't touch MariaDB hardening.
  --skip-mysql-secure            Don't run the mysql_secure_installation-equivalent cleanup.
  --skip-fail2ban                 Don't touch fail2ban.
  --skip-ufw                      Don't touch the firewall.
  --skip-unattended-upgrades        Don't touch unattended-upgrades.
  --skip-nginx                       Don't touch nginx at all (incl. rate-limit zone).
  -h, --help                          Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true; shift ;;
        --domain) DOMAIN="${2:?--domain requires a value}"; shift 2 ;;
        --app-root) APP_ROOT="${2:?--app-root requires a value}"; shift 2 ;;
        --php-sock) PHP_SOCK_ARG="${2:?--php-sock requires a value}"; shift 2 ;;
        --ssl-cert) SSL_CERT_PATH="${2:?--ssl-cert requires a value}"; shift 2 ;;
        --ssl-cert-key) SSL_CERT_KEY_PATH="${2:?--ssl-cert-key requires a value}"; shift 2 ;;
        --proxy-mode) PROXY_MODE=true; shift ;;
        --allow-port) EXTRA_PORTS+=("${2:?--allow-port requires a value}"); shift 2 ;;
        --skip-php) SKIP_PHP=true; shift ;;
        --skip-mariadb) SKIP_MARIADB=true; shift ;;
        --skip-mysql-secure) SKIP_MYSQL_SECURE=true; shift ;;
        --skip-fail2ban) SKIP_FAIL2BAN=true; shift ;;
        --skip-ufw) SKIP_UFW=true; shift ;;
        --skip-unattended-upgrades) SKIP_UNATTENDED_UPGRADES=true; shift ;;
        --skip-nginx) SKIP_NGINX=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown argument: $1 (see --help)" ;;
    esac
done

if [[ -n "$DOMAIN" ]]; then
    [[ -n "$APP_ROOT" ]] || die "--domain requires --app-root"
    [[ -n "$SSL_CERT_PATH" ]] || die "--domain requires --ssl-cert"
    [[ -n "$SSL_CERT_KEY_PATH" ]] || die "--domain requires --ssl-cert-key"
fi

$DRY_RUN && log "DRY RUN: no changes will be made, every action below is a preview."

# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

# Run a mutating command, or just describe it under --dry-run.
run_or_log() {
    local desc="$1"; shift
    log "$desc"
    if $DRY_RUN; then
        log "  [dry-run] would run: $*"
        return 0
    fi
    "$@"
}

# Install an apt package only if it isn't already present - never reinstall
# something another ITFlow instance's setup (or the admin) already put there.
ensure_pkg() {
    local pkg="$1"
    if package_installed "$pkg"; then
        log "Package already installed: $pkg"
        return 0
    fi
    run_or_log "Installing package: $pkg" apt-get install -y "$pkg"
}

# Copy $src to $dest (root:root, given mode), backing up an existing,
# differing $dest to $dest.bak-<timestamp> first. No-ops if $dest already
# matches $src byte-for-byte. Respects --dry-run.
deploy_file() {
    local src="$1" dest="$2" mode="${3:-0644}"
    if [[ -f "$dest" ]] && cmp -s "$src" "$dest"; then
        log "Already up to date: $dest"
        return 0
    fi
    if [[ -f "$dest" ]]; then
        local backup
        backup="${dest}.bak-$(date +%Y%m%d%H%M%S)"
        run_or_log "Backing up existing $dest -> $backup" cp -p "$dest" "$backup"
    fi
    run_or_log "Writing $dest (mode $mode)" install -o root -g root -m "$mode" "$src" "$dest"
}

# The service/package this script hardens must already exist - harden.sh
# hardens an existing install, it does not provision nginx/php-fpm/mariadb
# from scratch (that's install.sh's job).
require_service_installed() {
    local svc="$1" hint="$2"
    systemctl list-unit-files "${svc}.service" 2>/dev/null | grep -q "${svc}.service" \
        || die "${svc}.service not found - ${hint}"
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
require_root
detect_os

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
[[ -n "$PHP_VER" ]] || PHP_VER="8.4"   # fall back to this box's known version if detection fails
PHP_FPM_SVC="php${PHP_VER}-fpm"
PHP_FPM_BIN="php-fpm${PHP_VER}"
PHP_FPM_CONF_DIR="/etc/php/${PHP_VER}/fpm/conf.d"
PHP_SOCK_DEFAULT="/run/php/php${PHP_VER}-fpm.sock"
PHP_SOCK="${PHP_SOCK_ARG:-$PHP_SOCK_DEFAULT}"

require_service_installed "$PHP_FPM_SVC" "install php${PHP_VER}-fpm (or run deploy/install.sh) before hardening it."
require_service_installed "nginx" "install nginx (or run deploy/install.sh) before hardening it."
require_service_installed "mariadb" "install mariadb-server (or run deploy/install.sh) before hardening it."

log "Detected PHP-FPM: ${PHP_FPM_SVC} (conf.d: ${PHP_FPM_CONF_DIR}, socket: ${PHP_SOCK})"

# One apt index refresh up front - ensure_pkg below (fail2ban, ufw,
# unattended-upgrades) needs a non-stale index, and this is harmless to run
# even when every package it would install is already present.
run_or_log "Refreshing apt package index" apt-get update -qq

# ===========================================================================
# 1. PHP-FPM hardening (deploy/templates/php-hardening.ini)
# ===========================================================================
if ! $SKIP_PHP; then
    log "--- PHP-FPM hardening ---"
    PHP_INI_DEST="${PHP_FPM_CONF_DIR}/99-itflow-hardening.ini"
    PHP_INI_BEFORE=""
    [[ -f "$PHP_INI_DEST" ]] && PHP_INI_BEFORE="$(cat "$PHP_INI_DEST")"

    deploy_file "${TEMPLATES_DIR}/php-hardening.ini" "$PHP_INI_DEST" 0644

    if $DRY_RUN; then
        log "[dry-run] would test with '${PHP_FPM_BIN} -t' and reload ${PHP_FPM_SVC} on success"
    else
        # mktemp (not a predictable /tmp/name.$$ path): this runs as root, and
        # a world-writable /tmp with a guessable PID-based name is a classic
        # symlink-plant target (CWE-377) for a local unprivileged attacker.
        PHP_FPM_TEST_LOG="$(mktemp)"
        register_tmpfile "$PHP_FPM_TEST_LOG"
        if "$PHP_FPM_BIN" -t >"$PHP_FPM_TEST_LOG" 2>&1; then
            announce "About to reload ${PHP_FPM_SVC}.service."
            run_or_log "Reloading ${PHP_FPM_SVC}" systemctl reload "$PHP_FPM_SVC"
            success "PHP-FPM hardening applied and active."
        else
            warn "php-fpm config test failed - NOT reloading. Output:"
            cat "$PHP_FPM_TEST_LOG" >&2
            if [[ -n "$PHP_INI_BEFORE" ]]; then
                printf '%s' "$PHP_INI_BEFORE" > "$PHP_INI_DEST"
                warn "Restored previous ${PHP_INI_DEST} so the running config still matches what's on disk."
            else
                rm -f "$PHP_INI_DEST"
                warn "Removed the new ${PHP_INI_DEST} (there was nothing to restore) so disk matches the running config."
            fi
        fi
        rm -f "$PHP_FPM_TEST_LOG"
    fi
else
    log "Skipping PHP-FPM hardening (--skip-php)"
fi

# ===========================================================================
# 2. MariaDB hardening (deploy/templates/mariadb-hardening.cnf)
# ===========================================================================
if ! $SKIP_MARIADB; then
    log "--- MariaDB hardening ---"
    MARIADB_CNF_DEST="/etc/mysql/mariadb.conf.d/99-itflow-hardening.cnf"
    MARIADB_CNF_BEFORE=""
    [[ -f "$MARIADB_CNF_DEST" ]] && MARIADB_CNF_BEFORE="$(cat "$MARIADB_CNF_DEST")"

    deploy_file "${TEMPLATES_DIR}/mariadb-hardening.cnf" "$MARIADB_CNF_DEST" 0644

    # bind-address=127.0.0.1 in this file means the restart below drops any
    # existing remote MySQL client connection - announced loudly since this
    # is exactly the kind of invasive/hard-to-reverse step that needs a
    # heads-up before it happens.
    if $DRY_RUN; then
        log "[dry-run] would validate config and restart mariadb (this DROPS any current remote MySQL connections - by design, see bind-address in the template) on success"
    else
        MARIADB_TEST_LOG="$(mktemp)"
        register_tmpfile "$MARIADB_TEST_LOG"
        MARIADBD_BIN="$(command -v mariadbd || command -v mysqld || true)"
        VALIDATE_OK=true
        if [[ -n "$MARIADBD_BIN" ]]; then
            # NOTE: deliberately NOT `mariadbd --validate-config` here - verified
            # by hand against this exact MariaDB build (10.11) that it does not
            # do a lightweight parse-only check: it proceeds far enough to try
            # to open the real datadir's storage-engine files (ibdata1,
            # aria_log_control), which collides with the ALREADY-RUNNING
            # mariadbd on those same locks, burns ~30s of retries, and then
            # fails - reporting a perfectly valid config as broken and
            # discarding it. `--help --verbose` parses every config file and
            # every directive in it (exits non-zero with an
            # "[ERROR] mariadbd: unknown variable ..." line on a bad one, exit
            # 0 otherwise) without ever touching the datadir, which is exactly
            # the non-invasive check wanted here.
            if ! "$MARIADBD_BIN" --help --verbose >"$MARIADB_TEST_LOG" 2>&1; then
                VALIDATE_OK=false
            fi
        else
            warn "Neither mariadbd nor mysqld found on PATH - proceeding without a pre-restart config test (best effort)."
        fi

        if $VALIDATE_OK; then
            announce "About to restart mariadb.service - this will briefly interrupt all active DB connections (including this app's)."
            run_or_log "Restarting mariadb" systemctl restart mariadb
            success "MariaDB hardening applied and active."
        else
            warn "mariadb config validation failed - NOT restarting. Output:"
            cat "$MARIADB_TEST_LOG" >&2
            if [[ -n "$MARIADB_CNF_BEFORE" ]]; then
                printf '%s' "$MARIADB_CNF_BEFORE" > "$MARIADB_CNF_DEST"
                warn "Restored previous ${MARIADB_CNF_DEST} so the running config still matches what's on disk."
            else
                rm -f "$MARIADB_CNF_DEST"
                warn "Removed the new ${MARIADB_CNF_DEST} (there was nothing to restore) so disk matches the running config."
            fi
        fi
        rm -f "$MARIADB_TEST_LOG"
    fi
else
    log "Skipping MariaDB hardening (--skip-mariadb)"
fi

# ===========================================================================
# 3. mysql_secure_installation-equivalent cleanup
# ===========================================================================
# Only the parts of mysql_secure_installation that are actual server-state
# cleanup (anonymous users, the test db) - run as direct SQL rather than the
# interactive tool so this is scriptable and idempotent. Root's auth method
# is deliberately left untouched: Debian/Ubuntu's mariadb-server package
# already defaults the root@localhost account to unix_socket auth, which is
# already secure (only the OS root user can use it, no password to leak) -
# reconfiguring it wrongly could lock the admin out of their own database
# with no easy recovery, so this assumption is documented here instead of
# "fixed".
if ! $SKIP_MYSQL_SECURE; then
    log "--- MariaDB anonymous-user/test-db cleanup ---"
    if $DRY_RUN; then
        log "[dry-run] would run: DELETE FROM mysql.user WHERE User=''; DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;"
    else
        if mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
            mysql -u root -e "DELETE FROM mysql.user WHERE User=''; DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;"
            success "Removed anonymous MySQL users and the test database (if present)."
        else
            warn "Could not connect as 'mysql -u root' (expects Debian/Ubuntu's default unix_socket root auth, and this script running as the OS root user). Skipping the secure-install cleanup - run it manually if root uses password auth instead."
        fi
    fi
else
    log "Skipping mysql_secure_installation-equivalent cleanup (--skip-mysql-secure)"
fi

# ===========================================================================
# 4. fail2ban (deploy/templates/jail-itflow.local + its filter.d companion)
# ===========================================================================
if ! $SKIP_FAIL2BAN; then
    log "--- fail2ban ---"
    ensure_pkg fail2ban

    FILTER_DEST="/etc/fail2ban/filter.d/itflow-auth.conf"
    FILTER_TMP="$(mktemp)"
    register_tmpfile "$FILTER_TMP"
    cat > "$FILTER_TMP" <<'EOF'
# ITFlow-Internal-IT auth-endpoint filter for fail2ban.
# Written by deploy/harden.sh, sourced from deploy/templates/jail-itflow.local's
# header comment (fail2ban keeps jail and filter definitions in separate
# directories, so this file's content is kept in sync from there).
[Definition]
failregex = ^<HOST> .* "POST /(login|post)\.php[^"]*" (401|403|429)
ignoreregex =
EOF
    FILTER_BEFORE=""
    [[ -f "$FILTER_DEST" ]] && FILTER_BEFORE="$(cat "$FILTER_DEST")"
    deploy_file "$FILTER_TMP" "$FILTER_DEST" 0644
    rm -f "$FILTER_TMP"

    JAIL_DEST="/etc/fail2ban/jail.d/itflow.local"
    JAIL_BEFORE=""
    [[ -f "$JAIL_DEST" ]] && JAIL_BEFORE="$(cat "$JAIL_DEST")"
    deploy_file "${TEMPLATES_DIR}/jail-itflow.local" "$JAIL_DEST" 0644

    if $DRY_RUN; then
        log "[dry-run] would test with 'fail2ban-client -t' and restart fail2ban on success"
    else
        F2B_TEST_LOG="$(mktemp)"
        register_tmpfile "$F2B_TEST_LOG"
        if fail2ban-client -t >"$F2B_TEST_LOG" 2>&1; then
            announce "About to restart fail2ban.service (drops any bans currently only held in memory, not on-disk persistent bans)."
            run_or_log "Restarting fail2ban" systemctl restart fail2ban
            success "fail2ban jails applied and active."
        else
            warn "fail2ban config test failed - NOT restarting. Output:"
            cat "$F2B_TEST_LOG" >&2
            for pair in "$FILTER_DEST:$FILTER_BEFORE" "$JAIL_DEST:$JAIL_BEFORE"; do
                dest="${pair%%:*}"; before="${pair#*:}"
                if [[ -n "$before" ]]; then
                    printf '%s' "$before" > "$dest"
                else
                    rm -f "$dest"
                fi
            done
            warn "Restored previous fail2ban config so the running config still matches what's on disk."
        fi
        rm -f "$F2B_TEST_LOG"
    fi
else
    log "Skipping fail2ban (--skip-fail2ban)"
fi

# ===========================================================================
# 5. ufw firewall
# ===========================================================================
if ! $SKIP_UFW; then
    log "--- ufw firewall ---"
    ensure_pkg ufw

    # Detect every port sshd actually listens on (main config + Ubuntu
    # 24.04's default sshd_config.d/*.conf drop-ins) - sshd allows multiple
    # "Port" lines to each add a listener, they don't override each other.
    # Default to 22 if none are configured (sshd's own compiled-in default).
    mapfile -t SSH_PORTS < <(
        { [[ -f /etc/ssh/sshd_config ]] && grep -E '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config;
          compgen -G "/etc/ssh/sshd_config.d/*.conf" >/dev/null && grep -hE '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config.d/*.conf; } \
        2>/dev/null | awk '{print $2}' | sort -un
    )
    [[ ${#SSH_PORTS[@]} -eq 0 ]] && SSH_PORTS=(22)

    # SSH access MUST be allowed before ufw is ever enabled, or a fresh
    # `ufw --force enable` on a box with no prior rules locks out the very
    # session running this script.
    for p in "${SSH_PORTS[@]}"; do
        run_or_log "Allowing SSH on ${p}/tcp" ufw allow "${p}/tcp" comment "ITFlow hardening: SSH"
    done

    run_or_log "Allowing HTTP (80/tcp)" ufw allow 80/tcp comment "ITFlow hardening: HTTP"
    run_or_log "Allowing HTTPS (443/tcp)" ufw allow 443/tcp comment "ITFlow hardening: HTTPS"

    if $PROXY_MODE; then
        run_or_log "Allowing reverse-proxy backend port (8443/tcp)" ufw allow 8443/tcp comment "ITFlow hardening: proxy-mode backend"
    fi
    for p in "${EXTRA_PORTS[@]}"; do
        run_or_log "Allowing extra port ${p}" ufw allow "$p"
    done

    if $DRY_RUN; then
        log "[dry-run] would check ufw status and run 'ufw --force enable' if currently inactive (rules above are added either way)"
    else
        if ufw status | grep -q "Status: active"; then
            log "ufw is already active - rules above were merged in, not re-enabling."
        else
            announce "About to enable ufw (SSH/HTTP/HTTPS rules above are already in place)."
            ufw --force enable
            success "ufw enabled."
        fi
    fi
else
    log "Skipping ufw (--skip-ufw)"
fi

# ===========================================================================
# 6. unattended-upgrades (security updates only)
# ===========================================================================
if ! $SKIP_UNATTENDED_UPGRADES; then
    log "--- unattended-upgrades ---"
    ensure_pkg unattended-upgrades

    UU_DEST="/etc/apt/apt.conf.d/51-itflow-unattended-upgrades"
    UU_TMP="$(mktemp)"
    register_tmpfile "$UU_TMP"
    # ${distro_id}/${distro_codename} are literal tokens unattended-upgrades
    # itself resolves at run time - must NOT be shell-expanded here, hence
    # the quoted heredoc delimiter.
    cat > "$UU_TMP" <<'EOF'
// ITFlow-Internal-IT: restrict unattended-upgrades to security updates only.
// Written by deploy/harden.sh from a static template inline in that script.
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
// A box running a live ITFlow instance rebooting itself unattended is a
// worse outage than a delayed kernel update - leave reboots to the admin.
Unattended-Upgrade::Automatic-Reboot "false";
EOF
    deploy_file "$UU_TMP" "$UU_DEST" 0644
    rm -f "$UU_TMP"

    # Without this second file (the standard one dpkg-reconfigure
    # unattended-upgrades would create), the periodic timer never actually
    # invokes unattended-upgrades - the Allowed-Origins file above only
    # controls WHICH packages get upgraded once it runs, not WHETHER it runs.
    AUTO_DEST="/etc/apt/apt.conf.d/20auto-upgrades"
    AUTO_TMP="$(mktemp)"
    register_tmpfile "$AUTO_TMP"
    cat > "$AUTO_TMP" <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
    deploy_file "$AUTO_TMP" "$AUTO_DEST" 0644
    rm -f "$AUTO_TMP"

    run_or_log "Enabling apt-daily-upgrade.timer" systemctl enable --now apt-daily-upgrade.timer
    success "unattended-upgrades configured for security-origin updates only."
else
    log "Skipping unattended-upgrades (--skip-unattended-upgrades)"
fi

# ===========================================================================
# 7. nginx: box-wide rate-limit zone, plus optional per-domain vhost
# ===========================================================================
if ! $SKIP_NGINX; then
    log "--- nginx ---"

    # http{}-context rate limit zone shared by every ITFlow vhost on this
    # box - lives once per box regardless of how many instances/domains it
    # hosts, since nginx errors on defining the same zone name twice.
    RATE_LIMIT_DEST="/etc/nginx/conf.d/itflow-rate-limit.conf"
    RATE_LIMIT_TMP="$(mktemp)"
    register_tmpfile "$RATE_LIMIT_TMP"
    cat > "$RATE_LIMIT_TMP" <<'EOF'
# ITFlow-Internal-IT: shared brute-force rate limit zone for /login.php and
# /setup.php across every ITFlow vhost on this box. See the "Rate limiting"
# section of deploy/templates/nginx-vhost.conf.template's header comment.
limit_req_zone $binary_remote_addr zone=itflow_login:10m rate=5r/m;
EOF
    NGINX_CHANGED=false
    if [[ -f "$RATE_LIMIT_DEST" ]] && ! cmp -s "$RATE_LIMIT_TMP" "$RATE_LIMIT_DEST"; then
        die "Existing ${RATE_LIMIT_DEST} defines a different zone than expected - refusing to overwrite a hand-edited file blindly. Compare and merge manually, or remove it and re-run."
    fi
    if [[ ! -f "$RATE_LIMIT_DEST" ]]; then
        deploy_file "$RATE_LIMIT_TMP" "$RATE_LIMIT_DEST" 0644
        NGINX_CHANGED=true
    else
        log "Already up to date: $RATE_LIMIT_DEST"
    fi
    rm -f "$RATE_LIMIT_TMP"

    VHOST_DEST=""
    if [[ -n "$DOMAIN" ]]; then
        PHP_SOCK_FOR_VHOST="$PHP_SOCK"
        VHOST_DEST="/etc/nginx/sites-available/${DOMAIN}.conf"
        VHOST_TMP="$(mktemp)"
        register_tmpfile "$VHOST_TMP"

        # shellcheck disable=SC2016 # single-quoted on purpose: this is envsubst's own restrict-list argument, not meant to expand here
        DOMAIN="$DOMAIN" APP_ROOT="$APP_ROOT" PHP_SOCK="$PHP_SOCK_FOR_VHOST" \
            SSL_CERT_PATH="$SSL_CERT_PATH" SSL_CERT_KEY_PATH="$SSL_CERT_KEY_PATH" \
            envsubst '${DOMAIN} ${APP_ROOT} ${PHP_SOCK} ${SSL_CERT_PATH} ${SSL_CERT_KEY_PATH}' \
            < "${TEMPLATES_DIR}/nginx-vhost.conf.template" > "$VHOST_TMP"

        grep -q '__PROXY_MODE_BLOCK__' "$VHOST_TMP" \
            || die "Rendered vhost is missing the __PROXY_MODE_BLOCK__ sentinel - deploy/templates/nginx-vhost.conf.template may have been edited incompatibly with this script."

        # Strip the template's own leading documentation header (every line
        # up to the first blank/non-'#' line) before doing marker-based
        # deletion below. That header's prose necessarily quotes the marker
        # strings themselves (to document them) - leaving it in would give
        # the sed range-delete below extra, earlier matches of the same
        # patterns and silently corrupt the result (verified: without this
        # step, proxy-mode rendering produces an unbalanced config that
        # fails `nginx -t`). This also leaves the deployed
        # /etc/nginx/sites-available file free of template-authoring notes
        # that only make sense next to the source template in the repo.
        first_code_line="$(grep -n -v -E '^#|^$' "$VHOST_TMP" | head -1 | cut -d: -f1)"
        [[ -n "$first_code_line" && "$first_code_line" -gt 1 ]] && sed -i "1,$((first_code_line - 1))d" "$VHOST_TMP"

        if $PROXY_MODE; then
            sed -i '/# BEGIN DIRECT-TLS ONLY/,/# END DIRECT-TLS ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN PROXY-MODE ONLY/d; /# END PROXY-MODE ONLY/d' "$VHOST_TMP"
        else
            sed -i '/# BEGIN PROXY-MODE ONLY/,/# END PROXY-MODE ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN DIRECT-TLS ONLY/d; /# END DIRECT-TLS ONLY/d' "$VHOST_TMP"
        fi

        VHOST_BEFORE=""
        [[ -f "$VHOST_DEST" ]] && VHOST_BEFORE="$(cat "$VHOST_DEST")"
        if [[ ! -f "$VHOST_DEST" ]] || ! cmp -s "$VHOST_TMP" "$VHOST_DEST"; then
            deploy_file "$VHOST_TMP" "$VHOST_DEST" 0644
            NGINX_CHANGED=true
        else
            log "Already up to date: $VHOST_DEST"
        fi
        rm -f "$VHOST_TMP"

        LINK="/etc/nginx/sites-enabled/${DOMAIN}.conf"
        if [[ ! -L "$LINK" ]]; then
            run_or_log "Enabling site: $LINK -> $VHOST_DEST" ln -s "$VHOST_DEST" "$LINK"
            NGINX_CHANGED=true
        else
            log "Already enabled: $LINK"
        fi
    else
        log "No --domain given - skipping per-vhost render. Box-wide nginx hardening (rate-limit zone) still applied above."
        log "Pass --domain DOMAIN --app-root PATH --ssl-cert PATH --ssl-cert-key PATH [--proxy-mode] to also (re)harden a specific vhost."
    fi

    if $NGINX_CHANGED; then
        if $DRY_RUN; then
            log "[dry-run] would test with 'nginx -t' and reload nginx on success"
        else
            NGINX_TEST_LOG="$(mktemp)"
            register_tmpfile "$NGINX_TEST_LOG"
            if nginx -t >"$NGINX_TEST_LOG" 2>&1; then
                announce "About to reload nginx.service."
                run_or_log "Reloading nginx" systemctl reload nginx
                success "nginx hardening applied and active."
            else
                warn "nginx config test failed - NOT reloading. Output:"
                cat "$NGINX_TEST_LOG" >&2
                if [[ -n "$VHOST_DEST" && -n "${VHOST_BEFORE:-}" ]]; then
                    printf '%s' "$VHOST_BEFORE" > "$VHOST_DEST"
                    warn "Restored previous ${VHOST_DEST} so the running config still matches what's on disk."
                elif [[ -n "$VHOST_DEST" ]]; then
                    rm -f "$VHOST_DEST" "$LINK"
                    warn "Removed the new ${VHOST_DEST} (there was nothing to restore) so disk matches the running config."
                fi
            fi
            rm -f "$NGINX_TEST_LOG"
        fi
    fi
else
    log "Skipping nginx (--skip-nginx)"
fi

success "Hardening pass complete."
$DRY_RUN && log "This was a dry run - nothing was actually changed. Re-run without --dry-run to apply."
