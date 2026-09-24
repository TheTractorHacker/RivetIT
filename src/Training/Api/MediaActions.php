<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Authoring\LessonService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\CoverLibrary;
use ITFlow\Training\Media\KbSnapshot;
use ITFlow\Training\Media\MediaAccess;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Media\PdfRenderService;
use ITFlow\Training\Media\VideoCheckService;

/**
 * JSON actions of the media lane (spec §6.2), dispatched by the Router from Routes/media.php.
 * The Router has already enforced the module toggle, method, CSRF (POST), the route's level and
 * - for kb_* - Knowledge Base access, and has closed the session.
 *
 * Media services throw MediaException; every handler converts it to the Router's ApiException
 * so the client gets the same envelope and codes as everywhere else.
 */
final class MediaActions
{
    /** GET media_get (L2): one media row in the shared Media shape, plus pages for a PDF. */
    public static function mediaGet(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            $store = new MediaStore($c);
            $row = $store->get((int) $a->int('media_id', true, 1));
            if ($row === null || !MediaAccess::canServe($c, $row, false)) {
                throw ApiException::notFound('That file was not found.');
            }
            $out = ['media' => $store->toApi($row)];
            if ($row['media_kind'] === 'pdf') {
                $out['pages'] = (new PdfRenderService($c, $store))->pages((int) $row['media_id']);
            }
            return $out;
        });
    }

    /** POST pdf_render_next (L2): renders the next batch of a PDF's pages (<= 8 pages / 25 s). */
    public static function pdfRenderNext(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            return (new PdfRenderService($c))->renderNext((int) $a->int('media_id', true, 1));
        });
    }

    /** POST video_link_check (L2): parse + oEmbed (+ Data API) + thumbnail, returns a check token. */
    public static function videoLinkCheck(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            return (new VideoCheckService($c))->check((string) $a->str('url', 2048));
        });
    }

    /** POST video_verify (L2): the author played the video (ok) or the player reported an error. */
    public static function videoVerify(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            $ok = $a->bool('ok');
            if ($ok === null) {
                throw ApiException::validation(['ok' => 'Required.']);
            }
            $check = (new VideoCheckService($c))->verify(
                (string) $a->enum('provider', ['youtube', 'vimeo']),
                (string) $a->str('ext_id', 20),
                (string) ($a->str('ext_hash', 20, false, true) ?? ''),
                $ok ? (int) $a->int('duration_s', true, 1, 172800) : (int) ($a->int('duration_s', false, 0, 172800) ?? 0),
                $ok,
                $a->str('error_code', 40, false)
            );
            return ['check' => $check];
        });
    }

    /** GET kb_search (L2 + KB): articles the author may read, matching q (>= 2 characters). */
    public static function kbSearch(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            $q = (string) $a->str('q', 200);
            if (mb_strlen($q, 'UTF-8') < 2) {
                throw ApiException::validation(['q' => 'Type at least 2 characters.']);
            }
            return ['articles' => KbSnapshot::search($c, $q, 20)];
        });
    }

    /**
     * POST kb_import (L2 + KB): snapshots a KB article into an article lesson's language variant.
     * The media copies happen first (outside any transaction), then the lesson service applies
     * the result in its own transaction (409 kb_local_edits unless confirm_overwrite_edits).
     */
    public static function kbImport(Ctx $c, ApiContext $a): array
    {
        return self::run(static function () use ($c, $a): array {
            $lessonId = (int) $a->int('lesson_id', true, 1);
            $version = (int) $a->int('version', true, 0);
            $lang = (string) $a->lang();
            $articleId = (int) $a->int('kb_article_id', true, 1);
            $confirm = (bool) $a->bool('confirm_overwrite_edits', false);

            $lesson = Db::one($c->db, 'SELECT l.lesson_type, l.lesson_version, l.lesson_archived_at, co.course_archived_at
                FROM training_lessons l JOIN training_courses co ON co.course_id = l.lesson_course_id WHERE l.lesson_id = ?', 'i', [$lessonId]);
            if ($lesson === null || $lesson['lesson_archived_at'] !== null) {
                throw ApiException::notFound('That lesson was not found.');
            }
            if ($lesson['course_archived_at'] !== null) {
                throw ApiException::archived();
            }
            if ($lesson['lesson_type'] !== 'article') {
                throw ApiException::validation(['lesson_id' => 'Only article lessons can import from the Knowledge Base.']);
            }
            $lessons = new LessonService($c);
            // Checked before any media is copied, so a stale window does not import for nothing.
            if ((int) $lesson['lesson_version'] !== $version) {
                throw ApiException::conflict($lessons->get($lessonId));
            }

            // Images up to 16 MP are decoded while copying (the upload endpoint allows the same).
            ini_set('memory_limit', '384M');
            $import = KbSnapshot::import($c, $articleId, new MediaStore($c));
            $detail = $lessons->applyImportedArticle($lessonId, $lang, $import, $confirm);
            return ['lesson' => $detail, 'warnings' => $import['warnings']];
        });
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    /** GET cover_presets (L2): the built-in cover gallery - covers, categories, tints, defaults. */
    public static function coverPresets(Ctx $c, ApiContext $a): array
    {
        return CoverLibrary::api();
    }

    /**
     * POST cover_preset_ingest (L2): stores a gallery cover as media (deduplicated) and returns it.
     * The caller then sets it with course_update / path_save {cover_media_id, color}, so the
     * version check and the draft change log stay in one place.
     */
    public static function coverPresetIngest(Ctx $c, ApiContext $a): array
    {
        $key = (string) $a->str('key', 40);
        $cover = CoverLibrary::get($key);
        if ($cover === null) {
            throw ApiException::validation(['key' => 'That cover does not exist.']);
        }
        return self::run(static function () use ($c, $key, $cover): array {
            $row = CoverLibrary::ingest($c, $key);
            return ['media' => (new MediaStore($c))->toApi($row), 'key' => $key, 'color' => $cover['color']];
        });
    }

    private static function run(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (MediaException $e) {
            throw $e->toApi();
        }
    }
}
