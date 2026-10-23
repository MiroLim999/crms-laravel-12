<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // A ruled register book is described by its columns and the printed
            // row lines, not by one rectangle per row: every page's handwriting is
            // outlined line by line inside that grid. Both are page fractions.
            //
            // columns:  [{"name": "Child's Name", "box": [x, y, w, h]}, ...]
            // ruled_ys: [y, y, ...] top to bottom, one per printed row line
            //
            // Null on every template created before this, which keeps reading its
            // rectangle fields exactly as before.
            $table->json('columns')->nullable()->after('grouping_mode');
            $table->json('ruled_ys')->nullable()->after('columns');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['columns', 'ruled_ys']);
        });
    }
};
