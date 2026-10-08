# QA report - web (2026-10-06)

Targets: RivetIT demo http://127.0.0.1:8080 (26.10.21, DB 2.6.137, built from `beta` 76f5f0b50), Rivet MSP demo https://10.1.0.45:8445 (26.10.4, DB 2.6.72, rivetmsp-beta 0efb509c0). Chromium (all flows), Firefox 151 (ticket create + lifecycle). Android: see the Android section if present.

## Coverage (executed)
- Login (valid / wrong / empty / unknown / SQLi-like / unicode / 300-char), throttling, logout, back button, expired session, direct navigation: both editions.
- Crawl of 90 agent/admin pages per edition at 1366, 768, 390 px: PHP errors, failed requests, console, horizontal overflow, load time.
- Tickets: create (validation, unicode, markup), internal/public notes, On Hold, Open, Resolved (closes), Reopen: both editions, Chromium + Firefox.
- Users: create technician, duplicate email, role isolation, technician privilege-escalation POST, disable (live session killed, login blocked).
- Client portal: RivetIT (sophie.tran) crawl + 20-ticket IDOR probe; MSP (QA portal contact) crawl, IDOR probe, portal ticket create. Both editions: portal ticket create.
- Passkeys (CDP virtual authenticator): enroll, passkey sign-in, last-used, delete: both editions.
- Client CRUD (validation, search, archive), asset create/validate/details: both editions.
- Attachment upload matrix (16 cases): both editions.
- axe-core accessibility scan of 10 pages per edition.
- Unauthenticated probes of sensitive paths on the beta vhost.

## Defects
IDs: S = shared, IT = RivetIT only, MSP = Rivet MSP only.

| ID | Sev | Scope | Title |
|---|---|---|---|
| QA-WEB-001 | Medium | S | Duplicate user e-mail accepted by Admin > Users (two active accounts, same login e-mail), no error shown |
| QA-WEB-002 | Medium | S | Each open ticket page pins a web worker via `sse_ticket_stream.php`; closed connections are only noticed after the 15 s heartbeat, so ~8 tab loads starve an 8-worker server (reproduced: page loads time out) |
| QA-WEB-003 | Medium | S | Beta vhost serves `/db.sql` (schema dump, 339 KB), `/docker-compose.yml`, `/composer.json`, `/vendor/composer/installed.json` unauthenticated |
| QA-WEB-004 | Medium | MSP | `agent/csat.php` inline script has no CSP nonce: blocked by CSP, public-approval toggle cannot send its request |
| QA-WEB-005 | Low | MSP | Client portal pages log CSP inline-script violations (default-src 'self') |
| QA-WEB-006 | Low | S | Blank / whitespace-only client name accepted (nameless client created) |
| QA-WEB-007 | Low | S | Asset form accepts invalid IP (999.1.1.1) and MAC (ZZ:ZZ) |
| QA-WEB-008 | Low | IT | HTTP 500 + blank page: oversize client name (Data too long), `clients.php?page=-5` (negative LIMIT), `asset_details.php?asset_id=<missing>` |
| QA-WEB-009 | Low | S | Required `client_id` (TomSelect) in New Ticket / client modals gives no visible validation message ("invalid form control is not focusable") |
| QA-WEB-010 | Low | MSP | PHP warning `Undefined variable $client_id` in `agent/contacts.php:93`; IT/MSP `ticket.php` warnings for missing `$ticket_id` |
| QA-WEB-011 | Low | MSP | Tickets filter bar overflows viewport (16 px at 1366; 17 pages at 768; 21 at 390) |
| QA-WEB-012 | Low | IT | RMM/Intune pages, Admin > Users and contact details overflow at 768 / 390 px |
| QA-WEB-013 | Low | S | Upload validation is extension-only (HTML content accepted as `.pdf`; empty file accepted); rejected types give no inline feedback in the POST response |
| QA-A11Y-001 | High | S | axe: 76 (IT) / 54 (MSP) icon buttons without accessible name, 49 / 42 form controls without labels, 18 / 16 selects without names; passkey delete button unnamed |
| QA-A11Y-002 | Medium | S | axe: colour contrast (65 / 45 nodes), no `main` landmark, no `h1` on 9-10 of 10 pages, link-name (9 / 10) |
| QA-WEB-014 | Cosmetic | S | Calendar page: font data-URI blocked by CSP |
| INFO | | S | Choosing "Resolved" in a reply closes the ticket (status 4 -> 5); no persistent Resolved state |

## Passed
Login/throttle/logout/back/expiry, ticket lifecycle, technician isolation + escalation blocked, disable kills session, portal IDOR (no foreign ticket visible), passkey end-to-end, CSRF-protected admin links, security headers (CSP, XFO, nosniff, referrer-policy), no PHP errors or failed requests across 90-page crawls, uploads dir blocked on the beta vhost, `.php`/`.exe`/`.svg`/`.html` uploads rejected.

## Notes / limitations
- `:8444` is proxied to another session's scratch instance, not the RivetIT demo; flows use :8080 directly.
- MSP passkeys need an RP ID that is a hostname: the demo base URL is an IP, so the test temporarily set it to `localhost:8081` and restored it.
- No Hudu-style role beyond Administrator/Technician/portal exists in the demos; department-user, read-only and kiosk accounts were not exercised.
- Page timings include a 0.5 s settle wait: real loads were about 0.2-0.7 s.

# Android (emulator RivetIT_QA_API36, Android 36; Maestro 2.11.0 + adb)
RivetIT `com.foleyit.itflow.internal.beta` 0.8.9-beta and MSP `com.foleyit.itflow.beta` 1.31.0-beta built (incremental), installed, logged in, navigated, created a ticket. RivetIT was reached through a throwaway TLS proxy (app rejects http://).

| ID | Sev | Title |
|---|---|---|
| RIVETMSP-ANDROID-001 | High | Clients tab crashes (`NoSuchElementException` at `ClientsScreen.kt:58`, `c.name.first()`) when any client name is empty. Trigger was a blank-name client created by QA-WEB-006. 3/3 |
| RIVETIT-ANDROID-001 | High | POST_NOTIFICATIONS is never requested (both apps): push cannot display |
| RIVETIT-ANDROID-002 | Medium | Back with the drawer open exits the app (both apps) |
| RIVETIT-ANDROID-003 | Medium | Create Ticket validation / offline errors appear below the visible form |
| RIVETIT-ANDROID-004 | Medium | "Trust & Connect" hidden behind the keyboard in the cert dialog |
| RIVETIT-ANDROID-005..009 | Low | Offline wording ("server ran into a problem"), dead camera "Grant Permission" after permanent deny, misleading "https://" message, no sign-out confirmation / silent empty login, relaunch resets to Home |
| RIVETMSP-ANDROID-002..005 | Low/Cosmetic | Create ticket returns Home, extra top gap, Projects/Appts "Something went wrong" (possibly demo data), shared cert-dialog/camera issues |

Not tested: notification allow/deny (never requested), biometrics/passkey, MSP screens behind Clients, Live Chat/timer/attachments, visual masking and themes (FLAG_SECURE blocks screenshots), TalkBack, tablet.
Maestro flows: tests/qa/maestro/ (login + navigation pass for RivetIT, login passes for MSP; MSP navigation fails at Clients; ticket flows partly fail).
Cold start: RivetIT 2.7-3.2 s, MSP 2.9-3.2 s (emulator).

# Release decision
FAIL for MSP Android (reproducible crash on a blank-named client, which the server accepts); PASS WITH KNOWN ISSUES for RivetIT web, MSP web and RivetIT Android.
