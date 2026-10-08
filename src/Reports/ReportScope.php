<?php

namespace ITFlow\Reports;

/**
 * Per-user department (client) restriction for reports.
 *
 * Users can be limited to a set of departments through user_client_permissions; includes/load_user_session.php
 * turns that into the global $client_access_string (comma list of ints) and $session_is_admin. Admins and users
 * with no restriction rows are unrestricted. Everything here returns SQL fragments that begin with " AND " (or
 * an empty string), so a caller appends them to an existing WHERE.
 *
 * Outside a web session (cron, CLI) neither global exists and nothing is restricted, so scheduled summaries
 * keep their company-wide figures.
 */
final class ReportScope
{
    /** Normalise to a list of positive ints, or null when there is no restriction. */
    public static function ids(?string $accessString, bool $isAdmin): ?array
    {
        if ($isAdmin || $accessString === null || trim($accessString) === '') {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $accessString)), static fn ($v) => $v > 0)));
        return $ids === [] ? null : $ids;
    }

    /** Scope of the current web session, or null. */
    public static function sessionIds(): ?array
    {
        return self::ids(
            isset($GLOBALS['client_access_string']) ? (string) $GLOBALS['client_access_string'] : null,
            !empty($GLOBALS['session_is_admin'])
        );
    }

    public static function isRestricted(): bool
    {
        return self::sessionIds() !== null;
    }

    /** " AND col IN (1,2)" for the session, or ''. */
    public static function clause(string $column): string
    {
        return self::clauseFor(self::sessionIds(), $column);
    }

    public static function clauseFor(?array $ids, string $column): string
    {
        if ($ids === null) {
            return '';
        }
        // Column names are code constants; refuse anything that is not a plain (aliased) identifier.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            return ' AND 1 = 0';
        }
        return " AND $column IN (" . implode(',', array_map('intval', $ids)) . ')';
    }

    /** Is this client visible to the session? */
    public static function allowsClient(int $clientId): bool
    {
        $ids = self::sessionIds();
        return $ids === null || in_array($clientId, $ids, true);
    }

    /**
     * Company-wide figures (expenses, revenues, budgets, tax) cannot be sliced by department, so a
     * department-restricted user is refused them rather than shown totals that include other departments.
     */
    public static function denyIfRestricted(): void
    {
        if (self::isRestricted()) {
            if (function_exists('itflow_render_denied')) {
                itflow_render_denied();
            }
            http_response_code(403);
            exit('This report covers every department and is not available to department-restricted users.');
        }
    }
}
