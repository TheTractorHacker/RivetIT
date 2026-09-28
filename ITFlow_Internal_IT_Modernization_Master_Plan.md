# ITFlow Internal IT — Master Modernization Plan

> **Historical document.** Written before the product was renamed **RivetIT** (September 2026); "ITFlow Internal IT" here is the same product. Names, paths and plans are kept as written. See [REBRANDING.md](REBRANDING.md).

## 1. Project Goal

Transform the existing ITFlow fork into a modern, purpose-built **Internal IT Operations Platform** for a single organization with multiple departments and locations.

The application should no longer feel like an MSP PSA adapted for internal use.

It should feel comparable in polish to products such as:

- NinjaOne
- Hudu
- Microsoft Intune
- Linear
- HaloITSM
- Jira Service Management

while retaining the simplicity and maintainability of the existing application.

### Core product philosophy

The interface should answer:

1. What needs my attention?
2. What is broken?
3. What am I responsible for?
4. What changed recently?
5. Is the environment healthy?
6. Where is the documentation I need?
7. Can an employee easily request help?

---

# 2. Existing Architecture

Do **not** rewrite the application.

Keep the existing stack.

## Backend

```text
PHP 8.4
MariaDB
mysqli
nginx
PHP-FPM
Redis
SSE
PHP CLI cron jobs
REST API
```

## Frontend

```text
Bootstrap 5
AdminLTE 4
Font Awesome
jQuery
Vanilla JavaScript
Chart.js
TinyMCE
Tom Select
Simple-DataTables
FullCalendar
Leaflet
Frappe Gantt
SortableJS
Dropzone
etc.
```

## Important rule

Do not introduce:

```text
Laravel
Symfony
React
Vue
Angular
Node build process
Webpack
Vite
Tailwind
```

unless there is a future explicit architectural decision to do so.

The goal is:

> Modernize the application without making deployment or maintenance significantly more complicated.

---

# 3. Development Philosophy

Claude should make changes incrementally.

Do not attempt a giant rewrite.

Each change should:

```text
1. Understand existing behavior
2. Preserve functionality
3. Introduce reusable components
4. Improve UI/UX
5. Test the affected workflow
6. Remove obsolete CSS only when confirmed unused
7. Commit logically
```

Avoid changing business logic during purely visual work.

---

# 4. Git Strategy

Create a dedicated modernization branch.

Example:

```bash
git checkout -b feature/ui-modernization
```

Major phases can receive their own branch where appropriate:

```text
feature/design-system
feature/navigation-v2
feature/dashboard-v2
feature/ticket-ui-v2
feature/assets-v2
feature/kb-v2
feature/credentials-v2
feature/rmm-v2
feature/reports-v2
```

Use small commits.

Examples:

```text
feat(ui): introduce ITFlow design tokens

feat(nav): redesign main application sidebar

feat(tickets): add modern ticket activity timeline

refactor(ui): migrate stat cards to reusable component

fix(mobile): improve ticket page responsive behavior
```

---

# 5. Visual Identity

Create a consistent visual identity for the Internal IT edition.

## Brand personality

The platform should feel:

- Professional
- Calm
- Modern
- Technical
- Reliable
- Clean
- Information-dense without being cluttered

Avoid:

- Giant gradients
- Excessive animations
- Neon colors
- Gaming-style UI
- Heavy shadows
- Excessively rounded components
- Huge whitespace
- Bootstrap-default styling

---

# 6. Design System

The first major task should be creating the design system.

Primary file:

```text
css/itflow_design.css
```

Eventually this should become the authoritative style layer.

## Design tokens

Implement CSS variables.

Example:

```css
:root {

    /* Backgrounds */

    --it-bg: #f6f8fb;
    --it-surface: #ffffff;
    --it-surface-secondary: #f8fafc;
    --it-surface-hover: #f1f5f9;

    /* Sidebar */

    --it-sidebar-bg: #17202d;
    --it-sidebar-hover: #222d3d;
    --it-sidebar-active: #2d3b50;

    /* Brand */

    --it-primary: #2563eb;
    --it-primary-hover: #1d4ed8;
    --it-primary-soft: #eff6ff;

    /* Semantic */

    --it-success: #16a34a;
    --it-success-soft: #f0fdf4;

    --it-warning: #d97706;
    --it-warning-soft: #fffbeb;

    --it-danger: #dc2626;
    --it-danger-soft: #fef2f2;

    --it-info: #0891b2;
    --it-info-soft: #ecfeff;

    /* Text */

    --it-text: #111827;
    --it-text-secondary: #475569;
    --it-text-muted: #64748b;

    /* Borders */

    --it-border: #e2e8f0;
    --it-border-strong: #cbd5e1;

    /* Radius */

    --it-radius-sm: 6px;
    --it-radius-md: 10px;
    --it-radius-lg: 14px;

    /* Shadows */

    --it-shadow-sm:
        0 1px 2px rgba(15,23,42,.04),
        0 1px 3px rgba(15,23,42,.06);

    --it-shadow-md:
        0 4px 12px rgba(15,23,42,.08);

    /* Layout */

    --it-sidebar-width: 255px;
    --it-header-height: 62px;
}
```

