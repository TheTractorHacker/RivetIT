<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RowHasher;

/**
 * Turns a stored PDF into page images, in resumable batches (spec §3.3, §4.3).
 *
 * Learners never see the PDF itself: every page becomes a `page` media row (a 1600 px JPEG) and
 * is linked to the PDF in training_media_pages. Rendering a 150-page manual takes minutes, so it
 * is split into short requests: renderNext() renders at most 8 missing pages or stops after 25 s,
 * and the client (TrainingUploader, the builder's auto-resume) keeps calling pdf_render_next
 * until `done`. No request comes near the ~100 s proxy limit, and closing the tab mid-render
 * loses nothing - the next call picks up the missing pages.
 *
 *   Db::lock('trpdf:<id>', 0)      one renderer per PDF; a second caller gets 409 busy at once
 *   pages -> ingestBytes('page')   at depth 0 (each page is its own media.stored event)
 *   ONE Db::tx per batch            the batch's mpage rows + ONE media.pages_linked event
 *   scratch dir                     removed in finally
 */
final class PdfRenderService
{
    public const BATCH_PAGES = 8;
    public const BUDGET_S = 25.0;
    /** Pages rendered per pdftoppm call: small enough to check the time budget between calls. */
    private const CHUNK = 4;

    private MediaStore $store;

    public function __construct(private readonly Ctx $c, ?MediaStore $store = null)
    {
        $this->store = $store ?? new MediaStore($c);
    }

    /**
     * @return array{media_id:int, page_count:int, pages_ready:int, done:bool}
     * @throws MediaException 404 not_found, 409 busy, 415 (poppler), 413 budget_exceeded
     */
    public function renderNext(int $pdfMediaId): array
    {
        MediaStore::assertOutsideTx();
        $pdf = $this->pdfRow($pdfMediaId);
        $pageCount = (int) $pdf['media_page_count'];
        $status = $this->status($pdfMediaId);
        if ($status['done']) {
            return $status;
        }
        if (!$this->store->fileOk($pdf)) {
            throw MediaException::notFound('The PDF file is missing. Upload it again.');
        }

        $lockName = 'trpdf:' . $pdfMediaId;
        if (!Db::lock($this->c->db, $lockName, 0)) {
            throw MediaException::busy('This PDF is already being prepared. Its pages will appear shortly.');
        }
        $scratch = null;
        try {
            // Re-read under the lock: another request may have just finished a batch.
            $have = $this->existingNumbers($pdfMediaId);
            $missing = [];
            for ($n = 1; $n <= $pageCount && count($missing) < self::BATCH_PAGES; $n++) {
                if (!isset($have[$n])) {
                    $missing[] = $n;
                }
            }
            if ($missing === []) {
                return $this->status($pdfMediaId);
            }

            $scratch = Process::makeScratchDir('itflow-trpdf');
            $deadline = microtime(true) + self::BUDGET_S;
            $linked = [];         // n => media_id
            $failure = null;
            foreach (self::chunks($missing) as [$first, $last]) {
                if ($linked !== [] && microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $files = PdfPager::renderRange($this->store->absolutePath($pdf), $first, $last, $scratch);
                    if ($files === []) {
                        throw MediaException::unsupported("Page $first of this PDF could not be prepared.");
                    }
                    foreach ($files as $n => $file) {
                        $size = @getimagesize($file);
                        if ($size === false || $size[2] !== IMAGETYPE_JPEG) {
                            throw MediaException::unsupported("Page $n of this PDF could not be prepared.");
                        }
                        $media = $this->store->ingestFile($file, 'page', 'image/jpeg', 'jpg', null,
                            ['width' => (int) $size[0], 'height' => (int) $size[1]], 'pdf_page');
                        $linked[$n] = (int) $media['media_id'];
                        @unlink($file);
                    }
                } catch (MediaException $e) {
                    $failure = $e;
                    break;
                }
            }

            if ($linked !== []) {
                $this->link($pdfMediaId, $linked);
            }
            if ($failure !== null) {
                throw $failure;
            }
            return $this->status($pdfMediaId);
        } finally {
            if ($scratch !== null) {
                Process::removeScratchDir($scratch);
            }
            Db::unlock($this->c->db, $lockName);
        }
    }

