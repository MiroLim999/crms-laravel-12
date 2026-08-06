<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->decimal('custom_width_mm', 8, 2)->nullable()->after('orientation');
            $table->decimal('custom_height_mm', 8, 2)->nullable()->after('custom_width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['custom_width_mm', 'custom_height_mm']);
        });
    }
};
