<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages of a scanned file that Staff chose to skip (a cover, an index, a blank
 * page), by the file's SHA-256 and the page number. A skipped page counts as
 * done: the file no longer opens at it, and Next page goes past it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skipped_source_pages', function (Blueprint $table) {
            $table->id();
            $table->char('source_hash', 64);
            $table->unsignedInteger('page');
            $table->foreignId('skipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_hash', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skipped_source_pages');
    }
};
