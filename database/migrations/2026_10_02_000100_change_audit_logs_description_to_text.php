<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            // 255 characters was too short for some descriptions, and the
            // insert failed instead of the event being logged. change()
            // replaces the whole column definition, so nullable() is repeated.
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('description')->nullable()->change();
        });
    }
};
