<?php

namespace ITFlow\Training\Settings;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Kiosk\Core\KioskSettings;

/**
 * The shared validation and writes behind both Training settings pages (roles audit P2):
 *   admin/settings_training.php  -> admin/post.php -> admin/post/settings_training*.php (admins)
 *   agent/training_settings.php  -> AgentSettingsHandler (Training 3; Kiosk 3 for kiosk items)
 *
 * Moved here unchanged from the three admin handlers (2026-09-26): the same clamps, checks, SQL,
 * logAction lines, audit events and messages. Each method returns ['type' => ..., 'message' => ...]
 * for the caller to flash (flash_alert) before it redirects; nothing here redirects, exits or reads
 * $_POST - the caller passes the posted fields in. Messages that carry DB- or input-derived text
 * are already escaped (toastr renders flash text as HTML), exactly as the handlers did.
 *
 * Callers do the CSRF check and decide who may call what (SettingsPolicy). The $withAdminFields /
 * $withHireFill flags are the only difference between the two entry points: without them the
 * admin-only columns are neither read from the post nor written.
 */
final class SettingsService
{
    public function __construct(
        private readonly \mysqli $db,
        private readonly int $actorId,
        private readonly string $actorName,   // $session_name (already escaped by load_user_session.php)
        private readonly string $via = 'admin',   // error_log labels only
    ) {
    }

    private static function out(string $message, string $type = 'success'): array
    {
        return ['type' => $type, 'message' => $message];
    }

