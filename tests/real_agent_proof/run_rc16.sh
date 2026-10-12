#!/usr/bin/env bash
# REAL-agent proof of RivetCore rc.16 in RivetIT: pushing the MeshCentral agent to a device that is ALREADY enrolled, through RivetIT's own page handler (agent/post/rmm_mesh_push.php).
#
#   the Linux agent (the release build from RivetCore `make dist`) under a REAL systemd in a privileged container, installed WITHOUT any Mesh option (the plain command RivetIT's Add device
#   dialog hands out), TLS via nginx to `php -S` on a SCRATCH database; the Mesh server is a MOCK (tests/real_agent_proof/mock_mesh.py: a TLS server with a self-signed certificate that
#   serves a fake meshagent shell script). The device group id is MeshCentral's 64-character URL form (with '@' and '$').
#   Then: Install through the handler (idempotent states, force), the node id mapped by Core from the next check-in, the result read through the handler, the device page, a bulk call,
#   Remove (explicit confirm), and a check that the RivetIT agent was never restarted or touched.
#
#   . ./.scratch_env
#   RIVETIT_SOCKET=/path/to/mysql.sock AGENT_BIN=<core>/endpoint-agent/dist/rivetit-agent-linux-amd64 tests/real_agent_proof/run_rc16.sh
#
# Prints "ok:"/"FAIL:" lines and a final "PROOF PASS" or "PROOF FAIL". What a mock cannot show: that a real MeshCentral serves this download address and flags, that its agent ids and
# install/uninstall arguments are right, and where a real MeshAgent keeps its node id. Windows is not exercised.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../.." && pwd)"
AGENT_BIN="${AGENT_BIN:?set AGENT_BIN to the release-built Linux agent}"
INSTALL_SH="${INSTALL_SH:-$(dirname "$AGENT_BIN")/install-linux.sh}"
SOCKET="${RIVETIT_SOCKET:?set RIVETIT_SOCKET to the scratch MariaDB socket}"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"; NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-rc16-proof}"
PHPPORT="${PHP_PORT:-18780}"; TLSPORT="${TLS_PORT:-18743}"; MESHPORT="${MESH_PORT:-18744}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d /tmp/rivetit-rc16proof.XXXXXX)"
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
# MeshCentral's URL form of a device group id: 64 characters of base64 with '@' for '+' and '$' for '/'. A RANDOM placeholder (never a real meshid), re-drawn until it has both characters.
GROUP="$(python3 - <<'PY'
import base64, os
while True:
    g = base64.b64encode(os.urandom(48)).decode().replace('+', '@').replace('/', '$')
    if '@' in g and '$' in g:
        print(g); break
