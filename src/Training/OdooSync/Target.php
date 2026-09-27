<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;

/**
 * The Odoo the training write-back would talk to (Phase 5 spec §3.4, plan A22).
 *
 * current() reads the LATEST odoo_integrations row - the same row the directory sync and the
 * Phase 2 employee-link check use - and returns null unless that row is enabled. (The spec's
 * "latest enabled row" could pick an older integration whose employee links were never checked
 * while the newest one is switched off; the latest row is the one the links belong to.)
 *
 * key is the first 16 hex characters of sha256(lower(rtrim(trim(base_url),'/')) | trim(database)),
 * i.e. a prefix of the target hash Phase 2 stores for its accepted target, so both phases name a
 * target the same way. Write-back is pinned to the key an admin confirmed; any other key pauses it.
 *
 * The row carries the encrypted API key; it is decrypted only inside connector() (through
 * OdooConnectorFactory, per call) and never exposed by identity() or var_dump().
 */
final class Target
{
    public function __construct(
        public readonly int $integrationId,
        public readonly string $baseUrl,
        public readonly string $database,
        public readonly string $key,
        public readonly bool $looksStaging,
        public readonly bool $https,
        public readonly string $protocol,
        private readonly array $row,
    ) {
    }

    public static function current(\mysqli $db): ?self
    {
        try {
            $res = $db->query('SELECT odoo_integration_id, base_url, database_name, username, api_key_enc, api_protocol, enabled
                FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1');
            $row = $res->fetch_assoc();
            $res->free();
        } catch (\mysqli_sql_exception) {
            return null;
        }
        if (!$row || (int) ($row['enabled'] ?? 0) !== 1) {
            return null;
        }
        return self::fromRow($row);
    }

    /** Builds a Target from an odoo_integrations row (tests pass a row pointing at the mock). */
    public static function fromRow(array $row): self
    {
        $base = rtrim(trim((string) ($row['base_url'] ?? '')), '/');
        $database = trim((string) ($row['database_name'] ?? ''));
        $host = (string) (parse_url($base, PHP_URL_HOST) ?? '');
        $scheme = strtolower((string) (parse_url($base, PHP_URL_SCHEME) ?? ''));

        return new self(
            (int) ($row['odoo_integration_id'] ?? 0),
            $base,
            $database,
            self::keyFor($base, $database),
            self::looksStaging($host, $database),
            $scheme === 'https',
            OdooConnectorFactory::resolveProtocol($row),
            $row,
        );
    }

    public static function keyFor(string $baseUrl, string $database): string
    {
        return substr(hash('sha256', strtolower(rtrim(trim($baseUrl), '/')) . '|' . trim($database)), 0, 16);
    }

    public static function looksStaging(string $host, string $database): bool
    {
        return preg_match('/stag|test|sandbox|dev|demo/i', $host . ' ' . $database) === 1;
    }

    public function connector(): OdooConnectorInterface
    {
        return OdooConnectorFactory::fromRow($this->row);
    }

    public function host(): string
    {
        $h = parse_url($this->baseUrl, PHP_URL_HOST);
        return is_string($h) && $h !== '' ? $h : $this->baseUrl;
    }

    /** @return array{key:string, integration_id:int, base_url:string, database:string} */
    public function identity(): array
    {
        return ['key' => $this->key, 'integration_id' => $this->integrationId, 'base_url' => $this->baseUrl, 'database' => $this->database];
    }

    /** Keeps the row (and its encrypted key) out of var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return $this->identity() + ['looksStaging' => $this->looksStaging, 'https' => $this->https, 'protocol' => $this->protocol];
    }
}
