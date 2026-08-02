<?php

namespace App\Http\Controllers;

use App\Models\MlJob;
use App\Services\Ocr\JobCoordinator;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fine-tuning and evaluation runs - Super Admin only.
 *
 * Both are jobs inside the OCR service, never synchronous requests: training takes
 * hours. Starting one returns immediately with a job id; the workspace then polls
 * `status` and Laravel mirrors what it learns into `ml_jobs`.
 *
 * A job is never started automatically. Training saturates the same GPU that Staff
 * scanning uses, so it is always an explicit action by a Super Admin who has been
 * warned that scanning will be degraded.
 */
class OcrJobController extends Controller
{
    public function __construct(private readonly JobCoordinator $jobs) {}

    /**
     * Start a fine-tuning run.
     */
    public function train(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dataset' => ['required', 'string', 'max:64'],
            'output_name' => ['required', 'string', 'max:64'],
            'base_model' => ['nullable', 'string', 'max:191'],
            'epochs' => ['required', 'integer', 'min:1', 'max:200'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:128'],
            'learning_rate' => ['required', 'numeric', 'min:0.0000001', 'max:0.1'],
            'max_label_length' => ['required', 'integer', 'min:1', 'max:512'],
            'num_workers' => ['required', 'integer', 'min:0', 'max:16'],
            'train_subset' => ['nullable', 'integer', 'min:1'],
            'val_subset' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $job = $this->jobs->startTraining($this->clean($validated), $request->user());
        } catch (OcrServiceException $e) {
            return $this->back('training')->with(
                $e->isBusy() ? 'warning' : 'error',
                $e->getMessage(),
            );
        }

        return $this->back('training')->with(
            'success',
            "Fine-tuning started (job {$job->job_id}). Document scanning will be slower until it finishes.",
        );
    }

    /**
     * Start an evaluation run against a labelled split.
     */
    public function evaluate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:191'],
            'dataset' => ['required', 'string', 'max:64'],
            'split' => ['required', 'in:train,val,test'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $job = $this->jobs->startEvaluation($this->clean($validated), $request->user());
        } catch (OcrServiceException $e) {
            return $this->back('evaluation')->with(
                $e->isBusy() ? 'warning' : 'error',
                $e->getMessage(),
            );
        }

        return $this->back('evaluation')->with(
            'success',
            "Evaluation started (job {$job->job_id}). Figures will appear here when it finishes.",
        );
    }

    /**
     * Progress for the page's poller.
     *
     * Polling, not websockets: a job reports at epoch and step boundaries, so a few
     * seconds of latency costs nothing and there is no connection to keep alive.
     */
    public function status(MlJob $job): JsonResponse
    {
        $job = $this->jobs->sync($job);

        return response()->json([
            'id' => $job->getKey(),
            'job_id' => $job->job_id,
            'type' => $job->type->value,
            'type_label' => $job->type->label(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'badge' => $job->status->badgeClass(),
            'live' => $job->isLive(),
            'percent' => $job->percent(),
            'stage' => $job->stage(),
            'progress' => $job->progress,
            'metrics' => $job->metrics,
            'headline' => $job->headlineMetric(),
            'duration' => $job->duration(),
            'error' => $job->error,
            'log' => $job->log ?? [],
            'dataset' => $job->dataset,
            'output_name' => $job->output_name,
        ]);
    }

    /**
     * Ask the service to stop. It checks the cancel flag between steps and stops
     * cleanly rather than being killed, so no half-written checkpoint is left.
     */
    public function cancel(Request $request, MlJob $job): RedirectResponse
    {
        if (! $job->isLive()) {
            return $this->back($this->tabFor($job))->with(
                'error',
                'That job has already finished.',
            );
        }

        try {
            $this->jobs->cancel($job, $request->user());
        } catch (OcrServiceException $e) {
            return $this->back($this->tabFor($job))->with('error', $e->getMessage());
        }

        return $this->back($this->tabFor($job))->with(
            'success',
            'Cancellation requested. The run stops at the next safe point.',
        );
    }

    /**
     * Drop only the keys the caller left blank, so the service applies its own
     * defaults rather than receiving nulls.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function clean(array $validated): array
    {
        return array_filter($validated, fn ($value) => $value !== null && $value !== '');
    }

    private function tabFor(MlJob $job): string
    {
        return $job->type->value === 'training' ? 'training' : 'evaluation';
    }

    private function back(string $tab): RedirectResponse
    {
        return redirect()->route('ocr.index', ['tab' => $tab]);
    }
}
