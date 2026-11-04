<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a record's scan came from: a register book often arrives as one PDF
 * with many pages, and each page is saved as its own record.
 *
 * - source_name: the uploaded file's name ("BIRTHS-1995.pdf").
 * - source_page / source_page_count: which page of it, of how many.
 * - source_hash: the file's SHA-256, worked out in the browser. With the page
 *   number it tells the workspace which pages of that exact file are already
 *   saved, and warns before one is saved twice.
 *
 * Records saved before this have none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('source_name', 255)->nullable()->after('scan_rotation');
            $table->unsignedInteger('source_page')->nullable()->after('source_name');
            $table->unsignedInteger('source_page_count')->nullable()->after('source_page');
            $table->char('source_hash', 64)->nullable()->after('source_page_count');
            $table->index(['source_hash', 'source_page']);
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['source_hash', 'source_page']);
            $table->dropColumn(['source_name', 'source_page', 'source_page_count', 'source_hash']);
        });
    }
};
