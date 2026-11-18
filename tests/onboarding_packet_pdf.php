<?php
declare(strict_types=1);
// CLI-only: php tests/onboarding_packet_pdf.php
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/../src/Onboarding/PacketRequest.php';
require_once __DIR__ . '/../src/Onboarding/PacketPdf.php';

use ITFlow\Onboarding\PacketPdf;
use ITFlow\Onboarding\PacketRequest;

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . ": $name\n"; if (!$ok) { $fail++; } }

$pw = "P\u{e4}ssw0rd<b>&'\"%";
$post = [
    'start_date' => '2026-12-01', 'manager_name' => 'Mary Manager',
    'computer' => ['name' => 'Laptop', 'username' => 'jdoe', 'password' => $pw, 'change' => '1'],
    'email' => ['name' => 'Mail', 'username' => 'jdoe@example.com', 'password' => 'mailpw1', 'change' => '0'],
    'software' => [['name' => 'CRM', 'uri' => 'https://crm.example.com', 'username' => 'jd', 'password' => 'crmpw1', 'note' => 'Ask IT', 'change' => '1']],
];
$req = PacketRequest::fromPost($post);
$person = "Jane Q\u{e4}Doe";
$pdf = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, false);

check('starts with %PDF-', str_starts_with($pdf, '%PDF-'));
check('no Powered by TCPDF', !str_contains($pdf, 'Powered by TCPDF'));

// TCPDF's dejavusans/UTF-8 text is emitted as UTF-16BE inside [( ... )] TJ in the uncompressed stream
// (e.g. U+00E4 -> bytes 00 E4), so needles are matched both as plain bytes and as UTF-16BE.
$probe2 = static fn(string $d, string $n): bool => str_contains($d, $n) || str_contains($d, mb_convert_encoding($n, 'UTF-16BE', 'UTF-8'));
// Direct probe of how the bytes look
$probe = static function (string $needle) use ($pdf): bool {
    return str_contains($pdf, $needle)
        || str_contains($pdf, mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8'))
        || str_contains($pdf, bin2hex($needle));
};
check('person name present', $probe($person) || $probe('Jane Q'));
check('ASCII tail of password present', $probe("rd<b>&'\"%"));
check('software password present', $probe('crmpw1'));
check('non-ASCII password verbatim', $probe($pw));
check('Welcome heading', $probe('Welcome to Acme Corp'));
check('support contact present', $probe('help@msp.example'));

$count = static fn(string $n): int => substr_count($pdf, $n) + substr_count($pdf, mb_convert_encoding($n, 'UTF-16BE', 'UTF-8'));
check('change text once for computer + software (2 lines with change=true)', $count('Change on first sign-in') === 2);

$post2 = $post; $post2['computer']['change'] = '0'; $post2['software'][0]['change'] = '0';
$pdf2 = PacketPdf::render(PacketRequest::fromPost($post2), $person, 'Acme Corp', 'help@msp.example', null, false);
check('change text absent when no line has change', substr_count($pdf2, 'Change on first sign-in') + substr_count($pdf2, mb_convert_encoding('Change on first sign-in', 'UTF-16BE', 'UTF-8')) === 0);

$empty = PacketPdf::render(PacketRequest::fromPost([]), $person, 'Acme Corp', 'help@msp.example');
check('zero lines still valid PDF', str_starts_with($empty, '%PDF-') && str_contains($empty, '%%EOF'));

$many = ['software' => []];
for ($i = 1; $i <= 25; $i++) { $many['software'][] = ['name' => "App $i", 'uri' => 'https://x.example', 'username' => "u$i", 'password' => "pw$i", 'note' => 'n', 'change' => '1']; }
$ok = true;
try { $big = PacketPdf::render(PacketRequest::fromPost($many), $person, 'Acme Corp', 'help@msp.example'); $ok = str_starts_with($big, '%PDF-') && substr_count($big, '/Type /Page') > 2; } catch (\Throwable $e) { $ok = false; echo $e->getMessage() . "\n"; }
check('25 software lines paginate', $ok);

$png = (static function (): string { $im = imagecreatetruecolor(4, 4); ob_start(); imagepng($im); return (string) ob_get_clean(); })();
$withLogo = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', $png, false);
check('valid PNG logo -> valid PDF with image', str_starts_with($withLogo, '%PDF-') && str_contains($withLogo, '/Subtype /Image'));
foreach (['notanimage' => 'notanimage', 'png header + garbage' => "\x89PNG\r\n\x1a\n" . str_repeat("\x07garbage", 20), 'php code' => '<?php echo 1;'] as $label => $bytes) {
    $ok = false;
    try {
        $g = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', $bytes, false);
        $ok = str_starts_with($g, '%PDF-') && $probe2($g, $pw);
    } catch (\Throwable $e) { echo $e->getMessage() . "\n"; }
    check("garbage logo ($label) -> valid PDF with password", $ok);
}
// A PNG PHP accepts but TCPDF cannot decode must fall back to a fresh logo-less build.
$trunc = substr($png, 0, 40);
$ok = false;
try { $g = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', $trunc, false); $ok = str_starts_with($g, '%PDF-') && $probe2($g, $pw); } catch (\Throwable $e) { echo $e->getMessage() . "\n"; }
check('truncated PNG logo -> valid PDF with password', $ok);

$__src = (string) file_get_contents(__DIR__ . '/../src/Onboarding/PacketPdf.php');
check('creator uses APP_NAME with fallback', str_contains($__src, "defined('APP_NAME') ? APP_NAME : 'RivetIT'"));

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
