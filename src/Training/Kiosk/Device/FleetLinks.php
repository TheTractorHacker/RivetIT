<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Pin\Seam;

/**
 * Fleet links, the agent side (DB 2.6.151, GitHub issue #43): ONE enrollment link for a whole
 * department's tablets, pushed by an MDM, as an option alongside the per-device start URL
 * (DeviceEnrollment) and the setup code (CodeEnrollment). The device side is FleetEnrollment.
 *
 * A link is modelled on the endpoint agent's enrollment tokens (rvte1): scoped (one department =
 * one client), expiring, capped at a number of enrollments, revocable and rotatable. Only
 * sha256(token) is stored (training_fleet_links.fleet_token_hash); the plain token, form
 * "rvkf1_" + 43 base64url characters, is returned ONCE by create() / rotate(). It travels as
 * /kiosk/?e=<token>&sn=<serial> and, exactly like the per-device ?d= URL, the kiosk answers that
 * with a bare redirect to the fragment form /kiosk/#e=<token>&sn=<serial> so neither reaches an
 * access log; the page then redeems it by POST (PreauthActions::enrollFleet).
 *
 * APPROVAL. 'require' (the default): every device that redeems the link lands as a PENDING
 * training_kiosks row (method 'fleet') and gets no training data until an admin approves it here.
 * 'auto_match': a device whose serial matches exactly one active, non-archived Asset of the link's
 * department (of a device type) that has no active kiosk yet is enrolled on the spot; everything
 * else still waits for approval.
 *
 * Approving binds the device the way an agent enrollment would (asset, personal-owner snapshot,
 * department); rejecting revokes the row, which also stops its token. Revoking a LINK only stops
 * new enrollments: devices that already enrolled keep working (they hold their own device token).
 * Every action is audited (AuditService + logAction) and the training ledger records the device
 * events; neither ever carries a token.
 */
final class FleetLinks
{
    public const TOKEN_PREFIX = 'rvkf1_';
    public const TOKEN_RE = '/^rvkf1_[A-Za-z0-9_-]{43}$/D';
    /** A device serial as an MDM macro fills it in (Apple 8-14 alphanumerics, Android and others vary). */
    public const SERIAL_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/D';
    /** The text an MDM replaces with the serial number; shown in the URL template and Web Clip. */
    public const PLACEHOLDER_RE = '/^[A-Za-z0-9$%{}_.-]{1,40}$/D';
    public const DEFAULT_PLACEHOLDER = '%SerialNumber%';
    public const LABEL_MAX = 100;
    public const MAX_USES_CAP = 5000;
    public const MAX_DAYS = 90;
    public const APPROVALS = ['require', 'auto_match'];
    public const ICON_PATH = '/kiosk/icons/apple-touch-icon.png';

    public function __construct(private readonly Ctx $c)
    {
    }

