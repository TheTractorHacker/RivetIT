#!/usr/bin/env bash
set -euo pipefail

# RivetIT - apply-web-routes.sh: keep the web server's routes (nginx) in step with the app, with ONE command, so nobody
# ever hand-edits nginx again.
#
#   sudo deploy/apply-web-routes.sh --domain rivetit.example.com --dry-run     # read the diff
#   sudo deploy/apply-web-routes.sh --domain rivetit.example.com --yes-diff-sha <hash printed by the dry run>
#
# TRUST MODEL (read this once). This script runs as root, but the app tree it lives in is owned by the web user on most
# installs, so NOTHING from the app tree is ever executed as root:
#   - the PHP analysis and rendering (deploy/lib/web_routes_tool.php) runs as the app's own unprivileged user (runuser -u);
#     it can only print text to a pipe root holds, never write anywhere;
#   - root treats everything that text contains as UNTRUSTED INPUT: it is checked by the validator embedded in THIS file
#     (a strict allow-list: only the directives the routes use; no include of other paths, no load_module, lua*, perl*, js_*,
#     proxy_pass, alias or root; a vhost may differ from the installed one only by registry-defined location blocks and the
#     managed block);
#   - root then shows you the diff and asks you to confirm it, takes the backup, writes, runs nginx -t, reloads, and rolls
#     everything back on any failure. This script sources and executes no other file from the app tree.
# The one thing that must be trustworthy is this script itself. On a box where the app tree is web-writable, install a
# root-owned copy once and run that:   sudo deploy/apply-web-routes.sh --install-root-copy   (see docs/WEB_ROUTES.md).
#
# What it does, in order:
#   1. finds the nginx vhost(s) for the domain (or the ones you name);
#   2. renders the shared routes file and, for a vhost that still carries its locations inline (an older install), converts
#      it to ONE managed block that includes that file - only when it can prove the result serves the same things (anything
#      unrecognised is left alone and reported; if it cannot prove it, the vhost is NOT touched and exact manual steps print);
#   3. validates the result strictly, shows the diff, and waits for your confirmation;
#   4. backs up every file it changes, runs `nginx -t`, reloads ONLY if that passes, and rolls everything back if the test or
#      the reload fails.
# Running it again when everything is current changes nothing and does not reload nginx.
#
# Options:
#   --domain NAME        the site's domain (as in server_name); repeatable
#   --vhost FILE         an explicit vhost file; repeatable (use when --domain finds nothing)
#   --all                every RivetIT vhost found under the nginx folder (several sites sharing one routes file)
#   --php-sock PATH      the PHP-FPM socket for the sites (default: detected from each vhost's existing fastcgi_pass)
#   --php-sock-for NAME=PATH   a different socket for the vhost file NAME (file name only); repeatable
#   --hsts direct|proxy  direct: this box terminates public TLS and sends HSTS; proxy: an upstream proxy owns that
#   --snippet-dir DIR    where the routes file lives (default /etc/nginx/snippets)
#   --legacy-snippet NAME  another older shared snippet to recognise besides itflow-locations.conf; repeatable
#   --backup-dir DIR     keep this run's backups here (default /var/backups/rivetit/nginx-<time>)
#   --dry-run            show the diff and its hash (DIFF-SHA) and change nothing
#   --yes-diff-sha HASH  apply, but only if the diff is exactly the one whose hash you give (printed by --dry-run)
#   --yes                apply, but only if a --dry-run in the last hour showed exactly this diff
#   (neither: an interactive terminal shows the diff and asks y/N; without a terminal the run is refused)
#   --check              change nothing; exit 2 if the routes are out of date (for monitoring), 0 if current
#   --rollback DIR       put back the files saved in a previous run's backup folder, then test and reload
#   --install-root-copy  copy this script, the tool, the registry and the routes template to /usr/local/lib/rivetit (root-owned)
#   --allow-untrusted-script   run although this script file is writable by non-root (you reviewed it)
#   --check-trust PATH   print whether PATH and all its parent directories are root-owned and not group/world writable
#   --help
# Advanced / testing (a non-default --nginx-dir never reloads the real nginx unless --reload-cmd says so):
#   --nginx-dir DIR  --nginx-conf FILE  --nginx-bin PATH  --reload-cmd "CMD ARGS"  --routes-file FILE  --state-dir DIR
#   --root-copy-dir DIR
#
# Exit codes: 0 done or already current; 1 failed or refused (anything half-done was rolled back; the message says what to
#             look at); 2 --check: routes are out of date; 3 done, but at least one vhost needs the manual steps printed.
#
# Run it as root (sudo). It refuses to run as the web user: the web server's own account must never be able to rewrite nginx.
# Docker installs do not use this (docker/nginx.conf is their single source); Apache installs use .htaccess.

SCRIPT_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname -- "${SCRIPT_PATH}")"
APP_ROOT_DIR="$(dirname -- "${SCRIPT_DIR}")"

