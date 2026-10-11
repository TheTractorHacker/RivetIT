<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Core/Adapter/Endpoint/EndpointSecretBox.php';

/**
 * The RMM signing key and the Mesh login key are read through EndpointSecretBox. After the settings stage moves to the Core envelope those values start
 * with "v3:": the box must hand them to decryptSetting() like ENC2:/ENC:, and still refuse anything that is not a ciphertext (a cleartext key must never
 * be taken as a signing key). Regression for the beta rewrap (the box returned '' for every v3: value).
 */
final class EndpointSecretBoxV3Test extends TestCase
{
    public function testEveryCiphertextPrefixIsHandedToTheDecryptor(): void
    {
        $seen = [];
        $box = new \ITFlow\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c) use (&$seen): string {
            $seen[] = $c;
            return 'plain';
        });
        foreach (['v3:k1:AAAA', 'ENC2:AAAA', 'ENC:AAAA'] as $c) {
            $this->assertSame('plain', $box->decrypt($c), $c);
        }
        $this->assertCount(3, $seen);
    }

    public function testClearTextAndGarbageAreNeverTreatedAsKeys(): void
    {
        $called = 0;
        $box = new \ITFlow\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c) use (&$called): string {
            $called++;
            return 'plain';
        });
        foreach (['', 'plaintextkey', 'V3:k1:AAAA', ' v3:k1:AAAA', 'enc2:AAAA'] as $c) {
            $this->assertSame('', $box->decrypt($c), var_export($c, true));
        }
        $this->assertSame(0, $called);
    }

    public function testADecryptorThatThrowsMeansNotAvailable(): void
    {
        $box = new \ITFlow\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c): string {
            throw new \RuntimeException('no key');
        });
        $this->assertSame('', $box->decrypt('v3:k1:AAAA'));
    }
}
