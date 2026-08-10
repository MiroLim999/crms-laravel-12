<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardSystemStatusController;
use App\Http\Controllers\DocumentScanController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\DocumentTypeDefinitionController;
use App\Http\Controllers\OcrEngineController;
use App\Http\Controllers\OcrModelController;
use App\Http\Controllers\OcrUploadController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Sign-in only. There is deliberately no registration route: Staff and Admin
| accounts are provisioned by a Super Admin, and the bootstrap Super Admin is
| seeded. Do not add one.
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
    Route::get('dashboard/system-status', DashboardSystemStatusController::class)
        ->middleware('can:ocr.manage')
        ->name('dashboard.system-status');

    // Self-service settings, available to every role.
    Route::get('settings', [AccountSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/password', [AccountSettingsController::class, 'updatePassword'])
        ->name('settings.password.update');

    /*
     * Digitisation - Staff and Super Admin.
     *
     * Admin has no route in here at all. Data entry is not an oversight function,
     * and the gate is the enforcement, not the missing nav link.
     */
    Route::middleware('can:documents.process')->group(function () {
        Route::get('documents/new', [DocumentScanController::class, 'create'])->name('documents.create');
        Route::get('documents/workspace', [DocumentScanController::class, 'workspace'])
            ->name('documents.workspace');
        // Proxies the FastAPI service; never called from the browser directly.
        Route::post('documents/recognise', [DocumentScanController::class, 'recognise'])
            ->name('documents.recognise');
        Route::post('documents', [DocumentScanController::class, 'store'])->name('documents.store');
    });

    /*
     * Archive - every signed-in role may read it.
     *
     * Read-only for everyone, including Super Admin. There is no update or destroy
     * route: submitted records change only through an approved change request.
     */
    Route::middleware('can:records.view')->group(function () {
        Route::get('records', [RecordController::class, 'index'])->name('records.index');
        Route::get('records/{record}', [RecordController::class, 'show'])->name('records.show');
        Route::get('records/{record}/scan', [RecordController::class, 'scan'])->name('records.scan');
    });

    /*
     * Change requests - Staff propose, Admin and Super Admin decide.
     *
     * Approving is what writes the new values, which is why there is no direct
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

    Route::post('change-requests/{changeRequest}/withdraw', [ChangeRequestController::class, 'withdraw'])
        ->name('change-requests.withdraw');

    Route::middleware('can:change-requests.moderate')->group(function () {
        Route::post('change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])
            ->name('change-requests.approve');
        Route::post('change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])
            ->name('change-requests.reject');
    });

    /*
     * Oversight - Admin and Super Admin.
     *
     * Analytics now live on the role-aware Dashboard. Keep the old gated URL as a
     * redirect so saved bookmarks continue to work. Reports still list/export,
     * and neither route is a write path to record values.
     */
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('can:analytics.view')
        ->name('analytics.index');

    Route::middleware('can:reports.generate')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    /*
     * User management - Admin and Super Admin.
     *
     * Accounts are deactivated, never deleted, so the audit trail keeps pointing
     * at a real row. There is deliberately no destroy route.
     */
    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/password', [UserController::class, 'resetPassword'])
            ->name('users.password.reset');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->name('users.deactivate');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])
            ->name('users.activate');
    });

    /*
     * Audit log - view only. The trail is append-only, so there is no route here
     * that could edit or remove an entry, and none may be added.
     */
    Route::get('audit', [AuditLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('audit.index');

    /*
     * Document template builder - Super Admin only. Templates decide which fields
     * Staff capture, so changing one changes what the registry records.
     */
    Route::middleware('can:templates.manage')->group(function () {
        Route::post('templates/document-types', [DocumentTypeDefinitionController::class, 'store'])
            ->name('templates.document-types.store');
        Route::put('templates/document-types/{documentType}', [DocumentTypeDefinitionController::class, 'update'])
            ->name('templates.document-types.update');
        Route::delete('templates/document-types/{documentType}', [DocumentTypeDefinitionController::class, 'destroy'])
            ->name('templates.document-types.destroy');
        Route::get('templates', [DocumentTemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/create', [DocumentTemplateController::class, 'create'])->name('templates.create');
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
        Route::delete('templates/{template}', [DocumentTemplateController::class, 'destroy'])
            ->name('templates.destroy');
    });

    /*
     * OCR workspace - Super Admin only, one page.
     *
     * It does one job: decide which TrOCR model Staff scan with, and manage the
     * models installed on disk. Fine-tuning, evaluation, dataset preparation, and
     * batch prediction are command-line work under ml/ - deliberately not driven
     * from here, and CRMS does not start or stop the service process either.
     *
     * These routes still reach through to a service that has no authentication of
     * its own and can delete ~1.3 GB of weights, which is why the gate never widens
     * beyond Super Admin.
     */
    Route::middleware('can:ocr.manage')->prefix('ocr')->name('ocr.')->group(function () {
        Route::get('/', [OcrModelController::class, 'index'])->name('index');
        Route::post('rescan', [OcrModelController::class, 'rescan'])->name('rescan');

        // The Save settings button: which model Staff scan with, plus the two
        // scanning settings that go with that decision.
        Route::post('settings', [OcrModelController::class, 'saveSettings'])->name('settings');

        // Read-only. The workspace polls it so the page notices the service coming
        // up or going away without a manual refresh.
        Route::get('engine/status', [OcrEngineController::class, 'status'])->name('engine.status');

        // Direct upload control plane. Laravel authorizes and records the action;
        // the multi-gigabyte body travels from the browser straight to FastAPI.
        Route::post('uploads/authorize', [OcrUploadController::class, 'authorizeUpload'])
            ->name('uploads.authorize');
        Route::post('models/register', [OcrUploadController::class, 'register'])
            ->name('register');

        // Models on disk.
        Route::post('models/{key}/rename', [OcrModelController::class, 'rename'])->name('rename');
        Route::delete('models/{key}', [OcrModelController::class, 'destroy'])->name('destroy');
    });
});
