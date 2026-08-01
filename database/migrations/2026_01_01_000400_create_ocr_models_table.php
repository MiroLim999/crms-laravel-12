<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of TrOCR models known to CRMS.
 *
 * The weights themselves live on disk under Models/ and are owned by the FastAPI
 * service. This table holds what Laravel needs: which model is active for Staff
 * scanning, plus notes and evaluation figures recorded by a Super Admin. Rows are
 * reconciled against the service's /models endpoint rather than being the source
 * of truth for existence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_models', function (Blueprint $table) {
            $table->id();

            // Matches the folder name under Models/, or 'base' for the
            // un-fine-tuned upstream model.
            $table->string('key')->unique();
            $table->string('label')->nullable();
            $table->text('notes')->nullable();

            // Exactly one row may be active. Staff scanning uses this model, which
            // is why it lives in the database rather than in the browser.
            $table->boolean('is_active')->default(false);

            // Evaluation figures from the offline scripts, recorded when a model is
            // promoted so the decision is traceable.
            $table->decimal('cer', 6, 4)->nullable();
            $table->decimal('wer', 6, 4)->nullable();
            $table->decimal('exact_match', 6, 4)->nullable();
            $table->timestamp('evaluated_at')->nullable();

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_models');
    }
};
