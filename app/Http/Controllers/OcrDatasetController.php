<?php

namespace App\Http\Controllers;

use App\Services\Ocr\ChunkedUpload;
use App\Services\Ocr\DatasetManager;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Datasets tab of the OCR workspace - Super Admin only.
 *
 * A dataset arrives as a zip the browser sliced and Laravel reassembled: thousands
 * of images are far past PHP's 40M upload limit, so a plain form post would be
 * rejected before the app ever ran.
 *
 * Every upload is validated on arrival, and only a dataset that passes may be
 * trained on. That check is not bureaucracy: a manifest pointing at files that are
 * not there fails deep into an epoch, hours of GPU time later.
 */
class OcrDatasetController extends Controller
{
    public function __construct(
        private readonly DatasetManager $datasets,
        private readonly ChunkedUpload $uploads,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'upload_id' => ['required', 'string', 'max:64'],
        ]);

        $actor = $request->user();

        // Unpacking a 50–100 GB dataset zip and walking every image for validation
        // can take many minutes. This also applies to directory sets streamed to
        // the service one file at a time.
        set_time_limit(0);

        $files = $this->uploads->assembledFiles($actor, $validated['upload_id']);

        try {
            if ($files === []) {
                throw ValidationException::withMessages([
                    'upload_id' => 'No completed upload was found. Try uploading the dataset again.',
                ]);
            }

            $isSingleZip = count($files) === 1
                && strtolower(pathinfo($files[0]['name'], PATHINFO_EXTENSION)) === 'zip';

            if ($isSingleZip) {
                $dataset = $this->datasets->createFromArchive(
                    $validated['name'],
                    $files[0]['path'],
                    $actor,
                );
            } else {
                $containsZip = collect($files)->contains(
                    fn (array $file) => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'zip'
                );
                $hasManifest = collect($files)->contains(
                    fn (array $file) => strtolower(basename($file['relative_path'])) === 'manifest.csv'
                );

                if ($containsZip) {
                    throw ValidationException::withMessages([
                        'upload_id' => 'Upload exactly one zip, or a directory/file set without a zip. Do not mix them.',
                    ]);
                }

                if (! $hasManifest) {
                    throw ValidationException::withMessages([
                        'upload_id' => 'A directory/file set must contain manifest.csv.',
                    ]);
                }

                $dataset = $this->datasets->createFromFiles(
                    $validated['name'],
                    $files,
                    $actor,
                );
            }
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        } finally {
            // Never leave a multi-gigabyte archive or directory set in storage.
            $this->uploads->discard($actor, $validated['upload_id']);
        }

        if (! $dataset->is_valid) {
            return $this->back()->with(
                'warning',
                "Dataset '{$dataset->name}' was uploaded but did not pass validation. "
                .'Review the report below - it cannot be used for training until it does.',
            );
        }

        return $this->back()->with(
            'success',
            "Dataset '{$dataset->name}' uploaded and validated: {$dataset->total_images} image(s).",
        );
    }

    /**
     * Re-run the sanity report. Always available, because the folder can change on
     * disk without CRMS being told.
     */
    public function validateDataset(Request $request, string $name): RedirectResponse
    {
        try {
            $dataset = $this->datasets->validate($name, $request->user());
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        if ($dataset->is_valid) {
            return $this->back()->with(
                'success',
                "'{$name}' passed validation: {$dataset->usableTrainRows()} usable training row(s).",
            );
        }

        return $this->back()->with(
            'error',
            "'{$name}' failed validation. ".implode(' ', $dataset->errors()),
        );
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        try {
            $this->datasets->delete($name, $request->user());
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return $this->back()->with('success', "Deleted dataset '{$name}' and all its images.");
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('ocr.index', ['tab' => 'datasets']);
    }
}
