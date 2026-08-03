<?php

namespace App\Http\Controllers;

use App\Services\Ocr\EngineStatus;
use Illuminate\Http\JsonResponse;

/**
 * Read-only status of the OCR service, for the workspace's poller.
 *
 * There is no start or stop here on purpose. Spawning and killing an OS process
 * from a web request is a large amount of blast radius for a convenience: the
 * service is a separate program with its own lifetime, run from a terminal in
 * development or under a supervisor in a deployment. The workspace reports whether
 * it answers and shows the command to run it.
 */
class OcrEngineController extends Controller
{
    public function __construct(private readonly EngineStatus $engine) {}

    /**
     * /health touches no GPU, so this keeps answering while a scan is in flight.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->engine->status());
    }
}
