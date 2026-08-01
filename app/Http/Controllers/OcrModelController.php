<?php

namespace App\Http\Controllers;

use App\Services\Ocr\EvaluationCharts;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrModelManager;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

/**
 * OCR model management - Super Admin only, all on one page.
 *
 * Mirrors the legacy prototype's sidebar controls (list, add, rename, delete,
 * rescan) and adds what CRMS needs on top: a persisted active model and the
 * evaluation charts that justify promoting it.
 */
class OcrModelController extends Controller
{
    public function __construct(
        private readonly OcrModelManager $manager,
        private readonly OcrClient $client,
    ) {}

    public function index(): View
    {
        return view('ocr.index', [
            'overview' => $this->manager->overview(),
            'charts' => EvaluationCharts::all(),
            'threshold' => config('crms.confidence_review_threshold'),
        ]);
    }

    /**
     * Re-read the Models folder. Useful after dropping a folder in by hand.
     */
    public function rescan(): RedirectResponse
    {
        try {
            $count = count($this->client->models()['models']);

            return back()->with('success', "Rescanned. The service reports {$count} model(s).");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function activate(Request $request, string $key): RedirectResponse
    {
        try {
            $this->manager->activate($key, $request->user());

            return back()->with('success', "'{$key}' is now the active model for scanning.");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],
        ]);

        // Hand the service file handles rather than contents: weights run to
        // roughly 1.3 GB and must never be held in memory.
        $files = collect($request->file('files'))
            ->map(fn ($file) => [
                'name' => $file->getClientOriginalName(),
                'path' => $file->getRealPath(),
            ])
            ->all();

        try {
            $model = $this->manager->add($validated['name'], $files, $request->user());

            return back()->with('success', "Added model '{$model->key}'.");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function rename(Request $request, string $key): RedirectResponse
    {
        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:64'],
        ]);

        try {
            $resolved = $this->manager->rename($key, $validated['new_name'], $request->user());

            return back()->with('success', "Renamed to '{$resolved}'.");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        try {
            $this->manager->delete($key, $request->user());

            return back()->with('success', "Deleted model '{$key}' from disk.");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function recordEvaluation(Request $request, string $key): RedirectResponse
    {
        $validated = $request->validate([
            'cer' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'wer' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'exact_match' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->manager->recordEvaluation($key, $validated, $request->user());

            return back()->with('success', "Recorded evaluation for '{$key}'.");
        } catch (OcrServiceException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Serve an evaluation chart PNG. These live outside the public directory, so
     * they are streamed through a gated route rather than exposed by URL.
     */
    public function chart(string $variant, string $name)
    {
        $path = EvaluationCharts::resolve($variant, $name);

        abort_if($path === null, 404);

        return Response::file($path, ['Content-Type' => 'image/png']);
    }
}
