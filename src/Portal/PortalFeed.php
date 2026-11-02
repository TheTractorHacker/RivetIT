<?php

namespace ITFlow\Portal;

/**
 * The employee's notification feed: one read-only list built from what already exists (announcements for their department, staff replies and
 * closures on their own requests, approvals waiting on them, checklist tasks that are due, device warranties running out). Nothing is sent to
 * anyone and no email/chat traffic is involved. Everything is scoped to ONE contact and that contact's own department.
 *
 * Items either age out of "unread" once the contact marks the feed read (portal_feed_state), or are "sticky" (an approval waiting on you, a
 * task that is due, a warranty about to end): they stay unread until the underlying thing is dealt with.
 */
class PortalFeed
{
    public const WINDOW_DAYS = 30;

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * @return list<array{key:string,type:string,title:string,detail:string,link:string,at:string,sticky:bool,unread:bool,severity:string}>
     */
    public function items(int $contactId, int $clientId, int $limit = 40): array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $seen = $this->seenAt($contactId);
        $items = [];
        $nowRow = $this->rows("SELECT NOW() AS n");
        $now = isset($nowRow[0]['n']) ? strtotime((string) $nowRow[0]['n']) : time();   // the database clock, like every timestamp compared below

        foreach ((new Announcements($this->db))->visibleFor($clientId, 20) as $a) {
            if (strtotime($a['announcement_publish_at']) < $now - self::WINDOW_DAYS * 86400 && (int) $a['announcement_pinned'] !== 1) {
                continue;
            }
            $items[] = $this->item('ann' . (int) $a['announcement_id'], 'announcement', (string) $a['announcement_title'], self::excerpt((string) $a['announcement_body']),
                'index.php#announcements', (string) $a['announcement_publish_at'], false, $seen, (string) $a['announcement_severity']);
        }

        $w = self::WINDOW_DAYS;
        foreach ($this->rows("SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, MAX(r.ticket_reply_id) AS rid, MAX(r.ticket_reply_created_at) AS at
                FROM ticket_replies r JOIN tickets t ON t.ticket_id = r.ticket_reply_ticket_id
                WHERE t.ticket_contact_id = $contactId AND t.ticket_client_id = $clientId AND t.ticket_archived_at IS NULL
                  AND r.ticket_reply_type = 'Public' AND r.ticket_reply_archived_at IS NULL AND r.ticket_reply_created_at >= NOW() - INTERVAL $w DAY
                GROUP BY t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject ORDER BY at DESC LIMIT 20") as $r) {
            $items[] = $this->item('rep' . (int) $r['ticket_id'] . '-' . (int) $r['rid'], 'reply', 'New reply on #' . $r['ticket_prefix'] . (int) $r['ticket_number'], (string) $r['ticket_subject'],
                'ticket.php?id=' . (int) $r['ticket_id'], (string) $r['at'], false, $seen, 'info');
        }

        foreach ($this->rows("SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_closed_at AS at FROM tickets
                WHERE ticket_contact_id = $contactId AND ticket_client_id = $clientId AND ticket_archived_at IS NULL AND ticket_closed_at IS NOT NULL
                  AND ticket_closed_at >= NOW() - INTERVAL $w DAY ORDER BY ticket_closed_at DESC LIMIT 10") as $r) {
            $items[] = $this->item('cls' . (int) $r['ticket_id'], 'closed', 'Request #' . $r['ticket_prefix'] . (int) $r['ticket_number'] . ' was closed', (string) $r['ticket_subject'],
                'ticket.php?id=' . (int) $r['ticket_id'], (string) $r['at'], false, $seen, 'info');
        }

        foreach ((new EmployeeHome($this->db))->waitingOnMe($contactId, $clientId) as $a) {
            $items[] = $this->item('apr' . (int) $a['approval_id'], 'approval', 'Approval waiting: ' . $a['item_name'], 'From ' . (string) ($a['requester_name'] ?? 'a colleague'),
                'my_approvals.php', (string) $a['created_at'], true, $seen, 'important');
        }

        $check = (new EmployeeHome($this->db))->myChecklist($contactId, $clientId);
        if ($check) {
            $soon = $now + 3 * 86400;
            foreach ($check['tasks'] as $t) {
                if (in_array($t['status'], ['completed', 'skipped'], true) || empty($t['due_at']) || strtotime($t['due_at']) === false || strtotime($t['due_at']) > $soon) {
                    continue;
                }
                $overdue = strtotime($t['due_at']) < $now;
                $items[] = $this->item('tsk' . (int) $t['run_task_id'], 'task', ($overdue ? 'Overdue: ' : 'Due soon: ') . $t['title'], 'Due ' . substr((string) $t['due_at'], 0, 10),
                    'index.php#checklist', (string) $t['due_at'], true, $seen, $overdue ? 'urgent' : 'important');
            }
        }

        foreach ((new EmployeeHome($this->db))->myDeviceDetails($contactId, $clientId) as $d) {
            if ($d['warranty_state'] === 'expiring' || ($d['warranty_state'] === 'expired' && $d['warranty_days_left'] !== null && $d['warranty_days_left'] > -30)) {
                $left = (int) $d['warranty_days_left'];
                $items[] = $this->item('war' . (int) $d['id'], 'warranty', $d['name'] . ($left < 0 ? ': warranty ended' : ': warranty ends in ' . $left . ' day' . ($left === 1 ? '' : 's')),
                    'Warranty date ' . $d['warranty_expires'], 'assets.php', (string) $d['warranty_expires'] . ' 00:00:00', true, $seen, $left < 0 ? 'important' : 'info');
            }
        }

        usort($items, static fn($a, $b) => [$b['at'], $b['key']] <=> [$a['at'], $a['key']]);

        return array_slice($items, 0, $limit);
    }

    public function unreadCount(int $contactId, int $clientId): int
    {
        $n = 0;
        foreach ($this->items($contactId, $clientId, 100) as $i) {
            if ($i['unread']) {
                $n++;
            }
        }

        return $n;
    }

    /** Marks everything shown so far as read (sticky items stay unread until resolved). */
    public function markRead(int $contactId): void
    {
        if ($contactId <= 0) {
            return;
        }
        try {
            $this->db->query("INSERT INTO portal_feed_state SET feed_contact_id = $contactId, feed_seen_at = NOW() ON DUPLICATE KEY UPDATE feed_seen_at = NOW()");
        } catch (\Throwable $e) {
            // portal_feed_state not migrated yet (DB 2.6.217)
        }
    }

    public function seenAt(int $contactId): ?string
    {
        $r = $this->rows("SELECT feed_seen_at FROM portal_feed_state WHERE feed_contact_id = $contactId LIMIT 1");

        return $r[0]['feed_seen_at'] ?? null;
    }

    public static function excerpt(string $body, int $max = 140): string
    {
        $one = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return mb_strlen($one) > $max ? mb_substr($one, 0, $max - 1) . "\u{2026}" : $one;
    }

    /** @return array{key:string,type:string,title:string,detail:string,link:string,at:string,sticky:bool,unread:bool,severity:string} */
    private function item(string $key, string $type, string $title, string $detail, string $link, string $at, bool $sticky, ?string $seen, string $severity): array
    {
        $unread = $sticky || $seen === null || strtotime($at) > strtotime($seen);

        return ['key' => $key, 'type' => $type, 'title' => $title, 'detail' => $detail, 'link' => $link, 'at' => $at, 'sticky' => $sticky, 'unread' => $unread, 'severity' => $severity];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $out = [];
        try {
            $res = $this->db->query($sql);
            while ($res && ($r = $res->fetch_assoc())) {
                $out[] = $r;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }
}
