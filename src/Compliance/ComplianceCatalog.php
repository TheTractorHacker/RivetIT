<?php

namespace ITFlow\Compliance;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetCore\Compliance\Check\AuditTrailRecordingCheck;
use RivetCore\Compliance\Check\RetentionMeetsPresetCheck;
use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;
use RivetCore\Compliance\Framework as F;
use RivetCore\Compliance\ManualItem;

/**
 * Everything the compliance status page measures: automatic checks read this installation's own settings and records;
 * the manual checklist covers what software cannot see. Control references are indicative starting points, not a
 * mapping reviewed by an assessor; confirm them against the current text of each standard.
 */
final class ComplianceCatalog
{
    private const SETTINGS_PATH = 'settings_compliance.php';
    private const STALE_AGENT_DAYS = 90;

    public function __construct(private \mysqli $db, private string $appRoot, private array $settings)
    {
    }

    private function one(string $sql): array
    {
        $res = mysqli_query($this->db, $sql);

        return ($res ? mysqli_fetch_assoc($res) : null) ?: [];
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** @return list<CheckInterface> */
    public function checks(): array
    {
        $agent = "user_type = 1 AND user_status = 1 AND user_archived_at IS NULL";
        $mfaSql = "(u.user_token IS NOT NULL AND u.user_token <> '' OR EXISTS (SELECT 1 FROM user_passkeys p WHERE p.passkey_user_id = u.user_id) OR u.user_auth_method IN ('oidc','odoo'))";

        $out = [];
        $out[] = new AuditTrailRecordingCheck(new MysqliDatabaseAdapter($this->db), (bool) class_exists(\ITFlow\Audit\AuditService::class), self::SETTINGS_PATH);
        $out[] = new RetentionMeetsPresetCheck(
            (string) $this->setting('config_compliance_profile', 'none'),
            (int) $this->setting('config_log_retention', 0),
            (int) $this->setting('config_audit_retention_days', 365),
            self::SETTINGS_PATH
        );

        $out[] = new CallbackCheck('mfa_coverage', 'Multi-factor authentication for every agent', 'Access control',
            'Passwords alone are the most common way in. Authenticator apps, passkeys, or a single sign-on provider that enforces its own MFA all count here.',
            [F::ISO27001 => ['A.8.5'], F::SOC2 => ['CC6.1'], F::PCI => ['8.4.2'], F::HIPAA => ['164.312(d)']],
            function () use ($agent, $mfaSql): CheckResult {
                $r = $this->one("SELECT COUNT(*) AS total, SUM($mfaSql) AS covered FROM users u WHERE $agent");
                $total = (int) ($r['total'] ?? 0);
                $covered = (int) ($r['covered'] ?? 0);
                if ($total === 0) {
                    return CheckResult::notApplicable('No active agents.');
                }
                $m = ['agents' => $total, 'with_mfa' => $covered];
                if ($covered === $total) {
                    return CheckResult::pass("All $total active agents use MFA, a passkey or single sign-on.", null, $m);
                }

                return CheckResult::fail(($total - $covered) . " of $total active agents have no MFA.", 'Ask them to enrol an authenticator app or passkey from their profile.', 'users.php', $m);
            });

        $out[] = new CallbackCheck('admin_mfa', 'Administrators use MFA', 'Access control',
            'Administrator accounts can change everything, so they are the highest-value target.',
            [F::ISO27001 => ['A.8.2', 'A.8.5'], F::SOC2 => ['CC6.1', 'CC6.3'], F::PCI => ['8.4.1'], F::HIPAA => ['164.312(d)']],
            function () use ($agent, $mfaSql): CheckResult {
                $r = $this->one("SELECT COUNT(*) AS total, SUM(CASE WHEN $mfaSql THEN 0 ELSE 1 END) AS bare FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND r.role_is_admin = 1");
                $total = (int) ($r['total'] ?? 0);
                $bare = (int) ($r['bare'] ?? 0);
                if ($total === 0) {
                    return CheckResult::notApplicable('No active administrators found.');
                }

                return $bare === 0
                    ? CheckResult::pass("All $total administrators use MFA, a passkey or single sign-on.", null, ['admins' => $total])
                    : CheckResult::fail("$bare of $total administrators have no MFA.", null, 'users.php', ['admins' => $total, 'without_mfa' => $bare]);
            });

        $out[] = new CallbackCheck('admin_count', 'Administrator accounts are kept few', 'Access control',
            'Least privilege: only people who need full control should have it.',
            [F::ISO27001 => ['A.8.2'], F::SOC2 => ['CC6.3'], F::PCI => ['7.2.1'], F::HIPAA => ['164.308(a)(4)']],
            function () use ($agent): CheckResult {
                $r = $this->one("SELECT COUNT(*) AS admins, (SELECT COUNT(*) FROM users WHERE $agent) AS total FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND r.role_is_admin = 1");
                $admins = (int) ($r['admins'] ?? 0);
                $total = (int) ($r['total'] ?? 0);
                $m = ['admins' => $admins, 'agents' => $total];
                if ($total <= 3) {
                    return CheckResult::pass("$admins of $total agents are administrators.", 'Small team: judged by your own policy.', $m);
                }

                return $admins * 2 > $total
                    ? CheckResult::warn("$admins of $total agents are administrators.", 'More than half the team has full control. Consider a narrower role.', 'roles.php', $m)
                    : CheckResult::pass("$admins of $total agents are administrators.", null, $m);
            });

        $out[] = new CallbackCheck('session_lifetime', 'Sign-in sessions are limited', 'Access control',
            'Long-lived sessions leave an unattended or stolen device signed in.',
            [F::ISO27001 => ['A.8.5'], F::SOC2 => ['CC6.1'], F::PCI => ['8.2.8'], F::HIPAA => ['164.312(a)(2)(iii)']],
            function (): CheckResult {
                $min = (int) $this->setting('config_login_session_lifetime', 43200);
                $days = round($min / 1440, 1);
                $m = ['session_days' => $days];
                if ($min <= 43200) {
                    return CheckResult::pass("Sessions last up to $days days.", 'Shorter is stricter; PCI DSS expects idle sessions to end within 15 minutes, which is a separate control.', $m);
                }

                return $min <= 86400
                    ? CheckResult::warn("Sessions last up to $days days.", null, 'settings_security.php', $m)
                    : CheckResult::fail("Sessions last up to $days days.", null, 'settings_security.php', $m);
            });

        $out[] = new CallbackCheck('https_only', 'Traffic is encrypted (HTTPS only)', 'Cryptography',
            'Credentials and data must not cross the network in clear text.',
            [F::ISO27001 => ['A.8.24'], F::SOC2 => ['CC6.7'], F::PCI => ['4.2.1'], F::HIPAA => ['164.312(e)(1)']],
            fn (): CheckResult => !empty($GLOBALS['config_https_only'])
                ? CheckResult::pass('The application only serves HTTPS.')
                : CheckResult::fail('HTTPS-only is switched off in config.php.', 'Set $config_https_only = TRUE once TLS is in place.'));

        $out[] = new CallbackCheck('vault_key', 'Credential vault has a canonical key set', 'Cryptography',
            'The vault key protects stored credentials; a documented, rotated key supports key-management requirements.',
            [F::ISO27001 => ['A.8.24'], F::SOC2 => ['CC6.1'], F::PCI => ['3.6.1'], F::HIPAA => ['164.312(a)(2)(iv)']],
            function (): CheckResult {
                $at = $this->setting('config_vault_canonical_key_set_at');

                return $at ? CheckResult::pass('Vault key was set on ' . substr((string) $at, 0, 10) . '.') : CheckResult::warn('No vault key date is recorded.', 'Set the canonical vault key in the security settings.', 'settings_security.php');
            });

        $out[] = new CallbackCheck('backups', 'Backups run, are encrypted and are fresh', 'Resilience',
            'You can only recover from an incident if a recent, protected copy exists.',
            [F::ISO27001 => ['A.8.13'], F::SOC2 => ['A1.2', 'CC9.1'], F::PCI => ['12.10.1'], F::HIPAA => ['164.308(a)(7)(ii)(A)']],
            function (): CheckResult {
                $files = array_merge(glob($this->appRoot . '/backups/*.zip') ?: [], glob($this->appRoot . '/backups/*.enc') ?: []);
                $newest = 0;
                foreach ($files as $f) {
                    $newest = max($newest, (int) @filemtime($f));
                }
                $enabled = (int) $this->setting('config_backup_auto_enabled', 0) === 1;
                $m = ['scheduled' => $enabled, 'offsite' => (int) $this->setting('config_backup_s3_enabled', 0) === 1, 'newest_backup' => $newest ? date('Y-m-d H:i', $newest) : null];
                if ($newest === 0) {
                    return CheckResult::fail('No backup file was found.', null, 'backup.php', $m);
                }
                $age = (time() - $newest) / 86400;
                $notes = [$enabled ? 'scheduled backups on' : 'scheduled backups off', $m['offsite'] ? 'off-site copy on' : 'no off-site copy'];
                if ($age > 14) {
                    return CheckResult::fail('The newest backup is ' . (int) $age . ' days old.', implode('; ', $notes), 'backup.php', $m);
                }

                return ($age > 8 || !$enabled || !$m['offsite'])
                    ? CheckResult::warn('Newest backup is ' . max(0, (int) $age) . ' days old.', implode('; ', $notes), 'backup.php', $m)
                    : CheckResult::pass('Newest backup is ' . max(0, (int) $age) . ' days old.', implode('; ', $notes), $m);
            });

        $out[] = new CallbackCheck('credential_rotation', 'Stored credentials are rotated on time', 'Access control',
            'Credentials past their rotation date are a standing risk.',
            [F::ISO27001 => ['A.5.17'], F::SOC2 => ['CC6.1'], F::PCI => ['8.3.9'], F::HIPAA => ['164.308(a)(5)(ii)(D)']],
            function (): CheckResult {
                $r = $this->one('SELECT COUNT(*) AS due FROM credentials WHERE credential_archived_at IS NULL AND credential_rotation_due_at IS NOT NULL AND credential_rotation_due_at < NOW()');
                $n = (int) ($r['due'] ?? 0);

                return $n === 0 ? CheckResult::pass('No stored credential is past its rotation date.') : CheckResult::warn("$n stored credentials are past their rotation date.", null, 'credentials.php', ['overdue' => $n]);
            });

        $out[] = new CallbackCheck('api_keys', 'API keys expire', 'Access control',
            'Keys that never expire stay valid after the person or system that used them is gone.',
            [F::ISO27001 => ['A.5.16', 'A.8.2'], F::SOC2 => ['CC6.2'], F::PCI => ['8.6.3'], F::HIPAA => ['164.308(a)(3)(ii)(C)']],
            function (): CheckResult {
                $r = $this->one("SELECT COUNT(*) AS total, SUM(CASE WHEN api_key_expire IS NULL OR api_key_expire >= '2099-01-01' THEN 1 ELSE 0 END) AS forever, SUM(CASE WHEN api_key_expire < NOW() THEN 1 ELSE 0 END) AS expired FROM api_keys");
                $total = (int) ($r['total'] ?? 0);
                if ($total === 0) {
                    return CheckResult::notApplicable('No API keys exist.');
                }
                $forever = (int) $r['forever'];
                $expired = (int) $r['expired'];
                $m = ['keys' => $total, 'no_expiry' => $forever, 'expired' => $expired];
                if ($forever === 0 && $expired === 0) {
                    return CheckResult::pass("All $total API keys have a future expiry.", null, $m);
                }

                return CheckResult::warn("$forever of $total API keys never expire; $expired are expired and still listed.", 'Delete unused keys and set an expiry on the rest.', 'api_keys.php', $m);
            });

        $out[] = new CallbackCheck('dormant_agents', 'No dormant agent accounts', 'Access control',
            'Accounts nobody uses are an easy way in. Offboarded people should be disabled promptly.',
            [F::ISO27001 => ['A.5.18'], F::SOC2 => ['CC6.2', 'CC6.3'], F::PCI => ['8.2.6'], F::HIPAA => ['164.308(a)(3)(ii)(C)']],
            function () use ($agent): CheckResult {
                $days = self::STALE_AGENT_DAYS;
                $r = $this->one("SELECT COUNT(*) AS stale FROM users u WHERE $agent AND u.user_created_at < NOW() - INTERVAL $days DAY AND NOT EXISTS (SELECT 1 FROM auth_logs a WHERE a.auth_log_user_id = u.user_id AND a.auth_log_status = 1 AND a.auth_log_created_at >= NOW() - INTERVAL $days DAY)");
                $n = (int) ($r['stale'] ?? 0);

                return $n === 0
                    ? CheckResult::pass("Every active agent signed in within $days days.", 'Judged from the authentication log, which has its own retention.')
                    : CheckResult::warn("$n active agents have no recorded sign-in in $days days.", 'Disable accounts that are no longer needed.', 'users.php', ['dormant' => $n]);
            });

        $out[] = new CallbackCheck('schema_current', 'Application and database are up to date', 'Change management',
            'Running current code with a matching database schema avoids known defects and vulnerabilities.',
            [F::ISO27001 => ['A.8.8', 'A.8.32'], F::SOC2 => ['CC7.1', 'CC8.1'], F::PCI => ['6.3.3'], F::HIPAA => ['164.308(a)(1)(ii)(B)']],
            function (): CheckResult {
                if (!defined("LATEST_DATABASE_VERSION") || !defined("CURRENT_DATABASE_VERSION")) {
                    return CheckResult::error('Version information is unavailable.');
                }
                $cur = (string) CURRENT_DATABASE_VERSION;

                return $cur === LATEST_DATABASE_VERSION
                    ? CheckResult::pass('Database schema is at ' . $cur . '.')
                    : CheckResult::fail("Database is at $cur but the code expects " . LATEST_DATABASE_VERSION . '.', 'Run the update from Administration.', 'database_updates.php');
            });

        if ((int) $this->setting('config_module_enable_training', 0) === 1) {
            $out[] = new CallbackCheck('training_overdue', 'Required training is completed on time', 'People',
                'Staff must be trained for the security duties they carry out.',
                [F::ISO27001 => ['A.6.3'], F::SOC2 => ['CC1.4'], F::PCI => ['12.6.1'], F::HIPAA => ['164.308(a)(5)']],
                function (): CheckResult {
                    $r = $this->one("SELECT SUM(CASE WHEN tassign_due_on < CURDATE() THEN 1 ELSE 0 END) AS overdue, COUNT(*) AS open_total FROM training_assignments WHERE tassign_status = 'open' AND tassign_required = 1");
                    $overdue = (int) ($r['overdue'] ?? 0);

                    return $overdue === 0
                        ? CheckResult::pass('No required training is overdue.', null, ['open' => (int) ($r['open_total'] ?? 0)])
                        : CheckResult::warn("$overdue required training assignments are overdue.", null, 'settings_training.php', ['overdue' => $overdue]);
                });
        }

        return $out;
    }

    /** @return list<ManualItem> */
    public function manualItems(): array
    {
        $m = fn (string $id, string $title, string $cat, string $why, array $controls, int $days = 365) => new ManualItem($id, $title, $cat, $why, $controls, $days);

        return [
            $m('security_policy_review', 'Information security policy reviewed and approved', 'Governance', 'A written, management-approved policy sets direction for everything else.', [F::ISO27001 => ['A.5.1'], F::SOC2 => ['CC1.1', 'CC5.3'], F::PCI => ['12.1.1', '12.1.2'], F::HIPAA => ['164.316(b)(1)']]),
            $m('risk_assessment', 'Risk assessment performed', 'Governance', 'Risks to data and systems are identified and ranked, and treatment is decided.', [F::ISO27001 => ['Clause 6.1.2'], F::SOC2 => ['CC3.2'], F::PCI => ['12.3.1'], F::HIPAA => ['164.308(a)(1)(ii)(A)']]),
            $m('access_review', 'User access review', 'Access control', 'Every account and its permissions are confirmed against current job roles.', [F::ISO27001 => ['A.5.18'], F::SOC2 => ['CC6.2', 'CC6.3'], F::PCI => ['7.2.4'], F::HIPAA => ['164.308(a)(4)(ii)(C)']], 90),
            $m('offboarding_check', 'Leaver process verified', 'Access control', 'Access of departed staff is removed promptly; a sample of recent leavers is checked.', [F::ISO27001 => ['A.6.5'], F::SOC2 => ['CC6.2'], F::PCI => ['8.2.5'], F::HIPAA => ['164.308(a)(3)(ii)(C)']], 180),
            $m('backup_restore_test', 'Backup restore test', 'Resilience', 'A backup is only proven when a restore has worked.', [F::ISO27001 => ['A.8.13'], F::SOC2 => ['A1.3'], F::PCI => ['12.10.1'], F::HIPAA => ['164.308(a)(7)(ii)(D)']], 180),
            $m('dr_bcp_test', 'Disaster recovery and continuity plan tested', 'Resilience', 'The plan for keeping or restoring service has been exercised.', [F::ISO27001 => ['A.5.30'], F::SOC2 => ['A1.3'], F::PCI => ['12.10.2'], F::HIPAA => ['164.308(a)(7)(ii)(B)']]),
            $m('incident_response_test', 'Incident response plan tested', 'Incident management', 'A documented plan, roles and a tabletop or live exercise.', [F::ISO27001 => ['A.5.24', 'A.5.26'], F::SOC2 => ['CC7.3', 'CC7.4'], F::PCI => ['12.10.2'], F::HIPAA => ['164.308(a)(6)']]),
            $m('vuln_scan_pentest', 'Vulnerability scan or penetration test', 'Vulnerability management', 'Weaknesses are found by someone other than the people who built the system.', [F::ISO27001 => ['A.8.8', 'A.8.29'], F::SOC2 => ['CC7.1'], F::PCI => ['11.3.1', '11.4.1'], F::HIPAA => ['164.308(a)(8)']]),
            $m('supplier_review', 'Supplier and sub-processor review', 'Governance', 'Vendors with access to your data are assessed and under agreement (for HIPAA, business associate agreements).', [F::ISO27001 => ['A.5.19', 'A.5.22'], F::SOC2 => ['CC9.2'], F::PCI => ['12.8.1'], F::HIPAA => ['164.308(b)(1)']]),
            $m('security_awareness', 'Security awareness training delivered', 'People', 'Everyone with access has been trained and the record is kept.', [F::ISO27001 => ['A.6.3'], F::SOC2 => ['CC1.4', 'CC2.2'], F::PCI => ['12.6.1'], F::HIPAA => ['164.308(a)(5)']]),
            $m('physical_security', 'Physical security of servers and offices', 'Physical', 'Who can physically reach systems and records is controlled and reviewed.', [F::ISO27001 => ['A.7.1', 'A.7.2'], F::SOC2 => ['CC6.4'], F::PCI => ['9.2.1'], F::HIPAA => ['164.310(a)(1)']]),
            $m('data_classification', 'Data classification and handling rules', 'Governance', 'Data is labelled by sensitivity with matching handling and disposal rules.', [F::ISO27001 => ['A.5.12', 'A.5.13'], F::SOC2 => ['C1.1'], F::PCI => ['3.2.1'], F::HIPAA => ['164.310(d)(1)']]),
            $m('key_management_review', 'Encryption key management reviewed', 'Cryptography', 'Key custody, rotation and recovery procedures are documented and followed.', [F::ISO27001 => ['A.8.24'], F::SOC2 => ['CC6.1'], F::PCI => ['3.6.1', '3.7.1'], F::HIPAA => ['164.312(a)(2)(iv)']]),
        ];
    }
}
