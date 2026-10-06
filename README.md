<h1 align="center">
  <img src="img/branding/logo-mark.svg" alt="" width="72"><br>
  RivetIT
</h1>

<p align="center"><strong>Everything your IT department needs. One platform.</strong></p>

<p align="center">
  RivetIT is a free and open-source internal IT operations platform for managing service requests, users,
  devices, documentation, automation, integrations, and employee training from one centralized system.
</p>

<p align="center">
  <a href="#self-hosting">Self-hosting</a>
  ·
  <a href="docs/DEPLOYMENT.md">Deployment</a>
  ·
  <a href="docs/ARCHITECTURE.md">Architecture</a>
  ·
  <a href="https://github.com/TheTractorHacker/RivetIT/wiki">User Guides</a>
  ·
  <a href="docs/API.md">API</a>
  ·
  <a href="https://github.com/TheTractorHacker/RivetIT-Mobile">Android app</a>
  ·
  <a href="CHANGELOG.md">Changelog</a>
  ·
  <a href="https://github.com/TheTractorHacker/RivetIT/tags">Releases</a>
  ·
  <a href="https://github.com/TheTractorHacker/RivetIT/issues">Report a bug</a>
</p>

<p align="center">

[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Commits][commit-shield]][commit-url]
[![GPL License][license-shield]][license-url]

</p>

---

## Who it is for

RivetIT is built for the IT team inside an organization: corporate IT, manufacturing and plant IT, schools,
nonprofits, and small and midsize organizations. It runs one organization with many **departments**,
locations and employees, from one self-hosted install. It is not an MSP tool: there is no client billing,
and the invoicing, quoting, payroll and CRM code it inherited is switched off.

