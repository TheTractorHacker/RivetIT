<?php

/*
 * Training JSON routes owned by the authoring lane (spec §6.2): courses, sections, the
 * outline, lessons, resources, templates, categories, tags and the user picker.
 * Router::routes() merges this with the other lanes' files and refuses a duplicate name.
 */

use ITFlow\Training\Api\CourseActions;
use ITFlow\Training\Api\LessonActions;

return [
    'course_list'          => ['handler' => CourseActions::class . '::courseList',         'method' => 'GET',  'level' => 1],
    'course_create'        => ['handler' => CourseActions::class . '::courseCreate',       'method' => 'POST', 'level' => 2],
    'course_get'           => ['handler' => CourseActions::class . '::courseGet',          'method' => 'GET',  'level' => 2],
    'course_update'        => ['handler' => CourseActions::class . '::courseUpdate',       'method' => 'POST', 'level' => 2],
    'course_set_languages' => ['handler' => CourseActions::class . '::courseSetLanguages', 'method' => 'POST', 'level' => 2],
    'course_duplicate'     => ['handler' => CourseActions::class . '::courseDuplicate',    'method' => 'POST', 'level' => 2],
    'course_archive'       => ['handler' => CourseActions::class . '::courseArchive',      'method' => 'POST', 'level' => 3],
    'course_restore'       => ['handler' => CourseActions::class . '::courseRestore',      'method' => 'POST', 'level' => 3],
    'course_delete'        => ['handler' => CourseActions::class . '::courseDelete',       'method' => 'POST', 'level' => 3],
    'course_set_tags'      => ['handler' => CourseActions::class . '::courseSetTags',      'method' => 'POST', 'level' => 2],
    'course_set_prereqs'   => ['handler' => CourseActions::class . '::courseSetPrereqs',   'method' => 'POST', 'level' => 2],

    'outline_reorder'      => ['handler' => CourseActions::class . '::outlineReorder',     'method' => 'POST', 'level' => 2],
    'section_create'       => ['handler' => CourseActions::class . '::sectionCreate',      'method' => 'POST', 'level' => 2],
    'section_update'       => ['handler' => CourseActions::class . '::sectionUpdate',      'method' => 'POST', 'level' => 2],
    'section_delete'       => ['handler' => CourseActions::class . '::sectionDelete',      'method' => 'POST', 'level' => 2],

    'lesson_create'        => ['handler' => LessonActions::class . '::lessonCreate',       'method' => 'POST', 'level' => 2],
    'lesson_get'           => ['handler' => LessonActions::class . '::lessonGet',          'method' => 'GET',  'level' => 2],
    'lesson_update'        => ['handler' => LessonActions::class . '::lessonUpdate',       'method' => 'POST', 'level' => 2],
    'lesson_set_type'      => ['handler' => LessonActions::class . '::lessonSetType',      'method' => 'POST', 'level' => 2],
    'lesson_duplicate'     => ['handler' => LessonActions::class . '::lessonDuplicate',    'method' => 'POST', 'level' => 2],
    'lesson_delete'        => ['handler' => LessonActions::class . '::lessonDelete',       'method' => 'POST', 'level' => 2],
    'lesson_restore'       => ['handler' => LessonActions::class . '::lessonRestore',      'method' => 'POST', 'level' => 2],
    'lesson_copy_variant'  => ['handler' => LessonActions::class . '::lessonCopyVariant',  'method' => 'POST', 'level' => 2],

    'resource_add'         => ['handler' => LessonActions::class . '::resourceAdd',        'method' => 'POST', 'level' => 2],
    'resource_update'      => ['handler' => LessonActions::class . '::resourceUpdate',     'method' => 'POST', 'level' => 2],
    'resource_delete'      => ['handler' => LessonActions::class . '::resourceDelete',     'method' => 'POST', 'level' => 2],
    'resources_reorder'    => ['handler' => LessonActions::class . '::resourcesReorder',   'method' => 'POST', 'level' => 2],

    'template_list'        => ['handler' => CourseActions::class . '::templateList',       'method' => 'GET',  'level' => 2],
    'category_list'        => ['handler' => CourseActions::class . '::categoryList',       'method' => 'GET',  'level' => 1],
    'category_save'        => ['handler' => CourseActions::class . '::categorySave',       'method' => 'POST', 'level' => 3],
    'category_archive'     => ['handler' => CourseActions::class . '::categoryArchive',    'method' => 'POST', 'level' => 3],
    'tag_list'             => ['handler' => CourseActions::class . '::tagList',            'method' => 'GET',  'level' => 1],
    'user_search'          => ['handler' => CourseActions::class . '::userSearch',         'method' => 'GET',  'level' => 2],
];