    public static function newToken(): string
    {
        return self::TOKEN_PREFIX . KioskAuth::newToken();
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** True when the department is in the user's scope (null scope = every department). */
    public function inScope(int $clientId): bool
    {
        $scope = Seam::scopeClientIds($this->c);
        return $scope === null || in_array($clientId, $scope, true);
    }

    // ------------------------------------------------------------------------------------------
    // Links

    /**
     * New link. $expiresAt is a future UTC time at most MAX_DAYS away. Returns the row summary plus the
     * plain 'token' and its 'url' (shown once).
     *
     * @return array{fleet_id:int, token:string, url:string}
     */
    public function create(string $label, int $clientId, string $approval, string $expiresAt, int $maxUses): array
    {
        $db = $this->c->db;
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            throw ApiException::validation(['label' => 'Give the link a name (up to ' . self::LABEL_MAX . ' characters).']);
        }
        if (!in_array($approval, self::APPROVALS, true)) {
            throw ApiException::validation(['approval' => 'Pick how new devices are approved.']);
        }
        if ($maxUses < 1 || $maxUses > self::MAX_USES_CAP) {
            throw ApiException::validation(['max_uses' => 'Pick between 1 and ' . self::MAX_USES_CAP . ' devices.']);
        }
        if (!KTime::isFuture($expiresAt) || (KTime::epoch($expiresAt) ?? 0) > time() + self::MAX_DAYS * 86400 + 60) {
            throw ApiException::validation(['expires' => 'Pick an end between now and ' . self::MAX_DAYS . ' days from now.']);
        }
        if ($clientId < 1 || Db::one($db, 'SELECT client_id FROM clients WHERE client_id = ? AND client_archived_at IS NULL', 'i', [$clientId]) === null) {
            throw ApiException::validation(['client_id' => 'Pick a department that exists.']);
        }
        if (!$this->inScope($clientId)) {
            throw ApiException::forbidden('That department is outside your access.');
        }
        $token = self::newToken();
        $id = Db::insert($db, 'INSERT INTO training_fleet_links (fleet_token_hash, fleet_label, fleet_client_id, fleet_approval, fleet_expires_at_utc, fleet_max_uses, fleet_created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)', 'ssissii', [self::tokenHash($token), $label, $clientId, $approval, $expiresAt, $maxUses, $this->c->userId]);
        $this->audit('training.kiosk_fleet_created', $id, 'fleet_create', 'Created training fleet link "' . $label . '"',
            ['client_id' => $clientId, 'approval' => $approval, 'max_uses' => $maxUses, 'expires_at_utc' => $expiresAt]);
        $r = ['fleet_id' => $id, 'token' => $token, 'url' => $this->url($token)];
        unset($token);
        return $r;
    }

    /** Revoke a link: no new enrollment. Devices that already enrolled through it are not touched. */
    public function revoke(int $fleetId, string $reason): void
    {
        $db = $this->c->db;
        $label = Db::tx($db, function () use ($db, $fleetId, $reason): string {
            $l = $this->lockLink($fleetId);
            if ($l['fleet_revoked_at_utc'] !== null) {
                throw new ApiException(409, 'validation', 'That link is already revoked.');
            }
            Db::exec($db, 'UPDATE training_fleet_links SET fleet_revoked_at_utc = ?, fleet_revoked_by = ?, fleet_revoke_reason = ? WHERE fleet_id = ?',
                'sisi', [KTime::now(), $this->c->userId, mb_substr($reason, 0, 255, 'UTF-8'), $fleetId]);
            return (string) $l['fleet_label'];
        });
        $this->audit('training.kiosk_fleet_revoked', $fleetId, 'fleet_revoke', 'Revoked training fleet link "' . $label . '"', ['reason' => $reason]);
    }

    /**
     * Rotate: the old link is revoked ("Rotated") and a new one with the same name, department, approval and
     * device cap is minted with a fresh token and a fresh end (the old end if still ahead, else 30 days).
     *
     * @return array{fleet_id:int, token:string, url:string}
     */
    public function rotate(int $fleetId): array
    {
        $old = $this->link($fleetId);
        $expires = KTime::isFuture($old['fleet_expires_at_utc']) ? (string) $old['fleet_expires_at_utc'] : KTime::plus(30 * 86400);
        if ($old['fleet_revoked_at_utc'] === null) {
            $this->revoke($fleetId, 'Rotated');
        }
        $r = $this->create((string) $old['fleet_label'], (int) $old['fleet_client_id'], (string) $old['fleet_approval'], $expires, (int) $old['fleet_max_uses']);
        $this->audit('training.kiosk_fleet_rotated', $r['fleet_id'], 'fleet_rotate', 'Rotated training fleet link "' . $old['fleet_label'] . '"', ['replaces' => $fleetId]);
        return $r;
    }

