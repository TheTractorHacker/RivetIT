<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\CoreRmmScriptDispatcher;
use ITFlow\Automation\RmmScriptDispatcher;
use ITFlow\Workflow\ActionGateway;

/**
 * Dispatches a script from the RMM script library to the device the event is about (a context `device_id`, as every rmm.* event carries).
 *
 * Safety: OFF until an administrator turns on "Allow automation rules to run RMM scripts" (settings.config_automation_run_script_enabled); the script
 * and parameters are fixed in the rule (event data can never choose what runs, only which device it runs on); the job is created AS the technician the
 * rule names, so the RMM module's permission, department scope, platform check and second-person approval rules all apply (a run that needs approval
 * waits for it). Parameters are plain strings, never templated.
 * UNVERIFIED LIVE: the default dispatcher needs the RMM module and a real device; tests prove the rule path with a recording dispatcher.
 */
final class RunScriptAction extends AbstractAction
{
    private static ?RmmScriptDispatcher $dispatcher = null;

    public static function useDispatcher(?RmmScriptDispatcher $d): void
    {
        self::$dispatcher = $d;
    }

    public function key(): string
    {
        return 'run_script';
    }

    public function label(): string
    {
        return 'Run an RMM script';
    }

    public static function enabled(\mysqli $mysqli): bool
    {
        $res = @mysqli_query($mysqli, 'SELECT config_automation_run_script_enabled FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;

        return $row !== null && (int) $row[0] === 1;
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $script = (int) ($input['script_id'] ?? 0);
        if ($script <= 0) {
            throw new \InvalidArgumentException('Enter the library script id to run.');
        }
        $uid = (int) ($input['run_as_user_id'] ?? 0);
        if ($this->agent($mysqli, $uid) === null) {
            throw new \InvalidArgumentException('Choose the active technician the script runs as. Their RMM permissions and approval rules apply.');
        }
        $params = [];
        $raw = $input['params'] ?? [];
        if (is_string($raw)) {
            $lines = preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $raw = [];
            foreach ($lines as $line) {
                if (!str_contains($line, '=')) {
                    throw new \InvalidArgumentException('Parameters are one name=value per line.');
                }
                [$k, $v] = explode('=', $line, 2);
                $raw[trim($k)] = $v;
            }
        }
        foreach ((array) $raw as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,59}$/', $k) || !is_scalar($v)) {
                throw new \InvalidArgumentException('Parameter names are letters, numbers and underscores.');
            }
            $params[$k] = mb_substr(trim((string) $v), 0, 500);
        }
        if (count($params) > 20) {
            throw new \InvalidArgumentException('At most 20 parameters.');
        }
        $cfg = ['script_id' => $script, 'run_as_user_id' => $uid, 'params' => $params];
        $t = (int) ($input['timeout_s'] ?? 0);
        if ($t < 0 || $t > 86400) {
            throw new \InvalidArgumentException('The timeout is 1-86400 seconds (blank = the script default).');
        }
        if ($t > 0) {
            $cfg['timeout_s'] = $t;
        }

        return $cfg;
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if (!self::enabled($mysqli)) {
            throw new \RuntimeException('"Allow automation rules to run RMM scripts" is off (Administration > Automation); nothing was sent');
        }
        $device = isset($context['device_id']) && ctype_digit((string) $context['device_id']) ? (int) $context['device_id'] : 0;
        if ($device <= 0) {
            throw new \RuntimeException('the event is not about one RMM device (it has no device_id), so there is nowhere to run the script');
        }
        $agent = $this->agent($mysqli, (int) ($config['run_as_user_id'] ?? 0));
        if ($agent === null) {
            throw new \RuntimeException('the technician this rule runs scripts as is no longer active');
        }
        $d = self::$dispatcher ?? new CoreRmmScriptDispatcher($mysqli);
        $script = (int) $config['script_id'];
        $name = $d->scriptName($agent['user_id'], $agent['user_name'], $script);
        if ($name === null) {
            throw new \RuntimeException("library script #$script does not exist or {$agent['user_name']} may not see it");
        }
        if ($dry) {
            return 'WOULD queue the script "' . $name . '" on device #' . $device . ' as ' . $agent['user_name'] . ' (' . count((array) ($config['params'] ?? [])) . ' fixed parameter(s)); the RMM approval rules still apply';
        }
        $r = $d->dispatch($agent['user_id'], $agent['user_name'], $device, $script, (array) ($config['params'] ?? []), isset($config['timeout_s']) ? (int) $config['timeout_s'] : null);
        $gateway->audit('automation.run_script', null, 'rmm_device', $device, $r['pending_approval'] ? 'pending_approval' : 'queued',
            mb_substr("Rule '" . $rule['name'] . "': script \"$name\" on device #$device as {$agent['user_name']}" . ($r['pending_approval'] ? ' (waiting for approval)' : ''), 0, 480), ['rule_id' => (int) ($rule['rule_id'] ?? 0), 'script_id' => $script]);

        return $r['message'];
    }
}
