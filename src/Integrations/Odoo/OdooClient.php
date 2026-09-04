<?php

namespace ITFlow\Integrations\Odoo;

use ITFlow\Integrations\BusinessApplicationProvider;
use ITFlow\Integrations\ConnectionResult;
use ITFlow\Integrations\ExternalUser;

/**
 * Odoo client via JSON-RPC (Odoo's /jsonrpc endpoint - common.login for
 * auth, then object.execute_kw for model calls). Scaffolding per
 * PROGRESS.md: real, working JSON-RPC code, but no Odoo instance/API key
 * has been provided yet to test it against.
 *
 * Per Section 10.4: never assume res.users field names or group names are
 * identical across Odoo versions/environments - this only reads the
 * small, stable set of fields (id, name, login, active, partner_id)
 * rather than guessing at anything version-specific.
 */
class OdooClient implements BusinessApplicationProvider
{
    private ?int $uid = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $database,
        private readonly string $username,
        private readonly string $apiKey
    ) {
    }

    public function testConnection(): ConnectionResult
    {
        try {
            $this->authenticate();
        } catch (\RuntimeException $e) {
            return new ConnectionResult(false, $e->getMessage());
        }

        return new ConnectionResult(true, null, ['uid' => $this->uid]);
    }

    /**
     * @return ExternalUser[]
     */
    public function listUsers(?string $cursor = null): array
    {
        $this->authenticate();

        $offset = $cursor !== null ? (int) $cursor : 0;
        $records = $this->executeKw('res.users', 'search_read', [
            [], // no domain filter - all users
        ], [
            'fields' => ['id', 'name', 'login', 'active', 'partner_id'],
            'limit' => 100,
            'offset' => $offset,
        ]);

        return array_map(fn($r) => $this->mapUser($r), $records);
    }

    public function getUser(string $externalId): ?ExternalUser
    {
        $this->authenticate();

        $records = $this->executeKw('res.users', 'read', [[(int) $externalId]], [
            'fields' => ['id', 'name', 'login', 'active', 'partner_id'],
        ]);

        return isset($records[0]) ? $this->mapUser($records[0]) : null;
    }

    /**
     * @return array raw hr.department records: [{id, name, parent_id: [id,name]|false}, ...], all pages
     */
    public function listDepartments(): array
    {
        $this->authenticate();

        return $this->searchReadAll('hr.department', [], ['id', 'name', 'parent_id']);
    }

    /**
     * @return array raw hr.employee records: [{id, name, work_email, department_id,
     *   job_title, work_phone, mobile_phone, active}, ...], all pages, INCLUDING
     *   inactive employees (see 'active_test' => false below - without it Odoo
     *   silently drops active=false records from search_read, which would make
     *   detecting a deactivation impossible).
     */
    public function listEmployees(): array
    {
        $this->authenticate();

        return $this->searchReadAll('hr.employee', [], [
            'id', 'name', 'work_email', 'department_id', 'job_title', 'work_phone', 'mobile_phone', 'active',
        ], ['context' => ['active_test' => false]]);
    }

    private function searchReadAll(string $model, array $domain, array $fields, array $extraKwargs = []): array
    {
        $records = [];
        $limit = 100;
        $offset = 0;

        do {
            $batch = $this->executeKw($model, 'search_read', [$domain], array_merge([
                'fields' => $fields,
                'limit' => $limit,
                'offset' => $offset,
                'order' => 'id asc',
            ], $extraKwargs));

            foreach ($batch as $r) {
                $records[] = $r;
            }

            $offset += $limit;
        } while (count($batch) === $limit);

        return $records;
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

    private function authenticate(): void
    {
        if ($this->uid !== null) {
            return;
        }

        $result = $this->call('common', 'login', [$this->database, $this->username, $this->apiKey]);
        if (!is_int($result)) {
            throw new \RuntimeException('Odoo login failed - check database name, username, and API key.');
        }

        $this->uid = $result;
    }

    private function executeKw(string $model, string $method, array $args, array $kwargs = []): array
    {
        $result = $this->call('object', 'execute_kw', [
            $this->database, $this->uid, $this->apiKey, $model, $method, $args, $kwargs,
        ]);

        if (!is_array($result)) {
            throw new \RuntimeException("Unexpected response from Odoo for $model.$method");
        }

        return $result;
    }

    private function call(string $service, string $method, array $args)
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => random_int(1, PHP_INT_MAX),
        ]);

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/jsonrpc');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not reach Odoo at {$this->baseUrl}: $curlError");
        }

        $data = json_decode($body, true);
        if (isset($data['error'])) {
            $message = $data['error']['data']['message'] ?? $data['error']['message'] ?? 'Unknown Odoo error';
            throw new \RuntimeException("Odoo error: $message");
        }

        return $data['result'] ?? null;
    }
}
