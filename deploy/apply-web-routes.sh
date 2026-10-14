#!/usr/bin/env bash
set -euo pipefail

# RivetIT - apply-web-routes.sh: keep the web server's routes (nginx) in step with the app, with ONE command, so nobody
# ever hand-edits nginx again.
#
#   sudo deploy/apply-web-routes.sh --domain rivetit.example.com
#
# What it does, in order (and why each step is safe):
#   1. finds the nginx vhost(s) for the domain (or the ones you name), as root only;
#   2. renders the shared routes file (deploy/templates/rivetit-locations.conf) and, for a vhost that still carries its
#      locations inline (an older install), converts it to ONE managed block that includes that file - only when it can prove
#      the result serves the same things (anything unrecognised is left alone and reported; if it cannot prove it, the vhost
#      is NOT touched and exact manual steps are printed);
#   3. backs up every file it is about to change into a timestamped folder;
#   4. runs `nginx -t`, and reloads nginx ONLY if that passes; if the test or the reload fails, every file is put back.
# Running it again when everything is current changes nothing and does not reload nginx.
#
# Options:
#   --domain NAME        the site's domain (as in server_name); repeatable
#   --vhost FILE         an explicit vhost file; repeatable (use when --domain finds nothing)
#   --all                every RivetIT vhost found under the nginx folder (several sites sharing one routes file)
#   --php-sock PATH      the PHP-FPM socket for the sites (default: detected from each vhost's existing fastcgi_pass)
#   --php-sock-for NAME=PATH   a different socket for the vhost file NAME (file name only); repeatable
#   --hsts direct|proxy  direct: this box terminates public TLS and sends HSTS; proxy: an upstream proxy owns that
#                        (default: kept as the vhost already behaves)
#   --snippet-dir DIR    where the routes file lives (default /etc/nginx/snippets)
#   --legacy-snippet NAME  another older shared snippet to recognise besides itflow-locations.conf; repeatable
#   --backup-dir DIR     keep this run's backups here (default /var/backups/rivetit/nginx-<time>)
#   --dry-run            show exactly what would change (unified diff) and change nothing
#   --check              change nothing; exit 2 if the routes are out of date (for monitoring), 0 if current
#   --rollback DIR       put back the files saved in a previous run's backup folder, then test and reload
#   --help
# Advanced / testing (a non-default --nginx-dir never reloads the real nginx unless --reload-cmd says so):
#   --nginx-dir DIR  --nginx-conf FILE  --nginx-bin PATH  --reload-cmd "CMD ARGS"  --routes-file FILE
#
# Exit codes: 0 done or already current; 1 failed (anything half-done was rolled back; the message says what to look at);
#             2 --check: routes are out of date; 3 done, but at least one vhost needs the manual steps printed above.
#
# Run it as root (sudo). It refuses to run as the web user: the web server's own account must never be able to rewrite nginx.
# Docker installs do not use this (docker/nginx.conf is their single source); Apache installs use .htaccess.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

ROUTES_BASENAME="rivetit-locations.conf"
ROUTES_FILE="${SCRIPT_DIR}/templates/${ROUTES_BASENAME}"
TOOL="${SCRIPT_DIR}/lib/web_routes_tool.php"

DOMAINS=()
VHOSTS=()
LEGACY_SNIPPETS=()
declare -A PHP_SOCK_FOR=()
ALL=0
PHP_SOCK=""
HSTS=""
NGINX_DIR="/etc/nginx"
SNIPPET_DIR=""
BACKUP_ROOT="/var/backups/rivetit"
BACKUP_DIR=""
DRY_RUN=0
CHECK=0
ROLLBACK_FROM=""
NGINX_CONF=""
NGINX_BIN="nginx"
RELOAD_CMD=""

STAGE=""
APPLYING=0
COMPLETED=0
CH_DEST=()    # files to write
CH_NEW=()     # staged content for each
MANUAL=()     # printed manual-step blocks
declare -A MANUAL_REASONS=()

