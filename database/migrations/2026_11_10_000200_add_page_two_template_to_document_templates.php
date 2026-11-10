<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A two-page spread whose pages are laid out differently (a register's left and
 * right pages): the layout describing page 1 points at the layout of page 2.
 * Null: page 2 uses the same layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('page_two_template_id')->nullable()->after('parent_id')
                ->constrained('document_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_two_template_id');
        });
    }
};
