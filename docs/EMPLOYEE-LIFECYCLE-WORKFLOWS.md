# Employee lifecycle workflows

Onboarding, offboarding, role-change and department-transfer workflows are checklists tied to a person. A template (Administration > Tags & Categories >
Employee Workflow Templates) is a list of tasks; starting it for a person copies the tasks onto a run, so editing a template
later never changes a run already in progress. A template that uses none of the options below behaves exactly like the
original flat checklist.

## Task options

| Option | What it does |
|---|---|
| **Waits for** (dependencies) | A task lists the tasks that must be completed or skipped before it can be worked. Until then it is **blocked** and cannot be completed or skipped. Completing or skipping a task unblocks the tasks that were waiting on it; reopening it blocks them again. A dependency must be a task in the same template and the editor refuses a loop. |
| **Assignee** | An active agent responsible for the task. The free-text **Owner label** stays as a label. |
| **Due (days) / Counted from** | Offset in days (negative = before) from the workflow start, the employee's start date, or the employee's end date. A date anchor with no date on the person falls back to the workflow start. Due dates are resolved when the run starts. The run page marks tasks **Due soon** (within 24 hours) and **Overdue**. |
| **Approval** | The workflow waits at this task until an approver approves it. The approver is the employee's manager (via the manager's portal-linked agent login), a specific user, or anyone holding a role. Administrators can always decide; when no approver can be resolved, administrators are the approvers so a run never gets stuck. Approvals record who, when and a comment. A **rejection pauses the whole run** and notifies whoever started it and the task's assignee. Reopen the task to ask again, or an administrator can skip it to carry on (logged as an override). |
| **Automated action** | RivetIT does the task itself, see below. |

## Automated actions

| Action | Does | Notes |
|---|---|---|
| Create a ticket | Opens a ticket in the person's department through the same code as other automatic tickets. | Subject and details accept placeholders. |
| Send an email | Puts one email on the outgoing mail queue (the mail queue cron sends it). | To the employee, their manager, or one fixed address. Placeholder values are escaped in the body. |
| Notify an agent | In-app notification to one agent or every agent. | |
| Send a webhook event | Emits an event (default `workflow.task_webhook`) on the event bus. | Webhooks subscribed to that event (Administration > Webhooks) get a queued, signed, retried delivery. A task never carries a URL itself. |
| Disable the employee's portal login | Archives the person's client-portal login. | Offboarding templates only. Nothing is deleted and restoring the contact restores the login. A linked agent account is never touched. |

### Entra account actions

Three more actions change accounts in Microsoft Entra ID through Microsoft Graph. They are **off unless an administrator ticks
"Allow RivetIT to change Entra accounts"** (Administration > Integrations > Directory Sync), which in turn needs the
`User.ReadWrite.All` and `Group.ReadWrite.All` application permissions, see docs/ENTRA_INTUNE_SETUP.md. With the setting off, none of them sends anything: the task
retries, then becomes a manual task whose error names the setting, and the dry-run preview says so.

| Action | Template | Does |
|---|---|---|
| Entra: disable the employee's account and revoke sessions | Offboarding | Finds the user by UPN (the person's email), then by mail address (it refuses if two users share it); sets `accountEnabled=false` and revokes sign-in sessions. **Never deletes.** An account that cannot be found is a failure (manual task), never a silent success. |
| Entra: create the employee's account | Onboarding | Creates the user, **disabled** unless the task says enabled, with a random 20-character temporary password and "must change at first sign-in", then adds it to the listed groups. If the UPN already exists nothing is created and no password is set (so a retry is safe). |
| Entra: add the employee to groups | Onboarding | Adds the existing account to groups given by Object ID. Already a member counts as done. |

The temporary password is generated inside RivetIT and is shown **once, only to the technician the task is assigned to** (the person who
started the run when it has no assignee): a **Show the temporary password (once)** button on the run page, valid 7 days. It is stored
encrypted, erased when read, and never appears in the Activity log, audit trail, notifications, email or the task result. Reading it is
audited (without the value). Give it to the employee over a secure channel. If nobody is assigned and nobody started the run, no password
is kept and the result says to reset it in Entra.

Every Graph call that changes something writes an audit event (`entra.account_created`, `entra.account_disabled`,
`entra.sessions_revoked`, `entra.group_member_added`) with the target and the outcome, never a password or token. Retries follow the rules above
(3 attempts, then manual); Graph throttling is retried inside each attempt. A missing permission is reported as "Admin consent missing for
User.ReadWrite.All" (or Group.ReadWrite.All). Verified against a local Graph mock only, never a real tenant.

Placeholders: `{{employee_name}}`, `{{employee_email}}`, `{{employee_title}}`, `{{start_date}}`, `{{end_date}}`,
`{{manager_name}}`, `{{manager_email}}`, `{{department}}`, `{{template_name}}`, `{{run_id}}`, `{{task_title}}`.

