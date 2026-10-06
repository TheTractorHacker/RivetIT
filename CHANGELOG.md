# Changelog

This file documents all notable changes made to RivetIT. RivetIT was called ITFlow Internal IT until September 2026
and is built on ITFlow; entries from before the rename keep the names used at the time, and the version history
continues unchanged.

## [Unreleased]

### RivetCore 0.17.1

- Updates the shared library to RivetCore 0.17.1 (job heartbeat, webhook signed timestamp, PSR-3 logging, audit reader, retention horizons). **Migration 2.6.136** adds `integration_jobs.heartbeat_at` through Core's own migration runner. The Update page shows the RivetCore version; the library updates with the app (the pin is in `composer.json`/`composer.lock`), no separate button is needed.

### Service catalog approvals and request forms

- **Request forms.** Edit a catalog item (Administration > Service Catalog) to add questions: text, long text, choice list, checkbox, date or number, each optionally required, with a placeholder and an order. The portal's Request service form and the agent's New Ticket window show them, the server validates every answer, and the answers appear on the ticket (agent and portal) as a read-only **Request details** block.
- **Approval chains.** Switch on **Requires approval** and add ordered steps: a person, a role, or the requester's manager (from the contact's Manager field), each set to "any one approves" or "everyone approves". The ticket is created but held (status **Pending Approval** if you have one, otherwise **On Hold**, so the SLA clock is paused) until every step approves; a rejection closes it with the reason. A step nobody can approve (no manager on file) falls back to the administrators. An item can carry a 0-100 risk score and an **auto-approve below** threshold: lower-risk requests skip approval.
- **Approvals inbox.** Agents get Service Desk > Approvals (approve or reject with a comment; administrators can also decide a step for its approvers). Managers get a portal **My approvals** page, shown only to contacts that are approvers and limited to their own department. Approvers are notified in-app and by email.
- **Popular this month and Recently used by you.** Tickets now remember the catalog item they came from; both catalog pages show the five most requested items of the last 30 days, and the portal also shows the contact's own last five.
- Everything is off by default: items without questions or approval behave exactly as before. Database migration 2.6.133 (Update Database). See docs/SERVICE_CATALOG.md.

### Employee lifecycle workflows

- **Task dependencies.** A workflow task can wait for other tasks; until they are completed or skipped it is blocked and cannot be completed or skipped. Completing a task unblocks what was waiting on it. The template editor refuses loops and dependencies outside the template.
- **Assignees, due dates and reminders.** Tasks can have an assignee and a due date counted from the workflow start, the employee's start date or end date. The workflow page shows Due soon and Overdue, and a new cron job (`cron/workflow_cron.php`, every 15 minutes) sends one reminder per task per state as an in-app notification.
- **Approval tasks.** A task can need approval from the employee's manager, a user or a role. Approvals record who, when and a comment; a rejection pauses the workflow and notifies the person who started it.
- **Automated tasks.** A task can create a ticket, queue an email, notify an agent, emit a webhook event or disable the employee's portal login (offboarding only; nothing is deleted). Failures are retried, logged to a new task log and the audit trail, and fall back to a manual task that an agent can finish by hand or run again.
- **Preview.** A dry run on the person's page shows the tasks, their order, resolved due dates and what each action would do, without writing or sending anything.
- **Start from events (off by default).** New setting "Start workflows automatically" raises `employee.hired` and `employee.terminated` from the contact form, the People import and the Odoo sync, and a new event-rule action "Start an employee workflow" starts a template once per person.
- Fix: a workflow task could be completed, skipped or reopened through a different workflow's page; the task must now belong to the workflow whose department access was checked.
- Database migration 2.6.134. Existing templates and runs are unchanged. See `docs/EMPLOYEE-LIFECYCLE-WORKFLOWS.md`.

### Slack and Microsoft Teams notifications

- **A webhook can now post to Slack or Microsoft Teams.** Administration > Webhooks > Add Webhook has a Destination type (Generic, Slack, Teams). Slack gets a Block Kit message; Teams gets a Workflows (Power Automate) webhook message with an Adaptive Card 1.4 (the classic Office 365 connector format is retired). Each message shows title, priority, client, status and an Open ticket button. Generic webhooks are unchanged.
- **Routing per destination:** the events it listens to, a minimum ticket priority and an optional client list. A **Send test message** button on the edit dialog sends a clearly labelled message and shows the HTTP result.
- **Safe by default:** the URL is stored encrypted and never shown or logged; https only, public addresses only (checked on save and again on send, connection pinned, redirects not followed); ticket text is never treated as markup or as @channel / @here / mentions. Delivery goes through the existing job queue and retry schedule, and the Deliveries list shows a Slack or Teams badge.
- Not included: interactive buttons, slash commands and signing-secret endpoints. See docs/SLACK_TEAMS_SETUP.md. Tested against local mock servers only, not a real Slack workspace or Teams tenant.
- Database update 2.6.135 (webhook type, priority and client filter columns).

### Entra and Intune sync hardening

- **Microsoft Graph client hardened** (tested against a local mock, not a real tenant): the access token is cached encrypted and refreshed 5 minutes before expiry, 429/503/504 are retried honouring Retry-After (capped), requests time out, paging is bounded (page cap, loop detection, and a page link to another host is refused so the token is never sent elsewhere).
- **Clear errors:** sign-in failed, admin consent missing (names the permission, for example DeviceManagementManagedDevices.Read.All), throttled, unreachable, not available for this tenant. They show on the Integrations page and in the Intune sync log. Test Connection now lists the permissions granted to the app and probes each enabled feature.
- **Sync Now** and the cron job share one sync service (one run at a time, a 2 minute limit, same log).
- New docs/ENTRA_INTUNE_SETUP.md: exactly what a real tenant needs and a first-live-sync checklist. Live-tenant behaviour is unverified.
- Database update 2.6.135 (token cache and error code columns).

## [26.10.20] RivetIT — one event bus, Redis guards, Audit trail, SSRF hardening

### One event bus: queued webhooks, event rules and the job queue (rivet-core 0.15)

- **Webhooks are now queued, signed and retried through the job queue.** An event creates one background job per subscribed endpoint; the body is signed (HMAC-SHA256) and identical on every retry. A failed delivery is retried after 1, 5, 30 and 120 minutes, then set aside as failed. The Webhooks page shows every attempt, including retries. Deliveries go out right after the page responds (and the main cron processes any left over), so they no longer wait for a schedule.
- **Every audit event can be subscribed to or automated.** Logins, setting changes, workflow, problem and change events and the rest now flow through the same bus as ticket events, and the Webhooks event list includes every event type this server has recorded.
- **New: Administration > Event rules.** When an event happens (optionally only if fields match), create a ticket, notify a user or call a webhook. `{field}` placeholders insert values from the event. Rules run through the job queue and each run is on the audit trail.
- **New: Administration > Job queue.** Counts, the jobs, error details, Retry for failed jobs, Process jobs now.
- **Redis guards.** Sign-in is throttled (10 attempts per account and 30 per address every 5 minutes, on top of the existing lockout), every cron script now runs one copy at a time, and a database update cannot be started twice. All fail open if Redis is down.
- Requires rivet-core 0.15.1 (`composer install`). No database migration.

## [26.10.19] RivetIT — fix: blank Update page before the database update

Fixes a blank **Administration > Update** page on a server that had pulled 26.10.18 but not yet run **Update Database**: the new release-channel setting did not exist yet and its lookup raised an error. The page now renders, shows the channel from the checked-out branch, and saving the channel before the database update says to run Update Database first. No database change.

## [26.10.18] RivetIT — Release channel (Production / Beta)

Database migration 2.6.132 applies with **Update Database**. No other changes since 26.10.17.

### Release channel: Production or Beta

- **Administration > Update has a Release channel switch.** Production follows the `main` branch (tested releases); Beta follows `beta` (early access, changes more often). Each server chooses its own. The update check, **Update App**, the force update and `scripts/update_cli.php` (and so `deploy/update.sh`) all follow the chosen channel and move the server onto its branch.
- **A server never goes backwards.** Switching to a channel whose latest release is older than the code already running is refused with an explanation, because the database may already be newer than that code can read. It becomes possible once the release catches up. A switch that would overwrite hand-edited files is refused and leaves them untouched.
- Existing servers keep following the branch they are on today (a server on `beta` starts as Beta, everything else as Production), so nothing moves by itself. Database migration 2.6.132 adds the setting.

## [26.10.17] RivetIT — SLA pause, resolution time rules, compliance status and saved-view filters

Database migrations 2.6.125 to 2.6.131 apply with **Update Database**. Requires rivet-core 0.14.0 (`composer install`). Consolidates the entries that were listed as Unreleased after 26.10.15. The tag `v26.10.16` was published before these notes and the version number were committed, so it reports 26.10.15; this release supersedes it.

### Customisable ticket view filters

- **Edit the filters a saved view uses.** The view's Edit dialog (and Save Current View) now has a filter form instead of a fixed query: show Open, Closed or All tickets or pick specific statuses (new statuses you create appear automatically), assigned to anyone / me / unassigned / a named agent, priority, on-site or remote, overdue or due today, board, category and tags. The dialog also describes in plain words what the view currently does. Renaming a view without touching the form leaves its filters alone.
- Only validated choices are stored (unknown ids and junk values are dropped), a view with several statuses or tags now highlights correctly when active, and shared views can still only be edited by those allowed to. No database change.

### SLA no longer breaches while waiting on someone

- **Waiting statuses pause the SLA clock.** A ticket set to Waiting on Customer, Employee or Vendor (or On Hold) is no longer shown as breached because of the wait. While paused the clock is frozen: it never drifts into "Breached", the time spent paused is added back to the due date when the ticket moves on, and a ticket that was already past due before it was paused stays breached.
- **You choose which statuses pause it.** Administration > Ticket Statuses has a new **Pauses the SLA clock** option on every status, in the create dialog and the edit dialog, and an SLA clock column in the list. A status created later can opt in the same way, so the setting follows your own statuses. Saving a status updates the open tickets already in it. An SLA policy's own pause list still works and adds to this.
- **Every way of changing status is covered.** Agent status change, kanban drag, the API (including agent replies that move a ticket to Waiting on Customer), automation rules, and a customer reply that ends the wait all start or stop the pause. Anything that slips through is repaired when the ticket is opened and by the scheduled job. Ticket list, dashboard, ticket page and escalation rules all agree.
- Contract-hour SLAs (no policy) now also add paused time back. Database migration 2.6.131 adds the option and turns it on for On Hold and every status starting with "Waiting", then pauses open tickets already waiting.

### Resolution time rules

- **Only resolved tickets count toward average resolution time.** An open ticket, one in an "Unresolved" status, or one that was reopened and not yet resolved again is left out of every average (dashboard tile, ticket reports, per-technician figures and the API).
- **Reopening restarts the clock.** The time to resolve a reopened ticket is measured from the moment it was reopened to when it is resolved again, not from the original creation date. Creation dates are never changed. Every way of reopening is covered: agent status change, kanban, the department portal and guest link, the CSAT low-rating auto-reopen, automation and scheduled reopen, and the API. Moving or re-saving a ticket that is already open does not reset anything.
- Resolution time now ends at the resolved date (older tickets closed without one use the close date), instead of the close date, so a ticket that sits resolved before auto-closing is not penalised. SLA targets and SLA breach tracking are unchanged.
- Database migration 2.6.130 adds `ticket_resolution_started_at`. Existing tickets keep a blank start, so their time still runs from creation.

### Compliance status

- **Administration > Compliance status.** A live view of how this installation measures up against common security controls, tagged to ISO/IEC 27001, SOC 2, PCI DSS and HIPAA, with a score per framework. Automatic checks cover multi-factor authentication (agents and administrators), administrator count, session lifetime, HTTPS-only, vault key, backups, API-key expiry, dormant agents, credential rotation, schema currency, audit recording, retention against the chosen preset and, when the module is on, overdue required training.
- **Manual checklist with sign-off.** Thirteen things software cannot see (policy review, risk assessment, access review, restore test, incident-response test and so on). Record who reviewed, when, the next due date and a note; items show as current, due soon or overdue. Every review is kept (append-only).
- **Snapshots and auditor export.** Save a snapshot at any time (one is also saved automatically each month, and kept). Download a CSV or a printable report for the whole position or a single framework, from the live view or from any snapshot. Saving, recording and exporting are written to the audit trail.
- **Share a report on the portal.** On a saved snapshot, choose Share to publish it, with an optional message, for everyone who can sign in to the department portal. They see it under a Security menu item: framework scores, the title and result of each check, and the status and last-review date of each checklist item. Counts, account names, reviewer names, notes and settings values are never shared. Nothing is shown until an administrator publishes, a newer snapshot does not replace what is shared, and Stop sharing removes it. Publishing and unpublishing are audited. Database migration 2.6.127 adds the table; rivet-core 0.10.0.
- **NIST SP 800-171 Rev 2 / CMMC Level 2** is a fifth framework: every check and checklist item that maps to it carries requirement numbers (indicative; CMMC Level 2 corresponds to the 110 requirements). Database migration 2.6.128 adds `subject_id` to the compliance tables (required by rivet-core 0.12). rivet-core 0.12.0.
- **Who is responsible.** If a managed service provider looks after part of your compliance, choose them (from Vendors) for a whole section, or for a single item that differs. Unassigned items are your own staff. The choice shows beside each item, in CSV and printable exports, in snapshots and on the shared portal page. Changes are audited. Database migration 2.6.129 adds the table.
- **NIST 800-171 / CMMC retention preset** (Compliance settings): a 365-day minimum. 800-171 requires audit logs to be retained but sets no number, so this is the common choice to define in your policy. rivet-core 0.14.0.
- Fixed: the "Application and database are up to date" check showed "Version information is unavailable" because the page does not load the version file; it now reads it itself.
- This is a self-assessment aid, not a certification or an audit opinion; control references are indicative and should be confirmed against the current text of each standard.
- Database migration 2.6.126 adds the two tables (created by rivet-core's own migration runner). Upgraded to rivet-core 0.9.0.

### Compliance settings

- **Administration > Settings > Compliance.** Pick a retention preset (ISO/IEC 27001, SOC 2, PCI DSS or HIPAA) and set how long the audit trail and the activity logs are kept. A preset is a **minimum**: records are never deleted younger than it (365 days, or 6 years for HIPAA), even if a number is set lower, and the hourly cleanup enforces it, not just the form. 0 keeps records forever and is always allowed. A preset helps meet a retention requirement; it does not make an organization compliant on its own.
- The audit trail (sign-ins, setting changes, Remote MCP tool calls) now has its own retention, separate from the activity logs, webhook delivery log and finished background jobs. It defaults to 365 days, which keeps more than before.
- Saving the page is itself recorded in the audit trail with the before and after values. Security > Log retention honours a preset's minimum too.
- Database migration 2.6.125 adds the two settings. Upgraded to rivet-core 0.8.0.

### security follow-ups and log retention

- **Request ids are server-assigned.** The audit trail used to store whatever `X-Request-ID` header a client sent, and the MCP endpoint read the same variable. Both now use a server-generated id (`$_SERVER['RIVET_REQUEST_ID']`, which `mcp_server/index.php` sets); a client header is never stored.
- **Remote MCP: no more preselected agent.** The "link this sign-in to an agent" list no longer preselects the agent whose email matches the token's email claim. The email is only a hint from the sign-in provider, so you now choose the agent yourself.
- **Log retention covers RivetCore's tables.** `cron/cron.php` also prunes the audit trail, the webhook delivery log and finished integration jobs at the existing "log retention" horizon (0 keeps everything).
- Upgraded to rivet-core 0.7.1, which fixes a crash on a job's fifth failed attempt (the default limit) and PDF import on PHP 8.2.

## [26.10.15] RivetIT — RivetCore, company SSO for agents, safer updates and a round of fixes

Pre-release. Database migrations 2.6.122 to 2.6.124 apply with **Update Database**, and one click now applies every pending step.

**Updates and installs**
- One Update Database run applies all pending migrations (each step used to test a version fixed when the page loaded, so only one step ran per click). The browser/Docker installer now stamps the real schema version of `db.sql` and runs the migrations after it; before, a browser install lacked columns such as `training_lessons.lesson_requires_previous`.
- The in-app Update installs the PHP packages after pulling (`composer install --no-dev`, in a private temporary composer home, without plugins or scripts) and restores composer-generated `vendor/composer` files before the pull, so a pull is not blocked by them. A composer failure is shown as a warning. The Update screen now reports git errors instead of "Update successful".

**Agent sign-in with company SSO** (OpenID Connect; off by default, migration 2.6.122): linked agents only, administrators excluded, an agent's own two-factor code is still required, the credential vault stays locked, link changes are audited. Tested end to end with Authentik, Keycloak 26.8 and Ory Hydra 2.3 (`tests/e2e_oidc/`, `docs/OPENID_CONNECT_PORTAL.md`).

**Security and permissions:** the last active administrator cannot be demoted; legacy API keys act as an administrator as documented; the credentials CSV export needs Full access; RMM policy save, delete and remove need level 2; the incident-response password reset touches agents only; the portal ends sessions when switched off, hides system replies from employees and shows the Contacts tile only to primary/technical contacts; log retention of 0 keeps logs instead of deleting them.

**Fixes** across tickets, projects and calendar, departments and people (shared sites in pickers, contact delete removes the portal login), infrastructure (rack model, domain expiry, software seat links, OTP export), RMM, reports, training wording, Docker scheduled jobs and the interface (bulk deletes ask for confirmation, sort direction, "All Time" range, light theme, page sizes, a single breadcrumb on the Redis page). The full list, and the items still waiting on a product decision, are in issue #29.

**Behaviour changes to know about:** portal ticket pages no longer show System notes to employees; contract pages open for Technicians; project delete also removes project-only tasks and milestones; printer and drive hard deletes stay hidden until `config_destructive_deletes_enable` is set; the Email Sent badge only shows with SMTP; problem linking takes ticket numbers only; the CSP allows `data:` fonts; the theme preference gains a "light" value.

### Redis is installed with RivetIT

`deploy/install.sh` now starts a dedicated Redis (`rivetit-redis`, `127.0.0.1:6380`, loopback only, no persistence, 256 MB limit with `volatile-lru`) where
it used to install the `redis-server` package and start the stock instance on 6379, which the app never looks at, so a fresh install quietly ran without live
ticket and chat updates, rate limits or job locks. `deploy/update.sh` adds the same instance to existing installs on their next update. Both are idempotent and
never fatal (Redis stays optional), the config is written once and kept, and a listener already on 6380 is left alone. See `docs/REDIS.md`.

### RivetCore: shared package, Audit first

RivetIT now consumes **RivetCore** (`rivet/rivet-core`, tagged releases from github.com/TheTractorHacker/rivet-core), a
package of edition-neutral services shared with RivetMSP. Audit is the first module: `ITFlow\Audit\AuditService` is now a
thin compatibility shim over `RivetCore\Audit\AuditService`, so every existing caller (login, SSO, MCP, Training) is
unchanged. The shim falls back to the original direct insert if the package is not installed yet (the short window
between `git pull` and `composer install` during an update). Storage goes through a Core-owned `DatabaseInterface`;
RivetIT's implementation is `src/Core/Adapter/Database/MysqliDatabaseAdapter.php` and reuses the existing mysqli
connection. Database migration 2.6.124 runs RivetCore's own migration runner (state in `rivet_core_migrations`,
independent of this database version); it is a no-op for `audit_events`, which already exists. Summaries are now
truncated to the column width before insert. Adapter and shim tests live in `tests/core/` and run against a scratch
database. No behavior changes for users; MySQL/MariaDB is unchanged.

