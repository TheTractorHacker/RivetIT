-- Demo data for the Administration configuration chapters of the user guide
-- (group "admin-config", pages 13-administration-settings.md and 13b-administration-ticketing-and-automation.md).
--
-- Everything is fictional: addresses use the reserved .example domain, IPs come from 10.0.0.0/8.
-- IDEMPOTENT: every insert is guarded by a natural key (name / subject / email + date), so re-running adds nothing.
-- Parents are looked up by name; no numeric id is hard-coded. Depends only on 00-core.sql.
--
-- Apply with:  mysql -u root rivetit_demo < 85-admin-config.sql
-- (85-admin-config.php runs this file and then stores one real backup zip for the Backup screenshot.)
--
-- WHAT IT ADDS
--   Shared contract (only when missing): the five Ticket categories, the four canned responses, the
--     ticket templates "New Hire IT Setup" and "Access Request".
--   Ticketing: one custom ticket status, SLA calendars + policies ("Standard Support" is the default),
--     the US holiday catalog for this year and next, holidays on the Standard Support calendar, more ticket
--     templates with tasks, a worksheet template, service-catalog items, automation rules and a few run-log lines.
--   Organising data: extra ticket categories (one group with children), tags, custom fields, custom links.
--   Mail: three mailboxes (no passwords, no tokens), a small mail queue, three unknown-sender requests.
--   Integrations: two webhooks (no secret), one AI provider (no API key) with two models.

-- ---- Ticket categories (shared contract, then this chapter's own group) -------------------------
INSERT INTO categories (category_name, category_type, category_color, category_description, category_parent)
SELECT x.n, 'Ticket', x.c, x.d, 0 FROM (
  SELECT 'Hardware' AS n, '#6f42c1' AS c, 'Laptops, desktops, monitors, peripherals' AS d
  UNION ALL SELECT 'Software', '#0d6efd', 'Applications, licences, installs and updates'
  UNION ALL SELECT 'Network', '#20c997', 'Wi-Fi, wired network, VPN, internet access'
  UNION ALL SELECT 'Access & Accounts', '#fd7e14', 'Sign-in problems, permissions, new and leaving staff'
  UNION ALL SELECT 'Other', '#6c757d', 'Anything that does not fit another category'
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = x.n AND c.category_type = 'Ticket');

INSERT INTO categories (category_name, category_type, category_color, category_description, category_parent)
SELECT 'Plant Floor Systems', 'Ticket', '#dc3545', 'Group: equipment used on the production floor and in the warehouse', 0
WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = 'Plant Floor Systems' AND c.category_type = 'Ticket');

INSERT INTO categories (category_name, category_type, category_color, category_description, category_parent)
SELECT x.n, 'Ticket', x.c, x.d, (SELECT g.category_id FROM categories g WHERE g.category_name = 'Plant Floor Systems' AND g.category_type = 'Ticket' LIMIT 1)
FROM (
  SELECT 'Scanners & Handhelds' AS n, '#e8590c' AS c, 'Barcode scanners and handheld terminals' AS d
  UNION ALL SELECT 'Production PCs', '#c92a2a', 'Shop-floor workstations and HMI panels'
  UNION ALL SELECT 'Badge & Door Access', '#a61e4d', 'Door controllers, badge readers and cameras'
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = x.n AND c.category_type = 'Ticket');

-- ---- Canned responses (shared contract) ---------------------------------------------------------
INSERT INTO canned_responses (canned_response_name, canned_response_message)
SELECT x.n, x.m FROM (
  SELECT 'Ticket received' AS n, '<p>Hello,</p><p>Thanks for contacting IT. We have your request and a technician will pick it up shortly. You can reply to this message at any time to add more detail or attachments.</p><p>Summit Ridge IT Support</p>' AS m
  UNION ALL SELECT 'Request more information', '<p>Hello,</p><p>To help us fix this quickly, could you tell us:</p><ul><li>What you were doing when the problem started</li><li>The exact wording of any error message</li><li>Whether it also happens on another device</li></ul><p>Reply to this ticket with the details and we will carry on from there.</p>'
  UNION ALL SELECT 'Password reset instructions', '<p>Hello,</p><p>Your password has been reset. We will give you the temporary password by phone. Sign in with it and choose a new password straight away.</p><p>If you did not ask for this reset, tell us immediately.</p>'
  UNION ALL SELECT 'Resolved - please confirm', '<p>Hello,</p><p>We believe this is now fixed. Please check and reply within 72 hours if the problem comes back. If we do not hear from you the ticket will close automatically.</p>'
) x WHERE NOT EXISTS (SELECT 1 FROM canned_responses c WHERE c.canned_response_name = x.n);

