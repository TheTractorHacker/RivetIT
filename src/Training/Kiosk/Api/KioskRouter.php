<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskAuthException;
use ITFlow\Training\Kiosk\Core\KioskCsrf;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskRoleException;

/**
 * The single dispatcher behind kiosk/api.php?action=<name> (P3 spec §4.1). Order, every request:
 *
 *   1  JSON + nosniff + no-store (the bootstrap's api profile); Sec-Purpose prefetch => 503
 *   2  action ^[a-z_]{3,40}$ looked up in the routes merged from Routes/*.php (duplicate = fatal)
 *   3  method (405)
 *   4  Sec-Fetch-Site, if sent, must be same-origin (403); POST: application/json (415),
 *      body <= 512 KiB (413), a JSON object (400), Origin, if sent, = the app's base URL (403)
 *   5  auth: a ksess cookie on a route that lists a session role => KioskAuth::session()
 *      (KioskAuthException => 401 session_ended {reason} + cookie cleared; KioskRoleException =>
 *      403 wrong_role unless the route also lists `device`); `device` needs a valid device
 *      (403 device_not_enrolled + device cookie cleared); `anon` needs nothing. When this request
 *      found the device's temporary time up, both errors carry data.ended (epoch seconds) so the
 *      runtime opens /kiosk/?ended=… ("This device's training time ended at …")
 *   6  CSRF on EVERY non-anon request, GET included: X-Kiosk-Token must equal one of
 *      KioskCsrf::accepted(); routes listing `video` also accept KioskCsrf::checkVideo()
 *   7  handler(KioskCtx, ApiContext) => {"ok":true,"data":…}
 *   8  errors: ApiException => its status/code; MediaException::toApi(); 1062 => 422 validation
 *      (Db::KEY_FIELDS); 1213/1205 => 409 busy; anything else => error_log of the CLASS (plus
 *      the message only outside the PIN actions, §0.12) and 500 server. Input is never echoed.
 *
 * Route files: src/Training/Kiosk/Api/Routes/<lane>.php each `return [name => spec]`,
 *   spec = ['handler' => 'Class::method', 'method' => 'GET'|'POST', 'auth' => [anon|device|learner|trainer|checkin|handoff|video, …]]
 */
final class KioskRouter
{
    public const MAX_BODY_BYTES = 524288;
    public const AUTH_KINDS = ['anon', 'device', 'learner', 'trainer', 'checkin', 'handoff', 'video'];
    public const SESSION_ROLES = ['learner', 'trainer', 'checkin', 'handoff'];
    /** Actions whose exceptions are logged by class only (§0.12). Any action whose input carries a PIN-like key is added at runtime. */
    public const PIN_ACTIONS = ['pick', 'pin_login', 'setup_code_verify', 'pin_create', 'enroll_code', 'enroll_fleet', 'adopt_device'];
    private const SECRET_KEYS = ['pin', 'pin2', 'code', 'setup_token', 'token'];

    /** ['ended' => epoch] when this request found the device's temporary time up, else []. */
    private static function endedData(): array
    {
        $at = KioskAuth::expiredAt();
        $e = $at === null ? null : \ITFlow\Training\Kiosk\Core\KTime::epoch($at);
        return $e === null ? [] : ['ended' => (int) floor($e)];
    }

    public static function handle(KioskCtx $k): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $action = '';
        $quiet = true;
        try {
            if (self::isPrefetch()) {
                http_response_code(503);
                exit;
            }
            $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
            if (preg_match('/^[a-z_]{3,40}$/D', $action) !== 1) {
                $action = '';
                throw new ApiException(404, 'not_found', 'Unknown action.');
            }
            $routes = self::routes();
            if (!isset($routes[$action])) {
                throw new ApiException(404, 'not_found', 'Unknown action.');
            }
            $spec = $routes[$action];
            $auth = $spec['auth'];

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== $spec['method']) {
                header('Allow: ' . $spec['method']);
                throw new ApiException(405, 'validation', 'Method not allowed.');
            }
            $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
            if ($site !== null && $site !== 'same-origin') {
                throw new ApiException(403, 'forbidden', 'Cross-site request refused.');
            }
            if ($method === 'POST') {
                $input = self::readPost($k);
            } else {
                $input = $_GET;
                unset($input['action']);
            }
            $quiet = in_array($action, self::PIN_ACTIONS, true) || array_intersect(array_keys($input), self::SECRET_KEYS) !== [];

