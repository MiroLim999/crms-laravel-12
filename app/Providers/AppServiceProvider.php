<?php

namespace App\Providers;

use App\Services\Ocr\OcrClient;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OcrClient::class, fn () => new OcrClient(
            config('services.ocr.url'),
            (int) config('services.ocr.timeout'),
        ));
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
