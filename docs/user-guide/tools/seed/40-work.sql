-- Demo data for the "Work" guide page (Projects and Calendar) - Summit Ridge Manufacturing.
-- Adds: a project template (Device Refresh) with four ticket templates and their task templates;
-- five projects (three open, one closed, one archived) with milestones, tasks and linked tickets;
-- a second calendar and about twenty calendar events for the current month.
--
-- Everything here is fictional. Parents are looked up by NAME, never by numeric id, and every
-- insert is guarded with WHERE NOT EXISTS on a natural key, so re-running this file adds nothing.
-- Project and ticket numbers are taken from the same atomic counters the app uses
-- (settings.config_project_next_number / config_ticket_next_number).
--
-- Depends only on 00-core (users, departments, employees).

SET @m1 = CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE);            -- first day of this month
SET @mon1 = @m1 + INTERVAL ((9 - DAYOFWEEK(@m1)) % 7) DAY;              -- first Monday of this month
SET @tue2 = @m1 + INTERVAL ((10 - DAYOFWEEK(@m1)) % 7) DAY + INTERVAL 7 DAY;  -- second Tuesday ("Patch Tuesday")

-- ---- 1. Project template "Device Refresh" (ticket templates + task templates) -----------------
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT 'Device Refresh 1 - Audit and order', 'Part of the Device Refresh project template.', 'Audit current devices and order replacements', '<p>List every laptop or desktop in the department that is due for replacement, confirm the budget with the department head and place the order.</p><ul><li>Record make, model, serial number and age of each device</li><li>Get written approval before ordering</li></ul>' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 1 - Audit and order');
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT 'Device Refresh 2 - Image and stage', 'Part of the Device Refresh project template.', 'Image and stage replacement devices', '<p>Build each new device from the standard image, install the department software and label it for hand-over.</p>' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 2 - Image and stage');
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT 'Device Refresh 3 - Deploy to users', 'Part of the Device Refresh project template.', 'Deploy new devices to users', '<p>Swap each person''s old device for the new one, move their files and check that everything they use still works.</p>' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 3 - Deploy to users');
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT 'Device Refresh 4 - Retire old devices', 'Part of the Device Refresh project template.', 'Retire and recycle old devices', '<p>Collect the replaced devices, wipe them, update the asset records and send them for recycling.</p>' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 4 - Retire old devices');

INSERT INTO project_templates (project_template_name, project_template_description)
SELECT 'Device Refresh', 'Replace a department''s laptops or desktops: audit and order, image, deploy, retire the old ones.' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM project_templates WHERE project_template_name = 'Device Refresh');

