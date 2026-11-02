# Service catalog: request forms and approval chains

The service catalog (Administration > Service Catalog) is a menu of things people can ask for. An item that has no
questions and no approval is a plain shortcut: it pre-fills the ticket form, as it always did. This page covers the
optional parts. All of the logic lives in `src/ITSM/ServiceCatalogService.php`.

## Categories (tiles)

**Administration > Service Catalog > Categories** creates the groups shown on **Request Something** (name, description, icon,
order, active). Pick a category on each item. The agent page and the portal then show a row of category tiles (with a
request count) above the item tiles; clicking a tile narrows the list, "Everything" resets it. Items without a category (or in
an inactive one) are listed under **Other**. With no category defined the pages look as before (the portal keeps its chips
made from each item's ticket category). Deleting a category keeps its items. Stored in `service_catalog_categories` and
`service_catalog_items.catalog_category_id` (migration 2.6.211).

## Request forms

Edit an item and fill in the **Request form** table. Each row is a question:

| Type | Stored as | Checked by the server |
|---|---|---|
| Text | text up to 500 characters | length |
| Textarea | text up to 5000 characters | length |
| Select | one of the choices (one per line) | must match a choice |
| Checkbox | Yes / No | a required checkbox must be ticked |
| Date | `YYYY-MM-DD` | must be a real date |
| Number | decimal number | must be numeric |
| Table | rows of cells, stored as JSON `{"cols":[...],"rows":[[...]]}` | see below |

Rows are ordered by the Order number. Clear a row's label (or tick Remove) to delete it. Answers are stored with the
question text as it was when the request was made (`service_catalog_requests.field_values`, JSON), so renaming a
question later does not rewrite history. They are shown, escaped and read-only, as **Request details** on the agent
and portal ticket pages.

### Conditional questions

A question can be shown only when an earlier answer matches. In the **Request form** table, **Show only when** picks an earlier
saved question and a test:

| Test | Shown when the other question's answer... |
|---|---|
| equals | is exactly the value (a checkbox answer is `Yes` when ticked) |
| is one of (a\|b) | is any of the values, separated by `\|` |
| is answered | is not empty |

Rules, enforced when you save and again when someone submits:

- A condition can only point at a question that **exists** and comes **earlier** in the form (by the Order column). That makes loops
  impossible; a bad condition is refused with a message and the form is left unchanged (the rest of the item still saves).
- A hidden question is **neither required nor stored**, whatever the browser posted. A question hidden by another hidden question is
  hidden too. A required question that has a condition is required only while it is shown (there is no separate switch for this).
- The portal and the agent's New Ticket window show and hide questions as the person answers (`js/catalog_show_if.js`, a few lines
  of plain JavaScript; it only mirrors the server, which decides). Without JavaScript every question is posted and the server
  discards the hidden ones. The editor has a **Preview the saved form** panel that applies the same rules.
- Stored rule: `service_catalog_fields.show_if` (JSON, NULL = always shown), migration 2.6.140.

Rules that point forward are not supported (they would allow loops).

### Several conditions on one question

Each question can have up to **three** conditions ("More conditions" in the editor) combined with **All conditions must hold**
or **Any one condition is enough**. One condition is stored in the original single-rule shape; two or three are stored as
`{"match":"all|any","rules":[...]}` in the same `show_if` column (no schema change). Every condition must point at an earlier
saved question; the server and `js/catalog_show_if.js` evaluate them the same way.

### Repeating table questions

Choose type **Table** and list its columns in the Choices box, one per line: `Heading`, `Heading|number` or `Heading|date`
(at most 8 columns, text by default). The requester gets three blank rows and an **Add row** button (`js/catalog_table.js`, up
to 25 rows); blank rows are dropped, every cell is checked on the server (cells up to 200 characters, numbers numeric, dates
real), and a required table needs at least one row. The answer is shown as a table (cells escaped) on the ticket and the
approval pages, and as text lines in the mobile approvals API. A table answer counts as "answered" for conditions when it has
at least one row. The API (`GET /api/v1/service_catalog.php`) describes a table question with `columns` and takes rows as lists
or as objects keyed by the column heading.

