<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Super Admin
    |--------------------------------------------------------------------------
    |
    | The one account that is seeded rather than created through the UI. The
    | defaults match the documented prototype credentials; override them in .env
    | for any deployment that is not a local demo.
    |
    */

    'super_admin' => [
        'name' => env('CRMS_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('CRMS_SUPER_ADMIN_EMAIL', 'superadmin@admin.com'),
        'password' => env('CRMS_SUPER_ADMIN_PASSWORD', 'superadmin@admin.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Passwords
    |--------------------------------------------------------------------------
    |
    | Length of the generated temporary password handed to a newly provisioned
    | Admin or Staff account. The holder is forced to change it on first login.
    |
    */

    'temporary_password_length' => 12,

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

];
