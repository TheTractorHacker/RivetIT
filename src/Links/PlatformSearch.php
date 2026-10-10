<?php

declare(strict_types=1);

namespace ITFlow\Links;

/**
 * The extra global-search sections: software, networks, services, vendor roles and "linked records". One implementation for the
 * search page, the live dropdown and the API, always filtered to the departments and modules of the asking role.
 */
final class PlatformSearch
{
    private const MAX_EXAMINED = 8;

    public function __construct(private \mysqli $db)
    {
    }

    /** @return list<array{id:int,name:string,detail:string,client_id:int,client_name:string,url:string}> */
    public function software(LinkActor $actor, string $term, int $limit = 5): array
    {
        if (!$actor->canRead('software')) {
            return [];
        }
        $like = $this->like($term);
        $scope = $this->scope($actor, 'software_client_id');

        return $this->rows("SELECT software_id AS id, software_name AS name, CONCAT_WS(' ', NULLIF(software_type, ''), NULLIF(software_version, '')) AS detail, software_client_id AS cid, client_name
            FROM software LEFT JOIN clients ON client_id = software_client_id
            WHERE software_archived_at IS NULL AND $scope AND (software_name LIKE '$like' OR software_type LIKE '$like' OR software_version LIKE '$like' OR software_description LIKE '$like')
            ORDER BY software_name LIMIT " . $this->cap($limit), 'software');
    }

    /** @return list<array{id:int,name:string,detail:string,client_id:int,client_name:string,url:string}> */
    public function networks(LinkActor $actor, string $term, int $limit = 5): array
    {
        if (!$actor->canRead('network')) {
            return [];
        }
        $like = $this->like($term);
        $scope = $this->scope($actor, 'network_client_id');

        return $this->rows("SELECT network_id AS id, network_name AS name, CONCAT_WS(' ', NULLIF(network_subnet, ''), NULLIF(network_vlan, '')) AS detail, network_client_id AS cid, client_name
            FROM networks LEFT JOIN clients ON client_id = network_client_id
            WHERE network_archived_at IS NULL AND $scope AND (network_name LIKE '$like' OR network LIKE '$like' OR network_subnet LIKE '$like' OR network_vlan LIKE '$like' OR network_gateway LIKE '$like' OR network_description LIKE '$like')
            ORDER BY network_name LIMIT " . $this->cap($limit), 'network');
    }

    /** @return list<array{id:int,name:string,detail:string,client_id:int,client_name:string,url:string}> */
    public function services(LinkActor $actor, string $term, int $limit = 5): array
    {
        if (!$actor->canRead('service')) {
            return [];
        }
        $like = $this->like($term);
        $scope = $this->scope($actor, 'service_client_id');

        return $this->rows("SELECT service_id AS id, service_name AS name, COALESCE(service_importance, '') AS detail, service_client_id AS cid, client_name
            FROM services LEFT JOIN clients ON client_id = service_client_id
            WHERE $scope AND (service_name LIKE '$like' OR service_description LIKE '$like' OR service_backup LIKE '$like' OR service_notes LIKE '$like')
            ORDER BY service_name LIMIT " . $this->cap($limit), 'service');
    }

    /**
     * Vendor ids that hold a role ("reseller", "support", "manufacturer") on any asset or software title, for a query that is a role word.
     *
     * @return list<int>
     */
    public function vendorIdsWithRole(string $term): array
    {
        $role = strtolower(trim($term));
        if (!in_array($role, EntityTypes::VENDOR_ROLES, true)) {
            return [];
        }
        $r = $this->db->real_escape_string($role);
        $ids = [];
        $res = $this->db->query("SELECT vendor_id FROM asset_vendors WHERE vendor_role = '$r' UNION SELECT vendor_id FROM software_vendors WHERE vendor_role = '$r' LIMIT 50");
        while ($res && ($x = $res->fetch_row())) {
            $ids[] = (int) $x[0];
        }

        return $ids;
    }

    /**
     * What each vendor is to the records: how many assets / software titles it supports, resells or makes (the primary vendor counts as support).
     *
     * @param list<int> $vendorIds
     * @return array<int,array{support:int,reseller:int,manufacturer:int}>
     */
    public function vendorRoleSummary(array $vendorIds): array
    {
        $vendorIds = array_values(array_unique(array_filter(array_map('intval', $vendorIds))));
        $out = [];
        foreach ($vendorIds as $v) {
            $out[$v] = ['support' => 0, 'reseller' => 0, 'manufacturer' => 0];
        }
        if (!$vendorIds) {
            return $out;
        }
        $in = implode(',', $vendorIds);
        foreach ([['asset_vendors', 'assets', 'asset_id', 'asset_vendor_id', 'asset_archived_at'], ['software_vendors', 'software', 'software_id', 'software_vendor_id', 'software_archived_at']] as [$roles, $table, $pk, $primary, $arch]) {
            // The role table already holds the mirrored primary; add primaries that are not mirrored yet, once per record.
            $res = $this->db->query("SELECT vendor_id, vendor_role, COUNT(*) AS n FROM (
                SELECT r.vendor_id, r.vendor_role, r.$pk AS rid FROM $roles r JOIN $table t ON t.$pk = r.$pk AND t.$arch IS NULL WHERE r.vendor_id IN ($in)
                UNION
                SELECT t.$primary, 'support', t.$pk FROM $table t WHERE t.$primary IN ($in) AND t.$arch IS NULL
            ) u GROUP BY vendor_id, vendor_role");
            while ($res && ($x = $res->fetch_assoc())) {
                $out[(int) $x['vendor_id']][(string) $x['vendor_role']] += (int) $x['n'];
            }
        }

        return $out;
    }

    /**
     * "Linked records": records that are linked to a record whose name matches the term, each tagged with what it is linked to
     * ("Runbook, linked to Asset srv-core"). Real links and the older link tables, filtered to what $actor may see.
     *
     * @return list<array{type:string,id:int,name:string,label:string,icon:string,url:string,client_id:int,linked_to:string,relation:string}>
     */
    public function linkedRecords(LinkActor $actor, string $term, int $limit = 10): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $svc = new LinkService($this->db);
        $like = $this->like($term);
        $out = [];
        $seen = [];
        $examined = 0;   // each matched record costs a few dozen small queries: look at a handful, not at every match of a short term
        foreach (EntityTypes::all() as $type => $spec) {
            if (!$actor->canRead($type) || $type === 'ticket') {
                continue;
            }
            if ($examined >= self::MAX_EXAMINED) {
                break;
            }
            $scope = $this->scope($actor, (string) $spec['client']);
            $archived = $spec['archived'] ? " AND {$spec['archived']} IS NULL" : '';
            $res = $this->db->query("SELECT {$spec['pk']} AS i FROM {$spec['table']} WHERE $scope$archived AND {$spec['name']} LIKE '$like' ORDER BY {$spec['name']} LIMIT 2");
            while ($res && ($m = $res->fetch_row())) {
                if (++$examined > self::MAX_EXAMINED) {
                    break 2;
                }
                $mid = (int) $m[0];
                $self = $svc->lookup($type, $mid);
                if ($self === null) {
                    continue;
                }
                $view = $svc->view($actor, $type, $mid, false);
                foreach (['outgoing' => true, 'incoming' => false] as $dir => $outgoing) {
                    foreach ($view[$dir] as $e) {
                        $o = $e['other'];
                        $key = $o['type'] . ':' . $o['id'];
                        if (isset($seen[$key . '>' . $type . ':' . $mid])) {
                            continue;
                        }
                        $seen[$key . '>' . $type . ':' . $mid] = true;
                        $out[] = ['type' => $o['type'], 'id' => $o['id'], 'name' => $o['name'], 'label' => $o['label'], 'icon' => $o['icon'], 'url' => $o['url'], 'client_id' => $o['client_id'],
                            'linked_to' => $self['label'] . ' ' . $self['name'],
                            // read from the found record's side: "Runbook documents Asset srv-core", "Payroll depends on Asset srv-core"
                            'relation' => $outgoing ? EntityTypes::reverseLabel($e['link_type']) : EntityTypes::linkTypeLabel($e['link_type'])];
                        if (count($out) >= $limit) {
                            return $out;
                        }
                    }
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------- helpers

    private function like(string $term): string
    {
        return '%' . $this->db->real_escape_string(addcslashes(trim($term), '%_\\')) . '%';
    }

    private function cap(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    /** A SQL fragment limiting a department column to what the actor may reach (department 0 is open to all). */
    private function scope(LinkActor $actor, string $col): string
    {
        $ids = $actor->restrictedClientIds();
        if ($ids === null) {
            return '1 = 1';
        }

        return '(' . $col . ' = 0' . ($ids ? " OR $col IN (" . implode(',', array_map('intval', $ids)) . ')' : '') . ')';
    }

    /**
     * @return list<array{id:int,name:string,detail:string,client_id:int,client_name:string,url:string}>
     */
    private function rows(string $sql, string $type): array
    {
        $out = [];
        $res = $this->db->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $cid = (int) $r['cid'];
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'detail' => trim((string) ($r['detail'] ?? '')), 'client_id' => $cid,
                'client_name' => (string) ($r['client_name'] ?? ''), 'url' => EntityTypes::pageUrl($type, (int) $r['id'], $cid, (string) $r['name'])];
        }

        return $out;
    }
}
