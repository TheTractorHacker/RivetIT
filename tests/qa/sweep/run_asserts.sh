#!/usr/bin/env bash
# Runs every asserting script (each exits non-zero on failure) against the scratch demo (QA_BASE/QA_DB/QA_APP, default :8191). usage: run_asserts.sh <logdir>
cd "$(dirname "$0")"; L=${1:-/tmp/qa_asserts}; mkdir -p "$L"; rc=0
for s in verify_design_fixes verify_input_fixes xss_store_test kb_portal_csp_check verify_polish_fixes whitespace_required_check keyboard_tests orgchart_check ts_dropdown_check checkbox_align_check projects_ui_check mdm_ui_check ticket_ui_check rmm_alerts_check rmm_sqli_check rmm_ui_check ticket_status_inline_check assets_list_check assets_scope_check training_people_archive_check training_overview_check training_paths_check calendar_check list_sort_headers_check; do
  timeout 1500 python3 $s.py > "$L/$s.log" 2>&1; c=$?; echo "$s exit $c ($(grep -c '^PASS' "$L/$s.log") pass)"; [ $c -ne 0 ] && rc=1
done
exit $rc