            // 5. auth
            $sessionRoles = array_values(array_intersect($auth, self::SESSION_ROLES));
            if (in_array('video', $auth, true) && !in_array('learner', $sessionRoles, true)) {
                $sessionRoles[] = 'learner';   // the video principal is a learner session holding the restricted token
            }
            $principal = null;
            if ($sessionRoles !== [] && isset($_COOKIE[KioskAuth::SESS_COOKIE])) {
                // An idle tick is not activity, and neither is `end` itself (its idle path checks last_seen).
                $touch = $action !== 'end' && !($action === 'lesson_tick' && empty($input['active']) && empty($input['playing']));
                try {
                    $k = $k->withKsess(KioskAuth::session($k, $sessionRoles, $touch));
                    $principal = 'session';
                } catch (KioskAuthException $e) {
                    KioskAuth::clearSessionCookie();
                    throw new ApiException(401, 'session_ended', 'Your session ended.', [], ['reason' => $e->reason] + self::endedData());
                } catch (KioskRoleException) {
                    if (!in_array('device', $auth, true)) {
                        throw new ApiException(403, 'wrong_role', 'That is not available in this mode.');
                    }
                }
            }
            if ($principal === null) {
                if (in_array('device', $auth, true)) {
                    if ($k->device === null) {
                        if (!KioskAuth::awaitingApproval()) {
                            KioskAuth::clearDeviceCookie();   // a fleet device waiting for approval keeps its cookie (2.6.151)
                        }
                        throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.', [], self::endedData());
                    }
                    $principal = 'device';
                } elseif (in_array('anon', $auth, true)) {
                    $principal = 'anon';
                } else {
                    throw new ApiException(401, 'session_ended', 'Your session ended.', [], ['reason' => 'missing']);
                }
            }

            // A portal device (minted for a signed-in portal login) never signs anyone in by name: without a session it may only end/set language.
            if ($principal === 'device' && ($k->device['kiosk_enroll_method'] ?? '') === 'portal' && !in_array($action, ['end', 'set_language'], true)) {
                throw new ApiException(403, 'forbidden', 'Start training from the portal.');
            }

            // 6. CSRF (every non-anon request, GET included)
            if ($principal !== 'anon') {
                $given = KioskCsrf::given();
                $ok = false;
                if ($given !== '') {
                    foreach (KioskCsrf::accepted($k, $auth) as $t) {
                        if (hash_equals($t, $given)) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok && in_array('video', $auth, true) && $principal === 'session') {
                        $ok = KioskCsrf::checkVideo($k, $given, $input, $action);
                    }
                }
                if (!$ok) {
                    throw new ApiException(403, 'csrf', 'This page is out of date. Reload and try again.');
                }
            }

            // A run an agent reset (Assignments > Reset progress): 409 run_reset, and the kiosk goes back to the course list.
            if ($principal === 'session') {
                \ITFlow\Training\Kiosk\Learn\RunReset::guard($k, $input);
            }

