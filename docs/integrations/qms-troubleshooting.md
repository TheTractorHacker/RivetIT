# QMS integration: troubleshooting

Start at Administration > Quality: **Sync health** says what is wrong in words, and the **Integration error log** has the details. Every outbound call has a correlation id; the same id is in the QMS's log and in what a person sees on the "not available" page as a reference.

| Symptom | Likely cause and what to do |
|---|---|
| No Quality tab for anyone | The module is off; or the person's department is not ticked; or no feature is switched on; or the person is a kiosk/preview session. Check the three on the admin page. |
| "The quality system is not available" on a page | The QMS did not answer in time, refused the token, or returned an error. Open the error log: `timeout`, `transport_error` (name/connection/TLS), `auth_failed` (client id/secret, or the QMS rejected the token), `forbidden` (the QMS refused the person), `server_error`. Use the reference on the page to find the line. |
| Test connection: "That address is not allowed" | The address failed the checks (https only; public address unless the network is listed under Webhooks > Allowed internal networks; no credentials, query or fragment in it). |
| Test connection: "refused the credentials" | Wrong client id/secret, or the QMS client is not allowed `quality:read quality:write`. Re-enter the secret (it is never shown). |
| Test connection: "answered HTTP 403 (user_context_required)" on a page, not on the test | The QMS did not accept the signed user context. Check that it fetches RivetIT's JWKS (`/.well-known/openid-configuration`), expects `typ` `rivetit-user-context+jwt`, and that its audience equals the address saved here exactly. |
| Health: "Events are on but no outbound signing secret is set" | Create the secret and enter it in the QMS. |
| Events stay "pending" | The QMS is failing or slow; they retry (1 min up to 24 h). Look at *Last error*. Nothing is lost. |
| Events "dead" | The QMS refused them (HTTP 4xx) or they failed 8 times. Fix the cause, then **Replay**. |
| Inbound events fail with 401 `invalid_signature` | Wrong inbound secret, a body changed in transit, or a clock more than 5 minutes off. Check NTP on both servers. |
| Inbound 403 `insufficient_scope` | The QMS's API client lacks `quality:events`. |
| Inbound 503 `module_disabled` | Quality is off, or *Accept events from the QMS* is off. |
| Inbound 422 `event_rejected` | The Odoo id is shared by two people (send `rivetit_id`), unknown, or the QMS id is already mapped to someone else. |
| The QMS reports people it cannot match | Matching is by Odoo employee id or RivetIT id only. Check Odoo directory sync, and `qms.employee.mapped` events. |
| Sync health: "The sync has not run" | The cron job is not running as `www-data`, or `/var/log/...` for it is missing (a cron job silently stops if its log file cannot be opened). Run `php cron/quality_worker.php --force` as `www-data` and read the line it prints. |
| Acknowledge button greyed out | The sign-in is older than the re-sign-in age and the login has no local password (single sign-on login): sign out and in again. |
| "Too many wrong passwords" | Five wrong passwords in one sign-in; sign out and in again. |
| Kiosk has no Quality link | The *Kiosk* feature is off, or the person's department is not enabled. |

## Commands

```
sudo -u www-data php cron/quality_worker.php --force            # one pass now, prints a summary line
sudo -u www-data php cron/quality_worker.php --force --full     # also queue a full resync
```

The line looks like `ok queued=3 sent=3 retry=0 dead=0 health=ok`. A silent exit means the module is off, not configured, or not due.

## What is safe to do

- Switching the module off and on is safe: nothing is deleted.
- Replaying dead events is safe: events have deterministic ids and the QMS dedupes on them.
- A full resync is safe but can send many events.
- Never paste a secret, token or signature into a ticket; the error log has none, and the correlation id is enough.
