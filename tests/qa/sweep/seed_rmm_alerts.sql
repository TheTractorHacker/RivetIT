-- Scratch demo only (QA-UI-): endpoint-agent alerts shaped like the real bridge writes them (rmm_alerts under a rivetit_agent integration
-- plus the rmm_alert_meta record RivetCore keeps), including the awkward cases: a meta record with no rmm_alerts row, a mirror row that
-- disagrees with its meta record, and long device/check names.
INSERT INTO rmm_integrations (name, type, api_url, api_key_enc, enabled) SELECT 'RivetIT endpoint agent', 'rivetit_agent', '', '', 1 WHERE NOT EXISTS (SELECT 1 FROM rmm_integrations WHERE type = 'rivetit_agent');
SET @ig = (SELECT id FROM rmm_integrations WHERE type = 'rivetit_agent' LIMIT 1);
DELETE FROM rmm_alert_meta WHERE message LIKE 'QA-UI-%';
DELETE FROM rmm_alerts WHERE tactical_alert_id LIKE 'qa-ui-%';
DELETE FROM endpoint_agent_devices WHERE hostname LIKE 'QA-UI-%';
INSERT INTO endpoint_agent_devices (install_id, hostname, os, agent_version, asset_id, client_id, token_hash, link_state)
 SELECT UUID(), CONCAT('QA-UI-HOST-', n), IF(n % 2, 'windows', 'linux'), '1.0.0', 91 + n, 15 + n, SHA2(CONCAT('qa', n), 256), 'linked' FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) x;
INSERT INTO endpoint_agent_devices (install_id, hostname, os, agent_version, asset_id, client_id, token_hash, link_state)
 VALUES (UUID(), 'QA-UI-MACFLEET-JEFFTNB-WITH-A-LONG-HOSTNAME', 'linux', '1.0.0', 92, 16, SHA2('qa-long', 256), 'linked');
-- 10 open (8 critical, 2 warning), 2 acknowledged, 3 resolved: rmm_alerts mirror + meta
INSERT INTO rmm_alerts (asset_id, client_id, integration_id, tactical_alert_id, severity, status, message, created_at)
 SELECT d.asset_id, d.client_id, @ig, CONCAT('qa-ui-', n), IF(n <= 8, 'critical', 'warning'), 'new', CONCAT('QA-UI-', 'Endpoint agent check ''', IF(n % 3 = 0, 'svc_systemd-timesyncd', 'disk_free'), ''' ', IF(n <= 8, 'fail', 'warn'), ' on ', d.hostname, ': detail ', n), NOW() - INTERVAL n HOUR
 FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10) s
 JOIN endpoint_agent_devices d ON d.hostname = IF(s.n = 7, 'QA-UI-MACFLEET-JEFFTNB-WITH-A-LONG-HOSTNAME', CONCAT('QA-UI-HOST-', 1 + (s.n % 6)));
INSERT INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, group_key, severity, state, message, opened_at)
 SELECT a.id, d.device_id, a.client_id, IF(RIGHT(a.tactical_alert_id, 1) IN ('3', '6', '9'), 'svc_systemd-timesyncd', 'disk_free'), CAST(SUBSTRING(a.tactical_alert_id, 7) AS UNSIGNED), CONCAT(a.client_id, ':chk'), IF(a.severity = 'critical', 'crit', 'warn'), 'open', a.message, a.created_at
 FROM rmm_alerts a JOIN endpoint_agent_devices d ON d.asset_id = a.asset_id AND d.hostname LIKE 'QA-UI-%' WHERE a.tactical_alert_id LIKE 'qa-ui-%' GROUP BY a.id;
-- two acknowledged, one resolved (consistent in both tables)
UPDATE rmm_alert_meta SET state = 'acknowledged', acked_at = NOW() WHERE message LIKE 'QA-UI-%' AND alert_id IN (SELECT id FROM rmm_alerts WHERE tactical_alert_id IN ('qa-ui-9', 'qa-ui-10'));
UPDATE rmm_alerts SET status = 'acknowledged', acknowledged_at = NOW() WHERE tactical_alert_id IN ('qa-ui-9', 'qa-ui-10');
-- a mirror row that still says new while the meta record is resolved (divergence)
UPDATE rmm_alert_meta SET state = 'resolved', resolved_at = NOW(), resolve_reason = 'manual' WHERE message LIKE 'QA-UI-%' AND alert_id IN (SELECT id FROM rmm_alerts WHERE tactical_alert_id = 'qa-ui-8');
-- meta record with no rmm_alerts row at all (orphan): must still be listed
INSERT INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, group_key, severity, state, message, opened_at)
 SELECT 900001, device_id, client_id, 'orphan_check', 1, 'qa:orphan', 'crit', 'open', 'QA-UI-orphan agent alert (no rmm_alerts row)', NOW() - INTERVAL 30 MINUTE FROM endpoint_agent_devices WHERE hostname = 'QA-UI-HOST-1' LIMIT 1;
