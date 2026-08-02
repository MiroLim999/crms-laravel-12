<?php

namespace App\Services\Ocr;

use App\Models\OcrModel;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Reconciles the OCR service's on-disk models with the durable CRMS registry.
 */
class OcrModelManager
{
    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{reachable: bool, error: string|null, device: string|null, models: Collection<int, array<string, mixed>>}
     */
    public function overview(): array
    {
        $health = $this->client->health();

        if (! $health['reachable']) {
            return [
                'reachable' => false,
                'error' => $health['error'],
                'device' => null,
                'models' => OcrModel::orderByDesc('is_active')->orderBy('key')->get()
                    ->map(fn (OcrModel $model) => $this->describe($model, null))
                    ->values(),
            ];
        }

        $registry = OcrModel::all()->keyBy('key');
        $onDisk = collect($health['models']);
        $models = $onDisk->map(
            fn (array $remote) => $this->describe($registry->get($remote['key']), $remote)
        );
        $missing = $registry->keys()->diff($onDisk->pluck('key'))
            ->map(fn (string $key) => $this->describe($registry->get($key), null));

        return [
            'reachable' => true,
            'error' => null,
            'device' => $health['device'],
            'models' => $models->concat($missing)
                ->sortByDesc(fn (array $model) => (int) $model['is_active'])
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $remote
     * @return array<string, mixed>
     */
    private function describe(?OcrModel $local, ?array $remote): array
    {
        $key = $local?->key ?? $remote['key'];

        return [
            'key' => $key,
            'label' => $local?->label ?? $remote['label'] ?? $key,
            'is_base' => $key === 'base',
            'on_disk' => $remote !== null,
            'loaded' => (bool) ($remote['loaded'] ?? false),
            'is_active' => (bool) $local?->is_active,
            'registered' => $local !== null,
            'notes' => $local?->notes,
            'cer' => $local?->cer,
            'wer' => $local?->wer,
            'exact_match' => $local?->exact_match,
            'evaluated_at' => $local?->evaluated_at,
            'disk_deleted_at' => $local?->disk_deleted_at,
            'disk_deleted_by' => $local?->disk_deleted_by,
            'model' => $local,
        ];
    }

    public function activate(string $key, User $actor): OcrModel
    {
        $available = collect($this->client->models()['models'])->pluck('key');

        if (! $available->contains($key)) {
            throw new OcrServiceException(
                "The OCR service cannot serve '{$key}'. Rescan, or check the Models folder.",
            );
        }

        $model = $this->register($key, $actor);
        $previous = OcrModel::active();
        $model->activate();

        $this->audit->log(
            'ocr_model.activated',
            $model,
            old: $previous ? ['active_model' => $previous->key] : null,
            new: ['active_model' => $key],
            description: "Set '{$key}' as the active OCR model for scanning.",
            actor: $actor,
        );

        return $model;
    }

    /**
     * Ensure a registry row exists and revive it if the service reports it again.
     * Metrics survive ordinary registration, but not an artifact replacement.
     */
    public function register(
        string $key,
        User $actor,
        ?string $label = null,
        bool $artifactReplaced = false,
    ): OcrModel {
        $model = OcrModel::firstOrNew(['key' => $key]);

        if (! $model->exists) {
            $model->fill([
                'label' => $label ?? $key,
                'registered_by' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                'ocr_model.registered',
                $model,
                new: ['key' => $key],
                description: "Registered OCR model '{$key}'.",
                actor: $actor,
            );

            return $model;
        }

        $wasDeleted = $model->disk_deleted_at !== null;

        if ($wasDeleted) {
            $model->fill([
                'disk_deleted_at' => null,
                'disk_deleted_by' => null,
                'registered_by' => $actor->getKey(),
                'label' => $label ?? $model->label,
            ]);
        } elseif ($model->registered_by === null) {
            $model->registered_by = $actor->getKey();
        }

        if ($artifactReplaced) {
            $model->fill([
                'registered_by' => $actor->getKey(),
                'cer' => null,
                'wer' => null,
                'exact_match' => null,
                'evaluated_at' => null,
            ]);
        }

        if ($model->isDirty()) {
            $action = $wasDeleted
                ? 'ocr_model.restored'
                : ($artifactReplaced ? 'ocr_model.replaced' : 'ocr_model.registered');
            $description = $wasDeleted
                ? "Restored OCR model '{$key}' to the registry."
                : ($artifactReplaced
                    ? "Replaced OCR model '{$key}' and cleared stale evaluation metrics."
                    : "Updated registration metadata for OCR model '{$key}'.");

            $this->audit->saveAndLog($action, $model, $description, $actor);
        }

        return $model;
    }

    /**
     * @param  list<array{name: string, path: string}>  $files
     */
    public function add(string $name, array $files, User $actor): OcrModel
    {
        $result = $this->client->addModel($name, $files);
        $key = $result['name'] ?? $name;
        $model = $this->register($key, $actor, artifactReplaced: true);

        $this->audit->log(
            'ocr_model.added',
            $model,
            new: ['key' => $key, 'files' => $result['saved'] ?? []],
            description: "Uploaded OCR model '{$key}'.",
            actor: $actor,
        );

        return $model;
    }

    public function rename(string $key, string $newName, User $actor): string
    {
        $this->guardNotBase($key, 'renamed');
        $this->guardNotActive($key, 'Rename');

        $result = $this->client->renameModel($key, $newName);
        $resolved = $result['name'] ?? $newName;
        $model = OcrModel::where('key', $key)->first();

        if ($model) {
            $model->key = $resolved;
            $this->audit->saveAndLog(
                'ocr_model.renamed',
                $model,
                "Renamed OCR model '{$key}' to '{$resolved}'.",
            );
        }

        return $resolved;
    }

    public function delete(string $key, User $actor): void
    {
        $this->guardNotBase($key, 'deleted');
        $this->guardNotActive($key, 'Delete');
        $this->client->deleteModel($key);

        $model = OcrModel::firstOrNew(['key' => $key]);
        if (! $model->exists) {
            $model->fill([
                'label' => $key,
                'registered_by' => $actor->getKey(),
            ]);
        }

        $model->fill([
            'disk_deleted_at' => now(),
            'disk_deleted_by' => $actor->getKey(),
            'is_active' => false,
        ]);

        $this->audit->saveAndLog(
            'ocr_model.deleted',
            $model,
            "Deleted OCR model '{$key}' from disk.",
        );
    }

    /**
     * Fetch the service model list once and durably reconcile every transition.
     *
     * @return array{remote: int, registered: int, restored: int, tombstoned: int}
     */
    public function reconcile(User $actor): array
    {
        $remote = collect($this->client->models()['models'])->keyBy('key');
        $registry = OcrModel::all()->keyBy('key');
        $counts = ['remote' => $remote->count(), 'registered' => 0, 'restored' => 0, 'tombstoned' => 0];

        foreach ($remote as $key => $row) {
            $local = $registry->get($key);
            if ($local === null) {
                $counts['registered']++;
            } elseif ($local->disk_deleted_at !== null) {
                $counts['restored']++;
            }

            $this->register(
                $key,
                $actor,
                $row['label'] ?? null,
                artifactReplaced: $local?->disk_deleted_at !== null,
            );
        }

        foreach ($registry as $key => $model) {
            if ($remote->has($key)) {
                continue;
            }

            if ($key === 'base') {
                if ($model->disk_deleted_at !== null) {
                    $model->fill(['disk_deleted_at' => null, 'disk_deleted_by' => null]);
                    $this->audit->saveAndLog(
                        'ocr_model.restored',
                        $model,
                        'Cleared an invalid tombstone from the protected base OCR model.',
                    );
                    $counts['restored']++;
                }

                continue;
            }

            if ($model->disk_deleted_at === null) {
                $model->fill([
                    'disk_deleted_at' => now(),
                    'disk_deleted_by' => null,
                    'is_active' => false,
                ]);
                $this->audit->saveAndLog(
                    'ocr_model.marked_missing',
                    $model,
                    "Marked OCR model '{$key}' deleted because it is absent from disk.",
                );
                $counts['tombstoned']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array{cer?: float|null, wer?: float|null, exact_match?: float|null, notes?: string|null}  $metrics
     */
    public function recordEvaluation(string $key, array $metrics, User $actor): OcrModel
    {
        $model = $this->register($key, $actor);
        $model->fill([
            'cer' => $metrics['cer'] ?? null,
            'wer' => $metrics['wer'] ?? null,
            'exact_match' => $metrics['exact_match'] ?? null,
            'notes' => $metrics['notes'] ?? $model->notes,
            'evaluated_at' => now(),
        ]);

        $this->audit->saveAndLog(
            'ocr_model.evaluated',
            $model,
            "Recorded evaluation metrics for '{$key}'.",
        );

        return $model;
    }

    private function guardNotBase(string $key, string $verb): void
    {
        if ($key === 'base') {
            throw new OcrServiceException("The base model cannot be {$verb}.");
        }
    }

    private function guardNotActive(string $key, string $verb): void
    {
        if (OcrModel::where('key', $key)->where('is_active', true)->exists()) {
            throw new OcrServiceException(
                "{$verb} is blocked while '{$key}' is the active model. Activate another model first.",
            );
        }
    }
}