Redis is the second module: `ITFlow\Redis\Lock`, `RateLimit` and `RedisSettings` keep their static API and `rivetit:` key layout
but now delegate to RivetCore (`LockManager`, `RateLimiter`, `RedisAdmin`), and `health/ready.php` runs RivetCore's
`ReadinessChecker`. Connection settings still resolve in RivetIT (environment, then Administration > Redis, then the default);
Redis stays optional and everything still fails open, including during the moment between `git pull` and `composer install`.

Cron and Jobs are the third and fourth modules: `ITFlow\Cron\JobRunner` and `JobCatalog` and `ITFlow\Jobs\JobQueue` keep their API and
delegate to RivetCore (the job list itself stays RivetIT data; the runner keeps its `/tmp/rivetit-jobs` state directory). Two fixes ride along: the
queue's `claim()` is now safe for concurrent workers and returns the claimed state, so a failed job's attempt number and retry backoff are
correct (the old worker used a pre-claim snapshot, so the first failure waited two hours instead of one minute); and a job that exits without a
trailing newline no longer hides its exit status in the Cron Manager.

MCP and ITSM are the fifth and sixth modules. Remote MCP's config resolution, token-claim checks, tool pipeline (rate limit, role check, audit row, standard envelope),
unlinked-identity capture and linking, diagnostics and discovery cache now live in RivetCore; the SDK wiring (`mcp_server/`), every tool's SQL and the `users` queries stay in
RivetIT (`src/Core/Adapter/Mcp/UsersAgentDirectory.php`). Problem and Change management delegate to RivetCore's `ProblemService` / `ChangeService` (status tables are now public
constants there); the ticket link stays in RivetIT. Database migration 2.6.124 also records Core migrations 0003 and 0004, which are no-ops here because the tables already exist.
The composer repository entry now uses `no-api`, so installs clone over HTTPS instead of calling the GitHub API (avoids anonymous rate limits on deploy).

Webhooks, Automation, Workflow, KB converters and Knowledge are the seventh to eleventh modules. `ITFlow\Webhooks\WebhookDispatcher`, `Automation\AutomationRuleEvaluator`,
`Workflow\WorkflowService` and `Knowledge\CredentialReferenceRenderer` keep their API and delegate to RivetCore (webhook subscribers still come from RivetIT's `webhooks` table through
`Core\Adapter\Webhooks\WebhooksTableSubscriptions`; both `X-ITFlow-*` and `X-RivetIT-*` headers are still sent). The DOCX and PDF converters moved to RivetCore unchanged and
`ITFlow\KB\DocxConverter`, `PdfConverter` and their exceptions are class aliases of the Core classes, so Training and the KB import pages need no changes (output was verified
byte-identical to the previous implementation). Starting a workflow run is now atomic. Authorization stays in RivetIT: `Security\AuthorizationService` is only a wrapper over
`lookupUserPermission()` and `enforceClientAccess()`, so there is nothing shareable to extract. KB media tokens, the media URL rewriter, the HTML importer and the Metrics subsystem also
stay in RivetIT for now (they are bound to RivetIT's signing keys, URLs and RMM tables).

### Redis building blocks and a wider read-only MCP

Remote MCP (still off by default) now has eight more read-only tools: ticket search and detail, asset search and
detail, clients, contacts, and knowledge-base search and articles. All go through one pipeline: role and client-scope
checks, a per-agent rate limit, an `audit_events` row per call, and a standard response envelope. Tools now publish
as `rivetit_*` (previously the SDK showed bare method names). Redis gains configurable connection settings
(`RIVETIT_REDIS_*`), a fail-open lock and rate limiter in `src/Redis/`, a guard on the integration worker, and
`health/live.php` / `health/ready.php`. Remote MCP is now set up from **Administration → Settings → Remote MCP** instead of server files: issuer and
audience are saved in settings (the `RIVETIT_MCP_*` variables still override them, and `RIVETIT_MCP_ENABLED=0` remains a
hard off), one-click health checks explain what is wrong in plain language, and people who sign in but are not linked yet
are listed so an administrator can link them to an agent in one click. Database migration 2.6.123 adds the two settings
columns and the `mcp_unlinked_identities` table. The Cron Manager now describes each job in plain language, shows its
last activity and latest output, and has a per-job **Run now** (output and exit code are shown in the table). Jobs that
send real email, write to outside systems, or need arguments are never startable from the page; the reason is shown
instead. UniFi sync can also be started from there. New **Server status & tasks** and **Redis** pages under Maintenance
replace most terminal checks: disk, backups (including the encrypted disaster-recovery archives), jobs and Redis health in
one view with copy-ready commands for the few root-only tasks, and Redis host, port, password, memory limit and cache
clearing are editable in the browser (migration 2.6.123 adds the settings columns; the `RIVETIT_REDIS_*` variables still
override). See `docs/REDIS.md` and `docs/REMOTE_MCP.md`.

## [26.10.14] RivetIT — Odoo tab, easier Odoo and OpenID Connect sign-in

Database migrations 2.6.120 and 2.6.121 add two OpenID Connect settings. Both default to the previous behavior, so
existing sign-ins are unchanged.

- **Integrations > Odoo.** The Odoo connection, a sync summary, Department Portal sign-in, Odoo field mapping, employee
  links with the nightly sync, and recent syncs now share one tab. Directory Sync is Microsoft and Google only. The
  employee-link section left the Training settings page, which keeps a pointer; related notifications open the Odoo tab.
- **OpenID Connect for the Department Portal.** Optional first sign-in linking by email (off by default) stores the
  provider subject on a login set to OpenID Connect with a blank subject; a separate setting controls whether the provider
  must report the email as verified (on by default; Authentik reports it as false unless configured). Failed sign-ins now
  log which check failed (fixed messages, the endpoint path, HTTP status and the standard OAuth error code; never tokens,
  codes, credentials or response bodies).
- **Odoo addon for Odoo 19 and 20.** One source tree builds both zips (`odoo_addons/build_zips.sh`). Setup is native:
  one RivetIT address fills both URLs, the secret is set or generated in the form, an optional "All employees may use it"
  switch, plain explanation pages instead of raw errors, and a Check setup button. Upgrading keeps existing
  integrations. Tested on Odoo 19.0 and 20.0.
- Odoo Department Portal sign-in is labelled Experimental and remains off by default.

## [26.10.13] RivetIT — dedicated MCP access-token audience

The disabled-by-default remote MCP preview now rejects tokens with additional
audiences, even when the configured MCP audience is also present. This enforces
the dedicated-audience setup documented for the external OAuth provider. No
database migration is required.

## [26.10.12] RivetIT — stricter OpenID Connect token checks

The disabled-by-default Department Portal SSO preview now requires numeric ID-token
expiration and issued-at claims, rejects future-issued tokens, and rejects
untrusted extra audiences or a mismatched authorized party. No database
migration is required. Existing local and Microsoft sign-in paths are unchanged.

## [26.10.11] RivetIT — quieter cron notifications

Database migration 2.6.118 removes existing cron-only notices. Successful
cron runs and mail-queue failures remain visible in application logs without
creating notifications. Alerts about tickets, mail delivery, and expiring
assets still work as before.

## [26.10.10] RivetIT — remote MCP preview

No database migration is required. The remote MCP endpoint is disabled by default
and requires a dedicated external OAuth issuer before use.

- Add Streamable HTTP and OAuth protected-resource discovery with signed,
  audience-bound, short-lived access-token checks.
- Map an OAuth subject to one active RivetIT agent and recheck existing role and
  department access in read-only profile and recent-ticket tools.
- Add an Admin agent identity mapping, deployment routing, and setup guide.
  No write tools or RivetIT API keys are exposed through MCP.

## [26.10.9] RivetIT — Odoo Department Portal SSO preview

Database migration 2.6.117 is required. Odoo sign-in is disabled by default
until the `rivetit_sso` Odoo 19 addon is installed and configured.

- Add a browser-bound Odoo authorization-code handoff with PKCE and a dedicated
  server-side exchange credential. Codes expire in 60 seconds and the addon
  consumes each code atomically.
- Use existing Odoo employee links to find one active Department Portal login;
  email changes do not change the mapping and duplicate links fail closed.
- Add masked Admin settings, a connection test that signs in no one, and an
  Odoo sign-in choice for linked Department Logins.
- Include the Odoo 19 addon, setup and rollback guide, and callback-log
  suppression in the Nginx deployment template.

## [26.10.8] RivetIT — API reference production fix

No database migration is required. Load the reference through the API's
extensionless OpenAPI route, which returns the correct YAML content type on
production servers. Keep downloads named `rivetit-openapi.yaml`. Allow the
reference page to be embedded by the same-origin Admin page in the Nginx
deployment template.

## [26.10.7] RivetIT — API reference layout and code examples

No database migration is required.

- Replace the API reference viewer with a locally hosted Scalar build. The
  reference uses the available width on its overview and offers request examples
  in Python, PHP, C/Libcurl, and other clients for every endpoint.
- Add a C++ libcurl example and explain how to use the per-endpoint C examples
  from C++. Remove the unused AI and MCP controls from the reference.
- Give the embedded Admin reference more space and update its instructions.
- Point the OpenAPI drift check at RivetIT-Mobile instead of the former internal
  Android app.

## [26.10.6] RivetIT — Department Portal OpenID Connect preview

Database migration 2.6.116 is required. The provider is disabled by default.

- Add OpenID Connect authorization-code sign-in with PKCE for Department Portal
  accounts, using issuer discovery and signed ID tokens from a compatible
  Authentik, Keycloak, or Ory Hydra provider.
- Bind each portal account explicitly to the provider issuer and immutable
  subject. A matching email alone never creates a login. Disabled, archived,
  unmapped, and ambiguous accounts are rejected.
- Add masked provider settings and account-mapping controls in Administration.
  Existing local and Microsoft Entra sign-in remain available.
- Document setup and recovery in `docs/OPENID_CONNECT_PORTAL.md`. Agent SSO and
  single logout are outside this preview.

## [26.10.5] RivetIT — Vacation-return ticket automation

Database migration 2.6.115 is required.

- Record a requester's vacation start and end dates on their contact profile.
- Vacation-return automation rules reopen tickets closed during that window
  after the end date, with an audit note and run log. Tickets closed before a
  rule existed and tickets already reopened are skipped.
- The rule form selects the Reopen ticket action when the vacation trigger is
  chosen. Conditions are optional for this trigger.

## [26.10.4] RivetIT — API access and automation

Database migrations 2.6.113–2.6.114 are required.

- API documentation uses a locally hosted Redoc reference with endpoint search
  and complete request/response schemas, also embedded in Administration.
- New API keys default to read-only access, an explicitly selected department,
  and 30-day expiration. Delete/archive permission requires separate opt-in.
  Optional IPv4/IPv6 addresses and CIDR networks restrict key usage. Existing
  keys retain their previous scopes and delete permissions.
- Removed Quotes, Invoices, Expenses, Products, invoice line items, and related
  financial reports from the API and OpenAPI specification. Integrations using
  those endpoints must stop calling them.
- Scheduled ticket rules can run once per ticket. Rule forms are wider,
  adapt to mobile screens, and retain category/template/script selectors in
  additional rows. Vacation handling in issue #22 remains open.
- Removed underlines from Administration directory card hover states.

## [26.10.3] RivetIT — Admin settings and upload polish

No database migration is required.

- Removed invoice and quote sections from Notification Settings without clearing
  their saved values when other notification settings are saved.
- Joined icon and dropdown controls across Admin settings, centered the Calendar
  Sync guide numbers, and removed white corners from dark modal headers.
- Gave file uploads a consistent browse and drag-and-drop surface across the app,
  client portal, and installer, with accepted-type and server-limit hints. Company
  logo and favicon controls have clearer spacing and actions.
- Moved AI provider management into AI Settings. The former provider URL now
  redirects there. Improved the Webhooks page's layout, empty states, and delivery
  summary query.

## [26.10.2] RivetIT — Visual cron schedules

No database migration is required.

- Admin > Maintenance > Scheduled jobs now offers a visual editor for minute,
  hourly, daily, weekly, and monthly schedules, with a custom cron expression
  option. Each job shows a plain-language schedule and previews changes before
  saving. The existing per-installation Cron Manager validates and writes them.
- Breadcrumb text is larger throughout the app and on Admin detail pages.

## [26.10.1] RivetIT — Admin navigation and scheduled jobs

No database migration is required.

- Admin navigation is shorter and easier to scan. Settings, Tags & categories,
  Ticketing, Templates, and Maintenance have grouped directory pages with clear
  descriptions and return links. The template quick-add actions remain available.
- App, department, and portal navigation have tighter spacing and clearer labels.
- The installer provisions a RivetIT-named cron file for each installation.
  Admin > Maintenance > Scheduled jobs shows that installation's jobs and lets
  administrators edit its schedule without changing another installation's file.
- Scheduled backup jobs load their saved encryption and S3 settings.

## [26.10] RivetIT — Interactive organizational chart

No database migration is required. The organization chart remains read-only
and uses the existing contact manager links and access rules.

- A compact, searchable employee list opens first and remains available if
  JavaScript or the chart library cannot load.
- The interactive chart uses locally hosted, pinned D3 libraries. Open Chart
  to load it on demand, then pan, zoom, fit, expand or collapse branches.
- Search focuses an employee and highlights their authorized reporting path.
  Department, location, and employment-status filters retain needed ancestors
  as labeled context. Missing managers and reporting cycles remain visible as
  data-quality warnings without exposing inaccessible contacts.
- The chart uses smaller cards and a bounded canvas. The list is available for
  keyboard navigation and smaller screens.
- This release also includes the documentation, portal, setup, and training
  fixes merged since the preceding GitHub release.

## [Unreleased] RivetIT - Telemetry removed, the repository moved, clean defaults for new installs
No database change (still 2.6.99). Finishes the rename below: the last things that actively pointed at
`itflow.org`, or still said the repository "has not moved", are addressed.

### New Features & Updates
- **The repository moved**: `github.com/TheTractorHacker/ITFlow-Internal-IT` → `github.com/TheTractorHacker/RivetIT`
  (still private). `includes/branding.php`'s `APP_REPO_URL` default, `composer.json`, `deploy/install.sh`,
  the systemd unit templates, `README.md`, `SECURITY.md` and `docs/DEPLOYMENT.md` all point at the new URL.
  GitHub redirects the old one indefinitely, but every install should still run
  `git remote set-url origin <new-url>` and, if it has one, `git remote set-url fork <new-url>` (keep `fork`
  fetch-only) rather than rely on that. Done on this box; both remotes fetch cleanly against the new name.
- **New installs get `rivetit`-named database defaults** instead of `itflow`: `.env.example`'s suggested
  `DB_NAME`/`DB_USER`, and `deploy/install.sh`'s empty-domain fallback name. Safe for existing installs —
  `.env.example` is only ever copied once to a real `.env`, and `docker-compose.yml`'s own fallback (used only
  when `.env` sets neither) is unchanged, since a stack that relied on it really does have a database named
  `itflow` inside the `itflow_db_data` volume.
