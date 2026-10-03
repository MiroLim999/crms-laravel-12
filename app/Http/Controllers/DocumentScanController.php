<?php

namespace App\Http\Controllers;

use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\PageLine;
use App\Services\AuditLogger;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\ScanModelChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The digitisation flow: pick a certificate type, then upload, align, verify
 * and submit in the workspace.
 *
 * Staff and Super Admin only. Admin has no route into this controller at all -
 * data entry is not an oversight function.
 *
 * The page itself is handled by DocumentPageController: finishing Align uploads
 * the aligned page, and a queue job (ProcessDocumentPage) outlines every
 * handwritten line, crops along the outlines and reads each crop with TrOCR
 * (Detect stops before the reading, so Staff can check the outlines first).
 * Verify reviews those readings. Submitting (store) saves the checked values and
 * copies the page image and the crops into the record, so the archive keeps the
 * very images the model read.
 */
class DocumentScanController extends Controller
{
    public function __construct(
        private readonly OcrClient $ocr,
        private readonly AuditLogger $audit,
        private readonly ScanModelChoice $modelChoice,
    ) {}

    /**
     * Step 1: pick a certificate type. The scan itself is chosen in the workspace.
     */
    public function create(): View
    {
        $health = $this->ocr->health();

        $documentTypes = DocumentTypeDefinition::ordered();
        $templates = $documentTypes
            ->mapWithKeys(fn (DocumentTypeDefinition $type) => [
                $type->key => DocumentTemplate::activeFor($type),
            ]);

        return view('scan.create', [
            'documentTypes' => $documentTypes,
            'templates' => $templates,
            'health' => $health,
            'activeModel' => OcrModel::active(),
        ]);
    }

    /**
     * Step 2: the workspace, where Staff upload the scan, align the markers and
     * verify what was read.
     *
     * The aligned page is uploaded as soon as Staff finish Align, before they
     * decide to submit; the original file follows only with the submission. A
     * page nobody submits is deleted by documents:prune-pages (hourly) once it
     * has gone untouched for LINE_MARKERS_KEEP_HOURS, 24 by default.
     */
    public function workspace(Request $request): View|RedirectResponse
    {
        // An edited URL can send ?type[]=x, an array. Treat it as an unknown type.
        $key = $request->query('type');
        $type = is_string($key) ? DocumentTypeDefinition::where('key', $key)->first() : null;

        if ($type === null) {
            return redirect()->route('documents.create');
        }

        $template = DocumentTemplate::activeFor($type);

        if ($template === null) {
            return redirect()->route('documents.create')->with(
                'error',
                "No active template for {$type->label()}. A Super Admin must publish one first.",
            );
        }

        return view('scan.workspace', [
            'docType' => $type,
            'template' => $template,
            // Rectangle fields, then ledger columns. Staff align both the same way.
            'boxes' => $template->markerBoxes(),
            'ruledYs' => $template->isLedger() ? array_values($template->ruled_ys) : [],
            'activeModel' => OcrModel::active(),
            // Empty unless a Super Admin has allowed it, in which case the reading
            // step offers a picker instead of silently using the promoted model.
            'selectableModels' => $this->selectableModels(),
            'threshold' => OcrSetting::threshold(),
        ]);
    }

