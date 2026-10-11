#!/usr/bin/env bash
# REAL-agent proof of RivetCore rc.12 / rc.13 in RivetIT: the optional MeshCentral agent install with automatic node-id mapping, and the starter pack, through RivetIT's own
# pages and bridges.
#
#   the Linux agent (the release build from RivetCore `make dist`) under a REAL systemd in a privileged container, installed with the command RivetIT's Add device dialog hands out
#   when "Also install the MeshCentral agent" is ticked (agent/post/rmm_installer.php, a forged administrator session), TLS via nginx to `php -S` on a SCRATCH database;
#   the Mesh server is a MOCK (tests/real_agent_proof/mock_mesh.py: a TLS server with a self-signed certificate that serves a fake meshagent shell script).
#   Then the starter pack is installed through agent/post/rmm_starter.php and one read-only starter script is run on the device through agent/post/rmm_automation_scr.php.
#
#   . ./.scratch_env
#   RIVETIT_SOCKET=/path/to/mysql.sock AGENT_BIN=<core>/endpoint-agent/dist/rivetit-agent-linux-amd64 tests/real_agent_proof/run_rc13.sh
#
# Prints "ok:"/"FAIL:" lines and a final "PROOF PASS" or "PROOF FAIL". What a mock cannot show: that a real MeshCentral serves this download address and flags, that its agent ids and
# install arguments are right, and where a real MeshAgent keeps its node id. Windows is not exercised.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../.." && pwd)"
AGENT_BIN="${AGENT_BIN:?set AGENT_BIN to the release-built Linux agent}"
INSTALL_SH="${INSTALL_SH:-$(dirname "$AGENT_BIN")/install-linux.sh}"
SOCKET="${RIVETIT_SOCKET:?set RIVETIT_SOCKET to the scratch MariaDB socket}"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"; NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-rc13-proof}"
PHPPORT="${PHP_PORT:-18680}"; TLSPORT="${TLS_PORT:-18643}"; MESHPORT="${MESH_PORT:-18644}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d /tmp/rivetit-rc13proof.XXXXXX)"
case "${RIVETIT_TEST_DB_NAME:-}" in *scratch*) ;; *) echo "Refusing: RIVETIT_TEST_DB_NAME must name a scratch database"; exit 2;; esac
[ "${RIVETIT_TEST_DB:-}" = 1 ] || { echo "Refusing: set RIVETIT_TEST_DB=1 (source .scratch_env)"; exit 2; }
[ -x "$AGENT_BIN" ] && [ -f "$INSTALL_SH" ] || { echo "AGENT_BIN / INSTALL_SH not found"; exit 2; }
fail=0
ok()  { echo "ok: $*"; }
bad() { echo "FAIL: $*"; fail=1; }
chk() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*2)) ] && return 1; sleep 0.5; done; return 0; }
PHPPID=""; MESHPID=""
cleanup() { docker rm -f "$NAME" "$NAME-nginx" >/dev/null 2>&1 || true; [ -n "$PHPPID" ] && kill "$PHPPID" 2>/dev/null || true; [ -n "$MESHPID" ] && kill "$MESHPID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
docker rm -f "$NAME" "$NAME-nginx" >/dev/null 2>&1 || true

echo "== scratch RivetIT install, mock Mesh server, php -S behind TLS"
mkdir -p "$WORK/state" "$WORK/tls" "$WORK/sess" "$WORK/meshtls"; chmod 700 "$WORK/state" "$WORK/sess"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-proof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=mock-mesh" -addext "subjectAltName=IP:$GATEWAY" -keyout "$WORK/meshtls/key.pem" -out "$WORK/meshtls/cert.pem" >/dev/null 2>&1
PIN="$(openssl x509 -in "$WORK/meshtls/cert.pem" -noout -fingerprint -sha384 | cut -d= -f2 | tr -d ':' | tr 'A-F' 'a-f')"
GROUP="$(printf '4a1b2c3d4e5f6071%.0s' 1 2 3 4 5 6)"   # 96 hex
NODE_RAW='AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01'
: >"$WORK/mesh.log"
python3 "$HERE/mock_mesh.py" "$GATEWAY" "$MESHPORT" "$WORK/meshtls/cert.pem" "$WORK/meshtls/key.pem" "$GROUP" "$NODE_RAW" "$WORK/mesh.log" >"$WORK/mesh.out" 2>&1 &
MESHPID=$!
export RMM_TEST_STATE_DIR="$WORK/state" RMM_GATE_STATE_DIR= EA_TEST_NON_WINDOWS=1 RIVETIT_WEBHOOK_ALLOW_PRIVATE=1
SETUP="$(cd "$REPO" && php "$HERE/setup_rc13.php" "https://$GATEWAY:$TLSPORT" "https://$GATEWAY:$MESHPORT" "$GROUP" "$PIN")" || { echo "setup failed: $SETUP"; exit 2; }
ADMIN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["admin_api_token"];')"
export RIVETIT_SOCKET="$SOCKET"
dbq() { php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(null, getenv("RIVETIT_TEST_DB_USER"), getenv("RIVETIT_TEST_DB_PASS"), getenv("RIVETIT_TEST_DB_NAME"), null, getenv("RIVETIT_SOCKET")); $r = $m->query($argv[1]); if ($r instanceof mysqli_result) { while ($row = $r->fetch_row()) { echo implode("\t", $row), "\n"; } }' "$1"; }
SID="rc13proof$(openssl rand -hex 6)"
printf 'logged|b:1;user_id|i:1;csrf_token|s:8:"csrftok1";' >"$WORK/sess/sess_$SID"
( cd "$REPO" && exec env PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d log_errors=1 -d "error_log=$WORK/php.log" -d "session.save_path=$WORK/sess" -S "127.0.0.1:$PHPPORT" tests/mobile_api_router.php >/dev/null 2>&1 ) &
PHPPID=$!
wait_for 15 "curl -s -o /dev/null http://127.0.0.1:$PHPPORT/api/v1/nothing" || { bad "php -S did not start"; exit 1; }
cat >"$WORK/nginx.conf" <<NG
events {}
http { server { listen $TLSPORT ssl; ssl_certificate /tls/ca.pem; ssl_certificate_key /tls/key.pem; client_max_body_size 4m; proxy_request_buffering off; proxy_buffering off;
  location / { proxy_pass http://127.0.0.1:$PHPPORT; proxy_set_header X-Forwarded-Proto https; proxy_set_header Host \$host; proxy_http_version 1.1; } } }
NG
docker run -d --name "$NAME-nginx" --network host -v "$WORK/tls":/tls:ro -v "$WORK/nginx.conf":/etc/nginx/nginx.conf:ro "$NGINX_IMG" >/dev/null
wait_for 15 "curl -s --cacert $WORK/tls/ca.pem -o /dev/null https://$GATEWAY:$TLSPORT/api/v1/nothing" || { bad "TLS proxy did not start"; exit 1; }
api() { local m=$1 p=$2 b="${3:-}" out
  if [ -n "$b" ]; then out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' --data "$b" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"
  else out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"; fi
  printf '%s' "${out##*$'\n'}" >"$WORK/code"; [ "${out##*$'\n'}" -ge 400 ] 2>/dev/null && echo "$m $p -> ${out##*$'\n'} ${out:0:200}" >>"$WORK/api-errors.log"; printf '%s' "${out%$'\n'*}"; }
A() { R="$(api "$@")"; CODE="$(cat "$WORK/code")"; }
jget() { php -r '$d=json_decode(stream_get_contents(STDIN), true); $v=$d; foreach (explode(".", $argv[1]) as $k) { if (is_array($v) && array_key_exists($k, $v)) { $v=$v[$k]; } else { $v=null; break; } } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }
page() { # page PATH key=value ...  (a form post of the real page handler, as the forged administrator; JSON answer)
  local p=$1; shift; local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -H 'Accept: application/json' -b "PHPSESSID=$SID" --data-urlencode csrf_token=csrftok1 "${args[@]}" "http://127.0.0.1:$PHPPORT$p"; }

echo "== the Add device dialog with the MeshCentral box ticked (RivetIT's own endpoint, Core decides)"
chk "the Mesh install is ready (Core: no refusal)" 'dbq "SELECT 1" >/dev/null'
OFFERED="$(curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/rmm_fleet.php?installer=1")"
chk "the dialog offers the tick-box, unticked, ready" 'grep -q "name=\"install_mesh_agent\"" <<<"$OFFERED" && grep -q "data-ready=\"1\"" <<<"$OFFERED" && ! grep -Eq "name=\"install_mesh_agent\"[^>]*checked" <<<"$OFFERED"'
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$INSTALL_SH" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"
RESP="$(page /agent/post/rmm_installer.php action=linux client_id=1 arch=amd64 install_mesh_agent=1 ttl_hours=24 max_uses=1)"
CMD="$(printf '%s' "$RESP" | jget command)"
chk "RivetIT answered with an install command" '[ "$(printf "%s" "$RESP" | jget success)" = true ] && [ -n "$CMD" ]'
chk "the command carries --install-mesh-agent --mesh-url --mesh-group-id --mesh-cert-pin for the mock Mesh server" 'printf "%s" "$CMD" | grep -q -- "--install-mesh-agent --mesh-url .https://$GATEWAY:$MESHPORT. --mesh-group-id .$GROUP. --mesh-cert-pin .$PIN."'
PLAIN="$(page /agent/post/rmm_installer.php action=linux client_id=1 arch=amd64 ttl_hours=24 max_uses=1 | jget command)"
chk "without the tick the command has no Mesh flags" '[ -n "$PLAIN" ] && ! printf "%s" "$PLAIN" | grep -q -- "--install-mesh-agent"'
CMD2="$(printf '%s' "$CMD" | sed 's#\./install-linux.sh --server#./install-linux.sh --binary /w/rivetit-agent --ca /w/ca.pem --server#')"
printf '%s\n' "$CMD2" | docker exec -i -w /w "$NAME" bash -s >"$WORK/install.out" 2>&1
chk "install exit 0 (the RivetIT agent installed)" '[ $? = 0 ]'
wait_for 60 "[ \"\$(api GET '' | jget total)\" = 1 ]"; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against RivetIT's bridges ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != null ]'

echo "== the Mesh agent: downloaded from the (mock) Mesh server, pinned, installed, node id mapped automatically"
chk "the mock Mesh server saw GET /meshagents?id=6&meshid=<group>&installflags=0" 'wait_for 30 "grep -q \"MESH-DOWNLOAD path=/meshagents id=6 meshid=$GROUP installflags=0\" $WORK/mesh.log"'
chk "the fake meshagent was installed (marker, executable)" 'docker exec "$NAME" sh -c "test -x /usr/local/mesh_services/meshagent/meshagent && grep -q -- -install /usr/local/mesh_services/meshagent/installed.marker"'
chk "the group id is not in the agent's state directory" '! docker exec "$NAME" grep -rqa "$GROUP" /var/lib/rivetit-agent'
chk "the device reports the Mesh agent: installed, and its node mapped automatically (map_state mapped)" 'wait_for 90 "[ \"\$(api GET \"\$DEV\" | jget mesh_agent.map_state)\" = mapped ]"'
D="$(api GET "$DEV")"
chk "mesh_agent.installed is true and the node id is node//<the fake agent's id>" '[ "$(printf "%s" "$D" | jget mesh_agent.installed)" = true ] && [ "$(printf "%s" "$D" | jget mesh_agent.node_id)" = "node//$NODE_RAW" ]'
chk "the node is in the device's mapping with source agent" '[ "$(dbq "SELECT CONCAT(mesh_node_id, \"|\", source) FROM endpoint_agent_mesh_nodes WHERE device_id = $DEV")" = "node//$NODE_RAW|agent" ]'
PAGE="$(curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/rmm_agent_device.php?device_id=$DEV")"
ASSET="$(dbq "SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $DEV")"
APAGE="$(curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/asset_details.php?asset_id=$ASSET")"
chk "RivetIT's device page shows installed, the node and Mapped automatically" 'grep -q "id=\"rmm-mesh-agent\"" <<<"$PAGE$APAGE" && grep -q "Mapped automatically" <<<"$PAGE$APAGE" && grep -q "node//AbCdEfGh" <<<"$PAGE$APAGE"'
chk "audit: Mesh Agent Installed and Mesh Node Mapped" '[ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action IN (\"Mesh Agent Installed\",\"Mesh Node Mapped\")")" -ge 2 ]'

echo "== the starter pack through RivetIT's page handler, then one read-only starter script on the real agent"
SR="$(page /agent/post/rmm_starter.php action=plan 'ids[]=lin-disk-usage' 'ids[]=lin-system-summary')"
chk "plan answers and writes nothing" '[ "$(printf "%s" "$SR" | jget success)" = true ] && [ "$(dbq "SELECT COUNT(*) FROM rmm_scripts_v2")" = 0 ]'
SR="$(page /agent/post/rmm_starter.php action=install 'ids[]=lin-disk-usage' 'ids[]=lin-system-summary')"
chk "install answers success" '[ "$(printf "%s" "$SR" | jget success)" = true ]'
SID_SCRIPT="$(dbq "SELECT s.script_id FROM rmm_scripts_v2 s JOIN rmm_script_versions v ON v.script_id = s.script_id WHERE v.note LIKE 'starter|rivet-core-starter|lin-disk-usage|%' LIMIT 1")"
chk "the script is in the library with the starter and risk:read_only tags, signed" '[ -n "$SID_SCRIPT" ] && dbq "SELECT tags_json FROM rmm_scripts_v2 WHERE script_id = $SID_SCRIPT" | grep -q "risk:read_only" && [ -n "$(dbq "SELECT signature FROM rmm_script_versions WHERE script_id = $SID_SCRIPT AND version = 1")" ]'
RR="$(page /agent/post/rmm_automation_scr.php action=run_script script_id=$SID_SCRIPT script_version=1 device_id=$DEV)"
chk "running it from the library is accepted" '[ "$(printf "%s" "$RR" | jget success)" = true ]'
chk "the REAL agent verified the signature, ran the script and reported it (job succeeded, output has the df header)" 'wait_for 120 "api GET \"\$DEV/jobs\" | grep -q Filesystem"'
J="$(api GET "$DEV/jobs")"
chk "the job is in state succeeded with exit code 0" 'printf "%s" "$J" | php -r "\$d=json_decode(stream_get_contents(STDIN),true); foreach (\$d[\"data\"] as \$j) { if (strpos((string)(\$j[\"output\"] ?? \"\"), \"Filesystem\") !== false) { exit((\$j[\"state\"] === \"succeeded\" && (int)(\$j[\"exit_code\"] ?? 1) === 0) ? 0 : 1); } } exit(1);"'
echo "== uninstall"
docker exec "$NAME" bash /w/install-linux.sh --uninstall --purge --remove-meshagent >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
chk "--remove-meshagent removed the Mesh agent too" '! docker exec "$NAME" test -e /usr/local/mesh_services/meshagent/meshagent'
[ $fail = 0 ] && echo "PROOF PASS" || { echo "PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; tail -20 "$WORK/install.out"; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 30 2>/dev/null | tail -30; exit 1; }
