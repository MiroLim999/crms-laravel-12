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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /** Batch mode's files: every PDF here, in name order (in the codebase). */
    private const FILES_FOLDER = 'pdf-files';

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

            $pages[] = $this->snapshotPage($page, (int) $entry['number']);
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
     * Batch mode: each PDF in the codebase's pdf-files folder, with what was
     * saved of its reading (batchStore), keyed by its fingerprint (SHA-256).
     */
    public function batchStatus(): JsonResponse
    {
        return response()->json([
            'folder' => self::FILES_FOLDER,
            'files' => array_map(function (array $file) {
                $saved = $this->savedBatch($file['hash']);

                return [
                    'name' => $file['name'],
                    'hash' => $file['hash'],
                    'pageTotal' => $saved['pageTotal'] ?? null,
                    'pages' => array_map(fn (array $page) => [
                        'number' => (int) $page['number'],
                        'templateId' => $page['attributes']['document_template_id'] ?? null,
                    ], $saved['pages'] ?? []),
                    'complete' => $saved !== null && count($saved['pages'] ?? []) === (int) ($saved['pageTotal'] ?? -1),
                ];
            }, $this->batchFiles()),
        ]);
    }

    /** One PDF of the pdf-files folder (by name, as batchStatus lists it). */
    public function batchFile(Request $request): BinaryFileResponse
    {
        $name = (string) $request->query('name', '');
        $file = collect($this->batchFiles())->firstWhere('name', $name);
        abort_if($file === null, 404);

        return response()->file($file['path'], ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    /**
     * Shift+click in a batch: the pages it has read (of files in pdf-files) are
     * saved for the shortcut, each file's under its fingerprint, replacing what
     * was saved of that file before. Copies of one file share it.
     */
    public function batchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:400'],
            'pages.*.id' => ['required', 'integer'],
            'pages.*.hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'pages.*.number' => ['required', 'integer', 'min:1'],
            'pages.*.total' => ['required', 'integer', 'min:1'],
        ]);

        $known = array_column($this->batchFiles(), 'hash');
        $byHash = [];
        foreach ($validated['pages'] as $entry) {
            // Only files of the folder; a page of a file met twice (a copy) once.
            if (! in_array($entry['hash'], $known, true) || isset($byHash[$entry['hash']]['pages'][(int) $entry['number']])) {
                continue;
            }
            $page = DocumentPage::query()
                ->whereKey($entry['id'])
                ->where('created_by', $request->user()->getKey())
                ->where('status', DocumentPage::STATUS_READY)
                ->first();
            if ($page === null) {
                continue;
            }
            $byHash[$entry['hash']]['pageTotal'] = (int) $entry['total'];
            $byHash[$entry['hash']]['pages'][(int) $entry['number']] = $this->snapshotPage($page, (int) $entry['number']);
        }
        abort_if($byHash === [], 422, 'No read page of a file in ' . self::FILES_FOLDER . ' is in this batch. Scan them first (Scan all), then Shift+click in Verify.');

        $disk = $this->disk();
        $saved = [];
        foreach ($byHash as $hash => $file) {
            ksort($file['pages']);
            $dir = $this->batchDir($hash);
            $disk->deleteDirectory($dir);
            foreach ($file['pages'] as $number => $page) {
                $from = 'pages/' . $page['sourceId'];
                foreach ($disk->allFiles($from) as $path) {
                    $disk->copy($path, $dir . '/pages/' . $number . substr($path, strlen($from)));
                }
            }
            $disk->put($dir . '/saved.json', json_encode([
                'savedAt' => now()->toIso8601String(),
                'pageTotal' => $file['pageTotal'],
                'pages' => array_values($file['pages']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $saved[] = ['hash' => $hash, 'pages' => count($file['pages']), 'pageTotal' => $file['pageTotal']];
        }

        return response()->json(['files' => $saved]);
    }

    /**
     * Batch mode: a batch of every PDF in pdf-files, each from what was saved
     * of its reading, kept on the server exactly as a real batch is once Scan
     * all has read every page (pages "ready", each with its original in the
     * batch's workspace folder), and each record's draft with every field that
     * has a value ticked Verified. The workspace then resumes it as it resumes
     * any batch, so nothing in the batch's own code is skipped or changed.
     *
     * `scans[<hash>][N]` is page N of that file on its own (cut in the browser,
     * as Save & Next cuts a queued page), kept as the page's original. `pairs`:
     * the layout has a page 2, so pages 1–2, 3–4 are one record each.
     */
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'page_two_template_id' => ['nullable', 'integer', 'exists:document_templates,id'],
            'pairs' => ['required', 'boolean'],
            'scans' => ['required', 'array'],
        ]);

        $files = $this->batchFiles();
        abort_if($files === [], 422, 'There is no PDF in ' . self::FILES_FOLDER . '.');
        $plan = [];
        foreach ($files as $file) {
            $saved = $this->savedBatch($file['hash']);
            if ($saved === null || count($saved['pages'] ?? []) !== (int) ($saved['pageTotal'] ?? -1)) {
                abort(409, "{$file['name']} is not read yet. Scan it once in a batch, then Shift+click in Verify.");
            }
            foreach ($saved['pages'] as $page) {
                if (DocumentTemplate::query()->whereKey($page['attributes']['document_template_id'])->doesntExist()) {
                    abort(409, "The layout {$file['name']} was read with no longer exists. Scan it again, then Shift+click in Verify.");
                }
                if (! $request->file("scans.{$file['hash']}.{$page['number']}") instanceof UploadedFile) {
                    abort(422, "Page {$page['number']} of {$file['name']} was not sent.");
                }
            }
            $plan[] = [...$file, 'saved' => $saved];
        }

        $disk = $this->disk();
        $userId = $request->user()->getKey();
        $stamp = now()->format('YmdHis');
        $batchKey = 'dev_' . $stamp . '_' . Str::lower(Str::random(6));
        $pairs = (bool) $validated['pairs'];
        $copied = [];

        try {
            $batch = DB::transaction(function () use ($validated, $plan, $request, $disk, $userId, $stamp, $batchKey, $pairs, &$copied) {
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
                foreach ($plan as $fileIndex => $file) {
                    $pageTotal = (int) $file['saved']['pageTotal'];
                    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                    $ticked = [];

                    foreach ($file['saved']['pages'] as $entry) {
                        $number = (int) $entry['number'];
                        ['page' => $page, 'lines' => $lines] = $this->copyPage($entry, $userId, $copied, $this->batchDir($file['hash']) . '/pages/' . $number);
                        // As the workspace names a record: its file's group and its first page.
                        $lead = $pairs && $pageTotal > 1 ? ($number % 2 === 1 ? $number : $number - 1) : $number;
                        $recordKey = "dev{$stamp}-" . ($fileIndex + 1) . "#{$lead}";

                        // The page's original, where Save & Next would have put it.
                        $scanPath = 'documents/workspace/' . $batchKey . '/' . $page->getKey() . "-{$safeName}-p{$number}.pdf";
                        $disk->putFileAs(dirname($scanPath), $request->file("scans.{$file['hash']}.{$number}"), basename($scanPath));
                        $copied[] = $scanPath;
                        $disk->put($page->directory() . '/workspace_scan.json', json_encode([
                            'path' => $scanPath,
                            'name' => $file['name'],
                            'mime' => 'application/pdf',
                            'batch_key' => $batchKey,
                        ], JSON_UNESCAPED_SLASHES));

                        ScanBatchPage::create([
                            'scan_batch_id' => $batch->getKey(),
                            'client_key' => (string) Str::uuid(),
                            'position' => $position++,
                            'source_name' => $file['name'],
                            'source_hash' => $file['hash'],
                            'page_no' => $number,
                            'page_total' => $pageTotal,
                            'record_key' => $recordKey,
                            'status' => 'ready',
                            'document_page_id' => $page->getKey(),
                            'workspace_scan_path' => $scanPath,
                            'alignment' => null,
                            // The same files every time (and copies of one file): saved
                            // again without asking, as "Add anyway" and "Continue anyway" do.
                            'flags' => ['existingAccepted' => true, 'addedAnyway' => true, 'scanKind' => 'full'],
                            'error' => null,
                        ]);

                        foreach ($lines as $line) {
                            $value = trim((string) $line->ocr_text);
                            if ($value !== '') {
                                $ticked[$recordKey][] = ['line' => $line->getKey(), 'value' => $value, 'verified' => true];
                            }
                        }
                    }

                    // Step 3 as a person does it: every field with a value ticked Verified.
                    foreach ($ticked as $recordKey => $lines) {
                        ScanBatchDraft::create([
                            'scan_batch_id' => $batch->getKey(),
                            'record_key' => $recordKey,
                            'draft' => ['verify' => $lines, 'review' => []],
                        ]);
                    }
                }

                return $batch;
            });
        } catch (\Throwable $error) {
            foreach ($copied as $file) {
                $disk->delete($file);
            }
            throw $error;
        }

        return response()->json(['batchId' => $batch->getKey(), 'files' => count($plan)]);
    }

    /**
     * The PDFs of the pdf-files folder, by name (natural order), with their fingerprints.
     *
     * @return list<array{name: string, path: string, hash: string}>
     */
    private function batchFiles(): array
    {
        $paths = glob(base_path(self::FILES_FOLDER) . '/*.pdf') ?: [];
        natcasesort($paths);

        return array_values(array_map(fn (string $path) => [
            'name' => basename($path),
            'path' => $path,
            'hash' => hash_file('sha256', $path),
        ], $paths));
    }

    private function batchDir(string $hash): string
    {
        return self::DIR . '/batch/' . $hash;
    }

    /** @return array{savedAt?: string, pageTotal?: int, pages?: list<array<string, mixed>>}|null */
    private function savedBatch(string $hash): ?array
    {
        $json = $this->disk()->get($this->batchDir($hash) . '/saved.json');
        $saved = $json ? json_decode($json, true) : null;

        return is_array($saved) ? $saved : null;
    }

    /**
     * A read page as the shortcut keeps it: its row (what a copy needs) and its lines.
     *
     * @return array<string, mixed>
     */
    private function snapshotPage(DocumentPage $page, int $number): array
    {
        return [
            'number' => $number,
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

    /**
     * One saved page copied for the user as a finished scan leaves it: its row,
     * its image and crops, and its lines with their readings.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $copied  files written (removed again if anything fails)
     * @param  string|null  $filesDir  where the saved page's files are (the single
     *                                  shortcut's page folder when null)
     * @return array{page: DocumentPage, lines: list<PageLine>}
     */
    private function copyPage(array $entry, int $userId, array &$copied, ?string $filesDir = null): array
    {
        $filesDir ??= self::DIR . '/pages/' . $entry['number'];
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

        foreach ($disk->allFiles($filesDir) as $file) {
            $target = $to . substr($file, strlen($filesDir . '/'));
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
