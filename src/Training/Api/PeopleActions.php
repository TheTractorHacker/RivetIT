<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\People\DepartmentGroups;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\HireDateService;
use ITFlow\Training\People\JobGroupService;
use ITFlow\Training\People\Roster;
use ITFlow\Training\People\Scope;

/**
 * JSON handlers for People (Routes/people.php; Phase 2 spec §4.2). The Router has enforced the
 * route level; these add the fail-closed people scope (Scope::forCtx) and assertContact() for every
 * person touched (§0 #2). A person outside the scope is always 404 not_found.
 */
final class PeopleActions
{
    /** GET q (>= 2 chars), limit (<= 20) -> {people:[PersonRef]} eligible, in scope. */
    public static function search(Ctx $c, ApiContext $a): array
    {
        $q = (string) $a->str('q', 100, false, true);
        $limit = $a->int('limit', false, 1, 20) ?? 20;
        if (mb_strlen($q, 'UTF-8') < 2) {
            return ['people' => []];
        }
        $people = Directory::search($c->db, Scope::forCtx($c), $q, $limit);
        return ['people' => array_map([Directory::class, 'ref'], $people)];
    }

    /** GET q, client_id, state, page -> {people:[PersonRef + roster fields], total, page, per_page, scope}. */
    public static function roster(Ctx $c, ApiContext $a): array
    {
        $s = Scope::forCtx($c);
        $r = (new Roster($c))->list([
            'q' => $a->str('q', 100, false, true),
            'client_id' => $a->int('client_id', false, 0),
            'state' => $a->enum('state', ['auto', 'include', 'exclude', 'eligible', 'ineligible', 'archived', 'all'], false) ?? 'all',
            'page' => $a->int('page', false, 1, 100000) ?? 1,
        ], $s);
        return $r + ['scope' => self::scopeName($s)];
    }

    /** POST {contact_id, state, reason} (L3) -> {person, reconcile}. */
    public static function rosterSet(Ctx $c, ApiContext $a): array
    {
        $contactId = (int) $a->int('contact_id', true, 1);
        Scope::forCtx($c)->assertContact($c->db, $contactId);
        $r = (new Roster($c))->set($contactId, (string) $a->enum('state', Roster::STATES), $a->str('reason', 255, false));
        if ($r['changed']) {
            CourseActions::log('Edit', 'Set training roster state of contact #' . $contactId . ' to ' . ($r['person']['roster_state'] ?? '?'), $contactId);
        }
        return ['person' => $r['person'], 'reconcile' => $r['reconcile']];
    }

