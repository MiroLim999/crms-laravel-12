<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table): void {
            // Reports filters on the capture date range and sorts by it (see
            // docs/CODE_REVIEW_TODO.md #31).
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
