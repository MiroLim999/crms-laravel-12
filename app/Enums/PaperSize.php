<?php

namespace App\Enums;

enum PaperSize: string
{
    case Letter = 'letter';
    case LongBond = 'long_bond';
    case A4 = 'a4';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Letter => 'Short / Letter',
            self::LongBond => 'Long bond',
            self::A4 => 'A4',
            self::Custom => 'Custom size',
        };
    }

    public function dimensionsLabel(): string
    {
        return match ($this) {
            self::Letter => '8.5 × 11 in',
            self::LongBond => '8.5 × 13 in',
            self::A4 => '210 × 297 mm',
            self::Custom => 'Custom dimensions',
        };
    }

    /**
     * Physical portrait dimensions in millimetres.
     *
     * @return array{width: float, height: float}
     */
    public function portraitDimensions(): array
    {
        return match ($this) {
            self::Letter => ['width' => 215.9, 'height' => 279.4],
            self::LongBond => ['width' => 215.9, 'height' => 330.2],
            self::A4 => ['width' => 210.0, 'height' => 297.0],
            // Custom values are supplied by DocumentTemplate. A4 is only a
            // safe editor fallback before the administrator enters dimensions.
            self::Custom => ['width' => 210.0, 'height' => 297.0],
        };
    }

    /**
     * @return array{width: float, height: float}
     */
    public function dimensions(PageOrientation $orientation): array
    {
        $portrait = $this->portraitDimensions();

        if ($orientation === PageOrientation::Portrait) {
            return $portrait;
        }

        return [
            'width' => $portrait['height'],
            'height' => $portrait['width'],
        ];
    }

    public function aspectRatio(PageOrientation $orientation): float
    {
        $dimensions = $this->dimensions($orientation);

        return $dimensions['width'] / $dimensions['height'];
    }
}
