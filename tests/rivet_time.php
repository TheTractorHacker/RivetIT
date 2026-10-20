<?php
/* Unit tests of includes/rivet_time.php (no database). php tests/rivet_time.php */
require __DIR__ . '/../includes/rivet_time.php';
$pass = 0; $fail = 0;
$eq = function ($got, $want, string $name) use (&$pass, &$fail) { if ($got === $want) { $pass++; } else { $fail++; echo "FAIL $name: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; } };

$CH = 'America/Chicago';
// utc -> Chicago across both DST transitions of 2026 (spring 8 Mar 08:00Z, fall 1 Nov 07:00Z)
$eq(rivetFormatTime('2026-03-08 07:59:00', 'utc', true, 'Y-m-d H:i', $CH), '2026-03-08 01:59 CST', 'spring before');
$eq(rivetFormatTime('2026-03-08 08:00:00', 'utc', true, 'Y-m-d H:i', $CH), '2026-03-08 03:00 CDT', 'spring after');
$eq(rivetFormatTime('2026-11-01 06:59:59', 'utc', true, 'Y-m-d H:i', $CH), '2026-11-01 01:59 CDT', 'fall before');
$eq(rivetFormatTime('2026-11-01 07:00:00', 'utc', true, 'Y-m-d H:i', $CH), '2026-11-01 01:00 CST', 'fall after (repeated hour)');
$eq(rivetFormatTime('2026-10-18T17:16:53Z', 'utc', true, 'Y-m-d H:i', $CH), '2026-10-18 12:16 CDT', 'RFC3339 Z');
$eq(rivetFormatTime('2026-10-18T17:16:53Z', 'local', true, 'Y-m-d H:i', $CH), '2026-10-18 12:16 CDT', 'Z wins over local');
$eq(rivetFormatTime('2026-10-18T12:16:53-05:00', 'local', true, 'Y-m-d H:i', $CH), '2026-10-18 12:16 CDT', 'explicit offset');
// local: shown as stored, only labelled (no double conversion)
$eq(rivetFormatTime('2026-10-18 12:16:53', 'local', true, 'Y-m-d H:i', $CH), '2026-10-18 12:16 CDT', 'local as is');
$eq(rivetFormatTime('2026-01-18 12:16:53', 'local', true, 'Y-m-d H:i', $CH), '2026-01-18 12:16 CST', 'local winter label');
$eq(rivetFormatTime('2026-10-18 12:16:53', 'local', false, 'Y-m-d H:i', $CH), '2026-10-18 12:16', 'no label');
// epoch
$eq(rivetFormatTime(1790000000, 'epoch', true, 'Y-m-d H:i', $CH), '2026-09-21 09:13 CDT', 'epoch int');
$eq(rivetFormatTime('1790000000', 'epoch', true, 'Y-m-d H:i', $CH), '2026-09-21 09:13 CDT', 'epoch string');
$eq(rivetFormatTime(0, 'epoch', true, 'Y-m-d H:i', $CH), '', 'epoch 0');
// other zones
$eq(rivetFormatTime('2026-07-01 12:00:00', 'utc', true, 'Y-m-d H:i', 'Europe/London'), '2026-07-01 13:00 BST', 'London summer');
$eq(rivetFormatTime('2026-07-01 12:00:00', 'utc', true, 'Y-m-d H:i', 'Australia/Sydney'), '2026-07-01 22:00 AEST', 'Sydney');
$eq(rivetFormatTime('2026-07-01 12:00:00', 'utc', true, 'Y-m-d H:i', 'Asia/Kolkata'), '2026-07-01 17:30 IST', 'Kolkata half hour');
$eq(rivetFormatTime('2026-07-01 12:00:00', 'utc', true, 'Y-m-d H:i', 'Asia/Dubai'), '2026-07-01 16:00 UTC+04:00', 'no abbreviation');
$eq(rivetFormatTime('2026-07-01 12:00:00', 'utc', true, 'Y-m-d H:i', 'UTC'), '2026-07-01 12:00 UTC', 'company in UTC may say UTC');
// null / zero / invalid safe
foreach ([null, '', false, '0000-00-00 00:00:00', 'junk', '2026-13-45 99:99:99'] as $i => $bad) { $eq(rivetFormatTime($bad, 'utc', true, 'Y-m-d H:i', $CH), '', "bad $i"); $eq(rivetTimeIso($bad), '', "bad iso $i"); }
$eq(rivetFormatTime('2026-10-18 12:16:53', 'utc', true, 'Y-m-d H:i', 'Not/AZone'), '2026-10-18 12:16 UTC', 'bad zone falls back (no fatal)') ;
// iso + epoch
$eq(rivetTimeIso('2026-10-18 12:16:53', 'local', $CH), '2026-10-18T17:16:53Z', 'iso from local');
$eq(rivetTimeEpoch('2026-10-18T17:16:53Z'), 1792343813, 'epoch of iso');
// company zone resolution
$GLOBALS['rivet_company_tz_override'] = $CH;
$eq(rivetCompanyTimezone(), $CH, 'override');
$eq(rivetFormatTime('2026-10-18 17:16:53'), '2026-10-18 12:16 CDT', 'default zone is company zone');
unset($GLOBALS['rivet_company_tz_override']);
$_SESSION['session_timezone'] = 'America/New_York';
$eq(rivetCompanyTimezone(), 'America/New_York', 'session zone');
$_SESSION['session_timezone'] = 'bogus';
$eq(rivetCompanyTimezone(), 'UTC', 'bogus session zone falls back to UTC');
unset($_SESSION['session_timezone']);
date_default_timezone_set('Europe/Berlin');
$eq(rivetCompanyTimezone(), 'Europe/Berlin', 'server zone fallback');
echo "rivet_time: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
