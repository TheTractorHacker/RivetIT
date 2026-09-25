<?php

namespace ITFlow\Training\Api;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Authoring\CategoryService;
use ITFlow\Training\Authoring\CourseDuplicator;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\Guard;
use ITFlow\Training\Authoring\OutlineService;
use ITFlow\Training\Authoring\Patch;
use ITFlow\Training\Authoring\SectionService;
use ITFlow\Training\Authoring\TagService;
use ITFlow\Training\Authoring\TemplateCatalog;
use ITFlow\Training\Authoring\UserDirectory;
use ITFlow\Training\Catalog\PrereqService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Media\CoverLibrary;

/**
 * JSON handlers for courses, sections, the outline, templates, categories, tags and the user
 * picker (Routes/authoring.php). Handlers validate the request through ApiContext, call one
 * service, and only AFTER the service's transaction has committed write the app log
 * (logAction) and, for archive, the audit trail - both best-effort, so a logging failure never
 * turns a committed change into an error.
 *
 * Per-field autosaves are not written to the app log (they would flood it); creates, copies,
 * archive/restore, deletes and category changes are.
 */
final class CourseActions
{
    public static function builderUrl(int $courseId): string
    {
        return '/agent/training_course.php?course_id=' . $courseId;
    }

    // ---- courses ------------------------------------------------------------------------------

    public static function courseList(Ctx $c, ApiContext $a): array
    {
        return (new CourseService($c))->list([
            'q' => $a->str('q', 100, false),
            'kind' => $a->enum('kind', ['training', 'document'], false),
            'status' => $a->enum('status', CourseService::LIST_STATUSES, false),
            'category_id' => $a->int('category_id', false, 1),
            'tag_ids' => $a->ints('tag_ids'),
            'mine' => $a->bool('mine', false),
            'sort' => $a->enum('sort', CourseService::LIST_SORTS, false),
        ]);
    }

    public static function courseCreate(Ctx $c, ApiContext $a): array
    {
        $languages = $a->arr('languages', false);
        if (!array_is_list($languages)) {
            throw ApiException::validation(['languages' => 'Must be a list of languages.']);
        }
        $name = (string) $a->str('name', 200);
        $id = (new CourseService($c))->create(
            (string) $a->enum('kind', ['training', 'document']),
            $name,
            $a->int('category_id', false, 1),
            $languages,
            $a->str('template_key', 40, false),
            $a->str('cover_key', 40, false),
            $a->has('color') ? Patch::color(['color' => $a->str('color', 7, false)], 'color') : null
        );
        self::log('Create', "Created training course '$name'", $id);
        return ['course_id' => $id, 'url' => self::builderUrl($id)];
    }

    public static function courseGet(Ctx $c, ApiContext $a): array
    {
        return (new CourseService($c))->get((int) $a->int('course_id', true, 1));
    }

    public static function courseUpdate(Ctx $c, ApiContext $a): array
    {
        return (new CourseService($c))->update(
            (int) $a->int('course_id', true, 1),
            (int) $a->int('version', true, 0),
            $a->fields('fields', CourseService::UPDATE_FIELDS),
            $a->lang('lang', false)
        );
    }

    public static function courseSetLanguages(Ctx $c, ApiContext $a): array
    {
        $languages = self::stringList($a, 'languages');
        $required = self::stringList($a, 'required');
        $course = (new CourseService($c))->setLanguages(
            (int) $a->int('course_id', true, 1),
            $languages,
            $required,
            $a->lang('default_language', false)
        );
        return ['course' => $course];
    }

    public static function courseDuplicate(Ctx $c, ApiContext $a): array
    {
        $from = (int) $a->int('course_id', true, 1);
        $id = (new CourseDuplicator($c))->duplicate($from, $a->str('name', 200, false), (bool) $a->bool('as_training', false));
        self::log('Create', "Duplicated training course #$from as #$id", $id);
        return ['course_id' => $id, 'url' => self::builderUrl($id)];
    }

