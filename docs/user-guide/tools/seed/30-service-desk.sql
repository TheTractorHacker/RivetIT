-- Demo data for the Service Desk pages of the user guide (tickets, recurring tickets, request
-- catalog, problems, changes, mail requests, CSAT). Everything is fictional; addresses use the
-- reserved .example domain. Run AFTER 00-core.sql (and after 10-infrastructure if you want tickets
-- linked to assets - the asset lookups below are NULL-safe, so it also works without it).
--
--   mysql -u root rivetit_demo < docs/user-guide/tools/seed/30-service-desk.sql
--
-- Idempotent: every insert is guarded by a natural key (name / subject / title), so re-running
-- adds nothing. Ticket numbers come from the app's own counter (settings.config_ticket_prefix and
-- settings.config_ticket_next_number) and the counter is advanced past the tickets added here.
--
-- Tickets are recognised by a marker in ticket_url_key (MD5 of 'sd-url-key-' + subject), not by
-- subject alone, so a ticket with the same subject created by another chapter's seed is never touched.
--
-- Shared lookups (ticket categories, canned responses, ticket templates, the mailbox behind Requests,
-- the VIP tag) are inserted only when missing and use exactly the same wording as the Administration
-- chapter's seed (85-admin-config.sql), so the result is identical whichever seed runs first. The
-- request catalog tiles and the ticket-template task lists belong to that chapter and the portal chapter.
--
-- Times: the app stores company-local time (it sets the MySQL session zone from Settings, which is
-- America/Chicago here), whereas the mysql CLI defaults to UTC. Without the block below every
-- "n minutes ago" would land hours in the future as far as the app is concerned, so this session is
-- switched to the company clock first.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------------------------
-- 0. Use the company clock for NOW() (US daylight-saving rules for America/Chicago)
-- ---------------------------------------------------------------------------------------------
SET @sd_company_tz := (SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1);
SET @sd_year := YEAR(UTC_TIMESTAMP());
SET @sd_mar1 := DATE(CONCAT(@sd_year, '-03-01'));
SET @sd_dst_start := DATE_ADD(@sd_mar1, INTERVAL ((8 - DAYOFWEEK(@sd_mar1)) % 7 + 7) DAY);   -- 2nd Sunday of March
SET @sd_nov1 := DATE(CONCAT(@sd_year, '-11-01'));
SET @sd_dst_end := DATE_ADD(@sd_nov1, INTERVAL ((8 - DAYOFWEEK(@sd_nov1)) % 7) DAY);          -- 1st Sunday of November
SET @sd_is_dst := (UTC_TIMESTAMP() >= TIMESTAMP(@sd_dst_start, '08:00:00') AND UTC_TIMESTAMP() < TIMESTAMP(@sd_dst_end, '07:00:00'));
SET @sd_offset := CASE WHEN @sd_company_tz = 'America/Chicago' THEN IF(@sd_is_dst, '-05:00', '-06:00') ELSE '+00:00' END;
SET time_zone = @sd_offset;

-- ---------------------------------------------------------------------------------------------
-- 1. Shared lookups (inserted only when missing)
-- ---------------------------------------------------------------------------------------------

-- Ticket categories. Top-level rows are "groups" (the ticket list calls them Boards).
INSERT INTO categories (category_name, category_type, category_color, category_description, category_parent)
SELECT x.n, 'Ticket', x.c, x.d, 0 FROM (
  SELECT 'Hardware' AS n, '#6f42c1' AS c, 'Laptops, desktops, monitors, peripherals' AS d
  UNION ALL SELECT 'Software', '#0d6efd', 'Applications, licences, installs and updates'
  UNION ALL SELECT 'Network', '#20c997', 'Wi-Fi, wired network, VPN, internet access'
  UNION ALL SELECT 'Access & Accounts', '#fd7e14', 'Sign-in problems, permissions, new and leaving staff'
  UNION ALL SELECT 'Other', '#6c757d', 'Anything that does not fit another category'
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = x.n AND c.category_type = 'Ticket');

-- Sub-categories used by this chapter's tickets (each sits under one of the groups above and takes its colour).
INSERT INTO categories (category_name, category_description, category_type, category_color, category_parent)
SELECT x.n, x.d, 'Ticket', g.category_color, g.category_id
FROM (
  SELECT 'Laptops and Desktops' AS n, 'Hardware' AS grp, 'Laptops, desktops, monitors and docks' AS d
  UNION ALL SELECT 'Printers and Peripherals', 'Hardware', 'Printers, label printers, displays and accessories'
  UNION ALL SELECT 'Handhelds and Scanners', 'Hardware', 'Warehouse handhelds and barcode scanners'
  UNION ALL SELECT 'Microsoft 365', 'Software', 'Outlook, Teams, OneDrive and Office apps'
  UNION ALL SELECT 'Line-of-Business Apps', 'Software', 'CAD, inventory and other specialist software'
  UNION ALL SELECT 'Wi-Fi and Wired', 'Network', 'Wireless coverage and network ports'
  UNION ALL SELECT 'VPN and Remote Access', 'Network', 'Working from home and while travelling'
  UNION ALL SELECT 'Password and MFA', 'Access & Accounts', 'Password resets, locked accounts and sign-in codes'
  UNION ALL SELECT 'Access Requests', 'Access & Accounts', 'Shared drives, folders and application permissions'
  UNION ALL SELECT 'New Starters and Leavers', 'Access & Accounts', 'Equipment and accounts for people joining or leaving'
) x
JOIN categories g ON g.category_type = 'Ticket' AND g.category_name = x.grp AND g.category_parent = 0 AND g.category_archived_at IS NULL
WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = x.n AND c.category_type = 'Ticket');

-- Canned responses
INSERT INTO canned_responses (canned_response_name, canned_response_message)
SELECT x.n, x.m FROM (
  SELECT 'Ticket received' AS n, '<p>Hello,</p><p>Thanks for contacting IT. We have your request and a technician will pick it up shortly. You can reply to this message at any time to add more detail or attachments.</p><p>Summit Ridge IT Support</p>' AS m
  UNION ALL SELECT 'Request more information', '<p>Hello,</p><p>To help us fix this quickly, could you tell us:</p><ul><li>What you were doing when the problem started</li><li>The exact wording of any error message</li><li>Whether it also happens on another device</li></ul><p>Reply to this ticket with the details and we will carry on from there.</p>'
  UNION ALL SELECT 'Password reset instructions', '<p>Hello,</p><p>Your password has been reset. We will give you the temporary password by phone. Sign in with it and choose a new password straight away.</p><p>If you did not ask for this reset, tell us immediately.</p>'
  UNION ALL SELECT 'Resolved - please confirm', '<p>Hello,</p><p>We believe this is now fixed. Please check and reply within 72 hours if the problem comes back. If we do not hear from you the ticket will close automatically.</p>'
) x WHERE NOT EXISTS (SELECT 1 FROM canned_responses c WHERE c.canned_response_name = x.n);

-- Ticket templates (their task checklists are added by the Administration chapter's seed)
INSERT INTO ticket_templates (ticket_template_name, ticket_template_description, ticket_template_subject, ticket_template_details)
SELECT x.n, x.d, x.s, x.b FROM (
  SELECT 'New Hire IT Setup' AS n, 'Equipment, accounts and access for a new employee' AS d, 'New hire IT setup' AS s,
         '<p>Please prepare equipment and accounts for the new employee below.</p><ul><li>Name:</li><li>Start date:</li><li>Department:</li><li>Manager:</li><li>Equipment needed:</li></ul>' AS b
  UNION ALL SELECT 'Access Request', 'Permission or application access for an existing employee', 'Access request',
         '<p>Describe the access needed.</p><ul><li>Employee:</li><li>System or folder:</li><li>Level (read / change):</li><li>Approved by:</li></ul>'
) x WHERE NOT EXISTS (SELECT 1 FROM ticket_templates t WHERE t.ticket_template_name = x.n);

-- Ticket tags (tag type 6 = tickets)
INSERT INTO tags (tag_name, tag_type, tag_color, tag_icon)
SELECT x.n, 6, x.c, x.i FROM (
  SELECT 'VIP' AS n, '#d63384' AS c, 'star' AS i
  UNION ALL SELECT 'Onboarding', '#16a34a', 'user-plus'
  UNION ALL SELECT 'Waiting on vendor', '#d97706', 'truck'
  UNION ALL SELECT 'Security', '#7c3aed', 'shield-alt'
  UNION ALL SELECT 'Remote work', '#0891b2', 'house-user'
) x WHERE NOT EXISTS (SELECT 1 FROM tags g WHERE g.tag_name = x.n AND g.tag_type = 6);

-- The mailbox that unknown-sender email arrives in (no password or token is stored)
INSERT INTO mailboxes (mailbox_name, mailbox_email, mailbox_from_name, mailbox_type, mailbox_imap_host, mailbox_imap_port,
                       mailbox_imap_encryption, mailbox_imap_username, mailbox_parse_unknown_senders, mailbox_default_client_id,
                       mailbox_active, mailbox_order)
SELECT 'IT Support Inbox', 'it-support@summitridge.example', 'Summit Ridge IT Support', 'standard_imap',
       'imap.summitridge.example', 993, 'ssl', 'it-support@summitridge.example', 1, NULL, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM mailboxes m WHERE m.mailbox_email = 'it-support@summitridge.example');

