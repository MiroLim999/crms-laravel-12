<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TrOCR service
    |--------------------------------------------------------------------------
    |
    | The FastAPI process in ml/api/. It has no authentication of its own, so it
    | must stay bound to 127.0.0.1 and is only ever called server-side from Laravel.
    |
    | Timeout is generous because a cold start loads ~1.3 GB of weights before the
    | first prediction returns.
    |
    | CRMS does not start or stop this process. Run it from a terminal in
    | development, or under a supervisor in a deployment; the OCR workspace reports
    | whether it answers and shows the command, and nothing more.
    |
    */

    'ocr' => [
        'url' => env('OCR_API_URL', 'http://127.0.0.1:8001'),
        // Browser-facing URL for the one large model-upload request. In local CRMS
        // this is the same loopback service; deployments may reverse-proxy it.
        'browser_url' => env('OCR_BROWSER_API_URL', env('OCR_API_URL', 'http://127.0.0.1:8001')),
        'timeout' => env('OCR_API_TIMEOUT', 120),
        // FastAPI reads the same value. APP_KEY is a safe zero-configuration
        // fallback for this two-process repository; a dedicated secret can rotate
        // upload tickets independently in deployment.
        'upload_secret' => env('OCR_UPLOAD_SECRET') ?: env('APP_KEY'),
        'upload_ticket_ttl' => env('OCR_UPLOAD_TICKET_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Line markers (ml/line_markers.py)
    |--------------------------------------------------------------------------
    |
    | Outlines every handwritten line on an aligned page and crops along the
    | outlines. Runs as a local subprocess in its own Python environment,
    | ml/.venv-kraken, because Kraken needs a newer torch than the TrOCR service.
    | Build it with ml\setup_kraken.ps1. Leave `python` empty to use that
    | environment when it exists.
    |
    | Nothing leaves the machine: Kraken's model ships inside its wheel.
    |
    */

    'line_markers' => [
        'python' => env('LINE_MARKERS_PYTHON'),
        'script' => base_path('ml/line_markers.py'),
        // One page on CPU takes about half a minute; a slow machine gets room.
        'timeout' => (int) env('LINE_MARKERS_TIMEOUT', 600),
        // Unsubmitted pages older than this are removed by documents:prune-pages.
        'keep_hours' => (int) env('LINE_MARKERS_KEEP_HOURS', 24),
    ],

];
