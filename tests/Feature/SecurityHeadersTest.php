<?php

namespace Tests\Feature;

use App\Support\SecretFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_browser_security_headers(): void
    {
        // A redirect (no account yet: every page leads to setup) and a page.
        foreach (['/login', '/setup'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'same-origin');
            $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
            // Plain HTTP: HSTS would be ignored, and is only sent over HTTPS.
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/setup')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_ocr_secret_is_read_from_a_docker_secret_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'crms-secret');
        file_put_contents($file, "file-secret\n");
        putenv("CRMS_TEST_SECRET_FILE={$file}");
        $_ENV['CRMS_TEST_SECRET_FILE'] = $_SERVER['CRMS_TEST_SECRET_FILE'] = $file;

        try {
            $this->assertSame('file-secret', SecretFile::value('CRMS_TEST_SECRET_FILE', 'fallback'));
        } finally {
            putenv('CRMS_TEST_SECRET_FILE');
            unset($_ENV['CRMS_TEST_SECRET_FILE'], $_SERVER['CRMS_TEST_SECRET_FILE']);
            @unlink($file);
        }

        $this->assertSame('fallback', SecretFile::value('CRMS_TEST_SECRET_FILE', 'fallback'));
    }
}
