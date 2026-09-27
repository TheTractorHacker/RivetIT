<?php

namespace ITFlow\Training\Insight;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Publish\RevisionDiff;
use ITFlow\Training\Publish\RevisionRepository;
use ITFlow\Training\Upstream\LearnerGateway;
use ITFlow\Training\Upstream\PeopleScope;
use ITFlow\Training\Upstream\ScopeView;

/**
 * Two versions of one course side by side (Phase 5 spec §3.6 / §5.6, L1): kiosk results per version,
 * each question's % correct in both, and what changed in the content (Publish\RevisionDiff).
 *
 * Same sources and rules as ItemAnalysis: attempts only through Upstream\LearnerGateway, limited to
 * the caller's people scope; wording from the immutable revision documents; no person data.
 *
 *   {a:{…}, b:{…}} per version: id, number, published_at, change_note, attempts, people,
 *     pass_pct (attempts that passed), first_try_pass_pct (first tries that passed),
 *     mean_best_pct (mean of each person's best score), median_duration_s, passed_people, and
 *     completions = null (training records per version are Phase 2 data that Upstream does not
 *     expose; the page shows passed_people instead).
 *   questions: every question in the compared quizzes of either version, plus any answered one:
 *     {uid, text, type, critical, a_n, a_pct, b_n, b_pct, delta (b - a, one decimal, null unless
 *     both have answers), few (either side under 10 answers), status: same | changed | added | removed}
 *     where status compares the question's canonical document in version A and version B.
 *   content: RevisionDiff::diff(docA, docB)['items'] (A is the "from" side).
 */
final class RevisionCompare
{
    public function __construct(
        private readonly Ctx $c,
        private readonly ?LearnerGateway $learner = null,
        private readonly ?ScopeView $scope = null,
    ) {
    }

    /** @throws ApiException 404 unknown course; 422 a version outside the course, the same version twice, a bad kind */
    public function compare(int $courseId, int $revA, int $revB, string $kind = 'exam'): array
    {
        $db = $this->c->db;
        $course = ItemAnalysis::course($db, $courseId);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        if (!in_array($kind, ItemAnalysis::KINDS, true)) {
            throw ApiException::validation(['kind' => 'Not a valid choice.']);
        }
        $repo = new RevisionRepository($this->c);
        $list = [];
        foreach ($repo->list($courseId) as $r) {
            $list[(int) $r['id']] = $r;
        }
        $fields = [];
        if (!isset($list[$revA])) {
            $fields['a'] = 'That version is not part of this course.';
        }
        if (!isset($list[$revB])) {
            $fields['b'] = 'That version is not part of this course.';
        }
        if ($fields === [] && $revA === $revB) {
            $fields['b'] = 'Pick two different versions.';
        }
        if ($fields !== []) {
            throw ApiException::validation($fields);
        }
        $docA = $repo->get($revA)['doc'];
        $docB = $repo->get($revB)['doc'];

        $out = [
            'available' => false,
            'course' => ['id' => $course['id'], 'name' => $course['name'], 'uid' => $course['uid']],
            'revisions' => array_values(array_map(static fn($r) => ['id' => (int) $r['id'], 'number' => (int) $r['number'], 'published_at' => $r['published_at']], $list)),
            'filter' => ['a' => $revA, 'b' => $revB, 'kind' => $kind],
            'a' => self::side($list[$revA], []),
            'b' => self::side($list[$revB], []),
            'questions' => [],
            'content' => RevisionDiff::diff($docA, $docB)['items'],
            'scope_none' => false,
            'few_n' => ItemAnalysis::FEW_N,
        ];

        $attempts = [$revA => [], $revB => []];
        $ready = ($this->learner !== null || ItemAnalysis::available($db)) && ($this->scope !== null || class_exists(PeopleScope::class));
        if ($ready) {
            $out['available'] = true;
            $scope = $this->scope ?? PeopleScope::forCtx($this->c);
            if ($scope->isNone()) {
                $out['scope_none'] = true;
            } else {
                $learner = $this->learner ?? new LearnerGateway($db);
                $rows = $learner->attemptsForCourse($courseId, ['kind' => $kind, 'revision_ids' => [$revA, $revB]]);
                $allowed = $scope->isAll() ? null : $this->allowedContacts($rows, $scope);
                foreach ($rows as $a) {
                    if (!is_array($a) || !isset($a['revision_id'], $a['contact_id'], $a['score_pct']) || !is_array($a['answers'] ?? null)) {
                        continue;
                    }
                    $rev = (int) $a['revision_id'];
                    $k = ($a['kind'] ?? 'exam') === 'check' ? 'check' : 'exam';
                    if (!isset($attempts[$rev]) || ($kind !== 'all' && $k !== $kind) || ($allowed !== null && !isset($allowed[(int) $a['contact_id']]))) {
                        continue;
                    }
                    $attempts[$rev][] = $a;
                }
            }
        }
        $out['a'] = self::side($list[$revA], $attempts[$revA]);
        $out['b'] = self::side($list[$revB], $attempts[$revB]);
        $out['questions'] = self::questions($docA, $docB, $attempts[$revA], $attempts[$revB], $kind, $course['default_language']);
        return $out;
    }

