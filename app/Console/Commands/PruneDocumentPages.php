<?php

namespace App\Console\Commands;

use App\Models\DocumentPage;
use App\Models\ScanBatch;
use App\Models\ScanBatchPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remove aligned pages that were never submitted.
 *
 * A page is uploaded when the user finishes the Align step, before deciding to
 * submit. Abandoned pages are unsubmitted civil registry scans, so they are not
 * kept past `services.line_markers.keep_hours`. Scheduled hourly.
 *
 * A batch kept on the server (Batch File Upload) keeps its queued pages while
 * it is open, so it can be resumed: until it has been left untouched for
 * `crms.scan_batches.keep_days`. Then it goes, with its pages and their queued
 * files, as does a batch that was discarded; a finished one goes once it is
 * that old (its pages are pruned as usual).
 */
class PruneDocumentPages extends Command
{
    /** Where the removed "Test on sample" kept its pages. */
    private const OLD_LAYOUT_TESTS = 'template-tests';

    /** Where a batch keeps each queued page's original scan until its record is saved. */
    private const WORKSPACE = 'documents/workspace';

    protected $signature = 'documents:prune-pages {--hours= : Remove pages untouched for this many hours}';

    protected $description = 'Delete aligned pages (and their crops) that were never submitted.';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('services.line_markers.keep_hours', 24));
        $cutoff = now()->subHours(max(1, $hours));
        $disk = Storage::disk('local');
        $removed = 0;

        $batches = $this->pruneBatches();
        if ($batches > 0) {
            $this->info("Removed {$batches} batch(es) discarded, finished, or left open past their limit.");
        }

        // The pages of a batch still open are kept for it.
        $kept = $this->openBatchPageIds();

        DocumentPage::query()
            ->where('updated_at', '<', $cutoff)
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->chunkById(100, function ($pages) use ($disk, &$removed) {
                foreach ($pages as $page) {
                    // A batch keeps the page's original scan outside its folder
                    // (documents/workspace/<batch>/): it goes with the page.
                    $this->deleteWorkspaceScan($page);
                    $disk->deleteDirectory($page->directory());
                    $page->delete();
                    $removed++;
                }
            });

        $this->info("Removed {$removed} unsubmitted page(s) older than {$hours} hour(s).");

        $orphans = $this->pruneWorkspace($cutoff->getTimestamp());
        if ($orphans > 0) {
            $this->info("Removed {$orphans} queued scan(s) whose page is gone.");
        }

        // The Template Builder's "Test on sample" was removed (2026-11-11). Its
        // folder may still hold register pages from tests nobody finished.
        if ($disk->exists(self::OLD_LAYOUT_TESTS)) {
            $disk->deleteDirectory(self::OLD_LAYOUT_TESTS);
            $this->info('Removed the old layout test folder.');
        }

        return self::SUCCESS;
    }

    /**
     * Batches discarded, and batches left open (or finished) past their limit:
     * each goes with its pages and their queued files (a page saved as a
     * record is gone already: the record took what it keeps).
     */
    private function pruneBatches(): int
    {
        $cutoff = now()->subDays(ScanBatch::keepDays());
        $disk = Storage::disk('local');
        $removed = 0;

        ScanBatch::query()
            ->where(fn ($query) => $query
                ->where('status', ScanBatch::STATUS_ABANDONED)
                ->orWhere('updated_at', '<', $cutoff))
            ->with('pages.documentPage')
            ->chunkById(50, function ($batches) use ($disk, &$removed) {
                foreach ($batches as $batch) {
                    foreach ($batch->pages as $batchPage) {
                        $page = $batchPage->documentPage;
                        if ($page !== null && $batch->status !== ScanBatch::STATUS_DONE) {
                            $this->deleteWorkspaceScan($page);
                            $disk->deleteDirectory($page->directory());
                            $page->delete();
                        }
                    }
                    // The batch's folder of queued files, with anything left in it.
                    if ($batch->batch_key !== '' && ! str_contains($batch->batch_key, '..')) {
                        $disk->deleteDirectory(self::WORKSPACE.'/'.$batch->batch_key);
                    }
                    $batch->delete();
                    $removed++;
                }
            });

        return $removed;
    }

    /** @return list<int> */
    private function openBatchPageIds(): array
    {
        return ScanBatchPage::query()
            ->whereNotNull('document_page_id')
            ->whereHas('batch', fn ($batch) => $batch->where('status', ScanBatch::STATUS_OPEN))
            ->pluck('document_page_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** A batch page's original scan, as DocumentPageController kept it (workspace_scan.json). */
    private function deleteWorkspaceScan(DocumentPage $page): void
    {
        $disk = Storage::disk('local');
        $note = $page->directory().'/workspace_scan.json';
        if (! $disk->exists($note)) {
            return;
        }

        $path = (string) (json_decode((string) $disk->get($note), true)['path'] ?? '');
        if (str_starts_with($path, self::WORKSPACE.'/') && ! str_contains($path, '..')) {
            $disk->delete($path);
        }
    }

    /**
     * Queued scans left behind by pages already gone (a record saved removes
     * its own; a skipped, rejected or abandoned batch page may not have). Each
     * is named "<page id>-<name>": one whose page no longer exists, untouched
     * since the cutoff, is removed, then any batch folder left empty.
     */
    private function pruneWorkspace(int $cutoff): int
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::WORKSPACE)) {
            return 0;
        }

        $removed = 0;
        foreach ($disk->allFiles(self::WORKSPACE) as $file) {
            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }
            $pageId = preg_match('/^(\d+)-/', basename($file), $match) ? (int) $match[1] : null;
            if ($pageId !== null && DocumentPage::whereKey($pageId)->exists()) {
                continue;
            }
            $disk->delete($file);
            $removed++;
        }

        foreach ($disk->directories(self::WORKSPACE) as $folder) {
            if ($disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }

        return $removed;
    }
}
