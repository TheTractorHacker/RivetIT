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

// ---- Masked copy (the one attached to a ticket). Every needle is checked as plain bytes, UTF-16BE, and hex of both,
// and each absence check is paired with a presence check on the unmasked render, so it cannot pass vacuously.
$has = static function (string $d, string $n): bool {
    $u = mb_convert_encoding($n, 'UTF-16BE', 'UTF-8');
    $dl = strtolower($d);
    return str_contains($d, $n) || str_contains($d, $u) || str_contains($dl, bin2hex($n)) || str_contains($dl, bin2hex($u));
};
// Inflate every FlateDecode stream so the compressed (production) render can be inspected too.
$inflate = static function (string $d): string {
    $out = $d;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $d, $mm)) {
        foreach ($mm[1] as $raw) {
            $z = @gzuncompress($raw);
            if (is_string($z)) { $out .= "\n" . $z; }
        }
    }
    return $out;
};
$secrets = ['computer password' => $pw, 'computer password core' => 'ssw0rd', 'email password' => 'mailpw1', 'software password' => 'crmpw1'];
$plain = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, false);
$masked = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, false, true);
check('masked: valid PDF', str_starts_with($masked, '%PDF-') && str_contains($masked, '%%EOF'));
foreach ($secrets as $label => $secret) {
    check("unmasked control: $label present", $has($plain, $secret));
    check("masked: $label absent", !$has($masked, $secret));
}
check('masked: usernames present', $has($masked, 'jdoe') && $has($masked, 'jdoe@example.com') && $has($masked, 'Username: jd'));
check('masked: note "Passwords hidden in this copy" present', $has($masked, PacketPdf::MASK_NOTE) && PacketPdf::MASK_NOTE === 'Passwords hidden in this copy');
check('masked: mask glyphs present', $has($masked, PacketPdf::MASK) && PacketPdf::MASK === str_repeat("\u{2022}", 8));
check('masked: link, note, heading still print', $has($masked, 'https://crm.example.com') && $has($masked, 'Ask IT') && $has($masked, 'Welcome to Acme Corp'));
check('masked: change-on-first-sign-in lines still print (2)', (substr_count($masked, 'Change on first sign-in') + substr_count($masked, mb_convert_encoding('Change on first sign-in', 'UTF-16BE', 'UTF-8'))) === 2);
check('unmasked (default arg): no hidden-passwords note, no mask glyphs', !$has($plain, PacketPdf::MASK_NOTE) && !$has($plain, PacketPdf::MASK));
check('unmasked (explicit false) has no note either', !$has(PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, false, false), PacketPdf::MASK_NOTE));

// The exact call the endpoint makes for the ticket copy: compressed, masked.
$prodMasked = $inflate(PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, true, true));
$prodPlain = $inflate(PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', null, true));
check('compressed control: inflated unmasked render shows a password', $has($prodPlain, 'crmpw1'));
foreach ($secrets as $label => $secret) {
    check("compressed masked: $label absent", !$has($prodMasked, $secret));
}
check('compressed masked: note present', $has($prodMasked, PacketPdf::MASK_NOTE));

// The logo fallback path (rebuild without the logo) must keep the mask.
$fallback = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', $trunc, false, true);
check('masked + undecodable logo: still masked', str_starts_with($fallback, '%PDF-') && !$has($fallback, 'crmpw1') && !$has($fallback, $pw) && $has($fallback, PacketPdf::MASK_NOTE));
$withLogoMasked = PacketPdf::render($req, $person, 'Acme Corp', 'help@msp.example', $png, false, true);
check('masked + valid logo: still masked', str_contains($withLogoMasked, '/Subtype /Image') && !$has($withLogoMasked, 'crmpw1') && !$has($withLogoMasked, $pw));
check('render keeps BC signature (new param is trailing, optional)', str_contains($__src, 'bool $compress = true, bool $maskPasswords = false): string'));

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
