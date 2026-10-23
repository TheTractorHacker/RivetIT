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
    private ?\NumberFormatter $currencyFormat;

    public function __construct(
        public int $userId,
        ?array $scope,
        callable $can,
        public bool $csatEnabled = true,
        public bool $excludeProjects = false,
        public int $csatLowThreshold = 2,
        ?int $year = null,
        public bool $financeEnabled = false,
        public bool $avgResolutionEnabled = true,
        ?\NumberFormatter $currencyFormat = null,
        public string $currencyCode = 'USD'
    ) {
        $this->can = $can;
        $this->scope = $scope;
        $this->year = $year ?? (int) date('Y');
        $this->currencyFormat = $currencyFormat;
    }

    public ?array $scope;
    public int $year;

    public function can(string $module, int $level = 1): bool
    {
        return (bool) ($this->can)($module, $level);
    }

    /** " AND col IN (...)" for this context's departments, or ''. */
    public function scope(string $column): string
    {
        return ReportScope::clauseFor($this->scope, $column);
    }

    /** Formats a money amount the same way the rest of the app does (Admin > Company's locale/currency), or a
     * plain 2-decimal fallback when no NumberFormatter was given (e.g. a test context). */
    public function money(float $v): string
    {
        if ($this->currencyFormat instanceof \NumberFormatter) {
            return (string) numfmt_format_currency($this->currencyFormat, $v, $this->currencyCode);
        }
        return '$' . number_format($v, 2);
    }
}
