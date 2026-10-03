<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Models\DocumentTypeDefinition;
use App\Models\PageLine;
use App\Services\AuditLogger;
use App\Services\Lines\GeometryInput;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use App\Services\TemplateSampleStorage;
use App\Support\Limits;
use App\Support\MarkerBounds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Document template builder - Super Admin only.
 *
 * Templates decide which fields Staff capture per certificate type and where the
 * boxes start. Coordinates are fractions of the page so a layout works at any
 * scan resolution.
 *
 * A layout in use (published, or read by records or pages in progress) is never
 * changed in place: saving new markers or field settings makes a new version,
 * so every record keeps the layout it was read with. Its name, notes and
 * sample are only a description and are saved in place.
 */
class DocumentTemplateController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TemplateSampleStorage $samples,
    ) {}

    public function index(): View
    {
        return view('templates.index', [
            'templates' => DocumentTemplate::withCount(['fields', 'records', 'pages'])
                ->with(['creator', 'documentTypeDefinition', 'parent:id,name'])
                ->orderBy('document_type_id')
                ->orderByDesc('is_active')
                ->get()
                ->groupBy('document_type_id'),
            'documentTypes' => DocumentTypeDefinition::ordered(),
        ]);
    }

    public function create(Request $request): View
    {
        $requestedKey = $request->query('type', DocumentType::Birth->value);
        // An edited URL can send ?type[]=x, an array. Treat it as an unknown type.
        abort_unless(is_string($requestedKey), 404);
        $type = DocumentTypeDefinition::where('key', $requestedKey)->firstOrFail();

        return view('templates.edit', [
            'template' => null,
            'docType' => $type,
            // Start from the prototype's field boxes rather than a blank page.
            'fields' => $type->defaultFields(),
            'columns' => [],
            'ruledYs' => [],
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
        ]);
    }

    /**
     * Find the printed rules on a sample page and suggest a ledger grid.
     *
     * The builder posts the sample exactly as it renders it; the image is
     * processed locally and deleted straight away. Every printed rule found
     * comes back too, so markers and row lines snap to them in the builder.
     */
    public function detectGrid(Request $request, LineMarkers $markers): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:png', 'max:40960'],
        ]);

        $path = $request->file('image')->store('template-grid', 'local');

        try {
            $grid = $markers->grid(Storage::disk('local')->path($path));
        } catch (LineMarkersException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            Storage::disk('local')->delete($path);
        }

        return response()->json([
            'ruled_ys' => $grid['suggestion']['ruled_ys'] ?? [],
            'columns' => $grid['suggestion']['columns'] ?? [],
            'estimated_ys' => $grid['suggestion']['estimated_ys'] ?? 0,
            'lines' => [
                'horizontal' => $grid['horizontal'] ?? [],
                'vertical' => $grid['vertical'] ?? [],
            ],
        ]);
    }

    /**
     * Test the layout being built on its sample, saved or not: the page is
     * straightened, the markers fitted to its table and every line outlined,
     * exactly as Detect does it for Staff. Nothing is read or kept; the
     * builder shows where each line would be cut and which row it lands in.
     */
    public function testLayout(Request $request, LineMarkers $markers): JsonResponse
    {
        GeometryInput::hydrate($request);
        $validated = $request->validate([
            'page' => ['required', 'file', 'mimes:png', 'max:40960'],
            ...GeometryInput::rules(),
        ]);
        $geometry = GeometryInput::checked($validated['geometry']);

        // Line detection takes about half a minute a page.
        @set_time_limit(600);
        $disk = Storage::disk('local');
        $directory = 'template-tests/'.Str::uuid();
        $stored = $request->file('page')->storeAs($directory, 'page.png', 'local');

        try {
            $result = $markers->detect($disk->path($stored), $geometry, $disk->path($directory));
            // A straightened page no longer matches the builder's own picture of it.
            $straightened = abs((float) ($result['deskew'] ?? 0)) >= 0.05;

            return response()->json([
                'size' => $result['size'] ?? null,
                'deskew' => (float) ($result['deskew'] ?? 0),
                'image' => $straightened ? 'data:image/png;base64,'.base64_encode($disk->get($stored)) : null,
                'fit' => $result['fit'] ?? null,
                'geometry' => $result['geometry'] ?? $geometry,
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
        } catch (LineMarkersException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            $disk->deleteDirectory($directory);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);

        $template = DB::transaction(function () use ($validated, $request, $documentType) {
            $template = $this->createLayout($request, $validated, $documentType);

            if ($validated['publish'] ?? false) {
                $this->publishTemplate($template);
            }

            return $template;
        });

        return redirect()
            ->route('templates.edit', $template)
            ->with(
                'success',
                ($validated['publish'] ?? false)
                    ? 'Template created and published for Staff.'
                    : 'Template saved as a draft.',
            );
    }

    public function edit(DocumentTemplate $template): View
    {
        $template->load(['fields', 'documentTypeDefinition']);

        return view('templates.edit', [
            'template' => $template,
            'docType' => $template->documentTypeDefinition,
            'fields' => $template->fields->map(fn (DocumentTemplateField $f) => [
                'name' => $f->name,
                'x' => $f->x,
                'y' => $f->y,
                'width' => $f->width,
                'height' => $f->height,
                'angle' => $f->angle,
                'person_group' => $f->person_group,
                'person_field_order' => $f->person_field_order,
                ...$f->settings(),
            ])->all(),
            // Published, or read by records or pages in progress: saving new
            // markers makes a new version instead of changing this one.
            'usage' => [
                'published' => $template->is_active,
                'records' => $template->records()->count(),
                'pages' => $template->pages()->count(),
            ],
            'parent' => $template->parent()->first(['id', 'name']),
            'columns' => array_values($template->columns ?? []),
            'ruledYs' => array_values($template->ruled_ys ?? []),
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $validated = $this->validatePayload($request, $template);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);
        $opened = $request->filled('revision') ? $request->integer('revision') : null;
        $this->guardRevision($opened, $template->revision);

        // Records keep the layout they were read with: new markers or field
        // settings on a layout in use become a new version.
        if ($template->isInUse() && $this->layoutChanged($template, $validated)) {
            return $this->saveAsNewVersion($request, $template, $validated, $documentType);
        }

        DB::transaction(function () use ($template, $validated, $documentType, $opened) {
            // Checked again under a lock: two saves at the same moment cannot
            // both pass the check above.
            $this->guardRevision(
                $opened,
                (int) DocumentTemplate::whereKey($template->getKey())->lockForUpdate()->value('revision'),
            );

            $oldSample = $template->only([
                'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
            ]);
            $template->fill([
                'name' => $validated['name'],
                'doc_type' => $documentType->legacyType()->value,
                'document_type_id' => $documentType->getKey(),
                'paper_size' => $validated['paper_size'],
                'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'description' => $validated['description'] ?? null,
                'grouping_mode' => $validated['grouping_mode'],
                'columns' => $validated['columns'],
                'ruled_ys' => $validated['ruled_ys'],
            ]);
            $template->forceFill(['revision' => $template->revision + 1]);

            $before = $template->fields()->count();
            $this->syncFields($template, $validated['fields']);

            $ledger = $validated['columns']
                ? ', '.count($validated['columns']).' ledger columns, '.count($validated['ruled_ys']).' ruled lines'
                : '';
            $this->audit->saveAndLog(
                'template.updated',
                $template,
                "Updated template '{$template->name}' ({$before} -> ".count($validated['fields'])
                    .' fields, '.$this->personGroupCount($validated['fields']).' person groups'.$ledger.').',
            );

            if (($validated['sample_document'] ?? null) instanceof UploadedFile) {
                $template->forceFill(
                    $this->samples->store($template, $validated['sample_document']),
                )->save();

                $this->audit->log(
                    $oldSample['sample_path'] ? 'template.sample-replaced' : 'template.sample-uploaded',
                    $template,
                    old: $oldSample['sample_path'] ? $oldSample : null,
                    new: $template->only([
                        'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
                    ]),
                    description: "Stored sample document '{$template->sample_original_name}' for template '{$template->name}'.",
                );
            } elseif ($template->sample_path) {
                $relocated = $this->samples->relocate($template);
                if ($relocated !== $template->sample_path) {
                    $template->forceFill(['sample_path' => $relocated])->saveQuietly();
                }
            }

            if ($validated['publish'] ?? false) {
                $this->publishTemplate($template);
            }
        });

        return back()->with(
            'success',
            match (true) {
                (bool) ($validated['publish'] ?? false) => 'Template saved and published for Staff.',
                $template->is_active => 'Layout details saved. It stays published for Staff.',
                default => 'Template draft saved.',
            },
        );
    }

    /**
     * The Publish button in the template library. Only one template per
     * certificate type is published for Staff.
     */
    public function activate(DocumentTemplate $template): RedirectResponse
    {
        if ($template->fields()->doesntExist() && ! $template->isLedger()) {
            return back()->with('error', 'Add at least one field or ledger column before publishing.');
        }

        DB::transaction(fn () => $this->publishTemplate($template));

        return back()->with('success', "'{$template->name}' is now published for {$template->typeLabel()}.");
    }

    public function sample(DocumentTemplate $template): JsonResponse
    {
        $disk = Storage::disk('local');

        abort_unless(
            $template->sample_path && $disk->exists($template->sample_path),
            404,
        );

        // Return preview data as JSON instead of exposing a PDF/image response.
        // This prevents browsers and download-manager extensions from treating
        // an editor preview as a file download.
        return response()->json([
            'name' => $template->sample_original_name ?? basename($template->sample_path),
            'mime' => $template->sample_mime ?? 'application/octet-stream',
            'data' => base64_encode($disk->get($template->sample_path)),
        ], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_SLASHES);
    }

    public function destroySample(DocumentTemplate $template): RedirectResponse
    {
        if (! $template->sample_path) {
            return back()->with('error', 'This layout does not have a stored sample document.');
        }

        $sample = $template->only([
            'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
        ]);

        DB::transaction(function () use ($template, $sample) {
            $template->forceFill([
                'sample_path' => null,
                'sample_original_name' => null,
                'sample_mime' => null,
                'sample_size' => null,
            ])->save();

            $this->audit->log(
                'template.sample-deleted',
                $template,
                old: $sample,
                description: "Deleted the stored sample from template '{$template->name}'.",
            );
        });

        $this->samples->deletePath($sample['sample_path']);

        return back()->with('success', 'The stored sample document was deleted.');
    }

    /**
     * A draft copy of a layout, to start a variant from. The original, and every
     * record read with it, is not touched.
     */
    public function duplicate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $template->load(['fields', 'documentTypeDefinition']);

        $copy = DB::transaction(function () use ($request, $template) {
            $copy = $template->replicate([
                'is_active', 'revision', 'parent_id', 'created_by',
                'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
            ]);
            $copy->forceFill([
                'name' => $this->copyName($template),
                'is_active' => false,
                'parent_id' => $template->getKey(),
                'created_by' => $request->user()->getKey(),
            ])->save();

            foreach ($template->fields as $field) {
                $copy->fields()->create($field->only([
                    'name', 'x', 'y', 'width', 'height', 'angle', 'sort_order', 'is_required',
                    'person_group', 'person_field_order', 'role', 'value_type', 'options', 'hint',
                ]));
            }

            if ($sample = $this->samples->copy($template, $copy)) {
                $copy->forceFill($sample)->save();
            }

            $this->audit->log(
                'template.duplicated',
                $copy,
                new: ['name' => $copy->name, 'copied_from' => $template->name],
                description: "Duplicated template '{$template->name}' as '{$copy->name}'.",
            );

            return $copy;
        });

        return redirect()
            ->route('templates.edit', $copy)
            ->with('success', "Duplicated '{$template->name}' as a draft. Changes here do not touch the original.");
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        // A record keeps the layout it was read with, so a layout that has
        // records is never deleted: they would be left pointing at nothing.
        // Pages still in progress do not count. Deleting the layout only clears
        // their link to it (nullOnDelete), and the hourly prune removes them.
        $recordCount = $template->records()->count();
        if ($recordCount > 0) {
            return back()->with(
                'error',
                "'{$template->name}' was used by {$recordCount} ".Str::plural('record', $recordCount)." and can't be deleted.",
            );
        }

        $template->loadMissing('documentTypeDefinition');
        $samplePath = $template->sample_path;
        $wasPublished = $template->is_active;

        DB::transaction(function () use ($template, $wasPublished) {
            $this->audit->log(
                'template.deleted',
                $template,
                old: [
                    'name' => $template->name,
                    'document_type' => $template->documentTypeDefinition?->key ?? $template->doc_type->value,
                    'paper_size' => $template->paper_size->value,
                    'orientation' => $template->orientation->value,
                    'was_published' => $wasPublished,
                    'sample_document' => $template->sample_original_name,
                ],
                description: "Deleted template '{$template->name}'.",
            );

            $template->delete();
        });

        $this->samples->deletePath($samplePath);

        $message = "Layout '{$template->name}' was deleted.";
        if ($wasPublished) {
            $message .= ' Publish another layout before Staff scan this document type again.';
        }

        return redirect()->route('templates.index')->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?DocumentTemplate $template = null): array
    {
        $this->hydrateJsonFields($request);

        if (! $request->has('grouping_mode')) {
            $request->merge([
                'grouping_mode' => $template?->grouping_mode ?: 'auto',
            ]);
        }

        $definition = $request->filled('document_type_id')
            ? DocumentTypeDefinition::find($request->integer('document_type_id'))
            : DocumentTypeDefinition::where('key', (string) $request->input('doc_type'))->first();

        if ($definition) {
            $request->merge([
                'document_type_id' => $definition->getKey(),
                'doc_type' => $definition->legacyType()->value,
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document_type_id' => [
                'required',
                'integer',
                'exists:document_types,id',
                ...($template ? [Rule::in([$template->document_type_id])] : []),
            ],
            'doc_type' => [
                'required',
                Rule::enum(DocumentType::class),
                ...($template ? [Rule::in([$template->doc_type->value])] : []),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sample_document' => [
                'nullable',
                File::types(['pdf', 'png', 'jpg', 'jpeg', 'webp', 'bmp'])
                    ->max(20 * 1024),
            ],
            'paper_size' => ['required', Rule::enum(PaperSize::class)],
            'orientation' => ['required', Rule::enum(PageOrientation::class)],
            'custom_width_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'custom_height_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'grouping_mode' => ['required', Rule::in(['auto', 'custom'])],
            'publish' => ['sometimes', 'boolean'],
            // A ruled register may be described entirely by its ledger grid.
            'fields' => ['nullable', 'array', 'max:'.Limits::MAX_FIELDS],
            'columns' => ['nullable', 'array', 'max:60'],
            // One limit for columns and fields: a ledger line is named after its
            // column plus " · row N", and both are stored in 500 characters.
            'columns.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'columns.*.box' => ['required', 'array', 'size:4'],
            'columns.*.box.*' => ['required', 'numeric', 'min:0', 'max:1'],
            // Degrees clockwise about the column's own centre.
            'columns.*.angle' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'ruled_ys' => ['nullable', 'array', 'max:400'],
            'ruled_ys.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            // Fractions of the page. Bounds keep a box on the paper.
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.01', 'max:1'],
            // Degrees clockwise about the field's own centre.
            'fields.*.angle' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'fields.*.person_group' => [
                Rule::excludeIf(fn () => $request->input('grouping_mode') !== 'custom'),
                'nullable',
                'integer',
                'min:1',
                'max:'.Limits::MAX_FIELDS,
            ],
            'fields.*.person_field_order' => [
                Rule::excludeIf(fn () => $request->input('grouping_mode') !== 'custom'),
                'nullable',
                'integer',
                'min:0',
                'max:'.(Limits::MAX_FIELDS - 1),
            ],
            ...$this->settingsRules('fields.*'),
            ...$this->settingsRules('columns.*'),
        ], [
            'sample_document.mimes' => 'The sample must be a PDF, PNG, JPG, WEBP or BMP file.',
        ]);

        $validated['custom_width_mm'] ??= null;
        $validated['custom_height_mm'] ??= null;
        $validated['fields'] = array_values($validated['fields'] ?? []);
        $ratio = $this->pageRatio($validated);
        [$validated['columns'], $validated['ruled_ys']] = $this->checkedLedgerGrid(
            $validated['columns'] ?? [],
            $validated['ruled_ys'] ?? [],
            $validated['fields'],
            $ratio,
        );

        if ($validated['paper_size'] !== PaperSize::Custom->value) {
            $validated['custom_width_mm'] = null;
            $validated['custom_height_mm'] = null;
        }

        $fieldErrors = [];
        foreach ($validated['fields'] as $index => $field) {
            if ((float) $field['x'] + (float) $field['width'] > 1.00001) {
                $fieldErrors["fields.{$index}.width"] = 'This field marker extends beyond the document width.';
            }

            if ((float) $field['y'] + (float) $field['height'] > 1.00001) {
                $fieldErrors["fields.{$index}.height"] = 'This field marker extends beyond the document height.';
            }

            if (! isset($fieldErrors["fields.{$index}.width"]) && ! isset($fieldErrors["fields.{$index}.height"])
                && ! MarkerBounds::inside(
                    (float) $field['x'], (float) $field['y'], (float) $field['width'], (float) $field['height'],
                    (float) ($field['angle'] ?? 0), $ratio, 0.01,
                )) {
                $fieldErrors["fields.{$index}.angle"] = 'This field marker is tilted past the edge of the document.';
            }

            if ($validated['grouping_mode'] === 'custom') {
                $hasGroup = isset($field['person_group']);
                $hasOrder = isset($field['person_field_order']);

                if ($hasGroup && ! $hasOrder) {
                    $fieldErrors["fields.{$index}.person_field_order"] = 'Choose this field\'s order within its person group.';
                } elseif ($hasOrder && ! $hasGroup) {
                    $fieldErrors["fields.{$index}.person_group"] = 'Choose a person group for this ordered field.';
                }
            }
        }

        $fieldErrors = [
            ...$fieldErrors,
            ...$this->settingsErrors('fields', $validated['fields'], $validated['grouping_mode'] === 'custom'),
        ];

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages($fieldErrors);
        }

        $validated['fields'] = $this->canonicalizePersonGrouping(
            $validated['fields'],
            $validated['grouping_mode'],
        );

        return $validated;
    }

    private function hydrateJsonFields(Request $request): void
    {
        $this->hydrateLedgerGrid($request);

        if (! $request->filled('fields_json')) {
            return;
        }

        try {
            $fields = json_decode((string) $request->input('fields_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'fields' => 'The field layout could not be read. Refresh the page and try again.',
            ]);
        }

        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => 'The field layout must be a valid list of markers.',
            ]);
        }

        $request->merge(['fields' => $fields]);
    }

    private function hydrateLedgerGrid(Request $request): void
    {
        foreach (['columns_json' => 'columns', 'ruled_ys_json' => 'ruled_ys'] as $input => $key) {
            if (! $request->filled($input)) {
                continue;
            }
            try {
                $value = json_decode((string) $request->input($input), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages([
                    $key => 'The ledger grid could not be read. Refresh the page and try again.',
                ]);
            }
            $request->merge([$key => is_array($value) ? $value : []]);
        }
    }

    /**
     * The page's width : height, from its paper size and orientation.
     *
     * @param  array<string, mixed>  $validated
     */
    private function pageRatio(array $validated): float
    {
        $paper = PaperSize::from($validated['paper_size']);
        $orientation = PageOrientation::from($validated['orientation']);

        if ($paper !== PaperSize::Custom) {
            return $paper->aspectRatio($orientation);
        }

        // Custom sizes are entered upright; landscape swaps them.
        $width = max(1.0, (float) ($validated['custom_width_mm'] ?? 210));
        $height = max(1.0, (float) ($validated['custom_height_mm'] ?? 297));

        return $orientation === PageOrientation::Portrait ? $width / $height : $height / $width;
    }

    /**
     * The ledger grid, checked and normalised: null when a layout has none.
     *
     * Staff alignment places the row lines relative to the columns' band (the
     * median top and bottom of the columns), so row lines far outside that
     * band mean the two were moved apart and would land on the wrong rows.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  list<float|int|string>  $ruledYs
     * @param  list<array<string, mixed>>  $fields
     * @param  float  $ratio  Page width : height, for tilted columns.
     * @return array{0: list<array{name: string, box: list<float>}>|null, 1: list<float>|null}
     */
    private function checkedLedgerGrid(array $columns, array $ruledYs, array $fields, float $ratio): array
    {
        $columns = array_values($columns);
        $ruled = array_values(array_map('floatval', $ruledYs));
        $errors = [];

        if ($columns === [] && $fields === []) {
            $errors['fields'] = 'Add at least one field or ledger column before saving this layout.';
        }
        if ($columns !== [] && count($ruled) < 2) {
            $errors['ruled_ys'] = 'A ledger needs its ruled row lines. Detect them from the sample or add them.';
        }
        for ($i = 1; $i < count($ruled); $i++) {
            if ($ruled[$i] <= $ruled[$i - 1]) {
                $errors['ruled_ys'] = 'Ruled row lines must run from top to bottom without repeating.';
                break;
            }
            if ($ruled[$i] - $ruled[$i - 1] < 0.002) {
                $errors['ruled_ys'] = 'Two ruled row lines sit almost on top of each other. Remove one.';
                break;
            }
        }

        $fieldNames = collect($fields)->map(fn ($f) => mb_strtolower(trim((string) $f['name'])))->flip();
        foreach ($columns as $index => $column) {
            [$x, $y, $w, $h] = array_map('floatval', $column['box']);
            if ($w < 0.005 || $h < 0.005 || $x + $w > 1.00001 || $y + $h > 1.00001) {
                $errors["columns.{$index}.box"] = 'This ledger column extends beyond the document.';
            } elseif (! MarkerBounds::inside($x, $y, $w, $h, (float) ($column['angle'] ?? 0), $ratio, 0.01)) {
                $errors["columns.{$index}.box"] = 'This ledger column is tilted past the edge of the document.';
            }
            if ($fieldNames->has(mb_strtolower(trim((string) $column['name'])))) {
                $errors["columns.{$index}.name"] = 'A ledger column and a field cannot share a name.';
            }
        }

        if ($columns !== [] && count($ruled) >= 2 && ! isset($errors['ruled_ys'])) {
            $boxes = array_map(fn (array $column) => array_map('floatval', $column['box']), $columns);
            $top = $this->median(array_map(fn (array $box) => $box[1], $boxes));
            $bottom = $this->median(array_map(fn (array $box) => $box[1] + $box[3], $boxes));
            $gaps = [];
            for ($i = 1; $i < count($ruled); $i++) {
                $gaps[] = $ruled[$i] - $ruled[$i - 1];
            }
            // One row beyond the columns is still carried along; more is not.
            $tolerance = max(0.01, $this->median($gaps));
            if ($ruled[0] < $top - $tolerance || $ruled[count($ruled) - 1] > $bottom + $tolerance) {
                $errors['ruled_ys'] = 'The ruled row lines run outside the ledger columns. Move the columns over the rows, or the rows into the columns.';
            }
        }

        // Every column is one cell per row, all in one "person": one name and
        // one entry number at most.
        $errors = [...$errors, ...$this->settingsErrors('columns', $columns, true)];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($columns === []) {
            return [null, null];
        }

        return [
            array_map(fn (array $column) => [
                'name' => trim((string) $column['name']),
                'box' => array_map(fn ($v) => round((float) $v, 5), $column['box']),
                ...(round((float) ($column['angle'] ?? 0), 1) != 0.0 ? ['angle' => round((float) $column['angle'], 1)] : []),
                ...$this->compactSettings($this->settingsOf($column)),
            ], $columns),
            array_map(fn (float $y) => round($y, 5), $ruled),
        ];
    }

    /**
     * Rules for what a field or column holds, and how Staff check its value.
     *
     * @return array<string, list<mixed>>
     */
    private function settingsRules(string $prefix): array
    {
        return [
            "{$prefix}.role" => ['nullable', Rule::in(DocumentTemplateField::ROLES)],
            "{$prefix}.required" => ['sometimes', 'boolean'],
            "{$prefix}.type" => ['nullable', Rule::in(DocumentTemplateField::VALUE_TYPES)],
            "{$prefix}.options" => ['nullable', 'array', 'max:30'],
            "{$prefix}.options.*" => ['nullable', 'string', 'max:60'],
            "{$prefix}.hint" => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * A field's or column's settings, with defaults filled in.
     *
     * @param  array<string, mixed>  $item
     * @return array{role: string|null, required: bool, type: string, options: list<string>|null, hint: string|null}
     */
    private function settingsOf(array $item): array
    {
        $type = in_array($item['type'] ?? null, DocumentTemplateField::VALUE_TYPES, true) ? $item['type'] : 'text';
        $options = [];
        if ($type === 'choice') {
            foreach ((array) ($item['options'] ?? []) as $option) {
                $option = trim((string) $option);
                if ($option !== '' && ! in_array(mb_strtolower($option), array_map('mb_strtolower', $options), true)) {
                    $options[] = $option;
                }
            }
        }
        $hint = trim((string) ($item['hint'] ?? ''));

        return [
            'role' => in_array($item['role'] ?? null, DocumentTemplateField::ROLES, true) ? $item['role'] : null,
            'required' => filter_var($item['required'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'type' => $type,
            'options' => $options === [] ? null : $options,
            'hint' => $hint === '' ? null : $hint,
        ];
    }

    /**
     * Settings as a column stores them in the template's JSON: only what
     * differs from the defaults.
     *
     * @param  array{role: string|null, required: bool, type: string, options: list<string>|null, hint: string|null}  $settings
     * @return array<string, mixed>
     */
    private function compactSettings(array $settings): array
    {
        return array_filter([
            'role' => $settings['role'],
            'required' => $settings['required'] ? null : false,
            'type' => $settings['type'] === 'text' ? null : $settings['type'],
            'options' => $settings['options'],
            'hint' => $settings['hint'],
        ], fn ($value) => $value !== null);
    }

    /**
     * A choice needs its choices, and a person has one name and one entry
     * number. Person groups are only known when they are drawn by hand
     * (`$grouped`); automatic rows are found from the marker positions later.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function settingsErrors(string $key, array $items, bool $grouped): array
    {
        $errors = [];
        $taken = [];

        foreach (array_values($items) as $index => $item) {
            $settings = $this->settingsOf($item);
            if ($settings['type'] === 'choice' && $settings['options'] === null) {
                $errors["{$key}.{$index}.options"] = 'List the choices this value can take.';
            }

            if (! $grouped || $settings['role'] === null) {
                continue;
            }
            $group = $key === 'columns' ? 'ledger' : (string) ($item['person_group'] ?? 'details');
            if (isset($taken[$group][$settings['role']])) {
                $errors["{$key}.{$index}.role"] = $settings['role'] === 'name'
                    ? "Only one {$this->itemNoun($key)} in a row can hold the person's name."
                    : "Only one {$this->itemNoun($key)} in a row can hold the entry number.";
            }
            $taken[$group][$settings['role']] = true;
        }

        return $errors;
    }

    private function itemNoun(string $key): string
    {
        return $key === 'columns' ? 'column' : 'field';
    }

    /**
     * Publish the layout Staff receive, retiring the previous one atomically.
     * The row lock prevents two simultaneous publishes from leaving two active
     * layouts for the same certificate type.
     */
    private function publishTemplate(DocumentTemplate $template): void
    {
        $sameType = DocumentTemplate::query()
            ->where('document_type_id', $template->document_type_id)
            ->lockForUpdate()
            ->get();

        $previous = $sameType
            ->where('is_active', true)
            ->where('id', '!=', $template->getKey());

        DocumentTemplate::query()
            ->whereIn('id', $sameType->modelKeys())
            ->whereKeyNot($template->getKey())
            ->update(['is_active' => false]);

        // Always write the target row. Its in-memory state may be stale if
        // another publish was waiting on the same lock.
        $template->forceFill(['is_active' => true])->save();

        $this->audit->log(
            'template.activated',
            $template,
            old: ['previous_active' => $previous->pluck('name')->values()->all()],
            new: [
                'active' => $template->name,
                'paper_size' => $template->paper_size->value,
                'orientation' => $template->orientation->value,
            ],
            description: "Published template '{$template->name}' for {$template->typeLabel()}.",
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function syncFields(DocumentTemplate $template, array $fields): void
    {
        // Replaced wholesale: field identity is positional, and records keep their
        // own copy of the box they were cropped with.
        $template->fields()->delete();

        foreach (array_values($fields) as $index => $field) {
            $settings = $this->settingsOf($field);
            $template->fields()->create([
                'name' => $field['name'],
                'x' => $field['x'],
                'y' => $field['y'],
                'width' => $field['width'],
                'height' => $field['height'],
                'angle' => round((float) ($field['angle'] ?? 0), 1),
                'sort_order' => $index,
                'is_required' => $settings['required'],
                'person_group' => $field['person_group'],
                'person_field_order' => $field['person_field_order'],
                'role' => $settings['role'],
                'value_type' => $settings['type'],
                'options' => $settings['options'],
                'hint' => $settings['hint'],
            ]);
        }
    }

    /**
     * A new layout from a validated payload: created, as a new version of
     * `$parent`, or from scratch. A new version without a new sample keeps a
     * copy of its parent's.
     *
     * @param  array<string, mixed>  $validated
     */
    private function createLayout(
        Request $request,
        array $validated,
        DocumentTypeDefinition $documentType,
        ?DocumentTemplate $parent = null,
    ): DocumentTemplate {
        $template = DocumentTemplate::create([
            'name' => $validated['name'],
            'doc_type' => $documentType->legacyType()->value,
            'document_type_id' => $documentType->getKey(),
            'paper_size' => $validated['paper_size'],
            'orientation' => $validated['orientation'],
            'custom_width_mm' => $validated['custom_width_mm'],
            'custom_height_mm' => $validated['custom_height_mm'],
            'description' => $validated['description'] ?? null,
            'grouping_mode' => $validated['grouping_mode'],
            'columns' => $validated['columns'],
            'ruled_ys' => $validated['ruled_ys'],
            'is_active' => false,
            'created_by' => $request->user()->getKey(),
            'parent_id' => $parent?->getKey(),
        ]);

        $this->syncFields($template, $validated['fields']);

        if (($validated['sample_document'] ?? null) instanceof UploadedFile) {
            $template->forceFill(
                $this->samples->store($template, $validated['sample_document']),
            )->save();
        } elseif ($parent && ($sample = $this->samples->copy($parent, $template))) {
            $template->forceFill($sample)->save();
        }

        $this->audit->log(
            $parent ? 'template.versioned' : 'template.created',
            $template,
            new: ['name' => $template->name, 'document_type' => $documentType->key,
                'paper_size' => $validated['paper_size'], 'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'field_count' => count($validated['fields']),
                'column_count' => count($validated['columns'] ?? []),
                'ruled_line_count' => count($validated['ruled_ys'] ?? []),
                'grouping_mode' => $validated['grouping_mode'],
                'group_count' => $this->personGroupCount($validated['fields']),
                'sample_document' => $template->sample_original_name,
                ...($parent ? ['version_of' => $parent->name] : [])],
            description: $parent
                ? "Saved a new version of template '{$parent->name}' as '{$template->name}'; the previous version is kept for its records."
                : "Created template '{$template->name}'.",
        );

        return $template;
    }

    /**
     * Save changes to a layout in use as a new version, and publish it when
     * asked. The layout in use stays exactly as it was.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveAsNewVersion(
        Request $request,
        DocumentTemplate $template,
        array $validated,
        DocumentTypeDefinition $documentType,
    ): RedirectResponse {
        $publish = (bool) ($validated['publish'] ?? false);

        $version = DB::transaction(function () use ($request, $template, $validated, $documentType, $publish) {
            $version = $this->createLayout($request, $validated, $documentType, $template);

            if ($publish) {
                $this->publishTemplate($version);
            }

            return $version;
        });

        $records = $template->records()->count();
        $kept = $records > 0
            ? " '{$template->name}' is kept unchanged for the {$records} ".Str::plural('record', $records).' read with it.'
            : " '{$template->name}' is kept unchanged.";

        return redirect()
            ->route('templates.edit', $version)
            ->with('success', $publish
                ? 'Saved as a new version and published for Staff.'.$kept
                : 'Saved as a new draft version.'.$kept.($template->is_active ? ' It stays published until you publish this draft.' : ''));
    }

    /**
     * Refuse a save made from an older copy of the layout than the one stored.
     * Without a revision (an older form or an API call) nothing is checked.
     */
    private function guardRevision(?int $opened, int $current): void
    {
        if ($opened !== null && $opened !== $current) {
            throw ValidationException::withMessages([
                'revision' => 'Someone saved this layout after you opened it, so nothing was saved. '
                    .'Reload the page to see their version, then make your change again.',
            ]);
        }
    }

    /**
     * Whether a save changes what Staff read with the layout: its markers,
     * field settings, row lines, paper or person rows. Name, notes and sample
     * only describe it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function layoutChanged(DocumentTemplate $template, array $validated): bool
    {
        $template->loadMissing('fields');

        $saved = $this->layoutSignature(
            $template->paper_size->value,
            $template->orientation->value,
            $template->custom_width_mm,
            $template->custom_height_mm,
            $template->grouping_mode,
            $template->columns ?? [],
            $template->ruled_ys ?? [],
            $template->fields->map(fn (DocumentTemplateField $f) => [
                'name' => $f->name,
                'x' => $f->x,
                'y' => $f->y,
                'width' => $f->width,
                'height' => $f->height,
                'angle' => $f->angle,
                'person_group' => $f->person_group,
                'person_field_order' => $f->person_field_order,
                ...$f->settings(),
            ])->all(),
        );

        return $saved !== $this->layoutSignature(
            $validated['paper_size'],
            $validated['orientation'],
            $validated['custom_width_mm'],
            $validated['custom_height_mm'],
            $validated['grouping_mode'],
            $validated['columns'] ?? [],
            $validated['ruled_ys'] ?? [],
            $validated['fields'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<float|int|string>  $ruled
     * @param  list<array<string, mixed>>  $fields
     */
    private function layoutSignature(
        string $paper,
        string $orientation,
        ?float $width,
        ?float $height,
        string $grouping,
        array $columns,
        array $ruled,
        array $fields,
    ): string {
        $round = fn ($value) => round((float) $value, 5);
        $angle = fn ($value) => round((float) ($value ?? 0), 1);

        return json_encode([
            'paper' => [$paper, $orientation, $paper === PaperSize::Custom->value ? [$round($width), $round($height)] : null],
            'grouping' => $grouping,
            'columns' => array_map(fn (array $column) => [
                trim((string) $column['name']),
                array_map($round, $column['box']),
                $angle($column['angle'] ?? 0),
                $this->settingsOf($column),
            ], array_values($columns)),
            'ruled' => array_map($round, array_values($ruled)),
            'fields' => array_map(fn (array $field) => [
                trim((string) $field['name']),
                $round($field['x']),
                $round($field['y']),
                $round($field['width']),
                $round($field['height']),
                $angle($field['angle'] ?? 0),
                isset($field['person_group']) ? (int) $field['person_group'] : null,
                isset($field['person_field_order']) ? (int) $field['person_field_order'] : null,
                $this->settingsOf($field),
            ], array_values($fields)),
        ], JSON_THROW_ON_ERROR);
    }

    /** "Name (copy)", or "(copy 2)" and so on when that is taken for the type. */
    private function copyName(DocumentTemplate $template): string
    {
        $base = Str::limit($template->name, 105, '');
        $taken = DocumentTemplate::where('document_type_id', $template->document_type_id)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower($name))
            ->flip();

        for ($number = 1; ; $number++) {
            $name = $number === 1 ? "{$base} (copy)" : "{$base} (copy {$number})";
            if (! $taken->has(mb_strtolower($name))) {
                return $name;
            }
        }
    }

    /**
     * Group numbers and field positions are presentation order, not permanent
     * identifiers. Closing gaps here keeps every saved layout deterministic even
     * after a person or one of their fields is removed in the builder.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function canonicalizePersonGrouping(array $fields, string $mode): array
    {
        $fields = array_values($fields);

        foreach ($fields as &$field) {
            $field['person_group'] = $mode === 'custom'
                ? ($field['person_group'] ?? null)
                : null;
            $field['person_field_order'] = $mode === 'custom'
                ? ($field['person_field_order'] ?? null)
                : null;
        }
        unset($field);

        if ($mode !== 'custom') {
            return $fields;
        }

        $membersByGroup = [];
        foreach ($fields as $index => $field) {
            if ($field['person_group'] === null) {
                continue;
            }

            $group = (int) $field['person_group'];
            $membersByGroup[$group][] = [
                'field_index' => $index,
                'requested_order' => (int) $field['person_field_order'],
            ];
        }

        ksort($membersByGroup, SORT_NUMERIC);

        $canonicalGroup = 1;
        foreach ($membersByGroup as $members) {
            usort($members, fn (array $left, array $right): int => $left['requested_order'] <=> $right['requested_order']
                    ?: $left['field_index'] <=> $right['field_index']);

            foreach ($members as $canonicalOrder => $member) {
                $fields[$member['field_index']]['person_group'] = $canonicalGroup;
                $fields[$member['field_index']]['person_field_order'] = $canonicalOrder;
            }

            $canonicalGroup++;
        }

        return $fields;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /** @param list<array<string, mixed>> $fields */
    private function personGroupCount(array $fields): int
    {
        return count(array_unique(array_filter(
            array_column($fields, 'person_group'),
            fn ($group): bool => $group !== null,
        )));
    }
}
