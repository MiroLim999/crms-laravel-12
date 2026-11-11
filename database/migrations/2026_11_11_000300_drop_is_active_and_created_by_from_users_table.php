<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * With one account there is nobody to deactivate and nobody who made the account,
 * so `is_active` and `created_by` go (docs/SUPER-ADMIN-ONLY-PLAN.md, R7 and R8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
            // The role_id foreign key needs an index of its own before the shared
            // role_id + is_active index can go.
            $table->index('role_id');
            $table->dropIndex(['role_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('must_change_password');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->index(['role_id', 'is_active']);
            $table->dropIndex(['role_id']);
        });
    }
};
