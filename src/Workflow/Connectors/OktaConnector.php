<?php

namespace ITFlow\Workflow\Connectors;

/**
 * Okta (Management API, SSWS token): create a user, disable a user, add a user to groups. UNVERIFIED LIVE: written to Okta's documented API and
 * exercised only against a local mock (tests/mock/okta_mock.php); no real Okta org has been contacted.
 *
 * Safety rules, the same ones the Entra actions follow:
 *   - OFF unless an administrator ticks "Allow workflows to change Okta" (settings.config_lifecycle_okta_actions) AND saves an Okta domain and an API token;
 *     isConfigured() is false otherwise and every call refuses with a hint (a manual task, never a silent success). Nothing is sent when off.
 *   - never deletes. "Disable" = suspend (reversible in Okta: Unsuspend) plus clearing the user's sessions. Suspending needs an ACTIVE user; any other status is
 *     refused with its name so a person finishes the job (an account that cannot be found is a failure too, never a "done").
 *   - the account's profile.department must equal the person's department (fail closed, like Entra's IT-9 check).
 *   - create makes a STAGED user (no password, no email sent); "enabled" activates it with sendEmail=false so Okta does not mail a real person from a test or a typo.
 *     The user sets their credential through the org's normal activation flow; RivetIT never creates, sees or logs a password.
 *   - the API token is only ever sent in the Authorization header over https to the configured Okta domain, and never put in a message, log or exception.
 *   - repeat calls are safe: an existing user is not created twice, a user already in a group / already suspended is reported as done.
 */
final class OktaConnector implements GroupConnector
{
    private const MAX_GROUPS = 25;

    /** @var callable(string,string,array,?string):array{status:int,body:string} */
    private $http;
    /** @var callable():bool */
    private $allowed;
    /** @var callable(string,string,string,array):void */
    private $audit;

    /**
     * @param callable|null $http  (method, url, headers, body) => ['status'=>int,'body'=>string]; default is curl (https only, no redirects). Tests inject a mock.
     * @param callable|null $allowed  () => bool, the "Allow workflows to change Okta" switch, read at call time
     * @param callable|null $audit  (event, action, summary, metadata): void
     */
    public function __construct(private string $domain, private string $token, ?callable $allowed = null, ?callable $http = null, ?callable $audit = null, private bool $allowHttp = false)
    {
        $this->domain = strtolower(trim($domain));
        $this->allowed = $allowed ?? static fn (): bool => false;
        $this->http = $http ?? [$this, 'curl'];
        $this->audit = $audit ?? static function (): void {
        };
    }

    /** A bare Okta host name: letters, digits, dots and hyphens, at least two labels, no scheme, port, path or credentials. */
    public static function validDomain(string $domain): bool
    {
        $d = strtolower(trim($domain));

        return strlen($d) <= 253 && (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d);
    }

    public function key(): string
    {
        return 'okta';
    }

    public function label(): string
    {
        return 'Okta';
    }

    public function isConfigured(): bool
    {
        return self::validDomain($this->domain) && trim($this->token) !== '' && ($this->allowed)();
    }

    public function isConfiguredHint(): string
    {
        if (!($this->allowed)()) {
            return "'Allow workflows to change Okta' is off; nothing was sent to Okta. Do this step by hand, or turn the setting on (Administration > Employee Workflow Templates).";
        }
        if (!self::validDomain($this->domain) || trim($this->token) === '') {
            return 'Okta is not set up: save the Okta domain and an API token (Administration > Employee Workflow Templates). Nothing was sent.';
        }

        return 'Okta is connected.';
    }

    // ----- the three operations ---------------------------------------------------------------------------------------------------

