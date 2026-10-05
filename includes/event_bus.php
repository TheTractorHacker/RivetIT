<?php

/*
 * Event bus: the one place an event (a ticket was created, a login failed, a backup finished...) fans out to everything that cares.
 *
 *   rivetEmitEvent('ticket.created', $data)
 *     1. Webhooks: one queued, signed, retried delivery per subscribed endpoint (RivetCore job queue + webhook dispatcher).
 *     2. Automation: every enabled event rule (Administration > Event rules) whose event and conditions match queues its action.
 *
 * Audit events feed in here too (the audit service's after-log hook), so every audit event type can be subscribed to or automated.
 * It never throws: an event failing to fan out must never break the action that caused it. If the job queue is unavailable the
 * webhook falls back to the older queue table, so nothing is lost during an update.
 */

/** Namespace of this edition's Core adapters (RivetIT or RivetMSP). */
function rivetCoreAdapterNs(): string
{
    return class_exists('\RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter') ? '\RivetMSP\Core\Adapter' : '\ITFlow\Core\Adapter';
}

function rivetCoreDb($mysqli)
{
    $class = rivetCoreAdapterNs() . '\Database\MysqliDatabaseAdapter';

    return new $class($mysqli);
}

/** RivetMSP can switch Core modules off one by one (settings flags); RivetIT always has them on. */
function rivetCoreModuleOn(string $flag): bool
{
    if (class_exists('\RivetMSP\Core\CoreBridge')) {
        return \RivetMSP\Core\CoreBridge::enabled($flag);
    }

    return true;
}

function rivetTableExists($mysqli, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $res = @mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");
        $cache[$table] = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $cache[$table];
}

function rivetJobsAvailable($mysqli): bool
{
    return class_exists(\RivetCore\Jobs\JobQueue::class) && rivetCoreModuleOn('core.jobs.enabled') && rivetTableExists($mysqli, 'integration_jobs');
}

/** Webhook header prefixes receivers already verify (kept per edition). @return list<string> */
function rivetWebhookHeaderPrefixes(): array
{
    return class_exists('\RivetMSP\Core\CoreBridge') ? ['X-RivetMSP', 'X-ITFlow'] : ['X-ITFlow', 'X-RivetIT'];
}

function rivetWebhookDispatcher($mysqli): \RivetCore\Webhooks\WebhookDispatcher
{
    $db = rivetCoreDb($mysqli);
    $subsClass = rivetCoreAdapterNs() . '\Webhooks\WebhooksTableSubscriptions';

    return new \RivetCore\Webhooks\WebhookDispatcher($db, new $subsClass($db), new \RivetCore\Support\SystemClock(), rivetWebhookHeaderPrefixes());
}

/**
 * @param array<string,mixed> $data the event's data (the same array a webhook receives under "data")
 */