Do not scatter random colors throughout page-specific files.

---

# 7. Typography

Use a modern system font stack initially.

Example:

```css
font-family:
    Inter,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    Roboto,
    Helvetica,
    Arial,
    sans-serif;
```

If Inter is eventually bundled locally, use it.

Target:

```text
Page titles        24-28px
Section titles     17-20px
Card titles        14-16px
Body               14px
Secondary text     12-13px
Navigation         14px
```

Avoid the tiny text currently visible throughout ITFlow.

---

# 8. Create a Reusable UI Component System

Create:

```text
includes/ui/
```

Suggested components:

```text
page_header.php
stat_card.php
card.php
status_badge.php
priority_badge.php
empty_state.php
filter_bar.php
search_bar.php
data_table.php
activity_item.php
user_avatar.php
device_status.php
detail_panel.php
tabs.php
modal.php
confirm_modal.php
toast.php
```

Keep implementation simple.

Do not build a complicated component framework.

The purpose is to prevent pages from repeatedly generating different versions of the same Bootstrap markup.

---

# 9. Core CSS Components

Standardize these classes:

```text
.it-page
.it-page-header
.it-page-title
.it-page-subtitle
.it-page-actions

.it-card
.it-card-header
.it-card-body

.it-stat-grid
.it-stat-card
.it-stat-icon
.it-stat-value
.it-stat-label

.it-status-pill
.it-priority-pill

.it-toolbar
.it-filter-bar

.it-data-table

.it-empty-state
.it-empty-icon
.it-empty-title

.it-detail-layout
.it-detail-main
.it-detail-sidebar

.it-activity-feed
.it-activity-item

.it-command-button

.it-section
.it-section-header
```

---

# 10. Global Application Shell

Rebuild the application chrome first.

That includes:

```text
Sidebar
Top bar
Global search
Page content wrapper
Notifications
User menu
Create button
Breadcrumbs
```

---

# 11. Sidebar Redesign

Current navigation is becoming too large.

Replace the enormous flat list with grouped navigation.

Recommended structure:

```text
OVERVIEW

Dashboard
Alerts


SERVICE DESK

Tickets
Requests
Problems
Changes
Recurring Tickets


WORK

Projects
Calendar


ORGANIZATION

Departments
People
Locations
Vendors


KNOWLEDGE

Knowledge Base
Credentials
Files


INFRASTRUCTURE

Assets
Network
Racks
Licenses
Domains
Certificates


ENDPOINT MANAGEMENT

RMM Dashboard
Devices
RMM Alerts
Scripts
Policies
Software
Patching


INTEGRATIONS

Microsoft 365
Odoo
UniFi
RMM
Webhooks


ADMINISTRATION

Reports
Automations
Audit Log
Settings
```

---

# 12. Collapsible Navigation Sections

Sections such as Endpoint Management should collapse.

Example:

```text
▼ Endpoint Management
    RMM Dashboard
    Devices
    Alerts
    Scripts
    Policies
```

Collapsed:

```text
▶ Endpoint Management
```

Store preference locally or in the user's profile.

---

# 13. Collapsed Sidebar Mode

Allow:

```text
255px sidebar
      ↓
70px icon sidebar
```

Persist the preference.

Tooltips should appear when collapsed.

---

# 14. Top Navigation

Replace the current top bar with:

```text
☰     Search anything...

                      + Create    🔔    Blake Foley ▼
```

---

# 15. Global Create Menu

The `+ Create` button should open:

```text
New Ticket
New Request
New Change
New Project
New Asset
New KB Article
New Credential
```

Respect authorization permissions.

---

# 16. Global Search

The search box should eventually behave like a command palette.

Search:

```text
Tickets
Assets
People
KB articles
Credentials
Locations
Departments
Projects
Domains
Licenses
```

Eventually support:

```text
Ctrl + K
```

to open universal search.

Example:

```text
Search anything...

TCK-1045
Laptop overheating
Ticket

PC-0041
HILARY-LT
Asset

VPN Setup
Knowledge Base
```

---

# 17. Dashboard V2

The dashboard should become an actual operational overview.

Header:

```text
Good morning, Blake

Here's what needs your attention today.
```

## Primary statistics

Suggested cards:

```text
Open Tickets

Assigned to Me

Critical Alerts

Endpoint Health

Pending Requests

Upcoming Changes
```

Cards should stay white.

Use small semantic accent indicators instead of giant colored backgrounds.

---

# 18. Needs Attention Widget

Create a unified attention feed.

Example:

