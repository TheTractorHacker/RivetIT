<?php
/*
 * Unit check for the Webhook guides hub: renders a card and a full guide for a destination whose every text field is hostile and fails
 * (exit 1) when any of it reaches the output unescaped. Run from the app root by tests/e2e/webhook_guides.py:
 *   php tests/e2e/webhook_guides_escape.php <app dir>
 */
$app = $argv[1] ?? dirname(__DIR__, 2);
require $app . '/vendor/autoload.php';
require $app . '/includes/webhook_guide_page.php';

use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\DestinationField;

$evil = '<img src=x onerror=alert(1)>"\'<script>alert(2)</script>';
$d = new Destination(
    'x"><script>alert(9)</script>', $evil . 'Name', 'chat', $evil . 'desc', 'json', 'POST', $evil . 'hint', null,
    ['none', 'hmac', $evil], 'hmac', null,
    [new DestinationField('f', $evil . 'label', 'text', true, $evil . 'help', 'url', '', $evil)],
    [], 'javascript:alert(1)', [$evil . 'step1', 'step2'], 'curl ' . $evil, ['node' => $evil, $evil => $evil], [$evil . 'note secret', 'only ' . $evil],
);
$cats = ['chat' => $evil . 'Category'];
ob_start();
wgCard($d, $cats);
wgGuide($d, $cats, ['prev' => $d, 'next' => $d, 'related' => [$d]]);
$out = ob_get_clean();

$fail = [];
foreach (['<script', '<img'] as $needle) {
    // the only <script/<img allowed would be ours; the renderer emits none
    if (stripos($out, $needle) !== false) {
        $fail[] = "raw $needle in output";
    }
}
if (strpos($out, 'href="javascript:') !== false) {
    $fail[] = 'javascript: docs URL linked';
}
if (substr_count($out, '&lt;script&gt;alert(2)&lt;/script&gt;') < 5) {
    $fail[] = 'hostile text was not rendered as escaped text';
}
echo $fail ? 'FAIL: ' . implode('; ', $fail) . "\n" : "OK escaped\n";
exit($fail ? 1 : 0);
