<?php

namespace App\Services\Lines;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Bridge to ml/line_markers.py.
 *
 * Every call is a local subprocess in the line-detection Python environment
 * (see config/services.php). Pages and crops never leave the machine, and the
 * Python side is the only place that knows how a line is outlined, placed in
 * a row, or cropped - this class only moves files and JSON.
 */
class LineMarkers
{
    /**
     * Outline, flag and crop every line on an aligned page.
     *
     * @param  array<string, mixed>  $geometry  Template aligned to the page, in page fractions.
     * @return array<string, mixed> The decoded lines.json.
     */
    public function process(string $pagePath, array $geometry, string $outDirectory): array
    {
        File::ensureDirectoryExists($outDirectory);
        $geometryPath = $outDirectory.DIRECTORY_SEPARATOR.'geometry.json';
        File::put($geometryPath, json_encode($geometry, JSON_THROW_ON_ERROR));

        $this->run(['process', '--page', $pagePath, '--geometry', $geometryPath, '--out', $outDirectory]);

        $linesPath = $outDirectory.DIRECTORY_SEPARATOR.'lines.json';
        if (! File::exists($linesPath)) {
            throw new LineMarkersException('Line detection finished without writing its results.');
        }

        return json_decode(File::get($linesPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Straighten a page, fit the template to its own table, and outline every line.
     *
     * The straightened image replaces $pagePath. The result carries the
     * rotation ("deskew"), how the template was fitted ("fit"), and the
     * geometry that was used ("geometry", page fractions).
     *
     * @param  array<string, mixed>  $geometry  Template markers as Staff left them, page fractions.
     * @return array<string, mixed> The decoded lines.json.
     */
    public function detect(string $pagePath, array $geometry, string $outDirectory): array
    {
        File::ensureDirectoryExists($outDirectory);
        $geometryPath = $outDirectory.DIRECTORY_SEPARATOR.'geometry.json';
        File::put($geometryPath, json_encode($geometry, JSON_THROW_ON_ERROR));

        $this->run(['detect', '--page', $pagePath, '--geometry', $geometryPath, '--out', $outDirectory]);

        $linesPath = $outDirectory.DIRECTORY_SEPARATOR.'lines.json';
        if (! File::exists($linesPath)) {
            throw new LineMarkersException('Detection finished without writing its results.');
        }

        return json_decode(File::get($linesPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Crop one hand-drawn outline and place it in the page's grid.
     *
     * @param  list<list<float>>  $polygon  Page pixels.
     * @return array{bbox: list<int>, column_index: int|null, row: int|null, flags: list<string>}
     */
    public function crop(string $pagePath, array $polygon, string $outPath, ?string $linesPath = null): array
    {
        File::ensureDirectoryExists(dirname($outPath));
        $polygonPath = $outPath.'.polygon.json';
        File::put($polygonPath, json_encode($polygon, JSON_THROW_ON_ERROR));

        try {
            $arguments = ['crop', '--page', $pagePath, '--polygon', $polygonPath, '--out', $outPath];
            if ($linesPath !== null && File::exists($linesPath)) {
                array_push($arguments, '--lines', $linesPath);
            }

            $result = $this->run($arguments);
        } finally {
            File::delete($polygonPath);
        }

        return [
            'bbox' => $result['bbox'] ?? [0, 0, 0, 0],
            'column_index' => $result['column_index'] ?? null,
            'row' => $result['row'] ?? null,
            'flags' => $result['flags'] ?? [],
        ];
    }

    /**
     * Printed rules on a template sample, with a suggested ledger grid.
     *
     * @return array<string, mixed>
     */
    public function grid(string $imagePath): array
    {
        return $this->run(['grid', '--page', $imagePath]);
    }

    /**
     * Snap to table: the markers fitted to the page's printed table, plus every
     * printed rule found (for magnetic edges). No handwriting is detected, so
     * it takes well under a second.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{fit: array<string, mixed>, geometry: array<string, mixed>, lines: array{vertical: list<float>, horizontal: list<float>}, size: list<int>}
     */
    public function snap(string $imagePath, array $geometry): array
    {
        $geometryPath = tempnam(sys_get_temp_dir(), 'crms-snap-');
        File::put($geometryPath, json_encode($geometry, JSON_THROW_ON_ERROR));
        try {
            return $this->run(['snap', '--page', $imagePath, '--geometry', $geometryPath]);
        } finally {
            File::delete($geometryPath);
        }
    }

    /**
     * The interpreter that runs line_markers.py.
     *
     * An explicit LINE_MARKERS_PYTHON wins. Otherwise the Kraken environment is
     * used when it exists, then the project's main environment, which can still
     * crop older templates' rectangles but cannot detect lines.
     */
    public function python(): string
    {
        $configured = config('services.line_markers.python');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        foreach ([
            'ml/.venv-kraken/Scripts/python.exe',
            'ml/.venv-kraken/bin/python',
            '.venv/Scripts/python.exe',
            '.venv/bin/python',
        ] as $candidate) {
            if (File::exists(base_path($candidate))) {
                return base_path($candidate);
            }
        }

        return 'python';
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function run(array $arguments): array
    {
        $result = Process::timeout((int) config('services.line_markers.timeout', 600))
            ->path(base_path())
            ->env([
                'PYTHONIOENCODING' => 'utf-8',
                'PYTHONWARNINGS' => 'ignore',
                'LINE_MARKERS_DEVICE' => (string) config('services.line_markers.device', 'auto'),
            ])
            ->run([$this->python(), config('services.line_markers.script'), ...$arguments]);

        $summary = $this->lastJsonLine($result->output());

        if (! $result->successful() || ($summary['ok'] ?? false) !== true) {
            $reason = $summary['error'] ?? trim($result->errorOutput()) ?: 'no output';
            throw new LineMarkersException('Line detection failed: '.mb_strimwidth($reason, 0, 400, '…'));
        }

        return $summary;
    }

    /**
     * The script prints one JSON summary as its last stdout line; libraries may
     * print other things before it.
     *
     * @return array<string, mixed>
     */
    private function lastJsonLine(string $output): array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
