<?php

namespace ITFlow\EndpointAgent;

/**
 * The durable job queue.
 *
 * STATES: queued -> running -> succeeded | failed | timed_out | cancelled, plus expired (never started before expires_at).
 *
 * LOST ACKNOWLEDGEMENT RULES
 *  - Destructive jobs (every reboot, and any job flagged destructive) are offered AT MOST ONCE. If the result never arrives
 *    (offered but never reported running past the ack timeout, or running past its deadline) they become failed / result_lost.
 *    A late real result from the agent still replaces result_lost (the agent knows the truth), but nothing is ever re-sent.
 *  - Non-destructive jobs that were offered but never reported running may be re-offered with attempt + 1 after the ack timeout,
 *    up to job_max_attempts. A job that reported running is never re-offered: past its deadline it becomes timed_out.
 *  - Every offer is signed (Ed25519 over the canonical JSON of the job fields), and the signature covers the attempt.
 */
final class Jobs
{
    public const MAX_SCRIPT_BYTES = 102400;
    private const FINAL = ['succeeded', 'failed', 'timed_out', 'cancelled', 'expired'];
    private const REPORTABLE = ['running', 'succeeded', 'failed', 'timed_out', 'cancelled'];
    private const DEADLINE_GRACE_S = 60;

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    // ------------------------------------------------------------------ creation (RivetIT user, after Authz)

