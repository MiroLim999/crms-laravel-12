<?php

namespace App\Http\Controllers;

use App\Services\Ocr\ChunkedUpload;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Predict tab - Super Admin spot-checking, not a Staff path.
 *
 * Synchronous and capped on purpose: it exists to answer "does this model read
 * this handwriting?" in a few seconds. For anything larger there is an evaluation
 * job, which reports CER / WER against ground truth instead of confidence.
 *
 * Images use the same chunked staging area as every other OCR upload surface. This
 * keeps a batch below PHP's request limits even when all 50 images are near 20 MB.
 */
class OcrPredictionController extends Controller
{
    /** Matches MAX_PREDICT_IMAGES in the OCR service. */
    private const MAX_IMAGES = 50;

    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_BMP,
        IMAGETYPE_TIFF_II,
        IMAGETYPE_TIFF_MM,
    ];

    public function __construct(
        private readonly OcrClient $client,
        private readonly ChunkedUpload $uploads,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:191'],
            'upload_id' => ['nullable', 'string', 'max:64', 'required_without:images'],
            'images' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_IMAGES, 'required_without:upload_id'],
            'images.*' => ['required', 'file', 'image', 'max:20480'],
        ]);

        $actor = $request->user();
        $uploadId = $validated['upload_id'] ?? null;

        try {
            if ($uploadId !== null) {
                $files = $this->uploads->assembledFiles($actor, $uploadId);
                $validationField = 'upload_id';
            } else {
                $files = collect($request->file('images', []))
                    ->map(fn ($file) => [
                        'name' => $file->getClientOriginalName(),
                        'relative_path' => $file->getClientOriginalName(),
                        'path' => $file->getRealPath() ?: $file->getPathname(),
                        'size' => (int) $file->getSize(),
                    ])
                    ->all();
                $validationField = 'images';
            }

            $this->validateImages($files, $validationField);
            $result = $this->client->predict($files, $validated['model'] ?? null);
        } catch (OcrServiceException $e) {
            // Preserve actionable service-side validation errors. Transport and
            // service failures remain a 503 unavailable state.
            $status = $e->status !== null && $e->status >= 400 && $e->status < 500
                ? $e->status
                : 503;

            return response()->json(['message' => $e->getMessage()], $status);
        } finally {
            if ($uploadId !== null) {
                // Handles opened by OcrClient are closed before this runs, including
                // on Windows, so only supplied staging can be removed after resolution.
                $this->uploads->discard($actor, $uploadId);
            }
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

    /**
     * Validate actual bytes rather than trusting browser MIME declarations or file
     * extensions. getimagesize reads the image header and identifies the format.
     *
     * @param  list<array{name: string, relative_path: string, path: string, size: int}>  $files
     */
    private function validateImages(array $files, string $validationField): void
    {
        $count = count($files);

        if ($count < 1 || $count > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                $validationField => 'Prediction requires between 1 and '.self::MAX_IMAGES.' completed images.',
            ]);
        }

        foreach ($files as $file) {
            if ($file['size'] > self::MAX_IMAGE_BYTES) {
                throw ValidationException::withMessages([
                    $validationField => "'{$file['name']}' exceeds the 20 MB image limit.",
                ]);
            }

            $image = @getimagesize($file['path']);
            $type = is_array($image) ? ($image[2] ?? null) : null;

            if (! in_array($type, self::ALLOWED_IMAGE_TYPES, true)) {
                throw ValidationException::withMessages([
                    $validationField => "'{$file['name']}' is not a valid PNG, JPG, BMP, or TIFF image.",
                ]);
            }
        }
    }
}
