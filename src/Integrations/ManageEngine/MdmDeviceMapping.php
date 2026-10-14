<?php

declare(strict_types=1);

namespace ITFlow\Integrations\ManageEngine;

/**
 * The manual device -> asset mapping screen's actions (Endpoints > MDM Devices > Mapping): link, re-link, unlink, ignore, restore, for one
 * device or many. It only changes RivetIT's own link tables; nothing is sent to ManageEngine and no asset field is written.
 *
 * A device is addressed by a token: "l<id>" is a row of asset_mdm_links (linked), "u<id>" a row of mdm_unmatched_devices (unmatched or ignored).
 *
 * Access: the CALLER has already refused a department-scoped non-admin (mdmRequireUnscopedUser) and checked the permission level. This class
 * additionally asks $canAccessClient(clientId) before touching an asset, for EVERY asset a row would read or change (the asset a device is
 * linked to now, and the asset it is being linked to), so a rule that the caller forgot still holds here.
 *
 * One asset can carry only one MDM link per integration. Linking to an asset that already has another device is refused with an
 * explanation (code 'conflict') unless $replace is true: the other device then goes back to the review list (reason 'unlinked').
 */
final class MdmDeviceMapping
{
    public const MAX_ROWS = 500;

    /** @param callable(int):bool $canAccessClient @param ?callable(string,string,int,int):void $audit (action, text, clientId, assetId) */
    public function __construct(private \mysqli $db, private int $integrationId, private $canAccessClient, private $audit = null)
    {
    }

    /**
     * @param list<string> $tokens
     * @param array<string,int|string> $assetFor token => asset id (link only)
     * @return array{results:list<array{token:string,ok:bool,code:string,message:string}>,ok:int,failed:int}
     */
    public function run(string $action, array $tokens, array $assetFor = [], bool $replace = false): array
    {
        $out = ['results' => [], 'ok' => 0, 'failed' => 0];
        if (!in_array($action, ['link', 'unlink', 'ignore', 'restore'], true)) {
            return ['results' => [['token' => '', 'ok' => false, 'code' => 'bad_action', 'message' => 'Unknown action.']], 'ok' => 0, 'failed' => 1];
        }
        $tokens = array_slice(array_values(array_unique(array_map('strval', $tokens))), 0, self::MAX_ROWS);
        foreach ($tokens as $t) {
            try {
                $r = match ($action) {
                    'link' => $this->link($t, (int) ($assetFor[$t] ?? 0), $replace),
                    'unlink' => $this->unlink($t),
                    'ignore' => $this->setDismissed($t, 1),
                    'restore' => $this->setDismissed($t, 0),
                };
            } catch (\Throwable $e) {
                $r = $this->fail('error', 'Could not change this device: ' . $e->getMessage());
            }
            $out['results'][] = ['token' => $t] + $r;
            $r['ok'] ? $out['ok']++ : $out['failed']++;
        }

        return $out;
    }

    /** @return array{ok:bool,code:string,message:string} */
    private function link(string $token, int $assetId, bool $replace): array
    {
        [$kind, $id] = $this->parse($token);
        if ($kind === '' || $assetId < 1) {
            return $this->fail('no_asset', 'Choose an asset to link.');
        }
        $asset = $this->asset($assetId);
        if (!$asset) {
            return $this->fail('no_asset', 'That asset does not exist or is archived.');
        }
        if (!($this->canAccessClient)((int) $asset['asset_client_id'])) {
            return $this->fail('denied', 'You do not have access to that asset\'s department.');
        }
        $iid = $this->integrationId;
        $device = $kind === 'u' ? $this->row('mdm_unmatched_devices', $id) : $this->row('asset_mdm_links', $id);
        if (!$device) {
            return $this->fail('gone', 'That device is no longer listed. Reload the page.');
        }
        if ($kind === 'l') {
            if ((int) $device['asset_id'] === $assetId) {
                return $this->fail('same', 'Already linked to that asset.');
            }
            $cur = $this->asset((int) $device['asset_id'], true);
            if ($cur && !($this->canAccessClient)((int) $cur['asset_client_id'])) {
                return $this->fail('denied', 'You do not have access to the department of the asset this device is linked to now.');
            }
        }
        $other = $this->one("SELECT * FROM asset_mdm_links WHERE integration_id=$iid AND asset_id=$assetId AND mdm_device_id<>'" . $this->esc((string) $device['mdm_device_id']) . "' LIMIT 1");
        if ($other) {
            if (!$replace) {
                return $this->fail('conflict', 'Asset "' . $asset['asset_name'] . '" is already linked to device "' . ($other['device_name'] !== '' ? $other['device_name'] : $other['mdm_device_id'])
                    . '". An asset can have only one MDM link. Tick "Replace an existing link" to move it; that device then returns to the review list.');
            }
            $this->parkAsUnmatched($other, 'unlinked');
            $this->query('DELETE FROM asset_mdm_links WHERE id=' . (int) $other['id']);
            $this->audit('Unlink', "replaced the ManageEngine MDM link of asset #$assetId (another device was linked to it)", (int) $asset['asset_client_id'], $assetId);
        }
        if ($kind === 'u') {
            $res = (new MdmDeviceMapper($this->db, $iid))->linkUnmatched($id, $assetId);
            if (!$res['ok']) {
                return $this->fail('error', $res['message']);
            }
            $this->audit('Link', "linked a ManageEngine MDM device to asset #$assetId (mapping screen)", (int) $asset['asset_client_id'], $assetId);

            return ['ok' => true, 'code' => 'linked', 'message' => 'Linked.'];
        }
        $this->query("UPDATE asset_mdm_links SET asset_id=$assetId, match_method='manual' WHERE id=$id AND integration_id=$iid");
        $this->audit('Link', 'moved a ManageEngine MDM link from asset #' . (int) $device['asset_id'] . " to asset #$assetId (mapping screen)", (int) $asset['asset_client_id'], $assetId);

        return ['ok' => true, 'code' => 'relinked', 'message' => 'Re-linked.'];
    }

