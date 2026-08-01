<?php

namespace App\Enums;

/**
 * Certificate types the registry handles, carried over from the prototype's
 * FIELD_TEMPLATES in web/js/config.js.
 */
enum DocumentType: string
{
    case Birth = 'birth';
    case Death = 'death';
    case Marriage = 'marriage';

    public function label(): string
    {
        return match ($this) {
            self::Birth => 'Birth Certificate',
            self::Death => 'Death Certificate',
            self::Marriage => 'Marriage Certificate',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Birth => 'Birth',
            self::Death => 'Death',
            self::Marriage => 'Marriage',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Birth => 'bx-baby-carriage',
            self::Death => 'bx-file',
            self::Marriage => 'bx-heart',
        };
    }

    /**
     * Default field boxes, ported verbatim from web/js/config.js. Used to seed a
     * starter template per document type; Super Admins adjust them afterwards in
     * the template builder.
     *
     * @return list<array{name: string, x: float, y: float, width: float, height: float}>
     */
    public function defaultFields(): array
    {
        $f = fn (string $name, float $x, float $y, float $w, float $h) => [
            'name' => $name, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
        ];

        return match ($this) {
            self::Birth => [
                $f('Child Full Name', 0.30, 0.28, 0.45, 0.05),
                $f('Date of Birth', 0.30, 0.37, 0.30, 0.05),
                $f('Sex', 0.30, 0.46, 0.18, 0.05),
                $f('Place of Birth', 0.30, 0.55, 0.40, 0.05),
                $f('Father Full Name', 0.30, 0.64, 0.45, 0.05),
                $f('Mother Full Name', 0.30, 0.73, 0.45, 0.05),
            ],
            self::Death => [
                $f('Full Name', 0.30, 0.28, 0.45, 0.05),
                $f('Date of Death', 0.30, 0.37, 0.30, 0.05),
                $f('Sex', 0.30, 0.46, 0.18, 0.05),
                $f('Place of Death', 0.30, 0.55, 0.40, 0.05),
                $f('Cause of Death', 0.30, 0.64, 0.45, 0.05),
            ],
            self::Marriage => [
                $f('Husband Full Name', 0.30, 0.28, 0.45, 0.05),
                $f('Wife Full Name', 0.30, 0.37, 0.45, 0.05),
                $f('Date of Marriage', 0.30, 0.46, 0.30, 0.05),
                $f('Place of Marriage', 0.30, 0.55, 0.40, 0.05),
            ],
        };
    }
}
