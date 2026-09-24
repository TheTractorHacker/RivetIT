<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Text;

/**
 * Server-side oEmbed lookups for a parsed video (VideoLink shape), through SafeHttp only.
 *
 *   YouTube  https://www.youtube.com/oembed?format=json&url=<canonical watch URL>
 *            200 ok | 401 private | 403 embedding disabled | 400/404 not found.
 *            oEmbed carries no duration: it comes from the Data API (when a key is set) or from
 *            the author's verified play.
 *   Vimeo    https://vimeo.com/api/oembed.json?url=<canonical URL incl. privacy hash>
 *            with Referer = <baseUrl>/ so domain-restricted videos answer for this site.
 *            200 ok (with `duration` in seconds) | 403 private / domain-restricted | 404 not found.
 *
 * Never throws for a provider or network problem: the result's status says what happened
 * ('error' with error code 'network' / 'bad_response' when the provider could not be read).
 * Title and author are scrubbed and clipped to their 255-character columns here.
 */
final class OEmbedClient
{
    private const MAX_BYTES = 65536;

    /**
     * @param array{provider:string, id:string, hash?:string, canonical_url:string} $v
     * @return array{status:string, http:?int, title:?string, author:?string, thumbnail_url:?string,
     *               duration_s:?int, duration_source:?string, error:?string}
     */
    public static function check(array $v, string $baseUrl): array
    {
        $provider = (string) ($v['provider'] ?? '');
        $valid = VideoLink::fromParts($provider, (string) ($v['id'] ?? ''), (string) ($v['hash'] ?? ''));
        if ($valid === null) {
            throw new \InvalidArgumentException('OEmbedClient::check: not a valid video');
        }
        if ($provider === 'youtube') {
            $url = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($valid['canonical_url']);
            $headers = [];
        } else {
            $url = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($valid['canonical_url']);
            $headers = ['Referer' => rtrim($baseUrl, '/') . '/'];
        }

        try {
            $resp = SafeHttp::get($url, self::MAX_BYTES, $headers);
        } catch (SafeHttpException $e) {
            error_log('Training oEmbed: ' . $e->getMessage());
            return self::result('error', $e->status, error: $e->reason === 'redirect' ? 'bad_response' : 'network');
        }

        $status = $resp['status'];
        if ($status === 200) {
            try {
                $j = json_decode($resp['body'], true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return self::result('error', $status, error: 'bad_response');
            }
            if (!is_array($j)) {
                return self::result('error', $status, error: 'bad_response');
            }
            $duration = null;
            if ($provider === 'vimeo' && isset($j['duration']) && is_numeric($j['duration'])) {
                $d = (int) $j['duration'];
                $duration = ($d > 0 && $d < 86400 * 7) ? $d : null;
            }
            return self::result('ok', $status,
                self::text($j['title'] ?? null),
                self::text($j['author_name'] ?? null),
                is_string($j['thumbnail_url'] ?? null) ? $j['thumbnail_url'] : null,
                $duration,
                $duration !== null ? 'oembed' : null);
        }
        $mapped = match (true) {
            $provider === 'youtube' && $status === 401 => 'private',
            $provider === 'youtube' && $status === 403 => 'embed_disabled',
            $provider === 'vimeo' && ($status === 401 || $status === 403) => 'private',
            $status === 400 || $status === 404 || $status === 410 => 'not_found',
            default => 'error',
        };
        return self::result($mapped, $status, error: $mapped === 'error' ? 'http_' . $status : null);
    }

    private static function text(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub((string) $v, 'UTF-8')) ?? '');
        return $s === '' ? null : Text::clip($s, 255);
    }

    private static function result(string $status, ?int $http, ?string $title = null, ?string $author = null, ?string $thumb = null,
                                   ?int $duration = null, ?string $source = null, ?string $error = null): array
    {
        return ['status' => $status, 'http' => $http, 'title' => $title, 'author' => $author, 'thumbnail_url' => $thumb,
                'duration_s' => $duration, 'duration_source' => $source, 'error' => $error];
    }
}
