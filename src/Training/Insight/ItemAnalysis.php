<?php

namespace ITFlow\Training\Insight;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Publish\RevisionRepository;
use ITFlow\Training\Upstream\LearnerGateway;
use ITFlow\Training\Upstream\PeopleScope;
use ITFlow\Training\Upstream\Schema;
use ITFlow\Training\Upstream\ScopeView;

/**
 * Item analysis for one course (Phase 5 spec §3.6 / §4.1 ItemReport, S7): how each quiz question
 * performed across the kiosk attempts in a filter (version, language, quiz kind, period).
 *
 * Attempts come ONLY through Upstream\LearnerGateway (the P3 tables are never named here). Question
 * wording, options and the answer key come from the immutable revision documents
 * (Publish\RevisionRepository::get), using the NEWEST revision in the filter that contains the
 * question. Every number is limited to the caller's people scope (Upstream\PeopleScope, the
 * fail-closed P2 department scope): a Training 2 author with departments sees only their
 * departments' attempts; no departments means no attempts (scope_none). The report carries no
 * contact id or name.
 *
 * Metrics per question uid (n = attempts in the filter whose draw included the question):
 *   correct_pct     100 * correct / n, one decimal.
 *   discrimination  D = p(upper group) - p(lower group), two decimals. The groups are the top and
 *                   bottom 27 % of ALL attempts in the filter by score_pct (ties broken by attempt
 *                   id, so a run is repeatable): k = round(0.27 * N), at least 1, at most N/2.
 *                   p(group) counts only the group's attempts that were asked this question. Null
 *                   when either group has fewer than 3 answers to it, or when n < 10.
 *   options         chosen_n / chosen_pct over n; a multiple-choice answer counts every option it
 *                   selected. Options come from the newest revision's question; an option only an
 *                   older revision had is listed after them.
 *   wrong_top       the incorrect option chosen most (ties: option order), or null.
 *   flags           'few' when n < 10 - then D and every other flag are suppressed; else
 *                   'hard' (correct_pct < 40), 'easy' (> 95), 'check_key' (D < 0, or the top wrong
 *                   option was chosen more often than every correct option).
 *   changed_across_revisions  the question's canonical document differs between the revisions
 *                   its answers came from (the numbers then mix two wordings).
 * Quiz kinds follow the attempt: 'exam' = final exams and standalone quizzes, 'check' = lesson
 * quick checks, 'all' = both.
 */
final class ItemAnalysis
{
    public const KINDS = ['exam', 'check', 'all'];
    public const FEW_N = 10;
    public const GROUP_FRACTION = 0.27;
    public const MIN_GROUP_ANSWERS = 3;
    public const HARD_PCT = 40.0;
    public const EASY_PCT = 95.0;
    public const TYPE_LABELS = ['single' => 'Single choice', 'multi' => 'Multiple choice', 'truefalse' => 'True / false'];

    /** @var array<int, array> revision id => RevisionRepository::get() (memo) */
    private array $revisions = [];

    public function __construct(
        private readonly Ctx $c,
        private readonly ?LearnerGateway $learner = null,
        private readonly ?ScopeView $scope = null,
    ) {
    }

