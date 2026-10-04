#!/usr/bin/env bash
# Shrink the guide's screenshots (typically 30 MB -> 12 MB) without visible change: pngquant to an
# adaptive palette (quality 85-98, skipped for any image it cannot do well), then optipng.
#
#   docs/user-guide/tools/optimize-images.sh [--lossless] [dir]     # default dir: docs/user-guide/images
#
# --lossless skips pngquant and only recompresses (bit-for-bit identical pixels). Use it for
# docs/user-guide/images-clean, the plain screenshots meant for reuse elsewhere.
# Run it after re-shooting and before committing. Needs: pngquant, optipng (apt install pngquant optipng).
set -euo pipefail
LOSSLESS=0
if [[ "${1:-}" == "--lossless" ]]; then LOSSLESS=1; shift; fi
DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../images" && pwd)}"
need="optipng"; [[ "$LOSSLESS" -eq 0 ]] && need="pngquant optipng"
for c in $need; do command -v "$c" >/dev/null || { echo "optimize-images: $c is required" >&2; exit 1; }; done
before=$(du -sk "$DIR" | cut -f1)
if [[ "$LOSSLESS" -eq 0 ]]; then
    find "$DIR" -name '*.png' -print0 | xargs -0 -P4 -n8 pngquant --quality=85-98 --speed 1 --skip-if-larger --strip --force --ext .png || true
fi
find "$DIR" -name '*.png' -print0 | xargs -0 -P4 -n8 optipng -quiet -o2
after=$(du -sk "$DIR" | cut -f1)
echo "optimize-images: ${before} KB -> ${after} KB"
