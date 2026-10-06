<?php

namespace ITFlow\Webhooks;

use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\PayloadTemplate;
use RivetCore\Webhooks\WebhookSubscription;

/**
 * Everything the edition adds on top of RivetCore's webhook destination presets (Destinations): turning a `webhooks` row
 * into the delivery options Core's dispatcher understands, matching stored event patterns, and validating the add/edit form.
 *
 * Pure of HTTP and sessions. Encryption goes through encryptSetting()/decryptSetting() (functions.php), exactly like the
 * webhook URL and secret before it. A row whose webhook_destination is '' is a legacy row: it gets no options at all, so the
 * dispatcher keeps its original plain signed JSON envelope (and Slack/Teams rows keep using ChatDelivery).
 */
final class DestinationConfig
{
    /** Presets whose URL is not a secret (shown and editable); every other preset's URL is stored encrypted and never echoed. */
    public const PLAIN_URL = ['generic-json', 'generic-form', 'custom-template'];

    public const MAX_EVENTS_LENGTH = 4000;

    public static function destination(array $row): ?Destination
    {
        $id = (string) ($row['webhook_destination'] ?? '');

        return $id === '' ? null : Destinations::get($id);
    }

    /** The preset a row is shown as: its own, or the one a legacy row is equivalent to. */
    public static function effectiveDestinationId(array $row): string
    {
        $id = (string) ($row['webhook_destination'] ?? '');
        if ($id !== '' && Destinations::has($id)) {
            return $id;
        }

        return match (ChatFormatter::normalizeType($row['webhook_type'] ?? '')) {
            ChatFormatter::TYPE_SLACK => 'slack',
            ChatFormatter::TYPE_TEAMS => 'teams',
            default => 'generic-json',
        };
    }

    public static function urlIsSecret(string $destinationId): bool
    {
        return !in_array($destinationId, self::PLAIN_URL, true);
    }

    /** Methods an admin may choose for a preset: a PUT-only preset is PUT, the custom template may be either, the rest POST. */
    public static function allowedMethods(Destination $d): array
    {
        if ($d->method === 'PUT') {
            return ['PUT'];
        }

        return $d->id === 'custom-template' ? ['POST', 'PUT'] : ['POST'];
    }

    // ----- events ----------------------------------------------------------------------------------------------

