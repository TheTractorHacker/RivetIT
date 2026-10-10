<?php

declare(strict_types=1);

namespace ITFlow\Platform;

use ITFlow\Assets\StaleAssets;
use ITFlow\Audit\AuditChain;
use ITFlow\Audit\AuditSink;
use ITFlow\Links\EntityTypes;
use ITFlow\Recovery\RecoveryAlerts;

/**
 * Work the main cron does for the platform features, safe to call on every tick:
 *   every tick  - seal any audit rows that no writer sealed (AuditChain), copy them to the sink;
 *   once a day  - verify the audit hash chain (alert on a break), send document review reminders, auto-retire stale RMM assets (when
 *                 switched on), remove entity links whose record no longer exists.
 * Each task is separate: one failing never stops the others.
 */
final class Nightly
{
    /** @return array<string,mixed> what happened (for the cron log line) */
    public static function run(\mysqli $db, string $appRoot, bool $force = false): array
    {
        $out = [];
        try {
            if (PlatformSettings::bool($db, 'audit_chain_seal_enabled')) {
                $chain = new AuditChain($db);
                $locked = $chain->lock(5);
                try {
                    $sealed = $chain->seal(5000);
                } finally {
                    if ($locked) {
                        $chain->unlock();
                    }
                }
                AuditSink::write($db, $sealed, $appRoot);
                $out['sealed'] = count($sealed);
            }
        } catch (\Throwable $e) {
            $out['sealed'] = 'error: ' . $e->getMessage();
        }

        $today = gmdate('Y-m-d');
        if (!$force && PlatformSettings::get($db, 'nightly_last_run', '') === $today) {
            return $out;
        }
        PlatformSettings::set($db, 'nightly_last_run', $today);

        foreach (['audit_chain' => 'verifyAuditChain', 'document_reviews' => 'documentReviews', 'stale_assets' => 'staleAssets', 'orphan_links' => 'orphanLinks'] as $key => $method) {
            try {
                $out[$key] = self::$method($db);
            } catch (\Throwable $e) {
                $out[$key] = 'error: ' . $e->getMessage();
                if (function_exists('logApp')) {
                    logApp('Cron', 'error', "Platform task $key failed: " . $e->getMessage());
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function verifyAuditChain(\mysqli $db): array
    {
        $chain = new AuditChain($db);
        $res = $chain->verify();
        $chain->record($res);
        if ($res['status'] === 'broken') {
            RecoveryAlerts::raise($db, 'audit_chain.broken', 'Audit trail tampering suspected', $res['reason'] . ' Review Administration > Audit trail and restore from a backup if the change was not yours.',
                'audit.chain_broken', ['status' => 'broken', 'audit_id' => $res['broken_at']], '/admin/audit_trail.php');
        } elseif (in_array($res['status'], ['key_changed', 'key_unavailable'], true)) {
            RecoveryAlerts::raise($db, 'audit_chain.key', 'Audit trail chain cannot be verified', $res['reason'], 'audit.chain_broken', ['status' => $res['status']], '/admin/audit_trail.php');
        } else {
            RecoveryAlerts::clear($db, 'audit_chain.broken');
            RecoveryAlerts::clear($db, 'audit_chain.key');
        }

        return ['status' => $res['status'], 'checked' => $res['checked'], 'broken_at' => $res['broken_at']];
    }

    /**
     * A document whose review date has arrived: one in-app notification (and an event for webhooks / rules), once per date.
     * Changing the date re-arms it (the edit handler clears document_review_reminded_at).
     */
    public static function documentReviews(\mysqli $db): int
    {
        if (!PlatformSettings::bool($db, 'doc_review_reminders')) {
            return 0;
        }
        $n = 0;
        $res = $db->query("SELECT d.document_id, d.document_name, d.document_client_id, d.document_review_at, c.client_name
            FROM documents d LEFT JOIN clients c ON c.client_id = d.document_client_id
            WHERE d.document_archived_at IS NULL AND d.document_review_at IS NOT NULL AND d.document_review_at <= CURDATE()
              AND (d.document_review_reminded_at IS NULL OR d.document_review_reminded_at < d.document_review_at)
            ORDER BY d.document_review_at LIMIT 200");
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        foreach ($rows as $r) {
            $id = (int) $r['document_id'];
            $db->query("UPDATE documents SET document_review_reminded_at = CURDATE(), document_updated_at = document_updated_at WHERE document_id = $id");
            if ($db->affected_rows < 1) {
                continue;
            }
            $client = (int) $r['document_client_id'];
            $what = "Document \"{$r['document_name']}\"" . ($r['client_name'] ? " for {$r['client_name']}" : '') . " is due for review (review date {$r['document_review_at']})";
            if (function_exists('appNotify')) {
                appNotify('Document Review Due', $what, "/agent/document_details.php?client_id=$client&document_id=$id", $client, $id);
            }
            if (function_exists('rivetEmitEvent')) {
                try {
                    rivetEmitEvent('document.review_due', ['document_id' => $id, 'document_name' => $r['document_name'], 'client_id' => $client, 'review_at' => $r['document_review_at']]);
                } catch (\Throwable $e) {
                    // a reminder is not lost to a webhook problem
                }
            }
            $n++;
        }

        return $n;
    }

    /** @return array<string,mixed> */
    public static function staleAssets(\mysqli $db): array
    {
        return (new StaleAssets($db))->run();
    }

    /** Links whose record was deleted (archived records keep their links). */
    public static function orphanLinks(\mysqli $db): int
    {
        $removed = 0;
        foreach (EntityTypes::all() as $type => $spec) {
            foreach (['src', 'dst'] as $end) {
                $db->query("DELETE l FROM entity_links l LEFT JOIN {$spec['table']} t ON t.{$spec['pk']} = l.{$end}_id WHERE l.{$end}_type = '$type' AND t.{$spec['pk']} IS NULL");
                $removed += max(0, $db->affected_rows);
            }
        }

        return $removed;
    }
}
