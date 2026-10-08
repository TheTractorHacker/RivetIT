# Slack and Microsoft Teams notifications

RivetIT can post ticket and platform events into a Slack channel or a Microsoft Teams channel. It uses the same
**Administration > Webhooks** page, the same event list and the same job queue (with the same retry schedule) as every other
webhook; you choose the **Slack** or **Microsoft Teams** platform card in Add Webhook instead of a generic one.

Status: tested against local mock servers only (the interactive buttons too). It has not been run against a real Slack workspace or Teams tenant.

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

## Not included

- **Teams interactive cards.** Buttons that call back into RivetIT need a Microsoft **Bot Framework / Azure Bot registration** (a bot identity, its
  messaging endpoint, token validation); a Workflows webhook cannot receive clicks. That is a separate product to build and operate and is **not**
  done. Teams cards have an **Open ticket** link only.
- **Two-way sync** (replying in Slack or Teams to add a ticket reply, or mirroring channel conversation) and a **virtual agent / chat bot** are not included.
- Slash commands and Slack modals/shortcuts are not included.
- Teams messages go one way, from RivetIT to the channel. Slack rate limits (HTTP 429) are handled by the normal retry schedule.

## Tests

`tests/slack_interactive.php` (signature, replay, buttons, user mapping, the real endpoint), `tests/chat_webhooks.php` (formatter, routing, URL vetting, delivery and the event bus against `tests/mock/slack_webhook.php`) and
`tests/e2e/chat_webhooks.py` (the real web pages). Both need a scratch database; see the header of each file.
