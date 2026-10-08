#!/usr/bin/env bash
# Installer / updater end-to-end harness. See tests/installer/README.md.
#
#   tests/installer/run.sh [--scenario=fresh|upgrade|all] [--previous-tag=<tag>] [--keep] [--no-build]
#
# Spins up a privileged systemd Ubuntu 24.04 container per scenario, snapshots THIS working tree (committed +
# uncommitted + untracked-not-ignored) into it, and drives deploy/install.sh / deploy/update.sh for real.
# Prints one PASS/FAIL line per check; exits non-zero if any check (or the harness itself) failed.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SCENARIOS="all"; PREV_TAG=""; KEEP=0; BUILD=1

for a in "$@"; do
  case "$a" in
    --scenario=*)     SCENARIOS="${a#*=}" ;;
    --previous-tag=*) PREV_TAG="${a#*=}" ;;
    --keep)           KEEP=1 ;;
    --no-build)       BUILD=0 ;;
    -h|--help)        sed -n '2,9p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown option: $a" >&2; exit 2 ;;
  esac
done

# Edition: RivetIT's composer package is rivetit/rivetit; the MSP edition has no package name.
if grep -q '"name": *"rivetit/rivetit"' "$REPO/composer.json" 2>/dev/null; then ED=it; DEF_PREV=v26.10.24; else ED=msp; DEF_PREV=v26.10.6; fi
PREV_TAG="${PREV_TAG:-$DEF_PREV}"
[ "$SCENARIOS" = all ] && SCENARIOS="fresh upgrade"

DOCKER=docker; docker ps >/dev/null 2>&1 || DOCKER="sudo -n docker"
$DOCKER ps >/dev/null 2>&1 || { echo "FAIL: cannot talk to docker (tried docker and sudo -n docker)"; exit 2; }

NAME="rivet-inst-${ED}"
IMAGE="rivet-inst-base:${ED}"
# Host-side download caches (.deb files, composer dists) so repeat runs do not re-download; nothing else is shared.
CACHE="${RIVET_INST_CACHE:-$HOME/.cache/rivet-installer-harness}"; mkdir -p "$CACHE/apt-$ED" "$CACHE/composer-$ED"
WORK="$(mktemp -d /tmp/rivet-inst-${ED}.XXXXXX)"
GITDIR="$(cd "$REPO" && git rev-parse --path-format=absolute --git-common-dir)"
HEAD_SHA="$(git -C "$REPO" rev-parse HEAD)"
git -C "$REPO" rev-parse -q --verify "refs/tags/${PREV_TAG}" >/dev/null || { echo "FAIL: tag ${PREV_TAG} not found in $REPO"; exit 2; }

cleanup() {
  $DOCKER rm -f "$NAME" >/dev/null 2>&1
  if [ "$KEEP" -eq 0 ]; then $DOCKER rmi -f "$IMAGE" >/dev/null 2>&1; rm -rf "$WORK"; else echo "kept: image $IMAGE, work dir $WORK"; fi
}
trap cleanup EXIT

echo "edition=$ED head=${HEAD_SHA:0:9} previous-tag=$PREV_TAG scenarios=[$SCENARIOS] work=$WORK"
START=$(date +%s)

# Snapshot of the working tree (tracked + untracked-not-ignored, minus anything deleted on disk).
( cd "$REPO" && git ls-files -z -co --exclude-standard --deduplicate | tar --null -T - --ignore-failed-read -cf "$WORK/tree.tar" 2>/dev/null ) 
[ -s "$WORK/tree.tar" ] || { echo "FAIL: could not snapshot the working tree"; exit 2; }
cp "$HERE/scenario.sh" "$WORK/scenario.sh"; mkdir -p "$WORK/logs"

if [ "$BUILD" -eq 1 ] || ! $DOCKER image inspect "$IMAGE" >/dev/null 2>&1; then
  $DOCKER build -q -t "$IMAGE" "$HERE" >/dev/null || { echo "FAIL: docker build"; exit 2; }
fi

OVERALL=0
for S in $SCENARIOS; do
  echo; echo "################ ${ED} / ${S} ################"
  $DOCKER rm -f "$NAME" >/dev/null 2>&1
  $DOCKER run -d --name "$NAME" --privileged --cgroupns=host --tmpfs /run --tmpfs /run/lock \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$CACHE/apt-$ED":/var/cache/apt/archives -v "$CACHE/composer-$ED":/root/.cache/composer -v "$WORK":/work -v "$GITDIR":/src-git:ro "$IMAGE" >/dev/null \
    || { echo "FAIL: could not start container"; OVERALL=1; continue; }
  for _ in $(seq 1 60); do
    st="$($DOCKER exec "$NAME" systemctl is-system-running 2>/dev/null || true)"
    case "$st" in running|degraded) break;; esac; sleep 1
  done
  [ "$st" = running ] || [ "$st" = degraded ] || { echo "FAIL: systemd did not come up in the container (state: ${st:-none})"; OVERALL=1; continue; }
  $DOCKER exec -e ED="$ED" -e HEAD_SHA="$HEAD_SHA" -e PREV_TAG="$PREV_TAG" -e TREE_TAR=/work/tree.tar \
    "$NAME" bash /work/scenario.sh "$S" 2>&1 | tee "$WORK/logs/scenario_${S}.out"
  [ "${PIPESTATUS[0]}" -eq 0 ] || OVERALL=1
  $DOCKER rm -f "$NAME" >/dev/null 2>&1
done

echo; echo "installer harness (${ED}) finished in $(( $(date +%s) - START ))s: $([ $OVERALL -eq 0 ] && echo PASS || echo FAIL)"
[ $OVERALL -eq 0 ] && [ "$KEEP" -eq 0 ] || { [ $OVERALL -eq 0 ] || { mkdir -p /tmp/rivet-inst-last-logs; cp -r "$WORK/logs/." /tmp/rivet-inst-last-logs/ 2>/dev/null; echo "logs saved in /tmp/rivet-inst-last-logs"; }; }
exit $OVERALL
