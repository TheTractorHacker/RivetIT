# QMS integration: configuration (Administration > Quality)

Everything is **off** until you switch it on. The pages, the event endpoint, the scheduled sync and the sign-in to the QMS are all behind one switch, and nothing is sent until you have set a QMS address, credentials and signing secrets. The contract the QMS must implement is `docs/QUALITY_CONTRACT.md`; the design is [qms-overview.md](qms-overview.md).

## Before you start

1. Take the database update (DB 2.6.261 and 2.6.262): Administration > Update > Update Database. It only adds tables and a column; nothing is switched on.
2. The department portal must be on (Settings > Portal), people must have portal logins, and the Training module must be on if you want assignments and completions to flow.
3. Ask the QMS team for: its address, a client id and secret for RivetIT (the QMS token endpoint), and where to enter RivetIT's verification and signing secrets.
4. If the QMS is on a private network, list that network under Administration > Webhooks > Allowed internal networks. Loopback, link-local and cloud-metadata addresses are never allowed.
5. Single sign-on to the QMS is configured separately on Administration > Single sign-on provider (see [qms-sso.md](qms-sso.md)).

## Steps (Administration > Quality)

1. **Connection to the QMS.** QMS address (`https://quality.example.com`, optionally with a path), optional token address (same host only), client id, client secret, scope. Save, then **Test connection**. The test fetches a token and calls `GET /integrations/health`; the result and the time it took are kept. The secret is stored sealed and never shown again; leaving the field blank keeps it.
2. **Signing secrets.** Create the *outbound* secret (RivetIT to QMS) and the *inbound* secret (QMS to RivetIT). Each is shown once, immediately after it is created; copy it into the QMS. Creating a new one replaces the old one at once.
3. **Departments.** Tick the departments whose people may use Quality (or "All departments"). A person's department is the one on their contact record. Removing a department removes access at the next request.
4. **Features.** Switch on only what you need: My tasks, Documents to acknowledge, Required training, Corrective actions, Notifications, History, Department reporting (managers), Kiosk. A feature that is off does not appear and is refused on the server.
5. **Module and schedule.** Turn **Quality is on** on. Optionally turn on *Send events to the QMS* (needs the outbound secret) and *Accept events from the QMS* (needs the inbound secret and an API client, below). Defaults: sync every 5 minutes, reconcile every 24 hours, re-sign-in age 15 minutes, timeouts 3 s connect and 10 s total.
6. **API client for the QMS.** Administration > API Keys: create a client for the QMS with the scopes it needs (`lms:read lms:assign lms:completions:read lms:evidence:read directory:read quality:events`) and the departments it may see. The QMS uses it to call RivetIT (assign training, read completions, read the directory, post events).
7. **Schedule.** The sync is `cron/quality_worker.php` (every 5 minutes; installed by `deploy/install.sh`, runs as `www-data`). While the module is off it exits at once.

Switching **Quality is on** off (here or under Settings > Modules) hides the portal pages and the kiosk link, makes the event endpoint answer `503 module_disabled`, stops the sync and refuses single sign-on to the QMS. Settings, secrets, mappings, events and the log are kept, and Training is not touched.

## What people see

- Department portal: a **Quality** tab (tiles on the home page) with the sections you switched on, a department view for managers (Primary or technical contact, or portal role Manager), and an **Open the quality system** button (single sign-on). Items are read live from the QMS for that person on every page view and are shown with the time they were read. When the QMS cannot be reached the page says so, with a reference to give an administrator; it never shows older data as if it were current.
- Acknowledging a document or answering a corrective action asks for the person's password again unless they signed in within the re-sign-in age. Five wrong passwords stop further attempts for that sign-in.
- Kiosk (a shared screen): counts, the person's required training and the titles of documents waiting for acknowledgment, which need the PIN. Corrective actions and other quality records are never shown on a kiosk.

## Sync health and logs (same page)

- **Sync health**: ok, degraded or failing, with the reasons (last QMS check, events waiting and how long the oldest has waited, dead-lettered events, per-stream position and last problem, inbound failures).
- **Run sync now** does one pass immediately; **Queue full resync** queues one event per record that is in scope (use after connecting a new QMS; it can be large).
- **Events**: the latest outbound events with status and attempts, **Replay** for a dead-lettered one, and the latest inbound events.
- **Integration error log**: area, code, HTTP status, message and correlation id (the same id the QMS logs). No secrets, tokens or personal data. Kept 30 days.

## Go-live checklist

- [ ] Test connection passes; Sync health shows "Connected and in sync".
- [ ] A test person in an enabled department sees their own items and no one else's; a person in another department sees no Quality link.
- [ ] The QMS rejects a request with a valid service token but no user context (`403 user_context_required`).
- [ ] An event sent by RivetIT arrives signed and the QMS verifies it; an event the QMS sends arrives (inbound list shows `processed`).
- [ ] Switching the module off hides the tab, the event endpoint answers 503, and the QMS can still read the LMS API.
- [ ] Single sign-on: "Open the quality system" lands a portal user in the QMS already signed in; an administrator and a person of a disabled department are refused with a plain message and an audit row.

Anything that needs the real QMS (the first three events, the two-way signature check, the user-context verification on its side, the sign-in) cannot be proven from RivetIT's side alone; run these on a test QMS first.
