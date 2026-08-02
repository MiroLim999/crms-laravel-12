<?php

namespace App\Http\Controllers;

use App\Services\Ocr\EngineProcess;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Run / Stop for the OCR service - Super Admin only.
 *
 * Replaces typing
 *   python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001
 * into a terminal. The command is assembled entirely from config, never from the
 * request, and the bind address is forced to loopback - see EngineProcess for why
 * that matters.
 */
class OcrEngineController extends Controller
{
    public function __construct(private readonly EngineProcess $engine) {}

    public function start(Request $request): RedirectResponse
    {
        try {
            $this->engine->start($request->user());
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return $this->back()->with(
            'success',
            'OCR service started. Models load on first use, so the first scan is slower.',
        );
    }

    public function stop(Request $request): RedirectResponse
    {
        // Stopping mid-job loses the run, so the confirm step has to send `force`
        // explicitly. Without it, EngineProcess refuses and explains why.
        $force = $request->boolean('force');

        try {
            $this->engine->stop($request->user(), $force);
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return $this->back()->with('success', 'OCR service stopped.');
    }

    /**
     * Engine state for the page's poller: reachable, device, and any running job.
     *
     * /health touches no GPU, which is what lets this keep answering while a
     * training run has the card pinned.
     */
    public function status(): JsonResponse
    {
        $status = $this->engine->status();

        return response()->json($status + [
            'log' => $status['reachable'] ? [] : $this->engine->logTail(15),
        ]);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('ocr.index', ['tab' => request('tab', 'models')]);
    }
}
