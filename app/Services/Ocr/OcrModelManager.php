<?php

namespace App\Services\Ocr;

use App\Models\OcrModel;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Reconciles the OCR service's on-disk models with the CRMS registry.
 *
 * The service is authoritative about which models exist; this table is
 * authoritative about which one Staff use and what we know about its quality.
 * Every lifecycle action is audit logged, because deleting a model folder is
 * destructive and promoting one changes what every Staff scan runs against.
 */
class OcrModelManager
{
    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Merge the service's model list with our stored metadata.
     *
     * @return array{reachable: bool, error: string|null, device: string|null, models: Collection<int, array<string, mixed>>}
     */
    public function overview(): array
    {
        $health = $this->client->health();

        if (! $health['reachable']) {
            // Still show what we know about, flagged as unavailable, so a Super
            // Admin can see history while the service is down.
            return [
                'reachable' => false,
                'error' => $health['error'],
                'device' => null,
                'models' => OcrModel::orderByDesc('is_active')->orderBy('key')->get()
                    ->map(fn (OcrModel $m) => $this->describe($m, null))
                    ->values(),
            ];
        }

        $registry = OcrModel::all()->keyBy('key');
        $onDisk = collect($health['models']);

        $models = $onDisk->map(function (array $remote) use ($registry) {
            return $this->describe($registry->get($remote['key']), $remote);
        });

        // Registered models the service no longer sees, e.g. a folder removed by
        // hand. Surfaced rather than hidden so the mismatch is visible.
        $missing = $registry->keys()->diff($onDisk->pluck('key'))
            ->map(fn (string $key) => $this->describe($registry->get($key), null));

        return [
            'reachable' => true,
            'error' => null,
            'device' => $health['device'],
            'models' => $models->concat($missing)
                ->sortByDesc(fn (array $m) => (int) $m['is_active'])
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $remote  Null when the service cannot see it.
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
            'model' => $local,
        ];
    }

    /**
     * Promote a model so Staff scanning uses it. Requires the service to actually
     * be able to serve it - promoting a phantom model would break every scan.
     */
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
     * Ensure a registry row exists for a model the service reports.
     */
    public function register(string $key, User $actor, ?string $label = null): OcrModel
    {
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

        $model = $this->register($key, $actor);

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

    /**
     * Destructive: removes the model folder from disk.
     */
    public function delete(string $key, User $actor): void
    {
        $this->guardNotBase($key, 'deleted');
        $this->guardNotActive($key, 'Delete');

        $this->client->deleteModel($key);

        $model = OcrModel::where('key', $key)->first();

        $this->audit->log(
            'ocr_model.deleted',
            $model,
            old: ['key' => $key],
            description: "Deleted OCR model '{$key}' from disk.",
            actor: $actor,
        );

        $model?->delete();
    }

    /**
     * Record evaluation figures produced by the offline scripts, so the decision
     * to promote a model is traceable.
     *
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
