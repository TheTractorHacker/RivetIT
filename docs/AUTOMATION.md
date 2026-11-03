# Automation engine

Administration > Automation brings together everything that runs by itself:

| Tab | What it is |
| --- | --- |
| Event rules | Rules that react to events (a ticket was created, an SLA is about to be missed, a request was approved...). Edited on **Administration > Event rules**. |
| Ticket rules | The older ticket-only rules (scheduled checks, RMM alerts, asset online/offline, requester returns). Read only here; edit them on Administration > Ticket Automation. They are a separate engine and behave exactly as before. Under the list, **Move ticket rules to event rules** shows which of them have an event-rule equivalent and can be imported as a switched-off copy (see "Legacy ticket rules" below). |
| Lifecycle workflows | Summary of onboarding/offboarding templates, workflows in progress and auto-start. See `EMPLOYEE-LIFECYCLE-WORKFLOWS.md`. |
| Run log | One row per event-rule run: rule, event, result, what happened, time taken, which try of the queue it was and, for a failed run, **Run again**. Filter by rule and result. Old rows are deleted with the activity-log retention period. |

Below the master switch, **Action switches** holds the two OFF-by-default switches for risky actions (run RMM scripts, create Odoo users) and the certificate warning window.

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

**Events added by issue #33** (all appear in the event picker):

| Event | Raised by | Notes |
| --- | --- | --- |
| `employee.created` | the lifecycle detector, when a person record is created | Needs Employee workflows auto-start on, and only raised while an enabled rule or webhook names it. |
| `employee.disabled` | the lifecycle detector, when a person moves into `suspended`, `terminated` or `archived` from another status | Same two conditions. A person going `terminated` raises `employee.terminated` and `employee.disabled`. |
| `employee.offboarded` | `WorkflowService`, when an offboarding workflow run completes | Always raised (a run only exists because someone or a rule started it). Payload: `contact_id`, `client_id`, `run_id`, `run_status`. |
| `asset.assigned` | `AssetAssignmentService::recordChangeIfNeeded()`, so every assignment path (edit, link, bulk, inline) | Payload: `asset_id`, `asset_name`, `asset_type`, `client_id`, `contact_id`, `previous_contact_id`, `assigned_by_user_id`. Not raised for an unchanged assignment. |
| `asset.offline` / `asset.online` | cron, from `asset_rmm_links` status changes | Once per status change. An outage already older than a day when first seen is recorded, not announced. Payload: `asset_id`, `asset_name`, `asset_type`, `client_id`, `contact_id`, `hostname`, `integration_id`, `rmm_status`, `status_since`. |
| `certificate.expiring` | cron | When a certificate is within *certificate warning days* (default 30) of expiring, then again at 7, 1 and 0 days. Payload: `certificate_id`, `certificate_name`, `domain`, `issued_by`, `client_id`, `expires_on`, `days_left`, `threshold_days`. Key material is never included. |
| `credential.rotation_due` | cron | Once per rotation due date, up to 30 days overdue (older ones are only recorded). Payload: `credential_id`, `credential_name`, `credential_type`, `client_id`, `contact_id`, `asset_id`, `due_on`, `days_overdue`. **Never the username, password, OTP seed, note or URL.** |
| `schedule.hourly` / `daily` / `weekly` / `monthly` | cron | A clock tick once per period (at the first cron pass in it). Payload: `scheduled_for`, `period`, `date`, `hour` (0-23), `minute`, `weekday` (1 = Monday), `weekday_name`, `day_of_month`, `month`, `year`. |

Cron raises these only for events something listens to (an enabled rule, or a webhook naming the event or `group.*`; a bare `*` webhook does not count, so a catch-all webhook is not flooded with ticks). Each thing is announced once (`automation_emit_marks`). The times are the server's. To run a rule "every Monday at 8", use `schedule.hourly` with conditions `weekday` equals 1 and `hour` equals 8, or `schedule.weekly` for "once a week, whenever cron first runs on Monday". Ticks are not back-filled: if cron was down for an hour that hour is skipped.

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

