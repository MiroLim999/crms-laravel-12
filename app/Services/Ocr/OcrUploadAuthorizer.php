<?php

namespace App\Services\Ocr;

use App\Models\User;
use RuntimeException;

/**
 * Issues a short-lived HMAC ticket for one browser-to-FastAPI model upload.
 */
class OcrUploadAuthorizer
{
    public function issue(string $modelName, User $actor): string
    {
        $payload = json_encode([
            'purpose' => 'ocr-model-upload',
            'name' => $modelName,
            'user_id' => $actor->getKey(),
            'expires_at' => now()->addSeconds($this->ttl())->getTimestamp(),
            'nonce' => bin2hex(random_bytes(16)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $encoded = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encoded, $this->secret(), true);

        return $encoded.'.'.$this->base64UrlEncode($signature);
    }

    private function secret(): string
    {
        $secret = (string) config('services.ocr.upload_secret');

        if ($secret === '') {
            throw new RuntimeException(
                'OCR upload signing is not configured. Set OCR_UPLOAD_SECRET or APP_KEY.'
            );
        }

        return $secret;
    }

    private function ttl(): int
    {
        return max(60, min((int) config('services.ocr.upload_ticket_ttl', 900), 3600));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
