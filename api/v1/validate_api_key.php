<?php

/*
 * API - validate_api_key.php (retired)
 *
 * The old per-file handlers (api/v1/<resource>/<verb>.php) authenticated the legacy X-Api-Key / api_key against the api_keys table through
 * this file. Legacy API keys are not a credential any more: every machine caller uses an API key (Client ID + secret -> access token) through
 * the router (api/v1/index.php). This file now ends every request that reaches it with 401, so no old handler can run unauthenticated or on
 * a legacy key.
 */

header('Content-Type: application/json');
header('WWW-Authenticate: Bearer');
http_response_code(401);
echo json_encode(['success' => 'False', 'message' => 'The X-Api-Key header is no longer accepted. Use an API key (Client ID + secret): POST /api/v1/oauth/token, then Authorization: Bearer <access token>.']);
exit();