INSERT INTO project_template_ticket_templates (project_template_id, ticket_template_id, ticket_template_order)
SELECT (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh'),
       (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 1 - Audit and order'), 1 FROM DUAL
WHERE (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh') IS NOT NULL
  AND (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 1 - Audit and order') IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_template_ticket_templates pt
                  WHERE pt.project_template_id = (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh')
                    AND pt.ticket_template_id = (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 1 - Audit and order'));
INSERT INTO project_template_ticket_templates (project_template_id, ticket_template_id, ticket_template_order)
SELECT (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh'),
       (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 2 - Image and stage'), 2 FROM DUAL
WHERE (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh') IS NOT NULL
  AND (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 2 - Image and stage') IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_template_ticket_templates pt
                  WHERE pt.project_template_id = (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh')
                    AND pt.ticket_template_id = (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 2 - Image and stage'));
INSERT INTO project_template_ticket_templates (project_template_id, ticket_template_id, ticket_template_order)
SELECT (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh'),
       (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 3 - Deploy to users'), 3 FROM DUAL
WHERE (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh') IS NOT NULL
  AND (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 3 - Deploy to users') IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_template_ticket_templates pt
                  WHERE pt.project_template_id = (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh')
                    AND pt.ticket_template_id = (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 3 - Deploy to users'));
INSERT INTO project_template_ticket_templates (project_template_id, ticket_template_id, ticket_template_order)
SELECT (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh'),
       (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 4 - Retire old devices'), 4 FROM DUAL
WHERE (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh') IS NOT NULL
  AND (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 4 - Retire old devices') IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_template_ticket_templates pt
                  WHERE pt.project_template_id = (SELECT project_template_id FROM project_templates WHERE project_template_name = 'Device Refresh')
                    AND pt.ticket_template_id = (SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 4 - Retire old devices'));

INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'List devices due for replacement', 0, 30, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 1 - Audit and order'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'List devices due for replacement');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Confirm budget with the department head', 0, 60, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 1 - Audit and order'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Confirm budget with the department head');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Order replacement laptops', 0, 90, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 1 - Audit and order'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Order replacement laptops');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Record the delivery date', 0, 120, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 1 - Audit and order'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Record the delivery date');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Build the standard laptop image', 0, 30, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 2 - Image and stage'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Build the standard laptop image');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Install department software', 0, 60, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 2 - Image and stage'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Install department software');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Enrol devices in device management', 0, 90, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 2 - Image and stage'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Enrol devices in device management');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Label and stage the devices', 0, 120, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 2 - Image and stage'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Label and stage the devices');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Book hand-over slots with users', 0, 30, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 3 - Deploy to users'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Book hand-over slots with users');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Swap devices and migrate data', 0, 60, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 3 - Deploy to users'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Swap devices and migrate data');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Check email, printers and shared drives', 0, 90, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 3 - Deploy to users'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Check email, printers and shared drives');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Collect signed hand-over sheets', 0, 120, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 3 - Deploy to users'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Collect signed hand-over sheets');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Collect the old laptops', 0, 30, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 4 - Retire old devices'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Collect the old laptops');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Wipe drives and keep the certificates', 0, 60, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 4 - Retire old devices'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Wipe drives and keep the certificates');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Update asset records', 0, 90, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 4 - Retire old devices'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Update asset records');
INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT 'Send devices for recycling', 0, 120, tt.ticket_template_id FROM ticket_templates tt
WHERE tt.ticket_template_name = 'Device Refresh 4 - Retire old devices'
  AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.task_template_ticket_template_id = tt.ticket_template_id AND x.task_template_name = 'Send devices for recycling');

-- ---- 2. Projects (numbers come from the app counter) ----------------------------------------
UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number) + 1
 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade');
INSERT INTO projects (project_prefix, project_number, project_name, project_description, project_due, project_manager,
                      project_created_at, project_completed_at, project_archived_at, project_client_id, project_start, project_estimated_hours)
SELECT (SELECT config_project_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Plant Floor Wi-Fi Upgrade', 'Replace ageing access points on the plant floor and extend coverage to the loading dock.',
       CURDATE() + INTERVAL 16 DAY, COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), NOW() - INTERVAL 26 DAY, NULL, NULL,
       COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), CURDATE() - INTERVAL 26 DAY, 120
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade');

UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number) + 1
 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Sales Team Laptop Refresh');
INSERT INTO projects (project_prefix, project_number, project_name, project_description, project_due, project_manager,
                      project_created_at, project_completed_at, project_archived_at, project_client_id, project_start, project_estimated_hours)
SELECT (SELECT config_project_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Sales Team Laptop Refresh', 'Replace the oldest Sales & Marketing laptops. Created from the Device Refresh template.',
       CURDATE() + INTERVAL 40 DAY, COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), NOW() - INTERVAL 16 DAY, NULL, NULL,
       COALESCE((SELECT client_id FROM clients WHERE client_name = 'Sales & Marketing'), 0), CURDATE() - INTERVAL 16 DAY, 60
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Sales Team Laptop Refresh');

UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number) + 1
 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'New Hire Onboarding Workflow');
INSERT INTO projects (project_prefix, project_number, project_name, project_description, project_due, project_manager,
                      project_created_at, project_completed_at, project_archived_at, project_client_id, project_start, project_estimated_hours)
SELECT (SELECT config_project_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'New Hire Onboarding Workflow', 'Standardise how IT prepares equipment and accounts for new starters.',
       CURDATE() + INTERVAL 14 DAY, COALESCE((SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'), 0), NOW() - INTERVAL 4 DAY, NULL, NULL,
       COALESCE((SELECT client_id FROM clients WHERE client_name = 'Human Resources'), 0), CURDATE() - INTERVAL 4 DAY, 24
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'New Hire Onboarding Workflow');

UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number) + 1
 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Finance Laptop Disk Encryption');
INSERT INTO projects (project_prefix, project_number, project_name, project_description, project_due, project_manager,
                      project_created_at, project_completed_at, project_archived_at, project_client_id, project_start, project_estimated_hours)
SELECT (SELECT config_project_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Finance Laptop Disk Encryption', 'Turn on full-disk encryption on every Finance laptop and escrow the recovery keys.',
       CURDATE() - INTERVAL 30 DAY, COALESCE((SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'), 0), NOW() - INTERVAL 75 DAY, NOW() - INTERVAL 28 DAY, NULL,
       COALESCE((SELECT client_id FROM clients WHERE client_name = 'Finance & Accounting'), 0), CURDATE() - INTERVAL 75 DAY, 40
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Finance Laptop Disk Encryption');

UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number) + 1
 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Company-Wide Password Manager Rollout');
INSERT INTO projects (project_prefix, project_number, project_name, project_description, project_due, project_manager,
                      project_created_at, project_completed_at, project_archived_at, project_client_id, project_start, project_estimated_hours)
SELECT (SELECT config_project_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Company-Wide Password Manager Rollout', 'Introduce a shared password manager to every department. No single department owns this project.',
       CURDATE() - INTERVAL 100 DAY, COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), NOW() - INTERVAL 140 DAY, NOW() - INTERVAL 110 DAY, NOW() - INTERVAL 50 DAY,
       0, CURDATE() - INTERVAL 140 DAY, 80
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_name = 'Company-Wide Password Manager Rollout');

-- ---- 3. Milestones -----------------------------------------------------------------------------
INSERT INTO project_milestones (milestone_project_id, milestone_name, milestone_description, milestone_due, milestone_order, milestone_status, milestone_completed_at)
SELECT p.project_id, x.name, x.descr, x.due, x.ord, x.st, x.done
FROM (
  SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Site survey and design' AS name, 'Find the dead spots, pick the hardware and get the plan approved.' AS descr, CURDATE() - INTERVAL 13 DAY AS due, 1 AS ord, 'completed' AS st, NOW() - INTERVAL 13 DAY AS done
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Install access points' AS name, 'Cable, mount and configure the new access points.' AS descr, CURDATE() + INTERVAL 6 DAY AS due, 2 AS ord, 'open' AS st, NULL AS done
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Test coverage and hand over' AS name, 'Prove roaming works for the handheld scanners, then document it.' AS descr, CURDATE() + INTERVAL 12 DAY AS due, 3 AS ord, 'open' AS st, NULL AS done
) x
JOIN projects p ON p.project_name = x.pname
WHERE NOT EXISTS (SELECT 1 FROM project_milestones e WHERE e.milestone_project_id = p.project_id AND e.milestone_name = x.name);

-- ---- 4. Project tasks ---------------------------------------------------------------------------
INSERT INTO tasks (task_name, task_status, task_order, task_completion_estimate, task_completed_at, task_completed_by, task_created_at,
                   task_project_id, task_milestone_id, task_assigned_to, task_start, task_due, task_progress)
SELECT x.name, x.st, x.ord, x.est,
       IF(x.done IS NULL, NULL, NOW() - INTERVAL x.done DAY),
       IF(x.done IS NULL, NULL, u.user_id),
       p.project_created_at + INTERVAL x.ord MINUTE,
       p.project_id, m.milestone_id, u.user_id, x.start, x.due,
       IF(x.done IS NULL, x.prog, 100)
FROM (
  SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Site survey and design' AS mname, 'Walk the plant floor and mark dead spots' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() - INTERVAL 26 DAY AS start, CURDATE() - INTERVAL 21 DAY AS due, 240 AS est, 0 AS prog, NULL AS st, 21 AS done, 1 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Site survey and design' AS mname, 'Choose access point model and controller' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() - INTERVAL 20 DAY AS start, CURDATE() - INTERVAL 16 DAY AS due, 180 AS est, 0 AS prog, NULL AS st, 16 AS done, 2 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Site survey and design' AS mname, 'Get the quote approved by the Plant Manager' AS name, 'alex.morgan@summitridge.example' AS who, CURDATE() - INTERVAL 16 DAY AS start, CURDATE() - INTERVAL 13 DAY AS due, 60 AS est, 0 AS prog, NULL AS st, 13 AS done, 3 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Install access points' AS mname, 'Run cabling to the new mounting points' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 10 DAY AS start, CURDATE() - INTERVAL 3 DAY AS due, 480 AS est, 60 AS prog, 'In Progress' AS st, NULL AS done, 4 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Install access points' AS mname, 'Activate the controller licence' AS name, 'alex.morgan@summitridge.example' AS who, CURDATE() - INTERVAL 5 DAY AS start, CURDATE() - INTERVAL 1 DAY AS due, 30 AS est, 0 AS prog, 'Blocked' AS st, NULL AS done, 5 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Install access points' AS mname, 'Mount and power the access points' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() - INTERVAL 2 DAY AS start, CURDATE() + INTERVAL 4 DAY AS due, 360 AS est, 25 AS prog, 'In Progress' AS st, NULL AS done, 6 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Install access points' AS mname, 'Configure SSID and VLAN for scanners' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() + INTERVAL 3 DAY AS start, CURDATE() + INTERVAL 6 DAY AS due, 120 AS est, 0 AS prog, NULL AS st, NULL AS done, 7 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Test coverage and hand over' AS mname, 'Test roaming with the handheld scanners' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() + INTERVAL 7 DAY AS start, CURDATE() + INTERVAL 10 DAY AS due, 180 AS est, 0 AS prog, NULL AS st, NULL AS done, 8 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, 'Test coverage and hand over' AS mname, 'Publish the coverage map to the knowledge base' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() + INTERVAL 9 DAY AS start, CURDATE() + INTERVAL 12 DAY AS due, 90 AS est, 0 AS prog, NULL AS st, NULL AS done, 9 AS ord
  UNION ALL SELECT 'Plant Floor Wi-Fi Upgrade' AS pname, NULL AS mname, 'Update the plant network diagram' AS name, 'marcus.lee@summitridge.example' AS who, NULL AS start, CURDATE() + INTERVAL 12 DAY AS due, 60 AS est, 0 AS prog, NULL AS st, NULL AS done, 10 AS ord
  UNION ALL SELECT 'New Hire Onboarding Workflow' AS pname, NULL AS mname, 'Interview HR about the current onboarding steps' AS name, 'alex.morgan@summitridge.example' AS who, CURDATE() - INTERVAL 4 DAY AS start, CURDATE() - INTERVAL 2 DAY AS due, 60 AS est, 0 AS prog, NULL AS st, 2 AS done, 11 AS ord
  UNION ALL SELECT 'New Hire Onboarding Workflow' AS pname, NULL AS mname, 'Draft standard equipment bundles per role' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 2 DAY AS start, CURDATE() + INTERVAL 3 DAY AS due, 120 AS est, 40 AS prog, 'In Progress' AS st, NULL AS done, 12 AS ord
  UNION ALL SELECT 'New Hire Onboarding Workflow' AS pname, NULL AS mname, 'Agree the accounts checklist with department heads' AS name, 'alex.morgan@summitridge.example' AS who, CURDATE() - INTERVAL 3 DAY AS start, CURDATE() - INTERVAL 1 DAY AS due, 90 AS est, 0 AS prog, NULL AS st, NULL AS done, 13 AS ord
  UNION ALL SELECT 'New Hire Onboarding Workflow' AS pname, NULL AS mname, 'Build the New Hire IT Setup ticket template' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() + INTERVAL 0 DAY AS start, CURDATE() + INTERVAL 5 DAY AS due, 120 AS est, 0 AS prog, NULL AS st, NULL AS done, 14 AS ord
  UNION ALL SELECT 'New Hire Onboarding Workflow' AS pname, NULL AS mname, 'Pilot the workflow with the next new hire' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() + INTERVAL 10 DAY AS start, CURDATE() + INTERVAL 12 DAY AS due, 60 AS est, 0 AS prog, NULL AS st, NULL AS done, 15 AS ord
  UNION ALL SELECT 'Finance Laptop Disk Encryption' AS pname, NULL AS mname, 'Inventory Finance laptops and check encryption support' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 75 DAY AS start, CURDATE() - INTERVAL 68 DAY AS due, 120 AS est, 0 AS prog, NULL AS st, 68 AS done, 16 AS ord
  UNION ALL SELECT 'Finance Laptop Disk Encryption' AS pname, NULL AS mname, 'Encrypt wave 1 laptops' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 66 DAY AS start, CURDATE() - INTERVAL 55 DAY AS due, 300 AS est, 0 AS prog, NULL AS st, 56 AS done, 17 AS ord
  UNION ALL SELECT 'Finance Laptop Disk Encryption' AS pname, NULL AS mname, 'Encrypt wave 2 laptops' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 54 DAY AS start, CURDATE() - INTERVAL 40 DAY AS due, 240 AS est, 0 AS prog, NULL AS st, 41 AS done, 18 AS ord
  UNION ALL SELECT 'Finance Laptop Disk Encryption' AS pname, NULL AS mname, 'Escrow recovery keys and test the unlock procedure' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() - INTERVAL 38 DAY AS start, CURDATE() - INTERVAL 31 DAY AS due, 90 AS est, 0 AS prog, NULL AS st, 30 AS done, 19 AS ord
  UNION ALL SELECT 'Company-Wide Password Manager Rollout' AS pname, NULL AS mname, 'Pick the password manager and agree the plan' AS name, 'alex.morgan@summitridge.example' AS who, CURDATE() - INTERVAL 140 DAY AS start, CURDATE() - INTERVAL 130 DAY AS due, 120 AS est, 0 AS prog, NULL AS st, 131 AS done, 20 AS ord
  UNION ALL SELECT 'Company-Wide Password Manager Rollout' AS pname, NULL AS mname, 'Deploy the browser extension and desktop app' AS name, 'marcus.lee@summitridge.example' AS who, CURDATE() - INTERVAL 128 DAY AS start, CURDATE() - INTERVAL 112 DAY AS due, 480 AS est, 0 AS prog, NULL AS st, 113 AS done, 21 AS ord
  UNION ALL SELECT 'Company-Wide Password Manager Rollout' AS pname, NULL AS mname, 'Run a short training session for each department' AS name, 'priya.nair@summitridge.example' AS who, CURDATE() - INTERVAL 120 DAY AS start, CURDATE() - INTERVAL 110 DAY AS due, 420 AS est, 0 AS prog, NULL AS st, 110 AS done, 22 AS ord
) x
JOIN projects p ON p.project_name = x.pname
LEFT JOIN project_milestones m ON m.milestone_project_id = p.project_id AND m.milestone_name = x.mname
LEFT JOIN users u ON u.user_email = x.who
WHERE NOT EXISTS (SELECT 1 FROM tasks t WHERE t.task_project_id = p.project_id AND t.task_name = x.name);

-- ---- 5. Tickets linked to the projects (numbers come from the app counter) -----------------------
UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Wi-Fi drops on packing line 2' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Wi-Fi drops on packing line 2', '<p>Handheld scanners and the label printer on packing line 2 lose their Wi-Fi connection several times an hour. It started after the last plant shutdown.</p>', 'High', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'aisha.rahman@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade'), 0),
       NOW() - INTERVAL 21 DAY, NOW() - INTERVAL 2 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Wi-Fi drops on packing line 2' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Handheld scanners lose connection near dock door 3' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Handheld scanners lose connection near dock door 3', '<p>Quality inspection scanners lose the connection when we walk from the line to dock door 3, and we have to sign in again.</p>', 'Medium', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'emma.novak@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade'), 0),
       NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 3 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Handheld scanners lose connection near dock door 3' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Plant floor site survey and access point placement' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Plant floor site survey and access point placement', '<p>Survey the plant floor, map the signal strength and propose where the new access points should go.</p>', 'Medium', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'carlos.mendoza@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade'), 0),
       NOW() - INTERVAL 26 DAY, NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 17 DAY, NOW() - INTERVAL 16 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Plant floor site survey and access point placement' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Mount access points in zone B' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Mount access points in zone B', '<p>Zone B has a high ceiling. Book a lift and mount the six new access points on the cable runs installed last week.</p>', 'Medium', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'aisha.rahman@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Plant Floor Wi-Fi Upgrade'), 0),
       NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 5 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Mount access points in zone B' AND px.project_name = 'Plant Floor Wi-Fi Upgrade');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x WHERE x.ticket_subject = 'Guest Wi-Fi access for plant visitors' AND x.ticket_project_id = 0);
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Guest Wi-Fi access for plant visitors', '<p>Auditors visit the plant next month. Can they get guest Wi-Fi that only reaches the internet?</p>', 'Low', 1, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'), 0), 0, 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'carlos.mendoza@summitridge.example'), 0), 0,
       NOW() - INTERVAL 1 DAY, NULL, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x WHERE x.ticket_subject = 'Guest Wi-Fi access for plant visitors' AND x.ticket_project_id = 0);

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x WHERE x.ticket_subject = 'Break room access point restarts every night' AND x.ticket_project_id = 0);
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Break room access point restarts every night', '<p>The break room Wi-Fi disappears around 2 a.m. and comes back by itself.</p>', 'Medium', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Production'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'jake.sullivan@summitridge.example'), 0), 0,
       NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 3 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x WHERE x.ticket_subject = 'Break room access point restarts every night' AND x.ticket_project_id = 0);

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Audit current devices and order replacements' AND px.project_name = 'Sales Team Laptop Refresh');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), NULL, 'Audit current devices and order replacements', (SELECT ticket_template_details FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 1 - Audit and order'), 'Low', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Sales & Marketing'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'nina.rossi@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Sales Team Laptop Refresh'), 0),
       NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 1 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Audit current devices and order replacements' AND px.project_name = 'Sales Team Laptop Refresh');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Image and stage replacement devices' AND px.project_name = 'Sales Team Laptop Refresh');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), NULL, 'Image and stage replacement devices', (SELECT ticket_template_details FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 2 - Image and stage'), 'Low', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Sales & Marketing'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'nina.rossi@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Sales Team Laptop Refresh'), 0),
       NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 3 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Image and stage replacement devices' AND px.project_name = 'Sales Team Laptop Refresh');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Deploy new devices to users' AND px.project_name = 'Sales Team Laptop Refresh');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), NULL, 'Deploy new devices to users', (SELECT ticket_template_details FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 3 - Deploy to users'), 'Low', 2, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Sales & Marketing'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'nina.rossi@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Sales Team Laptop Refresh'), 0),
       NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 16 DAY, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Deploy new devices to users' AND px.project_name = 'Sales Team Laptop Refresh');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Retire and recycle old devices' AND px.project_name = 'Sales Team Laptop Refresh');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), NULL, 'Retire and recycle old devices', (SELECT ticket_template_details FROM ticket_templates WHERE ticket_template_name = 'Device Refresh 4 - Retire old devices'), 'Low', 1, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'), 0), 0, 0, COALESCE((SELECT client_id FROM clients WHERE client_name = 'Sales & Marketing'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'nina.rossi@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Sales Team Laptop Refresh'), 0),
       NOW() - INTERVAL 16 DAY, NULL, NULL, NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Retire and recycle old devices' AND px.project_name = 'Sales Team Laptop Refresh');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Encrypt Finance laptops - wave 1' AND px.project_name = 'Finance Laptop Disk Encryption');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Encrypt Finance laptops - wave 1', '<p>Turn on full-disk encryption for the first group of Finance laptops (Grace, Tom and Lena).</p>', 'Medium', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Finance & Accounting'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'grace.okafor@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Finance Laptop Disk Encryption'), 0),
       NOW() - INTERVAL 70 DAY, NOW() - INTERVAL 55 DAY, NOW() - INTERVAL 56 DAY, NOW() - INTERVAL 55 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Encrypt Finance laptops - wave 1' AND px.project_name = 'Finance Laptop Disk Encryption');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Encrypt Finance laptops - wave 2' AND px.project_name = 'Finance Laptop Disk Encryption');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Encrypt Finance laptops - wave 2', '<p>Second group of Finance laptops, including the two shared travel laptops.</p>', 'Medium', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Finance & Accounting'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'grace.okafor@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Finance Laptop Disk Encryption'), 0),
       NOW() - INTERVAL 60 DAY, NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 41 DAY, NOW() - INTERVAL 40 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Encrypt Finance laptops - wave 2' AND px.project_name = 'Finance Laptop Disk Encryption');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Store recovery keys and test the unlock procedure' AND px.project_name = 'Finance Laptop Disk Encryption');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Store recovery keys and test the unlock procedure', '<p>Escrow every recovery key in the credential vault and prove a locked laptop can be unlocked.</p>', 'Low', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Finance & Accounting'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'grace.okafor@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Finance Laptop Disk Encryption'), 0),
       NOW() - INTERVAL 50 DAY, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 31 DAY, NOW() - INTERVAL 30 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Store recovery keys and test the unlock procedure' AND px.project_name = 'Finance Laptop Disk Encryption');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Set up password manager for the Executive Office' AND px.project_name = 'Company-Wide Password Manager Rollout');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Set up password manager for the Executive Office', '<p>Install the password manager for the executive team and import their saved logins.</p>', 'Medium', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Executive Office'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'helen.brandt@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Company-Wide Password Manager Rollout'), 0),
       NOW() - INTERVAL 125 DAY, NOW() - INTERVAL 112 DAY, NOW() - INTERVAL 113 DAY, NOW() - INTERVAL 112 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Set up password manager for the Executive Office' AND px.project_name = 'Company-Wide Password Manager Rollout');

UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number) + 1 WHERE company_id = 1 AND NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Password manager training for Human Resources' AND px.project_name = 'Company-Wide Password Manager Rollout');
INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_url_key,
                     ticket_created_by, ticket_assigned_to, ticket_closed_by, ticket_client_id, ticket_contact_id, ticket_project_id,
                     ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1), LAST_INSERT_ID(), 'Agent', 'Password manager training for Human Resources', '<p>Half-hour hands-on session for the HR team.</p>', 'Low', 5, SUBSTRING(MD5(RAND()), 1, 32),
       COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'), 0), COALESCE((SELECT client_id FROM clients WHERE client_name = 'Human Resources'), 0), COALESCE((SELECT contact_id FROM contacts WHERE contact_email = 'miguel.alvarez@summitridge.example'), 0), COALESCE((SELECT project_id FROM projects WHERE project_name = 'Company-Wide Password Manager Rollout'), 0),
       NOW() - INTERVAL 118 DAY, NOW() - INTERVAL 110 DAY, NOW() - INTERVAL 111 DAY, NOW() - INTERVAL 110 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tickets x JOIN projects px ON px.project_id = x.ticket_project_id WHERE x.ticket_subject = 'Password manager training for Human Resources' AND px.project_name = 'Company-Wide Password Manager Rollout');