function rivetEmitEvent(string $event, array $data): void
{
    global $mysqli;
    if (!($mysqli instanceof \mysqli) || !preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
        return;
    }
    $queued = false;
    try {
        $emittedAt = gmdate('Y-m-d\TH:i:s\Z');
        $jobs = rivetJobsAvailable($mysqli) && class_exists(\RivetCore\Webhooks\WebhookDispatcher::class) && rivetTableExists($mysqli, 'webhook_deliveries')
            ? new \RivetCore\Jobs\JobQueue(rivetCoreDb($mysqli))
            : null;

        // 1. Webhooks
        $event_safe = mysqli_real_escape_string($mysqli, $event);
        $sql = mysqli_query($mysqli,
            "SELECT webhook_id FROM webhooks
             WHERE webhook_enabled = 1
               AND FIND_IN_SET('$event_safe', REPLACE(webhook_events, ', ', ','))"
        );
        while ($sql && ($row = mysqli_fetch_assoc($sql))) {
            $wid = intval($row['webhook_id']);
            if ($jobs !== null) {
                $jobs->enqueue('webhook.deliver', ['webhook_id' => $wid, 'event' => $event, 'data' => $data, 'emitted_at' => $emittedAt], null, 'webhook', 0, 5);
                $queued = true;
            } else {
                $payload = json_encode(['event' => $event, 'timestamp' => $emittedAt, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                mysqli_query($mysqli, "INSERT INTO webhook_queue SET queue_webhook_id = $wid, queue_event = '$event_safe', queue_payload = '" . mysqli_real_escape_string($mysqli, $payload) . "'");
            }
        }

        // 2. Event automation rules
        if (class_exists(\RivetCore\Automation\AutomationRuleEvaluator::class) && rivetCoreModuleOn('core.automation.enabled') && rivetTableExists($mysqli, 'automation_rules')) {
            $context = \RivetCore\Automation\EventContext::flatten($data + ['event' => $event]);
            $evaluator = new \RivetCore\Automation\AutomationRuleEvaluator(rivetCoreDb($mysqli));
            foreach ($evaluator->findMatchingRules($event, $context) as $rule) {
                if ($jobs !== null) {
                    $jobs->enqueue('automation.action', ['rule_id' => (int) $rule['rule_id'], 'event' => $event, 'context' => $context], null, 'automation', 0, 3);
                    $queued = true;
                } else {
                    rivetRunAutomationRule($mysqli, (int) $rule['rule_id'], $event, $context);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('rivetEmitEvent(' . $event . ') skipped: ' . $e->getMessage());
    }

    if ($queued) {
        rivetProcessJobsAfterResponse();
    }
}

/** Deliver what this request queued right after the response is sent, so webhooks go out in seconds, not at the next cron tick. */
function rivetProcessJobsAfterResponse(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () {
        global $mysqli;
        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if ($mysqli instanceof \mysqli) {
                rivetRunJobWorker($mysqli, 10, 8);
            }
        } catch (\Throwable $e) {
            error_log('post-response job run skipped: ' . $e->getMessage());
        }
    });
}

/** Run due jobs (the cron worker and the post-response pass both use this). @return array<string,int> */
function rivetRunJobWorker($mysqli, int $limit = 20, int $seconds = 50): array
{
    if (!rivetJobsAvailable($mysqli)) {
        return ['claimed' => 0, 'completed' => 0, 'retrying' => 0, 'dead' => 0, 'released' => 0];
    }
    $worker = new \RivetCore\Jobs\JobWorker(new \RivetCore\Jobs\JobQueue(rivetCoreDb($mysqli)));
    rivetRegisterJobHandlers($worker, $mysqli);

    return $worker->run($limit, $seconds);
}

/** What each job type does. Edition-specific work (creating a ticket, notifying a user) is done here, not in Core. */
function rivetRegisterJobHandlers(\RivetCore\Jobs\JobWorker $worker, $mysqli): void
{
    $worker->register('webhook.deliver', static function (array $p, array $job) use ($mysqli) {
        $r = rivetWebhookDispatcher($mysqli)->deliverTo((int) ($p['webhook_id'] ?? 0), (string) ($p['event'] ?? ''), (array) ($p['data'] ?? []), (int) ($job['attempts'] ?? 1), $p['emitted_at'] ?? null);
        if (!empty($r['gone'])) {
            throw new \RivetCore\Jobs\PermanentJobFailure('The webhook endpoint no longer exists or is disabled.');
        }
        if (!$r['ok']) {
            throw new \RuntimeException((string) ($r['error'] ?? 'delivery failed'));
        }

        return ['http_status' => $r['http_status'], 'duration_ms' => $r['duration_ms']];
    });

    $worker->register('automation.action', static function (array $p) use ($mysqli) {
        $res = rivetRunAutomationRule($mysqli, (int) ($p['rule_id'] ?? 0), (string) ($p['event'] ?? ''), (array) ($p['context'] ?? []));
        if (!$res['ok']) {
            throw new \RuntimeException($res['message']);
        }

        return ['message' => $res['message']];
    });
}

/** Execute one event rule's action now and record that it ran. @return array{rule_id:int, ok:bool, message:string} */
function rivetRunAutomationRule($mysqli, int $ruleId, string $event, array $context): array
{
    $store = new \RivetCore\Automation\AutomationRuleStore(rivetCoreDb($mysqli));
    $rule = $store->find($ruleId);
    if ($rule === null || (int) $rule['is_enabled'] !== 1) {
        return ['rule_id' => $ruleId, 'ok' => true, 'message' => 'rule is gone or disabled; skipped'];
    }
    $result = (new \RivetCore\Automation\AutomationExecutor())->execute($rule, $context, rivetAutomationActionHandlers($mysqli, $rule['name']));
    try {
        // Written straight to the audit table (not through the after-log hook) so a rule's own record can never trigger rules again.
        $stmt = mysqli_prepare($mysqli, "INSERT INTO audit_events (event_type, entity_type, entity_id, action, summary, metadata_json) VALUES ('automation.rule_fired', 'automation_rule', ?, ?, ?, ?)");
        $id = (string) $ruleId;
        $action = $result['ok'] ? 'ok' : 'failed';
        $summary = mb_substr("Rule '" . $rule['name'] . "' on $event: " . $result['message'], 0, 500);
        $meta = json_encode(['event' => $event, 'rule_id' => $ruleId], JSON_UNESCAPED_SLASHES);
        mysqli_stmt_bind_param($stmt, 'ssss', $id, $action, $summary, $meta);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (\Throwable $e) {
        // the record is best effort
    }

    return $result;
}

/** @return array<string, callable> */
function rivetAutomationActionHandlers($mysqli, string $ruleName): array
{
    return [
        'create_ticket' => static function (array $cfg, array $ctx) use ($mysqli, $ruleName) {
            $id = rivetCreateAutomationTicket($mysqli, (string) $cfg['subject'], (string) ($cfg['details'] ?? ''), (string) ($cfg['priority'] ?? 'Low'), (int) ($cfg['client_id'] ?? 0), 'Automation: ' . $ruleName);

            return "created ticket #$id";
        },
        'send_webhook' => static function (array $cfg, array $ctx) use ($mysqli) {
            // Checked again at call time (DNS can change after the rule was saved).
            $target = rivetWebhookResolveTarget((string) $cfg['url']);
            if ($target === null) {
                throw new \RuntimeException('the webhook URL does not resolve to a public address');
            }
            $body = json_encode(['event' => $ctx['event'] ?? '', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => $ctx], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers = ['Content-Type: application/json'];
            foreach (rivetWebhookHeaderPrefixes() as $prefix) {
                $headers[] = $prefix . '-Event: ' . ($ctx['event'] ?? '');
                if (($cfg['secret'] ?? '') !== '') {
                    $headers[] = $prefix . '-Signature: sha256=' . hash_hmac('sha256', $body, (string) $cfg['secret']);
                }
            }
            $ch = curl_init((string) $cfg['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // Pin the connection to the addresses just vetted, so a DNS answer that changes in between cannot redirect it inward.
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . implode(',', $target['ips'])]]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($out === false) {
                throw new \RuntimeException('webhook request failed: ' . curl_error($ch));
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException("webhook endpoint answered HTTP $code");
            }

            return "webhook sent (HTTP $code)";
        },
        'notify_user' => static function (array $cfg, array $ctx) use ($mysqli, $ruleName) {
            appNotify('Automation', mb_substr((string) $cfg['message'], 0, 900), null, 0, 0, true);

            return 'notification created';
        },
    ];
}

/** Create a ticket for an automation rule: same numbering and defaults as every other ticket-creation path. */
function rivetCreateAutomationTicket($mysqli, string $subject, string $details, string $priority, int $client_id, string $source): int
{
    $priority = in_array($priority, ['Low', 'Medium', 'High'], true) ? $priority : 'Low';
    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_ticket_prefix FROM settings WHERE company_id = 1"));
    $prefix = mysqli_real_escape_string($mysqli, (string) $settings['config_ticket_prefix']);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = mysqli_insert_id($mysqli);
    $assigned = resolveTicketAssignee(0);
    $status = $assigned > 0 ? 2 : 1;
    $subject_esc = mysqli_real_escape_string($mysqli, mb_substr($subject, 0, 500));
    $details_esc = mysqli_real_escape_string($mysqli, $details);
    $source_esc = mysqli_real_escape_string($mysqli, mb_substr($source, 0, 100));
    $url_key = randomString(32);
    mysqli_query($mysqli,
        "INSERT INTO tickets SET ticket_prefix = '$prefix', ticket_number = $number, ticket_subject = '$subject_esc', ticket_details = '$details_esc',
         ticket_status = $status, ticket_priority = '$priority', ticket_source = '$source_esc', ticket_client_id = $client_id, ticket_created_by = 0,
         ticket_assigned_to = $assigned, ticket_url_key = '$url_key', ticket_created_at = NOW()");

    return (int) mysqli_insert_id($mysqli);
}

/** Record an audit event from an admin page (RivetIT's audit service or RivetMSP's Core bridge); never throws. */
function rivetAudit(string $event, ?int $actor, ?string $entityType, $entityId, string $action, ?string $summary = null, array $metadata = []): void
{
    try {
        if (class_exists('\RivetMSP\Core\CoreBridge')) {
            \RivetMSP\Core\CoreBridge::audit()?->log($event, $actor, $entityType, $entityId, $action, $summary, $metadata);
        } elseif (class_exists('\ITFlow\Audit\AuditService')) {
            \ITFlow\Audit\AuditService::record($event, $actor, $entityType, $entityId, $action, $summary, $metadata);
        }
    } catch (\Throwable $e) {
        error_log('audit event not recorded: ' . $e->getMessage());
    }
}

/**
 * Is this webhook URL safe to call from the server? http(s) only, and the host must resolve ONLY to public addresses (no loopback,
 * link-local such as the cloud metadata address, or private ranges), so a rule cannot be used to reach internal services.
 */
/**
 * Resolve a webhook URL and vet it: http(s) only, no userinfo/backslashes, and every address it resolves to must be public.
 * Returns the vetted target so the caller can PIN the connection to those addresses (stops DNS rebinding between check and use), or null.
 *
 * @return array{host:string, port:int, ips:string[]}|null
 */
function rivetWebhookResolveTarget(string $url): ?array
{
    if (str_contains($url, '\\') || preg_match('/[\x00-\x20]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    $host = trim($parts['host'], '[]');
    $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $resolved = @gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }

    return ['host' => $host, 'port' => $port, 'ips' => array_values(array_unique($ips))];
}

function rivetWebhookUrlIsSafe(string $url): bool
{
    return rivetWebhookResolveTarget($url) !== null;
}
