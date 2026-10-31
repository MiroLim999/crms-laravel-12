<?php

namespace App\Services\Ocr;

use App\Jobs\ProcessDocumentPage;
use App\Models\OcrModel;
use App\Models\PageRun;

/**
 * Read-only benchmark profiles for the OCR workspace radar.
 *
 * Only complete, artifact-bound test-set evaluations imported from a model's
 * evaluation-report.json are shown. Production scans are deliberately excluded:
 * observed Staff corrections are not a controlled comparison between models.
 */
class OcrModelPerformance
{
    /**
     * @var list<array{key: string, label: string, axis: string, description: string}>
     */
    private const METRICS = [
        [
            'key' => 'character_accuracy',
            'label' => 'Character accuracy',
            'axis' => 'Characters',
            'description' => 'Test-set character accuracy calculated as 1 minus CER.',
        ],
        [
            'key' => 'word_accuracy',
            'label' => 'Word accuracy',
            'axis' => 'Words',
            'description' => 'Test-set word accuracy calculated as 1 minus WER.',
        ],
        [
            'key' => 'exact_match',
            'label' => 'Exact text match',
            'axis' => 'Exact match',
            'description' => 'Test samples whose predicted text exactly matches the reference.',
        ],
    ];

    /**
     * @return array{selected: string|null, metrics: array<int, array<string, string>>, models: list<array<string, mixed>>}
     */
    public function summary(iterable $inventoryModels = []): array
    {
        $models = OcrModel::query()
            ->whereNull('disk_deleted_at')
            ->orderByDesc('is_active')
            ->orderBy('key')
            ->get()
            ->keyBy('key');
        $profiles = [];
        $seen = [];

        foreach ($inventoryModels as $inventory) {
            if (! is_array($inventory)) {
                continue;
            }

            $key = trim((string) ($inventory['key'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $model = $models->get($key);
            $profiles[] = $this->profile(
                $key,
                (string) ($inventory['label'] ?? $model?->label ?? $key),
                (bool) ($inventory['is_active'] ?? $model?->is_active),
                $model,
            );
            $seen[$key] = true;
        }

        foreach ($models as $model) {
            if (isset($seen[$model->key])) {
                continue;
            }

            $profiles[] = $this->profile(
                $model->key,
                $model->label ?: $model->key,
                $model->is_active,
                $model,
            );
        }

        $speeds = $this->readingSpeeds();
        foreach ($profiles as &$profile) {
            $profile['reading'] = $speeds[$profile['key']] ?? ['lines' => 0, 'ms_per_line' => null];
        }
        unset($profile);

        $active = collect($profiles)->firstWhere('is_active', true);
        $evaluated = collect($profiles)->firstWhere('has_data', true);

        return [
            'selected' => $active['key'] ?? $evaluated['key'] ?? ($profiles[0]['key'] ?? null),
            'metrics' => self::METRICS,
            'models' => $profiles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $key, string $label, bool $isActive, ?OcrModel $model): array
    {
        $complete = $model?->hasEvaluation() ?? false;
        $displayLabel = ($key === 'base' && ($label === 'base' || ! $label))
            ? 'TrOCR Base (Baseline)'
            : ($label ?: $key);

        if (! $complete) {
            return [
                'key' => $key,
                'label' => $label ?: $key,
                'display_label' => $displayLabel,
                'is_active' => $isActive,
                'has_data' => false,
                'source' => null,
                'source_label' => 'No benchmark',
                'evidence' => 'Upload a model ZIP containing a valid evaluation-report.json from the locked test set.',
                'record_count' => 0,
                'field_count' => 0,
                'scores' => $this->emptyScores(),
                'cer' => null,
                'wer' => null,
                'dataset' => null,
                'split' => null,
                'sample_count' => 0,
                'evaluated_at' => null,
            ];
        }

        $samples = number_format($model->evaluation_sample_count);
        $date = $model->evaluated_at?->format('M j, Y');

        return [
            'key' => $key,
            'label' => $label ?: $key,
            'display_label' => $displayLabel,
            'is_active' => $isActive,
            'has_data' => true,
            'source' => 'evaluation',
            'source_label' => 'Locked test set',
            'evidence' => "{$model->evaluation_dataset} · test · {$samples} samples"
                .($date ? " · evaluated {$date}" : ''),
            'record_count' => 0,
            'field_count' => $model->evaluation_sample_count,
            'scores' => [
                'character_accuracy' => $this->percentage(1 - $model->cer),
                'word_accuracy' => $this->percentage(1 - $model->wer),
                'exact_match' => $this->percentage($model->exact_match),
            ],
            'cer' => $model->cer !== null ? $this->percentage($model->cer) : null,
            'wer' => $model->wer !== null ? $this->percentage($model->wer) : null,
            'dataset' => $model->evaluation_dataset,
            'split' => $model->evaluation_split ?? 'test',
            'sample_count' => $model->evaluation_sample_count,
            'evaluated_at' => $date,
        ];
    }

    /**
     * How fast each model read lines in real scans: the time spent reading over
     * the lines read, from every finished Scan the page job has logged. This is
     * speed on this machine, not on the test set, and it is independent of
     * whether the model has a benchmark.
     *
     * @return array<string, array{lines: int, ms_per_line: int|null}>
     */
    private function readingSpeeds(): array
    {
        return PageRun::query()
            ->where('outcome', PageRun::COMPLETED)
            ->whereIn('mode', [ProcessDocumentPage::MODE_FULL, ProcessDocumentPage::MODE_READ])
            ->whereNotNull('read_ms')
            ->whereNotNull('ocr_model_key')
            ->where('lines', '>', 0)
            ->get(['ocr_model_key', 'read_ms', 'lines'])
            ->groupBy('ocr_model_key')
            ->map(fn ($runs) => [
                'lines' => (int) $runs->sum('lines'),
                'ms_per_line' => (int) round($runs->sum('read_ms') / $runs->sum('lines')),
            ])
            ->all();
    }

    private function percentage(float $rate): float
    {
        return round(max(0.0, min(1.0, $rate)) * 100, 1);
    }

    /** @return array{character_accuracy: null, word_accuracy: null, exact_match: null} */
    private function emptyScores(): array
    {
        return [
            'character_accuracy' => null,
            'word_accuracy' => null,
            'exact_match' => null,
        ];
    }
}
