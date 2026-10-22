<?php
/*
 * Odoo write-back: the Badge target (gamification.badge.user, DB 2.6.190) - achievements only, no validity
 * period, never revoked on a void. Pusher::createBadge()/closeBadge()/ownBadges() are DB-free (like the
 * hr.skill name-fallback tests), so this runs without RIVETIT_TEST_DB: a fake connector exercises the
 * marker-based idempotency, the employee_id/user_id field fallback (the hr.skill lesson: a field existing
 * in Odoo's ORM is not always on the JSON-2 API's exposed-field list), and badge reuse by name.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\OdooSync\Marker;
use ITFlow\Training\OdooSync\Pusher;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

/** A row/payload shaped like PushService's real inputs, for one achievement award. */
function badgeRow(string $marker, string $action = 'create'): array
{
    return ['todoo_marker' => $marker, 'todoo_action' => $action, 'todoo_source_type' => 'award'];
}
function badgePayload(string $name): array
{
    return ['badge_name' => $name, 'title' => 'Achievement: ' . $name];
}

$marker1 = Marker::for('abcd1234', 'award', 7);

// ---- 1. employee_id exposed directly (the modern, employee-native Odoo Badges tab) -----------------------
final class FakeBadgeOdoo implements OdooConnectorInterface
{
    public array $creates = [];
    public bool $exposeEmployeeId = true;
    public array $grants = [];   // simulated existing gamification.badge.user rows

    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed
    {
        if ($model === 'gamification.badge.user' && $method === 'fields_get') {
            return $this->exposeEmployeeId ? ['id' => [], 'employee_id' => [], 'badge_id' => [], 'comment' => []]
                                            : ['id' => [], 'user_id' => [], 'badge_id' => [], 'comment' => []];
        }
        if ($model === 'gamification.badge' && $method === 'search_read') {
            return [];   // no existing badge named this yet
        }
        if ($model === 'gamification.badge' && $method === 'create') {
            $vals = ($kwargs['vals_list'] ?? [[]])[0];
            $this->creates[] = ['gamification.badge', $vals];
            return [301];
        }
        if ($model === 'hr.employee' && $method === 'read') {
            return [['id' => ($kwargs['ids'] ?? [0])[0], 'user_id' => [55, 'Blake Foley']]];
        }
        if ($model === 'gamification.badge.user' && $method === 'search_read') {
            $field = $this->exposeEmployeeId ? 'employee_id' : 'user_id';
            $out = [];
            foreach ($this->grants as $g) {
                if (($g[$field] ?? null) === ($kwargs['domain'][0][2] ?? null)) { $out[] = $g; }
            }
            return $out;
        }
        if ($model === 'gamification.badge.user' && $method === 'create') {
            $vals = ($kwargs['vals_list'] ?? [[]])[0];
            $this->creates[] = ['gamification.badge.user', $vals];
            $id = 400 + count($this->creates);
            $this->grants[] = ['id' => $id, 'employee_id' => $vals['employee_id'] ?? null, 'user_id' => $vals['user_id'] ?? null, 'comment' => $vals['comment'] ?? ''];
            return [$id];
        }
        return [];
    }

    public function serverVersion(): ?array { return null; }
    public function protocol(): string { return OdooConnectorFactory::PROTOCOL_JSON2; }
}

$odoo = new FakeBadgeOdoo();
$pusher = new Pusher($odoo, ['skill' => []], []);
$reflect = new ReflectionClass($pusher);
$createBadge = $reflect->getMethod('createBadge');
$createBadge->setAccessible(true);
$closeBadge = $reflect->getMethod('closeBadge');
$closeBadge->setAccessible(true);

$res1 = $createBadge->invoke($pusher, badgeRow($marker1), badgePayload('Forklift Operator'), 42);
$ok($res1['model'] === 'gamification.badge.user', 'createBadge returns the gamification.badge.user model');
$ok($odoo->creates[0] === ['gamification.badge', ['name' => 'Forklift Operator']], 'a new gamification.badge is created, named after the achievement');
$ok($odoo->creates[1][1]['employee_id'] === 42 && $odoo->creates[1][1]['badge_id'] === 301, 'the grant uses employee_id directly when the field is exposed');
$ok(str_contains((string) $odoo->creates[1][1]['comment'], $marker1), 'the marker is embedded in the comment field for idempotency');

$res1b = $createBadge->invoke($pusher, badgeRow($marker1), badgePayload('Forklift Operator'), 42);
$ok($res1b['res_id'] === $res1['res_id'], 'creating the same badge grant again (re-run/retry) reuses the existing one, no duplicate');
$ok(count($odoo->creates) === 2, 'no new Odoo records were created on the re-run');

$closeRes = $closeBadge->invoke($pusher, ['todoo_odoo_res_id' => $res1['res_id']]);
$ok($closeRes['res_id'] === $res1['res_id'], 'a close (void) is a no-op that confirms the existing grant, never revokes it');
$ok(count($odoo->creates) === 2, 'closing a badge never writes anything new to Odoo');

$threw = false;
try { $closeBadge->invoke($pusher, null); } catch (\Throwable $e) { $threw = $e->getMessage() === 'create_not_sent' || str_contains($e->getMessage(), 'create_not_sent'); }
$ok($threw, 'closing a badge that was never created throws create_not_sent, not a crash');

// ---- 2. employee_id NOT exposed: falls back to the employee's linked Odoo user (user_id) ------------------
$odoo2 = new FakeBadgeOdoo();
$odoo2->exposeEmployeeId = false;
$pusher2 = new Pusher($odoo2, ['skill' => []], []);
$createBadge2 = (new ReflectionClass($pusher2))->getMethod('createBadge');
$createBadge2->setAccessible(true);

$marker2 = Marker::for('abcd1234', 'award', 8);
$res2 = $createBadge2->invoke($pusher2, badgeRow($marker2), badgePayload('First Aid'), 42);
$ok($odoo2->creates[1][1]['user_id'] === 55 && !array_key_exists('employee_id', $odoo2->creates[1][1]), 'falls back to the employee\'s linked Odoo user_id when employee_id is not exposed');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
