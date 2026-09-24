<?php

namespace ITFlow\Integrations\Odoo;

use ITFlow\Integrations\BusinessApplicationProvider;
use ITFlow\Integrations\ConnectionResult;
use ITFlow\Integrations\ExternalUser;

/**
 * Odoo client for the directory sync and the admin connection test. The wire
 * protocol lives in an OdooConnectorInterface: OdooLegacyRpcConnector (the
 * /jsonrpc common.login + object.execute_kw calls this class used to make
 * itself - still the default) or OdooJson2Connector (Odoo 19+ JSON-2).
 * Callers normally build it through OdooConnectorFactory::clientFromRow(),
 * which picks the connector from odoo_integrations.api_protocol.
 *
 * Every call below passes its arguments by name ($kwargs: 'domain', 'ids',
 * 'fields'...), the one form both protocols accept.
 *
 * Per Section 10.4: never assume res.users field names or group names are
 * identical across Odoo versions/environments - this only reads the
 * small, stable set of fields (id, name, login, active, partner_id)
 * rather than guessing at anything version-specific.
 */
class OdooClient implements BusinessApplicationProvider
{
    /**
     * Timeouts for this class's calls. The sync is a batch job, so it keeps
     * the 30 s per-request budget it always had and allows 10 s to connect -
     * more than the connectors' 2 s default, which is sized for interactive
     * callers - so a slow DNS/TLS handshake doesn't fail a whole sync.
     */
    private const CALL_OPTS = ['connect_timeout' => 10, 'timeout' => 30];

    /**
     * testConnection() is interactive, and Test Connection may try both
     * protocols in one request: 15 s per call keeps a hung server's worst case
     * (JSON-2, then JSON-RPC) around 30 s - well inside Cloudflare's 100 s
     * proxy timeout in front of the admin page.
     */
    private const TEST_OPTS = ['connect_timeout' => 5, 'timeout' => 15];

    /**
     * verifyDirectoryReads() runs up to four calls; together they get this
     * many seconds. A Test Connection that tries JSON-2 (context_get 15 s,
     * version 10 s, these checks 15 s) and then JSON-RPC (login, context_get,
     * version: 40 s) so stays around 80 s even against a server that answers
     * every call just inside its timeout - under Cloudflare's 100 s.
     */
    private const VERIFY_BUDGET = 15;

    /** What listDepartments() reads (and verifyDirectoryReads() checks). */
    private const DEPARTMENT_FIELDS = ['id', 'name', 'parent_id'];

    /** What listEmployees() reads (and verifyDirectoryReads() checks). */
    private const EMPLOYEE_FIELDS = [
        'id', 'name', 'work_email', 'department_id', 'job_title', 'work_phone', 'mobile_phone', 'active', 'parent_id',
    ];

    /** listEmployees()' extra kwargs - archived employees too; see listEmployees(). */
    private const EMPLOYEE_KWARGS = ['context' => ['active_test' => false]];

    private OdooConnectorInterface $connector;

    /**
     * @param OdooConnectorInterface|null $connector the protocol to use; null = legacy
     *        JSON-RPC built from the four credentials (the pre-connector behaviour)
     */
    public function __construct(
        string $baseUrl,
        string $database,
        string $username,
        string $apiKey,
        ?OdooConnectorInterface $connector = null
    ) {
        $this->connector = $connector ?? new OdooLegacyRpcConnector($baseUrl, $database, $username, $apiKey);
    }

    public function connector(): OdooConnectorInterface
    {
        return $this->connector;
    }

    /** OdooConnectorFactory::PROTOCOL_JSON2 or ::PROTOCOL_JSONRPC. */
    public function protocol(): string
    {
        return $this->connector->protocol();
    }

    /**
     * Proves the credentials work on this client's protocol with one cheap,
     * read-only call every Odoo user may make (res.users.context_get - the
     * user's own lang/tz), then reads the server version.
     *
     * details: 'protocol' always; on success 'server_version' (string|null)
     * and, on the legacy protocol, 'uid'.
     */
    public function testConnection(): ConnectionResult
    {
        $details = ['protocol' => $this->connector->protocol()];

        try {
            $context = $this->connector->call('res.users', 'context_get', [], [], self::TEST_OPTS);
            if (!is_array($context)) {
                throw new \RuntimeException('Unexpected response from Odoo for res.users.context_get');
            }
        } catch (\RuntimeException $e) {
            return new ConnectionResult(false, $e->getMessage(), $details);
        }

        if ($this->connector instanceof OdooLegacyRpcConnector) {
            $details['uid'] = $this->connector->uid();
        }
        $details['server_version'] = $this->connector->serverVersion()['server_version'] ?? null;

        return new ConnectionResult(true, null, $details);
    }

