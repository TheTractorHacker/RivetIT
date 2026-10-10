<?php

declare(strict_types=1);

namespace ITFlow\Links;

use ITFlow\Audit\AuditService;

/**
 * Several vendors per asset and per software title, each with a role (support, reseller, manufacturer).
 *
 * assets.asset_vendor_id / software.software_vendor_id stay the PRIMARY vendor: every page and API field that reads them keeps
 * working, and the primary is mirrored into asset_vendors / software_vendors as role "support". Reads union the column and the
 * table, so a primary set by any path (import, API, an old page) is shown even before it is mirrored.
 */
final class VendorRoles
{
    private const KINDS = [
        'asset' => ['table' => 'assets', 'pk' => 'asset_id', 'primary' => 'asset_vendor_id', 'client' => 'asset_client_id', 'roles' => 'asset_vendors', 'rpk' => 'asset_id'],
        'software' => ['table' => 'software', 'pk' => 'software_id', 'primary' => 'software_vendor_id', 'client' => 'software_client_id', 'roles' => 'software_vendors', 'rpk' => 'software_id'],
    ];

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * @return list<array{vendor_id:int,name:string,roles:list<string>,primary:bool,client_id:int}>
     */
    public function forRecord(string $kind, int $id): array
    {
        $k = self::KINDS[$kind] ?? null;
        if ($k === null) {
            return [];
        }
        $byVendor = [];
        $res = $this->db->query("SELECT {$k['primary']} AS v FROM {$k['table']} WHERE {$k['pk']} = $id LIMIT 1");
        $primary = $res && ($r = $res->fetch_row()) ? (int) $r[0] : 0;
        if ($primary > 0) {
            $byVendor[$primary] = ['vendor_id' => $primary, 'roles' => ['support'], 'primary' => true];
        }
        $res = $this->db->query("SELECT vendor_id, vendor_role FROM {$k['roles']} WHERE {$k['rpk']} = $id ORDER BY created_at, vendor_role");
        while ($res && ($r = $res->fetch_assoc())) {
            $v = (int) $r['vendor_id'];
            $byVendor[$v] ??= ['vendor_id' => $v, 'roles' => [], 'primary' => false];
            if (!in_array($r['vendor_role'], $byVendor[$v]['roles'], true)) {
                $byVendor[$v]['roles'][] = (string) $r['vendor_role'];
            }
        }
        if (!$byVendor) {
            return [];
        }
        $res = $this->db->query('SELECT vendor_id, vendor_name, vendor_client_id FROM vendors WHERE vendor_id IN (' . implode(',', array_keys($byVendor)) . ')');
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $v = $byVendor[(int) $r['vendor_id']];
            $out[] = $v + ['name' => (string) $r['vendor_name'], 'client_id' => (int) $r['vendor_client_id']];
        }
        usort($out, static fn (array $a, array $b): int => [$b['primary'], $a['name']] <=> [$a['primary'], $b['name']]);