-- ---- 6. Ticket tasks copied from the task templates (as the app does when a project is created from a template) ----
INSERT INTO tasks (task_name, task_order, task_ticket_id, task_created_at, task_completed_at, task_completed_by, task_progress)
SELECT tk.task_template_name, tk.task_template_order, t.ticket_id, t.ticket_created_at + INTERVAL tk.seq SECOND,
       IF(tk.done IS NULL, NULL, NOW() - INTERVAL tk.done DAY), IF(tk.done IS NULL, NULL, t.ticket_assigned_to), IF(tk.done IS NULL, 0, 100)
FROM (
  SELECT tt.ticket_template_name AS tname, tsk.task_template_name, tsk.task_template_order, tsk.task_template_id AS seq,
         CASE tsk.task_template_name
           WHEN 'List devices due for replacement' THEN 13
           WHEN 'Confirm budget with the department head' THEN 11
           WHEN 'Build the standard laptop image' THEN 3
           ELSE NULL END AS done
  FROM task_templates tsk JOIN ticket_templates tt ON tt.ticket_template_id = tsk.task_template_ticket_template_id
  WHERE tt.ticket_template_name LIKE 'Device Refresh %'
) tk
JOIN tickets t ON t.ticket_subject = (SELECT ticket_template_subject FROM ticket_templates WHERE ticket_template_name = tk.tname)
JOIN projects p ON p.project_id = t.ticket_project_id AND p.project_name = 'Sales Team Laptop Refresh'
WHERE NOT EXISTS (SELECT 1 FROM tasks x WHERE x.task_ticket_id = t.ticket_id AND x.task_name = tk.task_template_name);

