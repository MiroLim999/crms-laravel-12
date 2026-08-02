<?php

namespace App\Http\Controllers;

use App\Services\Ocr\ChunkedUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives one slice of a chunked upload - Super Admin only.
 *
 * PHP caps uploads at 40M here while a model folder is around 1.3 GB and a dataset
 * runs to thousands of images. A plain multipart post is rejected by PHP before
 * Laravel runs, so the browser slices each file and posts the pieces at this
 * endpoint; ChunkedUpload stitches them back together.
 *
 * The chunk size is the browser's choice but must stay under `upload_max_filesize`.
 */
class OcrUploadController extends Controller
{
    public function __construct(private readonly ChunkedUpload $uploads) {}

    public function chunk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Browser-generated, so treated as untrusted: ChunkedUpload applies its
            // own pattern check and namespaces the directory by user id.
            'upload_id' => ['required', 'string', 'max:64'],
            'file_key' => ['required', 'string', 'max:64'],
            'filename' => ['required', 'string', 'max:255'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1'],
            'chunk' => ['required', 'file'],
        ]);

        $result = $this->uploads->receive(
            $request->user(),
            $validated['upload_id'],
            $validated['file_key'],
            $validated['filename'],
            (int) $validated['index'],
            (int) $validated['total'],
            $request->file('chunk'),
        );

        // Opportunistic sweep of uploads abandoned mid-flight; a half-sent 1.3 GB
        // model would otherwise sit in storage forever.
        if ((int) $validated['index'] === 0) {
            $this->uploads->prune();
        }

        return response()->json($result);
    }

    /**
     * Abandon an upload and free the disk it was using.
     */
    public function discard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'max:64'],
        ]);

        $this->uploads->discard($request->user(), $validated['upload_id']);

        return response()->json(['discarded' => true]);
    }
}
