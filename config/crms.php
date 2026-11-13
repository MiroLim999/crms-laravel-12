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

];
