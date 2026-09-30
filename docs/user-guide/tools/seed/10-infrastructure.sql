-- Demo data for the "Infrastructure" part of the user guide: assets, locations, vendors,
-- licences, domains, certificates, networks, racks, services, contracts and files.
--
-- Everything is fictional: .example domains, 555-01xx phones, IPs from 10.0.0.0/8 and
-- 192.0.2.0/24, MAC addresses from the documentation range 00:00:5E:00:53:xx, made-up
-- serials and licence keys. Rows are looked up by NAME, never by numeric id, and every
-- insert is guarded so the file can be run again without creating duplicates.
--
-- Depends on 00-core.sql only (departments, contacts, locations, users).
--
-- Shared infrastructure (server room, HQ network, printers, main domain, services) belongs to
-- the "Executive Office" department, the head-office department at Headquarters. Plant and
-- warehouse gear belongs to Production and Warehouse & Logistics. Addressing follows the
-- "Site Network Reference" knowledge article: HQ 10.10.x.x, Plant 1 10.20.x.x, Distribution
-- Center 10.30.x.x.
--
-- Credentials, tickets and RMM links for these assets come from other seeds, which look the
-- assets up by name (see the cross-module contract in the guide brief).

SET NAMES utf8mb4;

SET @u_alex   = (SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example'  LIMIT 1);
SET @u_priya  = (SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'   LIMIT 1);
SET @u_marcus = (SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'   LIMIT 1);

-- ======================================================================================
-- 1. Locations: fill in the blanks on the three sites created by 00-core (only where empty)
-- ======================================================================================
UPDATE locations SET location_phone_country_code = '1'
 WHERE location_name IN ('Headquarters - Madison','Plant 1 - Sun Prairie','Distribution Center - Milwaukee')
   AND (location_phone_country_code IS NULL OR location_phone_country_code = '');

UPDATE locations
   SET location_description = 'Corporate offices, engineering studio and the main server room',
       location_hours = 'Monday: 7:30 AM - 5:30 PM, Tuesday: 7:30 AM - 5:30 PM, Wednesday: 7:30 AM - 5:30 PM, Thursday: 7:30 AM - 5:30 PM, Friday: 7:30 AM - 5:30 PM, Saturday: Closed, Sunday: Closed',
       location_notes = 'Visitor parking is in Lot B. Badge required after 6 PM. The server room key is held by IT.'
 WHERE location_name = 'Headquarters - Madison' AND (location_hours IS NULL OR location_hours = '');

UPDATE locations
   SET location_description = 'Machining, assembly and quality inspection',
       location_hours = 'Monday: 6:00 AM - 10:30 PM, Tuesday: 6:00 AM - 10:30 PM, Wednesday: 6:00 AM - 10:30 PM, Thursday: 6:00 AM - 10:30 PM, Friday: 6:00 AM - 10:30 PM, Saturday: 7:00 AM - 3:00 PM, Sunday: Closed',
       location_notes = 'Safety glasses are required beyond the front office. The network closet (IDF) is behind the quality lab.'
 WHERE location_name = 'Plant 1 - Sun Prairie' AND (location_hours IS NULL OR location_hours = '');

UPDATE locations
   SET location_description = 'Receiving, inventory and outbound shipping',
       location_hours = 'Monday: 6:00 AM - 6:00 PM, Tuesday: 6:00 AM - 6:00 PM, Wednesday: 6:00 AM - 6:00 PM, Thursday: 6:00 AM - 6:00 PM, Friday: 6:00 AM - 6:00 PM, Saturday: 8:00 AM - 12:00 PM, Sunday: Closed',
       location_notes = 'Truck deliveries use Dock 3. Wi-Fi scanners roam across the whole floor.'
 WHERE location_name = 'Distribution Center - Milwaukee' AND (location_hours IS NULL OR location_hours = '');

-- ======================================================================================
-- 2. Tags (location tags are type 2, asset tags are type 5)
-- ======================================================================================
INSERT INTO tags (tag_name, tag_type, tag_color, tag_icon)
SELECT x.n, x.t, x.c, x.i FROM (
  SELECT 'Owned Site' AS n, 2 AS t, '#198754' AS c, 'home' AS i
  UNION ALL SELECT 'Leased Site', 2, '#fd7e14', 'file-contract'
  UNION ALL SELECT 'Critical Infrastructure', 5, '#dc3545', 'exclamation-triangle'
  UNION ALL SELECT 'Loaner Device', 5, '#0dcaf0', 'exchange-alt'
  UNION ALL SELECT 'Refresh 2026', 5, '#6f42c1', 'sync-alt'
  UNION ALL SELECT 'Shop Floor', 5, '#fd7e14', 'industry'
) x WHERE NOT EXISTS (SELECT 1 FROM tags t WHERE t.tag_name = x.n AND t.tag_type = x.t);

INSERT INTO location_tags (location_id, tag_id)
SELECT l.location_id, t.tag_id
FROM (
  SELECT 'Headquarters - Madison' AS ln, 'Owned Site' AS tn
  UNION ALL SELECT 'Plant 1 - Sun Prairie', 'Owned Site'
  UNION ALL SELECT 'Distribution Center - Milwaukee', 'Leased Site'
) m
JOIN locations l ON l.location_name = m.ln
JOIN tags t ON t.tag_name = m.tn AND t.tag_type = 2
WHERE NOT EXISTS (SELECT 1 FROM location_tags x WHERE x.location_id = l.location_id AND x.tag_id = t.tag_id);

-- ======================================================================================
-- 3. Vendors. Company-wide vendors (client 0) show on Infrastructure > Vendors; the last two
--    belong to one department and only show inside that department's workspace.
-- ======================================================================================
INSERT INTO vendors (vendor_name, vendor_description, vendor_contact_name, vendor_phone_country_code, vendor_phone, vendor_extension,
                     vendor_email, vendor_website, vendor_hours, vendor_sla, vendor_code, vendor_account_number, vendor_notes, vendor_client_id)
SELECT x.name, x.descr, x.contact, '1', x.phone, x.ext, x.email, x.website, x.hours, x.sla, x.code, x.acct, x.notes,
       COALESCE((SELECT c.client_id FROM clients c WHERE c.client_name = x.dept), 0)
FROM (
  SELECT 'Lakeshore Technology Partners' AS name, 'Hardware, software and Microsoft licensing reseller' AS descr, 'Renee Castillo' AS contact,
         '6085550177' AS phone, '214' AS ext, 'accounts@lakeshore-tech.example' AS email, 'lakeshore-tech.example' AS website,
         'Mon-Fri 8:00 AM - 5:00 PM CT' AS hours, 'Next-business-day shipping on stocked items' AS sla, 'LTP-EXEC' AS code, 'LTP-20418' AS acct,
         'Primary reseller for laptops, desktops, servers and Microsoft 365. Quotes are valid for 30 days. Ask for Renee on rush orders.' AS notes,
         '' AS dept
  UNION ALL SELECT 'NameHarbor Domains', 'Domain registrar', 'Support desk',
         '4155550121', NULL, 'support@nameharbor.example', 'nameharbor.example',
         '24/7 chat and email', 'Domain unlock within 1 business day', 'NH', 'NH-77129',
         'Registrar of record for all company domains. Auto-renew is off, so renew manually at least 30 days before expiry.', ''
  UNION ALL SELECT 'Cobalt Cloud Hosting', 'Web hosting and managed DNS', 'Aiden Park',
         '3125550188', NULL, 'help@cobalt-hosting.example', 'cobalt-hosting.example',
         '24/7', '99.9% uptime, 30-minute response on critical tickets', 'CCH', 'CCH-30551',
         'Hosts the corporate website and the parts catalog, and provides DNS for both.', ''
  UNION ALL SELECT 'Meridian Business Internet', 'Fiber internet circuit (500 Mbps) at Headquarters', 'NOC dispatch',
         '6085550166', NULL, 'noc@meridian-bi.example', 'meridian-bi.example',
         '24/7 network operations centre', '4-hour outage response, 99.95% availability', 'MBI', 'MBI-55210',
         'Circuit ID MBI-FIB-0093. Report outages to the NOC first, then open a ticket with the vendor reference.', ''
  UNION ALL SELECT 'Keystone ERP Software', 'Manufacturing ERP vendor: support and maintenance', 'Priority support',
         '4145550133', NULL, 'support@keystone-erp.example', 'keystone-erp.example',
         'Mon-Fri 7:00 AM - 7:00 PM CT', 'Priority 1: 2-hour response', 'KES', 'KES-8841',
         'Annual maintenance renews each March.', ''
  UNION ALL SELECT 'Beacon Print & Imaging', 'Managed print and copier service', 'Tessa Brandvold',
         '6085550155', NULL, 'service@beacon-print.example', 'beacon-print.example',
         'Mon-Fri 8:00 AM - 4:30 PM', 'Next-day on-site service', 'BPI', 'BPI-1207',
         'Supplies toner and services the HQ and plant printers.', 'Executive Office'
  UNION ALL SELECT 'Great Lakes Machine Service', 'CNC and tooling maintenance', 'Gus Halvorsen',
         '6085550181', NULL, 'dispatch@glms.example', 'glms.example',
         'Mon-Sat 6:00 AM - 6:00 PM', '24-hour emergency callout', 'GLMS', 'GLMS-330',
         'Annual preventive maintenance in January. The emergency line is answered around the clock.', 'Production'
) x
WHERE NOT EXISTS (SELECT 1 FROM vendors v WHERE v.vendor_name = x.name);

-- ======================================================================================
-- 4. Networks (department workspace > Networks; also listed company-wide)
-- ======================================================================================
INSERT INTO networks (network_name, network_description, network_vlan, network, network_gateway, network_primary_dns, network_secondary_dns,
                      network_dhcp_range, network_notes, network_location_id, network_client_id)
SELECT x.name, x.descr, x.vlan, x.cidr, x.gw, x.dns1, x.dns2, x.rng, x.notes,
       COALESCE((SELECT l.location_id FROM locations l WHERE l.location_name = x.loc), 0),
       (SELECT c.client_id FROM clients c WHERE c.client_name = x.dept)
FROM (
  SELECT 'HQ Servers & Network' AS name, 'Servers, firewall, switches and access-point management addresses at Headquarters' AS descr, 10 AS vlan,
         '10.10.1.0/24' AS cidr, '10.10.1.1' AS gw, '10.10.1.10' AS dns1, '10.10.1.20' AS dns2, '10.10.1.10-10.10.1.60' AS rng,
         'All addresses are static. Servers use .10 - .29, network gear uses .1 - .9.' AS notes,
         'Headquarters - Madison' AS loc, 'Executive Office' AS dept
  UNION ALL SELECT 'HQ Offices', 'Staff PCs, printers and copiers at Headquarters', 20,
         '10.10.20.0/24', '10.10.20.1', '10.10.1.10', '10.10.1.20', '10.10.20.100-10.10.20.220',
         'Printers hold fixed addresses in 10.10.20.40 - .60. Everything from .100 up is handed out by DHCP.',
         'Headquarters - Madison', 'Executive Office'
  UNION ALL SELECT 'Plant 1 Production LAN', 'Plant floor PCs, printers and the engineering office', 50,
         '10.20.5.0/24', '10.20.5.1', '10.10.1.10', '10.10.1.20', '10.20.5.100-10.20.5.199',
         'Routed to Headquarters over the site-to-site VPN. Static addresses are below .100.',
         'Plant 1 - Sun Prairie', 'Production'
  UNION ALL SELECT 'Distribution Center LAN', 'Warehouse office, handheld scanners and tablets', 60,
         '10.30.5.0/24', '10.30.5.1', '10.10.1.10', '10.10.1.20', '10.30.5.100-10.30.5.240',
         'Scanner Wi-Fi (SR-Warehouse-Scan) lands on this VLAN.',
         'Distribution Center - Milwaukee', 'Warehouse & Logistics'
) x
WHERE NOT EXISTS (SELECT 1 FROM networks n WHERE n.network_name = x.name);

-- ======================================================================================
-- 5. Assets
--    Columns: name, type, make, model, serial, os, status, department, assigned-to e-mail, location,
--    physical location, asset tag, description, purchase reference, days since purchase,
--    warranty years, days since install, favourite, URI, department URI, AnyDesk id, PIN, notes,
--    vendor, days since archived.
-- ======================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_ia;
CREATE TEMPORARY TABLE tmp_ia (
  seq INT AUTO_INCREMENT PRIMARY KEY,
  a_name VARCHAR(200), a_type VARCHAR(200), a_make VARCHAR(200), a_model VARCHAR(200), a_serial VARCHAR(200), a_os VARCHAR(200),
  a_status VARCHAR(200), a_dept VARCHAR(200), a_contact VARCHAR(200), a_loc VARCHAR(200), a_phys VARCHAR(200),
  a_atag VARCHAR(100), a_desc VARCHAR(255), a_pref VARCHAR(200),
  a_buy_days INT, a_warr_years INT, a_inst_days INT, a_fav TINYINT,
  a_uri VARCHAR(500), a_uri_client VARCHAR(500), a_anydesk VARCHAR(50), a_pin VARCHAR(50), a_notes TEXT, a_vendor VARCHAR(200), a_arch_days INT
) DEFAULT CHARSET = utf8mb4;

INSERT INTO tmp_ia (a_name, a_type, a_make, a_model, a_serial, a_os, a_status, a_dept, a_contact, a_loc, a_phys, a_atag, a_desc, a_pref,
                    a_buy_days, a_warr_years, a_inst_days, a_fav, a_uri, a_uri_client, a_anydesk, a_pin, a_notes, a_vendor, a_arch_days) VALUES
 -- Laptops and desktops -------------------------------------------------------------------------
 ('LT-EXEC-01','Laptop','Lenovo','ThinkPad X1 Carbon Gen 11','PF3K9M2X','Windows 11 Pro','Deployed','Executive Office','helen.brandt@summitridge.example','Headquarters - Madison','Executive suite, office 1',
  'SRM-0101','CEO laptop','PO-7712', 210,3,203,1, NULL,NULL,'845 210 337',NULL,'Docking station and second monitor at the desk. BitLocker is on; the recovery key is stored in the credential vault.','Lakeshore Technology Partners',NULL),
 ('LT-FIN-02','Laptop','Dell','Latitude 7440','5J8MT41','Windows 11 Pro','Deployed','Finance & Accounting','tom.kessler@summitridge.example','Headquarters - Madison','Finance office 1',
  'SRM-0103','Senior Accountant laptop','PO-7712', 210,3,204,0, NULL,NULL,NULL,NULL,'Re-assigned from Lena Fischer when she moved to the accounts payable desktop.','Lakeshore Technology Partners',NULL),
 ('DT-HR-01','Desktop','HP','EliteDesk 800 G9 Small Form Factor','5CG3421NQX','Windows 11 Pro','Deployed','Human Resources','miguel.alvarez@summitridge.example','Headquarters - Madison','HR office',
  'SRM-0104','HR Manager desktop','PO-7406', 640,3,632,0, NULL,NULL,NULL,NULL,'Locked-down profile: no USB storage. Used for benefits and payroll portals.','Lakeshore Technology Partners',NULL),
 ('DT-SALES-03','Desktop','Dell','OptiPlex 7010 Tower','2R6PD73','Windows 11 Pro','Deployed','Sales & Marketing','owen.baker@summitridge.example','Headquarters - Madison','Home office (remote)',
  'SRM-0105','Account Executive desktop for a remote worker','PO-7501', 520,3,515,0, NULL,NULL,'712 004 519',NULL,'Shipped to the employee home office. Remote support through AnyDesk.','Lakeshore Technology Partners',NULL),
 ('LT-SALES-01','Laptop','Apple','MacBook Pro 14-inch (M3 Pro)','FVFHK3R7Q6','macOS Sonoma 14','Deployed','Sales & Marketing','nina.rossi@summitridge.example','Headquarters - Madison',NULL,
  'SRM-0106','Sales Director laptop','PO-7790', 150,3,145,0, NULL,NULL,NULL,NULL,'Three-year AppleCare+ purchased with the laptop.','Lakeshore Technology Partners',NULL),
 ('LT-ENG-04','Laptop','Dell','Precision 5570','9K4VW62','Windows 11 Pro for Workstations','Deployed','Engineering','maya.singh@summitridge.example','Headquarters - Madison','Design studio',
  'SRM-0107','CAD laptop with discrete graphics','PO-6894', 1050,3,1040,0, NULL,NULL,NULL,NULL,'Warranty ends soon. Include a replacement in the next budget round.','Lakeshore Technology Partners',NULL),
 ('WS-ENG-01','Desktop','Dell','Precision 5860 Tower','4T7YB18','Windows 11 Pro for Workstations','Deployed','Engineering','yuki.tanaka@summitridge.example','Headquarters - Madison','Engineering lab',
  'SRM-0108','Engineering Manager workstation','PO-7245', 380,3,372,0, NULL,NULL,NULL,NULL,NULL,'Lakeshore Technology Partners',NULL),
 ('WS-ENG-02','Desktop','Dell','Precision 3660 Tower','1M2XC55','Windows 11 Pro','Deployed','Engineering','ivan.petrov@summitridge.example','Plant 1 - Sun Prairie','Process engineering office',
  'SRM-0109','Process Engineer workstation','PO-7245', 380,3,371,0, NULL,NULL,NULL,NULL,NULL,'Lakeshore Technology Partners',NULL),
 ('PC-PLANT-07','Desktop','Dell','OptiPlex 3000 Micro','8P5RZ40','Windows 10 Enterprise LTSC','Deployed','Production','aisha.rahman@summitridge.example','Plant 1 - Sun Prairie','Assembly line 2, supervisor station',
  'SRM-0110','Production supervisor PC','PO-7311', 560,3,555,0, NULL,NULL,NULL,NULL,'Mounted in a dust-proof enclosure. Clean the filter every quarter.','Lakeshore Technology Partners',NULL),
 ('LT-WHSE-01','Laptop','Lenovo','ThinkPad L14 Gen 4','PF4Q7TB1','Windows 11 Pro','Out for Repair','Warehouse & Logistics','frank.delgado@summitridge.example','Distribution Center - Milwaukee','Logistics office',
  'SRM-0112','Logistics Manager laptop','PO-7620', 300,3,296,0, NULL,NULL,NULL,NULL,'Sent to the repair depot for a cracked display. Loaner LT-SPARE-01 issued in the meantime.','Lakeshore Technology Partners',NULL),
 ('LT-SPARE-01','Laptop','Lenovo','ThinkPad L14 Gen 4','PF4Q7TC9','Windows 11 Pro','Ready to Deploy','','','Headquarters - Madison','IT storage cabinet',
  'SRM-0113','Spare laptop for loans and new hires','PO-7620', 300,3,NULL,0, NULL,NULL,NULL,NULL,'Imaged and patched. Not tied to any department until it is handed out.','Lakeshore Technology Partners',NULL),
 ('LT-OLD-03','Laptop','HP','EliteBook 840 G5','5CG8112KLM','Windows 10 Pro','Retired','Finance & Accounting','','Headquarters - Madison',NULL,
  'SRM-0044','Former AP clerk laptop, retired in the laptop refresh','PO-5120', 2100,3,2090,0, NULL,NULL,NULL,NULL,'Drive wiped and certificate of destruction filed. Kept in the archive for the audit trail.',NULL,30),
 -- Servers and virtual machines ---------------------------------------------------------------------
 ('SRV-FILE-01','Server','Dell','PowerEdge T350','6B2QX93','Windows Server 2022 Standard','Deployed','Executive Office','','Headquarters - Madison','Server room, Rack A, U5-8',
  'SRM-0201','Company file server: shared drives and home folders','PO-7782', 690,5,680,1, 'https://10.10.1.12',NULL,NULL,NULL,'Tower model fitted with a 4U rack kit. Nightly backup runs at 22:00. Read the backup and restore runbook before rebooting.','Lakeshore Technology Partners',NULL),
 ('SRV-APP-01','Server','Dell','PowerEdge R450','2L9WD37','Windows Server 2022 Standard','Deployed','Executive Office','','Headquarters - Madison','Server room, Rack A, U9',
  'SRM-0202','Application server and Hyper-V host','PO-7782', 690,5,680,0, 'https://10.10.1.22',NULL,NULL,NULL,'Hosts VM-ERP-01. Keep at least 20% of the RAM free for the ERP guest.','Lakeshore Technology Partners',NULL),
 ('VM-ERP-01','Virtual Machine','','','','Windows Server 2022 Standard','Deployed','Executive Office','','Headquarters - Madison','Runs on SRV-APP-01',
  'SRM-0203','Keystone ERP application server (virtual)',NULL, NULL,NULL,660,0, NULL,NULL,NULL,NULL,'Take a snapshot before every ERP upgrade. Vendor support: Keystone ERP Software.',NULL,NULL),
 -- Network gear -----------------------------------------------------------------------------------------
 ('SW-CORE-01','Switch','Ubiquiti','UniFi Switch Pro 24 PoE','USWP24-0817A','UniFi OS 7.1','Deployed','Executive Office','','Headquarters - Madison','Server room, Rack A, U10',
  'SRM-0301','Core switch for Headquarters','PO-7782', 690,3,680,0, 'https://10.10.1.2',NULL,NULL,NULL,'Port 1 is the uplink to the firewall. Ports 2-6 are listed on the Interfaces card.','Lakeshore Technology Partners',NULL),
 ('SW-PLANT-01','Switch','Ubiquiti','UniFi Switch 16 PoE','USW16P-0439B','UniFi OS 7.1','Deployed','Production','','Plant 1 - Sun Prairie','IDF wall rack, U11',
  'SRM-0302','Access switch for the plant network closet','PO-7311', 560,3,552,0, 'https://10.20.5.2',NULL,NULL,NULL,NULL,'Lakeshore Technology Partners',NULL),
 ('AP-HQ-01','Access Point','Ubiquiti','UniFi U6 Pro','U6PRO-2291C','UniFi OS 7.1','Deployed','Executive Office','','Headquarters - Madison','Ceiling, second-floor corridor',
  'SRM-0303','Wi-Fi access point for the second floor','PO-7782', 690,3,676,0, 'https://10.10.1.5',NULL,NULL,NULL,NULL,'Lakeshore Technology Partners',NULL),
 -- Firewall -------------------------------------------------------------------------------------------------
 ('FW-EDGE-01','Firewall/Router','Sophos','XGS 2100','S2100-DEMO-0001','Sophos Firewall OS (SFOS 20)','Deployed','Executive Office','','Headquarters - Madison','Server room, Rack A, U11',
  'SRM-0304','Edge firewall and internet gateway','PO-7782', 690,3,680,0, 'https://10.10.1.1:8443',NULL,NULL,NULL,'Configuration is exported weekly. Change requests go through a ticket; see the firewall change procedure.','Lakeshore Technology Partners',NULL),
 -- Mobile ------------------------------------------------------------------------------------------------------
 ('PH-EXEC-01','Mobile Phone','Apple','iPhone 15','DXKQ7M3R2N','iOS 18','Deployed','Executive Office','raj.patel@summitridge.example','Headquarters - Madison',NULL,
  'MOB-0107','COO company phone','PO-7895', 250,2,248,0, NULL,NULL,NULL,'2468',NULL,'Lakeshore Technology Partners',NULL),
 ('TAB-RCV-01','Tablet','Samsung','Galaxy Tab Active5','R52X30LQPM','Android 14','Ready to Deploy','Warehouse & Logistics','','Distribution Center - Milwaukee','Receiving office charging shelf',
  'MOB-0212','Rugged tablet for receiving inspections','PO-7910', 90,2,NULL,0, NULL,NULL,NULL,'1357','Keep on the charging shelf when not in use.','Lakeshore Technology Partners',NULL),
 -- Other ---------------------------------------------------------------------------------------------------------
 ('SCAN-WH-02','Other','Zebra','TC21 Handheld Scanner','22174528101349','Android 11','Deployed','Warehouse & Logistics','tara.whitfield@summitridge.example','Distribution Center - Milwaukee','Shipping dock, station 2',
  'SRM-0410','Handheld barcode scanner for shipping','PO-7655', 480,3,470,0, NULL,NULL,NULL,NULL,'Docks in the shipping cradle at the end of each shift.','Lakeshore Technology Partners',NULL),
 ('PRN-HQ-01','Printer','Ricoh','IM C3000 (color MFP)','E8735C41209','','Deployed','Executive Office','','Headquarters - Madison','Second-floor copy room',
  'SRM-0501','Shared colour multifunction printer for Headquarters','PO-7104', 800,3,790,0, 'http://10.10.20.41','http://10.10.20.41',NULL,NULL,'Toner and service are covered by Beacon Print & Imaging. Call the vendor first for jams inside the fuser.','Beacon Print & Imaging',NULL),
 ('PRN-PLANT-01','Printer','HP','LaserJet Enterprise M507dn','PHBL3K2061','','Deployed','Production','','Plant 1 - Sun Prairie','Quality lab, next to the inspection station',
  'SRM-0502','Mono laser printer for the quality lab','PO-7311', 560,3,551,0, 'http://10.20.5.31',NULL,NULL,NULL,NULL,NULL,NULL),
 ('CAM-DC-01','Camera','Axis','P3265-LVE','ACCC8E7A4421','Axis OS 11','Deployed','Warehouse & Logistics','','Distribution Center - Milwaukee','Dock 3, above the roll-up door',
  'SRM-0411','Dock security camera','PO-7655', 480,2,470,0, 'https://10.30.5.70',NULL,NULL,NULL,'Records to the building recorder for 30 days.','Lakeshore Technology Partners',NULL),
 ('DISP-EXEC-01','Display','Samsung','QM65C 65-inch Commercial Display','0BPX3QDR400123','','Deployed','Executive Office','','Headquarters - Madison','Boardroom wall',
  'SRM-0114','Boardroom display','PO-7712', 210,3,200,0, NULL,NULL,NULL,NULL,NULL,'Lakeshore Technology Partners',NULL);

INSERT INTO assets (asset_type, asset_name, asset_tag, asset_description, asset_make, asset_model, asset_serial, asset_pin, asset_os,
                    asset_uri, asset_uri_client, asset_anydesk_id, asset_status, asset_purchase_reference, asset_purchase_date,
                    asset_warranty_expire, asset_install_date, asset_physical_location, asset_notes, asset_favorite, asset_archived_at,
                    asset_vendor_id, asset_location_id, asset_contact_id, asset_client_id)
SELECT t.a_type, t.a_name, t.a_atag, t.a_desc, t.a_make, COALESCE(t.a_model,''), COALESCE(t.a_serial,''), t.a_pin, t.a_os,
       t.a_uri, t.a_uri_client, t.a_anydesk, t.a_status, t.a_pref,
       IF(t.a_buy_days IS NULL, NULL, DATE_SUB(CURDATE(), INTERVAL t.a_buy_days DAY)),
       IF(t.a_buy_days IS NULL OR t.a_warr_years IS NULL, NULL, DATE_ADD(DATE_SUB(CURDATE(), INTERVAL t.a_buy_days DAY), INTERVAL t.a_warr_years YEAR)),
       IF(t.a_inst_days IS NULL, NULL, DATE_SUB(CURDATE(), INTERVAL t.a_inst_days DAY)),
       t.a_phys, t.a_notes, t.a_fav,
       IF(t.a_arch_days IS NULL, NULL, DATE_SUB(NOW(), INTERVAL t.a_arch_days DAY)),
       COALESCE(v.vendor_id, 0), COALESCE(l.location_id, 0), COALESCE(ct.contact_id, 0), COALESCE(c.client_id, 0)
FROM tmp_ia t
LEFT JOIN clients  c  ON c.client_name    = t.a_dept
LEFT JOIN contacts ct ON ct.contact_email = t.a_contact
LEFT JOIN locations l ON l.location_name  = t.a_loc
LEFT JOIN vendors  v  ON v.vendor_name    = t.a_vendor
WHERE NOT EXISTS (SELECT 1 FROM assets a WHERE a.asset_name = t.a_name)
ORDER BY t.seq;

-- ======================================================================================
-- 6. Network interfaces. The first row of each asset is its primary interface (the address
--    shown in the Assets list). MACs come from the documentation range 00:00:5E:00:53:xx.
-- ======================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_ii;
CREATE TEMPORARY TABLE tmp_ii (
  seq INT AUTO_INCREMENT PRIMARY KEY,
  i_asset VARCHAR(200), i_name VARCHAR(200), i_type VARCHAR(50), i_ip VARCHAR(200), i_net VARCHAR(200), i_primary TINYINT, i_desc VARCHAR(200)
) DEFAULT CHARSET = utf8mb4;

INSERT INTO tmp_ii (i_asset, i_name, i_type, i_ip, i_net, i_primary, i_desc) VALUES
 ('LT-EXEC-01',   '01',      'WiFi',     'DHCP',        'HQ Offices', 1, NULL),
 ('LT-FIN-02',    '01',      'WiFi',     'DHCP',        'HQ Offices', 1, NULL),
 ('DT-HR-01',     '01',      'Ethernet', '10.10.20.101','HQ Offices', 1, NULL),
 ('DT-SALES-03',  '01',      'Ethernet', 'DHCP',        NULL,         1, 'Home network (remote worker)'),
 ('LT-SALES-01',  '01',      'WiFi',     'DHCP',        'HQ Offices', 1, NULL),
 ('LT-ENG-04',    '01',      'WiFi',     'DHCP',        'HQ Offices', 1, NULL),
 ('WS-ENG-01',    '01',      'Ethernet', '10.10.20.104','HQ Offices', 1, NULL),
 ('WS-ENG-02',    '01',      'Ethernet', '10.20.5.60',  'Plant 1 Production LAN', 1, NULL),
 ('PC-PLANT-07',  '01',      'Ethernet', '10.20.5.51',  'Plant 1 Production LAN', 1, NULL),
 ('LT-WHSE-01',   '01',      'WiFi',     'DHCP',        'Distribution Center LAN', 1, NULL),
 ('LT-SPARE-01',  '01',      'WiFi',     'DHCP',        NULL,         1, NULL),
 ('LT-OLD-03',    '01',      'WiFi',     NULL,          NULL,         1, NULL),
 ('SRV-FILE-01',  'NIC1',    'Ethernet', '10.10.1.10',  'HQ Servers & Network', 1, 'Production traffic'),
 ('SRV-FILE-01',  'NIC2',    'Ethernet', '10.10.1.11',  'HQ Servers & Network', 0, 'Second uplink for failover'),
 ('SRV-FILE-01',  'iDRAC',   'Ethernet', '10.10.1.12',  'HQ Servers & Network', 0, 'Out-of-band management'),
 ('SRV-APP-01',   'NIC1',    'Ethernet', '10.10.1.20',  'HQ Servers & Network', 1, NULL),
 ('SRV-APP-01',   'iDRAC',   'Ethernet', '10.10.1.22',  'HQ Servers & Network', 0, 'Out-of-band management'),
 ('VM-ERP-01',    'vNIC1',   'Ethernet', '10.10.1.21',  'HQ Servers & Network', 1, 'Virtual switch on SRV-APP-01'),
 ('SW-CORE-01',   'mgmt',    'Ethernet', '10.10.1.2',   'HQ Servers & Network', 1, 'Management address'),
 ('SW-CORE-01',   'Port 1',  'Ethernet', NULL,          NULL,         0, 'Uplink to the firewall'),
 ('SW-CORE-01',   'Port 2',  'Ethernet', NULL,          NULL,         0, 'File server NIC1'),
 ('SW-CORE-01',   'Port 3',  'Ethernet', NULL,          NULL,         0, 'File server NIC2'),
 ('SW-CORE-01',   'Port 4',  'Ethernet', NULL,          NULL,         0, 'Application server'),
 ('SW-CORE-01',   'Port 5',  'Ethernet', NULL,          NULL,         0, 'Access point, second floor'),
 ('SW-CORE-01',   'Port 6',  'Ethernet', NULL,          NULL,         0, 'Copy room printer'),
 ('SW-CORE-01',   'SFP+ 1',  'SFP+',     NULL,          NULL,         0, 'Reserved for the office switch stack'),
 ('SW-PLANT-01',  'mgmt',    'Ethernet', '10.20.5.2',   'Plant 1 Production LAN', 1, 'Management address'),
 ('SW-PLANT-01',  'Port 1',  'Ethernet', NULL,          NULL,         0, 'Supervisor station'),
 ('SW-PLANT-01',  'Port 2',  'Ethernet', NULL,          NULL,         0, 'Quality lab printer'),
 ('SW-PLANT-01',  'Port 3',  'Ethernet', NULL,          NULL,         0, 'Process engineering office'),
 ('AP-HQ-01',     'eth0',    'Ethernet', '10.10.1.5',   'HQ Servers & Network', 1, 'PoE from SW-CORE-01'),
 ('FW-EDGE-01',   'LAN1',    'Ethernet', '10.10.1.1',   'HQ Servers & Network', 1, 'Inside interface and default gateway'),
 ('FW-EDGE-01',   'WAN1',    'Ethernet', '192.0.2.18',  NULL,         0, 'Fiber handoff from Meridian Business Internet'),
 ('PH-EXEC-01',   '01',      'WiFi',     'DHCP',        NULL,         1, NULL),
 ('TAB-RCV-01',   '01',      'WiFi',     'DHCP',        'Distribution Center LAN', 1, NULL),
 ('SCAN-WH-02',   '01',      'WiFi',     'DHCP',        'Distribution Center LAN', 1, NULL),
 ('PRN-HQ-01',    'eth0',    'Ethernet', '10.10.20.41', 'HQ Offices', 1, NULL),
 ('PRN-PLANT-01', 'eth0',    'Ethernet', '10.20.5.31',  'Plant 1 Production LAN', 1, NULL),
 ('CAM-DC-01',    'eth0',    'Ethernet', '10.30.5.70',  'Distribution Center LAN', 1, 'PoE camera');

INSERT INTO asset_interfaces (interface_name, interface_description, interface_type, interface_mac, interface_ip,
                              interface_primary, interface_network_id, interface_asset_id)
SELECT t.i_name, t.i_desc, t.i_type, CONCAT('00:00:5E:00:53:', LPAD(UPPER(HEX(t.seq)), 2, '0')), t.i_ip,
       t.i_primary, n.network_id, a.asset_id
FROM tmp_ii t
JOIN assets a ON a.asset_name = t.i_asset
LEFT JOIN networks n ON n.network_name = t.i_net
WHERE NOT EXISTS (SELECT 1 FROM asset_interfaces x WHERE x.interface_asset_id = a.asset_id AND x.interface_name = t.i_name)
  AND (t.i_primary = 0 OR NOT EXISTS (SELECT 1 FROM asset_interfaces p WHERE p.interface_asset_id = a.asset_id AND p.interface_primary = 1))
ORDER BY t.seq;

-- Cabling between interfaces ("Connected To" on the asset page)
DROP TEMPORARY TABLE IF EXISTS tmp_il;
CREATE TEMPORARY TABLE tmp_il (a_asset VARCHAR(200), a_if VARCHAR(200), b_asset VARCHAR(200), b_if VARCHAR(200)) DEFAULT CHARSET = utf8mb4;
INSERT INTO tmp_il VALUES
 ('SW-CORE-01','Port 1','FW-EDGE-01','LAN1'),
 ('SW-CORE-01','Port 2','SRV-FILE-01','NIC1'),
 ('SW-CORE-01','Port 3','SRV-FILE-01','NIC2'),
 ('SW-CORE-01','Port 4','SRV-APP-01','NIC1'),
 ('SW-CORE-01','Port 5','AP-HQ-01','eth0'),
 ('SW-CORE-01','Port 6','PRN-HQ-01','eth0'),
 ('SW-PLANT-01','Port 1','PC-PLANT-07','01'),
 ('SW-PLANT-01','Port 2','PRN-PLANT-01','eth0'),
 ('SW-PLANT-01','Port 3','WS-ENG-02','01');

INSERT INTO asset_interface_links (interface_a_id, interface_b_id)
SELECT ia.interface_id, ib.interface_id
FROM tmp_il t
JOIN assets aa ON aa.asset_name = t.a_asset
JOIN asset_interfaces ia ON ia.interface_asset_id = aa.asset_id AND ia.interface_name = t.a_if
JOIN assets ab ON ab.asset_name = t.b_asset
JOIN asset_interfaces ib ON ib.interface_asset_id = ab.asset_id AND ib.interface_name = t.b_if
WHERE NOT EXISTS (SELECT 1 FROM asset_interface_links l
                   WHERE (l.interface_a_id = ia.interface_id AND l.interface_b_id = ib.interface_id)
                      OR (l.interface_a_id = ib.interface_id AND l.interface_b_id = ia.interface_id));

-- ======================================================================================
-- 7. Asset tags, assignment history and asset history
-- ======================================================================================
INSERT IGNORE INTO asset_tags (asset_tag_asset_id, asset_tag_tag_id)
SELECT a.asset_id, g.tag_id
FROM (
  SELECT 'SRV-FILE-01' AS an, 'Critical Infrastructure' AS tn
  UNION ALL SELECT 'SRV-APP-01', 'Critical Infrastructure'
  UNION ALL SELECT 'VM-ERP-01', 'Critical Infrastructure'
  UNION ALL SELECT 'SW-CORE-01', 'Critical Infrastructure'
  UNION ALL SELECT 'FW-EDGE-01', 'Critical Infrastructure'
  UNION ALL SELECT 'LT-SPARE-01', 'Loaner Device'
  UNION ALL SELECT 'TAB-RCV-01', 'Loaner Device'
  UNION ALL SELECT 'LT-EXEC-01', 'Refresh 2026'
  UNION ALL SELECT 'LT-SALES-01', 'Refresh 2026'
  UNION ALL SELECT 'TAB-RCV-01', 'Refresh 2026'
  UNION ALL SELECT 'PC-PLANT-07', 'Shop Floor'
  UNION ALL SELECT 'SW-PLANT-01', 'Shop Floor'
  UNION ALL SELECT 'PRN-PLANT-01', 'Shop Floor'
) m
JOIN assets a ON a.asset_name = m.an
JOIN tags g ON g.tag_name = m.tn AND g.tag_type = 5;

-- Who has had each device. Current assignments start on the install date; LT-FIN-02 and the
-- retired LT-OLD-03 show a previous holder (Lena Fischer) so the history card has two lines.
INSERT INTO asset_assignments (asset_id, contact_id, assigned_at, returned_at, assigned_by, returned_by)
SELECT a.asset_id, a.asset_contact_id, TIMESTAMP(COALESCE(a.asset_install_date, CURDATE()), '09:00:00'), NULL, @u_alex, NULL
FROM assets a JOIN tmp_ia t ON t.a_name = a.asset_name
WHERE a.asset_contact_id > 0 AND a.asset_name <> 'LT-FIN-02'
  AND NOT EXISTS (SELECT 1 FROM asset_assignments x WHERE x.asset_id = a.asset_id AND x.contact_id = a.asset_contact_id);

INSERT INTO asset_assignments (asset_id, contact_id, assigned_at, returned_at, assigned_by, returned_by)
SELECT a.asset_id, ct.contact_id, TIMESTAMP(a.asset_install_date, '09:00:00'), DATE_SUB(NOW(), INTERVAL 60 DAY), @u_alex, @u_priya
FROM assets a JOIN contacts ct ON ct.contact_email = 'lena.fischer@summitridge.example'
WHERE a.asset_name = 'LT-FIN-02'
  AND NOT EXISTS (SELECT 1 FROM asset_assignments x WHERE x.asset_id = a.asset_id AND x.contact_id = ct.contact_id);

INSERT INTO asset_assignments (asset_id, contact_id, assigned_at, returned_at, assigned_by, returned_by)
SELECT a.asset_id, ct.contact_id, DATE_SUB(NOW(), INTERVAL 60 DAY), NULL, @u_priya, NULL
FROM assets a JOIN contacts ct ON ct.contact_id = a.asset_contact_id
WHERE a.asset_name = 'LT-FIN-02'
  AND NOT EXISTS (SELECT 1 FROM asset_assignments x WHERE x.asset_id = a.asset_id AND x.contact_id = ct.contact_id);

INSERT INTO asset_assignments (asset_id, contact_id, assigned_at, returned_at, assigned_by, returned_by)
SELECT a.asset_id, ct.contact_id, TIMESTAMP(a.asset_install_date, '09:00:00'), DATE_SUB(NOW(), INTERVAL 45 DAY), @u_alex, @u_alex
FROM assets a JOIN contacts ct ON ct.contact_email = 'lena.fischer@summitridge.example'
WHERE a.asset_name = 'LT-OLD-03'
  AND NOT EXISTS (SELECT 1 FROM asset_assignments x WHERE x.asset_id = a.asset_id AND x.contact_id = ct.contact_id);

-- History tab of the Edit Asset window
INSERT INTO asset_history (asset_history_status, asset_history_description, asset_history_created_at, asset_history_asset_id)
SELECT COALESCE(NULLIF(a.asset_status, ''), 'Created'), CONCAT('Alex Morgan created ', a.asset_name),
       TIMESTAMP(COALESCE(a.asset_install_date, DATE_SUB(CURDATE(), INTERVAL 5 DAY)), '09:15:00'), a.asset_id
FROM assets a JOIN tmp_ia t ON t.a_name = a.asset_name
WHERE NOT EXISTS (SELECT 1 FROM asset_history h WHERE h.asset_history_asset_id = a.asset_id
                    AND h.asset_history_description = CONCAT('Alex Morgan created ', a.asset_name));

INSERT INTO asset_history (asset_history_status, asset_history_description, asset_history_created_at, asset_history_asset_id)
SELECT x.st, CONCAT(x.who, ' ', x.what, ' ', a.asset_name), DATE_SUB(NOW(), INTERVAL x.days DAY), a.asset_id
FROM (
  SELECT 'LT-FIN-02' AS an, 'Deployed' AS st, 'Priya Nair' AS who, 'updated' AS what, 60 AS days
  UNION ALL SELECT 'LT-WHSE-01', 'Out for Repair', 'Priya Nair', 'updated', 6
  UNION ALL SELECT 'LT-SPARE-01', 'Ready to Deploy', 'Priya Nair', 'updated', 6
  UNION ALL SELECT 'LT-OLD-03', 'Retired', 'Alex Morgan', 'updated', 31
  UNION ALL SELECT 'LT-OLD-03', 'Archived', 'Alex Morgan', 'archived', 30
  UNION ALL SELECT 'SRV-FILE-01', 'Deployed', 'Marcus Lee', 'updated', 14
) x
JOIN assets a ON a.asset_name = x.an
WHERE NOT EXISTS (SELECT 1 FROM asset_history h WHERE h.asset_history_asset_id = a.asset_id
                    AND h.asset_history_description = CONCAT(x.who, ' ', x.what, ' ', a.asset_name));

-- ======================================================================================
-- 8. Licences (Software & Licenses). The seat count on the list is "assigned / total".
-- ======================================================================================
INSERT INTO software (software_name, software_description, software_version, software_type, software_license_type, software_key,
                      software_seats, software_purchase_reference, software_purchase, software_expire, software_notes, software_vendor_id, software_client_id)
SELECT x.name, x.descr, x.ver, x.stype, x.ltype, x.skey, x.seats, x.pref,
       DATE_SUB(CURDATE(), INTERVAL x.buy_days DAY),
       IF(x.exp_days IS NULL, NULL, DATE_ADD(CURDATE(), INTERVAL x.exp_days DAY)),
       x.notes, COALESCE(v.vendor_id, 0), c.client_id
FROM (
  SELECT 'Microsoft 365 Business Premium' AS name, 'Email, Office apps, Teams and device management' AS descr, '' AS ver,
         'Software as a Service (SaaS)' AS stype, 'User' AS ltype, NULL AS skey, 25 AS seats, 'CSP-2026-0114' AS pref,
         153 AS buy_days, 212 AS exp_days, 'Billed annually through the reseller. Assign a seat to every new hire.' AS notes,
         'Lakeshore Technology Partners' AS vendor, 'Executive Office' AS dept
  UNION ALL SELECT 'Adobe Acrobat Pro', 'PDF editing and e-signature for the finance team', '2025',
         'Desktop Application', 'User', NULL, 6, 'PO-7388', 346, 19,
         'Annual named-user subscription. Renewal quote requested from the reseller.', 'Lakeshore Technology Partners', 'Finance & Accounting'
  UNION ALL SELECT 'Autodesk AutoCAD', '2D and 3D drafting', '2025',
         'Desktop Application', 'Device', 'DEMO-4F8K2-71QPT-ACAD', 4, 'PO-7245', 250, 118,
         'Each seat is tied to a device, not a person. Link new devices from the asset page.', 'Lakeshore Technology Partners', 'Engineering'
  UNION ALL SELECT 'Windows Server 2022 Standard', 'Operating system licences for the physical servers', '',
         'Operating System', 'Device', NULL, 2, 'PO-7782', 690, NULL,
         'Perpetual licences, one per physical server. No expiry date.', 'Lakeshore Technology Partners', 'Executive Office'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'Endpoint protection for laptops and desktops, managed in Sophos Central', '',
         'Security Software', 'Device', 'DEMO-SOPH-93KD-2026', 40, 'CSP-2026-0121', 200, 297,
         'Covers every laptop and desktop. Servers are covered separately.', 'Lakeshore Technology Partners', 'Executive Office'
  UNION ALL SELECT 'Adobe Creative Cloud All Apps', 'Design and video tools for marketing', '',
         'Software as a Service (SaaS)', 'User', NULL, 3, 'PO-7051', 377, -12,
         'Lapsed on the anniversary date. Confirm the seat count with Nina Rossi before renewing.', 'Lakeshore Technology Partners', 'Sales & Marketing'
) x
JOIN clients c ON c.client_name = x.dept
LEFT JOIN vendors v ON v.vendor_name = x.vendor
WHERE NOT EXISTS (SELECT 1 FROM software s WHERE s.software_name = x.name AND s.software_client_id = c.client_id);

-- Seats for user licences: people
INSERT IGNORE INTO software_contacts (software_id, contact_id)
SELECT s.software_id, ct.contact_id
FROM (
  SELECT 'Microsoft 365 Business Premium' AS sn, e AS em FROM (
    SELECT 'helen.brandt@summitridge.example' AS e UNION ALL SELECT 'raj.patel@summitridge.example'
    UNION ALL SELECT 'grace.okafor@summitridge.example' UNION ALL SELECT 'tom.kessler@summitridge.example' UNION ALL SELECT 'lena.fischer@summitridge.example'
    UNION ALL SELECT 'miguel.alvarez@summitridge.example' UNION ALL SELECT 'sophie.tran@summitridge.example'
    UNION ALL SELECT 'nina.rossi@summitridge.example' UNION ALL SELECT 'owen.baker@summitridge.example' UNION ALL SELECT 'zoe.hartman@summitridge.example' UNION ALL SELECT 'ben.carter@summitridge.example'
    UNION ALL SELECT 'carlos.mendoza@summitridge.example' UNION ALL SELECT 'aisha.rahman@summitridge.example' UNION ALL SELECT 'jake.sullivan@summitridge.example' UNION ALL SELECT 'emma.novak@summitridge.example'
    UNION ALL SELECT 'frank.delgado@summitridge.example' UNION ALL SELECT 'tara.whitfield@summitridge.example' UNION ALL SELECT 'liam.oconnor@summitridge.example'
    UNION ALL SELECT 'yuki.tanaka@summitridge.example' UNION ALL SELECT 'ivan.petrov@summitridge.example' UNION ALL SELECT 'maya.singh@summitridge.example'
  ) m1
  UNION ALL SELECT 'Adobe Acrobat Pro', 'grace.okafor@summitridge.example'
  UNION ALL SELECT 'Adobe Acrobat Pro', 'tom.kessler@summitridge.example'
  UNION ALL SELECT 'Adobe Acrobat Pro', 'lena.fischer@summitridge.example'
  UNION ALL SELECT 'Adobe Creative Cloud All Apps', 'zoe.hartman@summitridge.example'
  UNION ALL SELECT 'Adobe Creative Cloud All Apps', 'nina.rossi@summitridge.example'
) m
JOIN software s ON s.software_name = m.sn
JOIN contacts ct ON ct.contact_email = m.em;

-- Seats for device licences: assets
INSERT IGNORE INTO software_assets (software_id, asset_id)
SELECT s.software_id, a.asset_id
FROM (
  SELECT 'Autodesk AutoCAD' AS sn, 'LT-ENG-04' AS an
  UNION ALL SELECT 'Autodesk AutoCAD', 'WS-ENG-01'
  UNION ALL SELECT 'Autodesk AutoCAD', 'WS-ENG-02'
  UNION ALL SELECT 'Windows Server 2022 Standard', 'SRV-FILE-01'
  UNION ALL SELECT 'Windows Server 2022 Standard', 'SRV-APP-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-EXEC-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-FIN-02'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'DT-HR-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'DT-SALES-03'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-SALES-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-ENG-04'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'WS-ENG-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'WS-ENG-02'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'PC-PLANT-07'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-WHSE-01'
  UNION ALL SELECT 'Sophos Intercept X Advanced', 'LT-SPARE-01'
) m
JOIN software s ON s.software_name = m.sn
JOIN assets a ON a.asset_name = m.an;

-- ======================================================================================
-- 9. Domains and certificates. WHOIS/DNS columns are normally filled by the app from live
--    lookups; here they hold made-up values so the detail pages have something to show.
-- ======================================================================================
INSERT INTO domains (domain_name, domain_description, domain_expire, domain_registered_at, domain_ip, domain_name_servers, domain_mail_servers,
                     domain_txt, domain_raw_whois, domain_registrar_name, domain_status, domain_dnssec, domain_notes,
                     domain_registrar, domain_webhost, domain_dnshost, domain_mailhost, domain_client_id)
SELECT x.name, x.descr, DATE_ADD(CURDATE(), INTERVAL x.exp_days DAY), DATE_SUB(CURDATE(), INTERVAL x.age_years YEAR),
       x.ip, x.ns, x.mx, x.txt, x.whois, 'NameHarbor Domains, LLC', 'clientTransferProhibited', 'unsigned', x.notes,
       COALESCE((SELECT v.vendor_id FROM vendors v WHERE v.vendor_name = 'NameHarbor Domains'), 0),
       COALESCE((SELECT v.vendor_id FROM vendors v WHERE v.vendor_name = x.webhost), 0),
       COALESCE((SELECT v.vendor_id FROM vendors v WHERE v.vendor_name = x.dnshost), 0),
       COALESCE((SELECT v.vendor_id FROM vendors v WHERE v.vendor_name = x.mailhost), 0),
       c.client_id
FROM (
  SELECT 'summitridge.example' AS name, 'Main company domain: website and email' AS descr, 214 AS exp_days, 15 AS age_years,
         '192.0.2.44' AS ip, 'ns1.cobalt-hosting.example\nns2.cobalt-hosting.example' AS ns, '0 summitridge-example.mail.example' AS mx,
         'v=spf1 include:spf.mail.example -all' AS txt,
         'Domain Name: SUMMITRIDGE.EXAMPLE\nRegistrar: NameHarbor Domains, LLC\nStatus: clientTransferProhibited\nName Server: NS1.COBALT-HOSTING.EXAMPLE\nName Server: NS2.COBALT-HOSTING.EXAMPLE\nDNSSEC: unsigned' AS whois,
         'Registered by Executive Office. Renew manually; auto-renew is off.' AS notes,
         'Cobalt Cloud Hosting' AS webhost, 'Cobalt Cloud Hosting' AS dnshost, 'Lakeshore Technology Partners' AS mailhost, 'Executive Office' AS dept
  UNION ALL SELECT 'summitridgeparts.example', 'Online parts catalog for dealers', 26, 8,
         '192.0.2.45', 'ns1.cobalt-hosting.example\nns2.cobalt-hosting.example', '0 mail.summitridgeparts.example',
         'v=spf1 -all',
         'Domain Name: SUMMITRIDGEPARTS.EXAMPLE\nRegistrar: NameHarbor Domains, LLC\nStatus: clientTransferProhibited\nName Server: NS1.COBALT-HOSTING.EXAMPLE\nName Server: NS2.COBALT-HOSTING.EXAMPLE\nDNSSEC: unsigned',
         'Renewal is due soon. Marketing has approved the fee; ask IT to renew.',
         'Cobalt Cloud Hosting', 'Cobalt Cloud Hosting', NULL, 'Sales & Marketing'
  UNION ALL SELECT 'summitridge-supplier.example', 'Supplier portal and EDI gateway', 409, 4,
         '192.0.2.46', 'ns1.cobalt-hosting.example\nns2.cobalt-hosting.example', '0 mail.summitridge-supplier.example',
         'v=spf1 -all',
         'Domain Name: SUMMITRIDGE-SUPPLIER.EXAMPLE\nRegistrar: NameHarbor Domains, LLC\nStatus: clientTransferProhibited\nName Server: NS1.COBALT-HOSTING.EXAMPLE\nName Server: NS2.COBALT-HOSTING.EXAMPLE\nDNSSEC: unsigned',
         NULL,
         'Cobalt Cloud Hosting', 'Cobalt Cloud Hosting', NULL, 'Warehouse & Logistics'
) x
JOIN clients c ON c.client_name = x.dept
WHERE NOT EXISTS (SELECT 1 FROM domains d WHERE d.domain_name = x.name);

INSERT INTO domain_history (domain_history_column, domain_history_old_value, domain_history_new_value, domain_history_domain_id, domain_history_modified_at)
SELECT x.col, x.oldv, x.newv, d.domain_id, DATE_SUB(NOW(), INTERVAL x.days DAY)
FROM (
  SELECT 'summitridgeparts.example' AS dn, 'domain_description' AS col, 'Parts site' AS oldv, 'Online parts catalog for dealers' AS newv, 120 AS days
  UNION ALL SELECT 'summitridgeparts.example', 'domain_name_servers', 'ns1.old-dns.example', 'ns1.cobalt-hosting.example', 45
  UNION ALL SELECT 'summitridge.example', 'domain_notes', '', 'Registered by Executive Office. Renew manually; auto-renew is off.', 80
) x
JOIN domains d ON d.domain_name = x.dn
WHERE NOT EXISTS (SELECT 1 FROM domain_history h WHERE h.domain_history_domain_id = d.domain_id
                    AND h.domain_history_column = x.col AND h.domain_history_new_value = x.newv);

INSERT INTO certificates (certificate_name, certificate_description, certificate_domain, certificate_issued_by, certificate_expire,
                          certificate_public_key, certificate_notes, certificate_domain_id, certificate_client_id)
SELECT x.name, x.descr, x.fqdn, x.issuer, DATE_ADD(CURDATE(), INTERVAL x.exp_days DAY),
       CONCAT('-----BEGIN CERTIFICATE-----\nU3VtbWl0IFJpZGdlIGRlbW8gY2VydGlmaWNhdGUgcGxhY2Vob2xkZXIuIFRoaXMgaXMgbm90IGEg\ncmVhbCBjZXJ0aWZpY2F0ZSBhbmQgY2Fubm90IGJlIHVzZWQgZm9yIGFueXRoaW5nLg==\n-----END CERTIFICATE-----'),
       x.notes,
       COALESCE((SELECT d.domain_id FROM domains d WHERE d.domain_name = x.dn), 0), c.client_id
FROM (
  SELECT 'Wildcard - summitridge.example' AS name, 'Covers every host under the main domain' AS descr, '*.summitridge.example' AS fqdn,
         'Harbor Trust CA' AS issuer, 187 AS exp_days, 'Installed on the reverse proxy and the mail gateway. Renew 30 days ahead.' AS notes,
         'summitridge.example' AS dn, 'Executive Office' AS dept
  UNION ALL SELECT 'Employee portal', 'HTTPS certificate for the employee portal', 'portal.summitridge.example',
         'Harbor Trust CA', 6, 'Renewal request submitted. Install the new certificate on the reverse proxy as soon as it arrives.',
         'summitridge.example', 'Executive Office'
  UNION ALL SELECT 'Parts catalog', 'HTTPS certificate for the dealer parts catalog', 'shop.summitridgeparts.example',
         'Harbor Trust CA', 71, NULL,
         'summitridgeparts.example', 'Sales & Marketing'
) x
JOIN clients c ON c.client_name = x.dept
WHERE NOT EXISTS (SELECT 1 FROM certificates k WHERE k.certificate_name = x.name AND k.certificate_client_id = c.client_id);

INSERT INTO certificate_history (certificate_history_column, certificate_history_old_value, certificate_history_new_value, certificate_history_certificate_id, certificate_history_modified_at)
SELECT 'certificate_expire', DATE_SUB(k.certificate_expire, INTERVAL 1 YEAR), k.certificate_expire, k.certificate_id, DATE_SUB(NOW(), INTERVAL 178 DAY)
FROM certificates k JOIN clients c ON c.client_id = k.certificate_client_id
WHERE k.certificate_name = 'Wildcard - summitridge.example' AND c.client_name = 'Executive Office'
  AND NOT EXISTS (SELECT 1 FROM certificate_history h WHERE h.certificate_history_certificate_id = k.certificate_id AND h.certificate_history_column = 'certificate_expire');

-- ======================================================================================
-- 10. Racks (department workspace > Racks)
-- ======================================================================================
INSERT INTO racks (rack_name, rack_description, rack_model, rack_depth, rack_type, rack_units, rack_physical_location, rack_notes, rack_location_id, rack_client_id)
SELECT x.name, x.descr, x.model, x.depth, x.rtype, x.units, x.phys, x.notes,
       COALESCE((SELECT l.location_id FROM locations l WHERE l.location_name = x.loc), 0), c.client_id
FROM (
  SELECT 'HQ Server Room - Rack A' AS name, 'Main rack for servers, firewall and core switch' AS descr, 'Tripp Lite SmartRack 12U Enclosure' AS model,
         '800 mm' AS depth, '4-Post Enclosed Cabinet' AS rtype, 12 AS units, 'Server room, ground floor' AS phys,
         'The doors are locked and IT holds the keys. Power comes from the UPS at the bottom.' AS notes,
         'Headquarters - Madison' AS loc, 'Executive Office' AS dept
  UNION ALL SELECT 'Plant 1 IDF Wall Rack', 'Network closet behind the quality lab', 'StarTech 12U Wall-Mount Cabinet',
         '600 mm', 'Wall-Mount Enclosed', 12, 'IDF closet, behind the quality lab',
         'Only the plant switch and a patch panel live here.',
         'Plant 1 - Sun Prairie', 'Production'
) x
JOIN clients c ON c.client_name = x.dept
WHERE NOT EXISTS (SELECT 1 FROM racks r WHERE r.rack_name = x.name AND r.rack_client_id = c.client_id);

INSERT INTO rack_units (unit_start_number, unit_end_number, unit_device, unit_asset_id, unit_rack_id)
SELECT x.us, x.ue, x.dev, a.asset_id, r.rack_id
FROM (
  SELECT 'HQ Server Room - Rack A' AS rn, 12 AS us, 12 AS ue, 'Patch panel, 24-port Cat6A' AS dev, NULL AS an
  UNION ALL SELECT 'HQ Server Room - Rack A', 11, 11, NULL, 'FW-EDGE-01'
  UNION ALL SELECT 'HQ Server Room - Rack A', 10, 10, NULL, 'SW-CORE-01'
  UNION ALL SELECT 'HQ Server Room - Rack A', 9, 9, NULL, 'SRV-APP-01'
  UNION ALL SELECT 'HQ Server Room - Rack A', 5, 8, NULL, 'SRV-FILE-01'
  UNION ALL SELECT 'HQ Server Room - Rack A', 4, 4, 'Metered PDU', NULL
  UNION ALL SELECT 'HQ Server Room - Rack A', 1, 3, 'UPS, 3000 VA', NULL
  UNION ALL SELECT 'Plant 1 IDF Wall Rack', 12, 12, 'Patch panel, 24-port Cat6', NULL
  UNION ALL SELECT 'Plant 1 IDF Wall Rack', 11, 11, NULL, 'SW-PLANT-01'
  UNION ALL SELECT 'Plant 1 IDF Wall Rack', 1, 2, 'UPS, 1500 VA', NULL
) x
JOIN racks r ON r.rack_name = x.rn
LEFT JOIN assets a ON a.asset_name = x.an
WHERE NOT EXISTS (SELECT 1 FROM rack_units u WHERE u.unit_rack_id = r.rack_id AND u.unit_start_number = x.us AND u.unit_end_number = x.ue);

-- ======================================================================================
-- 11. Folders, files and documents (department workspace > Files) for Executive Office.
--     The file rows carry metadata only; no file is stored on disk for them.
-- ======================================================================================
INSERT INTO folders (folder_name, parent_folder, folder_location, folder_client_id)
SELECT x.fn, 0, 0, c.client_id
FROM (
  SELECT 'Network Diagrams' AS fn UNION ALL SELECT 'Purchase & Warranty Records' UNION ALL SELECT 'Runbooks'
) x
JOIN clients c ON c.client_name = 'Executive Office'
WHERE NOT EXISTS (SELECT 1 FROM folders f WHERE f.folder_name = x.fn AND f.folder_client_id = c.client_id AND f.parent_folder = 0 AND f.folder_location = 0);

INSERT INTO files (file_reference_name, file_name, file_description, file_ext, file_size, file_mime_type,
                   file_created_at, file_created_by, file_folder_id, file_client_id)
SELECT x.ref, x.fname, x.descr, x.ext, x.size, x.mime, DATE_SUB(NOW(), INTERVAL x.days DAY),
       CASE x.who WHEN 'marcus' THEN @u_marcus WHEN 'priya' THEN @u_priya ELSE @u_alex END,
       COALESCE(f.folder_id, 0), c.client_id
FROM (
  SELECT 'demo-infra-asset-refresh-plan.pdf' AS ref, 'Asset-Refresh-Plan-2026.pdf' AS fname, 'Which devices are replaced this year and why' AS descr, 'pdf' AS ext, 184320 AS size, 'application/pdf' AS mime, 40 AS days, 'alex' AS who, NULL AS folder
  UNION ALL SELECT 'demo-infra-hq-floor-plan.pdf', 'HQ-Floor-Plan.pdf', 'Second floor, with the network closet marked', 'pdf', 96256, 'application/pdf', 110, 'priya', NULL
  UNION ALL SELECT 'demo-infra-hq-network-diagram.pdf', 'HQ-Network-Diagram-v3.pdf', 'Logical diagram of the Headquarters network', 'pdf', 231424, 'application/pdf', 25, 'marcus', 'Network Diagrams'
  UNION ALL SELECT 'demo-infra-rack-a-layout.png', 'HQ-Rack-A-Layout.png', 'Photo of Rack A with unit numbers', 'png', 812544, 'image/png', 25, 'marcus', 'Network Diagrams'
  UNION ALL SELECT 'demo-infra-plant-network-diagram.pdf', 'Plant1-Network-Diagram.pdf', 'Plant 1 switch and access-point layout', 'pdf', 158720, 'application/pdf', 52, 'marcus', 'Network Diagrams'
  UNION ALL SELECT 'demo-infra-po-7782-order.pdf', 'PO-7782-Server-Rack-Order.pdf', 'Purchase order for the servers, switch and firewall', 'pdf', 143360, 'application/pdf', 690, 'alex', 'Purchase & Warranty Records'
  UNION ALL SELECT 'demo-infra-warranty-srv-file-01.pdf', 'Warranty-Certificate-SRV-FILE-01.pdf', 'Five-year ProSupport contract', 'pdf', 87040, 'application/pdf', 680, 'alex', 'Purchase & Warranty Records'
  UNION ALL SELECT 'demo-infra-lakeshore-quote-q4.pdf', 'Lakeshore-Quote-Q4-Laptops.pdf', 'Quote for the Q4 laptop refresh', 'pdf', 65536, 'application/pdf', 12, 'priya', 'Purchase & Warranty Records'
) x
JOIN clients c ON c.client_name = 'Executive Office'
LEFT JOIN folders f ON f.folder_name = x.folder AND f.folder_client_id = c.client_id AND f.parent_folder = 0 AND f.folder_location = 0
WHERE NOT EXISTS (SELECT 1 FROM files z WHERE z.file_reference_name = x.ref);

INSERT INTO documents (document_name, document_description, document_content, document_content_raw, document_client_visible,
                       document_created_at, document_updated_at, document_folder_id, document_created_by, document_updated_by, document_client_id)
SELECT x.name, x.descr, x.html, x.raw, 0,
       DATE_SUB(NOW(), INTERVAL x.days DAY), DATE_SUB(NOW(), INTERVAL x.upd DAY),
       COALESCE(f.folder_id, 0), @u_marcus, @u_marcus, c.client_id
FROM (
  SELECT 'SRV-FILE-01 Backup and Restore Runbook' AS name, 'How the nightly backup works and how to restore a file or the whole server' AS descr,
         '<h2>Nightly backup</h2><p>The backup job runs at 22:00 and copies the shared drives to the NAS. A weekly full copy goes to the offsite vault.</p><h2>Restore a single file</h2><ol><li>Open the backup console.</li><li>Pick the share and the date.</li><li>Restore to the original folder and tell the user.</li></ol><h2>Before you reboot</h2><p>Check that no backup is running and warn the finance team if it is month-end.</p>' AS html,
         'Nightly backup The backup job runs at 22:00 and copies the shared drives to the NAS. A weekly full copy goes to the offsite vault. Restore a single file Open the backup console. Pick the share and the date. Restore to the original folder and tell the user. Before you reboot Check that no backup is running and warn the finance team if it is month-end.' AS raw,
         'Runbooks' AS folder, 70 AS days, 14 AS upd
  UNION ALL SELECT 'Firewall Change Procedure', 'Steps for a safe change on FW-EDGE-01, including rollback',
         '<h2>Before the change</h2><ol><li>Open a ticket and get approval.</li><li>Export the current configuration.</li></ol><h2>Making the change</h2><p>Apply the rule, test from a laptop, then save. Roll back within ten minutes if anything breaks.</p><h2>After the change</h2><p>Update the network diagram and close the ticket.</p>',
         'Before the change Open a ticket and get approval. Export the current configuration. Making the change Apply the rule, test from a laptop, then save. Roll back within ten minutes if anything breaks. After the change Update the network diagram and close the ticket.',
         'Runbooks', 55, 20
  UNION ALL SELECT 'New Laptop Setup Checklist', 'Everything to do before a laptop goes to its new owner',
         '<ol><li>Image the laptop and join it to the domain.</li><li>Install the standard software and enrol endpoint protection.</li><li>Turn on disk encryption and store the recovery key.</li><li>Create the asset record and assign it to the new owner.</li></ol>',
         'Image the laptop and join it to the domain. Install the standard software and enrol endpoint protection. Turn on disk encryption and store the recovery key. Create the asset record and assign it to the new owner.',
         NULL, 95, 30
) x
JOIN clients c ON c.client_name = 'Executive Office'
LEFT JOIN folders f ON f.folder_name = x.folder AND f.folder_client_id = c.client_id AND f.parent_folder = 0 AND f.folder_location = 0
WHERE NOT EXISTS (SELECT 1 FROM documents d WHERE d.document_name = x.name AND d.document_client_id = c.client_id);

-- Attach documents and files to assets
INSERT IGNORE INTO asset_documents (asset_id, document_id)
SELECT a.asset_id, d.document_id
FROM (
  SELECT 'SRV-FILE-01' AS an, 'SRV-FILE-01 Backup and Restore Runbook' AS dn
  UNION ALL SELECT 'FW-EDGE-01', 'Firewall Change Procedure'
  UNION ALL SELECT 'LT-EXEC-01', 'New Laptop Setup Checklist'
  UNION ALL SELECT 'LT-SPARE-01', 'New Laptop Setup Checklist'
) m
JOIN assets a ON a.asset_name = m.an
JOIN documents d ON d.document_name = m.dn AND d.document_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office');

INSERT IGNORE INTO asset_files (asset_id, file_id)
SELECT a.asset_id, f.file_id
FROM (
  SELECT 'SRV-FILE-01' AS an, 'demo-infra-warranty-srv-file-01.pdf' AS ref
  UNION ALL SELECT 'SRV-FILE-01', 'demo-infra-po-7782-order.pdf'
  UNION ALL SELECT 'FW-EDGE-01', 'demo-infra-hq-network-diagram.pdf'
  UNION ALL SELECT 'SW-CORE-01', 'demo-infra-rack-a-layout.png'
) m
JOIN assets a ON a.asset_name = m.an
JOIN files f ON f.file_reference_name = m.ref;

-- ======================================================================================
-- 12. Services (department workspace > Services; also listed company-wide)
-- ======================================================================================
INSERT INTO services (service_name, service_description, service_category, service_importance, service_backup, service_notes, service_review_due,
                      service_created_at, service_updated_at, service_client_id)
SELECT x.name, x.descr, x.cat, x.imp, x.bkp, x.notes, DATE_ADD(CURDATE(), INTERVAL x.review DAY),
       DATE_SUB(NOW(), INTERVAL x.age DAY), DATE_SUB(NOW(), INTERVAL x.upd DAY), c.client_id
FROM (
  SELECT 'Company File Shares' AS name, 'Shared drives and home folders for every department' AS descr, 'File storage' AS cat, 'High' AS imp,
         'Nightly backup at 22:00 to the NAS, weekly full copy to the offsite vault, 30-day retention' AS bkp,
         'Business owner: Raj Patel (COO).\nSupport: IT service desk.\nAccess is controlled through the FS-<Department> security groups.\nSee the backup and restore runbook before any maintenance.' AS notes,
         60 AS review, 210 AS age, 18 AS upd
  UNION ALL SELECT 'Corporate Email & Calendar', 'Mailboxes, calendars and Teams for all staff', 'Messaging', 'High',
         'Provider-managed. Deleted items are recoverable for 30 days.',
         'Business owner: Helen Brandt (CEO).\nMail flows through the spam filter, then to Microsoft 365.\nThe wildcard certificate is used on the mail gateway.',
         120, 300, 45
  UNION ALL SELECT 'Internet & Firewall', 'Fiber internet, the edge firewall and the site-to-site VPN tunnels', 'Connectivity', 'High',
         'Firewall configuration is exported weekly to the Network Diagrams folder.',
         'Circuit ID MBI-FIB-0093.\nOutage: call the carrier NOC first, then open a ticket.\nFirewall changes follow the firewall change procedure.',
         30, 240, 9
) x
JOIN clients c ON c.client_name = 'Executive Office'
WHERE NOT EXISTS (SELECT 1 FROM services s WHERE s.service_name = x.name AND s.service_client_id = c.client_id);

INSERT INTO service_assets (service_id, asset_id)
SELECT s.service_id, a.asset_id
FROM (
  SELECT 'Company File Shares' AS sn, 'SRV-FILE-01' AS an
  UNION ALL SELECT 'Company File Shares', 'SW-CORE-01'
  UNION ALL SELECT 'Internet & Firewall', 'FW-EDGE-01'
  UNION ALL SELECT 'Internet & Firewall', 'SW-CORE-01'
  UNION ALL SELECT 'Internet & Firewall', 'AP-HQ-01'
) m
JOIN services s ON s.service_name = m.sn AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
JOIN assets a ON a.asset_name = m.an
WHERE NOT EXISTS (SELECT 1 FROM service_assets x WHERE x.service_id = s.service_id AND x.asset_id = a.asset_id);

INSERT INTO service_vendors (service_id, vendor_id)
SELECT s.service_id, v.vendor_id
FROM (
  SELECT 'Company File Shares' AS sn, 'Lakeshore Technology Partners' AS vn
  UNION ALL SELECT 'Corporate Email & Calendar', 'Lakeshore Technology Partners'
  UNION ALL SELECT 'Internet & Firewall', 'Meridian Business Internet'
) m
JOIN services s ON s.service_name = m.sn AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
JOIN vendors v ON v.vendor_name = m.vn
WHERE NOT EXISTS (SELECT 1 FROM service_vendors x WHERE x.service_id = s.service_id AND x.vendor_id = v.vendor_id);

INSERT INTO service_domains (service_id, domain_id)
SELECT s.service_id, d.domain_id
FROM services s JOIN domains d ON d.domain_name = 'summitridge.example'
WHERE s.service_name = 'Corporate Email & Calendar' AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
  AND NOT EXISTS (SELECT 1 FROM service_domains x WHERE x.service_id = s.service_id AND x.domain_id = d.domain_id);

INSERT INTO service_certificates (service_id, certificate_id)
SELECT s.service_id, k.certificate_id
FROM services s JOIN certificates k ON k.certificate_name = 'Wildcard - summitridge.example'
WHERE s.service_name = 'Corporate Email & Calendar' AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
  AND NOT EXISTS (SELECT 1 FROM service_certificates x WHERE x.service_id = s.service_id AND x.certificate_id = k.certificate_id);

INSERT INTO service_contacts (service_id, contact_id)
SELECT s.service_id, ct.contact_id
FROM (
  SELECT 'Company File Shares' AS sn, 'raj.patel@summitridge.example' AS em
  UNION ALL SELECT 'Corporate Email & Calendar', 'helen.brandt@summitridge.example'
  UNION ALL SELECT 'Internet & Firewall', 'raj.patel@summitridge.example'
) m
JOIN services s ON s.service_name = m.sn AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
JOIN contacts ct ON ct.contact_email = m.em
WHERE NOT EXISTS (SELECT 1 FROM service_contacts x WHERE x.service_id = s.service_id AND x.contact_id = ct.contact_id);

INSERT INTO service_documents (service_id, document_id)
SELECT s.service_id, d.document_id
FROM (
  SELECT 'Company File Shares' AS sn, 'SRV-FILE-01 Backup and Restore Runbook' AS dn
  UNION ALL SELECT 'Internet & Firewall', 'Firewall Change Procedure'
) m
JOIN services s ON s.service_name = m.sn AND s.service_client_id = (SELECT client_id FROM clients WHERE client_name = 'Executive Office')
JOIN documents d ON d.document_name = m.dn AND d.document_client_id = s.service_client_id
WHERE NOT EXISTS (SELECT 1 FROM service_documents x WHERE x.service_id = s.service_id AND x.document_id = d.document_id);

-- ======================================================================================
-- 13. Contracts (department workspace > Contracts; also listed company-wide)
-- ======================================================================================
INSERT INTO contracts (contract_name, contract_status, contract_type,
                       contract_sla_low_response_time, contract_sla_low_resolution_time,
                       contract_sla_medium_response_time, contract_sla_medium_resolution_time,
                       contract_sla_high_response_time, contract_sla_high_resolution_time,
                       contract_details, contract_client_id, contract_start_date, contract_end_date, contract_renewal_date,
                       contract_support_hours_included_remote, contract_support_hours_included_onsite, contract_created_by)
SELECT x.name, 'Active', x.ctype, x.lr, x.lres, x.mr, x.mres, x.hr, x.hres, x.details, c.client_id,
       DATE_SUB(CURDATE(), INTERVAL x.start_days DAY), DATE_ADD(CURDATE(), INTERVAL x.end_days DAY), DATE_ADD(CURDATE(), INTERVAL x.end_days DAY),
       x.hrs_remote, x.hrs_onsite, @u_alex
FROM (
  SELECT 'Production Floor Support SLA' AS name, 'SLA' AS ctype, 8 AS lr, 48 AS lres, 4 AS mr, 24 AS mres, 1 AS hr, 4 AS hres,
         'Response and resolution targets for plant-floor incidents. High priority covers line-down events.' AS details,
         'Production' AS dept, 327 AS start_days, 38 AS end_days, NULL AS hrs_remote, NULL AS hrs_onsite
  UNION ALL SELECT 'Executive Office Priority Support', 'Managed Services', 4, 24, 2, 8, 1, 2,
         'Priority handling for the executive team, including onsite visits in the Headquarters building.',
         'Executive Office', 20, 345, 10, 4
) x
JOIN clients c ON c.client_name = x.dept
WHERE NOT EXISTS (SELECT 1 FROM contracts k WHERE k.contract_name = x.name AND k.contract_client_id = c.client_id);
