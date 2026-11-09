<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A register spread: a PDF of two pages whose rows run across both. Row N of
 * the first page and row N of the second are one person, saved as one record.
 *
 * - templates.page_two: the second page's own layout (columns, ruled_ys,
 *   fields), in the same shapes as the first page's. Null for one page.
 * - records.page_image_path_two / scan_rotation_two: the second page as it was
 *   outlined, next to the first.
 * - record_fields.page: which page a field was read from (1 or 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('page_two')->nullable();
        });

        Schema::table('records', function (Blueprint $table) {
            $table->string('page_image_path_two', 500)->nullable();
            $table->float('scan_rotation_two')->nullable();
        });

        Schema::table('record_fields', function (Blueprint $table) {
            $table->unsignedTinyInteger('page')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('record_fields', fn (Blueprint $table) => $table->dropColumn('page'));
        Schema::table('records', fn (Blueprint $table) => $table->dropColumn(['page_image_path_two', 'scan_rotation_two']));
        Schema::table('document_templates', fn (Blueprint $table) => $table->dropColumn('page_two'));
    }
};
