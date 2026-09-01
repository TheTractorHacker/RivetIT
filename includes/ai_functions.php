<?php
/*
 * ITFlow - AI helper functions
 *
 * Centralised wrapper around the OpenAI-compatible chat/completions endpoint.
 * Reuses the existing provider/model config: a provider lives in `ai_providers`
 * and a model is selected by `ai_models.ai_model_use_case` (currently 'General').
 *
 * These helpers NEVER fatal - aiChat() always returns a structured array so
 * callers can degrade gracefully, and they enforce a request timeout, an input
 * character cap and a max_tokens cap (all sourced from the `settings` table).
 */

if (!function_exists('aiEnabled')) {

    /**
     * Is AI enabled for this company (and, optionally, this client)?
     *
     * Checks the company-wide `config_ai_enable` flag and, when a client id is
     * supplied, honours the per-client `clients.client_ai_opt_out` flag. Fails
     * closed (returns false) on any error or missing config.
     *
     * @param int|null $client_id Optional client to check the opt-out flag for.
     * @return bool
     */
    function aiEnabled(?int $client_id = null): bool {
        global $mysqli;

        if (!isset($mysqli) || !$mysqli) {
            return false;
        }

        // Company-wide enable flag
        $sql = mysqli_query($mysqli, "SELECT config_ai_enable FROM settings WHERE company_id = 1 LIMIT 1");
        if (!$sql) {
            return false;
        }
        $row = mysqli_fetch_assoc($sql);
        if (!$row || intval($row['config_ai_enable'] ?? 0) !== 1) {
            return false;
        }

        // Per-client opt-out
        if ($client_id !== null && $client_id > 0) {
            $client_id = intval($client_id);
            $csql = mysqli_query($mysqli, "SELECT client_ai_opt_out FROM clients WHERE client_id = $client_id LIMIT 1");
            if ($csql && ($crow = mysqli_fetch_assoc($csql))) {
                if (intval($crow['client_ai_opt_out'] ?? 0) === 1) {
                    return false;
                }
            }
        }

        return true;
    }
}

