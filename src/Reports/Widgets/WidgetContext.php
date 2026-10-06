<?php

namespace ITFlow\Reports\Widgets;

use ITFlow\Reports\ReportScope;

/**
 * Who a widget is being computed for: the user, their department restriction (null = all departments) and a
 * permission probe. Built from the session in the page, built by hand in tests.
 */
final class WidgetContext
{
    /** @var callable(string, int=): bool */
    private $can;

    public function __construct(
        public int $userId,
        public ?array $scope,
        callable $can,
        public bool $csatEnabled = true,
        public bool $excludeProjects = false,
        public int $csatLowThreshold = 2
    ) {
        $this->can = $can;
    }

    public function can(string $module, int $level = 1): bool
    {
        return (bool) ($this->can)($module, $level);
    }

    /** " AND col IN (...)" for this context's departments, or ''. */
    public function scope(string $column): string
    {
        return ReportScope::clauseFor($this->scope, $column);
    }
}
