<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentPage;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrSetting;
use App\Models\PageLine;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use App\Services\Lines\PageLineReader;
use App\Services\Ocr\OcrServiceException;
use App\Services\Ocr\ScanModelChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pages between the Align step and submission.
 *
 * Finishing Align uploads the aligned page and dispatches ProcessDocumentPage,
 * which outlines every handwritten line and reads each outline with TrOCR. The
 * Verify step polls for the stored result, shows each crop TrOCR read, and lets
 * a reviewer redraw an outline, which re-crops and re-reads that line alone.
 *
 * Staff and Super Admin only, and every page is visible to its uploader alone:
 * these are unsubmitted civil registry scans.
 */
class DocumentPageController extends Controller
{
    public function __construct(
        private readonly LineMarkers $markers,
        private readonly PageLineReader $reader,
        private readonly ScanModelChoice $modelChoice,
    ) {}

    /**
     * Align is finished: store the page and start line detection.
     */
    public function store(Request $request): JsonResponse
    {
        $this->hydrateGeometry($request);

        $validated = $request->validate([
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            // The rendered page exactly as it was aligned, so outlines and markers
            // share one pixel grid.
            'page' => ['required', 'file', 'mimes:png', 'max:40960'],
            'model' => ['nullable', 'string', 'max:255'],
            // Detect: straighten the page and fit the template to it, outline
            // every line, and stop before reading so Staff can check first.
            'detect' => ['sometimes', 'boolean'],
            'geometry' => ['required', 'array'],
            'geometry.columns' => ['present', 'array', 'max:60'],
            'geometry.columns.*.name' => ['required', 'string', 'max:500'],
            'geometry.columns.*.box' => ['required', 'array', 'size:4'],
            'geometry.columns.*.box.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.ruled_ys' => ['present', 'array', 'max:400'],
            'geometry.ruled_ys.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.fields' => ['present', 'array', 'max:450'],
            'geometry.fields.*.name' => ['required', 'string', 'max:500'],
            'geometry.fields.*.box' => ['required', 'array', 'size:4'],
            'geometry.fields.*.box.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.fields.*.person_group' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'geometry.fields.*.person_field_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $geometry = $this->checkedGeometry($validated['geometry']);

        $image = $request->file('page');
        if (! $image instanceof UploadedFile || ($size = @getimagesize($image->getRealPath())) === false) {
            throw ValidationException::withMessages(['page' => 'The aligned page could not be read as an image.']);
        }

        $template = DocumentTemplate::findOrFail((int) $validated['document_template_id']);

        $page = DocumentPage::create([
            'document_template_id' => $template->getKey(),
            'created_by' => $request->user()->getKey(),
            'status' => DocumentPage::STATUS_QUEUED,
            'image_path' => '',
            'width' => $size[0],
            'height' => $size[1],
            'geometry' => $geometry,
            'ocr_model_key' => $this->modelChoice->resolve($validated['model'] ?? null),
        ]);

        $path = Storage::disk('local')->putFileAs($page->directory(), $image, 'page.png');
        $page->forceFill(['image_path' => $path])->save();

        ProcessDocumentPage::dispatch(
            $page->getKey(),
            $request->boolean('detect') ? ProcessDocumentPage::MODE_DETECT : ProcessDocumentPage::MODE_FULL,
        );

        return response()->json($this->payload($page->fresh()), 202);
    }

    /**
     * Scan with OCR after Detect: read the crops Detect made, as they are.
     */
    public function read(Request $request, DocumentPage $page): JsonResponse
    {
        $this->authorizePage($request, $page);

        if ($page->status !== DocumentPage::STATUS_DETECTED) {
            return response()->json(['message' => 'Detect this page again before reading it.'], 409);
        }

        $validated = $request->validate(['model' => ['nullable', 'string', 'max:255']]);

        $page->forceFill([
            'status' => DocumentPage::STATUS_READING,
            'ocr_model_key' => $this->modelChoice->resolve($validated['model'] ?? null),
        ])->save();

        ProcessDocumentPage::dispatch($page->getKey(), ProcessDocumentPage::MODE_READ);

        return response()->json($this->payload($page->fresh()), 202);
    }

    /**
     * The page as it is being processed - straightened, after Detect.
     */
    public function image(Request $request, DocumentPage $page): StreamedResponse
    {
        $this->authorizePage($request, $page);
        abort_unless(Storage::disk('local')->exists($page->image_path), 404);

        return Storage::disk('local')->response($page->image_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The page's status, and its lines once they are ready.
     */
    public function show(Request $request, DocumentPage $page): JsonResponse
    {
        $this->authorizePage($request, $page);

        return response()->json($this->payload($page));
    }

    /**
     * The exact masked crop TrOCR read for one line.
     */
    public function crop(Request $request, DocumentPage $page, PageLine $line): StreamedResponse
    {
        $this->authorizePage($request, $page);
        abort_unless((int) $line->document_page_id === (int) $page->getKey(), 404);
        abort_unless(Storage::disk('local')->exists($line->crop_path), 404);

        return Storage::disk('local')->response($line->crop_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A reviewer redrew one outline: re-crop it and read that line again.
     */
    public function updateLine(Request $request, DocumentPage $page, PageLine $line): JsonResponse
    {
        $this->authorizePage($request, $page);
        abort_unless((int) $line->document_page_id === (int) $page->getKey(), 404);

        if ($page->status !== DocumentPage::STATUS_READY) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $validated = $request->validate([
            'polygon' => ['required', 'array', 'min:3', 'max:2000'],
            'polygon.*' => ['required', 'array', 'size:2'],
            'polygon.*.*' => ['required', 'numeric'],
        ]);

        $polygon = array_map(fn (array $point) => [
            round(min(max((float) $point[0], 0), $page->width), 1),
            round(min(max((float) $point[1], 0), $page->height), 1),
        ], $validated['polygon']);

        $disk = Storage::disk('local');
        $version = $line->crop_version + 1;
        $cropPath = sprintf('%s/crops/%03d-edit%d.png', $page->directory(), $line->position + 1, $version);

        try {
            $placed = $this->markers->crop(
                $disk->path($page->image_path),
                $polygon,
                $disk->path($cropPath),
                $disk->path($page->directory().'/lines.json'),
            );
        } catch (LineMarkersException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $previousCrop = $line->crop_path;
        $line->fill([
            'polygon' => $polygon,
            'bbox' => $placed['bbox'],
            'crop_path' => $cropPath,
            'crop_version' => $version,
            'adjusted_at' => now(),
        ]);

        // A field line keeps its field identity. A ledger line is placed
        // again from where the reviewer put it.
        if (! $line->belongsToField()) {
            $columns = $page->geometry['columns'] ?? [];
            $columnIndex = $placed['column_index'];
            if ($columnIndex !== null && isset($columns[$columnIndex])) {
                $line->column_index = $columnIndex;
                $line->column_name = (string) $columns[$columnIndex]['name'];
            }
            $line->row = $placed['row'];
            $line->flags = in_array(PageLine::FLAG_NO_ROW, $placed['flags'], true) ? [PageLine::FLAG_NO_ROW] : [];
        }
        $line->save();
        $this->refreshSharedCells($page);

        if ($previousCrop !== $cropPath) {
            $disk->delete($previousCrop);
        }

        $status = 200;
        $message = null;
        try {
            $this->reader->read($page, collect([$line]));
        } catch (OcrServiceException $e) {
            // The new outline and crop are kept; only the reading is missing.
            $line->forceFill(['ocr_text' => '', 'ocr_confidence' => 0, 'ocr_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $status = 503;
            $message = $e->getMessage();
        }

        return response()->json([
            'message' => $message,
            'line' => $line->fresh()->toClient(),
            // Moving one line can create or resolve a shared cell elsewhere.
            'flags' => $page->lines()->get()->mapWithKeys(fn (PageLine $l) => [$l->getKey() => $l->flags ?? []]),
        ], $status);
    }

    // ------------------------------------------------------------------ internals

    private function authorizePage(Request $request, DocumentPage $page): void
    {
        // 404, not 403: another user's unsubmitted scan does not exist for you.
        abort_unless((int) $page->created_by === (int) $request->user()->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DocumentPage $page): array
    {
        return [
            'id' => $page->getKey(),
            'status' => $page->status,
            'error' => $page->status === DocumentPage::STATUS_FAILED ? $page->error : null,
            'width' => $page->width,
            'height' => $page->height,
            'deskew' => $page->deskew_degrees ?? 0.0,
            // The markers as the page was outlined with them (fitted by Detect).
            'geometry' => $page->geometry,
            'statusUrl' => route('documents.pages.show', $page),
            'readUrl' => route('documents.pages.read', $page),
            'imageUrl' => route('documents.pages.image', ['page' => $page, 'v' => $page->updated_at?->timestamp]),
            'model' => $page->ocr_model_label,
            'modelKey' => $page->ocr_model_key,
            'threshold' => OcrSetting::threshold(),
            'lines' => $page->hasLines() ? $page->lines()->get()->map->toClient()->values() : [],
        ];
    }

    /**
     * Recompute shared_cell after a line moved.
     */
    private function refreshSharedCells(DocumentPage $page): void
    {
        $lines = $page->lines()->get()->reject->belongsToField();
        $counts = $lines
            ->filter(fn (PageLine $l) => $l->row !== null)
            ->countBy(fn (PageLine $l) => $l->column_index.':'.$l->row);

        foreach ($lines as $line) {
            $flags = array_values(array_diff($line->flags ?? [], [PageLine::FLAG_SHARED_CELL]));
            if ($line->row !== null && ($counts[$line->column_index.':'.$line->row] ?? 0) > 1) {
                $flags[] = PageLine::FLAG_SHARED_CELL;
            }
            if ($flags !== ($line->flags ?? [])) {
                $line->forceFill(['flags' => $flags])->save();
            }
        }
    }

    private function hydrateGeometry(Request $request): void
    {
        if (! $request->filled('geometry_json')) {
            return;
        }

        try {
            $geometry = json_decode((string) $request->input('geometry_json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['geometry' => 'The aligned markers could not be read. Try again.']);
        }

        if (is_array($geometry)) {
            $request->merge(['geometry' => [
                'columns' => $geometry['columns'] ?? [],
                'ruled_ys' => $geometry['ruled_ys'] ?? [],
                'fields' => $geometry['fields'] ?? [],
            ]]);
        }
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<string, mixed>
     */
    private function checkedGeometry(array $geometry): array
    {
        $columns = array_values($geometry['columns'] ?? []);
        $ruled = array_values(array_map('floatval', $geometry['ruled_ys'] ?? []));
        $fields = array_values($geometry['fields'] ?? []);

        $errors = [];
        if ($columns === [] && $fields === []) {
            $errors['geometry'] = 'There are no markers to read. Reset the layout and try again.';
        }
        if ($columns !== [] && count($ruled) < 2) {
            $errors['geometry.ruled_ys'] = 'This layout has columns but no ruled row lines.';
        }
        for ($i = 1; $i < count($ruled); $i++) {
            if ($ruled[$i] <= $ruled[$i - 1]) {
                $errors['geometry.ruled_ys'] = 'Ruled row lines must run from top to bottom.';
                break;
            }
        }
        foreach (['columns' => $columns, 'fields' => $fields] as $key => $items) {
            foreach ($items as $index => $item) {
                [$x, $y, $w, $h] = array_map('floatval', $item['box']);
                if ($w <= 0 || $h <= 0 || $x + $w > 1.00001 || $y + $h > 1.00001) {
                    $errors["geometry.{$key}.{$index}.box"] = 'A marker extends beyond the page.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'columns' => array_map(fn (array $c) => [
                'name' => (string) $c['name'],
                'box' => array_map('floatval', $c['box']),
            ], $columns),
            'ruled_ys' => $ruled,
            'fields' => array_map(fn (array $f) => [
                'name' => (string) $f['name'],
                'box' => array_map('floatval', $f['box']),
                'person_group' => isset($f['person_group']) ? (int) $f['person_group'] : null,
                'person_field_order' => isset($f['person_field_order']) ? (int) $f['person_field_order'] : null,
            ], $fields),
        ];
    }
}