**Fields added from the database.** Before rules are matched, the engine adds look-up fields for what the event is about (`PayloadEnricher`; never overwriting a key the event already has, and only when an enabled rule listens to the event): `department` / `client_name` (from `client_id`), `location_id` / `location_name`, `asset_name`, `asset_type`, `asset_status`, `asset_make`, `asset_model` (from `asset_id`), `contact_name`, `contact_email`, `contact_department`, `job_title`, `employment_status`, `employee_type`, `user_group` (the person's job groups, comma separated: use *contains*) and `user_role` (the role of their technician login) (from `contact_id`, or from an audit event whose `entity_type` is `contact`/`asset`). They work in conditions and `{placeholders}`. So `asset.assigned` with `asset_type` equals Laptop and `department` equals Finance needs nothing in the payload but the ids. "Department" and "client" are the same thing in this app.

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

| Run an RMM script | script_id (library), run_as_user_id, fixed params (`name=value` lines), timeout_s | **OFF** until *Allow rules to run RMM scripts* is on. Needs `device_id` in the event (every `rmm.*` event has one). The job is created through the RMM module's technician API **as the chosen technician**, so their permission, department scope, the script's platform check and the second-person approval rule apply; a run that needs approval waits under RMM > Approvals and the run log says so. The script and parameters are fixed in the rule, never templated; the event only picks the device. **UNVERIFIED LIVE** (see below). |
| Create an Odoo user | groups (Odoo external ids) | **OFF**, and **no Odoo write connection ships**: even switched on it stops with "no Odoo write gateway is installed". Login and name come from the person's record, never the event. Exists to prove the rule shape and the contract with a recording gateway. The Odoo this app uses is production and read only. |
| Send a chat message | destination (key), message | Goes through the registered `ChatNotifier` (see "Chat integration point"). With no sender installed the run fails with a clear message; dry run still describes it. |
| Entra: disable the person's account | revoke_sessions | The workflow action's code (department check IT-9, never deletes), on events about a person. The account is the email on the person's record (`contact_id`), never an address from the event. Needs *Allow RivetIT to change Entra accounts* (OFF by default). **UNVERIFIED LIVE** (the workflow tests prove Graph against a local mock). |
| Entra: add the person to groups | groups (Object IDs) | Same. Creating an Entra account is not a rule action: it hands a one-time password to a technician through a workflow task. |

The ticket actions need an event about a ticket (`ticket.*` or `catalog.request_*`); a rule is refused at save time otherwise. Placeholders are `{field}` using the same
names as conditions. Only free-text fields are templated; URLs, recipients, assignees and priorities never are.

## Chat integration point (Slack / Teams)

The *Send a chat message* action does not talk to Slack or Teams itself. It asks the notifier registered with `ChatNotifierRegistry::set()` (`src/Automation/ChatNotifier.php`: `destinations()` lists the keys the editor offers, `send($destination, $message, $context)` sends one plain-text message and returns a short log line or throws). Whoever builds the real senders (issue #36) implements `ChatNotifier` and registers it once at start-up. Until then `UnavailableChatNotifier` is in place: rules can be saved and dry-run, and a real run fails with "no chat sender is installed". Existing Slack/Teams *webhook destinations* (Administration > Webhooks) keep working as before for event subscriptions; they are a different path.

## Run log: retries and running again

* The job queue tries a rule's action up to 3 times (`RuleEngine::MAX_ATTEMPTS`). Every try is a run-log row: the History drawer and the Run log tab show **try 2 of 3** and, on the last, **gave up**. A rule's trigger and context are the same on every try.
* A **failed** run keeps the event it ran with (without its causal chain, capped at 60 KB; bigger events are not kept). **Run again** (History drawer, Run log tab, or `POST /api/v1/automation_rules/retry/{run_id}`) runs the rule once more with that event as a new run (source *manual*, `retry_of_run_id` = the failed run), written to the audit trail. It is refused when the run did not fail, kept no event, the rule is deleted or off, or a retry of it already succeeded (so a double click cannot make two tickets or mails). A manual re-run is not held back by the rate limit (a person asked for it); the loop guard still applies. Rows from before the update have no stored event and cannot be re-run.

## Legacy ticket rules

`ticket_automation_rules` (Administration > Ticket Automation) keep running exactly as before, from cron. **Move ticket rules to event rules** (Automation > Ticket rules) shows, for each, whether an event rule can do the same job and creates a **switched-off copy** on request (`LegacyRuleImporter`; `automation_rules.source_legacy_id` is unique, so importing twice makes one). Review the copy, switch it on, then switch the old rule off so the two do not both act. What can move is narrow, on purpose, because the engines differ: `asset_offline` / `asset_online` triggers with the `notify_assignee` action and conditions on `asset_id`, `client_id`, `hostname`, `integration_id`, `rmm_status`. Everything else is listed with the reason: ticket *schedule* rules scan tickets by age on every pass and event rules react to single events; `rmm_alert` and `vacation_return` read data the bus does not carry; `run_script` points at a Tactical script id, not a library script; rules with several actions are not guessed at; and a legacy rule with no conditions never ran, so it is not imported as one that would match everything. Hostname and status compared ignoring case in the old engine; the copy compares exactly (the report says so).

**In the event rules tab.** The first tab (Event rules) now also lists every legacy ticket rule, **read only**, under the event rules: when it triggers, what it checks and does, and its state. A rule with an equivalent has **Convert to event rule**: one click makes the switched-off copy (same code as the bulk import; the legacy rule is untouched and keeps running). Then the row shows *Copy ready, switched off* and **Switch over**, which after a confirmation turns the copy **on** and the legacy rule **off** in one step, so exactly one of them acts (the ticket rule can be turned back on in its editor). *Both are on* is flagged in red so a double run is visible. Rules with no equivalent stay as ticket rules, with the reason. All of it is administrator-only and audited (`automation.rule_created`, `automation.legacy_rule_switched`). Verified: the converted copy fires for exactly the events the legacy conditions matched (`tests/lifecycle_life33.php`).

## Rules over the API

`/api/v1/automation_rules` (administrators only, user API token only; an integration client gets 403). Full contract in `api/v1/openapi.yaml`, summary in `docs/API.md`: list/create, get/put/patch/delete, `runs`, `runs/{id}`, `retry/{run_id}`, `meta`. The body of create/update is `{name, trigger_event, conditions: {mode, conditions:[{field,op,value}|{mode,conditions}]}, action_type, action_config, is_enabled, priority, stop_on_match, rate_limit_per_min}`; `action_config` is the action's input, the same fields the editor posts (for example `send_mail {to_type, subject, body, address|user_id}`, `notify_chat {destination, message}`, `run_script {script_id, run_as_user_id, params, timeout_s}`). Saving goes through `RuleEngine::save()`, so every check of the editor applies. The webhook signing secret is never returned (`has_secret`).

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

## The Event rules page

**List.** One row per rule: an on/off switch (instant, no reload), the name, a plain-English sentence generated by `RuleSummary::describe()` ("When a ticket is created, and priority is High or Critical, then notify all technicians."),
the event's group badge, the action, the order number, the last run with its age, a 7-day sparkline and the run total. Search, filters (event group, action, on / off / failing) and sort (order, name, event, last run, run count)
are server side. In the default "Order" sort rules can be dragged (or moved with the arrows) among rules for the **same event**; the order is stored as priorities 10, 20, 30... Row actions: Edit, Test, History, Duplicate (a disabled copy
named "... (copy)") and Delete. With no rules the page shows the recipe gallery.

**Editor** (`?new`, `?edit=ID`, `?new&recipe=KEY`). Four numbered cards (When, If, Then, Settings) beside a sticky summary that re-renders the sentence and lists the problems of each section while you type (`preview` in
`admin/modals/event_rules_api.php`, using the same validators as Save). *If* is a builder for exactly the engine's condition model: a top-level ALL/ANY box, up to ten ANY/ALL groups (one level deep, as the engine allows), at most 20 conditions,
operators is / is not / is one of / contains / is greater than / is less than / is empty, with value selects for priority, ticket status, technicians and clients. "Check against recent events" evaluates the conditions (only) against the
10 most recent matching events. *Then* shows the action as a card, its form, and clickable `{placeholder}` chips with a rendered preview. A refused save returns to the editor with the typed input and every problem listed (kept in the session once).

**Test and History drawers.** *Test* is the dry run described above, on a saved or unsaved rule; "Run for real now" exists only for rules whose action is a notification. *History* lists `automation_rule_runs` (time, event, status, message,
duration), can show failed runs only, and shows throttled and loop-blocked rows.

**Recipes** (`RuleRecipes`) prefill the editor and are offered only when their event is emitted by this edition, their action exists and (for status conditions) the installation has the ticket status they name.

## Database (issue #33: migrations 2.6.219 - 2.6.222)

* 2.6.219: `automation_rules.action_type` gains `run_script`, `create_odoo_user`, `notify_chat`, `entra_disable_account`, `entra_add_to_groups`; `automation_rules.source_legacy_id` (unique) links an imported rule to its legacy rule.
* 2.6.220: `automation_rule_runs` gains `context_json`, `attempt`, `trigger_source`, `retry_of_run_id` (+ index).
* 2.6.221: `automation_emit_marks (kind, entity_id, mark)`, the once-only memory of the events cron raises.
* 2.6.222: `settings.config_automation_run_script_enabled` (0), `config_automation_odoo_user_enabled` (0), `config_automation_cert_days` (30).

All idempotent. Code deployed before the update still logs runs (the engine checks for the new columns).

## What is not proven live

Everything above is proven on a scratch database with recording fakes and, for HTTP and the editor, a throw-away instance. Not proven against real systems: running an RMM script on a real device (needs the RMM module and an enrolled device; the dispatcher is the module's technician API), the Entra actions against a real tenant, Slack/Teams delivery (no sender ships), and anything in Odoo (no write gateway ships; production Odoo is never written). To verify live: switch *Allow rules to run RMM scripts* on, create a rule on `rmm.device.offline` with a harmless library script, take a test device offline and watch RMM > Jobs and the Run log; for Entra turn on the write switch in a test tenant and complete an offboarding for a test account.

## Database (migration 2.6.139)

`automation_rules`: `priority`, `stop_on_match`, `rate_limit_per_min`, `rr_cursor`, and the five new `action_type` values. New `automation_rule_runs` (the run log) and `automation_sla_marks`
(once-only SLA announcements). New `settings.config_automation_enabled`. All idempotent.

## Code map

`src/Automation/`: `PayloadEnricher`, `CronEmitters`, `LegacyRuleImporter`, `ChatNotifier` (+ `ChatNotifierRegistry`, `UnavailableChatNotifier`), `RmmScriptDispatcher` (+ `CoreRmmScriptDispatcher`), `OdooUserGateway` (+ `UnconfiguredOdooUserGateway`), `Actions/RunScriptAction|CreateOdooUserAction|NotifyChatAction|EntraRuleAction`, `ConditionEvaluator`, `RuleEngine`, `Template`, `EventPayloads`, `SlaEventEmitter`, `RuleView`, `TicketRuleView`, `RuleSummary`, `RuleForm`, `RuleAdmin`, `RuleRecipes` (the Event rules page), `Actions/*` (registry in `ActionRegistry`; the four older actions in
`BuiltinAction` subclasses keep running through `rivetAutomationActionHandlers`). Tests: `tests/automation_engine.php`, `tests/rule_summary.php`, `tests/automation_i33.php` (issue #33), `tests/automation_api_i33.php` (the REST API), `tests/e2e/event_rules_ui.py`.
To add an action: implement `ActionInterface`, register it in `ActionRegistry`, widen the `action_type` enum in a migration, add its fields to `admin/includes/event_rules_editor.php`, its form mapping to `RuleForm::actionInput()` / `configToForm()`, a sentence to `RuleSummary::actionPhrase()` and an icon to `admin/event_rules.php`.
