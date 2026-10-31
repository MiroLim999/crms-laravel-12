<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a run was TrOCR reading the lines (the rest is finding them).
 * With the line count it gives the OCR's own speed per line, measured on real
 * scans, which the model's test-set report cannot say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_runs', function (Blueprint $table) {
            $table->unsignedInteger('read_ms')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('page_runs', function (Blueprint $table) {
            $table->dropColumn('read_ms');
        });
    }
};
