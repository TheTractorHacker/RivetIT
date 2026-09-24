<?php

namespace ITFlow\Training\Api;

/**
 * A client-facing error from a training JSON endpoint. The Router turns it into
 *   HTTP $http  {"ok":false,"error":{"code":$errCode,"message":…,"fields":{…}},"data"?:…}
 *
 * Codes (spec §6.1): module_disabled forbidden csrf not_found validation conflict archived
 * busy too_large unsupported_type pdf_password budget_exceeded no_changes prereq_cycle
 * document_shape video_live video_duration_mismatch kb_local_edits bank_in_use
 * media_evidence_ref server. A 409 `conflict` carries the current entity in data.current.
 *
 * The message is shown to the user as plain text (textContent), so write it for a person.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, string> $fields field => message, for inline validation errors
     * @param array<string, mixed>  $data   extra payload, e.g. ['current' => …] on a conflict
     */
    public function __construct(
        public int $http,
        public string $errCode,
        string $message,
        public array $fields = [],
        public array $data = [],
    ) {
        parent::__construct($message);
    }

    /** 422 validation with one or more field messages. */
    public static function validation(array $fields, string $message = 'Check the highlighted fields.'): self
    {
        return new self(422, 'validation', $message, $fields);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message = "You don't have access to this part of Training."): self
    {
        return new self(403, 'forbidden', $message);
    }

    /** 409 conflict: someone else saved first. $current is the entity as it is now. */
    public static function conflict(array $current, string $message = 'Changed in another tab.'): self
    {
        return new self(409, 'conflict', $message, [], ['current' => $current]);
    }

    public static function archived(string $message = 'This course is archived and read-only.'): self
    {
        return new self(409, 'archived', $message);
    }

    public static function busy(string $message = 'Someone else is saving; try again.'): self
    {
        return new self(409, 'busy', $message);
    }
}
