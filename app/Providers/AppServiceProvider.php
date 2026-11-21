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
            config('services.ocr.upload_secret'),
        ));
    }

    public function boot(): void
    {
        // The scan workspace view is large (its script is inline): compiling it needs a
        // little over PHP's default 1,000,000 regex backtracks, and the compile silently
        // drops most of the page when that runs out. Ten times the default leaves room.
        ini_set('pcre.backtrack_limit', '10000000');

        Vite::prefetch(concurrency: 3);
    }
}
