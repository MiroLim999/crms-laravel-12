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
use App\Services\Ocr\OcrServiceException;
use App\Services\Ocr\ScanModelChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The digitisation flow: upload a scan, mark the fields, run OCR, verify, submit.
 *
 * Staff and Super Admin only. Admin has no route into this controller at all -
 * data entry is not an oversight function.
 *
 * Cropping happens in the browser, matching the prototype, because the crops come
 * straight off the rendered canvas at full resolution. The server never needs the
 * image library that would otherwise be required.
 */
class DocumentScanController extends Controller
{
    public function __construct(
        private readonly OcrClient $ocr,
        private readonly AuditLogger $audit,
        private readonly ScanModelChoice $modelChoice,
    ) {}

    /**
     * Step 1: pick a certificate type and upload a scan.
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
     * Step 2: the marking and verification workspace.
     *
     * The scan itself stays in the browser until submission, so an abandoned
     * session leaves nothing behind on disk.
     */
    public function workspace(Request $request): View|RedirectResponse
    {
        $type = DocumentTypeDefinition::where('key', (string) $request->query('type'))->first();

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
     * Run OCR over the cropped fields.
     *
     * Proxied rather than called from the browser: the OCR service has no auth of
     * its own, so the capability check has to happen here.
     */
    public function recognise(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:450'],
            'fields.*.name' => ['required', 'string', 'max:500'],
            'fields.*.image' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
        ]);

        $key = $this->resolveModelKey($validated['model'] ?? null);

        try {
            $result = $this->ocr->recognise($validated['fields'], $key);
        } catch (OcrServiceException $e) {
            // A clear failure, not a stack trace, and nothing persisted.
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'results' => $result['results'],
            'model' => $result['model'],
            'modelKey' => $result['modelKey'],
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
            'scan' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp,bmp,tiff', 'max:20480'],
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
        ]);

        $templateId = (int) $validated['document_template_id'];
        $documentType = DocumentTypeDefinition::where('key', (string) $validated['doc_type'])->firstOrFail();
        $template = DocumentTemplate::with(['documentTypeDefinition', 'fields'])->find($templateId);

        if ($template?->document_type_id !== $documentType->getKey()) {
            throw ValidationException::withMessages([
                'document_template_id' => 'The selected template does not belong to this document type.',
            ]);
        }

        $requiredByName = $template->fields->mapWithKeys(
            fn ($field) => [mb_strtolower(trim($field->name)) => $field->is_required],
        );

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

        $scan = $request->file('scan');
        if (! $scan instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'scan' => 'Choose a valid scanned document to submit.',
            ]);
        }

        $path = $scan->store('scans', 'local');
        $copiedCrops = [];

        try {
            $record = DB::transaction(function () use ($request, $scan, $validated, $path, $documentType, $requiredByName, $page, $linesById, &$copiedCrops) {

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

                foreach (array_values($validated['fields']) as $index => $field) {
                    $line = isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null;

                    $record->fields()->create([
                        'name' => $field['name'],
                        'ocr_text' => $field['ocr_text'] ?? null,
                        'ocr_confidence' => $field['ocr_confidence'] ?? null,
                        'verified_value' => $field['verified_value'],
                        'is_required' => $requiredByName->get(
                            mb_strtolower(trim($field['name'])),
                            true,
                        ),
                        'person_group' => $field['person_group'] ?? null,
                        'person_field_order' => $field['person_field_order'] ?? null,
                        'x' => $field['x'],
                        'y' => $field['y'],
                        'width' => $field['width'],
                        'height' => $field['height'],
                        'sort_order' => $index,
                        ...($line ? $this->lineAttributes($record, $page, $line, $index, $copiedCrops) : []),
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
                    ],
                    description: "Submitted and locked a {$record->typeShortLabel()} record.",
                );

                return $record;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete([$path, ...$copiedCrops]);
            throw $exception;
        }

        // The record now holds its own copies of every crop it kept. The page's
        // working files (image, remaining crops, overlay) are no longer needed.
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
     * Keep the exact crop TrOCR read and the outline it was read from.
     *
     * The crop is copied, not re-made, so the archive and the training export
     * always hold the very image the model saw.
     *
     * @param  list<string>  $copiedCrops
     * @return array<string, mixed>
     */
    private function lineAttributes(CivilRecord $record, DocumentPage $page, PageLine $line, int $index, array &$copiedCrops): array
    {
        $disk = Storage::disk('local');
        $cropPath = sprintf('records/%d/crops/%03d.png', $record->getKey(), $index + 1);
        $disk->copy($line->crop_path, $cropPath);
        $copiedCrops[] = $cropPath;

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

    private function resolveModelKey(?string $requested): ?string
    {
        return $this->modelChoice->resolve($requested);
    }
}
