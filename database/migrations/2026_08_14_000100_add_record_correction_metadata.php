<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            // Preserve the capture-time rule even if the source template later
            // changes or is removed.
            $table->boolean('is_required')->default(true)->after('verified_value');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            // Registry numbers belong to the record rather than a RecordField,
            // but must still move through the same reviewed correction workflow.
            $table->boolean('changes_registry_number')->default(false)->after('reason');
            $table->string('current_registry_number', 64)->nullable()->after('changes_registry_number');
            $table->string('proposed_registry_number', 64)->nullable()->after('current_registry_number');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'changes_registry_number',
                'current_registry_number',
                'proposed_registry_number',
            ]);
        });

        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropColumn('is_required');
        });
    }
};
