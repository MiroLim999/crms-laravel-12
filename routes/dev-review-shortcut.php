<?php

// DEV ONLY: Review & Export shortcut (temporary). See config/dev_review_shortcut.php
// for what it is and how to remove it. Loaded by routes/web.php only when it is on.

use App\Http\Controllers\Dev\ReviewShortcutController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:documents.process'])
    ->prefix('dev/review-shortcut')
    ->name('dev.review-shortcut.')
    ->group(function () {
        // Whether a scan is saved for the shortcut.
        Route::get('/', [ReviewShortcutController::class, 'status'])->name('status');
        // The saved file (the PDF the saved scan was read from).
        Route::get('file', [ReviewShortcutController::class, 'file'])->name('file');
        // Save the pages just read in a real scan (and their file) for the shortcut.
        Route::post('/', [ReviewShortcutController::class, 'store'])->name('store');
        // Fresh copies of the saved pages, ready to verify, as a real scan leaves them.
        Route::post('pages', [ReviewShortcutController::class, 'pages'])->name('pages');
        // Batch mode: the PDFs in pdf-files and what is saved of their reading.
        Route::get('batch', [ReviewShortcutController::class, 'batchStatus'])->name('batch.status');
        // One of those PDFs (to cut its pages as originals).
        Route::get('batch/file', [ReviewShortcutController::class, 'batchFile'])->name('batch.file');
        // Shift+click in a batch: save the pages it has read for the shortcut.
        Route::post('batch/store', [ReviewShortcutController::class, 'batchStore'])->name('batch.store');
        // A batch of those PDFs, read and ticked, to resume.
        Route::post('batch', [ReviewShortcutController::class, 'batch'])->name('batch');
    });
