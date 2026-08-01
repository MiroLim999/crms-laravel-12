<?php

namespace App\Services\Ocr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the evaluation charts produced by the offline scripts.
 *
 * `ml/test_trocr.py` and `ml/test_finetuned.py` write timestamped PNGs into
 * ml/evaluation-metrics/{base,finetuned}/. Surfacing them on the OCR page is what
 * turns "the metrics look good" into a decision a Super Admin can actually make
 * before promoting a model.
 */
class EvaluationCharts
{
    /** Relative to the repo root. Mirrors metrics.py's DEFAULT_METRICS_DIR. */
    private const DIRECTORY = 'ml/evaluation-metrics';

    /**
     * @return Collection<string, Collection<int, array{name: string, variant: string, modified: Carbon, url: string}>>
     */
    public static function all(): Collection
    {
        return collect(['base', 'finetuned'])
            ->mapWithKeys(fn (string $variant) => [$variant => self::forVariant($variant)]);
    }

    /**
     * @return Collection<int, array{name: string, variant: string, modified: Carbon, url: string}>
     */
    public static function forVariant(string $variant): Collection
    {
        $path = base_path(self::DIRECTORY.DIRECTORY_SEPARATOR.$variant);

        if (! is_dir($path)) {
            return collect();
        }

        return collect(glob($path.DIRECTORY_SEPARATOR.'*.png') ?: [])
            ->map(fn (string $file) => [
                'name' => basename($file),
                'variant' => $variant,
                'modified' => Carbon::createFromTimestamp(filemtime($file)),
                'url' => route('ocr.chart', ['variant' => $variant, 'name' => basename($file)]),
            ])
            ->sortByDesc('modified')
            ->values();
    }

    /**
     * Resolve a chart to an absolute path, refusing anything outside the
     * Evaluation Metrics directory.
     */
    public static function resolve(string $variant, string $name): ?string
    {
        if (! in_array($variant, ['base', 'finetuned'], true)) {
            return null;
        }

        // Reject traversal outright rather than trying to normalise it.
        if ($name !== basename($name) || ! str_ends_with(strtolower($name), '.png')) {
            return null;
        }

        $root = realpath(base_path(self::DIRECTORY));
        $file = realpath(base_path(self::DIRECTORY.DIRECTORY_SEPARATOR.$variant.DIRECTORY_SEPARATOR.$name));

        if ($root === false || $file === false || ! str_starts_with($file, $root)) {
            return null;
        }

        return $file;
    }
}
