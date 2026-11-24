<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account's name and email can be changed once from Account Settings:
 * this is when that was (null: not yet). `php artisan crms:unlock-details`
 * clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('details_locked_at')->nullable()->after('recovery_codes_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('details_locked_at');
        });
    }
};