PY
)"
NODE_RAW='AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01'
: >"$WORK/mesh.log"
python3 "$HERE/mock_mesh.py" "$GATEWAY" "$MESHPORT" "$WORK/meshtls/cert.pem" "$WORK/meshtls/key.pem" "$GROUP" "$NODE_RAW" "$WORK/mesh.log" >"$WORK/mesh.out" 2>&1 &
MESHPID=$!
export RMM_TEST_STATE_DIR="$WORK/state" RMM_GATE_STATE_DIR= EA_TEST_NON_WINDOWS=1 RIVETIT_WEBHOOK_ALLOW_PRIVATE=1
SETUP="$(cd "$REPO" && php "$HERE/setup_rc16.php" "https://$GATEWAY:$TLSPORT" "https://$GATEWAY:$MESHPORT" "$GROUP" "$PIN")" || { echo "setup failed: $SETUP"; exit 2; }
ADMIN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["admin_api_token"];')"
export RIVETIT_SOCKET="$SOCKET"
dbq() { php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(null, getenv("RIVETIT_TEST_DB_USER"), getenv("RIVETIT_TEST_DB_PASS"), getenv("RIVETIT_TEST_DB_NAME"), null, getenv("RIVETIT_SOCKET")); $r = $m->query($argv[1]); if ($r instanceof mysqli_result) { while ($row = $r->fetch_row()) { echo implode("\t", $row), "\n"; } }' "$1"; }
SID="rc16proof$(openssl rand -hex 6)"
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
jget() { php -r '$d=json_decode(stream_get_contents(STDIN), true); $v=$d; foreach (explode(".", $argv[1]) as $k) { if (is_array($v) && array_key_exists($k, $v)) { $v=$v[$k]; } else { $v=null; break; } } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }
page() { # page PATH key=value ...  (a form post of the real page handler, as the forged administrator; JSON answer)
  local p=$1; shift; local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s -H 'Accept: application/json' -b "PHPSESSID=$SID" --data-urlencode csrf_token=csrftok1 "${args[@]}" "http://127.0.0.1:$PHPPORT$p"; }
push() { page /agent/post/rmm_mesh_push.php "$@"; }
jobs_n() { dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = $DEV AND type = '$1'"; }
agent_pid() { docker exec "$NAME" systemctl show -p MainPID --value rivetit-agent; }

echo "== install the agent WITHOUT any Mesh option (the plain command RivetIT's Add device dialog hands out)"
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$INSTALL_SH" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"
RESP="$(page /agent/post/rmm_installer.php action=linux client_id=1 arch=amd64 ttl_hours=24 max_uses=1)"
CMD="$(printf '%s' "$RESP" | jget command)"
chk "RivetIT answered with an install command that has no Mesh flags" '[ "$(printf "%s" "$RESP" | jget success)" = true ] && [ -n "$CMD" ] && ! printf "%s" "$CMD" | grep -q -- "--install-mesh-agent"'
CMD2="$(printf '%s' "$CMD" | sed 's#\./install-linux.sh --server#./install-linux.sh --binary /w/rivetit-agent --ca /w/ca.pem --server#')"
printf '%s\n' "$CMD2" | docker exec -i -w /w "$NAME" bash -s >"$WORK/install.out" 2>&1
chk "install exit 0 (the RivetIT agent installed)" '[ $? = 0 ]'
wait_for 60 "[ \"\$(api GET '' | jget total)\" = 1 ]"; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against RivetIT's bridges ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != null ]'
chk "no Mesh agent was installed by the installer and none is reported" '! docker exec "$NAME" test -e /usr/local/mesh_services/meshagent/meshagent && [ "$(api GET "$DEV" | jget mesh_agent)" = null ] && [ ! -s "$WORK/mesh.log" ]'
chk "Core offers the install for this device: mesh_install available, the rc.16 agent announced the capability" 'wait_for 60 "[ \"\$(api GET \"\$DEV\" | jget mesh_install.agent_supports)\" = true ]" && [ "$(api GET "$DEV" | jget mesh_install.available)" = true ] && [ "$(api GET "$DEV" | jget mesh_install.installed)" = false ]'
ASSET="$(dbq "SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $DEV")"
APAGE="$(curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/asset_details.php?asset_id=$ASSET")"
chk "RivetIT's device page draws the Install button (twice: Remote access card and Remote tab), the dialog and the script, and not the group id" 'test "$(grep -o "data-mesh-push-action=\"install\"" <<<"$APAGE" | wc -l)" = 2 && grep -q "id=\"rmmMeshPushConfirm\"" <<<"$APAGE" && grep -q "rmm_mesh_push.js" <<<"$APAGE" && ! grep -qF -- "$GROUP" <<<"$APAGE"'
PID0="$(agent_pid)"

echo "== push the Mesh agent through RivetIT's handler (the IT UI path)"
R="$(push action=install device_id=$DEV)"
chk "install: queued with a job id" '[ "$(printf "%s" "$R" | jget code)" = queued ] && [ -n "$(printf "%s" "$R" | jget job_id)" ]'
JOB="$(printf '%s' "$R" | jget job_id)"
R2="$(push action=install device_id=$DEV)"
chk "a second click while it is open: already_queued, the same job, nothing new" '[ "$(printf "%s" "$R2" | jget code)" = already_queued ] && [ "$(printf "%s" "$R2" | jget job_id)" = "$JOB" ] && [ "$(jobs_n mesh_install)" = 1 ]'
chk "the job row does not hold the device group id" '! dbq "SELECT params_json FROM endpoint_agent_jobs WHERE job_id = \"$JOB\"" | grep -qF -- "$GROUP"'
chk "the REAL agent verified the signed job, downloaded from the (mock) Mesh server over the pinned TLS and the job succeeded" 'wait_for 120 "[ \"\$(push action=job_result device_id=\$DEV job_id=\$JOB | jget state)\" = succeeded ]"'
chk "the mock Mesh server saw GET /meshagents?id=6&meshid=<the URL form, '@' and '\$' literal>&installflags=0" 'grep -qF -- "MESH-DOWNLOAD path=/meshagents id=6 meshid=$GROUP installflags=0" "$WORK/mesh.log"'
chk "the fake meshagent was installed (marker records -install)" 'docker exec "$NAME" sh -c "test -x /usr/local/mesh_services/meshagent/meshagent && grep -q -- -install /usr/local/mesh_services/meshagent/installed.marker"'
RES="$(push action=job_result device_id=$DEV job_id=$JOB)"
chk "the result fragment says Done and The Mesh agent was installed, and never prints the group id" 'grep -q "Done" <<<"$(printf "%s" "$RES" | jget html)" && grep -q "The Mesh agent was installed" <<<"$(printf "%s" "$RES" | jget html)" && ! grep -qF -- "$GROUP" <<<"$RES"'
chk "the group id is not in the agent's state directory" '! docker exec "$NAME" grep -rqaF -- "$GROUP" /var/lib/rivetit-agent'
chk "the job row holds no group id after the result either" '! dbq "SELECT CONCAT(IFNULL(params_json,\"\"), IFNULL(output,\"\")) FROM endpoint_agent_jobs WHERE job_id = \"$JOB\"" | grep -qF -- "$GROUP"'
chk "the node id was mapped by Core (the existing check-in rules): mesh_agent installed, map_state mapped, node//<the fake agent's id>" 'wait_for 90 "[ \"\$(api GET \"\$DEV\" | jget mesh_agent.map_state)\" = mapped ]" && [ "$(api GET "$DEV" | jget mesh_agent.installed)" = true ] && [ "$(api GET "$DEV" | jget mesh_agent.node_id)" = "node//$NODE_RAW" ]'
chk "the node is in the device's mapping with source agent" '[ "$(dbq "SELECT CONCAT(mesh_node_id, \"|\", source) FROM endpoint_agent_mesh_nodes WHERE device_id = $DEV")" = "node//$NODE_RAW|agent" ]'
chk "audit: Remote Tool Queued, Mesh Agent Installed and Mesh Node Mapped" '[ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action IN (\"Remote Tool Queued\",\"Mesh Agent Installed\",\"Mesh Node Mapped\")")" -ge 3 ]'
chk "the RivetIT agent was never restarted by the push (same MainPID, still active)" '[ "$(agent_pid)" = "$PID0" ] && docker exec "$NAME" systemctl is-active --quiet rivetit-agent'

echo "== idempotence, force, the device page and the job history"
N0="$(jobs_n mesh_install)"
R="$(push action=install device_id=$DEV)"
chk "install on an installed device: already_installed with the node id, nothing queued" '[ "$(printf "%s" "$R" | jget code)" = already_installed ] && [ "$(printf "%s" "$R" | jget node_id)" = "node//$NODE_RAW" ] && [ "$(jobs_n mesh_install)" = "$N0" ]'
R="$(push action=install device_id=$DEV force=1)"
chk "force=1 queues it again" '[ "$(printf "%s" "$R" | jget code)" = queued ]'
JOB2="$(printf '%s' "$R" | jget job_id)"
chk "the forced job ran on the real agent (succeeded) and the mock saw a second download" 'wait_for 120 "[ \"\$(push action=job_result device_id=\$DEV job_id=\$JOB2 | jget state)\" = succeeded ]" && [ "$(grep -c "MESH-DOWNLOAD" "$WORK/mesh.log")" = 2 ]'
APAGE="$(curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/asset_details.php?asset_id=$ASSET")"
chk "the device page now offers Remove too, marks the device installed, shows the node and Mapped automatically, and lists both jobs with a View result button" 'grep -q "data-mesh-push-action=\"uninstall\"" <<<"$APAGE" && grep -q "data-installed=\"1\"" <<<"$APAGE" && grep -q "Mapped automatically" <<<"$APAGE" && test "$(grep -o "data-mesh-push-result=\"" <<<"$APAGE" | wc -l)" -ge 2'
BR="$(push action=bulk target_type=client target_id=1)"
chk "bulk, first call: 422 confirmation_required with Core's device_count 1, nothing queued" '[ "$(printf "%s" "$BR" | jget code)" = confirmation_required ] && [ "$(printf "%s" "$BR" | jget device_count)" = 1 ] && [ "$(jobs_n mesh_install)" = 2 ]'
BR="$(push action=bulk target_type=client target_id=1 confirm=1)"
chk "bulk, confirmed: the device already has the agent, so skipped_installed is 1 and nothing is queued" '[ "$(printf "%s" "$BR" | jget skipped_installed)" = 1 ] && [ "$(printf "%s" "$BR" | jget created)" = 0 ] && [ "$(jobs_n mesh_install)" = 2 ]'

echo "== remove (explicit confirm), the RivetIT agent untouched"
R="$(push action=uninstall device_id=$DEV)"
chk "remove without the confirm box: confirmation_required, nothing queued" '[ "$(printf "%s" "$R" | jget code)" = confirmation_required ] && [ "$(jobs_n mesh_uninstall)" = 0 ]'
R="$(push action=uninstall device_id=$DEV confirm=1)"
chk "remove with the confirm box: queued" '[ "$(printf "%s" "$R" | jget code)" = queued ]'
JOBU="$(printf '%s' "$R" | jget job_id)"
chk "the real agent ran the Mesh agent's own uninstaller (job succeeded, the fake meshagent is gone)" 'wait_for 120 "[ \"\$(push action=job_result device_id=\$DEV job_id=\$JOBU | jget state)\" = succeeded ]" && ! docker exec "$NAME" test -e /usr/local/mesh_services/meshagent/meshagent'
chk "the result says The Mesh agent was removed" 'grep -q "The Mesh agent was removed" <<<"$(push action=job_result device_id=$DEV job_id=$JOBU | jget html)"'
chk "the next check-in tells the server it is gone (mesh_agent.installed false) and the page drops the Remove button" 'wait_for 90 "[ \"\$(api GET \"\$DEV\" | jget mesh_agent.installed)\" = false ]" && ! curl -s -b "PHPSESSID=$SID" "http://127.0.0.1:$PHPPORT/agent/asset_details.php?asset_id=$ASSET" | grep -q "data-mesh-push-action=\"uninstall\""'
chk "the RivetIT agent was never restarted or touched by the removal (same MainPID, still checking in)" '[ "$(agent_pid)" = "$PID0" ] && docker exec "$NAME" systemctl is-active --quiet rivetit-agent'
BR="$(push action=bulk target_type=client target_id=1 confirm=1)"
chk "bulk after the removal queues one install" '[ "$(printf "%s" "$BR" | jget created)" = 1 ]'
chk "...and the real agent installs it again" 'wait_for 120 "docker exec \"$NAME\" test -x /usr/local/mesh_services/meshagent/meshagent"'
J="$(api GET "$DEV/jobs")"
chk "none of the Mesh jobs' stored output or parameters contains the device group id" '! dbq "SELECT CONCAT(IFNULL(params_json,\"\"), IFNULL(output,\"\")) FROM endpoint_agent_jobs WHERE type LIKE \"mesh\\_%\"" | grep -qF -- "$GROUP"'

echo "== uninstall"
docker exec "$NAME" bash /w/install-linux.sh --uninstall --purge --remove-meshagent >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
chk "--remove-meshagent removed the Mesh agent too" '! docker exec "$NAME" test -e /usr/local/mesh_services/meshagent/meshagent'
[ $fail = 0 ] && echo "PROOF PASS" || { echo "PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; tail -20 "$WORK/install.out"; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 30 2>/dev/null | tail -30; exit 1; }
