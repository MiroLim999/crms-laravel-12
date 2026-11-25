<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // ruled_tilts: [deg, deg, ...] one per row line of ruled_ys, how far each
            // line is turned beyond the table's own tilt (degrees clockwise).
            $table->json('ruled_tilts')->nullable()->after('ruled_ys');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('ruled_tilts');
        });
    }
};
