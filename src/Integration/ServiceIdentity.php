<?php

namespace ITFlow\Integration;

/**
 * The identity integration clients act as on the general REST API.
 *
 * Legacy API keys ran as "the first administrator", so every key carried a whole administrator's privileges regardless of what its
 * creator meant it to do. An integration client never does: it acts as one fixed, DISABLED, role-less service user ("API integration
 * service"). That user
 *   - cannot sign in (user_status = 0, an unusable random password hash, no role) and cannot own a user token (api_tokens requires an
 *     enabled agent);
 *   - holds no role, so every role check that looks at it denies; the only authority an integration client has is its SCOPES and its
 *     department scope, which api/v1/index.php and api_permissions.php enforce;
 *   - is what created_by / assigned-by columns and the audit trail record for machine writes. Which client did it is in the log text
 *     ("Integration client <name>") and in the logs row's entity (the integration client id) written for every legacy-header request;
 *     the user row itself is shared by all clients.
 */
final class ServiceIdentity
{
    public const EMAIL = 'api-integration-service@rivetit.invalid';
    public const NAME = 'API integration service';

    /** The service user's id; creates it on first use (idempotent). 0 when the users table cannot be written. */
    public static function userId(\mysqli $db): int
    {
        $st = $db->prepare('SELECT user_id FROM users WHERE user_email = ? LIMIT 1');
        $email = self::EMAIL;
        $st->bind_param('s', $email);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return (int) $row['user_id'];
        }
        $name = self::NAME;
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);   // nobody knows it; the account is disabled anyway
        $st = $db->prepare("INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status, user_role_id) VALUES (?, ?, ?, 'local', 1, 0, 0)");
        $st->bind_param('sss', $name, $email, $hash);
        try {
            $st->execute();
            $id = (int) $db->insert_id;
        } catch (\Throwable $e) {
            $id = 0;
        }
        $st->close();
        if ($id > 0) {
            return $id;
        }
        // A concurrent first request may have inserted it.
        $st = $db->prepare('SELECT user_id FROM users WHERE user_email = ? LIMIT 1');
        $st->bind_param('s', $email);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int) ($row['user_id'] ?? 0);
    }
}