if (!function_exists('aiChat')) {

    /**
     * Send a chat/completions request to the configured provider for a use case.
     *
     * Never throws / never fatals. On any failure it returns ['ok' => false] with
     * an 'error' string. Input message content is truncated to the configured
     * character cap, and the request carries a timeout and a max_tokens cap.
     *
     * @param array  $messages  OpenAI-style [['role'=>..,'content'=>..], ...]
     * @param string $use_case  Selects the model via ai_models.ai_model_use_case.
     * @param array  $opts       temperature(float), max_tokens(int),
     *                           client_id(int, for opt-out), timeout(int seconds),
     *                           check_enabled(bool, default true).
     * @return array ['ok'=>bool, 'content'=>string, 'error'=>?string, 'tokens'=>?int]
     */
    function aiChat(array $messages, string $use_case = 'General', array $opts = []): array {
        global $mysqli;

        $result = ['ok' => false, 'content' => '', 'error' => null, 'tokens' => null];

        if (!isset($mysqli) || !$mysqli) {
            $result['error'] = 'Database connection unavailable';
            return $result;
        }

        // Enable / per-client opt-out gate (skippable via check_enabled=false)
        $check_enabled = $opts['check_enabled'] ?? true;
        $client_id = isset($opts['client_id']) ? intval($opts['client_id']) : null;
        if ($check_enabled && !aiEnabled($client_id)) {
            $result['error'] = 'AI is disabled';
            return $result;
        }

        // Company AI safety caps (timeout + input char cap)
        $timeout = 25;
        $max_input_chars = 12000;
        $cfg = mysqli_query($mysqli, "SELECT config_ai_timeout_seconds, config_ai_max_input_chars FROM settings WHERE company_id = 1 LIMIT 1");
        if ($cfg && ($crow = mysqli_fetch_assoc($cfg))) {
            $timeout = intval($crow['config_ai_timeout_seconds'] ?? 0) ?: 25;
            $max_input_chars = intval($crow['config_ai_max_input_chars'] ?? 0) ?: 12000;
        }
        if (isset($opts['timeout']) && intval($opts['timeout']) > 0) {
            $timeout = intval($opts['timeout']);
        }

        // Resolve provider + model for the use case (parameterised).
        // If there is no model row for the requested use case, fall back to the
        // 'General' use case so newer use cases (e.g. 'Reply Draft') keep working
        // without requiring a dedicated ai_models row. This never changes the
        // behaviour of callers that already pass a configured use case.
        // ai_model_id ASC makes the pick deterministic if an admin ever ends up
        // with two rows sharing a use case (the CRUD forms block this going
        // forward, but pre-existing duplicates could still be out there).
        $use_case_esc = mysqli_real_escape_string($mysqli, $use_case);
        $sql = mysqli_query($mysqli, "SELECT ai_model_name, ai_provider_api_url, ai_provider_api_key
            FROM ai_models
            LEFT JOIN ai_providers ON ai_model_ai_provider_id = ai_provider_id
            WHERE ai_model_use_case = '$use_case_esc'
            ORDER BY ai_model_id ASC
            LIMIT 1");
        if ((!$sql || mysqli_num_rows($sql) === 0) && $use_case !== 'General') {
            $sql = mysqli_query($mysqli, "SELECT ai_model_name, ai_provider_api_url, ai_provider_api_key
                FROM ai_models
                LEFT JOIN ai_providers ON ai_model_ai_provider_id = ai_provider_id
                WHERE ai_model_use_case = 'General'
                ORDER BY ai_model_id ASC
                LIMIT 1");
        }
        if (!$sql || mysqli_num_rows($sql) === 0) {
            $result['error'] = 'No AI model configured for use case: ' . $use_case;
            return $result;
        }
        $row = mysqli_fetch_assoc($sql);
        $model_name = $row['ai_model_name'] ?? '';
        $base_url = $row['ai_provider_api_url'] ?? '';
        $key = decryptSetting($row['ai_provider_api_key'] ?? '');

        if (empty($base_url) || empty($model_name)) {
            $result['error'] = 'AI provider/model not fully configured';
            return $result;
        }

        // Providers are configured with their base API URL (e.g.
        // https://api.openai.com/v1); the OpenAI-compatible chat/completions
        // path is appended here so admins don't need to know/type it.
        $url = rtrim($base_url, '/') . '/chat/completions';

        // Truncate oversized message content to the char cap (per message)
        foreach ($messages as $i => $m) {
            if (isset($m['content']) && is_string($m['content']) && strlen($m['content']) > $max_input_chars) {
                $messages[$i]['content'] = substr($m['content'], 0, $max_input_chars);
            }
        }

        $data = [
            'model' => $model_name,
            'messages' => array_values($messages),
        ];
        if (isset($opts['temperature'])) {
            $data['temperature'] = floatval($opts['temperature']);
        }
        $data['max_tokens'] = (isset($opts['max_tokens']) && intval($opts['max_tokens']) > 0)
            ? intval($opts['max_tokens'])
            : 1500;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        if ($response === false) {
            $result['error'] = 'AI request failed: ' . curl_error($ch);
            curl_close($ch);
            return $result;
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $result['error'] = 'Invalid AI response';
            return $result;
        }
        if ($http_code >= 400 || isset($decoded['error'])) {
            $result['error'] = $decoded['error']['message'] ?? ('AI provider returned HTTP ' . $http_code);
            return $result;
        }
        if (!isset($decoded['choices'][0]['message']['content'])) {
            $result['error'] = 'AI response missing content';
            return $result;
        }

        $result['ok'] = true;
        $result['content'] = $decoded['choices'][0]['message']['content'];
        $result['tokens'] = isset($decoded['usage']['total_tokens']) ? intval($decoded['usage']['total_tokens']) : null;
        return $result;
    }
}

if (!function_exists('markTicketSummaryStale')) {

    /**
     * Flag a ticket's cached AI summary as stale so the next viewer regenerates it.
     *
     * Called from every ticket_replies INSERT point that a summary would care about.
     * Never fatals: if the cache table doesn't exist yet the UPDATE simply affects
     * nothing. (The read handler also re-checks based_on_reply_id as a safety net.)
     *
     * @param mysqli $mysqli
     * @param int    $ticket_id
     * @return void
     */
    function markTicketSummaryStale($mysqli, int $ticket_id): void {
        if (!$mysqli) {
            return;
        }
        $ticket_id = intval($ticket_id);
        if ($ticket_id <= 0) {
            return;
        }
        @mysqli_query($mysqli, "UPDATE ticket_ai_summaries SET stale = 1 WHERE ticket_id = $ticket_id");
    }
}