```text
NEEDS ATTENTION

● PC-142          Offline                  32 min

⚠ TCK-1094        SLA approaching          48 min

↻ PC-097          Reboot required           2 hr

! CERT-04         Expires in 14 days
```

---

# 19. My Work Widget

Show:

```text
Assigned Tickets
Open Changes
Current Projects
Pending Approvals
Scheduled Tasks
```

---

# 20. Environment Health Widget

Example:

```text
Endpoints             132
Online                128
Offline                 4

Servers                 12
Patch Compliance       97%
Critical Alerts          3
Pending Reboots          8
```

---

# 21. Recent Activity

Unified activity feed:

```text
Blake closed TCK-1041

Microsoft synchronization completed

PC-122 installed update KBxxxx

Jennifer created a service request

Odoo employee provisioning completed
```

---

# 22. Ticketing System V2

Ticketing should become one of the most polished areas.

Current ticket page should be redesigned into:

```text
Ticket Header

Activity Timeline              Properties Panel
```

---

# 23. Ticket Header

Example:

```text
TCK-1092

Hilary's Browser Glitches

● Closed

Hilary Johnson
Office
Medium
On-Site

Created Sep 2
Updated Sep 2
```

Primary actions:

```text
Reply
Internal Note
Assign
Change Status
More
```

---

# 24. Ticket Activity Timeline

Replace giant comment cards.

Use compact timeline items.

Types:

```text
Requester Message

Technician Reply

Internal Note

System Event

Assignment Change

Priority Change

Status Change

Time Entry

Attachment

Automation Event
```

Each type should be visually distinguishable.

---

# 25. Ticket Properties Panel

Right sidebar:

```text
Status
Priority
Requester
Department
Location
Assigned Technician
Assets
Category
Tags
SLA
Time Worked
Created
Updated
```

Make most fields editable inline.

---

# 26. Ticket Composer

At bottom:

```text
Reply | Internal Note
```

Features:

```text
Rich text
Attachments
Images
Mention technician
Insert KB article
Insert canned response
Add time
Change status after sending
```

---

# 27. Ticket List

Improve current list with:

```text
Saved Views
Board filters
Search
Tags
Status
Priority
Department
Assignee
Location
Date range
```

Views:

```text
My Tickets
Unassigned
All Open
Overdue
Waiting for User
On-Site
Remote
Recently Closed
```

---

# 28. Service Requests

Separate incidents from service requests more clearly.

Examples:

```text
Request New Laptop
Software Installation
Shared Mailbox
New Employee
Employee Termination
Access Request
Printer Access
VPN Access
Mobile Device
Hardware Purchase
```

---

# 29. Request Catalog

Build a visually friendly request catalog.

Example:

```text
Hardware

[ Laptop ]
[ Monitor ]
[ Dock ]
[ Mouse / Keyboard ]


Access

[ Shared Mailbox ]
[ Folder Access ]
[ Software Access ]


Employee

[ New Hire ]
[ Role Change ]
[ Offboarding ]
```

---

# 30. Employee Onboarding Workflows

Major internal IT feature.

Templates should support tasks such as:

```text
Create AD account
Create Microsoft 365 account
Assign licenses
Create Odoo account
Add security groups
Create email
Configure MFA
Assign device
Deploy standard applications
Create phone extension
Assign building access
Add shared mailbox permissions
Create department memberships
Notify manager
Schedule first-day setup
```

---

# 31. Employee Offboarding

Template:

```text
Disable AD
Disable Microsoft login
Revoke sessions
Reset password
Remove MFA sessions
Archive mailbox
Convert mailbox to shared
Remove licenses
Disable Odoo
Disable VPN
Remove security groups
Collect equipment
Disable access card
Archive OneDrive
Assign mailbox delegate
Schedule account deletion
```

Every step should be auditable.

---

# 32. Problems

Use ITIL-style problem records.

Show:

```text
Impact
Known Error
Affected Assets
Related Tickets
Root Cause
Workaround
Resolution
Timeline
```

Allow linking multiple incidents.

---

# 33. Change Management

Change records should include:

```text
Title
Description
Reason
Risk
Impact
Implementation Plan
Rollback Plan
Testing Plan
Maintenance Window
Approvers
Assets
Locations
Users affected
```

Statuses:

```text
Draft
Pending Approval
Approved
Scheduled
In Progress
Completed
Failed
Rolled Back
```

---

# 34. Projects

Improve current project management UI.

Add:

```text
Overview
Tasks
Milestones
Timeline
Gantt
Assets
Tickets
Changes
Files
Notes
Activity
```

---

# 35. Knowledge Base V2

Transform KB into a documentation platform.

Layout:

```text
Categories         Article              Details

Networking         VPN Setup            Owner
Servers            ...                  Review Date
Microsoft                              Related Assets
Odoo                                   Tags
Security                               History
```

---

# 36. KB Features

Implement eventually:

