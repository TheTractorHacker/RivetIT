#!/usr/bin/env bash
# Runs the generated Linux install command in a throwaway ubuntu container against a php -S instance of this checkout (scratch database), with a stub systemctl.
# Proves: the command downloads install-linux.sh and the agent from THIS server (curl, and a wget-only image), verifies both against the printed SHA-256 values, runs
# install-linux.sh, a tampered download stops it (exit 65), and no token or key is left on a command line or in a temp file.
#   LX_FILES=<dir with install-linux.sh + rivetit-agent-linux-amd64>  LX_PORT=8741  RIVETIT_TEST_DB* (scratch)  bash tests/linux_command_container.sh
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; PORT="${LX_PORT:-8741}"; FILES="${LX_FILES:?set LX_FILES}"; WORK="$(mktemp -d)"; PASS=0; FAIL=0
trap 'rm -rf "$WORK"; docker image rm -f lxhost-test-curl lxhost-test-wget >/dev/null 2>&1 || true' EXIT
ok() { if [ "$1" = 0 ]; then PASS=$((PASS+1)); echo "PASS  $2"; else FAIL=$((FAIL+1)); echo "FAIL  $2"; fi; }
VERSION="${LX_VERSION:-0.9.9}"
php "$ROOT/tests/support/linux_command_issue.php" "$FILES" "$WORK/cmd.sh" "$VERSION" "http://127.0.0.1:$PORT" > "$WORK/issue.json"
SECRET="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["secret"];' "$WORK/issue.json")"
bash -n "$WORK/cmd.sh"; ok $? "the generated command is valid shell"
cat > "$WORK/Dockerfile.curl" <<'D'
FROM ubuntu:24.04
RUN apt-get update -qq && apt-get install -y -qq curl ca-certificates >/dev/null 2>&1
D
cat > "$WORK/Dockerfile.wget" <<'D'
FROM ubuntu:24.04
RUN apt-get update -qq && apt-get install -y -qq wget ca-certificates >/dev/null 2>&1
D
docker build -q -t lxhost-test-curl -f "$WORK/Dockerfile.curl" "$WORK" >/dev/null
docker build -q -t lxhost-test-wget -f "$WORK/Dockerfile.wget" "$WORK" >/dev/null
cat > "$WORK/inner.sh" <<'I'
set -u
mkdir -p /usr/local/bin
printf '#!/bin/sh\necho "systemctl $*" >> /tmp/systemctl.log\nexit 0\n' > /usr/local/bin/systemctl; chmod +x /usr/local/bin/systemctl
( while sleep 0.05; do ps -eo args 2>/dev/null; done > /tmp/ps.log ) &
PSPID=$!
bash /cmd.sh > /tmp/out.log 2>&1; RC=$?
kill $PSPID 2>/dev/null
echo "RC=$RC"
echo "PS_LEAK=$(grep -c "$SECRET" /tmp/ps.log || true)"
echo "KEY_ON_CMDLINE=$(grep -c 'rvdl1' /tmp/ps.log || true)"
echo "TMP_LEFT=$(ls /tmp | grep -c -E '^tmp\.' || true)"
echo "INSTALLED=$([ -x /opt/rivetit-agent/rivetit-agent ] && echo 1 || echo 0)"
echo "SYSTEMCTL=$(wc -l < /tmp/systemctl.log 2>/dev/null || echo 0)"
echo "--- output"; tail -n 8 /tmp/out.log
I
for IMG in curl wget; do
  OUT="$(docker run --rm --network host -e SECRET="$SECRET" -v "$WORK/cmd.sh:/cmd.sh:ro" -v "$WORK/inner.sh:/inner.sh:ro" "lxhost-test-$IMG" bash /inner.sh 2>&1 || true)"
  echo "$OUT" | sed 's/^/    /'
  echo "$OUT" | grep -q '^KEY_ON_CMDLINE=0$'; ok $? "$IMG image: the install key never appears on a process command line"
  echo "$OUT" | grep -q '^PS_LEAK=0$'; ok $? "$IMG image: the enrollment token never appears on a process command line"
  echo "$OUT" | grep -q '^TMP_LEFT=0$'; ok $? "$IMG image: every temporary file is removed"
  echo "$OUT" | grep -q -E 'using |installed|enrolled|Enrolled|install' ; ok $? "$IMG image: install-linux.sh ran (the agent was installed and enrolled or reached the enrollment step)"
done
# the downloaded agent does not match the SHA-256 printed in the command (a tampered download): the command stops with 65 before install-linux.sh runs
AGENT_SHA="$(sha256sum "$FILES/rivetit-agent-linux-amd64" | cut -d' ' -f1)"
sed "s/$AGENT_SHA/$(printf '0%.0s' $(seq 64))/" "$WORK/cmd.sh" > "$WORK/cmd_bad.sh"
OUT="$(docker run --rm --network host -v "$WORK/cmd_bad.sh:/cmd.sh:ro" -v "$WORK/inner.sh:/inner.sh:ro" -e SECRET=x lxhost-test-curl bash /inner.sh 2>&1 || true)"
echo "$OUT" | grep -q '^RC=65$'; ok $? "a download that does not match the printed SHA-256 stops the command with exit 65"
echo "$OUT" | grep -q '^INSTALLED=0$'; ok $? "...and nothing was installed"
echo "$OUT" | grep -q 'does not match the SHA-256 printed in this command'; ok $? "...with a message that says why"
echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
