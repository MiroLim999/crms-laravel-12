<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TemplateSampleStorage
{
    public const ROOT = 'template-samples';

    /**
     * @return array{sample_path: string, sample_original_name: string, sample_mime: string, sample_size: int}
     */
    public function store(DocumentTemplate $template, UploadedFile $file): array
    {
        $template->loadMissing('documentTypeDefinition');
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $extension = $this->extensionForMime($mime);
        $path = $this->pathFor($template, $extension);
        $oldPath = $template->sample_path;
        $stored = $file->storeAs(dirname($path), basename($path), 'local');

        if (! is_string($stored)) {
            throw new RuntimeException('The sample document could not be stored.');
        }

        if ($oldPath && $oldPath !== $stored) {
            $this->deletePath($oldPath);
        }

        return [
            'sample_path' => $stored,
            'sample_original_name' => $this->safeOriginalName($file),
            'sample_mime' => $mime,
            'sample_size' => (int) $file->getSize(),
        ];
    }

    /**
     * Move a stored sample after its document type or layout is renamed.
     */
    public function relocate(DocumentTemplate $template): ?string
    {
        if (! $template->sample_path) {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($template->sample_path)) {
            return $template->sample_path;
        }

        $extension = strtolower(pathinfo($template->sample_path, PATHINFO_EXTENSION));
        $destination = $this->pathFor($template, $extension);

        if ($destination === $template->sample_path) {
            return $destination;
        }

        $oldPath = $template->sample_path;
        if (! $disk->move($oldPath, $destination)) {
            throw new RuntimeException('The stored sample could not be renamed with its layout.');
        }

        $this->removeEmptyParents($oldPath);

        return $destination;
    }

    public function delete(DocumentTemplate $template): void
    {
        $this->deletePath($template->sample_path);
    }

    public function deletePath(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk('local')->delete($path);
        $this->removeEmptyParents($path);
    }

    private function pathFor(DocumentTemplate $template, string $extension): string
    {
        $type = $template->documentTypeDefinition;
        $typeSlug = Str::slug($type?->name ?? $template->doc_type->value) ?: 'document-type';
        $layoutSlug = Str::slug($template->name) ?: 'layout';
        $typeDirectory = sprintf('%03d-%s', $template->document_type_id ?? 0, $typeSlug);
        $layoutDirectory = sprintf('%05d-%s', $template->getKey(), $layoutSlug);
        $filename = "{$typeSlug}--{$layoutSlug}--sample.{$extension}";

        return self::ROOT."/{$typeDirectory}/{$layoutDirectory}/{$filename}";
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            'image/tiff' => 'tiff',
            default => throw new RuntimeException('That sample document type is not supported.'),
        };
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        return Str::limit($name ?: 'sample-document', 255, '');
    }

    private function removeEmptyParents(string $path): void
    {
        $disk = Storage::disk('local');
        $layoutDirectory = str_replace('\\', '/', dirname($path));
        $typeDirectory = str_replace('\\', '/', dirname($layoutDirectory));

        if ($layoutDirectory !== self::ROOT && $disk->allFiles($layoutDirectory) === []) {
            $disk->deleteDirectory($layoutDirectory);
        }

        if ($typeDirectory !== self::ROOT && $disk->allFiles($typeDirectory) === []) {
            $disk->deleteDirectory($typeDirectory);
        }
    }
}
