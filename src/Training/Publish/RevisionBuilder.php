<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\DurationEstimator;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Quiz\BankService;
use ITFlow\Training\Quiz\BankTree;
use ITFlow\Training\Quiz\Guard;
use ITFlow\Training\Quiz\InList;
use ITFlow\Training\Quiz\PoolResolver;
use ITFlow\Training\Quiz\QuestionRules;

/**
 * Builds revision JSON schema v1 (spec §3.6) from a course's draft - deterministically: the same
 * draft always yields the same canonical bytes, so "has changes" is simply
 * sha256(build) != sha256(current revision).
 *
 *   $strict = true   what publishing would store: the default language plus every other offered
 *                    language that is complete (every required lesson has a complete variant and
 *                    every quiz pool is fully translated); incomplete languages are left out and
 *                    reported in `excluded` (spec §1.3 #11).
 *   $strict = false  the author's preview: every offered language, with whatever exists.
 *
 * All reads happen in one consistent snapshot (START TRANSACTION WITH CONSISTENT SNAPSHOT, READ
 * ONLY) when no transaction is open, so a concurrent autosave can never produce a mixed build.
 * The builder never writes and never does network I/O.
 *
 * Determinism rules (§3.6): lists ordered by (sort, id); no floats, no timestamps; text maps
 * hold only the build's languages; an empty map is never emitted as an object.
 *
 * Returned array: doc, json, sha256, bytes, strict, course (row), languages (in the doc),
 * offered, required, default_language, excluded (lang => missing items), lesson_ids (uid => id),
 * lessons (uid => meta), quizzes (lesson uid => resolved pools + quiz row), videos, media_refs,
 * missing_media, draft_updated_at_utc.
 */
final class RevisionBuilder
{
    public const SCHEMA = 1;

    private const COURSE_COLS = 'course_id, course_uid, course_kind, course_code, course_name, course_summary, course_description_html,
        course_cover_media_id, course_color, course_default_language, course_languages, course_required_languages, course_regulation_ref,
        course_sequential, course_est_minutes, course_validity_months, course_renewal_lead_days, course_requires_signature,
        course_attestation_text, course_is_qualification, course_needs_online, course_needs_session, course_needs_practical,
        course_external_only, course_component_window_days, course_allow_trainer_attest, course_eval_checklist,
        course_current_revision_id, course_draft_updated_at_utc, course_archived_at';

    private const LESSON_COLS = 'lesson_id, lesson_uid, lesson_course_id, lesson_section_id, lesson_sort, lesson_type, lesson_required,
        lesson_duration_s, lesson_allow_download, lesson_preview_enabled, lesson_thumb_media_id, lesson_min_watch_pct,
        lesson_ack_require_signature, lesson_ack_require_pin, lesson_version';

    private const VARIANT_COLS = 'lvar_lesson_id, lvar_lang, lvar_title, lvar_description_html, lvar_body_html, lvar_word_count, lvar_media_id,
        lvar_caption, lvar_video_provider, lvar_video_ext_id, lvar_video_ext_hash, lvar_kb_source_article_id, lvar_kb_source_sha256,
        lvar_kb_import_body_sha256';

    private const QUIZ_COLS = 'quiz_id, quiz_uid, quiz_lesson_id, quiz_role, quiz_pass_pct, quiz_max_attempts, quiz_time_limit_s,
        quiz_shuffle_questions, quiz_shuffle_options, quiz_feedback_mode, quiz_show_review, quiz_must_pass, quiz_intro';

    public const VCHECK_COLS = 'vcheck_id, vcheck_provider, vcheck_ext_id, vcheck_ext_hash, vcheck_title, vcheck_thumb_media_id, vcheck_status,
        vcheck_http, vcheck_meta_duration_s, vcheck_meta_duration_source, vcheck_checked_at_utc, vcheck_play_duration_s, vcheck_verified_at_utc,
        vcheck_verified_by, vcheck_last_error, vcheck_last_error_at_utc';

    public function __construct(private readonly Ctx $c)
    {
    }

