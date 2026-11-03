<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\Connectors\ConnectorRegistry;
use ITFlow\Workflow\Connectors\GroupConnector;

/** Adds the employee's existing account to groups in an external identity system whose connector supports it (Okta). */
class ConnectorAddGroupsAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'connector_add_groups';
    }

    public function label(): string
    {
        return 'Identity system: add the employee to groups (Okta)';
    }

    public function templateTypes(): array
    {
        return ['onboarding', 'role_change', 'transfer'];
    }

    public function validate(array $config): array
    {
        $system = (string) ($config['system'] ?? '');
        if (!isset(ConnectorRegistry::SYSTEMS[$system])) {
            throw new \InvalidArgumentException('Choose the identity system.');
        }
        $groups = array_slice(array_values(array_filter(array_map(static fn ($g) => mb_substr(trim((string) $g), 0, 200), is_array($config['groups'] ?? null) ? $config['groups'] : preg_split('/[\r\n;]+/', (string) ($config['groups'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)))), 0, 25);
        if (!$groups) {
            throw new \InvalidArgumentException('List at least one group.');
        }

        return ['system' => $system, 'identifier' => $this->text($config, 'identifier', 200, false, 'account name') ?: '{{employee_email}}', 'groups' => implode("\n", $groups)];
    }

    public function describe(array $config, array $ctx): string
    {
        $system = ConnectorRegistry::SYSTEMS[$config['system'] ?? ''] ?? 'the identity system';
        $n = count(array_filter(preg_split('/[\r\n;]+/', (string) ($config['groups'] ?? '')) ?: []));

        return 'Would add ' . Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . " to $n group(s) in $system. Needs a live connector.";
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $connector = $this->lifecycle($gateway)->identityConnector((string) ($config['system'] ?? ''));
        if (!$connector->isConfigured()) {
            throw new \RuntimeException($connector->isConfiguredHint());
        }
        if (!$connector instanceof GroupConnector) {
            throw new \RuntimeException($connector->label() . ' cannot add people to groups from a workflow in this build; do this step by hand');
        }
        $vars = $ctx['vars'] ?? [];

        return $connector->addToGroups(
            Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $vars, 'text'),
            array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) ($config['groups'] ?? '')) ?: []))),
            (string) ($vars['department'] ?? '')
        );
    }
}
