<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Civil registry records digitised from scanned certificates.
 *
 * A record starts as a draft while Staff verify the OCR output, then becomes
 * locked on submission. Locked values are only changed through an approved change
 * request - never edited in place, and never by an Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();

            $table->string('doc_type', 32);
            $table->foreignId('document_template_id')->nullable()
                ->constrained()->nullOnDelete();

            // Registry reference as written on the certificate.
            $table->string('registry_number', 64)->nullable();

            // draft -> submitted. Submitted records are locked.
            $table->string('status', 16)->default('draft');

            // Stored scan, relative to the configured disk.
            $table->string('scan_path')->nullable();
            $table->string('scan_mime', 64)->nullable();

            // Which OCR model produced the extraction, kept for traceability.
            $table->string('ocr_model_key')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->index(['doc_type', 'status']);
            $table->index('registry_number');
            $table->index('submitted_at');
        });

        Schema::create('record_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // What the model read, and how certain it was of its own output.
            // Confidence is not accuracy - it is a review prompt.
            $table->text('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 1)->nullable();

            // What the human confirmed. This is the value of record.
            $table->text('verified_value')->nullable();

            // Crop of the scan for this field, so the archive can show the
            // original handwriting beside the transcription.
            $table->string('crop_path')->nullable();

            // Field box at capture time, so the crop stays reproducible even if
            // the template is later edited.
            $table->decimal('x', 6, 5)->nullable();
            $table->decimal('y', 6, 5)->nullable();
            $table->decimal('width', 6, 5)->nullable();
            $table->decimal('height', 6, 5)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['record_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_fields');
        Schema::dropIfExists('records');
    }
};
