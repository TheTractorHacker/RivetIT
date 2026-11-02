# Slack and Microsoft Teams notifications

RivetIT can post ticket and platform events into a Slack channel or a Microsoft Teams channel. It uses the same
**Administration > Webhooks** page, the same event list and the same job queue (with the same retry schedule) as every other
webhook; you choose the **Slack** or **Microsoft Teams** platform card in Add Webhook instead of a generic one.

Status: tested against local mock servers only (webhooks, buttons, Events API, commands, modals, Teams bot). It has not been run against a real Slack workspace or Teams tenant. Work through `docs/CHAT_LIVE_VERIFICATION.md` before relying on any of it.

## What it does

- Sends a formatted message: title (ticket number and subject), priority, client, status, assignee and an **Open ticket** button.
  Platform events (audit trail events) send the event name, the summary line, the action and the entity type, never the raw metadata.
- Routing per destination: the events it subscribes to, an optional **minimum ticket priority** (Low, Medium, High, Critical) and an
  optional list of **clients** (none selected = all clients). Priority and client filters apply to ticket events only.
- **Send test message** (edit dialog) posts a clearly labelled test message and shows the HTTP result. It never shows the URL.
- Every attempt, including retries and tests, appears in the Deliveries list with a Slack or Teams badge.

User text (ticket subjects, client names) is never treated as markup or as a mention: Slack gets plain text only (and
`<!channel>`, `@here`, `@channel` are neutralised); Teams text has its Markdown characters escaped.

## Slack

1. Go to https://api.slack.com/apps, **Create New App** > From scratch, pick the workspace.
2. **Incoming Webhooks** > turn on > **Add New Webhook to Workspace** > choose the channel.
3. Copy the webhook URL (it starts `https://hooks.slack.com/services/...`).
4. In RivetIT: Administration > Webhooks > **Add Webhook**, choose the **Slack** card, paste the URL, tick the events, Save,
   then press **Send test** (in the form, or the paper-plane button in the list).

## Microsoft Teams

The classic "Incoming Webhook" Office 365 connector is retired. RivetIT sends the format the replacement uses: a `message` with an
**Adaptive Card 1.4** attachment, to a **Workflows (Power Automate) webhook**.

1. In Teams, open the channel > **...** > **Workflows** > the template **Post to a channel when a webhook request is received**.
2. Name it, pick the team and channel, create it, and copy the **HTTP POST URL** it shows.
3. In RivetIT: Add Webhook, choose the **Microsoft Teams** card, paste the URL, tick the events, Save, then press **Send test**.

Workflows answer HTTP 202 when they accept a message; RivetIT counts any 2xx as delivered. A 202 only means the workflow accepted
it: if the card does not appear, check the workflow's run history in Power Automate.

## Slack interactive buttons (optional)

A Slack destination that has a **Signing Secret** gets two buttons on ticket messages, next to **Open ticket**:

- **Acknowledge** adds an internal note ("Acknowledged in Slack by ...") to the ticket.
- **Assign to me** assigns the ticket to the person who clicked (New becomes Open) and adds an internal note.

Setup (after the notification setup above):

1. In your Slack app: **Basic Information > App Credentials > Signing Secret**: copy it into the Slack destination's **Secret** field in RivetIT
   (Edit webhook). It is stored encrypted and never shown again. Until it is set the message has no buttons.
2. In the Slack app: **Interactivity & Shortcuts** > turn on > **Request URL** = `https://<your host>/slack_interactive.php` (Administration >
   Webhooks > Slack interactive actions shows the exact URL). This is an ordinary root-level PHP file, like `comet_webhook.php`: **no nginx rule is needed**.
   It must be reachable from the internet (Slack calls it), so it authenticates every request itself (below).
3. Decide whether Slack users may act. **Off by default**: while off, a click is answered (privately, only to the clicker) "your Slack account isn't
   linked" and nothing changes. To turn it on (Administration > Webhooks > Slack interactive actions): tick **Match Slack users to agents by their confirmed Slack email
   address**, paste a **bot token** (`xoxb-...`, scopes `users:read` and `users:read.email`; stored encrypted) and, strongly recommended, your **workspace (team) ID**.

How a click is trusted, in order (any failure stops it with no change):

1. **Signature.** `X-Slack-Signature` is `v0=` + HMAC-SHA256 of `v0:<timestamp>:<raw body>` with the Signing Secret, compared in constant time; the
   timestamp must be within 5 minutes. A request that does not verify against an enabled Slack destination's secret gets HTTP 401 and no detail.
2. **Single use.** Each accepted signature is remembered for 10 minutes (table `slack_interactive_seen`); a replay does nothing.
3. **Who clicked.** Only the signed Slack user id is used. RivetIT asks Slack (`users.info`) for that user and acts only if Slack says the email is
   **confirmed**, the person is not a bot, deleted or guest, belongs to the pinned workspace, and **exactly one active agent** has that email
   (a portal login, a disabled or archived agent, or two agents sharing the address never match). A name, username or email inside the request is never believed.
