#!/usr/bin/env bash
# Rebuild the demo instance behind the user-guide screenshots, from nothing.
#
#   docs/user-guide/tools/build-demo.sh [options]
#
#   --app-dir=DIR   Scratch copy of the app to build (default: $TMPDIR/rivetit-user-guide-demo).
#                   Never the repository itself: the repo must not gain a config.php or uploads.
#   --db=NAME       Demo database to (re)create (default: rivetit_demo). DROPPED first.
#   --port=N        Port for the PHP built-in server (default: 8080).
#   --only=GLOB     Replay only seed files whose name matches, e.g. --only='00-*'.
#   --no-setup      Stop after creating the empty database and the app copy: no setup_cli.php, no
#                   modules, no seeds, and no config.php. The instance is left un-installed so the
#                   browser wizard (setup-wizard.cjs) can install it.
#   --no-server     Build everything but don't start the web server.
#   --force-db      Allow a database name that doesn't look like a demo/test one.
#   -h, --help      This help.
#
# What it does
#   1. Copies the repository into the scratch app directory.
#   2. Drops/recreates the demo database and runs scripts/setup_cli.php for the fictional company
#      "Summit Ridge Manufacturing" (administrator Alex Morgan).
#   3. Turns on the modules the guide documents (KB, Training, Live Chat, Department Portal, CSAT).
#   4. Replays every file in tools/seed/ in filename order (.sql via mysql, .php via php with
#      RIVETIT_APP_DIR pointing at the scratch app).
#   5. Starts PHP's built-in web server on 127.0.0.1:PORT.
#
# Then regenerate the screenshots:
#   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8080 node docs/user-guide/tools/run-all.cjs
#
# The server runs with Docker-style upload limits (500M) so the setup wizard's checks page looks like a
# properly provisioned server; install `whois` and `dnsutils` (dig) if you want those checks green too.
#
# Needs: bash, php (mysqli + zip), composer, tar, curl, and MariaDB/MySQL reachable as root over the local
# socket (override the client command with MYSQL_ROOT="mysql -u root -p...").
set -euo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${TOOLS_DIR}/../../.." && pwd)"

APP_DIR="${TMPDIR:-/tmp}/rivetit-user-guide-demo"
DB_NAME="rivetit_demo"
DB_USER="rivetit_demo"
DB_PASS="DemoPass_2026"
ADMIN_PASSWORD='DemoAdmin#2026'
PORT=8080
START_SERVER=1
SKIP_SETUP=0
ONLY=""
FORCE_DB=0
MYSQL_ROOT="${MYSQL_ROOT:-mysql -u root}"
MARKER=".rivetit-user-guide-demo"

usage() { sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'; }
die() { echo "build-demo: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }
stop_server() {
    local pidfile="$1" pid
    [[ -f "${pidfile}" ]] || return 0
    pid="$(cat "${pidfile}")"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
        pkill -P "${pid}" 2>/dev/null || true   # the built-in server's worker processes
        kill "${pid}" 2>/dev/null || true
        sleep 1
    fi
    rm -f "${pidfile}"
}

for arg in "$@"; do
    case "${arg}" in
        --app-dir=*)  APP_DIR="${arg#*=}" ;;
        --db=*)       DB_NAME="${arg#*=}" ;;
        --port=*)     PORT="${arg#*=}" ;;
        --only=*)     ONLY="${arg#*=}" ;;
        --no-server)  START_SERVER=0 ;;
        --no-setup)   SKIP_SETUP=1 ;;
        --force-db)   FORCE_DB=1 ;;
        -h|--help)    usage; exit 0 ;;
        *)            usage >&2; die "unknown option: ${arg}" ;;
    esac
done

# ---- guards: this script drops a database and deletes a directory -----------------------------
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || die "database name must be letters, digits and underscores only"
if [[ "${FORCE_DB}" -ne 1 && ! "${DB_NAME}" =~ (demo|guide|test|rebuild) ]]; then
    die "refusing to DROP '${DB_NAME}': it doesn't look like a demo database. Use a name containing demo/guide/test/rebuild, or pass --force-db."