            // 7. handler
            $api = new ApiContext($method, $input, $k->core->settings);
            [$class, $fn] = explode('::', $spec['handler'], 2);
            $data = $class::$fn($k, $api);
            self::send(200, ['ok' => true, 'data' => ($data === [] || $data === null) ? new \stdClass() : $data]);
        } catch (ApiException $e) {
            self::sendApiError($e);
        } catch (\ITFlow\Training\Media\MediaException $e) {
            self::sendApiError($e->toApi());
        } catch (\mysqli_sql_exception $e) {
            $errno = (int) $e->getCode();
            if ($errno === 1062) {
                self::send(422, ['ok' => false, 'error' => [
                    'code' => 'validation', 'message' => 'That is already in use.', 'fields' => self::duplicateFields($e->getMessage()),
                ]]);
            }
            if ($errno === 1213 || $errno === 1205) {
                self::send(409, ['ok' => false, 'error' => ['code' => 'busy', 'message' => 'Busy - try again.', 'fields' => new \stdClass()]]);
            }
            self::serverError($action, $e, $quiet);
        } catch (\Throwable $e) {
            self::serverError($action, $e, $quiet);
        }
    }

    /** Sec-Purpose / Purpose containing "prefetch" (Cloudflare Speed Brain, browser prefetch). */
    public static function isPrefetch(): bool
    {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ', 'HTTP_X_PURPOSE'] as $h) {
            if (isset($_SERVER[$h]) && stripos((string) $_SERVER[$h], 'prefetch') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merged route table from every Routes/*.php (memoised). A duplicate or malformed route is a
     * load-time LogicException.
     *
     * @return array<string, array{handler:string, method:string, auth:list<string>}>
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
                throw new \LogicException('Kiosk route file ' . basename($file) . ' must return an array');
            }
            foreach ($table as $name => $spec) {
                if (isset($merged[$name])) {
                    throw new \LogicException("Kiosk route '$name' is defined twice (" . basename($file) . ')');
                }
                if (!is_string($name) || preg_match('/^[a-z_]{3,40}$/D', $name) !== 1
                    || !is_array($spec)
                    || !isset($spec['handler'], $spec['method'], $spec['auth'])
                    || !in_array($spec['method'], ['GET', 'POST'], true)
                    || !is_array($spec['auth']) || $spec['auth'] === [] || !array_is_list($spec['auth'])
                    || array_diff($spec['auth'], self::AUTH_KINDS) !== []
                    || !is_string($spec['handler']) || !str_contains($spec['handler'], '::')
                    || !is_callable($spec['handler'])) {
                    throw new \LogicException("Kiosk route '$name' in " . basename($file) . ' is malformed');
                }
                if (in_array('video', $spec['auth'], true) && ($spec['method'] !== 'POST' || !in_array($name, KioskCsrf::VIDEO_ACTIONS, true))) {
                    throw new \LogicException("Kiosk route '$name': the video principal is only for the POST video actions");
                }
                $merged[$name] = $spec;
            }
        }
        return $routes = $merged;
    }

    private static function readPost(KioskCtx $k): array
    {
        $ctype = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
        if (!str_starts_with($ctype, 'application/json')) {
            throw new ApiException(415, 'validation', 'Send the request as JSON.');
        }
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'validation', 'That request is too large.');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && !in_array(rtrim((string) $origin, '/'), self::allowedOrigins($k), true)) {
            throw new ApiException(403, 'forbidden', 'Cross-site request refused.');
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'validation', 'That request is too large.');
        }
        try {
            $input = json_decode($raw === '' ? '{}' : $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'validation', 'The request could not be read.');
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ApiException(400, 'validation', 'The request must be a JSON object.');
        }
        return $input;
    }

    /**
     * The app's own origin: 'https://' + $config_base_url. When config.php says the site is not
     * HTTPS-only ($config_https_only false - a local test server), the http:// form is accepted too.
     *
     * @return list<string>
     */
    public static function allowedOrigins(KioskCtx $k): array
    {
        $base = rtrim($k->core->baseUrl, '/');
        $out = [$base];
        if (empty($GLOBALS['config_https_only']) && str_starts_with($base, 'https://')) {
            $out[] = 'http://' . substr($base, 8);
        }
        return $out;
    }

    private static function sendApiError(ApiException $e): never
    {
        $body = ['ok' => false, 'error' => [
            'code' => $e->errCode,
            'message' => $e->getMessage(),
            'fields' => $e->fields === [] ? new \stdClass() : $e->fields,
        ]];
        if ($e->data !== []) {
            $body['data'] = $e->data;
        }
        self::send($e->http, $body);
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

    private static function serverError(string $action, \Throwable $e, bool $quiet): never
    {
        error_log('Kiosk API ' . ($action !== '' ? $action : '?') . ': ' . get_class($e) . ($quiet ? '' : ': ' . $e->getMessage()));
        self::send(500, ['ok' => false, 'error' => ['code' => 'server', 'message' => 'Something went wrong. Try again.', 'fields' => new \stdClass()]]);
    }

    private static function send(int $status, array $body): never
    {
        if (Db::depth() > 0) {
            error_log('Kiosk API: response sent with an open transaction');
        }
        http_response_code($status);
        try {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            error_log('Kiosk API: response encode failed');
            http_response_code(500);
            echo '{"ok":false,"error":{"code":"server","message":"Something went wrong. Try again.","fields":{}}}';
        }
        exit;
    }
}
