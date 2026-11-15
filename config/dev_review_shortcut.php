<?php

/*
|--------------------------------------------------------------------------
| DEV ONLY: Review & Export shortcut (temporary)
|--------------------------------------------------------------------------
|
| A button on the scan workspace that skips Document, Align and Verify and
| opens step 4 on a scan saved once, so the Review & Export page can be
| worked on without scanning again each time.
|
| On only when APP_ENV=local AND DEV_REVIEW_SHORTCUT=true in .env.
|
| To remove it completely, delete:
|   config/dev_review_shortcut.php                        (this file)
|   routes/dev-review-shortcut.php
|   app/Http/Controllers/Dev/ReviewShortcutController.php
|   resources/views/scan/partials/dev-review-shortcut.blade.php
|   storage/app/private/dev-review-shortcut/             (the saved scan)
| and the one line marked "DEV ONLY" in routes/web.php and in
| resources/views/scan/workspace.blade.php, and DEV_REVIEW_SHORTCUT in .env.
|
*/

return [
    'enabled' => env('APP_ENV') === 'local' && (bool) env('DEV_REVIEW_SHORTCUT', false),
];
