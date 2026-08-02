<?php

namespace App\Http\Controllers;

use App\Services\Ocr\DatasetManager;
use App\Services\Ocr\ChunkedUpload;
use App\Services\Ocr\EngineProcess;
use App\Services\Ocr\EvaluationCharts;
use App\Services\Ocr\JobCoordinator;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrModelManager;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

/**
 * The OCR workspace - Super Admin only, one tabbed page.
 *
 * This controller renders the whole page and owns the Models tab's actions.
 * Datasets, jobs, prediction, uploads, and engine control each have their own
 * controller; they all redirect back here.
 *
 * The one action that changes what Staff see is `activate`: until a model is
 * promoted, a fine-tuned model is just a folder on disk.
 */
class OcrModelController extends Controller
{
    public function __construct(
        private readonly OcrModelManager $manager,
        private readonly DatasetManager $datasets,
        private readonly JobCoordinator $jobs,
        private readonly EngineProcess $engine,
        private readonly ChunkedUpload $uploads,
        private readonly OcrClient $client,
    ) {}

    /**
     * The whole workspace. Every tab is rendered server-side and refreshed by
     * ordinary redirects; only job progress and prediction are polled.
     */
    public function index(Request $request): View
    {
        $engine = $this->engine->status();

        return view('ocr.index', [
            'engine' => $engine,
            'engineLog' => $engine['reachable'] ? [] : $this->engine->logTail(15),
            'overview' => $this->manager->overview(),
            'datasets' => $this->datasets->overview(),
            'activeJob' => $this->jobs->activeJob(),
            'history' => $this->jobs->history(),
            'defaults' => $this->trainingDefaults(),
            'charts' => EvaluationCharts::all(),
            'threshold' => config('crms.confidence_review_threshold'),
            // Which tab to open on load, so a redirect can return you where you were.
            'tab' => $this->resolveTab($request->query('tab')),
        ]);
    }

    /**
     * Re-read the models folder. Useful after dropping a folder in by hand, which
     * is the documented fallback when an upload is too large to be worth chunking.
     */
    public function rescan(): RedirectResponse
    {
        try {
            $models = count($this->client->models()['models']);
            $datasets = count($this->client->datasets());

            return $this->back('models')->with(
                'success',
                "Rescanned. The service reports {$models} model(s) and {$datasets} dataset(s).",
            );
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
        }
    }

    /**
     * Promote a model. This is the point at which Staff scanning starts using it -
     * everything before it is a rehearsal.
     */
    public function activate(Request $request, string $key): RedirectResponse
    {
        try {
            $this->manager->activate($key, $request->user());

            return $this->back('models')->with(
                'success',
                "'{$key}' is now the active model. Staff document scanning will use it.",
            );
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
        }
    }

    /**
     * Add a model folder that the browser uploaded in chunks.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'upload_id' => ['required', 'string', 'max:64'],
        ]);

        $actor = $request->user();

        // Weights run to roughly 1.3 GB, so the service is handed file paths and
        // streams them itself. Nothing is read into memory here.
        $files = $this->uploads->assembledFiles($actor, $validated['upload_id']);

        if ($files === []) {
            return $this->back('models')->with(
                'error',
                'No completed upload was found. Try adding the folder again.',
            );
        }

        try {
            $model = $this->manager->add($validated['name'], $files, $actor);

            return $this->back('models')->with('success', "Added model '{$model->key}'.");
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
        } finally {
            $this->uploads->discard($actor, $validated['upload_id']);
        }
    }

    public function rename(Request $request, string $key): RedirectResponse
    {
        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:64'],
        ]);

        try {
            $resolved = $this->manager->rename($key, $validated['new_name'], $request->user());

            return $this->back('models')->with('success', "Renamed to '{$resolved}'.");
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        try {
            $this->manager->delete($key, $request->user());

            return $this->back('models')->with('success', "Deleted model '{$key}' from disk.");
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
        }
    }

    /**
     * Record figures measured elsewhere. An evaluation job fills these in
     * automatically; this stays for numbers produced by a CLI run.
     */
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

            return $this->back('models')->with('success', "Recorded evaluation for '{$key}'.");
        } catch (OcrServiceException $e) {
            return $this->back('models')->with('error', $e->getMessage());
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

    // ------------------------------------------------------------------ internals

    /**
     * Pre-fill the training form from the script's own defaults, so the numbers
     * live in exactly one place. Falls back to a local copy when the service is
     * down, because the form still has to render.
     *
     * @return array<string, mixed>
     */
    private function trainingDefaults(): array
    {
        // Skip the round trip when health has already reported the service down.
        $remote = $this->client->isKnownUnreachable() ? [] : $this->client->trainingDefaults();

        return $remote + [
            'epochs' => 5,
            'batch_size' => 8,
            'learning_rate' => 5e-5,
            'max_label_length' => 32,
            'num_workers' => 2,
            'train_subset' => null,
            'val_subset' => null,
            'base_model' => 'base',
            'output_name' => 'trocr-finetuned',
        ];
    }

    private function resolveTab(?string $tab): string
    {
        $tabs = ['models', 'datasets', 'training', 'evaluation', 'predict'];

        return in_array($tab, $tabs, true) ? $tab : 'models';
    }

    private function back(string $tab): RedirectResponse
    {
        return redirect()->route('ocr.index', ['tab' => $tab]);
    }
}
