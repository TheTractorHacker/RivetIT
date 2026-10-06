# Employee lifecycle workflows

Onboarding and offboarding workflows are checklists tied to a person. A template (Administration > Tags & Categories >
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

The Microsoft and Google directory syncs do not raise these events yet.

## Database

Migration 2.6.134 adds the columns to `workflow_template_tasks` and `workflow_run_tasks`, the `paused` run status, the
`workflow_task_log` table, `start_workflow` in `automation_rules.action_type`, and `settings.config_lifecycle_auto_start`.