-- ---------------------------------------------------------------------------------------------
-- 2. Recurring tickets (created before the tickets so one ticket can point back at its schedule)
-- ---------------------------------------------------------------------------------------------
INSERT INTO recurring_tickets (recurring_ticket_category, recurring_ticket_subject, recurring_ticket_details, recurring_ticket_priority,
       recurring_ticket_frequency, recurring_ticket_billable, recurring_ticket_start_date, recurring_ticket_next_run,
       recurring_ticket_created_by, recurring_ticket_assigned_to, recurring_ticket_client_id, recurring_ticket_contact_id, recurring_ticket_asset_id)
SELECT COALESCE((SELECT c.category_id FROM categories c WHERE c.category_type = 'Ticket' AND c.category_name = x.cat AND c.category_archived_at IS NULL LIMIT 1), 0),
       x.subject, x.details, x.pri, x.freq, 0, x.start_date, x.next_run,
       COALESCE((SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'), 0),
       COALESCE((SELECT user_id FROM users WHERE user_email = x.agent), 0),
       ct.contact_client_id, ct.contact_id,
       COALESCE((SELECT a.asset_id FROM assets a WHERE a.asset_name = x.asset AND a.asset_archived_at IS NULL LIMIT 1), 0)
FROM (
  SELECT 'Monthly CAD workstation health check' AS subject,
         '<p>Monthly check of the CAD workstations: run the disk and memory health tests, confirm the graphics driver version, clear temporary files and check that the last backup completed.</p>' AS details,
         'Medium' AS pri, 'Monthly' AS freq,
         DATE_SUB(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 1 DAY), INTERVAL 1 MONTH), INTERVAL 4 MONTH) AS start_date,
         DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 1 DAY), INTERVAL 1 MONTH) AS next_run,
         'Laptops and Desktops' AS cat, 'priya.nair@summitridge.example' AS agent,
         'yuki.tanaka@summitridge.example' AS contact, 'LT-ENG-04' AS asset
  UNION ALL
  SELECT 'Quarterly network closet and UPS inspection - Plant 1',
         '<p>Quarterly walk-through of the network closets at Plant 1: check switch and UPS status lights, test the UPS battery, look for dust or heat problems and make sure cabling is tidy and labelled.</p>',
         'Low', 'Quarterly',
         DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 27 DAY), INTERVAL 6 MONTH),
         DATE_ADD(CURDATE(), INTERVAL 27 DAY),
         'Network', 'marcus.lee@summitridge.example',
         'carlos.mendoza@summitridge.example', ''
) x
JOIN contacts ct ON ct.contact_email = x.contact
WHERE NOT EXISTS (SELECT 1 FROM recurring_tickets r WHERE r.recurring_ticket_subject = x.subject);

-- ---------------------------------------------------------------------------------------------
-- 3. Tickets
-- ---------------------------------------------------------------------------------------------
-- seq is creation order (oldest first) and decides the ticket number. Times are "minutes before
-- now". due_min: minutes from now (negative = overdue), due_today = due this evening.
-- sla_resp / sla_reso: minutes after creation. paused = SLA clock paused (On Hold).
DROP TEMPORARY TABLE IF EXISTS tmp_sd_tickets;
CREATE TEMPORARY TABLE tmp_sd_tickets (
  seq INT PRIMARY KEY,
  subject VARCHAR(500) NOT NULL,
  contact_email VARCHAR(200) NOT NULL,
  category VARCHAR(200) NOT NULL,
  priority VARCHAR(10) NOT NULL,
  status_name VARCHAR(50) NOT NULL,
  assignee VARCHAR(200) NULL,
  creator VARCHAR(200) NULL,
  source VARCHAR(50) NOT NULL,
  asset_name VARCHAR(200) NULL,
  created_min INT NOT NULL,
  resolved_min INT NULL,
  due_min INT NULL,
  due_today TINYINT NOT NULL DEFAULT 0,
  sla_resp INT NULL,
  sla_reso INT NULL,
  paused TINYINT NOT NULL DEFAULT 0,
  onsite TINYINT NOT NULL DEFAULT 0,
  delivery VARCHAR(10) NULL,
  csat INT NULL,
  csat_comment VARCHAR(500) NULL,
  csat_min INT NULL,
  csat_public TINYINT NOT NULL DEFAULT 0,
  merged_into VARCHAR(500) NULL,
  recurring VARCHAR(500) NULL,
  details TEXT NOT NULL
) DEFAULT CHARSET = utf8mb4;

INSERT INTO tmp_sd_tickets VALUES
(1, 'Wireless mouse replacement request', 'ben.carter@summitridge.example', 'Printers and Peripherals', 'Low', 'Open', 'priya.nair@summitridge.example', NULL, 'Portal', NULL,
   12960, NULL, NULL, 0, NULL, NULL, 0, 0, NULL, 2, 'The replacement was the wrong model and I had to ask twice.', 7200, 0, NULL, NULL,
   '<p>My wireless mouse stopped working and a new battery did not help. Could I get a replacement? I sit at the front desk in the sales area.</p>'),
(2, 'Password reset - locked out after the long weekend', 'sophie.tran@summitridge.example', 'Password and MFA', 'Low', 'Closed', 'priya.nair@summitridge.example', NULL, 'Portal', NULL,
   8640, 8605, NULL, 0, 480, 4320, 0, 0, 'Remote', 5, 'Quick and friendly, thanks!', 8500, 1, NULL, NULL,
   '<p>I cannot sign in to my laptop or Outlook this morning. It says my account is locked.</p>'),
(3, 'Outlook keeps prompting for my password at home', 'owen.baker@summitridge.example', 'Microsoft 365', 'Medium', 'Closed', 'marcus.lee@summitridge.example', NULL, 'Email', NULL,
   8000, 6900, NULL, 0, 240, 1440, 0, 0, 'Remote', 4, NULL, 6800, 0, NULL, NULL,
   '<p>Outlook on my home desktop keeps popping up a password prompt every few minutes. I type it in and it comes back.</p>'),
(4, 'New monitor for the accounts payable desk', 'lena.fischer@summitridge.example', 'Laptops and Desktops', 'Low', 'Closed', 'alex.morgan@summitridge.example', NULL, 'Portal', NULL,
   7200, 5660, NULL, 0, 480, 4320, 0, 0, 'Onsite', 5, 'Perfect, thanks Alex.', 5600, 0, NULL, NULL,
   '<p>Grace approved a second monitor for my desk. Could someone bring one over? Any 24-inch or larger is fine.</p>'),
