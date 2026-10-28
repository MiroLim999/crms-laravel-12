<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // Literal values: the PaperSize and PageOrientation enums were
            // removed when these columns were (2026_10_28_000100).
            $table->string('paper_size', 32)
                ->default('letter')
                ->after('doc_type');
            $table->string('orientation', 16)
                ->default('portrait')
                ->after('paper_size');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['paper_size', 'orientation']);
        });
    }
};