    public function provision(array $spec): string
    {
        $this->assertReady();
        $login = $this->login((string) ($spec['identifier'] ?? ''));
        $existing = $this->findUser($login);
        if ($existing !== null) {
            return "Okta user $login already exists (status " . ($existing['status'] ?? 'unknown') . '); nothing created, no group changed';
        }
        $name = trim((string) ($spec['display_name'] ?? ''));
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts ? mb_substr(array_shift($parts), 0, 100) : $login;
        $last = $parts ? mb_substr(implode(' ', $parts), 0, 100) : $first; // Okta requires a last name
        $groupIds = $this->resolveGroups((array) ($spec['groups'] ?? []));
        $profile = ['firstName' => $first, 'lastName' => $last, 'email' => $login, 'login' => $login];
        if (trim((string) ($spec['department'] ?? '')) !== '') {
            $profile['department'] = mb_substr(trim((string) $spec['department']), 0, 200);
        }
        $r = $this->call('POST', '/api/v1/users?activate=false', ['profile' => $profile, 'groupIds' => $groupIds], [200]);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Okta created no user (no id in the answer); check the Okta System Log');
        }
        $state = 'staged (not active)';
        if (!empty($spec['enabled'])) {
            $this->call('POST', '/api/v1/users/' . rawurlencode($id) . '/lifecycle/activate?sendEmail=false', null, [200]);
            $state = 'activated (no email sent)';
        }
        $this->audit('okta.user_created', 'created', "Okta user $login created, $state, " . count($groupIds) . ' group(s)', ['okta_id' => $id, 'groups' => count($groupIds)]);

