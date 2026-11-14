<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Models\DocumentTypeDefinition;
use App\Services\AuditLogger;
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
use Throwable;

/**
 * Document template builder.
 *
 * Templates decide which fields a scan captures per certificate type and where the
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
        $versions = DocumentTemplate::versionInfo();

        $layouts = DocumentTemplate::listed()
            ->withCount(['fields', 'records', 'pages'])
            ->with(['creator', 'documentTypeDefinition', 'parent:id,name,is_active', 'pageTwoTemplate'])
            ->get()
            ->each(function (DocumentTemplate $layout) use ($versions) {
                $layout->setAttribute('publish_problems', $layout->is_active ? [] : $this->publishProblems($layout));
                // "v2" beside the name, only where the layout has versions to tell apart.
                $layout->setAttribute('version', ($versions[$layout->getKey()]['size'] ?? 1) > 1 ? $versions[$layout->getKey()]['number'] : null);
                $layout->setAttribute('family', $versions[$layout->getKey()]['root'] ?? $layout->getKey());
            });

        // The family (a layout and its versions) that holds the published layout first, each
        // family together with its newest version on top; other families after it.
        $publishedFamilies = $layouts->where('is_active', true)->pluck('family')->flip();
        $layouts = $layouts->sortBy([
            ['document_type_id', 'asc'],
            fn (DocumentTemplate $a, DocumentTemplate $b) => (int) $publishedFamilies->has($b->family) <=> (int) $publishedFamilies->has($a->family),
            ['family', 'asc'],
            fn (DocumentTemplate $a, DocumentTemplate $b) => ($b->version ?? 0) <=> ($a->version ?? 0),
            ['id', 'asc'],
        ]);

        return view('templates.index', [
            // Page 2 layouts are not listed: they belong to their main layout.
            'templates' => $layouts->groupBy('document_type_id'),
            // Deleted layouts that records were read with: kept, and shown per type to restore.
            'archivedTemplates' => DocumentTemplate::query()
                ->where('is_page_two', false)
                ->whereNotNull('archived_at')
                ->withCount('records')
                ->with('pageTwoTemplate')
                ->orderByDesc('archived_at')
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
            // A new layout starts empty: the admin adds the fields this document has.
            'fields' => [],
            'columns' => [],
            'ruledYs' => [],
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
            // Row lines put back where a printed rule was too faint (shown amber).
            'bridged_ys' => $grid['suggestion']['bridged_ys'] ?? [],
            // Where the table's column rules end: rows below it are not the table's.
            'frame_bottom' => $grid['suggestion']['frame_bottom'] ?? null,
            // How far the page is turned, degrees clockwise: the columns turn with it.
            'tilt' => (float) ($grid['tilt'] ?? 0),
            'lines' => [
                'horizontal' => $grid['horizontal'] ?? [],
                'vertical' => $grid['vertical'] ?? [],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);
        $pageTwo = $this->validatedPageTwo($request, $validated, null);

        $problems = [];
        $template = DB::transaction(function () use ($validated, $request, $documentType, $pageTwo, &$problems) {
            $template = $this->createLayout($request, $validated, $documentType);
            $this->savePageTwo($request, $template, $pageTwo, $documentType, $validated['page_two_template_id'], fresh: true);

            if ($validated['publish'] ?? false) {
                $problems = $this->publishIfReady($template, (bool) ($validated['publish_anyway'] ?? false));
            }

            return $template;
        });

        $redirect = redirect()->route('templates.edit', $template);

        return match (true) {
            $problems !== [] => $redirect->with('error', $this->notPublishedMessage('Template saved as a draft', $problems)),
            (bool) ($validated['publish'] ?? false) => $redirect->with('success', 'Template created and published.'),
            default => $redirect->with('success', 'Template saved as a draft.'),
        };
    }

    public function edit(DocumentTemplate $template): View|RedirectResponse
    {
        if ($redirect = $this->toMainLayout($template)) {
            return $redirect;
        }

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
            // Which version this is, and how many layouts the family has.
            'version' => DocumentTemplate::versionInfo()[$template->getKey()] ?? null,
            'columns' => array_values($template->columns ?? []),
            'ruledYs' => array_values($template->ruled_ys ?? []),
            'pageTwo' => $this->pageTwoForBuilder($template),
            // What still stops this layout from being published (none: it can be).
            'publishProblems' => $this->publishProblems($template),
        ]);
    }

    /**
     * A page 2 layout is opened, changed, published and deleted only through its
     * main layout: back to the main layout's builder (or the library).
     */
    private function toMainLayout(DocumentTemplate $template): ?RedirectResponse
    {
        // A deleted layout stays only for the records read with it: it cannot be opened,
        // changed, published or copied (an old tab or a typed address still reaches it).
        if ($template->isArchived()) {
            return redirect()->route('templates.index')
                ->with('error', "'{$template->name}' was deleted, so it can't be opened or changed.");
        }

        if (! $template->is_page_two) {
            return null;
        }

        $main = $template->mainLayout()->first();
        $message = 'That is page 2 of a two-page layout. Open its main layout and change page 2 there.';

        return $main
            ? redirect()->route('templates.edit', $main)->with('error', $message)
            : redirect()->route('templates.index')->with('error', $message);
    }

    /**
     * The linked page 2 layout in the builder's own shape, to draw beside page 1.
     *
     * @return array<string, mixed>|null
     */
    private function pageTwoForBuilder(DocumentTemplate $template): ?array
    {
        $linked = $template->pageTwoTemplate()->with('fields')->first();
        if ($linked === null) {
            return null;
        }

        return [
            'name' => $linked->name,
            'grouping_mode' => $linked->grouping_mode ?: 'auto',
            'fields' => $linked->fields->map(fn (DocumentTemplateField $f) => [
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
            'columns' => array_values($linked->columns ?? []),
            'ruled_ys' => array_values($linked->ruled_ys ?? []),
        ];
    }

    /**
     * The page 2 layout the builder sent with a two-page layout (`page_two_json`:
     * fields, columns, ruled_ys and grouping_mode), checked like any layout, or
     * null when the layout has no page 2 of its own.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function validatedPageTwo(Request $request, array $validated, ?DocumentTemplate $main): ?array
    {
        if (! $request->filled('page_two_json')) {
            return null;
        }

        try {
            $layout = json_decode((string) $request->input('page_two_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['page_two_json' => 'Page 2 of this layout could not be read. Reload the builder and try again.']);
        }

        if (! is_array($layout)) {
            throw ValidationException::withMessages(['page_two_json' => 'Page 2 of this layout must be a valid layout.']);
        }

        // A layout with nothing on it is no page 2: any page 2 saved before is dropped.
        if (($layout['fields'] ?? []) === [] && ($layout['columns'] ?? []) === []) {
            return ['empty' => true];
        }

        $linked = $main?->pageTwoTemplate;
        $sub = Request::create('/', 'POST', [
            'name' => Str::limit(($validated['name'] ?? 'Layout').' - page 2', 120, ''),
            'document_type_id' => $validated['document_type_id'],
            'doc_type' => $validated['doc_type'],
            'page_ratio' => $validated['page_ratio'],
            'grouping_mode' => ($layout['grouping_mode'] ?? 'auto') === 'custom' ? 'custom' : 'auto',
            'fields_json' => json_encode($layout['fields'] ?? []),
            'columns_json' => json_encode($layout['columns'] ?? []),
            'ruled_ys_json' => json_encode($layout['ruled_ys'] ?? []),
        ]);
        $sub->setUserResolver($request->getUserResolver());

        try {
            $checked = $this->validatePayload($sub, $linked);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'page_two_json' => 'Page 2: '.collect($exception->errors())->flatten()->first(),
            ]);
        }

        return $checked;
    }

    /**
     * Whether page 2's markers differ from the page 2 layout this layout links to.
     *
     * @param  array<string, mixed>|null  $pageTwo
     */
    private function pageTwoChanged(DocumentTemplate $template, ?array $pageTwo): bool
    {
        if ($pageTwo === null) {
            return false;
        }

        $linked = $template->pageTwoTemplate?->loadMissing('fields');
        if ($linked === null) {
            return ! ($pageTwo['empty'] ?? false);
        }

        return ($pageTwo['empty'] ?? false) || $this->layoutChanged($linked, $pageTwo);
    }

    /**
     * Give `$main` its page 2. Page 2 is a hidden layout that belongs to `$main`
     * alone, so changing it never changes another layout:
     *
     * - drawn in the builder (`$pageTwo`): `$main`'s own page 2 is changed in
     *   place, or a new one is made from the drawing;
     * - chosen from the "Page 2 format" list (`$chosenId`): a private copy of
     *   the chosen layout, which itself stays as it was.
     *
     * `$fresh` is a layout just made (new, a new version): it owns no page 2
     * yet, so whatever page 2 it points at is copied, never shared.
     *
     * @param  array<string, mixed>|null  $pageTwo
     */
    private function savePageTwo(
        Request $request,
        DocumentTemplate $main,
        ?array $pageTwo,
        DocumentTypeDefinition $documentType,
        ?int $chosenId,
        bool $fresh,
    ): void {
        $own = ! $fresh && $main->page_two_template_id !== null
            ? DocumentTemplate::with('fields')->find($main->page_two_template_id)
            : null;
        // An older link to a layout of its own is never edited through this one.
        if ($own !== null && ! $own->is_page_two) {
            $own = null;
        }
        $previous = $own;

        if ($pageTwo !== null) {
            if ($pageTwo['empty'] ?? false) {
                $this->linkPageTwo($main, null, $previous);
            } elseif ($own !== null) {
                if ($this->layoutChanged($own, $pageTwo)) {
                    $own->fill([
                        'name' => $this->pageTwoName($main),
                        'columns' => $pageTwo['columns'],
                        'ruled_ys' => $pageTwo['ruled_ys'],
                        'grouping_mode' => $pageTwo['grouping_mode'],
                    ]);
                    $own->forceFill(['revision' => $own->revision + 1])->save();
                    $this->syncFields($own, $pageTwo['fields']);
                }
            } else {
                $created = $this->createLayout($request, [
                    ...$pageTwo,
                    'name' => $this->pageTwoName($main),
                    'page_two_template_id' => null,
                ], $documentType, pageTwo: true);
                $this->linkPageTwo($main, $created, $previous);
            }

            return;
        }

        if ($chosenId === null) {
            $this->linkPageTwo($main, null, $previous);

            return;
        }
        if ($own !== null && $own->getKey() === $chosenId) {
            return;
        }

        $source = DocumentTemplate::with('fields')->find($chosenId);
        $this->linkPageTwo($main, $source ? $this->copyAsPageTwo($request, $source, $main) : null, $previous);
    }

    /** Point `$main` at `$pageTwo` (or none), and delete the page 2 it owned before. */
    private function linkPageTwo(DocumentTemplate $main, ?DocumentTemplate $pageTwo, ?DocumentTemplate $previous): void
    {
        $main->forceFill(['page_two_template_id' => $pageTwo?->getKey()])->save();

        if ($previous !== null && $previous->is_page_two && ! $previous->is($pageTwo)) {
            $previous->delete();
        }
    }

    /** A private page 2 for `$main`: a copy of `$source` with its fields. */
    private function copyAsPageTwo(Request $request, DocumentTemplate $source, DocumentTemplate $main): DocumentTemplate
    {
        $source->loadMissing('fields');

        $copy = $source->replicate([
            'is_active', 'published_at', 'revision', 'parent_id', 'is_version', 'created_by',
            'page_two_template_id', 'is_page_two',
            'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
        ]);
        $copy->forceFill([
            'name' => $this->pageTwoName($main),
            'is_active' => false,
            'is_page_two' => true,
            'created_by' => $request->user()?->getKey() ?? $source->created_by,
        ])->save();

        foreach ($source->fields as $field) {
            $copy->fields()->create($field->only([
                'name', 'x', 'y', 'width', 'height', 'angle', 'sort_order', 'is_required',
                'person_group', 'person_field_order', 'role', 'value_type', 'options', 'hint',
            ]));
        }

        return $copy;
    }

    private function pageTwoName(DocumentTemplate $main): string
    {
        return Str::limit($main->name, 110, '').' - page 2';
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        if ($redirect = $this->toMainLayout($template)) {
            return $redirect;
        }

        $validated = $this->validatePayload($request, $template);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);
        $opened = $request->filled('revision') ? $request->integer('revision') : null;
        $this->guardRevision($opened, $template->revision);
        $pageTwo = $this->validatedPageTwo($request, $validated, $template);

        // Records keep the layout they were read with: new markers or field
        // settings on a layout in use become a new version.
        if ($template->isInUse() && ($this->layoutChanged($template, $validated) || $this->pageTwoChanged($template, $pageTwo))) {
            return $this->saveAsNewVersion($request, $template, $validated, $documentType, $pageTwo);
        }

        $problems = [];
        DB::transaction(function () use ($request, $template, $validated, $documentType, $opened, $pageTwo, &$problems) {
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
                'page_ratio' => $validated['page_ratio'],
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

            $this->savePageTwo($request, $template, $pageTwo, $documentType, $validated['page_two_template_id'], fresh: false);
            // A renamed layout renames its page 2 with it.
            DocumentTemplate::whereKey($template->page_two_template_id)
                ->where('is_page_two', true)
                ->update(['name' => $this->pageTwoName($template)]);

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

            // Already published: nothing to publish again.
            if (($validated['publish'] ?? false) && ! $template->is_active) {
                $problems = $this->publishIfReady($template, (bool) ($validated['publish_anyway'] ?? false));
            }
        });

        if ($problems !== []) {
            return back()->with('error', $this->notPublishedMessage('Template draft saved', $problems));
        }

        return back()->with(
            'success',
            match (true) {
                (bool) ($validated['publish'] ?? false) => 'Template saved and published.',
                $template->is_active => 'Layout details saved. It stays published.',
                default => 'Template draft saved.',
            },
        );
    }

    /**
     * The Publish button in the template library. Only one template per
     * certificate type is published: it replaces the one before.
     */
    public function activate(DocumentTemplate $template): RedirectResponse
    {
        if ($redirect = $this->toMainLayout($template)) {
            return $redirect;
        }

        if ($template->fields()->doesntExist() && ! $template->isLedger()) {
            return back()->with('error', 'Add at least one field or ledger column before publishing.');
        }

        $problems = DB::transaction(fn () => $this->publishIfReady($template));
        if ($problems !== []) {
            return back()->with('error', $this->notPublishedMessage("'{$template->name}' was not published", $problems));
        }

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
        if ($redirect = $this->toMainLayout($template)) {
            return $redirect;
        }

        $template->load(['fields', 'documentTypeDefinition', 'pageTwoTemplate.fields']);

        $copy = DB::transaction(function () use ($request, $template) {
            $copy = $template->replicate([
                'is_active', 'published_at', 'revision', 'parent_id', 'is_version', 'created_by',
                'page_two_template_id', 'is_page_two',
                'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
            ]);
            $copy->forceFill([
                'name' => $this->copyName($template),
                'is_active' => false,
                'parent_id' => $template->getKey(),
                'created_by' => $request->user()->getKey(),
            ])->save();

            // Its own page 2: changing the copy's page 2 leaves the original's alone.
            if ($template->pageTwoTemplate !== null) {
                $copy->forceFill([
                    'page_two_template_id' => $this->copyAsPageTwo($request, $template->pageTwoTemplate, $copy)->getKey(),
                ])->save();
            }

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

    /**
     * Bring a deleted (archived) layout back into the library, as a draft: it is
     * never published again by itself. Its own page 2 comes back with it.
     */
    public function restore(DocumentTemplate $template): RedirectResponse
    {
        if ($template->is_page_two) {
            return redirect()->route('templates.index')
                ->with('error', 'That is page 2 of a two-page layout. Restore its main layout instead.');
        }

        $name = $template->name;
        $typeKey = $template->documentTypeDefinition?->key;

        try {
            $restored = DB::transaction(function () use ($template) {
                $locked = DocumentTemplate::query()->whereKey($template->getKey())->lockForUpdate()->first();
                if ($locked === null || ! $locked->isArchived()) {
                    return false;
                }

                $pageTwo = $locked->pageTwoTemplate()->where('is_page_two', true)->first();
                foreach (array_filter([$locked, $pageTwo]) as $layout) {
                    $layout->forceFill(['archived_at' => null])->save();
                }

                $this->audit->log(
                    'template.restored',
                    $locked,
                    new: ['name' => $locked->name],
                    description: "Restored template '{$locked->name}' as a draft.",
                );

                return true;
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', "'{$name}' could not be restored and nothing was changed. Try again; if it keeps happening, tell the system administrator.");
        }

        $to = redirect()->route('templates.index', $typeKey ? ['open' => $typeKey] : []);

        return $restored
            ? $to->with('success', "Layout '{$name}' was restored as a draft.")
            : $to->with('info', "'{$name}' is not deleted, so there is nothing to restore.");
    }

    /**
     * Delete a layout from the library.
     *
     * - Published: refused (a scan may be using it); publish another layout first.
     * - Records were read with it: it is archived (hidden, kept) so every record keeps
     *   its layout. Its own page 2 goes with it.
     * - Nothing was read with it: it is erased, with its page 2 and stored sample.
     *
     * The checks are repeated under a row lock, so a record saved or a layout published
     * at the same moment cannot slip past them. Any failure leaves everything as it was.
     */
    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        // A second click, or an old tab: already gone from the library.
        if ($template->isArchived()) {
            return redirect()->route('templates.index')
                ->with('info', "'{$template->name}' was already deleted.");
        }

        if ($redirect = $this->toMainLayout($template)) {
            return $redirect;
        }

        $template->loadMissing('documentTypeDefinition');
        $name = $template->name;
        $samplePath = $template->sample_path;

        try {
            $outcome = DB::transaction(function () use ($template) {
                $locked = DocumentTemplate::query()->whereKey($template->getKey())->lockForUpdate()->first();
                if ($locked === null || $locked->isArchived()) {
                    return ['gone', 0];
                }

                // A scan may be using it right now: another layout is published first.
                if ($locked->is_active) {
                    return ['published', 0];
                }

                // Pages still in progress do not count (the delete window warns about them):
                // deleting only clears their link (nullOnDelete) and the hourly prune removes them.
                $records = $locked->records()->count();
                $pageTwo = $locked->pageTwoTemplate()->where('is_page_two', true)->first();

                if ($records > 0) {
                    // Records keep the layout they were read with, so it is hidden, not erased.
                    $now = now();
                    foreach (array_filter([$locked, $pageTwo]) as $layout) {
                        $layout->forceFill(['archived_at' => $now])->save();
                    }
                    $this->audit->log(
                        'template.archived',
                        $locked,
                        old: ['name' => $locked->name, 'records' => $records],
                        description: "Deleted template '{$locked->name}'. It is kept for its {$records} saved "
                            .Str::plural('record', $records).'.',
                    );

                    return ['archived', $records];
                }

                if ($pageTwo !== null) {
                    $locked->forceFill(['page_two_template_id' => null])->save();
                    $pageTwo->delete();
                }

                $this->audit->log(
                    'template.deleted',
                    $locked,
                    old: [
                        'name' => $locked->name,
                        'document_type' => $template->documentTypeDefinition?->key ?? $locked->doc_type->value,
                        'was_published' => false,
                        'sample_document' => $locked->sample_original_name,
                    ],
                    description: "Deleted template '{$locked->name}'.",
                );
                $locked->delete();

                return ['deleted', 0];
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', "'{$name}' could not be deleted and nothing was changed. Try again; if it keeps happening, tell the system administrator.");
        }

        [$result, $records] = $outcome;

        if ($result === 'gone') {
            return redirect()->route('templates.index')->with('info', "'{$name}' was already deleted.");
        }
        if ($result === 'published') {
            return back()->with('error', "'{$name}' is the published layout. Publish another layout first, then delete this one.");
        }

        if ($result === 'deleted' && $samplePath) {
            // The layout is gone; a sample file that cannot be removed now is only left behind.
            try {
                $this->samples->deletePath($samplePath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('templates.index')->with('success', $result === 'archived'
            ? "Layout '{$name}' was deleted. The {$records} saved ".Str::plural('record', $records).' read with it '.($records === 1 ? 'keeps' : 'keep').' it.'
            : "Layout '{$name}' was deleted.");
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
            // The page's width : height as the builder drew it: its sample's shape.
            'page_ratio' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'grouping_mode' => ['required', Rule::in(['auto', 'custom'])],
            // The layout of page 2, when a spread's two pages are laid out
            // differently: another layout of the same certificate type.
            'page_two_template_id' => [
                'nullable',
                'integer',
                Rule::exists('document_templates', 'id')
                    ->where('document_type_id', $request->integer('document_type_id'))
                    ->whereNull('page_two_template_id')
                    ->when($template, fn ($rule) => $rule->whereNot('id', $template->getKey()))
                    // Another layout's page 2 is not offered; this layout's own may stay.
                    ->where(fn ($query) => $query->where('is_page_two', false)
                        ->when($template?->page_two_template_id, fn ($query, $own) => $query->orWhere('id', $own))),
            ],
            'publish' => ['sometimes', 'boolean'],
            // "Save and publish" chosen in the builder although something is missing.
            'publish_anyway' => ['sometimes', 'boolean'],
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
            'page_two_template_id.exists' => 'Choose another layout of this certificate type for page 2. A layout that has a page 2 of its own cannot be one.',
        ]);
        $validated['page_two_template_id'] = isset($validated['page_two_template_id'])
            ? (int) $validated['page_two_template_id']
            : null;
        // Page 2 drawn in the builder (page_two_json) replaces any layout chosen from the list.
        if ($request->filled('page_two_json')) {
            $validated['page_two_template_id'] = $template?->page_two_template_id;
        }

        // Without one (an older form or an API call), the shape it had stays.
        $validated['page_ratio'] = round((float) ($validated['page_ratio']
            ?? $template?->page_ratio ?? DocumentTemplate::DEFAULT_PAGE_RATIO), 5);
        $ratio = $validated['page_ratio'];
        $validated['fields'] = array_values($validated['fields'] ?? []);
        [$validated['columns'], $validated['ruled_ys']] = $this->checkedLedgerGrid(
            $validated['columns'] ?? [],
            $validated['ruled_ys'] ?? [],
            $validated['fields'],
            $ratio,
        );

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
     * The ledger grid, checked and normalised: null when a layout has none.
     *
     * The Align step places the row lines relative to the columns' band (the
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
     * Rules for what a field or column holds, and how Verify checks its value.
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
     * Publish the layout when nothing stops it (see publishProblems), and say
     * what does otherwise: then nothing is published. `$anyway`: the admin saw
     * what is missing in the builder and chose "Save and publish", so it is
     * published all the same (the audit log keeps what was missing).
     *
     * @return list<string> what stopped it; empty when it was published
     */
    private function publishIfReady(DocumentTemplate $template, bool $anyway = false): array
    {
        $problems = $this->publishProblems($template->fresh() ?? $template);
        if ($problems !== [] && ! $anyway) {
            return $problems;
        }

        $this->publishTemplate($template, $problems);

        return [];
    }

    /**
     * What stops a layout from being published, in words for the admin:
     * a sample page to check it against, real names instead of "Column 3",
     * and a column or field that holds the person's name (on page 1 or 2).
     *
     * @return list<string>
     */
    private function publishProblems(DocumentTemplate $template): array
    {
        $template->loadMissing(['fields', 'pageTwoTemplate.fields']);
        $layouts = array_filter([$template, $template->pageTwoTemplate]);
        $problems = [];

        if (! $template->sample_path) {
            $problems[] = 'Add a sample page: open a scan of this book in the builder and save.';
        }

        $unnamed = [];
        $hasName = false;
        foreach ($layouts as $layout) {
            $items = [
                ...$layout->fields->map(fn (DocumentTemplateField $f) => ['name' => $f->name, 'role' => $f->role])->all(),
                ...array_map(fn (array $c) => ['name' => (string) $c['name'], 'role' => $c['role'] ?? null], $layout->columns ?? []),
            ];
            foreach ($items as $item) {
                if (preg_match('/^(column|field)\s*\d+$/i', trim($item['name']))) {
                    $unnamed[] = trim($item['name']);
                }
                $hasName = $hasName || $item['role'] === 'name';
            }
        }

        if ($unnamed !== []) {
            $shown = array_slice(array_values(array_unique($unnamed)), 0, 4);
            $more = count(array_unique($unnamed)) - count($shown);
            $problems[] = 'Give every column a real name (still named: '.implode(', ', $shown)
                .($more > 0 ? " and {$more} more" : '').').';
        }
        if (! $hasName) {
            $problems[] = "Mark the column or field that holds the person's name (Field settings → Holds → Name).";
        }

        // A two-page register: row N of page 1 and row N of page 2 are one person.
        if (count($layouts) === 2) {
            [$one, $two] = array_values($layouts);
            $names = fn (DocumentTemplate $layout) => collect([
                ...$layout->fields->pluck('name')->all(),
                ...array_column($layout->columns ?? [], 'name'),
            ])->map(fn ($name) => trim((string) $name))->filter()->unique(fn ($name) => mb_strtolower($name));
            $second = $names($two)->map(fn ($name) => mb_strtolower($name))->flip();
            $both = $names($one)->filter(fn ($name) => $second->has(mb_strtolower($name)))->values();
            if ($both->isNotEmpty()) {
                $problems[] = 'Give each page its own column names (on both pages: '.$both->take(3)->implode(', ')
                    .($both->count() > 3 ? ' and '.($both->count() - 3).' more' : '').').';
            }

            $rows = fn (DocumentTemplate $layout) => count($layout->columns ?? []) > 0 && count($layout->ruled_ys ?? []) >= 2
                ? count($layout->ruled_ys) - 1
                : null;
            [$rowsOne, $rowsTwo] = [$rows($one), $rows($two)];
            if ($rowsOne !== null && $rowsTwo !== null && $rowsOne !== $rowsTwo) {
                $problems[] = "Page 1 has {$rowsOne} ".Str::plural('row', $rowsOne)." and page 2 has {$rowsTwo}. "
                    .'Row 1 of both pages is one person, so both pages need the same number of rows.';
            }
        }

        return $problems;
    }

    /** @param  list<string>  $problems */
    private function notPublishedMessage(string $lead, array $problems): string
    {
        return $lead.'. It was not published yet: '.implode(' ', $problems);
    }

    /**
     * Publish the layout scans use, retiring the previous one atomically.
     * The row lock prevents two simultaneous publishes from leaving two active
     * layouts for the same certificate type.
     *
     * @param  list<string>  $missing  what the publish checks found, when published anyway
     */
    private function publishTemplate(DocumentTemplate $template, array $missing = []): void
    {
        $sameType = DocumentTemplate::query()
            ->where('document_type_id', $template->document_type_id)
            ->lockForUpdate()
            ->get();

        $previous = $sameType
            ->where('is_active', true)
            ->where('id', '!=', $template->getKey());

        // Retiring a layout is not an edit: its "Updated" time stays what it was.
        DocumentTemplate::withoutTimestamps(fn () => DocumentTemplate::query()
            ->whereIn('id', $sameType->modelKeys())
            ->whereKeyNot($template->getKey())
            ->update(['is_active' => false]));

        // Always write the target row. Its in-memory state may be stale if
        // another publish was waiting on the same lock.
        $template->forceFill(['is_active' => true, 'published_at' => now()])->save();

        $this->audit->log(
            'template.activated',
            $template,
            old: ['previous_active' => $previous->pluck('name')->values()->all()],
            new: ['active' => $template->name, ...($missing !== [] ? ['published_with_missing' => $missing] : [])],
            description: "Published template '{$template->name}' for {$template->typeLabel()}."
                .($missing !== [] ? ' Published with checks not done: '.implode(' ', $missing) : ''),
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
        bool $pageTwo = false,
    ): DocumentTemplate {
        // Page 2 is linked by savePageTwo(), never shared from the payload.
        $template = DocumentTemplate::create([
            'name' => $validated['name'],
            'doc_type' => $documentType->legacyType()->value,
            'document_type_id' => $documentType->getKey(),
            'page_ratio' => $validated['page_ratio'],
            'description' => $validated['description'] ?? null,
            'grouping_mode' => $validated['grouping_mode'],
            'columns' => $validated['columns'],
            'ruled_ys' => $validated['ruled_ys'],
            'is_active' => false,
            'created_by' => $request->user()->getKey(),
            'parent_id' => $parent?->getKey(),
        ]);
        $template->forceFill(['is_page_two' => $pageTwo, 'is_version' => $parent !== null])->save();

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
        ?array $pageTwo = null,
    ): RedirectResponse {
        $publish = (bool) ($validated['publish'] ?? false);

        $problems = [];
        $version = DB::transaction(function () use ($request, $template, $validated, $documentType, $publish, $pageTwo, &$problems) {
            $version = $this->createLayout($request, $validated, $documentType, $template);
            // The new version gets its own page 2 (a copy): the old version keeps its own.
            $this->savePageTwo($request, $version, $pageTwo, $documentType, $validated['page_two_template_id'], fresh: true);

            if ($publish) {
                $problems = $this->publishIfReady($version, (bool) ($validated['publish_anyway'] ?? false));
            }

            return $version;
        });

        $records = $template->records()->count();
        $kept = $records > 0
            ? " '{$template->name}' is kept unchanged for the {$records} ".Str::plural('record', $records).' read with it.'
            : " '{$template->name}' is kept unchanged.";
        $redirect = redirect()->route('templates.edit', $version);

        if ($problems !== []) {
            return $redirect->with('error', $this->notPublishedMessage('Saved as a new draft version', $problems).$kept);
        }

        return $redirect->with('success', $publish
                ? 'Saved as a new version and published.'.$kept
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
     * Whether a save changes what scans read with the layout: its markers,
     * field settings, row lines or person rows. Name, notes and sample (with
     * its page shape) only describe it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function layoutChanged(DocumentTemplate $template, array $validated): bool
    {
        $template->loadMissing('fields');

        $saved = $this->layoutSignature(
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
            $validated['grouping_mode'],
            $validated['columns'] ?? [],
            $validated['ruled_ys'] ?? [],
            $validated['fields'],
        ) || $template->page_two_template_id !== ($validated['page_two_template_id'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<float|int|string>  $ruled
     * @param  list<array<string, mixed>>  $fields
     */
    private function layoutSignature(
        string $grouping,
        array $columns,
        array $ruled,
        array $fields,
    ): string {
        $round = fn ($value) => round((float) $value, 5);
        $angle = fn ($value) => round((float) ($value ?? 0), 1);

        return json_encode([
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
