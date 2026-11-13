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
                    $disk->deleteDirectory($page->directory());
                    $page->delete();
                    $removed++;
                }
            });

        $this->info("Removed {$removed} unsubmitted page(s) older than {$hours} hour(s).");

        // The Template Builder's "Test on sample" was removed (2026-11-11). Its
        // folder may still hold register pages from tests nobody finished.
        if ($disk->exists(self::OLD_LAYOUT_TESTS)) {
            $disk->deleteDirectory(self::OLD_LAYOUT_TESTS);
            $this->info('Removed the old layout test folder.');
        }

        return self::SUCCESS;
    }
}