    /** The 2.6.92 compliance tables and columns are installed (the Compliance & assignments guard). */
    public function complianceSchemaReady(bool $trainingSchemaReady): bool
    {
        if (!$trainingSchemaReady || !class_exists(RecordsSettings::class)) {
            return false;
        }
        try {
            return RecordsSettings::fromDb($this->db)->schemaReady;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // --- General & media --------------------------------------------------------------------

    /**
     * edit_training_settings. Course defaults always; media limits, the budget and the YouTube
     * key only with $withAdminFields (otherwise those columns are left exactly as they are).
     */
    public function saveGeneral(array $post, bool $withAdminFields): array
    {
        $cols = 'config_training_languages, config_training_default_pass_pct, config_training_default_max_attempts, config_training_attestation_text';
        if ($withAdminFields) {
            $cols .= ', config_training_video_max_mb, config_training_pdf_max_mb, config_training_pdf_max_pages,
            config_training_image_max_mb, config_training_file_max_mb, config_training_media_budget_mb';
        }
        $old = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT $cols FROM settings WHERE company_id = 1")) ?: [];

        $clamp = fn($v, int $min, int $max): int => max($min, min($max, intval($v)));
        $cap = TrainingSettings::UPLOAD_CAP_MB;

        // English is always offered; the other known languages come from the checkboxes.
        $langs = ['en'];
        foreach (array_keys(TrainingSettings::KNOWN_LANGUAGES) as $code) {
            if ($code !== 'en' && in_array($code, (array) ($post['training_languages'] ?? []), true)) {
                $langs[] = $code;
            }
        }

        $attestation = trim((string) ($post['config_training_attestation_text'] ?? ''));
        if (!mb_check_encoding($attestation, 'UTF-8')) {
            return self::out('The attestation text contains characters that could not be read. Retype it and save again.', 'error');
        }
        $attestation = $attestation === '' ? null : mb_substr($attestation, 0, 5000);

        $new = [
            'config_training_languages'            => implode(',', $langs),
            'config_training_default_pass_pct'     => $clamp($post['config_training_default_pass_pct'] ?? 80, 50, 100),
            'config_training_default_max_attempts' => $clamp($post['config_training_default_max_attempts'] ?? 3, 0, 10),
            'config_training_attestation_text'     => $attestation,
        ];
        if ($withAdminFields) {
            $new += [
                'config_training_video_max_mb'         => $clamp($post['config_training_video_max_mb'] ?? 95, 1, $cap),
                'config_training_pdf_max_mb'           => $clamp($post['config_training_pdf_max_mb'] ?? 50, 1, $cap),
                'config_training_pdf_max_pages'        => $clamp($post['config_training_pdf_max_pages'] ?? 150, 1, 1000),
                'config_training_image_max_mb'         => $clamp($post['config_training_image_max_mb'] ?? 15, 1, $cap),
                'config_training_file_max_mb'          => $clamp($post['config_training_file_max_mb'] ?? 50, 1, $cap),
                'config_training_media_budget_mb'      => $clamp($post['config_training_media_budget_mb'] ?? 1024, 100, 1048576),
            ];
        }

        // YouTube Data API key: blank keeps the saved one, "Remove" clears it, anything else replaces it.
        $keyChange = 'unchanged';
        $keySql = '';
        $keyValue = null;
        if ($withAdminFields) {
            $keyInput = trim((string) ($post['config_training_youtube_api_key'] ?? ''));
            if (!empty($post['training_youtube_key_clear'])) {
                $keyChange = 'removed';
                $keySql = ', config_training_youtube_api_key = NULL';
            } elseif ($keyInput !== '') {
                if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $keyInput)) {
                    return self::out('That does not look like a YouTube Data API key (letters, digits, - and _ only). Nothing was saved.', 'error');
                }
                try {
                    $keyValue = \encryptSetting($keyInput);
                } catch (\RuntimeException $e) {
                    return self::out('The API key could not be stored securely: the settings encryption key is missing from config.php. Nothing was saved.', 'error');
                }
                $keyChange = 'replaced';
                $keySql = ', config_training_youtube_api_key = ?';
            }
        }

        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($new)));
        $types = 'siis' . ($withAdminFields ? 'iiiiii' : '') . ($keyValue !== null ? 's' : '');
        $params = array_values($new);
        if ($keyValue !== null) {
            $params[] = $keyValue;
        }
        Db::exec($this->db, "UPDATE settings SET $set$keySql WHERE company_id = 1", $types, $params);

        $changed = [];
        foreach ($new as $col => $val) {
            $before = $old[$col] ?? null;
            if ((string) $before !== (string) $val) {
                // Never put the attestation text itself in the audit trail; just that it changed.
                $changed[$col] = $col === 'config_training_attestation_text' ? 'changed' : ['from' => $before, 'to' => $val];
            }
        }
        if ($keyChange !== 'unchanged') {
            $changed['config_training_youtube_api_key'] = $keyChange;   // never the value
        }

        \logAction("Training", "Edit", "{$this->actorName} edited Training settings");
        if ($changed) {
            \ITFlow\Audit\AuditService::record('training.settings_changed', $this->actorId, 'settings', 1, 'update',
                "{$this->actorName} changed Training settings", ['changed' => $changed]);
        }

        return self::out("Training settings saved");
    }

    // --- Records ledger -----------------------------------------------------------------------

    /** training_ledger_verify: shallow verification (60 s budget); a NEW break alerts every admin once. */
    public function verifyLedger(): array
    {
        @set_time_limit(90);
        try {
            $result = \ITFlow\Training\Core\LedgerVerifier::verify($this->db, ['time_budget_s' => 60]);
            $record = \ITFlow\Training\Core\LedgerVerifier::recordResult($this->db, $result);
        } catch (\Throwable $e) {
            error_log("Training ledger verify ({$this->via}): " . get_class($e) . ': ' . $e->getMessage());
            return self::out('The ledger check could not run. The details were written to the server error log.', 'error');
        }

        // A NEW break signature alerts once, from whichever path saw it first - the same block as
        // cron/training_cron.php. recordResult() just stored this line, so the nightly run will not
        // see it as new; if this path did not notify, no other admin would ever hear of it.
        if ($record['new_break']) {
            $first = $result['breaks'][0];
            \logApp('Training', 'error', 'Training ledger verification found a break: ' . $record['line'] . ' - ' . $first['detail']);
            $admins = mysqli_query($this->db, "SELECT users.user_id FROM users
                JOIN user_roles ON users.user_role_id = user_roles.role_id
                WHERE user_roles.role_is_admin = 1 AND users.user_type = 1 AND users.user_status = 1 AND users.user_archived_at IS NULL");
            while ($admin = mysqli_fetch_assoc($admins)) {
                \notifyUser(intval($admin['user_id']), 'Training', 'Training records integrity check found a problem: ' . $record['line'] . '. Open Admin > Training for details.', '/admin/settings_training.php#ledger');
            }
            try {
                \ITFlow\Audit\AuditService::record('training.ledger_break', $this->actorId, 'training_ledger', $first['seq'], 'verify', $record['line'], [
                    'breaks' => array_slice($result['breaks'], 0, 20),
                    'head' => $result['head'],
                    'deep' => false,
                ]);
            } catch (\Throwable $e) {
                \logApp('Training', 'error', 'Could not write the ledger-break audit event: ' . $e->getMessage());
            }
        }

        \logAction("Training", "Verify", "{$this->actorName} verified the training ledger: " . $record['line']);

        if ($result['ok']) {
            return self::out('Ledger verified: ' . \nullable_htmlentities($record['line']));
        }
        if (!empty($result['breaks'])) {
            $first = $result['breaks'][0];
            return self::out('Ledger check found a problem: ' . \nullable_htmlentities($record['line'] . ' - ' . $first['detail']), 'error');
        }
        return self::out('Ledger check stopped at the 60-second limit before finishing: ' . \nullable_htmlentities($record['line']), 'warning');
    }

    // --- Compliance & assignments -------------------------------------------------------------

    /**
     * edit_training_compliance_settings, section "defaults": the four day/percent fields and the
     * evidence cap always; the Odoo hire-date fill only with $withHireFill (otherwise untouched).
     */
    public function saveComplianceDefaults(array $post, bool $withHireFill): array
    {
        $old = Db::one($this->db, 'SELECT config_training_due_soon_days, config_training_reissue_days, config_training_reopen_window_days,
                config_training_evidence_max_mb, config_training_compliance_target_pct, config_training_hire_fill_since, config_training_odoo_sync_enabled
            FROM settings WHERE company_id = 1') ?? [];

        $int = static function (string $k, int $min, int $max) use ($post): ?int {
            $v = trim((string) ($post[$k] ?? ''));
            if (preg_match('/^\d{1,6}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                return null;
            }
            return (int) $v;
        };
        $new = [
            'config_training_due_soon_days' => $int('config_training_due_soon_days', 0, 365),
            'config_training_reissue_days' => $int('config_training_reissue_days', 1, 365),
            'config_training_reopen_window_days' => $int('config_training_reopen_window_days', 0, 365),
            'config_training_evidence_max_mb' => $int('config_training_evidence_max_mb', 1, 95),
            'config_training_compliance_target_pct' => $int('config_training_compliance_target_pct', 1, 100),
        ];
        $labels = [
            'config_training_due_soon_days' => '"Due soon" window (0 to 365 days)',
            'config_training_reissue_days' => 'Redo after a voided record (1 to 365 days)',
            'config_training_reopen_window_days' => 'Reopen window (0 to 365 days)',
            'config_training_evidence_max_mb' => 'Evidence scan upload (1 to 95 MB)',
            'config_training_compliance_target_pct' => 'Compliance target (1 to 100%)',
        ];
        $bad = array_keys(array_filter($new, static fn($v) => $v === null));
        if ($bad) {
            return self::out('Nothing was saved. Check: ' . \nullable_htmlentities(implode(', ', array_map(static fn($k) => $labels[$k], $bad))) . '.', 'error');
        }
        if ($withHireFill) {
            $hire = trim((string) ($post['config_training_hire_fill_since'] ?? ''));
            if ($hire !== '') {
                $max = Clock::addDays(Clock::todayLocal(), 366);
                if (!Clock::isYmd($hire) || $hire < '2000-01-01' || $hire > $max) {
                    return self::out('Nothing was saved. The hire-date fill date must be a real date (or empty to leave hire dates alone).', 'error');
                }
            }
            $new['config_training_hire_fill_since'] = $hire === '' ? null : $hire;
            Db::exec($this->db, 'UPDATE settings SET config_training_due_soon_days = ?, config_training_reissue_days = ?, config_training_reopen_window_days = ?,
                    config_training_evidence_max_mb = ?, config_training_compliance_target_pct = ?, config_training_hire_fill_since = ?
                WHERE company_id = 1', 'iiiiis', array_values($new));
        } else {
            Db::exec($this->db, 'UPDATE settings SET config_training_due_soon_days = ?, config_training_reissue_days = ?, config_training_reopen_window_days = ?,
                    config_training_evidence_max_mb = ?, config_training_compliance_target_pct = ?
                WHERE company_id = 1', 'iiiii', array_values($new));
        }

        $changed = $this->recordComplianceChange($old, $new);

        if (array_key_exists('config_training_hire_fill_since', $changed) && $new['config_training_hire_fill_since'] !== null) {
            return self::out(\nullable_htmlentities('Training compliance settings saved. Empty hire dates of Odoo employees created on or after '
                . $new['config_training_hire_fill_since'] . ' are filled on the next directory sync.'));
        }
        return self::out('Training compliance settings saved.');
    }

    /** edit_training_compliance_settings, section "odoo_sync" (admin only): the nightly Odoo directory sync switch. */
    public function saveOdooSync(array $post): array
    {
        $old = Db::one($this->db, 'SELECT config_training_due_soon_days, config_training_reissue_days, config_training_reopen_window_days,
                config_training_evidence_max_mb, config_training_compliance_target_pct, config_training_hire_fill_since, config_training_odoo_sync_enabled
            FROM settings WHERE company_id = 1') ?? [];
        $new = ['config_training_odoo_sync_enabled' => !empty($post['config_training_odoo_sync_enabled']) ? 1 : 0];
        Db::exec($this->db, 'UPDATE settings SET config_training_odoo_sync_enabled = ? WHERE company_id = 1', 'i', [$new['config_training_odoo_sync_enabled']]);

        $this->recordComplianceChange($old, $new);

        return self::out($new['config_training_odoo_sync_enabled'] ? 'Nightly Odoo directory sync turned on.' : 'Nightly Odoo directory sync turned off.');
    }

    /** logAction + the training.settings_changed audit event for a compliance save; returns the changed map. */
    private function recordComplianceChange(array $old, array $new): array
    {
        $changed = [];
        foreach ($new as $col => $val) {
            $before = $old[$col] ?? null;
            if ((string) $before !== (string) $val) {
                $changed[$col] = ['from' => $before, 'to' => $val];
            }
        }

        \logAction('Training', 'Edit', "{$this->actorName} edited Training compliance settings");
        if ($changed) {
            try {
                \ITFlow\Audit\AuditService::record('training.settings_changed', $this->actorId, 'settings', 1, 'update',
                    "{$this->actorName} changed Training compliance settings", ['changed' => $changed]);
            } catch (\Throwable $e) {
                error_log('Training compliance: audit failed: ' . $e->getMessage());
            }
        }
        return $changed;
    }

    /** training_reconcile_now: a full assignment recalculation. */
    public function reconcileNow(): array
    {
        @set_time_limit(95);
        try {
            $rec = (new \ITFlow\Training\Assign\AssignmentService(\ITFlow\Training\Core\Access::ctx($this->db)))->reconcile(null, 'reconcile_now');
        } catch (\Throwable $e) {
            error_log("Training reconcile ({$this->via}): " . get_class($e) . ': ' . $e->getMessage());
            return self::out('Recalculating assignments failed. The details were written to the server error log.', 'error');
        }
        if (!empty($rec['skipped_busy'])) {
            return self::out('Another recalculation is running. Try again in a minute.', 'warning');
        }
        $line = "{$rec['created']} created, {$rec['reopened']} reopened, {$rec['completed']} completed, {$rec['cancelled']} cancelled"
            . " for {$rec['contacts']} people in {$rec['ms']} ms" . ($rec['failed_chunks'] ? "; {$rec['failed_chunks']} batch(es) failed" : '');
        \logAction('Training', 'Edit', "{$this->actorName} recalculated training assignments ($line)");
        return self::out(\nullable_htmlentities('Assignments recalculated: ' . $line . '.'), $rec['failed_chunks'] ? 'warning' : 'success');
    }

    /** training_snapshot_now: today's compliance snapshot (replaces today's numbers). */
    public function snapshotNow(): array
    {
        @set_time_limit(95);
        try {
            $snap = \ITFlow\Training\Reports\SnapshotService::capture($this->db, Clock::todayLocal());
        } catch (\Throwable $e) {
            error_log("Training snapshot ({$this->via}): " . get_class($e) . ': ' . $e->getMessage());
            return self::out('The snapshot could not be captured. The details were written to the server error log.', 'error');
        }
        if (!empty($snap['skipped'])) {
            return self::out('A snapshot is being captured right now. Try again in a minute.', 'warning');
        }
        \logAction('Training', 'Edit', "{$this->actorName} captured the training compliance snapshot for {$snap['date']} ({$snap['rows']} rows)");
        return self::out(\nullable_htmlentities("Snapshot for {$snap['date']} captured ({$snap['rows']} rows)."));
    }

    // --- Kiosk & sign-in -----------------------------------------------------------------------

    /**
     * edit_training_kiosk_settings. Every threshold clamped by KioskSettings::clamp (the same
     * ranges the reader applies) -> one UPDATE -> logAction + audit 'training.kiosk_settings_changed'
     * (old/new numbers only). With $withAdminFields: every threshold plus the Odoo-PIN switch.
     * Without: only the session timeouts and setup-slip validity (SettingsPolicy::agentKioskColumns());
     * PIN lockouts, device/system caps and the switch are left exactly as they are.
     * Runtime state columns (pauses, breaker, sync time) are never written here.
     */
    public function saveKiosk(array $post, bool $withAdminFields): array
    {
        if ($withAdminFields) {
            $cols = array_keys(KioskSettings::RANGES);
            $cols = array_values(array_filter($cols, static fn($c) => $c !== 'config_training_odoo_breaker_errors'));   // runtime state
        } else {
            $cols = SettingsPolicy::agentKioskColumns();
        }
        try {
            $old = mysqli_fetch_assoc(mysqli_query($this->db, 'SELECT ' . implode(', ', $cols) . ', ' . KioskSettings::SWITCH
                . ' FROM settings WHERE company_id = 1')) ?: [];
        } catch (\mysqli_sql_exception $e) {
            return self::out('Run the database update first: the Training kiosk settings are not installed yet.', 'error');
        }

        $new = [];
        foreach ($cols as $col) {
            $new[$col] = KioskSettings::clamp($col, $post[$col] ?? null);
        }
        if (isset($new['config_training_pin_hard_failures'], $new['config_training_pin_soft_failures'])
            && $new['config_training_pin_hard_failures'] <= $new['config_training_pin_soft_failures']) {
            return self::out('The hard-lock count must be more than the soft-lock count. Nothing was saved.', 'error');
        }
        if ($withAdminFields) {
            $new[KioskSettings::SWITCH] = (($post[KioskSettings::SWITCH] ?? '') === '1') ? 1 : 0;
        }

        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($new)));
        Db::exec($this->db, "UPDATE settings SET $set WHERE company_id = 1", str_repeat('i', count($new)), array_values($new));

        $changed = [];
        foreach ($new as $col => $val) {
            if ((string) ($old[$col] ?? '') !== (string) $val) {
                $changed[$col] = ['from' => isset($old[$col]) ? (int) $old[$col] : null, 'to' => $val];
            }
        }

        \logAction("Training", "Edit", "{$this->actorName} edited Training kiosk settings");
        if ($changed) {
            \ITFlow\Audit\AuditService::record('training.kiosk_settings_changed', $this->actorId, 'settings', 1, 'update',
                "{$this->actorName} changed Training kiosk settings", ['changed' => $changed]);
        }

        if (isset($changed[KioskSettings::SWITCH])) {
            return self::out($new[KioskSettings::SWITCH] === 1
                ? 'Training kiosk settings saved. Odoo PIN sign-in is ON: people with a usable Odoo PIN now sign in with it.'
                : 'Training kiosk settings saved. Odoo PIN sign-in is off: everyone uses a training PIN.');
        }
        return self::out('Training kiosk settings saved');
    }
}
