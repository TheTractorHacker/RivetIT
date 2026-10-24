<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;

/**
 * RivetPanel's ticket storage over RivetIT's own schema (tickets, ticket_replies, categories, contacts). Core has already decided WHO may do
 * WHAT (device, client, contact, ownership, limits, text hygiene) before calling any method here; this adapter only stores and fetches, and
 * never widens access on its own (every read is still scoped by client/contact/owned-ids in SQL, matching what Core will re-check).
 *
 * Conventions reused from the rest of the app rather than invented here:
 *   - a ticket is closed exactly when `ticket_closed_at` is not null (the same check agent/ticket.php and agent/tickets.php use throughout);
 *   - `ticket_category` stores a `categories.category_id` as a string in a varchar column (agent/tickets.php, agent/ticket.php);
 *   - a `ticket_replies` row of type 'Client' with `ticket_reply_by` = a contact_id is a requester-authored reply (client/post.php); a client-level
 *     panel device (no contact) uses `ticket_reply_by = 0`, the same "no specific contact" value the lazy "Initial Issue" backfill in
 *     agent/ticket.php already falls back to;
 *   - attachments live in `ticket_attachments` (ticket_attachment_name = the display name, ticket_attachment_reference_name = the random name
 *     actually stored on disk under uploads/tickets/{ticket_id}/), the same split agent/post/ticket.php's upload handler uses. Content type and
 *     size are not columns of that table anywhere in the app; they are derived here from the stored extension and `filesize()`.
 *
 * Judgment calls (no existing convention covered these):
 *   - RivetIT's `categories` table has no client scoping at all (ticket categories are a single global list); `categories($clientId)` therefore
 *     validates the client exists and, if so, returns every active Ticket category - there is no narrower "this client's own" set to return.
 *   - The opening message of a panel-created ticket is stored as a REAL `ticket_replies` row (type 'Client', pointed to by the existing
 *     `tickets.ticket_initial_issue_reply_id` column) rather than a synthetic id, so messages() has one real, stable, monotonically increasing
 *     id space to page through - exactly the backfill agent/ticket.php already does lazily for staff-typed tickets, just done eagerly here.
 *   - A client-level device's unverified `requesterHint` (e.g. an OS user name) has no column of its own; when present it is recorded as a
 *     leading "[reported as: ...]" line in the stored reply (visible to staff in the normal ticket view) and stripped back off before the text
 *     is returned to the panel itself, so the device only ever sees the body it sent.
 *   - `tickets`/`ticket_replies` timestamps are written with MySQL's local `NOW()` (this server's system time zone, confirmed non-UTC), but the
 *     interface requires UTC; every timestamp this class returns is shifted by an offset measured once per instance
 *     (`TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())`). The stored string is parsed as a NAIVE value (DateTime with an explicit UTC
 *     DateTimeZone, never `strtotime()`) before that offset is applied - `strtotime()` follows PHP's own `date.timezone` ini setting, which
 *     on this install equals MySQL's local zone, so it would already return the correct absolute instant and the offset would double-shift
 *     it (found as a 5-hour-off conformance-test failure once PHP's `date.timezone` matched the server's zone). The offset can still be off by
 *     an hour for a row written on the opposite side of a DST transition from "now" - an accepted, minor limitation given RivetIT stores no
 *     explicit UTC ticket timestamps to read instead.
 */
final class EndpointPanelGateway implements PanelTicketGatewayInterface
{
    private const VISIBLE_REPLY_TYPES = ['Public', 'Client'];

