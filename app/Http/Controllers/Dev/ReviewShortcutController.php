<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\PageLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $saved = $this->saved();
        abort_if($saved === null || ($saved['pages'] ?? []) === [], 404, 'No scan is saved for the shortcut yet.');

        foreach ($saved['pages'] as $page) {
            if (DocumentTemplate::query()->whereKey($page['attributes']['document_template_id'])->doesntExist()) {
                abort(409, 'The layout the saved scan was read with no longer exists. Shift+click the button to scan again.');
            }
        }

        $disk = $this->disk();
        $made = [];
        $copied = [];
        try {
            DB::transaction(function () use ($saved, $request, $disk, &$made, &$copied) {
                foreach ($saved['pages'] as $entry) {
                    $from = 'pages/' . $entry['sourceId'] . '/';
                    $page = new DocumentPage();
                    $page->forceFill([
                        ...$entry['attributes'],
                        'image_path' => '',
                        'created_by' => $request->user()->getKey(),
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
                    foreach ($entry['lines'] as $line) {
                        $page->lines()->create([...$line, 'crop_path' => $moved($line['crop_path'] ?? null)]);
                    }

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
