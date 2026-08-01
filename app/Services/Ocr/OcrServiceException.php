<?php

namespace App\Services\Ocr;

use RuntimeException;

/**
 * The OCR service was unreachable or refused the request.
 *
 * Callers should surface this as a clear "OCR service unavailable" state and must
 * not leave a half-saved record behind.
 */
class OcrServiceException extends RuntimeException
{
    public static function unreachable(string $reason): self
    {
        return new self("The OCR service is not reachable. {$reason}");
    }
}