fi
[[ "${PORT}" =~ ^[0-9]+$ ]] || die "--port must be a number"
case "${APP_DIR}" in
    ""|"/"|"${REPO_ROOT}"|"${HOME:-/nonexistent}") die "refusing to use '${APP_DIR}' as the scratch app directory" ;;
esac
if [[ -e "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" && ! -f "${APP_DIR}/${MARKER}" ]]; then
    die "${APP_DIR} exists, isn't empty, and wasn't created by this script - choose another --app-dir"
fi

for cmd in php tar curl; do command -v "${cmd}" >/dev/null 2>&1 || die "${cmd} is required"; done
command -v "${MYSQL_ROOT%% *}" >/dev/null 2>&1 || die "the mysql client is required"

if ! ${MYSQL_ROOT} -e 'SELECT 1' >/dev/null 2>&1; then
    if command -v service >/dev/null 2>&1; then
        echo "MariaDB is not answering; trying to start it..."
        service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || true
        sleep 3
    fi
    ${MYSQL_ROOT} -e 'SELECT 1' >/dev/null 2>&1 || die "cannot reach MariaDB/MySQL as root (set MYSQL_ROOT to your client command)"
fi

START_TS=$(date +%s)

# ---- 1. scratch copy of the app ----------------------------------------------------------------
step "Copying the app to ${APP_DIR}"
rm -rf "${APP_DIR}"
mkdir -p "${APP_DIR}"
tar -C "${REPO_ROOT}" \
    --exclude=.git --exclude=node_modules --exclude=./config.php \
    --exclude=./docs/user-guide/images \
    -cf - . | tar -xf - -C "${APP_DIR}"
touch "${APP_DIR}/${MARKER}"
# keep only the tracked placeholders inside uploads/ (drop anything a developer's own use left behind)
if [[ -d "${APP_DIR}/uploads" ]]; then
    find "${APP_DIR}/uploads" -type f ! -name index.php ! -name .htaccess -delete
fi

# The repository tracks only part of vendor/ (the rest is installed by composer, as install.sh does), so a
# plain copy of a developer checkout can miss packages the code needs. Install them into the scratch copy.
if [[ -f "${APP_DIR}/composer.json" ]]; then
    command -v composer >/dev/null 2>&1 || die "composer is required to install the PHP dependencies (https://getcomposer.org)"
    step "Installing PHP dependencies (composer install --no-dev)"
    ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
fi

# ---- 2. database + first-run setup -------------------------------------------------------------
step "Creating database ${DB_NAME}"
${MYSQL_ROOT} <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

