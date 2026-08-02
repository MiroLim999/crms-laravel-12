<?php

namespace App\Enums;

/**
 * The two kinds of long-running GPU work a Super Admin can start.
 *
 * Both are jobs inside the FastAPI service, never synchronous requests: training
 * runs for hours and evaluation for minutes. Spot-check prediction is deliberately
 * absent - it is synchronous and capped, so it is not a job.
 */
enum MlJobType: string
{
    case Training = 'training';
    case Evaluation = 'evaluation';

    public function label(): string
    {
        return match ($this) {
            self::Training => 'Fine-tuning',
            self::Evaluation => 'Evaluation',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Training => 'bx-dumbbell',
            self::Evaluation => 'bx-bar-chart-alt-2',
        };
    }

    /**
     * How long a Super Admin should expect to wait, shown on the confirm step.
     */
    public function expectedDuration(): string
    {
        return match ($this) {
            self::Training => 'hours',
            self::Evaluation => 'minutes',
        };
    }
}
