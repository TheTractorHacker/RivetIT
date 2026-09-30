-- Demo data for the "Dashboard and Reports" guide page (group: reports).
--
-- WHY THIS SEED EXISTS
--   The dashboard and the reports only read data that other chapters create (tickets, assets, credentials...).
--   Three kinds of data that the reports need are not created anywhere else, so this seed adds a small amount:
--     * a year of ticket HISTORY with time entries (replies that carry "time worked"), SLA due dates and CSAT
--       ratings - the Service Desk, Tickets, Day by Day, Tickets by Department, Time by Technician, Technician
--       Performance and Customer Satisfaction reports plot these;
--     * a short "current queue" of open tickets (so the dashboard charts, the ageing chart and "Your Open Tickets"
--       have something to show, including two low-rated tickets that were re-opened);
--     * report schedules and rotation dates on credentials (the two Credential rotation reports).
--   (The RMM Health report reads the RMM alerts that the integrations chapter seeds; nothing is added for it here.)
--
-- RULES IT FOLLOWS
--   * Depends only on 00-core.sql. Everything else (assets, credentials, categories) is looked up by NAME and
--     NULL-safely: when a row does not exist yet the dependent value is simply left empty / skipped.
--   * Idempotent. Every demo ticket carries a marker in ticket_url_key ('rptdemo-NNN'); re-running adds nothing.
--     Schedules are keyed on (report, frequency, recipients).
--   * No numeric ids are hard-coded. Ticket numbers continue after whatever exists and settings.
--     config_ticket_next_number is bumped exactly like the app's own "new ticket" code does.
--   * TIME ZONE: the app runs in the company time zone (America/Chicago here) and sets its MySQL session to that
--     offset, but a plain `mysql` command line session is UTC. @ug_now below is "now" in the app's zone, minus one
--     more hour of safety, so nothing is ever stamped in the future when it is viewed in the app.
--   * Only fictional people from 00-core.sql; every address is on the reserved .example domain.
--
-- Apply with:  mysql -u root rivetit_demo < docs/user-guide/tools/seed/90-reports.sql

SET NAMES utf8mb4;
SET @ug_now := UTC_TIMESTAMP() - INTERVAL 6 HOUR;

-- ---- 1. Shared ticket categories (whichever chapter runs first wins) --------------------------------
INSERT INTO categories (category_name, category_type, category_color)
SELECT x.n, 'Ticket', x.c FROM (
  SELECT 'Hardware' AS n, '#2a78d6' AS c
  UNION ALL SELECT 'Software', '#f59f00'
  UNION ALL SELECT 'Network', '#0ca30c'
  UNION ALL SELECT 'Access & Accounts', '#9c36b5'
  UNION ALL SELECT 'Other', '#868e96'
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name = x.n AND c.category_type = 'Ticket');

-- ---- 2. Working tables (dropped again at the end) ------------------------------------------------------
DROP TABLE IF EXISTS ug_rpt_bd;
DROP TABLE IF EXISTS ug_rpt_num;
CREATE TABLE ug_rpt_num (n INT NOT NULL PRIMARY KEY);
INSERT INTO ug_rpt_num (n)
SELECT a.d + 10 * b.d + 100 * c.d + 1
FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c;

