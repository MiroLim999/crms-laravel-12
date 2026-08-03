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
        'timeout' => env('OCR_API_TIMEOUT', 120),
    ],

];
