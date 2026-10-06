<?php

namespace ITFlow\EndpointAgent;

/**
 * Ed25519 signing of jobs, check definitions and update manifests, plus the canonical JSON the signatures cover.
 *
 * CANONICAL JSON (the rule the Go agent must reproduce byte for byte; see tests/fixtures/agent_job_signing_vectors.json):
 *   - UTF-8, no insignificant whitespace.
 *   - Object members sorted by key, comparing the UTF-8 bytes (strcmp order). Applies recursively.
 *   - Arrays keep their order. {} and [] are distinct.
 *   - Strings escape only: \" \\ \b \f \n \r \t, every other code point below U+0020 as \u00xx (lowercase hex).
 *     Everything else, including "/", "<", ">", "&", U+007F, U+2028/2029 and non-ASCII, is written raw.
 *   - Numbers are integers only (no fraction, no exponent, no "-0"); true/false/null literal. Floats are refused.
 */
final class Signer
{
    /** @param mixed $v decoded with json_decode(..., false) so {} and [] stay distinct */
    public static function canonical($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            throw new \InvalidArgumentException('canonical JSON does not allow floating point numbers');
        }
        if (is_string($v)) {
            return self::str($v);
        }
        if ($v instanceof \stdClass) {
            $v = (array) $v;
            $assoc = true;
        } elseif (is_array($v)) {
            $assoc = !array_is_list($v);
        } else {
            throw new \InvalidArgumentException('unsupported type in canonical JSON');
        }
        if (!$assoc) {
            return '[' . implode(',', array_map([self::class, 'canonical'], $v)) . ']';
        }
        $keys = array_map('strval', array_keys($v));
        usort($keys, 'strcmp');
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = self::str($k) . ':' . self::canonical($v[$k] ?? $v[(int) $k] ?? null);
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function str(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            throw new \InvalidArgumentException('canonical JSON strings must be valid UTF-8');
        }
        $map = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\x0c" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];
        $out = preg_replace_callback('/["\\\\\x00-\x1f]/', static function ($m) use ($map) {
            return $map[$m[0]] ?? sprintf('\\u%04x', ord($m[0]));
        }, $s);
        return '"' . $out . '"';
    }

    /** @return array{0:string,1:string} [public key base64, secret key base64] */
    public static function generateKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [base64_encode(sodium_crypto_sign_publickey($kp)), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    /** Deterministic keypair from a 32-byte seed (test vectors only). */
    public static function keypairFromSeed(string $seed): array
    {
        $kp = sodium_crypto_sign_seed_keypair($seed);
        return [base64_encode(sodium_crypto_sign_publickey($kp)), base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    public static function sign(string $message, string $secretKeyB64): string
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('invalid signing key');
        }
        return base64_encode(sodium_crypto_sign_detached($message, $sk));
    }

    public static function verify(string $message, string $sigB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($sigB64, true);
        $pk = base64_decode($publicKeyB64, true);
        if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $message, $pk);
    }

    /** The signed content of a job: exactly the job object's fields except "signature". */
    public static function jobMessage(array $job): string
    {
        unset($job['signature']);
        return self::canonical(self::toObject($job));
    }

    /** Recursively turns assoc arrays into stdClass so empty objects serialise as {}. Lists stay lists. */
    public static function toObject($v)
    {
        if ($v instanceof \stdClass) {
            $o = new \stdClass();
            foreach ((array) $v as $k => $x) {
                $o->$k = self::toObject($x);
            }
            return $o;
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map([self::class, 'toObject'], $v);
            }
            $o = new \stdClass();
            foreach ($v as $k => $x) {
                $o->$k = self::toObject($x);
            }
            return $o;
        }
        return $v;
    }
}