- Confirmed no seeded install data (`db.sql`, the setup wizard, `setup_cli.php`) mentions ITFlow — the company
  name is always entered by the installer, never defaulted, and `installation_id` is an unprefixed random string.

### Fixes
- **The telemetry feature is removed**, not just left disabled: it was the last thing in the app that actively
  contacted `itflow.org`. It used to POST installation and company details to the upstream ITFlow project's
  `telemetry.itflow.org`, opt-in, from Admin > Settings > Telemetry, the setup wizard's last step, and
  `scripts/setup_cli.php`; `cron/cron.php` sent the same payload on a schedule when enabled. RivetIT now sends
  nothing anywhere; `config_telemetry` keeps its column, always read and written as `0`.
- **`admin/post/update.php` always sent telemetry**, regardless of the setting: its condition was
  `$config_telemetry > 0 OR $config_telemetry = 2`, a stray `=` instead of `==`, so it was unconditionally true
  on every "Update App" click. Found while removing the feature above. Harmless on installs that left telemetry
  at its default (Disabled) — nothing was actually sent — but fixed regardless.
- **Both force-update paths had a stale `origin/master`**, an upstream leftover from before this project's
  default branch was `main`: `admin/post/update.php` (fixed alongside the telemetry bug) and
  `scripts/update_cli.php --force_update` (fixed here). Neither path had run on this live install, whose Update
  App / FORCE Update App buttons already used `main`.

## [Unreleased] RivetIT - ITFlow Internal IT is now RivetIT
No database change (still 2.6.99) and no version reset (still 26.09). Nothing to run: pull the code as usual. RivetIT
is a free and open-source internal IT operations platform for managing service requests, users, devices,
documentation, automation, integrations, and employee training from one centralized system. This is a rename and a
product identity, not a rewrite: every feature, route, API endpoint and field, integration and stored record works
exactly as before.

### New Features & Updates
- **The product is called RivetIT** wherever the software names itself: the application chrome, login and setup,
  the About / debug page, e-mails and notifications about the system, PDFs and the training kiosk, the REST API
  docs, the installer and deployment scripts, the Docker files and the documentation. The company name and logo
  from Settings > Company still brand the install itself and keep precedence over the RivetIT mark.
- **One place for the name and links:** `includes/branding.php` defines `APP_NAME`, the descriptions, the project
  links (`APP_REPO_URL`, `APP_DOCS_URL`, `APP_SUPPORT_URL`, ...) and the logo paths. Each can be overridden with a
  constant defined earlier (e.g. in `config.php`) or a `RIVETIT_<NAME>` environment variable; Docker Compose passes
  the common ones through from `.env`. The repository is private, so the links built from it (docs, source, issue
  tracker, changelog, tags) stay hidden until `APP_REPO_PUBLIC` is set to `1`; pages show plain text or nothing
  instead of a link that would be a GitHub 404 for staff, and new installs no longer get a "Docs" custom link.