4. **Rights.** The agent needs the same rights as in the web app: administrator, or write access to the support module, and access to that department.
   The destination's own client filter also applies, and closed tickets are never touched.

Every action is audited (`ticket.slack_acknowledged`, `ticket.slack_assigned`) with the agent as actor. Payloads, tokens and the signing secret are never logged.
The reply to the clicker is ephemeral (posted to Slack's `response_url`, which must be a Slack host).

**Risk to understand before enabling email matching:** Slack identity is only as strong as the workspace. If members can set any email address
without Slack confirming it, a member could claim an agent's address; RivetIT requires Slack's `is_email_confirmed`, but you should still enable this only for a workspace you control.

Tested against local mocks only (`tests/slack_interactive.php`, including a real HTTP run of the endpoint); never against a real Slack workspace.

## Security

- The URL is a secret (anyone holding it can post to your channel). It is stored encrypted, is never shown again (the edit dialog
  and the list show nothing or only the host), and is never written to logs or error messages. To change it, paste a new one.
- Only `https://` URLs to public addresses are accepted. Private, loopback, link-local (including cloud metadata) and reserved
  addresses are rejected when you save **and** again at send time; the connection is pinned to the vetted address, redirects are
  not followed and TLS is verified.
- Outgoing messages are not signed (Slack and Teams do not verify one). The optional Slack Signing Secret is for verifying Slack's *interactive requests to RivetIT*, see above.

## Two-way chat, commands, actions, virtual agent (Administration > Chat)

Everything below is **off by default** and configured on **Administration > Settings > Chat**, which also shows the exact URLs and builds the app files for you.

1. **Create the Slack app from a manifest.** On the Chat page press *Download manifest*, then api.slack.com/apps > Create New App > *From an app manifest* > paste. It sets the interactivity, events and `/ticket` URLs and the scopes. Install the app, then copy the **Signing Secret** and the **Bot User OAuth Token** into the Chat page.
2. **Link people.** Under *Identity links* enter a Slack member ID (profile > three dots > Copy member ID) and pick the agent (or a contact's email). A link always wins. Optionally turn on the confirmed-email fallback (only for a workspace you control).
3. **Turn on what you want.**
   - *Two-way ticket threads*: needs the channel ID. New tickets are posted there as a thread, public and customer replies follow, and a linked agent's reply in the thread becomes an internal note (or a public reply: no email is sent from chat). Internal notes are mirrored only if you also tick that.
   - *Slash command, form and shortcuts*: `/ticket new [client=<id>] [priority=High] <subject>` (no subject opens a form), `/ticket status <ticket>`, the "New ticket" global shortcut and "Create ticket from message".
   - *Actions from chat*: status, priority and Reply on ticket messages (needs a signing secret on the destination), `/ticket set|reply|approvals`, and Approve / Deny direct messages to approvers who linked chat. Closing a ticket is never offered; an administrator cannot override an approval from chat.
   - *Virtual agent*: DM the bot or mention it; it searches the knowledge base and service catalog and can open a ticket or request a catalog item without required questions.
4. Rights are the person's own: support-module write access and department access, as in the web app. A contact (portal user) can only raise and follow their own tickets and see client-visible articles.

## Microsoft Teams bot (UNVERIFIED LIVE)

Needs an Azure Bot registration: create an *Azure Bot* (single tenant), copy its App ID and a client secret, set its messaging endpoint to `https://<host>/teams_bot.php`, enable the Teams channel. Enter App ID, tenant ID and secret on the Chat page, switch the bot on, download the **Teams app package**, and upload it in Teams. Every request must carry a valid Bot Framework token for your App ID; other tenants are ignored when the tenant ID is set. The bot answers the same commands as text (`new ...`, `status ...`, `set ...`, `approvals`), shows Adaptive Cards with Approve / Deny, and a ticket made in a conversation keeps mirroring replies back into it when two-way is on. Proactive messages (approval requests) only reach people who have messaged the bot once.

## Not included

- Teams channel-wide conversation sync (a reply typed in a channel thread is not matched to a ticket; use `reply <ticket> <text>`) and Teams card actions on webhook (Workflows) messages.
- Emailing the customer from a chat reply; closing / resolving from chat; administrator override of approvals from chat; contact (manager) approvers receive no chat prompt.
- Everything above has only been exercised against mocks.

## Tests

`tests/slack_interactive.php` (signature, replay, buttons, user mapping, the real endpoint), `tests/chat_webhooks.php` (formatter, routing, URL vetting, delivery and the event bus against `tests/mock/slack_webhook.php`) and
`tests/e2e/chat_webhooks.py` (the real web pages), `tests/chat_integration.php` (Slack: signed requests, events, commands, interactivity, identity, rights, threads, virtual agent, manifest, real endpoints) and `tests/chat_teams.php` (Teams: JWT against a mocked JWKS, connector, bot, package, real endpoint). All need a scratch database; see the header of each file.
