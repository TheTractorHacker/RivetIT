<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\Eligibility;

/**
 * Lane K2's only door to its sibling lanes' frozen interfaces (P3 spec §9.2): K3's
 * Kiosk\Bridge\RecordsBridge (eligibility SQL, reconcile, P2 link state, per-target scope) and
 * K5's Kiosk\Bridge\TrainerBridge (is this contact an active trainer?). K2 never names a P2
 * table or class (§0.7); every call goes through those bridges when their class exists.
 *
 * Until a sibling lane has merged, each method falls back to the spec's documented behaviour:
 *   eligibleSql   "$a.contact_archived_at IS NULL AND $a.contact_client_id > 0"
 *   trainer       null / false ("false when P2 is absent")
 *   odooLinkState null (unknown)
 *   reconcile     no-op
 *   scope         FAIL-CLOSED: admin or module_training 3 => every department; otherwise only the
 *                 user's user_client_permissions departments; no rows => nobody (404)
 * A bridge that throws fails closed as well (not eligible / not in scope / not a trainer) and
 * is logged by class only.
 */
final class Seam
{
    public const RECORDS = '\\ITFlow\\Training\\Kiosk\\Bridge\\RecordsBridge';
    public const TRAINERS = '\\ITFlow\\Training\\Kiosk\\Bridge\\TrainerBridge';

    /** @var array<int, ?array> */
    private static array $trainerMemo = [];

    /**
     * Eligible-contact filter for a query over contacts aliased $alias:
     * ['join' => string, 'where' => string, 'types' => string, 'params' => list].
     */
    public static function eligibleSql(Ctx $c, string $alias): array
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,19}$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Seam::eligibleSql: bad alias');
        }
        $fallback = ['join' => '', 'where' => "$alias.contact_archived_at IS NULL AND $alias.contact_client_id > 0", 'types' => '', 'params' => []];
        $cls = self::RECORDS;
        if (!class_exists($cls)) {
            return $fallback;
        }
        try {
            $r = (new $cls($c))->eligibleSql($alias);
            if (is_array($r) && isset($r['where']) && is_string($r['where'])) {
                return ['join' => (string) ($r['join'] ?? ''), 'where' => $r['where'], 'types' => (string) ($r['types'] ?? ''), 'params' => array_values((array) ($r['params'] ?? []))];
            }
        } catch (\Throwable $e) {
            error_log('Kiosk seam eligibleSql: ' . get_class($e));
        }
        return $fallback;
    }

    public static function isEligible(\mysqli $db, int $contactId, ?Ctx $c = null): bool
    {
        return Eligibility::isEligible($db, $contactId, $c);
    }

    /** K5 TrainerBridge::trainer(), or null (not a trainer / P2 or K5 absent). */
    public static function trainer(\mysqli $db, int $contactId): ?array
    {
        if ($contactId < 1) {
            return null;
        }
        if (array_key_exists($contactId, self::$trainerMemo)) {
            return self::$trainerMemo[$contactId];
        }
        $cls = self::TRAINERS;
        $t = null;
        if (class_exists($cls)) {
            try {
                $r = (new $cls($db))->trainer($contactId);
                $t = is_array($r) ? $r : null;
            } catch (\Throwable $e) {
                error_log('Kiosk seam trainer: ' . get_class($e));
            }
        }
        return self::$trainerMemo[$contactId] = $t;
    }

    /** Active trainer or evaluator (TrainerBridge::isActiveTrainer); false when the bridge is absent. */
    public static function isActiveTrainer(\mysqli $db, int $contactId): bool
    {
        if ($contactId < 1) {
            return false;
        }
        $cls = self::TRAINERS;
        if (!class_exists($cls)) {
            return false;
        }
        try {
            return (bool) (new $cls($db))->isActiveTrainer($contactId);
        } catch (\Throwable $e) {
            error_log('Kiosk seam isActiveTrainer: ' . get_class($e));
            return false;
        }
    }

    /** True when trainer sign-in can work at all (the trainer bridge is installed). */
    public static function trainersAvailable(): bool
    {
        return class_exists(self::TRAINERS);
    }

    /** P2's Odoo link state for the contact ('ok','unchecked','repointed','mismatch','missing') or null when unknown. */
    public static function odooLinkState(Ctx $c, int $contactId): ?string
    {
        $cls = self::RECORDS;
        if (!class_exists($cls)) {
            return null;
        }
        try {
            $s = (new $cls($c))->odooLinkState($contactId);
            return is_string($s) && $s !== '' ? $s : null;
        } catch (\Throwable $e) {
            error_log('Kiosk seam odooLinkState: ' . get_class($e));
            return null;
        }
    }

    /** RecordsBridge::reconcileContact() after a successful sign-in (outside any transaction); never throws. */
    public static function reconcileContact(Ctx $c, int $contactId): void
    {
        $cls = self::RECORDS;
        if (!class_exists($cls) || Db::depth() > 0) {
            return;
        }
        try {
            (new $cls($c))->reconcileContact($contactId);
        } catch (\Throwable $e) {
            error_log('Kiosk seam reconcile: ' . get_class($e));
        }
    }

    /**
     * Agent per-contact actions (§4.3): the contact when it is in $user's scope, else 404
     * not_found. Returns ['contact_id','contact_name','contact_client_id','contact_archived_at'].
     */
    public static function assertContactInScope(Ctx $user, int $contactId): array
    {
        $row = $contactId > 0 ? Db::one($user->db, 'SELECT contact_id, contact_name, contact_client_id, contact_archived_at FROM contacts WHERE contact_id = ?', 'i', [$contactId]) : null;
        if ($row === null) {
            throw ApiException::notFound('That person was not found.');
        }
        $cls = self::RECORDS;
        if (class_exists($cls)) {
            try {
                (new $cls($user))->assertContactInScope($user, $contactId);
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                error_log('Kiosk seam scope: ' . get_class($e));
                throw ApiException::notFound('That person was not found.');
            }
            return $row;
        }
        $ids = self::fallbackScope($user);
        if ($ids !== null && !in_array((int) $row['contact_client_id'], $ids, true)) {
            throw ApiException::notFound('That person was not found.');
        }
        return $row;
    }

    /** Department ids $user may see: null = all, [] = none (fail-closed). */
    public static function scopeClientIds(Ctx $user): ?array
    {
        $cls = self::RECORDS;
        if (class_exists($cls)) {
            try {
                $r = (new $cls($user))->scopeClientIds($user);
                return $r === null ? null : array_values(array_map('intval', (array) $r));
            } catch (\Throwable $e) {
                error_log('Kiosk seam scopeClientIds: ' . get_class($e));
                return [];
            }
        }
        return self::fallbackScope($user);
    }

    /** @return list<int>|null */
    private static function fallbackScope(Ctx $user): ?array
    {
        if ($user->isAdmin || $user->level >= 3) {
            return null;
        }
        if ($user->userId < 1) {
            return [];
        }
        $out = [];
        foreach (Db::all($user->db, 'SELECT client_id FROM user_client_permissions WHERE user_id = ? ORDER BY client_id', 'i', [$user->userId]) as $r) {
            if ((int) $r['client_id'] > 0) {
                $out[] = (int) $r['client_id'];
            }
        }
        return array_values(array_unique($out));
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$trainerMemo = [];
    }
}
