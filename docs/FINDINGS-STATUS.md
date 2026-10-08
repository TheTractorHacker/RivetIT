# Findings status (issue #29)

Status of every item in the user-guide application findings list (`docs/user-guide/tools/app-findings.md` on the docs
branch), re-checked against the current `beta` code. Statuses: **FIXED** (already fixed on beta, with the place or
commit), **FIXED NOW** (fixed in the findings round-up), **DECIDED** (a product decision, see
`FINDINGS-DECISIONS.md`), **BY DESIGN** (intentional, with the reason), **OPEN** (known, not changed; listed at the
end as follow-ups). File and line references are current at the time of writing and drift.

## Highest priority

| Finding | Status | Evidence |
|---|---|---|
| Browser installer skips migrations; one run or click applies one step | FIXED | `setup/index.php:567-571,737`; `admin/database_updates.php:15-24` (995080fa9) |
| Credentials CSV export needs only Read | FIXED | `agent/post/credential.php:566` requires Full (995080fa9) |
| Log retention 0 or blank deletes everything | FIXED | `cron/cron.php:165` (995080fa9, 8bf97266c) |
| `mail_log` never pruned | OPEN | no prune job; follow-up |
| Edit Department wipes Type | FIXED | `agent/post/client.php:263-271` (995080fa9) |
| People Import update overwrites unlisted fields; `site` ignores shared sites | FIXED | `src/Directory/PersonImportService.php:216-233,268` (995080fa9) |
| IR password reset also resets portal accounts | FIXED | `admin/post/users.php:474` filters `user_type = 1` (995080fa9) |

## Security and permissions

| Finding | Status | Evidence |
|---|---|---|
| Users edit can demote the last administrator | FIXED | `admin/post/users.php:159-170` (an administrator can still demote themselves while another administrator exists) |
| Legacy API key acts as lowest-id agent, no admin check | FIXED | `api/v1/index.php:200-204` (14c1c6f9b) |
| RMM `save_policy` / `delete_policy` need only level 1 | FIXED | `agent/post/rmm_check.php:161,206` (14c1c6f9b) |
| `delete_policy` leaves pushed checks on agents | OPEN | follow-up |
| Report schedules need only Reporting level 1 | FIXED | `agent/reports/schedules.php:26` |
| Reports ignore per-user department restrictions | FIXED (partly) | `src/Reports/ReportScope.php` on 18 of 27 reports (1da274eec); seven still unscoped, see follow-ups |
| Restore edge cases (encrypted manifest, old backups, `--admin-user` only) | BY DESIGN | the key cannot be recovered without the passphrase; limits documented in `deploy/restore_admin_zip.sh` |
| Portal Contacts tile logs an employee out | FIXED | `client/index.php:431`, `client/includes/header.php:540` (961fe6342) |
| Portal preview lists every department's unassigned assets | FIXED | `client/index.php:151-157` (961fe6342) |
| Portal switched off only blocks new sign-ins | FIXED | `client/includes/check_login.php:179-184` (961fe6342) |
| System replies reach employees | FIXED | `client/ticket.php:358` (961fe6342) |
| Single contact delete never deletes the portal login | FIXED | `agent/post/contact.php:1017,1029-1031` (953158bc8) |
| Bulk Delete in Archived views has no confirmation | FIXED (verified) | every `name="bulk_delete_*"` button on all 15 entity pages carries `confirm-link`; `tests/findings_round.php` guards it (software has no bulk delete) |
| User Revoke / Activate / Disable and bulk API-key delete act at once | FIXED | `admin/users.php:206-215`; `admin/api_keys.php:50`, `admin/post/api_keys.php` |
| Portal-created document stores a contact id in `document_created_by` | BY DESIGN (not a bug) | `$session_user_id` is the portal account's `users.user_id`; see `FINDINGS-DECISIONS.md`, "Verified and not a bug"; guarded by a test |
| Technician role has no Reporting or Knowledge Base rows | DECIDED | no change; built-in roles are protected (`FINDINGS-DECISIONS.md` 12) |

