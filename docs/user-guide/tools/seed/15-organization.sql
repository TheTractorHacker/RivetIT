-- Demo data for the "Departments and People" guide page (group: organization).
-- Depends only on 00-core.sql (looks rows up by name / e-mail, never by numeric id).
-- Idempotent: every insert is guarded, so re-running adds nothing new.
-- Nothing from 00-core is modified or deleted.
--
-- Adds:
--   * department tags and person tags (and applies them),
--   * one archived department ("Facilities & Maintenance") with one archived person,
--   * four more people, chosen so the guide can show every employment state:
--       Nadia Kowalski (pre-hire, has a running onboarding checklist),
--       Marco Bellini (on leave), Dale Whitaker (left the company: archived, offboarding done),
--       Ray Dunlap (belongs to the archived department),
--   * three Employee Workflow Templates (onboarding / offboarding checklists) and the two
--     workflow runs described above,
--   * one department Onboarding Template (a project template with a checklist).

-- ---- Tags (tag_type 1 = department, 3 = contact) -------------------------------------
INSERT INTO tags (tag_name, tag_type, tag_color, tag_icon)
SELECT x.n, x.t, x.c, x.i FROM (
  SELECT 'Plant Floor' AS n, 1 AS t, '#fd7e14' AS c, 'industry' AS i
  UNION ALL SELECT 'Regulated',       1, '#dc3545', 'shield-alt'
  UNION ALL SELECT 'Shared Services', 1, '#20c997', 'users-cog'
  UNION ALL SELECT 'Customer-facing', 1, '#007bff', 'building'
  UNION ALL SELECT 'Key Holder',      3, '#6f42c1', 'key'
  UNION ALL SELECT 'Fire Warden',     3, '#dc3545', 'fire-extinguisher'
  UNION ALL SELECT 'First Aider',     3, '#28a745', 'first-aid'
) x WHERE NOT EXISTS (SELECT 1 FROM tags t WHERE t.tag_name = x.n AND t.tag_type = x.t);

INSERT IGNORE INTO client_tags (client_id, tag_id)
SELECT c.client_id, t.tag_id
FROM (
  SELECT 'Production' AS dept, 'Plant Floor' AS tag
  UNION ALL SELECT 'Production', 'Regulated'
  UNION ALL SELECT 'Warehouse & Logistics', 'Plant Floor'
  UNION ALL SELECT 'Finance & Accounting', 'Regulated'
  UNION ALL SELECT 'Finance & Accounting', 'Shared Services'
  UNION ALL SELECT 'Human Resources', 'Shared Services'
  UNION ALL SELECT 'Human Resources', 'Regulated'
  UNION ALL SELECT 'Sales & Marketing', 'Customer-facing'
) m
JOIN clients c ON c.client_name = m.dept
JOIN tags t ON t.tag_name = m.tag AND t.tag_type = 1;

-- ---- One archived department ------------------------------------------------------------
INSERT INTO clients (client_name, client_type, client_abbreviation, client_status, client_currency_code, client_net_terms,
                     client_notes, client_cost_center, client_security_classification, client_created_at, client_archived_at)
SELECT x.n, x.t, x.a, x.s, 'USD', 30, x.notes, x.cc, 'General', NOW() - INTERVAL 400 DAY, NOW() - INTERVAL 60 DAY
FROM (
  SELECT 'Facilities & Maintenance' AS n, 'Operations' AS t, 'FAC' AS a, 'Inactive' AS s,
         'Merged into Warehouse & Logistics. Kept for its ticket and asset history.' AS notes, 'CC-590' AS cc
) x WHERE NOT EXISTS (SELECT 1 FROM clients c WHERE c.client_name = x.n);

INSERT IGNORE INTO department_sites (client_id, location_id)
SELECT c.client_id, l.location_id FROM clients c JOIN locations l ON l.location_name = 'Distribution Center - Milwaukee'
WHERE c.client_name = 'Facilities & Maintenance';