RivetIT started from [ITFlow](https://github.com/itflow-org/itflow) and was known as *ITFlow Internal IT*
before it was renamed; see [Credits](#license-and-credits).

## Features

Where each area lives in the menu: Service Desk is **Service Desk** (and **Work** for projects and the
calendar); devices and assets are **Infrastructure**, **Endpoints** (RMM, Intune, network) and **Backups**;
people are **People** and **Organization** (departments, org chart); documentation is **Knowledge**, plus the
documents inside each department; learning is **Training**; integrations, API keys and webhooks are under
**Admin**.

### Service Desk
- **Tickets** with categories (parent/child groups), custom statuses, priorities, assignment, watchers,
  time tracking, internal notes, canned responses and ticket templates; list and Kanban views, with inline
  changes to category, technician, priority and status from the list.
- **E-mail to ticket** from IMAP or Microsoft 365 / Google Workspace (OAuth) mailboxes, with a review queue
  for mail from unknown senders.
- **Service catalog** that employees request from in the self-service portal.
- **SLA policies** with business-hours calendars and holidays, SLA terms per department contract, and live
  SLA hints while a ticket is being logged.
- **Problems and changes** alongside incidents, **recurring tickets**, **appointments** (on-site or remote)
  and a shared calendar.
- **Worksheets and sign-off forms** with signatures, drag-and-drop field ordering and templates.
- **Satisfaction ratings (CSAT)** on resolved tickets, live ticket updates and ticket chat, and a warning
  when a colleague is viewing the same ticket.
- **Projects** with milestones, tasks, Kanban and Gantt views, and project templates.
- A **self-service portal** where employees raise and follow tickets, rate them, and see their assets,
  documents and knowledge-base articles; optional sign-in with Microsoft Entra ID.
- Optional **AI assistance** (ticket summaries, rewording replies, drafting documents) through an AI
  provider you configure.

### Asset Management
- **Assets**: computers, servers, network devices, mobile devices and more, with locations, vendors,
  warranty and purchase details, and per-department access.
- **Assignment history**: every hand-over of a device to a person is recorded, not overwritten.
- **Software and licenses**, **domains and SSL certificates** (with expiry checks), **networks**, **racks**,
  **printers** and **network drives**.
- A **secure share link** (expiring, view-limited) for a credential, document or file.

### RMM & Remote Management
- **RMM integrations**: Tactical RMM, Level.io and Action1 (patch management); Sophos Central for firewall
  inventory and alerts. Agents are matched to assets automatically.
- **RMM dashboard, alerts, script library and check policies**; alerts can open tickets automatically.
- **Remote access**: a Connect button through the RMM, and one-click AnyDesk connect from the asset page.
- **Device metrics**: CPU, memory, disk, network, uptime, pending reboot and battery charts, collected from
  Tactical RMM or by a small Windows PowerShell collector (`scripts/collector/`).
- **Microsoft Intune** device sync, **UniFi** controllers (devices, Wi-Fi networks and networks), and
  **Comet Backup** status per department.

### Documentation
- **Knowledge base** with version history and restore, rich formatting, import from Word (DOCX), PDF and
  HTML, per-department and portal visibility, and **interactive blocks**: step-by-step sequences with
  per-reader progress, decision trees, copy-to-clipboard commands and sandboxed embeds.
- **Credential vault** encrypted with per-user keys, TOTP codes, reveal logging, rotation reports and secure
  references to a credential from a KB article.
- **Documents** with templates, **files**, **contacts**, **locations**, **vendors** and **contracts**, all
  linked to each other and to assets.

### User Lifecycle
- **Employees and departments**: employee ID, job title, manager, start date, employment status and type,
  and work arrangement, with an **org chart** built from manager links.
- **Onboarding and offboarding checklists** from templates, started from a person's page, with an audit trail.
- **People import** from CSV with a preview to approve before anything is written.
- **Directory sync** of departments and employees from Microsoft Entra ID, Google Workspace or Odoo.
- **Security classification** on departments and per-user department access restrictions.

### Learning Management
- **Course builder**: uploaded video (with closed captions), YouTube and Vimeo, PDF and article lessons,
  documents to acknowledge, quick checks, final exams from question banks with time and attempt limits,
  English and Spanish versions, publishing with revision compare, and *Preview as learner*.
- **Learning paths**, **achievements and badges**, and requirement **rules** that assign training by
  department, Odoo job or work location, job group, person or new hire, with due dates and renewals.
- **Training kiosk** for shared iPads and Windows PCs: employees sign in with their name and a PIN, take
  courses, sign with a finger signature, and pick up where they left off; a **trainer mode** runs classroom
  sessions and hands-on evaluations.
- **Records** with certificate numbers and QR codes that anyone can check at `/verify/`, external cards and
  paper records with evidence scans, voids and reissues, on a tamper-evident ledger
  (`scripts/training_ledger_verify.php`).
- **Compliance dashboard and reports** (department x course matrix, overdue, expiring, course analytics,
  item analysis) with CSV export, transcripts and certificates as PDF, and reminder digests.

### Integrations
- **Microsoft 365 / Entra ID**: directory sync, Intune devices, mailboxes, Outlook calendar sync and portal
  sign-in.
- **Google Workspace**: directory sync and mailboxes.
- **RMM and network**: Tactical RMM, Level.io, Action1, Sophos Central, UniFi, Comet Backup.
- **Calendar feed** (iCal) for Outlook, Apple Calendar or Google Calendar.
- **Webhooks** to 24 platforms (see below), **SMTP / IMAP** mail, push notifications to the Android companion
  app, and **S3-compatible storage** for in-app backups.

### Webhooks
Send events to the tools you already run, without writing glue code.
- **24 ready-made platforms**: n8n, Node-RED, Activepieces, Windmill, Huginn, Zapier, Make, Pipedream and IFTTT (automation); Slack, Microsoft Teams, Discord, Mattermost, Rocket.Chat, Matrix (hookshot and client API) and Telegram (chat); ntfy, Gotify and Apprise (notifications); Home Assistant; and generic JSON, form and custom-template webhooks. Each has a step-by-step guide in **Administration > Webhooks > Guides**.
- **113 events in 14 groups** (tickets, SLA and escalations, approvals and service catalog, workflows and lifecycle, problems and changes, assets, clients, billing, security and sign-in, audit and compliance, backups and system, automation and jobs, integrations, training), 96 of them emitted today and 17 planned. A searchable event picker with group wildcards (`ticket.*`) and quick chips ("Critical only", "SLA problems", "Security & sign-in").
- **13 payload formats** (JSON, form, Slack blocks and attachments, Teams, Discord, ntfy, Gotify, Telegram, Matrix, Apprise and a custom template with `{{placeholders}}` and safe filters), **bearer, basic, custom-header and signed** authentication, and POST or PUT.
- **Signed and safe by default**: HMAC signatures with a signed timestamp, verification snippets in Node, Python, PHP, Bash and an n8n Code node, retries after 1, 5, 30 and 120 minutes, secrets stored encrypted, and public addresses only unless an admin lists an internal network.
- **A four-step guided setup** with a live address check, **Send test** and **Preview payload**, a delivery log with View payload, and a tabbed Edit page.

### Odoo
RivetIT integrates with [Odoo](https://www.odoo.com) (the Odoo Integration, under Admin > Settings >
Integrations > Directory Sync): people and departments come from Odoo, and training records can go back to it.
- **Directory sync** of departments and employees, with an employee-link check and an optional hire-date
  fill; Odoo's JSON-2 API (Odoo 19 and later) with automatic fallback to JSON-RPC.
- **Training rules** by Odoo job and work location.
- **Training write-back** (off by default): completed training becomes résumé lines, certification skills
  or HR notes on the Odoo employee, with retries, duplicate checks and undo on a void.
- Optional **Odoo PIN sign-in** on the training kiosk.

### Automation
- **Event rules** (Administration > Event rules): a **When, If, Then** builder. Pick any of the 113 events, add conditions (equals, does not equal, is one of, contains, greater or less than, is empty) in nested ALL/ANY groups, and choose one of **9 actions** (create a ticket, send a webhook, notify technicians, start a workflow, set a ticket field, add a note, assign, send an email, create a task). Each rule shows a plain-English summary, a **test drawer** that dry-runs it without side effects, run **history**, rate limits and a loop guard, and there are **11 ready-made recipes**.
- **Ticket automation rules**: conditions (age, idle time, priority, status, assignee, category) and actions
  (set fields, assign, add a note, notify, close, attach a worksheet template), with a run log.
- **Scheduled report e-mails**, recurring tickets, RMM alert-to-ticket, domain and certificate expiry
  checks, training assignment and reminders.
- **Cron Manager**: change the schedule from the web UI and run jobs on demand.

### Reporting
- Service desk and SLA, ticket volume (by month, day and department), time by technician, technician
  performance, satisfaction, RMM health and credential rotation; the service desk, ticket, technician and
  satisfaction summaries can also be e-mailed on a schedule.
- Training dashboards and reports with CSV export, plus the audit log and application log.

### Security
- **Two-factor sign-in** with TOTP or **passkeys (WebAuthn)**, per-user MFA enforcement, and e-mail alerts
  for unusual sign-ins.
- **Roles** with read / write / full access per module, admin roles, and module-only logins that stay inside
  their modules.
- **Per-department access** restrictions, secrets encrypted at rest, authenticated knowledge-base media,
  and an audit trail of security events.
- **Hardened deployment**: fail2ban, ufw, login rate limiting, PHP-FPM and MariaDB hardening, unattended
  security updates and encrypted backups, with an [ISO 27001 Annex A mapping](docs/ISO27001-COMPLIANCE.md)
  of what is and is not covered.

### API
- **REST API** at `/api/v1` for tickets, departments, contacts, assets, contracts, credentials, the
  knowledge base, worksheets, appointments, projects (milestones and tasks), search, reports, alerts and
  notifications, with live (Server-Sent Events) notifications and ticket chat.
- **Bearer tokens** per user, or API keys scoped to departments, with read or read/write access.
- **OpenAPI 3.0** spec at `/api/v1/openapi.yaml` and a searchable reference at `/api/v1/docs`
  (Admin > API Docs). The narrative guide is [docs/API.md](docs/API.md). The API still says `client`
  where the screens say Department, on purpose: see the note in that guide.

---

## Android companion

[RivetIT-Mobile](https://github.com/TheTractorHacker/RivetIT-Mobile) is the separate Android app for
RivetIT technicians. Its [0.8.0 beta release](https://github.com/TheTractorHacker/RivetIT-Mobile/releases/tag/v0.8.0)
is available for testing. The [RivetMSP-Mobile](https://github.com/TheTractorHacker/rivetmsp-mobile)
app remains a separate project for MSP installations.

## Self-Hosting

One install per organization, on your own server. RivetIT is PHP 8.5 with MariaDB (MySQL-compatible),
served by nginx, with Redis for live updates. Composer dependencies are committed in `vendor/`. Two
supported ways to run it are described in full in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), with the
flag-by-flag reference in [deploy/README.md](deploy/README.md).

### Hardware

| | Minimum | Recommended |
|---|---|---|
| CPU | 1 vCPU | 2+ vCPU |
| RAM | 2 GB | 4 GB+ |
| Disk | 20 GB | 40 GB+, more if the Learning module stores a lot of training video |

The minimum runs nginx, PHP-FPM, MariaDB and Redis together for a small team's evaluation or light use.
The recommended column gives PHP-FPM room for more concurrent workers and MariaDB more buffer pool, so
report/PDF generation and the Learning module's video handling stay responsive under real, everyday use.
Actual sizing depends on employee count, ticket/asset volume, how much training video you store, and how
many backup generations you keep (`config_backup_retain_count`) — treat these as a starting point, not a
guarantee.

### Docker Compose (fastest way to try it)

```bash
git clone https://github.com/TheTractorHacker/RivetIT.git
cd RivetIT
cp .env.example .env    # edit DB_PASSWORD/DB_ROOT_PASSWORD, and DOCKER_UID/DOCKER_GID (run `id -u`/`id -g`)
docker compose up -d --build
```

Then visit `http://localhost:8080/` (or the `APP_PORT` you set in `.env`); it redirects to the `/setup/`
wizard. When it asks for a database host, enter `db` and the credentials from your `.env`.

The stack runs nginx, PHP-FPM, Redis and the cron loop in `rivetit-web` and MariaDB in `rivetit-db`. The
app code is bind-mounted from the checkout, so `config.php`, `uploads/` and `backups/` persist on the host
and `git pull` + `docker compose up -d --build` is the update path. The container does not terminate TLS or
harden the host: put a reverse proxy in front of it for anything beyond local evaluation. To start from a
`deploy/backup.sh` backup instead of a fresh install, see `RESTORE_FROM` in `.env.example`.

### Bare-metal install (recommended for production)

```bash
git clone https://github.com/TheTractorHacker/RivetIT.git
cd RivetIT
sudo deploy/install.sh --domain=rivetit.example.com
```

`deploy/install.sh` provisions nginx, PHP 8.5, MariaDB and Redis (asking first — answer "n", or pass
`--skip-dependencies`, if you already have them set up the way you want), sets up TLS (Let's Encrypt, or
`--proxy-mode` behind your own reverse proxy), applies security hardening, installs the cron entry and runs
the first-run setup; with `--restore-from` it stands a new box up from an existing backup instead (an
encrypted `deploy/backup.sh` archive, or the app's own in-app `.zip`). Run it again with a different
`--domain` to host another organization's independent instance on the same box.
`deploy/harden.sh` adds the remaining hardening steps (see [deploy/README.md](deploy/README.md#hardensh)).

### Backups and updates

- `deploy/backup.sh` (encrypted, with a systemd timer) is the disaster-recovery backup and
  `deploy/restore.sh` its restore; the in-app backup (Admin > Backup) makes quick unencrypted snapshots,
  optionally to S3-compatible storage. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#4-backup--disaster-recovery)
  explains which is which.
- `deploy/update.sh` takes a backup, pulls the code and runs pending database migrations. Admin > Update
  shows what is new and applies the code and database updates from the browser; take a backup first.

## Documentation

| Document | What it covers |
|---|---|
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Docker vs. bare metal, backups and disaster recovery, updating |
| [deploy/README.md](deploy/README.md) | Every deployment script and flag |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Code layout, sign-in and permissions, data model, modules, migrations, integrations |
| [docs/ORG_CHART.md](docs/ORG_CHART.md) | Org chart access, filters, hierarchy context, and navigation |
| [docs/API.md](docs/API.md) | The REST API |
| [docs/training-kiosk-setup.md](docs/training-kiosk-setup.md) | Setting up training kiosks on iPads and PCs |
| [docs/ISO27001-COMPLIANCE.md](docs/ISO27001-COMPLIANCE.md) | ISO/IEC 27001:2022 Annex A control mapping |
| [REBRANDING.md](REBRANDING.md) | The rename from ITFlow Internal IT, and the internal names that were kept |
| [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) | The open-source libraries RivetIT bundles, and their licenses |
| [CHANGELOG.md](CHANGELOG.md) | Release notes and upgrade steps |

## Versioning

RivetIT uses calendar versions (`26.09` is September 2026) on a rolling `main` branch, with a separate
database schema version that the updater migrates one step at a time. The running version is shown in the
page footer and on Admin > Update.

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues
privately as described in [SECURITY.md](SECURITY.md), never in a public issue.

## License and credits

RivetIT is free software under the [GNU General Public License v3.0](LICENSE).

RivetIT is built on **[ITFlow](https://github.com/itflow-org/itflow)**, the free and open-source IT
documentation, ticketing and asset management platform; all original credit goes to the ITFlow
contributors. It was developed from **[TheTractorHacker/itflow](https://github.com/TheTractorHacker/itflow)**,
an MSP-focused fork whose workflow additions (ticket automation, the Cron Manager, worksheets, calendar
sync, SLA tracking and REST API work) are credited to TractorHacker / Foley IT, and was published as
*ITFlow Internal IT* before it became RivetIT. See [NOTICE](NOTICE) for the full attribution and
[REBRANDING.md](REBRANDING.md) for why some internal names still say `itflow`. The libraries RivetIT bundles keep their own licenses, listed in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md). RivetIT is an independent
project and is not maintained or endorsed by the ITFlow maintainers; security issues in upstream ITFlow
itself go to [its security policy](https://github.com/itflow-org/itflow/security/policy).

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/TheTractorHacker/RivetIT.svg?style=for-the-badge
[contributors-url]: https://github.com/TheTractorHacker/RivetIT/graphs/contributors
[stars-shield]: https://img.shields.io/github/stars/TheTractorHacker/RivetIT.svg?style=for-the-badge
[stars-url]: https://github.com/TheTractorHacker/RivetIT/stargazers
[license-shield]: https://img.shields.io/github/license/TheTractorHacker/RivetIT.svg?style=for-the-badge
[license-url]: LICENSE
[commit-shield]: https://img.shields.io/github/last-commit/TheTractorHacker/RivetIT?style=for-the-badge
[commit-url]: https://github.com/TheTractorHacker/RivetIT/commits/main
