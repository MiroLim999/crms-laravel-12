<?php

namespace App\Jobs;

use App\Models\DocumentPage;
use App\Models\PageLine;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Test layout in the Template Builder: outline every line on the sample page
 * with the layout being built, as Detect would for Staff.
 *
 * Line detection takes about half a minute, and `php artisan serve` on Windows
 * answers one request at a time, so it runs here instead of in the request.
 * Everything lives in template-tests/{id}/: the sample (page.png), `started`
 * once the worker picks the test up, then result.json or error.json. The
 * builder polls for them, and the folder is deleted once the result is shown.
 * documents:prune-pages clears folders nobody came back for.
 */
class TestTemplateLayout implements ShouldQueue
{
    use Queueable;

    /** Every test's folder is inside this one, on the local disk. */
    public const ROOT = 'template-tests';

    /** A failed test is run again from the builder, not retried behind its back. */
    public int $tries = 1;

    public int $timeout = 900;

    /** @param  array<string, mixed>  $geometry */
    public function __construct(
        public readonly string $testId,
        public readonly array $geometry,
    ) {}

    public static function directory(string $testId): string
    {
        return self::ROOT.'/'.$testId;
    }

    public function handle(LineMarkers $markers): void
    {
        $disk = Storage::disk('local');
        $directory = self::directory($this->testId);
        $page = $directory.'/page.png';
        if (! $disk->exists($page)) {
            return;  // Pruned while waiting in the queue.
        }
        $disk->put($directory.'/started', '');

        try {
            $result = $markers->detect($disk->path($page), $this->geometry, $disk->path($directory));
        } catch (LineMarkersException $e) {
            $this->finish('error.json', ['message' => $e->getMessage()]);

            return;
        }

        // A straightened page no longer matches the builder's own picture of it.
        $straightened = abs((float) ($result['deskew'] ?? 0)) >= 0.05;

        $this->finish('result.json', [
            'status' => 'done',
            'size' => $result['size'] ?? null,
            'deskew' => (float) ($result['deskew'] ?? 0),
            'image' => $straightened ? 'data:image/png;base64,'.base64_encode($disk->get($page)) : null,
            'fit' => $result['fit'] ?? null,
            'geometry' => $result['geometry'] ?? $this->geometry,
            'notes' => DocumentPage::cleanNotes($result['notes'] ?? []),
            // Written lines outside every marker, which nothing would read.
            'ignored' => is_array($result['ignored'] ?? null) ? count($result['ignored']) : (int) ($result['ignored'] ?? 0),
            'lines' => array_map(fn (array $line) => [
                'source' => $line['source'] ?? null,
                'column' => (string) ($line['column'] ?? ''),
                'column_index' => $line['column_index'] ?? null,
                'row' => $line['row'] ?? null,
                'polygon' => $line['polygon'] ?? [],
                'flags' => array_values(array_intersect(
                    $line['flags'] ?? [],
                    [PageLine::FLAG_NO_ROW, PageLine::FLAG_SHARED_CELL],
                )),
            ], $result['lines'] ?? []),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if (Storage::disk('local')->exists(self::directory($this->testId))) {
            $this->finish('error.json', ['message' => 'The layout could not be tested. Try again.']);
        }
    }

    /**
     * Write the outcome the builder polls for. It is written under another
     * name and then renamed, so a poll never reads half a file.
     *
     * @param  array<string, mixed>  $payload
     */
    private function finish(string $file, array $payload): void
    {
        $disk = Storage::disk('local');
        $path = self::directory($this->testId).'/'.$file;
        $disk->put($path.'.part', json_encode($payload, JSON_THROW_ON_ERROR));
        $disk->move($path.'.part', $path);
    }
}
