<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Bridge\TrainerBridge;
use ITFlow\Training\Kiosk\Core\Eligibility;

/**
 * Lane K2's only door to its sibling lanes' frozen interfaces (P3 spec §9.2): K3's
 * Kiosk\Bridge\RecordsBridge (eligibility SQL, reconcile, P2 link state, per-target scope) and
 * K5's Kiosk\Bridge\TrainerBridge (is this contact an active trainer?). K2 never names a P2
 * table or class (§0.7).
 *
 * A bridge that throws fails closed (not eligible / not in scope / not a trainer) and is logged
 * by class only.
 */
final class Seam
{
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
        try {
            $r = (new RecordsBridge($c))->eligibleSql($alias);
            return ['join' => $r['join'], 'where' => $r['where'], 'types' => $r['types'], 'params' => array_values($r['params'])];
        } catch (\Throwable $e) {
            error_log('Kiosk seam eligibleSql: ' . get_class($e));
            // Fail closed: nobody matches.
            return ['join' => '', 'where' => '0 = 1', 'types' => '', 'params' => []];
        }
    }

    public static function isEligible(\mysqli $db, int $contactId, ?Ctx $c = null): bool
    {
        return Eligibility::isEligible($db, $contactId, $c);
    }

    /** TrainerBridge::trainer(), or null (not a trainer). */
    public static function trainer(\mysqli $db, int $contactId): ?array
    {
        if ($contactId < 1) {
            return null;
        }
        if (array_key_exists($contactId, self::$trainerMemo)) {
            return self::$trainerMemo[$contactId];
        }
        $t = null;
        try {
            $t = (new TrainerBridge($db))->trainer($contactId);
        } catch (\Throwable $e) {
            error_log('Kiosk seam trainer: ' . get_class($e));
        }
        return self::$trainerMemo[$contactId] = $t;
    }

    /** Active trainer or evaluator (TrainerBridge::isActiveTrainer). */
    public static function isActiveTrainer(\mysqli $db, int $contactId): bool
    {
        if ($contactId < 1) {
            return false;
        }
        try {
            return (new TrainerBridge($db))->isActiveTrainer($contactId);
        } catch (\Throwable $e) {
            error_log('Kiosk seam isActiveTrainer: ' . get_class($e));
            return false;
        }
    }

    /** True when trainer sign-in can work at all (at least one active trainer or evaluator exists). */
    public static function trainersAvailable(\mysqli $db): bool
    {
        try {
            return (new TrainerBridge($db))->anyActive();
        } catch (\Throwable $e) {
            error_log('Kiosk seam trainersAvailable: ' . get_class($e));
            return false;
        }
    }

    /** P2's Odoo link state for the contact ('ok','unchecked','repointed','mismatch','missing') or null when unknown. */
    public static function odooLinkState(Ctx $c, int $contactId): ?string
    {
        try {
            $s = (new RecordsBridge($c))->odooLinkState($contactId);
            return is_string($s) && $s !== '' ? $s : null;
        } catch (\Throwable $e) {
            error_log('Kiosk seam odooLinkState: ' . get_class($e));
            return null;
        }
    }

    /** RecordsBridge::reconcileContact() after a successful sign-in (outside any transaction); never throws. */
    public static function reconcileContact(Ctx $c, int $contactId): void
    {
        if (Db::depth() > 0) {
            return;
        }
        try {
            (new RecordsBridge($c))->reconcileContact($contactId);
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
        try {
            (new RecordsBridge($user))->assertContactInScope($user, $contactId);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('Kiosk seam scope: ' . get_class($e));
            throw ApiException::notFound('That person was not found.');
        }
        return $row;
    }

    /** Department ids $user may see: null = all, [] = none (fail-closed). */
    public static function scopeClientIds(Ctx $user): ?array
    {
        try {
            $r = (new RecordsBridge($user))->scopeClientIds($user);
            return $r === null ? null : array_values(array_map('intval', $r));
        } catch (\Throwable $e) {
            error_log('Kiosk seam scopeClientIds: ' . get_class($e));
            return [];
        }
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$trainerMemo = [];
    }
}
