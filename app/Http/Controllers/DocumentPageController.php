<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentPage;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrSetting;
use App\Models\PageLine;
use App\Services\Lines\GeometryInput;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use App\Services\Lines\PageLineReader;
use App\Services\Ocr\OcrServiceException;
use App\Services\Ocr\ScanModelChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
        GeometryInput::hydrate($request);

        $validated = $request->validate([
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            // The rendered page exactly as it was aligned, so outlines and markers
            // share one pixel grid.
            'page' => ['required', 'file', 'mimes:png', 'max:40960'],
            'model' => ['nullable', 'string', 'max:255'],
            // Detect: straighten the page and fit the template to it, outline
            // every line, and stop before reading so Staff can check first.
            'detect' => ['sometimes', 'boolean'],
            ...GeometryInput::rules(),
        ]);

        $geometry = GeometryInput::checked($validated['geometry']);

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
     * Snap to table: fit the markers Staff are aligning onto this page's
     * printed table, and return the printed rules for magnetic edges.
     *
     * Nothing is stored: the page image is read from the upload itself and
     * the result goes straight back to the Align step. Well under a second,
     * because no handwriting is detected.
     */
    public function snap(Request $request): JsonResponse
    {
        GeometryInput::hydrate($request);

        $validated = $request->validate([
            'page' => ['required', 'file', 'mimes:png', 'max:40960'],
            ...GeometryInput::rules(),
        ]);

        $geometry = GeometryInput::checked($validated['geometry']);
        $image = $request->file('page');
        if (! $image instanceof UploadedFile || @getimagesize($image->getRealPath()) === false) {
            throw ValidationException::withMessages(['page' => 'The page could not be read as an image.']);
        }

        try {
            $result = $this->markers->snap($image->getRealPath(), $geometry);
        } catch (LineMarkersException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'fitted' => (bool) ($result['fit']['fitted'] ?? false),
            'reason' => $result['fit']['reason'] ?? null,
            'geometry' => $result['geometry'] ?? $geometry,
            'lines' => $result['lines'] ?? ['vertical' => [], 'horizontal' => []],
        ]);
    }

    /**
     * Markers moved after Detect: outline this page again from where Staff
     * have them now, then read it.
     *
     * The page itself is unchanged - already uploaded, and already
     * straightened by Detect - so nothing is sent but the markers, and the
     * lines Detect found are reused instead of detecting the page a second
     * time (see LineMarkers::cachePath). That is the slow part of a scan: a
     * register page takes about 25 seconds to detect and under five to
     * outline again.
     *
     * Every line is worked out afresh from the new markers, so outlines a
     * reviewer adjusted do not survive; the Align step warns before this.
     */
    public function reoutline(Request $request, DocumentPage $page): JsonResponse
    {
        $this->authorizePage($request, $page);
        GeometryInput::hydrate($request);

        if (! in_array($page->status, [DocumentPage::STATUS_DETECTED, DocumentPage::STATUS_READY], true)) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            ...GeometryInput::rules(),
        ]);
        $geometry = GeometryInput::checked($validated['geometry']);

        if (! Storage::disk('local')->exists($page->image_path)) {
            return response()->json(['message' => 'This page is no longer on the server. Scan it again.'], 409);
        }

        $page->forceFill([
            'status' => DocumentPage::STATUS_QUEUED,
            'error' => null,
            'geometry' => $geometry,
            // Detect's notes describe the grid it fitted, which is gone now.
            'notes' => [],
            'ocr_model_key' => $this->modelChoice->resolve($validated['model'] ?? null),
        ])->save();

        ProcessDocumentPage::dispatch($page->getKey(), ProcessDocumentPage::MODE_FULL);

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
     * Cancel Detect or Scan with OCR for this page (the × on the progress window).
     *
     * A page waiting for the worker, or being outlined, is marked cancelled
     * and the worker discards it at its next step (it cannot stop Kraken in
     * the middle of a page). Cancelling the read of a Detect result keeps that
     * result: the page goes back to "detected", ready to be read again.
     */
    public function cancel(Request $request, DocumentPage $page): JsonResponse
    {
        $this->authorizePage($request, $page);

        $validated = $request->validate(['keep_detection' => ['sometimes', 'boolean']]);

        if (! $page->isFinished() && $page->status !== DocumentPage::STATUS_CANCELLED) {
            $backToDetected = ($validated['keep_detection'] ?? false) && $page->status === DocumentPage::STATUS_READING;
            $page->forceFill([
                'status' => $backToDetected ? DocumentPage::STATUS_DETECTED : DocumentPage::STATUS_CANCELLED,
            ])->save();
        }

        return response()->json(['id' => $page->getKey(), 'status' => $page->status]);
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
        // The route's scopeBindings() already ensures this; checked again on purpose.
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
        // The route's scopeBindings() already ensures this; checked again on purpose.
        abort_unless((int) $line->document_page_id === (int) $page->getKey(), 404);

        // A read page (Verify) or a detected one (the Align step, after Detect
        // and before anything is read). A detected line is only re-cropped:
        // Scan with OCR reads it with all the others.
        $detected = $page->status === DocumentPage::STATUS_DETECTED;
        if (! $detected && $page->status !== DocumentPage::STATUS_READY) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $validated = $request->validate([
            'polygon' => ['required', 'array', 'min:3', 'max:2000'],
            'polygon.*' => ['required', 'array', 'size:2'],
            'polygon.*.*' => ['required', 'numeric'],
            // Back to the outline the detector drew: no longer counts as adjusted.
            'reset' => ['sometimes', 'boolean'],
            // The reviewer confirmed that this line belongs in another cell.
            'allow_move' => ['sometimes', 'boolean'],
        ]);

        $polygon = $this->clampPolygon($validated['polygon'], $page);

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

        // Adjusting an outline is meant to fix that field's crop. An outline
        // drawn over another cell would quietly give this field to another
        // person (and leave its own cell empty), so a line already placed in a
        // row moves only when the reviewer confirms it. A line with no row is
        // placed from its outline as before: that is how it gets a row.
        $move = $this->cellChange($page, $line, $placed);
        if ($move !== null && ! $request->boolean('allow_move') && ! $request->boolean('reset')) {
            $disk->delete($cropPath);

            return response()->json([
                'message' => "This outline sits in {$move['to']['column']}, row {$move['to']['row']}, "
                    ."not in {$move['from']['column']}, row {$move['from']['row']}. Nothing was saved.",
                'move' => $move,
            ], 409);
        }

        $previousCrop = $line->crop_path;
        $line->fill([
            'polygon' => $polygon,
            'bbox' => $placed['bbox'],
            'crop_path' => $cropPath,
            'crop_version' => $version,
            'adjusted_at' => $request->boolean('reset') ? null : now(),
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
            if (! $detected) {
                $this->reader->read($page, collect([$line]));
            }
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
            'flags' => $this->lineFlags($page),
        ], $status);
    }

    /**
     * Draw one line by hand, where the detector found none: writing it missed,
     * or a line removed and wanted back in another shape. The outline is
     * cropped and placed in the page's grid exactly as a redrawn one is.
     */
    public function storeLine(Request $request, DocumentPage $page): JsonResponse
    {
        $this->authorizePage($request, $page);

        if ($page->status !== DocumentPage::STATUS_DETECTED) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $validated = $request->validate([
            'polygon' => ['required', 'array', 'min:3', 'max:2000'],
            'polygon.*' => ['required', 'array', 'size:2'],
            'polygon.*.*' => ['required', 'numeric'],
        ]);

        $polygon = $this->clampPolygon($validated['polygon'], $page);

        $disk = Storage::disk('local');
        // Past every position ever used on this page, removed lines included,
        // so a crop can never land on another line's file.
        $position = (int) $page->lines()->withTrashed()->max('position') + 1;
        $cropPath = sprintf('%s/crops/%03d-drawn.png', $page->directory(), $position + 1);

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

        $line = $page->lines()->create([
            'position' => $position,
            'polygon' => $polygon,
            'bbox' => $placed['bbox'],
            'crop_path' => $cropPath,
            'adjusted_at' => now(),
            ...$this->drawnLinePlacement($page, $placed, $polygon),
        ]);
        $this->refreshSharedCells($page);

        return response()->json([
            'line' => $line->fresh()->toClient(),
            'flags' => $this->lineFlags($page),
        ], 201);
    }

    /**
     * Remove one outlined line before it is read: a stray mark the detector
     * took for writing, a word wrongly split in two, or a line that simply
     * does not belong. The line and its crop are kept until the page is
     * outlined again, so it can be put back (Ctrl+Z); nothing reads or
     * submits it meanwhile.
     *
     * Only while the page still holds Detect's result - once a page is read,
     * Verify and submission work from its lines.
     */
    public function destroyLine(Request $request, DocumentPage $page, PageLine $line): JsonResponse
    {
        $this->authorizePage($request, $page);
        // The route's scopeBindings() already ensures this; checked again on purpose.
        abort_unless((int) $line->document_page_id === (int) $page->getKey(), 404);

        if ($page->status !== DocumentPage::STATUS_DETECTED) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $keptCell = ! $line->belongsToField();
        $line->delete();

        // Removing a line can resolve a shared cell elsewhere (the two lines
        // that shared it are now one).
        if ($keptCell) {
            $this->refreshSharedCells($page);
        }

        return response()->json([
            'flags' => $this->lineFlags($page),
        ]);
    }

    /** Put back a line removed a moment ago (Ctrl+Z). */
    public function restoreLine(Request $request, DocumentPage $page, PageLine $line): JsonResponse
    {
        $this->authorizePage($request, $page);
        // The route's scopeBindings() already ensures this; checked again on purpose.
        abort_unless((int) $line->document_page_id === (int) $page->getKey(), 404);

        if ($page->status !== DocumentPage::STATUS_DETECTED) {
            return response()->json(['message' => 'This page is still being processed.'], 409);
        }

        $line->restore();
        if (! $line->belongsToField()) {
            $this->refreshSharedCells($page);
        }

        return response()->json([
            'line' => $line->fresh()->toClient(),
            'flags' => $this->lineFlags($page),
        ]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * An outline drawn or redrawn by hand, kept on the page and rounded to a
     * tenth of a pixel.
     *
     * @param  array<int, array<int, mixed>>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function clampPolygon(array $points, DocumentPage $page): array
    {
        return array_map(fn (array $point) => [
            round(min(max((float) $point[0], 0), $page->width), 1),
            round(min(max((float) $point[1], 0), $page->height), 1),
        ], $points);
    }

    /**
     * Every line's flags, by line id. A line that is moved, drawn, removed or
     * restored can create or resolve a shared cell elsewhere, so the Align step
     * takes them all again.
     *
     * @return Collection<int, list<string>>
     */
    private function lineFlags(DocumentPage $page): Collection
    {
        return $page->lines()->get()->mapWithKeys(fn (PageLine $l) => [$l->getKey() => $l->flags ?? []]);
    }

    /**
     * Which cell or field a hand-drawn outline belongs to.
     *
     * A ledger page places it by its grid, as a redrawn outline is placed. On
     * a page of drawn boxes there is no grid, so it belongs to the field it
     * was drawn inside, as the last of that field's lines.
     *
     * @param  array{column_index: int|null, row: int|null, flags: list<string>}  $placed
     * @param  list<list<float>>  $polygon
     * @return array<string, mixed>
     */
    private function drawnLinePlacement(DocumentPage $page, array $placed, array $polygon): array
    {
        $columns = $page->geometry['columns'] ?? [];
        $column = $placed['column_index'];

        if ($column !== null && isset($columns[$column])) {
            return [
                'source' => PageLine::SOURCE_MANUAL,
                'column_index' => $column,
                'column_name' => mb_substr((string) $columns[$column]['name'], 0, 500),
                'row' => $placed['row'],
                'flags' => in_array(PageLine::FLAG_NO_ROW, $placed['flags'], true) ? [PageLine::FLAG_NO_ROW] : [],
            ];
        }

        $field = $this->fieldAround($page, $polygon);
        if ($field === null) {
            return [
                'source' => PageLine::SOURCE_MANUAL,
                'column_name' => 'Drawn line',
                'flags' => [PageLine::FLAG_NO_ROW],
            ];
        }

        // Its line number within the field: after the lines already there, so
        // no existing line is renumbered under the reviewer.
        $name = mb_substr((string) $field['name'], 0, 500);
        $last = (int) $page->lines()
            ->where('source', PageLine::SOURCE_FIELD)
            ->where('column_name', $name)
            ->max('row');

        return [
            'source' => PageLine::SOURCE_FIELD,
            'column_name' => $name,
            'row' => $last + 1,
            'person_group' => $field['person_group'] ?? null,
            'person_field_order' => $field['person_field_order'] ?? null,
            'flags' => [],
        ];
    }

    /**
     * The template field a drawn outline sits in, by its centre.
     *
     * @param  list<list<float>>  $polygon
     * @return array<string, mixed>|null
     */
    private function fieldAround(DocumentPage $page, array $polygon): ?array
    {
        $xs = array_column($polygon, 0);
        $ys = array_column($polygon, 1);
        $cx = (min($xs) + max($xs)) / 2 / max(1, $page->width);
        $cy = (min($ys) + max($ys)) / 2 / max(1, $page->height);

        foreach ($page->geometry['fields'] ?? [] as $field) {
            [$x, $y, $w, $h] = array_map('floatval', $field['box']);
            if ($cx >= $x && $cx <= $x + $w && $cy >= $y && $cy <= $y + $h) {
                return $field;
            }
        }

        return null;
    }


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
            // What Staff should check about the grid as a whole.
            'notes' => $page->notes ?? [],
            'statusUrl' => route('documents.pages.show', $page),
            'readUrl' => route('documents.pages.read', $page),
            'cancelUrl' => route('documents.pages.cancel', $page),
            'imageUrl' => route('documents.pages.image', ['page' => $page, 'v' => $page->updated_at?->timestamp]),
            'model' => $page->ocr_model_label,
            'modelKey' => $page->ocr_model_key,
            'threshold' => OcrSetting::threshold(),
            'lines' => $page->hasLines() ? $page->lines()->get()->map->toClient()->values() : [],
        ];
    }

    /**
     * The cell a redrawn ledger line would move to, when it is not its own.
     *
     * @param  array{column_index: int|null, row: int|null}  $placed
     * @return array{from: array{column: string, row: int}, to: array{column: string, row: int|string}}|null
     */
    private function cellChange(DocumentPage $page, PageLine $line, array $placed): ?array
    {
        if ($line->belongsToField() || $line->row === null) {
            return null;
        }

        $column = $placed['column_index'] ?? $line->column_index;
        if ((int) $column === (int) $line->column_index && $placed['row'] === $line->row) {
            return null;
        }

        $columns = $page->geometry['columns'] ?? [];

        return [
            'from' => ['column' => (string) $line->column_name, 'row' => (int) $line->row],
            'to' => [
                'column' => (string) ($columns[$column]['name'] ?? $line->column_name),
                'row' => $placed['row'] ?? 'none (between rows)',
            ],
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
}
