<?php

namespace ITFlow\Integrations\Odoo;

/**
 * Shared plumbing for the two Odoo connectors: per-call cURL timeouts, JSON
 * encoding/decoding that never leaks a \JsonException (it is not a
 * \RuntimeException, so callers' catch blocks would miss it), error-message
 * cleanup, and the per-instance server-version cache.
 *
 * Every exception message built here goes through message(), which strips
 * control characters, caps the length and scrubs the API key - an Odoo or
 * proxy error body is echoed into flash messages, last_test_error and the
 * audit trail, none of which may ever carry the key.
 */
abstract class AbstractOdooConnector implements OdooConnectorInterface
{
    private const KNOWN_OPTIONS = ['connect_timeout', 'timeout'];
    private const MAX_TIMEOUT = 600;
    private const MAX_MESSAGE_LENGTH = 300;

    /** Version lookups are a nicety - never let one hold a page for the full call timeout. */
    private const VERSION_TIMEOUT = 10;

    protected readonly string $baseUrl;
    protected readonly string $database;
    protected readonly string $apiKey;
    private readonly float $connectTimeout;
    private readonly float $timeout;

    private bool $versionFetched = false;
    private ?array $version = null;

    /**
     * @param array $options 'connect_timeout' / 'timeout' (seconds) defaults for every call,
     *                       plus whatever extra keys the subclass declares in $extraOptions
     */
    protected function __construct(string $baseUrl, string $database, string $apiKey, array $options, array $extraOptions = [])
    {
        $unknown = array_diff(array_keys($options), self::KNOWN_OPTIONS, $extraOptions);
        if ($unknown) {
            throw new \InvalidArgumentException(static::class . ': unknown option(s) ' . implode(', ', $unknown));
        }

        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->database = trim($database);
        $this->apiKey = trim($apiKey);

        [$this->connectTimeout, $this->timeout] = self::readTimeouts(
            $options,
            (float) self::DEFAULT_CONNECT_TIMEOUT,
            (float) self::DEFAULT_TIMEOUT
        );
    }

    public function serverVersion(): ?array
    {
        if (!$this->versionFetched) {
            $this->versionFetched = true;
            try {
                $this->version = $this->fetchServerVersion(['timeout' => min($this->timeout, self::VERSION_TIMEOUT)]);
            } catch (\Exception $e) {
                $this->version = null;
            }
        }

        return $this->version;
    }

    /**
     * Unauthenticated version lookup for serverVersion(). May throw - the
     * caller turns any failure into null.
     */
    abstract protected function fetchServerVersion(array $opts): ?array;