```text
Favorites
Recently Viewed
Drafts
Published articles
Revision History
Article Owner
Review Date
Expiration Date
Tags
Related Articles
Related Assets
Related Credentials
Attachments
Markdown
Rich Text
Templates
Search
```

---

# 37. Documentation Review System

Articles should support:

```text
Last reviewed
Review interval
Next review
Owner
```

Example:

```text
Last reviewed: Aug 14, 2026

Next review:
Nov 14, 2026

Owner:
Blake Foley
```

Dashboard alert if overdue.

---

# 38. Password / Credential Manager V2

Rename UI terminology to:

**Credentials**

rather than Password Manager where appropriate.

Credential types:

```text
Username / Password
API Token
SSH Credential
Device Credential
Service Account
Database Credential
Encryption Key
Recovery Code
Other
```

---

# 39. Credential Security

Features:

```text
Encrypted storage
Reveal permission
Copy permission
Edit permission
Audit logging
TOTP
Rotation date
Expiration date
Owner
Department
Related asset
Related service
Related documentation
```

Never expose secrets in audit logs.

---

# 40. Credential Rotation

Track:

```text
Last rotated
Rotation interval
Next rotation
Rotation owner
```

Dashboard alert:

```text
3 credentials due for rotation
```

---

# 41. Asset Management V2

Assets need first-class object pages.

Asset header:

```text
HILARY-LT

Dell Latitude 7450

● Online

Hilary Johnson
Office
Windows 11 Pro
Warranty until Feb 2029
```

---

# 42. Asset Tabs

```text
Overview
Hardware
Software
RMM
Tickets
Changes
Credentials
Network
Warranty
Files
Activity
```

---

# 43. Asset Overview

Display:

```text
Hostname
Serial
Model
Manufacturer
Assigned user
Department
Location
IP
MAC
OS
Last check-in
RMM agent
Warranty
Purchase date
Lifecycle state
```

---

# 44. Lifecycle Management

Asset states:

```text
Ordered
Inventory
Preparing
Deployed
Repair
Loaner
Retired
Disposed
Lost
```

---

# 45. People / Contacts Redesign

Internal IT should primarily use the term:

**People**

rather than Contacts.

Profile:

```text
Hilary Johnson

Office
Accounting
Employee

Email
Phone
Manager

Assigned Assets
Open Tickets
Requests
Licenses
Groups
Microsoft Account
Odoo Account
Activity
```

---

# 46. Microsoft 365 Integration

Build dedicated Microsoft integration UI.

Sections:

```text
Users
Licenses
Groups
Shared Mailboxes
Devices
Sync Status
Provisioning
Audit
```

---

# 47. Microsoft Sync

Show:

```text
Last Sync
Next Sync
Users synced
Errors
Created
Updated
Disabled
```

Allow manual sync.

---

# 48. Odoo Integration

Similar UI:

```text
Users
Employees
Departments
Roles
Sync Jobs
Mapping
Errors
```

Employee onboarding workflows should be able to provision Odoo accounts.

---

# 49. RMM Dashboard V2

Keep the existing functionality but redesign it.

Top:

```text
132 Managed Devices

128 Online
4 Offline
3 Critical
8 Pending Restart
97% Patch Compliance
```

---

# 50. RMM Attention Feed

Primary screen should emphasize exceptions.

Example:

```text
Needs Attention

SERVER-01
Disk space critical
4 minutes ago

PC-044
Offline
32 minutes ago

PC-082
Updates failed
2 hours ago
```

---

# 51. Device Page

Device profile:

```text
Overview
Hardware
Software
Patching
Services
Processes
Alerts
Scripts
Remote Access
Tickets
Activity
```

---

# 52. Software Inventory

Eventually support:

```text
Installed applications
Versions
Install count
Outdated versions
Unauthorized applications
```

Example:

```text
Google Chrome

Installed: 112
Current: 106
Outdated: 6
```

---

# 53. Patching

Create dedicated patch dashboard.

```text
Compliance
Windows Updates
Pending Reboots
Failed Updates
Excluded Updates
Recently Installed
```

---

# 54. Scripts

Modern script library.

Categories:

```text
Maintenance
Diagnostics
Networking
Windows
Microsoft 365
Security
Software
Custom
```

Script page:

```text
Description
Language
Requirements
Parameters
Version
Last modified
Run history
Output
```

---

# 55. Network Documentation

Network should show:

```text
Networks
VLANs
Subnets
Gateways
DNS
DHCP
Switches
Firewalls
Access Points
WANs
VPNs
```

---

# 56. Network Map

Use existing Leaflet and potentially a logical topology view.

Allow relationships:

```text
Location
   ↓
Firewall
   ↓
Core Switch
   ↓
Access Switch
   ↓
AP / Device
```

---

# 57. Locations

Modern location profile.

Example:

```text
Main Office

123 Example Street

42 Users
61 Assets
4 Network Devices
18 Open Tickets
```

