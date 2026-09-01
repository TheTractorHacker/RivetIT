#!/usr/bin/env bash
#
# check_openapi_drift.sh — fail (exit 1) when the API v1 route surface has drifted
# away from api/v1/openapi.yaml, so CI catches routes added/removed without a
# corresponding spec update.
#
# It compares three sources:
#   1. openapi.yaml          — the documented paths (the contract).
#   2. api/v1/index.php       — the router's resource dispatch (switch cases +
#                               the pre-switch `$resource === '...'` public routes).
#   3. ApiService.kt          — the Android client's Retrofit endpoints (the
#                               de-facto client contract). This file lives OUTSIDE
#                               this repo; set APISERVICE_KT to point at it, or the
#                               Kotlin check is skipped (with a notice).
#
# Checks (each can independently fail the run):
#   A. Every Retrofit endpoint path is documented in openapi.yaml.
#   B. Every router resource is documented by at least one openapi path
#      (minus a small allowlist of legacy underscore aliases / sub-only routes).
#   C. Every openapi top-level resource maps to a real router resource
#      (catches spec typos or paths for routes that were removed).
#
# Paths are resolved relative to this script, so it runs from anywhere / in CI.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
OPENAPI="$REPO_ROOT/api/v1/openapi.yaml"
INDEX="$REPO_ROOT/api/v1/index.php"
KT="${APISERVICE_KT:-/home/sysadmin/itflow_android/app/src/main/java/com/foleyit/itflow/data/api/ApiService.kt}"

# Router resources that intentionally have no dedicated openapi path:
#   ticket_categories / ticket_views  — underscore aliases of the documented
#                                        hyphenated resources.
#   worksheet-responses               — served only via /worksheets/{id}/responses.
IGNORE_ROUTER_RES="ticket_categories ticket_views worksheet-responses"

fail=0
note()  { printf '  %s\n' "$*"; }
group() { printf '\n== %s ==\n' "$*"; }

if [[ ! -f "$OPENAPI" ]]; then echo "ERROR: spec not found: $OPENAPI" >&2; exit 2; fi
if [[ ! -f "$INDEX"   ]]; then echo "ERROR: router not found: $INDEX" >&2; exit 2; fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# 1. openapi path keys (2-space-indented "/....:") and their top-level resource.
grep -oE '^  /[^:]+:' "$OPENAPI" | sed -E 's/^  (.+):$/\1/' | sort -u > "$tmp/openapi_paths"
sed -E 's#^/([^/]+).*#\1#' "$tmp/openapi_paths" | sort -u > "$tmp/openapi_res"

# 2. router resources: switch `case '...':` + pre-switch `$resource === '...'`.
{
  grep -oE "case '[^']+':"        "$INDEX" | sed -E "s/case '([^']+)':/\1/"
  grep -oE "resource === '[^']+'" "$INDEX" | sed -E "s/.*'([^']+)'/\1/"
} | sort -u > "$tmp/router_res_all"
# Filtered set used for check B (drop known aliases / sub-only routes).
cp "$tmp/router_res_all" "$tmp/router_res_filtered"
for r in $IGNORE_ROUTER_RES; do
  grep -vxF "$r" "$tmp/router_res_filtered" > "$tmp/.f" 2>/dev/null || true
  mv "$tmp/.f" "$tmp/router_res_filtered"
done

echo "openapi paths : $(wc -l < "$tmp/openapi_paths" | tr -d ' ')"
echo "openapi res   : $(tr '\n' ' ' < "$tmp/openapi_res")"
echo "router res    : $(tr '\n' ' ' < "$tmp/router_res_all")"

# ── Check A: Retrofit endpoints ⊆ openapi paths ──────────────────────────────
if [[ -f "$KT" ]]; then
  grep -oE '@(GET|POST|PUT|DELETE|PATCH)\("[^"]+"\)' "$KT" \
    | sed -E 's/.*\("([^"]+)"\)/\1/' \
    | sed -E 's#^([^/])#/\1#' \
    | sort -u > "$tmp/kt_paths"
  echo "kotlin paths  : $(wc -l < "$tmp/kt_paths" | tr -d ' ')  (from $KT)"

  missing_kt="$(comm -23 "$tmp/kt_paths" "$tmp/openapi_paths")"
  if [[ -n "$missing_kt" ]]; then
    group "A. Retrofit paths MISSING from openapi.yaml"
    while IFS= read -r p; do note "$p"; done <<< "$missing_kt"
    fail=1
  fi
else
  echo "kotlin paths  : SKIPPED (ApiService.kt not found; set APISERVICE_KT to enable)"
fi

# ── Check B: router resources ⊆ openapi resources ────────────────────────────
missing_res="$(comm -23 "$tmp/router_res_filtered" "$tmp/openapi_res")"
if [[ -n "$missing_res" ]]; then
  group "B. Router resources with NO openapi path"
  while IFS= read -r r; do note "$r"; done <<< "$missing_res"
  fail=1
fi

# ── Check C: openapi resources ⊆ router resources ────────────────────────────
extra_res="$(comm -23 "$tmp/openapi_res" "$tmp/router_res_all")"
if [[ -n "$extra_res" ]]; then
  group "C. openapi resources not backed by any router resource"
  while IFS= read -r r; do note "$r"; done <<< "$extra_res"
  fail=1
fi

echo
if [[ "$fail" -eq 0 ]]; then
  echo "OK: openapi.yaml is in sync with the router and Retrofit client."
else
  echo "DRIFT DETECTED: update api/v1/openapi.yaml (see groups above)." >&2
fi
exit "$fail"
