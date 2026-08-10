<?php

namespace App\Http\Controllers;

use App\Services\Ocr\EngineStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Slow-changing and external current-state details loaded after the Super Admin
 * dashboard is already interactive.
 */
class DashboardSystemStatusController extends Controller
{
    public function __construct(private readonly EngineStatus $engine) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'engine' => $this->engine->status(),
            'scan_storage' => Cache::remember('dashboard.scan-storage.v1', now()->addMinutes(10), function () {
                $bytes = 0;
                $files = 0;

                try {
                    foreach (Storage::disk('local')->allFiles('scans') as $path) {
                        $bytes += Storage::disk('local')->size($path);
                        $files++;
                    }
                } catch (Throwable) {
                    return ['available' => false, 'bytes' => null, 'files' => null];
                }

                return ['available' => true, 'bytes' => $bytes, 'files' => $files];
            }),
        ]);
    }
}
