<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;

/**
 * Agent users for the "Responsible" pickers (course and lesson). Only active, non-archived
 * agent accounts (user_type 1) are offered or accepted; Phase 1 holds no contact data.
 */
final class UserDirectory
{
    /** @return list<array{id:int, name:string, email:string}> */
    public static function search(\mysqli $db, string $q, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $q = trim($q);
        if ($q === '') {
            $rows = Db::all($db, 'SELECT user_id, user_name, user_email FROM users
                WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name LIMIT ?', 'i', [$limit]);
        } else {
            $like = Patch::like($q);
            $rows = Db::all($db, 'SELECT user_id, user_name, user_email FROM users
                WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND (user_name LIKE ? OR user_email LIKE ?)
                ORDER BY user_name LIMIT ?', 'ssi', [$like, $like, $limit]);
        }
        return array_map(static fn($r) => ['id' => (int) $r['user_id'], 'name' => (string) $r['user_name'], 'email' => (string) $r['user_email']], $rows);
    }

    /** A responsible user id from a request: must be an active agent (422 otherwise). */
    public static function requireActive(\mysqli $db, int $userId, string $field = 'responsible_user_id'): array
    {
        $row = Db::one($db, 'SELECT user_id, user_name FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$userId]);
        if ($row === null) {
            throw ApiException::validation([$field => 'Choose an active user.']);
        }
        return $row;
    }

    /** @return array<int, string> user id => name */
    public static function names(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Db::all($db, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['user_id']] = (string) $r['user_name'];
        }
        return $out;
    }
}