    public function build(int $courseId, bool $strict): array
    {
        $db = $this->c->db;
        Db::ensureUtf8mb4($db);
        $ownSnapshot = Db::depth() === 0;
        if ($ownSnapshot) {
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $b = $this->assemble($courseId, $strict);
        } finally {
            if ($ownSnapshot) {
                try {
                    $db->query('COMMIT');
                } catch (\Throwable) {
                    // read-only snapshot: nothing to lose
                }
            }
        }
        $b['json'] = Canonical::doc($b['doc']);
        $b['sha256'] = Canonical::sha256($b['json']);
        $b['bytes'] = strlen($b['json']);
        return $b;
    }

    // ------------------------------------------------------------------------------------------

    private function assemble(int $courseId, bool $strict): array
    {
        $db = $this->c->db;
        $course = Db::one($db, 'SELECT ' . self::COURSE_COLS . ' FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($course === null) {
            throw ApiException::notFound('That course no longer exists.');
        }
        $langs = Guard::languages($course);
        $default = $langs['default'];

        // --- outline ------------------------------------------------------------------------
        $sections = Db::all($db, 'SELECT csection_id, csection_uid, csection_title, csection_sort FROM training_course_sections
            WHERE csection_course_id = ? ORDER BY csection_sort, csection_id', 'i', [$courseId]);
        $sectionIds = array_map(static fn($s) => (int) $s['csection_id'], $sections);
        $lessonRows = Db::all($db, 'SELECT ' . self::LESSON_COLS . ' FROM training_lessons WHERE lesson_course_id = ? AND lesson_archived_at IS NULL
            ORDER BY lesson_sort, lesson_id', 'i', [$courseId]);
        $bySection = [];
        foreach ($lessonRows as $l) {
            $sid = $l['lesson_section_id'] === null ? 0 : (int) $l['lesson_section_id'];
            if ($sid !== 0 && !in_array($sid, $sectionIds, true)) {
                $sid = 0;
            }
            $bySection[$sid][] = $l;
        }
        $ordered = $bySection[0] ?? [];
        foreach ($sectionIds as $sid) {
            foreach ($bySection[$sid] ?? [] as $l) {
                $ordered[] = $l;
            }
        }
        $lessonIds = array_map(static fn($l) => (int) $l['lesson_id'], $ordered);
        [$lph, $lt, $lp] = InList::ints($lessonIds);

        // --- per-lesson data ------------------------------------------------------------------
        $variants = [];
        foreach (Db::all($db, 'SELECT ' . self::VARIANT_COLS . " FROM training_lesson_variants WHERE lvar_lesson_id IN ($lph)", $lt, $lp) as $v) {
            $variants[(int) $v['lvar_lesson_id']][(string) $v['lvar_lang']] = $v;
        }
        $resources = [];
        foreach (Db::all($db, "SELECT lres_id, lres_uid, lres_lesson_id, lres_lang, lres_kind, lres_title, lres_media_id, lres_url, lres_sort
                FROM training_lesson_resources WHERE lres_lesson_id IN ($lph) AND lres_archived_at IS NULL
                ORDER BY lres_lesson_id, lres_sort, lres_id", $lt, $lp) as $r) {
            $resources[(int) $r['lres_lesson_id']][] = $r;
        }
        $quizRows = [];
        foreach (Db::all($db, 'SELECT ' . self::QUIZ_COLS . " FROM training_quizzes WHERE quiz_lesson_id IN ($lph)", $lt, $lp) as $z) {
            $quizRows[(int) $z['quiz_lesson_id']] = $z;
        }

        // Main media of every variant (pdf pages, video durations) and the PDF pages.
        $variantMedia = [];
        foreach ($variants as $vs) {
            foreach ($vs as $v) {
                if ($v['lvar_media_id'] !== null) {
                    $variantMedia[] = (int) $v['lvar_media_id'];
                }
            }
        }
        $mediaInfo = $this->mediaRows($variantMedia);
        $pages = [];
        $pdfIds = [];
        foreach ($mediaInfo as $id => $m) {
            if ($m['media_kind'] === 'pdf') {
                $pdfIds[] = $id;
            }
        }
        if ($pdfIds !== []) {
            [$pph, $pt, $pp] = InList::ints($pdfIds);
            foreach (Db::all($db, "SELECT p.mpage_pdf_media_id, p.mpage_number, p.mpage_media_id, m.media_width, m.media_height
                    FROM training_media_pages p LEFT JOIN training_media m ON m.media_id = p.mpage_media_id
                    WHERE p.mpage_pdf_media_id IN ($pph) ORDER BY p.mpage_pdf_media_id, p.mpage_number", $pt, $pp) as $p) {
                $pages[(int) $p['mpage_pdf_media_id']][] = [
                    'n' => (int) $p['mpage_number'],
                    'media_id' => (int) $p['mpage_media_id'],
                    'w' => $p['media_width'] === null ? null : (int) $p['media_width'],
                    'h' => $p['media_height'] === null ? null : (int) $p['media_height'],
                ];
            }
        }

        // Video checks for every external video in any variant.
        $vchecks = [];
        $extIds = [];
        foreach ($variants as $vs) {
            foreach ($vs as $v) {
                if (in_array($v['lvar_video_provider'], ['youtube', 'vimeo'], true) && (string) $v['lvar_video_ext_id'] !== '') {
                    $extIds[] = (string) $v['lvar_video_ext_id'];
                }
            }
        }
        if ($extIds !== []) {
            [$vph, $vt, $vp] = InList::strings($extIds);
            foreach (Db::all($db, 'SELECT ' . self::VCHECK_COLS . " FROM training_video_checks WHERE vcheck_ext_id IN ($vph)", $vt, $vp) as $vc) {
                $vchecks[self::videoKey((string) $vc['vcheck_provider'], (string) $vc['vcheck_ext_id'], (string) $vc['vcheck_ext_hash'])] = $vc;
            }
        }

        // Quizzes and pools.
        $tree = BankTree::load($db);
        $resolved = [];
        foreach ($quizRows as $lid => $z) {
            $resolved[$lid] = PoolResolver::resolve($db, (int) $z['quiz_id'], $langs['offered'], $tree);
        }

        // i18n: course, sections, quizzes.
        $i18n = $this->i18n('course', [$courseId]) + $this->i18n('section', $sectionIds)
            + $this->i18n('quiz', array_map(static fn($z) => (int) $z['quiz_id'], $quizRows));

        // --- language completeness ----------------------------------------------------------
        $mediaKinds = array_map(static fn($m) => (string) $m['media_kind'], $mediaInfo);
        $excluded = [];
        $published = [$default];
        foreach ($langs['offered'] as $L) {
            if ($L === $default) {
                continue;
            }
            $missing = [];
            foreach ($ordered as $l) {
                $lid = (int) $l['lesson_id'];
                if ((int) $l['lesson_required'] === 1 && !self::variantComplete($l, $variants[$lid][$L] ?? null, $mediaKinds)) {
                    $missing[] = ['kind' => 'lesson', 'lesson_id' => $lid, 'lesson_uid' => (string) $l['lesson_uid']];
                }
                if (isset($resolved[$lid]) && empty($resolved[$lid]['languages_ready'][$L])) {
                    $untranslated = 0;
                    $seen = [];
                    foreach ($resolved[$lid]['rules'] as $r) {
                        foreach ($r['pool_ids'] as $qid) {
                            if (!isset($seen[$qid]) && !QuestionRules::completeIn($resolved[$lid]['questions'][$qid], $L)) {
                                $untranslated++;
                            }
                            $seen[$qid] = true;
                        }
                    }
                    $missing[] = ['kind' => 'quiz', 'lesson_id' => $lid, 'lesson_uid' => (string) $l['lesson_uid'],
                        'quiz_id' => (int) $quizRows[$lid]['quiz_id'], 'questions' => $untranslated];
                }
            }
            if ($missing === []) {
                $published[] = $L;
            } else {
                $excluded[$L] = $missing;
                if (!$strict) {
                    $published[] = $L;
                }
            }
        }

        // --- lessons ------------------------------------------------------------------------
        $refs = new MediaRefCollector();
        $sectionUid = [];
        foreach ($sections as $s) {
            $sectionUid[(int) $s['csection_id']] = (string) $s['csection_uid'];
        }
        $lessonsDoc = [];
        $lessonOrder = [];
        $lessonMeta = [];
        $quizMeta = [];
        $videos = [];
        $questionIds = [];
        $totalSeconds = 0;
        foreach ($ordered as $l) {
            $lid = (int) $l['lesson_id'];
            $luid = (string) $l['lesson_uid'];
            $type = (string) $l['lesson_type'];
            $lessonOrder[] = $luid;
            $allowDl = (int) $l['lesson_allow_download'] === 1;

            $vdoc = [];
            foreach ($published as $L) {
                $v = $variants[$lid][$L] ?? null;
                if ($v === null) {
                    continue;
                }
                if ($strict && $L !== $default && !self::variantComplete($l, $v, $mediaKinds)) {
                    continue;
                }
                $vdoc[$L] = $this->variantDoc($l, $v, $mediaInfo, $pages, $vchecks, $refs, $allowDl, $luid, $L, $videos);
            }

            $quizDoc = null;
            $expected = null;
            if (isset($quizRows[$lid])) {
                $z = $quizRows[$lid];
                $res = $resolved[$lid];
                $rules = [];
                foreach ($res['rules'] as $r) {
                    $rules[] = [
                        'uid' => $r['uid'],
                        'bank_uid' => (string) ($r['bank_uid'] ?? ''),
                        'include_descendants' => $r['include_descendants'],
                        'count' => $r['count'],
                        'pool' => $r['pool'],
                    ];
                    foreach ($r['pool_ids'] as $qid) {
                        $questionIds[$qid] = $res['questions'][$qid];
                    }
                }
                $intro = [];
                foreach ($published as $L) {
                    $val = $L === $default ? $z['quiz_intro'] : ($i18n['quiz'][(int) $z['quiz_id']][$L]['intro'] ?? null);
                    if ($val !== null && trim((string) $val) !== '') {
                        $intro[$L] = (string) $val;
                    }
                }
                $quizDoc = [
                    'uid' => (string) $z['quiz_uid'],
                    'role' => (string) $z['quiz_role'],
                    'pass_pct' => (int) $z['quiz_pass_pct'],
                    'max_attempts' => (int) $z['quiz_max_attempts'],
                    'time_limit_s' => $z['quiz_time_limit_s'] === null ? null : (int) $z['quiz_time_limit_s'],
                    'shuffle_questions' => (int) $z['quiz_shuffle_questions'] === 1,
                    'shuffle_options' => (int) $z['quiz_shuffle_options'] === 1,
                    'feedback_mode' => (string) $z['quiz_feedback_mode'],
                    'show_review' => (int) $z['quiz_show_review'] === 1,
                    'must_pass' => (int) $z['quiz_must_pass'] === 1,
                    'intro' => $intro === [] ? null : $intro,
                    'rules' => $rules,
                ];
                $expected = PoolResolver::totalDraw($res);
                $quizMeta[$luid] = ['quiz_id' => (int) $z['quiz_id'], 'lesson_id' => $lid, 'row' => $z, 'resolved' => $res];
            }

            $resDoc = [];
            foreach ($resources[$lid] ?? [] as $r) {
                $rl = $r['lres_lang'] === null ? null : (string) $r['lres_lang'];
                if ($rl !== null && !in_array($rl, $published, true)) {
                    continue;
                }
                $mid = $r['lres_media_id'] === null ? null : (int) $r['lres_media_id'];
                if ($mid !== null) {
                    $refs->add($mid, true, "resource:$luid");
                }
                $resDoc[] = [
                    'uid' => (string) $r['lres_uid'],
                    'lang' => $rl,
                    'kind' => (string) $r['lres_kind'],
                    'title' => (string) $r['lres_title'],
                    'media_id' => $mid,
                    'url' => $r['lres_url'] === null || $r['lres_url'] === '' ? null : (string) $r['lres_url'],
                ];
            }

            $thumb = $l['lesson_thumb_media_id'] === null ? null : (int) $l['lesson_thumb_media_id'];
            if ($thumb !== null) {
                $refs->add($thumb, false, "thumb:$luid");
            }

            $duration = $this->duration($l, $variants[$lid][$default] ?? null, $mediaInfo, $vchecks, $type === 'quiz' ? $expected : null,
                $type !== 'quiz' ? $expected : null);
            $totalSeconds += $duration;

            $lessonsDoc[] = [
                'uid' => $luid,
                'section_uid' => $l['lesson_section_id'] === null ? null : ($sectionUid[(int) $l['lesson_section_id']] ?? null),
                'type' => $type,
                'required' => (int) $l['lesson_required'] === 1,
                'duration_s' => $duration,
                'allow_download' => $allowDl,
                'preview_enabled' => (int) $l['lesson_preview_enabled'] === 1,
                'thumb_media_id' => $thumb,
                'min_watch_pct' => (int) $l['lesson_min_watch_pct'],
                'ack' => $type === 'acknowledgment'
                    ? ['require_signature' => (int) $l['lesson_ack_require_signature'] === 1, 'require_pin' => (int) $l['lesson_ack_require_pin'] === 1]
                    : null,
                'variants' => $vdoc,
                'resources' => $resDoc,
                'quiz' => $quizDoc,
            ];
            $lessonMeta[$luid] = [
                'id' => $lid,
                'type' => $type,
                'required' => (int) $l['lesson_required'] === 1,
                'section_id' => $l['lesson_section_id'] === null ? null : (int) $l['lesson_section_id'],
                'title' => (string) ($variants[$lid][$default]['lvar_title'] ?? ''),
                'variant_langs' => array_keys($variants[$lid] ?? []),
            ];
        }

        // --- questions and banks ----------------------------------------------------------------
        $questionsDoc = [];
        $qUidToId = [];
        $orderedQ = $questionIds;
        uasort($orderedQ, static fn($a, $b) => strcmp($a['uid'], $b['uid']));
        foreach ($orderedQ as $qid => $q) {
            $qdoc = QuestionRules::revisionDoc($q, $published);
            $questionsDoc[$q['uid']] = $qdoc;
            $qUidToId[$q['uid']] = $qid;
            if ($qdoc['media_id'] !== null) {
                $refs->add($qdoc['media_id'], false, 'question:' . $q['uid']);
            }
            foreach ($qdoc['text'] as $t) {
                if ($t['media_id'] !== null) {
                    $refs->add($t['media_id'], false, 'question:' . $q['uid']);
                }
            }
        }
        $bankDesc = [];
        foreach ($quizMeta as $qm) {
            foreach ($qm['resolved']['rules'] as $r) {
                $bankDesc[$r['bank_id']] = ($bankDesc[$r['bank_id']] ?? false) || $r['include_descendants'];
            }
        }
        $banks = [];
        foreach ($bankDesc as $bid => $desc) {
            $b = $tree->get($bid);
            if ($b === null) {
                continue;
            }
            $banks[] = [
                'sort' => (int) $b['qbank_sort'], 'id' => $bid,
                'doc' => ['uid' => (string) $b['qbank_uid'], 'name' => (string) $b['qbank_name'], 'path' => $tree->path($bid),
                    'sha256' => BankService::poolSha($db, $tree, $bid, $desc)],
            ];
        }
        usort($banks, static fn($a, $b) => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);

        // --- course --------------------------------------------------------------------------
        $cover = $course['course_cover_media_id'] === null ? null : (int) $course['course_cover_media_id'];
        if ($cover !== null) {
            $refs->add($cover, false, 'course:cover');
        }
        $courseText = [];
        foreach ($published as $L) {
            if ($L === $default) {
                $t = [
                    'name' => (string) $course['course_name'],
                    'summary' => QuestionRules::nullIfBlank($course['course_summary']),
                    'description_html' => QuestionRules::nullIfBlank($course['course_description_html']),
                    'attestation_text' => QuestionRules::nullIfBlank($course['course_attestation_text']),
                ];
            } else {
                $tr = $i18n['course'][$courseId][$L] ?? [];
                $t = [
                    'name' => QuestionRules::nullIfBlank($tr['name'] ?? null) ?? (string) $course['course_name'],
                    'summary' => QuestionRules::nullIfBlank($tr['summary'] ?? null),
                    'description_html' => QuestionRules::nullIfBlank($tr['description_html'] ?? null),
                    'attestation_text' => QuestionRules::nullIfBlank($tr['attestation_text'] ?? null),
                ];
            }
            if ($t['description_html'] !== null) {
                $refs->addHtml($t['description_html'], 'course:description');
            }
            $courseText[$L] = $t;
        }
        $sectionsDoc = [];
        foreach ($sections as $s) {
            $sid = (int) $s['csection_id'];
            $text = [];
            foreach ($published as $L) {
                $title = $L === $default ? (string) $s['csection_title']
                    : (QuestionRules::nullIfBlank($i18n['section'][$sid][$L]['title'] ?? null) ?? (string) $s['csection_title']);
                $text[$L] = ['title' => $title];
            }
            $sectionsDoc[] = ['uid' => (string) $s['csection_uid'], 'text' => $text];
        }

        // --- media manifest -------------------------------------------------------------------
        $refRows = $this->mediaRows($refs->ids());
        $manifest = [];
        $missing = [];
        foreach ($refs->all() as $id => $info) {
            if (!isset($refRows[$id])) {
                $missing[$id] = $info['where'];
                continue;
            }
            $m = $refRows[$id];
            $manifest[] = [
                'id' => $id,
                'sha256' => (string) $m['media_sha256'],
                'kind' => (string) $m['media_kind'],
                'ext' => (string) $m['media_ext'],
                'bytes' => (int) $m['media_bytes'],
                'dl' => $info['dl'],
            ];
        }
        usort($manifest, static fn($a, $b) => $a['id'] <=> $b['id']);

        $doc = [
            'schema' => self::SCHEMA,
            'course' => [
                'uid' => (string) $course['course_uid'],
                'kind' => (string) $course['course_kind'],
                'code' => QuestionRules::nullIfBlank($course['course_code']),
                'default_language' => $default,
                'languages' => $published,
                'cover_media_id' => $cover,
                'color' => QuestionRules::nullIfBlank($course['course_color']),
                'regulation_ref' => QuestionRules::nullIfBlank($course['course_regulation_ref']),
                'sequential' => (int) $course['course_sequential'] === 1,
                'est_minutes' => $course['course_est_minutes'] !== null ? (int) $course['course_est_minutes'] : intdiv($totalSeconds + 59, 60),
                'validity_months' => $course['course_validity_months'] === null ? null : (int) $course['course_validity_months'],
                'renewal_lead_days' => (int) $course['course_renewal_lead_days'],
                'requires_signature' => (int) $course['course_requires_signature'] === 1,
                'is_qualification' => (int) $course['course_is_qualification'] === 1,
                'components' => [
                    'online' => (int) $course['course_needs_online'] === 1,
                    'session' => (int) $course['course_needs_session'] === 1,
                    'practical' => (int) $course['course_needs_practical'] === 1,
                    'external_only' => (int) $course['course_external_only'] === 1,
                    'window_days' => (int) $course['course_component_window_days'],
                    'allow_trainer_attest' => (int) $course['course_allow_trainer_attest'] === 1,
                ],
                'eval_checklist' => self::checklist($course['course_eval_checklist']),
                'text' => $courseText,
            ],
            'sections' => $sectionsDoc,
            'lesson_order' => $lessonOrder,
            'lessons' => $lessonsDoc,
            'questions' => $questionsDoc,
            'banks' => array_map(static fn($b) => $b['doc'], $banks),
            'media' => $manifest,
        ];

        return [
            'doc' => $doc,
            'strict' => $strict,
            'course' => $course,
            'languages' => $published,
            'offered' => $langs['offered'],
            'required' => $langs['required'],
            'default_language' => $default,
            'excluded' => $excluded,
            'lesson_ids' => array_map(static fn($m) => $m['id'], $lessonMeta),
            'lessons' => $lessonMeta,
            'quizzes' => $quizMeta,
            'question_ids' => $qUidToId,
            'videos' => $videos,
            'vchecks' => $vchecks,
            'media_refs' => $refs->all(),
            'missing_media' => $missing,
            'draft_updated_at_utc' => $course['course_draft_updated_at_utc'],
        ];
    }

    private function variantDoc(array $l, array $v, array $mediaInfo, array $pages, array $vchecks, MediaRefCollector $refs,
                                bool $allowDl, string $luid, string $lang, array &$videos): array
    {
        $type = (string) $l['lesson_type'];
        $mid = $v['lvar_media_id'] === null ? null : (int) $v['lvar_media_id'];
        $provider = $v['lvar_video_provider'];
        $usesMedia = in_array($type, ['document', 'image'], true) || ($type === 'video' && $provider === 'upload');
        if (!$usesMedia) {
            $mid = null;
        }
        if ($mid !== null) {
            $isPdf = ($mediaInfo[$mid]['media_kind'] ?? null) === 'pdf';
            $refs->add($mid, $type === 'document' && $isPdf && $allowDl, "variant:$luid:$lang");
        }
        $pageList = null;
        if ($type === 'document') {
            $pageList = [];
            if ($mid !== null) {
                foreach ($pages[$mid] ?? [] as $p) {
                    $pageList[] = $p;
                    $refs->add($p['media_id'], false, "page:$luid:$lang");
                }
            }
        }
        $video = null;
        if ($type === 'video') {
            if ($provider === 'upload' && $mid !== null) {
                $ms = $mediaInfo[$mid]['media_duration_ms'] ?? null;
                $video = ['provider' => 'upload', 'media_id' => $mid, 'duration_s' => $ms === null ? 0 : intdiv((int) $ms + 999, 1000)];
            } elseif (in_array($provider, ['youtube', 'vimeo'], true) && (string) $v['lvar_video_ext_id'] !== '') {
                $extId = (string) $v['lvar_video_ext_id'];
                $hash = (string) ($v['lvar_video_ext_hash'] ?? '');
                $vc = $vchecks[self::videoKey($provider, $extId, $hash)] ?? null;
                [$dur, $src] = self::videoDuration($vc);
                $video = $provider === 'youtube'
                    ? ['provider' => 'youtube', 'id' => $extId, 'duration_s' => $dur, 'duration_source' => $src]
                    : ['provider' => 'vimeo', 'id' => $extId, 'h' => $hash === '' ? null : $hash, 'duration_s' => $dur, 'duration_source' => $src];
                if ($vc !== null && $vc['vcheck_thumb_media_id'] !== null) {
                    $refs->add((int) $vc['vcheck_thumb_media_id'], false, "video_thumb:$luid:$lang");
                }
                $videos[] = ['provider' => $provider, 'id' => $extId, 'hash' => $hash, 'lesson_id' => (int) $l['lesson_id'],
                    'lesson_uid' => $luid, 'lang' => $lang];
            }
        }
        $desc = QuestionRules::nullIfBlank($v['lvar_description_html']);
        $body = in_array($type, ['article', 'acknowledgment'], true) ? QuestionRules::nullIfBlank($v['lvar_body_html']) : null;
        if ($desc !== null) {
            $refs->addHtml($desc, "description:$luid:$lang");
        }
        if ($body !== null) {
            $refs->addHtml($body, "body:$luid:$lang");
        }
        return [
            'title' => (string) $v['lvar_title'],
            'description_html' => $desc,
            'body_html' => $body,
            'media_id' => $mid,
            'pages' => $pageList,
            'caption' => $type === 'image' ? QuestionRules::nullIfBlank($v['lvar_caption']) : null,
            'video' => $video,
            'kb_source' => $v['lvar_kb_source_article_id'] === null ? null
                : ['article_id' => (int) $v['lvar_kb_source_article_id'], 'sha256' => (string) ($v['lvar_kb_source_sha256'] ?? '')],
        ];
    }

    private function duration(array $l, ?array $variant, array $mediaInfo, array $vchecks, ?int $quizQ, ?int $checkQ): int
    {
        if ($l['lesson_duration_s'] !== null) {
            return (int) $l['lesson_duration_s'];
        }
        $v = $variant ?? [];
        $vc = null;
        if ($variant !== null) {
            $mid = $variant['lvar_media_id'] === null ? null : (int) $variant['lvar_media_id'];
            if ($mid !== null && isset($mediaInfo[$mid])) {
                $v['media_kind'] = $mediaInfo[$mid]['media_kind'];
                $v['media_page_count'] = $mediaInfo[$mid]['media_page_count'] === null ? null : (int) $mediaInfo[$mid]['media_page_count'];
                $v['media_duration_ms'] = $mediaInfo[$mid]['media_duration_ms'] === null ? null : (int) $mediaInfo[$mid]['media_duration_ms'];
            }
            if (in_array($variant['lvar_video_provider'], ['youtube', 'vimeo'], true)) {
                $vc = $vchecks[self::videoKey((string) $variant['lvar_video_provider'], (string) $variant['lvar_video_ext_id'],
                    (string) ($variant['lvar_video_ext_hash'] ?? ''))] ?? null;
            }
        }
        return max(0, (int) DurationEstimator::forLesson($l, $v, $quizQ, $checkQ, $vc));
    }

    /** @return array{0:int, 1:?string} seconds and source (oembed | data_api | play) */
    public static function videoDuration(?array $vc): array
    {
        if ($vc === null) {
            return [0, null];
        }
        if ($vc['vcheck_meta_duration_s'] !== null && (int) $vc['vcheck_meta_duration_s'] > 0) {
            return [(int) $vc['vcheck_meta_duration_s'], (string) ($vc['vcheck_meta_duration_source'] ?? 'oembed')];
        }
        if ($vc['vcheck_play_duration_s'] !== null && (int) $vc['vcheck_play_duration_s'] > 0) {
            return [(int) $vc['vcheck_play_duration_s'], 'play'];
        }
        return [0, null];
    }

    public static function videoKey(string $provider, string $extId, string $hash): string
    {
        return $provider . ':' . $extId . ':' . $hash;
    }

    /**
     * Whether a variant has everything its lesson type needs (used for language completeness).
     */
    public static function variantComplete(array $lesson, ?array $v, array $mediaKinds = []): bool
    {
        if ($v === null || trim((string) $v['lvar_title']) === '') {
            return false;
        }
        $html = static function (?string $h): bool {
            if ($h === null) {
                return false;
            }
            // Same emptiness rule as Authoring's LessonIssues (non-breaking spaces are blank), so a
            // language this build publishes is never flagged translation_incomplete by the validator.
            return trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== ''
                || stripos($h, '<img') !== false;
        };
        $media = static function (string $kind) use ($v, $mediaKinds): bool {
            if ($v['lvar_media_id'] === null) {
                return false;
            }
            return $mediaKinds === [] || ($mediaKinds[(int) $v['lvar_media_id']] ?? null) === $kind;
        };
        return match ((string) $lesson['lesson_type']) {
            'article', 'acknowledgment' => $html($v['lvar_body_html']),
            'document' => $media('pdf'),
            'image' => $media('image'),
            'video' => ($v['lvar_video_provider'] === 'upload' && $media('video'))
                || (in_array($v['lvar_video_provider'], ['youtube', 'vimeo'], true) && (string) $v['lvar_video_ext_id'] !== ''),
            default => true,
        };
    }

    /** "one per line, start with * for critical" => [{item, critical}] */
    public static function checklist(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string) $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $critical = str_starts_with($line, '*');
            $item = trim($critical ? ltrim($line, '*') : $line);
            if ($item !== '') {
                $out[] = ['item' => $item, 'critical' => $critical];
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function mediaRows(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$ph, $t, $p] = InList::ints($chunk);
            foreach (Db::all($this->c->db, "SELECT media_id, media_sha256, media_kind, media_ext, media_bytes, media_page_count, media_duration_ms,
                    media_width, media_height, media_path FROM training_media WHERE media_id IN ($ph)", $t, $p) as $r) {
                $out[(int) $r['media_id']] = $r;
            }
        }
        return $out;
    }

    /** @return array<string, array<int, array<string, array<string, string>>>> entity => id => lang => field => value */
    private function i18n(string $entity, array $ids): array
    {
        $out = [$entity => []];
        if ($ids === []) {
            return $out;
        }
        [$ph, $t, $p] = InList::ints($ids);
        foreach (Db::all($this->c->db, "SELECT ti18n_entity_id, ti18n_lang, ti18n_field, ti18n_value FROM training_i18n
                WHERE ti18n_entity = ? AND ti18n_entity_id IN ($ph)", 's' . $t, array_merge([$entity], $p)) as $r) {
            $out[$entity][(int) $r['ti18n_entity_id']][(string) $r['ti18n_lang']][(string) $r['ti18n_field']] = (string) $r['ti18n_value'];
        }
        return $out;
    }
}

