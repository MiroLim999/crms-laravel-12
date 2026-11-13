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
 * With --test-set=<name> the same lines are written as a local test set instead,
 * in the layout ml/test_finetuned.py reads, so a model can be scored on real
 * ledger lines on this computer:
 *
 *     ml/datasets/<name>/manifest.csv   filename,label,split,source,record_id,hand_corrected
 *     ml/datasets/<name>/test/*.png
 *
 * The folder holds civil registry data. It is written to local storage only and
 * must not be uploaded to Kaggle or any other outside service.
 */
class ExportTrainingLines extends Command
{
    protected $signature = 'crms:export-training
        {--out= : Destination folder (default: storage/app/private/training-exports/<timestamp>)}
        {--since= : Only records submitted on or after this date (YYYY-MM-DD)}
        {--test-set= : Write a local test set to ml/datasets/<name> for ml/test_finetuned.py instead}';

    protected $description = 'Export verified line crops and their corrected text as a TrOCR training CSV.';

    public function handle(AuditLogger $audit): int
    {
        $testSet = $this->option('test-set');
        if ($testSet !== null) {
            return $this->exportTestSet((string) $testSet, $audit);
        }

        $out = $this->option('out')
            ?: Storage::disk('local')->path('training-exports/'.now()->format('Ymd-His'));
        $since = $this->since();

        File::ensureDirectoryExists($out.DIRECTORY_SEPARATOR.'images');
        $csv = fopen($out.DIRECTORY_SEPARATOR.'labels.csv', 'w');
        fputcsv($csv, ['file_name', 'text', 'doc_id', 'column', 'row']);

        $disk = Storage::disk('local');
        $exported = 0;
        $missing = 0;

        $query = $this->verifiedLines($since);

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

    private function since(): ?Carbon
    {
        return $this->option('since') ? Carbon::parse((string) $this->option('since'))->startOfDay() : null;
    }

    /** Verified lines of submitted records that kept the crop TrOCR read. */
    private function verifiedLines(?Carbon $since)
    {
        return RecordField::query()
            ->whereNotNull('crop_path')
            ->whereNotNull('verified_value')
            ->where('verified_value', '!=', '')
            ->whereHas('record', fn ($records) => $records
                ->whereNotNull('submitted_at')
                ->when($since, fn ($q) => $q->where('submitted_at', '>=', $since)));
    }

    /**
     * Every verified line as the test split of a local dataset, so
     * `python ml/test_finetuned.py --model <key> --dataset <name>` scores a model
     * on real ledger lines. Each line is test data only: nothing here is split
     * off for training.
     */
    private function exportTestSet(string $name, AuditLogger $audit): int
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name)) {
            $this->error('Use letters, digits, - and _ for the test set name (e.g. real-lines).');

            return self::FAILURE;
        }

        $root = base_path('ml'.DIRECTORY_SEPARATOR.'datasets'.DIRECTORY_SEPARATOR.$name);
        if (File::exists($root)) {
            $this->error("{$root} already exists. Delete it or pick another name.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'test');
        $csv = fopen($root.DIRECTORY_SEPARATOR.'manifest.csv', 'w');
        fputcsv($csv, ['filename', 'label', 'split', 'source', 'record_id', 'hand_corrected']);

        $disk = Storage::disk('local');
        $exported = 0;
        $corrected = 0;
        $missing = 0;
        $records = [];

        $this->verifiedLines($this->since())->chunkById(500, function ($fields) use ($disk, $csv, $root, &$exported, &$corrected, &$missing, &$records) {
            foreach ($fields as $field) {
                if (! $disk->exists($field->crop_path)) {
                    $missing++;

                    continue;
                }

                $fileName = sprintf('%d-%d.png', $field->record_id, $field->getKey());
                File::copy($disk->path($field->crop_path), $root.DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR.$fileName);

                // The reading was changed by hand. The other lines keep the app's own
                // reading as their answer, which flatters the model that made it.
                $changed = trim((string) $field->ocr_text) !== trim((string) $field->verified_value);
                fputcsv($csv, [$fileName, $field->verified_value, 'test', 'crms', $field->record_id, $changed ? 1 : 0]);

                $exported++;
                $corrected += $changed ? 1 : 0;
                $records[$field->record_id] = true;
            }
        });

        fclose($csv);

        $audit->log(
            'training.exported',
            new: ['lines' => $exported, 'test_set' => $name, 'folder' => $root],
            description: "Exported {$exported} verified line crop(s) as local test set {$name}.",
        );

        $this->info("Wrote {$exported} real line(s) from ".count($records)." record(s) to {$root}");
        if ($missing > 0) {
            $this->warn("{$missing} crop file(s) were missing on disk and were skipped.");
        }
        $this->line("The reading was changed for {$corrected} of them. The other ".($exported - $corrected)
            .' keep the app\'s own reading as the answer, so check them before trusting a score on this set.');
        $this->line("Score a model on it:  python ml/test_finetuned.py --model <model key or base> --dataset {$name}");
        $this->warn('This folder holds civil registry data. Keep it on this computer; do not upload it to Kaggle.');

        return self::SUCCESS;
    }
}
