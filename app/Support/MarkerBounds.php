<?php

namespace App\Support;

/**
 * Whether a marker stays on the page once its tilt is counted.
 *
 * Markers are stored upright (x, y, w, h as page fractions) plus a tilt in
 * degrees clockwise about their own centre. The upright box can be on the
 * page while a turned corner is off it, so the corners are checked after the
 * turn, the same way the Template Builder draws them (field-marker.js,
 * markerCorners). A turn is only a turn on the page's real proportions, so
 * the page's width : height ratio is needed.
 */
final class MarkerBounds
{
    /**
     * @param  float  $ratio  Page width divided by page height.
     * @param  float  $tolerance  Slack as a fraction of each side of the page.
     */
    public static function inside(
        float $x,
        float $y,
        float $w,
        float $h,
        float $angle,
        float $ratio,
        float $tolerance = 0.005,
    ): bool {
        if (round($angle, 1) == 0.0) {
            return $x >= -$tolerance && $y >= -$tolerance
                && $x + $w <= 1 + $tolerance && $y + $h <= 1 + $tolerance;
        }

        // Page height is 1 and width is $ratio, so a turn keeps its shape.
        $radians = deg2rad($angle);
        $cos = cos($radians);
        $sin = sin($radians);
        $centreX = ($x + $w / 2) * $ratio;
        $centreY = $y + $h / 2;
        $halfWidth = $w * $ratio / 2;
        $halfHeight = $h / 2;

        foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
            $dx = $sx * $halfWidth;
            $dy = $sy * $halfHeight;
            $cornerX = $centreX + $dx * $cos - $dy * $sin;
            $cornerY = $centreY + $dx * $sin + $dy * $cos;

            if ($cornerX < -$tolerance * $ratio || $cornerX > $ratio * (1 + $tolerance)
                || $cornerY < -$tolerance || $cornerY > 1 + $tolerance) {
                return false;
            }
        }

        return true;
    }
}
