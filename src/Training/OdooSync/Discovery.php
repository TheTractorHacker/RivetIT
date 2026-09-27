<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Text;

/**
 * "Check Odoo (read-only)" (spec §3.4 Discovery, §4.3 ta_odoo_discover). Reads what the Odoo at
 * $target offers for the write-back; writes nothing there. Every call has its own try/catch and an
 * 8 s timeout, and the run stops starting new calls once $budgetS is spent. The result names its
 * target, so a discovery can never be used against another Odoo (checked on save and on every run).
 *
 * Every string that comes from Odoo (type names, errors) is clipped here and escaped by the page.
 */
final class Discovery
{
    private const CALL = ['connect_timeout' => 5, 'timeout' => 8];

    private float $deadline = 0.0;

    public function __construct(private readonly Target $target, private readonly OdooConnectorInterface $odoo)
    {
    }

    /**
     * @return array{target:array{key:string, integration_id:int, base_url:string, database:string}, server_version:?string,
     *   protocol:string, checked_at_utc:string, errors:list<string>,
     *   resume:array{available:bool, fields:list<string>, course_type_values:list<string>, types:list<array{id:int,name:string,is_course:?bool}>, suggested_type_id:?int},
     *   skill:array{available:bool, cert_types:list<array{id:int,name:string}>, levels:list<array{id:int,name:string,type_id:int,default:bool}>,
     *     skills:list<array{id:int,name:string,type_id:int}>},
     *   note:array{available:bool}, gamification:array{employees_with_users:?int}, can_read_resume:bool}
     */
    public function run(int $budgetS = 25): array
    {
        $this->deadline = microtime(true) + max(1, $budgetS);
        $errors = [];
        $out = [
            'target' => $this->target->identity(),
            'server_version' => null,
            'protocol' => $this->odoo->protocol(),
            'checked_at_utc' => Clock::nowUtc(),
            'errors' => [],
            'resume' => ['available' => false, 'fields' => [], 'course_type_values' => [], 'types' => [], 'suggested_type_id' => null],
            'skill' => ['available' => false, 'cert_types' => [], 'levels' => [], 'skills' => []],
            'note' => ['available' => false],
            'gamification' => ['employees_with_users' => null],
            'can_read_resume' => false,
        ];

        $v = $this->odoo->serverVersion();
        $out['server_version'] = is_array($v) && is_string($v['server_version'] ?? null) ? Text::clip($v['server_version'], 50) : null;

        // Resume lines: the fields, then the line types.
        $fg = $this->try('hr.resume.line', 'fields_get', ['attributes' => ['type', 'required', 'selection']], $errors);
        if (is_array($fg) && $fg !== []) {
            $fields = array_values(array_filter(array_keys($fg), 'is_string'));
            $out['resume']['fields'] = $fields;
            $sel = $fg['course_type']['selection'] ?? [];
            foreach (is_array($sel) ? $sel : [] as $pair) {
                if (is_array($pair) && is_string($pair[0] ?? null)) {
                    $out['resume']['course_type_values'][] = (string) Text::clip($pair[0], 40);
                }
            }
            $out['resume']['available'] = !array_diff(['employee_id', 'name', 'date_start', 'date_end', 'description'], $fields);
        }
        $types = $this->try('hr.resume.line.type', 'search_read', ['domain' => [], 'fields' => ['id', 'name', 'is_course']], $errors, quiet: true);
        if (!is_array($types)) {
            $types = $this->try('hr.resume.line.type', 'search_read', ['domain' => [], 'fields' => ['id', 'name']], $errors);
        }
        foreach (is_array($types) ? $types : [] as $t) {
            if (is_array($t) && is_int($t['id'] ?? null) && $t['id'] > 0) {
                $out['resume']['types'][] = [
                    'id' => $t['id'],
                    'name' => (string) Text::clip(is_string($t['name'] ?? null) ? $t['name'] : ('#' . $t['id']), 200),
                    'is_course' => array_key_exists('is_course', $t) ? (bool) $t['is_course'] : null,
                ];
            }
        }
        $out['resume']['suggested_type_id'] = self::suggest($out['resume']['types']);
        if (!$out['resume']['types']) {
            $out['resume']['available'] = false;
        }

        // Certification skills: the certification skill types, their levels and their skills (the admin maps a course
        // or an achievement to one of these skills; read-only here - "Create skill in Odoo" is a separate admin action).
        $sk = $this->try('hr.employee.skill', 'fields_get', ['attributes' => ['type']], $errors);
        $out['skill']['available'] = is_array($sk) && $sk !== [];
        if ($out['skill']['available']) {
            $ct = $this->try('hr.skill.type', 'search_read', ['domain' => [['is_certification', '=', true]], 'fields' => ['id', 'name']], $errors);
            $ids = [];
            foreach (is_array($ct) ? $ct : [] as $t) {
                if (is_array($t) && is_int($t['id'] ?? null)) {
                    $out['skill']['cert_types'][] = ['id' => $t['id'], 'name' => (string) Text::clip(is_string($t['name'] ?? null) ? $t['name'] : '', 200)];
                    $ids[] = $t['id'];
                }
            }
            if ($ids) {
                $lv = $this->try('hr.skill.level', 'search_read', ['domain' => [['skill_type_id', 'in', $ids]], 'fields' => ['id', 'name', 'skill_type_id', 'default_level']], $errors);
                foreach (is_array($lv) ? $lv : [] as $l) {
                    if (is_array($l) && is_int($l['id'] ?? null)) {
                        $out['skill']['levels'][] = [
                            'id' => $l['id'],
                            'name' => (string) Text::clip(is_string($l['name'] ?? null) ? $l['name'] : '', 200),
                            'type_id' => is_array($l['skill_type_id'] ?? null) ? (int) ($l['skill_type_id'][0] ?? 0) : (int) ($l['skill_type_id'] ?? 0),
                            'default' => (bool) ($l['default_level'] ?? false),
                        ];
                    }
                }
                $sk = $this->try('hr.skill', 'search_read', ['domain' => [['skill_type_id', 'in', $ids]], 'fields' => ['id', 'name', 'skill_type_id'],
                    'order' => 'name asc', 'limit' => 2000], $errors);
                foreach (is_array($sk) ? $sk : [] as $k) {
                    if (is_array($k) && is_int($k['id'] ?? null) && $k['id'] > 0) {
                        $out['skill']['skills'][] = [
                            'id' => $k['id'],
                            'name' => (string) Text::clip(is_string($k['name'] ?? null) ? $k['name'] : ('#' . $k['id']), 200),
                            'type_id' => is_array($k['skill_type_id'] ?? null) ? (int) ($k['skill_type_id'][0] ?? 0) : (int) ($k['skill_type_id'] ?? 0),
                        ];
                    }
                }
            }
        }

        // Chatter (the HR-note target posts internal notes; hr.employee.message_ids is only readable by HR officers).
        $ef = $this->try('hr.employee', 'fields_get', ['attributes' => ['type']], $errors);
        $out['note']['available'] = is_array($ef) && isset($ef['message_ids']);

        $n = $this->try('hr.resume.line', 'search_count', ['domain' => []], $errors);
        $out['can_read_resume'] = is_int($n);
        $u = $this->try('hr.employee', 'search_count', ['domain' => [['user_id', '!=', false]]], $errors);
        $out['gamification']['employees_with_users'] = is_int($u) ? $u : null;

        $out['errors'] = $errors;
        return $out;
    }

    /** "Training" by name, else the first course type, else nothing. */
    public static function suggest(array $types): ?int
    {
        foreach ($types as $t) {
            if (preg_match('/^training$/i', trim((string) $t['name']))) {
                return (int) $t['id'];
            }
        }
        foreach ($types as $t) {
            if (($t['is_course'] ?? null) === true) {
                return (int) $t['id'];
            }
        }
        return null;
    }

    /** One read-only call; on failure records a short error and returns null. */
    private function try(string $model, string $method, array $kwargs, array &$errors, bool $quiet = false): mixed
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0.5) {
            $errors[] = "$model.$method: skipped (the check ran out of time)";
            return null;
        }
        try {
            return $this->odoo->call($model, $method, [], $kwargs, ['connect_timeout' => min(self::CALL['connect_timeout'], $left), 'timeout' => min(self::CALL['timeout'], $left)]);
        } catch (\Throwable $e) {
            if (!$quiet) {
                $errors[] = (string) Text::clip("$model.$method: " . $e->getMessage(), 300);
            }
            return null;
        }
    }
}
