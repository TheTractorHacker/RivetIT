<?php

/*
 * Wave 1 security - credential vault list: reveal and copy on demand.
 *
 * agent/credentials.php no longer decrypts every row into the page. It draws a mask, and the eye / copy buttons ask
 * agent/credential_reveal.php for ONE field of ONE credential. Each call that returns a value:
 *   - writes an audit event (vault.credential_revealed, action "reveal" or "copy") and a Credential log line
 *   - counts against a per-user limit (security_settings.vault_reveal_limit per 10 minutes); past it the request is refused,
 *     a vault.reveal_rate_limited event is recorded and the administrators are notified
 *   - may need the password again (step-up): when the last password entry is older than security_settings.vault_stepup_minutes
 *     (default 15, 0 = never). Accounts without a local password (SSO) are exempt.
 *
 * vaultReveal() holds all of that and takes the session as an array, so tests can drive it without HTTP.
 */

if (!function_exists('vaultReveal')) {

    define('VAULT_REVEAL_WINDOW_SECONDS', 600);
    define('VAULT_STEPUP_MAX_FAILURES', 5);
    define('VAULT_STEPUP_LOCK_SECONDS', 300);

    /** Reveals recorded for the user in the last 10 minutes. */
    function vaultRevealCount(mysqli $mysqli, int $userId): int
    {
        $r = mysqli_fetch_row(mysqli_query(
            $mysqli,
            "SELECT COUNT(*) FROM audit_events WHERE event_type = 'vault.credential_revealed' AND actor_user_id = $userId AND created_at > (NOW() - INTERVAL " . VAULT_REVEAL_WINDOW_SECONDS . ' SECOND)'
        ));

        return (int) ($r[0] ?? 0);
    }

    /** Is a (re)entry of the password due before the next reveal? */
    function vaultStepUpDue(array $session, int $minutes, bool $hasLocalPassword, ?int $now = null): bool
    {
        if ($minutes <= 0 || !$hasLocalPassword) {
            return false;
        }
        $now ??= time();

        return ($now - (int) ($session['vault_stepup_at'] ?? 0)) > $minutes * 60;
    }

    /**
     * May this user reach this department's credentials? Mirrors enforceClientAccess(): administrators and global (department 0)
     * items always; otherwise a user with no department rows may reach all, and a user with rows only those departments.
     */
    function vaultUserCanAccessClient(mysqli $mysqli, int $userId, bool $isAdmin, int $clientId): bool
    {
        if ($isAdmin || $clientId === 0) {
            return true;
        }
        $rows = [];
        $res  = mysqli_query($mysqli, "SELECT client_id FROM user_client_permissions WHERE user_id = $userId");
        while ($res && ($r = mysqli_fetch_row($res))) {
            $rows[] = (int) $r[0];
        }

        return $rows === [] || in_array($clientId, $rows, true);
    }

    /** Tell the administrators that a user hit the reveal limit. Never throws. */
    function vaultNotifyRateLimited(mysqli $mysqli, int $userId, string $userName, int $limit): void
    {
        try {
            $msg = "$userName hit the credential reveal limit ($limit in 10 minutes) and was blocked.";
            $res = mysqli_query($mysqli, "SELECT u.user_id FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE r.role_is_admin = 1 AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL");
            while ($res && ($a = mysqli_fetch_assoc($res))) {
                if (function_exists('notifyUser')) {
                    notifyUser((int) $a['user_id'], 'Vault', $msg, '/admin/audit_events.php', 0, $userId);
                }
            }
        } catch (\Throwable $e) {
            error_log('vault rate-limit notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Reveal or copy one field of one credential.
     *
     * @param array $session  the session array ($_SESSION); vault_stepup_at / vault_stepup_fail* are read and written here
     * @param array $actor    ['id'=>int, 'name'=>string, 'is_admin'=>bool, 'perm'=>int module_credential level, 'has_password'=>bool]
     * @param array $req      ['credential_id'=>int, 'field'=>'username'|'password', 'mode'=>'reveal'|'copy', 'stepup_password'=>?string]
     * @return array{status:int, body:array} HTTP status and the JSON body
     */
    function vaultReveal(mysqli $mysqli, array &$session, array $actor, array $req, ?int $now = null): array
    {
        $now ??= time();
        $userId = (int) $actor['id'];
        $field  = (string) ($req['field'] ?? '');
        $mode   = (string) ($req['mode'] ?? 'reveal');
        $credId = (int) ($req['credential_id'] ?? 0);
        if (!in_array($field, ['username', 'password'], true) || !in_array($mode, ['reveal', 'copy'], true) || $credId <= 0) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'bad_request']];
        }
        if (empty($actor['is_admin']) && (int) ($actor['perm'] ?? 0) < 1) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'denied']];
        }

        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_id, credential_name, credential_client_id, credential_username, credential_password FROM credentials WHERE credential_id = $credId AND credential_archived_at IS NULL"));
        if (!$row) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not_found']];
        }
        $clientId = (int) $row['credential_client_id'];
        if (!vaultUserCanAccessClient($mysqli, $userId, !empty($actor['is_admin']), $clientId)) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'denied']];
        }

        // Step-up: the password again after N minutes without one
        $stepMinutes = secSettingInt('vault_stepup_minutes', $mysqli);
        if (vaultStepUpDue($session, $stepMinutes, !empty($actor['has_password']), $now)) {
            if ($now < (int) ($session['vault_stepup_locked_until'] ?? 0)) {
                return ['status' => 429, 'body' => ['ok' => false, 'error' => 'stepup_locked']];
            }
            $pw = (string) ($req['stepup_password'] ?? '');
            if ($pw === '') {
                return ['status' => 401, 'body' => ['ok' => false, 'error' => 'stepup_required', 'stepup' => true]];
            }
            $own = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_password FROM users WHERE user_id = $userId"));
            if (!$own || !password_verify($pw, (string) $own['user_password'])) {
                $session['vault_stepup_fail'] = (int) ($session['vault_stepup_fail'] ?? 0) + 1;
                if ($session['vault_stepup_fail'] >= VAULT_STEPUP_MAX_FAILURES) {
                    $session['vault_stepup_locked_until'] = $now + VAULT_STEPUP_LOCK_SECONDS;
                    $session['vault_stepup_fail'] = 0;
                }
                if (function_exists('logAction')) {
                    logAction('Credential', 'Step-up Failed', $actor['name'] . ' entered a wrong password to reveal vault credentials', $clientId, $credId);
                }
                \ITFlow\Audit\AuditService::record('vault.stepup_failed', $userId, 'credential', $credId, 'failed', $actor['name'] . ' entered a wrong password for a vault reveal');

                return ['status' => 401, 'body' => ['ok' => false, 'error' => 'stepup_invalid', 'stepup' => true]];
            }
            $session['vault_stepup_at']   = $now;
            $session['vault_stepup_fail'] = 0;
        }

        // Rate limit
        $limit = secSettingInt('vault_reveal_limit', $mysqli);
        if (vaultRevealCount($mysqli, $userId) >= $limit) {
            $already = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM audit_events WHERE event_type = 'vault.reveal_rate_limited' AND actor_user_id = $userId AND created_at > (NOW() - INTERVAL " . VAULT_REVEAL_WINDOW_SECONDS . ' SECOND)'));
            if ((int) ($already[0] ?? 0) === 0) {
                \ITFlow\Audit\AuditService::record('vault.reveal_rate_limited', $userId, 'user', $userId, 'blocked', $actor['name'] . " exceeded $limit credential reveals in 10 minutes", ['limit' => $limit]);
                if (function_exists('logAction')) {
                    logAction('Credential', 'Reveal Limit', $actor['name'] . " exceeded $limit credential reveals in 10 minutes and was blocked", $clientId, $credId);
                }
                vaultNotifyRateLimited($mysqli, $userId, (string) $actor['name'], $limit);
            }

            return ['status' => 429, 'body' => ['ok' => false, 'error' => 'rate_limited']];
        }

        // Decrypt. A locked vault (no vault key in this session) is reported, never turned into an empty value.
        $stored = $field === 'username' ? $row['credential_username'] : $row['credential_password'];
        $plain  = decryptCredentialEntry($stored);
        if ($plain === null) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'vault_locked']];
        }
        if ($plain === false) {
            return ['status' => 409, 'body' => ['ok' => false, 'error' => 'decrypt_failed']];
        }

        \ITFlow\Audit\AuditService::record(
            'vault.credential_revealed',
            $userId,
            'credential',
            $credId,
            $mode,
            $actor['name'] . ' ' . ($mode === 'copy' ? 'copied' : 'revealed') . " the $field of credential " . $row['credential_name'],
            ['field' => $field, 'mode' => $mode, 'client_id' => $clientId, 'via' => 'list']
        );
        if (function_exists('logAction')) {
            logAction('Credential', $mode === 'copy' ? 'Copy' : 'Reveal', $actor['name'] . ' ' . ($mode === 'copy' ? 'copied' : 'revealed') . " the $field of credential " . $row['credential_name'], $clientId, $credId);
        }

        return ['status' => 200, 'body' => ['ok' => true, 'value' => (string) $plain, 'field' => $field]];
    }
}
