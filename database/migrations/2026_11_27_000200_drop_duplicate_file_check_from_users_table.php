<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The duplicate file check was taken out: every file is queued and saved as it is. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('duplicate_file_check');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('duplicate_file_check')->default(false)->after('must_change_password');
        });
    }
};
