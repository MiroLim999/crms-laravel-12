<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour a Super Admin picked for a model in the OCR Workspace (1-8, the
 * --ocr-series-N palette). Null = picked automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->unsignedTinyInteger('color_slot')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->dropColumn('color_slot');
        });
    }
};
