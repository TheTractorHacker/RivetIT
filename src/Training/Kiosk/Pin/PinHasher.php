<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Kiosk\Core\KioskKeys;

/**
 * Local PIN, setup-code and Odoo-fingerprint hashing (P3 spec §3.2, §8 "Local PIN storage").
 *
 * The bcrypt input is ALWAYS the 64-character lowercase HEX string
 * hash_hmac('sha256', "$cid:$secret", $pepper) - never raw bytes: PHP 8.4's bcrypt throws a
 * ValueError on a NUL byte, and 64 characters stay under bcrypt's 72-byte limit. Binding the
 * contact id into the HMAC means a hash copied onto another person's row never verifies.
 *
 * PINs and setup codes use cost 11; the Odoo change-detection fingerprint uses cost 10.
 * verifyPin()/verifySetup() with a null hash still run one bcrypt verify against a fixed dummy
 * hash, so "this person has no PIN" costs the same time as a wrong PIN.
 *
 * Nothing here logs, stores or echoes the secret; callers unset() their copies after use.
 */
final class PinHasher
{
    public const COST = 11;
    public const FP_COST = 10;

    /** bcrypt(11) of 64 random hex characters nobody knows: the target of every dummy verify. */
    private const DUMMY_11 = '$2y$11$OFjPtNqRWZ12HdqtUAu2t.ldsxlZfiT8EeL0f.TjLVW9nIat4JWvK';
    private const DUMMY_10 = '$2y$10$XxqQBUwThW4J7mAaWPVGcuWcuWJXMPjHkxVJC/u5YM3dlqeSQbfBa';

    public function __construct(private readonly KioskKeys $keys)
    {
    }

    public function hashPin(int $cid, string $pin): string
    {
        return $this->hash($this->keys->pinPepper(), $cid, $pin, self::COST);
    }

    public function verifyPin(int $cid, string $pin, ?string $hash): bool
    {
        return $this->verify($this->keys->pinPepper(), $cid, $pin, $hash, self::DUMMY_11);
    }

    public function hashSetup(int $cid, string $code): string
    {
        return $this->hash($this->keys->setupPepper(), $cid, $code, self::COST);
    }

    public function verifySetup(int $cid, string $code, ?string $hash): bool
    {
        return $this->verify($this->keys->setupPepper(), $cid, $code, $hash, self::DUMMY_11);
    }

    /** Odoo PIN change-detection fingerprint (plan A1 "Accountability notes"). */
    public function hashFp(int $cid, string $pin): string
    {
        return $this->hash($this->keys->fpPepper(), $cid, $pin, self::FP_COST);
    }

    public function verifyFp(int $cid, string $pin, string $hash): bool
    {
        return $this->verify($this->keys->fpPepper(), $cid, $pin, $hash, self::DUMMY_10);
    }

    /** One wasted verify: used when there is nothing to check but the timing must match a real check. */
    public function dummyVerify(): void
    {
        password_verify(str_repeat('0', 64), self::DUMMY_11);
    }

    /** The exact bcrypt input: 64 lowercase hex characters (public for the NUL-byte test vector). */
    public static function material(string $pepper, int $cid, string $secret): string
    {
        return hash_hmac('sha256', $cid . ':' . $secret, $pepper);
    }

    private function hash(string $pepper, int $cid, string $secret, int $cost): string
    {
        if ($cid < 1) {
            throw new \InvalidArgumentException('PinHasher: bad contact id');
        }
        return password_hash(self::material($pepper, $cid, $secret), PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    private function verify(string $pepper, int $cid, string $secret, ?string $hash, string $dummy): bool
    {
        $input = self::material($pepper, max(0, $cid), $secret);
        if ($hash === null || $hash === '' || $cid < 1) {
            password_verify($input, $dummy);
            return false;
        }
        return password_verify($input, $hash);
    }

    public function __debugInfo(): array
    {
        return ['keys' => '(hidden)'];
    }
}
