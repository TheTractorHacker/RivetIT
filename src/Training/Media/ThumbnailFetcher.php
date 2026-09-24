<?php

namespace ITFlow\Training\Media;

/**
 * Fetches a video's poster image (spec §3.3), for the link-check card and, once the lesson is
 * saved, as the video check's thumbnail media.
 *
 *   YouTube  https://i.ytimg.com/vi/<id>/hqdefault.jpg - built from the validated id; the
 *            oEmbed thumbnail URL is not needed and not trusted.
 *   Vimeo    the oEmbed thumbnail_url, accepted only when it is https, on i.vimeocdn.com, and
 *            its path is /video/<token>[.jpg|.jpeg|.png|.webp] (query string dropped).
 *
 * The bytes are always decoded and re-encoded (ImageProcessor, JPEG) before anyone sees them,
 * so what comes back is an image we produced. Any failure returns null: a card without a
 * thumbnail is fine.
 */
final class ThumbnailFetcher
{
    private const MAX_BYTES = 2097152;
    private const VIMEO_PATH_RE = '#^/video/[0-9A-Za-z_-]{1,200}(?:\.(?:jpg|jpeg|png|webp))?$#';

    /** @return string|null JPEG bytes */
    public static function fetchBytes(array $v, ?string $oembedThumb): ?string
    {
        $url = self::url($v, $oembedThumb);
        if ($url === null) {
            return null;
        }
        try {
            $resp = SafeHttp::get($url, self::MAX_BYTES, ['Accept' => 'image/jpeg, image/png, image/webp']);
            if ($resp['status'] !== 200 || $resp['body'] === '') {
                return null;
            }
            $img = ImageProcessor::reencodeBytes($resp['body'], ['force_jpeg' => true, 'max_edge' => 1280]);
            return $img['bytes'];
        } catch (SafeHttpException | MediaException $e) {
            error_log('Training thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    /** The URL that would be fetched, or null when there is nothing acceptable to fetch. */
    public static function url(array $v, ?string $oembedThumb): ?string
    {
        $parts = VideoLink::fromParts((string) ($v['provider'] ?? ''), (string) ($v['id'] ?? ''), (string) ($v['hash'] ?? ''));
        if ($parts === null) {
            return null;
        }
        if ($parts['provider'] === 'youtube') {
            return 'https://i.ytimg.com/vi/' . $parts['id'] . '/hqdefault.jpg';
        }
        if ($oembedThumb === null || $oembedThumb === '' || strlen($oembedThumb) > 1024) {
            return null;
        }
        $p = parse_url($oembedThumb);
        if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || strtolower($p['host'] ?? '') !== 'i.vimeocdn.com'
            || isset($p['port']) || isset($p['user']) || isset($p['pass'])
            || preg_match(self::VIMEO_PATH_RE, $p['path'] ?? '') !== 1) {
            return null;
        }
        return 'https://i.vimeocdn.com' . $p['path'];
    }
}
