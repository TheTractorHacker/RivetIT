<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Core\Hashed;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Finger signatures (P3 spec §3.4, §8 "Evidence integrity"). The kiosk pad always exports a fixed
 * 1200×400 PNG (transparent background, dark ink); the server accepts exactly that, measures the
 * ink with the same rule as the client (Kiosk.ui.signaturePad inkPx(): alpha >= 32 of 255, which
 * is GD alpha <= 111), refuses near-white pixels as ink, and stores a GD RE-ENCODED copy - never
 * the client's bytes - with its sha256. The verifier re-derives tsig_png_sha256 from the stored
 * base64.
 *
 * prepare() runs BEFORE any PIN step-up, so a bad drawing never costs a PIN entry.
 */
final class SignatureService
{
    public const W = 1200;
    public const H = 400;
    public const MAX_B64 = 280000;
    public const MAX_PNG = 200000;
    public const MIN_INK = 500;
    public const PURPOSES = ['learner_attest', 'ack', 'attendee', 'trainer', 'evaluator', 'evaluatee'];
    private const PREFIX = 'data:image/png;base64,';

    /**
     * @return array{png:string, b64:string, sha256:string, w:int, h:int, ink:int}
     * @throws SignatureException signature_invalid | signature_empty
     */
    public static function prepare(mixed $dataUrl): array
    {
        if (!is_string($dataUrl) || strlen($dataUrl) > self::MAX_B64 + strlen(self::PREFIX)
            || preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#D', $dataUrl) !== 1) {
            throw new SignatureException('signature_invalid');
        }
        $bytes = base64_decode(substr($dataUrl, strlen(self::PREFIX)), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_PNG) {
            throw new SignatureException('signature_invalid');
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_PNG || (int) $info[0] !== self::W || (int) $info[1] !== self::H) {
            throw new SignatureException('signature_invalid');
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            throw new SignatureException('signature_invalid');
        }
        try {
            if (imagesx($im) !== self::W || imagesy($im) !== self::H) {
                throw new SignatureException('signature_invalid');
            }
            if (!imageistruecolor($im)) {
                imagepalettetotruecolor($im);
            }
            $ink = self::inkPixels($im);
            if ($ink < self::MIN_INK) {
                throw new SignatureException('signature_empty');
            }
            imagealphablending($im, false);
            imagesavealpha($im, true);
            ob_start();
            $ok = imagepng($im, null, 6);
            $png = (string) ob_get_clean();
            if (!$ok || $png === '') {
                throw new SignatureException('signature_invalid');
            }
        } finally {
            imagedestroy($im);
        }
        return ['png' => $png, 'b64' => base64_encode($png), 'sha256' => hash('sha256', $png), 'w' => self::W, 'h' => self::H, 'ink' => $ink];
    }

    /**
     * Pixels that count as ink: GD alpha <= 111 (client alpha >= 32/255) and not near-white.
     */
    public static function inkPixels(\GdImage $im): int
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $n = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 24) & 0x7F) > 111) {
                    continue;
                }
                if ((($c >> 16) & 0xFF) > 240 && (($c >> 8) & 0xFF) > 240 && ($c & 0xFF) > 240) {
                    continue;
                }
                $n++;
            }
        }
        return $n;
    }

    /**
     * INSIDE the caller's Db::tx: one hashed training_signatures row. The caller appends the
     * signature.captured event (see event()) with its other events, last.
     *
     * @param array $meta purpose, contact_id, signer_name, statement_sha256, kiosk_id, ksess_id, run_id, tsession_id
     * @return array{id:int, sha:string}
     */
    public static function insert(\mysqli $db, array $prep, array $meta): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('SignatureService::insert must run inside Db::tx');
        }
        $purpose = (string) ($meta['purpose'] ?? '');
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('SignatureService: bad purpose');
        }
        $st = $meta['statement_sha256'] ?? null;
        if ($st !== null && preg_match('/^[0-9a-f]{64}$/D', (string) $st) !== 1) {
            throw new \InvalidArgumentException('SignatureService: statement_sha256 must be 64 lowercase hex');
        }
        $s = static fn($v): ?string => $v === null ? null : (string) (int) $v;
        return Hashed::insert($db, 'training_signatures', [
            'tsig_purpose' => $purpose,
            'tsig_contact_id' => $s($meta['contact_id'] ?? null),
            'tsig_signer_name' => (string) Text::clip((string) ($meta['signer_name'] ?? ''), 200),
            'tsig_png_sha256' => $prep['sha256'],
            'tsig_width' => (string) (int) $prep['w'],
            'tsig_height' => (string) (int) $prep['h'],
            'tsig_ink_px' => (string) (int) $prep['ink'],
            'tsig_statement_sha256' => $st,
            'tsig_kiosk_id' => $s($meta['kiosk_id'] ?? null),
            'tsig_ksess_id' => $s($meta['ksess_id'] ?? null),
            'tsig_run_id' => $s($meta['run_id'] ?? null),
            'tsig_tsession_id' => $s($meta['tsession_id'] ?? null),
            'tsig_captured_at_utc' => KTime::now(),
            'tsig_png_base64' => $prep['b64'],
        ]);
    }

    /** The signature.captured ledger event for an insert() result (merge the actor fields in). */
    public static function event(array $ins, string $purpose, ?int $runId, ?int $tsessionId, ?int $subjectContactId, ?int $courseId = null): array
    {
        return [
            'type' => 'signature.captured',
            'subject_contact_id' => $subjectContactId,
            'course_id' => $courseId,
            'entity_type' => 'signature',
            'entity_id' => (int) $ins['id'],
            'entity_sha256' => (string) $ins['sha'],
            'payload' => ['purpose' => $purpose, 'run_id' => $runId, 'tsession_id' => $tsessionId],
        ];
    }
}
