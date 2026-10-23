<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table): void {
            // No code path creates a draft (see docs/CODE_REVIEW_TODO.md #18), so
            // the default now matches the only status a record is ever saved
            // with. change() replaces the whole column definition, so the
            // length is repeated.
            $table->string('status', 16)->default('submitted')->change();
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table): void {
            $table->string('status', 16)->default('draft')->change();
        });
    }
};