Tabs:

```text
Overview
People
Assets
Network
Tickets
Documentation
Files
```

---

# 58. Departments

Department profile:

```text
Accounting

14 Employees
17 Assets
4 Open Tickets
```

Tabs:

```text
Overview
People
Assets
Tickets
Requests
Documentation
Applications
```

Do not replace the entire application navigation when opening a department.

Keep global navigation.

---

# 59. Licenses

Track:

```text
Product
Vendor
Seats
Assigned
Available
Renewal
Cost
Owner
```

Microsoft license assignments should optionally synchronize automatically.

---

# 60. Domains and Certificates

Provide modern expiration dashboards.

Example:

```text
foleyit.com

Domain
Expires May 2027

*.foleyit.com

TLS Certificate
Expires in 61 days
```

Alert thresholds:

```text
90 days
60 days
30 days
14 days
7 days
```

---

# 61. Reporting V2

Replace current AdminLTE colored boxes.

Top:

```text
Open Tickets
Mean Resolution Time
SLA Compliance
Tickets This Month
User Satisfaction
```

Charts below.

---

# 62. Report Filters

Every report should support relevant filters:

```text
Date
Department
Location
Technician
Category
Priority
Status
Asset
```

---

# 63. Scheduled Reports

Allow reports to be delivered:

```text
Daily
Weekly
Monthly
Quarterly
```

Formats:

```text
Email
PDF
CSV
```

---

# 64. Notifications Center

Unified notifications:

```text
Tickets
Mentions
Approvals
RMM alerts
Credential expiration
Certificate expiration
License renewal
KB review
Project updates
Automation failures
```

---

# 65. Notification Preferences

Users choose:

```text
In-app
Email
Browser
```

by category.

---

# 66. Alerts

Alerts should be actionable.

Instead of:

```text
Disk Warning
```

show:

```text
SERVER-02

Disk C: 94% full

Started 22 minutes ago

[View Device]
[Create Ticket]
[Resolve]
```

---

# 67. Unified Audit Log

Very important.

Audit:

```text
User login
Ticket updates
Credential access
Credential reveal
Asset changes
Administrative changes
User provisioning
Microsoft actions
Odoo actions
API activity
Automation activity
```

---

# 68. Automation Engine

Long-term major feature.

Triggers:

```text
Ticket Created
Ticket Closed
Employee Created
Employee Disabled
Asset Assigned
Asset Offline
Certificate Expiring
Credential Rotation Due
Microsoft User Created
```

Conditions:

```text
Department
Location
Priority
Category
Asset Type
User Group
```

Actions:

```text
Assign Ticket
Send Email
Create Task
Run Script
Create Microsoft User
Add Microsoft Group
Assign License
Create Odoo User
Send Webhook
```

---

# 69. API Improvements

Do not break current API.

Gradually refactor:

```text
api/v1/index.php
```

into structured handlers if appropriate.

Eventually:

```text
api/
└── v1/
    ├── index.php
    ├── tickets.php
    ├── assets.php
    ├── users.php
    ├── departments.php
    └── rmm.php
```

But only if it can be done without breaking compatibility.

---

# 70. API Documentation

Keep:

```text
docs/API.md
```

updated with every API change.

Eventually add interactive OpenAPI documentation.

Potential route:

```text
/api/docs
```

---

# 71. Error Handling

Replace raw PHP errors for users.

Example:

```text
Something went wrong

We couldn't load this ticket.

Reference:
ERR-83921
```

Log detailed error server-side.

---

# 72. Loading States

Use:

```text
Skeleton loaders
Button loading indicators
Inline progress
```

Avoid blank pages while asynchronous requests execute.

---

# 73. Empty States

No more giant blank white boxes.

Example:

```text
🖥

No devices yet

Devices connected through the RMM will appear here.

[Configure RMM]
```

---

# 74. Toast Notifications

Standardize success messages.

Example:

```text
✓ Ticket updated

✓ Asset assigned

✓ Microsoft sync started
```

Use Toastr consistently.

---

# 75. Confirmation Dialogs

Dangerous actions should use consistent confirmation dialogs.

Example:

```text
Delete credential?

This action cannot be undone.

Cancel     Delete Credential
```

Never rely only on JavaScript `confirm()`.

---

# 76. Responsive Layout

Primary platform remains desktop-focused.

Still support:

```text
Desktop
Laptop
Tablet
Mobile
```

Mobile priority:

```text
Tickets
Approvals
Alerts
Assets
People
KB
```

---

# 77. Accessibility

Implement:

```text
Keyboard navigation
Visible focus indicators
ARIA labels
Proper form labels
Color contrast
Semantic status icons
Reduced-motion support
```

Never rely solely on color.

---

# 78. Dark Mode

Do not necessarily implement immediately.

But design CSS variables so it can eventually support:

```css
[data-bs-theme="dark"]
```

