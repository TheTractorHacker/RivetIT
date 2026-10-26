<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmEscalationInterface;

/**
 * RivetIT's delivery of RMM alert escalations (RivetCore 1.0.0-rc.10, RmmEscalationInterface). Core decides WHEN a step is due and WHO it addresses;
 * this class only delivers, with what RivetIT already has:
 *
 *   - a person: an in-app notification (notifyUser(): the bell, the live stream and the mobile push) and an email through the mail queue
 *     (addToMailQueue(): sent by cron/mail_queue.php with the ticket system's From identity);
 *   - the targets of a step: `user` (a user id), `group` (a role id: every active user with that role, at most 50) and `email` (an address). `chat` and
 *     `webhook` are not delivered here (RivetIT's chat and webhook paths are the event bus: subscribe to rmm.alert.escalated); a step whose only targets
 *     are those answers false, so Core keeps the step due and says so instead of recording a notice nobody got;
 *   - the assigned technician: when the alert already has a ticket, its assignee is told too (they own it), whatever the step lists;
 *   - the configured escalation contact (Administration > Endpoint agent > Alerting): the fallback when a step resolves to nobody at all, and always
 *     copied on a critical alert so an unattended alert never goes silent;
 *   - a ticket for a critical alert: createTicketFromRmmAlert() (includes/rmm_functions.php, the SAME function the RMM Alerts page button and the
 *     cron auto-ticketing use, which reuses an open ticket instead of opening a second one). Only an alert with a department and severity "error".
 *
 * acknowledgeAlert()/raiseAlertSeverity() mirror what Core did onto RivetIT's own rmm_alerts row (the page, the dashboard and the auto-ticketing read it).
 *
 * The three methods never throw: a failure is logged and counts as "not delivered" for notify(), and as nothing for the other two. Idempotent where it can be:
 * a second acknowledgement changes nothing, a second severity change to the same value changes nothing; a repeated notify() notifies again (Core only repeats
 * it after a false answer), and the ticket is never opened twice.
 */
final class EndpointEscalation implements RmmEscalationInterface
{
    /** Most people one step reaches through a role. */
    public const MAX_GROUP_USERS = 50;
    private const TYPE = 'RMM Alert Escalation';

    public function __construct(private \mysqli $mysqli)
    {
    }

