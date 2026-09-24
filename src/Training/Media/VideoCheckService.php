<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Scratch;

/**
 * Per-video checks for YouTube / Vimeo lessons (spec §3.3, §4.4).
 *
 * One training_video_checks row per (provider, id, privacy hash) - ids are case-sensitive
 * (ascii_bin) - shared by every lesson, language variant and course that uses the video, so a
 * verification done once is inherited everywhere while it is fresh (30 days).
 *
 *   check($url)     NETWORK, outside any transaction: parse, oEmbed, optional Data API,
 *                   thumbnail. Nothing is written; the result (and the thumbnail bytes) goes into
 *                   a 10-minute Core\Scratch 'vcheck' token, and the card gets the thumbnail as a
 *                   data: URI. The link is stored only when the author saves the lesson.
 *   persist($token) called by the lesson service BEFORE its transaction: ingests the thumbnail
 *                   (depth 0) and upserts the check row; a live stream is refused (422 video_live)
 *   verify(…)       the author pressed play (check frame or preview): records the played
 *                   duration (which must match a known duration within max(2 s, 2 %), else
 *                   422 video_duration_mismatch) or a player error. Never touches a lesson, so no
 *                   lesson_version bump and no autosave conflict.
 *   forLessons(…)   batch read for the builder, issues and the publish checks.
 */
final class VideoCheckService
{
    public const FRESH_DAYS = 30;
    public const TOKEN_TTL_S = 600;
    public const STATUSES = ['ok', 'not_found', 'private', 'embed_disabled', 'live', 'error'];

    /** Plain-language fixes shown on the card (spec §4.4). */
    public const STATUS_MESSAGES = [
        'private' => 'In YouTube Studio set Visibility to Unlisted.',
        'embed_disabled' => 'Studio › Video › Show more › Allow embedding.',
        'not_found' => 'Check the link.',
        'live' => "Live streams and Premieres can't be used.",
        'error' => 'The video service did not answer. Try again in a moment.',
    ];

    private MediaStore $store;

    public function __construct(private readonly Ctx $c, ?MediaStore $store = null)
    {
        $this->store = $store ?? new MediaStore($c);
    }

