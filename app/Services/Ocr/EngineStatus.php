<?php

namespace App\Services\Ocr;

/**
 * Read-only view of the FastAPI OCR service.
 *
 * CRMS does not start or stop that process. It is a separate service with its own
 * lifetime - run it from a terminal in development, or under a real supervisor in
 * a deployment - so the workspace reports what it finds and shows the command to
 * run, rather than spawning an OS process from a web request.
 *
 * Nothing here writes, kills, or adopts anything.
 */
class EngineStatus
{
    public function __construct(private readonly OcrClient $client) {}

    /**
     * @return array{reachable: bool, device: string|null, error: string|null, url: string, command: string}
     */
    public function status(): array
    {
        // The client caches health for the life of the request, so the page costs
        // one probe however many collaborators ask.
        $health = $this->client->health();

        return [
            'reachable' => $health['reachable'],
            'device' => $health['device'],
            'error' => $health['error'],
            'url' => $this->baseUrl(),
            'command' => $this->displayCommand(),
        ];
    }

    /**
     * The command that starts the service, shown so it can be copied and run.
     * Built from config, never from a request - nothing here is interpolated from
     * user input, because this string is also what a Super Admin will paste into a
     * shell.
     */
    public function displayCommand(): string
    {
        $host = $this->host();
        $port = $this->port();

        return "python -m uvicorn ml.api.main:app --host {$host} --port {$port}";
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.ocr.url', 'http://127.0.0.1:8001'), '/');
    }

    private function host(): string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private function port(): int
    {
        $port = parse_url($this->baseUrl(), PHP_URL_PORT);

        return is_int($port) && $port > 0 ? $port : 8001;
    }
}
