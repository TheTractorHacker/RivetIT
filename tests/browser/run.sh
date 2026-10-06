#!/usr/bin/env bash
# Runs the browser smoke suite against a THROWAWAY install.
#   BASE_URL=http://127.0.0.1:8620 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD='...' tests/browser/run.sh
# Optional: EDITION=it|msp (auto-detected), SMOKE_OUT=<dir for screenshots/results>, ONLY=<regex of check names>,
#           CHROME_BIN=<path>, HEADED=1, WEBHOOK_PUBLIC_URL=<resolvable https URL>.
# Exit status: 0 all checks passed, 1 at least one failed, 2 usage / prerequisite problem.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if ! command -v node >/dev/null 2>&1; then echo "node >= 22 is required (see tests/browser/README.md)" >&2; exit 2; fi
major="$(node -p 'process.versions.node.split(".")[0]')"
if [ "$major" -lt 22 ]; then echo "node >= 22 is required (found $(node -v))" >&2; exit 2; fi
export SMOKE_OUT="${SMOKE_OUT:-$here/out}"
exec node "$here/suite.mjs" "$@"
