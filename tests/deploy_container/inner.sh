#!/usr/bin/env bash
# Runs INSIDE the ubuntu container (see ../setup_build_host_container.sh). Fakes: systemctl, docker, php. Real: useradd/usermod/runuser, the pinned build-host.sh --register against a mock server.
set -u
PASS=0; FAIL=0
ok() { if [ "$1" = 0 ]; then PASS=$((PASS+1)); echo "PASS  $2"; else FAIL=$((FAIL+1)); echo "FAIL  $2"; fi; }
t() { local d="$1"; shift; if "$@"; then ok 0 "$d"; else ok 1 "$d"; fi; }
SH=/repo/deploy/setup-build-host.sh
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null 2>&1; apt-get install -y -qq --no-install-recommends python3 curl git ca-certificates acl >/dev/null 2>&1
groupadd docker
mkdir -p /state /app/scripts && chmod 777 /state && : > /app/scripts/build_host_setup.php
cat > /usr/local/bin/systemctl <<'F'
#!/bin/sh
echo "systemctl $*" >> /state/systemctl.log
F
cat > /usr/local/bin/docker <<'F'
#!/bin/sh
[ "$1" = info ] && exit 0
exit 0
F
cat > /usr/local/bin/php <<'F'
#!/bin/sh
echo "$*" >> /state/php.args
shift
cmd=$1
newtok() { printf 'rbh_%s_%s' "$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" "$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"; }
case "$cmd" in
  server-url) echo http://127.0.0.1:18080/api/v1/build_host ;;
  status) if [ -f /state/host ]; then echo "exists=1 enabled=$(cat /state/host) host_id=1"; else echo "exists=0 enabled=0 host_id=0"; fi ;;
  create) [ -f /state/host ] && exit 3; echo 1 > /state/host; t=$(newtok); printf %s "$t" > /state/token; echo "TOKEN=$t" ;;
  rotate) [ -f /state/host ] || exit 3; echo 1 > /state/host; t=$(newtok); printf %s "$t" > /state/token; echo "TOKEN=$t" ;;
  disable) echo 0 > /state/host ;;
