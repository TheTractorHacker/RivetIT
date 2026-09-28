<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Device enrollment by a signed-in agent (plan A19/A21; P3 spec §3.3). Runs with the agent's Ctx;
 * the Actions layer checks module_training_kiosk levels first. Ledger actor 'user'.
 *
 * A device is a training_kiosks row, normally bound to an asset (Tablet, Phone, Mobile Phone,
 * Laptop, Desktop). Its secret is a random 43-character token: only sha256(token) is stored, and the
 * plain token is returned ONCE, in two forms of the permanent start URL: 'start_url'
 * /kiosk/?d=<token> (the owner's form for the Windows Edge/Chrome kiosk-mode start page; the server
 * - or nginx, which keeps it out of the access log - answers it with a bare 302 to the fragment
 * form) and 'open_url' /kiosk/#d=<token> (a fragment, never sent to a server; the setup page's
 * "Open training" uses it). Re-issuing rotates it. One active kiosk per asset; enrolling an asset
 * that already has one needs $replace (the old one is revoked).
 *
 * UNLISTED devices (2.6.94): $assetId null enrolls a device that is not in Assets ("Trainer's
 * laptop", "Borrowed iPad"). Its label is its only name; kiosk_asset_id / kiosk_asset_type are
 * NULL, so there are no asset lockout checks and no personal mode, and each one is independent
 * (the one-per-asset replacement rule does not apply).
 *
 * TEMPORARY devices (2.6.94): $expiresAt (UTC, from DeviceLifecycle::expiryFor) is stored in
 * kiosk_expires_at_utc; past it the device is revoked on its next request or by the cron
 * (DeviceLifecycle). setExpiry() gives any active device a new end time (a permanent one becomes
 * temporary; null makes it permanent); endNow() revokes a temporary one. A setup code never
 * outlives its device: issueCode() refuses an end time earlier than the code's own TTL
 * (KioskSettings::deviceCodeDays days, config_training_device_code_days).
 *
 * Personal-device mode (A19, D-4): the asset's assigned contact at enrollment (when eligible) is
 * snapshotted into kiosk_personal_contact_id; KioskAuth::device() locks the device out as soon as
 * the asset's assignment no longer matches that snapshot, until a re-issue re-snapshots it.
 *
 * BULK setup codes (2.6.101, owner ask "like how we add non asset devices via pin do the same for
 * iPad"): issueCodes() is the fleet-rollout sibling of issueSlips() (Pin\PinAdmin) - one issueCode()
 * per item (asset-linked and/or unlisted, one shared department/duration), sealed into Core\Scratch
 * the same way and printed from agent/training_device_slips.php.
 */
final class DeviceEnrollment
{
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LABEL_MAX = 100;
    public const MAX_DEVICES = 40;
    public const CODE_SLIP_TTL_S = 600;
    public const SCRATCH_KIND = 'device_codes';

    public function __construct(private readonly Ctx $c, private readonly KioskKeys $keys)
    {
    }

    /** Non-archived assets of an allowed type matching name / serial / tag (25 max). */
    public function assetOptions(string $q): array
    {
        $q = trim($q);
        $types = KioskSettings::ASSET_TYPES;
        $sql = 'SELECT a.asset_id, a.asset_name, a.asset_serial, a.asset_tag, a.asset_type, a.asset_contact_id, a.asset_client_id,
                       c.contact_name, cl.client_name
                FROM assets a
                LEFT JOIN contacts c ON c.contact_id = a.asset_contact_id
                LEFT JOIN clients cl ON cl.client_id = a.asset_client_id
                WHERE a.asset_archived_at IS NULL AND a.asset_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $typesStr = str_repeat('s', count($types));
        $params = $types;
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80, 'UTF-8')) . '%';
            $sql .= ' AND (a.asset_name LIKE ? OR a.asset_serial LIKE ? OR a.asset_tag LIKE ?)';
            $typesStr .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY a.asset_name, a.asset_id LIMIT 25';
        $rows = Db::all($this->c->db, $sql, $typesStr, $params);
        $ids = array_map(static fn($r) => (int) $r['asset_id'], $rows);
        $active = [];
        if ($ids !== []) {
            foreach (Db::all($this->c->db, "SELECT kiosk_id, kiosk_asset_id, kiosk_label FROM training_kiosks WHERE kiosk_status = 'active' AND kiosk_asset_id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $k) {
                $active[(int) $k['kiosk_asset_id']] = ['id' => (int) $k['kiosk_id'], 'label' => (string) $k['kiosk_label']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['asset_contact_id'] ?? 0);
            $out[] = [
                'asset_id' => (int) $r['asset_id'],
                'name' => (string) $r['asset_name'],
                'serial' => (string) ($r['asset_serial'] ?? ''),
                'tag' => (string) ($r['asset_tag'] ?? ''),
                'type' => (string) $r['asset_type'],
                'assigned_contact' => $cid > 0 && $r['contact_name'] !== null
                    ? ['id' => $cid, 'name' => (string) $r['contact_name'], 'eligible' => Eligibility::isEligible($this->c->db, $cid, $this->c)] : null,
                'client_id' => (int) ($r['asset_client_id'] ?? 0),
                'client_name' => (string) ($r['client_name'] ?? ''),
                'active_kiosk' => $active[(int) $r['asset_id']] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Enroll THIS browser's future kiosk: an active row + token. $assetId null = an unlisted device
     * (not in Assets); $expiresAt (UTC) = a temporary device. Returns
     * ['kiosk_id', 'start_url' => https://host/kiosk/?d=<token>, 'open_url' => https://host/kiosk/#d=<token>, 'label',
     *  'personal' => {id,name}|null, 'unlisted' => bool, 'expires_at_utc' => ?string].
     */
    public function enrollHere(?int $assetId, string $label, int $defaultClientId, bool $replace, ?string $expiresAt = null): array
    {
        $token = KioskAuth::newToken();
        $r = $this->insertKiosk($assetId, $label, $defaultClientId, $replace, 'agent_device', KioskAuth::tokenHash($token), null, $expiresAt);
        $this->audit('training.kiosk_enrolled', $r['kiosk_id'], 'enroll', 'Enrolled training device "' . $r['label'] . '"' . ($assetId === null ? ' (not in Assets)' : ''),
            ['asset_id' => $assetId, 'personal' => $r['personal'] !== null, 'expires_at_utc' => $expiresAt]);
        $r['start_url'] = $this->startUrl($token);
        $r['open_url'] = $this->openUrl($token);
        unset($token);
        return $r;
    }

    /**
     * [S] A pending row redeemable once on the device with a 10-character code
     * (KioskSettings::deviceCodeDays days; config_training_device_code_days, default 3).
     * Returns ['kiosk_id', 'code' => 'XXXXX-XXXXX', 'expires_at' (UTC ISO), 'label', 'personal', 'unlisted', 'expires_at_utc'].
     */
    public function issueCode(?int $assetId, string $label, int $defaultClientId, bool $replace, ?string $expiresAt = null): array
    {
        $ttlS = KioskSettings::fromDb($this->c->db)->deviceCodeDays * 86400;
        $code = '';
        $n = strlen(self::CODE_ALPHABET);
        for ($i = 0; $i < 10; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $n - 1)];
        }
        $expires = KTime::plus($ttlS);
        if ($expiresAt !== null && (KTime::epoch($expiresAt) ?? 0) < (KTime::epoch($expires) ?? 0)) {
            // Otherwise the right code could reach a device whose time is already up (and count as a wrong guess).
            throw ApiException::validation(['expires' => 'A device set up with a code must stay set up for at least ' . intdiv($ttlS, 86400)
                . ' day' . (intdiv($ttlS, 86400) === 1 ? '' : 's') . ' (the code lasts that long). Pick a later end time.']);
        }
        $r = $this->insertKiosk($assetId, $label, $defaultClientId, $replace, 'setup_code', null, ['hash' => hash('sha256', $code), 'expires' => $expires], $expiresAt);
        $this->audit('training.kiosk_enrolled', $r['kiosk_id'], 'enroll_code', 'Issued a setup code for training device "' . $r['label'] . '"' . ($assetId === null ? ' (not in Assets)' : ''),
            ['asset_id' => $assetId, 'expires_at_utc' => $expiresAt]);
        $r['code'] = substr($code, 0, 5) . '-' . substr($code, 5);
        $r['expires_at'] = str_replace(' ', 'T', substr($expires, 0, 19)) . 'Z';
        unset($code);
        return $r;
    }

    /**
     * [S] Bulk setup codes for a fleet rollout (owner ask 2026-09-28: "like how we add non asset
     * devices via pin do the same for iPad") - the device-side sibling of Pin\PinAdmin::issueSlips.
     * One issueCode() per item (so every item gets the same TTL, replace and one-active-per-asset
     * handling issueCode() already gives a single device), one shared department and duration.
     *
     * $items: an item with a positive asset_id enrolls that asset; any other item is unlisted
     * (label only). Every referenced asset is prechecked for the WHOLE batch before anything is
     * written - exists, not archived, an allowed type, and (unless $replace) not already actively
     * enrolled - same shape as issueSlips' Odoo-source precheck - so a batch fails clean listing the
     * problem rather than half-applying over it (a genuinely reachable case: an asset archived by
     * another admin between page load and submit, not just a tampered request). Beyond that
     * precheck, a later item's failure (e.g. a duplicate asset_id inside one submission, which the
     * precheck does not deduplicate) does not roll back earlier items already committed - the same
     * tolerance issueSlips already accepts; each item's row is its own transaction, exactly like
     * each person's credential update is.
     *
     * @param list<array{label:string, asset_id?:?int}> $items
     * @return string the Scratch token for agent/training_device_slips.php?t=
     */
    public function issueCodes(array $items, int $defaultClientId, ?string $expiresAt, bool $replace): string
    {
        if ($items === []) {
            throw ApiException::validation(['items' => 'Add at least one device.']);
        }
        if (count($items) > self::MAX_DEVICES) {
            throw ApiException::validation(['items' => 'At most ' . self::MAX_DEVICES . ' devices at a time.']);
        }
        $db = $this->c->db;
        $clean = [];
        $assetIds = [];
        foreach ($items as $it) {
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($it['label'] ?? '')) ?? '');
            if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
                throw ApiException::validation(['items' => 'Every device needs a name (up to ' . self::LABEL_MAX . ' characters).']);
            }
            $assetId = isset($it['asset_id']) && (int) $it['asset_id'] > 0 ? (int) $it['asset_id'] : null;
            if ($assetId !== null) {
                $assetIds[] = $assetId;
            }
            $clean[] = ['label' => $label, 'asset_id' => $assetId];
        }
        if ($assetIds !== []) {
            $ids = array_values(array_unique($assetIds));
            // Whole-batch existence/archived/type precheck (matches insertKiosk()'s own per-item
            // checks, run here up front so a batch fails clean instead of half-writing - the gap a
            // regression review found: without this, an asset archived between page load and submit
            // would throw mid-loop, leaving earlier items in this same batch committed as 'pending'
            // with a live code that was never returned to the caller and so never printed.
            $found = [];
            foreach (Db::all($db, 'SELECT asset_id, asset_type, asset_archived_at FROM assets WHERE asset_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $r) {
                $found[(int) $r['asset_id']] = $r;
            }
            $missing = [];
            $badType = [];
            foreach ($ids as $aid) {
                $r = $found[$aid] ?? null;
                if ($r === null || $r['asset_archived_at'] !== null) {
                    $missing[] = $aid;
                } elseif (!in_array((string) $r['asset_type'], KioskSettings::ASSET_TYPES, true)) {
                    $badType[] = $aid;
                }
            }
            if ($missing !== []) {
                throw ApiException::validation(['items' => count($missing) === 1
                    ? 'One of these assets no longer exists or is archived. Remove it and try again.'
                    : count($missing) . ' of these assets no longer exist or are archived. Remove them and try again.']);
            }
            if ($badType !== []) {
                throw ApiException::validation(['items' => count($badType) === 1
                    ? "One of these assets isn't a tablet, phone, laptop or desktop, so it can't be a training device."
                    : count($badType) . " of these assets aren't tablets, phones, laptops or desktops, so they can't be training devices."]);
            }
        }
        if ($assetIds !== [] && !$replace) {
            $ids = array_values(array_unique($assetIds));
            $existing = Db::all($db, "SELECT kiosk_asset_id, kiosk_label FROM training_kiosks WHERE kiosk_status = 'active' AND kiosk_asset_id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
            if ($existing !== []) {
                throw new ApiException(409, 'assets_enrolled', count($existing) === 1
                    ? 'One of these assets is already a training device ("' . $existing[0]['kiosk_label'] . '"). Tick "Replace already-enrolled assets" to move it here.'
                    : count($existing) . ' of these assets are already training devices. Tick "Replace already-enrolled assets" to move them here.',
                    [], ['conflicts' => array_map(static fn($r) => ['asset_id' => (int) $r['kiosk_asset_id'], 'label' => (string) $r['kiosk_label']], $existing)]);
            }
        }
        $batch = [];
        foreach ($clean as $it) {
            $r = $this->issueCode($it['asset_id'], $it['label'], $defaultClientId, $replace, $expiresAt);
            $batch[] = ['kiosk_id' => $r['kiosk_id'], 'label' => $r['label'], 'code' => $r['code'],
                        'expires_on' => substr($r['expires_at'], 0, 10), 'unlisted' => $r['unlisted']];
            unset($r);
        }

        $json = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($batch as $i => $b) {
            $batch[$i]['code'] = '';
        }
        unset($batch);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->keys->slipKey();
        $box = sodium_crypto_secretbox($json, $nonce, $key);
        sodium_memzero($json);
        sodium_memzero($key);
        return Scratch::put(self::SCRATCH_KIND, $this->c->userId, ['n' => base64_encode($nonce), 'c' => base64_encode($box)], self::CODE_SLIP_TTL_S);
    }

    /**
     * The decrypted device-code batch for the issuing user (Scratch::get, NOT take: a reload within
     * the TTL reprints the same slips), or null when missing, expired, another user's or
     * undecryptable. Mirrors Pin\PinAdmin::openSlips exactly (same Scratch pattern, same slip key).
     *
     * @return list<array{kiosk_id:int,label:string,code:string,expires_on:string,unlisted:bool}>|null
     */
    public static function openCodes(Ctx $c, KioskKeys $keys, string $token): ?array
    {
        $rec = Scratch::get(self::SCRATCH_KIND, $token, $c->userId);
        if ($rec === null || !is_string($rec['n'] ?? null) || !is_string($rec['c'] ?? null)) {
            return null;
        }
        $nonce = base64_decode($rec['n'], true);
        $box = base64_decode($rec['c'], true);
        if ($nonce === false || $box === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $key = $keys->slipKey();
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            return null;
        }
        $list = json_decode($plain, true);
        sodium_memzero($plain);
        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /** "Done - clear these slips": the single take(). */
    public static function clearCodes(Ctx $c, string $token): bool
    {
        return Scratch::take(self::SCRATCH_KIND, $token, $c->userId) !== null;
    }

    /** Revoke: status revoked, token (and any code) NULL, open kiosk session ended 'revoked'. */
    public function revoke(int $kioskId, string $reason): void
    {
        $db = $this->c->db;
        $base = $this->eventBase();
        $label = Db::tx($db, function () use ($db, $kioskId, $reason, $base): string {
            $k = Db::one($db, 'SELECT kiosk_id, kiosk_label, kiosk_status FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($k === null) {
                throw ApiException::notFound('That device was not found.');
            }
            if ($k['kiosk_status'] === 'revoked') {
                throw new ApiException(409, 'validation', 'That device is already revoked.');
            }
            DeviceLifecycle::revokeLocked($db, $kioskId, $reason, $this->c->userId, $base);
            return (string) $k['kiosk_label'];
        });
        $this->audit('training.kiosk_revoked', $kioskId, 'revoke', 'Revoked training device "' . $label . '"', ['reason' => $reason]);
    }

    /**
     * A new end time (UTC, from DeviceLifecycle::expiryFor; null = keep until removed) for an ACTIVE
     * device whose time is not up yet - temporary or permanent (a permanent device becomes
     * temporary). kiosk.expiry_changed {from, to}. Records never show the current expiry
     * (DeviceLifecycle::expiryAsOf), so changing it later rewrites nothing.
     * Returns ['kiosk_id', 'label', 'expires_at_utc'].
     */
    public function setExpiry(int $kioskId, ?string $expiresAt): array
    {
        if ($expiresAt !== null && !KTime::isFuture($expiresAt)) {
            throw ApiException::validation(['expires' => 'That time has already passed.']);
        }
        $db = $this->c->db;
        $base = $this->eventBase();
        $r = Db::tx($db, function () use ($db, $kioskId, $expiresAt, $base): array {
            $k = Db::one($db, 'SELECT kiosk_id, kiosk_label, kiosk_status, kiosk_expires_at_utc FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($k === null) {
                throw ApiException::notFound('That device was not found.');
            }
            if ($k['kiosk_status'] !== 'active') {
                throw new ApiException(409, 'validation', 'Only an active device can get a new end time.');
            }
            if (DeviceLifecycle::isExpired($k['kiosk_expires_at_utc'])) {
                throw new ApiException(409, 'validation', 'This device has already expired. Set it up again.');
            }
            Db::exec($db, 'UPDATE training_kiosks SET kiosk_expires_at_utc = ? WHERE kiosk_id = ?', 'si', [$expiresAt, $kioskId]);
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.expiry_changed', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId,
                'payload' => ['from' => $k['kiosk_expires_at_utc'], 'to' => $expiresAt],
            ]));
            return ['kiosk_id' => $kioskId, 'label' => (string) $k['kiosk_label'], 'from' => $k['kiosk_expires_at_utc'], 'expires_at_utc' => $expiresAt];
        });
        $this->audit('training.kiosk_expiry_changed', $kioskId, 'expiry', ($expiresAt === null ? 'Kept training device "' . $r['label'] . '" until removed'
            : 'Changed when training device "' . $r['label'] . '" expires'), ['from' => $r['from'], 'to' => $expiresAt]);
        unset($r['from']);
        return $r;
    }

    /**
     * Temporary devices: "End now" - a revoke with DeviceLifecycle::ENDED_REASON (only for a
     * temporary device). One whose time is already up (not cleaned up yet) is removed with
     * DeviceLifecycle::EXPIRED_REASON instead ("Remove now" on the Devices list).
     */
    public function endNow(int $kioskId): void
    {
        $k = Db::one($this->c->db, 'SELECT kiosk_expires_at_utc FROM training_kiosks WHERE kiosk_id = ?', 'i', [$kioskId]);
        if ($k === null) {
            throw ApiException::notFound('That device was not found.');
        }
        if ($k['kiosk_expires_at_utc'] === null) {
            throw new ApiException(409, 'validation', 'This device is kept until you remove it. Use Revoke instead.');
        }
        $this->revoke($kioskId, DeviceLifecycle::isExpired($k['kiosk_expires_at_utc']) ? DeviceLifecycle::EXPIRED_REASON : DeviceLifecycle::ENDED_REASON);
    }

    /** New start URL (the old token stops working); re-snapshots the personal owner (A19). */
    public function reissue(int $kioskId): array
    {
        $db = $this->c->db;
        $token = KioskAuth::newToken();
        $hash = KioskAuth::tokenHash($token);
        $base = $this->eventBase();
        $r = Db::tx($db, function () use ($db, $kioskId, $hash, $base): array {
            $k = Db::one($db, 'SELECT kiosk_id, kiosk_asset_id, kiosk_label, kiosk_status, kiosk_expires_at_utc FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($k === null) {
                throw ApiException::notFound('That device was not found.');
            }
            if ($k['kiosk_status'] !== 'active') {
                throw new ApiException(409, 'validation', 'Only an active device gets a new start URL. Enroll it again instead.');
            }
            if (DeviceLifecycle::isExpired($k['kiosk_expires_at_utc'])) {
                throw new ApiException(409, 'validation', 'This device has already expired. Set it up again.');
            }
            if ($k['kiosk_asset_id'] === null) {
                // An unlisted device: no asset to re-check and never personal.
                $asset = null;
                $personal = null;
            } else {
                $asset = Db::one($db, 'SELECT asset_id, asset_type, asset_serial, asset_archived_at, asset_contact_id FROM assets WHERE asset_id = ? FOR UPDATE', 'i', [(int) $k['kiosk_asset_id']]);
                if ($asset === null || $asset['asset_archived_at'] !== null || !in_array((string) $asset['asset_type'], KioskSettings::ASSET_TYPES, true)) {
                    throw new ApiException(409, 'validation', 'The asset is archived or no longer a device type. Revoke this device instead.');
                }
                $personal = $this->personalFor($asset);
            }
            DeviceLifecycle::endOpenSessions($db, (int) $kioskId, 'revoked', $base);
            if ($asset === null) {
                Db::exec($db, 'UPDATE training_kiosks SET kiosk_token_hash = ?, kiosk_token_issued_at_utc = ?, kiosk_personal_contact_id = NULL,
                        kiosk_cooldown_until_utc = NULL, kiosk_cooldown_reason = NULL WHERE kiosk_id = ?', 'ssi', [$hash, KTime::now(), $kioskId]);
            } else {
                Db::exec($db, 'UPDATE training_kiosks SET kiosk_token_hash = ?, kiosk_token_issued_at_utc = ?, kiosk_personal_contact_id = ?, kiosk_asset_type = ?,
                        kiosk_asset_serial = ?, kiosk_cooldown_until_utc = NULL, kiosk_cooldown_reason = NULL WHERE kiosk_id = ?',
                    'ssissi', [$hash, KTime::now(), $personal['id'] ?? null, (string) $asset['asset_type'], $asset['asset_serial'], $kioskId]);
            }
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.token_reissued', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId,
                'payload' => ['asset_id' => $asset === null ? null : (int) $asset['asset_id'], 'asset_type' => $asset === null ? null : (string) $asset['asset_type'],
                              'label' => (string) $k['kiosk_label'], 'method' => 'agent_device', 'personal' => $personal !== null],
            ]));
            return ['kiosk_id' => $kioskId, 'label' => (string) $k['kiosk_label'], 'personal' => $personal, 'unlisted' => $asset === null,
                    'expires_at_utc' => $k['kiosk_expires_at_utc']];
        });
        $this->audit('training.kiosk_token_reissued', $kioskId, 'reissue', 'New start URL for training device "' . $r['label'] . '"', ['personal' => $r['personal'] !== null]);
        $r['start_url'] = $this->startUrl($token);
        $r['open_url'] = $this->openUrl($token);
        unset($token);
        return $r;
    }

    // ------------------------------------------------------------------------------------------

    private function insertKiosk(?int $assetId, string $label, int $defaultClientId, bool $replace, string $method, ?string $tokenHash, ?array $code, ?string $expiresAt): array
    {
        $db = $this->c->db;
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            throw ApiException::validation(['label' => 'Give the device a name (up to ' . self::LABEL_MAX . ' characters).']);
        }
        if ($defaultClientId > 0 && Db::one($db, 'SELECT client_id FROM clients WHERE client_id = ? AND client_archived_at IS NULL', 'i', [$defaultClientId]) === null) {
            throw ApiException::validation(['default_client_id' => 'Pick a department that exists.']);
        }
        if ($expiresAt !== null && !KTime::isFuture($expiresAt)) {
            throw ApiException::validation(['expires' => 'That time has already passed.']);
        }
        $base = $this->eventBase();
        $userId = $this->c->userId;
        return Db::tx($db, function () use ($db, $assetId, $label, $defaultClientId, $replace, $method, $tokenHash, $code, $expiresAt, $base, $userId): array {
            $asset = null;
            $personal = null;
            if ($assetId !== null) {
                $asset = Db::one($db, 'SELECT asset_id, asset_type, asset_serial, asset_archived_at, asset_contact_id FROM assets WHERE asset_id = ? FOR UPDATE', 'i', [$assetId]);
                if ($asset === null || $asset['asset_archived_at'] !== null) {
                    throw ApiException::notFound('That asset was not found.');
                }
                if (!in_array((string) $asset['asset_type'], KioskSettings::ASSET_TYPES, true)) {
                    throw ApiException::validation(['asset_id' => 'Only tablets, phones, laptops and desktops can be training devices.']);
                }
                $existing = Db::all($db, "SELECT kiosk_id, kiosk_label, kiosk_status FROM training_kiosks WHERE kiosk_asset_id = ? AND kiosk_status IN ('active','pending') FOR UPDATE", 'i', [$assetId]);
                $active = array_values(array_filter($existing, static fn($k) => $k['kiosk_status'] === 'active'));
                if ($active !== [] && !$replace) {
                    throw new ApiException(409, 'asset_enrolled', 'This asset is already a training device.', [], ['label' => (string) $active[0]['kiosk_label'], 'kiosk_id' => (int) $active[0]['kiosk_id']]);
                }
                foreach ($existing as $k) {
                    // Replacing: the old device (and any unredeemed code for this asset) stops working now.
                    DeviceLifecycle::revokeLocked($db, (int) $k['kiosk_id'], 're-enrolled', $userId, $base);
                }
                $personal = $this->personalFor($asset);
            }
            // An unlisted device (no asset) is independent: nothing to replace, never personal.
            $now = KTime::now();
            $active = $method === 'agent_device';
            $id = Db::insert($db, 'INSERT INTO training_kiosks (kiosk_asset_id, kiosk_asset_type, kiosk_asset_serial, kiosk_personal_contact_id, kiosk_label,
                    kiosk_default_client_id, kiosk_status, kiosk_enroll_method, kiosk_enroll_code_hash, kiosk_enroll_expires_at_utc, kiosk_token_hash,
                    kiosk_token_issued_at_utc, kiosk_enrolled_at_utc, kiosk_enrolled_by, kiosk_expires_at_utc, kiosk_created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'issisisssssssisi',
                [$assetId, $asset === null ? null : (string) $asset['asset_type'], $asset['asset_serial'] ?? null, $personal['id'] ?? null, $label, max(0, $defaultClientId),
                 $active ? 'active' : 'pending', $method, $code['hash'] ?? null, $code['expires'] ?? null, $tokenHash,
                 $active ? $now : null, $active ? $now : null, $active ? $userId : null, $expiresAt, $userId]);
            Ledger::append($db, array_merge($base, [
                'type' => $active ? 'kiosk.enrolled' : 'kiosk.enroll_code_issued', 'kiosk_id' => $id, 'entity_type' => 'kiosk', 'entity_id' => $id,
                'payload' => ['asset_id' => $assetId, 'asset_type' => $asset === null ? null : (string) $asset['asset_type'], 'label' => $label, 'method' => $method,
                              'personal' => $personal !== null, 'unlisted' => $assetId === null, 'expires_at_utc' => $expiresAt],
            ]));
            return ['kiosk_id' => $id, 'label' => $label, 'personal' => $personal, 'unlisted' => $assetId === null, 'expires_at_utc' => $expiresAt];
        });
    }

    /** The asset's assigned contact when eligible (personal device), else null (shared device). */
    private function personalFor(array $asset): ?array
    {
        $cid = (int) ($asset['asset_contact_id'] ?? 0);
        if ($cid < 1 || !Eligibility::isEligible($this->c->db, $cid, $this->c)) {
            return null;
        }
        $c = Db::one($this->c->db, 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$cid]);
        return $c === null ? null : ['id' => $cid, 'name' => trim((string) $c['contact_name'])];
    }

    /** The permanent start URL to copy into a kiosk-mode browser: /kiosk/?d=<token> (answered with a 302 to the fragment form). */
    private function startUrl(string $token): string
    {
        return rtrim($this->c->baseUrl, '/') . '/kiosk/?d=' . $token;
    }

    /** The same token as a fragment, for opening training on this browser right now (never reaches a server). */
    private function openUrl(string $token): string
    {
        return rtrim($this->c->baseUrl, '/') . '/kiosk/#d=' . $token;
    }

    private function eventBase(): array
    {
        return ['actor_type' => 'user', 'actor_user_id' => $this->c->userId, 'user_agent' => $this->c->userAgent];
    }

    private function audit(string $event, int $kioskId, string $action, string $summary, array $meta): void
    {
        try {
            (new AuditService($this->c->db))->log($event, $this->c->userId, 'training_kiosk', $kioskId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training kiosk audit: ' . get_class($e));
        }
        if (function_exists('logAction')) {
            try {
                logAction('Training', 'Device', $summary, 0, $kioskId);
            } catch (\Throwable $e) {
                error_log('Training kiosk logAction: ' . get_class($e));
            }
        }
    }
}