(5, 'Finance floor printer shows Offline for everyone', 'grace.okafor@summitridge.example', 'Printers and Peripherals', 'Medium', 'On Hold', 'priya.nair@summitridge.example', NULL, 'Portal', 'PRN-HQ-01',
   7000, NULL, NULL, 0, 240, 8500, 1, 0, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The main printer on the Finance floor shows Offline for everyone. We need it for the check run on Friday.</p>'),
(6, 'Slow file server access in the afternoons', 'tom.kessler@summitridge.example', 'Network', 'Medium', 'Closed', 'marcus.lee@summitridge.example', NULL, 'Portal', 'SRV-FILE-01',
   6500, 4000, NULL, 0, 240, 1440, 0, 0, 'Remote', 3, 'Better now, but it took a few tries.', 3900, 0, NULL, NULL,
   '<p>Opening files from the shared drive takes 30 seconds or more after about 2 pm. Mornings are fine.</p>'),
(7, 'Desktop restarts randomly in the afternoon', 'miguel.alvarez@summitridge.example', 'Laptops and Desktops', 'Medium', 'Closed', 'priya.nair@summitridge.example', NULL, 'Email', 'DT-HR-01',
   5900, 4250, NULL, 0, 240, 1440, 0, 0, 'Onsite', 5, 'Fixed for good - thank you!', 4200, 0, NULL, NULL,
   '<p>My desktop has restarted by itself three times this week, always in the afternoon. I lose unsaved work each time.</p>'),
(8, 'VPN drops every 20 minutes when working from home', 'owen.baker@summitridge.example', 'VPN and Remote Access', 'High', 'Open', 'marcus.lee@summitridge.example', NULL, 'Portal', 'DT-SALES-03',
   5800, NULL, -1440, 0, 60, 5680, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>My VPN connection drops roughly every 20 minutes and I have to reconnect. It started last week and happens on both my home Wi-Fi and a wired connection.</p>'),
(9, 'Barcode scanner battery replaced under warranty', 'liam.oconnor@summitridge.example', 'Handhelds and Scanners', 'Low', 'Closed', 'marcus.lee@summitridge.example', NULL, 'Portal', NULL,
   5000, 3200, NULL, 0, 480, 4320, 0, 0, 'Onsite', 4, 'Quick swap.', 3100, 0, NULL, NULL,
   '<p>The battery on my handheld scanner only lasts about two hours. The scanner is only a few months old.</p>'),
(10, 'Suspicious invoice email reported by Sales', 'nina.rossi@summitridge.example', 'Other', 'High', 'Closed', 'alex.morgan@summitridge.example', NULL, 'Email', NULL,
   4400, 4250, NULL, 0, 60, 480, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Three of us got an email about an overdue invoice with a zip attachment from an address we do not recognise. I have not opened it.</p>'),
(11, 'Accounts payable printer offline (duplicate report)', 'lena.fischer@summitridge.example', 'Printers and Peripherals', 'Medium', 'Closed', 'priya.nair@summitridge.example', NULL, 'Portal', 'PRN-HQ-01',
   4310, 4290, NULL, 0, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'Finance floor printer shows Offline for everyone', NULL,
   '<p>The printer next to accounts payable shows Offline and nothing prints.</p>'),
(12, 'Warehouse Wi-Fi dead zone near dock door 4', 'frank.delgado@summitridge.example', 'Wi-Fi and Wired', 'Medium', 'Open', 'marcus.lee@summitridge.example', NULL, 'Portal', NULL,
   4300, NULL, NULL, 0, 240, 6300, 0, 1, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The Wi-Fi drops completely near dock door 4. The scanners lose their connection while we load trucks.</p>'),
(13, 'Cannot connect to VPN from hotel Wi-Fi', 'nina.rossi@summitridge.example', 'VPN and Remote Access', 'Medium', 'Open', 'marcus.lee@summitridge.example', NULL, 'Email', NULL,
   4200, NULL, NULL, 0, 240, 4820, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>I am travelling this week and the VPN will not connect from the hotel Wi-Fi. It just says timeout.</p>'),
(14, 'New hire IT setup - Warehouse coordinator starting Monday', 'sophie.tran@summitridge.example', 'New Starters and Leavers', 'Medium', 'Open', 'priya.nair@summitridge.example', 'alex.morgan@summitridge.example', 'Agent', NULL,
   4100, NULL, 5760, 0, 240, 9800, 0, 0, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Please prepare equipment and accounts for the new employee below.</p><ul><li>Name: to be confirmed by HR</li><li>Start date: Monday</li><li>Department: Warehouse &amp; Logistics</li><li>Manager: Frank Delgado</li><li>Equipment needed: laptop and headset</li></ul>'),
(15, 'Voicemail setup on the new desk phone', 'frank.delgado@summitridge.example', 'Other', 'Low', 'Closed', 'priya.nair@summitridge.example', NULL, 'Portal', NULL,
   3800, 3600, NULL, 0, 480, 4320, 0, 0, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>My new desk phone is on the desk but voicemail is not set up. Could someone help me record a greeting?</p>'),
(16, 'Handheld scanner will not sync to the inventory system', 'tara.whitfield@summitridge.example', 'Handhelds and Scanners', 'High', 'Open', 'marcus.lee@summitridge.example', NULL, 'Portal', 'SCAN-WH-02',
   2900, NULL, NULL, 0, 60, 3000, 0, 1, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>My handheld scanner shows "Sync failed" and none of today''s picks are uploading. The warehouse cannot close out shipments.</p>'),
(17, 'Laptop will not boot after Windows update', 'helen.brandt@summitridge.example', 'Laptops and Desktops', 'High', 'Open', 'priya.nair@summitridge.example', 'alex.morgan@summitridge.example', 'Agent', 'LT-EXEC-01',
   2800, NULL, NULL, 1, 60, 3100, 0, 1, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Helen''s laptop restarted for updates last night and now shows a blue screen at startup. She has a board meeting tomorrow morning and needs a working computer.</p>'),
(18, 'Read access to the PLM shared drive', 'ivan.petrov@summitridge.example', 'Access Requests', 'Low', 'Open', 'alex.morgan@summitridge.example', NULL, 'Portal', NULL,
   2700, NULL, NULL, 0, 480, 4320, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Describe the access needed.</p><ul><li>Employee: Ivan Petrov</li><li>System or folder: PLM shared drive (Engineering)</li><li>Level (read / change): read</li><li>Approved by: Yuki Tanaka</li></ul>'),
(19, 'CAD workstation crashes when opening large assemblies', 'maya.singh@summitridge.example', 'Laptops and Desktops', 'Medium', 'Open', 'priya.nair@summitridge.example', NULL, 'Portal', 'LT-ENG-04',
   1900, NULL, NULL, 0, 240, 2800, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>My laptop closes the CAD program without an error message when I open the big gearbox assembly. Smaller files are fine.</p>'),
(20, 'Plant floor PC freezes during inspection scans', 'aisha.rahman@summitridge.example', 'Laptops and Desktops', 'High', 'Open', 'priya.nair@summitridge.example', NULL, 'Portal', 'PC-PLANT-07',
   1600, NULL, NULL, 0, 60, 2020, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The PC at inspection station 3 freezes for about 20 seconds every time we scan a part label. It gets worse toward the end of the shift.</p>'),
(21, 'Label printer prints blank labels', 'jake.sullivan@summitridge.example', 'Printers and Peripherals', 'Medium', 'Open', 'marcus.lee@summitridge.example', NULL, 'Portal', NULL,
   1500, NULL, -180, 0, 240, 1740, 0, 0, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The label printer at the end of line 2 prints blank labels. The roll is loaded and the light is green.</p>'),
(22, 'Monthly CAD workstation health check', 'yuki.tanaka@summitridge.example', 'Laptops and Desktops', 'Medium', 'Open', 'priya.nair@summitridge.example', 'alex.morgan@summitridge.example', 'Recurring', 'LT-ENG-04',
   1490, NULL, NULL, 0, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, 'Monthly CAD workstation health check',
   '<p>Monthly check of the CAD workstations: run the disk and memory health tests, confirm the graphics driver version, clear temporary files and check that the last backup completed.</p>'),
(23, 'Conference room display will not cast', 'raj.patel@summitridge.example', 'Printers and Peripherals', 'Medium', 'Open', 'alex.morgan@summitridge.example', 'alex.morgan@summitridge.example', 'Agent', NULL,
   1300, NULL, NULL, 0, 240, 1440, 0, 0, 'Onsite', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The display in the executive conference room does not offer the cast option from laptops. There is a board call there on Thursday.</p>'),
(24, 'Cannot open the month-end close workbook', 'tom.kessler@summitridge.example', 'Microsoft 365', 'Medium', 'Open', 'alex.morgan@summitridge.example', NULL, 'Portal', 'LT-FIN-02',
   900, NULL, NULL, 1, 240, 1440, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Excel says the month-end close workbook is locked for editing by another user, but nobody else has it open. I need to finish it today.</p>'),
(25, 'Office 365 sign-in prompts on my new phone', 'raj.patel@summitridge.example', 'Password and MFA', 'Medium', 'Open', 'alex.morgan@summitridge.example', 'alex.morgan@summitridge.example', 'Agent', NULL,
   600, NULL, NULL, 0, 240, 1440, 0, 0, 'Remote', NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Raj has a new phone and the authenticator app does not have his account, so he cannot approve sign-ins.</p>'),
(26, 'Quality tablet cannot join the plant Wi-Fi', 'emma.novak@summitridge.example', 'Wi-Fi and Wired', 'Medium', 'New', NULL, NULL, 'Email', NULL,
   300, NULL, NULL, 0, 240, 1440, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>The tablet we use for quality checks says it cannot join the plant Wi-Fi. It worked yesterday.</p>'),
(27, 'Shared folder access for AP invoices', 'lena.fischer@summitridge.example', 'Access Requests', 'Low', 'New', NULL, NULL, 'Portal', NULL,
   200, NULL, NULL, 0, 480, 4320, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>I need access to the AP Invoices folder on the finance drive. Grace said it is fine.</p>'),
(28, 'Extra CAD seat for a contract engineer', 'yuki.tanaka@summitridge.example', 'Line-of-Business Apps', 'Medium', 'New', NULL, NULL, 'Portal', NULL,
   150, NULL, NULL, 0, 240, 1440, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>We are bringing in a contract engineer for six weeks starting on the 12th. Can we get a temporary CAD seat and a workstation for them?</p>'),
(29, 'Request guest Wi-Fi for visiting auditors', 'grace.okafor@summitridge.example', 'Wi-Fi and Wired', 'Low', 'New', NULL, NULL, 'Portal', NULL,
   120, NULL, NULL, 0, 480, 4320, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>Two external auditors will be on site Monday to Wednesday. Can we set up guest Wi-Fi for them?</p>'),
(30, 'Email signature is not updating in Outlook', 'zoe.hartman@summitridge.example', 'Microsoft 365', 'Low', 'New', NULL, NULL, 'Portal', NULL,
   90, NULL, NULL, 0, 480, 4320, 0, 0, NULL, NULL, NULL, NULL, 0, NULL, NULL,
   '<p>I updated my email signature template but Outlook still shows the old one on new messages.</p>');

-- The numbers this run will hand out: start at the app's counter (never below the highest existing number)
SET @sd_prefix := (SELECT config_ticket_prefix FROM settings WHERE company_id = 1 LIMIT 1);
SET @sd_base := GREATEST(
    (SELECT config_ticket_next_number FROM settings WHERE company_id = 1 LIMIT 1),
    COALESCE((SELECT MAX(ticket_number) + 1 FROM tickets WHERE ticket_prefix = @sd_prefix), 1));

DROP TEMPORARY TABLE IF EXISTS tmp_sd_new;
CREATE TEMPORARY TABLE tmp_sd_new (n INT AUTO_INCREMENT PRIMARY KEY, seq INT NOT NULL);
INSERT INTO tmp_sd_new (seq)
SELECT d.seq FROM tmp_sd_tickets d
WHERE NOT EXISTS (SELECT 1 FROM tickets t WHERE t.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject)))
ORDER BY d.seq;
SET @sd_new_count := (SELECT COUNT(*) FROM tmp_sd_new);

INSERT INTO tickets (
    ticket_prefix, ticket_number, ticket_source, ticket_mailbox_id, ticket_category, ticket_subject, ticket_details,
    ticket_priority, ticket_status, ticket_onsite, ticket_delivery_method,
    ticket_csat_rating, ticket_csat_comment, ticket_csat_rated_at, ticket_csat_public_approved,
    ticket_url_key, ticket_created_at, ticket_due_at, ticket_resolved_at, ticket_closed_at,
    ticket_created_by, ticket_assigned_to, ticket_closed_by,
    ticket_client_id, ticket_contact_id, ticket_asset_id, ticket_recurring_ticket_id,
    ticket_sla_response_due, ticket_sla_resolution_due, ticket_sla_paused_at)
SELECT
    @sd_prefix,
    @sd_base + n.n - 1,
    d.source,
    IF(d.source = 'Email', (SELECT m.mailbox_id FROM mailboxes m WHERE m.mailbox_email = 'it-support@summitridge.example' LIMIT 1), NULL),
    CAST(COALESCE((SELECT c.category_id FROM categories c WHERE c.category_type = 'Ticket' AND c.category_name = d.category AND c.category_archived_at IS NULL LIMIT 1), 0) AS CHAR),
    d.subject,
    IF(d.source = 'Email',
       CONCAT('<i>Email from: <b>', ct.contact_name, '</b> &lt;', ct.contact_email, '&gt; at ',
              DATE_FORMAT(NOW() - INTERVAL d.created_min MINUTE, '%Y-%m-%d %H:%i:%s'),
              ':-</i> <br><br><div style=''line-height:1.5;''>', d.details, '</div>'),
       d.details),
    d.priority,
    COALESCE((SELECT s.ticket_status_id FROM ticket_statuses s WHERE s.ticket_status_name = d.status_name LIMIT 1), 1),
    d.onsite,
    d.delivery,
    d.csat,
    d.csat_comment,
    IF(d.csat IS NULL, NULL, NOW() - INTERVAL d.csat_min MINUTE),
    d.csat_public,
    MD5(CONCAT('sd-url-key-', d.subject)),
    NOW() - INTERVAL d.created_min MINUTE,
    CASE WHEN d.due_today = 1 THEN TIMESTAMP(CURDATE(), '22:30:00')
         WHEN d.due_min IS NOT NULL THEN NOW() + INTERVAL d.due_min MINUTE
         ELSE NULL END,
    IF(d.resolved_min IS NULL, NULL, NOW() - INTERVAL d.resolved_min MINUTE),
    IF(d.resolved_min IS NULL, NULL, NOW() - INTERVAL d.resolved_min MINUTE),
    COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = d.creator LIMIT 1), 0),
    COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = d.assignee LIMIT 1), 0),
    IF(d.resolved_min IS NULL, 0, COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = d.assignee LIMIT 1),
                                          (SELECT u.user_id FROM users u WHERE u.user_email = 'alex.morgan@summitridge.example' LIMIT 1), 0)),
    ct.contact_client_id,
    ct.contact_id,
    COALESCE((SELECT a.asset_id FROM assets a WHERE a.asset_name = d.asset_name AND a.asset_archived_at IS NULL LIMIT 1), 0),
    IF(d.recurring IS NULL, 0, COALESCE((SELECT r.recurring_ticket_id FROM recurring_tickets r WHERE r.recurring_ticket_subject = d.recurring LIMIT 1), 0)),
    IF(d.sla_resp IS NULL, NULL, NOW() - INTERVAL d.created_min MINUTE + INTERVAL d.sla_resp MINUTE),
    IF(d.sla_reso IS NULL, NULL, NOW() - INTERVAL d.created_min MINUTE + INTERVAL d.sla_reso MINUTE),
    IF(d.paused = 1, NOW() - INTERVAL 2880 MINUTE, NULL)
FROM tmp_sd_new n
JOIN tmp_sd_tickets d ON d.seq = n.seq
JOIN contacts ct ON ct.contact_email = d.contact_email
ORDER BY n.n;

-- Move the app's counter past the numbers used above (never backwards)
UPDATE settings
   SET config_ticket_next_number = GREATEST(config_ticket_next_number, @sd_base + @sd_new_count)
 WHERE company_id = 1 AND @sd_new_count > 0;

-- ---------------------------------------------------------------------------------------------
-- 4. Replies and notes
-- ---------------------------------------------------------------------------------------------
-- The "Initial Issue" entry the app itself writes when a ticket is first opened: a Client entry
-- (from the person) for portal and email tickets, an Internal one (from the agent) for tickets an
-- agent created. Doing it here means viewing a ticket never writes to the database.
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT CONCAT('<strong>Initial Issue</strong><br>', t.ticket_details),
       IF(t.ticket_created_by = 0, 'Client', 'Internal'),
       t.ticket_created_at,
       IF(t.ticket_created_by = 0, t.ticket_contact_id, t.ticket_created_by),
       t.ticket_id
FROM tickets t
JOIN tmp_sd_tickets d ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject))
WHERE t.ticket_initial_issue_reply_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM ticket_replies r WHERE r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply LIKE '<strong>Initial Issue</strong>%');

UPDATE tickets t
JOIN tmp_sd_tickets d ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject))
JOIN ticket_replies r ON r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply LIKE '<strong>Initial Issue</strong>%'
   SET t.ticket_updated_at = t.ticket_updated_at,
       t.ticket_initial_issue_reply_id = r.ticket_reply_id
 WHERE t.ticket_initial_issue_reply_id IS NULL;

-- Everything after the first message. off_min = minutes after the ticket was created (NULL = at the
-- moment it was closed). by_kind: agent (user), contact (the person), none (automation).
DROP TEMPORARY TABLE IF EXISTS tmp_sd_replies;
CREATE TEMPORARY TABLE tmp_sd_replies (
  subject VARCHAR(500) NOT NULL,
  rtype VARCHAR(20) NOT NULL,
  by_kind VARCHAR(10) NOT NULL,
  by_email VARCHAR(200) NULL,
  off_min INT NULL,
  tw TIME NULL,
  emailed TINYINT NOT NULL DEFAULT 0,
  body TEXT NOT NULL
) DEFAULT CHARSET = utf8mb4;

INSERT INTO tmp_sd_replies VALUES
-- 1  Wireless mouse (closed, rated 2/5, reopened automatically)
('Wireless mouse replacement request', 'Public', 'agent', 'priya.nair@summitridge.example', 90, '00:10:00', 1,
   '<p>Hi Ben,</p><p>We have a replacement mouse in stock and will bring it to your desk this afternoon.</p><p>Priya, IT Support</p>'),
('Wireless mouse replacement request', 'System', 'agent', 'priya.nair@summitridge.example', 2880, '00:01:00', 0, 'Ticket closed.'),
('Wireless mouse replacement request', 'Internal', 'none', NULL, 5760, NULL, 0,
   'Automatically reopened — Ben Carter rated this ticket 2/5: "The replacement was the wrong model and I had to ask twice."'),
('Wireless mouse replacement request', 'Internal', 'agent', 'priya.nair@summitridge.example', 5800, '00:12:00', 0,
   '<p>Called Ben. The stockroom sent a Bluetooth mouse instead of the USB receiver model he had before. Ordering the right one from the supplier, due Thursday.</p>'),
-- 2  Password reset (closed, rated 5/5)
('Password reset - locked out after the long weekend', 'Internal', 'agent', 'priya.nair@summitridge.example', 8, '00:08:00', 0,
   '<p>Account locked after repeated bad-password attempts over the weekend. Unlocked it in the directory and set a temporary password.</p>'),
('Password reset - locked out after the long weekend', 'Public', 'agent', 'priya.nair@summitridge.example', 12, '00:05:00', 1,
   '<p>Hello Sophie,</p><p>Your password has been reset. We will give you the temporary password by phone. Sign in with it and choose a new password straight away.</p><p>If you did not ask for this reset, tell us immediately.</p><p>Priya, IT Support</p>'),
('Password reset - locked out after the long weekend', 'Client', 'contact', 'sophie.tran@summitridge.example', 25, NULL, 0, '<p>That worked, thank you!</p>'),
('Password reset - locked out after the long weekend', 'System', 'agent', 'priya.nair@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 3  Outlook password prompts (closed, rated 4/5)
('Outlook keeps prompting for my password at home', 'Public', 'agent', 'marcus.lee@summitridge.example', 30, '00:10:00', 1,
   '<p>Hi Owen,</p><p>This usually points to a stale saved credential. Could you open Credential Manager, remove any entries that start with "MicrosoftOffice", restart Outlook and tell me how it goes?</p>'),
('Outlook keeps prompting for my password at home', 'Client', 'contact', 'owen.baker@summitridge.example', 200, NULL, 0, '<p>Removed them and restarted. No prompts so far.</p>'),
('Outlook keeps prompting for my password at home', 'Internal', 'agent', 'marcus.lee@summitridge.example', 900, '00:20:00', 0,
   '<p>Cleared the cached credentials and re-created the Outlook profile remotely. No further prompts after 24 hours.</p>'),
('Outlook keeps prompting for my password at home', 'System', 'agent', 'marcus.lee@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 4  New monitor (closed, rated 5/5)
('New monitor for the accounts payable desk', 'Internal', 'agent', 'alex.morgan@summitridge.example', 20, '00:05:00', 0,
   '<p>Approved by Grace Okafor. 27-inch monitor and HDMI cable available from stock.</p>'),
('New monitor for the accounts payable desk', 'Public', 'agent', 'alex.morgan@summitridge.example', 1500, '00:20:00', 0,
   '<p>Monitor delivered and set up at your desk.</p>'),
('New monitor for the accounts payable desk', 'System', 'agent', 'alex.morgan@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 5  Printer offline (On Hold, the duplicate was merged into it)
('Finance floor printer shows Offline for everyone', 'Internal', 'agent', 'priya.nair@summitridge.example', 30, '00:15:00', 0,
   '<p>The printer is reachable on the network but shows a fuser error on its panel. It needs a service visit.</p>'),
('Finance floor printer shows Offline for everyone', 'Public', 'agent', 'priya.nair@summitridge.example', 45, '00:10:00', 1,
   '<p>Hi Grace,</p><p>The printer has a hardware fault that needs a technician visit. Until it is fixed, please use the printer in the HR office. We will keep you posted.</p><p>Priya, IT Support</p>'),
('Finance floor printer shows Offline for everyone', 'Internal', 'agent', 'priya.nair@summitridge.example', 60, '00:05:00', 0,
   '<p>Vendor visit booked for Thursday morning. Setting the ticket to On Hold until then.</p>'),
('Finance floor printer shows Offline for everyone', 'Client', 'contact', 'grace.okafor@summitridge.example', 1400, NULL, 0,
   '<p>Thanks, we are printing checks from the HR office for now.</p>'),
-- 6  Slow file server (closed, rated 3/5)
('Slow file server access in the afternoons', 'Internal', 'agent', 'marcus.lee@summitridge.example', 120, '00:45:00', 0,
   '<p>Backup verification and the antivirus scan both start at 1:30 pm and compete for disk. Moved the scan to 2:00 am and the backup verification to 1:00 am.</p>'),
('Slow file server access in the afternoons', 'Public', 'agent', 'marcus.lee@summitridge.example', 180, '00:10:00', 1,
   '<p>Hi Tom,</p><p>We found two maintenance jobs that overlapped with the busy part of the day and moved them to the early morning. Please tell us if the drive is still slow tomorrow afternoon.</p>'),
('Slow file server access in the afternoons', 'Client', 'contact', 'tom.kessler@summitridge.example', 1500, NULL, 0, '<p>Much better today, thanks.</p>'),
('Slow file server access in the afternoons', 'System', 'agent', 'marcus.lee@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 7  Desktop restarts (closed, rated 5/5)
('Desktop restarts randomly in the afternoon', 'Internal', 'agent', 'priya.nair@summitridge.example', 60, '00:20:00', 0,
   '<p>The event log shows power events with no clean shutdown. Running a memory test overnight.</p>'),
('Desktop restarts randomly in the afternoon', 'Internal', 'agent', 'priya.nair@summitridge.example', 1500, '00:50:00', 0,
   '<p>The memory test found errors on one module. Replaced it with a spare and ran the test again with no errors.</p>'),
('Desktop restarts randomly in the afternoon', 'Public', 'agent', 'priya.nair@summitridge.example', 1600, '00:05:00', 1,
   '<p>Hi Miguel,</p><p>We replaced a faulty memory module in your desktop. It should no longer restart. Please let us know if it does.</p><p>Priya, IT Support</p>'),
('Desktop restarts randomly in the afternoon', 'System', 'agent', 'priya.nair@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 8  VPN drops (open, overdue, linked to a problem)
('VPN drops every 20 minutes when working from home', 'Public', 'agent', 'marcus.lee@summitridge.example', 40, '00:10:00', 1,
   '<p>Hi Owen,</p><p>Thanks for the detail. Which VPN client version are you using, and does the drop happen while you are idle or during a call?</p><p>Marcus, IT Support</p>'),
('VPN drops every 20 minutes when working from home', 'Client', 'contact', 'owen.baker@summitridge.example', 130, NULL, 0,
   '<p>Version 5.2. It drops even while I am typing, so it is not only when I am idle.</p>'),
('VPN drops every 20 minutes when working from home', 'Internal', 'agent', 'marcus.lee@summitridge.example', 400, '00:40:00', 0,
   '<p>The firewall logs show keepalive timeouts every 20 minutes for six remote users. Looks like the same firmware issue. Opening a problem record so the tickets can be tracked together.</p>'),
('VPN drops every 20 minutes when working from home', 'Internal', 'agent', 'marcus.lee@summitridge.example', 2400, '00:15:00', 0,
   '<p>Firmware change is scheduled. Asked Owen to use the split-tunnel profile as a workaround until then.</p>'),
('VPN drops every 20 minutes when working from home', 'Automation', 'none', NULL, 5690, NULL, 0,
   'Automation rule "Escalate breached resolution SLA" raised the priority to High and notified Marcus Lee.'),
-- 9  Barcode scanner battery (closed, rated 4/5)
('Barcode scanner battery replaced under warranty', 'Internal', 'agent', 'marcus.lee@summitridge.example', 45, '00:25:00', 0,
   '<p>Battery health is at 62 percent and the scanner is still under warranty. Swapped in a battery from spare stock and logged the return.</p>'),
('Barcode scanner battery replaced under warranty', 'Public', 'agent', 'marcus.lee@summitridge.example', 60, '00:05:00', 1,
   '<p>Hi Liam, we swapped the battery at the dock desk. Please tell us if the run time is still short.</p>'),
('Barcode scanner battery replaced under warranty', 'System', 'agent', 'marcus.lee@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 10  Phishing report (closed, no rating)
('Suspicious invoice email reported by Sales', 'Internal', 'agent', 'alex.morgan@summitridge.example', 15, '00:30:00', 0,
   '<p>Confirmed phishing. Blocked the sender domain, removed the message from 3 mailboxes and checked that nobody opened the attachment.</p>'),
('Suspicious invoice email reported by Sales', 'Public', 'agent', 'alex.morgan@summitridge.example', 25, '00:10:00', 1,
   '<p>Hi Nina,</p><p>Thank you for reporting this and for not opening the attachment. We have removed the message from all mailboxes. Please keep forwarding anything suspicious to IT.</p>'),
('Suspicious invoice email reported by Sales', 'System', 'agent', 'alex.morgan@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 12  Wi-Fi dead zone
('Warehouse Wi-Fi dead zone near dock door 4', 'Internal', 'agent', 'marcus.lee@summitridge.example', 90, '00:35:00', 0,
   '<p>Walked the dock with a Wi-Fi analyser: the signal is about -78 dBm at door 4. It needs another access point. Adding this to the Wi-Fi coverage problem record.</p>'),
('Warehouse Wi-Fi dead zone near dock door 4', 'Public', 'agent', 'marcus.lee@summitridge.example', 120, '00:05:00', 0,
   '<p>Hi Frank, we measured the signal at door 4 and confirmed it is weak. We are planning an extra access point and will confirm a date.</p>'),
-- 13  VPN from hotel Wi-Fi
('Cannot connect to VPN from hotel Wi-Fi', 'Public', 'agent', 'marcus.lee@summitridge.example', 50, '00:10:00', 1,
   '<p>Hi Nina,</p><p>Hotel networks often block the standard VPN port. Try connecting through your phone hotspot and tell me whether that works.</p><p>Marcus, IT Support</p>'),
('Cannot connect to VPN from hotel Wi-Fi', 'Client', 'contact', 'nina.rossi@summitridge.example', 180, NULL, 0, '<p>It works on my phone hotspot.</p>'),
('Cannot connect to VPN from hotel Wi-Fi', 'Internal', 'agent', 'marcus.lee@summitridge.example', 200, '00:05:00', 0,
   '<p>Same firewall issue as the other VPN tickets. Linking this ticket to the VPN problem record.</p>'),
-- 14  New hire setup
('New hire IT setup - Warehouse coordinator starting Monday', 'Internal', 'agent', 'alex.morgan@summitridge.example', 5, '00:01:00', 0, 'Ticket re-assigned to Priya Nair.'),
('New hire IT setup - Warehouse coordinator starting Monday', 'Internal', 'agent', 'priya.nair@summitridge.example', 600, '00:35:00', 0,
   '<p>Laptop imaged and the asset tag recorded. Waiting on HR for the employee ID before creating the accounts.</p>'),
('New hire IT setup - Warehouse coordinator starting Monday', 'Client', 'contact', 'sophie.tran@summitridge.example', 1500, NULL, 0,
   '<p>The employee ID is on its way from payroll. I will send it this afternoon.</p>'),
-- 15  Voicemail (closed, no rating)
('Voicemail setup on the new desk phone', 'Public', 'agent', 'priya.nair@summitridge.example', 30, '00:10:00', 0,
   '<p>Voicemail is set up. Dial the voicemail number from your phone to record your greeting and choose a PIN.</p>'),
('Voicemail setup on the new desk phone', 'System', 'agent', 'priya.nair@summitridge.example', NULL, '00:01:00', 0, 'Ticket closed.'),
-- 16  Scanner sync (open, high, on-site visit booked)
('Handheld scanner will not sync to the inventory system', 'Public', 'agent', 'marcus.lee@summitridge.example', 20, '00:10:00', 1,
   '<p>Hi Tara,</p><p>Please put the scanner in its cradle and leave it powered on. I will come by tomorrow morning to check it and update the app.</p><p>Marcus, IT Support</p>'),
('Handheld scanner will not sync to the inventory system', 'Internal', 'agent', 'marcus.lee@summitridge.example', 30, '00:15:00', 0,
   '<p>Other scanners on the same access point sync normally. Suspect the scanner''s app or a stored Wi-Fi profile.</p>'),
('Handheld scanner will not sync to the inventory system', 'Client', 'contact', 'tara.whitfield@summitridge.example', 200, NULL, 0, '<p>The cradle is charging it. Thanks!</p>'),
-- 17  Laptop will not boot (the fully worked example)
('Laptop will not boot after Windows update', 'Internal', 'agent', 'alex.morgan@summitridge.example', 5, '00:01:00', 0, 'Ticket re-assigned to Priya Nair.'),
('Laptop will not boot after Windows update', 'Public', 'agent', 'priya.nair@summitridge.example', 25, '00:05:00', 1,
   '<p>Hello Helen,</p><p>Thanks for contacting IT. We have your request and a technician will pick it up shortly. You can reply to this message at any time to add more detail or attachments.</p><p>Please leave the laptop powered off until we reach you.</p><p>Summit Ridge IT Support</p>'),
('Laptop will not boot after Windows update', 'Internal', 'agent', 'priya.nair@summitridge.example', 60, '00:45:00', 0,
   '<p>Booted from recovery media and retrieved the BitLocker recovery key. The last cumulative update failed part-way and damaged the boot files. Running startup repair, then rolling back the update if that fails.</p>'),
('Laptop will not boot after Windows update', 'Client', 'contact', 'helen.brandt@summitridge.example', 120, NULL, 0,
   '<p>Thank you. I will be in the office until 6 pm if you need to collect it.</p>'),
('Laptop will not boot after Windows update', 'Public', 'agent', 'priya.nair@summitridge.example', 300, '00:10:00', 1,
   '<p>Hi Helen,</p><p>The repair is running and looks promising. I have also prepared a loaner laptop so you are covered for tomorrow whatever happens. I will confirm by the end of the day.</p><p>Priya, IT Support</p>'),
('Laptop will not boot after Windows update', 'Labor', 'agent', 'priya.nair@summitridge.example', 330, '00:30:00', 0, 'Time logged'),
-- 18  PLM access request
('Read access to the PLM shared drive', 'Internal', 'agent', 'alex.morgan@summitridge.example', 20, '00:05:00', 0,
   '<p>Manager approval confirmed by email from Yuki Tanaka.</p>'),
('Read access to the PLM shared drive', 'Internal', 'agent', 'alex.morgan@summitridge.example', 60, '00:10:00', 0,
   '<p>Added Ivan to the PLM-Readers group. Waiting for the directory sync before telling him.</p>'),
-- 19  CAD crashes (tasks and live chat)
('CAD workstation crashes when opening large assemblies', 'Public', 'agent', 'priya.nair@summitridge.example', 30, '00:05:00', 1,
   '<p>Hello Maya,</p><p>To help us fix this quickly, could you tell us:</p><ul><li>What you were doing when the problem started</li><li>The exact wording of any error message</li><li>Whether it also happens on another device</li></ul><p>Reply to this ticket with the details and we will carry on from there.</p>'),
('CAD workstation crashes when opening large assemblies', 'Client', 'contact', 'maya.singh@summitridge.example', 90, NULL, 0,
   '<p>It started on Monday after the latest CAD update. The file is the GB-2210 top assembly. There is no error, the program just closes. I have not tried another device.</p>'),
('CAD workstation crashes when opening large assemblies', 'Internal', 'agent', 'priya.nair@summitridge.example', 200, '00:30:00', 0,
   '<p>The event log shows the CAD program running out of memory as the assembly loads, and the graphics driver is two versions behind. Working through the task list.</p>'),
-- 20  Plant floor PC
('Plant floor PC freezes during inspection scans', 'Public', 'agent', 'priya.nair@summitridge.example', 20, '00:05:00', 1,
   '<p>Hi Aisha,</p><p>Thanks. I will connect remotely during the next break to look at it, and I may need to visit the station later today.</p><p>Priya, IT Support</p>'),
('Plant floor PC freezes during inspection scans', 'Internal', 'agent', 'priya.nair@summitridge.example', 120, '00:40:00', 0,
   '<p>Disk usage is at 100 percent during scans. The inspection app writes a log file on every scan and the drive has under 5 percent free. Cleaning out old logs and checking the drive health.</p>'),
-- 21  Label printer
('Label printer prints blank labels', 'Internal', 'agent', 'marcus.lee@summitridge.example', 60, '00:15:00', 0,
   '<p>The printer is a thermal-transfer model and the ribbon is missing. Will bring a ribbon and clean the print head.</p>'),
('Label printer prints blank labels', 'Public', 'agent', 'marcus.lee@summitridge.example', 65, '00:05:00', 1,
   '<p>Hi Jake, I will be at line 2 this afternoon with a new ribbon. Please keep the printer powered on.</p><p>Marcus, IT Support</p>'),
-- 23  Conference room display
('Conference room display will not cast', 'Public', 'agent', 'alex.morgan@summitridge.example', 30, '00:05:00', 1,
   '<p>Hi Raj, thanks for letting us know. I will test the room before Thursday.</p><p>Alex, IT</p>'),
('Conference room display will not cast', 'Internal', 'agent', 'alex.morgan@summitridge.example', 200, '00:15:00', 0,
   '<p>The room system firmware is out of date. Scheduled the update for Wednesday evening.</p>'),
-- 24  Month-end workbook (waiting for the agent: the last message is from the person)
('Cannot open the month-end close workbook', 'Public', 'agent', 'alex.morgan@summitridge.example', 120, '00:05:00', 1,
   '<p>Hello Tom,</p><p>To help us fix this quickly, could you tell us:</p><ul><li>What you were doing when the problem started</li><li>The exact wording of any error message</li><li>Whether it also happens on another device</li></ul><p>Reply to this ticket with the details and we will carry on from there.</p>'),
('Cannot open the month-end close workbook', 'Client', 'contact', 'tom.kessler@summitridge.example', 300, NULL, 0,
   '<p>It is on the finance share, "Close_Sep.xlsx". The message says it is locked by grace.okafor. No, I have not tried another device.</p>'),
-- 25  MFA on a new phone
('Office 365 sign-in prompts on my new phone', 'Public', 'agent', 'alex.morgan@summitridge.example', 15, '00:05:00', 1,
   '<p>Hi Raj, I will reset your multi-factor registration so you can add the new phone. Please have it with you.</p><p>Alex, IT</p>'),
('Office 365 sign-in prompts on my new phone', 'Internal', 'agent', 'alex.morgan@summitridge.example', 30, '00:10:00', 0,
   '<p>Reset the MFA registration in the admin centre. Raj will register the new phone from the security info page.</p>');

INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_emailed)
SELECT r.body, r.rtype, r.tw,
       CASE WHEN r.off_min IS NULL THEN t.ticket_resolved_at ELSE t.ticket_created_at + INTERVAL r.off_min MINUTE END,
       CASE r.by_kind
            WHEN 'agent'   THEN COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = r.by_email LIMIT 1), 0)
            WHEN 'contact' THEN COALESCE((SELECT c.contact_id FROM contacts c WHERE c.contact_email = r.by_email LIMIT 1), 0)
            ELSE 0 END,
       t.ticket_id,
       r.emailed
FROM tmp_sd_replies r
JOIN tickets t ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', r.subject))
WHERE NOT EXISTS (SELECT 1 FROM ticket_replies x WHERE x.ticket_reply_ticket_id = t.ticket_id AND x.ticket_reply = r.body);

-- ---------------------------------------------------------------------------------------------
-- 5. Merge: the duplicate printer ticket was merged into the main one
-- ---------------------------------------------------------------------------------------------
UPDATE tickets c
JOIN tmp_sd_tickets d ON c.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject))
JOIN tickets p ON p.ticket_url_key = MD5(CONCAT('sd-url-key-', d.merged_into))
   SET c.ticket_updated_at = c.ticket_updated_at,
       c.ticket_merged_into_id = p.ticket_id
 WHERE d.merged_into IS NOT NULL AND c.ticket_merged_into_id IS NULL;

INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT CONCAT('Ticket ', c.ticket_prefix, c.ticket_number, ' merged into <a href="ticket.php?ticket_id=', p.ticket_id, '">', p.ticket_prefix, p.ticket_number, '</a>. Comment: Duplicate report from the same floor.'),
       'System', '00:01:00', c.ticket_closed_at - INTERVAL 1 MINUTE,
       COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = 'priya.nair@summitridge.example'), 0), c.ticket_id
FROM tickets c
JOIN tickets p ON p.ticket_id = c.ticket_merged_into_id
WHERE c.ticket_url_key = MD5('sd-url-key-Accounts payable printer offline (duplicate report)')
  AND NOT EXISTS (SELECT 1 FROM ticket_replies x WHERE x.ticket_reply_ticket_id = c.ticket_id AND x.ticket_reply LIKE 'Ticket % merged into %');

INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT CONCAT('Ticket ', c.ticket_prefix, c.ticket_number, ' was merged into this ticket with comment: Duplicate report from the same floor.<br><br><b>', c.ticket_subject, '</b><br>', c.ticket_details),
       'System', '00:01:00', c.ticket_closed_at - INTERVAL 1 MINUTE,
       COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = 'priya.nair@summitridge.example'), 0), p.ticket_id
FROM tickets c
JOIN tickets p ON p.ticket_id = c.ticket_merged_into_id
WHERE c.ticket_url_key = MD5('sd-url-key-Accounts payable printer offline (duplicate report)')
  AND NOT EXISTS (SELECT 1 FROM ticket_replies x WHERE x.ticket_reply_ticket_id = p.ticket_id AND x.ticket_reply LIKE CONCAT('Ticket ', c.ticket_prefix, c.ticket_number, ' was merged into this ticket%'));

-- ---------------------------------------------------------------------------------------------
-- 6. Tasks, tags, watchers, extra technicians, appointments, live chat
-- ---------------------------------------------------------------------------------------------

-- Checklists (the wording matches the "New Hire IT Setup" and "Access Request" template tasks)
INSERT INTO tasks (task_name, task_order, task_completion_estimate, task_ticket_id, task_created_at)
SELECT x.n, x.o, x.m, t.ticket_id, t.ticket_created_at
FROM (
  SELECT 'New hire IT setup - Warehouse coordinator starting Monday' AS subj, 'Create the user account and mailbox' AS n, 0 AS o, 20 AS m
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Assign and image a laptop', 1, 60
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Add to department groups and shared drives', 2, 15
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Set up phone extension', 3, 15
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Enrol in Cybersecurity Awareness training', 4, 10
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Hand over equipment on day one', 5, 30
  UNION ALL SELECT 'Read access to the PLM shared drive', 'Confirm the manager approved the request', 0, 5
  UNION ALL SELECT 'Read access to the PLM shared drive', 'Grant the access', 1, 10
  UNION ALL SELECT 'Read access to the PLM shared drive', 'Confirm with the employee that it works', 2, 10
  UNION ALL SELECT 'CAD workstation crashes when opening large assemblies', 'Update the graphics driver', 0, 20
  UNION ALL SELECT 'CAD workstation crashes when opening large assemblies', 'Run a memory diagnostic', 1, 30
  UNION ALL SELECT 'CAD workstation crashes when opening large assemblies', 'Reinstall the CAD software if the crash continues', 2, 60
) x
JOIN tickets t ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', x.subj))
WHERE NOT EXISTS (SELECT 1 FROM tasks k WHERE k.task_ticket_id = t.ticket_id AND k.task_name = x.n);

-- Mark the first two tasks of each checklist done
UPDATE tasks k
JOIN tickets t ON t.ticket_id = k.task_ticket_id
   SET k.task_updated_at = k.task_updated_at,
       k.task_completed_at = t.ticket_created_at + INTERVAL 500 MINUTE,
       k.task_completed_by = t.ticket_assigned_to
 WHERE k.task_completed_at IS NULL AND k.task_order IN (0, 1)
   AND t.ticket_url_key IN (MD5('sd-url-key-New hire IT setup - Warehouse coordinator starting Monday'),
                            MD5('sd-url-key-Read access to the PLM shared drive'),
                            MD5('sd-url-key-CAD workstation crashes when opening large assemblies'));

-- Tags
INSERT INTO ticket_tags (ticket_tag_ticket_id, ticket_tag_tag_id)
SELECT t.ticket_id, g.tag_id
FROM (
  SELECT 'Laptop will not boot after Windows update' AS subj, 'VIP' AS tag
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'Onboarding'
  UNION ALL SELECT 'Finance floor printer shows Offline for everyone', 'Waiting on vendor'
  UNION ALL SELECT 'Suspicious invoice email reported by Sales', 'Security'
  UNION ALL SELECT 'Office 365 sign-in prompts on my new phone', 'Security'
  UNION ALL SELECT 'VPN drops every 20 minutes when working from home', 'Remote work'
  UNION ALL SELECT 'Cannot connect to VPN from hotel Wi-Fi', 'Remote work'
) x
JOIN tickets t ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', x.subj))
JOIN tags g ON g.tag_type = 6 AND g.tag_name = x.tag
WHERE NOT EXISTS (SELECT 1 FROM ticket_tags k WHERE k.ticket_tag_ticket_id = t.ticket_id AND k.ticket_tag_tag_id = g.tag_id);

-- Watchers (people who are copied on public updates)
INSERT INTO ticket_watchers (watcher_email, watcher_ticket_id)
SELECT x.email, t.ticket_id
FROM (
  SELECT 'Laptop will not boot after Windows update' AS subj, 'raj.patel@summitridge.example' AS email
  UNION ALL SELECT 'VPN drops every 20 minutes when working from home', 'nina.rossi@summitridge.example'
  UNION ALL SELECT 'Finance floor printer shows Offline for everyone', 'lena.fischer@summitridge.example'
  UNION ALL SELECT 'New hire IT setup - Warehouse coordinator starting Monday', 'frank.delgado@summitridge.example'
  UNION ALL SELECT 'Handheld scanner will not sync to the inventory system', 'frank.delgado@summitridge.example'
) x
JOIN tickets t ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', x.subj))
WHERE NOT EXISTS (SELECT 1 FROM ticket_watchers w WHERE w.watcher_ticket_id = t.ticket_id AND w.watcher_email = x.email);

-- Additional asset on the VPN ticket (only when the asset exists)
INSERT INTO ticket_assets (ticket_id, asset_id)
SELECT t.ticket_id, a.asset_id
FROM tickets t
JOIN assets a ON a.asset_name = 'FW-EDGE-01' AND a.asset_archived_at IS NULL
WHERE t.ticket_url_key = MD5('sd-url-key-VPN drops every 20 minutes when working from home')
  AND NOT EXISTS (SELECT 1 FROM ticket_assets k WHERE k.ticket_id = t.ticket_id AND k.asset_id = a.asset_id);

-- A second technician on the scanner ticket
INSERT INTO ticket_techs (tech_ticket_id, tech_user_id, tech_created_by, tech_created_at)
SELECT t.ticket_id, u.user_id, (SELECT a.user_id FROM users a WHERE a.user_email = 'marcus.lee@summitridge.example'), t.ticket_created_at + INTERVAL 40 MINUTE
FROM tickets t
JOIN users u ON u.user_email = 'priya.nair@summitridge.example'
WHERE t.ticket_url_key = MD5('sd-url-key-Handheld scanner will not sync to the inventory system')
  AND NOT EXISTS (SELECT 1 FROM ticket_techs k WHERE k.tech_ticket_id = t.ticket_id AND k.tech_user_id = u.user_id);

-- Appointments: an on-site visit tomorrow for two technicians, and a remote session this afternoon
INSERT INTO ticket_schedules (schedule_ticket_id, schedule_start, schedule_end, schedule_onsite, schedule_tech_id, schedule_notes, schedule_created_by)
SELECT t.ticket_id, TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00'), 1, u.user_id,
       'Bring a spare scanner and a charged battery. Ask Tara for the dock office door code.',
       (SELECT a.user_id FROM users a WHERE a.user_email = 'marcus.lee@summitridge.example')
FROM tickets t
JOIN users u ON u.user_email IN ('marcus.lee@summitridge.example', 'priya.nair@summitridge.example')
WHERE t.ticket_url_key = MD5('sd-url-key-Handheld scanner will not sync to the inventory system')
  AND NOT EXISTS (SELECT 1 FROM ticket_schedules s WHERE s.schedule_ticket_id = t.ticket_id AND s.schedule_tech_id = u.user_id AND s.schedule_archived_at IS NULL);

INSERT INTO ticket_schedules (schedule_ticket_id, schedule_start, schedule_end, schedule_onsite, schedule_tech_id, schedule_notes, schedule_created_by)
SELECT t.ticket_id, TIMESTAMP(CURDATE(), '15:00:00'), TIMESTAMP(CURDATE(), '15:30:00'), 0, u.user_id,
       'Remote session during the afternoon break.', u.user_id
FROM tickets t
JOIN users u ON u.user_email = 'priya.nair@summitridge.example'
WHERE t.ticket_url_key = MD5('sd-url-key-Plant floor PC freezes during inspection scans')
  AND NOT EXISTS (SELECT 1 FROM ticket_schedules s WHERE s.schedule_ticket_id = t.ticket_id AND s.schedule_archived_at IS NULL);

-- Live chat on the CAD ticket (chat is a side channel: messages are not part of the reply thread)
INSERT INTO ticket_chat_messages (ticket_id, sender_type, sender_id, message, created_at)
SELECT t.ticket_id, x.stype,
       CASE x.stype WHEN 'agent' THEN COALESCE((SELECT u.user_id FROM users u WHERE u.user_email = 'priya.nair@summitridge.example'), 0)
                    ELSE COALESCE((SELECT c.contact_id FROM contacts c WHERE c.contact_email = 'maya.singh@summitridge.example'), 0) END,
       x.msg, t.ticket_created_at + INTERVAL x.off MINUTE
FROM (
  SELECT 'contact' AS stype, 'Hi Priya, are you able to look at my laptop this afternoon?' AS msg, 240 AS off
  UNION ALL SELECT 'agent', 'Yes. I am connecting remotely at 2 pm, so please keep it on and plugged in.', 243
  UNION ALL SELECT 'contact', 'Great, thanks!', 245
) x
JOIN tickets t ON t.ticket_url_key = MD5('sd-url-key-CAD workstation crashes when opening large assemblies')
WHERE NOT EXISTS (SELECT 1 FROM ticket_chat_messages m WHERE m.ticket_id = t.ticket_id AND m.message = x.msg);

-- ---------------------------------------------------------------------------------------------
-- 7. Finishing touches on the tickets themselves
-- ---------------------------------------------------------------------------------------------
-- First response = the first public reply an agent sent
UPDATE tickets t
JOIN tmp_sd_tickets d ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject))
   SET t.ticket_updated_at = t.ticket_updated_at,
       t.ticket_first_response_at = (SELECT MIN(r.ticket_reply_created_at) FROM ticket_replies r
                                     WHERE r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply_type = 'Public')
 WHERE t.ticket_first_response_at IS NULL;

-- Last update = the newest reply (left empty for tickets nobody has touched yet, which the list shows in bold)
UPDATE tickets t
JOIN tmp_sd_tickets d ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', d.subject))
   SET t.ticket_updated_at = (SELECT MAX(r.ticket_reply_created_at) FROM ticket_replies r
                              WHERE r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply_id <> t.ticket_initial_issue_reply_id)
 WHERE t.ticket_updated_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- 8. Problems and changes
-- ---------------------------------------------------------------------------------------------
INSERT INTO changes (title, reason, impact, risk, implementation_plan, rollback_plan, scheduled_at, status, created_by, created_at)
SELECT x.title, x.reason, x.impact, x.risk, x.impl, x.rollback,
       IF(x.sched_days IS NULL, NULL, TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL x.sched_days DAY), x.sched_time)),
       x.status,
       (SELECT u.user_id FROM users u WHERE u.user_email = x.author LIMIT 1),
       NOW() - INTERVAL x.age_days DAY
FROM (
  SELECT 'Update the edge firewall firmware to fix VPN keepalive drops' AS title,
         'Remote users lose their VPN connection every 20 minutes. The vendor advisory describes a keepalive bug that is fixed in the newer firmware.' AS reason,
         'All VPN sessions drop for about 10 minutes while the firewall restarts. Staff on site keep working because the internet link fails over.' AS impact,
         'medium' AS risk,
         '1. Back up the firewall configuration.\n2. Upload the new firmware and schedule the reboot.\n3. Confirm the VPN reconnects and the keepalive timers stay stable.\n4. Tell remote staff the work is done.' AS impl,
         'Restore the saved configuration and boot from the previous firmware image (kept on the device).' AS rollback,
         3 AS sched_days, '22:00:00' AS sched_time, 'scheduled' AS status, 'marcus.lee@summitridge.example' AS author, 3 AS age_days
  UNION ALL
  SELECT 'Roll out the September Windows feature update in two waves',
         'Several laptops are behind on the feature update and will fall out of support next quarter.',
         'Each laptop restarts once and takes about 30 minutes to finish. Users are asked to save their work before leaving for the day.',
         'medium',
         '1. Wave one: IT and Finance laptops.\n2. Review problems for two days.\n3. Wave two: all other laptops.',
         'Uninstall the feature update from Settings within the first 10 days, or restore the laptop from the last image.',
         NULL, NULL, 'awaiting_approval', 'priya.nair@summitridge.example', 1
  UNION ALL
  SELECT 'Replace the core switch at Headquarters',
         'The core switch is 9 years old, out of vendor support and has begun to reboot on its own.',
         'Network outage of about 45 minutes across Headquarters, outside working hours.',
         'high',
         '1. Stage and configure the replacement switch in the lab.\n2. Schedule the maintenance window and warn all departments.\n3. Move cables one closet at a time and test each port group.',
         'Put the old switch back with its saved configuration and re-test the uplinks.',
         -12, '19:00:00', 'successful', 'marcus.lee@summitridge.example', 30
) x
WHERE NOT EXISTS (SELECT 1 FROM changes c WHERE c.title = x.title);

INSERT INTO problems (title, description, status, change_problem_id, created_by, created_at, resolved_at)
SELECT x.title, x.descr, x.status,
       (SELECT c.change_id FROM changes c WHERE c.title = x.change_title LIMIT 1),
       (SELECT u.user_id FROM users u WHERE u.user_email = x.author LIMIT 1),
       NOW() - INTERVAL x.age_days DAY,
       NULL
FROM (
  SELECT 'Intermittent VPN disconnects for remote staff' AS title,
         'Six remote users report the VPN dropping about every 20 minutes. Firewall logs show keepalive timeouts at that interval, and the vendor advisory points to a known bug in the current firmware.\n\nWorkaround: use the split-tunnel VPN profile until the firmware change is done.' AS descr,
         'investigating' AS status,
         'Update the edge firewall firmware to fix VPN keepalive drops' AS change_title,
         'marcus.lee@summitridge.example' AS author, 4 AS age_days
  UNION ALL
  SELECT 'Weak Wi-Fi coverage at the Milwaukee distribution center',
         'Handheld scanners lose their connection near dock doors 3 and 4 and in the north aisle. A signal survey shows readings below -75 dBm in those areas, which is why scanners fail to sync.',
         'open',
         NULL,
         'marcus.lee@summitridge.example', 3
) x
WHERE NOT EXISTS (SELECT 1 FROM problems p WHERE p.title = x.title);

-- Link tickets to problems
UPDATE tickets t
JOIN (
  SELECT 'VPN drops every 20 minutes when working from home' AS subj, 'Intermittent VPN disconnects for remote staff' AS problem
  UNION ALL SELECT 'Cannot connect to VPN from hotel Wi-Fi', 'Intermittent VPN disconnects for remote staff'
  UNION ALL SELECT 'Warehouse Wi-Fi dead zone near dock door 4', 'Weak Wi-Fi coverage at the Milwaukee distribution center'
  UNION ALL SELECT 'Handheld scanner will not sync to the inventory system', 'Weak Wi-Fi coverage at the Milwaukee distribution center'
) x ON t.ticket_url_key = MD5(CONCAT('sd-url-key-', x.subj))
JOIN problems p ON p.title = x.problem
   SET t.ticket_updated_at = t.ticket_updated_at,
       t.ticket_problem_id = p.problem_id
 WHERE t.ticket_problem_id IS NULL;

-- ---------------------------------------------------------------------------------------------
-- 9. Requests (emails from senders the app does not know, waiting for review)
-- ---------------------------------------------------------------------------------------------
INSERT INTO mail_requests (mail_request_mailbox_id, mail_request_from_email, mail_request_from_name, mail_request_subject, mail_request_body, mail_request_ccs, mail_request_received_at)
SELECT COALESCE((SELECT m.mailbox_id FROM mailboxes m WHERE m.mailbox_email = 'it-support@summitridge.example' LIMIT 1), 0),
       x.email, x.name, x.subject, x.body, x.ccs, NOW() - INTERVAL x.age_min MINUTE
FROM (
  SELECT 'dana.reyes@brightpath-consulting.example' AS email, 'Dana Reyes' AS name,
         'Contractor starting Monday - laptop and Wi-Fi access?' AS subject,
         '<p>Hi IT team,</p><p>I am a contractor starting with Engineering on Monday. Yuki Tanaka said you would set up a laptop and Wi-Fi access for me. Please let me know what you need from me beforehand.</p><p>Thanks,<br>Dana Reyes</p>' AS body,
         'yuki.tanaka@summitridge.example' AS ccs, 180 AS age_min
  UNION ALL
  SELECT 'service@lakeviewcopiers.example', 'Lakeview Copier Service',
         'Service visit confirmation - Tuesday 9:00 AM',
         '<p>This confirms that our technician will visit on Tuesday between 9:00 and 11:00 to service the copier on the Finance floor. Please make sure someone can open the copier room.</p><p>Lakeview Copier Service</p>',
         '', 1500
  UNION ALL
  SELECT 'jake.sullivan.home@webmail.example', 'Jake S',
         'Locked out of my account - writing from my personal email',
         '<p>I cannot sign in to the terminal on the line and my work email will not open. Please help, the line supervisor is waiting on my inspection sign-off.</p><p>Jake Sullivan<br>Machine Operator, Production</p>',
         '', 2900
) x
WHERE NOT EXISTS (SELECT 1 FROM mail_requests r WHERE r.mail_request_from_email = x.email AND r.mail_request_subject = x.subject);

INSERT INTO mail_request_attachments (mail_request_attachment_name, mail_request_attachment_reference_name, mail_request_attachment_mail_request_id)
SELECT 'Service-Order-2291.pdf', 'demo-service-order-2291.bin', r.mail_request_id
FROM mail_requests r
WHERE r.mail_request_from_email = 'service@lakeviewcopiers.example'
  AND r.mail_request_subject = 'Service visit confirmation - Tuesday 9:00 AM'
  AND NOT EXISTS (SELECT 1 FROM mail_request_attachments a WHERE a.mail_request_attachment_mail_request_id = r.mail_request_id);

-- ---------------------------------------------------------------------------------------------
-- 10. Tidy up
-- ---------------------------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_sd_replies;
DROP TEMPORARY TABLE IF EXISTS tmp_sd_new;
DROP TEMPORARY TABLE IF EXISTS tmp_sd_tickets;
SET time_zone = 'SYSTEM';
