<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery codes: ten one-time 6-digit codes the account makes in Account
 * Settings. "Forgot password?" on the sign-in page takes one of them to set a
 * new password. Only a keyed hash of each unused code is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('recovery_codes')->nullable()->after('password_changed_at');
            $table->timestamp('recovery_codes_generated_at')->nullable()->after('recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['recovery_codes', 'recovery_codes_generated_at']);
        });
    }
};