-- What people ask IT for. grp says who typically raises it; lab = typical minutes of hands-on time.
DROP TABLE IF EXISTS ug_rpt_subj;
CREATE TABLE ug_rpt_subj (sid INT NOT NULL PRIMARY KEY, subject VARCHAR(200), details TEXT, cat VARCHAR(40), prio VARCHAR(10), grp VARCHAR(10), lab INT);
INSERT INTO ug_rpt_subj (sid, subject, details, cat, prio, grp, lab) VALUES
(1,  'Laptop will not start after the latest Windows update', 'The laptop shows a black screen with a spinning circle after last night''s update and never reaches the sign-in screen.', 'Hardware', 'Medium', 'ANY', 90),
(2,  'Docking station does not detect the second monitor', 'Only the laptop screen works when docked. The second monitor stays blank until I unplug and replug the cable.', 'Hardware', 'Low', 'OFFICE', 45),
(3,  'Monitor flickers every few minutes', 'The screen goes black for a second and comes back, several times an hour. The cable has been reseated.', 'Hardware', 'Low', 'ANY', 30),
(4,  'Replace worn keyboard and mouse', 'Several keys stick and the mouse double-clicks on its own. Requesting a replacement set.', 'Hardware', 'Low', 'ANY', 20),
(5,  'Headset microphone not picked up in Teams', 'Teams shows the headset but colleagues cannot hear me. It works fine on my phone.', 'Hardware', 'Low', 'OFFICE', 25),
(6,  'Laptop battery drains in under an hour', 'The battery went from full to empty in about 50 minutes with only email open. The laptop is over three years old.', 'Hardware', 'Medium', 'ANY', 60),
(7,  'Label printer jams on shipping labels', 'The label printer at the shipping bench jams every 10 to 15 labels and wastes the roll.', 'Hardware', 'Medium', 'WH', 70),
(8,  'Handheld scanner will not pair with its base', 'The scanner beeps but the base station never shows it as connected. Two other scanners are fine.', 'Hardware', 'Medium', 'WH', 45),
(9,  'Plant floor PC is very slow at shift start', 'The cell PC takes several minutes to become usable at the start of each shift and the job screen lags.', 'Hardware', 'Medium', 'PLANT', 80),
(10, 'Touchscreen terminal on line 2 is unresponsive', 'Operators cannot log parts because the touchscreen ignores input. The line is using paper sheets meanwhile.', 'Hardware', 'High', 'PLANT', 100),
(11, 'Inspection tablet will not charge', 'The quality inspection tablet stays at 5 percent on the charger overnight.', 'Hardware', 'Low', 'PLANT', 30),
(12, 'Printer reports a paper jam but nothing is stuck', 'The front office printer keeps showing a paper jam error. I opened every tray and found no paper.', 'Hardware', 'Low', 'OFFICE', 35),
(13, 'CAD workstation crashes when rendering large assemblies', 'The workstation restarts itself while rendering the big assemblies. It happens about twice a day.', 'Hardware', 'High', 'ENG', 120),
(14, 'Second monitor for a new desk', 'Please set up a second monitor and stand for the new desk before the start date.', 'Hardware', 'Low', 'ANY', 40),
(15, 'Badge reader at the receiving dock not reading cards', 'The door reader shows a red light for every badge. Staff are propping the dock door open.', 'Hardware', 'Medium', 'WH', 60),
(16, 'Laptop fan is loud and the base is very hot', 'The fan runs at full speed even when idle and the bottom of the laptop is too hot to hold.', 'Hardware', 'Low', 'ANY', 45),
(17, 'USB drive is not recognised on the machine PC', 'The USB stick with the machine program is not detected on the cell PC. It works on my laptop.', 'Hardware', 'Low', 'PLANT', 25),
(18, 'Webcam not detected before a customer call', 'The built-in camera shows no device in Teams. I have a customer call this afternoon.', 'Hardware', 'Medium', 'SALES', 30),
(19, 'Outlook crashes on startup', 'Outlook closes about five seconds after opening. Safe mode works.', 'Software', 'Medium', 'ANY', 60),
(20, 'Adobe Acrobat needed for contract review', 'I need to edit and sign PDFs and the free reader is not enough. My manager has approved it.', 'Software', 'Low', 'OFFICE', 30),
(21, 'Excel macros blocked after the Office update', 'The month-end workbook macros stopped running with a security warning since Monday.', 'Software', 'Medium', 'FIN', 50),
(22, 'ERP client will not launch after update', 'After the ERP client update the icon opens and closes with no message. Others on the team see the same.', 'Software', 'High', 'ANY', 90),
(23, 'Teams meetings have echo for remote attendees', 'Remote people hear themselves when I speak in the meeting room.', 'Software', 'Low', 'ANY', 40),
(24, 'Install CAD viewer plugin on laptop', 'I need the CAD viewer plugin installed so I can open supplier drawings.', 'Software', 'Low', 'ENG', 35),
(25, 'PDF editor licence for HR forms', 'HR needs a PDF editor licence to update the onboarding forms.', 'Software', 'Low', 'HR', 25),
(26, 'Large spreadsheets open very slowly', 'The reconciliation workbook takes about four minutes to open, even after a restart.', 'Software', 'Low', 'FIN', 55),
(27, 'Antivirus flags a trusted CAD macro', 'Endpoint protection quarantines our tolerance macro every morning.', 'Software', 'Medium', 'ENG', 60),
(28, 'Shipping software will not print packing slips', 'Packing slips fail with a spooler error. Labels still print.', 'Software', 'High', 'WH', 75),
(29, 'Browser keeps redirecting to search pages', 'New tabs open an unfamiliar search site and I keep getting pop-ups.', 'Software', 'Medium', 'ANY', 60),
(30, 'Access to the marketing design tools', 'I need a seat in the design suite for the new campaign.', 'Software', 'Low', 'SALES', 25),
(31, 'Windows update stuck at 30 percent', 'The laptop has been on the updating screen for over an hour.', 'Software', 'Medium', 'ANY', 70),
(32, 'Payroll export fails with a permissions error', 'The payroll export to the bank format stops with an access denied message.', 'Software', 'High', 'FIN', 90),
(33, 'Wi-Fi keeps dropping in the warehouse', 'Scanners lose their connection near the back racks several times per shift.', 'Network', 'Medium', 'WH', 90),
(34, 'VPN will not connect from home', 'The VPN client says connection timed out. My home internet is fine.', 'Network', 'Medium', 'ANY', 60),
(35, 'Cannot reach the shared drive since this morning', 'The department share shows a network path not found error.', 'Network', 'High', 'ANY', 45),
(36, 'Internet is slow in the plant office', 'Pages take a very long time to load in the plant office, mostly after lunch.', 'Network', 'Medium', 'PLANT', 80),
(37, 'Network port at the desk is dead', 'The wall port near the window gives no link light.', 'Network', 'Low', 'OFFICE', 40),
(38, 'Guest Wi-Fi access for visitors', 'Two visitors are coming on Thursday and need guest Wi-Fi.', 'Network', 'Low', 'EXEC', 10),
(39, 'Remote desktop to the plant PC times out', 'I cannot reach the line PC from my desk. The connection hangs at Securing remote connection.', 'Network', 'Medium', 'ENG', 60),
(40, 'Printer shows offline on the network', 'The shared printer appears offline for everyone even though it is powered on.', 'Network', 'Low', 'OFFICE', 30),
(41, 'Loading dock camera feed is offline', 'The dock camera view has been blank in the security viewer since yesterday.', 'Network', 'Medium', 'WH', 75),
(42, 'VPN drops every 30 minutes while travelling', 'On hotel Wi-Fi the VPN disconnects and I have to sign in again.', 'Network', 'Medium', 'SALES', 60),
(43, 'Password reset request', 'I forgot my password after the long weekend and cannot sign in.', 'Access & Accounts', 'Low', 'ANY', 10),
(44, 'New hire account and mailbox setup', 'A new employee starts Monday and needs an account, a mailbox and access to the team share.', 'Access & Accounts', 'Medium', 'HR', 60),
(45, 'MFA needs to be re-enrolled on a new phone', 'I got a new phone and the authenticator app has no accounts.', 'Access & Accounts', 'Medium', 'ANY', 20),
(46, 'Access to the shared finance folder', 'Please add me to the month-end folder for the close.', 'Access & Accounts', 'Low', 'FIN', 15),
(47, 'Add me to the sales distribution list', 'I am not receiving the weekly sales updates. Please add me to the list.', 'Access & Accounts', 'Low', 'SALES', 10),
(48, 'Account locked after too many attempts', 'I was locked out after typing my password wrong on my phone and laptop.', 'Access & Accounts', 'Medium', 'ANY', 15),
(49, 'Disable accounts for a departing employee', 'The last day is Friday. Please disable the accounts and forward the mailbox to the manager.', 'Access & Accounts', 'Medium', 'HR', 40),
(50, 'Access to the production schedule workbook', 'I need read access to the weekly production schedule.', 'Access & Accounts', 'Low', 'PLANT', 15),
(51, 'Shared mailbox access for the warehouse team', 'Two new team members need the shipping shared mailbox.', 'Access & Accounts', 'Low', 'WH', 20),
(52, 'Cannot sign in to the ERP portal', 'The portal says invalid credentials but the password works everywhere else.', 'Access & Accounts', 'Medium', 'ANY', 30),
(53, 'Temporary admin rights needed to install a driver', 'I need to install the scanner driver and it asks for administrator rights.', 'Access & Accounts', 'Low', 'ENG', 20),
(54, 'Conference room display will not connect', 'Laptops cannot cast to the boardroom display. It worked last week.', 'Other', 'Medium', 'EXEC', 45),
(55, 'Move desk phone to the new office', 'We are moving offices on Friday. The phone needs to move and keep its extension.', 'Other', 'Low', 'OFFICE', 30),
(56, 'Question about archived email retention', 'How long do we keep archived mailboxes, and can I get one restored?', 'Other', 'Low', 'HR', 20),
(57, 'Set up a quiet room for video calls', 'I need a webcam and headset set up in the small meeting room.', 'Other', 'Low', 'ANY', 25),
(58, 'Loaner laptop for travel next week', 'I need a loaner laptop with VPN for a trade show trip.', 'Other', 'Low', 'SALES', 20),
(59, 'Help scanning signed contracts to PDF', 'The scanner sends blank pages when I scan double-sided contracts.', 'Other', 'Low', 'FIN', 15);

