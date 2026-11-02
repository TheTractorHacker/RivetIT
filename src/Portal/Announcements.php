<?php

namespace ITFlow\Portal;

/**
 * Employee portal announcements (DB 2.6.221). A row is shown to every department (announcement_client_id = 0) or to one department.
 * Reads for the portal take the signed-in contact's department from the session, never from the request. The body is plain text:
 * it is escaped on output and never treated as HTML, so an announcement cannot carry script.
 */
class Announcements
{
    public const SEVERITIES = ['info' => 'Information', 'important' => 'Important', 'urgent' => 'Urgent'];

    public const TITLE_MAX = 200;
    public const BODY_MAX = 4000;

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * Pure validation of posted admin input. $departmentIds is the list of department (client) ids that exist.
     *
     * @param array<string,mixed> $in
     * @param list<int> $departmentIds
     * @return array{ok: bool, errors: list<string>, clean: array<string,mixed>}
     */
    public static function validate(array $in, array $departmentIds): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($title === '') {
            $errors[] = 'A title is required.';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors[] = 'The title is too long (' . self::TITLE_MAX . ' characters at most).';
        }
        if ($body === '') {
            $errors[] = 'Some text is required.';
        } elseif (mb_strlen($body) > self::BODY_MAX) {
            $errors[] = 'The text is too long (' . self::BODY_MAX . ' characters at most).';
        }
        $severity = (string) ($in['severity'] ?? 'info');
        if (!isset(self::SEVERITIES[$severity])) {
            $errors[] = 'Choose a valid importance.';
            $severity = 'info';
        }
        $clientId = intval($in['client_id'] ?? 0);
        if ($clientId !== 0 && !in_array($clientId, $departmentIds, true)) {
            $errors[] = 'Choose All departments or an existing department.';
            $clientId = 0;
        }
        $publish = self::parseDateTime($in['publish_at'] ?? '');
        if ($publish === false) {
            $errors[] = 'The publish date is not a valid date and time.';
        }
        $expires = self::parseDateTime($in['expires_at'] ?? '');
        if ($expires === false) {
            $errors[] = 'The expiry date is not a valid date and time.';
        }
        if (is_string($publish) && is_string($expires) && $expires <= $publish) {
            $errors[] = 'The expiry must be after the publish time.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'clean' => [
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
                'client_id' => $clientId,
                'pinned' => !empty($in['pinned']) ? 1 : 0,
                'publish_at' => is_string($publish) ? $publish : null,   // null = now
                'expires_at' => is_string($expires) ? $expires : null,
            ],
        ];
    }

    /** '' / null becomes null (not set); a real date/datetime becomes 'Y-m-d H:i:s'; anything else is false. @return string|null|false */
    public static function parseDateTime(mixed $v): string|null|false
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)) {
            return false;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }
        $h = (int) ($m[4] ?? 0);
        $i = (int) ($m[5] ?? 0);
        $s = (int) ($m[6] ?? 0);
        if ($h > 23 || $i > 59 || $s > 59) {
            return false;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $h, $i, $s);
    }

    /** @param array<string,mixed> $clean output of validate() */
    public function create(array $clean, int $userId): int
    {
        $publish = $clean['publish_at'] === null ? 'NOW()' : "'" . $this->esc($clean['publish_at']) . "'";
        $expires = $clean['expires_at'] === null ? 'NULL' : "'" . $this->esc($clean['expires_at']) . "'";
        $this->db->query("INSERT INTO portal_announcements SET
            announcement_title = '" . $this->esc($clean['title']) . "', announcement_body = '" . $this->esc($clean['body']) . "',
            announcement_severity = '" . $this->esc($clean['severity']) . "', announcement_client_id = " . intval($clean['client_id']) . ",
            announcement_pinned = " . intval($clean['pinned']) . ", announcement_publish_at = $publish, announcement_expires_at = $expires,
            announcement_created_by = " . intval($userId));

        return (int) $this->db->insert_id;
    }

    /** @param array<string,mixed> $clean output of validate() */
    public function update(int $id, array $clean): bool
    {
        $publish = $clean['publish_at'] === null ? 'NOW()' : "'" . $this->esc($clean['publish_at']) . "'";
        $expires = $clean['expires_at'] === null ? 'NULL' : "'" . $this->esc($clean['expires_at']) . "'";
        $this->db->query("UPDATE portal_announcements SET
            announcement_title = '" . $this->esc($clean['title']) . "', announcement_body = '" . $this->esc($clean['body']) . "',
            announcement_severity = '" . $this->esc($clean['severity']) . "', announcement_client_id = " . intval($clean['client_id']) . ",
            announcement_pinned = " . intval($clean['pinned']) . ", announcement_publish_at = $publish, announcement_expires_at = $expires
            WHERE announcement_id = " . intval($id) . " AND announcement_archived_at IS NULL");

        return $this->db->affected_rows >= 0 && $this->find($id) !== null;
    }

    public function archive(int $id): void
    {
        $this->db->query("UPDATE portal_announcements SET announcement_archived_at = NOW() WHERE announcement_id = " . intval($id) . " AND announcement_archived_at IS NULL");
    }

    /** @return array<string,mixed>|null an unarchived announcement */
    public function find(int $id): ?array
    {
        $r = $this->rows("SELECT * FROM portal_announcements WHERE announcement_id = " . intval($id) . " AND announcement_archived_at IS NULL LIMIT 1");

        return $r[0] ?? null;
    }

    /**
     * What one department's employee sees right now: published, not expired, not archived, for All departments or their own department.
     * Pinned first, then more important, then newest. A department id of 0 (an admin preview) sees the all-department ones only.
     *
     * @return list<array<string,mixed>>
     */
    public function visibleFor(int $clientId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        return $this->rows("SELECT announcement_id, announcement_title, announcement_body, announcement_severity, announcement_client_id,
                announcement_pinned, announcement_publish_at, announcement_expires_at
            FROM portal_announcements
            WHERE announcement_archived_at IS NULL AND announcement_publish_at <= NOW()
              AND (announcement_expires_at IS NULL OR announcement_expires_at > NOW())
              AND announcement_client_id IN (0, " . max(0, intval($clientId)) . ")
            ORDER BY announcement_pinned DESC,
                     FIELD(announcement_severity, 'urgent', 'important', 'info') ASC,
                     announcement_publish_at DESC, announcement_id DESC
            LIMIT $limit");
    }

    /** Everything an administrator manages (unarchived), newest first, with the department name and a live/scheduled/expired state. @return list<array<string,mixed>> */
    public function adminList(): array
    {
        return $this->rows("SELECT a.*, c.client_name,
                CASE WHEN a.announcement_publish_at > NOW() THEN 'scheduled'
                     WHEN a.announcement_expires_at IS NOT NULL AND a.announcement_expires_at <= NOW() THEN 'expired'
                     ELSE 'live' END AS announcement_state
            FROM portal_announcements a LEFT JOIN clients c ON c.client_id = a.announcement_client_id AND a.announcement_client_id > 0
            WHERE a.announcement_archived_at IS NULL
            ORDER BY a.announcement_publish_at DESC, a.announcement_id DESC LIMIT 500");
    }

    private function esc(string $s): string
    {
        return $this->db->real_escape_string($s);
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
            return []; // table not migrated yet (DB 2.6.221): nothing to show, never a broken page
        }

        return $out;
    }
}
