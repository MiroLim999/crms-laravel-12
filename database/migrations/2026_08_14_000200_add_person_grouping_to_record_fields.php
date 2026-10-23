<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            // Capture the validation grouping used at submission so the archive
            // remains understandable after its source template changes.
            $table->unsignedSmallInteger('person_group')->nullable()->after('is_required');
            $table->unsignedSmallInteger('person_field_order')->nullable()->after('person_group');
            $table->index(['record_id', 'person_group', 'person_field_order'], 'record_fields_person_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('record_fields', function (Blueprint $table) {
            $table->dropIndex('record_fields_person_group_index');
            $table->dropColumn(['person_group', 'person_field_order']);
        });
    }
};
