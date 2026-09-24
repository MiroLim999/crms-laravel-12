<?php

namespace App\Jobs;

use App\Models\DocumentPage;
use App\Models\PageLine;
use App\Services\Lines\LineMarkers;
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

        if ($this->mode !== self::MODE_READ) {
            $this->outline($page, $markers);
        }

        if ($this->mode === self::MODE_DETECT) {
            $page->forceFill(['status' => DocumentPage::STATUS_DETECTED, 'processed_at' => now()])->save();

            return;
        }

        $page->forceFill(['status' => DocumentPage::STATUS_READING])->save();
        $reader->read($page, $page->lines()->get());

        $page->forceFill([
            'status' => DocumentPage::STATUS_READY,
            'processed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        DocumentPage::whereKey($this->pageId)->update([
            'status' => DocumentPage::STATUS_FAILED,
            'error' => mb_substr($exception?->getMessage() ?: 'The page could not be processed.', 0, 2000),
        ]);
    }

    private function outline(DocumentPage $page, LineMarkers $markers): void
    {
        $disk = Storage::disk('local');
        $page->forceFill(['status' => DocumentPage::STATUS_DETECTING, 'error' => null])->save();

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

            $page->lines()->delete();

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