Ready action tasks run when the workflow starts or when what they wait for is done. Each execution makes up to 3
attempts; every attempt is written to the run's **Activity log** (table `workflow_task_log`) and to the audit trail
(`workflow.action_executed`, `workflow.action_failed`). If all attempts fail the task becomes **action_failed**, a manual
state: an agent can finish the work by hand and tick it off, or run the action again. A failure notifies whoever started
the run. An action never runs twice on its own.

## Preview (dry run)

On a person's page, **Preview** next to **Start** shows which tasks would run, in what order, with due dates resolved and
what each action would do, with placeholders filled in. It writes nothing, sends nothing, queues nothing.

## Reminders

`cron/workflow_cron.php` (every 15 minutes, installed by `deploy/install.sh`; shown in Administration > Cron) notifies the
assignee (otherwise whoever started the run, otherwise every agent) of tasks due within a day and of overdue tasks, once per
task per state. It sends in-app notifications only, never email, and hands an action stuck in "running" for 15 minutes to a
person.

## Starting workflows from events

Off by default. Turn on **Start workflows automatically** on the Employee Workflow Templates page (setting
`config_lifecycle_auto_start`). Then:

* **`employee.hired`** is raised when a new person is created with an active or pre-hire status and a start date within 30
  days of today (either side), or when a person's status becomes active from pre-hire, terminated or archived.
* **`employee.terminated`** is raised when the status becomes termination pending or terminated, or an end date is set on
  someone who was not already leaving.

They are detected on the contact edit form, the People import and the Odoo directory sync, and recorded as audit events,
which puts them on the event bus (webhooks and event rules; the event bus itself is the existing `core.automation.enabled`
module on editions that can switch it). A rule on Administration > Event rules with the action **Start an employee workflow**
starts the chosen template for the person the event is about. It is idempotent: while a person has an open run (in progress
or paused) of that template, further events start nothing. `employee.hired` can only start an onboarding template and
`employee.terminated` an offboarding template.

* **`employee.role_changed`** is raised when a person's job title changes from one real title to another (filling in a first title is not a change). A rule starts a **role change** template.
* **`employee.transferred`** is raised when a person moves from one department to another (a first placement is not a transfer). A rule starts a **department transfer** template.
  A leaver (termination pending / terminated) never also raises these two.

The **Microsoft and Google directory syncs raise all four events** (DB 2.6.213 changes nothing for them, the hook is code): a pre-hire whose account is now enabled is a hire, a disabled / suspended account is a departure, a changed Entra job title or department is a role change / transfer. They are recorded only when "Start workflows automatically" is on, and a brand-new person created by a sync counts as a hire only with a start date near today, so the first large sync stays quiet. A second identical sync raises nothing. Verified with fixtures against a scratch database; **not run against a real Entra tenant or Google Workspace**.

## Role change and transfer workflows

Template types: Onboarding, Offboarding, **Role change**, **Department transfer**. Starter templates are available under the template list (installed inactive). Typical tasks: update group memberships (Entra: add to groups, licences), collect or assign a device, update building access, notify the manager. After a transfer the Entra department attribute may still be the old one until the directory is updated; the Entra actions refuse an account whose department does not match the person's (the IT-9 check), so such a task becomes a manual task rather than touching a wrong account.

## Onboarding form (variables) and conditional tasks

