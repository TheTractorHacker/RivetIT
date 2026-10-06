# Slack and Microsoft Teams notifications

RivetIT can post ticket and platform events into a Slack channel or a Microsoft Teams channel. It uses the same
**Administration > Webhooks** page, the same event list and the same job queue (with the same retry schedule) as every other
webhook; a destination just has a **Destination type** of Slack or Teams instead of Generic.

Status: tested against local mock servers only. It has not been run against a real Slack workspace or Teams tenant.

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
4. In RivetIT: Administration > Webhooks > **Add Webhook**, Destination type **Slack**, paste the URL, tick the events, Save,
   then edit it and press **Send test message**.

## Microsoft Teams

The classic "Incoming Webhook" Office 365 connector is retired. RivetIT sends the format the replacement uses: a `message` with an
**Adaptive Card 1.4** attachment, to a **Workflows (Power Automate) webhook**.

1. In Teams, open the channel > **...** > **Workflows** > the template **Post to a channel when a webhook request is received**.
2. Name it, pick the team and channel, create it, and copy the **HTTP POST URL** it shows.
3. In RivetIT: Add Webhook, Destination type **Microsoft Teams**, paste the URL, tick the events, Save, then **Send test message**.

Workflows answer HTTP 202 when they accept a message; RivetIT counts any 2xx as delivered. A 202 only means the workflow accepted
it: if the card does not appear, check the workflow's run history in Power Automate.

## Security

- The URL is a secret (anyone holding it can post to your channel). It is stored encrypted, is never shown again (the edit dialog
  and the list show nothing or only the host), and is never written to logs or error messages. To change it, paste a new one.
- Only `https://` URLs to public addresses are accepted. Private, loopback, link-local (including cloud metadata) and reserved
  addresses are rejected when you save **and** again at send time; the connection is pinned to the vetted address, redirects are
  not followed and TLS is verified.
- Chat destinations carry no signing secret (Slack and Teams do not verify one).

## Not included

Interactive buttons (acknowledge, assign from Slack), slash commands and Slack signing-secret / Teams bot endpoints are out of
scope. Messages go one way, from RivetIT to the channel. Slack rate limits (HTTP 429) are handled by the normal retry schedule.

## Tests

`tests/chat_webhooks.php` (formatter, routing, URL vetting, delivery and the event bus against `tests/mock/slack_webhook.php`) and
`tests/e2e/chat_webhooks.py` (the real web pages). Both need a scratch database; see the header of each file.
