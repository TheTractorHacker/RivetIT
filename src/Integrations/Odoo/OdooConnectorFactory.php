<?php

namespace ITFlow\Integrations\Odoo;

/**
 * Builds the Odoo connector (or a whole OdooClient) for an odoo_integrations
 * row, honouring its api_protocol column (DB 2.6.91, default 'jsonrpc').
 *
 * Stored api_protocol values (the column is varchar(10)):
 *   'jsonrpc'     Automatic mode, JSON-2 not (yet) confirmed: use legacy /jsonrpc.
 *                 Every existing row starts here, so nothing changes until an
 *                 admin runs Test Connection and JSON-2 passes.
 *   'json2'       Automatic mode, JSON-2 confirmed by a passing Test Connection.
 *   'rpc_pinned'  An admin pinned legacy JSON-RPC; Test Connection won't switch it.
 * Anything else - including a row read before the 2.6.91 migration added the
 * column - is treated as 'jsonrpc'. A live sync only ever *reads* this value;
 * the one writer that moves it to 'json2' is the admin Test Connection
 * handler, after a JSON-2 test has actually succeeded.
 */
final class OdooConnectorFactory
{
    public const PROTOCOL_JSON2 = 'json2';
    public const PROTOCOL_JSONRPC = 'jsonrpc';

    /** Stored value for "legacy, pinned by an admin". */
    public const STORED_JSONRPC_PINNED = 'rpc_pinned';

    /** The spelling used in the spec draft; too long for varchar(10), accepted on read only. */
    private const STORED_JSONRPC_PINNED_ALT = 'jsonrpc_pinned';

    private function __construct()
    {
    }

    /**
     * @param array       $row           an odoo_integrations row (SELECT *)
     * @param string|null $forceProtocol PROTOCOL_JSON2 / PROTOCOL_JSONRPC to ignore the stored value
     *                                   (Test Connection's auto-detect), or null to honour it
     *
     * Never throws for a bad row (see OdooConnectorInterface) - a missing URL
     * or an undecryptable key surfaces from the first call() instead.
     */
    public static function fromRow(array $row, ?string $forceProtocol = null): OdooConnectorInterface
    {
        return self::build($row, self::resolveProtocol($row, $forceProtocol), self::decryptKey($row));
    }

    /** An OdooClient (directory sync, Test Connection) on the connector fromRow() would pick. */
    public static function clientFromRow(array $row, ?string $forceProtocol = null): OdooClient
    {
        $apiKey = self::decryptKey($row);

        return new OdooClient(
            (string) ($row['base_url'] ?? ''),
            (string) ($row['database_name'] ?? ''),
            (string) ($row['username'] ?? ''),
            $apiKey,
            self::build($row, self::resolveProtocol($row, $forceProtocol), $apiKey)
        );
    }

    /** The wire protocol a live call on this row uses: PROTOCOL_JSON2 or PROTOCOL_JSONRPC. */
    public static function resolveProtocol(array $row, ?string $forceProtocol = null): string
    {
        if ($forceProtocol !== null) {
            if ($forceProtocol !== self::PROTOCOL_JSON2 && $forceProtocol !== self::PROTOCOL_JSONRPC) {
                throw new \InvalidArgumentException("Unknown Odoo protocol: $forceProtocol");
            }
            return $forceProtocol;
        }

        return self::storedProtocol($row) === self::PROTOCOL_JSON2 ? self::PROTOCOL_JSON2 : self::PROTOCOL_JSONRPC;
    }

    /** The row's api_protocol normalised to 'json2' | 'jsonrpc' | 'rpc_pinned'. */
    public static function storedProtocol(array $row): string
    {
        $stored = strtolower(trim((string) ($row['api_protocol'] ?? '')));

        if ($stored === self::PROTOCOL_JSON2) {
            return self::PROTOCOL_JSON2;
        }
        if ($stored === self::STORED_JSONRPC_PINNED || $stored === self::STORED_JSONRPC_PINNED_ALT) {
            return self::STORED_JSONRPC_PINNED;
        }

        return self::PROTOCOL_JSONRPC;
    }

    public static function isPinned(array $row): bool
    {
        return self::storedProtocol($row) === self::STORED_JSONRPC_PINNED;
    }

    /** False for a row read before the 2.6.91 migration added api_protocol - callers then must not write it. */
    public static function hasProtocolColumn(array $row): bool
    {
        return array_key_exists('api_protocol', $row);
    }

    public static function label(string $protocol): string
    {
        return $protocol === self::PROTOCOL_JSON2 ? 'JSON-2' : 'JSON-RPC';
    }

    private static function build(array $row, string $protocol, string $apiKey): OdooConnectorInterface
    {
        $baseUrl = (string) ($row['base_url'] ?? '');
        $database = (string) ($row['database_name'] ?? '');

        if ($protocol === self::PROTOCOL_JSON2) {
            return new OdooJson2Connector($baseUrl, $database, $apiKey);
        }

        return new OdooLegacyRpcConnector($baseUrl, $database, (string) ($row['username'] ?? ''), $apiKey);
    }

    private static function decryptKey(array $row): string
    {
        $enc = (string) ($row['api_key_enc'] ?? '');
        if ($enc === '') {
            return '';
        }

        if (!function_exists('decryptSetting')) {
            throw new \LogicException('OdooConnectorFactory needs functions.php (decryptSetting) loaded');
        }

        return \decryptSetting($enc);
    }
}