    /**
     * Step 3: persist the verified record and lock it.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->hydrateJsonFields($request);

        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'document_template_id' => ['required', 'exists:document_templates,id'],
            'registry_number' => ['nullable', 'string', 'max:64'],
            'ocr_model_key' => [
                'required',
                'string',
                'max:255',
                Rule::exists(OcrModel::class, 'key')->whereNull('disk_deleted_at'),
            ],
            'scan' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp,bmp', 'max:20480'],
            'fields' => ['required', 'array', 'min:1', 'max:450'],
            'fields.*.verified' => ['required', 'accepted'],
            'fields.*.name' => ['required', 'string', 'max:500', 'distinct:ignore_case'],
            'fields.*.ocr_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.ocr_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fields.*.verified_value' => ['required', 'string', 'max:2000'],
            'fields.*.person_group' => ['nullable', 'integer', 'min:1', 'max:450'],
            'fields.*.person_field_order' => ['nullable', 'integer', 'min:0', 'max:449'],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            // The processed page whose outlined lines these fields were read from.
            'document_page_id' => ['nullable', 'integer'],
            'fields.*.line_id' => ['nullable', 'integer', 'distinct'],
            // Staff chose "Submit anyway" when asked about missing required fields.
            'allow_missing' => ['sometimes', 'boolean'],
        ], [
            'scan.mimes' => 'The scan must be a PDF, PNG, JPG, WEBP or BMP file.',
        ]);

        $templateId = (int) $validated['document_template_id'];
        $documentType = DocumentTypeDefinition::where('key', (string) $validated['doc_type'])->firstOrFail();
        $template = DocumentTemplate::with(['documentTypeDefinition', 'fields'])->find($templateId);

        if ($template?->document_type_id !== $documentType->getKey()) {
            throw ValidationException::withMessages([
                'document_template_id' => 'The selected template does not belong to this document type.',
            ]);
        }

        // What each field holds and whether it is required, by its template
        // field's name (a ledger line: its column's). A field Staff added
        // themselves has no template settings.
        $settingsByName = $template->settingsByName();

        $coordinateErrors = [];
        foreach ($validated['fields'] as $index => $field) {
            $hasPersonGroup = isset($field['person_group']);
            $hasPersonOrder = isset($field['person_field_order']);
            if ($hasPersonGroup !== $hasPersonOrder) {
                $coordinateErrors["fields.{$index}.person_group"] =
                    'A grouped field must include both its person and field order.';
            }
            if ((float) $field['x'] + (float) $field['width'] > 1.00001) {
                $coordinateErrors["fields.{$index}.width"] = 'This field marker extends beyond the document width.';
            }
            if ((float) $field['y'] + (float) $field['height'] > 1.00001) {
                $coordinateErrors["fields.{$index}.height"] = 'This field marker extends beyond the document height.';
            }
        }
        if ($coordinateErrors !== []) {
            throw ValidationException::withMessages($coordinateErrors);
        }

        [$page, $linesById] = $this->pageLinesFor($request, $validated, $templateId);

        // Some certificates do leave a required field blank, so a missing one
        // is not refused outright: Staff must confirm it first.
        $missingRequired = [
            ...$this->missingRequiredFields($template, $validated['fields'], $linesById),
            ...$this->missingRegisterRows($template, $page, $validated['fields'], $linesById),
        ];
        if ($missingRequired !== [] && ! $request->boolean('allow_missing')) {
            // One message per field, so the workspace can list them when it asks.
            throw ValidationException::withMessages(['missing_required' => $missingRequired]);
        }

        $scan = $request->file('scan');
        if (! $scan instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'scan' => 'Choose a valid scanned document to submit.',
            ]);
        }

        $path = $scan->store('scans', 'local');
        $copiedFiles = [];

        try {
            $record = DB::transaction(function () use ($request, $scan, $validated, $path, $documentType, $settingsByName, $page, $linesById, $missingRequired, &$copiedFiles) {

                $record = CivilRecord::create([
                    'doc_type' => $documentType->legacyType()->value,
                    'document_type_id' => $documentType->getKey(),
                    'document_template_id' => $validated['document_template_id'],
                    'registry_number' => $validated['registry_number'] ?? null,
                    'status' => RecordStatus::Submitted,
                    'scan_path' => $path,
                    'scan_mime' => $scan->getMimeType(),
                    // Field outlines are fractions of the page as outlined,
                    // which Detect may have straightened.
                    'scan_rotation' => $page?->deskew_degrees,
                    'ocr_model_key' => $validated['ocr_model_key'],
                    'created_by' => $request->user()->getKey(),
                    'submitted_by' => $request->user()->getKey(),
                    'submitted_at' => now(),
                ]);

                // Every outline below is a fraction of the page as Detect left
                // it, which may be straightened and larger than the upload, so
                // the record page draws its boxes over this image instead.
                if ($page !== null) {
                    $record->update(['page_image_path' => $this->keepPageImage($record, $page, $copiedFiles)]);
                }

                foreach (array_values($validated['fields']) as $index => $field) {
                    $line = isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null;
                    $settings = $settingsByName[$this->templateNameKey($field, $line)] ?? null;

                    $record->fields()->create([
                        'name' => $field['name'],
                        'ocr_text' => $field['ocr_text'] ?? null,
                        'ocr_confidence' => $field['ocr_confidence'] ?? null,
                        'verified_value' => $field['verified_value'],
                        'is_required' => $settings['required'] ?? true,
                        // Kept with the record, so it keeps its person's name
                        // whatever later happens to the layout.
                        'role' => $settings['role'] ?? null,
                        'person_group' => $field['person_group'] ?? null,
                        'person_field_order' => $field['person_field_order'] ?? null,
                        'x' => $field['x'],
                        'y' => $field['y'],
                        'width' => $field['width'],
                        'height' => $field['height'],
                        'sort_order' => $index,
                        ...($line ? $this->lineAttributes($record, $page, $line, $index, $copiedFiles) : []),
                    ]);
                }

                $record->load('fields');

                $corrected = $record->fields->filter->wasCorrected()->count();

                $this->audit->log(
                    'record.submitted',
                    $record,
                    new: [
                        'document_type' => $documentType->key,
                        'registry_number' => $validated['registry_number'] ?? null,
                        'field_count' => $record->fields->count(),
                        'corrected_fields' => $corrected,
                        'outlined_fields' => $record->fields->whereNotNull('polygon')->count(),
                        'ocr_model' => $validated['ocr_model_key'],
                        // Left out on purpose: Staff confirmed with "Submit anyway".
                        ...($missingRequired !== [] ? ['missing_required_fields' => $missingRequired] : []),
                    ],
                    description: "Submitted and locked a {$record->typeShortLabel()} record.",
                );

                return $record;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete([$path, ...$copiedFiles]);
            throw $exception;
        }

        // The record now holds its own copies of every crop it kept, and of the
        // page image. The page's working files (image, remaining crops,
        // overlay) are no longer needed.
        if ($page !== null) {
            Storage::disk('local')->deleteDirectory($page->directory());
            $page->delete();
        }

        $redirect = route('records.show', $record);
        $message = 'Record submitted and locked. Further changes need a change request.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => $redirect,
            ], 201);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    private function hydrateJsonFields(Request $request): void
    {
        if (! $request->filled('fields_json')) {
            return;
        }

        try {
            $fields = json_decode((string) $request->input('fields_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'fields' => 'The verified fields could not be read. Return to validation and try again.',
            ]);
        }

        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => 'The verified fields must be a valid list.',
            ]);
        }

        $request->merge(['fields' => $fields]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * The processed page and its lines that the submitted fields point at.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: DocumentPage|null, 1: \Illuminate\Support\Collection<int, PageLine>}
     */
    private function pageLinesFor(Request $request, array $validated, int $templateId): array
    {
        $lineIds = collect($validated['fields'])->pluck('line_id')->filter()->map(fn ($id) => (int) $id);

        if (empty($validated['document_page_id'])) {
            if ($lineIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'document_page_id' => 'The outlined page these fields came from is missing. Scan the page again.',
                ]);
            }

