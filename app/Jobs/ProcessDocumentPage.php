<?php

namespace App\Jobs;

use App\Models\DocumentPage;
use App\Models\PageLine;
use App\Models\PageRun;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersCancelled;
use App\Services\Lines\PageLineReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Outline every handwritten line on a page, and/or read each outline.
 *
 * Three modes:
 *   full   - Scan with OCR on the markers as Staff aligned them: outline, crop, read.
 *   detect - the Detect button: straighten the page, fit the template to the
 *            page's own table, outline and crop. Nothing is read yet, so Staff
 *            can check the outlines first.
 *   read   - Scan with OCR after Detect: read the crops Detect already made.
 *
 * Everything is saved, so the Verify step loads results instead of
 * recomputing them.
 */
class ProcessDocumentPage implements ShouldQueue
{
    use Queueable;

    public const MODE_FULL = 'full';

    public const MODE_DETECT = 'detect';

    public const MODE_READ = 'read';

    /** A failed page is re-scanned by Staff, not retried behind their back. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $pageId,
        public readonly string $mode = self::MODE_FULL,
    ) {}

    public function handle(LineMarkers $markers, PageLineReader $reader): void
    {
        $page = DocumentPage::find($this->pageId);
        if ($page === null) {
            return;  // Pruned or submitted while waiting in the queue.
        }
        if ($this->stopIfCancelled($page)) {
            $this->record($page, PageRun::CANCELLED);  // Cancelled while waiting: it never started.

            return;
        }

        $started = hrtime(true);

        if ($this->mode !== self::MODE_READ) {
            // Cancelling while the lines are being found stops the detector
            // there, so the next page in the queue does not wait for it.
            try {
                $this->outline($page, $markers->stoppingWhen(
                    fn () => $this->currentStatus() === DocumentPage::STATUS_CANCELLED,
                ));
            } catch (LineMarkersCancelled) {
                // Discarded just below.
            }
            if ($this->stopIfCancelled($page)) {
                $this->record($page, PageRun::CANCELLED, $started);

                return;
            }
        }

        if ($this->mode === self::MODE_DETECT) {
            $page->forceFill(['status' => DocumentPage::STATUS_DETECTED, 'processed_at' => now()])->save();
            $this->record($page, PageRun::COMPLETED, $started);

            return;
        }

        $page->forceFill(['status' => DocumentPage::STATUS_READING])->save();
        // Either cancel (discard the page, or back to Detect's result) ends
        // the read at the next batch.
        $readStarted = hrtime(true);
        $reader->read($page, $page->lines()->get(), fn () => $this->currentStatus() !== DocumentPage::STATUS_READING);
        $readMs = (int) round((hrtime(true) - $readStarted) / 1_000_000);

        // A read cancelled from Detect's result is back to "detected" already;
        // any other cancelled page is discarded.
        $page->refresh();
        if ($this->stopIfCancelled($page) || $page->status !== DocumentPage::STATUS_READING) {
            $this->record($page, PageRun::CANCELLED, $started);

            return;
        }

        $page->forceFill([
            'status' => DocumentPage::STATUS_READY,
            'processed_at' => now(),
        ])->save();
        $this->record($page, PageRun::COMPLETED, $started, readMs: $readMs);
    }

    /**
     * Keep how this run ended for the dashboard. The page itself is deleted
     * when cancelled and pruned when abandoned, so it cannot be counted later.
     * Never lets a logging problem fail the page.
     *
     * @param  int|null  $started  hrtime(true) when the work began; null if it never did
     */
    private function record(DocumentPage $page, string $outcome, ?int $started = null, ?string $error = null, ?int $readMs = null): void
    {
        try {
            PageRun::create([
                'user_id' => $page->created_by,
                'document_page_id' => $page->getKey(),
                'mode' => $this->mode,
                'outcome' => $outcome,
                'duration_ms' => $started === null ? null : (int) round((hrtime(true) - $started) / 1_000_000),
                'read_ms' => $readMs,
                'lines' => $outcome === PageRun::COMPLETED ? $page->lines()->count() : null,
                'ocr_model_key' => $page->ocr_model_key,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** The page's status now, as Staff may have changed it meanwhile. */
    private function currentStatus(): ?string
    {
        return DocumentPage::whereKey($this->pageId)->value('status');
    }

    /**
     * Discard a page Staff cancelled: its record, lines and files. Returns
     * whether it was cancelled.
     */
    private function stopIfCancelled(DocumentPage $page): bool
    {
        if ($page->fresh()?->status !== DocumentPage::STATUS_CANCELLED) {
            return false;
        }
        Storage::disk('local')->deleteDirectory($page->directory());
        $page->delete();

        return true;
    }

    public function failed(?Throwable $exception): void
    {
        DocumentPage::whereKey($this->pageId)->update([
            'status' => DocumentPage::STATUS_FAILED,
            'error' => mb_substr($exception?->getMessage() ?: 'The page could not be processed.', 0, 2000),
        ]);

        // This instance is not the one that ran handle(), so it cannot say how long it took.
        if ($page = DocumentPage::find($this->pageId)) {
            $this->record($page, PageRun::FAILED, error: $exception?->getMessage() ?: 'The page could not be processed.');
        }
    }

    private function outline(DocumentPage $page, LineMarkers $markers): void
    {
        $disk = Storage::disk('local');
        $page->forceFill(['status' => DocumentPage::STATUS_DETECTING, 'error' => null])->save();

        // Outlining a page again (markers moved after Detect) names its crops
        // afresh, so the previous run's would otherwise be left behind. What
        // the detector found is kept: it is beside the crops, not inside them.
        $disk->deleteDirectory($page->directory().'/crops');

        $result = $this->mode === self::MODE_DETECT
            ? $markers->detect($disk->path($page->image_path), $page->geometry, $disk->path($page->directory()))
            : $markers->process($disk->path($page->image_path), $page->geometry, $disk->path($page->directory()));

        DB::transaction(function () use ($page, $result) {
            // Detect straightens the page in place, and so does a scan whose
            // ledger grid Staff tilted; that can change its size, and the
            // markers were moved onto the straightened page: keep both.
            if ($this->mode === self::MODE_DETECT || isset($result['deskew'])) {
                [$width, $height] = $result['size'];
                $page->forceFill([
                    'width' => $width,
                    'height' => $height,
                    'deskew_degrees' => $result['deskew'] ?? 0,
                    'geometry' => $result['geometry'] ?? $page->geometry,
                ])->save();
            }

            $page->forceFill(['notes' => DocumentPage::cleanNotes($result['notes'] ?? [])])->save();

            // forceDelete, not delete: lines a reviewer removed are kept only
            // until the page is outlined again, and these rows are replaced now.
            $page->lines()->withTrashed()->forceDelete();

            foreach ($result['lines'] as $position => $line) {
                $page->lines()->create([
                    'position' => $position,
                    'source' => $line['source'],
                    'column_index' => $line['column_index'] ?? null,
                    'column_name' => mb_substr((string) ($line['column'] ?? ''), 0, 500),
                    'row' => $line['row'] ?? null,
                    'person_group' => $line['person_group'] ?? null,
                    'person_field_order' => $line['person_field_order'] ?? null,
                    'polygon' => $line['polygon'],
                    'baseline' => $line['baseline'] ?? null,
                    'bbox' => $line['bbox'],
                    'crop_path' => $page->directory().'/'.$line['crop'],
                    'flags' => array_values(array_intersect(
                        $line['flags'] ?? [],
                        [PageLine::FLAG_NO_ROW, PageLine::FLAG_SHARED_CELL],
                    )),
                ]);
            }
        });
    }
}
