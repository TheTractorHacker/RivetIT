#!/usr/bin/env bash
# deploy/install.sh (RivetIT 26.10.58): the RMM Agent Builder question and flags. Extracts maybe_install_build_host and normalize_yes_no and runs them with a stub helper.
# shellcheck disable=SC2329,SC2034
set -u
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
P=0; F=0
ck() { if [ "$1" = 0 ]; then P=$((P+1)); echo "PASS  $2"; else F=$((F+1)); echo "FAIL  $2"; fi; }
H="$(bash "${REPO}/deploy/install.sh" --help 2>&1)"
echo "$H" | grep -q -- '--with-build-host' && echo "$H" | grep -q -- '--no-build-host' && echo "$H" | grep -q 'RIVETIT_WITH_BUILD_HOST'; ck $? "--help documents the flags and the environment variable"
grep -q -- '--with-build-host)   WITH_BUILD_HOST=1' "${REPO}/deploy/install.sh" && grep -q -- '--no-build-host)     WITH_BUILD_HOST=0' "${REPO}/deploy/install.sh"; ck $? "both flags are parsed"
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
{ sed -n '/^normalize_yes_no() {/,/^}/p' "${REPO}/deploy/install.sh"; sed -n '/^maybe_install_build_host() {/,/^}/p' "${REPO}/deploy/install.sh"; } > "$W/fns.sh"
cat > "$W/setup-build-host.sh" <<'S'
#!/usr/bin/env bash
echo "$*" >> "$LOGF"
exit "${STUB_RC:-0}"
S
chmod +x "$W/setup-build-host.sh"
run() { # NON_INTERACTIVE SKIP_TLS WITH stdin -> prints the helper log
  ( export LOGF="$W/log"; : > "$W/log"; info() { :; }; warn() { echo "WARN $*" >> "$W/log"; }; SCRIPT_DIR="$W"; APP_DIR=/srv/app; DOMAIN=rivet.example.com
    NON_INTERACTIVE="$1"; SKIP_TLS="$2"; WITH_BUILD_HOST="$3"; . "$W/fns.sh"; maybe_install_build_host <<<"$4" ) >/dev/null 2>&1
  cat "$W/log"
}
[ -z "$(run 1 0 '' '')" ]; ck $? "unattended with nothing set: not installed"
[ -z "$(run 0 0 0 'y')" ]; ck $? "--no-build-host never asks and does not install"
run 1 0 1 '' | grep -q -- '--yes --app-dir=/srv/app --site-url=https://rivet.example.com'; ck $? "--with-build-host installs with the site address, unattended"
! run 1 0 yes_please '' | grep -q -- '--yes'; ck $? "a junk RIVETIT_WITH_BUILD_HOST value installs nothing"
run 1 0 1 '' | grep -q . && ! run 1 1 1 '' | grep -q -- '--yes'; ck $? "--skip-tls: not installed, with a warning"
STUB_RC=1 run 1 0 1 '' | grep -q 'WARN The RMM Agent Builder did not finish'; ck $? "a failing helper only warns"
echo; echo "$P passed, $F failed"; [ "$F" -eq 0 ]