    /** One version's KPIs over its (in-scope) attempts. */
    public static function side(array $rev, array $attempts): array
    {
        $sm = ItemAnalysis::summary($attempts);
        $passed = 0;
        $best = [];
        $passedPeople = [];
        foreach ($attempts as $a) {
            $cid = (int) $a['contact_id'];
            $cents = ItemAnalysis::cents($a['score_pct']);
            $best[$cid] = max($best[$cid] ?? 0, $cents);
            if (!empty($a['passed'])) {
                $passed++;
                $passedPeople[$cid] = true;
            }
        }
        $n = count($attempts);
        $p = count($best);
        return [
            'id' => (int) $rev['id'],
            'number' => (int) $rev['number'],
            'published_at' => $rev['published_at'],
            'change_note' => (string) ($rev['change_note'] ?? ''),
            'attempts' => $n,
            'people' => $sm['people'],
            'pass_pct' => $n > 0 ? ItemAnalysis::pct($passed, $n) : null,
            'first_try_pass_pct' => $sm['first_try_pass_pct'],
            'mean_best_pct' => $p > 0 ? intdiv(2 * array_sum($best) + 10 * $p, 20 * $p) / 10.0 : null,
            'median_duration_s' => $sm['median_duration_s'],
            'passed_people' => count($passedPeople),
            'completions' => null,
        ];
    }

    /** @return list<array> */
    private static function questions(array $docA, array $docB, array $attA, array $attB, string $kind, string $courseDefault): array
    {
        $stats = static function (array $attempts): array {
            $s = [];
            foreach ($attempts as $a) {
                $seen = [];
                foreach ($a['answers'] as $ans) {
                    $q = (string) ($ans['q'] ?? '');
                    if ($q === '' || isset($seen[$q])) {
                        continue;
                    }
                    $seen[$q] = true;
                    $s[$q] ??= [0, 0];
                    $s[$q][0]++;
                    $s[$q][1] += !empty($ans['correct']) ? 1 : 0;
                }
            }
            return $s;
        };
        $sa = $stats($attA);
        $sb = $stats($attB);
        // Order: version B's quizzes, then A's (removed questions), then anything answered but not in a pool.
        $order = [];
        foreach ([$docB, $docA] as $doc) {
            foreach ((array) ($doc['lessons'] ?? []) as $l) {
                $quiz = $l['quiz'] ?? null;
                if (!is_array($quiz)) {
                    continue;
                }
                $qk = ($quiz['role'] ?? 'standalone') === 'check' ? 'check' : 'exam';
                if ($kind !== 'all' && $qk !== $kind) {
                    continue;
                }
                foreach ((array) ($quiz['rules'] ?? []) as $rule) {
                    foreach ((array) ($rule['pool'] ?? []) as $uid) {
                        $order[(string) $uid] ??= count($order);
                    }
                }
            }
        }
        foreach (array_merge(array_keys($sb), array_keys($sa)) as $uid) {
            $order[(string) $uid] ??= count($order);
        }
        asort($order);
        $rows = [];
        foreach (array_keys($order) as $uid) {
            $uid = (string) $uid;
            $qa = is_array($docA['questions'][$uid] ?? null) ? $docA['questions'][$uid] : null;
            $qb = is_array($docB['questions'][$uid] ?? null) ? $docB['questions'][$uid] : null;
            $q = $qb ?? $qa;
            $status = $qa !== null && $qb !== null ? (self::canon($qa) === self::canon($qb) ? 'same' : 'changed') : ($qb !== null ? 'added' : 'removed');
            [$an, $ac] = $sa[$uid] ?? [0, 0];
            [$bn, $bc] = $sb[$uid] ?? [0, 0];
            $aPct = $an > 0 ? ItemAnalysis::pct($ac, $an) : null;
            $bPct = $bn > 0 ? ItemAnalysis::pct($bc, $bn) : null;
            $langs = [(string) (($qb !== null ? $docB : $docA)['course']['default_language'] ?? $courseDefault), $courseDefault, 'en'];
            $rows[] = [
                'uid' => $uid,
                'text' => $q === null ? 'Question no longer in the course' : (string) (self::label($q['text'] ?? null, $langs) ?? ''),
                'type' => (string) ($q['type'] ?? 'single'),
                'critical' => !empty($q['critical']),
                'a_n' => $an,
                'a_pct' => $aPct,
                'b_n' => $bn,
                'b_pct' => $bPct,
                'delta' => ($aPct !== null && $bPct !== null) ? round($bPct - $aPct, 1) + 0.0 : null,
                'few' => ($an > 0 && $an < ItemAnalysis::FEW_N) || ($bn > 0 && $bn < ItemAnalysis::FEW_N),
                'status' => $status,
            ];
        }
        return $rows;
    }

    /** @return array<int, true> in-scope contact ids among the rows */
    private function allowedContacts(array $rows, ScopeView $scope): array
    {
        $ids = [];
        foreach ($rows as $a) {
            if (is_array($a) && isset($a['contact_id'])) {
                $ids[(int) $a['contact_id']] = (int) $a['contact_id'];
            }
        }
        $allowed = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (\ITFlow\Training\Core\Db::all($this->c->db, "SELECT contact_id, contact_client_id FROM contacts WHERE contact_id IN ($in)",
                str_repeat('i', count($chunk)), $chunk) as $r) {
                if ($scope->allows((int) $r['contact_client_id'])) {
                    $allowed[(int) $r['contact_id']] = true;
                }
            }
        }
        return $allowed;
    }

    private static function canon(array $q): string
    {
        try {
            return Canonical::doc($q);
        } catch (\Throwable) {
            return (string) json_encode($q);
        }
    }

    private static function label(mixed $map, array $langs): ?string
    {
        if (!is_array($map)) {
            return null;
        }
        foreach ($langs as $lg) {
            if (isset($map[$lg]['q']) && trim((string) $map[$lg]['q']) !== '') {
                return (string) $map[$lg]['q'];
            }
        }
        foreach ($map as $t) {
            if (is_array($t) && isset($t['q']) && trim((string) $t['q']) !== '') {
                return (string) $t['q'];
            }
        }
        return null;
    }
}
