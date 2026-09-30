-- Core demo data for the RivetIT user-guide screenshots: a fictional company
-- ("Summit Ridge Manufacturing"), its departments, employees, locations and a
-- couple of extra agent logins. Everything here is invented; all addresses use
-- the reserved .example domain and 555-01xx phone numbers.
--
-- Run AFTER scripts/setup_cli.php has created the company and first admin
-- (Alex Morgan). Later seed files look these rows up by NAME, never by numeric
-- id, so they keep working whatever AUTO_INCREMENT values the database started at.

-- ---- Extra agent logins (password for all demo users: DemoPass#2026) ----------
INSERT INTO users (user_name, user_title, user_email, user_password, user_type, user_status, user_role_id, user_color)
SELECT * FROM (
  SELECT 'Priya Nair' AS n, 'IT Support Technician' AS t, 'priya.nair@summitridge.example' AS e, '$2y$12$W.TLNA5HWkI38uveTbtJquCC9klTULnJRB5QRCqUpB1XDQ1ugUrL6' AS p, 1 AS ty, 1 AS s, 2 AS r, '#2f80ed' AS c
  UNION ALL SELECT 'Marcus Lee', 'Network Administrator', 'marcus.lee@summitridge.example', '$2y$12$W.TLNA5HWkI38uveTbtJquCC9klTULnJRB5QRCqUpB1XDQ1ugUrL6', 1, 1, 2, '#27ae60'
) x WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.user_email = x.e);
INSERT INTO user_settings (user_id)
SELECT user_id FROM users WHERE user_email IN ('priya.nair@summitridge.example','marcus.lee@summitridge.example')
  AND user_id NOT IN (SELECT user_id FROM user_settings);

-- ---- Departments (the app calls clients "Departments") ------------------------
INSERT INTO clients (client_name, client_type, client_abbreviation, client_status, client_currency_code, client_net_terms, client_notes, client_favorite)
SELECT * FROM (
  SELECT 'Executive Office' AS n, 'Leadership' AS t, 'EXEC' AS a, 'Active' AS s, 'USD' AS c, 30 AS nt, 'CEO, COO and executive assistants.' AS notes, 1 AS fav
  UNION ALL SELECT 'Finance & Accounting', 'Business Services', 'FIN', 'Active', 'USD', 30, 'Payroll, accounts payable and receivable, month-end close.', 0
  UNION ALL SELECT 'Human Resources', 'Business Services', 'HR', 'Active', 'USD', 30, 'Hiring, onboarding, benefits and compliance training.', 1
  UNION ALL SELECT 'Sales & Marketing', 'Commercial', 'SALES', 'Active', 'USD', 30, 'Customer accounts, quotes and marketing campaigns.', 0
  UNION ALL SELECT 'Production', 'Operations', 'PROD', 'Active', 'USD', 30, 'Plant floor: machining, assembly and quality inspection.', 1
  UNION ALL SELECT 'Warehouse & Logistics', 'Operations', 'WHSE', 'Active', 'USD', 30, 'Receiving, inventory, shipping and fleet.', 0
  UNION ALL SELECT 'Engineering', 'Operations', 'ENG', 'Active', 'USD', 30, 'Product design, CAD and process engineering.', 0
) x WHERE NOT EXISTS (SELECT 1 FROM clients c WHERE c.client_name = x.n);