esac
F
chmod +x /usr/local/bin/*
cat > /state/mock.py <<'F'
import http.server, json
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0); self.rfile.read(n)
        try: tok = open('/state/token').read().strip()
        except Exception: tok = ''
        if self.headers.get('Authorization') == 'Bearer ' + tok and tok and self.path.endswith('/poll'):
            b = b'{"job":null}'; self.send_response(200)
        else:
            b = b'{"code":"invalid_token"}'; self.send_response(401)
        self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def log_message(self, *a): pass
http.server.HTTPServer(('127.0.0.1', 18080), H).serve_forever()
F
python3 /state/mock.py & sleep 1
export RIVETIT_BH_SOURCE_DIR=/src
ARGS="--app-dir=/app --allow-http --yes"

# 1. refuses a non-root user
out=$(runuser -u nobody -- bash $SH $ARGS 2>&1); rc=$?
t "non-root user is refused with a helpful message" [ $rc -ne 0 ] && echo "$out" | grep -q "must run as root"

# 2. --check changes nothing
out=$(bash $SH --app-dir=/app --allow-http --check 2>&1); rc=$?
t "--check exits 0" [ $rc -eq 0 ]
t "--check created no user, no conf, no /opt, no units" bash -c '! getent passwd rivetit-build >/dev/null && [ ! -e /etc/rivetit/build-host.conf ] && [ ! -e /opt/rivetit-build-host ] && [ ! -e /etc/systemd/system/build-host.timer ] && [ ! -f /state/host ]'
t "--check says what it would do" bash -c 'echo "$0" | grep -q "would create the build host"' "$out"

# 3. install (an existing, closed /etc/rivetit must keep its mode; the build user gets search permission through an ACL)
install -d -o root -g root -m 0750 /etc/rivetit
out=$(bash $SH $ARGS 2>&1); rc=$?
t "install exits 0 ($rc)" [ $rc -eq 0 ]
echo "$out" | tail -12
t "user: nologin shell, home /var/lib/rivetit-build-host, in docker" bash -c 'getent passwd rivetit-build | grep -q ":/var/lib/rivetit-build-host:/usr/sbin/nologin$" && id -nG rivetit-build | tr " " "\n" | grep -qx docker'
t "www-data is NOT in docker" bash -c '! id -nG www-data | tr " " "\n" | grep -qx docker'
t "script root-owned 755" bash -c '[ "$(stat -c "%U:%a" /opt/rivetit-build-host/build-host.sh)" = root:755 ] && [ "$(stat -c %U:%a /opt/rivetit-build-host)" = root:755 ]'
t "units installed 644 root" bash -c '[ "$(stat -c %U:%a /etc/systemd/system/build-host.service)" = root:644 ] && [ "$(stat -c %a /etc/systemd/system/build-host.timer)" = 644 ]'
t "conf is mode 600 owned by rivetit-build" bash -c '[ "$(stat -c %U:%a /etc/rivetit/build-host.conf)" = rivetit-build:600 ]'
t "conf holds SERVER_URL, CORE_REPO, ALLOWED_REFS, WORKDIR and a token" bash -c 'c=/etc/rivetit/build-host.conf; grep -q "^SERVER_URL=http://127.0.0.1:18080/api/v1/build_host$" $c && grep -q "^CORE_REPO=https://github.com/TheTractorHacker/rivet-core.git$" $c && grep -q "^ALLOWED_REFS=\"main v\* agent-v\*\"$" $c && grep -q "^WORKDIR=/var/lib/rivetit-build-host$" $c && grep -Eq "^TOKEN=rbh_[0-9a-f]{16}_[0-9a-f]{48}$" $c'
t "an existing /etc/rivetit keeps mode 750" bash -c '[ "$(stat -c %a /etc/rivetit)" = 750 ]'
TOKEN=$(sed -n 's/^TOKEN=//p' /etc/rivetit/build-host.conf)
t "the token is not in the script output" bash -c '! echo "$0" | grep -q "$1"' "$out" "${TOKEN#rbh_*_}"
t "the token is in no argument passed to php" bash -c '! grep -q rbh_ /state/php.args'
t "timer enabled and started" grep -q "systemctl enable --now build-host.timer" /state/systemctl.log
t "daemon-reload ran" grep -q "systemctl daemon-reload" /state/systemctl.log
t "the pinned build-host.sh --register succeeded against the mock server" bash -c 'echo "$0" | grep -q "connection check passed: OK build host"' "$out"
t "a summary is printed" bash -c 'echo "$0" | grep -q "RMM Agent Builder: done"' "$out"

# 4. idempotent re-run keeps the token
out=$(bash $SH $ARGS 2>&1); rc=$?
t "re-run exits 0" [ $rc -eq 0 ]
t "re-run keeps the same token" [ "$(sed -n 's/^TOKEN=//p' /etc/rivetit/build-host.conf)" = "$TOKEN" ]
t "re-run: one user, one create call" bash -c '[ "$(getent passwd | grep -c "^rivetit-build:")" = 1 ] && [ "$(grep -c "^.* create " /state/php.args)" = 1 ]'
t "re-run says it kept the token" bash -c 'echo "$0" | grep -q "kept the existing token"' "$out"

# 5. --rotate-token
out=$(bash $SH $ARGS --rotate-token 2>&1); rc=$?
t "--rotate-token exits 0 and changes the token" bash -c '[ $0 -eq 0 ] && [ "$(sed -n "s/^TOKEN=//p" /etc/rivetit/build-host.conf)" != "$1" ]' $rc "$TOKEN"
t "conf still mode 600 after the rewrite" bash -c '[ "$(stat -c %U:%a /etc/rivetit/build-host.conf)" = rivetit-build:600 ]'

# 6. tampered download is refused and nothing is installed from it
mkdir -p /tampered && cp /src/* /tampered/ && echo "# evil" >> /tampered/build-host.sh
before=$(sha256sum /opt/rivetit-build-host/build-host.sh)
out=$(RIVETIT_BH_SOURCE_DIR=/tampered bash $SH $ARGS 2>&1); rc=$?
t "tampered build-host.sh is refused (checksum mismatch)" bash -c '[ $0 -ne 0 ] && echo "$1" | grep -q "checksum mismatch for build-host.sh"' $rc "$out"
t "the installed script is unchanged" [ "$(sha256sum /opt/rivetit-build-host/build-host.sh)" = "$before" ]
cp /src/* /tampered/ && echo "x" >> /tampered/build-host.timer
out=$(RIVETIT_BH_SOURCE_DIR=/tampered bash $SH $ARGS 2>&1); rc=$?
t "tampered timer unit is refused" bash -c '[ $0 -ne 0 ] && echo "$1" | grep -q "checksum mismatch for build-host.timer"' $rc "$out"

# 7. hostile arguments
for bad in "--name=a;rm -rf /" "--name=\$(id)" "--site-url=https://u:p@evil.test" "--site-url=https://x.test/a b" "--core-repo=http://evil.test/x.git" "--core-repo=https://x.test/a.git;id" "--allowed-refs=main;id" "--web-user=root;id" "--app-dir=relative" "--bogus"; do
  out=$(bash $SH --yes "$bad" 2>&1); rc=$?
  t "refused: $bad" [ $rc -ne 0 ]
done
# 8. http site without --allow-http
out=$(bash $SH --yes --app-dir=/app 2>&1); rc=$?
t "an http:// site is refused without --allow-http" bash -c '[ $0 -ne 0 ] && echo "$1" | grep -q "must be https"' $rc "$out"

# 9. uninstall keeps docker
out=$(bash $SH --uninstall --yes --app-dir=/app 2>&1); rc=$?
t "uninstall exits 0" [ $rc -eq 0 ]
t "uninstall removed user, conf, units and the script dir" bash -c '! getent passwd rivetit-build >/dev/null && [ ! -e /etc/rivetit/build-host.conf ] && [ ! -e /etc/systemd/system/build-host.timer ] && [ ! -e /etc/systemd/system/build-host.service ] && [ ! -e /opt/rivetit-build-host ] && [ ! -e /var/lib/rivetit-build-host ]'
t "uninstall disabled the host row and stopped the timer" bash -c '[ "$(cat /state/host)" = 0 ] && grep -q "disable --now build-host.timer" /state/systemctl.log'
t "uninstall left docker alone" bash -c 'command -v docker >/dev/null && echo "$0" | grep -q "Docker was left installed"' "$out"
out=$(bash $SH --uninstall --yes --app-dir=/app 2>&1); rc=$?
t "uninstall twice is harmless" [ $rc -eq 0 ]

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