# ---- output helpers (self-contained on purpose: nothing is sourced from the app tree) ----
if [[ -t 2 ]]; then C_RED=$'\033[0;31m'; C_GREEN=$'\033[0;32m'; C_YELLOW=$'\033[0;33m'; C_BLUE=$'\033[0;34m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_RESET=""; fi
_ts() { date '+%Y-%m-%d %H:%M:%S'; }
info()     { printf '%s %s[INFO]%s %s\n' "$(_ts)" "${C_BLUE}" "${C_RESET}" "$*"; }
success()  { printf '%s %s[ OK ]%s %s\n' "$(_ts)" "${C_GREEN}" "${C_RESET}" "$*"; }
warn()     { printf '%s %s[WARN]%s %s\n' "$(_ts)" "${C_YELLOW}" "${C_RESET}" "$*" >&2; }
error()    { printf '%s %s[FAIL]%s %s\n' "$(_ts)" "${C_RED}" "${C_RESET}" "$*" >&2; }
die()      { error "$*"; exit 1; }
announce() { printf '%s %s%s[ACTION]%s %s\n' "$(_ts)" "${C_BOLD}" "${C_YELLOW}" "${C_RESET}" "$*"; }
command_exists() { command -v "$1" >/dev/null 2>&1; }
# Anything that came out of the unprivileged tool is printed through this: printable ASCII only, no terminal escapes.
clean() { LC_ALL=C tr -cd '[:print:]\n'; }

ROUTES_BASENAME="rivetit-locations.conf"
ROUTES_FILE="${APP_ROOT_DIR}/deploy/templates/${ROUTES_BASENAME}"
TOOL="${APP_ROOT_DIR}/deploy/lib/web_routes_tool.php"
TRUSTED_COPY_DIR="/usr/local/lib/rivetit"

DOMAINS=()
VHOSTS=()
LEGACY_SNIPPETS=("itflow-locations.conf")
declare -A PHP_SOCK_FOR=()
ALL=0
PHP_SOCK=""
HSTS=""
NGINX_DIR="/etc/nginx"
SNIPPET_DIR=""
BACKUP_ROOT="/var/backups/rivetit"
BACKUP_DIR=""
STATE_DIR="/var/lib/rivetit"
DRY_RUN=0
CHECK=0
YES=0
YES_SHA=""
ROLLBACK_FROM=""
INSTALL_COPY=0
ALLOW_UNTRUSTED=0
CHECK_TRUST=""
NGINX_CONF=""
NGINX_BIN="nginx"
RELOAD_CMD=""

STAGE=""
APPLYING=0
COMPLETED=0
APP_USER=""
CH_DEST=()    # files to write
CH_NEW=()     # staged content for each
MANUAL=()     # vhosts needing manual steps
declare -A MANUAL_REASONS=()

usage() { sed -n '3,/^SCRIPT_PATH=/p' "${BASH_SOURCE[0]}" | sed -e '$d' -e 's/^# \{0,1\}//'; }

need_val() { [[ $# -ge 2 && -n "${2}" ]] || die "Option $1 needs a value (see --help)."; }

set_sock_for() {
    [[ "$1" == *=* && -n "${1%%=*}" && -n "${1#*=}" ]] || die "--php-sock-for needs NAME=PATH (got '$1')."
    PHP_SOCK_FOR["${1%%=*}"]="${1#*=}"
}

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
            --yes) YES=1; shift ;;
            --yes-diff-sha=*) YES_SHA="${1#*=}"; shift ;;
            --yes-diff-sha) need_val "$@"; YES_SHA="$2"; shift 2 ;;
            --rollback=*) ROLLBACK_FROM="${1#*=}"; shift ;;
            --rollback) need_val "$@"; ROLLBACK_FROM="$2"; shift 2 ;;
            --install-root-copy) INSTALL_COPY=1; shift ;;
            --allow-untrusted-script) ALLOW_UNTRUSTED=1; shift ;;
            --check-trust) need_val "$@"; CHECK_TRUST="$2"; shift 2 ;;
            --root-copy-dir=*) TRUSTED_COPY_DIR="${1#*=}"; shift ;;
            --root-copy-dir) need_val "$@"; TRUSTED_COPY_DIR="$2"; shift 2 ;;
            --state-dir=*) STATE_DIR="${1#*=}"; shift ;;
            --state-dir) need_val "$@"; STATE_DIR="$2"; shift 2 ;;
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

# ---------------------------------------------------------------------------
# trust check (the same text is in deploy/update.sh; tests/web_routes_trust.php keeps them identical)
# ---------------------------------------------------------------------------
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

