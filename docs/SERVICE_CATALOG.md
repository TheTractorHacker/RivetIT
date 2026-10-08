# Service catalog: request forms and approval chains

The service catalog (Administration > Service Catalog) is a menu of things people can ask for. An item that has no
questions and no approval is a plain shortcut: it pre-fills the ticket form, as it always did. This page covers the
optional parts. All of the logic lives in `src/ITSM/ServiceCatalogService.php`.

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

Not included: a repeating **table** field type, conditions on more than one question at once, or rules that point forward.

## Approval chains

Tick **Requires approval** and add steps. Steps run in order; each step is one of:

- **A person** (an active agent),
- **A role** (every active agent holding the role when the step starts),
- **Requester's manager** (the requesting contact's `contact_manager_id`, same department only).

Each step is **any** (one approver is enough) or **all** (every resolved approver must approve). Any rejection ends the
request. A step that resolves to nobody (for example the requester has no manager on file) falls back to the active
administrators, so a request can never be stuck with nobody able to decide; an agent never approves a request they
raised themselves when somebody else can.

**Risk score** (0-100) and **Auto-approve below**: when the threshold is above 0 and the risk score is lower than it,
the request is recorded as approved straight away and the ticket is not held. An item with approval switched on but no
steps never holds a ticket.

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

## Data model (migration 2.6.133)

`service_catalog_items` gains `requires_approval`, `risk_score`, `auto_approve_below`; `tickets` gains
`ticket_catalog_item_id` (indexed); new tables `service_catalog_fields`, `service_catalog_approval_steps`,
`service_catalog_requests`, `service_catalog_request_approvals`.

## Limits

- Editing an item's steps while a request is pending changes the steps that request has not reached yet.
- A held ticket can still be moved by an agent or by an automation rule; only portal replies are blocked from releasing it.
- There is no api/v1 endpoint for catalog requests yet.
