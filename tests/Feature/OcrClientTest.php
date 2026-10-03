<?php

namespace Tests\Feature;

use App\Services\Ocr\OcrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcrClientTest extends TestCase
{
    public function test_every_call_to_the_ocr_service_carries_the_shared_key(): void
    {
        config(['services.ocr.upload_secret' => 'shared-test-key']);
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'device' => 'cpu', 'default' => 'base', 'models' => []]),
            '*/models' => Http::response(['default' => 'base', 'models' => []]),
            '*/ocr' => Http::response(['results' => [], 'model' => 'TrOCR base', 'modelKey' => 'base']),
            '*/rename_model' => Http::response(['ok' => true]),
            '*/delete_model' => Http::response(['ok' => true]),
        ]);

        // Built the way the app builds it, so the key's wiring is tested too.
        $client = app(OcrClient::class);
        $client->health(fresh: true);
        $client->models();
        $client->recognise([['name' => 'Name', 'image' => 'data:image/png;base64,AA==']], 'base');
        $client->renameModel('old-model', 'new-model');
        $client->deleteModel('old-model');

        Http::assertSentCount(5);
        Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-CRMS-Service-Key', 'shared-test-key'));
    }
}