    /**
     * POST {contact_id, archived (bool), reason?} (Training L3 + Departments L2, and the department itself) -> {person, reconcile}.
     * Archives (or restores) the contact exactly as Contacts does (contact_archived_at, and the linked portal login), keeps the
     * Odoo directory sync from un-archiving them (contact_odoo_links.odoo_archive_hold) and reconciles their assignments.
     * Nothing is deleted; a department's primary contact cannot be archived (same rule as Contacts).
     */
    public static function rosterArchive(Ctx $c, ApiContext $a): array
    {
        if (!function_exists('lookupUserPermission') || (int) lookupUserPermission('module_client') < 2) {
            throw ApiException::forbidden('Archiving a person needs write access to Departments.');
        }
        $contactId = (int) $a->int('contact_id', true, 1);
        $archive = (bool) $a->bool('archived', true);
        $reason = $a->str('reason', 255, false);
        $ref = Scope::forCtx($c)->assertContact($c->db, $contactId);
        // The training scope can be wider than the user's department grants (Training L3 sees every department): honour both.
        $allowed = array_filter(array_map('intval', explode(',', (string) ($GLOBALS['client_access_string'] ?? ''))));
        if (empty($GLOBALS['session_is_admin']) && $allowed && !in_array($ref['contact_client_id'], $allowed, true)) {
            throw ApiException::notFound('That person was not found.');
        }
        $db = $c->db;
        $row = Db::one($db, 'SELECT contact_primary, contact_archived_at FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        if ($archive && (int) ($row['contact_primary'] ?? 0) === 1) {
            throw ApiException::validation(['contact_id' => 'Primary contact.'], 'This is the primary contact of their department. Make someone else primary first, then archive them.');
        }
        $isArchived = ($row['contact_archived_at'] ?? null) !== null;
        $changed = $isArchived !== $archive;
        if ($changed) {
            Db::tx($db, function () use ($db, $c, $contactId, $archive, $ref, $reason): void {
                if ($archive) {
                    Db::exec($db, 'UPDATE contacts SET contact_important = 0, contact_billing = 0, contact_technical = 0, contact_archived_at = NOW() WHERE contact_id = ?', 'i', [$contactId]);
                } else {
                    Db::exec($db, 'UPDATE contacts SET contact_archived_at = NULL WHERE contact_id = ?', 'i', [$contactId]);
                }
                if ($ref['contact_user_id'] > 0) {
                    Db::exec($db, 'UPDATE users SET user_archived_at = ' . ($archive ? 'NOW()' : 'NULL') . ' WHERE user_id = ?', 'i', [$ref['contact_user_id']]);
                }
                $hasHold = Db::one($db, "SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contact_odoo_links' AND column_name = 'odoo_archive_hold'", '', []);
                if ($hasHold !== null) {
                    Db::exec($db, 'UPDATE contact_odoo_links SET odoo_archive_hold = ? WHERE contact_id = ?', 'ii', [$archive ? 1 : 0, $contactId]);
                }
                Ledger::append($db, [
                    'type' => 'roster.changed',
                    'actor_type' => 'user',
                    'actor_user_id' => $c->userId,
                    'subject_contact_id' => $contactId,
                    'entity_type' => 'roster',
                    'entity_id' => $contactId,
                    'payload' => ['from' => $archive ? 'active' : 'archived', 'to' => $archive ? 'archived' : 'active', 'reason' => $reason],
                    'user_agent' => $c->userAgent,
                ]);
            });
            if (function_exists('logAction')) {
                $who = (string) ($GLOBALS['session_name'] ?? 'A user');
                logAction('Contact', $archive ? 'Archive' : 'Restore', $who . ($archive ? ' archived ' : ' restored ') . $ref['contact_name'] . ' from Training > People' . ($reason ? ': ' . $reason : ''), $ref['contact_client_id'], $contactId);
            }
        }
        $reconcile = $changed ? AssignmentService::safeReconcile($c, [$contactId], 'roster') : null;
        $person = Directory::load($db, Scope::all(), [$contactId], true)[$contactId] ?? null;
        return ['person' => $person === null ? null : Roster::row($person), 'changed' => $changed, 'reconcile' => $reconcile];
    }