    /** @return array{ok:bool,code:string,message:string} */
    private function unlink(string $token): array
    {
        [$kind, $id] = $this->parse($token);
        if ($kind !== 'l') {
            return $this->fail('not_linked', 'This device is not linked to an asset.');
        }
        $link = $this->row('asset_mdm_links', $id);
        if (!$link) {
            return $this->fail('gone', 'That device is no longer listed. Reload the page.');
        }
        $asset = $this->asset((int) $link['asset_id'], true);
        $clientId = $asset ? (int) $asset['asset_client_id'] : 0;
        if (!($this->canAccessClient)($clientId)) {
            return $this->fail('denied', 'You do not have access to that asset\'s department.');
        }
        $this->parkAsUnmatched($link, 'unlinked');
        $this->query("DELETE FROM asset_mdm_links WHERE id=$id AND integration_id={$this->integrationId}");
        $this->audit('Unlink', 'removed the ManageEngine MDM link from asset #' . (int) $link['asset_id'] . ' (mapping screen)', $clientId, (int) $link['asset_id']);

        return ['ok' => true, 'code' => 'unlinked', 'message' => 'Unlinked. The next sync links it again if its serial still matches an asset.'];
    }

    /** @return array{ok:bool,code:string,message:string} */
    private function setDismissed(string $token, int $dismissed): array
    {
        [$kind, $id] = $this->parse($token);
        if ($kind === 'l') {
            return $this->fail('linked', 'This device is linked to an asset. Unlink it first.');
        }
        $row = $kind === 'u' ? $this->row('mdm_unmatched_devices', $id) : null;
        if (!$row) {
            return $this->fail('gone', 'That device is no longer listed. Reload the page.');
        }
        $this->query("UPDATE mdm_unmatched_devices SET dismissed=$dismissed WHERE id=$id AND integration_id={$this->integrationId}");
        $this->audit('Edit', ($dismissed ? 'ignored' : 'restored') . ' an unmatched ManageEngine MDM device (mapping screen)', 0, 0);

        return ['ok' => true, 'code' => $dismissed ? 'ignored' : 'restored', 'message' => $dismissed ? 'Ignored.' : 'Restored to the review list.'];
    }

    /** Keep a device that lost its link visible: copy the shared device columns into the review list. @param array<string,mixed> $link */
    private function parkAsUnmatched(array $link, string $reason): void
    {
        $d = [];
        foreach (MdmDeviceMapper::FIELDS as $col => $_type) {
            $d[$col] = $link[$col] ?? null;
        }
        $d['mdm_device_id'] = (string) $link['mdm_device_id'];
        $set = [];
        foreach ($d as $col => $v) {
            if ($col === 'mdm_device_id') {
                continue;
            }
            $set[] = "`$col`=" . ($v === null ? 'NULL' : "'" . $this->esc((string) $v) . "'");
        }
        $iid = $this->integrationId;
        $idEsc = $this->esc($d['mdm_device_id']);
        $sql = implode(', ', $set) . ", reason='" . $this->esc($reason) . "', candidate_asset_ids='', dismissed=0, last_seen_at=NOW()";
        $row = $this->one("SELECT id FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc' LIMIT 1");
        if ($row) {
            $this->query("UPDATE mdm_unmatched_devices SET $sql WHERE id=" . (int) $row['id']);
        } else {
            $this->query("INSERT INTO mdm_unmatched_devices SET integration_id=$iid, mdm_device_id='$idEsc', first_seen_at=NOW(), $sql");
        }
    }

    /** @return array{0:string,1:int} */
    private function parse(string $token): array
    {
        if (preg_match('/^([lu])(\d{1,10})$/', $token, $m) && (int) $m[2] > 0) {
            return [$m[1], (int) $m[2]];
        }

        return ['', 0];
    }

    /** @return array<string,mixed>|null */
    private function asset(int $id, bool $includeArchived = false): ?array
    {
        return $this->one("SELECT asset_id, asset_name, asset_client_id FROM assets WHERE asset_id=$id" . ($includeArchived ? '' : ' AND asset_archived_at IS NULL') . ' LIMIT 1');
    }

    /** @return array<string,mixed>|null */
    private function row(string $table, int $id): ?array
    {
        return $this->one("SELECT * FROM `$table` WHERE id=$id AND integration_id={$this->integrationId} LIMIT 1");
    }

    /** @return array{ok:bool,code:string,message:string} */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private function audit(string $action, string $text, int $clientId, int $assetId): void
    {
        if ($this->audit !== null) {
            ($this->audit)($action, $text, $clientId, $assetId);
        }
    }

    private function esc(string $v): string
    {
        return mysqli_real_escape_string($this->db, $v);
    }

    private function query(string $sql): void
    {
        if (mysqli_query($this->db, $sql) === false) {
            throw new \RuntimeException('Database error: ' . mysqli_error($this->db));
        }
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql): ?array
    {
        $r = mysqli_query($this->db, $sql);
        if ($r === false) {
            throw new \RuntimeException('Database error: ' . mysqli_error($this->db));
        }

        return mysqli_fetch_assoc($r) ?: null;
    }
}