    /** Current progress without rendering anything. */
    public function status(int $pdfMediaId): array
    {
        $pdf = $this->pdfRow($pdfMediaId);
        $count = (int) $pdf['media_page_count'];
        $ready = count($this->existingNumbers($pdfMediaId));
        return ['media_id' => $pdfMediaId, 'page_count' => $count, 'pages_ready' => $ready, 'done' => $count > 0 && $ready >= $count];
    }

    /**
     * The rendered pages of a PDF in page order.
     *
     * @return list<array{n:int, media_id:int, w:?int, h:?int, url:string}>
     */
    public function pages(int $pdfMediaId): array
    {
        $rows = Db::all($this->c->db, 'SELECT p.mpage_number, p.mpage_media_id, m.media_width, m.media_height
            FROM training_media_pages p JOIN training_media m ON m.media_id = p.mpage_media_id
            WHERE p.mpage_pdf_media_id = ? ORDER BY p.mpage_number', 'i', [$pdfMediaId]);
        $out = [];
        foreach ($rows as $r) {
            $mid = (int) $r['mpage_media_id'];
            $out[] = [
                'n' => (int) $r['mpage_number'],
                'media_id' => $mid,
                'w' => $r['media_width'] === null ? null : (int) $r['media_width'],
                'h' => $r['media_height'] === null ? null : (int) $r['media_height'],
                'url' => MediaStore::url($mid),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------------------------------

    /** @param array<int, int> $linked page number => page media id */
    private function link(int $pdfMediaId, array $linked): void
    {
        ksort($linked);
        Db::tx($this->c->db, function () use ($pdfMediaId, $linked): void {
            $db = $this->c->db;
            Db::ensureUtf8mb4($db);
            $pages = [];
            $shas = [];
            foreach ($linked as $n => $mediaId) {
                $row = [
                    'mpage_pdf_media_id' => (string) $pdfMediaId,
                    'mpage_number' => (string) $n,
                    'mpage_media_id' => (string) $mediaId,
                    'mpage_created_at_utc' => Clock::nowUtc(),
                    'mpage_hash_v' => '1',
                ];
                $row['mpage_row_sha256'] = RowHasher::hash('training_media_pages', $row);
                Db::exec($db, 'INSERT INTO training_media_pages (mpage_pdf_media_id, mpage_number, mpage_media_id, mpage_created_at_utc, mpage_hash_v, mpage_row_sha256)
                    VALUES (?, ?, ?, ?, ?, ?)', 'ssssss', array_values($row));
                $pages[] = ['n' => (int) $n, 'media_id' => (int) $mediaId, 'row_sha256' => $row['mpage_row_sha256']];
                $shas[] = $row['mpage_row_sha256'];
            }
            Ledger::append($db, [
                'type' => 'media.pages_linked',
                'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
                'entity_type' => 'media',
                'entity_id' => $pdfMediaId,
                'entity_sha256' => Ledger::pagesEntitySha($shas),
                'payload' => ['pdf_media_id' => $pdfMediaId, 'pages' => $pages],
                'user_agent' => $this->c->userAgent,
            ]);
        });
    }

    private function pdfRow(int $pdfMediaId): array
    {
        $pdf = $this->store->get($pdfMediaId);
        if ($pdf === null || $pdf['media_kind'] !== 'pdf') {
            throw MediaException::notFound('That PDF was not found.');
        }
        if ((int) $pdf['media_page_count'] < 1) {
            throw MediaException::unsupported('That PDF has no pages to show.');
        }
        return $pdf;
    }

    /** @return array<int, true> */
    private function existingNumbers(int $pdfMediaId): array
    {
        $out = [];
        foreach (Db::all($this->c->db, 'SELECT mpage_number FROM training_media_pages WHERE mpage_pdf_media_id = ?', 'i', [$pdfMediaId]) as $r) {
            $out[(int) $r['mpage_number']] = true;
        }
        return $out;
    }

    /**
     * Groups sorted page numbers into contiguous runs of at most CHUNK pages.
     *
     * @param list<int> $numbers
     * @return list<array{0:int, 1:int}>
     */
    private static function chunks(array $numbers): array
    {
        $out = [];
        $start = null;
        $prev = null;
        foreach ($numbers as $n) {
            if ($start !== null && ($n !== $prev + 1 || $n - $start >= self::CHUNK)) {
                $out[] = [$start, $prev];
                $start = null;
            }
            $start ??= $n;
            $prev = $n;
        }
        if ($start !== null) {
            $out[] = [$start, $prev];
        }
        return $out;
    }
}
