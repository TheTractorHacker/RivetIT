# Automation engine

Administration > Automation brings together everything that runs by itself:

| Tab | What it is |
| --- | --- |
| Event rules | Rules that react to events (a ticket was created, an SLA is about to be missed, a request was approved...). Edited on **Administration > Event rules**. |
| Ticket rules | The older ticket-only rules (scheduled checks, RMM alerts, asset online/offline, requester returns). Read only here; edit them on Administration > Ticket Automation. They are a separate engine and behave exactly as before. |
| Lifecycle workflows | Summary of onboarding/offboarding templates, workflows in progress and auto-start. See `EMPLOYEE-LIFECYCLE-WORKFLOWS.md`. |
| Run log | One row per event-rule run: rule, event, result, what happened, time taken. Filter by rule and result. Old rows are deleted with the activity-log retention period. |

The **Enable automation rules** switch on that page turns every event rule off at once (events still reach webhooks). It is on by default,
which is how event rules always behaved; nothing happens until a rule exists. Ticket rules and lifecycle workflows are not affected.
In RivetMSP the same flag (`core.automation.enabled`) is the Core settings flag, and this page shows it read only.

## How an event becomes an action

1. Something happens and the app emits an event (`rivetEmitEvent`, `includes/event_bus.php`). Audit events are events too.
2. `RuleEngine::matching()` picks the enabled rules for that event whose conditions match, in order (lowest **Order** first). A matching rule with
   **Stop on first match** ends the list.
3. Each match is queued on the job queue (`automation.action`) and runs in the background (`RuleEngine::run()`): loop guard, rate limit, the action, a run-log row.
   A failed action is retried by the queue; a throttled or loop-blocked run is a decision, not a failure, and is not retried.

## Events

Ticket: `ticket.created`, `ticket.updated` (details, priority or category edited), `ticket.status_changed`, `ticket.assigned`, `ticket.replied`, `ticket.resolved`,
`ticket.sla_warning`, `ticket.sla_breached`. Others: `asset.created`, `contact.created`, `catalog.request_approved`, `catalog.request_rejected`,
`workflow.task_completed`, plus every audit event type this server has recorded (logins, workflow events, settings changes...).

* `ticket.updated` is emitted by the agent ticket editor and the quick priority/category changes, and by the rule actions below.
* **SLA events** have no event of their own in the app, so cron (`cron/cron.php`) checks open tickets on every pass, only when an enabled rule or webhook listens
  for them. `ticket.sla_warning` fires once per clock (response, resolution) when 80% of it is used and the clock is running (not paused);
  `ticket.sla_breached` fires once when it runs out. A breach that is already more than a day old when first seen is recorded but not announced, so switching
  this on does not fire for every old overdue ticket. The payload adds `sla_clock`, `sla_percent_used`, `sla_remaining_seconds`.
* `asset.created` / `contact.created` are emitted by the agent add forms and the API create endpoints (not by imports and directory syncs).
* `catalog.request_*` are emitted when an approver (or an administrator override) decides a request; the payload has `request_id`, `ticket_id`, `client_id`, `contact_id`, `reason`.
* `workflow.task_completed`: a task completed by a person, by an approval or by an automated task action.

## Conditions

Each row compares a field of the event with a value. Field names are the keys of the event payload, nested keys joined with dots; the bare last name also works when
it is unambiguous (`ticket_priority`, `client_id`, `metadata.reason`...). Operators: equals, does not equal, is one of (comma separated), contains (case insensitive),
greater than, less than (both sides numeric), is empty. A field the event does not have never equals, contains or compares to anything; "does not equal" and "is empty" are true for it.
Equals is exact and case sensitive, as it always was.

Rows are joined by **all** or **any**. Give rows the same group letter (A, B or C) to combine them with that group's own all/any first, then the group counts as one row
(one level deep). At most 20 rows.

