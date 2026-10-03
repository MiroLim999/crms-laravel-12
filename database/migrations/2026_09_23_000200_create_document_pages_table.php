<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An aligned page waiting for, or holding the results of, line detection.
 *
 * Created when Staff finish the Align step. A background job outlines every
 * handwritten line, crops along the outlines, and reads each crop with TrOCR.
 * The results are stored here so reopening the Verify step never recomputes
 * them. Submitting the record copies what it needs and removes the page;
 * pages that are never submitted are pruned (documents:prune-pages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // queued -> detecting -> reading -> ready, or failed.
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();

            // The aligned page image exactly as Staff saw it, on the local disk.
            $table->string('image_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            // Template geometry aligned to this page (page fractions).
            $table->json('geometry');

            $table->string('ocr_model_key')->nullable();
            $table->string('ocr_model_label')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'status']);
        });

        Schema::create('page_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // kraken (detected), ink (built from ink the detector missed), or
            // template (an older template's rectangle, as a four-point polygon).
            $table->string('source', 16);
            $table->unsignedSmallInteger('column_index')->nullable();
            $table->string('column_name', 500);
            $table->unsignedSmallInteger('row')->nullable();

            // Carried from a template rectangle's own person grouping.
            $table->unsignedSmallInteger('person_group')->nullable();
            $table->unsignedSmallInteger('person_field_order')->nullable();

            // Page pixels.
            $table->json('polygon');
            $table->json('baseline')->nullable();
            $table->json('bbox');
            $table->string('crop_path');
            $table->unsignedInteger('crop_version')->default(1);

            // no_row, shared_cell.
            $table->json('flags');

            $table->text('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 1)->nullable();
            $table->string('ocr_error', 500)->nullable();

            // Set when a reviewer redrew the outline by hand.
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();

            $table->index(['document_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_lines');
        Schema::dropIfExists('document_pages');
    }
};
