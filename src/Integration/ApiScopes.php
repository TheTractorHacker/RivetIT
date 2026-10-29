<?php

namespace ITFlow\Integration;

/**
 * The scopes that let an integration client call the general REST API (everything under /api/v1 except the LMS and directory routes,
 * which keep their own lms:* and directory:read scopes).
 *
 * One scope per resource and action: api:<resource>:read | write | delete. There is no wildcard and no implication between them
 * (write does not include read, delete does not include write): a client holds exactly the strings listed in
 * integration_clients.client_scopes, and a token can carry only what the client holds.
 *
 * Action by HTTP method: GET and HEAD -> read; POST, PUT and PATCH -> write; DELETE -> delete. Any other method is refused.
 *
 * Resources that are about a signed-in PERSON are deliberately not scopable and are refused (403) for every integration client:
 * credentials (the vault), me, notifications, approvals, endpoint_devices, service_catalog and workflow_tasks. The legacy shared X-Api-Key
 * already could not use credentials, me, approvals, endpoint_devices, service_catalog or workflow_tasks (they demand a user token);
 * notifications used to return the first administrator's inbox, which a machine must not read.
 */
final class ApiScopes
{
    /** resource => label shown on Administration > API Keys */
    public const RESOURCES = [
        'alerts' => 'Alerts (RMM and backup)',
        'appointments' => 'Appointments',
        'assets' => 'Assets',
        'clients' => 'Departments / clients (and their tabs)',
        'contacts' => 'Contacts',
        'contracts' => 'Contracts',
        'dashboard' => 'Dashboard summary',
        'kb' => 'Knowledge base',
        'milestones' => 'Milestones',
        'outtakes' => 'Outtakes',
        'projects' => 'Projects',
        'relationships' => 'Relationships',
        'reports' => 'Reports',
        'search' => 'Search',
        'tasks' => 'Tasks',
        'ticket_attachments' => 'Ticket attachments',
        'ticket_categories' => 'Ticket categories',
        'ticket_views' => 'Saved ticket views',
        'tickets' => 'Tickets (and statuses)',
        'worksheets' => 'Worksheets and charges',
    ];

    public const ACTIONS = ['read', 'write', 'delete'];

    /** Routes no integration client may use, whatever it holds (person-bound; see the class comment). */
    public const DENIED_ROUTES = ['credentials', 'me', 'notifications', 'approvals', 'endpoint_devices', 'service_catalog', 'workflow_tasks'];

    /**
     * Which role-module name (api_has_module_permission) is satisfied by which resources. A module check inside an endpoint asks "may
     * this caller work with this kind of data at this level?". For an integration client the answer is its scopes: when the route being
     * served is one of the module's resources the route scope has already decided; when an endpoint blends in another module (search,
     * a client's tabs) the client must hold the module's PRIMARY resource (the first one). module_credential has no resources: never.
     */
    public const MODULES = [
        'module_support' => ['tickets', 'worksheets', 'outtakes', 'ticket_attachments', 'ticket_categories', 'ticket_views', 'appointments', 'milestones', 'projects', 'tasks', 'dashboard', 'assets'],
        'module_client' => ['clients', 'contacts', 'contracts', 'assets', 'relationships'],
        'module_assets' => ['assets'],
        'module_kb' => ['kb'],
        'module_reporting' => ['reports'],
        'module_rmm_alerts' => ['alerts'],
        'module_rmm' => [],
        'module_credential' => [],
    ];

    public static function scope(string $resource, string $action): string
    {
        return 'api:' . $resource . ':' . $action;
    }

