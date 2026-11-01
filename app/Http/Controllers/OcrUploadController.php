<?php

namespace App\Http\Controllers;

use App\Models\OcrModel;
use App\Services\Ocr\OcrModelManager;
use App\Services\Ocr\OcrServiceException;
use App\Services\Ocr\OcrUploadAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small control-plane endpoints for a direct browser-to-FastAPI model upload.
 * The model bytes never enter PHP; Laravel authorizes the upload first and records
 * the completed installation afterwards.
 */
class OcrUploadController extends Controller
{
    public function __construct(
        private readonly OcrUploadAuthorizer $authorizer,
        private readonly OcrModelManager $manager,
    ) {}

    public function authorizeUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        return response()->json([
            'upload_url' => rtrim((string) config('services.ocr.browser_url'), '/').'/add_model',
            'authorization' => $this->authorizer->issue($validated['name'], $request->user()),
        ]);
    }

    /**
     * Persist the model FastAPI has installed and write its audit record. The OCR
     * service is queried again so a forged browser response cannot register a model
     * that is not actually present on disk.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $model = $this->manager->registerInstalled(
                $validated['name'],
                $request->user(),
            );
        } catch (OcrServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status ?? 503);
        }

        $message = "Installed model '{$model->key}'. Select it below and save settings to start scanning with it.";

        // The workspace overlays the base model's scores on this model's. Say
        // whether that comparison is fair: both scored on the same test set.
        $base = $model->key === 'base' ? null : OcrModel::query()->where('key', 'base')->first();
        $sameTestSet = $base !== null ? $model->sharesTestSetWith($base) : null;
        if ($sameTestSet === true) {
            $message .= ' The base model\'s scores come from the same test set, so the comparison is fair.';
        } elseif ($sameTestSet === false) {
            // A differing manifest hash is not proof of a different test set (a
            // hand-written report hashes the dataset name instead), so say only
            // what is known.
            $message .= " The base model's scores could not be matched to the same test set"
                ." (they are from {$base->evaluation_dataset}), so the comparison may not be like for like."
                .' A ZIP from the fine-tuning notebook includes the base scores from the same run.';
        }
        $request->session()->flash('success', $message);

        return response()->json([
            'registered' => true,
            'name' => $model->key,
            'message' => $message,
        ]);
    }
}
