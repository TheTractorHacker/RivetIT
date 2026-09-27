<?php

namespace ITFlow\Training\Kiosk\Core;

/**
 * Every kiosk secret is derived from config.php's $config_settings_enc_key (P3 spec §3.1): one
 * HMAC-SHA256 per fixed label, so rotating that key invalidates PIN hashes, setup codes, slips
 * and CSRF tokens together (risk R8). The raw key never leaves this object, and __debugInfo()
 * hides it from var_dump/print_r. An empty key is a KioskConfigException (bootstrap: 503).
 */
final class KioskKeys
{
    private function __construct(private readonly string $k)
    {
    }

    public static function fromSecret(string $encKey): self
    {
        if (trim($encKey) === '') {
            throw new KioskConfigException('Training kiosk is not configured');
        }
        return new self($encKey);
    }

    /** Raw 32 bytes; used only as an HMAC key for PIN hashing (Pin\PinHasher). */
    public function pinPepper(): string
    {
        return $this->derive('itflow-training-pin|v1');
    }

    public function setupPepper(): string
    {
        return $this->derive('itflow-training-setup|v1');
    }

    public function fpPepper(): string
    {
        return $this->derive('itflow-training-odoo-fp|v1');
    }

    /** 32 bytes: the sodium secretbox key for the encrypted slip batch. */
    public function slipKey(): string
    {
        return $this->derive('itflow-training-slips|v1');
    }

    /** Hex HMAC of "$scope|$tokenHash" (scope 'dev' | 'ks' | 'vid'). */
    public function csrf(string $scope, string $tokenHash): string
    {
        return hash_hmac('sha256', $scope . '|' . $tokenHash, $this->derive('itflow-training-kiosk-csrf|v1'));
    }

    /** The restricted token lesson_video.php carries instead of the session token (§4.1 step 6). */
    public function videoCsrf(string $ksessTokenHash, int $runId, string $lessonUid): string
    {
        return $this->csrf('vid', $ksessTokenHash . '|' . $runId . '|' . $lessonUid);
    }

    /** First 16 hex of HMAC "$kioskId:$contactId": binds a search result to this device (pick/pin_login). */
    public function pickSig(int $kioskId, int $contactId): string
    {
        return substr(hash_hmac('sha256', $kioskId . ':' . $contactId, $this->derive('itflow-training-kiosk-pick|v1')), 0, 16);
    }

    /**
     * First 16 hex of HMAC(ksess_token_hash): an opaque id for ONE kiosk session. The page's video options
     * (js/training_media_controls.js) tag the remembered captions choice with it in localStorage, so the
     * shared device never stores who was signed in (no name, no role) and two sessions never match.
     */
    public function prefKey(string $ksessTokenHash): string
    {
        return substr(hash_hmac('sha256', $ksessTokenHash, $this->derive('itflow-training-kiosk-prefs|v1')), 0, 16);
    }

    private function derive(string $label): string
    {
        return hash_hmac('sha256', $label, $this->k, true);
    }

    public function __debugInfo(): array
    {
        return ['key' => '(hidden)'];
    }
}
