# Competitor research: Freshservice and Jira Service Management (issue #37)

Accessed **2026-11-01**. Redoes and extends ROADMAP section 3.6 (first pass 2026-10-05). Nothing was created on GitHub.

## Method and limits

- Pages were read through a summarizing fetch tool (WebFetch), not a browser. Where a page was only a navigation index, or the text was cut off before
  prices, the claim is marked U. The tool cannot see plan-gated detail inside logged-in admin consoles.
- **V** = stated on a vendor page listed in the sources. **U** = not on a vendor page we could read, or only a third-party summary. Prices are for
  positioning only; re-check before quoting.
- RivetIT column comes from this repo at origin/beta d798e3054 (docs and code read; features were **not** re-run live for this report). "Y" built, "P" partial, "N" absent.
- Stale items in ROADMAP 3.6 found while comparing: conditional catalog fields, Entra create/disable lifecycle actions, Slack interactive buttons and the
  entity-link dependency graph now exist in the code. The ROADMAP rows were updated.

## Sources

| # | URL | What it gave |
|---|---|---|
| F1 | https://www.freshworks.com/freshservice/it-service-management/ | module list, CMDB, AI, automation (V) |
| F2 | https://www.freshworks.com/freshservice/pricing/ | plan names, prices, feature tiers (V) |
| F3 | https://www.freshworks.com/freshservice/features/employee-onboarding/ | onboarding, child tickets, bulk upload (V) |
| F4 | https://www.freshworks.com/freshservice/it-service-desk/it-service-catalog-management/ | catalog forms, shared fields (V) |
| F5 | https://support.freshservice.com/support/solutions/articles/50000000283-change-management | only a custom change-status note; no CAB detail (U) |
| J1 | https://www.atlassian.com/software/jira/service-management/features/itsm | request, incident, problem, change, asset, config, knowledge (V) |
| J2 | https://support.atlassian.com/jira-service-management-cloud/docs/get-to-know-the-main-jira-service-management-features/ | docs index: queues, SLAs, forms, approvals, channels (V, titles only; page was truncated at 100k chars) |
| J3 | https://www.atlassian.com/collections/service/pricing | fetched, but prices were cut off (U) |
| J4 | WebSearch of third-party pricing blogs (eesel.ai, spike.sh, unthread.io, costbench.com and others) | Free 3 agents, Standard about $20, Premium about $51.42 per agent per month; sources disagree (U) |

## Feature matrix

