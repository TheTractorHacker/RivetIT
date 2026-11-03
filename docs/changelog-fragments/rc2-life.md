### Added
- **HR requests from the employee portal** (issue #31): managers and department administrators can ask HR to start an onboarding, offboarding or role-change workflow (portal menu *HR requests*, off until Administration > Settings > Employee portal > "Allow HR requests from the portal" is ticked, DB 2.6.242). The request waits for an HR agent (*Service desk > HR requests*, also `GET/POST /api/v1/hr_requests`); nothing starts and no record changes until it is approved, and rejecting or withdrawing starts nothing. Department scoped (a manager can name only their reports), audited, in-app notification to eligible HR agents. Approving picks the template and starts the run through the normal workflow code.
- **Okta workflow actions** (issue #31): create a user (staged, no password, no activation email), suspend a user and clear their sessions (never delete), and add a user to groups, as workflow tasks. **Off by default** (Administration > Employee Workflow Templates > Okta actions; domain, encrypted API token and the "Allow workflows to change Okta" switch, DB 2.6.243). Department check, idempotent, token never in messages, logs or audit. Tested against a mock only; not verified against a real Okta org.
- **Legacy ticket rules in the Event rules tab** (issue #33): every older ticket rule is listed read-only with its state; a rule that has an event-rule equivalent has **Convert to event rule** (a switched-off copy, the legacy rule keeps running) and then **Switch over** (copy on, legacy off, one confirmed step). Rules with no equivalent stay as they are, with the reason.
- Workflow approval tasks reach approvers in Slack / Teams through the existing chat layer (`ChatNotifier::approvalRequested`); covered by a new end-to-end test. No new chat code.

### Changed
- Mobile API: a catalog `table` answer now accepts JSON `null` for an empty cell (it was a 422). `docs/MOBILE_API.md` documents answering table questions with an example and the limits, and the new `/hr_requests` endpoint.

### Fixed
- (none)
