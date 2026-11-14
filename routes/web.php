<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentPageController;
use App\Http\Controllers\DocumentScanController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\DocumentTypeDefinitionController;
use App\Http\Controllers\OcrEngineController;
use App\Http\Controllers\OcrModelController;
use App\Http\Controllers\OcrUploadController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Sign-in only. CRMS has one account, the Super Admin, made by the seeder.
| There is deliberately no registration route. A forgotten password is reset
| with `php artisan crms:reset-password`.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Forced password change
|--------------------------------------------------------------------------
|
| Reachable while EnsurePasswordIsChanged blocks everything else.
|
*/

Route::middleware('auth')->group(function () {
    Route::get('password/change', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::post('password/change', [PasswordChangeController::class, 'update'])->name('password.change.store');
});

/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::redirect('/', '/dashboard');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Your own name, email and password.
    Route::get('settings', [AccountSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/profile', [AccountSettingsController::class, 'updateProfile'])
        ->name('settings.profile.update');
    Route::put('settings/password', [AccountSettingsController::class, 'updatePassword'])
        ->name('settings.password.update');

    // Digitisation: scanning, Align and Verify.
    Route::middleware('can:documents.process')->group(function () {
        Route::get('documents/new', [DocumentScanController::class, 'create'])->name('documents.create');
        Route::get('documents/workspace', [DocumentScanController::class, 'workspace'])
            ->name('documents.workspace');
        Route::post('documents', [DocumentScanController::class, 'store'])->name('documents.store');
        // Which pages of one scanned file (by its SHA-256) are already saved.
        Route::get('documents/sources/{hash}/pages', [DocumentScanController::class, 'sourcePages'])
            ->where('hash', '[0-9a-f]{64}')
            ->name('documents.sources.pages');

        // Finishing Align uploads the page and starts line detection in the
        // background; Verify polls for the outlines and readings, shows the crop
        // TrOCR read, and re-reads a line after its outline is redrawn.
        Route::post('documents/pages', [DocumentPageController::class, 'store'])
            ->name('documents.pages.store');
        // Snap to table in Align: fit the markers to the printed table, fast.
        Route::post('documents/pages/snap', [DocumentPageController::class, 'snap'])
            ->name('documents.pages.snap');
        Route::get('documents/pages/{page}', [DocumentPageController::class, 'show'])
            ->name('documents.pages.show');
        Route::get('documents/pages/{page}/image', [DocumentPageController::class, 'image'])
            ->name('documents.pages.image');
        Route::post('documents/pages/{page}/read', [DocumentPageController::class, 'read'])
            ->name('documents.pages.read');
        // Markers moved after Detect: outline this page again from them and read
        // it, reusing the lines Detect already found instead of detecting again.
        Route::put('documents/pages/{page}/geometry', [DocumentPageController::class, 'reoutline'])
            ->name('documents.pages.reoutline');
        Route::post('documents/pages/{page}/cancel', [DocumentPageController::class, 'cancel'])
            ->name('documents.pages.cancel');
        Route::get('documents/pages/{page}/lines/{line}/crop', [DocumentPageController::class, 'crop'])
            ->scopeBindings()
            ->name('documents.pages.lines.crop');
        Route::put('documents/pages/{page}/lines/{line}', [DocumentPageController::class, 'updateLine'])
            ->scopeBindings()
            ->name('documents.pages.lines.update');
        // Draw a line by hand where the detector found none (Align after
        // Detect, or Verify's "Add missed field", which reads it at once).
        Route::post('documents/pages/{page}/lines', [DocumentPageController::class, 'storeLine'])
            ->name('documents.pages.lines.store');
        // Move a ledger line to another person's row or another column.
        Route::patch('documents/pages/{page}/lines/{line}/place', [DocumentPageController::class, 'placeLine'])
            ->scopeBindings()
            ->name('documents.pages.lines.place');
        // Move whole rows of the page to other people at once (Verify: a row of
        // a register's second page that landed on the wrong person).
        Route::patch('documents/pages/{page}/rows', [DocumentPageController::class, 'moveRows'])
            ->name('documents.pages.rows');
        // Remove one line before it is read: a stray mark, a wrongly split
        // word, or one that does not belong. Only while Detect's result stands.
        // It is kept, with its crop, until the page is outlined again, so
        // restore puts it back (Ctrl+Z in the Align step).
        Route::delete('documents/pages/{page}/lines/{line}', [DocumentPageController::class, 'destroyLine'])
            ->scopeBindings()
            ->name('documents.pages.lines.destroy');
        // Verify: delete a field's wrong crop (it must be drawn again), or take that back.
        Route::post('documents/pages/{page}/lines/{line}/redraw', [DocumentPageController::class, 'markRedraw'])
            ->scopeBindings()
            ->name('documents.pages.lines.redraw');
        Route::post('documents/pages/{page}/lines/{line}/restore', [DocumentPageController::class, 'restoreLine'])
            ->scopeBindings()
            ->withTrashed()
            ->name('documents.pages.lines.restore');
    });

    /*
     * Archive. There is no update or destroy route: a saved record changes only
     * through a correction, which keeps the old values in its history.
     */
    Route::middleware('can:records.view')->group(function () {
        Route::get('records', [RecordController::class, 'index'])->name('records.index');
        Route::get('records/{record}', [RecordController::class, 'show'])->name('records.show');
        // One document's checked values as CSV, one row per person, for the client's old system.
        Route::get('records/{record}/csv', [RecordController::class, 'csv'])->name('records.csv');
        Route::get('records/{record}/scan', [RecordController::class, 'scan'])->name('records.scan');
        Route::get('records/{record}/page', [RecordController::class, 'pageImage'])->name('records.page-image');
        Route::get('records/{record}/pages/{pageNo}/page', [RecordController::class, 'extraPageImage'])
            ->whereNumber('pageNo')->name('records.extra-page-image');
        Route::get('records/{record}/pages/{pageNo}/scan', [RecordController::class, 'extraPageScan'])
            ->whereNumber('pageNo')->name('records.extra-page-scan');
    });

    /*
     * Corrections (still named change requests in the code). Saving one changes the
     * record at once and keeps the old and new values, so there is no plain
     * record-edit route anywhere in this file.
     */
    Route::get('change-requests', [ChangeRequestController::class, 'index'])
        ->name('change-requests.index');
    Route::get('change-requests/{changeRequest}', [ChangeRequestController::class, 'show'])
        ->name('change-requests.show');

    Route::middleware('can:change-requests.create')->group(function () {
        Route::get('records/{record}/change-request', [ChangeRequestController::class, 'create'])
            ->name('records.change-requests.create');
        Route::post('records/{record}/change-request', [ChangeRequestController::class, 'store'])
            ->name('records.change-requests.store');
    });

    // Reports list and export records. Neither is a write path to record values.
    Route::middleware('can:reports.generate')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    /*
     * Audit log - view only. The trail is append-only, so there is no route here
     * that could edit or remove an entry, and none may be added.
     */
    Route::get('audit', [AuditLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('audit.index');

    /*
     * Document template builder. Templates decide which fields a scan captures,
     * so changing one changes what the registry records.
     */
    Route::middleware('can:templates.manage')->group(function () {
        Route::post('templates/document-types', [DocumentTypeDefinitionController::class, 'store'])
            ->name('templates.document-types.store');
        Route::put('templates/document-types/{documentType}', [DocumentTypeDefinitionController::class, 'update'])
            ->name('templates.document-types.update');
        Route::post('templates/document-types/{documentType}/archive', [DocumentTypeDefinitionController::class, 'archive'])
            ->name('templates.document-types.archive');
        Route::post('templates/document-types/{documentType}/restore', [DocumentTypeDefinitionController::class, 'restore'])
            ->name('templates.document-types.restore');
        Route::delete('templates/document-types/{documentType}', [DocumentTypeDefinitionController::class, 'destroy'])
            ->name('templates.document-types.destroy');
        Route::get('templates', [DocumentTemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/create', [DocumentTemplateController::class, 'create'])->name('templates.create');
        // Suggests a ledger grid from the printed rules on a sample page.
        Route::post('templates/detect-grid', [DocumentTemplateController::class, 'detectGrid'])
            ->name('templates.detect-grid');
        Route::post('templates', [DocumentTemplateController::class, 'store'])->name('templates.store');
        Route::get('templates/{template}/edit', [DocumentTemplateController::class, 'edit'])
            ->name('templates.edit');
        Route::get('templates/{template}/sample', [DocumentTemplateController::class, 'sample'])
            ->name('templates.sample');
        Route::delete('templates/{template}/sample', [DocumentTemplateController::class, 'destroySample'])
            ->name('templates.sample.destroy');
        Route::put('templates/{template}', [DocumentTemplateController::class, 'update'])
            ->name('templates.update');
        Route::post('templates/{template}/activate', [DocumentTemplateController::class, 'activate'])
            ->name('templates.activate');
        Route::post('templates/{template}/duplicate', [DocumentTemplateController::class, 'duplicate'])
            ->name('templates.duplicate');
        Route::post('templates/{template}/restore', [DocumentTemplateController::class, 'restore'])
            ->name('templates.restore');
        Route::delete('templates/{template}', [DocumentTemplateController::class, 'destroy'])
            ->name('templates.destroy')
            // A second click, or an old tab, after the layout was erased.
            ->missing(fn () => redirect()->route('templates.index')->with('info', 'That layout was already deleted.'));
    });

    /*
     * OCR workspace, one page.
     *
     * It decides which TrOCR model scans use, manages the models installed
     * on disk, and presents read-only performance evidence. Fine-tuning, evaluation,
     * dataset preparation, and batch prediction are command-line work under ml/ -
     * deliberately not driven from here, and CRMS does not start or stop the service
     * process either.
     *
     * These routes still reach through to a service that has no authentication of
     * its own and can delete ~1.3 GB of weights, which is why it stays behind its gate.
     */
    Route::middleware('can:ocr.manage')->prefix('ocr')->name('ocr.')->group(function () {
        Route::get('/', [OcrModelController::class, 'index'])->name('index');
        Route::post('rescan', [OcrModelController::class, 'rescan'])->name('rescan');

        // Read-only. The workspace polls it so the page notices the service coming
        // up or going away without a manual refresh.
        Route::get('engine/status', [OcrEngineController::class, 'status'])->name('engine.status');

        // Direct upload control plane. Laravel authorizes and records the action;
        // the multi-gigabyte body travels from the browser straight to FastAPI.
        Route::post('uploads/authorize', [OcrUploadController::class, 'authorizeUpload'])
            ->name('uploads.authorize');
        Route::post('models/register', [OcrUploadController::class, 'register'])
            ->name('register');

        // Models on disk. Not named `ocr.activate`: the tests keep that name on the
        // list of routes that were removed.
        Route::post('models/{key}/activate', [OcrModelController::class, 'activate'])->name('models.activate');
        Route::post('models/{key}/rename', [OcrModelController::class, 'rename'])->name('rename');
        Route::post('models/{key}/color', [OcrModelController::class, 'color'])->name('models.color');
        Route::delete('models/{key}', [OcrModelController::class, 'destroy'])->name('destroy');
    });
});