-- ---- Ticket templates and their tasks -----------------------------------------------------------
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT x.n, x.d, x.s, x.b FROM (
  SELECT 'New Hire IT Setup' AS n, 'Equipment, accounts and access for a new employee' AS d, 'New hire IT setup' AS s,
         '<p>Please prepare equipment and accounts for the new employee below.</p><ul><li>Name:</li><li>Start date:</li><li>Department:</li><li>Manager:</li><li>Equipment needed:</li></ul>' AS b
  UNION ALL SELECT 'Access Request', 'Permission or application access for an existing employee', 'Access request',
         '<p>Describe the access needed.</p><ul><li>Employee:</li><li>System or folder:</li><li>Level (read / change):</li><li>Approved by:</li></ul>'
  UNION ALL SELECT 'Laptop Replacement', 'Swap a failing or end-of-life laptop', 'Laptop replacement',
         '<p>The laptop below needs replacing.</p><ul><li>Asset tag:</li><li>Reason (fault / age / lost):</li><li>Data to migrate:</li></ul>'
  UNION ALL SELECT 'Shared Printer Setup', 'Install and map a shared printer for a team', 'Printer setup',
         '<p>Install the shared printer and map it for the department.</p><ul><li>Printer name / location:</li><li>People who need it:</li></ul>'
) x WHERE NOT EXISTS (SELECT 1 FROM ticket_templates t WHERE t.ticket_template_name = x.n);

INSERT INTO task_templates (task_template_name, task_template_order, task_template_completion_estimate, task_template_ticket_template_id)
SELECT x.task, x.ord, 0, t.ticket_template_id
FROM (
  SELECT 'New Hire IT Setup' AS tpl, 'Create the user account and mailbox' AS task, 0 AS ord
  UNION ALL SELECT 'New Hire IT Setup', 'Assign and image a laptop', 1
  UNION ALL SELECT 'New Hire IT Setup', 'Add to department groups and shared drives', 2
  UNION ALL SELECT 'New Hire IT Setup', 'Set up phone extension', 3
  UNION ALL SELECT 'New Hire IT Setup', 'Enrol in Cybersecurity Awareness training', 4
  UNION ALL SELECT 'New Hire IT Setup', 'Hand over equipment on day one', 5
  UNION ALL SELECT 'Access Request', 'Confirm the manager approved the request', 0
  UNION ALL SELECT 'Access Request', 'Grant the access', 1
  UNION ALL SELECT 'Access Request', 'Confirm with the employee that it works', 2
  UNION ALL SELECT 'Laptop Replacement', 'Back up the old laptop', 0
  UNION ALL SELECT 'Laptop Replacement', 'Prepare the replacement', 1
  UNION ALL SELECT 'Laptop Replacement', 'Migrate data and settings', 2
  UNION ALL SELECT 'Laptop Replacement', 'Wipe and retire the old laptop', 3
  UNION ALL SELECT 'Shared Printer Setup', 'Install the printer and drivers', 0
  UNION ALL SELECT 'Shared Printer Setup', 'Map it for the department', 1
) x
JOIN ticket_templates t ON t.ticket_template_name = x.tpl
-- Only a template that has no tasks yet gets this chapter's tasks, so a same-named template another chapter
-- created (with its own tasks) is never topped up with duplicates.
WHERE NOT EXISTS (SELECT 1 FROM task_templates k WHERE k.task_template_ticket_template_id = t.ticket_template_id);

-- ---- Ticket statuses: one custom status ---------------------------------------------------------
INSERT INTO ticket_statuses (ticket_status_name, ticket_status_color, ticket_status_active, ticket_status_order)
SELECT 'Waiting on Vendor', '#6f42c1', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM ticket_statuses s WHERE s.ticket_status_name = 'Waiting on Vendor');

