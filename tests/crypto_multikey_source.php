<?php
/*
 * Key rewrap over a table whose primary key is wider than one or two integers: rmm_package_params (package_id, scope_type, scope_id, name).
 * ColumnRegistry registers its sealed value_enc column with ColumnSpec::$keyCols and MysqliRewrapSource pages and replaces it by the whole key.
 * Needs the scratch database like the other security tests:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_multikey_source.php
 */
require __DIR__ . '/support/security_lib.php';

use ITFlow\Crypto\ColumnRegistry;
use ITFlow\Crypto\MysqliRewrapSource;

$spec = null;
foreach (ColumnRegistry::all() as $cs) {
    if ($cs->table === 'rmm_package_params' && $cs->column === 'value_enc') { $spec = $cs; }
}
$ok($spec !== null, 'rmm_package_params.value_enc is in the rewrap registry');
if ($spec === null) { exit(1); }
$ok($spec->keyCols === ['scope_type' => 's', 'scope_id' => 'i', 'name' => 's'] && $spec->kind === 'settings', 'it is keyed by four columns and sealed with the generic settings context');
$ok(MysqliRewrapSource::exists($db, $spec), 'the table and column exist on this install');

$P = 9100;   // package ids used by this test only
$q("DELETE FROM rmm_package_params WHERE package_id BETWEEN $P AND " . ($P + 9));
$rowsIn = [   // package_id, scope_type, scope_id, name, value
    [$P,     'global',  0,  'KEY',   'v-a'],
    [$P,     'clients', 9,  'KEY',   'v-b'],
    [$P,     'clients', 10, 'ALPHA', 'v-c'],     // 10 sorts after 9 (integer column), 'ALPHA' before 'KEY' (string column)
    [$P,     'clients', 10, 'KEY',   'v-d'],
    [$P + 1, 'global',  0,  'KEY',   'v-e'],
    [$P + 1, 'clients', 2,  'TOKEN', 'v-f'],
    [$P + 2, 'global',  0,  'KEY',   ''],         // an empty value is not a sealed value: skipped
];
foreach ($rowsIn as [$pk, $st, $sid, $nm, $v]) {
    $q("INSERT INTO rmm_package_params SET package_id = $pk, scope_type = '$st', scope_id = $sid, name = '$nm', value_enc = '" . $esc($v) . "', set_by = 0, set_at = '2026-10-25 00:00:00'");
}
$src = new MysqliRewrapSource($db, $spec, ColumnRegistry::rowFilter($spec));

$all = []; $cursor = null; $pages = 0;
while (($items = $src->next($cursor, 2)) !== []) {
    foreach ($items as $it) { $all[] = $it; }
    $cursor = $items[count($items) - 1]->id;
    if (++$pages > 20) { break; }
}
$ids = array_map(fn ($i) => $i->id, $all);
$expectIds = array_map('json_encode', [[$P, 'clients', 9, 'KEY'], [$P, 'clients', 10, 'ALPHA'], [$P, 'clients', 10, 'KEY'], [$P, 'global', 0, 'KEY'], [$P + 1, 'clients', 2, 'TOKEN'], [$P + 1, 'global', 0, 'KEY']]);
$mine = array_values(array_filter($ids, fn ($id) => (json_decode($id, true)[0] ?? 0) >= $P && (json_decode($id, true)[0] ?? 0) <= $P + 9));
$ok($mine === $expectIds, 'paging two at a time returns every row once, ordered by the whole key (' . implode(' ', $mine) . ')');
$ok(count($ids) === count(array_unique($ids)), 'no row is returned twice across pages');
$ok(!in_array(json_encode([$P + 2, 'global', 0, 'KEY']), $ids, true), 'a row with an empty value is skipped');
$byId = []; foreach ($all as $it) { $byId[$it->id] = $it; }
$k = json_encode([$P, 'clients', 10, 'ALPHA']);
$ok(isset($byId[$k]) && $byId[$k]->ciphertext === 'v-c', 'the item carries the stored value');
$ok(isset($byId[$k]) && $byId[$k]->context === \ITFlow\Crypto\SettingsCrypto::contextFor(\RivetCore\Crypto\KeyPurpose::SETTINGS, \ITFlow\Crypto\SettingsCrypto::GENERIC), 'and the generic settings context');

// replace(): optimistic, by the whole key
$ok($src->replace($k, 'WRONG', 'NEW') === false, 'replace refuses when the stored value is not the one that was read');
$ok((string) $one("SELECT value_enc FROM rmm_package_params WHERE package_id = $P AND scope_type = 'clients' AND scope_id = 10 AND name = 'ALPHA'") === 'v-c', 'and leaves the row untouched');
$ok($src->replace($k, 'v-c', 'v-c-NEW') === true, 'replace succeeds with the value that was read');
$ok((string) $one("SELECT value_enc FROM rmm_package_params WHERE package_id = $P AND scope_type = 'clients' AND scope_id = 10 AND name = 'ALPHA'") === 'v-c-NEW', 'and changes that row');
$ok((string) $one("SELECT value_enc FROM rmm_package_params WHERE package_id = $P AND scope_type = 'clients' AND scope_id = 10 AND name = 'KEY'") === 'v-d'
    && (string) $one("SELECT value_enc FROM rmm_package_params WHERE package_id = $P AND scope_type = 'global' AND scope_id = 0 AND name = 'KEY'") === 'v-a', 'and only that row (its neighbours that share three of four key columns are unchanged)');
$ok($src->replace($k, 'v-c', 'again') === false, 'a second replace with the old value is refused (it moved on)');
$ok($src->replace('not json', 'x', 'y') === false && $src->replace(json_encode([1, 2]), 'x', 'y') === false, 'a malformed id is refused, not guessed');
$threw = false;
try { $src->next('{"bad":1}', 5); } catch (\RuntimeException $e) { $threw = true; }
$ok($threw, 'a cursor that is not one this source produced is refused');

$q("DELETE FROM rmm_package_params WHERE package_id BETWEEN $P AND " . ($P + 9));
