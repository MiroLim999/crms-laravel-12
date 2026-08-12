<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->string('evaluation_dataset')->nullable()->after('exact_match');
            $table->string('evaluation_split', 16)->nullable()->after('evaluation_dataset');
            $table->unsignedInteger('evaluation_sample_count')->nullable()->after('evaluation_split');
            $table->char('evaluation_manifest_sha256', 64)->nullable()->after('evaluation_sample_count');
            $table->char('evaluation_weights_sha256', 64)->nullable()->after('evaluation_manifest_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->dropColumn([
                'evaluation_dataset',
                'evaluation_split',
                'evaluation_sample_count',
                'evaluation_manifest_sha256',
                'evaluation_weights_sha256',
            ]);
        });
    }
};