if [[ "${SKIP_SETUP}" -eq 0 ]]; then
  step "Running scripts/setup_cli.php (Summit Ridge Manufacturing)"
  ( cd "${APP_DIR}/scripts" && \
    RIVETIT_DB_PASSWORD="${DB_PASS}" RIVETIT_ADMIN_PASSWORD="${ADMIN_PASSWORD}" php setup_cli.php \
      --host=localhost --username="${DB_USER}" --database="${DB_NAME}" --base-url="localhost:${PORT}" \
      --locale=en_US --timezone=America/Chicago --currency=USD \
      --company-name="Summit Ridge Manufacturing" --country="United States" \
      --address="1200 Ridge Road" --city=Madison --state=WI --zip=53703 --phone=6085550100 \
      --company-email=it@summitridge.example --website=https://summitridge.example \
      --user-name="Alex Morgan" --user-email=alex.morgan@summitridge.example \
      --non-interactive ) | tail -3
  # http:// demo server: the session cookie must not be marked Secure-only
  sed -i 's/\$config_https_only = TRUE;/$config_https_only = FALSE;/' "${APP_DIR}/config.php"

  # db.sql is a schema snapshot (see its RIVETIT_SCHEMA_VERSION line); setup_cli.php records that version and
  # install.sh then runs the migrations that came after it. Do the same here, or newer features have no columns.
  step "Applying database migrations (scripts/update_cli.php --update_db)"
  # update_cli.php applies ONE version step per call (install.sh loops the same way), so repeat until done.
  for _ in $(seq 1 200); do
      out="$( cd "${APP_DIR}/scripts" && php update_cli.php --update_db 2>&1 )" || { echo "${out}" >&2; die "update_cli.php --update_db failed"; }
      [[ "${out}" == *"already at the latest version"* ]] && break
  done
  echo "  ${out}" | tail -1

  # ---- 3. modules the guide documents ------------------------------------------------------------
  step "Enabling the documented modules"
  ${MYSQL_ROOT} "${DB_NAME}" -e "UPDATE settings SET config_module_enable_kb=1, config_module_enable_training=1, config_module_enable_live_chat=1, config_client_portal_enable=1, config_ticket_csat_enable=1 WHERE company_id=1;"

  # ---- 4. demo data ------------------------------------------------------------------------------
  step "Replaying seed files"
  # The app keeps company-local times (America/Chicago) but a plain `mysql` session is UTC, so SQL seeds that
  # use NOW() would land hours in the future ("5 hours from now"). Give SQL seeds the company's UTC offset.
  # (PHP seeds load the app's own timezone code; seeds that manage the clock themselves just override this.)
  SEED_TZ_OFFSET="$(php -r 'date_default_timezone_set("America/Chicago"); echo date("P");')"
  mapfile -t SEEDS < <(find "${TOOLS_DIR}/seed" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.php' \) | sort)
  for f in "${SEEDS[@]}"; do
      base="$(basename "${f}")"
      if [[ -n "${ONLY}" && "${base}" != ${ONLY} ]]; then continue; fi
      echo "--- ${base}"
      case "${f}" in
          *.sql) ${MYSQL_ROOT} --init-command="SET time_zone='${SEED_TZ_OFFSET}'" "${DB_NAME}" < "${f}" || die "seed ${base} failed" ;;
          *.php) RIVETIT_APP_DIR="${APP_DIR}" php "${f}" || die "seed ${base} failed" ;;
      esac
  done
fi

# ---- 5. web server -----------------------------------------------------------------------------
if [[ "${START_SERVER}" -eq 1 ]]; then
    step "Starting the PHP built-in server on 127.0.0.1:${PORT}"
    PIDFILE="${APP_DIR}.server.pid"
    stop_server "${PIDFILE}"
    # exec the server directly with EVERY descriptor redirected: a wrapper shell left holding this
    # script's stdout would keep any pipe reading our output (tee, tail, a CI log) open forever.
    ( cd "${APP_DIR}" && exec env PHP_CLI_SERVER_WORKERS=8 php -d upload_max_filesize=500M -d post_max_size=500M -d memory_limit=512M -S "127.0.0.1:${PORT}" -t . >"${APP_DIR}.server.log" 2>&1 </dev/null ) &
    echo $! >"${PIDFILE}"
    disown 2>/dev/null || true
    for _ in $(seq 1 20); do
        if curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login.php" 2>/dev/null; then break; fi
        sleep 0.5
    done
    curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login.php" || { tail -5 "${APP_DIR}.server.log" >&2; die "the web server did not come up on port ${PORT}"; }
fi

echo
echo "Done in $(( $(date +%s) - START_TS ))s."
echo "  App directory : ${APP_DIR}"
echo "  Database      : ${DB_NAME}"
if [[ "${START_SERVER}" -eq 1 ]]; then
    echo "  URL           : http://127.0.0.1:${PORT}/   (stop it with: pkill -P \$(cat ${APP_DIR}.server.pid); kill \$(cat ${APP_DIR}.server.pid))"
fi
if [[ "${SKIP_SETUP}" -eq 1 ]]; then
    echo "  Not installed : open /setup/, or run:"
    echo "    DEMO_URL=http://127.0.0.1:${PORT} WIZ_DB=${DB_NAME} WIZ_DB_USER=${DB_USER} WIZ_DB_PASS='${DB_PASS}' NODE_PATH=\$(npm root -g) node docs/user-guide/tools/setup-wizard.cjs"
else
    echo "  Sign in       : alex.morgan@summitridge.example / ${ADMIN_PASSWORD}   (technicians: DemoPass#2026)"
    echo "  Screenshots   : NODE_PATH=\$(npm root -g) DEMO_URL=http://127.0.0.1:${PORT} node docs/user-guide/tools/run-all.cjs"
fi
