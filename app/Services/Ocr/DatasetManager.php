<?php

namespace App\Services\Ocr;

use App\Models\MlDataset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Reconciles the OCR service's on-disk datasets with the CRMS registry.
 *
 * Same split of authority as models: the service owns what exists on disk, this
 * table owns what CRMS knows about it - who uploaded it and what validation said.
 *
 * Validation is not optional. A manifest pointing at missing files fails deep into
 * an epoch after hours of GPU time, so a dataset is validated on upload and cannot
 * be trained on until it passes.
 */
class DatasetManager
{
    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Merge the service's dataset list with our stored metadata.
     *
     * @return array{reachable: bool, error: string|null, datasets: Collection<int, array<string, mixed>>}
     */
    public function overview(): array
    {
        // Skip a call already known to fail. Without this the page pays a second
        // connection-refused wait to learn what health just told it.
        if ($this->client->isKnownUnreachable()) {
            return $this->offline($this->client->health()['error']);
        }

        try {
            $remote = collect($this->client->datasets());
        } catch (OcrServiceException $e) {
            return $this->offline($e->getMessage());
        }

        // Eager loaded: the uploader is rendered on every row of the table.
        $registry = MlDataset::with('uploader')->orderBy('name')->get()->keyBy('name');

        $datasets = $remote->map(
            fn (array $row) => $this->describe($registry->get($row['name']), $row)
        );

        // Registered datasets the service can no longer see, e.g. a folder deleted
        // by hand. Flagged rather than hidden so the mismatch is visible.
        $missing = $registry->keys()->diff($remote->pluck('name'))
            ->map(fn (string $name) => $this->describe($registry->get($name), null));

        return [
            'reachable' => true,
            'error' => null,
            'datasets' => $datasets->concat($missing)->sortBy('name')->values(),
        ];
    }

    /**
     * Show what we know about, flagged, so history stays readable while the service
     * is down.
     *
     * @return array{reachable: bool, error: string|null, datasets: Collection<int, array<string, mixed>>}
     */
    private function offline(?string $error): array
    {
        return [
            'reachable' => false,
            'error' => $error ?? 'The OCR service is not reachable.',
            'datasets' => MlDataset::with('uploader')->orderBy('name')->get()
                ->map(fn (MlDataset $d) => $this->describe($d, null))
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $remote  Null when the service cannot see it.
     * @return array<string, mixed>
     */
    private function describe(?MlDataset $local, ?array $remote): array
    {
        $name = $local?->name ?? $remote['name'];
        $images = $remote['images'] ?? [];
        $usable = $remote['usable'] ?? [];

        return [
            'name' => $name,
            'on_disk' => $remote !== null,
            'registered' => $local !== null,
            'train' => $images['train'] ?? $local?->train_count ?? 0,
            'val' => $images['val'] ?? $local?->val_count ?? 0,
            'test' => $images['test'] ?? $local?->test_count ?? 0,
            'total' => $remote['total_images'] ?? $local?->total_images ?? 0,
            'usable_train' => $usable['train'] ?? $local?->usableTrainRows() ?? 0,
            'size' => $remote['size_bytes'] ?? $local?->size_bytes ?? 0,
            'has_manifest' => $remote['has_manifest'] ?? true,
            'is_valid' => $local?->is_valid,
            'validated_at' => $local?->validated_at,
            'validation' => $local?->validation,
            'trainable' => $remote !== null && (bool) $local?->isTrainable(),
            'uploader' => $local?->uploader?->name,
            'dataset' => $local,
        ];
    }

    /**
     * Register a dataset the service reports, so it has a row to hang metadata on.
     */
    public function register(string $name, User $actor): MlDataset
    {
        $dataset = MlDataset::firstOrNew(['name' => $name]);

        if (! $dataset->exists) {
            $dataset->fill(['uploaded_by' => $actor->getKey()])->save();
        }

        return $dataset;
    }

    /**
     * Hand an assembled zip to the service, then validate what came out of it.
     */
    public function createFromArchive(string $name, string $zipPath, User $actor): MlDataset
    {
        $result = $this->client->createDataset($name, $zipPath);
        $resolved = $result['name'] ?? $name;

        $dataset = $this->register($resolved, $actor);

        $this->applySummary($dataset, $result['summary'] ?? []);
        $this->applyValidation($dataset, $result['validation'] ?? null);
        $dataset->save();

        $this->audit->log(
            'ml_dataset.uploaded',
            $dataset,
            new: [
                'name' => $resolved,
                'total_images' => $dataset->total_images,
                'size_bytes' => $dataset->size_bytes,
                'is_valid' => $dataset->is_valid,
            ],
            description: "Uploaded dataset '{$resolved}' ({$dataset->total_images} images).",
            actor: $actor,
        );

        return $dataset;
    }

    /**
     * Re-run the service's sanity report and store it.
     */
    public function validate(string $name, User $actor): MlDataset
    {
        $report = $this->client->validateDataset($name);

        $dataset = $this->register($name, $actor);
        $this->applyValidation($dataset, $report);

        // Refresh the counts at the same time: they come from the same walk of disk.
        $summary = collect($this->client->datasets())->firstWhere('name', $name);
        if ($summary !== null) {
            $this->applySummary($dataset, $summary);
        }

        $dataset->save();

        return $dataset;
    }

    /**
     * Destructive: removes the folder and every image in it.
     */
    public function delete(string $name, User $actor): void
    {
        $this->client->deleteDataset($name);

        $dataset = MlDataset::where('name', $name)->first();

        $this->audit->log(
            'ml_dataset.deleted',
            $dataset,
            old: [
                'name' => $name,
                'total_images' => $dataset?->total_images,
                'size_bytes' => $dataset?->size_bytes,
            ],
            description: "Deleted dataset '{$name}' from disk.",
            actor: $actor,
        );

        $dataset?->delete();
    }

    /**
     * Datasets that may be trained on right now: on disk and passing validation.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trainable(): Collection
    {
        return $this->overview()['datasets']->filter(fn (array $d) => $d['trainable'])->values();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function applySummary(MlDataset $dataset, array $summary): void
    {
        $images = $summary['images'] ?? [];

        $dataset->fill([
            'train_count' => $images['train'] ?? 0,
            'val_count' => $images['val'] ?? 0,
            'test_count' => $images['test'] ?? 0,
            'total_images' => $summary['total_images'] ?? 0,
            'size_bytes' => $summary['size_bytes'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $report
     */
    private function applyValidation(MlDataset $dataset, ?array $report): void
    {
        if ($report === null || $report === []) {
            return;
        }

        $dataset->fill([
            'validation' => $report,
            'is_valid' => (bool) ($report['ok'] ?? false),
            'validated_at' => now(),
        ]);
    }
}
