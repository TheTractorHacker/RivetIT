<?php

namespace ITFlow\Training\Media;

/**
 * YouTube / Vimeo link parsing (spec §4.4, plan A2). Only the provider and a VALIDATED id (plus
 * Vimeo's privacy hash) are ever kept; the pasted URL itself is never stored, fetched or
 * rendered. Every URL this app builds for a video (oEmbed, thumbnail, embed) is rebuilt from
 * these parts.
 *
 *   YouTube  id ^[A-Za-z0-9_-]{11}$ (case-sensitive) from watch?v=, youtu.be/, /embed/, /shorts/,
 *            /v/, youtube-nocookie.com/embed/ and m./music. hosts. /live/<id> parses but is
 *            rejected 'live' (live streams and Premieres cannot be used).
 *   Vimeo    id ^[0-9]{6,12}$, hash ^[0-9a-f]{6,20}$ from vimeo.com/<id>[/<h>],
 *            player.vimeo.com/video/<id>?h=<h> and vimeo.com/channels/<name>/<id>.
 *            Showcase, album and group pages are collections, not a video: null.
 */
final class VideoLink
{
    public const YOUTUBE_ID_RE = '/^[A-Za-z0-9_-]{11}$/';
    public const VIMEO_ID_RE = '/^[0-9]{6,12}$/';
    public const VIMEO_HASH_RE = '/^[0-9a-f]{6,20}$/';

    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];
    private const NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];
    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];

    /**
     * @return array{provider:string, id:string, hash:string, canonical_url:string, rejected?:string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2048 || preg_match('/[\x00-\x1F\x7F\s]/', $input) === 1) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) !== 1) {
            if (str_starts_with($input, '//')) {
                $input = 'https:' . $input;
            } else {
                $input = 'https://' . $input;
            }
        }
        $p = parse_url($input);
        if ($p === false || !isset($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $host = strtolower($p['host']);
        $path = $p['path'] ?? '/';
        $query = [];
        parse_str($p['query'] ?? '', $query);
        $segs = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));

        if ($host === 'youtu.be') {
            return self::youtube($segs[0] ?? '');
        }
        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            $first = strtolower($segs[0] ?? '');
            if ($first === 'watch') {
                return self::youtube(is_string($query['v'] ?? null) ? $query['v'] : '');
            }
            if (in_array($first, ['embed', 'shorts', 'v'], true)) {
                return self::youtube($segs[1] ?? '');
            }
            if ($first === 'live') {
                $v = self::youtube($segs[1] ?? '');
                return $v === null ? null : $v + ['rejected' => 'live'];
            }
            return null;
        }
        if (in_array($host, self::NOCOOKIE_HOSTS, true)) {
            return strtolower($segs[0] ?? '') === 'embed' ? self::youtube($segs[1] ?? '') : null;
        }
        if ($host === 'player.vimeo.com') {
            if (strtolower($segs[0] ?? '') !== 'video' || count($segs) !== 2) {
                return null;
            }
            return self::vimeo($segs[1], is_string($query['h'] ?? null) ? $query['h'] : '');
        }
        if (in_array($host, self::VIMEO_HOSTS, true)) {
            $first = strtolower($segs[0] ?? '');
            if ($first === 'channels' && count($segs) === 3) {
                return self::vimeo($segs[2], '');
            }
            if (count($segs) === 1) {
                return self::vimeo($segs[0], is_string($query['h'] ?? null) ? $query['h'] : '');
            }
            if (count($segs) === 2 && preg_match(self::VIMEO_ID_RE, $segs[0]) === 1) {
                return self::vimeo($segs[0], $segs[1]);
            }
            return null;   // showcase/<id>, album/<id>, groups/…, user pages, anything else
        }
        return null;
    }

    /** Validates stored parts (e.g. a video_verify request) and returns the parsed shape, or null. */
    public static function fromParts(string $provider, string $id, string $hash = ''): ?array
    {
        return match ($provider) {
            'youtube' => $hash === '' ? self::youtube($id) : null,
            'vimeo' => self::vimeo($id, $hash),
            default => null,
        };
    }

    /**
     * The player URL the app embeds (plan A2). $baseUrl is Ctx::baseUrl ('https://<host>'),
     * never the Host header; YouTube needs it as the JS API origin.
     */
    public static function embedUrl(array $v, string $baseUrl): string
    {
        $id = (string) ($v['id'] ?? '');
        if (($v['provider'] ?? '') === 'youtube' && preg_match(self::YOUTUBE_ID_RE, $id) === 1) {
            return 'https://www.youtube-nocookie.com/embed/' . $id . '?' . http_build_query([
                'enablejsapi' => 1, 'origin' => rtrim($baseUrl, '/'), 'playsinline' => 1, 'rel' => 0,
                'controls' => 0, 'fs' => 0, 'disablekb' => 1, 'iv_load_policy' => 3,
            ], '', '&', PHP_QUERY_RFC3986);
        }
        if (($v['provider'] ?? '') === 'vimeo' && preg_match(self::VIMEO_ID_RE, $id) === 1) {
            $q = [];
            $hash = (string) ($v['hash'] ?? '');
            if ($hash !== '' && preg_match(self::VIMEO_HASH_RE, $hash) === 1) {
                $q['h'] = $hash;
            }
            $q += ['dnt' => 1, 'playsinline' => 1];
            return 'https://player.vimeo.com/video/' . $id . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        }
        throw new \InvalidArgumentException('VideoLink::embedUrl: not a valid video');
    }

    private static function youtube(string $id): ?array
    {
        if (preg_match(self::YOUTUBE_ID_RE, $id) !== 1) {
            return null;
        }
        return ['provider' => 'youtube', 'id' => $id, 'hash' => '', 'canonical_url' => 'https://www.youtube.com/watch?v=' . $id];
    }

    private static function vimeo(string $id, string $hash): ?array
    {
        if (preg_match(self::VIMEO_ID_RE, $id) !== 1) {
            return null;
        }
        if ($hash !== '' && preg_match(self::VIMEO_HASH_RE, $hash) !== 1) {
            return null;
        }
        return ['provider' => 'vimeo', 'id' => $id, 'hash' => $hash,
                'canonical_url' => 'https://vimeo.com/' . $id . ($hash !== '' ? '/' . $hash : '')];
    }
}
