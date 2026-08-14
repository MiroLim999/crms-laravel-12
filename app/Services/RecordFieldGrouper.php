<?php

namespace App\Services;

use App\Models\CivilRecord;
use App\Models\RecordField;
use Illuminate\Support\Collection;

class RecordFieldGrouper
{
    /**
     * @param  Collection<int, RecordField>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function groups(Collection $fields): array
    {
        $fields = $fields->sortBy('sort_order')->values();

        if ($fields->contains(fn (RecordField $field) => $field->person_group !== null)) {
            return $this->persistedGroups($fields);
        }

        return $this->inferredGroups($fields);
    }

    /** @param array<int, array<string, mixed>> $groups */
    public function heading(CivilRecord $record, array $groups): string
    {
        $people = collect($groups)->where('kind', 'person')->values();
        $type = $record->typeShortLabel();

        if ($people->isEmpty()) {
            return $record->registry_number ?: "{$type} record #{$record->getKey()}";
        }

        $entries = $people->map(function (array $group): ?string {
            $value = trim((string) $group['fields']->first()?->verified_value);

            return $value !== '' && mb_strlen($value) <= 20 && preg_match('/\d/', $value)
                ? $value
                : null;
        });

        if ($entries->every(fn (?string $entry) => $entry !== null)) {
            $first = $entries->first();
            $last = $entries->last();

            return $first === $last
                ? "{$type} · Entry {$first}"
                : "{$type} · Entries {$first}–{$last}";
        }

        return "{$type} · {$people->count()} people";
    }

