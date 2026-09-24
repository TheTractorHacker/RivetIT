<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\MediaStore;

/**
 * External-video verification as authoring sees it (spec §4.4): one training_video_checks row
 * per (provider, id, privacy hash), shared by every lesson, variant and course using that
 * video. Read-only here - VideoCheckService (Lane B) is the only writer.
 *
 * A verification counts when it is at most 30 days old and no error was recorded after it.
 */
final class VideoRefs
{
    public const FRESH_DAYS = 30;
    public const MIN_DURATION_S = 10;

    private const COLS = 'v.vcheck_id, v.vcheck_provider, v.vcheck_ext_id, v.vcheck_ext_hash, v.vcheck_title, v.vcheck_author,
        v.vcheck_thumb_media_id, v.vcheck_status, v.vcheck_http, v.vcheck_meta_duration_s, v.vcheck_meta_duration_source,
        v.vcheck_checked_at_utc, v.vcheck_play_duration_s, v.vcheck_verified_at_utc, v.vcheck_verified_by,
        v.vcheck_last_error, v.vcheck_last_error_at_utc, u.user_name AS verified_by_name';

    public static function key(string $provider, string $extId, ?string $extHash): string
    {
        return $provider . '|' . $extId . '|' . ($extHash ?? '');
    }

    /**
     * @param list<array{0:string,1:string,2:?string}> $triples [provider, ext_id, ext_hash]
     * @return array<string, array<string, mixed>> key() => row
     */
    public static function rows(\mysqli $db, array $triples): array
    {
        $out = [];
        foreach ($triples as [$provider, $extId, $extHash]) {
            if (!in_array($provider, ['youtube', 'vimeo'], true) || $extId === '') {
                continue;
            }
            $k = self::key($provider, $extId, $extHash);
            if (array_key_exists($k, $out)) {
                continue;
            }
            $out[$k] = Db::one(
                $db,
                'SELECT ' . self::COLS . ' FROM training_video_checks v LEFT JOIN users u ON u.user_id = v.vcheck_verified_by
                 WHERE v.vcheck_provider = ? AND v.vcheck_ext_id = ? AND v.vcheck_ext_hash = ?',
                'sss',
                [$provider, $extId, (string) ($extHash ?? '')]
            );
        }
        return array_filter($out, static fn($r) => $r !== null);
    }

    public static function row(\mysqli $db, string $provider, string $extId, ?string $extHash): ?array
    {
        return self::rows($db, [[$provider, $extId, $extHash]])[self::key($provider, $extId, $extHash)] ?? null;
    }

    /** Verification state of a check row (null = never checked). */
    public static function state(?array $row): array
    {
        $verifiedAt = $row['vcheck_verified_at_utc'] ?? null;
        $errorAt = $row['vcheck_last_error_at_utc'] ?? null;
        $verified = $verifiedAt !== null;
        $fresh = false;
        if ($verified) {
            $cut = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::FRESH_DAYS . ' days')->format('Y-m-d H:i:s.v');
            $fresh = strcmp((string) $verifiedAt, $cut) >= 0;
        }
        $failedAfter = $errorAt !== null && ($verifiedAt === null || strcmp((string) $errorAt, (string) $verifiedAt) > 0);
        $status = $row['vcheck_status'] ?? null;
        $duration = self::duration($row);
        return [
            'verified' => $verified,
            'fresh' => $verified && $fresh && !$failedAfter,
            'stale' => $verified && !$fresh,
            'failed' => $failedAfter || ($status !== null && $status !== 'ok'),
            'duration_s' => $duration,
        ];
    }

    /** Best known duration: the provider's metadata, else the verified play length. */
    public static function duration(?array $row): ?int
    {
        if ($row === null) {
            return null;
        }
        if ($row['vcheck_meta_duration_s'] !== null && (int) $row['vcheck_meta_duration_s'] > 0) {
            return (int) $row['vcheck_meta_duration_s'];
        }
        if ($row['vcheck_play_duration_s'] !== null && (int) $row['vcheck_play_duration_s'] > 0) {
            return (int) $row['vcheck_play_duration_s'];
        }
        return null;
    }

    /** The §6.1 VideoCheck shape. */
    public static function shape(array $row): array
    {
        $state = self::state($row);
        return [
            'provider' => (string) $row['vcheck_provider'],
            'ext_id' => (string) $row['vcheck_ext_id'],
            'ext_hash' => (string) $row['vcheck_ext_hash'] === '' ? null : (string) $row['vcheck_ext_hash'],
            'title' => $row['vcheck_title'],
            'author' => $row['vcheck_author'],
            'thumb_url' => $row['vcheck_thumb_media_id'] === null ? null : MediaStore::url((int) $row['vcheck_thumb_media_id']),
            'status' => $row['vcheck_status'],
            'meta_duration_s' => $row['vcheck_meta_duration_s'] === null ? null : (int) $row['vcheck_meta_duration_s'],
            'meta_duration_source' => $row['vcheck_meta_duration_source'],
            'checked_at' => Clock::toIso($row['vcheck_checked_at_utc'], true),
            'play_duration_s' => $row['vcheck_play_duration_s'] === null ? null : (int) $row['vcheck_play_duration_s'],
            'verified_at' => Clock::toIso($row['vcheck_verified_at_utc'], true),
            'verified_by_name' => $row['verified_by_name'] ?? null,
            'verified_fresh' => $state['fresh'],
            'last_error' => $row['vcheck_last_error'],
            'last_error_at' => Clock::toIso($row['vcheck_last_error_at_utc'], true),
        ];
    }

    /** The canonical watch URL for a stored (validated) provider id. */
    public static function canonicalUrl(string $provider, string $extId, ?string $extHash): ?string
    {
        if ($provider === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $extId) === 1) {
            return 'https://www.youtube.com/watch?v=' . $extId;
        }
        if ($provider === 'vimeo' && preg_match('/^[0-9]{6,12}$/', $extId) === 1) {
            return 'https://vimeo.com/' . $extId . (($extHash !== null && preg_match('/^[0-9a-f]{6,20}$/', $extHash) === 1) ? '/' . $extHash : '');
        }
        return null;
    }
}
