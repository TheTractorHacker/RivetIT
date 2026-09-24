<?php

namespace ITFlow\Training\Preview;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\ArticleSanitizer;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Media\VideoLink;
use ITFlow\Training\Publish\RevisionBuilder;
use ITFlow\Training\Publish\RevisionRepository;
use ITFlow\Training\Quiz\Guard;
use ITFlow\Training\Quiz\InList;
use ITFlow\Training\Quiz\QuizDraw;

/**
 * LearnerView v1 (spec §3.7): the one-language projection of a revision (or a draft build) that
 * the preview player - and the Phase 3 kiosk - renders. It never contains the answer key: no
 * correct flags, points, critical flags, explanations, option feedback, pools, bank data, hashes
 * or KB sources; every payload passes PayloadGuard before it leaves the server.
 *
 * Authored HTML is purified again here, at projection, with the revision's own media manifest
 * deciding which training media an <img> or link may point at.
 */
final class LearnerView
{
    public const ENDPOINT = '/agent/training_ajax.php';

    /**
     * @param array $build RevisionBuilder::build() result, or ['doc' => revision JSON, 'lesson_ids' => uid => id]
     * @param array $ctx   source ('draft'|'revision'), course_id, revision_id, revision_number, can_verify_video,
     *                     can_grade, base_url, video_checks (key "provider:id:hash" => {verified:bool}),
     *                     media (id => {w, h}), attestation_default (?string)
     */
    public static function fromBuild(array $build, string $lang, array $ctx): array
    {
        $doc = $build['doc'];
        $course = $doc['course'];
        $default = (string) $course['default_language'];
        $languages = array_values($course['languages']);
        if (!in_array($lang, $languages, true)) {
            $lang = $default;
        }
        $kinds = [];
        foreach ($doc['media'] as $m) {
            $kinds[(int) $m['id']] = (string) $m['kind'];
        }
        $purify = static function (?string $html) use ($kinds): ?string {
            if ($html === null || trim($html) === '') {
                return null;
            }
            $out = ArticleSanitizer::purify($html, static fn(int $id): ?string => $kinds[$id] ?? null);
            $clean = is_array($out) ? (string) ($out['html'] ?? '') : (string) $out;
            return trim($clean) === '' ? null : $clean;
        };
        $pick = static fn(?array $map) => $map === null ? null : ($map[$lang] ?? $map[$default] ?? (reset($map) ?: null));
        $lessonIds = $build['lesson_ids'] ?? [];
        $dims = $ctx['media'] ?? [];
        $baseUrl = (string) ($ctx['base_url'] ?? '');

        $ct = $pick($course['text']);
        $courseOut = [
            'uid' => (string) $course['uid'],
            'kind' => (string) $course['kind'],
            'name' => (string) ($ct['name'] ?? ''),
            'summary' => $ct['summary'] ?? null,
            'description_html' => $purify($ct['description_html'] ?? null),
            'cover_url' => $course['cover_media_id'] === null ? null : MediaStore::url((int) $course['cover_media_id']),
            'color' => $course['color'],
            'est_minutes' => (int) $course['est_minutes'],
            'sequential' => (bool) $course['sequential'],
            'requires_signature' => (bool) $course['requires_signature'],
            'attestation_text' => $ct['attestation_text'] ?? ($ctx['attestation_default'] ?? null),
        ];

        $lessonsBySection = [];
        $lessons = [];
        foreach ($doc['lessons'] as $l) {
            $uid = (string) $l['uid'];
            $v = $pick($l['variants'] ?: null) ?? [];
            $vLang = isset($l['variants'][$lang]) ? $lang : $default;
            $type = (string) $l['type'];
            $mediaId = $v['media_id'] ?? null;

            $video = null;
            if ($type === 'video' && ($v['video'] ?? null) !== null) {
                $vd = $v['video'];
                if ($vd['provider'] === 'upload') {
                    $video = ['provider' => 'upload', 'src_url' => MediaStore::url((int) $vd['media_id']),
                        'duration_s' => (int) $vd['duration_s'], 'min_watch_pct' => (int) $l['min_watch_pct']];
                } else {
                    $hash = $vd['provider'] === 'vimeo' ? ($vd['h'] ?? null) : null;
                    $key = RevisionBuilder::videoKey((string) $vd['provider'], (string) $vd['id'], (string) ($hash ?? ''));
                    try {
                        $embed = VideoLink::embedUrl(['provider' => (string) $vd['provider'], 'id' => (string) $vd['id'], 'hash' => (string) ($hash ?? '')], $baseUrl);
                    } catch (\InvalidArgumentException) {
                        $embed = null; // never render a URL built from an id that fails validation
                    }
                    $video = $embed === null ? null : [
                        'provider' => (string) $vd['provider'],
                        'embed_url' => $embed,
                        'video_id' => (string) $vd['id'],
                        'duration_s' => ((int) ($vd['duration_s'] ?? 0)) > 0 ? (int) $vd['duration_s'] : null,
                        'min_watch_pct' => (int) $l['min_watch_pct'],
                        'verified' => (bool) ($ctx['video_checks'][$key]['verified'] ?? false),
                    ];
                }
            }

            $quiz = null;
            if (($l['quiz'] ?? null) !== null) {
                $z = $l['quiz'];
                $quiz = [
                    'uid' => (string) $z['uid'],
                    'role' => (string) $z['role'],
                    'question_count' => QuizDraw::expectedCount($z, $doc['questions'] ?: []),
                    'pass_pct' => (int) $z['pass_pct'],
                    'max_attempts' => (int) $z['max_attempts'],
                    'time_limit_s' => $z['time_limit_s'] === null ? null : (int) $z['time_limit_s'],
                    'show_review' => (bool) $z['show_review'],
                    'must_pass' => (bool) $z['must_pass'],
                    'intro' => $z['intro'] === null ? null : ($z['intro'][$lang] ?? $z['intro'][$default] ?? null),
                ];
            }

            $resources = [];
            foreach ($l['resources'] as $r) {
                if ($r['lang'] !== null && $r['lang'] !== $vLang) {
                    continue;
                }
                $url = $r['kind'] === 'link' ? $r['url'] : ($r['media_id'] === null ? null : MediaStore::url((int) $r['media_id'], true));
                if ($url === null) {
                    continue;
                }
                $resources[] = ['uid' => (string) $r['uid'], 'kind' => (string) $r['kind'], 'title' => (string) $r['title'], 'url' => (string) $url];
            }

            $pages = [];
            if ($type === 'document') {
                foreach ($v['pages'] ?? [] as $p) {
                    $pages[] = ['n' => (int) $p['n'], 'url' => MediaStore::url((int) $p['media_id']), 'w' => $p['w'], 'h' => $p['h']];
                }
            }

            $lessons[] = [
                'uid' => $uid,
                'id' => isset($lessonIds[$uid]) ? (int) $lessonIds[$uid] : null,
                'type' => $type,
                'title' => (string) ($v['title'] ?? ''),
                'description_html' => $purify($v['description_html'] ?? null),
                'required' => (bool) $l['required'],
                'duration_s' => (int) $l['duration_s'],
                'preview_enabled' => (bool) $l['preview_enabled'],
                'article' => $type === 'article' ? ['body_html' => $purify($v['body_html'] ?? null)] : null,
                'document' => $type === 'document' ? [
                    'page_count' => count($pages),
                    'pages' => $pages,
                    'download_url' => ($l['allow_download'] && $mediaId !== null) ? MediaStore::url((int) $mediaId, true) : null,
                ] : null,
                'video' => $video,
                'image' => ($type === 'image' && $mediaId !== null) ? [
                    'url' => MediaStore::url((int) $mediaId),
                    'w' => $dims[(int) $mediaId]['w'] ?? null,
                    'h' => $dims[(int) $mediaId]['h'] ?? null,
                    'caption' => $v['caption'] ?? null,
                ] : null,
                'ack' => $type === 'acknowledgment' ? [
                    'statement_html' => $purify($v['body_html'] ?? null),
                    'require_signature' => (bool) ($l['ack']['require_signature'] ?? true),
                    'require_pin' => (bool) ($l['ack']['require_pin'] ?? true),
                ] : null,
                'quiz' => $quiz,
                'resources' => $resources,
            ];
            $lessonsBySection[$l['section_uid'] ?? ''][] = $uid;
        }

        $sections = [];
        foreach ($doc['sections'] as $s) {
            $sections[] = [
                'uid' => (string) $s['uid'],
                'title' => (string) (($s['text'][$lang] ?? $s['text'][$default] ?? ['title' => ''])['title']),
                'lesson_uids' => $lessonsBySection[$s['uid']] ?? [],
            ];
        }

        $view = [
            'mode' => 'preview',
            'source' => [
                'type' => ($ctx['source'] ?? 'draft') === 'revision' ? 'revision' : 'draft',
                'course_id' => (int) ($ctx['course_id'] ?? 0),
                'revision_id' => isset($ctx['revision_id']) ? (int) $ctx['revision_id'] : null,
                'revision_number' => isset($ctx['revision_number']) ? (int) $ctx['revision_number'] : null,
            ],
            'lang' => $lang,
            'languages' => $languages,
            'can_verify_video' => (bool) ($ctx['can_verify_video'] ?? false),
            'can_grade' => (bool) ($ctx['can_grade'] ?? false),
            'course' => $courseOut,
            'sections' => $sections,
            'lesson_order' => array_values($doc['lesson_order']),
            'lessons' => $lessons,
            'endpoints' => [
                'quiz_start' => self::ENDPOINT . '?action=preview_quiz_start',
                'quiz_submit' => self::ENDPOINT . '?action=preview_quiz_submit',
                'video_verify' => self::ENDPOINT . '?action=video_verify',
            ],
            'strings' => PreviewStrings::for($lang),
        ];
        PayloadGuard::assert($view, 'learner_view');
        return $view;
    }

