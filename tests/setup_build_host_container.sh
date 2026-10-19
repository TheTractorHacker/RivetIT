#!/usr/bin/env bash
# Container integration test for deploy/setup-build-host.sh (RivetIT 26.10.58): ubuntu:24.04 with a fake systemctl/docker/php, the REAL pinned build-host.sh --register against a mock server.
# Needs docker, network (apt inside the container) and a RivetCore clone that has the pinned commit (RIVET_CORE_REPO, default /home/sysadmin/rivet-core). No root needed on the host beyond docker access.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "${HERE}/.." && pwd)"
CORE="${RIVET_CORE_REPO:-/home/sysadmin/rivet-core}"
PIN="$(sed -n 's/^CORE_COMMIT="\([0-9a-f]\{40\}\)"$/\1/p' "${REPO}/deploy/setup-build-host.sh")"
[ -n "${PIN}" ] || { echo "no pin found"; exit 2; }
W="$(mktemp -d)"; trap 'rm -rf "${W}"' EXIT
mkdir -p "${W}/src"
for f in build-host.sh build-host.service build-host.timer; do git -C "${CORE}" show "${PIN}:endpoint-agent/scripts/${f}" > "${W}/src/${f}"; done
docker run --rm -v "${REPO}/deploy:/repo/deploy:ro" -v "${W}/src:/src:ro" -v "${HERE}/deploy_container/inner.sh:/inner.sh:ro" ubuntu:24.04 bash /inner.sh
