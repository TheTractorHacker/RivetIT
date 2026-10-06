<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Static check: guest file download claims views atomically and sanitises the filename header; DB-stored names go through basename() (semgrep triage). No DB. */
$r = fn($p) => file_get_contents(__DIR__ . '/../' . $p);
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$g = $r('guest/guest_download_file.php');
$ok(strpos($g, 'item_views = item_views + 1') !== false && strpos($g, 'mysqli_affected_rows') !== false, 'guest download: atomic view claim');
$ok(strpos($g, 'basename((string) $file_row') !== false, 'guest download: basename on stored names');
$ok(strpos($g, "filename=\"' . \$safe_download_name") !== false, 'guest download: quoted filename');
$ok(strpos($r('agent/post/contract.php'), 'basename((string) $doc[') !== false, 'contract serve: basename');
$ok(substr_count($r('agent/post/file.php'), 'basename((string) $row[') >= 2, 'file delete: basename');
exit($fails ? 1 : 0);
