-- Demo data for the "Administration: users, roles and security" guide page.
--
-- Adds
--   * ONE custom role, "Help Desk Lead", so the Roles page and role editor show more than the three
--     roles every install starts with;
--   * a believable trail in the audit log (Admin > Maintenance > Audit Logs), the app log
--     (App Logs) and the email log (Email Log).
-- It never touches the users, the built-in roles or any setting.
--
-- The API keys need the app's own vault-key helper (encryptUserSpecificKey), so they are created by
-- 80-admin-accounts.php next to this file. Apply both; neither depends on the other.
--
-- Re-runnable: every insert is guarded by WHERE NOT EXISTS on a natural key, parents are looked up by
-- name / e-mail (never by numeric id) and dates are relative to NOW().

-- ---- Custom role: Help Desk Lead -------------------------------------------------------------
INSERT INTO user_roles (role_name, role_description, role_is_admin)
SELECT 'Help Desk Lead', 'Runs the service desk day to day: full ticket access, knowledge base and reports', 0
WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE role_name = 'Help Desk Lead');

-- Departments Modify, Tickets/assets/docs Full, Credentials Read, Reports on, Knowledge base Full.
INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level)
SELECT r.role_id, m.module_id, p.lvl
FROM (
      SELECT 'module_client' AS mod_name, 2 AS lvl
      UNION ALL SELECT 'module_support', 3
      UNION ALL SELECT 'module_credential', 1
      UNION ALL SELECT 'module_reporting', 1
      UNION ALL SELECT 'module_kb', 3
     ) p
JOIN modules m    ON m.module_name = p.mod_name
JOIN user_roles r ON r.role_name = 'Help Desk Lead'
WHERE NOT EXISTS (SELECT 1 FROM user_role_permissions x WHERE x.user_role_id = r.role_id AND x.module_id = m.module_id);

-- ---- Audit log (table `logs`) ----------------------------------------------------------------------
-- The wording of every row is the wording the app itself writes (logAction() calls in the code).
-- Rows are inserted oldest first so the default newest-first view reads in time order.
-- The first block is one batch guarded by a single sentinel row; the last two rows are guarded on their own.
INSERT INTO logs (log_type, log_action, log_description, log_ip, log_user_agent, log_created_at, log_client_id, log_user_id, log_entity_id)
SELECT x.t, x.a, x.d, x.ip, x.ua, NOW() - INTERVAL x.ago MINUTE,
       COALESCE((SELECT client_id FROM clients WHERE client_name = x.dept LIMIT 1), 0),
       COALESCE((SELECT user_id FROM users WHERE user_email = x.actor LIMIT 1), 0),
       COALESCE((SELECT user_id FROM users WHERE user_email = x.ent_user LIMIT 1),
                (SELECT role_id FROM user_roles WHERE role_name = x.ent_role LIMIT 1), 0)
