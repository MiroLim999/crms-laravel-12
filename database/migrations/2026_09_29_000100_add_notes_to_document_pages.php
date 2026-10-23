<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            // What Staff should check about the grid as a whole, as codes with
            // numbers (see DocumentPage::cleanNotes): rows of a different height
            // than the template's, written lines just outside the grid.
            $table->json('notes')->nullable()->after('geometry');
        });
    }

    public function down(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
