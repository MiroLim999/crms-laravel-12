<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First-run setup
    |--------------------------------------------------------------------------
    |
    | There is no default account. While no account exists, every page leads to
    | the Set up CRMS page, where the Super Admin's name, email and password are
    | chosen once. By default that page only opens on the CRMS computer itself,
    | so another PC on the network cannot claim the account first. Docker turns
    | this off: there the site is published on 127.0.0.1 only, and requests
    | arrive from Docker's own network address.
    |
    */

    'setup' => [
        'local_only' => (bool) env('CRMS_SETUP_LOCAL_ONLY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted host names
    |--------------------------------------------------------------------------
    |
    | Outside APP_ENV=local, a request whose Host header is not APP_URL's host
    | (or a subdomain of it) or one of these names is refused, so links Laravel
    | builds cannot be pointed at another site. Comma-separated host names or
    | IP addresses, e.g. "localhost,127.0.0.1,192.168.1.20".
    |
    */

    'trusted_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CRMS_TRUSTED_HOSTS', 'localhost,127.0.0.1')),
    ))),

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
