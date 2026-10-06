#!/usr/bin/env bash
# End-to-end harness for the LINUX test build of the RivetIT agent.
# Nothing here needs Windows. Two modes:
#
#   1. Against a real RivetIT scratch server (the lead integrates this):
#        RIVETIT_E2E_SERVER_URL=https://scratch.example  RIVETIT_E2E_TOKEN=<enrollment token> \
#        [RIVETIT_E2E_CA=/path/ca.pem] [RIVETIT_E2E_SECONDS=90] ./e2e/run_e2e.sh
#      The agent enrolls, runs for N seconds, and the script checks the AGENT side
#      (status, seq progress, last check-in). Verify the SERVER side (asset linked,
#      metrics rows, alerts) in RivetIT afterwards; the script prints what to look for.
#      Jobs are NOT executed in this mode (Linux script execution stays disabled).
#
#   2. Self-contained (RIVETIT_E2E_SERVER_URL unset): starts e2e/fakeserver (a
#      contract-shaped stand-in), exercises enroll, check-ins, a signed job and
#      verifies the job result. Linux test mode scripts (sh -c) are enabled
#      ONLY in this mode via RIVETIT_AGENT_TESTMODE=1.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
export PATH="${GO_BIN_DIR:-$HOME/.local/go/bin}:$PATH"
WORK="${RIVETIT_E2E_WORK:-$ROOT/e2e/work}"
SECS="${RIVETIT_E2E_SECONDS:-45}"
rm -rf "$WORK"; mkdir -p "$WORK/state"

echo "== building linux test binary"
( cd "$ROOT" && go build -trimpath -o "$WORK/rivetit-agent" . && go build -o "$WORK/fakeserver" ./e2e/fakeserver )

FAKE_PID=""
cleanup() { [ -n "${AGENT_PID:-}" ] && kill "$AGENT_PID" 2>/dev/null || true; [ -n "$FAKE_PID" ] && kill "$FAKE_PID" 2>/dev/null || true; }
trap cleanup EXIT

CA_ARGS=()
if [ -z "${RIVETIT_E2E_SERVER_URL:-}" ]; then
  MODE=fake
  "$WORK/fakeserver" -dir "$WORK/fake" -interval 3 -collect 5 -job-script 'echo e2e-job-ok; exit 0' >"$WORK/fakeserver.out" 2>&1 &
  FAKE_PID=$!
  for _ in $(seq 1 50); do [ -s "$WORK/fake/url" ] && break; sleep 0.1; done
  SERVER="$(cat "$WORK/fake/url")"; TOKEN="E2E-ENROLL-TOKEN"; CA_ARGS=(--ca "$WORK/fake/ca.pem")
  export RIVETIT_AGENT_TESTMODE=1
  SECS="${RIVETIT_E2E_SECONDS:-20}"
else
  MODE=real
  SERVER="$RIVETIT_E2E_SERVER_URL"; TOKEN="${RIVETIT_E2E_TOKEN:?RIVETIT_E2E_TOKEN is required}"
  [ -n "${RIVETIT_E2E_CA:-}" ] && CA_ARGS=(--ca "$RIVETIT_E2E_CA")
fi
echo "== mode=$MODE server=$SERVER"

A="$WORK/rivetit-agent"
echo "== enroll"
printf '%s' "$TOKEN" >"$WORK/token"; chmod 600 "$WORK/token"
"$A" enroll --state-dir "$WORK/state" --server "$SERVER" "${CA_ARGS[@]}" --token-file "$WORK/token"
rm -f "$WORK/token"

echo "== run for ${SECS}s"
"$A" run --state-dir "$WORK/state" --no-update >"$WORK/agent.out" 2>&1 &
AGENT_PID=$!
sleep "$SECS"
kill "$AGENT_PID"; wait "$AGENT_PID" 2>/dev/null || true; AGENT_PID=""

echo "== status"
"$A" status --state-dir "$WORK/state" | tee "$WORK/status.txt"

fail=0
check() { if ! grep -q "$1" "$WORK/status.txt"; then echo "FAIL: status lacks '$1'"; fail=1; else echo "ok: $2"; fi; }
check "device id:   .\+" "device id assigned"
check "last check-in: 20" "agent checked in"
seq=$(sed -n 's/^check-in seq: //p' "$WORK/status.txt")
[ "${seq:-0}" -ge 2 ] && echo "ok: seq advanced to $seq" || { echo "FAIL: seq=$seq"; fail=1; }
if grep -q "REVOKED" "$WORK/agent.out"; then echo "note: agent reported revocation"; fi
perm=$(stat -c %a "$WORK/state/device.token"); [ "$perm" = 600 ] && echo "ok: device.token is 0600" || { echo "FAIL: token perms $perm"; fail=1; }

if [ "$MODE" = fake ]; then
  grep -q 'JOB-REPORT job=e2e-job-1 state=succeeded' "$WORK/fakeserver.out" && grep -q 'e2e-job-ok' "$WORK/fakeserver.out" \
    && echo "ok: signed job verified, executed once and reported" || { echo "FAIL: job result missing"; fail=1; }
  n=$(grep -c 'JOB-REPORT job=e2e-job-1 state=succeeded' "$WORK/fakeserver.out"); [ "$n" = 1 ] && echo "ok: exactly one terminal report" || { echo "FAIL: $n terminal reports"; fail=1; }
  grep CHECKIN "$WORK/fakeserver.out" | head -5
else
  cat <<MSG
== server-side verification (manual, in RivetIT):
   - the endpoint appears (device id above) and is linked/pending/ambiguous as expected
   - metrics/inventory rows arrived and last-seen is current
   - revoke the device in RivetIT, wait one check-in: agent.out must log 'REVOKED' and status must say DORMANT
   agent log: $WORK/agent.out
MSG
fi
[ $fail = 0 ] && echo "E2E PASS" || { echo "E2E FAIL"; exit 1; }
