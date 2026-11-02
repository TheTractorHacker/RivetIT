<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\Connectors\ConnectorRegistry;

/** Creates the employee's account in an external identity system (Active Directory, VPN, Okta, Google Workspace) through its connector. */
class ConnectorProvisionAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'connector_provision';
    }

    public function label(): string
    {
        return 'Identity system: create the employee account (AD / VPN / Okta / Google)';
    }

    public function templateTypes(): array
    {
        return ['onboarding'];
    }

    public function validate(array $config): array
    {
        $system = (string) ($config['system'] ?? '');
        if (!isset(ConnectorRegistry::SYSTEMS[$system])) {
            throw new \InvalidArgumentException('Choose the identity system.');
        }
        $groups = array_slice(array_values(array_filter(array_map(static fn ($g) => mb_substr(trim((string) $g), 0, 200), is_array($config['groups'] ?? null) ? $config['groups'] : preg_split('/[\r\n;]+/', (string) ($config['groups'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)))), 0, 25);

        return [
            'system' => $system,
            'identifier' => $this->text($config, 'identifier', 200, false, 'account name') ?: '{{employee_email}}',
            'display_name' => $this->text($config, 'display_name', 256, false, 'display name') ?: '{{employee_name}}',
            'enabled' => !empty($config['enabled']) ? 1 : 0,
            'groups' => implode("\n", $groups),
        ];
    }

    public function describe(array $config, array $ctx): string
    {
        $system = ConnectorRegistry::SYSTEMS[$config['system'] ?? ''] ?? 'the identity system';

        return 'Would create ' . Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . " in $system (" . (!empty($config['enabled']) ? 'enabled' : 'disabled') . '). Needs a live connector.';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $connector = $this->lifecycle($gateway)->identityConnector((string) ($config['system'] ?? ''));
        if (!$connector->isConfigured()) {
            throw new \RuntimeException($connector->isConfiguredHint());
        }
        $vars = $ctx['vars'] ?? [];

        return $connector->provision([
            'identifier' => Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $vars, 'text'),
            'display_name' => Placeholders::render((string) ($config['display_name'] ?? '{{employee_name}}'), $vars, 'text'),
            'enabled' => !empty($config['enabled']),
            'groups' => array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) ($config['groups'] ?? '')) ?: []))),
            'department' => (string) ($vars['department'] ?? ''),
        ]);
    }
}
