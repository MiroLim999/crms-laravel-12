<?php

namespace App\Models;

use App\Enums\MlJobStatus;
use App\Enums\MlJobType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fine-tuning or evaluation run.
 *
 * Mirrored from the FastAPI service, which owns a job while it is live because it
 * owns the GPU. Once the job is terminal this row is the whole record of it.
 */
class MlJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id', 'type', 'status', 'config',
        'dataset', 'model_key', 'output_name',
        'progress', 'metrics', 'log', 'error',
        'started_at', 'finished_at', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MlJobType::class,
            'status' => MlJobStatus::class,
            'config' => 'array',
            'progress' => 'array',
            'metrics' => 'array',
            'log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * The one job that may be in flight. There is only ever one GPU.
     */
    public static function current(): ?self
    {
        return static::inFlight()->latest('id')->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', MlJobStatus::liveValues());
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function percent(): float
    {
        return (float) data_get($this->progress, 'percent', 0);
    }

    public function stage(): ?string
    {
        return data_get($this->progress, 'stage');
    }

    /**
     * What this run produced, for the history table. Training reports a validation
     * loss; evaluation reports the figures that justify promoting a model.
     */
    public function headlineMetric(): ?string
    {
        if ($this->metrics === []) {
            return null;
        }

        if ($this->type === MlJobType::Training) {
            $loss = data_get($this->metrics, 'best_val_loss');

            return $loss === null ? null : 'best val loss '.$loss;
        }

        $cer = data_get($this->metrics, 'cer');

        return $cer === null ? null : 'CER '.number_format((float) $cer * 100, 2).'%';
    }

    public function duration(): ?string
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? now();
        $seconds = max($this->started_at->diffInSeconds($end), 0);

        return $seconds < 60
            ? $seconds.'s'
            : floor($seconds / 60).'m '.($seconds % 60).'s';
    }
}