    public static function courseArchive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('course_id', true, 1);
        $reason = $a->str('reason', 500, false);
        $name = (string) Guard::course($c->db, $id)['course_name'];
        (new CourseService($c))->archive($id, $reason);
        self::log('Archive', "Archived training course '$name'", $id);
        self::audit($c, 'training.course_archived', $id, 'archived', "Archived training course '$name'", ['reason' => $reason]);
        // Phase 2 (S18): open assignments for an archived course are cancelled (system actor).
        try { (new \ITFlow\Training\Assign\AssignmentService($c))->reconcile(null, 'course_archived'); }
        catch (\Throwable $e) { error_log('Training: reconcile after course archive failed: ' . $e->getMessage()); }
        return ['status' => 'archived'];
    }

    public static function courseRestore(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('course_id', true, 1);
        (new CourseService($c))->restore($id);
        $course = Guard::course($c->db, $id);
        self::log('Edit', "Restored training course '" . $course['course_name'] . "'", $id);
        // Phase 2 (S18): a restored published course's rules apply again (system actor). The restore has no
        // audit call of its own, so the hook follows the log line.
        try { (new \ITFlow\Training\Assign\AssignmentService($c))->reconcile(null, 'course_restored'); }
        catch (\Throwable $e) { error_log('Training: reconcile after course restore failed: ' . $e->getMessage()); }
        return ['status' => $course['course_current_revision_id'] === null ? 'draft' : 'published'];
    }

    public static function courseDelete(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('course_id', true, 1);
        $name = (string) Guard::course($c->db, $id)['course_name'];
        (new CourseService($c))->deleteDraft($id);
        self::log('Delete', "Deleted draft training course '$name'", $id);
        return [];
    }

    public static function courseSetTags(Ctx $c, ApiContext $a): array
    {
        $tags = $a->arr('tags', false);
        return ['tags' => (new CourseService($c))->setTags((int) $a->int('course_id', true, 1), array_is_list($tags) ? $tags : [])];
    }

    public static function courseSetPrereqs(Ctx $c, ApiContext $a): array
    {
        return ['prereqs' => (new PrereqService($c))->set((int) $a->int('course_id', true, 1), $a->ints('requires'))];
    }

    // ---- outline and sections ------------------------------------------------------------------

    public static function outlineReorder(Ctx $c, ApiContext $a): array
    {
        $sections = $a->arr('sections', false);
        if (!array_is_list($sections)) {
            throw ApiException::validation(['sections' => 'Must be a list.']);
        }
        (new OutlineService($c))->reorder((int) $a->int('course_id', true, 1), $sections, $a->ints('unsectioned'));
        return [];
    }

    public static function sectionCreate(Ctx $c, ApiContext $a): array
    {
        return (new SectionService($c))->create(
            (int) $a->int('course_id', true, 1),
            (string) $a->str('title', 200),
            $a->int('after_section_id', false, 1)
        );
    }

    public static function sectionUpdate(Ctx $c, ApiContext $a): array
    {
        return (new SectionService($c))->update(
            (int) $a->int('section_id', true, 1),
            (string) $a->str('title', 200, true, true),
            $a->lang('lang', false)
        );
    }

    public static function sectionDelete(Ctx $c, ApiContext $a): array
    {
        (new SectionService($c))->delete(
            (int) $a->int('section_id', true, 1),
            (string) $a->enum('mode', ['move', 'delete_lessons']),
            $a->int('target_section_id', false, 1)
        );
        return [];
    }

    // ---- templates, categories, tags, users -----------------------------------------------------

    public static function templateList(Ctx $c, ApiContext $a): array
    {
        $templates = TemplateCatalog::all();
        foreach ($templates as &$t) {
            $t['cover'] = CoverLibrary::defaultFor($t['kind'], $t['key']);
        }
        unset($t);
        return ['templates' => $templates];
    }

    public static function categoryList(Ctx $c, ApiContext $a): array
    {
        return ['categories' => (new CategoryService($c))->list($c->level >= 3 && (bool) $a->bool('include_archived', false))];
    }

    public static function categorySave(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('id', false, 1);
        $cat = (new CategoryService($c))->save($id, (string) $a->str('name', 100), (string) $a->str('color', 7), (string) $a->str('icon', 40));
        self::log($id === null ? 'Create' : 'Edit', ($id === null ? 'Created' : 'Edited') . " training category '" . $cat['name'] . "'", $cat['id']);
        return $cat;
    }

    public static function categoryArchive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('id', true, 1);
        $svc = new CategoryService($c);
        $name = $svc->get($id)['name'];
        $svc->archive($id);
        self::log('Archive', "Archived training category '$name'", $id);
        return [];
    }

    public static function tagList(Ctx $c, ApiContext $a): array
    {
        return ['tags' => (new TagService($c))->list()];
    }

    public static function userSearch(Ctx $c, ApiContext $a): array
    {
        return ['users' => UserDirectory::search($c->db, (string) ($a->str('q', 100, false) ?? ''))];
    }

    // ---- helpers --------------------------------------------------------------------------------

    /** @return list<string> */
    private static function stringList(ApiContext $a, string $k): array
    {
        $v = $a->arr($k, false);
        if (!array_is_list($v)) {
            throw ApiException::validation([$k => 'Must be a list of languages.']);
        }
        foreach ($v as $item) {
            if (!is_string($item)) {
                throw ApiException::validation([$k => 'Must be a list of languages.']);
            }
        }
        return array_values(array_unique($v));
    }

    /** logAction('Training', …) after commit; best-effort. */
    public static function log(string $action, string $description, int $entityId): void
    {
        if (!function_exists('logAction')) {
            return;
        }
        try {
            logAction('Training', $action, $description, 0, $entityId);
        } catch (\Throwable $e) {
            error_log('Training: logAction failed: ' . $e->getMessage());
        }
    }

    /** AuditService::record-equivalent on the request connection, after commit; best-effort. */
    public static function audit(Ctx $c, string $event, int $entityId, string $action, string $summary, array $meta = []): void
    {
        try {
            (new AuditService($c->db))->log($event, $c->userId, 'training_course', $entityId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }
}
