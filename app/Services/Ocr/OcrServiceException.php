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
    /**
     * The service's HTTP status, when it answered at all. Null means the transport
     * itself failed, which is a different problem to report.
     */
    public ?int $status = null;

    public static function unreachable(string $reason): self
    {
        return new self("The OCR service is not reachable. {$reason}");
    }

    public static function refused(string $message, int $status): self
    {
        $e = new self($message);
        $e->status = $status;

        return $e;
    }

    /**
     * A GPU job is already running. The only conflict the service reports, and the
     * one the workspace needs to present differently: it is not an error, it is a
     * "wait or cancel" state.
     */
    public function isBusy(): bool
    {
        return $this->status === 409;
    }

    public function isUnreachable(): bool
    {
        return $this->status === null;
    }
}