On the template (Edit > Onboarding form) add up to 12 questions (text, yes/no, choose one, date; optionally required, with a default). The person starting the workflow answers them (on the person's page, the dry-run page, or as columns in a bulk CSV); answers are stored on the run and available as `{{var.field_name}}` in action text, always escaped. Automation (event rules) cannot ask: it takes the defaults, and a required question without a default is left empty.

A task can be limited **"Only for some people"**: departments, job-title words, employee types, and form answers. A task whose condition the person does not meet is not copied onto that run at all (nothing to skip); a task that waited for it simply stops waiting. The dry run lists the tasks left out and why, and updates as you change an answer.

## Delayed tasks

An automated task can have **Delay (days)**: it runs no earlier than N days after the "Counted from" date (workflow start, employee start date or end date). Until then it is shown as **scheduled**; `cron/workflow_cron.php` (every 15 minutes) releases it. On the run page an agent can **run it now** or **skip it** (which cancels it for good). A scheduled task cannot be ticked off by hand. Cancelled and paused runs never fire scheduled tasks. A run is not complete while a required scheduled task is waiting. Scheduled tasks are not listed by `GET /api/v1/workflow_tasks.php` (it lists tasks a person can work).

## More actions

| Action | Template types | Does | Notes |
|---|---|---|---|
| Assign a device | any | Takes one unassigned, non-archived asset of the given type and stock status (default "Ready to Deploy") from the person's own department, links it to the person, sets the status (default "Deployed"), writes asset history and an audit event. Or assigns a specific asset ID of the same department. | One UPDATE claims the asset, so two workflows cannot take the same laptop. Running it again assigns no second device. No stock: becomes a manual task. Statuses must exist under Tags & Categories. |
| Collect devices | any | Unlinks every device assigned to the person (optionally only some types), sets the status (default "Ready to Deploy"), history and audit. | |
| Archive the record and portal login | offboarding | Archives the contact and their client-portal login (not a delete). The primary contact of a department is refused. | Use with Delay for "30 days after the end date". |
| Entra: assign / remove Microsoft 365 licences | assign: onboarding, role change, transfer; remove: offboarding, role change, transfer | `assignLicense` for SKU GUIDs. | Same gate as the other Entra actions (the "Allow ... to change Entra accounts" setting, the department check). The user needs a usage location for an add. |
| Entra: set the mailbox automatic reply | offboarding | PATCH `mailboxSettings` automatic replies on, message as written (placeholder values from the directory are HTML-escaped). | Needs the `MailboxSettings.ReadWrite` application permission. |
| Odoo: create / archive the employee | onboarding / offboarding | Creates `hr.employee` (unless the work email exists) / sets `active=false` (never unlink). | **Off unless Administration > Employee Workflow Templates > "Allow workflows to create / archive employees in Odoo" is ticked** (settings.config_lifecycle_odoo_actions, DB 2.6.214). Odoo is a live system: verified against an in-process mock only. |
| Identity system: create / disable the account (Active Directory, VPN, Okta, Google Workspace) | onboarding / offboarding | Calls the system's connector (`src/Workflow/Connectors/IdentityConnector`). | **Okta has a connector (below); Active Directory, VPN and Google Workspace are still stubs**: they refuse with "not connected" so the task becomes a manual task. A real connector implements `IdentityConnector` (idempotent, never deletes, never returns a password) and is registered with `ConnectorRegistry::register()`. |
| Identity system: add the employee to groups | onboarding / role change / transfer | `GroupConnector::addToGroups()`; only Okta implements it. | Needs at least one group (name or the system's id). A connector without group support refuses and the task becomes a manual task. |

### Okta (create, suspend, add to groups)

`src/Workflow/Connectors/OktaConnector` talks to the Okta Management API with an SSWS API token. **Off by default.** On Administration > Employee Workflow Templates > *Okta actions* an administrator saves the Okta domain (a bare host name such as `example.okta.com`, validated), the API token (stored encrypted with `encryptSetting`, never shown again, "Remove the saved token" clears it) and ticks **Allow workflows to change Okta** (`settings.config_lifecycle_okta_actions`, DB 2.6.233). With the switch off, or no domain / token, the connector refuses with a message that names the setting, **nothing is sent to Okta**, and the task becomes a manual task. The dry-run preview never sends anything.

| Action | Does |
|---|---|
| create | Looks the user up by login first; an existing user is reported and left alone (no second user, no group change). Otherwise creates a **STAGED** user (no password, no credentials sent) in the listed groups; only when the task says *enabled* it activates the user with `sendEmail=false`, so Okta mails nobody from a test or a typo. RivetIT never creates, sees or logs a password: the person completes setup through the org's normal activation flow. |
| disable | **Suspends** (reversible in Okta with Unsuspend) an ACTIVE user and clears their sessions; **never deletes**. An already suspended user still gets their sessions cleared. Any other status (staged, provisioned, ...) is refused and named, so a person finishes it. An account that cannot be found is a failure, not a silent success. |
| add to groups | Adds the existing user to each group (name must match exactly one group, or give the `00g...` id). An unknown or ambiguous name refuses **before** any change. Already a member counts as done. Nobody is ever removed. |

The Okta account's `profile.department` must equal the person's department (fail closed when either is empty), the same rule as the Entra actions. Audit events: `okta.user_created`, `okta.user_disabled`, `okta.group_member_added` and `settings.lifecycle_okta_changed` (never the token). Okta errors are summarised without echoing the token or the raw body (401, 403, 429 and network errors have their own wording). **UNVERIFIED LIVE: written to Okta's documented API and tested only against an in-process mock (`tests/lifecycle_life33.php`); no real Okta org has been contacted.**

**Live verification checklist for Okta (not done; needs a test Okta org and a scoped API token):**
1. Save the domain and token with the switch OFF; start an onboarding with an Okta create task: it must become a manual task naming the setting, and the Okta System Log must show nothing.
2. Turn the switch on. Create a test user with *enabled* off: the user is STAGED, no activation email arrives, the groups are set, no password exists.
3. Run it again: no second user, no error.
4. Add-to-groups with a group name, then with a duplicate-named group: the second must refuse before any change.
5. Offboarding with the department attribute matching: the user is SUSPENDED and the session list is empty; run again: still fine. With a mismatched department: refused, user untouched.
6. Confirm the token role can do only what is needed (read users and groups, create users, suspend, manage group membership), and that a revoked token produces the 401 wording.

## HR-initiated requests from the portal

Managers and department administrators can ask HR to start an **onboarding**, an **offboarding** or a **role change** from the employee portal (*HR requests*). Off by default: Administration > Settings > Employee portal > **Allow HR requests from the portal** (`settings.config_portal_hr_requests`, DB 2.6.232).

* The request is only a row in `hr_workflow_requests` (status `pending`). **Nothing starts, no person is created and no record changes until an HR agent approves it.** Rejecting or withdrawing starts nothing.
* Scoping: the department is always the requester's own. A manager can name only their direct reports; a department administrator anyone active in their department except themselves; never a leaver and never another department. `submit()` re-checks the person's department itself.
* HR side: *Service desk > HR requests* (`agent/hr_requests.php`, also `GET/POST /api/v1/hr_requests`). Needs Departments edit access, and only requests of departments the agent may access are listed or decidable. HR picks the workflow template (an active template of the matching type), may add a note, and approves or rejects (a rejection needs a reason; the requester sees it). Approval claims the request atomically first (two agents cannot both act); if starting fails (for example the named manager has left) the claim is released and the request stays pending with the reason shown.
* What approval does: onboarding creates the pre-hire in the requester's department with the named manager and starts the chosen template; offboarding sets the expected end date (it anchors the template's due dates) and starts the offboarding template; role change starts the role-change template and records the new title and effective date in the run's notes. **The job title is not changed behind HR's back.** Starting uses `WorkflowService::startRunIfNone`, so an open run of the same template for the same person is never doubled.
* Every step is audited: `hr_request.submitted`, `hr_request.approved`, `hr_request.rejected`, `hr_request.cancelled`. Eligible HR agents get an in-app notification when a request arrives. No email is sent by this feature.

### Approving workflow tasks from Slack / Teams

A workflow approval task is offered in chat only through the existing chat layer: when the task becomes ready `WorkflowService` calls `ChatNotifier::approvalRequested($db, 'workflow_task', $id)` (the #36 interface), the approver gets an Approve / Deny message, and the click goes to `ChatActions::decideWorkflow()` which uses `WorkflowService::approveTask / rejectTask`, so every approver rule, the audit event and the "rejection pauses the run" behaviour are the web ones. Nothing is sent unless *Actions from chat* is on. HR requests (above) are not offered in chat. Covered by `tests/chat_integration.php` (mock Slack).

## Built-in presets and bulk start

Template page > **Add a built-in preset**: Notify the manager (email), Phone extension, Building access (manager approval, then the badge task), Assign a laptop from stock, Collect equipment, Archive the record 30 days after the end date. A preset adds ordinary tasks you can edit; adding twice adds nothing. The template list can install an inactive **starter template** per type. No preset contains an Entra, Odoo or connector action.

**Start for many people** (Administration > Employee Workflow Templates): paste email addresses or upload a CSV (`email` column, plus one column per onboarding-form question), preview, confirm. At most 200 per batch; a person with the workflow open is skipped; ambiguous or missing emails are reported, not guessed; the commit re-checks every row. The **People import** also offers "start an onboarding workflow for the people this import created" (a template with required questions is refused there; use the bulk page).

## What is verified, and what is not

Verified in `tests/lifecycle_automation_test.php` against a scratch database, a local Graph mock and an in-process Odoo mock: migrations, conditions, variables, scheduling, device actions, events and start rules, directory-sync events (fixtures), bulk start, presets. **UNVERIFIED LIVE:** the Entra actions (the original three plus licences and mailbox) on a real tenant; the Odoo actions on a real Odoo (the JSON-2 `create` / `write` call shapes follow Odoo's documented API but have not been run); all four identity connectors (stubs); the Microsoft / Google syncs raising events on a real directory. Slack / Teams approvals for workflow tasks are not built: they need the interactive Slack/Teams endpoint (issue #36).

## Database

Migration 2.6.213 adds the role_change / transfer types, `variables_json`, `preset_key`, `delay_days`, `condition_json`, `run_after` and the `scheduled` task status; 2.6.214 adds `settings.config_lifecycle_odoo_actions`.

Migration 2.6.134 adds the columns to `workflow_template_tasks` and `workflow_run_tasks`, the `paused` run status, the
`workflow_task_log` table, `start_workflow` in `automation_rules.action_type`, and `settings.config_lifecycle_auto_start`.
