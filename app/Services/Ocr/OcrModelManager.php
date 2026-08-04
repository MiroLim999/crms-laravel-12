<?php

namespace App\Services\Ocr;

use App\Models\OcrModel;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the OCR service's on-disk models with the durable CRMS registry.
 *
 * The weights are the service's business; this class owns the Laravel-side facts:
 * which model Staff scan with, who installed it, and whether a folder CRMS knew
 * about has since disappeared.
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
                // Still list what CRMS knows about, so the page says something
                // useful while the service is down.
                'models' => OcrModel::whereNull('disk_deleted_at')
                    ->orderByDesc('is_active')->orderBy('key')->get()
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
                ->filter(fn (array $model) => $model['disk_deleted_at'] === null)
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
            'registered_at' => $local?->created_at,
            'registrar' => $local?->registrar?->name,
            'notes' => $local?->notes,
            'disk_deleted_at' => $local?->disk_deleted_at,
            'model' => $local,
        ];
    }

    /**
     * Keys the service can actually serve, for validating a submitted choice.
     *
     * @return list<string>
     */
    public function servableKeys(): array
    {
        if ($this->client->isKnownUnreachable()) {
            return [];
        }

        try {
            return collect($this->client->models()['models'])->pluck('key')->all();
        } catch (OcrServiceException) {
            return [];
        }
    }

    public function activate(string $key, User $actor): OcrModel
    {
        if (! in_array($key, $this->servableKeys(), true)) {
            throw new OcrServiceException(
                "The OCR service cannot serve '{$key}'. Rescan, or check ml/models/.",
            );
        }

        $model = $this->register($key, $actor);
        $previous = OcrModel::active();

        if ($previous?->key === $key) {
            return $model;      // nothing changed; do not write a no-op audit entry
        }

        $model->activate();

        $this->audit->log(
            'ocr_model.activated',
            $model,
            old: $previous ? ['active_model' => $previous->key] : null,
            new: ['active_model' => $key],
            description: "Set '{$key}' as the OCR model used for scanning.",
            actor: $actor,
        );

        return $model;
    }

    /**
     * Ensure a registry row exists and revive it if the service reports it again.
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
            $model->registered_by = $actor->getKey();
        }

        if ($model->isDirty()) {
            $action = $wasDeleted
                ? 'ocr_model.restored'
                : ($artifactReplaced ? 'ocr_model.replaced' : 'ocr_model.registered');
            $description = $wasDeleted
                ? "Restored OCR model '{$key}' to the registry."
                : ($artifactReplaced
                    ? "Replaced the files behind OCR model '{$key}'."
                    : "Updated registration metadata for OCR model '{$key}'.");

            $this->audit->saveAndLog($action, $model, $description, $actor);
        }

        return $model;
    }

    /**
     * Register a model after FastAPI has accepted the direct browser upload.
     *
     * FastAPI's inventory is the source of truth for both existence and file names;
     * browser-supplied metadata never enters the audit trail. Repeating this call
     * after a lost response is intentionally a no-op.
     */
    public function registerInstalled(string $name, User $actor): OcrModel
    {
        $remote = collect($this->client->models()['models'])
            ->first(fn (array $model) => ($model['key'] ?? null) === $name);

        if ($remote === null) {
            throw new OcrServiceException(
                "The OCR service does not report '{$name}' as installed. Rescan, or check ml/models/.",
            );
        }

        $files = collect($remote['files'] ?? [])
            ->filter(fn (mixed $file) => is_string($file))
            ->values()
            ->all();

        return DB::transaction(function () use ($name, $files, $actor) {
            $existing = OcrModel::query()->where('key', $name)->lockForUpdate()->first();

            if ($existing !== null && $existing->disk_deleted_at === null) {
                return $existing;
            }

            $model = $this->register($name, $actor, artifactReplaced: true);

            $this->audit->log(
                'ocr_model.added',
                $model,
                new: ['key' => $name, 'files' => $files],
                description: "Installed OCR model '{$name}'.",
                actor: $actor,
            );

            return $model;
        });
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
            $model->label = $resolved;
            $this->audit->saveAndLog(
                'ocr_model.renamed',
                $model,
                "Renamed OCR model '{$key}' to '{$resolved}'.",
                $actor,
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
            ])->save();
        }

        $this->audit->saveAndLog(
            'ocr_model.deleted',
            $model,
            "Deleted OCR model '{$key}' permanently from disk.",
            $actor,
        );

        $model->delete();
    }

    /**
     * Fetch the service model list once and durably reconcile every transition.
     * This is the documented fallback for a model folder placed on disk by hand.
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
                // The base model is pulled from the Hugging Face cache, so it is
                // never really absent. A tombstone on it is always wrong.
                if ($model->disk_deleted_at !== null) {
                    $model->fill(['disk_deleted_at' => null, 'disk_deleted_by' => null]);
                    $this->audit->saveAndLog(
                        'ocr_model.restored',
                        $model,
                        'Cleared an invalid tombstone from the protected base OCR model.',
                        $actor,
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
                    $actor,
                );
                $counts['tombstoned']++;
            }
        }

        return $counts;
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
                "{$verb} is blocked while '{$key}' is the model Staff scan with. "
                .'Select another model and save settings first.',
            );
        }
    }
}
