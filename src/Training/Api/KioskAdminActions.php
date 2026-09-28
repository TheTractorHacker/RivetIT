<?php

namespace ITFlow\Training\Api;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAlerts;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskConfigException;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Device\DeviceEnrollment;
use ITFlow\Training\Kiosk\Device\DeviceLifecycle;
use ITFlow\Training\Kiosk\Pin\PinAdmin;
use ITFlow\Training\Kiosk\Pin\Seam;

/**
 * Agent actions for training devices and PINs (P3 spec §4.3 kiosk_/pin_ rows, lane K2), behind
 * agent/training_ajax.php and the P1 Router (module_training >= 1). Each handler then checks
 * module_training_kiosk (Access::apiKiosk) at the level the spec states.
 *
 * Per-contact actions check the target is in the user's scope first (fail-closed 404, via
 * K3's RecordsBridge through Pin\Seam). A target who is an active trainer also needs
 * module_training level 3 or admin (403) and raises the 'trainer_slip' alert (v0 §12).
 * Every mutation is audited (AuditService) and logged (logAction) after commit; neither ever
 * carries a code, a PIN or a token.
 *
 * Devices (2.6.94): enrollment may be UNLISTED (`unlisted: true`, no asset_id - the label is the
 * device's only name) and/or TEMPORARY (`expires`: keep | today | 4h | 8h | 24h | until, with
 * `expires_until` 'YYYY-MM-DDTHH:MM' local for 'until'; DeviceLifecycle::expiryFor). kiosk_set_expiry
 * gives any active device a new end time with the same presets (a permanent one becomes temporary),
 * or ends a temporary one now (`expires: 'now'`; one whose time is already up is removed as expired).
 */
final class KioskAdminActions
{
    public const REASON_MIN = 5;
    public const REASON_MAX = 255;

    // ------------------------------------------------------------------ devices

