<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Catalog\AchievementRules;
use ITFlow\Training\Catalog\AchievementService;
use ITFlow\Training\Catalog\PathService;
use ITFlow\Training\Catalog\PrereqService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Icons;

/**
 * JSON handlers for learning paths, the prerequisite map and achievement definitions
 * (Routes/catalog.php).
 */
final class CatalogActions
{
    public static function pathList(Ctx $c, ApiContext $a): array
    {
        return ['paths' => (new PathService($c))->list((bool) $a->bool('include_archived', false))];
    }

    public static function pathGet(Ctx $c, ApiContext $a): array
    {
        return (new PathService($c))->get((int) $a->int('path_id', true, 1));
    }

    public static function prereqMap(Ctx $c, ApiContext $a): array
    {
        return ['courses' => (new PrereqService($c))->map()];
    }

    public static function pathSave(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('path_id', false, 1);
        $data = $a->arr('data');
        if ($data !== [] && array_is_list($data)) {
            throw ApiException::validation(['data' => 'Must be an object of fields.']);
        }
        $path = (new PathService($c))->save($id, $a->int('version', false, 0), $data);
        if ($id === null) {
            CourseActions::log('Create', "Created training learning path '" . $path['name'] . "'", $path['id']);
        }
        return $path;
    }

    /** {path_id, restore?: bool} - archives, or with restore=true brings an archived path back. */
    public static function pathArchive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('path_id', true, 1);
        $svc = new PathService($c);
        $restore = (bool) $a->bool('restore', false);
        if ($restore) {
            $svc->restore($id);
        } else {
            $svc->archive($id);
        }
        $path = $svc->get($id);
        CourseActions::log($restore ? 'Edit' : 'Archive', ($restore ? 'Restored' : 'Archived') . " training learning path '" . $path['name'] . "'", $id);
        return $path;
    }

    public static function achievementList(Ctx $c, ApiContext $a): array
    {
        return [
            'achievements' => (new AchievementService($c))->list((bool) $a->bool('include_archived', false)),
            'rule_types' => AchievementRules::catalog(),
            'icons' => Icons::ALLOWED,
            'swatches' => AchievementService::SWATCHES,
        ];
    }

    public static function achievementSave(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('achievement_id', false, 1);
        $data = $a->arr('data');
        if ($data !== [] && array_is_list($data)) {
            throw ApiException::validation(['data' => 'Must be an object of fields.']);
        }
        $achievement = (new AchievementService($c))->save($id, $a->int('version', false, 0), $data);
        if ($id === null) {
            CourseActions::log('Create', "Created training achievement '" . $achievement['name'] . "'", $achievement['id']);
        }
        return $achievement;
    }

    public static function achievementArchive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('achievement_id', true, 1);
        $svc = new AchievementService($c);
        $name = $svc->get($id)['name'];
        $svc->archive($id);
        CourseActions::log('Archive', "Archived training achievement '$name'", $id);
        return [];
    }
}
