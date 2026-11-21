<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\PageLine;
use App\Models\ScanBatch;
use App\Models\ScanBatchDraft;
use App\Models\ScanBatchPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DEV ONLY: Review & Export shortcut (temporary). See config/dev_review_shortcut.php
 * for what it is and how to remove it.
 *
 * One real scan is saved once: each page as it was read (its row in
 * document_pages, its lines with their readings, its image and crops) and the
 * file it came from, in storage/app/private/dev-review-shortcut. Each press of
 * the button then makes fresh copies of those pages for the signed-in user,
 * exactly as a finished scan leaves them, so Verify and Review & Export work
 * on them as usual (Save record saves and removes the copies, as it would the
 * pages of a real scan). Nothing else in the app reads or changes this folder.
 */
class ReviewShortcutController extends Controller
{
    private const DIR = 'dev-review-shortcut';

    public function status(): JsonResponse
    {
        $saved = $this->saved();

        return response()->json([
            'hasFile' => $this->disk()->exists(self::DIR . '/scan.pdf') && $saved !== null,
            'hasPages' => $saved !== null && ($saved['pages'] ?? []) !== [],
            'fileName' => $saved['fileName'] ?? null,
            'savedAt' => $saved['savedAt'] ?? null,
            'pages' => array_map(fn (array $page) => [
                'number' => $page['number'],
                'templateId' => $page['attributes']['document_template_id'] ?? null,
            ], $saved['pages'] ?? []),
        ]);
    }

    public function file(): StreamedResponse
    {
        abort_unless($this->disk()->exists(self::DIR . '/scan.pdf'), 404);

        return $this->disk()->response(self::DIR . '/scan.pdf', $this->saved()['fileName'] ?? 'scan.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Save the pages a real scan just read (page numbers in the file) and the file. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scan' => ['required', 'file', 'mimes:pdf', 'max:40960'],
            'pages' => ['required', 'array', 'min:1', 'max:2'],
            'pages.*.id' => ['required', 'integer'],
            'pages.*.number' => ['required', 'integer', 'min:1'],
        ]);

        $disk = $this->disk();
        $pages = [];
        foreach ($validated['pages'] as $entry) {
            $page = DocumentPage::query()
                ->whereKey($entry['id'])
                ->where('created_by', $request->user()->getKey())
                ->where('status', DocumentPage::STATUS_READY)
                ->firstOrFail();

            $pages[] = [
                'number' => (int) $entry['number'],
                'sourceId' => $page->getKey(),
                'attributes' => [
                    ...$page->only([
                        'document_template_id', 'image_path', 'width', 'height', 'deskew_degrees',
                        'geometry', 'notes', 'ocr_model_key', 'ocr_model_label',
                    ]),
                    'source_name' => $page->getAttribute('source_name'),
                ],
                'lines' => $page->lines()->get()->map(fn (PageLine $line) => $line->only([
                    'position', 'source', 'column_index', 'column_name', 'row',
                    'person_group', 'person_field_order', 'polygon', 'baseline', 'bbox',
                    'crop_path', 'crop_version', 'flags', 'ocr_text', 'ocr_confidence', 'ocr_error',
                ]))->values()->all(),
            ];
        }