    /**
     * @return array{provider:string, id:string, hash:?string, canonical_url:string, status:string, title:?string, author:?string,
     *               duration_s:?int, duration_source:?string, thumb_data_uri:?string, check_token:string, check:?array, warnings:list<string>}
     * @throws MediaException 422 validation (not a video link)
     */
    public function check(string $url): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('VideoCheckService::check does network I/O and must not run inside Db::tx');
        }
        $v = VideoLink::parse($url);
        if ($v === null) {
            throw MediaException::validation('url', "That isn't a YouTube or Vimeo video link.");
        }
        $warnings = [];
        $status = 'error';
        $http = null;
        $title = $author = $thumbUrl = null;
        $duration = null;
        $source = null;

        if (($v['rejected'] ?? null) === 'live') {
            $status = 'live';
        } else {
            $oe = OEmbedClient::check($v, $this->c->baseUrl);
            [$status, $http, $title, $author, $thumbUrl] = [$oe['status'], $oe['http'], $oe['title'], $oe['author'], $oe['thumbnail_url']];
            [$duration, $source] = [$oe['duration_s'], $oe['duration_source']];

            if ($v['provider'] === 'youtube') {
                $key = $this->c->youtubeKey();
                if ($key !== null) {
                    try {
                        $d = YouTubeDataApi::details($v['id'], $key);
                        if ($d === null) {
                            $warnings[] = 'data_api_rejected';
                        } elseif ($d['found']) {
                            if (($d['live'] ?? 'none') !== 'none') {
                                $status = 'live';
                            } elseif (($d['embeddable'] ?? true) === false) {
                                $status = 'embed_disabled';
                            } elseif (($d['privacy'] ?? null) === 'private') {
                                $status = 'private';
                            } elseif ($status === 'error') {
                                $status = 'ok';   // oEmbed hiccup, the API saw it fine
                            }
                            if (($d['duration_s'] ?? null) !== null) {
                                $duration = $d['duration_s'];
                                $source = 'data_api';
                            }
                            $title ??= $d['title'] ?? null;
                            $author ??= $d['channel'] ?? null;
                        }
                    } catch (SafeHttpException $e) {
                        error_log('Training YouTube Data API: ' . $e->getMessage());
                        $warnings[] = 'data_api_unavailable';
                    } finally {
                        unset($key);
                    }
                }
                if ($duration === null && $status === 'ok') {
                    $warnings[] = 'duration_on_play';
                }
            }
        }

        $thumb = $status === 'ok' ? ThumbnailFetcher::fetchBytes($v, $thumbUrl) : null;
        $token = Scratch::put('vcheck', $this->c->userId, [
            'provider' => $v['provider'],
            'id' => $v['id'],
            'hash' => $v['hash'],
            'status' => $status,
            'http' => $http,
            'title' => $title,
            'author' => $author,
            'meta_duration_s' => $duration,
            'meta_duration_source' => $source,
            'checked_at_utc' => Clock::nowUtc(),
            'thumb_b64' => $thumb === null ? null : base64_encode($thumb),
        ], self::TOKEN_TTL_S);

        $existing = $this->find($v['provider'], $v['id'], $v['hash']);
        return [
            'provider' => $v['provider'],
            'id' => $v['id'],
            'hash' => $v['hash'] === '' ? null : $v['hash'],
            'canonical_url' => $v['canonical_url'],
            'status' => $status,
            'status_message' => self::STATUS_MESSAGES[$status] ?? null,
            'title' => $title,
            'author' => $author,
            'duration_s' => $duration,
            'duration_source' => $source,
            'thumb_data_uri' => $thumb === null ? null : 'data:image/jpeg;base64,' . base64_encode($thumb),
            'check_token' => $token,
            'check' => $existing === null ? null : $this->present($existing),
            'warnings' => $warnings,
        ];
    }

    /**
     * Stores the result of a check() (called by lesson_create / lesson_update before their
     * transaction). The token may be used more than once until it expires (a retried save).
     *
     * @return array the VideoCheck shape of the stored row
     * @throws MediaException 422 validation (expired token) | video_live
     */
    public function persist(string $checkToken): array
    {
        MediaStore::assertOutsideTx();
        $d = preg_match('/^[0-9a-f]{32}$/', $checkToken) === 1 ? Scratch::get('vcheck', $checkToken, $this->c->userId) : null;
        if ($d === null) {
            throw MediaException::validation('video_check_token', 'The video check expired. Paste the link again.');
        }
        $v = VideoLink::fromParts((string) ($d['provider'] ?? ''), (string) ($d['id'] ?? ''), (string) ($d['hash'] ?? ''));
        $status = (string) ($d['status'] ?? '');
        if ($v === null || !in_array($status, self::STATUSES, true)) {
            throw MediaException::validation('video_check_token', 'The video check expired. Paste the link again.');
        }
        if ($status === 'live') {
            throw new MediaException(422, 'video_live', self::STATUS_MESSAGES['live']);
        }

        $thumbId = null;
        $b64 = $d['thumb_b64'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if ($size !== false && $size[2] === IMAGETYPE_JPEG) {
                try {
                    $m = $this->store->ingestBytes($bytes, 'image', 'image/jpeg', 'jpg', $v['provider'] . '-' . $v['id'] . '.jpg',
                        ['width' => (int) $size[0], 'height' => (int) $size[1]], 'video_thumb');
                    $thumbId = (int) $m['media_id'];
                } catch (MediaException $e) {
                    // A full media budget must not block saving the link; the card just has no thumbnail.
                    error_log('Training video thumbnail not stored: ' . $e->getMessage());
                }
            }
        }

        $dur = $d['meta_duration_s'] ?? null;
        $dur = (is_int($dur) && $dur > 0) ? $dur : null;
        $src = in_array($d['meta_duration_source'] ?? null, ['oembed', 'data_api'], true) && $dur !== null ? $d['meta_duration_source'] : null;
        $checkedAt = is_string($d['checked_at_utc'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/', $d['checked_at_utc']) === 1
            ? $d['checked_at_utc'] : Clock::nowUtc();
        $http = is_int($d['http'] ?? null) && $d['http'] >= 0 && $d['http'] <= 999 ? $d['http'] : null;

        Db::exec($this->c->db, "INSERT INTO training_video_checks
                (vcheck_provider, vcheck_ext_id, vcheck_ext_hash, vcheck_title, vcheck_author, vcheck_thumb_media_id, vcheck_status,
                 vcheck_http, vcheck_meta_duration_s, vcheck_meta_duration_source, vcheck_checked_at_utc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                vcheck_title = COALESCE(VALUES(vcheck_title), vcheck_title),
                vcheck_author = COALESCE(VALUES(vcheck_author), vcheck_author),
                vcheck_thumb_media_id = COALESCE(VALUES(vcheck_thumb_media_id), vcheck_thumb_media_id),
                vcheck_http = IF(VALUES(vcheck_status) = 'error' AND vcheck_status IS NOT NULL, vcheck_http, VALUES(vcheck_http)),
                vcheck_checked_at_utc = IF(VALUES(vcheck_status) = 'error' AND vcheck_status IS NOT NULL, vcheck_checked_at_utc, VALUES(vcheck_checked_at_utc)),
                vcheck_status = IF(VALUES(vcheck_status) = 'error' AND vcheck_status IS NOT NULL, vcheck_status, VALUES(vcheck_status)),
                vcheck_meta_duration_source = IF(VALUES(vcheck_meta_duration_s) IS NULL, vcheck_meta_duration_source, VALUES(vcheck_meta_duration_source)),
                vcheck_meta_duration_s = COALESCE(VALUES(vcheck_meta_duration_s), vcheck_meta_duration_s)",
            'sssssisiiss', [$v['provider'], $v['id'], $v['hash'], self::str($d['title'] ?? null), self::str($d['author'] ?? null), $thumbId,
                            $status, $http, $dur, $src, $checkedAt]);

        return $this->present($this->find($v['provider'], $v['id'], $v['hash']) ?? throw new \RuntimeException('video check row missing after upsert'));
    }

    /**
     * Records the author's play of the video (ok) or a player error (!ok).
     *
     * @return array VideoCheck shape
     * @throws MediaException 422 validation | video_duration_mismatch
     */
    public function verify(string $provider, string $extId, string $extHash, int $durationS, bool $ok, ?string $errorCode): array
    {
        $v = VideoLink::fromParts($provider, $extId, $extHash);
        if ($v === null) {
            throw MediaException::validation('ext_id', 'That is not a valid video.');
        }
        $now = Clock::nowUtc();
        if ($ok) {
            if ($durationS < 1 || $durationS > 172800) {
                throw MediaException::validation('duration_s', 'The played length is not valid.');
            }
            $row = $this->find($v['provider'], $v['id'], $v['hash']);
            $meta = $row === null || $row['vcheck_meta_duration_s'] === null ? null : (int) $row['vcheck_meta_duration_s'];
            if ($meta !== null && $meta > 0 && abs($durationS - $meta) * 100 > max(200, 2 * $meta)) {
                throw new MediaException(422, 'video_duration_mismatch',
                    'The video that played is ' . self::mmss($durationS) . ' long, but this video should be ' . self::mmss($meta)
                    . '. Check the link, or wait a moment and play it again.');
            }
            Db::exec($this->c->db, "INSERT INTO training_video_checks
                    (vcheck_provider, vcheck_ext_id, vcheck_ext_hash, vcheck_play_duration_s, vcheck_verified_at_utc, vcheck_verified_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE vcheck_play_duration_s = VALUES(vcheck_play_duration_s),
                    vcheck_verified_at_utc = VALUES(vcheck_verified_at_utc), vcheck_verified_by = VALUES(vcheck_verified_by)",
                'sssisi', [$v['provider'], $v['id'], $v['hash'], $durationS, $now, $this->c->userId]);
        } else {
            $code = ($errorCode !== null && preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $errorCode) === 1) ? $errorCode : 'player_error';
            Db::exec($this->c->db, "INSERT INTO training_video_checks
                    (vcheck_provider, vcheck_ext_id, vcheck_ext_hash, vcheck_last_error, vcheck_last_error_at_utc)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE vcheck_last_error = VALUES(vcheck_last_error), vcheck_last_error_at_utc = VALUES(vcheck_last_error_at_utc)",
                'sssss', [$v['provider'], $v['id'], $v['hash'], $code, $now]);
        }
        return $this->present($this->find($v['provider'], $v['id'], $v['hash']) ?? throw new \RuntimeException('video check row missing after upsert'));
    }

    /**
     * Batch read. $pairs: list of [provider, ext_id, ext_hash] or {provider, ext_id, ext_hash}.
     *
     * @return array<string, array> key(provider, ext_id, ext_hash) => VideoCheck (only videos that have a row)
     */
    public function forLessons(array $pairs): array
    {
        $want = [];
        foreach ($pairs as $p) {
            $provider = (string) ($p['provider'] ?? $p[0] ?? '');
            $id = (string) ($p['ext_id'] ?? $p['id'] ?? $p[1] ?? '');
            $hash = (string) ($p['ext_hash'] ?? $p['hash'] ?? $p[2] ?? '');
            if (VideoLink::fromParts($provider, $id, $hash) !== null) {
                $want[self::key($provider, $id, $hash)] = [$provider, $id, $hash];
            }
        }
        $out = [];
        foreach (array_chunk(array_values($want), 200) as $chunk) {
            $sql = 'SELECT ' . self::COLUMNS . ' FROM training_video_checks v LEFT JOIN users u ON u.user_id = v.vcheck_verified_by
                WHERE (v.vcheck_provider, v.vcheck_ext_id, v.vcheck_ext_hash) IN (' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')) . ')';
            foreach (Db::all($this->c->db, $sql, str_repeat('sss', count($chunk)), array_merge(...$chunk)) as $row) {
                $out[self::key((string) $row['vcheck_provider'], (string) $row['vcheck_ext_id'], (string) $row['vcheck_ext_hash'])] = $this->present($row);
            }
        }
        return $out;
    }

    public static function key(string $provider, string $extId, string $extHash): string
    {
        return $provider . ':' . $extId . ':' . $extHash;
    }

    /** @return array<string, mixed>|null the row (with verified_by_name) */
    public function find(string $provider, string $extId, string $extHash): ?array
    {
        return Db::one($this->c->db, 'SELECT ' . self::COLUMNS . ' FROM training_video_checks v LEFT JOIN users u ON u.user_id = v.vcheck_verified_by
            WHERE v.vcheck_provider = ? AND v.vcheck_ext_id = ? AND v.vcheck_ext_hash = ?', 'sss', [$provider, $extId, $extHash]);
    }

    /** The shared VideoCheck JSON shape (spec §6.1). */
    public function present(array $row): array
    {
        $int = static fn($v): ?int => $v === null ? null : (int) $v;
        $verifiedAt = $row['vcheck_verified_at_utc'] ?? null;
        $errorAt = $row['vcheck_last_error_at_utc'] ?? null;
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::FRESH_DAYS . ' days')->format('Y-m-d H:i:s.v');
        $fresh = $verifiedAt !== null && (string) $verifiedAt >= $cutoff && ($errorAt === null || (string) $errorAt <= (string) $verifiedAt);
        $thumb = $int($row['vcheck_thumb_media_id'] ?? null);
        $meta = $int($row['vcheck_meta_duration_s'] ?? null);
        $play = $int($row['vcheck_play_duration_s'] ?? null);
        return [
            'id' => (int) $row['vcheck_id'],
            'provider' => (string) $row['vcheck_provider'],
            'ext_id' => (string) $row['vcheck_ext_id'],
            // No hash is null in every JSON shape (LessonDetail video.hash, revision "h"); the column stores ''.
            'ext_hash' => (string) $row['vcheck_ext_hash'] === '' ? null : (string) $row['vcheck_ext_hash'],
            'title' => $row['vcheck_title'] === null ? null : (string) $row['vcheck_title'],
            'author' => $row['vcheck_author'] === null ? null : (string) $row['vcheck_author'],
            'thumb_media_id' => $thumb,
            'thumb_url' => $thumb === null ? null : MediaStore::url($thumb),
            'status' => $row['vcheck_status'] === null ? null : (string) $row['vcheck_status'],
            'status_message' => $row['vcheck_status'] === null ? null : (self::STATUS_MESSAGES[(string) $row['vcheck_status']] ?? null),
            'meta_duration_s' => $meta,
            'meta_duration_source' => $row['vcheck_meta_duration_source'] === null ? null : (string) $row['vcheck_meta_duration_source'],
            'checked_at' => Clock::toIso($row['vcheck_checked_at_utc'] === null ? null : (string) $row['vcheck_checked_at_utc'], true),
            'play_duration_s' => $play,
            'duration_s' => $meta ?? $play,
            'verified_at' => Clock::toIso($verifiedAt === null ? null : (string) $verifiedAt, true),
            'verified_by' => $int($row['vcheck_verified_by'] ?? null),
            'verified_by_name' => ($row['verified_by_name'] ?? null) === null ? null : (string) $row['verified_by_name'],
            'verified_fresh' => $fresh,
            'last_error' => $row['vcheck_last_error'] === null ? null : (string) $row['vcheck_last_error'],
            'last_error_at' => Clock::toIso($errorAt === null ? null : (string) $errorAt, true),
        ];
    }

    private const COLUMNS = 'v.vcheck_id, v.vcheck_provider, v.vcheck_ext_id, v.vcheck_ext_hash, v.vcheck_title, v.vcheck_author,
        v.vcheck_thumb_media_id, v.vcheck_status, v.vcheck_http, v.vcheck_meta_duration_s, v.vcheck_meta_duration_source,
        v.vcheck_checked_at_utc, v.vcheck_play_duration_s, v.vcheck_verified_at_utc, v.vcheck_verified_by, v.vcheck_last_error,
        v.vcheck_last_error_at_utc, u.user_name AS verified_by_name';

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function mmss(int $s): string
    {
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
