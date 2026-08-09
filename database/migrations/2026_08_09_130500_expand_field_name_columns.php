<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table): void {
            $table->string('name', 500)->change();
        });

        Schema::table('record_fields', function (Blueprint $table): void {
            $table->string('name', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table): void {
            $table->string('name')->change();
        });

        Schema::table('record_fields', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