- New `README.md`, `NOTICE` (GPL-3.0; credits ITFlow by itflow-org and the MSP fork by TractorHacker / Foley IT),
  `REBRANDING.md` (every name that was kept and why), `CONTRIBUTING.md`, and a `SECURITY.md` that points at this
  repository's private security advisories. `composer.json` gains the project metadata (`rivetit/rivetit`,
  `GPL-3.0-only`); its `ITFlow\` autoload namespace is unchanged.
- **RivetIT mark and favicon:** `img/branding/` holds the logo, dark logo, mark and favicon (SVG, plus
  `favicon.ico`), and the root `/favicon.ico` is the RivetIT icon instead of the upstream paper plane. The login
  page shows the mark and "Welcome to RivetIT" when no company logo is set; an uploaded company logo or favicon
  still wins. The footer reads "RivetIT 26.09" (the upstream docs, forum and services links are gone; "Docs ·
  Source" are added once the repository is public), and the About / debug page shows "RivetIT | Version 26.09",
  the source repository, the license and a "Based on ITFlow" credit. The wordmark in `logo.svg` /
  `logo-dark.svg` is drawn as outlines, so it no longer depends on the fonts installed on the viewing system.
- **E-mails from existing installs say RivetIT:** `config.php` still holds `$config_app_name` (setup wrote
  `ITFlow Internal IT`); the old defaults `ITFlow` and `ITFlow Internal IT`, or an empty value, now mean RivetIT at
  runtime. A name an administrator chose is kept, and `config.php` is never rewritten. New installs write an empty
  value, which means the product name, so a later rename or `RIVETIT_APP_NAME` reaches their e-mails too.
- **Webhooks** also send `X-RivetIT-Signature` and `X-RivetIT-Event`, with the same values as `X-ITFlow-Signature`
  and `X-ITFlow-Event`, which stay: existing receivers keep verifying the old names.
- **Display-only identity strings** now say RivetIT: the authenticator-app label and passkey name for new
  enrolments (existing authenticators and passkeys keep working), the calendar feed's PRODID and file name, the
  test push title, the Stripe payment description (the `itflow_*` metadata keys stay), the API token label for
  passkey logins, the outgoing User-Agent for address lookups (`RivetIT/26.09 (+<repository>; ...)`) and training
  video lookups (`RivetIT-Training/1.0`), the training PDF creator and transcript footer, the backup
  `version.txt` and SQL dump header (the backup file names stay), the kiosk web-app name ("RivetIT Training";
  kiosks already added to a home screen keep their name) and the wording around new Odoo write-back lines
  ("Record of truth: RivetIT", "RivetIT ref:"; the `[ITFLOW:…]` marker is unchanged, and lines written before keep
  their wording).

### Fixes
- **Admin > Update: "Latest Release"** linked to the releases of the old MSP fork (`TheTractorHacker/itflow`); it
  now shows the latest tag of this repository (a link to its tag list once the repository is public). The page
  shows the RivetIT version (26.09, as in the footer) with the git release tag below it, and says where updates
  come from: the check compares with the `fork` remote, now named by a constant, `APP_UPDATE_REMOTE` (default
  `fork`, still pointing where it did), while **Update App** runs `git pull` from the branch's upstream (`origin`).
- **Fresh installs start with ticket charges off**, like the other billing modules: the column default turned them
  on, showing Products, the Ticket Charges report and charge fields until Settings > Modules was first saved.
- **Internal-IT wording**: browser tab titles say Departments, Tickets by Department and the other department
  report names instead of the file names (Clients, Ticket By Client, ...), and KB, RMM, SLA, CSAT, API and IT in
  capitals; the dashboard tile is "Waiting on Employee" and counts tickets in a status of that name (or the
  upstream "Waiting on Customer"), where it always showed 0 on installs that had renamed the status; the Training
  switch on Settings > Modules no longer calls the kiosk and records "later phases"; the API docs no longer name
  a product they never explain.
- **Setup wizard:** the sidebar step numbers match the step cards (Welcome is not numbered, and step 5 is "Region
  and Language" in both).

### Upgrade notes
- **Docker Compose:** the containers are now named `rivetit-web` and `rivetit-db` and the built image
  `rivetit-web:local`. Run `git pull` then `docker compose up -d --build`: both containers are recreated under the
  new names on the same `itflow_db_data` volume, so the data is untouched. The service keys (`app`, `db`), the
  `DB_NAME` / `DB_USER` defaults (`itflow`) and the volume name are unchanged on purpose. To run two stacks on one
  host, set `RIVETIT_CONTAINER_PREFIX` in `.env`; it prefixes both containers and the image tag. **A host that
  already runs two or more stacks** must set a different prefix in every stack's `.env` but one **before** its
  `docker compose up`, otherwise the second stack stops with "container name /rivetit-db is already in use".
  Scripts should use `docker compose exec app|db ...`.
- **Installer secrets:** `scripts/setup_cli.php` reads `RIVETIT_DB_PASSWORD` / `RIVETIT_ADMIN_PASSWORD`. The old
  names `ITFLOW_DB_PASSWORD` / `ITFLOW_ADMIN_PASSWORD` still work and are deprecated; `deploy/install.sh` and the
  Docker entrypoint set both.
- **Unchanged on purpose** (see `REBRANDING.md`): the `ITFlow\` PHP namespace, `itflow_*` functions and
  `itflow*.css` files, database, table, column and config names, session / cookie / browser-storage keys, webhook
  signature headers, Odoo write-back markers, key-derivation labels, backup file names, the mail folder the ticket
  parser files messages into, and the server-level names the deploy scripts use (`/var/log/itflow-*.log`,
  `/etc/cron.d/itflow*`, `itflow-backup.service` / `.timer`, `/etc/itflow/backup-passphrase`, the `itflow-auth`
  fail2ban jail, the `itflow_login` nginx zone and the `99-itflow-hardening` drop-ins). Re-running
  `deploy/harden.sh` or `deploy/install.sh` on an existing box finds everything where it was.
- **Updates** still come from `TheTractorHacker/ITFlow-Internal-IT` (branch `main`): the check compares with the
  `fork` remote and **Update App** / `deploy/update.sh` pull from `origin`, both that repository; it has not moved.
  When it does, re-point both remotes (`git remote set-url`). The optional telemetry still reports to the upstream
  ITFlow endpoint. *(Both since changed — see the entry above: the repository moved to `TheTractorHacker/RivetIT`
  and telemetry was removed entirely, not left pointed upstream.)*
- The Android companion app keeps working unchanged (same `/api/v1`, same package id); renaming the app itself is a
  separate follow-up in its own repository.

## [Unreleased] ITFlow Internal IT - Training: continue from the last point
Database 2.6.98 -> 2.6.99: `training_runs` gets `trun_lesson_resume_at` (nullable). Nothing is backfilled: runs from
before the update have no last point and behave exactly as before until the learner next plays or turns a page. Apply
it through **Admin > Update > Update Database**. `training_runs` has no row hash and no ledger event reads the column,
so the ledger verify is unaffected. Owner report 2026-09-27: "There is no continue from last point" - reopening a video
went to the furthest point reached (or, since the honest-progress change, to 0:00 when that was the end), so where the
learner actually stopped was lost.

### Fixes
- **The kiosk remembers where you stopped.** The run keeps the learner's last point in the lesson they are on: the
  video position (YouTube / Vimeo and uploaded videos) or the PDF page on screen. It is saved on pause, every 10 s while
  a video plays, when the screen goes to the background or the page is left, when **Course** is pressed and before
  **Done** signs out - so it is never more than a few seconds old, on this kiosk or any other one.
- **Reopening resumes there.** A video opens with **Resuming at 2:13 · Start over** and plays from 2:13 (YouTube /
  Vimeo jump there on the first play). A PDF opens on the last page viewed: **Picked up at page 4 · Back to page 1**.
  A last point in the last few seconds of a video with too little time counted starts at 0 with the end-of-video
  message and **Watch from the start**, as before. Runs from before the update keep the old rule (the furthest point;
  the first unseen page) until a new point is saved.
- **"Continue at 2:13"** ("Sigue en 2:13") on the Learning Center card's button, on the lesson in the course list and
  on the YouTube / Vimeo lesson card; the course page's main button says **Continue: <lesson>** instead of **Start
  course** once a video is under way.
- Navigation only: the last point never counts as watched time, is never past the furthest point reached, and the
  credit, gate and quick-check rules are unchanged. **Start over** / **Back to page 1** work as before; Preview as
  learner keeps its own last point the same way.
- The kiosk YouTube page no longer loses track of a playing video after YouTube buffers (the jump to the last point
  buffers): watch time keeps counting and the play button shows Pause.
- Reset progress, a restart, a new run in another language and completing the lesson clear the last point with the
  rest of the lesson's progress.

## [Unreleased] ITFlow Internal IT - Training videos: honest watch progress
No database change and no server change: the credit rules (time counts only while the video plays with the kiosk page
showing, 90 % / the lesson's minimum, the furthest point bounded per tick) and the quick-check gating are exactly as they
were. Owner report 2026-09-27: a YouTube lesson's ring said "Watched 100% — the quick check is next" while the bottom
bar said "Keep watching: about 3:41 more" - the ring showed the furthest point reached, and the video had kept playing
with the kiosk page in the background, where no time counts.

### Fixes
- **The ring shows counted time.** On the YouTube / Vimeo page, the kiosk course page (uploaded videos and the
  YouTube / Vimeo card) and Preview as learner, the watch ring and its words show the time that counted against what is
  required ("2:18 of 5:59 watched", "About 3:41 more to watch") and reach 100 % only when the lesson can be finished.
  The progress bar's lighter shading is still how far one may move, now captioned "Furthest point reached".
- **Paused in the background.** The video pauses when the page is hidden (another app, a minimized window, a locked
  screen); back on the page: "Paused while this screen was in the background. Time only counts while you watch here."
  It never resumes by itself.
- **A clear way on at the end.** When the furthest point is at the end but not enough time counted, the bottom bar says
  "You reached the end, but only 2:18 of watching counted. Watch about 3:41 more — any part of the video counts." with a
  big **Watch from the start** button (also on the finished-video box, which no longer shows a green tick then).
  "Resuming at…" is no longer offered in the last few seconds of a video; it starts at 0 with that message instead.
- Preview as learner shows the same, from its own count of seconds played on the showing page (kept with its progress).
- Review fixes: the end-of-video message and **Watch from the start** no longer flash up in the last seconds of an
  ordinary watch (a short or high-percentage video) or on the finished video before the server has counted its last
  seconds; the finished video gets its green tick only once the lesson can be finished. On an iPad, where a YouTube /
  Vimeo player starts only from a tap inside it, **Watch from the start** on a freshly opened page keeps the message
  and highlights "Tap the video to start" instead of silently doing nothing. A pause that did not reach the player
  is sent again while the page stays in the background. Spanish: "solo se contaron".

## [Unreleased] ITFlow Internal IT - Training videos: volume, closed captions (CC), pick up where you left off
Database 2.6.97 -> 2.6.98 (runs **after** the 2.6.96 -> 2.6.97 step of the Odoo skill/note branch): `training_media`
gets the kind `caption`, `training_lesson_variants` gets `lvar_caption_media_id` (+ index). Nothing is backfilled and
existing revisions are untouched. Apply it through **Admin > Update > Update Database**; until then the caption row in
the course builder stays hidden and caption uploads answer "needs the latest database update". Volume and resume need
no schema. No nginx change is needed (PHP sends `text/vtt` and nginx keeps it on the X-Accel redirect); the repo nginx
templates list `vtt` anyway.

### New Features & Updates
- **Volume** on every lesson video (uploaded MP4 and YouTube / Vimeo): Mute plus a volume slider, Up / Down arrow keys
  on a PC (+-10 %). On iPhone and iPad only Mute / Unmute is shown, with "Louder: use the iPad's volume buttons": iOS
  ignores a web page's volume, the side buttons set it. The level is remembered per device (80 % when nothing is
  stored or the browser blocks storage); signing out of the kiosk undoes Mute, so the next person starts with sound.
- **Closed captions (CC).** In the course builder a video lesson's uploaded MP4 takes one caption file per language
  (.vtt or .srt, up to 1 MB). The server rebuilds it as plain-text WebVTT: SRT converted, UTF-8 / UTF-16 / Windows-1252
  read, every tag, script, style block, web link and control character removed, timings checked. Publishing pins the
  file with the version, and the kiosk serves it only for that version, like the video. Learners get a **CC** button;
  captions start in the language of the course they are taking, with **EN | ES** when both languages' captions fit
  the same video (joined to the CC button), in large white-on-black text that also shows in full screen. Someone
  taking a course in Spanish on the English video gets Spanish captions ON until they choose otherwise. YouTube /
  Vimeo lessons use the video's own captions through the player (a short "No captions for this video" note when Vimeo
  reports none; YouTube lists its captions only once they are switched on). On the kiosk the CC choice lasts for the
  signed-in session (stored under an opaque per-session id, never a name) and is cleared at sign-out; in Preview it
  is remembered per device. In the builder, a language without its own video gets **Use the English video** (only
  the video is shared; the translated text stays), then that language's caption file. A caption file is dropped
  when its video is removed or the lesson switches to YouTube / Vimeo, which use their own captions.
- **Pick up where you left off.** Reopening a started video (after going back to the course, a reload, or signing out
  and back in) opens at the furthest point the run recorded, with **Resuming at 3:42 · Start over** on the picture
  (YouTube / Vimeo: in the bar above the player; they jump there on the first play). The video's buttons share one
  row with the time under the progress bar, and the picture is made shorter when needed, so play, CC and Mute stay
  above the bottom bar on an iPad in landscape and on a 768 px tall PC screen. A PDF reopens at its first page not yet seen (**Picked up at page 4 · Back to page 1**).
  Preview as learner does the same from its own saved progress and records nothing. Credit rules are unchanged:
  reopening credits nothing and skipping past the furthest point watched stays blocked.
## [Unreleased] ITFlow Internal IT - Training Odoo write-back: certification skills and HR notes
Database 2.6.96 -> 2.6.97: `training_automation` gains three switches (`tauto_odoo_send_resume` on, `_skill` and `_note`
off) and `tauto_odoo_skill_label` (the name of the chosen certification type and level), and the `training_odoo_outbox`
unique key gains the target (`uq_training_todoo_source_mode`), so one training record has one outbox row per target and action. Apply it through **Admin > Update > Update Database** (one click); write-back
stays off and nothing is queued or sent by the update. Until it runs, write-back keeps working as résumé lines only.

### New Features & Updates
- **Send training records to Odoo as a résumé line, a certification skill, an HR note, or any combination**
  (Admin > Training > Odoo write-back; administrators only; résumé line stays the default). Each one has its own
  retries, its own "Odoo keeps refusing" stop, its own duplicate check and its own undo on a void; the employee-link
  check, the name check, the Odoo pinning, "Send to Odoo" per course and "training courses only" apply to all.
- **Certification skill**: an Odoo employee skill of a certification type ("Certifications > Certified"), valid from the
  completion date to the expiry. Choose the type and level (found by **Check Odoo**), then map each course or achievement
  to an Odoo skill under **Send to Odoo**, or click **Create in Odoo** to add a skill named after the course (the only
  button here that writes to Odoo; a skill with exactly that name is reused). Courses without a skill are not sent as
  certifications, and the outbox card lists them. A void (or "Reset (take again)") ends the certification the day
  before (Odoo's own convention), or on the void date when it started that day. Odoo has no "revoked" mark, so a voided
  certification looks like an expired one (the card says so; turn on HR notes for the explanation). A renewal adds a new
  certification; Odoo keeps the old one. Odoo refuses two identical certifications (same skill, level and dates): that
  is shown once with the reason and a "nothing to fix, Skip it" hint, never retried in a loop. If Odoo has no
  certification type yet, the card says exactly what to create. The values of each certification are saved before it is
  sent, so a retry after a lost Odoo answer finds it even if the course's skill or the level was changed in between.
- If the chosen certification type is archived or deleted in Odoo, only certifications pause (the card names the
  missing type and level and asks to choose again; the list shows "Choose…" instead of another type); résumé lines,
  HR notes and voids keep going.
- **HR note** (Odoo 19 or later): an internal note in the employee's Odoo chatter (course, completion date, certificate
  number, expiry, how it was recorded, "Record of truth: ITFlow"; never a score, link or PDF). It goes to nobody: no
  recipients, the employee's followers are skipped (also those following notes), ITFlow's Odoo user never becomes a
  follower, and no out-of-office auto-reply is triggered. A void adds a short follow-up note naming the course; the
  first note is never edited or deleted.
- Outbox card: counts per target, "Sent as" on the card and in Needs attention and the preview; the last run and
  Training 3's read-only summary say per target what was sent, retried, could not be sent or waits for an employee link.
  Held counts and admin notifications count training records, not one per target.
- A void of a record that already reached Odoo is sent before waiting new records, so a backlog cannot hold it back.

## [Unreleased] ITFlow Internal IT - Training (LMS) Phase 5: public certificate check, certificate and transcript PDFs, reminder digests, video checks, Odoo write-back, item analysis
Database 2.6.95 -> 2.6.96: five new operational tables (`training_automation` with its one settings row,
`training_odoo_outbox`, `training_odoo_map`, `training_reminder_log`, `training_video_watch`); nothing hashed or
ledgered. Apply it through **Admin > Update > Update Database** (one click), then check that the version reads 2.6.96
and `training_automation` has one row. New agent actions `insight_items`, `insight_items_csv`, `insight_revisions`
(Training 2). New cron script `cron/training_worker.php` (see Ops below). Everything that acts is **off** until an
administrator or Training manager switches it on; external video checks are on (they only alert).

### New Features & Updates
- **Public certificate check** at `/verify/?t=...`, the address the QR code on every certificate already points to.
  Anyone who scans it sees Valid, Expiring soon, Expired or Revoked with only the name, course, issued and expiry
  dates, certificate number and "External card recorded" (never the score, department, issuer or void reason), or
  Not found / Try again shortly / Not available. A revoked certificate shows no expiry and says not to accept it; a
  page with no record details says whom to contact. English and Spanish (browser language or a link). No login, cookies
  or scripts; strict security headers; nothing cached. Checks are limited to 240 a minute overall and 20 a minute per
  visitor (an IPv6 visitor counts as its /64), counted before any database lookup; a visitor over its own limit does
  not use up everyone else's, and the counters are a fixed set of small files. Each record's fingerprint is re-checked
  before a status is shown: a record that no longer matches shows "Not available" and administrators get one alert a
  day. An administrator can turn the public check off (Training settings > Certificates); while it or Training is off
  every code shows "Not available: online certificate checks are turned off".
- **Certificate PDF** (Letter landscape, English/Spanish, QR code, signatory, REVOKED / SUPERSEDED watermarks,
  outside cards and paper records as a "Training record" sheet that never says "certifies"; acknowledgments have no
  certificate) and **transcript PDF** (Letter portrait, qualifications, open assignments, history with struck-through
  voids and "Revoked ... by ...: ..." lines, achievements, evidence legend, "Page X of Y" and the records-ledger
  footer). **Download PDF** on the transcript; **PDF (English)** and **PDF en español** on the certificate and record
  pages. The PDF words how the record was proven exactly like the on-screen certificate ("PIN attestation" unless the
  employee drew a signature). Department scope applies as on screen, and any refusal is a plain "not found". Exports are
  logged; PDFs are never stored on the server. The evidence legend's B line now reads "trainer and employee signed, or
  the employee confirmed with a PIN" (it also covers online courses confirmed with a PIN).
- **Training settings** (still one page, Admin > Training and Training > Training settings for Training level 3) gain
  two sections, also found by the settings search:
  - **Certificates**: signatory name, title and signature image (PNG/JPEG, re-encoded and size-capped), a sample
    certificate, and the public check switch (administrators only; read-only for Training 3).
  - **Reminders & automation**: reminder digests, external video checks, Odoo write-back (administrators only; a
    read-only summary for Training 3) and the automation worker's last runs (the schedule lines for administrators).
- **Reminder digests** (off by default; Training 3 or an administrator switches them on and picks the weekdays): one
  in-app notification a day per person with Training access, listing overdue, due-soon and renewal-due training for the
  departments they can see; Training 3 and administrators also get an escalation for people overdue longer than N days
  (default 14). "Preview today's digests" shows what would be sent without sending. No email.
- **External video checks** (on; they only alert): once a day the YouTube and Vimeo videos in published courses are
  checked. A video that is private, removed, not embeddable, live or changed length on two checks at least an hour apart
  alerts the course's responsible person (Training 2 or higher) and Training 3 / administrators once; "Check now" on
  the card. The builder's own video checks are not touched.
- **Odoo write-back** (off by default; administrators only): training records become résumé lines ("Training" type)
  on the linked Odoo employee, with the certificate number, how it was recorded, the expiry and "Record of truth:
  ITFlow"; a voided record's line is closed ("(revoked)" and an end date); achievements can be sent too (each one
  switched on), and courses can be opted out. Nothing is ever deleted in Odoo. It pauses by itself on a changed Odoo
  address, a non-https address, a refused key or configuration errors, and holds a record whose employee link is
  flagged or whose Odoo name no longer matches. Only résumé lines the integration's own Odoo user created are ever
  treated as ITFlow's (a line someone else adds with the same reference is ignored). Check Odoo and write-back need an
  https:// Odoo address. Enabling needs Check Odoo, a staging acknowledgement on staging, and on production a fresh
  employee-link check. The Odoo copy is not evidence: employees with Odoo logins can edit their own résumé lines.
  The card shows the outbox (retry / skip), a dry-run preview and the "Send to Odoo" lists. Administrators are warned
  before the Odoo API key expires (from 14 days out).
- **Item analysis** (Training 2 and up, your departments only, no names): per question % correct, discrimination
  (Good / Fair / Weak / Check key), most-chosen wrong answer, answer spread and version changes, with filters for
  version, language, quiz type (final exams and quizzes, lesson quick checks, or all) and period, CSV export and print.
  Discrimination and flags are hidden below 10 answers. **Compare versions** shows two published versions side by side
  with what changed. Linked from Course analytics ("Full item analysis").
- **Notifications**: a new **Training** push category (Training Digest, Training Escalation, Training Video) so people
  can mute them on their phone. Odoo write-back problems ("Training Odoo") and records-integrity alerts ("Training")
  are always pushed. Training notification types now reach only roles that hold Training (the Training kiosk permission
  still receives "Training"); Administrator and Technician are unchanged.

### Ops (after the database update; nothing is installed by the code)
- Create the log first (a missing log file silently stops the job):
  `sudo install -o www-data -g adm -m 0640 /dev/null /var/log/itflow_mw_training_worker.log`
- Dry runs as www-data: `sudo -u www-data php /var/www/mw-itflow.foleyit.com/cron/training_worker.php --task=daily --dry-run --force`
  and `... --task=odoo --dry-run` (prints nothing while write-back is off).
- `/etc/cron.d/mw-itflow-training-worker` (root, 0644): `--task=odoo` every 10 minutes and `--task=daily` at 05:40,
  both as www-data, appending to the log above (exact lines in the file header of `cron/training_worker.php`).
- `/etc/logrotate.d/itflow-mw` for `/var/log/itflow_mw_*.log` (weekly, rotate 8, compress, delaycompress, missingok,
  notifempty, create 0640 www-data adm, su www-data adm); check with `sudo logrotate -d /etc/logrotate.d/itflow-mw`.
- No nginx change: `/verify/` is served by the existing `location /` and PHP handling.

## [Unreleased] ITFlow Internal IT - Training assignments: Reset and Un-waive
No database change. New agent actions `assignment_reset_preview`, `assignment_reset`,
`assignment_retake`, `assignment_unwaive`; new ledger event types `assignment.progress_reset`, `assignment.retake`,
`assignment.unwaived` and `run.reset`.

### New Features & Updates
- Assignments, the assignment history and the transcript have **Reset progress…** on open assignments (Training 2): it
  clears the person's kiosk progress on that course (the dialog lists what goes: started date, progress, current lesson,
  a passed exam, failed tries, a lock or block, waiting to sign or for a trainer, and warns when they only had to sign
  or are already overdue), optionally with a new due date. Their next kiosk sign-in starts at lesson 1 with fresh tries
  and no lock; the old tries stay in the history. Someone who hasn't started gets "Nothing to reset"; right after a
  reset it says "No kiosk progress to clear right now (last reset ... by ...)". A kiosk that has the course open says
  "Your progress on this course was reset by your supervisor. Start again when you're ready." (or in Spanish) and goes
  back to the course list; someone who was not on the kiosk sees the same on their Learning Center and on the course
  until they start it again.
- **Reset (take again)…** on a completed assignment (Training 3, like voiding a record): voids its record (kept as
  Voided, certificate stamped VOID) and gives the person a new assignment with the due date you pick ("Take again
  (record ... voided)"; its history says who voided which record and why). Sessions, practical evaluations and kiosk
  work from before it no longer count toward the new record. Only offered while that record is the one that counts and
  voiding it would assign the course again; otherwise the dialog says why. The person's Learning Center says "Your
  ... record from ... was voided by your supervisor. Please take it again by ...".
- **Un-waive…** on a waiver that is still running (Training 2): "They'll need to take this course again. The waiver
  stays in the history." The assignment reopens with the due date you pick (default: its due date or two weeks from
  today, whichever is later). When the rules now ask for something else (a renewal, say) that opens instead, and the
  dialog warns when their last record already expired; when nothing is required any more the waiver just ends and says
  why (the history keeps the reason). Expired waivers are not offered.
- History reads "Progress reset by ...: ...", "Waiver ended by ...: ...", "Record voided and reassigned by ...: ...".
  An ended waiver shows as "Waiver ended", a record voided later as "Record voided". The assignments CSV writes close
  reasons in words, and "Cancelled while overdue" leaves out ended waivers. The transcript says "Due date moved" when a
  due date was moved earlier.
- Department scope applies as everywhere (someone outside your departments is "not found"); archived people cannot be
  reset or un-waived; a second click or a stale page gets a friendly message, never a second change. Recalculate now
  and the nightly check leave all of it as it is.

### Fixes (review)
- Take again predicts the reassignment exactly as the nightly check does (a newer voided record of the pair, a rehire
  start date), applies your due date to whichever redo opens, and voids the record and clears leftover kiosk progress
  in one step, so nothing signed from before the void can close the new assignment.
- A record is never issued from kiosk progress an agent reset a moment earlier, and a timed exam left open on a reset
  run earns no achievement.

## [Unreleased] ITFlow Internal IT - Roles: module-only logins contained, checked pop-ups and pages, Assets module, Training settings for Training level 3, clearer role editor
Database 2.6.94 -> 2.6.95 (new module `module_assets`; every existing role is granted it at its current Tickets/assets/docs
level, so nobody's access changes). Apply it through **Admin > Update > Update Database**; until it has run, asset pages
keep working through Tickets/assets/docs. Phase 5's planned migration moves to 2.6.96.

### Security
- A module-only login (a role with none of Departments, Tickets/assets/docs and Assets - e.g. Training only) now gets
  only its own modules' pages, its account and its notifications. Everything else answers 403 with a "Go to Training"
  page (JSON for pop-ups and ajax). Its sidebar shows only its modules (no Dashboard, Work or custom links), the logo,
  sign-in and `/` go to its own home, `dashboard.php?home=1` no longer shows the dashboard, the search box is hidden,
  and notifications (bell, page, phone push, app API) carry only its modules' types. Its app API token may use only
  `me`, `notifications` and `kb`/`reports`/`alerts` for modules it holds.
- Checked for everyone: search (page and live) section by section; contact and asset details, company-wide People,
  contracts and onboarding/offboarding runs; the calendar (Tickets/assets/docs, each built-in feed by its module; the
  Work group only with Tickets/assets/docs); adding/editing/deleting calendar events, adding tags and categories,
  turning off shared links and ticket-viewer tracking; dashboard widgets by module with ticket widgets scoped to the
  user's departments; department overview cards; app API search, dashboard and v2 workflow runs.
- Denials are HTTP 403 with a proper page in the app shell and no leftover "not permitted" message on the next page;
  the admin area's denial too. Opening the Credentials page no longer logs a view before the permission check.
- Sidebar: Trips sits inside the Finance check, Intune Devices needs Departments.
- Every agent pop-up checks its module first (`includes/modal_permissions.php`, a folder/file map); a refused pop-up
  answers 403 and the loader shows the reason. Pages hide the buttons of pop-ups the role can't open (contact and asset
  "New" menus, New Contract, the departments bulk "Open Tickets", the asset list's contact card for a role without
  Departments).
- The Files page and document details need Tickets/assets/docs, like their pop-ups and save handlers (they were the only
  department pages with no module check).
- The asset forms' Login tab (which creates a Credentials record) shows only with Credentials edit access, and the save
  handler ignores it without that access.
- Admin > Roles: the "can't edit / can't archive" protection follows the last administrator role that still has an
  active user (was the literal role id 3), and the server refuses to demote or archive it.

### New Features & Updates
- **Assets** module (levels 1-3): asset pages without tickets. Asset lists, details, edits, imports/exports, inline
  edit, search and the dashboard accept Assets or Tickets/assets/docs. With Assets alone, an asset's linked tickets,
  documents, files, licenses and services are not shown.
- **Training settings for Training level 3** (`agent/training_settings.php`, Training > Training settings in the sidebar
  for non-admin Training level 3; admins keep Admin > Training, which is unchanged). Training level 3 edits course
  defaults, compliance defaults, Recalculate, Snapshot and ledger Verify; kiosk sessions and setup slips also need
  Training kiosk level 3. Media limits, the YouTube key, media purge, Odoo links and sync, PIN lockouts and device caps
  stay admin-only and show read-only with "Ask an administrator". Both pages validate through one shared service
  (`src/Training/Settings/`); admin saves behave exactly as before.
- **Role editor**: permissions in plain words, grouped IT / Business / Training, with per-level help; "Start from..."
  presets (Training Manager, Training Supervisor (department), Learner, Technician, Nothing); a "This role will see"
  sidebar preview; the Assets permission. The user form's Access tab explains how department ticks work in Training.
- App API `GET /api/v1/me` adds `is_admin`, `limited` and `permissions` (every module with its level 0-3).
## [Unreleased] ITFlow Internal IT - Training kiosk: quick checks after lessons
No database change (2.6.94 stays). Quick checks on video, article, document and image lessons now run on the kiosk;
before this, the kiosk skipped them and a lesson went straight to "Done" (and a one-lesson course to "Sign to finish").

### New Features & Updates
- Once a lesson's video, article, document or image is credited, the kiosk shows its **Quick check**: the lesson's
  settings (questions drawn by the server, shuffle, pass mark, unlimited or N tries), one question per screen with big
  answer tiles, the review screen when the author turned it on, then the result - score, pass/fail, missed questions with
  their explanations (correct answers only when the check's feedback setting allows) and **Try again** while tries remain.
  Grading, the answer log and the hashed attempt rows are the same engine as kiosk quizzes and exams (attempt kind
  `check`); the answer key never reaches the kiosk before grading.
- **Not must-pass** (the default): the lesson is done when its content is credited, as before; the check is offered
  right after and can be skipped (**Skip for now** / **Continue**), taken later from the lesson, and its result is recorded.
- **Must pass**: the lesson counts as done only after a pass - progress, lessons in order, the final exam and the
  sign-off wait for it ("Quick check to pass" on the lesson). Out of tries locks the course like an exam (agent **Locked
  courses** says "Out of tries on the quick check of ..."; **Give 1 more try** / **Restart on current version** as usual). A try left mid-way
  resumes with the same questions and the saved answers ("Continue quick check").
- YouTube/Vimeo lessons: **Mark lesson complete** on the video page goes back to the course straight into the check.
- A course run that the old kiosk had already moved to "Sign to finish" while a must-pass quick check was never taken is
  moved back to in progress the next time the person opens the Learning Center, a course or the sign page (ledger
  `run.reopened`), so the check is taken before signing; signing such a run is refused (`check_pending`) until then.
- Quick-check results never change the training record (its score stays the final exam's); the kiosk evidence on the
  record lists every try labelled **Quick check** (and "must pass"), in timeline order.
- Publishing no longer warns "This lesson's quick check is skipped on the kiosk for now" and no longer refuses a
  must-pass quick check. The preview player shows the check after the lesson too (never blocking the author).

### Review fixes
- **Give 1 more try** (agent Locked courses, trainer) now adds the try to the quiz or quick check the course is locked
  on only (ledger `run.unlocked` names it: `lesson_uid`). Before, the extra try counted for every quiz of the run, so
  unlocking a quick check also raised the final exam's limit. Unlocks from before this change still count run-wide.
- An optional quick check can be taken "later" while the course waits for "Sign to finish": the done lesson's row
  opens it (chip **Quick check (optional)**; the row also shows it while the course is in progress).
- Saying the check is coming: lessons with a check finish with **Continue to quick check** (course player and the
  YouTube/Vimeo page), and the status line says "Then a quick check · 4 questions" or "Then pass a 4-question quick
  check" (the watch card: "Watched 100% — the quick check is next").
- A lesson watched/read with its must-pass check still to pass: the Learning Center card says **Continue** with
  "Quick check to pass: <lesson>" (not Start / 0%), the course button says **Take the quick check: <lesson>**, and a
  run reopened from "Sign to finish" says "This course now has a quick check. Pass the quick check for <lesson>, then
  sign to finish."
- Locked course: the notice names it ("Out of tries on the quick check for <lesson>. Your trainer can give you another
  try.") and every lesson row says **Locked · see your trainer** (was "Finish the previous lesson first").
- Must pass with N tries: the check says "If you don't pass in N tries, your trainer has to unlock the course.", with a
  warning on the last try (intro and failed result). The duplicate must-pass footer is gone (Start and **Back to the
  lesson** stay in view at 1024x768).
- The check's result is compact (smaller ring, no stats row, no topic chips, "3 of 4 correct" in the line) so the
  explanations are in view at 1024x768, 768x1024 and 1366x768; a missed check offers **Watch again** / **Read again**;
  an optional miss goes on with **Next lesson**. The footer says "Doesn't go on your training record." or "Quick check
  passed. Sign to finish." (never "Signing adds it to your training record").
- Leaving a check part-way shows **Continue quick check** and the tries note right away (no reload needed).
- Runner: no course name in a check's title, no "Flag for review" on checks of 5 questions or fewer, and the question
  keeps its 20px gap above the answer tiles on the kiosk (all kiosk quizzes).
- Spanish: quick checks are **Prueba rápida** (like the other "Prueba"s), and scores are "puntuación" throughout.

## [Unreleased] ITFlow Internal IT - Training kiosk: temporary and unlisted devices
Database 2.6.93 -> 2.6.94 (`training_kiosks`: `kiosk_asset_id` and `kiosk_asset_type` become NULL-able, new
`kiosk_expires_at_utc` and index `idx_training_kiosk_expires`). Apply it through **Admin > Update > Update Database**;
until it has run the kiosk answers 404 and the cron skips, as before 2.6.93. Phase 5's planned migration moves to 2.6.95.

### New Features & Updates
- Set up this device: **This device isn't in Assets** enrolls a device by name only ("Trainer's laptop", "Borrowed
  iPad"). It is always shared (no personal-device mode), no asset lockout checks apply, and each one is independent of the
  one-device-per-asset rule. The Devices tab and the kiosk evidence on training records show its name and "Not in Assets".
- **Temporary** devices (listed or unlisted): until the end of today, 4 / 8 / 24 hours, or a date and time up to 30 days
  away (app time zone). Past that time the device is revoked on its next request (open kiosk session ended, start URL
  dead, "This device is not set up for training"), and `cron/training_kiosk_cron.php` revokes untouched ones every 10
  minutes with the reason "Temporary device expired" (ledger and audit like a manual revoke, system actor). The Devices tab
  shows a **Temporary** badge and "Temporary · expires ..." (amber in its last hour) / "Expired", with **Change end time**
  (current end, a live preview of the new one, an earlier time flagged as **Shorten it**), **End now**, and **Remove now**
  for an expired device nobody touched yet. A permanent device can get an end time later (**Set end time**). The setup
  success panel shows the end time; every time shows the zone in force at that time. "Until the end of today" needs 5
  minutes left today, and a setup code's device must last at least as long as the code (15 minutes).
- On the kiosk, a temporary device shows "This device: ... · until 3:13 PM" on the sign-in screen and in trainer mode, an
  "Ends 3:13 PM" chip in its last 15 minutes, and afterwards "This device's training time is over" with the time it ended.
- Training records say "temporary device" only when the device was temporary when the person signed (from the ledger),
  and show an unlisted device's number ("Not in Assets (device #12)").
- Same permission as enrollment (Training kiosk level 3). New agent action `kiosk_set_expiry`; new ledger event type
  `kiosk.expiry_changed`. The in-app Setup guide and `docs/training-kiosk-setup.md` have a "Temporary or unlisted devices"
  section.

## [Unreleased] ITFlow Internal IT - Training (LMS) Phases 3+4: kiosk, learner flow, achievement awards, trainer mode
Database 2.6.92 -> 2.6.93 (13 new tables, 21 settings columns). Apply it only through **Admin > Update > Update
Database**; fresh installs get the same schema from `db.sql`. The kiosk answers only while the Training module is on,
and Odoo-PIN sign-in stays off (`config_training_odoo_pin_enabled = 0`) until the owner switches it on.

### New Features & Updates
- Training kiosk at `/kiosk/` for shop iPads and Windows PCs (outside `/agent/`): an admin enrolls the device while
  signed in on it and gets a permanent start URL (`/kiosk/?d=<token>` for the Edge/Chrome kiosk-mode start page; the
  server answers it with a bare redirect to `/kiosk/#d=<token>`, and the nginx rule keeps it out of the access log);
  employees type their name, then their PIN. Local PINs come from printed setup slips; Odoo-PIN sign-in is
  built in behind a switch. Lockouts per person, per device and site-wide, with Clear cooldown / Clear pause.