-- Which of the 21 people can raise which kind of request.
DROP TABLE IF EXISTS ug_rpt_gc0;
CREATE TABLE ug_rpt_gc0 (grp VARCHAR(10) NOT NULL, email VARCHAR(200) NOT NULL);
INSERT INTO ug_rpt_gc0 (grp, email)
SELECT g.grp, c.contact_email
FROM contacts c
JOIN clients cl ON cl.client_id = c.contact_client_id
JOIN (
  SELECT 'Executive Office' AS d, 'ANY' AS grp UNION ALL SELECT 'Executive Office', 'OFFICE' UNION ALL SELECT 'Executive Office', 'EXEC'
  UNION ALL SELECT 'Finance & Accounting', 'ANY' UNION ALL SELECT 'Finance & Accounting', 'OFFICE' UNION ALL SELECT 'Finance & Accounting', 'FIN'
  UNION ALL SELECT 'Human Resources', 'ANY' UNION ALL SELECT 'Human Resources', 'OFFICE' UNION ALL SELECT 'Human Resources', 'HR'
  UNION ALL SELECT 'Sales & Marketing', 'ANY' UNION ALL SELECT 'Sales & Marketing', 'OFFICE' UNION ALL SELECT 'Sales & Marketing', 'SALES'
  UNION ALL SELECT 'Production', 'ANY' UNION ALL SELECT 'Production', 'PLANT'
  UNION ALL SELECT 'Warehouse & Logistics', 'ANY' UNION ALL SELECT 'Warehouse & Logistics', 'WH'
  UNION ALL SELECT 'Engineering', 'ANY' UNION ALL SELECT 'Engineering', 'ENG'
) g ON g.d = cl.client_name
WHERE c.contact_archived_at IS NULL
  AND c.contact_email IN (
    'helen.brandt@summitridge.example','raj.patel@summitridge.example','grace.okafor@summitridge.example','tom.kessler@summitridge.example',
    'lena.fischer@summitridge.example','miguel.alvarez@summitridge.example','sophie.tran@summitridge.example','nina.rossi@summitridge.example',
    'owen.baker@summitridge.example','zoe.hartman@summitridge.example','ben.carter@summitridge.example','carlos.mendoza@summitridge.example',
    'aisha.rahman@summitridge.example','jake.sullivan@summitridge.example','emma.novak@summitridge.example','frank.delgado@summitridge.example',
    'tara.whitfield@summitridge.example','liam.oconnor@summitridge.example','yuki.tanaka@summitridge.example','ivan.petrov@summitridge.example',
    'maya.singh@summitridge.example');

DROP TABLE IF EXISTS ug_rpt_gc;
CREATE TABLE ug_rpt_gc (grp VARCHAR(10) NOT NULL, email VARCHAR(200) NOT NULL, rn INT NOT NULL, cnt INT NOT NULL, KEY (grp, rn));
INSERT INTO ug_rpt_gc (grp, email, rn, cnt)
SELECT grp, email, ROW_NUMBER() OVER (PARTITION BY grp ORDER BY email), COUNT(*) OVER (PARTITION BY grp) FROM ug_rpt_gc0;

-- Short replies and notes used on the history tickets, and CSAT comments by rating.
DROP TABLE IF EXISTS ug_rpt_txt;
CREATE TABLE ug_rpt_txt (kind CHAR(1) NOT NULL, idx INT NOT NULL, txt VARCHAR(300), PRIMARY KEY (kind, idx));
INSERT INTO ug_rpt_txt (kind, idx, txt) VALUES
('A', 1, 'Thanks for letting us know. I am looking into this now and will update you shortly.'),
('A', 2, 'Hi, I have picked this up. Can you confirm the problem is still happening?'),
('A', 3, 'Received. I will start troubleshooting and follow up once I have an update.'),
('A', 4, 'Thanks, I am on it and will let you know as soon as it is sorted.'),
('C', 1, 'Checked the event log and found repeated driver errors. Reinstalling the driver.'),
('C', 2, 'Reproduced on a second device, so it is not specific to this machine.'),
('C', 3, 'Vendor portal case opened and logs attached.'),
('C', 4, 'Applied the standard fix from the knowledge base and re-tested with the user.'),
('C', 5, 'Cleared the cached profile and confirmed with the user.'),
('C', 6, 'Updated the firmware to the latest version and monitored for an hour.');

DROP TABLE IF EXISTS ug_rpt_cmt;
CREATE TABLE ug_rpt_cmt (rating INT NOT NULL, idx INT NOT NULL, cnt INT NOT NULL DEFAULT 0, txt VARCHAR(300), PRIMARY KEY (rating, idx));
INSERT INTO ug_rpt_cmt (rating, idx, txt) VALUES
(5, 1, 'Fast and friendly, thank you!'),
(5, 2, 'Fixed within the hour. Great service.'),
(5, 3, 'Exactly what I needed, thanks.'),
(5, 4, 'Very clear updates throughout.'),
(5, 5, 'Problem solved and explained well.'),
(5, 6, 'Quick turnaround, much appreciated.'),
(4, 1, 'Good help, took a little longer than expected.'),
(4, 2, 'Solved, though I had to follow up once.'),
(4, 3, 'Thanks for the quick fix.'),
(4, 4, 'Helpful and polite.'),
(3, 1, 'Fixed in the end but it took several days.'),
(3, 2, 'It works now. I would have liked more updates.'),
(3, 3, 'Okay, but I had to explain the problem twice.'),
(2, 1, 'Slow to get a first response.'),
(2, 2, 'The fix did not last and the problem came back.'),
(2, 3, 'I had to chase for an update.'),
(1, 1, 'Nothing was fixed and nobody followed up.'),
(1, 2, 'Very long wait and the issue is still there.');
UPDATE ug_rpt_cmt c JOIN (SELECT rating, COUNT(*) AS cn FROM ug_rpt_cmt GROUP BY rating) x ON x.rating = c.rating SET c.cnt = x.cn;

-- ---- 3. The plan: one row per demo ticket -------------------------------------------------------------
DROP TABLE IF EXISTS ug_rpt_plan;
CREATE TABLE ug_rpt_plan (
  n INT NOT NULL PRIMARY KEY,
  kind VARCHAR(8) NOT NULL DEFAULT 'hist',          -- hist = generated history, open = hand-written queue
  sid INT NULL, subject VARCHAR(300) NULL, details TEXT NULL, cat VARCHAR(40) NULL, prio VARCHAR(10) NULL, grp VARCHAR(10) NULL,
  lab0 INT NULL,
  contact_email VARCHAR(200) NULL, client_name VARCHAR(200) NULL, assignee_email VARCHAR(200) NULL, asset_name VARCHAR(100) NULL,
  source VARCHAR(20) NULL, status_id INT NOT NULL DEFAULT 5,
  -- hand-written rows say WHEN as "days ago" + "seconds after midnight" (working hours), generated rows compute it
  e_dago INT NULL, e_csec INT NULL, e_frmin INT NULL, e_udago INT NULL, e_usec INT NULL, e_rdago INT NULL, e_rsec INT NULL, e_xdago INT NULL, e_xsec INT NULL,
  created_at DATETIME NULL, first_resp_at DATETIME NULL, resolved_at DATETIME NULL, upd_at DATETIME NULL,
  res_class TINYINT NULL, sla_resp_due DATETIME NULL, sla_res_due DATETIME NULL,
  rating TINYINT NULL, cmt TEXT NULL, rated_at DATETIME NULL,
  u_d DOUBLE, u_t DOUBLE, u_pr DOUBLE, u_c DOUBLE, u_a DOUBLE, u_rl DOUBLE, u_rm DOUBLE, u_k DOUBLE, u_q DOUBLE, u_m DOUBLE,
  u_nt DOUBLE, u_cs DOUBLE, u_r DOUBLE, u_cc DOUBLE, u_ci DOUBLE, u_ra DOUBLE, u_src DOUBLE, u_lab DOUBLE, u_l2 DOUBLE,
  u_c1 DOUBLE, u_ai DOUBLE, u_ci2 DOUBLE
) ENGINE=InnoDB;

