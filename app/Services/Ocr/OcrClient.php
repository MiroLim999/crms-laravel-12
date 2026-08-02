<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-side bridge to the FastAPI TrOCR service.
 *
 * The browser never talks to the OCR service directly: it has no authentication
 * of its own and is bound to 127.0.0.1, so every call is proxied through Laravel
 * where the capability matrix is enforced.
 */
class OcrClient
{
    /**
     * Health for the current request.
     *
     * The workspace page asks several collaborators about the service, and each one
     * wants to know whether it is up. When it is *down*, every one of those probes
     * pays the full connection-refused cost, which added seconds to the page. This
     * is an HTTP response cached for the life of one request - not a database row -
     * so nothing here can outlive a transaction or hand out a stale primary key.
     *
     * @var array<string, mixed>|null
     */
    private ?array $health = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 120,
    ) {}

    // ------------------------------------------------------------------ reading

    /**
     * Is the service up, and what is it running on?
     *
     * @param  bool  $fresh  Skip the per-request cache. Required while waiting for a
     *                       process that is still starting up.
     * @return array{reachable: bool, status: string|null, device: string|null, default: string|null, models: list<array<string, mixed>>, busy: bool, job: array<string, mixed>|null, error: string|null}
     */
    public function health(bool $fresh = false): array
    {
        if (! $fresh && $this->health !== null) {
            return $this->health;
        }

        return $this->health = $this->probeHealth();
    }

    /**
     * @return array<string, mixed>
     */
    private function probeHealth(): array
    {
        try {
            $data = $this->request(5)->get('/health')->throw()->json();

            return [
                'reachable' => true,
                'status' => $data['status'] ?? null,
                'device' => $data['device'] ?? null,
                'default' => $data['default'] ?? null,
                'models' => $data['models'] ?? [],
                'busy' => (bool) ($data['busy'] ?? false),
                'job' => $data['job'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'status' => null,
                'device' => null,
                'default' => null,
                'models' => [],
                'busy' => false,
                'job' => null,
                'error' => $this->reason($e),
            ];
        }
    }

    /**
     * Has the service already been found unreachable in this request?
     *
     * Lets a caller skip a call it knows will fail, rather than paying another
     * connection-refused timeout to learn the same thing.
     */
    public function isKnownUnreachable(): bool
    {
        return $this->health !== null && ! $this->health['reachable'];
    }

    /**
     * Drop the cached health, e.g. after starting or stopping the process.
     */
    public function forgetHealth(): void
    {
        $this->health = null;
    }

    /**
     * Models the service can serve right now, discovered from disk.
     *
     * @return array{default: string|null, models: list<array{key: string, label: string, available: bool, loaded: bool}>}
     */
    public function models(): array
    {
        $data = $this->send(fn (PendingRequest $r) => $r->get('/models'))->json();

        return [
            'default' => $data['default'] ?? null,
            'models' => $data['models'] ?? [],
        ];
    }

    // -------------------------------------------------------------- recognition

    /**
     * Run OCR over cropped field images.
     *
     * @param  list<array{name: string, image: string}>  $fields  `image` is a PNG data URL.
     * @return array{results: list<array{name: string, text: string, confidence: float, error?: string}>, model: string, modelKey: string}
     */
    public function recognise(array $fields, ?string $modelKey = null): array
    {
        if ($fields === []) {
            return ['results' => [], 'model' => '', 'modelKey' => ''];
        }

        $response = $this->send(fn (PendingRequest $r) => $r->post('/ocr', [
            'fields' => array_map(fn (array $f) => [
                'name' => $f['name'],
                'image' => $f['image'],
            ], $fields),
            'model' => $modelKey,
        ]));

        $data = $response->json();

        return [
            'results' => $data['results'] ?? [],
            'model' => $data['model'] ?? '',
            'modelKey' => $data['modelKey'] ?? '',
        ];
    }

    // ----------------------------------------------------------- model lifecycle

    /**
     * Upload a model folder. Files are streamed, never buffered - weights run to
     * roughly 1.3 GB.
     *
     * @param  list<array{name: string, path: string}>  $files
     * @return array<string, mixed>
     */
    public function addModel(string $name, array $files): array
    {
        return $this->withAttachments(
            $this->request($this->timeout * 10),
            'files',
            $files,
            fn (PendingRequest $r) => $r->post('/add_model', ['name' => $name]),
        )->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function renameModel(string $key, string $newName): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post('/rename_model', [
            'model' => $key,
            'newName' => $newName,
        ]))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteModel(string $key): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post('/delete_model', [
            'model' => $key,
        ]))->json();
    }

    // ---------------------------------------------------------------- spot-check

    /**
     * Predict text for a handful of loose images. Synchronous and capped by the
     * service - anything larger belongs in an evaluation job.
     *
     * @param  list<array{name: string, path: string}>  $files
     * @return array<string, mixed>
     */
    public function predict(array $files, ?string $modelKey = null): array
    {
        return $this->withAttachments(
            $this->request(),
            'files',
            $files,
            fn (PendingRequest $r) => $r->post('/predict', ['model' => $modelKey ?? '']),
        )->json();
    }

    // -------------------------------------------------------------------- datasets

    /**
     * @return list<array<string, mixed>>
     */
    public function datasets(): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get('/datasets'))->json('datasets') ?? [];
    }

    /**
     * Pre-training sanity report. Always run this before offering a dataset for
     * training: a manifest pointing at missing files fails hours into an epoch.
     *
     * @return array<string, mixed>
     */
    public function validateDataset(string $name): array
    {
        return $this->send(
            fn (PendingRequest $r) => $r->get('/datasets/'.urlencode($name).'/validate')
        )->json('report') ?? [];
    }

    /**
     * Create a dataset from an assembled zip. Streamed, never buffered: a dataset
     * of thousands of images is far past PHP's memory and upload limits, which is
     * why the browser chunks it and Laravel reassembles before this call.
     *
     * @return array<string, mixed>
     */
    public function createDataset(string $name, string $zipPath): array
    {
        return $this->withAttachments(
            $this->request($this->timeout * 10),
            'file',
            [['name' => $name.'.zip', 'path' => $zipPath]],
            fn (PendingRequest $r) => $r->post('/datasets', ['name' => $name]),
        )->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDataset(string $name): array
    {
        return $this->send(
            fn (PendingRequest $r) => $r->delete('/datasets/'.urlencode($name))
        )->json();
    }

    // ------------------------------------------------------------------------ jobs

    /**
     * Start a training or evaluation run.
     *
     * Returns immediately with a job id, so the client timeout covers only the
     * start call and not the hours the run itself may take.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws OcrServiceException When a GPU job is already running (409).
     */
    public function startJob(string $type, array $config): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post('/jobs', [
            'type' => $type,
            'config' => $config,
        ]))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function job(string $jobId): array
    {
        return $this->send(
            fn (PendingRequest $r) => $r->timeout(10)->get('/jobs/'.urlencode($jobId))
        )->json('job') ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jobs(): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get('/jobs'))->json('jobs') ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelJob(string $jobId): array
    {
        return $this->send(
            fn (PendingRequest $r) => $r->post('/jobs/'.urlencode($jobId).'/cancel')
        )->json('job') ?? [];
    }

    /**
     * The training script's own defaults, so the form is pre-filled from one
     * source rather than a second copy of the numbers in Blade.
     *
     * @return array<string, mixed>
     */
    public function trainingDefaults(): array
    {
        try {
            return $this->request(5)->get('/training_defaults')->throw()->json('defaults') ?? [];
        } catch (\Throwable) {
            // The form still has to render when the service is down.
            return [];
        }
    }

    // ------------------------------------------------------------------ plumbing

    private function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Attach files as streams, and always close the handles afterwards.
     *
     * Streaming rather than reading is required: model weights run to roughly 1.3 GB
     * and would blow PHP's memory limit. Closing is required for a subtler reason -
     * an open handle pins the file on Windows, so the reassembled upload could not
     * be deleted and every upload left gigabytes behind in storage.
     *
     * @param  list<array{name: string, path: string}>  $files
     * @param  callable(PendingRequest): Response  $call
     */
    private function withAttachments(
        PendingRequest $request,
        string $field,
        array $files,
        callable $call,
    ): Response {
        $handles = [];

        try {
            foreach ($files as $file) {
                $handle = @fopen($file['path'], 'rb');

                if ($handle === false) {
                    throw new OcrServiceException("Could not read '{$file['name']}' for upload.");
                }

                $handles[] = $handle;
                $request = $request->attach($field, $handle, $file['name']);
            }

            return $this->send(fn () => $call($request));
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * Run a call, turning transport failures and error bodies into a single
     * exception type the UI knows how to present.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw OcrServiceException::unreachable($this->reason($e));
        }

        if ($response->failed()) {
            // The service returns { ok: false, error: "..." } for its own errors.
            $message = $response->json('error') ?? "HTTP {$response->status()}";

            // Carry the status so a 409 can be presented as "already running"
            // rather than as a failure.
            throw OcrServiceException::refused($message, $response->status());
        }

        return $response;
    }

    private function reason(\Throwable $e): string
    {
        return $e instanceof ConnectionException
            ? 'Start it with: uvicorn api.main:app --host 127.0.0.1 --port 8001'
            : $e->getMessage();
    }
}
