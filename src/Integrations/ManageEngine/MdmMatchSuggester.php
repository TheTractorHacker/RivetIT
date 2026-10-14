<?php

declare(strict_types=1);

namespace ITFlow\Integrations\ManageEngine;

/**
 * "Propose matches" for the MDM mapping screen: suggests assets for an unmatched device by LOOSE similarity of serial (or IMEI / UDID) and name.
 * Suggestions only. Nothing here writes; the screen shows them and a person clicks to use one (and still has to press Link).
 * The exact serial/IMEI/UDID match is the sync's job and is not repeated: a candidate that already matches exactly would have been linked.
 */
final class MdmMatchSuggester
{
    public const THRESHOLD = 0.6;

    /** Lower-case letters and digits only. */
    public static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';
    }

    /** 0..1 similarity of two identifiers (serial-like). Needs at least 4 characters each. */
    public static function idScore(string $a, string $b): float
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if (strlen($a) < 4 || strlen($b) < 4) {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return 0.85;
        }
        $max = max(strlen($a), strlen($b));
        if ($max > 255) {
            return 0.0;
        }
        $ratio = 1 - levenshtein($a, $b) / $max;

        return $ratio >= 0.75 ? round($ratio, 3) : 0.0;
    }

    /** 0..1 similarity of two names. */
    public static function nameScore(string $a, string $b): float
    {
        $a = self::norm($a);
        $b = self::norm($b);
        if (strlen($a) < 3 || strlen($b) < 3) {
            return 0.0;
        }
        if ($a === $b) {
            return 0.9;
        }
        if (min(strlen($a), strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a))) {
            return 0.7;
        }
        $max = max(strlen($a), strlen($b));
        if ($max > 255) {
            return 0.0;
        }
        $ratio = 1 - levenshtein($a, $b) / $max;

        return $ratio >= 0.7 ? round($ratio * 0.8, 3) : 0.0;
    }

    /**
     * @param array<string,mixed> $device unmatched row (device_name, serial_number, imei, udid)
     * @param list<array<string,mixed>> $assets rows with asset_id, asset_name, asset_serial, asset_tag (candidates the caller may already offer)
     * @return list<array{asset_id:int,score:float,why:string}> best first
     */
    public static function suggest(array $device, array $assets, int $limit = 3): array
    {
        $ids = [(string) ($device['serial_number'] ?? ''), (string) ($device['imei'] ?? ''), (string) ($device['udid'] ?? '')];
        $name = (string) ($device['device_name'] ?? '');
        $out = [];
        foreach ($assets as $a) {
            $idBest = 0.0;
            foreach ($ids as $id) {
                foreach ([(string) ($a['asset_serial'] ?? ''), (string) ($a['asset_tag'] ?? '')] as $cand) {
                    $idBest = max($idBest, self::idScore($id, $cand));
                }
            }
            $nm = max(self::nameScore($name, (string) ($a['asset_name'] ?? '')), self::nameScore((string) ($device['serial_number'] ?? ''), (string) ($a['asset_name'] ?? '')));
            $score = max($idBest, $nm);
            if ($idBest >= 0.5 && $nm >= 0.5) {
                $score = min(1.0, $score + 0.05);
            }
            if ($score < self::THRESHOLD) {
                continue;
            }
            $why = $idBest >= $nm ? ($idBest >= 1.0 ? 'same serial' : 'similar serial') : ($nm >= 0.9 ? 'same name' : 'similar name');
            $out[] = ['asset_id' => (int) $a['asset_id'], 'score' => round($score, 2), 'why' => $why];
        }
        usort($out, static fn (array $x, array $y): int => $y['score'] <=> $x['score'] ?: $x['asset_id'] <=> $y['asset_id']);

        return array_slice($out, 0, $limit);
    }
}
