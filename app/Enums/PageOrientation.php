<?php

namespace App\Enums;

enum PageOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';

    public function label(): string
    {
        return match ($this) {
            self::Portrait => 'Portrait',
            self::Landscape => 'Landscape',
        };
    }
}