- Learning Center (required, due soon, documents to sign, my courses, certificates, badges, PIN-change notices), the
  course player with server-credited lesson time, final exams with saved answers, time limits and attempt limits, a
  separate YouTube/Vimeo lesson page, finger signature + PIN sign-off, and a receipt with the certificate number. English
  and Spanish throughout.
- Records: a kiosk sign-off issues the Phase 2 completion (certificate, assignment closed); the record page shows the
  kiosk evidence (lesson time, attempts, signatures). People and departments with kiosk evidence cannot be hard-deleted.
- Achievement awards: automatic badges (course, category, path, course count, perfect score, first-try pass, on-time
  streak), manual badges from the agent page or by a trainer on the kiosk, shown on the result screen, receipt,
  Learning Center and transcript.
- Trainer mode on the kiosk: run a session and pass the iPad around for check-in (signature + PIN), finish with the
  trainer's signature and PIN, practical evaluations with an employee hand-off, check-in from a second device.
- Agent pages: Devices & PINs (enroll, revoke, new start URL, setup slips, unlock), Locked courses (+N attempts or
  restart), Awarded badges. Admin > Training kiosk for idle times, session caps and lockout thresholds.
- Cron: new `cron/training_kiosk_cron.php` (every 10 minutes; see its header for the cron.d line and log file).
- Publishing: a lesson quick check marked "must pass" is refused for now (the kiosk does not run quick checks yet);
  other quick checks publish with a warning.

### Fixes (Phase 3+4 end-to-end QA)
- Kiosk course pages on a portrait iPad (768-834 px wide) now use the player's narrow layout: the video, PDF and article
  side panels move below the lesson, and the lesson and quiz footers wrap instead of cutting off buttons or squeezing
  the "Available after ..." text. The header no longer clips the brand in Spanish.
- A Spanish screen shows Spanish course names and "Pick up at" lesson titles in the Learning Center (cards, completed
  courses, certificates) wherever the course has them.
- Blended courses (a class or hands-on evaluation still to come): the kiosk sign-off statement says the employee
  completed the online part, and the receipt preview no longer promises a certificate and expiry at signing.
- Trainer session header shows the date as "Sep 25, 2026" instead of 2026-09-25.
- Rule editor: "Change on the course" opened a "course no longer exists" page; it now opens the course Settings tab.

### Fixes (Phase 3+4 security and employee-UX review)
- Security: a flood of bogus start-URL adoptions no longer blocks real devices (a valid token is never rate-limited;
  only unknown tokens count). Check-in and hand-off can only be left with the trainer PIN (or a genuine idle timeout):
  `/kiosk/?switch=1` and a direct sign-out are refused there. A trainer needs the evaluate permission, the course and the
  person's department to record a hands-on pass/fail in a session, re-checked when the session is finished. The
  YouTube/Vimeo lesson page runs sandboxed without pop-ups, so the video provider's script cannot open another kiosk
  page. `/kiosk/?d=<token>` is redirected before any page renders; add the nginx rule in the release notes.
- Lessons in an in-order course no longer offer "Next lesson" before the lesson is done (it used to drop people back
  on the course page); acknowledgment lessons have the Sign button in the always-visible footer with a to-do line
  ("Tick the box, sign, then enter your PIN"), and say "Step 1 of 2" when the course sign-off follows.
- Two-language courses ask "English / Español" at the first Start; a course with nothing done yet follows a language
  change; otherwise the course page says why it stays in the language it was started in.
- Course page: a "My training" button back to the Learning Center. PDF lessons fill the width with the page counter
  and thumbnails always visible. Uploaded videos have a big Play button. Exam wording ("Start the exam"), no duplicate
  "Final exam" heading or attempts count, and a failed exam offers "Back to course".
- Sign page: the keypad fits its card, keys stay readable before signing with "Sign first, then enter your PIN here",
  Sign & finish stays on screen, and the signed statement reads "September 25, 2026" / "25 de septiembre de 2026".
- Spanish: the header button is "Salir"; learner messages say "instructor", "examen" and "este dispositivo". Windows
  PCs say "with the mouse" and "Use the arrows to turn pages"; trainer screens say "this device" instead of "iPad".
- Info messages use a neutral blue instead of the theme colour (on a red theme they looked like errors); the company
  logo shows in the kiosk header; courses without a cover get their own colour and initials; chips, labels and the
  signature hint are larger and higher-contrast; the "Still there?" seconds count down.
- Sign-in: on a touch screen the heading shrinks while typing a name so results stay above the on-screen keyboard;
  on a PC, Enter picks a single match and digits typed before the keypad appears are kept. After Done, the sign-in
  screen goes back to the default language. Check-in says "Type your name to check in" when there is no list and no
  longer shows the trainer's name in the header; evaluation results read "Passed" / "Did not pass".

