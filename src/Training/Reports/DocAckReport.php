<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;

/**
 * Document acknowledgments (S8 "documents" tab): per published document course, how many of
 * the people required to acknowledge it have signed the CURRENT version, signed an OLDER
 * version that still counts (a re-acknowledgment grace that has not run out, or a revision that
 * did not require one - decided by PairRules via the pair status), or have not acknowledged
 * (anything that does not count as current). By department; in scope only.
 */
final class DocAckReport
{
    public function __construct(private readonly Ctx $c, private readonly ?RecordsSettings $settings = null)
    {
    }

    /**
     * @param array{client_id?:?int} $f
     * @return array{courses:list<array>}
     */
    public function build(?int $courseId, array $f, Scope $s): array
    {
        $db = $this->c->db;
        $settings = $this->settings ?? RecordsSettings::fromDb($db);
        $courses = Lookup::publishedCourses($db, 'document');
        if ($courseId !== null) {
            $courses = array_values(array_filter($courses, static fn($c) => $c['id'] === $courseId));
        }
        $src = new PairSource($this->c, $s, $settings);
        $out = [];
        foreach ($courses as $course) {
            $pairs = $src->pairs(['course_id' => $course['id'], 'client_id' => $f['client_id'] ?? null]);
            $revs = self::revisionNumbers($db, array_values(array_filter(array_map(static fn($p) => $p['completion_id'], $pairs))));
            $depts = [];
            $tot = ['required' => 0, 'current_version' => 0, 'older_version' => 0, 'not_acknowledged' => 0, 'waived' => 0];
            foreach ($pairs as $p) {
                if (!$p['required'] || $p['course_id'] !== $course['id']) {
                    continue;
                }
                $d = $p['client_id'];
                $depts[$d] ??= ['client_id' => $d, 'required' => 0, 'current_version' => 0, 'older_version' => 0, 'not_acknowledged' => 0, 'waived' => 0];
                if ($p['waived']) {
                    $depts[$d]['waived']++;
                    $tot['waived']++;
                    continue;
                }
                $depts[$d]['required']++;
                $tot['required']++;
                if (!$p['counts_current']) {
                    $key = 'not_acknowledged';
                } else {
                    $rev = $p['completion_id'] !== null ? ($revs[$p['completion_id']] ?? null) : null;
                    $key = ($rev !== null && $course['revision_number'] !== null && $rev >= $course['revision_number'])
                        ? 'current_version' : 'older_version';
                }
                $depts[$d][$key]++;
                $tot[$key]++;
            }
            $names = Lookup::departmentNames($db, array_keys($depts));
            foreach ($depts as $d => &$row) {
                $row['name'] = $names[$d] ?? ('Department #' . $d);
                $row['pct'] = Labels::pct($row['current_version'] + $row['older_version'], $row['required']);
            }
            unset($row);
            $depts = array_values($depts);
            usort($depts, static function ($a, $b) {
                if (($a['client_id'] === 0) !== ($b['client_id'] === 0)) {
                    return $a['client_id'] === 0 ? 1 : -1;
                }
                return strcasecmp($a['name'], $b['name']);
            });
            $out[] = [
                'course' => $course,
                'required' => $tot['required'],
                'current_version' => $tot['current_version'],
                'older_version' => $tot['older_version'],
                'not_acknowledged' => $tot['not_acknowledged'],
                'waived' => $tot['waived'],
                'pct' => Labels::pct($tot['current_version'] + $tot['older_version'], $tot['required']),
                'departments' => $depts,
            ];
        }
        return ['courses' => $out, 'target' => $settings->targetPct];
    }

    /** @param list<int> $ids @return array<int, ?int> completion_id => revision number */
    private static function revisionNumbers(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all($db, "SELECT completion_id, completion_snap_revision_number FROM training_completions WHERE completion_id IN ($in)",
                str_repeat('i', count($chunk)), $chunk) as $r) {
                $out[(int) $r['completion_id']] = $r['completion_snap_revision_number'] !== null ? (int) $r['completion_snap_revision_number'] : null;
            }
        }
        return $out;
    }
}
