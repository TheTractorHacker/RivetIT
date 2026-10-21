# RivetIT UX/UI QA Report - 2026-10-21 (run 2: all defects fixed, coverage gaps closed)

Scope: web (Phases A-D). Android (Phase E) is a separate session. Worktree `/home/sysadmin/qa-ux-rivetit`, branch `qa-ux-20261021`, own server `127.0.0.1:8190` (stopped at the end). Demo data only; test records are `QA-UI-` (tickets TCK-255/256/257, users 15/16, asset "QA-UI- asset good", a training assignment for Sophie Tran). Nothing pushed or deployed. Scripts are in this folder (`ux_common.py` plus the sweeps named below); screenshots in `screenshots/` (committed) and `out/` (scratch, not committed).

## 1. Method and totals
| Check | Scope | Result |
|---|---|---|
| Visual review (light + real server-side dark) | ~25 pages read by eye at 1920x1080, 1366x768, 2560x1440 spot checks | 2 visual defects fixed (D1, D14) |
| axe-core | 50 agent/admin pages as admin (dark) and as technician (light) + 24 portal pages | landmark/h1: 0 violations on all 74. Violating nodes admin-dark 794 -> 146 -> ~120 after last CSS; light 74 -> 41 |
| Click sweep (Phase B) | 213 pages: 50 agent, 114 admin, 24 detail pages with real ids, 24 portal (459 links, re-run after D17). ~4,900 same-origin links fetched, ~460 modal triggers opened, ~380 dropdowns and 42 tabs clicked; destructive controls (delete/archive/disable/reset/post.php/csrf links) skipped by design | 0 JS errors; remaining "fails" are by design (RMM-off 403 notice pages, fragment endpoints that 404 when their panel is off, a binary PDF the harness could not decode); real ones fixed (D12, D13) |
| Overflow sweep | 189 pages x 768x1024 and 1024x768 | 17 pages overflowed -> 0 |
| Robustness | 51 detail/list pages with no id / id=0 / page=-5 / page=abc / page=huge / bad sort; add-forms with blank, whitespace and 400-char names | 73 HTTP 500s -> 0 |
| Persona tasks | technician, admin, department staff (portal), training learner, training manager, IT manager | section 5 |

## 2. A. Defects (all fixed unless stated)
Before/after screenshots for D1 and D2 are in `screenshots/`; the others are verified by the named scripts (output summarised in the row).