    /**
     * Every link in the user's scope, newest first, with a computed state (active | expired | used_up | revoked)
     * and its device counts. No token or hash.
     */
    public function listLinks(): array
    {
        $db = $this->c->db;
        $scope = Seam::scopeClientIds($this->c);
        if ($scope === []) {
            return [];
        }
        $sql = "SELECT f.fleet_id, f.fleet_label, f.fleet_client_id, f.fleet_approval, f.fleet_expires_at_utc, f.fleet_max_uses, f.fleet_use_count,
                f.fleet_last_used_at_utc, f.fleet_revoked_at_utc, f.fleet_revoke_reason, f.fleet_created_at, cl.client_name, u.user_name AS created_by_name,
                (SELECT COUNT(*) FROM training_kiosks k WHERE k.kiosk_fleet_id = f.fleet_id AND k.kiosk_status = 'pending') AS pending_count,
                (SELECT COUNT(*) FROM training_kiosks k WHERE k.kiosk_fleet_id = f.fleet_id AND k.kiosk_status = 'active') AS active_count
            FROM training_fleet_links f
            LEFT JOIN clients cl ON cl.client_id = f.fleet_client_id
            LEFT JOIN users u ON u.user_id = f.fleet_created_by";
        $types = '';
        $params = [];
        if ($scope !== null) {
            $sql .= ' WHERE f.fleet_client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types = str_repeat('i', count($scope));
            $params = $scope;
        }
        $sql .= ' ORDER BY f.fleet_id DESC LIMIT 200';
        $out = [];
        foreach (Db::all($db, $sql, $types, $params) as $r) {
            $out[] = [
                'id' => (int) $r['fleet_id'],
                'label' => (string) $r['fleet_label'],
                'client_id' => (int) $r['fleet_client_id'],
                'department' => (string) ($r['client_name'] ?? ''),
                'approval' => (string) $r['fleet_approval'],
                'state' => self::state($r),
                'expires_at' => self::iso($r['fleet_expires_at_utc']),
                'max_uses' => (int) $r['fleet_max_uses'],
                'use_count' => (int) $r['fleet_use_count'],
                'last_used_at' => self::iso($r['fleet_last_used_at_utc']),
                'revoked_at' => self::iso($r['fleet_revoked_at_utc']),
                'revoke_reason' => $r['fleet_revoke_reason'],
                'created_at' => self::iso($r['fleet_created_at']),
                'created_by' => (string) ($r['created_by_name'] ?? ''),
                'pending_count' => (int) $r['pending_count'],
                'active_count' => (int) $r['active_count'],
            ];
        }
        return $out;
    }

    /** active | expired | used_up | revoked for a training_fleet_links row. */
    public static function state(array $r): string
    {
        if ($r['fleet_revoked_at_utc'] !== null) {
            return 'revoked';
        }
        if (!KTime::isFuture($r['fleet_expires_at_utc'])) {
            return 'expired';
        }
        return (int) $r['fleet_use_count'] >= (int) $r['fleet_max_uses'] ? 'used_up' : 'active';
    }

    /**
     * The link when $token is its token (hash compare) and it is not revoked - the proof the admin who asks for a
     * Web Clip or a URL export still holds the once-shown token. 404 otherwise (a wrong token is never explained).
     */
    public function linkForToken(int $fleetId, mixed $token): array
    {
        $l = $this->link($fleetId);
        if (!is_string($token) || preg_match(self::TOKEN_RE, $token) !== 1 || !hash_equals((string) $l['fleet_token_hash'], self::tokenHash($token))) {
            throw ApiException::notFound('That link or token was not found. Rotate the link to get a new one.');
        }
        if ($l['fleet_revoked_at_utc'] !== null) {
            throw new ApiException(409, 'validation', 'That link is revoked.');
        }
        return $l;
    }

    /** The shareable fleet URL. $serial is the literal serial, or the MDM's placeholder text; null = the plain link. */
    public function url(string $token, ?string $serial = null): string
    {
        $u = rtrim($this->c->baseUrl, '/') . '/kiosk/?e=' . $token;
        return $serial === null ? $u : $u . '&sn=' . $serial;
    }

    // ------------------------------------------------------------------------------------------
    // Apple Web Clip