-- ---- Extra people -----------------------------------------------------------------------
-- Phones are stored as digits, like the app stores them. Archived rows carry contact_archived_at.
INSERT INTO contacts (contact_name, contact_employee_id, contact_employee_type, contact_employment_status, contact_work_arrangement,
                      contact_start_date, contact_title, contact_email, contact_phone, contact_extension,
                      contact_department, contact_client_id, contact_location_id, contact_primary, contact_created_at, contact_archived_at)
SELECT p.n, p.eid, 'employee', p.st, p.wa, p.sd, p.t, p.e, p.ph, p.ext, cl.client_name, cl.client_id, l.location_id, p.pri,
       NOW() - INTERVAL p.age DAY, p.arch
FROM (
  SELECT 'Nadia Kowalski' AS n, 'E1044' AS eid, 'pre-hire' AS st, 'onsite' AS wa, (CURDATE() + INTERVAL 12 DAY) AS sd,
         'Quality Inspector' AS t, 'nadia.kowalski@summitridge.example' AS e, '6085550144' AS ph, '144' AS ext,
         'Production' AS dept, 'Plant 1 - Sun Prairie' AS loc, 0 AS pri, 4 AS age, NULL AS arch
  UNION ALL SELECT 'Marco Bellini', 'E1053', 'leave', 'onsite', '2019-08-12',
         'Maintenance Mechanic', 'marco.bellini@summitridge.example', '6085550153', '153',
         'Warehouse & Logistics', 'Distribution Center - Milwaukee', 0, 900, NULL
  UNION ALL SELECT 'Dale Whitaker', 'E1045', 'terminated', 'onsite', '2021-03-15',
         'Machine Operator', 'dale.whitaker@summitridge.example', '6085550145', '145',
         'Production', 'Plant 1 - Sun Prairie', 0, 900, NOW() - INTERVAL 20 DAY
  UNION ALL SELECT 'Ray Dunlap', 'E1070', 'terminated', 'onsite', '2015-02-02',
         'Facilities Supervisor', 'ray.dunlap@summitridge.example', '6085550170', '170',
         'Facilities & Maintenance', 'Distribution Center - Milwaukee', 1, 900, NOW() - INTERVAL 60 DAY
) p
JOIN clients cl ON cl.client_name = p.dept
JOIN locations l ON l.location_name = p.loc
WHERE NOT EXISTS (SELECT 1 FROM contacts c WHERE c.contact_email = p.e);

-- Reporting lines for the new people
UPDATE contacts c
JOIN (
  SELECT 'nadia.kowalski@summitridge.example' AS person, 'aisha.rahman@summitridge.example' AS boss
  UNION ALL SELECT 'marco.bellini@summitridge.example', 'frank.delgado@summitridge.example'
  UNION ALL SELECT 'dale.whitaker@summitridge.example', 'aisha.rahman@summitridge.example'
) m ON m.person = c.contact_email
JOIN contacts b ON b.contact_email = m.boss
SET c.contact_manager_id = b.contact_id
WHERE c.contact_manager_id IS NULL;

-- ---- Tags on people -----------------------------------------------------------------------
INSERT IGNORE INTO contact_tags (contact_id, tag_id)
SELECT ct.contact_id, t.tag_id
FROM (
  SELECT 'carlos.mendoza@summitridge.example' AS e, 'Key Holder' AS tag
  UNION ALL SELECT 'frank.delgado@summitridge.example', 'Key Holder'
  UNION ALL SELECT 'aisha.rahman@summitridge.example', 'Fire Warden'
  UNION ALL SELECT 'aisha.rahman@summitridge.example', 'First Aider'
  UNION ALL SELECT 'jake.sullivan@summitridge.example', 'Fire Warden'
  UNION ALL SELECT 'emma.novak@summitridge.example', 'First Aider'
  UNION ALL SELECT 'sophie.tran@summitridge.example', 'First Aider'
) m
JOIN contacts ct ON ct.contact_email = m.e
JOIN tags t ON t.tag_name = m.tag AND t.tag_type = 3;