    /**
     * @param array<string,scalar|null> $params
     * @return array{ok:bool,error?:string,job_id?:string}
     */
    public static function create(array $dev, string $type, ?string $script, array $params, ?int $timeout, bool $destructive, int $userId): array
    {
        $cfg = Config::get();
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null || $dev['link_state'] === 'rejected') {
            return ['ok' => false, 'error' => 'This device is revoked or retired and cannot receive jobs.'];
        }
        if (!in_array($type, ['powershell', 'reboot', 'collect'], true)) {
            return ['ok' => false, 'error' => 'Unknown job type.'];
        }
        if ($type === 'powershell') {
            if ($script === null || trim($script) === '' || strlen($script) > self::MAX_SCRIPT_BYTES || strpos($script, "\0") !== false || !mb_check_encoding($script, 'UTF-8')) {
                return ['ok' => false, 'error' => 'A PowerShell job needs a script of at most ' . self::MAX_SCRIPT_BYTES . ' bytes.'];
            }
        } else {
            $script = null;
        }
        if ($type === 'reboot') {
            $destructive = true;
        }
        if ($type === 'reboot') {
            $delay = $params['delay_s'] ?? 30;
            if (!is_int($delay) || $delay < 5 || $delay > 3600) {
                return ['ok' => false, 'error' => 'A reboot delay (params.delay_s) must be 5 to 3600 seconds.'];
            }
            $params['delay_s'] = $delay;
        }
        if (count($params) > 20) {
            return ['ok' => false, 'error' => 'At most 20 parameters.'];
        }
        foreach ($params as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) || !(is_null($v) || is_bool($v) || is_int($v) || (is_string($v) && strlen($v) <= 1024))) {
                return ['ok' => false, 'error' => 'Parameter names must be identifiers and values short strings, whole numbers or booleans.'];
            }
        }
        $timeout = $timeout ?? (int) $cfg['job_default_timeout_s'];
        if ($timeout < 1 || $timeout > (int) $cfg['job_max_timeout_s']) {
            return ['ok' => false, 'error' => 'Timeout must be 1 to ' . (int) $cfg['job_max_timeout_s'] . ' seconds.'];
        }
        $id = self::uuid();
        $now = time();
        Db::run("INSERT INTO endpoint_agent_jobs (job_id, device_id, asset_id, client_id, type, script, params_json, timeout_s, max_output_bytes, destructive, run_as, state,
            attempt, issued_at, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SYSTEM', 'queued', 1, ?, ?, ?, ?)",
            [$id, $dev['device_id'], $dev['asset_id'] ?: null, (int) $dev['client_id'], $type, $script, $params ? json_encode($params) : '{}', $timeout,
                (int) $cfg['job_output_max_bytes'], $destructive ? 1 : 0, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + (int) $cfg['job_expiry_s']), $userId, gmdate('Y-m-d H:i:s', $now)]);
        return ['ok' => true, 'job_id' => $id];
    }

    public static function cancel(string $jobId, int $deviceId, int $userId): bool
    {
        // Scoped to the device the caller was authorized for: a job id from another department's device cannot be cancelled through this one.
        $n = Db::run("UPDATE endpoint_agent_jobs SET state = 'cancelled', reason = 'cancelled_by_user', finished_at = ? WHERE job_id = ? AND device_id = ? AND state = 'queued'", [Db::utcNow(), $jobId, $deviceId]);
        return $n > 0;
    }

    public static function cancelAllQueued(int $deviceId, string $reason): void
    {
        Db::run("UPDATE endpoint_agent_jobs SET state = 'cancelled', reason = ?, finished_at = ? WHERE device_id = ? AND state = 'queued'", [$reason, Db::utcNow(), $deviceId]);
    }

    // ------------------------------------------------------------------ device side

    public static function pendingCount(int $deviceId): int
    {
        self::sweep($deviceId);
        $cfg = Config::get();
        $ackBefore = gmdate('Y-m-d H:i:s', time() - (int) $cfg['job_ack_timeout_s']);
        return (int) Db::val("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id = ? AND state = 'queued' AND expires_at > ? AND
            (offered_count = 0 OR (destructive = 0 AND offered_count < ? AND last_offered_at <= ?))",
            [$deviceId, Db::utcNow(), (int) $cfg['job_max_attempts'], $ackBefore]);
    }

    /** @return list<array<string,mixed>> signed job objects for this device only */
    public static function offer(array $dev): array
    {
        global $mysqli;
        $cfg = Config::get();
        self::sweep((int) $dev['device_id']);
        [$sec] = Config::signingKey();
        $out = [];
        $mysqli->begin_transaction();
        try {
            $ackBefore = gmdate('Y-m-d H:i:s', time() - (int) $cfg['job_ack_timeout_s']);
            $rows = Db::all("SELECT * FROM endpoint_agent_jobs WHERE device_id = ? AND state = 'queued' AND expires_at > ? ORDER BY created_at, job_id LIMIT 5 FOR UPDATE",
                [$dev['device_id'], Db::utcNow()]);
            foreach ($rows as $j) {
                $attempt = (int) $j['attempt'];
                if ((int) $j['offered_count'] > 0) {
                    if ((int) $j['destructive'] === 1 || $j['last_offered_at'] > $ackBefore || (int) $j['offered_count'] >= (int) $cfg['job_max_attempts']) {
                        continue;   // never re-send a destructive job; give the agent time to acknowledge before re-sending a harmless one
                    }
                    $attempt++;
                }
                Db::run('UPDATE endpoint_agent_jobs SET attempt = ?, offered_count = offered_count + 1, last_offered_at = ? WHERE job_id = ?', [$attempt, Db::utcNow(), $j['job_id']]);
                $out[] = self::jobObject($j, $attempt, $sec);
            }
            $mysqli->commit();
        } catch (\Throwable $e) {
            @$mysqli->rollback();
            throw $e;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function jobObject(array $j, int $attempt, string $secretKey): array
    {
        $params = json_decode((string) ($j['params_json'] ?: '{}'));
        if (!($params instanceof \stdClass)) {
            $params = new \stdClass();
        }
        $obj = [
            'job_id' => $j['job_id'],
            'device_id' => (int) $j['device_id'],
            'attempt' => $attempt,
            'type' => $j['type'],
            'script' => $j['script'],
            'params' => $params,
            'timeout_s' => (int) $j['timeout_s'],
            'max_output_bytes' => (int) $j['max_output_bytes'],
            'issued_at' => Db::iso($j['issued_at']),
            'expires_at' => Db::iso($j['expires_at']),
        ];
        $obj['signature'] = Signer::sign(Signer::jobMessage($obj), $secretKey);
        return $obj;
    }

    /**
     * Record an agent's report.
     *
     * @throws ApiError 404 unknown job (for this device), 409 conflict, 422 invalid
     */
    public static function report(array $dev, array $b): void
    {
        global $mysqli;
        $cfg = Config::get();
        $jobId = $b['job_id'] ?? null;
        $attempt = $b['attempt'] ?? null;
        $state = $b['state'] ?? null;
        if (!is_string($jobId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $jobId) || !is_int($attempt) || $attempt < 1
            || !in_array($state, self::REPORTABLE, true)) {
            throw new ApiError(422, 'invalid', 'job_id, attempt and state are required');
        }
        $exit = $b['exit_code'] ?? null;
        if ($exit !== null && (!is_int($exit) || $exit < -2147483648 || $exit > 2147483647)) {
            throw new ApiError(422, 'invalid', 'exit_code must be an integer or null');
        }
        $output = $b['output'] ?? '';
        if (!is_string($output)) {
            throw new ApiError(422, 'invalid', 'output must be a string');
        }
        $started = self::optTime($b['started_at'] ?? null, 'started_at');
        $finished = self::optTime($b['finished_at'] ?? null, 'finished_at');

        $mysqli->begin_transaction();
        try {
            $j = Db::one('SELECT * FROM endpoint_agent_jobs WHERE job_id = ? AND device_id = ? FOR UPDATE', [$jobId, $dev['device_id']]);
            if (!$j) {
                throw new ApiError(404, 'not_found', 'Unknown job.');
            }
            if ($attempt > (int) $j['attempt']) {
                throw new ApiError(409, 'conflict', 'That attempt was never issued.');
            }
            $cur = $j['state'];
            $isFinal = $state !== 'running';
            $now = Db::utcNow();

            if (in_array($cur, self::FINAL, true)) {
                $late = $cur === 'failed' && $j['reason'] === 'result_lost' || $cur === 'timed_out' && $j['reason'] === 'no_result_by_deadline';
                if ($isFinal && $cur === $state) {
                    $mysqli->commit();
                    return;   // an idempotent replay of the result we already hold
                }
                if (!($isFinal && $late)) {
                    throw new ApiError(409, 'conflict', "Job is already $cur.");
                }
                // The agent finally reports what happened to a job whose result was lost: the real outcome wins.
            } elseif ($cur === 'queued' && strtotime($j['expires_at'] . ' UTC') <= time()) {
                Db::run("UPDATE endpoint_agent_jobs SET state = 'expired', reason = 'expired_before_run', finished_at = ? WHERE job_id = ?", [$now, $jobId]);
                $mysqli->commit();
                throw new ApiError(409, 'conflict', 'Job has expired.');
            }

            if ($state === 'running') {
                Db::run("UPDATE endpoint_agent_jobs SET state = 'running', started_at = COALESCE(started_at, ?), attempt = GREATEST(attempt, ?) WHERE job_id = ?",
                    [$started ?? $now, $attempt, $jobId]);
            } else {
                [$text, $truncated] = self::sanitizeOutput($output, min((int) $j['max_output_bytes'], (int) $cfg['job_output_max_bytes']));
                Db::run('UPDATE endpoint_agent_jobs SET state = ?, reason = ?, exit_code = ?, output = ?, output_truncated = ?, started_at = COALESCE(started_at, ?), finished_at = ? WHERE job_id = ?',
                    [$state, isset($late) && $late ? 'late_result' : null, $exit, $text, $truncated ? 1 : 0, $started ?? $now, $finished ?? $now, $jobId]);
            }
            $mysqli->commit();
        } catch (\Throwable $e) {
            @$mysqli->rollback();
            throw $e;
        }
    }

    private static function optTime($v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $v)) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        $ts = strtotime($v);
        if ($ts === false || $ts > time() + 300 || $ts < time() - 86400 * 30) {
            throw new ApiError(422, 'invalid', "$field is out of range");
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ housekeeping

    public static function sweep(?int $deviceId = null): void
    {
        $cfg = Config::get();
        $now = Db::utcNow();
        $ackBefore = gmdate('Y-m-d H:i:s', time() - (int) $cfg['job_ack_timeout_s']);
        $scope = $deviceId === null ? '' : ' AND device_id = ' . (int) $deviceId;
        Db::run("UPDATE endpoint_agent_jobs SET state = 'expired', reason = 'expired_before_run', finished_at = ? WHERE state = 'queued' AND expires_at <= ?$scope", [$now, $now]);
        // Destructive, offered, no running report: it may or may not have executed. Never retried.
        Db::run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'result_lost', finished_at = ? WHERE state = 'queued' AND destructive = 1 AND offered_count >= 1 AND last_offered_at <= ?$scope", [$now, $ackBefore]);
        Db::run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'never_started', finished_at = ? WHERE state = 'queued' AND destructive = 0 AND offered_count >= ? AND last_offered_at <= ?$scope",
            [$now, (int) $cfg['job_max_attempts'], $ackBefore]);
        // Running past its deadline plus grace with no result.
        $rows = Db::all("SELECT job_id, destructive, timeout_s, started_at FROM endpoint_agent_jobs WHERE state = 'running'$scope");
        foreach ($rows as $r) {
            if (strtotime($r['started_at'] . ' UTC') + (int) $r['timeout_s'] + self::DEADLINE_GRACE_S > time()) {
                continue;
            }
            if ((int) $r['destructive'] === 1) {
                Db::run("UPDATE endpoint_agent_jobs SET state = 'failed', reason = 'result_lost', finished_at = ? WHERE job_id = ? AND state = 'running'", [$now, $r['job_id']]);
            } else {
                Db::run("UPDATE endpoint_agent_jobs SET state = 'timed_out', reason = 'no_result_by_deadline', finished_at = ? WHERE job_id = ? AND state = 'running'", [$now, $r['job_id']]);
            }
        }
    }

    // ------------------------------------------------------------------ output handling

    /** @return array{0:string,1:bool} [redacted, size-capped text, truncated?] */
    public static function sanitizeOutput(string $out, int $cap): array
    {
        $cap = max(1024, $cap);
        // Bound the work first, then redact, then cut: redacting after cutting could leave half a secret behind.
        $out = substr($out, 0, $cap * 4);
        $out = (string) iconv('UTF-8', 'UTF-8//IGNORE', $out);
        $out = str_replace("\0", '', $out);
        $out = Redactor::redact($out);
        $truncated = false;
        if (strlen($out) > $cap) {
            $out = substr($out, 0, $cap);
            while ($out !== '' && !mb_check_encoding($out, 'UTF-8')) {
                $out = substr($out, 0, -1);
            }
            $truncated = true;
        }
        return [$out, $truncated];
    }
}