FROM (
  SELECT 'User' AS t, 'Create' AS a, 'Alex Morgan created user Priya Nair' AS d,
         '192.168.10.20' AS ip, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' AS ua,
         (41*1440 + 9*60 + 12) AS ago, CAST(NULL AS CHAR) AS dept, 'alex.morgan@summitridge.example' AS actor,
         'priya.nair@summitridge.example' AS ent_user, CAST(NULL AS CHAR) AS ent_role
  UNION ALL SELECT 'User', 'Create', 'Alex Morgan created user Marcus Lee', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (41*1440 + 9*60 + 3), NULL, 'alex.morgan@summitridge.example', 'marcus.lee@summitridge.example', NULL
  UNION ALL SELECT 'Settings', 'Edit', 'Alex Morgan edited security settings', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (40*1440 + 14*60 + 30), NULL, 'alex.morgan@summitridge.example', NULL, NULL
  UNION ALL SELECT 'User Role', 'Create', 'Alex Morgan created user role Help Desk Lead', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (38*1440 + 10*60 + 45), NULL, 'alex.morgan@summitridge.example', NULL, 'Help Desk Lead'
  UNION ALL SELECT 'User Role', 'Edit', 'Alex Morgan edited user role Help Desk Lead', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (37*1440 + 15*60 + 20), NULL, 'alex.morgan@summitridge.example', NULL, 'Help Desk Lead'
  UNION ALL SELECT 'Login', 'Success', 'Priya Nair successfully logged in', '192.168.10.44',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (36*1440 + 8*60 + 2), NULL, 'priya.nair@summitridge.example', NULL, NULL
  UNION ALL SELECT 'Login', 'Success', 'Marcus Lee successfully logged in', '192.168.10.51',
         'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
         (36*1440 + 8*60 + 40), NULL, 'marcus.lee@summitridge.example', NULL, NULL
  UNION ALL SELECT 'API Key', 'Create', CONCAT('Alex Morgan created API key Asset inventory sync set to expire on ', DATE_FORMAT(NOW() + INTERVAL 300 DAY, '%Y-%m-%d')),
         '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (30*1440 + 11*60 + 5), NULL, 'alex.morgan@summitridge.example', NULL, NULL
  UNION ALL SELECT 'API Key', 'Create', CONCAT('Alex Morgan created API key Plant floor status board set to expire on ', DATE_FORMAT(NOW() + INTERVAL 340 DAY, '%Y-%m-%d')),
         '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (24*1440 + 13*60 + 40), 'Production', 'alex.morgan@summitridge.example', NULL, NULL
  UNION ALL SELECT 'Login', 'Failed', 'Failed login attempt using admin@summitridge.example', '192.0.2.77',
         'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
         (20*1440 + 2*60 + 11), NULL, NULL, NULL, NULL
  UNION ALL SELECT 'Login', 'Failed', 'Failed login attempt using administrator@summitridge.example', '192.0.2.77',
         'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
         (20*1440 + 2*60 + 10), NULL, NULL, NULL, NULL
  UNION ALL SELECT 'Login', 'Failed', 'Failed login attempt using alex.morgan@summitridge.example', '192.0.2.77',
         'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
         (20*1440 + 2*60 + 9), NULL, NULL, NULL, NULL
  UNION ALL SELECT 'User', 'Edit', 'Alex Morgan edited user Priya Nair', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (12*1440 + 9*60 + 50), NULL, 'alex.morgan@summitridge.example', 'priya.nair@summitridge.example', NULL
  UNION ALL SELECT 'API Key', 'Revoke', 'Alex Morgan revoked API key Old scanner integration', '192.168.10.20',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (9*1440 + 16*60 + 25), 'Warehouse & Logistics', 'alex.morgan@summitridge.example', NULL, NULL
  UNION ALL SELECT 'Department Login', 'Success', 'Department contact grace.okafor@summitridge.example successfully logged in locally', '192.168.10.63',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
         (5*1440 + 7*60 + 55), 'Finance & Accounting', 'grace.okafor@summitridge.example', 'grace.okafor@summitridge.example', NULL
  UNION ALL SELECT 'API', 'Blocked', 'Read-only key (Plant floor status board) attempted a write (endpoint: /api/v1/tickets)', '192.168.30.12',
         'curl/8.5.0',
         (3*1440 + 22*60 + 18), NULL, NULL, NULL, NULL
  UNION ALL SELECT 'Login', 'Success', 'Marcus Lee successfully logged in', '192.168.10.51',
         'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
         (1*1440 + 5*60 + 12), NULL, 'marcus.lee@summitridge.example', NULL, NULL
  UNION ALL SELECT 'Login', 'Success', 'Priya Nair successfully logged in', '192.168.10.44',
         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
         (3*60 + 20), NULL, 'priya.nair@summitridge.example', NULL, NULL
) x
WHERE NOT EXISTS (SELECT 1 FROM logs WHERE log_type = 'Login' AND log_action = 'Failed'
                    AND log_description = 'Failed login attempt using administrator@summitridge.example');

-- The expired scanner key is still being tried by a device that was never reconfigured.
INSERT INTO logs (log_type, log_action, log_description, log_ip, log_user_agent, log_created_at, log_client_id, log_user_id, log_entity_id)
SELECT 'API', 'Failed', 'Incorrect or expired key (endpoint: /api/v1/clients)', '192.168.20.15', 'curl/8.5.0',
       NOW() - INTERVAL (2*60 + 35) MINUTE, 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM logs WHERE log_type = 'API' AND log_action = 'Failed'
                    AND log_description = 'Incorrect or expired key (endpoint: /api/v1/clients)');

-- ---- App log (table `app_logs`) ---------------------------------------------------------------------
-- Category = the part of the app that wrote the line; type = info / warning / error. One batch, guarded by a sentinel row.
INSERT INTO app_logs (app_log_category, app_log_type, app_log_details, app_log_created_at)
SELECT x.c, x.t, x.d, NOW() - INTERVAL x.ago MINUTE
FROM (
  SELECT 'Cron' AS c, 'info' AS t, 'Cron Started' AS d, (3*1440 + 8*60 + 0) AS ago
  UNION ALL SELECT 'Cron', 'info', 'Ticket automation rules processed (3 rules)', (3*1440 + 8*60 - 1)
  UNION ALL SELECT 'Cron', 'info', 'Cron executed successfully', (3*1440 + 8*60 - 2)
  UNION ALL SELECT 'Mail', 'error', 'Failed to send email to owen.baker@summitridge.example regarding Your ticket has been updated. SMTP Error: Could not connect to SMTP host.', (3*1440 + 7*60 + 58)
  UNION ALL SELECT 'Cron-Email-Parser', 'error', 'Mailbox #1 (helpdesk@summitridge.example) failed: connection setup failed', (2*1440 + 9*60 + 30)
  UNION ALL SELECT 'Cron', 'info', 'Cron Started', (2*1440 + 8*60 + 0)
  UNION ALL SELECT 'Cron', 'info', 'Scheduled reopen: 2 ticket(s) automatically reopened', (2*1440 + 8*60 - 1)
  UNION ALL SELECT 'Cron', 'info', 'Cron executed successfully', (2*1440 + 8*60 - 2)
  UNION ALL SELECT 'Cron', 'info', 'Cron Started', (1*1440 + 8*60 + 0)
  UNION ALL SELECT 'Cron', 'info', 'Ticket automation rules processed (3 rules)', (1*1440 + 8*60 - 1)
  UNION ALL SELECT 'Cron', 'info', 'Cron executed successfully', (1*1440 + 8*60 - 2)
  UNION ALL SELECT 'Cron-Email-Parser', 'warning', 'Lock file present. Cron Email Parser attempted to execute but was already executing, so instead it terminated.', (1*1440 + 6*60 + 45)
  UNION ALL SELECT 'Cron-Email-Parser', 'error', 'Mailbox #1 (helpdesk@summitridge.example) failed: connection setup failed', (1*1440 + 6*60 + 15)
  UNION ALL SELECT 'Cron', 'info', 'Cron Started', (8*60 + 0)
  UNION ALL SELECT 'Cron', 'info', 'Cron executed successfully', (8*60 - 2)
) x
WHERE NOT EXISTS (SELECT 1 FROM app_logs WHERE app_log_category = 'Mail' AND app_log_type = 'error'
                    AND app_log_details LIKE 'Failed to send email to owen.baker@summitridge.example regarding Your ticket has been updated.%');

