<?php

namespace App\Services;

use App\Models\CivilRecord;
use App\Models\RecordDownload;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Many records' checked values as one table, for the archive's download.
 *
 * Each record is laid out by RecordCsv (one row per person). Records of
 * different document types or layouts have different columns, so the table's
 * columns are all of them in first-seen order, and a record leaves the ones it
 * does not have empty. That needs the full column list before the first row is
 * written, so the records are read twice, one chunk at a time: once for the
 * columns, once for the rows. Memory stays flat however large the archive is.
 *
 * Values are raw. The CSV writer neutralises formula-looking text itself.
 */
class ArchiveExport
{
    public function __construct(private readonly RecordCsv $recordCsv) {}

    /**
     * @param  Builder<CivilRecord>  $records  unordered; chunking orders by id
     * @return list<string>
     */
    public function header(Builder $records): array
    {
        $header = [];

        foreach ($this->each($records) as $record) {
            foreach ($this->recordCsv->table($record, csvSafe: false)['header'] as $column) {
                $header[$column] = true;
            }
        }

        return array_keys($header);
    }

    /**
     * The rows of every record, then, only once the last row has been handed to
     * the writer, a download entry for each record. A download that is cut off
     * halfway never gets there, so those records stay "not downloaded".
     *
     * @param  Builder<CivilRecord>  $records
     * @param  list<string>  $header  from header()
     * @return Generator<int, list<string|int|null>>
     */
    public function rows(Builder $records, array $header, string $format, ?int $userId): Generator
    {
        $position = array_flip($header);
        $blank = array_fill(0, count($header), null);
        $ids = [];

        foreach ($this->each($records) as $record) {
            $ids[] = $record->getKey();
            $table = $this->recordCsv->table($record, csvSafe: false);
            $columns = array_map(fn (string $column) => $position[$column] ?? null, $table['header']);

            foreach ($table['rows'] as $row) {
                $out = $blank;
                foreach ($columns as $index => $at) {
                    if ($at !== null) {
                        $out[$at] = $row[$index] ?? null;
                    }
                }
                yield $out;
            }
        }

        self::markDownloaded($ids, $format, $userId);
    }

    /**
     * @param  list<int>  $recordIds
     */
    public static function markDownloaded(array $recordIds, string $format, ?int $userId): void
    {
        $now = now();

        foreach (array_chunk($recordIds, 500) as $chunk) {
            RecordDownload::insert(array_map(fn (int $id) => [
                'record_id' => $id,
                'user_id' => $userId,
                'format' => $format,
                'downloaded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * @param  Builder<CivilRecord>  $records
     * @return Generator<int, CivilRecord>
     */
    private function each(Builder $records): Generator
    {
        $query = (clone $records)->with(['fields', 'documentTypeDefinition']);

        foreach ($query->lazyById(50) as $record) {
            yield $record;
        }
    }
}
