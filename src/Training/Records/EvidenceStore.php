<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Media\FileValidator;
use ITFlow\Training\Media\ImageProcessor;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\People\Scope;

/**
 * Evidence scans: signed sign-in sheets, practical checklists, outside cards (Phase 2 spec
 * §1.4 #13, §3.5, §4.3, §8 "Evidence").
 *
 * STORAGE. training_media rows of kind 'evidence' under uploads/training/evidence/<aa>/<sha>.<ext>
 * (MediaStore; nginx denies the directory outright). Only PDF, JPEG and PNG are kept: images are
 * re-encoded by GD (EXIF and anything hidden after the image data are dropped; WebP and GIF come
 * out as JPEG/PNG), PDFs are stored as uploaded. Each file is capped by
 * config_training_evidence_max_mb, separately from the content budget (MediaUsage::liveBytes
 * excludes evidence), and evidence is never purged.
 *
 * ATTACHING. An upload never hands out a usable media id. It returns an ATTACH TOKEN: a
 * Core\Scratch entry bound to the uploading user, stored as sha256, valid 8 hours. Records,
 * sessions and evaluations accept only that token (resolveToken), so a client can never attach
 * - or reveal - somebody else's scan by guessing ids. Because MediaStore dedupes on
 * (sha, kind), two users uploading the same file get the same media row but each their own
 * working token.
 *
 * SERVING (agent/training_evidence.php). By media id only when a record the viewer may see
 * references the scan (canServe); otherwise only to its uploader through their own token
 * (previewMediaId). Everything else is a 404.
 */
final class EvidenceStore
{
    public const KIND = 'evidence';
    public const SCRATCH_KIND = 'evidence';
    public const TOKEN_TTL_S = 28800;
    public const TOKEN_RE = '/^[0-9a-f]{32}$/D';

    public function __construct(private readonly Ctx $c, private readonly ?MediaStore $store = null)
    {
    }

    /**
     * Stores an uploaded scan and returns its media row and a fresh attach token.
     * Never inside a transaction (MediaStore ingests in its own).
     *
     * @return array{media:array, attach_token:string}
     * @throws MediaException 413 too_large | 415 unsupported_type | 422 validation
     */
    public function ingestUpload(string $tmp, string $clientName): array
    {
        if ($this->c->userId < 1) {
            throw new \LogicException('EvidenceStore::ingestUpload needs a signed-in user (attach tokens are user-bound)');
        }
        $settings = RecordsSettings::fromDb($this->c->db);
        $size = @filesize($tmp);
        if ($size === false || $size < 1) {
            throw MediaException::unsupported('That file is empty.');
        }
        if ($size > $settings->evidenceMaxBytes) {
            throw new MediaException(413, 'too_large', 'That scan is larger than the ' . intdiv($settings->evidenceMaxBytes, 1048576) . ' MB limit for evidence.');
        }
        try {
            $info = FileValidator::classify($tmp, 'resource_file', $clientName, $this->c->settings);
        } catch (MediaException $e) {
            if ($e->errCode === 'unsupported_type' && !str_contains($e->getMessage(), 'HEIC')) {
                throw MediaException::unsupported(self::typeMessage());
            }
            throw $e;
        }
        $store = $this->store ?? new MediaStore($this->c);
        if ($info['kind'] === 'pdf') {
            $m = $store->ingestFile($tmp, self::KIND, 'application/pdf', 'pdf', $clientName, ['page_count' => $info['meta']['page_count'] ?? null], 'evidence');
        } elseif ($info['kind'] === 'image') {
            $img = ImageProcessor::reencodeFile($tmp);
            if (!in_array($img['ext'], ['jpg', 'png'], true)) {
                throw MediaException::unsupported(self::typeMessage());
            }
            $m = $store->ingestBytes($img['bytes'], self::KIND, $img['mime'], $img['ext'], $clientName,
                ['width' => $img['width'], 'height' => $img['height']], 'evidence');
        } else {
            throw MediaException::unsupported(self::typeMessage());
        }
        $mediaId = (int) $m['media_id'];
        $token = Scratch::put(self::SCRATCH_KIND, $this->c->userId, ['media_id' => $mediaId], self::TOKEN_TTL_S);
        return ['media' => $m, 'attach_token' => $token];
    }

