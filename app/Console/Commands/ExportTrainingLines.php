<?php

namespace App\Console\Commands;

use App\Models\RecordField;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Export verified lines as TrOCR training data.
 *
 * Writes each verified line's crop and its corrected text to a folder:
 *
 *     <out>/labels.csv     file_name,text,doc_id,column,row
 *     <out>/images/*.png
 *
 * The images are byte-for-byte copies of the crops TrOCR read in the app (the
 * masked crop from line_markers.py, or the re-crop after a manual fix), never
 * re-made, so training always sees exactly what the app reads.
 *
 * Records captured before line outlines existed have no stored crop and are
 * skipped: re-cropping them now could not guarantee the same image.
 *
 * The folder holds civil registry data. It is written to local storage only.
 */
class ExportTrainingLines extends Command
{
    protected $signature = 'crms:export-training
        {--out= : Destination folder (default: storage/app/private/training-exports/<timestamp>)}
        {--since= : Only records submitted on or after this date (YYYY-MM-DD)}';

    protected $description = 'Export verified line crops and their corrected text as a TrOCR training CSV.';

    public function handle(AuditLogger $audit): int
    {
        $out = $this->option('out')
            ?: Storage::disk('local')->path('training-exports/'.now()->format('Ymd-His'));
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'))->startOfDay() : null;

        File::ensureDirectoryExists($out.DIRECTORY_SEPARATOR.'images');
        $csv = fopen($out.DIRECTORY_SEPARATOR.'labels.csv', 'w');
        fputcsv($csv, ['file_name', 'text', 'doc_id', 'column', 'row']);

        $disk = Storage::disk('local');
        $exported = 0;
        $missing = 0;

        $query = RecordField::query()
            ->whereNotNull('crop_path')
            ->whereNotNull('verified_value')
            ->where('verified_value', '!=', '')
            ->whereHas('record', fn ($records) => $records
                ->whereNotNull('submitted_at')
                ->when($since, fn ($q) => $q->where('submitted_at', '>=', $since)));

        $query->chunkById(500, function ($fields) use ($disk, $csv, $out, &$exported, &$missing) {
            foreach ($fields as $field) {
                if (! $disk->exists($field->crop_path)) {
                    $missing++;

                    continue;
                }

                $fileName = sprintf('images/%d-%d.png', $field->record_id, $field->getKey());
                File::copy($disk->path($field->crop_path), $out.DIRECTORY_SEPARATOR.$fileName);

                fputcsv($csv, [
                    $fileName,
                    $field->verified_value,
                    $field->record_id,
                    $field->line_column ?? $field->name,
                    $field->line_row ?? $field->person_group,
                ]);
                $exported++;
            }
        });

        fclose($csv);

        $withoutCrop = RecordField::query()
            ->whereNull('crop_path')
            ->whereNotNull('verified_value')
            ->count();

        $audit->log(
            'training.exported',
            new: ['lines' => $exported, 'since' => $since?->toDateString(), 'folder' => $out],
            description: "Exported {$exported} verified line crop(s) as training data.",
        );

        $this->info("Exported {$exported} line(s) to {$out}");
        if ($missing > 0) {
            $this->warn("{$missing} crop file(s) were missing on disk and were skipped.");
        }
        if ($withoutCrop > 0) {
            $this->line("{$withoutCrop} verified field(s) predate line outlines and have no stored crop; they were not exported.");
        }

        return self::SUCCESS;
    }
}
