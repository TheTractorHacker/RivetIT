# RivetIT beta audit: RivetPanel API, Mesh approval, Core rc.31 fixes, UX and accessibility

Read-only audit. Nothing was fixed. Date 2026-10-23. Auditor: QA agent (fork), Claude Sonnet 5.5.

## 0. What was audited, and where

| Item | Value |
|---|---|
| Code | `origin/beta` commit `f4a39ef6a` (RivetIT 26.10.70, RivetCore `v1.0.0-rc.35`, confirmed from `composer.lock` and `vendor/composer/installed.json`) |
| Host PHP | `PHP 8.5.4 (cli) (built: Oct 19 2026)` |
| Scratch instance | worktree `/home/sysadmin/qa-beta-audit` (branch `audit-beta-20261023`), app copy `/home/sysadmin/qa-beta-audit-app`, DB `qa_beta_audit_demo`, `php -S 127.0.0.1:8196` (32 workers, `/home/sysadmin/demos/router.php`), throwaway Redis on 127.0.0.1:6391. Fictional demo data only; every record the audit created is named `QA-UI-...`. |
| Real beta (`https://mw-itflow.foleyit.com`) | Unauthenticated GET probes only (3 requests, no writes, no login). |
| mwa | Not touched. |

**Why a scratch copy.** There are no beta credentials, and enrolment, token minting and Mesh approval write data. Everything that needs a login or a write ran on the scratch copy of the exact beta commit. Scratch-only setup steps (none changed product code): DB built with `docs/user-guide/tools/build-demo.sh`; the endpoint-agent switches turned on in the scratch DB and a signing key generated there; a throwaway Redis so the Redis-backed rate limits work and no key could reach the host Redis.

**Limits that matter when reading the results** (also in section 6): the scratch server is PHP's built-in server, not nginx plus PHP-FPM behind Cloudflare. Anything that depends on proxy IP resolution, body buffering, FPM limits or the nginx rules is flagged as such. Redis state on beta/mwa is unknown.

## 1. Totals

| Severity | Count | Findings |
|---|---|---|
| Critical | 0 | |
| High | 3 | F1, F2, F3 |
| Medium | 16 | F4 to F19 |
| Low | 9 | F20 to F28 |
| Info / confirmed good | see section 4 | |

Check counts: 158 panel API checks, 19 panel lifecycle/header/audit checks, 32 Mesh checks, 40 + 23 redaction vectors plus a 16-marker end-to-end run, 7 alert-text vectors, 36 modal-permission probes, 138 page runs for axe/contrast/overflow (23 pages x light/dark x 1280/768/360), 71 widget checks. Scripts are in `tests/qa/audit/`, all re-runnable (README at the end of this file).

## 2. Ranked findings

Format: severity, where, what happens (redacted request/response or what a user sees), steps, suggested fix. For UX items the fix owner is **shared (Core)** when the file is a byte-identical copy of `vendor/rivet/rivet-core/assets/ui/` (all of `css/*.css` listed there and `js/{chart_theme,date_range_picker,event_picker,icon_picker,webhook_guides,webhook_wizard}.js`; verified with `cmp`) and **edition** when it is a RivetIT PHP/markup file.

### HIGH

