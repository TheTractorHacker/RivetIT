<?php

declare(strict_types=1);

namespace ITFlow\Links;

use ITFlow\Audit\AuditService;

/**
 * Generic record-to-record links (entity_links) and the views built on them:
 *  - create / delete with department scoping, module permission checks and audit rows;
 *  - outgoing links, "referenced by" (reverse lookups) and the transitive "what depends on this" impact walk (depth 3, cycle safe);
 *  - read-only DERIVED rows from the older link tables (asset_documents, software_assets, service_assets, ...), unioned in at query time.
 *
 * Scoping rule: both ends must be in the same department, except that a record kept in department 0 (a global vendor, a global KB
 * article) may be linked to anything. The link row carries the department of its scoped end.
 */
final class LinkService
{
    public function __construct(private \mysqli $db)
    {
    }

    // -------------------------------------------------------------------------------------------------------------- lookup

    /**
     * @param list<array{0:string,1:int}> $refs
     * @return array<string,array{type:string,id:int,name:string,client_id:int,archived:bool,url:string,label:string,icon:string}> keyed "type:id"
     */
    public function resolveMany(array $refs): array
    {
        $byType = [];
        foreach ($refs as [$type, $id]) {
            if (EntityTypes::isType($type) && $id > 0) {
                $byType[$type][$id] = $id;
            }
        }
        $out = [];
        foreach ($byType as $type => $ids) {
            $spec = EntityTypes::spec($type);
            $archived = $spec['archived'] ? ", ({$spec['archived']} IS NOT NULL) AS a" : ', 0 AS a';
            $res = $this->db->query("SELECT {$spec['pk']} AS i, {$spec['name']} AS n, {$spec['client']} AS c$archived FROM {$spec['table']} WHERE {$spec['pk']} IN (" . implode(',', array_map('intval', $ids)) . ')');
            while ($res && ($r = $res->fetch_assoc())) {
                $id = (int) $r['i'];
                $client = (int) $r['c'];
                $out["$type:$id"] = ['type' => $type, 'id' => $id, 'name' => (string) $r['n'], 'client_id' => $client, 'archived' => (bool) (int) $r['a'],
                    'url' => EntityTypes::pageUrl($type, $id, $client, (string) $r['n']), 'label' => (string) $spec['label'], 'icon' => (string) $spec['icon']];
            }
        }

        return $out;
    }

    /** @return array{type:string,id:int,name:string,client_id:int,archived:bool,url:string,label:string,icon:string}|null */
    public function lookup(string $type, int $id): ?array
    {
        return $this->resolveMany([[$type, $id]])["$type:$id"] ?? null;
    }

    // ------------------------------------------------------------------------------------------------------------ create

    /**
     * @return array{ok:true,link_id:int}|array{ok:false,error:string,message:string}
     */
    public function create(LinkActor $actor, string $srcType, int $srcId, string $dstType, int $dstId, string $linkType, string $note = ''): array
    {
        if (!EntityTypes::isType($srcType) || !EntityTypes::isType($dstType)) {
            return self::fail('bad_type', 'Unknown record type.');
        }
        if (!EntityTypes::isLinkType($linkType)) {
            return self::fail('bad_link_type', 'Unknown relationship type.');
        }
        if ($srcType === $dstType && $srcId === $dstId) {
            return self::fail('self_link', 'A record cannot be linked to itself.');
        }
        $src = $this->lookup($srcType, $srcId);
        $dst = $this->lookup($dstType, $dstId);
        if ($src === null || $dst === null) {
            return self::fail('not_found', 'One of the records no longer exists.');
        }
        // Permissions first (so the answer does not reveal whether a record in another department exists), then scope.
        if (!$actor->canWrite($srcType)) {
            return self::fail('forbidden', 'Your role cannot change ' . strtolower((string) $src['label']) . ' records.');
        }
        if (!$actor->canRead($dstType)) {
            return self::fail('forbidden', 'Your role cannot see ' . strtolower((string) $dst['label']) . ' records.');
        }
        if (!$actor->canAccessClient($src['client_id']) || !$actor->canAccessClient($dst['client_id'])) {
            return self::fail('forbidden', 'You do not have access to that department.');
        }
        if ($src['client_id'] > 0 && $dst['client_id'] > 0 && $src['client_id'] !== $dst['client_id']) {
            return self::fail('cross_client', 'Both records must belong to the same department (only global records, such as a vendor or KB article kept in no department, can be linked across departments).');
        }
        $client = $src['client_id'] > 0 ? $src['client_id'] : $dst['client_id'];
        $note = mb_substr(trim(strip_tags($note)), 0, 500);

        $stmt = $this->db->prepare('INSERT IGNORE INTO entity_links (client_id, src_type, src_id, dst_type, dst_id, link_type, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $noteOrNull = $note === '' ? null : $note;
        $by = $actor->userId;
        $stmt->bind_param('isisissi', $client, $srcType, $srcId, $dstType, $dstId, $linkType, $noteOrNull, $by);
        $stmt->execute();
        $linkId = (int) $this->db->insert_id;
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1 || $linkId < 1) {
            return self::fail('exists', 'Those records are already linked that way.');
        }
        $this->audit($actor, 'entity_link.create', $linkId, 'create',
            sprintf('Linked %s "%s" %s %s "%s"', $src['label'], $src['name'], EntityTypes::linkTypeLabel($linkType), $dst['label'], $dst['name']),
            ['client_id' => $client, 'src_type' => $srcType, 'src_id' => $srcId, 'dst_type' => $dstType, 'dst_id' => $dstId, 'link_type' => $linkType]);

        return ['ok' => true, 'link_id' => $linkId];
    }

