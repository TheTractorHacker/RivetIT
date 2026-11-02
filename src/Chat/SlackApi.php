<?php

namespace ITFlow\Chat;

/**
 * The few Slack Web API methods RivetIT calls with the bot token (chat.postMessage, chat.postEphemeral, views.open). Everything goes through
 * ChatHttp (SSRF-safe, pinned). The token is only ever sent in the Authorization header and never logged or returned.
 */
final class SlackApi
{
    public static function base(): string
    {
        return rtrim(defined('RIVETIT_SLACK_API_BASE') ? (string) RIVETIT_SLACK_API_BASE : 'https://slack.com/api', '/');
    }

    /** @return array{ok:bool,error:?string,data:array} data is Slack's decoded answer */
    public static function call(string $botToken, string $method, array $params): array
    {
        if ($botToken === '' || !preg_match('/^[a-zA-Z.]{3,40}$/', $method)) {
            return ['ok' => false, 'error' => 'no_token', 'data' => []];
        }
        $r = ChatHttp::postJson(self::base() . '/' . $method, $params, ['Authorization: Bearer ' . $botToken]);
        if ($r['error'] !== null || $r['status'] !== 200 || $r['data'] === null) {
            return ['ok' => false, 'error' => $r['error'] ?? 'http_' . (int) $r['status'], 'data' => []];
        }

        return ['ok' => !empty($r['data']['ok']), 'error' => empty($r['data']['ok']) ? (string) ($r['data']['error'] ?? 'unknown') : null, 'data' => $r['data']];
    }

    public static function postMessage(string $token, string $channel, string $text, array $blocks = [], ?string $threadTs = null): array
    {
        $p = ['channel' => $channel, 'text' => $text, 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false];
        if ($blocks) {
            $p['blocks'] = $blocks;
        }
        if ($threadTs !== null && $threadTs !== '') {
            $p['thread_ts'] = $threadTs;
        }

        return self::call($token, 'chat.postMessage', $p);
    }

    public static function openView(string $token, string $triggerId, array $view): array
    {
        return self::call($token, 'views.open', ['trigger_id' => $triggerId, 'view' => $view]);
    }
}