-- Working days of the last 268 days, newest first (so tickets are never created at a weekend).
DROP TABLE IF EXISTS ug_rpt_bd;
CREATE TABLE ug_rpt_bd (k INT NOT NULL PRIMARY KEY, d DATE NOT NULL);
INSERT INTO ug_rpt_bd (k, d)
SELECT ROW_NUMBER() OVER (ORDER BY d DESC), d
  FROM (SELECT DATE(@ug_now) - INTERVAL n DAY AS d FROM ug_rpt_num WHERE n <= 268) x
 WHERE DAYOFWEEK(d) BETWEEN 2 AND 6;
SET @ug_bd_count := (SELECT COUNT(*) FROM ug_rpt_bd);

-- 170 generated history tickets, cycling through the 59 request types.
INSERT INTO ug_rpt_plan (n, kind, sid)
SELECT n, 'hist', 1 + MOD(n * 13 + FLOOR((n - 1) / 59) * 7, 59) FROM ug_rpt_num WHERE n <= 170;

-- The current queue: hand-written so the dashboard reads like a real desk (ages from this morning to 33 days).
INSERT INTO ug_rpt_plan (n, kind, subject, details, cat, prio, contact_email, assignee_email, asset_name, source, status_id,
                         e_dago, e_csec, e_frmin, e_udago, e_usec, lab0, rating, cmt, e_rdago, e_rsec)
VALUES
(901, 'open', 'Outlook keeps asking for my password after the MFA change', 'Since I moved to the new phone Outlook prompts for a password every few minutes and never keeps the sign-in.', 'Access & Accounts', 'Medium', 'grace.okafor@summitridge.example', 'priya.nair@summitridge.example', NULL, 'Portal', 2,
   0, 29700, 40, 0, 36000, 30, NULL, NULL, NULL, NULL),
(902, 'open', 'New hire starting Monday: equipment and accounts', 'A Production hire starts Monday. They need a shared workstation login, badge access and the ERP portal.', 'Access & Accounts', 'Medium', 'sophie.tran@summitridge.example', 'alex.morgan@summitridge.example', NULL, 'Portal', 2,
   1, 55200, 95, 0, 30600, 45, NULL, NULL, NULL, NULL),
(903, 'open', 'Plant floor PC freezes during shift changeover', 'The line PC freezes for several minutes exactly when the shifts hand over and jobs are being closed.', 'Hardware', 'High', 'aisha.rahman@summitridge.example', 'marcus.lee@summitridge.example', 'PC-PLANT-07', 'Agent', 2,
   2, 47100, 25, 1, 38400, 90, NULL, NULL, NULL, NULL),
(904, 'open', 'Handheld scanner will not sync inventory', 'The scanner shows Sync failed when docked. We are waiting on the vendor for a replacement cradle.', 'Hardware', 'Medium', 'tara.whitfield@summitridge.example', 'marcus.lee@summitridge.example', 'SCAN-WH-02', 'Email', 3,
   9, 36900, 70, 5, 50400, 60, NULL, NULL, NULL, NULL),
(905, 'open', 'Printer leaves streaks on every page', 'Every printout has a vertical streak. Cleaning the drum did not help.', 'Hardware', 'Low', 'zoe.hartman@summitridge.example', 'priya.nair@summitridge.example', 'PRN-HQ-01', 'Portal', 2,
   12, 34800, 180, 4, 40800, 40, NULL, NULL, NULL, NULL),
(906, 'open', 'Second monitor for the CAD workstation', 'Requesting a second 27-inch monitor for the CAD workstation to review drawings side by side.', 'Hardware', 'Low', 'maya.singh@summitridge.example', NULL, NULL, 'Portal', 1,
   0, 35100, NULL, 0, 35100, 0, NULL, NULL, NULL, NULL),
(907, 'open', 'Wi-Fi drops in the shipping office every afternoon', 'Between 2 and 4 pm the Wi-Fi in the shipping office disconnects for a minute at a time.', 'Network', 'High', 'frank.delgado@summitridge.example', 'marcus.lee@summitridge.example', NULL, 'Email', 2,
   6, 51000, 30, 2, 33300, 100, NULL, NULL, NULL, NULL),
(908, 'open', 'Access to the Q4 budget folder', 'Please give me read and write access to the Q4 budget folder on the finance share.', 'Access & Accounts', 'Low', 'tom.kessler@summitridge.example', NULL, NULL, 'Portal', 1,
   1, 58500, NULL, 1, 58500, 0, NULL, NULL, NULL, NULL),
(909, 'open', 'VPN disconnects every 30 minutes on hotel Wi-Fi', 'On the road this week the VPN drops every half hour and I have to reconnect.', 'Network', 'Medium', 'nina.rossi@summitridge.example', 'alex.morgan@summitridge.example', NULL, 'Email', 2,
   4, 30000, 55, 1, 60000, 60, NULL, NULL, NULL, NULL),
(910, 'open', 'Conference room display flickers during video calls', 'The boardroom display flickers during calls. We are waiting for the AV installer to test the cable run.', 'Other', 'Low', 'raj.patel@summitridge.example', 'priya.nair@summitridge.example', NULL, 'Agent', 3,
   21, 48600, 120, 8, 36000, 45, NULL, NULL, NULL, NULL),
(911, 'open', 'Renew the CAD license server before it expires', 'The CAD license server needs a version upgrade and renewal before the maintenance window closes.', 'Software', 'Medium', 'yuki.tanaka@summitridge.example', 'alex.morgan@summitridge.example', NULL, 'Agent', 2,
   33, 32400, 200, 3, 55800, 120, NULL, NULL, NULL, NULL),
(912, 'open', 'Label printer still jams after the repair', 'The label printer jammed again two days after it was repaired.', 'Hardware', 'Medium', 'liam.oconnor@summitridge.example', 'marcus.lee@summitridge.example', NULL, 'Portal', 2,
   20, 40200, 45, 1, 39600, 60, 2, 'The fix did not last and the jam came back.', 6, 34200),
(913, 'open', 'Password reset email never arrived', 'I requested a password reset three times and no email came. I had to call the desk.', 'Access & Accounts', 'High', 'helen.brandt@summitridge.example', 'priya.nair@summitridge.example', NULL, 'Email', 2,
   15, 37500, 20, 2, 35400, 30, 1, 'The reset email never arrived and I had to call.', 4, 47400),
(914, 'open', 'Forecast workbook opens with macros blocked', 'The forecast workbook has opened read-only with macros disabled since Monday''s update.', 'Software', 'Medium', 'lena.fischer@summitridge.example', 'priya.nair@summitridge.example', NULL, 'Portal', 2,
   2, 53400, 60, 0, 33600, 40, NULL, NULL, NULL, NULL);

