<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of training datasets known to CRMS.
 *
 * The images live on disk under ml/datasets/<name>/ and are owned by the FastAPI
 * service, exactly like model weights. This table records who uploaded a dataset
 * and what the last validation run said about it, so the decision to spend hours
 * of GPU time training on it is traceable.
 *
 * Rows are reconciled against the service's /datasets endpoint rather than being
 * the source of truth for existence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_datasets', function (Blueprint $table) {
            $table->id();

            // Matches the folder name under ml/datasets/.
            $table->string('name')->unique();
            $table->text('notes')->nullable();

            // Per-split image counts, mirrored from the service so the list renders
            // without walking thousands of files on every page load.
            $table->unsignedInteger('train_count')->default(0);
            $table->unsignedInteger('val_count')->default(0);
            $table->unsignedInteger('test_count')->default(0);
            $table->unsignedInteger('total_images')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);

            // The full validation report. Null means it has never been validated,
            // which is different from "validated and failed".
            $table->json('validation')->nullable();
            $table->boolean('is_valid')->nullable();
            $table->timestamp('validated_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_datasets');
    }
};