-- ---- SLA business-hours calendars, holidays and policies ---------------------------------------
INSERT INTO sla_business_hours (calendar_name, calendar_timezone, calendar_is_default)
SELECT 'Standard Support', 'America/Chicago', 1
WHERE NOT EXISTS (SELECT 1 FROM sla_business_hours WHERE calendar_name = 'Standard Support')
  AND NOT EXISTS (SELECT 1 FROM sla_business_hours WHERE calendar_is_default = 1);
INSERT INTO sla_business_hours (calendar_name, calendar_timezone, calendar_is_default)
SELECT 'Standard Support', 'America/Chicago', 0
WHERE NOT EXISTS (SELECT 1 FROM sla_business_hours WHERE calendar_name = 'Standard Support');
INSERT INTO sla_business_hours (calendar_name, calendar_timezone, calendar_is_default)
SELECT 'Plant Shift Coverage', 'America/Chicago', 0
WHERE NOT EXISTS (SELECT 1 FROM sla_business_hours WHERE calendar_name = 'Plant Shift Coverage');

INSERT INTO sla_business_hours_periods (calendar_id, day_of_week, open_time, close_time)
SELECT c.calendar_id, d.dow, '08:00:00', '17:00:00'
FROM sla_business_hours c
JOIN (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) d
WHERE c.calendar_name = 'Standard Support'
  AND NOT EXISTS (SELECT 1 FROM sla_business_hours_periods p WHERE p.calendar_id = c.calendar_id);
INSERT INTO sla_business_hours_periods (calendar_id, day_of_week, open_time, close_time)
SELECT c.calendar_id, d.dow, '06:00:00', '22:00:00'
FROM sla_business_hours c
JOIN (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
WHERE c.calendar_name = 'Plant Shift Coverage'
  AND NOT EXISTS (SELECT 1 FROM sla_business_hours_periods p WHERE p.calendar_id = c.calendar_id);

INSERT INTO sla_policies (policy_name, policy_calendar_id, policy_pause_status_ids,
    policy_low_response, policy_low_resolution, policy_medium_response, policy_medium_resolution,
    policy_high_response, policy_high_resolution, policy_is_default)
SELECT 'Standard Support',
       (SELECT calendar_id FROM sla_business_hours WHERE calendar_name = 'Standard Support' LIMIT 1),
       (SELECT GROUP_CONCAT(ticket_status_id ORDER BY ticket_status_id) FROM ticket_statuses WHERE ticket_status_name IN ('On Hold','Waiting on Vendor')),
       480, 4320, 240, 1440, 60, 480,
       IF(EXISTS (SELECT 1 FROM sla_policies WHERE policy_is_default = 1 AND policy_archived_at IS NULL), 0, 1)
WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE policy_name = 'Standard Support');
INSERT INTO sla_policies (policy_name, policy_calendar_id, policy_pause_status_ids,
    policy_low_response, policy_low_resolution, policy_medium_response, policy_medium_resolution,
    policy_high_response, policy_high_resolution, policy_is_default)
SELECT 'Plant Floor Priority',
       (SELECT calendar_id FROM sla_business_hours WHERE calendar_name = 'Plant Shift Coverage' LIMIT 1),
       (SELECT GROUP_CONCAT(ticket_status_id) FROM ticket_statuses WHERE ticket_status_name = 'On Hold'),
       240, 1440, 60, 480, 15, 240, 0
WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE policy_name = 'Plant Floor Priority');

