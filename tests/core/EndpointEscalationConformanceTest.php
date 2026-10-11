<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmEscalationInterface;
use RivetCore\Testing\RmmEscalationConformanceTestCase;

// Stand-ins for the application functions the adapter calls (functions.php needs a whole app bootstrap). They write the same rows the real ones write, minimally,
// so the delivery is observable in the scratch database.
if (!function_exists('notifyUser')) {
    function notifyUser($user_id, $type, $details, $action = null, $client_id = 0, $entity_id = 0, $push = true): void
    {
        KitSupport::db()->execute('INSERT INTO notifications SET notification_type = ?, notification = ?, notification_action = ?, notification_client_id = ?, notification_entity_id = ?, notification_user_id = ?',
            [substr((string) $type, 0, 200), substr((string) $details, 0, 1000), $action, (int) $client_id, (int) $entity_id, (int) $user_id]);
    }
}
if (!function_exists('addToMailQueue')) {
    function addToMailQueue($data)
    {
        foreach ($data as $e) {
            KitSupport::db()->execute('INSERT INTO email_queue SET email_recipient = ?, email_recipient_name = ?, email_from = ?, email_from_name = ?, email_subject = ?, email_content = ?, email_queued_at = NOW(), email_cal_str = ?',
                [$e['recipient'], $e['recipient_name'], $e['from'], $e['from_name'], $e['subject'], $e['body'], '']);
        }

        return true;
    }
}

/**
 * EndpointEscalation delivers RMM alert escalations (in-app notification, email through the mail queue, a ticket for a critical alert). The kit's calls use
 * made-up alert, device and user ids, so what is proved here is the contract: no call throws, an unknown alert is a quiet no-op, repeated calls are harmless,
 * and notify() answers true only when something was really delivered (the email target is, a sender address being configured) and false when nothing was.
 * The ticket and the real alert rows are proved in tests/rmm_ui_p23_*.php over the real check-in and housekeeping.
 */
final class EndpointEscalationConformanceTest extends RmmEscalationConformanceTestCase
{
    /** @var list<array<string,mixed>> */
    private array $seen = [];

    protected function setUp(): void
    {
        $db = EndpointKit::db();
        $db->execute("UPDATE settings SET config_mail_from_email = 'noreply@example.test', config_mail_from_name = 'RivetIT' WHERE company_id = 1");
        $this->seen = [];
    }

    protected function escalation(): RmmEscalationInterface
    {
        $inner = new \ITFlow\Core\Adapter\Endpoint\EndpointEscalation(EndpointKit::mysqli());
        $seen = &$this->seen;

        return new class($inner, $seen) implements RmmEscalationInterface {
            /** @param list<array<string,mixed>> $seen */
            public function __construct(private RmmEscalationInterface $inner, private array &$seen)
            {
            }

            public function notify(int $integrationId, array $notification): bool
            {
                $this->seen[] = $notification;

                return $this->inner->notify($integrationId, $notification);
            }

            public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void
            {
                $this->inner->acknowledgeAlert($integrationId, $alertId, $userId);
            }

            public function raiseAlertSeverity(int $integrationId, int $alertId, string $severity): void
            {
                $this->inner->raiseAlertSeverity($integrationId, $alertId, $severity);
            }
        };
    }

    protected function notifications(): ?array
    {
        return $this->seen;
    }

    public function testAnEmailTargetIsQueuedAndAUserTargetOnlyWhenActive(): void
    {
        $db = EndpointKit::db();
        $db->execute("DELETE FROM email_queue WHERE email_recipient IN ('noc@example.test', 'esc-user@example.test', 'esc-gone@example.test')");
        $db->execute("DELETE FROM notifications WHERE notification_entity_id = 777001");
        $db->execute("DELETE FROM users WHERE user_name IN ('esc-user', 'esc-gone')");
        $uid = (int) $db->execute("INSERT INTO users SET user_name = 'esc-user', user_email = 'esc-user@example.test', user_password = 'x', user_type = 1, user_status = 1")->insertId;
        $gone = (int) $db->execute("INSERT INTO users SET user_name = 'esc-gone', user_email = 'esc-gone@example.test', user_password = 'x', user_type = 1, user_status = 0")->insertId;
        $n = ['alert_id' => 777001, 'device_id' => 1, 'asset_id' => null, 'client_id' => 0, 'hostname' => 'H', 'check_key' => 'disk_c', 'severity' => 'warning', 'message' => 'm', 'state' => 'open', 'step' => 1,
            'repeat' => false, 'policy_id' => 1, 'policy_name' => 'p', 'targets' => [['type' => 'user', 'ref' => (string) $uid], ['type' => 'user', 'ref' => (string) $gone], ['type' => 'email', 'ref' => 'noc@example.test']],
            'opened_at' => '2026-10-10T12:00:00Z', 'notified_at' => '2026-10-10T12:05:00Z'];
        $this->assertTrue($this->escalation()->notify(1, $n));
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM notifications WHERE notification_user_id = ? AND notification_entity_id = 777001', [$uid])['c']);
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM notifications WHERE notification_user_id = ?', [$gone])['c'], 'an inactive user is not notified');
        $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) AS c FROM email_queue WHERE email_recipient = 'noc@example.test'")['c']);
        $this->assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) AS c FROM email_queue WHERE email_recipient = 'esc-user@example.test'")['c']);
        // a chat target alone reaches nobody: honest false
        $n['targets'] = [['type' => 'chat', 'ref' => '#ops'], ['type' => 'webhook', 'ref' => 'https://x.example.test/h']];
        $this->assertFalse($this->escalation()->notify(1, $n));
    }

    public function testAcknowledgeAndSeverityMirrorOntoTheAlertRow(): void
    {
        $db = EndpointKit::db();
        $iid = (int) $db->execute("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('esc-kit', 'rivetit_agent_kit', '', '', '', 1)")->insertId;
        $aid = (int) $db->execute("INSERT INTO rmm_alerts (integration_id, tactical_alert_id, severity, status, message) VALUES (?, 'k1', 'warning', 'new', 'x')", [$iid])->insertId;
        $e = $this->escalation();
        $e->raiseAlertSeverity($iid, $aid, 'error');
        $e->raiseAlertSeverity($iid, $aid, 'error');
        $e->raiseAlertSeverity($iid, $aid, 'bogus');
        $this->assertSame('error', $db->fetchOne('SELECT severity FROM rmm_alerts WHERE id = ?', [$aid])['severity']);
        $e->acknowledgeAlert($iid, $aid, 0);
        $e->acknowledgeAlert($iid, $aid, 0);
        $row = $db->fetchOne('SELECT status, acknowledged_by FROM rmm_alerts WHERE id = ?', [$aid]);
        $this->assertSame('acknowledged', $row['status']);
        $this->assertNull($row['acknowledged_by']);
    }
}
