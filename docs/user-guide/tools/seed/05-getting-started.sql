-- Demo data for the "Getting started" guide page.
--
-- Adds only what the screenshots need and nothing that changes how anyone signs in:
--   * ONE former employee who is already archived, so the Archived view of the People list (used to
--     explain Archive vs Delete, Restore and the bulk menu) has a row that belongs to this page and
--     does not depend on another guide's seed;
--   * a few in-app notifications for the demo administrator, so the bell in the top bar opens onto a
--     realistic list instead of "No Notifications". The wording copies what the app's own cron jobs
--     write (appNotify() in cron/cron.php), so nothing here looks different from the real thing.
-- It never touches users, roles or settings.
--
-- Depends on 00-core.sql only. Re-runnable: every insert is guarded by WHERE NOT EXISTS on a natural
-- key, parents are looked up by name / e-mail (never by numeric id) and dates are relative to NOW().

-- ---- A former employee, already archived ---------------------------------------------------------
INSERT INTO contacts (contact_name, contact_employee_id, contact_title, contact_email, contact_phone, contact_extension,
                      contact_department, contact_work_arrangement, contact_start_date, contact_employment_status,
                      contact_client_id, contact_location_id, contact_manager_id, contact_archived_at)
SELECT 'Marlon Ibarra', 'E1090', 'Warehouse Associate', 'marlon.ibarra@summitridge.example', '608-555-0154', '154',
       cl.client_name, 'onsite', '2021-03-08', 'terminated',
       cl.client_id,
       COALESCE((SELECT location_id FROM locations WHERE location_name = 'Distribution Center - Milwaukee' LIMIT 1), 0),
       (SELECT contact_id FROM contacts WHERE contact_email = 'frank.delgado@summitridge.example' LIMIT 1),
       NOW() - INTERVAL 24 DAY
FROM clients cl
WHERE cl.client_name = 'Warehouse & Logistics'
  AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.contact_email = 'marlon.ibarra@summitridge.example');

-- ---- Notifications for the demo administrator (the bell in the top bar) ---------------------------
-- Same types and sentence patterns the app writes: "Pending Tickets", "Certificate Expiring",
-- "Domain Expiring", "Backup". The bell lists newest first, so the smallest INTERVAL is the top row.

-- "Pending Tickets": the app counts tickets whose status is New (1). Only written when there are some,
-- so the number always matches the Tickets list.
INSERT INTO notifications (notification_type, notification, notification_action, notification_timestamp,
                           notification_client_id, notification_user_id)
SELECT 'Pending Tickets', CONCAT('There are ', t.n, ' new tickets pending assignment'),
       '/agent/tickets.php?status=New', NOW() - INTERVAL 25 MINUTE, 0, u.user_id
FROM (SELECT COUNT(*) AS n FROM tickets WHERE ticket_status = 1) t
JOIN users u ON u.user_email = 'alex.morgan@summitridge.example'
WHERE t.n > 0
  AND NOT EXISTS (SELECT 1 FROM notifications n
                  WHERE n.notification_user_id = u.user_id AND n.notification_type = 'Pending Tickets');

INSERT INTO notifications (notification_type, notification, notification_action, notification_timestamp,
                           notification_client_id, notification_user_id)
SELECT x.t, x.msg, x.act, NOW() - INTERVAL x.mins MINUTE,
       COALESCE((SELECT client_id FROM clients WHERE client_name = x.dept LIMIT 1), 0),
       u.user_id
FROM (
  SELECT 'Certificate Expiring' AS t,
         CONCAT('Certificate portal.summitridge.example for Executive Office will expire in 14 day(s) on ', DATE_FORMAT(NOW() + INTERVAL 14 DAY, '%Y-%m-%d')) AS msg,
         '/agent/certificates.php' AS act, 95 AS mins, 'Executive Office' AS dept
  UNION ALL SELECT 'Domain Expiring',
         CONCAT('Domain summitridge-mfg.example for Sales & Marketing will expire in 30 Days on ', DATE_FORMAT(NOW() + INTERVAL 30 DAY, '%Y-%m-%d')),
         '/agent/domains.php', 340, 'Sales & Marketing'
  UNION ALL SELECT 'Backup',
         'Auto-backup saved: summit-ridge-nightly.zip',
         '/admin/backup.php', 610, NULL
) x
JOIN users u ON u.user_email = 'alex.morgan@summitridge.example'
WHERE NOT EXISTS (
  SELECT 1 FROM notifications n
  WHERE n.notification_user_id = u.user_id
    AND n.notification_type = x.t
    AND n.notification LIKE CONCAT(SUBSTRING_INDEX(x.msg, ' will expire', 1), '%')
);
