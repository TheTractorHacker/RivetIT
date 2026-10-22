<?php
/*
 * Odoo write-back: hr.skill(.type/.level) on an Odoo whose JSON-2 API only exposes id/display_name for those
 * models (the field has to be individually flagged "exposed to the API" there, and 'name' isn't on that list
 * yet) - Discovery::run() and Pusher's skill-type/skill-create helpers must fall back to display_name instead
 * of surfacing "no certification type in Odoo yet" or failing outright. No database access (Discovery/Pusher
 * are DB-free by design), so this runs without RIVETIT_TEST_DB.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\OdooSync\Discovery;
use ITFlow\Training\OdooSync\Pusher;
use ITFlow\Training\OdooSync\Target;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

/** Simulates an Odoo whose JSON-2 API only exposes id/display_name on hr.skill.type/level/skill. */
final class FakeNameRestrictedOdoo implements OdooConnectorInterface
{
    public array $creates = [];

    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed
    {
        $fields = $kwargs['fields'] ?? [];
        if ($method === 'search_read' && in_array($model, ['hr.skill.type', 'hr.skill.level', 'hr.skill'], true)) {
            if (in_array('name', $fields, true)) {
                throw new \RuntimeException("Invalid field 'name' on model '$model'");
            }
            if (($kwargs['order'] ?? '') !== '' && str_contains((string) $kwargs['order'], ' name')) {
                throw new \RuntimeException('order references an unexposed field');
            }
            return match ($model) {
                'hr.skill.type' => [['id' => 1, 'display_name' => 'Certifications']],
                'hr.skill.level' => [['id' => 11, 'display_name' => 'Certified', 'skill_type_id' => [1, 'Certifications'], 'default_level' => true]],
                'hr.skill' => [['id' => 101, 'display_name' => 'Forklift Operator', 'skill_type_id' => [1, 'Certifications']]],
            };
        }
        if ($method === 'search_read' && $model === 'hr.skill' && isset($kwargs['domain'])) {
            foreach ($kwargs['domain'] as $cond) {
                if (($cond[0] ?? null) === 'name') {
                    throw new \RuntimeException("Invalid field 'name' on model 'hr.skill'");
                }
            }
            return [['id' => 101, 'display_name' => 'Forklift Operator', 'skill_type_id' => [1, 'Certifications']]];
        }
        if ($method === 'fields_get') {
            return match ($model) {
                'hr.resume.line' => ['employee_id' => [], 'name' => [], 'date_start' => [], 'date_end' => [], 'description' => []],
                'hr.employee.skill' => ['employee_id' => []],
                'hr.employee' => ['message_ids' => []],
                default => [],
            };
        }
        if ($method === 'create' && $model === 'hr.skill.type') {
            $vals = ($kwargs['vals_list'] ?? [[]])[0];
            if (isset($vals['is_certification'])) {
                throw new \RuntimeException('Invalid field is_certification');
            }
            $this->creates[] = [$model, $vals];
            return [501];
        }
        if ($method === 'create' && $model === 'hr.skill.level') {
            $this->creates[] = [$model, ($kwargs['vals_list'] ?? [[]])[0]];
            return [502];
        }
        return [];
    }

    public function serverVersion(): ?array
    {
        return ['server_version' => '19.0e', 'server_version_info' => [19, 0, 0, 'final', 0, 'e'], 'server_serie' => '19.0'];
    }

    public function protocol(): string
    {
        return OdooConnectorFactory::PROTOCOL_JSON2;
    }
}

$target = new Target(1, 'https://example.odoo.com', 'db', 'target-key', false, true, OdooConnectorFactory::PROTOCOL_JSON2, []);
$odoo = new FakeNameRestrictedOdoo();

$disc = (new Discovery($target, $odoo))->run(25);

$ok($disc['skill']['available'] === true, 'skill target available despite the name-field restriction');
$ok(($disc['skill']['cert_types'][0]['name'] ?? null) === 'Certifications', 'cert type name falls back to display_name');
$ok(($disc['skill']['levels'][0]['name'] ?? null) === 'Certified', 'level name falls back to display_name');
$ok(($disc['skill']['skills'][0]['name'] ?? null) === 'Forklift Operator', 'skill name falls back to display_name');
$nameErrors = array_filter($disc['errors'], static fn($e) => str_contains($e, 'hr.skill'));
$ok($nameErrors === [], 'the quiet first attempt never surfaces as a discovery error once the fallback succeeds');

$pusher = new Pusher($odoo, $disc, []);
$found = $pusher->findSkillByName(1, 'Forklift Operator');
$ok($found !== null && $found['id'] === 101 && $found['name'] === 'Forklift Operator', 'findSkillByName falls back to display_name for the domain and fields');

$typeId = $pusher->createSkillType('New Certifications');
$ok($typeId === 501, 'createSkillType falls back to a plain create when is_certification is refused');
$levelId = $pusher->createSkillLevel($typeId, 'Certified');
$ok($levelId === 502, 'createSkillLevel creates a 100% level under the new type');
$ok($odoo->creates[0] === ['hr.skill.type', ['name' => 'New Certifications']], 'the fallback create never sends is_certification once it was refused');
$ok($odoo->creates[1][1]['level_progress'] === 100 && $odoo->creates[1][1]['default_level'] === true, 'the new level is created at 100% and marked default');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