    /** @var array<string,string> extension (lowercase, no dot) => content type, for the attachment metadata listing only (no download endpoint exists) */
    private const CONTENT_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'log' => 'text/plain', 'csv' => 'text/csv',
    ];

    private ?int $utcOffsetS = null;

    public function __construct(private readonly \mysqli $mysqli)
    {
    }

    public function categories(int $clientId): array
    {
        if ($clientId <= 0 || $this->one('SELECT client_id FROM clients WHERE client_id = ?', [$clientId]) === null) {
            return [];
        }
        $rows = $this->all(
            "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_order ASC, category_name ASC LIMIT 500"
        );

        return array_map(static fn (array $r): array => ['category_id' => (int) $r['category_id'], 'name' => (string) $r['category_name']], $rows);
    }

    public function contact(int $contactId, int $clientId): ?array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return null;
        }
        $row = $this->one('SELECT contact_id, contact_name FROM contacts WHERE contact_id = ? AND contact_client_id = ? AND contact_archived_at IS NULL', [$contactId, $clientId]);

        return $row === null ? null : ['contact_id' => (int) $row['contact_id'], 'name' => (string) $row['contact_name']];
    }

    public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int
    {
        return $this->transaction(function () use ($clientId, $contactId, $subject, $body, $categoryId, $requesterHint, $attachments): int {
            $settings = $this->one('SELECT config_ticket_prefix, config_ticket_default_technician_id, config_ticket_default_status_id FROM settings WHERE company_id = 1') ?? [];
            $prefix = (string) ($settings['config_ticket_prefix'] ?? '');
            $assignedTo = (int) ($settings['config_ticket_default_technician_id'] ?? 0);
            $statusId = (int) ($settings['config_ticket_default_status_id'] ?? 0);
            if ($statusId <= 0) {
                $statusId = $this->defaultStatusId();
            }
            $this->run('UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1', []);
            $ticketNumber = (int) $this->mysqli->insert_id;
            $urlKey = bin2hex(random_bytes(16));

            $ticketId = $this->insert(
                'INSERT INTO tickets SET ticket_prefix = ?, ticket_number = ?, ticket_source = ?, ticket_category = ?, ticket_subject = ?, ticket_details = ?,
                    ticket_priority = ?, ticket_status = ?, ticket_created_by = 0, ticket_contact_id = ?, ticket_url_key = ?, ticket_client_id = ?, ticket_assigned_to = ?',
                [$prefix, $ticketNumber, 'Panel', $categoryId !== null ? (string) $categoryId : null, $subject, $body, 'Medium', $statusId, $contactId ?? 0, $urlKey, $clientId, $assignedTo]
            );

            $replyId = $this->insertVisibleReply($ticketId, $contactId, $body, $requesterHint);
            $this->run('UPDATE tickets SET ticket_initial_issue_reply_id = ? WHERE ticket_id = ?', [$replyId, $ticketId]);
            $this->storeAttachments($ticketId, $replyId, $attachments);

            return $ticketId;
        });
    }

    public function listTickets(int $clientId, ?int $contactId, array $ownedTicketIds, int $limit, int $offset): array
    {
        $ownedTicketIds = array_values(array_unique(array_map('intval', $ownedTicketIds)));
        $conds = [];
        $params = [];
        if ($contactId !== null && $contactId > 0) {
            $conds[] = 't.ticket_contact_id = ?';
            $params[] = $contactId;
        }
        if ($ownedTicketIds !== []) {
            $in = implode(',', array_fill(0, count($ownedTicketIds), '?'));
            $conds[] = "t.ticket_id IN ($in)";
            array_push($params, ...$ownedTicketIds);
        }
        if ($conds === [] || $clientId <= 0) {
            return [];
        }
        $params = array_merge([$clientId], $params, [max(0, $limit), max(0, $offset)]);
        $rows = $this->all(
            'SELECT t.ticket_id, t.ticket_client_id, t.ticket_contact_id, t.ticket_subject, t.ticket_category, t.ticket_closed_at, t.ticket_created_at, t.ticket_updated_at, s.ticket_status_name
             FROM tickets t LEFT JOIN ticket_statuses s ON t.ticket_status = s.ticket_status_id
             WHERE t.ticket_client_id = ? AND (' . implode(' OR ', $conds) . ')
             ORDER BY COALESCE(t.ticket_updated_at, t.ticket_created_at) DESC, t.ticket_id DESC
             LIMIT ? OFFSET ?',
            $params
        );

        return array_map(fn (array $r): array => $this->mapTicketRow($r), $rows);
    }

    public function ticket(int $ticketId): ?array
    {
        if ($ticketId <= 0) {
            return null;
        }
        $row = $this->one(
            'SELECT t.ticket_id, t.ticket_client_id, t.ticket_contact_id, t.ticket_subject, t.ticket_category, t.ticket_closed_at, t.ticket_created_at, t.ticket_updated_at, s.ticket_status_name
             FROM tickets t LEFT JOIN ticket_statuses s ON t.ticket_status = s.ticket_status_id WHERE t.ticket_id = ?',
            [$ticketId]
        );

        return $row === null ? null : $this->mapTicketRow($row);
    }

    public function messages(int $ticketId, int $afterMessageId, int $limit): array
    {
        if ($ticketId <= 0) {
            return [];
        }
        $types = implode(',', array_fill(0, count(self::VISIBLE_REPLY_TYPES), '?'));
        $rows = $this->all(
            "SELECT tr.ticket_reply_id, tr.ticket_reply, tr.ticket_reply_type, tr.ticket_reply_by, tr.ticket_reply_created_at,
                    c.contact_name, u.user_name, cl.client_name
             FROM ticket_replies tr
             JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
             LEFT JOIN contacts c ON tr.ticket_reply_by = c.contact_id AND tr.ticket_reply_type = 'Client'
             LEFT JOIN users u ON tr.ticket_reply_by = u.user_id AND tr.ticket_reply_type = 'Public'
             LEFT JOIN clients cl ON cl.client_id = t.ticket_client_id
             WHERE tr.ticket_reply_ticket_id = ? AND tr.ticket_reply_archived_at IS NULL AND tr.ticket_reply_type IN ($types) AND tr.ticket_reply_id > ?
             ORDER BY tr.ticket_reply_id ASC
             LIMIT ?",
            array_merge([$ticketId], self::VISIBLE_REPLY_TYPES, [$afterMessageId, max(0, $limit)])
        );

        return array_map(fn (array $r): array => $this->mapMessageRow($ticketId, $r), $rows);
    }

    public function postMessage(int $ticketId, int $clientId, ?int $contactId, string $body, string $requesterHint, array $attachments): int
    {
        return $this->transaction(function () use ($ticketId, $contactId, $body, $requesterHint, $attachments): int {
            $replyId = $this->insertVisibleReply($ticketId, $contactId, $body, $requesterHint);
            $this->storeAttachments($ticketId, $replyId, $attachments);
            $this->run('UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = ?', [$ticketId]);

            return $replyId;
        });
    }

    // ------------------------------------------------------------------ helpers

    private const HINT_RE = '/^\[reported as: (.{1,100}?)\]\n\n/s';

    private function insertVisibleReply(int $ticketId, ?int $contactId, string $body, string $requesterHint): int
    {
        $by = $contactId ?? 0;
        $stored = $body;
        if ($contactId === null && trim($requesterHint) !== '') {
            $stored = '[reported as: ' . str_replace([']', "\n"], ' ', mb_substr(trim($requesterHint), 0, 100)) . "]\n\n" . $body;
        }

        return $this->insert('INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = ?, ticket_reply_by = ?, ticket_reply_ticket_id = ?',
            [$stored, 'Client', $by, $ticketId]);
    }

    /** @return array{ticket_id:int,client_id:int,contact_id:?int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string} */
    private function mapTicketRow(array $row): array
    {
        $contactId = (int) $row['ticket_contact_id'];
        $created = $this->toUtc((string) $row['ticket_created_at']) ?? '';
        $updated = $this->toUtc($row['ticket_updated_at'] !== null ? (string) $row['ticket_updated_at'] : null) ?? $created;

        return [
            'ticket_id' => (int) $row['ticket_id'],
            'client_id' => (int) $row['ticket_client_id'],
            'contact_id' => $contactId > 0 ? $contactId : null,
            'subject' => (string) $row['ticket_subject'],
            'status' => (string) ($row['ticket_status_name'] ?? ''),
            'closed' => $row['ticket_closed_at'] !== null,
            'category_id' => $this->categoryIdOf($row['ticket_category'] ?? null),
            'created_at' => $created,
            'updated_at' => $updated,
        ];
    }

    /** @return array{message_id:int,author:string,author_name:string,body:string,internal:bool,created_at:string,attachments:list<array{filename:string,content_type:string,size:int}>} */
    private function mapMessageRow(int $ticketId, array $row): array
    {
        $type = (string) $row['ticket_reply_type'];
        $isStaff = $type === 'Public';
        $body = (string) $row['ticket_reply'];
        if (!$isStaff && preg_match(self::HINT_RE, $body) === 1) {
            $body = (string) preg_replace(self::HINT_RE, '', $body, 1);
        }
        $authorName = $isStaff
            ? (string) ($row['user_name'] ?? '')
            : (string) ($row['contact_name'] ?? $row['client_name'] ?? '');

        return [
            'message_id' => (int) $row['ticket_reply_id'],
            'author' => $isStaff ? 'staff' : 'requester',
            'author_name' => $authorName,
            'body' => $body,
            'internal' => false,
            'created_at' => $this->toUtc((string) $row['ticket_reply_created_at']) ?? '',
            'attachments' => $this->attachmentsOf($ticketId, (int) $row['ticket_reply_id']),
        ];
    }

    /** @return list<array{filename:string,content_type:string,size:int}> */
    private function attachmentsOf(int $ticketId, int $replyId): array
    {
        $rows = $this->all('SELECT ticket_attachment_name, ticket_attachment_reference_name FROM ticket_attachments WHERE ticket_attachment_reply_id = ? AND ticket_attachment_ticket_id = ?', [$replyId, $ticketId]);

        return array_map(function (array $r) use ($ticketId): array {
            $ref = (string) $r['ticket_attachment_reference_name'];
            $size = @filesize($this->ticketUploadDir($ticketId) . '/' . $ref);

            return ['filename' => (string) $r['ticket_attachment_name'], 'content_type' => $this->contentTypeOf($ref), 'size' => $size !== false ? (int) $size : 0];
        }, $rows);
    }

    /** @param list<array{filename:string,content_type:string,bytes:string}> $attachments */
    private function storeAttachments(int $ticketId, int $replyId, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }
        $dir = $this->ticketUploadDir($ticketId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach ($attachments as $a) {
            $filename = (string) ($a['filename'] ?? '');
            $bytes = (string) ($a['bytes'] ?? '');
            // Core (PanelAttachments) has already checked this filename's extension against its own allow-list,
            // the magic bytes and executable/archive content before this method is ever called (the interface's
            // own docblock: "$attachments already validated by Core") - but this adapter is the last line before
            // bytes land on disk under a name a technician later opens, so it re-checks against the SAME safe
            // extensions self::CONTENT_TYPES already names, rather than trusting any extension the caller passed
            // through. An extension outside that list (whatever Core's own check missed or a future caller skips)
            // is stored as .bin, same as no extension at all.
            $ext = 'bin';
            if (preg_match('/\.([A-Za-z0-9]{1,10})$/', $filename, $m) === 1 && isset(self::CONTENT_TYPES[strtolower($m[1])])) {
                $ext = strtolower($m[1]);
            }
            $ref = bin2hex(random_bytes(16)) . '.' . $ext;
            file_put_contents($dir . '/' . $ref, $bytes);
            $this->insert('INSERT INTO ticket_attachments SET ticket_attachment_name = ?, ticket_attachment_reference_name = ?, ticket_attachment_ticket_id = ?, ticket_attachment_reply_id = ?',
                [mb_substr($filename, 0, 255), $ref, $ticketId, $replyId]);
        }
    }

    private function ticketUploadDir(int $ticketId): string
    {
        // Test-only escape hatch (unset in production): lets the conformance kit write attachments under a throwaway
        // directory instead of the live uploads/ tree, which is www-data-owned and keyed by the same ticket_id space
        // production tickets use - the same spirit as EA_ALLOW_INSECURE_HTTP's loopback-only override in rmm_bootstrap.php.
        $override = getenv('RIVETIT_PANEL_TEST_UPLOADS_DIR');
        $root = (is_string($override) && $override !== '') ? $override : dirname(__DIR__, 4) . '/uploads';

        return $root . "/tickets/$ticketId";
    }

    private function contentTypeOf(string $referenceName): string
    {
        $ext = strtolower((string) pathinfo($referenceName, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$ext] ?? 'application/octet-stream';
    }

    private function categoryIdOf(mixed $raw): ?int
    {
        $v = $raw !== null ? trim((string) $raw) : '';

        return ($v !== '' && $v !== '0' && ctype_digit($v)) ? (int) $v : null;
    }

    /** Resolve a starting status by name ('New' then 'Open'), then the first active status by display order - ids are per-install, never hard-coded. */
    private function defaultStatusId(): int
    {
        foreach (['New', 'Open'] as $name) {
            $row = $this->one('SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = ? AND ticket_status_active = 1 ORDER BY ticket_status_id ASC LIMIT 1', [$name]);
            if ($row !== null) {
                return (int) $row['ticket_status_id'];
            }
        }
        $row = $this->one('SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order ASC, ticket_status_id ASC LIMIT 1');

        return $row !== null ? (int) $row['ticket_status_id'] : 0;
    }

    private function utcOffsetSeconds(): int
    {
        if ($this->utcOffsetS === null) {
            $row = $this->one('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS d');
            $this->utcOffsetS = (int) ($row['d'] ?? 0);
        }

        return $this->utcOffsetS;
    }

    private function toUtc(?string $local): ?string
    {
        if ($local === null || $local === '' || $local === '0000-00-00 00:00:00') {
            return null;
        }
        // Parsed as a naive value (explicit UTC DateTimeZone, not PHP's own date.timezone) so the offset below is applied exactly once.
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $local, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $dt->getTimestamp() - $this->utcOffsetSeconds());
    }

    // ------------------------------------------------------------------ tiny mysqli helpers (no DatabaseInterface dependency: this class is handed a raw connection)

    private function transaction(\Closure $fn): mixed
    {
        $this->mysqli->begin_transaction();
        try {
            $v = $fn();
            $this->mysqli->commit();

            return $v;
        } catch (\Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    private function stmt(string $sql, array $params): \mysqli_stmt
    {
        $st = $this->mysqli->prepare($sql);
        if ($st === false) {
            throw new \RuntimeException('Prepare failed: ' . $this->mysqli->error);
        }
        if ($params !== []) {
            $types = '';
            $vals = [];
            foreach ($params as $v) {
                if (is_int($v) || is_bool($v)) {
                    $types .= 'i';
                    $vals[] = (int) $v;
                } elseif (is_float($v)) {
                    $types .= 'd';
                    $vals[] = $v;
                } else {
                    $types .= 's';
                    $vals[] = $v === null ? null : (string) $v;
                }
            }
            $st->bind_param($types, ...$vals);
        }
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new \RuntimeException('Execute failed: ' . $err);
        }

        return $st;
    }

    private function run(string $sql, array $params): int
    {
        $st = $this->stmt($sql, $params);
        $affected = $st->affected_rows;
        $st->close();

        return $affected;
    }

    private function insert(string $sql, array $params): int
    {
        $st = $this->stmt($sql, $params);
        $id = (int) $this->mysqli->insert_id;
        $st->close();

        return $id;
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $st = $this->stmt($sql, $params);
        $res = $st->get_result();
        $row = $res !== false ? $res->fetch_assoc() : null;
        $st->close();

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $st = $this->stmt($sql, $params);
        $res = $st->get_result();
        $rows = $res !== false ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();

        return $rows;
    }
}