    /**
     * Everything agent/training_preview.php needs in one call: loads the draft build or the
     * revision (with the level rules below), then projects it.
     *   draft     level 2+; strict=false build (every offered language)
     *   revision  level 1+; $revisionId null = the current version; must belong to $courseId;
     *             an archived course's versions are visible to level 2+ only
     */
    public static function forPreview(Ctx $c, string $source, int $courseId, ?int $revisionId, ?string $lang): array
    {
        $src = self::load($c, $source, $courseId, $revisionId);
        $doc = $src['doc'];
        return self::fromBuild($src, $lang ?? (string) $doc['course']['default_language'], [
            'source' => $src['source'],
            'course_id' => $courseId,
            'revision_id' => $src['revision_id'],
            'revision_number' => $src['revision_number'],
            'can_verify_video' => $c->level >= 2,
            'can_grade' => $c->level >= 2,
            'base_url' => $c->baseUrl,
            'video_checks' => self::videoChecks($c->db, $doc),
            'media' => self::imageDims($c->db, $doc),
            'attestation_default' => $c->settings->attestationDefault,
        ]);
    }

    /**
     * The document a preview runs against: {source, doc, lesson_ids, revision_id, revision_number}.
     */
    public static function load(Ctx $c, string $source, int $courseId, ?int $revisionId): array
    {
        $db = $c->db;
        if ($source === 'draft') {
            if ($c->level < 2) {
                throw ApiException::forbidden('Previewing a draft is available to course authors.');
            }
            Guard::course($db, $courseId);
            $build = (new RevisionBuilder($c))->build($courseId, false);
            return ['source' => 'draft', 'doc' => $build['doc'], 'lesson_ids' => $build['lesson_ids'], 'revision_id' => null, 'revision_number' => null];
        }
        if ($source !== 'revision') {
            throw ApiException::validation(['source' => 'Not a valid choice.']);
        }
        $course = Guard::course($db, $courseId);
        if ($course['course_archived_at'] !== null && $c->level < 2) {
            throw ApiException::notFound('That course no longer exists.');
        }
        $repo = new RevisionRepository($c);
        if ($revisionId === null) {
            $rev = $repo->current($courseId);
            if ($rev === null) {
                throw ApiException::notFound('This course has not been published yet.');
            }
        } else {
            $rev = $repo->get($revisionId);
            if ($rev['course_id'] !== $courseId) {
                throw ApiException::notFound('That version no longer exists.');
            }
        }
        return ['source' => 'revision', 'doc' => $rev['doc'], 'lesson_ids' => self::lessonIds($db, $courseId, $rev['doc']),
            'revision_id' => $rev['id'], 'revision_number' => $rev['number']];
    }

