<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_lines', function (Blueprint $table) {
            // A line a reviewer removed in the Align step. It is kept, with its
            // crop, so Ctrl+Z puts it back; nothing reads or submits it
            // meanwhile. Outlining the page again clears these for good, and a
            // submitted or pruned page takes its lines with it either way.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('page_lines', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