# ---------------------------------------------------------------------------
# who may run this
# ---------------------------------------------------------------------------
test_mode() {   # a non-root run is allowed ONLY on private copies, never against the real /etc/nginx
    [[ "${RIVETIT_ROUTES_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 && "${NGINX_DIR%/}" != "/etc/nginx" \
       && "${SNIPPET_DIR}" != /etc/nginx/* && "${BACKUP_DIR}" != /var/backups/* && "${ROLLBACK_FROM:-x}" != /var/backups/* \
       && "${STATE_DIR}" != /var/lib/* && ( "${INSTALL_COPY}" -eq 0 || "${TRUSTED_COPY_DIR}" != /usr/* ) ]]
}

check_caller() {
    local me
    me="$(id -un 2>/dev/null || echo unknown)"
    if [[ "${me}" == "www-data" || "${SUDO_USER:-}" == "www-data" ]]; then
        die "This must never be run as the web server's user (www-data): that account must not be able to rewrite nginx. Run it from an administrator account with sudo."
    fi
    [[ "${EUID}" -eq 0 ]] && return 0
    test_mode && return 0
    die "This script changes the web server's configuration and must be run as root. Re-run with: sudo $0 $*"
}

# This file is the one piece of code that runs as root. If a non-root account can modify it, say so; refuse when nobody is there to see.
check_self_trust() {
    local why
    if [[ "${RIVETIT_ROUTES_TEST_STRICT:-}" != "1" ]] && test_mode; then return 0; fi
    if why="$(rivetit_path_trusted "${SCRIPT_PATH}")"; then return 0; fi
    if [[ "${ALLOW_UNTRUSTED}" -eq 1 ]]; then warn "Running this script although it is not root-protected (${why}), as you asked."; return 0; fi
    if [[ -t 0 && -t 1 ]]; then
        warn "This copy of the script is not root-protected (${why}), so whoever can write there can change what runs as root."
        warn "Prefer a root-owned copy:  sudo ${SCRIPT_PATH} --install-root-copy   then run ${TRUSTED_COPY_DIR}/deploy/apply-web-routes.sh"
        return 0
    fi
    die "Refusing to run unattended from a copy that is not root-protected (${why}). Run: sudo ${SCRIPT_PATH} --install-root-copy, then run ${TRUSTED_COPY_DIR}/deploy/apply-web-routes.sh. (Or pass --allow-untrusted-script if you have reviewed this file.)"
}

# ---------------------------------------------------------------------------
# privilege drop: the app's PHP only ever runs as an unprivileged user, with its stdout going to a file ROOT opened
# ---------------------------------------------------------------------------
determine_app_user() {
    local owner uid
    owner="$(stat -c '%U' -- "${TOOL}")"
    uid="$(stat -c '%u' -- "${TOOL}")"
    if [[ "${EUID}" -ne 0 ]]; then APP_USER="$(id -un)"; return 0; fi   # (test mode only)
    if [[ "${uid}" == "0" ]]; then owner="nobody"; fi
    id -u "${owner}" >/dev/null 2>&1 || die "The user '${owner}' (owner of the tool) does not exist; cannot drop privileges."
    [[ "$(id -u "${owner}")" != "0" ]] || die "Refusing to run the tool as uid 0."
    APP_USER="${owner}"
}

as_app() {
    local bin
    if [[ "${EUID}" -eq 0 ]]; then
        if command_exists runuser; then bin="runuser"; else die "runuser (util-linux) is required to drop privileges."; fi
        "${bin}" -u "${APP_USER}" -- "$@"
    elif [[ -n "${RIVETIT_RUNUSER_BIN:-}" ]] && test_mode; then
        "${RIVETIT_RUNUSER_BIN}" -u "${APP_USER}" -- "$@"
    else
        "$@"   # test mode as a normal user: nothing to drop
    fi
}

# ---------------------------------------------------------------------------
# the validator: the ONLY thing allowed to bless text that came from the app tree. Runs as root, embedded here so it is
# exactly as trustworthy as this file. python3 stdlib only.
# ---------------------------------------------------------------------------
read -r -d '' PY_VALIDATOR <<'PYEOF' || true
import sys, re, json

class Bad(Exception):
    pass

def parse(t):
    n = len(t)
    pos = [0]
    def word():
        i = pos[0]
        q = t[i]
        if q in '"\'':
            i += 1
            out = []
            while i < n and t[i] != q:
                if t[i] == '\\' and i + 1 < n:
                    out.append(t[i:i+2]); i += 2; continue
                out.append(t[i]); i += 1
            if i >= n:
                raise Bad('unterminated string')
            pos[0] = i + 1
            return ''.join(out)
        out = ''
        while i < n:
            c = t[i]
            if c.isspace() or c in ';{}':
                if c == '{' and out.endswith('$'):
                    j = t.find('}', i)
                    if j < 0:
                        raise Bad('bad variable')
                    out += t[i:j+1]; i = j + 1; continue
                break
            out += c; i += 1
        pos[0] = i
        return out
    def block(nested):
        nodes, words = [], []
        while pos[0] < n:
            c = t[pos[0]]
            if c.isspace():
                pos[0] += 1; continue
            if c == '#':
                j = t.find('\n', pos[0]); pos[0] = n if j < 0 else j + 1; continue
            if c == ';':
                if not words: raise Bad('unexpected ;')
                pos[0] += 1; nodes.append((words[0], words[1:], None)); words = []; continue
            if c == '{':
                if not words: raise Bad('unexpected {')
                pos[0] += 1; ch = block(True); nodes.append((words[0], words[1:], ch)); words = []; continue
            if c == '}':
                if not nested: raise Bad('unbalanced }')
                if words: raise Bad('missing ;')
                pos[0] += 1; return nodes
            words.append(word())
        if nested: raise Bad('unclosed block')
        if words: raise Bad('missing ; at end')
        return nodes
    return block(False)

def loc_key(nd):
    name, args, ch = nd
    if name != 'location' or ch is None or not args: return None
    if args[0] in ('=', '^~', '~', '~*') and len(args) > 1: return args[0] + ' ' + args[1]
    return args[0]

def safe(s):
    return re.sub(r'[^A-Za-z0-9_.$-]', '?', s)[:40]

HDRS = {'Cache-Control', 'Strict-Transport-Security', 'Permissions-Policy', 'X-Content-Type-Options', 'Referrer-Policy',
        'X-Frame-Options', 'Content-Disposition', 'Pragma'}
TRY = re.compile(r'^(\$uri/?|=40[34]|/api/v[12]/index\.php\?\$query_string|\$fastcgi_script_name)$')

def check(nd, parent, errs):
    name, args, ch = nd
    def bad(msg): errs.append('%s: %s' % (safe(name), msg))
    if parent == 'types':
        if not re.match(r'^[a-z]+/[A-Za-z0-9.+-]+$', name) or ch is not None or not all(re.match(r'^[a-z0-9]+$', a) for a in args):
            bad('not a plain mime mapping')
        return
    if name == 'location':
        if ch is None or not args: bad('malformed location')
    elif name == 'return':
        if args in (['403'], ['404']): pass
        elif len(args) == 2 and args[0] in ('301', '302') and re.match(r'^/[A-Za-z0-9_./#&=$-]*$', args[1]): pass
        else: bad('only 403/404 or a redirect to a path on this site is allowed')
    elif name == 'try_files':
        if not args or not all(TRY.match(a) for a in args): bad('unexpected try_files target')
    elif name == 'deny':
        if args != ['all']: bad('only "deny all" is allowed')
    elif name == 'internal':
        if args: bad('takes no arguments')
    elif name == 'types':
        if ch is None: bad('needs a block')
    elif name == 'default_type':
        if args != ['application/octet-stream']: bad('unexpected value')
    elif name == 'absolute_redirect':
        if args != ['off']: bad('unexpected value')
    elif name == 'access_log':
        if args != ['off']: bad('only "access_log off" is allowed')
    elif name == 'include':
        if args not in (['snippets/fastcgi-php.conf'], ['fastcgi_params']): bad('include of an unexpected file')
    elif name == 'fastcgi_pass':
        if args != ['$rivetit_php']: bad('must be $rivetit_php')
    elif name == 'fastcgi_read_timeout':
        if len(args) != 1 or not re.match(r'^[0-9]+s$', args[0]): bad('unexpected value')
    elif name == 'fastcgi_buffering':
        if args != ['off']: bad('unexpected value')
    elif name == 'fastcgi_hide_header':
        if args != ['X-Frame-Options']: bad('unexpected header')
    elif name == 'limit_req':
        if not args or args[0] != 'zone=itflow_login' or not all(re.match(r'^(burst=[0-9]+|nodelay)$', x) for x in args[1:]): bad('unexpected limit_req')
    elif name == 'fastcgi_param':
        if len(args) != 2: bad('unexpected form')
        elif args[0] == 'SCRIPT_FILENAME':
            if not re.match(r'^\$document_root/[A-Za-z0-9_./-]+$', args[1]) or '..' in args[1]: bad('script path must be under $document_root')
        elif args[0] == 'SCRIPT_NAME':
            if not re.match(r'^/[A-Za-z0-9_./-]+$', args[1]) or '..' in args[1]: bad('unexpected script name')
        else: bad('only SCRIPT_FILENAME and SCRIPT_NAME may be set')
    elif name == 'add_header':
        if len(args) not in (2, 3) or args[0] not in HDRS or (len(args) == 3 and args[2] != 'always'): bad('unexpected header')
        elif '$' in args[1] and args[1] != '$rivetit_hsts': bad('unexpected variable in a header value')
        elif '$' not in args[1] and not re.match(r'^[A-Za-z0-9 ,;=()/._*:-]*$', args[1]): bad('unexpected characters in a header value')
        elif args[1] == '$rivetit_hsts' and args[0] != 'Strict-Transport-Security': bad('$rivetit_hsts is only for HSTS')
    elif name == 'if':
        if len(args) < 3 or args[0] != '($args' or args[1] != '~' or ch is None: bad('only the kiosk $args redirect is allowed')
        elif any(c[0] not in ('return', 'access_log') for c in ch): bad('an if block may only return or switch the log off')
    else:
        bad('directive not allowed in the routes file')
        return
    if ch is not None:
        for c in ch:
            check(c, name, errs)

def validate_routes(text, keys):
    errs = []
    if len(text) > 200000: raise Bad('routes file is unreasonably large')
    nodes = parse(text)
    seen = set()
    for nd in nodes:
        k = loc_key(nd)
        if k is None:
            errs.append('%s: only location blocks may appear at the top level' % safe(nd[0])); continue
        if k not in keys: errs.append('location "%s" is not in the route registry' % safe(k))
        if k in seen: errs.append('location "%s" appears twice' % safe(k))
        seen.add(k)
        check(nd, '', errs)
    missing = [k for k in keys if k not in seen]
    if missing: errs.append('%d registry locations are missing from the routes file' % len(missing))
    return errs

SOCK = re.compile(r'^(unix:/(run|var/run|tmp)/[A-Za-z0-9_./-]+|127\.0\.0\.1:[0-9]{1,5})$')

def strip(nodes, keys, legacy):
    out = []
    for nd in nodes:
        name, args, ch = nd
        if name == 'location' and loc_key(nd) in keys: continue
        if name == 'include' and args and args[0].rsplit('/', 1)[-1] in legacy: continue
        if name == 'set' and args and args[0] in ('$rivetit_php', '$rivetit_hsts'): continue
        out.append((name, args, None if ch is None else strip(ch, keys, legacy) if name in ('http', 'server') else ch))
    return out

def servers(nodes):
    res = []
    for name, args, ch in nodes:
        if name == 'server' and ch is not None: res.append(ch)
        elif ch is not None and name in ('http', 'events', 'stream'): res.extend(servers(ch))
    return res

def validate_vhost(old, new, keys, legacy, spec):
    errs = []
    if len(new) > max(1000000, 2 * len(old)): raise Bad('vhost is unreasonably large')
    o, n = parse(old), parse(new)
    legacy = set(legacy) | {'rivetit-locations.conf'}
    if json.dumps(strip(o, keys, legacy), sort_keys=True) != json.dumps(strip(n, keys, legacy), sort_keys=True):
        errs.append('the new vhost differs from the installed one by more than registry-defined location blocks and the managed block')
    so, sn = servers(n), servers(o)
    if len(so) != len(sn): errs.append('the number of server blocks changed')
    for srv in so:
        sets_php = [a for (nm, a, c) in srv if nm == 'set' and a and a[0] == '$rivetit_php']
        sets_hsts = [a for (nm, a, c) in srv if nm == 'set' and a and a[0] == '$rivetit_hsts']
        incs = [a for (nm, a, c) in srv if nm == 'include' and a and a[0].rsplit('/', 1)[-1] == 'rivetit-locations.conf']
        if not (sets_php or sets_hsts or incs): continue
        if len(sets_php) != 1 or len(sets_hsts) != 1 or len(incs) != 1:
            errs.append('a server block needs exactly one $rivetit_php, one $rivetit_hsts and one include of the routes file')
            continue
        if len(sets_php[0]) != 2 or not SOCK.match(sets_php[0][1]): errs.append('$rivetit_php must be a unix socket under /run, /var/run or /tmp (or 127.0.0.1:port)')
        if len(sets_hsts[0]) != 2 or sets_hsts[0][1] not in ('', 'max-age=63072000; includeSubDomains'): errs.append('$rivetit_hsts must be empty or the standard HSTS value')
        if incs[0] != [spec]: errs.append('the include must be exactly "%s"' % safe(spec))
    return errs

def main():
    cmd = sys.argv[1]
    try:
        if cmd == 'routes':
            keys = [l.rstrip('\n') for l in open(sys.argv[3], encoding='utf-8', errors='replace') if l.strip()]
            errs = validate_routes(open(sys.argv[2], encoding='utf-8').read(), keys)
        elif cmd == 'vhost':
            keys = set(l.rstrip('\n') for l in open(sys.argv[4], encoding='utf-8', errors='replace') if l.strip())
            legacy = [x for x in sys.argv[5].split(',') if x]
            errs = validate_vhost(open(sys.argv[2], encoding='utf-8').read(), open(sys.argv[3], encoding='utf-8').read(), keys, legacy, sys.argv[6])
        else:
            errs = ['unknown validator command']
    except (Bad, UnicodeDecodeError, OSError) as e:
        errs = ['could not be read: %s' % safe(str(e))]
    for e in errs: print('REJECTED: ' + e)
    sys.exit(1 if errs else 0)

main()
PYEOF

run_validator() { python3 -I -c "${PY_VALIDATOR}" "$@"; }

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------
cleanup() {
    local rc=$?
    if [[ "${APPLYING}" -eq 1 && "${COMPLETED}" -eq 0 ]]; then
        error "Stopped unexpectedly (exit ${rc}); putting every file back."
        do_rollback "${BACKUP_DIR}" || true
    fi
    if [[ -n "${STAGE}" && -d "${STAGE}" ]]; then rm -rf "${STAGE}"; fi
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
    cp -- "${src}" "${tmp}"
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
            M) cp -a -- "${dir}/files${path}" "${path}" || rc=1 ;;
            N) rm -f -- "${path}" || rc=1 ;;
        esac
    done < "${dir}/MANIFEST"
    return "${rc}"
}