-- Deterministic pseudo-random numbers in [0,1) per ticket (first 8 hex digits of an MD5), so the same ticket always
-- gets the same values. (CRC32 was tried first and is far too clustered for consecutive numbers.)
UPDATE ug_rpt_plan SET
  u_d   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|d')), 1, 8), 16, 10) / 4294967296, u_t   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|t')), 1, 8), 16, 10) / 4294967296,
  u_pr  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|pr')), 1, 8), 16, 10) / 4294967296, u_c   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|c')), 1, 8), 16, 10) / 4294967296,
  u_a   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|a')), 1, 8), 16, 10) / 4294967296, u_rl  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|rl')), 1, 8), 16, 10) / 4294967296,
  u_rm  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|rm')), 1, 8), 16, 10) / 4294967296, u_k   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|k')), 1, 8), 16, 10) / 4294967296,
  u_q   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|q')), 1, 8), 16, 10) / 4294967296, u_m   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|m')), 1, 8), 16, 10) / 4294967296,
  u_nt  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|nt')), 1, 8), 16, 10) / 4294967296, u_cs  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|cs')), 1, 8), 16, 10) / 4294967296,
  u_r   = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|r')), 1, 8), 16, 10) / 4294967296, u_cc  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|cc')), 1, 8), 16, 10) / 4294967296,
  u_ci  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|ci')), 1, 8), 16, 10) / 4294967296, u_ra  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|ra')), 1, 8), 16, 10) / 4294967296,
  u_src = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|src')), 1, 8), 16, 10) / 4294967296, u_lab = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|lab')), 1, 8), 16, 10) / 4294967296,
  u_l2  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|l2')), 1, 8), 16, 10) / 4294967296, u_c1  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|c1')), 1, 8), 16, 10) / 4294967296,
  u_ai  = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|ai')), 1, 8), 16, 10) / 4294967296, u_ci2 = CONV(SUBSTRING(MD5(CONCAT('ug|', n, '|ci2')), 1, 8), 16, 10) / 4294967296;

-- Spread the 170 history tickets evenly over the working days (one per 1/170th of the range, with a little jitter)
-- so the monthly counts rise gently instead of jumping around.
UPDATE ug_rpt_plan SET u_d = LEAST(0.9999, GREATEST(0, (MOD(n * 37, 170) + 3 * u_d - 1) / 170)) WHERE kind = 'hist' AND n <= 170;

-- Hand-written rows: turn "days ago + seconds after midnight" into timestamps. Nothing is ever in the future, and
-- nothing lands on a weekend (those move back to Friday, keeping the order created <= first response <= last activity).
UPDATE ug_rpt_plan SET
    created_at    = LEAST(@ug_now - INTERVAL 20 MINUTE, TIMESTAMP(DATE(@ug_now) - INTERVAL e_dago DAY) + INTERVAL e_csec SECOND)
 WHERE n > 170;
UPDATE ug_rpt_plan SET created_at = created_at - INTERVAL (CASE DAYOFWEEK(created_at) WHEN 1 THEN 2 WHEN 7 THEN 1 ELSE 0 END) DAY WHERE n > 170;
UPDATE ug_rpt_plan SET first_resp_at = created_at + INTERVAL e_frmin MINUTE WHERE n > 170 AND e_frmin IS NOT NULL;
UPDATE ug_rpt_plan SET first_resp_at = LEAST(first_resp_at, @ug_now - INTERVAL 15 MINUTE) WHERE n > 170 AND first_resp_at IS NOT NULL;
UPDATE ug_rpt_plan SET resolved_at = GREATEST(first_resp_at + INTERVAL 30 MINUTE, TIMESTAMP(DATE(@ug_now) - INTERVAL e_rdago DAY) + INTERVAL e_rsec SECOND)
 WHERE n > 170 AND kind = 'hist';
UPDATE ug_rpt_plan SET resolved_at = resolved_at - INTERVAL (CASE DAYOFWEEK(resolved_at) WHEN 1 THEN 2 WHEN 7 THEN 1 ELSE 0 END) DAY WHERE n > 170 AND kind = 'hist';
UPDATE ug_rpt_plan SET resolved_at = GREATEST(resolved_at, first_resp_at + INTERVAL 30 MINUTE) WHERE n > 170 AND kind = 'hist';
UPDATE ug_rpt_plan SET upd_at = GREATEST(COALESCE(first_resp_at, created_at),
        LEAST(@ug_now - INTERVAL 10 MINUTE, TIMESTAMP(DATE(@ug_now) - INTERVAL e_udago DAY) + INTERVAL e_usec SECOND))
 WHERE n > 170 AND kind = 'open';
UPDATE ug_rpt_plan SET upd_at = upd_at - INTERVAL (CASE DAYOFWEEK(upd_at) WHEN 1 THEN 2 WHEN 7 THEN 1 ELSE 0 END) DAY WHERE n > 170 AND kind = 'open';
UPDATE ug_rpt_plan SET upd_at = GREATEST(upd_at, COALESCE(first_resp_at, created_at)) WHERE n > 170 AND kind = 'open';
UPDATE ug_rpt_plan SET rated_at = GREATEST(created_at + INTERVAL 1 DAY, TIMESTAMP(DATE(@ug_now) - INTERVAL e_rdago DAY) + INTERVAL e_rsec SECOND)
 WHERE n > 170 AND kind = 'open' AND rating IS NOT NULL;
UPDATE ug_rpt_plan SET rated_at = rated_at - INTERVAL (CASE DAYOFWEEK(rated_at) WHEN 1 THEN 2 WHEN 7 THEN 1 ELSE 0 END) DAY WHERE n > 170 AND kind = 'open' AND rating IS NOT NULL;
UPDATE ug_rpt_plan SET upd_at = GREATEST(upd_at, rated_at) WHERE n > 170 AND kind = 'open' AND rating IS NOT NULL;

-- Fill the generated tickets from the request-type table.
UPDATE ug_rpt_plan p JOIN ug_rpt_subj s ON s.sid = p.sid
   SET p.subject = s.subject, p.details = s.details, p.cat = s.cat, p.prio = s.prio, p.grp = s.grp, p.lab0 = s.lab
 WHERE p.kind = 'hist' AND p.sid IS NOT NULL;

-- One ticket in ten is a notch more urgent than usual, one in ten a notch less.
UPDATE ug_rpt_plan SET prio = CASE
    WHEN u_pr < 0.10 THEN CASE prio WHEN 'Low' THEN 'Medium' ELSE 'High' END
    WHEN u_pr > 0.90 THEN CASE prio WHEN 'High' THEN 'Medium' ELSE 'Low' END
    ELSE prio END
 WHERE kind = 'hist' AND sid IS NOT NULL;

-- Who raised it: someone from the group that typically raises this kind of request.
UPDATE ug_rpt_plan p JOIN ug_rpt_gc g ON g.grp = p.grp AND g.rn = 1 + FLOOR(p.u_c * g.cnt)
   SET p.contact_email = g.email
 WHERE p.kind = 'hist' AND p.sid IS NOT NULL;

-- Who worked it: Marcus takes most network work, Priya most hardware and accounts, Alex the rest.
UPDATE ug_rpt_plan SET assignee_email = CASE
    WHEN cat = 'Network'           THEN CASE WHEN u_a < .65 THEN 'marcus.lee@summitridge.example' WHEN u_a < .80 THEN 'priya.nair@summitridge.example' ELSE 'alex.morgan@summitridge.example' END
    WHEN cat = 'Hardware'          THEN CASE WHEN u_a < .55 THEN 'priya.nair@summitridge.example' WHEN u_a < .85 THEN 'marcus.lee@summitridge.example' ELSE 'alex.morgan@summitridge.example' END
    WHEN cat = 'Access & Accounts' THEN CASE WHEN u_a < .50 THEN 'priya.nair@summitridge.example' WHEN u_a < .85 THEN 'alex.morgan@summitridge.example' ELSE 'marcus.lee@summitridge.example' END
    WHEN cat = 'Software'          THEN CASE WHEN u_a < .45 THEN 'priya.nair@summitridge.example' WHEN u_a < .75 THEN 'alex.morgan@summitridge.example' ELSE 'marcus.lee@summitridge.example' END
    ELSE CASE WHEN u_a < .34 THEN 'priya.nair@summitridge.example' WHEN u_a < .67 THEN 'marcus.lee@summitridge.example' ELSE 'alex.morgan@summitridge.example' END END
 WHERE kind = 'hist' AND sid IS NOT NULL;

