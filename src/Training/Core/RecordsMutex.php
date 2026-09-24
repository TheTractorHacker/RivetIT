<?php

namespace ITFlow\Training\Core;

/**
 * The records mutex (Phase 2 spec §0 #4, §1.4 #11): row certctr_year = 0 of
 * training_cert_counters, locked FOR UPDATE as the FIRST lock of every completion writer
 * (CompletionService::issue), void, evaluation record and reconcile chunk.
 *
 * Serializing those writers on one row removes the gap-lock deadlock between concurrent new
 * completion source keys, removes the finalize-versus-kiosk assignment/counter cycle, and gives
 * reconcile fresh facts inside each chunk.
 *
 * The row is created only by the 2.6.92 migration and db.sql. acquire() never INSERTs it, for
 * the same reason Ledger::append never inserts the ledger head: two INSERT IGNOREs on an
 * existing key both take S locks and both upgrading to X is a guaranteed deadlock. A missing
 * row is \RuntimeException('records_uninitialized').
 */
final class RecordsMutex
{
    public const YEAR = 0;

    public static function acquire(\mysqli $db): void
    {
        if (Db::depth() < 1) {
            throw new \LogicException('RecordsMutex::acquire must run inside Db::tx');
        }
        $res = $db->query('SELECT certctr_last_seq FROM training_cert_counters WHERE certctr_year = ' . self::YEAR . ' FOR UPDATE');
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            throw new \RuntimeException('records_uninitialized');
        }
    }
}