without rewriting the application.

---

# 79. Performance

Avoid adding huge JS bundles.

Maintain the current benefit of server rendering.

Target:

```text
Initial page load < 1 second on LAN

Normal navigation feels instantaneous

Minimal layout shifting
```

---

# 80. Security Rules

Claude must never weaken security for UI convenience.

Especially around:

```text
Credentials
API tokens
Passwords
Session management
CSRF
Authorization
File uploads
SQL queries
Remote commands
RMM
Microsoft API
Odoo API
```

All privileged actions must verify permissions server-side.

Never trust hidden form fields.

---

# 81. SQL Rules

Continue using mysqli unless a future dedicated migration project changes that.

Always:

```text
Prepared statements
Parameter validation
Escaping where appropriate
Authorization checks
Transactions where multi-step operations require consistency
```

Do not start introducing PDO alongside mysqli.

---

# 82. Database Migration Rules

All schema changes must support both:

```text
Fresh install
Existing installation
```

Therefore update:

```text
db.sql
```

and:

```text
admin/database_updates.php
```

when appropriate.

Never change only one.

---

# 83. Background Jobs

Keep job architecture compatible with:

```text
cron/
cron/cron.php
```

Any new scheduled task must document:

```text
Purpose
Schedule
Failure handling
Logging
Retry behavior
```

---

# 84. Redis/SSE

Use existing Redis infrastructure for useful live updates.

Good candidates:

```text
Ticket comments
Ticket status
Notifications
RMM alerts
Script completion
Sync completion
Job completion
```

Do not create constant polling when SSE already provides the capability.

---

# 85. Phase Order

I would do the project in this exact general order.

## Phase 1 — Foundation

```text
Design tokens
Typography
Spacing
Cards
Buttons
Badges
Forms
Tables
Empty states
Loading states
Toasts
Modals
```

Do not redesign individual modules until this foundation exists.

## Phase 2 — Application Shell

```text
Sidebar
Sidebar collapse
Navigation hierarchy
Top bar
Global search
Create menu
Notification menu
User menu
Responsive shell
```

## Phase 3 — Dashboard

```text
Dashboard V2
Attention feed
My Work
Environment Health
Recent activity
Quick actions
```

## Phase 4 — Ticketing

```text
Ticket list
Saved views
Ticket page
Activity timeline
Properties panel
Composer
Attachments
Time tracking
SSE improvements
```

This should receive significant attention because it is the primary daily workflow.

## Phase 5 — Employee Portal

Improve:

```text
Request Something
My Requests
Knowledge Base
Announcements
Device Information
```

Make the end-user portal drastically simpler than the technician portal.

## Phase 6 — Organization

```text
People
Departments
Locations
Vendors
```

## Phase 7 — Documentation

```text
Knowledge Base
Credentials
Files
Reviews
Credential rotation
```

## Phase 8 — Assets

```text
Asset inventory
Asset profiles
Lifecycle
Assignment
Warranty
Software
Relations
```

## Phase 9 — RMM

```text
RMM dashboard
Devices
Alerts
Scripts
Checks
Patch management
Software inventory
Remote access
```

## Phase 10 — Service Management

```text
Requests
Problems
Changes
Recurring work
Approvals
```

## Phase 11 — Projects

```text
Projects
Tasks
Milestones
Gantt
Files
Related tickets
Related changes
```

## Phase 12 — Integrations

```text
Microsoft 365
Odoo
UniFi
RMM
Webhooks
```

## Phase 13 — Employee Lifecycle Automation

```text
Onboarding
Offboarding
Role change
Department transfer
Device assignment
License provisioning
```

## Phase 14 — Reporting

```text
Technical reports
Service reports
RMM reports
Credential reports
Scheduled reports
PDF/CSV exports
```

## Phase 15 — Automation Engine

```text
Triggers
Conditions
Actions
Execution log
Retry handling
Testing
```

## Phase 16 — Polish

```text
Dark mode
Keyboard shortcuts
Command palette
Animations
Accessibility
Mobile improvements
Performance
```

---

# 86. Claude Working Procedure

Before Claude changes a module, follow:

```text
STEP 1

Inspect all files involved in the feature.

Do not modify anything yet.


STEP 2

Explain the current architecture and dependencies.


STEP 3

Identify duplicated code and reusable components.


STEP 4

Propose the smallest safe implementation.


STEP 5

Implement the feature.


STEP 6

Check syntax.


STEP 7

Review affected SQL/API/security logic.


STEP 8

Test responsive behavior.


STEP 9

Check for regressions.


STEP 10

Summarize changed files.
```

---

# 87. PHP Validation

After PHP changes:

```bash
php -l path/to/file.php
```

For multiple PHP files, Claude should lint all touched files.

---

# 88. CSS Validation

Claude should verify:

```text
No accidental global overrides
AdminLTE compatibility
Bootstrap compatibility
Sidebar behavior
Modal behavior
Responsive layouts
```