## [Unreleased] ITFlow Internal IT - Training (LMS) Phase 2: assignments, compliance, records and reports
Database 2.6.91 -> 2.6.92. Apply it only through **Admin > Update > Update Database** (the migration block in
`admin/database_updates.php`; fresh installs get the same schema from `db.sql`). Nothing changes for users until the
Training module is on.

### New Features & Updates
- Training: requirement rules (department, Odoo job, Odoo work location, job group, specific people, or everyone on the
  roster; new hires only; due-date policy; live preview) and an assignment engine that opens, renews, reopens, reissues
  and closes assignments from the records (nightly, and after every rule, roster, record or directory change).
- Training: completion records with LMS certificate numbers and verify tokens, office entry of external cards and paper
  records with an evidence scan, practical evaluations, attended sessions with an attested, digest-frozen finalize, void
  with reissue, and hard-delete protection for people and departments with records.
- Training: overview dashboard (KPIs, department x course heatmap, overdue ageing, expiring 30/60/90, trend), reports
  with CSV (matrix, overdue, expiring, course analytics, document acknowledgments), transcript, printable certificate
  with QR, and a Training panel on the contact page.
- Training: Assignments, People (roster, job groups, trainers, hire dates), Records & sessions pages; side-nav overdue
  badge and dashboard chips; training-only roles land on the Training overview.
- Contacts: the Add Contact form has a Start Date (hire date), as the edit form already did; with Training on, a new
  contact is matched against the assignment rules as soon as it is saved.
- Admin > Training compliance: compliance defaults, the opt-in hire-date fill from Odoo, Odoo employee link check and
  resolution (a changed Odoo database blocks the directory sync until the links are checked), Recalculate now,
  Capture today's snapshot, and the nightly Odoo directory sync switch (off by default).
- Cron: `cron/training_cron.php` also reconciles assignments and captures the daily compliance snapshot;
  new `cron/odoo_sync_cron.php` (inert until switched on).
- Training: department job groups. Every active department gets its own job group, created and kept in step for you
  (renamed, archived and restored with the department). Its people are the department's contacts right now, so a new
  hire or a department move takes effect at once. A rule on a department group matches the same people as a
  Department condition, also after the department is archived. People › Job groups now has two sections, "Your
  groups" (with New job group) and "Department groups" (view-only; each lists its people, links to the department,
  lists the rules that use it and offers "New rule for this group"). Users limited to some departments see only
  their departments' groups. The rule editor's group picker groups them under headings with the same people counts
  as the tiles. The Microsoft and Google directory syncs now recalculate assignments right away, as the Odoo sync
  does. No database change: the link is a reserved row in the job-group titles table that no real job title can match.

## [26.05] Stable Release
### Bug Fixes
- Stripe Payment: Fix adding saved cards on client portal.
- Various client and module enforments fixes. 
- Projects: Fix slow load by using an optimized query to count tickets and tasks.
- Show correct currency for the account balance when adding payment to invoice.
- Expire all Password reset tokens nightly with cron.
- Shared Items via secure link: Do not delete shared items that have not been viewed before cron runs.
- Client: Fix Client Abbreviation being converted to an int on edit.

### New Features & Updates 
- Bump TinyMCE from 8.4.0 to 8.5.0.
- Bump TCPDF from 6.11.2 to 6.11.3.
- DeBump stripe-php from 20.0.0 to 19.4.1.

## [26.04] Stable Release
### Bug Fixes
- Racks: Fix Device Removal.
- Table Lists: replace class table-responsive-sm with just table-reponsive was causing ui issues with certain screen sizes.
- Client: Fix Edit erroring on certain characters.
- Category: Fix Add/Edit due to missing CSRF fields.
- Category: Fix Restore function and Icon and text color.
- Invoice: Do not apply late fee on first overdue reminder (1 day).
- Ticket: Fix issue with contact not being added with Add contact modal v1.
- Quote: Fix Copy was missing client.
- API: Don't set client ID from POST - this is properly done via require_post_method instead only if it's an all-clients key.
- API: Prevent error 500s when existing data can't be cleanly re-inserted to database.
- API: Add more helpful errors.
- API: Fix asset read uri_2 field.
- API: Various other field fixes.

### New Features & Updates 
- Categories: Add Description Field.
- Categories: Add DB Field for order.
- Categories: Move Asset Status and Network Interface Type to categories so custom ones can be created and edited.
- Categories: Moved note type, software type, rack type to be creatable/editable Categories with common defaults and descriptions
- Files: Allow .swb file for MikroTik Backup Files.
- Software: Added additonal License Types including Perpetual, Site, etc.
- API: Invoice Items: Add read endpoint.
- Networks: Added Import.
- Bump TinyMCE from 8.3.2 to 8.4.0.
- Bump stripe-php from 19.4.1 to 20.0.0.

## [26.03] Stable Release
### Bug Fixes
- Ticket Templates: Fix Task Sortinhahahg.
- Ticket: Lower autoclose setting minimum value from 48 to 24 Hours.
- Ticket: Fix Task Approval.
- Recurring Ticket: add empty value placeholder for Ticket Frequency.
- Documents/Files: Fix redirect after File Upload to redirect to files instead of the non existent documents.
- Setup: Fix base url tacking on /setup when not installing via script.

### New Features & Updates 
- Clients: Net Terms: Added common 45 and 15 Days, removed 14 Days not as common.
- Clients: Bulk Action Set Net Terms Added.
- Clients: Swapped location and contact column, add PopOver with Details such as created, abbreviation, DB ID instead of taking up space underneath client, rounded tag pills and increased padding, removed info badges and added one info badge that displays a popover with details.
- Clients: Added New Ticket to Client Top Header Menu.
- Clients: Client Overview: UI Sprucing.
- Invoice: Send reminder 1 day after due date.
- Invoices/Quotes/Recurring Invoices: Split Items tables into their own POST logic and Modal UIs and tables (quote_items, invoice_items, recurring_items).
- Tickets: New Ticket Parsing - Anyone CC'ed onto the original email that created the ticket is added as a ticket watcher.
- Ticket/Quotes: Quotes can now be associated with a ticket.
- Networks: Removed Subnet Mask Field, Use CIDR instead.
- Networks: Rearranged fields, Updated placeholders, Add/Edit/list for better flow.
- Networks: Renamed DHCP to IP Range to allow for you use of both DHCP and or Usable IPs.
- Assets: Rearranged fields, Updated placeholders, Add/Edit/list for better flow.
- Assets: Added IPv6 if available under IP, Make and Model are now one line with Serial Underneath. Added OS under Type. use pill for status.
- Calendar: Event thats are cut off can now be viewed as a tooltip on hover.
- Calendar: Renamed System Calendars to built-in calendars and added the names and color dot for reference.
- Calendar: You can now delete a custom calendar.
- Report: Client Ticket Time Detail Audit: Selectable Billing Time Increment, will later be avauilable globally.
- Roles/Permissions: Now complete and is out of beta all permission roles are strictly enforced, except for in Trips and Calendar, new enforce modules will be added for these at a later date.
- Project Templates: Ticket Template order can now be dragged and dropped.
- Global: Introduced new checkbox class to all Checkbox select columns to keep consistency and reduce space and enhance ui.
- Global: CSRF Checks everywhere instead of just deletion calls.
- Global: Renamed the rest of the unarchive post and label calls to restore.
- Files: Allow upload of .unifi extension.
- Bump Libraries:
  - stripe-php from 19.0.0 to 19.4.1.
  - fullcalendar from 6.1.19 to 6.1.20
  - TCPDF from 6.10.1 to 6.11.2

## [26.02.1] Maint Release
### Bug Fixes
- Credentials: Fix Password Generator.
- Calendar: Restrict Events for client restricted agents. 
- Ticket Merge: Fix.
- Asset Transfer: Fix.
- Ticket Listing: Restrict Tickets presented in ticket list view from client restricted agents.
- Ticket Details: Deny access to client restricted agents to view tickets without client_id in uri.
- Tickets: Allow agents with restricted client access to view and edit tickets without a client.
- Ticket Change client: Limit selection for agents with restricted client access.
- Ticket Details: Don't display updated at when null.

### New Features & Updates 
- Report: Added Client Detail Auditing.
- API: Added Endpoint to retrieve time worked by agent.
- ajax-modal: Revert to previous JS implementation before 26.02 release.
- Ticket: Move Subject from Ticket main ticket header to ticket details card header.

## [26.02] Stable Release
### Bug Fixes
- Mail Parser - Do not automatically send new ticket notifications to noreply/donotreply addresses.
- Ticket: removed newline \n on Parsed emails.
- Show Trips for everyone if accounting module is enabled.
- Fix Invoice Exporting.
- Fix Billable Column not sorting correctly in tickets.
- Fix Login flow where user agent and client user exists and agent has MFA but will not let them continue.
- Fix passing missing user_id var in client portal.
- Fix Ticket Templates not auto filling when selected.
- Fix Invoices not being sent to all billings contacts when manaully sent.
- Fix Documents and Files not able to be bulk deleted.
- Fix Role Archiving, can be archived as long as no users are assigned to the role.
- Fix showing Powered By ITFlow visibility on the login screen when Whitelabel is enabled.
- Missing username in audit log on successful login due to missing passed user_id to logging.
- API: Fix updating all documents instead of the intended document.
- Documents: Fix Document created at not showing the correct creation date of the master document.
- Ticket: Fixed Using edit ticket modal agent was not able to be set.
- Always check if a user is archived and or disabled instead of just during login.
- Report: Fix Collected tax report not totalling all tax categories.

### New Features & Updates 
- Task Approval System for ticket tasks: Once an approval is requested, the task cannot be marked as complete until approved. Internal Approvals Any other technician, or Specific technician, Client Approvals Anyone (usually the requestor) Tech contacts Billing contacts.
- Printable Invoice Packing Slips now available.
- Drastic Performance Bump: Up to 50% faster queries accross the board and reduced server memory usage by 40% by switching Database Query method from mysqli_fetch_array to mysqli_fetch_assoc.
- Added Connect to Microsoft 365 Button to mail settings.
- OAUTH2 support for Microsoft 365 and Google Workspaces is now considered stable and working.
- Favorites: Assets and Credentials now can be favorited singly or by Bulk action. Favorited items appear in the client overview now.
- Files/Documents: Collapsable folders feature, collapsed by default with a button to expand all.
- URL Keys and such are now set to a more manageable 32 Characters by default.
- Various UI/UX Updates throughout the app, with focus oin ticket details, contact details modal etc.
- Added Show Archived files and documents to the files section.
- Added Bulk Archive and restore options to files and documents.
- Rewrite of the Kanban Ticket view to match our procedural style of coding.
- All options are available in TinyMCE now in Mobile mode.
- Agent names appear now in Invoice History section.
- Mail Parser: Support flowed text.
- Assets: Keep Purchase reference when copying.
- Assets: Add basic tracking history: Archiving, restoring, name changes, transferimg to new clients.
- Mail Parser: NDR Parsing.
- Allow SVG files in mail attachments.
- Tickets: Use a more friendly time worked instead of 02:41:00 translates to 2h 41m.
- Update wording on ticket to invoice item details.
- Merge Tickets: Now wth a ticket merge dropdown list of tickets instead of a text field.
- Role Permissions can now be set during role creation, update Permission UI to use radio buttons instead of select boxes.
- Bump TinyMCE 8.2.2 to 8.3.2.
- Bump PHPMailer from 7.0.1 to 7.0.2.
- Bump Datatables from 2.3.4 to 2.3.7.

## [25.12.1] Maint Release

### Major Changes
- Unified the Client/Agent Login and process (Note only Client Users can Reset passwords from the login page, does not apply to agent users).

### Bug Fixes
- Fix Payment Provider not adding an account.
- Fix New ticket button in contact details in the related tickets section.

### New Features & Updates
- You can now Set Payment Provider income/expense account, expense vendor and expense category upond creation or editing.
- Moved Saved Payment Provider Methods away from admin side nav to the count link within Payment Providers page.
- Moved AI Models from the admin side nav to the model count link within AI Providers.
- Add Favicon Reset.

## [25.12] Stable Release

### Breaking Changes ###
- For Existing installs: **php-xml** extension needs to be installed for document creation and editing, new install script does this for you as of Dec 6th 2025. To install php-xml: `sudo apt install php-xml`

### Major Changes
- Consolidated "Files" and "Documents" into a single section called **Files**.

### Bug Fixes
- Resolved issue with updating asset notes in asset details.
- Fixed problem with bulk ticket merging.
- Corrected issue where decimal inputs (e.g., price, cost) weren’t displaying on iPhones in certain forms.
- Added CSV escaping to the sample export data in areas where a sample CSV template is provided.
- Fix a race condition where dupe tickets, invoices, recurring invoices, recurring tickets, quotes will be created using the same number if created in parallel espcecially when using the API.

### New Features & Updates
- Introduced automatic subject-based ticket merging/reply detection. Now, if an email comes from a known contact or domain and the subject matches 95% of a ticket opened in the last 7 days, it will be merged automatically.
- Added `cleanInput` function to sanitize data before inserting it into the database when using MySQLi prepared statements.
- Migrated client post functionality to use MySQLi prepared statements.
- Updated payment method post functionality to use MySQLi prepared statements.
- Implemented `saveBase64Images()` to convert base64-encoded `<img>` tags into actual image files stored under `/uploads/<module>/<id>/` with secure filenames. Added wrapper functions, and updated document creation to use processed image paths.
- For new documents and document templates, images are now stored in `/uploads/documents/$document_id` instead of being stored as base64 in the database, using the `saveBase64Images()` function.
- UI/UX improvements made to the document details page.
- Removed sidebar quick-add options.
- Created new folders in the uploads directory: `documents`, `document_templates`, and `recurring_tickets`.
- Reworked the bulk action function to pass the name arrays, instead of a generic `selected_ids` array. This allows multiple bulk name arrays to be passed at once, currently used for the new file-document merge.
- Big task: Converted the remaining modals to use the new `ajax-modal` system, enabling more flexible flow expansion going forward.
- Mail queue: Added a `--no-mx-validation` flag to bypass recipient domain MX validation.
- Bump PHPMailer from 7.0.0 to 7.0.1.
- Bump stripe-php from 18.1.0 to 19.0.0.
- Bump TCPDF from 6.10.0 to 6.10.1.
- Bump TinyMCE from 8.2.0 to 8.2.2.

## [25.11.1] Maint Release

### Fixes
- Fix broken edit Payment Method.
- Fix unable to delete Vendor Template.
- Fix Mail Queue link in flash alert for testing email and sending a quote.
- Add Show Category Type select if not defined.
- Add Show Product Type select if not defined.
- Fix add ticket watcher.
- Fix if Client isn't assigned to a ticket dont show client view.
- Fix missing session client id check when paying an invoice from client portal.
- Update Composer Webklex-IMAP library dependency symfony/http-foundation from 7.3.3 to 7.3.7 to fix security related issues.
- Add back delete Payment provider the database will handle cascade deletes to saved cards, recurring payments and client payment provider reference.
- Don't show Client Tickets Breadcrumb if no client is assigned to a ticket.
- Don't Show Contact or Assignment Tab in edit ticket if no Client is Assigned.
- Don't Show add contact, asset, vendor, watcher if not client is assigned to a ticket.
- Don't Show Public Comment & Email if contact email doesn't exist.
- Fixed IMAP Test whicn now uses RAW TCP Connection instead of the depracated php-imap extension.
- Fix Broken Link in Ticket Updates via Client Portal to agent.

### Added / Changed
- [Feature] Added Asset Tags.
- [Feature] Added Quick Add Links to most side bar navs example quickly add a client from sidebar.
- Migrate ticket template add to ajax modal.
- Add TOTP secret to Client Export PDF in Credential section.
- Add UserID on hover in users listing.
- Merge ticket now redirects to the new ticket details page.
- [Feature] Add Pay via saved card under invoice Listings.
- Ticket Related Side Items UI Cleanup to use btn-tool class. 

## [25.11] Stable

