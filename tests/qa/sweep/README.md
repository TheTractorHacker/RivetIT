# tests/qa/sweep - RivetIT read-only sweep, design review, input/keyboard tests

All scripts talk to a **scratch demo on 127.0.0.1:8191 only** (never the shared demos, mwa or production). Output goes to `out*/`
(git-ignored); screenshots/contact sheets go to a scratch dir outside the repo. Small result summaries are kept in `results/`.

## Set up and tear down
```
docs/user-guide/tools/build-demo.sh --app-dir=/home/sysadmin/qa-sweep-app --db=qa_sweep_demo --port=8191 --no-server   # completes (seed 85 skips the backup when no passphrase exists)
tests/qa/sweep/env_up.sh                 # adds QA-UI- training users and extra admin/tech accounts, starts php -S on 127.0.0.1:8191
python3 build_targets.py && python3 build_id_targets.py
./sync.sh                                # copy worktree code into the scratch app after an edit
```
Stop: kill the php -S on 8191, `DROP DATABASE qa_sweep_demo`, remove /home/sysadmin/qa-sweep-app.
Extra scratch accounts used by parallel runs (the app allows one session per user): `qa.admin.dark`, `qa.tech.dark`, `qa.admin3..5`, `qa.l2`, `qa.l3` (see `common.py`).

## Regression sweep (assert-less report generators)
`run_all.sh <outdir>` runs sweep.py (admin/tech/L2/L3/portal/portal2), modals.py, the `?id=` pass and boundary.py; `analyze.py` summarises
(QA_OUT=<outdir>). Compare `results/analysis_base.txt` (before fixes) with `analysis_final.txt`.

## Design review
`design_shoot.py <admin|tech|portal> <light|dark> <WxH>` full-page JPEGs + DOM probes (probes.js); `modal_shoot.py` every ajax modal;
`make_sheets.py` / `make_sheets2.py` contact sheets; `probe_agg.py`, `contrast_probe.py` (pixel contrast leads); `shoot_list.py` (env `ITEMS` JSON list of pages -> PNGs, cached logins), `design_review_log.tsv` in
`results/` is the one-line-per-screenshot verdict log.

## Asserting tests (exit non-zero on failure)
| script | what it asserts |
|---|---|
| `verify_design_fixes.py` | computed-style/text facts for each design fix, light+dark, 1920 and 768 |
| `verify_polish_fixes.py` | the final "fix everything left" pass: breadcrumbs, empty states, kebab actions, tablet filter rows, api_docs dark, bulk-modal-with-0, single file picker, KB excerpt, portal date/inset (45 assertions) |
| `kb_portal_csp_check.py` | portal KB article: no inline `style=`, no CSP console violation, formatting kept as classes |
| `verify_input_fixes.py` | out-of-range number does not 500 and shows a readable message |
| `xss_store_test.py` | hostile category/user names typed with the keyboard are stored inert and rendered once-escaped on every ticket view (covers agent/ticket.php and agent/ticket_list.php) |
| `keyboard_tests.py` | modal focus moves in, no focus on the page behind, visible focus ring (incl. TomSelect), Esc closes, focus returns, resize/200% zoom, double-click creates one record, back/forward does not resubmit, arrow keys between tabs |
| `input_matrix.py` + `input_matrix_report.py` | real-keystroke matrix over every safe create/edit form and modal (`forms_enum.py` builds the inventory): 12 input classes, number/email/URL classes; report exits 1 on 5xx/PHP text, executed script, live markup, double escaping, whitespace-only required names, invalid-in-hidden-tab |
| `orgchart_check.py`, `ts_dropdown_check.py`, `checkbox_align_check.py`, `projects_ui_check.py`, `mdm_ui_check.py`, `ticket_ui_check.py` | third-pass user-reported defects (see the report's third pass); each asserts bounding boxes / computed styles / behaviour and exits non-zero on failure |
| `whitespace_required_check.py` | types whitespace into 11 required fields and reads the DB: nothing blank stored, nothing blanked (judges what the matrix toast heuristic cannot) |

Run the matrix in shards with one account each: `QA_WHO=admin_d QA_FAST=1 python3 input_matrix.py 1/4 both` (and admin3, admin4, admin5 for 2/4..4/4), then `python3 input_matrix_report.py`.