            return [null, collect()];
        }

        $page = DocumentPage::query()
            ->whereKey((int) $validated['document_page_id'])
            ->where('created_by', $request->user()->getKey())
            ->first();

        if ($page === null || $page->status !== DocumentPage::STATUS_READY
            || (int) $page->document_template_id !== $templateId) {
            throw ValidationException::withMessages([
                'document_page_id' => 'This page is no longer available. Scan it again before submitting.',
            ]);
        }

        $lines = $page->lines()->whereIn('id', $lineIds)->get()->keyBy('id');

        $errors = [];
        foreach ($validated['fields'] as $index => $field) {
            if (isset($field['line_id']) && ! $lines->has((int) $field['line_id'])) {
                $errors["fields.{$index}.line_id"] = 'This field no longer matches an outlined line on the page.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$page, $lines];
    }

    /**
     * The layout's required fields (not a register's columns) that nothing
     * was submitted for.
     *
     * A field counts under the template field it was read under, so one that
     * Detect split into written lines ("Diseases · line 2") is not missing.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  Collection<int, PageLine>  $linesById
     * @return list<string>
     */
    private function missingRequiredFields(DocumentTemplate $template, array $fields, Collection $linesById): array
    {
        $submitted = collect($fields)
            ->map(fn (array $field) => $this->templateNameKey(
                $field,
                isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null,
            ))
            ->flip();

        return $template->fields
            ->filter(fn ($field) => $field->is_required && ! $submitted->has(mb_strtolower(trim($field->name))))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The name a submitted field's template settings are found under: its
     * line's field or column, else its own name (see settingsByName()).
     *
     * @param  array<string, mixed>  $field
     */
    private function templateNameKey(array $field, ?PageLine $line): string
    {
        return mb_strtolower(trim($line?->column_name ?? $field['name']));
    }

    /**
     * In a register, the required columns missing from each row that has
     * something submitted ("Person 01: Date of Birth"), then the rows with
     * nothing submitted at all. The page is removed once the record is saved,
     * so those rows would have to be scanned again.
     *
     * Rows are named as Verify names its people; the workspace makes the
     * same list before sending.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  Collection<int, PageLine>  $linesById
     * @return list<string>
     */
    private function missingRegisterRows(DocumentTemplate $template, ?DocumentPage $page, array $fields, Collection $linesById): array
    {
        if ($page === null || ! $template->isLedger()) {
            return [];
        }

        $columns = [];
        $required = [];
        foreach ($template->columns as $column) {
            $key = mb_strtolower(trim((string) $column['name']));
            $columns[$key] = true;
            if (DocumentTemplate::columnSettings($column)['required']) {
                $required[$key] = (string) $column['name'];
            }
        }

        // A cell of a ledger row: what Verify groups into a person.
        $isCell = fn (PageLine $line) => ! $line->belongsToField()
            && $line->row !== null && $line->column_index !== null
            && ! in_array(PageLine::FLAG_NO_ROW, $line->flags ?? [], true);
        $columnOf = fn (PageLine $line) => mb_strtolower(trim((string) $line->column_name));

        $rows = $page->lines
            ->filter(fn (PageLine $line) => $isCell($line) && isset($columns[$columnOf($line)]))
            ->pluck('row')
            ->unique()
            ->sort()
            ->values();

        $submitted = [];
        foreach ($fields as $field) {
            $line = isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null;
            if ($line !== null && $isCell($line)) {
                $submitted[$line->row][$columnOf($line)] = true;
            }
        }

        $missing = [];
        $untouched = [];
        foreach ($rows as $row) {
            if (! isset($submitted[$row])) {
                $untouched[] = $row;

                continue;
            }

            $left = array_values(array_diff_key($required, $submitted[$row]));
            if ($left !== []) {
                $missing[] = $this->personLabel($row).': '.implode(', ', $left);
            }
        }

        if ($untouched !== []) {
            $missing[] = sprintf(
                'Nothing ticked in %s (%s not saved)',
                $this->rowRanges($untouched),
                count($untouched) === 1 ? 'this row is' : 'these rows are',
            );
        }

        return $missing;
    }

    /** A ledger row as Verify names it: one person per row. */
    private function personLabel(int $row): string
    {
        return sprintf('Person %02d', $row);
    }

    /**
     * "Person 02 – Person 05, Person 07" for rows 2 to 5 and 7.
     *
     * @param  list<int>  $rows  in order
     */
    private function rowRanges(array $rows): string
    {
        $runs = [];
        foreach ($rows as $row) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][1] === $row - 1) {
                $runs[$last][1] = $row;
            } else {
                $runs[] = [$row, $row];
            }
        }

        return implode(', ', array_map(
            fn (array $run) => $run[0] === $run[1]
                ? $this->personLabel($run[0])
                : $this->personLabel($run[0]).' – '.$this->personLabel($run[1]),
            $runs,
        ));
    }

    /**
     * Keep the page as it was outlined, next to the crops.
     *
     * Returns its path, or null when the page's file is gone: the record page
     * then shows the upload, as it did before records kept a page image.
     *
     * @param  list<string>  $copiedFiles
     */
    private function keepPageImage(CivilRecord $record, DocumentPage $page, array &$copiedFiles): ?string
    {
        $disk = Storage::disk('local');
        $path = sprintf('records/%d/page.png', $record->getKey());

        if (! $disk->exists($page->image_path) || ! $disk->copy($page->image_path, $path)) {
            return null;
        }

        $copiedFiles[] = $path;

        return $path;
    }

    /**
     * Keep the exact crop TrOCR read and the outline it was read from.
     *
     * The crop is copied, not re-made, so the archive and the training export
     * always hold the very image the model saw.
     *
     * @param  list<string>  $copiedFiles
     * @return array<string, mixed>
     */
    private function lineAttributes(CivilRecord $record, DocumentPage $page, PageLine $line, int $index, array &$copiedFiles): array
    {
        $disk = Storage::disk('local');
        $cropPath = sprintf('records/%d/crops/%03d.png', $record->getKey(), $index + 1);
        $disk->copy($line->crop_path, $cropPath);
        $copiedFiles[] = $cropPath;

        $width = max(1, $page->width);
        $height = max(1, $page->height);

        return [
            'crop_path' => $cropPath,
            'polygon' => array_map(
                fn (array $point) => [round($point[0] / $width, 5), round($point[1] / $height, 5)],
                $line->polygon,
            ),
            'line_column' => $line->column_name,
            'line_row' => $line->row,
            'line_flags' => $line->flags ?: [],
        ];
    }

    /**
     * @return list<array{key: string, label: string, is_active: bool}>
     */
    private function selectableModels(): array
    {
        return $this->modelChoice->selectable();
    }
}
