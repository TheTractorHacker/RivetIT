<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Media\SafeHttp;

/**
 * The publish-time availability re-check of external videos (spec §3.5 PublishValidator,
 * network=true): one fresh oEmbed request per distinct video, all in parallel through
 * SafeHttp::getMulti with a 20 s total budget. A video whose training_video_checks row was
 * checked less than 10 minutes ago is not fetched again.
 *
 * Outcome per video: ok | unavailable (oEmbed answered 401/403/404: deleted, private or
 * embedding disabled) | failed (network error, timeout, or any other answer). "failed" is only
 * ever a warning - a flaky network must never block publishing.
 */
final class VideoRecheck
{
    public const BUDGET_MS = 20000;
    public const FRESH_S = 600;
    private const MAX_BYTES = 65536;

    /**
     * @param list<array{provider:string, id:string, hash:string, vcheck:?array}> $videos distinct videos
     * @return array<string, array{status:string, http:?int, reason:?string, reused:bool}> keyed "provider:id:hash"
     */
    public static function run(string $baseUrl, array $videos, ?int $nowTs = null): array
    {
        $nowTs ??= time();
        $out = [];
        $requests = [];
        foreach ($videos as $v) {
            $key = RevisionBuilder::videoKey($v['provider'], $v['id'], $v['hash']);
            $vc = $v['vcheck'];
            $checked = $vc === null ? null : self::ts($vc['vcheck_checked_at_utc'] ?? null);
            if ($checked !== null && $nowTs - $checked < self::FRESH_S && $vc['vcheck_status'] !== null) {
                $out[$key] = self::fromStatus((string) $vc['vcheck_status'], $vc['vcheck_http'] === null ? null : (int) $vc['vcheck_http']);
                continue;
            }
            $requests[$key] = self::request($v, $baseUrl);
        }
        if ($requests === []) {
            return $out;
        }
        try {
            $responses = SafeHttp::getMulti($requests, self::BUDGET_MS);
        } catch (\Throwable $e) {
            foreach (array_keys($requests) as $key) {
                $out[$key] = ['status' => 'failed', 'http' => null, 'reason' => 'network', 'reused' => false];
            }
            return $out;
        }
        foreach (array_keys($requests) as $key) {
            $r = is_array($responses[$key] ?? null) ? $responses[$key] : [];
            $http = isset($r['status']) ? (int) $r['status'] : (isset($r['http']) ? (int) $r['http'] : null);
            if (!empty($r['error']) || $http === null || $http === 0) {
                $out[$key] = ['status' => 'failed', 'http' => $http ?: null, 'reason' => 'network', 'reused' => false];
            } elseif ($http === 200) {
                $out[$key] = ['status' => 'ok', 'http' => 200, 'reason' => null, 'reused' => false];
            } elseif (in_array($http, [401, 403, 404], true)) {
                $out[$key] = ['status' => 'unavailable', 'http' => $http, 'reason' => $http === 404 ? 'not_found' : 'private', 'reused' => false];
            } else {
                $out[$key] = ['status' => 'failed', 'http' => $http, 'reason' => 'http', 'reused' => false];
            }
        }
        return $out;
    }

    /** The oEmbed request for one video; URLs are built only from validated ids. */
    public static function request(array $v, string $baseUrl): array
    {
        if ($v['provider'] === 'youtube') {
            $watch = 'https://www.youtube.com/watch?v=' . rawurlencode($v['id']);
            return ['url' => 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($watch), 'headers' => [], 'max_bytes' => self::MAX_BYTES];
        }
        $page = 'https://vimeo.com/' . rawurlencode($v['id']) . ($v['hash'] !== '' ? '/' . rawurlencode($v['hash']) : '');
        return [
            'url' => 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($page),
            // Domain-restricted Vimeo videos answer oEmbed only with the embedding site's Referer.
            'headers' => ['Referer' => rtrim($baseUrl, '/') . '/'],
            'max_bytes' => self::MAX_BYTES,
        ];
    }

    private static function fromStatus(string $status, ?int $http): array
    {
        return match ($status) {
            'ok' => ['status' => 'ok', 'http' => $http, 'reason' => null, 'reused' => true],
            'not_found', 'private', 'embed_disabled', 'live' => ['status' => 'unavailable', 'http' => $http, 'reason' => $status, 'reused' => true],
            default => ['status' => 'failed', 'http' => $http, 'reason' => $status, 'reused' => true],
        };
    }

    private static function ts(?string $utc): ?int
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $iso = Clock::toIso($utc, true);
        return $iso === null ? null : strtotime($iso);
    }
}