    public function notify(int $integrationId, array $notification): bool
    {
        try {
            return $this->deliver($notification);
        } catch (\Throwable $e) {
            error_log('RMM escalation notify failed: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    public function acknowledgeAlert(int $integrationId, int $alertId, int $userId): void
    {
        try {
            $by = $userId > 0 ? $userId : null;
            $st = $this->mysqli->prepare("UPDATE rmm_alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND integration_id = ? AND status = 'new'");
            $st->bind_param('iii', $by, $alertId, $integrationId);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {
            error_log('RMM escalation acknowledge mirror failed: ' . $e->getMessage());
        }
    }

    public function raiseAlertSeverity(int $integrationId, int $alertId, string $severity): void
    {
        if (!in_array($severity, ['warning', 'error'], true)) {
            return;
        }
        try {
            $st = $this->mysqli->prepare("UPDATE rmm_alerts SET severity = ? WHERE id = ? AND integration_id = ? AND status <> 'resolved' AND (severity IS NULL OR severity <> ?)");
            $st->bind_param('siis', $severity, $alertId, $integrationId, $severity);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {
            error_log('RMM escalation severity mirror failed: ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $n the Core notification */
    private function deliver(array $n): bool
    {
        $alertId = (int) ($n['alert_id'] ?? 0);
        $alert = $alertId > 0 ? $this->alertRow($alertId) : null;
        $critical = ($n['severity'] ?? '') === 'error';

        // A critical alert that belongs to a department gets a ticket (or its open one); the notice then names it.
        $ticket = null;
        if ($critical && $alert !== null && (int) ($alert['client_id'] ?? 0) > 0) {
            $ticket = $this->ensureTicket($alert);
            if ($ticket !== null) {
                $alert = $this->alertRow($alertId) ?? $alert;
            }
        }

        $users = [];
        $emails = [];
        $unsupported = 0;
        foreach ((array) ($n['targets'] ?? []) as $t) {
            $type = (string) ($t['type'] ?? '');
            $ref = trim((string) ($t['ref'] ?? ''));
            if ($type === 'user' && ctype_digit($ref)) {
                $users[(int) $ref] = true;
            } elseif ($type === 'group' && ctype_digit($ref)) {
                foreach ($this->roleUsers((int) $ref) as $uid) {
                    $users[$uid] = true;
                }
            } elseif ($type === 'email' && filter_var($ref, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($ref)] = $ref;
            } else {
                ++$unsupported;
            }
        }
        $explicit = $users !== [] || $emails !== [];
        // The assigned technician of the alert's ticket always hears about it.
        if ($alert !== null && (int) ($alert['ticket_id'] ?? 0) > 0) {
            $assignee = $this->ticketAssignee((int) $alert['ticket_id']);
            if ($assignee > 0) {
                $users[$assignee] = true;
            }
        }
        // The escalation contact: the fallback for a step that reaches nobody, and a copy on every critical alert.
        if (!$explicit || $critical) {
            foreach ($this->contacts() as $c) {
                if (ctype_digit($c)) {
                    $users[(int) $c] = true;
                } else {
                    $emails[strtolower($c)] = $c;
                }
            }
        }

        $subject = $this->subject($n);
        $text = $this->text($n, $ticket);
        $delivered = 0;
        foreach (array_keys($users) as $uid) {
            $u = $this->activeUser($uid);
            if ($u === null) {
                continue;
            }
            $delivered += $this->inApp($uid, $text, $n, $alert) ? 1 : 0;
            if (filter_var($u['user_email'], FILTER_VALIDATE_EMAIL)) {
                unset($emails[strtolower((string) $u['user_email'])]);
                $delivered += $this->email((string) $u['user_email'], (string) $u['user_name'], $subject, $this->html($n, $ticket), $n) ? 1 : 0;
            }
        }
        foreach ($emails as $addr) {
            $delivered += $this->email($addr, '', $subject, $this->html($n, $ticket), $n) ? 1 : 0;
        }

        return $delivered > 0;
    }

    /** @return array<string,mixed>|null */
    private function alertRow(int $id): ?array
    {
        $st = $this->mysqli->prepare('SELECT * FROM rmm_alerts WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        return $row ?: null;
    }

    /**
     * The ticket for a critical alert: the open one when there is one, a new one from the existing RMM-alert-to-ticket function otherwise. Null when the
     * function is not available here (a harness without the application) or opening it failed; the notice goes out either way.
     *
     * @param array<string,mixed> $alert
     * @return array{ticket_id:int,existing:bool}|null
     */
    private function ensureTicket(array $alert): ?array
    {
        try {
            if (!function_exists('createTicketFromRmmAlert')) {
                $file = dirname(__DIR__, 4) . '/includes/rmm_functions.php';
                if (is_file($file) && function_exists('randomString') && function_exists('resolveTicketAssignee')) {
                    require_once $file;
                }
            }
            if (!function_exists('createTicketFromRmmAlert')) {
                return null;
            }
            $r = createTicketFromRmmAlert($this->mysqli, $alert, 0, 'RMM Escalation');

            return ['ticket_id' => (int) $r['ticket_id'], 'existing' => (bool) $r['existing']];
        } catch (\Throwable $e) {
            error_log('RMM escalation ticket not opened: ' . $e->getMessage());

            return null;
        }
    }

    private function ticketAssignee(int $ticketId): int
    {
        $st = $this->mysqli->prepare('SELECT ticket_assigned_to FROM tickets WHERE ticket_id = ? AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL');
        $st->bind_param('i', $ticketId);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();

        return (int) ($row[0] ?? 0);
    }

    /** @return list<int> */
    private function roleUsers(int $roleId): array
    {
        $out = [];
        $st = $this->mysqli->prepare('SELECT user_id FROM users WHERE user_role_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT ' . self::MAX_GROUP_USERS);
        $st->bind_param('i', $roleId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_row()) {
            $out[] = (int) $r[0];
        }
        $st->close();

        return $out;
    }

    /** @return array{user_name:string,user_email:string}|null */
    private function activeUser(int $userId): ?array
    {
        $st = $this->mysqli->prepare('SELECT user_name, user_email FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        return $row ?: null;
    }

    /** The configured escalation contact: user ids and addresses separated by commas, semicolons or lines. @return list<string> */
    private function contacts(): array
    {
        try {
            $rs = $this->mysqli->query('SELECT config_rmm_escalation_contact FROM settings WHERE company_id = 1');
            $raw = (string) ($rs ? ($rs->fetch_row()[0] ?? '') : '');
        } catch (\Throwable) {
            return [];   // the column does not exist yet (code newer than the schema)
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $c) {
            $c = trim($c);
            if ($c !== '' && (ctype_digit($c) || filter_var($c, FILTER_VALIDATE_EMAIL))) {
                $out[$c] = $c;
            }
        }

        return array_values($out);
    }

    /** @param array<string,mixed> $n */
    private function subject(array $n): string
    {
        $sev = ($n['severity'] ?? '') === 'error' ? 'Critical' : 'Warning';

        return mb_substr("[$sev] " . ((string) ($n['hostname'] ?? '') !== '' ? $n['hostname'] . ': ' : '') . ((string) ($n['check_key'] ?? 'check')) . ' needs attention (escalation step ' . (int) ($n['step'] ?? 1) . ')', 0, 200);
    }

    /**
     * @param array<string,mixed> $n
     * @param array{ticket_id:int,existing:bool}|null $ticket
     */
    private function text(array $n, ?array $ticket): string
    {
        $t = ($n['severity'] ?? '') === 'error' ? 'Critical' : 'Warning';
        $t .= ' alert on ' . ((string) ($n['hostname'] ?? '') ?: 'a device') . ': ' . (string) ($n['message'] ?? '');
        $t .= ' Escalation policy "' . (string) ($n['policy_name'] ?? '') . '", step ' . (int) ($n['step'] ?? 1) . (!empty($n['repeat']) ? ' (repeat)' : '') . '.';
        if ($ticket !== null) {
            $t .= ' Ticket #' . $ticket['ticket_id'] . ($ticket['existing'] ? ' is open.' : ' was opened.');
        }

        return mb_substr($t, 0, 900);
    }

    /**
     * @param array<string,mixed> $n
     * @param array{ticket_id:int,existing:bool}|null $ticket
     */
    private function html(array $n, ?array $ticket): string
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $host = (string) ($GLOBALS['config_base_url'] ?? '');
        $base = $host !== '' ? 'https://' . $host : '';
        $link = $base . '/agent/rmm_agent_alerts.php?alert_id=' . (int) ($n['alert_id'] ?? 0);
        $body = '<p>' . $e($this->text($n, null)) . '</p><table cellpadding="4">'
            . '<tr><td>Device</td><td>' . $e($n['hostname'] ?? '') . '</td></tr>'
            . '<tr><td>Check</td><td>' . $e($n['check_key'] ?? '') . '</td></tr>'
            . '<tr><td>Severity</td><td>' . $e(($n['severity'] ?? '') === 'error' ? 'Critical' : 'Warning') . '</td></tr>'
            . '<tr><td>Opened</td><td>' . $e($n['opened_at'] ?? '') . '</td></tr>';
        if ($ticket !== null) {
            $body .= '<tr><td>Ticket</td><td>' . ($base !== '' ? '<a href="' . $e($base . '/agent/ticket.php?ticket_id=' . $ticket['ticket_id']) . '">#' . $ticket['ticket_id'] . '</a>' : '#' . $ticket['ticket_id']) . '</td></tr>';
        }

        return $body . '</table>' . ($base !== '' ? '<p><a href="' . $e($link) . '">Open the alert</a> to acknowledge or resolve it, which stops the escalation.</p>' : ('<p>Acknowledge or resolve the alert in ' . htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' to stop the escalation.</p>'));
    }

    /**
     * @param array<string,mixed> $n
     * @param array<string,mixed>|null $alert
     */
    private function inApp(int $userId, string $text, array $n, ?array $alert): bool
    {
        if (!function_exists('notifyUser')) {
            return false;
        }
        notifyUser($userId, self::TYPE, $text, '/agent/rmm_agent_alerts.php?alert_id=' . (int) ($n['alert_id'] ?? 0), (int) ($n['client_id'] ?? 0), (int) ($n['alert_id'] ?? 0));

        return true;
    }

    /** @param array<string,mixed> $n */
    private function email(string $to, string $name, string $subject, string $html, array $n): bool
    {
        if (!function_exists('addToMailQueue')) {
            return false;
        }
        $rs = $this->mysqli->query('SELECT config_mail_from_email, config_mail_from_name FROM settings WHERE company_id = 1');
        $from = $rs ? $rs->fetch_assoc() : null;
        if (!$from || !filter_var((string) $from['config_mail_from_email'], FILTER_VALIDATE_EMAIL)) {
            return false;   // no sender configured: nothing can be queued honestly
        }
        addToMailQueue([[
            'from' => (string) $from['config_mail_from_email'],
            'from_name' => (string) $from['config_mail_from_name'],
            'recipient' => $to,
            'recipient_name' => $name,
            'subject' => $subject,
            'body' => $html,
        ]]);

        return true;
    }
}