    /**
     * The .mobileconfig XML for one link: a managed Web Clip (Full Screen, not removable, the kiosk icon embedded
     * as base64) whose URL carries the MDM's serial placeholder. Unsigned; the PayloadUUIDs are v5-style and stable
     * for a link, so pushing it again updates the same profile instead of adding a second.
     *
     * @return array{filename:string, content:string}
     */
    public function mobileconfig(int $fleetId, mixed $token, string $placeholder, string $name): array
    {
        $l = $this->linkForToken($fleetId, $token);
        if (preg_match(self::PLACEHOLDER_RE, $placeholder) !== 1) {
            throw ApiException::validation(['placeholder' => 'Use the serial-number macro your MDM expects, like %SerialNumber% (letters, digits and $ % { } _ . - only).']);
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 40) {
            throw ApiException::validation(['name' => 'Give the Home Screen icon a short name (up to 40 characters).']);
        }
        $icon = @file_get_contents(dirname(__DIR__, 4) . self::ICON_PATH);
        if ($icon === false || $icon === '') {
            throw new ApiException(500, 'server', 'The kiosk icon is missing on this server.');
        }
        $url = $this->url((string) $token, $placeholder);
        $seed = (string) $l['fleet_token_hash'];
        $host = (string) (parse_url($this->c->baseUrl, PHP_URL_HOST) ?: 'kiosk');
        $content = self::buildProfile($url, $name, (string) $l['fleet_label'], $icon, (int) $l['fleet_id'], $seed, $host);
        $this->audit('training.kiosk_fleet_profile', $fleetId, 'fleet_profile', 'Downloaded the Web Clip profile for training fleet link "' . $l['fleet_label'] . '"', []);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $l['fleet_label']), '-');
        return ['filename' => 'training-kiosk-' . ($slug === '' ? 'fleet' : strtolower($slug)) . '.mobileconfig', 'content' => $content];
    }

    /** Pure profile builder (unit-testable): no database. */
    public static function buildProfile(string $url, string $iconLabel, string $linkLabel, string $iconPng, int $fleetId, string $seed, string $host): string
    {
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $idBase = 'com.rivetit.kiosk.fleet.' . $fleetId;
        $b64 = chunk_split(base64_encode($iconPng), 76, "\n");
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0">' . "\n<dict>\n"
            . "\t<key>PayloadContent</key>\n\t<array>\n\t\t<dict>\n"
            . "\t\t\t<key>FullScreen</key>\n\t\t\t<true/>\n"
            . "\t\t\t<key>Icon</key>\n\t\t\t<data>\n" . $b64 . "\t\t\t</data>\n"
            . "\t\t\t<key>IgnoreManifestScope</key>\n\t\t\t<true/>\n"
            . "\t\t\t<key>IsRemovable</key>\n\t\t\t<false/>\n"
            . "\t\t\t<key>Label</key>\n\t\t\t<string>" . $x($iconLabel) . "</string>\n"
            . "\t\t\t<key>PayloadDescription</key>\n\t\t\t<string>Opens the training kiosk full screen and sets this device up.</string>\n"
            . "\t\t\t<key>PayloadDisplayName</key>\n\t\t\t<string>" . $x($iconLabel) . "</string>\n"
            . "\t\t\t<key>PayloadIdentifier</key>\n\t\t\t<string>" . $x($idBase . '.webclip') . "</string>\n"
            . "\t\t\t<key>PayloadType</key>\n\t\t\t<string>com.apple.webClip.managed</string>\n"
            . "\t\t\t<key>PayloadUUID</key>\n\t\t\t<string>" . self::uuid($seed, 'webclip') . "</string>\n"
            . "\t\t\t<key>PayloadVersion</key>\n\t\t\t<integer>1</integer>\n"
            . "\t\t\t<key>Precomposed</key>\n\t\t\t<true/>\n"
            . "\t\t\t<key>URL</key>\n\t\t\t<string>" . $x($url) . "</string>\n"
            . "\t\t</dict>\n\t</array>\n"
            . "\t<key>PayloadDescription</key>\n\t<string>Training kiosk Web Clip for fleet link " . $x($linkLabel) . ".</string>\n"
            . "\t<key>PayloadDisplayName</key>\n\t<string>Training kiosk - " . $x($linkLabel) . "</string>\n"
            . "\t<key>PayloadIdentifier</key>\n\t<string>" . $x($idBase) . "</string>\n"
            . "\t<key>PayloadOrganization</key>\n\t<string>" . $x($host) . "</string>\n"
            . "\t<key>PayloadRemovalDisallowed</key>\n\t<true/>\n"
            . "\t<key>PayloadType</key>\n\t<string>Configuration</string>\n"
            . "\t<key>PayloadUUID</key>\n\t<string>" . self::uuid($seed, 'profile') . "</string>\n"
            . "\t<key>PayloadVersion</key>\n\t<integer>1</integer>\n"
            . "</dict>\n</plist>\n";
    }

    /** An RFC 4122 version-5-shaped UUID (upper case, as Apple writes them) derived from a seed and a role. */
    public static function uuid(string $seed, string $role): string
    {
        $h = substr(hash('sha256', 'rivetit-kiosk-fleet|' . $role . '|' . $seed), 0, 32);
        $h[12] = '5';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);
        return strtoupper(substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12));
    }

    // ------------------------------------------------------------------------------------------
    // Per-device URL export

    /**
     * One row per device-type asset of the link's department that has a usable serial: the fleet URL with THAT
     * serial filled in, for an MDM that cannot expand a macro. Needs the once-shown token.
     *
     * @return list<array{name:string, serial:string, type:string, url:string}>
     */
    public function exportRows(int $fleetId, mixed $token): array
    {
        $l = $this->linkForToken($fleetId, $token);
        $types = KioskSettings::ASSET_TYPES;
        $rows = Db::all($this->c->db, 'SELECT asset_name, asset_serial, asset_type FROM assets WHERE asset_client_id = ? AND asset_archived_at IS NULL
            AND asset_serial IS NOT NULL AND asset_serial <> \'\' AND asset_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')
            ORDER BY asset_name, asset_id LIMIT 2000', 'i' . str_repeat('s', count($types)), array_merge([(int) $l['fleet_client_id']], $types));
        $out = [];
        foreach ($rows as $r) {
            $sn = trim((string) $r['asset_serial']);
            if (preg_match(self::SERIAL_RE, $sn) !== 1) {
                continue;   // a serial the kiosk would refuse (spaces, symbols) is left out rather than exported broken
            }
            $out[] = ['name' => (string) $r['asset_name'], 'serial' => $sn, 'type' => (string) $r['asset_type'], 'url' => $this->url((string) $token, $sn)];
        }
        $this->audit('training.kiosk_fleet_export', $fleetId, 'fleet_export', 'Exported per-device URLs for training fleet link "' . $l['fleet_label'] . '"', ['rows' => count($out)]);
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Pending devices

    /** Devices waiting for approval (method fleet, status pending) in the user's scope, oldest first. */
    public function pending(): array
    {
        $db = $this->c->db;
        $scope = Seam::scopeClientIds($this->c);
        if ($scope === []) {
            return [];
        }
        $sql = "SELECT k.kiosk_id, k.kiosk_label, k.kiosk_asset_id, k.kiosk_fleet_id, k.kiosk_fleet_serial, k.kiosk_fleet_note, k.kiosk_default_client_id,
                k.kiosk_created_at, k.kiosk_last_user_agent, a.asset_name, a.asset_serial, a.asset_type, cl.client_name, f.fleet_label
            FROM training_kiosks k
            LEFT JOIN assets a ON a.asset_id = k.kiosk_asset_id
            LEFT JOIN clients cl ON cl.client_id = k.kiosk_default_client_id
            LEFT JOIN training_fleet_links f ON f.fleet_id = k.kiosk_fleet_id
            WHERE k.kiosk_enroll_method = 'fleet' AND k.kiosk_status = 'pending' AND k.kiosk_hidden_at_utc IS NULL";
        $types = '';
        $params = [];
        if ($scope !== null) {
            $sql .= ' AND k.kiosk_default_client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types = str_repeat('i', count($scope));
            $params = $scope;
        }
        $sql .= ' ORDER BY k.kiosk_id LIMIT 500';
        $out = [];
        foreach (Db::all($db, $sql, $types, $params) as $r) {
            $out[] = [
                'id' => (int) $r['kiosk_id'],
                'label' => (string) $r['kiosk_label'],
                'serial' => (string) ($r['kiosk_fleet_serial'] ?? ''),
                'note' => (string) ($r['kiosk_fleet_note'] ?? ''),
                'department' => (string) ($r['client_name'] ?? ''),
                'link' => (string) ($r['fleet_label'] ?? ''),
                'asset' => $r['kiosk_asset_id'] === null ? null : ['id' => (int) $r['kiosk_asset_id'], 'name' => (string) ($r['asset_name'] ?? ''), 'type' => (string) ($r['asset_type'] ?? '')],
                'requested_at' => self::iso($r['kiosk_created_at']),
                'ua' => (string) ($r['kiosk_last_user_agent'] ?? ''),
            ];
        }
        return $out;
    }

    /** How many fleet devices wait for approval in the user's scope. */
    public function pendingCount(): int
    {
        return count($this->pending());
    }

    /**
     * Approve a pending fleet device. $assetId binds an Asset (default: the one the serial already matched);
     * with neither it becomes an unlisted device named $label. An asset that already has another active
     * training device takes over from it (that one is revoked "re-enrolled"). Returns ['kiosk_id','label','unlisted'].
     */
    public function approve(int $kioskId, ?int $assetId, string $label): array
    {
        $db = $this->c->db;
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            throw ApiException::validation(['label' => 'Use up to ' . self::LABEL_MAX . ' characters.']);
        }
        $base = $this->eventBase();
        $r = Db::tx($db, function () use ($db, $kioskId, $assetId, $label, $base): array {
            $k = $this->lockPending($kioskId);
            $assetId ??= $k['kiosk_asset_id'] === null ? null : (int) $k['kiosk_asset_id'];
            $asset = null;
            $personal = null;
            if ($assetId !== null) {
                $asset = Db::one($db, 'SELECT asset_id, asset_name, asset_type, asset_serial, asset_archived_at, asset_contact_id FROM assets WHERE asset_id = ? FOR UPDATE', 'i', [$assetId]);
                if ($asset === null || $asset['asset_archived_at'] !== null) {
                    throw ApiException::notFound('That asset was not found.');
                }
                if (!in_array((string) $asset['asset_type'], KioskSettings::ASSET_TYPES, true)) {
                    throw ApiException::validation(['asset_id' => 'Only tablets, phones, laptops and desktops can be training devices.']);
                }
                foreach (Db::all($db, "SELECT kiosk_id FROM training_kiosks WHERE kiosk_asset_id = ? AND kiosk_id <> ? AND kiosk_status IN ('active','pending') FOR UPDATE", 'ii', [$assetId, $kioskId]) as $o) {
                    DeviceLifecycle::revokeLocked($db, (int) $o['kiosk_id'], 're-enrolled', $this->c->userId, $base);
                }
                $personal = $this->personalFor($asset);
            }
            $name = $label !== '' ? $label : ($asset !== null ? trim((string) $asset['asset_name']) : (string) $k['kiosk_label']);
            $name = mb_substr($name === '' ? (string) $k['kiosk_label'] : $name, 0, self::LABEL_MAX, 'UTF-8');
            $now = KTime::now();
            Db::exec($db, "UPDATE training_kiosks SET kiosk_status = 'active', kiosk_label = ?, kiosk_asset_id = ?, kiosk_asset_type = ?, kiosk_asset_serial = ?,
                    kiosk_personal_contact_id = ?, kiosk_enrolled_at_utc = ?, kiosk_enrolled_by = ?, kiosk_fleet_note = 'approved' WHERE kiosk_id = ?",
                'sissisii', [$name, $assetId, $asset === null ? null : (string) $asset['asset_type'], $asset['asset_serial'] ?? null, $personal['id'] ?? null, $now, $this->c->userId, $kioskId]);
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.enrolled', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId,
                'payload' => ['asset_id' => $assetId, 'asset_type' => $asset === null ? null : (string) $asset['asset_type'], 'label' => $name, 'method' => 'fleet_approved',
                              'personal' => $personal !== null, 'unlisted' => $assetId === null, 'expires_at_utc' => null, 'fleet_id' => (int) $k['kiosk_fleet_id']],
            ]));
            return ['kiosk_id' => $kioskId, 'label' => $name, 'unlisted' => $assetId === null, 'asset_id' => $assetId];
        });
        $this->audit('training.kiosk_fleet_approved', $kioskId, 'fleet_approve', 'Approved training device "' . $r['label'] . '" from a fleet link', ['asset_id' => $r['asset_id']], 'training_kiosk');
        return ['kiosk_id' => $r['kiosk_id'], 'label' => $r['label'], 'unlisted' => $r['unlisted']];
    }

    /** Reject a pending fleet device: the row is revoked, so the token the tablet holds stops working. */
    public function reject(int $kioskId): void
    {
        $db = $this->c->db;
        $base = $this->eventBase();
        $label = Db::tx($db, function () use ($db, $kioskId, $base): string {
            $k = $this->lockPending($kioskId);
            DeviceLifecycle::revokeLocked($db, $kioskId, 'Fleet enrollment rejected', $this->c->userId, $base);
            return (string) $k['kiosk_label'];
        });
        $this->audit('training.kiosk_fleet_rejected', $kioskId, 'fleet_reject', 'Rejected training device "' . $label . '" from a fleet link', [], 'training_kiosk');
    }

    // ------------------------------------------------------------------------------------------

    private function lockPending(int $kioskId): array
    {
        $k = Db::one($this->c->db, "SELECT kiosk_id, kiosk_label, kiosk_status, kiosk_enroll_method, kiosk_asset_id, kiosk_fleet_id, kiosk_default_client_id
            FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE", 'i', [$kioskId]);
        if ($k === null || $k['kiosk_enroll_method'] !== 'fleet' || !$this->inScope((int) $k['kiosk_default_client_id'])) {
            throw ApiException::notFound('That device was not found.');
        }
        if ($k['kiosk_status'] !== 'pending') {
            throw new ApiException(409, 'validation', 'That device is not waiting for approval.');
        }
        return $k;
    }

    /** The link row (404 outside the user's scope), unlocked. */
    private function link(int $fleetId): array
    {
        $l = Db::one($this->c->db, 'SELECT * FROM training_fleet_links WHERE fleet_id = ?', 'i', [$fleetId]);
        if ($l === null || !$this->inScope((int) $l['fleet_client_id'])) {
            throw ApiException::notFound('That link was not found.');
        }
        return $l;
    }

    private function lockLink(int $fleetId): array
    {
        $l = Db::one($this->c->db, 'SELECT * FROM training_fleet_links WHERE fleet_id = ? FOR UPDATE', 'i', [$fleetId]);
        if ($l === null || !$this->inScope((int) $l['fleet_client_id'])) {
            throw ApiException::notFound('That link was not found.');
        }
        return $l;
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

    private function eventBase(): array
    {
        return ['actor_type' => 'user', 'actor_user_id' => $this->c->userId, 'user_agent' => $this->c->userAgent];
    }

    private static function iso(?string $utc): ?string
    {
        return ($utc === null || $utc === '') ? null : str_replace(' ', 'T', substr($utc, 0, 19)) . 'Z';
    }

    private function audit(string $event, int $entityId, string $action, string $summary, array $meta, string $entityType = 'training_fleet_link'): void
    {
        try {
            (new AuditService($this->c->db))->log($event, $this->c->userId, $entityType, $entityId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training kiosk audit: ' . get_class($e));
        }
        if (function_exists('logAction')) {
            try {
                logAction('Training', 'Device', $summary, 0, $entityType === 'training_kiosk' ? $entityId : 0);
            } catch (\Throwable $e) {
                error_log('Training kiosk logAction: ' . get_class($e));
            }
        }
    }
}