-- When: working days only, 07:30 - 14:30, more tickets in recent months (the exponent).
UPDATE ug_rpt_plan p JOIN ug_rpt_bd b ON b.k = 1 + FLOOR(@ug_bd_count * POW(p.u_d, 1.1))
   SET p.created_at = TIMESTAMP(b.d) + INTERVAL (27000 + FLOOR(p.u_t * 25200)) SECOND
 WHERE p.kind = 'hist' AND p.sid IS NOT NULL;

-- First response: most inside the SLA target (High 1 h, Medium 4 h, Low 24 h), a few late.
UPDATE ug_rpt_plan SET first_resp_at = created_at + INTERVAL (CASE prio
    WHEN 'High'   THEN IF(u_rl < 0.12, 65 + FLOOR(u_rm * 115), 5 + FLOOR(u_rm * 40))
    WHEN 'Medium' THEN IF(u_rl < 0.10, 250 + FLOOR(u_rm * 230), 15 + FLOOR(u_rm * 170))
    ELSE               IF(u_rl < 0.06, 1500 + FLOOR(u_rm * 500), 30 + FLOOR(u_rm * 400)) END) MINUTE
 WHERE kind = 'hist' AND sid IS NOT NULL;
-- Nobody answers at night or at the weekend: push those to the next morning / Monday.
UPDATE ug_rpt_plan SET first_resp_at = TIMESTAMP(DATE(first_resp_at) + INTERVAL 1 DAY) + INTERVAL (28800 + FLOOR(u_rm * 3600)) SECOND
 WHERE kind = 'hist' AND sid IS NOT NULL AND TIME(first_resp_at) > '18:00:00';
UPDATE ug_rpt_plan SET first_resp_at = first_resp_at + INTERVAL (CASE DAYOFWEEK(first_resp_at) WHEN 7 THEN 2 WHEN 1 THEN 1 ELSE 0 END) DAY
 WHERE kind = 'hist' AND sid IS NOT NULL;

-- Resolution: 1 = same day, 2 = next working day, 3 = two to five days later.
UPDATE ug_rpt_plan SET res_class = CASE
    WHEN u_k < (CASE prio WHEN 'High' THEN .85 WHEN 'Medium' THEN .55 ELSE .40 END) THEN 1
    WHEN u_k < (CASE prio WHEN 'High' THEN .97 WHEN 'Medium' THEN .85 ELSE .70 END) THEN 2
    ELSE 3 END
 WHERE kind = 'hist' AND sid IS NOT NULL;
UPDATE ug_rpt_plan SET resolved_at = first_resp_at + INTERVAL (15 + FLOOR(u_q * 200)) MINUTE
 WHERE kind = 'hist' AND sid IS NOT NULL AND res_class = 1;
UPDATE ug_rpt_plan SET resolved_at = GREATEST(first_resp_at + INTERVAL 5 MINUTE, TIMESTAMP(DATE(first_resp_at)) + INTERVAL (62000 - FLOOR(u_nt * 1800)) SECOND)
 WHERE kind = 'hist' AND sid IS NOT NULL AND res_class = 1 AND TIME(resolved_at) > '17:45:00';
UPDATE ug_rpt_plan SET resolved_at = GREATEST(first_resp_at + INTERVAL 5 MINUTE,
        TIMESTAMP(DATE(created_at) + INTERVAL (CASE DAYOFWEEK(DATE(created_at) + INTERVAL 1 DAY) WHEN 7 THEN 3 WHEN 1 THEN 2 ELSE 1 END) DAY) + INTERVAL (30600 + FLOOR(u_nt * 27000)) SECOND)
 WHERE kind = 'hist' AND sid IS NOT NULL AND res_class = 2;
UPDATE ug_rpt_plan SET resolved_at = GREATEST(first_resp_at + INTERVAL 5 MINUTE,
        TIMESTAMP(DATE(created_at) + INTERVAL (2 + FLOOR(u_m * 4)) DAY) + INTERVAL (32400 + FLOOR(u_nt * 25200)) SECOND)
 WHERE kind = 'hist' AND sid IS NOT NULL AND res_class = 3;
UPDATE ug_rpt_plan SET resolved_at = resolved_at + INTERVAL (CASE DAYOFWEEK(resolved_at) WHEN 7 THEN 2 WHEN 1 THEN 1 ELSE 0 END) DAY
 WHERE kind = 'hist' AND sid IS NOT NULL;
-- Never in the future: anything that would finish after "now" finished a little before it.
UPDATE ug_rpt_plan SET resolved_at = @ug_now - INTERVAL (45 + FLOOR(u_q * 120)) MINUTE
 WHERE kind = 'hist' AND sid IS NOT NULL AND resolved_at > @ug_now - INTERVAL 45 MINUTE;

-- SLA targets, for every ticket: response High 1 h / Medium 4 h / Low 24 h; resolution 8 h / 48 h / 120 h.
UPDATE ug_rpt_plan SET
    sla_resp_due = created_at + INTERVAL (CASE prio WHEN 'High' THEN 60 WHEN 'Medium' THEN 240 ELSE 1440 END) MINUTE,
    sla_res_due  = created_at + INTERVAL (CASE prio WHEN 'High' THEN 480 WHEN 'Medium' THEN 2880 ELSE 7200 END) MINUTE;

-- Where it came from, and the requester's rating (about 4 in 10 closed tickets are rated, a day or two later).
UPDATE ug_rpt_plan SET source = CASE WHEN u_src < .38 THEN 'Portal' WHEN u_src < .74 THEN 'Email' ELSE 'Agent' END
 WHERE kind = 'hist' AND sid IS NOT NULL;
UPDATE ug_rpt_plan SET
    rating = CASE
      WHEN assignee_email LIKE 'priya%'  THEN CASE WHEN u_r < .68 THEN 5 WHEN u_r < .90 THEN 4 WHEN u_r < .96 THEN 3 WHEN u_r < .985 THEN 2 ELSE 1 END
      WHEN assignee_email LIKE 'marcus%' THEN CASE WHEN u_r < .52 THEN 5 WHEN u_r < .82 THEN 4 WHEN u_r < .92 THEN 3 WHEN u_r < .97  THEN 2 ELSE 1 END
      ELSE                                    CASE WHEN u_r < .58 THEN 5 WHEN u_r < .86 THEN 4 WHEN u_r < .94 THEN 3 WHEN u_r < .98  THEN 2 ELSE 1 END END,
    rated_at = resolved_at + INTERVAL (30 + FLOOR(u_ra * 2300)) MINUTE
 WHERE kind = 'hist' AND sid IS NOT NULL AND u_cs < .42 AND resolved_at + INTERVAL 42 HOUR < @ug_now;
