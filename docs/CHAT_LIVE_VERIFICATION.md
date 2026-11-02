# Slack and Teams chat: live verification checklist

Everything in issue #36 was built and tested against local mocks and locally signed requests (`tests/chat_integration.php`, `tests/chat_teams.php`).
**Nothing has talked to a real Slack workspace, a real Azure Bot or a Teams tenant.** Use a test workspace/tenant and a test department. Tick each item; note failures.

## 0. Before you start
- [ ] Run **Update database** (DB 2.6.229). Open Administration > Settings > Chat: it shows the four URLs and every switch OFF.
- [ ] Base URL is the public https address (the manifest refuses http).
- [ ] The web server forwards `/slack_events.php`, `/slack_commands.php`, `/slack_interactive.php`, `/teams_bot.php` like `comet_webhook.php` (root-level PHP files; check the live nginx rules allow them).

## 1. Slack
- [ ] Download the manifest, create the app from it, install it. Slack accepts the manifest without errors (scopes, shortcuts, commands).
- [ ] Paste Signing Secret and Bot Token on the Chat page, set the workspace (T...) and channel (C...) IDs. Save.
- [ ] Slack > Event Subscriptions: the Request URL shows **Verified** (url_verification round trip).
- [ ] Add your Slack member ID under Identity links, linked to your agent.
- [ ] Switch on *Slash command*: `/ticket help`, `/ticket new Test from Slack` (ticket appears in the default client), `/ticket status <number>`, `/ticket new` (form opens), the "New ticket" shortcut, "Create ticket from message" (pre-filled).
- [ ] Switch on *Two-way*: create a ticket in the web app, it appears as a thread in the channel; reply as an agent in the web app (public) and see it in the thread; reply in the thread and see an **internal note** on the ticket; no echo of that note back into the thread.
- [ ] Unlinked Slack user replies in a thread: they are told they are not linked, nothing changes.
- [ ] Switch on *Actions*: ticket messages from a Slack destination with a signing secret show status / priority / Reply; each works; Closed is not offered. Create a catalog request needing your approval: you get a DM with Approve / Deny; Approve releases the ticket; a second click does nothing.
- [ ] Switch on *Virtual agent*: DM the bot "vpn" (KB + catalog results), "ticket my screen flickers", "status <n>", mention it in a channel.
- [ ] Negative: a request with a wrong signature (curl) gets 401; a request older than 5 minutes gets 401.
- [ ] Slack returns "dispatch_failed" / timeouts never seen (answers must come within 3 s).

## 2. Teams (UNVERIFIED LIVE: most likely place for surprises)
- [ ] Azure: create the Azure Bot (single tenant), client secret, messaging endpoint `https://<host>/teams_bot.php`, enable Teams channel.
- [ ] Enter App ID, tenant ID and secret; switch the bot on; download the package and upload it in Teams (it must be accepted: manifest v1.17, icons).
- [ ] Add your Entra object ID under Identity links. Message the bot "help", "new Test from Teams", "status <n>".
- [ ] Check the JWT really validates: the `iss` / `aud` / `serviceurl` claims match what the code expects; if real tokens are rejected, check `error_log` (the reason code is not shown to callers) and the key set URL (`login.botframework.com/v1/.well-known/openidconfiguration`).
- [ ] Reply delivery: the bot's answers appear (token request for scope `https://api.botframework.com/.default`, then the connector `v3/conversations/{id}/activities`). Confirm your service URL host passes the allow-list (smba.trafficmanager.net etc.); add the host in `TeamsConnector::SERVICE_HOST_SUFFIXES` if a sovereign cloud uses another.
- [ ] Approval card: after you have messaged the bot once, a catalog approval arrives as a card; Approve / Deny work.
- [ ] Optional email fallback (needs tenant ID set): confirm the roster lookup `GET .../members/{aadObjectId}` returns the directory email; otherwise keep using explicit links.
- [ ] Ticket made in Teams keeps mirroring public replies into that conversation (two-way on).
- [ ] Negative: POST to `/teams_bot.php` without or with a bogus token gets 401.

## 3. Cleanup / rollback
- [ ] Turn every switch off on the Chat page: all endpoints stop acting immediately. Remove the Slack app / Azure Bot if abandoned.
