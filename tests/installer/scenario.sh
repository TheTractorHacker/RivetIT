#!/usr/bin/env bash
# Runs INSIDE the systemd container (started by run.sh). Not meant to be run on a real host: it installs
# packages, creates databases and edits /etc.
#
#   scenario.sh fresh     deploy/install.sh on the clean box, then verify everything
#   scenario.sh upgrade   install the previous release tag, add data, run the NEW deploy/update.sh, verify
#
# Environment (set by run.sh): ED (it|msp), HEAD_SHA, PREV_TAG, TREE_TAR, ADMIN_PW
set -uo pipefail

: "${ED:?}" "${HEAD_SHA:?}" "${PREV_TAG:?}" "${TREE_TAR:?}"
SCENARIO="${1:?fresh|upgrade}"
ADMIN_EMAIL="admin@installer.test"
ADMIN_PW="${ADMIN_PW:-Inst4ller-Test-Pw!93xQ}"
DOM="${ED}-${SCENARIO}.installer.test"
APP_DIR="/var/www/${DOM}"
DB="$(printf '%s' "$DOM" | tr 'A-Z' 'a-z' | sed -E 's/[^a-z0-9]+/_/g')"
LOGDIR=/work/logs; mkdir -p "$LOGDIR"

PASS=0; FAIL=0; FAILED=()
ok()   { PASS=$((PASS+1)); echo "PASS: $1"; }
bad()  { FAIL=$((FAIL+1)); FAILED+=("$1"); echo "FAIL: $1${2:+  [$2]}"; }
# check "description" command...   (command's own output is discarded; use detail() when you need it)
check() { local d="$1"; shift; local out; if out="$("$@" 2>&1)"; then ok "$d"; else bad "$d" "$(printf '%s' "$out" | tail -c 300 | tr '\n' ' ')"; fi; }
section() { echo; echo "=== $* ==="; }

INSTALL_FLAGS=(--non-interactive --skip-tls --skip-firewall
  --admin-name="Installer Admin" --admin-email="$ADMIN_EMAIL" --admin-password="$ADMIN_PW"
  --locale=en_US --timezone=America/Chicago --currency=USD --company-name="Installer Test Co" --country="United States")

# ---------------------------------------------------------------------------
# Source repositories: a local bare "origin" holding the full history plus a snapshot commit of the working
# tree being tested, so update.sh's git pull has something real to pull from without touching GitHub.
# ---------------------------------------------------------------------------
prepare_sources() {
  # update.sh follows the release channel (default Production = this edition's production branch of remote
  # 'origin'), so the snapshot under test is published as that branch of the in-container bare origin.
  mkdir -p /tmp/treesrc && tar -xf "$TREE_TAR" -C /tmp/treesrc includes/release_channel.php 2>/dev/null; TREE_SRC=/tmp/treesrc
  BRANCH="$(sed -n "s/.*define('RELEASE_BRANCH_PRODUCTION', *'\([^']*\)').*/\1/p" "$TREE_SRC/includes/release_channel.php" 2>/dev/null | head -1)"; BRANCH="${BRANCH:-main}"
  git config --system --add safe.directory '*'   # www-data runs git against the root-owned /srv/origin.git too
  git config --global user.name "Installer Harness"
  git config --global user.email "harness@installer.test"
  git config --global protocol.file.allow always
  git clone -q --bare /src-git /srv/origin.git || return 1
  git clone -q /srv/origin.git /srv/new || return 1
  ( cd /srv/new && git checkout -q -B "$BRANCH" "$HEAD_SHA" \
    && find . -path ./.git -prune -o -type f -print0 | xargs -0 rm -f \
    && tar -xf "$TREE_TAR" \
    && git add -A \
    && { git diff --cached --quiet || git commit -q -m "installer harness: working tree snapshot"; } \
    && git push -q -f origin "$BRANCH":"$BRANCH" ) || return 1
  NEW_SHA="$(git -C /srv/new rev-parse HEAD)"
  echo "new tree commit: $NEW_SHA (base ${HEAD_SHA:0:9}$([ "$NEW_SHA" != "$HEAD_SHA" ] && echo ' + uncommitted working-tree changes'))"
  if [ "$SCENARIO" = upgrade ]; then
    git clone -q /srv/origin.git /srv/old || return 1
    ( cd /srv/old && git checkout -q -B "$BRANCH" "$PREV_TAG" && git branch -q --set-upstream-to="origin/$BRANCH" "$BRANCH" ) || return 1
    git -C /srv/old merge-base --is-ancestor "$PREV_TAG" "origin/$BRANCH" || { echo "$PREV_TAG is not an ancestor of the tested tree"; return 1; }
  fi
}

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------
curl_app() { curl -sk --resolve "${DOM}:443:127.0.0.1" "$@"; }
code_of()  { curl_app -o /dev/null -w '%{http_code}' "$@"; }
sql()      { mysql -N -B "$DB" -e "$1"; }
latest_db_version() { sed -n 's/.*LATEST_DATABASE_VERSION", *"\([0-9.]*\)".*/\1/p' "$1/includes/database_version.php"; }
php_fpm_svc() { systemctl list-units --no-legend --plain 'php*-fpm.service' | awk '{print $1}' | head -1; }
locked_core_version() { php -r '$l=json_decode(file_get_contents($argv[1]."/composer.lock"),true); foreach($l["packages"] as $p) if($p["name"]==="rivet/rivet-core") echo ltrim($p["version"],"v");' "$1"; }
installed_core_version() { php -r '$i=json_decode(file_get_contents($argv[1]."/vendor/composer/installed.json"),true); foreach(($i["packages"]??$i) as $p) if($p["name"]==="rivet/rivet-core") echo ltrim($p["version"],"v");' "$1"; }

