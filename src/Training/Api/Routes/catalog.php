<?php

/*
 * Training JSON routes owned by the catalog lane (spec §6.2): learning paths, the
 * prerequisite map and achievement definitions. (course_set_prereqs lives with the course
 * routes in authoring.php.)
 */

use ITFlow\Training\Api\CatalogActions;

return [
    'path_list'           => ['handler' => CatalogActions::class . '::pathList',           'method' => 'GET',  'level' => 1],
    'path_get'            => ['handler' => CatalogActions::class . '::pathGet',            'method' => 'GET',  'level' => 1],
    'prereq_map'          => ['handler' => CatalogActions::class . '::prereqMap',          'method' => 'GET',  'level' => 1],
    'path_save'           => ['handler' => CatalogActions::class . '::pathSave',           'method' => 'POST', 'level' => 2],
    'path_archive'        => ['handler' => CatalogActions::class . '::pathArchive',        'method' => 'POST', 'level' => 2],
    'achievement_list'    => ['handler' => CatalogActions::class . '::achievementList',    'method' => 'GET',  'level' => 2],
    'achievement_save'    => ['handler' => CatalogActions::class . '::achievementSave',    'method' => 'POST', 'level' => 2],
    'achievement_archive' => ['handler' => CatalogActions::class . '::achievementArchive', 'method' => 'POST', 'level' => 2],
];
