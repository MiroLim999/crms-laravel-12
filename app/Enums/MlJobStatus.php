<?php

namespace App\Enums;

/**
 * Lifecycle of a GPU job, mirroring the FastAPI service's own vocabulary.
 *
 * The service is the source of truth while a job is live; `ml_jobs` mirrors it so
 * the history survives a service restart. Keep these strings identical to
 * ml/jobs.py or the mirror silently stops matching.
 */
enum MlJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued => 'bg-label-secondary',
            self::Running => 'bg-label-info',
            self::Completed => 'bg-label-success',
            self::Failed => 'bg-label-danger',
            self::Cancelled => 'bg-label-warning',
        };
    }

    /**
     * A job that has stopped. Nothing more will arrive from the service for it.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return [self::Queued->value, self::Running->value];
    }
}