### Deprecation Notice:
- **Outdated CRON Scripts**: The following scripts are removed.
  - `/scripts/cron_mail_queue.php`
  - `/scripts/cron_ticket_email_parser.php`
  - `/scripts/cron.php`
  - `/scripts/cron_domain_refresher.php`
  - `/scripts/cron_certificate_refresher.php`
  
  **Action Required**: Transition to the new versions:
  - `/cron/mail_queue.php`
  - `/cron/ticket_email_parser.php`
  - `/cron/cron.php`
  - `/cron/domain_refresher.php`
  - `/cron/certificate_refresher.php`

- PHP Extensions php-imap and php-mime-mail-parser are no longer required.
---

### Fixes
- **Ticket Listing**: Resolved issue where the “Check All” checkbox was visible even when ticket status wasn’t set. Now hidden for closed tickets only.
- **Timer Auto-Start**: Show H/M/S placeholders when timer auto-start is disabled.
- **Ticket Guest URL**: Fixed email not including the ticket guest URL key.
- **EML Generation**: Resolved issue with EML not being generated in the new ticket parser.
- **New Ticket Mail Notification**: Included message when notifying the tech of a reply in the new ticket mail parser.
- **Advanced Filter Collapse**: Added clause to prevent collapse of advanced filters when the “from” date is set to the default (1970-01-01).
- **Recurring Invoice**: Fixed issue where email was marked as sent but not actually sent when forcing a recurring invoice to an invoice.
- **CSRF Token**: Fixed issue with deleting recurring ticket from asset details page due to missing CSRF check token.
- **Vendor Website Link**: Fixed missing `https://` prefix in the vendor website link on the vendor details modal.
- **Agent Select Box**: Resolved issue where agents sometimes didn’t appear in the agent select boxes.
- **TinyMCE**: Fixed TinyMCE editor issue on Bulk Create Ticket in Assets.
- **Ticket Timer**: Fixed ticket timer initialization after reload and when the tab is put to sleep (background tab).
- **Client Deletion**: Fixed issue with client deletion.
- **Domain Records**: Added flag for missing SOA record when adding a domain (prevents subdomain creation).
- **Domain Fetching**: Quits domain record fetching if no SOA record exists (prevents subdomains).
- **Domain Expiry**: Only show time to expiry when there’s an expiry date set; otherwise, display a dash.
- **Certificates**: Improved handling of empty date in the agent UI.
- **Certificates API**: Fixed bug with missing JS to fetch certificate details.
- **API Updates**:
  - Clients API: Added support for archiving/un-archiving clients, updating client data, and abbreviation support.
  - Contacts API: Added archiving/un-archiving and restriction to only allow one primary contact per client.
  - Mail Queue: Added recipient domain MX validation before sending emails.

---

### Added / Changed
- **Backup / Restore**: Improved backup and restore by streaming data to disk (to prevent memory issues), setting unlimited timeouts, checking for bad backup contents, and using PHP for DB import instead of shell exec. Added `.htaccess` to prevent PHP execution in `/uploads/` directory.
- **Ajax Modals**: Migrated all Add and Bulk modals to the new Ajax Modal for improved performance.
- **Recurring Ticket Sorting**: Default sorting of recurring tickets by `RunDate` instead of subject.
- **Recurring Ticket Enhancements**:
  - Added Billable column.
  - Added bulk actions for setting priority, agent, billable status, and next run dates.
  - Added filters for category, assigned agent, and billable status.
  - Added new frequency options: 3-day and biweekly.
- **Asset Select**: Updated asset select dropdown to separate asset types using opt groups (planned for wider use).
- **Expiring Domains & Certificates**: Added "30 Day" warning for expiring domains and certificates in the dashboard.
- **Ticket Search**: Allowed search using both ticket prefix and number.
- **Recurring Invoice**: Cancel recurring invoices when the associated client is archived.
- **Credentials Import/Export**: Now includes TOTP secrets when importing/exporting credentials.
- **Asset Notes Import**: Allowed importing of asset notes.
- **Ticket View**: Added a "View HTML Code" button in all ticket views for TinyMCE.
- **Date Range Picker**: Updated all date filters to use the improved DateRangePicker JS.
- **Bulk Ticket Creation**: Added bulk ticket creation for clients.
- **Sidebar Updates**: Updated all sidebars to use absolute paths for easier integration with custom code.
- **Document Actions**: Added Archive and Delete buttons to the Document Details view with improved redirect behavior.
- **Ticket Template Sorting**: Allowed sorting by task count in ticket templates.
- **Contact Modal UI**: Updated contact details modal to display contact information at the top.
- **API & Code Updates**: 
  - Separated out post files for recurring tickets, invoices, expenses, and payments.
  - Removed unused budget code.
- **Invoice Product Autocomplete**: Now allows searching for product codes as well as names.
- **Client Duplicate Check**: Flags duplicate clients or leads when using the client add modal.
- **Recurring Invoice Reference**: Added a column to invoices indicating if they were created from a recurring invoice.
- **Global Search Enhancements**: 
  - Allowed ticket details to be searchable in global search.
  - Allowed searching for quotes in global search.
- **UI/UX Improvements**:
  - Spruced up the ticket details page UI.
  - Added contact email validation to flag duplicates or invalid addresses.
- **API Debugging**: Log API endpoint/URL path for authentication failures to aid in debugging.
- **Image Upload Optimization**: Removed image optimization from uploads (this will be handled by a cron job in the future).
- **View Behavior Change**: Updated ticket/invoice/quote views to always be in the Client section, showing client-side navigation and top info bar.

---

### Library Updates:
- **DataTable**: Bumped from 2.3.3 to 2.3.4.
- **TinyMCE**: Bumped from 8.0.2 to 8.2.0.
- **Stripe-PHP**: Bumped from 17.6.0 to 18.1.0.
- **PHPMailer**: Bumped from 6.10.0 to 7.0.0.
- **Chart.js**: Bumped from 4.5.0 to 4.5.1.




## [25.10.1]
- Deprecation Notice: `/scripts/cron_mail_queue.php` , `/scripts/cron_ticket_email_parser.php` , `/scripts/cron.php` `/scripts/cron_domain_refresher.php`, `/scripts/cron_certificate_refresher.php` are being phased out. Please transition to `/cron/mail_queue.php` , `/cron/ticket_email_parser.php`, `/cron/cron.php`, `/cron/domain_refresher.php`, `/cron/certificate_refresher.php` These older scripts will be removed in the November 25.11 release—update accordingly. 25.10.1 installs have the script already configured.

### Fixes
- Fix regression missing custom Favicon.
- Update SMTP and IMAP provider to allow for empty strings, empty means disabled.
- Fix Client portal Microsoft SSO Logins.
- Fix regression in Vendor Templates.
- Fix refression in some broken links from user to agent.
- Fix Project edit.
- Prevent open redirects upon agent login.
- Fix regression on switching to Webklex IMAP to allow for no SSL/TLS in IMAP.
- Fix Setup Redirect not behaving properly when setup hasnt been performed.
- Added Server Document Root Var to several includes, headers, footers files to allow includes from deeper directory strutures such as the new custom directories.
- Fix edit contact in contact details.
- Add .htaccess to /cron/.

### Added / Changed
- Support for HTML Signatures.
- Add Edit Project Functionality in a ticket.
- Added more custom locations: /cron/custom/, /scripts/custom/, /api/v1/custom/, /setup/custom/.
- Copied `/scripts/cron.php` `/scripts/cron_domain_refresher.php`, `/scripts/cron_certificate_refresher.php` to `/cron/cron.php`, `/cron/domain_refresher.php`, `/cron/certificate_refresher.php`. See Above!
- Signatures is now handled in post ticket reply on Public Comments only.

## [25.10]

### Breaking Changes
- Renamed `/user/` directory to `/agent/`.
- Deprecation Notice: `/scripts/cron_mail_queue.php` and `/scripts/cron_ticket_email_parser.php` are being phased out. Please transition to `/cron/mail_queue.php` and `/cron/ticket_email_parser.php`. These older scripts will be removed in the November release—update accordingly. New Installs via the script will have this already configured.
- Custom is working now. Custom code should be placed in /admin/custom/ , /agent/custom/ , /client/custom/ /guest/custom/
We will provide example code with directory structure for each custom directory a week after this release.

### Fixes
- Resolved issue with "Restore from Setup" not functioning correctly.
- Corrected asset name display in logs and flash messages when editing an asset in a ticket.
- Fixed Payment Provider Threshold not being applied.
- Fixed issue where Threshold setting was not saving properly.
- Various minor fixes for Payment Provider issues.
- Removed leads from the client selection list in the "New Ticket" modal.
- Fixed issues with the MFA modal.
- Resolved MFA enforcement bugs.
- Fixed KeepAlive functionality to maintain user sessions longer.
- Fixed multiple broken links caused by the `/user/` to `/agent/` path migration.
- Fixed Custom code directories.

### Added / Changed
- Removed "ACH" as a payment method; added "Bank Transfer" instead.
- Replaced relative paths with absolute paths for web assets.
- Tickets can now be resolved via the API.
- Added a filter for Archived Users and an option to restore them.
- Introduced a modal when archiving users, allowing reassignment of open and recurring tickets to another agent.
- Improved logic for determining the index/root page.
- Added "Assigned Agent" column for recurring tickets.
- Introduced "Additional Assets" option when editing assets in tickets; modal now uses the updated AJAX method.
- Added Gibraltar to the list of supported countries.
- Added Custom Link Option for the Admin Nav.
- Added Custom Link Option for the Reports Nav.

### Other notes
- Major releases will happen on the first week of every Month.


## [25.09.2]

### Fixes
- Fix Payment Method Select box in Revenue.
- Remove Extra Feeback Wording When Invoice Sends.
- Updated all CSV exports to use escape parameters.
- Fix Missing First row on Asset interface export.
- Fix Edit User not working due to incorrect modal footer path.
- Fix Add Certificate breaking due spelling on function.
- Update all CSV Exports to include company name or client name depending on when its being exported from.
- Introduced new function sanitize_filename and implmented it in all exports.
- Spruced up UI/UX Saved Paymented section in Client Portal.
- Fix add Payment Link in client portal recurring invoice section.
- Better Logic handling for default page redirect.

### Features
- Introduced new Beta mail parser cron using webklex imap library instead of php-imap as this is deprecated --Not Enabled on existing installs, only new installs.
- Introduced Beta support for OAUTH2 Authentication for Microsoft 365 and Google Workspaces for both incoming ticket parsing and outgoing email but must use new mail parser and mail queue for this to work, and requires changing the cron jobs: scripts/cron_mail_queue.php to cron/mail_queue.php and scripts/cron_ticket_email_parser.php to cron/ticket_email_parser.php.

---

## [25.09.1]

### Fixes
- **Web Installer**: Resolved issue with broken installer caused by incorrect database schema file name.
- Hide the "Add Credit" button as the feature is not fully implemented yet.
- Corrected long invoice/quote notes that were overlapping with the footer in PDF exports.
- Fixed AI settings not appearing in the Admin Menu when the Billing module was disabled.
- Enabled wrapping of client tags when they are too long.
- Fixed an issue where AI was not functioning correctly.
- Removed extra spacing between the contact name and icon in the Ticket Details contact card.

### Features
- Redesigned **AI Ticket Summary**, now divided into 3 sections: Main Issue, Actions Taken, and Resolution/Next Steps.
- Updated the **AI Ticket Summary** prompt to include ticket status, reply author, source, category, and priority.

---

## [25.09]

***BACK UP*** before updating.

---

### Breaking Changes and Notes
- We strongly recommend updating from the command line, however if performed via the webui and after performed it will return a 404. thats normal as the directory structure has changed, just close your browser then log back in then go back to update to perform the many database updates. 
- This is a major release with significant changes. While the community has done a great job identifying bugs, some may still remain — continued testing is encouraged.
- All AI settings will be **reset** and must be reconfigured using the new AI provider backend.
- The `xcustom` directory has been renamed to `custom`. All custom libraries and post-processing scripts should now be placed here.

---

### Added / Changed
- Numerous UI improvements and refinements across the application.
- Enhanced visual clarity by thickening the left border on ticket comments to help identify comment types.
- Ticket details UI redesigned to use less space at the top of the screen.
- Introduced tracking for the **first response date/time** on tickets.
- New reporting feature: **Average time to first response** on tickets.
- Stripe integration rebuilt using the new **payment provider backend**.
- Clients can now save and manage **multiple payment methods**.
- Support for selecting saved cards for **recurring invoices** in both the client and agent portals.
- Initial database structure and logic added for **credit management** (feature not yet enabled).
- Major **backend directory restructuring**.
- Introduced **stock/inventory management**, including a stock ledger backend.
- Stock quantities now update automatically when invoice items are added or removed.
- Invoice autocomplete now includes: **name, description, price, tax, stock levels**, and links `product_id` to `item_id`.
- Added a **category filter** to invoices.
- Linked stock to related expenses.
- New product fields: **location, code, and type**.
- Products now separated into two types: **Service** and **Product**.
- **Dark mode** introduced.
- Projects: Now support linking **closed tickets**.
- Clients: Added bulk actions for tags, referral source, industry, hourly rate, email, archive, and restore.
- Invoices: Bulk action added to **assign categories**.
- Assets: New `client_uri` field, visible in both the agent and client portals.
- Client Portal: Clients can now **select an asset** during ticket creation.
- Client Portal: Company logo now **displays in the header**.
- Client Portal: Dashboard cards are now **clickable** for more detail.
- Assets: Option added to include **MAC Address** in additional columns.
- Asset Interface: Bulk actions added — set DHCP, network type, and delete.
- API:
  - Added `/location` endpoint.
  - Ticket content now supports **HTML formatting**.
- New option to filter and display **500 records per page** in the footer.
- Payment methods are now treated as a **separate entity** instead of being grouped under categories.
- Updated libraries:
  - **TinyMCE**
  - **Chart.js** (major upgrade)
  - **DataTables**
  - **Bootstrap**
  - **FullCalendar**
  - **php-stripe**

---

### Fixed
- Several security vulnerabilities patched (with thanks to www.helx.io).
- Ticket status is no longer updated when scheduling.
- Client Portal: Tech contacts can no longer edit their own details.
- Fixed overlapping logo issue in Invoice/Quote PDF exports.
- Refactored `check_login.php` into multiple files for modular login functionality.
- Removed redundant logging comments for redirects.
- Renamed `get_settings.php` to `load_global_settings.php`.
- Simplified syntax for `ajax-modal` and updated usage throughout the app.
- Fixed issue where primary contact text wasn’t displaying.
- Corrected client **Net Terms** display.
- Fixed logic for recurring expense **next run date**.
- Resolved broken **IMAP test button**.
- Archived clients can no longer log into the portal.
- Searching closed tickets no longer reverts to open tickets.
- Fixed project search filter not showing completed projects.
- Fixed issue where company logo was not being removed correctly.
- Resolved API bugs:
  - Default rate and net terms.
  - Contact location.
  - Document endpoint.

---

### Developer Updates
- Replaced legacy code with newer functions like `redirect()`, `getFieldById()`, and `flash_alert()`.
- Significantly improved performance of queries used for filter selection boxes.


## [25.06.1]

### Fixed
- Fixed a regression in setup causing it to crash and never complete, due to missing default for currency.

## [25.06]

### Breaking CHANGES
- Old Document Verions will be deleted due to the major backend rewrite how document versions work.

### Added / Changed
- Improved function for retrieving remote IP address for logging purposes.
- Ticket categories are now sorted alphabetically.
- Visiting a deleted invoice or recurring invoice now redirects to the listing page; delete option added to invoice details page.
- Added "Mark as Sent" and "Make Payment" actions directly on the invoice listing page.
- Introduced Ticket Category UI for recurring tickets.
- In Project Details, bulk actions and sorting are now available for tickets.
- Updated ticket details UI to use full card stacks with edit icons for stackable items (e.g., asset, watchers, contact).
- Added a new setting to toggle AutoStart Timer in ticket details (disabled by default).
- Applied gray accent theme in the client section to visually distinguish from the global view.
- Introduced Ticket Due Date functionality (currently supports add/edit only; more updates coming next release).
- Added settings option to display Company Tax ID on invoices.
- Client overview now displays badge counts for all entities.
- Overhauled UI for Invoice, Quote, and Recurring Invoice details; switched PDF generation to TCPDF PHP from PDFMake JS.
- Document versioning has been moved to a separate backend table to resolve permanent link issues -- SEE Breaking CHANGES.
- Migrated Document Templates, Vendor Templates, and Software/License Templates to dedicated tables.
- Added functionality to mark all tasks in a ticket as complete or incomplete.
- Asset CSV import now supports a purchase date field.
- Recurring Payments have been restructured to auto-charge on the invoice due date instead of at generation time.
- Added "Base Template" label for vendor templates when available.
- Backup and restore processes now use a temporary directory; files are cleaned up automatically if operations fail.
- Added confirmation prompt when accepting or declining a quote.
- Other minor code UI/UX cleanups and refactoring throughout the app.

