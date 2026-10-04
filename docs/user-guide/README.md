# RivetIT user guide

How each part of RivetIT works, one page per module, with screenshots. The guide is written for the people who use RivetIT every day: **IT staff** (technicians and administrators) and, where a module has an employee side, the **employees** they support.

Every page has the same shape: what the module is for, where to find it, who can use it, a screenshot tour with numbered call-outs, step-by-step tasks, a reference section and tips. If you only want pictures, the [visual tour](visual-tour.md) shows every module on one page.

## Start here

| If you are... | Read |
|---|---|
| Installing RivetIT for the first time | [First-time setup](00-first-time-setup.md), then [Administration: system settings](13-administration-settings.md) |
| A technician on your first day | [Getting started](01-getting-started.md), then [Service Desk: tickets](03-service-desk.md) |
| An administrator adding your team | [Administration: users, roles and security](12-administration-users-and-security.md), then [Departments and people](02-departments-and-people.md) |
| Responsible for documentation | [Assets](06-assets.md), [Knowledge Base](05-knowledge-base.md) and [Credentials](05b-credentials-printers-network-drives.md) |
| Running compliance training | [Training: courses and content](07-training-courses-and-content.md), then [assignments and records](08-training-assignments-and-records.md) |
| Rolling the portal out to employees | [Department Portal](11-employee-portal.md) |

## All pages

### Set up and get around

| Page | What it covers |
|---|---|
| [First-time setup](00-first-time-setup.md) | The browser installer, step by step, and what to do straight afterwards. |
| [Getting started](01-getting-started.md) | Signing in, the menus, the three navigation scopes, your account, roles, and the list controls every page shares. |

### Daily work

| Page | What it covers |
|---|---|
| [Departments and people](02-departments-and-people.md) | Departments, department workspaces, the org chart, and the people in them. |
| [Onboarding, offboarding and people import](02b-people-import-and-workflows.md) | Importing people from a spreadsheet and running onboarding and offboarding checklists. |
| [Service Desk: tickets](03-service-desk.md) | The ticket list, creating and working tickets, time, tasks and replies. |
| [Recurring tickets and Request service](03b-recurring-tickets-and-request-catalog.md) | Tickets that raise themselves on a schedule, and one-click request tiles. |
| [Problems, changes, requests and CSAT](03c-problems-changes-mail-requests-csat.md) | Root-cause tracking, change planning, mail from unknown senders, and satisfaction ratings. |
| [Projects and calendar](04-projects-and-calendar.md) | Projects with milestones, tasks and a Kanban board; the shared calendar. |

### Documentation

| Page | What it covers |
|---|---|
| [Knowledge Base](05-knowledge-base.md) | Writing, importing, sharing and versioning articles. |
| [Credentials, printers and network drives](05b-credentials-printers-network-drives.md) | The encrypted credential vault and two small reference lists. |
| [Assets](06-assets.md) | Devices: who has them, where they are, and how they are connected. |
| [Locations, vendors, licenses, domains and certificates](06b-locations-vendors-licenses-domains-certificates.md) | The records around your devices, including expiry tracking. |
| [Networks, racks, services, contracts and files](06c-networks-racks-services-contracts-files.md) | Network layout, rack diagrams, service dependencies, support contracts and documents. |

### Training

| Page | What it covers |
|---|---|
| [Courses and content](07-training-courses-and-content.md) | Building and publishing courses and required documents. |
| [Quizzes, paths and badges](07b-training-quizzes-paths-and-badges.md) | Quizzes, the question library, learning paths and achievements. |
| [Assignments, records and reports](08-training-assignments-and-records.md) | Deciding who must take what, by when, and checking they did. |
| [Kiosks, learners and certificates](08b-training-kiosks-learners-certificates.md) | The kiosk employees train on, PIN sign-in, certificates and the public certificate check. |

### Connections and insight

| Page | What it covers |
|---|---|
| [Endpoints and integrations](09-endpoints-and-integrations.md) | RMM, Intune, UniFi, Sophos Central and Comet backups. |
| [Dashboard and reports](10-dashboard-and-reports.md) | The dashboard, the report hub, date ranges, exports and scheduled reports. |
| [Report reference](10b-report-reference.md) | Every report, what it measures and how to read it. |
| [Department Portal](11-employee-portal.md) | What employees see and do, and how IT sets it up. |

### Administration

| Page | What it covers |
|---|---|
| [Users, roles and security](12-administration-users-and-security.md) | Accounts, roles and permissions, sign-in security, API keys and the audit logs. |
| [System settings, mail and maintenance](13-administration-settings.md) | Modules, company details, notifications, mail, scheduled jobs, backups and updates. |
| [Ticketing, automation and organising data](13b-administration-ticketing-and-automation.md) | Ticket settings, statuses, SLAs, templates, automation rules, categories and tags. |
| [Integrations, webhooks and AI](13c-administration-integrations.md) | The Integrations hub, webhooks, AI providers, Outlook calendar sync and the Telemetry page. |

## How to read the pages

- **Words in bold** are labels exactly as they appear on screen, for example **New Ticket**. An arrow shows a menu path: Administration → Settings → **Modules**.
- **Numbered call-outs.** Screenshots carry red numbered badges. The text below the picture says what each one points to, as **(1)**, **(2)** and so on.
- **Who can use it.** Each page names the permission it needs and the level (Read, Modify or Full). Administrators can see everything; other roles see only what an administrator has granted. See [Roles and what you see](01-getting-started.md#roles-and-what-you-see).
- **Notes about odd behaviour.** Where the application does something surprising, the page says so plainly in a **Note**, so you do not lose time wondering whether you did something wrong.
- **What this guide covers.** It documents RivetIT as an internal IT platform: service desk, documentation, training, endpoints, reporting and administration. Billing, accounting and CRM features are not covered.
- **Names.** RivetIT calls a client a **Department**, an employee a **Person**, and a member of IT staff an **Agent**.

All the names, addresses and numbers in the screenshots belong to a made-up company, Summit Ridge Manufacturing. None of it is real.

## Screenshots without call-outs

The numbered red call-outs are drawn on top of the pictures in `images/`. A second copy of every picture, with the same
folder and file names but no call-outs, is in [`images-clean/`](images-clean/README.md), for reuse outside the guide
(a web site, slides).

## Keeping the screenshots current

The pictures are generated, not drawn by hand. One command builds a demo instance and another re-shoots every image, so they can be refreshed whenever the interface changes. See [tools/README.md](tools/README.md).
