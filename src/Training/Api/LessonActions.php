<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Authoring\LessonService;
use ITFlow\Training\Authoring\ResourceService;
use ITFlow\Training\Core\Ctx;

/**
 * JSON handlers for lessons (content) and their resources (Routes/authoring.php).
 * Autosave patches are not written to the app log; duplicates and deletes are.
 */
final class LessonActions
{
    public static function lessonCreate(Ctx $c, ApiContext $a): array
    {
        return (new LessonService($c))->create(
            (int) $a->int('course_id', true, 1),
            $a->int('section_id', false, 1),
            (string) $a->enum('type', ['article', 'document', 'video', 'image', 'quiz', 'acknowledgment']),
            (string) $a->lang('lang'),
            $a->str('title', 200, false, true),
            $a->int('media_id', false, 1),
            $a->str('video_check_token', 64, false),
            $a->int('after_lesson_id', false, 1)
        );
    }

    public static function lessonGet(Ctx $c, ApiContext $a): array
    {
        return (new LessonService($c))->get((int) $a->int('lesson_id', true, 1));
    }

    public static function lessonUpdate(Ctx $c, ApiContext $a): array
    {
        return (new LessonService($c))->update(
            (int) $a->int('lesson_id', true, 1),
            (int) $a->int('version', true, 0),
            $a->fields('fields', LessonService::UPDATE_FIELDS),
            (string) $a->lang('lang')
        );
    }

    public static function lessonSetType(Ctx $c, ApiContext $a): array
    {
        return (new LessonService($c))->setType(
            (int) $a->int('lesson_id', true, 1),
            (int) $a->int('version', true, 0),
            (string) $a->enum('type', ['article', 'document', 'video', 'image', 'quiz', 'acknowledgment'])
        );
    }

    public static function lessonDuplicate(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('lesson_id', true, 1);
        $detail = (new LessonService($c))->duplicate($id);
        CourseActions::log('Create', "Duplicated training lesson #$id as #" . $detail['id'], $detail['id']);
        return $detail;
    }

    public static function lessonDelete(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('lesson_id', true, 1);
        (new LessonService($c))->delete($id);
        CourseActions::log('Delete', "Deleted training lesson #$id", $id);
        return [];
    }

    public static function lessonRestore(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('lesson_id', true, 1);
        $svc = new LessonService($c);
        $svc->restore($id);
        CourseActions::log('Edit', "Restored training lesson #$id", $id);
        return $svc->get($id);
    }

    public static function lessonCopyVariant(Ctx $c, ApiContext $a): array
    {
        return (new LessonService($c))->copyVariant(
            (int) $a->int('lesson_id', true, 1),
            (string) $a->lang('from'),
            (string) $a->lang('to'),
            (bool) $a->bool('overwrite', false)
        );
    }

    // ---- resources ------------------------------------------------------------------------------

    public static function resourceAdd(Ctx $c, ApiContext $a): array
    {
        return (new ResourceService($c))->add(
            (int) $a->int('lesson_id', true, 1),
            (string) $a->enum('kind', ['file', 'link']),
            (string) $a->str('title', 200),
            $a->int('media_id', false, 1),
            $a->str('url', 500, false),
            $a->lang('lang', false)
        );
    }

    public static function resourceUpdate(Ctx $c, ApiContext $a): array
    {
        return (new ResourceService($c))->update((int) $a->int('resource_id', true, 1), $a->fields('fields', ['title', 'lang', 'url']));
    }

    public static function resourceDelete(Ctx $c, ApiContext $a): array
    {
        (new ResourceService($c))->delete((int) $a->int('resource_id', true, 1));
        return [];
    }

    public static function resourcesReorder(Ctx $c, ApiContext $a): array
    {
        (new ResourceService($c))->reorder((int) $a->int('lesson_id', true, 1), $a->ints('ids'));
        return [];
    }
}
