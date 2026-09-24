<?php

namespace ITFlow\Training\Core;

/**
 * Everything a training service needs to know about the request, passed explicitly.
 *
 * Services take a Ctx in their constructor and never read globals (spec §0 "Service
 * boundaries"), which is what lets every service run from a CLI harness against a scratch
 * database. On the web a Ctx is built only by Access::ctx(); a CLI harness constructs one
 * directly.
 *
 * $baseUrl is 'https://' . $config_base_url with no trailing slash - never the Host header,
 * which a proxy hop can rewrite. The YouTube Data API key is not a property: it is decrypted
 * on demand by a closure (decryptSetting() reads $config_settings_enc_key), so it is never
 * stored on an object that might be dumped or logged.
 *
 * Constructing a Ctx also binds Core\Scratch to this connection (its files are namespaced by
 * DATABASE()).
 */
final class Ctx
{
    public function __construct(
        public readonly \mysqli $db,
        public readonly int $userId,
        public readonly bool $isAdmin,
        public readonly int $level,
        public readonly string $baseUrl,
        public readonly TrainingSettings $settings,
        public readonly ?string $userAgent,
        private readonly ?\Closure $youtubeKeyFn = null,
    ) {
        Scratch::bind($db);
    }

    /** The decrypted YouTube Data API key, or null when none is configured. Never cache or log it. */
    public function youtubeKey(): ?string
    {
        if ($this->youtubeKeyFn === null) {
            return null;
        }
        $key = ($this->youtubeKeyFn)();
        return (is_string($key) && $key !== '') ? $key : null;
    }

    /** Hides the connection and the key closure from var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return [
            'userId' => $this->userId,
            'isAdmin' => $this->isAdmin,
            'level' => $this->level,
            'baseUrl' => $this->baseUrl,
            'userAgent' => $this->userAgent,
            'youtubeKey' => $this->youtubeKeyFn === null ? null : '(closure)',
        ];
    }
}