## Functional bugs: service desk and tickets

| Finding | Status | Evidence |
|---|---|---|
| Category `&amp;` in group headers, counts 0 | FIXED | `agent/ticket_list.php:128-137,202-220` (246830462) |
| Force Reoccur has no Three Days / Biweekly rule | FIXED | `agent/post/recurring_ticket.php` (246830462) |
| Category group with sub-categories not selectable | OPEN | `functions.php` `ticketCategoryOptions` renders such groups as labels; follow-up |
| Automatic system notes log one minute | FIXED NOW + DECIDED | `FINDINGS-DECISIONS.md` 1 |
| `ticket_onsite` cannot be set from the UI | FIXED NOW + DECIDED | `agent/ticket.php` Edit schedule; `FINDINGS-DECISIONS.md` 2 |
| Share with all agents does nothing below Full | FIXED | `agent/modals/ticket/ticket_saved_view_add.php:43`, `agent/post/ticket_saved_view.php:22` |
| Resolved is remapped to Closed | BY DESIGN | `agent/post/ticket.php:1095-1101`, commented |
| `ticket_history` never displayed; Email Sent without SMTP | FIXED | `agent/ticket.php:417`, `:1068` |
| "(v2)"/"(v1)" titles, stray "To-do" text | FIXED | no occurrences remain |
| `problem.php` links the internal id when no number matches | FIXED | `agent/post/problem.php:5-13` |
| New Recurring Ticket form has no Assets tab outside a department | FIXED | `agent/modals/recurring_ticket/recurring_ticket_add.php:41-43` |
| `functions.php` looks for a status "Assigned" nobody seeds | FIXED NOW | `resolveTicketCreationStatus()`: Assigned (assigned tickets) or New, then Open, then the first active status, all by name; `tests/findings_round.php` |
| `escalate` shown as a raw key; ids shown | FIXED | `admin/ticket_automation.php:7,68` |
| Parser labelled with the wrong file name | FIXED | `admin/settings_ticket.php:46` |
| Mail queue red bin marks Failed rather than deleting | OPEN | message now says "cancelled and marked as failed"; icon unchanged (cosmetic) |

## Functional bugs: projects and calendar

| Finding | Status | Evidence |
|---|---|---|
| Calendar event click does nothing | FIXED | `agent/calendar.php:241` `eventClick` (6baf802fe) |
| Saving an event clears its department | FIXED | `agent/post/event_model.php:11` (6baf802fe) |
| Event Repeat disabled and never expanded | FIXED NOW + DECIDED | control and writes removed; `FINDINGS-DECISIONS.md` 9 |
| Calendar arrows invisible (CSP, `defaultView`) | FIXED | `includes/header.php:22`, `agent/calendar.php:205` |
| Project Delete hidden from non-administrators | FIXED | `agent/projects.php:279`, `project_details.php:403` |
| Project task bar counts only ticket tasks | FIXED | `agent/projects.php:204-211` |
| `?archived=1` with default Open status empty | FIXED | `agent/projects.php:26-27` |
| Un-tick after Kanban Done; progress stays 100 | FIXED | `agent/post/task.php:265`, `agent/ajax.php:2223` |
| Closed ticket to a No-Department project fails; NULL updated-at | FIXED | `agent/post/project.php:449-459` |
| Template project loses time estimates; undo adds a minute; delete leaves orphans | FIXED | `agent/post/project.php:176-178,351-354`; `agent/post/task.php:269` |
| Task template delete looks up `tags` | FIXED | `admin/post/ticket_template.php:104` |

## Functional bugs: departments and people