| ID | Sev | Page / path | Viewport+theme | Rule | Steps / evidence | Status | Files |
|---|---|---|---|---|---|---|---|
| D1 | High | any `.table-warning/-danger/-success/-info` row (tickets "Uncategorized" board) | 1920+1366, dark | Colour contrast | Tabler reads `--tblr-table-bg/-color`; the app only overrode `--bs-*`, leaving cream bg + light text. `defect1_*.png` | fixed | css/itflow_design.css |
| D2 | High | New Ticket modal Contact tab (any pill/tab modal) | both | Forms: validation message hidden in tab | Create did nothing. Now switches to the invalid tab and shows the native bubble. `defect2_*.png` | fixed | js/app.js |
| D3 | High | 50/50 agent/admin pages and portal pages had no `<h1>`/`<main>` | both | Accessibility | `<main id="main-content">` + skip link in all three shells; pages with no h1 promote their visible title (role=heading level 1) so there is no duplicate visible heading; hidden h1 only if no title exists; portal profile name is the h1. axe `page-has-heading-one`/`landmark-one-main`: 0 violations on 50 agent/admin (dark+light) and 24 portal pages; no layout change (checked 1920x1080 and 1366x768, light and dark) | fixed | includes/inc_wrapper.php, includes/footer.php, includes/header.php, guest/includes/inc_wrapper.php, client/includes/header.php, client/includes/footer.php, client/profile.php, js/app.js, css/itflow_design.css |
| D4 | High | icon-only buttons/links, bare checkboxes, TomSelect selects, empty `<th>`, progress bars, tooltip icons | both | Accessibility: no accessible name | axe nodes: button-name 176->0, label 157->1, select-name 49->2, empty-table-header 19->0, aria-progressbar-name 18->0, aria-prohibited-attr 33->0; question-bank ARIA tree made valid | fixed (residue in U2-U4) | js/a11y.js (new), agent/js/training_banks.js, includes/footer.php, client/includes/footer.php |
| D5 | High | every list with `?page=-5`, `abc`, huge, or unknown `?sort=` | n/a | HTTP 500 | 68 URLs returned 500; page clamped, unknown sort redirects without the sort | fixed | includes/filter_header.php |
| D6 | Medium | `asset_details.php?asset_id=-1` (no department) | n/a | HTTP 500 | undefined `$client_id` in included modals | fixed | agent/asset_details.php |
| D7 | Medium | `vendor_details.php` (every vendor) | n/a | HTTP 500 | queried `vendor_contacts`, dropped by DB 1.7.3 (page is orphaned; modals are used) | fixed | agent/vendor_details.php |
| D8 | High | every add/edit form | n/a | Forms: whitespace-only name accepted | New Department with "   " created a nameless record (cleaned up) | fixed (shared guard) | includes/post_guard.php, agent/post.php, admin/post.php |
| D9 | High | every add/edit form | n/a | HTTP 500 on oversize | 400-char name -> blank 500; now a readable message, nothing saved | fixed | same |
| D10 | High | Admin > Users add/edit | n/a | Duplicate e-mail accepted | two users shared one address | fixed | admin/post/users.php |
| D11 | Medium | asset add/edit | n/a | invalid IP/MAC stored | `999.1.1.1`, `zz:zz` saved; now rejected with a message; valid values still save (`asset_addr_fuzz.py`) | fixed | agent/post/asset_model.php |
| D12 | Medium | Admin > Modules | n/a | Dead controls | New/Edit opened modals that 404 ("Error loading modal content") and posted to handlers that do not exist | fixed (rows read-only) | admin/modules.php |
| D13 | Medium | admin ticket/document template details without id | n/a | HTTP 500 | | fixed | admin/ticket_template_details.php, admin/document_template_details.php |
| D14 | Medium | Portal Training table | light+dark | Colour carries no meaning | Due soon / Overdue / Current were all the same grey | fixed | css/itflow_portal.css |
| D15 | Medium | Global search with no hits | both | Feedback: empty state | blank card; now "No results for ..." with advice | fixed | agent/global_search.php |
| D16 | Medium | 17 pages (RMM assets/checks/scripts, Intune, credentials, admin users/audit/mail/webhooks...) | 768 and 1024 | Layout: horizontal page scroll | tables widened the page; now scroll inside their card; 0/189 pages overflow | fixed | css/itflow_design.css, js/a11y.js |
| D17 | High | 15 portal pages (contracts, invoices, documents, allowance, contacts...) | n/a | Error recovery | a contact without the needed role was **signed out of the whole portal** (`post.php?logout`); now returned Home with a reason | fixed | client/*.php (15 files) |
| D18 | Low | light/dark helper text, TomSelect placeholder, outline-danger, `.cred-desc` | both | Contrast | below 4.5:1 | fixed for the shared classes | css/itflow_design.css, css/itflow_custom.css |
| D19 | Low | my own first a11y.js badge pass (found by testing) | light | regression | white text on pale translucent portal pills, and hidden header text widening tablet pages | fixed before the run ended | js/a11y.js |

### Unfixed (with reasons)
| ID | What | Why not fixed |
|---|---|---|
| U1 | `heading-order` (moderate) on 19 pages: stat tiles / card titles are h3-h6 under the promoted h1 | page-by-page information-architecture choice (which tiles become h2); axe "moderate", no visible effect |
| U2 | Dark-theme `color-contrast`: ~76 nodes on 19 pages, colour-coded badges/buttons with data-driven or token colours | needs a design decision on the dark token palette; shared classes were fixed, the rest is per component |
| U3 | Calendar: FullCalendar's own `aria-allowed-attr` / `role-img-alt` (10 nodes) | third-party markup under `plugins/` |
| U4 | `link-in-text-block` (5 nodes), 7 `link-name` nodes (ticket rows with no department render an empty link), 1 `label` (disabled input on user_details) | data-dependent, low impact |
| U5 | `/admin/backup_recovery_panel.php`, `sync_watch_panel.php` answer 404 and RMM settings link to pages that answer a designed 403 "RMM module off" | by design (fragments, module switch); see P5 |
| Env | Learner "Start course" returned 403 on my port: the kiosk API only accepts Origin = `$config_base_url` (demo config says `localhost:8080`). I set the worktree's gitignored config.php to `127.0.0.1:8190` | environment, not product |

## 3. B. Improvement proposals (not implemented)
Correction to run 1: P1 (bulk actions on the ticket list) was wrong - the list already has "Bulk Action (n)" and saved Ticket Views. Retracted.

| ID | Persona | Task | Problem observed | Proposal | Benefit | Effort | Pri | Evidence |
|---|---|---|---|---|---|---|---|---|
| P1 | Dept staff | raise a ticket with a screenshot | New-ticket form has no attachment field; files only on the reply form after creation (works: `portal_attach_reply.py`) | add the upload zone to the new-ticket form | complete first message | S | P1 | portal_newticket.png |
| P2 | IT manager | "overdue by technician this week" | reports exist with Export CSV / Print / Schedule, but no SLA-breach-by-technician view | breach column/filter in Technician Performance | answers the manager's literal question | S | P1 | report_service.png |
| P3 | Admin | find a setting / audit log | audit log is 3 clicks deep, Admin has no search | admin settings search | findability | M | P2 | click counts |
| P4 | Training manager | export compliance | Export CSV does not say which filters it applies | label with active scope + confirmation | no wrong exports | S | P2 | training_assignments.png |
| P5 | Admin | RMM module off | Managed devices nav still lists RMM pages; denial button says "Go to Departments" instead of "Administration > Endpoint agent" | hide nav items when off; point the CTA at the setting | removes dead ends | S | P2 | s403_agent_rmm_add_device.png |
| P6 | Learner | open course from a mismatched host | kiosk 403 shows only "That isn't allowed here." | say what to do | recovery | S | P2 | learner_flow.py |
| P7 | Learner | retry after failing | result lists topics to review without links to the lessons | topic -> lesson deep links | faster retry | M | P2 | learner_result.png |
| P8 | Manager | drill-down | dashboard technician bars and stat tiles are not links | link to the filtered ticket list | information scent | S | P2 | dashboard |
| P9 | Dept staff | reply in portal | no success message after Reply | toast | feedback | S | P3 | portal_attach_reply.py |
| P10 | Technician | KB answer in a reply | reply toolbar has canned responses and AI draft but no "insert KB article" | KB picker | task takes 4+ clicks | M | P3 | ticket detail |
| P11 | Dept staff | service catalog | "New laptop or desktop" and "Request a New Laptop" overlap | merge/rename | clarity | S | P3 | catalog |
| P12 | All | global search | cannot filter results by type | type chips | speed | M | P3 | search_printer.png |

## 4. Coverage table
| Area | Covered | Result |
|---|---|---|
| Agent pages (50) x light/dark | screenshot, console, hscroll, axe, click sweep | pass after fixes |
| Admin pages (114 files) | load, links, modals, dropdowns (25-control cap) | pass after D12/D13; forms submitted: users, assets, add-forms fuzz |
| Detail pages with real ids (ticket, department overview, asset, contact, location, domain, software, project, document, credential, vendor, certificate, course, user, filtered lists) | full click sweep | pass |
| Portal (24 pages) | click sweep (459 links) + axe + new ticket, attachment, reply, KB, training | pass after D17 |
| Training: manager assign + export; learner start -> lessons -> exam -> graded result | `training_manager_flow.py`, `learner_flow.py`, `learner_exam.py` | works end to end (learner scored 50% on arbitrary answers: "Not this time", 1 try left) |
| Known open items: blank names, 500s (page, id, size), duplicate e-mail, invalid IP/MAC, unlabeled controls, tablet overflow, no h1/main | all re-tested | fixed (D3-D13, D16); the calendar-font/CSP item was not observed in this run |

## 5. Persona tasks (clicks from login, navigation only)
| Persona | Task | Clicks | Notes |
|---|---|---|---|
| Technician | queue -> unassigned -> open ticket | 5 | |
| Technician | create ticket (details + contact) | 6 | |
| Technician | public reply + internal note | 4 | |
| Technician | filter by priority | 2 | toolbar dropdown |
| Technician | handle three tickets at once | 4 | tick 3 rows + Bulk Action |
| Technician | find asset and see history | 3 | on the asset page, no named history tab |
| Technician | find contact, call/e-mail | 3 | tel/mailto links in the row |
| Admin | create / edit user | 3 / 3 | duplicate, blank name, bad e-mail now blocked |
| Admin | disable user | 4 | ellipsis, Disable, confirm |
| Admin | audit log lookup + search | 3 + 1 | Admin > Maintenance > Audit log; "QA-UI-" finds my records |
| Dept staff | new ticket | 5 | |
| Dept staff | attach file | +3 | reply form only (P1) |
| Dept staff | KB article | 2 | |
| Learner | portal Training -> course -> start | 3 | graded exam reached |
| Training manager | assign a course | 7 | |
| Training manager | export compliance CSV | 2 | file returned |
| IT manager | SLA / technician reports | 2 each | Export CSV, Print/PDF, Schedule |
| Anyone | global search | 2 | no-hit state fixed (D15) |

## 6. Not tested (candid)
- Android (separate phase); kiosk device enrolment/setup, guest/signature pages, SSO/MFA/passkey login.
- Passing the exam and the certificate (my answers were arbitrary); training authoring.
- Submitting every admin settings form (pages, links and modals were exercised; only users, assets and add-forms were submitted).
- RMM pages with the module on (it is off here); e-mail sending, backups, updates, cron (forbidden by the rules).
- Create/edit/delete cycles for software/licences, projects, calendar, recurring tickets, KB authoring.
- U1/U2 were measured, not fixed.
