<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Takes back every device assigned to the person: the link is removed and the status is set (default "Ready to Deploy"). History is recorded. */
class CollectAssetAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'collect_assets';
    }

    public function label(): string
    {
        return "Collect the employee's devices";
    }

    public function validate(array $config): array
    {
        return [
            'asset_types' => $this->text($config, 'asset_types', 300, false, 'device types'),
            'to_status' => $this->text($config, 'to_status', 100, false, 'status after collection') ?: 'Ready to Deploy',
        ];
    }

    public function describe(array $config, array $ctx): string
    {
        $types = trim((string) ($config['asset_types'] ?? ''));

        return 'Unassign ' . ($types !== '' ? "the $types devices" : 'all devices') . ' of ' . ($ctx['vars']['employee_name'] ?? 'the employee') . ' and set their status to "' . ($config['to_status'] ?? 'Ready to Deploy') . '"';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        return $this->lifecycle($gateway)->collectAssets($this->contactId($ctx), $config, (int) ($ctx['run_task_id'] ?? 0));
    }
}
