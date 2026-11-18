<?php

namespace App\Console\Commands;

use App\Models\DocumentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remove aligned pages that were never submitted.
 *
 * A page is uploaded when the user finishes the Align step, before deciding to
 * submit. Abandoned pages are unsubmitted civil registry scans, so they are not
 * kept past `services.line_markers.keep_hours`. Scheduled hourly.
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

        DocumentPage::query()
            ->where('updated_at', '<', $cutoff)
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