**F1. Light theme: secondary text fails WCAG AA (3.0:1 on white, 2.8:1 on the app surface), on 11 of 23 pages. shared (Core)**
- Measured by computing the contrast of every visible text node (`ux_audit.py`) and by axe `color-contrast` (783 nodes over the run). Text colour `rgba(107, 114, 128, 0.75)` on `#fff` is 3.01:1 and on the light surface `#eef2f2` is 2.79:1 (needs 4.5:1).
- Affected: table headers (`th`), form labels ("Search"), `.wz-sub`, `.wz-pcard-desc`, `.wg-card-desc`, `.wg-pill`, `.er-sub`, `.er-recipe-blurb`, `.rmmx-sub`, `.rmmx-kpi-sub`, `.rmmx-result-count`, the `Windows` platform text, `.icon-picker__hint`, `.drp-group-title`.
- Cause: Tabler's `--tblr-secondary-color` is `rgba(107,114,128,.75)`. Core's `itflow_design.css` overrides only the `.text-secondary` / `.text-muted` utility classes (to `#566570`), and defines `--if-muted`, but never re-points `--tblr-secondary-color`. Component CSS that reads `var(--tblr-secondary-color)` therefore still gets the grey.
- The deliberate change is correct but does not fix this: computed `--if-muted` is `#4b5d64` (light) and `#93a6ab` (dark), 6.89:1 and 6.59:1. The old value `#5d6f76` was already 5.25:1 on white. The text that fails does not use `--if-muted`.
- There are now three different "muted" greys in light mode (`#4b5d64`, `#566570`, the Tabler rgba). Several stylesheets also keep the old fallback `var(--if-muted, #5d6f76)` (harmless while the variable exists).
- Fix: in `itflow.bind-tabler.css` set `--tblr-secondary-color: var(--if-muted)` (light and dark) and make `.text-muted/.text-secondary` use `--if-muted` too.

**F2. `role="tablist"` on a list of plain links (Webhooks and Webhook guides pages): axe "critical". edition**
- `admin/settings_webhooks.php` and `admin/settings_webhook_guides.php`: `<ul class="nav nav-tabs ..." role="tablist"><li class="nav-item"><a class="nav-link" href="...">`. axe: `aria-required-children` (critical, 1 node on each page, all viewports and both themes) and `listitem` (2 nodes). A screen reader announces a tab list with no tabs and the items are no longer list items.
- Fix: these are page links, not tabs. Remove `role="tablist"` (leave it a `nav` with `aria-current="page"`).

**F3. Fresh install aborts at database update 2.6.190 ("Duplicate column name"). edition** (independently reproduced)
- On `f4a39ef6a`, `update_cli.php --update_db` after a fresh `db.sql` install stops with `Duplicate column name 'tauto_odoo_send_badge'` at `admin/database_updates.php:11746` (`ALTER TABLE training_automation ADD COLUMN tauto_odoo_send_badge`). Existing installs upgrading from before 2.6.190 are not affected. Workaround used in the scratch DB only: drop the column and re-run. Same class of bug as the earlier 2.6.184/186 one.
- Fix: add the column only when it is missing (guarded `ADD COLUMN`).

### MEDIUM

**F4. Mesh: the "needs approval" alert and Approve button stay after approval. Core or edition**
- Repro (scratch): device reports a node (state `needs_approval`), admin presses Approve. DB afterwards: `endpoint_agent_mesh_nodes.source = manual` (mapped) but `endpoint_agent_mesh_agents.map_state` is still `needs_approval`. The device page then shows, at the same time, "Remote access: Mapped to a MeshCentral node (manual)" and the yellow alert "This device reported a Mesh node that has not been approved yet, so nothing was mapped" with the Approve button. It only clears after the device's next check-in. Evidence: `evidence/mesh_owner_full.jpg`.
- Fix: the `set_mesh_node` action should reconcile `map_state` (set it to `mapped`/clear it) when it maps the node the device reported.

**F5. Mesh: mapping a node by hand (and the new Approve button) has no conflict check and no uniqueness. Core**
- `POST /agent/post/rmm_agent.php` `action=set_mesh_node` with a node id that another **live** device already owns returns `{"success":true}` and creates a second row: devices A and B both mapped to the same node (`endpoint_agent_mesh_nodes` has `PRIMARY(device_id)` and only a non-unique `idx_node(mesh_node_id)`). "Remote" on B would open A's machine. The device-report path is protected (conflict, never moved: verified, section 4), but the manual and Approve path is not. A stale page makes it easy: the page rendered `data-node` earlier, another device claims it, the admin clicks Approve.
- Fix: refuse (or require an explicit confirm naming the other device and client) when the node is mapped to another live device; consider a unique index.

