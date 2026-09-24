<?php

namespace ITFlow\Training\Directory;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;

/**
 * The one implementation of the Odoo link rules (Phase 2 spec §3.4, §1.4 #12). No Odoo call: it
 * compares the employee list the directory sync (or the link checker) just read with each link's
 * baseline in contact_odoo_attributes - the Odoo employee id and the LAST CONFIRMED Odoo name
 * (coattr_odoo_name), never contacts.contact_name, which the mapper overwrites.
 *
 * Per contact_odoo_links row of the integration:
 *   no coattr row                        => INSERT (baseline = this link, name = the Odoo name; state ok, or missing)
 *   baseline employee id != link id      => 'repointed' (STICKY: only confirm/relink clear it); job/location cleared
 *   repointed already                    => stays repointed
 *   employee id absent from the list     => 'missing'
 *   norm(Odoo name) != norm(baseline)    => 'mismatch'
 *   otherwise                            => 'ok' and coattr_odoo_target_sha = the current target
 * Department differences only go into the detail. Always refreshed: coattr_link_seen_name,
 * coattr_link_checked_at_utc and coattr_link_suggested_employee_id (the UNIQUE Odoo employee whose
 * normalised name equals the baseline name and who is not linked to another contact; flagged rows only).
 * coattr_odoo_name is written only on insert here (confirm/relink own it otherwise).
 */
final class LinkStates
{
    public const FLAG_STATES = ['mismatch', 'repointed'];