UPDATE ug_rpt_plan p JOIN ug_rpt_cmt c ON c.rating = p.rating AND c.idx = 1 + FLOOR(p.u_ci * c.cnt)
   SET p.cmt = c.txt
 WHERE p.kind = 'hist' AND p.rating IS NOT NULL AND p.u_cc < CASE p.rating WHEN 5 THEN .35 WHEN 4 THEN .40 WHEN 3 THEN .70 ELSE 1 END;

-- Last activity on a closed ticket (closing, or the rating that came in afterwards).
UPDATE ug_rpt_plan SET upd_at = GREATEST(resolved_at, COALESCE(rated_at, resolved_at)) WHERE resolved_at IS NOT NULL AND upd_at IS NULL;

-- ---- 4. Tickets --------------------------------------------------------------------------------------------
SET @ug_base := GREATEST(
    COALESCE((SELECT config_ticket_next_number FROM settings WHERE company_id = 1), 1),
    COALESCE((SELECT MAX(ticket_number) FROM tickets), 0) + 1);

INSERT INTO tickets (ticket_prefix, ticket_number, ticket_source, ticket_category, ticket_subject, ticket_details, ticket_priority,
                     ticket_status, ticket_billable, ticket_url_key, ticket_created_at, ticket_updated_at, ticket_resolved_at,
                     ticket_first_response_at, ticket_closed_at, ticket_created_by, ticket_assigned_to, ticket_closed_by,
                     ticket_client_id, ticket_contact_id, ticket_asset_id, ticket_sla_response_due, ticket_sla_resolution_due,
                     ticket_sla_response_met, ticket_sla_resolution_met, ticket_csat_rating, ticket_csat_comment, ticket_csat_rated_at)
SELECT (SELECT config_ticket_prefix FROM settings WHERE company_id = 1),
       @ug_base + s.rn - 1,
       s.source,
       CAST(cat.category_id AS CHAR),
       s.subject,
       CONCAT('<p>', REPLACE(s.details, '\n', '<br>'), '</p>'),
       s.prio,
       s.status_id,
       0,
       CONCAT('rptdemo-', LPAD(s.n, 3, '0')),
       s.created_at, s.upd_at, s.resolved_at, s.first_resp_at, s.resolved_at,
       IF(s.source = 'Agent', COALESCE(au.user_id, 0), 0),
       COALESCE(au.user_id, 0),
       IF(s.resolved_at IS NULL, 0, COALESCE(au.user_id, 0)),
       COALESCE(ct.contact_client_id, NULLIF(ast.asset_client_id, 0), cl.client_id),
       COALESCE(ct.contact_id, 0),
       COALESCE(ast.asset_id, 0),
       s.sla_resp_due, s.sla_res_due,
       IF(s.first_resp_at IS NULL, NULL, IF(s.first_resp_at <= s.sla_resp_due, 1, 0)),
       IF(s.resolved_at IS NULL, NULL, IF(s.resolved_at <= s.sla_res_due, 1, 0)),
       s.rating, s.cmt, s.rated_at
FROM (SELECT p.*, ROW_NUMBER() OVER (ORDER BY p.created_at, p.n) AS rn
        FROM ug_rpt_plan p
       WHERE NOT EXISTS (SELECT 1 FROM tickets x WHERE x.ticket_url_key = CONCAT('rptdemo-', LPAD(p.n, 3, '0')))) s
JOIN categories cat ON cat.category_name = s.cat AND cat.category_type = 'Ticket'
LEFT JOIN contacts ct ON ct.contact_email = s.contact_email AND ct.contact_archived_at IS NULL
LEFT JOIN clients cl ON cl.client_name = s.client_name
LEFT JOIN users au ON au.user_email = s.assignee_email
LEFT JOIN assets ast ON ast.asset_name = s.asset_name AND ast.asset_archived_at IS NULL
WHERE COALESCE(ct.contact_client_id, NULLIF(ast.asset_client_id, 0), cl.client_id) IS NOT NULL
ORDER BY s.rn;

-- Keep the app's own counter ahead of the numbers used here (the app does this on every new ticket).
UPDATE settings SET config_ticket_next_number = GREATEST(config_ticket_next_number, COALESCE((SELECT MAX(ticket_number) FROM tickets), 0) + 1)
 WHERE company_id = 1;

-- ---- 5. Replies and time entries -----------------------------------------------------------------------------
-- Only tickets that have no replies yet get any (so a re-run adds nothing). Times come from the ticket row itself.
DROP TABLE IF EXISTS ug_rpt_todo;
CREATE TABLE ug_rpt_todo AS
SELECT t.ticket_id, t.ticket_details, t.ticket_created_at, t.ticket_first_response_at, t.ticket_resolved_at, t.ticket_updated_at,
       t.ticket_assigned_to, t.ticket_contact_id, t.ticket_created_by, t.ticket_status, t.ticket_csat_rating, t.ticket_csat_rated_at,
       p.n, p.res_class, p.lab0, p.u_lab, p.u_l2, p.u_c1, p.u_ai, p.u_ci2, p.u_q
FROM tickets t
JOIN ug_rpt_plan p ON t.ticket_url_key = CONCAT('rptdemo-', LPAD(p.n, 3, '0'))
WHERE NOT EXISTS (SELECT 1 FROM ticket_replies r WHERE r.ticket_reply_ticket_id = t.ticket_id);

-- The original request ("Initial Issue"), exactly as the ticket page would create it on first view.
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT CONCAT('<strong>Initial Issue</strong><br>', d.ticket_details),
       IF(d.ticket_created_by = 0 AND d.ticket_contact_id > 0, 'Client', 'Internal'),
       d.ticket_created_at,
       IF(d.ticket_created_by = 0, d.ticket_contact_id, d.ticket_created_by),
       d.ticket_id
FROM ug_rpt_todo d;

-- First response by the assigned technician (a public reply with a few minutes of work).
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_emailed)
SELECT CONCAT('<p>', a.txt, '</p>'), 'Public', SEC_TO_TIME((5 + FLOOR(d.u_lab * 16)) * 60), d.ticket_first_response_at, d.ticket_assigned_to, d.ticket_id, 1
FROM ug_rpt_todo d JOIN ug_rpt_txt a ON a.kind = 'A' AND a.idx = 1 + FLOOR(d.u_ai * 4)
WHERE d.ticket_assigned_to > 0 AND d.ticket_first_response_at IS NOT NULL;

-- The main block of hands-on time ("Time logged" is the label the app gives a time-only entry).
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT 'Time logged', 'Labor', SEC_TO_TIME(GREATEST(300, ROUND(d.lab0 * (0.9 + 1.2 * d.u_lab) / 5) * 5 * 60)),
       GREATEST(d.ticket_first_response_at + INTERVAL 5 MINUTE, COALESCE(d.ticket_resolved_at, d.ticket_updated_at) - INTERVAL 25 MINUTE),
       d.ticket_assigned_to, d.ticket_id
FROM ug_rpt_todo d
WHERE d.ticket_assigned_to > 0 AND d.ticket_first_response_at IS NOT NULL AND d.lab0 > 0;

