<?php

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('paper_size', 32)
                ->default(PaperSize::Letter->value)
                ->after('doc_type');
            $table->string('orientation', 16)
                ->default(PageOrientation::Portrait->value)
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