-- ---- Employee Workflow Templates (onboarding / offboarding checklists for one person) ----
INSERT INTO workflow_templates (name, type, description, created_by)
SELECT x.n, x.ty, x.d, (SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example')
FROM (
  SELECT 'New Employee Onboarding' AS n, 'onboarding' AS ty,
         'IT setup for anyone joining an office or hybrid role.' AS d
  UNION ALL SELECT 'Plant Floor Onboarding', 'onboarding',
         'IT setup for production and warehouse staff who share floor terminals.'
  UNION ALL SELECT 'Employee Offboarding', 'offboarding',
         'What IT does when someone leaves the company.'
) x WHERE NOT EXISTS (SELECT 1 FROM workflow_templates w WHERE w.name = x.n);

INSERT INTO workflow_template_tasks (workflow_template_id, title, instructions, category, default_owner, required, sort_order)
SELECT w.workflow_template_id, t.title, t.ins, t.cat, t.own, t.req, t.so
FROM (
  SELECT 'New Employee Onboarding' AS tpl, 'Create the network account and mailbox' AS title,
         'Use first.last as the sign-in name and add the person to their department group.' AS ins, 'Identity' AS cat, 'IT' AS own, 1 AS req, 0 AS so
  UNION ALL SELECT 'New Employee Onboarding', 'Assign a laptop or desktop', 'Link the asset to the person on their record.', 'Equipment', 'IT', 1, 1
  UNION ALL SELECT 'New Employee Onboarding', 'Enrol in multi-factor authentication', 'Send the enrolment link and confirm the first sign-in works.', 'Identity', 'IT', 1, 2
  UNION ALL SELECT 'New Employee Onboarding', 'Grant shared-drive and application access', 'Match the access list for the person''s job title.', 'Access', 'IT', 1, 3
  UNION ALL SELECT 'New Employee Onboarding', 'Assign Cybersecurity Awareness training', 'Due within 30 days of the start date.', 'Training', 'HR', 1, 4
  UNION ALL SELECT 'New Employee Onboarding', 'Order a desk phone', 'Only if the role needs one.', 'Equipment', 'IT', 0, 5
  UNION ALL SELECT 'New Employee Onboarding', 'Welcome call with the manager', 'Optional: 15 minutes on day one to check everything works.', 'Orientation', 'Manager', 0, 6
  UNION ALL SELECT 'Plant Floor Onboarding', 'Create the shared floor login', 'Floor terminals use a shared account plus a personal PIN.', 'Identity', 'IT', 1, 0
  UNION ALL SELECT 'Plant Floor Onboarding', 'Set up a handheld scanner', 'Pair the scanner and test a scan.', 'Equipment', 'IT', 1, 1
  UNION ALL SELECT 'Plant Floor Onboarding', 'Safety orientation sign-off', 'The supervisor confirms the person completed the plant safety walk-through.', 'Orientation', 'Manager', 1, 2
  UNION ALL SELECT 'Employee Offboarding', 'Disable accounts and revoke access', 'Do this on the last working day, or immediately for an involuntary exit.', 'Identity', 'IT', 1, 0
  UNION ALL SELECT 'Employee Offboarding', 'Collect the laptop and accessories', 'Record the return on the asset.', 'Equipment', 'IT', 1, 1
  UNION ALL SELECT 'Employee Offboarding', 'Move the mailbox and files to the manager', 'Keep them for 90 days.', 'Data', 'IT', 1, 2
  UNION ALL SELECT 'Employee Offboarding', 'Remove from groups and distribution lists', NULL, 'Access', 'IT', 1, 3
  UNION ALL SELECT 'Employee Offboarding', 'Archive the person in RivetIT', 'Archiving also revokes portal access.', 'Records', 'IT', 1, 4
  UNION ALL SELECT 'Employee Offboarding', 'Return badge and keys', 'HR collects these.', 'Facilities', 'HR', 0, 5
) t
JOIN workflow_templates w ON w.name = t.tpl
WHERE NOT EXISTS (SELECT 1 FROM workflow_template_tasks x WHERE x.workflow_template_id = w.workflow_template_id AND x.title = t.title);

-- ---- Two workflow runs: one in progress (Nadia), one finished with an exception (Dale) ------
INSERT INTO workflow_runs (workflow_template_id, contact_id, type, status, started_by, started_at, completed_at)
SELECT w.workflow_template_id, c.contact_id, w.type, r.st,
       (SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'),
       NOW() - INTERVAL r.started DAY,
       CASE WHEN r.st = 'in_progress' THEN NULL ELSE NOW() - INTERVAL r.finished DAY END
FROM (
  SELECT 'New Employee Onboarding' AS tpl, 'nadia.kowalski@summitridge.example' AS e, 'in_progress' AS st, 3 AS started, 0 AS finished
  UNION ALL SELECT 'Employee Offboarding', 'dale.whitaker@summitridge.example', 'completed_with_exceptions', 22, 20
) r
JOIN workflow_templates w ON w.name = r.tpl
JOIN contacts c ON c.contact_email = r.e
WHERE NOT EXISTS (SELECT 1 FROM workflow_runs x WHERE x.contact_id = c.contact_id AND x.workflow_template_id = w.workflow_template_id);

-- Snapshot the template tasks onto each run (the app copies them when a run starts), then mark progress.
INSERT INTO workflow_run_tasks (run_id, title, instructions, category, default_owner, required, sort_order)
SELECT r.run_id, t.title, t.instructions, t.category, t.default_owner, t.required, t.sort_order
FROM workflow_runs r
JOIN workflow_template_tasks t ON t.workflow_template_id = r.workflow_template_id
JOIN contacts c ON c.contact_id = r.contact_id
WHERE c.contact_email IN ('nadia.kowalski@summitridge.example', 'dale.whitaker@summitridge.example')
  AND NOT EXISTS (SELECT 1 FROM workflow_run_tasks x WHERE x.run_id = r.run_id);

-- Nadia: the first two required tasks are done, the optional desk-phone task was skipped.
UPDATE workflow_run_tasks rt
JOIN workflow_runs r ON r.run_id = rt.run_id
JOIN contacts c ON c.contact_id = r.contact_id AND c.contact_email = 'nadia.kowalski@summitridge.example'
SET rt.status = 'completed', rt.completed_at = NOW() - INTERVAL 2 DAY,
    rt.completed_by = (SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example')
WHERE rt.title IN ('Create the network account and mailbox', 'Assign a laptop or desktop') AND rt.status = 'pending';

UPDATE workflow_run_tasks rt
JOIN workflow_runs r ON r.run_id = rt.run_id
JOIN contacts c ON c.contact_id = r.contact_id AND c.contact_email = 'nadia.kowalski@summitridge.example'
SET rt.status = 'skipped', rt.completed_at = NOW() - INTERVAL 1 DAY, rt.skip_reason = 'Plant floor staff use the shared line',
    rt.completed_by = (SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example')
WHERE rt.title = 'Order a desk phone' AND rt.status = 'pending';

-- Dale: every required task done, the optional badge task skipped.
UPDATE workflow_run_tasks rt
JOIN workflow_runs r ON r.run_id = rt.run_id
JOIN contacts c ON c.contact_id = r.contact_id AND c.contact_email = 'dale.whitaker@summitridge.example'
SET rt.status = 'completed', rt.completed_at = NOW() - INTERVAL 21 DAY,
    rt.completed_by = (SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example')
WHERE rt.required = 1 AND rt.status = 'pending';

UPDATE workflow_run_tasks rt
JOIN workflow_runs r ON r.run_id = rt.run_id
JOIN contacts c ON c.contact_id = r.contact_id AND c.contact_email = 'dale.whitaker@summitridge.example'
SET rt.status = 'skipped', rt.completed_at = NOW() - INTERVAL 20 DAY, rt.skip_reason = 'Badge was already returned to HR',
    rt.completed_by = (SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example')
WHERE rt.title = 'Return badge and keys' AND rt.status = 'pending';

-- ---- History lines for the new people (the History card on a person's page reads the log) ----
INSERT INTO logs (log_type, log_action, log_description, log_client_id, log_user_id, log_entity_id, log_created_at)
SELECT 'Contact', l.act, CONCAT(l.who, ' ', l.what), c.contact_client_id,
       (SELECT user_id FROM users WHERE user_name = l.who), c.contact_id, NOW() - INTERVAL l.ago HOUR
FROM (
  SELECT 'nadia.kowalski@summitridge.example' AS e, 'Create' AS act, 'Alex Morgan' AS who, 'created contact Nadia Kowalski' AS what, 96 AS ago
  UNION ALL SELECT 'nadia.kowalski@summitridge.example', 'Edit', 'Alex Morgan', 'started workflow "New Employee Onboarding" for Nadia Kowalski', 72
  UNION ALL SELECT 'nadia.kowalski@summitridge.example', 'Edit', 'Priya Nair', 'completed a workflow task for Nadia Kowalski', 48
  UNION ALL SELECT 'dale.whitaker@summitridge.example', 'Edit', 'Alex Morgan', 'started workflow "Employee Offboarding" for Dale Whitaker', 528
  UNION ALL SELECT 'dale.whitaker@summitridge.example', 'Archive', 'Marcus Lee', 'archived contact Dale Whitaker', 480
) l
JOIN contacts c ON c.contact_email = l.e
WHERE NOT EXISTS (SELECT 1 FROM logs x WHERE x.log_type = 'Contact' AND x.log_entity_id = c.contact_id
                  AND x.log_description = CONCAT(l.who, ' ', l.what));

-- ---- One department Onboarding Template (project template + companion checklist) ----------
INSERT INTO project_templates (project_template_name, project_template_description, project_template_is_onboarding)
SELECT 'New Department Onboarding', 'Standard rollout when a team starts using IT support for the first time.', 1
WHERE NOT EXISTS (SELECT 1 FROM project_templates p WHERE p.project_template_name = 'New Department Onboarding' AND p.project_template_is_onboarding = 1);

INSERT INTO ticket_templates (ticket_template_name, ticket_template_subject, ticket_template_description)
SELECT 'New Department Onboarding Checklist', 'New Department Onboarding', 'Standard rollout when a team starts using IT support for the first time.'
WHERE NOT EXISTS (SELECT 1 FROM ticket_templates t WHERE t.ticket_template_name = 'New Department Onboarding Checklist');

INSERT INTO project_template_ticket_templates (project_template_id, ticket_template_id, ticket_template_order)
SELECT p.project_template_id, t.ticket_template_id, 0
FROM project_templates p, ticket_templates t
WHERE p.project_template_name = 'New Department Onboarding' AND p.project_template_is_onboarding = 1
  AND t.ticket_template_name = 'New Department Onboarding Checklist'
  AND NOT EXISTS (SELECT 1 FROM project_template_ticket_templates m WHERE m.project_template_id = p.project_template_id AND m.ticket_template_id = t.ticket_template_id);

INSERT INTO task_templates (task_template_name, task_template_order, task_template_ticket_template_id)
SELECT k.n, k.o, t.ticket_template_id
FROM (
  SELECT 'Create the department and set its primary contact' AS n, 0 AS o
  UNION ALL SELECT 'Import the department''s people from the HR list', 1
  UNION ALL SELECT 'Link the department to its locations', 2
  UNION ALL SELECT 'Record the department''s computers and printers', 3
  UNION ALL SELECT 'Add the department portal contacts', 4
  UNION ALL SELECT 'Walk the department head through raising a ticket', 5
) k
JOIN ticket_templates t ON t.ticket_template_name = 'New Department Onboarding Checklist'
WHERE NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = t.ticket_template_id AND x.task_template_name = k.n);
