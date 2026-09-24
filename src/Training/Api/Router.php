<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;

/**
 * The single dispatcher behind agent/training_ajax.php?action=<name> (spec §3.2, §6.1).
 *
 * Order of checks, for every action:
 *   1  JSON + nosniff + no-store headers
 *   2  module on and module_training >= 1          (404 module_disabled / 403 forbidden)
 *   3  action name ^[a-z_]{3,40}$, looked up in the merged Routes/*.php tables
 *   4  method (405). POST also: Content-Type application/json (415), body <= 2 MB (413),
 *      valid JSON object (400), X-CSRF-Token matches the session (403 csrf), and a
 *      Sec-Fetch-Site header, IF the browser sent one, must be same-origin (403)
 *   5  session_write_close() - no training route writes the session, and holding the
 *      per-user session lock would serialise every autosave behind a long PDF render
 *   6  the route's own level (403), and KB routes also need KB access (403)
 *   7  handler(Ctx, ApiContext) => array, sent as {"ok":true,"data":…}
 *   8  errors: ApiException => its status and code; MariaDB 1062 => 422 validation with
 *      fields from Db::KEY_FIELDS; 1213 deadlock / 1205 lock wait => 409 busy; anything
 *      else => error_log() + 500 server (the message never reaches the client)
 *
 * Route files: src/Training/Api/Routes/<lane>.php each `return [name => spec]` where spec is
 *   ['handler' => 'Class::method', 'method' => 'GET'|'POST', 'level' => 1..3, 'kb' => bool?, 'raw' => bool?]
 * A name defined in two files is a load-time error. raw handlers stream their own response
 * (Core\Csv::send) and exit.
 */
final class Router
{
    public const MAX_BODY_BYTES = 2097152;

    public static function handle(\mysqli $db): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $action = '';
        try {
            Access::api(1);

            $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
            if (preg_match('/^[a-z_]{3,40}$/', $action) !== 1) {
                throw new ApiException(404, 'not_found', 'Unknown action.');
            }
            $routes = self::routes();
            if (!isset($routes[$action])) {
                throw new ApiException(404, 'not_found', 'Unknown action.');
            }
            $spec = $routes[$action];

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== $spec['method']) {
                header('Allow: ' . $spec['method']);
                throw new ApiException(405, 'validation', 'Method not allowed.');
            }

            if ($method === 'POST') {
                $input = self::readPost();
            } else {
                $input = $_GET;
                unset($input['action']);
            }

            session_write_close();

            if (Access::level() < $spec['level']) {
                throw ApiException::forbidden();
            }
            if (!empty($spec['kb']) && !Access::canUseKb()) {
                throw ApiException::forbidden("You don't have access to the Knowledge Base.");
            }

            $ctx = Access::ctx($db);
            $api = new ApiContext($method, $input, $ctx->settings);
            [$class, $fn] = explode('::', $spec['handler'], 2);
            $data = $class::$fn($ctx, $api);