-- A second block of time on tickets that ran over several days (and some others).
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT 'Time logged', 'Labor', SEC_TO_TIME((20 + FLOOR(d.u_l2 * 70)) * 60),
       GREATEST(d.ticket_first_response_at + INTERVAL 5 MINUTE,
                LEAST(d.ticket_resolved_at - INTERVAL 30 MINUTE,
                      TIMESTAMP(DATE(d.ticket_first_response_at + INTERVAL (TIMESTAMPDIFF(MINUTE, d.ticket_first_response_at, d.ticket_resolved_at) DIV 2) MINUTE)) + INTERVAL (36000 + FLOOR(d.u_q * 18000)) SECOND)),
       d.ticket_assigned_to, d.ticket_id
FROM ug_rpt_todo d
WHERE d.ticket_assigned_to > 0 AND d.ticket_first_response_at IS NOT NULL AND d.ticket_resolved_at IS NOT NULL
  AND d.lab0 > 0 AND (d.res_class = 3 OR d.u_l2 < .25);

-- An internal note with some time on it, on about a third of the tickets.
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT CONCAT('<p>', c.txt, '</p>'), 'Internal', SEC_TO_TIME((10 + FLOOR(d.u_c1 * 26)) * 60),
       GREATEST(d.ticket_first_response_at + INTERVAL 3 MINUTE,
                d.ticket_first_response_at + INTERVAL FLOOR(TIMESTAMPDIFF(MINUTE, d.ticket_first_response_at, COALESCE(d.ticket_resolved_at, d.ticket_updated_at)) * 0.4) MINUTE),
       d.ticket_assigned_to, d.ticket_id
FROM ug_rpt_todo d JOIN ug_rpt_txt c ON c.kind = 'C' AND c.idx = 1 + FLOOR(d.u_ci2 * 6)
WHERE d.ticket_assigned_to > 0 AND d.ticket_first_response_at IS NOT NULL AND d.lab0 > 0 AND d.u_c1 < .35;

-- Closing a ticket in the app adds this one-minute system note (and so one minute of "time worked").
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT 'Ticket closed.', 'System', '00:01:00', d.ticket_resolved_at, d.ticket_assigned_to, d.ticket_id
FROM ug_rpt_todo d WHERE d.ticket_resolved_at IS NOT NULL AND d.ticket_assigned_to > 0;

-- Re-opened after a low rating: the requester says the problem is back.
INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_created_at, ticket_reply_by, ticket_reply_ticket_id)
SELECT '<p>The problem is back - please take another look.</p>', 'Client', d.ticket_csat_rated_at + INTERVAL 45 MINUTE, d.ticket_contact_id, d.ticket_id
FROM ug_rpt_todo d WHERE d.ticket_csat_rating IS NOT NULL AND d.ticket_status <> 5 AND d.ticket_contact_id > 0;

-- Point each ticket at its Initial Issue reply (the ticket page would otherwise create it - and touch
-- ticket_updated_at - the first time someone opened the ticket). The explicit self-assignment keeps the timestamp.
UPDATE tickets t JOIN ticket_replies r ON r.ticket_reply_ticket_id = t.ticket_id AND r.ticket_reply LIKE '<strong>Initial Issue</strong>%'
   SET t.ticket_initial_issue_reply_id = r.ticket_reply_id, t.ticket_updated_at = t.ticket_updated_at
 WHERE t.ticket_url_key LIKE 'rptdemo-%' AND t.ticket_initial_issue_reply_id IS NULL;

-- ---- 6. Scheduled reports ------------------------------------------------------------------------------------------
INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients, schedule_last_sent, schedule_active, schedule_created_at)
SELECT x.r, x.f, x.rc, x.ls, x.a, x.ca FROM (
  SELECT 'service_desk' AS r, 'weekly' AS f, 'it-leads@summitridge.example, raj.patel@summitridge.example' AS rc, @ug_now - INTERVAL 3 DAY AS ls, 1 AS a, @ug_now - INTERVAL 40 DAY AS ca
  UNION ALL SELECT 'csat', 'monthly', 'helen.brandt@summitridge.example', @ug_now - INTERVAL 12 DAY, 1, @ug_now - INTERVAL 75 DAY
  UNION ALL SELECT 'technician_performance', 'monthly', 'raj.patel@summitridge.example', NULL, 0, @ug_now - INTERVAL 20 DAY
) x WHERE NOT EXISTS (SELECT 1 FROM report_schedules s WHERE s.schedule_report = x.r AND s.schedule_frequency = x.f AND s.schedule_recipients = x.rc);

-- ---- 7. Credential rotation dates (the two Credential rotation reports) ---------------------------------------------
-- Credentials are created by another chapter and every new one is "changed today" with no rotation date, so both
-- reports would be empty. When fewer than five credentials are older than 90 days / fewer than six are due within
-- 30 days, back-date / schedule a few (chosen by a stable hash of the name). Does nothing when there are none.
SET @ug_old := (SELECT COUNT(*) FROM credentials WHERE credential_archived_at IS NULL AND credential_password_changed_at < @ug_now - INTERVAL 90 DAY);
UPDATE credentials c
JOIN (SELECT credential_id, ROW_NUMBER() OVER (ORDER BY CRC32(credential_name), credential_id) AS rn
        FROM credentials
       WHERE credential_archived_at IS NULL AND credential_password_changed_at >= @ug_now - INTERVAL 90 DAY) r ON r.credential_id = c.credential_id
   SET c.credential_password_changed_at = @ug_now - INTERVAL (96 + r.rn * 29) DAY,
       c.credential_updated_at = c.credential_updated_at
 WHERE r.rn <= GREATEST(0, 5 - @ug_old);

SET @ug_due := (SELECT COUNT(*) FROM credentials WHERE credential_archived_at IS NULL AND credential_rotation_due_at IS NOT NULL
                   AND credential_rotation_due_at <= DATE(@ug_now) + INTERVAL 30 DAY);
UPDATE credentials c
JOIN (SELECT credential_id, ROW_NUMBER() OVER (ORDER BY CRC32(CONCAT(credential_name, '|due')), credential_id) AS rn
        FROM credentials
       WHERE credential_archived_at IS NULL AND credential_rotation_due_at IS NULL) r ON r.credential_id = c.credential_id
   SET c.credential_rotation_due_at = DATE(@ug_now) + INTERVAL (CASE r.rn WHEN 1 THEN -21 WHEN 2 THEN -6 WHEN 3 THEN 4 WHEN 4 THEN 11 WHEN 5 THEN 19 ELSE 28 END) DAY,
       c.credential_last_rotated_at = IF(r.rn IN (2, 4, 5), @ug_now - INTERVAL (120 + r.rn * 17) DAY, c.credential_last_rotated_at),
       c.credential_updated_at = c.credential_updated_at
 WHERE r.rn <= GREATEST(0, 6 - @ug_due);

-- ---- 8. Clean up the working tables ---------------------------------------------------------------------------------
DROP TABLE IF EXISTS ug_rpt_todo;
DROP TABLE IF EXISTS ug_rpt_plan;
DROP TABLE IF EXISTS ug_rpt_cmt;
DROP TABLE IF EXISTS ug_rpt_txt;
DROP TABLE IF EXISTS ug_rpt_gc;
DROP TABLE IF EXISTS ug_rpt_gc0;
DROP TABLE IF EXISTS ug_rpt_subj;
DROP TABLE IF EXISTS ug_rpt_bd;
DROP TABLE IF EXISTS ug_rpt_num;
