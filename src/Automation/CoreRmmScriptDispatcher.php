<?php

namespace ITFlow\Automation;

/** The live RmmScriptDispatcher: the RMM module's technician API (includes/rmm_automation.php), called as the rule's "run as" technician. UNVERIFIED LIVE. */
final class CoreRmmScriptDispatcher implements RmmScriptDispatcher
{
    public function __construct(private \mysqli $mysqli)
    {
    }

    private function boot(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/includes/event_bus.php';
        require_once $root . '/includes/rmm_bootstrap.php';
        require_once $root . '/includes/rmm_automation.php';
        if (!\rivetRmmEnabled($this->mysqli) || !\rivetRmmFeatureOn('scripts', $this->mysqli)) {
            throw new \RuntimeException('the RMM module (or its Scripts switch) is off');
        }
    }

    public function scriptName(int $asUserId, string $asUserName, int $scriptId): ?string
    {
        $this->boot();
        $r = \rivetRmmAutoApi($this->mysqli, $asUserId, $asUserName, 'GET', ['scripts', $scriptId]);

        return $r['ok'] ? (string) ($r['data']['script']['name'] ?? ('script #' . $scriptId)) : null;
    }

    public function dispatch(int $asUserId, string $asUserName, int $deviceId, int $scriptId, array $params, ?int $timeoutS): array
    {
        $this->boot();
        $body = ['library_script_id' => $scriptId, 'params' => (object) $params, 'confirm' => true];
        if ($timeoutS !== null) {
            $body['timeout_s'] = $timeoutS;
        }
        $r = \rivetRmmAutoApi($this->mysqli, $asUserId, $asUserName, 'POST', [$deviceId, 'jobs'], [], $body);
        if (!$r['ok']) {
            throw new \RuntimeException('the RMM module refused: ' . ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']));
        }
        if ($r['status'] === 202) {
            return ['queued' => false, 'pending_approval' => true, 'message' => 'the run needs a second person to approve it; it is waiting under RMM > Approvals'];
        }

        return ['queued' => true, 'pending_approval' => false, 'message' => 'script job queued on device #' . $deviceId . '; the device runs it at its next check-in'];
    }
}
