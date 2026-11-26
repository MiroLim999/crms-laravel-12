<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // ruled_bends: [[[left, right], ...] | null, ...] one per row line of ruled_ys: how far
            // each column's part of the line is moved up or down at its left and right ends (page
            // fractions), set by a column's squares. Seen and lined up against only, not read by.
            $table->json('ruled_bends')->nullable()->after('ruled_spans');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('ruled_bends');
        });
    }
};
