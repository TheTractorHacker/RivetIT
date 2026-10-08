<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Integrations\Microsoft\EntraAccountService;
use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\DirectoryActionGateway;

/** Shared parts of the Entra account actions: the setting gate, the UPN/placeholder handling and the gateway check. */
abstract class AbstractEntraAction extends AbstractAction
{
    /** Which template type may use the action: 'onboarding' or 'offboarding'. */
    abstract public function templateType(): string;

    /** @param array<string,mixed> $config */
    protected function upnFrom(array $config, array $ctx): string
    {
        $upn = Placeholders::render((string) ($config['upn'] ?? '{{employee_email}}'), $ctx['vars'] ?? [], 'text');
        if (!preg_match('/^[^@\s]{1,64}@[^@\s]+\.[^@\s]+$/', $upn) || strlen($upn) > 200) {
            throw new \RuntimeException('the person has no usable email address / user principal name for Entra');
        }

        return $upn;
    }

    protected function directory(ActionGateway $gateway): DirectoryActionGateway
    {
        if (!$gateway instanceof DirectoryActionGateway) {
            throw new \RuntimeException('Entra account actions are not available here');
        }
        if (!$gateway->entraWritesAllowed()) {
            throw new \RuntimeException("'Allow RivetIT to change Entra accounts' is off (Administration > Integrations > Directory Sync); nothing was sent. Do this step by hand, or turn the setting on and run it again.");
        }

        return $gateway;
    }

    /** @param array<string,mixed> $config @return string[] */
    protected function groupsFrom(array $config, bool $required): array
    {
        $raw = $config['groups'] ?? '';
        $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $clean = EntraAccountService::cleanGroupIds($items);
        if (count($clean) !== count(array_unique(array_map('strtolower', array_map('trim', $items))))) {
            throw new \InvalidArgumentException('Groups must be Entra group Object IDs (GUIDs), one per line.');
        }
        if ($required && !$clean) {
            throw new \InvalidArgumentException('Enter at least one Entra group Object ID.');
        }

        return $clean;
    }

    protected function cleanName(string $s): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s)), 0, 256);
    }
}