| Finding | Status | Evidence |
|---|---|---|
| Person Location pickers miss shared sites | FIXED | `contact_add.php:8`, `contact_edit.php:258`, `contacts.php:170` (953158bc8) |
| No UI moves a person to another department | DECIDED | no change; People Import only (`FINDINGS-DECISIONS.md` 12) |
| Restoring a note reads the wrong key | FIXED | `agent/post/contact.php:346` (953158bc8) |
| Start workflow redirect lands on a non-page | FIXED | `agent/post/contact.php:1583` |
| Department CSV export ignores access / includes archived | FIXED | `agent/post/client.php:754` |
| Department import creates a legacy per-department location | OPEN | `agent/post/client.php:975`; follow-up |
| Bulk department email: mails everyone with nothing ticked | FIXED | `agent/post/client.php:1375-1378` (archived departments are still not filtered) |
| CRM leftovers with CRM off | FIXED | `agent/contact_details.php:512,1446`, `client_overview.php:810` |
| `config_destructive_deletes_enable` has no writer | FIXED NOW + DECIDED | Security settings switch; `FINDINGS-DECISIONS.md` 10 |

## Functional bugs: infrastructure and documentation

| Finding | Status | Evidence |
|---|---|---|
| Location dropdowns miss shared sites (assets, bulk, racks, networks) | FIXED | c5cc2c511 |
| Firewall/Router assets appear in no Assets tab | BY DESIGN | listed on the Network page (`agent/network.php:10-15`) |
| Rack Model never saved | FIXED | `agent/post/rack.php:19,66` (c5cc2c511) |
| `module_contracts` permission does not exist | FIXED | `agent/modals/contract/contract_edit.php:3` uses `module_support` |
| Company-wide vendors need Financial | DECIDED | stays Financial-only; guide wording fixed (`FINDINGS-DECISIONS.md` 11) |
| `agent/vendor_details.php` requires missing files | OPEN | search no longer links to it; the orphan page remains; follow-up (delete it) |
| Domain edit overwrites expiry with WHOIS | FIXED | `agent/post/domain.php:82-91` |
| License edit drops seat links outside its department | FIXED | `agent/post/software.php:147` |
| Bulk Transfer copies an asset to a new id | BY DESIGN | audited, old and new ids logged (`agent/post/asset.php:~455-466`) |
| Deployed assets show a grey badge | FIXED | `client/assets.php:29,88,105` |
| TOTP element id collision; History markup in `title`; Word-import heading | FIXED | `credential_show_otp_via_id.js:13,29`; `credential_edit.php:~318`; `kb_article_import_pdf.php:82` (c5cc2c511) |
| TinyMCE image upload tab spins | BY DESIGN | `agent/kb_article_upload.php` is documented as deliberately disabled |
| Printer Location ignores `department_sites` | FIXED | `printer_add.php:10`, `printer_edit.php:26` (c5cc2c511) |
| Printer/drive bulk Delete on Archived ungated | FIXED | `agent/printers.php:81`, `network_drives.php:80` test the switch and confirm |
| KB article always shows Delete | FIXED | `agent/kb_article.php:325` |
| Portal KB shows raw `[[credential:ID]]` | FIXED | tokens stripped, `client/kb_article.php:110-111` (the portal has no reveal modal) |
| Credential OTP export may be ciphertext | FIXED | `agent/post/credential.php:601` |

## Functional bugs: training

| Finding | Status | Evidence |
|---|---|---|
| "Once the Learning Center launches" wording | FIXED | d98ba5e6b |
| Kiosk Origin vs `config_base_url` refuses `127.0.0.1` against `localhost` | BY DESIGN | `KioskRouter.php:251-283` |
| New License Devices tab header dark on dark | FIXED | `software_add.php:241` (2d6f4f60b) |

## Functional bugs: endpoints and reports

