<?php

namespace ITFlow\Integrations\Odoo;

/**
 * One wire protocol for talking to Odoo's external API (LMS plan A7).
 *
 * Two implementations:
 *   - OdooJson2Connector: Odoo 19+'s JSON-2 API (POST /json/2/<model>/<method>,
 *     bearer API key). The long-term path.
 *   - OdooLegacyRpcConnector: the /jsonrpc endpoint (common.login, then
 *     object.execute_kw) the directory sync has always used. Odoo 19 still
 *     serves it but logs a deprecation warning per call; removal is planned
 *     for Odoo 22.
 * OdooConnectorFactory::fromRow() picks one from odoo_integrations.api_protocol.
 *
 * CALLING CONVENTION - pass arguments by name, in $kwargs. JSON-2 has no
 * positional arguments at all, so a call written that way works unchanged on
 * both connectors:
 *   - model methods (search_read, search_count, context_get...): the method's
 *     own parameter names, e.g. ['domain' => [...], 'fields' => [...], 'limit' => 100];
 *   - recordset methods (read, write...): the record ids go in $kwargs['ids'],
 *     e.g. ['ids' => [7], 'fields' => ['name']];
 *   - $kwargs['context'] is the Odoo context on both protocols.
 * $args (positional) exists only for the legacy connector and is refused by
 * JSON-2 with an InvalidArgumentException.
 *
 * CONSTRUCTION NEVER THROWS for bad configuration (non-https URL, empty key,
 * unreachable host): those surface from call() as a \RuntimeException, so a
 * caller that builds a connector outside its try block (cron/cron.php) can't
 * be taken down by a bad settings row.
 *
 * Errors: every failure a caller should handle is a \RuntimeException - Odoo
 * refusing the credentials or the access is the OdooAuthException subclass.
 * \InvalidArgumentException means a programming error (bad model/method name,
 * positional args on JSON-2, bad timeout option), not an Odoo condition.
 */
interface OdooConnectorInterface
{
    /** Seconds allowed to establish the connection (DNS + TCP + TLS). */
    public const DEFAULT_CONNECT_TIMEOUT = 2;

    /** Seconds allowed for the whole request, connection included. */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Calls $model.$method on Odoo and returns its decoded JSON result, which
     * can be any JSON value: a list of records, a dict, an int (search_count),
     * a bool (write), a string, or null.
     *
     * @param array $args   positional arguments - legacy connector only, see above
     * @param array $kwargs named arguments; 'ids' for recordset methods, 'context' for the context
     * @param array $opts   per-call 'connect_timeout' and 'timeout', in seconds
     *                      (int or float), overriding the connector's defaults
     *
     * @throws OdooAuthException         Odoo rejected the API key/login or denied access
     * @throws \RuntimeException         transport failure, timeout, or any other Odoo error
     * @throws \InvalidArgumentException programming error (see class comment)
     */
    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed;

    /**
     * The Odoo server's version, fetched without credentials and cached per
     * instance, or null when it could not be read. Never throws.
     *
     * @return array{server_version:string, server_version_info:array, server_serie:string}|null
     *         e.g. ['server_version' => '19.0+e', 'server_version_info' => [19, 0, 0, 'final', 0, 'e'],
     *               'server_serie' => '19.0'] - the shape of Odoo's own common.version()
     */
    public function serverVersion(): ?array;

    /** OdooConnectorFactory::PROTOCOL_JSON2 or OdooConnectorFactory::PROTOCOL_JSONRPC. */
    public function protocol(): string;
}