        return "Okta user $login created, $state" . ($groupIds ? ', added to ' . count($groupIds) . ' group(s)' : '') . '. No password was created by RivetIT.';
    }

    public function deprovision(string $identifier, string $expectedDepartment): string
    {
        $this->assertReady();
        $login = $this->login($identifier);
        $user = $this->findUser($login);
        if ($user === null) {
            throw new \RuntimeException("no Okta user $login; nothing was disabled");
        }
        $this->assertDepartment($user, $expectedDepartment, $login);
        $id = (string) $user['id'];
        $status = strtoupper((string) ($user['status'] ?? ''));
        $did = [];
        if ($status === 'SUSPENDED' || $status === 'DEPROVISIONED') {
            $did[] = 'already ' . strtolower($status);
        } elseif ($status === 'ACTIVE') {
            $this->call('POST', '/api/v1/users/' . rawurlencode($id) . '/lifecycle/suspend', null, [200]);
            $did[] = 'suspended';
        } else {
            throw new \RuntimeException("Okta user $login has status $status, which cannot be suspended automatically; nothing was changed. Disable it by hand in Okta");
        }
        // Even an already-suspended account may still hold a session; clearing is idempotent.
        $this->call('DELETE', '/api/v1/users/' . rawurlencode($id) . '/sessions', null, [200, 204]);
        $did[] = 'sessions cleared';
        $this->audit('okta.user_disabled', 'disabled', "Okta user $login: " . implode(', ', $did), ['okta_id' => $id, 'previous_status' => $status]);

        return "Okta user $login " . implode(', ', $did) . ' (never deleted)';
    }

    public function addToGroups(string $identifier, array $groups, string $expectedDepartment): string
    {
        $this->assertReady();
        $login = $this->login($identifier);
        $user = $this->findUser($login);
        if ($user === null) {
            throw new \RuntimeException("no Okta user $login; no group was changed");
        }
        $this->assertDepartment($user, $expectedDepartment, $login);
        $ids = $this->resolveGroups($groups);
        if (!$ids) {
            throw new \RuntimeException('no group was named; nothing was changed');
        }
        foreach ($ids as $gid) {
            $this->call('PUT', '/api/v1/groups/' . rawurlencode($gid) . '/users/' . rawurlencode((string) $user['id']), null, [200, 204]); // already a member is a 204 too
        }
        $this->audit('okta.group_member_added', 'added', "Okta user $login added to " . count($ids) . ' group(s)', ['okta_id' => (string) $user['id'], 'groups' => count($ids)]);

        return "Okta user $login is in " . count($ids) . ' group(s)';
    }

    // ----- internals --------------------------------------------------------------------------------------------------------------

    private function assertReady(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException($this->isConfiguredHint());
        }
    }

    private function login(string $identifier): string
    {
        $login = trim($identifier);
        if ($login === '' || strlen($login) > 100 || !filter_var($login, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('the Okta account name must be an email-style login (usually the work email); got "' . mb_substr($login, 0, 60) . '"');
        }

        return strtolower($login);
    }

    /** @param array<string,mixed> $user */
    private function assertDepartment(array $user, string $expected, string $login): void
    {
        $want = mb_strtolower(trim($expected));
        $have = mb_strtolower(trim((string) ($user['profile']['department'] ?? '')));
        if ($want === '') {
            throw new \RuntimeException("the person has no department, so the Okta account $login cannot be tied to them; nothing was changed");
        }
        if ($have !== $want) {
            throw new \RuntimeException("the Okta account $login is not in this person's department (the Okta department attribute must match); nothing was changed");
        }
    }

    /** @return array<string,mixed>|null */
    private function findUser(string $login): ?array
    {
        $r = $this->call('GET', '/api/v1/users/' . rawurlencode($login), null, [200, 404]);
        if ($r === null || !isset($r['id'])) {
            return null;
        }
        // The path accepts login, id or short name; insist the answer really is this login.
        if (strtolower((string) ($r['profile']['login'] ?? '')) !== $login) {
            return null;
        }

        return $r;
    }

    /**
     * A group is given by Okta group id (00g...) or exact name. A name must match exactly one group; anything else refuses before any change.
     * @param array<int,mixed> $groups @return list<string>
     */
    private function resolveGroups(array $groups): array
    {
        $groups = array_slice(array_values(array_unique(array_filter(array_map(static fn ($g) => mb_substr(trim((string) $g), 0, 200), $groups), 'strlen'))), 0, self::MAX_GROUPS);
        $ids = [];
        foreach ($groups as $g) {
            if (preg_match('/^00g[A-Za-z0-9]{10,40}$/', $g)) {
                $ids[] = $g;
                continue;
            }
            $r = $this->call('GET', '/api/v1/groups?limit=10&q=' . rawurlencode($g), null, [200]);
            $hits = [];
            foreach (is_array($r) ? $r : [] as $row) {
                if (isset($row['id']) && mb_strtolower((string) ($row['profile']['name'] ?? '')) === mb_strtolower($g)) {
                    $hits[] = (string) $row['id'];
                }
            }
            if (count($hits) !== 1) {
                throw new \RuntimeException(count($hits) === 0 ? "no Okta group named \"$g\"; nothing was changed" : "more than one Okta group is named \"$g\"; use its group id. Nothing was changed");
            }
            $ids[] = $hits[0];
        }

        return array_values(array_unique($ids));
    }

    /**
     * One API call. @param list<int> $okStatuses statuses that are success (404 in the list returns null)
     * @return array<mixed>|null decoded JSON, [] for an empty body
     */
    private function call(string $method, string $path, ?array $body, array $okStatuses): ?array
    {
        $url = ($this->allowHttp ? 'http://' : 'https://') . $this->domain . $path;
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: SSWS ' . $this->token, 'User-Agent: RivetIT-Okta'];
        $resp = ($this->http)($method, $url, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 404 && in_array(404, $okStatuses, true)) {
            return null;
        }
        if (!in_array($status, $okStatuses, true)) {
            throw new \RuntimeException($this->explain($status, (string) ($resp['body'] ?? '')));
        }
        $decoded = json_decode((string) ($resp['body'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** A message that names what Okta said without ever echoing the token or the raw body. */
    private function explain(int $status, string $body): string
    {
        $j = json_decode($body, true);
        $summary = is_array($j) && isset($j['errorSummary']) ? mb_substr((string) $j['errorSummary'], 0, 200) : '';
        $summary = str_replace($this->token, '[token]', $summary);

        return match (true) {
            $status === 0 => 'Okta could not be reached (network error or timeout); nothing is known to have changed',
            $status === 401 => 'Okta refused the API token (401). Check the token in Administration > Employee Workflow Templates',
            $status === 403 => 'The Okta API token is not allowed to do this (403); give its admin role the permission',
            $status === 429 => 'Okta is rate limiting this org (429); the task will be retried',
            default => "Okta answered HTTP $status" . ($summary !== '' ? ": $summary" : ''),
        };
    }

    private function audit(string $event, string $action, string $summary, array $meta): void
    {
        try {
            ($this->audit)($event, $action, $summary, $meta);
        } catch (\Throwable) {
            // an audit failure must not turn a completed change into a retried one
        }
    }

    /** @return array{status:int,body:string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $this->allowHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $out === false ? 0 : $status, 'body' => is_string($out) ? $out : ''];
    }
}
