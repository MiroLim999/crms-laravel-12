<?php

namespace App\Services\Ocr;

use App\Models\OcrModel;

/**
 * Which TrOCR model a scan reads with: always the active model, the one chosen in
 * OCR Workspace. Shared by the scan workspace and the page-processing flow.
 */
class ScanModelChoice
{
    public function resolve(): ?string
    {
        return OcrModel::active()?->key;
    }
}
