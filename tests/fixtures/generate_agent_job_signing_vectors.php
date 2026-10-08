<?php
/*
 * Regenerates tests/fixtures/agent_job_signing_vectors.json (no database needed):  php tests/fixtures/generate_agent_job_signing_vectors.php
 * The vectors pin the canonical-JSON rule and the Ed25519 signatures so the Go agent can reuse them byte for byte. The key is a TEST key
 * derived from a fixed seed; never use it anywhere real. Ed25519 signatures are deterministic, so regenerating changes nothing.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../support/endpoint_compat.php';   // Signer::canonical() and the Ed25519 helpers of RivetCore\Rmm\Crypto (rivet-core)
use ITFlow\EndpointAgent\Signer;

$seed = hash('sha256', 'RivetIT-agent-TEST-seed', true);   // 32 bytes, fixed
[$pub, $sec] = Signer::keypairFromSeed($seed);

$jobCases = [
    'minimal reboot job (null script, empty params object)' => '{"job_id":"11111111-2222-4333-8444-555555555555","attempt":1,"type":"reboot","script":null,"params":{},"timeout_s":60,"max_output_bytes":65536,"issued_at":"2026-10-06T12:00:00Z","expires_at":"2026-10-06T13:00:00Z"}',
    'powershell job, unsorted input keys, nested params' => '{"expires_at":"2026-10-06T13:00:00Z","issued_at":"2026-10-06T12:00:00Z","max_output_bytes":65536,"timeout_s":300,"params":{"Zeta":true,"Alpha":"x","Mid":{"b":2,"a":[3,2,1]},"N":null},"script":"Get-Service | Where-Object Status -eq \'Running\'","type":"powershell","attempt":2,"job_id":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"}',
    'script with quotes, backslashes, control characters and unicode' => '{"job_id":"99999999-8888-4777-8666-555555555555","attempt":1,"type":"powershell","script":"Write-Host \"a\\\\b\"\n\t\u0001 café   <&> / 😀","params":{},"timeout_s":5,"max_output_bytes":1024,"issued_at":"2026-10-06T12:00:00Z","expires_at":"2026-10-06T12:05:00Z"}',
    'collect job with parameters of every scalar type' => '{"job_id":"01010101-0202-4303-8404-050505050505","attempt":3,"type":"collect","script":null,"params":{"Count":5,"Flag":false,"Name":"Ünï","Nothing":null},"timeout_s":30,"max_output_bytes":4096,"issued_at":"2026-10-06T00:00:00Z","expires_at":"2026-10-06T01:00:00Z"}',
];
$jobs = [];
foreach ($jobCases as $name => $json) {
    $obj = json_decode($json);
    $canonical = Signer::canonical($obj);
    $jobs[] = ['name' => $name, 'job_json' => $json, 'canonical' => $canonical, 'signature' => Signer::sign($canonical, $sec)];
}

$canonOnly = [
    ['name' => 'keys sort by UTF-8 bytes', 'input' => '{"b":1,"a":2,"B":3,"é":4,"aa":5}', 'canonical' => null],
    ['name' => 'empty containers stay distinct', 'input' => '{"o":{},"a":[]}', 'canonical' => null],
    ['name' => 'string escaping', 'input' => '["\\"","\\\\","\b\f\n\r\t","\u0000\u001f\u007f","  ","/"]', 'canonical' => null],
    ['name' => 'integers and literals', 'input' => '[0,-5,9007199254740991,true,false,null]', 'canonical' => null],
];
foreach ($canonOnly as &$c) { $c['canonical'] = Signer::canonical(json_decode($c['input'])); }
unset($c);

$sha = hash('sha256', 'RivetIT test package v1.2.3');
$checkJson = '{"key":"disk_c","type":"disk","params":{"mount":"C:","warn_pct":85,"fail_pct":95},"interval_s":300}';
$out = [
    'description' => 'RivetIT endpoint agent signing vectors. Canonical JSON: UTF-8, object keys sorted by their UTF-8 bytes (recursively), arrays in order, no whitespace, strings escape only \\" \\\\ \\b \\f \\n \\r \\t and other code points below U+0020 as \\u00xx (lowercase hex), everything else raw (including / < > & U+007F U+2028 U+2029), integers only, true/false/null. The signed message is the canonical form of the job object WITHOUT its "signature" member; the signature is Ed25519, base64 (standard alphabet, padded).',
    'test_key' => ['seed_derivation' => 'SHA-256 of the ASCII string RivetIT-agent-TEST-seed (raw 32 bytes)', 'seed_hex' => bin2hex($seed), 'public_key_base64' => $pub, 'secret_key_base64_libsodium' => $sec],
    'jobs' => $jobs,
    'canonical_only' => $canonOnly,
    'update_manifest' => ['description' => 'The signature is Ed25519 over the lowercase hex SHA-256 string of the package, as ASCII bytes.', 'sha256' => $sha, 'signature' => Signer::sign($sha, $sec)],
    'check_definition' => ['description' => 'Check definitions are signed the same way as jobs (canonical JSON of the object without "signature").', 'check_json' => $checkJson,
        'canonical' => Signer::canonical(json_decode($checkJson)), 'signature' => Signer::sign(Signer::canonical(json_decode($checkJson)), $sec)],
];
$file = __DIR__ . '/agent_job_signing_vectors.json';
$text = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if (($argv[1] ?? '') === '--check') { exit(file_get_contents($file) === $text ? 0 : 1); }
file_put_contents($file, $text);
echo "wrote $file\n";