    /** Does a stored comma list (ids and/or patterns such as "ticket.*" or "*") include this event? */
    public static function eventMatches(string $stored, string $event): bool
    {
        foreach (explode(',', $stored) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if ($p === $event) {
                return true;
            }
            if (str_contains($p, '*') && preg_match('/^[a-z0-9_.*-]{1,150}$/', $p) === 1) {
                if (in_array($event, EventCatalog::matchPattern($p), true)) {
                    return true;
                }
                // Same rule as EventCatalog::matchPattern, applied to events the catalog does not list (other audit events).
                if (preg_match('/^' . str_replace('\*', '.*', preg_quote($p, '/')) . '$/', $event) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    // ----- stored configuration --------------------------------------------------------------------------------

    /** @return array<string,mixed> decrypted {mode, token, username, password, header_name, header_value} */
    public static function authConfig(array $row): array
    {
        $cfg = [];
        $enc = (string) ($row['webhook_auth_enc'] ?? '');
        if ($enc !== '') {
            $dec = json_decode(decryptSetting($enc), true);
            $cfg = is_array($dec) ? $dec : [];
        }
        $cfg['mode'] = (string) ($row['webhook_auth_mode'] ?? 'none') ?: 'none';

        return $cfg;
    }

    /** @return array<string,mixed> */
    public static function extra(array $row): array
    {
        $x = json_decode((string) ($row['webhook_extra'] ?? ''), true);

        return is_array($x) ? $x : [];
    }

    /**
     * Delivery options for RivetCore\Webhooks\WebhookDispatcher (format, method, extraHeaders, format_options, template...).
     * Empty for a legacy row. $event/$data (when known) add the "open this ticket" link.
     *
     * @return array<string,mixed>
     */
    public static function options(array $row, ?string $event = null, array $data = [], bool $test = false): array
    {
        $d = self::destination($row);
        if ($d === null) {
            return [];
        }
        $format = (string) ($row['webhook_format'] ?? '') ?: $d->format;
        $method = strtoupper((string) ($row['webhook_method'] ?? '')) ?: $d->method;
        $extra = self::extra($row);

        $headers = $d->headers;
        try {
            $headers = array_merge($headers, Authentication::headers(self::authConfig($row)));
        } catch (\Throwable) {
            // Validated on save; if it is somehow broken now the dispatcher refuses this header and the attempt fails visibly.
            $headers['Invalid Header'] = "bad\r\nauth";
        }

        $fo = $d->formatOptions;
        foreach ($d->extraFields as $f) {
            if ($f->target === 'option' && $f->option !== '' && isset($extra[$f->name]) && $extra[$f->name] !== '') {
                $fo[$f->option] = $extra[$f->name];
            }
        }
        $fo['app_name'] = defined('APP_NAME') ? (string) APP_NAME : 'RivetIT';
        if ($data !== []) {
            $link = ChatDelivery::formatterOptions($data)['ticket_url'] ?? null;
            if ($link !== null) {
                $fo['link_url'] = $link;
            }
        }
        if ($test) {
            $fo['test'] = true;
        }

        $opts = ['format' => $format, 'method' => $method, 'extraHeaders' => $headers, 'format_options' => $fo];
        if ($format === 'template') {
            $opts['template'] = (string) ($row['webhook_template'] ?? '') !== '' ? (string) $row['webhook_template'] : (string) ($d->formatOptions['template'] ?? '');
            $opts['template_encoding'] = (string) ($extra['template_encoding'] ?? ($d->formatOptions['template_encoding'] ?? 'json'));
        }

        return $opts;
    }

    /** The subscription Core delivers to for this row (secret and URL decrypted). */
    public static function subscription(array $row): WebhookSubscription
    {
        return new WebhookSubscription((int) ($row['webhook_id'] ?? 0), decryptSetting((string) ($row['webhook_url'] ?? '')), decryptSetting((string) ($row['webhook_secret'] ?? '')), self::options($row));
    }

    // ----- form validation -------------------------------------------------------------------------------------

    /**
     * Validate the add/edit form against the chosen preset. Never trusts the browser: every rule below is enforced here.
     *
     * @param array<string,mixed> $post      $_POST
     * @param array<string,mixed>|null $existing the saved row when editing (blank secrets then mean "keep")
     * @param callable(string):bool $knownEvent whether an id is an event this install knows
     * @param callable(string):bool $urlSafe   the shared URL policy (rivetWebhookUrlPolicy)
     * @return array{errors:list<string>,row:array<string,mixed>,keep_url:bool,destination:?Destination}
     *         row = columns ready for the database (secrets already encrypted)
     */
    public static function validateForm(array $post, ?array $existing, callable $knownEvent, callable $urlSafe): array
    {
        try {
            return self::doValidate($post, $existing, $knownEvent, $urlSafe);
        } catch (\RuntimeException $e) {
            // encryptSetting() refuses to store a secret without $config_settings_enc_key.
            return ['errors' => [$e->getMessage()], 'row' => [], 'keep_url' => false, 'destination' => null];
        }
    }

    /** @return array{errors:list<string>,row:array<string,mixed>,keep_url:bool,destination:?Destination} */
    private static function doValidate(array $post, ?array $existing, callable $knownEvent, callable $urlSafe): array
    {
        $errors = [];
        $str = static fn (string $k): string => is_scalar($post[$k] ?? null) ? trim((string) $post[$k]) : '';

        // No destination posted = an older form or script: map the legacy type onto its equivalent preset.
        $destId = $str('webhook_destination');
        $legacyPost = $destId === '';
        if ($destId === '') {
            $destId = match (ChatFormatter::normalizeType($str('webhook_type'))) {
                ChatFormatter::TYPE_SLACK => 'slack',
                ChatFormatter::TYPE_TEAMS => 'teams',
                default => 'generic-json',
            };
        }
        $d = Destinations::get($destId);
        if ($d === null) {
            return ['errors' => ['Choose a platform from the list.'], 'row' => [], 'keep_url' => false, 'destination' => null];
        }
        $type = match ($d->id) {
            'slack' => ChatFormatter::TYPE_SLACK,
            'teams' => ChatFormatter::TYPE_TEAMS,
            default => ChatFormatter::TYPE_GENERIC,
        };
        $isChat = ChatFormatter::isChatType($type);
        $existing ??= [];
        $editing = $existing !== [];
        $sameDestination = $editing && self::effectiveDestinationId($existing) === $d->id;

        // ---- name
        $name = (string) (function_exists('cleanInput') ? cleanInput($str('webhook_name')) : $str('webhook_name'));
        if ($name === '') {
            $errors[] = 'A name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'The name is too long (200 characters at most).';
        }

        // ---- events
        $events = [];
        $rawEvents = $post['webhook_events'] ?? [];
        foreach (is_array($rawEvents) ? $rawEvents : [] as $ev) {
            $ev = is_scalar($ev) ? trim((string) $ev) : '';
            if ($ev === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_.*-]{1,150}$/', $ev)) {
                $errors[] = 'The event "' . self::clip($ev) . '" is not valid.';
                continue;
            }
            if ($ev === '*' || str_contains($ev, '*')) {
                $known = $ev === '*' || EventCatalog::matchPattern($ev) !== [];
                if (!$known) {
                    $errors[] = 'The event pattern "' . $ev . '" matches no known event.';
                    continue;
                }
            } elseif (!$knownEvent($ev)) {
                $errors[] = 'The event "' . $ev . '" is not a known event.';
                continue;
            }
            $events[$ev] = $ev;
        }
        if (!$events) {
            $errors[] = 'Choose at least one event.';
        }
        $eventsCsv = implode(',', $events);
        if (strlen($eventsCsv) > self::MAX_EVENTS_LENGTH) {
            $errors[] = 'Too many events are selected to store; choose whole groups (such as ticket.*) instead of each event.';
        }

        // ---- extra fields (the preset's own inputs: topic, chat id, room ...). url-target ones fill {placeholders} in the URL.
        $extraIn = is_array($post['extra'] ?? null) ? $post['extra'] : [];
        $extraStored = $sameDestination ? self::extra($existing) : [];
        $extra = [];
        $urlValues = [];
        foreach ($d->extraFields as $f) {
            $v = is_scalar($extraIn[$f->name] ?? null) ? trim((string) $extraIn[$f->name]) : '';
            if ($v !== '' && (strlen($v) > 200 || preg_match('/[\x00-\x1F\x7F]/', $v))) {
                $errors[] = $f->label . ' is not valid (200 characters, no line breaks).';
                continue;
            }
            if ($v !== '' && $f->type === 'number' && !preg_match('/^\d{1,6}$/', $v)) {
                $errors[] = $f->label . ' must be a number.';
                continue;
            }
            if ($f->target === 'url') {
                if ($v !== '') {
                    $urlValues[$f->name] = $v;
                }
                continue;
            }
            if ($v === '' && $f->type === 'secret') {
                $v = (string) ($extraStored[$f->name] ?? '');
            }
            if ($v === '' && $f->required) {
                $errors[] = $f->label . ' is required.';
            }
            if ($v !== '') {
                $extra[$f->name] = $v;
            }
        }

        // ---- URL
        $url = filter_var($str('webhook_url'), FILTER_SANITIZE_URL);
        $url = is_string($url) ? $url : '';
        $keepUrl = false;
        if ($url === '' && $sameDestination && (string) ($existing['webhook_url'] ?? '') !== '' && !$urlValues) {
            // A secret URL is never shown, so blank on edit = keep what is saved.
            $keepUrl = true;
        } elseif ($url === '' && $urlValues && $d->urlHint !== '') {
            $url = $d->urlHint;   // the preset's own address, filled in from the fields
        }
        $urlStored = '';
        if (!$keepUrl) {
            foreach ($d->extraFields as $f) {
                if ($f->target === 'url' && isset($urlValues[$f->name])) {
                    $url = str_replace('{' . $f->name . '}', str_replace('%3A', ':', rawurlencode($urlValues[$f->name])), $url);
                }
            }
            if ($url === '') {
                $errors[] = 'The endpoint URL is required.';
            } elseif (preg_match('/\{([a-z_]+)\}/', str_replace('{txn}', '', $url), $m)) {
                $label = $m[1];
                foreach ($d->extraFields as $f) {
                    if ($f->name === $m[1]) {
                        $label = $f->label;
                    }
                }
                $errors[] = 'Fill in "' . $label . '": the URL still contains {' . $m[1] . '}.';
            } elseif (strlen($url) > (($isChat || self::urlIsSecret($d->id)) ? ChatDelivery::MAX_URL_LENGTH : 2048)) {
                $errors[] = 'The URL is too long (' . (($isChat || self::urlIsSecret($d->id)) ? ChatDelivery::MAX_URL_LENGTH : 2048) . ' characters at most).';
            } elseif (!$legacyPost && !$d->urlMatches($url)) {
                $errors[] = 'That URL does not look like a valid ' . $d->name . ' URL. Expected something like ' . $d->urlHint . '.';
            } elseif ($isChat) {
                $vet = ChatDelivery::vetUrl($url);
                if (!$vet['ok']) {
                    $errors[] = $d->name . ' URL rejected: ' . $vet['error'];
                }
            } elseif (!$urlSafe(str_replace('{txn}', 'x', $url))) {
                $errors[] = 'Endpoint URL rejected (' . (function_exists('rivetWebhookRuleText') ? rivetWebhookRuleText() : 'public addresses only') . '). Loopback, link-local and cloud-metadata addresses are never allowed.';
            }
            $urlStored = $url;
        }

        // ---- method
        $allowedMethods = self::allowedMethods($d);
        $method = strtoupper($str('webhook_method')) ?: $allowedMethods[0];
        if (!in_array($method, $allowedMethods, true)) {
            $errors[] = $d->name . ' accepts ' . implode(' or ', $allowedMethods) . ' only, not ' . self::clip($method, 10) . '.';
            $method = $allowedMethods[0];
        }

        // ---- outgoing authentication (what the receiver expects from us)
        $mode = strtolower($str('webhook_auth_mode')) ?: ($sameDestination ? (string) ($existing['webhook_auth_mode'] ?? '') : '');
        $mode = $mode !== '' ? $mode : $d->defaultAuth;
        $authStored = $sameDestination ? self::authConfig($existing) : [];
        $authEnc = '';
        if (!in_array($mode, $d->authModes, true)) {
            $errors[] = 'Authentication "' . self::clip($mode, 20) . '" is not available for ' . $d->name . ' (choose ' . implode(', ', $d->authModes) . ').';
        } else {
            $cfg = ['mode' => $mode];
            $map = ['token' => 'auth_token', 'username' => 'auth_username', 'password' => 'auth_password', 'header_name' => 'auth_header_name', 'header_value' => 'auth_header_value'];
            $secretKeys = ['token', 'password', 'header_value'];
            $keep = $mode === ($authStored['mode'] ?? '');
            foreach ($map as $key => $field) {
                $v = isset($post[$field]) && is_scalar($post[$field]) ? (string) $post[$field] : '';
                $v = in_array($key, ['username', 'header_name'], true) ? trim($v) : $v;
                if ($v === '' && $keep) {
                    $v = (string) ($authStored[$key] ?? '');
                }
                $cfg[$key] = $v;
            }
            if ($mode === 'header' && $cfg['header_name'] === '' && $d->defaultAuthHeader) {
                $cfg['header_name'] = $d->defaultAuthHeader;
            }
            $problems = Authentication::validate($cfg);
            foreach ($problems as $p) {
                $errors[] = $p;
            }
            if (!$problems) {
                $keepKeys = match ($mode) {
                    'bearer' => ['token'],
                    'basic' => ['username', 'password'],
                    'header' => ['header_name', 'header_value'],
                    default => [],
                };
                $blob = array_intersect_key($cfg, array_flip($keepKeys));
                if ($blob && $keep && $blob == array_intersect_key($authStored, array_flip($keepKeys))) {
                    $authEnc = null;   // unchanged: leave the saved ciphertext alone
                } else {
                    $authEnc = $blob ? self::encrypt((string) json_encode($blob)) : '';
                }
            }
            unset($secretKeys);
        }

        // ---- body template (custom template only)
        $template = '';
        $format = $d->format;
        if ($format === 'template' && $d->id === 'custom-template') {
            $template = (string) ($post['webhook_template'] ?? '');
            $template = str_replace("\r\n", "\n", $template);
            $enc = $str('webhook_template_encoding') ?: 'json';
            if (!in_array($enc, PayloadTemplate::ENCODINGS, true)) {
                $errors[] = 'The template encoding must be one of: ' . implode(', ', PayloadTemplate::ENCODINGS) . '.';
                $enc = 'json';
            }
            foreach (PayloadTemplate::validate($template, $enc) as $e) {
                $errors[] = 'Template: ' . $e;
            }
            $extra['template_encoding'] = $enc;
        }

        // ---- signing secret (HMAC key; for Slack, the app's Signing Secret; Teams carries none)
        $rawSecret = isset($post['webhook_secret']) && is_scalar($post['webhook_secret']) ? trim((string) $post['webhook_secret']) : '';
        $secretEnc = null;   // null = leave the column alone
        $keepKind = $editing && ChatFormatter::normalizeType($existing['webhook_type'] ?? '') === $type;
        if ($type === ChatFormatter::TYPE_TEAMS) {
            $secretEnc = '';
        } elseif ($rawSecret !== '') {
            if (strlen($rawSecret) > 200 || preg_match('/[\x00-\x1F\x7F]/', $rawSecret)) {
                $errors[] = 'The signing secret is not valid (200 characters, no line breaks).';
            } else {
                $secretEnc = self::encrypt((function_exists('cleanInput') ? (string) cleanInput($rawSecret) : $rawSecret));
            }
        } elseif ($editing && (!$keepKind || ($type === ChatFormatter::TYPE_SLACK && isset($post['webhook_secret_clear'])))) {
            $secretEnc = '';
        } elseif (!$editing) {
            $secretEnc = '';
        }

        // ---- routing filters (chat and notification destinations)
        $min = '';
        $clients = '';
        if (in_array($d->category, ['chat', 'notify'], true)) {
            $m = $str('webhook_min_priority');
            $min = isset(ChatFormatter::PRIORITIES[$m]) ? $m : '';
            $ids = [];
            foreach (is_array($post['webhook_client_ids'] ?? null) ? $post['webhook_client_ids'] : [] as $cid) {
                if (is_scalar($cid) && ctype_digit((string) $cid) && (int) $cid > 0) {
                    $ids[(int) $cid] = (int) $cid;
                }
            }
            $clients = implode(',', array_slice(array_values($ids), 0, 60));
        }

        $row = [
            'webhook_name' => $name,
            'webhook_events' => $eventsCsv,
            'webhook_enabled' => isset($post['webhook_enabled']) ? 1 : 0,
            'webhook_type' => $type,
            'webhook_min_priority' => $min,
            'webhook_client_ids' => $clients,
            'webhook_destination' => $d->id,
            'webhook_format' => $format,
            'webhook_method' => $method,
            'webhook_template' => $template === '' ? null : $template,
            'webhook_auth_mode' => $mode,
            'webhook_auth_enc' => $authEnc === '' ? null : $authEnc,
            'webhook_extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];
        if ($authEnc === null) {
            unset($row['webhook_auth_enc']);
        }
        if (!$keepUrl) {
            $enc = '';
            if ($urlStored !== '') {
                try {
                    $enc = ($isChat || self::urlIsSecret($d->id)) ? self::encrypt($urlStored) : $urlStored;
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $row['webhook_url'] = $enc;
        }
        if ($secretEnc !== null) {
            $row['webhook_secret'] = $secretEnc;
        }

        return ['errors' => array_values(array_unique($errors)), 'row' => $row, 'keep_url' => $keepUrl, 'destination' => $d];
    }

    /**
     * The row a form describes, as the dispatcher would read it from the database: the validated values laid over the saved row
     * (a kept URL or secret comes from the saved row). Used to test or preview before anything is saved.
     *
     * @param array{row:array<string,mixed>,keep_url:bool} $validated
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    public static function draftRow(array $validated, ?array $existing): array
    {
        $row = $validated['row'];
        $existing ??= [];
        if (!empty($validated['keep_url'])) {
            $row['webhook_url'] = (string) ($existing['webhook_url'] ?? '');
        }
        if (!array_key_exists('webhook_secret', $row)) {
            $row['webhook_secret'] = (string) ($existing['webhook_secret'] ?? '');
        }
        if (!array_key_exists('webhook_auth_enc', $row)) {
            $row['webhook_auth_enc'] = $existing['webhook_auth_enc'] ?? null;   // unchanged auth is not rewritten
        }
        $row['webhook_id'] = (int) ($existing['webhook_id'] ?? 0);

        return $row;
    }

    /**
     * Exactly what would be sent for a sample event, with secrets masked: the method, the URL's host, the headers and the body.
     *
     * @param array<string,mixed> $row a draft or stored row
     * @return array{method:string,url:string,headers:list<string>,body:string,format:string}
     */
    public static function preview(array $row, string $event): array
    {
        $ctx = PayloadTemplate::sampleContext($event);
        $data = $ctx['data'];
        $url = ChatDelivery::maskUrl(decryptSetting((string) ($row['webhook_url'] ?? '')));
        $type = ChatFormatter::normalizeType($row['webhook_type'] ?? '');
        if (ChatFormatter::isChatType($type)) {
            $msg = ChatFormatter::format($type, $event, $data, ChatDelivery::formatterOptions($data)) ?? [];

            return ['method' => 'POST', 'url' => $url, 'format' => $type, 'headers' => ['Content-Type: application/json; charset=utf-8', 'User-Agent: RivetIT-Chat-Webhook'],
                'body' => (string) json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)];
        }
        $opts = self::options($row, $event, $data);
        $secret = decryptSetting((string) ($row['webhook_secret'] ?? ''));
        $ts = time();
        if ($opts === []) {
            $body = (string) json_encode(['event' => $event, 'timestamp' => $ctx['timestamp'], 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers = ['Content-Type: application/json'];
            $method = 'POST';
            $format = 'json';
            $masked = [];
        } else {
            $fo = (array) ($opts['format_options'] ?? []);
            foreach (['template', 'template_encoding'] as $k) {
                if (isset($opts[$k])) {
                    $fo[$k] = $opts[$k];
                }
            }
            try {
                $f = \RivetCore\Webhooks\PayloadFormatter::format((string) $opts['format'], ['event' => $event, 'timestamp' => $ctx['timestamp'], 'data' => $data], $fo);
            } catch (\Throwable $e) {
                return ['method' => (string) $opts['method'], 'url' => $url, 'format' => (string) $opts['format'], 'headers' => [], 'body' => 'Could not build the body: ' . $e->getMessage()];
            }
            $body = $f->body;
            $headers = ['Content-Type: ' . $f->contentType];
            foreach ($f->headers as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
            $masked = [];
            try {
                $masked = array_keys(Authentication::headers(self::authConfig($row)));
            } catch (\Throwable) {
            }
            foreach ((array) $opts['extraHeaders'] as $k => $v) {
                $headers[] = $k . ': ' . (in_array($k, $masked, true) ? (preg_match('/^(Bearer|Basic) /', (string) $v, $sm) ? $sm[1] . ' ' : '') . '********' : $v);
            }
            $method = (string) $opts['method'];
            $format = (string) $opts['format'];
        }
        $headers[] = 'X-Rivet-Timestamp: ' . $ts;
        $headers[] = 'X-Rivet-Signature-V2: ' . \RivetCore\Webhooks\WebhookDispatcher::signatureV2($ts, $body, $secret);
        foreach (function_exists('rivetWebhookHeaderPrefixes') ? rivetWebhookHeaderPrefixes() : ['X-ITFlow', 'X-RivetIT'] as $prefix) {
            $headers[] = $prefix . '-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
            $headers[] = $prefix . '-Event: ' . $event;
        }
        if ($format === 'json' && str_starts_with($body, '{')) {
            $pretty = json_decode($body, true);
            if (is_array($pretty)) {
                $body = (string) json_encode($pretty, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
        }

        return ['method' => $method, 'url' => $url, 'format' => $format, 'headers' => $headers, 'body' => $body];
    }

    /** encryptSetting() refuses to store a secret in clear text; surface that as a form error instead of a fatal. */
    private static function encrypt(string $plain): string
    {
        return encryptSetting($plain);
    }

    private static function clip(string $s, int $n = 60): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';

        return strlen($s) > $n ? substr($s, 0, $n) . '...' : $s;
    }
}
