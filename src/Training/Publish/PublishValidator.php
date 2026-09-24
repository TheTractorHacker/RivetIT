<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Authoring\LessonIssues;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Media\ArticleSanitizer;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Quiz\InList;
use ITFlow\Training\Quiz\QuestionRules;
use ITFlow\Training\Quiz\QuizService;

/**
 * Publish readiness for a strict build (spec §3.5 "Validation codes").
 *
 * Every LessonIssues code is included, plus the course, language, quiz, question, media,
 * article, video and size checks below. The codes listed in WARNING_CODES are warnings; every
 * other code is an error and blocks publishing. Issues about a language that is being left out
 * (offered, incomplete, not required) are summarised by one lang_excluded warning instead of
 * being listed per lesson.
 *
 * With $network the validator re-checks every external video's availability (VideoRecheck:
 * parallel oEmbed, 20 s budget); a network failure is only ever the warning video_recheck_failed.
 *
 * Result: {errors:[Issue], warnings:[Issue], todo:[Issue]} - `todo` repeats errors and warnings
 * with severity 'todo', for the builder's neutral checklist (red only in the publish modal).
 */
final class PublishValidator
{
    public const WARNING_CODES = ['exam_not_last', 'section_empty', 'lang_excluded', 'video_recheck_failed', 'revision_too_large'];
    public const MAX_JSON_BYTES = 2097152;
    public const VIDEO_FRESH_DAYS = 30;
    public const VIDEO_MIN_S = 10;

    public function __construct(private readonly Ctx $c, private readonly ?MediaStore $store = null)
    {
    }

