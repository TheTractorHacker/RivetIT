<?php

namespace ITFlow\EndpointAgent;

/** An error the device-facing endpoints turn into {"error": "...", "code": "..."} with an HTTP status. */
final class ApiError extends \RuntimeException
{
    public int $http;
    public string $errCode;
    /** @var array<string,string> */
    public array $headers;

    public function __construct(int $http, string $code, string $message, array $headers = [])
    {
        parent::__construct($message);
        $this->http = $http;
        $this->errCode = $code;
        $this->headers = $headers;
    }
}