-- ---- 7. Time worked on project tickets ------------------------------------------------------------
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT x.txt, 'Internal', x.tw, NOW() - INTERVAL x.ago DAY, u.user_id, t.ticket_id
FROM (
  SELECT 'Wi-Fi drops on packing line 2' AS subj, 'marcus.lee@summitridge.example' AS who, '01:15:00' AS tw, 20 AS ago, '<p>Checked the controller logs. The access point above line 2 drops its clients every few minutes and is past end of life. Adding it to the plant Wi-Fi project.</p>' AS txt
  UNION ALL SELECT 'Handheld scanners lose connection near dock door 3' AS subj, 'priya.nair@summitridge.example' AS who, '00:45:00' AS tw, 14 AS ago, '<p>Walked the route with a scanner. Signal falls off just before dock door 3. Needs an extra access point there.</p>' AS txt
  UNION ALL SELECT 'Plant floor site survey and access point placement' AS subj, 'marcus.lee@summitridge.example' AS who, '03:30:00' AS tw, 24 AS ago, '<p>Surveyed the whole plant floor and mapped signal strength on the floor plan.</p>' AS txt
  UNION ALL SELECT 'Plant floor site survey and access point placement' AS subj, 'marcus.lee@summitridge.example' AS who, '02:00:00' AS tw, 18 AS ago, '<p>Placed 14 access points on the drawing. Plant Manager approved the layout.</p>' AS txt
  UNION ALL SELECT 'Mount access points in zone B' AS subj, 'priya.nair@summitridge.example' AS who, '00:30:00' AS tw, 5 AS ago, '<p>Booked the ceiling lift for the install day.</p>' AS txt
  UNION ALL SELECT 'Audit current devices and order replacements' AS subj, 'priya.nair@summitridge.example' AS who, '01:00:00' AS tw, 12 AS ago, '<p>Listed 9 laptops older than four years. Budget confirmed by Nina; order placed.</p>' AS txt
  UNION ALL SELECT 'Encrypt Finance laptops - wave 1' AS subj, 'priya.nair@summitridge.example' AS who, '04:00:00' AS tw, 57 AS ago, '<p>Encrypted the three laptops and saved the recovery keys.</p>' AS txt
  UNION ALL SELECT 'Encrypt Finance laptops - wave 2' AS subj, 'priya.nair@summitridge.example' AS who, '03:30:00' AS tw, 42 AS ago, '<p>Encrypted the remaining laptops and both travel laptops.</p>' AS txt
  UNION ALL SELECT 'Store recovery keys and test the unlock procedure' AS subj, 'marcus.lee@summitridge.example' AS who, '01:30:00' AS tw, 31 AS ago, '<p>Tested unlocking a laptop from the escrowed key. Works.</p>' AS txt
) x
JOIN tickets t ON t.ticket_subject = x.subj AND t.ticket_project_id > 0
JOIN users u ON u.user_email = x.who
WHERE NOT EXISTS (SELECT 1 FROM ticket_replies r WHERE r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply = x.txt);

