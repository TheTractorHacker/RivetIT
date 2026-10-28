<?php

declare(strict_types=1);

namespace ITFlow\Links;

/**
 * Who is asking: user id, admin flag, module levels and the departments the user is limited to. Built from the signed-in session
 * (pages), from an agent user id (API, MCP, cron) or by hand (tests). The link service takes this instead of reading globals, so
 * the same rules apply to the web pages and the API.
 */
final class LinkActor
{
    /**
     * @param array<string,int> $levels module name => level (0 = none)
     * @param list<int>|null    $clientIds null = every department; otherwise only these (plus department 0, the global records)
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $admin,
        private array $levels,
        private ?array $clientIds = null,
        private ?array $apiKeyClients = null,
    ) {
    }

    /** The signed-in web session (functions.php: lookupUserPermission, user_client_permissions). */
    public static function fromSession(\mysqli $db): self
    {
        $uid = (int) ($GLOBALS['session_user_id'] ?? 0);
        $admin = !empty($GLOBALS['session_is_admin']);
        $levels = [];
        foreach (['module_assets', 'module_support', 'module_client', 'module_kb', 'module_credential', 'module_financial'] as $m) {
            $levels[$m] = $admin ? 3 : (int) lookupUserPermission($m);
        }

        return new self($uid, $admin, $levels, self::clientIdsFor($db, $uid));
    }

    /** Any agent user, by id (no session needed). */
    public static function forUser(\mysqli $db, int $userId, int|array|null $apiKeyClient = null): self
    {
        $levels = [];
        $admin = false;
        $res = $db->query('SELECT u.user_role_id, r.role_is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ' . $userId . ' LIMIT 1');
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $admin = (int) ($row['role_is_admin'] ?? 0) === 1;
            $r2 = $db->query('SELECT m.module_name, urp.user_role_permission_level AS lvl FROM user_role_permissions urp JOIN modules m ON m.module_id = urp.module_id WHERE urp.user_role_id = ' . (int) $row['user_role_id']);
            while ($r2 && ($x = $r2->fetch_assoc())) {
                $levels[(string) $x['module_name']] = (int) $x['lvl'];
            }
        }

        return new self($userId, $admin, $levels, $row ? self::clientIdsFor($db, $userId) : [], self::keyClients($apiKeyClient));
    }

    /** @return list<int>|null */
    private static function clientIdsFor(\mysqli $db, int $userId): ?array
    {
        $res = $db->query('SELECT client_id FROM user_client_permissions WHERE user_id = ' . $userId);
        $ids = [];
        while ($res && ($x = $res->fetch_row())) {
            $ids[] = (int) $x[0];
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * A legacy API key's department limit as a list: null = none (not a legacy key, or the key covers every department),
     * an int above zero = that one department, an int below zero (the "several departments" sentinel with no resolved list)
     * = nothing at all, an array = exactly those departments. Never read a negative value as "unrestricted".
     *
     * @return list<int>|null
     */
    private static function keyClients(int|array|null $key): ?array
    {
        if ($key === null || $key === 0) {
            return null;
        }
        if (is_array($key)) {
            return array_values(array_unique(array_map('intval', $key)));
        }

        return $key > 0 ? [$key] : [];
    }

    /**
     * The departments this actor is limited to, or null when every department is open to them (admins, users with no
     * restriction). A legacy API key's own department list is applied on top, even for the admin the key resolves to.
     */
    public function restrictedClientIds(): ?array
    {
        $own = $this->admin ? null : $this->clientIds;
        if ($this->apiKeyClients === null) {
            return $own;
        }

        return $own === null ? $this->apiKeyClients : array_values(array_intersect($own, $this->apiKeyClients));
    }

    public function level(string $module): int
    {
        return $this->admin ? 3 : (int) ($this->levels[$module] ?? 0);
    }

    /** @param list<string> $anyOf */
    public function hasAny(array $anyOf, int $level): bool
    {
        foreach ($anyOf as $m) {
            if ($this->level($m) >= $level) {
                return true;
            }
        }

        return false;
    }

    public function canRead(string $type): bool
    {
        $spec = EntityTypes::spec($type);

        return $spec !== null && $this->hasAny($spec['modules'], 1);
    }

    public function canWrite(string $type): bool
    {
        $spec = EntityTypes::spec($type);
        if ($spec === null) {
            return false;
        }
        // Locations change under Departments (module_client); everything else under the module that owns it.
        $mods = $type === 'location' ? ['module_client'] : $spec['modules'];

        return $this->hasAny($mods, 2);
    }

    /** Department 0 is the global bucket: any signed-in agent may see a record kept there (the app's enforceClientAccess() rule). */
    public function canAccessClient(int $clientId): bool
    {
        if ($this->apiKeyClients !== null && $clientId !== 0 && !in_array($clientId, $this->apiKeyClients, true)) {
            return false;
        }
        if ($clientId === 0 || $this->admin || $this->clientIds === null) {
            return true;
        }

        return in_array($clientId, $this->clientIds, true);
    }
}
