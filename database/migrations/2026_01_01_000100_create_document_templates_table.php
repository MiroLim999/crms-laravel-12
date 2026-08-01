<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document templates define which fields are captured from each certificate type,
 * and where they sit on the page.
 *
 * Coordinates are stored as fractions of page width/height (0-1), carried over
 * from the prototype's web/js/config.js. Keeping them resolution-independent means
 * the field-marking UI works at any zoom level or scan DPI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('doc_type', 32);
            $table->text('description')->nullable();

            // Only one template per doc type is active at a time; that is the one
            // Staff get when they start a new document.
            $table->boolean('is_active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['doc_type', 'is_active']);
        });

        Schema::create('document_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Fractions of the page, 0-1.
            $table->decimal('x', 6, 5);
            $table->decimal('y', 6, 5);
            $table->decimal('width', 6, 5);
            $table->decimal('height', 6, 5);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index(['document_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_fields');
        Schema::dropIfExists('document_templates');
    }
};