        // Replace what was saved before.
        $disk->deleteDirectory(self::DIR);
        foreach ($pages as $page) {
            $from = 'pages/' . $page['sourceId'];
            foreach ($disk->allFiles($from) as $file) {
                $disk->copy($file, self::DIR . '/pages/' . $page['number'] . substr($file, strlen($from)));
            }
        }
        $disk->putFileAs(self::DIR, $validated['scan'], 'scan.pdf');
        $disk->put(self::DIR . '/saved.json', json_encode([
            'fileName' => $validated['scan']->getClientOriginalName(),
            'savedAt' => now()->toIso8601String(),
            'pages' => $pages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json(['saved' => count($pages)]);
    }

    /** Fresh copies of the saved pages for this user, as a finished scan leaves them. */
    public function pages(Request $request): JsonResponse
    {
        $saved = $this->savedOrFail();

        $disk = $this->disk();
        $made = [];
        $copied = [];
        try {
            DB::transaction(function () use ($saved, $request, &$made, &$copied) {
                foreach ($saved['pages'] as $entry) {
                    $page = $this->copyPage($entry, $request->user()->getKey(), $copied)['page'];
                    $made[] = [
                        'number' => $entry['number'],
                        'id' => $page->getKey(),
                        'statusUrl' => route('documents.pages.show', $page),
                    ];
                }
            });
        } catch (\Throwable $error) {
            foreach ($copied as $file) {
                $disk->delete($file);
            }
            throw $error;
        }

        return response()->json(['fileName' => $saved['fileName'] ?? 'scan.pdf', 'pages' => $made]);
    }

    /**
     * Batch mode: a batch of `count` documents, each a fresh copy of the saved
     * scan, kept on the server exactly as a real batch is once Scan all has
     * read every page (pages "ready", each with its original in the batch's
     * workspace folder), and each record's draft with every field that has a
     * value ticked Verified. The workspace then resumes it as it resumes any
     * batch, so nothing in the batch's own code is skipped or changed.
     *
     * `scans[N]` is page N of the saved file on its own (cut in the browser,
     * as Save & Next cuts a queued page), kept as each copy's original.
     */
    public function batch(Request $request): JsonResponse
    {
        $saved = $this->savedOrFail();
        $numbers = array_map(fn (array $page) => (int) $page['number'], $saved['pages']);

        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'page_two_template_id' => ['nullable', 'integer', 'exists:document_templates,id'],
            'count' => ['required', 'integer', 'min:1', 'max:20'],
            'scans' => ['required', 'array'],
            'scans.*' => ['file', 'mimes:pdf', 'max:20480'],
        ]);
        foreach ($numbers as $number) {
            if (! $request->file("scans.{$number}") instanceof UploadedFile) {
                abort(422, "Page {$number} of the saved file was not sent.");
            }
        }

        $disk = $this->disk();
        $userId = $request->user()->getKey();
        $stamp = now()->format('YmdHis');
        $batchKey = 'dev_' . $stamp . '_' . Str::lower(Str::random(6));
        $baseName = pathinfo((string) ($saved['fileName'] ?? 'scan.pdf'), PATHINFO_FILENAME);
        $pageTotal = max($numbers);
        $lead = min($numbers);
        $copied = [];

        try {
            $batch = DB::transaction(function () use ($validated, $saved, $request, $disk, $userId, $stamp, $batchKey, $baseName, $pageTotal, $lead, &$copied) {
                $batch = ScanBatch::create([
                    'user_id' => $userId,
                    'doc_type' => $validated['doc_type'],
                    'document_template_id' => $validated['document_template_id'],
                    'page_two_template_id' => $validated['page_two_template_id'] ?? null,
                    'batch_key' => $batchKey,
                    'status' => ScanBatch::STATUS_OPEN,
                    'version' => 0,
                    'tab' => null,
                    'seen_at' => null,
                ]);

                $position = 0;
                for ($copy = 1; $copy <= (int) $validated['count']; $copy++) {
                    $sourceName = "{$baseName} ({$copy}).pdf";
                    // As the workspace names a record: its file's group and its first page.
                    $recordKey = "dev{$stamp}-{$copy}#{$lead}";
                    $ticked = [];

                    foreach ($saved['pages'] as $entry) {
                        $number = (int) $entry['number'];
                        ['page' => $page, 'lines' => $lines] = $this->copyPage($entry, $userId, $copied);

                        // The page's original, where Save & Next would have put it.
                        $scanPath = 'documents/workspace/' . $batchKey . '/' . $page->getKey() . '-'
                            . preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName) . "-p{$number}.pdf";
                        $disk->putFileAs(dirname($scanPath), $request->file("scans.{$number}"), basename($scanPath));
                        $copied[] = $scanPath;
                        $disk->put($page->directory() . '/workspace_scan.json', json_encode([
                            'path' => $scanPath,
                            'name' => $sourceName,
                            'mime' => 'application/pdf',
                            'batch_key' => $batchKey,
                        ], JSON_UNESCAPED_SLASHES));

                        ScanBatchPage::create([
                            'scan_batch_id' => $batch->getKey(),
                            'client_key' => (string) Str::uuid(),
                            'position' => $position++,
                            'source_name' => $sourceName,
                            'source_hash' => null,
                            'page_no' => $number,
                            'page_total' => $pageTotal,
                            'record_key' => $recordKey,
                            'status' => 'ready',
                            'document_page_id' => $page->getKey(),
                            'workspace_scan_path' => $scanPath,
                            'alignment' => null,
                            // Copies of one scan match each other: not asked about on each.
                            'flags' => ['existingAccepted' => true, 'scanKind' => 'full'],
                            'error' => null,
                        ]);

                        foreach ($lines as $line) {
                            $value = trim((string) $line->ocr_text);
                            if ($value !== '') {
                                $ticked[] = ['line' => $line->getKey(), 'value' => $value, 'verified' => true];
                            }
                        }
                    }

                    // Step 3 as a person does it: every field with a value ticked Verified.
                    ScanBatchDraft::create([
                        'scan_batch_id' => $batch->getKey(),
                        'record_key' => $recordKey,
                        'draft' => ['verify' => $ticked, 'review' => []],
                    ]);
                }

                return $batch;
            });
        } catch (\Throwable $error) {
            foreach ($copied as $file) {
                $disk->delete($file);
            }
            throw $error;
        }

