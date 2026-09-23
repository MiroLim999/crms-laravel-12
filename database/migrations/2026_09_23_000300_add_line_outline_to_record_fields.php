<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            // The outline TrOCR read, as page fractions, so the archive and the
            // training export can always point back at the exact handwriting.
            // x/y/width/height keep holding its bounding box. crop_path (already
            // present) now holds the masked crop TrOCR read.
            $table->json('polygon')->nullable()->after('height');
            $table->string('line_column', 500)->nullable()->after('polygon');
            $table->unsignedSmallInteger('line_row')->nullable()->after('line_column');
            $table->json('line_flags')->nullable()->after('line_row');
        });
    }

    public function down(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn(['polygon', 'line_column', 'line_row', 'line_flags']);
        });
    }
};