    /**
     * The media id behind an attach token of THIS user, or null when no token was given.
     * Invalid, expired, other-user or non-evidence tokens are 422 evidence_token_invalid.
     */
    public static function resolveToken(Ctx $c, ?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }
        $bad = new ApiException(422, 'evidence_token_invalid', 'The attached scan has expired or was not uploaded by you. Upload it again.',
            ['evidence_token' => 'Upload the scan again.']);
        if (preg_match(self::TOKEN_RE, $token) !== 1 || $c->userId < 1) {
            throw $bad;
        }
        $data = Scratch::get(self::SCRATCH_KIND, $token, $c->userId);
        $id = (int) ($data['media_id'] ?? 0);
        if ($id < 1) {
            throw $bad;
        }
        $row = Db::one($c->db, 'SELECT media_kind FROM training_media WHERE media_id = ?', 'i', [$id]);
        if ($row === null || $row['media_kind'] !== self::KIND) {
            throw $bad;
        }
        return $id;
    }

    /**
     * May this viewer receive the bytes of $mediaRow by id? Only an evidence row referenced by a
     * completion or evaluation whose person is in scope, or by a session in scope (its department,
     * or any of its attendees). Scope is the person's CURRENT department (spec §0 #3).
     */
    public static function canServe(Ctx $c, Scope $s, array $mediaRow): bool
    {
        $id = (int) ($mediaRow['media_id'] ?? 0);
        if ($id < 1 || ($mediaRow['media_kind'] ?? null) !== self::KIND || $c->level < 1 || $s->isNone()) {
            return false;
        }
        $db = $c->db;
        [$scopeSql, $st, $sp] = $s->sqlIn('c.contact_client_id');
        $hit = Db::one($db, "SELECT 1 AS ok FROM training_completions tc JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_evidence_media_id = ?$scopeSql LIMIT 1", 'i' . $st, array_merge([$id], $sp));
        if ($hit !== null) {
            return true;
        }
        $hit = Db::one($db, "SELECT 1 AS ok FROM training_evaluations te JOIN contacts c ON c.contact_id = te.evaluation_contact_id
            WHERE te.evaluation_evidence_media_id = ?$scopeSql LIMIT 1", 'i' . $st, array_merge([$id], $sp));
        if ($hit !== null) {
            return true;
        }
        $sessions = Db::all($db, 'SELECT tsession_id, tsession_client_id FROM training_sessions WHERE tsession_evidence_media_id = ?', 'i', [$id]);
        foreach ($sessions as $ses) {
            if ($s->isAll() || $s->allows((int) $ses['tsession_client_id'])) {
                return true;
            }
            $hit = Db::one($db, "SELECT 1 AS ok FROM training_session_attendees ta JOIN contacts c ON c.contact_id = ta.tattendee_contact_id
                WHERE ta.tattendee_tsession_id = ? AND ta.tattendee_removed_at_utc IS NULL$scopeSql LIMIT 1",
                'i' . $st, array_merge([(int) $ses['tsession_id']], $sp));
            if ($hit !== null) {
                return true;
            }
        }
        return false;
    }

    /** The uploader's own attach token => media id (preview before the record is saved), else null. */
    public static function previewMediaId(Ctx $c, string $token): ?int
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1 || $c->userId < 1) {
            return null;
        }
        $data = Scratch::get(self::SCRATCH_KIND, $token, $c->userId);
        $id = (int) ($data['media_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Evidence {media_id, url, mime, original_name} for API shapes, or null. */
    public static function ref(\mysqli $db, ?int $mediaId): ?array
    {
        if ($mediaId === null || $mediaId < 1) {
            return null;
        }
        $row = Db::one($db, 'SELECT media_id, media_mime, media_ext, media_original_name, media_kind FROM training_media WHERE media_id = ?', 'i', [$mediaId]);
        if ($row === null || $row['media_kind'] !== self::KIND) {
            return null;
        }
        return [
            'media_id' => (int) $row['media_id'],
            'url' => self::url((int) $row['media_id']),
            'mime' => (string) $row['media_mime'],
            'original_name' => $row['media_original_name'] === null ? null : (string) $row['media_original_name'],
        ];
    }

    public static function url(int $mediaId, bool $download = false): string
    {
        return '/agent/training_evidence.php?m=' . $mediaId . ($download ? '&dl=1' : '');
    }

    public static function previewUrl(string $attachToken): string
    {
        return '/agent/training_evidence.php?a=' . $attachToken;
    }

    private static function typeMessage(): string
    {
        return 'Scans must be a PDF, JPEG or PNG file.';
    }
}