        return $out;
    }

    /**
     * Keep the mirror in step with the primary column. Call after the primary vendor of a record is set or changed.
     * A replaced primary loses its mirrored "support" row (it is no longer the primary); an explicit extra role stays.
     */
    public function mirrorPrimary(string $kind, int $id, int $oldVendorId = 0): void
    {
        $k = self::KINDS[$kind] ?? null;
        if ($k === null) {
            return;
        }
        $res = $this->db->query("SELECT {$k['primary']} FROM {$k['table']} WHERE {$k['pk']} = $id LIMIT 1");
        $new = $res && ($r = $res->fetch_row()) ? (int) $r[0] : 0;
        if ($oldVendorId > 0 && $oldVendorId !== $new) {
            $this->db->query("DELETE FROM {$k['roles']} WHERE {$k['rpk']} = $id AND vendor_id = $oldVendorId AND vendor_role = 'support'");
        }
        if ($new > 0) {
            $this->db->query("INSERT IGNORE INTO {$k['roles']} ({$k['rpk']}, vendor_id, vendor_role) VALUES ($id, $new, 'support')");
        }
    }

    /** @return array{ok:true}|array{ok:false,error:string,message:string} */
    public function add(LinkActor $actor, string $kind, int $id, int $vendorId, string $role): array
    {
        $chk = $this->check($actor, $kind, $id, $vendorId, $role);
        if (!$chk['ok']) {
            return $chk;
        }
        $k = self::KINDS[$kind];
        $r = $this->db->real_escape_string($role);
        $this->db->query("INSERT IGNORE INTO {$k['roles']} ({$k['rpk']}, vendor_id, vendor_role) VALUES ($id, $vendorId, '$r')");
        if ($this->db->affected_rows < 1) {
            return ['ok' => false, 'error' => 'exists', 'message' => 'That vendor already has that role.'];
        }
        $this->audit($actor, 'vendor_role.add', $kind, $id, $vendorId, $role, "Added vendor {$chk['vendor']} as $role for {$chk['name']}");

        return ['ok' => true];
    }

    /** @return array{ok:true}|array{ok:false,error:string,message:string} */
    public function remove(LinkActor $actor, string $kind, int $id, int $vendorId, string $role): array
    {
        $chk = $this->check($actor, $kind, $id, $vendorId, $role);
        if (!$chk['ok']) {
            return $chk;
        }
        $k = self::KINDS[$kind];
        // The primary vendor is changed on the record's own edit form, not here: removing its mirror would be undone on the next read.
        $res = $this->db->query("SELECT {$k['primary']} FROM {$k['table']} WHERE {$k['pk']} = $id LIMIT 1");
        $primary = $res && ($row = $res->fetch_row()) ? (int) $row[0] : 0;
        if ($primary === $vendorId && $role === 'support') {
            return ['ok' => false, 'error' => 'primary', 'message' => 'That is the primary vendor. Change it on the record itself.'];
        }
        $r = $this->db->real_escape_string($role);
        $this->db->query("DELETE FROM {$k['roles']} WHERE {$k['rpk']} = $id AND vendor_id = $vendorId AND vendor_role = '$r'");
        $this->audit($actor, 'vendor_role.remove', $kind, $id, $vendorId, $role, "Removed vendor {$chk['vendor']} as $role from {$chk['name']}");

        return ['ok' => true];
    }

    /** @return array{ok:true,name:string,vendor:string}|array{ok:false,error:string,message:string} */
    private function check(LinkActor $actor, string $kind, int $id, int $vendorId, string $role): array
    {
        $k = self::KINDS[$kind] ?? null;
        if ($k === null || !in_array($role, EntityTypes::VENDOR_ROLES, true)) {
            return ['ok' => false, 'error' => 'bad_type', 'message' => 'Unknown record type or vendor role.'];
        }
        $svc = new LinkService($this->db);
        $rec = $svc->lookup($kind, $id);
        $ven = $svc->lookup('vendor', $vendorId);
        if ($rec === null || $ven === null) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'The record or the vendor no longer exists.'];
        }
        if (!$actor->canWrite($kind) || !$actor->canRead('vendor')) {
            return ['ok' => false, 'error' => 'forbidden', 'message' => 'Your role cannot change this.'];
        }
        if (!$actor->canAccessClient($rec['client_id']) || !$actor->canAccessClient($ven['client_id'])) {
            return ['ok' => false, 'error' => 'forbidden', 'message' => 'You do not have access to that department.'];
        }
        if ($ven['client_id'] > 0 && $rec['client_id'] > 0 && $ven['client_id'] !== $rec['client_id']) {
            return ['ok' => false, 'error' => 'cross_client', 'message' => 'The vendor belongs to another department.'];
        }

        return ['ok' => true, 'name' => $rec['name'], 'vendor' => $ven['name']];
    }

    private function audit(LinkActor $actor, string $event, string $kind, int $id, int $vendorId, string $role, string $summary): void
    {
        try {
            (new AuditService($this->db))->log($event, $actor->userId > 0 ? $actor->userId : null, $kind, $id, str_contains($event, 'add') ? 'create' : 'delete', $summary, ['vendor_id' => $vendorId, 'role' => $role]);
        } catch (\Throwable $e) {
            // auditing never breaks the action
        }
    }
}
