<div id="top"></div>

<!-- PROJECT SHIELDS -->
[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Commits][commit-shield]][commit-url]
[![GPL License][license-shield]][license-url]

<div align="center">

  <h3 align="center">ITFlow — MSP Edition</h3>

  <p align="center">
    A fork of <a href="https://github.com/itflow-org/itflow">ITFlow</a> with extended MSP workflow features built by <a href="https://foleyit.com">Foley IT</a>.
    <br />
    <br />
    <a href="https://github.com/itflow-org/itflow">Upstream Project</a>
    ·
    <a href="https://docs.itflow.org">Docs</a>
    ·
    <a href="https://github.com/TheTractorHacker/itflow/releases">Releases</a>
    ·
    <a href="https://github.com/TheTractorHacker/itflow/issues">Report Bug</a>
    ·
    <a href="https://github.com/TheTractorHacker/itflow-msp-app">📱 Android App</a>
  </p>
</div>

---

> **This is a fork.** It tracks the upstream [itflow-org/itflow](https://github.com/itflow-org/itflow) and merges updates regularly. All original credit goes to the ITFlow contributors. MSP-specific additions are maintained here by Foley IT / TractorHacker.

---

<!-- ABOUT -->
## About

**ITFlow MSP Edition** is a hardened, feature-extended build of ITFlow — the free and open-source IT documentation, ticketing, and accounting platform for managed service providers.

This fork adds real-world MSP dispatch and scheduling workflows that go beyond the upstream project, while staying in sync with upstream security patches and improvements.

We also built a **native Android app** from scratch to go alongside this fork — giving technicians full mobile access to tickets, assets, clients, worksheets, and more. Check it out at [TheTractorHacker/itflow-msp-app](https://github.com/TheTractorHacker/itflow-msp-app).

---

## 📱 Android App

A native Android companion app is available at **[TheTractorHacker/itflow-msp-app](https://github.com/TheTractorHacker/itflow-msp-app)**.

Built with Kotlin + Jetpack Compose + Material 3. Features include:

- Dashboard with open ticket counts, recent activity, and alerts
- Full ticket management — view, reply, change status, assign, add charges
- Asset browsing and detail view with barcode/QR scanner
- Client list with contacts, locations, and credentials
- Worksheet viewing and response entry
- Global search across tickets, clients, and assets
- Push notifications for new tickets and assignments

> Requires your ITFlow MSP Edition server running **v2.4.12+** with the REST API enabled.

---

<!-- MSP ADDITIONS -->
## What's Added in This Fork

### Ticket Automation
- **Rule-based automation engine** — create rules that run automatically on every cron cycle
- **Conditions**: ticket age, idle time since last reply, priority, status, assigned user, or **ticket category** (On-Site, Remote, Project, etc.)
- **Actions**: set priority, assign to user, set status, add internal note, notify assignee, close ticket, or **automatically attach a worksheet template**
- Smart dropdowns in the rule builder — category and worksheet selects populate from your live data

### Cron Manager
- **Web UI cron scheduler** — change the main cron schedule (5 min / 15 min / 30 min / hourly / custom) without touching the server
- **Run Now** button to trigger cron immediately from the admin panel
- Shows last successful run time and all scheduled cron jobs

### Ticketing
- **Ticket categories** with parent/group hierarchy and collapsible grouped list view
- **Inline pill-style dropdowns** — change Category, Assigned Tech, Priority, and Status directly from the ticket list without opening the ticket
- **Syncro-style appointments** — end time, duration picker (30 min – 8 hr), Remote/Onsite toggle, appointment notes, and live preview
- **Ticket reply draft autosave** — localStorage autosave with Restore/Discard banner so replies survive accidental navigation

### Worksheets
- **Unfinalize button** — unlock a finalized worksheet to edit it again (unavailable on client-signed worksheets)
- **Worksheet template drag-and-drop field reordering**
- **Worksheet percent counter** — accurately counts all field types
- **Automation-attached worksheets** — rules can auto-attach a worksheet template when a ticket matches a condition

### Calendar & Scheduling
- **Outlook Calendar push sync** — each technician connects their Microsoft account once; scheduled tickets automatically create, update, and cancel events in their personal Outlook calendar via Microsoft Graph API
- **iCal subscription feed** — per-user webcal:// URL for subscribing scheduled tickets into Outlook Classic, Apple Calendar, or Google Calendar
- **Per-tech calendar colors** — each technician picks a color shown on the ITFlow dispatch calendar

### Contracts & SLA
- **SLA tracking on contracts** — define response/resolution hours per priority tier
- **Contract billing frequency** — Monthly, Quarterly, Annual, or Other
- **Live SLA hint on ticket add** — shows expected response/resolution time when a contract is selected

### REST API (for Mobile App)
- Full REST API layer under `/api/v1/` powering the Android app
- Endpoints: tickets, clients, assets, contacts, locations, credentials, worksheets, charges, appointments, search, reports
- Token-based auth with rate limiting, token expiry, and payload size limits

### Security Fixes (beyond upstream)
- Fixed authorization bypass on ticket charge handlers (client access not enforced)
- Fixed XSS in outtake/worksheet signature storage
- Fixed SQL injection in contract name logAction call

---

<!-- SYNCING WITH UPSTREAM -->
## Keeping Up With Upstream

This fork merges upstream changes periodically:

```bash
git fetch origin        # origin = itflow-org/itflow
git merge origin/master
git push fork master    # fork = TheTractorHacker/itflow
```

<!-- GETTING STARTED -->
## Getting Started

Installation is the same as upstream ITFlow. See the [official docs](https://docs.itflow.org/installation).

```bash
wget -O itflow_install.sh https://github.com/itflow-org/itflow-install-script/raw/main/itflow_install.sh
bash itflow_install.sh
```

After installing, replace the files with this fork's content or clone this repo directly into your web root.

<!-- RELEASES -->
## Releases

| Release | Notes |
|---------|-------|
| v2.6.0 | Ticket automation rules, Cron Manager UI, worksheet unfinalize, Android app |
| v2.5.2 | Outtake forms, ticket filters, worksheet delete |
| v2.5.0 | Worksheet/charge creation, onsite tracking, products API |
| v1.2.6-msp | Webhooks, Passkeys, Backup, Comet, Contract Docs |
| v1.2.5-msp | Outlook push sync, per-tech calendar colors, modal bug fixes |
| v1.2.3-msp | Categories, SLA, contracts, worksheets, inline ticket actions |

See [all releases](https://github.com/TheTractorHacker/itflow/releases) for full changelogs.

## License

ITFlow is distributed under the GPL License. This fork inherits the same license. See [`LICENSE`](https://github.com/itflow-org/itflow/blob/master/LICENSE) for details.

## Security

If you find a security issue in the upstream project, report it [here](https://github.com/itflow-org/itflow/security/policy).
For issues specific to this fork, open an [issue](https://github.com/TheTractorHacker/itflow/issues).

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/TheTractorHacker/itflow.svg?style=for-the-badge
[contributors-url]: https://github.com/TheTractorHacker/itflow/graphs/contributors
[stars-shield]: https://img.shields.io/github/stars/TheTractorHacker/itflow.svg?style=for-the-badge
[stars-url]: https://github.com/TheTractorHacker/itflow/stargazers
[license-shield]: https://img.shields.io/github/license/TheTractorHacker/itflow.svg?style=for-the-badge
[license-url]: https://github.com/itflow-org/itflow/blob/master/LICENSE
[commit-shield]: https://img.shields.io/github/last-commit/TheTractorHacker/itflow?style=for-the-badge
[commit-url]: https://github.com/TheTractorHacker/itflow/commits/master