login_ok() {  # nginx limits login.php to 5 req/min (burst 3, answers 503), so back off and retry on 503
  local i out; for i in 1 2 3 4 5; do out="$(login_ok_once)" && return 0; case "$out" in *'HTTP 503'*) sleep 25;; *) echo "$out"; return 1;; esac; done; echo "$out"; return 1
}
login_ok_once() {  # scripted admin login: POST the real form, expect a redirect to somewhere that is not the login page
  local jar; jar="$(mktemp)"
  curl_app -c "$jar" -b "$jar" -o /dev/null "https://${DOM}/login.php" || return 1
  local hdr; hdr="$(curl_app -c "$jar" -b "$jar" -o /dev/null -D - \
      --data-urlencode "email=${ADMIN_EMAIL}" --data-urlencode "password=${ADMIN_PW}" -d login=1 "https://${DOM}/login.php")"
  local status loc; status="$(printf '%s' "$hdr" | head -1 | awk '{print $2}')"; loc="$(printf '%s' "$hdr" | tr -d '\r' | awk 'tolower($1)=="location:"{print $2}')"
  [ "$status" = 302 ] && [ -n "$loc" ] && ! printf '%s' "$loc" | grep -q 'login' || { echo "login POST -> HTTP $status Location '$loc'"; return 1; }
  case "$loc" in http*) ;; /*) loc="https://${DOM}${loc}";; *) loc="https://${DOM}/${loc}";; esac
  local c; c="$(curl_app -c "$jar" -b "$jar" -L -o /dev/null -w '%{http_code} %{url_effective}' "$loc")"
  [ "${c%% *}" = 200 ] && ! printf '%s' "$c" | grep -q 'login' || { echo "after login landing page -> $c"; return 1; }
}

core_migrations_complete() {
  ( cd "$APP_DIR" && sudo -u www-data php -r '
    require "config.php"; require "vendor/autoload.php";
    $m = new mysqli($dbhost,$dbusername,$dbpassword,$database);
    $want = [];
    foreach (\RivetCore\Migration\CoreMigrations::all() as $x) { $want[] = method_exists($x,"id") ? $x->id() : (method_exists($x,"version") ? $x->version() : get_class($x)); }
    $have = [];
    $r = $m->query("SELECT * FROM rivet_core_migrations");
    while ($row = $r->fetch_assoc()) { $have[] = (string) reset($row); }
    $missing = array_diff(array_map("strval",$want), $have);
    echo count($want)." core migrations known, ".count($have)." recorded\n";
    exit($missing ? (print("missing: ".implode(",",$missing)."\n")) * 0 + 1 : 0);' )
}

run_cron_jobs() {  # every command in this instance's /etc/cron.d file, once, as www-data (cron.php is inert until enabled in-app)
  local f line n=0 rc=0 cmd
  for f in $(grep -l "$APP_DIR" /etc/cron.d/* 2>/dev/null); do
    while IFS= read -r line; do
      [[ "$line" =~ ^[[:space:]]*# || -z "${line// }" || "$line" == *=* && "$line" != *"$APP_DIR"* ]] && continue
      cmd="$(printf '%s' "$line" | awk '{ $1=$2=$3=$4=$5=$6=""; sub(/^ +/,""); print }' | sed -E 's/ *>>? *[^ ]+ *(2>&1)?$//')"
      [ -n "$cmd" ] || continue
      n=$((n+1))
      if timeout 180 sudo -u www-data bash -c "$cmd" >/tmp/cron_job_$n.out 2>&1; then echo "ran OK: $cmd"; else echo "FAILED rc=$?: $cmd"; tail -5 /tmp/cron_job_$n.out; rc=1; fi
    done < "$f"
  done
  [ "$n" -gt 0 ] || { echo "no cron commands found for $APP_DIR"; return 1; }
  return $rc
}

verify_install() {   # $1 = label suffix; checks of a healthy instance (used after install and after update)
  local want_db; want_db="$(latest_db_version "$APP_DIR")"
  check "nginx active"           systemctl is-active --quiet nginx
  check "php-fpm active ($(php_fpm_svc))" systemctl is-active --quiet "$(php_fpm_svc)"
  check "mariadb active"         bash -c 'systemctl is-active --quiet mariadb || systemctl is-active --quiet mysql'
  check "nginx config valid"     nginx -t
  local c; c="$(code_of -L "https://${DOM}/")"
  [ "$c" = 200 ] && ok "https://${DOM}/ serves the login page (HTTP 200 after redirects)" || bad "https://${DOM}/ serves the login page" "HTTP $c"
  check "login.php body is the login form" bash -c "curl -sk --resolve ${DOM}:443:127.0.0.1 https://${DOM}/login.php | grep -qi 'name=\"password\"'"
  local have_db; have_db="$(sql 'SELECT config_current_database_version FROM settings WHERE company_id = 1' 2>&1)"
  [ "$have_db" = "$want_db" ] && ok "DB schema version $have_db == includes/database_version.php" || bad "DB schema version == includes/database_version.php" "db=$have_db code=$want_db"
  check "rivet_core_migrations has every Core migration" core_migrations_complete
  check "Redis answers PONG on 127.0.0.1:6380" bash -c '[ "$(redis-cli -h 127.0.0.1 -p 6380 ping)" = PONG ]'
  check "Redis is loopback-only"  bash -c '! ss -ltn "sport = :6380" | grep -E "0\.0\.0\.0:6380|\*:6380|\[::\]:6380"'
  local ready; ready="$(curl_app -w ' HTTP %{http_code}' "https://${DOM}/health/ready.php")"
  case "$ready" in *'"status":"ready"'*"HTTP 200") ok "health/ready.php reports ready ($ready)";; *) bad "health/ready.php reports ready" "$ready";; esac
  [ "$(code_of "https://${DOM}/health/live.php")" = 200 ] && ok "health/live.php 200" || bad "health/live.php 200"
  check "scripted admin login works" login_ok
  check "cron.d entry present"   bash -c "grep -l '$APP_DIR' /etc/cron.d/* >/dev/null"
  local cj; if cj="$(run_cron_jobs)"; then ok "every cron.d job runs once as www-data ($(printf '%s\n' "$cj" | grep -c 'ran OK') jobs)"; else bad "every cron.d job runs once as www-data" "$(printf '%s\n' "$cj" | grep -A6 FAILED | tr '\n' ' ' | cut -c1-400)"; fi
  local lock inst; lock="$(locked_core_version "$APP_DIR")"; inst="$(installed_core_version "$APP_DIR")"
  [ -n "$lock" ] && [ "$lock" = "$inst" ] && ok "RivetCore in vendor/ is the pinned version ($inst)" || bad "RivetCore in vendor/ is the pinned version" "lock=$lock installed=$inst"
  check "composer autoloader loads RivetCore" sudo -u www-data php -r 'require $argv[1]."/vendor/autoload.php"; exit(class_exists(\RivetCore\Migration\MigrationRunner::class) ? 0 : 1);' "$APP_DIR"
  # permissions
  local mode; mode="$(stat -c %a "$APP_DIR/config.php")"
  [ $(( 8#$mode & 8#007 )) -eq 0 ] && ok "config.php not world-accessible (mode $mode, $(stat -c %U:%G "$APP_DIR/config.php"))" || bad "config.php not world-accessible" "mode $mode"
  check "www-data can read config.php"  sudo -u www-data test -r "$APP_DIR/config.php"
  check "nobody cannot read config.php" bash -c "! sudo -u nobody test -r '$APP_DIR/config.php'"
  check "uploads/ writable by www-data" sudo -u www-data bash -c "t=\$(mktemp -p '$APP_DIR/uploads/tmp') && rm -f \"\$t\""
  check "uploads/ not writable by others" bash -c "! sudo -u nobody test -w '$APP_DIR/uploads' && ! sudo -u nobody test -w '$APP_DIR/uploads/tmp'"
  local ww; ww="$(find "$APP_DIR" -path "$APP_DIR/.git" -prune -o ! -type l -perm -o+w -print 2>/dev/null | head -5)"
  [ -z "$ww" ] && ok "no world-writable files or directories in the app tree" || bad "no world-writable files or directories" "$(echo $ww)"
  check "app tree owned by www-data" bash -c "[ -z \"\$(find '$APP_DIR' -path '$APP_DIR/.git' -prune -o ! -user www-data -print | head -1)\" ]"
}

# ---------------------------------------------------------------------------
# scenarios
# ---------------------------------------------------------------------------
scenario_fresh() {
  section "fresh install ($ED) from $(git -C /srv/new rev-parse --short HEAD)"
  /srv/new/deploy/install.sh --domain="$DOM" "${INSTALL_FLAGS[@]}" > "$LOGDIR/install.log" 2>&1
  local rc=$?
  [ $rc -eq 0 ] && ok "deploy/install.sh exits 0" || { bad "deploy/install.sh exits 0" "rc=$rc, see logs/install.log: $(tail -3 "$LOGDIR/install.log" | tr '\n' ' ')"; return; }
  verify_install

  section "idempotency: second install.sh run"
  local cfg_before db_before users_before; cfg_before="$(sha256sum "$APP_DIR/config.php" | cut -d' ' -f1)"; users_before="$(sql 'SELECT COUNT(*) FROM users')"
  /srv/new/deploy/install.sh --domain="$DOM" "${INSTALL_FLAGS[@]}" > "$LOGDIR/install2.log" 2>&1
  rc=$?
  if [ $rc -eq 0 ]; then
    ok "second install.sh exits 0"
    [ "$(sha256sum "$APP_DIR/config.php" | cut -d' ' -f1)" = "$cfg_before" ] && ok "second install.sh left config.php untouched" || bad "second install.sh left config.php untouched"
    [ "$(sql 'SELECT COUNT(*) FROM users')" = "$users_before" ] && ok "second install.sh did not duplicate users" || bad "second install.sh did not duplicate users"
    check "login still works after second install.sh" login_ok
    [ "$(code_of "https://${DOM}/health/ready.php")" = 200 ] && ok "ready after second install.sh" || bad "ready after second install.sh"
  else
    if grep -qiE 'already|refus' "$LOGDIR/install2.log"; then ok "second install.sh refused cleanly (rc=$rc: $(grep -iE 'already|refus' "$LOGDIR/install2.log" | tail -1 | cut -c1-160))"
    else bad "second install.sh idempotent or clean refusal" "rc=$rc $(tail -2 "$LOGDIR/install2.log" | tr '\n' ' ')"; fi
    check "instance still healthy after refused second install.sh" login_ok
  fi

  section "update.sh on an up-to-date fresh install"
  /srv/new/deploy/update.sh --app-dir="$APP_DIR" --no-backup-confirmed > "$LOGDIR/update_noop.log" 2>&1 && ok "update.sh no-op run exits 0" || bad "update.sh no-op run exits 0" "$(tail -3 "$LOGDIR/update_noop.log" | tr '\n' ' ')"
  check "login works after no-op update" login_ok
}

scenario_upgrade() {
  section "upgrade ($ED): install $PREV_TAG, then update to $(git -C /srv/new rev-parse --short HEAD)"
  /srv/old/deploy/install.sh --domain="$DOM" "${INSTALL_FLAGS[@]}" > "$LOGDIR/install_old.log" 2>&1
  local rc=$?
  [ $rc -eq 0 ] && ok "$PREV_TAG deploy/install.sh exits 0" || { bad "$PREV_TAG deploy/install.sh exits 0" "rc=$rc: $(tail -3 "$LOGDIR/install_old.log" | tr '\n' ' ')"; return; }
  local old_db new_db old_core; old_db="$(sql 'SELECT config_current_database_version FROM settings WHERE company_id = 1')"; new_db="$(latest_db_version /srv/new)"
  [ "$old_db" = "$(latest_db_version /srv/old)" ] && ok "old install is at its release schema ($old_db)" || bad "old install is at its release schema" "db=$old_db"
  [ "$old_db" != "$new_db" ] && ok "new code has a newer schema ($new_db) so the upgrade is real" || bad "new code has a newer schema than $PREV_TAG" "both $old_db"
  [ "$(git -C "$APP_DIR" rev-parse HEAD)" = "$(git -C /srv/old rev-parse HEAD)" ] && ok "old install code is at $PREV_TAG" || bad "old install code is at $PREV_TAG"
  old_core="$(installed_core_version "$APP_DIR")"; echo "old RivetCore: $old_core"

  section "seed data (client, ticket, webhook) via SQL"
  sql "INSERT INTO clients SET client_name='Upgrade Survivor Co', client_currency_code='USD', client_net_terms=30" \
    && CID="$(sql "SELECT client_id FROM clients WHERE client_name='Upgrade Survivor Co'")" \
    && sql "INSERT INTO tickets SET ticket_number=9001, ticket_subject='Survives the upgrade', ticket_details='seeded by tests/installer', ticket_status=1, ticket_created_by=1, ticket_client_id=$CID" \
    && sql "INSERT INTO webhooks SET webhook_name='Upgrade survivor hook', webhook_url='https://example.invalid/hook', webhook_events='ticket.created'" \
    && ok "seed data inserted" || { bad "seed data inserted"; return; }
  check "admin can log in on the old release" login_ok
  local users_before; users_before="$(sql 'SELECT COUNT(*) FROM users')"

  section "deploy/update.sh from the NEW checkout"
  /srv/new/deploy/update.sh --app-dir="$APP_DIR" --no-backup-confirmed > "$LOGDIR/update.log" 2>&1
  rc=$?
  [ $rc -eq 0 ] && ok "update.sh exits 0" || { bad "update.sh exits 0" "rc=$rc: $(tail -4 "$LOGDIR/update.log" | tr '\n' ' ')"; }
  [ "$(git -C "$APP_DIR" rev-parse HEAD)" = "$NEW_SHA" ] && ok "code is at the new commit ${NEW_SHA:0:9}" || bad "code is at the new commit" "at $(git -C "$APP_DIR" rev-parse --short HEAD), want ${NEW_SHA:0:9}"
  verify_install
  [ "$(sql "SELECT COUNT(*) FROM clients WHERE client_name='Upgrade Survivor Co'")" = 1 ] && ok "seeded client intact" || bad "seeded client intact"
  [ "$(sql "SELECT COUNT(*) FROM tickets WHERE ticket_subject='Survives the upgrade'")" = 1 ] && ok "seeded ticket intact" || bad "seeded ticket intact"
  [ "$(sql "SELECT COUNT(*) FROM webhooks WHERE webhook_name='Upgrade survivor hook'")" = 1 ] && ok "seeded webhook intact" || bad "seeded webhook intact"
  [ "$(sql 'SELECT COUNT(*) FROM users')" = "$users_before" ] && ok "user accounts intact" || bad "user accounts intact"
  [ "$(installed_core_version "$APP_DIR")" != "$old_core" ] && ok "composer deps refreshed (RivetCore $old_core -> $(installed_core_version "$APP_DIR"))" || echo "note: RivetCore unchanged ($old_core) by this upgrade"

  section "update.sh idempotent (second run)"
  local h1 d1; h1="$(git -C "$APP_DIR" rev-parse HEAD)"; d1="$(sql 'SELECT config_current_database_version FROM settings WHERE company_id = 1')"
  /srv/new/deploy/update.sh --app-dir="$APP_DIR" --no-backup-confirmed > "$LOGDIR/update2.log" 2>&1 && ok "second update.sh exits 0" || bad "second update.sh exits 0" "$(tail -3 "$LOGDIR/update2.log" | tr '\n' ' ')"
  [ "$(git -C "$APP_DIR" rev-parse HEAD)" = "$h1" ] && [ "$(sql 'SELECT config_current_database_version FROM settings WHERE company_id = 1')" = "$d1" ] && ok "second update.sh changed nothing" || bad "second update.sh changed nothing"
  check "login works after second update" login_ok
  [ "$(code_of "https://${DOM}/health/ready.php")" = 200 ] && ok "ready after second update" || bad "ready after second update"
}

# ---------------------------------------------------------------------------
section "prepare sources"
prepare_sources || { echo "FAIL: could not prepare sources"; exit 2; }
"scenario_${SCENARIO}"
echo
echo "RESULT ${ED}/${SCENARIO}: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ] || { printf 'FAILED: %s\n' "${FAILED[@]}"; exit 1; }
