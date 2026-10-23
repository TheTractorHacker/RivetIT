#!/usr/bin/env bash
# Runs every asserting script (each exits non-zero on failure) against the scratch demo on :8191. usage: run_asserts.sh <logdir>
cd "$(dirname "$0")"; L=${1:-/tmp/qa_asserts}; mkdir -p "$L"; rc=0
for s in verify_design_fixes verify_input_fixes xss_store_test kb_portal_csp_check verify_polish_fixes whitespace_required_check keyboard_tests orgchart_check ts_dropdown_check checkbox_align_check projects_ui_check mdm_ui_check ticket_ui_check; do
  timeout 1500 python3 $s.py > "$L/$s.log" 2>&1; c=$?; echo "$s exit $c ($(grep -c '^PASS' "$L/$s.log") pass)"; [ $c -ne 0 ] && rc=1
done
exit $rc
