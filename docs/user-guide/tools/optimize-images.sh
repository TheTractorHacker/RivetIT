#!/usr/bin/env bash
# Shrink the guide's screenshots (typically 30 MB -> 12 MB) without visible change: pngquant to an
# adaptive palette (quality 85-98, skipped for any image it cannot do well), then optipng.
#
#   docs/user-guide/tools/optimize-images.sh [dir]     # default: docs/user-guide/images
#
# Run it after re-shooting and before committing. Needs: pngquant, optipng (apt install pngquant optipng).
set -euo pipefail
DIR="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../images" && pwd)}"
for c in pngquant optipng; do command -v "$c" >/dev/null || { echo "optimize-images: $c is required" >&2; exit 1; }; done
before=$(du -sk "$DIR" | cut -f1)
find "$DIR" -name '*.png' -print0 | xargs -0 -P4 -n8 pngquant --quality=85-98 --speed 1 --skip-if-larger --strip --force --ext .png || true
find "$DIR" -name '*.png' -print0 | xargs -0 -P4 -n8 optipng -quiet -o2
after=$(du -sk "$DIR" | cut -f1)
echo "optimize-images: ${before} KB -> ${after} KB"