### Fixed
- Resolved issue with enabling MFA.
- Fixed UI regression where ticket listing columns would misalign.
- Non-billable invoices are no longer included in calculations.
- Addressed multiple minor reported security vulnerabilities.
- Tickets with open tasks are no longer resolved in bulk; a warning is shown along with a count of affected tickets.


## [25.05.1]

### Added / Changed
- Added Domain Expiring Card to Client Portal Dashboard for Primary and Technical Users.
- Added Balance and Monthly Recurring Amount to Client Portal Dahboard for Primary and Technical Users.
- Added Archive Searching to network and certificates also added unarchive capabilities to them as well.

### Fixed
- Add Payment not showing in Invoice.
- Updated Client Overview Entities to not show archived client's Entities even though the entity may not be archived.


## [25.05]

### Added / Changed
- Expanded file upload allow-list to include .bat and .stk file types.
- Added full backup/restore functionality. Backup downloads a zip that includes the SQL dump and uploads folder, setup now has option to restore from zip backup.
- Migrated Asset and Contact Links to modals to resolve variable overlap issue.
- Added Pagination to Notification Modal.
- Removed 500 Records Per Page option.
- Removed unused old DB checks in the top nav.
- Clients can now use the portal to setup Stripe automatic payments themselves for recurring invoices
- Automatic payments are now disabled for all recurring invoices if the saved payment method is removed
- Added Card Details and Payment added to Client Stripe.
- UI / UX updates to guest pay Make use of cards.
- Don't show Checkbox columns when ticket is closed, compact ticket list now matches round pills for status and priority.
- Ticket UI/UX update allow the ticket toolbar to be a little more mobile-friendly
- UI / UX Updates to Expenses - Combine Category and Description into 1 column.
- Country information is now displayed in Invoices, Quotes, Recurring Invoices, Clients, Locations, and the client top header.
- Added country-based search filters in Locations and Clients sections.
- Changed the settings name from Integrations to Identity Providers to make room for future iDPs (e.g. Google).
- Bump FullCalendar from 6.1.15 to 6.1.17.
- Bump DataTables from 2.2.2 to 2.3.1.
- Bump TCPDF from 6.8.2 to 6.9.4.
- Bump tinyMCE from 7.7.1 to 7.9.0.
- Bump phpMailer from 6.9.2 to 6.10.0.
- Bump stripe-php from 16.4.0 to 17.2.1.


### Fixed
- "None" option for SMTP encryption now functions correctly.
- Debug table row counts now reflect actual counts instead of relying on SHOW TABLE STATUS.
- Archived Categories now display properly.
- Stripe saved payment methods are now limited to credit/debit cards only.

## [25.03.6]

### Fixed
- Set default to date to 2035-12-31 as 9999-12-31 and 2999-12-31 broke certain browsers.
- Update Client PDF Export, add header added company logo.
- Present Larger clearer Warning about updates on update page.
- Allow to search by project reference.

## [25.03.5]

### Fixed
- Fixed the user listing issue when copying a trip.
- Corrected the display of recurring invoice amounts on the dashboard.
- Fixed the linking of entities with assets and contacts.
- Resolved the issue with displaying the correct mobile country code in the contact listing.
- Set the default date to `9999-12-31` to ensure future items (like invoices) are displayed by default.
- Fixed the display issue where file folders were not showing properly during document creation.
- Migrated from Dragula to SortableJS for a more modern, mobile-friendly solution.
- Added Handlebars icons for drag-and-drop items.
- Changed behavior to open Contact and Asset Details pages directly instead of using a modal.

## [25.03.4]

### Fixed
- Ability to remove additional assets from the ticket details screen.
- Fix the ability to remove assets from edit ticket not working when only 1 asset exists.
- Fix Database Backup corruption.
- Client Portal - show ticket number instead of ticket id in ticket listing.
- Add Purchase Reference to copy asset.
- Add Link to asset details from the global search.
- Fix Bulk assign ticket only showing contacts instead of ITFlow users.


## [25.03.3]

### Fixed
- Fix adding ITFlow user.
- Do not alert on inactive recurring invoices.
- Fix ticket user assignment including bulk assignment.
- Fix adding a location phone extension.
- Do not default to +1 Country code, instead default to null.
- Do not format numbers unless a country code is entered.
- Fix editing network location.
- Fix ticket redaction on client replies.
- Remove more from user activity as it requires admin privledges.
- Fix MFA Enforcement page.

## [25.03.2]

### Fixed
- Revert DB.sql change

## [25.03.1]

### Fixed
- Phone number missing in various sections.
- Match Database.
- Client Export Only display licenses users and assets from the selected client only.

## [25.03]

### Fixed
- Resolved missing attachments in ticket replies processed via the email parser.
- Fixed issue where the top half of portrait image uploads appeared cut off at the bottom.
- Ensured all tables and fields use `CHARACTER SET utf8mb4` and `COLLATE utf8mb4_general_ci` for updates and new installations.
- Converted `service_domains` table to use InnoDB instead of MyISAM.
- Fixed the initials function to properly handle UTF-8 characters, preventing contact-related issues.
- Interfaces can now start with `0`.
- Adjusted AI prompt handling to focus solely on content, avoiding unnecessary additions.

### Added / Changed
- Introduced bulk delete functionality for assets.
- Added the ability to redact ticket replies after a ticket is closed.
- Added support for redacting specific text while a ticket is open.
- Switched file upload hashing from SHA256 to MD5 to significantly improve performance.
- Enabled assigning multiple assets to a single ticket.
- Updated all many-to-many tables to support cascading deletes using foreign key associations, improving efficiency, performance, and data integrity.
- Enabled caching for AJAX modals to reduce repeated reloads and enhance browser performance.
- Upgraded DataTables from 2.2.1 to 2.2.2.
- Upgraded TinyMCE from 7.6.1 to 7.7.1, providing a significant performance boost.
- Added “Copy Credentials to Clipboard” button in AJAX asset and contact views.
- Renamed and reorganized several tables.
- Improved theme color organization by grouping primary colors and their related shades.
- Displayed a user icon next to contacts who have user accounts.
- New image uploads are now converted to optimized `.webp` format by default; original files are no longer saved. Existing images remain unchanged.
- Added international phone number support throughout the system.
- Introduced user signatures in preferences, which are now appended to all ticket replies.
- Optimized search filters to only display defined tags.
- Added “Projects” to the client-side navigation.
- Enabled “Create New Ticket” from within project details.
- Reintroduced batch payment functionality in client invoices.
- Included client abbreviations in both client and global search options.
- Added assigned software license details (User/Asset) to the client PDF export.
- Replaced client-side `pdfMake` with the PHP-based `TCPDF` library for generating client export runbooks.
- Introduced the ability to download documents as PDFs.
- Added a “Reference” field to tickets and invoices generated from recurring templates (not yet in active use).

### Breaking Changes
> **Important:** To update to this version, you **must** run the following commands from the command line from the scripts directory:
>
> ```bash
> php update_cli.php
> php update_cli.php --db_update
> ```
>
> Repeat `--db_update` until no further updates are found.
>
> **Back up your system before upgrading.**  
> This version includes numerous backend changes critical for future development.

## [25.02.4]

### Fixed
- Resolved issue preventing the addition or editing of licenses when no vendor was selected.
- Fixed several undeclared variables in AJAX contact details.
- Corrected the contact ticket count display.
- Addressed an issue where clicking "More Details" in AJAX contact/asset details failed to include the `client_id` in the URL.
- Fixed an issue with recurring invoices in the client URL: clicking "Inactive" or "Active" would unexpectedly navigate away from the client section.
- Added new php function getFieldById() to return a record using just an id and sanitized as well.

## [25.02.3]

### Fixed
- Fixed notifications being reversed as dismissed notifications.

## [25.02.2]

### Fixed
- Corrected some edit modals not showing notes correctly.
- Bugfix: When exporting to CSV, the first asset wasn't being shown.
- Fix broken create / edit credentials.
- Fixed missing Notificatons link.
- Fixed a few dead links.
- Fixed Overdue count also counting Non-Billable Invoices.
- Fix Edit Client Notes.

### Added / Changed
- Implemented SSL certificate history tracking.
- Added Inactive / Active Filter to Recurring Invoices.
- Merged Dismissed notifications and notification in one.
- Added Link Button to addd / edit Document WYSIWYG.
- Added Physical location to the asset export / import.

## [25.02.1]
### Fixed
- Resolved broken links in the client overview, project and client listings, and rack details.
- Corrected asset transfer functionality to clients.
- Fixed the ticket scheduling redirect.
- Corrected the ticket link in the Scheduled Ticket Agent Notification email.
- Addressed issues with credentials and ticket actions in the Contact Detail Modal.
- Fixed text wrapping in notifications.
- Adjusted notifications so that they are sorted with the newest first.
- Fixed drag-and-drop functionality for tickets in the Kanban view on mobile devices.
- Resolved a weird issue with TinyMCE that prevented using links referencing your ITFlow instance url.
- Corrected image orientation issues during upload and the preview optimization process.

### Added / Changed
- Introduced entity link indicator icons and counts in the contacts and credentials section.
- Implemented a fade animation for the new AJAX modal.
- Removed the Client Overview Expire Day Select and replaced it with simplified 1, 7, or 45-day options.
- Added the ability to link and unlink entities within asset details.
- Introduced quick tag/category creation across the app.
- Added a Vendor Quick Details Modal.
- Enabled vendor linking and added a License Purchase Reference in the Software Licenses section.
- Added download original, optimized and thumbnail option for images.
- Added Paid status to the top corner of Invoice PDFs.

## [25.02]
### Fixed
- Migrated several reports to the new permissions/roles system.
- Resolved issue with empty task box showing for closed/resolved tickets.
- Corrected ticket priority sorting.
- Cloned asset interfaces when transferring assets between clients.

### Added / Changed
- Restored max number of records per page option back to 500 since we dont have repeating modals.
- Bulk Categorize Tickets feature.
- Renamed "Interface port" to "Interface Description." "Interface Name" should now refer to port name and/or number.
- Changed "Transfer Asset to Client" from a single action to a bulk action.
- Updated Filter Footer UI to show "Showing x to x of x records" instead of just the total records.
- Added Client Overview section to view client assets, contacts, licenses, credentials, etc.
- Introduced Quick Peek for asset details, contact information, and document viewing throughout the ITFlow App, all made possible by AJAX.
- Enabled Simple Drag-and-Drop Ordering for Invoices, Recurring Invoices, Quotes, Ticket Tasks, and Ticket Template Tasks.
- Added new Ticket View options: Kanban and Simple View.
- Migrated all repeating modals to the new AJAX modal function for faster loading times and quicker development.
- Allowed clients to upload PDF documents to accepted quotes.
- Client Portal now shows ticket category.
- Custom links can now be added to the Client Portal navbar.
- Lots of little tweaks to UI, performance, bugs, etc.

### Breaking Changes
- Cron scripts have officially been moved to the /scripts folder and are no longer in the root directory; they must be updated to function properly.

## [25.01.3]
### Fixed
- Fixed ticket assignment modal showing client contacts.

## [25.01.2]
### Fixed
- Fixed app version.

## [25.01.1]

### Added / Changed
- Redesigned the Multi-Factor Authentication (MFA) Setup and Enforcement Flow UI/UX for a more intuitive user experience.
- Added a "Member" column in the user roles listing for improved visibility.
- General UI/UX improvements, along with minor performance optimizations and cleanups.

### Fixed
- Fixed an issue where Stripe was not appearing as a recurring payment option.
- Corrected inaccurate Quarter 2 Expense results in the Profit & Loss Report.
- Resolved TOTP code not displaying correctly on hover in the Contact or Asset Details sections.
- Archived contacts no longer appear in the Bulk Mail section.
- Fixed an issue where the Ticket Assign Modal was showing both ITFlow and client users.
- Fixed issue with login key redirecting to legacy client portal page.

## [25.01]

### Added / Changed
- Added support for saving cards in Stripe for automatic invoice payments.
- Page titles now display detailed information (e.g., page name, client selection, company name, ticket and invoice info) for easier multi-tab navigation.
- Reintroduced the new admin role-check for admin pages.
- Admin roles can now be archived.
- Debug mode now shows the current Git branch.
- The auto-acknowledgment email for email-parsed tickets now includes a guest link.
- Recurring tickets no longer require a contact.
- Stripe online payment setup now prompts you to set the income/expense account.
- New cron/CLI scripts have been moved to the `/scripts` subfolder — remember to update your cron configurations!
- Moved modal includes to `/modals` to tidy up the root directory.
- Moved most include files to `/includes` to improve directory structure.
- Moved guest pages to `/guest` for better organization.
- Renamed the include file `pagination.php` to `filter_footer.php`, as it is used in conjunction with `filter_header.php` for page filtering.
- Guest ticket feedback now shows the ticket prefix and number, not just the ID.
- Individual POST handler logic pages are no longer directly accessible.
- Added the ability to delete payments on the Payments and Client Payments pages.
- Implemented domain history tracking.
- Added Asset Interface Linking/Connections to show what interface is connected to which interface port of another asset.
- Added Force Recurring Ticket option in more locations, not just for recurring tickets.
- Implemented row spanning and centered devices that occupy multiple units in a rack.
- Added tooltips to main navigation badge counts to clarify what is being counted.
- Reduced max records per page from 500 to 100 to prevent performance issues.
- Updated several plugins:
  - `stripe-php` from 10.5.0 to 16.4.0
  - `Inputmask` from 5.0.8 to 5.0.9
  - `DataTables` from 2.1.8 to 2.2.1
  - `pdfmake` from 0.2.8 to 0.2.18
  - `php-mime-mail-parser` to 9.0.1
  - `TinyMCE` from 7.5.1 to 7.6.1
- Removed unused libraries from the vendor folder and moved Stripe to the plugins folder, eliminating the vendor folder.
- Merged the MFA TOTP functionality files `base32static.php` and `rfc6238.php` into a single file (`totp`) and moved it to the plugins folder.
- No longer need to pass the DB connection (`$mysqli`) to the `addToMailQueue` function.
- Disabled HTML Purifier caching.
- Replaced the `nullable_htmlentities` function with `htmlspecialchars`.
- Updated filter variable naming.
- Implemented other minor UI updates, performance optimizations, and directory cleanups.

### Fixed
- Fixed an issue where the ticket edit modal didn't show multi-client or no-client projects.
- Fixed asset interface losing DHCP settings.
- Fixed a 500 error when creating or editing recurring expenses due to an incorrect variable name.
- Fixed tickets created via the portal/email not being marked as billable.
- Fixed issues with editing recurring expenses.
- Resolved a regression where the TinyMCE editor didn’t display when adding or editing ticket templates.
- Fixed a TinyMCE license issue.

### Removed / Deprecated
- Deprecated the cron scripts in the root directory. Cron jobs should now use the ones in the `/scripts` subfolder, which no longer require a cron key and must be run via CLI.

### BREAKING CHANGES
- The client portal has been moved from `/portal` to `/client`:
  - Links in previous emails will be broken.
  - The Azure Entra ID SSO Redirect URI needs to be updated to `/client`.
  - You may need to update other links (e.g., website, support page).
- Guest links have been moved from `/` to `/guest`. Previous links will be broken.

## [24.12]

### Added / Changed
- Introduced versioned releases for the first time!
