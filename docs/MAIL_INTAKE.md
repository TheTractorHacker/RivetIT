# Mail intake runbook

How RivetIT turns email into tickets and replies, how it protects itself from mail loops and bad messages, how to connect Microsoft 365, Google Workspace or plain IMAP mailboxes, and what to do when an alert fires.

Scripts: `cron/ticket_email_parser.php` (reads mailboxes), `cron/mail_queue.php` (sends email), `cron/cron.php` (housekeeping and standing health checks).
Code: `src/Mail/` (namespace `ITFlow\Mail`). Admin pages: **Admin > Ticketing > Mailboxes**, **Mail requests**, **Maintenance > Email Log**, **Maintenance > Mail Queue**.
Database: 2.6.152.

## 1. What happens to an inbound message

The poller runs every five minutes per active mailbox. For each unread message it decides, in this order:

| Step | Check | Result |
|---|---|---|
| 0 | Already quarantined by an earlier failure | Skipped, marked read |
| 1 | **Message-ID already imported** (ticket, reply or mail request, including dismissed ones) | `duplicate` in the Email Log, message moved out of the inbox |
| 2 | **Machine-generated mail** (below) | `suppressed` in the Email Log. Never a ticket, never a reply, never an answer. Bounces notify an admin and leave a system note on the original ticket |
| 3 | **Per-sender hourly cap** | Held as a **mail request** with reason *Rate limited*; one alert per sender |
| 4 | **Threading by `In-Reply-To` / `References`** against Message-IDs we stored (inbound tickets, replies, mail requests) and ids we generated for outbound mail | Reply added to that ticket |
| 5 | `[PREFIX-123]` token in the subject | Reply added to that ticket |
| 6 | Fuzzy subject match (95 %, same client, open, last 7 days) | Reply added |
| 7 | Known contact / known domain | New ticket |
| 8 | Unknown sender | Mail request (if the mailbox queues unknown senders), otherwise left flagged |

Message-ID threading comes before the subject token on purpose: a customer who edits the subject, or whose mail client drops the `[TCK-123]` token, still lands on the right ticket. The subject token stays as the fallback.

If the sender of a threaded or tagged reply is not the ticket's contact (or another contact of that department), the message is queued as a mail request (reason *Not the ticket contact*) rather than added to the ticket. It is queued **once**: it is deduplicated by Message-ID and moved out of the inbox, so it no longer re-queues on every poll.

### Mail that is suppressed

Any one of these marks a message as machine generated:

