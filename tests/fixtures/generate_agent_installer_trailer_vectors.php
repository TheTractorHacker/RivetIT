<?php
/*
 * Generates tests/fixtures/agent_installer_trailer_vectors.json: the stamped-installer file format shared with the Go agent.
 *   php tests/fixtures/generate_agent_installer_trailer_vectors.php > tests/fixtures/agent_installer_trailer_vectors.json
 * Deterministic (no clock, no randomness). Written with raw pack()/hash() on purpose, NOT through InstallerStamp, so the unit test compares
 * the production class against an independent statement of the format. Must stay stable: the agent's tests pin the same bytes.
 */
$MAGIC = 'RIVETIT-EMBED-v1';
$footer = static fn(string $p): string => pack('N', strlen($p)) . hash('sha256', $p, true) . $MAGIC;
$payload = static function (array $over = []): string {
    return json_encode(array_merge([
        'version' => 1, 'installer_id' => '3f2b8c1e-5d4a-4e7b-9a10-6c2d8e9f0a1b', 'server_url' => 'https://rivet.example.com/rivetit',
        'enrollment_token' => 'rvte1.0123456789ab.0123456789abcdef0123456789abcdef01234567', 'department' => 'Acme Corp', 'ca_pem' => null,
        'created_at' => '2026-10-06T12:00:00Z', 'expires_at' => '2026-10-09T12:00:00Z'], $over), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
$fakeExe = static fn(int $n): string => 'MZ' . str_repeat("\x90\x00\xFF\x7F", intdiv($n, 4)) . substr("PE\0\0", 0, $n % 4);
$vec = [];
$add = function (string $name, string $exe, string $pl, string $note) use (&$vec, $footer) {
    $vec[] = ['name' => $name, 'note' => $note, 'exe_hex' => bin2hex($exe), 'payload' => $pl, 'payload_length' => strlen($pl), 'payload_sha256_hex' => hash('sha256', $pl),
        'footer_hex' => bin2hex($footer($pl)), 'stamped_hex' => bin2hex($exe . $pl . $footer($pl))];
};
$add('basic', $fakeExe(64), $payload(), 'ordinary installer, no CA');
$add('with_ca_and_unicode', $fakeExe(10), $payload(['department' => "Müller & Söhne \"Ost\" 日本", 'ca_pem' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n"]), 'UTF-8 department, CA PEM with newlines, escaped quotes');
$add('one_byte_exe', 'M', $payload(), 'the original executable is a single byte');
$add('empty_exe', '', $payload(), 'degenerate: nothing before the payload (the agent finds exe_length 0)');
$add('magic_inside_body', "MZ....RIVETIT-EMBED-v1....body", $payload(), 'the magic string inside the exe body is not a footer (a real agent binary contains the constant)');
$add('minimal_payload', $fakeExe(8), '{}', 'two-byte payload: the format check passes, semantic checks belong to the parser');
$pad = 16384 - strlen($payload(['department' => '']));
$add('max_payload_16384', $fakeExe(32), $payload(['department' => str_repeat('d', $pad)]), 'payload exactly 16384 bytes: accepted');
$neg = [];
$addn = function (string $name, string $stamped, string $why) use (&$neg) { $neg[] = ['name' => $name, 'reason' => $why, 'stamped_hex' => bin2hex($stamped), 'expect' => 'reject']; };
$p = $payload(); $exe = $fakeExe(40); $good = $exe . $p . $footer($p);
$addn('too_short', substr($good, -51), 'shorter than the 52-byte footer');
$addn('empty_file', '', 'no bytes at all');
$addn('bad_magic', substr($good, 0, -1) . '2', 'last byte of the magic changed');
$addn('bad_magic_case', substr($good, 0, -16) . strtolower($MAGIC), 'magic compared case-sensitively');
$bad = $good; $bad[strlen($good) - 20] = $bad[strlen($good) - 20] ^ "\x01";
$addn('bad_sha256', $bad, 'one bit of the stored hash flipped');
$pp = $p; $pp[3] = 'X';
$addn('payload_corrupted', $exe . $pp . $footer($p), 'payload changed after hashing');
$addn('truncated_one_byte', substr($good, 0, -1), 'file truncated by one byte (magic incomplete)');
$addn('length_zero', $exe . pack('N', 0) . hash('sha256', '', true) . $MAGIC, 'declared payload length 0');
$addn('length_exceeds_file', $exe . pack('N', 100000) . hash('sha256', $p, true) . $MAGIC, 'declared length larger than the file');
$big = str_repeat('a', 16385);
$addn('length_16385', $exe . $big . pack('N', 16385) . hash('sha256', $big, true) . $MAGIC, 'declared length one over the 16384 bound, hash valid');
$addn('length_off_by_one_short', $exe . $p . pack('N', strlen($p) - 1) . hash('sha256', $p, true) . $MAGIC, 'length does not match the hashed payload');
$addn('length_off_by_one_long', $exe . $p . pack('N', strlen($p) + 1) . hash('sha256', $p, true) . $MAGIC, 'length does not match the hashed payload (includes one exe byte)');
$nj = 'not json at all';
$addn('payload_not_json', $exe . $nj . $footer($nj), 'footer valid, payload is not JSON: the parser must refuse');
$arr = '["rvte1.x"]';
$addn('payload_json_array', $exe . $arr . $footer($arr), 'footer valid, payload is a JSON array, not an object');
echo json_encode(['format' => 'RIVETIT-EMBED-v1', 'description' => 'stamped_exe = original exe || payload || footer; footer = uint32 BE payload length (4) || SHA-256 of payload (32) || ASCII "RIVETIT-EMBED-v1" (16) = 52 bytes; payload <= 16384 bytes. The agent reads the LAST 52 bytes of its own exe. Hex strings are lowercase.',
    'footer_length' => 52, 'max_payload' => 16384, 'vectors' => $vec, 'negative' => $neg], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
