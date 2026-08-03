<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OCR workspace's saved settings.
 *
 * Deliberately a singleton: there is one registry, so there is one set of scanning
 * settings. A table rather than .env because a Super Admin changes these from the
 * UI and the change has to be audited and take effect without a deploy.
 *
 * Which model is active still lives in `ocr_models.is_active` - that is a property
 * of a model, not a setting - but the Save settings form writes both together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_settings', function (Blueprint $table) {
            $table->id();

            // Whether Staff may pick a different model than the promoted one when
            // they scan. Off means every reading in the registry came from the model
            // a Super Admin approved, which is the stricter, auditable default.
            $table->boolean('allow_staff_model_choice')->default(false);

            // Null falls back to config('crms.confidence_review_threshold'), so an
            // untouched install behaves exactly as it did before this table existed.
            $table->decimal('confidence_review_threshold', 5, 2)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_settings');
    }
};
