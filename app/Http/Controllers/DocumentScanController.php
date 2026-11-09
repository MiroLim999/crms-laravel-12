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
use App\Support\Limits;
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
    ) {
    }

    /** Session key: the type last opened in the workspace. */
    private const LAST_TYPE = 'scan.last_type';

    /** More pages than any register book has: a typed page number past it is refused. */
    private const MAX_SOURCE_PAGES = 10000;

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
        $active = DocumentTemplate::query()
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

        if ($type === null || $type->isArchived()) {
            return redirect()->route('documents.create');
        }

        $template = DocumentTemplate::activeFor($type);

        if ($template === null) {
            return redirect()->route('documents.create')->with(
                'error',
                "No active template for {$type->label()}. A Super Admin must publish one first.",
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
            // Rectangle fields, then ledger columns. Staff align both the same way.
            'boxes' => $template->markerBoxes(),
            'ruledYs' => $template->isLedger() ? array_values($template->ruled_ys) : [],
            // A spread's second page: its own markers and row lines. Null for one page.
            'pageTwo' => $template->hasSecondPage() ? [
                'boxes' => $template->forPage(2)->markerBoxes(),
                'ruledYs' => $template->forPage(2)->isLedger() ? array_values($template->forPage(2)->ruled_ys) : [],
            ] : null,
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
            'ocr_model_key' => [
                'required',
                'string',
                'max:255',
                Rule::exists(OcrModel::class, 'key')->whereNull('disk_deleted_at'),
            ],
            'scan' => ['required_without:workspace_scan_path', 'file', 'mimes:pdf,png,jpg,jpeg,webp,bmp', 'max:20480'],
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
            // A spread (a layout with a second page): the second page's outlined
            // page, and which page each field was read from.
            'document_page_id_two' => ['nullable', 'integer'],
            'fields.*.page' => ['nullable', 'integer', 'in:1,2'],
            'fields.*.line_id' => ['nullable', 'integer', 'distinct'],
            // Staff chose "Submit anyway" when asked about missing required fields.
            'allow_missing' => ['sometimes', 'boolean'],
            // The file this scan is a page of (the workspace sends the page alone,
            // cut out of the file): its name, the page, and the file's SHA-256.
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_SOURCE_PAGES, 'required_with:source_page_count,source_hash'],
            'source_page_count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_SOURCE_PAGES],
            'source_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // Staff chose "Save again" when told this page is already saved.
            'allow_duplicate' => ['sometimes', 'boolean'],
        ], [
            'scan.mimes' => 'The scan must be a PDF, PNG, JPG, WEBP or BMP file.',
            'source_hash.regex' => 'The file fingerprint is not valid. Open the file again.',
        ]);

        $source = $this->sourceOf($request, $validated);

        $templateId = (int) $validated['document_template_id'];
        $documentType = DocumentTypeDefinition::where('key', (string) $validated['doc_type'])->firstOrFail();
        $template = DocumentTemplate::with(['documentTypeDefinition', 'fields'])->find($templateId);

        // Archiving only stops new scans from starting (create/workspace). A scan
        // already verified by Staff can still be submitted, so no work is lost.
        if ($template?->document_type_id !== $documentType->getKey()) {
            throw ValidationException::withMessages([
                'document_template_id' => 'The selected template does not belong to this document type.',
            ]);
        }

        // What each field holds and whether it is required, by its template
        // field's name (a ledger line: its column's). A field Staff added
        // themselves has no template settings.
        $settingsByName = $template->settingsByName();

        // A PDF of two pages is one record: row N of page 2 carries on row N of
        // page 1. Page 2 is read with the layout's own second page, or, for a
        // layout of one page, with the first page's (as Staff adjusted it).
        $onSecondPage = fn (array $field) => (int) ($field['page'] ?? 1) === 2;
        $fieldsOne = array_filter($validated['fields'], fn (array $field) => !$onSecondPage($field));
        $fieldsTwo = array_filter($validated['fields'], $onSecondPage);
        $secondPage = $fieldsTwo !== [] || !empty($validated['document_page_id_two']);
        $layoutTwo = $template->forPage(2);

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

        // This page of this exact file is saved already: say so first. Staff may
        // save it again ("Save again"), which the audit log notes.
        $savedBefore = $this->savedSourcePage($source);
        if ($savedBefore !== null && !$request->boolean('allow_duplicate')) {
            $message = ($source['page_count'] ?? 1) > 1
                ? "Page {$source['page']} of this file is already saved as record #{$savedBefore->getKey()}."
                : "This file is already saved as record #{$savedBefore->getKey()}.";

            if ($request->expectsJson()) {
                return response()->json([
                    'duplicate_page' => true,
                    'message' => $message,
                    'record' => $this->sourcePagePayload($savedBefore),
                ], 409);
            }

            throw ValidationException::withMessages(['scan' => $message]);
        }

        [$page, $linesOne] = $this->pageLinesFor($request, [...$validated, 'fields' => $fieldsOne], $templateId);
        [$pageTwo, $linesTwo] = $secondPage
            ? $this->pageLinesFor($request, [
                ...$validated,
                'fields' => $fieldsTwo,
                'document_page_id' => $validated['document_page_id_two'] ?? null,
            ], $templateId)
            : [null, collect()];
        $linesById = $linesOne->union($linesTwo);

        // A crop deleted in Verify must be drawn again and read first.
        $toRedraw = collect([$page, $pageTwo])->filter()
            ->sum(fn (DocumentPage $each) => $each->lines
                ->filter(fn (PageLine $line) => in_array(PageLine::FLAG_REDRAW, $line->flags ?? [], true))
                ->count());
        if ($toRedraw > 0) {
            throw ValidationException::withMessages([
                'fields' => $toRedraw === 1
                    ? 'One field has a deleted crop. Draw it again before submitting.'
                    : "{$toRedraw} fields have a deleted crop. Draw them again before submitting.",
            ]);
        }

        // Some certificates do leave a required field blank, so a missing one
        // is not refused outright: Staff must confirm it first.
        $missingRequired = [
            ...$this->missingRequiredFields($template, $fieldsOne, $linesOne),
            ...$this->missingRegisterRows($template, $page, $fieldsOne, $linesOne),
            ...($secondPage ? array_map(
                fn (string $message) => 'Page 2 · '.$message,
                [
                    // The first page's own fields (a title, a page number) are not asked of
                    // page 2 when it is read with the first page's layout.
                    ...($template->hasSecondPage() ? $this->missingRequiredFields($layoutTwo, $fieldsTwo, $linesTwo) : []),
                    ...$this->missingRegisterRows($layoutTwo, $pageTwo, $fieldsTwo, $linesTwo),
                ],
            ) : []),
        ];
        if ($missingRequired !== [] && !$request->boolean('allow_missing')) {
            // One message per field, so the workspace can list them when it asks.
            throw ValidationException::withMessages(['missing_required' => $missingRequired]);
        }

        // Flowchart step: "Is it existing? -> Yes -> Data Extracted Rejected"
        $existingRecord = $this->findExistingRecord($templateId, $validated['fields']);
        if ($existingRecord !== null) {
            $rejectMessage = "Data Extracted Rejected: This document record ({$existingRecord->title()}) already exists in the database.";
            if ($request->expectsJson()) {
                return response()->json([
                    'existing_record' => true,
                    'message' => $rejectMessage,
                    'record_id' => $existingRecord->getKey(),
                    'record_title' => $existingRecord->title(),
                    'record_url' => route('records.show', $existingRecord),
                ], 409);
            }

            throw ValidationException::withMessages(['scan' => $rejectMessage]);
        }

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
        $gridChangesTwo = $secondPage ? $this->gridChanges($layoutTwo, $pageTwo) : [];

        try {
            $record = DB::transaction(function () use ($request, $scan, $scanMime, $validated, $path, $documentType, $settingsByName, $page, $pageTwo, $linesById, $missingRequired, $gridChanges, $gridChangesTwo, $source, $savedBefore, &$copiedFiles) {

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
                    'scan_rotation_two' => $pageTwo?->deskew_degrees,
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
                if ($pageTwo !== null) {
                    $record->update(['page_image_path_two' => $this->keepPageImage($record, $pageTwo, $copiedFiles, 'page-two.png')]);
                }

                foreach (array_values($validated['fields']) as $index => $field) {
                    $line = isset($field['line_id']) ? $linesById->get((int) $field['line_id']) : null;
                    $settings = $settingsByName[$this->templateNameKey($field, $line)] ?? null;
                    $fieldPage = (int) ($field['page'] ?? 1) === 2 ? $pageTwo : $page;

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
                        'page' => (int) ($field['page'] ?? 1) === 2 ? 2 : 1,
                        ...($line && $fieldPage ? $this->lineAttributes($record, $fieldPage, $line, $index, $copiedFiles) : []),
                    ]);
                }

                $record->load('fields');

                $corrected = $record->fields->filter->wasCorrected()->count();

                $this->audit->log(
                    'record.submitted',
                    $record,
                    new: [
                        'document_type' => $documentType->key,
                        'field_count' => $record->fields->count(),
                        'corrected_fields' => $corrected,
                        'outlined_fields' => $record->fields->whereNotNull('polygon')->count(),
                        'ocr_model' => $validated['ocr_model_key'],
                        // Left out on purpose: Staff confirmed with "Submit anyway".
                        ...($missingRequired !== [] ? ['missing_required_fields' => $missingRequired] : []),
                        // This page's grid against the template's: rows, and
                        // columns Staff added or removed in Align.
                        ...($gridChanges !== [] ? ['grid_changes' => $gridChanges] : []),
                        ...($gridChangesTwo !== [] ? ['grid_changes_page_two' => $gridChangesTwo] : []),
                        ...($pageTwo !== null ? ['pages' => 2] : []),
                        ...($source['page'] !== null ? [
                            'source_page' => $source['page'],
                            'source_page_count' => $source['page_count'],
                        ] : []),
                        // Saved although this page was saved before ("Save again").
                        ...($savedBefore !== null ? ['saved_again_after_record' => $savedBefore->getKey()] : []),
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
        foreach ([$page, $pageTwo] as $working) {
            if ($working !== null) {
                $disk->deleteDirectory($working->directory());
                $working->delete();
            }
        }
        if ($workspaceScanToClean !== null) {
            $disk->delete($workspaceScanToClean);
            $batchFolder = dirname($workspaceScanToClean);
            if ($batchFolder !== 'documents/workspace' && $disk->files($batchFolder) === []) {
                $disk->deleteDirectory($batchFolder);
            }
        }

        $redirect = route('records.show', $record);
        $message = 'Record submitted and locked. Further changes need a change request.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => $redirect,
                // The workspace marks this page saved and offers the next one.
                'record' => $this->sourcePagePayload($record),
                // What the "Saved" summary shows.
                'summary' => [
                    'title' => $record->title(),
                    'fields' => $record->fields->count(),
                    'corrected' => $record->fields->filter->wasCorrected()->count(),
                ],
            ], 201);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    /**
     * The pages of one scanned file that are already saved, by the file's
     * SHA-256: the workspace ticks them, opens the file at the first page not
     * saved yet, and warns on a page saved before. The newest record per page.
     */
    public function sourcePages(string $hash): JsonResponse
    {
        $records = CivilRecord::withTitleField()
            ->where('source_hash', $hash)
            ->whereNotNull('source_page')
            ->orderBy('source_page')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'pages' => $records->unique('source_page')
                ->map(fn (CivilRecord $record) => $this->sourcePagePayload($record))
                ->values(),
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

    /**
     * @param  array{name: ?string, page: ?int, page_count: ?int, hash: ?string}  $source
     */
    private function savedSourcePage(array $source): ?CivilRecord
    {
        if ($source['hash'] === null || $source['page'] === null) {
            return null;
        }

        return CivilRecord::withTitleField()
            ->where('source_hash', $source['hash'])
            ->where('source_page', $source['page'])
            ->latest('id')
            ->first();
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
     * Only the columns on this page's grid count. A column Staff removed in
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
            }
        }

        $missing = [];
        $untouched = [];
        foreach ($rows as $row) {
            if (!isset($submitted[$row])) {
                $untouched[] = $row;

                continue;
            }

            // A column Staff shortened in Align is not asked for in the rows it leaves out.
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
     * The ledger columns on this page's grid, as Staff left them in Align (and
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
     * log: its rows, and the columns Staff added or removed in Align. Empty
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

    /**
     * @return list<array{key: string, label: string, is_active: bool}>
     */
    private function selectableModels(): array
    {
        return $this->modelChoice->selectable();
    }

    /**
     * Check whether a CivilRecord for the same template already has identical
     * verified field values ("Is it existing? -> Data Extracted Rejected").
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function findExistingRecord(int $templateId, array $fields): ?CivilRecord
    {
        $submitted = collect($fields)
            ->mapWithKeys(fn(array $f) => [
                mb_strtolower(trim((string) ($f['name'] ?? ''))) . ':' . ((int) ($f['person_group'] ?? 0)) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($f['verified_value'] ?? '')))),
            ])
            ->filter(fn(string $v) => $v !== '');

        if ($submitted->isEmpty()) {
            return null;
        }

        $candidates = CivilRecord::with('fields')
            ->where('document_template_id', $templateId)
            ->latest('id')
            ->limit(150)
            ->get();

        foreach ($candidates as $record) {
            $existing = $record->fields
                ->mapWithKeys(fn($f) => [
                    mb_strtolower(trim((string) $f->name)) . ':' . ((int) ($f->person_group ?? 0)) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $f->verified_value))),
                ])
                ->filter(fn(string $v) => $v !== '');

            if ($existing->count() !== $submitted->count()) {
                continue;
            }

            $allMatch = $submitted->every(fn(string $val, string $key) => ($existing->get($key) ?? null) === $val);
            if ($allMatch) {
                return $record;
            }
        }

        return null;
    }
}
