<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-factor sign-in with an authenticator app (TOTP, RFC 6238).
     *
     *  two_factor_secret        the shared key, encrypted with APP_KEY (text: the
     *                           ciphertext is longer than the key itself)
     *  two_factor_confirmed_at  when the app was set up and a first code checked;
     *                           null means it still has to be set up
     *  two_factor_last_step     the 30-second step of the last code accepted, so
     *                           the same code cannot be used twice
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('recovery_codes_generated_at');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