    /**
     * @param  Collection<int, RecordField>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function persistedGroups(Collection $fields): array
    {
        $groups = [];
        $details = $fields->whereNull('person_group')->values();

        if ($details->isNotEmpty()) {
            $groups[] = $this->makeGroup('document-details', 'details', 'Document details', $details);
        }

        $fields->whereNotNull('person_group')
            ->groupBy('person_group')
            ->sortKeys()
            ->each(function (Collection $personFields, int|string $personGroup) use (&$groups): void {
                $number = (int) $personGroup;
                $ordered = $personFields
                    ->sortBy(fn (RecordField $field) => $field->person_field_order ?? $field->sort_order)
                    ->values();
                $groups[] = $this->makeGroup(
                    "person-{$number}",
                    'person',
                    'Person '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                    $ordered,
                );
            });

        return $groups;
    }

    /**
     * Coordinate fallback for records submitted before person metadata was
     * snapshotted. It mirrors the validation workspace's repeated-row heuristic.
     *
     * @param  Collection<int, RecordField>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function inferredGroups(Collection $fields): array
    {
        $items = $fields
            ->filter(fn (RecordField $field) => $field->x !== null
                && $field->y !== null
                && $field->width !== null
                && $field->height !== null)
            ->map(fn (RecordField $field) => [
                'field' => $field,
                'x' => (float) $field->x,
                'width' => (float) $field->width,
                'height' => max(0.00001, (float) $field->height),
                'center_y' => (float) $field->y + max(0.00001, (float) $field->height) / 2,
            ])
            ->sortBy(fn (array $item) => [$item['center_y'], $item['x']])
            ->values();

        if ($items->isEmpty()) {
            return [$this->makeGroup('record-details', 'details', 'Record details', $fields)];
        }

        $tolerance = max(0.004, $this->median($items->pluck('height')->all()) * 0.58);
        $rows = [];

        foreach ($items as $item) {
            $closestIndex = null;
            $closestDistance = INF;

            foreach ($rows as $index => $row) {
                $distance = abs($item['center_y'] - $row['center_y']);
                if ($distance <= $tolerance && $distance < $closestDistance) {
                    $closestIndex = $index;
                    $closestDistance = $distance;
                }
            }

            if ($closestIndex === null) {
                $rows[] = ['center_y' => $item['center_y'], 'items' => [$item]];

                continue;
            }

            $rows[$closestIndex]['items'][] = $item;
            $rows[$closestIndex]['center_y'] = collect($rows[$closestIndex]['items'])->avg('center_y');
        }

        usort($rows, fn (array $a, array $b) => $a['center_y'] <=> $b['center_y']);
        foreach ($rows as &$row) {
            usort($row['items'], fn (array $a, array $b) => $a['x'] <=> $b['x']);
        }
        unset($row);

        $repeatedRows = array_values(array_filter($rows, fn (array $row) => count($row['items']) >= 3));
        if (count($repeatedRows) < 2) {
            return [$this->makeGroup('record-details', 'details', 'Record details', $fields)];
        }

        $frequencies = [];
        foreach ($repeatedRows as $row) {
            $columns = count($row['items']);
            $frequencies[$columns] = ($frequencies[$columns] ?? 0) + 1;
        }
        uksort($frequencies, function (int|string $a, int|string $b) use ($frequencies): int {
            $score = ((int) $b * $frequencies[$b]) <=> ((int) $a * $frequencies[$a]);

            return $score !== 0 ? $score : ((int) $b <=> (int) $a);
        });
        $dominantColumns = (int) array_key_first($frequencies);
        $prototype = collect($repeatedRows)->first(
            fn (array $row) => count($row['items']) === $dominantColumns,
        );

        if ($prototype === null) {
            return [$this->makeGroup('record-details', 'details', 'Record details', $fields)];
        }

        $prototypeCenters = collect($prototype['items'])
            ->map(fn (array $item) => $item['x'] + $item['width'] / 2)
            ->all();
        $typicalWidth = $this->median(collect($prototype['items'])->pluck('width')->all());
        $spacings = collect($prototypeCenters)->slice(1)->values()
            ->map(fn (float $center, int $index) => $center - $prototypeCenters[$index])
            ->all();
        $columnTolerance = max(
            0.006,
            min($typicalWidth * 0.48, ($this->median($spacings) ?: $typicalWidth) * 0.36),
        );
        $minimumColumns = max(3, (int) ceil($dominantColumns * 0.6));

        $personRows = array_values(array_filter($rows, function (array $row) use (
            $minimumColumns,
            $dominantColumns,
            $prototypeCenters,
            $columnTolerance,
        ): bool {
            $count = count($row['items']);
            if ($count < $minimumColumns || $count > $dominantColumns + 2) {
                return false;
            }

            $available = array_keys($prototypeCenters);
            $matches = 0;
            foreach ($row['items'] as $item) {
                $center = $item['x'] + $item['width'] / 2;
                $nearestKey = null;
                $nearestDistance = INF;
                foreach ($available as $availableKey => $prototypeIndex) {
                    $distance = abs($center - $prototypeCenters[$prototypeIndex]);
                    if ($distance < $nearestDistance) {
                        $nearestKey = $availableKey;
                        $nearestDistance = $distance;
                    }
                }
                if ($nearestKey !== null && $nearestDistance <= $columnTolerance) {
                    $matches++;
                    unset($available[$nearestKey]);
                }
            }

            return $matches >= max(3, (int) ceil(min($count, $dominantColumns) * 0.75));
        }));

        $minimumRows = $dominantColumns >= 6 ? 2 : 3;
        if (count($personRows) < $minimumRows) {
            return [$this->makeGroup('record-details', 'details', 'Record details', $fields)];
        }

        $personIds = collect($personRows)
            ->flatMap(fn (array $row) => collect($row['items'])->pluck('field.id'))
            ->all();
        $details = $fields->reject(fn (RecordField $field) => in_array($field->getKey(), $personIds, true))->values();
        $groups = [];

        if ($details->isNotEmpty()) {
            $groups[] = $this->makeGroup('document-details', 'details', 'Document details', $details);
        }

        foreach ($personRows as $index => $row) {
            $number = $index + 1;
            $rowFields = collect($row['items'])->pluck('field')->values();
            $groups[] = $this->makeGroup(
                "person-{$number}",
                'person',
                'Person '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                $rowFields,
            );
        }

        return $groups;
    }

    /**
     * @param  Collection<int, RecordField>  $fields
     * @return array<string, mixed>
     */
    private function makeGroup(string $id, string $kind, string $label, Collection $fields): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'identity' => $kind === 'person' ? $this->personIdentity($fields) : null,
            'fields' => $fields,
            'field_count' => $fields->count(),
            'corrected_count' => $fields->filter->wasCorrected()->count(),
        ];
    }

    /** @param Collection<int, RecordField> $fields */
    private function personIdentity(Collection $fields): string
    {
        if ($fields->count() === 11) {
            $childName = trim((string) $fields->get(2)?->verified_value);
            if ($childName !== '') {
                return $childName;
            }
        }

        return $fields
            ->map(fn (RecordField $field) => [
                'text' => trim((string) $field->verified_value),
                'width' => (float) ($field->width ?? 0),
            ])
            ->filter(fn (array $candidate) => mb_strlen($candidate['text']) >= 3
                && preg_match('/[\pL]/u', $candidate['text']))
            ->sortByDesc('width')
            ->first()['text'] ?? "{$fields->count()} verified fields";
    }

    /** @param array<int, float|int> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
            : (float) $values[$middle];
    }
}