            self::send(200, ['ok' => true, 'data' => ($data === [] || $data === null) ? new \stdClass() : $data]);
        } catch (ApiException $e) {
            $body = ['ok' => false, 'error' => [
                'code' => $e->errCode,
                'message' => $e->getMessage(),
                'fields' => $e->fields === [] ? new \stdClass() : $e->fields,
            ]];
            if ($e->data !== []) {
                $body['data'] = $e->data;
            }
            self::send($e->http, $body);
        } catch (\mysqli_sql_exception $e) {
            $errno = (int) $e->getCode();
            if ($errno === 1062) {
                self::send(422, ['ok' => false, 'error' => [
                    'code' => 'validation',
                    'message' => 'That is already in use.',
                    'fields' => self::duplicateFields($e->getMessage()),
                ]]);
            }
            if ($errno === 1213 || $errno === 1205) {
                self::send(409, ['ok' => false, 'error' => [
                    'code' => 'busy', 'message' => 'Someone else is saving; try again.', 'fields' => new \stdClass(),
                ]]);
            }
            self::serverError($action, $e);
        } catch (\Throwable $e) {
            self::serverError($action, $e);
        }
    }

    /**
     * Merged route table from every Routes/*.php file (memoised).
     *
     * @return array<string, array{handler:string, method:string, level:int, kb?:bool, raw?:bool}>
     */
    public static function routes(): array
    {
        static $routes = null;
        if ($routes !== null) {
            return $routes;
        }
        $merged = [];
        $files = glob(__DIR__ . '/Routes/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $table = require $file;
            if (!is_array($table)) {
                throw new \LogicException('Training route file ' . basename($file) . ' must return an array');
            }
            foreach ($table as $name => $spec) {
                if (isset($merged[$name])) {
                    throw new \LogicException("Training route '$name' is defined twice (" . basename($file) . ')');
                }
                if (!is_string($name) || preg_match('/^[a-z_]{3,40}$/', $name) !== 1
                    || !is_array($spec)
                    || !isset($spec['handler'], $spec['method'], $spec['level'])
                    || !in_array($spec['method'], ['GET', 'POST'], true)
                    || !is_int($spec['level']) || $spec['level'] < 1 || $spec['level'] > 3
                    || !is_string($spec['handler']) || !str_contains($spec['handler'], '::')
                    || !is_callable($spec['handler'])) {
                    throw new \LogicException("Training route '$name' in " . basename($file) . ' is malformed');
                }
                $merged[$name] = $spec;
            }
        }
        return $routes = $merged;
    }

    // --- Core actions (Routes/core.php) ------------------------------------------------------

    /** GET ping (L1): liveness, the caller's level, and a fresh CSRF token for the client to adopt. */
    public static function actionPing(Ctx $c, ApiContext $a): array
    {
        return [
            'level' => $c->level,
            'enabled' => true,
            'user_id' => $c->userId,
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ];
    }

    /** GET ledger_head (L3): the chain head, for Details views and ops. */
    public static function actionLedgerHead(Ctx $c, ApiContext $a): array
    {
        try {
            $head = Ledger::head($c->db);
        } catch (\RuntimeException) {
            throw new ApiException(404, 'not_found', 'The training ledger has not been initialised. Run the database update.');
        }
        return [
            'seq' => $head['seq'],
            'hash' => $head['hash'],
            'updated_at' => Clock::toIso($head['updated_at_utc'], true),
        ];
    }

    // ------------------------------------------------------------------------------------------

    private static function readPost(): array
    {
        $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with(trim($ctype), 'application/json')) {
            throw new ApiException(415, 'unsupported_type', 'Send the request as JSON.');
        }
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'too_large', 'That request is too large.');
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'too_large', 'That request is too large.');
        }
        try {
            $input = json_decode($raw === '' ? '{}' : $raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'validation', 'The request could not be read.');
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ApiException(400, 'validation', 'The request must be a JSON object.');
        }

        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            throw new ApiException(403, 'csrf', 'Your session changed. Try again.');
        }
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && $site !== 'same-origin') {
            throw new ApiException(403, 'forbidden', 'Cross-site request refused.');
        }
        return $input;
    }

    /** @return array<string, string>|\stdClass */
    private static function duplicateFields(string $message): array|\stdClass
    {
        if (preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", $message, $m) === 1) {
            $field = Db::KEY_FIELDS[$m[1]] ?? null;
            if ($field !== null) {
                return [$field => 'Already in use.'];
            }
        }
        return new \stdClass();
    }

    private static function serverError(string $action, \Throwable $e): never
    {
        error_log('Training API ' . ($action !== '' ? $action : '?') . ': ' . get_class($e) . ': ' . $e->getMessage());
        self::send(500, ['ok' => false, 'error' => [
            'code' => 'server', 'message' => 'Something went wrong. Try again.', 'fields' => new \stdClass(),
        ]]);
    }

    private static function send(int $status, array $body): never
    {
        if (Db::depth() > 0) {
            // A handler escaped with an open transaction (should be impossible via Db::tx).
            error_log('Training API: response sent with an open transaction');
        }
        http_response_code($status);
        try {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('Training API: response encode failed: ' . $e->getMessage());
            http_response_code(500);
            echo '{"ok":false,"error":{"code":"server","message":"Something went wrong. Try again.","fields":{}}}';
        }
        exit;
    }
}
