<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Text;

/**
 * Optional YouTube Data API v3 lookup (spec §4.4): the exact duration, whether the video is a
 * live stream or Premiere, whether embedding is allowed, and its privacy status - everything
 * oEmbed cannot tell us.
 *
 * The key comes only from Ctx::youtubeKey() (decrypted on demand) and is passed straight
 * into the request; SafeHttp redacts query strings in every error, so the key never reaches a
 * log line or an exception message.
 *
 * Returns
 *   array with found=true  and the details, when the video exists;
 *   array with found=false when the key works but no such (visible) video exists;
 *   null                   when the API refused the key or the quota (HTTP 400/401/403) or
 *                          answered with something unreadable.
 * Throws SafeHttpException for network problems (timeouts, DNS, TLS).
 */
final class YouTubeDataApi
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

    /**
     * @return array{found:bool, duration_s?:?int, live?:string, embeddable?:bool, privacy?:?string, title?:?string, channel?:?string}|null
     */
    public static function details(string $id, string $key): ?array
    {
        if (preg_match(VideoLink::YOUTUBE_ID_RE, $id) !== 1) {
            throw new \InvalidArgumentException('YouTubeDataApi::details: bad video id');
        }
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]{10,100}$/', $key) !== 1) {
            return null;
        }
        $url = self::ENDPOINT . '?' . http_build_query([
            'part' => 'contentDetails,status,snippet',
            'id' => $id,
            'fields' => 'items(contentDetails/duration,status/privacyStatus,status/embeddable,snippet/liveBroadcastContent,snippet/title,snippet/channelTitle)',
            'key' => $key,
        ], '', '&', PHP_QUERY_RFC3986);

        $resp = SafeHttp::get($url, 65536);
        if ($resp['status'] !== 200) {
            return null;
        }
        try {
            $j = json_decode($resp['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($j) || !is_array($j['items'] ?? null)) {
            return null;
        }
        $item = $j['items'][0] ?? null;
        if (!is_array($item)) {
            return ['found' => false];
        }
        $live = (string) ($item['snippet']['liveBroadcastContent'] ?? 'none');
        return [
            'found' => true,
            'duration_s' => self::isoDuration((string) ($item['contentDetails']['duration'] ?? '')),
            'live' => in_array($live, ['none', 'live', 'upcoming'], true) ? $live : 'none',
            'embeddable' => ($item['status']['embeddable'] ?? true) !== false,
            'privacy' => is_string($item['status']['privacyStatus'] ?? null) ? $item['status']['privacyStatus'] : null,
            'title' => is_string($item['snippet']['title'] ?? null) ? Text::clip($item['snippet']['title'], 255) : null,
            'channel' => is_string($item['snippet']['channelTitle'] ?? null) ? Text::clip($item['snippet']['channelTitle'], 255) : null,
        ];
    }

    /** ISO-8601 duration (PT1H2M3S, P1DT2H, P0D) to seconds; null when absent or zero. */
    public static function isoDuration(string $d): ?int
    {
        if (preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.\d+)?S)?)?$/', $d, $m) !== 1) {
            return null;
        }
        $s = (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
        return $s > 0 ? $s : null;
    }
}
