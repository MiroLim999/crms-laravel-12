<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of more than one page: a register book's two-page spread saved as
 * one record. The record's own scan columns stay page 1, so every record saved
 * before this is unchanged; each further page has a row here.
 *
 * record_fields.page_no says which page a field was read from (1 for the
 * record's own page, and for every field saved before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('records')->cascadeOnDelete();
            $table->unsignedInteger('page_no');
            $table->string('scan_path')->nullable();
            $table->string('scan_mime')->nullable();
            $table->decimal('scan_rotation', 6, 3)->nullable();
            $table->string('page_image_path')->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->timestamps();

            $table->unique(['record_id', 'page_no']);
        });

        Schema::table('record_fields', function (Blueprint $table) {
            $table->unsignedInteger('page_no')->default(1)->after('record_id');
        });
    }

    public function down(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn('page_no');
        });

        Schema::dropIfExists('record_pages');
    }
};
