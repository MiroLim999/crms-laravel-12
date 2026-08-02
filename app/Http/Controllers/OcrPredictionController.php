<?php

namespace App\Http\Controllers;

use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Predict tab - Super Admin spot-checking, not a Staff path.
 *
 * Synchronous and capped on purpose: it exists to answer "does this model read
 * this handwriting?" in a few seconds. For anything larger there is an evaluation
 * job, which reports CER / WER against ground truth instead of confidence.
 *
 * Images are small enough for a normal multipart post, so this surface does not
 * need chunking. It is still drag and drop.
 */
class OcrPredictionController extends Controller
{
    /** Matches MAX_PREDICT_IMAGES in ml/api/main.py. */
    private const MAX_IMAGES = 50;

    public function __construct(private readonly OcrClient $client) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:191'],
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'max:20480'],
        ]);

        $files = collect($request->file('images'))
            ->map(fn ($file) => [
                'name' => $file->getClientOriginalName(),
                'path' => $file->getRealPath(),
            ])
            ->all();

        try {
            $result = $this->client->predict($files, $validated['model'] ?? null);
        } catch (OcrServiceException $e) {
            // A clear failure state, not a stack trace, and nothing persisted.
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'model' => $result['model'] ?? '',
            'modelKey' => $result['modelKey'] ?? '',
            'count' => $result['count'] ?? 0,
            'rows' => $result['rows'] ?? [],
            'average_confidence' => $result['average_confidence'] ?? 0,
            'low_confidence' => $result['low_confidence'] ?? 0,
            // Confidence is the model's certainty in its own output, never accuracy.
            // The threshold is a review prompt and nothing more.
            'threshold' => (float) config('crms.confidence_review_threshold'),
        ]);
    }
}
