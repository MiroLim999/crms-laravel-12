<?php

namespace App\Services\Ocr;

use App\Models\MlDataset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Reconciles the OCR service's on-disk datasets with the durable CRMS registry.
 */
class DatasetManager
{
    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{reachable: bool, error: string|null, datasets: Collection<int, array<string, mixed>>}
     */
    public function overview(): array
    {
        if ($this->client->isKnownUnreachable()) {
            return $this->offline($this->client->health()['error']);
        }

        try {
            $remote = collect($this->client->datasets());
        } catch (OcrServiceException $e) {
            return $this->offline($e->getMessage());
        }

        $registry = MlDataset::with('uploader')->orderBy('name')->get()->keyBy('name');
        $datasets = $remote->map(
            fn (array $row) => $this->describe($registry->get($row['name']), $row)
        );
        $missing = $registry->keys()->diff($remote->pluck('name'))
            ->map(fn (string $name) => $this->describe($registry->get($name), null));

        return [
            'reachable' => true,
            'error' => null,
            'datasets' => $datasets->concat($missing)->sortBy('name')->values(),
        ];
    }

    /**
     * @return array{reachable: bool, error: string|null, datasets: Collection<int, array<string, mixed>>}
     */
    private function offline(?string $error): array
    {
        return [
            'reachable' => false,
            'error' => $error ?? 'The OCR service is not reachable.',
            'datasets' => MlDataset::with('uploader')->orderBy('name')->get()
                ->map(fn (MlDataset $dataset) => $this->describe($dataset, null))
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $remote
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
            'disk_deleted_at' => $local?->disk_deleted_at,
            'disk_deleted_by' => $local?->disk_deleted_by,
            'dataset' => $local,
        ];
    }

    public function register(string $name, User $actor): MlDataset
    {
        $dataset = MlDataset::firstOrNew(['name' => $name]);

        if (! $dataset->exists) {
            $dataset->fill(['uploaded_by' => $actor->getKey()])->save();
            $this->audit->log(
                'ml_dataset.registered',
                $dataset,
                new: ['name' => $name],
                description: "Registered dataset '{$name}'.",
                actor: $actor,
            );

            return $dataset;
        }

        if ($dataset->disk_deleted_at !== null) {
            $dataset->fill([
                'disk_deleted_at' => null,
                'disk_deleted_by' => null,
            ]);
            $this->audit->saveAndLog(
                'ml_dataset.restored',
                $dataset,
                "Restored dataset '{$name}' to the registry.",
            );
        }

        return $dataset;
    }

    /**
     * @param  list<array{name: string, relative_path: string, path: string, size: int}>  $files
     */
    public function createFromFiles(string $name, array $files, User $actor): MlDataset
    {
        return $this->persistCreated(
            $this->client->createDatasetFromFiles($name, $files),
            $name,
            $actor,
        );
    }

    public function createFromArchive(string $name, string $zipPath, User $actor): MlDataset
    {
        return $this->persistCreated(
            $this->client->createDataset($name, $zipPath),
            $name,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistCreated(array $result, string $requestedName, User $actor): MlDataset
    {
        $resolved = $result['name'] ?? $requestedName;
        $dataset = MlDataset::firstOrNew(['name' => $resolved]);

        $dataset->fill([
            'uploaded_by' => $actor->getKey(),
            'disk_deleted_at' => null,
            'disk_deleted_by' => null,
            'validation' => null,
            'is_valid' => null,
            'validated_at' => null,
        ]);
        $this->applySummary($dataset, $result['summary'] ?? []);
        $this->applyValidation($dataset, $result['validation'] ?? null);

        $this->audit->saveAndLog(
            'ml_dataset.uploaded',
            $dataset,
            "Uploaded dataset '{$resolved}' ({$dataset->total_images} images).",
        );

        return $dataset;
    }

    public function validate(string $name, User $actor): MlDataset
    {
        $report = $this->client->validateDataset($name);
        $dataset = $this->register($name, $actor);
        $this->applyValidation($dataset, $report);

        $summary = collect($this->client->datasets())->firstWhere('name', $name);
        if ($summary !== null) {
            $this->applySummary($dataset, $summary);
        }

        $dataset->save();

        return $dataset;
    }

    public function delete(string $name, User $actor): void
    {
        $this->client->deleteDataset($name);
        $dataset = MlDataset::firstOrNew(['name' => $name]);
        $dataset->fill([
            'disk_deleted_at' => now(),
            'disk_deleted_by' => $actor->getKey(),
            'is_valid' => false,
        ]);

        $this->audit->saveAndLog(
            'ml_dataset.deleted',
            $dataset,
            "Deleted dataset '{$name}' from disk.",
        );
    }

    /**
     * Fetch the service dataset list once and durably reconcile every transition.
     *
     * @return array{remote: int, registered: int, restored: int, tombstoned: int, updated: int}
     */
    public function reconcile(User $actor): array
    {
        $remote = collect($this->client->datasets())->keyBy('name');
        $registry = MlDataset::all()->keyBy('name');
        $counts = [
            'remote' => $remote->count(),
            'registered' => 0,
            'restored' => 0,
            'tombstoned' => 0,
            'updated' => 0,
        ];

        foreach ($remote as $name => $summary) {
            $local = $registry->get($name);
            if ($local === null) {
                $counts['registered']++;
            } elseif ($local->disk_deleted_at !== null) {
                $counts['restored']++;
            }

            $dataset = $this->register($name, $actor);
            $this->applySummary($dataset, $summary);

            if ($dataset->isDirty()) {
                $this->audit->saveAndLog(
                    'ml_dataset.reconciled',
                    $dataset,
                    "Updated the on-disk summary for dataset '{$name}'.",
                );
                $counts['updated']++;
            }
        }

        foreach ($registry as $name => $dataset) {
            if ($remote->has($name) || $dataset->disk_deleted_at !== null) {
                continue;
            }

            $dataset->fill([
                'disk_deleted_at' => now(),
                'disk_deleted_by' => null,
                'is_valid' => false,
            ]);
            $this->audit->saveAndLog(
                'ml_dataset.marked_missing',
                $dataset,
                "Marked dataset '{$name}' deleted because it is absent from disk.",
            );
            $counts['tombstoned']++;
        }

        return $counts;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function trainable(): Collection
    {
        return $this->overview()['datasets']->filter(fn (array $dataset) => $dataset['trainable'])->values();
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
