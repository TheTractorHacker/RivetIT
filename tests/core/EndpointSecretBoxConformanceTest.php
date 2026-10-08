<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Testing\SecretBoxConformanceTestCase;

/**
 * EndpointSecretBox over the REAL encryptSetting()/decryptSetting() of functions.php (the file needs a whole app bootstrap, so their source is
 * extracted and defined under other names here; KitSupport.php's stand-ins use a different, non-encrypting format). The ciphertext format of
 * enrolled data ("ENC2:" AES-256-GCM, legacy "ENC:" AES-128-CBC) must stay byte-compatible, so the test also decrypts a value written by the old format.
 */
final class EndpointSecretBoxConformanceTest extends SecretBoxConformanceTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (function_exists('ea_conf_encrypt')) {
            return;
        }
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/functions.php');
        foreach (['encryptSetting' => 'ea_conf_encrypt', 'decryptSetting' => 'ea_conf_decrypt'] as $from => $to) {
            if (preg_match('/^function ' . $from . '\(.*?^}/ms', $src, $m) !== 1) {
                self::fail("$from not found in functions.php");
            }
            eval(str_replace('function ' . $from . '(', 'function ' . $to . '(', $m[0]));
        }
        $GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
    }

    protected function box(): SecretBoxInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointSecretBox(static fn (string $p): string => ea_conf_encrypt($p), static fn (string $c): string => ea_conf_decrypt($c));
    }

    public function testRowsWrittenByTheApplicationFormatStillDecrypt(): void
    {
        $key = $GLOBALS['config_settings_enc_key'];
        $legacyCbc = (static function (string $plain) use ($key): string {
            $k = substr(hash('sha256', $key, true), 0, 16);
            $iv = random_bytes(16);

            return 'ENC:' . base64_encode($iv . openssl_encrypt($plain, 'aes-128-cbc', $k, OPENSSL_RAW_DATA, $iv));
        })('legacy-cbc-secret');
        $this->assertSame('legacy-cbc-secret', $this->box()->decrypt($legacyCbc));
        $this->assertStringStartsWith('ENC2:', $this->box()->encrypt('x'));
        $this->assertSame('', $this->box()->decrypt('plain legacy text without a prefix'));
    }
}
