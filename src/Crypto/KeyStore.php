<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyRingLoader;

/**
 * Where RivetIT finds its key ring (ADR-011): the key file when one exists, otherwise the legacy
 * $config_settings_enc_key from config.php as kid "k1", otherwise nothing.
 *
 * Key file path, first match wins:
 *   1. $config_keyfile in config.php (a string; '' switches the file lookup off)
 *   2. the RIVETIT_KEYFILE constant or environment variable
 *   3. /etc/rivetit/keys.json
 *
 * Nothing here ever creates a file. An existing key file that cannot be used is an ERROR state, never silently replaced by the legacy key
 * (that would hide a broken deployment); reads of values that the legacy key alone can open keep working, everything else fails closed.
 *
 * The result is cached per process, keyed on the file's mtime and size and on the legacy key, so a key file written by a CLI run, or a
 * test that switches the configuration, is seen by the next call without a restart.
 */
final class KeyStore
{
    public const DEFAULT_PATH = '/etc/rivetit/keys.json';

    /** @var array{sig:string, state:KeyState}|null */
    private static ?array $cache = null;

    /** The key file path in effect, or null when the file lookup is switched off. */
    public static function path(): ?string
    {
        if (array_key_exists('config_keyfile', $GLOBALS)) {
            $p = $GLOBALS['config_keyfile'];

            return is_string($p) && $p !== '' ? $p : null;
        }
        if (defined('RIVETIT_KEYFILE') && is_string(RIVETIT_KEYFILE) && RIVETIT_KEYFILE !== '') {
            return RIVETIT_KEYFILE;
        }
        $env = getenv('RIVETIT_KEYFILE');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::DEFAULT_PATH;
    }

    /** $config_settings_enc_key from config.php ('' when absent). */
    public static function legacyKey(): string
    {
        $k = $GLOBALS['config_settings_enc_key'] ?? '';

        return is_string($k) ? $k : '';
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function load(): KeyState
    {
        $path = self::path();
        $legacy = self::legacyKey();
        clearstatcache();
        $exists = $path !== null && is_file($path);
        $sig = ($path ?? '-') . '|' . ($exists ? (string) @filemtime($path) . ':' . (string) @filesize($path) : 'absent') . '|' . hash('sha256', $legacy);
        if (self::$cache !== null && self::$cache['sig'] === $sig) {
            return self::$cache['state'];
        }
        $state = self::build($path, $exists, $legacy);
        self::$cache = ['sig' => $sig, 'state' => $state];

        return $state;
    }

    private static function build(?string $path, bool $exists, string $legacy): KeyState
    {
        if ($exists && $path !== null) {
            try {
                $res = (new KeyRingLoader(null, false, dirname(__DIR__, 2)))->fromFile($path);

                return new KeyState($res->ring, 'file', $path, $res->warnings, null);
            } catch (CryptoException $e) {
                return new KeyState(KeyRing::empty(), 'file-error', $path, [], 'The key file ' . $path . ' cannot be used: ' . $e->getMessage());
            } catch (\Throwable $e) {
                return new KeyState(KeyRing::empty(), 'file-error', $path, [], 'The key file ' . $path . ' cannot be read (' . get_class($e) . ').');
            }
        }
        if ($legacy !== '') {
            try {
                return new KeyState(KeyGenerator::ringFromLegacySettingsKey($legacy), 'legacy', $path, [], null);
            } catch (CryptoException $e) {
                return new KeyState(KeyRing::empty(), 'legacy-error', $path, [], 'config.php $config_settings_enc_key is not usable: ' . $e->getMessage());
            }
        }

        return new KeyState(KeyRing::empty(), 'none', $path, [], null);
    }
}
