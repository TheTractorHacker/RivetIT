#!/bin/sh
# Builds the Odoo 19 and Odoo 20 zips of rivetit_sso from the one source tree.
# The only per-version difference is the access-rights file: Odoo 19 reads
# security/ir.model.access.csv, Odoo 20 renamed the model and reads security/ir.access.csv.
# The manifest version is series-agnostic ('1.0.0'); Odoo prefixes its own series.
set -e
cd "$(dirname "$0")"
build() {
    series=$1; keep=$2; drop=$3
    tmp=$(mktemp -d)
    cp -r rivetit_sso "$tmp/rivetit_sso"
    find "$tmp" -name __pycache__ -type d -prune -exec rm -rf {} +
    rm -f "$tmp/rivetit_sso/security/$drop"
    sed -i "s#security/ir\\.[a-z.]*csv#security/$keep#" "$tmp/rivetit_sso/__manifest__.py"
    out="rivetit_sso-$series.0.1.0.0.zip"
    rm -f "$out"
    (cd "$tmp" && zip -qrX - rivetit_sso) > "$out"
    rm -rf "$tmp"
    echo "built $out"
}
build 19 ir.model.access.csv ir.access.csv
build 20 ir.access.csv ir.model.access.csv
