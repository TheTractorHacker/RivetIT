<?php

namespace ITFlow\Integrations\Odoo;

/**
 * Odoo's legacy JSON-RPC external API - the behaviour OdooClient has always
 * had, moved here unchanged apart from per-call timeouts, scalar results and
 * an X-Odoo-Database header (see rpc()):
 *
 *   POST {base}/jsonrpc  {"jsonrpc":"2.0","method":"call","params":{"service":..,"method":..,"args":[..]}}
 *   common.login(db, username, api_key)                          -> uid (cached for this instance)
 *   object.execute_kw(db, uid, api_key, model, method, args, kwargs)
 *
 * Works on every Odoo version the directory sync has been used with. On
 * Odoo 19 each call logs a deprecation warning server-side, and the endpoint
 * is scheduled for removal in Odoo 22 - OdooJson2Connector is the successor.
 *
 * Named-argument calls ($kwargs, see OdooConnectorInterface) map onto
 * execute_kw's kwargs, except the arguments the pre-connector OdooClient
 * always sent positionally, which are moved into $args so the request body
 * stays exactly what it was: a recordset method's 'ids', and a search
 * method's 'domain' (search_read(domain=...) is accepted by name too, but
 * the default sync path has only ever been run with it positional).
 */
final class OdooLegacyRpcConnector extends AbstractOdooConnector
{
    /** error.data.name values that mean "bad credentials" / "not allowed". */
    private const AUTH_ERRORS = ['odoo.exceptions.AccessDenied', 'odoo.exceptions.AccessError'];

    /** Model methods whose first parameter is the domain. */
    private const DOMAIN_FIRST_METHODS = ['search', 'search_read', 'search_count'];

    private ?int $uid = null;

    /**
     * @param array $options 'connect_timeout', 'timeout' (seconds; defaults 2 and 30)
     */
    public function __construct(
        string $baseUrl,
        string $database,
        private readonly string $username,
        string $apiKey,
        array $options = []
    ) {
        parent::__construct($baseUrl, $database, $apiKey, $options);
    }

    public function protocol(): string
    {
        return OdooConnectorFactory::PROTOCOL_JSONRPC;
    }

    /** The Odoo user id from common.login, once a call has logged in; null before that. */
    public function uid(): ?int
    {
        return $this->uid;
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed
    {
        self::assertCallable($model, $method, $kwargs);
        $this->authenticate($opts);

        if (array_key_exists('ids', $kwargs)) {
            array_unshift($args, $kwargs['ids']);
            unset($kwargs['ids']);
        } elseif ($args === [] && array_key_exists('domain', $kwargs) && in_array($method, self::DOMAIN_FIRST_METHODS, true)) {
            $args = [$kwargs['domain']];
            unset($kwargs['domain']);
        }

        return $this->rpc('object', 'execute_kw', [
            $this->database, $this->uid, $this->apiKey, $model, $method, array_values($args), self::objectOrEmpty($kwargs),
        ], $opts, "$model.$method");
    }

    protected function fetchServerVersion(array $opts): ?array
    {
        return self::normalizeVersion($this->rpc('common', 'version', [], $opts, 'common.version'));
    }

    private function authenticate(array $opts): void
    {
        if ($this->uid !== null) {
            return;
        }

        $this->assertHasKey();

        $result = $this->rpc('common', 'login', [$this->database, $this->username, $this->apiKey], $opts, 'common.login');
        if (!is_int($result) || $result <= 0) {
            throw new OdooAuthException('Odoo login failed - check database name, username, and API key.');
        }

        $this->uid = $result;
    }

    private function rpc(string $service, string $method, array $args, array $opts, string $what): mixed
    {
        $payload = $this->encodeJson([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => random_int(1, PHP_INT_MAX),
        ]);

        // X-Odoo-Database names the database the args already name. From Odoo 18
        // /jsonrpc lives in a per-database module, so a server hosting more than
        // one database answers 404 "No database is selected" without it (seen on
        // the live Odoo 19 staging server once it gained a second database).
        // Older servers ignore the header; the request body is unchanged.
        $headers = ['Content-Type: application/json'];
        if ($this->database !== '') {
            self::assertHeaderSafe($this->database, 'database name');
            $headers[] = 'X-Odoo-Database: ' . $this->database;
        }

        [$status, $body] = $this->httpPost($this->baseUrl . '/jsonrpc', $headers, $payload, $opts);

        // /jsonrpc reports Odoo errors inside a 200. A non-2xx here comes from
        // in front of Odoo (proxy/WAF/gateway) or means there is no such route.
        if ($status === 401 || $status === 403) {
            throw new OdooAuthException($this->message("Odoo refused the request (HTTP $status) for $what"), $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($this->message("Odoo returned HTTP $status for $what"), $status);
        }

        $data = $this->decodeJson($body, $what);
        if (!is_array($data)) {
            throw new \RuntimeException("Unexpected response from Odoo for $what");
        }

        if (isset($data['error'])) {
            $error = is_array($data['error']) ? $data['error'] : [];
            $errorData = isset($error['data']) && is_array($error['data']) ? $error['data'] : [];

            $message = $errorData['message'] ?? $error['message'] ?? null;
            if (!is_string($message) || trim($message) === '') {
                $message = 'Unknown Odoo error';
            }

            $name = $errorData['name'] ?? '';
            if (is_string($name) && in_array($name, self::AUTH_ERRORS, true)) {
                throw new OdooAuthException($this->message("Odoo error: $message"));
            }
            throw new \RuntimeException($this->message("Odoo error: $message"));
        }

        if (!array_key_exists('result', $data)) {
            throw new \RuntimeException("Unexpected response from Odoo for $what");
        }

        return $data['result'];
    }
}