    /** @return array<string, int> lesson uid => id for lessons of the course that still exist */
    private static function lessonIds(\mysqli $db, int $courseId, array $doc): array
    {
        $uids = array_map(static fn($l) => (string) $l['uid'], $doc['lessons']);
        if ($uids === []) {
            return [];
        }
        [$ph, $t, $p] = InList::strings($uids);
        $out = [];
        foreach (Db::all($db, "SELECT lesson_id, lesson_uid FROM training_lessons WHERE lesson_course_id = ? AND lesson_uid IN ($ph)",
                'i' . $t, array_merge([$courseId], $p)) as $r) {
            $out[(string) $r['lesson_uid']] = (int) $r['lesson_id'];
        }
        return $out;
    }

    /** Current verification state of every external video in $doc, keyed "provider:id:hash". */
    public static function videoChecks(\mysqli $db, array $doc): array
    {
        $ids = [];
        foreach ($doc['lessons'] as $l) {
            foreach ($l['variants'] ?: [] as $v) {
                if (($v['video']['provider'] ?? 'upload') !== 'upload') {
                    $ids[] = (string) $v['video']['id'];
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        [$ph, $t, $p] = InList::strings($ids);
        $out = [];
        $now = time();
        foreach (Db::all($db, "SELECT vcheck_provider, vcheck_ext_id, vcheck_ext_hash, vcheck_verified_at_utc, vcheck_last_error_at_utc
                FROM training_video_checks WHERE vcheck_ext_id IN ($ph)", $t, $p) as $r) {
            $v = Clock::toIso($r['vcheck_verified_at_utc'], true);
            $e = Clock::toIso($r['vcheck_last_error_at_utc'], true);
            $vt = $v === null ? null : strtotime($v);
            $et = $e === null ? null : strtotime($e);
            $out[RevisionBuilder::videoKey((string) $r['vcheck_provider'], (string) $r['vcheck_ext_id'], (string) $r['vcheck_ext_hash'])] = [
                'verified' => $vt !== null && $now - $vt <= 30 * 86400 && ($et === null || $et <= $vt),
            ];
        }
        return $out;
    }

    /** Width/height of image-lesson media: id => {w, h}. */
    public static function imageDims(\mysqli $db, array $doc): array
    {
        $ids = [];
        foreach ($doc['lessons'] as $l) {
            if ($l['type'] !== 'image') {
                continue;
            }
            foreach ($l['variants'] ?: [] as $v) {
                if (($v['media_id'] ?? null) !== null) {
                    $ids[] = (int) $v['media_id'];
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        [$ph, $t, $p] = InList::ints($ids);
        $out = [];
        foreach (Db::all($db, "SELECT media_id, media_width, media_height FROM training_media WHERE media_id IN ($ph)", $t, $p) as $r) {
            $out[(int) $r['media_id']] = [
                'w' => $r['media_width'] === null ? null : (int) $r['media_width'],
                'h' => $r['media_height'] === null ? null : (int) $r['media_height'],
            ];
        }
        return $out;
    }
}