    public function validate(array $build, int $courseId, bool $network): array
    {
        $doc = $build['doc'];
        $default = $build['default_language'];
        $published = $build['languages'];
        $issues = [];
        $add = static function (array $i) use (&$issues): void {
            $issues[] = $i;
        };

        // --- course and outline -------------------------------------------------------------
        $course = $build['course'];
        if (trim((string) $course['course_name']) === '') {
            $add(self::issue('course_name_missing', 'Give the course a name.'));
        }
        $lessons = $doc['lessons'];
        if ($lessons === [] && $doc['course']['components']['online']) {
            $add(self::issue('no_lessons', 'Add at least one lesson.'));
        }
        if ($doc['course']['kind'] === 'document') {
            $content = 0;
            $acks = 0;
            $other = 0;
            foreach ($lessons as $l) {
                if ($l['type'] === 'acknowledgment') {
                    $acks++;
                } elseif (in_array($l['type'], ['document', 'article'], true)) {
                    $content++;
                } else {
                    $other++;
                }
            }
            if ($content !== 1 || $acks !== 1 || $other !== 0) {
                $add(self::issue('document_shape', 'A required document has exactly one document (or article) and one acknowledgment.'));
            }
        }
        $exams = [];
        foreach ($lessons as $l) {
            if (($l['quiz']['role'] ?? null) === 'exam') {
                $exams[] = $l['uid'];
            }
        }
        if (count($exams) > 1) {
            $add(self::issue('multiple_exams', 'Only one lesson can be the final exam.'));
        } elseif (count($exams) === 1 && end($doc['lesson_order']) !== $exams[0]) {
            $add(self::issue('exam_not_last', 'The final exam is not the last lesson.', $this->lessonRef($build, $exams[0])));
        }
        $used = [];
        foreach ($lessons as $l) {
            if ($l['section_uid'] !== null) {
                $used[$l['section_uid']] = true;
            }
        }
        foreach ($doc['sections'] as $s) {
            if (!isset($used[$s['uid']])) {
                $add(self::issue('section_empty', 'Section "' . ($s['text'][$default]['title'] ?? '') . '" has no lessons.'));
            }
        }
        if ($doc['course']['components']['practical'] && $doc['course']['eval_checklist'] === []) {
            $add(self::issue('practical_checklist_missing', 'Add the practical evaluation checklist (Settings › Completion rules).'));
        }

        // --- languages --------------------------------------------------------------------------
        $excludedNotRequired = [];
        foreach ($build['excluded'] as $lang => $items) {
            $n = count($items);
            $name = TrainingSettings::KNOWN_LANGUAGES[$lang] ?? strtoupper($lang);
            if (in_array($lang, $build['required'], true)) {
                $add(self::issue('lang_required_incomplete', "$name is required for this course but isn't finished ($n " . ($n === 1 ? 'item' : 'items') . ').',
                    ['lang' => $lang]));
            } else {
                $excludedNotRequired[] = $lang;
                $publishedNames = implode(' and ', array_map(static fn($l) => TrainingSettings::KNOWN_LANGUAGES[$l] ?? strtoupper($l), $published));
                $add(self::issue('lang_excluded', "Published in $publishedNames only; $name isn't finished ($n " . ($n === 1 ? 'item' : 'items') . ').',
                    ['lang' => $lang]));
            }
        }
        $checkLangs = array_values(array_unique(array_merge($published, array_values(array_intersect(array_keys($build['excluded']), $build['required'])))));

        // --- lessons (LessonIssues) -------------------------------------------------------------
        foreach ($doc['lesson_order'] as $luid) {
            $meta = $build['lessons'][$luid] ?? null;
            if ($meta === null) {
                continue;
            }
            foreach (LessonIssues::forLesson($this->c->db, $meta['id'], $checkLangs) as $li) {
                if (!is_array($li) || !isset($li['code'])) {
                    continue;
                }
                $lang = isset($li['lang']) ? (string) $li['lang'] : null;
                if ($lang !== null && in_array($lang, $excludedNotRequired, true)) {
                    continue; // summarised by lang_excluded
                }
                if ($li['code'] === 'translation_incomplete' && !$meta['required']) {
                    continue; // an optional lesson never holds a language back (§1.3 #11); learners get the default language
                }
                $add(array_merge($li, ['lesson_id' => $meta['id'], 'lesson_uid' => $luid]));
            }
        }

        // --- quizzes and questions ------------------------------------------------------------
        foreach ($build['quizzes'] as $luid => $qm) {
            $ref = $this->lessonRef($build, $luid) + ['quiz_id' => $qm['quiz_id']];
            $z = $qm['row'];
            $res = $qm['resolved'];
            if ($res['rules'] === []) {
                $add(self::issue('quiz_no_rules', 'This quiz has no questions to draw from.', $ref));
            }
            foreach ($res['rules'] as $r) {
                foreach (QuizService::ruleIssues($r, $qm['quiz_id']) as $ri) {
                    $add(array_merge($ri, $ref));
                }
            }
            foreach ($res['overlaps'] as $o) {
                $add(self::issue('quiz_pools_overlap', "Two sources of this quiz share {$o['question_count']} questions.", $ref + ['rule_id' => $o['rule_ids'][1]]));
            }
            $pass = (int) $z['quiz_pass_pct'];
            $attempts = (int) $z['quiz_max_attempts'];
            $limit = $z['quiz_time_limit_s'] === null ? null : (int) $z['quiz_time_limit_s'];
            if ($pass < QuizService::PASS_MIN || $pass > QuizService::PASS_MAX || $attempts < 0 || $attempts > QuizService::ATTEMPTS_MAX
                || ($limit !== null && ($limit < QuizService::TIME_MIN_S || $limit > QuizService::TIME_MAX_S))
                || ($z['quiz_role'] === 'exam' && (int) $z['quiz_must_pass'] !== 1)
                || !in_array($z['quiz_feedback_mode'], QuizService::FEEDBACK_MODES, true)) {
                $add(self::issue('quiz_settings_invalid', 'Check the quiz settings (pass mark 50–100%, attempts, time limit).', $ref));
            }
            $seen = [];
            foreach ($res['rules'] as $r) {
                foreach ($r['pool_ids'] as $qid) {
                    if (isset($seen[$qid])) {
                        continue;
                    }
                    $seen[$qid] = true;
                    foreach (QuestionRules::issues($res['questions'][$qid], $default) as $qi) {
                        $add(array_merge($qi, $ref, ['question_id' => $qid]));
                    }
                }
            }
        }

        // --- media ------------------------------------------------------------------------------
        foreach ($build['missing_media'] as $mid => $where) {
            $add(self::issue('media_missing', "A file used by this course no longer exists (#$mid).", $this->whereRef($build, $where)));
        }
        $store = $this->store ?? new MediaStore($this->c);
        $paths = $this->mediaPaths(array_map(static fn($m) => $m['id'], $doc['media']));
        foreach ($doc['media'] as $m) {
            if ($m['kind'] === 'evidence') {
                $add(self::issue('media_evidence_ref', 'A learner evidence file cannot be part of course content.'));
                continue;
            }
            $row = ['media_id' => $m['id'], 'media_path' => $paths[$m['id']] ?? null];
            $ok = false;
            try {
                $ok = $row['media_path'] !== null && is_file($store->absolutePath($row));
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                $add(self::issue('media_missing', "A file used by this course is missing from storage (#{$m['id']}).",
                    $this->whereRef($build, $build['media_refs'][$m['id']]['where'] ?? [])));
            }
        }

        // --- article HTML ---------------------------------------------------------------------
        foreach ($doc['course']['text'] as $lang => $t) {
            if ($t['description_html'] !== null) {
                foreach (self::htmlIssues($t['description_html']) as $code) {
                    $add(self::issue($code, self::htmlMessage($code) . ' (course description)', ['lang' => $lang]));
                }
            }
        }
        foreach ($lessons as $l) {
            foreach ($l['variants'] as $lang => $v) {
                foreach (['description_html', 'body_html'] as $f) {
                    if ($v[$f] !== null) {
                        foreach (self::htmlIssues($v[$f]) as $code) {
                            $add(self::issue($code, self::htmlMessage($code), $this->lessonRef($build, $l['uid']) + ['lang' => $lang]));
                        }
                    }
                }
            }
        }

        // --- external videos ---------------------------------------------------------------------
        $distinct = [];
        foreach ($build['videos'] as $v) {
            $key = RevisionBuilder::videoKey($v['provider'], $v['id'], $v['hash']);
            $distinct[$key] ??= ['provider' => $v['provider'], 'id' => $v['id'], 'hash' => $v['hash'], 'vcheck' => $build['vchecks'][$key] ?? null, 'uses' => []];
            $distinct[$key]['uses'][] = $v;
        }
        $now = time();
        foreach ($distinct as $key => $v) {
            $vc = $v['vcheck'];
            $ref = $this->lessonRef($build, $v['uses'][0]['lesson_uid']) + ['lang' => $v['uses'][0]['lang']];
            $verified = $vc === null ? null : self::ts($vc['vcheck_verified_at_utc']);
            if ($verified === null) {
                $add(self::issue('video_unverified', 'Play this video once to confirm it works.', $ref));
                continue;
            }
            if ($now - $verified > self::VIDEO_FRESH_DAYS * 86400) {
                $add(self::issue('video_verification_stale', 'This video was last confirmed more than 30 days ago. Play it once more.', $ref));
            }
            $errAt = self::ts($vc['vcheck_last_error_at_utc']);
            if ($errAt !== null && $errAt > $verified) {
                $add(self::issue('video_check_failed', 'The last check of this video failed. Play it once more to confirm it works.', $ref));
            }
            [$dur] = RevisionBuilder::videoDuration($vc);
            if ($dur < self::VIDEO_MIN_S) {
                $add(self::issue('video_duration_short', 'This video is shorter than 10 seconds, or its length is unknown.', $ref));
            }
        }
        // video_unavailable: a FRESH oEmbed answer of 401/403/404 decides when there is one (§3.5).
        // Without one (network=0, or the re-check failed), the stored link-check answer counts only
        // while it is newer than the last successful play - a video fixed in YouTube Studio and
        // then played is not held to its old "private".
        $results = ($network && $distinct !== []) ? VideoRecheck::run($this->c->baseUrl, array_values($distinct)) : [];
        foreach ($distinct as $key => $v) {
            $ref = $this->lessonRef($build, $v['uses'][0]['lesson_uid']) + ['lang' => $v['uses'][0]['lang']];
            $r = $results[$key] ?? null;
            if ($r !== null && $r['status'] === 'failed') {
                $add(self::issue('video_recheck_failed', "This video couldn't be checked just now. It will be published as last confirmed.", $ref));
            }
            $reason = match ($r['status'] ?? null) {
                'unavailable' => (string) $r['reason'],
                'ok' => null,
                default => VideoRecheck::storedUnavailable($v['vcheck']),
            };
            if ($reason !== null) {
                $add(self::issue('video_unavailable', self::unavailableMessage($reason), $ref));
            }
        }

        // --- size -------------------------------------------------------------------------------
        if (($build['bytes'] ?? 0) > self::MAX_JSON_BYTES) {
            $add(self::issue('revision_too_large', 'This version is unusually large (over 2 MB of text). It will still publish.'));
        }

        return self::split($issues, $build['doc']['lesson_order']);
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{errors:list<array>, warnings:list<array>, todo:list<array>} */
    public static function split(array $issues, array $lessonOrder): array
    {
        $pos = array_flip($lessonOrder);
        $seen = [];
        $unique = [];
        foreach ($issues as $i => $issue) {
            $issue['code'] = (string) $issue['code'];
            $issue['message'] = (string) ($issue['message'] ?? $issue['code']);
            $issue['severity'] = in_array($issue['code'], self::WARNING_CODES, true) ? 'warning' : 'error';
            $k = implode('|', [$issue['code'], $issue['lesson_id'] ?? '', $issue['lang'] ?? '', $issue['quiz_id'] ?? '',
                $issue['question_id'] ?? '', $issue['rule_id'] ?? '', $issue['sub'] ?? '']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $issue['_o'] = [isset($issue['lesson_uid']) ? 1 + ($pos[$issue['lesson_uid']] ?? 0) : 0, $i];
            $unique[] = $issue;
        }
        usort($unique, static fn($a, $b) => $a['_o'] <=> $b['_o']);
        $errors = [];
        $warnings = [];
        $todo = [];
        foreach ($unique as $issue) {
            unset($issue['_o']);
            if ($issue['severity'] === 'warning') {
                $warnings[] = $issue;
            } else {
                $errors[] = $issue;
            }
            $todo[] = array_merge($issue, ['severity' => 'todo']);
        }
        return ['errors' => $errors, 'warnings' => $warnings, 'todo' => $todo];
    }

    private static function issue(string $code, string $message, array $extra = []): array
    {
        return array_merge(['code' => $code, 'severity' => in_array($code, self::WARNING_CODES, true) ? 'warning' : 'error', 'message' => $message], $extra);
    }

    private function lessonRef(array $build, string $luid): array
    {
        $meta = $build['lessons'][$luid] ?? null;
        return $meta === null ? [] : ['lesson_id' => $meta['id'], 'lesson_uid' => $luid];
    }

    /** A lesson reference from a MediaRefCollector "where" list ("variant:<uid>:<lang>", "thumb:<uid>", …). */
    private function whereRef(array $build, array $where): array
    {
        foreach ($where as $w) {
            $parts = explode(':', (string) $w);
            if (isset($parts[1]) && isset($build['lessons'][$parts[1]])) {
                return $this->lessonRef($build, $parts[1]) + (isset($parts[2]) ? ['lang' => $parts[2]] : []);
            }
        }
        return [];
    }

    /** @return array<int, string> media id => media_path */
    private function mediaPaths(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            [$ph, $t, $p] = InList::ints($chunk);
            foreach (Db::all($this->c->db, "SELECT media_id, media_path FROM training_media WHERE media_id IN ($ph)", $t, $p) as $r) {
                $out[(int) $r['media_id']] = (string) $r['media_path'];
            }
        }
        return $out;
    }

    /** @return list<string> article_data_uri / article_foreign_media */
    private static function htmlIssues(string $html): array
    {
        $out = [];
        foreach (ArticleSanitizer::issues($html) as $i) {
            $code = is_array($i) ? (string) ($i['code'] ?? '') : (string) $i;
            if (in_array($code, ['article_data_uri', 'article_foreign_media'], true) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    private static function htmlMessage(string $code): string
    {
        return $code === 'article_data_uri'
            ? 'An image is pasted inline instead of uploaded. Upload it again.'
            : 'This text links to a file from outside Training. Upload it here instead.';
    }

    private static function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'private' => 'This video is private or can no longer be embedded. In YouTube Studio set Visibility to Unlisted and allow embedding.',
            'embed_disabled' => 'Embedding is turned off for this video (Studio › Video › Show more › Allow embedding).',
            'live' => "Live streams and Premieres can't be used.",
            default => 'This video no longer exists. Check the link.',
        };
    }

    private static function ts(?string $utc): ?int
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $iso = Clock::toIso($utc, true);
        return $iso === null ? null : strtotime($iso);
    }
}
