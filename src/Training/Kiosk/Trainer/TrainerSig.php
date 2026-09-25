<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Learn\SignatureException;
use ITFlow\Training\Kiosk\Learn\SignatureService;

/**
 * Finger signatures in trainer mode (attendee, trainer, evaluator, evaluatee) through lane K3's
 * Kiosk\Learn\SignatureService - one implementation of the fixed 1200x400, GD re-encoded,
 * ink-checked, row-hashed training_signatures row (P3 spec §3.4).
 *
 * prepare() always runs BEFORE the PIN step-up, so a bad signature never costs a PIN entry.
 * insert() runs inside the caller's Db::tx and returns the signature.captured event for the
 * caller to append LAST (§0 "ledger last").
 */
final class TrainerSig
{
    public const PURPOSES = ['attendee', 'trainer', 'evaluator', 'evaluatee'];

    /** Lane K3's SignatureService ships in the same build. */
    public static function available(): bool
    {
        return true;
    }

    /**
     * The prepared signature, or null when none is required and none was given.
     * Maps K3's SignatureException to 422 signature_invalid / signature_empty.
     */
    public static function prepare(mixed $dataUrl, bool $required): ?array
    {
        if ($dataUrl === null || $dataUrl === '') {
            if ($required) {
                throw new ApiException(422, 'signature_empty', 'Please sign in the box.', ['signature_png' => 'Required.']);
            }
            return null;
        }
        if (!is_string($dataUrl)) {
            throw new ApiException(422, 'signature_invalid', 'That signature could not be read. Clear it and sign again.', ['signature_png' => 'Invalid.']);
        }
        try {
            return SignatureService::prepare($dataUrl);
        } catch (SignatureException $e) {
            $code = $e->reason === 'signature_empty' ? 'signature_empty' : 'signature_invalid';
            throw new ApiException(422, $code, $code === 'signature_empty' ? 'Please sign in the box.' : 'That signature could not be read. Clear it and sign again.',
                ['signature_png' => $code === 'signature_empty' ? 'Required.' : 'Invalid.']);
        }
    }

    /**
     * INSIDE the caller's Db::tx: inserts the signature row and returns
     * ['id' => tsig_id, 'sha' => row sha, 'event' => the signature.captured event to append last].
     *
     * @param array{purpose:string, contact_id:?int, signer_name:string, statement_sha256:?string, run_id?:?int, tsession_id?:?int} $meta
     */
    public static function insert(KioskCtx $k, array $prep, array $meta): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('TrainerSig::insert must run inside Db::tx');
        }
        if (!in_array($meta['purpose'] ?? '', self::PURPOSES, true)) {
            throw new \InvalidArgumentException('TrainerSig: bad purpose');
        }
        $full = [
            'purpose' => (string) $meta['purpose'],
            'contact_id' => isset($meta['contact_id']) ? (int) $meta['contact_id'] : null,
            'signer_name' => (string) $meta['signer_name'],
            'statement_sha256' => $meta['statement_sha256'] ?? null,
            'kiosk_id' => $k->kioskId() > 0 ? $k->kioskId() : null,
            'ksess_id' => $k->ksessId(),
            'run_id' => isset($meta['run_id']) ? (int) $meta['run_id'] : null,
            'tsession_id' => isset($meta['tsession_id']) ? (int) $meta['tsession_id'] : null,
        ];
        $r = SignatureService::insert($k->db(), $prep, $full);
        $id = $r['id'];
        $sha = $r['sha'];
        $event = array_merge($k->eventBase(), [
            'type' => 'signature.captured',
            'subject_contact_id' => $full['contact_id'],
            'entity_type' => 'signature',
            'entity_id' => $id,
            'entity_sha256' => $sha,
            'payload' => ['purpose' => $full['purpose'], 'run_id' => $full['run_id'], 'tsession_id' => $full['tsession_id']],
        ]);
        return ['id' => $id, 'sha' => $sha, 'event' => $event];
    }

    /** sha256 of the exact statement text a signer confirmed (stored as tsig_statement_sha256). */
    public static function statementSha(string $text): string
    {
        return hash('sha256', $text);
    }
}
