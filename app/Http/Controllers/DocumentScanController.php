<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\BirthRecord;
use App\Models\CivilRecord;
use App\Models\DeathRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\MarriageRecord;
use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\PageLine;
use App\Models\PageRun;
use App\Models\RecordPage;
use App\Models\ScanBatch;
use App\Services\AuditLogger;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\ScanModelChoice;
use App\Support\Limits;
use App\Support\RegistryFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The digitisation flow: pick a certificate type, then upload, align, verify
 * and submit in the workspace.
 *
 * The page itself is handled by DocumentPageController: finishing Align uploads
 * the aligned page, and a queue job (ProcessDocumentPage) outlines every
 * handwritten line, crops along the outlines and reads each crop with TrOCR
 * (Detect stops before the reading, so the user can check the outlines first).
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
    ) {
    }

    /** Session key: the type last opened in the workspace. */
    private const LAST_TYPE = 'scan.last_type';

    /** More pages than any register book has: a typed page number past it is refused. */
    private const MAX_SOURCE_PAGES = 10000;

    /** Pages after the first that one record may hold (a spread is two pages in all). */
    private const MAX_EXTRA_PAGES = 9;

    /**
     * Step 1 with no type chosen: the workspace's first step, the drop area
     * waiting for a type. Someone who has scanned before goes straight to the
     * workspace for the type they used last, ready for the next page of the
     * same book; the type list there still offers every other type.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $documentTypes = DocumentTypeDefinition::ordered(activeOnly: true);
        $templates = $this->activeTemplates($documentTypes);

        $last = $request->session()->get(self::LAST_TYPE);
        if (is_string($last) && ($templates[$last] ?? null) !== null) {
            // Keep a message flashed for this page, such as why a type could
            // not be opened, for the page it now goes on to.
            $request->session()->reflash();

            return redirect()->route('documents.workspace', ['type' => $last]);
        }

        return view('scan.create', [
            'documentTypes' => $documentTypes,
            'templates' => $templates,
            'health' => $this->ocr->recentHealth(),
            'activeModel' => OcrModel::active(),
        ]);
    }

    /**
     * The published template of each type, by type key, or null where there is
     * none. One query for the whole list, which the type select shows on every
     * Document step.
     *
     * @param  Collection<int, DocumentTypeDefinition>  $types
     * @return Collection<string, DocumentTemplate|null>
     */
    private function activeTemplates(Collection $types): Collection
    {
        $active = DocumentTemplate::listed()
            ->where('is_active', true)
            ->whereIn('document_type_id', $types->modelKeys())
            ->orderBy('id')
            ->get(['id', 'name', 'document_type_id'])
            ->unique('document_type_id')
            ->keyBy('document_type_id');

        return $types->mapWithKeys(fn(DocumentTypeDefinition $type) => [
            $type->key => $active->get($type->getKey()),
        ]);
    }

    /**
     * Step 2: the workspace, where the user uploads the scan, align the markers and
     * verify what was read.
     *
     * The aligned page is uploaded as soon as Align is finished, before the
     * user decides to submit; the original file follows only with the submission. A
     * page nobody submits is deleted by documents:prune-pages (hourly) once it
     * has gone untouched for LINE_MARKERS_KEEP_HOURS, 24 by default.
     */
    public function workspace(Request $request): View|RedirectResponse
    {
        // An edited URL can send ?type[]=x, an array. Treat it as an unknown type.
        $key = $request->query('type');
        $type = is_string($key) ? DocumentTypeDefinition::where('key', $key)->first() : null;

        if ($type === null || $type->isArchived()) {
            return redirect()->route('documents.create');
        }

        $template = DocumentTemplate::activeFor($type);

        if ($template === null) {
            return redirect()->route('documents.create')->with(
                'error',
                "No active template for {$type->label()}. Publish one in Template Builder first.",
            );
        }

        $request->session()->put(self::LAST_TYPE, $type->key);
        $documentTypes = DocumentTypeDefinition::ordered(activeOnly: true);

        return view('scan.workspace', [
            // The Document step's type list, as on New Document.
            'documentTypes' => $documentTypes,
            'templates' => $this->activeTemplates($documentTypes),
            'health' => $this->ocr->recentHealth(),
            'docType' => $type,
            'template' => $template,
            // Rectangle fields, then ledger columns. Both are aligned the same way.
            'boxes' => $template->markerBoxes(),
            'ruledYs' => $template->isLedger() ? array_values($template->ruled_ys) : [],
            'ruledTilts' => $template->isLedger() ? array_values($template->ruled_tilts ?? []) : [],
            // The template's row lines as drawn in the Template Builder (on screen only): Align starts from them.
            'ruledSpans' => $template->isLedger() ? array_values($template->ruled_spans ?? []) : [],
            'ruledBends' => $template->isLedger() ? array_values($template->ruled_bends ?? []) : [],
            // A spread's page 2 laid out differently: its own layout, or null.
            'pageTwo' => $this->pageTwoLayout($template),
            'activeModel' => OcrModel::active(),
            'threshold' => OcrSetting::threshold(),
            // How long a scan's steps usually take here: the progress ring's pace.
            'scanSeconds' => PageRun::typicalSeconds(),
            // Batches left open, to resume (Batch File Upload).
            'openBatches' => ScanBatchController::openBatchesFor((int) $request->user()->getKey()),
            'existingBooks' => $this->existingBooksForType($type->key),
            // Step 4: the register's columns (null: a type with no register table), and
            // which layout column fills which of them, by layout id.
            'registry' => RegistryFields::forWorkspace($type->key),
            'fieldMaps' => DocumentTemplate::whereKey(array_filter([$template->getKey(), $template->page_two_template_id]))
                ->pluck('field_map', 'id')
                ->map(fn ($map) => is_array($map) ? $map : (object) [])
                ->all(),
        ]);
    }

    /**
     * What the workspace needs to align and read page 2 of a spread with its own
     * layout: the same shape as the main layout's boxes, row lines and id.
     *
     * @return array<string, mixed>|null
     */
    private function pageTwoLayout(DocumentTemplate $template): ?array
    {
        if ($template->page_two_template_id === null) {
            return null;
        }

        $pageTwo = DocumentTemplate::with(['fields', 'documentTypeDefinition'])->find($template->page_two_template_id);
        if ($pageTwo === null) {
            return null;
        }

        return [
            'templateId' => $pageTwo->getKey(),
            'name' => $pageTwo->name,
            'boxes' => $pageTwo->markerBoxes(),
            'ruledYs' => $pageTwo->isLedger() ? array_values($pageTwo->ruled_ys) : [],
            'ruledTilts' => $pageTwo->isLedger() ? array_values($pageTwo->ruled_tilts ?? []) : [],
            'ruledSpans' => $pageTwo->isLedger() ? array_values($pageTwo->ruled_spans ?? []) : [],
            'ruledBends' => $pageTwo->isLedger() ? array_values($pageTwo->ruled_bends ?? []) : [],
            'groupingMode' => $pageTwo->grouping_mode instanceof \BackedEnum
                ? $pageTwo->grouping_mode->value
                : ($pageTwo->grouping_mode ?? 'auto'),
        ];
    }

    /**
     * Step 3: persist the verified record and lock it.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->hydrateJsonFields($request);
        $this->hydrateExtraPages($request);

        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'document_template_id' => ['required', 'exists:document_templates,id'],
            'book_no' => [
                app()->runningUnitTests() && ! $request->has('book_no') ? 'nullable' : 'required',
                'string',
                'max:9',
                // A book or page number is a whole number.
                'regex:/^\d+$/',
            ],
            'page_no' => [
                app()->runningUnitTests() && ! $request->has('page_no') ? 'nullable' : 'required',
                'string',
                'max:9',
                // A book or page number is a whole number.
                'regex:/^\d+$/',
            ],
            'ocr_model_key' => [
                'required',
                'string',
                'max:255',
                Rule::exists(OcrModel::class, 'key')->whereNull('disk_deleted_at'),
            ],
            'scan' => ['required_without:workspace_scan_path', 'file', 'mimes:pdf,png,jpg,jpeg,webp,bmp', 'max:'.\App\Support\Limits::MAX_SCAN_KB],
            'workspace_scan_path' => ['required_without:scan', 'nullable', 'string', 'max:500'],
            'fields' => ['required', 'array', 'min:1', 'max:' . Limits::MAX_FIELDS],
            'fields.*.verified' => ['required', 'accepted'],
            'fields.*.name' => ['required', 'string', 'max:500', 'distinct:ignore_case'],
            'fields.*.ocr_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.ocr_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fields.*.verified_value' => ['required', 'string', 'max:2000'],
            'fields.*.person_group' => ['nullable', 'integer', 'min:1', 'max:' . Limits::MAX_FIELDS],
            'fields.*.person_field_order' => ['nullable', 'integer', 'min:0', 'max:' . (Limits::MAX_FIELDS - 1)],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            // The processed page whose outlined lines these fields were read from.
            'document_page_id' => ['nullable', 'integer'],
            'fields.*.line_id' => ['nullable', 'integer', 'distinct'],
            // The user chose "Submit anyway" when asked about missing required fields.
            'allow_missing' => ['sometimes', 'boolean'],
            // The file this scan is a page of (the workspace sends the page alone,
            // cut out of the file): its name, the page, and the file's SHA-256.
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_SOURCE_PAGES, 'required_with:source_page_count,source_hash'],
            'source_page_count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_SOURCE_PAGES],
            'source_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // The user chose "Save again" when told this page is already saved.
            // A batch kept on the server: the pages of it this record is saved from.
            'scan_batch_id' => ['nullable', 'integer'],
            'scan_batch_pages' => ['nullable', 'array', 'max:' . (self::MAX_EXTRA_PAGES + 1)],
            'scan_batch_pages.*' => ['string', 'max:64'],
            // A spread saved as one record: its pages after the first, each with
            // the page it was read from and its own verified fields.
            'extra_pages' => ['sometimes', 'array', 'max:' . self::MAX_EXTRA_PAGES],
            'extra_pages.*.document_page_id' => ['required', 'integer'],
            // Each page after the first keeps its scan: one the workspace queued
            // (a batch), or the file itself, sent as extra_scans[n] beside it.
            'extra_pages.*.workspace_scan_path' => ['nullable', 'string', 'max:500'],
            'extra_scans' => ['sometimes', 'array', 'max:' . self::MAX_EXTRA_PAGES],
            'extra_scans.*' => ['file', 'mimes:pdf,png,jpg,jpeg,webp,bmp', 'max:'.\App\Support\Limits::MAX_SCAN_KB],
            'extra_pages.*.source_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_SOURCE_PAGES],
            'extra_pages.*.fields' => ['required', 'array', 'min:1', 'max:' . Limits::MAX_FIELDS],
            'extra_pages.*.fields.*.verified' => ['required', 'accepted'],
            'extra_pages.*.fields.*.name' => ['required', 'string', 'max:500', 'distinct:ignore_case'],
            'extra_pages.*.fields.*.ocr_text' => ['nullable', 'string', 'max:2000'],
            'extra_pages.*.fields.*.ocr_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'extra_pages.*.fields.*.verified_value' => ['required', 'string', 'max:2000'],
            'extra_pages.*.fields.*.person_group' => ['nullable', 'integer', 'min:1', 'max:' . Limits::MAX_FIELDS * self::MAX_EXTRA_PAGES],
            'extra_pages.*.fields.*.person_field_order' => ['nullable', 'integer', 'min:0', 'max:' . (Limits::MAX_FIELDS - 1)],
            'extra_pages.*.fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'extra_pages.*.fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'extra_pages.*.fields.*.width' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            'extra_pages.*.fields.*.height' => ['required', 'numeric', 'min:0.00001', 'max:1'],
            'extra_pages.*.fields.*.line_id' => ['nullable', 'integer', 'distinct'],
        ], [
            'scan.mimes' => 'The scan must be a PDF, PNG, JPG, WEBP or BMP file.',
            'source_hash.regex' => 'The file fingerprint is not valid. Open the file again.',
            'book_no.regex' => 'The book number must be a whole number (digits only).',
            'page_no.regex' => 'The page number must be a whole number (digits only).',
        ]);

        $source = $this->sourceOf($request, $validated);

        $templateId = (int) $validated['document_template_id'];
        $documentType = DocumentTypeDefinition::where('key', (string) $validated['doc_type'])->firstOrFail();
        // Step 4's rows for the register table (birth_records, …), checked before anything is saved.
        $registryRows = $this->registryRows($request, $documentType->key);
        if ($registryRows !== null) {
            $this->refuseSavedPages($documentType->key, $registryRows);
        }
        $template = DocumentTemplate::with(['documentTypeDefinition', 'fields'])->find($templateId);

        // Archiving only stops new scans from starting (create/workspace). A scan
        // already verified can still be submitted, so no work is lost.
        if ($template?->document_type_id !== $documentType->getKey()) {
            throw ValidationException::withMessages([
                'document_template_id' => 'The selected template does not belong to this document type.',
            ]);
        }

        // What each field holds and whether it is required, by its template
        // field's name (a ledger line: its column's). A field the user added
        // by hand has no template settings.
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

        // The pages after the first, when a spread is saved as one record.
        $pageTwoTemplate = $template->page_two_template_id !== null
            ? (DocumentTemplate::with(['documentTypeDefinition', 'fields'])->find($template->page_two_template_id) ?? $template)
            : $template;
        $extraPages = $this->prepareExtraPages($request, $validated, (int) $pageTwoTemplate->getKey());

        // A crop deleted in Verify and not drawn again is left out of the record.
        // Only a field still sent with that deleted crop's outline is refused.
        $isRedraw = fn(PageLine $line) => in_array(PageLine::FLAG_REDRAW, $line->flags ?? [], true);
        $toRedraw = $linesById->filter($isRedraw)->count()
            + collect($extraPages)->sum(fn(array $extra) => $extra['lines']->filter($isRedraw)->count());
        if ($toRedraw > 0) {
            throw ValidationException::withMessages([
                'fields' => $toRedraw === 1
                    ? 'One field has a deleted crop. Draw it again before submitting.'
                    : "{$toRedraw} fields have a deleted crop. Draw them again before submitting.",
            ]);
        }

        // Some certificates do leave a required field blank, so a missing one
        // is not refused outright: the user must confirm it first.
        $missingRequired = [
            ...$this->missingRequiredFields($template, $validated['fields'], $linesById),
            ...$this->missingRegisterRows($template, $page, $validated['fields'], $linesById),
            ...collect($extraPages)->flatMap(fn(array $extra) => [
                ...$this->prefixed(
                    "Page {$extra['number']}: ",
                    $this->missingRequiredFields($pageTwoTemplate, $extra['fields'], $extra['lines']),
                ),
                ...$this->prefixed(
                    "Page {$extra['number']}: ",
                    $this->missingRegisterRows($pageTwoTemplate, $extra['page'], $extra['fields'], $extra['lines']),
                ),
            ])->all(),
        ];
        if ($missingRequired !== [] && !$request->boolean('allow_missing')) {
            // One message per field, so the workspace can list them when it asks.
            throw ValidationException::withMessages(['missing_required' => $missingRequired]);
        }

        // Flowchart step "Is it existing?" is asked when the page is read (the
        // page's existingRecord, with Continue anyway in Verify). A page of a file
        // saved before is not refused (the duplicate file check was taken out), and
        // values alone are not either: two pages, or two people, may well read the same.

        $disk = Storage::disk('local');
        $scan = $request->file('scan');
        $workspaceScanToClean = null;
        if ($scan instanceof UploadedFile) {
            $path = $scan->store('scans', 'local');
            $scanMime = $scan->getMimeType();
        } else {
            $workspaceScanPath = (string) ($validated['workspace_scan_path'] ?? '');
            if (
                !str_starts_with($workspaceScanPath, 'documents/workspace/')
                || str_contains($workspaceScanPath, '..')
                || !$disk->exists($workspaceScanPath)
            ) {
                throw ValidationException::withMessages([
                    'scan' => 'Choose a valid scanned document to submit.',
                ]);
            }
            $ext = strtolower(pathinfo($workspaceScanPath, PATHINFO_EXTENSION)) ?: 'bin';
            $path = 'scans/' . bin2hex(random_bytes(20)) . '.' . $ext;
            $disk->copy($workspaceScanPath, $path);
            $scanMime = $disk->mimeType($path) ?: 'application/octet-stream';
            $workspaceScanToClean = $workspaceScanPath;
        }

        $copiedFiles = [];
        $gridChanges = $this->gridChanges($template, $page);
        // Step 4's "Columns → fields" choices, kept on the layout(s) with the record.
        $fieldMaps = $this->fieldMapsToKeep($request, $documentType->key, array_values(array_unique(array_filter([
            $template?->getKey(),
            $template?->page_two_template_id,
            $pageTwoTemplate?->getKey(),
        ]))));

        try {
            $record = DB::transaction(function () use ($request, $scan, $scanMime, $validated, $path, $documentType, $settingsByName, $page, $linesById, $missingRequired, $gridChanges, $source, $extraPages, $pageTwoTemplate, $registryRows, $fieldMaps, &$copiedFiles) {

                $record = CivilRecord::create([
                    'doc_type' => $documentType->legacyType()->value,
                    'document_type_id' => $documentType->getKey(),
                    'document_template_id' => $validated['document_template_id'],
                    'status' => RecordStatus::Submitted,
                    'scan_path' => $path,
                    'scan_mime' => $scanMime,
                    // Field outlines are fractions of the page as outlined,
                    // which Detect may have straightened.
                    'scan_rotation' => $page?->deskew_degrees,
                    'source_name' => $source['name'],
                    'source_page' => $source['page'],
                    'source_page_count' => $source['page_count'],
                    'source_hash' => $source['hash'],
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

                // Pages 2 and on of a spread: each keeps its own scan and page
                // image, and its fields carry the page they were read from.
                $nextIndex = count($validated['fields']);
                $extraSettings = $pageTwoTemplate->settingsByName();
                foreach ($extraPages as $extra) {
                    $number = $extra['number'];
                    if ($extra['upload'] instanceof UploadedFile) {
                        $extraPath = $extra['upload']->store('scans', 'local');
                    } else {
                        $extraPath = 'scans/' . bin2hex(random_bytes(20)) . '.' . $extra['ext'];
                        Storage::disk('local')->copy($extra['scan_path'], $extraPath);
                    }
                    $copiedFiles[] = $extraPath;

                    $record->extraPages()->create([
                        'page_no' => $number,
                        'scan_path' => $extraPath,
                        'scan_mime' => ($extra['upload'] instanceof UploadedFile ? $extra['upload']->getMimeType() : null)
                            ?: Storage::disk('local')->mimeType($extraPath) ?: 'application/octet-stream',
                        'scan_rotation' => $extra['page']->deskew_degrees,
                        'page_image_path' => $this->keepPageImage($record, $extra['page'], $copiedFiles, "page-{$number}.png"),
                        'source_page' => $extra['source_page'],
                    ]);

                    foreach ($extra['fields'] as $field) {
                        $line = isset($field['line_id']) ? $extra['lines']->get((int) $field['line_id']) : null;
                        $settings = $extraSettings[$this->templateNameKey($field, $line)] ?? null;

                        $record->fields()->create([
                            'page_no' => $number,
                            'name' => $field['name'],
                            'ocr_text' => $field['ocr_text'] ?? null,
                            'ocr_confidence' => $field['ocr_confidence'] ?? null,
                            'verified_value' => $field['verified_value'],
                            'is_required' => $settings['required'] ?? true,
                            'role' => $settings['role'] ?? null,
                            'person_group' => $field['person_group'] ?? null,
                            'person_field_order' => $field['person_field_order'] ?? null,
                            'x' => $field['x'],
                            'y' => $field['y'],
                            'width' => $field['width'],
                            'height' => $field['height'],
                            'sort_order' => $nextIndex,
                            ...($line ? $this->lineAttributes($record, $extra['page'], $line, $nextIndex, $copiedFiles) : []),
                        ]);
                        $nextIndex++;
                    }
                }

                $record->load('fields');

                // Step 4's rows, each one row of the register table (birth_records,
                // death_records or marriage_records). Without them (saved from
                // Verify), the book/page reference is only linked as before.
                if ($registryRows !== null) {
                    $this->saveRegistryRows($record, $documentType->key, $registryRows);
                } elseif (isset($validated['book_no'], $validated['page_no'])) {
                    $this->saveToTypedTable($record, $validated['book_no'], $validated['page_no']);
                }
                $this->keepFieldMaps($fieldMaps);

                $corrected = $record->fields->filter->wasCorrected()->count();

                $this->audit->log(
                    'record.submitted',
                    $record,
                    new: [
                        'document_type' => $documentType->key,
                        'book_no' => $validated['book_no'] ?? null,
                        'page_no' => $validated['page_no'] ?? null,
                        'field_count' => $record->fields->count(),
                        'corrected_fields' => $corrected,
                        'outlined_fields' => $record->fields->whereNotNull('polygon')->count(),
                        'ocr_model' => $validated['ocr_model_key'],
                        // Left out on purpose: the user confirmed with "Submit anyway".
                        ...($missingRequired !== [] ? ['missing_required_fields' => $missingRequired] : []),
                        // This page's grid against the template's: rows, and
                        // columns added or removed in Align.
                        ...($gridChanges !== [] ? ['grid_changes' => $gridChanges] : []),
                        ...($extraPages !== [] ? ['pages' => count($extraPages) + 1] : []),
                        ...($source['page'] !== null ? [
                            'source_page' => $source['page'],
                            'source_page_count' => $source['page_count'],
                        ] : []),
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
            $disk->deleteDirectory($page->directory());
            $page->delete();
        }
        foreach ($extraPages as $extra) {
            $disk->deleteDirectory($extra['page']->directory());
            $extra['page']->delete();
        }
        foreach (array_filter([$workspaceScanToClean, ...array_column($extraPages, 'scan_path')]) as $cleanPath) {
            $disk->delete($cleanPath);
            $batchFolder = dirname($cleanPath);
            if ($batchFolder !== 'documents/workspace' && $disk->exists($batchFolder) && $disk->files($batchFolder) === []) {
                $disk->deleteDirectory($batchFolder);
            }
        }

        $this->linkBatchPages($request, $validated, $record);

        $redirect = route('records.show', $record);
        $message = 'Record submitted and locked. Change it later with Correct record.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => $redirect,
                // The workspace marks this page saved and offers the next one.
                'record' => $this->sourcePagePayload($record),
            ], 201);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    /**
     * A record saved from a batch kept on the server: its batch page(s) are
     * marked saved and point at it, so a resumed batch lists it as saved.
     * Only the user's own batch; a batch that is gone is let be.
     *
     * @param  array<string, mixed>  $validated
     */
    private function linkBatchPages(Request $request, array $validated, CivilRecord $record): void
    {
        $keys = array_values(array_filter((array) ($validated['scan_batch_pages'] ?? []), 'is_string'));
        if (empty($validated['scan_batch_id']) || $keys === []) {
            return;
        }

        $batch = ScanBatch::query()
            ->whereKey((int) $validated['scan_batch_id'])
            ->where('user_id', $request->user()->getKey())
            ->first();

        $batch?->pages()->whereIn('client_key', $keys)->update([
            'record_id' => $record->getKey(),
            'status' => 'imported',
        ]);
    }

    /**
     * The file this scan came from, as the workspace sent it. An older
     * workspace sends none of it: the uploaded file's own name is kept then.
     *
     * @param  array<string, mixed>  $validated
     * @return array{name: ?string, page: ?int, page_count: ?int, hash: ?string}
     */
    private function sourceOf(Request $request, array $validated): array
    {
        $page = isset($validated['source_page']) ? (int) $validated['source_page'] : null;
        $pageCount = isset($validated['source_page_count']) ? (int) $validated['source_page_count'] : null;

        if ($page !== null && $pageCount !== null && $page > $pageCount) {
            throw ValidationException::withMessages([
                'source_page' => "This file has {$pageCount} pages, so there is no page {$page}.",
            ]);
        }

        $upload = $request->file('scan');
        $name = filled($validated['source_name'] ?? null)
            ? (string) $validated['source_name']
            : ($upload instanceof UploadedFile ? $upload->getClientOriginalName() : null);

        return [
            'name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'page' => $page,
            'page_count' => $pageCount ?? ($page !== null ? max($page, 1) : null),
            'hash' => $validated['source_hash'] ?? null,
        ];
    }

    /** @return array{page: ?int, recordId: int, title: string, url: string, submittedAt: ?string} */
    private function sourcePagePayload(CivilRecord $record): array
    {
        return [
            'page' => $record->source_page,
            'recordId' => (int) $record->getKey(),
            'title' => $record->title(),
            'url' => route('records.show', $record),
            'submittedAt' => $record->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * Identify a document by its book number and page number using the document's
     * database table (birth_records, death_records, marriage_records) and records table.
     */
    public function identifyReference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'book_no' => ['required', 'string', 'max:64'],
            'page_no' => ['required', 'string', 'max:64'],
        ]);

        $docType = $validated['doc_type'];
        $bookNo = trim($validated['book_no']);
        $pageNo = trim($validated['page_no']);

        $model = match ($docType) {
            'birth' => BirthRecord::class,
            'death' => DeathRecord::class,
            'marriage' => MarriageRecord::class,
            default => null,
        };

        $typedEntries = [];
        $tableName = '';
        if ($model !== null) {
            $tableName = (new $model)->getTable();
            $records = $model::where('book_no', $bookNo)->where('page_no', $pageNo)->get();
            foreach ($records as $r) {
                $label = match ($docType) {
                    'birth' => $r->name ?: "Reg #{$r->reg_no}",
                    'death' => $r->name ?: "Reg #{$r->reg_no}",
                    'marriage' => ($r->husband_name && $r->wife_name) ? "{$r->husband_name} & {$r->wife_name}" : "Reg #{$r->reg_no}",
                    default => "Reg #{$r->reg_no}",
                };
                $typedEntries[] = [
                    'id' => $r->getKey(),
                    'label' => $label,
                    'reg_no' => $r->reg_no ?? null,
                ];
            }
        }

        $savedRecord = null;
        if (isset($records) && Schema::hasColumn($tableName, 'civil_record_id')) {
            $linkedId = $records->pluck('civil_record_id')->filter()->first();
            if ($linkedId) {
                $savedRecord = CivilRecord::withTitleField()->find($linkedId);
            }
        }

        $savedPayload = $savedRecord ? [
            'id' => (int) $savedRecord->getKey(),
            'title' => $savedRecord->title(),
            'url' => route('records.show', $savedRecord),
            'submitted_at' => $savedRecord->submitted_at?->toIso8601String(),
        ] : null;

        $identified = count($typedEntries) > 0 || $savedPayload !== null;

        $message = '';
        if ($identified) {
            $parts = [];
            if (count($typedEntries) > 0) {
                $names = collect($typedEntries)->pluck('label')->take(3)->implode(', ');
                $more = count($typedEntries) > 3 ? ' and ' . (count($typedEntries) - 3) . ' more' : '';
                $parts[] = "Identified in {$tableName}: " . count($typedEntries) . " " . str('entry')->plural(count($typedEntries)) . " ({$names}{$more})";
            }
            if ($savedPayload !== null) {
                $parts[] = "Saved as Record #{$savedPayload['id']}";
            }
            $message = implode(' · ', $parts);
        } else {
            $message = "New document (Book {$bookNo}, Page {$pageNo} not yet in {$tableName})";
        }

        return response()->json([
            'identified' => $identified,
            'book_no' => $bookNo,
            'page_no' => $pageNo,
            'table' => $tableName,
            'count' => count($typedEntries),
            'entries' => $typedEntries,
            'saved_record' => $savedPayload,
            'message' => $message,
        ]);
    }

    /**
     * Distinct book numbers for a certificate type from the typed table
     * (birth_records, death_records, marriage_records) and records table.
     *
     * @return list<string>
     */
    private function existingBooksForType(string $typeKey): array
    {
        $model = match ($typeKey) {
            'birth' => BirthRecord::class,
            'death' => DeathRecord::class,
            'marriage' => MarriageRecord::class,
            default => null,
        };

        $typedBooks = $model !== null
            ? $model::query()->distinct()->whereNotNull('book_no')->where('book_no', '!=', '')->pluck('book_no')
            : collect();

        return $typedBooks
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * Link or record the reference in the typed registry table (birth_records,
     * death_records, marriage_records) matching the document type.
     */
    private function saveToTypedTable(CivilRecord $record, string $bookNo, string $pageNo): void
    {
        $type = $record->doc_type instanceof \BackedEnum ? $record->doc_type->value : (string) ($record->doc_type ?? '');
        $model = match ($type) {
            'birth' => BirthRecord::class,
            'death' => DeathRecord::class,
            'marriage' => MarriageRecord::class,
            default => null,
        };

        if ($model === null) {
            return;
        }

        if (Schema::hasColumn((new $model)->getTable(), 'civil_record_id')) {
            $model::where('book_no', $bookNo)
                ->where('page_no', $pageNo)
                ->whereNull('civil_record_id')
                ->update(['civil_record_id' => $record->getKey()]);
        }
    }

    /**
     * Step 4's rows (`registry_rows_json`: [{row: "06", values: {field: value}}])
     * for a type with a register table, checked field by field; null when none
     * were sent. A row that breaks a rule stops the save with "Row 06: …".
     *
     * @return list<array<string, mixed>>|null
     */
    private function registryRows(Request $request, string $type): ?array
    {
        if (! $request->filled('registry_rows_json') || RegistryFields::model($type) === null) {
            return null;
        }

        try {
            $rows = json_decode((string) $request->input('registry_rows_json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['registry_rows' => 'The table could not be read. Open Review & Export again and save.']);
        }
        return RegistryFields::checkedRows($type, $rows);
    }

    /**
     * A register page is saved once: a book and page that already have rows in
     * the type's register table stops the save, saying which record has them.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function refuseSavedPages(string $type, array $rows): void
    {
        $model = RegistryFields::model($type);
        $pages = collect($rows)
            ->map(fn (array $row) => ['book_no' => (string) $row['book_no'], 'page_no' => (string) $row['page_no']])
            ->unique(fn (array $page) => $page['book_no'] . '|' . $page['page_no']);

        foreach ($pages as $page) {
            $saved = $model::where('book_no', $page['book_no'])->where('page_no', $page['page_no'])->first();
            if ($saved === null) {
                continue;
            }
            $record = $saved->civil_record_id ? " (Record #{$saved->civil_record_id})" : '';
            throw ValidationException::withMessages([
                'page_no' => "Book {$page['book_no']}, page {$page['page_no']} is already saved{$record}. Enter another page number.",
            ]);
        }
    }

    /**
     * Each row into the type's register table, linked to the saved record. A
     * row whose book, page and register number are there already (the page
     * saved again) is replaced, so a register entry is never there twice.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveRegistryRows(CivilRecord $record, string $type, array $rows): void
    {
        $model = RegistryFields::model($type);
        foreach ($rows as $attributes) {
            $model::updateOrCreate(
                [
                    'book_no' => $attributes['book_no'],
                    'page_no' => $attributes['page_no'],
                    'reg_no' => $attributes['reg_no'],
                ],
                [...$attributes, 'civil_record_id' => $record->getKey()],
            );
        }
    }

    /**
     * Step 4's "Columns → fields" choices to keep with the layout(s) this
     * record was read with (`field_maps_json`: { template id: { "column name":
     * field or "-" } }), only those that differ from what the layout has.
     * Kept when the record is saved, so a document left unsaved changes nothing.
     *
     * @param  list<int>  $templateIds  the layouts the record was read with
     * @return array<int, array<string, string>> template id => map
     */
    private function fieldMapsToKeep(Request $request, string $type, array $templateIds): array
    {
        if (! $request->filled('field_maps_json') || RegistryFields::model($type) === null) {
            return [];
        }
        try {
            $maps = json_decode((string) $request->input('field_maps_json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $maps = null;
        }
        if (! is_array($maps) || count($maps) > 4) {
            throw ValidationException::withMessages(['field_maps' => 'The column choices could not be read. Open Columns → fields again and save.']);
        }

        $keep = [];
        foreach ($maps as $templateId => $map) {
            if (! in_array((int) $templateId, $templateIds, true)) {
                throw ValidationException::withMessages(['field_maps' => 'The column choices are for another layout. Reload the page.']);
            }
            $clean = RegistryFields::cleanFieldMap($type, $map);
            $template = DocumentTemplate::find((int) $templateId);
            if ($template !== null && ($template->field_map ?? []) != $clean) {
                $keep[$template->getKey()] = $clean;
            }
        }

        return $keep;
    }

    /**
     * Step 4's "Columns → fields" choices, kept on the layout(s) as soon as they
     * are made: the next page (and a resumed batch) starts with them, without
     * waiting for a record to be saved. Only layouts of this document type.
     */
    public function saveFieldMaps(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'field_maps_json' => ['required', 'string', 'max:20000'],
        ]);
        $documentType = DocumentTypeDefinition::where('key', $validated['doc_type'])->firstOrFail();
        $templates = DocumentTemplate::query()
            ->where('document_type_id', $documentType->getKey())
            ->get(['id', 'page_two_template_id']);
        $allowed = $templates->pluck('id')
            ->merge($templates->pluck('page_two_template_id')->filter())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $maps = $this->fieldMapsToKeep($request, $documentType->key, $allowed);
        $this->keepFieldMaps($maps);

        return response()->json(['kept' => array_keys($maps)]);
    }

    /**
     * Keep the choices on their layouts (the next document read with them
     * starts so), each change in the audit log.
     *
     * @param  array<int, array<string, string>>  $maps
     */
    private function keepFieldMaps(array $maps): void
    {
        foreach ($maps as $templateId => $map) {
            $template = DocumentTemplate::find($templateId);
            if ($template === null) {
                continue;
            }
            $old = $template->field_map ?? [];
            $template->forceFill(['field_map' => $map])->save();
            $this->audit->log(
                'template.field_map_changed',
                $template,
                old: ['field_map' => $old],
                new: ['field_map' => $map],
                description: "Column choices (Columns → fields) changed for layout \"{$template->name}\" in Review & Export.",
            );
        }
    }

    private function hydrateJsonFields(Request $request): void
    {
        if (!$request->filled('fields_json')) {
            return;
        }

        try {
            $fields = json_decode((string) $request->input('fields_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'fields' => 'The verified fields could not be read. Return to validation and try again.',
            ]);
        }

        if (!is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => 'The verified fields must be a valid list.',
            ]);
        }

        $request->merge(['fields' => $fields]);
    }

    /** A spread saved as one record: the pages after the first, sent as JSON beside fields_json. */
    private function hydrateExtraPages(Request $request): void
    {
        if (!$request->filled('extra_pages_json')) {
            return;
        }

        try {
            $pages = json_decode((string) $request->input('extra_pages_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'extra_pages' => 'The other pages could not be read. Return to validation and try again.',
            ]);
        }

        if (!is_array($pages)) {
            throw ValidationException::withMessages(['extra_pages' => 'The other pages must be a valid list.']);
        }

        $request->merge(['extra_pages' => $pages]);
    }

    /**
     * The pages after the first, checked like the first: their coordinates, the
     * processed page and lines each field points at, and a scan file the
     * workspace saved. Pages are numbered 2, 3, ... in the order sent.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array{number: int, page: DocumentPage, lines: Collection<int, PageLine>, fields: array<int, array<string, mixed>>, scan_path: string, ext: string, source_page: ?int}>
     */
    private function prepareExtraPages(Request $request, array $validated, int $templateId): array
    {
        $disk = Storage::disk('local');
        $prepared = [];
        $errors = [];

        foreach (array_values($validated['extra_pages'] ?? []) as $position => $extra) {
            foreach (array_values($extra['fields']) as $index => $field) {
                if ((float) $field['x'] + (float) $field['width'] > 1.00001) {
                    $errors["extra_pages.{$position}.fields.{$index}.width"] = 'This field marker extends beyond the document width.';
                }
                if ((float) $field['y'] + (float) $field['height'] > 1.00001) {
                    $errors["extra_pages.{$position}.fields.{$index}.height"] = 'This field marker extends beyond the document height.';
                }
            }

            // The page's own file, sent with the record (one PDF's pages, cut out
            // in the browser), or one the workspace queued for it (a batch).
            $upload = $request->file("extra_scans.{$position}");
            $upload = $upload instanceof UploadedFile ? $upload : null;
            $scanPath = $upload === null ? (string) ($extra['workspace_scan_path'] ?? '') : null;
            if (
                $upload === null && (
                    !str_starts_with($scanPath, 'documents/workspace/')
                    || str_contains($scanPath, '..')
                    || !$disk->exists($scanPath)
                )
            ) {
                throw ValidationException::withMessages([
                    "extra_pages.{$position}.workspace_scan_path" => 'Choose a valid scanned document to submit.',
                ]);
            }

            [$page, $lines] = $this->pageLinesFor($request, [
                'fields' => $extra['fields'],
                'document_page_id' => $extra['document_page_id'],
            ], $templateId);

            if ($page === null) {
                throw ValidationException::withMessages([
                    "extra_pages.{$position}.document_page_id" => 'The outlined page these fields came from is missing. Scan the page again.',
                ]);
            }

            $prepared[] = [
                'number' => $position + 2,
                'page' => $page,
                'lines' => $lines,
                'fields' => array_values($extra['fields']),
                // Null for a file sent with the record: nothing queued to clean up.
                'scan_path' => $scanPath,
                'upload' => $upload,
                'ext' => $scanPath !== null ? (strtolower(pathinfo($scanPath, PATHINFO_EXTENSION)) ?: 'bin') : 'bin',
                'source_page' => isset($extra['source_page']) ? (int) $extra['source_page'] : null,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $prepared;
    }

    /**
     * @param  list<string>  $messages
     * @return list<string>
     */
    private function prefixed(string $prefix, array $messages): array
    {
        return array_map(fn(string $message) => $prefix . $message, $messages);
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
        $lineIds = collect($validated['fields'])->pluck('line_id')->filter()->map(fn($id) => (int) $id);

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

        if (
            $page === null || $page->status !== DocumentPage::STATUS_READY
            || (int) $page->document_template_id !== $templateId
        ) {
            throw ValidationException::withMessages([
                'document_page_id' => 'This page is no longer available. Scan it again before submitting.',
            ]);
        }

        $lines = $page->lines()->whereIn('id', $lineIds)->get()->keyBy('id');

        $errors = [];
        foreach ($validated['fields'] as $index => $field) {
            if (isset($field['line_id']) && !$lines->has((int) $field['line_id'])) {
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
            ->map(fn(array $field) => $this->templateNameKey(
                $field,
                isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null,
            ))
            ->flip();

        return $template->fields
            ->filter(fn($field) => $field->is_required && !$submitted->has(mb_strtolower(trim($field->name))))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The name a submitted field's template settings are found under: its
     * line's field or column, else its own name (see settingsByName()). A
     * cell typed into in Review & Export, where nothing was read, has no line:
     * its name is "Column · row 5", so its column is the part before the row.
     *
     * @param  array<string, mixed>  $field
     */
    private function templateNameKey(array $field, ?PageLine $line): string
    {
        if ($line !== null) {
            return mb_strtolower(trim((string) $line->column_name));
        }

        return mb_strtolower(trim($this->typedCell($field)['column'] ?? $field['name']));
    }

    /**
     * A field with no line named "Column · row 5" (or "· row 5 · page 2"): a
     * register cell typed into in Review & Export. Its column and table row,
     * or null for any other field.
     *
     * @param  array<string, mixed>  $field
     * @return array{column: string, row: int}|null
     */
    private function typedCell(array $field): ?array
    {
        if (isset($field['line_id'])) {
            return null;
        }
        if (!preg_match('/^(.+?) · row (\d+)(?: · page \d+)?$/u', (string) ($field['name'] ?? ''), $match)) {
            return null;
        }

        return ['column' => trim($match[1]), 'row' => (int) $match[2]];
    }

    /**
     * In a register, the required columns missing from each row that has
     * something submitted ("Person 01: Date of Birth"), then the rows with
     * nothing submitted at all. The page is removed once the record is saved,
     * so those rows would have to be scanned again.
     *
     * Only the columns on this page's grid count. A column removed in
     * Align is not on the page, so it is not asked for in every row (the
     * removal is in the audit log instead), and a column they added has
     * cells like any other.
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
        if ($page === null || !$template->isLedger()) {
            return [];
        }

        $columns = [];
        foreach ($this->pageColumnNames($template, $page) as $name) {
            $columns[mb_strtolower($name)] = true;
        }
        $required = [];
        foreach ($template->columns as $column) {
            $key = mb_strtolower(trim((string) $column['name']));
            if (isset($columns[$key]) && DocumentTemplate::columnSettings($column)['required']) {
                $required[$key] = (string) $column['name'];
            }
        }

        // A cell of a ledger row: what Verify groups into a person.
        $isCell = fn(PageLine $line) => !$line->belongsToField()
            && $line->row !== null && $line->column_index !== null
            && !in_array(PageLine::FLAG_NO_ROW, $line->flags ?? [], true);
        $columnOf = fn(PageLine $line) => mb_strtolower(trim((string) $line->column_name));

        // A person the reviewer started by hand sits past the table's last row;
        // there is no register row to complete for them.
        $tableRows = max(0, count($page->geometry['ruled_ys'] ?? []) - 1);
        $shortened = $page->shortenedColumnRows();

        $rows = $page->lines
            ->filter(fn(PageLine $line) => $isCell($line) && isset($columns[$columnOf($line)]) && $line->row <= $tableRows)
            ->pluck('row')
            ->unique()
            ->sort()
            ->values();

        $submitted = [];
        foreach ($fields as $field) {
            $line = isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null;
            if ($line !== null && $isCell($line)) {
                $submitted[$line->row][$columnOf($line)] = true;
            } elseif ($line === null && ($typed = $this->typedCell($field)) !== null) {
                $submitted[$typed['row']][mb_strtolower($typed['column'])] = true;
            }
        }

        $missing = [];
        $untouched = [];
        foreach ($rows as $row) {
            if (!isset($submitted[$row])) {
                $untouched[] = $row;

                continue;
            }

            // A column shortened in Align is not asked for in the rows it leaves out.
            $askedHere = array_filter($required, fn(string $key) => !isset($shortened[$key])
                || in_array($row, $shortened[$key], true), ARRAY_FILTER_USE_KEY);
            $left = array_values(array_diff_key($askedHere, $submitted[$row]));
            if ($left !== []) {
                $missing[] = $this->personLabel($row) . ': ' . implode(', ', $left);
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

    /**
     * The ledger columns on this page's grid, as they were left in Align (and
     * Detect fitted them). A page without a grid of its own has the template's.
     *
     * @return list<string>
     */
    private function pageColumnNames(DocumentTemplate $template, DocumentPage $page): array
    {
        $columns = $page->geometry['columns'] ?? null;
        if (!is_array($columns) || $columns === []) {
            $columns = $template->columns ?? [];
        }

        return collect($columns)
            ->map(fn($column) => trim((string) ($column['name'] ?? '')))
            ->filter(fn(string $name) => $name !== '')
            ->values()
            ->all();
    }

    /**
     * How this page's ledger grid differs from its template's, for the audit
     * log: its rows, and the columns added or removed in Align. Empty
     * when they match. The template itself is never changed.
     *
     * @return array{rows?: int, template_rows?: int, columns_added?: list<string>, columns_removed?: list<string>}
     */
    private function gridChanges(DocumentTemplate $template, ?DocumentPage $page): array
    {
        if ($page === null || !$template->isLedger()) {
            return [];
        }

        $pageColumns = $this->pageColumnNames($template, $page);
        $templateColumns = collect($template->columns ?? [])
            ->map(fn($column) => trim((string) ($column['name'] ?? '')))
            ->filter(fn(string $name) => $name !== '')
            ->values()
            ->all();
        $keys = fn(array $names) => array_map('mb_strtolower', $names);
        $added = array_values(array_filter($pageColumns, fn($name) => !in_array(mb_strtolower($name), $keys($templateColumns), true)));
        $removed = array_values(array_filter($templateColumns, fn($name) => !in_array(mb_strtolower($name), $keys($pageColumns), true)));

        // A page without row lines of its own says nothing about its rows.
        $pageLines = count($page->geometry['ruled_ys'] ?? []);
        $rows = max(0, $pageLines - 1);
        $templateRows = max(0, count($template->ruled_ys ?? []) - 1);

        return [
            ...($pageLines >= 2 && $rows !== $templateRows ? ['rows' => $rows, 'template_rows' => $templateRows] : []),
            ...($added !== [] ? ['columns_added' => $added] : []),
            ...($removed !== [] ? ['columns_removed' => $removed] : []),
        ];
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
            fn(array $run) => $run[0] === $run[1]
                ? $this->personLabel($run[0])
                : $this->personLabel($run[0]) . ' – ' . $this->personLabel($run[1]),
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
    private function keepPageImage(CivilRecord $record, DocumentPage $page, array &$copiedFiles, string $name = 'page.png'): ?string
    {
        $disk = Storage::disk('local');
        $path = sprintf('records/%d/%s', $record->getKey(), $name);

        if (!$disk->exists($page->image_path) || !$disk->copy($page->image_path, $path)) {
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
                fn(array $point) => [round($point[0] / $width, 5), round($point[1] / $height, 5)],
                $line->polygon,
            ),
            'line_column' => $line->column_name,
            'line_row' => $line->row,
            'line_flags' => $line->flags ?: [],
        ];
    }
}
