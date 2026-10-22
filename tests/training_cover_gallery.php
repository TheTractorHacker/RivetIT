<?php
/*
 * Training: sharing an uploaded course cover to the gallery (CoverLibrary::addCustom()/customCovers(), DB 2.6.189).
 * Before this, an uploaded cover was scoped to the one course that uploaded it; the gallery (cover_presets) was only
 * the 30 built-in illustrations. Covers: merging into api(), dedup by media_id (re-sharing updates in place, never
 * duplicates), key namespacing ("custom:" never collides with a built-in key), tint/category validation and fallback,
 * and refusing a non-image or nonexistent media row.
 *
 * SCRATCH DATABASE ONLY: never run against a live database.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/training_cover_gallery.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");

require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Media\CoverLibrary;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$db->query("DELETE FROM training_cover_gallery");
$db->query("DELETE FROM training_media WHERE media_id IN (1, 2)");
$db->query("INSERT INTO training_media (media_id, media_sha256, media_kind, media_mime, media_ext, media_bytes, media_path, media_uploaded_by, media_created_at_utc, media_row_sha256)
    VALUES (1, SHA2('cover-gallery-test-1', 256), 'image', 'image/jpeg', 'jpg', 1000, '/tmp/x1.jpg', 1, UTC_TIMESTAMP(3), SHA2('row1', 256)),
           (2, SHA2('cover-gallery-test-2', 256), 'pdf', 'application/pdf', 'pdf', 1000, '/tmp/x2.pdf', 1, UTC_TIMESTAMP(3), SHA2('row2', 256))");

$ctx = new Ctx($db, 1, true, 3, 'https://support.example.test', TrainingSettings::fromDb($db), 'test');

$api0 = CoverLibrary::api($ctx);
$ok(count($api0['covers']) === 30, 'with nothing shared yet, api() returns exactly the 30 built-ins');

$added = CoverLibrary::addCustom($ctx, 1, '  My Forklift Photo  ', 'Equipment', 'green');
$ok($added['label'] === 'My Forklift Photo', 'label is trimmed');
$ok($added['category'] === 'Equipment', 'category is kept as given');
$ok($added['tint'] === 'green' && $added['color'] === '#16A34A', 'a known tint name resolves to its real hex');
$ok($added['media_id'] === 1, 'the shared cover points at the right media row');
$ok(str_starts_with($added['key'], 'custom:'), "a shared cover's key is namespaced 'custom:', never collides with a built-in key");

$api1 = CoverLibrary::api($ctx);
$ok(count($api1['covers']) === 31, 'the shared cover now appears in the gallery');
$ok(in_array('Equipment', $api1['categories'], true), 'a category already in the built-in 7 is not duplicated');

CoverLibrary::addCustom($ctx, 1, 'Renamed', 'Our Own Stuff', 'blue');
$api2 = CoverLibrary::api($ctx);
$ok(in_array('Our Own Stuff', $api2['categories'], true), 'a brand-new category shows up for the picker to filter by');
$ok(count($api2['covers']) === 31, 're-sharing the SAME media_id updates the existing entry, not a duplicate (dedup by media_id)');
$custom = array_values(array_filter($api2['covers'], static fn($c) => ($c['media_id'] ?? null) === 1))[0] ?? null;
$ok($custom !== null && $custom['label'] === 'Renamed' && $custom['category'] === 'Our Own Stuff', 're-sharing updates label/category/tint in place');

$added2 = CoverLibrary::addCustom($ctx, 1, 'X', 'Y', 'not-a-real-tint');
$ok($added2['tint'] === 'slate', 'an unrecognized tint name falls back to slate, not left invalid');

$threw = false;
try { CoverLibrary::addCustom($ctx, 2, 'A PDF', 'Docs', 'blue'); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'sharing a non-image (a PDF) is refused');

$threw2 = false;
try { CoverLibrary::addCustom($ctx, 9999, 'Ghost', 'X', 'blue'); } catch (\InvalidArgumentException $e) { $threw2 = true; }
$ok($threw2, 'sharing a nonexistent media_id is refused');

$cc = CoverLibrary::customCovers($ctx);
$ok(count($cc) === 1, 'customCovers() returns exactly the one shared row');

$db->query("DELETE FROM training_cover_gallery");
$db->query("DELETE FROM training_media WHERE media_id IN (1, 2)");

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