---

# 89. JavaScript Rules

Use:

```text
Vanilla JS

or

jQuery
```

based on surrounding code.

Do not introduce a framework just for one feature.

Namespaced functions where possible.

Avoid dumping hundreds of lines into page templates.

Prefer:

```text
js/modules/
```

eventually.

Example:

```text
tickets.js
assets.js
search.js
navigation.js
credentials.js
rmm.js
```

---

# 90. UI Acceptance Checklist

Every redesigned page should pass:

```text
□ Page has clear title
□ Primary action is obvious
□ Layout uses design system
□ No unnecessary giant whitespace
□ Loading state exists
□ Empty state exists
□ Errors are understandable
□ Buttons are consistent
□ Tables are readable
□ Status colors are semantic
□ Mobile layout works
□ Permissions still work
□ Existing functionality remains
□ No console errors
□ No PHP warnings
□ No broken API calls
```

---

# 91. Security Acceptance Checklist

For every feature:

```text
□ Authentication required where needed
□ Authorization verified server-side
□ CSRF protection maintained
□ Inputs validated
□ SQL injection prevented
□ Secret values protected
□ Audit events recorded where appropriate
□ Error messages don't leak secrets
□ File uploads validated
□ API permissions verified
```

---

# 92. Database Acceptance Checklist

For schema modifications:

```text
□ db.sql updated
□ migration added
□ migration is idempotent where appropriate
□ existing installations supported
□ fresh installation works
□ indexes added where appropriate
□ queries reviewed for performance
```

---

# 93. Claude Must Not Do These Things

```text
DO NOT rewrite the entire application.

DO NOT introduce Laravel.

DO NOT introduce React/Vue.

DO NOT add Node unless specifically requested.

DO NOT replace mysqli with PDO.

DO NOT alter database schema without migrations.

DO NOT bypass authorization.

DO NOT expose credentials.

DO NOT remove existing functionality merely because it appears unused.

DO NOT modify dozens of pages at once without verification.

DO NOT duplicate components when a reusable component can be created.

DO NOT hard-code organization-specific values if they belong in settings.

DO NOT make cosmetic changes that break responsive behavior.
```

---

# 94. Product Identity

Start treating the fork as its own product internally.

Instead of constantly referring to:

```text
ITFlow MSP
```

the product architecture should increasingly refer to:

```text
ITFlow Internal IT
```

or eventually whatever final name is chosen.

Keep upstream copyright/license requirements, while giving the user experience its own identity.

---

# 95. Organization-Specific Configuration

Do not hard-code Midwest Automation into the product.

Instead use settings:

```text
Organization Name
Logo
Favicon
Primary Color
Accent Color
Support Email
Support Phone
Default Timezone
Default Location
Portal Name
```

That means an installation can display:

```text
Midwest Automation & Custom Fabrication
```

while the code remains reusable.

---

# 96. Branding Settings

Eventually:

```text
Settings
   ↓
Appearance
```

Allow:

```text
Organization logo
Compact logo
Favicon
Primary color
Login background
Portal title
Support contact
```

---

# 97. Long-Term Product Layout

The end result should conceptually be:

```text
                 ITFlow Internal IT
                        │
       ┌────────────────┼────────────────┐
       │                │                │
 Service Desk      Operations       Knowledge
       │                │                │
 Tickets            Assets             KB
 Requests           RMM                Credentials
 Problems           Network            Files
 Changes            Software
       │                │
       └────────┬───────┘
                │
            Organization
                │
       People / Departments
       Locations / Vendors
                │
          Integrations
                │
       Microsoft / Odoo
       UniFi / RMM / API
                │
          Automation
```

---

# 98. Sprint 1

Start with:

```text
1. Audit current CSS
2. Build design tokens
3. Standardize typography
4. Standardize buttons
5. Standardize badges
6. Standardize cards
7. Standardize form controls
8. Standardize tables
9. Build empty-state component
10. Build page-header component
```

Then stop and inspect the result manually.

---

# 99. Sprint 2

```text
Global shell
Sidebar
Top navigation
Global search visual design
Create menu
Notifications
User dropdown
Responsive navigation
```

Again, inspect it before continuing.

---

# 100. Sprint 3

Build:

```text
Dashboard V2
```

Once that looks right, **that becomes the visual reference page** for the rest of the application.

---

# 101. Sprint 4

Then:

```text
Ticket list
Ticket details
Ticket activity feed
Ticket properties
Ticket composer
```

At this point the application's new identity should be very visible.

---

# 102. Sprint 5

Then:

```text
People
Departments
Locations
Assets
```

---

# 103. Sprint 6

Then:

```text
Knowledge Base
Credentials
Files
```

---

# 104. Sprint 7

Then:

```text
RMM
```

---

# 105. Sprint 8+

Afterward:

```text
Requests
Problems
Changes
Projects
Integrations
Automation
Reports
Polish
```

---

# Master Claude Code Prompt

Use this at the start of modernization work:

```text
You are working on ITFlow Internal IT, a fork of ITFlow that is being
transformed into a purpose-built internal IT operations platform.

This is NOT an MSP-focused product.

The application serves one organization with multiple departments,
locations, employees, devices, systems, and integrations.

The existing architecture must remain intact unless specifically
authorized otherwise.

STACK

Backend:
- PHP 8.4
- Procedural PHP
- Small PSR-4 ITFlow namespace under src/
- MariaDB
- mysqli
- nginx
- PHP-FPM
- Redis
- SSE
- PHP CLI cron jobs

Frontend:
- Bootstrap 5
- AdminLTE 4
- Font Awesome
- jQuery
- Vanilla JavaScript
- Chart.js
- TinyMCE
- Tom Select
- Simple-DataTables
- FullCalendar
- Leaflet
- Frappe Gantt
- Other existing vendored plugins

IMPORTANT CONSTRAINTS

Do not introduce:
- Laravel
- Symfony
- React
- Vue
- Angular
- Tailwind
- Vite
- Webpack
- Node build processes

Do not convert mysqli to PDO.

Do not rewrite functioning modules unnecessarily.

Do not break existing APIs.

Do not change database tables without supporting both db.sql and the
existing database migration mechanism.

The objective is to gradually modernize the application into a polished,
commercial-quality internal IT platform.

DESIGN GOALS

The interface should feel similar in quality to modern SaaS applications
such as NinjaOne, Hudu, Intune, Linear, HaloITSM, and Jira Service
Management without directly copying any of them.

The UI should be:

- clean
- modern
- professional
- responsive
- information dense but uncluttered
- consistent
- accessible
- reusable
- fast

Avoid:
- giant colored dashboard cards
- excessive gradients
- excessive shadows
- tiny typography
- inconsistent spacing
- stock AdminLTE appearance
- enormous blank areas
- duplicate component styling
- excessive animations

DESIGN SYSTEM

css/itflow_design.css should gradually become the authoritative project
design-system layer.

Build reusable styles and PHP components rather than styling each page
independently.

Prefer reusable components for:

- page headers
- cards
- stat cards
- badges
- priorities
- empty states
- filters
- tables
- activity feeds
- property panels
- user avatars
- status indicators
- confirmation dialogs
- loading states

WORKFLOW

Before modifying any major feature:

1. Inspect the related files.
2. Explain how the current implementation works.
3. Identify dependencies.
4. Identify reusable components.
5. Propose the change.
6. Implement incrementally.
7. PHP-lint modified PHP files.
8. Check JavaScript for syntax/runtime issues.
9. Verify responsive behavior.
10. Verify permissions/security.
11. Summarize every modified file.

Do not perform huge uncontrolled rewrites.

When possible, complete one coherent feature before moving to another.

SECURITY

Never weaken:
- authentication
- authorization
- CSRF protection
- SQL safety
- credential encryption
- audit logging
- session security
- file validation
- API authorization

Sensitive information must never be written to logs.

PRODUCT DIRECTION

Core modules:

- Dashboard
- Service Desk
- Requests
- Problems
- Changes
- Projects
- Calendar
- People
- Departments
- Locations
- Vendors
- Knowledge Base
- Credentials
- Files
- Assets
- Network
- Racks
- Licenses
- Domains
- Certificates
- RMM
- Scripts
- Policies
- Patch Management
- Microsoft 365
- Odoo
- UniFi
- Integrations
- Automations
- Reports
- Audit Log

We will implement this modernization in controlled phases.

Do not move to the next major phase until requested.

CURRENT PHASE:

Phase 1 — Design System Foundation.

Begin by auditing the current design/CSS implementation.

Inspect:
- css/itflow_design.css
- css/itflow_custom.css
- css/itflow_bs5_bridge.css
- AdminLTE overrides
- includes/header.php
- includes/footer.php
- repeated card/button/table/badge markup

DO NOT make changes yet.

First report:

1. Current CSS architecture.
2. Where conflicts or duplication exist.
3. Which styles should become global design tokens.
4. Components that should be standardized.
5. Files that will likely need modification.
6. Risks of changing them.
7. Proposed implementation order.

Wait for approval before implementing.
```

---

# Recommended First Milestone

Use **Dashboard V2 + Ticket V2** as the visual benchmark for the rest of the platform.

Once those two pages are polished:

- Assets
- Knowledge Base
- Credentials
- RMM
- Departments
- Microsoft integrations
- Reports
- Service Requests

can all inherit the same design system and interaction patterns.

The objective is not simply to make ITFlow prettier.

The objective is to evolve it into a coherent **Internal IT Operations Platform** with a modern design system, reusable UI components, strong security controls, clean workflows, and long-term maintainability.