usage() { sed -n '3,/^SCRIPT_DIR=/p' "${BASH_SOURCE[0]}" | sed -e '$d' -e 's/^# \{0,1\}//'; }

need_val() { [[ $# -ge 2 && -n "${2}" ]] || die "Option $1 needs a value (see --help)."; }

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --domain=*) DOMAINS+=("${1#*=}"); shift ;;
            --domain) need_val "$@"; DOMAINS+=("$2"); shift 2 ;;
            --vhost=*) VHOSTS+=("${1#*=}"); shift ;;
            --vhost) need_val "$@"; VHOSTS+=("$2"); shift 2 ;;
            --all) ALL=1; shift ;;
            --php-sock=*) PHP_SOCK="${1#*=}"; shift ;;
            --php-sock) need_val "$@"; PHP_SOCK="$2"; shift 2 ;;
            --php-sock-for=*) set_sock_for "${1#*=}"; shift ;;
            --php-sock-for) need_val "$@"; set_sock_for "$2"; shift 2 ;;
            --hsts=*) HSTS="${1#*=}"; shift ;;
            --hsts) need_val "$@"; HSTS="$2"; shift 2 ;;
            --snippet-dir=*) SNIPPET_DIR="${1#*=}"; shift ;;
            --snippet-dir) need_val "$@"; SNIPPET_DIR="$2"; shift 2 ;;
            --legacy-snippet=*) LEGACY_SNIPPETS+=("${1#*=}"); shift ;;
            --legacy-snippet) need_val "$@"; LEGACY_SNIPPETS+=("$2"); shift 2 ;;
            --backup-dir=*) BACKUP_DIR="${1#*=}"; shift ;;
            --backup-dir) need_val "$@"; BACKUP_DIR="$2"; shift 2 ;;
            --dry-run) DRY_RUN=1; shift ;;
            --check) CHECK=1; shift ;;
            --rollback=*) ROLLBACK_FROM="${1#*=}"; shift ;;
            --rollback) need_val "$@"; ROLLBACK_FROM="$2"; shift 2 ;;
            --nginx-dir=*) NGINX_DIR="${1#*=}"; shift ;;
            --nginx-dir) need_val "$@"; NGINX_DIR="$2"; shift 2 ;;
            --nginx-conf=*) NGINX_CONF="${1#*=}"; shift ;;
            --nginx-conf) need_val "$@"; NGINX_CONF="$2"; shift 2 ;;
            --nginx-bin=*) NGINX_BIN="${1#*=}"; shift ;;
            --nginx-bin) need_val "$@"; NGINX_BIN="$2"; shift 2 ;;
            --reload-cmd=*) RELOAD_CMD="${1#*=}"; shift ;;
            --reload-cmd) need_val "$@"; RELOAD_CMD="$2"; shift 2 ;;
            --routes-file=*) ROUTES_FILE="${1#*=}"; shift ;;
            --routes-file) need_val "$@"; ROUTES_FILE="$2"; shift 2 ;;
            --help|-h) usage; exit 0 ;;
            *) usage >&2; die "Unknown option: $1" ;;
        esac
    done
}

set_sock_for() {
    [[ "$1" == *=* && -n "${1%%=*}" && -n "${1#*=}" ]] || die "--php-sock-for needs NAME=PATH (got '$1')."
    PHP_SOCK_FOR["${1%%=*}"]="${1#*=}"
}