    /** GET -> {jobs:[{id,name,count}], locations:[{id,name,count}]} over eligible in-scope people. */
    public static function odooAttrOptions(Ctx $c, ApiContext $a): array
    {
        $s = Scope::forCtx($c);
        if ($s->isNone()) {
            return ['jobs' => [], 'locations' => []];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $from = ' FROM contact_odoo_attributes ca JOIN contacts c ON c.contact_id = ca.coattr_contact_id ' . Roster::JOIN
              . ' WHERE ' . Roster::ELIGIBLE . $scopeSql;
        $jobs = Db::all($c->db, 'SELECT ca.coattr_job_id AS id, MAX(ca.coattr_job_name) AS name, COUNT(*) AS n' . $from
            . ' AND ca.coattr_job_id IS NOT NULL GROUP BY ca.coattr_job_id ORDER BY name, id', $types, $params);
        $locs = Db::all($c->db, 'SELECT ca.coattr_work_location_id AS id, MAX(ca.coattr_work_location_name) AS name, COUNT(*) AS n' . $from
            . ' AND ca.coattr_work_location_id IS NOT NULL GROUP BY ca.coattr_work_location_id ORDER BY name, id', $types, $params);
        $shape = static fn(array $rows) => array_map(static fn($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'] === null ? ('#' . $r['id']) : (string) $r['name'], 'count' => (int) $r['n'],
        ], $rows);
        return ['jobs' => $shape($jobs), 'locations' => $shape($locs)];
    }

    /** POST {contact_id, hire_date, rehired, reason} (L3) -> {person, reconcile}. */
    public static function hireDateSet(Ctx $c, ApiContext $a): array
    {
        $contactId = (int) $a->int('contact_id', true, 1);
        Scope::forCtx($c)->assertContact($c->db, $contactId);
        $r = (new HireDateService($c))->set($contactId, (string) $a->date('hire_date'), (bool) $a->bool('rehired', false),
            (string) $a->str('reason', 255));
        CourseActions::log('Edit', 'Set hire date of contact #' . $contactId . ' to ' . $r['new'] . ($r['old'] !== null ? ' (was ' . $r['old'] . ')' : ''), $contactId);
        return ['person' => $r['person'], 'reconcile' => $r['reconcile']];
    }

    /**
     * GET include_archived? -> {groups:[…]} (matched = eligible people in the caller's scope). Department
     * groups come first (auto:true, department:{id,name}). Brings them in step with the departments
     * first when the last sync is over DepartmentGroups::STALE_S old (never fails the list).
     */
    public static function jobgroupList(Ctx $c, ApiContext $a): array
    {
        DepartmentGroups::syncIfStale($c->db);
        return ['groups' => (new JobGroupService($c))->list(Scope::forCtx($c), (bool) $a->bool('include_archived', false))];
    }

    /** GET jobgroup_id -> group with in-scope members and titles. */
    public static function jobgroupGet(Ctx $c, ApiContext $a): array
    {
        return (new JobGroupService($c))->get((int) $a->int('jobgroup_id', true, 1), Scope::forCtx($c));
    }

    /** POST {id?, version?, name, description, members:[ids], titles:[str]} (L3) -> group (+ reconcile). 409 department_group for a department group. */
    public static function jobgroupSave(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('id', false, 1) ?? $a->int('jobgroup_id', false, 1);
        $members = $a->ints('members');
        Scope::forCtx($c)->assertContacts($c->db, $members);
        $titles = $a->arr('titles', false);
        if ($titles !== [] && !array_is_list($titles)) {
            throw ApiException::validation(['titles' => 'Must be a list of titles.']);
        }
        $group = (new JobGroupService($c))->save($id, $a->int('version', false, 0), [
            'name' => $a->str('name', 100),
            'description' => $a->str('description', 255, false, true),
            'members' => $members,
            'titles' => $titles,
        ]);
        CourseActions::log($id === null ? 'Create' : 'Edit', ($id === null ? 'Created' : 'Edited') . " training job group '" . $group['name'] . "'", $group['id']);
        return $group;
    }

    /** POST {id} (L3) -> {reconcile}. 409 department_group for a department group. */
    public static function jobgroupArchive(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('id', false, 1) ?? (int) $a->int('jobgroup_id', true, 1);
        $r = (new JobGroupService($c))->archive($id);
        CourseActions::log('Archive', 'Archived training job group #' . $id, $id);
        return $r;
    }

    /** GET (L3) -> {titles:[{title, count}]}. */
    public static function jobgroupTitles(Ctx $c, ApiContext $a): array
    {
        return ['titles' => (new JobGroupService($c))->titles(Scope::forCtx($c))];
    }

    /** 'all' | 'some' | 'none' - pages show the "ask an administrator" banner for none. */
    public static function scopeName(Scope $s): string
    {
        return $s->isAll() ? 'all' : ($s->isNone() ? 'none' : 'some');
    }
}
