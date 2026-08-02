<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->timestamp('disk_deleted_at')->nullable()->index();
            $table->foreignId('disk_deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('ml_datasets', function (Blueprint $table) {
            $table->timestamp('disk_deleted_at')->nullable()->index();
            $table->foreignId('disk_deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_models', function (Blueprint $table) {
            $table->dropForeign(['disk_deleted_by']);
            $table->dropIndex(['disk_deleted_at']);
            $table->dropColumn(['disk_deleted_by', 'disk_deleted_at']);
        });

        Schema::table('ml_datasets', function (Blueprint $table) {
            $table->dropForeign(['disk_deleted_by']);
            $table->dropIndex(['disk_deleted_at']);
            $table->dropColumn(['disk_deleted_by', 'disk_deleted_at']);
        });
    }
};
