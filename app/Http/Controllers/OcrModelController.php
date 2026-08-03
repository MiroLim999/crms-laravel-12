<?php

namespace App\Http\Controllers;

use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Services\AuditLogger;
use App\Services\Ocr\ChunkedUpload;
use App\Services\Ocr\EngineStatus;
use App\Services\Ocr\OcrModelManager;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The OCR workspace - Super Admin only, one page.
 *
 * Two responsibilities, and nothing else:
 *
 *   1. Manage the model folders the service can serve - install, rename, delete.
 *   2. Save which of them Staff scan with, alongside the two settings that
 *      decision implies.
 *
 * Installing a model is housekeeping. Saving the settings is the load-bearing
 * action: until it runs, a newly installed model is just a folder on disk.
 */
class OcrModelController extends Controller
{
    public function __construct(
        private readonly OcrModelManager $manager,
        private readonly EngineStatus $engine,
        private readonly ChunkedUpload $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $settings = OcrSetting::current();

        return view('ocr.index', [
            'engine' => $this->engine->status(),
            'overview' => $this->manager->overview(),
            'activeModel' => OcrModel::active(),
            'settings' => $settings,
            // Rendered into the form, so an empty override shows the value actually
            // in force rather than a blank box.
            'threshold' => OcrSetting::threshold(),
            'configThreshold' => (float) config('crms.confidence_review_threshold', 80.0),
            // The browser sizes its slices from this rather than assuming a php.ini.
            'chunkBytes' => $this->uploads->chunkBytes(),
        ]);
    }

    /**
     * The Save settings button.
     *
     * One submit covers the model choice and the settings around it, because they
     * are one decision: this model, and whether Staff may deviate from it.
     */
    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Nullable, not required: with no models installed - or the service down -
            // the picker has nothing to offer, and the other settings must still save.
            'model' => ['nullable', 'string', 'max:255'],
            'allow_staff_model_choice' => ['nullable', 'boolean'],
            // Null clears the override and falls back to CRMS_CONFIDENCE_THRESHOLD.
            'confidence_review_threshold' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $changes = [];
        $model = $validated['model'] ?? '';

        try {
            $previous = OcrModel::active();

            if ($model !== '' && $previous?->key !== $model) {
                $this->manager->activate($model, $actor);
                $changes[] = "Staff now scan with '{$model}'";
            }
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        $settings = OcrSetting::current();
        $settings->fill([
            'allow_staff_model_choice' => $request->boolean('allow_staff_model_choice'),
            'confidence_review_threshold' => $validated['confidence_review_threshold'] ?? null,
            'updated_by' => $actor->getKey(),
        ]);

        // `updated_by` alone is not a change worth reporting, so it is excluded
        // when deciding whether anything actually moved.
        $touched = array_diff_key($settings->getDirty(), ['updated_by' => null]);

        if ($touched !== []) {
            $this->audit->saveAndLog(
                'ocr_settings.updated',
                $settings,
                'Updated OCR scanning settings.',
                $actor,
            );
            $changes[] = 'scanning settings saved';
        } elseif ($settings->isDirty()) {
            // Only `updated_by` moved. Worth recording who last confirmed the
            // settings, but not worth an audit entry describing no change.
            $settings->save();
        }

        OcrSetting::forgetCached();

        return $this->back()->with(
            'success',
            $changes === []
                ? 'Nothing to save - those settings were already in force.'
                : ucfirst(implode(', ', $changes)).'.',
        );
    }

    /**
     * Reconcile the registry with what is on disk, which is the documented fallback
     * for a model folder copied into ml/models/ by hand.
     */
    public function rescan(Request $request): RedirectResponse
    {
        try {
            $counts = $this->manager->reconcile($request->user());

            return $this->back()->with(
                'success',
                "Rescanned {$counts['remote']} model(s): registered {$counts['registered']}, "
                ."restored {$counts['restored']}, marked missing {$counts['tombstoned']}.",
            );
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Install a model the browser uploaded in chunks - either a folder of files or
     * a single .zip.
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
            return $this->back()->with(
                'error',
                'No completed upload was found. Try adding the model again.',
            );
        }

        // Extraction and a 1.3 GB copy both outlast PHP's default execution time.
        set_time_limit(0);

        try {
            $archive = $this->soleArchive($files);

            $model = $archive === null
                ? $this->manager->add($validated['name'], $files, $actor)
                : $this->manager->addArchive(
                    $validated['name'],
                    $archive['path'],
                    $archive['name'],
                    $actor,
                );

            return $this->back()->with(
                'success',
                "Installed model '{$model->key}'. Select it below and save settings to "
                .'start scanning with it.',
            );
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
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

            return $this->back()->with('success', "Renamed to '{$resolved}'.");
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        try {
            $this->manager->delete($key, $request->user());

            return $this->back()->with('success', "Deleted model '{$key}' from disk.");
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ internals

    /**
     * The upload as a single .zip, or null when it is a folder of loose files.
     *
     * A zip mixed with loose files is ambiguous - which one is the model? - so it is
     * refused rather than guessed at.
     *
     * @param  list<array{name: string, relative_path: string, path: string, size: int}>  $files
     * @return array{name: string, path: string}|null
     */
    private function soleArchive(array $files): ?array
    {
        $zips = array_values(array_filter(
            $files,
            fn (array $file) => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'zip',
        ));

        if ($zips === []) {
            return null;
        }

        if (count($zips) > 1 || count($files) > 1) {
            throw new OcrServiceException(
                'Upload either one .zip archive or the model folder, not both.',
            );
        }

        return ['name' => $zips[0]['name'], 'path' => $zips[0]['path']];
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('ocr.index');
    }
}