**F6. Mesh needs-approval state is hard to find and ambiguous for a non-technical admin. edition (copy and layout)**
- The card is about 1,240 px down the page (below "Maintenance jobs"); at the top the device header still shows an enabled green primary "Launch remote session" button next to an amber "Waiting for approval" badge. That badge means the **device** must be approved for jobs (a different concept), while the Mesh alert says "approved" about the **node**. The Status card says "Remote access: Not mapped to a MeshCentral node" with no pointer to the pending Approve.
- Copy: "Check this node in MeshCentral, then map it. [Approve this node]" does not say what "approve" does (it binds this device to remote control of that node), shows no node id in the sentence, and there is no confirm step.
- Conflict text for two devices in the **same** department says "The other device belongs to another department, or is not shown to you" when the other device simply has no asset link (link is only built when `asset_id > 0`).
- Non-admins see the alert but neither the button nor any note about who can approve. A visible literal backslash: "...lets the agent\'s node replace it" (`agent/rmm_agent_device.php:216`).
- Suggest: show the state in the "Remote access" row with the action next to it, disable or annotate "Launch remote session" while unmapped, name the node and device in a confirm, rename the two kinds of approval, name the conflicting device.

**F7. Approve button fails silently on a bad or expired CSRF token. edition**
- `validateCSRFToken()` (`functions.php:1419`) redirects with `Location: index.php` (relative). From `/agent/post/rmm_agent.php` that resolves to `/agent/post/index.php`, an HTML 404. `fetch()` follows it and `r.json()` throws; `agent/rmm_agent_device.php:281` has no `.catch`, so nothing is shown and the button does nothing. Repro: admin POST with `csrf_token=wrong` ends on `404 Not Found` (HTML). This affects every standalone handler under `agent/post/*.php` (the Deploy page's own form posts hit the same 404 on a bad token).
- Fix: JSON endpoints answer `403 {"error":"Invalid CSRF token"}` (the missing-token branch already does); the helper should redirect to the referring page; add `.catch` with a visible message.

**F8. Enrollment throttle locks out legitimate enrolments from the same IP for 10 minutes. Core (plus edition IP configuration)**
- `PanelEnrollment`: 10 failures per source IP per 10 minutes, then `429 rate_limited`, `Retry-After: 600`, for every enrolment from that IP. Repro: 10 enrolments with a bad token then one with a valid fresh token: all later calls 429, including the valid one (`X-Forwarded-For` spoof does not change the bucket).
- Impact: anyone at a department (or one mistyped token repeated) blocks the whole site's installs. If beta/mwa do not resolve the real client IP (the code comment in `api_ratelimit.php` says this box "sits behind a reverse-proxy chain with no nginx real_ip config yet"), every client shares one bucket and one attacker blocks all enrolments. I could not check beta's setting (section 6). Each failed attempt also writes an audit row (59 rows from this audit's attempts).
- Fix: key the failure counter by IP **and** token selector, or count only attempts that do not name an existing selector; make sure `getIP()` resolves the real address.

**F9. Rate limits fail open when Redis is down (panel and general API). edition**
- With Redis unreachable: 14 ticket creates by one device (limit 10/60 s) all returned 201; 40 invalid-token calls (limit 30/60 s) produced no 429. With Redis up: the 11th create returns `429` with `Retry-After: 60`, and the valid-device calls are not locked out by auth-failure throttling (checked). `api_rate_limit()` defaults to `fail_closed = false`; the panel passes the default. The 50-tickets-per-day cap is DB-backed and still works. Whether beta/mwa have Redis on is unknown.
- Fix: fail closed (or use a DB-backed fallback) for the create/chat/enrol budgets; at minimum alert when the limiter is unavailable.

**F10. Change-monitor redaction still leaks several common forms. Core**
- 40 vectors through `ChangeProtocol::redact()`: 38 masked. Leaks: `mariadb -u root -pSECRET` and `mariadb-dump -u root -pSECRET` (attached `-p`; the changelog says "`-p <word>` for mysql, mariadb, sshpass and mongo", only the spaced form works for mariadb and mongo), and a bare 24-character base64 value (documented limit).
- A second 23-vector set: 14 leak: `mariadb -pSECRET`, `mariadb-admin -pSECRET`, `mongosh -u a -pSECRET`, `docker login -u u -p SECRET`, `redis-cli -a SECRET`, `ldapsearch -w SECRET`, `smbclient -U bob%SECRET`, `7z a -pSECRET`, `winrar -hpSECRET`, `useradd -p SECRET`, `setx DB_PASSWORD SECRET`, `[Environment]::SetEnvironmentVariable("TOKEN","SECRET")`, `chpasswd <<< user:SECRET`, `echo SECRET | passwd --stdin`. Masked correctly: `DB_PASS=`, `ENCRYPTION_KEY=`, `--password=`, `--password x`, `--token x`, JSON `"password"`/`"api_key"`, `Authorization: Bearer ...`, URL `user:pass@`, `PGPASSWORD=`, `MYSQL_PWD=`, `Environment=APP_SECRET=`, AWS secret key lines, sshpass `-p`, `net use /user:`, `schtasks /RP`, `curl -u`, `X-Api-Key` header, private key blocks, `Password=` in connection strings.
- End to end (`change_redaction_e2e.py`, a Windows device reporting 5 changes): 16 secret markers sent; 14 masked in `endpoint_agent_change_records` (item/old/new/summary), the rule's alert text, and nowhere else in a full DB dump; 2 stored in clear: the `mariadb -p` one and a bare `FOO_BAR=value` env line (documented known gap).
- Loopback webhook capture (generic-JSON webhook created through the wizard, subscribed to `rmm.change.detected` and `rmm.change.rule_matched`, delivered to a local capture server): 6 deliveries (5 + 1). The same 2 markers appear in the delivered bodies (`mariadb -pSECRET` in one `rmm.change.detected`, the bare `FOO_BAR=` line in another); no other marker, no `<`, no backtick, no bidi or zero-width character. The one `>` in the output is the application's own `->` in the rule sample. Alert text and webhook text go through `alertText()`, which strips markup characters but does **not** redact, so whatever ingest lets through also reaches alerts and webhooks (queued job payloads in `integration_jobs` carry the same values).
- Fix: add attached `-p` for `mariadb*`/`mongo*`, `docker login -p`, `redis-cli -a`, `ldap* -w`, `-U user%pass`, archive `-p`; consider masking any `NAME=value` env line in watched env files.

**F11. Panel API: archived tickets and archived clients. edition (`EndpointPanelGateway`)**
- A ticket archived in the agent UI is still listed to the device (`GET /api/v1/panel_tickets` includes it; the list query has no `ticket_archived_at IS NULL`) and still accepts chat (`POST panel_ticket_chat` -> 201). A device whose client is archived can still create tickets (`201`). Archiving a **contact** is handled (`403 contact_invalid`).
- Fix: filter archived tickets in list/chat/create and refuse create/chat for an archived client.

**F12. Panel Deploy page defects (edition `agent/panel_deploy.php`)**
- Times shown raw from the DB in UTC with no zone: "Expires 2026-10-25 03:01:57" (actual local time CDT is 22:01), "First seen" the same. Every other RMM page shows local time with a zone label ("21:51 CDT").
- One-time token: shown in a dark `<code>` block with no Copy button; a toast and an alert both announce it (the toast overlaps the search bar in the screenshot).
- Revoke confirmation is the generic "Are you sure?" and does not name the token or device. Revoke is a GET with the CSRF token in the URL.
- "Pending" badge wraps mid-word ("Pendin / g") in the Outstanding tokens table at 1280 px.
- "Contact (optional...)" defaults to "None (client-level)", which creates a device that cannot open tickets until a per-client switch is turned on; nothing on the form explains this.
- "Turn on" (green outline) 2.74:1; "Used 0 / 1" hard-codes the 1; labels are `<label class="form-label">` without `for`/`id` (names are added at run time by `js/a11y.js`; the markup itself is not associated).
- Empty states say "No tokens yet." / "No devices enrolled yet." with no next step (where to get RivetPanel, what to paste, which server URL).
- Evidence: `evidence/panel_token_full.jpg`, `panel_deploy_light_1280.jpg`.

**F13. Dark mode contrast on shared widgets. shared (Core)**
- Webhook guides (24-25 nodes: blue `#2563eb` text, 3.0 to 3.2:1, e.g. "Why", `.wg-chip-cat`), Event rules builder (`<summary>` links, 15-16 nodes), RMM policies (12), `btn-outline-primary` on RMM fleet. 

**F14. Webhook wizard: validation errors are not programmatically linked, and one error message is missing. shared (Core `js/webhook_wizard.js`)**
- Step 2 with empty fields: three controls get `.is-invalid`, none has `aria-describedby`; the only live regions say "Step 2 of 4: Connect" and "0 events selected". The Name field shows "This is required."; the URL field turns red but shows no message (screenshot `evidence/wz_connect_error_light_1280.jpg`).

**F15. Tickets page: the primary "New Ticket" button has no accessible name at tablet and phone widths; small link targets. edition (`agent/tickets.php`)**
- axe `button-name` at 768 and 360 wide: the label is a `<span class="d-none d-lg-inline">`, leaving an icon-only button. Also `target-size` (WCAG 2.2): 10 links (client names in the list).

**F16. Nested `<main>` on Webhook guides. edition (`includes/webhook_guide_page.php`)**
- `<main class="wg-main" id="wg-main">` sits inside the app's `<main id="main-content">`: axe `landmark-no-duplicate-main`, `landmark-main-is-top-level`, `landmark-unique`.

**F17. Global search box has no visible focus indicator on any page. shared (Core CSS) or edition header**
- 38 of 138 runs list it as the only tab stop without an indicator. Focused `input[placeholder="Search everywhere"]`: `outline: none`, no box-shadow, group border unchanged (`evidence/global_search_focus.jpg` shows only a faint background change).

**F18. Horizontal page scroll at 360 px and scrollable regions not reachable by keyboard. shared (Core CSS)**
- Overflow at 360 px: RMM dashboard (content 542 px) and Audit log (498-533 px), both themes. axe `scrollable-region-focusable`: 10 nodes at 360 px (Deploy page tables, the Mesh page cards, Webhooks table), 2 at 768 px.

**F19. Unauthenticated body read and decode on the general API. edition (`api/v1/index.php:205`)**
- Any `POST` with no token and no `X-Api-Key` reaches `json_decode(file_get_contents('php://input'))` before authentication (a wrong Bearer token also does). A 20 MB JSON array to `/api/v1/tickets` took 1.1 s of CPU against 0.01 s for a tiny body (scratch PHP has no memory limit). On FPM the cost is bounded only by nginx `client_max_body_size` and `memory_limit`. The panel and agent endpoints are routed above it on purpose.

### LOW

- **F20.** API CORS: `Access-Control-Allow-Origin: *` with preflight `204` for any origin on all `/api/v1/*` including the panel endpoints (confirmed on real beta). Tokens are bearer headers, not cookies, so the exposure is small. API JSON also lacks `X-Content-Type-Options`, `Strict-Transport-Security` (the HTML login page has nosniff, `X-Frame-Options: DENY`, a CSP). edition (`api/v1/index.php:8-10`)
- **F21.** Idempotency: replaying a `client_request_id` with a **different** payload silently returns the first ticket (`replayed:true`); after the 5-minute claim expiry a duplicate is possible (audit `Panel Ticket Duplicate` exists). Core, informational.
- **F22.** Zero-byte attachment is reported as "Attachment 1 is not valid base64 data". Core.
- **F23.** `Authorization: Bearer  <token>` (two spaces) is accepted (`\s+`); trailing space is rejected. Informational.
- **F24.** Reduced motion: `js/webhook_wizard.js` calls `scrollIntoView({behavior:'smooth'})` unconditionally (lines 79, 382); `itflow_rmm_ui.css` has 4 transition declarations and no `prefers-reduced-motion` block. shared (Core)
- **F25.** Event rules recipe tiles are `<a role="listitem">` (axe `aria-allowed-role`, 11 nodes). edition (`admin/includes/event_rules_editor.php`)
- **F26.** CSV attachments are stored verbatim, so a `=cmd|...` cell is kept; staff who open the file in Excel get the formula. Core, low.
- **F27.** Hosted-installer upload (F5 in the Core review): on the REST route (`POST /api/v1/endpoint_devices/software_files`) the role gate runs before the body: a technician without RMM rights gets `403` for a 60 MB body in 0.5 s and nothing is stored. The **web** handler (`agent/post/rmm_auto_install.php`, multipart) calls `uploadFile()` after PHP has already written the upload to its temp directory, so the gate cannot save that disk write; only nginx limits do. Informational.
- **F28.** PDF attachments are accepted if the header is `%PDF-` (no script/launch-action scan); staff who open them in a viewer see whatever the PDF contains. Low.

## 3. Item 4 (verbatim from the parent, then verified on the scratch instance)

> `includes/modal_permissions.php` in the main checkout has NO uncommitted change at the moment; entries for quote, invoice, recurring_invoice, product (`module_sales=>1`) still exist at lines 81-84, but `agent/modals/quote`, `invoice`, `recurring_invoice`, `product` and ticket quote/invoice modal files do not exist (removed in commit `6089d450b` "Remove invoicing/billing from this edition"; only `agent/modals/price_quote/*` exist); unmapped files fall back to a default requirement (Departments, Tickets/assets/docs or Assets at level 1, never a module-only login) and are logged, so it's a default-restrict not a default-deny.

Verified with `modal_perm_test.py` (a throwaway unmapped file was added to the **scratch app copy** and removed afterwards):

| Request | admin | technician | module-only (Sales) | client-portal user |
|---|---|---|---|---|
| `/agent/modals/{quote,invoice,recurring_invoice,product}/*_add.php`, `ticket_quote_add.php`, `ticket_invoice_add.php` (files do not exist) | 404 | 404 | 404 | 404 |
| unmapped new file `agent/modals/zz_qaunmapped/qa_unmapped.php` | **200** | **200** | 403 | 302 to login |
| `price_quote/price_quote_add.php?client_id=16` (mapped `module_client=>2`) | 200 | 200 | 403 | 302 |
| `../../includes/config.php` traversal | 404 | 404 | 404 | 404 |

Conclusion: the four removed modals are unreachable by anyone (no file). A newly added **unmapped** modal is open to every agent with Departments or Tickets level 1 (the technician got 200) and denied to module-only logins and the portal. Dead map entries are harmless; removing them leaves behaviour unchanged.

## 4. Confirmed good (checked, no defect)

RivetPanel API (`panel_api.py` 158 checks, `panel_extras.py` 19):
- Auth: no token, fake, wrong prefix, uppercase hex, truncated, trailing space, lower-case `bearer`, Basic, bare token, old `X-Api-Key`, SQL/JSON oddities, NUL byte, token in query string: all `401 invalid_token`. Plain HTTP without the TLS marker: `426 tls_required`. `.php` URL: empty 200. Same codes on real beta (`401 invalid_token` with `server_time`, `.php` 200 length 0).
- Device tokens and enrolment tokens stored as 64-hex SHA-256 only; no `rvpd_`/`rvtp1` text in any audit row; `rvpd_` tokens fail on `agent_checkin`, `agent_jobs`, the general API and as `X-Api-Key`; an `rvat_`-style token fails on panel endpoints; an RMM enrolment token on `panel_enroll` and a panel enrolment token on `agent_enroll` both give `401 invalid_token`.
- Enrolment: single use (second use `403 forbidden`), expired and revoked tokens refused (`401 revoked`), garbage bodies `422`, `GET` `405` with `Allow`, another client's token cannot take an `install_id` (`409`), re-enrolment of the same machine rotates the token (old 401, new 200), a revoked device cannot re-enrol (`403`).
- Isolation: spoofed `client_id`, `contact_id`, `priority`, `status`, `assigned_to` in body or query are ignored (ticket stored with the enrolled client and contact, priority Medium, source `Panel`); other contact or other client gets `404` (not 403) on list and chat; internal notes never returned; client-level device gets `403 client_level_disabled` until the per-client setting is on, sees only its own tickets, and the switch off blocks it immediately.
- Revoke from the Deploy page: next API call `401 revoked` in 0.15 s.
- Idempotency: sequential replay `200 replayed:true`; 10 concurrent identical `client_request_id`: exactly **1** ticket row and 1 owner row, responses 7x200, 1x201, 2x409 `request_in_progress`; scoped per device; bad ids `422`.
- Attachments: all of exe/dll/msi/bat/ps1/sh/js/vbs/zip/7z/rar/tar.gz/svg/html-in-txt/ELF/shebang/NUL/MIME-lie/double-extension/no-extension cases refused with clear 422; oversize `413`; 4 attachments refused; traversal, NUL and RTL names stored under sanitised display names with random hex on-disk names.
- Validation: 16 MiB body `413`; invalid UTF-8, list body, depth bomb, missing/oversize fields `422`; method override header ignored; bidi, zero-width and control characters stripped on input; markup stored verbatim and escaped on output (agent ticket page escapes in `<h4>` and JSON-encodes in the title script; list, kanban, dashboard, search show no live markup).
- Rate limits (Redis up): create 10/60 s gives `429` + `Retry-After: 60`, other devices unaffected; auth-failure throttle works and does not block a valid device.
- Audit rows exist for enrol, ticket create, chat, token created, token revoked, device revoked (`logs`, type "Endpoint Agent", actions `Panel Enrolled`, `Panel Ticket Created`, `Panel Chat Posted`, `Panel Enrollment Token Created/Revoked`, `Panel Device Revoked`, `Panel Enrollment Failed`).
- Deploy page permissions: login required; portal user redirected; minting needs `module_support` level 2.

Mesh (`mesh_test.py`, 32 checks): a new device's node is **not** mapped (`needs_approval`, audit `Mesh Node Needs Approval` once per device, not repeated); Approve by an admin maps it (`source: manual`), twice is idempotent; technician and portal user cannot approve; a node mapped to another live device (same or other client) gives `conflict` on the **device report** and is never moved; a node bound to a retired device is not moved to a different machine; the conflict page does not reveal another client's device; a re-enrolled machine (same `install_id`, serial and machine GUID) keeps its row and mapping; malformed node ids (short, long, SQL, markup, bidi) never map and never error.

Core rc.31 other items: alert text and webhook text (`alertText`, 7 vectors incl. `<script>`, `<img onerror>`, bidi, zero-width, backticks): no markup characters, no bidi; `text()` removes bidi and C1 characters; hosted-installer REST upload gate (F27); `install-linux.sh` from tag `v1.0.0-rc.35` (static): `mktemp -d` work directory, `trap 'rm -rf "$WORK"' EXIT`, the agent runs as a child through `run_and_clean` (not `exec`), `bash -n` clean, `--installer-sha256` documented and warns when absent. It needs root, so it was **not** run.

UX and accessibility: 138 page runs show no console errors, no failed requests, no `alert()/confirm()/prompt()` calls, no unnamed buttons or unlabeled inputs (names added by `js/a11y.js` at run time), no horizontal overflow at 1280 and 768. Times on the Mesh device page are local with a zone ("21:51 CDT"). Date range picker: opens with Enter, 2x Arrow keys move through options, `Esc` closes and focus returns to the trigger, `role=dialog`/`option`, `aria-expanded` updates (checked at 1280 and 360). Webhook wizard: every tab stop has a focus indicator and a name, Enter on a platform card advances to step 2, step count announced in a live region. Icon picker: keyboard stops have indicators, search works, `621 icons`. Revoke uses the Bootstrap confirmation modal, not a native dialog.

## 5. Test matrix

| Area | Script | Checks | Result |
|---|---|---|---|
| Panel API | `panel_api.py` | 158 | 155 pass; 3 flagged = F11 (archived list), 1 harness artefact (helper omits an empty id), 1 hypothesis refuted (valid device not locked out) |
| Panel lifecycle, headers, audit, permissions | `panel_extras.py` | 19 | 14 pass; 5 findings (F11 x3, F20 x2) |
| Enrollment throttle | inline (section F8) | 1 scenario | F8 confirmed |
| Redis down | inline (section F9) | 2 scenarios | F9 confirmed |
| Mesh | `mesh_test.py` | 32 | 32 as expected (includes the F4, F5, F7 and F6 confirmations) |
| Redaction (function) | `redaction_vectors.php` + inline | 40 + 23 + 7 | F10 |
| Redaction (end to end, incl. webhook capture) | `change_redaction_e2e.py`, `webhook_capture_*.py` | 16 markers, 5 records, 6 webhook deliveries | 2 leaks in records and webhooks (F10); alert text clean |
| Modal permissions | `modal_perm_test.py` | 36 probes | section 3 |
| Pages (axe AA + BP, contrast, overflow, focus, dialogs) | `ux_audit.py` | 138 runs | F1, F2, F13 to F18 |
| Widgets and flows | `widgets_audit.py`, `flows_shots.py` | 71 + 5 flows | 28 clean; 43 flagged = F1/F13/F14/F2/F16/F25 repeated across themes and widths; two "Esc closes the picker" rows are a selector error in my script (re-tested by hand: passes) |
| Real beta | `curl` | 3 GETs | `401 invalid_token` x2, `.php` empty 200, headers noted (F20) |

## 6. Could not check

- Real beta/mwa configuration: nginx rules, Cloudflare/real-IP resolution (F8 severity depends on it), whether Redis is on (F9), PHP-FPM `memory_limit`/`post_max_size`, `client_max_body_size` (F19, F27). The scratch server is PHP's built-in server; its body buffering and routing differ from nginx + FPM (the router handles `/api/v1/*` without `.php` as the live rules do, but I did not compare them).
- A real MeshCentral server: "Launch remote session" after Approve reached "Remote access through MeshCentral is not configured" in the scratch copy (no mock running), so the end of the Remote flow was not exercised. Mapping, conflict and approval logic were.
- The RivetPanel desktop client itself, and email/notification content generated for panel tickets (no mail was sent).
- `install-linux.sh` was read, syntax-checked and its cleanup logic reviewed; it was not run (it requires root and installs a service).
- Screen-reader testing (no assistive technology available); reduced-motion behaviour was checked from the stylesheets and scripts, not by emulating the preference.
- A non-admin user who has RMM access: the demo technician role cannot open the RMM device page (403), so the non-admin view of the Mesh alert was checked from the markup code path only.
- Authenticated pages on real beta, RMM with real agents, and any data volume effects.

## 7. Evidence and scripts

Evidence (`tests/qa/audit/evidence/`, downscaled JPG, no secrets): `mesh_*_full.jpg`, `panel_token_full.jpg`, `panel_deploy_*_1280.jpg`, `wz_*`, `er_*`, `wg_*`, `daterange_open_*`, `icon_picker_*`, `global_search_focus.jpg`, page shots `{page}_{light|dark}_1280.jpg`. Raw results: `out/ux_findings.json`, `out/widgets.json`, `out/panel_api.txt`, `out/panel_extras.txt`.

Re-run (scratch only; the scripts refuse nothing, so do not point `AUD_BASE` at a live host): `python3 cleanup.py`, `python3 panel_api.py`, `python3 panel_extras.py`, `python3 fixtures.py > out/fixtures.json`, `python3 mesh_test.py`, `php redaction_vectors.php`, `python3 change_redaction_e2e.py`, `python3 modal_perm_test.py`, `python3 ux_audit.py`, `python3 widgets_audit.py`, `python3 flows_shots.py`; for the webhook capture start `webhook_capture_server.py` (127.0.0.1:8997), start the scratch server with `RIVETIT_WEBHOOK_ALLOW_PRIVATE=1`, run `python3 webhook_capture_setup.py`, then `change_redaction_e2e.py` and read `/tmp/claude-1000/webhook_capture.jsonl`. They need the scratch app on 127.0.0.1:8196 with a throwaway Redis on 6391 (`RIVETIT_REDIS_HOST/PORT/ENV_FILE` set for the server), the endpoint-agent switches on, and a generated signing key; see section 0.
