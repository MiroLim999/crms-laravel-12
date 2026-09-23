<?php

namespace App\Services\Lines;

use App\Models\DocumentPage;
use App\Models\PageLine;
use App\Services\Ocr\OcrClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Reads page lines with TrOCR from their stored masked crops.
 *
 * The crop files written by line_markers.py are the only images sent, so what
 * TrOCR reads, what the reviewer sees as a thumbnail, and what the training
 * export copies are always the same file.
 */
class PageLineReader
{
    /** Crops per request: keeps each call well inside the OCR timeout. */
    private const BATCH = 40;

    public function __construct(private readonly OcrClient $ocr) {}

    /**
     * @param  Collection<int, PageLine>  $lines
     */
    public function read(DocumentPage $page, Collection $lines): void
    {
        $disk = Storage::disk('local');

        foreach ($lines->chunk(self::BATCH) as $batch) {
            $fields = $batch->map(fn (PageLine $line) => [
                'name' => 'line-'.$line->getKey(),
                'image' => 'data:image/png;base64,'.base64_encode($disk->get($line->crop_path)),
            ])->values()->all();

            $result = $this->ocr->recognise($fields, $page->ocr_model_key);

            $byName = collect($result['results'])->keyBy('name');
            foreach ($batch as $line) {
                $reading = $byName->get('line-'.$line->getKey());
                $line->forceFill([
                    'ocr_text' => $reading['text'] ?? '',
                    'ocr_confidence' => $reading['confidence'] ?? 0.0,
                    'ocr_error' => isset($reading['error']) ? mb_substr((string) $reading['error'], 0, 500) : null,
                ])->save();
            }

            if (($result['modelKey'] ?? '') !== '') {
                $page->forceFill([
                    'ocr_model_key' => $result['modelKey'],
                    'ocr_model_label' => $result['model'] ?? $result['modelKey'],
                ])->save();
            }
        }
    }
}