| Capability | Freshservice | Jira Service Management | RivetIT now | Gap |
|---|---|---|---|---|
| Incident / request queues, bulk actions | Incident and task mgmt on all plans (V, F2) | Queues, bulk actions, ML grouping of similar tickets (V, J1) | Y tickets, kanban, bulk merge/actions. No ML grouping | Low |
| Service catalog + portal | Searchable catalog, branded portal; Growth+ (V, F1 F2) | Help Center, portals, request types (V, J2) | Y catalog, recent/trending, per-item forms | None |
| Conditional catalog fields | Not on catalog page (F4 silent) U; shared and auto-populated fields V | Dynamic forms with conditions (V in earlier pass; not re-read) | Y show-if on earlier answers (migration 2.6.140). No tables, no multi-condition | Low |
| Multiple portals | "up to 50 portals" Growth (V, F2); catalog page silent | Per-space portals (V, J2) | N single portal | Med for MSP, Low for internal IT |
| Approvals | Catalog approval automation (V, F4); detail U | Approval stages per request type; auto-approve recipes (V, J2) | Y ordered any/all steps, manager approver, risk auto-approve | None |
| SLA and OLA policies | SLA and OLA (V, F1); Growth+ (V, F2) | Many SLA policies, escalation rules; Premium per J4 (V/U) | P: policies, business hours, holidays, breach events. No OLA objects | Med: OLA |
| Problem management | Pro+ plan; RCA workflows (V) | Group incidents to problem, workarounds, incidents-per-problem report (V, J1) | Y problems + link to fixing change | Low: workaround field and report U |
| Change management | Approvals with CAB, rollout, backout plans (V, F1); Pro+ | Risk score, type-based approvals, auto-approve low risk, native change calendar, CI/CD deployment gating (V, J1) | P: status machine, risk, impl and rollback plans. Source says "no separate approver/CAB model". No calendar | **High** |
| Release management | Named module, no detail (V/U), Pro+ | Via deployments and change (J1) | N | Low-Med |
| Major incident / swarm | Major incident, Pro+ (V, F2) | Escalate major incidents, chat channel, video bridge, timeline, post-mortem (V, J1) | N | Med |
| On-call and alerting | On-call mgmt, Growth+ (V, F2) | Schedules, routing, escalation, SMS/push (V, J1) | N (RMM has escalation policies, not on-call rotas) | Med |
| CMDB and relationships | Auto-updating CMDB, CI relationships (V, F1) | Dependency mapping, discovery, unauthorized-change issues (V, J1) | P: assets + typed entity links (depends_on, runs_on...) with impact traversal depth 3. No discovery-based CMDB, no change-impact on risk | Med |
| Asset discovery | ITAM across hardware, software, cloud (V) | Network scan discovery, shadow IT (V) | P: Intune, RMM agent inventory. No network scan | Low (RMM covers) |
| Workflow / automation | Workflow Automator, Supervisor Rules, Orchestration Center (V, F1) | Automation rules with templates (V, J1) | Y event rules, ticket rules, run log, loop guard, lifecycle workflows. Smaller action set | Low-Med: more actions |
| Employee onboarding | HR request from portal, child tickets, forms to manager and employee, bulk upload, reminders (V, F3) | Via request types (U) | P: lifecycle workflows with deps, dry-run, hire/term triggers, Entra create/disable (flagged). No HR portal request, no bulk | Med |
| Chat (Slack/Teams) | Virtual agent in Slack/Teams (V, F1) | Two-way chat sync, Slack virtual service agent (V, J1) | P: one-way notifications; Slack interactive buttons (mock-tested only). No virtual agent | Med |
| Knowledge base | KB in all plans (V); AI use U | Articles in portal, Confluence, gap analytics, ML search (V, J1) | Y KB v2 versions, review dates. No usage-gap analytics | Low |
| AI | Freddy: copilot (add-on $29/agent/mo), agents, insights, virtual agent (V, F1 F2) | Virtual agent, AI answers, AI stakeholder updates (V, J1) | P: read-only MCP server; no in-app triage, summary, reply | Med-High |
| CSAT | Reporting (V, generic) | CSAT reporting (V, J1) | Y csat with reminders | None |
| Reporting | Dashboards, XLAs (Enterprise) (V) | Dashboards, metrics (V) | Y reporting module, widgets | Low |
| Project management | Category listed, no detail (U) | Not on read pages | Y projects, Gantt, kanban | None |
| Sandbox / audit log | Sandbox and audit logs, Enterprise (V, F2) | U | Y audit log; no sandbox | Low |

## Price context (positioning only)

- Freshservice (annual, per agent per month): Starter $19, Growth $49, Pro $99, Enterprise custom; Copilot add-on $29. V, F2. Problem, change, release and
  major incident sit in **Pro ($99)**.
- JSM: Free up to 3 agents; Standard about $20; Premium about $51.42, which is where advanced incident, problem and change sit. U (J4: third-party; Atlassian's own page
  did not render prices; one snippet showed $17.65, unverified).
- RivetIT is self-hosted, so the pitch is that change/problem/SLA/CAB-class features are not behind a $50-$100 per agent tier.

## Prioritized gap list

1. **Change advisory board and change calendar** (High). Both vendors lead with them; RivetIT's own source says there is no CAB model. Build: CAB approver
   group on a change, scheduled start/end window, calendar view with conflict warning, freeze windows. Reuse approval-step code from the service catalog.
2. **In-app AI: ticket summary, suggested reply, triage** (Med-High). Both sell it; RivetIT only exposes MCP. Off by default, per-department opt-in.
3. **Onboarding via HR portal request plus bulk upload (CSV) creating workflow runs** (Med). Freshservice shows this plainly (F3). Workflow engine already exists.
4. **Major incident flow** (Med): flag a ticket as major, notify group, timeline, post-incident review template.
5. **OLA objects and on-call rota** (Med). SLA exists; add internal-team targets and a schedule table feeding escalation.
6. **Two-way chat and virtual agent** (Med). Slack buttons exist; creating tickets from chat and KB answers is the next step. Needs real Slack/Teams to verify.
7. **Change impact from entity links** (Med): show downstream dependents on the change page and feed risk. Links data already exists.
8. Multiple portals/branding per department (Med for MSP edition), release records, KB gap analytics, ML ticket grouping (Low).

Already at parity or ahead: service catalog with forms and approval chains, conditional fields, SLA with business hours, problem management, CSAT, KB versions,
projects, event-rule automation with run log, Entra lifecycle actions (default off), self-hosting and price.

## Not verified

Freshservice change/CAB/release detail beyond marketing text (F5 was off-topic); JSM Premium vs Standard feature split and real prices; whether either vendor's
conditional fields or onboarding exist on lower tiers; JSM Free plan limits (only third-party). RivetIT cells were read from code and docs, not exercised.