    // ------------------------------------------------------------------------------------------------------------ delete

    /** @return array{ok:true}|array{ok:false,error:string,message:string} */
    public function delete(LinkActor $actor, int $linkId): array
    {
        $res = $this->db->query('SELECT * FROM entity_links WHERE link_id = ' . $linkId . ' LIMIT 1');
        $link = $res ? $res->fetch_assoc() : null;
        if (!$link) {
            return self::fail('not_found', 'That link no longer exists.');
        }
        $client = (int) $link['client_id'];
        if (!$actor->canAccessClient($client)) {
            return self::fail('forbidden', 'You do not have access to that department.');
        }
        // Either end's module is enough (a KB editor may remove the KB side of a link to an asset).
        if (!$actor->canWrite((string) $link['src_type']) && !$actor->canWrite((string) $link['dst_type'])) {
            return self::fail('forbidden', 'Your role cannot change either of those records.');
        }
        $names = $this->resolveMany([[(string) $link['src_type'], (int) $link['src_id']], [(string) $link['dst_type'], (int) $link['dst_id']]]);
        $this->db->query('DELETE FROM entity_links WHERE link_id = ' . $linkId);
        $s = $names[$link['src_type'] . ':' . $link['src_id']] ?? null;
        $d = $names[$link['dst_type'] . ':' . $link['dst_id']] ?? null;
        $this->audit($actor, 'entity_link.delete', $linkId, 'delete',
            sprintf('Unlinked %s "%s" %s %s "%s"', $s['label'] ?? $link['src_type'], $s['name'] ?? $link['src_id'], EntityTypes::linkTypeLabel((string) $link['link_type']), $d['label'] ?? $link['dst_type'], $d['name'] ?? $link['dst_id']),
            ['client_id' => $client, 'src_type' => $link['src_type'], 'src_id' => (int) $link['src_id'], 'dst_type' => $link['dst_type'], 'dst_id' => (int) $link['dst_id'], 'link_type' => $link['link_type']]);

        return ['ok' => true];
    }

    /** Remove every link that mentions a record that is being deleted (call from the delete handlers; archive keeps links). */
    public function purgeFor(string $type, int $id): int
    {
        if (!EntityTypes::isType($type)) {
            return 0;
        }
        $t = $this->db->real_escape_string($type);
        $this->db->query("DELETE FROM entity_links WHERE (src_type = '$t' AND src_id = $id) OR (dst_type = '$t' AND dst_id = $id)");

        return max(0, $this->db->affected_rows);
    }

    // ------------------------------------------------------------------------------------------------------------- edges