-- ---- Employees (contacts) ------------------------------------------------------
INSERT INTO contacts (contact_name, contact_employee_id, contact_title, contact_email, contact_phone, contact_extension, contact_mobile, contact_department, contact_work_arrangement, contact_start_date, contact_client_id, contact_technical, contact_important)
SELECT p.n, p.eid, p.t, p.e, p.ph, p.ext, p.mob, cl.client_name, p.wa, p.sd, cl.client_id, p.tech, p.imp
FROM (
  SELECT 'Helen Brandt' AS n, 'E1001' AS eid, 'Chief Executive Officer' AS t, 'helen.brandt@summitridge.example' AS e, '608-555-0101' AS ph, '101' AS ext, '608-555-0201' AS mob, 'Executive Office' AS dept, 'onsite' AS wa, '2014-03-10' AS sd, 0 AS tech, 1 AS imp
  UNION ALL SELECT 'Raj Patel','E1002','Chief Operating Officer','raj.patel@summitridge.example','608-555-0102','102','608-555-0202','Executive Office','onsite','2016-08-22',0,1
  UNION ALL SELECT 'Grace Okafor','E1010','Finance Director','grace.okafor@summitridge.example','608-555-0110','110','608-555-0210','Finance & Accounting','hybrid','2017-01-16',0,1
  UNION ALL SELECT 'Tom Kessler','E1011','Senior Accountant','tom.kessler@summitridge.example','608-555-0111','111','608-555-0211','Finance & Accounting','onsite','2019-05-06',0,0
  UNION ALL SELECT 'Lena Fischer','E1012','Accounts Payable Clerk','lena.fischer@summitridge.example','608-555-0112','112',NULL,'Finance & Accounting','onsite','2021-09-13',0,0
  UNION ALL SELECT 'Miguel Alvarez','E1020','HR Manager','miguel.alvarez@summitridge.example','608-555-0120','120','608-555-0220','Human Resources','hybrid','2018-02-19',0,1
  UNION ALL SELECT 'Sophie Tran','E1021','HR Coordinator','sophie.tran@summitridge.example','608-555-0121','121',NULL,'Human Resources','onsite','2022-04-04',0,0
  UNION ALL SELECT 'Nina Rossi','E1030','Sales Director','nina.rossi@summitridge.example','608-555-0130','130','608-555-0230','Sales & Marketing','hybrid','2016-11-07',0,1
  UNION ALL SELECT 'Owen Baker','E1031','Account Executive','owen.baker@summitridge.example','608-555-0131','131','608-555-0231','Sales & Marketing','remote','2020-06-01',0,0
  UNION ALL SELECT 'Zoe Hartman','E1032','Marketing Specialist','zoe.hartman@summitridge.example','608-555-0132','132',NULL,'Sales & Marketing','hybrid','2021-01-25',0,0
  UNION ALL SELECT 'Ben Carter','E1033','Sales Coordinator','ben.carter@summitridge.example','608-555-0133','133',NULL,'Sales & Marketing','onsite','2023-03-13',0,0
  UNION ALL SELECT 'Carlos Mendoza','E1040','Plant Manager','carlos.mendoza@summitridge.example','608-555-0140','140','608-555-0240','Production','onsite','2015-07-20',0,1
  UNION ALL SELECT 'Aisha Rahman','E1041','Production Supervisor','aisha.rahman@summitridge.example','608-555-0141','141','608-555-0241','Production','onsite','2018-10-08',0,0
  UNION ALL SELECT 'Jake Sullivan','E1042','Machine Operator','jake.sullivan@summitridge.example','608-555-0142','142',NULL,'Production','onsite','2022-08-15',0,0
  UNION ALL SELECT 'Emma Novak','E1043','Quality Inspector','emma.novak@summitridge.example','608-555-0143','143',NULL,'Production','onsite','2020-02-03',0,0
  UNION ALL SELECT 'Frank Delgado','E1050','Logistics Manager','frank.delgado@summitridge.example','608-555-0150','150','608-555-0250','Warehouse & Logistics','onsite','2017-05-30',0,1
  UNION ALL SELECT 'Tara Whitfield','E1051','Shipping Coordinator','tara.whitfield@summitridge.example','608-555-0151','151',NULL,'Warehouse & Logistics','onsite','2021-11-01',0,0
  UNION ALL SELECT 'Liam O''Connor','E1052','Forklift Operator','liam.oconnor@summitridge.example','608-555-0152','152',NULL,'Warehouse & Logistics','onsite','2023-06-12',0,0
  UNION ALL SELECT 'Yuki Tanaka','E1060','Engineering Manager','yuki.tanaka@summitridge.example','608-555-0160','160','608-555-0260','Engineering','hybrid','2016-04-11',1,1
  UNION ALL SELECT 'Ivan Petrov','E1061','Process Engineer','ivan.petrov@summitridge.example','608-555-0161','161',NULL,'Engineering','onsite','2019-09-23',1,0
  UNION ALL SELECT 'Maya Singh','E1062','CAD Designer','maya.singh@summitridge.example','608-555-0162','162',NULL,'Engineering','hybrid','2022-01-10',0,0
) p JOIN clients cl ON cl.client_name = p.dept
WHERE NOT EXISTS (SELECT 1 FROM contacts c WHERE c.contact_email = p.e);