        return response()->json(['batchId' => $batch->getKey(), 'records' => (int) $validated['count']]);
    }

    /**
     * One saved page copied for the user as a finished scan leaves it: its row,
     * its image and crops, and its lines with their readings.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $copied  files written (removed again if anything fails)
     * @return array{page: DocumentPage, lines: list<PageLine>}
     */
    private function copyPage(array $entry, int $userId, array &$copied): array
    {
        $disk = $this->disk();
        $from = 'pages/' . $entry['sourceId'] . '/';
        $page = new DocumentPage();
        $page->forceFill([
            ...$entry['attributes'],
            'image_path' => '',
            'created_by' => $userId,
            'status' => DocumentPage::STATUS_READY,
            'error' => null,
            'processed_at' => now(),
        ])->save();

        $to = $page->directory() . '/';
        $moved = fn (?string $path) => $path !== null && str_starts_with($path, $from)
            ? $to . substr($path, strlen($from))
            : $path;

        foreach ($disk->allFiles(self::DIR . '/pages/' . $entry['number']) as $file) {
            $target = $to . substr($file, strlen(self::DIR . '/pages/' . $entry['number'] . '/'));
            $disk->copy($file, $target);
            $copied[] = $target;
        }

        $page->update(['image_path' => $moved($entry['attributes']['image_path'])]);
        $lines = [];
        foreach ($entry['lines'] as $line) {
            $lines[] = $page->lines()->create([...$line, 'crop_path' => $moved($line['crop_path'] ?? null)]);
        }

        return ['page' => $page, 'lines' => $lines];
    }

    /** @return array{fileName?: string, savedAt?: string, pages: list<array<string, mixed>>} */
    private function savedOrFail(): array
    {
        $saved = $this->saved();
        abort_if($saved === null || ($saved['pages'] ?? []) === [], 404, 'No scan is saved for the shortcut yet.');

        foreach ($saved['pages'] as $page) {
            if (DocumentTemplate::query()->whereKey($page['attributes']['document_template_id'])->doesntExist()) {
                abort(409, 'The layout the saved scan was read with no longer exists. Shift+click the button (in Single mode) to scan again.');
            }
        }

        return $saved;
    }

    /** @return array{fileName?: string, savedAt?: string, pages?: list<array<string, mixed>>}|null */
    private function saved(): ?array
    {
        $json = $this->disk()->get(self::DIR . '/saved.json');
        $saved = $json ? json_decode($json, true) : null;

        return is_array($saved) ? $saved : null;
    }

    private function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return Storage::disk('local');
    }
}