    /**
     * Proves the directory sync itself will work on this client's protocol -
     * what Test Connection checks before it moves a live integration onto
     * JSON-2 (testConnection()'s context_get only proves the key works).
     *
     *   1. listDepartments()' and listEmployees()' own search_read calls, with
     *      their exact arguments, one record each: field names, access rights
     *      and argument binding all as the real sync sends them.
     *   2. That the active_test=false context is honoured: hr.employee counted
     *      through that context must match the count through an explicit
     *      "active in (true, false)" domain. If the context were dropped,
     *      archived employees would silently stop coming back and
     *      OdooDirectoryMapper would never see anyone leave. (An Odoo user
     *      without HR read access gets the explicit count filtered down
     *      instead - that direction is never treated as a failure.)
     *
     * Read-only. Throws a \RuntimeException naming the check that failed.
     */
    public function verifyDirectoryReads(): void
    {
        $deadline = microtime(true) + self::VERIFY_BUDGET;

        $this->checked('hr.department.search_read', fn() => $this->searchReadPage(
            'hr.department', [], self::DEPARTMENT_FIELDS, 1, 0, [], $this->budgetOpts($deadline)
        ));
        $this->checked('hr.employee.search_read', fn() => $this->searchReadPage(
            'hr.employee', [], self::EMPLOYEE_FIELDS, 1, 0, self::EMPLOYEE_KWARGS, $this->budgetOpts($deadline)
        ));

        $viaContext = $this->checked('hr.employee.search_count', fn() => $this->connector->call(
            'hr.employee', 'search_count', [], ['domain' => []] + self::EMPLOYEE_KWARGS, $this->budgetOpts($deadline)
        ));
        $viaDomain = $this->checked('hr.employee.search_count', fn() => $this->connector->call(
            'hr.employee', 'search_count', [], ['domain' => [['active', 'in', [true, false]]]], $this->budgetOpts($deadline)
        ));

        if (!is_int($viaContext) || !is_int($viaDomain)) {
            throw new \RuntimeException('Unexpected response from Odoo for hr.employee.search_count');
        }
        if ($viaContext < $viaDomain) {
            throw new \RuntimeException(
                "Odoo ignored the active_test context ($viaContext of $viaDomain employees returned) - the sync would miss archived employees"
            );
        }
    }

    /**
     * @return ExternalUser[]
     */
    public function listUsers(?string $cursor = null): array
    {
        $offset = $cursor !== null ? (int) $cursor : 0;
        $records = $this->callList('res.users', 'search_read', [
            'domain' => [], // no domain filter - all users
            'fields' => ['id', 'name', 'login', 'active', 'partner_id'],
            'limit' => 100,
            'offset' => $offset,
        ]);

        return array_map(fn($r) => $this->mapUser($r), $records);
    }

    public function getUser(string $externalId): ?ExternalUser
    {
        $records = $this->callList('res.users', 'read', [
            'ids' => [(int) $externalId],
            'fields' => ['id', 'name', 'login', 'active', 'partner_id'],
        ]);

        return isset($records[0]) ? $this->mapUser($records[0]) : null;
    }

    /**
     * @return array raw hr.department records: [{id, name, parent_id: [id,name]|false}, ...], all pages
     */
    public function listDepartments(): array
    {
        return $this->searchReadAll('hr.department', [], self::DEPARTMENT_FIELDS);
    }

    /**
     * @return array raw hr.employee records: [{id, name, work_email, department_id,
     *   job_title, work_phone, mobile_phone, active, parent_id: [id,name]|false}, ...],
     *   all pages, INCLUDING inactive employees (see 'active_test' => false below -
     *   without it Odoo silently drops active=false records from search_read,
     *   which would make detecting a deactivation impossible - and would also
     *   make a manager who has since left unresolvable, since an inactive
     *   manager's own record needs to come back too for
     *   OdooDirectoryMapper::syncEmployees() to link a still-active report to
     *   them). parent_id is hr.employee's own "Manager" field - Odoo's
     *   employee-to-employee reporting link, not to be confused with
     *   department_id (which department they belong to) or hr.department's
     *   OWN parent_id (that department's parent department, read by
     *   listDepartments() above - an entirely different field on an entirely
     *   different model, despite the identical name).
     */
    public function listEmployees(): array
    {
        return $this->searchReadAll('hr.employee', [], self::EMPLOYEE_FIELDS, self::EMPLOYEE_KWARGS);
    }

    private function searchReadAll(string $model, array $domain, array $fields, array $extraKwargs = []): array
    {
        $records = [];
        $limit = 100;
        $offset = 0;

        do {
            $batch = $this->searchReadPage($model, $domain, $fields, $limit, $offset, $extraKwargs, self::CALL_OPTS);

            foreach ($batch as $r) {
                $records[] = $r;
            }

            $offset += $limit;
        } while (count($batch) === $limit);

        return $records;
    }

    /** One search_read page - the one call shape the sync and verifyDirectoryReads() share. */
    private function searchReadPage(string $model, array $domain, array $fields, int $limit, int $offset, array $extraKwargs, array $opts): array
    {
        return $this->callList($model, 'search_read', array_merge([
            'domain' => $domain,
            'fields' => $fields,
            'limit' => $limit,
            'offset' => $offset,
            'order' => 'id asc',
        ], $extraKwargs), $opts);
    }

    /** Runs one verifyDirectoryReads() check, naming it in any failure. */
    private function checked(string $what, callable $check): mixed
    {
        try {
            return $check();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("$what: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /** Per-call timeouts for whatever is left of verifyDirectoryReads()' budget. */
    private function budgetOpts(float $deadline): array
    {
        $left = $deadline - microtime(true);
        if ($left < 1) {
            throw new \RuntimeException('Odoo took too long to answer the directory sync checks');
        }

        return [
            'connect_timeout' => min((float) self::TEST_OPTS['connect_timeout'], $left),
            'timeout' => min((float) self::TEST_OPTS['timeout'], $left),
        ];
    }

    private function mapUser(array $r): ExternalUser
    {
        return new ExternalUser(
            externalId: (string) $r['id'],
            displayName: $r['name'] ?? null,
            email: $r['login'] ?? null, // Odoo login is conventionally an email, but not guaranteed
            enabled: (bool) ($r['active'] ?? false),
            raw: $r
        );
    }

    /**
     * A call whose result must be a list/dict (search_read, read). The
     * connectors return any JSON value; this keeps the old executeKw()
     * guarantee for the methods above that index into the result.
     */
    private function callList(string $model, string $method, array $kwargs, array $opts = self::CALL_OPTS): array
    {
        $result = $this->connector->call($model, $method, [], $kwargs, $opts);

        if (!is_array($result)) {
            throw new \RuntimeException("Unexpected response from Odoo for $model.$method");
        }

        return $result;
    }
}