    /** GET kiosk_list (kiosk >= 1). */
    public static function kioskList(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(1);
        $db = $c->db;
        $rows = Db::all($db, "SELECT k.kiosk_id, k.kiosk_asset_id, k.kiosk_asset_type, k.kiosk_asset_serial, k.kiosk_personal_contact_id, k.kiosk_force_shared, k.kiosk_label,
                k.kiosk_default_client_id, k.kiosk_status, k.kiosk_enroll_method, k.kiosk_token_hash, k.kiosk_enroll_expires_at_utc, k.kiosk_enrolled_at_utc,
                k.kiosk_enrolled_by, k.kiosk_last_seen_at_utc, k.kiosk_last_user_agent, k.kiosk_cooldown_until_utc, k.kiosk_cooldown_reason,
                k.kiosk_revoked_at_utc, k.kiosk_revoke_reason, k.kiosk_expires_at_utc,
                a.asset_id AS asset_row_id, a.asset_name, a.asset_type, a.asset_serial, a.asset_archived_at, a.asset_contact_id, a.asset_client_id,
                pc.contact_name AS personal_name, u.user_name AS enrolled_by_name, cl.client_name AS default_client_name
            FROM training_kiosks k
            LEFT JOIN assets a ON a.asset_id = k.kiosk_asset_id
            LEFT JOIN contacts pc ON pc.contact_id = k.kiosk_personal_contact_id
            LEFT JOIN users u ON u.user_id = k.kiosk_enrolled_by
            LEFT JOIN clients cl ON cl.client_id = k.kiosk_default_client_id
            WHERE k.kiosk_hidden_at_utc IS NULL AND (k.kiosk_status IN ('active','pending') OR k.kiosk_revoked_at_utc >= ?)
            ORDER BY FIELD(k.kiosk_status, 'active', 'pending', 'revoked'), k.kiosk_label, k.kiosk_id", 's', [KTime::plus(-30 * 86400)]);
        $open = [];
        foreach (Db::all($db, 'SELECT ksess_kiosk_id, ksess_role FROM training_kiosk_sessions WHERE ksess_open_guard = 1') as $s) {
            $open[(int) $s['ksess_kiosk_id']] = (string) $s['ksess_role'];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['kiosk_id'];
            $why = $r['kiosk_status'] === 'active' ? KioskAuth::invalidReason($db, $r) : null;
            $pending = $r['kiosk_status'] === 'pending';
            $unlisted = $r['kiosk_asset_id'] === null;
            $out[] = [
                'id' => $id,
                'label' => (string) $r['kiosk_label'],
                'status' => (string) $r['kiosk_status'],
                'problem' => $why,
                'unlisted' => $unlisted,
                'asset' => $unlisted ? null : [
                    'id' => (int) $r['kiosk_asset_id'],
                    'name' => (string) ($r['asset_name'] ?? ''),
                    'serial' => (string) ($r['asset_serial'] ?? $r['kiosk_asset_serial'] ?? ''),
                    'type' => (string) ($r['asset_type'] ?? $r['kiosk_asset_type']),
                    'archived' => $r['asset_archived_at'] !== null || $r['asset_row_id'] === null,
                ],
                'temporary' => $r['kiosk_expires_at_utc'] !== null,
                'expires_at' => self::iso($r['kiosk_expires_at_utc']),
                'expired' => DeviceLifecycle::isExpired($r['kiosk_expires_at_utc']),
                'personal' => $r['kiosk_personal_contact_id'] !== null ? ['id' => (int) $r['kiosk_personal_contact_id'], 'name' => (string) ($r['personal_name'] ?? '')] : null,
                'forced_shared' => (int) ($r['kiosk_force_shared'] ?? 0) === 1,
                'assignment_ok' => $why !== 'assignment_changed' && $why !== 'owner_ineligible',
                'default_department' => (string) ($r['default_client_name'] ?? ''),
                'method' => (string) ($r['kiosk_enroll_method'] ?? ''),
                'code_expires_at' => $pending ? self::iso($r['kiosk_enroll_expires_at_utc']) : null,
                'last_seen' => self::iso($r['kiosk_last_seen_at_utc']),
                'ua' => self::uaSummary((string) ($r['kiosk_last_user_agent'] ?? '')),
                'enrolled_at' => self::iso($r['kiosk_enrolled_at_utc']),
                'enrolled_by' => (string) ($r['enrolled_by_name'] ?? ''),
                'cooldown_until' => KTime::isFuture($r['kiosk_cooldown_until_utc']) ? self::iso($r['kiosk_cooldown_until_utc']) : null,
                'cooldown_reason' => KTime::isFuture($r['kiosk_cooldown_until_utc']) ? $r['kiosk_cooldown_reason'] : null,
                'active_session' => isset($open[$id]),
                'session_role' => $open[$id] ?? null,
                'revoked_at' => self::iso($r['kiosk_revoked_at_utc']),
                'revoke_reason' => $r['kiosk_revoke_reason'],
            ];
        }
        $ks = KioskSettings::fromDb($db);
        return [
            'kiosks' => $out,
            'pause_until' => KTime::isFuture($ks->pinPauseUntilUtc) ? self::iso($ks->pinPauseUntilUtc) : null,
            'enroll_pause_until' => KTime::isFuture($ks->enrollPauseUntilUtc) ? self::iso($ks->enrollPauseUntilUtc) : null,
            'odoo_pin_enabled' => $ks->odooPinEnabled,
            'kiosk_level' => Access::kioskLevel(),
        ];
    }

    /** GET kiosk_asset_options {q} (kiosk 3). */
    public static function kioskAssetOptions(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        if (!Access::canAssets()) {
            throw ApiException::forbidden(Access::ASSETS_NEEDED);
        }
        return ['assets' => (new DeviceEnrollment($c, self::keys()))->assetOptions((string) ($a->str('q', 80, false, true) ?? ''))];
    }

    /**
     * POST kiosk_enroll_here {asset_id | unlisted:true, label, default_client_id, replace?, expires?, expires_until?} (kiosk 3).
     * Response adds `unlisted` and `device_expires_at` (UTC ISO, null = kept until removed).
     */
    public static function kioskEnrollHere(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        [$assetId, $expiresAt] = self::enrollTarget($a);
        $r = (new DeviceEnrollment($c, self::keys()))->enrollHere($assetId, (string) $a->str('label', 100),
            (int) ($a->int('default_client_id', false, 0) ?? 0), $assetId !== null && (bool) $a->bool('replace', false), $expiresAt);
        return ['kiosk_id' => $r['kiosk_id'], 'label' => $r['label'], 'start_url' => $r['start_url'], 'open_url' => $r['open_url'], 'personal' => $r['personal'],
                'unlisted' => $r['unlisted'], 'device_expires_at' => self::iso($r['expires_at_utc'])];
    }

    /** POST kiosk_enroll_code {asset_id | unlisted:true, label, default_client_id, replace?, expires?, expires_until?} (kiosk 3) [S]. */
    public static function kioskEnrollCode(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        [$assetId, $expiresAt] = self::enrollTarget($a);
        $r = (new DeviceEnrollment($c, self::keys()))->issueCode($assetId, (string) $a->str('label', 100),
            (int) ($a->int('default_client_id', false, 0) ?? 0), $assetId !== null && (bool) $a->bool('replace', false), $expiresAt);
        return ['kiosk_id' => $r['kiosk_id'], 'label' => $r['label'], 'code' => $r['code'], 'expires_at' => $r['expires_at'], 'personal' => $r['personal'],
                'unlisted' => $r['unlisted'], 'device_expires_at' => self::iso($r['expires_at_utc'])];
    }

    /**
     * POST kiosk_enroll_codes {items:[{label, asset_id?}], default_client_id, replace?, expires?, expires_until?} (kiosk 3) [S].
     * Bulk sibling of kiosk_enroll_code (owner ask 2026-09-28, fleet rollout): one setup code per
     * item, one shared department/duration, printed together from the token in `print_url`.
     */
    public static function kioskEnrollCodes(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $items = [];
        $hasAsset = false;
        foreach ($a->arr('items') as $it) {
            if (!is_array($it)) {
                throw ApiException::validation(['items' => 'Bad device list.']);
            }
            $assetId = isset($it['asset_id']) && is_numeric($it['asset_id']) ? (int) $it['asset_id'] : null;
            if ($assetId !== null && $assetId > 0) {
                $hasAsset = true;
            } else {
                $assetId = null;
            }
            $items[] = ['label' => (string) ($it['label'] ?? ''), 'asset_id' => $assetId];
        }
        if ($hasAsset && !Access::canAssets()) {
            throw ApiException::forbidden(Access::ASSETS_NEEDED);   // enrolling by asset id reveals who has the asset
        }
        $preset = (string) ($a->enum('expires', DeviceLifecycle::PRESETS, false) ?? 'keep');
        $expiresAt = DeviceLifecycle::expiryFor($preset, $a->str('expires_until', 20, false));
        $token = (new DeviceEnrollment($c, self::keys()))->issueCodes($items, (int) ($a->int('default_client_id', false, 0) ?? 0),
            $expiresAt, (bool) $a->bool('replace', false));
        self::audit($c, 'training.kiosk_enroll_codes_issued', 'settings', 1, 'enroll_codes',
            'Issued ' . count($items) . ' training device setup code' . (count($items) === 1 ? '' : 's'), ['count' => count($items)]);
        return ['print_url' => '/agent/training_device_slips.php?t=' . rawurlencode($token), 'count' => count($items)];
    }

    /** POST kiosk_device_codes_clear {t} (kiosk 3). */
    public static function kioskDeviceCodesClear(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        DeviceEnrollment::clearCodes($c, (string) $a->str('t', 100));
        return [];
    }

    /**
     * POST kiosk_set_expiry {kiosk_id, expires: keep|today|4h|8h|24h|until|now, expires_until?} (kiosk 3).
     * 'now' ends a temporary device at once (a revoke with the reason "Temporary device ended early", or
     * "Temporary device expired" when its time is already up); the presets set a new end time counted
     * from now on any active device ('keep' = kept until removed).
     */
    public static function kioskSetExpiry(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $id = (int) $a->int('kiosk_id', true, 1);
        $preset = (string) $a->enum('expires', array_merge(DeviceLifecycle::PRESETS, ['now']));
        $dev = new DeviceEnrollment($c, self::keys());
        if ($preset === 'now') {
            $dev->endNow($id);
            return ['ended' => true, 'device_expires_at' => null];
        }
        $r = $dev->setExpiry($id, DeviceLifecycle::expiryFor($preset, $a->str('expires_until', 20, false)));
        return ['ended' => false, 'label' => $r['label'], 'device_expires_at' => self::iso($r['expires_at_utc'])];
    }

    /** POST kiosk_set_mode {kiosk_id, forced_shared} (kiosk 3) - asset-linked devices only. */
    public static function kioskSetMode(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $r = (new DeviceEnrollment($c, self::keys()))->setForcedShared((int) $a->int('kiosk_id', true, 1), (bool) $a->bool('forced_shared', true));
        return ['label' => $r['label'], 'forced_shared' => $r['forced_shared'], 'personal' => $r['personal']];
    }

    /** POST kiosk_revoke {kiosk_id, reason} (kiosk 3). */
    public static function kioskRevoke(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        (new DeviceEnrollment($c, self::keys()))->revoke((int) $a->int('kiosk_id', true, 1), self::reason($a));
        return [];
    }

    /** POST kiosk_hide {kiosk_ids} (kiosk 3) - bulk-remove already-revoked devices from the default list. Not a delete. */
    public static function kioskHide(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $ids = array_values(array_unique(array_filter(array_map('intval', $a->ints('kiosk_ids')), static fn($i) => $i > 0)));
        if ($ids === []) {
            throw ApiException::validation(['kiosk_ids' => 'Pick at least one device.']);
        }
        $n = (new DeviceEnrollment($c, self::keys()))->hide($ids);
        self::audit($c, 'training.kiosk_hidden', 'settings', 1, 'hide',
            'Removed ' . $n . ' revoked training device' . ($n === 1 ? '' : 's') . ' from the list', ['count' => $n]);
        return ['hidden' => $n];
    }

    /** POST kiosk_reissue {kiosk_id} (kiosk 3). */
    public static function kioskReissue(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $r = (new DeviceEnrollment($c, self::keys()))->reissue((int) $a->int('kiosk_id', true, 1));
        return ['start_url' => $r['start_url'], 'open_url' => $r['open_url'], 'label' => $r['label'], 'personal' => $r['personal'],
                'unlisted' => $r['unlisted'], 'device_expires_at' => self::iso($r['expires_at_utc'])];
    }

    /** POST kiosk_clear_cooldown {kiosk_id, reason} (kiosk >= 2). */
    public static function kioskClearCooldown(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $id = (int) $a->int('kiosk_id', true, 1);
        $reason = self::reason($a);
        if ((new PinAdmin($c, self::keys()))->clearCooldown($id, $reason)) {
            self::audit($c, 'training.kiosk_cooldown_cleared', 'training_kiosk', $id, 'clear_cooldown', 'Cleared the sign-in pause of a training device', ['reason' => $reason]);
        }
        return [];
    }

    /** POST pin_clear_pause {reason} (kiosk >= 2). */
    public static function pinClearPause(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $reason = self::reason($a);
        if ((new PinAdmin($c, self::keys()))->clearPause($reason)) {
            self::audit($c, 'training.kiosk_cooldown_cleared', 'settings', 1, 'clear_pause', 'Cleared the system-wide training sign-in pause', ['reason' => $reason]);
        }
        return [];
    }

    // ------------------------------------------------------------------ people and PINs

    /** GET pin_people {q?, client_id?, filter?} (kiosk >= 1), scoped. */
    public static function pinPeople(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(1);
        $db = $c->db;
        $q = trim((string) ($a->str('q', 80, false, true) ?? ''));
        $clientId = (int) ($a->int('client_id', false, 0) ?? 0);
        $filter = $a->enum('filter', ['all', 'locked', 'needs_slip', 'blocked'], false) ?? 'all';
        $scope = Seam::scopeClientIds($c);
        if ($scope === []) {
            return ['people' => [], 'truncated' => false];
        }
        $el = Seam::eligibleSql($c, 'c');
        $ks = KioskSettings::fromDb($db);
        $now = KTime::now();
        $sql = 'SELECT c.contact_id, c.contact_name, c.contact_client_id, cl.client_name,
                t.tcred_contact_id, t.tcred_source, t.tcred_source_pinned, t.tcred_pin_hash IS NOT NULL AS has_local_pin, t.tcred_failed_count,
                t.tcred_locked_until_utc, t.tcred_hard_locked, t.tcred_odoo_blocked, t.tcred_last_success_at_utc, t.tcred_setup_code_expires_at_utc,
                (t.tcred_setup_code_hash IS NOT NULL) AS has_code
            FROM contacts c ' . $el['join'] . '
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN training_learner_credentials t ON t.tcred_contact_id = c.contact_id
            WHERE ' . $el['where'];
        $types = $el['types'];
        $params = $el['params'];
        if ($scope !== null) {
            $sql .= ' AND c.contact_client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types .= str_repeat('i', count($scope));
            array_push($params, ...$scope);
        }
        if ($clientId > 0) {
            $sql .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $params[] = $clientId;
        }
        if ($q !== '') {
            $sql .= ' AND c.contact_name LIKE ?';
            $types .= 's';
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        if ($filter === 'locked') {
            $sql .= ' AND (t.tcred_hard_locked = 1 OR t.tcred_locked_until_utc > ?)';
            $types .= 's';
            $params[] = $now;
        } elseif ($filter === 'blocked') {
            $sql .= ' AND t.tcred_odoo_blocked = 1';
        } elseif ($filter === 'needs_slip') {
            $sql .= $ks->odooPinEnabled
                ? " AND (t.tcred_contact_id IS NULL OR (t.tcred_pin_hash IS NULL AND (t.tcred_source = 'local' OR t.tcred_source_pinned = 1)))"
                : ' AND (t.tcred_contact_id IS NULL OR t.tcred_pin_hash IS NULL)';
        }
        $sql .= ' ORDER BY c.contact_name, c.contact_id LIMIT 301';
        $rows = Db::all($db, $sql, $types, $params);
        $truncated = count($rows) > 300;
        $rows = array_slice($rows, 0, 300);
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['contact_id'];
            $trainer = Seam::isActiveTrainer($db, $cid);
            $stored = $r['tcred_source'] === null ? 'local' : (string) $r['tcred_source'];
            $effective = ($stored === 'odoo' && $ks->odooPinEnabled && !$trainer && (int) $r['tcred_source_pinned'] !== 1) ? 'odoo' : 'local';
            $out[] = [
                'contact_id' => $cid,
                'name' => (string) $r['contact_name'],
                'dept' => (string) ($r['client_name'] ?? ''),
                'client_id' => (int) $r['contact_client_id'],
                'source' => $effective,
                'stored_source' => $stored,
                'pinned' => (int) ($r['tcred_source_pinned'] ?? 0) === 1,
                'has_local_pin' => (int) ($r['has_local_pin'] ?? 0) === 1,
                'code_pending' => (int) ($r['has_code'] ?? 0) === 1 && KTime::isFuture($r['tcred_setup_code_expires_at_utc']),
                'failed' => (int) ($r['tcred_failed_count'] ?? 0),
                'locked_until' => KTime::isFuture($r['tcred_locked_until_utc']) ? self::iso($r['tcred_locked_until_utc']) : null,
                'hard_locked' => (int) ($r['tcred_hard_locked'] ?? 0) === 1,
                'odoo_blocked' => (int) ($r['tcred_odoo_blocked'] ?? 0) === 1,
                'last_success' => self::iso($r['tcred_last_success_at_utc']),
                'is_trainer' => $trainer,
            ];
        }
        return ['people' => $out, 'truncated' => $truncated, 'odoo_pin_enabled' => $ks->odooPinEnabled];
    }

    /** POST pin_unlock {contact_id, reason} (kiosk >= 2 + trainer rule). */
    public static function pinUnlock(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $cid = (int) $a->int('contact_id', true, 1);
        $reason = self::reason($a);
        $p = Seam::assertContactInScope($c, $cid);
        $trainer = self::trainerRule($c, $cid);
        if (!(new PinAdmin($c, self::keys()))->unlock($cid, $reason)) {
            throw ApiException::notFound('That person has no training PIN record yet.');
        }
        if ($trainer) {
            KioskAlerts::notify($c->db, 'trainer_slip', $cid);
        }
        self::audit($c, 'training.pin_unlocked', 'contact', $cid, 'pin_unlock', 'Unlocked the training PIN of ' . $p['contact_name'], ['reason' => $reason]);
        return [];
    }

    /** POST pin_slips_issue {contact_ids:[], switch_to_local?} (kiosk >= 2 + trainer rule). */
    public static function pinSlipsIssue(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $ids = array_values(array_unique(array_filter(array_map('intval', $a->ints('contact_ids')), static fn($i) => $i > 0)));
        if ($ids === []) {
            throw ApiException::validation(['contact_ids' => 'Pick at least one person.']);
        }
        $trainers = [];
        foreach ($ids as $cid) {
            Seam::assertContactInScope($c, $cid);
            if (self::trainerRule($c, $cid)) {
                $trainers[] = $cid;
            }
        }
        $token = (new PinAdmin($c, self::keys()))->issueSlips($ids, (bool) $a->bool('switch_to_local', false));
        foreach ($trainers as $cid) {
            KioskAlerts::notify($c->db, 'trainer_slip', $cid);
        }
        self::audit($c, 'training.pin_slips_issued', 'contact', count($ids) === 1 ? $ids[0] : null, 'pin_slips', 'Issued ' . count($ids) . ' training PIN setup slip' . (count($ids) === 1 ? '' : 's'),
            ['contact_ids' => $ids, 'switch_to_local' => (bool) $a->bool('switch_to_local', false)]);
        return ['print_url' => '/agent/training_pin_slips.php?t=' . rawurlencode($token), 'count' => count($ids)];
    }

    /** POST pin_slips_clear {t} (kiosk >= 2). */
    public static function pinSlipsClear(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $t = (string) $a->str('t', 100);
        PinAdmin::clearSlips($c, $t);
        return [];
    }

    /** POST pin_odoo_unblock {contact_id, reason} (kiosk >= 2 + trainer rule) [S★]. */
    public static function pinOdooUnblock(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        $cid = (int) $a->int('contact_id', true, 1);
        $reason = self::reason($a);
        $p = Seam::assertContactInScope($c, $cid);
        $trainer = self::trainerRule($c, $cid);
        $ids = (new PinAdmin($c, self::keys()))->unblockOdoo($cid, $reason);
        if ($trainer) {
            KioskAlerts::notify($c->db, 'trainer_slip', $cid);
        }
        self::audit($c, 'training.pin_odoo_unblocked', 'contact', $cid, 'pin_odoo_unblock', 'Confirmed the Odoo link of ' . $p['contact_name'] . ' for PIN sign-in', ['reason' => $reason] + $ids);
        return [];
    }

    /** POST pin_sources_refresh {} (kiosk >= 2) [S★]. */
    public static function pinSourcesRefresh(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(2);
        try {
            $r = (new PinAdmin($c, self::keys()))->refreshSources();
        } catch (\RuntimeException $e) {
            throw new ApiException(503, 'signin_unavailable', 'Odoo did not answer. Try again later.', [], ['reason' => 'odoo']);
        }
        if (!isset($r['skipped'])) {
            self::audit($c, 'training.pin_sources_refreshed', 'settings', 1, 'pin_sources_refresh', 'Refreshed training PIN sources', ['changed' => $r['changed']]);
        }
        return ['odoo' => $r['odoo'], 'local' => $r['local'], 'changed' => $r['changed'], 'needs_slip' => $r['needs_slip'], 'skipped' => $r['skipped'] ?? null];
    }

    /** POST pin_odoo_bulk_unblock {reason} (kiosk 3) [S]. */
    public static function pinOdooBulkUnblock(Ctx $c, ApiContext $a): array
    {
        Access::apiKiosk(3);
        $reason = self::reason($a);
        $r = (new PinAdmin($c, self::keys()))->bulkUnblockOdoo($reason);
        if ($r['unblocked'] > 0) {
            self::audit($c, 'training.pin_odoo_unblocked', 'settings', 1, 'pin_odoo_bulk_unblock', 'Confirmed ' . $r['unblocked'] . ' Odoo links for PIN sign-in', ['reason' => $reason]);
        }
        return ['unblocked' => $r['unblocked'], 'skipped' => $r['skipped']];
    }

    // ------------------------------------------------------------------ helpers

    /** The derived kiosk keys; a missing settings key is a fixed 503. */
    public static function keys(): KioskKeys
    {
        try {
            return KioskKeys::fromSecret((string) ($GLOBALS['config_settings_enc_key'] ?? ''));
        } catch (KioskConfigException) {
            throw new ApiException(503, 'server', 'Training devices are not configured on this server (settings key missing).');
        }
    }

    /**
     * [asset id or null for an unlisted device, UTC expiry or null] from an enrollment request.
     * `unlisted: true` must not come with an asset_id (a device is either one or the other).
     */
    private static function enrollTarget(ApiContext $a): array
    {
        $unlisted = (bool) $a->bool('unlisted', false);
        if ($unlisted && $a->has('asset_id') && $a->input['asset_id'] !== '' && $a->input['asset_id'] !== 0) {
            throw ApiException::validation(['asset_id' => 'A device that is not in Assets has no asset.']);
        }
        $assetId = $unlisted ? null : (int) $a->int('asset_id', true, 1);
        if ($assetId !== null && !Access::canAssets()) {
            throw ApiException::forbidden(Access::ASSETS_NEEDED);   // enrolling by asset id reveals who has the asset
        }
        $preset = (string) ($a->enum('expires', DeviceLifecycle::PRESETS, false) ?? 'keep');
        return [$assetId, DeviceLifecycle::expiryFor($preset, $a->str('expires_until', 20, false))];
    }

    /** An active trainer target needs module_training level 3 or admin (403); true when the target is a trainer. */
    private static function trainerRule(Ctx $c, int $cid): bool
    {
        if (!Seam::isActiveTrainer($c->db, $cid)) {
            return false;
        }
        if (!$c->isAdmin && $c->level < 3) {
            throw ApiException::forbidden('Only Training admins (Full) can reset a trainer’s PIN.');
        }
        return true;
    }

    private static function reason(ApiContext $a): string
    {
        $r = trim((string) preg_replace('/\s+/u', ' ', (string) ($a->str('reason', self::REASON_MAX, true, true) ?? '')));
        if (mb_strlen($r, 'UTF-8') < self::REASON_MIN) {
            throw ApiException::validation(['reason' => 'Give a reason (at least ' . self::REASON_MIN . ' characters).']);
        }
        return $r;
    }

    private static function iso(?string $utc): ?string
    {
        return ($utc === null || $utc === '') ? null : str_replace(' ', 'T', substr($utc, 0, 19)) . 'Z';
    }

    /** "iPad · Safari 17" style summary (never the raw string in the UI list). */
    private static function uaSummary(string $ua): string
    {
        if ($ua === '') {
            return '';
        }
        $dev = match (true) {
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Device',
        };
        $br = '';
        if (preg_match('#Edg(?:e|A|iOS)?/(\d+)#', $ua, $m)) {
            $br = 'Edge ' . $m[1];
        } elseif (preg_match('#(?:Chrome|CriOS)/(\d+)#', $ua, $m)) {
            $br = 'Chrome ' . $m[1];
        } elseif (preg_match('#Firefox/(\d+)#', $ua, $m)) {
            $br = 'Firefox ' . $m[1];
        } elseif (preg_match('#Version/(\d+(?:\.\d+)?).*Safari#', $ua, $m)) {
            $br = 'Safari ' . $m[1];
        }
        return $br === '' ? $dev : "$dev · $br";
    }

    private static function audit(Ctx $c, string $event, string $entityType, ?int $entityId, string $action, string $summary, array $meta): void
    {
        try {
            (new AuditService($c->db))->log($event, $c->userId, $entityType, $entityId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training kiosk audit: ' . get_class($e));
        }
        if (function_exists('logAction')) {
            try {
                logAction('Training', 'PIN', $summary, 0, $entityType === 'contact' && $entityId !== null ? $entityId : 0);
            } catch (\Throwable $e) {
                error_log('Training kiosk logAction: ' . get_class($e));
            }
        }
    }
}
