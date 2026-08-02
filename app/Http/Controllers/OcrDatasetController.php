<?php

namespace App\Http\Controllers;

use App\Services\Ocr\ChunkedUpload;
use App\Services\Ocr\DatasetManager;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        try {
            $archive = $this->uploads->singleFile($actor, $validated['upload_id']);

            $dataset = $this->datasets->createFromArchive(
                $validated['name'],
                $archive['path'],
                $actor,
            );
        } catch (OcrServiceException $e) {
            return $this->back()->with('error', $e->getMessage());
        } finally {
            // Never leave a multi-gigabyte archive sitting in storage.
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