# ---------------------------------------------------------------------------
# who may run this
# ---------------------------------------------------------------------------
check_caller() {
    local me
    me="$(id -un 2>/dev/null || echo unknown)"
    if [[ "${me}" == "www-data" || "${SUDO_USER:-}" == "www-data" ]]; then
        die "This must never be run as the web server's user (www-data): that account must not be able to rewrite nginx. Run it from an administrator account with sudo."
    fi
    if [[ "${EUID}" -eq 0 ]]; then return 0; fi
    # Test hook: allowed only when EVERY path it will touch is a private copy, never the real /etc/nginx.
    if [[ "${RIVETIT_ROUTES_TEST_NONROOT:-}" == "1" && "${NGINX_DIR}" != "/etc/nginx" && "${NGINX_DIR}" != "/etc/nginx/" \
          && "${SNIPPET_DIR}" != /etc/nginx/* && "${BACKUP_DIR}" != /var/backups/* && "${ROLLBACK_FROM:-x}" != /var/backups/* ]]; then
        return 0
    fi
    die "This script changes the web server's configuration and must be run as root. Re-run with: sudo $0 $*"
}

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------
cleanup() {
    local rc=$?
    if [[ "${APPLYING}" -eq 1 && "${COMPLETED}" -eq 0 ]]; then
        error "Stopped unexpectedly (exit ${rc}); putting every file back."
        do_rollback "${BACKUP_DIR}" || true
    fi
    [[ -n "${STAGE}" && -d "${STAGE}" ]] && rm -rf "${STAGE}"
    return 0
}

routes_dest() { printf '%s/%s' "${SNIPPET_DIR}" "${ROUTES_BASENAME}"; }

include_spec() {
    if [[ "${SNIPPET_DIR}" == "${NGINX_DIR%/}/snippets" ]]; then
        printf 'snippets/%s' "${ROUTES_BASENAME}"
    else
        printf '%s' "$(routes_dest)"
    fi
}

nginx_test() {
    local -a cmd=("${NGINX_BIN}" -t)
    if [[ -n "${NGINX_CONF}" ]]; then cmd+=(-p "$(dirname "${NGINX_CONF}")/" -c "${NGINX_CONF}"); fi
    "${cmd[@]}" 2>&1
}

do_reload() {
    local -a cmd
    if [[ -n "${RELOAD_CMD}" ]]; then
        read -r -a cmd <<< "${RELOAD_CMD}"
    elif [[ "${NGINX_DIR%/}" == "/etc/nginx" ]]; then
        cmd=(systemctl reload nginx)
    else
        info "A private --nginx-dir was given without --reload-cmd, so nothing is reloaded."
        return 0
    fi
    "${cmd[@]}"
}

write_file() {   # write_file STAGED DEST : atomic replace keeping mode/owner
    local src="$1" dest="$2" tmp
    mkdir -p "$(dirname "${dest}")"
    tmp="${dest}.rivetit-new.$$"
    cp "${src}" "${tmp}"
    if [[ -e "${dest}" ]]; then
        chmod --reference="${dest}" "${tmp}"
        chown --reference="${dest}" "${tmp}" 2>/dev/null || true
    else
        chmod 644 "${tmp}"
    fi
    mv -f "${tmp}" "${dest}"
}

do_rollback() {   # do_rollback BACKUP_DIR
    local dir="$1" kind path rc=0
    [[ -f "${dir}/MANIFEST" ]] || { error "No MANIFEST in ${dir}; nothing to roll back."; return 1; }
    while IFS=$'\t' read -r kind path; do
        [[ -n "${path}" ]] || continue
        case "${kind}" in
            M) cp -a "${dir}/files${path}" "${path}" || rc=1 ;;
            N) rm -f "${path}" || rc=1 ;;
        esac
    done < "${dir}/MANIFEST"
    return "${rc}"
}

print_diff() {   # print_diff OLD NEW LABEL
    local old="$1" new="$2" label="$3"
    if [[ -e "${old}" ]]; then
        diff -u --label "${label} (current)" --label "${label} (after)" "${old}" "${new}" || true
    else
        diff -u --label "/dev/null" --label "${label} (new file)" /dev/null "${new}" || true
    fi
}

print_manual() {   # print_manual VHOST SOCK
    local v="$1"
    printf '\n--- %s needs a manual change ---\n' "${v}"
    printf 'Why it was not converted automatically:\n'
    printf '%s\n' "${MANUAL_REASONS[$v]}" | sed 's/^/  - /'
    cat <<EOF

To convert it yourself (the routes file is already installed for the other sites):
  1. Open ${v} in an editor. Inside the HTTPS "server { ... }" block delete every "location ... { ... }" block that the
     routes file provides (docs/WEB_ROUTES.md lists them; they are the ones copied from the old deploy template).
  2. Where the first of them was, add exactly these three lines (use YOUR site's PHP socket):
         set \$rivetit_php unix:/run/php/php8.5-fpm.sock;
         set \$rivetit_hsts "max-age=63072000; includeSubDomains";     # use "" instead when a proxy in front owns HTTPS
         include $(include_spec);
  3. Test and reload:   sudo nginx -t && sudo systemctl reload nginx
  Then run this script again with --check: it should say the routes are current.
EOF
}

# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------
main() {
    parse_args "$@"
    [[ -n "${SNIPPET_DIR}" ]] || SNIPPET_DIR="${NGINX_DIR%/}/snippets"
    check_caller "$@"
    trap cleanup EXIT

    if [[ -n "${ROLLBACK_FROM}" ]]; then
        announce "Rolling back to the files saved in ${ROLLBACK_FROM}."
        do_rollback "${ROLLBACK_FROM}" || die "Rollback was incomplete; check the messages above and ${ROLLBACK_FROM}/MANIFEST."
        if out="$(nginx_test)"; then do_reload || die "Files restored, but the nginx reload failed. Check: systemctl status nginx"; success "Rolled back and nginx reloaded."; else printf '%s\n' "${out}" >&2; die "Files restored, but nginx -t still fails; the problem is not from this script's change."; fi
        exit 0
    fi

    command_exists php || die "php is required (it analyses the vhost files)."
    command_exists "${NGINX_BIN}" || die "nginx was not found (looked for '${NGINX_BIN}'). RivetIT on Docker or Apache does not use this script."
    [[ -f "${ROUTES_FILE}" ]] || die "Routes template not found at ${ROUTES_FILE}."
    [[ -f "${TOOL}" ]] || die "Helper not found at ${TOOL}."
    [[ -f "${NGINX_DIR%/}/snippets/fastcgi-php.conf" ]] || die "Expected ${NGINX_DIR%/}/snippets/fastcgi-php.conf (installed by the nginx package) is missing; the routes file needs it."
    if [[ "${#DOMAINS[@]}" -eq 0 && "${#VHOSTS[@]}" -eq 0 && "${ALL}" -eq 0 ]]; then
        usage >&2
        die "Say which site: --domain <name>, --vhost <file>, or --all."
    fi
    case "${HSTS}" in ""|direct|proxy) ;; *) die "--hsts must be 'direct' or 'proxy'." ;; esac

    STAGE="$(mktemp -d)"

    # 1. find the vhosts -----------------------------------------------------
    local -a found=() detect_args=("--nginx-dir=${NGINX_DIR}")
    local d f
    for d in "${DOMAINS[@]}"; do detect_args+=("--domain=${d}"); done
    [[ "${ALL}" -eq 1 ]] && detect_args+=(--all)
    for f in "${LEGACY_SNIPPETS[@]}"; do detect_args+=("--legacy-snippet=${f}"); done
    while IFS= read -r f; do [[ -n "${f}" ]] && found+=("${f}"); done < <(php "${TOOL}" detect "${detect_args[@]}")
    for f in "${VHOSTS[@]}"; do
        [[ -f "${f}" ]] || die "--vhost ${f}: no such file."
        found+=("$(readlink -f "${f}")")
    done
    if [[ "${#found[@]}" -eq 0 ]]; then
        die "No nginx vhost with server_name ${DOMAINS[*]:-(any)} was found under ${NGINX_DIR%/}/sites-enabled or conf.d. Name the file yourself with --vhost <file>."
    fi
    local -a uniq=()
    local -A seen=()
    for f in "${found[@]}"; do [[ -n "${seen[$f]:-}" ]] || { seen[$f]=1; uniq+=("${f}"); }; done

    # 2. plan each vhost -----------------------------------------------------
    local i=0 out result includes sock_opt name any_includes=0 vhost_changes=0
    local -A V_RESULT=()
    for f in "${uniq[@]}"; do
        i=$((i + 1))
        name="$(basename "${f}")"
        sock_opt="${PHP_SOCK_FOR[$name]:-${PHP_SOCK}}"
        local -a targs=(convert "--vhost=${f}" "--out=${STAGE}/v${i}.new" "--routes=${ROUTES_FILE}" "--nginx-dir=${NGINX_DIR}" "--include=$(include_spec)")
        [[ -n "${sock_opt}" ]] && targs+=("--sock=${sock_opt}")
        [[ -n "${HSTS}" ]] && targs+=("--hsts=${HSTS}")
        out="$(php "${TOOL}" "${targs[@]}")" || die "Could not analyse ${f}."
        result="$(sed -n 's/^RESULT: //p' <<< "${out}")"
        includes="$(sed -n 's/^INCLUDES: //p' <<< "${out}")"
        V_RESULT[$f]="${result}"
        [[ "${includes}" == "yes" ]] && any_includes=1
        info "${f}: ${result}"
        sed -n 's/^REMOVED: /    replaced by the shared routes: /p; s/^KEPT: /    left alone (not a RivetIT route): /p; s/^NOTE: /    note: /p' <<< "${out}"
        case "${result}" in
            changed)
                CH_DEST+=("${f}"); CH_NEW+=("${STAGE}/v${i}.new"); vhost_changes=1
                local s
                s="$(sed -n 's/^SOCK: //p' <<< "${out}")"
                if [[ -n "${s}" && "${s}" == unix:* && ! -e "${s#unix:}" ]]; then
                    warn "${f}: the PHP socket ${s#unix:} does not exist on this machine (fine on a staging copy; on a live server fix it with --php-sock)."
                fi
                ;;
            unchanged) ;;
            manual)
                MANUAL+=("${f}")
                MANUAL_REASONS[$f]="$(sed -n 's/^REASON: //p' <<< "${out}")"
                ;;
            *) die "Unexpected helper answer for ${f}: ${out}" ;;
        esac
    done
    [[ "${vhost_changes}" -eq 1 ]] && any_includes=1

    # 3. the shared routes file and the rate-limit zone ------------------------
    local dest zone_dest
    dest="$(routes_dest)"
    if [[ "${any_includes}" -eq 1 || -e "${dest}" ]]; then
        if [[ ! -e "${dest}" ]] || ! cmp -s "${ROUTES_FILE}" "${dest}"; then
            CH_DEST+=("${dest}"); CH_NEW+=("${ROUTES_FILE}")
        fi
        if ! grep -rEqs '^[[:space:]]*limit_req_zone[[:space:]].*zone=itflow_login:' "${NGINX_DIR}" 2>/dev/null; then
            zone_dest="${NGINX_DIR%/}/conf.d/itflow-rate-limit.conf"
            printf '%s\n' '# Shared across every RivetIT vhost on this box, written by deploy/apply-web-routes.sh (same file deploy/install.sh writes).' \
                '# Do not duplicate this zone name in another file under conf.d/ - nginx refuses to start if it is defined twice.' \
                'limit_req_zone $binary_remote_addr zone=itflow_login:10m rate=5r/m;' > "${STAGE}/zone.new"
            CH_DEST+=("${zone_dest}"); CH_NEW+=("${STAGE}/zone.new")
        fi
    else
        warn "No vhost includes the routes file yet (see the manual steps below), so it is not installed."
    fi

    # 4. report / check / dry-run ---------------------------------------------
    local n_changes="${#CH_DEST[@]}" k
    if [[ "${CHECK}" -eq 1 ]]; then
        if [[ "${n_changes}" -gt 0 || "${#MANUAL[@]}" -gt 0 ]]; then
            warn "Web server routes are OUT OF DATE. Fix with:  sudo ${SCRIPT_DIR}/apply-web-routes.sh $(printf -- '--domain %s ' "${DOMAINS[@]:-}")"
            for k in "${!CH_DEST[@]}"; do info "would change: ${CH_DEST[$k]}"; done
            for f in "${MANUAL[@]}"; do info "needs manual conversion: ${f}"; done
            exit 2
        fi
        success "Web server routes are current."
        exit 0
    fi

    if [[ "${n_changes}" -eq 0 ]]; then
        success "Web server routes are already up to date. Nothing changed, nginx was not reloaded."
        if [[ "${#MANUAL[@]}" -gt 0 ]]; then
            for f in "${MANUAL[@]}"; do print_manual "${f}"; done
            exit 3
        fi
        exit 0
    fi

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        info "DRY RUN: nothing will be written. This is what a real run would change:"
        for k in "${!CH_DEST[@]}"; do print_diff "${CH_DEST[$k]}" "${CH_NEW[$k]}" "${CH_DEST[$k]}"; done
        for f in "${MANUAL[@]}"; do print_manual "${f}"; done
        exit 0
    fi

    # 5. back up, write, test, reload -----------------------------------------
    [[ -n "${BACKUP_DIR}" ]] || BACKUP_DIR="${BACKUP_ROOT}/nginx-$(date +%Y%m%d-%H%M%S)"
    [[ ! -e "${BACKUP_DIR}/MANIFEST" ]] || die "${BACKUP_DIR} already holds a backup; pick another --backup-dir."
    mkdir -p "${BACKUP_DIR}/files"
    chmod 700 "${BACKUP_DIR}"
    : > "${BACKUP_DIR}/MANIFEST"
    for k in "${!CH_DEST[@]}"; do
        f="${CH_DEST[$k]}"
        if [[ -e "${f}" ]]; then
            mkdir -p "${BACKUP_DIR}/files$(dirname "${f}")"
            cp -a "${f}" "${BACKUP_DIR}/files${f}"
            printf 'M\t%s\n' "${f}" >> "${BACKUP_DIR}/MANIFEST"
        else
            printf 'N\t%s\n' "${f}" >> "${BACKUP_DIR}/MANIFEST"
        fi
    done
    info "Backed up the files about to change to ${BACKUP_DIR}"

    APPLYING=1
    announce "Writing ${n_changes} file(s)."
    for k in "${!CH_DEST[@]}"; do
        write_file "${CH_NEW[$k]}" "${CH_DEST[$k]}"
        info "wrote ${CH_DEST[$k]}"
    done

    local test_out
    if ! test_out="$(nginx_test)"; then
        error "nginx rejected the new configuration, so NOTHING was kept. nginx said:"
        printf '%s\n' "${test_out}" >&2
        do_rollback "${BACKUP_DIR}" || true
        APPLYING=0
        die "Rolled back to the previous files (backup also in ${BACKUP_DIR}). The running nginx was never touched."
    fi
    info "nginx -t passed."

    announce "Reloading nginx."
    if ! do_reload; then
        error "The nginx reload failed; restoring the previous files."
        do_rollback "${BACKUP_DIR}" || true
        APPLYING=0
        if nginx_test >/dev/null 2>&1; then do_reload || warn "Could not reload after rolling back; check: systemctl status nginx"; fi
        die "Rolled back (backup also in ${BACKUP_DIR})."
    fi
    COMPLETED=1
    APPLYING=0

    success "Web server routes applied and nginx reloaded. Backup of the previous files: ${BACKUP_DIR}"
    info "To undo this change at any time:  sudo ${SCRIPT_DIR}/apply-web-routes.sh --rollback ${BACKUP_DIR}"
    if [[ "${#MANUAL[@]}" -gt 0 ]]; then
        for f in "${MANUAL[@]}"; do print_manual "${f}"; done
        exit 3
    fi
    exit 0
}

main "$@"
