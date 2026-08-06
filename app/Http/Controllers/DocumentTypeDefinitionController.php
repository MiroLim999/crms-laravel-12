<?php

namespace App\Http\Controllers;

use App\Models\DocumentTypeDefinition;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentTypeDefinitionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $name = $this->validatedName($request);
        $base = Str::slug($name) ?: 'document';
        $key = 'custom-'.Str::limit($base, 54, '').'-'.Str::lower(Str::random(6));

        $type = DocumentTypeDefinition::create([
            'key' => $key,
            'name' => $name,
            'short_name' => $name,
            'icon' => 'bx-file-blank',
            'is_system' => false,
        ]);

        $this->audit->log(
            'document-type.created',
            $type,
            new: ['key' => $type->key, 'name' => $type->name],
            description: "Created custom document type '{$type->name}'.",
        );

        return redirect()
            ->route('templates.create', ['type' => $type->key])
            ->with('success', "Document type '{$type->name}' created. Build its first layout.");
    }

    public function update(Request $request, DocumentTypeDefinition $documentType): RedirectResponse
    {
        abort_if($documentType->is_system, 403, 'Built-in document types cannot be renamed.');

        $name = $this->validatedName($request, $documentType);
        $old = $documentType->name;
        $documentType->fill(['name' => $name, 'short_name' => $name]);
        $this->audit->saveAndLog(
            'document-type.renamed',
            $documentType,
            "Renamed document type '{$old}' to '{$name}'.",
        );

        return redirect()
            ->route('templates.index', ['open' => $documentType->key])
            ->with('success', "Document type renamed to '{$name}'.");
    }

    private function validatedName(Request $request, ?DocumentTypeDefinition $current = null): string
    {
        $validated = $request->validate([
            'document_type_name' => ['required', 'string', 'max:120'],
        ]);
        $name = preg_replace('/\s+/', ' ', trim($validated['document_type_name'])) ?? '';

        if ($name === '') {
            throw ValidationException::withMessages(['document_type_name' => 'Enter a document type name.']);
        }

        $duplicate = DocumentTypeDefinition::query()
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['document_type_name' => 'A document type with this name already exists.']);
        }

        return $name;
    }
}
