<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // ruled_spans: [[left, right] | null, ...] one per row line of ruled_ys, for a line
            // stretched shorter or longer than the table (page fractions); null = the table's width.
            $table->json('ruled_spans')->nullable()->after('ruled_tilts');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('ruled_spans');
        });
    }
};
