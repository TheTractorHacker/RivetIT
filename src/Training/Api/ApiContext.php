<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\TrainingSettings;

/**
 * Typed, validating accessors over one request's input (the decoded JSON body for POST, the
 * query string for GET). Handlers receive (Ctx $c, ApiContext $a); services only ever see
 * values that came through here.
 *
 * Every failure is a 422 `validation` ApiException carrying the field name, so the client can
 * mark the exact input. Strings must be valid UTF-8 (the database is strict, and Canonical
 * refuses invalid UTF-8), are trimmed, and are measured in characters (mb_strlen), matching
 * the varchar(N) character limits.
 */
final class ApiContext
{
    public string $method;
    /** @var array<string, mixed> */
    public array $input;
    private ?TrainingSettings $settings;

    public function __construct(string $method, array $input, ?TrainingSettings $settings = null)
    {
        $this->method = $method;
        $this->input = $input;
        $this->settings = $settings;
    }

    public function has(string $k): bool
    {
        return array_key_exists($k, $this->input) && $this->input[$k] !== null;
    }

    public function int(string $k, bool $required = true, ?int $min = null, ?int $max = null): ?int
    {
        if (!$this->has($k) || $this->input[$k] === '') {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        $v = $this->input[$k];
        if (is_string($v) && preg_match('/^-?[0-9]{1,18}$/', trim($v))) {
            $v = (int) trim($v);
        }
        if (!is_int($v)) {
            throw ApiException::validation([$k => 'Must be a whole number.']);
        }
        if ($min !== null && $v < $min) {
            throw ApiException::validation([$k => "Must be at least $min."]);
        }
        if ($max !== null && $v > $max) {
            throw ApiException::validation([$k => "Must be at most $max."]);
        }
        return $v;
    }

    public function str(string $k, int $maxChars, bool $required = true, bool $allowEmpty = false): ?string
    {
        if (!$this->has($k)) {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        $v = $this->input[$k];
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            throw ApiException::validation([$k => 'Must be text.']);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw ApiException::validation([$k => 'Contains characters that could not be read. Retype it and try again.']);
        }
        $v = trim($v);
        if ($v === '') {
            if ($allowEmpty) {
                return '';
            }
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        if (mb_strlen($v, 'UTF-8') > $maxChars) {
            throw ApiException::validation([$k => "Too long (at most $maxChars characters)."]);
        }
        return $v;
    }

    public function bool(string $k, ?bool $default = null): ?bool
    {
        if (!$this->has($k) || $this->input[$k] === '') {
            return $default;
        }
        $v = $this->input[$k];
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === '1' || $v === 'true' || $v === 'on') {
            return true;
        }
        if ($v === 0 || $v === '0' || $v === 'false' || $v === 'off') {
            return false;
        }
        throw ApiException::validation([$k => 'Must be true or false.']);
    }

    public function arr(string $k, bool $required = true): array
    {
        if (!$this->has($k)) {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return [];
        }
        if (!is_array($this->input[$k])) {
            throw ApiException::validation([$k => 'Must be a list or an object.']);
        }
        return $this->input[$k];
    }

    public function enum(string $k, array $allowed, bool $required = true): ?string
    {
        if (!$this->has($k) || $this->input[$k] === '') {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        $v = $this->input[$k];
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw ApiException::validation([$k => 'Not a valid choice.']);
        }
        return $v;
    }

    /** A language code offered by Admin › Training (TrainingSettings::$languages). */
    public function lang(string $k = 'lang', bool $required = true): ?string
    {
        $allowed = $this->settings?->languages ?? ['en'];
        return $this->enum($k, $allowed, $required);
    }

    /**
     * A list of ids: JSON [1,2,3] or GET k[]=1&k[]=2. Order kept, duplicates removed.
     * Missing means an empty list.
     *
     * @return list<int>
     */
    public function ints(string $k): array
    {
        if (!$this->has($k)) {
            return [];
        }
        $v = $this->input[$k];
        if (!is_array($v) || !array_is_list($v)) {
            throw ApiException::validation([$k => 'Must be a list of ids.']);
        }
        if (count($v) > 5000) {
            throw ApiException::validation([$k => 'Too many items.']);
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && preg_match('/^-?[0-9]{1,18}$/', trim($item))) {
                $item = (int) trim($item);
            }
            if (!is_int($item)) {
                throw ApiException::validation([$k => 'Must be a list of ids.']);
            }
            if (!in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * An object of field => value patches, restricted to $allowlist. Unknown keys are a 422
     * naming each one - the allowlist is also how immutable fields (course_kind) are enforced.
     * Values are returned untouched; the handler validates each one.
     *
     * @param list<string> $allowlist
     * @return array<string, mixed>
     */
    public function fields(string $k, array $allowlist): array
    {
        if (!$this->has($k)) {
            throw ApiException::validation([$k => 'Required.']);
        }
        $v = $this->input[$k];
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw ApiException::validation([$k => 'Must be an object of fields.']);
        }
        $unknown = [];
        foreach (array_keys($v) as $f) {
            if (!in_array((string) $f, $allowlist, true)) {
                $unknown[(string) $f] = 'This field cannot be changed here.';
            }
        }
        if ($unknown !== []) {
            throw ApiException::validation($unknown);
        }
        return $v;
    }
}
