<?php

namespace ITFlow\Training\Core;

/**
 * The composite digest that freezes a finalized training session (Phase 2 spec §2.4, §3.1).
 *
 *   tsession_sha256 = sha256(Canonical::doc([
 *       'v'         => '1',
 *       'session'   => the session's HashSpecs::DIGESTS columns (text-protocol strings / null),
 *       'attendees' => one object per attendee row (removed rows included), sorted by
 *                      (int) tattendee_contact_id,
 *   ]))
 *
 * It is computed ONLY here, and only over a TEXT-PROTOCOL re-read (computeFromDb), never
 * from bound values: a session saved with start_time '07:30' re-reads as '07:30:00', and the
 * digest must be over what the verifier will read back years later. SessionService calls
 * computeFromDb() inside the finalize transaction after the finalize UPDATE; LedgerVerifier
 * calls it to prove a finalized session was not changed.
 */
final class SessionDigest
{
    public const TABLE = 'training_sessions';

    /**
     * The explicit select lists for version $v: ['session' => '...', 'attendees' => '...'].
     *
     * @return array{session:string, attendees:string}
     */
    public static function columnsSql(int $v = 1): array
    {
        $spec = self::spec($v);
        return [
            'session' => implode(', ', $spec['session']),
            'attendees' => implode(', ', $spec['attendees']),
        ];
    }

    /**
     * @param array<string, mixed>       $sessionRow   must hold every session digest column (extra keys ignored)
     * @param list<array<string, mixed>> $attendeeRows each must hold every attendee digest column (extra keys ignored)
     */
    public static function compute(array $sessionRow, array $attendeeRows, int $v = 1): string
    {
        $spec = self::spec($v);
        $session = self::subset($sessionRow, $spec['session'], 'session');
        $attendees = [];
        foreach (array_values($attendeeRows) as $i => $a) {
            if (!is_array($a)) {
                throw new \InvalidArgumentException("SessionDigest: attendee #$i is not a row");
            }
            $attendees[] = self::subset($a, $spec['attendees'], "attendee #$i");
        }
        usort($attendees, static fn(array $x, array $y) => (int) $x['tattendee_contact_id'] <=> (int) $y['tattendee_contact_id']);

        return Canonical::sha256(Canonical::doc([
            'v' => (string) $v,
            'session' => $session,
            'attendees' => $attendees,
        ]));
    }

    /**
     * Re-reads the session and ALL its attendee rows with $db->query() (text protocol; the id is
     * interpolated as an int) and computes the digest.
     *
     * @throws \RuntimeException 'session_not_found' when the session row does not exist
     */
    public static function computeFromDb(\mysqli $db, int $sessionId, int $v = 1): string
    {
        $sql = self::columnsSql($v);
        $id = (int) $sessionId;
        Db::ensureUtf8mb4($db);

        $res = $db->query("SELECT {$sql['session']} FROM training_sessions WHERE tsession_id = $id");
        $session = $res->fetch_assoc();
        $res->free();
        if (!$session) {
            throw new \RuntimeException('session_not_found');
        }
        $res = $db->query("SELECT {$sql['attendees']} FROM training_session_attendees WHERE tattendee_tsession_id = $id ORDER BY tattendee_contact_id");
        $attendees = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();

        return self::compute($session, $attendees, $v);
    }

    /** @return array{session:list<string>, attendees:list<string>} */
    private static function spec(int $v): array
    {
        if (!isset(HashSpecs::DIGESTS[self::TABLE][$v])) {
            throw new \InvalidArgumentException("SessionDigest: no digest spec v$v");
        }
        return HashSpecs::DIGESTS[self::TABLE][$v];
    }

    /** @return array<string, ?string> */
    private static function subset(array $row, array $cols, string $label): array
    {
        $out = [];
        foreach ($cols as $c) {
            if (!array_key_exists($c, $row)) {
                throw new \InvalidArgumentException("SessionDigest: $label needs column '$c'");
            }
            $out[$c] = $row[$c];
        }
        return RowHasher::normalize($out);
    }
}