| Finding | Status | Evidence |
|---|---|---|
| Connect on Sophos / Action1 fails | FIXED | `agent/post/rmm_remote.php:~55` (d98ba5e6b) |
| Sophos acknowledge/resolve `vendor_warning` ignored | FIXED NOW + DECIDED | toast and bulk summary; `FINDINGS-DECISIONS.md` 3 |
| `config_comet_auto_ticket` never read | FIXED NOW + DECIDED | `FINDINGS-DECISIONS.md` 4; `tests/findings_round.php` |
| `/api/v1/metrics-ingest` has no route/table/UI | DECIDED | unreleased, documented (`FINDINGS-DECISIONS.md` 5) |
| Custom fields stored but never read | DECIDED | labelled "Stored only" (`FINDINGS-DECISIONS.md` 6) |
| Docker runs only `cron.php` | FIXED | `docker/supervisord.conf:64,73` (d98ba5e6b) |
| RMM dashboard "critical" counts errors | FIXED | tile reads "critical/error" (`rmm_dashboard.php:325`) |
| Script Library has five categories; "1 agents"; Ack All Shown; `btn-outline-light` | FIXED | `rmm_scripts.php:92`; `rmm_checks.php:136`; `rmm_alerts.php`; `asset_details.php:409` |
| Day-by-Day "This month"; credential rotation includes archived; hub "per month"; Waiting on Employee tile; CSAT note | FIXED | `ticket_day_breakdown.php:8-14`; `credential_rotation.php:14`; `reports/index.php:76`; `dashboard.php:890`; `csat.php:119` |
| Role editor "Open every report" | FIXED | `role_lib.php:181-185` |
| Archived user Activate; API key Secret column; `$extended_log_description`; Users CSV archived; roles join | FIXED | `admin/users.php:210`; `api_keys.php:116`; `admin/post/users.php:243,392,405`; `roles.php` |
| Kiosk-only role sent to Devices & PINs then refused | FIXED NOW + DECIDED | `Access::kioskDecision`; `FINDINGS-DECISIONS.md` 7 |
| Preferences page sizes 10/25/50/100 vs footer 5 to 500 | FIXED NOW | `recordsPerPageOptions()` used by Preferences, the footer handler and the footer; the footer handler also rejects unknown sizes |
| Departments date range shows `1970-01-01 - 2099-12-31` | FIXED | `js/date_range_picker.js` (482a639f8) |
| Session length: 30-day minimum vs stored defaults 480 / 3 | FIXED NOW + DECIDED | labels show the effective values (`FINDINGS-DECISIONS.md` 8) |
| Start Page offers Invoices with accounting off | FIXED | `settings_default.php:10-12` |
| Light cannot override company dark default | FIXED | `includes/header.php:56` |
| `contacts.php:529` tests `session_user_role == 3` | FIXED | no longer present |
| Passkey message tells users to type an email | FIXED | `js/webauthn_signin.js:57` (1fa1cfebd) |
| First click on another heading sorts the wrong way | FIXED NOW | `nextSortOrder()` / `sortLinkOrder()`; verified on three list pages (`tests/findings_http_walk.php`) |
| `admin/category.php` opens on Expense | DECIDED | no change (`FINDINGS-DECISIONS.md` 12) |
| `REBRANDING.md` claims telemetry | FIXED | now says RivetIT sends none (a495fab11) |
| Dark-on-dark card titles (portal, guest) | FIXED | `client/ticket.php:106-107`, guest pages (1fa1cfebd) |

## Open follow-ups (not changed here)

- `mail_log` is never pruned.
- RMM `delete_policy` does not remove the checks already pushed to agents.
- Seven reports still ignore per-user department scope: `csat`, `clients_with_balance`, `mrr`, `ticket_day_breakdown`, `service_desk`, `technician_performance`, `rmm_health`.
- A ticket category that has sub-categories cannot be chosen in ticket forms.
- Mail queue delete icon marks a message Failed instead of deleting it.
- Department import still creates a legacy per-department location; bulk department email does not filter archived departments.
- `agent/vendor_details.php` is an orphaned page requiring files that do not exist.
