<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\DashboardController;
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

    // Self-service settings, available to every role.
    Route::get('settings', [AccountSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/password', [AccountSettingsController::class, 'updatePassword'])
        ->name('settings.password.update');

    /*
    |----------------------------------------------------------------------
    | Feature stubs - replaced slice by slice
    |----------------------------------------------------------------------
    |
    | Each carries its real `can:` gate already, so authorization is live and
    | testable before the feature itself exists. Replace the placeholder with a
    | real controller as each slice lands; do not relax the gate.
    |
    */

    // Digitization - Staff and Super Admin
    Route::view('documents/new', 'placeholder', [
        'title' => 'New Document',
        'description' => 'Upload a scanned certificate, mark its fields, and run the OCR model.',
    ])->middleware('can:documents.process')->name('documents.create');

    // Archive - every signed-in role
    Route::view('records', 'placeholder', [
        'title' => 'Records Archive',
        'description' => 'Search and view submitted civil registry records.',
    ])->middleware('can:records.view')->name('records.index');

    // Change requests - Staff raise them, Admin moderates
    Route::view('change-requests', 'placeholder', [
        'title' => 'Change Requests',
        'description' => 'Corrections to locked records: Staff request, Admin approves or rejects.',
    ])->name('change-requests.index');

    // Oversight - Admin and Super Admin
    Route::view('analytics', 'placeholder', [
        'title' => 'Analytics',
        'description' => 'Throughput, accuracy trends, and workload across the registry.',
    ])->middleware('can:analytics.view')->name('analytics.index');

    Route::view('reports', 'placeholder', [
        'title' => 'Reports',
        'description' => 'Generate registry reports for a given period or document type.',
    ])->middleware('can:reports.generate')->name('reports.index');

    Route::view('users', 'placeholder', [
        'title' => 'User Accounts',
        'description' => 'Provision accounts, issue temporary passwords, and manage roles.',
    ])->middleware('can:users.manage')->name('users.index');

    Route::view('audit', 'placeholder', [
        'title' => 'Audit Log',
        'description' => 'Immutable trail of every state change, with actor and before/after values.',
    ])->middleware('can:audit.view')->name('audit.index');

    // System - Super Admin only
    Route::view('templates', 'placeholder', [
        'title' => 'Document Templates',
        'description' => 'Define the field boxes captured for each certificate type.',
    ])->middleware('can:templates.manage')->name('templates.index');

    Route::view('ocr', 'placeholder', [
        'title' => 'OCR Models',
        'description' => 'Manage TrOCR models: add, rename, delete, set active, and review evaluation metrics.',
    ])->middleware('can:ocr.manage')->name('ocr.index');
});
