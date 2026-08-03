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

        // A later migration drops ml_datasets outright, so on any database that has
        // reached it there is no table left to take these columns off. Guarded rather
        // than removed: a database rolled back from between the two still needs it.
        if (Schema::hasTable('ml_datasets')) {
            Schema::table('ml_datasets', function (Blueprint $table) {
                $table->dropForeign(['disk_deleted_by']);
                $table->dropIndex(['disk_deleted_at']);
                $table->dropColumn(['disk_deleted_by', 'disk_deleted_at']);
            });
        }
    }
};
