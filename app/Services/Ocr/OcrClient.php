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
    public function health(): array
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
        $request = $this->request($this->timeout * 10);

        foreach ($files as $file) {
            $request = $request->attach(
                'files',
                fopen($file['path'], 'rb'),
                $file['name'],
            );
        }

        return $this->send(fn () => $request->post('/add_model', ['name' => $name]))->json();
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

            throw new OcrServiceException($message);
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
