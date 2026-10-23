-- Scratch demo only (QA-UI-): hypervisor hosts, VMs and storage for the Hypervisors page (hand-seeded rows, nothing contacts a host).
UPDATE endpoint_agent_settings SET features_json = JSON_SET(COALESCE(features_json, '{}'), '$.hypervisor', TRUE) WHERE id = 1;
DELETE FROM rmm_hypervisor_vms WHERE device_id IN (SELECT device_id FROM endpoint_agent_devices WHERE hostname LIKE 'QA-UI-%');
DELETE FROM rmm_hypervisor_storage WHERE device_id IN (SELECT device_id FROM endpoint_agent_devices WHERE hostname LIKE 'QA-UI-%');
DELETE FROM rmm_hypervisor_hosts WHERE device_id IN (SELECT device_id FROM endpoint_agent_devices WHERE hostname LIKE 'QA-UI-%');
INSERT INTO rmm_hypervisor_hosts (device_id, platform, version, cluster_name, quorate, vm_count, last_collected_at, last_full_at, last_report_at, error, ignored, updated_at)
 SELECT device_id, IF(os = 'windows', 'hyperv', 'proxmox'), IF(os = 'windows', '10.0.20348', '8.2.4'), IF(hostname IN ('QA-UI-HOST-1', 'QA-UI-HOST-2'), 'pve-cluster-a', ''), IF(hostname IN ('QA-UI-HOST-1', 'QA-UI-HOST-2'), 1, NULL), 0, UTC_TIMESTAMP() - INTERVAL 3 MINUTE, UTC_TIMESTAMP() - INTERVAL 3 MINUTE, UTC_TIMESTAMP() - INTERVAL 3 MINUTE,
        IF(hostname = 'QA-UI-HOST-2', 'ceph: /usr/bin/ceph exited with status 1', IF(hostname = 'QA-UI-MACFLEET-JEFFTNB-WITH-A-LONG-HOSTNAME', 'zfs: pool tank is DEGRADED (a very long error message that needs to wrap or be truncated cleanly in the table)', '')), IF(hostname = 'QA-UI-HOST-6', 1, 0), UTC_TIMESTAMP()
 FROM endpoint_agent_devices WHERE hostname IN ('QA-UI-HOST-1', 'QA-UI-HOST-2', 'QA-UI-HOST-3', 'QA-UI-HOST-4', 'QA-UI-HOST-6', 'QA-UI-MACFLEET-JEFFTNB-WITH-A-LONG-HOSTNAME');
UPDATE rmm_hypervisor_hosts SET last_collected_at = UTC_TIMESTAMP() - INTERVAL 2 DAY, last_report_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE device_id = (SELECT device_id FROM endpoint_agent_devices WHERE hostname = 'QA-UI-HOST-4');
INSERT INTO rmm_hypervisor_vms (device_id, vm_key, name, type, state, node, cpus, cpu_pct, mem_bytes, mem_max_bytes, disk_bytes, disk_used_bytes, uptime_s, template, snapshot_count, backup_covered, first_seen_at, last_seen_at)
 SELECT h.device_id, CONCAT('vm-', h.device_id, '-', n), CONCAT('QA-UI-vm-', n, IF(n = 3, '-with-a-rather-long-virtual-machine-name', '')), IF(h.platform = 'proxmox', 'qemu', 'vm'), ELT(1 + (n % 4), 'running', 'running', 'stopped', 'running'), 'node1', 2 + n % 6, 5 + n * 7 % 90,
        (1 + n % 8) * 1073741824, 8 * 1073741824, 40 * 1073741824, (5 + n * 3) * 1073741824, 3600 * n, 0, n % 3, n % 2, UTC_TIMESTAMP() - INTERVAL 30 DAY, UTC_TIMESTAMP() - INTERVAL 2 MINUTE
 FROM rmm_hypervisor_hosts h JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) x ON x.n <= 3 + (h.device_id % 6);
UPDATE rmm_hypervisor_hosts h SET vm_count = (SELECT COUNT(*) FROM rmm_hypervisor_vms v WHERE v.device_id = h.device_id);
INSERT INTO rmm_hypervisor_storage (device_id, name, type, total_bytes, used_bytes, health, shared, last_seen_at)
 SELECT device_id, 'local-zfs', 'zfspool', 2000000000000, 700000000000, IF(device_id % 3 = 0, 'degraded', 'ok'), 0, UTC_TIMESTAMP() FROM rmm_hypervisor_hosts
 UNION ALL SELECT device_id, 'nas-backup', 'nfs', 8000000000000, 3200000000000, 'ok', 1, UTC_TIMESTAMP() FROM rmm_hypervisor_hosts WHERE device_id % 2 = 0;