Stored in `automation_rules.condition_json`: the original `{"field":"value"}` map (a plain "all of: equals" rule is still saved this way, so older code can read it) or
`{"version":2,"mode":"all|any","conditions":[{"field","op","value"} | {"mode","conditions":[...]}]}`. Invalid stored conditions never match (fail closed).

## Actions

| Action | Config | Notes |
| --- | --- | --- |
| Create a ticket | subject, details, priority | Unchanged. `{field}` placeholders filled in as before. |
| Send a webhook | url, secret | Unchanged. The URL must resolve to a public address; checked on save and again when sent (connection pinned). |
| Notify a user | message | Unchanged. |
| Start an employee workflow | template_id | Unchanged. |
| Set ticket fields | status (by name), priority, category, assignee | Only fields that differ are written. Resolved is treated as Closed like everywhere else. Emits `ticket.status_changed` / `ticket.assigned` / `ticket.updated` and leaves an internal note. The assignee must be an active technician (never a portal login or archived user). |
| Add an internal note | note | Staff-only note on the ticket. Text and `{field}` values are HTML-escaped. |
| Assign the ticket | one technician, or a rotation of 2-50 | Round robin remembers its place per rule (`automation_rules.rr_cursor`) and skips technicians who are no longer active. Moves a New ticket to Assigned/Open. Skips closed tickets. Notifies the new assignee. |
| Send an email | to (assignee, ticket contact, a technician, a fixed address), subject, body | Queued on the normal mail queue. The recipient is fixed by the rule, never by event data. Subject and body use escaped `{field}` placeholders; line breaks are stripped from the subject. |
| Add a task to the ticket | name, assignee, due in N days | A task (checklist item) on the ticket. |

The ticket actions need an event about a ticket (`ticket.*` or `catalog.request_*`); a rule is refused at save time otherwise. Placeholders are `{field}` using the same
names as conditions. Only free-text fields are templated; URLs, recipients, assignees and priorities never are.

## Safety

* **Loop guard.** While a rule runs, every event its action emits carries a chain (`_chain.id`, `_chain.depth`, `_chain.rules`). A rule is blocked (`loop_blocked`) when it is already in
  the chain, or when the chain is already 3 rules long. A `_chain` in event data from anywhere else is discarded.
* **Rate limit.** Each rule may run at most N times per minute (default 30, 1-1000). Over the limit the run is `throttled` and does nothing; one throttled row per minute is logged.
* **Permissions.** Every Automation page and its handlers are Administration only, CSRF protected. Rule changes are written to the activity log and the audit trail
  (`automation.rule_created`, `_updated`, `_toggled`, `_deleted`); each action also audits what it changed.
* **Dry run.** `RuleEngine::test()` runs the conditions and each action with `$dry = true`: actions read but never write, queue, send or advance a rotation.

## Test rule

Event rules > the flask button on a rule (or Automation > Event rules). Choose a recent ticket, a recent audit event of the rule's event type, or edit a synthetic JSON event.
The page lists each condition with its result and what the action WOULD do (or why it would fail), and writes nothing.

## Database (migration 2.6.139)

`automation_rules`: `priority`, `stop_on_match`, `rate_limit_per_min`, `rr_cursor`, and the five new `action_type` values. New `automation_rule_runs` (the run log) and `automation_sla_marks`
(once-only SLA announcements). New `settings.config_automation_enabled`. All idempotent.

## Code map

`src/Automation/`: `ConditionEvaluator`, `RuleEngine`, `Template`, `EventPayloads`, `SlaEventEmitter`, `RuleView`, `TicketRuleView`, `Actions/*` (registry in `ActionRegistry`; the four older actions in
`BuiltinAction` subclasses keep running through `rivetAutomationActionHandlers`). Tests: `tests/automation_engine.php`.
To add an action: implement `ActionInterface`, register it in `ActionRegistry`, widen the `action_type` enum in a migration, add its fields to `admin/event_rules.php` and `admin/post/event_rules.php`.
