<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether uploaded files are checked against what was saved or queued
     * before (by the file's fingerprint). Off unless the account turns it on
     * in its settings. The fingerprint itself is always kept with a record.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('duplicate_file_check')->default(false)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('duplicate_file_check');
        });
    }
};
