<?php

namespace App\Services\Lines;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Markers posted for line detection, as page fractions: ledger columns, ruled
 * row lines and rectangle fields. Shared by the Align step (Snap, Detect,
 * Scan) and the Template Builder's test on its sample.
 */
final class GeometryInput
{
    /**
     * The geometry can arrive as one JSON input (geometry_json), which keeps a
     * large ledger clear of PHP's max_input_vars.
     */
    public static function hydrate(Request $request): void
    {
        if (! $request->filled('geometry_json')) {
            return;
        }

        try {
            $geometry = json_decode((string) $request->input('geometry_json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['geometry' => 'The aligned markers could not be read. Try again.']);
        }

        if (is_array($geometry)) {
            $request->merge(['geometry' => [
                'columns' => $geometry['columns'] ?? [],
                'ruled_ys' => $geometry['ruled_ys'] ?? [],
                'fields' => $geometry['fields'] ?? [],
            ]]);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'geometry' => ['required', 'array'],
            'geometry.columns' => ['present', 'array', 'max:60'],
            'geometry.columns.*.name' => ['required', 'string', 'max:500'],
            'geometry.columns.*.box' => ['required', 'array', 'size:4'],
            'geometry.columns.*.box.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.columns.*.angle' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'geometry.ruled_ys' => ['present', 'array', 'max:400'],
            'geometry.ruled_ys.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.fields' => ['present', 'array', 'max:450'],
            'geometry.fields.*.name' => ['required', 'string', 'max:500'],
            'geometry.fields.*.box' => ['required', 'array', 'size:4'],
            'geometry.fields.*.box.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'geometry.fields.*.angle' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'geometry.fields.*.person_group' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'geometry.fields.*.person_field_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The validated geometry, checked and in the shape line_markers.py reads.
     *
     * @param  array<string, mixed>  $geometry
     * @return array<string, mixed>
     */
    public static function checked(array $geometry): array
    {
        $columns = array_values($geometry['columns'] ?? []);
        $ruled = array_values(array_map('floatval', $geometry['ruled_ys'] ?? []));
        $fields = array_values($geometry['fields'] ?? []);

        $errors = [];
        if ($columns === [] && $fields === []) {
            $errors['geometry'] = 'There are no markers to read. Reset the layout and try again.';
        }
        if ($columns !== [] && count($ruled) < 2) {
            $errors['geometry.ruled_ys'] = 'This layout has columns but no ruled row lines.';
        }
        for ($i = 1; $i < count($ruled); $i++) {
            if ($ruled[$i] <= $ruled[$i - 1]) {
                $errors['geometry.ruled_ys'] = 'Ruled row lines must run from top to bottom.';
                break;
            }
        }
        foreach (['columns' => $columns, 'fields' => $fields] as $key => $items) {
            foreach ($items as $index => $item) {
                [$x, $y, $w, $h] = array_map('floatval', $item['box']);
                if ($w <= 0 || $h <= 0 || $x + $w > 1.00001 || $y + $h > 1.00001) {
                    $errors["geometry.{$key}.{$index}.box"] = 'A marker extends beyond the page.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Every marker has its own tilt; only a turned one carries an angle.
        $angle = fn (array $marker) => round((float) ($marker['angle'] ?? 0), 1);

        return [
            'columns' => array_map(fn (array $c) => [
                'name' => (string) $c['name'],
                'box' => array_map('floatval', $c['box']),
                ...($angle($c) != 0.0 ? ['angle' => $angle($c)] : []),
            ], $columns),
            'ruled_ys' => $ruled,
            'fields' => array_map(fn (array $f) => [
                'name' => (string) $f['name'],
                'box' => array_map('floatval', $f['box']),
                'person_group' => isset($f['person_group']) ? (int) $f['person_group'] : null,
                'person_field_order' => isset($f['person_field_order']) ? (int) $f['person_field_order'] : null,
                ...($angle($f) != 0.0 ? ['angle' => $angle($f)] : []),
            ], $fields),
        ];
    }
}
