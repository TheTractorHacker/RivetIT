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

// IDOR guard: sharing/overwriting is scoped to who actually uploaded the media, admins excepted.
$db->query("DELETE FROM training_media WHERE media_id IN (3, 4)");
$db->query("INSERT INTO training_media (media_id, media_sha256, media_kind, media_mime, media_ext, media_bytes, media_path, media_uploaded_by, media_created_at_utc, media_row_sha256)
    VALUES (3, SHA2('cover-gallery-test-3', 256), 'image', 'image/jpeg', 'jpg', 1000, '/tmp/x3.jpg', 2, UTC_TIMESTAMP(3), SHA2('row3', 256)),
           (4, SHA2('cover-gallery-test-4', 256), 'image', 'image/jpeg', 'jpg', 1000, '/tmp/x4.jpg', 3, UTC_TIMESTAMP(3), SHA2('row4', 256))");

$ctx2 = new Ctx($db, 2, false, 2, 'https://support.example.test', TrainingSettings::fromDb($db), 'test');
$ctx3 = new Ctx($db, 3, false, 2, 'https://support.example.test', TrainingSettings::fromDb($db), 'test');

$threw3 = false;
try { CoverLibrary::addCustom($ctx3, 3, 'Not mine', 'X', 'red'); } catch (\InvalidArgumentException $e) { $threw3 = true; }
$ok($threw3, "a non-admin user cannot share another user's upload (IDOR guard)");

$shared3 = CoverLibrary::addCustom($ctx2, 3, 'Mine', 'Equipment', 'blue');
$ok($shared3['media_id'] === 3, 'the actual uploader can share their own upload');

$threw4 = false;
try { CoverLibrary::addCustom($ctx3, 3, 'Hijacked', 'X', 'red'); } catch (\InvalidArgumentException $e) { $threw4 = true; }
$ok($threw4, "a non-admin user cannot overwrite someone else's existing gallery entry");

$overridden = CoverLibrary::addCustom($ctx, 3, 'Admin override', 'Equipment', 'blue');
$ok($overridden['label'] === 'Admin override', "an admin can update another user's existing gallery entry");

$adminAdd = CoverLibrary::addCustom($ctx, 4, 'Admin added', 'Equipment', 'blue');
$ok($adminAdd['media_id'] === 4, "an admin can share another user's upload with no prior entry");

// removeCustom() (the standalone Cover Library's "Remove" button): ownership-checked the same way as addCustom().
// $overridden is the same gallery row as $shared3 (addCustom() upserts by media_id); gallery_added_by stays
// ctx2's (the original sharer) even after the admin's relabel, since the UPDATE clause never touches it.
$beforeRemove = count(CoverLibrary::customCovers($ctx));
$sharedGalleryId = (int) str_replace('custom:', '', $overridden['key']);
$threw5 = false;
try { CoverLibrary::removeCustom($ctx3, $sharedGalleryId); } catch (\InvalidArgumentException $e) { $threw5 = true; }
$ok($threw5, "a non-admin user cannot remove someone else's shared cover");
$ok(count(CoverLibrary::customCovers($ctx)) === $beforeRemove, 'the refused remove changed nothing');

$ownGalleryId = (int) str_replace('custom:', '', $shared3['key']);
CoverLibrary::removeCustom($ctx2, $ownGalleryId);
$ok(count(array_filter(CoverLibrary::customCovers($ctx), static fn($c) => $c['media_id'] === 3)) === 0, 'the sharer can remove their own shared cover');

$adminGalleryId = (int) str_replace('custom:', '', $adminAdd['key']);
CoverLibrary::removeCustom($ctx, $adminGalleryId);
$ok(count(array_filter(CoverLibrary::customCovers($ctx), static fn($c) => $c['media_id'] === 4)) === 0, "an admin can remove anyone's shared cover");

$threw6 = false;
try { CoverLibrary::removeCustom($ctx, 999999); } catch (\InvalidArgumentException $e) { $threw6 = true; }
$ok($threw6, 'removing a nonexistent gallery_id is refused, not a silent no-op');

// The scratch DB has no `users` row for user_id 1 (schema-only clone), so added_by_name is null here - the
// LEFT JOIN working as intended for a since-deleted user, not a bug. The field just has to be present.
$row1 = CoverLibrary::customCovers($ctx)[0] ?? null;
$ok($row1 !== null && $row1['added_by'] === 1 && array_key_exists('added_by_name', $row1) && array_key_exists('gallery_id', $row1) && array_key_exists('created_at', $row1),
    'customCovers() reports who shared the remaining cover (and when), for the standalone library page');

$db->query("DELETE FROM training_cover_gallery");
$db->query("DELETE FROM training_media WHERE media_id IN (1, 2, 3, 4)");

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