- `Auto-Submitted:` present and not `no` (RFC 3834: `auto-generated`, `auto-replied`, `auto-notified`)
- `Precedence:` `bulk`, `junk`, `list` or `auto_reply`
- `X-Auto-Response-Suppress:` containing `All`, `OOF` or `AutoReply` (`DR`, `RN`, `NRN` alone are about receipts and do not count)
- `X-Autoreply` / `X-Autorespond` / `X-Auto-Reply`
- `Return-Path: <>` (null sender)
- `X-RivetIT-Auto:` (RivetIT's own mail coming back: a loop)
- a subject that starts like an auto-responder (after any `Re:`/`AW:`/`SV:`): "Automatic reply", "Out of office", "Abwesenheit", "Réponse automatique", "Respuesta automática", "Risposta automatica", "Automatisch antwoord", "Autosvar", ...
- a delivery status notification: `multipart/report`, a `message/delivery-status` part, or a bounce-style subject ("Undeliverable", "Mail delivery failed", ...) - these are classed as **bounces** and shown as *Bounce (NDR)* rather than *Suppressed*

Suppression is a log entry only. Find it under **Maintenance > Email Log**, filter *Suppressed (auto-reply)*; the detail column names the rule that fired. If a real person's mail is wrongly suppressed (for example a mail system that stamps `Precedence: bulk` on everything), ask the sender to resend from another route, or open the original in the mailbox's `ITFlow` folder; the message is not deleted.

### Quoted history is removed from replies

On a reply, the text above the quote is kept and the quoted conversation is dropped: `On ... wrote:` (and German `schrieb`, French `a écrit`, Spanish `escribió`, Italian, Portuguese, Dutch, Swedish forms, including lines wrapped over several lines), `-----Original Message-----`, `From:/Sent:/To:/Subject:` header blocks (with the Outlook underscore rule above them), `>` quoted lines, and the Gmail / Outlook / Apple Mail / Thunderbird HTML quote containers. The existing `##- Please type your reply above this line -##` marker still wins. The plain-text part is preferred; if it shows no quote the HTML body is tried.

It never empties a message: if stripping would leave nothing (the whole message is a quote, or a forward with no new text) the original is kept. New tickets are never stripped.

### Attachments and inline images

- Per file: default **25 MB**. Per message: default **50 MB**. Over the limit, the file is not imported and the stored message ends with a list of what was left out.
- Inline `cid:` images up to **1 MB** are embedded in the body as data URIs; a larger one is stored as an ordinary attachment instead.
- Optional virus scan: switch on **Virus scan attachments** (needs `clamdscan` and a running `clamd`; the box is greyed with a warning if the binary is missing). Infected files are dropped and listed in the note. If the scanner is down the file is let through (fail open): intake must not stop because an antivirus daemon is down.
- The existing extension allow-list still applies when a file is saved to a ticket.

## 2. Poison messages

A message that makes processing throw (corrupt MIME, a database error on one row, ...) used to be fetched and failed again on every run. Each failure is now counted in `mail_intake_state` by Message-ID (falling back to the folder UID). After **3** failures (setting) it is **quarantined**:

- an error: moved to the mailbox folder **`ITFlow-Quarantine`** (created on demand) and marked read; if the move fails it is flagged and marked read in place
- a message that simply could not be placed three times in a row (no matching contact or domain on a mailbox that does not queue unknown senders): stays in the Inbox, **flagged and marked read**, so it stops being fetched and logged every five minutes

Both are listed under **Admin > Mailboxes > Quarantined mail** with the last error, and raise one *Message set aside after repeated failures* alert. Actions: **Reset** gives it a fresh set of attempts (move it back to the Inbox as unread to retry), **Dismiss** removes the list entry.

A message that cannot even be *fetched* by the mail library is outside this protection (it fails inside the library before RivetIT sees it); that shows up as the mailbox failing to poll and the *Mailbox unreachable* alert.

## 3. Outbound mail

Everything sent through the mail queue is machine generated unless a caller says otherwise, and carries:

- `Message-ID: <rivet.<random>@your-sender-domain>` - stored on the queue row (`email_queue.email_message_id`), together with the ticket id (from the caller, or the `[PREFIX-123]` token in the subject), so a customer's reply threads back to the ticket even if the subject is changed
- `Auto-Submitted: auto-generated`
- `X-Auto-Response-Suppress: All`
- `X-RivetIT-Auto: 1` (lets the poller recognise its own mail if it loops back)

Queue behaviour (`cron/mail_queue.php`, runs every minute):

- **Reaper:** a row stuck in *sending* for more than 10 minutes (a killed or crashed run) goes back to *queued*.
- **Back-off:** after failed attempt 1, 2, 3, 4 the next try waits **5, 15, 60, 240 minutes**. After the 5th failed attempt the row is *exhausted*: not retried, one **Outbound email gave up** alert. **Maintenance > Mail Queue > Force send** gives it a fresh set of attempts.
- **Permanent failures** (invalid sender or recipient address, no MX record) are marked failed at once and are not retried.
- **Rate limit:** at most **120** messages per minute (setting); the rest wait for the next run.

## 4. Health and alerts

**Admin > Mailboxes** shows per mailbox: last polled, last success, consecutive failed polls and the last error, with a Healthy / Retrying / Failing / Not polled badge, and a banner counting everything that needs attention (unhealthy mailboxes, quarantined messages, outbound mail out of retries). **Admin > Ticketing** repeats the count on the Mailboxes tile.

Each alert is an in-app notification plus an email, and the same alert is **not repeated for 6 hours** (setting). Recipients: the *Alert email address(es)* setting, or every active administrator when blank. The checks run from `cron/cron.php`, `cron/mail_queue.php` and the parser, so a dead parser is still noticed.

| Alert | Fires when | What to do |
|---|---|---|
| **Mailbox sign-in failed** | An OAuth token refresh failed (Microsoft *and* Google; the provider's own reason, such as `invalid_grant`, `AADSTS7000215` or `AADSTS700082`, is in the alert and in **Maintenance > App Log**) | Revoked or expired refresh token: reconnect the mailbox (Mailboxes > Edit > Connect). Invalid client secret: renew it in the Entra / Google console and paste it in Admin > Settings > Mail |
| **Mailbox unreachable** | The mailbox failed 3 polls in a row (setting) | Read *Last error* on the Mailboxes page: wrong password, IMAP disabled, firewall, DNS, expired app password |
| **Mail poller silent** | An active mailbox has not been polled for over 15 minutes (setting) while email-to-ticket parsing is on | `cron/ticket_email_parser.php` is not scheduled or not running. Check `/etc/cron.d`, the cron log file exists and is writable by the web user, and that **Settings > Ticketing > Email-to-ticket parsing** is on |
| **Outbound email gave up** | One or more queued emails failed all 5 attempts | Mail Queue page: open the message, fix the cause (SMTP credentials, relay, recipient), then **Force send** |
| **Sender rate limit reached** | A sender exceeded the hourly cap | Usually a mail loop or a stuck application. Review under Mail requests (reason *Rate limited*), fix the sender, dismiss the requests |
| **Message set aside after repeated failures** | A message hit the failure limit | See *Poison messages* above |

## 5. Connecting a mailbox

### Microsoft 365 (Microsoft Graph, OAuth)

Mailboxes of type *Microsoft 365* are read through the Microsoft Graph `/messages` API (not IMAP), so tenants that block basic IMAP auth work.

1. Entra admin center > **App registrations > New registration**, single tenant.
2. **Redirect URI** (type *Web*): `https://<your-host>/admin/oauth_microsoft_mail_callback.php` - the exact value is shown on Admin > Settings > Mail.
3. **API permissions:** Microsoft Graph delegated `Mail.ReadWrite`, `offline_access`, `openid`, `profile` (plus the SMTP send permission if you also send through Microsoft 365). Grant admin consent.
4. **Certificates & secrets:** new client secret. Copy the *value*, not the secret id.
5. Admin > Settings > Mail: paste Application (client) id, client secret, Directory (tenant) id.
6. Admin > Mailboxes > **Add Mailbox**, type Microsoft 365, then **Connect**. Sign in as the mailbox (or a user with access to a shared mailbox - see *Check Shared Mailbox Access*).
7. The mailbox gets two folders next to the Inbox: `ITFlow` (processed) and `ITFlow-Quarantine`.

Client secrets expire (usually 6-24 months). Put the expiry in a calendar: when it lapses you get *Mailbox sign-in failed* with `AADSTS7000215`.

### Google Workspace (IMAP, OAuth)

1. Google Cloud console: create a project, enable the **Gmail API**, configure the OAuth consent screen (Internal for a Workspace-only deployment).
2. **Credentials > OAuth client id** (Web application); authorised redirect URI as shown on Admin > Settings > Mail.
3. Scope `https://mail.google.com/` (needed for IMAP XOAUTH2).
4. Paste client id and secret into Admin > Settings > Mail, then add a mailbox of type Google Workspace and connect it.
5. IMAP must be enabled for the account. An OAuth app left in *Testing* status has refresh tokens that expire after 7 days (`invalid_grant`): publish it (Internal) to stop that.

### Standard IMAP

Host, port (993 SSL / 143 TLS), encryption, username and password (an app password where the provider requires one). The certificate is validated, so a self-signed server must be fixed rather than bypassed.

### Mail from RivetIT: SPF, DKIM, DMARC

Replies and notifications are sent from the mailbox or ticket sender address, so that domain must authorise the sending service or customers' servers will junk or reject them (and you will see *Outbound email gave up*).

- **SPF:** one TXT record at the domain apex listing every sender, e.g. `v=spf1 include:spf.protection.outlook.com include:_spf.google.com ~all`. Only one SPF record per domain; keep it under 10 DNS lookups.
- **DKIM:** enable signing at the sending provider (Microsoft 365: Defender portal > Email authentication > DKIM, publish the two CNAME records; Google: Admin console > Apps > Gmail > Authenticate email) and verify the selector resolves.
- **DMARC:** start with `v=DMARC1; p=none; rua=mailto:dmarc@your-domain` and read the reports, then move to `quarantine` and `reject` once SPF and DKIM align for everything you send.
- Send from an address in a domain you control and whose records you edited. A "From" at a domain the SMTP account is not allowed to send for is the usual cause of persistent failures.
- Test with a message to an outside mailbox and read the headers: `spf=pass`, `dkim=pass`, `dmarc=pass`.

## 6. Settings

Admin > Mailboxes > **Mail intake settings** (stored in `mail_intake_settings`, defaults apply until saved).

| Setting | Default | Meaning |
|---|---|---|
| Messages per sender per hour | 20 | Over this a sender is held as a mail request (loop / flood protection). Suppressed mail and duplicates do not count |
| Failures before quarantine | 3 | Failed attempts before a message is set aside |
| Max attachment size (MB) | 25 | Per file |
| Max attachments per message (MB) | 50 | Total per message |
| Inline image cap (KB) | 1024 | Larger inline images become attachments; 0 = no cap |
| Virus scan attachments | off | Uses `clamdscan` when installed |
| Outbound emails per minute | 120 | Mail queue send limit |
| Failed polls before alert | 3 | Consecutive failures before *Mailbox unreachable* |
| Poller silent after (minutes) | 15 | Silence before *Mail poller silent* |
| Repeat an alert at most every (hours) | 6 | De-duplication window per problem |
| Alert email address(es) | blank | Comma separated; blank = all administrators |

## 7. Troubleshooting

- **A customer says their email never made a ticket.** Email Log: search the sender. *Suppressed* names the rule; *Duplicate* means that Message-ID was already imported; *Rate limited* means the hourly cap; *Ignored* means no contact or domain matched and the mailbox does not queue unknown senders (turn on *Parse unknown senders* on the mailbox, or add the contact). Nothing at all: check the Mailboxes health columns and the mailbox's `ITFlow` / `ITFlow-Quarantine` folders.
- **Every reply makes a new ticket.** The reply does not carry the ticket token and the Message-ID chain is lost (some gateways rewrite `Message-ID` and drop `References`). Confirm the outbound mail has a `Message-ID: <rivet....>` header and that the customer's reply has it in `In-Reply-To`.
- **Two systems keep answering each other.** RivetIT drops anything with `Auto-Submitted`, `X-RivetIT-Auto` or a null return path, and caps a sender at 20 messages per hour. The other system must also honour `Auto-Submitted` / `X-Auto-Response-Suppress`; the rate-limit alert names the sender.
- **Poller silent right after install.** The parser's cron log file must exist and be writable by the web user, otherwise cron silently never starts the job.
- **Microsoft: `AADSTS7000215`** invalid client secret (expired or the secret id was pasted); **`AADSTS700082`** refresh token expired from inactivity (reconnect); **`AADSTS65001`** consent missing (grant admin consent).
- **Google: `invalid_grant`** token revoked, password changed, or the OAuth app is in Testing status.
- **Mail is stuck in *sending*.** A run was killed; the reaper returns it to the queue after 10 minutes.
- **Re-run a quarantined message.** Fix the cause, click **Reset**, then move the message from `ITFlow-Quarantine` (or unflag it) back into the Inbox as unread.
- **See what the parser is doing.** Maintenance > App Log, category `Cron-Email-Parser`, and `php cron/ticket_email_parser.php` from the install directory as the web user (the output counts processed and unprocessed messages).

## 8. Tests

- `php tests/mail_intake_parser.php` - golden `.eml` tests (`tests/fixtures/mail/*.eml`, expected results in `tests/fixtures/mail/expected/*.json`; `UPDATE_GOLDEN=1` regenerates them), no database or mailbox needed.
- `RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<scratch> php tests/mail_intake_db.php` - migration, dedupe, threading, poison counter, the real `processInboundMessage()` glue, queue reaper / back-off / rate limit, health alerts, and real `cron/mail_queue.php` runs against a local SMTP sink. Scratch databases only.