    /**
     * POSTs $body and returns [HTTP status, response body, redirect target|null].
     * Redirects are never followed: a 30x on a POST would be replayed as a GET
     * (a confusing 405 later), and following one would carry the bearer key to
     * whatever host the Location header names.
     *
     * @return array{0:int,1:string,2:?string}
     */
    protected function httpPost(string $url, array $headers, string $body, array $opts): array
    {
        $unknown = array_diff(array_keys($opts), self::KNOWN_OPTIONS);
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown Odoo call option(s): ' . implode(', ', $unknown));
        }
        [$connectTimeout, $timeout] = self::readTimeouts($opts, $this->connectTimeout, $this->timeout);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise cURL for the Odoo request');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            // Required for sub-second timeouts with the synchronous resolver,
            // which otherwise implements them with SIGALRM (and gives up at once).
            CURLOPT_NOSIGNAL => true,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $errno = curl_errno($ch);
            $curlError = curl_error($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new \RuntimeException($this->message("Odoo at {$this->displayBase()} did not respond in time: $curlError"));
            }
            throw new \RuntimeException($this->message("Could not reach Odoo at {$this->displayBase()}: $curlError"));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        return [$status, (string) $response, is_string($redirect) && $redirect !== '' ? $redirect : null];
    }

    protected function encodeJson(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Could not encode the Odoo request as JSON: ' . $e->getMessage());
        }
    }

    protected function decodeJson(string $body, string $what): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Unexpected response from Odoo for $what (not JSON)");
        }
    }

    /**
     * Odoo model and method names go into the JSON-2 URL path, so both are
     * held to Odoo's own naming rules. A leading underscore is a private
     * method, which neither API will run.
     */
    protected static function assertCallable(string $model, string $method, array $kwargs): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $model)) {
            throw new \InvalidArgumentException("Invalid Odoo model name: $model");
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $method)) {
            throw new \InvalidArgumentException("Invalid Odoo method name: $method");
        }
        if ($kwargs !== [] && array_is_list($kwargs)) {
            throw new \InvalidArgumentException("Odoo \$kwargs must be keyed by argument name ($model.$method)");
        }
    }

    /** No API key means no call can succeed - say so instead of letting Odoo answer with a generic refusal. */
    protected function assertHasKey(): void
    {
        if ($this->apiKey === '') {
            throw new OdooAuthException('No Odoo API key is available - save one on the Odoo card (or check that config.php still has this install\'s settings encryption key).');
        }
    }

    /** A header value with CR/LF (or any control character) could smuggle extra headers into the request. */
    protected static function assertHeaderSafe(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \RuntimeException("The Odoo $label contains control characters");
        }
    }

    /** {} rather than [] for an empty argument map or context: Odoo expects a JSON object there. */
    protected static function objectOrEmpty(array $kwargs): array|\stdClass
    {
        if (array_key_exists('context', $kwargs) && $kwargs['context'] === []) {
            $kwargs['context'] = new \stdClass();
        }

        return $kwargs === [] ? new \stdClass() : $kwargs;
    }

    /**
     * Normalises either version payload - Odoo's common.version() /
     * /web/webclient/version_info ({server_version, server_version_info,
     * server_serie}) - into the interface's documented shape.
     */
    protected static function normalizeVersion(mixed $raw): ?array
    {
        if (!is_array($raw) || !isset($raw['server_version']) || !is_string($raw['server_version'])) {
            return null;
        }

        $info = isset($raw['server_version_info']) && is_array($raw['server_version_info']) ? array_values($raw['server_version_info']) : [];

        $serie = $raw['server_serie'] ?? null;
        if (!is_string($serie) || $serie === '') {
            $serie = (isset($info[0], $info[1]) && is_int($info[0])) ? $info[0] . '.' . $info[1] : '';
        }

        return [
            'server_version' => mb_substr($raw['server_version'], 0, 50),
            'server_version_info' => $info,
            'server_serie' => mb_substr($serie, 0, 20),
        ];
    }

    /**
     * Clean an exception message for display and storage: valid UTF-8, no
     * control characters (Odoo tracebacks are multi-line), bounded length,
     * and never the API key.
     */
    protected function message(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        // (A "key" of a few characters would redact half of every message; real keys are 40 hex chars.)
        if (strlen($this->apiKey) >= 8) {
            $text = str_replace($this->apiKey, '[redacted]', $text);
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - 1)) . '…';
        }

        return $text;
    }

    /** The base URL for messages, minus any user:password@ part. */
    protected function displayBase(): string
    {
        return preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', $this->baseUrl) ?? $this->baseUrl;
    }

    /** @return array{0:float,1:float} [connect timeout, total timeout] in seconds */
    private static function readTimeouts(array $opts, float $defaultConnect, float $defaultTotal): array
    {
        $values = [];
        foreach (['connect_timeout' => $defaultConnect, 'timeout' => $defaultTotal] as $key => $default) {
            if (!array_key_exists($key, $opts)) {
                $values[] = $default;
                continue;
            }
            $v = $opts[$key];
            if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v <= 0 || $v > self::MAX_TIMEOUT) {
                throw new \InvalidArgumentException("Odoo option '$key' must be a number of seconds between 0 and " . self::MAX_TIMEOUT);
            }
            $values[] = (float) $v;
        }

        return $values;
    }
}
