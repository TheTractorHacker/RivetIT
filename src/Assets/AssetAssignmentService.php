<?php

namespace ITFlow\Assets;

/**
 * Asset assignment history (master plan Section 11.5) - "record every
 * transition, don't just overwrite the current user." assets.asset_contact_id
 * stays the current-assignment pointer every existing page already reads;
 * this is the append-only history layered on top, matching Section 3.5
 * ("history is more valuable than overwrite").
 */
class AssetAssignmentService
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Call this whenever an asset's assigned contact is being changed.
     * No-ops if $newContactId is the same as the currently-open assignment
     * (so routine edits that don't touch assignment don't spam history).
     */
    public function recordChangeIfNeeded(int $assetId, ?int $newContactId, ?int $changedByUserId): void
    {
        $newContactId = $newContactId > 0 ? $newContactId : null;

        $current = $this->currentAssignment($assetId);
        $currentContactId = $current['contact_id'] ?? null;

        if ($currentContactId === $newContactId) {
            return; // unchanged
        }

        if ($current !== null) {
            $stmt = $this->mysqli->prepare("UPDATE asset_assignments SET returned_at = NOW(), returned_by = ? WHERE assignment_id = ?");
            $stmt->bind_param('ii', $changedByUserId, $current['assignment_id']);
            $stmt->execute();
            $stmt->close();
        }

        if ($newContactId !== null) {
            $stmt = $this->mysqli->prepare("INSERT INTO asset_assignments (asset_id, contact_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->bind_param('iii', $assetId, $newContactId, $changedByUserId);
            $stmt->execute();
            $stmt->close();
            $this->emitAssigned($assetId, $newContactId, $currentContactId, $changedByUserId);
        }
    }

    /**
     * asset.assigned for event rules and webhooks: an asset was handed to a person (first assignment or a change of person). Never throws: a rule or
     * webhook problem must not break the assignment that caused it. The payload carries ids and names only; the automation engine adds department,
     * location and asset type for conditions (PayloadEnricher).
     */
    private function emitAssigned(int $assetId, int $contactId, ?int $previousContactId, ?int $byUserId): void
    {
        try {
            if (!function_exists('rivetEmitEvent')) {
                require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
            }
            $stmt = $this->mysqli->prepare('SELECT asset_name, asset_type, asset_client_id FROM assets WHERE asset_id = ?');
            $stmt->bind_param('i', $assetId);
            $stmt->execute();
            $a = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$a) {
                return;
            }
            $previous = $GLOBALS['mysqli'] ?? null;
            $GLOBALS['mysqli'] = $this->mysqli; // the bus works on the global connection
            try {
                \rivetEmitEvent('asset.assigned', ['asset_id' => $assetId, 'asset_name' => (string) $a['asset_name'], 'asset_type' => (string) $a['asset_type'], 'client_id' => (int) $a['asset_client_id'],
                    'contact_id' => $contactId, 'previous_contact_id' => (int) $previousContactId, 'assigned_by_user_id' => (int) $byUserId]);
            } finally {
                if ($previous === null) {
                    unset($GLOBALS['mysqli']);
                } else {
                    $GLOBALS['mysqli'] = $previous;
                }
            }
        } catch (\Throwable $e) {
            error_log('asset.assigned not emitted: ' . $e->getMessage());
        }
    }

    private function currentAssignment(int $assetId): ?array
    {
        $stmt = $this->mysqli->prepare("SELECT assignment_id, contact_id FROM asset_assignments WHERE asset_id = ? AND returned_at IS NULL ORDER BY assignment_id DESC LIMIT 1");
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array Rows ordered most-recent-first, each with contact_name joined in.
     */
    public function history(int $assetId): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT aa.*, c.contact_name
             FROM asset_assignments aa
             LEFT JOIN contacts c ON c.contact_id = aa.contact_id
             WHERE aa.asset_id = ?
             ORDER BY aa.assigned_at DESC"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
