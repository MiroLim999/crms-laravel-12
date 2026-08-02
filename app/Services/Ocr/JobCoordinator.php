<?php

namespace App\Services\Ocr;

use App\Enums\MlJobStatus;
use App\Enums\MlJobType;
use App\Models\MlDataset;
use App\Models\MlJob;
use App\Models\OcrModel;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Starts, mirrors, and cancels the GPU jobs that run inside the OCR service.
 *
 * The service owns a live job because it owns the GPU: Laravel never trains
 * anything itself. What Laravel owns is the decision to start one, the
 * authorization behind it, and the durable history in `ml_jobs`.
 *
 * Division of truth:
 *  - while a job is live, the service's `/jobs/{id}` is authoritative and every
 *    poll is mirrored into the row;
 *  - once it is terminal, the row is all that remains.
 */
class JobCoordinator
{
    public function __construct(
        private readonly OcrClient $client,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------- starting

    /**
     * Begin a fine-tuning run.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws OcrServiceException
     */
    public function startTraining(array $config, User $actor): MlJob
    {
        $dataset = $config['dataset'] ?? null;

        // Cheap local guard before spending a round trip. The service validates
        // again and is the real gate.
        $registered = MlDataset::where('name', $dataset)->first();

        if ($registered !== null && ! $registered->isTrainable()) {
            throw new OcrServiceException(
                "Dataset '{$dataset}' has not passed validation. Validate it first - "
                .'training on a broken manifest fails hours into the run.'
            );
        }

        return $this->start(MlJobType::Training, $config, $actor);
    }

    /**
     * Begin an evaluation run.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws OcrServiceException
     */
    public function startEvaluation(array $config, User $actor): MlJob
    {
        return $this->start(MlJobType::Evaluation, $config, $actor);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function start(MlJobType $type, array $config, User $actor): MlJob
    {
        $response = $this->client->startJob($type->value, $config);

        $snapshot = $response['job'] ?? [];
        $jobId = $response['job_id'] ?? ($snapshot['id'] ?? null);

        if ($jobId === null) {
            throw new OcrServiceException('The service did not return a job id.');
        }

        // The service echoes back the config it actually resolved, defaults filled
        // in. Store that, not what was requested, so the run is reproducible.
        $resolved = $snapshot['config'] ?? $config;

        $job = MlJob::create([
            'job_id' => $jobId,
            'type' => $type,
            'status' => $this->statusFrom($snapshot),
            'config' => $resolved,
            'dataset' => $resolved['dataset'] ?? null,
            'model_key' => $resolved['model'] ?? ($resolved['base_model'] ?? null),
            'output_name' => $resolved['output_name'] ?? null,
            'progress' => $snapshot['progress'] ?? null,
            'log' => $snapshot['log'] ?? null,
            'started_at' => now(),
            'triggered_by' => $actor->getKey(),
        ]);

        $this->audit->log(
            'ml_job.started',
            $job,
            new: [
                'job_id' => $jobId,
                'type' => $type->value,
                'config' => $resolved,
            ],
            description: sprintf(
                'Started a %s job on the GPU (dataset: %s).',
                $type->label(),
                $resolved['dataset'] ?? 'n/a',
            ),
            actor: $actor,
        );

        return $job;
    }

    // ---------------------------------------------------------------- mirroring

    /**
     * Poll the service for a live job and mirror what it says into the row.
     *
     * Returns the refreshed row. A job the service has forgotten - it restarted, or
     * the history rolled over - is marked failed rather than left running forever.
     */
    public function sync(MlJob $job): MlJob
    {
        if (! $job->isLive()) {
            return $job;
        }

        try {
            $snapshot = $this->client->job($job->job_id);
        } catch (OcrServiceException $e) {
            if ($e->isUnreachable()) {
                // The service is down; say nothing rather than guess. A restart
                // loses the job, which the next reachable poll will discover.
                return $job;
            }

            return $this->finalise($job, MlJobStatus::Failed, [
                'error' => 'The service no longer knows about this job. '.$e->getMessage(),
            ]);
        }

        $status = $this->statusFrom($snapshot);

        $job->fill([
            'status' => $status,
            'progress' => $snapshot['progress'] ?? $job->progress,
            'metrics' => $snapshot['metrics'] ?? $job->metrics,
            'log' => $snapshot['log'] ?? $job->log,
            'error' => $snapshot['error'] ?? $job->error,
        ]);

        if ($status->isTerminal()) {
            return $this->finalise($job, $status, [
                'result' => $snapshot['result'] ?? null,
            ]);
        }

        $job->save();

        return $job;
    }

    /**
     * Record the end of a run, and apply anything it produced.
     *
     * @param  array{error?: string, result?: array<string, mixed>|null}  $extra
     */
    private function finalise(MlJob $job, MlJobStatus $status, array $extra = []): MlJob
    {
        $job->status = $status;
        $job->finished_at = $job->finished_at ?? now();

        if (isset($extra['error'])) {
            $job->error = $extra['error'];
        }

        $job->save();

        if ($status === MlJobStatus::Completed) {
            $this->applyOutcome($job, $extra['result'] ?? null);
        }

        $this->audit->log(
            'ml_job.'.$status->value,
            $job,
            new: [
                'job_id' => $job->job_id,
                'status' => $status->value,
                'metrics' => $job->metrics,
                'error' => $job->error,
            ],
            description: sprintf(
                '%s job %s %s.',
                $job->type->label(),
                $job->job_id,
                $status->value,
            ),
            // The job finished on its own, so there is no acting user: attribute it
            // to whoever started it.
            actor: $job->trigger,
        );

        return $job;
    }

    /**
     * A finished run has side effects worth recording.
     *
     * Training registers its output model so it appears in the model list. Evaluation
     * writes its figures onto the model's row, which is what makes promoting that
     * model a traceable decision rather than a hunch.
     *
     * Neither one activates anything. Promotion stays an explicit human step.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function applyOutcome(MlJob $job, ?array $result): void
    {
        $actor = $job->trigger;

        if ($actor === null) {
            return;
        }

        if ($job->type === MlJobType::Training && $job->output_name) {
            $model = OcrModel::firstOrNew(['key' => $job->output_name]);

            if (! $model->exists) {
                $model->fill([
                    'label' => $job->output_name,
                    'notes' => sprintf(
                        'Fine-tuned from %s on dataset %s (%s epochs).',
                        $job->config['base_model'] ?? 'base',
                        $job->dataset ?? 'n/a',
                        $job->config['epochs'] ?? '?',
                    ),
                    'registered_by' => $actor->getKey(),
                ])->save();
            }

            return;
        }

        if ($job->type === MlJobType::Evaluation) {
            $key = $result['model_key'] ?? $job->model_key;
            $metrics = $job->metrics ?? [];

            if ($key === null || $metrics === []) {
                return;
            }

            $model = OcrModel::firstOrNew(['key' => $key]);

            if (! $model->exists) {
                $model->fill(['label' => $key, 'registered_by' => $actor->getKey()])->save();
            }

            $model->fill([
                'cer' => $metrics['cer'] ?? null,
                'wer' => $metrics['wer'] ?? null,
                'exact_match' => $metrics['exact_match'] ?? null,
                'evaluated_at' => now(),
                'notes' => sprintf(
                    'Evaluated on %s/%s: %s samples.',
                    $job->dataset ?? 'n/a',
                    $job->config['split'] ?? 'test',
                    $metrics['total'] ?? '?',
                ),
            ]);

            $this->audit->saveAndLog(
                'ocr_model.evaluated',
                $model,
                "Recorded evaluation metrics for '{$key}' from job {$job->job_id}.",
            );
        }
    }

    // ------------------------------------------------------------------ cancelling

    /**
     * Ask the service to stop a run. It checks the flag between steps and stops
     * cleanly, so no half-written checkpoint is left behind.
     *
     * @throws OcrServiceException
     */
    public function cancel(MlJob $job, User $actor): MlJob
    {
        $snapshot = $this->client->cancelJob($job->job_id);

        $job->fill([
            'status' => $this->statusFrom($snapshot),
            'progress' => $snapshot['progress'] ?? $job->progress,
            'log' => $snapshot['log'] ?? $job->log,
        ])->save();

        $this->audit->log(
            'ml_job.cancel_requested',
            $job,
            old: ['status' => MlJobStatus::Running->value],
            new: ['status' => $job->status->value, 'progress' => $job->percent()],
            description: sprintf(
                'Requested cancellation of %s job %s at %s%%.',
                $job->type->label(),
                $job->job_id,
                $job->percent(),
            ),
            actor: $actor,
        );

        return $job;
    }

    // -------------------------------------------------------------------- reading

    /**
     * The job currently holding the GPU, mirrored fresh. Null when idle.
     */
    public function activeJob(): ?MlJob
    {
        $job = MlJob::current();

        return $job === null ? null : $this->sync($job);
    }

    /**
     * Recent runs for the history table.
     *
     * @return Collection<int, MlJob>
     */
    public function history(int $limit = 15): Collection
    {
        return MlJob::with('trigger')->latest('id')->limit($limit)->get();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function statusFrom(array $snapshot): MlJobStatus
    {
        return MlJobStatus::tryFrom((string) ($snapshot['status'] ?? '')) ?? MlJobStatus::Queued;
    }
}
