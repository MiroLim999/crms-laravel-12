<?php

namespace App\Jobs;

use App\Models\DocumentPage;
use App\Models\PageLine;
use App\Models\PageRun;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersCancelled;
use App\Services\Lines\PageLineReader;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Outline every handwritten line on a page, and/or read each outline.
 *
 * Three modes:
 *   full   - Scan with OCR on the markers as they were aligned: outline, crop, read.
 *   detect - the Detect button: straighten the page, fit the template to the
 *            page's own table, outline and crop. Nothing is read yet, so the
 *            user can check the outlines first.
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

    /** A failed page is re-scanned by the user, not retried behind their back. */
    public int $tries = 1;

    public int $timeout = 900;

    /**
     * Which old line became which new one when readings were kept (old id => new id): a line
     * cut out exactly as before (its reading kept), or the same outline in the same cell
     * (read again). What was typed on the old line follows it either way.
     */
    public const LINE_MAP = 'line_map.json';

    public function __construct(
        public readonly int $pageId,
        public readonly string $mode = self::MODE_FULL,
        // Re-align: a line whose crop is exactly as before keeps its reading; only the rest is read.
        public readonly bool $keepReadings = false,
    ) {}

    public function handle(LineMarkers $markers, PageLineReader $reader, OcrClient $ocr): void
    {
        $page = DocumentPage::find($this->pageId);
        if ($page === null) {
            return;  // Pruned or submitted while waiting in the queue.
        }
        if ($this->stopIfCancelled($page)) {
            $this->record($page, PageRun::CANCELLED);  // Cancelled while waiting: it never started.

            return;
        }

        // A page before this one found the OCR service down: if it still is, fail
        // now (seconds) rather than find every line first (about 20 s) and fail
        // at the read. Scan all stops the batch on a second such page.
        if ($this->mode !== self::MODE_DETECT && ($reason = $ocr->stillDown()) !== null) {
            throw OcrServiceException::unreachable($reason);
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
        // Kept readings are not read again: only the lines that changed are.
        $toRead = $page->lines()->get();
        if ($this->keepReadings) {
            $toRead = $toRead->whereNull('ocr_text')->values();
        }
        try {
            $reader->read($page, $toRead, fn () => $this->currentStatus() !== DocumentPage::STATUS_READING);
        } catch (OcrServiceException $exception) {
            if ($exception->isUnreachable()) {
                $ocr->markDown();  // the next pages check before finding their lines
            }
            throw $exception;
        }
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

    /** The page's status now, as the user may have changed it meanwhile. */
    private function currentStatus(): ?string
    {
        return DocumentPage::whereKey($this->pageId)->value('status');
    }

    /**
     * Discard a cancelled page: its record, lines and files. Returns
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

        // Re-align: what each line read before, by the exact crop it was read from, so
        // a line cut out the same again keeps it. A previous map is stale from here on.
        $disk->delete($page->directory().'/'.self::LINE_MAP);
        $previous = $this->keepReadings ? $this->readingsByCrop($page) : [];

        // Outlining a page again (markers moved after Detect) names its crops
        // afresh, so the previous run's would otherwise be left behind. What
        // the detector found is kept: it is beside the crops, not inside them.
        $disk->deleteDirectory($page->directory().'/crops');

        $this->startFromTheImageTheMarkersWerePlacedOn($page);

        $result = $this->mode === self::MODE_DETECT
            ? $markers->detect($disk->path($page->image_path), $page->geometry, $disk->path($page->directory()))
            : $markers->process($disk->path($page->image_path), $page->geometry, $disk->path($page->directory()));

        $kept = [];
        $unmatched = [];
        DB::transaction(function () use ($page, $result, $disk, &$previous, &$kept, &$unmatched) {
            // Detect straightens the page in place, and so does a scan whose
            // ledger grid tilted by hand; that can change its size, and the
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
                $created = $page->lines()->create([
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

                // The same crop as before: the same handwriting, so the same reading.
                $matched = false;
                if ($previous !== [] && $disk->exists($created->crop_path)) {
                    $hash = md5((string) $disk->get($created->crop_path));
                    if (! empty($previous[$hash])) {
                        $old = array_shift($previous[$hash]);
                        $created->forceFill([
                            'ocr_text' => $old['text'],
                            'ocr_confidence' => $old['confidence'],
                            'ocr_error' => $old['error'],
                        ])->save();
                        $kept[$old['id']] = $created->getKey();
                        $matched = true;
                    }
                }
                if ($previous !== [] && ! $matched) {
                    $unmatched[] = $created;
                }
            }

            // Not cut out exactly as before, but the same outline in the same cell (a tilted
            // page's first re-read cuts a few pixels differently): read again, and what was
            // typed on it still follows it.
            $left = collect($previous)->flatten(1)->filter(fn ($old) => ! isset($kept[$old['id']]));
            foreach ($unmatched as $created) {
                $key = $this->cellKey($created->polygon, $created->column_index, $created->row);
                $old = $left->first(fn ($candidate) => $candidate['cell'] === $key);
                if ($old !== null) {
                    $kept[$old['id']] = $created->getKey();
                    $left = $left->reject(fn ($candidate) => $candidate['id'] === $old['id']);
                }
            }
        });

        if ($this->keepReadings) {
            $disk->put($page->directory().'/'.self::LINE_MAP, json_encode((object) $kept));
        }
    }

    /**
     * The page image as it was before the line finder last ran on it (see below).
     */
    public const INPUT_IMAGE = 'page.input.png';

    /**
     * The line finder straightens a tilted page in place (page.png). Sent the
     * same markers again - a page that failed after its lines were found (the
     * OCR service down, say), scanned again - it would turn the page a second
     * time, as those markers were placed on the page before it was turned.
     * So the image each run starts from is kept, and the same markers start
     * from it again. New markers (Re-align, moved after Smoke test) were
     * placed on the page as it is now, which is then kept instead.
     */
    private function startFromTheImageTheMarkersWerePlacedOn(DocumentPage $page): void
    {
        $disk = Storage::disk('local');
        if ($page->image_path === '' || ! is_file($disk->path($page->image_path))) {
            return;
        }
        $input = $page->directory().'/'.self::INPUT_IMAGE;
        $lastMarkers = $page->directory().'/geometry.json';  // what LineMarkers gave the last run

        $sameMarkers = $disk->exists($input) && $disk->exists($lastMarkers)
            && json_decode((string) $disk->get($lastMarkers), true) == $page->geometry;
        if (! $sameMarkers) {
            $disk->put($input, (string) $disk->get($page->image_path));

            return;
        }

        $disk->put($page->image_path, (string) $disk->get($input));
        $size = @getimagesize($disk->path($page->image_path));
        $page->forceFill([
            'width' => $size[0] ?? $page->width,
            'height' => $size[1] ?? $page->height,
            'deskew_degrees' => 0,
        ])->save();
    }

    /**
     * The readings of a page's lines by the content of their crop (lines read before only).
     *
     * @return array<string, list<array{id: int, text: string|null, confidence: float|null, error: string|null}>>
     */
    private function readingsByCrop(DocumentPage $page): array
    {
        $disk = Storage::disk('local');
        $readings = [];
        foreach ($page->lines()->whereNotNull('ocr_text')->get() as $line) {
            if (! $line->crop_path || ! $disk->exists($line->crop_path)) {
                continue;
            }
            $readings[md5((string) $disk->get($line->crop_path))][] = [
                'id' => (int) $line->getKey(),
                'text' => $line->ocr_text,
                'confidence' => $line->ocr_confidence,
                'error' => $line->ocr_error,
                'cell' => $this->cellKey($line->polygon, $line->column_index, $line->row),
            ];
        }

        return $readings;
    }

    /** A line's outline and the cell it sits in, to know it again after the page is outlined again. */
    private function cellKey(mixed $polygon, ?int $column, ?int $row): string
    {
        return json_encode([$polygon, $column, $row]);
    }
}
