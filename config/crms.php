<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Super Admin
    |--------------------------------------------------------------------------
    |
    | The one CRMS account, made by `php artisan db:seed`. This password is only
    | the first one: the account must choose its own at the first sign-in, and
    | running the seeder again never resets an existing account.
    |
    */

    'super_admin' => [
        'name' => env('CRMS_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('CRMS_SUPER_ADMIN_EMAIL', 'superadmin@admin.com'),
        'password' => env('CRMS_SUPER_ADMIN_PASSWORD', 'superadmin@admin.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR Review Threshold
    |--------------------------------------------------------------------------
    |
    | Fields returned below this confidence are flagged for review. Confidence is
    | the model's certainty in its own output, not accuracy, so this is a review
    | prompt and never a quality guarantee.
    |
    */

    'confidence_review_threshold' => env('CRMS_CONFIDENCE_THRESHOLD', 80.0),

    /*
    |--------------------------------------------------------------------------
    | Registry Reporting Timezone
    |--------------------------------------------------------------------------
    |
    | Database timestamps remain UTC, while dashboard day and month boundaries
    | follow the civil registry's local working day.
    |
    */

    'reporting_timezone' => env('CRMS_REPORTING_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Batch File Upload: how long an unfinished batch is kept
    |--------------------------------------------------------------------------
    |
    | A batch is kept on the server while it is worked on, so it can be resumed
    | after a reload or a crash. Its queued pages are kept with it (past the
    | usual 24 hours) until it has been left untouched this many days; then
    | documents:prune-pages removes it, its pages and their queued files.
    |
    */

    'scan_batches' => [
        'keep_days' => (int) env('CRMS_SCAN_BATCH_KEEP_DAYS', 7),
    ],

];