## Approval chains

Tick **Requires approval** and add steps. Steps run in order; each step is one of:

- **A person** (an active agent),
- **A role** (every active agent holding the role when the step starts),
- **Requester's manager** (the requesting contact's `contact_manager_id`, same department only).

Each step is **any** (one approver is enough) or **all** (every resolved approver must approve). Any rejection ends the
request.

### Parallel steps

Tick **Parallel** on a step to make it decide at the same time as the step above it. Steps that share a stage are asked together;
the next stage starts only when **every** one of them is satisfied (an "any" step once one of its approvers approved, an "all"
step once all of them did). One rejection rejects the request and withdraws the other open approvals. A person who appears in two
parallel steps decides once for both. An administrator override settles the whole stage. Stored as several
`service_catalog_approval_steps` rows with the same `step_order`; each request approval row remembers its step (`step_id`). A step that resolves to nobody (for example the requester has no manager on file) falls back to the active
administrators, so a request can never be stuck with nobody able to decide; an agent never approves a request they
raised themselves when somebody else can.

**Risk score** (0-100) and **Auto-approve below**: when the threshold is above 0 and the risk score is lower than it,
the request is recorded as approved straight away and the ticket is not held. An item with approval switched on but no
steps never holds a ticket.

### Computed risk and risk-based routing

The item's score is the **base**. **Risk rules** (Approval section of the item) add or remove points when an answer matches:
the question equals / is one of / is answered / is at least / is at most a value, plus or minus up to 100 points. The request's
score is base plus the points of every matching rule, kept between 0 and 100; hidden questions count as unanswered. The score
and its breakdown are stored on the request (`risk_score`, `risk_breakdown`) and shown on the ticket page, so it can be audited.

That computed score decides two things:

- the **auto-approve** test (so a "laptop" request can pass without asking while a "server with admin rights" one is held), and
- **which approval steps apply**: every step has a **risk band** (default 0 to 100 = always). A step only runs for requests whose
  score is inside its band, so a low-risk request can be approved by a manager while a high-risk one also needs security.
  A risk level that no step applies to is recorded as approved without asking anyone (so give every level a chain you want asked).

### Out of office, reminders, escalation and timeouts

