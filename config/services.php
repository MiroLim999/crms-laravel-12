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
    | The FastAPI process in api/. It has no authentication of its own, so it must
    | stay bound to 127.0.0.1 and is only ever called server-side from Laravel.
    |
    | Timeout is generous because a cold start loads ~1.3 GB of weights before the
    | first prediction returns.
    |
    */

    'ocr' => [
        'url' => env('OCR_API_URL', 'http://127.0.0.1:8001'),
        'timeout' => env('OCR_API_TIMEOUT', 120),

        /*
         * Process control for the Run / Stop buttons in the OCR workspace, so a
         * Super Admin never has to type the uvicorn command into a terminal.
         *
         * Every value here ends up on a command line, and none of it may come from
         * a request. `host` is additionally forced to a loopback address in
         * EngineProcess: the service has no authentication of its own, so binding
         * it anywhere routable would publish unauthenticated model and dataset
         * deletion to the network.
         *
         * Set OCR_MANAGED=false where something else supervises the process.
         */
        'managed' => env('OCR_MANAGED', true),
        'python' => env('OCR_PYTHON', 'python'),
        'module' => env('OCR_MODULE', 'ml.api.main:app'),
        'host' => env('OCR_HOST', '127.0.0.1'),
        'port' => env('OCR_PORT', 8001),
    ],

];
