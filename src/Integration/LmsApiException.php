<?php

namespace ITFlow\Integration;

/** A client-facing failure of a machine API call; lms.php turns it into the structured error response. */
final class LmsApiException extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $http, public readonly string $errCode, string $message, public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}