- **Delegation.** On the Approvals page (**Out of office**) an agent picks a colleague and a period. While it lasts, new approvals
  addressed to the agent go to the delegate instead (the row notes "covering for a colleague"); with the approval worker on,
  approvals the agent already has pending are handed over as well, and the original row is kept as *skipped*. One hop only
  (a delegate's own delegation is not followed), and a delegate who raised the request is ignored. Portal managers
  (requester's manager steps) have no delegation yet.
- **Reminders, escalation, timeout** are set per item (**Reminders and timeouts**, hours, 0 = off): remind every N hours (at most 5
  times), escalate after N hours (the active administrators are added to the stage and any of them can decide it, once per stage),
  reject after N hours (the request is rejected with a "timed out" reason and its ticket closed). The clock starts when a stage is asked.
- The work is done by `cron/catalog_approvals.php` (required from `cron/cron.php`, also `php cron/catalog_approvals.php`). It does nothing
  until an administrator ticks **Approval worker** on the Service Catalog admin page (`settings.config_catalog_approval_cron`,
  default off). Reminders go out as in-app notifications and as email through the normal mail queue. Migration 2.6.212.

### What happens to the ticket

| Moment | Ticket |
|---|---|
| Submitted, approval needed | created, then moved to the status named **Pending Approval**, else **On Hold** (looked up by name, never by id). Give your Pending Approval status the "Pauses the SLA clock" option and the SLA is paused while held. |
| Every step approved | moved to the normal creation status (Assigned or New, or the configured default); SLA clock resumes; the assigned agent is notified |
| Rejected | closed, with a System note carrying the reason; the requester is told |

A customer reply on the portal does not release a held ticket.

### Who sees what

- **Agents**: Service Desk > Approvals lists requests waiting on them (directly or through a role), with approve /
  reject and a comment (a comment is required to reject). Needs access to the Support module; module-only logins have
  no access, as with the rest of the agent area. Administrators also see every other pending request and can decide a
  step for its approvers; that is recorded as an administrator override.
- **Employees who manage others**: the portal shows **My approvals** only to contacts that are named as someone's
  manager (or already hold an approval), and only requests from their own department. Decisions are checked against
  the logged-in contact and department on the server, so a guessed request id does nothing.

Every decision is stored in `service_catalog_request_approvals` (approver, status, comment, time) and written to the
audit log.

## Popular and recent

Tickets raised from a catalog item carry `tickets.ticket_catalog_item_id`. The agent and portal catalog pages show
**Popular this month** (tickets per active item over the last 30 days, top 5); the portal also shows **Recently used by
you** (this contact's last 5 distinct items).

## Reporting

**Reports > Service Catalog Requests** (`agent/reports/service_catalog.php`, Tickets access, department scoped like the other
reports, CSV export, listed in the report catalog so it can be saved and scheduled): requests, approved, rejected, waiting,
rejection rate (rejected / decided), average and longest approval time (submission to final decision, for requests that went through
approval; auto-approved requests are counted separately), per item, per category and per day.

## Raising a request from code (API and chat)

`ServiceCatalogService::describeItem()` returns an item as plain data (name, category, questions with choices, table columns and
conditions) and `ServiceCatalogService::createRequest($itemId, $answers, $clientId, $contactId, $userId, $source)` validates the
answers, creates the ticket, stores the request, computes the risk and starts the approval. `POST /api/v1/service_catalog.php` is a thin
wrapper over it. **The caller must authenticate the person and check access to the department first**; the method does not. A Slack or
Teams bridge (issue #36) should map the chat user to an agent or contact, call `describeItem()` to build its form, then `createRequest()`
with `$source` = `Slack` / `Teams`. See `docs/MOBILE_API.md` for the HTTP shape.

## Data model

Migration 2.6.133: `service_catalog_items` gains `requires_approval`, `risk_score`, `auto_approve_below`; `tickets` gains
`ticket_catalog_item_id` (indexed); new tables `service_catalog_fields`, `service_catalog_approval_steps`,
`service_catalog_requests`, `service_catalog_request_approvals`. Migration 2.6.140: `service_catalog_fields.show_if`.

Migration 2.6.211: `service_catalog_categories`, `service_catalog_items.catalog_category_id`, `service_catalog_risk_rules`,
`service_catalog_approval_steps.risk_min/risk_max`, `service_catalog_requests.risk_breakdown`,
`service_catalog_request_approvals.step_id`. Migration 2.6.212: `service_catalog_delegations`,
`service_catalog_items.reminder_after_hours/escalate_after_hours/expire_after_hours`,
`service_catalog_request_approvals.delegated_from_user_id/is_escalation/escalated_at/last_reminded_at/reminder_count`,
`settings.config_catalog_approval_cron`. All additive and off by default: existing items keep their behaviour.

## Limits

- Editing an item's steps while a request is pending changes the steps that request has not reached yet.
- A held ticket can still be moved by an agent or by an automation rule; only portal replies are blocked from releasing it.
- The mobile app has to know the `table` question type; an older app cannot answer a required table.
- Out-of-office delegation covers agents only, not a requester's manager on the portal.
- Reminders, escalation and timeouts need the approval worker switched on and the main cron running.
- Catalog from Slack or Teams is not built here: only the service method it needs (issue #36 owns the bridge).
