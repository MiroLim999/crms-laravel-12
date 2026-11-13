<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One table as a CSV or Excel download. Rows are streamed, so a large archive
 * costs a constant amount of memory.
 *
 * Pass raw values. CSV neutralises formula-looking text itself (CsvCell::safe);
 * Excel text is written as explicit string cells, because OpenSpout would
 * otherwise turn any text starting with "=" into a live formula.
 */
final class TableDownload
{
    public const FORMATS = ['csv', 'xlsx'];

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function respond(string $format, string $basename, string $sheetName, array $header, iterable $rows): StreamedResponse
    {
        return $format === 'xlsx'
            ? self::xlsx($basename, $sheetName, $header, $rows)
            : self::csv($basename, $header, $rows);
    }

    public static function label(string $format): string
    {
        return $format === 'xlsx' ? 'Excel' : 'CSV';
    }

    private static function headers(string $type): array
    {
        // Exports are per-request and role-scoped; never let one sit in a cache.
        return ['Content-Type' => $type, 'Cache-Control' => 'no-store, no-cache'];
    }

    private static function csv(string $basename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            // Byte-order mark, so Excel shows ñ and accents correctly.
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, array_map(CsvCell::safe(...), $header));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(CsvCell::safe(...), $row));
            }
            fclose($handle);
        }, "{$basename}.csv", self::headers('text/csv; charset=UTF-8'));
    }

    private static function xlsx(string $basename, string $sheetName, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($sheetName, $header, $rows) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            // Sheet names: at most 31 characters, none of : \ / ? * [ ]
            $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $sheetName), 0, 31));
            $writer->addRow(self::xlsxRow($header, (new Style)->setFontBold()));
            foreach ($rows as $row) {
                $writer->addRow(self::xlsxRow($row));
            }
            $writer->close();
        }, "{$basename}.xlsx", self::headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    }

    /**
     * @param  list<string|int|float|null>  $values
     */
    private static function xlsxRow(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(
            fn ($value) => is_string($value) ? new Cell\StringCell($value, null) : Cell::fromValue($value),
            $values,
        ), $style);
    }
}