-- ---- Email log (table `mail_log`) ----------------------------------------------------------------
-- One row per inbound email the mailbox poller handled; the wording of the outcome and detail columns is the poller's own.
--  * Three rows need no ticket (a bounce, an ignored newsletter, an unknown sender queued for review).
--  * "Ticket Created" / "Reply Added" rows are built from tickets other seeds created from email (ticket source "Email"),
--    so the link and the subject match a real ticket; without such tickets those rows are simply not added.
--  * The Mailbox column uses the first active mailbox if one exists (a dash otherwise).
-- Rows are inserted oldest first so the default newest-first view reads in time order.
INSERT INTO mail_log (mail_log_mailbox_id, mail_log_from_email, mail_log_from_name, mail_log_subject, mail_log_outcome, mail_log_detail, mail_log_ticket_id, mail_log_created_at)
SELECT (SELECT MIN(mailbox_id) FROM mailboxes WHERE mailbox_archived_at IS NULL),
       y.from_email, y.from_name, y.subject, y.outcome, y.detail, y.ticket_id, y.created_at
FROM (
  SELECT 'mailer-daemon@summitridge.example' AS from_email, 'Mail Delivery System' AS from_name,
         'Undelivered Mail Returned to Sender' AS subject, 'ndr' AS outcome,
         'Bounce for j.doe@example.net - 5.1.1 / smtp; 550 5.1.1 User unknown' AS detail,
         NULL AS ticket_id, NOW() - INTERVAL (22*60 + 10) MINUTE AS created_at
  UNION ALL SELECT 'newsletter@software-vendor.example', 'Software Vendor News',
         'Your licence renewal is coming up', 'ignored',
         'Unknown sender - mailbox does not queue unknown senders', NULL, NOW() - INTERVAL (9*60 + 40) MINUTE
  UNION ALL SELECT 'orders@parts-supplier.example', 'Parts Supplier Orders',
         'Quote request for spare conveyor belts', 'mail_request',
         'Unknown sender queued for review', NULL, NOW() - INTERVAL (7*60 + 5) MINUTE
  UNION ALL SELECT te.contact_email, te.contact_name, te.ticket_subject, 'ticket_created',
         'New ticket from known contact', te.ticket_id, te.ticket_created_at
    FROM (SELECT t.ticket_id, t.ticket_subject, t.ticket_created_at, c.contact_name, c.contact_email
            FROM tickets t JOIN contacts c ON c.contact_id = t.ticket_contact_id
           WHERE t.ticket_source = 'Email' AND t.ticket_created_at > NOW() - INTERVAL 30 DAY
           ORDER BY t.ticket_id DESC LIMIT 2) te
  UNION ALL SELECT te.contact_email, te.contact_name,
         CONCAT('Re: [', te.ticket_prefix, te.ticket_number, '] ', te.ticket_subject), 'reply_added',
         CONCAT('Matched ticket #', te.ticket_number, ' by subject tag'), te.ticket_id, te.ticket_created_at + INTERVAL 45 MINUTE
    FROM (SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_created_at, c.contact_name, c.contact_email
            FROM tickets t JOIN contacts c ON c.contact_id = t.ticket_contact_id
           WHERE t.ticket_source = 'Email' AND t.ticket_created_at > NOW() - INTERVAL 30 DAY
           ORDER BY t.ticket_id DESC LIMIT 2) te
) y
WHERE NOT EXISTS (SELECT 1 FROM mail_log m WHERE m.mail_log_from_email = y.from_email AND m.mail_log_subject = y.subject)
ORDER BY y.created_at ASC;
