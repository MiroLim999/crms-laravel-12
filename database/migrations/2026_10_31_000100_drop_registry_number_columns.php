<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry number is gone from the system: the book-of-records pages it
 * scans have no single number per page, and nobody filled it in. Rolling back
 * brings the columns back empty; the values dropped here are not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'changes_registry_number',
                'current_registry_number',
                'proposed_registry_number',
            ]);
        });

        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['registry_number']);
            $table->dropColumn('registry_number');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('registry_number', 64)->nullable()->after('document_template_id');
            $table->index('registry_number');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->boolean('changes_registry_number')->default(false)->after('reason');
            $table->string('current_registry_number', 64)->nullable()->after('changes_registry_number');
            $table->string('proposed_registry_number', 64)->nullable()->after('current_registry_number');
        });
    }
};