    /**
     * Every edge touching the record, real and derived.
     *
     * @param 'out'|'in' $direction out: the record is the source; in: the record is the target ("referenced by")
     * @return list<array{src_type:string,src_id:int,dst_type:string,dst_id:int,link_type:string,derived:bool,link_id:int,note:?string,created_at:?string,source:string}>
     */
    public function edges(string $type, int $id, string $direction, bool $withDerived = true): array
    {
        if (!EntityTypes::isType($type)) {
            return [];
        }
        $t = $this->db->real_escape_string($type);
        $out = [];
        $sql = $direction === 'out'
            ? "SELECT * FROM entity_links WHERE src_type = '$t' AND src_id = $id ORDER BY link_id"
            : "SELECT * FROM entity_links WHERE dst_type = '$t' AND dst_id = $id ORDER BY link_id";
        $res = $this->db->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = ['src_type' => (string) $r['src_type'], 'src_id' => (int) $r['src_id'], 'dst_type' => (string) $r['dst_type'], 'dst_id' => (int) $r['dst_id'], 'link_type' => (string) $r['link_type'],
                'derived' => false, 'link_id' => (int) $r['link_id'], 'note' => $r['note'] === null ? null : (string) $r['note'], 'created_at' => (string) $r['created_at'], 'source' => 'Link'];
        }
        if ($withDerived) {
            foreach ($this->derivedEdges($type, $id, $direction) as $e) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function derivedEdges(string $type, int $id, string $direction): array
    {
        $out = [];
        $seen = [];
        foreach (EntityTypes::derivedMaps() as $m) {
            $mine = $direction === 'out' ? $m['left'] : $m['right'];
            if ($mine !== $type) {
                continue;
            }
            $myCol = $direction === 'out' ? $m['lcol'] : $m['rcol'];
            $otherCol = $direction === 'out' ? $m['rcol'] : $m['lcol'];
            $otherType = $direction === 'out' ? $m['right'] : $m['left'];
            // fk maps: the left record is identified by the table's own id column and the right one by the pointer column
            $where = isset($m['where']) ? ' AND ' . $m['where'] : '';
            $res = $this->db->query("SELECT DISTINCT $otherCol AS o FROM {$m['table']} WHERE $myCol = $id$where");
            while ($res && ($r = $res->fetch_assoc())) {
                $other = (int) $r['o'];
                if ($other < 1) {
                    continue;
                }
                $key = "{$m['link_type']}|$otherType|$other";
                if (isset($seen[$key])) {
                    continue;   // the same relationship via two tables (a primary vendor and its role mirror) is one row
                }
                $seen[$key] = true;
                $out[] = $direction === 'out'
                    ? ['src_type' => $type, 'src_id' => $id, 'dst_type' => $otherType, 'dst_id' => $other, 'link_type' => $m['link_type'], 'derived' => true, 'link_id' => 0, 'note' => null, 'created_at' => null, 'source' => $m['label']]
                    : ['src_type' => $otherType, 'src_id' => $other, 'dst_type' => $type, 'dst_id' => $id, 'link_type' => $m['link_type'], 'derived' => true, 'link_id' => 0, 'note' => null, 'created_at' => null, 'source' => $m['label']];
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------------------------------------------- view

    /**
     * What the Relationships card shows: outgoing links, "referenced by" and the impact list, filtered to what $actor may see.
     *
     * @return array{outgoing:list<array<string,mixed>>,incoming:list<array<string,mixed>>,impact:list<array<string,mixed>>,can_write:bool}
     */
    public function view(LinkActor $actor, string $type, int $id, bool $withImpact = true): array
    {
        $self = $this->lookup($type, $id);
        if ($self === null || !$actor->canRead($type) || !$actor->canAccessClient($self['client_id'])) {
            return ['outgoing' => [], 'incoming' => [], 'impact' => [], 'can_write' => false];
        }
        $out = $this->edges($type, $id, 'out');
        $in = $this->edges($type, $id, 'in');
        $refs = [];
        foreach ($out as $e) {
            $refs[] = [$e['dst_type'], $e['dst_id']];
        }
        foreach ($in as $e) {
            $refs[] = [$e['src_type'], $e['src_id']];
        }
        $names = $this->resolveMany($refs);
        $shape = function (array $edges, bool $outgoing) use ($names, $actor): array {
            $rows = [];
            foreach ($edges as $e) {
                $t = $outgoing ? $e['dst_type'] : $e['src_type'];
                $i = $outgoing ? $e['dst_id'] : $e['src_id'];
                $n = $names["$t:$i"] ?? null;
                if ($n === null || !$actor->canRead($t) || !$actor->canAccessClient($n['client_id'])) {
                    continue;
                }
                $rows[] = $e + ['other' => $n, 'relation' => $outgoing ? EntityTypes::linkTypeLabel($e['link_type']) : EntityTypes::reverseLabel($e['link_type'])];
            }

            return $rows;
        };

        return [
            'outgoing' => $shape($out, true),
            'incoming' => $shape($in, false),
            'impact' => $withImpact ? $this->impact($actor, $type, $id) : [],
            'can_write' => $actor->canWrite($type),
        ];
    }

    /**
     * Everything that (transitively) depends on the record: breadth first over the incoming depends_on / runs_on / supported_by edges
     * (real and derived), up to $depth levels (max 3). Each record appears once, at its shallowest level, so a cycle ends the walk
     * instead of looping. Records $actor may not see are neither listed nor walked through.
     *
     * @return list<array{type:string,id:int,name:string,url:string,label:string,icon:string,client_id:int,depth:int,via:string,via_name:string}>
     */
    public function impact(LinkActor $actor, string $type, int $id, int $depth = EntityTypes::MAX_DEPTH): array
    {
        $depth = max(1, min(EntityTypes::MAX_DEPTH, $depth));
        $visited = ["$type:$id" => true];
        $frontier = [[$type, $id]];
        $found = [];
        $nameOf = [];
        for ($level = 1; $level <= $depth && $frontier; $level++) {
            $next = [];
            $candidates = [];
            foreach ($frontier as [$ft, $fi]) {
                foreach ($this->edges($ft, $fi, 'in') as $e) {
                    if (!in_array($e['link_type'], EntityTypes::IMPACT_TYPES, true)) {
                        continue;
                    }
                    $key = $e['src_type'] . ':' . $e['src_id'];
                    if (isset($visited[$key]) || isset($candidates[$key])) {
                        continue;
                    }
                    $candidates[$key] = ['edge' => $e, 'via' => "$ft:$fi"];
                }
            }
            if (!$candidates) {
                break;
            }
            $refs = [];
            foreach ($candidates as $c) {
                $refs[] = [$c['edge']['src_type'], $c['edge']['src_id']];
            }
            foreach ($frontier as $f) {
                $refs[] = $f;
            }
            $names = $this->resolveMany($refs) + $nameOf;
            $nameOf = $names;
            foreach ($candidates as $key => $c) {
                $visited[$key] = true;   // even a hidden record is marked, so it is not reconsidered through another path
                $n = $names[$key] ?? null;
                if ($n === null || !$actor->canRead($n['type']) || !$actor->canAccessClient($n['client_id'])) {
                    continue;
                }
                $via = $names[$c['via']] ?? null;
                $found[] = ['type' => $n['type'], 'id' => $n['id'], 'name' => $n['name'], 'url' => $n['url'], 'label' => $n['label'], 'icon' => $n['icon'], 'client_id' => $n['client_id'],
                    'depth' => $level, 'via' => $c['via'], 'via_name' => $via['name'] ?? ''];
                $next[] = [$n['type'], $n['id']];
            }
            $frontier = $next;
        }

        return $found;
    }

    // ------------------------------------------------------------------------------------------------------------ search

    /**
     * Records of $type the actor may link to (for the "Link item" picker), by name, most relevant first.
     * A source in a department offers that department's records plus global ones; a global source (department 0, e.g. a global KB
     * article) may be linked to a record of any department the actor can access.
     *
     * @return list<array{id:int,name:string,client_id:int,client_name:string}>
     */
    public function search(LinkActor $actor, string $type, string $term, int $clientId, int $limit = 20): array
    {
        $spec = EntityTypes::spec($type);
        if ($spec === null || !$actor->canRead($type)) {
            return [];
        }
        $like = '%' . $this->db->real_escape_string(addcslashes($term, '%_\\')) . '%';
        $col = $spec['client'];
        if ($clientId > 0) {
            $scope = "($col = $clientId OR $col = 0)";
        } else {
            $ids = $actor->restrictedClientIds();
            $scope = $ids === null ? '1 = 1' : '(' . $col . ' = 0' . ($ids ? " OR $col IN (" . implode(',', array_map('intval', $ids)) . ')' : '') . ')';
        }
        $archived = $spec['archived'] ? " AND {$spec['archived']} IS NULL" : '';
        $res = $this->db->query("SELECT {$spec['pk']} AS i, {$spec['name']} AS n, $col AS c, (SELECT client_name FROM clients WHERE clients.client_id = $col) AS cn FROM {$spec['table']}
            WHERE $scope$archived AND {$spec['name']} LIKE '$like' ORDER BY {$spec['name']} LIMIT " . max(1, min(50, $limit)));
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            if ($actor->canAccessClient((int) $r['c'])) {
                $out[] = ['id' => (int) $r['i'], 'name' => (string) $r['n'], 'client_id' => (int) $r['c'], 'client_name' => (string) ($r['cn'] ?? '')];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------- helpers

    /** @param array<string,mixed> $meta */
    private function audit(LinkActor $actor, string $event, int $linkId, string $action, string $summary, array $meta): void
    {
        try {
            (new AuditService($this->db))->log($event, $actor->userId > 0 ? $actor->userId : null, 'entity_link', $linkId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            // auditing never breaks the action
        }
    }

    /** @return array{ok:false,error:string,message:string} */
    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message];
    }
}