print_diff() {   # print_diff OLD NEW LABEL  (labels only, no timestamps: the output, and so its hash, is reproducible)
    local old="$1" new="$2" label="$3"
    if [[ -e "${old}" ]]; then
        diff -u --label "${label} (current)" --label "${label} (after)" "${old}" "${new}" || true
    else
        diff -u --label "/dev/null" --label "${label} (new file)" /dev/null "${new}" || true
    fi
}

sha_of() { sha256sum | cut -d' ' -f1; }

# The confirmation gate: nothing is written until a human has seen THIS diff.
confirm_diff() {   # confirm_diff HASH
    local hash="$1" ans last_hash last_ts
    if [[ -n "${YES_SHA}" ]]; then
        [[ "${YES_SHA}" == "${hash}" ]] || die "These are not the changes you reviewed (diff hash is ${hash}, you passed ${YES_SHA}). Run --dry-run again and read the new diff."
        info "Confirmed: the diff hash matches the one you passed."
    elif [[ "${YES}" -eq 1 ]]; then
        read -r last_hash last_ts < "${STATE_DIR}/last-dry-run" 2>/dev/null || { last_hash=""; last_ts=0; }
        if [[ "${last_hash}" != "${hash}" || $(( $(date +%s) - ${last_ts:-0} )) -gt 3600 ]]; then
            die "--yes needs a --dry-run in the last hour that showed exactly this diff (hash ${hash}); none did. Run --dry-run, read it, then repeat."
        fi
        info "Confirmed: a dry run in this session showed exactly this diff."
    elif [[ -t 0 ]]; then
        printf 'Diff hash %s. Apply these changes to the nginx configuration? [y/N] ' "${hash}"
        read -r ans || ans=""
        [[ "${ans}" == "y" || "${ans}" == "Y" || "${ans}" == "yes" ]] || die "Not confirmed; nothing was changed."
    else
        die "Refusing to change the nginx configuration without a human review. Read the diff above, then re-run with: --yes-diff-sha ${hash}"
    fi
}

