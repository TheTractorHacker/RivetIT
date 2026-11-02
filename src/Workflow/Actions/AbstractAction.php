<?php

namespace ITFlow\Workflow\Actions;

abstract class AbstractAction implements ActionInterface
{
    /** Template types this action may be used in (the editor hides it elsewhere and saving refuses it). */
    public function templateTypes(): array
    {
        return ['onboarding', 'offboarding', 'role_change', 'transfer'];
    }

    /** @param array<string,mixed> $config */
    protected function text(array $config, string $key, int $max, bool $required, string $label): string
    {
        $v = mb_substr(trim((string) ($config[$key] ?? '')), 0, $max);
        if ($required && $v === '') {
            throw new \InvalidArgumentException("Enter the $label.");
        }
        $unknown = Placeholders::unknown($v);
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown placeholder {{' . $unknown[0] . '}} in the ' . $label . '. Available: ' . implode(', ', array_map(static fn ($n) => '{{' . $n . '}}', Placeholders::NAMES)));
        }

        return $v;
    }
}