-- ---- 8. Ticket appointments -----------------------------------------------------------------------
INSERT INTO ticket_schedules (schedule_ticket_id, schedule_start, schedule_end, schedule_onsite, schedule_tech_id, schedule_notes, schedule_created_by)
SELECT t.ticket_id, TIMESTAMP(CURDATE() + INTERVAL x.day DAY, x.f), TIMESTAMP(CURDATE() + INTERVAL x.day DAY, x.e), x.onsite, u.user_id, x.notes,
       (SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example')
FROM (
  SELECT 'Mount access points in zone B' AS subj, 'marcus.lee@summitridge.example' AS who, 1 AS day, '07:00:00' AS f, '11:00:00' AS e, 1 AS onsite, 'Install day with ceiling lift, zone B.' AS notes
  UNION ALL SELECT 'Handheld scanners lose connection near dock door 3' AS subj, 'priya.nair@summitridge.example' AS who, -2 AS day, '13:00:00' AS f, '14:00:00' AS e, 1 AS onsite, 'Walk the dock door 3 route with the scanner.' AS notes
  UNION ALL SELECT 'Deploy new devices to users' AS subj, 'priya.nair@summitridge.example' AS who, 4 AS day, '09:00:00' AS f, '12:00:00' AS e, 1 AS onsite, 'First hand-over slots for the Sales team.' AS notes
) x
JOIN tickets t ON t.ticket_subject = x.subj AND t.ticket_project_id > 0
JOIN users u ON u.user_email = x.who
WHERE NOT EXISTS (SELECT 1 FROM ticket_schedules s WHERE s.schedule_ticket_id = t.ticket_id AND s.schedule_notes = x.notes);

-- ---- 9. A second calendar ---------------------------------------------------------------------------
INSERT INTO calendars (calendar_name, calendar_color)
SELECT 'Maintenance Windows', '#f39c12' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM calendars WHERE calendar_name = 'Maintenance Windows');

