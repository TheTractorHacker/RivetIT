<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Api\ApiException;

/**
 * A user-facing failure from the media pipeline (upload validation, probing, storage, video
 * checks). It carries exactly what the JSON envelope needs (spec §6.1): an HTTP status, one of
 * the §6.1 error codes, and a plain-language message written for the author.
 *
 * Media services are called from three places - training_upload.php, the Router (via
 * MediaActions) and other lanes' services - and none of them should have to re-derive a status
 * code, so the exception already holds one. The Router only understands ApiException, so every
 * caller that lets a MediaException reach it converts first with toApi() (MediaActions does this
 * for every media action).
 *
 * $warnings are non-fatal notes gathered before the failure (e.g. "HEVC may not play on
 * Windows") that the client may still want to show; they travel in the envelope's data.
 */
final class MediaException extends \RuntimeException
{
    /** @param list<string> $warnings */
    public function __construct(
        public int $http,
        public string $errCode,
        string $msg,
        public array $warnings = [],
        public array $fields = [],
    ) {
        parent::__construct($msg);
    }

    public function toApi(): ApiException
    {
        return new ApiException(
            $this->http,
            $this->errCode,
            $this->getMessage(),
            $this->fields,
            $this->warnings === [] ? [] : ['warnings' => array_values($this->warnings)]
        );
    }

    public static function notFound(string $msg = 'Not found.'): self
    {
        return new self(404, 'not_found', $msg);
    }

    public static function unsupported(string $msg, array $warnings = []): self
    {
        return new self(415, 'unsupported_type', $msg, $warnings);
    }

    public static function tooLarge(string $msg): self
    {
        return new self(413, 'too_large', $msg);
    }

    public static function validation(string $field, string $msg): self
    {
        return new self(422, 'validation', $msg, [], [$field => $msg]);
    }

    public static function busy(string $msg = 'Someone else is working on this file; try again in a moment.'): self
    {
        return new self(409, 'busy', $msg);
    }
}
