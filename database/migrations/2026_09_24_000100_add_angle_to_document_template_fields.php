<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template field marker can be tilted: degrees clockwise about its own
 * centre. Every existing field is upright, so the default keeps them as they
 * were. Ledger columns keep their shared tilt inside the `columns` JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table) {
            $table->decimal('angle', 5, 1)->default(0)->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('document_template_fields', function (Blueprint $table) {
            $table->dropColumn('angle');
        });
    }
};
