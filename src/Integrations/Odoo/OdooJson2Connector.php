<?php

namespace ITFlow\Integrations\Odoo;

/**
 * Odoo 19+ External JSON-2 API.
 *
 *   POST {base}/json/2/{model}/{method}
 *   Authorization: bearer <API key>
 *   X-Odoo-Database: <database>
 *   Content-Type: application/json
 *   body: the method's arguments by name, plus 'ids' (recordset methods) and 'context'
 *
 * A 2xx response body is the method's return value as plain JSON - a list,
 * dict, number, bool, string or null. Anything else is an error whose JSON
 * body carries {name, message, arguments, context, debug}; only `message`
 * is ever surfaced (`debug` is a server traceback).
 *
 * The API key identifies the Odoo user on every request, so there is no
 * login round trip and no username. The base URL must be https:// - the key
 * travels in a header on every call. The one exception is
 * 'allow_http_loopback' => true, which accepts http:// on 127.0.0.1/::1/
 * localhost only and exists for the local mock-server tests; nothing in the
 * app sets it (OdooConnectorFactory never does).
 */
final class OdooJson2Connector extends AbstractOdooConnector
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    private readonly bool $allowHttpLoopback;

    /**
     * @param array $options 'connect_timeout', 'timeout' (seconds; defaults 2 and 30),
     *                       'allow_http_loopback' (tests only, see class comment)
     */
    public function __construct(string $baseUrl, string $database, string $apiKey, array $options = [])
    {
        parent::__construct($baseUrl, $database, $apiKey, $options, ['allow_http_loopback']);
        $this->allowHttpLoopback = !empty($options['allow_http_loopback']);
    }

    public function protocol(): string
    {
        return OdooConnectorFactory::PROTOCOL_JSON2;
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed
    {
        if ($args !== []) {
            throw new \InvalidArgumentException(
                "JSON-2 takes named arguments only - pass them in \$kwargs ('ids' for recordset methods), not positional \$args ($model.$method)"
            );
        }
        self::assertCallable($model, $method, $kwargs);
        $this->assertTransport();
        $this->assertHasKey();
        self::assertHeaderSafe($this->apiKey, 'API key');

        [$status, $body, $redirect] = $this->httpPost(
            $this->baseUrl . '/json/2/' . $model . '/' . $method,
            $this->headers(true),
            $this->encodeJson(self::objectOrEmpty($kwargs)),
            $opts
        );

        if ($status >= 200 && $status < 300) {
            return $this->decodeJson($body, "$model.$method");
        }

        throw $this->httpError($status, $body, $redirect);
    }

    /**
     * JSON-2 has no version endpoint of its own. /web/webclient/version_info
     * is the web client's unauthenticated JSON route (the same payload as
     * common.version) and is not part of the deprecated /jsonrpc API. No
     * Authorization header: the key is never sent where it is not needed.
     */
    protected function fetchServerVersion(array $opts): ?array
    {
        if (!$this->transportAllowed()) {
            return null;
        }

        [$status, $body] = $this->httpPost(
            $this->baseUrl . '/web/webclient/version_info',
            $this->headers(false),
            $this->encodeJson(['jsonrpc' => '2.0', 'method' => 'call', 'params' => new \stdClass(), 'id' => random_int(1, PHP_INT_MAX)]),
            $opts
        );

        if ($status < 200 || $status >= 300) {
            return null;
        }

        $data = $this->decodeJson($body, 'version_info');

        return is_array($data) ? self::normalizeVersion($data['result'] ?? null) : null;
    }

    private function headers(bool $withKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            // No "Expect: 100-continue" round trip on bodies over 1 KB.
            'Expect:',
        ];

        if ($this->database !== '') {
            self::assertHeaderSafe($this->database, 'database name');
            $headers[] = 'X-Odoo-Database: ' . $this->database;
        }
        if ($withKey) {
            $headers[] = 'Authorization: bearer ' . $this->apiKey;
        }

        return $headers;
    }

    private function transportAllowed(): bool
    {
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && $this->allowHttpLoopback && in_array(strtolower($parts['host']), self::LOOPBACK_HOSTS, true);
    }

    private function assertTransport(): void
    {
        if (!$this->transportAllowed()) {
            throw new \RuntimeException('JSON-2 needs an https:// base URL (the API key is sent with every request)');
        }
    }

    private function httpError(int $status, string $body, ?string $redirect): \RuntimeException
    {
        $decoded = json_decode($body, true);
        $odooMessage = (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '')
            ? $decoded['message']
            : null;
        $suffix = $odooMessage !== null ? ": $odooMessage" : '';

        if ($status === 401) {
            return new OdooAuthException($this->message("Odoo rejected the API key (HTTP 401)$suffix"), 401);
        }
        if ($status === 403) {
            return new OdooAuthException($this->message("Odoo denied access (HTTP 403)$suffix"), 403);
        }
        if ($status >= 300 && $status < 400) {
            $to = $redirect !== null ? ' to ' . $redirect : '';
            return new \RuntimeException($this->message("Odoo redirected the request (HTTP $status$to) - check the base URL"), $status);
        }
        if ($status === 404 && $odooMessage === null) {
            return new \RuntimeException(
                $this->message('Odoo has no JSON-2 endpoint at this address (HTTP 404) - JSON-2 needs Odoo 19 or later and the correct database name'),
                404
            );
        }

        return new \RuntimeException($this->message("Odoo error (HTTP $status)$suffix"), $status);
    }
}
