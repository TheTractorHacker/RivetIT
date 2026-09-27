<?php

namespace ITFlow\Training\Core;

/**
 * Admin › Training settings (the 2.6.91 `config_training_*` columns), as one immutable value.
 *
 * fromDb() reads the settings row with an explicit column list; before the migration has
 * run (columns missing) it returns the column defaults, so nothing that merely asks for a
 * limit can 500 an unmigrated install. fromGlobals() is the web entry point: it uses the
 * request's global connection and is memoised for the request.
 *
 * Caps mirror the admin form: video and file uploads never exceed 95 MB, because Cloudflare
 * answers a larger request body with an HTML 413 before PHP ever sees it.
 */
final class TrainingSettings
{
    public const MB = 1048576;
    public const UPLOAD_CAP_MB = 95;
    public const DOCX_MAX_BYTES = 20971520;
    public const CSV_MAX_BYTES = 2097152;
    public const KNOWN_LANGUAGES = ['en' => 'English', 'es' => 'Español'];

    private const COLUMNS = [
        'config_training_languages', 'config_training_default_pass_pct', 'config_training_default_max_attempts',
        'config_training_attestation_text', 'config_training_video_max_mb', 'config_training_pdf_max_mb',
        'config_training_pdf_max_pages', 'config_training_image_max_mb', 'config_training_file_max_mb',
        'config_training_media_budget_mb', 'config_training_youtube_api_key',
    ];

    private const DEFAULTS = [
        'config_training_languages' => 'en,es',
        'config_training_default_pass_pct' => 80,
        'config_training_default_max_attempts' => 3,
        'config_training_attestation_text' => null,
        'config_training_video_max_mb' => 95,
        'config_training_pdf_max_mb' => 50,
        'config_training_pdf_max_pages' => 150,
        'config_training_image_max_mb' => 15,
        'config_training_file_max_mb' => 50,
        'config_training_media_budget_mb' => 1024,
        'config_training_youtube_api_key' => null,
    ];

    private static ?self $request = null;

    /** @param list<string> $languages */
    private function __construct(
        public readonly array $languages,
        public readonly int $videoMaxBytes,
        public readonly int $pdfMaxBytes,
        public readonly int $pdfMaxPages,
        public readonly int $imageMaxBytes,
        public readonly int $fileMaxBytes,
        public readonly int $budgetBytes,
        public readonly int $defaultPassPct,
        public readonly int $defaultMaxAttempts,
        public readonly ?string $attestationDefault,
        public readonly ?string $youtubeKeyEnc,
        public readonly bool $schemaReady,
    ) {
    }

    public static function fromGlobals(): self
    {
        if (self::$request === null) {
            $db = $GLOBALS['mysqli'] ?? null;
            if (!($db instanceof \mysqli)) {
                throw new \LogicException('TrainingSettings::fromGlobals() needs the request connection ($mysqli)');
            }
            self::$request = self::fromDb($db);
        }
        return self::$request;
    }

    public static function fromDb(\mysqli $db): self
    {
        try {
            $res = $db->query('SELECT ' . implode(', ', self::COLUMNS) . ' FROM settings WHERE company_id = 1');
            $row = $res ? $res->fetch_assoc() : null;
            if ($res) {
                $res->free();
            }
            if (is_array($row)) {
                return self::fromRow($row, true);
            }
        } catch (\mysqli_sql_exception) {
            // 1054 unknown column: 2.6.91 has not run yet - fall through to the defaults.
        }
        return self::fromRow([], false);
    }

    /** Builds settings from a (possibly partial) settings row; missing keys take the column defaults. */
    public static function fromRow(array $row, bool $schemaReady = true): self
    {
        $v = static fn(string $k) => array_key_exists($k, $row) ? $row[$k] : self::DEFAULTS[$k];
        $mb = static fn(string $k, int $min, int $max) => max($min, min($max, (int) $v($k))) * self::MB;

        $languages = [];
        foreach (explode(',', (string) $v('config_training_languages')) as $l) {
            $l = strtolower(trim($l));
            if (preg_match('/^[a-z]{2}$/', $l) && !in_array($l, $languages, true)) {
                $languages[] = $l;
            }
        }
        if (!in_array('en', $languages, true)) {
            array_unshift($languages, 'en');
        }

        $att = $v('config_training_attestation_text');
        $key = $v('config_training_youtube_api_key');

        return new self(
            $languages,
            $mb('config_training_video_max_mb', 1, self::UPLOAD_CAP_MB),
            $mb('config_training_pdf_max_mb', 1, self::UPLOAD_CAP_MB),
            max(1, min(1000, (int) $v('config_training_pdf_max_pages'))),
            $mb('config_training_image_max_mb', 1, self::UPLOAD_CAP_MB),
            $mb('config_training_file_max_mb', 1, self::UPLOAD_CAP_MB),
            max(1, (int) $v('config_training_media_budget_mb')) * self::MB,
            max(1, min(100, (int) $v('config_training_default_pass_pct'))),
            max(0, min(255, (int) $v('config_training_default_max_attempts'))),
            ($att === null || $att === '') ? null : (string) $att,
            ($key === null || $key === '') ? null : (string) $key,
            $schemaReady,
        );
    }

    /** Limits the browser pre-checks uploads against (the server re-checks every one). */
    public function clientLimits(): array
    {
        return [
            'video_max_bytes'   => $this->videoMaxBytes,
            'pdf_max_bytes'     => $this->pdfMaxBytes,
            'pdf_max_pages'     => $this->pdfMaxPages,
            'image_max_bytes'   => $this->imageMaxBytes,
            'file_max_bytes'    => $this->fileMaxBytes,
            'docx_max_bytes'    => self::DOCX_MAX_BYTES,
            'csv_max_bytes'     => self::CSV_MAX_BYTES,
            'caption_max_bytes' => \ITFlow\Training\Media\Captions::MAX_BYTES,
            'request_max_bytes' => self::requestMaxBytes(),
            'languages'         => $this->languages,
        ];
    }

    /** The smaller of PHP's post_max_size and 100 MB (the Cloudflare request-body ceiling). */
    public static function requestMaxBytes(): int
    {
        $ini = trim((string) ini_get('post_max_size'));
        $n = (int) $ini;
        $unit = strtolower(substr($ini, -1));
        $bytes = match ($unit) {
            'g' => $n * 1073741824,
            'm' => $n * self::MB,
            'k' => $n * 1024,
            default => (int) $ini,
        };
        if ($bytes <= 0) {
            $bytes = PHP_INT_MAX;
        }
        return min($bytes, 100 * self::MB);
    }
}
