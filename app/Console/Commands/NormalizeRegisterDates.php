<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Support\RegistryFields;
use Illuminate\Console\Command;

/**
 * Register dates saved before they were kept as YYYY-MM-DD ("2-11-57",
 * "Jan, 07,1978"), written as YYYY-MM-DD, page by page: as written where they
 * can be read, else repaired from the page's usual month (a one-digit misread,
 * "22-22-57"). A date that cannot be worked out is left, and listed.
 *
 *   php artisan crms:normalize-dates            change them
 *   php artisan crms:normalize-dates --dry-run  only show what would change
 */
class NormalizeRegisterDates extends Command
{
    protected $signature = 'crms:normalize-dates {--dry-run : Show what would change without saving}';

    protected $description = 'Write the register tables\' dates as YYYY-MM-DD';

    public function handle(AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $left = [];

        foreach (RegistryFields::types() as $type) {
            $model = RegistryFields::model($type);
            $fields = array_values(array_intersect(RegistryFields::DATE_FIELDS, RegistryFields::keys($type)));
            $pages = $model::query()->orderBy('book_no')->orderBy('page_no')->orderBy('id')->get()
                ->groupBy(fn ($row) => $row->book_no.'|'.$row->page_no);

            foreach ($pages as $rows) {
                $rows = $rows->values();
                $data = $rows->map(fn ($row) => $row->only($fields))->all();
                $changes = RegistryFields::normalizedDates($data);

                foreach ($rows as $index => $row) {
                    foreach ($changes[$index] ?? [] as $field => $iso) {
                        $this->line("{$type} book {$row->book_no} page {$row->page_no} reg {$row->reg_no}: {$field} {$row->{$field}} -> {$iso}");
                        $row->{$field} = $iso;
                        $changed++;
                    }
                    foreach ($fields as $field) {
                        $value = (string) $row->{$field};
                        if ($value !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $left[] = "{$type} book {$row->book_no} page {$row->page_no} reg {$row->reg_no}: {$field} \"{$value}\"";
                        }
                    }
                    if (! $dry && $row->isDirty()) {
                        $row->save();
                    }
                }
            }
        }

        $this->info(($dry ? 'Would change ' : 'Changed ')."{$changed} ".str('date')->plural($changed).'.');
        if ($left !== []) {
            $this->warn(count($left).' could not be worked out (correct them in the record):');
            foreach ($left as $line) {
                $this->line("  {$line}");
            }
        }
        if (! $dry && $changed > 0) {
            $audit->log('registry.dates_normalized', new: ['dates' => $changed], description: "Wrote {$changed} register ".str('date')->plural($changed).' as YYYY-MM-DD (crms:normalize-dates).');
        }

        return self::SUCCESS;
    }
}