-- ---- 10. Calendar events (about twenty across the current month; the Repeat field is disabled in the app, so weekly events are entered one by one) ----
INSERT INTO calendar_events (event_title, event_location, event_description, event_start, event_end, event_calendar_id, event_client_id)
SELECT x.title, x.loc, x.descr, x.s, x.e,
       COALESCE((SELECT calendar_id FROM calendars WHERE calendar_name = x.cal), (SELECT MIN(calendar_id) FROM calendars)),
       COALESCE((SELECT client_id FROM clients WHERE client_name = x.dept), 0)
FROM (
  SELECT 'IT Team Standup' AS title, 'Default' AS cal, 'IT office, Headquarters' AS loc, 'Fifteen minutes: open tickets, today''s site visits, anything blocked.' AS descr, TIMESTAMP((@mon1 + INTERVAL 0 DAY), '08:30:00') AS s, TIMESTAMP((@mon1 + INTERVAL 0 DAY), '08:45:00') AS e, NULL AS dept
  UNION ALL SELECT 'IT Team Standup' AS title, 'Default' AS cal, 'IT office, Headquarters' AS loc, 'Fifteen minutes: open tickets, today''s site visits, anything blocked.' AS descr, TIMESTAMP((@mon1 + INTERVAL 7 DAY), '08:30:00') AS s, TIMESTAMP((@mon1 + INTERVAL 7 DAY), '08:45:00') AS e, NULL AS dept
  UNION ALL SELECT 'IT Team Standup' AS title, 'Default' AS cal, 'IT office, Headquarters' AS loc, 'Fifteen minutes: open tickets, today''s site visits, anything blocked.' AS descr, TIMESTAMP((@mon1 + INTERVAL 14 DAY), '08:30:00') AS s, TIMESTAMP((@mon1 + INTERVAL 14 DAY), '08:45:00') AS e, NULL AS dept
  UNION ALL SELECT 'IT Team Standup' AS title, 'Default' AS cal, 'IT office, Headquarters' AS loc, 'Fifteen minutes: open tickets, today''s site visits, anything blocked.' AS descr, TIMESTAMP((@mon1 + INTERVAL 21 DAY), '08:30:00') AS s, TIMESTAMP((@mon1 + INTERVAL 21 DAY), '08:45:00') AS e, NULL AS dept
  UNION ALL SELECT 'New Hire IT Orientation' AS title, 'Default' AS cal, 'Training Room A, Headquarters' AS loc, 'Walk the new starters through logins, the password manager, printing and how to raise a ticket.' AS descr, TIMESTAMP((@m1 + INTERVAL 2 DAY), '13:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 2 DAY), '14:00:00') AS e, 'Human Resources' AS dept
  UNION ALL SELECT 'Quarterly Security Review' AS title, 'Default' AS cal, 'Boardroom, Headquarters' AS loc, 'Phishing test results, patch compliance and open risks. Bring the vulnerability summary.' AS descr, TIMESTAMP((@m1 + INTERVAL 8 DAY), '10:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 8 DAY), '11:30:00') AS e, 'Executive Office' AS dept
  UNION ALL SELECT 'Plant Wi-Fi Survey Walk-through' AS title, 'Default' AS cal, 'Plant 1 - Sun Prairie' AS loc, 'Walk the floor with the Plant Manager to confirm access point positions.' AS descr, TIMESTAMP((@m1 + INTERVAL 10 DAY), '07:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 10 DAY), '09:00:00') AS e, 'Production' AS dept
  UNION ALL SELECT 'Core Switch Support Renewal Call' AS title, 'Default' AS cal, 'Phone' AS loc, 'Call with the network vendor about the support renewal for the core switch.' AS descr, TIMESTAMP((@m1 + INTERVAL 14 DAY), '14:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 14 DAY), '14:45:00') AS e, NULL AS dept
  UNION ALL SELECT 'Budget Check-in with Finance' AS title, 'Default' AS cal, 'Finance office, Headquarters' AS loc, 'Review the IT hardware budget and next quarter''s refresh plan with Grace.' AS descr, TIMESTAMP((@m1 + INTERVAL 16 DAY), '11:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 16 DAY), '12:00:00') AS e, 'Finance & Accounting' AS dept
  UNION ALL SELECT 'Warehouse Scanner Pilot Kickoff' AS title, 'Default' AS cal, 'Distribution Center - Milwaukee' AS loc, 'Introduce the replacement handheld scanners to the shipping team.' AS descr, TIMESTAMP((@m1 + INTERVAL 21 DAY), '10:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 21 DAY), '11:00:00') AS e, 'Warehouse & Logistics' AS dept
  UNION ALL SELECT 'CAD Workstation Demo' AS title, 'Default' AS cal, 'Engineering lab, Headquarters' AS loc, 'Try the new CAD workstation build with two designers before ordering more.' AS descr, TIMESTAMP((@m1 + INTERVAL 23 DAY), '13:30:00') AS s, TIMESTAMP((@m1 + INTERVAL 23 DAY), '15:00:00') AS e, 'Engineering' AS dept
  UNION ALL SELECT 'Backup Restore Test' AS title, 'Default' AS cal, 'Server room, Headquarters' AS loc, 'Restore a sample of files from last night''s backup and record the result.' AS descr, TIMESTAMP((@m1 + INTERVAL 26 DAY), '10:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 26 DAY), '12:00:00') AS e, NULL AS dept
  UNION ALL SELECT 'Patch Tuesday - Windows Updates' AS title, 'Maintenance Windows' AS cal, 'All departments' AS loc, 'Servers reboot in sequence. Laptops install updates overnight. Post a notice a week ahead.' AS descr, TIMESTAMP((@tue2), '18:00:00') AS s, TIMESTAMP((@tue2), '21:00:00') AS e, NULL AS dept
  UNION ALL SELECT 'Firewall Firmware Upgrade' AS title, 'Maintenance Windows' AS cal, 'Server room, Headquarters' AS loc, 'Brief internet outage while the edge firewall reboots.' AS descr, TIMESTAMP((@m1 + INTERVAL 11 DAY), '22:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 11 DAY), '23:30:00') AS e, NULL AS dept
  UNION ALL SELECT 'File Server Storage Expansion' AS title, 'Maintenance Windows' AS cal, 'Server room, Headquarters' AS loc, 'Add disks to the file server. Shares stay online.' AS descr, TIMESTAMP((@m1 + INTERVAL 18 DAY), '19:00:00') AS s, TIMESTAMP((@m1 + INTERVAL 18 DAY), '21:00:00') AS e, NULL AS dept
  UNION ALL SELECT 'UPS Battery Replacement - Plant 1' AS title, 'Maintenance Windows' AS cal, 'Plant 1 - Sun Prairie, network closet' AS loc, 'Swap the UPS batteries before the first shift starts.' AS descr, TIMESTAMP((@m1 + INTERVAL 24 DAY), '05:30:00') AS s, TIMESTAMP((@m1 + INTERVAL 24 DAY), '07:00:00') AS e, 'Production' AS dept
  UNION ALL SELECT 'Plant Wi-Fi Vendor Check-in' AS title, 'Default' AS cal, 'Phone' AS loc, 'Confirm the controller licence date and the access point delivery.' AS descr, TIMESTAMP((CURDATE() + INTERVAL 0 DAY), '10:00:00') AS s, TIMESTAMP((CURDATE() + INTERVAL 0 DAY), '10:45:00') AS e, 'Production' AS dept
  UNION ALL SELECT 'Zone B Install Planning' AS title, 'Default' AS cal, 'Plant 1 - Sun Prairie' AS loc, 'Agree lift booking and safety rules with the shift supervisor.' AS descr, TIMESTAMP((CURDATE() + INTERVAL 1 DAY), '13:00:00') AS s, TIMESTAMP((CURDATE() + INTERVAL 1 DAY), '13:30:00') AS e, 'Production' AS dept
) x
WHERE NOT EXISTS (SELECT 1 FROM calendar_events c WHERE c.event_title = x.title AND DATE(c.event_start) = DATE(x.s));

