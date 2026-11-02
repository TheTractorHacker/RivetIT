<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\OdooUserGateway;
use ITFlow\Automation\UnconfiguredOdooUserGateway;
use ITFlow\Workflow\ActionGateway;

/**
 * Creates an Odoo user for the person the event is about (contact_id), through an OdooUserGateway.
 *
 * MOCK ONLY / UNVERIFIED LIVE: the Odoo this app talks to is PRODUCTION and read only. No write gateway ships, so by default this action fails with a clear
 * message even when switched on; it exists so the rule shape, the switch (settings.config_automation_odoo_user_enabled, OFF) and the contract can be
 * tested with a recording gateway. The login and name come from the person's record in the database, never from event data.
 */
final class CreateOdooUserAction extends AbstractAction
{
    private static ?OdooUserGateway $gateway = null;

    public static function useGateway(?OdooUserGateway $g): void
    {
        self::$gateway = $g;
    }

    public function key(): string
    {
        return 'create_odoo_user';
    }

    public function label(): string
    {
        return 'Create an Odoo user';
    }

    public static function enabled(\mysqli $mysqli): bool
    {
        $res = @mysqli_query($mysqli, 'SELECT config_automation_odoo_user_enabled FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;

        return $row !== null && (int) $row[0] === 1;
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $raw = $input['groups'] ?? [];
        $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $groups = [];
        foreach ($items as $g) {
            $g = trim((string) $g);
            if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/i', $g)) {
                throw new \InvalidArgumentException('Odoo groups are external ids such as base.group_user, one per line.');
            }
            $groups[] = $g;
        }
        if (count($groups) > 20) {
            throw new \InvalidArgumentException('At most 20 Odoo groups.');
        }

        return ['groups' => array_values(array_unique($groups))];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if (!self::enabled($mysqli)) {
            throw new \RuntimeException('"Allow automation rules to create Odoo users" is off (Administration > Automation); nothing was sent');
        }
        $cid = \ITFlow\Automation\PayloadEnricher::subjectId($context, 'contact');
        if ($cid <= 0) {
            throw new \RuntimeException('the event is not about a person (it has no contact_id)');
        }
        $stmt = mysqli_prepare($mysqli, 'SELECT contact_name, contact_email FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'i', $cid);
        mysqli_stmt_execute($stmt);
        $c = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$c) {
            throw new \RuntimeException("person #$cid no longer exists");
        }
        $login = trim((string) $c['contact_email']);
        if (!filter_var($login, FILTER_VALIDATE_EMAIL) || strlen($login) > 200) {
            throw new \RuntimeException('the person has no usable email address to use as the Odoo login');
        }
        $spec = ['login' => $login, 'name' => mb_substr(trim((string) $c['contact_name']), 0, 200), 'groups' => (array) ($config['groups'] ?? []), 'contact_id' => $cid];
        if ($dry) {
            return 'WOULD ask Odoo to create user ' . $login . ' ("' . $spec['name'] . '") with ' . count($spec['groups']) . ' group(s); skipped if that login exists. No Odoo write gateway ships with this version.';
        }
        $result = (self::$gateway ?? new UnconfiguredOdooUserGateway())->createUser($spec);
        $gateway->audit('automation.create_odoo_user', null, 'contact', $cid, 'create', mb_substr("Rule '" . $rule['name'] . "': Odoo user $login", 0, 480), ['rule_id' => (int) ($rule['rule_id'] ?? 0)]);

        return $result;
    }
}
