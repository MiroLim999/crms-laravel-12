<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('sample_path')->nullable()->after('description');
            $table->string('sample_original_name')->nullable()->after('sample_path');
            $table->string('sample_mime', 100)->nullable()->after('sample_original_name');
            $table->unsignedBigInteger('sample_size')->nullable()->after('sample_mime');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn([
                'sample_path',
                'sample_original_name',
                'sample_mime',
                'sample_size',
            ]);
        });
    }
};
