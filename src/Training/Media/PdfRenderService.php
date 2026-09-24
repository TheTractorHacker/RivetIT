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
 *
 * A page counts as ready only while its file is there: an admin purge (MediaPurger offers the
 * pages of an unreferenced PDF with it) deletes page files but never the insert-only mpage rows.
 * Such a page is rendered again and ingested, which brings its file back (MediaStore logs
 * media.file_restored); its mpage row already exists, so it is not linked a second time.
 *
 * Limits: a PDF with more pages than Admin › Training allows (config_training_pdf_max_pages) is
 * never rendered, whatever the purpose it was uploaded for (resource files and KB copies are not
 * page-capped at upload). The first pdftoppm call of a request may take up to 60 s; later calls
 * get only what is left of the 25 s budget, and one that runs out is discarded and redone by the
 * next request - so a request stays near 60 s, far below the ~100 s proxy limit.
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
        $maxPages = $this->c->settings->pdfMaxPages;
        if ($maxPages > 0 && $pageCount > $maxPages) {
            throw MediaException::validation('media_id', "This PDF has $pageCount pages; the limit is $maxPages. Split it into smaller documents.");
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
            $pages = $this->linkedPages($pdfMediaId);
            $missing = [];
            for ($n = 1; $n <= $pageCount && count($missing) < self::BATCH_PAGES; $n++) {
                if (!($pages[$n]['live'] ?? false)) {
                    $missing[] = $n;
                }
            }
            if ($missing === []) {
                return $this->status($pdfMediaId);
            }

            $scratch = Process::makeScratchDir('itflow-trpdf');
            $deadline = microtime(true) + self::BUDGET_S;
            $linked = [];         // n => media_id (pages without an mpage row yet)
            $rendered = 0;
            $failure = null;
            foreach (self::chunks($missing) as [$first, $last]) {
                $left = $deadline - microtime(true);
                if ($rendered > 0 && $left <= 0) {
                    break;
                }
                try {
                    if ($rendered === 0) {
                        $files = PdfPager::renderRange($this->store->absolutePath($pdf), $first, $last, $scratch);
                    } else {
                        // Only the budget that is left; a chunk that does not finish in it is
                        // dropped whole and the next request renders it first.
                        $files = PdfPager::renderRangeWithin($this->store->absolutePath($pdf), $first, $last, $scratch, (int) ceil($left * 1000));
                        if ($files === null) {
                            break;
                        }
                    }
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
                        $rendered++;
                        @unlink($file);
                        $existing = $pages[$n] ?? null;
                        if ($existing === null) {
                            $linked[$n] = (int) $media['media_id'];
                        } elseif ((int) $media['media_id'] !== $existing['media_id']) {
                            // The page row is insert-only and names the old image; poppler now
                            // renders different bytes, so the purged image cannot come back.
                            throw MediaException::unsupported("Page $n of this PDF can no longer be prepared. Save the PDF again "
                                . '(for example File › Save As › PDF, or print it to a new PDF) and upload that copy.');
                        }
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

    /** Current progress without rendering anything. A page is ready only while its file is there. */
    public function status(int $pdfMediaId): array
    {
        $pdf = $this->pdfRow($pdfMediaId);
        $count = (int) $pdf['media_page_count'];
        $ready = 0;
        foreach ($this->linkedPages($pdfMediaId) as $n => $p) {
            if ($p['live'] && $n <= $count) {
                $ready++;
            }
        }
        return ['media_id' => $pdfMediaId, 'page_count' => $count, 'pages_ready' => $ready, 'done' => $count > 0 && $ready >= $count];
    }

    /**
     * Ready page counts per PDF for lists (MediaStore::toApi, the builder): linked pages whose
     * file is not purged (by the ledger; status() also checks the disk).
     *
     * @param list<int> $pdfMediaIds
     * @return array<int, int> pdf media id => ready pages (ids without pages are absent)
     */
    public static function readyCounts(\mysqli $db, array $pdfMediaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pdfMediaIds), static fn(int $i): bool => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::all($db, 'SELECT mpage_pdf_media_id, mpage_media_id FROM training_media_pages WHERE mpage_pdf_media_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk);
            $purged = MediaStore::purgedIds($db, array_map(static fn($r) => (int) $r['mpage_media_id'], $rows));
            foreach ($rows as $r) {
                $pdf = (int) $r['mpage_pdf_media_id'];
                $out[$pdf] ??= 0;
                if (!isset($purged[(int) $r['mpage_media_id']])) {
                    $out[$pdf]++;
                }
            }
        }
        return $out;
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

    /**
     * The PDF's linked pages: page number => its page media id and whether that file is live
     * (on disk with the recorded size, and not purged).
     *
     * @return array<int, array{media_id:int, live:bool}>
     */
    private function linkedPages(int $pdfMediaId): array
    {
        $rows = Db::all($this->c->db, 'SELECT p.mpage_number, ' . MediaStore::COLUMNS . '
            FROM training_media_pages p JOIN training_media m ON m.media_id = p.mpage_media_id
            WHERE p.mpage_pdf_media_id = ?', 'i', [$pdfMediaId]);
        $purged = MediaStore::purgedIds($this->c->db, array_map(static fn($r) => (int) $r['media_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $mid = (int) $r['media_id'];
            $out[(int) $r['mpage_number']] = ['media_id' => $mid, 'live' => !isset($purged[$mid]) && $this->store->fileOk($r)];
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
