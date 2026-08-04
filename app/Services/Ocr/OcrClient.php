<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-side bridge to the FastAPI TrOCR service.
 *
 * Ordinary calls stay server-side. Model bytes are the one exception: the browser
 * sends them directly with a short-lived Laravel-signed authorization ticket, then
 * Laravel verifies and registers the installed model.
 *
 * The surface is deliberately small - health, the model list, recognition, and the
 * model-list and model-lifecycle calls. Training, evaluation, datasets, and batch prediction
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
