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
 *
 * The surface is deliberately small - health, the model list, recognition, and the
 * three model-lifecycle calls. Training, evaluation, datasets, and batch prediction
 * are command-line work under ml/, not something the web app drives.
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
     * @return array{reachable: bool, status: string|null, device: string|null, default: string|null, models: list<array<string, mixed>>, error: string|null}
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
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'status' => null,
                'device' => null,
                'default' => null,
                'models' => [],
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
     * Drop the cached health.
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
     * Upload a model as loose files - the folder a Super Admin dropped on the page.
     * Files are streamed, never buffered: weights run to roughly 1.3 GB.
     *
     * @param  list<array{name: string, path: string}>  $files
     * @return array<string, mixed>
     */
    public function addModel(string $name, array $files): array
    {
        return $this->withAttachments(
            // No timeout cap: writing 1.3 GB to disk on a slow volume can take
            // minutes, and there is nothing useful to do with a half-written model.
            $this->multipartRequest(0),
            'files',
            $files,
            fn (PendingRequest $r) => $r->post('/add_model', ['name' => $name]),
        )->json();
    }

    /**
     * Upload a model as one .zip. The service extracts it and locates the model
     * files inside, so a wrapping folder in the archive is fine.
     *
     * @return array<string, mixed>
     */
    public function addModelArchive(string $name, string $zipPath, string $filename = 'model.zip'): array
    {
        return $this->withAttachments(
            $this->multipartRequest(0),
            'archive',
            [['name' => $filename, 'path' => $zipPath]],
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

    // ------------------------------------------------------------------ plumbing

    private function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson()
            ->asJson();
    }

    /**
     * A request whose body will be multipart, and deliberately not asJson().
     *
     * asJson() pins Content-Type to application/json. attach() switches the body
     * format to multipart but leaves that header in place, and Guzzle only supplies
     * its own multipart Content-Type - the one carrying the boundary - when none is
     * set. The upload therefore went out as a multipart body labelled JSON: Starlette
     * parsed no form at all, `name` arrived empty, and the service answered "Please
     * provide a model name" however carefully the Super Admin had filled the field in.
     */
    private function multipartRequest(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson()
            ->asMultipart();
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

            // Carry the status so a 409 can be presented as "already exists"
            // rather than as an opaque failure.
            throw OcrServiceException::refused($message, $response->status());
        }

        return $response;
    }

    private function reason(\Throwable $e): string
    {
        // The module path is ml.api.main, not api.main: all Python lives under ml/
        // and the service is launched from the repo root.
        return $e instanceof ConnectionException
            ? 'Start it with: python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001'
            : $e->getMessage();
    }
}
