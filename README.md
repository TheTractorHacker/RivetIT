<div id="top"></div>

<!-- PROJECT SHIELDS -->
[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Commits][commit-shield]][commit-url]
[![GPL License][license-shield]][license-url]

<div align="center">

  <h3 align="center">ITFlow — Internal IT Edition</h3>

  <p align="center">
    A fork of <a href="https://github.com/TheTractorHacker/itflow">ITFlow MSP Edition</a> (itself a fork of <a href="https://github.com/itflow-org/itflow">ITFlow</a>), repurposed for internal IT teams instead of MSPs — one organization, many departments, no client billing.
    <br />
    <br />
    <a href="https://github.com/itflow-org/itflow">Upstream Project</a>
    ·
    <a href="https://docs.itflow.org">Docs</a>
    ·
    <a href="docs/ARCHITECTURE.md">Architecture</a>
    ·
    <a href="docs/API.md">API Reference</a>
    ·
    <a href="https://github.com/TheTractorHacker/ITFlow-Internal-IT/releases">Releases</a>
    ·
    <a href="https://github.com/TheTractorHacker/ITFlow-Internal-IT/issues">Report Bug</a>
  </p>
</div>

---

> **This is a fork.** It started from a snapshot of [TheTractorHacker/itflow](https://github.com/TheTractorHacker/itflow) (an MSP-focused fork of upstream [itflow-org/itflow](https://github.com/itflow-org/itflow)) and now tracks its own independent history. All original credit goes to the ITFlow contributors; MSP workflow additions credit to Foley IT / TractorHacker. Internal-IT-specific changes are maintained here.

---

<!-- ABOUT -->
## About

**ITFlow Internal IT Edition** takes ITFlow — the free and open-source IT documentation, ticketing, and asset management platform — and repurposes its MSP data model (one provider, many billed clients) for an internal IT team supporting a single organization's own departments instead.

Compared to ITFlow MSP Edition, this fork:

- **Disables billing/accounting by default** — invoicing, quotes, payments, recurring invoices, expenses, and the QuickBooks integration are all off out of the box (re-enable anytime in *Settings > Modules*, nothing is removed from the codebase).
- **Uses "Client" records as departments** — each "client" in the data model represents an internal department, site, or business unit rather than an external customer.
- **Keeps Contracts** for documenting SLA/service terms per department, since that's still useful without external billing.
- **Adds AnyDesk quick-connect** — a dedicated AnyDesk ID field on assets with a one-click Connect button on the asset details page.

Ticketing, assets/IT documentation, knowledge base, contracts, credentials, projects, and the RMM integrations all work the same as upstream ITFlow.

New to this codebase? [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) covers the admin/agent/client/guest portal structure, auth & permission model, data model, module toggles, integrations, and how this edition differs from ITFlow MSP. [docs/API.md](docs/API.md) is the narrative companion to the REST API — including a note on why the API still says "client" while the UI says "department".

---

## What's Inherited From ITFlow MSP Edition

This fork's base (before the internal-IT changes above) already included:

### Ticket Automation
- **Rule-based automation engine** — create rules that run automatically on every cron cycle
- **Conditions**: ticket age, idle time since last reply, priority, status, assigned user, or **ticket category** (On-Site, Remote, Project, etc.)
- **Actions**: set priority, assign to user, set status, add internal note, notify assignee, close ticket, or **automatically attach a worksheet template**

### Cron Manager
- **Web UI cron scheduler** — change the main cron schedule without touching the server, plus a **Run Now** button

### Ticketing
- **Ticket categories** with parent/group hierarchy and collapsible grouped list view
- **Inline pill-style dropdowns** — change Category, Assigned Tech, Priority, and Status directly from the ticket list
- **Appointments** — end time, duration picker, Remote/Onsite toggle, appointment notes, live preview
- **Ticket reply draft autosave**

### Worksheets
- **Unfinalize button**, drag-and-drop field reordering, percent-complete counter, automation-attached templates

### Calendar & Scheduling
- **Outlook Calendar push sync** per technician via Microsoft Graph API
- **iCal subscription feed** for Outlook Classic, Apple Calendar, or Google Calendar
- **Per-tech calendar colors**

### Contracts & SLA
- **SLA tracking on contracts** — response/resolution hours per priority tier
- **Live SLA hint on ticket add**

### REST API
- Full REST API layer under `/api/v1/` — tickets, clients (departments), assets, contacts, locations, credentials, worksheets, charges, appointments, search, reports
- Full reference: [docs/API.md](docs/API.md) (narrative guide) · in-app searchable reference at Settings → API Docs (`/api/v1/docs` on your own instance)

---

## Getting Started

The fastest path to a running instance on a fresh Ubuntu/Debian box is the deployment tooling in
[`deploy/`](deploy/README.md):

```bash
git clone https://github.com/TheTractorHacker/ITFlow-Internal-IT.git
cd ITFlow-Internal-IT
sudo deploy/install.sh --domain=itflow.example.com
```

It provisions nginx, PHP 8.4, and MariaDB; sets up TLS; applies security hardening; and runs the app's
own first-run setup — see [`deploy/README.md`](deploy/README.md) for the full flag reference, worked
examples (including adding a second company's instance to a box that already runs one), backups, and
updates. If you'd rather install manually or use the browser-based `/setup/` wizard, see the [official
upstream docs](https://docs.itflow.org/installation) for general server requirements.

For a control-by-control look at what this deployment tooling does (and doesn't) cover from a security
standpoint, see [`docs/ISO27001-COMPLIANCE.md`](docs/ISO27001-COMPLIANCE.md).

## License

ITFlow is distributed under the GPL License. This fork inherits the same license. See [`LICENSE`](LICENSE) for details.

## Security

If you find a security issue in the upstream project, report it [here](https://github.com/itflow-org/itflow/security/policy).
For issues specific to this fork, open an [issue](https://github.com/TheTractorHacker/ITFlow-Internal-IT/issues).

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/TheTractorHacker/ITFlow-Internal-IT.svg?style=for-the-badge
[contributors-url]: https://github.com/TheTractorHacker/ITFlow-Internal-IT/graphs/contributors
[stars-shield]: https://img.shields.io/github/stars/TheTractorHacker/ITFlow-Internal-IT.svg?style=for-the-badge
[stars-url]: https://github.com/TheTractorHacker/ITFlow-Internal-IT/stargazers
[license-shield]: https://img.shields.io/github/license/TheTractorHacker/ITFlow-Internal-IT.svg?style=for-the-badge
[license-url]: https://github.com/itflow-org/itflow/blob/master/LICENSE
[commit-shield]: https://img.shields.io/github/last-commit/TheTractorHacker/ITFlow-Internal-IT?style=for-the-badge
[commit-url]: https://github.com/TheTractorHacker/ITFlow-Internal-IT/commits/main