    /** Whether the attempt tables and the Upstream gateway exist (Phase 3 migrated, Lane A merged). */
    public static function available(\mysqli $db): bool
    {
        if (!class_exists(LearnerGateway::class) || !class_exists(Schema::class) || !class_exists(PeopleScope::class)) {
            return false;
        }
        try {
            return Schema::has($db, Schema::P3);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Published training courses for the course picker, by name: {id, uid, name, code, revision_number, archived}.
     *
     * @return list<array>
     */
    public static function courses(\mysqli $db): array
    {
        $out = [];
        foreach (Db::all($db, "SELECT c.course_id, c.course_uid, c.course_name, c.course_code, c.course_archived_at, r.revision_number
                FROM training_courses c JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
                WHERE c.course_kind = 'training' ORDER BY c.course_archived_at IS NOT NULL, c.course_name, c.course_id") as $r) {
            $out[] = [
                'id' => (int) $r['course_id'],
                'uid' => (string) $r['course_uid'],
                'name' => (string) $r['course_name'],
                'code' => $r['course_code'] === null ? null : (string) $r['course_code'],
                'revision_number' => (int) $r['revision_number'],
                'archived' => $r['course_archived_at'] !== null,
            ];
        }
        return $out;
    }

    /**
     * The course row the report is about (a training course), or null.
     *
     * @return ?array{id:int, uid:string, name:string, code:?string, default_language:string, archived:bool}
     */
    public static function course(\mysqli $db, int $courseId): ?array
    {
        if ($courseId < 1) {
            return null;
        }
        $r = Db::one($db, "SELECT course_id, course_uid, course_name, course_code, course_kind, course_default_language, course_archived_at
            FROM training_courses WHERE course_id = ?", 'i', [$courseId]);
        if ($r === null || $r['course_kind'] !== 'training') {
            return null;
        }
        return [
            'id' => (int) $r['course_id'],
            'uid' => (string) $r['course_uid'],
            'name' => (string) $r['course_name'],
            'code' => $r['course_code'] === null ? null : (string) $r['course_code'],
            'default_language' => (string) $r['course_default_language'],
            'archived' => $r['course_archived_at'] !== null,
        ];
    }

    /**
     * The ItemReport (spec §4.1) plus: course.uid/code/archived, scope_none, few_n, quiz per question.
     *
     * @throws ApiException 404 unknown course / document; 422 revision not in the course, bad kind, lang or since
     */
    public function forCourse(int $courseId, ?int $revisionId, ?string $lang, string $kind, ?string $sinceLocal): array
    {
        $db = $this->c->db;
        $course = self::course($db, $courseId);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw ApiException::validation(['kind' => 'Not a valid choice.']);
        }
        if ($lang !== null && preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/D', $lang) !== 1) {
            throw ApiException::validation(['lang' => 'Not a valid choice.']);
        }
        if ($sinceLocal !== null && !Clock::isYmd($sinceLocal)) {
            throw ApiException::validation(['since' => 'Must be a date (YYYY-MM-DD).']);
        }

        $revList = (new RevisionRepository($this->c))->list($courseId);
        $revisions = [];
        $numberOf = [];
        foreach ($revList as $r) {
            $revisions[] = ['id' => (int) $r['id'], 'number' => (int) $r['number'], 'published_at' => $r['published_at']];
            $numberOf[(int) $r['id']] = (int) $r['number'];
        }
        if ($revisionId !== null && !isset($numberOf[$revisionId])) {
            throw ApiException::validation(['revision_id' => 'That version is not part of this course.']);
        }

        $report = [
            'available' => false,
            'course' => ['id' => $course['id'], 'name' => $course['name'], 'uid' => $course['uid'], 'code' => $course['code'], 'archived' => $course['archived']],
            'revisions' => $revisions,
            'filter' => ['revision_id' => $revisionId, 'lang' => $lang, 'kind' => $kind, 'since' => $sinceLocal],
            'summary' => ['attempts' => 0, 'people' => 0, 'first_try_pass_pct' => null, 'mean_score_pct' => null, 'median_duration_s' => null],
            'questions' => [],
            'scope_none' => false,
            'few_n' => self::FEW_N,
        ];
        // Degrade, never 500: before the Phase 3 tables (or Lane A's gateway) exist, the page says results come later.
        $ready = ($this->learner !== null || self::available($db)) && ($this->scope !== null || class_exists(PeopleScope::class));
        if (!$ready) {
            return $report;
        }
        $report['available'] = true;

        $scope = $this->scope ?? PeopleScope::forCtx($this->c);
        if ($scope->isNone()) {
            $report['scope_none'] = true;
            return $report;
        }

        $sinceUtc = $sinceLocal === null ? null : self::localMidnightUtc($sinceLocal);
        $opts = ['kind' => $kind];
        if ($revisionId !== null) {
            $opts['revision_ids'] = [$revisionId];
        }
        if ($lang !== null) {
            $opts['lang'] = $lang;
        }
        if ($sinceUtc !== null) {
            $opts['since_utc'] = $sinceUtc;
        }
        $learner = $this->learner ?? new LearnerGateway($db);
        $attempts = self::refilter($learner->attemptsForCourse($courseId, $opts), $opts, $numberOf);
        $attempts = $this->inScope($attempts, $scope);
        if ($attempts === []) {
            return $report;
        }
        $report['summary'] = self::summary($attempts);
        $report['questions'] = $this->questions($attempts, $numberOf, $lang, $course['default_language'], $kind);
        return $report;
    }

    // ------------------------------------------------------------------------------------------
    // Pure metrics (public for the CLI suite)
    // ------------------------------------------------------------------------------------------

    /**
     * {attempts, people, first_try_pass_pct, mean_score_pct, median_duration_s}. First-try pass is the share of
     * attempt-1 tries (the first try of that quiz in a course run) that passed.
     *
     * @param list<array> $attempts AttemptDTOs
     */
    public static function summary(array $attempts): array
    {
        $people = [];
        $scores = [];
        $durations = [];
        $first = 0;
        $firstPassed = 0;
        foreach ($attempts as $a) {
            $people[(int) $a['contact_id']] = true;
            $scores[] = (float) $a['score_pct'];
            $durations[] = (int) $a['duration_s'];
            if ((int) $a['number'] === 1) {
                $first++;
                if (!empty($a['passed'])) {
                    $firstPassed++;
                }
            }
        }
        $n = count($attempts);
        return [
            'attempts' => $n,
            'people' => count($people),
            'first_try_pass_pct' => $first > 0 ? round(100 * $firstPassed / $first, 1) : null,
            'mean_score_pct' => $n > 0 ? round(array_sum($scores) / $n, 1) : null,
            'median_duration_s' => self::median($durations),
        ];
    }

    /**
     * The upper and lower 27 % groups by score (desc; ties by attempt id asc).
     *
     * @param list<array> $attempts
     * @return array{upper: array<int, true>, lower: array<int, true>, k: int}
     */
    public static function groups(array $attempts): array
    {
        $rows = array_map(static fn($a) => [(float) $a['score_pct'], (int) $a['attempt_id']], $attempts);
        usort($rows, static fn($x, $y) => [$y[0], $x[1]] <=> [$x[0], $y[1]]);
        $n = count($rows);
        $k = $n < 2 ? 0 : min(intdiv($n, 2), max(1, (int) round($n * self::GROUP_FRACTION)));
        $upper = [];
        $lower = [];
        for ($i = 0; $i < $k; $i++) {
            $upper[$rows[$i][1]] = true;
            $lower[$rows[$n - 1 - $i][1]] = true;
        }
        return ['upper' => $upper, 'lower' => $lower, 'k' => $k];
    }

    public static function median(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $mid = intdiv($n, 2);
        $m = $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
        return (int) round($m);
    }

    /** Local midnight of a 'Y-m-d' as UTC 'Y-m-d H:i:s.v'. */
    public static function localMidnightUtc(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Applies the filter again on the returned rows (a gateway that ignored an option can never widen the
     * report) and drops rows that are not submitted attempts of this course's revisions.
     *
     * @param list<array> $rows
     * @param array<int, int> $numberOf revision id => number (this course)
     * @return list<array>
     */
    private static function refilter(array $rows, array $opts, array $numberOf): array
    {
        $out = [];
        foreach ($rows as $a) {
            if (!is_array($a) || !isset($a['attempt_id'], $a['contact_id'], $a['revision_id'], $a['score_pct']) || !is_array($a['answers'] ?? null)) {
                continue;
            }
            $rev = (int) $a['revision_id'];
            $k = ($a['kind'] ?? 'exam') === 'check' ? 'check' : 'exam';
            if (!isset($numberOf[$rev])
                || ($opts['kind'] !== 'all' && $k !== $opts['kind'])
                || (isset($opts['revision_ids']) && !in_array($rev, $opts['revision_ids'], true))
                || (isset($opts['lang']) && (string) ($a['language'] ?? '') !== $opts['lang'])
                || (isset($opts['since_utc']) && (string) ($a['submitted_at_utc'] ?? '') < $opts['since_utc'])) {
                continue;
            }
            $a['kind'] = $k;
            $out[] = $a;
        }
        return $out;
    }

    /** @param list<array> $attempts @return list<array> the attempts of people inside the scope */
    private function inScope(array $attempts, ScopeView $scope): array
    {
        if ($scope->isAll()) {
            return $attempts;
        }
        $ids = array_values(array_unique(array_map(static fn($a) => (int) $a['contact_id'], $attempts)));
        $allowed = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all($this->c->db, "SELECT contact_id, contact_client_id FROM contacts WHERE contact_id IN ($in)", str_repeat('i', count($chunk)), $chunk) as $r) {
                if ($scope->allows((int) $r['contact_client_id'])) {
                    $allowed[(int) $r['contact_id']] = true;
                }
            }
        }
        return array_values(array_filter($attempts, static fn($a) => isset($allowed[(int) $a['contact_id']])));
    }

    private function revision(int $revisionId): ?array
    {
        if (!array_key_exists($revisionId, $this->revisions)) {
            try {
                $this->revisions[$revisionId] = (new RevisionRepository($this->c))->get($revisionId);
            } catch (\Throwable $e) {
                error_log('Training item analysis: revision #' . $revisionId . ' could not be read: ' . get_class($e));
                $this->revisions[$revisionId] = null;
            }
        }
        return $this->revisions[$revisionId];
    }

    /**
     * @param list<array> $attempts in-scope AttemptDTOs
     * @param array<int, int> $numberOf revision id => number
     * @return list<array> ItemReport questions, numbered
     */
    private function questions(array $attempts, array $numberOf, ?string $lang, string $courseDefault, string $kind): array
    {
        $groups = self::groups($attempts);
        $stats = [];
        foreach ($attempts as $a) {
            $aid = (int) $a['attempt_id'];
            $rev = (int) $a['revision_id'];
            $inUpper = isset($groups['upper'][$aid]);
            $inLower = isset($groups['lower'][$aid]);
            $seen = [];
            foreach ($a['answers'] as $ans) {
                $q = (string) ($ans['q'] ?? '');
                if ($q === '' || isset($seen[$q])) {
                    continue;
                }
                $seen[$q] = true;
                $ok = !empty($ans['correct']);
                $s = &$stats[$q];
                $s ??= ['n' => 0, 'correct' => 0, 'up_n' => 0, 'up_c' => 0, 'lo_n' => 0, 'lo_c' => 0, 'chosen' => [], 'revs' => []];
                $s['n']++;
                $s['correct'] += $ok ? 1 : 0;
                if ($inUpper) {
                    $s['up_n']++;
                    $s['up_c'] += $ok ? 1 : 0;
                }
                if ($inLower) {
                    $s['lo_n']++;
                    $s['lo_c'] += $ok ? 1 : 0;
                }
                foreach (array_unique(array_map('strval', (array) ($ans['selected'] ?? []))) as $o) {
                    if ($o !== '') {
                        $s['chosen'][$o] = ($s['chosen'][$o] ?? 0) + 1;
                    }
                }
                $s['revs'][$rev] = true;
                unset($s);
            }
        }

        // Revision docs, newest first: the answered revisions (only this course's).
        $revIds = [];
        foreach ($stats as $s) {
            foreach (array_keys($s['revs']) as $r) {
                $revIds[$r] = $numberOf[$r] ?? 0;
            }
        }
        arsort($revIds);
        $docs = [];
        foreach (array_keys($revIds) as $rid) {
            $rev = $this->revision($rid);
            if ($rev !== null && is_array($rev['doc'] ?? null)) {
                $docs[$rid] = $rev['doc'];
            }
        }
        [$order, $quizOf] = self::questionOrder($docs, $kind, $lang, $courseDefault);

        $rows = [];
        foreach ($stats as $q => $s) {
            $answeredRevs = array_keys($s['revs']);
            usort($answeredRevs, static fn($x, $y) => ($numberOf[$y] ?? 0) <=> ($numberOf[$x] ?? 0));
            // The newest answered revision that has the question supplies its wording and key.
            $qdoc = null;
            $qdocLangs = [$lang, $courseDefault, 'en'];
            $versions = [];
            $qdocs = [];
            foreach ($answeredRevs as $rid) {
                $d = $docs[$rid]['questions'][$q] ?? null;
                if (!is_array($d)) {
                    continue;
                }
                if ($qdoc === null) {
                    $qdoc = $d;
                    $qdocLangs = [$lang, (string) ($docs[$rid]['course']['default_language'] ?? $courseDefault), $courseDefault, 'en'];
                }
                $qdocs[] = $d;
                $versions[self::canon($d)] = true;
            }
            $n = (int) $s['n'];
            $pct = round(100 * $s['correct'] / $n, 1);

            // Options: the newest wording first, then any option only an older revision had.
            $options = [];
            $known = [];
            foreach ($qdocs as $d) {
                foreach ((array) ($d['options'] ?? []) as $o) {
                    $ou = (string) ($o['uid'] ?? '');
                    if ($ou === '' || isset($known[$ou])) {
                        continue;
                    }
                    $known[$ou] = true;
                    $cn = (int) ($s['chosen'][$ou] ?? 0);
                    $options[] = [
                        'uid' => $ou,
                        'label' => (string) (self::text($o['text'] ?? null, 'label', $qdocLangs) ?? ''),
                        'correct' => !empty($o['correct']),
                        'chosen_n' => $cn,
                        'chosen_pct' => round(100 * $cn / $n, 1),
                    ];
                }
            }
            foreach ($s['chosen'] as $ou => $cn) {
                $ou = (string) $ou;
                if (!isset($known[$ou])) {
                    $known[$ou] = true;
                    $options[] = ['uid' => $ou, 'label' => 'Option no longer in the course', 'correct' => false,
                        'chosen_n' => (int) $cn, 'chosen_pct' => round(100 * $cn / $n, 1)];
                }
            }

            $wrongTop = null;
            $bestCorrect = null;
            foreach ($options as $o) {
                if ($o['correct']) {
                    $bestCorrect = $bestCorrect === null ? $o['chosen_pct'] : max($bestCorrect, $o['chosen_pct']);
                } elseif ($o['chosen_n'] > 0 && ($wrongTop === null || $o['chosen_n'] > $wrongTop['n'])) {
                    $wrongTop = ['uid' => $o['uid'], 'label' => $o['label'], 'pct' => $o['chosen_pct'], 'n' => $o['chosen_n']];
                }
            }
            if ($wrongTop !== null) {
                unset($wrongTop['n']);
            }

            $d = null;
            $flags = [];
            if ($n < self::FEW_N) {
                $flags[] = 'few';
            } else {
                if ($s['up_n'] >= self::MIN_GROUP_ANSWERS && $s['lo_n'] >= self::MIN_GROUP_ANSWERS) {
                    $d = round($s['up_c'] / $s['up_n'] - $s['lo_c'] / $s['lo_n'], 2);
                    if ($d == 0.0) {
                        $d = 0.0;   // never "-0"
                    }
                }
                if ($pct < self::HARD_PCT) {
                    $flags[] = 'hard';
                }
                if ($pct > self::EASY_PCT) {
                    $flags[] = 'easy';
                }
                if (($d !== null && $d < 0) || ($wrongTop !== null && $wrongTop['pct'] > ($bestCorrect ?? 0.0))) {
                    $flags[] = 'check_key';
                }
            }

            $numbers = array_values(array_unique(array_map(static fn($r) => $numberOf[$r] ?? 0, $answeredRevs)));
            sort($numbers, SORT_NUMERIC);
            $type = (string) ($qdoc['type'] ?? 'single');
            $rows[] = [
                'uid' => $q,
                'number' => 0,
                'type' => isset(self::TYPE_LABELS[$type]) ? $type : 'single',
                'critical' => !empty($qdoc['critical']),
                'points' => (int) ($qdoc['points'] ?? 1),
                'text' => $qdoc === null ? 'Question no longer in the course' : (string) (self::text($qdoc['text'] ?? null, 'q', $qdocLangs) ?? ''),
                'topic' => $qdoc === null ? null : self::text($qdoc['text'] ?? null, 'topic', $qdocLangs),
                'quiz' => $quizOf[$q] ?? null,
                'n' => $n,
                'correct_pct' => $pct,
                'discrimination' => $d,
                'flags' => $flags,
                'wrong_top' => $wrongTop,
                'options' => $options,
                'revision_numbers' => $numbers,
                'changed_across_revisions' => count($versions) > 1,
                '_order' => $order[$q] ?? PHP_INT_MAX,
            ];
        }
        usort($rows, static fn($x, $y) => [$x['_order'], $x['uid']] <=> [$y['_order'], $y['uid']]);
        foreach ($rows as $i => &$r) {
            $r['number'] = $i + 1;
            unset($r['_order']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Question order and home quiz: newest revision first, lessons in course order, each quiz's rule
     * pools in order; the first place a uid appears wins.
     *
     * @param array<int, array> $docs revision id => doc, newest first
     * @return array{0: array<string, int>, 1: array<string, array{lesson_uid:string, title:string, kind:string}>}
     */
    private static function questionOrder(array $docs, string $kind, ?string $lang, string $courseDefault): array
    {
        $order = [];
        $quizOf = [];
        $i = 0;
        foreach ($docs as $doc) {
            $langs = [$lang, (string) ($doc['course']['default_language'] ?? $courseDefault), $courseDefault, 'en'];
            foreach ((array) ($doc['lessons'] ?? []) as $l) {
                $quiz = $l['quiz'] ?? null;
                if (!is_array($quiz)) {
                    continue;
                }
                $qk = ($quiz['role'] ?? 'standalone') === 'check' ? 'check' : 'exam';
                if ($kind !== 'all' && $qk !== $kind) {
                    continue;
                }
                $title = null;
                $variants = is_array($l['variants'] ?? null) ? $l['variants'] : [];
                foreach ($langs as $lg) {
                    if ($lg !== null && isset($variants[$lg]['title']) && trim((string) $variants[$lg]['title']) !== '') {
                        $title = (string) $variants[$lg]['title'];
                        break;
                    }
                }
                if ($title === null && $variants !== []) {
                    $first = reset($variants);
                    $title = is_array($first) ? (string) ($first['title'] ?? '') : '';
                }
                foreach ((array) ($quiz['rules'] ?? []) as $rule) {
                    foreach ((array) ($rule['pool'] ?? []) as $uid) {
                        $uid = (string) $uid;
                        if (!isset($order[$uid])) {
                            $order[$uid] = $i++;
                            $quizOf[$uid] = ['lesson_uid' => (string) ($l['uid'] ?? ''), 'title' => (string) $title, 'kind' => $qk];
                        }
                    }
                }
            }
        }
        return [$order, $quizOf];
    }

    /** A stable fingerprint of a question document (Canonical::doc; json_encode if it ever refuses a value). */
    private static function canon(array $q): string
    {
        try {
            return Canonical::doc($q);
        } catch (\Throwable) {
            return (string) json_encode($q);
        }
    }

    /** A localised field ($map[lang][field]) in the first language that has it, else the first language present. */
    private static function text(mixed $map, string $field, array $langs): ?string
    {
        if (!is_array($map)) {
            return null;
        }
        foreach ($langs as $lg) {
            if ($lg !== null && isset($map[$lg][$field]) && trim((string) $map[$lg][$field]) !== '') {
                return (string) $map[$lg][$field];
            }
        }
        foreach ($map as $t) {
            if (is_array($t) && isset($t[$field]) && trim((string) $t[$field]) !== '') {
                return (string) $t[$field];
            }
        }
        return null;
    }
}