remember_dry_run() {
    mkdir -p "${STATE_DIR}" && chmod 700 "${STATE_DIR}" 2>/dev/null || true
    printf '%s %s\n' "$1" "$(date +%s)" > "${STATE_DIR}/last-dry-run" 2>/dev/null || true
}

print_manual() {   # print_manual VHOST
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
# install a root-owned copy (the trust anchor on boxes where the app tree is writable by the web user)
# ---------------------------------------------------------------------------
install_root_copy() {
    local -a rel=(deploy/apply-web-routes.sh deploy/lib/web_routes_tool.php src/Ops/NginxConf.php src/Ops/WebRoutes.php src/Ops/WebRoutesConverter.php "deploy/templates/${ROUTES_BASENAME}")
    local f manifest hash why
    manifest=""
    for f in "${rel[@]}"; do
        [[ -f "${APP_ROOT_DIR}/${f}" && ! -L "${APP_ROOT_DIR}/${f}" ]] || die "${APP_ROOT_DIR}/${f} is missing or a symlink; refusing to copy."
        manifest+="$(sha256sum -- "${APP_ROOT_DIR}/${f}" | cut -d' ' -f1)  ${f}"$'\n'
    done
    printf '%s' "${manifest}"
    hash="$(printf '%s' "${manifest}" | sha_of)"
    echo "DIFF-SHA: ${hash}"
    info "These files will be copied, as root-owned read-only files, to ${TRUSTED_COPY_DIR}/ . Compare the hashes with the reviewed commit (git hash-object is not the same; use sha256sum on a clean checkout)."
    if [[ "${DRY_RUN}" -eq 1 ]]; then remember_dry_run "${hash}"; info "Dry run: nothing copied. Apply with: --install-root-copy --yes-diff-sha ${hash}"; return 0; fi
    confirm_diff "${hash}"
    install -d -m 0755 -o root -g root "${TRUSTED_COPY_DIR}" 2>/dev/null || install -d -m 0755 "${TRUSTED_COPY_DIR}"
    for f in "${rel[@]}"; do
        install -d -m 0755 "${TRUSTED_COPY_DIR}/$(dirname "${f}")"
        if [[ "${f}" == *.sh ]]; then install -m 0755 -- "${APP_ROOT_DIR}/${f}" "${TRUSTED_COPY_DIR}/${f}"; else install -m 0644 -- "${APP_ROOT_DIR}/${f}" "${TRUSTED_COPY_DIR}/${f}"; fi
        if [[ "${EUID}" -eq 0 ]]; then chown root:root "${TRUSTED_COPY_DIR}/${f}"; fi
    done
    if [[ "${EUID}" -eq 0 ]]; then chown root:root "${TRUSTED_COPY_DIR}" "${TRUSTED_COPY_DIR}/deploy" "${TRUSTED_COPY_DIR}/deploy/lib" "${TRUSTED_COPY_DIR}/deploy/templates" "${TRUSTED_COPY_DIR}/src" "${TRUSTED_COPY_DIR}/src/Ops"; fi
    if why="$(rivetit_path_trusted "${TRUSTED_COPY_DIR}/deploy/apply-web-routes.sh")"; then
        success "Installed. Run it with: sudo ${TRUSTED_COPY_DIR}/deploy/apply-web-routes.sh --domain <domain> --dry-run"
    else
        warn "Copied, but the copy is not fully root-protected: ${why}"
    fi
    info "This copy is only as fresh as this install: after updating the app, re-run --install-root-copy to refresh it (an old copy keeps applying the old routes)."

}

# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------
main() {
    parse_args "$@"
    [[ -n "${SNIPPET_DIR}" ]] || SNIPPET_DIR="${NGINX_DIR%/}/snippets"

    if [[ -n "${CHECK_TRUST}" ]]; then
        if why="$(rivetit_path_trusted "${CHECK_TRUST}")"; then echo "trusted: ${CHECK_TRUST}"; exit 0; fi
        echo "NOT trusted: ${why}"; exit 1
    fi

    check_caller "$@"
    trap cleanup EXIT
    check_self_trust

    if [[ -n "${ROLLBACK_FROM}" ]]; then
        announce "Rolling back to the files saved in ${ROLLBACK_FROM}."
        do_rollback "${ROLLBACK_FROM}" || die "Rollback was incomplete; check the messages above and ${ROLLBACK_FROM}/MANIFEST."
        if out="$(nginx_test)"; then do_reload || die "Files restored, but the nginx reload failed. Check: systemctl status nginx"; success "Rolled back and nginx reloaded."; else printf '%s\n' "${out}" >&2; die "Files restored, but nginx -t still fails; the problem is not from this script's change."; fi
        exit 0
    fi
    if [[ "${INSTALL_COPY}" -eq 1 ]]; then install_root_copy; exit 0; fi

    command_exists php || die "php is required (it analyses the vhost files, as an unprivileged user)."
    command_exists python3 || die "python3 is required (it validates everything the app tree produced, before root installs it)."
    command_exists "${NGINX_BIN}" || die "nginx was not found (looked for '${NGINX_BIN}'). RivetIT on Docker or Apache does not use this script."
    [[ -f "${ROUTES_FILE}" ]] || die "Routes template not found at ${ROUTES_FILE}."
    [[ -f "${TOOL}" ]] || die "Helper not found at ${TOOL}."
    [[ -f "${NGINX_DIR%/}/snippets/fastcgi-php.conf" ]] || die "Expected ${NGINX_DIR%/}/snippets/fastcgi-php.conf (installed by the nginx package) is missing; the routes file needs it."
    if [[ "${#DOMAINS[@]}" -eq 0 && "${#VHOSTS[@]}" -eq 0 && "${ALL}" -eq 0 ]]; then
        usage >&2
        die "Say which site: --domain <name>, --vhost <file>, or --all."
    fi
    case "${HSTS}" in ""|direct|proxy) ;; *) die "--hsts must be 'direct' or 'proxy'." ;; esac
    [[ -z "${YES_SHA}" || "${YES_SHA}" =~ ^[0-9a-f]{64}$ ]] || die "--yes-diff-sha must be the 64-character hash printed by --dry-run."

    umask 077
    STAGE="$(mktemp -d)"
    chmod 700 "${STAGE}"
    determine_app_user
    local legacy_csv
    legacy_csv="$(IFS=,; echo "${LEGACY_SNIPPETS[*]}")"

    # 0. everything the app tree contributes comes in through the unprivileged user, into files root owns
    as_app php "${TOOL}" registry-keys 2>/dev/null | clean > "${STAGE}/keys" || die "The route registry could not be read."
    [[ -s "${STAGE}/keys" ]] || die "The route registry is empty or unreadable."
    as_app cat -- "${ROUTES_FILE}" > "${STAGE}/routes.in" 2>/dev/null || die "Could not read the routes template ${ROUTES_FILE}."
    [[ "$(stat -c %s "${STAGE}/routes.in")" -le 200000 ]] || die "The routes template is unreasonably large; refusing."
    local verdict
    if ! verdict="$(run_validator routes "${STAGE}/routes.in" "${STAGE}/keys")"; then
        error "The routes template was REJECTED by the root-side validator; nothing was changed:"
        printf '%s\n' "${verdict}" | head -20 >&2
        exit 1
    fi

    # 1. find the vhosts -----------------------------------------------------
    local -a found=() detect_args=("--nginx-dir=${NGINX_DIR}")
    local d f
    for d in "${DOMAINS[@]}"; do detect_args+=("--domain=${d}"); done
    [[ "${ALL}" -eq 1 ]] && detect_args+=(--all)
    for f in "${LEGACY_SNIPPETS[@]}"; do detect_args+=("--legacy-snippet=${f}"); done
    while IFS= read -r f; do [[ -n "${f}" ]] && found+=("${f}"); done < <(as_app php "${TOOL}" detect "${detect_args[@]}" | clean)
    for f in "${VHOSTS[@]}"; do
        [[ -f "${f}" ]] || die "--vhost ${f}: no such file."
        found+=("$(readlink -f "${f}")")
    done
    if [[ "${#found[@]}" -eq 0 ]]; then
        die "No nginx vhost with server_name ${DOMAINS[*]:-(any)} was found under ${NGINX_DIR%/}/sites-enabled or conf.d (or the vhost files are not world-readable, which the unprivileged analysis needs). Name the file yourself with --vhost <file>."
    fi
    local -a uniq=()
    local -A seen=()
    for f in "${found[@]}"; do [[ -n "${seen[$f]:-}" ]] || { seen[$f]=1; uniq+=("${f}"); }; done

    # 2. plan each vhost -----------------------------------------------------
    local i=0 out result includes sock_opt name any_includes=0 vhost_changes=0 off raw s
    for f in "${uniq[@]}"; do
        i=$((i + 1))
        name="$(basename "${f}")"
        sock_opt="${PHP_SOCK_FOR[$name]:-${PHP_SOCK}}"
        local -a targs=(convert "--vhost=${f}" "--routes=${ROUTES_FILE}" "--nginx-dir=${NGINX_DIR}" "--include=$(include_spec)")
        [[ -n "${sock_opt}" ]] && targs+=("--sock=${sock_opt}")
        [[ -n "${HSTS}" ]] && targs+=("--hsts=${HSTS}")
        raw="${STAGE}/v${i}.raw"
        as_app php "${TOOL}" "${targs[@]}" > "${raw}" 2>/dev/null || die "Could not analyse ${f} (is it readable by the unprivileged user '${APP_USER}'?)."
        off="$(grep -a -b -m1 '^---CONTENT---$' "${raw}" | cut -d: -f1 || true)"
        [[ "${off}" =~ ^[0-9]+$ ]] || die "The analysis of ${f} did not produce a result."
        head -c "${off}" "${raw}" | clean > "${STAGE}/v${i}.report"
        tail -c +"$((off + 15))" "${raw}" > "${STAGE}/v${i}.new"
        out="$(cat "${STAGE}/v${i}.report")"
        result="$(sed -n 's/^RESULT: //p' <<< "${out}" | head -n1)"
        includes="$(sed -n 's/^INCLUDES: //p' <<< "${out}" | head -n1)"
        [[ "${includes}" == "yes" ]] && any_includes=1
        info "${f}: ${result}"
        sed -n 's/^REMOVED: /    replaced by the shared routes: /p; s/^KEPT: /    left alone (not a RivetIT route): /p; s/^NOTE: /    note: /p' <<< "${out}"
        case "${result}" in
            changed)
                # root's own check of what the tool produced: only registry-defined differences, only the managed block
                if ! verdict="$(run_validator vhost "${f}" "${STAGE}/v${i}.new" "${STAGE}/keys" "${legacy_csv}" "$(include_spec)")"; then
                    error "The converted vhost for ${f} was REJECTED by the root-side validator; nothing was changed:"
                    printf '%s\n' "${verdict}" | head -20 >&2
                    exit 1
                fi
                CH_DEST+=("${f}"); CH_NEW+=("${STAGE}/v${i}.new"); vhost_changes=1
                s="$(sed -n 's/^SOCK: //p' <<< "${out}" | head -n1)"
                if [[ -n "${s}" && "${s}" == unix:* && ! -e "${s#unix:}" ]]; then
                    warn "${f}: the PHP socket ${s#unix:} does not exist on this machine (fine on a staging copy; on a live server fix it with --php-sock)."
                fi
                ;;
            unchanged) ;;
            manual)
                MANUAL+=("${f}")
                MANUAL_REASONS[$f]="$(sed -n 's/^REASON: //p' <<< "${out}")"
                ;;
            *) die "Unexpected analysis answer for ${f}." ;;
        esac
    done
    [[ "${vhost_changes}" -eq 1 ]] && any_includes=1

    # 3. the shared routes file and the rate-limit zone ------------------------
    local dest zone_dest
    dest="$(routes_dest)"
    if [[ "${any_includes}" -eq 1 || -e "${dest}" ]]; then
        if [[ ! -e "${dest}" ]] || ! cmp -s "${STAGE}/routes.in" "${dest}"; then
            CH_DEST+=("${dest}"); CH_NEW+=("${STAGE}/routes.in")
        fi
        if ! grep -rEqs --include='*.conf' '^[[:space:]]*limit_req_zone[[:space:]].*zone=itflow_login:' "${NGINX_DIR}" 2>/dev/null; then
            zone_dest="${NGINX_DIR%/}/conf.d/itflow-rate-limit.conf"
            printf '%s\n' '# Shared across every RivetIT vhost on this box, written by deploy/apply-web-routes.sh (same file deploy/install.sh writes).' \
                '# Do not duplicate this zone name in another file under conf.d/ - nginx refuses to start if it is defined twice.' \
                'limit_req_zone $binary_remote_addr zone=itflow_login:10m rate=5r/m;' > "${STAGE}/zone.new"
            CH_DEST+=("${zone_dest}"); CH_NEW+=("${STAGE}/zone.new")
        fi
    else
        warn "No vhost includes the routes file yet (see the manual steps below), so it is not installed."
    fi

    # 4. report / check -------------------------------------------------------
    local n_changes="${#CH_DEST[@]}" k
    if [[ "${CHECK}" -eq 1 ]]; then
        if [[ "${n_changes}" -gt 0 || "${#MANUAL[@]}" -gt 0 ]]; then
            warn "Web server routes are OUT OF DATE. Fix with:  sudo ${SCRIPT_PATH} $(printf -- '--domain %s ' "${DOMAINS[@]:-}")--dry-run"
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

    # 5. the diff a human must see (hash covers every byte of it) ---------------
    local diff_text="" hash
    for k in "${!CH_DEST[@]}"; do diff_text+="$(print_diff "${CH_DEST[$k]}" "${CH_NEW[$k]}" "${CH_DEST[$k]}")"$'\n'; done
    hash="$(printf '%s' "${diff_text}" | sha_of)"
    printf '%s\n' "${diff_text}" | clean
    echo "DIFF-SHA: ${hash}"

    if [[ "${DRY_RUN}" -eq 1 ]]; then
        remember_dry_run "${hash}"
        info "DRY RUN: nothing was written. To apply exactly this:  sudo ${SCRIPT_PATH} $(printf -- '--domain %s ' "${DOMAINS[@]:-}")--yes-diff-sha ${hash}"
        for f in "${MANUAL[@]}"; do print_manual "${f}"; done
        exit 0
    fi
    confirm_diff "${hash}"

    # 6. back up, write, test, reload -----------------------------------------
    [[ -n "${BACKUP_DIR}" ]] || BACKUP_DIR="${BACKUP_ROOT}/nginx-$(date +%Y%m%d-%H%M%S)"
    [[ ! -e "${BACKUP_DIR}/MANIFEST" ]] || die "${BACKUP_DIR} already holds a backup; pick another --backup-dir."
    mkdir -p "${BACKUP_DIR}/files"
    chmod 700 "${BACKUP_DIR}"
    : > "${BACKUP_DIR}/MANIFEST"
    for k in "${!CH_DEST[@]}"; do
        f="${CH_DEST[$k]}"
        if [[ -e "${f}" ]]; then
            mkdir -p "${BACKUP_DIR}/files$(dirname "${f}")"
            cp -a -- "${f}" "${BACKUP_DIR}/files${f}"
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
    info "To undo this change at any time:  sudo ${SCRIPT_PATH} --rollback ${BACKUP_DIR}"
    if [[ "${#MANUAL[@]}" -gt 0 ]]; then
        for f in "${MANUAL[@]}"; do print_manual "${f}"; done
        exit 3
    fi
    exit 0
}

main "$@"
