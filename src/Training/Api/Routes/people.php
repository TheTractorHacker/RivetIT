<?php

/*
 * Training JSON routes owned by Phase 2 Lane B, People (spec §4.2): people search and roster,
 * Odoo job/location options, hire dates and job groups. Scope checks happen in PeopleActions.
 */

use ITFlow\Training\Api\PeopleActions;

return [
    'people_search'     => ['handler' => PeopleActions::class . '::search',          'method' => 'GET',  'level' => 1],
    'people_roster'     => ['handler' => PeopleActions::class . '::roster',          'method' => 'GET',  'level' => 1],
    'roster_set'        => ['handler' => PeopleActions::class . '::rosterSet',       'method' => 'POST', 'level' => 3],
    'odoo_attr_options' => ['handler' => PeopleActions::class . '::odooAttrOptions', 'method' => 'GET',  'level' => 1],
    'hire_date_set'     => ['handler' => PeopleActions::class . '::hireDateSet',     'method' => 'POST', 'level' => 3],
    'jobgroup_list'     => ['handler' => PeopleActions::class . '::jobgroupList',    'method' => 'GET',  'level' => 1],
    'jobgroup_get'      => ['handler' => PeopleActions::class . '::jobgroupGet',     'method' => 'GET',  'level' => 1],
    'jobgroup_save'     => ['handler' => PeopleActions::class . '::jobgroupSave',    'method' => 'POST', 'level' => 3],
    'jobgroup_archive'  => ['handler' => PeopleActions::class . '::jobgroupArchive', 'method' => 'POST', 'level' => 3],
    'jobgroup_titles'   => ['handler' => PeopleActions::class . '::jobgroupTitles',  'method' => 'GET',  'level' => 3],
];