    /** @return list<string> every general-API scope */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::RESOURCES) as $r) {
            foreach (self::ACTIONS as $a) {
                $out[] = self::scope($r, $a);
            }
        }
        return $out;
    }

    public static function isApiScope(string $s): bool
    {
        return in_array($s, self::all(), true);
    }

    /** read | write | delete for an HTTP method, or null when the method is not served. */
    public static function actionForMethod(string $method): ?string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD' => 'read',
            'POST', 'PUT', 'PATCH' => 'write',
            'DELETE' => 'delete',
            default => null,
        };
    }

    /**
     * The scope resource that guards a route (the first path segment as api/v1/index.php parsed it), or null when the route has no
     * scope (an unknown path, answered 404 by the router, or one of DENIED_ROUTES). validate_api_key is handled by the caller.
     */
    public static function routeResource(string $resource, ?string $sub): ?string
    {
        $sub = $sub === null ? '' : str_replace('_', '-', $sub);
        switch ($resource) {
            case 'tickets':
                if ($sub === 'charges' || $sub === 'worksheets') {
                    return 'worksheets';
                }
                if ($sub === 'outtake' || $sub === 'outtakes') {
                    return 'outtakes';
                }
                return 'tickets';
            case 'statuses':
                return 'tickets';
            case 'ticket-categories':
            case 'ticket_categories':
                return 'ticket_categories';
            case 'ticket-views':
            case 'ticket_views':
                return 'ticket_views';
            case 'worksheets':
            case 'worksheet-templates':
                return 'worksheets';
            case 'dashboard':
            case 'alerts':
            case 'clients':
            case 'contacts':
            case 'assets':
            case 'relationships':
            case 'projects':
            case 'contracts':
            case 'tasks':
            case 'milestones':
            case 'outtakes':
            case 'search':
            case 'reports':
            case 'kb':
            case 'appointments':
            case 'ticket_attachments':
                return $resource;
        }
        return null;
    }

    /** @param list<string> $scopes */
    public static function has(array $scopes, string $resource, string $action): bool
    {
        return in_array(self::scope($resource, $action), $scopes, true);
    }

    /**
     * Answer for api_has_module_permission() when the caller is an integration client. $level: 1 = read, 2 = write, 3 and up = delete.
     *
     * @param list<string> $scopes
     */
    public static function moduleAllows(array $scopes, string $module, int $level, ?string $routeResource): bool
    {
        $resources = self::MODULES[$module] ?? [];
        if ($resources === []) {
            return false;
        }
        $action = $level >= 3 ? 'delete' : ($level === 2 ? 'write' : 'read');
        $resource = $routeResource !== null && in_array($routeResource, $resources, true) ? $routeResource : $resources[0];
        return self::has($scopes, $resource, $action);
    }

    /**
     * The scopes a legacy API key amounts to, from its two real settings (api_keys.api_key_permission and api_key_allow_delete):
     *   read                  -> api:<r>:read for every resource
     *   write                 -> read and write for every resource
     *   write + allow_delete  -> read, write and delete for every resource
     * A read-only key never had delete, whatever its flag said. An unknown permission value grants nothing (deny by default).
     *
     * @return list<string>
     */
    public static function fromLegacy(string $permission, bool $allowDelete): array
    {
        if ($permission !== 'read' && $permission !== 'write') {
            return [];
        }
        $actions = $permission === 'read' ? ['read'] : ($allowDelete ? ['read', 'write', 'delete'] : ['read', 'write']);
        $out = [];
        foreach (array_keys(self::RESOURCES) as $r) {
            foreach ($actions as $a) {
                $out[] = self::scope($r, $a);
            }
        }
        return $out;
    }
    /**
     * A short human summary of a client's scopes for the API Keys list (Administration > API Keys), instead of one badge per scope.
     *
     * 'headline' is e.g. "Read only: 20 areas", "Read & write: 18 areas", "Read, write & delete: 3 areas" (every area holds the same
     * actions) or "Custom: Tickets (read, write), Assets (read) +4" (mixed). 'extras' names the non-general-API scopes (LMS, directory,
     * single sign-on) by their plain names. 'groups' is the full list, one entry per area, with R / W / D marks; 'text' is the whole thing as
     * plain text for a tooltip. Pure function: no database, no HTML.
     *
     * @param list<string> $scopes
     * @return array{headline:string, areas:int, extras:list<string>, groups:list<array{resource:string,label:string,read:bool,write:bool,delete:bool,marks:string}>, text:string}
     */
    public static function summary(array $scopes): array
    {
        $held = [];
        $extras = [];
        $extraNames = ['lms:read' => 'LMS: read', 'lms:assign' => 'LMS: assign', 'lms:completions:read' => 'LMS: completions', 'lms:evidence:read' => 'LMS: evidence',
            'directory:read' => 'Directory: read', 'quality:events' => 'Quality events', 'sso' => 'Single sign-on'];
        foreach (array_unique($scopes) as $s) {
            if (preg_match('/^api:([a-z_]+):(read|write|delete)$/D', $s, $m) && isset(self::RESOURCES[$m[1]])) {
                $held[$m[1]][$m[2]] = true;
            } elseif ($s !== '') {
                $extras[] = $extraNames[$s] ?? $s;
            }
        }
        $groups = [];
        $sigs = [];
        foreach (self::RESOURCES as $res => $label) {
            if (!isset($held[$res])) {
                continue;
            }
            $r = !empty($held[$res]['read']);
            $w = !empty($held[$res]['write']);
            $d = !empty($held[$res]['delete']);
            $groups[] = ['resource' => $res, 'label' => $label, 'read' => $r, 'write' => $w, 'delete' => $d,
                'marks' => ($r ? 'R' : '-') . ($w ? 'W' : '-') . ($d ? 'D' : '-')];
            $sigs[$r . $w . $d] = true;
        }
        $n = count($groups);
        $words = static function (array $g): string {
            return implode(', ', array_filter([$g['read'] ? 'read' : '', $g['write'] ? 'write' : '', $g['delete'] ? 'delete' : '']));
        };
        $shortLabel = static fn(string $l): string => trim(preg_replace('/\s*\(.*$/', '', $l));
        if ($n === 0) {
            $headline = $extras ? 'No general API access' : 'No access';
        } elseif (count($sigs) === 1) {
            $g = $groups[0];
            $kind = $g['read'] && !$g['write'] && !$g['delete'] ? 'Read only'
                : ($g['read'] && $g['write'] && !$g['delete'] ? 'Read & write'
                : ($g['read'] && $g['write'] && $g['delete'] ? 'Read, write & delete' : ucfirst($words($g))));
            $headline = $kind . ': ' . $n . ($n === 1 ? ' area' : ' areas');
        } else {
            $shown = array_slice($groups, 0, 2);
            $headline = 'Custom: ' . implode(', ', array_map(static fn($g) => $shortLabel($g['label']) . ' (' . $words($g) . ')', $shown)) . ($n > 2 ? ' +' . ($n - 2) : '');
        }
        $lines = [$headline];
        foreach ($groups as $g) {
            $lines[] = $g['label'] . ': ' . $words($g);
        }
        foreach ($extras as $e) {
            $lines[] = $e;
        }
        return ['headline' => $headline, 'areas' => $n, 'extras' => $extras, 'groups' => $groups, 'text' => implode("\n", $lines)];
    }
}