    /**
     * @param list<array> $employees raw hr.employee records [{id, name, department_id:[id,name]|false, active}, …]
     * @return array{ok:int, mismatch:int, missing:int, repointed:int, new:int, newly_flagged:list<int>}
     */
    public static function apply(\mysqli $db, int $integrationId, string $targetSha, array $employees): array
    {
        $emp = [];
        $byName = [];
        foreach ($employees as $e) {
            if (!is_array($e) || !isset($e['id']) || !is_int($e['id'])) {
                continue;
            }
            $name = is_string($e['name'] ?? null) ? $e['name'] : '';
            $dept = (isset($e['department_id']) && is_array($e['department_id'])) ? [(int) ($e['department_id'][0] ?? 0), (string) ($e['department_id'][1] ?? '')] : null;
            $emp[$e['id']] = ['name' => $name, 'dept' => $dept];
            $n = self::norm($name);
            if ($n !== '') {
                $byName[$n][] = $e['id'];
            }
        }
        $now = Clock::nowUtc();
        $stats = ['ok' => 0, 'mismatch' => 0, 'missing' => 0, 'repointed' => 0, 'new' => 0, 'newly_flagged' => []];

        Db::tx($db, function () use ($db, $integrationId, $targetSha, $emp, $byName, $now, &$stats): void {
            $links = Db::all($db, 'SELECT l.contact_id, l.odoo_employee_id, c.contact_name, cl.client_name,
                    a.coattr_contact_id, a.coattr_odoo_employee_id, a.coattr_odoo_name, a.coattr_link_state, a.coattr_link_detail
                FROM contact_odoo_links l
                JOIN contacts c ON c.contact_id = l.contact_id
                LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
                LEFT JOIN contact_odoo_attributes a ON a.coattr_contact_id = l.contact_id
                WHERE l.odoo_integration_id = ?
                ORDER BY l.contact_id FOR UPDATE', 'i', [$integrationId]);
            // Employee ids linked to each contact (for suggestions: never suggest someone else's employee).
            $linkedTo = [];
            foreach ($links as $l) {
                $linkedTo[(int) $l['odoo_employee_id']][] = (int) $l['contact_id'];
            }
            $depts = [];
            foreach (Db::all($db, 'SELECT client_id, odoo_department_id FROM client_odoo_links WHERE odoo_integration_id = ?', 'i', [$integrationId]) as $d) {
                $depts[(int) $d['odoo_department_id']] = (int) $d['client_id'];
            }
            $clientOf = [];
            foreach (Db::all($db, 'SELECT contact_id, contact_client_id FROM contacts WHERE contact_id IN (SELECT contact_id FROM contact_odoo_links WHERE odoo_integration_id = ?)',
                'i', [$integrationId]) as $r) {
                $clientOf[(int) $r['contact_id']] = (int) $r['contact_client_id'];
            }

            foreach ($links as $l) {
                $cid = (int) $l['contact_id'];
                $linkId = (int) $l['odoo_employee_id'];
                $e = $emp[$linkId] ?? null;
                $seen = $e === null ? null : Text::clip($e['name'], 200);

                if ($l['coattr_contact_id'] === null) {
                    $baseline = $e !== null && $e['name'] !== '' ? Text::clip($e['name'], 200) : Text::clip((string) $l['contact_name'], 200);
                    $state = $e === null ? 'missing' : 'ok';
                    Db::exec($db, 'INSERT INTO contact_odoo_attributes (coattr_contact_id, coattr_odoo_integration_id, coattr_odoo_employee_id, coattr_odoo_name,
                            coattr_odoo_target_sha, coattr_link_state, coattr_link_detail, coattr_link_seen_name, coattr_link_checked_at_utc)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', 'iiissssss',
                        [$cid, $integrationId, $linkId, $baseline, $state === 'ok' ? $targetSha : null, $state,
                         $state === 'missing' ? 'Odoo employee #' . $linkId . ' was not in the employee list' : 'New link', $seen, $now]);
                    $stats[$state]++;
                    $stats['new']++;
                    continue;
                }

                $prev = (string) $l['coattr_link_state'];
                $baseId = (int) $l['coattr_odoo_employee_id'];
                $baseName = (string) ($l['coattr_odoo_name'] ?? '');
                $detail = null;
                $clearAttrs = false;
                if ($prev === 'repointed' || $baseId !== $linkId) {
                    $state = 'repointed';
                    $clearAttrs = true;
                    $old = (string) ($l['coattr_link_detail'] ?? '');
                    $on = ($prev === 'repointed' && preg_match('/^Odoo employee changed #\d+ -> #' . $linkId . ' on (\d{4}-\d{2}-\d{2})/', $old, $m) === 1)
                        ? $m[1] : Clock::todayLocal();
                    $detail = $baseId !== $linkId ? "Odoo employee changed #$baseId -> #$linkId on $on"
                        : "Odoo link was re-pointed and is back on #$linkId; confirm it";
                } elseif ($e === null) {
                    $state = 'missing';
                    $detail = 'Odoo employee #' . $linkId . ' was not in the employee list';
                } elseif (self::norm($e['name']) !== self::norm($baseName)) {
                    $state = 'mismatch';
                    $detail = 'Odoo name is now "' . Text::clip($e['name'], 80) . '" (confirmed: "' . Text::clip($baseName, 80) . '")';
                } else {
                    $state = 'ok';
                }
                if ($e !== null && $e['dept'] !== null && $e['dept'][0] > 0) {
                    $mapped = $depts[$e['dept'][0]] ?? null;
                    if ($mapped !== null && $mapped !== ($clientOf[$cid] ?? 0)) {
                        $detail = trim(($detail ?? '') . ' Department differs: Odoo "' . Text::clip($e['dept'][1], 60) . '".');
                    }
                }
                $suggest = null;
                if ($state !== 'ok') {
                    $cands = $byName[self::norm($baseName)] ?? [];
                    $cands = array_values(array_filter($cands, static fn($id) => array_diff($linkedTo[$id] ?? [], [$cid]) === []));
                    $suggest = count($cands) === 1 ? $cands[0] : null;
                }
                $sets = 'coattr_odoo_integration_id = ?, coattr_link_state = ?, coattr_link_detail = ?, coattr_link_seen_name = ?, coattr_link_checked_at_utc = ?,
                    coattr_link_suggested_employee_id = ?';
                $types = 'issssi';
                $params = [$integrationId, $state, Text::clip($detail, 255), $seen, $now, $suggest];
                if ($state === 'ok') {
                    $sets .= ', coattr_odoo_target_sha = ?';
                    $types .= 's';
                    $params[] = $targetSha;
                }
                if ($clearAttrs) {
                    $sets .= ', coattr_job_id = NULL, coattr_job_name = NULL, coattr_work_location_id = NULL, coattr_work_location_name = NULL';
                }
                Db::exec($db, "UPDATE contact_odoo_attributes SET $sets WHERE coattr_contact_id = ?", $types . 'i', array_merge($params, [$cid]));
                $stats[$state]++;
                if (in_array($state, self::FLAG_STATES, true) && $prev !== $state) {
                    $stats['newly_flagged'][] = $cid;
                }
            }
        });
        return $stats;
    }

    /** Lowercase, accents removed (NFD without combining marks), whitespace collapsed. */
    public static function norm(?string $s): string
    {
        $s = (string) $s;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_scrub($s, 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        }
        $s = mb_strtolower($s, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