-- ---- Holiday catalog: US federal holidays (same rules as the app's "Load Holidays") for this year and next
CREATE TEMPORARY TABLE tmp_hol_years (yr INT);
INSERT INTO tmp_hol_years VALUES (YEAR(CURDATE())), (YEAR(CURDATE()) + 1);
CREATE TEMPORARY TABLE tmp_hol (yr INT, nm VARCHAR(150), d DATE);
-- fixed-date holidays, moved to the nearest weekday like the app does (Saturday -> Friday, Sunday -> Monday)
INSERT INTO tmp_hol
SELECT yr, nm, CASE DAYOFWEEK(raw) WHEN 7 THEN raw - INTERVAL 1 DAY WHEN 1 THEN raw + INTERVAL 1 DAY ELSE raw END
FROM (
  SELECT yr, 'New Year''s Day' AS nm, MAKEDATE(yr, 1) AS raw FROM tmp_hol_years
  UNION ALL SELECT yr, 'Juneteenth National Independence Day', STR_TO_DATE(CONCAT(yr, '-06-19'), '%Y-%m-%d') FROM tmp_hol_years
  UNION ALL SELECT yr, 'Independence Day', STR_TO_DATE(CONCAT(yr, '-07-04'), '%Y-%m-%d') FROM tmp_hol_years
  UNION ALL SELECT yr, 'Veterans Day', STR_TO_DATE(CONCAT(yr, '-11-11'), '%Y-%m-%d') FROM tmp_hol_years
  UNION ALL SELECT yr, 'Christmas Day', STR_TO_DATE(CONCAT(yr, '-12-25'), '%Y-%m-%d') FROM tmp_hol_years
) f;
-- nth-weekday holidays (WEEKDAY(): Monday = 0 ... Thursday = 3)
INSERT INTO tmp_hol
SELECT yr, nm, first_day + INTERVAL (((wd - WEEKDAY(first_day) + 7) MOD 7) + 7 * (n - 1)) DAY
FROM (
  SELECT yr, 'Martin Luther King, Jr. Day' AS nm, STR_TO_DATE(CONCAT(yr, '-01-01'), '%Y-%m-%d') AS first_day, 0 AS wd, 3 AS n FROM tmp_hol_years
  UNION ALL SELECT yr, 'Washington''s Birthday (Presidents Day)', STR_TO_DATE(CONCAT(yr, '-02-01'), '%Y-%m-%d'), 0, 3 FROM tmp_hol_years
  UNION ALL SELECT yr, 'Labor Day', STR_TO_DATE(CONCAT(yr, '-09-01'), '%Y-%m-%d'), 0, 1 FROM tmp_hol_years
  UNION ALL SELECT yr, 'Columbus Day', STR_TO_DATE(CONCAT(yr, '-10-01'), '%Y-%m-%d'), 0, 2 FROM tmp_hol_years
  UNION ALL SELECT yr, 'Thanksgiving Day', STR_TO_DATE(CONCAT(yr, '-11-01'), '%Y-%m-%d'), 3, 4 FROM tmp_hol_years
) w;
-- Memorial Day: last Monday of May
INSERT INTO tmp_hol
SELECT yr, 'Memorial Day', last_day - INTERVAL ((WEEKDAY(last_day) - 0 + 7) MOD 7) DAY
FROM (SELECT yr, LAST_DAY(STR_TO_DATE(CONCAT(yr, '-05-01'), '%Y-%m-%d')) AS last_day FROM tmp_hol_years) m;

INSERT INTO holidays (holiday_country, holiday_year, holiday_date, holiday_name, holiday_is_custom)
SELECT 'United States', h.yr, h.d, h.nm, 0 FROM tmp_hol h
WHERE NOT EXISTS (SELECT 1 FROM holidays x WHERE x.holiday_country = 'United States' AND x.holiday_date = h.d);

-- One custom entry: the company closes the day after Thanksgiving
INSERT INTO holidays (holiday_country, holiday_year, holiday_date, holiday_name, holiday_is_custom)
SELECT 'United States', h.yr, h.d + INTERVAL 1 DAY, 'Day after Thanksgiving (company shutdown)', 1
FROM tmp_hol h WHERE h.nm = 'Thanksgiving Day'
  AND NOT EXISTS (SELECT 1 FROM holidays x WHERE x.holiday_country = 'United States' AND x.holiday_date = h.d + INTERVAL 1 DAY);

-- Copy the next twelve months of catalog holidays onto the Standard Support calendar (the app copies by value too)
INSERT INTO sla_holidays (calendar_id, holiday_date, holiday_name)
SELECT c.calendar_id, h.holiday_date, h.holiday_name
FROM holidays h JOIN sla_business_hours c ON c.calendar_name = 'Standard Support'
WHERE h.holiday_country = 'United States' AND h.holiday_date >= CURDATE() AND h.holiday_date < CURDATE() + INTERVAL 12 MONTH
  AND NOT EXISTS (SELECT 1 FROM sla_holidays s WHERE s.calendar_id = c.calendar_id AND s.holiday_date = h.holiday_date);

DROP TEMPORARY TABLE tmp_hol;
DROP TEMPORARY TABLE tmp_hol_years;

-- ---- Worksheet template (used by an automation rule and by the ticket "Worksheets" panel) ---------
INSERT INTO worksheet_templates (worksheet_template_name, worksheet_template_description)
SELECT 'Onsite Visit Checklist', 'Fill in when a technician visits a department in person'
WHERE NOT EXISTS (SELECT 1 FROM worksheet_templates WHERE worksheet_template_name = 'Onsite Visit Checklist');

INSERT INTO worksheet_template_fields (field_template_id, field_name, field_type, field_options, field_order, field_required)
SELECT t.worksheet_template_id, x.n, x.ty, x.o, x.ord, x.rq
FROM (
  SELECT 'Arrival' AS n, 'heading' AS ty, '' AS o, 0 AS ord, 0 AS rq
  UNION ALL SELECT 'Checked in with the department lead', 'checkbox', '', 1, 1
  UNION ALL SELECT 'Asset tag worked on', 'text', '', 2, 1
  UNION ALL SELECT 'Type of work', 'select', CONCAT('Repair', CHAR(10), 'Install', CHAR(10), 'Replace', CHAR(10), 'Inspect'), 3, 1
  UNION ALL SELECT 'Work performed', 'textarea', '', 4, 0
  UNION ALL SELECT 'Employee signature', 'signature', '', 5, 0
) x
JOIN worksheet_templates t ON t.worksheet_template_name = 'Onsite Visit Checklist'
WHERE NOT EXISTS (SELECT 1 FROM worksheet_template_fields f WHERE f.field_template_id = t.worksheet_template_id AND f.field_name = x.n);

-- ---- Service catalog ("Request Something") ------------------------------------------------------
INSERT INTO service_catalog_items (name, description, icon, ticket_subject_template, ticket_category_id, default_priority, is_active, sort_order)
SELECT x.n, x.d, x.i, x.s,
       (SELECT category_id FROM categories WHERE category_name = x.cat AND category_type = 'Ticket' AND category_archived_at IS NULL LIMIT 1),
       x.p, x.a, x.o
FROM (
  -- The common tiles (laptop, software, shared folder) come from 70-portal.php; these three are additional ones.
  SELECT 'Guest Wi-Fi Voucher' AS n, 'Visitors on site who need internet access.' AS d, 'fa-wifi' AS i, 'Guest Wi-Fi voucher request' AS s, 'Network' AS cat, 'Low' AS p, 1 AS a, 70 AS o
  UNION ALL SELECT 'Report a Broken Scanner', 'Barcode scanner or handheld not working.', 'fa-barcode', 'Scanner or handheld fault', 'Hardware', 'High', 1, 80
  UNION ALL SELECT 'Conference Room AV Help', 'Screen, camera or speakerphone problem.', 'fa-video', 'Conference room AV problem', 'Other', 'Medium', 0, 90
) x WHERE NOT EXISTS (SELECT 1 FROM service_catalog_items s WHERE s.name = x.n);

-- ---- Ticket automation rules --------------------------------------------------------------------
-- Rules that change tickets when they are created are kept narrow or disabled so they cannot touch other
-- chapters' demo tickets.
INSERT INTO ticket_automation_rules
  (rule_name, rule_enabled, rule_trigger, rule_cond_field, rule_cond_op, rule_cond_value, rule_conditions_json,
   rule_action, rule_action_value, rule_actions_json, rule_order)
SELECT x.n, x.en, x.tr, x.cf, x.co, x.cv, x.cj, x.ac, x.av, x.aj, x.o FROM (
  SELECT 'Nudge tickets with no update in 24 hours' AS n, 1 AS en, 'schedule' AS tr,
         'idle_hours' AS cf, 'greater_than' AS co, '24' AS cv,
         '[{"field":"idle_hours","op":"greater_than","value":"24"}]' AS cj,
         'add_note' AS ac, 'No update in 24 hours - please follow up with the requester.' AS av,
         '[{"action":"add_note","value":"No update in 24 hours - please follow up with the requester."}]' AS aj, 10 AS o
  UNION ALL SELECT 'Escalate breached resolution SLA', 1, 'schedule',
         'sla_resolution_breached', 'equals', '1',
         '[{"field":"sla_resolution_breached","op":"equals","value":"1"}]',
         'escalate', CONCAT(IFNULL((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), ':high'),
         CONCAT('[{"action":"escalate","value":"', IFNULL((SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'), 0), ':high"},{"action":"notify_assignee","value":""}]'), 20
  UNION ALL SELECT 'Add onsite checklist when subject says [onsite]', 1, 'ticket_created',
         'subject', 'contains', '[onsite]',
         '[{"field":"subject","op":"contains","value":"[onsite]"}]',
         'add_worksheet', CAST(IFNULL((SELECT worksheet_template_id FROM worksheet_templates WHERE worksheet_template_name = 'Onsite Visit Checklist' LIMIT 1), 0) AS CHAR),
         CONCAT('[{"action":"add_worksheet","value":"', IFNULL((SELECT worksheet_template_id FROM worksheet_templates WHERE worksheet_template_name = 'Onsite Visit Checklist' LIMIT 1), 0), '"}]'), 30
  UNION ALL SELECT 'Close tickets on hold for two weeks', 0, 'schedule',
         'idle_hours', 'greater_than', '336',
         '[{"field":"status_id","op":"equals","value":"3"},{"field":"idle_hours","op":"greater_than","value":"336"}]',
         'close_ticket', '',
         '[{"action":"close_ticket","value":""}]', 40
) x WHERE NOT EXISTS (SELECT 1 FROM ticket_automation_rules r WHERE r.rule_name = x.n);

-- A few run-log lines, one per existing ticket (up to four); nothing is added when no tickets exist yet.
INSERT INTO ticket_automation_runs (rule_id, rule_name, trigger_type, ticket_id, client_id, summary, created_at)
SELECT r.rule_id, r.rule_name, 'schedule', t.ticket_id, NULLIF(t.ticket_client_id, 0),
       CONCAT('added note to ticket #', t.ticket_number), NOW() - INTERVAL (t.rn * 7 + 3) HOUR
FROM (
  SELECT ticket_id, ticket_number, ticket_client_id, (@rn := @rn + 1) AS rn
  FROM tickets, (SELECT @rn := 0) v
  WHERE ticket_archived_at IS NULL
  ORDER BY ticket_id LIMIT 4
) t
JOIN ticket_automation_rules r ON r.rule_name = 'Nudge tickets with no update in 24 hours'
WHERE NOT EXISTS (SELECT 1 FROM ticket_automation_runs x WHERE x.rule_id = r.rule_id AND x.ticket_id = t.ticket_id AND x.trigger_type = 'schedule');

-- ---- Tags (types: 1 Department, 2 Location, 3 Contact, 4 Credential, 5 Asset, 6 Ticket) -----------
INSERT INTO tags (tag_name, tag_type, tag_color, tag_icon)
SELECT x.n, x.t, x.c, x.i FROM (
  SELECT 'VIP' AS n, 6 AS t, '#d63384' AS c, 'star' AS i
  UNION ALL SELECT 'Month-End', 6, '#0d6efd', 'calendar-check'
  UNION ALL SELECT 'Vendor Escalation', 6, '#6f42c1', 'external-link-alt'
  UNION ALL SELECT 'Recurring Fault', 6, '#fd7e14', 'redo'
  UNION ALL SELECT 'Loaner Pool', 5, '#20c997', 'exchange-alt'
  UNION ALL SELECT 'Under Warranty', 5, '#198754', 'shield-alt'
  UNION ALL SELECT 'Secured Area', 2, '#dc3545', 'lock'
  UNION ALL SELECT 'Shared Login', 4, '#ffc107', 'users'
) x WHERE NOT EXISTS (SELECT 1 FROM tags g WHERE g.tag_name = x.n AND g.tag_type = x.t);

-- ---- Custom fields (definitions only; see the guide for what they currently do) ------------------
INSERT INTO custom_fields (custom_field_table, custom_field_label, custom_field_type, custom_field_order)
SELECT x.t, x.l, 'Text', x.o FROM (
  SELECT 'client_assets' AS t, 'Cost Centre' AS l, 1 AS o
  UNION ALL SELECT 'client_assets', 'Purchase Order', 2
  UNION ALL SELECT 'clients', 'Plant Code', 1
) x WHERE NOT EXISTS (SELECT 1 FROM custom_fields f WHERE f.custom_field_table = x.t AND f.custom_field_label = x.l);

-- ---- Custom links (location: 1 Main Side Nav, 2 Top Nav, 3 Department Portal Nav, 4 Admin Nav, 5 Reports Nav)
INSERT INTO custom_links (custom_link_name, custom_link_uri, custom_link_new_tab, custom_link_icon, custom_link_location, custom_link_order)
SELECT x.n, x.u, x.nt, x.i, x.l, x.o FROM (
  SELECT 'Password Manager' AS n, 'https://vault.summitridge.example' AS u, 1 AS nt, 'key' AS i, 1 AS l, 1 AS o
  UNION ALL SELECT 'IT Runbook Wiki', 'https://wiki.summitridge.example/it', 1, 'book-open', 4, 1
  UNION ALL SELECT 'Monthly IT Scorecard', 'https://reports.summitridge.example/it-scorecard', 1, 'chart-bar', 5, 1
) x WHERE NOT EXISTS (SELECT 1 FROM custom_links l WHERE l.custom_link_name = x.n AND l.custom_link_location = x.l);

-- ---- Mailboxes (no passwords or tokens are stored) ----------------------------------------------
INSERT INTO mailboxes (mailbox_name, mailbox_email, mailbox_from_name, mailbox_type, mailbox_imap_host, mailbox_imap_port,
                       mailbox_imap_encryption, mailbox_imap_username, mailbox_parse_unknown_senders, mailbox_default_client_id,
                       mailbox_active, mailbox_order)
SELECT x.n, x.e, x.f, x.ty, x.h, x.p, x.enc, x.u, x.pu,
       (SELECT client_id FROM clients WHERE client_name = x.dept LIMIT 1), x.a, x.o
FROM (
  SELECT 'IT Support Inbox' AS n, 'it-support@summitridge.example' AS e, 'Summit Ridge IT Support' AS f, 'standard_imap' AS ty,
         'imap.summitridge.example' AS h, 993 AS p, 'ssl' AS enc, 'it-support@summitridge.example' AS u, 1 AS pu, NULL AS dept, 1 AS a, 1 AS o
  UNION ALL SELECT 'Plant Floor Requests', 'plant-it@summitridge.example', 'Plant IT', 'microsoft_oauth',
         NULL, NULL, NULL, 'plant-it@summitridge.example', 1, 'Production', 1, 2
  UNION ALL SELECT 'Facilities Requests', 'facilities-it@summitridge.example', 'Facilities IT', 'standard_imap',
         'imap.summitridge.example', 993, 'ssl', 'facilities-it@summitridge.example', 0, 'Warehouse & Logistics', 0, 3
) x WHERE NOT EXISTS (SELECT 1 FROM mailboxes m WHERE m.mailbox_email = x.e);

-- ---- Mail queue (outgoing email) ----------------------------------------------------------------
INSERT INTO email_queue (email_status, email_recipient, email_recipient_name, email_from, email_from_name, email_subject, email_content,
                         email_queued_at, email_failed_at, email_attempts, email_sent_at)
SELECT x.st, x.rcpt, x.rname, 'it-support@summitridge.example', 'Summit Ridge IT Support', x.subj, x.body,
       NOW() - INTERVAL x.hrs HOUR,
       IF(x.st = 2, NOW() - INTERVAL (x.hrs - 1) HOUR, NULL), x.att,
       IF(x.st = 3, NOW() - INTERVAL (x.hrs - 1) HOUR, NULL)
FROM (
  SELECT 3 AS st, 'grace.okafor@summitridge.example' AS rcpt, 'Grace Okafor' AS rname, 'Your IT ticket has been received' AS subj, '<p>Hello Grace, we have received your request.</p>' AS body, 30 AS hrs, 1 AS att
  UNION ALL SELECT 3, 'tom.kessler@summitridge.example', 'Tom Kessler', 'Your IT ticket has been resolved', '<p>Hello Tom, your ticket has been resolved. Please confirm.</p>', 26, 1
  UNION ALL SELECT 3, 'aisha.rahman@summitridge.example', 'Aisha Rahman', 'Your IT ticket has been received', '<p>Hello Aisha, we have received your request.</p>', 20, 1
  UNION ALL SELECT 2, 'jake.sullivan@summitridge.example', 'Jake Sullivan', 'Your IT ticket has been updated', '<p>Hello Jake, a technician replied to your ticket.</p>', 8, 5
  UNION ALL SELECT 1, 'nina.rossi@summitridge.example', 'Nina Rossi', 'Your IT ticket has been received', '<p>Hello Nina, we have received your request.</p>', 1, 1
  UNION ALL SELECT 0, 'miguel.alvarez@summitridge.example', 'Miguel Alvarez', 'Domain and certificate expiry summary', '<p>Two certificates expire in the next 45 days.</p>', 0, 0
  UNION ALL SELECT 0, 'yuki.tanaka@summitridge.example', 'Yuki Tanaka', 'How did we do? Rate your closed ticket', '<p>Please rate your recent ticket.</p>', 0, 0
) x WHERE NOT EXISTS (SELECT 1 FROM email_queue q WHERE q.email_recipient = x.rcpt AND q.email_subject = x.subj);

-- ---- Mail requests (emails from unknown senders waiting for review) -----------------------------
INSERT INTO mail_requests (mail_request_mailbox_id, mail_request_from_email, mail_request_from_name, mail_request_subject,
                           mail_request_body, mail_request_received_at)
SELECT (SELECT mailbox_id FROM mailboxes WHERE mailbox_email = 'it-support@summitridge.example' LIMIT 1),
       x.e, x.n, x.s, x.b, NOW() - INTERVAL x.hrs HOUR
FROM (
  SELECT 'dana.whitcomb@lakeview-freight.example' AS e, 'Dana Whitcomb' AS n, 'Dock scheduler will not open on the shared PC' AS s,
         '<p>Hi, I am with the freight carrier working at the Milwaukee dock this week. The scheduling page will not load on the shared PC. Can someone take a look?</p>' AS b, 5 AS hrs
  UNION ALL SELECT 'p.okonkwo@ridgeline-audit.example', 'P. Okonkwo', 'Auditor needs read access to the finance share',
         '<p>Good morning, our team is on site for the annual audit and needs temporary read access to the finance share. Grace Okafor said to email IT.</p>', 22
  UNION ALL SELECT 'noreply@print-supplies.example', 'Print Supplies Co.', 'Your toner order has shipped',
         '<p>Your order has shipped and will arrive within 3 business days.</p>', 47
) x
WHERE EXISTS (SELECT 1 FROM mailboxes WHERE mailbox_email = 'it-support@summitridge.example')
  AND NOT EXISTS (SELECT 1 FROM mail_requests r WHERE r.mail_request_from_email = x.e AND r.mail_request_subject = x.s);

-- ---- Webhooks (no secret) -----------------------------------------------------------------------
INSERT INTO webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled)
SELECT x.n, x.u, '', x.ev, x.en FROM (
  SELECT 'Ops Chat Notifier' AS n, 'https://hooks.summitridge.example/rivetit/tickets' AS u, 'ticket.created,ticket.resolved' AS ev, 1 AS en
  UNION ALL SELECT 'Ticket Archive Export', 'https://automation.summitridge.example/webhook/ticket-archive', 'ticket.replied,ticket.status_changed,ticket.assigned', 0
) x WHERE NOT EXISTS (SELECT 1 FROM webhooks w WHERE w.webhook_name = x.n);

-- ---- AI provider and models (no API key stored) -------------------------------------------------
INSERT INTO ai_providers (ai_provider_name, ai_provider_api_url, ai_provider_api_key)
SELECT 'Local LLM Gateway', 'http://10.0.0.60:11434/v1', NULL
WHERE NOT EXISTS (SELECT 1 FROM ai_providers WHERE ai_provider_name = 'Local LLM Gateway');

INSERT INTO ai_models (ai_model_name, ai_model_prompt, ai_model_use_case, ai_model_ai_provider_id)
SELECT x.n, x.p, x.uc, (SELECT ai_provider_id FROM ai_providers WHERE ai_provider_name = 'Local LLM Gateway' LIMIT 1)
FROM (
  SELECT 'llama3.1:8b-instruct' AS n, 'You are an assistant for an internal IT service desk. Be concise and accurate. Never invent facts about the company.' AS p, 'General' AS uc
  UNION ALL SELECT 'llama3.1:8b-instruct', 'Draft a short, polite reply to the requester. Ask for missing details. Do not promise a fix time.', 'Reply Draft'
) x
WHERE EXISTS (SELECT 1 FROM ai_providers WHERE ai_provider_name = 'Local LLM Gateway')
  AND NOT EXISTS (SELECT 1 FROM ai_models m WHERE m.ai_model_use_case = x.uc AND m.ai_model_ai_provider_id = (SELECT ai_provider_id FROM ai_providers WHERE ai_provider_name = 'Local LLM Gateway' LIMIT 1));