-- ---- Reporting lines (drives the Org Chart) ------------------------------------
CREATE TEMPORARY TABLE tmp_mgr (person VARCHAR(200), boss VARCHAR(200));
INSERT INTO tmp_mgr VALUES
 ('Raj Patel','Helen Brandt'),('Grace Okafor','Helen Brandt'),('Miguel Alvarez','Helen Brandt'),('Nina Rossi','Helen Brandt'),
 ('Tom Kessler','Grace Okafor'),('Lena Fischer','Grace Okafor'),
 ('Sophie Tran','Miguel Alvarez'),
 ('Owen Baker','Nina Rossi'),('Zoe Hartman','Nina Rossi'),('Ben Carter','Nina Rossi'),
 ('Carlos Mendoza','Raj Patel'),('Frank Delgado','Raj Patel'),('Yuki Tanaka','Raj Patel'),
 ('Aisha Rahman','Carlos Mendoza'),('Jake Sullivan','Aisha Rahman'),('Emma Novak','Aisha Rahman'),
 ('Tara Whitfield','Frank Delgado'),('Liam O''Connor','Frank Delgado'),
 ('Ivan Petrov','Yuki Tanaka'),('Maya Singh','Yuki Tanaka');
UPDATE contacts c JOIN tmp_mgr t ON c.contact_name = t.person JOIN contacts m ON m.contact_name = t.boss
   SET c.contact_manager_id = m.contact_id;
DROP TEMPORARY TABLE tmp_mgr;

-- Department heads
UPDATE clients cl JOIN contacts ct ON ct.contact_name = CASE cl.client_name
    WHEN 'Executive Office' THEN 'Helen Brandt' WHEN 'Finance & Accounting' THEN 'Grace Okafor'
    WHEN 'Human Resources' THEN 'Miguel Alvarez' WHEN 'Sales & Marketing' THEN 'Nina Rossi'
    WHEN 'Production' THEN 'Carlos Mendoza' WHEN 'Warehouse & Logistics' THEN 'Frank Delgado'
    WHEN 'Engineering' THEN 'Yuki Tanaka' END
   SET cl.client_head_contact_id = ct.contact_id;
-- The department list's "Primary contact" column reads contact_primary, so mark each head.
UPDATE contacts ct JOIN clients cl ON cl.client_head_contact_id = ct.contact_id SET ct.contact_primary = 1;

-- ---- Locations, linked to departments through department_sites -------------------
INSERT INTO locations (location_name, location_type, location_address, location_city, location_state, location_zip, location_country, location_phone, location_primary, location_client_id)
SELECT * FROM (
  SELECT 'Headquarters - Madison' AS n, 'Office' AS t, '1200 Ridge Road' AS a, 'Madison' AS c, 'WI' AS s, '53703' AS z, 'United States' AS co, '608-555-0100' AS ph, 1 AS pr, 0 AS cid
  UNION ALL SELECT 'Plant 1 - Sun Prairie', 'Manufacturing', '450 Industrial Parkway', 'Sun Prairie', 'WI', '53590', 'United States', '608-555-0140', 0, 0
  UNION ALL SELECT 'Distribution Center - Milwaukee', 'Warehouse', '88 Harbor Drive', 'Milwaukee', 'WI', '53202', 'United States', '414-555-0150', 0, 0
) x WHERE NOT EXISTS (SELECT 1 FROM locations l WHERE l.location_name = x.n);

-- A department's "primary location" is the lowest-numbered site linked to it, and Headquarters is
-- created first - so Production and Warehouse are linked ONLY to their own site to show it.
INSERT IGNORE INTO department_sites (client_id, location_id)
SELECT c.client_id, l.location_id FROM clients c JOIN locations l ON (
      (l.location_name = 'Headquarters - Madison' AND c.client_name IN ('Executive Office','Finance & Accounting','Human Resources','Sales & Marketing','Engineering'))
   OR (l.location_name = 'Plant 1 - Sun Prairie' AND c.client_name IN ('Production','Engineering'))
   OR (l.location_name = 'Distribution Center - Milwaukee' AND c.client_name IN ('Warehouse & Logistics','Sales & Marketing')));

UPDATE contacts c JOIN locations l ON l.location_name = CASE c.contact_department
    WHEN 'Production' THEN 'Plant 1 - Sun Prairie' WHEN 'Warehouse & Logistics' THEN 'Distribution Center - Milwaukee'
    ELSE 'Headquarters - Madison' END
   SET c.contact_location_id = l.location_id WHERE c.contact_location_id = 0;
